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

$u = LmsAuth::user();
if (!$u) { header('Location: ' . av_login_url('/portal/')); exit; }

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
// KPI seeds (client refreshes online + tasks live).
$openTasks  = $isOrg && class_exists('Collab') ? count(array_filter(Collab::myTasks((int) $u['id']), fn($t) => empty($t['done']))) : 0;
$onlineNow  = $isOrg && class_exists('Collab') ? Collab::onlineCount() : 0;

$ptheme    = (($_COOKIE['av_portal_theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
$parts     = preg_split('/\s+/', trim((string) $u['name'])) ?: [];
$pInitials = strtoupper(substr((string) ($parts[0] ?? 'A'), 0, 1) . substr((string) ($parts[1] ?? ''), 0, 1)) ?: 'A';

render_head([
    'title'      => ($isOrg ? 'Member portal' : 'Your learning') . ' — Afrovanguard',
    'desc'       => 'Your Afrovanguard portal — learning, and (for members) mentorship and members-only spaces.',
    'canonical'  => rtrim(SITE_URL, '/') . '/portal/',
    'robots'     => 'noindex, nofollow',
    'body_class' => 'portal-page portal-app' . ($ptheme === 'dark' ? ' is-dark' : ''),
    'css'        => ['/portal/portal.css'],
    'manifest'   => '/manifest.webmanifest',
]);

/* Nav model — grouped, with dot colours + optional live badges. */
$nav = [
    'Main' => [
        ['overview', 'Dashboard', 'gold', ''],
        ['learning', 'Learning', 'gray', $courses ? (string) count($courses) : ''],
        ['mentorship', 'Mentorship', 'gray', $mentorStats['attended'] ? (string) (int) $mentorStats['attended'] : ''],
    ],
    'Account' => [
        ['membership', ($isOrg ? 'Membership' : 'Account'), 'gray', ''],
        ['diary', 'Diary', 'gray', $myEntries ? (string) count($myEntries) : ''],
    ],
];
if ($isOrg) array_splice($nav['Main'], 3, 0, [[ 'workspace', 'Workspace', 'gray', '' ]]);
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
              <!-- Tasks (with filter chips) -->
              <section class="pcard" id="tasks" data-csrf="<?= e($collabCsrf) ?>">
                <div class="pcard-head">
                  <h2>Your tasks</h2>
                  <div class="pseg" id="taskFilters" role="tablist">
                    <button type="button" class="pseg-btn is-on" data-filter="all">All <span class="pseg-n" id="fcAll">0</span></button>
                    <button type="button" class="pseg-btn" data-filter="open">Open <span class="pseg-n" id="fcOpen">0</span></button>
                    <button type="button" class="pseg-btn" data-filter="done">Done <span class="pseg-n" id="fcDone">0</span></button>
                  </div>
                </div>
                <div class="pcard-body">
                  <form class="task-add" id="taskAdd" autocomplete="off">
                    <input type="text" id="taskInput" name="title" maxlength="300" placeholder="Add a task and press Enter…" aria-label="Add a task">
                    <select id="taskAssignee" class="task-assignee" aria-label="Assign to" title="Assign to a member">
                      <option value="0">Assign to me</option>
                    </select>
                    <button type="submit" class="pbtn pbtn-gold">Add</button>
                  </form>
                  <ul class="task-list" id="taskList"><li class="pc-empty task-empty">Loading your tasks…</li></ul>
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

          <!-- Upcoming schedule -->
          <section class="pcard">
            <div class="pcard-head"><h2>Your schedule</h2><a class="pcard-link" href="/mentorship/">Manage →</a></div>
            <div class="pcard-body">
<?php if ($upcoming): ?>              <ul class="mini-sched">
<?php foreach (array_slice($upcoming, 0, 6) as $s): $sd = strtotime((string) $s['when'] . ' UTC') ?: time(); ?>                <li><span class="ms-when"><?= e(date('j M', $sd)) ?> · <?= e(date('g:ia', $sd)) ?></span><span class="ms-title"><?= e($s['title']) ?></span><?php if ($s['meet_url'] !== ''): ?><a class="ms-join" href="<?= e($s['meet_url']) ?>" target="_blank" rel="noopener">Join</a><?php endif; ?></li>
<?php endforeach; ?>              </ul>
<?php else: ?>              <p class="pc-empty">No upcoming sessions. <a href="/mentorship/">Book one with your mentor →</a></p>
<?php endif; ?>
            </div>
          </section>
        </section>

<?php if ($isOrg):
        require_once AV_ROOT . '/lib/workspace.php';
        $wsAdmin    = LmsAuth::rank((string) $u['role']) >= LmsAuth::ROLE_RANK['admin'];
        $wsSurfaces = av_workspace_surfaces($wsAdmin);
?>
        <!-- ============================================================ -->
        <!-- WORKSPACE                                                    -->
        <!-- ============================================================ -->
        <section class="pview" id="view-workspace" data-view="workspace" hidden>
          <div class="view-head"><h1>Workspace</h1><a class="pcard-link" href="/workspace">Open all →</a></div>
          <section class="pcard">
            <div class="pcard-head">
              <div><h2>Your Workspace</h2><p class="pcard-sub">Signed in via Google · @<?= e(av_workspace_domain()) ?></p></div>
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
          <div class="view-head"><h1>My Diary</h1><a class="pcard-link" href="/diary/me/">Open your Diary →</a></div>
          <section class="pcard">
            <div class="pcard-body diary-card">
              <div class="diary-stat"><span class="diary-n"><?= count($myEntries) ?></span><div><h2>My Diary</h2><p class="pcard-sub"><?= count($myEntries) === 1 ? 'entry' : 'entries' ?></p></div></div>
              <a class="pbtn pbtn-blue" href="/diary/me/">Write entry</a>
            </div>
          </section>
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
    /* Theme toggle */
    var tbtn = document.getElementById('portalTheme');
    function paintTheme(){ var dark=document.body.classList.contains('is-dark'); var s=tbtn&&tbtn.querySelector('.ico-sun'), m=tbtn&&tbtn.querySelector('.ico-moon'); if(s)s.style.display=dark?'block':'none'; if(m)m.style.display=dark?'none':'block'; }
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
        fcAll=document.getElementById('fcAll'), fcOpen=document.getElementById('fcOpen'), fcDone=document.getElementById('fcDone');
    var TASKS=[], FILTER='all';

    function counts(){ var open=TASKS.filter(function(t){return !t.done;}).length, done=TASKS.length-open;
      if(fcAll)fcAll.textContent=TASKS.length; if(fcOpen)fcOpen.textContent=open; if(fcDone)fcDone.textContent=done;
      if(kpiTasks)kpiTasks.textContent=open; }
    function taskHtml(t){
      var who = t.assigned_out ? ('→ '+esc(t.assignee_name)) : (t.mine ? '' : ('from '+esc(t.creator_name)));
      return '<li class="task'+(t.done?' is-done':'')+'" data-id="'+t.id+'">'
      +'<button type="button" class="task-check" aria-label="Toggle done">'+(t.done?'✓':'')+'</button>'
      +'<span class="task-title">'+esc(t.title)+(who?' <span class="task-who">'+who+'</span>':'')+'</span>'
      +(t.due&&!t.done?'<span class="task-due">'+esc(t.due)+'</span>':'')
      +'<button type="button" class="task-del" aria-label="Delete task">✕</button></li>'; }
    function fillRoster(roster){ var sel=document.getElementById('taskAssignee'); if(!sel||!roster) return;
      var cur=sel.value; sel.innerHTML='<option value="0">Assign to me</option>'+roster.map(function(m){ return '<option value="'+m.id+'">'+esc(m.name)+'</option>'; }).join(''); sel.value=cur; }
    function render(){ var rows=TASKS.filter(function(t){ return FILTER==='all'?true:FILTER==='open'?!t.done:t.done; });
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
      input.value=''; input.disabled=true;
      post('task_add',{title:title, assignee:assignee}).then(function(d){ input.disabled=false; input.focus(); if(asel)asel.value='0'; if(d&&d.ok&&d.task){ TASKS.unshift(d.task); render(); } }).catch(function(){ input.disabled=false; }); });
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
  /* PWA — register the service worker. */
  (function(){ if('serviceWorker' in navigator){ window.addEventListener('load', function(){ navigator.serviceWorker.register('/sw.js').catch(function(){}); }); } })();
  </script>
  <script src="/assets/site/nav.js" defer></script>
</body>
</html>
