<?php

declare(strict_types=1);

return [
    'app' => [
        'env' => 'production',
        'base_url' => 'https://example.com',
        'force_https' => true,
        'cookie_secure' => true,
        'same_site' => 'Lax',
        'session_name' => 'SecureBlogAdmin',
        'pepper' => 'replace-with-64-plus-random-hex',
        'baidu_map_ak' => '',
        'site_name' => '安全博客',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'secure_blog',
        'user' => 'secure_blog_user',
        'pass' => 'change-me',
        'charset' => 'utf8mb4',
    ],
    'storage' => [
        'upload_root' => 'storage/uploads',
    ],
];
