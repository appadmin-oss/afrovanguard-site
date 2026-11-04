<?php
/**
 * tests/lmsqueries.test.php — the LMS's hot paths cost a fixed number of
 * queries, not one per module, lesson or course; and an outline's access
 * check reads a lesson's release date.
 *
 * A remembered outline has no other observable behaviour (same answers,
 * fewer questions), so this counts queries on a second connection to the
 * test database, and asserts the first call does reach it.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

/* Counts EXECUTED statements: a query prepared once and run per row is still a
   query per row, which is exactly the shape being measured. */
if (!class_exists('AvCountingStmt')) {
    class AvCountingStmt extends PDOStatement
    {
        public static int $n = 0;
        protected function __construct() {}
        public function execute(?array $params = null): bool { self::$n++; return parent::execute($params); }
    }
    final class AvCountingPdo extends PDO
    {
        public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false { AvCountingStmt::$n++; return $m === null ? parent::query($q) : parent::query($q, $m, ...$a); }
    }
}
$cp = new AvCountingPdo('sqlite:' . AV_DB_PATH, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_STATEMENT_CLASS => [AvCountingStmt::class, []]]);
AvCountingStmt::$n = 0;
$cp->exec('PRAGMA busy_timeout = 5000');

$ac = new AcademyRepository();
$setup = new LmsRepository();
$qs = $ac->save(['title' => 'Query Count Course', 'summary' => 's', 'status' => 'published', 'access_type' => 'membership']);
$qc = $ac->bySlug($qs); $qcid = (int) $qc['id'];
for ($m = 1; $m <= 6; $m++) {
    $mid = $setup->addModule($qcid, "Module $m");
    for ($l = 1; $l <= 5; $l++) $setup->saveLesson(['module_id' => $mid, 'title' => "Lesson $m.$l", 'slug' => '', 'body_html' => '<p>x</p>', 'is_preview' => $l === 1 ? 1 : 0]);
}
Database::pdo()->prepare('INSERT INTO lms_users (name, email, password_hash, role) VALUES (?,?,?,?)')->execute(['Q Learner', 'q-learner@example.com', 'x', 'learner']);
$qu = $setup->userByEmail('q-learner@example.com');
$setup->enrol((int) $qu['id'], $qcid);

$lms = new LmsRepository($cp);
AvCountingStmt::$n = 0; $out = $lms->curriculum($qcid);
ck('queries: an outline of 6 modules × 5 lessons is two queries', AvCountingStmt::$n === 2 && count($out) === 6 && count($out[5]['lessons']) === 5);
AvCountingStmt::$n = 0; $lms->curriculum($qcid); $lms->orderedLessons($qcid); $lms->lessonCount($qcid);
ck('queries: asking again in the same request is free', AvCountingStmt::$n === 0);
ck('queries: the flattened order is module then lesson', array_column($lms->orderedLessons($qcid), 'title')[5] === 'Lesson 2.1');

AvCountingStmt::$n = 0; $map = $lms->canAccessMany($qu, $qc, $lms->orderedLessons($qcid));
ck('queries: access for a whole outline is a handful of queries, not one per lesson', AvCountingStmt::$n <= 4 && count($map) === 30);
ck('queries: and gives the same answers as one at a time', $map === array_combine(array_map(static fn($l) => (int) $l['id'], $lms->orderedLessons($qcid)),
    array_map(static fn($l) => $setup->canAccess($qu, $qc, $setup->lessonById((int) $l['id'])), $lms->orderedLessons($qcid))));
ck('queries: a non-member sees only the previews', count(array_filter($map)) === 6);

/* A release date in the future shuts a lesson on the outline as well as on its own page. */
$first = $lms->orderedLessons($qcid)[1];
Database::pdo()->prepare('UPDATE lessons SET available_at = ?, is_preview = 1 WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() + 86400), (int) $first['id']]);
$lms->forgetOutlines();
$map2 = $lms->canAccessMany($qu, $qc, $lms->orderedLessons($qcid));
ck('queries: the outline carries the release date', isset($lms->orderedLessons($qcid)[1]['available_at']));
ck('queries: a scheduled lesson is shut on the outline too', $map2[(int) $first['id']] === false);

AvCountingStmt::$n = 0; $lms->unpassedQuizzes((int) $qu['id'], $qcid);
ck('queries: counting unpassed quizzes is one query once the outline is known', AvCountingStmt::$n === 1);

$lms2 = new LmsRepository($cp);
AvCountingStmt::$n = 0; $ec = $lms2->enrolledCourses((int) $qu['id']);
ck('queries: the portal course list is grouped (4 + an outline per course)', AvCountingStmt::$n <= 6 && ($ec[0]['total'] ?? 0) === 30 && $ec[0]['next'] !== null);

/* A write forgets what it changed. */
$lms->saveLesson(['module_id' => (int) $out[0]['id'], 'title' => 'Added later', 'slug' => '', 'body_html' => '']);
ck('queries: a saved lesson appears in the next outline', count($lms->orderedLessons($qcid)) === 31);
