<?php
/**
 * Shared helper functions.
 *
 * Loaded through includes/app.php only.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden.');
}

/** HTML-escape any value for safe output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an internal application URL, respecting the installation subfolder. */
function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return BASE_URL . '/' . $path;
}

/** Build an asset URL. */
function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

/** Redirect internally and stop. */
function redirect(string $path, int $status = 302): never
{
    $path = str_replace(["\r", "\n"], '', $path);
    header('Location: ' . url($path), true, $status);
    exit;
}

/** Redirect with a one-time flash message. */
function flash_redirect(string $path, string $type, string $message, int $status = 303): never
{
    flash($type, $message);
    redirect($path, $status);
}

/**
 * Store a one-time flash message. Allowed types mirror Bootstrap's contextual
 * colours: success, danger, warning, info.
 */
function flash(string $type, string $message): void
{
    $allowed = ['success', 'danger', 'warning', 'info'];
    if (!in_array($type, $allowed, true)) {
        $type = 'info';
    }
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** Fetch and clear all flash messages. */
function get_flashes(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($flashes) ? $flashes : [];
}

/** Render Bootstrap toast markup for queued flash messages. */
function render_flash_toasts(): string
{
    $flashes = get_flashes();
    if (!$flashes) {
        return '';
    }

    $icons = [
        'success' => 'bi-check-circle-fill',
        'danger'  => 'bi-exclamation-octagon-fill',
        'warning' => 'bi-exclamation-triangle-fill',
        'info'    => 'bi-info-circle-fill',
    ];

    $html = '<div class="toast-container position-fixed top-0 end-0 p-3" id="flashToasts" aria-live="polite" aria-atomic="true">';
    foreach ($flashes as $index => $flash) {
        $type = in_array(($flash['type'] ?? ''), ['success', 'danger', 'warning', 'info'], true)
            ? $flash['type']
            : 'info';
        $icon = $icons[$type];
        $html .= '<div class="toast app-toast app-toast--' . e($type) . ' show" role="alert" aria-live="assertive" aria-atomic="true" data-autohide="true" data-delay="5500" data-toast-index="' . $index . '">';
        $html .= '<div class="toast-body"><span class="app-toast__icon"><i class="bi ' . e($icon) . '"></i></span><span class="app-toast__message">' . e($flash['message'] ?? '') . '</span>';
        $html .= '<button type="button" class="btn-close ms-auto" data-bs-dismiss="toast" aria-label="Close notification"></button></div></div>';
    }
    $html .= '</div>';

    return $html;
}

/** True when the current request is a POST. */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Read a trimmed string from POST. */
function post_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

/** Read a raw string from POST (for passwords, where spaces are significant). */
function post_raw(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

/** Read a positive integer from POST/GET, or zero. */
function positive_int(mixed $value): int
{
    if (is_int($value)) {
        return max(0, $value);
    }
    if (is_string($value) && ctype_digit($value)) {
        return (int) $value;
    }
    return 0;
}

/** Current request IP; deliberately does not trust X-Forwarded-For headers. */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** Whether the request is served over HTTPS. */
function is_https(): bool
{
    return !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
}

/** Format a database datetime for display. */
function format_datetime(?string $datetime, string $format = 'M j, Y · g:i A'): string
{
    if ($datetime === null || $datetime === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($datetime, new DateTimeZone(APP_TIMEZONE)))->format($format);
    } catch (Exception) {
        return '—';
    }
}

/** Format a database date (no time). */
function format_date(?string $datetime, string $format = 'M j, Y'): string
{
    return format_datetime($datetime, $format);
}

/** Format for a <input type="datetime-local"> value. */
function datetime_input_value(?string $datetime): string
{
    return format_datetime($datetime, 'Y-m-d\TH:i');
}

/** Parse an HTML datetime-local value strictly, returning null when invalid. */
function parse_datetime_local(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
        return null;
    }
    $timezone = new DateTimeZone(APP_TIMEZONE);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
    if (!$parsed || $parsed->format('Y-m-d\TH:i') !== $value) {
        return null;
    }
    $year = (int) $parsed->format('Y');
    if ($year < 2000 || $year > 2100) {
        return null;
    }
    return $parsed;
}

/** Human friendly "X ago" text. */
function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    try {
        $then = new DateTimeImmutable($datetime, new DateTimeZone(APP_TIMEZONE));
        $now = new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
        $diff = $then->diff($now);
        if ($then > $now) {
            return 'in ' . human_interval($diff);
        }
        return human_interval($diff) . ' ago';
    } catch (Exception) {
        return '';
    }
}

/** Convert a DateInterval into a friendly duration. */
function human_interval(DateInterval $diff): string
{
    if ($diff->y > 0) {
        return $diff->y . ' year' . ($diff->y === 1 ? '' : 's');
    }
    if ($diff->m > 0) {
        return $diff->m . ' month' . ($diff->m === 1 ? '' : 's');
    }
    if ($diff->d > 0) {
        return $diff->d . ' day' . ($diff->d === 1 ? '' : 's');
    }
    if ($diff->h > 0) {
        return $diff->h . ' hour' . ($diff->h === 1 ? '' : 's');
    }
    if ($diff->i > 0) {
        return $diff->i . ' minute' . ($diff->i === 1 ? '' : 's');
    }
    return 'moments';
}

/** Duration from now until a future datetime, or a fallback. */
function time_until(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    try {
        $target = new DateTimeImmutable($datetime, new DateTimeZone(APP_TIMEZONE));
        $now = new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
        if ($target <= $now) {
            return 'now';
        }
        return human_interval($target->diff($now));
    } catch (Exception) {
        return '';
    }
}

/** Initials for an avatar (max two characters). */
function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, static fn ($part) => $part !== ''));
    if (!$parts) {
        return '?';
    }
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $last = count($parts) > 1 ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) : '';
    return $first . $last;
}

/** Deterministic avatar gradient based on a name. */
function avatar_background(string $seed): string
{
    $palettes = [
        'linear-gradient(135deg, #4f46e5, #7c3aed)',
        'linear-gradient(135deg, #0f766e, #14b8a6)',
        'linear-gradient(135deg, #b45309, #f59e0b)',
        'linear-gradient(135deg, #be123c, #fb7185)',
        'linear-gradient(135deg, #1d4ed8, #38bdf8)',
        'linear-gradient(135deg, #6d28d9, #c084fc)',
        'linear-gradient(135deg, #047857, #84cc16)',
        'linear-gradient(135deg, #9a3412, #fb923c)',
    ];
    $index = abs(crc32(mb_strtolower($seed))) % count($palettes);
    return $palettes[$index];
}

/** Render an accessible initials/photo avatar. */
function avatar_markup(string $name, ?string $photoUrl = null, string $extraClass = ''): string
{
    $safePhoto = safe_photo_url($photoUrl);
    $class = trim('avatar ' . $extraClass);
    $style = e(avatar_background($name));
    $html = '<span class="' . e($class) . '" style="background:' . $style . '" aria-hidden="true">';
    $html .= '<span class="avatar__initials">' . e(initials($name)) . '</span>';
    if ($safePhoto !== null) {
        $html .= '<img src="' . e($safePhoto) . '" alt="" loading="lazy" class="avatar__img" data-avatar-image>';
    }
    $html .= '</span>';
    return $html;
}

/** Truncate plain text safely. */
function truncate_text(?string $text, int $limit = 120): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
}

/** Validate an optional photo URL and return it if safe. */
function safe_photo_url(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }
    if (strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : null;
}

/** Return a user-safe password policy error, or null. */
function password_policy_error(string $password): ?string
{
    if (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
        return 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters long.';
    }
    if (strlen($password) > 72) {
        return 'Password must be 72 characters or fewer.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    return null;
}

/** Validate username format. */
function username_policy_error(string $username): ?string
{
    if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $username)) {
        return 'Username must be 3–30 characters and may contain letters, numbers, dots, underscores, or hyphens.';
    }
    return null;
}

/** Normalize a username/email for comparisons. */
function normalize_login_identifier(string $identifier): string
{
    return mb_strtolower(trim($identifier));
}

/** Escape LIKE wildcards in user search input. */
function like_escape(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

/** Render an inline validation message. */
function field_error(array $errors, string $field): string
{
    if (!isset($errors[$field])) {
        return '';
    }
    return '<div class="invalid-feedback d-block">' . e($errors[$field]) . '</div>';
}

/** Return Bootstrap's is-invalid class if a field has an error. */
function field_invalid(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' is-invalid' : '';
}

/** Build a URL for pagination preserving current query parameters. */
function page_url(int $page): string
{
    $params = $_GET;
    if ($page <= 1) {
        unset($params['page']);
    } else {
        $params['page'] = $page;
    }
    $query = http_build_query($params);
    return '?' . $query;
}

/** Paginate a result set. Returns page metadata. */
function paginate(int $totalItems, int $perPage, int $currentPage): array
{
    $pages = max(1, (int) ceil($totalItems / $perPage));
    $currentPage = min(max(1, $currentPage), $pages);
    return [
        'total'  => $totalItems,
        'pages'  => $pages,
        'page'   => $currentPage,
        'per'    => $perPage,
        'offset' => ($currentPage - 1) * $perPage,
        'from'   => $totalItems === 0 ? 0 : (($currentPage - 1) * $perPage) + 1,
        'to'     => min($totalItems, $currentPage * $perPage),
    ];
}

/** Render pagination navigation. */
function pagination_html(array $pagination): string
{
    if (($pagination['pages'] ?? 1) <= 1) {
        return '';
    }
    $page = (int) $pagination['page'];
    $pages = (int) $pagination['pages'];
    $html = '<nav class="app-pagination" aria-label="Pagination"><ul class="pagination pagination-sm mb-0">';
    $html .= '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="' . e(page_url($page - 1)) . '" aria-label="Previous page"><i class="bi bi-chevron-left"></i></a></li>';

    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . e(page_url(1)) . '">1</a></li>';
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= '<li class="page-item ' . ($i === $page ? 'active' : '') . '"><a class="page-link" href="' . e(page_url($i)) . '">' . $i . '</a></li>';
    }
    if ($end < $pages) {
        if ($end < $pages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . e(page_url($pages)) . '">' . $pages . '</a></li>';
    }

    $html .= '<li class="page-item ' . ($page >= $pages ? 'disabled' : '') . '"><a class="page-link" href="' . e(page_url($page + 1)) . '" aria-label="Next page"><i class="bi bi-chevron-right"></i></a></li>';
    $html .= '</ul></nav>';
    return $html;
}

/** Presentation metadata for an administrator audit action. */
function audit_action_meta(string $action): array
{
    $map = [
        'admin.login'            => ['Signed in', 'bi-box-arrow-in-right', 'info'],
        'admin.logout'           => ['Signed out', 'bi-box-arrow-left', 'neutral'],
        'election.created'       => ['Election created', 'bi-calendar-plus', 'success'],
        'election.updated'       => ['Election updated', 'bi-calendar-check', 'info'],
        'election.activated'     => ['Election activated', 'bi-broadcast', 'success'],
        'election.closed'        => ['Election closed', 'bi-lock', 'warning'],
        'election.deleted'       => ['Election deleted', 'bi-trash3', 'danger'],
        'election.published'     => ['Results published', 'bi-award', 'success'],
        'election.unpublished'   => ['Results unpublished', 'bi-eye-slash', 'warning'],
        'candidate.created'      => ['Candidate added', 'bi-person-plus', 'success'],
        'candidate.updated'      => ['Candidate updated', 'bi-person-check', 'info'],
        'candidate.deleted'      => ['Candidate removed', 'bi-person-dash', 'danger'],
        'voter.updated'          => ['Voter updated', 'bi-person-gear', 'info'],
        'voter.activated'        => ['Voter activated', 'bi-person-check', 'success'],
        'voter.deactivated'      => ['Voter deactivated', 'bi-person-slash', 'warning'],
        'voter.password_reset'   => ['Voter password reset', 'bi-key', 'warning'],
        'admin.profile_updated'  => ['Profile updated', 'bi-person-gear', 'info'],
        'admin.password_changed' => ['Password changed', 'bi-shield-lock', 'success'],
    ];

    if (isset($map[$action])) {
        return ['label' => $map[$action][0], 'icon' => $map[$action][1], 'tone' => $map[$action][2]];
    }

    return [
        'label' => ucwords(str_replace(['.', '_'], ' ', $action)),
        'icon'  => 'bi-activity',
        'tone'  => 'neutral',
    ];
}

/** Log an administrator action. Never let audit failures break the request. */
function audit_log(?int $adminId, string $action, string $description): void
{
    if ($adminId === null || $adminId <= 0) {
        return;
    }
    try {
        $stmt = Database::connection()->prepare(
            'INSERT INTO audit_logs (admin_id, action, description, ip_address) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $adminId,
            mb_substr($action, 0, 80),
            mb_substr($description, 0, 500),
            client_ip(),
        ]);
    } catch (Throwable $exception) {
        error_log('Audit log write failed: ' . $exception->getMessage());
    }
}

/** Render a standalone error page and terminate. */
function abort_request(int $status, string $title, string $message, ?string $detail = null): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }
    $errorStatus = $status;
    $errorTitle = $title;
    $errorMessage = $message;
    $errorDetail = $detail;
    include APP_ROOT . '/errors/error.php';
    exit;
}
