<?php
/**
 * academy/grades.php — a learner's grades for one course.
 *   /academy/<course>/grades/            every graded item, status, score, the grade so far
 *   /academy/<course>/grades/?item=N     one item: instructions, submit, feedback, peer
 *                                        reviews to do, live session booking
 *
 * What a learner sees is only what has been RELEASED (lib/Grading::result):
 * a mark a team member has typed is invisible until the team releases it.
 * Forms are plain POSTs with a CSRF field, handled by avgr_learner_action().
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once __DIR__ . '/_grading_actions.php';
require_once __DIR__ . '/_grades_view.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['course'] ?? '')));
$course = $slug ? (new AcademyRepository())->bySlug($slug) : null;
if (!$course) { require_once AV_ROOT . '/lib/errors.php'; av_error_render(404); return; }
$user = LmsAuth::user();
$uid = $user ? (int) $user['id'] : 0;
$cid = (int) $course['id'];
$base = academy_url($course['slug'] . '/grades/');

if ($user && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_same_origin();
    $r = avgr_learner_action($course, $uid, $_POST, $_FILES);
    avgr_flash_set($r);
    if (!headers_sent()) { header('Location: ' . $base . ($r['to'] !== '' ? '?' . $r['to'] : ''), true, 303); exit; }
    parse_str((string) $r['to'], $q); $_GET = $q + $_GET;
}

render_head([
    'title' => 'Grades — ' . $course['title'] . ' · Afrovanguard Academy',
    'desc' => 'Your graded work, scores and feedback.',
    'canonical' => $base, 'og_kind' => 'website', 'robots' => 'noindex,nofollow',
    'css' => ['/academy/academy.css'], 'body_class' => 'academy',
]);
render_nav('academy');
echo '<main id="main" tabindex="-1"><div class="container teach grd">';
echo '<nav class="breadcrumb"><a href="' . e(academy_url($course['slug'] . '/')) . '">' . e((string) $course['title']) . '</a><span class="sep">/</span><span>Grades</span></nav>';

if (!$user) {
    echo '<div class="gate"><h2>Your grades</h2><p>Sign in to see your graded work for this course.</p><a class="btn btn-primary" href="/login?next=' . e(rawurlencode('/academy/' . $course['slug'] . '/grades/')) . '" data-auth="login">Sign in</a></div>';
} elseif (!Grading::hasGradedItems($cid)) {
    echo '<h1 class="teach-h1">Grades</h1><p class="teach-empty">This course has no graded work. Complete every lesson to earn your certificate.</p>';
} else {
    echo avgr_flash_html(avgr_flash_take());
    $itemId = (int) ($_GET['item'] ?? 0);
    $item = $itemId ? Grading::item($itemId) : null;
    if ($item && ((int) $item['course_id'] !== $cid || $item['status'] !== 'published')) $item = null;
    if ($item) avgr_learner_item($course, $item, $uid, $base);
    else avgr_learner_summary($course, $uid, $base);
}
echo '</div></main>';
echo '<script src="/academy/academy.js" defer></script>';
render_footer();
