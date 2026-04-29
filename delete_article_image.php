<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
$user = Auth::requireLogin();
$userId = (int)$user['id'];
$pdo = Database::pdo();

header('Content-Type: application/json; charset=utf-8');

function delete_image_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_post()) {
    delete_image_json(405, ['ok' => false, 'message' => 'Method Not Allowed']);
}

$token = $_POST['csrf_token'] ?? null;
if (!Security::verifyCsrf(is_string($token) ? $token : null) || !Security::sameOriginRequest()) {
    delete_image_json(403, ['ok' => false, 'message' => 'Forbidden']);
}

$imageId = filter_var($_POST['image_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_int($imageId)) {
    delete_image_json(400, ['ok' => false, 'message' => '参数不合法。']);
}

try {
    $stmt = $pdo->prepare('SELECT id, image_role, storage_path FROM article_images WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $imageId, ':user_id' => $userId]);
    $image = $stmt->fetch();
    if (!is_array($image)) {
        throw new InvalidArgumentException('图片不存在。');
    }
    $role = (int)$image['image_role'];
    if (!in_array($role, [ImageUploadService::ROLE_MAIN, ImageUploadService::ROLE_EDITOR, ImageUploadService::ROLE_COVER], true)) {
        throw new InvalidArgumentException('图片角色不合法。');
    }

    $pdo->beginTransaction();
    $update = $pdo->prepare('UPDATE article_images SET deleted_at = NOW() WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL');
    $update->execute([':id' => $imageId, ':user_id' => $userId]);
    $temp = $pdo->prepare('UPDATE article_upload_temps SET status = 2, updated_at = NOW() WHERE user_id = :user_id AND storage_path = :storage_path');
    $temp->execute([':user_id' => $userId, ':storage_path' => (string)$image['storage_path']]);
    $pdo->commit();

    ImageUploadService::deleteStoragePath((string)$image['storage_path']);
    ContentAudit::event($userId, 'article_image_delete', 'article_image', $imageId, ['role' => $role]);
    delete_image_json(200, ['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('article_image_delete_error: ' . $e->getMessage());
    delete_image_json(400, ['ok' => false, 'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : '删除失败，请稍后重试。']);
}
