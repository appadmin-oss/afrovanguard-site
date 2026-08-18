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

    /** When a configured MySQL/Postgres can't be reached we fall back to SQLite
     *  so the site stays up; this records which driver was requested so the
     *  admin (System Health) can SEE the fallback instead of silently running
     *  on the wrong database. null = no fallback (running on the chosen driver). */
    private static ?string $fellBackFrom = null;
    public static function fellBack(): ?string { return self::$fellBackFrom; }
    /** When true, an unreachable primary DB is a hard error rather than a silent SQLite fallback. */
    public static function dbStrict(): bool {
        $v = getenv('AV_DB_STRICT');
        return $v !== false && $v !== '' && !in_array(strtolower((string) $v), ['0', 'false', 'no', 'off'], true);
    }

    /** Bump to force a schema re-sync even when db/schema.sql is byte-identical
     *  (e.g. after changing one of the ensure/grandfathering steps). Normally you
     *  don't touch this — editing db/schema.sql changes its hash and re-syncs. */
    // Bumping this re-runs the additive migration steps on deployments whose
    // stamp already matches. Bumped to 2 (2026-08-18) because the academy access
    // columns shipped without a bump, so every settled database silently never got
    // them — and every academy admin query 500s on the first one it touches.
    private const SCHEMA_REV = 2;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        // Driver selection — MySQL is PRIORITISED. An explicit AV_DB_DRIVER always
        // wins; otherwise we infer the driver from the configured connection and
        // prefer MySQL whenever any MySQL config is present (a DSN, or discrete
        // AV_DB_* creds). SQLite is used only when MySQL isn't configured — and as
        // a safety net if a configured MySQL can't be reached, so the site stays up.
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $dsnEnv = (string) getenv('AV_DB_DSN');
        $driver = strtolower((string) getenv('AV_DB_DRIVER'));
        if ($driver === '') {
            if (stripos($dsnEnv, 'mysql:') === 0) $driver = 'mysql';
            elseif (stripos($dsnEnv, 'pgsql:') === 0) $driver = 'pgsql';
            elseif (getenv('AV_DB_NAME') || getenv('AV_DB_USER') || getenv('AV_DB_HOST') || getenv('AV_DB_PASS')) $driver = 'mysql'; // ← MySQL prioritised when any creds exist
            else $driver = 'sqlite';
        }
        $fresh = false;

        $connectSqlite = static function () use ($opts, &$fresh): PDO {
            if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('pdo_sqlite extension is required for the Diary database.');
            $path = AV_DB_PATH; $dir = dirname($path);
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $fresh = !is_file($path);
            $pdo = new PDO('sqlite:' . $path, null, null, $opts);
            $pdo->exec('PRAGMA foreign_keys = ON');
            // WAL lets readers and a writer work concurrently (SQLite's default
            // rollback journal locks the whole DB on every write); busy_timeout
            // makes a blocked write wait briefly instead of failing outright.
            try { $pdo->exec('PRAGMA journal_mode = WAL'); $pdo->exec('PRAGMA busy_timeout = 5000'); $pdo->exec('PRAGMA synchronous = NORMAL'); } catch (\Throwable $e) {}
            return $pdo;
        };

        if ($driver === 'sqlite') {
            $pdo = $connectSqlite();
        } elseif ($driver === 'mysql' || $driver === 'pgsql') {
            $dsn = $dsnEnv;
            if ($dsn === '') {
                $host = getenv('AV_DB_HOST') ?: '127.0.0.1';
                $name = getenv('AV_DB_NAME') ?: 'afrovanguard';
                $port = getenv('AV_DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306');
                $dsn  = $driver === 'pgsql'
                    ? "pgsql:host={$host};port={$port};dbname={$name}"
                    : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            }
            try {
                $pdo = new PDO($dsn, getenv('AV_DB_USER') ?: null, getenv('AV_DB_PASS') ?: null, $opts);
            } catch (Throwable $e) {
                // Configured MySQL/Postgres unreachable. By default we fall back to
                // SQLite so the public site stays up (logged + surfaced in System
                // Health). But silently serving the LIVE site from an empty/stale
                // local SQLite file can mask a real outage and risk data divergence,
                // so AV_DB_STRICT=1 makes the failure LOUD (re-throw → hard error)
                // instead of degrading. Recommended for production.
                error_log('[db] ' . $driver . ' connection failed (' . $e->getMessage() . ')'
                    . (self::dbStrict() ? ' — AV_DB_STRICT is set, refusing to fall back to SQLite' : ' — falling back to SQLite'));
                if (self::dbStrict()) {
                    throw new RuntimeException('Primary ' . $driver . ' database is unreachable and AV_DB_STRICT is set.', 0, $e);
                }
                self::$fellBackFrom = $driver;
                $driver = 'sqlite';
                $pdo = $connectSqlite();
            }
        } else {
            throw new RuntimeException("Unsupported AV_DB_DRIVER: {$driver}");
        }
        self::$pdo = $pdo;

        // Provision + migrate on first use so MySQL is a true first-class driver:
        // a freshly-configured server database sets ITSELF up (applies the
        // per-driver schema + seeds) with no SSH/CLI step. SQLite uses the bundled
        // schema.sql + version-gated column sync. All steps are idempotent and
        // wrapped so a provisioning hiccup never turns into a hard 500.
        try {
            if ($driver === 'sqlite') {
                if ($fresh || !self::tableExists('articles')) {
                    self::migrate();
                    self::seedIfEmpty();
                }
                self::autoMigrate();    // version-gated: applies pending schema updates, then cheap no-op
            } else { // mysql | pgsql — auto-provision when the core table is absent
                if (!self::tableExists('articles')) {
                    self::applyServerSchema($driver);
                    self::seedIfEmpty();
                }
                self::autoMigrateServer(); // additive column/table sync for already-deployed server DBs
            }
        } catch (Throwable $e) {
            error_log('[db] provision (' . $driver . '): ' . $e->getMessage());
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
        $ddl =
            "CREATE TABLE IF NOT EXISTS diary_entries (
               id             INTEGER PRIMARY KEY AUTOINCREMENT,
               author_id      INTEGER NOT NULL,
               kind           VARCHAR(32) NOT NULL DEFAULT 'private',
               title          TEXT NOT NULL DEFAULT '',
               body           TEXT NOT NULL,
               entry_date     TEXT NOT NULL,
               status         VARCHAR(32) NOT NULL DEFAULT 'logged',
               published_slug TEXT,
               review_note    TEXT,
               created_at     TEXT NOT NULL DEFAULT (datetime('now')),
               updated_at     TEXT NOT NULL DEFAULT (datetime('now'))
             );
             CREATE INDEX IF NOT EXISTS idx_diary_entries_author ON diary_entries(author_id, entry_date DESC);
             CREATE INDEX IF NOT EXISTS idx_diary_entries_mod    ON diary_entries(kind, status);";
        self::$pdo->exec(self::driver() === 'sqlite' ? $ddl : self::translateDDL($ddl));
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
        try { self::purgeDemoContent(); self::metaSet('demo_purged_v1', '1'); }
        catch (Throwable $e) { error_log('[db] demo purge skipped: ' . $e->getMessage()); }
    }

    /**
     * Remove all shipped demo/sample content — the original demo Diary articles
     * and the placeholder Academy lessons/modules. Targeted by exact demo slugs
     * and a unique sentinel phrase in demo lesson bodies, so real content authored
     * in the Studio (or the real starter curriculum) is never touched. Idempotent;
     * safe to run on demand. Returns the row counts removed.
     */
    public static function purgeDemoContent(): array
    {
        $pdo = self::pdo();
        $out = ['articles' => 0, 'lessons' => 0, 'modules' => 0];
        $slugs = [
            'introducing-the-afrovanguard-diary',
            'school-storm-reaching-30000-children',
            'the-math-behind-1-million-leaders',
            'rebuilding-summer-school-six-lgas',
            'what-techome-taught-us',
        ];
        $in = implode(',', array_fill(0, count($slugs), '?'));
        // Articles cascade to sections/related/reactions (FK ON DELETE CASCADE).
        $st = $pdo->prepare("DELETE FROM articles WHERE slug IN ($in)"); $st->execute($slugs);
        $out['articles'] = $st->rowCount();
        if (self::tableExists('lessons')) {
            // Demo lessons all carry this exact phrase; real ones won't.
            $out['lessons'] = $pdo->exec("DELETE FROM lessons WHERE body_html LIKE '%Full lesson content is authored in the Studio.%'") ?: 0;
        }
        if (self::tableExists('modules')) {
            // Remove now-empty demo modules (known titles only, and only when they
            // hold no lessons — so the real starter curriculum's modules survive).
            $out['modules'] = $pdo->exec(
                "DELETE FROM modules WHERE title IN ('Foundations','Building','Becoming a mentor','Practicum')
                 AND id NOT IN (SELECT module_id FROM lessons WHERE module_id IS NOT NULL)"
            ) ?: 0;
        }
        return $out;
    }

    /**
     * Create the Academy tables + additive columns (idempotent) and seed once.
     *
     * Driver-aware: every ADD COLUMN / CREATE TABLE here works on SQLite, MySQL
     * and Postgres. This matters because the fresh-provision path for a server DB
     * (applyServerSchema) only runs when the DB is empty, so already-deployed
     * MySQL/Postgres databases rely on THIS step to pick up columns/tables added
     * after their first deploy — notably courses.pass_code / cover_is_dark and the
     * course_access / member_passes tables, whose absence otherwise makes every
     * Academy query fail (the catalogue SELECTs those columns) and 500 the site.
     */
    /**
     * Run the academy's additive schema step on demand.
     *
     * `autoMigrate()` is version-stamped, so a deployment whose stamp already
     * matches never reaches `ensureAcademy()` — which is exactly how a database
     * ends up without `courses.access_type` and every academy query dies. Public
     * so `AcademyRepository` can heal itself when it notices, rather than a second
     * copy of this DDL being written somewhere else.
     */
    public static function ensureAcademySchema(): void
    {
        self::pdo();
        self::ensureAcademy();
    }

    private static function ensureAcademy(): void
    {
        $drv = self::driver();
        // Fresh SQLite bootstrap only — server DBs are provisioned from
        // schema.<driver>.sql before this runs, so never load the SQLite DDL there.
        if ($drv === 'sqlite') {
            if (!self::tableExists('courses') || !self::tableExists('lessons')) {
                self::$pdo->exec(file_get_contents(AV_ROOT . '/db/schema.sql'));
            }
        }
        // Course access columns (additive, driver-aware types). TEXT can't carry a
        // DEFAULT on some MySQL builds, so short string columns use VARCHAR there.
        $str = $drv === 'mysql' ? 'VARCHAR(191)' : 'TEXT';
        $cadd = [
            'access_type'   => "$str NOT NULL DEFAULT 'open'",      // open|tracked|membership|paid|restricted
            'price_ngn'     => 'INTEGER NOT NULL DEFAULT 0',
            'instructor_id' => 'INTEGER',
            'pass_code'     => "$str NOT NULL DEFAULT ''",          // access_type=restricted: any member holding this pass gets in
            'cover_is_dark' => 'INTEGER NOT NULL DEFAULT -1',       // -1 unknown, 0 light, 1 dark (colour-aware overlay text)
        ];
        if (self::tableExists('courses')) {
            foreach ($cadd as $name => $decl) {
                if (self::columnExists('courses', $name)) continue;
                try { self::$pdo->exec("ALTER TABLE courses ADD COLUMN {$name} {$decl}"); }
                catch (Throwable $e) { error_log('[db] add courses.' . $name . ': ' . $e->getMessage()); }
            }
        }
        // Tables added after the LMS shipped (idempotent for deployed DBs). Written
        // as canonical SQLite DDL and translated per driver so they land correctly
        // on MySQL/Postgres too. Restricted-course access = an explicit per-member
        // allowlist (course_access) + named admin-granted passes (member_passes).
        $tables = [
            'course_access' => "CREATE TABLE IF NOT EXISTS course_access (id INTEGER PRIMARY KEY AUTOINCREMENT, course_id INTEGER NOT NULL, user_id INTEGER NOT NULL, granted_by INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT (datetime('now')), UNIQUE(course_id, user_id));",
            'member_passes' => "CREATE TABLE IF NOT EXISTS member_passes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code VARCHAR(191) NOT NULL DEFAULT '', label VARCHAR(191) NOT NULL DEFAULT '', granted_by INTEGER NOT NULL DEFAULT 0, expires_at TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now')), UNIQUE(user_id, code));",
            'certificates'  => "CREATE TABLE IF NOT EXISTS certificates (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, course_id INTEGER NOT NULL, serial VARCHAR(191) UNIQUE NOT NULL, issued_at TEXT NOT NULL DEFAULT (datetime('now')), UNIQUE(user_id, course_id));",
            'quiz_attempts' => "CREATE TABLE IF NOT EXISTS quiz_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, lesson_id INTEGER NOT NULL, score INTEGER NOT NULL DEFAULT 0, passed INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT (datetime('now')));",
            'payments'      => "CREATE TABLE IF NOT EXISTS payments (id INTEGER PRIMARY KEY AUTOINCREMENT, reference VARCHAR(191) UNIQUE NOT NULL, user_id INTEGER NOT NULL, provider VARCHAR(32) NOT NULL DEFAULT 'paystack', kind VARCHAR(32) NOT NULL, course_id INTEGER, amount_kobo INTEGER NOT NULL DEFAULT 0, currency VARCHAR(8) NOT NULL DEFAULT 'NGN', status VARCHAR(32) NOT NULL DEFAULT 'pending', created_at TEXT NOT NULL DEFAULT (datetime('now')), paid_at TEXT);",
        ];
        foreach ($tables as $name => $ddl) {
            if (self::tableExists($name)) continue;
            try { self::$pdo->exec($drv === 'sqlite' ? $ddl : self::translateDDL($ddl, $drv)); }
            catch (Throwable $e) { error_log('[db] create ' . $name . ': ' . $e->getMessage()); }
        }
        // lessons.quiz_json (additive)
        if (self::tableExists('lessons') && !self::columnExists('lessons', 'quiz_json')) {
            try { self::$pdo->exec('ALTER TABLE lessons ADD COLUMN quiz_json TEXT'); }
            catch (Throwable $e) { error_log('[db] add lessons.quiz_json: ' . $e->getMessage()); }
        }

        // Seed the starter catalogue once — best-effort, so a seed hiccup never
        // aborts the schema migration above (which is what actually keeps the site up).
        try {
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
        } catch (Throwable $e) { error_log('[db] academy seed skipped: ' . $e->getMessage()); }
    }

    /**
     * Additive schema sync for already-deployed MySQL/Postgres databases.
     *
     * applyServerSchema() only runs when the DB is empty (a fresh install), so a
     * server database provisioned before a column/table was introduced would never
     * pick it up — and any query touching the new column/table would 500. This runs
     * the driver-aware ensure* steps (which use portable DDL) to fill those gaps.
     * Version-gated on (SCHEMA_REV + schema.sql hash) so it is a single cheap SELECT
     * once applied, and every step is wrapped so a hiccup is logged, never fatal.
     */
    private static function autoMigrateServer(): void
    {
        $stamp = 'srv:' . self::SCHEMA_REV . ':' . (@md5_file(AV_ROOT . '/db/schema.sql') ?: '0');
        try { if (self::metaGet('schema_state') === $stamp) return; } catch (Throwable $e) { return; /* meta not ready */ }

        foreach (['ensureAcademy', 'ensureLmsVerify', 'ensureDiaryEntries'] as $step) {
            try { self::$step(); } catch (Throwable $e) { error_log('[db] server migration ' . $step . ': ' . $e->getMessage()); }
        }
        try { self::metaSet('schema_state', $stamp); self::metaSet('schema_migrated_at', gmdate('c')); }
        catch (Throwable $e) { error_log('[db] record server schema_state: ' . $e->getMessage()); }
    }

    /** Add columns introduced after the first release (idempotent). */
    /**
     * Auto-migration — runs at boot so schema updates apply on deploy with NO
     * SSH/CLI step. Version-gated on (SCHEMA_REV + a hash of db/schema.sql): when
     * the DB already matches, this is a single cheap SELECT and returns. When it
     * is behind (fresh DB, or schema.sql changed in a deploy), it runs the
     * bespoke grandfathering steps and then syncs any missing tables/columns
     * straight from db/schema.sql, and records the new state. Each step is
     * isolated so one failure is logged, never fatal.
     */
    private static function autoMigrate(): void
    {
        $stamp = self::SCHEMA_REV . ':' . (@md5_file(AV_ROOT . '/db/schema.sql') ?: '0');
        try { if (self::metaGet('schema_state') === $stamp) return; } catch (Throwable $e) { /* meta not ready yet */ }

        // Generic sync first — create any missing tables/columns from schema.sql so
        // the schema is complete before the bespoke grandfathering/seed steps run.
        try { self::syncSchemaFromFile(); } catch (Throwable $e) { error_log('[db] schema sync: ' . $e->getMessage()); }
        foreach (['ensureColumns', 'ensureAcademy', 'ensureDiaryEntries', 'ensureLmsVerify'] as $step) {
            try { self::$step(); } catch (Throwable $e) { error_log('[db] migration step ' . $step . ': ' . $e->getMessage()); }
        }
        self::maybePurgeDemo();
        try { self::metaSet('schema_state', $stamp); self::metaSet('schema_migrated_at', gmdate('c')); }
        catch (Throwable $e) { error_log('[db] record schema_state: ' . $e->getMessage()); }
    }

    /**
     * Additively bring the live SQLite schema up to db/schema.sql: create any
     * missing tables and ADD any missing columns. Never drops or rewrites
     * existing columns/data. Columns that can't be added as-is (NOT NULL without
     * a constant default, non-constant DEFAULT, inline REFERENCES, PRIMARY KEY)
     * are softened to a safe additive form so the column still appears.
     * mysql/pgsql migrate via db/migrate.php (schema there is provisioned out-of-band).
     */
    private static function syncSchemaFromFile(): void
    {
        // Server databases are provisioned from schema.<driver>.sql out of band, so
        // this stays SQLite-only by design; the generalised worker below is not.
        if (self::driver() !== 'sqlite') return;
        $sql = @file_get_contents(AV_ROOT . '/db/schema.sql');
        if (!$sql) return;
        self::syncTablesFromDdl(self::$pdo, (string) $sql, 'sqlite', 'db');
    }

    /**
     * Additively bring a live database up to a block of canonical SQLite DDL:
     * create any missing table, and ADD any column a `CREATE TABLE` declares that
     * the live table does not have. Never drops or rewrites anything.
     *
     * Generalised out of `syncSchemaFromFile()` so it can serve a second database
     * with its own DDL and its own connection — `NgvDb` runs on a separate PDO and
     * has no out-of-band migrate script, so this is the only thing standing between
     * it and a column that never arrives. One parser and one set of softening rules
     * for both; a second copy is how the two drift apart.
     *
     * Columns that cannot be added as-is (PRIMARY KEY / AUTOINCREMENT, NOT NULL with
     * no constant default, CHECK, inline REFERENCES, a non-constant DEFAULT) are
     * softened to a form `ADD COLUMN` accepts on a populated table, because a column
     * that exists and is nullable beats a migration that aborts.
     *
     * Returns the number of columns added, for logging and for tests.
     */
    public static function syncTablesFromDdl(PDO $pdo, string $ddl, ?string $driver = null, string $tag = 'db'): int
    {
        $drv = $driver ?: (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        // Strip SQL comments first so column parsing never trips on them.
        $ddl = (string) preg_replace('~/\*.*?\*/~s', '', $ddl);
        $ddl = (string) preg_replace('~--[^\n]*~', '', $ddl);
        if (!preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["`]?([a-zA-Z0-9_]+)["`]?\s*\((.*?)\)\s*;/is', $ddl, $mm, PREG_SET_ORDER)) return 0;

        $added = 0;
        foreach ($mm as $m) {
            $table = $m[1];
            // true = present, false = definitely absent, null = could not tell.
            $exists = self::tableExistsOn($pdo, $table, $drv);
            if ($exists !== true) {
                try { $pdo->exec(self::translateDDL($m[0] . "\n", $drv)); }
                catch (Throwable $e) { error_log('[' . $tag . '] create ' . $table . ': ' . $e->getMessage()); }
                // Definitely absent means it was just created with every column, so
                // there is nothing to diff. When the probe could not tell, the CREATE
                // was an IF NOT EXISTS no-op and the diff below is still worth running
                // — guessing "absent" must not silently skip the column sync.
                if ($exists === false) continue;
            }
            $have = self::columnsOn($pdo, $table, $drv);
            foreach (self::splitTopLevel($m[2]) as $def) {
                $def = trim($def);
                if ($def === '' || preg_match('/^(PRIMARY|FOREIGN|UNIQUE|CHECK|CONSTRAINT|KEY|INDEX)\b/i', $def)) continue;
                if (!preg_match('/^["`]?([a-zA-Z0-9_]+)["`]?\s+(.+)$/s', $def, $cm)) continue;
                $col = $cm[1]; $rest = $cm[2];
                if (isset($have[strtolower($col)]) || preg_match('/PRIMARY\s+KEY|AUTOINCREMENT/i', $rest)) continue;
                if (!preg_match('/^\s*([A-Za-z]+(?:\s*\(\s*[0-9,\s]+\s*\))?)/', $rest, $tm)) continue;
                $type = (string) preg_replace('/\s+/', '', $tm[1]);
                $dflt = '';
                if (preg_match('/\bDEFAULT\s+(\x27[^\x27]*\x27|"[^"]*"|-?[0-9.]+)/i', $rest, $dm)) $dflt = ' DEFAULT ' . $dm[1];
                // A bare TEXT cannot carry a DEFAULT on several MySQL builds — the same
                // reason ensureAcademy() declares short string columns as VARCHAR there.
                if ($drv !== 'sqlite' && $dflt !== '' && strcasecmp($type, 'TEXT') === 0) $type = 'VARCHAR(191)';
                // `(datetime('now'))` is a non-constant default, so the rule above drops
                // it — but translateDDL PROMOTES that column to a real timestamp type
                // when it creates the table. A repaired column has to match, or the same
                // created_at is DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP on a table
                // that was created and TEXT NULL on one that was repaired. Verified
                // divergent on MariaDB 10.11 before this existed.
                if ($drv !== 'sqlite' && preg_match("/DEFAULT\s*\(\s*datetime\('now'\)\s*\)/i", $rest)) {
                    $type = ($drv === 'mysql' ? 'DATETIME' : 'TIMESTAMP')
                          . (preg_match('/\bNOT\s+NULL\b/i', $rest) ? ' NOT NULL' : '');
                    $dflt = ' DEFAULT CURRENT_TIMESTAMP';
                }
                // Quoted from the driver passed in, not self::quoteIdent(), which reads
                // the MAIN connection's driver — wrong when syncing a second database.
                $ident = $drv === 'mysql' ? '`' . $col . '`' : $col;
                try { $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $ident . ' ' . $type . $dflt); $added++; }
                catch (Throwable $e) { error_log('[' . $tag . '] add ' . $table . '.' . $col . ': ' . $e->getMessage()); }
            }
        }
        return $added;
    }

    /**
     * Does $table exist on an arbitrary connection?
     * true / false, or null when the probe itself failed — the caller must not
     * treat "I could not check" as "it is not there".
     */
    private static function tableExistsOn(PDO $pdo, string $table, string $drv): ?bool
    {
        try {
            if ($drv === 'sqlite') {
                $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
                $st->execute([$table]);
                return (bool) $st->fetchColumn();
            }
            $st = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_name = ?' .
                ($drv === 'mysql' ? ' AND table_schema = DATABASE()' : '')
            );
            $st->execute([$table]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            error_log('[db] table probe ' . $table . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Lower-cased column-name set for $table on an arbitrary connection. */
    private static function columnsOn(PDO $pdo, string $table, string $drv): array
    {
        $have = [];
        try {
            if ($drv === 'sqlite') {
                foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $r) {
                    $have[strtolower((string) ($r['name'] ?? ''))] = true;
                }
                return $have;
            }
            $st = $pdo->prepare(
                'SELECT column_name FROM information_schema.columns WHERE table_name = ?' .
                ($drv === 'mysql' ? ' AND table_schema = DATABASE()' : '')
            );
            $st->execute([$table]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $have[strtolower((string) $c)] = true;
        } catch (Throwable $e) { error_log('[db] columns of ' . $table . ': ' . $e->getMessage()); }
        return $have;
    }

    /** Split a CREATE TABLE body on top-level commas (ignoring those inside parens). */
    private static function splitTopLevel(string $body): array
    {
        $out = []; $depth = 0; $cur = '';
        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $ch = $body[$i];
            if ($ch === '(') $depth++; elseif ($ch === ')') $depth--;
            if ($ch === ',' && $depth === 0) { $out[] = $cur; $cur = ''; } else { $cur .= $ch; }
        }
        if (trim($cur) !== '') $out[] = $cur;
        return $out;
    }

    /**
     * Run the articles table's additive schema step on demand.
     *
     * Same reasoning as `ensureAcademySchema()`: `autoMigrate()` is version-stamped,
     * so a settled deployment never reaches `ensureColumns()`, and every Diary query
     * that names `cover_url` then dies — the admin list, the editor's save, and the
     * public /diary/ page alike. Public so `DiaryRepository` can heal itself.
     */
    public static function ensureArticleSchema(): void
    {
        self::pdo();
        self::ensureColumns();
    }

    private static function ensureColumns(): void
    {
        // Driver-aware declarations, and `columnExists()` rather than a PRAGMA: this
        // used to probe with `PRAGMA table_info`, which is not SQL on MySQL or
        // Postgres, so the whole step threw there and the articles table on a server
        // database never gained these columns at all.
        $drv  = self::driver();
        $str  = $drv === 'mysql' ? 'VARCHAR(191)' : 'TEXT';
        $int  = $drv === 'sqlite' ? 'INTEGER' : 'INT';
        $now  = $drv === 'sqlite' ? "(datetime('now'))" : ($drv === 'mysql' ? 'CURRENT_TIMESTAMP' : 'CURRENT_TIMESTAMP');
        $add = [
            'cover_url'     => 'TEXT',
            'og_image'      => 'TEXT',
            'audio_url'     => 'TEXT',
            'status'        => "$str NOT NULL DEFAULT 'published'",
            'updated_at'    => $drv === 'sqlite' ? "TEXT NOT NULL DEFAULT $now" : "TIMESTAMP NULL DEFAULT $now",
            'format'        => "$str NOT NULL DEFAULT 'standard'",
            'series_id'     => "$int NOT NULL DEFAULT 0",
            'series_part'   => "$int NOT NULL DEFAULT 0",
            'cover_is_dark' => "$int NOT NULL DEFAULT -1",   // colour-aware hero text
        ];
        if (!self::tableExists('articles')) return;
        foreach ($add as $name => $decl) {
            if (self::columnExists('articles', $name)) continue;
            try { self::$pdo->exec("ALTER TABLE articles ADD COLUMN {$name} {$decl}"); }
            catch (Throwable $e) { error_log('[db] add articles.' . $name . ': ' . $e->getMessage()); }
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

    /**
     * Idempotently create an index, portably. MySQL/MariaDB have no reliable
     * `CREATE INDEX IF NOT EXISTS` (plain MySQL lacks it entirely; re-running a
     * bare CREATE INDEX errors 1061 "Duplicate key name"), so on the mysql driver
     * we check information_schema first. SQLite/Postgres use IF NOT EXISTS. Safe to
     * call on every boot — this is how runtime ensure() steps add their indexes
     * without 1061-spamming the log on server databases.
     */
    public static function ensureIndex(PDO $db, string $name, string $table, string $cols, bool $unique = false): void
    {
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';
        try {
            if ($drv === 'mysql') {
                $st = $db->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1');
                $st->execute([$table, $name]);
                if ($st->fetchColumn()) return;
                $db->exec("CREATE {$kind} {$name} ON {$table} ({$cols})");
            } else {
                $db->exec("CREATE {$kind} IF NOT EXISTS {$name} ON {$table} ({$cols})");
            }
        } catch (Throwable $e) { error_log('[db] ensureIndex ' . $name . ': ' . $e->getMessage()); }
    }

    /**
     * Run a multi-statement schema DDL portably + IDEMPOTENTLY.
     *
     * The app's subsystems each ensure() their own tables/indexes at boot by
     * exec()-ing a canonical SQLite DDL (translated per driver). That is safe to
     * repeat on SQLite/Postgres (IF NOT EXISTS everywhere) but NOT on MySQL, where
     * `CREATE INDEX IF NOT EXISTS` isn't supported — translateDDL strips the guard,
     * so the second request onward errors 1061 "Duplicate key name" and can break
     * the feature. This executes each statement individually and swallows the
     * benign "already exists" family, so re-running an ensure() is always a no-op
     * on every engine. Non-benign errors are logged, never thrown (ensures are
     * best-effort). Use this instead of `$db->exec($drv==='sqlite'?$ddl:translate)`.
     */
    public static function execSchema(PDO $db, string $ddl): void
    {
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $drv === 'sqlite' ? $ddl : self::translateDDL($ddl, $drv);
        // These schema DDLs never contain ';' inside a literal, so a plain split is
        // safe (same approach as applyServerSchema()).
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            try { $db->exec($stmt); }
            catch (Throwable $e) {
                if (self::isBenignSchemaError($e)) continue;
                error_log('[db] execSchema: ' . $e->getMessage() . ' :: ' . substr(preg_replace('/\s+/', ' ', $stmt), 0, 90));
            }
        }
    }

    /** True for "object already exists / duplicate" DDL errors that make an ensure() re-run a no-op. */
    private static function isBenignSchemaError(Throwable $e): bool
    {
        $m = $e->getMessage();
        // MySQL: 1050 table exists · 1060 dup column · 1061 dup key/index · 1826 dup FK.
        // Postgres: 42P07 dup table · 42701 dup column · 42710 dup object.
        // SQLite: "already exists".
        return (bool) preg_match('/\b(1050|1060|1061|1826)\b|already exists|duplicate key name|duplicate column name|42P07|42701|42710/i', $m);
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
            // Append ENGINE=InnoDB (FKs + transactions) + utf8mb4 (emoji) to every
            // table closer. The closer is a line that is whitespace then `);` — match
            // any indentation (runtime DDL indents heredocs; schema.sql does not), but
            // NOT a `CREATE INDEX ... (cols);` whose `);` is preceded by column text on
            // the same line. Without this, tables inherit the server-default charset/
            // engine — frequently latin1 + MyISAM on shared cPanel hosting, which
            // truncates 4-byte emoji and drops foreign keys.
            $sql = preg_replace('/\n([ \t]*)\);/', "\n\$1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;", $sql);
            // MySQL lacks IF NOT EXISTS on indexes — strip it for both plain and UNIQUE indexes.
            $sql = preg_replace('/CREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS/i', 'CREATE $1INDEX', $sql);
        }
        return $sql;
    }

    public static function migrate(): void
    {
        $sql = file_get_contents(AV_ROOT . '/db/schema.sql');
        if ($sql === false) throw new RuntimeException('Cannot read db/schema.sql');
        self::$pdo->exec($sql);
    }

    /**
     * Provision a fresh MySQL/Postgres database from db/schema.<driver>.sql plus
     * the auxiliary tables that live outside the base schema file (app_meta,
     * communities, celebrations, team). Mirrors the browser DB-migration tool's
     * apply-schema step. Idempotent — every statement is CREATE … IF NOT EXISTS —
     * and only invoked when the core `articles` table is missing (fresh target),
     * so it never disturbs existing data.
     */
    private static function applyServerSchema(string $driver): void
    {
        $file = AV_ROOT . '/db/schema.' . $driver . '.sql';
        $sql  = @file_get_contents($file);
        if ($sql === false || trim((string) $sql) === '') throw new RuntimeException("Cannot read {$file}");
        // Strip comments (some contain ';') before splitting on the statement terminator.
        $sql = (string) preg_replace('/--[^\n]*/', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            self::$pdo->exec($stmt);
        }
        // Auxiliary/admin tables that aren't in the base schema file.
        foreach (['workspace', 'celebrations', 'people'] as $libf) {
            $p = AV_ROOT . '/lib/' . $libf . '.php';
            if (is_file($p)) require_once $p;
        }
        self::ensureMetaOn(self::$pdo);
        if (function_exists('av_communities_ensure'))  av_communities_ensure(self::$pdo);
        if (function_exists('av_celebrations_ensure')) av_celebrations_ensure(self::$pdo);
        if (function_exists('av_team_ensure'))         av_team_ensure(self::$pdo);
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
