<?php
/**
 * lib/search-page.php — /search.php as a page (search.php includes it for a
 * browser request). The query, the results and their ranking are SiteSearch's,
 * the same the JSON endpoint returns; this only draws them in the Home chrome.
 * Prefix avsr- (assets/site/avsr.css). Read-only, noindex.
 */
declare(strict_types=1);
require_once AV_ROOT . '/lib/partials.php';

$q     = trim((string) ($_GET['q'] ?? ''));
$qlen  = function_exists('mb_strlen') ? mb_strlen($q) : strlen($q);
$q     = $qlen > 120 ? (function_exists('mb_substr') ? mb_substr($q, 0, 120) : substr($q, 0, 120)) : $q;
$types = ['' => 'Everything', 'Page' => 'Pages', 'Diary' => 'Diary', 'Academy' => 'Academy', 'People' => 'People'];
$type  = (string) ($_GET['type'] ?? '');
if (!array_key_exists($type, $types)) $type = '';

$results = []; $failed = false;
if ($qlen >= 2) {
    try { $results = SiteSearch::query($q, 30, $type); }
    catch (\Throwable $e) { error_log('[search page] ' . $e->getMessage()); $failed = true; }
}
$href = static fn(array $over): string => '/search.php?' . http_build_query(array_filter(array_merge(['q' => $q, 'type' => $type], $over), static fn($v) => $v !== ''));

render_head([
    'title'     => ($q !== '' ? $q . ' — ' : '') . 'Search Afrovanguard',
    'desc'      => 'Search Afrovanguard: pages, the Diary, the Academy and our people.',
    'canonical' => rtrim(SITE_URL, '/') . '/search.php',
    'robots'    => 'noindex, follow',
    'og_kind'   => 'website',
    'css'       => ['/assets/site/avsr.css'],
]);
render_nav('');
?>
<main id="main" tabindex="-1" class="avsr">
  <header class="avsr-head">
    <div class="avsr-wrap">
      <p class="avsr-eyebrow">Search</p>
      <h1 class="avsr-h1">Search Afrovanguard</h1>
      <form class="avsr-form" role="search" action="/search.php" method="get">
        <label class="avsr-label" for="avsr-q">Search pages, the Diary, the Academy and our people</label>
        <div class="avsr-row">
          <input id="avsr-q" type="search" name="q" value="<?= e($q) ?>" minlength="2" maxlength="120" autocomplete="off" placeholder="Try “mentorship” or “Techome”" />
<?php if ($type !== ''): ?>          <input type="hidden" name="type" value="<?= e($type) ?>" />
<?php endif; ?>          <button type="submit" class="avsr-btn">Search</button>
        </div>
      </form>
    </div>
  </header>

  <section class="avsr-body" aria-labelledby="avsr-count">
    <div class="avsr-wrap">
<?php if ($qlen >= 2): ?>
      <nav class="avsr-types" aria-label="Filter results">
<?php foreach ($types as $k => $label): ?>        <a href="<?= e($href(['type' => $k])) ?>"<?= $k === $type ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
<?php endforeach; ?>      </nav>
<?php endif; ?>
<?php if ($failed): ?>
      <p id="avsr-count" class="avsr-count" role="status">Search is unavailable right now.</p>
      <p class="avsr-empty">Try again in a moment, or <a href="/contact.html">contact us</a> and we will point you to it.</p>
<?php elseif ($qlen < 2): ?>
      <p id="avsr-count" class="avsr-count" role="status">Type at least two letters to search.</p>
<?php elseif (!$results): ?>
      <p id="avsr-count" class="avsr-count" role="status">No results for “<?= e($q) ?>”.</p>
      <p class="avsr-empty">Check the spelling, try a broader word, or browse <a href="/diary/">the Diary</a> and <a href="/academy/">the Academy</a>.</p>
<?php else: ?>
      <p id="avsr-count" class="avsr-count" role="status"><?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?> for “<?= e($q) ?>”</p>
      <ol class="avsr-list">
<?php foreach ($results as $r): $off = (bool) preg_match('~^https?://~i', (string) $r['url']); ?>
        <li class="avsr-item">
          <span class="avsr-type"><?= e($r['type'] === 'People' ? 'Person' : $r['type']) ?></span>
          <a class="avsr-title" href="<?= e($r['url']) ?>"<?= $off ? ' target="_blank" rel="noopener"' : '' ?>><?= e($r['title']) ?><?= $off ? '<span class="avsr-ext" aria-hidden="true"> ↗</span><span class="sr-only"> (opens in a new tab)</span>' : '' ?></a>
<?php if (!empty($r['excerpt'])): ?>          <p class="avsr-excerpt"><?= e($r['excerpt']) ?></p>
<?php endif; ?>
        </li>
<?php endforeach; ?>
      </ol>
<?php endif; ?>
    </div>
  </section>
</main>
<?php render_footer();
