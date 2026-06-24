<?php
/**
 * diary/index.php — The Afrovanguard Diary (listing), rendered from the database.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

Sitemap::ensureFresh();
$repo      = new DiaryRepository();
$articles  = $repo->all();
$featured  = $repo->featured();
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
          <div class="diary-stat"><div class="num"><?= count($articles) ?></div><div class="lbl">Entries published</div></div>
          <div class="diary-stat"><div class="num">1M</div><div class="lbl">Leaders by 2040</div></div>
        </div>
        <p style="margin-top:22px"><a href="/diary/me/" style="display:inline-flex;align-items:center;gap:8px;font-weight:600;color:var(--gold,#b8860b);text-decoration:none;border-bottom:1px solid currentColor;padding-bottom:2px">✍️ Members — open your Vanguard Diary →</a></p>
      </div>
    </section>

    <div class="container">
      <div class="diary-controls">
        <div class="search-wrap"><?= Icons::SEARCH ?><input type="search" class="search-input" placeholder="Search the diary…  (press /)" aria-label="Search the diary" value="<?= e($q) ?>" /></div>
        <div class="diary-filters" role="tablist" aria-label="Filter entries">
          <button class="chip active" data-filter="all">All entries</button>
<?php foreach ($repo->categories() as $c): ?>
          <button class="chip" data-filter="<?= e($c['slug']) ?>"><?= e($c['name']) ?></button>
<?php endforeach; ?>
          <button class="chip" data-filter="saved">★ Saved</button>
        </div>
      </div>

<?php if ($featured): ?>
      <article class="featured" data-cat="<?= e($featured['category_slug']) ?>" data-slug="<?= e($featured['slug']) ?>" data-reveal>
<?php if (!empty($featured['cover_url'])): ?>
        <a class="feat-thumb has-cover" href="/diary/<?= e($featured['slug']) ?>/" style="background-image:url('<?= e($featured['cover_url']) ?>')"></a>
<?php else: ?>
        <a class="feat-thumb <?= e($featured['gradient']) ?> g-grain" href="/diary/<?= e($featured['slug']) ?>/"><span class="mc-title"><?= $featured['mc_title'] ?></span></a>
<?php endif; ?>
        <div>
          <span class="feat-flag">Featured · <?= e($featured['category']) ?></span>
          <h2><a href="/diary/<?= e($featured['slug']) ?>/"><?= e($featured['title']) ?></a></h2>
          <p><?= e($featured['dek']) ?></p>
          <div class="feat-meta"><?= $featured['authors_html'] ?> · <?= e($featured['published']) ?> · <?= (int)$featured['read_minutes'] ?> min read</div>
          <a class="btn btn-ink" href="/diary/<?= e($featured['slug']) ?>/">Read the dispatch →</a>
        </div>
      </article>
<?php endif; ?>

      <section class="diary-grid" aria-label="All diary entries">
<?php if (!$articles): ?>
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
<?php else: ?>
        <div class="post-grid">
<?php foreach ($articles as $a) { render_card($a); } ?>
        </div>
        <div class="no-results">No entries match your search yet. Try another term, or clear the filters.</div>
<?php endif; ?>
      </section>

      <section class="diary-subscribe-band" data-reveal>
        <div>
          <h2>Get the next dispatch</h2>
          <p>New field notes roughly twice a month. No spam — just the working, as we learn it. Or grab the <a href="<?= e(diary_url('feed.xml')) ?>">RSS feed</a>.</p>
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
<?php render_footer();
