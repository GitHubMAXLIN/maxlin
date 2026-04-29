<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
$user = Auth::requireLogin();
$userId = (int)$user['id'];

header('Content-Type: application/json; charset=utf-8');

function upload_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_post()) {
    upload_json(405, ['errno' => 1, 'message' => 'Method Not Allowed']);
}

$token = $_POST['csrf_token'] ?? null;
if (!Security::verifyCsrf(is_string($token) ? $token : null) || !Security::sameOriginRequest()) {
    upload_json(403, ['errno' => 1, 'message' => 'Forbidden']);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > (ImageUploadService::MAX_FILE_BYTES + 1024 * 1024)) {
    upload_json(413, ['errno' => 1, 'message' => '单张图片不能超过 20MB']);
}

try {
    ImageUploadService::cleanupExpiredTemps($userId);
    if (ImageUploadService::activeTempRoleCount($userId, ImageUploadService::ROLE_EDITOR) >= 20) {
        throw new InvalidArgumentException('富文本图片最多 20 张，请先保存文章或删除未使用图片。');
    }
    if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
        if ($contentLength > 0) {
            throw new InvalidArgumentException('服务器没有收到图片文件，请检查 PHP 的 upload_max_filesize 和 post_max_size 是否已调到 25M 以上。');
        }
        throw new InvalidArgumentException('请选择图片。');
    }
    $result = ImageUploadService::handleUploadedImage($_FILES['image'], $userId, null, ImageUploadService::ROLE_EDITOR);
    ContentAudit::event($userId, 'article_image_upload', 'article_image', (int)$result['id'], ['role' => 3, 'size' => (int)$result['size']]);
    upload_json(200, [
        'errno' => 0,
        'data' => [
            'url' => $result['url'],
            'alt' => '',
            'href' => '',
            'image_id' => (int)$result['id'],
        ],
    ]);
} catch (Throwable $e) {
    error_log('article_image_upload_error: ' . $e->getMessage());
    upload_json(400, ['errno' => 1, 'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : '上传失败，请稍后重试。']);
}
