<?php
/**
 * diary/index.php — The Afrovanguard Diary (listing), rendered from the database.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$repo      = new DiaryRepository();
$articles  = $repo->all();
$featured  = $repo->featured();
$canonical = diary_url();

render_head(
    'The Afrovanguard Diary — Field notes from a youth movement',
    'Field notes, methodology, and the mission behind raising one million incorruptible leaders for Africa by 2040. Honest dispatches from Afrovanguard.',
    $canonical, '', 'website'
);
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
      </div>
    </section>

    <div class="container">
      <div class="diary-controls">
        <div class="search-wrap"><?= Icons::SEARCH ?><input type="search" class="search-input" placeholder="Search the diary…  (press /)" aria-label="Search the diary" /></div>
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
        <a class="feat-thumb <?= e($featured['gradient']) ?> g-grain" href="/diary/<?= e($featured['slug']) ?>/"><span class="mc-title"><?= $featured['mc_title'] ?></span></a>
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
        <div class="post-grid">
<?php foreach ($articles as $a) { render_card($a); } ?>
        </div>
        <div class="no-results">No entries match your search yet. Try another term, or clear the filters.</div>
      </section>
    </div>
  </main>
<?php render_footer();
