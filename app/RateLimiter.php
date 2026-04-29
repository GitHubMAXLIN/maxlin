<?php

declare(strict_types=1);

final class RateLimitDecision
{
    /** @var bool */
    public $blocked;
    /** @var bool */
    public $captchaRequired;
    /** @var int */
    public $cooldownSeconds;
    /** @var string */
    public $riskLevel;

    public function __construct(bool $blocked, bool $captchaRequired, int $cooldownSeconds, string $riskLevel)
    {
        $this->blocked = $blocked;
        $this->captchaRequired = $captchaRequired;
        $this->cooldownSeconds = $cooldownSeconds;
        $this->riskLevel = $riskLevel;
    }
}


final class RateLimiter
{
    public const CAPTCHA_AFTER_FAILURES = 3;
    public const COOLDOWN_AFTER_FAILURES = 5;
    public const WINDOW_MINUTES = 30;

    public static function checkPasswordLogin(string $normalizedUsername): RateLimitDecision
    {
        $targetHash = Security::hmac('login|' . $normalizedUsername);
        $ipHash = Security::ipHash(client_ip());
        $subnetHash = Security::subnetHash(client_ip());

        $targetFailures = self::countFailuresByTarget($targetHash);
        $ipFailures = self::countFailuresByIp($ipHash);
        $subnetFailures = self::countFailuresBySubnet($subnetHash);

        $captchaRequired = $targetFailures >= self::CAPTCHA_AFTER_FAILURES || $ipFailures >= 10 || $subnetFailures >= 20;
        $blocked = false;
        $cooldownSeconds = 0;
        $riskLevel = $captchaRequired ? 'medium' : 'normal';

        if ($targetFailures >= self::COOLDOWN_AFTER_FAILURES || $ipFailures >= 20 || $subnetFailures >= 50) {
            $lastFailureAt = self::lastFailureAt($targetHash, $ipHash, $subnetHash);
            $cooldownSeconds = self::cooldownForFailures(max($targetFailures, intdiv($ipFailures, 4), intdiv($subnetFailures, 10)));
            if ($lastFailureAt !== null && time() < ($lastFailureAt + $cooldownSeconds)) {
                $blocked = true;
                $riskLevel = 'high';
                $cooldownSeconds = ($lastFailureAt + $cooldownSeconds) - time();
            }
        }

        return new RateLimitDecision($blocked, $captchaRequired, max(0, $cooldownSeconds), $riskLevel);
    }

    public static function recordPasswordLoginAttempt(string $normalizedUsername, ?int $userId, string $status, string $riskLevel): void
    {
        $pdo = Database::pdo();
        $challengeId = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare(
            'INSERT INTO auth_challenges
             (challenge_id, user_id, purpose, channel, target_hash, code_hash, status, attempts, max_attempts, expires_at, used_at, request_ip_hash, request_subnet_hash, request_ua_hash, risk_level, created_at, updated_at)
             VALUES
             (:challenge_id, :user_id, :purpose, :channel, :target_hash, NULL, :status, 1, 5, DATE_ADD(NOW(), INTERVAL 30 MINUTE), NULL, :ip_hash, :subnet_hash, :ua_hash, :risk_level, NOW(), NOW())'
        );
        $stmt->execute([
            ':challenge_id' => $challengeId,
            ':user_id' => $userId,
            ':purpose' => 'password_login',
            ':channel' => 'password',
            ':target_hash' => Security::hmac('login|' . $normalizedUsername),
            ':status' => $status,
            ':ip_hash' => Security::ipHash(client_ip()),
            ':subnet_hash' => Security::subnetHash(client_ip()),
            ':ua_hash' => Security::userAgentHash(client_user_agent()),
            ':risk_level' => $riskLevel,
        ]);
    }

    private static function countFailuresByTarget(string $targetHash): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM auth_challenges
             WHERE purpose = :purpose
               AND status IN (\'failed\', \'blocked\')
               AND target_hash = :target_hash
               AND created_at >= :cutoff'
        );
        $stmt->execute([
            ':purpose' => 'password_login',
            ':target_hash' => $targetHash,
            ':cutoff' => self::cutoffDateTime(),
        ]);
        return (int)$stmt->fetchColumn();
    }

    private static function countFailuresByIp(string $ipHash): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM auth_challenges
             WHERE purpose = :purpose
               AND status IN (\'failed\', \'blocked\')
               AND request_ip_hash = :ip_hash
               AND created_at >= :cutoff'
        );
        $stmt->execute([
            ':purpose' => 'password_login',
            ':ip_hash' => $ipHash,
            ':cutoff' => self::cutoffDateTime(),
        ]);
        return (int)$stmt->fetchColumn();
    }

    private static function countFailuresBySubnet(string $subnetHash): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM auth_challenges
             WHERE purpose = :purpose
               AND status IN (\'failed\', \'blocked\')
               AND request_subnet_hash = :subnet_hash
               AND created_at >= :cutoff'
        );
        $stmt->execute([
            ':purpose' => 'password_login',
            ':subnet_hash' => $subnetHash,
            ':cutoff' => self::cutoffDateTime(),
        ]);
        return (int)$stmt->fetchColumn();
    }

    private static function cutoffDateTime(): string
    {
        return (new DateTimeImmutable('-' . self::WINDOW_MINUTES . ' minutes'))->format('Y-m-d H:i:s');
    }

    private static function lastFailureAt(string $targetHash, string $ipHash, string $subnetHash): ?int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT created_at FROM auth_challenges
             WHERE purpose = :purpose
               AND status IN (\'failed\', \'blocked\')
               AND (target_hash = :target_hash OR request_ip_hash = :ip_hash OR request_subnet_hash = :subnet_hash)
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            ':purpose' => 'password_login',
            ':target_hash' => $targetHash,
            ':ip_hash' => $ipHash,
            ':subnet_hash' => $subnetHash,
        ]);
        $value = $stmt->fetchColumn();
        if (!is_string($value) || $value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }

    private static function cooldownForFailures(int $failures): int
    {
        if ($failures >= 10) {
            return 3600;
        }
        if ($failures >= 8) {
            return 900;
        }
        return 300;
    }
}
