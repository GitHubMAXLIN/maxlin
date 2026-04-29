<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
$user = Auth::requireLogin();
$userId = (int)$user['id'];

header('Content-Type: application/json; charset=utf-8');

function upload_main_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_post()) {
    upload_main_json(405, ['ok' => false, 'message' => 'Method Not Allowed']);
}

$token = $_POST['csrf_token'] ?? null;
if (!Security::verifyCsrf(is_string($token) ? $token : null) || !Security::sameOriginRequest()) {
    upload_main_json(403, ['ok' => false, 'message' => 'Forbidden']);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > (ImageUploadService::MAX_FILE_BYTES + 1024 * 1024)) {
    upload_main_json(413, ['ok' => false, 'message' => '单张主图不能超过 20MB']);
}

try {
    ImageUploadService::cleanupExpiredTemps($userId);
    if (ImageUploadService::activeTempRoleCount($userId, ImageUploadService::ROLE_MAIN) >= 20) {
        throw new InvalidArgumentException('主图最多 20 张，请先删除多余图片或保存文章。');
    }
    if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
        if ($contentLength > 0) {
            throw new InvalidArgumentException('服务器没有收到主图文件，请检查 PHP 的 upload_max_filesize 和 post_max_size 是否已调到 25M 以上。');
        }
        throw new InvalidArgumentException('请选择主图。');
    }
    $result = ImageUploadService::handleUploadedImage($_FILES['image'], $userId, null, ImageUploadService::ROLE_MAIN);
    ContentAudit::event($userId, 'article_main_image_upload', 'article_image', (int)$result['id'], ['role' => 2, 'size' => (int)$result['size']]);
    upload_main_json(200, [
        'ok' => true,
        'id' => (int)$result['id'],
        'url' => $result['url'],
        'width' => (int)$result['width'],
        'height' => (int)$result['height'],
    ]);
} catch (Throwable $e) {
    error_log('article_main_image_upload_error: ' . $e->getMessage());
    upload_main_json(400, ['ok' => false, 'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : '上传失败，请稍后重试。']);
}
