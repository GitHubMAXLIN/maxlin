<?php

declare(strict_types=1);

final class SiteSettings
{
    public const BAIDU_MAP_AK = 'baidu_map_ak';
    public const SITE_NAME = 'site_name';
    public const DEFAULT_SITE_NAME = '安全博客';

    public static function get(string $name, ?string $default = null): ?string
    {
        $pdo = Database::pdo();
        if (!self::tableExists($pdo)) {
            return $default;
        }
        $stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_name = :setting_name LIMIT 1');
        $stmt->execute([':setting_name' => $name]);
        $value = $stmt->fetchColumn();
        return is_string($value) ? $value : $default;
    }

    public static function set(string $name, string $value, int $userId): void
    {
        $pdo = Database::pdo();
        self::ensureTable($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO site_settings
             (setting_name, setting_value, updated_by, created_at, updated_at)
             VALUES
             (:setting_name, :setting_value, :updated_by, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
             setting_value = VALUES(setting_value),
             updated_by = VALUES(updated_by),
             updated_at = NOW()'
        );
        $stmt->execute([
            ':setting_name' => $name,
            ':setting_value' => $value,
            ':updated_by' => $userId,
        ]);
    }

    public static function baiduMapAk(): string
    {
        $dbValue = self::get(self::BAIDU_MAP_AK, null);
        if (is_string($dbValue) && $dbValue !== '') {
            return $dbValue;
        }
        return (string)Config::get('app.baidu_map_ak', '');
    }

    public static function siteName(): string
    {
        $dbValue = self::get(self::SITE_NAME, null);
        if (!is_string($dbValue) || trim($dbValue) === '') {
            $dbValue = (string)Config::get('app.site_name', self::DEFAULT_SITE_NAME);
        }
        try {
            return self::normalizeSiteName($dbValue);
        } catch (Throwable $e) {
            error_log('site_name_normalize_error: ' . $e->getMessage());
            return self::DEFAULT_SITE_NAME;
        }
    }

    public static function adminName(): string
    {
        $siteName = self::siteName();
        return str_ends_with($siteName, '后台') ? $siteName : $siteName . '后台';
    }

    public static function normalizeSiteName(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\r\n\t\x00-\x1F\x7F]/u', '', $value) ?? '';
        $value = mb_substr($value, 0, 40, 'UTF-8');
        if ($value === '') {
            throw new InvalidArgumentException('网站名称不能为空。');
        }
        if (preg_match('/[<>"\']/', $value)) {
            throw new InvalidArgumentException('网站名称不能包含尖括号或引号。');
        }
        return $value;
    }

    public static function normalizeBaiduAk(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\r\n\t\x00-\x1F\x7F]/u', '', $value) ?? '';
        $value = mb_substr($value, 0, 120, 'UTF-8');
        if ($value !== '' && !preg_match('/^[A-Za-z0-9_\-]{6,120}$/', $value)) {
            throw new InvalidArgumentException('百度地图 AK 格式不正确，只允许英文、数字、下划线和短横线。');
        }
        return $value;
    }

    public static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS site_settings (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                setting_name VARCHAR(80) NOT NULL,
                setting_value TEXT NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_setting_name (setting_name),
                KEY idx_updated_by (updated_by),
                CONSTRAINT fk_site_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function tableExists(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'site_settings'");
            return $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable $e) {
            error_log('site_settings_table_check_error: ' . $e->getMessage());
            return false;
        }
    }
}
