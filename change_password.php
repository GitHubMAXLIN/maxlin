<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
$user = Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requirePostCsrf();

    $oldPassword = (string)($_POST['old_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $newPasswordConfirm = (string)($_POST['new_password_confirm'] ?? '');

    if (!hash_equals($newPassword, $newPasswordConfirm)) {
        Audit::event((int)$user['id'], 'password_change_failed_confirm', 'medium');
        flash_set('danger', '操作失败，请稍后重试。');
        redirect('change_password.php');
    }

    $changed = Auth::changePassword((int)$user['id'], $oldPassword, $newPassword);
    redirect($changed ? 'dashboard.php' : 'change_password.php');
}

$messages = flash_get_all();
$token = Security::csrfToken();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>修改密码 - <?= e(SiteSettings::adminName()) ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
<?php render_admin_nav('account', $user, $token); ?>
<main class="container py-4 py-md-5">
    <?php foreach ($messages as $message): ?>
        <div class="alert alert-<?= e((string)$message['type']) ?>" role="alert"><?= e((string)$message['message']) ?></div>
    <?php endforeach; ?>

    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            <section class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4 p-md-5">
                    <div class="mb-4">
                        <h1 class="h4 mb-2"><i class="bi bi-key me-2" aria-hidden="true"></i>修改密码</h1>
                        <p class="text-secondary mb-0">修改成功后，系统会更新密码版本并让其他旧会话失效。</p>
                    </div>
                    <form method="post" action="change_password.php" autocomplete="off" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="old_password"><i class="bi bi-lock me-1" aria-hidden="true"></i>旧密码</label>
                            <input class="form-control" id="old_password" name="old_password" type="password" required maxlength="128" autocomplete="current-password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="new_password"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>新密码</label>
                            <input class="form-control" id="new_password" name="new_password" type="password" required minlength="12" maxlength="128" autocomplete="new-password">
                            <div class="form-text">建议至少 12 位，混合大小写、数字和符号。</div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="new_password_confirm"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>确认新密码</label>
                            <input class="form-control" id="new_password_confirm" name="new_password_confirm" type="password" required minlength="12" maxlength="128" autocomplete="new-password">
                        </div>
                        <div class="d-grid gap-2">
                            <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1" aria-hidden="true"></i>保存新密码</button>
                            <a class="btn btn-light border" href="dashboard.php"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>返回后台首页</a>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
</main>
</body>
</html>
