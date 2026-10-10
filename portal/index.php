<?php
/**
 * portal/index.php — the member portal shell, v2 (REPLACEMENT_MAP row 14).
 *
 * Design: "Afrovanguard Portal v2" (prop `role`: Member / NextGen Vanguard /
 * Learner). The shell is this file plus portal/avp.css and portal/avp.js:
 * a sidebar grouped Home · Work · NextGen Vanguard · Learn · You · More, a
 * sticky header (crumb, who is online, notifications), a drawer under 960px
 * and a five-tab bar on a phone. Each view is one file in portal/views/ and
 * is shown one at a time by the hash (#tasks, #membership …), so rows 15 and
 * 16 replace a view file without touching the shell.
 *
 * Today and Membership are rebuilt to the design (views/today.php,
 * views/membership.php). The other views kept their v1 markup and logic and
 * are restyled through the shell's tokens.
 *
 * Who sees what is decided by the account, never by the design's prop:
 *   org member (@afrovanguard.org.ng or a member role) → Work + Membership
 *   NextGen Vanguard (NgvMember::isVanguard)             → the programme group
 *   everyone else                                        → learning + Account
 *
 * Served at /portal (a real folder, so WordPress never intercepts it).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/lib/community_view.php';

$u = LmsAuth::user();
if (!$u) { header('Location: ' . av_login_url('/portal/')); exit; }

// Community lives inside the portal now (a tab). A ?space= param deep-links to a
// space, and #community opens the tab (see the view switcher below).
$communitySpace = ($_GET['space'] ?? '') !== '' ? preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $_GET['space'])) : '';

$lms        = new LmsRepository();
$courses    = $lms->enrolledCourses((int) $u['id']);
$isOrg      = LmsAuth::isOrgMember($u);            // @afrovanguard.org.ng → member
$canMentor  = LmsAuth::canMentor($u);
$myEntries  = (new DiaryJournal())->mine((int) $u['id']);
$first      = explode(' ', trim((string) $u['name']))[0] ?: 'there';
$roleLabel  = ucfirst((string) $u['role']);
$certs      = count(array_filter($courses, fn($c) => !empty($c['certified'])));
$inProgress = count(array_filter($courses, fn($c) => empty($c['complete'])));
$tag        = $isOrg ? 'Member Portal' : 'Learning';
$showRole   = $isOrg && LmsAuth::rank((string) $u['role']) > LmsAuth::ROLE_RANK['member'];
$accessLevel = (LmsAuth::rank((string) $u['role']) >= LmsAuth::ROLE_RANK['member']) ? $roleLabel : 'Member';
$dues     = $isOrg ? $lms->duesStatus((int) $u['id']) : null;
$duesCsrf = $dues ? av_csrf_token() : '';
$journey  = Levels::progress((int) $u['id']);
$collabCsrf = av_csrf_token();
$upcoming = class_exists('Mentorship') ? Mentorship::upcomingSessions((int) $u['id'], 6) : [];
// Mentorship hours logged (attended sessions, as mentor or mentee) + consistency.
$mentorStats = class_exists('Mentorship') ? Mentorship::memberConsistency((int) $u['id']) : ['held' => 0, 'attended' => 0, 'rate' => null, 'minutes' => 0, 'hours' => 0.0];
$mentorHours = (float) ($mentorStats['hours'] ?? 0);
$mentorHoursLabel = (fmod($mentorHours, 1.0) === 0.0 ? (string) (int) $mentorHours : rtrim(rtrim(number_format($mentorHours, 1), '0'), '.')) . 'h';
if (!function_exists('self_fmt_dur')) {
    function self_fmt_dur(int $m): string { $m = max(0, $m); $h = intdiv($m, 60); $r = $m % 60; return $h ? ($h . 'h' . ($r ? ' ' . $r . 'm' : '')) : ($r . 'm'); }
}
if (!function_exists('self_diary_item')) {
    // One entry row for the portal Diary streams (mirrors portal/diary.js).
    function self_diary_item(array $e): string {
        $icon = ['event' => '📅', 'private' => '🔒', 'public' => '🌐'][$e['kind']] ?? '📝';
        $klabel = ['event' => 'Event', 'private' => 'Private', 'public' => 'Public'][$e['kind']] ?? 'Entry';
        if ($e['kind'] === 'public' || $e['kind'] === 'event') {
            $map = ['pending' => ['Pending review', 'is-pending'], 'approved' => ['Published', 'is-live'], 'rejected' => ['Not approved', 'is-rejected']];
            [$stTxt, $stCls] = $map[$e['status']] ?? ['Logged', 'is-logged'];
        } else { [$stTxt, $stCls] = ['Logged', 'is-logged']; }
        $slug = ($e['status'] === 'approved') ? (string) ($e['published_slug'] ?? '') : '';
        $date = date('M j, Y', strtotime((string) $e['entry_date']) ?: time());
        $h  = '<li class="pd-item" data-id="' . (int) $e['id'] . '">';
        $h .= '<div class="pd-item-top"><span class="pd-kind">' . $icon . ' ' . e($klabel) . '</span><span class="pd-st ' . $stCls . '">' . e($stTxt) . '</span></div>';
        if (($e['title'] ?? '') !== '') $h .= '<p class="pd-item-title">' . e($e['title']) . '</p>';
        $h .= '<p class="pd-item-ex">' . e(DiaryJournal::excerpt((string) $e['body'], 140)) . '</p>';
        $h .= '<div class="pd-item-foot"><time>' . e($date) . '</time>';
        if ($slug !== '') $h .= ' · <a href="/diary/' . e($slug) . '/" target="_blank" rel="noopener">View →</a>';
        $h .= '<button type="button" class="pd-share" data-id="' . (int) $e['id'] . '">Share</button>';
        $h .= '<button type="button" class="pd-del" data-id="' . (int) $e['id'] . '">Delete</button></div></li>';
        return $h;
    }
}
if (!function_exists('self_meet_source')) {
    // How the logged hours were confirmed → a trust label for transparency.
    function self_meet_source(string $src): string {
        switch ($src) {
            case 'meet':    return 'Verified by Google Meet';
            case 'reports': return 'Verified · Meet audit log';
            default:        return 'Provisional · confirming with Google';
        }
    }
}
// KPI seeds (client refreshes online + tasks live).
$myTasks    = $isOrg && class_exists('Collab') ? Collab::myTasks((int) $u['id']) : [];
$openTasks  = count(array_filter($myTasks, fn($t) => empty($t['done'])));
/* The same person's work, kept on the other site. CACENTRE reads this site's
   tasks already; without this the portal showed half a member's day, and
   "what have I got today" answered differently depending on which site they
   asked. Read, never copied — CACENTRE stays the one place a CACENTRE task
   is true, and the link goes back there to work one. Fails soft: if the
   other site is deploying or the shared secret is unset, $cacTasks is empty
   and nothing on this page changes. */
$cacTasks   = $isOrg && class_exists('CacTasks') ? CacTasks::openFor((int) $u['id']) : [];
/* The counts a member reads at a glance have to count the same work the list
   below shows, or the badge says four and the page shows six. The console's
   tasks are the member's tasks; where they are stored is this site's problem,
   not theirs. */
$cacOpen    = count($cacTasks);
$cacDue     = count(array_filter($cacTasks, fn($t) => $t['due'] !== '' && $t['due'] <= gmdate('Y-m-d')));
$onlineNow  = $isOrg && class_exists('Collab') ? Collab::onlineCount() : 0;
// Productivity "Today" aggregates — what genuinely needs attention now.
$todayStr   = gmdate('Y-m-d');
$tasksDue   = array_values(array_filter($myTasks, fn($t) => empty($t['done']) && $t['due'] !== '' && $t['due'] <= $todayStr));
$tasksOverdue = count(array_filter($tasksDue, fn($t) => !empty($t['overdue'])));
$nextSession = $upcoming[0] ?? null; // upcoming is ordered live-first, then soonest
$cPulseToday = class_exists('Community') ? Community::pulse() : ['posts_today' => 0];

$ptheme    = (($_COOKIE['av_portal_theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
$parts     = preg_split('/\s+/', trim((string) $u['name'])) ?: [];
$pInitials = strtoupper(substr((string) ($parts[0] ?? 'A'), 0, 1) . substr((string) ($parts[1] ?? ''), 0, 1)) ?: 'A';

render_head([
    'title'      => ($isOrg ? 'Member portal' : 'Your learning') . ' — Afrovanguard',
    'desc'       => 'Your Afrovanguard portal — learning, and (for members) mentorship and members-only spaces.',
    'canonical'  => rtrim(SITE_URL, '/') . '/portal/',
    'robots'     => 'noindex, nofollow',
    'body_class' => 'portal-app avp' . ($ptheme === 'dark' ? ' is-dark' : ''),
    'css'        => ['/assets/site/av-tokens.css', '/portal/portal.css', '/community/community.css', '/portal/community.css',
                     '/portal/avdy.css', '/academy/ngv/ngv-dashboard.css', '/portal/avng.css',
                     /* The member card (Membership → Your card) and its photo editor. */
                     '/assets/site/avc-card.css', '/assets/site/avc-photo.css',
                     /* Last, so the v2 shell wins over the panel stylesheet. */
                     '/portal/avp.css'],
    'manifest'   => '/manifest.webmanifest',
    'chrome'     => 'app', /* its own shell and theme; not the Home chrome */
]);

/* Nav model — grouped for flow (Home · Work · Learn · You), with dot colours
   + optional live badges. Related productivity tools sit together. */
$postsToday = (int) ($cPulseToday['posts_today'] ?? 0);
$nav = [
    'Home' => [
        ['overview', 'Today', 'gold', ($tasksDue || $nextSession) ? (string) (count($tasksDue) + ($nextSession ? 1 : 0)) : ''],
        ['tools', 'Suite', 'indigo', ''],
        ['community', 'Community', 'green', $postsToday > 0 ? (string) $postsToday : ''],
    ],
];
if ($isOrg) {
    // Team Chat is strictly for @afrovanguard members.
    $nav['Work'] = [
        ['tasks', 'Tasks', 'gold', ($openTasks + $cacOpen) ? (string) ($openTasks + $cacOpen) : ''],
        ['chat', 'Team Chat', 'green', ''],
        ['workspace', 'Workspace', 'gray', ''],
    ];
    /* The centre's register, read here rather than signed across to. It is
       the same access a member already had — inventory is in the console's
       MEMBER_PAGES — minus the journey, which is the whole point: looking up
       where something is took four seconds and getting there took thirty, so
       people stopped asking the system and started asking each other.

       Only when the bridge is configured. A nav entry that leads to "the link
       is not set up" is a worse answer than no entry. */
    if (CacSso::ready()) {
        $nav['Work'][] = ['inventory', 'Inventory', 'gray', ''];
    }
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
if ($isNgv) $nav['NextGen Vanguard'][] = ['attendance', 'Attendance & pass', 'gray', $gateSum && $gateSum['counted'] ? (int) $gateSum['rate'] . '%' : ''];
$nav['Learn'] = [
    ['learning', 'Learning', 'gray', $courses ? (string) count($courses) : ''],
    ['mentorship', 'Mentorship', 'gray', $mentorStats['attended'] ? (string) (int) $mentorStats['attended'] : ''],
];
$nav['You'] = [
    ['diary', 'Diary', 'gray', $myEntries ? (string) count($myEntries) : ''],
    ['membership', ($isOrg ? 'Membership' : 'Account'), 'gray', ''],
];
if ($hasGate && !$isNgv) $nav['You'][] = ['attendance', 'Attendance & pass', 'gray', ''];
/* The phone bar: five tabs, the design's choice for each role, "More" opens the drawer. */
$tabs = [['overview', 'Today']];
$tabs[] = $isOrg ? ['tasks', 'Tasks'] : ($isNgv ? ['ngv', 'Programme'] : ['learning', 'Learning']);
$tabs[] = $isOrg ? ['chat', 'Chat'] : ['community', 'Community'];
$tabs[] = ['diary', 'Diary'];
$avpIcons = [
    'overview'  => 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
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
    <?php /* The way into the command palette (portal/palette.js). A button,
             because it opens a dialog, and it says which key. */ ?>
    <div class="avp-search-wrap">
      <button class="avp-search" type="button" data-cmdk="cmd">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <span class="avp-search-t">Search anything</span>
        <span class="avp-keys"><kbd class="cmdk-mod">Ctrl</kbd><kbd>K</kbd></span>
      </button>
    </div>

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
        <a class="avp-nav-link" href="/academy/"><span class="avp-dot avp-dot--gray" aria-hidden="true"></span><span class="avp-nav-label">Academy</span><span class="avp-ext" aria-hidden="true">↗</span></a>
        <a class="avp-nav-link" href="<?= e(rtrim(SITE_URL, '/')) ?>/"><span class="avp-dot avp-dot--gray" aria-hidden="true"></span><span class="avp-nav-label">Main site</span><span class="avp-ext" aria-hidden="true">↗</span></a>
        <a class="avp-nav-link" href="/diary/me/"><span class="avp-dot avp-dot--gray" aria-hidden="true"></span><span class="avp-nav-label">Your Diary page</span><span class="avp-ext" aria-hidden="true">↗</span></a>
<?php /* Shown to org members when the bridge is configured. A filter, not
         the gate: CACENTRE decides on every arrival who may use it. */ ?>
<?php if ($isOrg && CacSso::ready()): ?>        <a class="avp-nav-link" href="<?= e(CacSso::DOOR) ?>"><span class="avp-dot avp-dot--gray" aria-hidden="true"></span><span class="avp-nav-label">CACENTRE workspace</span><span class="avp-ext" aria-hidden="true">↗</span></a>
<?php endif; ?>
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
<?php if ($isOrg): ?>        <span class="avp-online" id="topOnline"<?= $onlineNow > 0 ? '' : ' hidden' ?>><span class="avp-online-dot" aria-hidden="true"></span><span><span class="av-num" id="tbCount"><?= (int) $onlineNow ?></span> online</span></span>
<?php endif; ?>        <div class="avp-notif" id="notifWrap" data-csrf="<?= e($collabCsrf) ?>">
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
        // Precompute view data used across views.
        $stageCode = (string) ($journey['level'] ?? 'O');
        $duesState = $dues ? (string) $dues['state'] : 'none';
        $duesVal   = $dues ? ((!empty($dues['lifetime'])) ? 'Lifetime' : ($duesState === 'active' ? 'Current' : ($duesState === 'none' ? 'Not paid' : ucfirst(str_replace('_', ' ', $duesState))))) : '—';
        $duesTotal = $dues ? (int) ($dues['total_paid_ngn'] ?? 0) : 0;
        $jOrder = $journey['order']; $jHere = array_search($journey['level'], $jOrder, true);
        // Progress toward the next level tracks ACTIVE mentees (pairings that
        // actually meet), which is what the engine tests.
        $jPct = min(100, (int) round(100 * ($journey['active_mentees'] ?? 0) / max(1, (int) ($journey['mentees_needed'] ?? 2))));
        $jBase = class_exists('Levels') ? Levels::base() : 'O';
        $jNextLabel = (string) ($journey['next_label'] ?? 'the next level');

        require __DIR__ . '/views/today.php';
        require __DIR__ . '/views/tools.php';
        if ($isOrg) {
            require __DIR__ . '/views/tasks.php';
            require __DIR__ . '/views/chat.php';
        }
        require __DIR__ . '/views/learning.php';
        require __DIR__ . '/views/community.php';
        require __DIR__ . '/views/mentorship.php';
        if ($isOrg) {
            if (CacSso::ready()) require __DIR__ . '/views/inventory.php';
            require __DIR__ . '/views/workspace.php';
        }
        if ($isNgv) require __DIR__ . '/views/ngv.php';
?>
<?php if ($hasGate || $isNgv): ?>
      <section class="pview avp-view" id="view-attendance" data-view="attendance" hidden>
<?php require __DIR__ . '/_attendance.php'; ?>
      </section>
<?php endif; ?>
<?php
        require __DIR__ . '/views/membership.php';
        require __DIR__ . '/views/diary.php';
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
<?php if ($isOrg) require __DIR__ . '/views/meet-modal.php'; ?>

  <script src="/portal/avp.js" defer></script>
  <script src="/portal/tasks.js" defer></script>
  <script src="/portal/chat-panel.js" defer></script>
  <script src="/portal/meet-modal.js" defer></script>
  <script src="/portal/dues-pay.js" defer></script>
  <script src="/portal/workspace-live.js" defer></script>
  <script src="/portal/mentor-meet.js" defer></script>
  <script src="/portal/team-chat.js" defer></script>
  <script src="/portal/tools.js" defer></script>
  <script src="/portal/meetings.js" defer></script>
  <script src="/portal/notifications.js" defer></script>
  <script src="/portal/directory.js" defer></script>
  <script src="/portal/inventory.js" defer></script>
  <script src="/portal/leads.js" defer></script>
  <?php /* The palette reads the sidebar rather than being handed a second copy
           of it. Panes are hash links, and the portal already listens for
           hashchange, so going to one is the same as clicking it. */ ?>
  <script type="application/json" id="cmdk-data"><?= json_encode([
      'navFrom' => '.pnav-link[data-view]',
      'actions' => array_values(array_filter([
          ['label' => 'New task',  'href' => '/portal/#tasks',     'sub' => 'Something to be done'],
          ['label' => 'Write in the diary', 'href' => '/portal/#diary', 'sub' => 'Today, in your own words'],
          $isOrg && CacSso::ready()
              ? ['label' => 'Look something up in the register', 'href' => '/portal/#inventory',
                 'sub' => 'Where it is, and who has it']
              : null,
      ])),
      'find'    => '/portal/palette.php',
  ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script src="/portal/palette.js" defer></script>
  <script src="/community/community.js" defer></script>
  <script src="/portal/avdy.js" defer></script>
  <script src="/portal/commitments.js" defer></script>
  <script src="/assets/site/avc-photo.js" defer></script>
  <script src="/portal/card-photo.js" defer></script>
  <script src="/assets/site/celebrations.js" defer></script>
  <script src="/assets/site/nav.js" defer></script>
</body>
</html>
