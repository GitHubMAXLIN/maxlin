<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_datetime(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function app_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = str_replace('\\', '/', dirname($scriptName));
    $dir = rtrim($dir, '/');

    if ($dir === '.' || $dir === '/') {
        return '';
    }

    if (str_ends_with($dir, '/install')) {
        $dir = substr($dir, 0, -8);
        $dir = rtrim($dir, '/');
    }

    return ($dir === '' || $dir === '/') ? '' : $dir;
}

function url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        $path = 'index.php';
    }
    if (preg_match('#^(?:https?:)?//#i', $path)) {
        throw new InvalidArgumentException('不允许跳转到外部地址。');
    }
    $path = ltrim($path, '/');
    return app_base_path() . '/' . $path;
}

function redirect(string $path): void
{
    header('Location: ' . url($path), true, 302);
    exit;
}

function current_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    return is_string($path) ? $path : '/';
}

function current_script_is_install(): bool
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return str_contains($scriptName, '/install/');
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function client_user_agent(): string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return mb_substr(preg_replace('/[\r\n\t\x00-\x1F\x7F]/u', ' ', $ua) ?? '', 0, 512);
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get_all(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($messages) ? $messages : [];
}
