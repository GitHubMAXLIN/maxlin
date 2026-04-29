<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pdo = Database::pdo();
$stmt = $pdo->prepare(
    'SELECT a.id, a.title, a.summary, a.cover_image_id, a.tags_text, a.published_at, c.name AS category_name
     FROM articles a
     JOIN categories c ON c.id = a.category_id
     WHERE a.status = 1 AND a.deleted_at IS NULL AND c.status = 1 AND c.deleted_at IS NULL
     ORDER BY COALESCE(a.published_at, a.created_at) DESC, a.id DESC
     LIMIT 30'
);
$stmt->execute();
$articles = $stmt->fetchAll();
$siteName = SiteSettings::siteName();
VisitTracker::trackFrontVisit('index');
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($siteName) ?></title>
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
    <div class="page-hero text-center">
        <span class="badge text-bg-primary mb-3"><i class="bi bi-journal-text me-1" aria-hidden="true"></i>Blog</span>
        <h1 class="display-6 fw-semibold mb-2"><i class="bi bi-card-list me-2" aria-hidden="true"></i>文章列表</h1>
        <p class="text-secondary mb-0">这里只展示已经上架的文章。</p>
    </div>
    <?php if ($articles === []): ?>
        <div class="card"><div class="empty-state"><i class="bi bi-inbox me-1" aria-hidden="true"></i>暂无文章</div></div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($articles as $article): ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <article class="card h-100 overflow-hidden dashboard-card">
                        <?php if (!empty($article['cover_image_id'])): ?>
                            <img src="media.php?id=<?= (int)$article['cover_image_id'] ?>" class="card-img-top" alt="封面" style="height:210px;object-fit:cover;">
                        <?php endif; ?>
                        <div class="card-body d-flex flex-column">
                            <div class="mb-2"><span class="badge text-bg-primary"><i class="bi bi-folder me-1" aria-hidden="true"></i><?= e((string)$article['category_name']) ?></span></div>
                            <h2 class="h5"><a class="text-dark text-decoration-none" href="article.php?id=<?= (int)$article['id'] ?>"><?= e((string)$article['title']) ?></a></h2>
                            <?php if (!empty($article['summary'])): ?>
                                <p class="text-secondary flex-grow-1"><?= e((string)$article['summary']) ?></p>
                            <?php else: ?>
                                <div class="flex-grow-1"></div>
                            <?php endif; ?>
                            <a class="btn btn-outline-primary mt-2" href="article.php?id=<?= (int)$article['id'] ?>"><i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>阅读文章</a>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
