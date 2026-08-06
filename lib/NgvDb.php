<?php
/**
 * lib/NgvDb.php — the NextGen Vanguard database (a SEPARATE connection).
 *
 * NGV participant data (enrolment, fee ledger, certifications, self-tracked
 * progress) lives in its OWN database, isolated from the main site DB — a
 * deliberate separation so the programme's records can be hosted, backed up,
 * or moved independently. It shares NONE of the main site's tables.
 *
 * Config (all optional; env or config.php constants):
 *   AV_NGV_DB_DSN     full PDO DSN (mysql:… / pgsql:… / sqlite:…) — wins if set
 *   AV_NGV_DB_DRIVER  sqlite | mysql | pgsql   (inferred from the rest if unset)
 *   AV_NGV_DB_HOST / _PORT / _NAME / _USER / _PASS   discrete server creds
 *   AV_NGV_DB_PATH    SQLite file (default db/ngv.sqlite)
 *
 * With nothing configured it is a zero-config SQLite file next to the main one —
 * the same "just works on shared cPanel" posture as lib/Database.php. It reuses
 * Database::execSchema() so its schema is cross-engine portable for free.
 */
declare(strict_types=1);

final class NgvDb
{
    private static ?PDO $pdo = null;
    private static ?string $fellBackFrom = null;
    private static bool $provisioned = false;

    /** Which driver we fell back FROM (null = running on the chosen driver). */
    public static function fellBack(): ?string { return self::$fellBackFrom; }

    private static function cfg(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') return (string) $v;
        return defined($key) ? (string) constant($key) : $default;
    }

    private static function sqlitePath(): string
    {
        $p = self::cfg('AV_NGV_DB_PATH');
        if ($p !== '') return $p;
        $root = defined('AV_ROOT') ? AV_ROOT : dirname(__DIR__);
        return $root . '/db/ngv.sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $dsnEnv = self::cfg('AV_NGV_DB_DSN');
        $driver = strtolower(self::cfg('AV_NGV_DB_DRIVER'));
        if ($driver === '') {
            if (stripos($dsnEnv, 'mysql:') === 0) $driver = 'mysql';
            elseif (stripos($dsnEnv, 'pgsql:') === 0) $driver = 'pgsql';
            elseif (stripos($dsnEnv, 'sqlite:') === 0) $driver = 'sqlite';
            elseif (self::cfg('AV_NGV_DB_NAME') || self::cfg('AV_NGV_DB_USER') || self::cfg('AV_NGV_DB_HOST')) $driver = 'mysql';
            else $driver = 'sqlite';
        }

        $connectSqlite = static function () use ($opts): PDO {
            if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('pdo_sqlite is required for the NGV database.');
            $path = NgvDb::sqlitePathPublic();
            $dir = dirname($path);
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $pdo = new PDO('sqlite:' . $path, null, null, $opts);
            $pdo->exec('PRAGMA foreign_keys = ON');
            try { $pdo->exec('PRAGMA journal_mode = WAL'); $pdo->exec('PRAGMA busy_timeout = 5000'); $pdo->exec('PRAGMA synchronous = NORMAL'); } catch (Throwable $e) {}
            return $pdo;
        };

        if ($driver === 'sqlite') {
            $pdo = $connectSqlite();
        } elseif ($driver === 'mysql' || $driver === 'pgsql') {
            $dsn = $dsnEnv;
            if ($dsn === '' || stripos($dsn, $driver . ':') !== 0) {
                $host = self::cfg('AV_NGV_DB_HOST', '127.0.0.1');
                $name = self::cfg('AV_NGV_DB_NAME', 'ngv');
                $port = self::cfg('AV_NGV_DB_PORT', $driver === 'pgsql' ? '5432' : '3306');
                $dsn  = $driver === 'pgsql'
                    ? "pgsql:host={$host};port={$port};dbname={$name}"
                    : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            }
            try {
                $pdo = new PDO($dsn, self::cfg('AV_NGV_DB_USER') ?: null, self::cfg('AV_NGV_DB_PASS') ?: null, $opts);
            } catch (Throwable $e) {
                $strict = class_exists('Database') ? Database::dbStrict() : false;
                error_log('[ngvdb] ' . $driver . ' connect failed (' . $e->getMessage() . ')'
                    . ($strict ? ' — AV_DB_STRICT set, refusing SQLite fallback' : ' — falling back to SQLite'));
                if ($strict) throw new RuntimeException('NGV ' . $driver . ' database unreachable and AV_DB_STRICT is set.', 0, $e);
                self::$fellBackFrom = $driver;
                $pdo = $connectSqlite();
            }
        } else {
            throw new RuntimeException("Unsupported AV_NGV_DB_DRIVER: {$driver}");
        }

        self::$pdo = $pdo;
        self::provision();
        return self::$pdo;
    }

    /** Exposed only so the connect closure can reach the resolved path. */
    public static function sqlitePathPublic(): string { return self::sqlitePath(); }

    public static function driver(): string
    {
        return self::$pdo ? (string) self::$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) : 'sqlite';
    }

    /** Idempotent schema. Runs once per process; safe to call repeatedly. */
    private static function provision(): void
    {
        if (self::$provisioned) return;
        self::$provisioned = true;
        $ddl = "
        CREATE TABLE IF NOT EXISTS ngv_participants (
          id          INTEGER PRIMARY KEY AUTOINCREMENT,
          member_id   INTEGER NOT NULL UNIQUE,
          name        TEXT NOT NULL DEFAULT '',
          email       TEXT NOT NULL DEFAULT '',
          cohort      TEXT NOT NULL DEFAULT '',
          track       TEXT NOT NULL DEFAULT '',
          status      TEXT NOT NULL DEFAULT 'active',
          phase       TEXT NOT NULL DEFAULT '',
          books       TEXT NOT NULL DEFAULT '',
          focus_note  TEXT NOT NULL DEFAULT '',
          start_date  TEXT NOT NULL DEFAULT '',
          created_at  TEXT NOT NULL DEFAULT (datetime('now')),
          updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS ngv_payments (
          id          INTEGER PRIMARY KEY AUTOINCREMENT,
          member_id   INTEGER NOT NULL,
          kind        TEXT NOT NULL DEFAULT 'commitment',
          amount      INTEGER NOT NULL DEFAULT 0,
          currency    TEXT NOT NULL DEFAULT 'NGN',
          period      TEXT NOT NULL DEFAULT '',
          method      TEXT NOT NULL DEFAULT '',
          reference   TEXT NOT NULL DEFAULT '',
          note        TEXT NOT NULL DEFAULT '',
          recorded_by INTEGER NOT NULL DEFAULT 0,
          voided      INTEGER NOT NULL DEFAULT 0,
          created_at  TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_ngv_pay_member ON ngv_payments (member_id);
        CREATE TABLE IF NOT EXISTS ngv_certifications (
          id          INTEGER PRIMARY KEY AUTOINCREMENT,
          member_id   INTEGER NOT NULL,
          title       TEXT NOT NULL DEFAULT '',
          issued_on   TEXT NOT NULL DEFAULT '',
          issued_by   TEXT NOT NULL DEFAULT '',
          reference   TEXT NOT NULL DEFAULT '',
          created_at  TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_ngv_cert_member ON ngv_certifications (member_id);
        CREATE TABLE IF NOT EXISTS ngv_applications (
          id          INTEGER PRIMARY KEY AUTOINCREMENT,
          name        TEXT NOT NULL DEFAULT '',
          email       TEXT NOT NULL DEFAULT '',
          phone       TEXT NOT NULL DEFAULT '',
          age         TEXT NOT NULL DEFAULT '',
          gender      TEXT NOT NULL DEFAULT '',
          location    TEXT NOT NULL DEFAULT '',
          track       TEXT NOT NULL DEFAULT '',
          plan        TEXT NOT NULL DEFAULT '',
          education   TEXT NOT NULL DEFAULT '',
          message     TEXT NOT NULL DEFAULT '',
          status      TEXT NOT NULL DEFAULT 'new',
          source      TEXT NOT NULL DEFAULT 'web',
          member_id   INTEGER NOT NULL DEFAULT 0,
          reviewed_by INTEGER NOT NULL DEFAULT 0,
          created_at  TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_ngv_app_status ON ngv_applications (status);
        CREATE INDEX IF NOT EXISTS idx_ngv_app_email  ON ngv_applications (email);
        ";
        try {
            if (class_exists('Database')) {
                Database::execSchema(self::$pdo, $ddl); // reuse the portable cross-engine DDL runner
            } else {
                foreach (array_filter(array_map('trim', explode(';', $ddl))) as $stmt) {
                    try { self::$pdo->exec($stmt); } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {
            error_log('[ngvdb] provision: ' . $e->getMessage());
        }
    }

    /** Portable "current timestamp" expression for runtime inserts. */
    public static function nowExpr(): string
    {
        switch (self::driver()) {
            case 'mysql': return 'NOW()';
            case 'pgsql': return 'CURRENT_TIMESTAMP';
            default:      return "datetime('now')";
        }
    }
}
