<?php
/**
 * tests/projects.test.php — the project pages (REPLACEMENT_MAP row 6).
 *
 * One data file (lib/projects_content.php) drives the detail template and is
 * mirrored by the static index. These pin the design's rules (lib/avpj.php)
 * and that every project URL still has something behind it.
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/avpj.php';
$all = avpj_projects();

ck('projects: nine, in the design order',
   array_keys($all) === ['sts', 'ngg', 'techhome', 'mediapro', 'kap', 'africa-gates', 'bec', 'career-hub', 'ngv']);
ck('projects: filter counts match the design (9 · 2 · 3 · 1 · 2 · 1)',
   array_values(avpj_filters($all)) === [9, 2, 3, 1, 2, 1]);

/* Every local project has a page, every external one keeps its own site. */
foreach ($all as $slug => $p) {
    if ($p['local']) {
        ck("projects: /projects/$slug/ has a page", is_file(AV_ROOT . "/projects/$slug/index.php"));
        ck("projects: $slug links to its page", $p['href'] === "/projects/$slug/");
    } else {
        ck("projects: $slug has no detail view here", avpj_view($all, $slug) === null);
    }
}
ck('projects: Africa GATES and Next Generation Genius keep their external sites',
   $all['africa-gates']['href'] === 'https://afg.afrovanguard.org.ng' && $all['africa-gates']['ext']
   && $all['ngg']['href'] === 'https://next.afrovanguard.org.ng/' && $all['ngg']['ext']);
ck('projects: an unknown slug has no view', avpj_view($all, 'nope') === null);

/* projectVals(): selective entry changes the facts, step 1 and the CTA. */
$open = avpj_view($all, 'sts');
$sel  = avpj_view($all, 'kap');
ck('projects: open entry → Register free, Open registration',
   $open['cta'] === 'Register free' && $open['facts'][2] === ['Entry', 'Open registration'] && $open['steps'][0][0] === 'Register');
ck('projects: selective entry → Apply now, Application',
   $sel['cta'] === 'Apply now' && $sel['facts'][2] === ['Entry', 'Application'] && $sel['steps'][0][0] === 'Apply');
ck('projects: a local project’s CTA goes to the volunteer form', $open['ctaHref'] === AVPJ_VOLUNTEER_URL);
ck('projects: other projects are the first three that are not this one',
   array_keys($open['others']) === ['ngg', 'techhome', 'mediapro']
   && array_keys(avpj_view($all, 'techhome')['others']) === ['sts', 'ngg', 'mediapro']);
ck('projects: the How to join images never repeat the hero',
   count($open['gallery']) === 2 && !in_array($all['sts']['img'], $open['gallery'], true));

/* The static index mirrors the data file. */
$idx = (string) @file_get_contents(AV_ROOT . '/projects/index.html');
foreach ($all as $slug => $p) {
    ck("projects index: card for $slug (title, link, status) matches the data",
       str_contains($idx, 'href="' . htmlspecialchars($p['href'], ENT_QUOTES) . '" data-cat="' . htmlspecialchars($p['cat'], ENT_QUOTES) . '"')
       && str_contains($idx, '<h3>' . htmlspecialchars($p['name'], ENT_QUOTES) . '</h3>')
       && str_contains($idx, htmlspecialchars($p['status'], ENT_QUOTES) . '</div></div>'));
}
ck('projects index: keeps its canonical', str_contains($idx, '<link rel="canonical" href="https://afrovanguard.org.ng/projects/" />'));
ck('projects index: no-JS shows every program (filters hidden, no card pre-hidden)',
   str_contains((string) @file_get_contents(AV_ROOT . '/assets/site/avpj.css'), '.no-js .avpj-filters{display:none}')
   && !str_contains($idx, 'class="avpj-prog" hidden'));

/* Appeals may only be filed against a programme whose page can show them. */
ck('appeals: external programmes are not offered',
   !isset(Appeals::projects()['africa-gates']) && !isset(Appeals::projects()['ngg']) && isset(Appeals::projects()['sts']));

/* The STS sub-apps stay where they were. */
ck('projects: the STS sub-pages are untouched',
   is_file(AV_ROOT . '/projects/sts/ceo/index.html') && is_file(AV_ROOT . '/projects/sts/lcasp.html')
   && !is_file(AV_ROOT . '/projects/sts/index.html'));
