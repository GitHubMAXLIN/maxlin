<?php

declare(strict_types=1);

const INSTALL_ROOT = __DIR__ . '/..';
const INSTALL_CONFIG_FILE = INSTALL_ROOT . '/app/config/config.php';
const INSTALL_LOCK_FILE = INSTALL_ROOT . '/install.lock';

require_once INSTALL_ROOT . '/app/helpers.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_name('SecureBlogInstall');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

function install_csrf(): string
{
    if (empty($_SESSION['install_csrf_token']) || !is_string($_SESSION['install_csrf_token'])) {
        $_SESSION['install_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['install_csrf_token'];
}

function install_verify_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['install_csrf_token'])
        && is_string($_SESSION['install_csrf_token'])
        && hash_equals($_SESSION['install_csrf_token'], $token);
}

function install_origin_authority(string $url): string
{
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    $port = parse_url($url, PHP_URL_PORT);
    if ($host === '') {
        return '';
    }
    return is_int($port) ? $host . ':' . $port : $host;
}

function install_same_origin(): bool
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return false;
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (is_string($origin) && $origin !== '') {
        return hash_equals($host, install_origin_authority($origin));
    }
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (is_string($referer) && $referer !== '') {
        return hash_equals($host, install_origin_authority($referer));
    }
    return false;
}

function install_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function install_base_url(): string
{
    $scheme = install_is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . app_base_path();
}

function install_clean_host(string $host): string
{
    $host = trim($host);
    if ($host === '' || mb_strlen($host, '8bit') > 255) {
        throw new InvalidArgumentException('数据库主机不合法。');
    }
    if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $host)) {
        throw new InvalidArgumentException('数据库主机格式不合法。');
    }
    return $host;
}

function install_clean_db_name(string $name): string
{
    $name = trim($name);
    if (!preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $name)) {
        throw new InvalidArgumentException('数据库名只允许字母、数字、下划线和短横线。');
    }
    return $name;
}

function install_clean_db_user(string $user): string
{
    $user = trim($user);
    if ($user === '' || mb_strlen($user, '8bit') > 128) {
        throw new InvalidArgumentException('数据库用户名不合法。');
    }
    return $user;
}

function install_clean_admin_username(string $username): string
{
    $username = trim($username);
    if (!preg_match('/^[a-zA-Z0-9_@.\-]{3,80}$/', $username)) {
        throw new InvalidArgumentException('管理员账号需为 3-80 位，只允许字母、数字、下划线、点、短横线和 @。');
    }
    return $username;
}

function install_hmac(string $value, string $pepper): string
{
    return hash_hmac('sha256', $value, $pepper);
}

function install_apply_schema(PDO $pdo): void
{
    $schema = file_get_contents(INSTALL_ROOT . '/database/schema.sql');
    if (!is_string($schema)) {
        throw new RuntimeException('数据库结构文件读取失败。');
    }
    $statements = array_filter(array_map('trim', explode(';', $schema)));
    foreach ($statements as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function install_write_config(array $config): void
{
    $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
    $target = INSTALL_CONFIG_FILE;
    $tmp = $target . '.tmp.' . bin2hex(random_bytes(8));
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('配置文件写入失败，请检查 app/config 目录权限。');
    }
    chmod($tmp, 0600);
    if (!rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException('配置文件移动失败。');
    }
}

$errors = [];
$success = false;
$alreadyInstalled = is_file(INSTALL_LOCK_FILE) || is_file(INSTALL_CONFIG_FILE);

if (!$alreadyInstalled && is_post()) {
    try {
        $csrf = $_POST['csrf_token'] ?? null;
        if (!install_verify_csrf(is_string($csrf) ? $csrf : null) || !install_same_origin()) {
            throw new RuntimeException('请求校验失败，请刷新页面后重试。');
        }

        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('当前 PHP 未启用 pdo_mysql 扩展。');
        }

        $dbHost = install_clean_host((string)($_POST['db_host'] ?? ''));
        $dbPort = filter_var($_POST['db_port'] ?? 3306, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if (!is_int($dbPort)) {
            throw new InvalidArgumentException('数据库端口不合法。');
        }
        $dbName = install_clean_db_name((string)($_POST['db_name'] ?? ''));
        $dbUser = install_clean_db_user((string)($_POST['db_user'] ?? ''));
        $dbPass = (string)($_POST['db_pass'] ?? '');
        $adminUsername = install_clean_admin_username((string)($_POST['admin_username'] ?? ''));
        $adminPassword = (string)($_POST['admin_password'] ?? '');
        $adminPasswordConfirm = (string)($_POST['admin_password_confirm'] ?? '');

        if (!hash_equals($adminPassword, $adminPasswordConfirm)) {
            throw new InvalidArgumentException('两次输入的管理员密码不一致。');
        }
        if (mb_strlen($adminPassword, '8bit') < 12 || mb_strlen($adminPassword, '8bit') > 128) {
            throw new InvalidArgumentException('管理员密码必须为 12-128 位。');
        }

        $pepper = bin2hex(random_bytes(32));
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        install_apply_schema($pdo);

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM users');
        $countStmt->execute();
        if ((int)$countStmt->fetchColumn() > 0) {
            throw new RuntimeException('users 表已存在账号。为避免覆盖，请手动确认数据库后再安装。');
        }

        $passwordHash = password_hash($adminPassword, PASSWORD_ARGON2ID);
        if (!is_string($passwordHash)) {
            throw new RuntimeException('密码哈希生成失败。');
        }
        $normalizedUsername = mb_strtolower($adminUsername, 'UTF-8');
        $insert = $pdo->prepare(
            'INSERT INTO users
             (username, username_hash, password_hash, password_version, password_changed_at, role, status, protected_account, created_at, updated_at)
             VALUES
             (:username, :username_hash, :password_hash, 1, NOW(), :role, :status, 1, NOW(), NOW())'
        );
        $insert->execute([
            ':username' => $adminUsername,
            ':username_hash' => install_hmac('user|' . $normalizedUsername, $pepper),
            ':password_hash' => $passwordHash,
            ':role' => 'admin',
            ':status' => 'active',
        ]);

        $config = [
            'app' => [
                'env' => 'production',
                'base_url' => install_base_url(),
                'force_https' => isset($_POST['force_https']) && $_POST['force_https'] === '1',
                'cookie_secure' => isset($_POST['force_https']) && $_POST['force_https'] === '1',
                'same_site' => 'Lax',
                'session_name' => 'SecureBlogAdmin',
                'pepper' => $pepper,
                'baidu_map_ak' => '',
                'site_name' => '安全博客',
            ],
            'db' => [
                'host' => $dbHost,
                'port' => $dbPort,
                'name' => $dbName,
                'user' => $dbUser,
                'pass' => $dbPass,
                'charset' => 'utf8mb4',
            ],
            'storage' => [
                'upload_root' => 'storage/uploads',
            ],
        ];
        install_write_config($config);

        if (file_put_contents(INSTALL_LOCK_FILE, 'installed_at=' . date(DATE_ATOM) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('install.lock 写入失败。');
        }
        chmod(INSTALL_LOCK_FILE, 0600);
        session_regenerate_id(true);
        $success = true;
        $alreadyInstalled = true;
    } catch (Throwable $e) {
        error_log('install_error: ' . $e->getMessage());
        $errors[] = $e instanceof InvalidArgumentException ? $e->getMessage() : '安装失败，请检查数据库连接、目录权限和 PHP 扩展。';
    }
}

$token = install_csrf();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>安全博客系统安装</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="bg-light">
<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8 col-xl-7">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4 p-md-5">
                    <div class="mb-4">
                        <span class="badge text-bg-primary mb-2">第一阶段</span>
                        <h1 class="h3 mb-2"><i class="bi bi-tools me-2" aria-hidden="true"></i>安全博客系统安装</h1>
                        <p class="text-secondary mb-0">配置数据库连接并初始化第一个管理员账号。</p>
                    </div>

                    <?php foreach ($errors as $error): ?>
                        <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
                    <?php endforeach; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success" role="alert">安装完成。请删除或限制访问 install 目录，然后前往后台登录。</div>
                        <a class="btn btn-primary" href="../login.php"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>进入登录页</a>
                    <?php elseif ($alreadyInstalled): ?>
                        <div class="alert alert-warning" role="alert">系统已安装。若需重新安装，请先备份数据，并手动删除 install.lock 与 app/config/config.php。</div>
                        <a class="btn btn-primary" href="../login.php"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>进入登录页</a>
                    <?php else: ?>
                        <form method="post" autocomplete="off" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

                            <h2 class="h5 mt-3"><i class="bi bi-database-gear me-1" aria-hidden="true"></i>数据库配置</h2>
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label class="form-label" for="db_host">数据库主机</label>
                                    <input class="form-control" id="db_host" name="db_host" value="127.0.0.1" required maxlength="255">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="db_port">端口</label>
                                    <input class="form-control" id="db_port" name="db_port" value="3306" inputmode="numeric" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="db_name">数据库名</label>
                                    <input class="form-control" id="db_name" name="db_name" required maxlength="64">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="db_user">数据库用户</label>
                                    <input class="form-control" id="db_user" name="db_user" required maxlength="128">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="db_pass">数据库密码</label>
                                    <input class="form-control" id="db_pass" name="db_pass" type="password" autocomplete="new-password">
                                </div>
                            </div>

                            <h2 class="h5 mt-4"><i class="bi bi-person-gear me-1" aria-hidden="true"></i>管理员账号</h2>
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label" for="admin_username">管理员账号</label>
                                    <input class="form-control" id="admin_username" name="admin_username" required minlength="3" maxlength="80" autocomplete="username">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="admin_password">管理员密码</label>
                                    <input class="form-control" id="admin_password" name="admin_password" type="password" required minlength="12" maxlength="128" autocomplete="new-password">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="admin_password_confirm">确认密码</label>
                                    <input class="form-control" id="admin_password_confirm" name="admin_password_confirm" type="password" required minlength="12" maxlength="128" autocomplete="new-password">
                                </div>
                            </div>

                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" value="1" id="force_https" name="force_https" <?= install_is_https() ? 'checked' : '' ?>>
                                <label class="form-check-label" for="force_https">生产环境强制 HTTPS，并启用 Secure Cookie / HSTS</label>
                            </div>
                            <p class="small text-secondary mt-2">若当前站点还没有 HTTPS，先不要勾选；上线后请在 app/config/config.php 中开启。</p>

                            <button class="btn btn-primary btn-lg w-100 mt-4" type="submit"><i class="bi bi-play-circle me-1" aria-hidden="true"></i>开始安装</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>
</body>
</html>
