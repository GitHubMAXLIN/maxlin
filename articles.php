<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
$user = Auth::requireLogin();
$userId = (int)$user['id'];
$pdo = Database::pdo();
BlogSchema::ensureArticleTypeColumn($pdo);

function articles_post_int(string $key, int $min, int $max): int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if (!is_int($value)) {
        throw new InvalidArgumentException('参数不合法。');
    }
    return $value;
}

function articles_like_pattern(string $value): string
{
    $value = trim($value);
    $value = mb_substr($value, 0, 80, 'UTF-8');
    return '%' . addcslashes($value, "\\%_") . '%';
}

function articles_page_url(int $statusFilter, string $searchKeyword, int $perPage, int $page, array $overrides = []): string
{
    $params = [];
    if ($statusFilter !== -1) {
        $params['status'] = $statusFilter;
    }
    if ($searchKeyword !== '') {
        $params['q'] = $searchKeyword;
    }
    if ($perPage !== 10) {
        $params['per_page'] = $perPage;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }
        $params[$key] = $value;
    }

    return 'articles.php' . ($params === [] ? '' : '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
}

if (is_post()) {
    Security::requirePostCsrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        $articleId = articles_post_int('article_id', 1, PHP_INT_MAX);
        ResourceGuard::requireOwnedArticle($articleId, $userId);

        if ($action === 'status') {
            $status = articles_post_int('status', 0, 2);
            $stmt = $pdo->prepare(
                'UPDATE articles
                 SET status = :status,
                     published_at = CASE WHEN :status_for_publish = 1 AND published_at IS NULL THEN NOW() ELSE published_at END,
                     updated_at = NOW()
                 WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
            );
            $stmt->execute([
                ':status' => $status,
                ':status_for_publish' => $status,
                ':id' => $articleId,
                ':user_id' => $userId,
            ]);
            ContentAudit::event($userId, 'article_status_update', 'article', $articleId, ['status' => $status]);
            flash_set('success', '文章状态已更新。');
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare(
                'UPDATE articles
                 SET deleted_at = NOW(), updated_at = NOW()
                 WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
            );
            $stmt->execute([':id' => $articleId, ':user_id' => $userId]);
            ContentAudit::event($userId, 'article_delete', 'article', $articleId);
            flash_set('success', '文章已删除。');
        } else {
            throw new InvalidArgumentException('未知操作。');
        }
    } catch (Throwable $e) {
        error_log('article_list_action_error: ' . $e->getMessage());
        flash_set('danger', $e instanceof InvalidArgumentException ? $e->getMessage() : '操作失败，请稍后重试。');
    }
    redirect('articles.php');
}

$statusFilter = filter_var($_GET['status'] ?? -1, FILTER_VALIDATE_INT, ['options' => ['min_range' => -1, 'max_range' => 2]]);
if (!is_int($statusFilter)) {
    $statusFilter = -1;
}
$searchKeyword = trim((string)($_GET['q'] ?? ''));
$searchKeyword = mb_substr($searchKeyword, 0, 80, 'UTF-8');

$allowedPerPages = [10, 20, 50, 100];
$perPage = filter_var($_GET['per_page'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 100]]);
if (!is_int($perPage) || !in_array($perPage, $allowedPerPages, true)) {
    $perPage = 10;
}
$currentPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
if (!is_int($currentPage)) {
    $currentPage = 1;
}

$articleWhere = 'a.user_id = :user_id AND a.deleted_at IS NULL';
$articleParams = [':user_id' => $userId];
if ($statusFilter !== -1) {
    $articleWhere .= ' AND a.status = :status_filter';
    $articleParams[':status_filter'] = $statusFilter;
}
if ($searchKeyword !== '') {
    $articleWhere .= " AND (a.title LIKE :q_title ESCAPE '\\\\' OR a.summary LIKE :q_summary ESCAPE '\\\\' OR a.tags_text LIKE :q_tags ESCAPE '\\\\')";
    $likePattern = articles_like_pattern($searchKeyword);
    $articleParams[':q_title'] = $likePattern;
    $articleParams[':q_summary'] = $likePattern;
    $articleParams[':q_tags'] = $likePattern;
}

try {
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM articles a
         JOIN categories c ON c.id = a.category_id AND c.user_id = a.user_id
         WHERE ' . $articleWhere
    );
    foreach ($articleParams as $key => $value) {
        $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalArticles = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalArticles / $perPage));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }
    $offset = ($currentPage - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT a.id, a.title, a.summary, a.cover_image_id, a.article_type, a.status, a.like_seed, a.dislike_seed, a.like_count, a.dislike_count, a.created_at, a.updated_at,
                c.name AS category_name
         FROM articles a
         JOIN categories c ON c.id = a.category_id AND c.user_id = a.user_id
         WHERE ' . $articleWhere . '
         ORDER BY a.id DESC
         LIMIT :limit OFFSET :offset'
    );
    foreach ($articleParams as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $articles = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('article_list_query_error: ' . $e->getMessage());
    $articles = [];
    $totalArticles = 0;
    $totalPages = 1;
    $currentPage = 1;
    flash_set('danger', '文章列表加载失败，请确认数据库升级已执行，或查看 PHP 错误日志。');
}

$flashes = flash_get_all();
$token = Security::csrfToken();
$statusMap = [0 => ['草稿', 'secondary'], 1 => ['上架', 'success'], 2 => ['下架', 'warning']];
$typeMap = [1 => '轮播图', 2 => '普通'];
$paginationStart = max(1, $currentPage - 2);
$paginationEnd = min($totalPages, $currentPage + 2);
if ($paginationEnd - $paginationStart < 4) {
    $paginationStart = max(1, $paginationEnd - 4);
    $paginationEnd = min($totalPages, $paginationStart + 4);
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>文章列表 - <?= e(SiteSettings::adminName()) ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
<?php render_admin_nav('articles', $user, $token); ?>
<main class="container py-4 article-list-page">
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e((string)$flash['type']) ?>" role="alert"><?= e((string)$flash['message']) ?></div>
    <?php endforeach; ?>

    <div class="section-title page-hero">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-card-list me-2" aria-hidden="true"></i>文章列表</h1>
        </div>
        <a class="btn btn-primary btn-sm" href="article_form.php"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>发布文章</a>
    </div>

    <div class="card mb-4 article-filter-card">
        <div class="card-body">
            <form class="article-search-bar mb-3" method="get" action="articles.php" role="search">
                <?php if ($statusFilter !== -1): ?>
                    <input type="hidden" name="status" value="<?= (int)$statusFilter ?>">
                <?php endif; ?>
                <?php if ($perPage !== 10): ?>
                    <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                <?php endif; ?>
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" name="q" value="<?= e($searchKeyword) ?>" maxlength="80" placeholder="搜索文章标题、摘要、标签">
                <button class="btn btn-primary btn-sm article-search-submit" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="ms-1">搜索</span></button>
                <?php if ($searchKeyword !== ''): ?>
                    <a class="btn btn-outline-secondary btn-sm btn-icon" href="<?= e(articles_page_url($statusFilter, '', $perPage, 1)) ?>" title="清空搜索" aria-label="清空搜索"><i class="bi bi-x-lg" aria-hidden="true"></i></a>
                <?php endif; ?>
            </form>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="btn-group btn-group-sm status-filter-icons" role="group" aria-label="文章状态筛选">
                    <a class="btn btn<?= $statusFilter === -1 ? '' : '-outline' ?>-secondary" href="<?= e(articles_page_url(-1, $searchKeyword, $perPage, 1, ['status' => null])) ?>" title="全部" aria-label="全部"><i class="bi bi-grid" aria-hidden="true"></i></a>
                    <a class="btn btn<?= $statusFilter === 0 ? '' : '-outline' ?>-secondary" href="<?= e(articles_page_url($statusFilter, $searchKeyword, $perPage, 1, ['status' => 0])) ?>" title="草稿" aria-label="草稿"><i class="bi bi-file-earmark" aria-hidden="true"></i></a>
                    <a class="btn btn<?= $statusFilter === 1 ? '' : '-outline' ?>-success" href="<?= e(articles_page_url($statusFilter, $searchKeyword, $perPage, 1, ['status' => 1])) ?>" title="上架" aria-label="上架"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i></a>
                    <a class="btn btn<?= $statusFilter === 2 ? '' : '-outline' ?>-warning" href="<?= e(articles_page_url($statusFilter, $searchKeyword, $perPage, 1, ['status' => 2])) ?>" title="下架" aria-label="下架"><i class="bi bi-archive" aria-hidden="true"></i></a>
                </div>
                <div class="d-flex align-items-center flex-wrap gap-2 article-list-tools">
                    <span class="text-secondary small">共 <?= (int)$totalArticles ?> 篇<?= $searchKeyword !== '' ? '，关键词：' . e($searchKeyword) : '' ?></span>
                    <form class="per-page-form" method="get" action="articles.php">
                        <?php if ($statusFilter !== -1): ?>
                            <input type="hidden" name="status" value="<?= (int)$statusFilter ?>">
                        <?php endif; ?>
                        <?php if ($searchKeyword !== ''): ?>
                            <input type="hidden" name="q" value="<?= e($searchKeyword) ?>">
                        <?php endif; ?>
                        <label class="small text-secondary" for="perPageSelect">每页</label>
                        <select id="perPageSelect" class="form-select form-select-sm" name="per_page">
                            <?php foreach ($allowedPerPages as $option): ?>
                                <option value="<?= (int)$option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= (int)$option ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline-secondary btn-sm btn-icon" type="submit" title="应用显示条数" aria-label="应用显示条数"><i class="bi bi-check2" aria-hidden="true"></i></button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if ($articles === []): ?>
        <div class="card"><div class="empty-state"><i class="bi bi-inbox me-1" aria-hidden="true"></i>暂无文章。</div></div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($articles as $article): ?>
                <?php $status = (int)$article['status']; $badge = $statusMap[$status] ?? ['未知', 'secondary']; ?>
                <div class="col-12">
                    <div class="article-item">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <?php if (!empty($article['cover_image_id'])): ?>
                                <img class="article-cover-thumb" src="media.php?id=<?= (int)$article['cover_image_id'] ?>" alt="封面">
                            <?php endif; ?>
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                    <h2 class="h5 mb-0"><?= e((string)$article['title']) ?></h2>
                                    <span class="badge text-bg-<?= e($badge[1]) ?>"><i class="bi bi-circle-fill me-1" aria-hidden="true"></i><?= e($badge[0]) ?></span>
                                    <span class="badge text-bg-light border"><i class="bi bi-folder me-1" aria-hidden="true"></i><?= e((string)$article['category_name']) ?></span>
                                    <span class="badge text-bg-light border"><i class="bi bi-layers me-1" aria-hidden="true"></i><?= e($typeMap[(int)($article['article_type'] ?? 1)] ?? '轮播图') ?></span>
                                </div>
                                <div class="small text-secondary"><i class="bi bi-hash" aria-hidden="true"></i><?= (int)$article['id'] ?>　<i class="bi bi-hand-thumbs-up" aria-hidden="true"></i><?= (int)$article['like_seed'] + (int)$article['like_count'] ?> / <i class="bi bi-hand-thumbs-down" aria-hidden="true"></i><?= (int)$article['dislike_seed'] + (int)$article['dislike_count'] ?>　<i class="bi bi-clock" aria-hidden="true"></i><?= e((string)$article['updated_at']) ?></div>
                            </div>
                            <div class="d-flex gap-2 flex-wrap justify-content-end">
                                <?php if ($status === 1): ?>
                                    <a class="btn btn-sm btn-outline-secondary btn-icon" href="article.php?id=<?= (int)$article['id'] ?>" target="_blank" rel="noopener noreferrer" title="预览" aria-label="预览"><i class="bi bi-eye" aria-hidden="true"></i></a>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-outline-primary btn-icon" href="article_form.php?id=<?= (int)$article['id'] ?>" title="编辑" aria-label="编辑"><i class="bi bi-pencil-square" aria-hidden="true"></i></a>
                                <?php if ($status !== 1): ?>
                                    <form method="post" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                        <input type="hidden" name="action" value="status">
                                        <input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>">
                                        <input type="hidden" name="status" value="1">
                                        <button class="btn btn-sm btn-outline-success btn-icon" type="submit" title="上架" aria-label="上架"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($status !== 2): ?>
                                    <form method="post" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                        <input type="hidden" name="action" value="status">
                                        <input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>">
                                        <input type="hidden" name="status" value="2">
                                        <button class="btn btn-sm btn-outline-warning btn-icon" type="submit" title="下架" aria-label="下架"><i class="bi bi-archive" aria-hidden="true"></i></button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" class="m-0">
                                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger btn-icon" type="submit" title="删除" aria-label="删除"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($totalPages > 1): ?>
            <nav class="article-pagination-wrap" aria-label="文章分页">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($currentPage <= 1 ? '#' : articles_page_url($statusFilter, $searchKeyword, $perPage, $currentPage - 1)) ?>" aria-label="上一页" title="上一页"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
                    </li>
                    <?php if ($paginationStart > 1): ?>
                        <li class="page-item"><a class="page-link" href="<?= e(articles_page_url($statusFilter, $searchKeyword, $perPage, 1)) ?>">1</a></li>
                        <?php if ($paginationStart > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($pageNo = $paginationStart; $pageNo <= $paginationEnd; $pageNo++): ?>
                        <li class="page-item <?= $pageNo === $currentPage ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(articles_page_url($statusFilter, $searchKeyword, $perPage, $pageNo)) ?>"><?= (int)$pageNo ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($paginationEnd < $totalPages): ?>
                        <?php if ($paginationEnd < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                        <li class="page-item"><a class="page-link" href="<?= e(articles_page_url($statusFilter, $searchKeyword, $perPage, $totalPages)) ?>"><?= (int)$totalPages ?></a></li>
                    <?php endif; ?>
                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($currentPage >= $totalPages ? '#' : articles_page_url($statusFilter, $searchKeyword, $perPage, $currentPage + 1)) ?>" aria-label="下一页" title="下一页"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
                    </li>
                </ul>
                <span class="small text-secondary">第 <?= (int)$currentPage ?> / <?= (int)$totalPages ?> 页</span>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
