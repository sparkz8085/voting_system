<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

$admin = Auth::requireAdmin();
$pdo = Database::connection();

$editErrors = [];
$resetErrors = [];
$editVoter = null;
$resetVoter = null;
$editForm = [];
$openModal = '';

$findVoter = static function (int $id) use ($pdo): ?array {
    $stmt = $pdo->prepare(
        'SELECT v.*, (SELECT COUNT(*) FROM vote_participation p WHERE p.voter_id = v.id) AS participation_count
         FROM voters v WHERE v.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
};

if (is_post()) {
    csrf_abort_if_invalid();
    $action = post_string('action');
    $voterId = positive_int($_POST['voter_id'] ?? 0);
    $voter = $voterId > 0 ? $findVoter($voterId) : null;

    if (!$voter) {
        flash_redirect('admin/voters.php', 'danger', 'That voter account could not be found.');
    }

    if ($action === 'update_voter') {
        $editVoter = $voter;
        $editForm = [
            'id'         => (int) $voter['id'],
            'full_name'  => post_string('full_name'),
            'username'   => normalize_login_identifier(post_string('username')),
            'email'      => normalize_login_identifier(post_string('email')),
            'student_id' => mb_strtoupper(post_string('student_id')),
            'department' => post_string('department'),
        ];

        if (mb_strlen($editForm['full_name']) < 2 || mb_strlen($editForm['full_name']) > 100) {
            $editErrors['full_name'] = 'Enter the voter’s full name (2–100 characters).';
        } elseif (!preg_match('/\p{L}/u', $editForm['full_name']) || preg_match('/[\x00-\x1F\x7F<>]/u', $editForm['full_name'])) {
            $editErrors['full_name'] = 'Enter a valid name.';
        }
        $usernameError = username_policy_error($editForm['username']);
        if ($usernameError !== null) {
            $editErrors['username'] = $usernameError;
        }
        if (filter_var($editForm['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($editForm['email']) > 150) {
            $editErrors['email'] = 'Enter a valid email address.';
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9._\/-]{2,29}$/', $editForm['student_id'])) {
            $editErrors['student_id'] = 'Student ID must be 3–30 letters, numbers, dots, dashes, or slashes.';
        }
        if (mb_strlen($editForm['department']) < 2 || mb_strlen($editForm['department']) > 100) {
            $editErrors['department'] = 'Enter a department name (2–100 characters).';
        }

        if (!$editErrors) {
            $stmt = $pdo->prepare(
                'SELECT id, username, email, student_id FROM voters
                 WHERE id <> ? AND (username = ? OR email = ? OR student_id = ?) LIMIT 3'
            );
            $stmt->execute([$voterId, $editForm['username'], $editForm['email'], $editForm['student_id']]);
            foreach ($stmt->fetchAll() as $duplicate) {
                if (mb_strtolower((string) $duplicate['username']) === $editForm['username']) {
                    $editErrors['username'] = 'Another voter already uses that username.';
                }
                if (mb_strtolower((string) $duplicate['email']) === $editForm['email']) {
                    $editErrors['email'] = 'Another voter already uses that email.';
                }
                if (mb_strtoupper((string) $duplicate['student_id']) === $editForm['student_id']) {
                    $editErrors['student_id'] = 'Another voter already uses that student ID.';
                }
            }
        }

        if (!$editErrors) {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE voters SET full_name = ?, username = ?, email = ?, student_id = ?, department = ? WHERE id = ?'
                );
                $stmt->execute([
                    $editForm['full_name'], $editForm['username'], $editForm['email'],
                    $editForm['student_id'], $editForm['department'], $voterId,
                ]);
                audit_log((int) $admin['id'], 'voter.updated', 'Updated voter account #' . $voterId . ' (' . $editForm['username'] . ').');
                flash('success', 'Voter details updated.');
                redirect('admin/voters.php', 303);
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    $editErrors['general'] = 'One of those details is already used by another voter.';
                } else {
                    throw $exception;
                }
            }
        }
        $openModal = 'edit';
    } elseif ($action === 'reset_password') {
        $resetVoter = $voter;
        $newPassword = post_raw('new_password');
        $confirmPassword = post_raw('new_password_confirm');
        $passwordError = password_policy_error($newPassword);
        if ($passwordError !== null) {
            $resetErrors['new_password'] = $passwordError;
        }
        if ($newPassword !== $confirmPassword) {
            $resetErrors['new_password_confirm'] = 'The two passwords do not match.';
        }
        if (!$resetErrors) {
            $stmt = $pdo->prepare('UPDATE voters SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $voterId]);
            audit_log((int) $admin['id'], 'voter.password_reset', 'Reset password for voter account #' . $voterId . ' (' . $voter['username'] . ').');
            flash('success', 'Password reset for ' . $voter['full_name'] . '. Share the new password through a secure channel.');
            redirect('admin/voters.php', 303);
        }
        $openModal = 'reset';
    } elseif ($action === 'toggle_active') {
        $newState = (int) $voter['is_active'] === 1 ? 0 : 1;
        $stmt = $pdo->prepare('UPDATE voters SET is_active = ? WHERE id = ?');
        $stmt->execute([$newState, $voterId]);
        audit_log(
            (int) $admin['id'],
            $newState === 1 ? 'voter.activated' : 'voter.deactivated',
            ($newState === 1 ? 'Activated' : 'Deactivated') . ' voter account #' . $voterId . ' (' . $voter['username'] . ').'
        );
        flash(
            'success',
            $newState === 1
                ? 'Voter account activated. The voter can sign in again.'
                : 'Voter account deactivated. Existing access is blocked on the next request.'
        );
        redirect('admin/voters.php', 303);
    } elseif ($action === 'delete_voter') {
        if ((int) $voter['participation_count'] > 0) {
            flash_redirect(
                'admin/voters.php',
                'danger',
                'This voter has participation records and cannot be deleted. Deactivate the account instead to preserve the election record.'
            );
        }
        try {
            $stmt = $pdo->prepare('DELETE FROM voters WHERE id = ?');
            $stmt->execute([$voterId]);
            audit_log((int) $admin['id'], 'voter.deleted', 'Deleted voter account #' . $voterId . ' (' . $voter['username'] . ').');
            flash('success', 'Voter account deleted.');
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                flash('danger', 'This voter is referenced by election records and cannot be deleted.');
            } else {
                throw $exception;
            }
        }
        redirect('admin/voters.php', 303);
    } else {
        flash_redirect('admin/voters.php', 'danger', 'Unknown voter action.');
    }
}

// Open edit/reset modals from a GET link without letting modal ids leak into pagination.
if (!is_post()) {
    if (isset($_GET['edit'])) {
        $editId = positive_int($_GET['edit']);
        $editVoter = $editId > 0 ? $findVoter($editId) : null;
        if (!$editVoter) {
            flash_redirect('admin/voters.php', 'danger', 'That voter account could not be found.');
        }
        $editForm = [
            'id'         => (int) $editVoter['id'],
            'full_name'  => $editVoter['full_name'],
            'username'   => $editVoter['username'],
            'email'      => $editVoter['email'],
            'student_id' => $editVoter['student_id'],
            'department' => $editVoter['department'],
        ];
        $openModal = 'edit';
    } elseif (isset($_GET['reset'])) {
        $resetId = positive_int($_GET['reset']);
        $resetVoter = $resetId > 0 ? $findVoter($resetId) : null;
        if (!$resetVoter) {
            flash_redirect('admin/voters.php', 'danger', 'That voter account could not be found.');
        }
        $openModal = 'reset';
    }
}
unset($_GET['edit'], $_GET['reset']);

$search = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}
$statusFilter = (string) ($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
    $statusFilter = 'all';
}

$counts = $pdo->query(
    'SELECT COUNT(*) AS total,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive_count
     FROM voters'
)->fetch();
$totalVoters = (int) $counts['total'];
$activeCount = (int) $counts['active_count'];
$inactiveCount = (int) $counts['inactive_count'];

$where = [];
$params = [];
if ($search !== '') {
    $like = '%' . like_escape($search) . '%';
    $where[] = '(v.full_name LIKE ? OR v.email LIKE ? OR v.username LIKE ? OR v.student_id LIKE ? OR v.department LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($statusFilter === 'active') {
    $where[] = 'v.is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'v.is_active = 0';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM voters v ' . $whereSql);
$countStmt->execute($params);
$filteredCount = (int) $countStmt->fetchColumn();

$page = max(1, positive_int($_GET['page'] ?? 1));
$perPage = 10;
$pagination = paginate($filteredCount, $perPage, $page);

$listSql = 'SELECT v.*,
                   (SELECT COUNT(*) FROM vote_participation p WHERE p.voter_id = v.id) AS participation_count
            FROM voters v ' . $whereSql . '
            ORDER BY v.created_at DESC, v.id DESC
            LIMIT ? OFFSET ?';
$listStmt = $pdo->prepare($listSql);
$bindIndex = 1;
foreach ($params as $param) {
    $listStmt->bindValue($bindIndex++, $param, PDO::PARAM_STR);
}
$listStmt->bindValue($bindIndex++, (int) $pagination['per'], PDO::PARAM_INT);
$listStmt->bindValue($bindIndex, (int) $pagination['offset'], PDO::PARAM_INT);
$listStmt->execute();
$voters = $listStmt->fetchAll();

$filterUrl = static function (string $status) use ($search): string {
    $params = [];
    if ($status !== 'all') {
        $params['status'] = $status;
    }
    if ($search !== '') {
        $params['q'] = $search;
    }
    return url('admin/voters.php' . ($params ? '?' . http_build_query($params) : ''));
};

$listParams = [];
if ($statusFilter !== 'all') {
    $listParams['status'] = $statusFilter;
}
if ($search !== '') {
    $listParams['q'] = $search;
}
if ((int) $pagination['page'] > 1) {
    $listParams['page'] = (int) $pagination['page'];
}

$pageTitle = 'Voters';
$pageEyebrow = 'Voter roll management';
$activeNav = 'voters';
require_once dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="page-toolbar">
    <div>
        <p class="page-toolbar__lead">Review the voter roll, correct student details, and control account access.</p>
        <div class="page-toolbar__meta">
            <span><i class="bi bi-people" aria-hidden="true"></i><?= e(number_format($totalVoters)) ?> registered</span>
            <span><span class="status-dot status-dot--green"></span><?= e(number_format($activeCount)) ?> active</span>
            <span><span class="status-dot status-dot--muted"></span><?= e(number_format($inactiveCount)) ?> inactive</span>
        </div>
    </div>
    <a class="btn btn-light" href="<?= e(url('admin/voters.php')) ?>">
        <i class="bi bi-arrow-clockwise me-2" aria-hidden="true"></i>Reset filters
    </a>
</div>

<div class="panel panel--flush">
    <div class="panel__toolbar">
        <div class="filter-tabs" role="tablist" aria-label="Filter voter accounts">
            <a class="filter-tab<?= $statusFilter === 'all' ? ' active' : '' ?>" href="<?= e($filterUrl('all')) ?>">
                All voters <span><?= e(number_format($totalVoters)) ?></span>
            </a>
            <a class="filter-tab<?= $statusFilter === 'active' ? ' active' : '' ?>" href="<?= e($filterUrl('active')) ?>">
                Active <span><?= e(number_format($activeCount)) ?></span>
            </a>
            <a class="filter-tab<?= $statusFilter === 'inactive' ? ' active' : '' ?>" href="<?= e($filterUrl('inactive')) ?>">
                Inactive <span><?= e(number_format($inactiveCount)) ?></span>
            </a>
        </div>
        <form class="app-search" method="get" action="<?= e(url('admin/voters.php')) ?>" role="search">
            <?php if ($statusFilter !== 'all'): ?>
                <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            <?php endif; ?>
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" name="q" value="<?= e($search) ?>" maxlength="100"
                   placeholder="Search name, ID, email…" aria-label="Search voters">
            <?php if ($search !== ''): ?>
                <a href="<?= e($filterUrl($statusFilter)) ?>" class="app-search__clear" aria-label="Clear search"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($voters): ?>
        <div class="table-responsive">
            <table class="table app-table app-table--voters align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Voter</th>
                        <th scope="col">Student details</th>
                        <th scope="col">Registered</th>
                        <th scope="col">Last sign-in</th>
                        <th scope="col" class="text-center">Ballots</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($voters as $voter):
                        $editLink = url('admin/voters.php?' . http_build_query(array_merge($listParams, ['edit' => (int) $voter['id']])));
                        $resetLink = url('admin/voters.php?' . http_build_query(array_merge($listParams, ['reset' => (int) $voter['id']])));
                    ?>
                        <tr>
                            <td>
                                <div class="table-identity">
                                    <?= avatar_markup((string) $voter['full_name'], null, 'avatar--sm') ?>
                                    <div class="table-primary">
                                        <strong><?= e($voter['full_name']) ?></strong>
                                        <span><?= e($voter['email']) ?> · @<?= e($voter['username']) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="table-date"><?= e($voter['student_id']) ?></span>
                                <span class="table-subdate"><?= e($voter['department']) ?></span>
                            </td>
                            <td>
                                <span class="table-date"><?= e(format_date($voter['created_at'], 'M j, Y')) ?></span>
                                <span class="table-subdate"><?= e(time_ago($voter['created_at'])) ?></span>
                            </td>
                            <td>
                                <?php if ($voter['last_login_at']): ?>
                                    <span class="table-date"><?= e(format_date($voter['last_login_at'], 'M j, Y')) ?></span>
                                    <span class="table-subdate"><?= e(format_datetime($voter['last_login_at'], 'g:i A')) ?></span>
                                <?php else: ?>
                                    <span class="table-subdate">Never signed in</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <strong class="table-number"><?= e((string) $voter['participation_count']) ?></strong>
                            </td>
                            <td>
                                <?php if ((int) $voter['is_active'] === 1): ?>
                                    <span class="account-state account-state--active"><span class="status-dot status-dot--green"></span>Active</span>
                                <?php else: ?>
                                    <span class="account-state account-state--inactive"><span class="status-dot status-dot--muted"></span>Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="table-actions">
                                    <a class="btn btn-sm btn-icon btn-light" href="<?= e($editLink) ?>" title="Edit voter" aria-label="Edit <?= e($voter['full_name']) ?>">
                                        <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                    </a>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-icon btn-light" type="button" data-bs-toggle="dropdown"
                                                data-bs-boundary="viewport" aria-expanded="false" aria-label="Actions for <?= e($voter['full_name']) ?>">
                                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end app-dropdown action-menu">
                                            <li><a class="dropdown-item" href="<?= e($editLink) ?>"><i class="bi bi-person-gear" aria-hidden="true"></i>Edit details</a></li>
                                            <li><a class="dropdown-item" href="<?= e($resetLink) ?>"><i class="bi bi-key" aria-hidden="true"></i>Reset password</a></li>
                                            <li>
                                                <form method="post" action="<?= e(url('admin/voters.php')) ?>"
                                                      data-confirm="<?= (int) $voter['is_active'] === 1 ? 'Deactivate this voter account? They will be blocked on their next request and cannot sign in.' : 'Reactivate this voter account? They will be able to sign in and vote again.' ?>"
                                                      data-confirm-title="<?= (int) $voter['is_active'] === 1 ? 'Deactivate voter' : 'Activate voter' ?>"
                                                      data-confirm-button="<?= (int) $voter['is_active'] === 1 ? 'Deactivate account' : 'Activate account' ?>"
                                                      data-confirm-variant="<?= (int) $voter['is_active'] === 1 ? 'warning' : 'primary' ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="toggle_active">
                                                    <input type="hidden" name="voter_id" value="<?= (int) $voter['id'] ?>">
                                                    <button type="submit" class="dropdown-item">
                                                        <i class="bi <?= (int) $voter['is_active'] === 1 ? 'bi-person-slash' : 'bi-person-check' ?>" aria-hidden="true"></i>
                                                        <?= (int) $voter['is_active'] === 1 ? 'Deactivate account' : 'Activate account' ?>
                                                    </button>
                                                </form>
                                            </li>
                                            <?php if ((int) $voter['participation_count'] === 0): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form method="post" action="<?= e(url('admin/voters.php')) ?>"
                                                          data-confirm="Permanently delete the voter account for <?= e($voter['full_name']) ?>? This cannot be undone."
                                                          data-confirm-title="Delete voter" data-confirm-button="Delete account" data-confirm-variant="danger">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete_voter">
                                                        <input type="hidden" name="voter_id" value="<?= (int) $voter['id'] ?>">
                                                        <button type="submit" class="dropdown-item dropdown-item--danger"><i class="bi bi-trash3" aria-hidden="true"></i>Delete account</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer">
            <span class="table-footer__summary">
                Showing <strong><?= e((string) $pagination['from']) ?>–<?= e((string) $pagination['to']) ?></strong>
                of <strong><?= e((string) $pagination['total']) ?></strong> voter<?= (int) $pagination['total'] === 1 ? '' : 's' ?>
                <?= $search !== '' ? ' matching “' . e($search) . '”' : '' ?>
            </span>
            <?= pagination_html($pagination) ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <span class="empty-state__icon"><i class="bi bi-person-x" aria-hidden="true"></i></span>
            <h3>No voters found</h3>
            <p><?= $search !== '' || $statusFilter !== 'all' ? 'Try a different search term or account status.' : 'Voter accounts will appear here as students register.' ?></p>
            <?php if ($search !== '' || $statusFilter !== 'all'): ?>
                <a class="btn btn-light" href="<?= e(url('admin/voters.php')) ?>">Clear filters</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="info-callout mt-4">
    <i class="bi bi-shield-check" aria-hidden="true"></i>
    <div>
        <strong>Account controls and election records</strong>
        <p>Deactivated voters are blocked on their next request. Accounts with participation records cannot be deleted, and ballots never store the voter's identity. A password reset does not alter any existing ballot or participation receipt.</p>
    </div>
</div>

<?php if ($openModal === 'edit' && $editVoter): ?>
    <div class="modal fade" id="voterEditModal" tabindex="-1" aria-labelledby="voterEditModalTitle" aria-hidden="true" data-open-on-load="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content app-modal">
                <form method="post" action="<?= e(url('admin/voters.php')) ?>" data-loading-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_voter">
                    <input type="hidden" name="voter_id" value="<?= (int) $editVoter['id'] ?>">
                    <div class="modal-header">
                        <div>
                            <span class="eyebrow">Voter account</span>
                            <h2 class="modal-title" id="voterEditModalTitle">Edit <?= e($editVoter['full_name']) ?></h2>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if (!empty($editErrors)): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
                                <div><?= e($editErrors['general'] ?? 'Please correct the highlighted fields.') ?></div>
                            </div>
                        <?php endif; ?>
                        <div class="form-note mb-4">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            Changing a username, email, or student ID changes how the voter signs in and how they are identified in the roll.
                        </div>
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="edit_full_name" class="form-label">Full name</label>
                                <input type="text" class="form-control<?= field_invalid($editErrors, 'full_name') ?>" id="edit_full_name" name="full_name"
                                       value="<?= e($editForm['full_name'] ?? $editVoter['full_name']) ?>" required maxlength="100">
                                <?= field_error($editErrors, 'full_name') ?>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_username" class="form-label">Username</label>
                                <input type="text" class="form-control<?= field_invalid($editErrors, 'username') ?>" id="edit_username" name="username"
                                       value="<?= e($editForm['username'] ?? $editVoter['username']) ?>" required maxlength="30"
                                       pattern="[A-Za-z0-9._-]{3,30}">
                                <?= field_error($editErrors, 'username') ?>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_email" class="form-label">Email address</label>
                                <input type="email" class="form-control<?= field_invalid($editErrors, 'email') ?>" id="edit_email" name="email"
                                       value="<?= e($editForm['email'] ?? $editVoter['email']) ?>" required maxlength="150">
                                <?= field_error($editErrors, 'email') ?>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_student_id" class="form-label">Student ID</label>
                                <input type="text" class="form-control<?= field_invalid($editErrors, 'student_id') ?>" id="edit_student_id" name="student_id"
                                       value="<?= e($editForm['student_id'] ?? $editVoter['student_id']) ?>" required maxlength="30">
                                <?= field_error($editErrors, 'student_id') ?>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_department" class="form-label">Department</label>
                                <input type="text" class="form-control<?= field_invalid($editErrors, 'department') ?>" id="edit_department" name="department"
                                       value="<?= e($editForm['department'] ?? $editVoter['department']) ?>" required maxlength="100">
                                <?= field_error($editErrors, 'department') ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a class="btn btn-light me-auto" href="<?= e(url('admin/voters.php?reset=' . (int) $editVoter['id'])) ?>">
                            <i class="bi bi-key me-1" aria-hidden="true"></i>Reset password
                        </a>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand" data-loading-button><span class="btn-label">Save changes</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($openModal === 'reset' && $resetVoter): ?>
    <div class="modal fade" id="voterResetModal" tabindex="-1" aria-labelledby="voterResetModalTitle" aria-hidden="true" data-open-on-load="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content app-modal">
                <form method="post" action="<?= e(url('admin/voters.php')) ?>" data-loading-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="voter_id" value="<?= (int) $resetVoter['id'] ?>">
                    <div class="modal-header">
                        <div>
                            <span class="eyebrow">Account recovery</span>
                            <h2 class="modal-title" id="voterResetModalTitle">Reset password</h2>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="reset-identity">
                            <?= avatar_markup((string) $resetVoter['full_name'], null, 'avatar--md') ?>
                            <div>
                                <strong><?= e($resetVoter['full_name']) ?></strong>
                                <span><?= e($resetVoter['username']) ?> · <?= e($resetVoter['email']) ?></span>
                            </div>
                        </div>
                        <?php if (!empty($resetErrors)): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
                                <div>Please correct the highlighted fields.</div>
                            </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New password</label>
                            <input type="password" class="form-control<?= field_invalid($resetErrors, 'new_password') ?>" id="new_password" name="new_password"
                                   required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password"
                                   data-password-strength-source placeholder="At least <?= PASSWORD_MIN_LENGTH ?> characters">
                            <div class="password-strength" data-password-strength aria-live="polite">
                                <span class="password-strength__track"><span class="password-strength__bar"></span></span>
                                <span class="password-strength__label">Use letters and numbers</span>
                            </div>
                            <?= field_error($resetErrors, 'new_password') ?>
                        </div>
                        <div class="mb-2">
                            <label for="new_password_confirm" class="form-label">Confirm new password</label>
                            <input type="password" class="form-control<?= field_invalid($resetErrors, 'new_password_confirm') ?>" id="new_password_confirm" name="new_password_confirm"
                                   required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
                            <?= field_error($resetErrors, 'new_password_confirm') ?>
                        </div>
                        <div class="form-note">
                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                            The password is hashed immediately. It is not stored in plain text and cannot be viewed later. Share it with the voter through a secure channel.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand" data-loading-button><span class="btn-label">Reset password</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/admin_footer.php'; ?>
