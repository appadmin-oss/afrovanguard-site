<?php
/**
 * lib/DbMigrateJob.php — move this site's SQLite data onto MySQL/Postgres
 * from the browser, in slices, with the job surviving a timeout.
 *
 * Why this exists. db/migrate.php does the same work in one pass and is
 * CLI-only (it 404s under a web server). This project is deployed to cPanel
 * shared hosting, where there is no shell — so the one tool that moves a
 * site onto its real database could not be run on the platform the project
 * targets. That is not a configuration problem anybody can work around.
 *
 * Three things a one-pass copy cannot do here:
 *
 *  1. SURVIVE max_execution_time. A shared host stops a request after 30s.
 *     Migrator::copyTable() streams an entire table in one call, so a big
 *     table dies half-copied, and re-running refuses because the target is
 *     no longer empty. Every slice here records where it got to.
 *  2. COPY EVERY TABLE. Migrator::ORDER is a hand-written list; this
 *     database has 32 tables and the list names 25, so av_settings (every
 *     stored secret), member_passes, course_access, lesson_notes and the
 *     diary's comment tables were silently never copied. The list is kept
 *     — it is a real foreign-key ordering — but as the ORDER, not the
 *     MEMBERSHIP: whatever else the source holds is appended after it.
 *  3. RESUME SAFELY. The cursor is the source's rowid, which SQLite keeps
 *     for every ordinary table, so tables with no `id` resume exactly like
 *     tables that have one. OFFSET would re-read or skip rows whenever the
 *     live site writes between slices.
 *
 * The target password is NEVER stored. It is re-sent with each slice and
 * held only for that request; the saved state carries progress, and a
 * description of the target with no credentials in it.
 *
 * It fills the target and stops. It does not repoint the site: that is a
 * hand edit to .env or .htaccess, and it is the operator's to make once
 * they have seen the counts match.
 */
declare(strict_types=1);

final class DbMigrateJob
{
    private const META = 'db_migrate_job';
    private const SLICE = 400;

    /** Every table in the source, FK-safe names first, then whatever else is there. */
    public static function tables(PDO $src): array { return Migrator::allTables($src); }

    /**
     * Connect to the target. Credentials come from the request every time;
     * nothing here writes them anywhere.
     */
    public static function connect(array $c): PDO
    {
        $drv  = ($c['driver'] ?? 'mysql') === 'pgsql' ? 'pgsql' : 'mysql';
        $host = trim((string) ($c['host'] ?? '')) ?: '127.0.0.1';
        $port = trim((string) ($c['port'] ?? '')) ?: ($drv === 'pgsql' ? '5432' : '3306');
        $name = trim((string) ($c['name'] ?? ''));
        if ($name === '') throw new RuntimeException('The target database name is required.');
        $dsn = $drv === 'pgsql'
            ? "pgsql:host={$host};port={$port};dbname={$name}"
            : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        return new PDO($dsn, (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 8,
        ]);
    }

    /** A target description with no credentials in it, safe to store and show. */
    public static function label(array $c): string
    {
        return (($c['driver'] ?? 'mysql') === 'pgsql' ? 'pgsql' : 'mysql') . '://'
            . trim((string) ($c['host'] ?? '127.0.0.1')) . '/' . trim((string) ($c['name'] ?? ''));
    }

    public static function state(): ?array
    {
        $raw = Database::metaGet(self::META);
        if ($raw === null || $raw === '') return null;
        $j = json_decode($raw, true);

        return is_array($j) ? $j : null;
    }

    public static function reset(): void { Database::metaSet(self::META, ''); }

    private static function put(array $s): void { Database::metaSet(self::META, (string) json_encode($s)); }

    /**
     * Look before leaping: what is here, what is there, and what is in the
     * way. Changes nothing.
     */
    public static function plan(PDO $src, PDO $dst): array
    {
        $rows = [];
        $blocking = [];
        foreach (self::tables($src) as $t) {
            $there = Migrator::tableExists($dst, $t);
            $srcN  = Migrator::count($src, $t);
            $dstN  = $there ? Migrator::count($dst, $t) : 0;
            if ($dstN > 0) $blocking[] = $t;
            $rows[] = ['table' => $t, 'source' => $srcN, 'target' => $dstN, 'exists' => $there,
                       'columns' => $there ? count(array_intersect(Migrator::columns($src, $t), Migrator::columns($dst, $t))) : 0];
        }

        return ['tables' => $rows, 'blocking' => $blocking,
                'missing' => array_values(array_map(static fn($r) => $r['table'],
                    array_filter($rows, static fn($r) => !$r['exists'])))];
    }

    /** Create the target's tables from db/schema.<driver>.sql. Idempotent. */
    public static function applySchema(PDO $dst, string $driver): int
    {
        $f = AV_ROOT . '/db/schema.' . ($driver === 'pgsql' ? 'pgsql' : 'mysql') . '.sql';
        if (!is_file($f)) throw new RuntimeException('No schema file at ' . $f);
        $n = 0;
        /* Comments out first, as Database::applyServerSchema() does: some hold a
           ';' ("survives a slug rename); NULL until assigned"), and splitting on
           it cut the articles table in two — MySQL refused the first half with a
           1064, and the migration stopped before a single row was copied. */
        $sql = (string) preg_replace('/--[^\n]*/', '', (string) file_get_contents($f));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt === '' || str_starts_with($stmt, '--')) continue;
            try { $dst->exec($stmt); $n++; }
            catch (Throwable $e) {
                /* "Already there" is expected on a re-run — MySQL has no
                   CREATE INDEX IF NOT EXISTS, so every retry raises 1061. Any
                   OTHER error is the target telling us the schema did not
                   land, and swallowing it would mean copying rows into tables
                   that are not shaped the way this site expects. */
                if (Database::isBenignSchemaError($e)) continue;
                throw new RuntimeException('The target refused part of the schema: ' . $e->getMessage()
                    . ' — nothing has been copied.', 0, $e);
            }
        }

        return $n;
    }

    /**
     * Begin, or begin again. `replace` empties the target's tables first —
     * required when it already holds rows, because copying into them would
     * double every record rather than fail.
     */
    public static function begin(PDO $src, PDO $dst, array $conf, bool $replace): array
    {
        /* A table this site made for itself as a feature first ran is not in
           db/schema.mysql.sql. Make each one on the target now, shaped like the
           source, or its rows are left behind (they were: 24 of ~150 tables
           moved). One the target refuses is named, not skipped in silence. */
        $refused = [];
        if (Migrator::driver($dst) === 'mysql' && Migrator::driver($src) === 'sqlite') {
            foreach (Migrator::allTables($src) as $t) {
                if (Migrator::tableExists($dst, $t)) {
                    /* …and a table the schema file made is older than the source's. */
                    array_push($refused, ...Migrator::addMissingColumns($src, $dst, $t));
                    continue;
                }
                $why = Migrator::createLike($src, $dst, $t);
                if ($why !== null) $refused[] = $t . ': ' . $why;
            }
        }
        if ($refused) {
            throw new RuntimeException('The target would not create ' . count($refused) . ' table(s) — nothing has been copied. ' . implode(' | ', array_slice($refused, 0, 5)));
        }
        $plan = self::plan($src, $dst);
        if ($plan['blocking'] && !$replace) {
            throw new RuntimeException('The target already holds rows in: ' . implode(', ', $plan['blocking'])
                . '. Tick "replace what is there" to empty those tables first, or point at an empty database.');
        }

        $tables = [];
        foreach ($plan['tables'] as $r) {
            if (!$r['exists']) continue;
            $tables[$r['table']] = ['src' => $r['source'], 'copied' => 0, 'cursor' => 0, 'done' => $r['source'] === 0];
        }
        if (!$tables) throw new RuntimeException('The target has none of this site\'s tables. Apply the schema first.');

        if ($replace) {
            $drv = Migrator::driver($dst);
            foreach (array_reverse(array_keys($tables)) as $t) {
                try {
                    $dst->exec(($drv === 'sqlite' ? 'DELETE FROM ' : 'TRUNCATE TABLE ') . Migrator::q($dst, $t)
                        . ($drv === 'pgsql' ? ' RESTART IDENTITY CASCADE' : ''));
                } catch (Throwable $e) {
                    /* A foreign key can refuse a TRUNCATE; DELETE is accepted where it is not. */
                    try { $dst->exec('DELETE FROM ' . Migrator::q($dst, $t)); } catch (Throwable $e2) {}
                }
            }
        }

        $s = ['v' => 1, 'started' => gmdate('c'), 'target' => self::label($conf), 'replace' => $replace,
              'order' => array_keys($tables), 'tables' => $tables, 'phase' => 'copy', 'error' => '',
              'skipped' => $plan['missing']];
        self::put($s);

        return $s;
    }

    /**
     * One slice of work, bounded by time rather than by rows, so the answer
     * comes back before the host cuts the request off.
     *
     * $maxSlices caps how many slices a single call may copy, whatever the
     * clock says. Zero means the time budget alone decides, which is what a
     * host wants. A caller that needs the work split into fixed, repeatable
     * units — a test proving the cursor really resumes, or a driver pacing
     * itself against a server that bills by the query — sets it to 1.
     */
    public static function step(PDO $src, PDO $dst, float $budgetSec = 10.0, ?string $expect = null, int $maxSlices = 0): array
    {
        $s = self::state();
        if ($s === null) throw new RuntimeException('No migration is running. Start one first.');
        if (($s['phase'] ?? '') === 'done') return $s;
        /* The job records which target it is filling, and a slice must go to
           that one. Resuming with different credentials would otherwise pour
           the second half of a copy into another database — and the row
           counts at the end would look fine on both. */
        if ($expect !== null && ($s['target'] ?? '') !== $expect) {
            throw new RuntimeException('This migration is filling ' . (string) ($s['target'] ?? 'another database')
                . ', not ' . $expect . '. Finish it, or discard it, before starting a different one.');
        }

        /* The floor is small on purpose: the caller sets the budget from what
           the host allows, and a budget below it would buy no work at all. */
        $deadline = microtime(true) + max(0.05, $budgetSec);
        $slices = 0;
        try {
            foreach ($s['order'] as $t) {
                if (!empty($s['tables'][$t]['done'])) continue;
                $spent = false;
                while (microtime(true) < $deadline) {
                    $moved = self::slice($src, $dst, $t, $s['tables'][$t]['cursor']);
                    $s['tables'][$t]['copied'] += $moved['rows'];
                    $s['tables'][$t]['cursor']  = $moved['cursor'];
                    $slices++;
                    $spent = $maxSlices > 0 && $slices >= $maxSlices;
                    if ($moved['rows'] < self::SLICE) { $s['tables'][$t]['done'] = true; break; }
                    if ($spent) break;
                }
                self::put($s);
                if ($spent || microtime(true) >= $deadline) return $s;
            }
            $s['phase'] = 'done';
            $s['finished'] = gmdate('c');
            self::put($s);
        } catch (Throwable $e) {
            $s['error'] = $e->getMessage();
            $s['phase'] = 'error';
            self::put($s);
        }

        return $s;
    }

    /** Copy up to SLICE rows after $cursor. Returns the new cursor. */
    private static function slice(PDO $src, PDO $dst, string $t, int $cursor): array
    {
        $cols = array_values(array_intersect(Migrator::columns($src, $t), Migrator::columns($dst, $t)));
        if (!$cols) return ['rows' => 0, 'cursor' => $cursor];

        $sel = $src->prepare('SELECT rowid AS __rid, ' . implode(', ', array_map(fn($c) => Migrator::q($src, $c), $cols))
            . ' FROM ' . Migrator::q($src, $t) . ' WHERE rowid > ? ORDER BY rowid LIMIT ' . self::SLICE);
        $sel->execute([$cursor]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['rows' => 0, 'cursor' => $cursor];

        $ins = $dst->prepare('INSERT INTO ' . Migrator::q($dst, $t)
            . ' (' . implode(', ', array_map(fn($c) => Migrator::q($dst, $c), $cols)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');

        $last = $cursor;
        $dst->beginTransaction();
        try {
            foreach ($rows as $r) {
                $last = (int) $r['__rid'];
                unset($r['__rid']);
                $ins->execute(array_values(array_map(static fn($c) => $r[$c] ?? null, $cols)));
            }
            $dst->commit();
        } catch (Throwable $e) {
            if ($dst->inTransaction()) $dst->rollBack();
            throw new RuntimeException("Copying '{$t}' stopped at row {$last}: " . $e->getMessage(), 0, $e);
        }

        return ['rows' => count($rows), 'cursor' => $last];
    }

    /** Count both ends, table by table. The only answer worth trusting. */
    public static function verify(PDO $src, PDO $dst): array
    {
        $rows = []; $ok = true;
        foreach (self::tables($src) as $t) {
            if (!Migrator::tableExists($dst, $t)) {
                $rows[] = ['table' => $t, 'source' => Migrator::count($src, $t), 'target' => null, 'ok' => false];
                $ok = false;
                continue;
            }
            $a = Migrator::count($src, $t);
            $b = Migrator::count($dst, $t);
            /* The job's own progress row is written as it runs, so the two
               ends differ by it alone and that is not a mismatch. */
            $same = $t === 'app_meta' ? abs($a - $b) <= 1 : $a === $b;
            if (!$same) $ok = false;
            $rows[] = ['table' => $t, 'source' => $a, 'target' => $b, 'ok' => $same];
        }

        return ['ok' => $ok, 'tables' => $rows];
    }
}
