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
        $loginError = 'Enter your username or email and your password.';
    } else {
        $result = Auth::attempt('voter', $identifier, $password);
        if ($result['success']) {
            $signedIn = Auth::user();
            $firstName = $signedIn ? explode(' ', (string) $signedIn['name'])[0] : '';
            flash('success', 'Welcome back' . ($firstName !== '' ? ', ' . $firstName : '') . '. Your voter dashboard is ready.');
            redirect((string) $result['redirect'], 303);
        }
        $loginError = $result['message'];
    }
}

$pageTitle = 'Voter sign in';
$authTheme = 'voter';
require_once dirname(__DIR__) . '/includes/auth_header.php';
?>
<main id="auth-main" class="auth-layout">
    <section class="auth-showcase" aria-labelledby="auth-showcase-title">
        <a class="brand brand--light" href="<?= e(url()) ?>">
            <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="40" height="40">
            <span><?= e(APP_NAME) ?></span>
        </a>

        <div class="auth-showcase__content">
            <span class="eyebrow eyebrow--light"><span class="eyebrow__dot"></span> Voter portal</span>
            <h1 id="auth-showcase-title">Your voice shapes the campus.</h1>
            <p>Sign in to review active elections, read candidate manifestos, and cast your one secure ballot.</p>

            <ul class="auth-points">
                <li>
                    <span class="auth-points__icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
                    <span><strong>Verified on the server</strong>Every election window and ballot is validated server-side.</span>
                </li>
                <li>
                    <span class="auth-points__icon"><i class="bi bi-fingerprint" aria-hidden="true"></i></span>
                    <span><strong>One voter, one ballot</strong>A database constraint prevents duplicate participation.</span>
                </li>
                <li>
                    <span class="auth-points__icon"><i class="bi bi-journal-check" aria-hidden="true"></i></span>
                    <span><strong>Clear confirmation</strong>Receive a participation receipt for every election.</span>
                </li>
            </ul>
        </div>

        <div class="auth-showcase__foot">
            <span><i class="bi bi-calendar2-check me-2" aria-hidden="true"></i>Academic demonstration system</span>
            <span>Not for governmental elections</span>
        </div>
        <div class="auth-showcase__orb auth-showcase__orb--one" aria-hidden="true"></div>
        <div class="auth-showcase__orb auth-showcase__orb--two" aria-hidden="true"></div>
    </section>

    <section class="auth-panel">
        <div class="auth-card">
            <a class="brand auth-mobile-brand d-lg-none" href="<?= e(url()) ?>">
                <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="36" height="36">
                <span><?= e(APP_NAME) ?></span>
            </a>

            <div class="auth-card__head">
                <span class="eyebrow">Welcome back</span>
                <h2>Sign in to vote</h2>
                <p>Use the username or email address registered with the election office.</p>
            </div>

            <?php if ($loginError !== ''): ?>
                <div class="alert alert-danger auth-alert" role="alert">
                    <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
                    <div><?= e($loginError) ?></div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('auth/login.php')) ?>" class="auth-form" data-loading-form>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label for="identifier" class="form-label">Username or email</label>
                    <div class="input-group auth-input-group">
                        <span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span>
                        <input type="text" class="form-control" id="identifier" name="identifier"
                               value="<?= e($identifier) ?>" autocomplete="username" required autofocus
                               placeholder="e.g. aditi or aditi@campus.test" maxlength="150">
                    </div>
                </div>
                <div class="mb-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <label for="password" class="form-label mb-0">Password</label>
                        <span class="form-hint">Minimum 8 characters</span>
                    </div>
                    <div class="input-group auth-input-group">
                        <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               autocomplete="current-password" required placeholder="Enter your password">
                        <button class="btn btn-password-toggle" type="button" data-toggle-password="password"
                                aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3 mb-4">
                    <span class="form-hint"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Secure session</span>
                    <a href="mailto:<?= e(APP_SUPPORT_EMAIL) ?>" class="small-link">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-brand btn-lg w-100" data-loading-button>
                    <span class="btn-label">Sign in</span>
                    <i class="bi bi-arrow-right ms-2" aria-hidden="true"></i>
                </button>
            </form>

            <div class="auth-divider"><span>New to <?= e(APP_NAME) ?>?</span></div>
            <a href="<?= e(url('auth/register.php')) ?>" class="btn btn-outline-brand btn-lg w-100">
                Create a voter account
            </a>

            <p class="auth-admin-link">
                <i class="bi bi-shield-lock" aria-hidden="true"></i>
                Election official?
                <a href="<?= e(url('auth/admin-login.php')) ?>">Open the admin portal</a>
            </p>
        </div>

        <p class="auth-legal">
            By signing in you agree to the election code of conduct.
            <a href="<?= e(url()) ?>">Return to home</a>
        </p>
    </section>
</main>
<?php require_once dirname(__DIR__) . '/includes/auth_footer.php'; ?>
