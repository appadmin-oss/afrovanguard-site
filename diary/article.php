<?php
/**
 * diary/article.php — a single Diary entry, rendered from the database.
 * Reached via pretty URL /diary/<slug>/ (see diary/.htaccess) or ?slug=<slug>.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$slug = (string) ($_GET['slug'] ?? '');
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));

$repo = new DiaryRepository();
$a = $slug ? $repo->bySlug($slug) : null;

if (!$a) {
    require_once AV_ROOT . '/lib/errors.php';
    av_error_render(404);
    exit;
}

$canonical = diary_url($a['slug'] . '/');
$related   = $repo->relatedCards((int) $a['id']);
$ogImage   = !empty($a['og_image']) ? $a['og_image'] : diary_url('og/' . $a['slug'] . '.png');
$cover     = $a['cover_url'] ?? '';
$authorsText = trim(strip_tags($a['authors_html']));

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
    'wordCount'        => str_word_count(strip_tags($a['body_html'])),
    'timeRequired'     => 'PT' . (int) $a['read_minutes'] . 'M',
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
render_subbar($a['title'], $a['slug'], $canonical);
?>
<?php $format = $a['format'] ?? 'standard'; $isFeature = $format === 'feature' && $cover; ?>
  <main id="main-content">
    <article class="format-<?= e($format) ?>">
<?php if ($isFeature): ?>
      <header class="feature-hero" style="background-image:url('<?= e($cover) ?>')">
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
            <div><div class="meta-label">Written by</div><div class="meta-value"><?= $a['authors_html'] ?></div></div>
            <div><div class="meta-label">Published</div><div class="meta-value"><?= e($a['published']) ?> · <?= (int)$a['read_minutes'] ?> min read</div></div>
          </div>
<?php render_listen_bar($a['slug'], $canonical); ?>
<?php if (!empty($a['audio_url'])): ?>
          <figure class="article-audio">
            <figcaption><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10v4h4l5 5V5L7 10H3Z"/><path d="M16.5 8.5a5 5 0 0 1 0 7"/></svg> Listen to this story <span>· narrated audio</span></figcaption>
            <audio controls preload="none" src="<?= e($a['audio_url']) ?>">Your browser doesn’t support audio — <a href="<?= e($a['audio_url']) ?>">download the narration</a>.</audio>
          </figure>
<?php endif; ?>
        </div>
      </div>

<?php if ($cover && !$isFeature): ?>
      <div class="container">
        <figure class="article-hero"><img src="<?= e($cover) ?>" alt="<?= e($a['title']) ?>" loading="eager" /></figure>
      </div>
<?php endif; ?>

      <div class="container">
        <div class="article-layout">
          <aside class="toc" aria-label="On this page">
            <div class="toc-head">On this page</div>
            <ul class="toc-list">
<?php foreach ($a['sections'] as $s): ?>
              <li><a href="#<?= e($s['anchor']) ?>"><?= e($s['label']) ?></a></li>
<?php endforeach; ?>
            </ul>
          </aside>
          <div class="article-body">
<?= $a['body_html'] ?>

            <div class="reactions">
              <button class="react-btn" data-react="<?= e($a['slug']) ?>" data-base="<?= (int)$a['claps'] ?>"><span class="emoji">👏</span> <span class="react-count"><?= (int)$a['claps'] ?></span></button>
              <button class="react-btn" data-share="<?= e($canonical) ?>"><span class="emoji">↗</span> Share</button>
              <span class="react-hint">Applause is saved server-side and shared by every reader</span>
            </div>
            <div class="byline-end">
              <span>Reply to any entry: <a href="mailto:cacentre@afrovanguard.org.ng">cacentre@afrovanguard.org.ng</a> — we read every message.</span>
              <span class="share-row">
                <a href="https://twitter.com/intent/tweet?url=<?= e($canonical) ?>" aria-label="Share on X" target="_blank" rel="noopener"><?= Icons::X ?></a>
                <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= e($canonical) ?>" aria-label="Share on LinkedIn" target="_blank" rel="noopener"><?= Icons::LI ?></a>
                <button data-share="<?= e($canonical) ?>" aria-label="Copy link"><?= Icons::SHARE ?></button>
              </span>
            </div>
          </div>
        </div>
      </div>

      <section class="similar" style="padding-top:8px">
        <div class="container">
          <div class="article-cta" data-reveal>
            <div>
              <h3>Build leaders Africa cannot buy.</h3>
              <p>The Diary documents the work — you can join it. Volunteer with a programme or fund a leader today.</p>
            </div>
            <div class="cta-actions">
              <a class="btn btn-primary" href="https://cacentre.afrovanguard.org.ng/volunteer">Join the Movement</a>
              <a class="btn btn-outline" href="<?= rtrim(SITE_URL,'/') ?>/donate.html">Fund a Leader</a>
            </div>
          </div>
        </div>
      </section>

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
