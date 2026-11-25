<?php
/**
 * lib/search-page.php — /search.php as a page (search.php includes it for a
 * browser request): the palette's "See all results", and the whole search
 * without JavaScript. The query, the results and their ranking are
 * SiteSearch's, the same the JSON endpoint returns; this draws them in the
 * Home chrome, grouped by type like the palette, with filter chips (and how
 * many each holds) and the matched words marked. Prefix avsr-
 * (assets/site/avsr.css). Read-only, noindex.
 */
declare(strict_types=1);
require_once AV_ROOT . '/lib/partials.php';

$q     = SiteSearch::normalise(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$qlen  = mb_strlen($q);
$type  = is_string($_GET['type'] ?? null) ? (string) $_GET['type'] : '';
if (!array_key_exists($type, SiteSearch::TYPES)) $type = '';

$all = []; $failed = false;
if ($qlen >= 2) {
    try { $all = SiteSearch::query($q, 60); }
    catch (\Throwable $e) { error_log('[search page] ' . $e->getMessage()); $failed = true; }
}
$counts = [];
foreach ($all as $r) $counts[$r['type']] = ($counts[$r['type']] ?? 0) + 1;
$results = $type === '' ? $all : array_values(array_filter($all, static fn($r) => $r['type'] === $type));
$suggest = ($qlen >= 2 && !$failed && !$all) ? SiteSearch::suggest($q) : '';
$groups = [];
foreach (array_keys(SiteSearch::TYPES) as $t) foreach ($results as $r) if ($r['type'] === $t) $groups[$t][] = $r;

$href = static fn(array $over): string => '/search.php?' . http_build_query(array_filter(array_merge(['q' => $q, 'type' => $type], $over), static fn($v) => $v !== ''));
$P = 'fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"';
$ICON = [
    'Page'         => '<path d="M7 3.5h7l4 4V20a.5.5 0 0 1-.5.5h-10A.5.5 0 0 1 7 20z"/><path d="M14 3.5V8h4M9.5 12.5h5M9.5 16h5"/>',
    'Academy'      => '<path d="M2.5 9 12 4.5 21.5 9 12 13.5z"/><path d="M6.5 11v4.5c1.5 1.5 3.5 2.2 5.5 2.2s4-.7 5.5-2.2V11M21.5 9v5"/>',
    'Project'      => '<path d="m12 3.5 8.5 4.5-8.5 4.5L3.5 8z"/><path d="m3.5 12 8.5 4.5 8.5-4.5M3.5 16l8.5 4.5 8.5-4.5"/>',
    'Event'        => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    'Diary'        => '<path d="M12 6.5c-2-1.5-4.5-2-8.5-2v14c4 0 6.5.5 8.5 2 2-1.5 4.5-2 8.5-2v-14c-4 0-6.5.5-8.5 2zM12 6.5v14"/>',
    'People'       => '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20c.8-3.6 3.6-5.5 7-5.5s6.2 1.9 7 5.5"/>',
    'Constitution' => '<path d="M12 4v16M7 20h10M5 7h14M5 7l-2.5 6a2.5 2.5 0 0 0 5 0zM19 7l-2.5 6a2.5 2.5 0 0 0 5 0z"/>',
    'ask'          => '<path d="M12 3.5c.6 3.9 2.6 5.9 6.5 6.5-3.9.6-5.9 2.6-6.5 6.5-.6-3.9-2.6-5.9-6.5-6.5 3.9-.6 5.9-2.6 6.5-6.5z"/><path d="M18.5 15.5c.3 1.6 1 2.3 2.5 2.5-1.5.3-2.2 1-2.5 2.5-.3-1.5-1-2.2-2.5-2.5 1.5-.2 2.2-.9 2.5-2.5z"/>',
];
$icon = static fn(string $k): string => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g ' . $P . '>' . ($ICON[$k] ?? $ICON['Page']) . '</g></svg>';
$popular = [['Academy', '/academy/', 'Free, hands-on programmes', 'Academy'], ['Projects', '/projects/', 'Our seven projects', 'Project'],
            ['Events', '/events/', 'What is coming up', 'Event'], ['The Diary', '/diary/', 'Field notes and stories', 'Diary'],
            ['Donate', '/donate.html', 'Give funds, materials, skills or time', 'Page'], ['Contact us', '/contact.html', 'Reach the team', 'Page']];

render_head([
    'title'     => ($q !== '' ? 'Results for “' . $q . '” — ' : '') . 'Search',
    'desc'      => 'Search Afrovanguard: pages, Academy courses, projects, events, the Diary and our people.',
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
      <h1 class="avsr-h1">Search</h1>
      <form class="avsr-form" role="search" action="/search.php" method="get">
        <label class="avsr-label" for="avsr-q">Search pages, Academy courses, projects, events, the Diary and our people</label>
        <div class="avsr-row">
          <span class="avsr-field"><svg class="avsr-field-i" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g <?= $P ?>><circle cx="10.75" cy="10.75" r="6.25"/><path d="m15.5 15.5 4.75 4.75"/></g></svg>
          <input id="avsr-q" type="search" name="q" value="<?= e($q) ?>" minlength="2" maxlength="120" autocomplete="off" placeholder="Try “mentorship” or “Techome”" /></span>
<?php if ($type !== ''): ?>          <input type="hidden" name="type" value="<?= e($type) ?>" />
<?php endif; ?>          <button type="submit" class="avsr-btn">Search</button>
        </div>
      </form>
    </div>
  </header>

  <section class="avsr-body" aria-labelledby="avsr-count">
    <div class="avsr-wrap">
<?php if ($qlen >= 2 && $all): ?>
      <nav class="avsr-types" aria-label="Filter results">
        <a href="<?= e($href(['type' => ''])) ?>"<?= $type === '' ? ' aria-current="true"' : '' ?>>All <span class="avsr-n"><?= count($all) ?></span></a>
<?php foreach (SiteSearch::TYPES as $k => $label): if (empty($counts[$k])) continue; ?>        <a href="<?= e($href(['type' => $k])) ?>"<?= $k === $type ? ' aria-current="true"' : '' ?>><?= e($label) ?> <span class="avsr-n"><?= (int) $counts[$k] ?></span></a>
<?php endforeach; ?>      </nav>
<?php endif; ?>
<?php if ($failed): ?>
      <p id="avsr-count" class="avsr-count" role="status">Search is unavailable right now.</p>
      <p class="avsr-empty">Try again in a moment, or <a href="/contact.html">contact us</a> and we will point you to it.</p>
<?php elseif ($qlen < 2): ?>
      <p id="avsr-count" class="avsr-count" role="status">Type at least two letters to search.</p>
      <h2 class="avsr-gh">Popular</h2>
      <ul class="avsr-list" role="list">
<?php foreach ($popular as [$t, $u, $d, $k]): ?>        <li class="avsr-item"><span class="avsr-tile"><?= $icon($k) ?></span><span class="avsr-txt"><a class="avsr-title" href="<?= e($u) ?>"><?= e($t) ?></a><span class="avsr-sub"><?= e($d) ?></span></span></li>
<?php endforeach; ?>      </ul>
<?php elseif (!$results): ?>
      <p id="avsr-count" class="avsr-count" role="status">No results for “<?= e($q) ?>”<?= $type !== '' ? ' in ' . e(SiteSearch::TYPES[$type]) : '' ?>.</p>
<?php if ($suggest !== ''): ?>      <p class="avsr-empty">Did you mean <a class="avsr-sugg" href="<?= e($href(['q' => $suggest, 'type' => ''])) ?>"><?= e($suggest) ?></a>?</p>
<?php endif; ?>      <p class="avsr-empty">Check the spelling, try a broader word, or browse <a href="/diary/">the Diary</a>, <a href="/academy/">the Academy</a>, <a href="/projects/">our projects</a> and <a href="/events/">events</a>.</p>
<?php else: ?>
      <p id="avsr-count" class="avsr-count" role="status"><?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?> for “<?= e($q) ?>”<?= $type !== '' ? ' in ' . e(SiteSearch::TYPES[$type]) : '' ?></p>
<?php foreach ($groups as $t => $rows): ?>
      <h2 class="avsr-gh"><?= e(SiteSearch::TYPES[$t] ?? $t) ?></h2>
      <ol class="avsr-list">
<?php foreach ($rows as $r): $off = (bool) preg_match('~^https?://~i', (string) $r['url']); ?>
        <li class="avsr-item">
          <span class="avsr-tile"><?= $icon((string) $r['type']) ?></span>
          <span class="avsr-txt">
            <a class="avsr-title" href="<?= e($r['url']) ?>"<?= $off ? ' target="_blank" rel="noopener"' : '' ?>><?= SiteSearch::highlight((string) $r['title'], $q) ?><?= $off ? '<span class="avsr-ext" aria-hidden="true"> ↗</span><span class="av-sr"> (opens in a new tab)</span>' : '' ?></a>
            <span class="avsr-sub"><span class="avsr-crumb"><?= e((string) ($r['crumb'] ?? $r['type'])) ?></span><?php if (!empty($r['excerpt'])): ?><span class="avsr-dot" aria-hidden="true">·</span><?= SiteSearch::highlight((string) $r['excerpt'], $q) ?><?php endif; ?></span>
          </span>
        </li>
<?php endforeach; ?>
      </ol>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($qlen >= 2 && !$failed): ?>
      <p class="avsr-ask" data-avs-ask-row hidden><button type="button" class="avsr-ask-btn" data-avs-ask="<?= e($q) ?>"><span class="avsr-tile avsr-tile--ask"><?= $icon('ask') ?></span><span class="avsr-txt"><span class="avsr-title">Ask Chioma about “<?= e($q) ?>”</span><span class="avsr-sub">Our assistant answers from what the site says</span></span></button></p>
<?php endif; ?>
    </div>
  </section>
</main>
<?php render_footer();
