<?php

declare(strict_types=1);

final class BlogSchema
{
    public static function ensureArticleTypeColumn(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($dbName === '') {
                return;
            }
            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = :schema_name
                   AND TABLE_NAME = :table_name
                   AND COLUMN_NAME = :column_name'
            );
            $stmt->execute([
                ':schema_name' => $dbName,
                ':table_name' => 'articles',
                ':column_name' => 'article_type',
            ]);
            if ((int)$stmt->fetchColumn() > 0) {
                return;
            }
            $pdo->exec("ALTER TABLE articles ADD COLUMN article_type TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1轮播图 2普通' AFTER category_id");
        } catch (Throwable $e) {
            error_log('ensure_article_type_column_error: ' . $e->getMessage());
        }
    }
}
