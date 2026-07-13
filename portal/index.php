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
// "Continue learning": the freshest in-progress course (most recent activity,
// then most recently enrolled), and a short recap of the learner's latest notes.
$resume = null;
foreach ($courses as $c) {
    if (!empty($c['complete']) || (int) $c['pct'] === 0 && empty($c['last_active'])) continue;
    if (!$resume) { $resume = $c; continue; }
    if ((string) ($c['last_active'] ?? '') > (string) ($resume['last_active'] ?? '')) $resume = $c;
}
if (!$resume) { foreach ($courses as $c) { if (empty($c['complete'])) { $resume = $c; break; } } }
$recentNotes = $lms->recentNotes((int) $u['id'], 4);
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
// Upcoming mentorship sessions (with Meet links) for the portal schedule/calendar.
$upcoming = class_exists('Mentorship') ? Mentorship::upcomingSessions((int) $u['id'], 6) : [];
// The portal has its OWN theme (dark by default, with a light toggle) — server-set
// from a cookie so there's no flash.
$ptheme    = (($_COOKIE['av_portal_theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
$parts     = preg_split('/\s+/', trim((string) $u['name'])) ?: [];
$pInitials = strtoupper(substr((string) ($parts[0] ?? 'A'), 0, 1) . substr((string) ($parts[1] ?? ''), 0, 1)) ?: 'A';

render_head([
    'title'      => ($isOrg ? 'Member portal' : 'Your learning') . ' — Afrovanguard',
    'desc'       => 'Your Afrovanguard portal — learning, and (for members) mentorship and members-only spaces.',
    'canonical'  => rtrim(SITE_URL, '/') . '/portal/',
    'robots'     => 'noindex, nofollow',
    'body_class' => 'portal-page' . ($ptheme === 'dark' ? ' is-dark' : ''),
    'css'        => ['/portal/portal.css'],
    'manifest'   => '/manifest.webmanifest',
]);
?>
  <header class="portal-bar">
    <div class="container portal-bar-inner">
      <a class="portal-brand" href="<?= e(rtrim(SITE_URL, '/')) ?>/" aria-label="Afrovanguard — home">
        <span class="brand-wordmark"><span class="wm-1">Afro</span><span class="wm-2">vanguard</span></span>
        <span class="portal-tag"><?= e($tag) ?></span>
      </a>
      <nav class="portal-bar-actions" aria-label="Member navigation">
        <a class="portal-bar-link" href="/academy/">Academy</a>
        <a class="portal-bar-link" href="/diary/me/">Diary</a>
        <button type="button" class="portal-icon-btn" id="portalTheme" aria-label="Switch theme" title="Light / dark">
          <svg class="ico-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/></svg>
          <svg class="ico-moon" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 109.8 9.8z"/></svg>
        </button>
        <div class="portal-user">
          <span class="portal-avatar" aria-hidden="true"><?= e($pInitials) ?></span>
          <a class="portal-bar-link portal-signout" href="#" data-logout>Sign out</a>
        </div>
      </nav>
    </div>
  </header>
  <main id="main-content" class="portal portal--<?= $isOrg ? 'member' : 'learner' ?>">
    <div class="container">
      <header class="portal-head">
        <div>
          <span class="portal-eyebrow"><?= $isOrg ? 'Member portal' : 'Your learning' ?></span>
          <h1>Welcome back, <?= e($first) ?>.</h1>
          <p class="portal-badges">
<?php if ($isOrg): ?>            <span class="portal-badge org">✦ Afrovanguard member</span>
<?php if ($showRole): ?>            <span class="portal-badge"><?= e($roleLabel) ?></span>
<?php endif; ?>
<?php else: ?>            <span class="portal-badge">Learning access</span>
<?php endif; ?>          </p>
        </div>
        <a class="btn btn-outline btn-sm" href="#" data-logout>Sign out</a>
      </header>

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
<?php if ($isOrg):
        require_once AV_ROOT . '/lib/workspace.php';
        $wsAdmin     = LmsAuth::rank((string) $u['role']) >= LmsAuth::ROLE_RANK['admin'];
        $wsSurfaces  = av_workspace_surfaces($wsAdmin);
        $communities = av_workspace_communities();
        $wsEmbeds    = av_workspace_embeds();
?>
        <!-- Your Workspace — SSO launchpad into Google Workspace (members only) -->
        <section class="portal-card span-2 ws-hub">
          <div class="pc-head"><h2>Your Workspace</h2><span class="pc-tag ws-domain">@<?= e(av_workspace_domain()) ?></span></div>
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
<?php endif; ?>

        <!-- My learning -->
        <section class="portal-card span-2">
          <div class="pc-head"><h2>My learning</h2><a href="/academy/" class="pc-link">Browse the Academy →</a></div>
<?php if ($courses): ?>
          <p class="pc-summary"><b><?= count($courses) ?></b> programme<?= count($courses) === 1 ? '' : 's' ?><?= $inProgress ? ' · ' . $inProgress . ' in progress' : '' ?><?= $certs ? ' · ' . $certs . ' 🎓 certificate' . ($certs === 1 ? '' : 's') : '' ?></p>
<?php if ($resume): ?>
          <a class="resume-card" href="/academy/<?= e($resume['slug']) ?>/learn/">
            <div class="resume-copy">
              <span class="resume-kicker"><?= (int) $resume['pct'] > 0 ? 'Continue where you left off' : 'Start learning' ?></span>
              <span class="resume-title"><?= e($resume['title']) ?></span>
<?php if (!empty($resume['next'])): ?>
              <span class="resume-next">Next · <?= e($resume['next']['title']) ?></span>
<?php endif; ?>
              <div class="resume-bar" aria-hidden="true"><span style="width:<?= (int) $resume['pct'] ?>%"></span></div>
              <span class="resume-meta"><?= (int) $resume['done'] ?> of <?= (int) $resume['total'] ?> lessons · <?= (int) $resume['pct'] ?>%</span>
            </div>
            <span class="resume-go" aria-hidden="true">Resume →</span>
          </a>
<?php endif; ?>
          <div class="learn-list">
<?php foreach ($courses as $c): ?>
            <a class="learn-row" href="/academy/<?= e($c['slug']) ?>/learn/">
              <div class="learn-info">
                <span class="learn-title"><?= e($c['title']) ?></span>
                <span class="learn-meta"><?= $c['complete'] ? '✓ Complete' : ((int) $c['done']) . ' of ' . (int) $c['total'] . ' lessons · ' . ((int) $c['pct']) . '%' ?><?= $c['certified'] ? ' · 🎓 Certified' : '' ?></span>
              </div>
              <div class="learn-bar" aria-hidden="true"><span style="width:<?= (int) $c['pct'] ?>%"></span></div>
            </a>
<?php endforeach; ?>
          </div>
<?php if ($recentNotes): ?>
          <div class="notes-recap">
            <div class="notes-recap-head"><h3>Your recent notes</h3></div>
<?php foreach ($recentNotes as $n): ?>
            <a class="note-chip" href="/academy/<?= e($n['course_slug']) ?>/learn/<?= e($n['lesson_slug']) ?>#narration">
              <span class="note-chip-lesson"><?= e($n['lesson_title']) ?><span class="note-chip-course"> · <?= e($n['course_title']) ?></span></span>
              <span class="note-chip-excerpt"><?= e(mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string) $n['body'])), 0, 120, '…')) ?></span>
            </a>
<?php endforeach; ?>
          </div>
<?php endif; ?>
<?php else: ?>
          <p class="pc-empty">You haven’t joined a programme yet. <a href="/academy/">Explore the Academy →</a></p>
<?php endif; ?>
        </section>

        <!-- Mentorship — find a mentor (everyone); members can also mentor -->
        <section class="portal-card accent-green">
          <div class="pc-head"><h2>Mentorship</h2><span class="pc-tag"><?= $isOrg ? 'Member' : 'Open' ?></span></div>
          <p><?= $isOrg ? 'Find a mentor, run your mentee inbox, and give back by mentoring others.' : 'Get paired with an Afrovanguard mentor for guidance on your journey.' ?></p>
          <a class="btn btn-primary btn-sm" href="/mentorship/">Open the mentor network →</a>
        </section>

        <!-- Status & profile -->
        <section class="portal-card">
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
        <section class="portal-card journey-card span-2">
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

        <!-- My Diary -->
        <section class="portal-card accent-blue">
          <div class="pc-head"><h2>My Diary</h2><a href="/diary/me/" class="pc-link">Open →</a></div>
          <p class="portal-stat"><b><?= count($myEntries) ?></b> diary <?= count($myEntries) === 1 ? 'entry' : 'entries' ?></p>
          <a class="btn btn-primary btn-sm" href="/diary/me/">Write an entry</a>
        </section>
      </div>
    </div>
  </main>

  <footer class="portal-foot">
    <div class="container portal-foot-inner">
      <span class="brand-wordmark portal-foot-mark"><span class="wm-1">Afro</span><span class="wm-2">vanguard</span></span>
      <span class="portal-foot-links"><a href="<?= e(rtrim(SITE_URL, '/')) ?>/">Main site ↗</a> · <a href="mailto:cacentre@afrovanguard.org.ng">Support</a></span>
      <span class="portal-foot-legal">© 2026 Afrovanguard</span>
    </div>
  </footer>
  <script>
  (function () {
    var btn = document.getElementById('portalTheme'); if (!btn) return;
    btn.addEventListener('click', function () {
      var dark = document.body.classList.toggle('is-dark');
      document.cookie = 'av_portal_theme=' + (dark ? 'dark' : 'light') + ';path=/;max-age=31536000;samesite=Lax';
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
  <script src="/assets/site/nav.js" defer></script>
</body>
</html>
