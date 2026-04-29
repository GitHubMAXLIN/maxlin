<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
$pdo = Database::pdo();
BlogSchema::ensureArticleTypeColumn($pdo);

$articleId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_int($articleId)) {
    http_response_code(404);
    exit('Not Found');
}
$stmt = $pdo->prepare(
    'SELECT a.*, c.name AS category_name
     FROM articles a
     JOIN categories c ON c.id = a.category_id
     WHERE a.id = :id AND a.status = 1 AND a.deleted_at IS NULL AND c.status = 1 AND c.deleted_at IS NULL
     LIMIT 1'
);
$stmt->execute([':id' => $articleId]);
$article = $stmt->fetch();
if (!is_array($article)) {
    http_response_code(404);
    exit('Not Found');
}
VisitTracker::trackFrontVisit('article', (int)$article['id']);
$mainImages = [];
if ((int)($article['article_type'] ?? 1) === 1) {
    $mainStmt = $pdo->prepare('SELECT id FROM article_images WHERE article_id = :article_id AND image_role = :image_role AND deleted_at IS NULL ORDER BY sort_order ASC, id ASC');
    $mainStmt->execute([':article_id' => (int)$article['id'], ':image_role' => ImageUploadService::ROLE_MAIN]);
    foreach ($mainStmt->fetchAll() as $row) {
        if (is_array($row)) { $mainImages[] = (int)$row['id']; }
    }
}

$token = Security::csrfToken();
$flashes = flash_get_all();
$tags = array_filter(array_map('trim', explode(',', (string)($article['tags_text'] ?? ''))));
$likeTotal = (int)$article['like_seed'] + (int)$article['like_count'];
$dislikeTotal = (int)$article['dislike_seed'] + (int)$article['dislike_count'];
$contentHtml = str_replace(['src="/media.php?id=', "src='/media.php?id="], ['src="media.php?id=', "src='media.php?id="], (string)$article['content_html']);
$siteName = SiteSettings::siteName();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e((string)$article['title']) ?> - <?= e($siteName) ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
<nav class="navbar border-bottom sticky-top">
    <div class="container">
        <a class="navbar-brand" href="index.php"><i class="bi bi-journal-richtext me-1" aria-hidden="true"></i><?= e($siteName) ?></a>
        <a class="btn btn-outline-secondary btn-sm" href="login.php"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>后台登录</a>
    </div>
</nav>
<main class="container py-4 py-md-5">
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e((string)$flash['type']) ?>" role="alert"><?= e((string)$flash['message']) ?></div>
    <?php endforeach; ?>

    <article class="card overflow-hidden">
        <?php if (!empty($article['cover_image_id'])): ?>
            <img src="media.php?id=<?= (int)$article['cover_image_id'] ?>" alt="封面" class="w-100" style="max-height:420px;object-fit:cover;">
        <?php endif; ?>
        <div class="card-body p-4 p-md-5">
            <div class="mb-3">
                <span class="badge text-bg-primary"><i class="bi bi-folder me-1" aria-hidden="true"></i><?= e((string)$article['category_name']) ?></span>
            </div>
            <h1 class="display-6 fw-semibold mb-3"><?= e((string)$article['title']) ?></h1>
            <?php if (!empty($article['summary'])): ?>
                <p class="lead text-secondary"><?= e((string)$article['summary']) ?></p>
            <?php endif; ?>
            <?php if ($tags !== []): ?>
                <div class="mb-4">
                    <?php foreach ($tags as $tag): ?>
                        <span class="badge rounded-pill text-bg-light border me-1"><i class="bi bi-tag me-1" aria-hidden="true"></i><?= e($tag) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($mainImages !== []): ?>
                <div class="carousel-main-gallery mb-4">
                    <?php foreach ($mainImages as $mainImageId): ?>
                        <img src="media.php?id=<?= (int)$mainImageId ?>" alt="主图">
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="article-content mb-4">
                <?= $contentHtml ?>
            </div>
            <?php if ((int)$article['show_location'] === 1): ?>
                <div class="alert alert-secondary">
                    <div class="fw-semibold mb-1"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i>位置</div>
                    <div><?= e((string)($article['location_address'] ?? '')) ?></div>
                    <div class="small text-secondary">经度 <?= e((string)$article['location_lng']) ?>，纬度 <?= e((string)$article['location_lat']) ?></div>
                </div>
            <?php endif; ?>
            <div class="d-flex gap-2 flex-wrap align-items-center border-top pt-3">
                <form method="post" action="article_react.php" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                    <input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>">
                    <input type="hidden" name="reaction_type" value="1">
                    <button class="btn btn-outline-success" type="submit"><i class="bi bi-hand-thumbs-up me-1" aria-hidden="true"></i>点赞 <?= $likeTotal ?></button>
                </form>
                <form method="post" action="article_react.php" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                    <input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>">
                    <input type="hidden" name="reaction_type" value="2">
                    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-hand-thumbs-down me-1" aria-hidden="true"></i>踩 <?= $dislikeTotal ?></button>
                </form>
                <?php if ((int)$article['comments_enabled'] === 0): ?>
                    <span class="text-secondary small"><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i>评论已关闭</span>
                <?php elseif ((int)$article['comment_password_enabled'] === 1): ?>
                    <span class="text-secondary small"><i class="bi bi-lock me-1" aria-hidden="true"></i>评论区已开启密码保护</span>
                <?php endif; ?>
            </div>
        </div>
    </article>
</main>
</body>
</html>
