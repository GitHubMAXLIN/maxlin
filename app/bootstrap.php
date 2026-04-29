<?php

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const CONFIG_FILE = __DIR__ . '/config/config.php';
const INSTALL_LOCK = APP_ROOT . '/install.lock';

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Config.php';

if (!is_file(CONFIG_FILE) || !is_file(INSTALL_LOCK)) {
    if (!current_script_is_install()) {
        header('Location: ' . url('install/install.php'), true, 302);
        exit;
    }
} else {
    Config::load(CONFIG_FILE);
}

if (is_file(CONFIG_FILE)) {
    Config::load(CONFIG_FILE);
    $isProduction = Config::get('app.env', 'production') === 'production';
    ini_set('display_errors', $isProduction ? '0' : '1');
    ini_set('log_errors', '1');

    require_once __DIR__ . '/Security.php';
    require_once __DIR__ . '/Database.php';
    require_once __DIR__ . '/Audit.php';
    require_once __DIR__ . '/CaptchaVerifier.php';
    require_once __DIR__ . '/RateLimiter.php';
    require_once __DIR__ . '/Auth.php';
    require_once __DIR__ . '/ResourceGuard.php';
    require_once __DIR__ . '/HtmlSanitizer.php';
    require_once __DIR__ . '/ImageUploadService.php';
    require_once __DIR__ . '/ContentAudit.php';
    require_once __DIR__ . '/SiteSettings.php';
    require_once __DIR__ . '/BlogSchema.php';
    require_once __DIR__ . '/VisitTracker.php';
    require_once __DIR__ . '/Layout.php';

    Security::enforceHttpsIfConfigured();
    Security::startSession();
    $currentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    Security::sendSecurityHeaders($currentScript === 'article_form.php' ? 'map_editor' : 'default');
}
