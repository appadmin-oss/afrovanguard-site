<?php
/**
 * portal/index.php — the member portal / dashboard.
 *
 * The authenticated home for learners and @afrovanguard.org.ng members.
 * Served at /portal (a real folder, so WordPress never intercepts it).
 * Four sections (per the agreed scope): My learning, Mentorship hub
 * (org-members only), Membership & profile, My Diary.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$u = LmsAuth::user();
if (!$u) { header('Location: ' . av_login_url('/portal/')); exit; }

$lms       = new LmsRepository();
$courses   = $lms->enrolledCourses((int) $u['id']);
$isMember  = $lms->isMember((int) $u['id']);     // paid Academy membership
$isOrg     = LmsAuth::isOrgMember($u);           // org member → mentorship access
$canMentor = LmsAuth::canMentor($u);
$myEntries = (new DiaryJournal())->mine((int) $u['id']);
$first     = explode(' ', trim((string) $u['name']))[0] ?: 'there';
$roleLabel = ucfirst((string) $u['role']);

render_head([
    'title'      => 'Your portal — Afrovanguard',
    'desc'       => 'Your Afrovanguard member portal — learning, mentorship, membership and your diary.',
    'canonical'  => rtrim(SITE_URL, '/') . '/portal/',
    'robots'     => 'noindex, nofollow',
    'body_class' => 'portal-page',
    'css'        => ['/portal/portal.css'],
]);
render_nav('');
?>
  <main id="main-content" class="portal">
    <div class="container">
      <header class="portal-head">
        <div>
          <span class="portal-eyebrow">Member portal</span>
          <h1>Welcome back, <?= e($first) ?>.</h1>
          <p class="portal-badges">
            <span class="portal-badge"><?= e($roleLabel) ?></span>
<?php if ($isOrg): ?>            <span class="portal-badge org">Afrovanguard member</span>
<?php endif; ?>          </p>
        </div>
        <a class="btn btn-outline btn-sm" href="#" data-logout>Sign out</a>
      </header>

      <div class="portal-grid">
        <!-- My learning -->
        <section class="portal-card span-2">
          <div class="pc-head"><h2>My learning</h2><a href="/academy/" class="pc-link">Browse the Academy →</a></div>
<?php if ($courses): ?>
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

        <!-- Mentorship (org members only) -->
        <section class="portal-card<?= $isOrg ? '' : ' is-locked' ?>">
          <div class="pc-head"><h2>Mentorship</h2><?= $isOrg ? '<span class="pc-tag">Active</span>' : '<span class="pc-tag locked">Members only</span>' ?></div>
<?php if ($isOrg): ?>
          <p>You’re connected to the Afrovanguard mentor network.<?= $canMentor ? ' As a mentor, your mentees and sessions will appear here.' : ' Your mentor and upcoming sessions will appear here.' ?></p>
          <a class="btn btn-primary btn-sm" href="mailto:cacentre@afrovanguard.org.ng?subject=Mentorship">Reach the mentorship team</a>
<?php else: ?>
          <p>Mentorship is for Afrovanguard members. Sign in with your <strong>@afrovanguard.org.ng</strong> account to unlock it.</p>
<?php endif; ?>
        </section>

        <!-- Membership & profile -->
        <section class="portal-card">
          <div class="pc-head"><h2>Membership &amp; profile</h2></div>
          <dl class="profile-dl">
            <dt>Name</dt><dd><?= e($u['name']) ?></dd>
            <dt>Email</dt><dd><?= e($u['email']) ?></dd>
            <dt>Access level</dt><dd><?= e($roleLabel) ?></dd>
            <dt>Academy membership</dt><dd><?= $isMember ? '<strong>Active</strong>' : 'Not a member yet' ?></dd>
          </dl>
<?php if (!$isMember): ?>          <a class="btn btn-outline btn-sm" href="/academy/#membership">Become a member</a>
<?php endif; ?>
        </section>

        <!-- My Diary -->
        <section class="portal-card">
          <div class="pc-head"><h2>My Diary</h2><a href="/diary/me/" class="pc-link">Open →</a></div>
          <p class="portal-stat"><b><?= count($myEntries) ?></b> diary <?= count($myEntries) === 1 ? 'entry' : 'entries' ?></p>
          <a class="btn btn-primary btn-sm" href="/diary/me/">Write an entry</a>
        </section>
      </div>
    </div>
  </main>
<?php render_footer();
