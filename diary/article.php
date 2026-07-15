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
render_subbar($a['title'], $a['slug'], $canonical);
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
            <div><div class="meta-label">Written by</div><div class="meta-value"><?= $a['authors_html'] ?></div></div>
            <div><div class="meta-label">Published</div><div class="meta-value"><?= e($a['published']) ?> · <?= $readMin ?> min read</div></div>
          </div>
<?php render_listen_bar($a['slug'], $canonical); ?>
<?php if (!empty($a['audio_url'])): ?>
          <figure class="article-audio" id="narration" data-narration>
            <button type="button" class="na-play" aria-label="Play narration">
              <svg class="na-ic-play" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
              <svg class="na-ic-pause" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" hidden><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
            </button>
            <div class="na-main">
              <figcaption class="na-label">Listen to this story <span>· narrated by a human</span></figcaption>
              <div class="na-bar" role="slider" tabindex="0" aria-label="Seek"><span class="na-progress"></span></div>
            </div>
            <span class="na-time">0:00</span>
            <button type="button" class="na-speed" aria-label="Playback speed">1×</button>
            <audio preload="none" src="<?= e($a['audio_url']) ?>"></audio>
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
        <div class="article-layout<?= empty($a['sections']) ? ' no-toc' : '' ?>">
<?php if (!empty($a['sections'])): ?>
          <aside class="toc" aria-label="On this page">
            <div class="toc-head">On this page</div>
            <ul class="toc-list">
<?php foreach ($a['sections'] as $s): ?>
              <li><a href="#<?= e($s['anchor']) ?>"><?= e($s['label']) ?></a></li>
<?php endforeach; ?>
            </ul>
            <div class="toc-actions">
              <button type="button" class="toc-btn toc-btn-primary" data-listen aria-label="Listen to this article">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg> Listen
              </button>
<?php if ($audioDl): ?>              <a class="toc-btn" href="/diary/audio.php?slug=<?= e($a['slug']) ?>" download>
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m0 0l-4-4m4 4l4-4M4 21h16"/></svg> Download audio
              </a>
<?php endif; ?>              <button type="button" class="toc-btn" onclick="window.print()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg> Print / PDF
              </button>
            </div>
          </aside>
<?php endif; ?>
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
<?= $a['body_html'] ?>

<?php if (!empty($a['series']) && ($a['series']['prev'] || $a['series']['next'])): $sx = $a['series']; ?>
            <nav class="series-nav" aria-label="Series navigation">
<?php if ($sx['prev']): ?>              <a class="series-step series-prev" href="/diary/<?= e($sx['prev']['slug']) ?>/"><span class="series-dir">← Previous in series</span><strong><?= e($sx['prev']['title']) ?></strong></a>
<?php else: ?>              <span class="series-step is-empty"></span>
<?php endif; ?>
<?php if ($sx['next']): ?>              <a class="series-step series-next" href="/diary/<?= e($sx['next']['slug']) ?>/"><span class="series-dir">Next in series →</span><strong><?= e($sx['next']['title']) ?></strong></a>
<?php endif; ?>
            </nav>
<?php endif; ?>

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

            <!-- Comments -->
            <section class="comments" id="comments" data-slug="<?= e($a['slug']) ?>" aria-label="Comments">
              <h2 class="comments-title">Comments <span class="comments-count" id="commentsCount"<?= $commentN ? '' : ' hidden' ?>><?= (int) $commentN ?></span></h2>
              <form class="comment-form" id="commentForm" autocomplete="on" novalidate>
<?php if ($me): ?>
                <p class="comment-as">Commenting as <strong><?= e($me['name']) ?></strong></p>
<?php else: ?>
                <div class="comment-row">
                  <input type="text" name="name" id="cName" placeholder="Your name" maxlength="120" aria-label="Your name" required />
                </div>
<?php endif; ?>
                <input type="text" name="hp" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
                <textarea name="body" id="cBody" rows="3" placeholder="Share a thought…" maxlength="4000" aria-label="Your comment" required></textarea>
                <div class="comment-actions">
                  <button type="submit" class="btn btn-primary">Post comment</button>
                  <span class="comment-msg" role="status" aria-live="polite"></span>
                </div>
              </form>
              <ol class="comment-list" id="commentList" aria-live="polite"></ol>
              <p class="comment-empty" id="commentEmpty" hidden>Be the first to comment.</p>
            </section>
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
