<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

$voter = Auth::requireVoter();
$voterId = (int) $voter['id'];

$participationId = positive_int($_GET['id'] ?? 0);
if ($participationId <= 0) {
    flash_redirect('voter/elections.php', 'danger', 'Choose an election to view its participation receipt.');
}

$participation = VoteService::findParticipation($participationId, $voterId);
if (!$participation) {
    abort_request(404, 'Receipt not found', 'That participation receipt does not belong to your voter account.');
}

$pdo = Database::connection();
$electionStmt = $pdo->prepare('SELECT * FROM elections WHERE id = ? LIMIT 1');
$electionStmt->execute([(int) $participation['election_id']]);
$election = $electionStmt->fetch();

if (!$election) {
    abort_request(404, 'Election not found', 'The election for this receipt is no longer available.');
}

$status = Election::status($election);
$meta = Election::meta($status);
$isNew = isset($_GET['new']);
$resultsPublic = (int) $election['results_published'] === 1;

$pageTitle = 'Participation receipt';
$pageEyebrow = 'Voting confirmation';
$activeNav = 'elections';
require_once dirname(__DIR__) . '/includes/voter_header.php';
?>

<div class="receipt-page">
    <div class="receipt-page__topline">
        <a class="back-link" href="<?= e(url('voter/elections.php')) ?>">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>All elections
        </a>
        <button type="button" class="btn btn-sm btn-light" data-print>
            <i class="bi bi-printer me-1" aria-hidden="true"></i>Print receipt
        </button>
    </div>

    <?php if ($isNew): ?>
        <section class="receipt-success">
            <span class="receipt-success__icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
            <span class="receipt-success__eyebrow">Ballot recorded</span>
            <h2>Thank you for voting, <?= e(explode(' ', (string) $voter['full_name'])[0]) ?>.</h2>
            <p>Your ballot for <strong><?= e($participation['election_title']) ?></strong> was securely recorded on <?= e(format_datetime($participation['cast_at'])) ?>. Keep the receipt below as confirmation of your participation.</p>
        </section>
    <?php endif; ?>

    <div class="row g-4 align-items-start">
        <div class="col-lg-7">
            <article class="receipt-card">
                <div class="receipt-card__header">
                    <div class="receipt-card__brand">
                        <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="36" height="36">
                        <div>
                            <strong><?= e(APP_NAME) ?></strong>
                            <span>Official participation receipt</span>
                        </div>
                    </div>
                    <span class="status-pill status-pill--<?= e($meta['class']) ?>">
                        <i class="bi <?= e($meta['icon']) ?>" aria-hidden="true"></i><?= e($meta['label']) ?>
                    </span>
                </div>

                <div class="receipt-card__body">
                    <span class="receipt-card__label">Receipt code</span>
                    <div class="receipt-code" data-receipt-code><?= e($participation['receipt_code']) ?></div>
                    <p class="receipt-card__hint">This code confirms participation. It does not reveal how you voted and cannot be used to change your ballot.</p>

                    <dl class="receipt-details">
                        <div>
                            <dt>Election</dt>
                            <dd><?= e($participation['election_title']) ?></dd>
                        </div>
                        <div>
                            <dt>Recorded at</dt>
                            <dd><?= e(format_datetime($participation['cast_at'], 'l, F j, Y · g:i:s A')) ?></dd>
                        </div>
                        <div>
                            <dt>Voter</dt>
                            <dd><?= e($voter['full_name']) ?> · <?= e($voter['student_id']) ?></dd>
                        </div>
                        <div>
                            <dt>Election closed</dt>
                            <dd><?= e(format_datetime($election['end_at'])) ?></dd>
                        </div>
                        <div>
                            <dt>Ballot choice</dt>
                            <dd><span class="sealed-value"><i class="bi bi-lock-fill" aria-hidden="true"></i>Sealed and not stored with your identity</span></dd>
                        </div>
                    </dl>
                </div>

                <div class="receipt-card__footer">
                    <span><i class="bi bi-shield-check" aria-hidden="true"></i>Issued by the <?= e(APP_NAME) ?> election office</span>
                    <span>Receipt #<?= (int) $participation['id'] ?></span>
                </div>
            </article>

            <div class="receipt-actions">
                <a class="btn btn-brand" href="<?= e(url('voter/elections.php')) ?>">
                    <i class="bi bi-ui-checks me-2" aria-hidden="true"></i>Back to elections
                </a>
                <?php if ($resultsPublic): ?>
                    <a class="btn btn-light" href="<?= e(url('voter/results.php?election_id=' . (int) $election['id'])) ?>">
                        <i class="bi bi-bar-chart-line me-2" aria-hidden="true"></i>View published results
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-5">
            <section class="panel receipt-explainer">
                <div class="panel__header">
                    <div>
                        <h2 class="panel__title">What this receipt means</h2>
                        <p class="panel__subtitle">Your participation is confirmed</p>
                    </div>
                </div>
                <ul class="receipt-checklist">
                    <li>
                        <span><i class="bi bi-check2" aria-hidden="true"></i></span>
                        <div><strong>One ballot, recorded once</strong><p>The unique participation record prevents a second submission in this election.</p></div>
                    </li>
                    <li>
                        <span><i class="bi bi-check2" aria-hidden="true"></i></span>
                        <div><strong>Your choice stays private</strong><p>The ballot is stored anonymously. This receipt confirms participation, not a candidate.</p></div>
                    </li>
                    <li>
                        <span><i class="bi bi-check2" aria-hidden="true"></i></span>
                        <div><strong>No changes after submission</strong><p>There is no edit or withdrawal function. Your recorded ballot is final.</p></div>
                    </li>
                </ul>
                <div class="form-note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    Save or print this receipt now. You can return to it later from your dashboard, but it is not emailed automatically.
                </div>
            </section>

            <div class="info-callout mt-4">
                <i class="bi bi-shield-lock" aria-hidden="true"></i>
                <div>
                    <strong>Keep your receipt code private</strong>
                    <p>It proves you participated. Do not post it publicly alongside comments about your vote.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/voter_footer.php'; ?>
