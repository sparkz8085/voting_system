<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

$admin = Auth::requireAdmin();
$pdo = Database::connection();
$timezone = new DateTimeZone(APP_TIMEZONE);

$defaultStart = (new DateTimeImmutable('tomorrow 09:00', $timezone))->format('Y-m-d\TH:i');
$defaultEnd = (new DateTimeImmutable('+7 days 18:00', $timezone))->format('Y-m-d\TH:i');

$formMode = 'create';
$formData = [
    'id'          => 0,
    'title'       => '',
    'description' => '',
    'start_at'    => $defaultStart,
    'end_at'      => $defaultEnd,
];
$formErrors = [];
$openModal = false;

/** Fetch one election with all counts needed for lifecycle decisions. */
function admin_find_election(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT e.*,
                (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count,
                (SELECT COUNT(*) FROM votes v WHERE v.election_id = e.id) AS vote_count,
                (SELECT COUNT(*) FROM vote_participation p WHERE p.election_id = e.id) AS participation_count
         FROM elections e WHERE e.id = ? LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

if (is_post()) {
    csrf_abort_if_invalid();
    $action = post_string('action');
    $electionId = positive_int($_POST['election_id'] ?? 0);

    if ($action === 'save') {
        $formMode = $electionId > 0 ? 'edit' : 'create';
        $formData = [
            'id'          => $electionId,
            'title'       => post_string('title'),
            'description' => post_string('description'),
            'start_at'    => post_string('start_at'),
            'end_at'      => post_string('end_at'),
        ];

        if (mb_strlen($formData['title']) < 5 || mb_strlen($formData['title']) > 150) {
            $formErrors['title'] = 'Election title must be between 5 and 150 characters.';
        }
        if (mb_strlen($formData['description']) > 3000) {
            $formErrors['description'] = 'Description must be 3,000 characters or fewer.';
        }

        $start = parse_datetime_local($formData['start_at']);
        $end = parse_datetime_local($formData['end_at']);
        if (!$start) {
            $formErrors['start_at'] = 'Enter a valid start date and time.';
        }
        if (!$end) {
            $formErrors['end_at'] = 'Enter a valid end date and time.';
        }
        if ($start && $end && $end <= $start) {
            $formErrors['end_at'] = 'The end time must be after the start time.';
        }

        $existing = null;
        if ($electionId > 0) {
            $existing = admin_find_election($pdo, $electionId);
            if (!$existing) {
                flash_redirect('admin/elections.php', 'danger', 'That election no longer exists.');
            }
            if (!Election::canEdit($existing, (int) $existing['vote_count'])) {
                flash_redirect(
                    'admin/elections.php',
                    'warning',
                    'This election can no longer be edited because voting has begun or it has ended. Schedule changes are locked to protect the ballot.'
                );
            }
        }

        if (!$formErrors) {
            $title = $formData['title'];
            $description = $formData['description'] !== '' ? $formData['description'] : null;

            if ($electionId > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE elections SET title = ?, description = ?, start_at = ?, end_at = ? WHERE id = ?'
                );
                $stmt->execute([
                    $title,
                    $description,
                    $start->format('Y-m-d H:i:s'),
                    $end->format('Y-m-d H:i:s'),
                    $electionId,
                ]);
                audit_log((int) $admin['id'], 'election.updated', 'Updated election #' . $electionId . ': ' . $title);
                flash('success', 'Election details updated.');
                redirect('admin/elections.php', 303);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO elections (title, description, start_at, end_at, status, created_by)
                 VALUES (?, ?, ?, ?, 'draft', ?)"
            );
            $stmt->execute([
                $title,
                $description,
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                (int) $admin['id'],
            ]);
            $newId = (int) $pdo->lastInsertId();
            audit_log((int) $admin['id'], 'election.created', 'Created election #' . $newId . ': ' . $title);
            flash('success', 'Election created as a draft. Add at least two candidates, then activate it.');
            redirect('admin/candidates.php?election_id=' . $newId, 303);
        }

        $openModal = true;
    } else {
        if ($electionId <= 0) {
            flash_redirect('admin/elections.php', 'danger', 'Choose a valid election.');
        }
        $election = admin_find_election($pdo, $electionId);
        if (!$election) {
            flash_redirect('admin/elections.php', 'danger', 'That election no longer exists.');
        }

        if ($action === 'activate') {
            if ((string) $election['status'] !== 'draft') {
                flash_redirect('admin/elections.php', 'warning', 'Only draft elections can be activated.');
            }
            if ((int) $election['candidate_count'] < 2) {
                flash_redirect(
                    'admin/elections.php',
                    'danger',
                    'Add at least two candidates before activating this election.'
                );
            }
            $end = new DateTimeImmutable((string) $election['end_at'], $timezone);
            if ($end <= new DateTimeImmutable('now', $timezone)) {
                flash_redirect(
                    'admin/elections.php',
                    'danger',
                    'The scheduled end time has already passed. Edit the election window before activating it.'
                );
            }
            $stmt = $pdo->prepare("UPDATE elections SET status = 'active' WHERE id = ? AND status = 'draft'");
            $stmt->execute([$electionId]);
            audit_log((int) $admin['id'], 'election.activated', 'Activated election #' . $electionId . ': ' . $election['title']);
            flash('success', 'Election activated. Voters can now see the ballot during the scheduled window.');
            redirect('admin/elections.php', 303);
        }

        if ($action === 'close') {
            if ((string) $election['status'] !== 'active') {
                flash_redirect('admin/elections.php', 'warning', 'Only an active election can be closed.');
            }
            $stmt = $pdo->prepare("UPDATE elections SET status = 'closed' WHERE id = ? AND status = 'active'");
            $stmt->execute([$electionId]);
            audit_log((int) $admin['id'], 'election.closed', 'Closed election #' . $electionId . ': ' . $election['title']);
            flash('success', 'Election closed. Voting is now disabled; review the results before publishing.');
            redirect('admin/elections.php', 303);
        }

        if ($action === 'publish') {
            if ((int) $election['results_published'] === 1) {
                flash_redirect('admin/elections.php', 'warning', 'Results for this election are already published.');
            }
            if (!Election::canPublishResults($election)) {
                flash_redirect('admin/elections.php', 'danger', 'Results can only be published after voting has ended.');
            }
            if ((int) $election['candidate_count'] < 1) {
                flash_redirect('admin/elections.php', 'danger', 'This election has no candidates, so there are no results to publish.');
            }
            if ((int) $election['vote_count'] !== (int) $election['participation_count']) {
                flash_redirect(
                    'admin/elections.php',
                    'danger',
                    'Integrity check failed: anonymous ballots and participation records do not match. Results were not published.'
                );
            }
            $stmt = $pdo->prepare('UPDATE elections SET results_published = 1 WHERE id = ?');
            $stmt->execute([$electionId]);
            audit_log((int) $admin['id'], 'election.published', 'Published results for election #' . $electionId . ': ' . $election['title']);
            flash('success', 'Results published. Voters can now view the certified outcome.');
            redirect('admin/elections.php', 303);
        }

        if ($action === 'unpublish') {
            if ((int) $election['results_published'] !== 1) {
                flash_redirect('admin/elections.php', 'warning', 'Results for this election are not published.');
            }
            $stmt = $pdo->prepare('UPDATE elections SET results_published = 0 WHERE id = ?');
            $stmt->execute([$electionId]);
            audit_log((int) $admin['id'], 'election.unpublished', 'Unpublished results for election #' . $electionId . ': ' . $election['title']);
            flash('success', 'Results hidden from voters until published again.');
            redirect('admin/elections.php', 303);
        }

        if ($action === 'delete') {
            if ((int) $election['vote_count'] > 0 || (int) $election['participation_count'] > 0) {
                flash_redirect(
                    'admin/elections.php',
                    'danger',
                    'This election has recorded votes and cannot be deleted. Close it instead to preserve the record.'
                );
            }
            try {
                $stmt = $pdo->prepare('DELETE FROM elections WHERE id = ?');
                $stmt->execute([$electionId]);
                audit_log((int) $admin['id'], 'election.deleted', 'Deleted election #' . $electionId . ': ' . $election['title']);
                flash('success', 'Draft election deleted.');
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    flash('danger', 'This election is referenced by other records and cannot be deleted.');
                } else {
                    throw $exception;
                }
            }
            redirect('admin/elections.php', 303);
        }

        flash_redirect('admin/elections.php', 'danger', 'Unknown election action.');
    }
}

// Preserve a useful create/edit modal after a validation failure or direct GET.
if (!is_post()) {
    if (isset($_GET['edit'])) {
        $editId = positive_int($_GET['edit']);
        $editElection = $editId > 0 ? admin_find_election($pdo, $editId) : null;
        if (!$editElection) {
            flash_redirect('admin/elections.php', 'danger', 'That election could not be found.');
        }
        if (!Election::canEdit($editElection, (int) $editElection['vote_count'])) {
            flash_redirect(
                'admin/elections.php',
                'warning',
                'This election cannot be edited now. Only draft or scheduled elections without votes may be changed.'
            );
        }
        $formMode = 'edit';
        $formData = [
            'id'          => (int) $editElection['id'],
            'title'       => $editElection['title'],
            'description' => $editElection['description'] ?? '',
            'start_at'    => datetime_input_value($editElection['start_at']),
            'end_at'      => datetime_input_value($editElection['end_at']),
        ];
        $openModal = true;
    } elseif (isset($_GET['new'])) {
        $openModal = true;
    }
}

$filter = (string) ($_GET['filter'] ?? 'all');
$allowedFilters = ['all', 'live', 'upcoming', 'draft', 'completed'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}
$search = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$rowsStmt = $pdo->query(
    "SELECT e.*,
            (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count,
            (SELECT COUNT(*) FROM votes v WHERE v.election_id = e.id) AS vote_count,
            (SELECT COUNT(*) FROM vote_participation p WHERE p.election_id = e.id) AS participation_count
     FROM elections e
     ORDER BY e.updated_at DESC, e.id DESC"
);
$allElections = $rowsStmt->fetchAll();
$now = new DateTimeImmutable('now', $timezone);

$filterCounts = ['all' => count($allElections), 'live' => 0, 'upcoming' => 0, 'draft' => 0, 'completed' => 0];
$decorated = [];
foreach ($allElections as $election) {
    $effective = Election::status($election, $now);
    $meta = Election::meta($effective);
    $election['effective_status'] = $effective;
    $election['status_meta'] = $meta;
    $election['can_edit'] = Election::canEdit($election, (int) $election['vote_count']);
    $election['can_manage_candidates'] = Election::canManageCandidates($election);
    $end = new DateTimeImmutable((string) $election['end_at'], $timezone);
    $election['can_activate'] = $election['status'] === 'draft'
        && (int) $election['candidate_count'] >= 2
        && $end > $now;
    $election['can_close'] = $election['status'] === 'active';
    $election['can_publish'] = Election::canPublishResults($election);
    $election['can_delete'] = (int) $election['vote_count'] === 0 && (int) $election['participation_count'] === 0;

    if ($effective === Election::LIVE) {
        $filterCounts['live']++;
    } elseif ($effective === Election::UPCOMING) {
        $filterCounts['upcoming']++;
    } elseif ($effective === Election::DRAFT) {
        $filterCounts['draft']++;
    } else {
        $filterCounts['completed']++;
    }

    $matchesSearch = $search === ''
        || mb_stripos((string) $election['title'], $search) !== false
        || mb_stripos((string) ($election['description'] ?? ''), $search) !== false;

    $matchesFilter = $filter === 'all'
        || ($filter === 'live' && $effective === Election::LIVE)
        || ($filter === 'upcoming' && $effective === Election::UPCOMING)
        || ($filter === 'draft' && $effective === Election::DRAFT)
        || ($filter === 'completed' && in_array($effective, [Election::ENDED, Election::CLOSED], true));

    if ($matchesSearch && $matchesFilter) {
        $decorated[] = $election;
    }
}

$listParams = [];
if ($filter !== 'all') {
    $listParams['filter'] = $filter;
}
if ($search !== '') {
    $listParams['q'] = $search;
}
$filterUrl = static function (string $target) use ($listParams, $search): string {
    $params = $listParams;
    if ($target === 'all') {
        unset($params['filter']);
    } else {
        $params['filter'] = $target;
    }
    $query = http_build_query($params);
    return url('admin/elections.php' . ($query !== '' ? '?' . $query : ''));
};

$pageTitle = 'Elections';
$pageEyebrow = 'Election management';
$activeNav = 'elections';
require_once dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="page-toolbar">
    <div>
        <p class="page-toolbar__lead">Prepare ballots, manage voting windows, and control when results become public.</p>
        <div class="page-toolbar__meta">
            <span><i class="bi bi-collection" aria-hidden="true"></i><?= e((string) count($allElections)) ?> total</span>
            <span><span class="status-dot status-dot--green status-dot--pulse"></span><?= e((string) $filterCounts['live']) ?> live</span>
            <span><i class="bi bi-calendar-event" aria-hidden="true"></i><?= e((string) $filterCounts['upcoming']) ?> scheduled</span>
        </div>
    </div>
    <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#electionModal"
            data-modal-mode="create">
        <i class="bi bi-plus-lg me-2" aria-hidden="true"></i>New election
    </button>
</div>

<div class="panel panel--flush">
    <div class="panel__toolbar">
        <div class="filter-tabs" role="tablist" aria-label="Filter elections">
            <?php
            $tabs = [
                'all' => 'All elections',
                'live' => 'Live',
                'upcoming' => 'Scheduled',
                'draft' => 'Drafts',
                'completed' => 'Completed',
            ];
            foreach ($tabs as $key => $label): ?>
                <a class="filter-tab<?= $filter === $key ? ' active' : '' ?>" href="<?= e($filterUrl($key)) ?>"
                   <?= $filter === $key ? 'aria-current="page"' : '' ?>>
                    <?= e($label) ?><span><?= e((string) $filterCounts[$key]) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <form class="app-search" method="get" action="<?= e(url('admin/elections.php')) ?>" role="search">
            <?php if ($filter !== 'all'): ?>
                <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <?php endif; ?>
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search elections…" aria-label="Search elections" maxlength="100">
            <?php if ($search !== ''): ?>
                <a href="<?= e($filterUrl($filter)) ?>" class="app-search__clear" aria-label="Clear search"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($decorated): ?>
        <div class="table-responsive">
            <table class="table app-table app-table--elections align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Election</th>
                        <th scope="col">Status</th>
                        <th scope="col">Schedule</th>
                        <th scope="col" class="text-center">Candidates</th>
                        <th scope="col" class="text-center">Ballots</th>
                        <th scope="col">Results</th>
                        <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($decorated as $election):
                        $effective = $election['effective_status'];
                        $meta = $election['status_meta'];
                        $editLink = url('admin/elections.php?' . http_build_query(array_merge($listParams, ['edit' => $election['id']])));
                    ?>
                        <tr>
                            <td>
                                <div class="table-primary">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <strong><?= e($election['title']) ?></strong>
                                        <?php if ((int) $election['results_published'] === 1): ?>
                                            <span class="mini-tag mini-tag--success"><i class="bi bi-award" aria-hidden="true"></i>Published</span>
                                        <?php endif; ?>
                                    </div>
                                    <span><?= e(truncate_text($election['description'], 86) ?: 'No description added') ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="status-pill status-pill--<?= e($meta['class']) ?>">
                                    <i class="bi <?= e($meta['icon']) ?>" aria-hidden="true"></i><?= e($meta['label']) ?>
                                </span>
                                <?php if ($election['status'] === 'draft' && !$election['can_activate']): ?>
                                    <span class="table-warning-note">Needs 2+ candidates and a future end date</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="table-date"><?= e(format_datetime($election['start_at'], 'M j, Y · g:i A')) ?></span>
                                <span class="table-subdate">to <?= e(format_datetime($election['end_at'], 'M j, Y · g:i A')) ?></span>
                            </td>
                            <td class="text-center">
                                <strong class="table-number"><?= e((string) $election['candidate_count']) ?></strong>
                            </td>
                            <td class="text-center">
                                <strong class="table-number"><?= e(number_format((int) $election['participation_count'])) ?></strong>
                                <?php if ((int) $election['vote_count'] !== (int) $election['participation_count']): ?>
                                    <span class="table-subdate text-danger">Integrity mismatch</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $election['results_published'] === 1): ?>
                                    <span class="result-state result-state--public"><i class="bi bi-globe2" aria-hidden="true"></i>Public</span>
                                <?php elseif ($effective === Election::ENDED || $effective === Election::CLOSED): ?>
                                    <span class="result-state result-state--ready"><i class="bi bi-lock" aria-hidden="true"></i>Ready to publish</span>
                                <?php else: ?>
                                    <span class="result-state result-state--hidden"><i class="bi bi-eye-slash" aria-hidden="true"></i>Not public</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="table-actions">
                                    <a class="btn btn-sm btn-icon btn-light" href="<?= e(url('admin/results.php?election_id=' . (int) $election['id'])) ?>"
                                       aria-label="View results for <?= e($election['title']) ?>" title="View results">
                                        <i class="bi bi-bar-chart-line" aria-hidden="true"></i>
                                    </a>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-icon btn-light" type="button" data-bs-toggle="dropdown"
                                                data-bs-boundary="viewport" aria-expanded="false" aria-label="Actions for <?= e($election['title']) ?>">
                                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end app-dropdown action-menu">
                                            <?php if ($election['can_edit']): ?>
                                                <li>
                                                    <a class="dropdown-item" href="<?= e($editLink) ?>">
                                                        <i class="bi bi-pencil-square" aria-hidden="true"></i>Edit details
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                            <li>
                                                <a class="dropdown-item" href="<?= e(url('admin/candidates.php?election_id=' . (int) $election['id'])) ?>">
                                                    <i class="bi bi-people" aria-hidden="true"></i>Manage candidates
                                                </a>
                                            </li>
                                            <?php if ($election['can_activate']): ?>
                                                <li>
                                                    <form method="post" action="<?= e(url('admin/elections.php')) ?>"
                                                          data-confirm="Activate “<?= e($election['title']) ?>”? Voters will see this election on their ballot during its scheduled window."
                                                          data-confirm-title="Activate election" data-confirm-button="Activate election" data-confirm-variant="primary">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="activate">
                                                        <input type="hidden" name="election_id" value="<?= (int) $election['id'] ?>">
                                                        <button type="submit" class="dropdown-item"><i class="bi bi-broadcast" aria-hidden="true"></i>Activate election</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                            <?php if ($election['can_close']): ?>
                                                <li>
                                                    <form method="post" action="<?= e(url('admin/elections.php')) ?>"
                                                          data-confirm="Close “<?= e($election['title']) ?>” now? No further ballots can be cast, even if the scheduled end time has not arrived."
                                                          data-confirm-title="Close election" data-confirm-button="Close election" data-confirm-variant="warning">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="close">
                                                        <input type="hidden" name="election_id" value="<?= (int) $election['id'] ?>">
                                                        <button type="submit" class="dropdown-item"><i class="bi bi-lock" aria-hidden="true"></i>Close voting</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                            <?php if ($election['can_publish']): ?>
                                                <li>
                                                    <form method="post" action="<?= e(url('admin/elections.php')) ?>"
                                                          data-confirm="Publish the certified results for “<?= e($election['title']) ?>”? They will become visible to all voters."
                                                          data-confirm-title="Publish results" data-confirm-button="Publish results" data-confirm-variant="primary">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="publish">
                                                        <input type="hidden" name="election_id" value="<?= (int) $election['id'] ?>">
                                                        <button type="submit" class="dropdown-item"><i class="bi bi-award" aria-hidden="true"></i>Publish results</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                            <?php if ((int) $election['results_published'] === 1): ?>
                                                <li>
                                                    <form method="post" action="<?= e(url('admin/elections.php')) ?>"
                                                          data-confirm="Hide published results for “<?= e($election['title']) ?>” from voters?"
                                                          data-confirm-title="Unpublish results" data-confirm-button="Unpublish" data-confirm-variant="warning">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="unpublish">
                                                        <input type="hidden" name="election_id" value="<?= (int) $election['id'] ?>">
                                                        <button type="submit" class="dropdown-item"><i class="bi bi-eye-slash" aria-hidden="true"></i>Unpublish results</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                            <?php if ($election['can_delete']): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form method="post" action="<?= e(url('admin/elections.php')) ?>"
                                                          data-confirm="Permanently delete “<?= e($election['title']) ?>” and its candidate list? This cannot be undone."
                                                          data-confirm-title="Delete election" data-confirm-button="Delete permanently" data-confirm-variant="danger">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="election_id" value="<?= (int) $election['id'] ?>">
                                                        <button type="submit" class="dropdown-item dropdown-item--danger"><i class="bi bi-trash3" aria-hidden="true"></i>Delete election</button>
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
    <?php else: ?>
        <div class="empty-state">
            <span class="empty-state__icon"><i class="bi bi-calendar2-x" aria-hidden="true"></i></span>
            <h3><?= $search !== '' || $filter !== 'all' ? 'No elections match your filters' : 'No elections yet' ?></h3>
            <p><?= $search !== '' || $filter !== 'all' ? 'Try a different search term or status filter.' : 'Create an election, add candidates, and activate the ballot when you are ready.' ?></p>
            <?php if ($search !== '' || $filter !== 'all'): ?>
                <a class="btn btn-light" href="<?= e(url('admin/elections.php')) ?>">Clear filters</a>
            <?php else: ?>
                <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#electionModal" data-modal-mode="create">
                    <i class="bi bi-plus-lg me-2" aria-hidden="true"></i>Create first election
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="info-callout mt-4">
    <i class="bi bi-shield-lock" aria-hidden="true"></i>
    <div>
        <strong>Ballot protection rules</strong>
        <p>Schedule edits are locked once voting starts or votes exist. Candidates can only be changed before an election goes live. Results can be published only after voting ends and only when the participation ledger matches the anonymous ballot count.</p>
    </div>
</div>

<div class="modal fade" id="electionModal" tabindex="-1" aria-labelledby="electionModalTitle" aria-hidden="true"
     <?= $openModal ? 'data-open-on-load="true"' : '' ?>>
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content app-modal">
            <form method="post" action="<?= e(url('admin/elections.php')) ?>" data-loading-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="election_id" value="<?= (int) $formData['id'] ?>">
                <div class="modal-header">
                    <div>
                        <span class="eyebrow"><?= $formMode === 'edit' ? 'Update schedule' : 'New ballot' ?></span>
                        <h2 class="modal-title" id="electionModalTitle"><?= $formMode === 'edit' ? 'Edit election' : 'Create an election' ?></h2>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($formErrors)): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
                            <div>Please correct the highlighted fields.</div>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="title" class="form-label">Election title</label>
                        <input type="text" class="form-control<?= field_invalid($formErrors, 'title') ?>" id="title" name="title"
                               value="<?= e($formData['title']) ?>" required minlength="5" maxlength="150"
                               placeholder="e.g. Student Council President 2027">
                        <?= field_error($formErrors, 'title') ?>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description <span class="form-hint">Optional</span></label>
                        <textarea class="form-control<?= field_invalid($formErrors, 'description') ?>" id="description" name="description"
                                  rows="4" maxlength="3000" placeholder="Explain what voters are electing and why it matters."><?= e($formData['description']) ?></textarea>
                        <div class="form-text">Shown to voters on the election card. Maximum 3,000 characters.</div>
                        <?= field_error($formErrors, 'description') ?>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="start_at" class="form-label">Voting opens</label>
                            <input type="datetime-local" class="form-control<?= field_invalid($formErrors, 'start_at') ?>" id="start_at" name="start_at"
                                   value="<?= e($formData['start_at']) ?>" required>
                            <?= field_error($formErrors, 'start_at') ?>
                        </div>
                        <div class="col-md-6">
                            <label for="end_at" class="form-label">Voting closes</label>
                            <input type="datetime-local" class="form-control<?= field_invalid($formErrors, 'end_at') ?>" id="end_at" name="end_at"
                                   value="<?= e($formData['end_at']) ?>" required>
                            <?= field_error($formErrors, 'end_at') ?>
                        </div>
                    </div>
                    <div class="form-note">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        New elections are saved as drafts. Add at least two candidates, then use <strong>Activate election</strong> from the actions menu. Times use <?= e(APP_TIMEZONE) ?>.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand" data-loading-button>
                        <span class="btn-label"><?= $formMode === 'edit' ? 'Save changes' : 'Create draft' ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin_footer.php'; ?>
