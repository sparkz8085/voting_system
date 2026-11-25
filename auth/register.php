<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

Auth::redirectIfAuthenticated();

$departments = [
    'Computer Science',
    'Information Technology',
    'Electronics',
    'Mechanical Engineering',
    'Civil Engineering',
    'Electrical Engineering',
    'Commerce',
    'Economics',
    'Management',
    'Design',
    'Architecture',
    'Law',
    'Physics',
    'Chemistry',
    'Mathematics',
    'Political Science',
    'Psychology',
    'English Literature',
    'Journalism',
];

$form = [
    'full_name'        => '',
    'email'            => '',
    'username'         => '',
    'student_id'       => '',
    'department'       => '',
];
$errors = [];
$termsChecked = false;

if (is_post()) {
    csrf_abort_if_invalid();

    $form['full_name'] = post_string('full_name');
    $form['email'] = normalize_login_identifier(post_string('email'));
    $form['username'] = normalize_login_identifier(post_string('username'));
    $form['student_id'] = mb_strtoupper(post_string('student_id'));
    $form['department'] = post_string('department');
    $password = post_raw('password');
    $passwordConfirm = post_raw('password_confirm');
    $termsChecked = isset($_POST['terms']);

    if (mb_strlen($form['full_name']) < 2 || mb_strlen($form['full_name']) > 100) {
        $errors['full_name'] = 'Enter your full name (2–100 characters).';
    } elseif (!preg_match('/\p{L}/u', $form['full_name']) || preg_match('/[\x00-\x1F\x7F<>]/u', $form['full_name'])) {
        $errors['full_name'] = 'Enter a valid name using letters and normal punctuation.';
    }

    if (filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($form['email']) > 150) {
        $errors['email'] = 'Enter a valid email address.';
    }

    $usernameError = username_policy_error($form['username']);
    if ($usernameError !== null) {
        $errors['username'] = $usernameError;
    }

    if (!preg_match('/^[A-Z0-9][A-Z0-9._\/-]{2,29}$/', $form['student_id'])) {
        $errors['student_id'] = 'Student ID must be 3–30 letters, numbers, dots, dashes, or slashes.';
    }

    if (mb_strlen($form['department']) < 2 || mb_strlen($form['department']) > 100) {
        $errors['department'] = 'Select or enter your department (2–100 characters).';
    }

    $passwordError = password_policy_error($password);
    if ($passwordError !== null) {
        $errors['password'] = $passwordError;
    }
    if ($passwordConfirm !== $password) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }
    if (mb_strtolower($password) === $form['username'] || ($form['email'] !== '' && mb_strtolower($password) === $form['email'])) {
        $errors['password'] = 'Choose a password that is different from your username or email.';
    }
    if (!$termsChecked) {
        $errors['terms'] = 'Please confirm that your details are accurate and accept the election rules.';
    }

    if (!$errors) {
        $stmt = Database::connection()->prepare(
            'SELECT username, email, student_id FROM voters
             WHERE username = ? OR email = ? OR student_id = ? LIMIT 3'
        );
        $stmt->execute([$form['username'], $form['email'], $form['student_id']]);
        foreach ($stmt->fetchAll() as $existing) {
            if (mb_strtolower((string) $existing['username']) === $form['username']) {
                $errors['username'] = 'That username is already registered.';
            }
            if (mb_strtolower((string) $existing['email']) === $form['email']) {
                $errors['email'] = 'An account with that email already exists.';
            }
            if (mb_strtoupper((string) $existing['student_id']) === $form['student_id']) {
                $errors['student_id'] = 'That student ID is already registered.';
            }
        }
    }

    if (!$errors) {
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO voters (full_name, username, email, student_id, department, password_hash, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([
                $form['full_name'],
                $form['username'],
                $form['email'],
                $form['student_id'],
                $form['department'],
                password_hash($password, PASSWORD_DEFAULT),
            ]);

            // Registration is open to eligible campus members in this
            // demonstration, so sign the new voter in immediately.
            $result = Auth::attempt('voter', $form['username'], $password);
            if ($result['success']) {
                flash('success', 'Your voter account is ready. Read the candidate profiles and cast your ballot when an election is live.');
                redirect((string) $result['redirect'], 303);
            }
            flash('success', 'Your account was created. Please sign in.');
            redirect('auth/login.php', 303);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $errors['general'] = 'One of those details is already registered. Check your email, username, and student ID.';
            } else {
                throw $exception;
            }
        }
    }
}

$pageTitle = 'Create voter account';
$authTheme = 'voter';
require_once dirname(__DIR__) . '/includes/auth_header.php';
?>
<main id="auth-main" class="auth-layout">
    <section class="auth-showcase auth-showcase--register" aria-labelledby="register-showcase-title">
        <a class="brand brand--light" href="<?= e(url()) ?>">
            <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="40" height="40">
            <span><?= e(APP_NAME) ?></span>
        </a>

        <div class="auth-showcase__content">
            <span class="eyebrow eyebrow--light"><span class="eyebrow__dot"></span> Voter registration</span>
            <h1 id="register-showcase-title">Join the campus voter roll.</h1>
            <p>Register once with your student details, then use the same account for every campus election.</p>

            <div class="registration-steps">
                <div class="registration-step">
                    <span class="registration-step__number">01</span>
                    <div><strong>Create your account</strong><span>Use accurate student information.</span></div>
                </div>
                <div class="registration-step">
                    <span class="registration-step__number">02</span>
                    <div><strong>Review the ballot</strong><span>Read candidate profiles before voting.</span></div>
                </div>
                <div class="registration-step">
                    <span class="registration-step__number">03</span>
                    <div><strong>Vote with confidence</strong><span>One secure ballot per election.</span></div>
                </div>
            </div>

            <div class="showcase-note">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <span>Registrations are subject to verification by the election office.</span>
            </div>
        </div>

        <div class="auth-showcase__foot">
            <span><i class="bi bi-person-check me-2" aria-hidden="true"></i>Students only</span>
            <span>Data used for election administration</span>
        </div>
        <div class="auth-showcase__orb auth-showcase__orb--one" aria-hidden="true"></div>
        <div class="auth-showcase__orb auth-showcase__orb--two" aria-hidden="true"></div>
    </section>

    <section class="auth-panel auth-panel--register">
        <div class="auth-card auth-card--wide">
            <a class="brand auth-mobile-brand d-lg-none" href="<?= e(url()) ?>">
                <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="36" height="36">
                <span><?= e(APP_NAME) ?></span>
            </a>

            <div class="auth-card__head">
                <span class="eyebrow">Get started</span>
                <h2>Create your voter account</h2>
                <p>All fields are required. Your details are validated on the server.</p>
            </div>

            <?php if (!empty($errors['general'])): ?>
                <div class="alert alert-danger auth-alert" role="alert">
                    <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
                    <div><?= e($errors['general']) ?></div>
                </div>
            <?php elseif ($errors): ?>
                <div class="alert alert-danger auth-alert" role="alert">
                    <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
                    <div>Please review the highlighted fields and try again.</div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('auth/register.php')) ?>" class="auth-form" data-loading-form novalidate>
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-12">
                        <label for="full_name" class="form-label">Full name</label>
                        <input type="text" class="form-control<?= field_invalid($errors, 'full_name') ?>" id="full_name" name="full_name"
                               value="<?= e($form['full_name']) ?>" autocomplete="name" required maxlength="100"
                               placeholder="Your name as it appears on the student record">
                        <?= field_error($errors, 'full_name') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="email" class="form-label">Email address</label>
                        <input type="email" class="form-control<?= field_invalid($errors, 'email') ?>" id="email" name="email"
                               value="<?= e($form['email']) ?>" autocomplete="email" required maxlength="150"
                               placeholder="you@campus.test">
                        <?= field_error($errors, 'email') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control<?= field_invalid($errors, 'username') ?>" id="username" name="username"
                               value="<?= e($form['username']) ?>" autocomplete="username" required maxlength="30"
                               pattern="[A-Za-z0-9._-]{3,30}" placeholder="e.g. aditi.sharma">
                        <?= field_error($errors, 'username') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="student_id" class="form-label">Student ID</label>
                        <input type="text" class="form-control<?= field_invalid($errors, 'student_id') ?>" id="student_id" name="student_id"
                               value="<?= e($form['student_id']) ?>" required maxlength="30"
                               placeholder="e.g. STU-2026-042">
                        <?= field_error($errors, 'student_id') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="department" class="form-label">Department</label>
                        <input type="text" class="form-control<?= field_invalid($errors, 'department') ?>" id="department" name="department"
                               value="<?= e($form['department']) ?>" list="departmentList" required maxlength="100"
                               placeholder="Select or type your department">
                        <datalist id="departmentList">
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= e($department) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <?= field_error($errors, 'department') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control<?= field_invalid($errors, 'password') ?>" id="password" name="password"
                               autocomplete="new-password" required minlength="<?= PASSWORD_MIN_LENGTH ?>"
                               placeholder="At least <?= PASSWORD_MIN_LENGTH ?> characters" data-password-strength-source>
                        <div class="password-strength" data-password-strength aria-live="polite">
                            <span class="password-strength__track"><span class="password-strength__bar"></span></span>
                            <span class="password-strength__label">Use letters and numbers</span>
                        </div>
                        <?= field_error($errors, 'password') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="password_confirm" class="form-label">Confirm password</label>
                        <input type="password" class="form-control<?= field_invalid($errors, 'password_confirm') ?>" id="password_confirm" name="password_confirm"
                               autocomplete="new-password" required minlength="<?= PASSWORD_MIN_LENGTH ?>"
                               placeholder="Re-enter your password">
                        <?= field_error($errors, 'password_confirm') ?>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-check-card<?= isset($errors['terms']) ? ' is-invalid' : '' ?>">
                            <input class="form-check-input" type="checkbox" value="1" id="terms" name="terms" <?= $termsChecked ? 'checked' : '' ?> required>
                            <label class="form-check-label" for="terms">
                                I confirm my details are accurate and I accept the election code of conduct.
                            </label>
                        </div>
                        <?= field_error($errors, 'terms') ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-brand btn-lg w-100 mt-4" data-loading-button>
                    <span class="btn-label">Create account</span>
                    <i class="bi bi-arrow-right ms-2" aria-hidden="true"></i>
                </button>
            </form>

            <p class="auth-switch">Already registered? <a href="<?= e(url('auth/login.php')) ?>">Sign in instead</a></p>
        </div>

        <p class="auth-legal">
            Your password is stored as a one-way hash. It is never shown or emailed.
            <a href="<?= e(url()) ?>">Return to home</a>
        </p>
    </section>
</main>
<?php require_once dirname(__DIR__) . '/includes/auth_footer.php'; ?>
