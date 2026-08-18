<?php
/**
 * tests/mysql.test.php — the server-engine paths, against a real MySQL server.
 *
 * SKIPPED unless AV_TEST_MYSQL_DSN is set, so the normal SQLite run is unaffected:
 *
 *   AV_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=av_test;charset=utf8mb4' \
 *   AV_TEST_MYSQL_USER=… AV_TEST_MYSQL_PASS=… php tests/run.php
 *
 * These exist because the MySQL branches cannot be reasoned about from a SQLite
 * run, and the first time they were exercised against a real server they were
 * wrong: a repaired `created_at` came back `TEXT NULL DEFAULT NULL` while a
 * created one was `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`. The same column,
 * a different type, depending on whether the table had been repaired. Nothing in
 * a SQLite suite can see that.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$mysqlDsn = (string) (getenv('AV_TEST_MYSQL_DSN') ?: '');
if ($mysqlDsn === '') {
    ck('mysql: skipped (set AV_TEST_MYSQL_DSN to run these)', true);
    return;
}

try {
    $my = new PDO($mysqlDsn, (string) getenv('AV_TEST_MYSQL_USER'), (string) getenv('AV_TEST_MYSQL_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $e) {
    ck('mysql: could not connect — ' . substr($e->getMessage(), 0, 80), false);
    return;
}
ck('mysql: connected to a real server', true);

/** Column metadata for the connected schema. */
$meta = function (string $table) use ($my): array {
    $st = $my->prepare('SELECT column_name, column_type, is_nullable, column_default
                        FROM information_schema.columns
                        WHERE table_schema = DATABASE() AND table_name = ?');
    $st->execute([$table]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[strtolower((string) $r['column_name'])] = $r;
    return $out;
};

$ddl = "CREATE TABLE IF NOT EXISTS t_my_sync (
          id         INTEGER PRIMARY KEY AUTOINCREMENT,
          name       TEXT NOT NULL DEFAULT '',
          note       TEXT NOT NULL DEFAULT '',
          amount     INTEGER NOT NULL DEFAULT 0,
          created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );";

$my->exec('DROP TABLE IF EXISTS t_my_sync');
Database::syncTablesFromDdl($my, $ddl, 'mysql', 'test');
$created = $meta('t_my_sync');
ck('mysql: the table is created from SQLite DDL', $created !== []);
ck('mysql: AUTOINCREMENT becomes AUTO_INCREMENT', isset($created['id']));

// The reference the repair has to match.
$createdTs = $created['created_at'] ?? [];
ck('mysql: a created timestamp column is a real DATETIME',
   stripos((string) ($createdTs['column_type'] ?? ''), 'datetime') !== false);
ck('mysql: and defaults to CURRENT_TIMESTAMP',
   stripos((string) ($createdTs['column_default'] ?? ''), 'current_timestamp') !== false);

// Populate, then drift: ADD COLUMN on a populated table is the case MySQL is
// strictest about, and the one softening exists for.
$my->exec("INSERT INTO t_my_sync (name, note, amount) VALUES ('kept row', 'kept note', 99)");
foreach (['note', 'amount', 'created_at'] as $c) $my->exec("ALTER TABLE t_my_sync DROP COLUMN `$c`");
ck('mysql: the fixture dropped three columns from a populated table', count($meta('t_my_sync')) === 2);

$added = Database::syncTablesFromDdl($my, $ddl, 'mysql', 'test');
ck('mysql: the sync reports three additions', $added === 3);
$rep = $meta('t_my_sync');
ck('mysql: every dropped column is restored', isset($rep['note'], $rep['amount'], $rep['created_at']));

// The defect this file was written for: a repaired column must be the same column
// a created one is, not a nullable TEXT standing in for a timestamp.
ck('mysql: a REPAIRED timestamp is DATETIME too, not TEXT',
   stripos((string) ($rep['created_at']['column_type'] ?? ''), 'datetime') !== false);
ck('mysql: and carries the CURRENT_TIMESTAMP default',
   stripos((string) ($rep['created_at']['column_default'] ?? ''), 'current_timestamp') !== false);
ck('mysql: repaired and created agree on the type',
   strcasecmp((string) ($rep['created_at']['column_type'] ?? 'a'),
              (string) ($createdTs['column_type'] ?? 'b')) === 0);

// A defaulted TEXT becomes VARCHAR: several MySQL builds reject a default on TEXT.
ck('mysql: a defaulted TEXT column is repaired as VARCHAR',
   stripos((string) ($rep['note']['column_type'] ?? ''), 'varchar') !== false);
ck('mysql: an INTEGER column keeps its constant default',
   (string) ($rep['amount']['column_default'] ?? '') === '0');

// The row that was already there must be untouched by any of it.
$row = $my->query("SELECT name, note, amount FROM t_my_sync WHERE name = 'kept row'")->fetch(PDO::FETCH_ASSOC);
ck('mysql: the existing row survived the repair', ($row['name'] ?? '') === 'kept row');
ck('mysql: its restored columns default rather than error',
   $row !== false && array_key_exists('note', $row) && array_key_exists('amount', $row));

// Writing after a repair is the thing that actually matters to a caller.
$my->exec("INSERT INTO t_my_sync (name, note, amount) VALUES ('after repair', 'n', 5)");
$after = $my->query("SELECT amount, created_at FROM t_my_sync WHERE name = 'after repair'")->fetch(PDO::FETCH_ASSOC);
ck('mysql: an insert naming the repaired columns works', (int) ($after['amount'] ?? 0) === 5);
ck('mysql: and the repaired timestamp populates itself',
   trim((string) ($after['created_at'] ?? '')) !== '' && stripos((string) $after['created_at'], '0000') !== 0);

ck('mysql: the sync is idempotent', Database::syncTablesFromDdl($my, $ddl, 'mysql', 'test') === 0);
$my->exec('DROP TABLE IF EXISTS t_my_sync');
