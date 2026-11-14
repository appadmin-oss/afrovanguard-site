<?php
/**
 * tests/ddltextdefault.test.php — no TEXT/BLOB column reaches MySQL with a
 * literal default.
 *
 * MySQL 8 refuses `TEXT NOT NULL DEFAULT ''` with error 1101 and accepts only
 * an expression, `DEFAULT ('')`. MariaDB takes either, so every validation run
 * here (MariaDB 10.11) passed while, on MySQL 8, `Database::execSchema()` logged
 * the refusal and carried on: collab_tasks, av_settings and some ninety other
 * runtime tables were simply never created, and each feature 500ed on its first
 * query. A fresh MySQL 8 deploy did not get that far — db/schema.mysql.sql is
 * applied raw and aborted on `articles.gradient`.
 *
 * The rule is asserted over the DDL the app actually holds, not over a fixture:
 * every CREATE/ALTER string in lib/, db/schema.sql translated for MySQL, and
 * the committed db/schema.mysql.sql as it is. tests/mysql.test.php proves the
 * same thing against a real MySQL 8 server when one is configured.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

/* A TEXT/BLOB column whose DEFAULT is a bare quoted string or number. */
$tdLiteral = "/\\b(?:TINY|MEDIUM|LONG)?(?:TEXT|BLOB)(?:\\s+(?:NOT\\s+NULL|NULL|COLLATE\\s+\\w+|CHARACTER\\s+SET\\s+\\w+))*\\s+DEFAULT\\s+(?:'|-?\\d)/i";

$td = Database::translateDDL("CREATE TABLE t (\n  id INTEGER PRIMARY KEY AUTOINCREMENT,\n  a TEXT NOT NULL DEFAULT '',\n  b TEXT DEFAULT 'it''s, here' NOT NULL,\n  c TEXT NOT NULL DEFAULT 0,\n  d TEXT DEFAULT NULL,\n  e VARCHAR(20) NOT NULL DEFAULT '',\n  f TEXT NOT NULL DEFAULT (datetime('now'))\n);", 'mysql');
ck('ddl: a literal TEXT default becomes an expression on MySQL', str_contains($td, "a TEXT NOT NULL DEFAULT ('')"));
ck('ddl: a quoted default with a comma and a doubled quote is wrapped whole', str_contains($td, "b TEXT DEFAULT ('it''s, here') NOT NULL"));
ck('ddl: a numeric TEXT default is wrapped too', str_contains($td, 'c TEXT NOT NULL DEFAULT (0)'));
ck('ddl: DEFAULT NULL is legal bare and left alone', str_contains($td, 'd TEXT DEFAULT NULL'));
ck('ddl: a VARCHAR default is left alone', str_contains($td, "e VARCHAR(20) NOT NULL DEFAULT ''"));
ck('ddl: a timestamp default is still promoted to DATETIME', str_contains($td, 'f DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'));
$same = "CREATE TABLE t (a TEXT NOT NULL DEFAULT '')";
ck('ddl: SQLite DDL is returned byte-identical', Database::translateDDL($same, 'sqlite') === $same);
ck('ddl: Postgres keeps the literal (it accepts a TEXT default)', Database::translateDDL($same, 'pgsql') === $same);

/* The sweep: every piece of schema the app holds. */
$tdStrings = [];
foreach (glob(dirname(__DIR__) . '/lib/*.php') ?: [] as $f) {
    foreach (token_get_all((string) file_get_contents($f)) as $tok) {
        if (!is_array($tok) || !in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) continue;
        $s = $tok[0] === T_CONSTANT_ENCAPSED_STRING ? substr($tok[1], 1, -1) : $tok[1];
        if (preg_match('/^\s*(CREATE\s+TABLE|ALTER\s+TABLE)/i', $s)) $tdStrings[] = [basename($f), $s];
    }
}
$tdStrings[] = ['db/schema.sql', (string) file_get_contents(dirname(__DIR__) . '/db/schema.sql')];
$tdBad = [];
foreach ($tdStrings as [$f, $s]) {
    if (preg_match($tdLiteral, Database::translateDDL($s, 'mysql'), $m)) $tdBad[] = $f . ': ' . trim($m[0]);
}
ck('ddl: the sweep read the schema it is meant to (' . count($tdStrings) . ' statements)', count($tdStrings) > 150);
ck('ddl: no CREATE/ALTER in lib/ or db/schema.sql reaches MySQL with a literal TEXT default' . ($tdBad ? ' — ' . implode('; ', array_slice($tdBad, 0, 3)) : ''), $tdBad === []);
ck('ddl: the committed db/schema.mysql.sql (applied raw on a fresh server) has none either',
   !preg_match($tdLiteral, preg_replace('/--[^\n]*/', '', (string) file_get_contents(dirname(__DIR__) . '/db/schema.mysql.sql'))));

/* translateDDL cannot help a statement that never passes through it: a bare
   ->exec() of SQLite DDL. Those were three ALTERs; they go through execSchema now. */
$tdRaw = [];
foreach (glob(dirname(__DIR__) . '/lib/*.php') ?: [] as $f) {
    foreach (file($f) ?: [] as $i => $line) {
        if (preg_match('/->exec\(\s*"\s*ALTER\s+TABLE/i', $line) && preg_match($tdLiteral, $line)) $tdRaw[] = basename($f) . ':' . ($i + 1);
    }
}
ck('ddl: no raw ->exec() of an ALTER adding a literal-default TEXT column' . ($tdRaw ? ' — ' . implode(', ', $tdRaw) : ''), $tdRaw === []);
