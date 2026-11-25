<?php
/**
 * Application bootstrap. Every web entry point loads this file first.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/classes/Election.php';
require_once __DIR__ . '/classes/VoteService.php';
require_once __DIR__ . '/classes/ResultService.php';
require_once __DIR__ . '/auth.php';

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

$isWebRequest = PHP_SAPI !== 'cli';

if ($isWebRequest) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Content-Security-Policy: default-src \'self\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'none\'; object-src \'none\'; script-src \'self\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data: https:; font-src \'self\'; connect-src \'self\'');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header_remove('X-Powered-By');

    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

set_exception_handler(static function (Throwable $exception): void {
    error_log(sprintf(
        "Unhandled exception: %s in %s:%d\n%s",
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    ));

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Error: " . $exception->getMessage() . PHP_EOL);
        exit(1);
    }

    abort_request(
        500,
        'Something went wrong',
        'The request could not be completed. Please try again, or contact the election office if the problem continues.',
        APP_DEBUG ? $exception->getMessage() : null
    );
});

if ($isWebRequest) {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) (SESSION_IDLE_MINUTES * 60));

        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => BASE_URL === '' ? '/' : BASE_URL . '/',
            'domain'   => '',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    Auth::enforceIdleTimeout();
}
