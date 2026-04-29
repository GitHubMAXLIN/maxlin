<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$imageId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_int($imageId)) {
    http_response_code(404);
    exit('Not Found');
}

$stmt = Database::pdo()->prepare(
    'SELECT i.id, i.user_id, i.article_id, i.storage_path, i.mime_type, i.file_size,
            a.status AS article_status, a.deleted_at AS article_deleted_at
     FROM article_images i
     LEFT JOIN articles a ON a.id = i.article_id
     WHERE i.id = :id AND i.deleted_at IS NULL
     LIMIT 1'
);
$stmt->execute([':id' => $imageId]);
$image = $stmt->fetch();
if (!is_array($image)) {
    http_response_code(404);
    exit('Not Found');
}

$currentUser = Auth::currentUser();
$isOwner = is_array($currentUser) && (int)$currentUser['id'] === (int)$image['user_id'];
$isPublicArticleImage = $image['article_id'] !== null && (int)$image['article_status'] === 1 && $image['article_deleted_at'] === null;
if (!$isOwner && !$isPublicArticleImage) {
    http_response_code(404);
    exit('Not Found');
}

try {
    $absolute = ImageUploadService::absolutePathFromRelative((string)$image['storage_path']);
    $real = realpath($absolute);
    $root = ImageUploadService::storageRoot();
    if (!is_string($real) || !str_starts_with($real, $root) || !is_file($real)) {
        throw new RuntimeException('File missing.');
    }
    $mime = (string)$image['mime_type'];
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('Invalid mime.');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($real));
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($real);
} catch (Throwable $e) {
    error_log('media_error: ' . $e->getMessage());
    http_response_code(404);
    echo 'Not Found';
}
