<?php
/**
 * portal/index.php — the member / learning portal.
 *
 * A clean, single-page workspace dashboard (Public-Sans SaaS look): a white
 * sidebar with grouped nav + search + a user card, a breadcrumb top bar, a KPI
 * chip row, and cards for tasks, Workspace, journey, presence, activity, dues,
 * membership, mentorship and the Diary. The sidebar smooth-scrolls to each
 * section and scroll-spy highlights the active one.
 *
 * Two experiences from one dashboard, decided by the account:
 *   @afrovanguard.org.ng  → MEMBER       — learning + mentorship + members + diary
 *   everyone else         → LEARNING ONLY — learning + diary (org spaces locked)
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
    'body_class' => 'portal-page portal-app' . ($ptheme === 'dark' ? ' is-dark' : ''),
    'css'        => ['/portal/portal.css', '/community/community.css', '/portal/community.css', '/assets/vendor/trix/trix.css'],
    'manifest'   => '/manifest.webmanifest',
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
        ['tasks', 'Tasks', 'gold', $openTasks ? (string) $openTasks : ''],
        ['chat', 'Team Chat', 'green', ''],
        ['workspace', 'Workspace', 'gray', ''],
    ];
}
$nav['Learn'] = [
    ['learning', 'Learning', 'gray', $courses ? (string) count($courses) : ''],
    ['mentorship', 'Mentorship', 'gray', $mentorStats['attended'] ? (string) (int) $mentorStats['attended'] : ''],
];
$nav['You'] = [
    ['diary', 'Diary', 'gray', $myEntries ? (string) count($myEntries) : ''],
    ['membership', ($isOrg ? 'Membership' : 'Account'), 'gray', ''],
];
?>
  <div class="portal-shell">

    <!-- ============ SIDEBAR ============ -->
    <aside class="pside" id="portalSide" aria-label="Portal navigation">
      <a class="pside-brand" href="<?= e(rtrim(SITE_URL, '/')) ?>/" aria-label="Afrovanguard home">
        <span class="pside-mark">A</span>
        <span class="pside-brand-text"><span class="pb-name">Afrovanguard</span><span class="pb-sub"><?= e($tag) ?></span></span>
      </a>

      <div class="pside-search">
        <span class="pside-search-ico" aria-hidden="true">⌕</span>
        <input type="search" id="pSearch" placeholder="Search…" aria-label="Search the portal" autocomplete="off">
      </div>

      <nav class="pside-nav" aria-label="Sections">
<?php foreach ($nav as $group => $items): ?>
        <div class="pnav-group">
          <div class="pnav-title"><?= e($group) ?></div>
<?php foreach ($items as [$id, $label, $dot, $badge]): ?>
          <a class="pnav-link" href="#<?= e($id) ?>" data-view="<?= e($id) ?>">
            <span class="pnav-dot pnav-dot--<?= e($dot) ?>"></span>
            <span class="pnav-label"><?= e($label) ?></span>
<?php if ($badge !== ''): ?>            <span class="pnav-badge"><?= e($badge) ?></span>
<?php endif; ?>          </a>
<?php endforeach; ?>
        </div>
<?php endforeach; ?>
        <div class="pnav-group">
          <div class="pnav-title">More</div>
          <a class="pnav-link" href="/academy/"><span class="pnav-dot pnav-dot--gray"></span><span class="pnav-label">Academy</span><span class="pnav-ext">↗</span></a>
          <a class="pnav-link" href="<?= e(rtrim(SITE_URL, '/')) ?>/"><span class="pnav-dot pnav-dot--gray"></span><span class="pnav-label">Main site</span><span class="pnav-ext">↗</span></a>
        </div>
      </nav>

      <div class="pside-user">
        <span class="pside-avatar"><?= e($pInitials) ?></span>
        <span class="pside-user-meta"><span class="pu-name"><?= e($first . ' ' . (($parts[1] ?? ''))) ?></span><span class="pu-role"><?= $isOrg ? e($accessLevel) : 'Learner' ?></span></span>
        <a class="pside-signout" href="#" data-logout title="Sign out" aria-label="Sign out">⋯</a>
      </div>
    </aside>

    <div class="portal-scrim" id="portalScrim" hidden></div>

    <!-- ============ MAIN ============ -->
    <main class="portal-main" id="main-content">
      <header class="ptop">
        <button type="button" class="ptop-burger" id="sideToggle" aria-label="Open navigation" aria-expanded="false">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <div class="ptop-crumb"><span>Portal</span><span class="ptop-sep">/</span><span class="ptop-here" id="crumbHere">Dashboard</span></div>
        <div class="ptop-actions">
<?php if ($isOrg): ?>          <span class="ptop-online" id="topOnline"<?= $onlineNow > 0 ? '' : ' hidden' ?>><span class="dot-live"></span><span id="tbCount"><?= (int) $onlineNow ?></span> online</span>
<?php endif; ?>          <div class="ptop-notif" id="notifWrap" data-csrf="<?= e($collabCsrf) ?>">
            <button type="button" class="ptop-icon" id="notifBtn" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" title="Notifications">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"/></svg>
              <span class="notif-badge" id="notifBadge" hidden>0</span>
            </button>
            <div class="notif-panel" id="notifPanel" hidden role="dialog" aria-label="Notifications">
              <div class="notif-head"><span>Notifications</span><button type="button" class="notif-readall" id="notifReadAll">Mark all read</button></div>
              <div class="notif-list" id="notifList"><p class="notif-empty">Loading…</p></div>
            </div>
          </div>
          <button type="button" class="ptop-icon" id="portalTheme" aria-label="Light / dark" title="Light / dark">
            <svg class="ico-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/></svg>
            <svg class="ico-moon" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="display:none"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 109.8 9.8z"/></svg>
          </button>
          <a class="ptop-icon" href="/diary/me/" title="Your Diary" aria-label="Your Diary">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"/></svg>
          </a>
        </div>
      </header>

      <div class="portal-scroll">

<?php
        // Precompute view data used across tabs.
        $stageCode = (string) ($journey['level'] ?? 'O');
        $duesState = $dues ? (string) $dues['state'] : 'none';
        $duesVal   = $dues ? ((!empty($dues['lifetime'])) ? 'Lifetime' : ($duesState === 'active' ? 'Current' : ($duesState === 'none' ? 'Not paid' : ucfirst(str_replace('_', ' ', $duesState))))) : '—';
        $duesTotal = $dues ? (int) ($dues['total_paid_ngn'] ?? 0) : 0;
        $jOrder = $journey['order']; $jHere = array_search($journey['level'], $jOrder, true);
        $jPct = min(100, (int) round(100 * ($journey['referrals'] ?? 0) / max(1, (int) ($journey['referrals_needed'] ?? 2))));
?>

        <!-- ============================================================ -->
        <!-- DASHBOARD                                                    -->
        <!-- ============================================================ -->
        <section class="pview" id="view-overview" data-view="overview">

          <!-- Welcome + quick actions -->
          <div class="phead">
            <div>
              <h1>Welcome back, <?= e($first) ?>.</h1>
              <p class="phead-sub"><?= $isOrg ? "Here’s what’s happening across your Afrovanguard workspace today." : 'Pick up where you left off in your learning.' ?></p>
            </div>
            <div class="phead-actions">
<?php if ($isOrg): ?>              <a class="pbtn pbtn-ghost" href="#tasks" data-goto="tasks">＋ New task</a>
              <button type="button" class="pbtn pbtn-ghost" id="openMeetModal">🎥 Schedule meeting</button>
              <a class="pbtn pbtn-gold" href="https://meet.google.com/new" target="_blank" rel="noopener noreferrer">▶ Start a Meet</a>
<?php else: ?>              <a class="pbtn pbtn-gold" href="/academy/">Browse the Academy →</a>
<?php endif; ?>            </div>
          </div>

          <!-- Needs your attention — the productivity focus of "Today" -->
<?php
            $attn = [];
            if ($nextSession) {
                $ns = $nextSession;
                $when = !empty($ns['live']) ? 'Live now' : date('D g:ia', strtotime((string) $ns['when'] . ' UTC') ?: time());
                $attn[] = ['ico' => '🎥', 'tone' => !empty($ns['live']) ? 'green' : 'indigo',
                    'title' => (!empty($ns['live']) ? 'Meeting live — ' : 'Next meeting — ') . e($ns['title']),
                    'sub' => e($ns['role']) . ' ' . e($ns['with']) . ' · ' . e($when), 'cta' => 'Go', 'goto' => 'mentorship'];
            }
            if ($isOrg && $tasksDue) {
                $n = count($tasksDue);
                $attn[] = ['ico' => '✓', 'tone' => $tasksOverdue ? 'red' : 'gold',
                    'title' => $n . ' task' . ($n === 1 ? '' : 's') . ' due' . ($tasksOverdue ? ' · ' . $tasksOverdue . ' overdue' : ''),
                    'sub' => 'Due today or earlier', 'cta' => 'Open', 'goto' => 'tasks'];
            }
            if ($postsToday) {
                $attn[] = ['ico' => '💬', 'tone' => 'indigo',
                    'title' => $postsToday . ' new community post' . ($postsToday === 1 ? '' : 's') . ' today',
                    'sub' => 'Catch up with members', 'cta' => 'Open', 'goto' => 'community'];
            }
            if ($attn):
?>          <section class="pcard today-attn">
            <div class="pcard-head"><h2>Needs your attention</h2><span class="pchip pchip--gold"><?= e(date('D, M j')) ?></span></div>
            <div class="pcard-body">
              <ul class="attn-list">
<?php foreach ($attn as $a): ?>                <li class="attn-item attn--<?= e($a['tone']) ?>">
                  <span class="attn-ico"><?= $a['ico'] ?></span>
                  <span class="attn-txt"><span class="attn-title"><?= $a['title'] ?></span><span class="attn-sub"><?= $a['sub'] ?></span></span>
                  <a class="pbtn pbtn-soft attn-cta" href="#<?= e($a['goto']) ?>" data-goto="<?= e($a['goto']) ?>"><?= e($a['cta']) ?> →</a>
                </li>
<?php endforeach; ?>              </ul>
            </div>
          </section>
<?php endif; ?>

          <!-- KPI chip row -->
          <div class="pkpis" aria-label="At a glance">
<?php
            $kpis = [];
            if ($isOrg) {
                $kpis[] = ['label' => 'Tasks open', 'value' => (string) $openTasks, 'id' => 'kpiTasks', 'sub' => 'across your list', 'chip' => 'Active', 'tone' => 'gold'];
                $kpis[] = ['label' => 'Members online', 'value' => (string) $onlineNow, 'id' => 'kpiOnline', 'sub' => 'right now', 'chip' => 'Live', 'tone' => 'green'];
            }
            $kpis[] = ['label' => 'Your stage', 'value' => (string) ($journey['label'] ?? 'Member'), 'sub' => ($stageCode === 'O' ? 'Level A up next' : 'Keep building'), 'chip' => 'Level ' . $stageCode, 'tone' => 'indigo'];
            $kpis[] = ['label' => 'Mentorship hours', 'value' => $mentorHoursLabel, 'sub' => ((int) $mentorStats['attended']) . ' session' . (((int) $mentorStats['attended']) === 1 ? '' : 's') . ' attended', 'chip' => 'Logged', 'tone' => 'gold'];
            if ($dues) {
                $kpis[] = ['label' => 'Total dues paid', 'value' => '₦' . number_format($duesTotal), 'sub' => $duesVal, 'chip' => ($duesState === 'active' || !empty($dues['lifetime'])) ? 'Current' : 'Due', 'tone' => ($duesState === 'active' || !empty($dues['lifetime'])) ? 'green' : 'red'];
            } else {
                $kpis[] = ['label' => 'Certificates', 'value' => (string) $certs, 'sub' => $inProgress . ' in progress', 'chip' => 'Learning', 'tone' => 'indigo'];
            }
            foreach ($kpis as $k):
?>            <div class="pkpi">
              <div class="pkpi-top"><span class="pkpi-label"><?= e($k['label']) ?></span><span class="pchip pchip--<?= e($k['tone']) ?>"><?= e($k['chip']) ?></span></div>
              <div class="pkpi-value"<?= isset($k['id']) ? ' id="' . e($k['id']) . '"' : '' ?>><?= e($k['value']) ?></div>
              <div class="pkpi-sub"><?= e($k['sub']) ?></div>
            </div>
<?php endforeach; ?>
          </div>

          <div class="pcols">
            <div class="pcol pcol--main">
<?php if ($isOrg): ?>
              <!-- Your calendar — today + what's coming, with meeting links -->
              <section class="pcard" id="todayCal" data-csrf="<?= e($collabCsrf) ?>">
                <div class="pcard-head">
                  <div class="task-head-l">
                    <h2>Your calendar</h2>
                    <span class="task-head-sub" id="calSummary">Next 14 days</span>
                  </div>
                  <a class="pcard-link" href="#tools" data-goto="tools">Open calendar →</a>
                </div>
                <div class="pcard-body">
                  <div class="cal-week" id="calWeek" aria-hidden="true"></div>
                  <div class="cal-agenda" id="calAgenda"><p class="pc-empty">Loading your calendar…</p></div>
                </div>
              </section>
<?php endif; ?>
              <!-- Your journey -->
              <section class="pcard">
                <div class="pcard-head"><h2>Your journey</h2><a class="pcard-link" href="/how-it-works">How progression works →</a></div>
                <div class="pcard-body">
                  <div class="jl-track">
<?php foreach ($jOrder as $i => $code): $st = $i === $jHere ? 'is-here' : ($i < $jHere ? 'is-done' : ''); ?>                    <span class="jl-chip <?= $st ?>"><span class="jl-badge"><?= e($code) ?></span><?= e(Levels::LADDER[$code]['label'] ?? $code) ?></span>
<?php endforeach; ?>                  </div>
<?php if ($journey['level'] === 'O'): ?>
                  <div class="jl-progress"><div class="jl-bar" aria-hidden="true"><span style="width:<?= $jPct ?>%"></span></div><span class="jl-count"><?= (int) $journey['referrals'] ?> / <?= (int) $journey['referrals_needed'] ?> introduced</span></div>
                  <p class="pcard-note">Toward <strong>Level A</strong> — personally introduce committed members and mentor them as they settle in.</p>
                  <div class="jl-invite"><div class="jl-invite-url" title="Your invite link"><?= e($journey['invite_url']) ?></div><button type="button" class="pbtn pbtn-ghost" id="copyInvite" data-url="<?= e($journey['invite_url']) ?>">Copy</button></div>
<?php else: ?>
                  <p class="pcard-note">You’re at <strong><?= e($journey['label']) ?></strong>. <?= e($journey['blurb']) ?></p>
<?php endif; ?>
                </div>
              </section>
            </div>

            <div class="pcol pcol--side">
<?php if ($isOrg): ?>
              <!-- Who's online -->
              <section class="pcard">
                <div class="pcard-head"><h2>Who’s online</h2><span class="pchip pchip--green" id="onlinePill"><span class="dot-live"></span><span id="onlineCount"><?= (int) $onlineNow ?></span> now</span></div>
                <div class="pcard-body"><div class="online-list" id="onlineList"><p class="pc-empty">Just you so far.</p></div></div>
              </section>

              <!-- Team activity — task pool, claims and completions -->
              <section class="pcard">
                <div class="pcard-head"><h2>Team activity</h2><a class="pcard-link" href="#tasks" data-goto="tasks">Tasks →</a></div>
                <div class="pcard-body"><ul class="activity-list" id="activityList"><li class="pc-empty">Loading…</li></ul></div>
              </section>
<?php endif; ?>
              <!-- Mentorship snapshot -->
              <section class="pcard">
                <div class="pcard-head"><h2>Mentorship</h2><span class="pchip pchip--gold"><?= e($mentorHoursLabel) ?> logged</span></div>
                <div class="pcard-body">
                  <div class="mentor-stats">
                    <div class="mstat"><span class="mstat-n"><?= e($mentorHoursLabel) ?></span><span class="mstat-l">Hours logged</span></div>
                    <div class="mstat"><span class="mstat-n"><?= (int) $mentorStats['attended'] ?></span><span class="mstat-l">Attended</span></div>
                    <div class="mstat"><span class="mstat-n"><?= $mentorStats['rate'] !== null ? (int) $mentorStats['rate'] . '%' : '—' ?></span><span class="mstat-l">Consistency</span></div>
                  </div>
                  <a class="pbtn pbtn-soft" href="#mentorship" data-goto="mentorship">View mentorship →</a>
                </div>
              </section>
            </div>
          </div>
        </section>

<?php if ($isOrg): ?>
        <!-- Schedule-a-meeting modal (creates a Google Meet + calendar invite) -->
        <div class="pm-scrim" id="meetScrim" hidden>
          <div class="pm-modal" role="dialog" aria-modal="true" aria-labelledby="meetModalTitle" id="meetModal" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pm-head"><h2 id="meetModalTitle">Schedule a meeting</h2><button type="button" class="pm-x" id="meetClose" aria-label="Close">✕</button></div>
            <form id="meetForm" class="pm-body" autocomplete="off">
              <label class="pm-f"><span>Title</span><input type="text" id="mmTitle" maxlength="200" required placeholder="e.g. Clean-up drive planning"></label>
              <div class="pm-row">
                <label class="pm-f"><span>When</span><input type="datetime-local" id="mmWhen" required></label>
                <label class="pm-f pm-f--sm"><span>Duration</span>
                  <select id="mmDur"><option value="15">15 min</option><option value="30" selected>30 min</option><option value="45">45 min</option><option value="60">1 hour</option><option value="90">1.5 hours</option></select>
                </label>
                <label class="pm-f pm-f--sm"><span>Repeats</span>
                  <select id="mmFreq"><option value="once" selected>Once</option><option value="weekly">Weekly</option><option value="biweekly">Every 2 weeks</option><option value="monthly">Monthly</option></select>
                </label>
              </div>
              <label class="pm-f"><span>Invite (comma-separated emails)</span><input type="text" id="mmAtt" placeholder="ada@afrovanguard.org.ng, bode@…"></label>
              <label class="pm-f"><span>Agenda <small>(optional)</small></span><textarea id="mmAgenda" rows="2" maxlength="2000" placeholder="What we’ll cover…"></textarea></label>
              <p class="pm-msg" id="mmMsg" hidden></p>
              <div class="pm-actions">
                <button type="button" class="pbtn pbtn-ghost" id="meetCancel">Cancel</button>
                <button type="submit" class="pbtn pbtn-gold" id="mmSubmit">Create meeting &amp; Meet link</button>
              </div>
            </form>
          </div>
        </div>
<?php endif; ?>

        <!-- ============================================================ -->
        <!-- TOOLS  (Afrovanguard first-party productivity apps)          -->
        <!-- ============================================================ -->
        <section class="pview" id="view-tools" data-view="tools" hidden data-uid="<?= (int) $u['id'] ?>">
          <div class="view-head">
            <div><h1>Suite</h1><p class="view-sub">Your Afrovanguard productivity suite — personal tools and shared team apps, right in the portal.</p></div>
          </div>

          <!-- App launcher: pick one app to open -->
          <div class="suite-home" id="suiteHome">
            <h2 class="suite-section"><span>◧ Personal</span><small>Private to you, synced to your account</small></h2>
            <div class="app-tiles">
              <button type="button" class="app-tile app-tile--cal" data-app="cal"><span class="app-ic">◗</span><span class="app-tx"><span class="app-nm">Calendar</span><span class="app-desc">Your month, unified</span></span></button>
              <button type="button" class="app-tile" data-app="notes"><span class="app-ic">✎</span><span class="app-tx"><span class="app-nm">Notes</span><span class="app-desc">Quick private scratchpad</span></span></button>
              <button type="button" class="app-tile" data-app="focus"><span class="app-ic">◐</span><span class="app-tx"><span class="app-nm">Focus</span><span class="app-desc">Pomodoro timer</span></span></button>
              <button type="button" class="app-tile" data-app="habits"><span class="app-ic">✓</span><span class="app-tx"><span class="app-nm">Habits</span><span class="app-desc">Build daily streaks</span></span></button>
              <button type="button" class="app-tile" data-app="countdown"><span class="app-ic">◔</span><span class="app-tx"><span class="app-nm">Countdown</span><span class="app-desc">Days to a date</span></span></button>
              <button type="button" class="app-tile" data-app="rem"><span class="app-ic">⏰</span><span class="app-tx"><span class="app-nm">Reminders</span><span class="app-desc">Nudges with due dates</span></span></button>
            </div>
<?php if ($isOrg): ?>
            <h2 class="suite-section"><span>◨ Team</span><small>Shared with everyone at Afrovanguard</small></h2>
            <div class="app-tiles">
              <button type="button" class="app-tile app-tile--team" data-app="meet"><span class="app-ic">🎥</span><span class="app-tx"><span class="app-nm">Meetings</span><span class="app-desc">Schedule with a Meet link + AI minutes</span></span></button>
              <button type="button" class="app-tile app-tile--team" data-app="board"><span class="app-ic">▦</span><span class="app-tx"><span class="app-nm">Team board</span><span class="app-desc">Kanban workflow</span></span></button>
              <button type="button" class="app-tile app-tile--team" data-app="polls"><span class="app-ic">▤</span><span class="app-tx"><span class="app-nm">Team polls</span><span class="app-desc">Quick decisions</span></span></button>
              <button type="button" class="app-tile app-tile--team" data-app="standup"><span class="app-ic">◷</span><span class="app-tx"><span class="app-nm">Daily standup</span><span class="app-desc">Async check-ins</span></span></button>
              <button type="button" class="app-tile app-tile--team" data-app="goals"><span class="app-ic">◎</span><span class="app-tx"><span class="app-nm">Goals &amp; OKRs</span><span class="app-desc">Track objectives</span></span></button>
              <button type="button" class="app-tile app-tile--team" data-app="links"><span class="app-ic">🔖</span><span class="app-tx"><span class="app-nm">Team links</span><span class="app-desc">Shared resources</span></span></button>
            </div>
<?php endif; ?>
          </div>

          <!-- Opened app: back bar + a single app on stage -->
          <div class="suite-open" id="suiteOpen" hidden>
            <div class="suite-bar">
              <button type="button" class="suite-back" id="suiteBack">‹ All apps</button>
              <h2 class="suite-open-title" id="suiteOpenTitle"></h2>
            </div>
            <div class="suite-stage" id="suiteStage">

            <div class="suite-app" data-app="cal" hidden>
          <!-- Integrated calendar: team events + AFG events + sessions + tasks + reminders -->
          <section class="pcard tool-cal" id="tlCal" data-csrf="<?= e($collabCsrf) ?>" data-org="<?= $isOrg ? '1' : '0' ?>">
            <div class="pcard-head cal-head">
              <h2>◗ Calendar</h2>
              <div class="cal-ctrls">
                <button type="button" class="cal-arrow" id="tlCalPrev" aria-label="Previous month">‹</button>
                <span class="cal-month" id="tlCalMonth">—</span>
                <button type="button" class="cal-arrow" id="tlCalNext" aria-label="Next month">›</button>
                <button type="button" class="pbtn pbtn-ghost pbtn-sm" id="tlCalToday">Today</button>
              </div>
            </div>
            <div class="pcard-body cal-body">
              <div class="cal-main">
                <div class="cal-dow"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
                <div class="cal-grid" id="tlCalGrid"><p class="pc-empty">Loading calendar…</p></div>
                <div class="cal-legend">
                  <span class="cal-key cal-key--event">Team event</span>
                  <span class="cal-key cal-key--afg">Afrovanguard</span>
                  <span class="cal-key cal-key--session">Mentorship</span>
                  <span class="cal-key cal-key--task">Task</span>
                  <span class="cal-key cal-key--reminder">Reminder</span>
                </div>
              </div>
              <aside class="cal-side">
                <h3 class="cal-side-title" id="tlCalAgendaTitle">Today</h3>
                <div class="cal-agenda" id="tlCalAgenda"></div>
<?php if ($isOrg): ?>
                <form id="tlCalForm" class="cal-form" autocomplete="off">
                  <h4>Add an event</h4>
                  <input id="tlCalTitle" class="cal-in" placeholder="Event title…" maxlength="300">
                  <div class="cal-row">
                    <input type="date" id="tlCalDate" class="cal-in" aria-label="Date">
                  </div>
                  <div class="cal-row">
                    <input type="time" id="tlCalStart" class="cal-in" aria-label="Start time">
                    <input type="time" id="tlCalEnd" class="cal-in" aria-label="End time">
                  </div>
                  <input id="tlCalLoc" class="cal-in" placeholder="Location (optional)" maxlength="200">
                  <input id="tlCalNote" class="cal-in" placeholder="Note (optional)" maxlength="500">
                  <div class="cal-form-foot">
                    <button type="submit" class="pbtn pbtn-gold">Add event</button>
                    <span class="poll-msg" id="tlCalMsg" role="status" aria-live="polite"></span>
                  </div>
                </form>
<?php endif; ?>
              </aside>
            </div>
          </section>
            </div><!-- /cal -->

            <div class="suite-app" data-app="notes" hidden>
            <section class="pcard tool" id="tlNotes">
              <div class="pcard-head"><h2>✎ Notes</h2><span class="tool-meta" id="tlNotesMeta">Autosaves</span></div>
              <div class="pcard-body">
                <textarea id="tlNotesArea" class="tool-notes" placeholder="Jot anything… it autosaves as you type."></textarea>
                <div class="tool-row tool-row--foot"><span class="tool-hint" id="tlNotesCount">0 words</span><button type="button" class="pbtn pbtn-ghost" id="tlNotesClear">Clear</button></div>
              </div>
            </section>
            </div><!-- /notes -->

            <div class="suite-app" data-app="focus" hidden>
            <section class="pcard tool" id="tlFocus">
              <div class="pcard-head"><h2>◐ Focus timer</h2><span class="tool-meta"><b id="tlFocusSessions">0</b> done today</span></div>
              <div class="pcard-body tool-focus">
                <div class="focus-mode" id="tlFocusMode">
                  <button type="button" class="fm-btn is-on" data-min="25" data-mode="Focus">Focus · 25</button>
                  <button type="button" class="fm-btn" data-min="5" data-mode="Break">Break · 5</button>
                  <button type="button" class="fm-btn" data-min="15" data-mode="Long break">Long · 15</button>
                </div>
                <div class="focus-clock" id="tlFocusClock">25:00</div>
                <div class="focus-actions">
                  <button type="button" class="pbtn pbtn-gold" id="tlFocusStart">Start</button>
                  <button type="button" class="pbtn pbtn-ghost" id="tlFocusReset">Reset</button>
                </div>
              </div>
            </section>
            </div><!-- /focus -->

            <div class="suite-app" data-app="habits" hidden>
            <section class="pcard tool" id="tlHabits">
              <div class="pcard-head"><h2>✓ Habits</h2><span class="tool-meta" id="tlHabitsMeta"></span></div>
              <div class="pcard-body">
                <form id="tlHabitAdd" class="tool-row" autocomplete="off"><input id="tlHabitInput" placeholder="Add a daily habit…" maxlength="60"><button class="pbtn pbtn-gold" type="submit">Add</button></form>
                <ul class="habit-list" id="tlHabitList"></ul>
              </div>
            </section>
            </div><!-- /habits -->

            <div class="suite-app" data-app="countdown" hidden>
            <section class="pcard tool" id="tlCountdown">
              <div class="pcard-head"><h2>◔ Countdown</h2></div>
              <div class="pcard-body tool-cd">
                <div class="cd-set" id="tlCdSet">
                  <input id="tlCdLabel" placeholder="Counting down to…" maxlength="60">
                  <input type="date" id="tlCdDate">
                  <button type="button" class="pbtn pbtn-gold" id="tlCdSave">Set</button>
                </div>
                <div class="cd-view" id="tlCdView" hidden>
                  <div class="cd-big"><b id="tlCdNum">0</b><span id="tlCdUnit">days</span></div>
                  <p class="cd-label" id="tlCdShow"></p>
                  <button type="button" class="pbtn pbtn-ghost" id="tlCdClear">Clear</button>
                </div>
              </div>
            </section>
            </div><!-- /countdown -->

            <div class="suite-app" data-app="rem" hidden>
            <section class="pcard tool tool-rem" id="tlRem" data-csrf="<?= e($collabCsrf) ?>">
              <div class="pcard-head"><h2>⏰ Reminders</h2><span class="tool-meta" id="tlRemMeta"></span></div>
              <div class="pcard-body">
                <form id="tlRemForm" class="rem-form" autocomplete="off">
                  <input id="tlRemText" class="rem-in" placeholder="Remind me to…" maxlength="300">
                  <div class="rem-row">
                    <input type="datetime-local" id="tlRemDue" class="rem-due" aria-label="Due (optional)">
                    <button type="submit" class="pbtn pbtn-gold">Add</button>
                  </div>
                  <span class="poll-msg" id="tlRemMsg" role="status" aria-live="polite"></span>
                </form>
                <ul class="rem-list" id="tlRemList"></ul>
                <div class="rem-tz">
                  <label for="tlRemTz">Times shown in</label>
                  <select id="tlRemTz" class="rem-tz-sel" data-csrf="<?= e($collabCsrf) ?>">
<?php $curTz = av_user_tz((int) $u['id']); foreach (Prefs::TIMEZONES as $tzLabel => $tzId): ?>
                    <option value="<?= e($tzId) ?>"<?= $tzId === $curTz ? ' selected' : '' ?>><?= e($tzLabel) ?></option>
<?php endforeach; ?>
                  </select>
                </div>
              </div>
            </section>
            </div><!-- /rem -->

<?php if ($isOrg): ?>
            <div class="suite-app" data-app="meet" hidden>
          <!-- Standardized meetings: schedule (with a link + cadence) and get AI minutes -->
          <section class="pcard tool-meet" id="tlMeet" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>🎥 Meetings</h2><span class="pchip pchip--indigo">Members · shared</span></div>
            <div class="pcard-body">
              <form id="tlMeetForm" class="meet-form" autocomplete="off">
                <input id="tlMeetTitle" class="meet-in" placeholder="Meeting title…" maxlength="200">
                <div class="meet-row">
                  <label class="meet-f"><span>When</span><input type="datetime-local" id="tlMeetWhen" class="meet-in"></label>
                  <label class="meet-f"><span>Length</span>
                    <select id="tlMeetDur" class="meet-in">
                      <option value="15">15 min</option>
                      <option value="30" selected>30 min</option>
                      <option value="45">45 min</option>
                      <option value="60">1 hour</option>
                      <option value="90">1.5 hours</option>
                      <option value="120">2 hours</option>
                    </select>
                  </label>
                  <label class="meet-f"><span>Repeats</span>
                    <select id="tlMeetFreq" class="meet-in">
                      <option value="once" selected>One-off</option>
                      <option value="daily">Every day</option>
                      <option value="weekdays">Every weekday</option>
                      <option value="weekly">Every week</option>
                      <option value="biweekly">Every 2 weeks</option>
                      <option value="monthly">Every month</option>
                    </select>
                  </label>
                </div>
                <input id="tlMeetWho" class="meet-in" placeholder="Invite by email (comma-separated, optional)" maxlength="600">
                <input id="tlMeetAgenda" class="meet-in" placeholder="Agenda / notes (optional)" maxlength="2000">
                <label class="meet-bot"><input type="checkbox" id="tlMeetRec"> <span>🤖 Add the recording bot — auto-capture &amp; transcribe this meeting</span></label>
                <div class="meet-form-foot">
                  <button type="submit" class="pbtn pbtn-gold">Schedule meeting</button>
                  <span class="poll-msg" id="tlMeetMsg" role="status" aria-live="polite"></span>
                </div>
                <p class="meet-hint">A Google Meet link is created and the meeting is added to everyone’s Google Calendar with an invite. Minutes are generated by Gemini Flash from the Google Meet transcript, an uploaded recording, or pasted text.</p>
              </form>
              <div class="meet-list" id="tlMeetList"><p class="pc-empty">Loading meetings…</p></div>
            </div>
          </section>
            </div><!-- /meet -->

            <div class="suite-app" data-app="board" hidden>
          <!-- Enterprise: Kanban board — shared team workflow -->
          <section class="pcard tool-board" id="tlBoard" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>▦ Team board</h2><span class="pchip pchip--indigo">Members · shared</span></div>
            <div class="pcard-body">
              <div class="board-cols" id="tlBoardCols"><p class="pc-empty">Loading board…</p></div>
            </div>
          </section>
            </div><!-- /board -->

            <div class="suite-app" data-app="polls" hidden>
          <!-- Enterprise: Team Polls — collaborative decisions with live tallies -->
          <section class="pcard tool-polls" id="tlPolls" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>▤ Team polls</h2><span class="pchip pchip--indigo">Members · shared</span></div>
            <div class="pcard-body">
              <form id="tlPollForm" class="poll-new" autocomplete="off">
                <input id="tlPollQ" class="poll-q" placeholder="Ask the team a question…" maxlength="300">
                <div class="poll-opts" id="tlPollOpts">
                  <input class="poll-opt" placeholder="Option 1" maxlength="120">
                  <input class="poll-opt" placeholder="Option 2" maxlength="120">
                </div>
                <div class="poll-new-foot">
                  <button type="button" class="pbtn pbtn-ghost" id="tlPollAddOpt">+ Add option</button>
                  <button type="submit" class="pbtn pbtn-gold">Create poll</button>
                  <span class="poll-msg" id="tlPollMsg" role="status" aria-live="polite"></span>
                </div>
              </form>
              <div class="poll-list" id="tlPollList"><p class="pc-empty">Loading polls…</p></div>
            </div>
          </section>
            </div><!-- /polls -->

            <div class="suite-app" data-app="standup" hidden>
          <!-- Enterprise: Async standup — daily team check-ins -->
          <section class="pcard tool-standup" id="tlStandup" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>◷ Daily standup</h2><span class="pchip pchip--indigo">Members · today</span></div>
            <div class="pcard-body">
              <form id="tlSuForm" class="su-form" autocomplete="off">
                <label class="su-field"><span>✅ What I did</span><textarea id="tlSuDone" class="su-in" rows="2" maxlength="1000" placeholder="Yesterday / recently…"></textarea></label>
                <label class="su-field"><span>▶ What's next</span><textarea id="tlSuNext" class="su-in" rows="2" maxlength="1000" placeholder="Today's focus…"></textarea></label>
                <label class="su-field"><span>⛔ Blockers</span><textarea id="tlSuBlk" class="su-in" rows="1" maxlength="1000" placeholder="Anything in the way? (optional)"></textarea></label>
                <div class="su-foot">
                  <button type="submit" class="pbtn pbtn-gold" id="tlSuSave">Post update</button>
                  <button type="button" class="pbtn pbtn-ghost" id="tlSuClear" hidden>Clear mine</button>
                  <span class="poll-msg" id="tlSuMsg" role="status" aria-live="polite"></span>
                </div>
              </form>
              <div class="su-board" id="tlSuBoard"><p class="pc-empty">Loading today's board…</p></div>
            </div>
          </section>
            </div><!-- /standup -->

            <div class="suite-app" data-app="goals" hidden>
          <!-- Enterprise: Goals & OKRs — shared objectives with progress -->
          <section class="pcard tool-goals" id="tlGoals" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>◎ Goals &amp; OKRs</h2><span class="pchip pchip--indigo">Members · shared</span></div>
            <div class="pcard-body">
              <form id="tlGoalForm" class="goal-form" autocomplete="off">
                <input id="tlGoalTitle" class="goal-in" placeholder="Set an objective…" maxlength="300">
                <div class="goal-row">
                  <input id="tlGoalTarget" class="goal-in goal-in--sm" placeholder="Target metric (optional)" maxlength="200">
                  <button type="submit" class="pbtn pbtn-gold">Add goal</button>
                </div>
                <span class="poll-msg" id="tlGoalMsg" role="status" aria-live="polite"></span>
              </form>
              <div class="goal-list" id="tlGoalList"><p class="pc-empty">Loading goals…</p></div>
            </div>
          </section>
            </div><!-- /goals -->

            <div class="suite-app" data-app="links" hidden>
          <!-- Enterprise: Team links — shared resource hub -->
          <section class="pcard tool-links" id="tlLinks" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>🔖 Team links</h2><span class="pchip pchip--indigo">Members · shared</span></div>
            <div class="pcard-body">
              <form id="tlLinkForm" class="link-form" autocomplete="off">
                <input id="tlLinkUrl" class="link-in" placeholder="Paste a URL…" maxlength="600">
                <div class="link-row">
                  <input id="tlLinkTitle" class="link-in link-in--sm" placeholder="Title (optional)" maxlength="200">
                  <button type="submit" class="pbtn pbtn-gold">Save</button>
                </div>
                <input id="tlLinkNote" class="link-in" placeholder="Note (optional)" maxlength="300">
                <span class="poll-msg" id="tlLinkMsg" role="status" aria-live="polite"></span>
              </form>
              <div class="link-list" id="tlLinkList"><p class="pc-empty">Loading links…</p></div>
            </div>
          </section>
            </div><!-- /links -->
<?php endif; ?>
            </div><!-- /suiteStage -->
          </div><!-- /suiteOpen -->
        </section>

<?php if ($isOrg): ?>
        <!-- ============================================================ -->
        <!-- TASKS  (dedicated productivity view)                         -->
        <!-- ============================================================ -->
        <section class="pview" id="view-tasks" data-view="tasks" hidden>
          <div class="view-head">
            <div><h1>Tasks</h1><p class="view-sub">Plan the work, share it with the team, and let AI turn goals into a plan. Anyone can claim an open task.</p></div>
          </div>

          <!-- AI: turn a goal into a plan of tasks (dropped into the pool) -->
          <section class="pcard task-ai" id="taskAi" data-csrf="<?= e($collabCsrf) ?>" hidden>
            <div class="pcard-head">
              <h2>✨ Plan a goal with AI</h2>
              <span class="pchip pchip--indigo">Beta</span>
            </div>
            <div class="pcard-body">
              <p class="pcard-note">Pick a team goal — AI breaks it into concrete tasks with deadlines and posts them to the pool for anyone to pick up.</p>
              <div class="task-ai-row">
                <label class="task-af task-af--grow"><span>Goal</span>
                  <select id="aiGoal" aria-label="Goal to plan"><option value="">Loading goals…</option></select>
                </label>
                <label class="task-af"><span>Up to</span>
                  <select id="aiMax" aria-label="How many tasks">
                    <option value="4">4 tasks</option>
                    <option value="6" selected>6 tasks</option>
                    <option value="8">8 tasks</option>
                  </select>
                </label>
                <button type="button" class="pbtn pbtn-gold" id="aiGenerate">Generate tasks</button>
              </div>
              <p class="task-ai-msg" id="aiMsg" hidden></p>
            </div>
          </section>

          <!-- The shared task pool — open, unclaimed work anyone can take up -->
          <section class="pcard" id="taskPool" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head">
              <h2>Task pool</h2>
              <span class="pchip pchip--gold" id="poolCount">0 open</span>
            </div>
            <div class="pcard-body">
              <p class="pcard-note">Unclaimed work the team needs done. Claim one to make it yours — it moves into your list below.</p>
              <ul class="task-list task-pool-list" id="poolList"><li class="pc-empty task-empty">Loading the pool…</li></ul>
            </div>
          </section>

          <section class="pcard" id="tasks" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head task-head">
              <div class="task-head-l">
                <h2>My tasks</h2>
                <span class="task-head-sub" id="taskFocusTxt">Loading…</span>
              </div>
              <div class="task-focus" id="taskFocus" title="Today’s progress" hidden>
                <span class="task-ring" id="taskRing" style="--pct:0"><span class="task-ring-n" id="taskRingN">0</span></span>
                <span class="task-focus-cap">left<br>today</span>
              </div>
            </div>
            <div class="pcard-body">
              <form class="task-add task-add--full" id="taskAdd" autocomplete="off">
                <input type="text" id="taskInput" name="title" maxlength="300" placeholder="What needs doing?" aria-label="Task">
                <div class="task-add-meta">
                  <label class="task-af"><span>Due</span><input type="date" id="taskDue" aria-label="Due date"></label>
                  <label class="task-af"><span>Priority</span>
                    <select id="taskPriority" aria-label="Priority">
                      <option value="normal" selected>Normal</option>
                      <option value="high">High</option>
                      <option value="low">Low</option>
                    </select>
                  </label>
                  <label class="task-af"><span>Assign</span>
                    <select id="taskAssignee" class="task-assignee" aria-label="Assign to"><option value="0">Me</option></select>
                  </label>
                  <label class="task-af task-af--check"><input type="checkbox" id="taskPool"> <span>Open to anyone (pool)</span></label>
                  <button type="submit" class="pbtn pbtn-gold">Add task</button>
                </div>
              </form>
              <div class="pseg task-filters" id="taskFilters" role="tablist">
                <button type="button" class="pseg-btn is-on" data-filter="all">All <span class="pseg-n" id="fcAll">0</span></button>
                <button type="button" class="pseg-btn" data-filter="open">Open <span class="pseg-n" id="fcOpen">0</span></button>
                <button type="button" class="pseg-btn" data-filter="overdue">Overdue <span class="pseg-n" id="fcOver">0</span></button>
                <button type="button" class="pseg-btn" data-filter="mine">Mine <span class="pseg-n" id="fcMine">0</span></button>
                <button type="button" class="pseg-btn" data-filter="done">Done <span class="pseg-n" id="fcDone">0</span></button>
              </div>
              <ul class="task-list" id="taskList"><li class="pc-empty task-empty">Loading your tasks…</li></ul>
            </div>
          </section>
        </section>

        <!-- ============================================================ -->
        <!-- TEAM CHAT  (Slack-style native channels — @afrovanguard only)-->
        <!-- ============================================================ -->
        <section class="pview" id="view-chat" data-view="chat" hidden>
          <div class="view-head">
            <div><h1>Team Chat</h1><p class="view-sub">Talk to the team in real time. Channels, @mentions and reactions — always on, no app to open.</p></div>
          </div>
          <section class="pcard tc-card" id="teamChat" data-csrf="<?= e($collabCsrf) ?>" data-me="<?= e($pInitials) ?>">
            <div class="tc">
              <!-- Channel rail -->
              <aside class="tc-rail" aria-label="Channels">
                <div class="tc-search">
                  <svg class="tc-search-ico" width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.9"/><path d="m20 20-3.5-3.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                  <input type="search" id="tcSearch" placeholder="Search messages…" aria-label="Search messages" autocomplete="off">
                  <div class="tc-search-results" id="tcSearchResults" hidden></div>
                </div>
                <div class="tc-rail-h">Channels<button type="button" class="tc-addch" id="tcAddChannel" title="New channel" aria-label="New channel" hidden>+</button></div>
                <ul class="tc-channels" id="tcChannels"><li class="pc-empty">Loading…</li></ul>
                <button type="button" class="tc-saved-btn" id="tcSavedBtn">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M6 4h12v16l-6-4-6 4V4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                  <span>Saved items</span>
                </button>
                <button type="button" class="tc-catchup" id="tcCatchup" hidden>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 2.5 14 8.5 20 10 14 11.5 12 17.5 10 11.5 4 10 10 8.5 12 2.5Z" fill="currentColor"/></svg>
                  <span>Catch me up</span>
                </button>
              </aside>
              <!-- Conversation -->
              <div class="tc-main">
                <div class="tc-topbar">
                  <span class="tc-ch-ico">#</span>
                  <div class="tc-ch-meta">
                    <div class="tc-ch-title"><b id="tcChannelName">general</b><span class="tc-ch-count" id="tcMemberCount"></span></div>
                    <div class="tc-ch-topic" id="tcTopic"></div>
                  </div>
                  <button type="button" class="tc-pinbtn" id="tcPinBtn" hidden aria-label="Pinned messages">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M9 4h6l-1 6 4 3v2H6v-2l4-3-1-6Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 17v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <span id="tcPinCount">0</span>
                  </button>
                  <button type="button" class="tc-pinbtn tc-chset" id="tcChannelSettings" hidden aria-label="Channel settings" title="Channel settings">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                  </button>
                  <span class="tc-presence"><span class="dot-live"></span><span id="tcPresence">0</span> online</span>
                </div>
                <div class="tc-pinned" id="tcPinned" hidden></div>
                <div class="tc-stream" id="tcStream"><p class="pc-empty">Loading messages…</p></div>
                <div class="tc-typing" id="tcTyping" hidden></div>
                <form class="tc-compose tc-compose--float" id="tcCompose" autocomplete="off">
                  <div class="tc-mentions" id="tcMentions" hidden></div>
                  <div class="tc-editbar" id="tcEditBar" hidden><svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M4 20h4l10-10-4-4L4 16v4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg> Editing message <button type="button" class="tc-editcancel" id="tcEditCancel">cancel</button></div>
                  <div class="tc-rte" id="tcInput" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Message" data-ph="Message #general — use @ to mention"></div>
                  <div class="tc-compose-bar">
                    <div class="tc-fmtbar" role="toolbar" aria-label="Text formatting">
                      <button type="button" class="tc-fmt" data-cmd="bold" title="Bold — Ctrl+B" aria-label="Bold"><b>B</b></button>
                      <button type="button" class="tc-fmt" data-cmd="italic" title="Italic — Ctrl+I" aria-label="Italic"><i>I</i></button>
                      <button type="button" class="tc-fmt" data-cmd="strike" title="Strikethrough" aria-label="Strikethrough"><s>S</s></button>
                      <span class="tc-fmtsep" aria-hidden="true"></span>
                      <button type="button" class="tc-fmt" data-cmd="ul" title="Bulleted list" aria-label="Bulleted list"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M8 6h13M8 12h13M8 18h13" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/><circle cx="3.6" cy="6" r="1.4" fill="currentColor"/><circle cx="3.6" cy="12" r="1.4" fill="currentColor"/><circle cx="3.6" cy="18" r="1.4" fill="currentColor"/></svg></button>
                      <button type="button" class="tc-fmt" data-cmd="ol" title="Numbered list" aria-label="Numbered list"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M10 6h11M10 12h11M10 18h11" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/><text x="2" y="8.5" font-size="8" font-weight="700" fill="currentColor">1</text><text x="2" y="14.5" font-size="8" font-weight="700" fill="currentColor">2</text><text x="2" y="20.5" font-size="8" font-weight="700" fill="currentColor">3</text></svg></button>
                      <button type="button" class="tc-fmt" data-cmd="quote" title="Quote" aria-label="Quote"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M6 5v14M10 8h8M10 12h8M10 16h5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg></button>
                      <span class="tc-fmtsep" aria-hidden="true"></span>
                      <button type="button" class="tc-fmt" data-cmd="code" title="Inline code" aria-label="Inline code"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M9 8 5 12l4 4M15 8l4 4-4 4" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                      <button type="button" class="tc-fmt" data-cmd="codeblock" title="Code block" aria-label="Code block"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="16" rx="2.5" stroke="currentColor" stroke-width="1.7"/><path d="M9 9 7 12l2 3M15 9l2 3-2 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                      <button type="button" class="tc-fmt" data-cmd="link" title="Link" aria-label="Link"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                    </div>
                    <button type="submit" class="pbtn pbtn-gold tc-send" aria-label="Send message"><span>Send</span><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                  </div>
                </form>
                <!-- Thread drawer -->
                <div class="tc-thread" id="tcThread" hidden aria-label="Thread">
                  <div class="tc-thread-h"><span>Thread</span><button type="button" class="pm-x" id="tcThreadClose" aria-label="Close thread">✕</button></div>
                  <div class="tc-thread-body" id="tcThreadBody"></div>
                  <form class="tc-compose tc-thread-compose" id="tcThreadForm" autocomplete="off">
                    <div class="tc-mentions" id="tcThreadMentions" hidden></div>
                    <div class="tc-rte tc-rte--sm" id="tcThreadInput" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Reply" data-ph="Reply… use @ to mention"></div>
                    <div class="tc-compose-bar">
                      <div class="tc-fmtbar" role="toolbar" aria-label="Text formatting">
                        <button type="button" class="tc-fmt" data-cmd="bold" title="Bold — Ctrl+B" aria-label="Bold"><b>B</b></button>
                        <button type="button" class="tc-fmt" data-cmd="italic" title="Italic — Ctrl+I" aria-label="Italic"><i>I</i></button>
                        <button type="button" class="tc-fmt" data-cmd="code" title="Inline code" aria-label="Inline code"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 8 5 12l4 4M15 8l4 4-4 4" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                        <button type="button" class="tc-fmt" data-cmd="link" title="Link" aria-label="Link"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                      </div>
                      <button type="submit" class="pbtn pbtn-gold tc-send">Reply</button>
                    </div>
                  </form>
                </div>
              </div>
              <!-- Members rail -->
              <aside class="tc-members" id="tcMembers" aria-label="Members"><p class="pc-empty">Loading…</p></aside>
            </div>
          </section>

          <!-- Catch-me-up recap modal -->
          <div class="pm-scrim" id="recapScrim" hidden>
            <div class="pm-modal" role="dialog" aria-modal="true" aria-labelledby="recapTitle">
              <div class="pm-head"><h2 id="recapTitle">✨ Catch me up</h2><button type="button" class="pm-x" id="recapClose" aria-label="Close">✕</button></div>
              <div class="pm-body"><div class="tc-recap" id="recapBody"><p class="pc-empty">Summarising the channel…</p></div></div>
            </div>
          </div>

          <!-- Saved items modal -->
          <div class="pm-scrim" id="savedScrim" hidden>
            <div class="pm-modal" role="dialog" aria-modal="true" aria-labelledby="savedTitle">
              <div class="pm-head"><h2 id="savedTitle">🔖 Saved items</h2><button type="button" class="pm-x" id="savedClose" aria-label="Close">✕</button></div>
              <div class="pm-body"><div class="tc-saved" id="savedBody"><p class="pc-empty">Loading…</p></div></div>
            </div>
          </div>

          <!-- Channel editor modal (admins) -->
          <div class="pm-scrim" id="chanScrim" hidden>
            <div class="pm-modal" role="dialog" aria-modal="true" aria-labelledby="chanTitle">
              <div class="pm-head"><h2 id="chanTitle">New channel</h2><button type="button" class="pm-x" id="chanClose" aria-label="Close">✕</button></div>
              <div class="pm-body">
                <form id="chanForm" autocomplete="off">
                  <input type="hidden" id="chanKey" value="">
                  <label class="pm-label">Name<input type="text" id="chanLabel" maxlength="60" placeholder="e.g. Coding practice" required></label>
                  <label class="pm-label">Topic<input type="text" id="chanTopic" maxlength="200" placeholder="What this channel is for"></label>
                  <label class="pm-check"><input type="checkbox" id="chanPrivate"> <span>Private — only chosen members can see it</span></label>
                  <div class="tc-memberpick" id="chanMemberPick" hidden>
                    <div class="pm-label" style="margin:0 0 4px">Members</div>
                    <div class="tc-memberpick-list" id="chanMemberList"></div>
                  </div>
                  <label class="pm-check"><input type="checkbox" id="chanGchatOn"> <span>Mirror to Google Chat (posts as the author)</span></label>
                  <label class="pm-label tc-gchat-space" id="chanGchatSpaceWrap" hidden>Google Chat space id<input type="text" id="chanGchatSpace" maxlength="120" placeholder="spaces/AAAA… or AAAA…"></label>
                  <div class="pm-actions"><button type="submit" class="pbtn pbtn-gold" id="chanSave">Create channel</button></div>
                  <p class="tc-chan-msg" id="chanMsg" hidden></p>
                </form>
              </div>
            </div>
          </div>
        </section>
<?php endif; /* end @afrovanguard-only Tasks + Team Chat */ ?>

        <!-- ============================================================ -->
        <!-- LEARNING                                                     -->
        <!-- ============================================================ -->
        <section class="pview" id="view-learning" data-view="learning" hidden>
          <div class="view-head"><h1>Learning</h1><a class="pcard-link" href="/academy/">Browse the Academy →</a></div>
          <section class="pcard">
            <div class="pcard-head"><h2>My learning</h2><span class="pchip pchip--indigo"><?= count($courses) ?> enrolled</span></div>
            <div class="pcard-body">
<?php if ($courses): ?>
              <p class="pcard-note"><b><?= count($courses) ?></b> programme<?= count($courses) === 1 ? '' : 's' ?><?= $inProgress ? ' · ' . $inProgress . ' in progress' : '' ?><?= $certs ? ' · ' . $certs . ' 🎓 certificate' . ($certs === 1 ? '' : 's') : '' ?></p>
              <div class="learn-list">
<?php foreach ($courses as $c): ?>                <a class="learn-row" href="/academy/<?= e($c['slug']) ?>/learn/">
                  <div class="learn-info"><span class="learn-title"><?= e($c['title']) ?></span><span class="learn-meta"><?= $c['complete'] ? '✓ Complete' : ((int) $c['pct']) . '% complete' ?><?= $c['certified'] ? ' · 🎓 Certified' : '' ?></span></div>
                  <div class="learn-bar" aria-hidden="true"><span style="width:<?= (int) $c['pct'] ?>%"></span></div>
                </a>
<?php endforeach; ?>              </div>
<?php else: ?>
              <p class="pc-empty">You haven’t joined a programme yet. <a href="/academy/">Explore the Academy →</a></p>
<?php endif; ?>
            </div>
          </section>
        </section>

        <!-- ============================================================ -->
        <!-- COMMUNITY  (the members' social space, embedded)             -->
        <!-- ============================================================ -->
        <section class="pview pview--community" id="view-community" data-view="community" hidden>
          <div class="view-head">
            <div>
              <h1>Community</h1>
              <p class="view-sub"><?= $isOrg ? 'Talk with members, share field notes, and ask the Afrovanguard bot.' : 'Share field notes and learn alongside the wider community.' ?></p>
            </div>
            <span class="ptop-online view-live"><span class="dot-live"></span>Live</span>
          </div>
<?php av_render_community((int) $u['id'], $isOrg, ['hero' => false, 'space' => $communitySpace]); ?>
        </section>

        <!-- ============================================================ -->
        <!-- MENTORSHIP                                                   -->
        <!-- ============================================================ -->
        <section class="pview" id="view-mentorship" data-view="mentorship" hidden>
          <div class="view-head"><h1>Mentorship</h1><a class="pcard-link" href="/mentorship/">Open the mentor network →</a></div>

          <!-- Hours logged + consistency -->
          <section class="pcard">
            <div class="pcard-head"><h2>Your mentorship</h2><span class="pchip pchip--gold"><?= $isOrg ? 'Member' : 'Open' ?></span></div>
            <div class="pcard-body">
              <div class="mentor-stats mentor-stats--lg">
                <div class="mstat"><span class="mstat-n"><?= e($mentorHoursLabel) ?></span><span class="mstat-l">Hours logged</span></div>
                <div class="mstat"><span class="mstat-n"><?= (int) $mentorStats['attended'] ?></span><span class="mstat-l">Sessions attended</span></div>
                <div class="mstat"><span class="mstat-n"><?= (int) $mentorStats['held'] ?></span><span class="mstat-l">Sessions held</span></div>
                <div class="mstat"><span class="mstat-n"><?= $mentorStats['rate'] !== null ? (int) $mentorStats['rate'] . '%' : '—' ?></span><span class="mstat-l">Consistency</span></div>
              </div>
              <p class="pcard-note"><?= $isOrg ? 'Find a mentor, run your mentee inbox, and give back by mentoring others. Attended sessions are counted toward your logged hours.' : 'Get paired with an Afrovanguard mentor for guidance on your journey. Attended sessions count toward your logged hours.' ?></p>
              <a class="pbtn pbtn-soft" href="/mentorship/">Open mentor network →</a>
            </div>
          </section>

          <!-- Upcoming schedule — start the Meet from here; start/end auto-logged -->
          <section class="pcard" id="mentorSchedule" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>Your schedule</h2><a class="pcard-link" href="/mentorship/">Manage →</a></div>
            <div class="pcard-body">
<?php if ($upcoming): ?>              <ul class="mini-sched">
<?php foreach (array_slice($upcoming, 0, 6) as $s): $sd = strtotime((string) $s['when'] . ' UTC') ?: time();
                $mst = $s['ended_at'] !== '' ? 'done' : ($s['live'] ? 'live' : 'idle'); ?>
                <li class="msi" data-session="<?= (int) $s['id'] ?>" data-meet="<?= e($s['meet_url']) ?>" data-state="<?= $mst ?>" data-started="<?= e($s['started_at']) ?>" data-source="<?= e((string) ($s['source'] ?? '')) ?>">
                  <div class="msi-top">
                    <span class="ms-when"><?= e(date('j M', $sd)) ?> · <?= e(date('g:ia', $sd)) ?></span>
                    <span class="ms-title"><?= e($s['title']) ?> <span class="ms-with">· <?= e($s['role']) ?> <?= e($s['with']) ?></span></span>
                  </div>
                  <div class="msi-meet">
                    <button type="button" class="pbtn pbtn-gold msi-start"<?= $mst === 'done' ? ' hidden' : '' ?>><?= $s['live'] ? 'Join meeting' : 'Start meeting' ?></button>
                    <span class="msi-log"><?php
                      if ($s['ended_at'] !== '') {
                          $src = (string) ($s['source'] ?? '');
                          $vcls = in_array($src, ['meet', 'reports'], true) ? 'msi-verified' : 'msi-provisional';
                          echo '✓ Logged ' . e(self_fmt_dur((int) $s['duration_min'])) . ' · ' . e(date('g:ia', strtotime($s['started_at'] . ' UTC') ?: time())) . '–' . e(date('g:ia', strtotime($s['ended_at'] . ' UTC') ?: time()));
                          echo ' <span class="msi-src ' . $vcls . '">' . e(self_meet_source($src)) . '</span>';
                      }
                      elseif ($s['live']) { echo '<span class="msi-live"><span class="dot-live"></span>Live · <span class="msi-timer" aria-label="Elapsed meeting time">…</span></span>'; }
                    ?></span>
                  </div>
                </li>
<?php endforeach; ?>              </ul>
              <p class="msi-note">Start the meeting from here — the system logs the hours automatically. Times are reconciled against Google Meet’s own record for complete transparency, so the logged hours reflect the real call.</p>
<?php else: ?>              <p class="pc-empty">No upcoming sessions. <a href="/mentorship/">Book one with your mentor →</a></p>
<?php endif; ?>
            </div>
          </section>
        </section>

<?php if ($isOrg):
        require_once AV_ROOT . '/lib/workspace.php';
        $wsAdmin    = LmsAuth::rank((string) $u['role']) >= LmsAuth::ROLE_RANK['admin'];
        $wsSurfaces = av_workspace_surfaces($wsAdmin);
        $wsOauth    = class_exists('GoogleWorkspaceUser') && GoogleWorkspaceUser::configured();
        $wsConn     = $wsOauth && GoogleWorkspaceUser::connected((int) $u['id']);
?>
        <!-- ============================================================ -->
        <!-- WORKSPACE                                                    -->
        <!-- ============================================================ -->
        <section class="pview" id="view-workspace" data-view="workspace" hidden>
          <div class="view-head"><h1>Workspace</h1><a class="pcard-link" href="/workspace">Open all →</a></div>

<?php if ($wsOauth && !$wsConn): ?>
          <!-- Not connected → clear call to action -->
          <section class="pcard ws-connect-card">
            <div class="pcard-body">
              <h2>Connect your Google Workspace</h2>
              <p class="pcard-note">Bring your Gmail, Calendar and Drive into the portal. You’ll sign in with Google once and can disconnect anytime.</p>
              <a class="pbtn pbtn-gold" href="/auth/google/connect?next=<?= rawurlencode('/portal/#workspace') ?>">Connect Google →</a>
            </div>
          </section>
<?php elseif ($wsConn): ?>
          <!-- Connected → live snapshot (fetched from /portal/workspace.php?action=me) -->
          <section class="pcard" id="wsLive" data-live>
            <div class="pcard-head"><h2>Your Google Workspace</h2><span class="pchip pchip--green"><span class="dot-live"></span>Connected</span></div>
            <div class="pcard-body">
              <div class="pkpis ws-live-kpis">
                <div class="pkpi"><div class="pkpi-top"><span class="pkpi-label">Unread mail</span></div><div class="pkpi-value" id="wsUnread">—</div><div class="pkpi-sub">in your inbox</div></div>
                <div class="pkpi"><div class="pkpi-top"><span class="pkpi-label">Next event</span></div><div class="pkpi-value" id="wsNextC" style="font-size:16px;line-height:1.3">—</div><div class="pkpi-sub" id="wsNextW"></div></div>
                <div class="pkpi"><div class="pkpi-top"><span class="pkpi-label">Recent files</span></div><div class="pkpi-value" id="wsFiles">—</div><div class="pkpi-sub">in your Drive</div></div>
              </div>
              <div class="ws-live-cols">
                <div><h3 class="ws-live-h">Recent mail</h3><ul class="ws-live-list" id="wsMail"><li class="pc-empty">Loading…</li></ul></div>
                <div><h3 class="ws-live-h">Upcoming</h3><ul class="ws-live-list" id="wsEvents"><li class="pc-empty">Loading…</li></ul></div>
              </div>
            </div>
          </section>

          <!-- Team Chat — the native, in-portal chat (replaces the old Google Chat tile) -->
          <section class="pcard ws-teamchat-card">
            <div class="pcard-head"><h2>Team Chat</h2><span class="pchip pchip--green"><span class="dot-live"></span>Live · in-portal</span></div>
            <div class="pcard-body">
              <p class="pcard-note">Your team's real-time chat lives right here in the portal — channels, threads, @mentions, reactions, saved items and AI catch-up. Admins can mirror any channel into a Google Chat space, posted as the author.</p>
              <div class="pws-inline-actions">
                <a class="pbtn pbtn-gold" href="#chat" data-goto="chat">Open Team Chat →</a>
                <a class="pbtn pbtn-ghost" href="https://chat.google.com/" target="_blank" rel="noopener noreferrer">Google Chat ↗</a>
              </div>
            </div>
          </section>
<?php else: /* Google OAuth not configured on this deployment — be honest about why nothing fetches */ ?>
          <section class="pcard ws-connect-card">
            <div class="pcard-body">
              <h2>Live Google Workspace isn’t enabled yet</h2>
              <p class="pcard-note">Your Gmail, Calendar and Drive can appear here live — but this site first needs its Google Workspace connection switched on by an administrator (the Google OAuth credentials). Until then, use the app launchpad below to jump straight into each tool.</p>
              <a class="pbtn pbtn-soft" href="/workspace">Open the Workspace hub →</a>
            </div>
          </section>
<?php endif; ?>

          <section class="pcard">
            <div class="pcard-head">
              <div><h2>Your Workspace apps</h2><p class="pcard-sub">Signed in via Google · @<?= e(av_workspace_domain()) ?></p></div>
            </div>
            <div class="pcard-body pws-grid">
<?php foreach ($wsSurfaces as $s): $wsInt = !empty($s['internal']); ?>              <a class="pws-app" href="<?= e($s['url']) ?>"<?= $wsInt ? ' data-goto="chat"' : ' target="_blank" rel="noopener noreferrer"' ?>>
                <span class="pws-ico pws-ico--<?= e($s['key']) ?>"><?= av_workspace_icon($s['icon']) ?></span>
                <span class="pws-text"><span class="pws-name"><?= e($s['label']) ?></span><span class="pws-desc"><?= e($s['desc']) ?></span><?= $wsInt ? '' : '' ?></span>
              </a>
<?php endforeach; ?>            </div>
          </section>
        </section>
<?php endif; ?>

        <!-- ============================================================ -->
        <!-- MEMBERSHIP / ACCOUNT                                         -->
        <!-- ============================================================ -->
        <section class="pview" id="view-membership" data-view="membership" hidden>
          <div class="view-head"><h1><?= $isOrg ? 'Membership' : 'Account' ?></h1></div>
          <div class="pcols">
            <div class="pcol pcol--main">
<?php if ($isOrg && $dues):
              // Dues fee amounts are intentionally NOT shown on the portal or the
              // public site — only the member's own "Total dues paid" and status.
              $duesPT   = $dues['paid_through'] ? date('j M Y', (int) strtotime((string) $dues['paid_through'])) : null;
              $duesPill = ['active' => 'Current', 'due_soon' => 'Due soon', 'overdue' => 'Overdue', 'none' => 'Not paid'][$duesState] ?? 'Dues';
              if (!empty($dues['lifetime'])) $duesPill = 'Lifetime';
              $duesTone = (!empty($dues['lifetime']) || $duesState === 'active') ? 'green' : ($duesState === 'overdue' ? 'red' : 'gold');
              $duesCanPay = !empty($dues['payable']) && empty($dues['lifetime']);
              $duesRecurring = defined('AV_DUES_PLAN_CODE') && AV_DUES_PLAN_CODE;
              $duesN = (int) ($dues['payments_count'] ?? 0);
?>
              <!-- Membership dues -->
              <section class="pcard dues-card dues-<?= e($duesState) ?>" id="membership" data-csrf="<?= e($duesCsrf) ?>">
                <div class="pcard-head"><h2>Membership dues</h2><span class="pchip pchip--<?= e($duesTone) ?>"><?= e($duesPill) ?></span></div>
                <div class="pcard-body">
<?php if ($duesTotal > 0): ?>                  <div class="dues-total-row"><span>Total dues paid</span><strong>₦<?= number_format($duesTotal) ?></strong><span class="dues-total-n">· <?= $duesN ?> payment<?= $duesN === 1 ? '' : 's' ?></span></div>
<?php endif; ?>
<?php if (!empty($dues['lifetime'])): ?>                  <p class="dues-line ok">✓ <strong>Lifetime membership</strong> — no dues due.</p>
<?php elseif ($duesState === 'active'): ?>                  <p class="dues-line ok">✓ Paid<?= $duesPT ? ' through <strong>' . e($duesPT) . '</strong>' : '' ?>.</p>
<?php elseif ($duesState === 'overdue'): ?>                  <p class="dues-line warn">⚠ Lapsed<?= $duesPT ? ' on <strong>' . e($duesPT) . '</strong>' : '' ?> — please renew.</p>
<?php elseif ($duesState === 'due_soon'): ?>                  <p class="dues-line warn">⏳ Renew soon to stay current.</p>
<?php endif; ?>
<?php if ($duesCanPay): ?>                  <div class="dues-actions">
                    <button type="button" class="pbtn <?= $duesState === 'active' ? 'pbtn-ghost' : 'pbtn-gold' ?>" data-dues-pay data-period="year"><?= $duesState === 'active' ? 'Renew a year' : 'Pay a year' ?></button>
                    <button type="button" class="pbtn pbtn-ghost" data-dues-pay data-period="month"><?= $duesRecurring ? 'Monthly' : 'Pay a month' ?></button>
                  </div>
                  <p class="enroll-msg dues-msg" hidden></p>
<?php else: ?>                  <div class="dues-note-box">Online payment isn’t available yet — <a href="mailto:cacentre@afrovanguard.org.ng">contact us to pay</a>.</div>
<?php endif; ?>
                </div>
              </section>
<?php endif; ?>
              <!-- Membership / account details -->
              <section class="pcard"<?= $isOrg ? '' : ' id="membership"' ?>>
                <div class="pcard-head"><h2><?= $isOrg ? 'Membership' : 'Account' ?></h2><span class="pchip pchip--<?= $isOrg ? 'green' : 'indigo' ?>"><?= $isOrg ? 'Active' : 'Learner' ?></span></div>
                <div class="pcard-body pdl">
                  <div class="pdl-row"><span>Name</span><strong><?= e($u['name']) ?></strong></div>
                  <div class="pdl-row"><span>Email</span><strong><?= e($u['email']) ?></strong></div>
                  <div class="pdl-row"><span><?= $isOrg ? 'Access' : 'Account' ?></span><strong class="<?= $isOrg ? 'ok' : '' ?>"><?= $isOrg ? e($accessLevel) : 'Learner' ?></strong></div>
                </div>
              </section>
<?php if ($isOrg): ?>
              <!-- Members-only continental scaling plan -->
              <section class="pcard">
                <div class="pcard-head"><h2>Continental Scaling Plan</h2><span class="pchip pchip--gold">🔒 Members</span></div>
                <div class="pcard-body">
                  <p class="pcard-note">The 2026–2040 strategic plan to one million incorruptible C-Level leaders — the phase-by-phase CACENTRE growth path, output targets and org structure. Restricted to Afrovanguard members.</p>
                  <a class="pbtn pbtn-soft" href="/blueprint">Read the plan →</a>
                </div>
              </section>
<?php endif; ?>
            </div>
          </div>
        </section>

        <!-- ============================================================ -->
        <!-- DIARY                                                        -->
        <!-- ============================================================ -->
        <section class="pview" id="view-diary" data-view="diary" hidden>
          <div class="view-head">
            <div><h1>My Diary</h1><p class="view-sub">Write with a rich editor. Private stays yours; public is reviewed before it joins the Diary.</p></div>
            <a class="pcard-link" href="/diary/" target="_blank" rel="noopener">Open the public Diary →</a>
          </div>
          <div class="pcols pcols--diary">
            <div class="pcol pcol--main">
              <section class="pcard" id="pdiary">
                <div class="pcard-body">
                  <form id="pdCompose" class="pd-form" autocomplete="off">
                    <div class="pd-meta">
                      <label class="pd-field"><span>Category</span>
                        <select id="pdKind">
                          <option value="private" selected>🔒 Private — only you</option>
                          <option value="public">🌐 Public — submit to the Diary</option>
                          <option value="event">📅 Event — a public happening</option>
                        </select>
                      </label>
                      <label class="pd-field"><span>Template</span>
                        <select id="pdTemplate">
                          <option value="">Blank page</option>
                          <option value="daily">Daily reflection</option>
                          <option value="field">Field note</option>
                          <option value="project">Project log</option>
                          <option value="meeting">Meeting notes</option>
                          <option value="gratitude">Gratitude</option>
                          <option value="weekly">Weekly review</option>
                          <option value="idea">Idea / proposal</option>
                        </select>
                      </label>
                      <label class="pd-field"><span>Date</span>
                        <input type="date" id="pdDate" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>">
                      </label>
                    </div>
                    <label class="pd-field pd-field--title"><span>Title <em>(optional)</em></span>
                      <input type="text" id="pdTitle" maxlength="160" placeholder="A short headline">
                    </label>
                    <input type="hidden" id="pdBody" name="body">
                    <trix-editor input="pdBody" class="pd-editor" placeholder="Write your entry…"></trix-editor>
                    <div class="pd-foot">
                      <button type="submit" class="pbtn pbtn-gold" id="pdSave">Save entry</button>
                      <span class="pd-hint" id="pdHint">🔒 Private entries are visible only to you.</span>
                      <span class="pd-msg" id="pdMsg" role="status" aria-live="polite"></span>
                    </div>
                  </form>
                </div>
              </section>
            </div>
            <div class="pcol pcol--side">
              <section class="pcard">
                <div class="pcard-head"><h2>Your entries</h2><span class="pchip pchip--gray" id="pdCount"><?= count($myEntries) ?></span></div>
                <div class="pcard-body">
                  <ul class="pd-list" id="pdList">
<?php if ($myEntries): foreach ($myEntries as $e) { echo self_diary_item($e); } else: ?>
                    <li class="pc-empty" id="pdEmpty">No entries yet — write your first above.</li>
<?php endif; ?>
                  </ul>
                </div>
              </section>
              <section class="pcard" id="pdSharedCard" hidden>
                <div class="pcard-head"><h2>Shared with me</h2><span class="pchip pchip--indigo" id="pdSharedCount">0</span></div>
                <div class="pcard-body"><ul class="pd-list" id="pdShared"></ul></div>
              </section>
            </div>
          </div>

          <!-- read modal for shared-with-me entries -->
          <div class="pd-modal" id="pdModal" hidden>
            <div class="pd-modal-back" data-pdclose></div>
            <div class="pd-modal-card" role="dialog" aria-modal="true" aria-labelledby="pdModalTitle">
              <button type="button" class="pd-modal-x" data-pdclose aria-label="Close">✕</button>
              <h2 id="pdModalTitle"></h2>
              <p class="pd-modal-meta" id="pdModalMeta"></p>
              <div class="pd-modal-body" id="pdModalBody"></div>
            </div>
          </div>
        </section>

        <footer class="pfoot">
          <span>© 2026 Afrovanguard</span>
          <span><a href="<?= e(rtrim(SITE_URL, '/')) ?>/">Main site ↗</a> · <a href="mailto:cacentre@afrovanguard.org.ng">Support</a></span>
        </footer>
      </div>
    </main>
  </div>

  <script>
  (function () {
    /* Theme toggle — also mirror onto <html data-theme> so the embedded
       Community (community.css keys off [data-theme]) follows the portal. */
    var tbtn = document.getElementById('portalTheme');
    function syncTheme(){ var dark=document.body.classList.contains('is-dark'); document.documentElement.setAttribute('data-theme', dark?'dark':'light'); }
    function paintTheme(){ var dark=document.body.classList.contains('is-dark'); var s=tbtn&&tbtn.querySelector('.ico-sun'), m=tbtn&&tbtn.querySelector('.ico-moon'); if(s)s.style.display=dark?'block':'none'; if(m)m.style.display=dark?'none':'block'; syncTheme(); }
    if (tbtn) tbtn.addEventListener('click', function(){ var dark=document.body.classList.toggle('is-dark'); document.cookie='av_portal_theme='+(dark?'dark':'light')+';path=/;max-age=31536000;samesite=Lax'; paintTheme(); });
    paintTheme();

    /* Sidebar drawer (mobile) */
    var toggle=document.getElementById('sideToggle'), scrim=document.getElementById('portalScrim');
    function setOpen(on){ document.body.classList.toggle('side-open', on); if(scrim) scrim.hidden=!on; if(toggle) toggle.setAttribute('aria-expanded', on?'true':'false'); }
    if (toggle) toggle.addEventListener('click', function(){ setOpen(!document.body.classList.contains('side-open')); });
    if (scrim) scrim.addEventListener('click', function(){ setOpen(false); });

    /* Sidebar: multi-tab view switcher (show one .pview at a time) + breadcrumb.
       Hash-routed so tabs are linkable and the back button works. */
    var links = [].slice.call(document.querySelectorAll('.pnav-link[data-view]'));
    var views = [].slice.call(document.querySelectorAll('.pview'));
    var scroller = document.querySelector('.portal-scroll');
    var crumb = document.getElementById('crumbHere');
    var labelFor = {}; links.forEach(function(a){ labelFor[a.getAttribute('data-view')] = (a.querySelector('.pnav-label')||a).textContent.trim(); });

    function showView(name, push){
      var found = false;
      views.forEach(function(v){ var on = v.getAttribute('data-view') === name; v.hidden = !on; if(on) found = true; });
      if (!found) { name = 'overview'; views.forEach(function(v){ v.hidden = v.getAttribute('data-view') !== 'overview'; }); }
      links.forEach(function(l){ l.classList.toggle('is-active', l.getAttribute('data-view') === name); });
      if (crumb) crumb.textContent = labelFor[name] || 'Dashboard';
      if (scroller) scroller.scrollTop = 0;
      if (push && ('#'+name) !== location.hash) { try { history.pushState(null, '', '#'+name); } catch(e) { location.hash = name; } }
    }

    links.forEach(function(a){
      a.addEventListener('click', function(e){
        e.preventDefault();
        showView(a.getAttribute('data-view'), true);
        if (window.innerWidth < 960) setOpen(false);
      });
    });
    // Non-view nav links (Academy / Main site) just close the mobile drawer.
    document.querySelectorAll('.pnav-link:not([data-view])').forEach(function(a){ a.addEventListener('click', function(){ if(window.innerWidth<960) setOpen(false); }); });
    // In-page "View mentorship →" style jumps.
    document.querySelectorAll('[data-goto]').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); showView(a.getAttribute('data-goto'), true); }); });
    // Deep-link + back/forward support.
    window.addEventListener('hashchange', function(){ showView((location.hash||'').replace('#',''), false); });
    showView((location.hash||'').replace('#','') || 'overview', false);

    /* Sidebar search → filter nav items */
    var search=document.getElementById('pSearch');
    if (search) search.addEventListener('input', function(){ var q=this.value.trim().toLowerCase();
      document.querySelectorAll('.pnav-link').forEach(function(a){ var t=a.textContent.toLowerCase(); a.style.display=(!q||t.indexOf(q)>=0)?'':'none'; }); });

    /* Copy invite link */
    var ci=document.getElementById('copyInvite');
    if (ci) ci.addEventListener('click', function(){ var u=ci.getAttribute('data-url')||''; if(navigator.clipboard) navigator.clipboard.writeText(u).catch(function(){}); var t=ci.textContent; ci.textContent='Copied'; ci.classList.add('is-ok'); setTimeout(function(){ci.textContent=t; ci.classList.remove('is-ok');},1500); });
  })();
  </script>

  <script>
  /* Collaboration — presence, activity, tasks (with the filter chips). */
  (function () {
    var root = document.getElementById('tasks'); if (!root) return;
    var csrf = root.getAttribute('data-csrf') || '';
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function post(action, body){ return fetch('/portal/collab.php?action='+action,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body||{})}).then(function(r){return r.json();}); }
    var listEl=document.getElementById('taskList'), actEl=document.getElementById('activityList'),
        onlineEl=document.getElementById('onlineList'), onlineCountEl=document.getElementById('onlineCount'),
        topOnline=document.getElementById('topOnline'), tbCount=document.getElementById('tbCount'),
        kpiTasks=document.getElementById('kpiTasks'), kpiOnline=document.getElementById('kpiOnline'),
        fcAll=document.getElementById('fcAll'), fcOpen=document.getElementById('fcOpen'), fcDone=document.getElementById('fcDone'),
        fcOver=document.getElementById('fcOver'), fcMine=document.getElementById('fcMine');
    var poolEl=document.getElementById('poolList'), poolCountEl=document.getElementById('poolCount'),
        aiCard=document.getElementById('taskAi'), aiGoalSel=document.getElementById('aiGoal'),
        aiMaxSel=document.getElementById('aiMax'), aiBtn=document.getElementById('aiGenerate'), aiMsgEl=document.getElementById('aiMsg'),
        poolChk=document.getElementById('taskPool'),
        focusWrap=document.getElementById('taskFocus'), ringEl=document.getElementById('taskRing'),
        ringNEl=document.getElementById('taskRingN'), focusTxt=document.getElementById('taskFocusTxt');
    var TASKS=[], POOL=[], GOALS=[], FILTER='all', SHOW_DONE=false;
    function ymd(off){ var d=new Date(); d.setHours(0,0,0,0); d.setDate(d.getDate()+(off||0)); return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2); }
    var TODAY=ymd(0), TOMORROW=ymd(1), WEEK_END=ymd(7);
    function isOverdue(t){ return !t.done && t.due && (t.overdue || t.due < TODAY); }
    function counts(){ var open=TASKS.filter(function(t){return !t.done;}).length, done=TASKS.length-open,
        over=TASKS.filter(isOverdue).length, mine=TASKS.filter(function(t){return t.mine && !t.done;}).length;
      if(fcAll)fcAll.textContent=TASKS.length; if(fcOpen)fcOpen.textContent=open; if(fcDone)fcDone.textContent=done;
      if(fcOver)fcOver.textContent=over; if(fcMine)fcMine.textContent=mine;
      if(kpiTasks)kpiTasks.textContent=open; }
    // Relative, urgency-aware due label + tone.
    function dueMeta(t){ if(!t.due) return null;
      var tone = isOverdue(t) ? 'over' : (t.due===TODAY ? 'today' : (t.due===TOMORROW ? 'soon' : ''));
      var label; if(isOverdue(t)) label='Overdue'; else if(t.due===TODAY) label='Today'; else if(t.due===TOMORROW) label='Tomorrow';
      else { var d=new Date(t.due+'T00:00:00'); label=isNaN(d)?t.due:d.toLocaleDateString(undefined,(t.due<ymd(365)?{weekday:'short',month:'short',day:'numeric'}:{month:'short',day:'numeric',year:'numeric'})); }
      return {label:label, tone:tone}; }
    function priTag(t){ if(t.priority==='high') return '<span class="task-flag task-flag--high" title="High priority">High</span>';
      if(t.priority==='low') return '<span class="task-flag task-flag--low" title="Low priority">Low</span>'; return ''; }
    // Which urgency bucket an open task belongs in.
    function bucketOf(t){ if(t.done) return 'done'; if(!t.due) return 'nodate';
      if(t.due<TODAY) return 'over'; if(t.due===TODAY) return 'today'; if(t.due===TOMORROW) return 'tom';
      if(t.due<=WEEK_END) return 'week'; return 'later'; }
    var BUCKETS=[['over','Overdue'],['today','Today'],['tom','Tomorrow'],['week','This week'],['later','Later'],['nodate','No date']];
    function taskHtml(t){
      var who = t.assigned_out ? ('→ '+esc(t.assignee_name)) : (t.mine ? '' : ('from '+esc(t.creator_name)));
      var dm = (t.due&&!t.done) ? dueMeta(t) : null;
      var due = dm ? '<span class="task-due'+(dm.tone?' is-'+dm.tone:'')+'">'+esc(dm.label)+'</span>' : '';
      var goal = t.goal_title ? ' <span class="task-goal" title="Advances a goal">◎ '+esc(t.goal_title)+'</span>' : '';
      var rel = (t.mine && !t.done) ? '<button type="button" class="task-release" title="Return to the pool" aria-label="Return to pool">↩</button>' : '';
      return '<li class="task task--pri-'+esc(t.priority||'normal')+(t.done?' is-done':'')+'" data-id="'+t.id+'">'
      +'<button type="button" class="task-check" aria-label="Toggle done">'+(t.done?'✓':'')+'</button>'
      +'<span class="task-title">'+esc(t.title)+(who?' <span class="task-who">'+who+'</span>':'')+goal+'</span>'
      +priTag(t)+due+rel+'<button type="button" class="task-del" aria-label="Delete task">✕</button></li>'; }
    function poolHtml(t){
      var dm = t.due ? dueMeta(t) : null;
      var due = dm ? '<span class="task-due'+(dm.tone?' is-'+dm.tone:'')+'">'+esc(dm.label)+'</span>' : '';
      var goal = t.goal_title ? '<span class="task-goal" title="Advances a goal">◎ '+esc(t.goal_title)+'</span>' : '';
      var by = (t.creator_name && t.creator_name!=='You') ? '<span class="task-who">by '+esc(t.creator_name)+'</span>' : '';
      return '<li class="task task-pool-item task--pri-'+esc(t.priority||'normal')+'" data-id="'+t.id+'">'
      +'<span class="task-body"><span class="task-title">'+esc(t.title)+'</span>'
      +'<span class="task-sub">'+priTag(t)+by+goal+due+'</span></span>'
      +'<button type="button" class="pbtn pbtn-gold task-claim">Claim</button></li>'; }
    function renderPool(){
      if(!poolEl) return;
      poolEl.innerHTML = POOL.length ? POOL.map(poolHtml).join('') : '<li class="pc-empty task-empty">The pool is empty — nice work. Add an open task or plan a goal with AI.</li>';
      if(poolCountEl) poolCountEl.textContent = POOL.length + ' open';
    }
    function fillGoals(){
      if(!aiGoalSel) return;
      if(!GOALS.length){ aiGoalSel.innerHTML='<option value="">No open goals — add one in Suite → Goals</option>'; if(aiBtn)aiBtn.disabled=true; return; }
      aiGoalSel.innerHTML = GOALS.map(function(g){ return '<option value="'+g.id+'">'+esc(g.title)+'</option>'; }).join('');
      if(aiBtn)aiBtn.disabled=false;
    }
    function aiSay(msg,tone){ if(!aiMsgEl) return; aiMsgEl.hidden=!msg; aiMsgEl.textContent=msg||''; aiMsgEl.className='task-ai-msg'+(tone?(' is-'+tone):''); }
    function fillRoster(roster){ var sel=document.getElementById('taskAssignee'); if(!sel||!roster) return;
      var cur=sel.value; sel.innerHTML='<option value="0">Me</option>'+roster.map(function(m){ return '<option value="'+m.id+'">'+esc(m.name)+'</option>'; }).join(''); sel.value=cur; }
    function groupHtml(label,n,extra){ return '<li class="task-group'+(extra||'')+'"><span class="task-group-label">'+esc(label)+'</span><span class="task-group-n">'+n+'</span></li>'; }
    function render(){
      counts(); renderFocus();
      // Flat lenses: overdue / mine / done render as a simple filtered list.
      if(FILTER==='overdue'||FILTER==='mine'||FILTER==='done'){
        var rows=TASKS.filter(function(t){ if(FILTER==='done')return t.done; if(FILTER==='overdue')return isOverdue(t); return t.mine && !t.done; });
        listEl.innerHTML = rows.length ? rows.map(taskHtml).join('') : '<li class="pc-empty task-empty">Nothing here.</li>';
        return;
      }
      // Default: group open tasks by urgency; completed folds into a collapsible tail.
      var open=TASKS.filter(function(t){return !t.done;}), done=TASKS.filter(function(t){return t.done;});
      if(!open.length && !(FILTER==='all'&&done.length)){
        listEl.innerHTML='<li class="pc-empty task-empty">✳ Inbox zero. Nothing on your plate — grab one from the pool or plan a goal.</li>'; return; }
      var byB={}; open.forEach(function(t){ var b=bucketOf(t); (byB[b]=byB[b]||[]).push(t); });
      var html='';
      BUCKETS.forEach(function(bk){ var arr=byB[bk[0]]; if(!arr||!arr.length) return;
        html+=groupHtml(bk[1],arr.length,(bk[0]==='over'?' is-over':(bk[0]==='today'?' is-today':'')))+arr.map(taskHtml).join(''); });
      if(FILTER==='all'&&done.length){
        html+='<li class="task-group task-group--done" id="doneToggle">'+
          '<span class="task-group-label">Completed <span class="task-caret">'+(SHOW_DONE?'▾':'▸')+'</span></span>'+
          '<span class="task-group-n">'+done.length+'</span></li>';
        if(SHOW_DONE) html+=done.map(taskHtml).join('');
      }
      listEl.innerHTML=html;
    }
    function renderFocus(){
      if(!focusWrap) return;
      // Today's focus = everything due today or already overdue (the actionable set).
      var set=TASKS.filter(function(t){ return t.due && t.due<=TODAY; });
      var total=set.length, doneN=set.filter(function(t){return t.done;}).length, left=total-doneN;
      if(!total){ focusWrap.hidden=true; if(focusTxt)focusTxt.textContent = TASKS.filter(function(t){return !t.done;}).length ? 'Nothing due today — you’re ahead.' : 'All clear. Add a task to get going.'; return; }
      focusWrap.hidden=false;
      var pct=Math.round(100*doneN/total);
      if(ringEl) ringEl.style.setProperty('--pct', pct);
      if(ringEl) ringEl.classList.toggle('is-complete', left===0);
      if(ringNEl) ringNEl.textContent = left===0 ? '✓' : left;
      if(focusTxt) focusTxt.textContent = left===0 ? ('Today’s done — '+doneN+' cleared. 🎉') : (left+' of '+total+' left today · '+pct+'% done');
    }
    function renderOnline(users,count){ if(onlineCountEl)onlineCountEl.textContent=count||0; if(tbCount)tbCount.textContent=count||0; if(kpiOnline)kpiOnline.textContent=count||0; if(topOnline)topOnline.hidden=!(count>0);
      users=users||[]; onlineEl.innerHTML = users.length ? users.map(function(u){ return '<div class="online-row"><span class="online-ava is-'+esc(u.status)+'">'+esc(u.initials)+'</span><span class="online-name">'+esc(u.name)+'</span></div>'; }).join('') : '<p class="pc-empty">Just you so far.</p>'; }
    function renderActivity(items){ items=items||[]; actEl.innerHTML = items.length ? items.map(function(a){ var obj=a.object?' <b>'+esc(a.object)+'</b>':''; var inner='<span class="act-ava">'+esc(a.initials)+'</span><span class="act-body"><span class="act-line"><b>'+esc(a.actor)+'</b> '+esc(a.verb)+obj+'</span><span class="act-ago">'+esc(a.ago)+'</span></span>'; return '<li class="act">'+(a.url?'<a href="'+esc(a.url)+'">'+inner+'</a>':inner)+'</li>'; }).join('') : '<li class="pc-empty">No activity yet.</li>'; }

    function load(){ fetch('/portal/collab.php?action=bootstrap',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){ if(!d||!d.ok) return;
        TASKS=d.tasks||[]; POOL=d.pool||[]; GOALS=d.goals||[];
        render(); renderPool(); renderActivity(d.activity); renderOnline(d.online,d.count); fillRoster(d.roster); fillGoals();
        if(aiCard) aiCard.hidden = !d.ai;   // only show the AI planner when Claude is wired up
      }).catch(function(){}); }

    // filter chips
    document.querySelectorAll('#taskFilters .pseg-btn').forEach(function(b){ b.addEventListener('click', function(){ document.querySelectorAll('#taskFilters .pseg-btn').forEach(function(x){x.classList.remove('is-on');}); b.classList.add('is-on'); FILTER=b.getAttribute('data-filter'); render(); }); });
    // add
    var form=document.getElementById('taskAdd'), input=document.getElementById('taskInput');
    form.addEventListener('submit', function(e){ e.preventDefault(); var title=(input.value||'').trim(); if(!title) return;
      var asel=document.getElementById('taskAssignee'); var assignee=asel?(+asel.value||0):0;
      var dsel=document.getElementById('taskDue'); var due=dsel?dsel.value:'';
      var psel=document.getElementById('taskPriority'); var priority=psel?psel.value:'normal';
      var toPool = poolChk && poolChk.checked;
      input.value=''; input.disabled=true;
      if(toPool){
        post('task_add_pool',{title:title, due:due, priority:priority}).then(function(d){ input.disabled=false; input.focus(); if(dsel)dsel.value=''; if(psel)psel.value='normal'; poolChk.checked=false; if(d&&d.ok&&d.task){ POOL.unshift(d.task); renderPool(); } }).catch(function(){ input.disabled=false; });
      } else {
        post('task_add',{title:title, assignee:assignee, due:due, priority:priority}).then(function(d){ input.disabled=false; input.focus(); if(asel)asel.value='0'; if(dsel)dsel.value=''; if(psel)psel.value='normal'; if(d&&d.ok&&d.task){ TASKS.unshift(d.task); render(); } }).catch(function(){ input.disabled=false; });
      } });
    // toggle / delete / release
    listEl.addEventListener('click', function(e){
      if(e.target.closest('#doneToggle')){ SHOW_DONE=!SHOW_DONE; render(); return; }
      var li=e.target.closest('.task'); if(!li) return; var id=+li.getAttribute('data-id');
      if(e.target.closest('.task-check')){ post('task_toggle',{id:id}).then(function(d){ if(d&&d.ok){ TASKS=TASKS.map(function(t){return t.id===id?Object.assign({},t,{done:d.done}):t;}); render(); } }); }
      else if(e.target.closest('.task-release')){ var rt=TASKS.filter(function(t){return t.id===id;})[0]; post('task_release',{id:id}).then(function(d){ if(d&&d.ok){ TASKS=TASKS.filter(function(t){return t.id!==id;}); render(); if(rt){ POOL.unshift(Object.assign({},rt,{open:true,mine:false,assignee_id:0,assignee_name:'Unclaimed'})); renderPool(); } } }); }
      else if(e.target.closest('.task-del')){ post('task_delete',{id:id}).then(function(d){ if(d&&d.ok){ TASKS=TASKS.filter(function(t){return t.id!==id;}); render(); } }); } });

    // claim from the pool
    if(poolEl) poolEl.addEventListener('click', function(e){ var btn=e.target.closest('.task-claim'); if(!btn) return; var li=e.target.closest('.task'); if(!li) return; var id=+li.getAttribute('data-id');
      btn.disabled=true; btn.textContent='Claiming…';
      post('task_claim',{id:id}).then(function(d){ if(d&&d.ok&&d.task){ POOL=POOL.filter(function(t){return t.id!==id;}); renderPool(); TASKS.unshift(d.task); render(); }
        else { btn.disabled=false; btn.textContent='Claim'; if(d&&d.error){ btn.textContent='Taken'; POOL=POOL.filter(function(t){return t.id!==id;}); setTimeout(renderPool,900); } } }).catch(function(){ btn.disabled=false; btn.textContent='Claim'; }); });

    // AI: plan a goal into pooled tasks
    if(aiBtn) aiBtn.addEventListener('click', function(){ var gid=aiGoalSel?(+aiGoalSel.value||0):0; if(!gid){ aiSay('Pick a goal first.','warn'); return; }
      var max=aiMaxSel?(+aiMaxSel.value||6):6; aiBtn.disabled=true; var old=aiBtn.textContent; aiBtn.textContent='Thinking…'; aiSay('Planning your goal into tasks…','');
      post('ai_from_goal',{goal_id:gid, max:max}).then(function(d){ aiBtn.disabled=false; aiBtn.textContent=old;
        if(d&&d.ok&&d.created&&d.created.length){ d.created.forEach(function(t){ POOL.unshift(t); }); renderPool(); aiSay('Added '+d.created.length+' task'+(d.created.length===1?'':'s')+' to the pool — claim what you can take on.','ok'); }
        else { aiSay((d&&d.error)||'Could not generate tasks. Try again.','warn'); } }).catch(function(){ aiBtn.disabled=false; aiBtn.textContent=old; aiSay('Network error — try again.','warn'); }); });

    load();
    setInterval(function(){ post('heartbeat',{}).then(function(d){ if(d&&typeof d.count==='number'){ if(onlineCountEl)onlineCountEl.textContent=d.count; if(tbCount)tbCount.textContent=d.count; if(kpiOnline)kpiOnline.textContent=d.count; if(topOnline)topOnline.hidden=!(d.count>0); } }).catch(function(){}); }, 45000);
    setInterval(load, 90000);
  })();

  /* Today tab — the general calendar agenda: events, meetings (with Meet join
     links), mentorship sessions, due tasks and reminders, next 14 days. */
  (function(){
    var box=document.getElementById('calAgenda'); if(!box) return;
    var weekEl=document.getElementById('calWeek'), sumEl=document.getElementById('calSummary');
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function ymd(off){ var d=new Date(); d.setHours(0,0,0,0); d.setDate(d.getDate()+(off||0)); return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2); }
    var TODAY=ymd(0), TOMORROW=ymd(1), from=TODAY, to=ymd(14);
    var ICON={event:'📌',afg:'🎟️',session:'🎥',meeting:'🎥',task:'✓',reminder:'⏰',gcal:'📆'};
    function timeLabel(t){ if(!t) return 'All day'; var p=t.split(':'); var h=+p[0]; var ap=h<12?'AM':'PM'; return ((h%12)||12)+':'+p[1]+' '+ap; }
    function dayHead(d,n){ var dt=new Date(d+'T00:00:00');
      var name=isNaN(dt)?d:dt.toLocaleDateString(undefined,{weekday:'long'});
      var date=isNaN(dt)?'':dt.toLocaleDateString(undefined,{month:'short',day:'numeric'});
      var pill=d===TODAY?'<span class="cal-pill cal-pill--today">Today</span>':(d===TOMORROW?'<span class="cal-pill">Tomorrow</span>':'');
      return '<div class="cal-day-h"><span class="cal-day-name">'+esc(name)+'</span><span class="cal-day-date">'+esc(date)+'</span>'+pill+'<span class="cal-day-count">'+n+'</span></div>'; }
    function itemHtml(it){
      var ico=ICON[it.kind]||'•';
      var isMeet=(it.kind==='meeting'||it.kind==='session');
      var over=it.kind==='task'&&it.date<TODAY;
      var join = it.url ? '<a class="cal-join'+(isMeet?'':' cal-join--ghost')+'" href="'+esc(it.url)+'" target="_blank" rel="noopener">'+(isMeet?'Join':'Open')+'</a>' : '';
      var meta=[]; if(it.location)meta.push(esc(it.location)); if(it.who&&it.who!=='You')meta.push(esc(it.who)); if(!meta.length&&it.note)meta.push(esc(it.note));
      return '<li class="cal-item cal-'+esc(it.kind)+(over?' is-over':'')+'">'
        +'<span class="cal-time'+(it.time?'':' is-allday')+'">'+esc(timeLabel(it.time))+'</span>'
        +'<span class="cal-ico">'+ico+'</span>'
        +'<span class="cal-body"><span class="cal-title">'+esc(it.title)+'</span>'
        +(meta.length?'<span class="cal-meta">'+meta.join(' · ')+'</span>':'')+'</span>'+join+'</li>';
    }
    function renderWeek(items){
      if(!weekEl) return; var cnt={}; items.forEach(function(it){ cnt[it.date]=(cnt[it.date]||0)+1; });
      var cells=''; for(var i=0;i<7;i++){ var d=ymd(i); var dt=new Date(d+'T00:00:00');
        var L=dt.toLocaleDateString(undefined,{weekday:'narrow'}); var num=dt.getDate(); var n=cnt[d]||0;
        cells+='<div class="cal-wd'+(i===0?' is-today':'')+(n?' has-items':'')+'"><span class="cal-wd-l">'+esc(L)+'</span>'
          +'<span class="cal-wd-n">'+num+'</span><span class="cal-wd-dot">'+(n?'':'')+'</span></div>'; }
      weekEl.innerHTML=cells;
    }
    function render(items){
      items=(items||[]).filter(function(it){ return !it.done; });
      var meetN=items.filter(function(it){return it.kind==='meeting'||it.kind==='session';}).length;
      var taskN=items.filter(function(it){return it.kind==='task';}).length;
      if(sumEl) sumEl.textContent = items.length ? (meetN+' meeting'+(meetN===1?'':'s')+' · '+taskN+' task'+(taskN===1?'':'s')+' · next 14 days') : 'Next 14 days';
      renderWeek(items);
      items=items.slice(0,40);
      if(!items.length){ box.innerHTML='<p class="pc-empty">🗓️ Clear skies for the next two weeks. Schedule a meeting or add an event from the Suite.</p>'; return; }
      var groups={}, order=[];
      items.forEach(function(it){ if(!groups[it.date]){groups[it.date]=[];order.push(it.date);} groups[it.date].push(it); });
      box.innerHTML = order.map(function(d){
        return '<div class="cal-day">'+dayHead(d,groups[d].length)+'<ul class="cal-list">'+groups[d].map(itemHtml).join('')+'</ul></div>';
      }).join('');
    }
    function loadCal(){
      fetch('/portal/calendar.php?action=feed&from='+from+'&to='+to,{credentials:'same-origin'})
        .then(function(r){return r.json();}).then(function(d){ if(d&&d.ok) render(d.items); else box.innerHTML='<p class="pc-empty">Couldn’t load your calendar.</p>'; })
        .catch(function(){ box.innerHTML='<p class="pc-empty">Couldn’t load your calendar.</p>'; });
    }
    window.avReloadCalendar = loadCal;   // let the meeting scheduler refresh the agenda
    loadCal();
  })();
  </script>

  <script>
  /* Team Chat — Slack-style native channels: message stream (grouped, day
     dividers), composer with @mention autocomplete, emoji reactions, presence
     rail, and near-realtime polling via sinceId. */
  (function () {
    var root = document.getElementById('teamChat'); if (!root) return;
    var csrf = root.getAttribute('data-csrf') || '';
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function post(action, body){ return fetch('/portal/chat.php?action='+action,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body||{})}).then(function(r){return r.json();}); }
    function get(action, qs){ return fetch('/portal/chat.php?action='+action+(qs||''),{credentials:'same-origin'}).then(function(r){return r.json();}); }
    var streamEl=document.getElementById('tcStream'), chanEl=document.getElementById('tcChannels'),
        membersEl=document.getElementById('tcMembers'), typingEl=document.getElementById('tcTyping'),
        presEl=document.getElementById('tcPresence'), chanNameEl=document.getElementById('tcChannelName'),
        memberCountEl=document.getElementById('tcMemberCount'), topicEl=document.getElementById('tcTopic'),
        catchupBtn=document.getElementById('tcCatchup'),
        pinnedEl=document.getElementById('tcPinned'), pinBtn=document.getElementById('tcPinBtn'), pinCountEl=document.getElementById('tcPinCount'),
        input=document.getElementById('tcInput'), mentEl=document.getElementById('tcMentions'),
        composeForm=document.getElementById('tcCompose'),
        editBar=document.getElementById('tcEditBar'), editCancel=document.getElementById('tcEditCancel'),
        savedBtn=document.getElementById('tcSavedBtn'), addChannelBtn=document.getElementById('tcAddChannel'), chSetBtn=document.getElementById('tcChannelSettings');
    var CHANNEL='general', MSGS=[], LAST=0, EMOJI=[], ME={id:0}, CHANNELS=[], TOPICS={}, MEMBERS={mentors:[],members:[]}, PINS=[], PINS_OPEN=false, AI_OK=false, IS_ADMIN=false, EDITING=0, poller=null, active=false, ready=false, seenKey='av_chat_seen';
    // Per-channel last-seen id (localStorage) → unread dots.
    function seen(){ try{ return JSON.parse(localStorage.getItem(seenKey)||'{}'); }catch(e){ return {}; } }
    function markSeen(ch, id){ var s=seen(); if(!s[ch]||id>s[ch]){ s[ch]=id; try{ localStorage.setItem(seenKey, JSON.stringify(s)); }catch(e){} } }
    function fmtTime(iso){ var t=Date.parse((iso||'').replace(' ','T')+'Z'); if(!t) return ''; return new Date(t).toLocaleTimeString(undefined,{hour:'numeric',minute:'2-digit'}); }
    function dayOf(iso){ var t=Date.parse((iso||'').replace(' ','T')+'Z'); if(!t) return ''; var d=new Date(t); var td=new Date(); var y=new Date(td.getTime()-86400000);
      if(d.toDateString()===td.toDateString()) return 'Today'; if(d.toDateString()===y.toDateString()) return 'Yesterday';
      return d.toLocaleDateString(undefined,{weekday:'long',month:'short',day:'numeric'}); }
    function codeBlock(code){ return '<div class="tc-codewrap"><pre class="tc-code"><code>'+esc(code)+'</code></pre><button type="button" class="tc-copy" title="Copy code">Copy</button></div>'; }
    // Lightweight, safe Markdown: fenced/inline code, **bold** *italic* ~~strike~~,
    // [links](url) + bare URLs, and "- " bullet lists. Everything is escaped first;
    // mentions resolve to chips. Private-use markers isolate code from formatting.
    function bodyHtml(m){
      var raw=String(m.body==null?'':m.body);
      var S='\ue000', E='\ue001';   // private-use markers: never appear in user text
      var fen=[]; raw=raw.replace(/```([\s\S]*?)```/g, function(_, c){ fen.push(c.replace(/^\n/,'').replace(/\n+$/,'')); return S+'F'+(fen.length-1)+E; });
      var inl=[]; raw=raw.replace(/`([^`\n]+)`/g, function(_, c){ inl.push(c); return S+'I'+(inl.length-1)+E; });
      function inline(s){ s=esc(s);
        (m.mentions||[]).forEach(function(mn){ var tok=(mn.token||mn.handle||'').replace(/^@/,''); if(tok){ s=s.split('@'+esc(tok)).join('<span class="tc-at">@'+esc(mn.name||mn.handle||'')+'</span>'); } });
        s=s.replace(/\*\*([^*\n]+)\*\*/g,'<b>$1</b>').replace(/~~([^~\n]+)~~/g,'<s>$1</s>')
           .replace(/(^|[^\w*])\*([^*\n]+)\*(?!\w)/g,'$1<i>$2</i>').replace(/(^|[^\w_])_([^_\n]+)_(?!\w)/g,'$1<i>$2</i>');
        s=s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,'<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
        s=s.replace(/(^|[\s(])((?:https?:\/\/)[^\s<)]+)/g, function(_, p, u){ return p+'<a href="'+u+'" target="_blank" rel="noopener noreferrer">'+u+'</a>'; });
        s=s.replace(new RegExp(S+'I(\\d+)'+E,'g'), function(_, i){ return '<code class="tc-code-inline">'+esc(inl[+i])+'</code>'; });
        return s; }
      var rows=raw.split('\n'), out='', inUl=false, para=[];
      function flush(){ if(para.length){ out+='<span class="tc-line">'+para.join('<br>')+'</span>'; para=[]; } }
      var fenRe=new RegExp('^'+S+'F(\\d+)'+E+'$');
      rows.forEach(function(l){
        var fm=l.match(fenRe);
        if(fm){ flush(); if(inUl){out+='</ul>';inUl=false;} out+=codeBlock(fen[+fm[1]]); return; }
        if(/^\s*[-*]\s+/.test(l)){ flush(); if(!inUl){out+='<ul class="tc-ul">';inUl=true;} out+='<li>'+inline(l.replace(/^\s*[-*]\s+/,''))+'</li>'; return; }
        if(inUl){ out+='</ul>'; inUl=false; }
        para.push(inline(l)); });
      flush(); if(inUl) out+='</ul>';
      return out; }
    // Only render a reactions row when there ARE reactions — an always-present
    // (invisible) add button would reserve empty vertical space under every
    // message. Adding the first reaction happens from the hover actions bar.
    function reactHtml(m){ var rx=m.reactions||[]; if(!rx.length) return '';
      var chips=rx.map(function(r){ return '<button type="button" class="tc-react'+(r.mine?' is-mine':'')+'" data-emoji="'+esc(r.emoji)+'">'+esc(r.emoji)+' '+r.count+'</button>'; }).join('');
      return '<span class="tc-reacts">'+chips+'<button type="button" class="tc-react-add" title="Add reaction">＋</button></span>'; }
    function threadSummary(m){ if(!m.reply_count) return ''; return '<button type="button" class="tc-thread-sum" data-thread="'+m.id+'">🧵 '+m.reply_count+' repl'+(m.reply_count===1?'y':'ies')+(m.last_reply?' <span class="tc-thread-ago">· last '+esc(m.last_reply)+'</span>':'')+'</button>'; }
    function actionsHtml(m){
      if(m.deleted) return '';
      var reply = (!m.parent_id) ? '<button type="button" class="tc-act" data-act="reply" title="Reply in thread">💬</button>' : '';
      var pin = (!m.parent_id) ? '<button type="button" class="tc-act'+(m.pinned?' is-on':'')+'" data-act="pin" title="'+(m.pinned?'Unpin':'Pin to channel')+'">📌</button>' : '';
      var reactBtn = '<button type="button" class="tc-act" data-act="react" title="Add reaction">😊</button>';
      var save = '<button type="button" class="tc-act'+(m.saved?' is-on':'')+'" data-act="save" title="'+(m.saved?'Remove from saved':'Save for later')+'">'+(m.saved?'🔖':'🏷️')+'</button>';
      var edit = m.is_me ? '<button type="button" class="tc-act" data-act="edit" title="Edit">✎</button>' : '';
      var del = (m.is_me || IS_ADMIN) ? '<button type="button" class="tc-act tc-act-del" data-act="delete" title="Delete">🗑</button>' : '';
      return '<div class="tc-actions">'+reactBtn+reply+pin+'<button type="button" class="tc-act" data-act="assign" title="Assign as task">⌗</button>'+save+edit+del+'</div>'; }
    function msgHtml(m, grouped){
      var head = grouped ? '' : '<span class="tc-avatar">'+esc(m.initial)+'</span>';
      var pinMark = m.pinned && !m.deleted ? '<span class="tc-pinmark" title="Pinned">📌</span>' : '';
      var meta = grouped ? '' : '<span class="tc-msg-h"><b class="tc-name">'+esc(m.author)+'</b>'+(m.verified?'<span class="tc-badge" title="Verified member">✓</span>':'')+'<span class="tc-time">'+esc(fmtTime(m.created_at))+'</span>'+pinMark+'</span>';
      if(m.deleted){
        return '<div class="tc-msg is-deleted'+(grouped?' is-grouped':'')+'" data-id="'+m.id+'">'
          +'<div class="tc-msg-l">'+head+'</div>'
          +'<div class="tc-msg-b">'+meta+'<div class="tc-text tc-tomb"><svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13h10l1-13" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg> This message was deleted</div></div></div>'; }
      var edited = m.edited ? '<span class="tc-edited" title="Edited">(edited)</span>' : '';
      var savedMark = m.saved ? '<span class="tc-savemark" title="Saved">🔖</span>' : '';
      return '<div class="tc-msg'+(grouped?' is-grouped':'')+(m.is_me?' is-me':'')+(m.pinned?' is-pinned':'')+'" data-id="'+m.id+'">'
        +actionsHtml(m)
        +'<div class="tc-msg-l">'+head+savedMark+'</div>'
        +'<div class="tc-msg-b">'+meta+'<div class="tc-text">'+bodyHtml(m)+edited+'</div>'+reactHtml(m)+threadSummary(m)+'</div></div>'; }
    function render(){
      if(!MSGS.length){ streamEl.innerHTML='<p class="pc-empty tc-empty">👋 No messages yet in #'+esc(CHANNEL)+'. Say hello to the team.</p>'; return; }
      var html='', lastDay='', lastAuthor='', lastT=0;
      MSGS.forEach(function(m){ var day=dayOf(m.created_at);
        if(day!==lastDay){ html+='<div class="tc-divider"><span>'+esc(day)+'</span></div>'; lastDay=day; lastAuthor=''; }
        var t=Date.parse((m.created_at||'').replace(' ','T')+'Z')||0;
        var grouped = (m.author===lastAuthor) && (t-lastT < 5*60000);
        html+=msgHtml(m, grouped); lastAuthor=m.author; lastT=t; });
      streamEl.innerHTML=html; streamEl.scrollTop=streamEl.scrollHeight;
    }
    function renderChannels(){
      var s=seen();
      chanEl.innerHTML=CHANNELS.map(function(c){ var unread = c.last_id>(s[c.key]||0) && c.key!==CHANNEL;
        var hash = c.private ? '<span class="tc-hash" title="Private">🔒</span>' : '<span class="tc-hash">#</span>';
        var gc = c.gchat ? '<span class="tc-gc" title="Mirrors to Google Chat">↗</span>' : '';
        return '<li><button type="button" class="tc-channel'+(c.key===CHANNEL?' is-on':'')+'" data-ch="'+esc(c.key)+'">'+hash+esc(c.label)+gc+(unread?'<span class="tc-unread"></span>':'')+'</button></li>'; }).join('');
      updateNavBadge();
    }
    // A count of channels with unread messages, shown on the sidebar "Team Chat" nav.
    function updateNavBadge(){
      var link=document.querySelector('.pnav-link[data-view="chat"]'); if(!link) return;
      var s=seen(), chatVisible=!!(document.getElementById('view-chat') && !document.getElementById('view-chat').hidden);
      var n=CHANNELS.filter(function(c){ if(c.key===CHANNEL && chatVisible) return false; return c.last_id>(s[c.key]||0); }).length;
      var badge=link.querySelector('.pnav-badge');
      if(n>0){ if(!badge){ badge=document.createElement('span'); badge.className='pnav-badge'; link.appendChild(badge); } badge.textContent=n; badge.hidden=false; }
      else if(badge){ badge.hidden=true; }
    }
    function updatePresence(count){ if(presEl)presEl.textContent=count||0; }
    function roleTag(r){ return (r==='mentor'||r==='instructor') ? '<span class="tc-role tc-role--mentor">Mentor</span>'
      : (r==='coordinator'||r==='admin') ? '<span class="tc-role tc-role--lead">Lead</span>' : ''; }
    function memberRow(u){ return '<li class="tc-mem'+(u.online?'':' is-off')+'"><span class="tc-mem-ava">'+esc(u.initial)+(u.online?'<span class="tc-mem-dot"></span>':'')+'</span>'
      +'<span class="tc-mem-b"><span class="tc-mem-name">'+esc(u.name)+(u.is_me?' <span class="tc-mem-you">you</span>':'')+'</span>'+roleTag(u.role)+'</span></li>'; }
    function renderMembers(){ if(!membersEl) return; var m=MEMBERS||{mentors:[],members:[]}; var html='';
      if((m.mentors||[]).length){ html+='<div class="tc-mem-h">Mentors · '+m.mentors.length+'</div>'+m.mentors.map(memberRow).join(''); }
      html+='<div class="tc-mem-h tc-mem-h--sp">Members · '+(m.members||[]).length+'</div>'+((m.members||[]).length?m.members.map(memberRow).join(''):'<p class="pc-empty">No members yet.</p>');
      membersEl.innerHTML=html;
      var total=(m.mentors||[]).length+(m.members||[]).length; if(memberCountEl)memberCountEl.textContent=total?('· '+total+' member'+(total===1?'':'s')):''; }
    function renderTyping(names){ names=names||[]; if(!typingEl) return;
      if(!names.length){ typingEl.hidden=true; typingEl.innerHTML=''; return; }
      var label = names.length===1 ? esc(names[0])+' is typing' : names.length===2 ? esc(names[0])+' and '+esc(names[1])+' are typing' : names.length+' people are typing';
      typingEl.hidden=false; typingEl.innerHTML='<span class="tc-typing-dots"><i></i><i></i><i></i></span>'+label+'…'; }
    function setTopic(){ if(topicEl) topicEl.textContent = TOPICS[CHANNEL] || ''; }
    // Plain-text one-liner from a message body (strip fences/inline code/newlines).
    function plainText(body){ return String(body||'').replace(/```[\s\S]*?```/g,'[code]').replace(/`([^`]+)`/g,'$1').replace(/\s+/g,' ').trim(); }
    function renderPins(){
      if(!pinnedEl) return;
      var n=PINS.length;
      if(pinBtn){ pinBtn.hidden = n===0; if(pinCountEl) pinCountEl.textContent=n; pinBtn.classList.toggle('is-on', PINS_OPEN); }
      if(!n || !PINS_OPEN){ pinnedEl.hidden=true; pinnedEl.innerHTML=''; return; }
      pinnedEl.hidden=false;
      pinnedEl.innerHTML='<div class="tc-pinned-h"><svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M9 4h6l-1 6 4 3v2H6v-2l4-3-1-6Z" fill="currentColor"/></svg> '+n+' pinned</div>'
        + PINS.map(function(p){ var txt=plainText(p.body); if(txt.length>140) txt=txt.slice(0,140)+'…';
            return '<div class="tc-pin" data-id="'+p.id+'"><span class="tc-pin-b"><b>'+esc(p.author)+'</b> <span class="tc-pin-txt">'+esc(txt)+'</span></span>'
              +'<button type="button" class="tc-pin-jump" data-jump="'+p.id+'" title="Jump to message">↧</button>'
              +'<button type="button" class="tc-pin-x" data-unpin="'+p.id+'" title="Unpin">✕</button></div>'; }).join('');
    }
    // Append only strictly-newer messages (id > LAST) so a late/overlapping poll can't duplicate.
    function applyNew(list){ if(!list||!list.length) return; var added=false; list.forEach(function(m){ if(m.id>LAST){ MSGS.push(m); LAST=m.id; added=true; } }); if(!added) return; if(MSGS.length>200)MSGS=MSGS.slice(-200); markSeen(CHANNEL,LAST); render(); }
    function bootstrap(){ ready=false; get('bootstrap','&channel='+encodeURIComponent(CHANNEL)).then(function(d){ if(!d||!d.ok){ ready=true; return; }
        CHANNELS=d.channels||[]; EMOJI=d.react_emoji||[]; ME=d.me||ME; TOPICS=d.topics||TOPICS; MEMBERS=d.members||MEMBERS; PINS=d.pins||[]; AI_OK=!!d.ai; IS_ADMIN=!!d.is_admin;
        MSGS=d.messages||[]; LAST=MSGS.length?MSGS[MSGS.length-1].id:0;
        markSeen(CHANNEL,LAST); renderChannels(); render(); renderMembers(); renderTyping(d.typing); renderPins(); setTopic(); updatePresence(d.count);
        if(catchupBtn) catchupBtn.hidden=!AI_OK; if(addChannelBtn) addChannelBtn.hidden=!IS_ADMIN; if(chSetBtn) chSetBtn.hidden=!IS_ADMIN; ready=true; }).catch(function(){ ready=true; }); }
    function poll(){ if(!active || !ready) return; get('poll','&channel='+encodeURIComponent(CHANNEL)+'&since='+LAST).then(function(d){ if(!d||!d.ok) return;
        CHANNELS=d.channels||CHANNELS; if(d.members)MEMBERS=d.members; applyNew(d.messages); renderChannels(); renderMembers(); renderTyping(d.typing); updatePresence(d.count); }).catch(function(){}); }
    function switchChannel(ch){ if(ch===CHANNEL) return; CHANNEL=ch; MSGS=[]; LAST=0; if(chanNameEl)chanNameEl.textContent=ch; if(input)input.setAttribute('data-ph','Message #'+ch+' — use @ to mention'); renderChannels(); setTopic(); streamEl.innerHTML='<p class="pc-empty">Loading…</p>'; bootstrap(); }

    chanEl.addEventListener('click', function(e){ var b=e.target.closest('.tc-channel'); if(b) switchChannel(b.getAttribute('data-ch')); });

    // ── Message search ──
    var searchEl=document.getElementById('tcSearch'), searchResEl=document.getElementById('tcSearchResults'), searchTimer=null;
    function renderSearch(rows){ if(!searchResEl) return; rows=rows||[];
      if(!rows.length){ searchResEl.hidden=false; searchResEl.innerHTML='<div class="tc-sr-empty">No matches.</div>'; return; }
      searchResEl.hidden=false;
      searchResEl.innerHTML=rows.map(function(r){ var t=plainText(r.body); if(t.length>90)t=t.slice(0,90)+'…';
        return '<button type="button" class="tc-sr" data-ch="'+esc(r.channel||'')+'" data-id="'+r.id+'"><span class="tc-sr-top"><b>'+esc(r.author)+'</b> <span class="tc-sr-ch">#'+esc(r.channel||'')+'</span></span><span class="tc-sr-txt">'+esc(t)+'</span></button>'; }).join(''); }
    if(searchEl) searchEl.addEventListener('input', function(){ var q=(searchEl.value||'').trim(); clearTimeout(searchTimer);
      if(q.length<2){ if(searchResEl){searchResEl.hidden=true; searchResEl.innerHTML='';} return; }
      searchTimer=setTimeout(function(){ get('search','&q='+encodeURIComponent(q)).then(function(d){ if(d&&d.ok) renderSearch(d.results); }).catch(function(){}); }, 220); });
    if(searchResEl) searchResEl.addEventListener('click', function(e){ var b=e.target.closest('.tc-sr'); if(!b) return; var ch=b.getAttribute('data-ch'), id=+b.getAttribute('data-id');
      searchResEl.hidden=true; if(searchEl)searchEl.value=''; var switched = ch && ch!==CHANNEL; if(switched) switchChannel(ch);
      setTimeout(function(){ var el=streamEl.querySelector('.tc-msg[data-id="'+id+'"]'); if(el){ el.scrollIntoView({block:'center'}); el.classList.add('tc-flash'); setTimeout(function(){ el.classList.remove('tc-flash'); },1500); } }, switched?650:60); });
    document.addEventListener('click', function(e){ if(searchResEl && !searchResEl.hidden && !e.target.closest('.tc-search')) searchResEl.hidden=true; });

    // ── WYSIWYG composer ─────────────────────────────────────────
    // The composer shows the FORMATTED text as you type (bold/italic/code render
    // live) — not raw markdown. On send/edit it serialises to markdown so the
    // stored + mirrored message stays plain, portable text. attachRichEditor()
    // (defined below) encapsulates the toolbar, @mention chips and md round-trip.
    function replaceMsg(nm){ var i; for(i=0;i<MSGS.length;i++){ if(MSGS[i].id===nm.id){ MSGS[i]=nm; } } for(i=0;i<THREAD.length;i++){ if(THREAD[i].id===nm.id){ THREAD[i]=nm; } } render(); if(OPEN_THREAD) renderThread(); }
    var lastTyping=0;
    var mainRTE = attachRichEditor(input, composeForm, mentEl, {
      onSubmit: function(){ composeForm.requestSubmit(); },
      onInput:  function(){ var now=Date.now(); if(!mainRTE.isEmpty() && now-lastTyping>2500){ lastTyping=now; post('typing',{channel:CHANNEL}); } }
    });
    // Edit mode — load the message's markdown back into the formatted editor.
    function enterEdit(id){ var m=MSGS.concat(THREAD).filter(function(x){return x.id===id;})[0]; if(!m||m.deleted) return;
      EDITING=id; mainRTE.setMarkdown(m.body||''); mainRTE.focus();
      if(editBar) editBar.hidden=false; composeForm.classList.add('is-editing'); }
    function exitEdit(){ EDITING=0; mainRTE.clear(); if(editBar) editBar.hidden=true; composeForm.classList.remove('is-editing'); }
    if(editCancel) editCancel.addEventListener('click', exitEdit);
    // Send / save-edit
    composeForm.addEventListener('submit', function(e){ e.preventDefault(); var body=mainRTE.getMarkdown().trim(); if(!body) return;
      if(EDITING){ var id=EDITING; post('edit',{id:id, body:body}).then(function(d){ if(d&&d.ok&&d.message){ replaceMsg(d.message); } }).catch(function(){}); exitEdit(); return; }
      mainRTE.clear();
      post('send',{channel:CHANNEL, body:body}).then(function(d){ if(d&&d.ok&&d.message){ applyNew([d.message]); renderChannels(); } }).catch(function(){}); });
    // Escape cancels an in-progress edit (when mentions aren't open).
    input.addEventListener('keydown', function(e){ if(e.key==='Escape' && EDITING && !mainRTE.mentionsOpen()){ exitEdit(); } });

    // ── Catch me up (AI recap) ──
    var recapScrim=document.getElementById('recapScrim'), recapBody=document.getElementById('recapBody');
    function mdLite(t){ var lines=String(t||'').split('\n'), out='', inUl=false;
      function inl(s){ return esc(s).replace(/\*\*([^*]+)\*\*/g,'<b>$1</b>').replace(/`([^`]+)`/g,'<code class="tc-code-inline">$1</code>'); }
      lines.forEach(function(l){ var t2=l.trim();
        if(/^[-*•]\s+/.test(t2)){ if(!inUl){ out+='<ul>'; inUl=true; } out+='<li>'+inl(t2.replace(/^[-*•]\s+/,''))+'</li>'; return; }
        if(inUl){ out+='</ul>'; inUl=false; }
        if(!t2){ out+=''; return; }
        if(/^#{1,6}\s/.test(t2)){ out+='<h4>'+inl(t2.replace(/^#{1,6}\s/,''))+'</h4>'; return; }
        out+='<p>'+inl(t2)+'</p>'; });
      if(inUl) out+='</ul>'; return out; }
    function openRecap(){ if(!recapScrim) return; recapScrim.hidden=false; recapBody.innerHTML='<p class="pc-empty tc-recap-load"><span class="tc-typing-dots"><i></i><i></i><i></i></span> Reading #'+esc(CHANNEL)+' and writing your briefing…</p>';
      get('recap','&channel='+encodeURIComponent(CHANNEL)).then(function(d){ if(d&&d.ok){ recapBody.innerHTML=mdLite(d.summary)+'<div class="tc-recap-src">Summarised by AI'+(d.via?' ('+esc(d.via)+')':'')+' — verify anything important.</div>'; } else { recapBody.innerHTML='<p class="pc-empty">'+esc((d&&d.error)||'Could not generate a recap.')+'</p>'; } }).catch(function(){ recapBody.innerHTML='<p class="pc-empty">Network error — try again.</p>'; }); }
    function closeRecap(){ if(recapScrim) recapScrim.hidden=true; }
    if(catchupBtn) catchupBtn.addEventListener('click', openRecap);
    var recapCloseBtn=document.getElementById('recapClose'); if(recapCloseBtn) recapCloseBtn.addEventListener('click', closeRecap);
    if(recapScrim) recapScrim.addEventListener('click', function(e){ if(e.target===recapScrim) closeRecap(); });

    // ── Saved items ──────────────────────────────────────────────
    var savedScrim=document.getElementById('savedScrim'), savedBody=document.getElementById('savedBody'), savedCloseBtn=document.getElementById('savedClose');
    function openSaved(){ if(!savedScrim) return; savedScrim.hidden=false; savedBody.innerHTML='<p class="pc-empty">Loading…</p>';
      get('saved').then(function(d){ if(!d||!d.ok){ savedBody.innerHTML='<p class="pc-empty">Couldn’t load saved items.</p>'; return; }
        var rows=d.messages||[]; if(!rows.length){ savedBody.innerHTML='<p class="pc-empty">🔖 Nothing saved yet. Hover a message and tap 🏷️ to bookmark it.</p>'; return; }
        savedBody.innerHTML=rows.map(function(m){ return '<div class="tc-saved-item" data-ch="'+esc(m.channel||'')+'" data-id="'+m.id+'"><div class="tc-saved-top"><b>'+esc(m.author)+'</b> <span class="tc-saved-ch">#'+esc(m.channel||'')+'</span> <span class="tc-time">'+esc(fmtTime(m.created_at))+'</span></div><div class="tc-text">'+bodyHtml(m)+'</div><div class="tc-saved-act"><button type="button" class="tc-saved-jump" data-jump="'+m.id+'" data-ch="'+esc(m.channel||'')+'">Jump →</button><button type="button" class="tc-saved-unsave" data-unsave="'+m.id+'">Remove</button></div></div>'; }).join(''); }).catch(function(){ savedBody.innerHTML='<p class="pc-empty">Network error.</p>'; }); }
    function closeSaved(){ if(savedScrim) savedScrim.hidden=true; }
    if(savedBtn) savedBtn.addEventListener('click', openSaved);
    if(savedCloseBtn) savedCloseBtn.addEventListener('click', closeSaved);
    if(savedScrim) savedScrim.addEventListener('click', function(e){ if(e.target===savedScrim) closeSaved(); });
    if(savedBody) savedBody.addEventListener('click', function(e){
      var cp=e.target.closest('.tc-copy'); if(cp){ copyCode(cp); return; }
      var un=e.target.closest('.tc-saved-unsave'); if(un){ var uid=+un.getAttribute('data-unsave'); post('save',{id:uid, saved:false}).then(function(){ var it=un.closest('.tc-saved-item'); if(it) it.remove(); MSGS.concat(THREAD).forEach(function(x){ if(x.id===uid){ x.saved=false; } }); render(); }); return; }
      var jp=e.target.closest('.tc-saved-jump'); if(jp){ var jid=+jp.getAttribute('data-jump'), jch=jp.getAttribute('data-ch'); closeSaved();
        var switched = jch && jch!==CHANNEL; if(switched) switchChannel(jch);
        setTimeout(function(){ var el=streamEl.querySelector('.tc-msg[data-id="'+jid+'"]'); if(el){ el.scrollIntoView({block:'center'}); el.classList.add('tc-flash'); setTimeout(function(){ el.classList.remove('tc-flash'); },1500); } }, switched?650:60); } });

    // ── Channel editor (admins) ──────────────────────────────────
    var chanScrim=document.getElementById('chanScrim'), chanForm=document.getElementById('chanForm'), chanTitle=document.getElementById('chanTitle'),
        chanKeyEl=document.getElementById('chanKey'), chanLabelEl=document.getElementById('chanLabel'), chanTopicEl=document.getElementById('chanTopic'),
        chanPrivEl=document.getElementById('chanPrivate'), chanMemberPick=document.getElementById('chanMemberPick'), chanMemberList=document.getElementById('chanMemberList'),
        chanGchatOnEl=document.getElementById('chanGchatOn'), chanGchatSpaceWrap=document.getElementById('chanGchatSpaceWrap'), chanGchatSpaceEl=document.getElementById('chanGchatSpace'),
        chanSaveBtn=document.getElementById('chanSave'), chanMsg=document.getElementById('chanMsg'), chanCloseBtn=document.getElementById('chanClose');
    function allMembers(){ return (MEMBERS.mentors||[]).concat(MEMBERS.members||[]); }
    function renderMemberPicker(selected){ selected=selected||[]; if(!chanMemberList) return;
      chanMemberList.innerHTML=allMembers().filter(function(u){ return !u.is_me; }).map(function(u){ var on=selected.indexOf(u.id)>-1;
        return '<label class="tc-mp-row"><input type="checkbox" value="'+u.id+'"'+(on?' checked':'')+'> <span class="tc-mp-ava">'+esc(u.initial)+'</span> '+esc(u.name)+'</label>'; }).join('') || '<p class="pc-empty">No other members yet.</p>'; }
    function chanMsgSay(t,tone){ if(!chanMsg)return; chanMsg.hidden=!t; chanMsg.textContent=t||''; chanMsg.className='tc-chan-msg'+(tone?' is-'+tone:''); }
    function openChannelEditor(key){ if(!chanScrim||!IS_ADMIN) return; chanScrim.hidden=false; chanMsgSay('');
      if(key){ var c=CHANNELS.filter(function(x){return x.key===key;})[0]||{}; chanTitle.textContent='Edit #'+key; chanKeyEl.value=key;
        chanLabelEl.value=c.label||''; chanTopicEl.value=TOPICS[key]||c.topic||''; chanPrivEl.checked=!!c.private; chanGchatOnEl.checked=!!c.gchat; chanGchatSpaceEl.value='';
        chanSaveBtn.textContent='Save changes'; chanMemberPick.hidden=!c.private; chanGchatSpaceWrap.hidden=!c.gchat; renderMemberPicker([]);
        if(c.private){ get('channel_members','&channel='+encodeURIComponent(key)).then(function(d){ if(d&&d.ok) renderMemberPicker(d.members||[]); }); }
      } else { chanTitle.textContent='New channel'; chanKeyEl.value=''; chanLabelEl.value=''; chanTopicEl.value=''; chanPrivEl.checked=false; chanGchatOnEl.checked=false; chanGchatSpaceEl.value='';
        chanSaveBtn.textContent='Create channel'; chanMemberPick.hidden=true; chanGchatSpaceWrap.hidden=true; renderMemberPicker([]); }
      setTimeout(function(){ chanLabelEl.focus(); },30); }
    function closeChannelEditor(){ if(chanScrim) chanScrim.hidden=true; }
    if(addChannelBtn) addChannelBtn.addEventListener('click', function(){ openChannelEditor(''); });
    if(chSetBtn) chSetBtn.addEventListener('click', function(){ openChannelEditor(CHANNEL); });
    if(chanCloseBtn) chanCloseBtn.addEventListener('click', closeChannelEditor);
    if(chanScrim) chanScrim.addEventListener('click', function(e){ if(e.target===chanScrim) closeChannelEditor(); });
    if(chanPrivEl) chanPrivEl.addEventListener('change', function(){ chanMemberPick.hidden=!chanPrivEl.checked; if(chanPrivEl.checked) renderMemberPicker(collectMembers()); });
    if(chanGchatOnEl) chanGchatOnEl.addEventListener('change', function(){ chanGchatSpaceWrap.hidden=!chanGchatOnEl.checked; });
    function collectMembers(){ if(!chanMemberList) return []; return [].map.call(chanMemberList.querySelectorAll('input:checked'), function(i){ return +i.value; }); }
    if(chanForm) chanForm.addEventListener('submit', function(e){ e.preventDefault();
      var label=(chanLabelEl.value||'').trim(); if(!label){ chanMsgSay('Enter a channel name.','warn'); return; }
      var key=chanKeyEl.value, payload={label:label, topic:(chanTopicEl.value||'').trim(), private:chanPrivEl.checked, gchat_on:chanGchatOnEl.checked};
      if(chanGchatOnEl.checked && (chanGchatSpaceEl.value||'').trim()) payload.gchat_space=(chanGchatSpaceEl.value||'').trim();
      if(chanPrivEl.checked) payload.members=collectMembers();
      chanSaveBtn.disabled=true;
      var action = key ? 'channel_update' : 'channel_create'; if(key) payload.channel=key;
      post(action, payload).then(function(d){ chanSaveBtn.disabled=false;
        if(d&&d.ok){ CHANNELS=d.channels||CHANNELS; renderChannels(); closeChannelEditor(); if(d.channel&&!key){ switchChannel(d.channel.key); } else if(key){ setTopic(); bootstrap(); } }
        else { chanMsgSay((d&&d.error)||'Could not save the channel.','warn'); } }).catch(function(){ chanSaveBtn.disabled=false; chanMsgSay('Network error.','warn'); }); });

    // Message interactions (event-delegated) — shared by the stream and thread panel.
    function onMsgClick(e){
      var cp=e.target.closest('.tc-copy'); if(cp){ copyCode(cp); return; }
      var msgEl=e.target.closest('.tc-msg'); if(!msgEl) return; var id=+msgEl.getAttribute('data-id');
      var chip=e.target.closest('.tc-react'); if(chip){ react(id, chip.getAttribute('data-emoji')); return; }
      var add=e.target.closest('.tc-react-add'); if(add){ openEmojiPicker(add, id); return; }
      var act=e.target.closest('.tc-act'); if(act){ var a=act.getAttribute('data-act');
        if(a==='reply') openThread(id); else if(a==='react') openEmojiPicker(act, id); else if(a==='assign') openAssign(id, msgEl); else if(a==='pin') doPin(id);
        else if(a==='save') doSave(id); else if(a==='edit') enterEdit(id); else if(a==='delete') doDelete(id); return; }
      var sum=e.target.closest('.tc-thread-sum'); if(sum){ openThread(+sum.getAttribute('data-thread')); } }
    streamEl.addEventListener('click', onMsgClick);
    // Pin / unpin a message; the endpoint returns the fresh pin list.
    function doPin(id){ var m=MSGS.filter(function(x){return x.id===id;})[0]; var want=!(m&&m.pinned);
      post('pin',{id:id, pinned:want, channel:CHANNEL}).then(function(d){ if(!d||!d.ok) return;
        if(m){ m.pinned=d.pinned; } PINS=d.pins||PINS; if(want) PINS_OPEN=true; render(); renderPins(); }); }
    // Save / unsave (bookmark) a message.
    function doSave(id){ var m=MSGS.concat(THREAD).filter(function(x){return x.id===id;})[0]; var want=!(m&&m.saved);
      post('save',{id:id, saved:want}).then(function(d){ if(!d||!d.ok) return; MSGS.concat(THREAD).forEach(function(x){ if(x.id===id) x.saved=d.saved; }); render(); if(OPEN_THREAD) renderThread(); }); }
    // Soft-delete a message (retained, compressed, in the trash — never truly gone).
    function doDelete(id){ if(!window.confirm('Delete this message? It’s retained in the trash and can’t be seen by others.')) return;
      post('delete',{id:id}).then(function(d){ if(d&&d.ok&&d.message){ replaceMsg(d.message); if(EDITING===id) exitEdit(); } }); }
    // Header pin button toggles the pinned banner.
    if(pinBtn) pinBtn.addEventListener('click', function(){ PINS_OPEN=!PINS_OPEN; renderPins(); });
    // Pinned banner: unpin or jump-to-message.
    if(pinnedEl) pinnedEl.addEventListener('click', function(e){
      var un=e.target.closest('.tc-pin-x'); if(un){ var uid=+un.getAttribute('data-unpin'); post('pin',{id:uid, pinned:false, channel:CHANNEL}).then(function(d){ if(d&&d.ok){ var mm=MSGS.filter(function(x){return x.id===uid;})[0]; if(mm)mm.pinned=false; PINS=d.pins||[]; render(); renderPins(); } }); return; }
      var jp=e.target.closest('.tc-pin-jump'); if(jp){ var jid=+jp.getAttribute('data-jump'); var el=streamEl.querySelector('.tc-msg[data-id="'+jid+'"]'); if(el){ el.scrollIntoView({behavior:'smooth', block:'center'}); el.classList.add('tc-flash'); setTimeout(function(){ el.classList.remove('tc-flash'); }, 1500); } } });
    function react(id, emoji){ post('react',{id:id, emoji:emoji}).then(function(d){ if(!d||!d.ok) return;
      var m=MSGS.filter(function(x){return x.id===id;})[0]; if(m){ m.reactions=d.reactions; render(); }
      var tm=THREAD.filter(function(x){return x.id===id;})[0]; if(tm){ tm.reactions=d.reactions; }
      if(OPEN_THREAD===id || tm) renderThread(); }); }
    function copyCode(btn){ var pre=btn.parentNode.querySelector('.tc-code'); if(!pre) return; var text=pre.textContent||'';
      var done=function(){ var o=btn.textContent; btn.textContent='Copied ✓'; btn.classList.add('is-done'); setTimeout(function(){ btn.textContent=o; btn.classList.remove('is-done'); },1400); };
      if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(text).then(done).catch(function(){ fallback(text); done(); }); }
      else { fallback(text); done(); }
      function fallback(t){ try{ var ta=document.createElement('textarea'); ta.value=t; ta.style.position='fixed'; ta.style.opacity='0'; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); }catch(e){} } }
    var pickerEl=null;
    function openEmojiPicker(anchor, id){ closePicker(); pickerEl=document.createElement('div'); pickerEl.className='tc-picker';
      pickerEl.innerHTML=EMOJI.map(function(x){ return '<button type="button" data-e="'+esc(x)+'">'+esc(x)+'</button>'; }).join('');
      // Anchor the picker inside the message so hovering it keeps the message
      // (and its action bar) alive; position it just under the clicked button.
      var host=anchor.closest('.tc-msg')||anchor.parentNode; host.appendChild(pickerEl);
      try{ var ar=anchor.getBoundingClientRect(), hr=host.getBoundingClientRect();
        pickerEl.style.top=(ar.bottom-hr.top+4)+'px'; pickerEl.style.left=Math.max(4,(ar.left-hr.left))+'px'; }catch(e){}
      pickerEl.addEventListener('click', function(e){ var b=e.target.closest('button'); if(b){ react(id, b.getAttribute('data-e')); closePicker(); } });
      setTimeout(function(){ document.addEventListener('click', outside); },0);
      function outside(ev){ if(pickerEl && !pickerEl.contains(ev.target) && ev.target!==anchor){ closePicker(); document.removeEventListener('click', outside); } } }
    function closePicker(){ if(pickerEl){ pickerEl.remove(); pickerEl=null; } }

    // ── Threads ──────────────────────────────────────────────────
    var threadPanel=document.getElementById('tcThread'), threadBody=document.getElementById('tcThreadBody'),
        threadForm=document.getElementById('tcThreadForm'), threadInput=document.getElementById('tcThreadInput');
    var OPEN_THREAD=0, THREAD=[], tLast=0, threadReady=false;
    function renderThread(){ if(!OPEN_THREAD) return;
      var parent=MSGS.filter(function(x){return x.id===OPEN_THREAD;})[0];
      var top = parent ? '<div class="tc-thread-parent">'+msgHtml(parent,false)+'</div>' : '';
      var count = THREAD.length ? '<div class="tc-thread-count">'+THREAD.length+' repl'+(THREAD.length===1?'y':'ies')+'</div>' : '';
      var reps = THREAD.length ? THREAD.map(function(m){return msgHtml(m,false);}).join('') : '<p class="pc-empty">No replies yet — start the thread.</p>';
      threadBody.innerHTML = top+count+reps; threadBody.scrollTop=threadBody.scrollHeight; }
    function openThread(id){ OPEN_THREAD=id; THREAD=[]; tLast=0; threadReady=false; threadPanel.hidden=false;
      threadBody.innerHTML='<p class="pc-empty">Loading…</p>'; renderThread();
      get('thread','&parent='+id+'&since=0').then(function(d){ if(!d||!d.ok||OPEN_THREAD!==id) return; THREAD=d.replies||[]; tLast=THREAD.length?THREAD[THREAD.length-1].id:0; threadReady=true; renderThread(); if(threadRTE) threadRTE.focus(); }); }
    function closeThread(){ OPEN_THREAD=0; THREAD=[]; threadPanel.hidden=true; }
    function pollThread(){ if(!OPEN_THREAD||!threadReady) return; get('thread','&parent='+OPEN_THREAD+'&since='+tLast).then(function(d){ if(!d||!d.ok||!d.replies||!d.replies.length) return;
      var added=false; d.replies.forEach(function(m){ if(m.id>tLast){THREAD.push(m);tLast=m.id;added=true;} }); if(added) renderThread(); }); }
    document.getElementById('tcThreadClose').addEventListener('click', closeThread);
    threadBody.addEventListener('click', onMsgClick);
    var threadRTE = attachRichEditor(threadInput, threadForm, document.getElementById('tcThreadMentions'), {
      onSubmit: function(){ threadForm.requestSubmit(); }
    });
    threadForm.addEventListener('submit', function(e){ e.preventDefault(); if(!OPEN_THREAD) return; var b=threadRTE.getMarkdown().trim(); if(!b) return;
      threadRTE.clear();
      post('send',{channel:CHANNEL, body:b, parent_id:OPEN_THREAD}).then(function(d){ if(!d||!d.ok||!d.message) return;
        if(d.message.id>tLast){ THREAD.push(d.message); tLast=d.message.id; }
        var p=MSGS.filter(function(x){return x.id===OPEN_THREAD;})[0]; if(p){ p.reply_count=(p.reply_count||0)+1; p.last_reply='just now'; render(); }
        renderThread(); }).catch(function(){}); });

    // ── Assign a message as a task (the @mention → task-assignment bridge) ──
    var assignPop=null;
    function closeAssign(){ if(assignPop){ assignPop.remove(); assignPop=null; document.removeEventListener('click', assignOutside); } }
    function assignOutside(e){ if(assignPop && !assignPop.contains(e.target) && !e.target.closest('[data-act="assign"]')) closeAssign(); }
    function openAssign(id, anchorEl){ closeAssign();
      var m=MSGS.concat(THREAD).filter(function(x){return x.id===id;})[0]; if(!m) return;
      var mentions=m.mentions||[];
      var opts='<option value="0">Me</option>'+mentions.map(function(mn){ return '<option value="'+mn.id+'">'+esc(mn.name)+'</option>'; }).join('');
      var pop=document.createElement('div'); pop.className='tc-assign'; assignPop=pop;
      pop.innerHTML='<div class="tc-assign-h">Assign as task</div>'
        +'<input type="text" class="tc-assign-title" maxlength="300" placeholder="Task title">'
        +'<div class="tc-assign-row"><label>To <select class="tc-assign-who">'+opts+'</select></label><label>Due <input type="date" class="tc-assign-due"></label></div>'
        +'<div class="tc-assign-actions"><button type="button" class="pbtn pbtn-ghost tc-assign-cancel">Cancel</button><button type="button" class="pbtn pbtn-gold tc-assign-go">Assign</button></div>'
        +'<p class="tc-assign-msg" hidden></p>';
      anchorEl.appendChild(pop);
      pop.querySelector('.tc-assign-title').value = (m.body||'').slice(0,120);
      var who=pop.querySelector('.tc-assign-who'); if(mentions.length) who.value=String(mentions[0].id);
      pop.querySelector('.tc-assign-cancel').addEventListener('click', closeAssign);
      pop.querySelector('.tc-assign-go').addEventListener('click', function(){
        var title=(pop.querySelector('.tc-assign-title').value||'').trim(); var pmsg=pop.querySelector('.tc-assign-msg');
        if(!title){ pmsg.hidden=false; pmsg.className='tc-assign-msg is-warn'; pmsg.textContent='Add a title.'; return; }
        var go=pop.querySelector('.tc-assign-go'); go.disabled=true; go.textContent='Assigning…';
        post('assign',{title:title, assignee:+who.value||0, due:pop.querySelector('.tc-assign-due').value}).then(function(d){
          if(d&&d.ok){ pmsg.hidden=false; pmsg.className='tc-assign-msg is-ok'; pmsg.textContent=(+who.value>0?'Assigned — they’ll get an email. ✓':'Added to your tasks. ✓'); setTimeout(closeAssign,1200); }
          else { go.disabled=false; go.textContent='Assign'; pmsg.hidden=false; pmsg.className='tc-assign-msg is-warn'; pmsg.textContent=(d&&d.error)||'Could not assign.'; } }).catch(function(){ go.disabled=false; go.textContent='Assign'; }); });
      setTimeout(function(){ document.addEventListener('click', assignOutside); },0);
    }

    // ── attachRichEditor — a small WYSIWYG contenteditable editor ────
    // Shows formatted text live (never raw markdown), a modern formatting
    // toolbar with active-state highlighting, @mention chips, and clean
    // markdown serialisation on send/edit. Reused by the main + thread composers.
    function attachRichEditor(el, form, dropEl, opts){
      opts=opts||{};
      function setEmpty(){ var e=isEmpty(); el.classList.toggle('is-empty', e); }
      function isEmpty(){ return el.textContent.replace(/ /g,' ').trim()==='' && !el.querySelector('pre,ul,ol,li,img,.tc-at,code,a'); }
      function focus(){ el.focus(); placeCaretEnd(); }
      function placeCaretEnd(){ try{ var r=document.createRange(); r.selectNodeContents(el); r.collapse(false); var s=window.getSelection(); s.removeAllRanges(); s.addRange(r); }catch(e){} }

      // Keep pasted content plain — no foreign fonts/colours leaking in.
      el.addEventListener('paste', function(e){ e.preventDefault(); var t=((e.clipboardData||window.clipboardData).getData('text/plain'))||''; document.execCommand('insertText', false, t); });

      // ── formatting commands ──
      function surround(tag, ph){ var sel=window.getSelection(); if(!sel.rangeCount){ el.focus(); sel=window.getSelection(); if(!sel.rangeCount) return; }
        var range=sel.getRangeAt(0); var node=document.createElement(tag);
        if(range.collapsed){ node.textContent=ph||''; range.insertNode(node); var r=document.createRange(); r.selectNodeContents(node); sel.removeAllRanges(); sel.addRange(r); }
        else { node.appendChild(range.extractContents()); range.insertNode(node); var r2=document.createRange(); r2.selectNodeContents(node); sel.removeAllRanges(); sel.addRange(r2); } }
      function insertBlock(node){ var sel=window.getSelection(); if(!sel.rangeCount){ el.appendChild(node); return; }
        var range=sel.getRangeAt(0); range.deleteContents(); range.insertNode(node);
        var after=document.createElement('div'); after.appendChild(document.createElement('br')); node.parentNode.insertBefore(after, node.nextSibling);
        var r=document.createRange(); r.selectNodeContents(node); r.collapse(true); sel.removeAllRanges(); sel.addRange(r); }
      function ancestor(tagRe){ var sel=window.getSelection(); if(!sel.rangeCount) return null; var n=sel.getRangeAt(0).startContainer;
        while(n && n!==el){ if(n.nodeType===1 && tagRe.test(n.nodeName)) return n; n=n.parentNode; } return null; }
      function exec(cmd){ el.focus();
        if(cmd==='bold'||cmd==='italic') document.execCommand(cmd,false,null);
        else if(cmd==='strike') document.execCommand('strikeThrough',false,null);
        else if(cmd==='ul') document.execCommand('insertUnorderedList',false,null);
        else if(cmd==='ol') document.execCommand('insertOrderedList',false,null);
        else if(cmd==='quote'){ if(ancestor(/^BLOCKQUOTE$/)) document.execCommand('formatBlock',false,'div'); else document.execCommand('formatBlock',false,'blockquote'); }
        else if(cmd==='code') surround('code','code');
        else if(cmd==='codeblock'){ var pre=document.createElement('pre'); var sel=window.getSelection(); var txt=(sel && sel.toString())||''; pre.textContent=txt||'code'; insertBlock(pre); }
        else if(cmd==='link'){ var sel=window.getSelection(); var text=(sel && sel.toString())||''; var url=window.prompt('Link URL', 'https://'); if(!url) return; url=url.trim(); if(!/^https?:\/\//i.test(url)) url='https://'+url.replace(/^\/+/,'');
          if(text){ var a=document.createElement('a'); a.href=url; a.textContent=text; if(sel.rangeCount){ var rr=sel.getRangeAt(0); rr.deleteContents(); rr.insertNode(a); } }
          else { var a2=document.createElement('a'); a2.href=url; a2.textContent=url; insertInline(a2); } }
        setEmpty(); updateToolbar(); if(opts.onInput) opts.onInput(); }
      function insertInline(node){ var sel=window.getSelection(); if(!sel.rangeCount){ el.appendChild(node); return; } var r=sel.getRangeAt(0); r.deleteContents(); r.insertNode(node); r.setStartAfter(node); r.collapse(true); sel.removeAllRanges(); sel.addRange(r); }

      // Toolbar buttons live inside the form; highlight the active formats.
      var btns=[].slice.call(form.querySelectorAll('.tc-fmt'));
      btns.forEach(function(b){ b.addEventListener('mousedown', function(e){ e.preventDefault(); }); b.addEventListener('click', function(e){ e.preventDefault(); exec(b.getAttribute('data-cmd')); }); });
      function state(cmd){ try{ return document.queryCommandState(cmd); }catch(e){ return false; } }
      function updateToolbar(){ if(!btns.length) return; var inEl = (function(){ var s=window.getSelection(); if(!s.rangeCount) return false; var n=s.getRangeAt(0).startContainer; while(n){ if(n===el) return true; n=n.parentNode; } return false; })();
        btns.forEach(function(b){ var c=b.getAttribute('data-cmd'), on=false; if(inEl){
          if(c==='bold') on=state('bold'); else if(c==='italic') on=state('italic'); else if(c==='strike') on=state('strikeThrough');
          else if(c==='ul') on=state('insertUnorderedList'); else if(c==='ol') on=state('insertOrderedList');
          else if(c==='quote') on=!!ancestor(/^BLOCKQUOTE$/); else if(c==='code') on=!!ancestor(/^CODE$/); else if(c==='codeblock') on=!!ancestor(/^PRE$/); }
          b.classList.toggle('is-on', on); }); }
      document.addEventListener('selectionchange', function(){ updateToolbar(); });

      // ── @mention autocomplete (chips) ──
      var mOpen=false, mTimer=null, mActive=-1, mItems=[], mSaved=null;
      function mHide(){ if(dropEl){ dropEl.hidden=true; } mOpen=false; mActive=-1; mItems=[]; mSaved=null; }
      function mRender(){ if(!dropEl) return; dropEl.innerHTML=mItems.map(function(m,i){ return '<button type="button" class="tc-ment'+(i===0?' is-on':'')+'" data-h="'+esc(m.handle)+'" data-n="'+esc(m.name)+'"><span class="tc-ment-ava">'+esc(m.initial)+'</span>'+esc(m.name)+' <span class="tc-ment-h">@'+esc(m.handle)+'</span></button>'; }).join(''); dropEl.hidden=false; mOpen=true; mActive=0; }
      function caretAt(){ var sel=window.getSelection(); if(!sel.rangeCount) return null; var range=sel.getRangeAt(0); if(!range.collapsed) return null; var node=range.startContainer; if(node.nodeType!==3) return null;
        var before=node.nodeValue.slice(0, range.startOffset); var m=before.match(/(?:^|\s)@([\w.\-]*)$/); if(!m) return null; return {node:node, end:range.startOffset, start:range.startOffset-m[1].length-1, q:m[1]}; }
      function mPick(handle, name){ var cq=mSaved; mHide(); if(!cq) return; try{
        var r=document.createRange(); r.setStart(cq.node, cq.start); r.setEnd(cq.node, cq.end); r.deleteContents();
        var chip=document.createElement('span'); chip.className='tc-at'; chip.setAttribute('contenteditable','false'); chip.setAttribute('data-h', handle); chip.textContent='@'+name;
        r.insertNode(chip); var sp=document.createTextNode(' '); chip.parentNode.insertBefore(sp, chip.nextSibling);
        var nr=document.createRange(); nr.setStartAfter(sp); nr.collapse(true); var s=window.getSelection(); s.removeAllRanges(); s.addRange(nr);
      }catch(e){} setEmpty(); if(opts.onInput) opts.onInput(); }
      if(dropEl) dropEl.addEventListener('mousedown', function(e){ var b=e.target.closest('.tc-ment'); if(b){ e.preventDefault(); mPick(b.getAttribute('data-h'), b.getAttribute('data-n')); } });

      el.addEventListener('input', function(){ setEmpty(); if(opts.onInput) opts.onInput();
        var cq=caretAt(); if(!cq){ mHide(); return; } mSaved=cq; var q=cq.q; clearTimeout(mTimer);
        mTimer=setTimeout(function(){ get('mention','&q='+encodeURIComponent(q)).then(function(d){ if(!d||!d.ok||!d.members.length){ mHide(); return; } mItems=d.members; mRender(); }).catch(mHide); }, 130); });

      el.addEventListener('keydown', function(e){
        if(mOpen){ if(e.key==='ArrowDown'||e.key==='ArrowUp'){ e.preventDefault(); mActive=(mActive+(e.key==='ArrowDown'?1:mItems.length-1))%mItems.length; [].forEach.call(dropEl.children,function(c,i){ c.classList.toggle('is-on',i===mActive); }); return; }
          if(e.key==='Enter'||e.key==='Tab'){ e.preventDefault(); var it=mItems[mActive]; if(it) mPick(it.handle, it.name); return; }
          if(e.key==='Escape'){ e.preventDefault(); mHide(); return; } }
        // Keyboard formatting shortcuts.
        if((e.ctrlKey||e.metaKey) && !e.shiftKey){ var k=e.key.toLowerCase(); if(k==='b'){ e.preventDefault(); exec('bold'); return; } if(k==='i'){ e.preventDefault(); exec('italic'); return; } }
        // Enter sends; Shift+Enter is a newline.
        if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); if(opts.onSubmit) opts.onSubmit(); }
      });

      // ── markdown round-trip ──
      var BLOCK={DIV:1,P:1};
      function ser(node){ var out='';
        for(var i=0;i<node.childNodes.length;i++){ var ch=node.childNodes[i];
          if(ch.nodeType===3){ out+=ch.nodeValue.replace(/ /g,' ').replace(/\n/g,' '); continue; }
          if(ch.nodeType!==1) continue; var tag=ch.nodeName;
          if(ch.classList && ch.classList.contains('tc-at')){ out+='@'+(ch.getAttribute('data-h')||ch.textContent.replace(/^@/,'')); continue; }
          if(tag==='BR'){ out+='\n'; continue; }
          if(tag==='B'||tag==='STRONG'){ var t=ser(ch).trim(); if(t) out+='**'+t+'**'; continue; }
          if(tag==='I'||tag==='EM'){ var t2=ser(ch).trim(); if(t2) out+='*'+t2+'*'; continue; }
          if(tag==='S'||tag==='STRIKE'||tag==='DEL'){ var t3=ser(ch).trim(); if(t3) out+='~~'+t3+'~~'; continue; }
          if(tag==='CODE'){ out+='`'+ch.textContent+'`'; continue; }
          if(tag==='A'){ out+='['+ser(ch).trim()+']('+(ch.getAttribute('href')||'')+')'; continue; }
          if(tag==='PRE'){ out=nl(out)+'```\n'+ch.textContent.replace(/\n+$/,'')+'\n```\n'; continue; }
          if(tag==='BLOCKQUOTE'){ var q=ser(ch).trim().split('\n').map(function(l){return '> '+l;}).join('\n'); out=nl(out)+q+'\n'; continue; }
          if(tag==='UL'||tag==='OL'){ out=nl(out); var n=1; [].forEach.call(ch.children,function(li){ if(li.nodeName==='LI') out+=(tag==='OL'?(n++)+'. ':'- ')+ser(li).trim()+'\n'; }); continue; }
          if(BLOCK[tag]){ out=nl(out)+ser(ch); continue; }
          out+=ser(ch);
        }
        return out; }
      function nl(s){ return (s===''||/\n$/.test(s))? s : s+'\n'; }
      function getMarkdown(){ return ser(el).replace(/[ \t]+\n/g,'\n').replace(/\n{3,}/g,'\n\n').trim(); }

      // markdown → editable HTML (used when loading a message to edit).
      function mdToHtml(md){ var S='', E=''; var raw=String(md||'');
        var fen=[]; raw=raw.replace(/```([\s\S]*?)```/g, function(_,c){ fen.push(c.replace(/^\n/,'').replace(/\n+$/,'')); return S+'F'+(fen.length-1)+E; });
        var inl=[]; raw=raw.replace(/`([^`\n]+)`/g, function(_,c){ inl.push(c); return S+'I'+(inl.length-1)+E; });
        function inline(s){ s=esc(s);
          s=s.replace(/\*\*([^*\n]+)\*\*/g,'<b>$1</b>').replace(/~~([^~\n]+)~~/g,'<s>$1</s>')
             .replace(/(^|[^\w*])\*([^*\n]+)\*(?!\w)/g,'$1<i>$2</i>').replace(/(^|[^\w_])_([^_\n]+)_(?!\w)/g,'$1<i>$2</i>');
          s=s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,'<a href="$2">$1</a>');
          s=s.replace(new RegExp(S+'I(\\d+)'+E,'g'), function(_,i){ return '<code>'+esc(inl[+i])+'</code>'; });
          return s; }
        var rows=raw.split('\n'), out='', inUl=false, inOl=false; var fenRe=new RegExp('^'+S+'F(\\d+)'+E+'$');
        function closeL(){ if(inUl){out+='</ul>';inUl=false;} if(inOl){out+='</ol>';inOl=false;} }
        rows.forEach(function(l){ var fm=l.match(fenRe);
          if(fm){ closeL(); out+='<pre>'+esc(fen[+fm[1]])+'</pre>'; return; }
          if(/^\s*[-*]\s+/.test(l)){ if(inOl){out+='</ol>';inOl=false;} if(!inUl){out+='<ul>';inUl=true;} out+='<li>'+inline(l.replace(/^\s*[-*]\s+/,''))+'</li>'; return; }
          if(/^\s*\d+\.\s+/.test(l)){ if(inUl){out+='</ul>';inUl=false;} if(!inOl){out+='<ol>';inOl=true;} out+='<li>'+inline(l.replace(/^\s*\d+\.\s+/,''))+'</li>'; return; }
          closeL();
          if(/^\s*>\s?/.test(l)){ out+='<blockquote>'+inline(l.replace(/^\s*>\s?/,''))+'</blockquote>'; return; }
          if(l.trim()===''){ out+='<div><br></div>'; return; }
          out+='<div>'+inline(l)+'</div>'; });
        closeL(); return out; }

      function setMarkdown(md){ el.innerHTML=mdToHtml(md); setEmpty(); }
      function clear(){ el.innerHTML=''; setEmpty(); mHide(); }
      setEmpty();
      return { getMarkdown:getMarkdown, setMarkdown:setMarkdown, clear:clear, focus:focus, isEmpty:isEmpty, mentionsOpen:function(){ return mOpen; } };
    }

    // Cheap background refresh of channel state (for the nav unread badge) when
    // the Chat view ISN'T open — a message-less poll that still returns channels.
    function refreshChannelsBg(){ get('poll','&channel='+encodeURIComponent(CHANNEL)+'&since=999999999').then(function(d){ if(d&&d.ok){ CHANNELS=d.channels||CHANNELS; updateNavBadge(); } }).catch(function(){}); }
    // Fast 4s polling while the Chat view is visible; a slower (~24s) background
    // channel check otherwise, so the unread badge stays roughly live everywhere.
    var bg=0;
    function tick(){ var v=document.getElementById('view-chat');
      if(v && !v.hidden){ if(!active){ active=true; bootstrap(); } poll(); pollThread(); }
      else { active=false; if((++bg % 6) === 0) refreshChannelsBg(); } }
    window.addEventListener('hashchange', function(){ setTimeout(tick, 60); });
    if(location.hash.indexOf('chat')>-1){ active=true; bootstrap(); } else { refreshChannelsBg(); }
    poller=setInterval(tick, 4000);
  })();
  </script>

  <script>
  /* Schedule-a-meeting modal → creates a Google Meet + calendar invite via
     portal/meetings.php, then refreshes the Today calendar agenda. */
  (function () {
    var scrim=document.getElementById('meetScrim'), modal=document.getElementById('meetModal'); if(!scrim||!modal) return;
    var csrf=modal.getAttribute('data-csrf')||'', msg=document.getElementById('mmMsg');
    function open(){ scrim.hidden=false; document.body.style.overflow='hidden'; var w=document.getElementById('mmWhen'); if(w&&!w.value){ var d=new Date(Date.now()+3600000); d.setMinutes(0); w.value=d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2)+'T'+('0'+d.getHours()).slice(-2)+':00'; } document.getElementById('mmTitle').focus(); }
    function close(){ scrim.hidden=true; document.body.style.overflow=''; if(msg)msg.hidden=true; }
    function say(t,tone){ if(!msg)return; msg.hidden=!t; msg.textContent=t||''; msg.className='pm-msg'+(tone?' is-'+tone:''); }
    var openBtn=document.getElementById('openMeetModal'); if(openBtn) openBtn.addEventListener('click', open);
    document.getElementById('meetClose').addEventListener('click', close);
    document.getElementById('meetCancel').addEventListener('click', close);
    scrim.addEventListener('click', function(e){ if(e.target===scrim) close(); });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape' && !scrim.hidden) close(); });
    document.getElementById('meetForm').addEventListener('submit', function(e){ e.preventDefault();
      var title=(document.getElementById('mmTitle').value||'').trim(); var when=document.getElementById('mmWhen').value;
      if(!title||!when){ say('Add a title and a time.','warn'); return; }
      var atts=(document.getElementById('mmAtt').value||'').split(',').map(function(s){return s.trim();}).filter(Boolean);
      var btn=document.getElementById('mmSubmit'); btn.disabled=true; var old=btn.textContent; btn.textContent='Creating…'; say('Creating the meeting and Meet link…','');
      fetch('/portal/meetings.php?action=schedule',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body:JSON.stringify({title:title, when:when, duration:+document.getElementById('mmDur').value, frequency:document.getElementById('mmFreq').value, agenda:document.getElementById('mmAgenda').value, attendees:atts, context:'workspace'})})
        .then(function(r){return r.json();}).then(function(d){ btn.disabled=false; btn.textContent=old;
          if(d&&d.ok){ say((d.warning||'Meeting scheduled — invites sent. 🎉'), d.warning?'warn':'ok'); if(window.avReloadCalendar) window.avReloadCalendar();
            setTimeout(close, d.warning?2600:900); document.getElementById('meetForm').reset(); }
          else { say((d&&d.error)||'Could not schedule the meeting.','warn'); } })
        .catch(function(){ btn.disabled=false; btn.textContent=old; say('Network error — try again.','warn'); }); });
  })();
  </script>

  <script>
  /* Membership dues — Paystack checkout (unchanged behaviour). */
  (function () {
    var card=document.getElementById('membership'); if(!card) return;
    var btns=card.querySelectorAll('[data-dues-pay]'); if(!btns.length) return;
    var msg=card.querySelector('.dues-msg');
    function say(t){ if(msg){ msg.hidden=false; msg.textContent=t; } }
    [].forEach.call(btns, function(btn){ btn.addEventListener('click', function(){
      var period=btn.getAttribute('data-period')||'year'; [].forEach.call(btns,function(b){b.disabled=true;}); say('Starting secure checkout…');
      fetch('/portal/dues.php?action=pay_init',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':card.getAttribute('data-csrf')||''},body:JSON.stringify({period:period})})
        .then(function(r){return r.json();}).then(function(d){ if(d&&d.ok&&d.authorization_url){ window.location.href=d.authorization_url; return; } [].forEach.call(btns,function(b){b.disabled=false;}); say((d&&d.error)||'Could not start payment.'); })
        .catch(function(){ [].forEach.call(btns,function(b){b.disabled=false;}); say('Network error — please try again.'); }); }); });
  })();
  </script>

  <script>
  /* Google Workspace — live snapshot once connected (real difference post-connect). */
  (function () {
    var box = document.getElementById('wsLive'); if (!box) return;
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function whenFmt(iso){ var t=Date.parse(iso); if(!t) return esc(iso||''); var d=new Date(t); return d.toLocaleDateString(undefined,{month:'short',day:'numeric'})+' · '+d.toLocaleTimeString(undefined,{hour:'numeric',minute:'2-digit'}); }
    var $=function(id){return document.getElementById(id);};
    fetch('/portal/workspace.php?action=me',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(!d||!d.ok||!d.mine) return; var m=d.mine;
      if($('wsUnread')) $('wsUnread').textContent = (m.unread==null?'—':m.unread);
      if($('wsFiles')) $('wsFiles').textContent = (m.files?m.files.length:0);
      var ev=(m.events||[])[0];
      if(ev){ if($('wsNextC')) $('wsNextC').textContent=ev.title||'Event'; if($('wsNextW')) $('wsNextW').textContent=whenFmt(ev.start); }
      else { if($('wsNextC')) $('wsNextC').textContent='Nothing scheduled'; if($('wsNextW')) $('wsNextW').textContent=''; }
      var mail=$('wsMail'); if(mail){ var ms=m.mail||[]; mail.innerHTML = ms.length ? ms.map(function(x){ return '<li class="ws-li'+(x.unread?' is-unread':'')+'"><a href="'+esc(x.url)+'" target="_blank" rel="noopener"><span class="ws-li-from">'+esc(x.from)+'</span><span class="ws-li-sub">'+esc(x.subject)+'</span></a></li>'; }).join('') : '<li class="pc-empty">Inbox is clear.</li>'; }
      var evl=$('wsEvents'); if(evl){ var es=m.events||[]; evl.innerHTML = es.length ? es.map(function(x){ return '<li class="ws-li"><a href="'+esc(x.url||x.meet_url||'#')+'" target="_blank" rel="noopener"><span class="ws-li-sub">'+esc(x.title)+'</span><span class="ws-li-when">'+whenFmt(x.start)+'</span></a></li>'; }).join('') : '<li class="pc-empty">No upcoming events.</li>'; }
    }).catch(function(){});
  })();

  /* Mentorship meetings — trigger the Meet link + auto-log start/end times. */
  (function () {
    var root = document.getElementById('mentorSchedule'); if (!root) return;
    var csrf = root.getAttribute('data-csrf') || '';
    function post(action, body) { return fetch('/mentorship/api.php?action=' + action, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body || {}) }).then(function (r) { return r.json(); }); }
    function parseUTC(s) { return Date.parse((s || '').replace(' ', 'T') + 'Z') || 0; }
    function hhmm(iso) { var t = parseUTC(iso); if (!t) return ''; return new Date(t).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }); }
    function fmtDur(m) { m = Math.max(0, m || 0); var h = Math.floor(m / 60), r = m % 60; return h ? (h + 'h' + (r ? ' ' + r + 'm' : '')) : (r + 'm'); }
    function srcLabel(s) {
      var v = s === 'meet' || s === 'reports';
      var t = s === 'meet' ? 'Verified by Google Meet' : (s === 'reports' ? 'Verified · Meet audit log' : 'Provisional · confirming with Google');
      return ' <span class="msi-src ' + (v ? 'msi-verified' : 'msi-provisional') + '">' + t + '</span>';
    }
    function doneMsg(d) { return '✓ Logged ' + fmtDur(d.duration_min) + ' · ' + hhmm(d.started_at) + '–' + hhmm(d.ended_at) + srcLabel(d.source || ''); }
    // Live elapsed as H:MM:SS (or M:SS under an hour).
    function fmtElapsed(sec) { sec = Math.max(0, sec | 0); var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60, p = function (n) { return (n < 10 ? '0' : '') + n; }; return h ? (h + ':' + p(m) + ':' + p(s)) : (m + ':' + p(s)); }
    var pingTimers = {};
    var liveHtml = '<span class="msi-live"><span class="dot-live"></span>Live · <span class="msi-timer">0:00</span></span>';
    function tickAll() {
      var now = Date.now();
      [].forEach.call(root.querySelectorAll('.msi[data-state="live"]'), function (li) {
        var t = parseUTC(li.getAttribute('data-started') || ''); var el = li.querySelector('.msi-timer');
        if (t && el) el.textContent = fmtElapsed((now - t) / 1000);
      });
    }
    setInterval(tickAll, 1000); tickAll();
    function setState(li, state, log) {
      li.setAttribute('data-state', state);
      var start = li.querySelector('.msi-start'), logEl = li.querySelector('.msi-log');
      if (state === 'live') { if (start) { start.hidden = false; start.textContent = 'Join meeting'; } }
      else if (state === 'done') { if (start) start.hidden = true; }
      else { if (start) { start.hidden = false; start.textContent = 'Start meeting'; } }
      if (log != null && logEl) logEl.innerHTML = log;
      if (state === 'live') tickAll();
    }
    // One beat every 60s keeps a live meeting fresh; the server closes it (and
    // logs the hours) automatically once the beats stop — nobody presses "end".
    function startPing(li, id) {
      stopPing(id);
      pingTimers[id] = setInterval(function () { if (!document.hidden) post('meet_ping', { session_id: id }); }, 60000);
    }
    function stopPing(id) { if (pingTimers[id]) { clearInterval(pingTimers[id]); delete pingTimers[id]; } }
    // Fire one last beat on leave so the auto-logged end time ≈ when they left.
    function beat(id) {
      var url = '/mentorship/api.php?action=meet_ping';
      var payload = JSON.stringify({ session_id: id });
      try {
        if (navigator.sendBeacon) { navigator.sendBeacon(url, new Blob([payload], { type: 'application/json' })); return; }
      } catch (e) {}
      fetch(url, { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: payload }).catch(function () {});
    }
    function farewell() {
      Object.keys(pingTimers).forEach(function (id) { beat(+id); });
    }
    document.addEventListener('visibilitychange', function () { if (document.hidden) farewell(); });
    window.addEventListener('pagehide', farewell);
    root.addEventListener('click', function (e) {
      if (!e.target.closest('.msi-start')) return;
      var li = e.target.closest('.msi'); if (!li) return;
      var id = +li.getAttribute('data-session');
      var meet = li.getAttribute('data-meet') || '';
      // Open a tab synchronously (user gesture → not blocked); we'll point it at
      // the room. If there's no link yet, meet_start generates one and returns it.
      var w = window.open(meet || '', '_blank');
      post('meet_start', { session_id: id }).then(function (d) {
        if (d && d.ok) {
          if (d.url) { li.setAttribute('data-meet', d.url); if (w) { try { w.location = d.url; } catch (e) {} } else window.open(d.url, '_blank'); }
          else if (w && !meet) { try { w.close(); } catch (e) {} }
          li.setAttribute('data-started', d.started_at || ''); setState(li, 'live', liveHtml); startPing(li, id);
        } else { if (w) { try { w.close(); } catch (e) {} } }
      }).catch(function () { if (w) { try { w.close(); } catch (e) {} } });
    });
    // Keep already-live rows pinging and reflect the meeting being auto-closed.
    [].forEach.call(root.querySelectorAll('.msi[data-state="live"]'), function (li) {
      var id = +li.getAttribute('data-session'); startPing(li, id);
      var poll = setInterval(function () {
        post('meet_state', { session_id: id }).then(function (d) {
          if (d && d.ok && !d.live && d.ended_at) { clearInterval(poll); stopPing(id); setState(li, 'done', doneMsg(d)); }
        });
      }, 30000);
    });
  })();

  /* PWA — register the service worker. */
  (function(){ if('serviceWorker' in navigator){ window.addEventListener('load', function(){ navigator.serviceWorker.register('/sw.js').catch(function(){}); }); } })();
  </script>
  <script src="/portal/team-chat.js" defer></script>
  <script src="/portal/tools.js" defer></script>
  <script src="/portal/meetings.js" defer></script>
  <script src="/portal/notifications.js" defer></script>
  <script src="/portal/directory.js" defer></script>
  <script src="/community/community.js" defer></script>
  <script src="/assets/vendor/trix/trix.min.js" defer></script>
  <script src="/portal/diary.js" defer></script>
  <script src="/assets/site/nav.js" defer></script>
</body>
</html>
