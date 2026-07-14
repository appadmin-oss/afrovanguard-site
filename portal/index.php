<?php
/**
 * portal/index.php — the member / learning portal.
 *
 * Two experiences from one dashboard, decided by the account:
 *   @afrovanguard.org.ng  → MEMBER       — learning + mentorship + member status + diary
 *   everyone else         → LEARNING ONLY — learning + diary (mentorship locked)
 *
 * Served at /portal (a real folder, so WordPress never intercepts it);
 * subdomain-ready via PORTAL_URL. Its own slim chrome (member bar + footer).
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
$tag        = $isOrg ? 'Member portal' : 'Learning';
$showRole   = $isOrg && LmsAuth::rank((string) $u['role']) > LmsAuth::ROLE_RANK['member']; // mentor+
// Org members are at least "Member" even if their stored role is still learner
// (org status comes from the verified email domain). Never show below Member.
$accessLevel = (LmsAuth::rank((string) $u['role']) >= LmsAuth::ROLE_RANK['member']) ? $roleLabel : 'Member';
// Membership dues (annual fee) — shown to Afrovanguard members on the dashboard.
$dues     = $isOrg ? $lms->duesStatus((int) $u['id']) : null;
$duesCsrf = $dues ? av_csrf_token() : '';
// Real membership progression (Level O → A → B → C) + referral progress.
$journey = Levels::progress((int) $u['id']);
// Collaboration (presence / activity / tasks) CSRF token for the workspace panels.
$collabCsrf = av_csrf_token();
// Upcoming mentorship sessions (with Meet links) for the portal schedule/calendar.
$upcoming = class_exists('Mentorship') ? Mentorship::upcomingSessions((int) $u['id'], 6) : [];
// The portal has its OWN theme (LIGHT by default — a clean, professional
// dashboard — with a dark toggle). Server-set from a cookie so there's no flash.
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
?>
  <div class="portal-shell">
    <!-- Sidebar navigation (the standard dashboard rail) -->
    <aside class="portal-side" id="portalSide" aria-label="Portal navigation">
      <div class="side-top">
        <a class="portal-brand" href="<?= e(rtrim(SITE_URL, '/')) ?>/" aria-label="Afrovanguard — home">
          <span class="brand-wordmark"><span class="wm-1">Afro</span><span class="wm-2">vanguard</span></span>
        </a>
        <span class="side-tag"><?= e($tag) ?></span>
      </div>
      <nav class="side-nav" aria-label="Sections">
        <a class="side-link" href="#home" data-view="home"><span class="side-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg></span>Home</a>
        <a class="side-link" href="#learning" data-view="learning"><span class="side-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5A1.5 1.5 0 015.5 4H11v16H5.5A1.5 1.5 0 014 18.5zM20 5.5A1.5 1.5 0 0018.5 4H13v16h5.5a1.5 1.5 0 001.5-1.5z"/></svg></span>Learning</a>
        <a class="side-link" href="#mentorship" data-view="mentorship"><span class="side-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20a6.5 6.5 0 0113 0"/><path d="M16 5.2A3.2 3.2 0 0116 11M21.5 20a6.5 6.5 0 00-4-6"/></svg></span>Mentorship</a>
<?php if ($isOrg): ?>        <a class="side-link" href="#workspace" data-view="workspace"><span class="side-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 4v5"/></svg></span>Workspace</a>
<?php endif; ?>        <a class="side-link" href="#membership" data-view="membership"><span class="side-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.4 8.3-8 10-4.6-1.7-8-5-8-10V6z"/></svg></span><?= $isOrg ? 'Membership' : 'Account' ?></a>
        <a class="side-link" href="#diary" data-view="diary"><span class="side-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h13l3 3v13H4z"/><path d="M8 4v6h8"/></svg></span>Diary</a>
        <a class="side-link side-link--ext" href="/academy/"><span class="side-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3L2 8l10 5 10-5z"/><path d="M6 10.5V16c0 1.7 2.7 3 6 3s6-1.3 6-3v-5.5"/></svg></span>Academy ↗</a>
      </nav>
      <div class="side-foot">
        <a class="side-link side-link--muted" href="<?= e(rtrim(SITE_URL, '/')) ?>/">Main site ↗</a>
        <a class="side-link side-link--muted" href="#" data-logout>Sign out</a>
      </div>
    </aside>

    <div class="portal-scrim" id="portalScrim" hidden></div>

    <div class="portal-main">
      <!-- Slim top bar -->
      <header class="portal-topbar">
        <button type="button" class="topbar-burger" id="sideToggle" aria-label="Open navigation" aria-expanded="false">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <span class="topbar-title"><?= $isOrg ? 'Member portal' : 'Your learning' ?></span>
        <div class="topbar-actions">
<?php if ($isOrg): ?>          <span class="topbar-presence" id="topbarPresence" hidden><span class="online-dot"></span><span id="tbCount">0</span> online</span>
<?php endif; ?>          <button type="button" class="portal-icon-btn" id="portalTheme" aria-label="Switch theme" title="Light / dark">
            <svg class="ico-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/></svg>
            <svg class="ico-moon" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 109.8 9.8z"/></svg>
          </button>
          <div class="topbar-user">
            <span class="portal-avatar" aria-hidden="true"><?= e($pInitials) ?></span>
            <span class="topbar-user-meta"><span class="topbar-user-name"><?= e($first) ?></span><span class="topbar-user-role"><?= $isOrg ? e($accessLevel) : 'Learner' ?></span></span>
          </div>
        </div>
      </header>

      <main id="main-content" class="portal portal--<?= $isOrg ? 'member' : 'learner' ?>">
        <div class="container">
        <!-- ==================== HOME ==================== -->
        <section class="pview" data-view="home" id="view-home">
          <header class="portal-head" id="overview">
            <div>
              <span class="portal-eyebrow"><?= $isOrg ? 'Member portal' : 'Your learning' ?></span>
              <h1>Welcome back, <?= e($first) ?>.</h1>
              <p class="portal-badges">
<?php if ($isOrg): ?>                <span class="portal-badge org">✦ Afrovanguard member</span>
<?php if ($showRole): ?>                <span class="portal-badge"><?= e($roleLabel) ?></span>
<?php endif; ?>
<?php else: ?>                <span class="portal-badge">Learning access</span>
<?php endif; ?>              </p>
            </div>
          </header>

<?php if ($isOrg): ?>
          <!-- Quick actions — the "get to work" row (à la Workspace / Zoom) -->
          <section class="quick-actions" aria-label="Quick actions">
            <a class="qa qa--go" href="https://meet.google.com/new" target="_blank" rel="noopener noreferrer">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="13" height="12" rx="2"/><path d="M16 10l5-3v10l-5-3z"/></svg>Start a Meet</a>
            <a class="qa" href="https://calendar.google.com/calendar/u/0/r/eventedit" target="_blank" rel="noopener noreferrer">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4M12 13v4M10 15h4"/></svg>New event</a>
            <a class="qa" href="https://docs.google.com/document/create" target="_blank" rel="noopener noreferrer">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/></svg>New doc</a>
            <a class="qa qa--ws" href="/workspace">Open Workspace →</a>
          </section>
<?php endif; ?>

<?php
      // "Coming up" — live countdowns to the next major event (from the AFG
      // events feed, client-side) and the member's next mentorship session.
      $cdSession = null;
      try {
          $pairs = array_merge(Mentorship::myMentors((int) $u['id']), Mentorship::myMentees((int) $u['id']));
          $nowTs = time();
          foreach ($pairs as $pp) foreach (($pp['sessions'] ?? []) as $s) {
              $ts = !empty($s['when']) ? (int) strtotime((string) $s['when']) : 0;
              if ($ts && $ts >= $nowTs && (!$cdSession || $ts < $cdSession['ts'])) {
                  $cdSession = ['ts' => $ts, 'iso' => gmdate('c', $ts), 'title' => ($s['title'] ?: 'Mentorship session'), 'with' => (string) ($pp['name'] ?? '')];
              }
          }
      } catch (Throwable $e) { $cdSession = null; }
?>
      <section class="portal-coming" id="portalComing" hidden aria-label="Coming up">
        <h2 class="pc-coming-h">Coming up</h2>
        <div class="pc-coming-grid">
          <div class="cd-card" id="cdEvent" hidden data-iso="">
            <span class="cd-kicker">Next event</span>
            <span class="cd-title"></span>
            <div class="cd-timer"></div>
            <a class="cd-link" href="<?= e(defined('AV_EVENTS_URL') ? AV_EVENTS_URL : 'https://afg.afrovanguard.org.ng/events') ?>" target="_blank" rel="noopener">All events →</a>
          </div>
<?php if ($cdSession): ?>
          <div class="cd-card" id="cdSession" data-iso="<?= e($cdSession['iso']) ?>">
            <span class="cd-kicker">Your next session</span>
            <span class="cd-title"><?= e($cdSession['title']) . ($cdSession['with'] !== '' ? ' · with ' . e($cdSession['with']) : '') ?></span>
            <div class="cd-timer"></div>
            <a class="cd-link" href="/mentorship/">Open mentorship →</a>
          </div>
<?php endif; ?>
        </div>
      </section>

      <div class="portal-grid">
<?php if ($isOrg): ?>
        <!-- Your tasks — shared action items -->
        <section class="portal-card span-2 collab-tasks" id="collabTasks" data-csrf="<?= e($collabCsrf) ?>">
          <div class="pc-head"><h2>Your tasks</h2><span class="pc-tag" id="taskCount" hidden></span></div>
          <form class="task-add" id="taskAdd" autocomplete="off">
            <input type="text" id="taskInput" name="title" maxlength="300" placeholder="Add a task…" aria-label="Add a task">
            <button type="submit" class="btn btn-primary btn-sm">Add</button>
          </form>
          <ul class="task-list" id="taskList"><li class="pc-empty task-empty">Loading your tasks…</li></ul>
        </section>

        <!-- Team activity — a live feed from across the workspace -->
        <section class="portal-card collab-activity" id="collabActivity">
          <div class="pc-head"><h2>Team activity</h2></div>
          <ul class="activity-list" id="activityList"><li class="pc-empty">Loading…</li></ul>
        </section>

        <!-- Who's online — presence -->
        <section class="portal-card collab-online" id="collabOnline">
          <div class="pc-head"><h2>Who’s online</h2><span class="pc-tag online-pill"><span class="online-dot"></span><span id="onlineCount">0</span></span></div>
          <div class="online-list" id="onlineList"><p class="pc-empty">Just you so far.</p></div>
        </section>
<?php endif; ?>
<?php if ($upcoming): ?>
        <!-- Your schedule — upcoming mentorship sessions (Afrovanguard calendar) -->
        <section class="portal-card span-2 sched-card">
          <div class="pc-head"><h2>Your schedule</h2><a href="/mentorship/" class="pc-link">Open mentorship →</a></div>
          <ul class="sched-list">
<?php foreach ($upcoming as $s):
            $sd = strtotime((string) $s['when'] . ' UTC') ?: time();
?>            <li class="sched-item">
              <div class="sched-when"><span class="sched-day"><?= e(date('D', $sd)) ?></span><span class="sched-date"><?= e(date('j M', $sd)) ?></span><span class="sched-time"><?= e(date('g:ia', $sd)) ?></span></div>
              <div class="sched-body">
                <span class="sched-title"><?= e($s['title']) ?></span>
                <span class="sched-sub"><?= e($s['role']) ?><?= $s['with'] !== '' ? ' · ' . e($s['with']) : '' ?></span>
              </div>
<?php if ($s['meet_url'] !== ''): ?>              <a class="sched-join" href="<?= e($s['meet_url']) ?>" target="_blank" rel="noopener">▶ Join Meet</a>
<?php endif; ?>            </li>
<?php endforeach; ?>          </ul>
        </section>
<?php endif; ?>
          </div><!-- /.portal-grid (home) -->
        </section><!-- /view: home -->

<?php if ($isOrg):
        require_once AV_ROOT . '/lib/workspace.php';
        $wsAdmin     = LmsAuth::rank((string) $u['role']) >= LmsAuth::ROLE_RANK['admin'];
        $wsSurfaces  = av_workspace_surfaces($wsAdmin);
        $communities = av_workspace_communities();
        $wsEmbeds    = av_workspace_embeds();
?>
        <!-- ==================== WORKSPACE ==================== -->
        <section class="pview" data-view="workspace" id="view-workspace" hidden>
          <div class="view-head"><h1>Workspace</h1><a class="pc-link" href="/workspace">Open the full Workspace →</a></div>
          <div class="portal-grid">
        <!-- Your Workspace — SSO launchpad into Google Workspace (members only) -->
        <section class="portal-card span-2 ws-hub">
          <div class="pc-head"><h2>Your Workspace</h2><span class="pc-tag ws-domain">@<?= e(av_workspace_domain()) ?></span></div>
          <p class="pc-summary">You’re signed in with Google — jump straight into the Afrovanguard Workspace. <a href="/workspace" style="color:var(--afg-accent,#f3b416);font-weight:600;text-decoration:none;white-space:nowrap">Open the full Workspace →</a></p>
          <div class="ws-grid">
<?php foreach ($wsSurfaces as $s): ?>
            <a class="ws-tile" href="<?= e($s['url']) ?>" target="_blank" rel="noopener noreferrer">
              <span class="ws-ico ws-ico--<?= e($s['key']) ?>"><?= av_workspace_icon($s['icon']) ?></span>
              <span class="ws-text"><span class="ws-label"><?= e($s['label']) ?></span><span class="ws-desc"><?= e($s['desc']) ?></span></span>
            </a>
<?php endforeach; ?>
          </div>
        </section>
<?php if (GoogleWorkspace::configured()): ?>
        <!-- Workspace · live — REAL data pulled from the Google APIs (progressive) -->
        <section class="portal-card span-2 ws-live" id="wsLive">
          <div class="pc-head"><h2>Workspace · live</h2><span class="pc-tag">From Google</span></div>
          <div class="ws-live-grid">
            <div class="ws-live-col">
              <h3 class="ws-live-h">Upcoming events</h3>
              <div class="ws-live-list" id="wsEvents"><p class="pc-summary">Loading…</p></div>
            </div>
            <div class="ws-live-col">
              <h3 class="ws-live-h">Recent shared files</h3>
              <div class="ws-live-list" id="wsFiles"><p class="pc-summary">Loading…</p></div>
            </div>
          </div>
        </section>
        <script>
        (function () {
          function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
          function when(iso, allDay){ if(!iso) return ''; var d=new Date(iso); if(isNaN(d)) return esc(iso);
            var o=allDay?{weekday:'short',month:'short',day:'numeric'}:{weekday:'short',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'};
            try{return d.toLocaleString(undefined,o);}catch(e){return d.toISOString().slice(0,16).replace('T',' ');} }
          function fill(id, html){ var el=document.getElementById(id); if(el) el.innerHTML=html; }
          fetch('/portal/workspace.php?action=events',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
            if(!d.ok){ fill('wsEvents','<p class="pc-empty">Couldn’t load events.</p>'); return; }
            if(!d.events||!d.events.length){ fill('wsEvents','<p class="pc-empty">No upcoming events.</p>'); return; }
            fill('wsEvents', d.events.map(function(e){
              return '<a class="ws-live-row" '+(e.url?'href="'+esc(e.url)+'" target="_blank" rel="noopener"':'')+'>'
                +'<span class="ws-live-title">'+esc(e.title)+'</span>'
                +'<span class="ws-live-sub">'+esc(when(e.start,e.all_day))+(e.location?' · '+esc(e.location):'')+'</span></a>';
            }).join(''));
          }).catch(function(){ fill('wsEvents','<p class="pc-empty">Couldn’t load events.</p>'); });
          fetch('/portal/workspace.php?action=files',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
            if(!d.ok){ fill('wsFiles','<p class="pc-empty">Couldn’t load files.</p>'); return; }
            if(!d.files||!d.files.length){ fill('wsFiles','<p class="pc-empty">No files shared yet.</p>'); return; }
            fill('wsFiles', d.files.map(function(f){
              return '<a class="ws-live-row" '+(f.url?'href="'+esc(f.url)+'" target="_blank" rel="noopener"':'')+'>'
                +'<span class="ws-live-title">'+esc(f.name)+'</span>'
                +'<span class="ws-live-sub">'+esc(when(f.modified,false))+'</span></a>';
            }).join(''));
          }).catch(function(){ fill('wsFiles','<p class="pc-empty">Couldn’t load files.</p>'); });
        })();
        </script>
<?php endif; ?>
<?php if ($communities): ?>
        <!-- Communities — Google Chat Spaces / Groups (configurable via AV_WS_COMMUNITIES) -->
        <section class="portal-card span-2 ws-communities">
          <div class="pc-head"><h2>Communities</h2><span class="pc-tag"><?= count($communities) ?> space<?= count($communities) === 1 ? '' : 's' ?></span></div>
          <div class="ws-comm-list">
<?php foreach ($communities as $c): ?>
            <a class="ws-comm" href="<?= e($c['url']) ?>" target="_blank" rel="noopener noreferrer">
              <span class="ws-comm-name"><?= e($c['name']) ?></span>
<?php if ($c['desc'] !== ''): ?>              <span class="ws-comm-desc"><?= e($c['desc']) ?></span>
<?php endif; ?>            </a>
<?php endforeach; ?>
          </div>
        </section>
<?php endif; ?>
<?php if (!empty($wsEmbeds['calendar'])): ?>
        <!-- Team calendar (read-only embed) -->
        <section class="portal-card span-2 ws-embed">
          <div class="pc-head"><h2>Team calendar</h2><a class="pc-link" href="<?= e(av_ws_link('AV_WS_CALENDAR_URL', 'https://calendar.google.com/a/' . av_workspace_domain())) ?>" target="_blank" rel="noopener noreferrer">Open in Calendar →</a></div>
          <div class="ws-frame"><iframe src="<?= e($wsEmbeds['calendar']) ?>" title="Team calendar" loading="lazy" referrerpolicy="no-referrer"></iframe></div>
        </section>
<?php endif; ?>
<?php if (!empty($wsEmbeds['drive'])): ?>
        <!-- Shared files (read-only Drive folder embed) -->
        <section class="portal-card span-2 ws-embed">
          <div class="pc-head"><h2>Shared files</h2><a class="pc-link" href="<?= e(av_ws_link('AV_WS_DRIVE_URL', 'https://drive.google.com/a/' . av_workspace_domain())) ?>" target="_blank" rel="noopener noreferrer">Open in Drive →</a></div>
          <div class="ws-frame ws-frame--drive"><iframe src="<?= e($wsEmbeds['drive']) ?>" title="Shared files" loading="lazy" referrerpolicy="no-referrer"></iframe></div>
        </section>
<?php endif; ?>
          </div><!-- /.portal-grid (workspace) -->
        </section><!-- /view: workspace -->
<?php endif; ?>

        <!-- ==================== LEARNING ==================== -->
        <section class="pview" data-view="learning" id="view-learning" hidden>
          <div class="view-head"><h1>Learning</h1></div>
          <div class="portal-grid">
        <!-- My learning -->
        <section class="portal-card span-2" id="learning">
          <div class="pc-head"><h2>My learning</h2><a href="/academy/" class="pc-link">Browse the Academy →</a></div>
<?php if ($courses): ?>
          <p class="pc-summary"><b><?= count($courses) ?></b> programme<?= count($courses) === 1 ? '' : 's' ?><?= $inProgress ? ' · ' . $inProgress . ' in progress' : '' ?><?= $certs ? ' · ' . $certs . ' 🎓 certificate' . ($certs === 1 ? '' : 's') : '' ?></p>
          <div class="learn-list">
<?php foreach ($courses as $c): ?>
            <a class="learn-row" href="/academy/<?= e($c['slug']) ?>/learn/">
              <div class="learn-info">
                <span class="learn-title"><?= e($c['title']) ?></span>
                <span class="learn-meta"><?= $c['complete'] ? '✓ Complete' : ((int) $c['pct']) . '% complete' ?><?= $c['certified'] ? ' · 🎓 Certified' : '' ?></span>
              </div>
              <div class="learn-bar" aria-hidden="true"><span style="width:<?= (int) $c['pct'] ?>%"></span></div>
            </a>
<?php endforeach; ?>
          </div>
<?php else: ?>
          <p class="pc-empty">You haven’t joined a programme yet. <a href="/academy/">Explore the Academy →</a></p>
<?php endif; ?>
        </section>
          </div><!-- /.portal-grid (learning) -->
        </section><!-- /view: learning -->

        <!-- ==================== MENTORSHIP ==================== -->
        <section class="pview" data-view="mentorship" id="view-mentorship" hidden>
          <div class="view-head"><h1>Mentorship</h1><a class="pc-link" href="/mentorship/">Open the mentor network →</a></div>
          <div class="portal-grid">
        <!-- Mentorship — find a mentor (everyone); members can also mentor -->
        <section class="portal-card accent-green" id="mentorship">
          <div class="pc-head"><h2>Mentorship</h2><span class="pc-tag"><?= $isOrg ? 'Member' : 'Open' ?></span></div>
          <p><?= $isOrg ? 'Find a mentor, run your mentee inbox, and give back by mentoring others.' : 'Get paired with an Afrovanguard mentor for guidance on your journey.' ?></p>
          <a class="btn btn-primary btn-sm" href="/mentorship/">Open the mentor network →</a>
        </section>
          </div><!-- /.portal-grid (mentorship) -->
        </section><!-- /view: mentorship -->

        <!-- ==================== MEMBERSHIP / ACCOUNT ==================== -->
        <section class="pview" data-view="membership" id="view-membership" hidden>
          <div class="view-head"><h1><?= $isOrg ? 'Membership' : 'Account' ?></h1></div>
          <div class="portal-grid">
        <!-- Status & profile -->
        <section class="portal-card" id="membership">
          <div class="pc-head"><h2><?= $isOrg ? 'Membership' : 'Your account' ?></h2><?= $isOrg ? '<span class="pc-tag">Member</span>' : '' ?></div>
<?php if ($isOrg): ?>
          <p class="portal-status-line">✓ You’re an <strong>Afrovanguard member</strong> — full access to mentorship and members-only programmes.</p>
<?php else: ?>
          <p class="portal-status-line">You have <strong>learning access</strong>. Mentorship and members-only spaces are for Afrovanguard members.</p>
<?php endif; ?>
          <dl class="profile-dl">
            <dt>Name</dt><dd><?= e($u['name']) ?></dd>
            <dt>Email</dt><dd><?= e($u['email']) ?></dd>
            <dt><?= $isOrg ? 'Access level' : 'Account' ?></dt><dd><?= $isOrg ? e($accessLevel) : 'Learner' ?></dd>
          </dl>
        </section>

<?php if ($isOrg && $dues):
        $duesAnnual  = '₦' . number_format((int) ($dues['annual_ngn'] ?? $dues['amount_ngn']));
        $duesMonthly = '₦' . number_format((int) ($dues['monthly_ngn'] ?? 0));
        $duesPT     = $dues['paid_through'] ? date('j M Y', (int) strtotime((string) $dues['paid_through'])) : null;
        $duesDL     = $dues['days_left'];
        $duesState  = (string) $dues['state'];
        $duesPill   = ['active' => 'Current', 'due_soon' => 'Due soon', 'overdue' => 'Overdue', 'none' => 'Not paid'][$duesState] ?? 'Dues';
        if (!empty($dues['lifetime'])) $duesPill = 'Lifetime';
        $duesCanPay = !empty($dues['payable']) && empty($dues['lifetime']);
        $duesRecurring = defined('AV_DUES_PLAN_CODE') && AV_DUES_PLAN_CODE;
?>
        <!-- Membership dues (annual) -->
        <section class="portal-card dues-card dues-<?= e($duesState) ?>" id="duesCard" data-csrf="<?= e($duesCsrf) ?>">
          <div class="pc-head"><h2>Membership dues</h2><span class="pc-tag dues-pill"><?= e($duesPill) ?></span></div>
<?php if (!empty($dues['lifetime'])): ?>
          <p class="portal-status-line">✓ <strong>Lifetime membership</strong> — no dues due. Thank you for building Africa with us.</p>
<?php elseif ($duesState === 'active'): ?>
          <p class="portal-status-line">✓ Your dues are <strong>paid</strong><?= $duesPT ? ' through <strong>' . e($duesPT) . '</strong>' : '' ?><?= $duesDL !== null ? ' · ' . (int) $duesDL . ' day' . ((int) $duesDL === 1 ? '' : 's') . ' left' : '' ?>.</p>
<?php elseif ($duesState === 'due_soon'): ?>
          <p class="portal-status-line">⏳ Your dues expire<?= $duesPT ? ' on <strong>' . e($duesPT) . '</strong>' : ' soon' ?><?= $duesDL !== null ? ' — <strong>' . max(0, (int) $duesDL) . ' day' . ((int) $duesDL === 1 ? '' : 's') . '</strong> left' : '' ?>. Renew to stay current.</p>
<?php elseif ($duesState === 'overdue'): ?>
          <p class="portal-status-line">⚠ Your dues <strong>lapsed</strong><?= $duesPT ? ' on <strong>' . e($duesPT) . '</strong>' : '' ?>. Please renew to keep your membership active.</p>
<?php else: ?>
          <p class="portal-status-line">Back the mission with your annual membership dues.</p>
<?php endif; ?>
          <dl class="profile-dl dues-dl">
            <dt>Dues</dt><dd><strong><?= e($duesAnnual) ?></strong> <span class="dues-per">/ year</span> · <?= e($duesMonthly) ?> <span class="dues-per">/ month</span></dd>
<?php if ($duesPT): ?>            <dt><?= $duesState === 'overdue' ? 'Lapsed' : 'Paid through' ?></dt><dd><?= e($duesPT) ?></dd>
<?php endif; ?>
<?php $duesTotal = (int) ($dues['total_paid_ngn'] ?? 0); $duesN = (int) ($dues['payments_count'] ?? 0); if ($duesTotal > 0): ?>            <dt>Total dues paid</dt><dd><strong class="dues-total">₦<?= number_format($duesTotal) ?></strong> <span class="dues-per">across <?= $duesN ?> payment<?= $duesN === 1 ? '' : 's' ?></span></dd>
<?php endif; ?>          </dl>
<?php if ($duesCanPay): ?>
          <div class="dues-actions">
            <button type="button" class="btn <?= $duesState === 'active' ? 'btn-outline' : 'btn-primary' ?> btn-sm" data-dues-pay data-period="year"><?= $duesState === 'active' ? 'Renew a year' : 'Pay a year' ?> — <?= e($duesAnnual) ?></button>
            <button type="button" class="btn btn-outline btn-sm" data-dues-pay data-period="month"><?= $duesRecurring ? 'Subscribe monthly' : 'Pay a month' ?> — <?= e($duesMonthly) ?></button>
          </div>
          <p class="pc-summary dues-note"><?= $duesRecurring ? 'Monthly auto-renews — cancel anytime from your Paystack receipt. ' : '' ?><a href="/how-it-works">How dues &amp; progression work →</a></p>
          <p class="enroll-msg dues-msg" hidden></p>
<?php elseif (empty($dues['lifetime'])): ?>
          <p class="pc-summary">Online dues payment isn’t available right now — <a href="mailto:cacentre@afrovanguard.org.ng">contact us</a> to pay. <a href="/how-it-works">How dues work →</a></p>
<?php endif; ?>
        </section>
<?php endif; ?>

<?php
        // Your journey — the real membership progression (see /how-it-works).
        $jOrder = $journey['order'];
        $jHere  = array_search($journey['level'], $jOrder, true);
        $jPct   = min(100, (int) round(100 * $journey['referrals'] / max(1, $journey['referrals_needed'])));
?>
        <!-- Your growth path -->
        <section class="portal-card portal-journey span-2" id="journey">
          <div class="pc-head"><h2>Your journey</h2><a href="/how-it-works" class="pc-link">How progression works →</a></div>
          <p class="pc-summary">You're at <strong><?= e($journey['label']) ?></strong>. <?= e($journey['blurb']) ?></p>
          <ol class="journey-ladder">
<?php foreach ($jOrder as $i => $code):
            $cls = $i === $jHere ? ' is-here' : ($i < $jHere ? ' is-done' : '');
?>            <li class="jl<?= $cls ?>"><span class="jl-badge"><?= e($code) ?></span><span class="jl-label"><?= e(Levels::LADDER[$code]['label'] ?? $code) ?></span></li>
<?php endforeach; ?>          </ol>
<?php if ($journey['level'] === 'O'): ?>
          <div class="journey-next">
            <p class="pc-summary" style="margin:0 0 8px">Toward <strong>Level A</strong> — personally introduce <strong><?= (int) $journey['referrals'] ?> of <?= (int) $journey['referrals_needed'] ?></strong> committed members and mentor them as they settle in.</p>
            <div class="journey-bar" aria-hidden="true"><span style="width:<?= $jPct ?>%"></span></div>
<?php if ($journey['eligible_next']): ?>
            <p class="journey-elig">✓ You've met the referral requirement — leadership will confirm your Level A.</p>
<?php endif; ?>
            <label class="journey-invite">Your invite link
              <input type="text" readonly value="<?= e($journey['invite_url']) ?>" onclick="this.select()" aria-label="Your invite link">
            </label>
          </div>
<?php endif; ?>
        </section>

          </div><!-- /.portal-grid (membership) -->
        </section><!-- /view: membership -->

        <!-- ==================== DIARY ==================== -->
        <section class="pview" data-view="diary" id="view-diary" hidden>
          <div class="view-head"><h1>Diary</h1><a class="pc-link" href="/diary/me/">Open the Diary →</a></div>
          <div class="portal-grid">
        <!-- My Diary -->
        <section class="portal-card accent-blue">
          <div class="pc-head"><h2>My Diary</h2><a href="/diary/me/" class="pc-link">Open →</a></div>
          <p class="portal-stat"><b><?= count($myEntries) ?></b> diary <?= count($myEntries) === 1 ? 'entry' : 'entries' ?></p>
          <a class="btn btn-primary btn-sm" href="/diary/me/">Write an entry</a>
        </section>
          </div><!-- /.portal-grid (diary) -->
        </section><!-- /view: diary -->

      <footer class="portal-foot">
        <div class="portal-foot-inner">
          <span class="portal-foot-legal">© 2026 Afrovanguard</span>
          <span class="portal-foot-links"><a href="<?= e(rtrim(SITE_URL, '/')) ?>/">Main site ↗</a> · <a href="mailto:cacentre@afrovanguard.org.ng">Support</a></span>
        </div>
      </footer>
        </div>
      </main>
    </div><!-- /.portal-main -->
  </div><!-- /.portal-shell -->
  <script>
  (function () {
    var btn = document.getElementById('portalTheme');
    if (btn) btn.addEventListener('click', function () {
      var dark = document.body.classList.toggle('is-dark');
      document.cookie = 'av_portal_theme=' + (dark ? 'dark' : 'light') + ';path=/;max-age=31536000;samesite=Lax';
    });

    // Sidebar: off-canvas on small screens, persistent on large.
    var side = document.getElementById('portalSide'), toggle = document.getElementById('sideToggle'),
        scrim = document.getElementById('portalScrim');
    function setOpen(on) {
      document.body.classList.toggle('side-open', on);
      if (scrim) scrim.hidden = !on;
      if (toggle) toggle.setAttribute('aria-expanded', on ? 'true' : 'false');
    }
    if (toggle) toggle.addEventListener('click', function () { setOpen(!document.body.classList.contains('side-open')); });
    if (scrim) scrim.addEventListener('click', function () { setOpen(false); });

    // ---- View switching: the sidebar swaps the main panel (workplace app feel) ----
    var navLinks = Array.prototype.slice.call(document.querySelectorAll('.side-link[data-view]'));
    var views = Array.prototype.slice.call(document.querySelectorAll('.pview'));
    var titleEl = document.querySelector('.topbar-title');
    var labelFor = {};
    navLinks.forEach(function (a) { labelFor[a.getAttribute('data-view')] = (a.textContent || '').trim(); });
    var main = document.getElementById('main-content');

    function showView(name, push) {
      var found = false;
      views.forEach(function (v) {
        var on = v.getAttribute('data-view') === name;
        v.hidden = !on; if (on) found = true;
      });
      if (!found) { name = 'home'; views.forEach(function (v) { v.hidden = v.getAttribute('data-view') !== 'home'; }); }
      navLinks.forEach(function (l) { l.classList.toggle('is-active', l.getAttribute('data-view') === name); });
      if (titleEl && labelFor[name]) titleEl.textContent = labelFor[name];
      if (main) main.scrollTop = 0;
      window.scrollTo(0, 0);
      if (push && location.hash.slice(1) !== name) history.replaceState(null, '', '#' + name);
    }
    navLinks.forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        showView(a.getAttribute('data-view'), true);
        if (window.innerWidth < 960) setOpen(false);
      });
    });
    // Non-view links (Academy, Main site, Sign out) just close the drawer on mobile.
    document.querySelectorAll('.side-link:not([data-view])').forEach(function (a) {
      a.addEventListener('click', function () { if (window.innerWidth < 960) setOpen(false); });
    });
    // Deep-link + back/forward support.
    window.addEventListener('hashchange', function () { showView(location.hash.slice(1) || 'home', false); });
    showView(location.hash.slice(1) || 'home', false);

    // Let any in-page control jump to a view via href="#viewname".
    window.portalGoTo = function (name) { showView(name, true); };
  })();
  </script>
  <script>
  /* PWA — register the service worker and offer an install button. */
  (function () {
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); });
    }
    var deferred = null;
    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault(); deferred = e;
      var b = document.createElement('button');
      b.type = 'button'; b.id = 'pwaInstall';
      b.textContent = '⤓ Install the app';
      b.style.cssText = 'position:fixed;right:18px;bottom:18px;z-index:300;background:#f3b416;color:#111827;border:0;border-radius:9999px;padding:13px 22px;font:700 14px/1 Montserrat,sans-serif;box-shadow:0 12px 30px rgba(0,0,0,.35);cursor:pointer';
      b.addEventListener('click', function () {
        b.remove();
        if (!deferred) return;
        deferred.prompt(); deferred.userChoice.finally(function () { deferred = null; });
      });
      document.body.appendChild(b);
    });
    window.addEventListener('appinstalled', function () { var b = document.getElementById('pwaInstall'); if (b) b.remove(); });
  })();
  </script>
  <script>
  /* "Coming up" live countdowns — next AFG event + next mentorship session. */
  (function () {
    var wrap = document.getElementById('portalComing'); if (!wrap) return;
    var cards = [];
    function fmt(ms) {
      if (ms <= 0) return 'Starting now';
      var s = Math.floor(ms / 1000), d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
      var p = function (n) { return (n < 10 ? '0' : '') + n; };
      return (d ? d + 'd ' : '') + p(h) + 'h ' + p(m) + 'm ' + p(x) + 's';
    }
    function reg(card) {
      if (!card) return; var iso = card.getAttribute('data-iso'); if (!iso) return;
      var t = Date.parse(iso); if (isNaN(t)) return;
      cards.push({ t: t, el: card.querySelector('.cd-timer'), card: card }); card.hidden = false;
    }
    function tick() {
      var now = Date.now(), anyVisible = false;
      cards.forEach(function (c) { var ms = c.t - now; if (c.el) c.el.textContent = fmt(ms); if (ms < -3600000) c.card.hidden = true; if (!c.card.hidden) anyVisible = true; });
      if (anyVisible) wrap.hidden = false;
    }
    reg(document.getElementById('cdSession'));
    fetch('/events-feed.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var ev = ((d && d.events) || []).filter(function (e) { return e.iso && Date.parse(e.iso) > Date.now(); })
          .sort(function (a, b) { return Date.parse(a.iso) - Date.parse(b.iso); })[0];
        if (ev) { var c = document.getElementById('cdEvent'); c.setAttribute('data-iso', ev.iso); var ti = c.querySelector('.cd-title'); if (ti) ti.textContent = ev.title || 'Upcoming event'; reg(c); tick(); }
      }).catch(function () {});
    tick(); setInterval(tick, 1000);
  })();
  </script>
  <script>
  /* Membership dues — start a secure Paystack checkout for the annual fee. */
  (function () {
    var card = document.getElementById('duesCard'); if (!card) return;
    var btns = card.querySelectorAll('[data-dues-pay]'); if (!btns.length) return;
    var msg = card.querySelector('.dues-msg');
    function say(t) { if (msg) { msg.hidden = false; msg.textContent = t; } }
    Array.prototype.forEach.call(btns, function (btn) {
      btn.addEventListener('click', function () {
        var period = btn.getAttribute('data-period') || 'year';
        Array.prototype.forEach.call(btns, function (b) { b.disabled = true; });
        say('Starting secure checkout…');
        fetch('/portal/dues.php?action=pay_init', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': card.getAttribute('data-csrf') || '' },
          body: JSON.stringify({ period: period })
        }).then(function (r) { return r.json(); }).then(function (d) {
          if (d && d.ok && d.authorization_url) { window.location.href = d.authorization_url; return; }
          Array.prototype.forEach.call(btns, function (b) { b.disabled = false; });
          say((d && d.error) || 'Could not start payment. Please try again.');
        }).catch(function () { Array.prototype.forEach.call(btns, function (b) { b.disabled = false; }); say('Network error — please try again.'); });
      });
    });
  })();
  </script>
  <script>
  /* Collaboration panel — presence, team activity and tasks. */
  (function () {
    var root = document.getElementById('collabTasks'); if (!root) return;
    var csrf = root.getAttribute('data-csrf') || '';
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function post(action, body){
      return fetch('/portal/collab.php?action=' + action, {
        method:'POST', credentials:'same-origin',
        headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify(body||{})
      }).then(function(r){ return r.json(); });
    }
    var listEl = document.getElementById('taskList'),
        countEl = document.getElementById('taskCount'),
        actEl = document.getElementById('activityList'),
        onlineEl = document.getElementById('onlineList'),
        onlineCountEl = document.getElementById('onlineCount'),
        presence = document.getElementById('topbarPresence'),
        tbCount = document.getElementById('tbCount');

    function taskItem(t){
      return '<li class="task' + (t.done?' is-done':'') + '" data-id="' + t.id + '">'
        + '<button type="button" class="task-check" aria-label="Toggle done">' + (t.done?'✓':'') + '</button>'
        + '<span class="task-title">' + esc(t.title) + '</span>'
        + (t.due?'<span class="task-due">' + esc(t.due) + '</span>':'')
        + '<button type="button" class="task-del" aria-label="Delete task">×</button></li>';
    }
    function renderTasks(tasks){
      tasks = tasks || [];
      var open = tasks.filter(function(t){ return !t.done; }).length;
      if (countEl){ countEl.hidden = false; countEl.textContent = open + ' open'; }
      listEl.innerHTML = tasks.length ? tasks.map(taskItem).join('')
        : '<li class="pc-empty task-empty">No tasks yet — add your first above.</li>';
    }
    function renderActivity(items){
      items = items || [];
      actEl.innerHTML = items.length ? items.map(function(a){
        var obj = a.object ? ' <b>' + esc(a.object) + '</b>' : '';
        var line = '<span class="act-line"><b>' + esc(a.actor) + '</b> ' + esc(a.verb) + obj + '</span>';
        var inner = '<span class="act-ava">' + esc(a.initials) + '</span><span class="act-body">' + line + '<span class="act-ago">' + esc(a.ago) + '</span></span>';
        return '<li class="act">' + (a.url ? '<a href="' + esc(a.url) + '">' + inner + '</a>' : inner) + '</li>';
      }).join('') : '<li class="pc-empty">No activity yet.</li>';
    }
    function renderOnline(users, count){
      if (onlineCountEl) onlineCountEl.textContent = count || (users?users.length:0);
      if (tbCount) tbCount.textContent = count || 0;
      if (presence) presence.hidden = !(count > 0);
      users = users || [];
      onlineEl.innerHTML = users.length ? users.map(function(u){
        return '<span class="online-chip" title="' + esc(u.name) + ' · ' + esc(u.ago) + '"><span class="online-ava is-' + esc(u.status) + '">' + esc(u.initials) + '</span><span class="online-name">' + esc(u.name) + '</span></span>';
      }).join('') : '<p class="pc-empty">Just you so far.</p>';
    }

    function load(){
      fetch('/portal/collab.php?action=bootstrap', {credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(d){ if(!d||!d.ok) return; renderTasks(d.tasks); renderActivity(d.activity); renderOnline(d.online, d.count); })
        .catch(function(){});
    }

    // Add a task
    var form = document.getElementById('taskAdd'), input = document.getElementById('taskInput');
    form.addEventListener('submit', function(e){
      e.preventDefault();
      var title = (input.value||'').trim(); if(!title) return;
      input.value=''; input.disabled = true;
      post('task_add', {title:title}).then(function(d){
        input.disabled=false; input.focus();
        if (d && d.ok && d.task){
          var empty = listEl.querySelector('.task-empty'); if (empty) listEl.innerHTML='';
          listEl.insertAdjacentHTML('afterbegin', taskItem(d.task));
          var open = listEl.querySelectorAll('.task:not(.is-done)').length;
          if (countEl){ countEl.hidden=false; countEl.textContent = open + ' open'; }
        }
      }).catch(function(){ input.disabled=false; });
    });
    // Toggle / delete (event delegation)
    listEl.addEventListener('click', function(e){
      var li = e.target.closest('.task'); if(!li) return; var id = +li.getAttribute('data-id');
      if (e.target.closest('.task-check')){
        post('task_toggle', {id:id}).then(function(d){ if(d&&d.ok){ li.classList.toggle('is-done', d.done); li.querySelector('.task-check').textContent = d.done?'✓':'';
          var open = listEl.querySelectorAll('.task:not(.is-done)').length; if(countEl) countEl.textContent = open + ' open'; } });
      } else if (e.target.closest('.task-del')){
        post('task_delete', {id:id}).then(function(d){ if(d&&d.ok){ li.remove(); if(!listEl.querySelector('.task')) listEl.innerHTML='<li class="pc-empty task-empty">No tasks yet — add your first above.</li>'; } });
      }
    });

    load();
    // Heartbeat keeps presence live (and refreshes the count) every 45s.
    setInterval(function(){ post('heartbeat', {}).then(function(d){ if(d&&typeof d.count==='number'){ if(onlineCountEl) onlineCountEl.textContent=d.count; if(tbCount) tbCount.textContent=d.count; if(presence) presence.hidden = !(d.count>0); } }).catch(function(){}); }, 45000);
    // Refresh the feed + presence list periodically.
    setInterval(load, 90000);
  })();
  </script>
  <script src="/assets/site/nav.js" defer></script>
</body>
</html>
