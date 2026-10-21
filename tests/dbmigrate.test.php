<?php
/**
 * tests/dbmigrate.test.php — moving this site onto a server database.
 *
 * Three defects, all of which reported success:
 *
 *  1. Migrator::ORDER was the MEMBERSHIP as well as the order, so the ten
 *     tables absent from it — av_settings (every stored secret),
 *     member_passes, course_access, lesson_notes, the diary's comment
 *     tables — were never copied, and the migration said ✓.
 *  2. The copy ran in one request. A shared host stops a request at 30s,
 *     which leaves the target half-filled; the retry then refuses, because
 *     the target is no longer empty. That is the loop a migration gets
 *     stuck in and cannot be talked out of.
 *  3. admin db_status counted ORDER too, so the screen that says what is
 *     about to be migrated under-reported it by the same ten tables.
 *
 * The target here is SQLite so the machine can be driven end to end
 * without a server: slicing, the resume cursor, the non-empty guard and
 * verification are all driver-independent, and the MySQL-specific
 * statements are the ones the existing migrate path already used.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/Migrator.php';
require_once AV_ROOT . '/lib/DbMigrateJob.php';

$src = Database::pdo();

/* A table with more rows than one slice carries (SLICE is 400), so "it
   resumed" means something. Dropped again at the end. */
$src->exec('CREATE TABLE IF NOT EXISTS mig_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT)');
$src->exec('DELETE FROM mig_probe');
$src->beginTransaction();
$ins = $src->prepare('INSERT INTO mig_probe (body) VALUES (?)');
for ($i = 0; $i < 950; $i++) $ins->execute(['row ' . $i]);
$src->commit();

/* ── 1. every table, not a hand-written subset ────────────────────────── */

$all = Migrator::allTables($src);
$live = [];
foreach ($src->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $r) {
    $live[] = (string) $r['name'];
}
sort($live);
$sorted = $all; sort($sorted);
ck('migrate: every table in the database is in the copy list', $sorted === $live);
ck('migrate: and av_settings — every stored secret — is one of them',
   in_array('av_settings', $all, true));
ck('migrate: parents still come before children', (function () use ($all) {
    $u = array_search('lms_users', $all, true);
    $e = array_search('enrollments', $all, true);
    return $u !== false && $e !== false && $u < $e;
})());

/* ── a target: same shape, no rows ────────────────────────────────────── */

$targetPath = sys_get_temp_dir() . '/av-migrate-test-' . getmypid() . '.sqlite';
@unlink($targetPath);
$dst = new PDO('sqlite:' . $targetPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
foreach ($src->query("SELECT sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL") as $r) {
    try { $dst->exec((string) $r['sql']); } catch (Throwable $e) {}
}

$plan = DbMigrateJob::plan($src, $dst);
ck('migrate: the plan lists a row per table', count($plan['tables']) === count($all));
ck('migrate: an empty target blocks nothing', $plan['blocking'] === []);

/* ── 2. it resumes: one row at a time, across many calls ──────────────── */

DbMigrateJob::reset();
$state = DbMigrateJob::begin($src, $dst,
    ['driver' => 'mysql', 'host' => 'h', 'name' => 'n', 'user' => 'u', 'pass' => 'hunter2-the-target-password'], false);
ck('migrate: beginning records a table list', !empty($state['tables']));
ck('migrate: and stores no password',
   !str_contains((string) json_encode($state), 'hunter2-the-target-password'));

/* Drive it one slice per call, the way a browser polling a shared host does.
   The cap, not the clock, decides how much a call copies: a time budget small
   enough to force a second call on one machine is large enough to finish the
   job on a faster one, and a test that only passes on slow hardware is not a
   test of resuming. */
$calls = 0;
do {
    $state = DbMigrateJob::step($src, $dst, 10.0, null, 1);
    $calls++;
} while (($state['phase'] ?? '') === 'copy' && $calls < 2000);

ck('migrate: the job finishes', ($state['phase'] ?? '') === 'done');
ck('migrate: and took a call per slice — it resumed where it stopped each time',
   $calls > (int) ceil(950 / 400) && $calls < 2000);
ck('migrate: the big table arrived whole, with no row copied twice',
   Migrator::count($dst, 'mig_probe') === 950);
ck('migrate: no error was recorded', ($state['error'] ?? '') === '');

/* ── 3. the only answer worth trusting ────────────────────────────────── */

$v = DbMigrateJob::verify($src, $dst);
$bad = array_values(array_filter($v['tables'], static fn($r) => !$r['ok']));
ck('migrate: every table matches end to end' . ($bad ? ' — mismatched: '
   . implode(', ', array_map(static fn($r) => $r['table'] . ' ' . $r['source'] . '≠' . var_export($r['target'], true), $bad)) : ''),
   $v['ok']);

/* The regression: a table that ORDER never named is really carried across. */
$srcSettings = Migrator::count($src, 'av_settings');
$dstSettings = Migrator::count($dst, 'av_settings');
ck('migrate: av_settings arrives with its rows', $srcSettings === $dstSettings);

/* ── 3b. a slice cannot be poured into a different database ───────────── */

DbMigrateJob::reset();
DbMigrateJob::begin($src, $dst, ['driver' => 'mysql', 'host' => 'h', 'name' => 'first'], true);
$wrong = false;
try { DbMigrateJob::step($src, $dst, 0.05, 'mysql://h/somewhere-else'); }
catch (Throwable $e) { $wrong = str_contains($e->getMessage(), 'not mysql://h/somewhere-else'); }
ck('migrate: resuming against a different target is refused', $wrong);
$ok = DbMigrateJob::step($src, $dst, 0.05, 'mysql://h/first');
ck('migrate: and against the right one it carries on', ($ok['phase'] ?? '') !== 'error');
DbMigrateJob::reset();

/* ── 4. a non-empty target is refused, not doubled ────────────────────── */

$refused = false;
try { DbMigrateJob::begin($src, $dst, ['driver' => 'mysql', 'host' => 'h', 'name' => 'n'], false); }
catch (Throwable $e) { $refused = str_contains($e->getMessage(), 'already holds rows'); }
ck('migrate: a second run into a full target is refused', $refused);

/* ...and replace empties it first rather than inserting twice. */
DbMigrateJob::reset();
DbMigrateJob::begin($src, $dst, ['driver' => 'mysql', 'host' => 'h', 'name' => 'n'], true);
$calls = 0;
do { $state = DbMigrateJob::step($src, $dst, 10.0, null, 1); $calls++; }
while (($state['phase'] ?? '') === 'copy' && $calls < 2000);
ck('migrate: replace re-runs cleanly instead of doubling rows',
   DbMigrateJob::verify($src, $dst)['ok']);

DbMigrateJob::reset();
unset($dst);
@unlink($targetPath);
$src->exec('DROP TABLE IF EXISTS mig_probe');

/* ── 5. the endpoints exist AND are gated ────────────────────────────────
   The classic way this breaks is a new action that dispatches fine and was
   never added to the superadmin list, so an editor can migrate the database.
   Both lists are checked, not just the switch. */
$api = (string) file_get_contents(AV_ROOT . '/admin/api.php');
foreach (['db_job_plan', 'db_job_begin', 'db_job_step', 'db_job_verify'] as $a) {
    ck("migrate: {$a} is dispatched", str_contains($api, "case '{$a}'"));
    ck("migrate: {$a} is superadmin-only", (bool) preg_match(
        "~\\$superadminOnly = \[(?:[^;]*?)'{$a}'~s", $api));
}
ck('migrate: reading the job state is dispatched too', str_contains($api, "case 'db_job_state'"));

/* CSRF: the named list is explicitly NOT the guarantee — api.php says so in
   its own comment, because an action added to the switch and forgotten in
   the list went through with no token at all. The backstop is "every POST
   is a write", and that is what these four rely on, so that is what is
   pinned here. */
ck('migrate: every POST is treated as a write, so a token is required',
   str_contains($api, 'if ($method === \'POST\') $writing = true;')
   && str_contains($api, 'if ($writing && !av_admin_bearer_ok()) av_csrf_require();'));

/* The screen that says what is about to be migrated must count the same
   tables the migration copies. It used to count Migrator::ORDER. */
ck('migrate: db_status counts every table, not the hand-written list',
   (bool) preg_match('~db_status.*?Migrator::allTables~s', $api));

/* ── 6. "already there" is not a failure ─────────────────────────────────
   Reported from production, with the schema step ticked:

     Schema step failed: SQLSTATE[42000]: Syntax error or access violation:
     1061 Duplicate key name 'idx_articles_published'

   MySQL has no CREATE INDEX IF NOT EXISTS, and db/schema.mysql.sql carries
   ten bare CREATE INDEX statements, so re-applying it ALWAYS raises 1061 on
   the first one. admin db_migrate aborted the whole migration there, which
   meant that once the tables existed no further attempt could ever get
   past the schema step. DbMigrateJob had the opposite fault: it swallowed
   every error, so a target that genuinely refused the schema would have
   been filled with rows anyway.

   Database::isBenignSchemaError() already knew the answer and was private. */
$dup = new PDOException("SQLSTATE[42000]: Syntax error or access violation: 1061 Duplicate key name 'idx_articles_published'");
ck('migrate: the exact 1061 from production is treated as already-there',
   Database::isBenignSchemaError($dup));

foreach ([
    '1050 Table \'articles\' already exists'            => 'a table that exists',
    '1060 Duplicate column name \'status\''             => 'a column that exists',
    '1826 Duplicate foreign key constraint name \'fk1\'' => 'a foreign key that exists',
] as $msg => $label) {
    ck('migrate: ' . $label . ' is already-there too', Database::isBenignSchemaError(new PDOException($msg)));
}

/* The other half, and the one that matters more: a real refusal must still
   stop the migration rather than be shrugged off. */
foreach ([
    "SQLSTATE[42000]: Access denied for user 'x'@'localhost' to database 'y'",
    'SQLSTATE[HY000]: Got error 28 from storage engine',
    "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'db.articles' doesn't exist",
] as $real) {
    ck('migrate: a real schema failure is not mistaken for already-there: ' . substr($real, 0, 40),
       !Database::isBenignSchemaError(new PDOException($real)));
}

/* Both schema paths must consult it rather than inventing an answer. */
$apiSrc = (string) file_get_contents(AV_ROOT . '/admin/api.php');
$jobSrc = (string) file_get_contents(AV_ROOT . '/lib/DbMigrateJob.php');
ck('migrate: the one-shot schema step skips benign errors instead of aborting',
   (bool) preg_match('~Schema step failed~', $apiSrc) && str_contains($apiSrc, 'Database::isBenignSchemaError'));
ck('migrate: the resumable schema step stops on a real one instead of swallowing it',
   str_contains($jobSrc, 'Database::isBenignSchemaError') && str_contains($jobSrc, 'refused part of the schema'));
