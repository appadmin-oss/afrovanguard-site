<?php
/**
 * portal/index.php — the member / learning portal.
 *
 * Enterprise app shell: a fixed left sidebar (scrollspy section nav, theme,
 * account) beside a sectioned workspace — Overview / Workspace / Learning /
 * Community / Giving / Account. Two experiences from one dashboard:
 *   @afrovanguard.org.ng  → MEMBER       — everything incl. Workspace + spaces
 *   everyone else         → LEARNING     — learning + giving + diary
 *
 * Served at /portal (a real folder, so WordPress never intercepts it);
 * subdomain-ready via PORTAL_URL.
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
$accessLevel = (LmsAuth::rank((string) $u['role']) >= LmsAuth::ROLE_RANK['member']) ? $roleLabel : 'Member';
$ptheme    = (($_COOKIE['av_portal_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
$parts     = preg_split('/\s+/', trim((string) $u['name'])) ?: [];
$pInitials = strtoupper(substr((string) ($parts[0] ?? 'A'), 0, 1) . substr((string) ($parts[1] ?? ''), 0, 1)) ?: 'A';

// Giving history + today's celebration (rendered in their sections below).
$giving = ['rows' => [], 'count' => 0, 'total_ngn' => 0.0, 'inkind' => 0];
try { require_once AV_ROOT . '/lib/Donations.php'; $giving = Donations::forEmail((string) $u['email']); } catch (Throwable $e) {}
$cele = null;
try { require_once AV_ROOT . '/lib/celebrations.php'; $cele = av_celebration_today(Database::pdo()); } catch (Throwable $e) {}
$sym = ['NGN' => '₦', 'USD' => '$', 'GBP' => '£'];

// Next mentorship session (for the Coming up strip).
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

$hour = (int) date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$resume = null; foreach ($courses as $c) { if (empty($c['complete'])) { $resume = $c; break; } }

// Notifications — derived from the member's real state (no fake backend). The
// app-bar bell shows the count; the panel lists each with a deep link.
$notifs = [];
if ($cdSession) {
    $notifs[] = ['ico' => '📅', 'cls' => 'is-blue', 'title' => 'Upcoming: ' . $cdSession['title'], 'sub' => 'Your next mentorship session', 'href' => '/mentorship/'];
}
if ($resume) {
    $notifs[] = ['ico' => '📚', 'cls' => '', 'title' => 'Continue ' . mb_strimwidth((string) $resume['title'], 0, 40, '…'), 'sub' => ((int) $resume['pct']) . '% complete — pick up where you left off', 'href' => '/academy/' . $resume['slug'] . '/learn/'];
}
if ($certs) {
    $notifs[] = ['ico' => '🎓', 'cls' => 'is-green', 'title' => $certs . ' certificate' . ($certs === 1 ? '' : 's') . ' earned', 'sub' => 'View or share from My learning', 'href' => '#learning'];
}
if ($cele && !empty($cele['primary'])) {
    $notifs[] = ['ico' => $cele['primary']['emoji'] ?? '🎉', 'cls' => '', 'title' => (string) ($cele['primary']['title'] ?? 'Celebration today'), 'sub' => 'Today at Afrovanguard', 'href' => '#overview'];
}
if (!$courses) {
    $notifs[] = ['ico' => '✨', 'cls' => '', 'title' => 'Start learning', 'sub' => 'Browse the free Academy programmes', 'href' => '/academy/'];
}

/** Tiny inline nav icon set (stroke inherits currentColor). */
function pico(string $k): string {
    $p = [
        'overview'  => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'workspace' => '<rect x="2" y="7" width="20" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M2 13h20"/>',
        'learning'  => '<path d="M12 4L2 9l10 5 10-5-10-5z"/><path d="M6 11v5c0 1.3 2.7 2.5 6 2.5s6-1.2 6-2.5v-5"/>',
        'community' => '<path d="M21 15a2 2 0 0 1-2 2H8l-4 4V5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2z"/><path d="M8 9h8M8 12h5"/>',
        'giving'    => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
        'account'   => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.9 3.1-6 7-6s7 2.1 7 6"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$k] ?? '') . '</svg>';
}

render_head([
    'title'      => ($isOrg ? 'Member portal' : 'Your learning') . ' — Afrovanguard',
    'desc'       => 'Your Afrovanguard portal — learning, and (for members) mentorship and members-only spaces.',
    'canonical'  => rtrim(SITE_URL, '/') . '/portal/',
    'robots'     => 'noindex, nofollow',
    'body_class' => 'portal-page' . ($ptheme === 'light' ? ' is-light' : ''),
    'css'        => ['/portal/portal.css?v=' . (@filemtime(__DIR__ . '/portal.css') ?: 1)],
    'manifest'   => '/manifest.webmanifest',
]);
?>
  <div class="pshell">

    <div class="ps-scrim" id="psideScrim" hidden></div>

    <!-- ── Sidebar ── -->
    <aside class="pside" id="pside" aria-label="Portal navigation">
      <a class="ps-brand" href="<?= e(rtrim(SITE_URL, '/')) ?>/" aria-label="Afrovanguard — home">
        <span class="brand-wordmark"><span class="wm-1">Afro</span><span class="wm-2">vanguard</span></span>
        <span class="ps-tag"><?= e($tag) ?></span>
      </a>
      <nav class="ps-nav" aria-label="Sections">
        <a class="ps-link active" href="#overview"><?= pico('overview') ?><span>Overview</span></a>
<?php if ($isOrg): ?>        <a class="ps-link" href="#workspace"><?= pico('workspace') ?><span>Workspace</span></a>
<?php endif; ?>
        <a class="ps-link" href="#learning"><?= pico('learning') ?><span>Learning</span></a>
<?php if ($isOrg): ?>        <a class="ps-link" href="#community"><?= pico('community') ?><span>Community</span></a>
<?php endif; ?>
        <a class="ps-link" href="#giving"><?= pico('giving') ?><span>Giving</span></a>
        <a class="ps-link" href="#account"><?= pico('account') ?><span>Account</span></a>
      </nav>
      <div class="ps-quicklinks">
        <span class="ps-nav-h">Apps</span>
        <a class="ps-link ps-link--ext" href="/academy/"><span>Academy</span><span class="ps-ext" aria-hidden="true">↗</span></a>
        <a class="ps-link ps-link--ext" href="/diary/me/"><span>My Diary</span><span class="ps-ext" aria-hidden="true">↗</span></a>
        <a class="ps-link ps-link--ext" href="/mentorship/"><span>Mentorship</span><span class="ps-ext" aria-hidden="true">↗</span></a>
      </div>
      <div class="ps-foot">
        <span class="portal-avatar" aria-hidden="true"><?= e($pInitials) ?></span>
        <span class="ps-user">
          <span class="ps-user-name" id="psUserName"><?= e($u['name']) ?></span>
          <span class="ps-user-email"><?= e($u['email']) ?></span>
        </span>
      </div>
    </aside>

    <!-- ── Main column ── -->
    <main id="main-content" class="pmain portal--<?= $isOrg ? 'member' : 'learner' ?>">

      <!-- ── Top app bar ── -->
      <header class="pbar">
        <button type="button" class="pbar-burger" id="psideBurger" aria-label="Open navigation" aria-expanded="false" aria-controls="pside">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
        <div class="pbar-search">
          <div class="pbar-search-field">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
            <input id="pbarSearch" type="search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="pbarSearchMenu" placeholder="Search courses, sections, actions…" autocomplete="off" />
            <kbd class="pbar-kbd">/</kbd>
          </div>
          <div class="pbar-search-menu" id="pbarSearchMenu" role="listbox" hidden></div>
        </div>
        <div class="pbar-actions">
          <button type="button" class="pbar-btn" id="notifBtn" aria-label="Notifications" aria-haspopup="dialog" aria-expanded="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
            <span class="pbar-badge" id="notifBadge"<?= $notifs ? '' : ' hidden' ?>></span>
          </button>
          <button type="button" class="pbar-btn" id="portalTheme" aria-label="Switch theme" title="Light / dark">
            <svg class="ico-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/></svg>
            <svg class="ico-moon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 109.8 9.8z"/></svg>
          </button>
          <button type="button" class="pbar-btn pbar-avatar-btn" id="avatarBtn" aria-label="Your account" aria-haspopup="menu" aria-expanded="false">
            <span class="portal-avatar"><?= e($pInitials) ?></span>
          </button>

          <div class="pop notif-panel" id="notifPanel" hidden role="dialog" aria-label="Notifications">
            <div class="pop-head"><h3>Notifications</h3><?php if ($notifs): ?><span class="pop-count"><?= count($notifs) ?></span><?php endif; ?></div>
            <div class="notif-list">
<?php if ($notifs): foreach ($notifs as $n): ?>
              <a class="notif-item" href="<?= e($n['href']) ?>">
                <span class="notif-ico <?= e($n['cls']) ?>"><?= $n['ico'] ?></span>
                <span class="notif-body"><span class="notif-title"><?= e($n['title']) ?></span><span class="notif-sub"><?= e($n['sub']) ?></span></span>
              </a>
<?php endforeach; else: ?>
              <p class="notif-empty">You’re all caught up. 🎉</p>
<?php endif; ?>
            </div>
          </div>

          <div class="pop avatar-menu" id="avatarMenu" hidden role="menu" aria-label="Account">
            <div class="am-head">
              <span class="portal-avatar lg"><?= e($pInitials) ?></span>
              <span class="am-id"><span class="am-name"><?= e($u['name']) ?></span><span class="am-email"><?= e($u['email']) ?></span></span>
            </div>
            <div class="am-list">
              <a class="am-item" role="menuitem" href="#account">Account settings</a>
              <a class="am-item" role="menuitem" href="/academy/">Academy</a>
              <a class="am-item" role="menuitem" href="/diary/me/">My Diary</a>
              <div class="am-sep"></div>
              <a class="am-item am-danger" role="menuitem" href="#" data-logout>Sign out</a>
            </div>
          </div>
        </div>
      </header>

      <div class="pbody">

      <!-- ═══ OVERVIEW ═══ -->
      <section class="psec" id="overview" aria-labelledby="secOverview">
        <header class="psec-head">
          <div>
            <span class="portal-eyebrow"><?= $isOrg ? 'Member portal' : 'Your learning' ?></span>
            <h1 id="secOverview"><?= $greet ?>, <span id="portalFirst"><?= e($first) ?></span>.</h1>
            <p class="portal-badges">
<?php if ($isOrg): ?>              <span class="portal-badge org">✦ Afrovanguard member</span>
<?php if ($showRole): ?>              <span class="portal-badge"><?= e($roleLabel) ?></span>
<?php endif; ?>
<?php else: ?>              <span class="portal-badge">Learning access</span>
<?php endif; ?>            </p>
          </div>
        </header>

        <nav class="portal-quick" aria-label="Quick actions">
          <a class="pq pq-primary" href="<?= $resume ? '/academy/' . e($resume['slug']) . '/learn/' : '/academy/' ?>">
            <span class="pq-ico" aria-hidden="true">📚</span>
            <span class="pq-txt"><?= $resume ? 'Continue: ' . e(mb_strimwidth((string) $resume['title'], 0, 28, '…')) : 'Browse the Academy' ?></span>
            <?= $resume ? '<span class="pq-sub">' . (int) $resume['pct'] . '% done</span>' : '' ?>
          </a>
          <a class="pq" href="/diary/me/"><span class="pq-ico" aria-hidden="true">✍️</span><span class="pq-txt">Write a diary entry</span></a>
          <a class="pq" href="/mentorship/"><span class="pq-ico" aria-hidden="true">🤝</span><span class="pq-txt"><?= $isOrg ? 'Mentorship' : 'Find a mentor' ?></span></a>
          <a class="pq" href="/donate.html#donate-form"><span class="pq-ico" aria-hidden="true">💛</span><span class="pq-txt">Give</span></a>
        </nav>

        <div class="kpi-row" role="list" aria-label="Your numbers at a glance">
          <div class="kpi" role="listitem"><span class="kpi-n"><?= (int) $inProgress ?></span><span class="kpi-l">In progress</span></div>
          <div class="kpi" role="listitem"><span class="kpi-n"><?= (int) $certs ?></span><span class="kpi-l">Certificate<?= $certs === 1 ? '' : 's' ?></span></div>
          <div class="kpi" role="listitem"><span class="kpi-n"><?= count($myEntries) ?></span><span class="kpi-l">Diary entr<?= count($myEntries) === 1 ? 'y' : 'ies' ?></span></div>
          <div class="kpi" role="listitem"><span class="kpi-n">₦<?= number_format($giving['total_ngn']) ?></span><span class="kpi-l">Given</span></div>
        </div>

        <div class="portal-coming" id="portalComing" hidden aria-label="Coming up">
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
        </div>

<?php if ($cele && !empty($cele['primary'])): $cp = $cele['primary']; ?>
        <div class="portal-card pc-cele" style="--cc:<?= e($cp['theme'] ?? '#f3b416') ?>">
          <div class="pc-head"><h2>Today at Afrovanguard</h2><span class="pc-tag"><?= e(ucfirst((string) ($cp['scope'] ?? ''))) ?></span></div>
          <p class="pc-cele-line"><span class="pc-cele-emoji" aria-hidden="true"><?= $cp['emoji'] ?? '🎉' ?></span> <strong><?= e($cp['title'] ?? '') ?></strong></p>
<?php if (!empty($cp['message'])): ?>          <p class="pc-summary"><?= e($cp['message']) ?></p>
<?php endif; ?>
<?php if (!empty($cp['people'])): ?>
          <p class="pc-summary">🎂 <?= e(implode(', ', array_map(fn($pp) => $pp['name'], $cp['people']))) ?></p>
<?php endif; ?>
        </div>
<?php endif; ?>
      </section>

<?php if ($isOrg):
      require_once AV_ROOT . '/lib/workspace.php';
      $wsAdmin     = LmsAuth::rank((string) $u['role']) >= LmsAuth::ROLE_RANK['admin'];
      $wsSurfaces  = av_workspace_surfaces($wsAdmin);
      $communities = av_workspace_communities();
      $wsEmbeds    = av_workspace_embeds();
?>
      <!-- ═══ WORKSPACE ═══ -->
      <section class="psec" id="workspace" aria-labelledby="secWorkspace">
        <header class="psec-h"><span class="psec-kicker">Workspace</span><h2 id="secWorkspace">Your Google Workspace</h2></header>
        <div class="portal-grid">
        <section class="portal-card span-2 ws-hub">
          <div class="pc-head"><h2>Launchpad</h2><span class="pc-tag ws-domain">@<?= e(av_workspace_domain()) ?></span></div>
          <p class="pc-summary">You’re signed in with Google — jump straight into the Afrovanguard Workspace.</p>
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
<?php if (!empty($wsEmbeds['calendar'])): ?>
        <section class="portal-card span-2 ws-embed">
          <div class="pc-head"><h2>Team calendar</h2><a class="pc-link" href="<?= e(av_ws_link('AV_WS_CALENDAR_URL', 'https://calendar.google.com/a/' . av_workspace_domain())) ?>" target="_blank" rel="noopener noreferrer">Open in Calendar →</a></div>
          <div class="ws-frame"><iframe src="<?= e($wsEmbeds['calendar']) ?>" title="Team calendar" loading="lazy" referrerpolicy="no-referrer"></iframe></div>
        </section>
<?php endif; ?>
<?php if (!empty($wsEmbeds['drive'])): ?>
        <section class="portal-card span-2 ws-embed">
          <div class="pc-head"><h2>Shared files</h2><a class="pc-link" href="<?= e(av_ws_link('AV_WS_DRIVE_URL', 'https://drive.google.com/a/' . av_workspace_domain())) ?>" target="_blank" rel="noopener noreferrer">Open in Drive →</a></div>
          <div class="ws-frame ws-frame--drive"><iframe src="<?= e($wsEmbeds['drive']) ?>" title="Shared files" loading="lazy" referrerpolicy="no-referrer"></iframe></div>
        </section>
<?php endif; ?>
        </div>
      </section>
<?php endif; ?>

      <!-- ═══ LEARNING ═══ -->
      <section class="psec" id="learning" aria-labelledby="secLearning">
        <header class="psec-h"><span class="psec-kicker">Learning</span><h2 id="secLearning">Programmes &amp; mentorship</h2></header>
        <div class="portal-grid">
        <section class="portal-card span-2">
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
<?php if (!empty($c['certified'])): ?>            <a class="learn-cert" href="/academy/certificate.php?course=<?= e($c['slug']) ?>">🎓 View your certificate →</a>
<?php endif; ?>
<?php endforeach; ?>
          </div>
<?php else:
          $recs = [];
          try { $recs = array_slice((new AcademyRepository())->all(), 0, 3); } catch (Throwable $e) {}
          if ($recs): ?>
          <p class="pc-summary">Pick your first programme — free and self-paced:</p>
          <div class="learn-list">
<?php foreach ($recs as $rc): ?>
            <a class="learn-row" href="/academy/<?= e($rc['slug']) ?>/">
              <div class="learn-info">
                <span class="learn-title"><?= e($rc['title']) ?></span>
                <span class="learn-meta"><?= e(mb_strimwidth(trim((string) ($rc['summary'] ?? '')), 0, 90, '…')) ?></span>
              </div>
            </a>
<?php endforeach; ?>
          </div>
<?php else: ?>
          <p class="pc-empty">You haven’t joined a programme yet. <a href="/academy/">Explore the Academy →</a></p>
<?php endif; endif; ?>
        </section>

        <section class="portal-card accent-green">
          <div class="pc-head"><h2>Mentorship</h2><span class="pc-tag"><?= $isOrg ? 'Member' : 'Open' ?></span></div>
          <p><?= $isOrg ? 'Find a mentor, run your mentee inbox, and give back by mentoring others.' : 'Get paired with an Afrovanguard mentor for guidance on your journey.' ?></p>
          <a class="btn btn-primary btn-sm" href="/mentorship/">Open the mentor network →</a>
        </section>

        <section class="portal-card accent-blue">
          <div class="pc-head"><h2>My Diary</h2><a href="/diary/me/" class="pc-link">Open →</a></div>
          <p class="portal-stat"><b><?= count($myEntries) ?></b> diary <?= count($myEntries) === 1 ? 'entry' : 'entries' ?></p>
          <a class="btn btn-primary btn-sm" href="/diary/me/">Write an entry</a>
        </section>
        </div>
      </section>

<?php if ($isOrg && $communities): ?>
      <!-- ═══ COMMUNITY ═══ -->
      <section class="psec" id="community" aria-labelledby="secCommunity">
        <header class="psec-h"><span class="psec-kicker">Community</span><h2 id="secCommunity">Spaces &amp; groups</h2></header>
        <div class="portal-grid">
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
        </div>
      </section>
<?php endif; ?>

      <!-- ═══ GIVING ═══ -->
      <section class="psec" id="giving" aria-labelledby="secGiving">
        <header class="psec-h"><span class="psec-kicker">Giving</span><h2 id="secGiving">Your impact</h2></header>
        <div class="portal-grid">
        <section class="portal-card accent-gold span-2">
          <div class="pc-head"><h2>My giving</h2><?= $giving['count'] ? '<span class="pc-tag">' . (int) $giving['count'] . ' gift' . ($giving['count'] === 1 ? '' : 's') . '</span>' : '' ?></div>
<?php if ($giving['count']): ?>
          <p class="portal-stat"><b>₦<?= number_format($giving['total_ngn']) ?></b> given<?= $giving['inkind'] ? ' · ' . (int) $giving['inkind'] . ' in-kind' : '' ?> — thank you 💛</p>
          <div class="give-list">
<?php foreach (array_slice($giving['rows'], 0, 3) as $g): ?>
            <div class="give-row">
              <span class="give-what"><?= ($g['type'] ?? '') === 'inkind' ? e($g['inkind_type'] ?? 'In-kind gift') : e(($sym[$g['currency'] ?? 'NGN'] ?? '₦')) . number_format((float) ($g['amount'] ?? 0)) ?></span>
              <span class="give-meta"><?= e(substr((string) ($g['created_at'] ?? ''), 0, 10)) ?> · <?= e($g['campaign'] ?? 'general') ?></span>
            </div>
<?php endforeach; ?>
          </div>
          <a class="btn btn-primary btn-sm" href="/donate.html#donate-form">Give again</a>
<?php else: ?>
          <p class="pc-summary">Gifts you make with this email will show here — funds, equipment or your time.</p>
          <a class="btn btn-primary btn-sm" href="/donate.html">Support the movement →</a>
<?php endif; ?>
        </section>
        </div>
      </section>

      <!-- ═══ ACCOUNT ═══ -->
      <section class="psec" id="account" aria-labelledby="secAccount">
        <header class="psec-h"><span class="psec-kicker">Account</span><h2 id="secAccount"><?= $isOrg ? 'Membership & profile' : 'Your account' ?></h2></header>
        <div class="portal-grid">
        <section class="portal-card span-2">
          <div class="pc-head"><h2><?= $isOrg ? 'Membership' : 'Your account' ?></h2><?= $isOrg ? '<span class="pc-tag">Member</span>' : '' ?></div>
<?php if ($isOrg): ?>
          <p class="portal-status-line">✓ You’re an <strong>Afrovanguard member</strong> — full access to mentorship and members-only programmes.</p>
<?php else: ?>
          <p class="portal-status-line">You have <strong>learning access</strong>. Mentorship and members-only spaces are for Afrovanguard members.</p>
<?php endif; ?>
          <dl class="profile-dl">
            <dt>Name</dt><dd id="pfNameShow"><?= e($u['name']) ?></dd>
            <dt>Email</dt><dd><?= e($u['email']) ?></dd>
            <dt><?= $isOrg ? 'Access level' : 'Account' ?></dt><dd><?= $isOrg ? e($accessLevel) : 'Learner' ?></dd>
          </dl>
          <details class="acct-manage">
            <summary>Manage account</summary>
            <form class="acct-form" id="pfNameForm">
              <label>Display name
                <input id="pfNameIn" value="<?= e($u['name']) ?>" minlength="2" maxlength="80" required autocomplete="name" />
              </label>
              <button class="btn btn-outline btn-sm" type="submit">Save name</button>
              <span class="acct-msg" role="status" aria-live="polite"></span>
            </form>
            <form class="acct-form" id="pfPassForm">
              <label>Current password
                <input type="password" id="pfCur" autocomplete="current-password" placeholder="Leave blank if you sign in by code" />
              </label>
              <label>New password
                <input type="password" id="pfNew" autocomplete="new-password" minlength="8" required placeholder="At least 8 characters" />
              </label>
              <button class="btn btn-outline btn-sm" type="submit">Change password</button>
              <span class="acct-msg" role="status" aria-live="polite"></span>
            </form>
          </details>
        </section>
        </div>
      </section>

      <footer class="portal-foot">
        <div class="portal-foot-inner">
          <span class="brand-wordmark portal-foot-mark"><span class="wm-1">Afro</span><span class="wm-2">vanguard</span></span>
          <span class="portal-foot-links"><a href="<?= e(rtrim(SITE_URL, '/')) ?>/">Main site ↗</a> · <a href="mailto:cacentre@afrovanguard.org.ng">Support</a></span>
          <span class="portal-foot-legal">© 2026 Afrovanguard</span>
        </div>
      </footer>
      </div><!-- /.pbody -->
    </main>
  </div>

  <script>
  /* App bar — quick search, notifications, account menu. */
  (function () {
    // ── Popovers (notifications + account): open one, close the other; outside
    //    click and Escape dismiss; focus returns to the trigger.
    function pair(btnId, popId) {
      var btn = document.getElementById(btnId), pop = document.getElementById(popId);
      if (!btn || !pop) return null;
      var api = {
        open: function (on) {
          pop.hidden = !on; btn.setAttribute('aria-expanded', String(on));
          if (on) { closeOthers(api); var f = pop.querySelector('a,button'); if (f) try { f.focus({ preventScroll: true }); } catch (e) {} }
        },
        toggle: function () { api.open(pop.hidden); },
        contains: function (t) { return btn.contains(t) || pop.contains(t); },
        focusBtn: function () { btn.focus(); },
        isOpen: function () { return !pop.hidden; }
      };
      btn.addEventListener('click', function (e) { e.stopPropagation(); api.toggle(); });
      return api;
    }
    var pops = [];
    function closeOthers(except) { pops.forEach(function (p) { if (p !== except && p.isOpen()) p.open(false); }); }
    var notif = pair('notifBtn', 'notifPanel'); if (notif) pops.push(notif);
    var acct  = pair('avatarBtn', 'avatarMenu'); if (acct) pops.push(acct);
    document.addEventListener('click', function (e) { pops.forEach(function (p) { if (p.isOpen() && !p.contains(e.target)) p.open(false); }); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') pops.forEach(function (p) { if (p.isOpen()) { p.open(false); p.focusBtn(); } }); });

    // ── Quick search / jump — a client-side index of sections, enrolled
    //    courses and quick actions. Arrow keys navigate; Enter opens the top
    //    hit; no match → hand the query to Chioma.
    var input = document.getElementById('pbarSearch'), menu = document.getElementById('pbarSearchMenu');
    if (input && menu) {
      var index = [];
      document.querySelectorAll('.ps-nav .ps-link').forEach(function (l) {
        var label = (l.textContent || '').trim();
        index.push({ label: label, kind: 'Section', href: l.getAttribute('href'), ico: '❯' });
      });
      document.querySelectorAll('.learn-row').forEach(function (r) {
        var t = r.querySelector('.learn-title'); if (t) index.push({ label: t.textContent.trim(), kind: 'Course', href: r.getAttribute('href'), ico: '📘' });
      });
      document.querySelectorAll('.portal-quick .pq').forEach(function (q) {
        var t = q.querySelector('.pq-txt'); if (t) index.push({ label: t.textContent.trim(), kind: 'Action', href: q.getAttribute('href'), ico: '⚡' });
      });
      var active = -1, shown = [];
      function go(item) {
        if (!item) return;
        menu.hidden = true; input.setAttribute('aria-expanded', 'false');
        if (item.href && item.href.charAt(0) === '#') {
          var el = document.querySelector(item.href); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
          history.replaceState(null, '', item.href);
        } else if (item.href) { location.href = item.href; }
        input.blur();
      }
      function render(q) {
        shown = index.filter(function (i) { return i.label.toLowerCase().indexOf(q) !== -1; }).slice(0, 8);
        active = -1;
        if (!q) { menu.hidden = true; input.setAttribute('aria-expanded', 'false'); return; }
        var html = shown.map(function (i, n) {
          return '<a class="pbar-search-item" role="option" data-n="' + n + '" href="' + i.href + '">' +
            '<span class="si-ico" aria-hidden="true">' + i.ico + '</span>' +
            '<span>' + i.label.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }) + '</span>' +
            '<span class="si-kind">' + i.kind + '</span></a>';
        }).join('');
        if (!shown.length) {
          html = '<div class="pbar-search-empty">No matches. Press <b>Enter</b> to ask Chioma about “' +
            q.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }) + '”.</div>';
        }
        menu.innerHTML = html; menu.hidden = false; input.setAttribute('aria-expanded', 'true');
      }
      input.addEventListener('input', function () { render(this.value.trim().toLowerCase()); });
      input.addEventListener('focus', function () { if (this.value.trim()) render(this.value.trim().toLowerCase()); });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          if (!shown.length) return; e.preventDefault();
          active += (e.key === 'ArrowDown' ? 1 : -1);
          if (active < 0) active = shown.length - 1; if (active >= shown.length) active = 0;
          menu.querySelectorAll('.pbar-search-item').forEach(function (el, n) { el.classList.toggle('active', n === active); });
        } else if (e.key === 'Enter') {
          e.preventDefault();
          if (shown.length) { go(shown[active >= 0 ? active : 0]); }
          else if (this.value.trim() && window.chioma) { menu.hidden = true; window.chioma.open(this.value.trim()); this.value = ''; }
        } else if (e.key === 'Escape') { menu.hidden = true; input.setAttribute('aria-expanded', 'false'); }
      });
      menu.addEventListener('click', function (e) { var a = e.target.closest('.pbar-search-item'); if (a) { e.preventDefault(); go(shown[+a.getAttribute('data-n')]); } });
      document.addEventListener('click', function (e) { if (!input.contains(e.target) && !menu.contains(e.target)) { menu.hidden = true; input.setAttribute('aria-expanded', 'false'); } });
      // "/" focuses search (unless already typing in a field).
      document.addEventListener('keydown', function (e) {
        if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName || '')) && !e.target.isContentEditable) { e.preventDefault(); input.focus(); }
      });
    }
  })();
  </script>
  <script>
  /* Sidebar shell — mobile drawer + scrollspy section nav. */
  (function () {
    var side = document.getElementById('pside'), scrim = document.getElementById('psideScrim'), burger = document.getElementById('psideBurger');
    function setSide(open) {
      side.classList.toggle('open', open);
      if (scrim) scrim.hidden = !open;
      if (burger) burger.setAttribute('aria-expanded', String(open));
      document.body.style.overflow = open ? 'hidden' : '';
    }
    if (burger) burger.addEventListener('click', function () { setSide(!side.classList.contains('open')); });
    if (scrim) scrim.addEventListener('click', function () { setSide(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && side.classList.contains('open')) setSide(false); });
    var links = Array.prototype.slice.call(document.querySelectorAll('.ps-nav .ps-link'));
    links.forEach(function (l) { l.addEventListener('click', function () { setSide(false); }); });
    // Scrollspy: highlight the section closest to the top of the viewport.
    var secs = Array.prototype.slice.call(document.querySelectorAll('.psec'));
    function spy() {
      var best = null, bestTop = -Infinity;
      secs.forEach(function (s) {
        var top = s.getBoundingClientRect().top;
        if (top <= 120 && top > bestTop) { bestTop = top; best = s; }
      });
      if (!best) best = secs[0];
      links.forEach(function (l) { l.classList.toggle('active', l.getAttribute('href') === '#' + best.id); });
    }
    var t = false;
    window.addEventListener('scroll', function () { if (t) return; t = true; requestAnimationFrame(function () { spy(); t = false; }); }, { passive: true });
    spy();
  })();
  </script>
  <script>
  /* Account self-service — display name + password, against the academy API. */
  (function () {
    function wire(formId, build, onOk) {
      var form = document.getElementById(formId); if (!form) return;
      var msg = form.querySelector('.acct-msg');
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var req = build(); if (!req) return;
        msg.textContent = 'Saving…';
        fetch('/academy/api.php?action=' + req.action, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(req.data)
        }).then(function (r) { return r.json(); }).then(function (d) {
          if (d && d.ok) { msg.textContent = 'Saved ✓'; if (onOk) onOk(d); }
          else msg.textContent = (d && d.error) || 'Could not save.';
        }).catch(function () { msg.textContent = 'Network error — try again.'; });
      });
    }
    wire('pfNameForm', function () {
      var v = document.getElementById('pfNameIn').value.trim();
      return v.length >= 2 ? { action: 'me-update', data: { name: v } } : null;
    }, function (d) {
      var name = (d.user && d.user.name) || '';
      var show = document.getElementById('pfNameShow'); if (show) show.textContent = name;
      var first = document.getElementById('portalFirst'); if (first) first.textContent = name.split(' ')[0] || name;
      var side = document.getElementById('psUserName'); if (side) side.textContent = name;
    });
    wire('pfPassForm', function () {
      var nw = document.getElementById('pfNew').value;
      if (nw.length < 8) return null;
      return { action: 'change-password', data: { current: document.getElementById('pfCur').value, password: nw } };
    }, function () {
      document.getElementById('pfCur').value = ''; document.getElementById('pfNew').value = '';
    });
  })();
  </script>
  <script>
  (function () {
    var btn = document.getElementById('portalTheme'); if (!btn) return;
    btn.addEventListener('click', function () {
      var light = document.body.classList.toggle('is-light');
      document.cookie = 'av_portal_theme=' + (light ? 'light' : 'dark') + ';path=/;max-age=31536000;samesite=Lax';
    });
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
      // bottom offset clears Chioma's FAB, which owns the corner itself
      b.style.cssText = 'position:fixed;right:18px;bottom:92px;z-index:300;background:#f3b416;color:#111827;border:0;border-radius:9999px;padding:13px 22px;font:700 14px/1 Montserrat,sans-serif;box-shadow:0 12px 30px rgba(0,0,0,.35);cursor:pointer';
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
  /* Announcements → the notification bell + a native notification for new ones.
     Works on any browser (the bell is universal); the native popup is a
     progressive enhancement where the member has granted permission. */
  (function () {
    var panel = document.getElementById('notifPanel'); if (!panel) return;
    var list = panel.querySelector('.notif-list'), badge = document.getElementById('notifBadge'), btn = document.getElementById('notifBtn');
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function seen() { try { return parseInt(localStorage.getItem('av.ann.seen') || '0', 10) || 0; } catch (e) { return 0; } }
    function setSeen(id) { try { localStorage.setItem('av.ann.seen', String(id)); } catch (e) {} }
    fetch('/academy/api.php?action=announcements', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var anns = (d && d.announcements) || []; if (!anns.length) return;
        var empty = list.querySelector('.notif-empty'); if (empty) empty.remove();
        list.insertAdjacentHTML('afterbegin', anns.map(function (a) {
          var href = a.url || '#overview';
          return '<a class="notif-item" href="' + esc(href) + '">'
            + '<span class="notif-ico">📣</span>'
            + '<span class="notif-body"><span class="notif-title">' + esc(a.title) + '</span>'
            + '<span class="notif-sub">' + esc((a.body || '').slice(0, 80)) + '</span></span></a>';
        }).join(''));
        if (badge) badge.hidden = false;
        var newest = anns[0].id || 0;
        if (newest > seen()) {
          if ('Notification' in window && Notification.permission === 'granted') {
            try { new Notification('Afrovanguard — ' + anns[0].title, { body: (anns[0].body || '').slice(0, 120), icon: '/assets/site/icon-192.png' }); } catch (e) {}
          }
          setSeen(newest);
        }
      }).catch(function () {});
    // First bell open → offer to enable native notifications.
    if (btn && 'Notification' in window) {
      btn.addEventListener('click', function once() {
        if (Notification.permission === 'default') { try { Notification.requestPermission(); } catch (e) {} }
        btn.removeEventListener('click', once);
      });
    }
  })();
  </script>
  <script src="/assets/site/nav.js" defer></script>
  <script src="/assets/site/chioma.js" defer></script>
</body>
</html>
