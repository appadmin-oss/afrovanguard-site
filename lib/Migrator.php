<?php
/**
 * lib/Migrator.php — enterprise-grade data migration between any two PDO
 * databases (SQLite ⇄ MySQL ⇄ Postgres). Copies every row, preserving primary
 * keys so foreign keys stay intact, in dependency-safe order.
 *
 * Properties:
 *  - FK-safe order (parents before children); truncation runs in reverse.
 *  - Column intersection (only columns present in BOTH ends), so schema drift is
 *    tolerated rather than fatal.
 *  - Batched, transactional inserts (bounded transaction size).
 *  - Postgres SERIAL sequences are re-synced to MAX(id) after each table.
 *  - Row-count verification per table; a structured report is returned.
 *  - dry-run (count only) and a non-empty-target guard (refuses to double-insert
 *    unless truncate is requested).
 *
 * It takes two explicit PDO handles and is independent of the Database singleton,
 * so it can run source→target without the app's global connection getting in the
 * way. Identifiers are quoted per-driver (handles reserved columns like `key`).
 */
declare(strict_types=1);

final class Migrator
{
    /** Dependency-safe table order (parents first). Children truncate in reverse. */
    const ORDER = [
        'categories', 'lms_users', 'courses',
        'articles', 'modules', 'lessons',
        'sections', 'related', 'reactions', 'subscribers',
        'lms_sessions', 'memberships', 'enrollments',
        'course_enrolment', 'lesson_progress', 'certificates', 'quiz_attempts', 'payments',
        'diary_entries', 'auth_illustrations', 'lms_audit',
        'app_meta', 'communities', 'celebrations', 'team',
    ];

    public static function driver(PDO $db): string
    {
        return (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** Quote an identifier for the given driver (mysql backticks, else double-quotes). */
    public static function q(PDO $db, string $ident): string
    {
        return self::driver($db) === 'mysql' ? '`' . str_replace('`', '', $ident) . '`'
                                             : '"' . str_replace('"', '', $ident) . '"';
    }

    public static function tableExists(PDO $db, string $t): bool
    {
        if (self::driver($db) === 'sqlite') {
            $s = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
        } else {
            $s = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_name = ?'
                . (self::driver($db) === 'mysql' ? ' AND table_schema = DATABASE()' : ''));
        }
        $s->execute([$t]);
        return (bool) $s->fetchColumn();
    }

    /** Ordered column names of a table on the given connection. */
    public static function columns(PDO $db, string $t): array
    {
        $out = [];
        if (self::driver($db) === 'sqlite') {
            foreach ($db->query('PRAGMA table_info(' . self::q($db, $t) . ')') as $r) { $out[] = (string) $r['name']; }
            return $out;
        }
        $s = $db->prepare('SELECT column_name FROM information_schema.columns WHERE table_name = ?'
            . (self::driver($db) === 'mysql' ? ' AND table_schema = DATABASE()' : '')
            . ' ORDER BY ordinal_position');
        $s->execute([$t]);
        return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function count(PDO $db, string $t): int
    {
        return (int) $db->query('SELECT COUNT(*) FROM ' . self::q($db, $t))->fetchColumn();
    }

    /**
     * Migrate $src → $dst. Options:
     *   bool   dryRun   — count only, copy nothing
     *   bool   truncate — clear each target table (reverse FK order) first
     *   int    batch    — rows per transaction (default 500)
     *   array  tables   — restrict to this subset
     *   callable log    — fn(string) progress sink
     * Returns: [table => ['source'=>int,'copied'=>int,'target'=>int,'ok'=>bool]]
     */
    public static function migrate(PDO $src, PDO $dst, array $opts = []): array
    {
        $dryRun   = !empty($opts['dryRun']);
        $truncate = !empty($opts['truncate']);
        $batch    = max(1, (int) ($opts['batch'] ?? 500));
        $only     = $opts['tables'] ?? null;
        $log      = $opts['log'] ?? static function (): void {};
        $dstDrv   = self::driver($dst);

        $tables = array_values(array_filter(self::ORDER, static function ($t) use ($src, $dst, $only) {
            return Migrator::tableExists($src, $t) && Migrator::tableExists($dst, $t)
                && (!$only || in_array($t, $only, true));
        }));

        if ($truncate && !$dryRun) {
            foreach (array_reverse($tables) as $t) {
                $dst->exec(($dstDrv === 'sqlite' ? 'DELETE FROM ' : 'TRUNCATE TABLE ') . self::q($dst, $t)
                    . ($dstDrv === 'pgsql' ? ' RESTART IDENTITY CASCADE' : ''));
            }
            $log("• truncated " . count($tables) . " target tables\n");
        }

        $report = [];
        foreach ($tables as $t) {
            $srcCount  = self::count($src, $t);
            $dstBefore = self::count($dst, $t);
            $cols = array_values(array_intersect(self::columns($src, $t), self::columns($dst, $t)));

            if ($dryRun) {
                $report[$t] = ['source' => $srcCount, 'copied' => 0, 'target' => $dstBefore, 'ok' => true];
                $log(sprintf("  %-18s %6d rows (dry-run)\n", $t, $srcCount));
                continue;
            }
            if ($dstBefore > 0 && !$truncate) {
                throw new RuntimeException("Target table '$t' already has $dstBefore rows — pass truncate to replace it.");
            }

            $copied = self::copyTable($src, $dst, $t, $cols, $batch);

            if ($dstDrv === 'pgsql' && in_array('id', $cols, true)) {
                // Re-sync the SERIAL sequence so future inserts don't collide.
                $dst->exec("SELECT setval(pg_get_serial_sequence('" . $t . "','id'), "
                    . "GREATEST((SELECT COALESCE(MAX(id), 0) FROM " . self::q($dst, $t) . "), 1), "
                    . "(SELECT COUNT(*) FROM " . self::q($dst, $t) . ") > 0)");
            }

            $after = self::count($dst, $t);
            $report[$t] = ['source' => $srcCount, 'copied' => $copied, 'target' => $after, 'ok' => $after === $srcCount + $dstBefore];
            $log(sprintf("  %-18s %6d → %-6d %s\n", $t, $srcCount, $after, $report[$t]['ok'] ? '✓' : '✗ COUNT MISMATCH'));
        }
        return $report;
    }

    /** Stream rows from source and insert into target in bounded transactions. */
    private static function copyTable(PDO $src, PDO $dst, string $t, array $cols, int $batch): int
    {
        if (!$cols) return 0;
        $srcCols = implode(', ', array_map(fn($c) => self::q($src, $c), $cols));
        $dstCols = implode(', ', array_map(fn($c) => self::q($dst, $c), $cols));
        $ph      = implode(', ', array_fill(0, count($cols), '?'));

        $sel = $src->prepare('SELECT ' . $srcCols . ' FROM ' . self::q($src, $t));
        $sel->execute();
        $ins = $dst->prepare('INSERT INTO ' . self::q($dst, $t) . ' (' . $dstCols . ') VALUES (' . $ph . ')');

        $n = 0;
        $dst->beginTransaction();
        try {
            while ($row = $sel->fetch(PDO::FETCH_NUM)) {
                $ins->execute($row);
                if (++$n % $batch === 0) { $dst->commit(); $dst->beginTransaction(); }
            }
            $dst->commit();
        } catch (Throwable $e) {
            if ($dst->inTransaction()) $dst->rollBack();
            throw new RuntimeException("Failed copying '$t' at row $n: " . $e->getMessage(), 0, $e);
        }
        return $n;
    }
}
