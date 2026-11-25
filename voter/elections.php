<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

$voter = Auth::requireVoter();
$pdo = Database::connection();
$voterId = (int) $voter['id'];

$stmt = $pdo->prepare(
    "SELECT e.*,
            (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count,
            p.id AS participation_id,
            p.receipt_code,
            p.cast_at AS voted_at
     FROM elections e
     LEFT JOIN vote_participation p ON p.election_id = e.id AND p.voter_id = ?
     WHERE e.status <> 'draft'
     ORDER BY e.start_at DESC, e.id DESC"
);
$stmt->execute([$voterId]);
$allElections = $stmt->fetchAll();

$priority = [Election::LIVE => 0, Election::UPCOMING => 1, Election::ENDED => 2, Election::CLOSED => 3];
foreach ($allElections as &$election) {
    $election['effective_status'] = Election::status($election);
    $election['status_meta'] = Election::meta($election['effective_status']);
}
unset($election);
usort($allElections, static function (array $a, array $b) use ($priority): int {
    $aPriority = $priority[$a['effective_status']] ?? 9;
    $bPriority = $priority[$b['effective_status']] ?? 9;
    if ($aPriority !== $bPriority) {
        return $aPriority <=> $bPriority;
    }
    return strcmp((string) $b['end_at'], (string) $a['end_at']);
});

$counts = ['all' => count($allElections), 'live' => 0, 'upcoming' => 0, 'completed' => 0];
foreach ($allElections as $election) {
    if ($election['effective_status'] === Election::LIVE) {
        $counts['live']++;
    } elseif ($election['effective_status'] === Election::UPCOMING) {
        $counts['upcoming']++;
    } else {
        $counts['completed']++;
    }
}

$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'live', 'upcoming', 'completed'], true)) {
    $filter = 'all';
}

$elections = array_values(array_filter($allElections, static function (array $election) use ($filter): bool {
    if ($filter === 'all') {
        return true;
    }
    if ($filter === 'completed') {
        return in_array($election['effective_status'], [Election::ENDED, Election::CLOSED], true);
    }
    return $election['effective_status'] === $filter;
}));

$filterUrl = static function (string $target): string {
    return url('voter/elections.php' . ($target === 'all' ? '' : '?filter=' . $target));
};

$pageTitle = 'Elections';
$pageEyebrow = 'Voting';
$activeNav = 'elections';
require_once dirname(__DIR__) . '/includes/voter_header.php';
?>

<div class="voter-page-intro">
    <div>
        <p>Review the ballot, read each candidate’s profile and manifesto, and cast your one secure vote per election.</p>
        <div class="voter-page-intro__meta">
            <span><span class="status-dot status-dot--green status-dot--pulse"></span><?= e((string) $counts['live']) ?> live now</span>
            <span><i class="bi bi-calendar-event" aria-hidden="true"></i><?= e((string) $counts['upcoming']) ?> scheduled</span>
            <span><i class="bi bi-check2-circle" aria-hidden="true"></i>One ballot per election</span>
        </div>
    </div>
    <a class="btn btn-light" href="<?= e(url('voter/results.php')) ?>"><i class="bi bi-bar-chart-line me-2" aria-hidden="true"></i>Published results</a>
</div>

<div class="filter-tabs filter-tabs--voter" role="tablist" aria-label="Filter elections">
    <?php
    $tabs = ['all' => 'All elections', 'live' => 'Live now', 'upcoming' => 'Opening soon', 'completed' => 'Completed'];
    foreach ($tabs as $key => $label): ?>
        <a class="filter-tab<?= $filter === $key ? ' active' : '' ?>" href="<?= e($filterUrl($key)) ?>"
           <?= $filter === $key ? 'aria-current="page"' : '' ?>>
            <?= e($label) ?><span><?= e((string) $counts[$key]) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($elections): ?>
    <div class="voter-election-grid">
        <?php foreach ($elections as $election):
            $status = $election['effective_status'];
            $meta = $election['status_meta'];
            $hasVoted = !empty($election['participation_id']);
            $isLive = $status === Election::LIVE;
            $isUpcoming = $status === Election::UPCOMING;
            $isCompleted = in_array($status, [Election::ENDED, Election::CLOSED], true);
            $candidateCount = (int) $election['candidate_count'];
        ?>
            <article class="election-listing<?= $isLive ? ' election-listing--live' : '' ?>">
                <div class="election-listing__header">
                    <span class="status-pill status-pill--<?= e($meta['class']) ?>">
                        <i class="bi <?= e($meta['icon']) ?>" aria-hidden="true"></i><?= e($meta['label']) ?>
                    </span>
                    <span class="election-listing__timing"><?= e(Election::timingHint($election)) ?></span>
                </div>
                <div class="election-listing__body">
                    <h2><?= e($election['title']) ?></h2>
                    <p><?= e(truncate_text($election['description'], 220)) ?></p>
                    <div class="election-listing__meta">
                        <span><i class="bi bi-people" aria-hidden="true"></i><?= e((string) $candidateCount) ?> candidate<?= $candidateCount === 1 ? '' : 's' ?></span>
                        <span><i class="bi bi-calendar3" aria-hidden="true"></i><?= e(format_datetime($election['start_at'], 'M j · g:i A')) ?> – <?= e(format_datetime($election['end_at'], 'M j · g:i A')) ?></span>
                    </div>
                </div>
                <div class="election-listing__footer">
                    <div class="election-listing__status">
                        <?php if ($hasVoted): ?>
                            <span class="vote-confirmed"><i class="bi bi-check2-circle" aria-hidden="true"></i>Vote recorded</span>
                            <span class="election-listing__receipt">Receipt <?= e($election['receipt_code']) ?></span>
                        <?php elseif ($isLive): ?>
                            <span class="election-listing__not-voted"><i class="bi bi-circle" aria-hidden="true"></i>You have not voted yet</span>
                        <?php elseif ($isUpcoming): ?>
                            <span class="election-listing__not-voted"><i class="bi bi-clock" aria-hidden="true"></i>Voting has not opened</span>
                        <?php else: ?>
                            <span class="election-listing__not-voted"><i class="bi bi-dash-circle" aria-hidden="true"></i>You did not vote</span>
                        <?php endif; ?>
                    </div>
                    <div class="election-listing__actions">
                        <?php if ($hasVoted): ?>
                            <a class="btn btn-sm btn-light" href="<?= e(url('voter/receipt.php?id=' . (int) $election['participation_id'])) ?>">View receipt</a>
                        <?php endif; ?>
                        <?php if ($isLive && !$hasVoted): ?>
                            <a class="btn btn-brand" href="<?= e(url('voter/vote.php?election_id=' . (int) $election['id'])) ?>">
                                <?= $candidateCount > 0 ? 'Open ballot' : 'View election' ?> <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                            </a>
                        <?php elseif ($candidateCount > 0): ?>
                            <a class="btn btn-sm btn-light" href="<?= e(url('voter/vote.php?election_id=' . (int) $election['id'])) ?>">View candidates</a>
                        <?php endif; ?>
                        <?php if ($isCompleted && (int) $election['results_published'] === 1): ?>
                            <a class="btn btn-sm btn-outline-brand" href="<?= e(url('voter/results.php?election_id=' . (int) $election['id'])) ?>">View results</a>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="panel">
        <div class="empty-state">
            <span class="empty-state__icon"><i class="bi bi-calendar2-x" aria-hidden="true"></i></span>
            <h3>No elections in this view</h3>
            <p><?= $filter === 'all' ? 'The election office has not published any elections yet.' : 'There are no elections in this category right now.' ?></p>
            <?php if ($filter !== 'all'): ?>
                <a class="btn btn-light" href="<?= e($filterUrl('all')) ?>">View all elections</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="info-callout info-callout--voter mt-4">
    <i class="bi bi-shield-check" aria-hidden="true"></i>
    <div>
        <strong>How voting works here</strong>
        <p>Voting opens and closes at fixed times checked by the server. Once you submit a ballot it cannot be changed. You can view candidate profiles even when the ballot is not open, but the vote form only appears while the election is live and your account has not yet voted.</p>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/voter_footer.php'; ?>
