<?php
/**
 * diary/index.php — The Afrovanguard Diary (listing), from the database.
 *
 * Design "Afrovanguard Diary": hero (title, lede, the latest entry large with
 * the next three beside it) → All entries ("Field Notes": stats, category
 * chips, sort, search, the card grid, load more) → Series → Mission CTA →
 * Subscribe. Styles assets/site/avdl.css, behaviour assets/site/avdl.js.
 *
 * Everything works without JavaScript: chips, sort and search are one GET
 * form (?cat=, ?sort=, ?q=, plus ?year=/&month= from archive links) and
 * "Load more entries" is a link to the next page. With JavaScript the same
 * controls filter in place and further pages load from /diary/api.php.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once __DIR__ . '/partials.php';   // avd_mission() (drop-in)
require_once __DIR__ . '/_avdl.php';

Sitemap::ensureFresh();
$repo     = new DiaryRepository();
$PER_PAGE = 12;

$sort  = (string) ($_GET['sort'] ?? 'latest');
if (!in_array($sort, ['latest', 'read', 'discussed'], true)) $sort = 'latest';
$cat   = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['cat'] ?? '')));
$q     = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 80));
$year  = substr(preg_replace('/\D/', '', (string) ($_GET['year'] ?? '')), 0, 4);
$month = preg_replace('/\D/', '', (string) ($_GET['month'] ?? ''));
if (strlen($month) === 1) $month = '0' . $month;
$month = substr($month, 0, 2);
$pageN = max(1, min(500, (int) ($_GET['page'] ?? 1)));
$filtered = ($cat !== '' || $q !== '' || $year !== '' || $month !== '');

$facets   = $repo->facets();
$featured = $repo->featured();
$all      = $repo->page(['limit' => 4, 'sort' => 'latest']);
$allTotal = (int) $all['total'];
/* The hero: the featured entry (else the latest) large, the next three beside it. */
$side = array_values(array_filter($all['items'], fn($a) => !$featured || $a['slug'] !== $featured['slug']));
$side = array_slice($side, 0, 3);

/* The grid. Unfiltered, the hero's large entry is not repeated in it; one
   extra row is read so the page stays full when it is dropped. */
$skip  = (!$filtered && $featured) ? $featured['slug'] : '';
$res   = $repo->page(['limit' => $PER_PAGE * $pageN + ($skip ? 1 : 0), 'offset' => 0, 'sort' => $sort,
                      'cat' => $cat, 'q' => $q, 'year' => $year, 'month' => $month]);
$items = array_values(array_filter($res['items'], fn($a) => $a['slug'] !== $skip));
$shownTotal = (int) $res['total'] - ($skip && $res['total'] > 0 ? 1 : 0);
$readCount  = count($res['items']);              // rows read, the API's next offset
$items = array_slice($items, 0, $PER_PAGE * $pageN);
$hasMore = $readCount < (int) $res['total'];

$ids    = $repo->idsForSlugs(array_merge(array_column($items, 'slug'), $featured ? [$featured['slug']] : []));
$counts = $ids ? $repo->countsFor(array_values($ids)) : [];
$cOf    = fn(array $a) => $counts[$ids[$a['slug']] ?? 0] ?? null;

$seriesList = array_values(array_filter($repo->seriesList(), fn($s) => (int) ($s['n'] ?? 0) > 0));
$series     = $seriesList[0] ?? null;

$canonical = diary_url();
$S = rtrim(SITE_URL, '/');
$qs = static function (array $over) use ($sort, $cat, $q, $year, $month): string {
    $p = array_filter(array_merge(['sort' => $sort === 'latest' ? '' : $sort, 'cat' => $cat, 'q' => $q, 'year' => $year, 'month' => $month], $over), 'strlen');
    return '/diary/' . ($p ? '?' . http_build_query($p) : '');
};

$blog = [
    '@type'       => 'Blog',
    '@id'         => $canonical . '#blog',
    'url'         => $canonical,
    'name'        => 'The Afrovanguard Diary',
    'description' => 'Field notes, methodology, and the mission behind raising one million incorruptible leaders for Africa by 2040.',
    'publisher'   => ['@id' => SITE_URL . '/#organization'],
    'blogPost'    => array_map(fn($a) => [
        '@type' => 'BlogPosting', 'headline' => $a['title'], 'url' => diary_url($a['slug'] . '/'),
        'datePublished' => $a['published_at'], 'articleSection' => $a['category'],
    ], array_slice($items, 0, 12)),
];
$crumbs = schema_breadcrumb([
    ['name' => 'Home', 'url' => $S . '/'],
    ['name' => 'The Diary', 'url' => $canonical],
]);

render_head([
    'title'     => 'The Afrovanguard Diary — Field notes from a youth movement',
    'desc'      => 'Field notes, methodology, and the mission behind raising one million incorruptible leaders for Africa by 2040. Honest dispatches from Afrovanguard.',
    'canonical' => $canonical,
    'og_kind'   => 'website',
    'image'     => $featured ? diary_url('og/' . $featured['slug'] . '.png') : null,
    'keywords'  => 'Afrovanguard, Afrovanguard Diary, youth leadership Nigeria, Alimosho, Lagos NGO, field notes',
    'jsonld'    => [schema_org(), schema_website(), $blog, $crumbs],
    'css'       => ['/assets/site/avd.css', '/assets/site/avcv.css', '/assets/site/avdl.css'],
    'robots'    => $filtered || $pageN > 1 ? 'noindex, follow' : null,
]);
render_nav('diary');
?>
  <main id="main" tabindex="-1" class="avdl">
    <header class="avdl-hero avdl-pad">
      <div class="avh-topo" data-avh-topo="light" data-seed="7" aria-hidden="true"></div>
      <div class="avdl-wrap">
        <nav aria-label="Breadcrumb"><ol class="avdl-crumbs"><li><a href="/">Home</a></li><li><span aria-current="page">Diary</span></li></ol></nav>
        <div class="avdl-hero-grid">
          <h1>The Afrovanguard Diary</h1>
          <div class="avdl-hero-side">
            <p>Field notes, methodology, and the mission behind raising one million incorruptible leaders by 2040. We publish the working — including the parts we are still figuring out.</p>
            <a class="avdl-own" href="/diary/me/">Keep your own diary — start writing →</a>
          </div>
        </div>
<?php if ($featured): ?>
        <div class="avdl-lead">
          <a class="avdl-feat" href="/diary/<?= e($featured['slug']) ?>/">
            <?= avdl_img($featured, '16:9') ?>
            <span class="avdl-feat-shade" aria-hidden="true"></span>
            <span class="avdl-feat-body">
              <span class="avdl-feat-kick">Latest <span class="avdl-sep" aria-hidden="true">|</span> <?= e($featured['category']) ?></span>
              <h2><?= e($featured['title']) ?></h2>
              <span class="avdl-feat-meta"><?= e(avdl_meta($featured)) ?></span>
            </span>
          </a>
<?php if ($side): ?>
          <ul class="avdl-side" aria-label="More recent entries">
<?php foreach ($side as $a): ?>
            <li><a class="avdl-side-card" href="/diary/<?= e($a['slug']) ?>/">
              <span class="avdl-side-img"><?= avdl_img($a, '1:1') ?></span>
              <span class="avdl-side-body">
                <span class="avdl-side-kick"><?= e($a['category']) ?></span>
                <span class="avdl-side-t"><?= e($a['title']) ?></span>
                <span class="avdl-side-meta"><?= e(avdl_meta($a)) ?></span>
              </span>
            </a></li>
<?php endforeach; ?>
          </ul>
<?php endif; ?>
        </div>
<?php endif; ?>
      </div>
    </header>

    <section class="avdl-all avdl-pad" aria-labelledby="avdl-all-h" id="entries">
      <div class="avdl-wrap">
        <div class="avdl-all-head">
          <div><div class="avdl-eyebrow">All entries</div><h2 class="avdl-h2" id="avdl-all-h">Field Notes</h2></div>
          <dl class="avdl-stats">
            <div><dt>Lives transformed</dt><dd>10,000+</dd></div>
            <div><dt>Entries published</dt><dd class="av-num"><?= number_format($allTotal) ?></dd></div>
            <div><dt>Leaders by 2040</dt><dd>1M</dd></div>
          </dl>
        </div>

        <form class="avdl-bar" method="get" action="/diary/#entries" role="search" aria-label="Filter the Diary" id="avdlForm"
              data-total="<?= (int) $shownTotal ?>" data-per="<?= (int) $PER_PAGE ?>" data-offset="<?= (int) $readCount ?>" data-skip="<?= e($skip) ?>">
          <?php /* Enter in the search box submits the first submit button: this one,
               so the chosen category travels with it (a chip pressed later in the
               form overrides the hidden value — the last field of a name wins). */ ?>
          <button type="submit" class="avdl-default" tabindex="-1" aria-hidden="true">Search</button>
          <input type="hidden" name="cat" value="<?= e($cat) ?>" data-avdl-cat>
<?php if ($year !== ''): ?>          <input type="hidden" name="year" value="<?= e($year) ?>">
<?php endif; ?>
<?php if ($month !== ''): ?>          <input type="hidden" name="month" value="<?= e($month) ?>">
<?php endif; ?>
          <div class="avdl-chips" role="group" aria-label="Category">
            <button type="submit" name="cat" value="" class="avdl-chip" aria-pressed="<?= $cat === '' ? 'true' : 'false' ?>">All</button>
<?php foreach ($facets['categories'] as $c): ?>
            <button type="submit" name="cat" value="<?= e($c['slug']) ?>" class="avdl-chip" aria-pressed="<?= $cat === $c['slug'] ? 'true' : 'false' ?>"><?= e($c['name']) ?></button>
<?php endforeach; ?>
            <button type="button" class="avdl-chip" data-avdl-saved aria-pressed="false" hidden>Saved</button>
          </div>
          <div class="avdl-tools">
            <select name="sort" class="avdl-sel" aria-label="Sort entries" data-avdl-sort>
<?php foreach (['latest' => 'Latest', 'read' => 'Most read', 'discussed' => 'Most discussed'] as $k => $t): ?>
              <option value="<?= $k ?>"<?= $k === $sort ? ' selected' : '' ?>><?= $t ?></option>
<?php endforeach; ?>
            </select>
            <input type="search" name="q" class="avdl-search" placeholder="Search the Diary" aria-label="Search the Diary" value="<?= e($q) ?>" maxlength="80" data-avdl-q>
            <noscript><button type="submit" class="avdl-go">Search</button></noscript>
          </div>
        </form>
<?php if ($year !== ''): ?>
        <p class="avdl-scope">Showing <?= e($month !== '' ? date('F', mktime(0, 0, 0, (int) $month, 1)) . ' ' : '') . e($year) ?> · <a href="<?= e($qs(['year' => '', 'month' => ''])) ?>">All dates</a></p>
<?php endif; ?>
        <p class="av-sr" role="status" aria-live="polite" data-avdl-status><?= (int) $shownTotal ?> entries</p>

        <ul class="avdl-grid" data-avdl-grid<?= $items ? '' : ' hidden' ?>>
<?php foreach ($items as $a) echo '          ' . avdl_card($a, $cOf($a)) . "\n"; ?>
        </ul>
        <div class="avdl-skel" data-avdl-skel hidden aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        <div class="avdl-empty" data-avdl-empty<?= $items ? ' hidden' : '' ?>>
<?php if ($allTotal === 0): ?>
          <p>The first dispatch is on its way. New field notes will land here soon.</p>
          <a class="avdl-clear" href="#subscribe">Get the next dispatch</a>
<?php else: ?>
          <p>No entries match your search yet.</p>
          <a class="avdl-clear" href="/diary/#entries" data-avdl-clear>Clear filters</a>
<?php endif; ?>
        </div>
        <div class="avdl-err" data-avdl-err hidden role="alert"><p>The next entries could not load.</p><button type="button" class="avdl-clear" data-avdl-retry>Try again</button></div>
        <div class="avdl-more"<?= $hasMore ? '' : ' hidden' ?> data-avdl-more>
          <a class="avdl-more-btn" href="<?= e($qs(['page' => (string) ($pageN + 1)])) ?>#entries" data-avdl-more-btn>Load more entries</a>
        </div>
      </div>
    </section>

<?php if ($series): ?>
    <section class="avdl-series-sec avdl-pad" aria-labelledby="avdl-series-h">
      <a class="avdl-series" href="/diary/series/<?= e($series['slug']) ?>">
        <span class="avdl-series-body">
          <span class="avdl-series-kick">Series · Read start to finish</span>
          <h2 id="avdl-series-h"><?= e($series['title']) ?></h2>
<?php if (!empty($series['description'])): ?>
          <span class="avdl-series-dek"><?= e($series['description']) ?></span>
<?php endif; ?>
          <span class="avdl-series-go">Start reading →</span>
        </span>
        <img src="/Images/storm3.png" alt="" loading="lazy" decoding="async">
      </a>
    </section>
<?php endif; ?>

    <div class="avdl-mission-sec avdl-pad"><div class="avdl-wrap"><?php avd_mission(); ?></div></div>

    <section class="avdl-sub avdl-pad" id="subscribe" aria-labelledby="avdl-sub-h">
      <div class="avh-topo" data-avh-topo="light" data-seed="11" aria-hidden="true"></div>
      <div class="avdl-sub-in">
        <h2 id="avdl-sub-h">Get the next dispatch</h2>
        <p>New field notes roughly twice a month. No spam — just the working, as we learn it.</p>
        <form class="avdl-sub-form" data-avdl-sub novalidate>
          <input type="email" name="email" required placeholder="you@example.com" aria-label="Email address" autocomplete="email">
          <input type="text" name="hp" class="avdl-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
          <button type="submit">Subscribe</button>
        </form>
        <p class="avdl-sub-msg" role="status" aria-live="polite" data-avdl-sub-msg></p>
        <div class="avdl-feeds">
          <a href="<?= e(diary_url('feed.xml')) ?>">RSS feed</a>
          <a href="<?= e(diary_url('export.php?format=book')) ?>">Journal</a>
          <a href="<?= e(diary_url('export.php?format=md')) ?>">Markdown</a>
          <a href="<?= e(diary_url('export.php?format=json')) ?>">JSON</a>
        </div>
      </div>
    </section>
  </main>
  <script src="/assets/site/avcv.js" defer></script>
  <script src="/assets/site/avdl.js" defer></script>
<?php render_footer();
