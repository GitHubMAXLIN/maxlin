<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
$user = Auth::requireLogin();
$userId = (int)$user['id'];
$pdo = Database::pdo();
SiteSettings::ensureTable($pdo);

if (is_post()) {
    Security::requirePostCsrf();
    try {
        $siteName = SiteSettings::normalizeSiteName((string)($_POST['site_name'] ?? SiteSettings::DEFAULT_SITE_NAME));
        $baiduAk = SiteSettings::normalizeBaiduAk((string)($_POST['baidu_map_ak'] ?? ''));
        SiteSettings::set(SiteSettings::SITE_NAME, $siteName, $userId);
        SiteSettings::set(SiteSettings::BAIDU_MAP_AK, $baiduAk, $userId);
        ContentAudit::event($userId, 'setting_update', 'site_setting', null, ['setting_names' => [SiteSettings::SITE_NAME, SiteSettings::BAIDU_MAP_AK], 'baidu_ak_filled' => $baiduAk !== '']);
        flash_set('success', '系统设置已保存。');
    } catch (Throwable $e) {
        error_log('setting_update_error: ' . $e->getMessage());
        flash_set('danger', $e instanceof InvalidArgumentException ? $e->getMessage() : '设置保存失败，请稍后重试。');
    }
    redirect('settings.php');
}

$flashes = flash_get_all();
$token = Security::csrfToken();
$siteName = SiteSettings::siteName();
$baiduAk = SiteSettings::get(SiteSettings::BAIDU_MAP_AK, (string)Config::get('app.baidu_map_ak', '')) ?? '';
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>系统设置 - <?= e(SiteSettings::adminName()) ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
<?php render_admin_nav('settings', $user, $token); ?>
<main class="container py-4">
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e((string)$flash['type']) ?>" role="alert"><?= e((string)$flash['message']) ?></div>
    <?php endforeach; ?>

    <div class="page-hero">
        <h1 class="display-6 fw-semibold mb-2"><i class="bi bi-gear me-2" aria-hidden="true"></i>系统设置</h1>
        <p class="text-secondary mb-0">集中管理博客后台通用配置。集中管理前台网站名称、后台名称和百度地图浏览器端 AK。</p>
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3"><i class="bi bi-sliders me-1" aria-hidden="true"></i>基础设置</h2>
                    <form method="post" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="site_name"><i class="bi bi-type me-1" aria-hidden="true"></i>网站名称</label>
                            <input class="form-control" id="site_name" name="site_name" maxlength="40" autocomplete="off" value="<?= e($siteName) ?>" placeholder="例如：安全博客">
                            <div class="form-text">这里会同步作为前台网站名称；后台顶部会自动显示为“网站名称 + 后台”。</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="baidu_map_ak"><i class="bi bi-key me-1" aria-hidden="true"></i>百度地图浏览器端 AK</label>
                            <input class="form-control" id="baidu_map_ak" name="baidu_map_ak" maxlength="120" autocomplete="off" value="<?= e($baiduAk) ?>" placeholder="例如：你的百度地图浏览器端 AK">
                            <div class="form-text">用于发布 / 编辑文章时加载小地图选点。请在百度地图开放平台创建浏览器端 AK，并配置允许的 Referer 白名单。</div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1" aria-hidden="true"></i>保存设置</button>
                            <a class="btn btn-outline-secondary" href="article_form.php"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>去发布文章测试地图</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>说明</h2>
                    <ul class="text-secondary mb-0 settings-help-list">
                        <li>网站名称与 AK 保存到数据库 `site_settings`，不再要求手动修改 `config.php`。</li>
                        <li>发布文章页的地址、经纬度只做只读展示，真实值由地图选择后写入隐藏字段。</li>
                        <li>后台仍会对坐标范围和地址长度做后端校验，不能相信前端提交。</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</main>
</body>
</html>
