<?php
/**
 * Standalone error view. Invoked via abort_request().
 */

if (!defined('APP_NAME') || !isset($errorStatus)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden.');
}
$errorStatus = (int) $errorStatus;
$errorTitle = (string) ($errorTitle ?? 'Request failed');
$errorMessage = (string) ($errorMessage ?? 'Please try again.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($errorStatus) ?> · <?= e($errorTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('img/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="error-page">
    <main class="error-shell">
        <a class="brand brand--error" href="<?= e(url()) ?>">
            <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="38" height="38">
            <span><?= e(APP_NAME) ?></span>
        </a>
        <div class="error-art" aria-hidden="true">
            <span class="error-code"><?= e($errorStatus) ?></span>
            <div class="error-orbit"></div>
        </div>
        <span class="eyebrow eyebrow--light">Request interrupted</span>
        <h1><?= e($errorTitle) ?></h1>
        <p class="error-message"><?= e($errorMessage) ?></p>
        <?php if (APP_DEBUG && !empty($errorDetail)): ?>
            <div class="error-debug" role="note">
                <strong>Debug detail</strong>
                <code><?= e($errorDetail) ?></code>
            </div>
        <?php endif; ?>
        <div class="error-actions">
            <a href="<?= e(url()) ?>" class="btn btn-light btn-lg">
                <i class="bi bi-house-door me-2" aria-hidden="true"></i>Back to home
            </a>
            <a href="<?= e(url('auth/login.php')) ?>" class="btn btn-outline-light btn-lg">
                Voter sign in
            </a>
        </div>
        <p class="error-footnote"><?= e(APP_TAGLINE) ?> · If this keeps happening, contact the election office.</p>
    </main>
</body>
</html>
