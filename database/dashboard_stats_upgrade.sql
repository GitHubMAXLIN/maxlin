CREATE TABLE IF NOT EXISTS site_visit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visitor_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    user_agent_hash CHAR(64) NULL,
    page_type VARCHAR(30) NOT NULL,
    article_id BIGINT UNSIGNED NULL,
    visited_at DATETIME NOT NULL,
    KEY idx_visited_at (visited_at),
    KEY idx_ip_visited (ip_hash, visited_at),
    KEY idx_visitor_visited (visitor_hash, visited_at),
    KEY idx_page_visited (page_type, visited_at),
    KEY idx_article_visited (article_id, visited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
