<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

$voter = Auth::requireVoter();
$pdo = Database::connection();

$publishedElections = ResultService::publishedElections();

if (!$publishedElections) {
    $pageTitle = 'Results';
    $pageEyebrow = 'Certified outcomes';
    $activeNav = 'results';
    require_once dirname(__DIR__) . '/includes/voter_header.php';
    ?>
    <div class="voter-page-intro">
        <div>
            <p>Certified election outcomes are published here after the election office closes voting and verifies the count.</p>
        </div>
    </div>
    <div class="panel">
        <div class="empty-state">
            <span class="empty-state__icon"><i class="bi bi-bar-chart-line" aria-hidden="true"></i></span>
            <h3>No results published yet</h3>
            <p>When an election has ended and its results are certified, the full tally and turnout will appear here. Results are never shown while voting is still open.</p>
            <a class="btn btn-light" href="<?= e(url('voter/elections.php')) ?>">Browse elections</a>
        </div>
    </div>
    <?php require_once dirname(__DIR__) . '/includes/voter_footer.php'; ?>
    <?php exit;
}

$publishedIds = array_map(static fn (array $row): int => (int) $row['id'], $publishedElections);
$selectedId = positive_int($_GET['election_id'] ?? 0);
if (!in_array($selectedId, $publishedIds, true)) {
    $selectedId = (int) $publishedElections[0]['id'];
}

$election = null;
foreach ($publishedElections as $publishedElection) {
    if ((int) $publishedElection['id'] === $selectedId) {
        $election = $publishedElection;
        break;
    }
}
if (!$election) {
    abort_request(404, 'Results not available', 'That election result has not been published.');
}

$meta = Election::meta(Election::status($election));
$summary = ResultService::summary($election);
$winnerIds = array_map(static fn (array $candidate): int => (int) $candidate['id'], $summary['winners']);
$participationStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM vote_participation WHERE voter_id = ? AND election_id = ?'
);
$participationStmt->execute([(int) $voter['id'], $selectedId]);
$voterParticipated = (int) $participationStmt->fetchColumn() > 0;

$pageTitle = 'Results';
$pageEyebrow = 'Certified outcomes';
$activeNav = 'results';
require_once dirname(__DIR__) . '/includes/voter_header.php';
?>

<div class="voter-page-intro">
    <div>
        <p>Official tallies published by the election office after voting closed. Counts are calculated directly from anonymous ballots.</p>
        <div class="voter-page-intro__meta">
            <span><i class="bi bi-globe2" aria-hidden="true"></i><?= e((string) count($publishedElections)) ?> published result<?= count($publishedElections) === 1 ? '' : 's' ?></span>
            <span><i class="bi bi-shield-check" aria-hidden="true"></i>Certified by the election office</span>
        </div>
    </div>
    <a class="btn btn-light" href="<?= e(url('voter/elections.php')) ?>"><i class="bi bi-ui-checks me-2" aria-hidden="true"></i>Elections</a>
</div>

<?php if (count($publishedElections) > 1): ?>
    <div class="published-picker" role="tablist" aria-label="Published elections">
        <?php foreach ($publishedElections as $option):
            $optionMeta = Election::meta(Election::status($option));
        ?>
            <a class="published-picker__item<?= (int) $option['id'] === $selectedId ? ' active' : '' ?>"
               href="<?= e(url('voter/results.php?election_id=' . (int) $option['id'])) ?>"
               <?= (int) $option['id'] === $selectedId ? 'aria-current="page"' : '' ?>>
                <span class="published-picker__date"><?= e(format_date($option['end_at'], 'M j, Y')) ?></span>
                <strong><?= e($option['title']) ?></strong>
                <span class="published-picker__status"><i class="bi <?= e($optionMeta['icon']) ?>" aria-hidden="true"></i><?= e($optionMeta['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<article class="result-hero">
    <div class="result-hero__copy">
        <span class="result-hero__eyebrow"><i class="bi bi-award" aria-hidden="true"></i>Official result</span>
        <h2><?= e($election['title']) ?></h2>
        <p><?= e($election['description'] ?: 'No description was provided for this election.') ?></p>
        <div class="result-hero__meta">
            <span><i class="bi bi-calendar3" aria-hidden="true"></i><?= e(format_datetime($election['start_at'])) ?> – <?= e(format_datetime($election['end_at'])) ?></span>
            <span><i class="bi bi-check2-square" aria-hidden="true"></i><?= e(number_format($summary['total_ballots'])) ?> ballots counted</span>
            <?php if ($voterParticipated): ?>
                <span class="result-hero__voted"><i class="bi bi-patch-check-fill" aria-hidden="true"></i>You participated</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="result-hero__turnout">
        <span class="result-hero__turnout-label">Voter turnout</span>
        <strong><?= e(number_format($summary['turnout'], 1)) ?>%</strong>
        <span><?= e(number_format($summary['total_participants'])) ?> of <?= e(number_format($summary['eligible_voters'])) ?> active voters</span>
        <div class="progress app-progress" role="progressbar" aria-label="Voter turnout" aria-valuenow="<?= e((string) round($summary['turnout'])) ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" style="width: <?= e((string) min(100, $summary['turnout'])) ?>%"></div>
        </div>
    </div>
</article>

<div class="row g-4 mt-0">
    <div class="col-xl-8">
        <section class="panel panel--results">
            <div class="panel__header">
                <div>
                    <h2 class="panel__title">Final candidate tally</h2>
                    <p class="panel__subtitle"><?= e((string) $election['candidate_count']) ?> candidates · percentages of valid ballots</p>
                </div>
                <span class="metric-chip"><i class="bi bi-globe2" aria-hidden="true"></i>Public</span>
            </div>

            <?php if ($summary['candidates']): ?>
                <div class="tally-list tally-list--public">
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
                                        <span class="winner-chip"><i class="bi bi-award-fill" aria-hidden="true"></i>Winner</span>
                                    <?php endif; ?>
                                </div>
                                <div class="progress result-bar" role="progressbar"
                                     aria-label="<?= e($candidate['full_name']) ?> share of votes"
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
                    Percentages are calculated against <?= e(number_format($summary['total_ballots'])) ?> valid ballots. Ties are shown as ties and are not broken by the software.
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="empty-state__icon"><i class="bi bi-person-x" aria-hidden="true"></i></span>
                    <h3>No candidate tally</h3>
                    <p>This election was published without candidate records.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-xl-4">
        <?php if ($summary['winners'] && $summary['max_votes'] > 0): ?>
            <section class="winner-panel winner-panel--public">
                <span class="winner-panel__eyebrow"><?= $summary['has_tie'] ? 'Joint winners' : 'Elected' ?></span>
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
                    <div class="winner-panel__tie"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>This result is tied. The election office will determine the next step.</div>
                <?php endif; ?>
                <span class="winner-panel__status"><i class="bi bi-globe2" aria-hidden="true"></i>Published <?= e(format_date($election['end_at'])) ?></span>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="panel__header">
                <div>
                    <h2 class="panel__title">Result summary</h2>
                    <p class="panel__subtitle">Certified figures</p>
                </div>
            </div>
            <dl class="detail-list">
                <div><dt>Ballots counted</dt><dd><?= e(number_format($summary['total_ballots'])) ?></dd></div>
                <div><dt>Voters participated</dt><dd><?= e(number_format($summary['total_participants'])) ?></dd></div>
                <div><dt>Active voter roll</dt><dd><?= e(number_format($summary['eligible_voters'])) ?></dd></div>
                <div><dt>Turnout</dt><dd><?= e(number_format($summary['turnout'], 1)) ?>%</dd></div>
                <div><dt>Published</dt><dd><?= e(format_date($election['end_at'])) ?></dd></div>
            </dl>
            <div class="result-integrity-note <?= $summary['integrity_balanced'] ? 'is-ok' : 'is-warning' ?>">
                <i class="bi <?= $summary['integrity_balanced'] ? 'bi-patch-check-fill' : 'bi-exclamation-triangle-fill' ?>" aria-hidden="true"></i>
                <span><?= $summary['integrity_balanced'] ? 'Ballot and participation records reconcile.' : 'This result requires review by the election office.' ?></span>
            </div>
        </section>

        <div class="voter-help-note">
            <i class="bi bi-shield-check" aria-hidden="true"></i>
            <div>
                <strong>About these results</strong>
                <p>Counts come from anonymous ballot rows. The system confirms participation separately, so it cannot show how any individual voted. Results become public only after certification.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/voter_footer.php'; ?>
