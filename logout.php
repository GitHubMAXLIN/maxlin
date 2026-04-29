<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
Auth::requireLogin();
Security::requirePostCsrf();
Auth::logout(true);
redirect('login.php');
