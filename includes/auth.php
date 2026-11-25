<?php
/**
 * Authentication, session identity, and authorization.
 *
 * One session has one active role. Signing in regenerates the session id and
 * the CSRF token; protected pages re-check the account against the database.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden.');
}

final class Auth
{
    /** A valid bcrypt hash used to equalise timing for unknown accounts. */
    private const DUMMY_HASH = '$2y$12$kz3VlV/2F0xr9sRHbytWXu6BcOmWESQ263ZCbgaPGhfLqQly1JZEG';

    private static array $activeUserCache = [];

    /**
     * Attempt a sign-in.
     *
     * @return array{success: bool, message: string, redirect?: string}
     */
    public static function attempt(string $type, string $identifier, string $password): array
    {
        if (!in_array($type, ['admin', 'voter'], true)) {
            return ['success' => false, 'message' => 'Unable to sign in.'];
        }

        $identifier = trim($identifier);
        if ($identifier === '' || $password === '') {
            return ['success' => false, 'message' => 'Enter your credentials to continue.'];
        }

        $ip = client_ip();
        if (self::tooManyAttempts($type, $identifier, $ip)) {
            return [
                'success' => false,
                'message' => 'Too many failed attempts. Please wait ' . LOGIN_WINDOW_MINUTES . ' minutes before trying again.',
            ];
        }

        $pdo = Database::connection();
        if ($type === 'admin') {
            $stmt = $pdo->prepare(
                'SELECT id, full_name, username, email, password_hash, is_active
                 FROM admins WHERE username = ? OR email = ? LIMIT 1'
            );
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, full_name, username, email, password_hash, is_active
                 FROM voters WHERE username = ? OR email = ? LIMIT 1'
            );
        }
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        $hash = $user ? (string) $user['password_hash'] : self::DUMMY_HASH;
        $passwordMatches = password_verify($password, $hash);
        $accountActive = $user && (int) $user['is_active'] === 1;

        if (!$user || !$passwordMatches || !$accountActive) {
            self::recordAttempt($type, $identifier, $ip, false);
            return ['success' => false, 'message' => 'Invalid credentials. Check your details and try again.'];
        }

        // Successful login clears the failure counter for this identifier + IP.
        $delete = $pdo->prepare(
            'DELETE FROM login_attempts
             WHERE user_type = ? AND identifier = ? AND ip_address = ? AND success = 0'
        );
        $delete->execute([$type, $identifier, $ip]);

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $update = $pdo->prepare('UPDATE ' . ($type === 'admin' ? 'admins' : 'voters') . ' SET password_hash = ? WHERE id = ?');
            $update->execute([$newHash, $user['id']]);
        }

        $pdo->prepare(
            'UPDATE ' . ($type === 'admin' ? 'admins' : 'voters') . ' SET last_login_at = NOW() WHERE id = ?'
        )->execute([$user['id']]);

        self::recordAttempt($type, $identifier, $ip, true);
        self::establishSession($user, $type);

        if ($type === 'admin') {
            audit_log((int) $user['id'], 'admin.login', 'Administrator signed in.');
        }

        return [
            'success'  => true,
            'message'  => 'Signed in successfully.',
            'redirect' => $type === 'admin' ? 'admin/dashboard.php' : 'voter/dashboard.php',
        ];
    }

    /** Count recent failures for this identifier and IP. */
    private static function tooManyAttempts(string $type, string $identifier, string $ip): bool
    {
        $window = (int) LOGIN_WINDOW_MINUTES;
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE user_type = ? AND identifier = ? AND ip_address = ?
               AND success = 0
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL ' . $window . ' MINUTE)'
        );
        $stmt->execute([$type, $identifier, $ip]);
        return (int) $stmt->fetchColumn() >= MAX_LOGIN_ATTEMPTS;
    }

    /** Store an authentication attempt, pruning old rows opportunistically. */
    private static function recordAttempt(string $type, string $identifier, string $ip, bool $success): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (user_type, identifier, ip_address, success) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$type, mb_substr($identifier, 0, 191), $ip, $success ? 1 : 0]);

        if (random_int(1, 20) === 1) {
            $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 DAY)');
        }
    }

    /** Establish a fresh authenticated session for a user. */
    private static function establishSession(array $user, string $type): void
    {
        session_regenerate_id(true);
        $_SESSION['auth'] = [
            'id'         => (int) $user['id'],
            'type'       => $type,
            'name'       => (string) $user['full_name'],
            'username'   => (string) $user['username'],
            'email'      => (string) $user['email'],
            'login_at'   => time(),
        ];
        $_SESSION['_last_activity'] = time();
        csrf_rotate();
        self::$activeUserCache = [];
    }

    /** Current session identity, or null. */
    public static function user(): ?array
    {
        $auth = $_SESSION['auth'] ?? null;
        if (!is_array($auth) || empty($auth['id']) || empty($auth['type'])) {
            return null;
        }
        if (!in_array($auth['type'], ['admin', 'voter'], true)) {
            return null;
        }
        return $auth;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function type(): ?string
    {
        return self::user()['type'] ?? null;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user ? (int) $user['id'] : null;
    }

    /**
     * Re-check the current account against the database on every protected
     * request and return the fresh user row.
     *
     * @param 'admin'|'voter' $type
     */
    private static function validateActiveAccount(array $sessionUser, string $type): ?array
    {
        $cacheKey = $type . ':' . (int) $sessionUser['id'];
        if (array_key_exists($cacheKey, self::$activeUserCache)) {
            return self::$activeUserCache[$cacheKey];
        }

        $table = $type === 'admin' ? 'admins' : 'voters';
        $columns = $type === 'admin'
            ? 'id, full_name, username, email, is_active, last_login_at, created_at'
            : 'id, full_name, username, email, student_id, department, is_active, last_login_at, created_at';
        $stmt = Database::connection()->prepare(
            'SELECT ' . $columns . ' FROM ' . $table . ' WHERE id = ? LIMIT 1'
        );
        $stmt->execute([(int) $sessionUser['id']]);
        $row = $stmt->fetch();

        if (!$row || (int) $row['is_active'] !== 1) {
            self::$activeUserCache[$cacheKey] = null;
            return null;
        }

        // Keep the displayed profile name in sync when an administrator edits it.
        $_SESSION['auth']['name'] = (string) $row['full_name'];
        $_SESSION['auth']['username'] = (string) $row['username'];
        $_SESSION['auth']['email'] = (string) $row['email'];
        self::$activeUserCache[$cacheKey] = $row;
        return $row;
    }

    /** Require an active administrator or terminate with the correct redirect. */
    public static function requireAdmin(): array
    {
        return self::requireRole('admin');
    }

    /** Require an active voter or terminate with the correct redirect. */
    public static function requireVoter(): array
    {
        return self::requireRole('voter');
    }

    /** @param 'admin'|'voter' $role */
    private static function requireRole(string $role): array
    {
        $sessionUser = self::user();

        if (!$sessionUser) {
            flash('warning', 'Please sign in to continue.');
            redirect($role === 'admin' ? 'auth/admin-login.php' : 'auth/login.php');
        }

        if (($sessionUser['type'] ?? '') !== $role) {
            flash('danger', 'You do not have permission to open that area.');
            redirect(($sessionUser['type'] ?? '') === 'admin' ? 'admin/dashboard.php' : 'voter/dashboard.php');
        }

        $freshUser = self::validateActiveAccount($sessionUser, $role);
        if (!$freshUser) {
            self::logout(false);
            flash('danger', 'Your account is no longer active. Contact the election office.');
            redirect($role === 'admin' ? 'auth/admin-login.php' : 'auth/login.php');
        }

        return $freshUser;
    }

    /** Keep signed-in users away from login/register screens. */
    public static function redirectIfAuthenticated(): void
    {
        $user = self::user();
        if (!$user) {
            return;
        }
        redirect($user['type'] === 'admin' ? 'admin/dashboard.php' : 'voter/dashboard.php');
    }

    /**
     * End the current session.
     *
     * @return string|null the role that was signed out
     */
    public static function logout(bool $audit = true): ?string
    {
        $user = self::user();
        $type = $user['type'] ?? null;

        if ($audit && $type === 'admin' && $user) {
            audit_log((int) $user['id'], 'admin.logout', 'Administrator signed out.');
        }

        unset($_SESSION['auth'], $_SESSION['_last_activity'], $_SESSION['_login_at']);
        csrf_rotate();
        session_regenerate_id(true);
        self::$activeUserCache = [];

        return $type;
    }

    /** Sign a user out after a period of inactivity. */
    public static function enforceIdleTimeout(): void
    {
        if (!self::check()) {
            return;
        }

        $lastActivity = (int) ($_SESSION['_last_activity'] ?? 0);
        if ($lastActivity > 0 && (time() - $lastActivity) > SESSION_IDLE_MINUTES * 60) {
            $type = self::logout(false);
            flash('warning', 'Your session expired after inactivity. Please sign in again.');
            redirect($type === 'admin' ? 'auth/admin-login.php' : 'auth/login.php');
        }

        $_SESSION['_last_activity'] = time();
    }
}
