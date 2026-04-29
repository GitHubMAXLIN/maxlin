<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
$user = Auth::requireLogin();
$userId = (int)$user['id'];
$pdo = Database::pdo();
BlogSchema::ensureArticleTypeColumn($pdo);

function dashboard_like_pattern(string $value): string
{
    $value = trim($value);
    $value = mb_substr($value, 0, 80, 'UTF-8');
    return '%' . addcslashes($value, "\\%_") . '%';
}

$searchKeyword = trim((string)($_GET['q'] ?? ''));
$searchKeyword = mb_substr($searchKeyword, 0, 80, 'UTF-8');

$stats = [
    'today_unique_ips' => 0,
    'today_unique_visitors' => 0,
    'today_views' => 0,
    'seven_unique_ips' => 0,
    'seven_unique_visitors' => 0,
    'seven_views' => 0,
    'today_likes' => 0,
    'today_dislikes' => 0,
    'today_comments' => 0,
    'seven_likes' => 0,
    'seven_dislikes' => 0,
    'seven_comments' => 0,
];

try {
    $stats = VisitTracker::dashboardStats($userId);
} catch (Throwable $e) {
    error_log('dashboard_stats_error: ' . $e->getMessage());
}

$articleWhere = 'a.user_id = :user_id AND a.deleted_at IS NULL';
$articleParams = [':user_id' => $userId];
if ($searchKeyword !== '') {
    $articleWhere .= " AND (a.title LIKE :q_title ESCAPE '\\\\' OR a.summary LIKE :q_summary ESCAPE '\\\\' OR a.tags_text LIKE :q_tags ESCAPE '\\\\')";
    $likePattern = dashboard_like_pattern($searchKeyword);
    $articleParams[':q_title'] = $likePattern;
    $articleParams[':q_summary'] = $likePattern;
    $articleParams[':q_tags'] = $likePattern;
}

try {
    $stmt = $pdo->prepare(
        'SELECT a.id, a.title, a.summary, a.article_type, a.status, a.like_seed, a.dislike_seed, a.like_count, a.dislike_count, a.created_at, a.updated_at,
                c.name AS category_name
         FROM articles a
         JOIN categories c ON c.id = a.category_id AND c.user_id = a.user_id
         WHERE ' . $articleWhere . '
         ORDER BY a.id DESC
         LIMIT 12'
    );
    $stmt->execute($articleParams);
    $dashboardArticles = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('dashboard_article_list_error: ' . $e->getMessage());
    $dashboardArticles = [];
    flash_set('danger', '控制台文章列表加载失败，请查看 PHP 错误日志。');
}

$flashes = flash_get_all();
$token = Security::csrfToken();
$statusMap = [0 => ['草稿', 'secondary', 'bi-file-earmark'], 1 => ['上架', 'success', 'bi-cloud-check'], 2 => ['下架', 'warning', 'bi-archive']];
$typeMap = [1 => '轮播图', 2 => '普通'];
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(SiteSettings::adminName()) ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
<?php render_admin_nav('dashboard', $user, $token); ?>
<main class="container-fluid dashboard-shell py-4">
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e((string)$flash['type']) ?>" role="alert"><?= e((string)$flash['message']) ?></div>
    <?php endforeach; ?>

    <div class="dashboard-hero mb-4">
        <div>
            <span class="badge text-bg-primary mb-2"><i class="bi bi-activity me-1" aria-hidden="true"></i>Overview</span>
            <h1 class="h3 fw-semibold mb-1">控制台</h1>
            <p class="text-secondary mb-0">只展示核心数据与文章入口，访问 IP、访客数均按哈希去重统计。</p>
        </div>
        <form class="dashboard-search" method="get" action="dashboard.php" role="search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input class="form-control" type="search" name="q" value="<?= e($searchKeyword) ?>" maxlength="80" placeholder="搜索文章标题、摘要、标签">
            <button class="btn btn-primary" type="submit">搜索</button>
            <?php if ($searchKeyword !== ''): ?>
                <a class="btn btn-outline-secondary btn-icon" href="dashboard.php" title="清空搜索" aria-label="清空搜索"><i class="bi bi-x-lg" aria-hidden="true"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="metric-card">
                <div class="metric-icon"><i class="bi bi-router" aria-hidden="true"></i></div>
                <div>
                    <div class="metric-label">今日独立 IP</div>
                    <div class="metric-value"><?= (int)$stats['today_unique_ips'] ?></div>
                    <div class="metric-sub">7日 <?= (int)$stats['seven_unique_ips'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="metric-card">
                <div class="metric-icon success"><i class="bi bi-people" aria-hidden="true"></i></div>
                <div>
                    <div class="metric-label">今日访客</div>
                    <div class="metric-value"><?= (int)$stats['today_unique_visitors'] ?></div>
                    <div class="metric-sub">7日 <?= (int)$stats['seven_unique_visitors'] ?>，今日浏览 <?= (int)$stats['today_views'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="metric-card">
                <div class="metric-icon like"><i class="bi bi-hand-thumbs-up" aria-hidden="true"></i></div>
                <div>
                    <div class="metric-label">今日点赞 / 踩</div>
                    <div class="metric-value"><?= (int)$stats['today_likes'] ?> / <?= (int)$stats['today_dislikes'] ?></div>
                    <div class="metric-sub">7日 <?= (int)$stats['seven_likes'] ?> / <?= (int)$stats['seven_dislikes'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="metric-card">
                <div class="metric-icon comment"><i class="bi bi-chat-left-text" aria-hidden="true"></i></div>
                <div>
                    <div class="metric-label">今日留言</div>
                    <div class="metric-value"><?= (int)$stats['today_comments'] ?></div>
                    <div class="metric-sub">7日 <?= (int)$stats['seven_comments'] ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card dashboard-list-card">
        <div class="card-body">
            <div class="section-title mb-3">
                <div>
                    <h2 class="h5 mb-1"><i class="bi bi-card-list me-1" aria-hidden="true"></i><?= $searchKeyword === '' ? '文章列表' : '搜索结果' ?></h2>
                    <p class="text-secondary small mb-0"><?= $searchKeyword === '' ? '显示最近更新的文章。' : '关键词：' . e($searchKeyword) ?></p>
                </div>
                <div class="d-flex gap-2 flex-wrap justify-content-end">
                    <a class="btn btn-outline-secondary btn-sm" href="articles.php"><i class="bi bi-list-ul me-1" aria-hidden="true"></i>全部文章</a>
                    <a class="btn btn-primary btn-sm" href="article_form.php"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>发布</a>
                </div>
            </div>

            <?php if ($dashboardArticles === []): ?>
                <div class="empty-state"><i class="bi bi-inbox me-1" aria-hidden="true"></i>暂无匹配文章。</div>
            <?php else: ?>
                <div class="dashboard-article-list">
                    <?php foreach ($dashboardArticles as $article): ?>
                        <?php $status = (int)$article['status']; $badge = $statusMap[$status] ?? ['未知', 'secondary', 'bi-question-circle']; ?>
                        <div class="dashboard-article-row">
                            <div class="article-row-main">
                                <div class="article-row-title">
                                    <a href="article_form.php?id=<?= (int)$article['id'] ?>"><?= e((string)$article['title']) ?></a>
                                </div>
                                <div class="article-row-meta">
                                    <span><i class="bi bi-folder" aria-hidden="true"></i><?= e((string)$article['category_name']) ?></span>
                                    <span><i class="bi bi-layers" aria-hidden="true"></i><?= e($typeMap[(int)($article['article_type'] ?? 1)] ?? '轮播图') ?></span>
                                    <span><i class="bi bi-clock" aria-hidden="true"></i><?= e((string)$article['updated_at']) ?></span>
                                </div>
                            </div>
                            <div class="article-row-stats">
                                <span class="badge text-bg-<?= e($badge[1]) ?>"><i class="bi <?= e($badge[2]) ?> me-1" aria-hidden="true"></i><?= e($badge[0]) ?></span>
                                <span class="text-secondary small"><i class="bi bi-hand-thumbs-up" aria-hidden="true"></i><?= (int)$article['like_seed'] + (int)$article['like_count'] ?></span>
                                <span class="text-secondary small"><i class="bi bi-hand-thumbs-down" aria-hidden="true"></i><?= (int)$article['dislike_seed'] + (int)$article['dislike_count'] ?></span>
                                <a class="btn btn-sm btn-outline-primary btn-icon" href="article_form.php?id=<?= (int)$article['id'] ?>" title="编辑" aria-label="编辑"><i class="bi bi-pencil-square" aria-hidden="true"></i></a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
