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
    http_response_code(404);
    render_head('Not found — The Afrovanguard Diary', 'This diary entry could not be found.', diary_url(), '', 'website');
    render_nav('diary');
    echo '<main id="main-content"><div class="container" style="padding:120px 0;text-align:center">'
       . '<h1 class="article-title" style="margin:0 auto 20px">Entry not found</h1>'
       . '<p style="color:var(--muted);font-size:18px">That dispatch isn\'t here. <a href="/diary/">Return to the Diary →</a></p></div></main>';
    render_footer();
    exit;
}

$canonical = diary_url($a['slug'] . '/');
$related   = $repo->relatedCards((int) $a['id']);

render_head($a['title'] . ' — The Afrovanguard Diary', $a['dek'], $canonical, $a['slug']);
render_nav('diary');
render_subbar($a['title'], $a['slug'], $canonical);
?>
  <main id="main-content">
    <article>
      <div class="article-wrap">
        <div class="container">
          <nav class="breadcrumb" aria-label="Breadcrumb"><a href="/diary/">The Diary</a><span class="sep">/</span><span><?= e($a['category']) ?></span></nav>
          <h1 class="article-title"><?= e($a['title']) ?></h1>
          <div class="article-meta">
            <div><div class="meta-label">Written by</div><div class="meta-value"><?= $a['authors_html'] ?></div></div>
            <div><div class="meta-label">Published</div><div class="meta-value"><?= e($a['published']) ?> · <?= (int)$a['read_minutes'] ?> min read</div></div>
          </div>
<?php render_listen_bar($a['slug'], $canonical); ?>
        </div>
      </div>

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
