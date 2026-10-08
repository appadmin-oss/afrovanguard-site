<?php
/**
 * diary/article.php — a single Diary entry, rendered from the database.
 * Reached via pretty URL /diary/<slug>/ (see diary/.htaccess) or ?slug=<slug>.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$raw  = (string) ($_GET['code'] ?? $_GET['slug'] ?? '');
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($raw));

$repo = new DiaryRepository();
$a = $slug ? $repo->bySlug($slug) : null;

// Not a slug? It may be a reference code. A code is a permanent handle — it keeps
// resolving after the slug has been rewritten — so it answers with a 301 to the
// entry's canonical URL rather than serving a second address for the same page.
if (!$a && $raw !== '') {
    $bySlug = $repo->slugForRefCode($raw);
    if ($bySlug !== '') {
        header('Location: ' . diary_url($bySlug . '/'), true, 301);
        exit;
    }
}

if (!$a) {
    require_once AV_ROOT . '/lib/errors.php';
    av_error_render(404);
    exit;
}

$canonical = diary_url($a['slug'] . '/');
$related   = $repo->relatedCards((int) $a['id']);
$me        = LmsAuth::user();
$commentN  = $repo->commentCount((int) $a['id']);
$audioDl   = class_exists('Tts') && Tts::available() && Tts::ext() === 'mp3' && Tts::engine() !== 'mock';
$ogImage   = !empty($a['og_image']) ? $a['og_image'] : diary_url('og/' . $a['slug'] . '.png');
$cover     = $a['cover_url'] ?? '';
$authorsText = trim(strip_tags($a['authors_html']));
// Accurate read time from the actual body (≈220 wpm silent reading), so the
// "min read" always matches the words on the page rather than a stale field.
$bodyWords = str_word_count(strip_tags(strip_tags($a['body_html'])));
$readMin   = $bodyWords > 0 ? max(1, (int) round($bodyWords / 220)) : max(1, (int) $a['read_minutes']);

// Structured data: the article, its breadcrumb, and the site graph.
$blogPosting = [
    '@type'            => 'BlogPosting',
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
    'headline'         => $a['title'],
    'description'      => $a['dek'],
    'image'            => [$ogImage],
    'datePublished'    => $a['published_at'],
    'dateModified'     => $a['published_at'],
    'author'           => ['@type' => 'Organization', 'name' => $authorsText ?: 'The Afrovanguard Team', 'url' => rtrim(SITE_URL,'/').'/about/'],
    'publisher'        => ['@id' => SITE_URL . '/#organization'],
    'articleSection'   => $a['category'],
    'wordCount'        => $bodyWords,
    'timeRequired'     => 'PT' . $readMin . 'M',
    'isPartOf'         => ['@id' => SITE_URL . '/#website'],
];
$crumbs = schema_breadcrumb([
    ['name' => 'Home', 'url' => rtrim(SITE_URL,'/').'/'],
    ['name' => 'The Diary', 'url' => diary_url()],
    ['name' => $a['title'], 'url' => $canonical],
]);

render_head([
    'title'     => $a['title'] . ' — The Afrovanguard Diary',
    'desc'      => $a['dek'],
    'canonical' => $canonical,
    'slug'      => $a['slug'],
    'og_kind'   => 'article',
    'image'     => $ogImage,
    'image_alt' => $a['title'],
    'published' => $a['published_at'],
    'section'   => $a['category'],
    'tags'      => [$a['category'], 'Afrovanguard', 'Alimosho', 'youth leadership'],
    'keywords'  => $a['category'] . ', Afrovanguard, Alimosho, Lagos, youth leadership, ' . strtolower($a['title']),
    'jsonld'    => [schema_org(), schema_website(), $blogPosting, $crumbs],
]);
render_nav('diary');
?>
<?php $format = $a['format'] ?? 'standard'; $isFeature = $format === 'feature' && $cover; ?>
  <main id="main-content">
    <article class="format-<?= e($format) ?>">
<?php if ($isFeature): $hcid = (int) ($a['cover_is_dark'] ?? -1); $heroTone = $hcid === 1 ? ' is-on-dark' : ($hcid === 0 ? ' is-on-light' : ''); ?>
      <header class="feature-hero<?= $heroTone ?>" style="background-image:url('<?= e($cover) ?>')">
        <div class="container">
          <nav class="breadcrumb" aria-label="Breadcrumb"><a href="/diary/">The Diary</a><span class="sep">/</span><span><?= e($a['category']) ?></span></nav>
          <h1><?= e($a['title']) ?></h1>
          <p class="feature-dek"><?= e($a['dek']) ?></p>
        </div>
      </header>
<?php endif; ?>
      <div class="article-wrap">
        <div class="container">
<?php if (!$isFeature): ?>
          <nav class="breadcrumb" aria-label="Breadcrumb"><a href="/diary/">The Diary</a><span class="sep">/</span><span><?= e($a['category']) ?></span></nav>
          <h1 class="article-title"><?= e($a['title']) ?></h1>
<?php endif; ?>
          <div class="article-meta">
            <div><div class="meta-label">Written by</div><div class="meta-value"><?= av_byline_html($a['authors_html']) ?></div></div>
            <div><div class="meta-label">Published</div><div class="meta-value"><?= e($a['published']) ?> · <?= $readMin ?> min read</div></div>
<?php if (!empty($a['ref_code'])): ?>
            <div><div class="meta-label">Reference</div><div class="meta-value"><code class="article-ref" title="Quote this code to identify this entry — it never changes"><?= e($a['ref_code']) ?></code></div></div>
<?php endif; ?>
          </div>
        </div>
      </div>

<?php if ($cover && !$isFeature): ?>
      <div class="container">
        <figure class="article-hero"><img src="<?= e($cover) ?>" alt="<?= e($a['title']) ?>" loading="eager" /></figure>
      </div>
<?php endif; ?>

      <div class="container">
        <div class="article-layout<?= empty($a['sections']) ? ' no-toc' : '' ?>">
          <div class="article-body">
<?php if (!empty($a['series'])): $sx = $a['series']; ?>
            <aside class="series-box" aria-label="Part of a series">
              <div class="series-box-head">
                <span class="series-kicker"><?= $sx['part'] ? 'Part ' . (int) $sx['part'] . ' of' : 'Part of' ?> a <?= (int) $sx['count'] ?>-part series</span>
                <a class="series-title" href="/diary/series/<?= e($sx['slug']) ?>"><?= e($sx['title']) ?></a>
              </div>
              <ol class="series-list">
<?php foreach ($sx['posts'] as $p): $cur = $p['slug'] === $a['slug']; ?>
                <li class="<?= $cur ? 'is-current' : '' ?>"><span class="series-n"><?= (int) $p['series_part'] ?: '•' ?></span><?php if ($cur): ?><span class="series-cur"><?= e($p['title']) ?> <em>· you’re here</em></span><?php else: ?><a href="/diary/<?= e($p['slug']) ?>/"><?= e($p['title']) ?></a><?php endif; ?></li>
<?php endforeach; ?>
              </ol>
            </aside>
<?php endif; ?>
<?= class_exists('IQ') ? IQ::embedShortcodes($a['body_html']) : $a['body_html'] ?>

<?php if (!empty($a['series']) && ($a['series']['prev'] || $a['series']['next'])): $sx = $a['series']; ?>
            <nav class="series-nav" aria-label="Series navigation">
<?php if ($sx['prev']): ?>              <a class="series-step series-prev" href="/diary/<?= e($sx['prev']['slug']) ?>/"><span class="series-dir">← Previous in series</span><strong><?= e($sx['prev']['title']) ?></strong></a>
<?php else: ?>              <span class="series-step is-empty"></span>
<?php endif; ?>
<?php if ($sx['next']): ?>              <a class="series-step series-next" href="/diary/<?= e($sx['next']['slug']) ?>/"><span class="series-dir">Next in series →</span><strong><?= e($sx['next']['title']) ?></strong></a>
<?php endif; ?>
            </nav>
<?php endif; ?>
          </div>
        </div>
      </div>

<?php if ($related): ?>
      <section class="similar">
        <div class="container">
          <h2>More from the Diary</h2>
          <div class="post-grid">
<?php foreach ($related as $r) { render_card($r); } ?>
          </div>
        </div>
      </section>
<?php endif; ?>
    </article>
  </main>
<?php render_footer();
