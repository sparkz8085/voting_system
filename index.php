<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

$pdo = Database::connection();

$stats = [
    'active_elections' => (int) $pdo->query(
        "SELECT COUNT(*) FROM elections WHERE status = 'active' AND end_at > NOW()"
    )->fetchColumn(),
    'registered_voters' => (int) $pdo->query(
        'SELECT COUNT(*) FROM voters WHERE is_active = 1'
    )->fetchColumn(),
    'candidates' => (int) $pdo->query(
        "SELECT COUNT(*) FROM candidates c
         JOIN elections e ON e.id = c.election_id
         WHERE e.status = 'active' AND e.end_at > NOW()"
    )->fetchColumn(),
    'published_results' => (int) $pdo->query(
        'SELECT COUNT(*) FROM elections WHERE results_published = 1'
    )->fetchColumn(),
];

$electionsStmt = $pdo->query(
    "SELECT e.*,
            (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count
     FROM elections e
     WHERE e.status = 'active' AND e.end_at > NOW()
     ORDER BY CASE WHEN NOW() BETWEEN e.start_at AND e.end_at THEN 0 ELSE 1 END,
              e.start_at ASC
     LIMIT 3"
);
$publicElections = $electionsStmt->fetchAll();

$publishedElections = array_slice(ResultService::publishedElections(), 0, 3);
$currentUser = Auth::user();
$dashboardLink = $currentUser
    ? ($currentUser['type'] === 'admin' ? 'admin/dashboard.php' : 'voter/dashboard.php')
    : null;
$primaryCtaUrl = $currentUser ? url($dashboardLink) : url('auth/register.php');
$primaryCtaLabel = $currentUser ? 'Open my dashboard' : 'Register to vote';
$voterElectionsUrl = $currentUser && $currentUser['type'] === 'voter'
    ? url('voter/elections.php')
    : url('auth/login.php');
$resultsUrl = $currentUser && $currentUser['type'] === 'voter'
    ? url('voter/results.php')
    : url('auth/login.php');

$pageTitle = 'Secure campus elections';
$pageDescription = 'CampusVote is a secure online voting system for student elections, with verified voter accounts, server-checked voting windows, and published results.';
require_once __DIR__ . '/includes/header.php';
?>
<main id="main-content">
    <section class="hero">
        <div class="hero__decor hero__decor--one" aria-hidden="true"></div>
        <div class="hero__decor hero__decor--two" aria-hidden="true"></div>
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <span class="eyebrow"><span class="eyebrow__dot"></span> Campus election season <?= e(date('Y')) ?></span>
                    <h1 class="hero__title">Your campus.<br><span>Your voice.</span> Your vote.</h1>
                    <p class="hero__lede">
                        A clear, accountable way to choose student representatives.
                        Verify once, review every candidate, and cast one secure ballot — all in a few minutes.
                    </p>
                    <div class="hero__actions">
                        <a class="btn btn-brand btn-lg" href="<?= e($primaryCtaUrl) ?>">
                            <?= e($primaryCtaLabel) ?>
                            <i class="bi bi-arrow-right ms-2" aria-hidden="true"></i>
                        </a>
                        <a class="btn btn-outline-soft btn-lg" href="#elections">
                            <i class="bi bi-play-circle me-2" aria-hidden="true"></i>See live elections
                        </a>
                    </div>
                    <ul class="hero__trust">
                        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> One voter, one ballot</li>
                        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Server-verified windows</li>
                        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Private ballot records</li>
                    </ul>
                </div>
                <div class="col-lg-6">
                    <div class="hero__art">
                        <img src="<?= e(asset('img/hero-ballot.svg')) ?>"
                             alt="Illustration of a completed campus ballot and a secure ballot box"
                             width="640" height="620" fetchpriority="high">
                        <div class="hero__float-card hero__float-card--top">
                            <span class="hero__float-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
                            <span><strong>Identity verified</strong><small>Registered voter</small></span>
                        </div>
                        <div class="hero__float-card hero__float-card--bottom">
                            <span class="hero__float-icon hero__float-icon--teal"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
                            <span><strong>Voting window</strong><small>Checked on every ballot</small></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="stats-ribbon" aria-label="Campus election statistics">
                <div class="stats-ribbon__item">
                    <span class="stats-ribbon__icon stats-ribbon__icon--indigo"><i class="bi bi-calendar2-week" aria-hidden="true"></i></span>
                    <div><strong><?= e(number_format($stats['active_elections'])) ?></strong><span>Open or scheduled elections</span></div>
                </div>
                <div class="stats-ribbon__item">
                    <span class="stats-ribbon__icon stats-ribbon__icon--teal"><i class="bi bi-people" aria-hidden="true"></i></span>
                    <div><strong><?= e(number_format($stats['registered_voters'])) ?></strong><span>Registered voters</span></div>
                </div>
                <div class="stats-ribbon__item">
                    <span class="stats-ribbon__icon stats-ribbon__icon--amber"><i class="bi bi-person-badge" aria-hidden="true"></i></span>
                    <div><strong><?= e(number_format($stats['candidates'])) ?></strong><span>Candidates on current ballots</span></div>
                </div>
                <div class="stats-ribbon__item">
                    <span class="stats-ribbon__icon stats-ribbon__icon--violet"><i class="bi bi-bar-chart-line" aria-hidden="true"></i></span>
                    <div><strong><?= e(number_format($stats['published_results'])) ?></strong><span>Published result sets</span></div>
                </div>
            </div>
        </div>
    </section>

    <section class="section section--elections" id="elections">
        <div class="container">
            <div class="section-heading section-heading--row">
                <div>
                    <span class="eyebrow">On the ballot</span>
                    <h2>Elections happening now</h2>
                    <p>Everything you need to know before you cast a vote.</p>
                </div>
                <a href="<?= e($voterElectionsUrl) ?>" class="section-link">
                    View all elections <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </div>

            <?php if ($publicElections): ?>
                <div class="row g-4">
                    <?php foreach ($publicElections as $election):
                        $status = Election::status($election);
                        $meta = Election::meta($status);
                    ?>
                        <div class="col-md-6 col-xl-4">
                            <article class="public-election-card">
                                <div class="public-election-card__top">
                                    <span class="status-pill status-pill--<?= e($meta['class']) ?>">
                                        <i class="bi <?= e($meta['icon']) ?>" aria-hidden="true"></i>
                                        <?= e($meta['label']) ?>
                                    </span>
                                    <span class="public-election-card__count">
                                        <i class="bi bi-people" aria-hidden="true"></i>
                                        <?= e((string) $election['candidate_count']) ?> candidates
                                    </span>
                                </div>
                                <h3><?= e($election['title']) ?></h3>
                                <p><?= e(truncate_text($election['description'], 138)) ?></p>
                                <div class="public-election-card__meta">
                                    <span><i class="bi bi-calendar3" aria-hidden="true"></i><?= e(format_date($election['start_at'])) ?> – <?= e(format_date($election['end_at'])) ?></span>
                                    <span class="public-election-card__timing"><?= e(Election::timingHint($election)) ?></span>
                                </div>
                                <a class="public-election-card__link" href="<?= e($voterElectionsUrl) ?>">
                                    <?= $currentUser && $currentUser['type'] === 'voter' ? 'Review this election' : 'Sign in to take part' ?>
                                    <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                </a>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state empty-state--public">
                    <span class="empty-state__icon"><i class="bi bi-calendar2-x" aria-hidden="true"></i></span>
                    <h3>No elections are open right now</h3>
                    <p>Check back when the election office announces the next campus vote.</p>
                    <a href="<?= e(url('auth/register.php')) ?>" class="btn btn-outline-brand">Join the voter roll</a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="section section--steps" id="how-it-works">
        <div class="container">
            <div class="section-heading section-heading--center">
                <span class="eyebrow">Simple by design</span>
                <h2>Three steps from sign-in to ballot</h2>
                <p>A focused voting experience, with verification handled quietly in the background.</p>
            </div>
            <div class="steps-grid">
                <article class="step-card">
                    <span class="step-card__number">01</span>
                    <span class="step-card__icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span>
                    <h3>Create your voter account</h3>
                    <p>Register with your name, student ID, department, and a secure password. Your login is hashed before it is stored.</p>
                </article>
                <article class="step-card">
                    <span class="step-card__number">02</span>
                    <span class="step-card__icon"><i class="bi bi-journal-text" aria-hidden="true"></i></span>
                    <h3>Review the candidates</h3>
                    <p>Open an active election to read each candidate's profile, priorities, and manifesto before deciding.</p>
                </article>
                <article class="step-card">
                    <span class="step-card__number">03</span>
                    <span class="step-card__icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
                    <h3>Confirm and cast your ballot</h3>
                    <p>Review your choice one last time. The server validates the election window and records exactly one ballot.</p>
                </article>
            </div>
            <div class="steps-cta">
                <a class="btn btn-brand btn-lg" href="<?= e($primaryCtaUrl) ?>"><?= e($primaryCtaLabel) ?></a>
                <span>Registration takes less than two minutes.</span>
            </div>
        </div>
    </section>

    <section class="section section--integrity" id="integrity">
        <div class="container">
            <div class="integrity-panel">
                <div class="integrity-panel__intro">
                    <span class="eyebrow eyebrow--light">Built with care</span>
                    <h2>Security you can see. Limitations stated honestly.</h2>
                    <p>
                        Every important rule is enforced on the server, not in the browser.
                        Passwords are hashed, requests are CSRF-protected, and votes are committed in a transaction.
                    </p>
                    <a href="<?= e(url('auth/register.php')) ?>" class="btn btn-light">Join the voter roll</a>
                </div>
                <div class="integrity-grid">
                    <div class="integrity-item">
                        <span class="integrity-item__icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
                        <h3>Server-side gatekeeping</h3>
                        <p>Election dates, account status, candidate validity, and duplicate ballots are checked in PHP and MySQL.</p>
                    </div>
                    <div class="integrity-item">
                        <span class="integrity-item__icon"><i class="bi bi-fingerprint" aria-hidden="true"></i></span>
                        <h3>One ballot per voter</h3>
                        <p>A unique database constraint on voter and election makes a second submission impossible, even under a race.</p>
                    </div>
                    <div class="integrity-item">
                        <span class="integrity-item__icon"><i class="bi bi-eye-slash" aria-hidden="true"></i></span>
                        <h3>Separated records</h3>
                        <p>Ballots carry no voter ID. A separate participation ledger issues a receipt without storing the choice.</p>
                    </div>
                    <div class="integrity-item">
                        <span class="integrity-item__icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span>
                        <h3>Traceable administration</h3>
                        <p>Election officials can review action logs, participation counts, and published tallies.</p>
                    </div>
                </div>
            </div>
            <div class="integrity-note">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <p>
                    <strong>Academic honesty note.</strong>
                    This is a college project built with conventional PHP and MySQL. It demonstrates good application-security practices, but it cannot provide independently verified anonymity or end-to-end cryptographic verifiability, and it is not suitable for governmental elections.
                </p>
            </div>
        </div>
    </section>

    <section class="section section--results" id="results">
        <div class="container">
            <div class="results-showcase">
                <div class="results-showcase__copy">
                    <span class="eyebrow">Transparent outcomes</span>
                    <h2>Published results, clear counting.</h2>
                    <p>When an election ends and the election office publishes the outcome, every voter can review candidate totals, turnout, and the winning margin.</p>
                    <a href="<?= e($resultsUrl) ?>" class="btn btn-outline-brand">
                        View published results <i class="bi bi-arrow-up-right ms-2" aria-hidden="true"></i>
                    </a>
                </div>
                <div class="results-showcase__panel">
                    <?php if ($publishedElections): ?>
                        <?php foreach ($publishedElections as $published): ?>
                            <a class="published-result-row" href="<?= e($resultsUrl) ?>">
                                <span class="published-result-row__icon"><i class="bi bi-award" aria-hidden="true"></i></span>
                                <span class="published-result-row__meta">
                                    <strong><?= e($published['title']) ?></strong>
                                    <small>Closed <?= e(format_date($published['end_at'])) ?></small>
                                </span>
                                <span class="published-result-row__badge">Results published</span>
                                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="results-panel-empty">
                            <span><i class="bi bi-hourglass-split" aria-hidden="true"></i></span>
                            <h3>No results published yet</h3>
                            <p>Certified outcomes will appear here after an election closes.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="section section--cta">
        <div class="container">
            <div class="cta-panel">
                <div>
                    <span class="eyebrow eyebrow--light">Ready when you are</span>
                    <h2>The next campus decision needs your voice.</h2>
                    <p>Create your voter account and be ready for the next election.</p>
                </div>
                <div class="cta-panel__actions">
                    <a href="<?= e($primaryCtaUrl) ?>" class="btn btn-light btn-lg"><?= e($primaryCtaLabel) ?></a>
                    <a href="<?= e(url('auth/login.php')) ?>" class="btn btn-outline-light btn-lg">Sign in</a>
                </div>
            </div>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
