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

/** Item type → label + glyph (▤ reading, ▶ video, ? quiz, ✎ assignment, ❝ discussion). */
if (!function_exists('avle_type')) {
function avle_type(string $type): array
{
    return ['video' => ['Video', '▶'], 'quiz' => ['Graded quiz', '?'], 'practice' => ['Practice quiz', '?'],
            'assignment' => ['Assignment', '✎'], 'discussion' => ['Discussion', '❝']][$type] ?? ['Reading', '▤'];
}
}

$courseSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['course'] ?? '')));
$lessonSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['lesson'] ?? '')));

$ac = new AcademyRepository();
$lms = new LmsRepository();
$cw = new LmsCourseware(null, $lms);
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
/* The study coach and the lesson's key points. Both are drawn only when
   there is something behind them: a control that cannot act has no business
   on the screen, and a summary nobody has generated is not a heading with
   nothing under it. */
$coachOn = $canAccess && $user && LmsCoach::available();
$summary = $canAccess ? LmsCoach::points(LmsCoach::summary($lesson)['text'] ?? '') : [];
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
$quizStats = ($quiz && $user) ? $lms->quizStats((int) $user['id'], (int) $lesson['id']) : ['used' => 0, 'best' => null, 'passed' => false];
$attemptsLeft = ($quiz && $quiz['attempts'] > 0) ? max(0, $quiz['attempts'] - $quizStats['used']) : null;
$submission = ($lType === 'assignment' && $user && $canAccess) ? $cw->submission((int) $user['id'], (int) $lesson['id']) : null;
$asgPass = $lType === 'assignment' ? LmsCourseware::assignmentPass($lesson) : 0;
$prompt = ($lType === 'discussion' && $canAccess) ? $cw->promptThread($lesson) : null;
$replies = $prompt ? $cw->posts((int) $prompt['id']) : [];
/* Items completed by doing something, not by ticking a box. */
$selfComplete = !$quiz && !in_array($lType, ['assignment', 'discussion'], true);
$gradesUrl = academy_url($courseSlug . '/grades');
$forumUrl = academy_url($courseSlug . '/discussions');

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
<?php if ($coachOn): ?>    <button type="button" class="avle-btn avle-btn--line avle-coach-btn" data-avle-coach-toggle aria-controls="avle-coach" aria-expanded="false">Ask Coach</button>
<?php endif; ?>
<?php if ($user && $canAccess): ?>    <a class="avle-btn avle-btn--line avle-hide-sm" href="<?= e($gradesUrl) ?>">Grades</a>
    <a class="avle-btn avle-btn--line avle-hide-sm" href="<?= e($forumUrl) ?>">Discussions</a>
<?php endif; ?>
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
<?php if (!empty($course['sequential']) && $user && $lms->canAccess($user, $course, ['is_preview' => 0]) && !$lms->sequenceOpen($user, (int) $course['id'], (int) $lesson['id'])): $nx = $lms->nextLesson((int) $course['id'], $doneIds); ?>
      <p>This course opens items in order. Finish the one before to unlock this.</p>
<?php if ($nx): ?>      <div class="avle-gate-btns"><a class="avle-btn avle-btn--lg" href="<?= e($learnUrl((string) $nx['slug'])) ?>">Go to your next item →</a></div>
<?php endif; ?>
<?php elseif (!$user): ?>
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
<?php if ($lType === 'video'): $vid = Embeds::lessonVideo((string) $lesson['video_url']); $caps = Embeds::captionsFile((string) ($lesson['captions_url'] ?? '')); ?>
  <div class="avle-stage">
    <div class="avle-video">
<?php if (($vid['kind'] ?? '') === 'file'): ?>
      <video data-avle-video src="<?= e($vid['src']) ?>" controls preload="metadata" playsinline<?= $caps && !str_starts_with($caps, '/') ? ' crossorigin="anonymous"' : '' ?> data-key="av.vpos.<?= e($courseSlug) ?>.<?= e($lessonSlug) ?>"<?= $user && !$isDone ? ' data-autocomplete="1"' : '' ?>>
<?php if ($caps): ?>        <track kind="captions" src="<?= e($caps) ?>" srclang="en" label="English" default>
<?php endif; ?>
        <a href="<?= e($vid['src']) ?>">Download the video</a>
      </video>
<?php elseif ($vid): ?>
      <iframe src="<?= e($vid['src']) ?>" title="<?= e((string) $lesson['title']) ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen></iframe>
<?php else: ?>
      <p class="avle-video-off">This video can’t be shown here.</p>
<?php endif; ?>
    </div>
<?php if (($vid['kind'] ?? '') === 'file'): ?>
    <div class="avle-vbar" role="group" aria-label="Playback speed">
      <span>Speed</span>
<?php foreach (['0.75', '1', '1.25', '1.5', '2'] as $sp): ?>      <button type="button" class="avle-speed" data-avle-speed="<?= $sp ?>" aria-pressed="<?= $sp === '1' ? 'true' : 'false' ?>"><?= $sp ?>×</button>
<?php endforeach; ?>
    </div>
<?php endif; ?>
  </div>
<?php endif; ?>
  <div class="avle-col<?= $lType === 'video' ? ' avle-col--video' : '' ?>">
<?php if ($lType === 'video'): ?>
    <h1 class="avle-h1 avle-h1--video"><?= e((string) $lesson['title']) ?></h1>
    <div class="avle-byline">
      <div class="avle-by"><span class="avle-by-mark" aria-hidden="true"><span></span></span><div><div class="avle-by-n">Afrovanguard Academy</div><div class="avle-by-s av-num"><?= $modTitle !== '' ? e($modTitle) . ' · ' : '' ?>Lesson <?= $lessonNum ?> of <?= $lessonTotal ?></div></div></div>
      <div class="avle-pills">
<?php if ($coachOn): ?>        <button type="button" class="avle-pill" data-avle-coach-toggle aria-controls="avle-coach" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4L3 21l1.1-4.6A8.4 8.4 0 1 1 21 11.5z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/></svg>Ask Coach</button>
<?php endif; ?>        <button type="button" class="avle-pill" data-avle-notes-toggle aria-controls="avle-notes" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>Take notes</button>
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
<?php if ($summary): ?>
    <?php /* What the reader will be able to do, written from the lesson and
             cached against a hash of it. It is a way in, never a
             replacement, and the label says which it is. */ ?>
    <aside class="avle-brief" aria-labelledby="avle-brief-h">
      <h2 class="avle-brief-h" id="avle-brief-h">In short<span>written by AI from this lesson</span></h2>
      <ul>
<?php foreach ($summary as $pt): ?>        <li><?= e($pt) ?></li>
<?php endforeach; ?>
      </ul>
    </aside>
<?php endif; ?>
    <div class="avle-body"><?= $lesson['body_html'] ?></div>
<?php endif; ?>

<?php $transcript = trim((string) ($lesson['transcript'] ?? '')); $hasCues = $lType === 'video' && !empty($caps) && ($vid['kind'] ?? '') === 'file'; ?>
<?php if ($transcript !== '' || $hasCues): ?>
    <details class="avle-trans" data-avle-trans<?= $hasCues ? ' data-cues="1"' : '' ?>>
      <summary>Transcript</summary>
      <ol class="avle-cues" data-avle-cues hidden></ol>
<?php if ($transcript !== ''): ?>      <div class="avle-trans-text" data-avle-trans-text><?= nl2br(e($transcript)) ?></div>
<?php endif; ?>
    </details>
<?php endif; ?>
<?php if ($lType === 'video' && $transcript === '' && empty($caps)): /* No way to follow this video without sound: say so, and offer the way to get one. */ ?>
    <p class="avle-noaccess" role="note">This video has no captions or transcript yet. If you need one to follow it, <a href="/contact.html?purpose=general&amp;subject=<?= rawurlencode('Captions for: ' . (string) $lesson['title']) ?>&amp;message=<?= rawurlencode('Please add captions or a transcript to the lesson “' . (string) $lesson['title'] . '” (' . (string) $courseSlug . ').') ?>#contact-form">ask the team</a> and we will add it.</p>
<?php endif; ?>
<?php if ($resources = LmsRepository::resources($lesson)): ?>
    <section class="avle-res" aria-labelledby="avle-res-h">
      <h2 class="avle-h2" id="avle-res-h">Resources</h2>
      <ul>
<?php foreach ($resources as $rs): $rx = strtoupper((string) pathinfo((string) parse_url($rs['url'], PHP_URL_PATH), PATHINFO_EXTENSION)); ?>
        <li><a href="<?= e($rs['url']) ?>" target="_blank" rel="noopener"><span class="avle-res-k" aria-hidden="true"><?= e(in_array($rx, ['PDF', 'DOC', 'DOCX', 'PPT', 'PPTX', 'XLS', 'XLSX', 'CSV', 'TXT', 'MP3', 'MP4', 'ZIP'], true) ? $rx : 'LINK') ?></span><span><?= e($rs['title']) ?></span><span class="av-sr"> (opens in a new tab)</span></a></li>
<?php endforeach; ?>
      </ul>
    </section>
<?php endif; ?>

<?php $curMod = null; foreach ($curriculum as $mi => $mm) { if (($mm['lessons'][0]['slug'] ?? '') === $lessonSlug) { $curMod = $mm + ['n' => $mi + 1]; break; } }
if ($curMod && (trim((string) ($curMod['summary'] ?? '')) !== '' || LmsCourseware::objectives($curMod))): $mMins = array_sum(array_map(static fn($x) => (int) $x['duration_min'], $curMod['lessons'])); ?>
    <section class="avle-modintro" aria-labelledby="avle-modintro-h">
      <div class="avle-modintro-k av-num">Module <?= (int) $curMod['n'] ?> · <?= e(avac_plural(count($curMod['lessons']), 'item')) ?><?= $mMins ? ' · ' . $mMins . ' min' : '' ?></div>
      <h2 class="avle-h2" id="avle-modintro-h"><?= e((string) $curMod['title']) ?></h2>
<?php if (trim((string) ($curMod['summary'] ?? '')) !== ''): ?>      <p><?= e((string) $curMod['summary']) ?></p>
<?php endif; ?>
<?php if ($objs = LmsCourseware::objectives($curMod)): ?>      <h3>By the end of this module you will be able to</h3>
      <ul><?php foreach ($objs as $ob): ?><li><?= e($ob) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
    </section>
<?php endif; ?>
<?php if ($quiz): $qn = count($quiz['questions']); $outOfTries = $attemptsLeft === 0; ?>
    <form class="avle-quiz" data-avle-quiz data-course="<?= e($courseSlug) ?>" data-lesson="<?= e($lessonSlug) ?>" data-graded="<?= $quiz['graded'] ? '1' : '0' ?>">
      <p class="avle-quiz-intro"><?php if ($quiz['graded']): ?>Graded quiz · pass mark <?= (int) $quiz['pass'] ?>%<?= (int) ($lesson['weight'] ?? 1) > 1 ? ' · weight ' . (int) $lesson['weight'] : '' ?><?php else: ?>Practice quiz · doesn’t count toward your grade<?php endif; ?><?= $quiz['attempts'] > 0 ? ' · ' . e(avac_plural((int) $quiz['attempts'], 'attempt')) . ' allowed' : '' ?></p>
<?php if ($quizStats['used'] > 0): ?>
      <p class="avle-quiz-stat av-num" data-avle-quiz-stat>Best score <?= (int) $quizStats['best'] ?>% · <?= e(avac_plural($quizStats['used'], 'attempt')) ?><?= $attemptsLeft !== null ? ' · ' . $attemptsLeft . ' left' : '' ?></p>
<?php endif; ?>
<?php foreach ($quiz['questions'] as $qi => $q): $multi = $q['type'] === 'multi'; ?>
      <fieldset class="avle-q" data-avle-q="<?= $qi ?>" data-type="<?= e($q['type']) ?>">
        <legend><span class="avle-q-k av-num">Question <?= $qi + 1 ?> of <?= $qn ?><?= $multi ? ' · choose all that apply' : '' ?></span><span class="avle-q-t"><?= e((string) $q['q']) ?></span></legend>
<?php foreach ($q['options'] as $oi => $opt): ?>
        <label class="avle-opt" data-opt="<?= $oi ?>"><input type="<?= $multi ? 'checkbox' : 'radio' ?>" name="q<?= $qi ?><?= $multi ? '[]' : '' ?>" value="<?= $oi ?>"<?= $outOfTries ? ' disabled' : '' ?>><span class="avle-opt-k" aria-hidden="true"><?= e(chr(65 + ($oi % 26))) ?></span><span><?= e((string) $opt) ?></span></label>
<?php endforeach; ?>
        <p class="avle-q-fb" data-avle-qfb role="status" hidden></p>
      </fieldset>
<?php endforeach; ?>
      <div class="avle-quiz-foot">
<?php if ($outOfTries): ?>
        <p class="avle-quiz-out is-err">You’ve used every attempt on this quiz.</p>
<?php else: ?>
        <button type="submit" class="avle-btn avle-btn--lg"><?= $quizStats['used'] > 0 ? 'Try again' : 'Submit answers' ?></button>
        <p class="avle-quiz-out" data-avle-quiz-out role="status" aria-live="polite"></p>
<?php endif; ?>
      </div>
    </form>
<?php endif; ?>

<?php if ($lType === 'assignment'): $st = (string) ($submission['status'] ?? ''); $locked = $st === 'graded' && (int) ($submission['score'] ?? 0) >= $asgPass; ?>
    <section class="avle-asg" aria-labelledby="avle-asg-h">
      <h2 class="avle-h2" id="avle-asg-h">Your submission</h2>
      <p class="avle-asg-rule">Graded by your instructor · pass mark <?= $asgPass ?>%<?= (int) ($lesson['weight'] ?? 1) > 1 ? ' · weight ' . (int) $lesson['weight'] : '' ?></p>
<?php if ($submission): ?>
      <div class="avle-asg-state is-<?= e($st) ?>" data-avle-asg-state>
<?php if ($st === 'graded'): ?>
        <b class="av-num"><?= (int) $submission['score'] ?>%</b> <?= (int) $submission['score'] >= $asgPass ? 'Passed' : 'Not yet passed — revise and resubmit' ?>
<?php elseif ($st === 'returned'): ?>
        <b>Returned</b> Your instructor asked for changes.
<?php else: ?>
        <b>Submitted</b> Waiting for your instructor to grade it.
<?php endif; ?>
<?php if (trim((string) $submission['feedback']) !== ''): ?>
        <div class="avle-asg-fb"><span>Feedback</span><?= nl2br(e((string) $submission['feedback'])) ?></div>
<?php endif; ?>
      </div>
<?php endif; ?>
<?php if (!$user): ?>
      <p>Sign in to submit your work.</p>
<?php elseif (!$locked): ?>
      <form class="avle-asg-form" data-avle-asg data-course="<?= e($courseSlug) ?>" data-lesson="<?= e($lessonSlug) ?>">
        <label for="avle-asg-body">Your answer</label>
        <textarea id="avle-asg-body" name="body" rows="8" maxlength="20000"><?= e((string) ($submission['body'] ?? '')) ?></textarea>
        <label for="avle-asg-url">Link to your work <span>(optional)</span></label>
        <input id="avle-asg-url" name="url" type="url" inputmode="url" placeholder="https://" value="<?= e((string) ($submission['link_url'] ?? '')) ?>">
        <div class="avle-asg-foot"><button type="submit" class="avle-btn avle-btn--lg"><?= $submission ? 'Resubmit' : 'Submit' ?></button><p class="avle-quiz-out" data-avle-asg-out role="status" aria-live="polite"></p></div>
      </form>
<?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($lType === 'discussion' && $prompt): $mine = $user && array_filter($replies, static fn($p) => (int) $p['user_id'] === (int) $user['id']); ?>
    <section class="avle-disc" aria-labelledby="avle-disc-h">
      <h2 class="avle-h2" id="avle-disc-h">Responses <span class="av-num">(<?= count($replies) ?>)</span></h2>
<?php if ($user): ?>
      <form class="avle-asg-form" data-avle-disc data-course="<?= e($courseSlug) ?>" data-lesson="<?= e($lessonSlug) ?>">
        <label for="avle-disc-body"><?= $mine ? 'Add another response' : 'Your response — posting completes this item' ?></label>
        <textarea id="avle-disc-body" name="body" rows="5" maxlength="10000" required></textarea>
        <div class="avle-asg-foot"><button type="submit" class="avle-btn avle-btn--lg">Post</button><p class="avle-quiz-out" data-avle-disc-out role="status" aria-live="polite"></p></div>
      </form>
<?php endif; ?>
      <ol class="avle-posts">
<?php foreach (array_reverse($replies) as $p): ?>
        <li><div class="avle-post-h"><b><?= e((string) ($p['author'] ?? 'Learner')) ?></b><span class="av-num"><?= e(substr((string) $p['created_at'], 0, 10)) ?></span></div><div class="avle-post-b"><?= nl2br(e((string) $p['body'])) ?></div></li>
<?php endforeach; ?>
<?php if (!$replies): ?>        <li class="avle-posts-none">No responses yet — be the first.</li>
<?php endif; ?>
      </ol>
    </section>
<?php endif; ?>

<?php if ($user): ?>
    <div class="avle-done" data-avle-done<?= $lms->getCertificate((int) $user['id'], (int) $course['id']) ? '' : ' hidden' ?>>
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
        <span class="avle-keys" aria-hidden="true">N next · P prev<?= $selfComplete ? ' · K complete' : '' ?> · S contents</span>
<?php if ($selfComplete): ?>
        <button type="button" class="avle-btn avle-btn--lg" data-avle-complete data-done="<?= $isDone ? '1' : '0' ?>"<?= $next ? ' data-next="' . e($nextUrl) . '"' : '' ?>><?= $isDone ? 'Completed ✓' : 'Mark complete' . ($next ? ' & continue' : '') ?></button>
<?php else: ?>
        <span class="avle-qstatus<?= $isDone ? ' is-done' : '' ?>" data-avle-qstatus><?= $isDone ? 'Completed ✓' : ($quiz ? 'Quiz required' : ($lType === 'assignment' ? 'Submission required' : 'Response required')) ?></span>
<?php endif; ?>
<?php if ($next): ?>        <a class="avle-btn avle-btn--line avle-btn--lg" href="<?= e($nextUrl) ?>" data-avle-next>Next →</a>
<?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
</main>

<?php if ($coachOn): ?>
<?php /* THE COACH. A drawer beside the lesson, not a box at the bottom of
         it: a learner who is stuck is stuck HERE, with the paragraph still
         in front of them, and a panel they have to scroll past the whole
         lesson to reach is one they will not use.

         It is a conversation — earlier turns travel with each question, so
         "say that again, simpler" means something. The four quick actions
         are the four things people actually ask for.

         PRACTICE IS NOT ASSESSMENT: "Quiz me" asks and responds and records
         nothing. Graded quizzes are the lesson's own, and a person wrote
         them. lib/LmsCoach.php cannot reach a grade. */ ?>
<aside class="avle-coach" id="avle-coach" aria-labelledby="avle-coach-h" data-avle-coach hidden
       data-course="<?= e($courseSlug) ?>" data-lesson="<?= e($lessonSlug) ?>" data-forum="<?= e($forumUrl) ?>">
  <div class="avle-coach-hd">
    <div>
      <h2 id="avle-coach-h">Study coach</h2>
      <p class="avle-coach-tag">AI · reads this lesson only · never marks your work</p>
    </div>
    <button type="button" class="avle-icon" data-avle-coach-close aria-label="Close the coach"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
  </div>
  <div class="avle-coach-log" data-avle-coach-log role="log" aria-live="polite">
    <p class="avle-coach-hello">Ask me anything about <strong><?= e((string) $lesson['title']) ?></strong>. I read this lesson and nothing else — if you need something it does not cover, or you want a person, the <a href="<?= e($forumUrl) ?>">course discussions</a> are where a person will see it.</p>
  </div>
  <div class="avle-coach-acts" data-avle-coach-acts>
    <button type="button" class="avle-chip" data-coach-mode="summarise">Summarise it</button>
    <button type="button" class="avle-chip" data-coach-mode="simpler">Explain it simply</button>
    <button type="button" class="avle-chip" data-coach-mode="example">Give an example</button>
    <button type="button" class="avle-chip" data-coach-mode="quiz">Quiz me</button>
  </div>
  <form class="avle-coach-form" data-avle-coach-form>
    <label class="av-sr" for="avle-coach-q">Your message to the coach</label>
    <textarea id="avle-coach-q" rows="2" maxlength="<?= (int) LmsCoach::MAX_QUESTION ?>" placeholder="Ask about this lesson…"></textarea>
    <button type="submit" class="avle-btn" data-avle-coach-send>Send</button>
  </form>
</aside>
<?php endif; ?>
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
