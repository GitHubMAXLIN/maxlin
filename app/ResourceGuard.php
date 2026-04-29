<?php

declare(strict_types=1);

final class ResourceGuard
{
    /** 资源类型只允许走后端白名单，禁止前端传表名或列名。 */
    public const RESOURCE_TYPES = ['article', 'category', 'article_image'];

    public static function requireOwnedResource(string $resourceType, int $resourceId, int $ownerUserId): array
    {
        if ($resourceId < 1 || !in_array($resourceType, self::RESOURCE_TYPES, true)) {
            http_response_code(404);
            exit('Not Found');
        }

        $pdo = Database::pdo();
        $stmt = match ($resourceType) {
            'article' => $pdo->prepare(
                'SELECT * FROM articles
                 WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
                 LIMIT 1'
            ),
            'category' => $pdo->prepare(
                'SELECT * FROM categories
                 WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
                 LIMIT 1'
            ),
            'article_image' => $pdo->prepare(
                'SELECT * FROM article_images
                 WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
                 LIMIT 1'
            ),
        };
        $stmt->execute([':id' => $resourceId, ':user_id' => $ownerUserId]);
        $resource = $stmt->fetch();
        if (!is_array($resource)) {
            http_response_code(404);
            exit('Not Found');
        }
        return $resource;
    }

    public static function requireOwnedCategory(int $categoryId, int $ownerUserId): array
    {
        return self::requireOwnedResource('category', $categoryId, $ownerUserId);
    }

    public static function requireOwnedArticle(int $articleId, int $ownerUserId): array
    {
        return self::requireOwnedResource('article', $articleId, $ownerUserId);
    }

    public static function requireOwnedArticleImage(int $imageId, int $ownerUserId): array
    {
        return self::requireOwnedResource('article_image', $imageId, $ownerUserId);
    }
}
