<?php
if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Forbidden.');
}
$pageTitle = $pageTitle ?? 'Online voting';
$pageDescription = $pageDescription ?? 'A secure, transparent online voting system for campus elections.';
$currentUser = Auth::user();
$dashboardLink = $currentUser
    ? ($currentUser['type'] === 'admin' ? 'admin/dashboard.php' : 'voter/dashboard.php')
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e($pageDescription) ?>">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('img/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="public-body">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <?= render_flash_toasts() ?>

    <header class="site-header" id="siteHeader">
        <div class="container site-header__inner">
            <a class="brand brand--public" href="<?= e(url()) ?>">
                <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="40" height="40">
                <span><?= e(APP_NAME) ?><small>Campus elections</small></span>
            </a>

            <nav class="site-nav d-none d-lg-flex" aria-label="Primary navigation">
                <a href="#elections">Elections</a>
                <a href="#how-it-works">How it works</a>
                <a href="#integrity">Integrity</a>
                <a href="#results">Results</a>
            </nav>

            <div class="site-actions d-none d-lg-flex">
                <?php if ($currentUser): ?>
                    <a class="btn btn-brand" href="<?= e(url($dashboardLink)) ?>">
                        <i class="bi bi-grid-1x2 me-2" aria-hidden="true"></i>Dashboard
                    </a>
                <?php else: ?>
                    <a class="btn btn-link-soft" href="<?= e(url('auth/login.php')) ?>">Sign in</a>
                    <a class="btn btn-brand" href="<?= e(url('auth/register.php')) ?>">
                        Register to vote <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>
            </div>

            <button class="site-menu-toggle d-lg-none" type="button" data-bs-toggle="collapse"
                    data-bs-target="#siteMenu" aria-controls="siteMenu" aria-expanded="false" aria-label="Open menu">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
        </div>

        <div class="collapse site-mobile-menu d-lg-none" id="siteMenu">
            <div class="container">
                <nav aria-label="Mobile navigation">
                    <a href="#elections">Elections</a>
                    <a href="#how-it-works">How it works</a>
                    <a href="#integrity">Integrity</a>
                    <a href="#results">Results</a>
                </nav>
                <div class="site-mobile-actions">
                    <?php if ($currentUser): ?>
                        <a class="btn btn-brand w-100" href="<?= e(url($dashboardLink)) ?>">Open dashboard</a>
                    <?php else: ?>
                        <a class="btn btn-light w-50" href="<?= e(url('auth/login.php')) ?>">Sign in</a>
                        <a class="btn btn-brand w-50" href="<?= e(url('auth/register.php')) ?>">Register</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>
