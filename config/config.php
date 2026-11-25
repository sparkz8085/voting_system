<?php
/**
 * CampusVote — application configuration
 *
 * Keep secrets in config/config.local.php (ignored by version control and
 * blocked from direct web access). The defaults below are suitable for a
 * standard LAMPP installation where MySQL root has no password.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$config = [
    'app' => [
        'name'             => 'CampusVote',
        'tagline'          => 'Secure campus elections',
        'timezone'         => 'Asia/Kolkata',
        'debug'            => false,
        // Leave as null to auto-detect (e.g. "/online-voting-system").
        'base_url'         => null,
        'support_email'    => 'election-office@campus.test',
    ],
    'database' => [
        'host'      => '127.0.0.1',
        'port'      => 3306,
        'name'      => 'online_voting_system',
        'user'      => 'root',
        'password'  => '',
        'charset'   => 'utf8mb4',
    ],
    'security' => [
        'max_login_attempts'    => 5,
        'login_window_minutes'  => 15,
        'session_idle_minutes'  => 120,
        'password_min_length'   => 8,
    ],
];

$localConfigFile = __DIR__ . '/config.local.php';
if (is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;
    if (is_array($localConfig)) {
        $config = array_replace_recursive($config, $localConfig);
    }
}

// Environment overrides are useful for automated testing; never required.
if (getenv('APP_DEBUG') !== false) {
    $config['app']['debug'] = filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN);
}
if (getenv('APP_BASE_URL') !== false) {
    $config['app']['base_url'] = (string) getenv('APP_BASE_URL');
}
if (getenv('DB_HOST') !== false) {
    $config['database']['host'] = (string) getenv('DB_HOST');
}
if (getenv('DB_NAME') !== false) {
    $config['database']['name'] = (string) getenv('DB_NAME');
}
if (getenv('DB_USER') !== false) {
    $config['database']['user'] = (string) getenv('DB_USER');
}
if (getenv('DB_PASSWORD') !== false) {
    $config['database']['password'] = (string) getenv('DB_PASSWORD');
}

date_default_timezone_set((string) $config['app']['timezone']);

/**
 * Work out the URL path the application is installed under. This keeps links
 * working at http://localhost/online-voting-system/ as well as at a domain root.
 */
$detectedBase = '';
if (PHP_SAPI !== 'cli') {
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    $appRoot = realpath(APP_ROOT);
    if ($documentRoot !== false && $appRoot !== false) {
        $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
        $appRoot = str_replace('\\', '/', $appRoot);
        if ($appRoot === $documentRoot) {
            $detectedBase = '';
        } elseif (str_starts_with($appRoot, $documentRoot . '/')) {
            $detectedBase = substr($appRoot, strlen($documentRoot));
        }
    }
}
$configuredBase = $config['app']['base_url'];
$baseUrl = $configuredBase !== null ? (string) $configuredBase : $detectedBase;
$baseUrl = '/' . trim(str_replace('\\', '/', $baseUrl), '/');
$baseUrl = $baseUrl === '/' ? '' : $baseUrl;

define('APP_NAME', (string) $config['app']['name']);
define('APP_TAGLINE', (string) $config['app']['tagline']);
define('APP_TIMEZONE', (string) $config['app']['timezone']);
define('APP_DEBUG', (bool) $config['app']['debug']);
define('APP_SUPPORT_EMAIL', (string) $config['app']['support_email']);
define('BASE_URL', $baseUrl);

define('DB_HOST', (string) $config['database']['host']);
define('DB_PORT', (int) $config['database']['port']);
define('DB_NAME', (string) $config['database']['name']);
define('DB_USER', (string) $config['database']['user']);
define('DB_PASSWORD', (string) $config['database']['password']);
define('DB_CHARSET', (string) $config['database']['charset']);

define('MAX_LOGIN_ATTEMPTS', (int) $config['security']['max_login_attempts']);
define('LOGIN_WINDOW_MINUTES', (int) $config['security']['login_window_minutes']);
define('SESSION_IDLE_MINUTES', (int) $config['security']['session_idle_minutes']);
define('PASSWORD_MIN_LENGTH', (int) $config['security']['password_min_length']);

define('SESSION_NAME', 'campusvote_session');
