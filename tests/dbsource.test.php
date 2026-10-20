<?php
/**
 * tests/dbsource.test.php — "why is this site on SQLite?"
 *
 * The reported symptom: a deployment with MySQL credentials that runs on
 * SQLite anyway, loses writes into it, and cannot say why.
 *
 * The cause is not a bug in the connection code. lib/Database.php picks its
 * driver from getenv() and nothing else, and it has to: pdo() runs before
 * Config and AvSettings, and both of those read from the database, so
 * asking either would recurse. But config.php is this project's documented
 * home for every OTHER secret, so AV_DB_* goes there, is never read, and
 * the site falls to SQLite in silence.
 *
 * These pin the detection and the sentence it produces. configSource() must
 * report rather than switch: flipping a live site onto the empty MySQL in
 * its config.php looks exactly like being wiped.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$detail = static function (string $label): string {
    $d = Config::diagnostics();
    foreach (($d['groups'] ?? $d) as $g) {
        foreach (($g['checks'] ?? []) as $c) {
            if (($c['label'] ?? '') === $label) return (string) ($c['detail'] ?? '');
        }
    }
    return '';
};

$src = Database::configSource();

ck('db: configSource reports a source and an ignored list',
   isset($src['source']) && is_array($src['ignored'] ?? null));
ck('db: the suite runs from the environment, so that is what it says',
   $src['source'] === 'environment');
ck('db: System Health states where the database settings came from',
   $detail('Database settings') !== '');

/* The trap, with the constants the suite cannot define retroactively: the
   detection is a pure function of defined()/getenv(), so it is exercised
   directly rather than by re-bootstrapping a whole second site. */
ck('db: a constant that is also in the environment is NOT called ignored',
   !in_array('AV_DB_PATH', $src['ignored'], true));

/* configSource must never connect, switch or write — diagnostics run on
   every Studio page load, and on a site whose database is already wrong. */
$before = Database::driver();
Database::configSource();
ck('db: asking where the settings came from does not change the connection',
   Database::driver() === $before);

/* The fallback is the other half of "data missing or inconsistent": a blip
   serves and accepts writes against a different database. Production wants
   it refused, and the page has to say which way it is set. */
ck('db: dbStrict() answers either way', is_bool(Database::dbStrict()));

/* config.example.php is where somebody looks before they look at the code.
   It said nothing at all about the database, which is how AV_DB_* ended up
   in config.php in the first place. */
$ex = (string) file_get_contents(AV_ROOT . '/config.example.php');
ck('db: the example config warns that the database is not read from config.php',
   str_contains($ex, 'AV_DB_* written into config.php is dead') || str_contains($ex, 'CANNOT'));
ck('db: and names the environment variables that do work',
   str_contains($ex, 'AV_DB_HOST') && str_contains($ex, 'AV_DB_STRICT'));
ck('db: and says to copy the data across before pointing at an empty target',
   stripos($ex, 'before you point') !== false);
