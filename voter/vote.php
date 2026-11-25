<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

$voter = Auth::requireVoter();
$pdo = Database::connection();
$voterId = (int) $voter['id'];

$electionId = positive_int($_GET['election_id'] ?? 0);
if ($electionId <= 0) {
    flash_redirect('voter/elections.php', 'danger', 'Choose an election to open its ballot.');
}

$stmt = $pdo->prepare(
    "SELECT e.*,
            (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count,
            p.id AS participation_id,
            p.receipt_code,
            p.cast_at AS voted_at
     FROM elections e
     LEFT JOIN vote_participation p ON p.election_id = e.id AND p.voter_id = ?
     WHERE e.id = ? AND e.status <> 'draft'
     LIMIT 1"
);
$stmt->execute([$voterId, $electionId]);
$election = $stmt->fetch();

if (!$election) {
    abort_request(404, 'Election not found', 'This election is not available on your voter ballot.');
}

$status = Election::status($election);
$meta = Election::meta($status);
$hasVoted = !empty($election['participation_id']);
$isLive = $status === Election::LIVE;
$isUpcoming = $status === Election::UPCOMING;
$isFinal = in_array($status, [Election::ENDED, Election::CLOSED], true);
$resultsPublic = (int) $election['results_published'] === 1;

$candidatesStmt = $pdo->prepare(
    'SELECT * FROM candidates WHERE election_id = ? ORDER BY display_order ASC, full_name ASC'
);
$candidatesStmt->execute([$electionId]);
$candidates = $candidatesStmt->fetchAll();

$selectionError = '';

if (is_post()) {
    csrf_abort_if_invalid();

    if ($hasVoted) {
        flash_redirect(
            'voter/receipt.php?id=' . (int) $election['participation_id'],
            'info',
            'Your ballot for this election has already been recorded.'
        );
    }

    $candidateId = positive_int($_POST['candidate_id'] ?? 0);
    if ($candidateId <= 0) {
        $selectionError = 'Select one candidate before submitting your ballot.';
    } else {
        try {
            $result = VoteService::cast($voterId, $electionId, $candidateId);
            session_regenerate_id(true);
            csrf_rotate();
            flash('success', 'Your ballot has been recorded. Keep your participation receipt as confirmation.');
            redirect('voter/receipt.php?id=' . $result['participation_id'] . '&new=1', 303);
        } catch (VoteException $exception) {
            if ($exception->httpStatus === 409) {
                $existingStmt = $pdo->prepare(
                    'SELECT id FROM vote_participation WHERE voter_id = ? AND election_id = ? LIMIT 1'
                );
                $existingStmt->execute([$voterId, $electionId]);
                $existingId = (int) $existingStmt->fetchColumn();
                flash_redirect(
                    $existingId > 0 ? 'voter/receipt.php?id=' . $existingId : 'voter/elections.php',
                    'warning',
                    $exception->getMessage()
                );
            }
            $selectionError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Vote submission failed: ' . $exception->getMessage());
            $selectionError = 'The ballot could not be recorded right now. Please try again.';
        }
    }

    // Refresh the ballot state after a failed attempt.
    $stmt->execute([$voterId, $electionId]);
    $election = $stmt->fetch() ?: $election;
    $hasVoted = !empty($election['participation_id']);
    $status = Election::status($election);
    $meta = Election::meta($status);
    $isLive = $status === Election::LIVE;
}

$canVote = $isLive && !$hasVoted && (bool) $candidates;

$pageTitle = 'Cast your vote';
$pageEyebrow = 'Secure ballot';
$activeNav = 'elections';
require_once dirname(__DIR__) . '/includes/voter_header.php';
?>

<div class="ballot-topline">
    <a class="back-link" href="<?= e(url('voter/elections.php')) ?>">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>All elections
    </a>
    <span class="status-pill status-pill--<?= e($meta['class']) ?>">
        <i class="bi <?= e($meta['icon']) ?>" aria-hidden="true"></i><?= e($meta['label']) ?>
    </span>
</div>

<header class="ballot-header">
    <div class="ballot-header__copy">
        <span class="ballot-header__eyebrow">Official campus ballot</span>
        <h2><?= e($election['title']) ?></h2>
        <p><?= e($election['description'] ?: 'No description was provided for this election.') ?></p>
        <div class="ballot-header__meta">
            <span><i class="bi bi-calendar3" aria-hidden="true"></i><?= e(format_datetime($election['start_at'], 'M j, Y · g:i A')) ?> – <?= e(format_datetime($election['end_at'], 'M j, Y · g:i A')) ?></span>
            <span><i class="bi bi-people" aria-hidden="true"></i><?= e((string) $election['candidate_count']) ?> candidate<?= (int) $election['candidate_count'] === 1 ? '' : 's' ?></span>
        </div>
    </div>
    <div class="ballot-header__clock">
        <span class="ballot-header__clock-label"><?= $isLive ? 'Voting closes in' : ($isUpcoming ? 'Voting opens in' : 'Voting closed') ?></span>
        <?php if ($isLive): ?>
            <strong data-countdown-end="<?= e((new DateTimeImmutable((string) $election['end_at'], new DateTimeZone(APP_TIMEZONE)))->format('c')) ?>"><?= e(time_until($election['end_at'])) ?></strong>
        <?php elseif ($isUpcoming): ?>
            <strong data-countdown-end="<?= e((new DateTimeImmutable((string) $election['start_at'], new DateTimeZone(APP_TIMEZONE)))->format('c')) ?>"><?= e(time_until($election['start_at'])) ?></strong>
        <?php else: ?>
            <strong><?= e(format_date($election['end_at'])) ?></strong>
        <?php endif; ?>
        <span class="ballot-header__clock-note">Server time · <?= e(APP_TIMEZONE) ?></span>
    </div>
</header>

<?php if ($hasVoted): ?>
    <div class="ballot-state-card ballot-state-card--success">
        <span class="ballot-state-card__icon"><i class="bi bi-patch-check-fill" aria-hidden="true"></i></span>
        <div class="ballot-state-card__copy">
            <h2>Your ballot is already recorded</h2>
            <p>You voted in this election on <?= e(format_datetime($election['voted_at'])) ?>. A ballot cannot be changed or submitted twice.</p>
            <span class="receipt-inline">Receipt <code><?= e($election['receipt_code']) ?></code></span>
        </div>
        <div class="ballot-state-card__actions">
            <a class="btn btn-brand" href="<?= e(url('voter/receipt.php?id=' . (int) $election['participation_id'])) ?>">
                <i class="bi bi-receipt me-2" aria-hidden="true"></i>View receipt
            </a>
            <?php if ($resultsPublic): ?>
                <a class="btn btn-light" href="<?= e(url('voter/results.php?election_id=' . $electionId)) ?>">View results</a>
            <?php endif; ?>
        </div>
    </div>
<?php elseif (!$candidates): ?>
    <div class="ballot-state-card">
        <span class="ballot-state-card__icon"><i class="bi bi-person-x" aria-hidden="true"></i></span>
        <div class="ballot-state-card__copy">
            <h2>This ballot has no candidates yet</h2>
            <p>The election office has not finalised the candidate list. Check back before the voting window closes.</p>
        </div>
        <div class="ballot-state-card__actions">
            <a class="btn btn-light" href="<?= e(url('voter/elections.php')) ?>">Back to elections</a>
        </div>
    </div>
<?php elseif ($isUpcoming): ?>
    <div class="ballot-state-card ballot-state-card--upcoming">
        <span class="ballot-state-card__icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
        <div class="ballot-state-card__copy">
            <h2>Voting has not opened yet</h2>
            <p><?= e(Election::blockReason($election)) ?> You can read every candidate profile and manifesto now, then return to cast your ballot.</p>
        </div>
        <div class="ballot-state-card__actions">
            <a class="btn btn-light" href="<?= e(url('voter/elections.php')) ?>">Back to elections</a>
        </div>
    </div>
<?php elseif ($isFinal): ?>
    <div class="ballot-state-card">
        <span class="ballot-state-card__icon"><i class="bi bi-lock" aria-hidden="true"></i></span>
        <div class="ballot-state-card__copy">
            <h2>Voting for this election is closed</h2>
            <p><?= e(Election::blockReason($election)) ?> Candidate profiles remain available for reference.</p>
        </div>
        <div class="ballot-state-card__actions">
            <?php if ($resultsPublic): ?>
                <a class="btn btn-brand" href="<?= e(url('voter/results.php?election_id=' . $electionId)) ?>">View published results</a>
            <?php else: ?>
                <a class="btn btn-light" href="<?= e(url('voter/elections.php')) ?>">Back to elections</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($selectionError !== ''): ?>
    <div class="alert alert-danger ballot-alert" role="alert">
        <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
        <div><?= e($selectionError) ?></div>
    </div>
<?php endif; ?>

<?php if ($canVote): ?>
    <form method="post" action="<?= e(url('voter/vote.php?election_id=' . $electionId)) ?>"
          class="ballot-layout" data-ballot-form data-loading-form
          data-confirm="Submit your ballot? Once recorded it cannot be changed or withdrawn."
          data-confirm-title="Confirm your vote" data-confirm-button="Submit my ballot" data-confirm-variant="primary">
        <?= csrf_field() ?>

        <div class="ballot-layout__main">
            <div class="ballot-instructions">
                <span class="ballot-instructions__icon"><i class="bi bi-1-circle" aria-hidden="true"></i></span>
                <div>
                    <strong>Select one candidate</strong>
                    <p>Tap a candidate card to make your choice. You may review their profile and manifesto before deciding.</p>
                </div>
            </div>

            <div class="ballot-list" role="radiogroup" aria-label="Candidates">
                <?php foreach ($candidates as $candidate): ?>
                    <label class="ballot-candidate" data-ballot-candidate>
                        <input type="radio" name="candidate_id" value="<?= (int) $candidate['id'] ?>"
                               class="ballot-candidate__input" required
                               data-candidate-name="<?= e($candidate['full_name']) ?>"
                               data-candidate-party="<?= e($candidate['party'] ?: 'Independent candidate') ?>"
                               data-candidate-order="<?= e((string) $candidate['display_order']) ?>">
                        <span class="ballot-candidate__marker" aria-hidden="true"><span></span></span>
                        <span class="ballot-candidate__number">#<?= e((string) $candidate['display_order']) ?></span>
                        <?= avatar_markup((string) $candidate['full_name'], $candidate['photo_url'] ?? null, 'avatar--md') ?>
                        <span class="ballot-candidate__body">
                            <span class="ballot-candidate__name"><?= e($candidate['full_name']) ?></span>
                            <span class="ballot-candidate__party"><?= e($candidate['party'] ?: 'Independent candidate') ?></span>
                            <?php if ($candidate['tagline']): ?>
                                <span class="ballot-candidate__tagline">“<?= e($candidate['tagline']) ?>”</span>
                            <?php endif; ?>
                            <?php if ($candidate['bio']): ?>
                                <span class="ballot-candidate__bio"><?= e(truncate_text($candidate['bio'], 150)) ?></span>
                            <?php endif; ?>
                            <?php if ($candidate['manifesto'] || $candidate['bio']): ?>
                                <button type="button" class="ballot-candidate__more"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#manifesto-<?= (int) $candidate['id'] ?>"
                                        aria-expanded="false"
                                        aria-controls="manifesto-<?= (int) $candidate['id'] ?>">
                                    Read full profile <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                </button>
                            <?php endif; ?>
                        </span>
                        <span class="ballot-candidate__check" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
                    </label>
                    <?php if ($candidate['manifesto'] || $candidate['bio']): ?>
                        <div class="collapse ballot-candidate__collapsed" id="manifesto-<?= (int) $candidate['id'] ?>">
                            <div class="candidate-manifesto">
                                <?php if ($candidate['bio']): ?>
                                    <div class="candidate-manifesto__section">
                                        <span>Background</span>
                                        <p><?= nl2br(e($candidate['bio'])) ?></p>
                                    </div>
                                <?php endif; ?>
                                <?php if ($candidate['manifesto']): ?>
                                    <div class="candidate-manifesto__section">
                                        <span>Manifesto</span>
                                        <p><?= nl2br(e($candidate['manifesto'])) ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="ballot-rules">
                <h2>Before you submit</h2>
                <ul>
                    <li><i class="bi bi-check2" aria-hidden="true"></i>You may vote only once in this election.</li>
                    <li><i class="bi bi-check2" aria-hidden="true"></i>Your selected candidate is final once the ballot is recorded.</li>
                    <li><i class="bi bi-check2" aria-hidden="true"></i>Your identity is recorded only as having participated, never alongside your choice.</li>
                    <li><i class="bi bi-check2" aria-hidden="true"></i>Voting closes automatically at <?= e(format_datetime($election['end_at'], 'M j, Y · g:i A')) ?>.</li>
                </ul>
            </div>
        </div>

        <aside class="ballot-layout__side">
            <div class="ballot-summary" data-ballot-summary>
                <div class="ballot-summary__header">
                    <span class="ballot-summary__eyebrow">Your selection</span>
                    <span class="ballot-summary__state" data-ballot-state>Nothing selected</span>
                </div>
                <div class="ballot-summary__empty" data-ballot-empty>
                    <span class="ballot-summary__empty-icon"><i class="bi bi-ui-checks" aria-hidden="true"></i></span>
                    <p>Choose a candidate from the ballot to review your selection here.</p>
                </div>
                <div class="ballot-summary__choice" data-ballot-choice hidden>
                    <div class="ballot-summary__candidate">
                        <span class="ballot-summary__avatar" data-ballot-avatar>?</span>
                        <div>
                            <strong data-ballot-name>—</strong>
                            <span data-ballot-party>—</span>
                        </div>
                    </div>
                    <dl class="ballot-summary__details">
                        <div><dt>Election</dt><dd><?= e($election['title']) ?></dd></div>
                        <div><dt>Ballot position</dt><dd data-ballot-order>—</dd></div>
                        <div><dt>Voter ID</dt><dd><?= e($voter['student_id']) ?></dd></div>
                    </dl>
                    <div class="ballot-summary__warning">
                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                        This choice cannot be changed after submission.
                    </div>
                </div>

                <button type="submit" class="btn btn-brand w-100 ballot-submit" data-loading-button data-ballot-submit>
                    <span class="btn-label"><i class="bi bi-check2-circle me-2" aria-hidden="true"></i>Submit ballot</span>
                </button>
                <p class="ballot-summary__note">
                    <i class="bi bi-lock" aria-hidden="true"></i>
                    Submitted over a protected session. Your ballot is final.
                </p>
            </div>

            <div class="ballot-privacy">
                <i class="bi bi-shield-check" aria-hidden="true"></i>
                <div>
                    <strong>How your privacy is protected</strong>
                    <p>The ballot stores your chosen candidate without your voter ID. A separate participation record confirms you voted and generates your receipt, but it never stores your choice.</p>
                </div>
            </div>
        </aside>
    </form>
<?php else: ?>
    <div class="ballot-layout ballot-layout--readonly">
        <div class="ballot-layout__main">
            <div class="ballot-readonly-heading">
                <h2>Candidate profiles</h2>
                <p>Read each candidate’s background and manifesto. This is a read-only view of the ballot.</p>
            </div>
            <div class="ballot-list ballot-list--readonly">
                <?php foreach ($candidates as $candidate): ?>
                    <article class="ballot-candidate ballot-candidate--readonly">
                        <span class="ballot-candidate__number">#<?= e((string) $candidate['display_order']) ?></span>
                        <?= avatar_markup((string) $candidate['full_name'], $candidate['photo_url'] ?? null, 'avatar--md') ?>
                        <div class="ballot-candidate__body">
                            <h3 class="ballot-candidate__name"><?= e($candidate['full_name']) ?></h3>
                            <span class="ballot-candidate__party"><?= e($candidate['party'] ?: 'Independent candidate') ?></span>
                            <?php if ($candidate['tagline']): ?>
                                <p class="ballot-candidate__tagline">“<?= e($candidate['tagline']) ?>”</p>
                            <?php endif; ?>
                            <?php if ($candidate['bio']): ?>
                                <p class="ballot-candidate__bio"><?= e($candidate['bio']) ?></p>
                            <?php endif; ?>
                            <?php if ($candidate['manifesto']): ?>
                                <div class="candidate-manifesto candidate-manifesto--inline">
                                    <div class="candidate-manifesto__section">
                                        <span>Manifesto</span>
                                        <p><?= nl2br(e($candidate['manifesto'])) ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
        <aside class="ballot-layout__side">
            <div class="ballot-summary ballot-summary--static">
                <span class="ballot-summary__eyebrow">Ballot access</span>
                <div class="ballot-summary__static-state">
                    <i class="bi <?= $hasVoted ? 'bi-patch-check-fill' : ($isLive ? 'bi-broadcast' : 'bi-lock') ?>" aria-hidden="true"></i>
                    <strong><?= $hasVoted ? 'Ballot recorded' : ($isLive ? 'Voting open' : 'Voting unavailable') ?></strong>
                    <p><?= $hasVoted ? 'You have already participated in this election.' : e(Election::blockReason($election)) ?></p>
                </div>
                <a class="btn btn-light w-100" href="<?= e(url('voter/elections.php')) ?>">Back to elections</a>
            </div>
        </aside>
    </div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/voter_footer.php'; ?>
