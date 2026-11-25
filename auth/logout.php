<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

if (!is_post()) {
    abort_request(
        405,
        'Sign out requires confirmation',
        'For security, signing out must be requested from the sign-out button inside the application.'
    );
}

csrf_abort_if_invalid();

$signedOutType = Auth::logout(true);

if ($signedOutType === 'admin') {
    flash('success', 'You have been signed out of the administrator portal.');
    redirect('auth/admin-login.php', 303);
}

if ($signedOutType === 'voter') {
    flash('success', 'You have been signed out. Your session is closed.');
    redirect('auth/login.php', 303);
}

redirect('/', 303);
