<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

$admin = Auth::requireAdmin();
$pdo = Database::connection();

$overview = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM voters) AS total_voters,
        (SELECT COUNT(*) FROM voters WHERE is_active = 1) AS active_voters,
        (SELECT COUNT(*) FROM candidates) AS total_candidates,
        (SELECT COUNT(*) FROM votes) AS total_votes,
        (SELECT COUNT(*) FROM elections) AS total_elections,
        (SELECT COUNT(*) FROM elections WHERE status = 'active' AND end_at > NOW()) AS active_elections,
        (SELECT COUNT(*) FROM elections WHERE status = 'active' AND NOW() BETWEEN start_at AND end_at) AS live_elections,
        (SELECT COUNT(*) FROM elections WHERE results_published = 1) AS published_results"
)->fetch();

$totalVoters = (int) $overview['total_voters'];
$activeVoters = (int) $overview['active_voters'];
$totalCandidates = (int) $overview['total_candidates'];
$totalVotes = (int) $overview['total_votes'];
$activeElections = (int) $overview['active_elections'];
$liveElections = (int) $overview['live_elections'];

$progressStmt = $pdo->query(
    "SELECT e.*,
            (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count,
            (SELECT COUNT(*) FROM vote_participation p WHERE p.election_id = e.id) AS participation_count
     FROM elections e
     WHERE e.status = 'active' AND NOW() >= e.start_at AND NOW() <= e.end_at
     ORDER BY e.end_at ASC LIMIT 1"
);
$liveElection = $progressStmt->fetch() ?: null;
if (!$liveElection) {
    $nextStmt = $pdo->query(
        "SELECT e.*,
                (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count,
                (SELECT COUNT(*) FROM vote_participation p WHERE p.election_id = e.id) AS participation_count
         FROM elections e
         WHERE e.status = 'active' AND e.start_at > NOW()
         ORDER BY e.start_at ASC LIMIT 1"
    );
    $nextElection = $nextStmt->fetch() ?: null;
} else {
    $nextElection = null;
}

$recentElectionsStmt = $pdo->query(
    "SELECT e.*,
            (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count,
            (SELECT COUNT(*) FROM vote_participation p WHERE p.election_id = e.id) AS participation_count
     FROM elections e
     ORDER BY e.updated_at DESC, e.id DESC LIMIT 5"
);
$recentElections = $recentElectionsStmt->fetchAll();

$recentVoters = $pdo->query(
    'SELECT id, full_name, email, username, department, student_id, is_active, created_at
     FROM voters ORDER BY created_at DESC, id DESC LIMIT 5'
)->fetchAll();

$recentActivityStmt = $pdo->query(
    'SELECT a.*, ad.full_name AS admin_name
     FROM audit_logs a
     LEFT JOIN admins ad ON ad.id = a.admin_id
     ORDER BY a.created_at DESC, a.id DESC LIMIT 6'
);
$recentActivity = $recentActivityStmt->fetchAll();

$activitySeries = ResultService::recentVoteActivity(7);
$chartMax = 0;
$weekTotal = 0;
foreach ($activitySeries as $day) {
    $chartMax = max($chartMax, $day['total']);
    $weekTotal += $day['total'];
}

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', (string) $admin['full_name'])[0];
$eligibleForProgress = max(1, $activeVoters);

$pageTitle = 'Dashboard';
$pageEyebrow = 'Election office overview';
$activeNav = 'dashboard';
require_once dirname(__DIR__) . '/includes/admin_header.php';
?>

<section class="admin-welcome">
    <div class="admin-welcome__copy">
        <span class="admin-welcome__date"><i class="bi bi-calendar3" aria-hidden="true"></i><?= e(date('l, F j, Y')) ?></span>
        <h2><?= e($greeting) ?>, <?= e($firstName) ?>.</h2>
        <p>Here is the current picture of the campus election programme. All figures are calculated live from the database.</p>
        <div class="admin-welcome__actions">
            <a class="btn btn-light" href="<?= e(url('admin/elections.php?new=1')) ?>">
                <i class="bi bi-plus-lg me-2" aria-hidden="true"></i>New election
            </a>
            <a class="btn btn-outline-light" href="<?= e(url('admin/results.php')) ?>">
                <i class="bi bi-bar-chart-line me-2" aria-hidden="true"></i>Review results
            </a>
        </div>
    </div>
    <div class="admin-welcome__badge">
        <span class="admin-welcome__badge-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
        <div>
            <strong><?= $liveElections > 0 ? $liveElections . ' election' . ($liveElections === 1 ? '' : 's') . ' live' : 'No live election' ?></strong>
            <span><?= $liveElections > 0 ? 'Server checks are active' : 'Prepare the next ballot' ?></span>
        </div>
    </div>
</section>

<div class="row g-4 dashboard-stats">
    <div class="col-sm-6 col-xl-3">
        <a class="stat-card stat-card--indigo" href="<?= e(url('admin/voters.php')) ?>">
            <div class="stat-card__top">
                <span class="stat-card__icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                <span class="stat-card__arrow"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></span>
            </div>
            <span class="stat-card__value"><?= e(number_format($totalVoters)) ?></span>
            <span class="stat-card__label">Registered voters</span>
            <span class="stat-card__meta"><span class="status-dot status-dot--green"></span><?= e(number_format($activeVoters)) ?> active accounts</span>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a class="stat-card stat-card--teal" href="<?= e(url('admin/candidates.php')) ?>">
            <div class="stat-card__top">
                <span class="stat-card__icon"><i class="bi bi-person-badge" aria-hidden="true"></i></span>
                <span class="stat-card__arrow"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></span>
            </div>
            <span class="stat-card__value"><?= e(number_format($totalCandidates)) ?></span>
            <span class="stat-card__label">Candidates</span>
            <span class="stat-card__meta"><i class="bi bi-collection" aria-hidden="true"></i><?= e(number_format((int) $overview['total_elections'])) ?> elections on record</span>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a class="stat-card stat-card--amber" href="<?= e(url('admin/results.php')) ?>">
            <div class="stat-card__top">
                <span class="stat-card__icon"><i class="bi bi-check2-square" aria-hidden="true"></i></span>
                <span class="stat-card__arrow"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></span>
            </div>
            <span class="stat-card__value"><?= e(number_format($totalVotes)) ?></span>
            <span class="stat-card__label">Votes cast</span>
            <span class="stat-card__meta"><i class="bi bi-shield-check" aria-hidden="true"></i>Anonymous ballot records</span>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a class="stat-card stat-card--violet" href="<?= e(url('admin/elections.php')) ?>">
            <div class="stat-card__top">
                <span class="stat-card__icon"><i class="bi bi-calendar2-week" aria-hidden="true"></i></span>
                <span class="stat-card__arrow"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></span>
            </div>
            <span class="stat-card__value"><?= e(number_format($activeElections)) ?></span>
            <span class="stat-card__label">Active elections</span>
            <span class="stat-card__meta">
                <span class="status-dot <?= $liveElections > 0 ? 'status-dot--green status-dot--pulse' : '' ?>"></span>
                <?= e(number_format($liveElections)) ?> live right now
            </span>
        </a>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-xl-8">
        <section class="panel h-100">
            <div class="panel__header">
                <div>
                    <h2 class="panel__title">Ballot activity</h2>
                    <p class="panel__subtitle">Anonymous ballots recorded over the last seven days</p>
                </div>
                <span class="metric-chip"><i class="bi bi-activity" aria-hidden="true"></i><?= e(number_format($weekTotal)) ?> this week</span>
            </div>
            <div class="activity-chart" role="img" aria-label="Daily ballot activity for the last seven days">
                <?php foreach ($activitySeries as $day):
                    $height = $chartMax > 0 ? max(3, (int) round(($day['total'] / $chartMax) * 100)) : 3;
                ?>
                    <div class="activity-chart__column">
                        <span class="activity-chart__value"><?= e((string) $day['total']) ?></span>
                        <div class="activity-chart__track">
                            <span class="activity-chart__bar<?= $day['total'] === 0 ? ' activity-chart__bar--empty' : '' ?>"
                                  style="height: <?= e((string) $height) ?>%"
                                  title="<?= e(format_date($day['date'])) ?>: <?= e((string) $day['total']) ?> ballots"></span>
                        </div>
                        <span class="activity-chart__label"><?= e($day['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="panel__footnote">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                Ballots are counted from the anonymous votes table. No voter identity is attached to a choice.
            </div>
        </section>
    </div>

    <div class="col-xl-4">
        <section class="panel h-100 spotlight-panel">
            <div class="panel__header">
                <div>
                    <h2 class="panel__title">Election spotlight</h2>
                    <p class="panel__subtitle">The next election needing attention</p>
                </div>
            </div>
            <?php if ($liveElection):
                $liveMeta = Election::meta(Election::status($liveElection));
                $participationRate = min(100, ($eligibleForProgress > 0 ? ((int) $liveElection['participation_count'] / $eligibleForProgress) * 100 : 0));
            ?>
                <div class="spotlight">
                    <span class="status-pill status-pill--live">
                        <i class="bi <?= e($liveMeta['icon']) ?>" aria-hidden="true"></i> <?= e($liveMeta['label']) ?>
                    </span>
                    <h3><?= e($liveElection['title']) ?></h3>
                    <p class="spotlight__timing"><i class="bi bi-clock" aria-hidden="true"></i><?= e(Election::timingHint($liveElection)) ?></p>
                    <div class="spotlight__stats">
                        <div><strong><?= e((string) $liveElection['candidate_count']) ?></strong><span>Candidates</span></div>
                        <div><strong><?= e(number_format((int) $liveElection['participation_count'])) ?></strong><span>Ballots</span></div>
                    </div>
                    <div class="spotlight__progress">
                        <div class="d-flex justify-content-between">
                            <span>Participation</span>
                            <strong><?= e(number_format($participationRate, 1)) ?>%</strong>
                        </div>
                        <div class="progress app-progress" role="progressbar" aria-label="Voter participation" aria-valuenow="<?= e((string) round($participationRate)) ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar" style="width: <?= e((string) $participationRate) ?>%"></div>
                        </div>
                        <span class="spotlight__progress-note"><?= e(number_format((int) $liveElection['participation_count'])) ?> of <?= e(number_format($activeVoters)) ?> active voters</span>
                    </div>
                    <a class="btn btn-brand w-100" href="<?= e(url('admin/elections.php')) ?>">Manage election</a>
                </div>
            <?php elseif ($nextElection):
                $nextMeta = Election::meta(Election::status($nextElection));
            ?>
                <div class="spotlight spotlight--upcoming">
                    <span class="status-pill status-pill--upcoming">
                        <i class="bi <?= e($nextMeta['icon']) ?>" aria-hidden="true"></i> <?= e($nextMeta['label']) ?>
                    </span>
                    <h3><?= e($nextElection['title']) ?></h3>
                    <p class="spotlight__timing"><i class="bi bi-calendar-event" aria-hidden="true"></i>Opens <?= e(format_datetime($nextElection['start_at'])) ?></p>
                    <div class="spotlight__stats">
                        <div><strong><?= e((string) $nextElection['candidate_count']) ?></strong><span>Candidates</span></div>
                        <div><strong><?= e(time_until($nextElection['start_at'])) ?></strong><span>Until opening</span></div>
                    </div>
                    <p class="spotlight__note">Review the candidate list before voting opens.</p>
                    <a class="btn btn-brand w-100" href="<?= e(url('admin/candidates.php?election_id=' . (int) $nextElection['id'])) ?>">Review ballot</a>
                </div>
            <?php else: ?>
                <div class="empty-state empty-state--compact">
                    <span class="empty-state__icon"><i class="bi bi-calendar2-plus" aria-hidden="true"></i></span>
                    <h3>Nothing scheduled</h3>
                    <p>Create an election to start preparing the next ballot.</p>
                    <a class="btn btn-brand" href="<?= e(url('admin/elections.php?new=1')) ?>">Create election</a>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-xl-8">
        <section class="panel">
            <div class="panel__header">
                <div>
                    <h2 class="panel__title">Recent elections</h2>
                    <p class="panel__subtitle">Latest election activity and participation</p>
                </div>
                <a class="panel__link" href="<?= e(url('admin/elections.php')) ?>">View all <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <?php if ($recentElections): ?>
                <div class="table-responsive">
                    <table class="table app-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Election</th>
                                <th scope="col">Status</th>
                                <th scope="col">Window</th>
                                <th scope="col" class="text-end">Participation</th>
                                <th scope="col" class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentElections as $election):
                                $status = Election::status($election);
                                $meta = Election::meta($status);
                            ?>
                                <tr>
                                    <td>
                                        <div class="table-primary">
                                            <strong><?= e($election['title']) ?></strong>
                                            <span><?= e((string) $election['candidate_count']) ?> candidate<?= (int) $election['candidate_count'] === 1 ? '' : 's' ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-pill status-pill--<?= e($meta['class']) ?>">
                                            <i class="bi <?= e($meta['icon']) ?>" aria-hidden="true"></i><?= e($meta['label']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="table-date"><?= e(format_date($election['start_at'])) ?></span>
                                        <span class="table-subdate">to <?= e(format_date($election['end_at'])) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <strong class="table-number"><?= e(number_format((int) $election['participation_count'])) ?></strong>
                                        <span class="table-subdate">ballots</span>
                                    </td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-light" href="<?= e(url('admin/elections.php')) ?>" aria-label="Manage <?= e($election['title']) ?>">
                                            <i class="bi bi-sliders" aria-hidden="true"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state empty-state--compact">
                    <span class="empty-state__icon"><i class="bi bi-calendar2" aria-hidden="true"></i></span>
                    <h3>No elections yet</h3>
                    <p>Start by creating your first election and adding candidates.</p>
                    <a class="btn btn-brand" href="<?= e(url('admin/elections.php?new=1')) ?>">Create election</a>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-xl-4">
        <section class="panel">
            <div class="panel__header">
                <div>
                    <h2 class="panel__title">New voters</h2>
                    <p class="panel__subtitle">Latest registrations</p>
                </div>
                <a class="panel__link" href="<?= e(url('admin/voters.php')) ?>">View all</a>
            </div>
            <?php if ($recentVoters): ?>
                <div class="compact-list">
                    <?php foreach ($recentVoters as $newVoter): ?>
                        <div class="compact-list__item">
                            <?= avatar_markup((string) $newVoter['full_name'], null, 'avatar--sm') ?>
                            <div class="compact-list__meta">
                                <strong><?= e($newVoter['full_name']) ?></strong>
                                <span><?= e($newVoter['department']) ?> · <?= e(time_ago($newVoter['created_at'])) ?></span>
                            </div>
                            <span class="status-dot <?= (int) $newVoter['is_active'] === 1 ? 'status-dot--green' : 'status-dot--muted' ?>" title="<?= (int) $newVoter['is_active'] === 1 ? 'Active' : 'Inactive' ?>"></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state empty-state--tiny">
                    <p>No voter registrations yet.</p>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel mt-4">
            <div class="panel__header">
                <div>
                    <h2 class="panel__title">Administration activity</h2>
                    <p class="panel__subtitle">Recent audited actions</p>
                </div>
                <a class="panel__link" href="<?= e(url('admin/activity.php')) ?>">Full log</a>
            </div>
            <?php if ($recentActivity): ?>
                <div class="activity-list">
                    <?php foreach ($recentActivity as $activity):
                        $actionMeta = audit_action_meta((string) $activity['action']);
                    ?>
                        <div class="activity-list__item">
                            <span class="activity-list__icon activity-list__icon--<?= e($actionMeta['tone']) ?>">
                                <i class="bi <?= e($actionMeta['icon']) ?>" aria-hidden="true"></i>
                            </span>
                            <div class="activity-list__meta">
                                <strong><?= e($actionMeta['label']) ?></strong>
                                <span><?= e($activity['description']) ?></span>
                                <small><?= e(time_ago($activity['created_at'])) ?><?= $activity['admin_name'] ? ' · ' . e($activity['admin_name']) : '' ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state empty-state--tiny">
                    <p>No administrative activity recorded yet.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin_footer.php'; ?>
