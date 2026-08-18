<?php
/**
 * diary/index.php — The Afrovanguard Diary (listing), rendered from the database.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

Sitemap::ensureFresh();
$repo      = new DiaryRepository();
$PER_PAGE  = 12;
$first     = $repo->page(['limit' => $PER_PAGE]);   // first page only — the rest load progressively
$articles  = $first['items'];
$total     = $first['total'];
$facets    = $repo->facets();
$featured  = $repo->featured();
$seriesList = array_values(array_filter($repo->seriesList(), fn($s) => (int) ($s['n'] ?? 0) > 0));
$canonical = diary_url();
$q         = trim((string) ($_GET['q'] ?? ''));

// Structured data: a Blog with its posts, the site graph, and a breadcrumb.
$blog = [
    '@type'       => 'Blog',
    '@id'         => $canonical . '#blog',
    'url'         => $canonical,
    'name'        => 'The Afrovanguard Diary',
    'description' => 'Field notes, methodology, and the mission behind raising one million incorruptible leaders for Africa by 2040.',
    'publisher'   => ['@id' => SITE_URL . '/#organization'],
    'blogPost'    => array_map(fn($a) => [
        '@type' => 'BlogPosting',
        'headline' => $a['title'],
        'url' => diary_url($a['slug'] . '/'),
        'datePublished' => $a['published_at'],
        'articleSection' => $a['category'],
    ], $articles),
];
$crumbs = schema_breadcrumb([
    ['name' => 'Home', 'url' => rtrim(SITE_URL,'/').'/'],
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
]);
render_nav('diary');
?>
  <main id="main-content">
    <section class="diary-hero">
      <div class="container">
        <span class="diary-eyebrow">Est. 2018 · Alimosho, Lagos</span>
        <h1>The Afrovanguard<br/>Diary</h1>
        <p>Field notes, methodology, and the mission behind raising <strong>one million incorruptible leaders for Africa by 2040</strong>. We publish the working — what we are learning as we build the movement, including the parts we are still figuring out.</p>
        <div class="diary-stats">
          <div class="diary-stat"><div class="num">5,000+</div><div class="lbl">Lives transformed</div></div>
          <div class="diary-stat"><div class="num"><?= (int) $total ?></div><div class="lbl">Entries published</div></div>
          <div class="diary-stat"><div class="num">1M</div><div class="lbl">Leaders by 2040</div></div>
        </div>
        <p style="margin-top:22px"><a class="diary-cta-link" href="/diary/me/">✍️ Keep your own diary — start writing →</a></p>
      </div>
    </section>

    <div class="container">
      <div class="diary-controls" id="diaryControls" data-total="<?= (int) $total ?>" data-per="<?= (int) $PER_PAGE ?>">
        <div class="diary-controls-row">
          <div class="search-wrap"><?= Icons::SEARCH ?><input type="search" class="search-input" id="diarySearch" placeholder="Search the diary…  (press /)" aria-label="Search the diary" value="<?= e($q) ?>" /></div>
          <div class="diary-selects">
            <label class="diary-sel"><span class="diary-sel-lbl">Year</span>
              <select id="diaryYear" aria-label="Filter by year">
                <option value="">All years</option>
<?php foreach ($facets['years'] as $yr): ?>
                <option value="<?= e($yr) ?>"><?= e($yr) ?></option>
<?php endforeach; ?>
              </select>
            </label>
            <label class="diary-sel"><span class="diary-sel-lbl">Month</span>
              <select id="diaryMonth" aria-label="Filter by month" disabled>
                <option value="">All months</option>
              </select>
            </label>
          </div>
        </div>
        <div class="diary-filters" role="tablist" aria-label="Filter entries">
          <button class="chip active" data-filter="all">All<span class="chip-n"><?= (int) $total ?></span></button>
<?php foreach ($facets['categories'] as $c): ?>
          <button class="chip" data-filter="<?= e($c['slug']) ?>"><?= e($c['name']) ?><span class="chip-n"><?= (int) $c['n'] ?></span></button>
<?php endforeach; ?>
          <button class="chip" data-filter="saved">★ Saved</button>
        </div>
      </div>
      <script type="application/json" id="diaryFacets"><?= json_encode($facets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

<?php if ($seriesList): ?>
      <!-- Series highlight — multi-part collections, each linking to its landing page -->
      <section class="diary-series" aria-label="Series in the Diary">
        <div class="diary-series-head">
          <h2>Series</h2>
          <p>Multi-part collections — read them start to finish.</p>
        </div>
        <div class="diary-series-row">
<?php foreach ($seriesList as $s): ?>
          <a class="series-card" href="/diary/series/<?= e($s['slug']) ?>">
            <span class="series-card-kicker"><?= (int) $s['n'] ?>-part series</span>
            <span class="series-card-title"><?= e($s['title']) ?></span>
<?php if (!empty($s['description'])): ?>            <span class="series-card-dek"><?= e($s['description']) ?></span>
<?php endif; ?>
            <span class="series-card-go">Start reading →</span>
          </a>
<?php endforeach; ?>
        </div>
      </section>
<?php endif; ?>

<?php if ($total === 0): ?>
      <section class="diary-grid" aria-label="All diary entries">
        <div class="diary-empty">
          <h2>The first dispatch is on its way</h2>
          <p>We’re putting the finishing touches on the Diary. New field notes on the mission, our programmes and what we’re learning will land here soon.</p>
          <form class="diary-subscribe sub-inline" novalidate>
            <input type="email" name="email" placeholder="you@example.com" aria-label="Email address" autocomplete="email" required />
            <input type="text" name="hp" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
            <button type="submit" class="btn btn-primary">Notify me →</button>
            <p class="sub-msg" role="status" aria-live="polite"></p>
          </form>
        </div>
      </section>
<?php else: ?>
      <!-- The diary as a canvas "journey map": a winding, year-chaptered trail
           of entry markers that scales to a lot of entries (camera + culling).
           The visually-hidden <ul> is the crawlable, keyboard-navigable source
           of truth AND the List view; the canvas mirrors visible links. -->
      <section class="diary-journey" aria-label="The diary, entry by entry">
        <div class="journey-toolbar">
          <p class="journey-hint"><span id="journeyCount"><?= (int) $total ?></span> entries · newest first · tap a marker to read</p>
          <div class="journey-tools">
            <div class="journey-viewtoggle" role="group" aria-label="Choose a view">
              <button type="button" class="jv-btn is-active" data-view="map" aria-pressed="true">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l-6 3V6l6-3 6 3 6-3v15l-6 3-6-3z"/><path d="M9 3v15M15 6v15"/></svg>
                Map
              </button>
              <button type="button" class="jv-btn" data-view="list" aria-pressed="false">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
                List
              </button>
            </div>
            <button type="button" class="journey-fs" id="journeyFs" aria-pressed="false">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M8 21H5a2 2 0 0 1-2-2v-3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
              <span>Full screen</span>
            </button>
          </div>
        </div>
        <div class="journey-canvas-wrap" id="journeyWrap">
          <nav class="journey-rail" id="journeyRail" aria-label="Jump to a year" hidden></nav>
          <div class="journey-viewport" id="journeyViewport">
            <canvas id="journeyCanvas" class="journey-canvas" role="img" aria-label="A winding map of the diary entries — use the list view or the links below to navigate."></canvas>
            <div class="journey-spacer" id="journeySpacer" aria-hidden="true"></div>
          </div>
          <div class="journey-card" id="journeyCard" hidden aria-hidden="true"></div>
          <button type="button" class="journey-fs-close" id="journeyFsClose" aria-label="Exit full screen" hidden>✕</button>
          <ul class="journey-a11y" id="journeyList" aria-label="All diary entries">
<?php $n = $total; foreach ($articles as $i => $a): ?>
            <li><a href="/diary/<?= e($a['slug']) ?>/"
                   data-cat="<?= e($a['category_slug']) ?>" data-slug="<?= e($a['slug']) ?>"
                   data-search="<?= e(strtolower($a['title'] . ' ' . $a['category'] . ' ' . ($a['ref_code'] ?? ''))) ?>"
                   data-ref="<?= e($a['ref_code'] ?? '') ?>"
                   data-title="<?= e($a['title']) ?>" data-cat-name="<?= e($a['category']) ?>"
                   data-published="<?= e($a['published']) ?>" data-date="<?= e($a['published_at']) ?>"
                   data-min="<?= (int) $a['read_minutes'] ?>"
                   data-num="<?= $i === 0 ? '★' : ($n - $i) ?>" data-latest="<?= $i === 0 ? '1' : '0' ?>">
              <span class="je-num"><?= $i === 0 ? '★' : ($n - $i) ?></span>
              <span class="je-main">
                <span class="je-cat" data-c="<?= e($a['category_slug']) ?>"><?= e($a['category']) ?><?php if ($i === 0): ?> · Latest<?php endif; ?></span>
                <span class="je-title"><?= e($a['title']) ?></span>
                <span class="je-meta"><?= e($a['published']) ?><?php if ((int) $a['read_minutes']): ?> · <?= (int) $a['read_minutes'] ?> min read<?php endif; ?></span>
              </span>
              <span class="je-arrow" aria-hidden="true">→</span>
            </a></li>
<?php endforeach; ?>
          </ul>
        </div>
        <div class="no-results">No entries match your search yet. Try another term, or clear the filters.</div>
      </section>
      <div class="diary-feed-foot" id="diaryFeedFoot"<?= ($total > count($articles)) ? '' : ' hidden' ?>>
        <div class="diary-skeleton" id="diarySkeleton" hidden aria-hidden="true">
          <div class="sk-card"></div><div class="sk-card"></div><div class="sk-card"></div>
        </div>
        <button type="button" class="diary-loadmore" id="diaryLoadMore">Load more entries</button>
        <div id="diarySentinel" class="diary-sentinel" aria-hidden="true"></div>
      </div>
<?php endif; ?>

      <section class="diary-subscribe-band" id="subscribe" data-reveal>
        <div>
          <h2>Get the next dispatch</h2>
          <p>New field notes roughly twice a month. No spam — just the working, as we learn it. Or grab the <a href="<?= e(diary_url('feed.xml')) ?>">RSS feed</a>, or download the Diary as a <a href="<?= e(diary_url('export.php?format=book')) ?>">Journal</a> · <a href="<?= e(diary_url('export.php?format=md')) ?>">Markdown</a> · <a href="<?= e(diary_url('export.php?format=json')) ?>">JSON</a>.</p>
        </div>
        <form class="diary-subscribe sub-inline" novalidate>
          <input type="email" name="email" placeholder="you@example.com" aria-label="Email address" autocomplete="email" required />
          <input type="text" name="hp" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
          <button type="submit" class="btn btn-primary">Subscribe →</button>
          <p class="sub-msg" role="status" aria-live="polite"></p>
        </form>
      </section>
    </div>
  </main>
  <script src="/diary/feed.js" defer></script>
  <script src="/diary/journey.js" defer></script>
<?php render_footer();
