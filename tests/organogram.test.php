<?php
/**
 * tests/organogram.test.php — the organogram (/people/organogram/, lib/Organogram.php).
 *
 *   - its structure comes from the People map (AV_TEAM_STRUCTURE) in the same order,
 *     so the two pages cannot disagree;
 *   - the solid lines nest as the Constitution reads (Art. VI–X, XIV, Schedule B);
 *   - the independent lines are present and written out at both ends;
 *   - names only for people the public People page may list;
 *   - no NGGC node; no constitutional text, references or links while the
 *     Constitution is members-only;
 *   - the page is complete without script; the PDF route works.
 *
 * Run via tests/run.php (provides ck() and render_page()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/people.php';
require_once AV_ROOT . '/lib/Organogram.php';
require_once AV_ROOT . '/lib/ConstitutionDoc.php';

$pdo = Database::pdo();
av_team_ensure($pdo);
$pdo->exec('DELETE FROM team');
$mk = static function (array $f) use ($pdo): int {
    $f += ['role' => '', 'tier' => 'volunteer', 'body' => '', 'office' => '', 'synced' => 0, 'active' => 1, 'listed_consent_at' => '', 'email' => '', 'photo_url' => ''];
    $cols = implode(',', array_keys($f));
    $pdo->prepare("INSERT INTO team ($cols) VALUES (" . implode(',', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
    return (int) $pdo->lastInsertId();
};
$pres   = $mk(['name' => 'Orgtest President Adaeze', 'role' => 'President and Chief Servant', 'tier' => 'management']);
$cfo    = $mk(['name' => 'Orgtest Finance Danjuma', 'body' => 'executive', 'office' => 'cfo']);
$pd     = $mk(['name' => 'Orgtest Director Bello', 'body' => 'techome', 'office' => 'project-director']);
$chair  = $mk(['name' => 'Orgtest Chair Amadi', 'body' => 'council', 'office' => 'chair']);
$consent= $mk(['name' => 'Orgtest Consented Trustee', 'body' => 'trustees', 'office' => 'trustee', 'synced' => 1, 'listed_consent_at' => '2026-11-01 10:00:00']);
$hidden = $mk(['name' => 'Orgtest Unconsented Coo', 'body' => 'executive', 'office' => 'coo', 'synced' => 1]);
$off    = $mk(['name' => 'Orgtest Inactive Cto', 'body' => 'executive', 'office' => 'cto', 'active' => 0]);
$sys    = $mk(['name' => 'Super Admin', 'body' => 'board', 'office' => 'chair']);
$nooff  = $mk(['name' => 'Orgtest Logistics Head', 'role' => 'Head of Logistics', 'tier' => 'management']);

$nodes = Organogram::nodes();

/* ══ One structure: the People map, in its order ══════════════════════ */
ck('organogram: the Chief Officers box is the Executive offices of the People map, in order, without the President and COO',
   array_keys(Organogram::offices($nodes['chief-officers'])) === array_values(array_diff(array_keys(AV_TEAM_STRUCTURE['executive']['bodies']['executive']['offices']), ['president', 'coo'])));
ck('organogram: the Directorates box is the seven Directorates of the map, in order',
   array_keys(Organogram::units($nodes['directorates'])) === array_keys(AV_TEAM_STRUCTURE['directorates']['bodies'])
   && count(Organogram::units($nodes['directorates'])) === 7);
ck('organogram: the Institutions box is the map\'s institutions (the Academy, the CACENTREs)',
   array_keys(Organogram::units($nodes['institutions'])) === array_keys(AV_TEAM_STRUCTURE['institutions']['bodies']));
ck('organogram: body and office titles are the People map\'s labels',
   Organogram::label($nodes['council']) === av_team_body_label('council') && Organogram::label($nodes['board']) === av_team_body_label('board')
   && Organogram::label($nodes['trustees']) === av_team_body_label('trustees')
   && Organogram::label($nodes['president']) === av_team_office_label('executive', 'president')
   && Organogram::label($nodes['coo']) === av_team_office_label('executive', 'coo'));
ck('organogram: every body or office node points at a real body/office of the map', (static function () use ($nodes): bool {
    foreach ($nodes as $n) {
        if (isset($n['body']) && !isset(av_team_bodies()[$n['body']])) return false;
        if (isset($n['office']) && av_team_office_label($n['body'], $n['office']) === '') return false;
        if (isset($n['group']) && !isset(AV_TEAM_STRUCTURE[$n['group']])) return false;
    }
    return true;
})());
ck('organogram: five Board committees, in constitutional order', array_values(Organogram::COMMITTEES) === ['Audit, Finance and Risk Committee',
   'Governance, Nominations and Succession Committee', 'Programmes, Impact and Safeguarding Committee', 'Technology, Research and Data Ethics Committee',
   'Partnerships and Resource Mobilisation Committee']);

/* ══ The solid lines ══════════════════════════════════════════════════ */
$parent = array_map(static fn($n) => $n['parent'], $nodes);
ck('organogram: General Assembly at the top', $parent['assembly'] === null && array_key_first($nodes) === 'assembly');
ck('organogram: Council and Trustees answer to the General Assembly', $parent['council'] === 'assembly' && $parent['trustees'] === 'assembly');
ck('organogram: Board under the Council; committees and President under the Board',
   $parent['board'] === 'council' && $parent['committees'] === 'board' && $parent['president'] === 'board');
ck('organogram: COO and the Chief Officers under the President (Art. X §2(1)(e)); Directorates and institutions under the COO (X §4(3))',
   $parent['coo'] === 'president' && $parent['chief-officers'] === 'president' && $parent['directorates'] === 'coo' && $parent['institutions'] === 'coo');
ck('organogram: Board children in order President, committees; President children COO, Chief Officers (the Chief Officers sit beside the committees)',
   array_column($nodes['board']['children'], 'id') === ['president', 'committees'] && array_column($nodes['president']['children'], 'id') === ['coo', 'chief-officers']);
ck('organogram: every solid line has a basis and a sentence', !array_filter(Organogram::solidLines(), static fn($l) => !$l['basis'] || trim($l['say']) === ''));
ck('organogram: the Honorary Institutional Custodians sit beside the Trustees with no line', ($nodes['trustees']['aside'] ?? '') === 'custodians' && !isset($nodes['custodians']));

/* ══ The independent lines ════════════════════════════════════════════ */
$ids = Organogram::allIds();
$pairs = array_map(static fn($l) => $l['from'] . '>' . ($l['to'] ?? ''), Organogram::LINES);
ck('organogram: CFO → Audit, Finance and Risk Committee (X §5(3))', in_array('office-cfo>cmte-afr', $pairs, true));
ck('organogram: Safeguarding Officer → Programmes, Impact and Safeguarding Committee (X §13(3))', in_array('office-safeguarding>cmte-pis', $pairs, true));
ck('organogram: CTO → Technology, Research and Data Ethics Committee (X §6(2)(j), Schedule A)', in_array('office-cto>cmte-trd', $pairs, true));
ck('organogram: General Counsel → the relevant governance body, no invented target (X §12(2)(f))', in_array('office-counsel>', $pairs, true));
ck('organogram: Trustees\' fiduciary oversight of the Board (Schedule A)', in_array('trustees>board', $pairs, true));
ck('organogram: Directorate Finance Officers → CFO (X §5(2)(c))', in_array('directorate-finance>office-cfo', $pairs, true));
ck('organogram: every line end is a node the chart draws', !array_filter(Organogram::LINES, static fn($l) =>
   ($l['from'] !== 'directorate-finance' && !in_array($l['from'], $ids, true)) || ($l['to'] !== null && !in_array($l['to'], $ids, true))));
ck('organogram: every line has a basis and a sentence, and drawn lines have words at both ends', !array_filter(Organogram::LINES, static fn($l) =>
   !$l['basis'] || trim($l['say']) === '' || ($l['draw'] && (empty($l['at_from']) || empty($l['at_to'])))));

/* ══ Holders: listable people only ════════════════════════════════════ */
$people = Organogram::people($pdo);
$h = Organogram::holders($people);
$names = static fn(string $k) => array_map(static fn($m) => $m['name'], $h[$k] ?? []);
ck('organogram holders: placed by av_team_placement (role text → President)', $names('executive/president') === ['Orgtest President Adaeze']);
ck('organogram holders: a set body/office wins', $names('executive/cfo') === ['Orgtest Finance Danjuma'] && $names('techome/project-director') === ['Orgtest Director Bello']);
ck('organogram holders: a consented synced row is shown', $names('trustees/trustee') === ['Orgtest Consented Trustee']);
ck('organogram holders: an unconsented synced row, an inactive row and a system account are not',
   !isset($h['executive/coo']) && !isset($h['executive/cto']) && !isset($h['board/chair']));
$raw = av_team_rows($pdo, false);
ck('organogram holders: raw rows passed straight in are filtered by the listing rule too', !isset(Organogram::holders($raw)['executive/coo']) && !isset(Organogram::holders($raw)['board/chair'])
   && isset(Organogram::holders($raw)['executive/cfo']));
ck('organogram holders: somebody in a body but no named office holds no office on the chart',
   !in_array('Orgtest Logistics Head', array_merge(...array_values(array_map(static fn($l) => array_column($l, 'name'), $h))), true));

/* ══ The page ═════════════════════════════════════════════════════════ */
$html = render_page(AV_ROOT . '/people/organogram/index.php');
$text = html_entity_decode(strip_tags((string) preg_replace('~<(script|style)\b.*?</\1>~s', '', $html)), ENT_QUOTES, 'UTF-8');
ck('organogram page: renders', str_contains($html, '<h1>How Afrovanguard is <em>organised</em></h1>') && str_contains($html, 'class="ppl org"') && !str_contains($html, 'render_page: threw'));
/* The chart model: one flattened list the page, the panel and search share. */
$Mo = Organogram::model($h);
ck('organogram model: tree order, the spine first, every group\'s members under it', (static function () use ($Mo): bool {
    $k = array_keys($Mo); $p = -1;
    foreach (['assembly', 'council', 'board', 'president', 'coo', 'directorates', 'unit-techome', 'techome-finance', 'institutions', 'chief-officers', 'office-cfo', 'committees', 'cmte-afr', 'trustees', 'custodians'] as $id) {
        $q = array_search($id, $k, true); if ($q === false || $q < $p) return false; $p = $q;
    }
    return $Mo['office-cfo']['parent'] === 'chief-officers' && $Mo['cmte-afr']['parent'] === 'committees' && $Mo['techome-finance']['parent'] === 'unit-techome';
})());
ck('organogram model: a group member answers to the group\'s parent (a Chief Officer to the President, a committee to the Board, a Directorate to the COO)',
   $Mo['office-cfo']['upTo'] === 'president' && $Mo['cmte-pis']['upTo'] === 'board' && $Mo['unit-sts']['upTo'] === 'coo' && $Mo['techome-deputy-director']['upTo'] === 'unit-techome');
ck('organogram model: the groups collapse and stack (Board committees, Chief Officers, Directorates, Institutions, each team); the spine does not',
   $Mo['chief-officers']['collapsible'] && $Mo['committees']['collapsible'] && $Mo['directorates']['collapsible'] && $Mo['unit-techome']['collapsible']
   && $Mo['chief-officers']['layout'] === 'stack' && $Mo['coo']['layout'] === 'stack' && !$Mo['board']['collapsible'] && !$Mo['president']['collapsible'] && $Mo['board']['layout'] === 'row');
ck('organogram model: one accent per tier', $Mo['council']['tier'] === 'gov' && $Mo['cmte-afr']['tier'] === 'gov' && $Mo['office-cto']['tier'] === 'exec' && $Mo['techome-finance']['tier'] === 'ops');
ck('organogram model: the Custodians sit beside the Trustees, with no line', $Mo['custodians']['beside'] && $Mo['trustees']['layout'] === 'beside');
ck('organogram model: counts on the groups', Organogram::countText($Mo, 'chief-officers') === '9 offices · 1 filled' && Organogram::countText($Mo, 'committees') === '5 committees'
   && Organogram::countText($Mo, 'directorates') === '7 directorates' && Organogram::countText($Mo, 'board') === '');
ck('organogram model: every independent line reaches both of its ends in words (the seven Finance Officers are one line at the CFO\'s end)', (static function (): bool {
    foreach (Organogram::LINES as $l) {
        foreach (Organogram::endKeys($l['from']) as $f) if (!in_array($l['say'], array_column(Organogram::linesFor($f), 0), true)) return false;
        if ($l['to'] !== null && !in_array($l['say'], array_column(Organogram::linesFor($l['to']), 0), true)) return false;
    }
    return count(array_filter(Organogram::linesFor('office-cfo'), static fn($x) => $x[1] === 'directorates')) === 1;
})());

ck('organogram page: one nested list, tree order in the markup (the outline is the page)', (static function () use ($html): bool {
    $p = -1;
    foreach (['assembly', 'council', 'board', 'president', 'coo', 'directorates', 'unit-sts', 'institutions', 'chief-officers', 'office-cfo', 'committees', 'cmte-afr', 'trustees', 'custodians'] as $id) {
        $q = strpos($html, 'id="org-' . $id . '"'); if ($q === false || $q < $p) return false; $p = $q;
    }
    return str_contains($html, '<ul class="org-tree">') && substr_count($html, 'class="org-kids"') === 19;
})());
ck('organogram page: every node is in the outline with a deep-link id and search words', (static function () use ($html, $Mo): bool {
    foreach ($Mo as $k => $n) if (!preg_match('~<li class="org-li[^"]*" id="org-' . preg_quote($k, '~') . '" data-org-key="' . preg_quote($k, '~') . '" data-org-q="[^"]+"~', $html)) return false;
    return true;
})());
ck('organogram page: search words carry titles, office titles, listed names and short forms', (bool) preg_match('~id="org-office-cfo" data-org-key="office-cfo" data-org-q="Chief Financial Officer \| cfo \| Orgtest Finance Danjuma"~', $html)
   && (bool) preg_match('~id="org-council" data-org-key="council" data-org-q="[^"]*Chairperson[^"]*Orgtest Chair Amadi~', $html));
ck('organogram page: groups say they collapse; the Custodians are marked beside', str_contains($html, 'id="org-chief-officers" data-org-key="chief-officers" data-org-q="Chief Officers" data-org-collapsible')
   && str_contains($html, 'data-org-beside') && str_contains($html, '<p class="org-tag">Beside the Registered Trustees, no line</p>'));
/* Lean nodes: a title, the holders' faces, a count. No description, no note callouts inside a node. */
$nodesHtml = preg_match_all('~<div class="org-node">(.*?)</div>\n~s', $html, $nm) ? $nm[1] : [];
ck('organogram page: lean nodes — title, holder and count only (' . count($nodesHtml) . ')', count($nodesHtml) === count($Mo) && !array_filter($nodesHtml, static fn($x) =>
   str_contains($x, 'org-d"') || str_contains($x, 'org-chip') || str_contains($x, 'org-ind') || str_contains($x, 'org-about') || str_contains($x, '<a ')));
ck('organogram page: a node shows its holder plainly (the profile link is in its words)', (bool) preg_match('~<p class="org-face" id="org-f-office-cfo"><span class="org-avs"><span class="ppl-av org-av ppl-tint-\d" aria-hidden="true">OD</span></span><span class="org-face-n">Orgtest Finance Danjuma</span></p>~', $html));
ck('organogram page: the count on a collapsed group', str_contains($html, '<p class="org-meta" id="org-m-chief-officers">9 offices · 1 filled</p>'));
ck('organogram page: every node\'s words sit beside it for the outline and the panel (description, holders, who it answers to, its lines)',
   str_contains($html, '<div class="org-about" id="org-a-council"><p class="org-d">Keeps the vision, the values and the long-term direction on course.</p>')
   && (bool) preg_match('~id="org-a-office-cfo"><p class="org-held"><span class="org-lab">Held by</span> <a class="org-who" href="/people/' . $cfo . '-orgtest-finance-danjuma/">~', $html)
   && str_contains($html, '<p class="org-up" data-org-up="president"><span class="org-lab">Answers to</span> <a href="#org-president">President and Chief Servant</a>')
   && str_contains($html, '<li data-org-to="cmte-afr" data-org-short="Independent line to the Audit, Finance and Risk Committee"><span class="org-dash-k" aria-hidden="true"></span><span class="av-sr">Independent line: </span>'));
ck('organogram page: names only for listable people', str_contains($html, 'Orgtest Consented Trustee') && !str_contains($html, 'Orgtest Unconsented Coo')
   && !str_contains($html, 'Orgtest Inactive Cto') && !str_contains($html, 'Super Admin'));
ck('organogram page: an unfilled office shows its title only, with no "Vacant"', str_contains($html, '<li><span class="org-o">Deputy Chairperson</span></li>') && stripos($text, 'vacant') === false);
ck('organogram page: every independent line is written out, at each end', (static function () use ($html): bool {
    foreach (Organogram::LINES as $l) if (substr_count($html, htmlspecialchars($l['say'], ENT_QUOTES)) < ($l['to'] === null ? 1 : 2)) return false;
    return true;
})());
ck('organogram page: the drawing is presentational (role="img", labelled, pointing at the Text version) and holds nothing focusable',
   (bool) preg_match('~<div class="org-viz" data-org-viz role="img" aria-label="[^"]*Text version[^"]*" tabindex="-1" hidden></div>~', $html)
   && !str_contains($html, 'class="org-svg"') && !str_contains($html, 'class="org-scroll"'));
ck('organogram page: a short legend line, no long notes, and a visible keyboard entry beside the drawing', str_contains($html, 'Solid line: answers to') && str_contains($html, 'Dashed line: an independent line')
   && str_contains($html, '<button type="button" class="org-kbd" data-org-kbd>Browse by keyboard</button>')
   && !str_contains($html, 'Every line, in words') && !str_contains($html, 'class="org-chip'));
ck('organogram page: one toolbar — script-only controls hidden until script runs (find, expand, collapse, fit, lines, PNG, text version), the PDF always there; the panel is an empty dialog',
   str_contains($html, '<div class="org-bar" data-org-bar>') && str_contains($html, '<div class="org-find" role="search" aria-label="Find on the chart" hidden>')
   && str_contains($html, '<label class="av-sr" for="org-q">Find a person or office</label>')
   && str_contains($html, 'data-org-all="open" data-org-chart-only hidden>Expand all</button>') && str_contains($html, 'data-org-all="close" data-org-chart-only hidden>Collapse all</button>')
   && str_contains($html, 'data-org-fit data-org-chart-only hidden>Fit</button>') && str_contains($html, 'data-org-lines aria-pressed="false" data-org-chart-only hidden>')
   && (bool) preg_match('~data-org-png data-org-chart-only hidden>.*?<span class="av-sr">Download </span>PNG</button>~', $html)
   && (bool) preg_match('~<a class="org-b" href="/people/organogram/download/">.*?<span class="av-sr">Download </span>PDF</a>~', $html)
   && str_contains($html, 'data-org-text aria-pressed="false" hidden>Text version</button>')
   && str_contains($html, 'id="org-live" role="status" aria-live="polite"') && str_contains($html, '<dialog class="org-panel" id="org-panel" aria-labelledby="org-panel-h"></dialog>')
   && str_contains($html, '<nav class="org-path" aria-label="Chart path" data-org-path hidden></nav>'));
/* The drawn chart's data: one JSON copy of the same model, CSP-safe (not executed, < escaped). */
$orgJson = preg_match('~<script type="application/json" id="org-data">(.*?)</script>~s', $html, $jm) ? json_decode($jm[1], true) : null;
ck('organogram data: the chart is fed from the model — every node once, in tree order, with its parent', is_array($orgJson)
   && array_column($orgJson['nodes'], 'id') === array_keys($Mo)
   && array_column($orgJson['nodes'], 'parentId', 'id') === array_map(static fn($n) => $n['parent'], $Mo)
   && !str_contains($jm[1], '<') && !str_contains($jm[1], '</'));
ck('organogram data: titles, tiers, groups and counts match the model; the Custodians are marked beside', is_array($orgJson) && (static function () use ($orgJson, $Mo): bool {
    foreach ($orgJson['nodes'] as $n) {
        $m = $Mo[$n['id']];
        if ($n['title'] !== $m['title'] || $n['tier'] !== $m['tier'] || $n['group'] !== $m['collapsible'] || $n['count'] !== Organogram::countText($Mo, $n['id']) || $n['kids'] !== count($m['kids'])) return false;
        if (array_column($n['holders'], 'name') !== array_column(array_slice($m['face'], 0, 3), 'name') || $n['more'] !== max(0, count($m['face']) - 1)) return false;
    }
    $c = array_column($orgJson['nodes'], null, 'id')['custodians'];
    return $c['beside'] === 'Registered Trustees' && $c['parentId'] === 'trustees';
})());
ck('organogram data: names only for listable people', is_array($orgJson) && str_contains($jm[1], 'Orgtest Finance Danjuma') && !str_contains($jm[1], 'Orgtest Unconsented Coo')
   && !str_contains($jm[1], 'Orgtest Inactive Cto') && !str_contains($jm[1], 'Super Admin') && !str_contains($jm[1], 'Orgtest Logistics Head'));
ck('organogram data: the independent lines, both ends on the chart (the seven Finance Officers as one line\'s seven starts); no words, references or NGGC',
   is_array($orgJson) && count($orgJson['lines']) === count(array_filter(Organogram::LINES, static fn($l) => $l['to'] !== null))
   && in_array(['from' => ['office-cfo'], 'to' => 'cmte-afr', 'kind' => 'finance'], $orgJson['lines'], true)
   && in_array(['from' => array_map(static fn($b) => $b . '-finance', array_keys(AV_TEAM_STRUCTURE['directorates']['bodies'])), 'to' => 'office-cfo', 'kind' => 'finance'], $orgJson['lines'], true)
   && !array_filter($orgJson['lines'], static fn($l) => array_diff($l['from'], array_keys($Mo)) || !isset($Mo[$l['to']]))
   && stripos($jm[1], 'NGGC') === false && !preg_match('~Art\. [IVX]+ §|Schedule [AB]~', $jm[1]));
/* The library: self-hosted, pinned, licensed, loaded by this page only, on demand. */
$libs = ['d3@7.9.0/d3.min.js' => 'd3@7.9.0/LICENSE', 'd3-flextree@2.1.2/d3-flextree.min.js' => 'd3-flextree@2.1.2/LICENSE', 'd3-org-chart@3.1.1/d3-org-chart.min.js' => 'd3-org-chart@3.1.1/LICENSE.md'];
ck('organogram library: d3 7.9.0, d3-flextree 2.1.2 and d3-org-chart 3.1.1 are vendored with their licences', (static function () use ($libs): bool {
    foreach ($libs as $js => $lic) if (!is_file(AV_ROOT . '/assets/vendor/' . $js) || filesize(AV_ROOT . '/assets/vendor/' . $js) < 5000 || !is_file(AV_ROOT . '/assets/vendor/' . $lic)) return false;
    return str_contains((string) file_get_contents(AV_ROOT . '/assets/vendor/d3@7.9.0/d3.min.js'), 'd3js.org v7.9.0')
        && str_contains((string) file_get_contents(AV_ROOT . '/assets/vendor/d3-org-chart@3.1.1/LICENSE.md'), 'MIT License')
        && str_contains((string) file_get_contents(AV_ROOT . '/assets/vendor/d3@7.9.0/LICENSE'), 'Mike Bostock')
        && str_contains((string) file_get_contents(AV_ROOT . '/assets/vendor/d3-flextree@2.1.2/LICENSE'), 'DO WHAT THE');
})());
ck('organogram library: the page names the stamped files, in load order, for the script to fetch on first use (no CDN)',
   (bool) preg_match('~data-org-lib="\[&quot;/assets/vendor/d3@7\.9\.0/d3\.min\.js\?v=[0-9a-f]{10}&quot;,&quot;/assets/vendor/d3-flextree@2\.1\.2/d3-flextree\.min\.js\?v=[0-9a-f]{10}&quot;,&quot;/assets/vendor/d3-org-chart@3\.1\.1/d3-org-chart\.min\.js\?v=[0-9a-f]{10}&quot;\]"~', $html)
   && !preg_match('~<script[^>]+src="https?://[^"]*(d3|org-chart)~i', $html) && !str_contains($html, 'cdn.jsdelivr.net/npm/d3'));
ck('organogram library: no other page loads it', (static function (): bool {
    foreach (['/people/index.php', '/index.html', '/about.html'] as $f) if (str_contains((string) @file_get_contents(AV_ROOT . $f), 'd3-org-chart')) return false;
    return true;
})());
$main = (string) (preg_match('~<main\b.*?</main>~s', $html, $mm) ? $mm[0] : '');
ck('organogram page: no NGGC node or name in the chart (the site menu is not the chart)', $main !== '' && stripos($main, 'NGGC') === false && stripos($main, 'Next Generation Genius') === false);
ck('organogram page: no Constitution link or article references while it is members-only',
   ConstitutionDoc::PUBLIC || (!str_contains($html, '/constitution/') && !preg_match('~\bArt\. [IVX]+ §~', $html) && !str_contains($html, 'class="org-ref"')));
/* No run of eight words of the Constitution's text on the page (names of bodies are short). */
$grams = static function (string $s, int $n = 8): array {
    $w = preg_split('/[^a-z0-9’\']+/', strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $w = array_values(array_filter($w, static fn($x) => strlen($x) > 1 || $x === 'a'));
    $out = [];
    for ($i = 0; $i + $n <= count($w); $i++) $out[implode(' ', array_slice($w, $i, $n))] = 1;
    return $out;
};
$cg = $grams(ConstitutionDoc::markdown());
$hit = array_keys(array_intersect_key($grams($text), $cg));
ck('organogram page: quotes no eight-word run of the Constitution' . ($hit ? ' — ' . $hit[0] : ''), ConstitutionDoc::PUBLIC || !$hit);
ck('organogram page: the hero is quiet (the PDF is in the toolbar); the script is stamped and deferred',
   !str_contains($html, 'data-org-print') && !str_contains($html, 'org-actions')
   && (bool) preg_match('~<script src="/people/organogram/organogram\.js\?v=[0-9a-f]{10}" defer></script>~', $html)
   && str_contains($html, 'href="/people/organogram/download/">'));
ck('organogram page: the profile anchors exist on the page', (static function () use ($html): bool {
    foreach ([['body' => 'executive', 'office' => 'cfo'], ['body' => 'techome', 'office' => ''], ['body' => 'council', 'office' => 'chair'], ['body' => 'custodians', 'office' => 'custodian'],
              ['body' => 'honorary', 'office' => 'patron'], ['body' => 'official-volunteers', 'office' => ''], ['body' => 'executive', 'office' => '']] as $p) {
        if (!str_contains($html, 'id="org-' . Organogram::anchorFor($p) . '"')) return false;
    }
    return true;
})());
ck('organogram page: breadcrumb and canonical', str_contains($html, '<li><a href="/people/">Our people</a><span aria-hidden="true">›</span></li><li><span aria-current="page">Organogram</span>')
   && str_contains($html, 'rel="canonical" href="' . rtrim(SITE_URL, '/') . '/people/organogram/"'));
$css = (string) file_get_contents(AV_ROOT . '/people/organogram/organogram.css');
$js = (string) file_get_contents(AV_ROOT . '/people/organogram/organogram.js');
ck('organogram css: a landscape print page, the chrome hidden', str_contains($css, '@page{size:A4 landscape') && str_contains($css, '.avh-head,.avh-foot'));
ck('organogram css: the stage\'s own width picks the view (container query, media fallback); larger text always gets the navigator',
   str_contains($css, '@container orgstage (min-width:700px){.org-body{--org-mode:chart}}') && str_contains($css, '@supports not (container-type:inline-size){@media (min-width:760px)')
   && str_contains($css, 'html[class*="a11y-t"] .org-body{--org-mode:nav!important}'));
ck('organogram css: the drawer overlays the chart (absolute, from the right), a bottom sheet when modal; reduced motion stops its slide',
   str_contains($css, '.is-chart .org-panel[open]:not(:modal){position:absolute;') && str_contains($css, '.org-panel:modal{position:fixed;inset:auto 0 0 0;')
   && str_contains($css, '@media (prefers-reduced-motion:reduce){.org *,.org *::after{transition:none!important;animation:none!important}}'));
ck('organogram css: without script the outline shows every node\'s words; in chart view the list steps aside for the drawing', str_contains($css, '.is-chart .org-tree{display:none}') && !preg_match('~(^|\})\.org-about\{display:none~', $css));
ck('organogram js: d3-org-chart drives the drawing (compact, highlight-to-root, connections, library export); no role="tree"; deep links with replaceState; polite announcements; a typo-tolerant find; motion off when asked',
   str_contains($js, 'new window.d3.OrgChart()') && str_contains($js, '.compact(true)') && str_contains($js, 'chart.setUpToTheRootHighlighted(sel)') && str_contains($js, 'chart.connections(connections())')
   && str_contains($js, 'chart.downloadImage(') && str_contains($js, "matchMedia('(prefers-reduced-motion: reduce)')") && str_contains($js, ".duration(still() ? 0 : 320)")
   && !preg_match('~role["\x27]?\s*[=,]\s*["\x27]tree~', $js) && str_contains($js, 'history.replaceState')
   && str_contains($js, 'function within1(') && str_contains($js, "showModal()") && str_contains($js, "'Back'") && str_contains($js, "JSON.parse(document.getElementById('org-data').textContent)"));

/* ══ Routes, links, sitemap, search ═══════════════════════════════════ */
$router = (string) file_get_contents(AV_ROOT . '/router.php');
ck('organogram routes: dev router and Apache both serve the page and the PDF', str_contains($router, "'~^/people/organogram/?$~'") && str_contains($router, "'~^/people/organogram/download/?$~'")
   && str_contains((string) file_get_contents(AV_ROOT . '/people/organogram/.htaccess'), 'RewriteRule ^download/?$ download.php')
   && strpos($router, 'people/organogram/?$') < strpos($router, "'~^/people/([0-9]+)"));
$dir = render_page(AV_ROOT . '/people/index.php');
ck('organogram: the People page links to it quietly from the toolbar', str_contains($dir, '<a class="ppl-chartlink" href="/people/organogram/">See the organogram <span aria-hidden="true">→</span></a>'));
$prof = render_page(AV_ROOT . '/people/index.php', ['id' => (string) $cfo]);
ck('organogram: a profile\'s "where they sit" links to its place on the chart', str_contains($prof, 'href="/people/organogram/#org-office-cfo"'));
ck('organogram: in the sitemap and in site search', str_contains((string) file_get_contents(AV_ROOT . '/sitemap.xml'), '<loc>https://afrovanguard.org.ng/people/organogram/</loc>')
   && str_contains((string) file_get_contents(AV_ROOT . '/lib/Sitemap.php'), "['people/organogram/', '0.5', 'monthly']")
   && str_contains((string) file_get_contents(AV_ROOT . '/lib/SiteSearch.php'), "['Organogram', '/people/organogram/'"));

/* ══ The PDF ══════════════════════════════════════════════════════════ */
$pdfHtml = Organogram::pdfHtml($h);
ck('organogram pdf: names only listable people', str_contains($pdfHtml, 'Orgtest Finance Danjuma') && !str_contains($pdfHtml, 'Orgtest Unconsented Coo') && !str_contains($pdfHtml, 'Super Admin'));
ck('organogram pdf: no NGGC, no Constitution text', stripos($pdfHtml, 'NGGC') === false
   && (ConstitutionDoc::PUBLIC || !array_intersect_key($grams(html_entity_decode(strip_tags((string) preg_replace('~<style\b.*?</style>~s', '', $pdfHtml)), ENT_QUOTES, 'UTF-8')), $cg)));
if (class_exists(\Dompdf\Dompdf::class)) {
    $keepDir = getenv('AV_PRIVATE_DIR');
    $tmp = sys_get_temp_dir() . '/av-org-test-' . getmypid(); @mkdir($tmp, 0700, true);
    putenv('AV_PRIVATE_DIR=' . $tmp);
    $pdf = (string) Organogram::pdf($people);
    $pages = preg_match_all('~/Type\s*/Page\b(?!s)~', $pdf);
    ck('organogram pdf: Dompdf builds one A4 landscape page', str_starts_with($pdf, '%PDF') && $pages === 1 && (bool) preg_match('~/MediaBox\s*\[\s*0\.?0*\s+0\.?0*\s+841\.8\d*\s+595\.2\d*~', $pdf));
    ck('organogram pdf: cached — the second call is the same bytes from disk', Organogram::pdf($people) === $pdf && count(glob($tmp . '/organogram-*.pdf') ?: []) === 1);
    array_map('unlink', glob($tmp . '/*') ?: []); @rmdir($tmp);
    putenv($keepDir === false ? 'AV_PRIVATE_DIR' : 'AV_PRIVATE_DIR=' . $keepDir);
}
ck('organogram pdf: the route serves it as a download, with a fallback', (static function (): bool {
    $s = (string) file_get_contents(AV_ROOT . '/people/organogram/download.php');
    return str_contains($s, "header('Content-Type: application/pdf')") && str_contains($s, 'Organogram::FILENAME') && str_contains($s, 'http_response_code(503)')
        && str_contains($s, 'Organogram::people(Database::pdo())');
})());

$pdo->exec('DELETE FROM team');
