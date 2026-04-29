<?php

declare(strict_types=1);

final class Auth
{
    public static function attemptLogin(string $username, string $password, array $post): bool
    {
        $normalized = Security::normalizeUsername($username);
        $decision = RateLimiter::checkPasswordLogin($normalized);
        $_SESSION['login_captcha_required'] = $decision->captchaRequired;

        if ($decision->blocked) {
            RateLimiter::recordPasswordLoginAttempt($normalized, null, 'blocked', $decision->riskLevel);
            Audit::event(null, 'login_blocked_by_rate_limit', $decision->riskLevel, ['username_hash' => Security::hmac('login|' . $normalized)]);
            return false;
        }

        if (!CaptchaVerifier::verifyIfRequired($decision->captchaRequired, $post)) {
            RateLimiter::recordPasswordLoginAttempt($normalized, null, 'blocked', 'high');
            Audit::event(null, 'login_captcha_failed', 'high', ['username_hash' => Security::hmac('login|' . $normalized)]);
            return false;
        }

        $user = self::findUserForLogin($normalized);
        $valid = is_array($user)
            && hash_equals((string)$user['status'], 'active')
            && password_verify($password, (string)$user['password_hash']);

        if (!$valid) {
            $userId = is_array($user) ? (int)$user['id'] : null;
            RateLimiter::recordPasswordLoginAttempt($normalized, $userId, 'failed', $decision->riskLevel);
            Audit::event($userId, 'login_failed', $decision->riskLevel, ['username_hash' => Security::hmac('login|' . $normalized)]);
            return false;
        }

        if (password_needs_rehash((string)$user['password_hash'], PASSWORD_ARGON2ID)) {
            self::rehashPassword((int)$user['id'], $password);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = (string)$user['username'];
        $_SESSION['role'] = (string)$user['role'];
        $_SESSION['password_version'] = (int)$user['password_version'];
        $_SESSION['last_auth_at'] = time();
        $_SESSION['logged_in_at'] = time();

        self::storeCurrentSession((int)$user['id'], (int)$user['password_version']);
        RateLimiter::recordPasswordLoginAttempt($normalized, (int)$user['id'], 'success', 'normal');
        Audit::event((int)$user['id'], 'login_success', 'normal');
        unset($_SESSION['login_captcha_required']);
        return true;
    }

    public static function requireLogin(): array
    {
        $user = self::currentUser();
        if ($user === null) {
            redirect('login.php');
        }
        return $user;
    }

    public static function currentUser(): ?array
    {
        if (empty($_SESSION['user_id']) || empty($_SESSION['password_version'])) {
            return null;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, username, role, status, password_version, password_changed_at
             FROM users
             WHERE id = :id AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([':id' => (int)$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!is_array($user) || (string)$user['status'] !== 'active') {
            self::logout(false);
            return null;
        }

        if ((int)$user['password_version'] !== (int)$_SESSION['password_version']) {
            self::logout(false);
            return null;
        }

        if (!self::currentSessionIsValid((int)$user['id'], (int)$user['password_version'])) {
            self::logout(false);
            return null;
        }

        return $user;
    }

    public static function changePassword(int $userId, string $oldPassword, string $newPassword): bool
    {
        if (mb_strlen($newPassword, '8bit') < 12 || mb_strlen($newPassword, '8bit') > 128) {
            flash_set('danger', '操作失败，请稍后重试。');
            Audit::event($userId, 'password_change_rejected_policy', 'medium');
            return false;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT id, password_hash, password_version
                 FROM users
                 WHERE id = :id AND deleted_at IS NULL AND status = :status
                 FOR UPDATE'
            );
            $stmt->execute([':id' => $userId, ':status' => 'active']);
            $user = $stmt->fetch();
            if (!is_array($user) || !password_verify($oldPassword, (string)$user['password_hash'])) {
                $pdo->rollBack();
                Audit::event($userId, 'password_change_failed_old_password', 'medium');
                flash_set('danger', '操作失败，请稍后重试。');
                return false;
            }

            $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
            if (!is_string($newHash)) {
                throw new RuntimeException('Password hash failed.');
            }

            $update = $pdo->prepare(
                'UPDATE users
                 SET password_hash = :password_hash,
                     password_version = password_version + 1,
                     password_changed_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $update->execute([':password_hash' => $newHash, ':id' => $userId]);

            $versionStmt = $pdo->prepare('SELECT password_version FROM users WHERE id = :id LIMIT 1');
            $versionStmt->execute([':id' => $userId]);
            $newVersion = (int)$versionStmt->fetchColumn();

            self::revokeOtherSessions($userId);
            self::deleteRememberTokens($userId);
            $pdo->commit();

            session_regenerate_id(true);
            $_SESSION['password_version'] = $newVersion;
            $_SESSION['last_auth_at'] = time();
            self::storeCurrentSession($userId, $newVersion);
            Audit::event($userId, 'password_changed', 'normal');
            flash_set('success', '密码已修改，其他旧会话已失效。');
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('password_change_error: ' . $e->getMessage());
            flash_set('danger', '操作失败，请稍后重试。');
            return false;
        }
    }

    public static function logout(bool $recordEvent = true): void
    {
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        if ($userId !== null) {
            self::revokeCurrentSession($userId);
            self::deleteCurrentRememberToken($userId);
        }

        if ($recordEvent && $userId !== null) {
            Audit::event($userId, 'logout', 'normal');
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool)($params['secure'] ?? true),
                'httponly' => true,
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        Security::clearRememberCookie();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    private static function findUserForLogin(string $normalizedUsername): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, username, password_hash, password_version, role, status
             FROM users
             WHERE username_hash = :username_hash AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([':username_hash' => Security::hmac('user|' . $normalizedUsername)]);
        $user = $stmt->fetch();
        return is_array($user) ? $user : null;
    }

    private static function rehashPassword(int $userId, string $password): void
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if (!is_string($hash)) {
            return;
        }
        $stmt = Database::pdo()->prepare('UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':password_hash' => $hash, ':id' => $userId]);
    }

    public static function storeCurrentSession(int $userId, int $passwordVersion): void
    {
        $pdo = Database::pdo();
        $sessionHash = Security::hmac('sid|' . session_id());
        $stmt = $pdo->prepare(
            'INSERT INTO user_sessions
             (user_id, session_id_hash, password_version, ip_hash, ua_hash, created_at, last_seen_at, revoked_at)
             VALUES (:user_id, :session_id_hash, :password_version, :ip_hash, :ua_hash, NOW(), NOW(), NULL)
             ON DUPLICATE KEY UPDATE password_version = VALUES(password_version), last_seen_at = NOW(), revoked_at = NULL'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':session_id_hash' => $sessionHash,
            ':password_version' => $passwordVersion,
            ':ip_hash' => Security::ipHash(client_ip()),
            ':ua_hash' => Security::userAgentHash(client_user_agent()),
        ]);
    }

    private static function currentSessionIsValid(int $userId, int $passwordVersion): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM user_sessions
             WHERE user_id = :user_id
               AND session_id_hash = :session_id_hash
               AND password_version = :password_version
               AND revoked_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':session_id_hash' => Security::hmac('sid|' . session_id()),
            ':password_version' => $passwordVersion,
        ]);
        return (bool)$stmt->fetchColumn();
    }

    private static function revokeOtherSessions(int $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE user_sessions
             SET revoked_at = NOW()
             WHERE user_id = :user_id AND session_id_hash <> :session_id_hash AND revoked_at IS NULL'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':session_id_hash' => Security::hmac('sid|' . session_id()),
        ]);
    }

    private static function revokeCurrentSession(int $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE user_sessions
             SET revoked_at = NOW()
             WHERE user_id = :user_id AND session_id_hash = :session_id_hash AND revoked_at IS NULL'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':session_id_hash' => Security::hmac('sid|' . session_id()),
        ]);
    }

    private static function deleteRememberTokens(int $userId): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
    }

    private static function deleteCurrentRememberToken(int $userId): void
    {
        $token = $_COOKIE['remember_token'] ?? null;
        if (!is_string($token) || $token === '') {
            return;
        }
        $stmt = Database::pdo()->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id AND token_hash = :token_hash');
        $stmt->execute([
            ':user_id' => $userId,
            ':token_hash' => Security::hmac('remember|' . $token),
        ]);
    }
}
