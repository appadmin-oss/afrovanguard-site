<?php
/**
 * tests/rawindex.test.php — no index is created with a bare exec().
 *
 * `CREATE INDEX IF NOT EXISTS` is SQLite syntax that MySQL refuses with a
 * 1064. Run through a bare exec() inside a try/catch, the refusal is
 * swallowed and the index simply never exists on MySQL — which, for the
 * UNIQUE ones (commitments.dedupe_key, articles.ref_code), means the
 * duplicate they exist to refuse is let in. Database::execSchema() rewrites
 * the statement per driver and treats "already there" as done.
 */
declare(strict_types=1);

$bad = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/lib', FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->getExtension() !== 'php') continue;
    foreach (file((string) $f) as $i => $line) {
        if (preg_match('/->exec\(\s*[\'"]CREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS/i', $line)) {
            $bad[] = basename((string) $f) . ':' . ($i + 1);
        }
    }
}
ck('no index is created with a bare exec() that MySQL would refuse in silence' . ($bad ? ' — ' . implode(', ', $bad) : ''), $bad === []);

ck('the MySQL form of an index statement drops IF NOT EXISTS',
   Database::translateDDL('CREATE UNIQUE INDEX IF NOT EXISTS ux ON t(a)', 'mysql') === 'CREATE UNIQUE INDEX ux ON t(a)');
