<?php
/**
 * tools/academy-a11y-audit.php — every barrier in every lesson, in one list.
 *
 * The Studio shows an instructor the barriers in THEIR course before they
 * publish (LmsCourseware::readiness). This walks every course at once, for
 * the person answerable for the Academy as a whole, and for the yearly review
 * the accessibility statement (/accessibility/) promises.
 *
 *     php tools/academy-a11y-audit.php                 every course, as text
 *     php tools/academy-a11y-audit.php --course=techome  one course
 *     php tools/academy-a11y-audit.php --published     published courses only
 *     php tools/academy-a11y-audit.php --csv > a.csv   a sheet to share out
 *     php tools/academy-a11y-audit.php --strict        exit 1 if any 'fix' is found
 *
 * Read-only. It changes nothing. Without a shell (shared hosting), the same
 * report is in the Academy Studio under Accessibility, with a spreadsheet
 * download (LessonA11y::audit serves both).
 *
 * What it checks: lib/LessonA11y.php (pictures, links, headings, tables,
 * embeds, instructions that depend on seeing) and, for videos, captions or a
 * transcript.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once dirname(__DIR__) . '/lib/bootstrap.php';

$opt = ['course' => '', 'published' => false, 'csv' => false, 'strict' => false];
foreach (array_slice($argv ?? [], 1) as $a) {
    if (preg_match('/^--course=(.+)$/', (string) $a, $m)) $opt['course'] = $m[1];
    elseif ($a === '--published') $opt['published'] = true;
    elseif ($a === '--csv') $opt['csv'] = true;
    elseif ($a === '--strict') $opt['strict'] = true;
    elseif ($a === '--help' || $a === '-h') { fwrite(STDOUT, "See the comment at the top of tools/academy-a11y-audit.php\n"); exit(0); }
    else { fwrite(STDERR, "Unknown option: $a\n"); exit(2); }
}

$pdo = Database::pdo();
if ($opt['course'] !== '') {
    $st = $pdo->prepare('SELECT 1 FROM courses WHERE slug = ?'); $st->execute([$opt['course']]);
    if (!$st->fetchColumn()) { fwrite(STDERR, "No course with the slug '{$opt['course']}'.\n"); exit(2); }
}
$a = LessonA11y::audit($pdo, $opt['course'], $opt['published']);
$fix = $a['fix']; $tip = $a['tip']; $lessons = $a['lessons'];
$rows = array_map(static fn ($r) => [$r['course'], $r['course_title'], $r['status'], $r['lesson'], $r['lesson_title'], $r['level'], $r['code'], $r['text']], $a['rows']);
$courses = array_fill(0, $a['courses'], 1);

if ($opt['csv']) {
    $out = fopen('php://stdout', 'w');
    fputcsv($out, ['course', 'course title', 'status', 'lesson', 'lesson title', 'level', 'check', 'what to do'], ',', '"', '\\');
    foreach ($rows as $r) fputcsv($out, $r, ',', '"', '\\');
} else {
    $cur = '';
    foreach ($rows as [$cs, $ct, $status, $lslug, $lt, $lvl, $code, $text]) {
        if ($cs !== $cur) { echo "\n== $ct ($cs, $status) ==\n"; $cur = $cs; }
        echo '  [' . ($lvl === 'fix' ? 'FIX' : 'tip') . "] $lt — $text\n";
    }
    printf("\n%d course(s), %d lesson(s): %d to fix, %d tip(s).\n", count($courses), $lessons, $fix, $tip);
    if (!$rows) echo "No barriers found in the lesson text. Test the pages too: keyboard, a screen reader, 400% zoom.\n";
}

exit($opt['strict'] && $fix > 0 ? 1 : 0);
