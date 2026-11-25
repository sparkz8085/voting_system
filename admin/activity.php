<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

$admin = Auth::requireAdmin();
$pdo = Database::connection();

$search = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$where = '';
$params = [];
if ($search !== '') {
    $like = '%' . like_escape($search) . '%';
    $where = 'WHERE a.action LIKE ? OR a.description LIKE ? OR ad.full_name LIKE ?';
    array_push($params, $like, $like, $like);
}

$countStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM audit_logs a LEFT JOIN admins ad ON ad.id = a.admin_id ' . $where
);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$page = max(1, positive_int($_GET['page'] ?? 1));
$pagination = paginate($total, 15, $page);

$stmt = $pdo->prepare(
    'SELECT a.*, ad.full_name AS admin_name
     FROM audit_logs a
     LEFT JOIN admins ad ON ad.id = a.admin_id
     ' . $where . '
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT ? OFFSET ?'
);
$bind = 1;
foreach ($params as $param) {
    $stmt->bindValue($bind++, $param, PDO::PARAM_STR);
}
$stmt->bindValue($bind++, (int) $pagination['per'], PDO::PARAM_INT);
$stmt->bindValue($bind, (int) $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$summary = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_total,
        COUNT(DISTINCT admin_id) AS admin_count,
        MAX(created_at) AS last_action
     FROM audit_logs"
)->fetch();

$pageTitle = 'Activity log';
$pageEyebrow = 'Governance & audit';
$activeNav = 'activity';
require_once dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="page-toolbar">
    <div>
        <p class="page-toolbar__lead">A chronological record of administrative actions. Entries are append-only during normal operation.</p>
        <div class="page-toolbar__meta">
            <span><i class="bi bi-journal-text" aria-hidden="true"></i><?= e(number_format((int) $summary['total'])) ?> entries</span>
            <span><i class="bi bi-calendar-check" aria-hidden="true"></i><?= e(number_format((int) $summary['today_total'])) ?> today</span>
            <span><i class="bi bi-clock-history" aria-hidden="true"></i>Last action <?= e($summary['last_action'] ? time_ago($summary['last_action']) : 'never') ?></span>
        </div>
    </div>
    <form class="app-search app-search--toolbar" method="get" action="<?= e(url('admin/activity.php')) ?>" role="search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search the audit trail…" aria-label="Search activity log" maxlength="100">
        <?php if ($search !== ''): ?>
            <a href="<?= e(url('admin/activity.php')) ?>" class="app-search__clear" aria-label="Clear search"><i class="bi bi-x-lg"></i></a>
        <?php endif; ?>
    </form>
</div>

<div class="panel panel--flush">
    <div class="panel__header panel__header--inset">
        <div>
            <h2 class="panel__title">Administrative trail</h2>
            <p class="panel__subtitle">Newest actions first · stored with actor, time, and IP address</p>
        </div>
        <span class="metric-chip"><i class="bi bi-shield-check" aria-hidden="true"></i>Audited</span>
    </div>

    <?php if ($logs): ?>
        <div class="table-responsive">
            <table class="table app-table app-table--activity align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Time</th>
                        <th scope="col">Action</th>
                        <th scope="col">Description</th>
                        <th scope="col">Administrator</th>
                        <th scope="col">IP address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log):
                        $actionMeta = audit_action_meta((string) $log['action']);
                    ?>
                        <tr>
                            <td>
                                <span class="table-date"><?= e(format_datetime($log['created_at'], 'M j, Y')) ?></span>
                                <span class="table-subdate"><?= e(format_datetime($log['created_at'], 'g:i:s A')) ?></span>
                            </td>
                            <td>
                                <span class="audit-badge audit-badge--<?= e($actionMeta['tone']) ?>">
                                    <i class="bi <?= e($actionMeta['icon']) ?>" aria-hidden="true"></i><?= e($actionMeta['label']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="audit-description"><?= e($log['description']) ?></span>
                                <span class="table-subdate"><?= e(time_ago($log['created_at'])) ?> · <code><?= e($log['action']) ?></code></span>
                            </td>
                            <td>
                                <div class="table-identity">
                                    <?= avatar_markup((string) ($log['admin_name'] ?? 'System'), null, 'avatar--xs') ?>
                                    <span class="table-date"><?= e($log['admin_name'] ?? 'Deleted administrator') ?></span>
                                </div>
                            </td>
                            <td><code class="ip-chip"><?= e($log['ip_address'] ?: '—') ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer">
            <span class="table-footer__summary">
                Showing <strong><?= e((string) $pagination['from']) ?>–<?= e((string) $pagination['to']) ?></strong>
                of <strong><?= e((string) $pagination['total']) ?></strong> entries
                <?= $search !== '' ? ' matching “' . e($search) . '”' : '' ?>
            </span>
            <?= pagination_html($pagination) ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <span class="empty-state__icon"><i class="bi bi-journal-x" aria-hidden="true"></i></span>
            <h3><?= $search !== '' ? 'No matching activity' : 'No administrative activity yet' ?></h3>
            <p><?= $search !== '' ? 'Try a different search term.' : 'Audited actions will appear here as administrators manage the election.' ?></p>
            <?php if ($search !== ''): ?>
                <a class="btn btn-light" href="<?= e(url('admin/activity.php')) ?>">Clear search</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="info-callout mt-4">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <div>
        <strong>About this log</strong>
        <p>Successful administrator logins and management changes are recorded. The audit log does not record ballot choices and cannot be used to link a voter to a candidate. The application does not provide tamper-evident sealing of these records; a production election would require independent append-only storage.</p>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin_footer.php'; ?>
