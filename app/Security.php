<?php

declare(strict_types=1);

final class Security
{
    public const CSRF_BYTES = 32;
    public const HMAC_HEX_LENGTH = 64;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_trans_sid', '0');

        $secureCookie = (bool)Config::get('app.cookie_secure', true);
        $sameSite = (string)Config::get('app.same_site', 'Lax');
        session_name((string)Config::get('app.session_name', 'SecureBlogAdmin'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => in_array($sameSite, ['Lax', 'Strict'], true) ? $sameSite : 'Lax',
        ]);
        session_start();
    }

    public static function sendSecurityHeaders(string $pageMode = 'default'): void
    {
        if (headers_sent()) {
            return;
        }

        if ($pageMode === 'map_editor') {
            // article_form.php 需要同时加载 wangEditor 和百度地图。
            // 百度地图 JS 会动态加载多级脚本、样式和瓦片资源，单独在该页面放开必要域名；其它页面仍使用更严格 CSP。
            $csp = "default-src 'self'; "
                . "style-src 'self' 'unsafe-inline' https://api.map.baidu.com http://api.map.baidu.com https://*.map.baidu.com http://*.map.baidu.com https://*.bdimg.com http://*.bdimg.com https://*.bdstatic.com http://*.bdstatic.com https://*.baidu.com http://*.baidu.com https://*.bcebos.com http://*.bcebos.com https://cdn.jsdelivr.net; "
                . "script-src 'self' 'unsafe-eval' 'unsafe-inline' https://api.map.baidu.com http://api.map.baidu.com https://*.map.baidu.com http://*.map.baidu.com https://*.bdimg.com http://*.bdimg.com https://*.bdstatic.com http://*.bdstatic.com https://*.baidu.com http://*.baidu.com https://*.bcebos.com http://*.bcebos.com https://cdn.jsdelivr.net; "
                . "script-src-elem 'self' 'unsafe-inline' https://api.map.baidu.com http://api.map.baidu.com https://*.map.baidu.com http://*.map.baidu.com https://*.bdimg.com http://*.bdimg.com https://*.bdstatic.com http://*.bdstatic.com https://*.baidu.com http://*.baidu.com https://*.bcebos.com http://*.bcebos.com https://cdn.jsdelivr.net; "
                . "img-src 'self' data: blob: https://api.map.baidu.com http://api.map.baidu.com https://*.map.baidu.com http://*.map.baidu.com https://*.bdimg.com http://*.bdimg.com https://*.bdstatic.com http://*.bdstatic.com https://*.baidu.com http://*.baidu.com https://*.bcebos.com http://*.bcebos.com; "
                . "connect-src 'self' https://api.map.baidu.com http://api.map.baidu.com https://*.map.baidu.com http://*.map.baidu.com https://*.bdimg.com http://*.bdimg.com https://*.bdstatic.com http://*.bdstatic.com https://*.baidu.com http://*.baidu.com https://*.bcebos.com http://*.bcebos.com; "
                . "font-src 'self' data: https://*.bdimg.com http://*.bdimg.com https://*.bdstatic.com http://*.bdstatic.com https://cdn.jsdelivr.net; "
                . "worker-src 'self' blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'";
        } else {
            $csp = "default-src 'self'; "
                . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
                . "script-src 'self' https://cdn.jsdelivr.net; "
                . "img-src 'self' data: blob:; "
                . "connect-src 'self'; "
                . "font-src 'self' data: https://cdn.jsdelivr.net; "
                . "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'";
        }

        header('Content-Security-Policy: ' . $csp);
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(self), microphone=(), camera=()');

        if (self::isHttps() || (bool)Config::get('app.force_https', false)) {
            header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
        }
    }

    public static function enforceHttpsIfConfigured(): void
    {
        if (!(bool)Config::get('app.force_https', false) || self::isHttps()) {
            return;
        }
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if ($host !== '') {
            header('Location: https://' . $host . $uri, true, 308);
            exit;
        }
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(self::CSRF_BYTES));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token)
            && isset($_SESSION['csrf_token'])
            && is_string($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function requirePostCsrf(): void
    {
        if (!is_post()) {
            http_response_code(405);
            echo 'Method Not Allowed';
            exit;
        }

        $token = $_POST['csrf_token'] ?? null;
        if (!self::verifyCsrf(is_string($token) ? $token : null) || !self::sameOriginRequest()) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }

    public static function sameOriginRequest(): bool
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return false;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (is_string($origin) && $origin !== '') {
            return hash_equals($host, self::originAuthority($origin));
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (is_string($referer) && $referer !== '') {
            return hash_equals($host, self::originAuthority($referer));
        }

        return false;
    }

    private static function originAuthority(string $url): string
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        $port = parse_url($url, PHP_URL_PORT);
        if ($host === '') {
            return '';
        }
        return is_int($port) ? $host . ':' . $port : $host;
    }

    public static function hmac(string $value): string
    {
        $pepper = (string)Config::get('app.pepper');
        if ($pepper === '') {
            throw new RuntimeException('Application pepper is missing.');
        }
        return hash_hmac('sha256', $value, $pepper);
    }

    public static function normalizeUsername(string $username): string
    {
        $username = trim($username);
        $username = mb_strtolower($username, 'UTF-8');
        return mb_substr($username, 0, 190, 'UTF-8');
    }

    public static function ipHash(string $ip): string
    {
        return self::hmac('ip|' . $ip);
    }

    public static function subnetHash(string $ip): string
    {
        $subnet = $ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $subnet = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
        }
        return self::hmac('subnet|' . $subnet);
    }

    public static function userAgentHash(string $ua): string
    {
        return self::hmac('ua|' . mb_substr($ua, 0, 512, 'UTF-8'));
    }

    public static function clearRememberCookie(): void
    {
        setcookie('remember_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => (bool)Config::get('app.cookie_secure', true),
            'httponly' => true,
            'samesite' => (string)Config::get('app.same_site', 'Lax'),
        ]);
    }
}
