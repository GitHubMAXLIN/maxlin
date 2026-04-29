<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
$user = Auth::requireLogin();
$userId = (int)$user['id'];
$pdo = Database::pdo();

function category_text(string $key, int $max, bool $required = true): string
{
    $value = (string)($_POST[$key] ?? '');
    $value = preg_replace('/[\r\n\t\x00-\x1F\x7F]/u', ' ', trim($value)) ?? '';
    $value = mb_substr($value, 0, $max, 'UTF-8');
    if ($required && $value === '') {
        throw new InvalidArgumentException('必填项不能为空。');
    }
    return $value;
}

function category_slug(string $name): string
{
    $raw = (string)($_POST['slug'] ?? '');
    $slug = trim(mb_strtolower($raw, 'UTF-8'));
    if ($slug === '') {
        $slug = trim(mb_strtolower($name, 'UTF-8'));
    }
    $slug = preg_replace('/[^a-z0-9\-]+/u', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'cat-' . bin2hex(random_bytes(4));
    }
    return mb_substr($slug, 0, 100, 'UTF-8');
}

function category_int(string $key, int $min, int $max): int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if (!is_int($value)) {
        throw new InvalidArgumentException('参数不合法。');
    }
    return $value;
}

if (is_post()) {
    Security::requirePostCsrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            $name = category_text('name', 80, true);
            $slug = category_slug($name);
            $sortOrder = category_int('sort_order', 0, 1000000);
            $status = category_int('status', 0, 1);
            $stmt = $pdo->prepare(
                'INSERT INTO categories
                 (user_id, name, slug, sort_order, status, created_at, updated_at, deleted_at)
                 VALUES
                 (:user_id, :name, :slug, :sort_order, :status, NOW(), NOW(), NULL)'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':name' => $name,
                ':slug' => $slug,
                ':sort_order' => $sortOrder,
                ':status' => $status,
            ]);
            ContentAudit::event($userId, 'category_create', 'category', (int)$pdo->lastInsertId(), ['status' => $status]);
            flash_set('success', '分类已添加。');
        } elseif ($action === 'update') {
            $categoryId = category_int('category_id', 1, PHP_INT_MAX);
            ResourceGuard::requireOwnedCategory($categoryId, $userId);
            $name = category_text('name', 80, true);
            $slug = category_slug($name);
            $sortOrder = category_int('sort_order', 0, 1000000);
            $status = category_int('status', 0, 1);
            $stmt = $pdo->prepare(
                'UPDATE categories
                 SET name = :name,
                     slug = :slug,
                     sort_order = :sort_order,
                     status = :status,
                     updated_at = NOW()
                 WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
            );
            $stmt->execute([
                ':name' => $name,
                ':slug' => $slug,
                ':sort_order' => $sortOrder,
                ':status' => $status,
                ':id' => $categoryId,
                ':user_id' => $userId,
            ]);
            ContentAudit::event($userId, 'category_update', 'category', $categoryId, ['status' => $status]);
            flash_set('success', '分类已更新。');
        } elseif ($action === 'delete') {
            $categoryId = category_int('category_id', 1, PHP_INT_MAX);
            ResourceGuard::requireOwnedCategory($categoryId, $userId);
            $stmt = $pdo->prepare(
                'UPDATE categories
                 SET deleted_at = NOW(), updated_at = NOW()
                 WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
            );
            $stmt->execute([':id' => $categoryId, ':user_id' => $userId]);
            ContentAudit::event($userId, 'category_delete', 'category', $categoryId);
            flash_set('success', '分类已删除。');
        } else {
            throw new InvalidArgumentException('未知操作。');
        }
    } catch (PDOException $e) {
        error_log('category_db_error: ' . $e->getMessage());
        flash_set('danger', '操作失败，请确认分类别名没有重复。');
    } catch (Throwable $e) {
        error_log('category_error: ' . $e->getMessage());
        flash_set('danger', $e instanceof InvalidArgumentException ? $e->getMessage() : '操作失败，请稍后重试。');
    }
    redirect('categories.php');
}

$stmt = $pdo->prepare(
    'SELECT id, name, slug, sort_order, status, created_at, updated_at
     FROM categories
     WHERE user_id = :user_id AND deleted_at IS NULL
     ORDER BY sort_order ASC, id DESC'
);
$stmt->execute([':user_id' => $userId]);
$categories = $stmt->fetchAll();
$flashes = flash_get_all();
$token = Security::csrfToken();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>分类管理 - <?= e(SiteSettings::adminName()) ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
<?php render_admin_nav('categories', $user, $token); ?>
<main class="container py-4">
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e((string)$flash['type']) ?>" role="alert"><?= e((string)$flash['message']) ?></div>
    <?php endforeach; ?>

    <div class="page-hero">
        <h1 class="h3 mb-1"><i class="bi bi-folder2-open me-2" aria-hidden="true"></i>分类管理</h1>
        <p class="text-secondary mb-0">分类用于组织文章。所有新增、编辑、删除操作都会做 CSRF 校验和 user_id 身份锁。</p>
    </div>

    <div class="row g-4 align-items-start">
        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-body">
                    <h2 class="h5 mb-3"><i class="bi bi-folder-plus me-1" aria-hidden="true"></i>添加分类</h2>
                    <form method="post" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label class="form-label" for="name"><i class="bi bi-type me-1" aria-hidden="true"></i>分类名称</label>
                            <input class="form-control" id="name" name="name" maxlength="80" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="slug"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>URL 别名</label>
                            <input class="form-control" id="slug" name="slug" maxlength="100" placeholder="可留空自动生成">
                            <div class="form-text">只允许小写字母、数字和短横线。</div>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label" for="sort_order"><i class="bi bi-sort-numeric-down me-1" aria-hidden="true"></i>排序</label>
                                <input class="form-control" id="sort_order" name="sort_order" value="0" inputmode="numeric">
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="status"><i class="bi bi-toggle-on me-1" aria-hidden="true"></i>状态</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="1">启用</option>
                                    <option value="0">禁用</option>
                                </select>
                            </div>
                        </div>
                        <button class="btn btn-primary w-100 mt-4" type="submit"><i class="bi bi-save me-1" aria-hidden="true"></i>保存分类</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-body">
                    <div class="section-title">
                        <h2 class="h5"><i class="bi bi-list-ul me-1" aria-hidden="true"></i>分类列表</h2>
                        <span class="text-secondary small">共 <?= count($categories) ?> 个</span>
                    </div>
                    <?php if ($categories === []): ?>
                        <div class="empty-state"><i class="bi bi-inbox me-1" aria-hidden="true"></i>暂无分类，请先添加。</div>
                    <?php else: ?>
                        <?php foreach ($categories as $category): ?>
                            <div class="category-item">
                                <form method="post" autocomplete="off">
                                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="category_id" value="<?= (int)$category['id'] ?>">
                                    <div class="row g-3 align-items-end">
                                        <div class="col-12 col-xl-5">
                                            <label class="form-label small" for="name_<?= (int)$category['id'] ?>"><i class="bi bi-type me-1" aria-hidden="true"></i>分类名称</label>
                                            <input class="form-control form-control-sm" id="name_<?= (int)$category['id'] ?>" name="name" value="<?= e((string)$category['name']) ?>" maxlength="80" required>
                                        </div>
                                        <div class="col-12 col-xl-3">
                                            <label class="form-label small" for="slug_<?= (int)$category['id'] ?>"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>别名</label>
                                            <input class="form-control form-control-sm" id="slug_<?= (int)$category['id'] ?>" name="slug" value="<?= e((string)$category['slug']) ?>" maxlength="100" required>
                                        </div>
                                        <div class="col-6 col-xl-2">
                                            <label class="form-label small" for="sort_<?= (int)$category['id'] ?>"><i class="bi bi-sort-numeric-down me-1" aria-hidden="true"></i>排序</label>
                                            <input class="form-control form-control-sm" id="sort_<?= (int)$category['id'] ?>" name="sort_order" value="<?= (int)$category['sort_order'] ?>" inputmode="numeric">
                                        </div>
                                        <div class="col-6 col-xl-2">
                                            <label class="form-label small" for="status_<?= (int)$category['id'] ?>"><i class="bi bi-toggle-on me-1" aria-hidden="true"></i>状态</label>
                                            <select class="form-select form-select-sm" id="status_<?= (int)$category['id'] ?>" name="status">
                                                <option value="1" <?= (int)$category['status'] === 1 ? 'selected' : '' ?>>启用</option>
                                                <option value="0" <?= (int)$category['status'] === 0 ? 'selected' : '' ?>>禁用</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                                        <div class="small text-secondary">
                                            <?= (int)$category['status'] === 1 ? '<span class="badge text-bg-success"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>启用</span>' : '<span class="badge text-bg-secondary"><i class="bi bi-pause-circle me-1" aria-hidden="true"></i>禁用</span>' ?>
                                            <span class="ms-1">更新时间：<?= e((string)$category['updated_at']) ?></span>
                                        </div>
                                        <button class="btn btn-sm btn-outline-primary btn-icon" type="submit" title="保存修改" aria-label="保存修改"><i class="bi bi-check2-circle" aria-hidden="true"></i></button>
                                    </div>
                                </form>
                                <form method="post" class="m-0 mt-2">
                                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="category_id" value="<?= (int)$category['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger btn-icon" type="submit" title="删除分类" aria-label="删除分类"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                </form>
                            </div>
                        <?php endforeach; ?>

                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>
</body>
</html>
