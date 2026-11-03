<?php
/**
 * portal/index.php — the member portal shell, v2 (REPLACEMENT_MAP row 14).
 *
 * Design: "Afrovanguard Portal v2" (prop `role`: Member / NextGen Vanguard /
 * Learner). The shell is this file plus portal/avp.css and portal/avp.js:
 * a sidebar grouped Home · Work · Vanguard Quest · NextGen Vanguard · Learn ·
 * You · More, a sticky header (crumb, who is online, notifications), a drawer
 * under 960px and a five-tab bar on a phone. Each view is one file in
 * portal/views/ and is shown one at a time by the hash (#tasks, #membership …).
 * Which sections exist, their group and label, who sees them and which view
 * draws them is lib/PortalNav.php — the sidebar, the views and the tab bar are
 * all generated from it. Adding a section is one entry there and a view file.
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
/* Google Workspace is part of the portal, not a page beside it (lib/GoogleHub.php):
   what it can do for this person, read once and used by every view. */
$google = GoogleHub::state($u);
$googleNotice = GoogleHub::notice($_GET);
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

/* Light, dark, or Auto (follow the phone) — the More sheet's Appearance switch. */
$ptheme    = in_array($_COOKIE['av_portal_theme'] ?? 'light', ['light', 'dark', 'auto'], true) ? (string) $_COOKIE['av_portal_theme'] : 'light';
$parts     = preg_split('/\s+/', trim((string) $u['name'])) ?: [];
$pInitials = strtoupper(substr((string) ($parts[0] ?? 'A'), 0, 1) . substr((string) ($parts[1] ?? ''), 0, 1)) ?: 'A';

render_head([
    'title'      => ($isOrg ? 'Member portal' : 'Your learning') . ' — Afrovanguard',
    'desc'       => 'Your Afrovanguard portal — learning, and (for members) mentorship and members-only spaces.',
    'canonical'  => rtrim(SITE_URL, '/') . '/portal/',
    'robots'     => 'noindex, nofollow',
    'body_class' => 'portal-app avp' . ($ptheme === 'dark' ? ' is-dark' : ($ptheme === 'auto' ? ' is-auto' : '')),
    'css'        => ['/assets/site/av-tokens.css', '/portal/portal.css', '/community/community.css', '/portal/community.css',
                     '/portal/avdy.css', '/academy/ngv/ngv-dashboard.css', '/portal/avng.css',
                     /* The member card (Membership → Your card) and its photo editor. */
                     '/assets/site/avc-card.css', '/assets/site/avc-photo.css',
                     /* Last, so the v2 shell wins over the panel stylesheet. */
                     '/portal/avp.css', '/portal/volunteer.css', '/portal/todo-pop.css',
                     /* Today, Home v4: its own components (hv-), after the shell's. */
                     '/portal/home.css', '/portal/shell.css'],
    'manifest'   => '/manifest.webmanifest',
    'chrome'     => 'app', /* its own shell and theme; not the Home chrome */
]);

/* What this account opens. The sections themselves — their group, label and
   view — are lib/PortalNav.php; the shell only works out who is signed in and
   what each badge says. */
$postsToday = (int) ($cPulseToday['posts_today'] ?? 0);
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
}
/* The gate, in the portal: the pass, the card, the days — for every member
   the gate takes, including one it is refusing today, who is the person most
   in need of seeing why. */
$hasGate = GatePass::ready() && GatePass::roleAllowed($u);
$gateWhy = $hasGate ? (GatePass::whyNot($u) ?? '') : '';
$gateSum = $hasGate || $isNgv ? GateAttendance::summary((int) $u['id'], 30) : null;
/* Volunteering — only for volunteers (lib/Volunteers: made one by an admin,
   on a team, an NGG volunteer, or on the People page) and the coordinators
   who run them. Being a member is not being a volunteer. */
$isVol = class_exists('Volunteers') && Volunteers::canOpenDashboard($u);
$volWaiting = $isVol ? Volunteers::waitingCount($u) : 0;
$mreqWaiting = class_exists('MemberRequests') ? count(array_filter(MemberRequests::mine((int) $u['id']), static fn($r) => $r['status'] === 'pending')) : 0;
/* The register is read here only when the bridge is configured: a nav entry
   that leads to "the link is not set up" is a worse answer than no entry. */
/* Fines for a member outside NGV (a vanguard's are under NextGen Vanguard):
   shown once they have had one. */
$myFines = !$isNgv && class_exists('NgvFines') ? NgvFines::forMember((int) $u['id']) : ['fines' => [], 'owing' => 0, 'open' => 0];
/* Reading is open beyond the programme: a verified member or a volunteer
   reads on the same record (NgvReading::mayRead). A vanguard's card is
   already inside their Programme view, so they do not get a second section. */
$mayRead = !$isNgv && class_exists('NgvReading') && NgvReading::mayRead((int) $u['id']);
$portalCtx = ['org' => $isOrg, 'ngv' => $isNgv, 'vol' => $isVol, 'gate' => $hasGate, 'reading' => $mayRead, 'inventory' => $isOrg && CacSso::ready(),
              'fines' => (bool) $myFines['fines']];
/* Quest: the month's points against the level's ceiling, when it is on. */
$questMonth = QuestPoints::earnedInMonth((int) $u['id'], gmdate('Y-m'));
/* The wallet: the member's money from every ledger, read once (the badge and the view). */
$myStatement = MemberStatement::for((int) $u['id']);
$walletOwed = max(0, (int) $myStatement['balance_kobo']);
/* The member's bills, read once: the badge, Today's list and the Bills view use them. */
$myBills = Conduct::billsFor((int) $u['id']);
$billsUnanswered = array_values(array_filter($myBills, static fn($c) => $c['status'] === 'proposed' && (string) $c['response'] === ''));
$billsWaiting = count($billsUnanswered);
/* Approved, not appealed, and the appeal window closing within three days. */
$billsClosing = array_values(array_filter($myBills, static function ($c): bool {
    if ($c['appeal_id']) return false;
    $until = Conduct::appealOpenUntil($c);
    return $until !== '' && $until <= gmdate('Y-m-d', time() + 3 * 86400);
}));
/* ── What each section says beside its name (Home v4) ─────────────────
   id => [count, chip, chip tone]. Status is a word first: the chip says it
   ("1 late", "Due", "₦5,000"), and its colour only confirms — red late,
   amber owing, gold yours, green fine. A count is quiet grey. */
$hvMoney = static fn(int $n): string => '₦' . number_format($n);
$navStatus = [
    'overview'    => [($tasksDue || $nextSession) ? (string) (count($tasksDue) + ($nextSession ? 1 : 0)) : '', '', ''],
    'community'   => [$postsToday > 0 ? (string) $postsToday : '', '', ''],
    'tasks'       => [($openTasks + $cacOpen) ? (string) ($openTasks + $cacOpen) : '', $tasksOverdue ? $tasksOverdue . ' late' : '', 'bad'],
    'quest'       => ['', $questMonth > 0 ? '+' . $questMonth : '', 'you'],
    /* A bill nobody has answered is the one thing on the Quest that waits on the member. */
    'bills'       => ['', $billsWaiting ? $billsWaiting . ' to answer' : '', 'bad'],
    'mentorship'  => [$mentorStats['attended'] ? (string) (int) $mentorStats['attended'] : '', '', ''],
    'attendance'  => [$gateSum && $gateSum['counted'] ? (int) $gateSum['rate'] . '%' : '', $gateWhy !== '' ? 'Refused' : '', 'bad'],
    'fines'       => ['', $myFines['owing'] > 0 ? $hvMoney((int) $myFines['owing']) : '', 'warn'],
    'reading'     => [$mayRead ? NgvReading::verifiedCount((int) $u['id']) . ' / ' . NgvReading::TOTAL : '', '', ''],
    'learning'    => [$courses ? (string) count($courses) : '', '', ''],
    'volunteer'   => ['', $volWaiting > 0 ? $volWaiting . ' waiting' : '', 'info'],
    'diary'       => [$myEntries ? (string) count($myEntries) : '', '', ''],
    'wallet'      => ['', $walletOwed > 0 ? MemberStatement::naira($walletOwed) : '', 'warn'],
    'membership'  => [$mreqWaiting ? (string) $mreqWaiting : '', $dues && ($dues['state'] ?? '') !== 'active' && empty($dues['lifetime']) ? 'Due' : '', 'warn'],
];
if ($isNgv) {
    $navStatus['ngv'] = [$ngvVars['booksRead'] . ' / ' . $ngvVars['BOOKS_TOTAL'], '', ''];
    $navStatus['ngv-account'] = ['', $ngvOwed > 0 ? $hvMoney($ngvOwed) : 'Clear', $ngvOwed > 0 ? 'warn' : 'ok'];
    $fOwing = (int) $ngvVars['myFines']['owing'];
    $navStatus['ngv-fines'] = ['', $fOwing > 0 ? $hvMoney($fOwing) : '', 'warn'];
}
$nav = [];
foreach (PortalNav::groups($portalCtx) as $group => $items) {
    foreach ($items as [$id, $label]) $nav[$group][] = [$id, $label] + [2 => $navStatus[$id] ?? ['', '', '']];
}
$tabs = PortalNav::tabs($portalCtx);
$navLabels = [];
foreach ($nav as $items) foreach ($items as [$id, $label]) $navLabels[$id] = $label;
$navShort = PortalNav::shortcuts($portalCtx, array_filter(explode(',', class_exists('Prefs') ? Prefs::get((int) $u['id'], 'nav_shortcuts', '') : '')));
$roleName = $isNgv ? 'NextGen Vanguard' : ($isOrg ? e($accessLevel) : 'Learner');
/* The ring round the member in the sheet: the way to the next level, as on Today. */
$navNeed = $journey['next_points'] ?? null;
$navLevelPct = $journey['next'] === null ? 100 : ($navNeed !== null && (int) $navNeed > 0 ? min(100, (int) round(100 * max(0, (int) ($journey['quest_points'] ?? 0)) / (int) $navNeed)) : 0);
$navPhoto = '';
if (class_exists('CardPhoto')) { try { $navPhoto = CardPhoto::deliver((string) (CardPhoto::of((int) $u['id'])['photo'] ?? '')); } catch (Throwable $e) {} }
/* One icon, stroked, from the registry. */
$navIco = static fn(string $id, int $s = 18): string => '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . e(PortalNav::icon($id)) . '"/></svg>';
/* The end of a row: a quiet count and/or a chip, both inside .pnav-badge so the
   command palette strips them from the label it matches on. */
$navEnd = static function (array $st): string {
    [$n, $chip, $tone] = $st;
    if ($n === '' && $chip === '') return '';
    return '<span class="pnav-badge avn-end">' . ($n !== '' ? '<span class="avn-n">' . e($n) . '</span>' : '')
        . ($chip !== '' ? '<span class="avn-chip avn-chip--' . e($tone ?: 'n') . '">' . e($chip) . '</span>' : '') . '</span>';
};
?>
<?php if ($ptheme === 'auto'): ?>
<script>/* Auto: follow the device before the first paint, so a dark phone never flashes light. */
if (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) document.body.classList.add('is-dark');</script>
<?php endif; ?>
<div class="avp-app">

  <aside class="avp-side avn-side" id="avpSide" aria-label="Portal navigation">
    <a class="avn-brand" href="#overview" data-view="overview" aria-label="Afrovanguard — Today">
      <img src="/assets/site/av-seal-96.png" alt="" width="32" height="32">
      <span class="avn-brand-t">Afrovanguard</span>
    </a>
    <?php /* The way into the command palette (portal/palette.js). A button,
             because it opens a dialog, and it says which key: "/" from
             anywhere, or Ctrl/⌘ K, which the script names correctly on a Mac. */ ?>
    <button class="avp-search avn-search" type="button" data-cmdk="cmd">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
      <span class="avn-search-t">Search or jump</span><kbd class="avn-kbd" title="or Ctrl K">/</kbd><span class="avn-sr">, or <kbd class="cmdk-mod">Ctrl</kbd> <kbd>K</kbd></span>
    </button>

    <nav class="avp-nav avn-nav" aria-label="Sections">
<?php foreach ($nav as $group => $items): $gid = 'avnG' . preg_replace('/[^a-z]/', '', strtolower($group)); $titled = !in_array($group, PortalNav::UNTITLED, true); ?>
      <div class="avn-group" data-group="<?= e($group) ?>">
<?php if ($titled): ?>
        <button type="button" class="avn-gh" aria-expanded="true" aria-controls="<?= e($gid) ?>"><?= e($group) ?><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>
<?php endif; ?>
        <div class="avn-items" id="<?= e($gid) ?>">
<?php foreach ($items as [$id, $label, $st]): ?>
          <a class="avp-nav-link pnav-link avn-link" href="#<?= e($id) ?>" data-view="<?= e($id) ?>"><?= $navIco($id) ?><span class="avp-nav-label pnav-label"><?= e($label) ?></span><?= $navEnd($st) ?></a>
<?php endforeach; ?>
        </div>
      </div>
<?php endforeach; ?>
      <div class="avn-group" data-group="Elsewhere">
        <button type="button" class="avn-gh" aria-expanded="false" aria-controls="avnGelsewhere" data-default-closed>Elsewhere<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>
        <div class="avn-items" id="avnGelsewhere" hidden>
          <a class="avn-link" href="/academy/"><?= $navIco('learning') ?><span>Academy</span><span class="avn-ext" aria-hidden="true">↗</span></a>
          <a class="avn-link" href="/diary/me/"><?= $navIco('diary') ?><span>Your Diary page</span><span class="avn-ext" aria-hidden="true">↗</span></a>
<?php /* Shown to org members when the bridge is configured. A filter, not
         the gate: CACENTRE decides on every arrival who may use it. */ ?>
<?php if ($isOrg && CacSso::ready()): ?>          <a class="avn-link" href="<?= e(CacSso::DOOR) ?>"><?= $navIco('tools') ?><span>CACENTRE workspace</span><span class="avn-ext" aria-hidden="true">↗</span></a>
<?php endif; ?>
        </div>
      </div>
    </nav>

<?php if ($google['oauth'] || ($google['admin'] && $google['problem'] !== '')): ?>
    <!-- The Google account this portal works with — connect, or who it is and a way out. -->
    <div class="avp-google avn-google" id="avpGoogle">
<?php if ($google['connected']): ?>
      <span class="avp-g-dot" aria-hidden="true"></span>
      <span class="avp-g-t"><span class="avp-g-l"><?= $google['mode'] === 'delegated' ? 'Google Workspace' : 'Google connected' ?></span><span class="avp-g-e"><?= e($google['email']) ?></span></span>
<?php if ($google['mode'] !== 'delegated'): /* Through the organisation's Workspace there is nothing of theirs to disconnect. */ ?>
      <form method="post" action="/auth/google/disconnect" class="avp-g-out" onsubmit="return confirm('Disconnect Google from the portal? Your mail, calendar and Chat stop showing here.')">
        <input type="hidden" name="csrf" value="<?= e($collabCsrf) ?>"><button type="submit" class="avp-g-btn">Disconnect</button></form>
<?php endif; ?>
<?php elseif ($google['oauth']): ?>
      <a class="avp-g-connect" href="<?= e($google['connect']) ?>"><span class="avp-g-logo" aria-hidden="true">G</span> Connect Google <span class="avp-g-sub">mail, calendar, Drive, Chat</span></a>
<?php else: ?>
      <a class="avp-g-warn" href="/admin/#rules" title="<?= e($google['problem']) ?>">⚠ Google needs setting up</a>
<?php endif; ?>
    </div>
<?php endif; ?>
    <div class="avn-foot">
      <a href="#membership" data-view="membership" class="avn-foot-a">Settings</a>
      <a href="<?= e(rtrim(SITE_URL, '/')) ?>/" class="avn-foot-a">Main site ↗</a>
      <button type="button" class="avn-icon" id="portalTheme" aria-pressed="<?= $ptheme === 'dark' ? 'true' : 'false' ?>" aria-label="Dark mode" title="Dark mode">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
      </button>
      <a class="avn-icon" href="#" data-logout title="Sign out" aria-label="Sign out">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
      </a>
    </div>
  </aside>
  <div class="avp-scrim" id="avpScrim" hidden></div>

  <main class="avp-main" id="main-content" tabindex="-1">
    <header class="avp-top avn-top">
      <button type="button" class="avp-icon-btn avp-burger avn-burger" id="avpBurger" aria-label="Open navigation" aria-controls="avpSide" aria-expanded="false">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
      </button>
      <a class="avn-top-seal" href="#overview" data-goto="overview" aria-label="Afrovanguard — Today"><img src="/assets/site/av-seal-96.png" alt="" width="36" height="36"></a>
      <?php /* Which view is showing, said to a screen reader as it changes; the sidebar shows it to everyone else. */ ?>
      <p class="avn-sr" aria-live="polite">Portal: <span id="crumbHere">Today</span></p>
      <div class="avp-top-actions avn-top-actions">
<?php if ($isOrg): ?>        <a class="avp-online avn-online" id="topOnline" href="/people/"<?= $onlineNow > 0 ? '' : ' hidden' ?>><span class="avn-dot" aria-hidden="true"></span><span><span class="av-num" id="tbCount"><?= (int) $onlineNow ?></span> online</span></a>
<?php endif; ?>        <div class="avp-notif" id="notifWrap" data-csrf="<?= e($collabCsrf) ?>">
          <button type="button" class="avp-bell avn-bell" id="notifBtn" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" aria-controls="notifPanel">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg>
            <span class="avp-bell-n" id="notifBadge" hidden>0</span>
          </button>
          <div class="avp-notif-panel" id="notifPanel" hidden role="dialog" aria-label="Notifications">
            <div class="avp-notif-head"><span>Notifications</span><button type="button" class="avp-link-btn" id="notifReadAll">Mark all read</button></div>
            <div class="avp-notif-list notif-list" id="notifList"><p class="notif-empty">Loading…</p></div>
          </div>
        </div>
        <a class="avn-me" href="#membership" data-goto="membership" aria-label="Your profile — <?= e(trim((string) $u['name'])) ?>"><?php if ($navPhoto !== ''): ?><img src="<?= e($navPhoto) ?>" alt=""><?php else: ?><?= e($pInitials) ?><?php endif; ?></a>
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

        foreach (PortalNav::views($portalCtx) as $portalView) require __DIR__ . '/views/' . $portalView . '.php';
?>
      <footer class="avp-foot">
        <span>© 2026 Afrovanguard</span>
        <span><a href="<?= e(rtrim(SITE_URL, '/')) ?>/">Main site ↗</a> · <a href="mailto:cacentre@afrovanguard.org.ng">Support</a></span>
      </footer>
    </div>
  </main>

  <nav class="avp-tabs avn-tabs" aria-label="Primary">
<?php foreach ($tabs as [$id, $label]): ?>
    <a class="avp-tab avn-tab" href="#<?= e($id) ?>" data-view="<?= e($id) ?>"><span class="avn-tab-i"><?= $navIco($id, 21) ?></span><span><?= e($label) ?></span></a>
<?php endforeach; ?>
    <button type="button" class="avp-tab avn-tab" id="avpMore" aria-haspopup="dialog" aria-controls="avpSheet" aria-expanded="false">
      <span class="avn-tab-i"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="4" width="6.5" height="6.5" rx="1.8"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.8"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.8"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.8"/></svg></span>
      <span>More</span>
    </button>
  </nav>
</div>

<?php /* ── The phone's More sheet (Home v4, "Phone — More sheet") ───────────
   Opens at half height from the tab bar's More; the handle (tap, or drag)
   takes it to full height and back; drag it down, Esc, or the scrim closes
   it. Every section the account has is here, under the sidebar's groups,
   with the same words beside it; the eight shortcuts at the top are the
   member's own (Edit), kept in Prefs. portal/shell.js. */ ?>
<div class="avm" id="avpSheet" hidden data-csrf="<?= e($collabCsrf) ?>">
  <div class="avm-scrim" data-avm-close></div>
  <section class="avm-sheet" role="dialog" aria-modal="true" aria-label="More" tabindex="-1">
    <button type="button" class="avm-handle" aria-label="Open fully" aria-expanded="false"><span aria-hidden="true"></span></button>
    <div class="avm-body">
      <div class="avm-me">
        <a href="#membership" data-goto="membership" class="avm-me-a">
          <span class="avm-ring" aria-hidden="true"><svg width="52" height="52" viewBox="0 0 52 52"><circle cx="26" cy="26" r="24" fill="none" stroke-width="3" class="avm-ring-t"/><?php if ($navLevelPct > 0): ?><circle cx="26" cy="26" r="24" fill="none" stroke-width="3" class="avm-ring-f" stroke-dasharray="<?= round(2 * M_PI * 24 * $navLevelPct / 100, 1) ?> <?= round(2 * M_PI * 24, 1) ?>" stroke-linecap="round" transform="rotate(-90 26 26)"/><?php endif; ?></svg><span class="avm-av"><?php if ($navPhoto !== ''): ?><img src="<?= e($navPhoto) ?>" alt=""><?php else: ?><?= e($pInitials) ?><?php endif; ?></span></span>
          <span class="avm-me-t"><b><?= e(trim((string) $u['name'])) ?></b><span>Level <?= e((string) ($journey['level'] ?? 'O')) ?><?= $journey['next'] !== null ? ' · ' . $navLevelPct . '% to ' . e((string) $journey['next']) : '' ?> · view profile</span></span>
        </a>
        <button type="button" class="avm-x" data-avm-close aria-label="Close"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
      </div>

      <button type="button" class="avm-search" data-cmdk="cmd"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg><span>Search people, tasks, pages…</span></button>

      <div class="avm-sec">
        <div class="avm-lab"><h2 id="avmShortH">Shortcuts</h2><button type="button" class="avm-edit" aria-pressed="false">Edit</button></div>
        <p class="avm-edit-note" hidden>Tap a shortcut to take it off, or a section below to add it — up to <?= PortalNav::SHORTCUT_MAX ?>.</p>
        <nav class="avm-tiles" aria-labelledby="avmShortH" id="avmTiles">
<?php foreach ($navShort as $id): $st = $navStatus[$id] ?? ['', '', '']; $badge = $st[1] !== '' ? $st[1] : $st[0]; ?>
          <a href="#<?= e($id) ?>" data-goto="<?= e($id) ?>" data-id="<?= e($id) ?>" class="avm-tile avm-tile--<?= e(PortalNav::SECTIONS[$id]['group'] === 'Learn' ? 'learn' : ($st[2] === 'warn' && $st[1] !== '' ? 'warn' : 'you')) ?>"><span class="avm-tile-i"><?= $navIco($id, 20) ?></span><?= e($navLabels[$id] ?? PortalNav::label($id, $portalCtx)) ?><?php if ($badge !== ''): ?><b class="avm-badge avm-badge--<?= e($st[1] !== '' ? ($st[2] ?: 'n') : 'n') ?>"><?= e($badge) ?></b><?php endif; ?></a>
<?php endforeach; ?>
        </nav>
      </div>

      <button type="button" class="avm-all"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>All sections</button>

<?php foreach ($nav as $group => $items): ?>
      <div class="avm-sec">
        <div class="avm-lab"><h2><?= e($group === 'Home' ? 'Home' : $group) ?></h2></div>
        <ul class="avm-grp">
<?php foreach ($items as [$id, $label, $st]): ?>
          <li><a href="#<?= e($id) ?>" data-goto="<?= e($id) ?>" data-id="<?= e($id) ?>" class="avm-it"><span class="avm-ico avm-ico--<?= e(PortalNav::SECTIONS[$id]['group'] === 'Learn' ? 'learn' : (in_array(PortalNav::SECTIONS[$id]['group'], ['Vanguard Quest', 'NextGen Vanguard'], true) ? 'you' : 'n')) ?>"><?= $navIco($id, 17) ?></span><?= e($label) ?><span class="avm-end"><?= $navEnd($st) ?><svg class="avm-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></span></a></li>
<?php endforeach; ?>
        </ul>
      </div>
<?php endforeach; ?>

      <div class="avm-sec">
        <div class="avm-lab"><h2>Account</h2></div>
        <ul class="avm-grp">
          <li class="avm-it avm-it--static"><span class="avm-ico avm-ico--n"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg></span>Appearance
            <span class="avm-end avm-segs" role="radiogroup" aria-label="Appearance" data-mode="<?= e($ptheme) ?>"><span class="avm-thumb" aria-hidden="true"></span><?php foreach (['light' => 'Light', 'dark' => 'Dark', 'auto' => 'Auto'] as $mv => $ml): ?><button type="button" class="avm-seg" role="radio" data-mode="<?= $mv ?>" aria-checked="<?= $ptheme === $mv ? 'true' : 'false' ?>"><?= $ml ?></button><?php endforeach; ?></span></li>
          <li><a href="mailto:cacentre@afrovanguard.org.ng" class="avm-it"><span class="avm-ico avm-ico--n"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6M12 17h.01"/></svg></span>Help &amp; support<span class="avm-end"><svg class="avm-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></span></a></li>
        </ul>
      </div>

      <div class="avm-foot">
        <a href="<?= e(rtrim(SITE_URL, '/')) ?>/" class="avm-foot-a">Main site ↗</a>
        <a href="#" data-logout class="avm-foot-a avm-out">Sign out</a>
      </div>
      <p class="avm-msg" role="status" aria-live="polite"></p>
    </div>
  </section>
</div>
<?php if ($isOrg) require __DIR__ . '/views/meet-modal.php'; ?>
<?php if (class_exists('Todo') && Todo::enabled()): /* the to-do pop-up: one list across both sites (lib/Todo.php) */ ?>
<div class="tdp tdp--av" id="todoPop" data-src="/portal/collab.php" data-csrf="<?= e($collabCsrf) ?>" data-key="av-<?= (int) $u['id'] ?>" hidden></div>
<?php endif; ?>

  <script src="<?= e(av_asset('/portal/avp.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/home.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/shell.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/tasks.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/todo-pop.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/chat-panel.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/meet-modal.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/dues-pay.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/google.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/mentor-meet.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/team-chat.js')) ?>" defer></script>
  <script type="application/json" id="avGoogleState"><?= json_encode(['oauth' => $google['oauth'], 'connected' => $google['connected'], 'org' => $google['org'],
      'admin' => $google['admin'], 'problem' => $google['problem'], 'connect' => $google['connect'], 'domain' => $google['domain'], 'notice' => $googleNotice, 'csrf' => $collabCsrf],
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
  <script src="<?= e(av_asset('/portal/tools.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/repeat.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/meetings.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/notifications.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/directory.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/inventory.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/wifi.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/wallet.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/leads.js')) ?>" defer></script>
  <?php /* The palette reads the sidebar rather than being handed a second copy
           of it. Panes are hash links, and the portal already listens for
           hashchange, so going to one is the same as clicking it. */ ?>
  <script type="application/json" id="cmdk-data"><?= json_encode([
      'navFrom' => '.pnav-link[data-view]',
      'actions' => array_values(array_filter([
          ['label' => 'New task',  'href' => '/portal/#tasks',     'sub' => 'Something to be done'],
          ['label' => 'Write in the diary', 'href' => '/portal/#diary', 'sub' => 'Today, in your own words'],
          AvRules::bool('quest.self_report') ? ['label' => 'Report yourself', 'href' => '/portal/#bills', 'sub' => 'A fine or deduction you own up to'] : null,
          $billsWaiting ? ['label' => 'Answer a fine or deduction', 'href' => '/portal/#bills', 'sub' => $billsWaiting . ' waiting for you'] : null,
          $isOrg && CacSso::ready()
              ? ['label' => 'Look something up in the register', 'href' => '/portal/#inventory',
                 'sub' => 'Where it is, and who has it']
              : null,
      ])),
      'find'    => '/portal/palette.php',
  ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script src="<?= e(av_asset('/portal/palette.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/money-requests.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/member-requests.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/community/community.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/avdy.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/commitments.js')) ?>" defer></script>
<?php if ($mayRead): ?>
  <?php /* The claim sheet's script AND its token. A vanguard gets both from
           academy/ngv/_dashboard-body.php, which their Programme view
           includes; a member or a volunteer has no dashboard body, so the
           Reading page drew the sheet with nothing driving it and every save
           came back "Bad token." One emitter per page — never both. */ ?>
  <script>window.NGV_CSRF = <?= json_encode(av_csrf_token()) ?>;
          window.NGV_CHECKER = <?= json_encode('the reading checker') ?>;</script>
  <script src="<?= e(av_asset('/academy/ngv/reading.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/academy/ngv/courses-take.js')) ?>" defer></script>
<?php endif; ?>
<?php if ($isVol): ?>  <script src="<?= e(av_asset('/portal/volunteer.js')) ?>" defer></script>
<?php endif; ?>
  <script src="<?= e(av_asset('/assets/site/avc-photo.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/portal/card-photo.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/assets/site/celebrations.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/assets/site/nav.js')) ?>" defer></script>
</body>
</html>
