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
    $nav['Work'] = [
        ['tasks', 'Tasks', 'gold', $openTasks ? (string) $openTasks : ''],
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
<?php endif; ?>          <button type="button" class="ptop-icon" id="portalTheme" aria-label="Light / dark" title="Light / dark">
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
<?php if ($isOrg): ?>              <a class="pbtn pbtn-ghost" href="https://calendar.google.com/calendar/u/0/r/eventedit" target="_blank" rel="noopener noreferrer">＋ New event</a>
              <a class="pbtn pbtn-ghost" href="https://docs.google.com/document/create" target="_blank" rel="noopener noreferrer">＋ New doc</a>
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

              <!-- Recent activity -->
              <section class="pcard">
                <div class="pcard-head"><h2>Recent activity</h2></div>
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

        <!-- ============================================================ -->
        <!-- TOOLS  (Afrovanguard first-party productivity apps)          -->
        <!-- ============================================================ -->
        <section class="pview" id="view-tools" data-view="tools" hidden data-uid="<?= (int) $u['id'] ?>">
          <div class="view-head">
            <div><h1>Suite</h1><p class="view-sub">Your Afrovanguard productivity suite — personal tools and shared team apps, right in the portal.</p></div>
          </div>
          <h2 class="suite-section"><span>◧ Personal</span><small>Private to you, synced to your account</small></h2>
          <div class="tools-grid">
            <section class="pcard tool" id="tlNotes">
              <div class="pcard-head"><h2>✎ Notes</h2><span class="tool-meta" id="tlNotesMeta">Autosaves</span></div>
              <div class="pcard-body">
                <textarea id="tlNotesArea" class="tool-notes" placeholder="Jot anything… it autosaves as you type."></textarea>
                <div class="tool-row tool-row--foot"><span class="tool-hint" id="tlNotesCount">0 words</span><button type="button" class="pbtn pbtn-ghost" id="tlNotesClear">Clear</button></div>
              </div>
            </section>

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

            <section class="pcard tool" id="tlHabits">
              <div class="pcard-head"><h2>✓ Habits</h2><span class="tool-meta" id="tlHabitsMeta"></span></div>
              <div class="pcard-body">
                <form id="tlHabitAdd" class="tool-row" autocomplete="off"><input id="tlHabitInput" placeholder="Add a daily habit…" maxlength="60"><button class="pbtn pbtn-gold" type="submit">Add</button></form>
                <ul class="habit-list" id="tlHabitList"></ul>
              </div>
            </section>

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
              </div>
            </section>
          </div>

<?php if ($isOrg): ?>
          <h2 class="suite-section"><span>◨ Team</span><small>Shared with everyone at Afrovanguard</small></h2>

          <!-- Enterprise: Kanban board — shared team workflow -->
          <section class="pcard tool-board" id="tlBoard" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>▦ Team board</h2><span class="pchip pchip--indigo">Members · shared</span></div>
            <div class="pcard-body">
              <div class="board-cols" id="tlBoardCols"><p class="pc-empty">Loading board…</p></div>
            </div>
          </section>

          <div class="tools-grid tools-grid--team">
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
          </div><!-- /.tools-grid--team -->
<?php endif; ?>
        </section>

<?php if ($isOrg): ?>
        <!-- ============================================================ -->
        <!-- TASKS  (dedicated productivity view)                         -->
        <!-- ============================================================ -->
        <section class="pview" id="view-tasks" data-view="tasks" hidden>
          <div class="view-head">
            <div><h1>Tasks</h1><p class="view-sub">Plan your work, set due dates and priorities, and assign to teammates.</p></div>
          </div>
          <section class="pcard" id="tasks" data-csrf="<?= e($collabCsrf) ?>">
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
<?php endif; ?>

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

          <!-- Google Chat — live, in-portal (spaces + messages + send) -->
          <section class="pcard chat-card" id="chatCard" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>Team Chat <span class="chat-count" id="chatSpaceCount" hidden></span></h2>
              <span class="pchip pchip--green"><span class="dot-live"></span>Google Chat <span class="chat-unread" id="chatUnreadBadge" hidden></span></span></div>
            <div class="pcard-body">
              <div class="chat-wrap">
                <aside class="chat-spaces" id="chatSpaces" aria-label="Chat spaces"><p class="pc-empty">Loading spaces…</p></aside>
                <div class="chat-main">
                  <div class="chat-thread" id="chatThread"><p class="pc-empty">Pick a space to start chatting.</p></div>
                  <form class="chat-compose" id="chatCompose" autocomplete="off" hidden>
                    <input type="text" id="chatInput" maxlength="4000" placeholder="Message this space…" aria-label="Message">
                    <button type="submit" class="pbtn pbtn-gold">Send</button>
                  </form>
                </div>
              </div>
              <p class="pcard-note chat-hint">Chats are live from Google Chat and sent as you. <a href="https://chat.google.com/" target="_blank" rel="noopener noreferrer">Open Chat ↗</a></p>
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
<?php foreach ($wsSurfaces as $s): ?>              <a class="pws-app" href="<?= e($s['url']) ?>" target="_blank" rel="noopener noreferrer">
                <span class="pws-ico pws-ico--<?= e($s['key']) ?>"><?= av_workspace_icon($s['icon']) ?></span>
                <span class="pws-text"><span class="pws-name"><?= e($s['label']) ?></span><span class="pws-desc"><?= e($s['desc']) ?></span></span>
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
    var TASKS=[], FILTER='all';
    var TODAY=new Date().toISOString().slice(0,10);
    function isOverdue(t){ return !t.done && t.due && (t.overdue || t.due < TODAY); }
    function counts(){ var open=TASKS.filter(function(t){return !t.done;}).length, done=TASKS.length-open,
        over=TASKS.filter(isOverdue).length, mine=TASKS.filter(function(t){return t.mine && !t.done;}).length;
      if(fcAll)fcAll.textContent=TASKS.length; if(fcOpen)fcOpen.textContent=open; if(fcDone)fcDone.textContent=done;
      if(fcOver)fcOver.textContent=over; if(fcMine)fcMine.textContent=mine;
      if(kpiTasks)kpiTasks.textContent=open; }
    function dueLabel(t){ if(!t.due) return ''; var d=new Date(t.due+'T00:00:00');
      return isNaN(d)?t.due:d.toLocaleDateString(undefined,{month:'short',day:'numeric'}); }
    function taskHtml(t){
      var who = t.assigned_out ? ('→ '+esc(t.assignee_name)) : (t.mine ? '' : ('from '+esc(t.creator_name)));
      var pr = (t.priority&&t.priority!=='normal') ? '<span class="task-pri task-pri--'+esc(t.priority)+'" title="'+esc(t.priority)+' priority"></span>' : '';
      var due = (t.due&&!t.done) ? '<span class="task-due'+(isOverdue(t)?' is-over':'')+'">'+esc(dueLabel(t))+'</span>' : '';
      return '<li class="task'+(t.done?' is-done':'')+'" data-id="'+t.id+'">'
      +'<button type="button" class="task-check" aria-label="Toggle done">'+(t.done?'✓':'')+'</button>'
      +pr+'<span class="task-title">'+esc(t.title)+(who?' <span class="task-who">'+who+'</span>':'')+'</span>'
      +due+'<button type="button" class="task-del" aria-label="Delete task">✕</button></li>'; }
    function fillRoster(roster){ var sel=document.getElementById('taskAssignee'); if(!sel||!roster) return;
      var cur=sel.value; sel.innerHTML='<option value="0">Me</option>'+roster.map(function(m){ return '<option value="'+m.id+'">'+esc(m.name)+'</option>'; }).join(''); sel.value=cur; }
    function render(){ var rows=TASKS.filter(function(t){
        if(FILTER==='open')return !t.done; if(FILTER==='done')return t.done;
        if(FILTER==='overdue')return isOverdue(t); if(FILTER==='mine')return t.mine && !t.done; return true; });
      listEl.innerHTML = rows.length ? rows.map(taskHtml).join('') : '<li class="pc-empty task-empty">Nothing here — you’re all caught up.</li>';
      counts(); }
    function renderOnline(users,count){ if(onlineCountEl)onlineCountEl.textContent=count||0; if(tbCount)tbCount.textContent=count||0; if(kpiOnline)kpiOnline.textContent=count||0; if(topOnline)topOnline.hidden=!(count>0);
      users=users||[]; onlineEl.innerHTML = users.length ? users.map(function(u){ return '<div class="online-row"><span class="online-ava is-'+esc(u.status)+'">'+esc(u.initials)+'</span><span class="online-name">'+esc(u.name)+'</span></div>'; }).join('') : '<p class="pc-empty">Just you so far.</p>'; }
    function renderActivity(items){ items=items||[]; actEl.innerHTML = items.length ? items.map(function(a){ var obj=a.object?' <b>'+esc(a.object)+'</b>':''; var inner='<span class="act-ava">'+esc(a.initials)+'</span><span class="act-body"><span class="act-line"><b>'+esc(a.actor)+'</b> '+esc(a.verb)+obj+'</span><span class="act-ago">'+esc(a.ago)+'</span></span>'; return '<li class="act">'+(a.url?'<a href="'+esc(a.url)+'">'+inner+'</a>':inner)+'</li>'; }).join('') : '<li class="pc-empty">No activity yet.</li>'; }

    function load(){ fetch('/portal/collab.php?action=bootstrap',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){ if(!d||!d.ok) return; TASKS=d.tasks||[]; render(); renderActivity(d.activity); renderOnline(d.online,d.count); fillRoster(d.roster); }).catch(function(){}); }

    // filter chips
    document.querySelectorAll('#taskFilters .pseg-btn').forEach(function(b){ b.addEventListener('click', function(){ document.querySelectorAll('#taskFilters .pseg-btn').forEach(function(x){x.classList.remove('is-on');}); b.classList.add('is-on'); FILTER=b.getAttribute('data-filter'); render(); }); });
    // add
    var form=document.getElementById('taskAdd'), input=document.getElementById('taskInput');
    form.addEventListener('submit', function(e){ e.preventDefault(); var title=(input.value||'').trim(); if(!title) return;
      var asel=document.getElementById('taskAssignee'); var assignee=asel?(+asel.value||0):0;
      var dsel=document.getElementById('taskDue'); var due=dsel?dsel.value:'';
      var psel=document.getElementById('taskPriority'); var priority=psel?psel.value:'normal';
      input.value=''; input.disabled=true;
      post('task_add',{title:title, assignee:assignee, due:due, priority:priority}).then(function(d){ input.disabled=false; input.focus(); if(asel)asel.value='0'; if(dsel)dsel.value=''; if(psel)psel.value='normal'; if(d&&d.ok&&d.task){ TASKS.unshift(d.task); render(); } }).catch(function(){ input.disabled=false; }); });
    // toggle / delete
    listEl.addEventListener('click', function(e){ var li=e.target.closest('.task'); if(!li) return; var id=+li.getAttribute('data-id');
      if(e.target.closest('.task-check')){ post('task_toggle',{id:id}).then(function(d){ if(d&&d.ok){ TASKS=TASKS.map(function(t){return t.id===id?Object.assign({},t,{done:d.done}):t;}); render(); } }); }
      else if(e.target.closest('.task-del')){ post('task_delete',{id:id}).then(function(d){ if(d&&d.ok){ TASKS=TASKS.filter(function(t){return t.id!==id;}); render(); } }); } });

    load();
    setInterval(function(){ post('heartbeat',{}).then(function(d){ if(d&&typeof d.count==='number'){ if(onlineCountEl)onlineCountEl.textContent=d.count; if(tbCount)tbCount.textContent=d.count; if(kpiOnline)kpiOnline.textContent=d.count; if(topOnline)topOnline.hidden=!(d.count>0); } }).catch(function(){}); }, 45000);
    setInterval(load, 90000);
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
  <script src="/community/community.js" defer></script>
  <script src="/assets/vendor/trix/trix.min.js" defer></script>
  <script src="/portal/diary.js" defer></script>
  <script src="/assets/site/nav.js" defer></script>
</body>
</html>
