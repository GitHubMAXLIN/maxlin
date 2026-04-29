CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL,
    username_hash CHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    password_version INT UNSIGNED NOT NULL DEFAULT 1,
    password_changed_at DATETIME NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'admin',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    protected_account TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_username_hash (username_hash),
    INDEX idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_challenges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    challenge_id CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    purpose VARCHAR(40) NOT NULL,
    channel VARCHAR(40) NOT NULL,
    target_hash CHAR(64) NOT NULL,
    code_hash CHAR(64) NULL,
    status VARCHAR(20) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    expires_at DATETIME NULL,
    used_at DATETIME NULL,
    request_ip_hash CHAR(64) NOT NULL,
    request_subnet_hash CHAR(64) NOT NULL,
    request_ua_hash CHAR(64) NOT NULL,
    risk_level VARCHAR(20) NOT NULL DEFAULT 'normal',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_auth_challenges_challenge_id (challenge_id),
    INDEX idx_auth_challenges_target (purpose, target_hash, status, created_at),
    INDEX idx_auth_challenges_ip (purpose, request_ip_hash, status, created_at),
    INDEX idx_auth_challenges_subnet (purpose, request_subnet_hash, status, created_at),
    INDEX idx_auth_challenges_user (user_id, created_at),
    CONSTRAINT fk_auth_challenges_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    session_id_hash CHAR(64) NOT NULL,
    password_version INT UNSIGNED NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    ua_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_user_sessions_sid (session_id_hash),
    INDEX idx_user_sessions_user (user_id, revoked_at),
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_remember_tokens_token_hash (token_hash),
    INDEX idx_remember_tokens_user (user_id, revoked_at, expires_at),
    CONSTRAINT fk_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS security_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(80) NOT NULL,
    risk_level VARCHAR(20) NOT NULL DEFAULT 'normal',
    ip_hash CHAR(64) NOT NULL,
    ua_hash CHAR(64) NOT NULL,
    context_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_security_events_user (user_id, created_at),
    INDEX idx_security_events_type (event_type, created_at),
    CONSTRAINT fk_security_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    status TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uniq_user_slug (user_id, slug),
    KEY idx_user_status_sort (user_id, status, sort_order),
    KEY idx_deleted_at (deleted_at),
    CONSTRAINT fk_categories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS articles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    summary VARCHAR(300) NULL,
    cover_image_id BIGINT UNSIGNED NULL COMMENT '封面图，对应 article_images.id',
    content_html MEDIUMTEXT NOT NULL COMMENT '经过白名单清洗后的富文本',
    content_text MEDIUMTEXT NULL COMMENT '可选，用于搜索或摘要，不含HTML',
    tags_text VARCHAR(500) NULL COMMENT '后端提取后的标签，逗号分隔，不带#',
    show_location TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1显示位置 0隐藏位置',
    location_address VARCHAR(255) NULL,
    location_lng DECIMAL(10,7) NULL,
    location_lat DECIMAL(10,7) NULL,
    comments_enabled TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1允许评论 0关闭评论',
    comment_password_enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
    comment_password_hash VARCHAR(255) NULL,
    like_seed INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '后台设置的初始点赞数',
    dislike_seed INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '后台设置的初始踩数',
    like_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '真实点赞数',
    dislike_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '真实踩数',
    status TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0草稿 1上架 2下架',
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    KEY idx_user_status_created (user_id, status, created_at),
    KEY idx_category_status_created (category_id, status, created_at),
    KEY idx_deleted_at (deleted_at),
    CONSTRAINT fk_articles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_articles_category FOREIGN KEY (category_id) REFERENCES categories(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS article_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    article_id BIGINT UNSIGNED NULL,
    image_role TINYINT UNSIGNED NOT NULL COMMENT '1封面图 2内容图',
    storage_path VARCHAR(255) NOT NULL COMMENT 'webroot外或安全上传目录中的相对路径',
    public_url VARCHAR(255) NULL COMMENT '可选，前端访问地址',
    mime_type VARCHAR(50) NOT NULL,
    file_ext VARCHAR(10) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    original_name_hash CHAR(64) NULL COMMENT '原始文件名哈希，仅审计，不存原名',
    created_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    KEY idx_article_role_sort (article_id, image_role, sort_order),
    KEY idx_user_created (user_id, created_at),
    KEY idx_deleted_at (deleted_at),
    CONSTRAINT fk_article_images_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_article_images_article FOREIGN KEY (article_id) REFERENCES articles(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS article_tag_index (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    article_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    tag_name VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_article_tag (article_id, tag_name),
    KEY idx_user_tag (user_id, tag_name),
    CONSTRAINT fk_article_tag_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_article_tag_article FOREIGN KEY (article_id) REFERENCES articles(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS article_reactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    article_id BIGINT UNSIGNED NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    user_agent_hash CHAR(64) NULL,
    reaction_type TINYINT UNSIGNED NOT NULL COMMENT '1点赞 2踩',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_article_ip (article_id, ip_hash),
    KEY idx_article_type (article_id, reaction_type),
    CONSTRAINT fk_article_reactions_article FOREIGN KEY (article_id) REFERENCES articles(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS article_upload_temps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    temp_token CHAR(64) NOT NULL,
    storage_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(50) NOT NULL,
    file_ext VARCHAR(10) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    status TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0临时 1已绑定 2已删除',
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_temp_token (temp_token),
    KEY idx_user_status (user_id, status),
    KEY idx_expires_at (expires_at),
    CONSTRAINT fk_article_upload_temps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id BIGINT UNSIGNED NULL,
    ip_hash CHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,
    detail_json JSON NULL,
    created_at DATETIME NOT NULL,
    KEY idx_user_created (user_id, created_at),
    KEY idx_target (target_type, target_id),
    KEY idx_action_created (action, created_at),
    CONSTRAINT fk_content_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
