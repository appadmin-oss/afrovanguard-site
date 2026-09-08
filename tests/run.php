<?php
/**
 * tests/run.php — tiny, dependency-free test runner for the data layer.
 *
 *   php tests/run.php
 *
 * Spins up a throwaway SQLite DB, loads the app bootstrap, then includes every
 * tests/*.test.php file. Each test uses ck($label,$cond) and reset_users().
 * Exits non-zero if anything fails (so CI goes red). No PHPUnit needed.
 */
declare(strict_types=1);

$dbFile = sys_get_temp_dir() . '/av-test-' . getmypid() . '.db';
@unlink($dbFile); @unlink($dbFile . '-wal'); @unlink($dbFile . '-shm');
// For SQLite the app reads AV_DB_PATH (AV_DB_DSN is only for MySQL/Postgres),
// so set BOTH — this keeps the suite in a throwaway DB and never touches the
// project's db/diary.sqlite.
putenv('AV_DB_PATH=' . $dbFile);
putenv('AV_DB_DSN=sqlite:' . $dbFile);
// NGV runs on its own connection, and NgvDb defaults to db/ngv.sqlite INSIDE the
// project. Without this the suite provisions and writes the developer's real NGV
// database — gitignored, so nobody notices until a test's fixture participants
// turn up on the staff console.
$ngvFile = sys_get_temp_dir() . '/av-test-ngv-' . getmypid() . '.db';
@unlink($ngvFile); @unlink($ngvFile . '-wal'); @unlink($ngvFile . '-shm');
putenv('AV_NGV_DB_PATH=' . $ngvFile);
putenv('APP_KEY=ci_test_key_0123456789abcdefghij');
// No outbound mail from the suite. Without this every message shells out to a
// sendmail that is not there — slow, noisy, and one misconfigured CI runner away
// from actually delivering test fixtures to real addresses.
putenv('AV_MAIL_DISABLED=1');

$ROOT = dirname(__DIR__);
require $ROOT . '/lib/bootstrap.php';

$GLOBALS['__pass'] = 0; $GLOBALS['__fail'] = 0; $GLOBALS['__fails'] = [];

function ck(string $label, bool $cond): void {
    if ($cond) { $GLOBALS['__pass']++; }
    else { $GLOBALS['__fail']++; $GLOBALS['__fails'][] = $label; echo "  \033[31mFAIL\033[0m $label\n"; }
}

/** Fresh set of three users (ids 1-3) for a test file to build on. */
function reset_users(): void {
    $db = Database::pdo();
    foreach (['user_notifications','user_reminders','team_cards','team_polls','team_poll_votes','team_goals','team_links','team_events','team_standups','collab_tasks','user_prefs'] as $t) {
        try { $db->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
    $db->exec('DELETE FROM lms_users');
    $db->exec("INSERT INTO lms_users (id,name,email,password_hash) VALUES (1,'Ada','a@x.co','x'),(2,'Bode','b@x.co','x'),(3,'Chidi','c@x.co','x')");
}

$files = glob(__DIR__ . '/*.test.php') ?: [];
foreach ($files as $f) { echo "\n# " . basename($f) . "\n"; require $f; }

@unlink($dbFile); @unlink($dbFile . '-wal'); @unlink($dbFile . '-shm');
@unlink($ngvFile); @unlink($ngvFile . '-wal'); @unlink($ngvFile . '-shm');

echo "\n" . str_repeat('─', 48) . "\n";
if ($GLOBALS['__fail'] === 0) {
    echo "\033[32mOK\033[0m — {$GLOBALS['__pass']} assertions passed across " . count($files) . " file(s)\n";
    exit(0);
}
echo "\033[31mFAILED\033[0m — {$GLOBALS['__fail']} of " . ($GLOBALS['__pass'] + $GLOBALS['__fail']) . " assertions failed:\n";
foreach ($GLOBALS['__fails'] as $l) echo "  • $l\n";
exit(1);
