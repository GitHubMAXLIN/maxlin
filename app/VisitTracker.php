<?php

declare(strict_types=1);

final class VisitTracker
{
    private const COOKIE_NAME = 'sb_visitor_id';
    private const COOKIE_DAYS = 365;
    private const PAGE_INDEX = 'index';
    private const PAGE_ARTICLE = 'article';

    private static bool $tableEnsured = false;

    public static function trackFrontVisit(string $pageType, ?int $articleId = null): void
    {
        try {
            $pageType = self::normalizePageType($pageType);
            self::ensureTable();
            $visitorHash = self::visitorHash();
            $ipHash = Security::ipHash(client_ip());
            $uaHash = Security::userAgentHash(client_user_agent());

            $stmt = Database::pdo()->prepare(
                'INSERT INTO site_visit_logs
                    (visitor_hash, ip_hash, user_agent_hash, page_type, article_id, visited_at)
                 VALUES
                    (:visitor_hash, :ip_hash, :user_agent_hash, :page_type, :article_id, NOW())'
            );
            $stmt->execute([
                ':visitor_hash' => $visitorHash,
                ':ip_hash' => $ipHash,
                ':user_agent_hash' => $uaHash,
                ':page_type' => $pageType,
                ':article_id' => $articleId,
            ]);
        } catch (Throwable $e) {
            // 访问统计不能影响前台访问。
            error_log('visit_track_error: ' . $e->getMessage());
        }
    }

    public static function dashboardStats(int $userId): array
    {
        self::ensureTable();

        $todayStart = (new DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $sevenDaysStart = (new DateTimeImmutable('-6 days'))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        return [
            'today_unique_ips' => self::countDistinct('ip_hash', $todayStart),
            'today_unique_visitors' => self::countDistinct('visitor_hash', $todayStart),
            'today_views' => self::countRows($todayStart),
            'seven_unique_ips' => self::countDistinct('ip_hash', $sevenDaysStart),
            'seven_unique_visitors' => self::countDistinct('visitor_hash', $sevenDaysStart),
            'seven_views' => self::countRows($sevenDaysStart),
            'today_likes' => self::countReactions($userId, 1, $todayStart),
            'today_dislikes' => self::countReactions($userId, 2, $todayStart),
            'today_comments' => self::countComments($userId, $todayStart),
            'seven_likes' => self::countReactions($userId, 1, $sevenDaysStart),
            'seven_dislikes' => self::countReactions($userId, 2, $sevenDaysStart),
            'seven_comments' => self::countComments($userId, $sevenDaysStart),
        ];
    }

    public static function ensureTable(): void
    {
        if (self::$tableEnsured) {
            return;
        }

        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS site_visit_logs (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        self::$tableEnsured = true;
    }

    private static function normalizePageType(string $pageType): string
    {
        return in_array($pageType, [self::PAGE_INDEX, self::PAGE_ARTICLE], true) ? $pageType : self::PAGE_INDEX;
    }

    private static function visitorHash(): string
    {
        $visitorId = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (!is_string($visitorId) || !preg_match('/^[a-f0-9]{64}$/', $visitorId)) {
            $visitorId = bin2hex(random_bytes(32));
            self::setVisitorCookie($visitorId);
        }

        return Security::hmac('visitor|' . $visitorId);
    }

    private static function setVisitorCookie(string $visitorId): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE_NAME, $visitorId, [
            'expires' => time() + self::COOKIE_DAYS * 86400,
            'path' => '/',
            'secure' => Security::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE_NAME] = $visitorId;
    }

    private static function countDistinct(string $column, string $since): int
    {
        if (!in_array($column, ['ip_hash', 'visitor_hash'], true)) {
            return 0;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(DISTINCT ' . $column . ') AS total
             FROM site_visit_logs
             WHERE visited_at >= :since'
        );
        $stmt->execute([':since' => $since]);
        return (int)$stmt->fetchColumn();
    }

    private static function countRows(string $since): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM site_visit_logs WHERE visited_at >= :since');
        $stmt->execute([':since' => $since]);
        return (int)$stmt->fetchColumn();
    }

    private static function countReactions(int $userId, int $reactionType, string $since): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM article_reactions r
             JOIN articles a ON a.id = r.article_id
             WHERE a.user_id = :user_id
               AND a.deleted_at IS NULL
               AND r.reaction_type = :reaction_type
               AND r.created_at >= :since'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':reaction_type' => $reactionType,
            ':since' => $since,
        ]);
        return (int)$stmt->fetchColumn();
    }

    private static function countComments(int $userId, string $since): int
    {
        if (!self::tableExists('article_comments')) {
            return 0;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM article_comments cm
             JOIN articles a ON a.id = cm.article_id
             WHERE a.user_id = :user_id
               AND a.deleted_at IS NULL
               AND cm.created_at >= :since'
        );
        $stmt->execute([':user_id' => $userId, ':since' => $since]);
        return (int)$stmt->fetchColumn();
    }

    private static function tableExists(string $tableName): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        $stmt->execute([':table_name' => $tableName]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
