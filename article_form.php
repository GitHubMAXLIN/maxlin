<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
$user = Auth::requireLogin();
$userId = (int)$user['id'];
$pdo = Database::pdo();
BlogSchema::ensureArticleTypeColumn($pdo);
ImageUploadService::cleanupExpiredTemps($userId);

function af_int_post(string $key, int $min, int $max): int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if (!is_int($value)) {
        throw new InvalidArgumentException('参数不合法。');
    }
    return $value;
}

function af_text(string $value, int $max, bool $required = false): ?string
{
    $value = preg_replace('/[\r\n\t\x00-\x1F\x7F]/u', ' ', trim($value)) ?? '';
    $value = mb_substr($value, 0, $max, 'UTF-8');
    if ($required && $value === '') {
        throw new InvalidArgumentException('必填项不能为空。');
    }
    return $value === '' ? null : $value;
}

function af_normalize_tags(string $raw): array
{
    $raw = mb_substr($raw, 0, 500, 'UTF-8');
    $tags = [];
    preg_match_all('/#([^#\s,，;；]{1,50})/u', $raw, $matches);
    foreach ($matches[1] ?? [] as $tag) {
        $tag = trim((string)$tag, " \t\n\r\0\x0B#，,;；");
        $tag = preg_replace('/[\r\n\t\x00-\x1F\x7F]/u', '', $tag) ?? '';
        $tag = mb_substr($tag, 0, 50, 'UTF-8');
        if ($tag !== '') {
            $tags[$tag] = $tag;
        }
    }
    if ($tags === []) {
        foreach (preg_split('/[,，;；\s]+/u', $raw) ?: [] as $tag) {
            $tag = trim((string)$tag, "# \t\n\r\0\x0B");
            $tag = mb_substr($tag, 0, 50, 'UTF-8');
            if ($tag !== '') {
                $tags[$tag] = $tag;
            }
        }
    }
    return array_slice(array_values($tags), 0, 20);
}

function af_content_text(string $cleanHtml): string
{
    $text = html_entity_decode(strip_tags($cleanHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    return mb_substr(trim($text), 0, 5000, 'UTF-8');
}

function af_float_or_null(string $key, float $min, float $max): ?float
{
    $raw = trim((string)($_POST[$key] ?? ''));
    if ($raw === '') {
        return null;
    }
    $value = filter_var($raw, FILTER_VALIDATE_FLOAT);
    if (!is_float($value) || $value < $min || $value > $max) {
        throw new InvalidArgumentException('定位坐标不合法。');
    }
    return $value;
}

function af_category_exists(PDO $pdo, int $categoryId, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL AND status = 1 LIMIT 1');
    $stmt->execute([':id' => $categoryId, ':user_id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

function af_fetch_article(PDO $pdo, int $articleId, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM articles WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $articleId, ':user_id' => $userId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function af_image_ids_from_csv(string $raw, int $max): array
{
    $ids = [];
    foreach (preg_split('/[,\s]+/', $raw) ?: [] as $part) {
        $id = filter_var($part, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (is_int($id)) {
            $ids[$id] = $id;
        }
    }
    $ids = array_values($ids);
    if (count($ids) > $max) {
        throw new InvalidArgumentException('图片数量超出限制。');
    }
    return $ids;
}

function af_validate_image(PDO $pdo, int $imageId, int $userId, int $role, ?int $articleId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, article_id, storage_path
         FROM article_images
         WHERE id = :id AND user_id = :user_id AND image_role = :image_role AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([':id' => $imageId, ':user_id' => $userId, ':image_role' => $role]);
    $image = $stmt->fetch();
    if (!is_array($image)) {
        throw new InvalidArgumentException('图片不合法。');
    }
    $ownerArticleId = $image['article_id'] === null ? null : (int)$image['article_id'];
    if ($ownerArticleId !== null && $articleId !== null && $ownerArticleId !== $articleId) {
        throw new InvalidArgumentException('图片归属不合法。');
    }
    if ($ownerArticleId !== null && $articleId === null) {
        throw new InvalidArgumentException('图片归属不合法。');
    }
    return $image;
}

function af_bind_single_image(PDO $pdo, int $imageId, int $articleId, int $userId, int $role): void
{
    $image = af_validate_image($pdo, $imageId, $userId, $role, $articleId);
    $bind = $pdo->prepare(
        'UPDATE article_images
         SET article_id = :article_id, sort_order = 0
         WHERE id = :id AND user_id = :user_id AND image_role = :image_role AND deleted_at IS NULL AND (article_id IS NULL OR article_id = :article_id_check)'
    );
    $bind->execute([
        ':article_id' => $articleId,
        ':id' => $imageId,
        ':user_id' => $userId,
        ':image_role' => $role,
        ':article_id_check' => $articleId,
    ]);
    $temp = $pdo->prepare('UPDATE article_upload_temps SET status = 1, updated_at = NOW() WHERE user_id = :user_id AND storage_path = :storage_path AND status = 0');
    $temp->execute([':user_id' => $userId, ':storage_path' => (string)$image['storage_path']]);
}

function af_bind_tags(PDO $pdo, int $articleId, int $userId, array $tags): void
{
    $delete = $pdo->prepare('DELETE FROM article_tag_index WHERE article_id = :article_id AND user_id = :user_id');
    $delete->execute([':article_id' => $articleId, ':user_id' => $userId]);
    $insert = $pdo->prepare('INSERT INTO article_tag_index (article_id, user_id, tag_name, created_at) VALUES (:article_id, :user_id, :tag_name, NOW())');
    foreach ($tags as $tag) {
        $insert->execute([':article_id' => $articleId, ':user_id' => $userId, ':tag_name' => $tag]);
    }
}

function af_bind_images_by_role(PDO $pdo, int $articleId, int $userId, array $imageIds, int $role, int $max): void
{
    if (count($imageIds) > $max) {
        throw new InvalidArgumentException('图片最多 ' . $max . ' 张。');
    }

    $existing = $pdo->prepare('SELECT id, storage_path FROM article_images WHERE article_id = :article_id AND user_id = :user_id AND image_role = :image_role AND deleted_at IS NULL');
    $existing->execute([':article_id' => $articleId, ':user_id' => $userId, ':image_role' => $role]);
    $oldRows = $existing->fetchAll();
    $selected = array_fill_keys($imageIds, true);

    $markDeleted = $pdo->prepare('UPDATE article_images SET deleted_at = NOW() WHERE id = :id AND article_id = :article_id AND user_id = :user_id AND image_role = :image_role');
    foreach ($oldRows as $row) {
        if (is_array($row) && !isset($selected[(int)$row['id']])) {
            $markDeleted->execute([':id' => (int)$row['id'], ':article_id' => $articleId, ':user_id' => $userId, ':image_role' => $role]);
            ImageUploadService::deleteStoragePath((string)$row['storage_path']);
        }
    }

    $bind = $pdo->prepare(
        'UPDATE article_images
         SET article_id = :article_id, sort_order = :sort_order
         WHERE id = :id AND user_id = :user_id AND image_role = :image_role AND deleted_at IS NULL AND (article_id IS NULL OR article_id = :article_id_check)'
    );
    $temp = $pdo->prepare('UPDATE article_upload_temps SET status = 1, updated_at = NOW() WHERE user_id = :user_id AND storage_path = :storage_path AND status = 0');

    $sort = 0;
    foreach ($imageIds as $imageId) {
        $image = af_validate_image($pdo, $imageId, $userId, $role, $articleId);
        $bind->execute([
            ':article_id' => $articleId,
            ':sort_order' => $sort++,
            ':id' => $imageId,
            ':user_id' => $userId,
            ':image_role' => $role,
            ':article_id_check' => $articleId,
        ]);
        $temp->execute([':user_id' => $userId, ':storage_path' => (string)$image['storage_path']]);
    }
}

$articleId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$editing = is_int($articleId);
$article = $editing ? af_fetch_article($pdo, $articleId, $userId) : null;
if ($editing && $article === null) {
    http_response_code(404);
    exit('Not Found');
}

if (is_post()) {
    Security::requirePostCsrf();
    try {
        $postArticleId = filter_var($_POST['article_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $editingPost = is_int($postArticleId);
        $currentArticle = $editingPost ? af_fetch_article($pdo, $postArticleId, $userId) : null;
        if ($editingPost && $currentArticle === null) {
            throw new InvalidArgumentException('文章不存在。');
        }

        $articleType = af_int_post('article_type', 1, 2);
        $categoryId = af_int_post('category_id', 1, PHP_INT_MAX);
        if (!af_category_exists($pdo, $categoryId, $userId)) {
            throw new InvalidArgumentException('请选择有效分类。');
        }
        $title = af_text((string)($_POST['title'] ?? ''), 150, true);
        $summary = af_text((string)($_POST['summary'] ?? ''), 300, false);
        $rawContent = (string)($_POST['content_html'] ?? '');
        $contentHtml = HtmlSanitizer::purify($rawContent);
        if (trim(strip_tags($contentHtml)) === '' && HtmlSanitizer::extractMediaImageIds($contentHtml) === []) {
            throw new InvalidArgumentException('文章正文不能为空。');
        }
        $contentText = af_content_text($contentHtml);
        $tags = af_normalize_tags((string)($_POST['tags'] ?? ''));
        $tagsText = $tags === [] ? null : implode(',', $tags);

        $showLocation = isset($_POST['show_location']) && $_POST['show_location'] === '1' ? 1 : 0;
        $locationAddress = af_text((string)($_POST['location_address'] ?? ''), 255, false);
        $locationLng = af_float_or_null('location_lng', -180.0, 180.0);
        $locationLat = af_float_or_null('location_lat', -90.0, 90.0);
        if ($showLocation === 1 && ($locationLng === null || $locationLat === null)) {
            throw new InvalidArgumentException('显示位置时必须选择坐标。');
        }
        if ($showLocation === 0) {
            $locationAddress = null;
            $locationLng = null;
            $locationLat = null;
        }

        $commentsEnabled = isset($_POST['comments_enabled']) && $_POST['comments_enabled'] === '1' ? 1 : 0;
        $commentPasswordEnabled = isset($_POST['comment_password_enabled']) && $_POST['comment_password_enabled'] === '1' ? 1 : 0;
        $commentPassword = (string)($_POST['comment_password'] ?? '');
        $commentPasswordHash = $currentArticle['comment_password_hash'] ?? null;
        if ($commentPasswordEnabled === 1) {
            if ($commentPassword !== '') {
                if (mb_strlen($commentPassword, '8bit') < 6 || mb_strlen($commentPassword, '8bit') > 128) {
                    throw new InvalidArgumentException('评论密码需为 6-128 位。');
                }
                $hash = password_hash($commentPassword, PASSWORD_ARGON2ID);
                if (!is_string($hash)) {
                    throw new RuntimeException('评论密码哈希失败。');
                }
                $commentPasswordHash = $hash;
            } elseif (!$editingPost || empty($commentPasswordHash)) {
                throw new InvalidArgumentException('开启评论密码时必须填写密码。');
            }
        } else {
            $commentPasswordHash = null;
        }

        $likeSeed = af_int_post('like_seed', 0, 1000000);
        $dislikeSeed = af_int_post('dislike_seed', 0, 1000000);
        $status = af_int_post('status', 0, 2);

        $coverImageId = filter_var($_POST['cover_image_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($coverImageId) && $editingPost && !empty($currentArticle['cover_image_id'])) {
            $coverImageId = (int)$currentArticle['cover_image_id'];
        }
        if (!is_int($coverImageId)) {
            throw new InvalidArgumentException('请上传并裁剪封面图。');
        }
        af_validate_image($pdo, $coverImageId, $userId, ImageUploadService::ROLE_COVER, $editingPost ? $postArticleId : null);

        $mainImageIds = af_image_ids_from_csv((string)($_POST['main_image_ids'] ?? ''), 20);
        if ($articleType === 1 && count($mainImageIds) < 1) {
            throw new InvalidArgumentException('轮播图类型至少需要上传 1 张主图。');
        }
        if ($articleType === 2) {
            $mainImageIds = [];
        }

        $editorImageIds = HtmlSanitizer::extractMediaImageIds($contentHtml);
        if (count($editorImageIds) > 20) {
            throw new InvalidArgumentException('富文本图片最多 20 张。');
        }

        $pdo->beginTransaction();
        if ($editingPost) {
            $sql = 'UPDATE articles
                    SET article_type = :article_type,
                        category_id = :category_id,
                        title = :title,
                        summary = :summary,
                        cover_image_id = :cover_image_id,
                        content_html = :content_html,
                        content_text = :content_text,
                        tags_text = :tags_text,
                        show_location = :show_location,
                        location_address = :location_address,
                        location_lng = :location_lng,
                        location_lat = :location_lat,
                        comments_enabled = :comments_enabled,
                        comment_password_enabled = :comment_password_enabled,
                        comment_password_hash = :comment_password_hash,
                        like_seed = :like_seed,
                        dislike_seed = :dislike_seed,
                        status = :status,
                        published_at = CASE WHEN :status_for_publish = 1 AND status <> 1 AND published_at IS NULL THEN NOW() ELSE published_at END,
                        updated_at = NOW()
                    WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':article_type' => $articleType,
                ':category_id' => $categoryId,
                ':title' => $title,
                ':summary' => $summary,
                ':cover_image_id' => $coverImageId,
                ':content_html' => $contentHtml,
                ':content_text' => $contentText,
                ':tags_text' => $tagsText,
                ':show_location' => $showLocation,
                ':location_address' => $locationAddress,
                ':location_lng' => $locationLng,
                ':location_lat' => $locationLat,
                ':comments_enabled' => $commentsEnabled,
                ':comment_password_enabled' => $commentPasswordEnabled,
                ':comment_password_hash' => $commentPasswordHash,
                ':like_seed' => $likeSeed,
                ':dislike_seed' => $dislikeSeed,
                ':status' => $status,
                ':status_for_publish' => $status,
                ':id' => $postArticleId,
                ':user_id' => $userId,
            ]);
            $savedArticleId = $postArticleId;
            ContentAudit::event($userId, 'article_update', 'article', $savedArticleId, ['status' => $status, 'article_type' => $articleType]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO articles
                 (user_id, article_type, category_id, title, summary, cover_image_id, content_html, content_text, tags_text, show_location, location_address, location_lng, location_lat, comments_enabled, comment_password_enabled, comment_password_hash, like_seed, dislike_seed, like_count, dislike_count, status, published_at, created_at, updated_at, deleted_at)
                 VALUES
                 (:user_id, :article_type, :category_id, :title, :summary, :cover_image_id, :content_html, :content_text, :tags_text, :show_location, :location_address, :location_lng, :location_lat, :comments_enabled, :comment_password_enabled, :comment_password_hash, :like_seed, :dislike_seed, 0, 0, :status, :published_at, NOW(), NOW(), NULL)'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':article_type' => $articleType,
                ':category_id' => $categoryId,
                ':title' => $title,
                ':summary' => $summary,
                ':cover_image_id' => $coverImageId,
                ':content_html' => $contentHtml,
                ':content_text' => $contentText,
                ':tags_text' => $tagsText,
                ':show_location' => $showLocation,
                ':location_address' => $locationAddress,
                ':location_lng' => $locationLng,
                ':location_lat' => $locationLat,
                ':comments_enabled' => $commentsEnabled,
                ':comment_password_enabled' => $commentPasswordEnabled,
                ':comment_password_hash' => $commentPasswordHash,
                ':like_seed' => $likeSeed,
                ':dislike_seed' => $dislikeSeed,
                ':status' => $status,
                ':published_at' => $status === 1 ? now_datetime() : null,
            ]);
            $savedArticleId = (int)$pdo->lastInsertId();
            ContentAudit::event($userId, 'article_create', 'article', $savedArticleId, ['status' => $status, 'article_type' => $articleType]);
        }

        af_bind_single_image($pdo, $coverImageId, $savedArticleId, $userId, ImageUploadService::ROLE_COVER);
        af_bind_tags($pdo, $savedArticleId, $userId, $tags);
        af_bind_images_by_role($pdo, $savedArticleId, $userId, $mainImageIds, ImageUploadService::ROLE_MAIN, 20);
        af_bind_images_by_role($pdo, $savedArticleId, $userId, $editorImageIds, ImageUploadService::ROLE_EDITOR, 20);
        $pdo->commit();
        flash_set('success', '文章已保存。');
        redirect('articles.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('article_save_error: ' . $e->getMessage());
        flash_set('danger', $e instanceof InvalidArgumentException ? $e->getMessage() : '操作失败，请稍后重试。');
        redirect('article_form.php' . (isset($postArticleId) && is_int($postArticleId) ? '?id=' . $postArticleId : ''));
    }
}

$categoriesStmt = $pdo->prepare('SELECT id, name FROM categories WHERE user_id = :user_id AND deleted_at IS NULL AND status = 1 ORDER BY sort_order ASC, id DESC');
$categoriesStmt->execute([':user_id' => $userId]);
$categories = $categoriesStmt->fetchAll();

$coverUrl = null;
if ($article && !empty($article['cover_image_id'])) {
    $coverUrl = 'media.php?id=' . (int)$article['cover_image_id'];
}

$mainImages = [];
if ($article) {
    $mainStmt = $pdo->prepare('SELECT id FROM article_images WHERE article_id = :article_id AND user_id = :user_id AND image_role = :image_role AND deleted_at IS NULL ORDER BY sort_order ASC, id ASC');
    $mainStmt->execute([':article_id' => (int)$article['id'], ':user_id' => $userId, ':image_role' => ImageUploadService::ROLE_MAIN]);
    foreach ($mainStmt->fetchAll() as $row) {
        if (is_array($row)) {
            $mainImages[] = ['id' => (int)$row['id'], 'url' => 'media.php?id=' . (int)$row['id']];
        }
    }
}

$editorInitialHtml = (string)($article['content_html'] ?? '');
$editorInitialHtml = str_replace(['src="/media.php?id=', "src='/media.php?id="], ['src="media.php?id=', "src='media.php?id="], $editorInitialHtml);
$articleType = (int)($article['article_type'] ?? 1);
if (!in_array($articleType, [1, 2], true)) {
    $articleType = 1;
}
$flashes = flash_get_all();
$token = Security::csrfToken();
$baiduAk = SiteSettings::baiduMapAk();
$mainImageCsv = implode(',', array_map(static fn(array $img): string => (string)$img['id'], $mainImages));
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $editing ? '编辑文章' : '发布文章' ?> - <?= e(SiteSettings::adminName()) ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@wangeditor/editor@5.1.23/dist/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="bg-light">
<?php render_admin_nav('publish', $user, $token); ?>
<main class="container py-4 article-editor-page">
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e((string)$flash['type']) ?>" role="alert"><?= e((string)$flash['message']) ?></div>
    <?php endforeach; ?>

    <?php if ($categories === []): ?>
        <div class="alert alert-warning">请先添加并启用至少一个分类。</div>
        <a class="btn btn-primary" href="categories.php"><i class="bi bi-folder-plus me-1" aria-hidden="true"></i>去添加分类</a>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="article-editor-form" id="articleForm">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>">
            <?php endif; ?>
            <input type="hidden" id="cover_image_id" name="cover_image_id" value="<?= e((string)($article['cover_image_id'] ?? '')) ?>">
            <input type="hidden" id="main_image_ids" name="main_image_ids" value="<?= e($mainImageCsv) ?>">

            <section class="editor-section editor-hero-section">
                <h1 class="h3 mb-2"><i class="bi bi-pencil-square me-2" aria-hidden="true"></i><?= $editing ? '编辑文章' : '发布文章' ?></h1>
                <p class="text-secondary mb-3">先选择文章类型，下方编辑区会自动切换对应内容。</p>
                <div class="article-type-switch" role="radiogroup" aria-label="文章类型">
                    <label class="article-type-option <?= $articleType === 1 ? 'is-active' : '' ?>">
                        <input type="radio" name="article_type" value="1" <?= $articleType === 1 ? 'checked' : '' ?>>
                        <span class="type-title"><i class="bi bi-images me-1" aria-hidden="true"></i>轮播图类型</span>
                        <span class="type-desc">封面 + 最多 20 张主图 + 富文本正文</span>
                    </label>
                    <label class="article-type-option <?= $articleType === 2 ? 'is-active' : '' ?>">
                        <input type="radio" name="article_type" value="2" <?= $articleType === 2 ? 'checked' : '' ?>>
                        <span class="type-title"><i class="bi bi-file-richtext me-1" aria-hidden="true"></i>普通类型</span>
                        <span class="type-desc">封面 + 富文本正文，适合常规文章</span>
                    </label>
                </div>
            </section>

            <section class="editor-section">
                <div class="mb-3">
                    <label class="form-label" for="title"><i class="bi bi-type me-1" aria-hidden="true"></i>标题</label>
                    <input class="form-control form-control-lg" id="title" name="title" maxlength="150" required value="<?= e((string)($article['title'] ?? '')) ?>">
                </div>
                <div class="mb-0">
                    <label class="form-label" for="summary"><i class="bi bi-card-text me-1" aria-hidden="true"></i>摘要</label>
                    <textarea class="form-control" id="summary" name="summary" maxlength="300" rows="3" placeholder="简短概括文章内容，最多 300 字。"><?= e((string)($article['summary'] ?? '')) ?></textarea>
                </div>
            </section>

            <section class="editor-section">
                <div class="section-title compact-title">
                    <div>
                        <h2 class="h5 mb-0"><i class="bi bi-image me-1" aria-hidden="true"></i>封面图</h2>
                    </div>
                    <span class="text-secondary small">最终裁剪为 800×800</span>
                </div>
                <div class="cover-upload-shell">
                    <button class="cover-upload-box <?= $coverUrl ? 'has-image' : '' ?>" type="button" id="coverUploadBox" aria-label="设置封面图">
                        <img src="<?= $coverUrl ? e((string)$coverUrl) : '' ?>" alt="封面预览" id="coverPreview" class="<?= $coverUrl ? '' : 'd-none' ?>">
                        <i class="bi bi-image cover-upload-icon <?= $coverUrl ? 'd-none' : '' ?>" id="coverPlaceholder" aria-hidden="true"></i>
                    </button>
                    <input type="file" id="coverFileInput" class="d-none" accept="image/jpeg,image/png,image/webp">
                    <div class="upload-note">支持 jpg / png / webp；裁剪输出 800px × 800px；后端会再次检测 MIME、重新编码并剥离 EXIF。</div>
                </div>
            </section>

            <section class="editor-section carousel-only" data-type-visible="carousel">
                <div class="section-title compact-title">
                    <div>
                        <h2 class="h5 mb-0"><i class="bi bi-images me-1" aria-hidden="true"></i>主图</h2>
                    </div>
                    <span class="text-secondary small">最多 20 张，每张 20MB</span>
                </div>
                <div class="main-image-uploader">
                    <button class="main-image-add" type="button" id="mainImageAddButton" title="上传主图" aria-label="上传主图">
                        <i class="bi bi-plus-circle-dotted" aria-hidden="true"></i>
                    </button>
                    <input type="file" id="mainImageInput" class="d-none" accept="image/jpeg,image/png,image/webp" multiple>
                    <div class="main-image-grid" id="mainImageGrid">
                        <?php foreach ($mainImages as $img): ?>
                            <div class="main-image-thumb" data-image-id="<?= (int)$img['id'] ?>">
                                <img src="<?= e((string)$img['url']) ?>" alt="主图">
                                <button type="button" class="main-image-delete" data-delete-image title="删除主图" aria-label="删除主图"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="editor-section">
                <label class="form-label" for="contentEditor"><i class="bi bi-braces-asterisk me-1" aria-hidden="true"></i>富文本编辑器</label>
                <div id="editorToolbar" class="editor-toolbar border rounded-top bg-white"></div>
                <div id="contentEditor" class="editor-area border border-top-0 rounded-bottom bg-white" data-upload-url="upload_article_image.php" data-csrf-token="<?= e($token) ?>" data-initial-html="<?= e($editorInitialHtml) ?>"></div>
                <textarea class="d-none" id="content_html" name="content_html"><?= e($editorInitialHtml) ?></textarea>
                <div class="progress mt-2 d-none" id="uploadProgress" role="progressbar" aria-label="上传进度">
                    <div class="progress-bar" id="uploadProgressBar" style="width:0%">0%</div>
                </div>
                <div class="form-text">富文本图片最多 20 张，单张 20MB；提交后后端会使用白名单清洗富文本。</div>
            </section>

            <section class="editor-section">
                <label class="form-label" for="tags"><i class="bi bi-tags me-1" aria-hidden="true"></i>标签</label>
                <input class="form-control" id="tags" name="tags" maxlength="500" placeholder="#宠物 #生活 #随手拍" value="<?= e((string)($article['tags_text'] ? '#' . str_replace(',', ' #', (string)$article['tags_text']) : '')) ?>">
                <div class="form-text">前端按 #标签 输入，后端会提取词组并去掉 # 存储。</div>
            </section>

            <section class="editor-section">
                <h2 class="h5 mb-3"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i>定位地址</h2>
                <?php $showLocationOn = (int)($article['show_location'] ?? 0) === 1; ?>
                <div class="visual-toggle-field mb-3">
                    <input class="visual-toggle-input visually-hidden" type="checkbox" role="switch" id="show_location" name="show_location" value="1" <?= $showLocationOn ? 'checked' : '' ?>>
                    <label class="visual-toggle-button <?= $showLocationOn ? 'is-on' : 'is-off' ?>" for="show_location" title="前端显示位置">
                        <i class="bi <?= $showLocationOn ? 'bi-toggle-on' : 'bi-toggle-off' ?> visual-toggle-icon" aria-hidden="true"></i>
                        <span>前端显示位置</span>
                    </label>
                </div>
                <input type="hidden" id="location_address" name="location_address" value="<?= e((string)($article['location_address'] ?? '')) ?>">
                <input type="hidden" id="location_lng" name="location_lng" value="<?= e((string)($article['location_lng'] ?? '')) ?>">
                <input type="hidden" id="location_lat" name="location_lat" value="<?= e((string)($article['location_lat'] ?? '')) ?>">
                <div class="location-picker-card" data-baidu-ak="<?= e($baiduAk) ?>" data-has-point="<?= ($article && $article['location_lng'] !== null && $article['location_lat'] !== null) ? '1' : '0' ?>">
                    <button class="location-address-button" type="button" id="location_address_display">
                        <span class="location-address-text" id="locationAddressText"><?= e((string)(($article['location_address'] ?? '') !== '' ? (string)$article['location_address'] : '点击选择当前位置或手动在地图上选点')) ?></span>
                        <span class="location-address-action"><i class="bi bi-map me-1" aria-hidden="true"></i>选择位置</span>
                    </button>
                    <div class="form-text mt-2">页面只显示地址；点击后弹窗打开地图，可默认定位当前位置，也可拖动标记点。</div>
                    <?php if ($baiduAk === ''): ?>
                        <div class="alert alert-warning mt-3 mb-0">未配置百度地图 AK，请先到 <a href="settings.php"><i class="bi bi-gear me-1" aria-hidden="true"></i>系统设置</a> 填写浏览器端 AK。</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="editor-section">
                <label class="form-label" for="category_id"><i class="bi bi-folder2-open me-1" aria-hidden="true"></i>分类</label>
                <select class="form-select" id="category_id" name="category_id" required>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int)$category['id'] ?>" <?= (int)($article['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>><?= e((string)$category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </section>

            <section class="editor-section">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="status"><i class="bi bi-toggle-on me-1" aria-hidden="true"></i>文章状态</label>
                        <select class="form-select" id="status" name="status">
                            <option value="0" <?= (int)($article['status'] ?? 0) === 0 ? 'selected' : '' ?>>草稿</option>
                            <option value="1" <?= (int)($article['status'] ?? 0) === 1 ? 'selected' : '' ?>>上架</option>
                            <option value="2" <?= (int)($article['status'] ?? 0) === 2 ? 'selected' : '' ?>>下架</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 d-flex align-items-end gap-4 flex-wrap">
                        <?php $commentsOn = (int)($article['comments_enabled'] ?? 1) === 1; ?>
                        <?php $commentPasswordOn = (int)($article['comment_password_enabled'] ?? 0) === 1; ?>
                        <div class="visual-toggle-field mb-2">
                            <input class="visual-toggle-input visually-hidden" type="checkbox" role="switch" id="comments_enabled" name="comments_enabled" value="1" <?= $commentsOn ? 'checked' : '' ?>>
                            <label class="visual-toggle-button <?= $commentsOn ? 'is-on' : 'is-off' ?>" for="comments_enabled" title="允许评论">
                                <i class="bi <?= $commentsOn ? 'bi-toggle-on' : 'bi-toggle-off' ?> visual-toggle-icon" aria-hidden="true"></i>
                                <span>允许评论</span>
                            </label>
                        </div>
                        <div class="visual-toggle-field mb-2">
                            <input class="visual-toggle-input visually-hidden" type="checkbox" role="switch" id="comment_password_enabled" name="comment_password_enabled" value="1" <?= $commentPasswordOn ? 'checked' : '' ?>>
                            <label class="visual-toggle-button <?= $commentPasswordOn ? 'is-on' : 'is-off' ?>" for="comment_password_enabled" title="评论需密码">
                                <i class="bi <?= $commentPasswordOn ? 'bi-toggle-on' : 'bi-toggle-off' ?> visual-toggle-icon" aria-hidden="true"></i>
                                <span>评论需密码</span>
                            </label>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="comment_password"><i class="bi bi-key me-1" aria-hidden="true"></i>评论密码</label>
                        <input class="form-control" id="comment_password" name="comment_password" type="password" autocomplete="new-password" placeholder="编辑时留空表示不修改">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="like_seed"><i class="bi bi-hand-thumbs-up me-1" aria-hidden="true"></i>初始点赞</label>
                        <input class="form-control" id="like_seed" name="like_seed" inputmode="numeric" value="<?= (int)($article['like_seed'] ?? 0) ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="dislike_seed"><i class="bi bi-hand-thumbs-down me-1" aria-hidden="true"></i>初始踩数</label>
                        <input class="form-control" id="dislike_seed" name="dislike_seed" inputmode="numeric" value="<?= (int)($article['dislike_seed'] ?? 0) ?>">
                    </div>
                </div>
            </section>

            <div class="editor-submit-bar">
                <div class="text-secondary small">保存前会再次进行 CSRF、身份锁、参数范围、图片归属、富文本清洗校验。</div>
                <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-save me-1" aria-hidden="true"></i>保存文章</button>
            </div>
        </form>
    <?php endif; ?>
</main>

<div class="crop-modal" id="coverCropModal" aria-hidden="true">
    <div class="crop-modal-backdrop" data-close-crop-modal></div>
    <div class="crop-modal-panel" role="dialog" aria-modal="true" aria-labelledby="coverCropTitle">
        <div class="crop-modal-header">
            <div>
                <h2 class="h5 mb-1" id="coverCropTitle"><i class="bi bi-crop me-1" aria-hidden="true"></i>裁剪封面</h2>
                <div class="small text-muted">请裁剪为正方形，最终上传为 800×800 像素。</div>
            </div>
            <button class="btn btn-light btn-sm btn-icon" type="button" data-close-crop-modal title="关闭" aria-label="关闭"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="cover-crop-stage"><img id="coverCropImage" alt="待裁剪封面"></div>
        <div class="map-modal-actions mt-3">
            <button class="btn btn-outline-secondary btn-icon" type="button" data-close-crop-modal title="取消" aria-label="取消"><i class="bi bi-x-circle" aria-hidden="true"></i></button>
            <button class="btn btn-primary ms-auto" type="button" id="confirmCoverCrop"><i class="bi bi-cloud-arrow-up me-1" aria-hidden="true"></i>裁剪并上传</button>
        </div>
    </div>
</div>

<div class="map-modal" id="locationMapModal" aria-hidden="true">
    <div class="map-modal-backdrop" data-close-map-modal></div>
    <div class="map-modal-panel" role="dialog" aria-modal="true" aria-labelledby="mapModalTitle">
        <div class="map-modal-header">
            <div>
                <h2 class="h5 mb-1" id="mapModalTitle"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i>选择文章位置</h2>
                <div class="small text-muted" id="baiduMapHint">可自动定位当前位置，也可以点击地图或拖动标记点选择地址。</div>
            </div>
            <button class="btn btn-light btn-sm btn-icon" type="button" data-close-map-modal title="关闭" aria-label="关闭"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div id="baiduMapBox" class="map-box map-box-modal">
            <div class="map-placeholder"><?= $baiduAk === '' ? '未配置百度地图 AK，请先到“系统设置”填写百度地图浏览器端 AK。' : '点击“选择位置”后加载地图...' ?></div>
        </div>
        <div class="map-modal-selected mt-3">
            <div class="small text-muted mb-1">当前选择</div>
            <div class="selected-address" id="mapSelectedAddress"><?= e((string)(($article['location_address'] ?? '') !== '' ? (string)$article['location_address'] : '尚未选择地址')) ?></div>
            <div class="selected-coordinates small text-muted mt-1" id="mapSelectedCoordinates">
                <?= ($article && $article['location_lng'] !== null && $article['location_lat'] !== null) ? e((string)$article['location_lng'] . ', ' . (string)$article['location_lat']) : '未选择坐标' ?>
            </div>
        </div>
        <div class="map-modal-actions mt-3">
            <button class="btn btn-outline-secondary" type="button" id="useBrowserLocation"><i class="bi bi-crosshair me-1" aria-hidden="true"></i>获取当前位置</button>
            <button class="btn btn-outline-danger" type="button" id="clearLocationButton"><i class="bi bi-eraser me-1" aria-hidden="true"></i>清除位置</button>
            <a class="btn btn-outline-primary" href="settings.php"><i class="bi bi-gear me-1" aria-hidden="true"></i>设置百度地图 AK</a>
            <button class="btn btn-primary ms-auto" type="button" id="confirmMapLocation"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>确定使用此位置</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@wangeditor/editor@5.1.23/dist/index.js"></script>
<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
<script src="assets/js/article_form.js"></script>
</body>
</html>
