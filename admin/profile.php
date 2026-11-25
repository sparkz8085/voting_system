<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

$admin = Auth::requireAdmin();
$pdo = Database::connection();

$profileForm = [
    'full_name' => $admin['full_name'],
    'username'  => $admin['username'],
    'email'     => $admin['email'],
];
$profileErrors = [];
$passwordErrors = [];

if (is_post()) {
    csrf_abort_if_invalid();
    $action = post_string('action');

    if ($action === 'update_profile') {
        $profileForm = [
            'full_name' => post_string('full_name'),
            'username'  => normalize_login_identifier(post_string('username')),
            'email'     => normalize_login_identifier(post_string('email')),
        ];

        if (mb_strlen($profileForm['full_name']) < 2 || mb_strlen($profileForm['full_name']) > 100) {
            $profileErrors['full_name'] = 'Enter your full name (2–100 characters).';
        } elseif (!preg_match('/\p{L}/u', $profileForm['full_name']) || preg_match('/[\x00-\x1F\x7F<>]/u', $profileForm['full_name'])) {
            $profileErrors['full_name'] = 'Enter a valid name.';
        }
        $usernameError = username_policy_error($profileForm['username']);
        if ($usernameError !== null) {
            $profileErrors['username'] = $usernameError;
        }
        if (filter_var($profileForm['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($profileForm['email']) > 150) {
            $profileErrors['email'] = 'Enter a valid email address.';
        }

        if (!$profileErrors) {
            $stmt = $pdo->prepare(
                'SELECT id, username, email FROM admins WHERE id <> ? AND (username = ? OR email = ?) LIMIT 2'
            );
            $stmt->execute([(int) $admin['id'], $profileForm['username'], $profileForm['email']]);
            foreach ($stmt->fetchAll() as $duplicate) {
                if (mb_strtolower((string) $duplicate['username']) === $profileForm['username']) {
                    $profileErrors['username'] = 'Another administrator already uses that username.';
                }
                if (mb_strtolower((string) $duplicate['email']) === $profileForm['email']) {
                    $profileErrors['email'] = 'Another administrator already uses that email.';
                }
            }
        }

        if (!$profileErrors) {
            try {
                $stmt = $pdo->prepare('UPDATE admins SET full_name = ?, username = ?, email = ? WHERE id = ?');
                $stmt->execute([$profileForm['full_name'], $profileForm['username'], $profileForm['email'], (int) $admin['id']]);
                $_SESSION['auth']['name'] = $profileForm['full_name'];
                $_SESSION['auth']['username'] = $profileForm['username'];
                $_SESSION['auth']['email'] = $profileForm['email'];
                audit_log((int) $admin['id'], 'admin.profile_updated', 'Updated administrator profile details.');
                flash('success', 'Administrator profile updated.');
                redirect('admin/profile.php', 303);
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    $profileErrors['username'] = 'Those account details are already in use.';
                } else {
                    throw $exception;
                }
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = post_raw('current_password');
        $newPassword = post_raw('new_password');
        $confirmPassword = post_raw('new_password_confirm');

        $hashStmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ? LIMIT 1');
        $hashStmt->execute([(int) $admin['id']]);
        $currentHash = (string) $hashStmt->fetchColumn();

        if ($currentPassword === '' || !password_verify($currentPassword, $currentHash)) {
            $passwordErrors['current_password'] = 'Your current password is incorrect.';
        }
        $passwordError = password_policy_error($newPassword);
        if ($passwordError !== null) {
            $passwordErrors['new_password'] = $passwordError;
        } elseif (password_verify($newPassword, $currentHash)) {
            $passwordErrors['new_password'] = 'Choose a password different from your current one.';
        }
        if ($newPassword !== $confirmPassword) {
            $passwordErrors['new_password_confirm'] = 'The two passwords do not match.';
        }

        if (!$passwordErrors) {
            $stmt = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $admin['id']]);
            session_regenerate_id(true);
            csrf_rotate();
            audit_log((int) $admin['id'], 'admin.password_changed', 'Changed administrator account password.');
            flash('success', 'Password changed. Your current session remains signed in.');
            redirect('admin/profile.php', 303);
        }
    }
}

$pageTitle = 'My profile';
$pageEyebrow = 'Account & security';
$activeNav = 'profile';
require_once dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="page-toolbar">
    <div>
        <p class="page-toolbar__lead">Keep your administrator credentials accurate and secure.</p>
        <div class="page-toolbar__meta">
            <span><i class="bi bi-shield-lock" aria-hidden="true"></i>Administrator role</span>
            <span><i class="bi bi-clock-history" aria-hidden="true"></i>Last sign-in <?= e($admin['last_login_at'] ? time_ago($admin['last_login_at']) : 'not recorded') ?></span>
        </div>
    </div>
</div>

<div class="row g-4 profile-grid">
    <div class="col-lg-4">
        <section class="profile-summary">
            <div class="profile-summary__cover" aria-hidden="true"></div>
            <div class="profile-summary__body">
                <?= avatar_markup((string) $admin['full_name'], null, 'avatar--xl profile-summary__avatar') ?>
                <h2><?= e($admin['full_name']) ?></h2>
                <span class="profile-summary__role"><i class="bi bi-shield-lock-fill" aria-hidden="true"></i>Election administrator</span>
                <dl class="profile-summary__details">
                    <div><dt>Username</dt><dd>@<?= e($admin['username']) ?></dd></div>
                    <div><dt>Email</dt><dd><?= e($admin['email']) ?></dd></div>
                    <div><dt>Account created</dt><dd><?= e(format_date($admin['created_at'])) ?></dd></div>
                    <div><dt>Last sign-in</dt><dd><?= e($admin['last_login_at'] ? format_datetime($admin['last_login_at']) : 'Not recorded') ?></dd></div>
                </dl>
                <div class="profile-summary__permissions">
                    <span><i class="bi bi-check2" aria-hidden="true"></i>Manage elections</span>
                    <span><i class="bi bi-check2" aria-hidden="true"></i>Manage voter roll</span>
                    <span><i class="bi bi-check2" aria-hidden="true"></i>Publish results</span>
                </div>
            </div>
        </section>

        <div class="info-callout mt-4">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                <strong>Administrator security</strong>
                <p>Use a unique password that is not shared with any other account. Every profile and password change is recorded in the activity log.</p>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <section class="panel">
            <div class="panel__header">
                <div>
                    <h2 class="panel__title">Profile details</h2>
                    <p class="panel__subtitle">The identity shown in the administrator workspace</p>
                </div>
            </div>
            <form method="post" action="<?= e(url('admin/profile.php')) ?>" data-loading-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_profile">
                <div class="row g-3">
                    <div class="col-12">
                        <label for="profile_full_name" class="form-label">Full name</label>
                        <input type="text" class="form-control<?= field_invalid($profileErrors, 'full_name') ?>" id="profile_full_name" name="full_name"
                               value="<?= e($profileForm['full_name']) ?>" required maxlength="100">
                        <?= field_error($profileErrors, 'full_name') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="profile_username" class="form-label">Username</label>
                        <input type="text" class="form-control<?= field_invalid($profileErrors, 'username') ?>" id="profile_username" name="username"
                               value="<?= e($profileForm['username']) ?>" required maxlength="30" pattern="[A-Za-z0-9._-]{3,30}">
                        <?= field_error($profileErrors, 'username') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="profile_email" class="form-label">Email address</label>
                        <input type="email" class="form-control<?= field_invalid($profileErrors, 'email') ?>" id="profile_email" name="email"
                               value="<?= e($profileForm['email']) ?>" required maxlength="150">
                        <?= field_error($profileErrors, 'email') ?>
                    </div>
                </div>
                <div class="panel__form-actions">
                    <span class="form-hint">Changes to your username affect the next administrator sign-in.</span>
                    <button type="submit" class="btn btn-brand" data-loading-button><span class="btn-label">Save profile</span></button>
                </div>
            </form>
        </section>

        <section class="panel mt-4">
            <div class="panel__header">
                <div>
                    <h2 class="panel__title">Change password</h2>
                    <p class="panel__subtitle">Passwords are hashed with PHP's password hashing API</p>
                </div>
                <span class="security-chip"><i class="bi bi-shield-check" aria-hidden="true"></i>Protected</span>
            </div>
            <form method="post" action="<?= e(url('admin/profile.php')) ?>" data-loading-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <div class="row g-3">
                    <div class="col-12">
                        <label for="current_password" class="form-label">Current password</label>
                        <input type="password" class="form-control<?= field_invalid($passwordErrors, 'current_password') ?>" id="current_password" name="current_password"
                               required autocomplete="current-password">
                        <?= field_error($passwordErrors, 'current_password') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="new_password" class="form-label">New password</label>
                        <input type="password" class="form-control<?= field_invalid($passwordErrors, 'new_password') ?>" id="new_password" name="new_password"
                               required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password"
                               data-password-strength-source placeholder="At least <?= PASSWORD_MIN_LENGTH ?> characters">
                        <div class="password-strength" data-password-strength aria-live="polite">
                            <span class="password-strength__track"><span class="password-strength__bar"></span></span>
                            <span class="password-strength__label">Use letters and numbers</span>
                        </div>
                        <?= field_error($passwordErrors, 'new_password') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="new_password_confirm" class="form-label">Confirm new password</label>
                        <input type="password" class="form-control<?= field_invalid($passwordErrors, 'new_password_confirm') ?>" id="new_password_confirm" name="new_password_confirm"
                               required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
                        <?= field_error($passwordErrors, 'new_password_confirm') ?>
                    </div>
                </div>
                <div class="panel__form-actions">
                    <span class="form-hint"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Your session stays active after changing your password.</span>
                    <button type="submit" class="btn btn-brand" data-loading-button><span class="btn-label">Update password</span></button>
                </div>
            </form>
        </section>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin_footer.php'; ?>
