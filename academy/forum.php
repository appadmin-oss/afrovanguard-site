<?php
/**
 * academy/forum.php — a course's discussion forum.
 *   /academy/<course>/discussions          threads, newest activity first
 *   /academy/<course>/discussions/<id>     one thread and its replies
 *
 * Reading is open to anyone who can open the course; posting needs access
 * (academy/api.php forum_thread / forum_reply). The instructor can pin, lock
 * and hide (forum_moderate). Behaviour: academy/avcw.js.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once __DIR__ . '/_avcw.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['course'] ?? '')));
$tid = (int) ($_GET['thread'] ?? 0);
$ac = new AcademyRepository(); $lms = new LmsRepository(); $cw = new LmsCourseware(null, $lms);
$c = $slug ? $ac->bySlug($slug) : null;
if (!$c) { require_once AV_ROOT . '/lib/errors.php'; av_error_render(404); exit; }
$u = LmsAuth::user();
$teach = $u && $lms->teaches($u, $c);
$member = $u && ($teach || $lms->canAccess($u, $c, ['is_preview' => 0]));
/* A paid or restricted course's prompts are course content, so its forum is
   read by the people taking it; an open course's forum is open to read. */
$canRead = $member || in_array((string) ($c['access_type'] ?? 'open'), ['open', 'tracked'], true);
$base = academy_url($slug . '/discussions');
$thread = $tid ? $cw->thread($tid) : null;
if ($tid && (!$thread || (int) $thread['course_id'] !== (int) $c['id'] || ((int) $thread['hidden'] === 1 && !$teach))) {
    require_once AV_ROOT . '/lib/errors.php'; av_error_render(404); exit;
}
$day = static fn(string $t): string => date('j M Y', strtotime($t) ?: time());

avcw_open([
    'title' => ($thread ? $thread['title'] . ' — ' : 'Discussions — ') . $c['title'] . ' · Afrovanguard Academy',
    'desc' => 'Questions and ideas from learners on ' . $c['title'] . '.',
    'canonical' => $thread ? $base . '/' . (int) $thread['id'] : $base, 'private' => true,
    'crumbs' => array_values(array_filter([['Academy', '/academy/'], [(string) $c['title'], academy_url($slug . '/')],
        ['Discussions', $thread ? $base : null], $thread ? [(string) $thread['title'], null] : null])),
]);
?>
<div class="avcw-forum" data-avcw-forum data-course="<?= e($slug) ?>">
<?php if (!$canRead): ?>
<header class="avcw-head"><div class="avac-eyebrow"><?= e((string) $c['title']) ?></div><h1 class="avcw-h1">Discussions</h1></header>
<p class="avcw-note"><?= $u ? 'Join this course to read and take part in its discussions.' : '<a href="/login" data-avac-auth="login">Sign in</a> and join this course to read its discussions.' ?> <a href="<?= e(academy_url($slug . '/')) ?>">See the course →</a></p>
<?php elseif (!$thread):
    $threads = $cw->threads((int) $c['id'], $teach); ?>
<header class="avcw-head">
  <div class="avac-eyebrow"><?= e((string) $c['title']) ?></div>
  <h1 class="avcw-h1">Discussions</h1>
  <p class="avcw-lede">Ask a question, share what you made, or help someone stuck on the same step.</p>
</header>
<?php if ($member): ?>
<details class="avcw-new"<?= $threads ? '' : ' open' ?>>
  <summary class="avac-btn">Start a discussion</summary>
  <form class="avcw-form" data-avcw-thread>
    <label for="avcw-t-title">Title</label>
    <input id="avcw-t-title" name="title" maxlength="160" required>
    <label for="avcw-t-body">Your question or idea</label>
    <textarea id="avcw-t-body" name="body" rows="5" maxlength="10000" required></textarea>
    <div class="avcw-form-foot"><button type="submit" class="avac-btn">Post</button><p class="avcw-msg" data-avcw-msg role="status" aria-live="polite"></p></div>
  </form>
</details>
<?php elseif (!$u): ?>
<p class="avcw-note"><a href="/login" data-avac-auth="login">Sign in</a> and join the course to take part.</p>
<?php else: ?>
<p class="avcw-note">Join this course to take part. <a href="<?= e(academy_url($slug . '/')) ?>">See how →</a></p>
<?php endif; ?>
<?php if ($threads): ?>
<ul class="avcw-threads">
<?php foreach ($threads as $t): ?>
  <li class="<?= (int) $t['hidden'] ? 'is-hidden' : '' ?>">
    <a href="<?= e($base . '/' . (int) $t['id']) ?>">
      <span class="avcw-t-title"><?php if ((int) $t['pinned']): ?><span class="avcw-chip avcw-chip--wait">Pinned</span> <?php endif; ?><?php if ((int) $t['locked']): ?><span class="avcw-chip avcw-chip--idle">Closed</span> <?php endif; ?><?= e((string) $t['title']) ?></span>
      <span class="avcw-sub"><?= (int) $t['user_id'] === 0 ? 'Course prompt' : e((string) ($t['author'] ?? 'Learner')) ?><?= $t['lesson_title'] ? ' · ' . e((string) $t['lesson_title']) : '' ?> · <?= e($day((string) $t['last_post_at'])) ?></span>
    </a>
    <span class="avcw-count av-num"><?= e(avac_plural((int) $t['reply_count'], 'reply')) ?></span>
  </li>
<?php endforeach; ?>
</ul>
<?php else: ?>
<p class="avcw-empty">No discussions yet. Be the first to start one.</p>
<?php endif; ?>

<?php else:
    $posts = $cw->posts((int) $thread['id'], $teach);
    $isPrompt = (int) $thread['user_id'] === 0;
    $promptLesson = $isPrompt && $thread['lesson_id'] ? $lms->lessonById((int) $thread['lesson_id']) : null; ?>
<article class="avcw-thread" data-thread="<?= (int) $thread['id'] ?>">
  <header class="avcw-head">
    <h1 class="avcw-h1 avcw-h1--sm"><?= e((string) $thread['title']) ?></h1>
    <p class="avcw-sub"><?= $isPrompt ? 'Course prompt' : e((string) ($thread['author'] ?? 'Learner')) ?> · <?= e($day((string) $thread['created_at'])) ?></p>
<?php if ($teach): ?>
    <div class="avcw-mod" role="group" aria-label="Moderate">
      <button type="button" class="avac-btn avac-btn--line" data-avcw-mod="thread" data-id="<?= (int) $thread['id'] ?>" data-op="<?= (int) $thread['pinned'] ? 'unpin' : 'pin' ?>"><?= (int) $thread['pinned'] ? 'Unpin' : 'Pin' ?></button>
      <button type="button" class="avac-btn avac-btn--line" data-avcw-mod="thread" data-id="<?= (int) $thread['id'] ?>" data-op="<?= (int) $thread['locked'] ? 'unlock' : 'lock' ?>"><?= (int) $thread['locked'] ? 'Reopen' : 'Close' ?></button>
      <button type="button" class="avac-btn avac-btn--line" data-avcw-mod="thread" data-id="<?= (int) $thread['id'] ?>" data-op="<?= (int) $thread['hidden'] ? 'show' : 'hide' ?>"><?= (int) $thread['hidden'] ? 'Show' : 'Hide' ?></button>
    </div>
<?php endif; ?>
  </header>
<?php if ($promptLesson): ?>
  <div class="avcw-body"><?= $promptLesson['body_html'] ?></div>
  <p class="avcw-note">This is the prompt for a discussion item. <a href="<?= e(academy_url($slug . '/learn/' . $promptLesson['slug'])) ?>">Respond in the course →</a></p>
<?php elseif (trim((string) $thread['body']) !== ''): ?>
  <div class="avcw-body"><?= nl2br(e((string) $thread['body'])) ?></div>
<?php endif; ?>
  <h2 class="avcw-h2"><?= e(avac_plural(count($posts), 'reply')) ?></h2>
  <ol class="avcw-posts">
<?php foreach ($posts as $p): ?>
    <li class="<?= (int) $p['hidden'] ? 'is-hidden' : '' ?>">
      <div class="avcw-post-h"><b><?= e((string) ($p['author'] ?? 'Learner')) ?></b><?php if (in_array((string) ($p['author_role'] ?? ''), ['instructor', 'coordinator', 'admin'], true)): ?> <span class="avcw-chip avcw-chip--ok">Staff</span><?php endif; ?><span class="av-num"><?= e($day((string) $p['created_at'])) ?></span>
<?php if ($teach): ?>        <button type="button" class="avcw-link" data-avcw-mod="post" data-id="<?= (int) $p['id'] ?>" data-op="<?= (int) $p['hidden'] ? 'show' : 'hide' ?>"><?= (int) $p['hidden'] ? 'Show' : 'Hide' ?></button>
<?php endif; ?>
      </div>
      <div class="avcw-post-b"><?= nl2br(e((string) $p['body'])) ?></div>
    </li>
<?php endforeach; ?>
  </ol>
<?php if ($member && !(int) $thread['locked'] && !$promptLesson): ?>
  <form class="avcw-form" data-avcw-reply="<?= (int) $thread['id'] ?>">
    <label for="avcw-r-body">Your reply</label>
    <textarea id="avcw-r-body" name="body" rows="4" maxlength="10000" required></textarea>
    <div class="avcw-form-foot"><button type="submit" class="avac-btn">Reply</button><p class="avcw-msg" data-avcw-msg role="status" aria-live="polite"></p></div>
  </form>
<?php elseif ((int) $thread['locked']): ?>
  <p class="avcw-note">This discussion is closed.</p>
<?php endif; ?>
  <p><a class="avac-more" href="<?= e($base) ?>">← All discussions</a></p>
</article>
<?php endif; ?>
</div>
<?php avcw_close();
