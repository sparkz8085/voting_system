<?php
if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Forbidden.');
}
$voter = $voter ?? Auth::requireVoter();
$activeNav = $activeNav ?? '';
$navItems = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'href' => 'voter/dashboard.php'],
    ['key' => 'elections', 'label' => 'Elections', 'icon' => 'bi-calendar2-week', 'href' => 'voter/elections.php'],
    ['key' => 'results', 'label' => 'Results', 'icon' => 'bi-bar-chart-line', 'href' => 'voter/results.php'],
    ['key' => 'profile', 'label' => 'My profile', 'icon' => 'bi-person-gear', 'href' => 'voter/profile.php'],
];
?>
<div class="sidebar-backdrop" data-sidebar-close aria-hidden="true"></div>
<aside class="app-sidebar app-sidebar--voter" id="appSidebar" aria-label="Voter navigation">
    <div class="sidebar-brand-row">
        <a class="brand brand--sidebar-voter" href="<?= e(url('voter/dashboard.php')) ?>">
            <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="38" height="38">
            <span><?= e(APP_NAME) ?><small>Voter portal</small></span>
        </a>
        <button type="button" class="sidebar-close d-lg-none" data-sidebar-close aria-label="Close navigation">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>

    <div class="sidebar-context sidebar-context--voter">
        <span class="sidebar-context__label">Signed in as</span>
        <span class="sidebar-context__value"><?= e($voter['student_id'] ?? '') ?></span>
    </div>

    <nav class="sidebar-nav">
        <div class="sidebar-nav__group">
            <span class="sidebar-nav__title">Voting</span>
            <?php foreach ($navItems as $item): ?>
                <a class="sidebar-link<?= $activeNav === $item['key'] ? ' active' : '' ?>"
                   href="<?= e(url($item['href'])) ?>"
                   <?= $activeNav === $item['key'] ? 'aria-current="page"' : '' ?>>
                    <i class="bi <?= e($item['icon']) ?>" aria-hidden="true"></i>
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="sidebar-nav__group">
            <span class="sidebar-nav__title">Information</span>
            <a class="sidebar-link" href="<?= e(url()) ?>" target="_blank" rel="noopener">
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span>Public site</span>
            </a>
            <a class="sidebar-link" href="mailto:<?= e(APP_SUPPORT_EMAIL) ?>">
                <i class="bi bi-life-preserver" aria-hidden="true"></i><span>Election help</span>
            </a>
        </div>
    </nav>

    <div class="sidebar-help-card">
        <span class="sidebar-help-card__icon"><i class="bi bi-patch-question" aria-hidden="true"></i></span>
        <strong>Need a hand?</strong>
        <p>Contact the election office if your student details need correcting.</p>
        <a href="mailto:<?= e(APP_SUPPORT_EMAIL) ?>">Contact support <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>

    <div class="sidebar-footer">
        <div class="sidebar-profile">
            <?= avatar_markup((string) $voter['full_name'], null, 'avatar--sm') ?>
            <div class="sidebar-profile__meta">
                <strong><?= e($voter['full_name']) ?></strong>
                <span><?= e($voter['department'] ?? '') ?></span>
            </div>
        </div>
        <div class="sidebar-footer__actions">
            <form method="post" action="<?= e(url('auth/logout.php')) ?>" class="w-100">
                <?= csrf_field() ?>
                <button type="submit" class="sidebar-signout sidebar-signout--voter">
                    <i class="bi bi-box-arrow-left" aria-hidden="true"></i> Sign out
                </button>
            </form>
            <span class="sidebar-security"><i class="bi bi-shield-check" aria-hidden="true"></i> Secure session</span>
        </div>
    </div>
</aside>
