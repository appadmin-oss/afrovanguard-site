<?php
/**
 * people/organogram/index.php — /people/organogram/: how Afrovanguard is
 * organised, as a chart.
 *
 * The structure is lib/Organogram.php (TREE, LINES, ALONGSIDE) laid over the
 * People map AV_TEAM_STRUCTURE, so this page and /people/ always agree. The
 * names are the People page's: only rows av_team_public_rows() returns.
 *
 * One nested list, drawn two ways by organogram.css:
 *   - from 1240px (and normal text size): a top-down tree, solid connectors in
 *     CSS; the independent lines are dashed notes at both ends, and
 *     organogram.js adds dashed connectors between ends that sit side by side;
 *   - below 1240px, or with larger text: an indented outline.
 * Group boxes and every Directorate are <details>, so the page works by
 * keyboard and without script. Print: one A4/Letter landscape page.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/lib/Organogram.php';

$S = rtrim(SITE_URL, '/');
$pdo = Database::pdo();
$people = Organogram::people($pdo);
$holders = Organogram::holders($people);
$nodes = Organogram::nodes();
$showBasis = Organogram::showBasis();

/* ---------- pieces ---------- */
$avatar = function (array $m): string {
    $tint = 'ppl-tint-' . av_person_tint($m['name']); $ini = e(av_person_initials($m['name']));
    $img = ($m['photo'] ?? '') !== '' ? '<img class="' . $tint . '" src="' . e($m['photo']) . '" alt="" data-ini="' . $ini . '" width="56" height="56" loading="lazy" decoding="async" />' : '';
    return '<span class="ppl-av org-av ' . $tint . '" aria-hidden="true">' . $ini . $img . '</span>';
};
/** The holders of one office: each a link to their profile with a small avatar. */
$who = function (string $body, string $office) use ($holders, $avatar): string {
    $out = '';
    foreach ($holders[$body . '/' . $office] ?? [] as $m) {
        $out .= '<a class="org-who" href="' . e(av_person_url($m)) . '">' . $avatar($m) . '<span class="org-who-n">' . e($m['name']) . '</span></a>';
    }
    return $out;
};
/** The article references, only while the Constitution is public (then linked to it). */
$basis = function (array $b) use ($showBasis): string {
    if (!$showBasis) return '';
    return ' <a class="org-ref" href="/constitution/">' . e(Organogram::basisText($b)) . '</a>';
};
/** The independent lines at one end, as dashed notes. */
$chips = function (string $id): string {
    $out = '';
    foreach (Organogram::linesAt($id) as [$text, $line, $end]) {
        $ref = $line['draw'] && $line['to'] !== null ? ' data-org-end="' . e($end) . '" data-org-pair="' . e($line['from'] . '>' . $line['to']) . '"' : '';
        $out .= '<li class="org-chip org-chip--' . e($line['kind']) . '"' . $ref . '><span class="org-chip-k" aria-hidden="true"></span>' . e($text) . '</li>';
    }
    return $out === '' ? '' : '<ul class="org-chips">' . $out . '</ul>';
};
/** A row: an office title, its holders, its notes. */
$row = function (string $id, string $title, string $whoHtml, string $extra = '') use ($chips): string {
    return '<li class="org-row" id="org-' . e($id) . '"><span class="org-o">' . e($title) . '</span>' . ($whoHtml !== '' ? '<span class="org-whos">' . $whoHtml . '</span>' : '') . $extra . $chips($id) . '</li>';
};
/** One Directorate or institution: expandable to its team. */
$unit = function (string $b, array $def) use ($who, $chips, $holders): string {
    $first = (string) array_key_first($def['offices']);
    $leadNames = array_map(fn($m) => $m['name'], $holders[$b . '/' . $first] ?? []);
    $team = '';
    foreach ($def['offices'] as $o => $lab) {
        $team .= '<li class="org-row"' . ($o === 'finance' ? ' id="org-' . e($b) . '-finance"' : '') . '><span class="org-o">' . e($lab) . '</span>'
              . (($w = $who($b, $o)) !== '' ? '<span class="org-whos">' . $w . '</span>' : '')
              . ($o === 'finance' ? $chips('directorate-finance') : '') . '</li>';
    }
    $isDir = (av_team_bodies()[$b]['group'] ?? '') === 'directorates';
    ob_start(); ?>
<li class="org-unit-li" id="org-unit-<?= e($b) ?>"><details class="org-unit">
  <summary><span class="org-u-t"><?= e($def['label']) ?></span><?php if ($leadNames): ?><span class="org-u-lead"><span class="av-sr">, </span><?= e(implode(', ', $leadNames)) ?></span><?php endif; ?><span class="av-sr"> — show the team</span></summary>
  <div class="org-team">
<?php if (!empty($def['desc'])): ?>    <p class="org-n"><?= e($def['desc']) ?></p>
<?php endif; ?>
    <ul class="org-rows"><?= $team ?></ul>
<?php if ($isDir): ?>    <p class="org-n org-n--sm">Named safeguarding people wherever children or vulnerable people are served.</p>
<?php endif; ?>
    <?= $chips('unit-' . $b) ?>
  </div>
</details></li>
<?php return (string) ob_get_clean();
};

/** A node of the tree, and its children, recursively. */
$render = function (array $n, int $h = 3) use (&$render, $nodes, $who, $chips, $row, $unit): string {
    $id = $n['id']; $label = Organogram::label($n); $note = Organogram::note($n);
    $kids = $n['children'] ?? [];
    $isGroup = in_array($n['kind'], ['offices', 'units', 'cmtees'], true);
    ob_start(); ?>
<li class="org-li org-li--<?= e($n['kind']) ?>" id="org-<?= e($id) ?>">
<?php if ($isGroup): ?>
  <details class="org-node org-group" open data-org-node="<?= e($id) ?>">
    <summary><h<?= $h ?> class="org-t"><?= e($label) ?></h<?= $h ?>></summary>
    <div class="org-group-b">
<?php if ($note !== ''): ?>      <p class="org-n"><?= e($note) ?></p>
<?php endif; ?>
      <?= $chips($id) ?>
      <ul class="org-rows">
<?php if ($n['kind'] === 'offices'): foreach (Organogram::offices($n) as $o => $lab) echo $row('office-' . $o, $lab, $who($n['body'], $o));
      elseif ($n['kind'] === 'cmtees'): foreach (Organogram::COMMITTEES as $k => $lab) echo $row('cmte-' . $k, $lab, '');
      else: foreach (Organogram::units($n) as $b => $def) echo $unit($b, $def); endif; ?>
      </ul>
    </div>
  </details>
<?php else: ?>
  <div class="org-node org-node--<?= e($n['kind']) ?><?= $n['parent'] === null ? ' org-node--top' : '' ?>" data-org-node="<?= e($id) ?>">
    <h<?= $h ?> class="org-t"><?= e($label) ?></h<?= $h ?>>
<?php if ($n['kind'] === 'office'): ?>
<?php if (($w = $who($n['body'], $n['office'])) !== ''): ?>    <p class="org-whos"><?= $w ?></p>
<?php endif; ?>
<?php elseif ($n['kind'] === 'body'): ?>
<?php if ($note !== ''): ?>    <p class="org-n"><?= e($note) ?></p>
<?php endif; ?>
    <ul class="org-rows org-rows--body">
<?php foreach (Organogram::offices($n) as $o => $lab) echo $row($n['body'] . '-' . $o, $lab, $who($n['body'], $o)); ?>
    </ul>
<?php else: ?>
    <p class="org-n"><?= e($note) ?></p>
<?php endif; ?>
    <?= $chips($id) ?>
  </div>
<?php if (!empty($n['aside'])): $ab = $n['aside']; $adef = av_team_bodies()[$ab]['def']; ?>
  <div class="org-aside" id="org-<?= e($ab) ?>">
    <p class="org-t org-t--sm"><?= e($adef['label']) ?></p>
    <p class="org-n">Beside the Trustees, with no trustee powers and no line of authority.</p>
<?php foreach ($adef['offices'] as $o => $lab) if (($w = $who($ab, $o)) !== '') echo '    <p class="org-whos">' . $w . "</p>\n"; ?>
  </div>
<?php endif; ?>
<?php endif; ?>
<?php if ($kids): ?>
  <ul class="org-kids">
<?php foreach ($kids as $k) echo $render($nodes[$k['id']]); ?>
  </ul>
<?php endif; ?>
</li>
<?php return (string) ob_get_clean();
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
        <p class="ppl-intro">From the General Assembly of our Members to the teams in every Directorate: who answers to whom, and the lines that stay independent.</p>
        <div class="ppl-btns org-actions">
          <a class="ppl-btn" href="/people/organogram/download/">Download (PDF)</a>
          <button type="button" class="ppl-btn ppl-btn--line" data-org-print hidden>Print</button>
          <a class="org-back" href="/people/">See everyone on the People page <span aria-hidden="true">→</span></a>
        </div>
      </div>
    </header>

    <section class="org-sec">
      <div class="ppl-wrap">
        <div class="org-sec-head">
          <h2 id="org-chart-h">The chart</h2>
          <div class="org-legend" id="org-legend">
            <p class="org-key"><span class="org-key-s" aria-hidden="true"></span>Solid line: answers to the body above</p>
            <p class="org-key"><span class="org-key-d" aria-hidden="true"></span>Dashed: an independent line, described at both ends</p>
            <p class="org-key org-key--note">The COO coordinates operations only; finance, safeguarding, audit and compliance keep their own lines.</p>
          </div>
        </div>
        <div class="org-scroll" role="region" aria-labelledby="org-chart-h" aria-describedby="org-legend" tabindex="0">
          <div class="org-chart">
            <ul class="org-tree">
<?= $render($nodes['assembly']) ?>
            </ul>
            <svg class="org-dash" aria-hidden="true" focusable="false"></svg>
          </div>
        </div>
      </div>
    </section>

    <section class="org-sec org-words" aria-labelledby="org-words-h">
      <div class="ppl-wrap">
        <h2 id="org-words-h">Every line, in words</h2>
        <div class="org-words-cols">
          <div>
            <h3>Who answers to whom</h3>
            <ul class="org-say">
<?php foreach (Organogram::solidLines() as $l): ?>              <li><?= e($l['say']) ?><?= $basis($l['basis']) ?></li>
<?php endforeach; ?>
            </ul>
          </div>
          <div>
            <h3>Independent lines</h3>
            <ul class="org-say org-say--dash">
<?php foreach (Organogram::LINES as $l): ?>              <li><?= e($l['say']) ?><?= $basis($l['basis']) ?></li>
<?php endforeach; ?>
              <li>Until each office can be funded on its own, the Board may ask one person to hold more than one Chief Officer post, provided finance, compliance and safeguarding stay independent of the work they check, and every such combination is written down and reviewed each year.<?= $basis(['X §3(4)']) ?></li>
            </ul>
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
        <p class="org-fine">Names on this chart are the people who appear on our public People page. An office shown without a name is not yet listed publicly.</p>
      </div>
    </section>
  </main>
  <script src="<?= e(av_asset('/people/organogram/organogram.js')) ?>" defer></script>
<?php render_footer();
