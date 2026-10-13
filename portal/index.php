<?php
/**
 * portal/index.php — the member portal shell.
 *
 * The portal is the Volunteer dashboard and Membership, nothing else (owner,
 * 2026-10-13): one place volunteers come back to, rather than a dozen tabs
 * of tools they did not use. Who sees what is decided by the account:
 *   a volunteer (Volunteers::isVolunteer)     → the dashboard, their home
 *   a NextGen Vanguard (NgvMember::isVanguard) → the programme, fees, fines
 *   everyone                                   → Membership
 * The shell is this file plus portal/avp.css and portal/avp.js: a sidebar, a
 * sticky header (crumb, notifications), a drawer under 960px and a tab bar
 * on a phone. Each view is one file in portal/views/, shown by the hash; the
 * first view drawn is home.
 *
 * Served at /portal (a real folder, so WordPress never intercepts it).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/lib/community_view.php';

$u = LmsAuth::user();
if (!$u) { header('Location: ' . av_login_url('/portal/')); exit; }

$lms        = new LmsRepository();
$isOrg      = LmsAuth::isOrgMember($u);            // @afrovanguard.org.ng → member
$first      = explode(' ', trim((string) $u['name']))[0] ?: 'there';
$roleLabel  = ucfirst((string) $u['role']);
$tag        = $isOrg ? 'Member Portal' : 'Member';
$accessLevel = (LmsAuth::rank((string) $u['role']) >= LmsAuth::ROLE_RANK['member']) ? $roleLabel : 'Member';
$dues     = $isOrg ? $lms->duesStatus((int) $u['id']) : null;
$duesCsrf = $dues ? av_csrf_token() : '';
$collabCsrf = av_csrf_token();

$ptheme    = (($_COOKIE['av_portal_theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
$parts     = preg_split('/\s+/', trim((string) $u['name'])) ?: [];
$pInitials = strtoupper(substr((string) ($parts[0] ?? 'A'), 0, 1) . substr((string) ($parts[1] ?? ''), 0, 1)) ?: 'A';

render_head([
    'title'      => 'Member portal — Afrovanguard',
    'desc'       => 'Your Afrovanguard portal — the Volunteer dashboard and your membership.',
    'canonical'  => rtrim(SITE_URL, '/') . '/portal/',
    'robots'     => 'noindex, nofollow',
    'body_class' => 'portal-app avp' . ($ptheme === 'dark' ? ' is-dark' : ''),
    'css'        => ['/assets/site/av-tokens.css', '/portal/portal.css',
                     '/academy/ngv/ngv-dashboard.css', '/portal/avng.css',
                     /* The member card (Membership → Your card) and its photo editor. */
                     '/assets/site/avc-card.css', '/assets/site/avc-photo.css',
                     /* Last, so the v2 shell wins over the panel stylesheet. */
                     '/portal/avp.css', '/portal/volunteer.css'],
    'manifest'   => '/manifest.webmanifest',
    'chrome'     => 'app', /* its own shell and theme; not the Home chrome */
]);

/* Nav: the dashboard, the programme (vanguards), and You. */
$nav = [];
$isVol = class_exists('Volunteers') && Volunteers::isVolunteer($u);
if ($isVol) {
    $volWaiting = Volunteers::waitingCount($u);
    $nav['Volunteer'] = [['volunteer', 'Dashboard', $volWaiting > 0 ? 'gold' : 'green', $volWaiting > 0 ? (string) $volWaiting : '']];
}
/* NextGen Vanguard — the programme dashboard, here rather than a link away.
   For vanguards only (NgvMember::isVanguard: their NGV record or ID card,
   not their email). Its data and content are the same files the standalone
   page uses, so the two cannot drift. */
$isNgv = class_exists('NgvMember') && NgvMember::isVanguard((int) $u['id']);
if ($isNgv) {
    /* Read in its own scope: the dashboard's names ($myEntries, $csrf, $p…)
       are the portal's too, and must not overwrite them. */
    $ngvVars = (static function (array $u): array {
        $c = Ngv::get();
        require dirname(__DIR__) . '/academy/ngv/_dashboard-data.php';
        return get_defined_vars();
    })($u);
    $ngvOwed = (int) ($ngvVars['account']['payable'] ?? 0);
    $ngvA = $ngvVars['account'];
    $nav['NextGen Vanguard'] = [
        ['ngv', 'Programme', 'gold', $ngvVars['booksRead'] . '/' . $ngvVars['BOOKS_TOTAL']],
        ['ngv-account', 'Fees & account', $ngvOwed > 0 ? 'gold' : 'green', $ngvOwed > 0 ? '₦' . number_format($ngvOwed) : ''],
        ['ngv-fines', 'Fines', $ngvVars['myFines']['owing'] > 0 ? 'gold' : 'gray', $ngvVars['myFines']['owing'] > 0 ? '₦' . number_format((int) $ngvVars['myFines']['owing']) : ''],
    ];
}
/* The gate, in the portal: the pass, the card, the days. For a vanguard it
   sits with the programme it is part of; for anybody else, under "You". */
/* Every member the gate takes — including one it is refusing today, who is
   the person most in need of seeing why. */
$hasGate = GatePass::ready() && in_array(strtolower((string) ($u['role'] ?? '')), GatePass::roles(), true);
$gateWhy = $hasGate ? (GatePass::whyNot($u) ?? '') : '';
$gateSum = $hasGate || $isNgv ? GateAttendance::summary((int) $u['id'], 30) : null;
/* Attendance lives in the dashboard for a volunteer; a vanguard or gate
   member who is not one keeps it as its own view. */
$attView = ($hasGate || $isNgv) && !$isVol;
if ($isNgv && $attView) $nav['NextGen Vanguard'][] = ['attendance', 'Attendance & pass', 'gray', $gateSum && $gateSum['counted'] ? (int) $gateSum['rate'] . '%' : ''];
$nav['You'] = [['membership', ($isOrg ? 'Membership' : 'Account'), 'gray', '']];
if ($attView && !$isNgv) $nav['You'][] = ['attendance', 'Attendance & pass', 'gray', ''];
/* The phone bar: home first, then the programme and Membership; "More" opens the drawer. */
$tabs = [];
if ($isVol) $tabs[] = ['volunteer', 'Dashboard'];
if ($isNgv) $tabs[] = ['ngv', 'Programme'];
if ($attView) $tabs[] = ['attendance', 'Attendance'];
$tabs[] = ['membership', $isOrg ? 'Membership' : 'Account'];
$avpIcons = [
    'overview'  => 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
    'volunteer' => 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
    'membership'=> 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
    'attendance'=> 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM9 16l2 2 4-4',
    'tasks'     => 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
    'chat'      => 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
    'community' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    'ngv'       => 'M4 22V4M4 4h13l-2 4 2 4H4',
    'learning'  => 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5zM4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5',
    'diary'     => 'M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z',
    'more'      => 'M5 12h.01M12 12h.01M19 12h.01',
];
$roleName = $isNgv ? 'NextGen Vanguard' : ($isOrg ? e($accessLevel) : 'Learner');
?>
<div class="avp-app">

  <aside class="avp-side" id="avpSide" aria-label="Portal navigation">
    <a class="avp-brand" href="<?= e(rtrim(SITE_URL, '/')) ?>/" aria-label="Afrovanguard home">
      <span class="avp-mark" aria-hidden="true">A</span>
      <span class="avp-brand-t"><span class="avp-brand-n">Afrovanguard</span><span class="avp-brand-s"><?= e($tag) ?></span></span>
    </a>

    <nav class="avp-nav" aria-label="Sections">
<?php foreach ($nav as $group => $items): ?>
      <div class="avp-nav-group">
        <div class="avp-nav-title"><?= e($group) ?></div>
<?php foreach ($items as [$id, $label, $dot, $badge]): ?>
        <a class="avp-nav-link pnav-link" href="#<?= e($id) ?>" data-view="<?= e($id) ?>">
          <span class="avp-dot avp-dot--<?= e($dot) ?>" aria-hidden="true"></span>
          <span class="avp-nav-label pnav-label"><?= e($label) ?></span>
<?php if ($badge !== ''): ?>          <span class="avp-nav-badge pnav-badge"><?= e($badge) ?></span>
<?php endif; ?>        </a>
<?php endforeach; ?>
      </div>
<?php endforeach; ?>
      <div class="avp-nav-group">
        <div class="avp-nav-title">More</div>
        <a class="avp-nav-link" href="<?= e(rtrim(SITE_URL, '/')) ?>/"><span class="avp-dot avp-dot--gray" aria-hidden="true"></span><span class="avp-nav-label">Main site</span><span class="avp-ext" aria-hidden="true">↗</span></a>
      </div>
    </nav>

    <div class="avp-me">
      <span class="avp-me-av" aria-hidden="true"><?= e($pInitials) ?></span>
      <span class="avp-me-t"><span class="avp-me-n"><?= e(trim($first . ' ' . ($parts[1] ?? ''))) ?></span><span class="avp-me-r"><?= $roleName ?></span></span>
      <button type="button" class="avp-icon-btn" id="portalTheme" aria-pressed="<?= $ptheme === 'dark' ? 'true' : 'false' ?>" aria-label="Dark mode" title="Dark mode">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
      </button>
      <a class="avp-icon-btn" href="#" data-logout title="Sign out" aria-label="Sign out">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
      </a>
    </div>
  </aside>
  <div class="avp-scrim" id="avpScrim" hidden></div>

  <main class="avp-main" id="main-content" tabindex="-1">
    <header class="avp-top">
      <button type="button" class="avp-icon-btn avp-burger" id="avpBurger" aria-label="Open navigation" aria-controls="avpSide" aria-expanded="false">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
      </button>
      <div class="avp-crumb"><span class="avp-crumb-root">Portal</span><span class="avp-crumb-sep" aria-hidden="true">/</span><span class="avp-crumb-here" id="crumbHere" aria-live="polite">Today</span></div>
      <div class="avp-top-actions">
        <div class="avp-notif" id="notifWrap" data-csrf="<?= e($collabCsrf) ?>">
          <button type="button" class="avp-bell" id="notifBtn" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" aria-controls="notifPanel">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg>
            <span class="avp-bell-n" id="notifBadge" hidden>0</span>
          </button>
          <div class="avp-notif-panel" id="notifPanel" hidden role="dialog" aria-label="Notifications">
            <div class="avp-notif-head"><span>Notifications</span><button type="button" class="avp-link-btn" id="notifReadAll">Mark all read</button></div>
            <div class="avp-notif-list notif-list" id="notifList"><p class="notif-empty">Loading…</p></div>
          </div>
        </div>
      </div>
    </header>

    <div class="avp-page">
<?php
        $duesState = $dues ? (string) $dues['state'] : 'none';
        $duesTotal = $dues ? (int) ($dues['total_paid_ngn'] ?? 0) : 0;
        /* Home first: avp.js treats the first view drawn as home. */
        if ($isVol) require __DIR__ . '/views/volunteer.php';
        if ($isNgv) require __DIR__ . '/views/ngv.php';
        if ($attView): ?>
      <section class="pview avp-view" id="view-attendance" data-view="attendance" hidden>
<?php require __DIR__ . '/_attendance.php'; ?>
      </section>
<?php endif;
        require __DIR__ . '/views/membership.php';
?>
      <footer class="avp-foot">
        <span>© 2026 Afrovanguard</span>
        <span><a href="<?= e(rtrim(SITE_URL, '/')) ?>/">Main site ↗</a> · <a href="mailto:cacentre@afrovanguard.org.ng">Support</a></span>
      </footer>
    </div>
  </main>

  <nav class="avp-tabs" aria-label="Primary">
<?php foreach ($tabs as [$id, $label]): ?>
    <a class="avp-tab" href="#<?= e($id) ?>" data-view="<?= e($id) ?>">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= e($avpIcons[$id]) ?>"/></svg>
      <span><?= e($label) ?></span>
    </a>
<?php endforeach; ?>
    <button type="button" class="avp-tab" id="avpMore" aria-controls="avpSide" aria-expanded="false">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= e($avpIcons['more']) ?>"/></svg>
      <span>More</span>
    </button>
  </nav>
</div>

  <script src="/portal/avp.js" defer></script>
  <script src="/portal/dues-pay.js" defer></script>
  <script src="/portal/notifications.js" defer></script>
<?php if ($isVol): ?>  <script src="/assets/vendor/sortable/Sortable.min.js" defer></script>
  <script src="/portal/volunteer.js" defer></script>
<?php endif; ?>
  <script src="/assets/site/avc-photo.js" defer></script>
  <script src="/portal/card-photo.js" defer></script>
  <script src="/assets/site/celebrations.js" defer></script>
  <script src="/assets/site/nav.js" defer></script>
</body>
</html>
