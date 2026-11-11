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
 * Read-only. It changes nothing.
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
$sql = 'SELECT id, slug, title, status FROM courses';
$args = [];
$where = [];
if ($opt['course'] !== '') { $where[] = 'slug = ?'; $args[] = $opt['course']; }
if ($opt['published']) $where[] = "status = 'published'";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY title';
$st = $pdo->prepare($sql); $st->execute($args);
$courses = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$courses) { fwrite(STDERR, $opt['course'] !== '' ? "No course with the slug '{$opt['course']}'.\n" : "No courses.\n"); exit($opt['course'] !== '' ? 2 : 0); }

$ls = $pdo->prepare('SELECT id, slug, title, kind, quiz_json, body_html, video_url, captions_url, transcript FROM lessons WHERE course_id = ? ORDER BY position, id');

$rows = []; $fix = 0; $tip = 0; $lessons = 0;
foreach ($courses as $c) {
    $ls->execute([(int) $c['id']]);
    foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $lessons++;
        $issues = LessonA11y::check((string) $l['body_html']);
        if (LmsRepository::lessonType($l) === 'video' && trim((string) ($l['captions_url'] ?? '')) === '' && trim((string) ($l['transcript'] ?? '')) === '')
            $issues[] = ['level' => 'fix', 'code' => 'video-captions', 'text' => 'The video has no captions and no transcript, so learners who cannot hear it cannot follow it.'];
        foreach ($issues as $i) {
            $i['level'] === 'fix' ? $fix++ : $tip++;
            $rows[] = [$c['slug'], (string) $c['title'], (string) $c['status'], (string) $l['slug'], (string) $l['title'], $i['level'], $i['code'], $i['text']];
        }
    }
}

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
