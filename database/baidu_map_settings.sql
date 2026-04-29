CREATE TABLE IF NOT EXISTS site_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_name VARCHAR(80) NOT NULL,
    setting_value TEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_setting_name (setting_name),
    KEY idx_updated_by (updated_by),
    CONSTRAINT fk_site_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO site_settings (setting_name, setting_value, updated_by, created_at, updated_at)
VALUES ('site_name', '安全博客', NULL, NOW(), NOW())
ON DUPLICATE KEY UPDATE setting_name = setting_name;
