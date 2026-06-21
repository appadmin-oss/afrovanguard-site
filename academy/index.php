<?php
/**
 * academy/index.php — Afrovanguard Academy course catalogue (from the DB).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$repo = new AcademyRepository();
$courses = $repo->all();
$featured = $repo->featured();
$canonical = rtrim(SITE_URL, '/') . '/academy/';

$itemList = ['@type' => 'ItemList', 'itemListElement' => []];
foreach ($courses as $i => $c) {
    $itemList['itemListElement'][] = ['@type' => 'ListItem', 'position' => $i + 1,
        'url' => rtrim(SITE_URL, '/') . '/academy/' . $c['slug'] . '/', 'name' => $c['title']];
}
$crumbs = schema_breadcrumb([
    ['name' => 'Home', 'url' => rtrim(SITE_URL, '/') . '/'],
    ['name' => 'Academy', 'url' => $canonical],
]);

render_head([
    'title' => 'Afrovanguard Academy — Free programmes for young Africans',
    'desc'  => 'Technology, creative, and leadership programmes raising one million incorruptible leaders for Africa. Learn, build, and lead with Afrovanguard.',
    'canonical' => $canonical, 'og_kind' => 'website',
    'keywords' => 'Afrovanguard Academy, free training Lagos, Techome, MediaPro, Africa GATES, youth programmes Nigeria',
    'jsonld' => [schema_org(), schema_website(), $itemList, $crumbs],
    'css' => ['/academy/academy.css'], 'body_class' => 'academy',
]);
render_nav('academy');
?>
  <main id="main-content">
    <section class="ac-hero">
      <div class="container">
        <span class="diary-eyebrow">The Afrovanguard Academy</span>
        <h1>Learn. Build.<br/>Lead Africa.</h1>
        <p>Free, hands-on programmes in technology, the creative arts and leadership — the formation behind our goal of <strong>one million incorruptible leaders by 2040</strong>.</p>
        <div class="ac-hero-cta">
          <a class="btn btn-primary" href="#catalogue">Explore programmes ↓</a>
          <a class="btn btn-outline" href="https://cacentre.afrovanguard.org.ng/volunteer">Teach with us</a>
        </div>
      </div>
    </section>

    <div class="container" id="catalogue">
      <div class="diary-controls">
        <div class="search-wrap"><?= Icons::SEARCH ?><input type="search" class="search-input" placeholder="Search programmes…" aria-label="Search programmes" /></div>
        <div class="diary-filters" role="tablist" aria-label="Filter programmes">
          <button class="chip active" data-filter="all">All</button>
<?php foreach ($repo->categories() as $cat): ?>
          <button class="chip" data-filter="<?= e(slugify($cat)) ?>"><?= e($cat) ?></button>
<?php endforeach; ?>
        </div>
      </div>

      <section class="ac-grid" aria-label="Programmes">
<?php foreach ($courses as $c): render_course_card($c); endforeach; ?>
      </section>
      <div class="no-results">No programmes match your search.</div>
    </div>
  </main>
<?php echo '<script src="/academy/academy.js" defer></script>'; render_footer();
