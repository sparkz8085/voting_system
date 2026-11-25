<?php
/**
 * CSRF protection.
 *
 * Every state-changing request must be a POST containing a token tied to the
 * current server-side session.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden.');
}

/** Return the current session CSRF token, creating one when necessary. */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/** Create a fresh CSRF token (called after authentication changes). */
function csrf_rotate(): string
{
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}

/** Hidden input for HTML forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Validate a CSRF token from POST or an X-CSRF-Token header. */
function csrf_valid(): bool
{
    $submitted = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($submitted) || $submitted === '') {
        return false;
    }
    $sessionToken = $_SESSION['_csrf'] ?? '';
    if (!is_string($sessionToken) || $sessionToken === '') {
        return false;
    }
    return hash_equals($sessionToken, $submitted);
}

/** Abort the request with a 419 when CSRF validation fails. */
function csrf_abort_if_invalid(): void
{
    if (!csrf_valid()) {
        abort_request(
            419,
            'Your session expired',
            'This form could not be verified. Refresh the page and try again.'
        );
    }
}
