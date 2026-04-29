<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (Auth::currentUser() !== null) {
    redirect('dashboard.php');
}

$loginFailed = false;
if (is_post()) {
    Security::requirePostCsrf();
    $username = (string)($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if (Auth::attemptLogin($username, $password, $_POST)) {
        redirect('dashboard.php');
    }
    $loginFailed = true;
}

$captchaRequired = (bool)($_SESSION['login_captcha_required'] ?? false);
$siteName = SiteSettings::adminName();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>后台登录 - <?= e($siteName) ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="login-shell">
<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-7 col-lg-5 col-xl-4">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <span class="badge text-bg-primary mb-2"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Admin</span>
                        <h1 class="h4 mb-1"><i class="bi bi-shield-check me-1" aria-hidden="true"></i><?= e($siteName) ?></h1>
                        <p class="text-secondary mb-0">请输入管理员账号和密码</p>
                    </div>

                    <?php if ($loginFailed): ?>
                        <div class="alert alert-danger" role="alert">账号或密码错误，或当前请求暂不可用。</div>
                    <?php endif; ?>

                    <?php if ($captchaRequired): ?>
                        <div class="alert alert-warning" role="alert">
                            检测到连续失败登录，正式上线时请在 CaptchaVerifier 中接入人机验证。
                        </div>
                    <?php endif; ?>

                    <form method="post" autocomplete="off" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="username"><i class="bi bi-person me-1" aria-hidden="true"></i>账号</label>
                            <input class="form-control form-control-lg" id="username" name="username" autocomplete="username" required maxlength="80">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password"><i class="bi bi-lock me-1" aria-hidden="true"></i>密码</label>
                            <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" required maxlength="128">
                        </div>
                        <?php if ($captchaRequired): ?>
                            <div class="mb-3 border rounded-3 p-3 bg-light">
                                <label class="form-label" for="captcha_response"><i class="bi bi-robot me-1" aria-hidden="true"></i>人机验证预留</label>
                                <input class="form-control" id="captcha_response" name="captcha_response" placeholder="正式接入验证码后使用">
                            </div>
                        <?php endif; ?>
                        <button class="btn btn-primary btn-lg w-100" type="submit"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>登录</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>
</body>
</html>
