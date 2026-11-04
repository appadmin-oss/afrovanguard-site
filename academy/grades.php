<?php
/**
 * academy/grades.php — a learner's grades for one course. /academy/<course>/grades
 *
 * Every graded item (graded quizzes and assignments) with its weight, pass
 * mark, score and status; the weighted overall grade against the course's
 * pass mark; and what is left before the certificate. The figures are
 * LmsCourseware::grades(), the same answer the certificate is issued on.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once __DIR__ . '/_avcw.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['course'] ?? '')));
$ac = new AcademyRepository(); $lms = new LmsRepository(); $cw = new LmsCourseware(null, $lms);
$c = $slug ? $ac->bySlug($slug) : null;
if (!$c) { require_once AV_ROOT . '/lib/errors.php'; av_error_render(404); exit; }
$u = avcw_need_login();
$g = $cw->grades((int) $u['id'], $c);
$pr = $lms->progress((int) $u['id'], (int) $c['id']);
$cert = $lms->getCertificate((int) $u['id'], (int) $c['id']);
if (!$cert && $lms->requirementsMet((int) $u['id'], (int) $c['id'])) $cert = $lms->issueCertificate((int) $u['id'], (int) $c['id']);
$learn = static fn(string $s): string => academy_url($slug . '/learn/' . $s);
$typeName = ['quiz' => 'Graded quiz', 'assignment' => 'Assignment'];
$left = array_values(array_filter($g['items'], static fn($i) => $i['status'] !== 'passed'));

avcw_open([
    'title' => 'Grades — ' . $c['title'] . ' · Afrovanguard Academy',
    'canonical' => academy_url($slug . '/grades'), 'private' => true,
    'crumbs' => [['Academy', '/academy/'], [(string) $c['title'], academy_url($slug . '/')], ['Grades', null]],
]);
?>
<header class="avcw-head">
  <div class="avac-eyebrow"><?= e((string) $c['title']) ?></div>
  <h1 class="avcw-h1">Grades</h1>
  <div class="avcw-actions">
    <a class="avac-btn" href="<?= e(academy_url($slug . '/learn')) ?>">Resume course →</a>
    <a class="avac-btn avac-btn--line" href="<?= e(academy_url($slug . '/discussions')) ?>">Discussions</a>
  </div>
</header>

<section class="avcw-summary" aria-label="Summary">
  <div class="avcw-stat"><span>Overall grade</span><b class="av-num"><?= $g['graded_count'] ? (int) $g['overall'] . '%' : '—' ?></b><small>Pass mark <?= (int) $g['pass_mark'] ?>%</small></div>
  <div class="avcw-stat"><span>Items done</span><b class="av-num"><?= (int) $pr['completed'] ?>/<?= (int) $pr['total'] ?></b><small><?= (int) $pr['pct'] ?>% of the course</small></div>
  <div class="avcw-stat"><span>Certificate</span>
<?php if ($cert): ?>
    <b>Earned</b><small><a href="<?= e(academy_url($slug . '/certificate')) ?>" target="_blank" rel="noopener">Open certificate →</a></small>
<?php else: ?>
    <b>Not yet</b><small><?= !$pr['complete'] ? 'Finish every item' : ($left ? 'Pass every graded item' : 'Reach the pass mark') ?></small>
<?php endif; ?>
  </div>
</section>

<?php if (!$g['items']): ?>
<p class="avcw-empty">This course has no graded work. Complete every item to earn the certificate.</p>
<?php else: ?>
<div class="avcw-tablewrap">
<table class="avcw-table">
  <caption class="av-sr">Graded items in <?= e((string) $c['title']) ?></caption>
  <thead><tr><th scope="col">Item</th><th scope="col">Status</th><th scope="col" class="num avcw-opt">Weight</th><th scope="col" class="num avcw-opt">Pass</th><th scope="col" class="num">Score</th></tr></thead>
  <tbody>
<?php foreach ($g['items'] as $it): ?>
    <tr>
      <td><a href="<?= e($learn($it['slug'])) ?>"><?= e($it['title']) ?></a><span class="avcw-sub"><?= e($typeName[$it['type']] ?? 'Graded item') ?> · pass <?= (int) $it['pass'] ?>%<?= (int) $it['weight'] > 1 ? ' · weight ' . (int) $it['weight'] : '' ?></span></td>
      <td><?= avcw_status($it['status']) ?></td>
      <td class="num av-num avcw-opt"><?= (int) $it['weight'] ?></td>
      <td class="num av-num avcw-opt"><?= (int) $it['pass'] ?>%</td>
      <td class="num av-num"><?= $it['score'] === null ? '—' : (int) $it['score'] . '%' ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="avcw-note">Your overall grade is the weighted average of these items; an item not yet graded counts as 0. To pass, score <?= (int) $g['pass_mark'] ?>% overall and pass every item.</p>
<?php endif; ?>
<?php avcw_close();
