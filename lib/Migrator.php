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
    /**
     * Dependency-safe ORDER (parents first); children truncate in reverse.
     *
     * This is the order, NOT the membership. It used to be both, and every
     * table absent from it was silently never copied — on this database that
     * was ten of them, av_settings (every stored secret), member_passes,
     * course_access, lesson_notes and the diary's comment tables among them.
     * A migration reported success and left them behind. allTables() now
     * takes the source's own list and uses this only to sort it.
     */
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

    /**
     * Every table the source actually has, the ones ORDER names first (so
     * parents still precede children) and everything else after. Reading the
     * source rather than a hand-written list is what stops a table added
     * next year from being dropped by a migration that reports success.
     */
    public static function allTables(PDO $src): array
    {
        $all = [];
        if (self::driver($src) === 'sqlite') {
            foreach ($src->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $r) {
                $all[] = (string) $r['name'];
            }
        } else {
            $sql = 'SELECT table_name FROM information_schema.tables WHERE '
                 . (self::driver($src) === 'mysql' ? 'table_schema = DATABASE()' : "table_schema = 'public'");
            foreach ($src->query($sql) as $r) { $all[] = (string) array_values($r)[0]; }
        }
        sort($all);
        $known = array_values(array_filter(self::ORDER, static fn($t) => in_array($t, $all, true)));

        return array_merge($known, array_values(array_diff($all, $known)));
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

    /**
     * Create table $t on the MySQL target, shaped like the SQLite source.
     *
     * Only the tables in db/schema.mysql.sql existed on a fresh target — the
     * rest (some 125: roles, meetings, tasks, gate attendance, …) are created
     * by each feature the first time it runs, so a migration skipped them and
     * their rows never moved. This builds each one from the source's own
     * column list (PRAGMA table_info), not from its DDL text: a type is mapped,
     * a default carried, the key and indexes kept. Every rule is one MySQL
     * itself imposes:
     *   · TEXT can neither be a key nor be indexed whole — an indexed or key
     *     TEXT column becomes VARCHAR(191) when its data fits, else it is
     *     indexed by a 191-character prefix;
     *   · a row is at most 64 KB, so a long VARCHAR(n>255) becomes TEXT;
     *   · a TEXT or BLOB default must be an expression: DEFAULT ('…').
     * Returns null on success, else MySQL's refusal.
     */
    public static function createLike(PDO $src, PDO $dst, string $t): ?string
    {
        $cols = $src->query('PRAGMA table_info(' . self::q($src, $t) . ')')->fetchAll(PDO::FETCH_ASSOC);
        if (!$cols) return 'the source has no columns for ' . $t;
        $ddl = (string) ($src->query("SELECT sql FROM sqlite_master WHERE type='table' AND name = " . $src->quote($t))->fetchColumn() ?: '');
        $auto = (bool) preg_match('/AUTOINCREMENT/i', $ddl);
        $pk = array_values(array_map(static fn($c) => (string) $c['name'], array_filter($cols, static fn($c) => (int) $c['pk'] > 0)));
        usort($cols, static fn($a, $b) => (int) $a['cid'] <=> (int) $b['cid']);

        /* Indexes: name, unique, columns. */
        $idx = [];
        foreach ($src->query('PRAGMA index_list(' . self::q($src, $t) . ')')->fetchAll(PDO::FETCH_ASSOC) as $i) {
            if (($i['origin'] ?? 'c') === 'pk') continue;
            $c = array_map(static fn($r) => (string) $r['name'], $src->query('PRAGMA index_info(' . self::q($src, (string) $i['name']) . ')')->fetchAll(PDO::FETCH_ASSOC));
            if ($c && !in_array('', $c, true)) $idx[] = ['unique' => (int) $i['unique'] === 1, 'cols' => $c];
        }
        $keyed = array_flip($pk);
        foreach ($idx as $i) foreach ($i['cols'] as $c) $keyed[$c] = true;

        $lines = []; $prefix = [];
        foreach ($cols as $c) {
            $n = (string) $c['name'];
            $decl = strtoupper(trim((string) $c['type']));
            if (in_array($n, $pk, true) && count($pk) === 1 && str_contains($decl, 'INT') && ($auto || $decl === 'INTEGER')) {
                $lines[] = self::q($dst, $n) . ' BIGINT NOT NULL AUTO_INCREMENT';
                continue;
            }
            $lines[] = self::columnDef($src, $dst, $t, $c, isset($keyed[$n]), $isPrefix);
            if ($isPrefix) $prefix[$n] = true;
        }
        $col = static fn(string $c) => self::q($dst, $c) . (isset($prefix[$c]) ? '(191)' : '');
        if ($pk) $lines[] = 'PRIMARY KEY (' . implode(', ', array_map($col, $pk)) . ')';
        $k = 0;
        foreach ($idx as $i) {
            $lines[] = ($i['unique'] ? 'UNIQUE ' : '') . 'KEY ' . self::q($dst, 'ix_' . substr(md5($t . '|' . implode(',', $i['cols'])), 0, 10) . '_' . (++$k))
                . ' (' . implode(', ', array_map($col, $i['cols'])) . ')';
        }
        try {
            /* The same collation as the tables already there (those from
               db/schema.mysql.sql and the site's own, which name no collation
               and so take the server's). Two collations on one database and
               any join between them is refused (1267: illegal mix). */
            $coll = self::collation($dst);
            $dst->exec('CREATE TABLE IF NOT EXISTS ' . self::q($dst, $t) . " (\n  " . implode(",\n  ", $lines) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" . ($coll !== '' ? ' COLLATE=' . $coll : ''));
            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Add to the target's table every column the source has and it lacks.
     * db/schema.mysql.sql is older than the SQLite tables it describes
     * (lms_users has since gained level, phone, …), and a column missing on
     * the target is a column whose data the copy leaves behind. Returns the
     * columns that could not be added, with MySQL's reason.
     */
    /** The collation the target's tables already use (lms_users, else articles), or '' for the server's own. */
    public static function collation(PDO $dst): string
    {
        static $memo = [];
        $k = spl_object_id($dst);
        if (isset($memo[$k])) return $memo[$k];
        $c = '';
        try {
            $st = $dst->query("SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('lms_users','articles') ORDER BY table_name = 'lms_users' DESC LIMIT 1");
            $c = (string) ($st ? $st->fetchColumn() : '');
        } catch (Throwable $e) {}
        return $memo[$k] = preg_match('/^utf8mb4_[a-z0-9_]+$/', $c) ? $c : '';
    }

    /** One column's MySQL definition from its SQLite PRAGMA table_info row. */
    public static function columnDef(PDO $src, PDO $dst, string $t, array $c, bool $keyed = false, ?bool &$prefix = null): string
    {
        $prefix = false;
        $n = (string) $c['name'];
        $decl = strtoupper(trim((string) $c['type']));
        $blob = false;
        if (str_contains($decl, 'INT')) $type = 'BIGINT';
        elseif (preg_match('/^(VAR)?CHAR\s*\((\d+)\)/', $decl, $m)) $type = (int) $m[2] > 255 && !$keyed ? 'TEXT' : 'VARCHAR(' . min((int) $m[2], 255) . ')';
        elseif (preg_match('/REAL|FLOA|DOUB/', $decl)) $type = 'DOUBLE';
        elseif (preg_match('/^(NUMERIC|DECIMAL)\s*\(\s*\d+\s*,\s*\d+\s*\)$/', $decl)) $type = $decl;
        elseif (str_contains($decl, 'BLOB')) { $type = 'LONGBLOB'; $blob = true; }
        elseif (preg_match('/BOOL/', $decl)) $type = 'TINYINT';
        else $type = 'TEXT';
        if ($type === 'TEXT' && $keyed) {
            $max = (int) $src->query('SELECT COALESCE(MAX(LENGTH(' . self::q($src, $n) . ')), 0) FROM ' . self::q($src, $t))->fetchColumn();
            if ($max <= 191) $type = 'VARCHAR(191)'; else $prefix = true;
        }
        if ($type === 'TEXT' && !$keyed) $type = 'MEDIUMTEXT';
        $line = self::q($dst, $n) . ' ' . $type . ((int) $c['notnull'] === 1 ? ' NOT NULL' : ' NULL');
        $d = $c['dflt_value'];
        if ($d !== null && strtoupper(trim((string) $d)) !== 'NULL') {
            $d = trim((string) $d);
            $long = in_array($type, ['MEDIUMTEXT', 'TEXT', 'LONGBLOB'], true) || $blob;
            if (preg_match("/^\\(?\\s*datetime\\s*\\(\\s*'now'/i", $d) || strtoupper($d) === 'CURRENT_TIMESTAMP') {
                $lit = $long ? '(CURRENT_TIMESTAMP)' : (str_starts_with($type, 'VARCHAR') ? null : 'CURRENT_TIMESTAMP');
                if ($lit !== null) $line .= ' DEFAULT ' . $lit;
            } else {
                if (preg_match('/^\((.*)\)$/s', $d, $m)) $d = trim($m[1]);
                $lit = preg_match("/^'.*'$/s", $d) || preg_match('/^-?\d+(\.\d+)?$/', $d) ? $d : $dst->quote($d);
                $line .= ' DEFAULT ' . ($long ? '(' . $lit . ')' : $lit);
            }
        }
        return $line;
    }

    public static function addMissingColumns(PDO $src, PDO $dst, string $t): array
    {
        $have = array_flip(self::columns($dst, $t));
        $bad = [];
        foreach ($src->query('PRAGMA table_info(' . self::q($src, $t) . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $n = (string) $c['name'];
            if (isset($have[$n])) continue;
            /* The column's MySQL definition, as createLike() would write it. */
            $def = self::columnDef($src, $dst, $t, $c);
            /* NOT NULL with no default cannot be added to rows that exist. */
            if (str_contains($def, ' NOT NULL') && !str_contains($def, ' DEFAULT ')) $def = str_replace(' NOT NULL', ' NULL', $def);
            try { $dst->exec('ALTER TABLE ' . self::q($dst, $t) . ' ADD COLUMN ' . $def); }
            catch (Throwable $e) { $bad[] = $t . '.' . $n . ': ' . $e->getMessage(); }
        }
        return $bad;
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

        $tables = array_values(array_filter(self::allTables($src), static function ($t) use ($src, $dst, $only) {
            return Migrator::tableExists($dst, $t) && (!$only || in_array($t, $only, true));
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
