<?php
/**
 * Shared head for authentication screens.
 * Expects: $pageTitle, $authTheme ('voter'|'admin').
 */

if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Forbidden.');
}
$authTheme = $authTheme ?? 'voter';
$pageTitle = $pageTitle ?? 'Sign in';
$metaDescription = 'Secure sign in for the campus online voting system.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e($metaDescription) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('img/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="auth-page auth-page--<?= e($authTheme) ?>">
    <a class="skip-link" href="#auth-main">Skip to content</a>
    <?= render_flash_toasts() ?>
