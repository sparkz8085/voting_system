<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

$admin = Auth::requireAdmin();
$pdo = Database::connection();

$allElections = $pdo->query(
    "SELECT e.*,
            (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count,
            (SELECT COUNT(*) FROM votes v WHERE v.election_id = e.id) AS vote_count
     FROM elections e
     ORDER BY CASE e.status WHEN 'draft' THEN 0 WHEN 'active' THEN 1 ELSE 2 END,
              e.start_at ASC"
)->fetchAll();

if (!$allElections) {
    $pageTitle = 'Candidates';
    $pageEyebrow = 'Ballot preparation';
    $activeNav = 'candidates';
    require_once dirname(__DIR__) . '/includes/admin_header.php';
    ?>
    <div class="panel">
        <div class="empty-state">
            <span class="empty-state__icon"><i class="bi bi-calendar2-plus" aria-hidden="true"></i></span>
            <h3>Create an election first</h3>
            <p>Candidates belong to an election. Start by creating a draft election and setting its voting window.</p>
            <a class="btn btn-brand" href="<?= e(url('admin/elections.php?new=1')) ?>">Create election</a>
        </div>
    </div>
    <?php require_once dirname(__DIR__) . '/includes/admin_footer.php'; ?>
    <?php exit; ?>
<?php
}

$electionIds = array_map(static fn (array $row): int => (int) $row['id'], $allElections);
$selectedId = positive_int($_POST['election_id'] ?? $_GET['election_id'] ?? 0);
if (!in_array($selectedId, $electionIds, true)) {
    $selectedId = (int) $allElections[0]['id'];
}

$findElection = static function (int $id) use ($pdo): ?array {
    $stmt = $pdo->prepare(
        "SELECT e.*,
                (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count,
                (SELECT COUNT(*) FROM votes v WHERE v.election_id = e.id) AS vote_count
         FROM elections e WHERE e.id = ? LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
};

$selectedElection = $findElection($selectedId);
if (!$selectedElection) {
    flash_redirect('admin/candidates.php', 'danger', 'That election could not be found.');
}

$candidateErrors = [];
$candidateForm = [
    'id'            => 0,
    'election_id'   => $selectedId,
    'full_name'     => '',
    'party'         => '',
    'tagline'       => '',
    'bio'           => '',
    'manifesto'     => '',
    'photo_url'     => '',
    'display_order' => ((int) $selectedElection['candidate_count']) + 1,
];
$candidateMode = 'create';
$openModal = false;

if (is_post()) {
    csrf_abort_if_invalid();
    $action = post_string('action');

    if ($action === 'save_candidate') {
        $candidateId = positive_int($_POST['candidate_id'] ?? 0);
        $candidateMode = $candidateId > 0 ? 'edit' : 'create';
        $candidateForm = [
            'id'            => $candidateId,
            'election_id'   => $selectedId,
            'full_name'     => post_string('full_name'),
            'party'         => post_string('party'),
            'tagline'       => post_string('tagline'),
            'bio'           => post_string('bio'),
            'manifesto'     => post_string('manifesto'),
            'photo_url'     => post_string('photo_url'),
            'display_order' => post_string('display_order'),
        ];

        if (!Election::canManageCandidates($selectedElection)) {
            flash_redirect(
                'admin/candidates.php?election_id=' . $selectedId,
                'warning',
                'The ballot is locked because voting has started or the election is complete. Candidates can no longer be changed.'
            );
        }

        if (mb_strlen($candidateForm['full_name']) < 2 || mb_strlen($candidateForm['full_name']) > 100) {
            $candidateErrors['full_name'] = 'Candidate name must be between 2 and 100 characters.';
        } elseif (!preg_match('/\p{L}/u', $candidateForm['full_name']) || preg_match('/[\x00-\x1F\x7F<>]/u', $candidateForm['full_name'])) {
            $candidateErrors['full_name'] = 'Enter a valid candidate name.';
        }
        if (mb_strlen($candidateForm['party']) > 100) {
            $candidateErrors['party'] = 'Party or group name must be 100 characters or fewer.';
        }
        if (mb_strlen($candidateForm['tagline']) > 180) {
            $candidateErrors['tagline'] = 'Tagline must be 180 characters or fewer.';
        }
        if (mb_strlen($candidateForm['bio']) > 1000) {
            $candidateErrors['bio'] = 'Profile summary must be 1,000 characters or fewer.';
        }
        if (mb_strlen($candidateForm['manifesto']) > 5000) {
            $candidateErrors['manifesto'] = 'Manifesto must be 5,000 characters or fewer.';
        }
        if ($candidateForm['photo_url'] !== '' && safe_photo_url($candidateForm['photo_url']) === null) {
            $candidateErrors['photo_url'] = 'Enter a valid http(s) image URL, or leave this blank.';
        }
        $orderRaw = (string) $candidateForm['display_order'];
        if (!ctype_digit($orderRaw) || (int) $orderRaw < 0 || (int) $orderRaw > 999) {
            $candidateErrors['display_order'] = 'Ballot order must be a whole number between 0 and 999.';
        }

        $existingCandidate = null;
        if ($candidateId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM candidates WHERE id = ? AND election_id = ? LIMIT 1');
            $stmt->execute([$candidateId, $selectedId]);
            $existingCandidate = $stmt->fetch() ?: null;
            if (!$existingCandidate) {
                flash_redirect('admin/candidates.php?election_id=' . $selectedId, 'danger', 'That candidate no longer exists on this ballot.');
            }
        } elseif ((int) $selectedElection['candidate_count'] >= 20) {
            $candidateErrors['general'] = 'This ballot already has the maximum of 20 candidates.';
        }

        if (!$candidateErrors) {
            $stmt = $pdo->prepare(
                'SELECT id FROM candidates WHERE election_id = ? AND full_name = ? AND id <> ? LIMIT 1'
            );
            $stmt->execute([$selectedId, $candidateForm['full_name'], $candidateId]);
            if ($stmt->fetch()) {
                $candidateErrors['full_name'] = 'A candidate with this name is already on the ballot.';
            }
        }

        if (!$candidateErrors) {
            $party = $candidateForm['party'] !== '' ? $candidateForm['party'] : null;
            $tagline = $candidateForm['tagline'] !== '' ? $candidateForm['tagline'] : null;
            $bio = $candidateForm['bio'] !== '' ? $candidateForm['bio'] : null;
            $manifesto = $candidateForm['manifesto'] !== '' ? $candidateForm['manifesto'] : null;
            $photoUrl = $candidateForm['photo_url'] !== '' ? safe_photo_url($candidateForm['photo_url']) : null;
            $displayOrder = (int) $candidateForm['display_order'];

            try {
                if ($candidateId > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE candidates
                         SET full_name = ?, party = ?, tagline = ?, bio = ?, manifesto = ?, photo_url = ?, display_order = ?
                         WHERE id = ? AND election_id = ?'
                    );
                    $stmt->execute([
                        $candidateForm['full_name'], $party, $tagline, $bio, $manifesto, $photoUrl, $displayOrder,
                        $candidateId, $selectedId,
                    ]);
                    audit_log(
                        (int) $admin['id'],
                        'candidate.updated',
                        'Updated candidate “' . $candidateForm['full_name'] . '” on election #' . $selectedId . '.'
                    );
                    flash('success', 'Candidate profile updated.');
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO candidates
                            (election_id, full_name, party, tagline, bio, manifesto, photo_url, display_order)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $selectedId, $candidateForm['full_name'], $party, $tagline, $bio, $manifesto, $photoUrl, $displayOrder,
                    ]);
                    audit_log(
                        (int) $admin['id'],
                        'candidate.created',
                        'Added candidate “' . $candidateForm['full_name'] . '” to election #' . $selectedId . '.'
                    );
                    flash('success', 'Candidate added to the ballot.');
                }
                redirect('admin/candidates.php?election_id=' . $selectedId, 303);
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    $candidateErrors['full_name'] = 'A candidate with this name is already on the ballot.';
                } else {
                    throw $exception;
                }
            }
        }

        $openModal = true;
    } elseif ($action === 'delete_candidate') {
        $candidateId = positive_int($_POST['candidate_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT c.*, e.title AS election_title, e.status AS election_status, e.start_at, e.end_at
             FROM candidates c JOIN elections e ON e.id = c.election_id
             WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute([$candidateId]);
        $candidate = $stmt->fetch();

        if (!$candidate) {
            flash_redirect('admin/candidates.php?election_id=' . $selectedId, 'danger', 'That candidate could not be found.');
        }

        $electionForCandidate = $findElection((int) $candidate['election_id']);
        if (!$electionForCandidate || !Election::canManageCandidates($electionForCandidate)) {
            flash_redirect(
                'admin/candidates.php?election_id=' . (int) $candidate['election_id'],
                'warning',
                'The ballot is locked. Candidates cannot be removed once voting starts or the election is complete.'
            );
        }

        $voteStmt = $pdo->prepare('SELECT COUNT(*) FROM votes WHERE candidate_id = ?');
        $voteStmt->execute([$candidateId]);
        if ((int) $voteStmt->fetchColumn() > 0) {
            flash_redirect(
                'admin/candidates.php?election_id=' . (int) $candidate['election_id'],
                'danger',
                'This candidate has received votes and cannot be removed.'
            );
        }

        $stmt = $pdo->prepare('DELETE FROM candidates WHERE id = ? AND election_id = ?');
        $stmt->execute([$candidateId, (int) $candidate['election_id']]);
        audit_log(
            (int) $admin['id'],
            'candidate.deleted',
            'Removed candidate “' . $candidate['full_name'] . '” from election #' . (int) $candidate['election_id'] . '.'
        );
        flash('success', 'Candidate removed from the draft ballot.');
        redirect('admin/candidates.php?election_id=' . (int) $candidate['election_id'], 303);
    }

    if ($action === 'save_candidate') {
        // Rebuild the selected election with fresh counts if the form failed.
        $selectedElection = $findElection($selectedId);
    }
}

// Open an existing candidate for editing.
if (!is_post() && isset($_GET['edit'])) {
    $editId = positive_int($_GET['edit']);
    $stmt = $pdo->prepare('SELECT * FROM candidates WHERE id = ? AND election_id = ? LIMIT 1');
    $stmt->execute([$editId, $selectedId]);
    $candidate = $stmt->fetch();
    if (!$candidate) {
        flash_redirect('admin/candidates.php?election_id=' . $selectedId, 'danger', 'That candidate could not be found.');
    }
    if (!Election::canManageCandidates($selectedElection)) {
        flash_redirect(
            'admin/candidates.php?election_id=' . $selectedId,
            'warning',
            'The ballot is locked because voting has started or the election is complete.'
        );
    }
    $candidateMode = 'edit';
    $candidateForm = [
        'id'            => (int) $candidate['id'],
        'election_id'   => $selectedId,
        'full_name'     => $candidate['full_name'],
        'party'         => $candidate['party'] ?? '',
        'tagline'       => $candidate['tagline'] ?? '',
        'bio'           => $candidate['bio'] ?? '',
        'manifesto'     => $candidate['manifesto'] ?? '',
        'photo_url'     => $candidate['photo_url'] ?? '',
        'display_order' => (string) $candidate['display_order'],
    ];
    $openModal = true;
} elseif (!is_post() && isset($_GET['new'])) {
    if (!Election::canManageCandidates($selectedElection)) {
        flash_redirect(
            'admin/candidates.php?election_id=' . $selectedId,
            'warning',
            'The ballot is locked because voting has started or the election is complete.'
        );
    }
    $openModal = true;
}

$candidatesStmt = $pdo->prepare(
    'SELECT c.*, (SELECT COUNT(*) FROM votes v WHERE v.candidate_id = c.id) AS vote_count
     FROM candidates c
     WHERE c.election_id = ?
     ORDER BY c.display_order ASC, c.id ASC'
);
$candidatesStmt->execute([$selectedId]);
$candidates = $candidatesStmt->fetchAll();

$selectedMeta = Election::meta(Election::status($selectedElection));
$canManage = Election::canManageCandidates($selectedElection);

$pageTitle = 'Candidates';
$pageEyebrow = 'Ballot preparation';
$activeNav = 'candidates';
require_once dirname(__DIR__) . '/includes/admin_header.php';
?>

<div class="page-toolbar page-toolbar--candidates">
    <div>
        <p class="page-toolbar__lead">Manage the people on the ballot and the profile voters will read before deciding.</p>
        <div class="page-toolbar__meta">
            <span><i class="bi bi-people" aria-hidden="true"></i><?= e((string) $selectedElection['candidate_count']) ?> candidates</span>
            <span class="status-pill status-pill--<?= e($selectedMeta['class']) ?>">
                <i class="bi <?= e($selectedMeta['icon']) ?>" aria-hidden="true"></i><?= e($selectedMeta['label']) ?>
            </span>
        </div>
    </div>
    <div class="page-toolbar__actions">
        <form method="get" action="<?= e(url('admin/candidates.php')) ?>" class="election-picker">
            <label for="electionSelect" class="visually-hidden">Choose election</label>
            <select class="form-select" id="electionSelect" name="election_id" data-election-switch>
                <?php foreach ($allElections as $election):
                    $electionStatus = Election::status($election);
                    $electionMeta = Election::meta($electionStatus);
                ?>
                    <option value="<?= (int) $election['id'] ?>" <?= (int) $election['id'] === $selectedId ? 'selected' : '' ?>>
                        <?= e($election['title']) ?> · <?= e($electionMeta['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-light">View</button>
        </form>
        <?php if ($canManage): ?>
            <a class="btn btn-brand" href="<?= e(url('admin/candidates.php?election_id=' . $selectedId . '&new=1')) ?>">
                <i class="bi bi-person-plus me-2" aria-hidden="true"></i>Add candidate
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="election-context-bar">
    <div class="election-context-bar__main">
        <span class="election-context-bar__eyebrow">Preparing ballot for</span>
        <h2><?= e($selectedElection['title']) ?></h2>
        <span class="election-context-bar__dates">
            <i class="bi bi-calendar3" aria-hidden="true"></i>
            <?= e(format_datetime($selectedElection['start_at'], 'M j, Y · g:i A')) ?> – <?= e(format_datetime($selectedElection['end_at'], 'M j, Y · g:i A')) ?>
        </span>
    </div>
    <div class="election-context-bar__state">
        <?php if ($canManage): ?>
            <span class="ballot-state ballot-state--open"><i class="bi bi-pencil-square" aria-hidden="true"></i>Ballot editable</span>
            <span class="form-hint">Locked automatically when voting starts.</span>
        <?php else: ?>
            <span class="ballot-state ballot-state--locked"><i class="bi bi-lock" aria-hidden="true"></i>Ballot locked</span>
            <span class="form-hint">Candidate profiles are frozen during voting and after closure.</span>
        <?php endif; ?>
    </div>
</div>

<?php if ($candidates): ?>
    <div class="row g-4 candidate-admin-grid">
        <?php foreach ($candidates as $index => $candidate): ?>
            <div class="col-md-6 col-xxl-4">
                <article class="candidate-admin-card">
                    <div class="candidate-admin-card__top">
                        <span class="ballot-number" title="Ballot order">#<?= e((string) $candidate['display_order']) ?></span>
                        <span class="mini-tag mini-tag--neutral"><i class="bi bi-check2-square" aria-hidden="true"></i><?= e(number_format((int) $candidate['vote_count'])) ?> votes</span>
                    </div>
                    <div class="candidate-admin-card__identity">
                        <?= avatar_markup((string) $candidate['full_name'], $candidate['photo_url'] ?? null, 'avatar--lg') ?>
                        <div>
                            <h3><?= e($candidate['full_name']) ?></h3>
                            <span class="candidate-admin-card__party"><?= e($candidate['party'] ?: 'Independent candidate') ?></span>
                        </div>
                    </div>
                    <?php if ($candidate['tagline']): ?>
                        <p class="candidate-admin-card__tagline">“<?= e($candidate['tagline']) ?>”</p>
                    <?php endif; ?>
                    <p class="candidate-admin-card__bio"><?= e(truncate_text($candidate['bio'], 150) ?: 'No profile summary added yet.') ?></p>
                    <div class="candidate-admin-card__meta">
                        <span><i class="bi bi-journal-text" aria-hidden="true"></i><?= $candidate['manifesto'] ? 'Manifesto added' : 'No manifesto' ?></span>
                        <span><i class="bi bi-image" aria-hidden="true"></i><?= $candidate['photo_url'] ? 'Photo linked' : 'Initials avatar' ?></span>
                    </div>
                    <?php if ($canManage): ?>
                        <div class="candidate-admin-card__actions">
                            <a class="btn btn-light flex-grow-1" href="<?= e(url('admin/candidates.php?election_id=' . $selectedId . '&edit=' . (int) $candidate['id'])) ?>">
                                <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Edit profile
                            </a>
                            <form method="post" action="<?= e(url('admin/candidates.php?election_id=' . $selectedId)) ?>"
                                  data-confirm="Remove “<?= e($candidate['full_name']) ?>” from this draft ballot? This cannot be undone."
                                  data-confirm-title="Remove candidate" data-confirm-button="Remove candidate" data-confirm-variant="danger">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_candidate">
                                <input type="hidden" name="election_id" value="<?= $selectedId ?>">
                                <input type="hidden" name="candidate_id" value="<?= (int) $candidate['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-icon" aria-label="Remove <?= e($candidate['full_name']) ?>" title="Remove candidate">
                                    <i class="bi bi-trash3" aria-hidden="true"></i>
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="panel">
        <div class="empty-state">
            <span class="empty-state__icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span>
            <h3>No candidates on this ballot yet</h3>
            <p><?= $canManage ? 'Add at least two candidates before activating the election.' : 'This ballot has no candidate profiles.' ?></p>
            <?php if ($canManage): ?>
                <a class="btn btn-brand" href="<?= e(url('admin/candidates.php?election_id=' . $selectedId . '&new=1')) ?>">
                    <i class="bi bi-person-plus me-2" aria-hidden="true"></i>Add first candidate
                </a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($canManage): ?>
    <div class="info-callout mt-4">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            <strong>Preparing a fair ballot</strong>
            <p>Ballot order follows the number you assign. Candidate profiles lock automatically when the election window opens, and a candidate who has received votes can never be deleted. At least two candidates are required before activation.</p>
        </div>
    </div>
<?php endif; ?>

<div class="modal fade" id="candidateModal" tabindex="-1" aria-labelledby="candidateModalTitle" aria-hidden="true"
     <?= $openModal ? 'data-open-on-load="true"' : '' ?>>
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content app-modal">
            <form method="post" action="<?= e(url('admin/candidates.php')) ?>" data-loading-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_candidate">
                <input type="hidden" name="candidate_id" value="<?= (int) $candidateForm['id'] ?>">
                <input type="hidden" name="election_id" value="<?= $selectedId ?>">
                <div class="modal-header">
                    <div>
                        <span class="eyebrow"><?= $candidateMode === 'edit' ? 'Profile update' : 'Add to ballot' ?></span>
                        <h2 class="modal-title" id="candidateModalTitle"><?= $candidateMode === 'edit' ? 'Edit candidate' : 'Add a candidate' ?></h2>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($candidateErrors)): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
                            <div><?= e($candidateErrors['general'] ?? 'Please correct the highlighted fields.') ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-7">
                            <label for="full_name" class="form-label">Candidate name</label>
                            <input type="text" class="form-control<?= field_invalid($candidateErrors, 'full_name') ?>" id="full_name" name="full_name"
                                   value="<?= e($candidateForm['full_name']) ?>" required minlength="2" maxlength="100"
                                   placeholder="Full name as it should appear on the ballot">
                            <?= field_error($candidateErrors, 'full_name') ?>
                        </div>
                        <div class="col-md-5">
                            <label for="display_order" class="form-label">Ballot order</label>
                            <input type="number" class="form-control<?= field_invalid($candidateErrors, 'display_order') ?>" id="display_order" name="display_order"
                                   value="<?= e((string) $candidateForm['display_order']) ?>" min="0" max="999" step="1" required>
                            <div class="form-text">Lower numbers appear first.</div>
                            <?= field_error($candidateErrors, 'display_order') ?>
                        </div>
                        <div class="col-12">
                            <label for="party" class="form-label">Party or group <span class="form-hint">Optional</span></label>
                            <input type="text" class="form-control<?= field_invalid($candidateErrors, 'party') ?>" id="party" name="party"
                                   value="<?= e($candidateForm['party']) ?>" maxlength="100" placeholder="e.g. Students for Change, or Independent">
                            <?= field_error($candidateErrors, 'party') ?>
                        </div>
                        <div class="col-12">
                            <label for="tagline" class="form-label">Campaign tagline <span class="form-hint">Optional</span></label>
                            <input type="text" class="form-control<?= field_invalid($candidateErrors, 'tagline') ?>" id="tagline" name="tagline"
                                   value="<?= e($candidateForm['tagline']) ?>" maxlength="180" placeholder="One short line that sums up the campaign">
                            <?= field_error($candidateErrors, 'tagline') ?>
                        </div>
                        <div class="col-12">
                            <label for="bio" class="form-label">Profile summary <span class="form-hint">Optional</span></label>
                            <textarea class="form-control<?= field_invalid($candidateErrors, 'bio') ?>" id="bio" name="bio" rows="3"
                                      maxlength="1000" placeholder="Short background: course, year, experience, and role."><?= e($candidateForm['bio']) ?></textarea>
                            <?= field_error($candidateErrors, 'bio') ?>
                        </div>
                        <div class="col-12">
                            <label for="manifesto" class="form-label">Manifesto <span class="form-hint">Optional</span></label>
                            <textarea class="form-control<?= field_invalid($candidateErrors, 'manifesto') ?>" id="manifesto" name="manifesto" rows="5"
                                      maxlength="5000" placeholder="What the candidate will prioritise if elected."><?= e($candidateForm['manifesto']) ?></textarea>
                            <div class="form-text">Line breaks are preserved. Maximum 5,000 characters.</div>
                            <?= field_error($candidateErrors, 'manifesto') ?>
                        </div>
                        <div class="col-12">
                            <label for="photo_url" class="form-label">Photo URL <span class="form-hint">Optional</span></label>
                            <input type="url" class="form-control<?= field_invalid($candidateErrors, 'photo_url') ?>" id="photo_url" name="photo_url"
                                   value="<?= e($candidateForm['photo_url']) ?>" maxlength="500" placeholder="https://example.com/photo.jpg">
                            <div class="form-text">Use a publicly accessible https image. Initials are shown when omitted.</div>
                            <?= field_error($candidateErrors, 'photo_url') ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand" data-loading-button>
                        <span class="btn-label"><?= $candidateMode === 'edit' ? 'Save profile' : 'Add candidate' ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin_footer.php'; ?>
