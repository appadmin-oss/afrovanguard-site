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
// The portal has its OWN theme (dark by default, with a light toggle) — server-set
// from a cookie so there's no flash.
$ptheme    = (($_COOKIE['av_portal_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
$parts     = preg_split('/\s+/', trim((string) $u['name'])) ?: [];
$pInitials = strtoupper(substr((string) ($parts[0] ?? 'A'), 0, 1) . substr((string) ($parts[1] ?? ''), 0, 1)) ?: 'A';

render_head([
    'title'      => ($isOrg ? 'Member portal' : 'Your learning') . ' — Afrovanguard',
    'desc'       => 'Your Afrovanguard portal — learning, and (for members) mentorship and members-only spaces.',
    'canonical'  => rtrim(SITE_URL, '/') . '/portal/',
    'robots'     => 'noindex, nofollow',
    'body_class' => 'portal-page' . ($ptheme === 'light' ? ' is-light' : ''),
    'css'        => ['/portal/portal.css'],
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

      <div class="portal-grid">
        <!-- My learning -->
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
<?php endforeach; ?>
          </div>
<?php else: ?>
          <p class="pc-empty">You haven’t joined a programme yet. <a href="/academy/">Explore the Academy →</a></p>
<?php endif; ?>
        </section>

<?php if ($isOrg): ?>
        <!-- Mentorship — members only; not shown to learners at all -->
        <section class="portal-card accent-green">
          <div class="pc-head"><h2>Mentorship</h2><span class="pc-tag">Active</span></div>
          <p>You’re connected to the Afrovanguard mentor network.<?= $canMentor ? ' As a mentor, your mentees and sessions will appear here.' : ' Your mentor and upcoming sessions will appear here.' ?></p>
          <a class="btn btn-primary btn-sm" href="mailto:cacentre@afrovanguard.org.ng?subject=Mentorship">Reach the mentorship team</a>
        </section>
<?php endif; ?>

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
      var light = document.body.classList.toggle('is-light');
      document.cookie = 'av_portal_theme=' + (light ? 'light' : 'dark') + ';path=/;max-age=31536000;samesite=Lax';
    });
  })();
  </script>
  <script src="/assets/site/nav.js" defer></script>
</body>
</html>
