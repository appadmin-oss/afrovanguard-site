<?php
/**
 * projects/_detail.php — shared renderer for project detail pages.
 *
 * Each projects/<slug>/index.php is a two-liner:
 *     <?php $PROJECT_SLUG = 'techhome'; require dirname(__DIR__) . '/_detail.php';
 *
 * Content lives in lib/projects_content.php; styling in
 * assets/site/project-detail.css. Built on the shared chrome so every project
 * page sits natively in the site (like the ethos page).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$slug = $PROJECT_SLUG ?? '';
$all  = require AV_ROOT . '/lib/projects_content.php';
if (!isset($all[$slug])) { http_response_code(404); echo 'Project not found.'; return; }
$P = $all[$slug];

$S         = rtrim(SITE_URL, '/');
$canonical = "$S/projects/$slug/";
$fullName  = $P['name'] . ($P['abbr'] !== '' ? ' (' . $P['abbr'] . ')' : '');

$jsonld = [
    schema_org(),
    ['@type' => 'CreativeWork', '@id' => $canonical . '#project',
     'name' => $fullName, 'headline' => $P['name'], 'description' => $P['lead'],
     'about' => $P['about'],
     'isPartOf' => ['@id' => $S . '/#organization'], 'publisher' => ['@id' => $S . '/#organization']],
    schema_breadcrumb([
        ['name' => 'Home', 'url' => "$S/"],
        ['name' => 'Projects', 'url' => "$S/projects/"],
        ['name' => $P['name'], 'url' => $canonical],
    ]),
];

render_head([
    'title' => $fullName . ' — Afrovanguard',
    'desc'  => $P['lead'],
    'canonical' => $canonical, 'og_kind' => 'website',
    'image_alt' => $P['name'] . ' — Afrovanguard',
    'keywords' => implode(', ', array_merge([$P['name']], $P['abbr'] !== '' ? [$P['abbr']] : [], $P['about'], ['Afrovanguard'])),
    'css' => ['/assets/site/project-detail.css'], 'jsonld' => $jsonld,
]);
render_nav('projects');
?>
<main id="main-content">
  <section class="pjd-hero">
    <div class="container">
      <span class="diary-eyebrow"><?= e($P['eyebrow']) ?></span>
      <h1><?= $P['title'] /* trusted, authored markup (e.g. <br>) */ ?></h1>
      <p class="pjd-lead"><?= e($P['lead']) ?></p>
      <div class="pjd-cta">
        <a class="btn btn-primary" href="/contact/">Get involved</a>
        <a class="btn btn-outline" href="/projects/">All projects</a>
      </div>
    </div>
  </section>

  <div class="container pjd-body">
    <section class="pjd-sec" id="foundations">
      <h2><?= e($P['found_h']) ?></h2>
      <p class="pjd-sub"><?= e($P['found_sub']) ?></p>
      <div class="pjd-grid">
<?php foreach ($P['foundations'] as $i => $f): ?>        <article class="pjd-tile">
          <span class="pjd-no"><?= $i + 1 ?></span>
          <h3><?= e($f[0]) ?></h3>
          <p><?= e($f[1]) ?></p>
        </article>
<?php endforeach; ?>
      </div>
    </section>

    <section class="pjd-sec" id="who">
      <h2>Who it's for</h2>
      <p class="pjd-sub"><?= e($P['who']) ?></p>
    </section>

    <section class="pjd-sec" aria-label="Take the next step">
      <h2>Take the next step</h2>
      <p class="pjd-sub">This is where it becomes real.</p>
      <div class="pjd-grid pjd-grid--links">
        <a class="pjd-tile pjd-tile--link" href="/academy/"><h3>Learn &amp; lead →</h3><p>Free, hands-on programmes in the Afrovanguard Academy.</p></a>
        <a class="pjd-tile pjd-tile--link" href="/contact/"><h3>Get involved →</h3><p>Apply, partner, or volunteer with <?= e($P['name']) ?>.</p></a>
        <a class="pjd-tile pjd-tile--link" href="/projects/"><h3>See all projects →</h3><p>Every initiative across the Afrovanguard platform.</p></a>
      </div>
    </section>
  </div>
</main>
<?php render_footer();
