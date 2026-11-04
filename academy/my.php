<?php
/**
 * academy/my.php — My learning. /academy/my
 *
 * In progress (resume at the next item, with the suggested date for it and a
 * "behind" flag), recent feedback on assignments, programmes under way, and
 * completed courses with their certificates. One LmsCourseware::dashboard().
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once __DIR__ . '/_avcw.php';

$u = avcw_need_login();
$lms = new LmsRepository(); $cw = new LmsCourseware(null, $lms);
$d = $cw->dashboard((int) $u['id']);
$teaches = LmsAuth::canTeach($u);
$day = static fn(?string $t): string => $t ? date('D j M', strtotime($t) ?: time()) : '';
$typeName = ['video' => 'Video', 'reading' => 'Reading', 'quiz' => 'Graded quiz', 'practice' => 'Practice quiz', 'assignment' => 'Assignment', 'discussion' => 'Discussion'];

avcw_open([
    'title' => 'My learning · Afrovanguard Academy',
    'canonical' => rtrim(SITE_URL, '/') . '/academy/my', 'private' => true,
    'crumbs' => [['Academy', '/academy/'], ['My learning', null]],
]);
?>
<header class="avcw-head">
  <div class="avac-eyebrow">Welcome back, <?= e(explode(' ', trim((string) $u['name']))[0] ?: 'learner') ?></div>
  <h1 class="avcw-h1">My learning</h1>
  <div class="avcw-actions">
    <a class="avac-btn avac-btn--line" href="/academy/#catalogue">Find a course</a>
<?php if ($teaches): ?>    <a class="avac-btn avac-btn--line" href="/academy/teach/grading">Grading queue</a>
<?php endif; ?>
  </div>
</header>

<section aria-labelledby="avcw-in-h">
  <h2 class="avcw-h2" id="avcw-in-h">In progress</h2>
<?php if (!$d['active']): ?>
  <p class="avcw-empty">Nothing in progress. <a href="/academy/#catalogue">Browse the catalogue →</a></p>
<?php else: ?>
  <ul class="avcw-cards">
<?php foreach ($d['active'] as $r): $c = $r['course']; $nx = $r['next']; $cs = (string) $c['slug']; ?>
    <li class="avcw-card">
      <div class="avcw-card-top">
        <a class="avcw-card-t" href="<?= e(academy_url($cs . '/')) ?>"><?= e((string) $c['title']) ?></a>
        <span class="av-num avcw-sub"><?= (int) $r['done'] ?>/<?= (int) $r['total'] ?> items</span>
      </div>
      <span class="avac-bar"><span style="width:<?= (int) $r['pct'] ?>%"></span></span>
<?php if ($nx): ?>
      <p class="avcw-next"><span>Next: <?= e($typeName[$nx['type']] ?? 'Item') ?></span> <?= e((string) $nx['title']) ?><span class="avcw-sub"><?= e((string) $nx['module']) ?></span></p>
<?php if ($r['due']): ?>      <p class="avcw-due<?= $r['behind'] ? ' is-behind' : '' ?>"><?= $r['behind'] ? 'Behind schedule — suggested by ' : 'Suggested by ' ?><?= e($day($r['due'])) ?></p>
<?php endif; ?>
<?php elseif ($r['grades'] && !$r['grades']['passed']): ?>
      <p class="avcw-next">Every item is done — <?= (int) $r['grades']['overall'] ?>% overall against a pass mark of <?= (int) $r['grades']['pass_mark'] ?>%.</p>
<?php endif; ?>
      <div class="avcw-card-b">
        <a class="avac-btn" href="<?= e(academy_url($cs . '/learn' . ($nx ? '/' . $nx['slug'] : ''))) ?>"><?= $r['done'] ? 'Resume' : 'Start' ?> →</a>
        <a class="avac-btn avac-btn--line" href="<?= e(academy_url($cs . '/grades')) ?>">Grades</a>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>

<?php if ($d['feedback']): ?>
<section aria-labelledby="avcw-fb-h">
  <h2 class="avcw-h2" id="avcw-fb-h">Assignments</h2>
  <ul class="avcw-list">
<?php foreach ($d['feedback'] as $f):
    $st = $f['status'] === 'graded' ? ((int) $f['score'] >= (int) $f['pass'] ? 'passed' : 'failed') : (string) $f['status']; ?>
    <li><a href="<?= e(academy_url($f['course_slug'] . '/learn/' . $f['item_slug'])) ?>"><b><?= e((string) $f['item_title']) ?></b><span class="avcw-sub"><?= e((string) $f['course_title']) ?><?= $f['status'] === 'graded' ? ' · ' . (int) $f['score'] . '%' : '' ?></span></a><?= avcw_status($st) ?></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($d['programmes']): ?>
<section aria-labelledby="avcw-pg-h">
  <h2 class="avcw-h2" id="avcw-pg-h">Programmes</h2>
  <ul class="avcw-list">
<?php foreach ($d['programmes'] as $p): ?>
    <li><a href="/academy/programmes/<?= e(rawurlencode((string) $p['programme']['slug'])) ?>/"><b><?= e((string) $p['programme']['title']) ?></b><span class="avcw-sub av-num"><?= (int) $p['done'] ?> of <?= e(avac_plural((int) $p['total'], 'course')) ?> certified</span></a><?= $p['cert'] ? avcw_status('passed') : '<span class="av-num avcw-sub">' . (int) $p['pct'] . '%</span>' ?></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($d['done']): ?>
<section aria-labelledby="avcw-done-h">
  <h2 class="avcw-h2" id="avcw-done-h">Completed</h2>
  <ul class="avcw-list">
<?php foreach ($d['done'] as $r): $c = $r['course']; ?>
    <li><a href="<?= e(academy_url($c['slug'] . '/')) ?>"><b><?= e((string) $c['title']) ?></b><span class="avcw-sub">Certificate <?= e((string) $r['cert']['serial']) ?></span></a><a class="avac-more" href="<?= e(academy_url($c['slug'] . '/certificate')) ?>" target="_blank" rel="noopener">Certificate →<span class="av-sr"> (opens in a new tab)</span></a></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php avcw_close();
