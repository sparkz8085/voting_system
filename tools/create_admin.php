<?php
/**
 * CampusVote — CLI helper to create or reset an administrator account.
 *
 * Usage:
 *   php tools/create_admin.php --username=admin --password='Admin@12345'
 *   php tools/create_admin.php --username=admin --password='Admin@12345' --reset
 */

declare(strict_types=1);

if (PHP_SAPI === 'cli') {
    // Allow the application bootstrap to run under the CLI SAPI.
} else {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('This tool must be run from the command line.');
}

require_once dirname(__DIR__) . '/includes/app.php';

$options = getopt('', ['username:', 'password:', 'reset', 'email::']);
$username = isset($options['username']) && is_string($options['username']) ? (string) $options['username'] : '';
$password = isset($options['password']) && is_string($options['password']) ? (string) $options['password'] : '';
$reset = isset($options['reset']);

if ($username === '' || $password === '') {
    fwrite(STDERR, "Usage: php tools/create_admin.php --username=<username> --password=<password> [--reset]\n");
    fwrite(STDERR, "Example: php tools/create_admin.php --username=admin --password='Admin@12345'\n");
    exit(2);
}

if (strlen($password) < PASSWORD_MIN_LENGTH) {
    fwrite(STDERR, 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters long.' . PHP_EOL);
    exit(2);
}

/** @var PDO $pdo */
$pdo = Database::connection();

$existing = $pdo->prepare('SELECT id, full_name, username, email FROM admins WHERE username = ? LIMIT 1');
$existing->execute([$username]);
$admin = $existing->fetch();

if ($admin !== false) {
    if (!$reset) {
        fwrite(STDOUT, 'An administrator named "' . $admin['username'] . '" already exists.' . PHP_EOL);
        fwrite(STDOUT, 'Re-run with --reset to replace the password for this account.' . PHP_EOL);
        exit(1);
    }
    $update = $pdo->prepare('UPDATE admins SET password_hash = ?, updated_at = NOW() WHERE id = ?');
    $update->execute([password_hash($password, PASSWORD_DEFAULT), (int) $admin['id']]);
    fwrite(STDOUT, 'Password reset for administrator "' . $username . '".' . PHP_EOL);
    exit(0);
}

$insert = $pdo->prepare(
    'INSERT INTO admins (full_name, username, email, password_hash, is_active, created_at, updated_at)
     VALUES (?, ?, ?, ?, 1, NOW(), NOW())'
);
$insert->execute([
    'Election Office',
    $username,
    $username . '@campus.test',
    password_hash($password, PASSWORD_DEFAULT),
]);

fwrite(STDOUT, 'Administrator "' . $username . '" created successfully.' . PHP_EOL);
exit(0);
