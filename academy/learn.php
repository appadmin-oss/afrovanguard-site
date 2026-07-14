<?php
/**
 * academy/learn.php — lesson player. /academy/<course>/learn/<lesson?>
 *
 * Coursera-style two-pane learning view: a left curriculum sidebar (module
 * accordion with progress checkmarks + current-lesson highlight) and a focused
 * main pane (lesson content, quiz, "mark complete & continue" / prev-next).
 * Progress persists through the existing /academy/api.php endpoints.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

/** Small inline glyph for a lesson type (video · reading · quiz). */
function lp_type_icon(string $type): string
{
    switch ($type) {
        case 'video':
            return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="3"/><path d="M10 9l5 3-5 3z" fill="currentColor" stroke="none"/></svg>';
        case 'quiz':
            return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>';
        default: // reading
            return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h11l5 5v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/><path d="M14 4v5h5M8 13h8M8 17h6"/></svg>';
    }
}
function lp_type_label(string $type): string
{
    return $type === 'video' ? 'Video' : ($type === 'quiz' ? 'Quiz' : 'Reading');
}

$courseSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['course'] ?? '')));
$lessonSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['lesson'] ?? '')));

$ac = new AcademyRepository();
$lms = new LmsRepository();
$course = $courseSlug ? $ac->bySlug($courseSlug) : null;
if (!$course) { require_once AV_ROOT . '/lib/errors.php'; av_error_render(404); exit; }

$user = LmsAuth::user();
$ordered = $lms->orderedLessons((int) $course['id']);
if (!$ordered) { header('Location: ' . academy_url($courseSlug . '/')); exit; }

$progress = $user ? $lms->progress((int) $user['id'], (int) $course['id'])
                  : ['ids' => [], 'pct' => 0, 'completed' => 0, 'total' => count($ordered), 'complete' => false];
$doneIds = $progress['ids'];

// No lesson in the URL → resume at the learner's first incomplete lesson; fall
// back to the very first lesson for anonymous / brand-new / finished learners.
if ($lessonSlug === '') {
    $lessonSlug = $ordered[0]['slug'];
    if ($user && $doneIds && empty($progress['complete'])) {
        foreach ($ordered as $l) { if (!in_array((int) $l['id'], $doneIds, true)) { $lessonSlug = $l['slug']; break; } }
    }
}
$lesson = $lms->lesson((int) $course['id'], $lessonSlug);
if (!$lesson) { require_once AV_ROOT . '/lib/errors.php'; av_error_render(404); exit; }

$canAccess = $lms->canAccess($user, $course, $lesson);

// prev / next
$pos = 0; foreach ($ordered as $i => $l) { if ($l['slug'] === $lessonSlug) { $pos = $i; break; } }
$prev = $ordered[$pos - 1] ?? null; $next = $ordered[$pos + 1] ?? null;
$lessonNum = $pos + 1; $lessonTotal = count($ordered);

render_head([
    'title' => $lesson['title'] . ' — ' . $course['title'] . ' · Afrovanguard Academy',
    'desc' => $course['summary'], 'canonical' => academy_url($courseSlug . '/learn/' . $lessonSlug),
    'og_kind' => 'website', 'css' => ['/academy/academy.css'], 'body_class' => 'academy learn-mode',
]);
render_nav('academy');
?>
<main id="main-content">
<?php if (!$canAccess): ?>
  <div class="container">
    <div class="gate">
      <div class="gate-mark" aria-hidden="true">🔒</div>
      <h2>This lesson is locked</h2>
<?php if (!$user): ?>
      <p>Create a free account or sign in to start learning <strong><?= e($course['title']) ?></strong> and track your progress.</p>
      <div class="gate-actions">
        <button class="btn btn-pill" data-auth="register">Create free account</button>
        <button class="btn btn-pill-ghost" data-auth="login">Sign in</button>
      </div>
<?php elseif (($course['access_type'] ?? '') === 'restricted'): ?>
      <p><strong><?= e($course['title']) ?></strong> is a restricted programme. Access is limited to invited members or those holding a pass.</p>
<?php if (trim((string) ($course['pass_code'] ?? '')) !== ''): ?>
      <form class="enroll-form pass-form gate-actions" data-course="<?= e($courseSlug) ?>">
        <input name="code" placeholder="Enter your pass code" autocomplete="off" required />
        <button type="submit" class="btn btn-pill">Unlock →</button>
      </form>
<?php endif; ?>
      <a class="btn btn-pill-ghost" href="<?= e(academy_url($courseSlug . '/')) ?>">Course overview</a>
<?php elseif (($course['access_type'] ?? '') === 'membership'): ?>
      <p><strong><?= e($course['title']) ?></strong> is a members' programme. Become a member to unlock every lesson.</p>
<?php if (Payments::configured('paystack')): ?>
      <div class="gate-actions pay-card">
        <button class="btn btn-pill pay-btn" data-pay="membership">Become a member — ₦<?= number_format((int) AV_MEMBERSHIP_NGN) ?>/yr →</button>
        <a class="btn btn-pill-ghost" href="<?= e(academy_url($courseSlug . '/')) ?>">Course overview</a>
      </div>
      <p class="enroll-msg" hidden></p>
<?php else: ?>
      <a class="btn btn-pill" href="<?= rtrim(SITE_URL,'/') ?>/contact/?subject=Academy+membership">Become a member</a>
<?php endif; ?>
<?php else: ?>
      <p><strong><?= e($course['title']) ?></strong> requires enrolment to unlock every lesson and your certificate.</p>
<?php if (Payments::configured('paystack')): ?>
      <div class="gate-actions pay-card">
        <button class="btn btn-pill pay-btn" data-pay="course" data-course="<?= e($courseSlug) ?>">Enrol<?= (int)($course['price_ngn'] ?? 0) > 0 ? ' — ₦' . number_format((int) $course['price_ngn']) : '' ?> →</button>
        <a class="btn btn-pill-ghost" href="<?= e(academy_url($courseSlug . '/')) ?>">Course overview</a>
      </div>
      <p class="enroll-msg" hidden></p>
<?php else: ?>
      <a class="btn btn-pill" href="<?= e(academy_url($courseSlug . '/')) ?>">Back to programme</a>
<?php endif; ?>
<?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <div class="lesson-page">
    <button class="ls-toggle" id="lsToggle" aria-controls="lessonSide" aria-expanded="false">
      <span class="ls-toggle-ico" aria-hidden="true"></span><span>Course content</span>
      <span class="ls-toggle-pct"><?= (int)$progress['pct'] ?>%</span>
    </button>
    <aside class="lesson-side" id="lessonSide" data-course="<?= e($courseSlug) ?>">
      <div class="ls-head">
        <a class="ls-course" href="<?= e(academy_url($courseSlug . '/')) ?>"><span aria-hidden="true">←</span> <?= e($course['title']) ?></a>
        <div class="ls-progress">
          <div class="cur-bar"><span id="sideBar" style="width:<?= (int)$progress['pct'] ?>%"></span></div>
          <span id="sidePct" class="ls-progress-pct"><?= (int)$progress['pct'] ?>%</span>
        </div>
        <p class="ls-progress-note"><?= (int)$progress['completed'] ?> of <?= (int)$progress['total'] ?> lessons complete</p>
      </div>
      <nav class="ls-nav" aria-label="Course curriculum">
<?php foreach ($lms->curriculum((int) $course['id']) as $mi => $m):
        $hasCurrent = false; foreach ($m['lessons'] as $l) { if ($l['slug'] === $lessonSlug) { $hasCurrent = true; break; } }
        $mDone = 0; foreach ($m['lessons'] as $l) { if (in_array((int)$l['id'], $doneIds, true)) $mDone++; }
        $open = $hasCurrent || $mi === 0;
?>
        <div class="ls-mod-group<?= $open ? ' is-open' : '' ?>">
          <button type="button" class="ls-mod" aria-expanded="<?= $open ? 'true' : 'false' ?>">
            <span class="ls-mod-toggle" aria-hidden="true"></span>
            <span class="ls-mod-title"><?= e($m['title']) ?></span>
            <span class="ls-mod-count"><?= $mDone ?>/<?= count($m['lessons']) ?></span>
          </button>
          <div class="ls-mod-body">
<?php foreach ($m['lessons'] as $l): $done = in_array((int)$l['id'], $doneIds, true); $cur = $l['slug'] === $lessonSlug; $lt = $l['type'] ?? 'reading'; ?>
            <a class="lp<?= $cur ? ' active' : '' ?><?= $done ? ' done' : '' ?>" data-type="<?= e($lt) ?>" href="<?= e(academy_url($courseSlug . '/learn/' . $l['slug'])) ?>"<?= $cur ? ' aria-current="true"' : '' ?>>
              <span class="dot" aria-hidden="true"><?= $done ? '✓' : '' ?></span>
              <span class="lp-type" aria-hidden="true" title="<?= e(lp_type_label($lt)) ?>"><?= lp_type_icon($lt) ?></span>
              <span class="lp-title"><?= e($l['title']) ?></span>
<?php if ((int)$l['duration_min']): ?>              <span class="lp-min"><?= (int)$l['duration_min'] ?>m</span><?php endif; ?>
            </a>
<?php endforeach; ?>
          </div>
        </div>
<?php endforeach; ?>
      </nav>
    </aside>
    <article class="lesson-main" data-course="<?= e($courseSlug) ?>" data-lesson="<?= e($lessonSlug) ?>">
      <div class="lesson-read-progress" aria-hidden="true"><span id="readBar"></span></div>
<?php $lType = LmsRepository::lessonType($lesson); ?>
      <div class="lesson-main-inner">
        <div class="l-kicker">
          <span class="l-type-badge" data-type="<?= e($lType) ?>"><?= lp_type_icon($lType) ?> <?= e(lp_type_label($lType)) ?></span>
          <span><?= e($course['title']) ?></span><span class="l-kicker-pos">Lesson <?= $lessonNum ?> of <?= $lessonTotal ?></span>
        </div>
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
            <button type="submit" class="btn btn-pill btn-sm"><?= $isDone ? 'Retake quiz' : 'Submit answers' ?></button>
            <span class="quiz-result" role="status" aria-live="polite"></span>
          </div>
        </form>
<?php endif; ?>

<?php $savedNote = $user ? $lms->getNote((int) $user['id'], (int) $lesson['id']) : ''; $notesRemote = $user ? '1' : '0'; ?>
        <section class="l-notes" data-notes-key="av.notes.<?= e($courseSlug) ?>.<?= e($lessonSlug) ?>" data-notes-course="<?= e($courseSlug) ?>" data-notes-lesson="<?= e($lessonSlug) ?>" data-notes-remote="<?= $notesRemote ?>" data-lesson-title="<?= e($lesson['title']) ?>">
          <div class="l-notes-head">
            <h2><span class="l-notes-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></span> My notes</h2>
            <span class="l-notes-status" data-notes-status role="status" aria-live="polite"></span>
            <button type="button" class="l-notes-dl" data-notes-download>Download all notes</button>
          </div>
          <textarea class="l-notes-area" data-notes-area rows="4" placeholder="<?= $user ? 'Capture the key ideas, questions, and timestamps from this lesson. Saved to your account and synced across your devices.' : 'Capture the key ideas from this lesson. Saved on this device — sign in to sync your notes across devices.' ?>"><?= e($savedNote) ?></textarea>
        </section>
<?php if ($user): ?>
        <div class="cert-banner" id="courseDone"<?= !empty($progress['complete']) ? '' : ' hidden' ?>>
          <span style="font-size:22px">🎓</span>
          <span style="flex:1">You've completed <strong><?= e($course['title']) ?></strong> — your certificate is ready.</span>
          <a class="btn btn-pill-gold btn-sm" href="<?= e(academy_url($courseSlug . '/certificate')) ?>" target="_blank" rel="noopener">Get your certificate →</a>
        </div>
<?php endif; ?>
<?php if ($next): $nt = $next['type'] ?? 'reading'; ?>
        <div class="up-next" id="upNext" hidden data-next-url="<?= e(academy_url($courseSlug . '/learn/' . $next['slug'])) ?>">
          <div class="up-next-body">
            <p class="up-next-kicker">Up next · Lesson <?= $lessonNum + 1 ?> of <?= $lessonTotal ?></p>
            <h3 class="up-next-title"><?= e($next['title']) ?></h3>
            <p class="up-next-meta"><span class="up-next-type" data-type="<?= e($nt) ?>"><?= lp_type_icon($nt) ?> <?= e(lp_type_label($nt)) ?></span><?php if ((int)($next['duration_min'] ?? 0)): ?> · <?= (int)$next['duration_min'] ?> min<?php endif; ?></p>
          </div>
          <div class="up-next-actions">
            <button type="button" class="btn btn-pill btn-sm" data-upnext-go>Continue <span class="up-next-count" data-upnext-count></span></button>
            <button type="button" class="btn btn-pill-ghost btn-sm" data-upnext-cancel>Stay here</button>
          </div>
        </div>
<?php endif; ?>
        <div class="lesson-nav">
          <span class="lesson-nav-prev"><?php if ($prev): ?><a class="btn btn-pill-ghost btn-sm" href="<?= e(academy_url($courseSlug . '/learn/' . $prev['slug'])) ?>">← Previous</a><?php endif; ?></span>
<?php if (!$quiz): ?>
          <button class="btn btn-pill btn-sm" id="completeBtn" data-done="<?= $isDone ? '1':'0' ?>"<?= $next ? ' data-next="' . e(academy_url($courseSlug . '/learn/' . $next['slug'])) . '"' : '' ?>><?= $isDone ? '✓ Completed' : 'Mark complete' . ($next ? ' & continue' : '') ?></button>
<?php else: ?>
          <span class="quiz-status<?= $isDone ? ' done' : '' ?>" id="quizStatus"><?= $isDone ? '✓ Completed' : 'Quiz required' ?></span>
<?php endif; ?>
          <span class="lesson-nav-next"><?php if ($next): ?><a class="btn btn-pill-ghost btn-sm" href="<?= e(academy_url($courseSlug . '/learn/' . $next['slug'])) ?>">Next →</a><?php endif; ?></span>
        </div>
        <p class="l-kbd-hint" aria-hidden="true">Shortcuts <kbd>N</kbd> next · <kbd>P</kbd> previous · <kbd>K</kbd> mark complete · <kbd>S</kbd> contents</p>
      </div>
    </article>
  </div>
<?php endif; ?>
</main>
<?php
// academy.js handles auth + progress
echo '<script src="/academy/academy.js" defer></script>';
render_footer();
