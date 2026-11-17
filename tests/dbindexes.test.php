<?php
/**
 * tests/dbindexes.test.php — indexes reach existing databases; the CLI
 * migration makes the tables the schema file does not list.
 *
 *   • The schema sync adds tables and columns, never indexes, so the lookups
 *     on memberships, lms_sessions and quiz_attempts added to db/schema.sql
 *     would only ever have reached fresh installs. ensureIndexes() carries them
 *     to a database that already exists.
 *   • db/migrate.php skipped the step the browser migration runs to create a
 *     feature's own tables on the target, so a Postgres cutover from the
 *     command line always stopped at av_settings.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$ixPdo = Database::pdo();
if (Database::driver() === 'sqlite') {
    $ixHas = static function (string $name) use ($ixPdo): bool {
        $st = $ixPdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = ?");
        $st->execute([$name]);
        return (bool) $st->fetchColumn();
    };
    $ixNames = ['idx_memberships_user', 'idx_lms_sessions_user', 'idx_quiz_attempts_user_lesson'];
    ck('db indexes: a fresh database has the lookup indexes',
       count(array_filter($ixNames, $ixHas)) === count($ixNames));

    /* An "old" database: the indexes are gone, the stamp says current. */
    foreach ($ixNames as $n) $ixPdo->exec('DROP INDEX IF EXISTS ' . $n);
    $ixM = new ReflectionMethod('Database', 'ensureIndexes'); $ixM->setAccessible(true);
    $ixM->invoke(null);
    ck('db indexes: an existing database is given them by the migration step',
       count(array_filter($ixNames, $ixHas)) === count($ixNames));
    $ixM->invoke(null);
    ck('db indexes: …and running it again changes nothing and throws nothing', $ixHas('idx_memberships_user'));
} else {
    ck('db indexes: (sqlite only, skipped)', true);
}

$ixDb = (string) file_get_contents(dirname(__DIR__) . '/lib/Database.php');
ck('db indexes: both the SQLite and the server migrations run the index step',
   substr_count($ixDb, "'ensureDiaryEntries', 'ensureIndexes']") + substr_count($ixDb, "'ensureLmsVerify', 'ensureIndexes']") === 2);
foreach (['schema.mysql.sql', 'schema.pgsql.sql'] as $ixF) {
    $ixS = (string) file_get_contents(dirname(__DIR__) . '/db/' . $ixF);
    ck("db indexes: $ixF lists the lookup indexes",
       str_contains($ixS, 'idx_memberships_user') && str_contains($ixS, 'idx_lms_sessions_user') && str_contains($ixS, 'idx_quiz_attempts_user_lesson'));
}

$ixMig = (string) file_get_contents(dirname(__DIR__) . '/db/migrate.php');
$ixJob = (string) file_get_contents(dirname(__DIR__) . '/lib/DbMigrateJob.php');
ck('db migrate: the CLI prepares every target table before copying, as the browser job does',
   str_contains($ixMig, 'Migrator::prepareTarget($source, $target)')
   && strpos($ixMig, 'Migrator::prepareTarget(') < strpos($ixMig, 'Migrator::migrate('));
ck('db migrate: the browser job uses the same step', str_contains($ixJob, 'Migrator::prepareTarget($src, $dst)'));
ck('db migrate: --apply-schema re-runs over a target that already has some of it',
   str_contains($ixMig, 'Database::isBenignSchemaError($e)'));
