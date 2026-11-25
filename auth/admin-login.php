<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

Auth::redirectIfAuthenticated();

$loginError = '';
$identifier = '';

if (is_post()) {
    csrf_abort_if_invalid();
    $identifier = post_string('identifier');
    $password = post_raw('password');

    if ($identifier === '' || $password === '') {
        $loginError = 'Enter your administrator username or email and password.';
    } else {
        $result = Auth::attempt('admin', $identifier, $password);
        if ($result['success']) {
            flash('success', 'Administrator session started. All management actions are logged.');
            redirect((string) $result['redirect'], 303);
        }
        $loginError = $result['message'];
    }
}

$pageTitle = 'Administrator sign in';
$authTheme = 'admin';
require_once dirname(__DIR__) . '/includes/auth_header.php';
?>
<main id="auth-main" class="auth-layout auth-layout--admin">
    <section class="auth-showcase auth-showcase--admin" aria-labelledby="admin-showcase-title">
        <a class="brand brand--light" href="<?= e(url()) ?>">
            <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="40" height="40">
            <span><?= e(APP_NAME) ?></span>
        </a>

        <div class="auth-showcase__content">
            <span class="eyebrow eyebrow--light"><span class="eyebrow__dot eyebrow__dot--amber"></span> Election control room</span>
            <h1 id="admin-showcase-title">Run elections with clarity and control.</h1>
            <p>Manage the voter roll, prepare ballots, monitor turnout, and publish certified results from one place.</p>

            <ul class="auth-points auth-points--admin">
                <li>
                    <span class="auth-points__icon"><i class="bi bi-sliders2" aria-hidden="true"></i></span>
                    <span><strong>Full election lifecycle</strong>Draft, schedule, open, close, and publish results.</span>
                </li>
                <li>
                    <span class="auth-points__icon"><i class="bi bi-clipboard-data" aria-hidden="true"></i></span>
                    <span><strong>Auditable actions</strong>Administrative changes are recorded in the activity log.</span>
                </li>
                <li>
                    <span class="auth-points__icon"><i class="bi bi-database-check" aria-hidden="true"></i></span>
                    <span><strong>Integrity checks</strong>Compare participation records against anonymous ballots.</span>
                </li>
            </ul>
        </div>

        <div class="admin-auth-status">
            <span class="status-dot"></span>
            <span>Restricted system · Authorized officials only</span>
        </div>
        <div class="auth-showcase__orb auth-showcase__orb--one" aria-hidden="true"></div>
        <div class="auth-showcase__orb auth-showcase__orb--two" aria-hidden="true"></div>
    </section>

    <section class="auth-panel auth-panel--admin">
        <div class="auth-card auth-card--admin">
            <a class="brand auth-mobile-brand d-lg-none" href="<?= e(url()) ?>">
                <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="36" height="36">
                <span><?= e(APP_NAME) ?></span>
            </a>

            <div class="auth-card__head">
                <span class="admin-secure-label"><i class="bi bi-shield-lock-fill" aria-hidden="true"></i> Secure access</span>
                <h2>Administrator sign in</h2>
                <p>Enter the credentials issued by the election office.</p>
            </div>

            <?php if ($loginError !== ''): ?>
                <div class="alert alert-danger auth-alert" role="alert">
                    <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
                    <div><?= e($loginError) ?></div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('auth/admin-login.php')) ?>" class="auth-form" data-loading-form>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label for="identifier" class="form-label">Administrator username or email</label>
                    <div class="input-group auth-input-group auth-input-group--dark">
                        <span class="input-group-text"><i class="bi bi-person-badge" aria-hidden="true"></i></span>
                        <input type="text" class="form-control" id="identifier" name="identifier"
                               value="<?= e($identifier) ?>" autocomplete="username" required autofocus
                               placeholder="Administrator username" maxlength="150">
                    </div>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group auth-input-group auth-input-group--dark">
                        <span class="input-group-text"><i class="bi bi-key" aria-hidden="true"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               autocomplete="current-password" required placeholder="Enter your password">
                        <button class="btn btn-password-toggle" type="button" data-toggle-password="password"
                                aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                </div>

                <button type="submit" class="btn btn-admin-primary btn-lg w-100" data-loading-button>
                    <span class="btn-label">Enter administration</span>
                    <i class="bi bi-arrow-right ms-2" aria-hidden="true"></i>
                </button>
            </form>

            <div class="admin-login-notices">
                <p><i class="bi bi-clock-history" aria-hidden="true"></i> Repeated failed attempts temporarily lock sign-in.</p>
                <p><i class="bi bi-journal-text" aria-hidden="true"></i> Administrative actions are recorded for audit.</p>
            </div>

            <div class="auth-divider"><span>Looking for the voter portal?</span></div>
            <a href="<?= e(url('auth/login.php')) ?>" class="btn btn-outline-dark-soft btn-lg w-100">
                Go to voter sign in
            </a>
        </div>

        <p class="auth-legal auth-legal--admin">
            <?= e(APP_NAME) ?> election administration ·
            <a href="<?= e(url()) ?>">Public site</a>
        </p>
    </section>
</main>
<?php require_once dirname(__DIR__) . '/includes/auth_footer.php'; ?>
