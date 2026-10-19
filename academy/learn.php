<?php
/**
 * academy/learn.php — lesson player. /academy/<course>/learn/<lesson?>
 *
 * Rebuilt to the design "Afrovanguard Lesson": a focused player with its own
 * slim header (no marketing nav), the course contents on the left (a drawer on
 * phone and tablet), the lesson in the middle, notes on the right, and a
 * sticky bar to move on. Data as v1: AcademyRepository, LmsRepository
 * (ordered lessons, curriculum, progress, access, quiz, notes), LmsAuth.
 * Progress, quizzes and notes go through academy/api.php; a locked lesson
 * offers the same sign-in / membership / course checkout / pass code as v1.
 * Styles: academy/avle.css · behaviour: academy/avac.js (gate) + avle.js.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once __DIR__ . '/_avac.php';

/** Lesson type → label + glyph (design: ▤ reading, ▶ video, ? quiz). */
function avle_type(string $type): array
{
    return ['video' => ['Video', '▶'], 'quiz' => ['Quiz', '?']][$type] ?? ['Reading', '▤'];
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

// No lesson in the URL → resume at the first incomplete lesson (v1 rule).
if ($lessonSlug === '') {
    $lessonSlug = $ordered[0]['slug'];
    if ($user && $doneIds && empty($progress['complete'])) {
        foreach ($ordered as $l) { if (!in_array((int) $l['id'], $doneIds, true)) { $lessonSlug = $l['slug']; break; } }
    }
}
$lesson = $lms->lesson((int) $course['id'], $lessonSlug);
if (!$lesson) { require_once AV_ROOT . '/lib/errors.php'; av_error_render(404); exit; }

$canAccess = $lms->canAccess($user, $course, $lesson);
$access = (string) ($course['access_type'] ?? 'open');
$curriculum = $lms->curriculum((int) $course['id']);

$pos = 0; foreach ($ordered as $i => $l) { if ($l['slug'] === $lessonSlug) { $pos = $i; break; } }
$prev = $ordered[$pos - 1] ?? null; $next = $ordered[$pos + 1] ?? null;
$lessonNum = $pos + 1; $lessonTotal = count($ordered);
$modTitle = '';
foreach ($curriculum as $m) { foreach ($m['lessons'] as $l) { if ($l['slug'] === $lessonSlug) { $modTitle = (string) $m['title']; break 2; } } }

$lType = LmsRepository::lessonType($lesson);
[$typeLabel, $typeGlyph] = avle_type($lType);
$mins = (int) ($lesson['duration_min'] ?? 0);
$quiz = $canAccess ? $lms->quiz($lesson) : null;
$isDone = in_array((int) $lesson['id'], $doneIds, true);
$learnUrl = static fn(string $s): string => academy_url($courseSlug . '/learn/' . $s);
$courseUrl = academy_url($courseSlug . '/');
$nextUrl = $next ? $learnUrl((string) $next['slug']) : '';
$pct = (int) $progress['pct'];
$savedNote = ($user && $canAccess) ? $lms->getNote((int) $user['id'], (int) $lesson['id']) : '';

avac_head([
    'title' => $lesson['title'] . ' — ' . $course['title'] . ' · Afrovanguard Academy',
    'desc' => (string) $course['summary'],
    'canonical' => $learnUrl($lessonSlug),
    'css' => ['/academy/avle.css'],
    'js'  => ['/academy/avac.js', '/academy/avle.js'],
]);
?>
<body class="avle" data-course="<?= e($courseSlug) ?>">
<a class="avle-skip" href="#main">Skip to lesson</a>
<header class="avle-top">
  <div class="avle-top-l">
    <button type="button" class="avle-icon" data-avle-side-toggle aria-controls="avle-side" aria-expanded="true" aria-label="Hide contents"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
    <a class="avle-brand" href="/academy/"><span class="avle-diamond" aria-hidden="true"></span><span>Academy</span></a>
    <span class="avle-slash" aria-hidden="true">/</span>
    <a class="avle-course" href="<?= e($courseUrl) ?>"><?= e((string) $course['title']) ?></a>
  </div>
  <div class="avle-top-r">
    <span class="avle-ring" style="--p:<?= $pct ?>" aria-hidden="true"></span><span class="avle-ring-n av-num" data-avle-count><?= (int) $progress['completed'] ?>/<?= (int) $progress['total'] ?></span><span class="av-sr" data-avle-count-sr><?= (int) $progress['completed'] ?> of <?= (int) $progress['total'] ?> lessons complete</span>
<?php if ($canAccess): ?>    <button type="button" class="avle-btn avle-btn--line avle-notes-btn" data-avle-notes-toggle aria-controls="avle-notes" aria-expanded="false">Notes</button>
<?php endif; ?>
    <a class="avle-btn" href="<?= e($courseUrl) ?>">Exit</a>
  </div>
  <div class="avle-read" aria-hidden="true"><span data-avle-readbar></span></div>
</header>

<div class="avle-shell">
<aside class="avle-side" id="avle-side" aria-label="Course content" data-avle-side>
  <div class="avle-side-head">
    <div class="avle-side-k">Course content</div>
    <div class="avle-side-t"><?= e((string) $course['title']) ?></div>
    <div class="avle-bar"><span data-avle-bar style="width:<?= $pct ?>%"></span></div>
    <div class="avle-side-n av-num" data-avle-note><?= $pct ?>% · <?= (int) $progress['completed'] ?> of <?= (int) $progress['total'] ?> lessons complete</div>
    <button type="button" class="avle-icon avle-side-x" data-avle-side-close aria-label="Close contents"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
  </div>
  <nav aria-label="Lessons">
<?php foreach ($curriculum as $mi => $m):
    $hasCur = false; $mDone = 0;
    foreach ($m['lessons'] as $l) { if ($l['slug'] === $lessonSlug) $hasCur = true; if (in_array((int) $l['id'], $doneIds, true)) $mDone++; }
    $open = $hasCur || $mi === 0; ?>
    <div class="avle-mod">
      <button type="button" class="avle-mod-h" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="avle-mod-<?= (int) $m['id'] ?>" data-avle-mod>
        <span><span class="avle-mod-t"><?= e((string) $m['title']) ?></span><span class="avle-mod-n av-num" data-avle-modcount><?= $mDone ?>/<?= count($m['lessons']) ?> complete</span></span>
        <span class="avle-chev" aria-hidden="true">▼</span>
      </button>
      <ul class="avle-items" id="avle-mod-<?= (int) $m['id'] ?>"<?= $open ? '' : ' hidden' ?>>
<?php foreach ($m['lessons'] as $l):
        $done = in_array((int) $l['id'], $doneIds, true); $cur = $l['slug'] === $lessonSlug;
        [$tl, $tg] = avle_type((string) ($l['type'] ?? 'reading')); ?>
        <li><a class="avle-item<?= $cur ? ' is-cur' : '' ?><?= $done ? ' is-done' : '' ?>" href="<?= e($learnUrl((string) $l['slug'])) ?>"<?= $cur ? ' aria-current="page" data-avle-cur' : '' ?>>
          <span class="avle-ck" aria-hidden="true"><?= $done ? '✓' : '' ?></span>
          <span><span class="avle-item-t"><?= e((string) $l['title']) ?></span><span class="avle-item-m"><span aria-hidden="true"><?= $tg ?></span><?= e($tl) ?><?= (int) $l['duration_min'] ? ' · ' . (int) $l['duration_min'] . ' min' : '' ?><?php if (!empty($l['is_preview'])): ?> · Preview<?php endif; ?></span></span>
          <span class="av-sr" data-avle-done-sr><?= $done ? ' (completed)' : '' ?></span>
        </a></li>
<?php endforeach; ?>
      </ul>
    </div>
<?php endforeach; ?>
  </nav>
</aside>
<div class="avle-scrim" data-avle-scrim hidden></div>

<main class="avle-main" id="main" tabindex="-1" data-course="<?= e($courseSlug) ?>" data-lesson="<?= e($lessonSlug) ?>">
<?php if (!$canAccess): ?>
  <div class="avle-col">
    <div class="avle-meta"><?php if ($modTitle !== ''): ?><span><?= e($modTitle) ?></span><span aria-hidden="true">·</span><?php endif; ?><span class="av-num">Lesson <?= $lessonNum ?> of <?= $lessonTotal ?></span></div>
    <h1 class="avle-h1"><?= e((string) $lesson['title']) ?></h1>
    <section class="avle-gate" aria-labelledby="avle-gate-h" data-avac-paycard>
      <div class="avle-gate-k">Locked</div>
      <h2 id="avle-gate-h">This lesson is locked</h2>
<?php if (!$user): ?>
      <p>Create a free account or sign in to start learning <strong><?= e((string) $course['title']) ?></strong> and track your progress.</p>
      <div class="avle-gate-btns">
        <a class="avle-btn avle-btn--lg" href="/login?mode=register" data-avac-auth="register">Create free account</a>
        <a class="avle-btn avle-btn--line avle-btn--lg" href="/login" data-avac-auth="login">Sign in</a>
      </div>
<?php elseif ($access === 'restricted'): ?>
      <p><strong><?= e((string) $course['title']) ?></strong> is a restricted programme. Access is limited to invited members or those holding a pass.</p>
<?php if (trim((string) ($course['pass_code'] ?? '')) !== ''): ?>
      <form class="avle-pass" data-avac-pass="<?= e($courseSlug) ?>">
        <label><span class="av-sr">Pass code</span><input name="code" placeholder="Enter your pass code" autocomplete="off" required></label>
        <button type="submit" class="avle-btn avle-btn--lg">Unlock →</button>
      </form>
<?php endif; ?>
      <div class="avle-gate-btns"><a class="avle-btn avle-btn--line avle-btn--lg" href="<?= e($courseUrl) ?>">Course overview</a></div>
<?php elseif ($access === 'membership'): ?>
      <p><strong><?= e((string) $course['title']) ?></strong> is a members’ programme. Become a member to unlock every lesson.</p>
<?php if (Payments::canCollect()): ?>
      <div class="avle-gate-btns">
        <button type="button" class="avle-btn avle-btn--lg" data-avac-pay="membership">Become a member — ₦<?= e(number_format((int) AV_MEMBERSHIP_NGN)) ?>/yr →</button>
        <a class="avle-btn avle-btn--line avle-btn--lg" href="<?= e($courseUrl) ?>">Course overview</a>
      </div>
<?php else: ?>
      <div class="avle-gate-btns"><a class="avle-btn avle-btn--lg" href="<?= e(rtrim(SITE_URL, '/')) ?>/contact/?subject=Academy+membership">Become a member</a></div>
<?php endif; ?>
<?php else: ?>
      <p><strong><?= e((string) $course['title']) ?></strong> requires enrolment to unlock every lesson and your certificate.</p>
<?php if (Payments::canCollect()): ?>
      <div class="avle-gate-btns">
        <button type="button" class="avle-btn avle-btn--lg" data-avac-pay="course" data-course="<?= e($courseSlug) ?>">Enrol<?= (int) ($course['price_ngn'] ?? 0) > 0 ? ' — ₦' . e(number_format((int) $course['price_ngn'])) : '' ?> →</button>
        <a class="avle-btn avle-btn--line avle-btn--lg" href="<?= e($courseUrl) ?>">Course overview</a>
      </div>
<?php else: ?>
      <div class="avle-gate-btns"><a class="avle-btn avle-btn--lg" href="<?= e($courseUrl) ?>">Back to programme</a></div>
<?php endif; ?>
<?php endif; ?>
      <p class="avle-msg" data-avac-msg role="status" aria-live="polite" hidden></p>
    </section>
  </div>
<?php else: ?>
<?php if ($lType === 'video'): ?>
  <div class="avle-stage">
    <div class="avle-video"><iframe src="<?= e((string) $lesson['video_url']) ?>" title="<?= e((string) $lesson['title']) ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen></iframe></div>
  </div>
<?php endif; ?>
  <div class="avle-col<?= $lType === 'video' ? ' avle-col--video' : '' ?>">
<?php if ($lType === 'video'): ?>
    <h1 class="avle-h1 avle-h1--video"><?= e((string) $lesson['title']) ?></h1>
    <div class="avle-byline">
      <div class="avle-by"><span class="avle-by-mark" aria-hidden="true"><span></span></span><div><div class="avle-by-n">Afrovanguard Academy</div><div class="avle-by-s av-num"><?= $modTitle !== '' ? e($modTitle) . ' · ' : '' ?>Lesson <?= $lessonNum ?> of <?= $lessonTotal ?></div></div></div>
      <div class="avle-pills">
        <button type="button" class="avle-pill" data-avle-notes-toggle aria-controls="avle-notes" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>Take notes</button>
        <button type="button" class="avle-pill" data-avle-share><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 5h5v5M19 5l-8 8M18 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h4" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg><span data-avle-share-label>Share</span></button>
      </div>
    </div>
    <div class="avle-about">
      <div class="avle-about-k av-num"><?= $mins ? $mins . ' min · ' : '' ?>Video lesson</div>
      <div class="avle-body avle-prose-sm"><?= $lesson['body_html'] ?></div>
    </div>
<?php else: ?>
    <div class="avle-meta"><?php if ($modTitle !== ''): ?><span><?= e($modTitle) ?></span><span aria-hidden="true">·</span><?php endif; ?><span class="av-num">Lesson <?= $lessonNum ?> of <?= $lessonTotal ?></span><span class="avle-type"><span aria-hidden="true"><?= $typeGlyph ?></span><?= e($typeLabel) ?><?= $mins ? ' · ' . $mins . ' min' : '' ?></span></div>
    <h1 class="avle-h1"><?= e((string) $lesson['title']) ?></h1>
    <div class="avle-body"><?= $lesson['body_html'] ?></div>
<?php endif; ?>

<?php if ($quiz): $qn = count($quiz['questions']); ?>
    <form class="avle-quiz" data-avle-quiz data-course="<?= e($courseSlug) ?>" data-lesson="<?= e($lessonSlug) ?>">
      <p class="avle-quiz-intro">Answer every question — score <?= (int) $quiz['pass'] ?>% or higher to complete this lesson.</p>
<?php foreach ($quiz['questions'] as $qi => $q): ?>
      <fieldset class="avle-q">
        <legend><span class="avle-q-k av-num">Question <?= $qi + 1 ?> of <?= $qn ?></span><span class="avle-q-t"><?= e((string) $q['q']) ?></span></legend>
<?php foreach ($q['options'] as $oi => $opt): ?>
        <label class="avle-opt"><input type="radio" name="q<?= $qi ?>" value="<?= $oi ?>"><span class="avle-opt-k" aria-hidden="true"><?= e(chr(65 + ($oi % 26))) ?></span><span><?= e((string) $opt) ?></span></label>
<?php endforeach; ?>
      </fieldset>
<?php endforeach; ?>
      <div class="avle-quiz-foot">
        <button type="submit" class="avle-btn avle-btn--lg"><?= $isDone ? 'Retake quiz' : 'Check answers' ?></button>
        <p class="avle-quiz-out" data-avle-quiz-out role="status" aria-live="polite"></p>
      </div>
    </form>
<?php endif; ?>

<?php if ($user): ?>
    <div class="avle-done" data-avle-done<?= !empty($progress['complete']) ? '' : ' hidden' ?>>
      <span>You’ve completed <strong><?= e((string) $course['title']) ?></strong> — your certificate is ready.</span>
      <a class="avle-btn avle-btn--gold" href="<?= e(academy_url($courseSlug . '/certificate')) ?>" target="_blank" rel="noopener">Get your certificate →<span class="av-sr"> (opens in a new tab)</span></a>
    </div>
<?php endif; ?>
<?php if ($next): [$ntl, $ntg] = avle_type((string) ($next['type'] ?? 'reading')); ?>
    <section class="avle-upnext" data-avle-upnext data-next-url="<?= e($nextUrl) ?>" aria-labelledby="avle-upnext-t" hidden>
      <div class="avle-upnext-k" data-avle-upnext-k>Up next</div>
      <h2 class="avle-upnext-t" id="avle-upnext-t"><?= e((string) $next['title']) ?></h2>
      <div class="avle-upnext-m"><span aria-hidden="true"><?= $ntg ?></span> <?= e($ntl) ?><?= (int) ($next['duration_min'] ?? 0) ? ' · ' . (int) $next['duration_min'] . ' min' : '' ?> · Lesson <?= $lessonNum + 1 ?> of <?= $lessonTotal ?></div>
      <div class="avle-upnext-b">
        <button type="button" class="avle-upnext-alt" data-avle-upnext-cancel>Cancel</button>
        <a class="avle-upnext-go" href="<?= e($nextUrl) ?>">Next lesson</a>
      </div>
    </section>
<?php endif; ?>
  </div>

  <div class="avle-bar-wrap">
    <div class="avle-barline<?= $lType === 'video' ? ' avle-barline--video' : '' ?>">
<?php if ($prev): ?>      <a class="avle-btn avle-btn--line avle-btn--lg" href="<?= e($learnUrl((string) $prev['slug'])) ?>" data-avle-prev>← Previous</a>
<?php endif; ?>
      <div class="avle-bar-r">
        <span class="avle-keys" aria-hidden="true">N next · P prev<?= $quiz ? '' : ' · K complete' ?> · S contents</span>
<?php if (!$quiz): ?>
        <button type="button" class="avle-btn avle-btn--lg" data-avle-complete data-done="<?= $isDone ? '1' : '0' ?>"<?= $next ? ' data-next="' . e($nextUrl) . '"' : '' ?>><?= $isDone ? 'Completed ✓' : 'Mark complete' . ($next ? ' & continue' : '') ?></button>
<?php else: ?>
        <span class="avle-qstatus<?= $isDone ? ' is-done' : '' ?>" data-avle-qstatus><?= $isDone ? 'Completed ✓' : 'Quiz required' ?></span>
<?php endif; ?>
<?php if ($next): ?>        <a class="avle-btn avle-btn--line avle-btn--lg" href="<?= e($nextUrl) ?>" data-avle-next>Next →</a>
<?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
</main>

<?php if ($canAccess): ?>
<aside class="avle-notes" id="avle-notes" aria-labelledby="avle-notes-h" data-avle-notes hidden
       data-key="av.notes.<?= e($courseSlug) ?>.<?= e($lessonSlug) ?>" data-remote="<?= $user ? '1' : '0' ?>" data-lesson-title="<?= e((string) $lesson['title']) ?>">
  <div class="avle-notes-h"><h2 id="avle-notes-h">My notes</h2><button type="button" class="avle-icon" data-avle-notes-close aria-label="Close notes"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button></div>
  <p class="avle-notes-s">Saved per lesson · <?= e((string) $lesson['title']) ?><span data-avle-notes-status role="status" aria-live="polite"></span></p>
  <label class="av-sr" for="avle-notes-area">Notes for this lesson</label>
  <textarea id="avle-notes-area" data-avle-notes-area placeholder="<?= $user ? 'Write what you’re learning… Saved to your account and synced across your devices.' : 'Write what you’re learning… Saved on this device — sign in to sync your notes.' ?>"><?= e($savedNote) ?></textarea>
  <button type="button" class="avle-btn avle-btn--line avle-btn--lg" data-avle-notes-dl>Download all notes</button>
</aside>
<?php endif; ?>
</div>
<div class="avle-toast" data-avac-toast role="status" aria-live="polite" hidden></div>
</body>
</html>
