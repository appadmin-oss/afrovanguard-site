<?php
/**
 * diary/series.php — a Diary series landing: all posts in one series, in order.
 * Served at /diary/series/<slug> (see .htaccess + router.php).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$repo = new DiaryRepository();
$series = $slug ? $repo->seriesBySlug($slug) : null;

if (!$series) {
    http_response_code(404);
    render_head(['title' => 'Series not found — The Afrovanguard Diary', 'robots' => 'noindex']);
    render_nav('diary');
    echo '<main class="container" style="padding:80px 20px;text-align:center"><h1>Series not found</h1><p><a href="/diary/">← Back to the Diary</a></p></main>';
    render_footer();
    exit;
}

$posts     = $series['posts'];
$canonical = diary_url('series/' . $series['slug']);
$crumbs = schema_breadcrumb([
    ['name' => 'Home', 'url' => rtrim(SITE_URL, '/') . '/'],
    ['name' => 'The Diary', 'url' => diary_url()],
    ['name' => $series['title'], 'url' => $canonical],
]);

render_head([
    'title'     => $series['title'] . ' — a series in The Afrovanguard Diary',
    'desc'      => $series['description'] ?: ('A ' . count($posts) . '-part series in The Afrovanguard Diary: ' . $series['title'] . '.'),
    'canonical' => $canonical,
    'og_kind'   => 'website',
    'jsonld'    => [$crumbs],
]);
render_nav('diary');
?>
<main id="main-content">
  <section class="series-page">
    <div class="container">
      <nav class="breadcrumb" aria-label="Breadcrumb"><a href="/diary/">The Diary</a><span class="sep">/</span><span>Series</span></nav>
      <p class="series-eyebrow"><?= count($posts) ?>-part series</p>
      <h1 class="series-page-title"><?= e($series['title']) ?></h1>
<?php if (!empty($series['description'])): ?>      <p class="series-page-dek"><?= e($series['description']) ?></p>
<?php endif; ?>

<?php if ($posts): ?>
      <ol class="series-index">
<?php foreach ($posts as $i => $p): ?>
        <li class="series-index-item">
          <a href="/diary/<?= e($p['slug']) ?>/">
            <span class="series-index-n"><?= (int) $p['series_part'] ?: ($i + 1) ?></span>
            <span class="series-index-body">
              <span class="series-index-title"><?= e($p['title']) ?></span>
<?php if (!empty($p['dek'])): ?>              <span class="series-index-dek"><?= e($p['dek']) ?></span>
<?php endif; ?>
              <span class="series-index-meta"><?= e($p['published']) ?></span>
            </span>
          </a>
        </li>
<?php endforeach; ?>
      </ol>
<?php else: ?>
      <p class="series-page-empty">No published posts in this series yet.</p>
<?php endif; ?>
      <p class="series-back"><a href="/diary/">← All Diary entries</a></p>
    </div>
  </section>
</main>
<?php render_footer(); ?>
