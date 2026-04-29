<?php

declare(strict_types=1);

final class ImageUploadService
{
    public const ROLE_COVER = 1;
    public const ROLE_MAIN = 2;
    public const ROLE_EDITOR = 3;
    public const MAX_FILE_BYTES = 20971520;
    public const MAX_SINGLE_SIDE = 8000;
    public const MAX_TOTAL_PIXELS = 36000000;
    public const TEMP_TTL_HOURS = 24;

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public static function storageRoot(): string
    {
        $configured = (string)Config::get('storage.upload_root', 'storage/uploads');
        $root = str_starts_with($configured, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $configured)
            ? $configured
            : APP_ROOT . '/' . $configured;
        if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
            throw new RuntimeException('上传目录创建失败。');
        }
        $real = realpath($root);
        if (!is_string($real)) {
            throw new RuntimeException('上传目录不可用。');
        }
        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    public static function cleanupExpiredTemps(int $userId): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT t.id, t.storage_path
             FROM article_upload_temps t
             WHERE t.user_id = :user_id AND t.status = 0 AND t.expires_at < NOW()
             LIMIT 50'
        );
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            self::deleteStoragePath((string)$row['storage_path']);
            $update = $pdo->prepare('UPDATE article_upload_temps SET status = 2, updated_at = NOW() WHERE id = :id AND user_id = :user_id');
            $update->execute([':id' => (int)$row['id'], ':user_id' => $userId]);
        }
    }

    public static function activeTempContentCount(int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM article_upload_temps
             WHERE user_id = :user_id AND status = 0 AND expires_at >= NOW()'
        );
        $stmt->execute([':user_id' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    public static function activeTempRoleCount(int $userId, int $role): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM article_upload_temps t
             JOIN article_images i ON i.user_id = t.user_id AND i.storage_path = t.storage_path AND i.deleted_at IS NULL
             WHERE t.user_id = :user_id AND t.status = 0 AND t.expires_at >= NOW() AND i.image_role = :role'
        );
        $stmt->execute([':user_id' => $userId, ':role' => $role]);
        return (int)$stmt->fetchColumn();
    }

    /** @return array{id:int,url:string,path:string,width:int,height:int,mime:string,size:int,temp_token:?string} */
    public static function handleUploadedImage(array $file, int $userId, ?int $articleId, int $role, array $options = []): array
    {
        set_time_limit(90);
        ini_set('memory_limit', '256M');

        if (!in_array($role, [self::ROLE_COVER, self::ROLE_MAIN, self::ROLE_EDITOR], true)) {
            throw new InvalidArgumentException('图片角色不合法。');
        }
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(self::uploadErrorMessage($uploadError));
        }
        $tmpName = $file['tmp_name'] ?? '';
        if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('上传来源不合法。');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('单张图片不能超过 20MB。');
        }

        $info = self::inspectImage($tmpName);
        if (!empty($options['require_cover_800']) && ($info['width'] !== 800 || $info['height'] !== 800)) {
            throw new InvalidArgumentException('封面图必须先裁剪为 800×800 像素。');
        }

        $ext = self::MIME_EXTENSIONS[$info['mime']];
        $relative = self::makeRelativePath($ext);
        $absolute = self::absolutePathFromRelative($relative);
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('上传目录创建失败。');
        }

        try {
            self::reencodeImage($tmpName, $absolute, $info['mime']);
            clearstatcache(true, $absolute);
            $finalSize = filesize($absolute);
            if (!is_int($finalSize) || $finalSize < 1) {
                throw new RuntimeException('图片保存失败。');
            }

            $pdo = Database::pdo();
            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO article_images
                 (user_id, article_id, image_role, storage_path, public_url, mime_type, file_ext, file_size, width, height, sort_order, original_name_hash, created_at, deleted_at)
                 VALUES
                 (:user_id, :article_id, :image_role, :storage_path, NULL, :mime_type, :file_ext, :file_size, :width, :height, 0, :original_name_hash, NOW(), NULL)'
            );
            $insert->execute([
                ':user_id' => $userId,
                ':article_id' => $articleId,
                ':image_role' => $role,
                ':storage_path' => $relative,
                ':mime_type' => $info['mime'],
                ':file_ext' => $ext,
                ':file_size' => $finalSize,
                ':width' => $info['width'],
                ':height' => $info['height'],
                ':original_name_hash' => self::originalNameHash((string)($file['name'] ?? '')),
            ]);
            $imageId = (int)$pdo->lastInsertId();
            $url = 'media.php?id=' . $imageId;
            $update = $pdo->prepare('UPDATE article_images SET public_url = :public_url WHERE id = :id AND user_id = :user_id');
            $update->execute([':public_url' => $url, ':id' => $imageId, ':user_id' => $userId]);

            $tempToken = null;
            if ($articleId === null) {
                $tempToken = bin2hex(random_bytes(32));
                $temp = $pdo->prepare(
                    'INSERT INTO article_upload_temps
                     (user_id, temp_token, storage_path, mime_type, file_ext, file_size, width, height, status, expires_at, created_at, updated_at)
                     VALUES
                     (:user_id, :temp_token, :storage_path, :mime_type, :file_ext, :file_size, :width, :height, 0, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW(), NOW())'
                );
                $temp->execute([
                    ':user_id' => $userId,
                    ':temp_token' => $tempToken,
                    ':storage_path' => $relative,
                    ':mime_type' => $info['mime'],
                    ':file_ext' => $ext,
                    ':file_size' => $finalSize,
                    ':width' => $info['width'],
                    ':height' => $info['height'],
                ]);
            }
            $pdo->commit();

            return [
                'id' => $imageId,
                'url' => $url,
                'path' => $relative,
                'width' => $info['width'],
                'height' => $info['height'],
                'mime' => $info['mime'],
                'size' => $finalSize,
                'temp_token' => $tempToken,
            ];
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($absolute);
            throw $e;
        }
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '图片超过服务器上传限制，请把 PHP 的 upload_max_filesize 和 post_max_size 调到 25M 以上。',
            UPLOAD_ERR_PARTIAL => '图片只上传了一部分，请重新上传。',
            UPLOAD_ERR_NO_FILE => '请选择图片。',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少上传临时目录。',
            UPLOAD_ERR_CANT_WRITE => '服务器无法写入上传文件，请检查目录权限。',
            UPLOAD_ERR_EXTENSION => '图片上传被 PHP 扩展中止。',
            default => '图片上传失败，请稍后重试。',
        };
    }

    /** @return array{mime:string,width:int,height:int} */
    private static function inspectImage(string $tmpName): array
    {
        if (!class_exists('finfo')) {
            throw new InvalidArgumentException('服务器未启用 fileinfo 扩展，无法安全识别图片 MIME。');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpName);
        if (!is_string($mime) || !array_key_exists($mime, self::MIME_EXTENSIONS)) {
            throw new InvalidArgumentException('只允许上传 jpg、png、webp 图片。');
        }

        $size = @getimagesize($tmpName);
        if (!is_array($size) || empty($size[0]) || empty($size[1])) {
            throw new InvalidArgumentException('图片尺寸读取失败。');
        }

        $allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if (function_exists('exif_imagetype')) {
            $type = @exif_imagetype($tmpName);
            if (!in_array($type, $allowedTypes, true)) {
                throw new InvalidArgumentException('图片结构校验失败。');
            }
        } else {
            $type = isset($size[2]) ? (int)$size[2] : 0;
            if (!in_array($type, $allowedTypes, true)) {
                throw new InvalidArgumentException('图片结构校验失败。');
            }
        }

        $width = (int)$size[0];
        $height = (int)$size[1];
        if ($width < 1 || $height < 1 || $width > self::MAX_SINGLE_SIDE || $height > self::MAX_SINGLE_SIDE || ($width * $height) > self::MAX_TOTAL_PIXELS) {
            throw new InvalidArgumentException('图片尺寸过大。');
        }

        return ['mime' => $mime, 'width' => $width, 'height' => $height];
    }

    private static function reencodeImage(string $source, string $target, string $mime): void
    {
        if (extension_loaded('gd')) {
            self::reencodeWithGd($source, $target, $mime);
            return;
        }
        if (extension_loaded('imagick')) {
            self::reencodeWithImagick($source, $target, $mime);
            return;
        }
        throw new InvalidArgumentException('服务器未启用 GD 或 Imagick 图片处理扩展，请先在 PHP 扩展中开启 gd 或 imagick。');
    }

    private static function reencodeWithGd(string $source, string $target, string $mime): void
    {
        $image = match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source) : false,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($source) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default => false,
        };
        if (!$image instanceof GdImage) {
            throw new InvalidArgumentException('图片解码失败。');
        }

        try {
            $ok = match ($mime) {
                'image/jpeg' => function_exists('imagejpeg') ? imagejpeg($image, $target, 86) : false,
                'image/png' => self::savePng($image, $target),
                'image/webp' => function_exists('imagewebp') ? imagewebp($image, $target, 86) : false,
                default => false,
            };
            if (!$ok) {
                throw new RuntimeException('图片重新编码失败。');
            }
        } finally {
            imagedestroy($image);
        }
    }

    private static function savePng(GdImage $image, string $target): bool
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        return imagepng($image, $target, 6);
    }

    private static function reencodeWithImagick(string $source, string $target, string $mime): void
    {
        $image = new Imagick();
        $image->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 128 * 1024 * 1024);
        $image->setResourceLimit(Imagick::RESOURCETYPE_MAP, 128 * 1024 * 1024);
        $image->readImage($source);
        $image->stripImage();
        if ($mime === 'image/jpeg') {
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(86);
        } elseif ($mime === 'image/png') {
            $image->setImageFormat('png');
        } elseif ($mime === 'image/webp') {
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(86);
        }
        $ok = $image->writeImage($target);
        $image->clear();
        $image->destroy();
        if (!$ok) {
            throw new RuntimeException('图片重新编码失败。');
        }
    }

    private static function makeRelativePath(string $ext): string
    {
        $datePath = (new DateTimeImmutable('now'))->format('Y/m');
        return 'articles/' . $datePath . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    }

    public static function absolutePathFromRelative(string $relative): string
    {
        $relative = str_replace(['\\', "\0"], ['/', ''], $relative);
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
            throw new RuntimeException('存储路径不合法。');
        }
        $root = self::storageRoot();
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $dir = realpath(dirname($absolute));
        if ($dir !== false && !str_starts_with($dir, $root)) {
            throw new RuntimeException('存储路径越界。');
        }
        return $absolute;
    }

    public static function deleteStoragePath(string $relative): void
    {
        try {
            $absolute = self::absolutePathFromRelative($relative);
            $root = self::storageRoot();
            $real = realpath($absolute);
            if (is_string($real) && str_starts_with($real, $root) && is_file($real)) {
                @unlink($real);
            }
        } catch (Throwable $e) {
            error_log('delete_storage_path_failed: ' . $e->getMessage());
        }
    }

    private static function originalNameHash(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        return Security::hmac('original_name|' . mb_substr($name, 0, 255, 'UTF-8'));
    }
}
