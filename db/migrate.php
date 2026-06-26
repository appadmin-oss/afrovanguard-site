<?php
/**
 * db/migrate.php — copy all data from the app's SQLite database into a
 * MySQL/MariaDB or PostgreSQL target (the cutover step for DB portability).
 *
 * Usage (CLI only):
 *   php db/migrate.php --to=mysql  --host=127.0.0.1 --port=3306 --db=afrovanguard --user=u --pass=p [--apply-schema] [--truncate] [--dry-run]
 *   php db/migrate.php --to=pgsql  --host=127.0.0.1 --port=5432 --db=afrovanguard --user=u --pass=p  --apply-schema
 *   php db/migrate.php --to=mysql                                  # target from AV_DB_* env vars
 *
 * Flags:
 *   --to=mysql|pgsql        target driver (required; or AV_DB_DRIVER)
 *   --dsn=...               full PDO DSN (overrides host/port/db)
 *   --host --port --db --user --pass   discrete target connection (or AV_DB_* env)
 *   --from=/path.sqlite     source SQLite file (default: AV_DB_PATH)
 *   --apply-schema          apply db/schema.<to>.sql to the target first
 *   --truncate              clear target tables before copying (replace)
 *   --dry-run               report source counts only; change nothing
 *   --tables=a,b,c          restrict to a subset
 *
 * Verifies row counts per table and exits non-zero on any mismatch.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/Migrator.php';
ini_set('display_errors', '1');

function mig_fail(string $msg): void { fwrite(STDERR, "migrate: $msg\n"); exit(2); }

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z][a-z-]*)(?:=(.*))?$/', $a, $m)) { $opt[$m[1]] = $m[2] ?? true; }
}
if (isset($opt['help'])) { fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1700)); exit(0); }

$to = (string) ($opt['to'] ?? getenv('AV_DB_DRIVER') ?: '');
if (!in_array($to, ['mysql', 'pgsql', 'sqlite'], true)) mig_fail('--to=mysql|pgsql is required.');

/* ── Source: the app's SQLite DB (opened directly, untouched) ── */
$from = (string) ($opt['from'] ?? (defined('AV_DB_PATH') ? AV_DB_PATH : ''));
if ($from === '' || !is_file($from)) mig_fail("source SQLite not found: " . ($from ?: '(unset)'));
try {
    $source = new PDO('sqlite:' . $from, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) { mig_fail('cannot open source: ' . $e->getMessage()); }

/* ── Target: build DSN from --dsn, discrete flags, or AV_DB_* env ── */
$dsn  = (string) ($opt['dsn'] ?? getenv('AV_DB_DSN') ?: '');
$user = (string) ($opt['user'] ?? getenv('AV_DB_USER') ?: '') ?: null;
$pass = (string) ($opt['pass'] ?? getenv('AV_DB_PASS') ?: '') ?: null;
if ($dsn === '') {
    $host = (string) ($opt['host'] ?? getenv('AV_DB_HOST') ?: '127.0.0.1');
    $db   = (string) ($opt['db']   ?? getenv('AV_DB_NAME') ?: 'afrovanguard');
    $port = (string) ($opt['port'] ?? getenv('AV_DB_PORT') ?: ($to === 'pgsql' ? '5432' : '3306'));
    $dsn  = $to === 'pgsql'
        ? "pgsql:host=$host;port=$port;dbname=$db"
        : "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
}
try {
    $target = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) { mig_fail('cannot connect to target: ' . $e->getMessage()); }

fwrite(STDOUT, "Source: sqlite:$from\nTarget: $dsn\n\n");

/* ── Optionally provision the target schema from the generated per-driver file ── */
if (isset($opt['apply-schema'])) {
    if ($to === 'sqlite') mig_fail('--apply-schema is only for mysql/pgsql targets.');
    $file = AV_ROOT . "/db/schema.$to.sql";
    if (!is_file($file)) mig_fail("schema file not found: $file");
    // Strip ALL `-- …` comments (incl. inline ones — some contain ';', which would
    // otherwise break the naive split below) before splitting on statement ';'.
    $sql = preg_replace('/--[^\n]*/', '', (string) file_get_contents($file));
    $applied = 0;
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        try { $target->exec($stmt); $applied++; }
        catch (Throwable $e) { mig_fail("schema statement failed: " . $e->getMessage() . "\n  …in: " . substr($stmt, 0, 80)); }
    }
    fwrite(STDOUT, "Applied db/schema.$to.sql ($applied statements).\n\n");
}

/* ── Ensure the auxiliary/admin tables that aren't in the base schema file
      (app_meta, communities, celebrations, team) exist on the target, so their
      rows migrate too. Idempotent + driver-aware. ── */
require_once AV_ROOT . '/lib/workspace.php';
require_once AV_ROOT . '/lib/celebrations.php';
require_once AV_ROOT . '/lib/people.php';
try {
    Database::ensureMetaOn($target);
    av_communities_ensure($target);
    av_celebrations_ensure($target);
    av_team_ensure($target);
} catch (Throwable $e) { mig_fail('could not ensure auxiliary tables on target: ' . $e->getMessage()); }

/* ── Migrate ── */
try {
    $report = Migrator::migrate($source, $target, [
        'dryRun'   => isset($opt['dry-run']),
        'truncate' => isset($opt['truncate']),
        'tables'   => isset($opt['tables']) && is_string($opt['tables']) ? array_filter(array_map('trim', explode(',', $opt['tables']))) : null,
        'log'      => static function (string $m): void { fwrite(STDOUT, $m); },
    ]);
} catch (Throwable $e) { mig_fail($e->getMessage()); }

$rows = array_sum(array_map(fn($r) => $r['copied'], $report));
$bad  = array_filter($report, fn($r) => empty($r['ok']));
fwrite(STDOUT, "\n" . count($report) . " tables processed, $rows rows copied.\n");
if ($bad) { fwrite(STDERR, "ROW-COUNT MISMATCH: " . implode(', ', array_keys($bad)) . "\n"); exit(1); }
fwrite(STDOUT, (isset($opt['dry-run']) ? "Dry run complete." : "Done — all row counts verified.") . "\n");
exit(0);
