<?php
/**
 * people/organogram/index.php — /people/organogram/: how Afrovanguard is
 * organised.
 *
 * The structure is lib/Organogram.php (TREE, LINES, ALONGSIDE) laid over the
 * People map AV_TEAM_STRUCTURE, flattened by Organogram::model(), so this page
 * and /people/ always agree. The names are the People page's: only rows
 * av_team_public_rows() returns.
 *
 * ONE nested list is the page. Without script it is the full text outline:
 * every body and office, who holds it, who it answers to and its independent
 * lines in words (the primary, accessible version; UW / Minnesota State
 * accessible org chart guidance). organogram.js then draws the same list as:
 *   - a lean top-down tree on wide charts (container ≥ 900px): title and
 *     holder only, the governance spine open and the big groups collapsed
 *     behind disclosure buttons (aria-expanded), a details panel beside it;
 *   - a drill-down navigator on narrow charts or with larger text: one level
 *     of cards at a time, a breadcrumb and Back;
 *   - the text outline again on demand ("Text version").
 * Each node's .org-about holds its words; the panel is built from it.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/lib/Organogram.php';

$S = rtrim(SITE_URL, '/');
$pdo = Database::pdo();
$people = Organogram::people($pdo);
$holders = Organogram::holders($people);
$M = Organogram::model($holders);
$showBasis = Organogram::showBasis();

/* ---------- pieces ---------- */
$avatar = function (array $m): string {
    $tint = 'ppl-tint-' . av_person_tint($m['name']); $ini = e(av_person_initials($m['name']));
    $img = ($m['photo'] ?? '') !== '' ? '<img class="' . $tint . '" src="' . e($m['photo']) . '" alt="" data-ini="' . $ini . '" width="56" height="56" loading="lazy" decoding="async" />' : '';
    return '<span class="ppl-av org-av ' . $tint . '" aria-hidden="true">' . $ini . $img . '</span>';
};
/** A holder: a link to their profile with a small avatar (the outline and the panel). */
$whoLink = fn(array $m): string => '<a class="org-who" href="' . e(av_person_url($m)) . '">' . $avatar($m) . '<span class="org-who-n">' . e($m['name']) . '</span></a>';
/** The lean node's holder line: avatars and the first name, "+N" for the rest. Plain text: the links are in the words. */
$face = function (array $n) use ($avatar): string {
    $f = $n['face'];
    if (!$f) return '';
    $av = '';
    foreach (array_slice($f, 0, 3) as $m) $av .= $avatar($m);
    $more = count($f) - 1;
    return '<span class="org-avs">' . $av . '</span><span class="org-face-n">' . e($f[0]['name'])
         . ($more > 0 ? ' <span class="org-more"><span aria-hidden="true">+' . $more . '</span><span class="av-sr">and ' . $more . ' more</span></span>' : '') . '</span>';
};
/** The article references, only while the Constitution is public (then linked to it). */
$basis = function (array $b) use ($showBasis): string {
    if (!$showBasis) return '';
    return ' <a class="org-ref" href="/constitution/">' . e(Organogram::basisText($b)) . '</a>';
};
$treeBasis = [];
foreach (Organogram::nodes() as $id => $tn) $treeBasis[$id] = $tn['basis'];
$lineBasis = static function (string $say): array { foreach (Organogram::LINES as $l) if ($l['say'] === $say) return $l['basis']; return []; };

/** A node's words: its line, who holds it, who it answers to, its independent lines. */
$about = function (array $n) use ($M, $whoLink, $basis, $treeBasis, $lineBasis): string {
    $k = $n['key']; $h = '';
    if ($n['desc'] !== '') $h .= '<p class="org-d">' . e($n['desc']) . '</p>';
    $offs = $n['offices'];
    if (count($offs) === 1 && $offs[0][0] === $n['title']) {
        if ($offs[0][1]) $h .= '<p class="org-held"><span class="org-lab">Held by</span> ' . implode(' ', array_map($whoLink, $offs[0][1])) . '</p>';
    } elseif ($offs) {
        $h .= '<ul class="org-offices">';
        foreach ($offs as [$lab, $ms]) $h .= '<li><span class="org-o">' . e($lab) . '</span>' . ($ms ? '<span class="org-whos">' . implode('', array_map($whoLink, $ms)) . '</span>' : '') . '</li>';
        $h .= '</ul>';
    }
    if ($n['parent'] !== null && !$n['beside']) {
        $p = $M[$n['upTo'] ?? $n['parent']];
        $h .= '<p class="org-up" data-org-up="' . e($p['key']) . '"><span class="org-lab">Answers to</span> <a href="#org-' . e($p['key']) . '">' . e($p['title']) . '</a>'
            . ($n['say'] !== '' ? '<span class="org-say"> ' . e($n['say']) . $basis($treeBasis[$k] ?? []) . '</span>' : '') . '</p>';
    }
    if ($k === 'chief-officers') $h .= '<p class="org-say org-say--p">Until each office can be funded on its own, the Board may ask one person to hold more than one Chief Officer post, provided finance, compliance and safeguarding stay independent of the work they check, and every such combination is written down and reviewed each year.' . $basis(['X §3(4)']) . '</p>';
    $lines = Organogram::linesFor($k);
    if ($lines) {
        $h .= '<ul class="org-ind">';
        foreach ($lines as [$say, $other, $short]) {
            $h .= '<li' . ($other !== null && isset($M[$other]) ? ' data-org-to="' . e($other) . '"' : '') . ($short !== '' ? ' data-org-short="' . e($short) . '"' : '') . '><span class="org-dash-k" aria-hidden="true"></span><span class="av-sr">Independent line: </span>' . e($say) . $basis($lineBasis($say)) . '</li>';
        }
        $h .= '</ul>';
    }
    return $h;
};
/** What search looks through: the title, the office titles, the holders, short forms (CFO). */
$q = function (array $n): string {
    $w = [$n['title'], $n['aka']];
    foreach ($n['offices'] as [$lab, $ms]) { $w[] = $lab; foreach ($ms as $m) $w[] = $m['name']; }
    foreach ($n['face'] as $m) $w[] = $m['name'];
    return implode(' | ', array_unique(array_filter($w, 'strlen')));
};

/** A node and its children, recursively: the lean node, its words, its children. */
$render = function (string $k) use (&$render, $M, $face, $about, $q): string {
    $n = $M[$k]; $d = $n['depth'];
    $tag = $d <= 3 ? 'h' . (3 + $d) : 'p';
    $f = $face($n); $count = Organogram::countText($M, $k);
    $attrs = ' class="org-li org-li--' . e($n['kind']) . ' org-tier--' . e($n['tier']) . '" id="org-' . e($k) . '" data-org-key="' . e($k) . '" data-org-q="' . e($q($n)) . '"'
           . ($n['collapsible'] ? ' data-org-collapsible' : '') . ($n['beside'] ? ' data-org-beside' : '');
    $h = '<li' . $attrs . '>' . "\n"
       . '  <div class="org-node">'
       . ($n['beside'] ? '<p class="org-tag">Beside the ' . e($M[$n['parent']]['title']) . ', no line</p>' : '')
       . '<' . $tag . ' class="org-t" id="org-t-' . e($k) . '">' . e($n['title']) . '</' . $tag . '>'
       . ($f !== '' ? '<p class="org-face" id="org-f-' . e($k) . '">' . $f . '</p>' : '')
       . ($count !== '' ? '<p class="org-meta" id="org-m-' . e($k) . '">' . e($count) . '</p>' : '')
       . "</div>\n"
       . '  <div class="org-about" id="org-a-' . e($k) . '">' . $about($n) . "</div>\n";
    if ($n['kids']) {
        $h .= '  <ul class="org-kids" id="org-k-' . e($k) . '" data-org-layout="' . e($n['layout']) . '">' . "\n";
        foreach ($n['kids'] as $c) $h .= $render($c);
        $h .= "  </ul>\n";
    }
    return $h . "</li>\n";
};

render_head([
    'title' => 'Organogram — How Afrovanguard Is Organised · Afrovanguard',
    'desc'  => 'The organisational chart of Afrovanguard: the General Assembly, Governing Council, Trustees, Board, Executive and the seven Community Advancement Directorates.',
    'canonical' => $S . '/people/organogram/', 'css' => ['/people/people.css', '/people/organogram/organogram.css'],
    'jsonld' => [schema_org(), [
        '@type' => 'WebPage', '@id' => $S . '/people/organogram/#webpage', 'url' => $S . '/people/organogram/',
        'name' => 'Organogram', 'inLanguage' => 'en-NG', 'isPartOf' => ['@id' => $S . '/#website'], 'about' => ['@id' => $S . '/#organization'],
    ], schema_breadcrumb([
        ['name' => 'Home', 'url' => $S . '/'], ['name' => 'About', 'url' => $S . '/about.html'],
        ['name' => 'Our people', 'url' => $S . '/people/'], ['name' => 'Organogram'],
    ])],
]);
render_nav('people');
?>
  <main id="main" tabindex="-1" class="ppl org">
    <header class="ppl-hero org-hero">
      <div class="avh-topo" data-avh-topo="light" data-seed="11" aria-hidden="true"></div>
      <div class="ppl-wrap">
        <nav class="ppl-crumbs" aria-label="Breadcrumb"><ol class="av-crumbs"><li><a href="/">Home</a><span aria-hidden="true">›</span></li><li><a href="/about.html">About</a><span aria-hidden="true">›</span></li><li><a href="/people/">Our people</a><span aria-hidden="true">›</span></li><li><span aria-current="page">Organogram</span></li></ol></nav>
        <p class="ppl-eyebrow">Organogram</p>
        <h1>How Afrovanguard is <em>organised</em></h1>
        <p class="ppl-intro">Who answers to whom, from the General Assembly of our Members to the teams in every Directorate.</p>
        <div class="ppl-btns org-actions">
          <a class="ppl-btn" href="/people/organogram/download/">Download (PDF)</a>
          <button type="button" class="ppl-btn ppl-btn--line" data-org-print hidden>Print</button>
        </div>
      </div>
    </header>

    <section class="org-sec" aria-labelledby="org-chart-h">
      <div class="ppl-wrap">
        <h2 id="org-chart-h" class="av-sr">The chart</h2>
        <div class="org-bar" data-org-bar hidden>
          <div class="org-find" role="search" aria-label="Find on the chart">
            <label class="av-sr" for="org-q">Find a person or office</label>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input type="search" id="org-q" placeholder="Find a person or office" autocomplete="off" spellcheck="false" aria-describedby="org-live" />
            <ul class="org-res" id="org-res" aria-label="Matches" hidden></ul>
          </div>
          <div class="org-ctl">
            <button type="button" class="org-b" data-org-all="open">Expand all</button>
            <button type="button" class="org-b" data-org-all="close">Collapse all</button>
            <button type="button" class="org-b org-b--sw" data-org-lines aria-pressed="false"><span class="org-sw" aria-hidden="true"></span>Show independent lines</button>
            <button type="button" class="org-b" data-org-fit aria-pressed="false" hidden>Fit to width</button>
            <button type="button" class="org-b" data-org-text aria-pressed="false">Text version</button>
          </div>
          <p class="av-sr" id="org-live" role="status" aria-live="polite" aria-atomic="true"></p>
        </div>
        <p class="org-legend" id="org-legend"><span class="org-key"><span class="org-key-s" aria-hidden="true"></span>Solid line: answers to</span> <span class="org-key"><span class="org-key-d" aria-hidden="true"></span>Dashed line: an independent line</span></p>

        <div class="org-stage" data-org-stage>
          <nav class="org-path" aria-label="Chart path" data-org-path hidden></nav>
          <div class="org-body">
            <div class="org-scroll" role="region" aria-label="Organogram" aria-describedby="org-legend" tabindex="0">
              <div class="org-chart">
                <ul class="org-tree">
<?= $render('assembly') ?>
                </ul>
                <svg class="org-svg" aria-hidden="true" focusable="false"></svg>
              </div>
            </div>
            <dialog class="org-panel" id="org-panel" aria-labelledby="org-panel-h"></dialog>
          </div>
        </div>
      </div>
    </section>

    <section class="org-sec org-along" id="org-alongside" aria-labelledby="org-along-h">
      <div class="ppl-wrap">
        <h2 id="org-along-h">Alongside the structure</h2>
        <p class="org-along-p">These roles matter to Afrovanguard but hold no line of authority, so they sit beside the chart rather than in it.</p>
        <ul class="org-along-list">
<?php foreach (Organogram::ALONGSIDE as [$aid, $title, $line, $anchor, $b]): ?>
          <li id="org-along-<?= e($aid) ?>"><h3><?= e($title) ?></h3><p><?= e($line) ?><?= $basis($b) ?></p><?php if ($anchor !== ''): ?><a href="/people/#<?= e($anchor) ?>">See them on the People page<span class="av-sr">: <?= e($title) ?></span> <span aria-hidden="true">→</span></a><?php endif; ?></li>
<?php endforeach; ?>
        </ul>
        <p class="org-fine">Names on this chart are the people who appear on our public <a href="/people/">People page</a>. An office shown without a name is not yet listed publicly.</p>
      </div>
    </section>
  </main>
  <script src="<?= e(av_asset('/people/organogram/organogram.js')) ?>" defer></script>
<?php render_footer();
