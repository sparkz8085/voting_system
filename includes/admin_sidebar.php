<?php
if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Forbidden.');
}
$admin = $admin ?? Auth::requireAdmin();
$activeNav = $activeNav ?? '';
$navItems = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'href' => 'admin/dashboard.php'],
    ['key' => 'elections', 'label' => 'Elections', 'icon' => 'bi-calendar2-week', 'href' => 'admin/elections.php'],
    ['key' => 'candidates', 'label' => 'Candidates', 'icon' => 'bi-people', 'href' => 'admin/candidates.php'],
    ['key' => 'voters', 'label' => 'Voters', 'icon' => 'bi-person-vcard', 'href' => 'admin/voters.php'],
    ['key' => 'results', 'label' => 'Results', 'icon' => 'bi-bar-chart-line', 'href' => 'admin/results.php'],
];
?>
<div class="sidebar-backdrop" data-sidebar-close aria-hidden="true"></div>
<aside class="app-sidebar app-sidebar--admin" id="appSidebar" aria-label="Administrator navigation">
    <div class="sidebar-brand-row">
        <a class="brand brand--sidebar" href="<?= e(url('admin/dashboard.php')) ?>">
            <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="38" height="38">
            <span><?= e(APP_NAME) ?><small>Election office</small></span>
        </a>
        <button type="button" class="sidebar-close d-lg-none" data-sidebar-close aria-label="Close navigation">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>

    <div class="sidebar-context">
        <span class="sidebar-context__label">Workspace</span>
        <span class="sidebar-context__value"><span class="status-dot status-dot--green"></span> Administration</span>
    </div>

    <nav class="sidebar-nav">
        <div class="sidebar-nav__group">
            <span class="sidebar-nav__title">Overview</span>
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
            <span class="sidebar-nav__title">Governance</span>
            <a class="sidebar-link<?= $activeNav === 'activity' ? ' active' : '' ?>" href="<?= e(url('admin/activity.php')) ?>"
               <?= $activeNav === 'activity' ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-journal-text" aria-hidden="true"></i><span>Activity log</span>
            </a>
            <a class="sidebar-link<?= $activeNav === 'profile' ? ' active' : '' ?>" href="<?= e(url('admin/profile.php')) ?>"
               <?= $activeNav === 'profile' ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-person-gear" aria-hidden="true"></i><span>My profile</span>
            </a>
        </div>

        <div class="sidebar-nav__group">
            <span class="sidebar-nav__title">Public site</span>
            <a class="sidebar-link" href="<?= e(url()) ?>" target="_blank" rel="noopener">
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span>Open landing page</span>
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-profile">
            <?= avatar_markup((string) $admin['full_name'], null, 'avatar--sm') ?>
            <div class="sidebar-profile__meta">
                <strong><?= e($admin['full_name']) ?></strong>
                <span><?= e($admin['email']) ?></span>
            </div>
        </div>
        <div class="sidebar-footer__actions">
            <form method="post" action="<?= e(url('auth/logout.php')) ?>" class="w-100">
                <?= csrf_field() ?>
                <button type="submit" class="sidebar-signout">
                    <i class="bi bi-box-arrow-left" aria-hidden="true"></i> Sign out
                </button>
            </form>
            <span class="sidebar-security"><i class="bi bi-shield-check" aria-hidden="true"></i> Session protected</span>
        </div>
    </div>
</aside>
