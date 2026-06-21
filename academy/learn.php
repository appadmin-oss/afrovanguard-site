<?php
/**
 * academy/learn.php — lesson player. /academy/<course>/learn/<lesson?>
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$courseSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['course'] ?? '')));
$lessonSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['lesson'] ?? '')));

$ac = new AcademyRepository();
$lms = new LmsRepository();
$course = $courseSlug ? $ac->bySlug($courseSlug) : null;
if (!$course) { require_once AV_ROOT . '/lib/errors.php'; av_error_render(404); exit; }

$user = LmsAuth::user();
$ordered = $lms->orderedLessons((int) $course['id']);
if (!$ordered) { header('Location: ' . academy_url($courseSlug . '/')); exit; }
// default to first lesson
if ($lessonSlug === '') $lessonSlug = $ordered[0]['slug'];
$lesson = $lms->lesson((int) $course['id'], $lessonSlug);
if (!$lesson) { require_once AV_ROOT . '/lib/errors.php'; av_error_render(404); exit; }

$canAccess = $lms->canAccess($user, $course, $lesson);
$progress = $user ? $lms->progress((int) $user['id'], (int) $course['id']) : ['ids' => [], 'pct' => 0, 'completed' => 0, 'total' => count($ordered)];
$doneIds = $progress['ids'];

// prev / next
$pos = 0; foreach ($ordered as $i => $l) { if ($l['slug'] === $lessonSlug) { $pos = $i; break; } }
$prev = $ordered[$pos - 1] ?? null; $next = $ordered[$pos + 1] ?? null;

render_head([
    'title' => $lesson['title'] . ' — ' . $course['title'] . ' · Afrovanguard Academy',
    'desc' => $course['summary'], 'canonical' => academy_url($courseSlug . '/learn/' . $lessonSlug),
    'og_kind' => 'website', 'css' => ['/academy/academy.css'], 'body_class' => 'academy',
]);
render_nav('academy');
?>
<main id="main-content">
<?php if (!$canAccess): ?>
  <div class="container">
    <div class="gate">
      <h2>This lesson is locked</h2>
<?php if (!$user): ?>
      <p>Create a free account or sign in to start learning <strong><?= e($course['title']) ?></strong> and track your progress.</p>
      <div class="err-actions" style="justify-content:center;display:flex;gap:12px;flex-wrap:wrap">
        <button class="btn btn-primary" data-auth="register">Create free account</button>
        <button class="btn btn-outline" data-auth="login">Sign in</button>
      </div>
<?php elseif (($course['access_type'] ?? '') === 'membership'): ?>
      <p><strong><?= e($course['title']) ?></strong> is a members’ programme. Become a member to unlock every lesson.</p>
      <a class="btn btn-primary" href="<?= rtrim(SITE_URL,'/') ?>/contact/?subject=Academy+membership">Become a member</a>
<?php else: ?>
      <p><strong><?= e($course['title']) ?></strong> requires enrolment. Reach out and we’ll get you set up.</p>
      <a class="btn btn-primary" href="<?= e(academy_url($courseSlug . '/')) ?>">Back to programme</a>
<?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <div class="lesson-page">
    <aside class="lesson-side" data-course="<?= e($courseSlug) ?>">
      <div class="ls-course"><a href="<?= e(academy_url($courseSlug . '/')) ?>" style="color:inherit">← <?= e($course['title']) ?></a></div>
      <div class="cur-progress"><div class="cur-bar"><span id="sideBar" style="width:<?= (int)$progress['pct'] ?>%"></span></div><span id="sidePct"><?= (int)$progress['pct'] ?>%</span></div>
<?php foreach ($lms->curriculum((int) $course['id']) as $m): ?>
      <div class="ls-mod"><?= e($m['title']) ?></div>
<?php foreach ($m['lessons'] as $l): $done = in_array((int)$l['id'], $doneIds, true); ?>
      <a class="lp<?= $l['slug'] === $lessonSlug ? ' active' : '' ?><?= $done ? ' done' : '' ?>" href="<?= e(academy_url($courseSlug . '/learn/' . $l['slug'])) ?>"><span class="dot"></span><span><?= e($l['title']) ?></span></a>
<?php endforeach; endforeach; ?>
    </aside>
    <article class="lesson-main" data-course="<?= e($courseSlug) ?>" data-lesson="<?= e($lessonSlug) ?>">
      <div class="l-kicker"><?= e($course['title']) ?></div>
      <h1><?= e($lesson['title']) ?></h1>
<?php if (!empty($lesson['video_url'])): ?>
      <div class="lesson-video"><iframe src="<?= e($lesson['video_url']) ?>" title="<?= e($lesson['title']) ?>" loading="lazy" allowfullscreen></iframe></div>
<?php endif; ?>
      <div class="article-body"><?= $lesson['body_html'] ?></div>
<?php $quiz = $lms->quiz($lesson); $isDone = in_array((int)$lesson['id'], $doneIds, true); ?>
<?php if ($quiz): ?>
      <form class="quiz" id="quizForm" data-course="<?= e($courseSlug) ?>" data-lesson="<?= e($lessonSlug) ?>" data-pass="<?= (int)$quiz['pass'] ?>">
        <h2>Knowledge check</h2>
        <p class="quiz-intro">Answer all questions — score <?= (int)$quiz['pass'] ?>% or higher to complete this lesson.</p>
<?php foreach ($quiz['questions'] as $qi => $q): ?>
        <fieldset class="quiz-q"><legend><?= ($qi+1) ?>. <?= e($q['q']) ?></legend>
<?php foreach ($q['options'] as $oi => $opt): ?>
          <label class="quiz-opt"><input type="radio" name="q<?= $qi ?>" value="<?= $oi ?>" /> <span><?= e($opt) ?></span></label>
<?php endforeach; ?>
        </fieldset>
<?php endforeach; ?>
        <div class="quiz-actions">
          <button type="submit" class="btn btn-primary btn-sm"><?= $isDone ? 'Retake quiz' : 'Submit answers' ?></button>
          <span class="quiz-result" role="status" aria-live="polite"></span>
        </div>
      </form>
<?php endif; ?>
      <div class="lesson-nav">
        <span><?php if ($prev): ?><a class="btn btn-outline btn-sm" href="<?= e(academy_url($courseSlug . '/learn/' . $prev['slug'])) ?>">← Previous</a><?php endif; ?></span>
<?php if (!$quiz): ?>
        <button class="btn btn-primary btn-sm" id="completeBtn" data-done="<?= $isDone ? '1':'0' ?>"><?= $isDone ? '✓ Completed' : 'Mark complete' ?></button>
<?php else: ?>
        <span class="quiz-status<?= $isDone ? ' done' : '' ?>" id="quizStatus"><?= $isDone ? '✓ Completed' : 'Quiz required' ?></span>
<?php endif; ?>
        <span><?php if ($next): ?><a class="btn btn-outline btn-sm" href="<?= e(academy_url($courseSlug . '/learn/' . $next['slug'])) ?>">Next →</a><?php endif; ?></span>
      </div>
    </article>
  </div>
<?php endif; ?>
</main>
<?php
// academy.js handles auth + progress
echo '<script src="/academy/academy.js" defer></script>';
render_footer();
