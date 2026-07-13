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

/* ── CSV roster export (instructor-gated; streamed before any HTML) ── */
if ($course && ($_GET['export'] ?? '') === 'csv') {
    $stats  = $lms->courseStats((int) $course['id']);
    $roster = $lms->roster((int) $course['id'], 5000);
    $fname  = 'afrovanguard-' . $course['slug'] . '-learners-' . gmdate('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
    fputcsv($out, ['Name', 'Email', 'Progress %', 'Lessons completed', 'Lessons total', 'Last active', 'Certificate']);
    foreach ($roster as $r) {
        fputcsv($out, [
            $r['name'], $r['email'], (int) $r['pct'], (int) $r['done'], (int) $stats['lessons'],
            $r['last_active'] ? date('Y-m-d', strtotime((string) $r['last_active'])) : '',
            $r['certified'] ? 'Issued' : '',
        ]);
    }
    fclose($out);
    exit;
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
    $funnel = $lms->lessonFunnel((int) $course['id']);
    // Learner status buckets, for the filter chips + summary.
    $notStarted = 0; $active = 0; $done = 0;
    foreach ($roster as $r) { if ($r['pct'] >= 100) $done++; elseif ($r['done'] > 0) $active++; else $notStarted++; }
    $statusOf = fn($r) => $r['pct'] >= 100 ? 'completed' : ($r['done'] > 0 ? 'active' : 'notstarted');
    ?>
  <main id="main-content">
    <div class="container teach" data-teach-roster>
      <nav class="breadcrumb"><a href="<?= e(academy_url('teach/')) ?>">Dashboard</a><span class="sep">/</span><span><?= e($course['title']) ?></span></nav>
      <div class="teach-head-row">
        <h1 class="teach-h1"><?= e($course['title']) ?></h1>
<?php if ($roster): ?>        <a class="btn btn-pill-ghost btn-sm teach-export" href="<?= e(academy_url('teach/' . $course['slug'] . '/')) ?>?export=csv">↓ Export CSV</a><?php endif; ?>
      </div>
      <div class="teach-stats">
        <div class="tstat"><span class="tn"><?= (int) $stats['enrolled'] ?></span><span class="tl">Learners</span></div>
        <div class="tstat"><span class="tn"><?= (int) $stats['completed'] ?></span><span class="tl">Completed</span></div>
        <div class="tstat"><span class="tn"><?= (int) $stats['avg_pct'] ?>%</span><span class="tl">Avg. progress</span></div>
        <div class="tstat"><span class="tn"><?= (int) $stats['lessons'] ?></span><span class="tl">Lessons</span></div>
      </div>
<?php if (!$roster): ?>
      <p class="teach-empty">No learners have enrolled yet. Share your programme to get started.</p>
<?php else: ?>
<?php if ($funnel): ?>
      <section class="funnel">
        <div class="funnel-head"><h2>Lesson completion</h2><p>How many of your <?= (int) $stats['enrolled'] ?> learner<?= $stats['enrolled'] === 1 ? '' : 's' ?> have finished each lesson — watch for the drop-off.</p></div>
        <ol class="funnel-list">
<?php foreach ($funnel as $fi => $f): ?>
          <li class="funnel-row">
            <span class="funnel-num"><?= $fi + 1 ?></span>
            <span class="funnel-title"><?= e($f['title']) ?></span>
            <span class="funnel-track"><span class="funnel-fill" style="width:<?= (int) $f['pct'] ?>%"></span></span>
            <span class="funnel-val"><?= (int) $f['done'] ?> · <?= (int) $f['pct'] ?>%</span>
          </li>
<?php endforeach; ?>
        </ol>
      </section>
<?php endif; ?>
      <div class="teach-toolbar">
        <div class="search-wrap"><?= Icons::SEARCH ?><input type="search" class="search-input" data-roster-search placeholder="Search learners…" aria-label="Search learners" /></div>
        <div class="teach-filters" role="tablist" aria-label="Filter learners">
          <button class="chip active" data-roster-filter="all" role="tab" aria-selected="true">All <?= count($roster) ?></button>
          <button class="chip" data-roster-filter="active" role="tab" aria-selected="false">In progress <?= $active ?></button>
          <button class="chip" data-roster-filter="completed" role="tab" aria-selected="false">Completed <?= $done ?></button>
          <button class="chip" data-roster-filter="notstarted" role="tab" aria-selected="false">Not started <?= $notStarted ?></button>
        </div>
      </div>
      <div class="teach-table-wrap">
        <table class="teach-table" data-roster-table>
          <thead><tr>
            <th data-sort="name" class="th-sort">Learner</th>
            <th data-sort="pct" class="th-sort" aria-sort="descending">Progress</th>
            <th>Lessons</th>
            <th data-sort="active" class="th-sort">Last active</th>
            <th>Certificate</th>
          </tr></thead>
          <tbody>
<?php foreach ($roster as $r): ?>
            <tr data-status="<?= $statusOf($r) ?>" data-name="<?= e(strtolower($r['name'] . ' ' . $r['email'])) ?>" data-pct="<?= (int) $r['pct'] ?>" data-active="<?= $r['last_active'] ? (int) strtotime((string) $r['last_active']) : 0 ?>">
              <td><span class="r-name"><?= e($r['name']) ?></span><span class="r-email"><?= e($r['email']) ?></span></td>
              <td><div class="r-bar"><span style="width:<?= (int) $r['pct'] ?>%"></span></div><span class="r-pct"><?= (int) $r['pct'] ?>%</span></td>
              <td><?= (int) $r['done'] ?>/<?= (int) $stats['lessons'] ?></td>
              <td><?= $r['last_active'] ? e(date('M j, Y', strtotime((string) $r['last_active']))) : '—' ?></td>
              <td><?= $r['certified'] ? '<span class="r-cert">✓ Issued</span>' : '—' ?></td>
            </tr>
<?php endforeach; ?>
          </tbody>
        </table>
        <p class="teach-noresults" data-roster-empty hidden>No learners match your search.</p>
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
