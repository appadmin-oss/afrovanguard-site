<?php
/**
 * academy/programme.php — programmes: series of courses with their own certificate.
 *   /academy/programmes/                    every published programme
 *   /academy/programmes/<slug>/             the series, in order, with your progress
 *   /academy/programmes/<slug>/certificate  the programme certificate (PNG), once
 *                                           every course in it is certified
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once __DIR__ . '/_avcw.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$lms = new LmsRepository(); $cw = new LmsCourseware(null, $lms);
$u = LmsAuth::user();
$S = rtrim(SITE_URL, '/');
$p = $slug !== '' ? $cw->programme($slug) : null;
if ($slug !== '' && !$p) { require_once AV_ROOT . '/lib/errors.php'; av_error_render(404); exit; }

if ($p && !empty($_GET['cert'])) {
    require_once __DIR__ . '/_cert.php';
    $u = avcw_need_login();
    $cert = $cw->issueProgrammeCertificate((int) $u['id'], $p);
    if (!$cert) { header('Location: /academy/programmes/' . rawurlencode($slug) . '/'); exit; }
    avac_cert_png((string) $u['name'], 'programme', (string) $p['title'], (string) $cert['issued_at'], (string) $cert['serial'], 'afrovanguard-programme-' . $slug . '.png');
    exit;
}

if (!$p) {
    $all = array_values(array_filter($cw->programmes(true), static fn($x) => (int) $x['course_count'] > 0));
    avcw_open([
        'title' => 'Programmes — Afrovanguard Academy',
        'desc' => 'Series of Afrovanguard Academy courses that build one skill set, each with a programme certificate.',
        'canonical' => $S . '/academy/programmes/',
        'crumbs' => [['Academy', '/academy/'], ['Programmes', null]],
        'jsonld' => [schema_org(), ['@type' => 'CollectionPage', 'name' => 'Programmes — Afrovanguard Academy',
            'url' => $S . '/academy/programmes/', 'isPartOf' => ['@id' => $S . '/#website'], 'about' => ['@id' => $S . '/#organization']],
            schema_breadcrumb([['name' => 'Academy', 'url' => $S . '/academy/'], ['name' => 'Programmes', 'url' => $S . '/academy/programmes/']])],
    ]);
    ?>
<header class="avcw-head">
  <div class="avac-eyebrow">Programmes</div>
  <h1 class="avcw-h1">Learn a skill set, course by course</h1>
  <p class="avcw-lede">Each programme is a series of courses. Pass every one to earn the programme certificate.</p>
</header>
<?php if (!$all): ?>
<p class="avcw-empty">No programmes yet. <a href="/academy/#catalogue">Browse single courses →</a></p>
<?php else: ?>
<ul class="avac-prog-list" role="list">
<?php foreach ($all as $x): ?>
  <li><a href="/academy/programmes/<?= e(rawurlencode((string) $x['slug'])) ?>/"><span class="avac-prog-k av-num"><?= e(avac_plural((int) $x['course_count'], 'course')) ?> series</span><span class="avac-prog-t"><?= e((string) $x['title']) ?></span><span class="avac-prog-d"><?= e((string) $x['summary']) ?></span></a></li>
<?php endforeach; ?>
</ul>
<?php endif;
    avcw_close();
    return;
}

$pp = $u ? $cw->programmeProgress((int) $u['id'], $p) : null;
$courses = $pp ? $pp['courses'] : array_map(static fn($c) => ['course' => $c, 'certified' => false], $cw->programmeCourses((int) $p['id']));
$st = $cw->stats(array_map(static fn($r) => (int) $r['course']['id'], $courses));
if ($pp && $pp['complete'] && !$pp['cert']) $pp['cert'] = $cw->issueProgrammeCertificate((int) $u['id'], $p);
$canonical = $S . '/academy/programmes/' . $p['slug'] . '/';
$schema = ['@type' => 'EducationalOccupationalProgram', 'name' => $p['title'], 'description' => $p['summary'], 'url' => $canonical,
    'provider' => ['@type' => 'Organization', 'name' => 'Afrovanguard', '@id' => SITE_URL . '/#organization'],
    'hasCourse' => array_map(static fn($r) => ['@type' => 'Course', 'name' => $r['course']['title'], 'url' => $S . '/academy/' . $r['course']['slug'] . '/'], $courses)];
avcw_open([
    'title' => $p['title'] . ' — Programme · Afrovanguard Academy',
    'desc' => (string) $p['summary'], 'canonical' => $canonical, 'jsonld' => [schema_org(), $schema],
    'crumbs' => [['Academy', '/academy/'], ['Programmes', '/academy/programmes/'], [(string) $p['title'], null]],
]);
?>
<header class="avcw-head">
  <div class="avac-eyebrow">Programme · <?= e(avac_plural(count($courses), 'course')) ?></div>
  <h1 class="avcw-h1"><?= e((string) $p['title']) ?></h1>
  <p class="avcw-lede"><?= e((string) $p['summary']) ?></p>
<?php if ($pp): ?>
  <div class="avcw-prog"><span class="av-num"><?= (int) $pp['done'] ?> of <?= (int) $pp['total'] ?> courses certified</span><span class="avac-bar"><span style="width:<?= (int) $pp['pct'] ?>%"></span></span></div>
<?php if ($pp['cert']): ?>
  <div class="avcw-actions"><a class="avac-btn" href="/academy/programmes/<?= e(rawurlencode((string) $p['slug'])) ?>/certificate" target="_blank" rel="noopener">Programme certificate →<span class="av-sr"> (opens in a new tab)</span></a></div>
<?php endif; ?>
<?php endif; ?>
</header>
<?php if (trim(strip_tags((string) $p['body_html'])) !== ''): ?>
<div class="avcw-body"><?= $p['body_html'] ?></div>
<?php endif; ?>
<ol class="avcw-series" role="list">
<?php foreach ($courses as $i => $r): $c = $r['course']; $f = $st[(int) $c['id']] ?? ['lessons' => 0, 'rating' => 0, 'ratings' => 0]; ?>
  <li class="<?= $r['certified'] ? 'is-done' : '' ?>">
    <span class="avcw-series-n av-num" aria-hidden="true"><?= $r['certified'] ? '✓' : $i + 1 ?></span>
    <div>
      <a class="avcw-card-t" href="<?= e(academy_url($c['slug'] . '/')) ?>">Course <?= $i + 1 ?>: <?= e((string) $c['title']) ?></a>
      <p class="avcw-sub"><?= e(implode(' · ', array_filter([(string) ($c['level'] ?? ''), (string) ($c['duration'] ?? ''), $f['lessons'] ? avac_plural((int) $f['lessons'], 'item') : '']))) ?> <?= avac_stars((float) $f['rating'], (int) $f['ratings']) ?></p>
      <p><?= e((string) $c['summary']) ?></p>
<?php if ($r['certified']): ?>      <span class="av-sr">Certified</span><?= avcw_status('passed') ?>
<?php endif; ?>
    </div>
  </li>
<?php endforeach; ?>
</ol>
<?php avcw_close();
