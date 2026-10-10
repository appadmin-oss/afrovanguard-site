<?php
/**
 * diary/article.php — a single Diary entry, rendered from the database.
 * Reached via pretty URL /diary/<slug>/ (see diary/.htaccess) or ?slug=<slug>.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once __DIR__ . '/partials.php';   // the engagement blocks (drop-in)
require_once __DIR__ . '/reader.php';     // the reading rail
require_once AV_ROOT . '/partials/av-cover.php'; // the cover when an entry has no photo

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
$me        = LmsAuth::user();
$commentN  = $repo->commentCount((int) $a['id']);
$counts    = $repo->counts((int) $a['id']);
$author    = $repo->authorCard($a);
$audio     = $repo->audioMeta((int) $a['id']);
// $me is set above from LmsAuth::user().
$saved     = $repo->isSaved((int) $a['id'], $me ? (int) $me['id'] : 0);
$clapped   = $repo->myClaps((int) $a['id']) > 0;
$sections  = $a['sections'] ?? [];
$seriesNav = null;
if (!empty($a['series']) && ($a['series']['prev'] || $a['series']['next'])) {
    $sx = $a['series'];
    $seriesNav = [
        'name' => $sx['title'], 'part' => (int) ($sx['part'] ?: 0), 'of' => (int) $sx['count'],
        'prev' => $sx['prev'] ? ['title' => $sx['prev']['title'], 'url' => '/diary/' . $sx['prev']['slug'] . '/'] : null,
        'next' => $sx['next'] ? ['title' => $sx['next']['title'], 'url' => '/diary/' . $sx['next']['slug'] . '/'] : null,
    ];
}
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
    'csrf'      => true,
    'css'       => ['/assets/site/avd.css', '/assets/site/avd-pages.css', '/assets/site/av-tokens.css', '/assets/site/avcv.css', '/assets/site/avde.css'],
]);
render_nav('diary');
?>
<?php $format = $a['format'] ?? 'standard'; $isFeature = $format === 'feature' && $cover; ?>
  <main id="main" tabindex="-1" class="avd">
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
<?php if (!$isFeature): ?>
      <header class="avde-head">
        <div class="avh-topo" data-avh-topo="light" data-seed="7" aria-hidden="true"></div>
        <div class="avde-head-in">
          <nav aria-label="Breadcrumb"><ol class="avde-crumbs"><li><a href="/diary/">The Diary</a></li><li><a href="/diary/?cat=<?= e(rawurlencode((string) $a['category_slug'])) ?>" aria-current="page"><?= e($a['category']) ?></a></li></ol></nav>
          <h1><?= e($a['title']) ?></h1>
<?php if (trim((string) $a['dek']) !== ''): ?>
          <p class="avde-dek"><?= e($a['dek']) ?></p>
<?php endif; ?>
          <div class="avde-by">
            <span class="avde-by-who"><span class="avde-av" aria-hidden="true"><?= e(mb_substr((string) ($author['initials'] ?? 'A'), 0, 1)) ?></span><span class="avde-by-name"><?= av_byline_html($a['authors_html']) ?></span></span>
            <span><?= e($a['published']) ?> · <?= $readMin ?> min read</span>
<?php if ($counts !== null): ?>
            <span class="av-num"><?= e(number_format((int) $counts['views'])) ?> views</span>
            <a class="av-num avde-by-c" href="#comments"><?= (int) $counts['comments'] ?> <?= (int) $counts['comments'] === 1 ? 'comment' : 'comments' ?></a>
<?php endif; ?>
<?php if (!empty($a['ref_code'])): ?>
            <code class="avde-ref" title="Quote this code to identify this entry — it never changes"><?= e($a['ref_code']) ?></code>
<?php endif; ?>
          </div>
        </div>
        <figure class="avde-cover">
<?php if ($cover): ?>
          <img src="<?= e($cover) ?>" alt="<?= e($a['title']) ?>" loading="eager" fetchpriority="high">
<?php else: /* No photo: the shared cover graphic (partials/av-cover.php), never a blank. */ ?>
          <?= av_cover([
              'kind' => 'diary', 'title' => (string) $a['title'], 'category' => mb_strtolower((string) $a['category']),
              'readTime' => $readMin . ' min read', 'date' => substr((string) ($a['published_at'] ?? ''), 0, 10),
              'ratio' => '16:9', 'class' => 'avcv--fill',
          ]) ?>
<?php endif; ?>
        </figure>
      </header>
<?php else: ?>
      <div class="avde-feat-meta">
        <div class="avde-by">
          <span class="avde-by-who"><span class="avde-av" aria-hidden="true"><?= e(mb_substr((string) ($author['initials'] ?? 'A'), 0, 1)) ?></span><span class="avde-by-name"><?= av_byline_html($a['authors_html']) ?></span></span>
          <span><?= e($a['published']) ?> · <?= $readMin ?> min read</span>
<?php if ($counts !== null): ?>
          <span class="av-num"><?= e(number_format((int) $counts['views'])) ?> views</span>
          <a class="av-num avde-by-c" href="#comments"><?= (int) $counts['comments'] ?> <?= (int) $counts['comments'] === 1 ? 'comment' : 'comments' ?></a>
<?php endif; ?>
<?php if (!empty($a['ref_code'])): ?>
          <code class="avde-ref"><?= e($a['ref_code']) ?></code>
<?php endif; ?>
        </div>
      </div>
<?php endif; ?>

      <div class="avde-body">
        <div class="avd-grid<?= $sections ? '' : ' avde-norail' ?>">
<?php avd_rail($sections, $readMin); ?>
          <article class="avd-article article-body">
<?php avd_listen($a, $a['slug'], $audio); ?>
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

            <ul class="avd-tags" aria-label="Filed under">
              <li><a href="/diary/?cat=<?= e(rawurlencode($a['category_slug'])) ?>"># <?= e($a['category']) ?></a></li>
            </ul>
<?php avd_engagement($counts ?? ['claps' => (int) $a['claps'], 'comments' => $commentN], $clapped, $saved, $canonical, $a['title']); ?>
<?php avd_author($author); ?>
<?php avd_series($seriesNav); ?>
<?php avd_comments($a['slug'], $repo->threadCount((int) $a['id']), $repo->commentTree((int) $a['id'], 'top', false), av_csrf_token()); ?>
          </article>
        </div>
      </div>

      <div class="avde-mission"><?php avd_mission(); ?></div>
<?php $kr = $repo->keepReading((int) $a['id'], 3); if ($kr): ?>
      <section class="avde-kr" aria-labelledby="avde-kr-h">
        <div class="avh-topo" data-avh-topo="light" data-seed="11" aria-hidden="true"></div>
        <div class="avde-kr-in">
          <div class="avde-kr-head"><h2 id="avde-kr-h">Keep reading</h2><a href="/diary/">All entries →</a></div>
          <ul class="avde-kr-grid">
<?php foreach ($kr as $it): ?>
            <li><a class="avde-kcard" href="<?= e($it['url']) ?>">
              <div class="avde-kcard-img"><?php if ($it['cover'] !== ''): ?><img src="<?= e($it['cover']) ?>" alt="" loading="lazy" decoding="async"><?php else: ?><?= av_cover(['kind' => 'diary', 'title' => $it['title'], 'date' => substr($it['published_at'], 0, 10), 'ratio' => '1.91:1', 'class' => 'avcv--fill']) ?><?php endif; ?></div>
              <div class="avde-kcard-body">
                <span class="avde-kcard-cat"><?= e($it['category']) ?></span>
                <span class="avde-kcard-t"><?= e($it['title']) ?></span>
                <span class="avde-kcard-m av-num"><?= e($it['date']) ?> · <?= (int) $it['minutes'] ?> min read<?= $it['views'] ? ' · ' . e(avd_compact($it['views'])) . ' views' : '' ?></span>
              </div>
            </a></li>
<?php endforeach; ?>
          </ul>
        </div>
      </section>
<?php endif; ?>
    </article>
  </main>
  <div class="avd">
<?php if ($audio) avd_audio_bar(); ?>
<?php avd_highlight_toolbar(); ?>
  </div>
  <script src="/assets/site/avd-reader.js" defer></script>
  <script src="/assets/site/avd.js" defer></script>
<?php render_footer();
