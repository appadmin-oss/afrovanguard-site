<?php
/**
 * tests/constitution.test.php — the Constitution's structure holds together.
 *
 * docs/governance/constitution.md is the source of truth for /constitution/
 * and its PDF. These checks pin what a renumbering can break: every Article
 * and Schedule the text cites exists as a heading, Articles run I, II, III …
 * without a gap, the contents (TOC) lists them, and the version date shown on
 * the page agrees with the document-control table. They also pin Article XIII
 * (the Social Franchise and CACENTRE Adoption) and Schedule H.
 *
 * Run via tests/run.php (provides ck() and render_page()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/ConstitutionDoc.php';
require_once __DIR__ . '/_grading.php';

$md = ConstitutionDoc::markdown();
ck('constitution: the source is there', $md !== '');

$roman = static function (string $r): int {
    $v = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100]; $t = 0;
    for ($i = 0, $n = strlen($r); $i < $n; $i++) {
        $x = $v[$r[$i]]; $t += ($i + 1 < $n && $v[$r[$i + 1]] > $x) ? -$x : $x;
    }
    return $t;
};

/* ── Headings: Articles in order, Sections numbered from 1 ────────────── */
preg_match_all('/^### Article ([IVXLC]+) — (.+)$/m', $md, $am);
$articles = array_combine($am[1], $am[2]);
ck('constitution: 26 Articles, numbered I to XXVI with no gap or repeat',
   count($am[1]) === 26 && array_map($roman, $am[1]) === range(1, 26));
preg_match_all('/^### Schedule ([A-Z]) — (.+)$/m', $md, $sm);
ck('constitution: Schedules A to H, in order', $sm[1] === range('A', 'H'));

// Every Article and Schedule's Sections run 1, 2, 3 …
$sectionsOf = [];
$blocks = preg_split('/^(?=### )/m', $md);
foreach ($blocks as $b) {
    if (!preg_match('/^### (Article ([IVXLC]+)|Schedule ([A-Z]))\b/', $b, $h)) continue;
    preg_match_all('/^#### Section (\d+)\./m', $b, $s);
    $key = ($h[2] ?? '') !== '' ? 'Article ' . $h[2] : 'Schedule ' . $h[3];
    $sectionsOf[$key] = array_map('intval', $s[1]);
}
$badSeq = array_keys(array_filter($sectionsOf, static fn($s) => $s !== [] && $s !== range(1, count($s))));
ck('constitution: every Article\'s Sections are numbered 1, 2, 3 … (' . implode(', ', $badSeq) . ')', $badSeq === []);

/* ── No dangling cross-reference ─────────────────────────────────────── */
// The concordance's "Original" column cites the ORIGINAL numbering, by design.
$prose = (string) preg_replace('/^### Article concordance.*\z/ms', '', $md);
preg_match_all('/\bArticle ([IVXLC]+)\b/', $prose, $refs);
$dangling = array_values(array_unique(array_diff($refs[1], $am[1])));
ck('constitution: every "Article <roman>" cited exists as a heading (' . implode(', ', $dangling) . ')', $dangling === []);

preg_match_all('/\bArticle ([IVXLC]+), Sections? (\d+)/', $prose, $sr, PREG_SET_ORDER);
$missing = [];
foreach ($sr as [$all, $a, $n]) if (!in_array((int) $n, $sectionsOf["Article $a"] ?? [], true)) $missing[] = $all;
ck('constitution: every "Article X, Section N" cited exists (' . implode('; ', array_unique($missing)) . ')', $sr !== [] && $missing === []);

preg_match_all('/\bSchedule ([A-Z])\b/', $prose, $sch);
$badSch = array_values(array_unique(array_diff($sch[1], $sm[1])));
ck('constitution: every Schedule cited exists (' . implode(', ', $badSch) . ')', $badSch === []);

// Each "This edition" cell of the concordance names a real Article (or a Schedule).
preg_match('/^### Article concordance.*\z/ms', $md, $conc);
preg_match_all('/^\| [^|]+ \| ([IVXLC]+)\b[^|]*\|$/m', $conc[0] ?? '', $cc);
ck('constitution: the concordance\'s "This edition" column names only real Articles, all 26 of them',
   array_diff($cc[1], $am[1]) === [] && count(array_unique($cc[1])) === 26);
ck('constitution: the concordance lists Article XIII and Schedule H as new',
   str_contains($md, '| — | XIII The Social Franchise and CACENTRE Adoption (new) |')
   && str_contains($md, '| — | Schedule H Social Franchise and CACENTRE Adoption Standards (new) |'));

/* ── Article XIII and Schedule H ─────────────────────────────────────── */
ck('constitution: Article XIII is the Social Franchise and CACENTRE Adoption, in Part Five after Article XII',
   ($articles['XIII'] ?? '') === 'The Social Franchise and CACENTRE Adoption'
   && (bool) preg_match('/^## Part Five — Programmes and Institutions\n.*^### Article XII — .*^### Article XIII — .*^### Article XIV — Project Directors.*^## Part Six/ms', $md));
ck('constitution: the later Articles moved up one (Project Directors XIV … Ratification XXVI)',
   ($articles['XIV'] ?? '') === 'Project Directors and the Succession System'
   && ($articles['XV'] ?? '') === 'Non-Hereditary Leadership and Founder Independence'
   && ($articles['XVI'] ?? '') === 'Finance and Property'
   && ($articles['XXIII'] ?? '') === 'Bylaws and Subsidiary Instruments'
   && ($articles['XXVI'] ?? '') === 'Ratification and Transitional Provisions');
ck('constitution: Article XIII has its 16 Sections', count($sectionsOf['Article XIII'] ?? []) === 16);
ck('constitution: Schedule H is the Social Franchise and CACENTRE Adoption Standards, after Schedule G',
   ($sm[2][7] ?? '') === 'Social Franchise and CACENTRE Adoption Standards' && count($sectionsOf['Schedule H'] ?? []) === 10);
foreach (['Adopting Party', 'Adoption Agreement', 'CACENTRE Adoption', 'CACENTRE Network', 'Franchise Agreement', 'Franchise Holder', 'Social Franchise'] as $term)
    ck("constitution: \"$term\" is defined in Article II", str_contains($md, "- **\"$term\"** means "));
preg_match('/^#### Section 1\. Definitions\n(.*?)^#### /ms', $md, $def);
preg_match_all('/^- \*\*"([^"]+)"\*\*/m', $def[1] ?? '', $terms);
$sorted = $terms[1]; usort($sorted, 'strcasecmp');
ck('constitution: definitions stay in alphabetical order', $terms[1] !== [] && $terms[1] === $sorted);
ck('constitution: adoption confers no ownership, and ties to the non-ownership and founder-independence provisions',
   str_contains($md, 'ownership of, or any proprietary interest in, Afrovanguard')
   && str_contains($md, 'Article I, Section 6, Article VIII, Section 5, and Article XVI, Section 1 apply to every CACENTRE')
   && str_contains($md, 'Article XV applies to every franchise and adoption'));
ck('constitution: Schedule A and the Bylaws table carry the franchise and adoption',
   str_contains($md, '| Social Franchise grants and CACENTRE Adoption agreements |') && str_contains($md, '| Social Franchise and Adoption Bylaws |'));
ck('constitution: "Changes made in this edition" records it', str_contains($md, '- Added Article XIII (The Social Franchise and CACENTRE Adoption) and Schedule H'));

/* ── Version date ────────────────────────────────────────────────────── */
ck('constitution: the page\'s version date matches the document-control table',
   str_contains($md, '| Version date | ' . ConstitutionDoc::VERSION . ' |'));

/* ── Rendering: anchors and the contents ─────────────────────────────── */
[$html, $toc] = ConstitutionDoc::render($md);
$tocIds = [];
foreach ($toc as $p) foreach ($p['items'] as [$id, $label]) $tocIds[$id] = $label;
ck('constitution render: Article XIII has its anchor and is in the contents under Part Five',
   str_contains($html, '<h3 class="const-article" id="article-xiii">Article XIII — The Social Franchise and CACENTRE Adoption</h3>')
   && (static function () use ($toc) {
       foreach ($toc as $p) if ($p['part'] === 'Part Five — Programmes and Institutions')
           return array_column($p['items'], 0) === ['article-xi', 'article-xii', 'article-xiii', 'article-xiv'];
       return false;
   })());
ck('constitution render: Schedule H has its anchor and is in the contents',
   str_contains($html, 'id="schedule-h"') && ($tocIds['schedule-h'] ?? '') === 'Schedule H — Social Franchise and CACENTRE Adoption Standards');
ck('constitution render: every Article from I to XXVI has one anchor and one contents entry',
   count(array_filter(array_keys($tocIds), static fn($id) => (bool) preg_match('/^article-[ivxlc]+$/', $id))) === 26
   && substr_count($html, 'id="article-xxvi"') === 1);
ck('constitution render: Article XIII\'s Sections get their own anchors', str_contains($html, 'id="article-xiii-section-16"') && str_contains($html, 'id="schedule-h-section-10"'));
ck('constitution render: the lettered clauses in Article XIII keep the clause styling', (bool) preg_match('~id="article-xiii-section-2".*?<ul class="const-clauses">~s', $html));

/* ── The page, as a member ───────────────────────────────────────────── */
$member = gt_user('Constitution Reader', 'member');
gt_sign_in($member);
$page = render_page(AV_ROOT . '/constitution/index.php');
gt_sign_in(0);
ck('constitution page: renders for a member, with Article XIII, Schedule H and the new version date',
   !str_contains($page, 'render_page: threw') && str_contains($page, 'href="#article-xiii"') && str_contains($page, 'id="schedule-h"')
   && str_contains($page, 'Version date: ' . ConstitutionDoc::VERSION));
