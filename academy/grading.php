<?php
/**
 * academy/grading.php — the instructor's grading queue. /academy/teach/grading
 *
 * Every submission waiting on the courses this person teaches (a coordinator
 * or admin sees every course), oldest first, with the learner's answer and a
 * grade-or-return form. Grading goes through academy/api.php
 * assignment_grade, which checks LmsRepository::teaches() per submission —
 * this page's list is a convenience, never the permission.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once __DIR__ . '/_avcw.php';

$u = avcw_need_login();
if (!LmsAuth::canTeach($u)) { require_once AV_ROOT . '/lib/errors.php'; av_error_render(403); exit; }
$lms = new LmsRepository(); $cw = new LmsCourseware(null, $lms);
$all = LmsAuth::atLeast($u, 'coordinator');
$ids = $all ? null : array_map(static fn($c) => (int) $c['id'], $lms->coursesForInstructor((int) $u['id']));
$tab = in_array($_GET['status'] ?? '', ['submitted', 'graded', 'returned'], true) ? (string) $_GET['status'] : 'submitted';
$rows = $cw->queue($ids, $tab);
$waiting = $tab === 'submitted' ? count($rows) : count($cw->queue($ids, 'submitted'));

avcw_open([
    'title' => 'Grading · Afrovanguard Academy',
    'canonical' => rtrim(SITE_URL, '/') . '/academy/teach/grading', 'private' => true,
    'crumbs' => [['Academy', '/academy/'], ['Teach', '/academy/teach/'], ['Grading', null]],
]);
?>
<header class="avcw-head">
  <div class="avac-eyebrow">Teach</div>
  <h1 class="avcw-h1">Grading</h1>
  <p class="avcw-lede av-num"><?= $waiting ? e(avac_plural($waiting, 'submission')) . ' waiting' : 'Nothing waiting — you’re up to date.' ?></p>
</header>
<nav class="avcw-tabs" aria-label="Submissions" data-tabs="grading">
<?php foreach (['submitted' => 'To grade', 'graded' => 'Graded', 'returned' => 'Returned'] as $k => $lbl): ?>
  <a href="?status=<?= e($k) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($lbl) ?></a>
<?php endforeach; ?>
</nav>
<?php if (!$rows): ?>
<p class="avcw-empty">No submissions here.</p>
<?php else: ?>
<ul class="avcw-queue">
<?php foreach ($rows as $r): ?>
  <li class="avcw-sub-card">
    <div class="avcw-card-top">
      <div><b><?= e((string) $r['learner']) ?></b><span class="avcw-sub"><?= e((string) $r['course_title']) ?> · <a href="<?= e(academy_url($r['course_slug'] . '/learn/' . $r['item_slug'])) ?>"><?= e((string) $r['item_title']) ?></a> · pass <?= (int) $r['pass'] ?>%</span></div>
      <span class="av-num avcw-sub"><?= e(date('j M Y', strtotime((string) $r['updated_at']) ?: time())) ?></span>
    </div>
<?php if (trim((string) $r['body']) !== ''): ?>    <div class="avcw-answer"><?= nl2br(e((string) $r['body'])) ?></div>
<?php endif; ?>
<?php if (trim((string) $r['link_url']) !== ''): ?>    <p><a href="<?= e((string) $r['link_url']) ?>" target="_blank" rel="noopener nofollow ugc">Open the learner’s link ↗<span class="av-sr"> (opens in a new tab)</span></a></p>
<?php endif; ?>
    <form class="avcw-grade" data-avcw-grade="<?= (int) $r['id'] ?>">
      <label>Score (0–100)<input name="score" type="number" min="0" max="100" inputmode="numeric" value="<?= $r['score'] !== null ? (int) $r['score'] : '' ?>"></label>
      <label class="avcw-grade-fb">Feedback<textarea name="feedback" rows="3" maxlength="5000"><?= e((string) $r['feedback']) ?></textarea></label>
      <div class="avcw-form-foot">
        <button type="submit" class="avac-btn">Save grade</button>
        <button type="button" class="avac-btn avac-btn--line" data-avcw-return>Return for changes</button>
        <p class="avcw-msg" data-avcw-msg role="status" aria-live="polite"></p>
      </div>
    </form>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php avcw_close();
