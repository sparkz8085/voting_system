<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

$admin = Auth::requireAdmin();
$pdo = Database::connection();

$elections = $pdo->query('SELECT * FROM elections ORDER BY end_at DESC, id DESC')->fetchAll();

if (!$elections) {
    $pageTitle = 'Results';
    $pageEyebrow = 'Voting statistics';
    $activeNav = 'results';
    require_once dirname(__DIR__) . '/includes/admin_header.php';
    ?>
    <div class="panel">
        <div class="empty-state">
            <span class="empty-state__icon"><i class="bi bi-bar-chart-line" aria-hidden="true"></i></span>
            <h3>No results to review</h3>
            <p>Results appear here once an election has been created and the first ballot is cast.</p>
            <a class="btn btn-brand" href="<?= e(url('admin/elections.php?new=1')) ?>">Create election</a>
        </div>
    </div>
    <?php require_once dirname(__DIR__) . '/includes/admin_footer.php'; ?>
    <?php exit; ?>
<?php
}

$electionIds = array_map(static fn (array $row): int => (int) $row['id'], $elections);
$selectedId = positive_int($_POST['election_id'] ?? $_GET['election_id'] ?? 0);
if (!in_array($selectedId, $electionIds, true)) {
    // Prefer the most recent completed election, otherwise the newest election.
    $selectedId = (int) $elections[0]['id'];
    foreach ($elections as $candidateElection) {
        if (in_array(Election::status($candidateElection), [Election::ENDED, Election::CLOSED], true)) {
            $selectedId = (int) $candidateElection['id'];
            break;
        }
    }
}

$stmt = $pdo->prepare(
    "SELECT e.*,
            (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count,
            (SELECT COUNT(*) FROM votes v WHERE v.election_id = e.id) AS vote_count,
            (SELECT COUNT(*) FROM vote_participation p WHERE p.election_id = e.id) AS participation_count
     FROM elections e WHERE e.id = ? LIMIT 1"
);
$stmt->execute([$selectedId]);
$election = $stmt->fetch();
if (!$election) {
    flash_redirect('admin/results.php', 'danger', 'That election could not be found.');
}

if (is_post()) {
    csrf_abort_if_invalid();
    $action = post_string('action');
    $postElectionId = positive_int($_POST['election_id'] ?? 0);
    if ($postElectionId !== (int) $election['id']) {
        flash_redirect('admin/results.php', 'danger', 'The selected election changed. Try again.');
    }

    if ($action === 'publish') {
        if ((int) $election['results_published'] === 1) {
            flash_redirect('admin/results.php?election_id=' . $selectedId, 'warning', 'These results are already published.');
        }
        if (!Election::canPublishResults($election)) {
            flash_redirect('admin/results.php?election_id=' . $selectedId, 'danger', 'Results can only be published after voting has ended.');
        }
        if ((int) $election['vote_count'] !== (int) $election['participation_count']) {
            flash_redirect(
                'admin/results.php?election_id=' . $selectedId,
                'danger',
                'Integrity check failed: anonymous ballots and participation records do not match. Results were not published.'
            );
        }
        if ((int) $election['candidate_count'] < 1) {
            flash_redirect('admin/results.php?election_id=' . $selectedId, 'danger', 'This election has no candidates to certify.');
        }
        $pdo->prepare('UPDATE elections SET results_published = 1 WHERE id = ?')->execute([$selectedId]);
        audit_log((int) $admin['id'], 'election.published', 'Published results for election #' . $selectedId . ': ' . $election['title']);
        flash('success', 'Results published. They are now visible to voters.');
        redirect('admin/results.php?election_id=' . $selectedId, 303);
    }

    if ($action === 'unpublish') {
        if ((int) $election['results_published'] !== 1) {
            flash_redirect('admin/results.php?election_id=' . $selectedId, 'warning', 'These results are not published.');
        }
        $pdo->prepare('UPDATE elections SET results_published = 0 WHERE id = ?')->execute([$selectedId]);
        audit_log((int) $admin['id'], 'election.unpublished', 'Unpublished results for election #' . $selectedId . ': ' . $election['title']);
        flash('success', 'Results hidden from voters.');
        redirect('admin/results.php?election_id=' . $selectedId, 303);
    }

    flash_redirect('admin/results.php?election_id=' . $selectedId, 'danger', 'Unknown results action.');
}

$effectiveStatus = Election::status($election);
$meta = Election::meta($effectiveStatus);
$published = (int) $election['results_published'] === 1;
$isFinal = Election::hasEnded($election);
$summary = ResultService::summary($election);
$winnerIds = array_map(static fn (array $candidate): int => (int) $candidate['id'], $summary['winners']);
$canPublish = Election::canPublishResults($election);
$leadingLabel = ($published || $isFinal) ? 'Winner' : 'Leading';

$pageTitle = 'Results';
$pageEyebrow = 'Voting statistics';
$activeNav = 'results';
require_once dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="page-toolbar results-toolbar">
    <div>
        <p class="page-toolbar__lead">Review counts, verify ballot integrity, and decide when an outcome becomes public.</p>
        <div class="page-toolbar__meta">
            <span class="status-pill status-pill--<?= e($meta['class']) ?>"><i class="bi <?= e($meta['icon']) ?>" aria-hidden="true"></i><?= e($meta['label']) ?></span>
            <span class="results-visibility <?= $published ? 'results-visibility--public' : '' ?>">
                <i class="bi <?= $published ? 'bi-globe2' : 'bi-eye-slash' ?>" aria-hidden="true"></i>
                <?= $published ? 'Visible to voters' : 'Hidden from voters' ?>
            </span>
        </div>
    </div>
    <div class="page-toolbar__actions">
        <form method="get" action="<?= e(url('admin/results.php')) ?>" class="election-picker">
            <label for="resultsElection" class="visually-hidden">Choose election</label>
            <select class="form-select" id="resultsElection" name="election_id" data-election-switch>
                <?php foreach ($elections as $option):
                    $optionMeta = Election::meta(Election::status($option));
                ?>
                    <option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === $selectedId ? 'selected' : '' ?>>
                        <?= e($option['title']) ?> · <?= e($optionMeta['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-light">View</button>
        </form>
        <button type="button" class="btn btn-light d-none d-sm-inline-flex" data-print>
            <i class="bi bi-printer me-2" aria-hidden="true"></i>Print
        </button>
    </div>
</div>

<div class="results-header">
    <div class="results-header__copy">
        <span class="results-header__eyebrow">Result sheet · Election #<?= e((string) $election['id']) ?></span>
        <h2><?= e($election['title']) ?></h2>
        <p><?= e($election['description'] ?: 'No description was provided for this election.') ?></p>
        <div class="results-header__dates">
            <span><i class="bi bi-calendar3" aria-hidden="true"></i><?= e(format_datetime($election['start_at'])) ?></span>
            <span><i class="bi bi-flag" aria-hidden="true"></i>Closed <?= e(format_datetime($election['end_at'])) ?></span>
        </div>
    </div>
    <div class="results-header__actions">
        <?php if ($canPublish): ?>
            <form method="post" action="<?= e(url('admin/results.php?election_id=' . $selectedId)) ?>"
                  data-confirm="Publish these results to all voters? Make sure the count and integrity check have been reviewed."
                  data-confirm-title="Publish results" data-confirm-button="Publish results" data-confirm-variant="primary">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="publish">
                <input type="hidden" name="election_id" value="<?= $selectedId ?>">
                <button type="submit" class="btn btn-brand"><i class="bi bi-award me-2" aria-hidden="true"></i>Publish results</button>
            </form>
        <?php elseif ($published): ?>
            <form method="post" action="<?= e(url('admin/results.php?election_id=' . $selectedId)) ?>"
                  data-confirm="Hide these published results from voters until you publish them again?"
                  data-confirm-title="Unpublish results" data-confirm-button="Unpublish" data-confirm-variant="warning">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="unpublish">
                <input type="hidden" name="election_id" value="<?= $selectedId ?>">
                <button type="submit" class="btn btn-light"><i class="bi bi-eye-slash me-2" aria-hidden="true"></i>Unpublish</button>
            </form>
        <?php elseif ($effectiveStatus === Election::LIVE): ?>
            <span class="results-live-badge"><span class="status-dot status-dot--green status-dot--pulse"></span>Live vote count</span>
        <?php else: ?>
            <a class="btn btn-light" href="<?= e(url('admin/elections.php?edit=' . $selectedId)) ?>"><i class="bi bi-sliders me-2" aria-hidden="true"></i>Manage election</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($effectiveStatus === Election::LIVE): ?>
    <div class="info-callout info-callout--warning mt-4">
        <i class="bi bi-broadcast" aria-hidden="true"></i>
        <div>
            <strong>Provisional count in progress</strong>
            <p>These numbers reflect ballots committed so far. This view is restricted to administrators; voters cannot see any tallies until results are published after the election ends.</p>
        </div>
    </div>
<?php elseif ($published): ?>
    <div class="info-callout info-callout--success mt-4">
        <i class="bi bi-globe2" aria-hidden="true"></i>
        <div>
            <strong>Published to voters</strong>
            <p>This result sheet is visible on the voter results page. Unpublishing hides it immediately without changing the underlying counts.</p>
        </div>
    </div>
<?php elseif ($isFinal): ?>
    <div class="info-callout mt-4">
        <i class="bi bi-hourglass-split" aria-hidden="true"></i>
        <div>
            <strong>Awaiting publication</strong>
            <p>The voting window has ended. Check the integrity panel, then publish when the election office has certified the outcome.</p>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4 mt-0 result-metrics">
    <div class="col-sm-6 col-xl-3">
        <div class="result-metric">
            <span class="result-metric__icon result-metric__icon--indigo"><i class="bi bi-check2-square" aria-hidden="true"></i></span>
            <span class="result-metric__value"><?= e(number_format($summary['total_ballots'])) ?></span>
            <span class="result-metric__label">Ballots counted</span>
            <span class="result-metric__meta">Anonymous ballot records</span>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="result-metric">
            <span class="result-metric__icon result-metric__icon--teal"><i class="bi bi-person-check" aria-hidden="true"></i></span>
            <span class="result-metric__value"><?= e(number_format($summary['total_participants'])) ?></span>
            <span class="result-metric__label">Voters participated</span>
            <span class="result-metric__meta">Participation ledger entries</span>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="result-metric">
            <span class="result-metric__icon result-metric__icon--amber"><i class="bi bi-people" aria-hidden="true"></i></span>
            <span class="result-metric__value"><?= e(number_format($summary['eligible_voters'])) ?></span>
            <span class="result-metric__label">Active voter accounts</span>
            <span class="result-metric__meta">Turnout denominator</span>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="result-metric">
            <span class="result-metric__icon result-metric__icon--violet"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i></span>
            <span class="result-metric__value"><?= e(number_format($summary['turnout'], 1)) ?>%</span>
            <span class="result-metric__label">Turnout</span>
            <span class="result-metric__meta">Of currently active voters</span>
        </div>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-xl-8">
        <section class="panel panel--results">
            <div class="panel__header">
                <div>
                    <h2 class="panel__title">Candidate tally</h2>
                    <p class="panel__subtitle"><?= e((string) $election['candidate_count']) ?> candidates · <?= e(number_format($summary['total_ballots'])) ?> valid ballots</p>
                </div>
                <span class="metric-chip"><i class="bi bi-shield-check" aria-hidden="true"></i><?= $summary['integrity_balanced'] ? 'Counts balanced' : 'Review required' ?></span>
            </div>

            <?php if ($summary['candidates']): ?>
                <div class="tally-list">
                    <?php foreach ($summary['candidates'] as $rank => $candidate):
                        $isWinner = in_array((int) $candidate['id'], $winnerIds, true) && $summary['max_votes'] > 0;
                    ?>
                        <div class="tally-row<?= $isWinner ? ' tally-row--winner' : '' ?>">
                            <span class="tally-rank"><?= e((string) ($rank + 1)) ?></span>
                            <?= avatar_markup((string) $candidate['full_name'], $candidate['photo_url'] ?? null, 'avatar--md') ?>
                            <div class="tally-main">
                                <div class="tally-main__top">
                                    <div>
                                        <strong><?= e($candidate['full_name']) ?></strong>
                                        <span><?= e($candidate['party'] ?: 'Independent') ?></span>
                                    </div>
                                    <?php if ($isWinner): ?>
                                        <span class="winner-chip"><i class="bi bi-award-fill" aria-hidden="true"></i><?= e($leadingLabel) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="progress result-bar" role="progressbar"
                                     aria-label="<?= e($candidate['full_name']) ?> share"
                                     aria-valuenow="<?= e((string) round($candidate['percentage'])) ?>" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar<?= $isWinner ? ' progress-bar--winner' : '' ?>" style="width: <?= e((string) max(0, min(100, (float) $candidate['percentage']))) ?>%"></div>
                                </div>
                            </div>
                            <div class="tally-values">
                                <strong><?= e(number_format($candidate['vote_count'])) ?></strong>
                                <span><?= e(number_format((float) $candidate['percentage'], 1)) ?>%</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="panel__footnote">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    Percentages use total valid ballots as the denominator. Ties are shown explicitly and are not broken by the software.
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="empty-state__icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                    <h3>No candidates on this ballot</h3>
                    <p>Add candidates before the election opens. Results cannot be meaningful without a ballot.</p>
                    <?php if (Election::canManageCandidates($election)): ?>
                        <a class="btn btn-brand" href="<?= e(url('admin/candidates.php?election_id=' . $selectedId)) ?>">Add candidates</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-xl-4">
        <?php if ($summary['winners'] && $summary['max_votes'] > 0): ?>
            <section class="winner-panel">
                <span class="winner-panel__eyebrow"><?= e($leadingLabel) ?><?= $summary['has_tie'] ? 's' : '' ?></span>
                <?php foreach ($summary['winners'] as $winner): ?>
                    <div class="winner-panel__person">
                        <?= avatar_markup((string) $winner['full_name'], $winner['photo_url'] ?? null, 'avatar--lg') ?>
                        <div>
                            <h3><?= e($winner['full_name']) ?></h3>
                            <span><?= e($winner['party'] ?: 'Independent') ?></span>
                            <strong><?= e(number_format($winner['vote_count'])) ?> votes · <?= e(number_format((float) $winner['percentage'], 1)) ?>%</strong>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($summary['has_tie']): ?>
                    <div class="winner-panel__tie"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>This result is tied. The software does not break ties.</div>
                <?php endif; ?>
                <span class="winner-panel__status">
                    <i class="bi <?= $published ? 'bi-globe2' : 'bi-clock' ?>" aria-hidden="true"></i>
                    <?= $published ? 'Published result' : ($isFinal ? 'Awaiting publication' : 'Provisional while voting is open') ?>
                </span>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="panel__header">
                <div>
                    <h2 class="panel__title">Integrity check</h2>
                    <p class="panel__subtitle">Database-level reconciliation</p>
                </div>
            </div>
            <div class="integrity-check <?= $summary['integrity_balanced'] ? 'integrity-check--ok' : 'integrity-check--fail' ?>">
                <span class="integrity-check__icon">
                    <i class="bi <?= $summary['integrity_balanced'] ? 'bi-patch-check-fill' : 'bi-exclamation-octagon-fill' ?>" aria-hidden="true"></i>
                </span>
                <div>
                    <strong><?= $summary['integrity_balanced'] ? 'Records reconcile' : 'Records do not reconcile' ?></strong>
                    <p><?= e(number_format($summary['total_ballots'])) ?> anonymous ballots · <?= e(number_format($summary['total_participants'])) ?> participation records</p>
                </div>
            </div>
            <dl class="detail-list">
                <div><dt>Election status</dt><dd><span class="status-pill status-pill--<?= e($meta['class']) ?>"><i class="bi <?= e($meta['icon']) ?>" aria-hidden="true"></i><?= e($meta['label']) ?></span></dd></div>
                <div><dt>Candidates</dt><dd><?= e((string) $election['candidate_count']) ?></dd></div>
                <div><dt>Votes cast</dt><dd><?= e(number_format((int) $election['vote_count'])) ?></dd></div>
                <div><dt>Active voters</dt><dd><?= e(number_format($summary['eligible_voters'])) ?></dd></div>
            </dl>
            <p class="detail-list__note">Participation is compared against anonymous ballots, never against how any individual voted.</p>
        </section>

        <section class="panel">
            <div class="panel__header">
                <div>
                    <h2 class="panel__title">Publication</h2>
                    <p class="panel__subtitle">Control voter-facing results</p>
                </div>
            </div>
            <div class="publication-state">
                <span class="publication-state__dot <?= $published ? 'is-public' : 'is-private' ?>"></span>
                <div>
                    <strong><?= $published ? 'Visible to voters' : 'Private to administrators' ?></strong>
                    <p><?= $published ? 'The result sheet appears in the voter results section.' : 'No result totals are shown to voters for this election.' ?></p>
                </div>
            </div>
            <?php if ($published): ?>
                <form method="post" action="<?= e(url('admin/results.php?election_id=' . $selectedId)) ?>"
                      data-confirm="Hide these results from voters?"
                      data-confirm-title="Unpublish results" data-confirm-button="Unpublish" data-confirm-variant="warning">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="unpublish">
                    <input type="hidden" name="election_id" value="<?= $selectedId ?>">
                    <button class="btn btn-light w-100" type="submit"><i class="bi bi-eye-slash me-2" aria-hidden="true"></i>Unpublish results</button>
                </form>
            <?php elseif ($canPublish): ?>
                <form method="post" action="<?= e(url('admin/results.php?election_id=' . $selectedId)) ?>"
                      data-confirm="Publish these results to all voters? Review the tally and integrity check first."
                      data-confirm-title="Publish results" data-confirm-button="Publish results" data-confirm-variant="primary">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="publish">
                    <input type="hidden" name="election_id" value="<?= $selectedId ?>">
                    <button class="btn btn-brand w-100" type="submit"><i class="bi bi-award me-2" aria-hidden="true"></i>Publish results</button>
                </form>
            <?php else: ?>
                <p class="publication-hint"><i class="bi bi-lock" aria-hidden="true"></i><?= $isFinal ? 'Publishing is available after the ballot is certified.' : 'Results become publishable after voting ends.' ?></p>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin_footer.php'; ?>
