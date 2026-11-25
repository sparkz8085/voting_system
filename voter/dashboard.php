<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

$voter = Auth::requireVoter();
$pdo = Database::connection();
$voterId = (int) $voter['id'];

$activeStmt = $pdo->prepare(
    "SELECT e.*,
            (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count,
            (SELECT p.id FROM vote_participation p WHERE p.election_id = e.id AND p.voter_id = ? LIMIT 1) AS participation_id
     FROM elections e
     WHERE e.status = 'active' AND e.end_at > NOW()
     ORDER BY CASE WHEN NOW() BETWEEN e.start_at AND e.end_at THEN 0 ELSE 1 END, e.end_at ASC"
);
$activeStmt->execute([$voterId]);
$activeElections = $activeStmt->fetchAll();

$myVoteStmt = $pdo->prepare('SELECT COUNT(*) FROM vote_participation WHERE voter_id = ?');
$myVoteStmt->execute([$voterId]);
$myVoteCount = (int) $myVoteStmt->fetchColumn();

$pendingCount = 0;
$upcomingCount = 0;
$liveCount = 0;
foreach ($activeElections as $activeElection) {
    $status = Election::status($activeElection);
    if ($status === Election::LIVE) {
        $liveCount++;
        if (empty($activeElection['participation_id'])) {
            $pendingCount++;
        }
    } elseif ($status === Election::UPCOMING) {
        $upcomingCount++;
    }
}

$publishedCount = (int) $pdo->query('SELECT COUNT(*) FROM elections WHERE results_published = 1')->fetchColumn();
$latestPublished = $pdo->query(
    'SELECT * FROM elections WHERE results_published = 1 ORDER BY end_at DESC LIMIT 1'
)->fetch() ?: null;

$receiptsStmt = $pdo->prepare(
    'SELECT p.*, e.title AS election_title, e.results_published, e.end_at
     FROM vote_participation p
     JOIN elections e ON e.id = p.election_id
     WHERE p.voter_id = ?
     ORDER BY p.cast_at DESC, p.id DESC
     LIMIT 4'
);
$receiptsStmt->execute([$voterId]);
$receipts = $receiptsStmt->fetchAll();

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', (string) $voter['full_name'])[0];

$pageTitle = 'Dashboard';
$pageEyebrow = 'Voter portal';
$activeNav = 'dashboard';
require_once dirname(__DIR__) . '/includes/voter_header.php';
?>

<section class="voter-welcome">
    <div class="voter-welcome__copy">
        <span class="voter-welcome__date"><i class="bi bi-calendar3" aria-hidden="true"></i><?= e(date('l, F j, Y')) ?></span>
        <h2><?= e($greeting) ?>, <?= e($firstName) ?>.</h2>
        <?php if ($pendingCount > 0): ?>
            <p>You have <strong><?= e((string) $pendingCount) ?> open ballot<?= $pendingCount === 1 ? '' : 's' ?></strong> waiting. Review the candidates and have your say.</p>
        <?php elseif ($liveCount > 0): ?>
            <p>You have voted in every open election. Your participation receipts are saved in your account.</p>
        <?php else: ?>
            <p>No ballot is open right now. Your account is verified and ready for the next campus vote.</p>
        <?php endif; ?>
        <div class="voter-welcome__actions">
            <a class="btn btn-brand" href="<?= e(url('voter/elections.php')) ?>">
                <i class="bi bi-ui-checks me-2" aria-hidden="true"></i>View elections
            </a>
            <a class="btn btn-light" href="<?= e(url('voter/results.php')) ?>">
                <i class="bi bi-bar-chart-line me-2" aria-hidden="true"></i>Published results
            </a>
        </div>
    </div>
    <div class="voter-welcome__identity">
        <?= avatar_markup((string) $voter['full_name'], null, 'avatar--lg') ?>
        <div>
            <span class="verified-chip"><i class="bi bi-patch-check-fill" aria-hidden="true"></i>Verified voter</span>
            <strong><?= e($voter['student_id']) ?></strong>
            <span><?= e($voter['department']) ?></span>
        </div>
    </div>
</section>

<div class="row g-4 dashboard-stats">
    <div class="col-sm-6 col-xl-3">
        <a class="stat-card stat-card--voter-teal" href="<?= e(url('voter/elections.php')) ?>">
            <div class="stat-card__top">
                <span class="stat-card__icon"><i class="bi bi-ui-checks" aria-hidden="true"></i></span>
                <span class="stat-card__arrow"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></span>
            </div>
            <span class="stat-card__value"><?= e((string) $pendingCount) ?></span>
            <span class="stat-card__label">Ballots awaiting you</span>
            <span class="stat-card__meta"><i class="bi bi-broadcast" aria-hidden="true"></i><?= e((string) $liveCount) ?> live election<?= $liveCount === 1 ? '' : 's' ?></span>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card stat-card--voter-green">
            <div class="stat-card__top">
                <span class="stat-card__icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
            </div>
            <span class="stat-card__value"><?= e((string) $myVoteCount) ?></span>
            <span class="stat-card__label">Your recorded votes</span>
            <span class="stat-card__meta"><i class="bi bi-receipt" aria-hidden="true"></i>Each with a receipt</span>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a class="stat-card stat-card--voter-blue" href="<?= e(url('voter/elections.php')) ?>">
            <div class="stat-card__top">
                <span class="stat-card__icon"><i class="bi bi-calendar-event" aria-hidden="true"></i></span>
                <span class="stat-card__arrow"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></span>
            </div>
            <span class="stat-card__value"><?= e((string) $upcomingCount) ?></span>
            <span class="stat-card__label">Scheduled elections</span>
            <span class="stat-card__meta"><i class="bi bi-clock" aria-hidden="true"></i>Opens on a fixed schedule</span>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a class="stat-card stat-card--voter-amber" href="<?= e(url('voter/results.php')) ?>">
            <div class="stat-card__top">
                <span class="stat-card__icon"><i class="bi bi-trophy" aria-hidden="true"></i></span>
                <span class="stat-card__arrow"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></span>
            </div>
            <span class="stat-card__value"><?= e((string) $publishedCount) ?></span>
            <span class="stat-card__label">Published results</span>
            <span class="stat-card__meta"><i class="bi bi-globe2" aria-hidden="true"></i>Certified and public</span>
        </a>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-xl-8">
        <section class="voter-section">
            <div class="voter-section__header">
                <div>
                    <h2>Your active elections</h2>
                    <p>Ballots that are open or opening soon</p>
                </div>
                <a class="section-link" href="<?= e(url('voter/elections.php')) ?>">All elections <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>

            <?php if ($activeElections): ?>
                <div class="voter-election-list">
                    <?php foreach ($activeElections as $election):
                        $status = Election::status($election);
                        $meta = Election::meta($status);
                        $hasVoted = !empty($election['participation_id']);
                    ?>
                        <article class="voter-election-card<?= $hasVoted ? ' voter-election-card--voted' : '' ?>">
                            <div class="voter-election-card__status">
                                <span class="status-pill status-pill--<?= e($meta['class']) ?>">
                                    <i class="bi <?= e($meta['icon']) ?>" aria-hidden="true"></i><?= e($meta['label']) ?>
                                </span>
                                <span class="voter-election-card__timing"><?= e(Election::timingHint($election)) ?></span>
                            </div>
                            <div class="voter-election-card__body">
                                <h3><?= e($election['title']) ?></h3>
                                <p><?= e(truncate_text($election['description'], 160)) ?></p>
                                <div class="voter-election-card__meta">
                                    <span><i class="bi bi-people" aria-hidden="true"></i><?= e((string) $election['candidate_count']) ?> candidate<?= (int) $election['candidate_count'] === 1 ? '' : 's' ?></span>
                                    <span><i class="bi bi-calendar3" aria-hidden="true"></i><?= e(format_date($election['start_at'])) ?> – <?= e(format_date($election['end_at'])) ?></span>
                                </div>
                            </div>
                            <div class="voter-election-card__action">
                                <?php if ($hasVoted): ?>
                                    <span class="vote-confirmed"><i class="bi bi-check2-circle" aria-hidden="true"></i>Vote recorded</span>
                                    <a class="btn btn-sm btn-light" href="<?= e(url('voter/receipt.php?id=' . (int) $election['participation_id'])) ?>">Receipt</a>
                                <?php elseif ($status === Election::LIVE): ?>
                                    <a class="btn btn-brand" href="<?= e(url('voter/vote.php?election_id=' . (int) $election['id'])) ?>">
                                        Cast your vote <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-light" type="button" disabled>
                                        <i class="bi bi-clock me-2" aria-hidden="true"></i>Opens <?= e(format_date($election['start_at'])) ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="empty-state__icon"><i class="bi bi-calendar2-x" aria-hidden="true"></i></span>
                    <h3>No elections open right now</h3>
                    <p>When the election office opens a ballot, it will appear here with its candidate list and closing time.</p>
                    <a class="btn btn-light" href="<?= e(url('voter/results.php')) ?>">Browse past results</a>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-xl-4">
        <section class="voter-side-card">
            <div class="voter-side-card__header">
                <h2>Participation receipts</h2>
                <a href="<?= e(url('voter/elections.php')) ?>">View elections</a>
            </div>
            <?php if ($receipts): ?>
                <div class="receipt-list">
                    <?php foreach ($receipts as $receipt): ?>
                        <a class="receipt-list__item" href="<?= e(url('voter/receipt.php?id=' . (int) $receipt['id'])) ?>">
                            <span class="receipt-list__icon"><i class="bi bi-receipt" aria-hidden="true"></i></span>
                            <span class="receipt-list__meta">
                                <strong><?= e($receipt['election_title']) ?></strong>
                                <span><?= e(format_date($receipt['cast_at'])) ?> · <?= e($receipt['receipt_code']) ?></span>
                            </span>
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
                <p class="voter-side-card__note"><i class="bi bi-info-circle" aria-hidden="true"></i>A receipt confirms participation, not how you voted.</p>
            <?php else: ?>
                <div class="empty-state empty-state--tiny">
                    <p>No participation receipts yet. Your first one is issued after you vote.</p>
                </div>
            <?php endif; ?>
        </section>

        <section class="voter-side-card voter-side-card--results">
            <div class="voter-side-card__header">
                <h2>Results corner</h2>
                <a href="<?= e(url('voter/results.php')) ?>">All results</a>
            </div>
            <?php if ($latestPublished): ?>
                <span class="results-corner__eyebrow"><i class="bi bi-award" aria-hidden="true"></i>Latest published result</span>
                <h3><?= e($latestPublished['title']) ?></h3>
                <p>Certified on <?= e(format_date($latestPublished['end_at'])) ?>. Candidate totals and turnout are available.</p>
                <a class="btn btn-outline-brand w-100" href="<?= e(url('voter/results.php?election_id=' . (int) $latestPublished['id'])) ?>">
                    View result <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                </a>
            <?php else: ?>
                <div class="results-corner__empty">
                    <span><i class="bi bi-hourglass-split" aria-hidden="true"></i></span>
                    <p>No results have been published yet. Check back after an election is certified.</p>
                </div>
            <?php endif; ?>
        </section>

        <div class="voter-help-note">
            <i class="bi bi-shield-check" aria-hidden="true"></i>
            <div>
                <strong>Your ballot stays private</strong>
                <p>Your account receives a participation receipt, while the ballot records only the chosen candidate. No voter ID is stored with a choice.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/voter_footer.php'; ?>
