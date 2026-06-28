<?php
/**
 * academy/teach.php — instructor dashboard.
 *   /academy/teach/                 → overview of my courses + stats
 *   /academy/teach/<course>/        → learner roster for one of my courses
 *
 * Gated by an LMS account with the instructor (or admin) role. Instructors
 * only ever see courses where courses.instructor_id is their own id.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$user = LmsAuth::user();
$lms  = new LmsRepository();
$courseSlug   = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['course'] ?? '')));
$isInstructor = $user && in_array($user['role'], ['instructor', 'admin'], true);

// Resolve the course up front (before any output) so a bad/forbidden slug
// can return a clean 404 with the right status code.
$course = null;
if ($isInstructor && $courseSlug !== '') {
    $course = $user['role'] === 'admin'
        ? (new AcademyRepository())->bySlug($courseSlug, true)
        : $lms->ownsCourse((int) $user['id'], $courseSlug);
    if (!$course) { require_once AV_ROOT . '/lib/errors.php'; av_error_render(404); exit; }
}

render_head([
    'title' => 'Instructor dashboard — Afrovanguard Academy',
    'desc'  => 'Manage your programmes and follow your learners’ progress.',
    'canonical' => academy_url('teach/'), 'og_kind' => 'website',
    'robots' => 'noindex,nofollow',
    'css' => ['/academy/academy.css'], 'body_class' => 'academy',
]);
render_nav('academy');

/* ── Not signed in, or not an instructor ── */
if (!$isInstructor) {
    ?>
  <main id="main-content">
    <div class="container">
      <div class="gate">
        <h2>Instructor access</h2>
<?php if (!$user): ?>
        <p>Sign in with your instructor account to manage your programmes and see how your learners are progressing.</p>
        <div class="err-actions" style="justify-content:center;display:flex;gap:12px;flex-wrap:wrap">
          <button class="btn btn-primary" data-auth="login">Sign in</button>
        </div>
<?php else: ?>
        <p>Your account doesn’t have instructor access yet. If you teach a programme, ask an Academy admin to assign it to <strong><?= e($user['email']) ?></strong>.</p>
        <a class="btn btn-outline" href="<?= e(academy_url('')) ?>">Back to the Academy</a>
<?php endif; ?>
      </div>
    </div>
  </main>
<?php
    echo '<script src="/academy/academy.js" defer></script>';
    render_footer();
    exit;
}

$uid = (int) $user['id'];

/* ── Roster view for a single course ── */
if ($course) {
    $stats = $lms->courseStats((int) $course['id']);
    $roster = $lms->roster((int) $course['id']);
    ?>
  <main id="main-content">
    <div class="container teach">
      <nav class="breadcrumb"><a href="<?= e(academy_url('teach/')) ?>">Dashboard</a><span class="sep">/</span><span><?= e($course['title']) ?></span></nav>
      <h1 class="teach-h1"><?= e($course['title']) ?></h1>
      <div class="teach-stats">
        <div class="tstat"><span class="tn"><?= (int) $stats['enrolled'] ?></span><span class="tl">Learners</span></div>
        <div class="tstat"><span class="tn"><?= (int) $stats['completed'] ?></span><span class="tl">Completed</span></div>
        <div class="tstat"><span class="tn"><?= (int) $stats['avg_pct'] ?>%</span><span class="tl">Avg. progress</span></div>
        <div class="tstat"><span class="tn"><?= (int) $stats['lessons'] ?></span><span class="tl">Lessons</span></div>
      </div>
<?php if (!$roster): ?>
      <p class="teach-empty">No learners have enrolled yet. Share your programme to get started.</p>
<?php else: ?>
      <div class="teach-table-wrap">
        <table class="teach-table">
          <thead><tr><th>Learner</th><th>Progress</th><th>Lessons</th><th>Last active</th><th>Certificate</th></tr></thead>
          <tbody>
<?php foreach ($roster as $r): ?>
            <tr>
              <td><span class="r-name"><?= e($r['name']) ?></span><span class="r-email"><?= e($r['email']) ?></span></td>
              <td><div class="r-bar"><span style="width:<?= (int) $r['pct'] ?>%"></span></div><span class="r-pct"><?= (int) $r['pct'] ?>%</span></td>
              <td><?= (int) $r['done'] ?>/<?= (int) $stats['lessons'] ?></td>
              <td><?= $r['last_active'] ? e(date('M j, Y', strtotime((string) $r['last_active']))) : '—' ?></td>
              <td><?= $r['certified'] ? '<span class="r-cert">✓ Issued</span>' : '—' ?></td>
            </tr>
<?php endforeach; ?>
          </tbody>
        </table>
      </div>
<?php endif; ?>
    </div>
  </main>
<?php
    echo '<script src="/academy/academy.js" defer></script>';
    render_footer();
    exit;
}

/* ── Overview: all of my courses ── */
$courses = $user['role'] === 'admin'
    ? array_map(function ($c) use ($lms) { $c['stats'] = $lms->courseStats((int) $c['id']); return $c; }, (new AcademyRepository())->allForAdmin())
    : $lms->coursesForInstructor($uid);
$totLearners = array_sum(array_map(fn($c) => (int) $c['stats']['enrolled'], $courses));
$totCerts = array_sum(array_map(fn($c) => (int) $c['stats']['completed'], $courses));
?>
  <main id="main-content">
    <div class="container teach">
      <span class="diary-eyebrow">Instructor dashboard</span>
      <h1 class="teach-h1">Welcome, <?= e(explode(' ', $user['name'])[0]) ?></h1>
      <div class="teach-stats">
        <div class="tstat"><span class="tn"><?= count($courses) ?></span><span class="tl"><?= $user['role'] === 'admin' ? 'All programmes' : 'My programmes' ?></span></div>
        <div class="tstat"><span class="tn"><?= (int) $totLearners ?></span><span class="tl">Total learners</span></div>
        <div class="tstat"><span class="tn"><?= (int) $totCerts ?></span><span class="tl">Certificates issued</span></div>
      </div>
<?php if (!$courses): ?>
      <p class="teach-empty">No programmes are assigned to you yet. An Academy admin can assign a programme to <strong><?= e($user['email']) ?></strong> from the Studio.</p>
<?php else: ?>
      <div class="teach-grid">
<?php foreach ($courses as $c): $s = $c['stats']; ?>
        <a class="teach-card" href="<?= e(academy_url('teach/' . $c['slug'] . '/')) ?>">
          <div class="tc-head"><span class="tc-title"><?= e($c['title']) ?></span><span class="tc-status <?= $c['status'] === 'published' ? 'pub' : 'draft' ?>"><?= e($c['status']) ?></span></div>
          <div class="tc-stats"><span><strong><?= (int) $s['enrolled'] ?></strong> learners</span><span><strong><?= (int) $s['completed'] ?></strong> completed</span></div>
          <div class="r-bar"><span style="width:<?= (int) $s['avg_pct'] ?>%"></span></div>
          <span class="tc-link">View roster →</span>
        </a>
<?php endforeach; ?>
      </div>
<?php endif; ?>
    </div>
  </main>
<?php
echo '<script src="/academy/academy.js" defer></script>';
render_footer();
