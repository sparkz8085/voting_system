<?php
if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Forbidden.');
}
$voter = $voter ?? Auth::requireVoter();
$pageTitle = $pageTitle ?? 'Dashboard';
$pageEyebrow = $pageEyebrow ?? 'Voter portal';
$activeNav = $activeNav ?? '';
$bodyClass = $bodyClass ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Voter workspace for <?= e(APP_NAME) ?> campus elections.">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('img/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="app-body voter-body <?= e($bodyClass) ?>">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <?= render_flash_toasts() ?>

    <div class="app-shell">
        <?php require __DIR__ . '/voter_sidebar.php'; ?>

        <div class="app-main">
            <header class="app-topbar app-topbar--voter">
                <div class="topbar-start">
                    <button type="button" class="sidebar-toggle" data-sidebar-toggle aria-controls="appSidebar" aria-expanded="false">
                        <i class="bi bi-list" aria-hidden="true"></i>
                        <span class="visually-hidden">Open navigation</span>
                    </button>
                    <div class="topbar-heading">
                        <span class="topbar-eyebrow"><?= e($pageEyebrow) ?></span>
                        <h1><?= e($pageTitle) ?></h1>
                    </div>
                </div>

                <div class="topbar-actions">
                    <span class="voter-live-note d-none d-md-inline-flex">
                        <span class="status-dot status-dot--green"></span> Voter account active
                    </span>
                    <div class="dropdown">
                        <button class="topbar-profile" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?= avatar_markup((string) $voter['full_name'], null, 'avatar--xs') ?>
                            <span class="topbar-profile__meta d-none d-sm-block">
                                <strong><?= e($voter['full_name']) ?></strong>
                                <small><?= e($voter['department'] ?? 'Voter') ?></small>
                            </span>
                            <i class="bi bi-chevron-down topbar-profile__chevron" aria-hidden="true"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end app-dropdown">
                            <li class="dropdown-header">
                                <strong><?= e($voter['full_name']) ?></strong>
                                <span><?= e($voter['email']) ?></span>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="<?= e(url('voter/profile.php')) ?>">
                                    <i class="bi bi-person-gear" aria-hidden="true"></i> My profile
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= e(url('voter/results.php')) ?>">
                                    <i class="bi bi-bar-chart-line" aria-hidden="true"></i> Published results
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="post" action="<?= e(url('auth/logout.php')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="dropdown-item dropdown-item--danger">
                                        <i class="bi bi-box-arrow-left" aria-hidden="true"></i> Sign out
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </div>
            </header>

            <main class="app-content app-content--voter" id="main-content">
