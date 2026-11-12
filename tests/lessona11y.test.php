<?php
/**
 * tests/lessona11y.test.php — what an instructor writes, checked for the
 * learners who will read it with a screen reader (lib/LessonA11y.php); the
 * accessibility statement; the CI accessibility gate (owner, 2026-11-11).
 */
declare(strict_types=1);
$r = AV_ROOT;
$codes = static fn (string $html): array => array_column(LessonA11y::check($html), 'level', 'code');

/* Pictures. */
ck('lesson a11y: a picture with no description is a fix', ($codes('<p><img src="a.jpg"></p>')['img-alt-missing'] ?? '') === 'fix');
ck('lesson a11y: a picture "described" by its file name is a fix', ($codes('<img src="a.jpg" alt="IMG_2041.jpg">')['img-alt-filename'] ?? '') === 'fix');
ck('lesson a11y: alt="" (decoration) is asked about, not refused', ($codes('<img src="a.jpg" alt="">')['img-alt-empty'] ?? '') === 'tip');
ck('lesson a11y: a described picture passes', $codes('<img src="a.jpg" alt="A bar chart: enrolment rose from 120 to 410.">') === []);
ck('lesson a11y: the count is in words a person reads ("3 pictures have no description")',
   str_starts_with(LessonA11y::check('<img src=a><img src=b><img src=c>')[0]['text'], '3 pictures have no description'));

/* Links. */
ck('lesson a11y: "click here" is flagged', isset($codes('<p><a href="/x">click here</a></p>')['link-vague']));
ck('lesson a11y: …and "Read more." with punctuation', isset($codes('<p><a href="/x">Read more.</a></p>')['link-vague']));
ck('lesson a11y: a link with no words is a fix', ($codes('<a href="/x"></a>')['link-empty'] ?? '') === 'fix');
ck('lesson a11y: …unless its picture is described', !isset($codes('<a href="/x"><img src="a" alt="Techome home page"></a>')['link-empty']));
ck('lesson a11y: a long bare web address is flagged', isset($codes('<a href="https://example.com/a/very/long/path/to/a/page">https://example.com/a/very/long/path/to/a/page</a>')['link-url']));
ck('lesson a11y: a link named for where it goes passes', $codes('<p>Get <a href="https://python.org">the Python download page</a>.</p>') === []);

/* Structure. */
ck('lesson a11y: an h1 inside a lesson is flagged (the title is the h1)', isset($codes('<h1>Intro</h1>')['heading-h1']));
ck('lesson a11y: a skipped heading level is flagged', isset($codes('<h2>A</h2><h4>B</h4>')['heading-skip']));
ck('lesson a11y: h2 then h3 is fine', $codes('<h2>A</h2><p>x</p><h3>B</h3>') === []);
ck('lesson a11y: an empty heading is a fix', ($codes('<h2> </h2>')['heading-empty'] ?? '') === 'fix');
ck('lesson a11y: bold lines standing in for headings are flagged', isset($codes('<p><strong>Step one</strong></p><p>x.</p><p><b>Step two</b></p>')['heading-fake']));
ck('lesson a11y: a bold sentence in a paragraph is not a fake heading', !isset($codes('<p><strong>Note:</strong> save first.</p><p><b>Remember this.</b></p>')['heading-fake']));
ck('lesson a11y: a table with no header row is a fix', ($codes('<table><tr><td>1</td></tr></table>')['table-headers'] ?? '') === 'fix');
ck('lesson a11y: a table with headers passes', !isset($codes('<table><tr><th>Year</th></tr><tr><td>2025</td></tr></table>')['table-headers']));
ck('lesson a11y: an embed with no title is a fix', ($codes('<iframe src="https://www.youtube.com/embed/x"></iframe>')['iframe-title'] ?? '') === 'fix');
ck('lesson a11y: an embed with a title passes', $codes('<iframe title="Video: setting up Python" src="x"></iframe>') === []);

/* Instructions that depend on seeing. */
ck('lesson a11y: "click the green button" is flagged', isset($codes('<p>Now click the green button.</p>')['sensory']));
ck('lesson a11y: …even straight after another paragraph (block text is not run together)', isset($codes('<p><b>Step two</b></p><p>Click the green button.</p>')['sensory']));
ck('lesson a11y: a passage in capitals is flagged', isset($codes('<p>PLEASE READ ALL OF THIS BEFORE YOU START</p>')['all-caps']));
ck('lesson a11y: an acronym or two is not', !isset($codes('<p>Use HTML and CSS, then the API.</p>')['all-caps']));
ck('lesson a11y: an empty lesson has nothing to report', LessonA11y::check('') === []);

/* The Studio's readiness list includes them. */
$pdo = Database::pdo();
$cid = (int) ($pdo->query("SELECT id FROM courses ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if ($cid) {
    $c = $pdo->query("SELECT * FROM courses WHERE id = $cid")->fetch(PDO::FETCH_ASSOC);
    $lid = (int) $pdo->query("SELECT id FROM lessons WHERE course_id = $cid ORDER BY id LIMIT 1")->fetchColumn();
    $was = (string) $pdo->query("SELECT body_html FROM lessons WHERE id = $lid")->fetchColumn();
    $pdo->prepare('UPDATE lessons SET body_html = ? WHERE id = ?')->execute(['<p>See the diagram.</p><p><img src="/uploads/d.png"></p>', $lid]);
    $hit = array_filter((new LmsCourseware())->readiness($c), static fn ($x) => $x['level'] === 'fix' && $x['lesson'] === $lid && str_contains($x['text'], 'no description'));
    ck('lesson a11y: the Studio will not call a course ready while a picture has no description', count($hit) === 1);
    $pdo->prepare('UPDATE lessons SET body_html = ? WHERE id = ?')->execute([$was, $lid]);
}

/* The Studio asks for a description when a picture goes in. */
$studio = (string) file_get_contents("$r/academy/studio/studio.js");
ck('Studio: inserting a picture asks what it shows (it used to insert alt="")',
   !str_contains($studio, "'<p><img src=\"' + esc(url) + '\" alt=\"\"></p>'") && str_contains($studio, "title: 'Describe this picture'") && str_contains($studio, 'only decoration'));

/* The audit tool. */
ck('audit tool: tools/academy-a11y-audit.php exists, is CLI-only and has --strict and --csv', (static function () use ($r) {
    $t = (string) @file_get_contents("$r/tools/academy-a11y-audit.php");
    return str_contains($t, "PHP_SAPI !== 'cli'") && str_contains($t, '--strict') && str_contains($t, '--csv') && str_contains($t, 'LessonA11y::audit(');
})());

/* The accessibility statement. */
$st = (string) @file_get_contents("$r/accessibility/index.php");
ck('statement: /accessibility/ exists and names its standard (WCAG 2.2 AA)', str_contains($st, 'WCAG') && str_contains($st, '2.2') && str_contains($st, 'level AA'));
ck('statement: it says honestly that the site partly meets it, and lists what falls short', str_contains($st, 'partly meets') && str_contains($st, 'What we know falls short'));
ck('statement: a barrier can be reported with a form already filled in', str_contains($st, "rawurlencode('Accessibility barrier')") && str_contains($st, '#contact-form'));
ck('statement: it carries the date it was last reviewed', (bool) preg_match('~\$reviewed\s*=\s*\'\d{1,2} \w+ \d{4}\'~', $st));
foreach (['partials/avh-chrome.php', 'index.html', 'lib/partials.php', 'academy/_avac.php'] as $f)
    ck("statement: linked from the footer in $f", str_contains((string) file_get_contents("$r/$f"), '/accessibility/">Accessibility</a>'));
ck('statement: listed in the sitemap, and in the list the sitemap is rebuilt from', str_contains((string) file_get_contents("$r/sitemap.xml"), '<loc>https://afrovanguard.org.ng/accessibility/</loc>') && str_contains((string) file_get_contents("$r/lib/Sitemap.php"), "['accessibility/', "));

/* The CI gate. */
$ci = (string) file_get_contents("$r/.github/workflows/ci.yml");
ck('CI: an accessibility job runs axe and the lesson audit on every push',
   str_contains($ci, "\n  accessibility:\n") && str_contains($ci, 'node axe-ci.js') && str_contains($ci, 'academy-a11y-audit.php --strict'));
$axe = (string) @file_get_contents("$r/tests/a11y/axe-ci.js");
ck('CI: the gate tests WCAG 2.2 AA, at 320px, and as a signed-in learner',
   str_contains($axe, "'wcag22aa'") && str_contains($axe, '[320, 640') && str_contains($axe, 'check-your-understanding'));
ck('CI: the test-learner helper refuses to run outside CI', str_contains((string) @file_get_contents("$r/tests/a11y/ci-session.php"), "getenv('CI') !== 'true'"));
ck('CI: the checker\'s versions are pinned', (bool) preg_match('~"axe-core":\s*"\d+\.\d+\.\d+"~', (string) @file_get_contents("$r/tests/a11y/package.json")));

/* No shell on shared hosting (owner, 2026-11-12): the every-course report is
   in the Studio, from the same function the command uses. */
$api = (string) file_get_contents("$r/admin/api.php");
ck('Studio: the Accessibility screen has its own API (list and spreadsheet), both from LessonA11y::audit',
   str_contains($api, "case 'ac_a11y': {") && str_contains($api, "case 'ac_a11y_csv': {") && substr_count($api, 'LessonA11y::audit(') === 2);
ck('Studio: the spreadsheet goes through Csv::put (formula-safe cells)', (bool) preg_match("~case 'ac_a11y_csv'.{0,900}Csv::put~s", $api));
ck('Studio: the command uses the same audit', str_contains((string) file_get_contents("$r/tools/academy-a11y-audit.php"), 'LessonA11y::audit($pdo'));
$si = (string) file_get_contents("$r/academy/studio/index.php"); $sj = (string) file_get_contents("$r/academy/studio/studio.js");
ck('Studio: an Accessibility item in the menu, a live count, a table with column headers',
   str_contains($si, 'data-view="a11y"') && str_contains($si, 'id="a11ySum" role="status" aria-live="polite"') && str_contains($si, '<th scope="col">What to do</th>'));
ck('Studio: the screen has loading, empty and error-with-retry states', str_contains($sj, 'Checking every lesson') && str_contains($sj, 'No problems found in the lesson text') && str_contains($sj, 'id="a11yRetry"'));
$a = LessonA11y::audit(Database::pdo());
ck('Studio: the audit counts every lesson and puts must-fix rows first',
   $a['lessons'] > 0 && $a['rows'] === array_values(array_merge(array_filter($a['rows'], fn ($x) => $x['level'] === 'fix'), array_filter($a['rows'], fn ($x) => $x['level'] !== 'fix'))));
$sc = (string) file_get_contents("$r/academy/studio/studio.css");
ck('Studio: grey text is readable (4.5:1) in both themes', str_contains($sc, '--muted:#5f6878;') && str_contains($sc, '--muted:#9aa3b2;'));
ck('Studio: the course pickers are labelled', str_contains($si, '<label for="curCourse">') && str_contains($si, '<label for="learnCourse">'));
