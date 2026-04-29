<?php

declare(strict_types=1);

function render_admin_nav(string $active, array $user, string $csrfToken): void
{
    $items = [
        'dashboard' => ['控制台', 'dashboard.php', 'bi-speedometer2'],
        'articles' => ['文章列表', 'articles.php', 'bi-card-list'],
        'publish' => ['发布文章', 'article_form.php', 'bi-pencil-square'],
        'categories' => ['分类', 'categories.php', 'bi-folder2-open'],
        'settings' => ['设置', 'settings.php', 'bi-gear'],
    ];
    $brand = SiteSettings::adminName();
    $username = (string)($user['username'] ?? '');
    ?>
<nav class="navbar admin-navbar border-bottom sticky-top">
    <div class="container-fluid px-3 px-md-4 admin-nav-inner">
        <a class="navbar-brand admin-brand" href="<?= e(url('dashboard.php')) ?>" title="<?= e($brand) ?>">
            <i class="bi bi-shield-lock-fill me-1" aria-hidden="true"></i><span><?= e($brand) ?></span>
        </a>
        <input class="admin-nav-toggle visually-hidden" type="checkbox" id="adminNavToggle" aria-hidden="true">
        <label class="btn btn-outline-secondary btn-sm admin-menu-button" for="adminNavToggle" title="展开菜单" aria-label="展开菜单">
            <i class="bi bi-list" aria-hidden="true"></i>
        </label>
        <div class="admin-nav-panel">
            <div class="admin-nav" aria-label="后台导航">
                <?php foreach ($items as $key => $item): ?>
                    <a class="nav-link<?= $active === $key ? ' active' : '' ?>" href="<?= e(url($item[1])) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?> title="<?= e($item[0]) ?>">
                        <i class="bi <?= e($item[2]) ?> me-1" aria-hidden="true"></i><span><?= e($item[0]) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="admin-actions d-flex align-items-center gap-2 justify-content-end">
                <span class="admin-user-chip" title="当前账号"><i class="bi bi-person-circle me-1" aria-hidden="true"></i><?= e($username) ?></span>
                <a class="btn btn-outline-secondary btn-sm btn-icon" href="<?= e(url('change_password.php')) ?>" title="修改密码" aria-label="修改密码"><i class="bi bi-key" aria-hidden="true"></i></a>
                <form method="post" action="<?= e(url('logout.php')) ?>" class="m-0 admin-logout-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <button class="btn btn-outline-danger btn-sm btn-icon" type="submit" title="安全退出" aria-label="安全退出"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button>
                </form>
            </div>
        </div>
    </div>
</nav>
    <?php
}
