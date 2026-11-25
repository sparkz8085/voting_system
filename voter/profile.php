<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

$voter = Auth::requireVoter();
$pdo = Database::connection();
$voterId = (int) $voter['id'];

$profileForm = [
    'full_name'  => $voter['full_name'],
    'email'      => $voter['email'],
    'department' => $voter['department'],
];
$profileErrors = [];
$passwordErrors = [];

if (is_post()) {
    csrf_abort_if_invalid();
    $action = post_string('action');

    if ($action === 'update_profile') {
        $profileForm = [
            'full_name'  => post_string('full_name'),
            'email'      => normalize_login_identifier(post_string('email')),
            'department' => post_string('department'),
        ];

        if (mb_strlen($profileForm['full_name']) < 2 || mb_strlen($profileForm['full_name']) > 100) {
            $profileErrors['full_name'] = 'Enter your full name (2–100 characters).';
        } elseif (!preg_match('/\p{L}/u', $profileForm['full_name']) || preg_match('/[\x00-\x1F\x7F<>]/u', $profileForm['full_name'])) {
            $profileErrors['full_name'] = 'Enter a valid name.';
        }
        if (filter_var($profileForm['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($profileForm['email']) > 150) {
            $profileErrors['email'] = 'Enter a valid email address.';
        }
        if (mb_strlen($profileForm['department']) < 2 || mb_strlen($profileForm['department']) > 100) {
            $profileErrors['department'] = 'Enter your department (2–100 characters).';
        }

        if (!$profileErrors) {
            $stmt = $pdo->prepare('SELECT id FROM voters WHERE id <> ? AND email = ? LIMIT 1');
            $stmt->execute([$voterId, $profileForm['email']]);
            if ($stmt->fetch()) {
                $profileErrors['email'] = 'Another voter account already uses that email address.';
            }
        }

        if (!$profileErrors) {
            try {
                $stmt = $pdo->prepare('UPDATE voters SET full_name = ?, email = ?, department = ? WHERE id = ?');
                $stmt->execute([$profileForm['full_name'], $profileForm['email'], $profileForm['department'], $voterId]);
                $_SESSION['auth']['name'] = $profileForm['full_name'];
                $_SESSION['auth']['email'] = $profileForm['email'];
                flash('success', 'Your profile details were updated.');
                redirect('voter/profile.php', 303);
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    $profileErrors['email'] = 'That email address is already in use.';
                } else {
                    throw $exception;
                }
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = post_raw('current_password');
        $newPassword = post_raw('new_password');
        $confirmPassword = post_raw('new_password_confirm');

        $hashStmt = $pdo->prepare('SELECT password_hash FROM voters WHERE id = ? LIMIT 1');
        $hashStmt->execute([$voterId]);
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
            $stmt = $pdo->prepare('UPDATE voters SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $voterId]);
            session_regenerate_id(true);
            csrf_rotate();
            flash('success', 'Your password was changed. Your current session stays signed in.');
            redirect('voter/profile.php', 303);
        }
    }
}

$historyStmt = $pdo->prepare(
    'SELECT p.*, e.title AS election_title, e.status AS election_status, e.start_at, e.end_at, e.results_published
     FROM vote_participation p
     JOIN elections e ON e.id = p.election_id
     WHERE p.voter_id = ?
     ORDER BY p.cast_at DESC, p.id DESC'
);
$historyStmt->execute([$voterId]);
$history = $historyStmt->fetchAll();

$pendingStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM elections e
     WHERE e.status = 'active' AND NOW() BETWEEN e.start_at AND e.end_at
       AND NOT EXISTS (
           SELECT 1 FROM vote_participation p WHERE p.election_id = e.id AND p.voter_id = ?
       )"
);
$pendingStmt->execute([$voterId]);
$pendingBallots = (int) $pendingStmt->fetchColumn();

$pageTitle = 'My profile';
$pageEyebrow = 'Account & security';
$activeNav = 'profile';
require_once dirname(__DIR__) . '/includes/voter_header.php';
?>

<div class="voter-page-intro">
    <div>
        <p>Keep your contact details accurate and your account password secure. Your username and student ID are managed by the election office.</p>
        <div class="voter-page-intro__meta">
            <span><i class="bi bi-patch-check-fill" aria-hidden="true"></i>Verified voter</span>
            <span><i class="bi bi-clock-history" aria-hidden="true"></i>Member since <?= e(format_date($voter['created_at'])) ?></span>
        </div>
    </div>
    <a class="btn btn-light" href="<?= e(url('voter/elections.php')) ?>">
        <i class="bi bi-ui-checks me-2" aria-hidden="true"></i>Go to elections
    </a>
</div>

<div class="row g-4 profile-grid">
    <div class="col-lg-4">
        <section class="profile-summary profile-summary--voter">
            <div class="profile-summary__cover" aria-hidden="true"></div>
            <div class="profile-summary__body">
                <?= avatar_markup((string) $voter['full_name'], null, 'avatar--xl profile-summary__avatar') ?>
                <h2><?= e($voter['full_name']) ?></h2>
                <span class="profile-summary__role"><i class="bi bi-patch-check-fill" aria-hidden="true"></i>Verified student voter</span>
                <dl class="profile-summary__details">
                    <div><dt>Student ID</dt><dd><?= e($voter['student_id']) ?></dd></div>
                    <div><dt>Username</dt><dd>@<?= e($voter['username']) ?></dd></div>
                    <div><dt>Department</dt><dd><?= e($voter['department']) ?></dd></div>
                    <div><dt>Email</dt><dd><?= e($voter['email']) ?></dd></div>
                    <div><dt>Last sign-in</dt><dd><?= e($voter['last_login_at'] ? format_datetime($voter['last_login_at']) : 'Not recorded') ?></dd></div>
                </dl>
                <div class="profile-summary__permissions">
                    <span><i class="bi bi-check2" aria-hidden="true"></i>One ballot per election</span>
                    <span><i class="bi bi-check2" aria-hidden="true"></i>Receipt after voting</span>
                    <span><i class="bi bi-check2" aria-hidden="true"></i>Secret ballot privacy</span>
                </div>
            </div>
        </section>

        <section class="voter-side-card mt-4">
            <div class="voter-side-card__header">
                <h2>Voting record</h2>
                <span class="metric-chip"><?= e((string) count($history)) ?> ballot<?= count($history) === 1 ? '' : 's' ?></span>
            </div>
            <?php if ($pendingBallots > 0): ?>
                <div class="profile-pending-note">
                    <i class="bi bi-broadcast" aria-hidden="true"></i>
                    <span><?= e((string) $pendingBallots) ?> open ballot<?= $pendingBallots === 1 ? '' : 's' ?> waiting for you.</span>
                    <a href="<?= e(url('voter/elections.php')) ?>">Vote now</a>
                </div>
            <?php endif; ?>
            <?php if ($history): ?>
                <div class="compact-list">
                    <?php foreach (array_slice($history, 0, 4) as $record): ?>
                        <a class="compact-list__item compact-list__item--link" href="<?= e(url('voter/receipt.php?id=' . (int) $record['id'])) ?>">
                            <span class="compact-list__icon"><i class="bi bi-receipt" aria-hidden="true"></i></span>
                            <div class="compact-list__meta">
                                <strong><?= e($record['election_title']) ?></strong>
                                <span><?= e(format_date($record['cast_at'])) ?> · <?= e($record['receipt_code']) ?></span>
                            </div>
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state empty-state--tiny">
                    <p>You have not voted yet. Your participation receipts will appear here.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-lg-8">
        <section class="panel">
            <div class="panel__header">
                <div>
                    <h2 class="panel__title">Personal details</h2>
                    <p class="panel__subtitle">Contact information used by the election office</p>
                </div>
            </div>
            <form method="post" action="<?= e(url('voter/profile.php')) ?>" data-loading-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_profile">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="full_name" class="form-label">Full name</label>
                        <input type="text" class="form-control<?= field_invalid($profileErrors, 'full_name') ?>" id="full_name" name="full_name"
                               value="<?= e($profileForm['full_name']) ?>" required minlength="2" maxlength="100">
                        <?= field_error($profileErrors, 'full_name') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="email" class="form-label">Email address</label>
                        <input type="email" class="form-control<?= field_invalid($profileErrors, 'email') ?>" id="email" name="email"
                               value="<?= e($profileForm['email']) ?>" required maxlength="150">
                        <?= field_error($profileErrors, 'email') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="department" class="form-label">Department</label>
                        <input type="text" class="form-control<?= field_invalid($profileErrors, 'department') ?>" id="department" name="department"
                               value="<?= e($profileForm['department']) ?>" required minlength="2" maxlength="100">
                        <?= field_error($profileErrors, 'department') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="student_id" class="form-label">Student ID <span class="form-hint">Locked</span></label>
                        <input type="text" class="form-control" id="student_id" value="<?= e($voter['student_id']) ?>" disabled>
                        <div class="form-text">Contact the election office to correct your student ID or username.</div>
                    </div>
                </div>
                <div class="panel__form-actions">
                    <span class="form-hint">Changing your email changes how you can sign in.</span>
                    <button type="submit" class="btn btn-brand" data-loading-button><span class="btn-label">Save details</span></button>
                </div>
            </form>
        </section>

        <section class="panel mt-4">
            <div class="panel__header">
                <div>
                    <h2 class="panel__title">Change password</h2>
                    <p class="panel__subtitle">Use a unique password you do not use elsewhere</p>
                </div>
                <span class="security-chip"><i class="bi bi-shield-check" aria-hidden="true"></i>Protected</span>
            </div>
            <form method="post" action="<?= e(url('voter/profile.php')) ?>" data-loading-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <div class="row g-3">
                    <div class="col-12">
                        <label for="current_password" class="form-label">Current password</label>
                        <div class="password-field">
                            <input type="password" class="form-control<?= field_invalid($passwordErrors, 'current_password') ?>" id="current_password"
                                   name="current_password" required autocomplete="current-password">
                            <button type="button" class="password-toggle" data-password-toggle aria-label="Show password">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <?= field_error($passwordErrors, 'current_password') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="new_password" class="form-label">New password</label>
                        <div class="password-field">
                            <input type="password" class="form-control<?= field_invalid($passwordErrors, 'new_password') ?>" id="new_password"
                                   name="new_password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password"
                                   data-password-strength-source placeholder="At least <?= PASSWORD_MIN_LENGTH ?> characters">
                            <button type="button" class="password-toggle" data-password-toggle aria-label="Show password">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="password-strength" data-password-strength aria-live="polite">
                            <span class="password-strength__track"><span class="password-strength__bar"></span></span>
                            <span class="password-strength__label">Use letters and numbers</span>
                        </div>
                        <?= field_error($passwordErrors, 'new_password') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="new_password_confirm" class="form-label">Confirm new password</label>
                        <div class="password-field">
                            <input type="password" class="form-control<?= field_invalid($passwordErrors, 'new_password_confirm') ?>" id="new_password_confirm"
                                   name="new_password_confirm" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
                            <button type="button" class="password-toggle" data-password-toggle aria-label="Show password">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <?= field_error($passwordErrors, 'new_password_confirm') ?>
                    </div>
                </div>
                <div class="panel__form-actions">
                    <span class="form-hint"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Your session stays signed in after the change.</span>
                    <button type="submit" class="btn btn-brand" data-loading-button><span class="btn-label">Update password</span></button>
                </div>
            </form>
        </section>

        <section class="panel mt-4">
            <div class="panel__header">
                <div>
                    <h2 class="panel__title">Full participation history</h2>
                    <p class="panel__subtitle">Every election in which you have cast a ballot</p>
                </div>
            </div>
            <?php if ($history): ?>
                <div class="table-responsive">
                    <table class="table app-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Election</th>
                                <th scope="col">Voted at</th>
                                <th scope="col">Receipt</th>
                                <th scope="col" class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $record): ?>
                                <tr>
                                    <td>
                                        <div class="table-primary">
                                            <strong><?= e($record['election_title']) ?></strong>
                                            <span><?= e(format_date($record['start_at'], 'M j')) ?> – <?= e(format_date($record['end_at'])) ?></span>
                                        </div>
                                    </td>
                                    <td><span class="table-date"><?= e(format_datetime($record['cast_at'], 'M j, Y · g:i A')) ?></span></td>
                                    <td><code class="receipt-chip"><?= e($record['receipt_code']) ?></code></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-light" href="<?= e(url('voter/receipt.php?id=' . (int) $record['id'])) ?>">Receipt</a>
                                        <?php if ((int) $record['results_published'] === 1): ?>
                                            <a class="btn btn-sm btn-outline-brand" href="<?= e(url('voter/results.php?election_id=' . (int) $record['election_id'])) ?>">Results</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state empty-state--compact">
                    <span class="empty-state__icon"><i class="bi bi-receipt" aria-hidden="true"></i></span>
                    <h3>No voting history yet</h3>
                    <p>After you submit your first ballot, the participation receipt appears here.</p>
                    <a class="btn btn-light" href="<?= e(url('voter/elections.php')) ?>">View elections</a>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/voter_footer.php'; ?>
