<?php
/**
 * lib/Database.php — PDO / SQLite connection (singleton).
 *
 * One shared connection for the whole Diary. On first use it creates the
 * database from db/schema.sql and seeds it from db/content.php, so a fresh
 * deploy is self-bootstrapping — no manual migration step required.
 */
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        // Driver is selectable for portability (sqlite | mysql | pgsql); SQLite
        // stays the default so existing deploys are byte-for-byte unchanged.
        $driver = strtolower((string) (getenv('AV_DB_DRIVER') ?: 'sqlite'));
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $fresh = false;

        if ($driver === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                throw new RuntimeException('pdo_sqlite extension is required for the Diary database.');
            }
            $path = AV_DB_PATH;
            $dir  = dirname($path);
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $fresh = !is_file($path);
            $pdo = new PDO('sqlite:' . $path, null, null, $opts);
            $pdo->exec('PRAGMA foreign_keys = ON');
        } elseif ($driver === 'mysql' || $driver === 'pgsql') {
            // DSN from AV_DB_DSN, or assembled from discrete host/name/port env vars.
            $dsn = (string) getenv('AV_DB_DSN');
            if ($dsn === '') {
                $host = getenv('AV_DB_HOST') ?: '127.0.0.1';
                $name = getenv('AV_DB_NAME') ?: 'afrovanguard';
                $port = getenv('AV_DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306');
                $dsn  = $driver === 'pgsql'
                    ? "pgsql:host={$host};port={$port};dbname={$name}"
                    : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            }
            $pdo = new PDO($dsn, getenv('AV_DB_USER') ?: null, getenv('AV_DB_PASS') ?: null, $opts);
        } else {
            throw new RuntimeException("Unsupported AV_DB_DRIVER: {$driver}");
        }
        self::$pdo = $pdo;

        // Auto-migrate + seed on a brand-new database, or if the core table is
        // missing. The schema DDL is currently SQLite-shaped, so for mysql/pgsql
        // the schema is provisioned out-of-band until the DDL-translation slice
        // lands; the connection + portable helpers below already work on all three.
        if ($driver === 'sqlite') {
            if ($fresh || !self::tableExists('articles')) {
                self::migrate();
                self::seedIfEmpty();
            }
            self::ensureColumns();      // additive upgrades for already-deployed DBs
            self::ensureAcademy();      // create + seed academy tables if missing
            self::ensureDiaryEntries(); // member-contributed diary (categories + moderation)
            self::ensureLmsVerify();    // email-verification columns (+ grandfather existing accounts)
            self::maybePurgeDemo();     // one-time removal of shipped demo content
        }
        return self::$pdo;
    }

    /**
     * Member-contributed Vanguard Diary entries (Event / Private / Public).
     * Idempotent so already-deployed databases pick it up on the next request,
     * exactly like ensureAcademy(). Kept separate from `articles` for privacy.
     */
    private static function ensureDiaryEntries(): void
    {
        if (self::tableExists('diary_entries')) return;
        self::$pdo->exec(
            "CREATE TABLE IF NOT EXISTS diary_entries (
               id             INTEGER PRIMARY KEY AUTOINCREMENT,
               author_id      INTEGER NOT NULL,
               kind           TEXT NOT NULL DEFAULT 'private',
               title          TEXT NOT NULL DEFAULT '',
               body           TEXT NOT NULL,
               entry_date     TEXT NOT NULL,
               status         TEXT NOT NULL DEFAULT 'logged',
               published_slug TEXT,
               review_note    TEXT,
               created_at     TEXT NOT NULL DEFAULT (datetime('now')),
               updated_at     TEXT NOT NULL DEFAULT (datetime('now'))
             );
             CREATE INDEX IF NOT EXISTS idx_diary_entries_author ON diary_entries(author_id, entry_date DESC);
             CREATE INDEX IF NOT EXISTS idx_diary_entries_mod    ON diary_entries(kind, status);"
        );
    }

    /**
     * Email-verification columns on lms_users (idempotent). Adds email_verified
     * (default 0) + token columns, and GRANDFATHERS every existing account as
     * verified so introducing verification never locks out current users — only
     * new signups start unverified.
     */
    private static function ensureLmsVerify(): void
    {
        if (!self::tableExists('lms_users')) return;
        if (!self::columnExists('lms_users', 'email_verified')) {
            self::$pdo->exec("ALTER TABLE lms_users ADD COLUMN email_verified INTEGER NOT NULL DEFAULT 0");
            self::$pdo->exec("UPDATE lms_users SET email_verified = 1");
        }
        if (!self::columnExists('lms_users', 'verify_hash'))    self::$pdo->exec("ALTER TABLE lms_users ADD COLUMN verify_hash TEXT");
        if (!self::columnExists('lms_users', 'verify_expires')) self::$pdo->exec("ALTER TABLE lms_users ADD COLUMN verify_expires TEXT");
    }

    /* ── Small key/value store for one-time migrations/flags ──
       `key` is a reserved word in MySQL, so it is back-quoted there; SQLite and
       Postgres accept it bare. The SQLite statements stay byte-identical. */
    private static function ensureMeta(): void { self::ensureMetaOn(self::pdo()); }

    /** Create the app_meta key/value table on an arbitrary connection (driver-aware).
     *  Public so the migrator can provision it on a target before copying data. */
    public static function ensureMetaOn(PDO $pdo): void
    {
        switch ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
                $pdo->exec('CREATE TABLE IF NOT EXISTS app_meta (`key` VARCHAR(191) PRIMARY KEY, value TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                break;
            case 'pgsql':
                $pdo->exec('CREATE TABLE IF NOT EXISTS app_meta (key VARCHAR(191) PRIMARY KEY, value TEXT)');
                break;
            default:
                $pdo->exec('CREATE TABLE IF NOT EXISTS app_meta (key TEXT PRIMARY KEY, value TEXT)');
        }
    }
    public static function metaGet(string $k): ?string
    {
        self::ensureMeta();
        $key = self::driver() === 'mysql' ? '`key`' : 'key';
        $s = self::$pdo->prepare("SELECT value FROM app_meta WHERE {$key} = ?"); $s->execute([$k]);
        $v = $s->fetchColumn(); return $v === false ? null : (string) $v;
    }
    public static function metaSet(string $k, string $v): void
    {
        self::ensureMeta();
        $sql = self::driver() === 'mysql'
            ? 'INSERT INTO app_meta (`key`, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
            : 'INSERT INTO app_meta (key, value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value';
        self::$pdo->prepare($sql)->execute([$k, $v]);
    }

    /**
     * Remove the demo content this project used to ship — once per database.
     * Targeted by exact demo article slugs and a unique sentinel in the demo
     * lesson bodies, so real content authored in the Studio is never touched.
     */
    private static function maybePurgeDemo(): void
    {
        if (self::metaGet('demo_purged_v1') !== null) return;
        try {
            $slugs = [
                'introducing-the-afrovanguard-diary',
                'school-storm-reaching-30000-children',
                'the-math-behind-1-million-leaders',
                'rebuilding-summer-school-six-lgas',
                'what-techome-taught-us',
            ];
            $in = implode(',', array_fill(0, count($slugs), '?'));
            // Articles cascade to sections/related/reactions (FK ON DELETE CASCADE).
            self::$pdo->prepare("DELETE FROM articles WHERE slug IN ($in)")->execute($slugs);
            if (self::tableExists('lessons')) {
                // Demo lessons all carry this exact phrase; real ones won't.
                self::$pdo->exec("DELETE FROM lessons WHERE body_html LIKE '%Full lesson content is authored in the Studio.%'");
            }
            if (self::tableExists('modules')) {
                // Remove the now-empty demo modules (by their known titles only).
                self::$pdo->exec(
                    "DELETE FROM modules WHERE title IN ('Foundations','Building','Becoming a mentor','Orientation','Practicum')
                     AND id NOT IN (SELECT module_id FROM lessons WHERE module_id IS NOT NULL)
                     AND course_id IN (SELECT id FROM courses WHERE slug IN ('techome','africa-gates'))"
                );
            }
            self::metaSet('demo_purged_v1', '1');
        } catch (Throwable $e) {
            error_log('[db] demo purge skipped: ' . $e->getMessage());
        }
    }

    /** Create the Academy tables (idempotent) and seed them once. */
    private static function ensureAcademy(): void
    {
        if (!self::tableExists('courses')) {
            self::$pdo->exec(file_get_contents(AV_ROOT . '/db/schema.sql'));
        }
        // Course access columns (additive)
        $ccols = [];
        foreach (self::$pdo->query('PRAGMA table_info(courses)') as $r) { $ccols[$r['name']] = true; }
        $cadd = [
            'access_type'   => "ALTER TABLE courses ADD COLUMN access_type TEXT NOT NULL DEFAULT 'open'", // open|tracked|membership|paid
            'price_ngn'     => "ALTER TABLE courses ADD COLUMN price_ngn INTEGER NOT NULL DEFAULT 0",
            'instructor_id' => "ALTER TABLE courses ADD COLUMN instructor_id INTEGER",
        ];
        foreach ($cadd as $name => $sql) { if (!isset($ccols[$name])) self::$pdo->exec($sql); }
        // LMS tables (idempotent)
        if (!self::tableExists('lessons')) { self::$pdo->exec(file_get_contents(AV_ROOT . '/db/schema.sql')); }
        // Tables added after the LMS shipped (idempotent for deployed DBs)
        if (!self::tableExists('certificates')) {
            self::$pdo->exec("CREATE TABLE IF NOT EXISTS certificates (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, course_id INTEGER NOT NULL, serial TEXT UNIQUE NOT NULL, issued_at TEXT NOT NULL DEFAULT (datetime('now')), UNIQUE(user_id, course_id))");
        }
        if (!self::tableExists('quiz_attempts')) {
            self::$pdo->exec("CREATE TABLE IF NOT EXISTS quiz_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, lesson_id INTEGER NOT NULL, score INTEGER NOT NULL DEFAULT 0, passed INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT (datetime('now')))");
        }
        if (!self::tableExists('payments')) {
            self::$pdo->exec("CREATE TABLE IF NOT EXISTS payments (id INTEGER PRIMARY KEY AUTOINCREMENT, reference TEXT UNIQUE NOT NULL, user_id INTEGER NOT NULL, provider TEXT NOT NULL DEFAULT 'paystack', kind TEXT NOT NULL, course_id INTEGER, amount_kobo INTEGER NOT NULL DEFAULT 0, currency TEXT NOT NULL DEFAULT 'NGN', status TEXT NOT NULL DEFAULT 'pending', created_at TEXT NOT NULL DEFAULT (datetime('now')), paid_at TEXT)");
        }
        // lessons.quiz_json (additive)
        $lcols = [];
        foreach (self::$pdo->query('PRAGMA table_info(lessons)') as $r) { $lcols[$r['name']] = true; }
        if (!isset($lcols['quiz_json'])) { self::$pdo->exec("ALTER TABLE lessons ADD COLUMN quiz_json TEXT"); }

        $n = (int) self::$pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
        if ($n === 0 && is_file(AV_ROOT . '/db/academy_content.php')) {
            require_once AV_ROOT . '/db/academy_seed.php';
            av_seed_courses(self::$pdo);
        }
        $lc = (int) self::$pdo->query('SELECT COUNT(*) FROM lessons')->fetchColumn();
        if ($lc === 0 && is_file(AV_ROOT . '/db/lessons_seed.php')) {
            require_once AV_ROOT . '/db/lessons_seed.php';
            av_seed_lessons(self::$pdo);
        }
    }

    /** Add columns introduced after the first release (idempotent). */
    private static function ensureColumns(): void
    {
        $cols = [];
        foreach (self::$pdo->query('PRAGMA table_info(articles)') as $r) { $cols[$r['name']] = true; }
        $add = [
            'cover_url'  => "ALTER TABLE articles ADD COLUMN cover_url TEXT",
            'og_image'   => "ALTER TABLE articles ADD COLUMN og_image TEXT",
            'status'     => "ALTER TABLE articles ADD COLUMN status TEXT NOT NULL DEFAULT 'published'",
            'updated_at' => "ALTER TABLE articles ADD COLUMN updated_at TEXT NOT NULL DEFAULT (datetime('now'))",
            'format'     => "ALTER TABLE articles ADD COLUMN format TEXT NOT NULL DEFAULT 'standard'",
        ];
        foreach ($add as $name => $sql) {
            if (!isset($cols[$name])) { self::$pdo->exec($sql); }
        }
    }

    /** Active PDO driver name: 'sqlite' | 'mysql' | 'pgsql'. */
    public static function driver(): string
    {
        return self::$pdo ? (string) self::$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) : 'sqlite';
    }

    public static function tableExists(string $name): bool
    {
        if (self::driver() === 'sqlite') {
            $st = self::$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
        } else {
            $st = self::$pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_name = ?' .
                (self::driver() === 'mysql' ? ' AND table_schema = DATABASE()' : '')
            );
        }
        $st->execute([$name]);
        return (bool) $st->fetchColumn();
    }

    /** Portable "does this column exist" check (SQLite PRAGMA / information_schema). */
    public static function columnExists(string $table, string $col): bool
    {
        if (self::driver() === 'sqlite') {
            foreach (self::$pdo->query('PRAGMA table_info(' . $table . ')') as $r) {
                if (($r['name'] ?? '') === $col) return true;
            }
            return false;
        }
        $st = self::$pdo->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?' .
            (self::driver() === 'mysql' ? ' AND table_schema = DATABASE()' : '')
        );
        $st->execute([$table, $col]);
        return (bool) $st->fetchColumn();
    }

    /** Portable "current timestamp" SQL expression for runtime queries. */
    public static function nowExpr(): string
    {
        switch (self::driver()) {
            case 'mysql': return 'NOW()';
            case 'pgsql': return 'CURRENT_TIMESTAMP';
            default:      return "datetime('now')";
        }
    }

    /** Quote an identifier that collides with a reserved word. Only MySQL needs it
     *  for the words this app uses (e.g. `key`); SQLite and Postgres accept them bare,
     *  so their SQL stays byte-identical. */
    public static function quoteIdent(string $ident): string
    {
        return self::driver() === 'mysql' ? "`{$ident}`" : $ident;
    }

    /** Driver-correct "insert; ignore a duplicate" with positional (?) placeholders. */
    public static function insertIgnore(string $table, array $cols): string
    {
        $list = implode(', ', $cols);
        $ph   = implode(', ', array_fill(0, count($cols), '?'));
        return self::insertIgnoreExpr($table, $list, $ph);
    }

    /**
     * Driver-correct INSERT-IGNORE with caller-supplied column + VALUES strings,
     * so callers using named placeholders (e.g. the seeders) stay portable too.
     * SQLite output is unchanged from the hand-written `INSERT OR IGNORE`.
     */
    public static function insertIgnoreExpr(string $table, string $colsCsv, string $valuesCsv): string
    {
        switch (self::driver()) {
            case 'mysql': return "INSERT IGNORE INTO {$table} ({$colsCsv}) VALUES ({$valuesCsv})";
            case 'pgsql': return "INSERT INTO {$table} ({$colsCsv}) VALUES ({$valuesCsv}) ON CONFLICT DO NOTHING";
            default:      return "INSERT OR IGNORE INTO {$table} ({$colsCsv}) VALUES ({$valuesCsv})";
        }
    }

    /**
     * Translate the canonical SQLite DDL to another driver's dialect.
     * SQLite returns the input UNCHANGED (the live runtime path is byte-identical
     * — this method is only used to GENERATE db/schema.mysql.sql + schema.pgsql.sql).
     * MySQL/Postgres output is best-effort scaffolding and MUST be validated on a
     * live instance before switching production — see docs/db-portability.md.
     */
    public static function translateDDL(string $sql, ?string $driver = null): string
    {
        $driver = $driver ?: self::driver();
        if ($driver === 'sqlite') return $sql;

        $sql = preg_replace('/^\s*PRAGMA[^;]*;\s*$/mi', '', $sql);            // drop SQLite-only PRAGMAs
        $sql = $driver === 'pgsql'
            ? preg_replace('/\bINTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT\b/i', 'SERIAL PRIMARY KEY', $sql)
            : preg_replace('/\bINTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT\b/i', 'INTEGER PRIMARY KEY AUTO_INCREMENT', $sql);
        // TEXT used as a key needs a bounded type for MySQL indexes (harmless on PG).
        $sql = preg_replace('/\bTEXT\s+PRIMARY\s+KEY\b/i', 'VARCHAR(191) PRIMARY KEY', $sql);
        $sql = preg_replace('/\bTEXT(\s+UNIQUE)\b/i', 'VARCHAR(191)$1', $sql);
        $sql = preg_replace("/DEFAULT\s*\(\s*datetime\('now'\)\s*\)/i", 'DEFAULT CURRENT_TIMESTAMP', $sql);
        // A TEXT column can't carry a CURRENT_TIMESTAMP default on MySQL/Postgres —
        // promote those created/updated columns to a real timestamp type. (Date
        // columns without a default stay TEXT: the app stores/compares ISO strings.)
        $ts  = $driver === 'mysql' ? 'DATETIME' : 'TIMESTAMP';
        $sql = preg_replace('/\bTEXT(\s+NOT\s+NULL)?\s+DEFAULT\s+CURRENT_TIMESTAMP/i', $ts . '$1 DEFAULT CURRENT_TIMESTAMP', $sql);

        if ($driver === 'mysql') {
            $sql = str_replace("\n);", "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;", $sql); // FKs + unicode
            $sql = preg_replace('/CREATE\s+INDEX\s+IF\s+NOT\s+EXISTS/i', 'CREATE INDEX', $sql); // MySQL lacks IF NOT EXISTS on indexes
        }
        return $sql;
    }

    public static function migrate(): void
    {
        $sql = file_get_contents(AV_ROOT . '/db/schema.sql');
        if ($sql === false) throw new RuntimeException('Cannot read db/schema.sql');
        self::$pdo->exec($sql);
    }

    /** Seed from the canonical content file only when the DB has no articles. */
    public static function seedIfEmpty(): void
    {
        $count = (int) self::$pdo->query('SELECT COUNT(*) FROM articles')->fetchColumn();
        if ($count > 0) return;
        require_once AV_ROOT . '/db/seed.php'; // defines av_seed(PDO)
        av_seed(self::$pdo);
    }
}
