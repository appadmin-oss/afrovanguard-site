<?php
/**
 * lib/NgvDb.php — the NextGen Vanguard database (a SEPARATE connection).
 *
 * NGV participant data lives in its OWN database, isolated from the main site
 * DB — a deliberate separation so the programme's records can be hosted, backed
 * up, or moved independently. It shares NONE of the main site's tables.
 *
 *   ngv_participants   enrolment, plan, self-tracked progress, reminder opt-out
 *   ngv_charges        what is owed        ─┐ the two halves of the ledger,
 *   ngv_payments       what has been given ─┘ read together by lib/NgvLedger.php
 *   ngv_fee_requests   what a participant said about their own account
 *   ngv_damages        equipment or premises damage, from report to outcome
 *   ngv_certifications what has been earned
 *   ngv_applications   the public registration intake
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

    /**
     * The canonical NGV schema, as SQLite DDL.
     *
     * Read twice: once to create what is missing, and once by the additive sync to
     * add any column an existing table lacks. Keeping it in one place is what makes
     * the sync trustworthy — a column added here reaches deployed databases without
     * anyone remembering to hand-write a matching ALTER.
     */
    private static function ddl(): string
    {
        return "
        CREATE TABLE IF NOT EXISTS ngv_participants (
          id          INTEGER PRIMARY KEY AUTOINCREMENT,
          member_id   INTEGER NOT NULL UNIQUE,
          name        TEXT NOT NULL DEFAULT '',
          email       TEXT NOT NULL DEFAULT '',
          cohort      TEXT NOT NULL DEFAULT '',
          track       TEXT NOT NULL DEFAULT '',
          plan        TEXT NOT NULL DEFAULT '',
          status      TEXT NOT NULL DEFAULT 'active',
          phase       TEXT NOT NULL DEFAULT '',
          books       TEXT NOT NULL DEFAULT '',
          focus_note  TEXT NOT NULL DEFAULT '',
          start_date  TEXT NOT NULL DEFAULT '',
          remind_off  INTEGER NOT NULL DEFAULT 0,
          reminded_at TEXT NOT NULL DEFAULT '',
          /* Last time a full statement went out. Separate from reminded_at
             because they are different letters: a reminder chases money and only
             goes to somebody who owes, a statement says where you stand and goes
             to anybody who asks — including somebody who owes nothing, who under
             the reminder rules could never be told so. */
          statement_at TEXT NOT NULL DEFAULT '',
          /* The training-fee commitment, as agreed with this participant. Four
             columns rather than one, and `training_total` in particular, because
             the total is fixed AT THE MOMENT IT IS AGREED — a later edit to the
             plan price on the public page must not move a figure somebody has
             already been quoted and started paying. `training_months` of 0 means
             no schedule is running. */
          training_from   VARCHAR(10) NOT NULL DEFAULT '',
          training_months INTEGER NOT NULL DEFAULT 0,
          training_each   INTEGER NOT NULL DEFAULT 0,
          training_total  INTEGER NOT NULL DEFAULT 0,
          created_at  TEXT NOT NULL DEFAULT (datetime('now')),
          updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS ngv_payments (
          id          INTEGER PRIMARY KEY AUTOINCREMENT,
          member_id   INTEGER NOT NULL,
          kind        VARCHAR(24) NOT NULL DEFAULT 'commitment',
          amount      INTEGER NOT NULL DEFAULT 0,
          currency    TEXT NOT NULL DEFAULT 'NGN',
          period      VARCHAR(40) NOT NULL DEFAULT '',
          method      TEXT NOT NULL DEFAULT '',
          reference   TEXT NOT NULL DEFAULT '',
          note        TEXT NOT NULL DEFAULT '',
          recorded_by INTEGER NOT NULL DEFAULT 0,
          voided      INTEGER NOT NULL DEFAULT 0,
          credit_kind VARCHAR(16) NOT NULL DEFAULT 'payment',
          voided_by   INTEGER NOT NULL DEFAULT 0,
          voided_at   TEXT NOT NULL DEFAULT '',
          void_reason TEXT NOT NULL DEFAULT '',
          /* When a receipt for this payment was last emailed. The ONLY thing a
             receipt needs stored: the number derives from this row id and the
             verification code is an HMAC of it, so there is no ngv_receipts
             table to drift out of step with the payment it describes. */
          receipt_at  TEXT NOT NULL DEFAULT '',
          created_at  TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_ngv_pay_member ON ngv_payments (member_id);

        /* ── The charge half of the ledger ──────────────────────────────────
         * NOTE, and it matters more than it looks: execSchema() splits this DDL
         * into statements by exploding on the semicolon, so a semicolon anywhere
         * in a comment here cuts a CREATE in half and the halves fail silently
         * down the benign-error path. There are none below, deliberately.
         *
         * ngv_payments records money RECEIVED, and this records money OWED. Two
         * tables rather than one signed table, for a reason worth stating. The
         * accrual's whole safety property is that running it twice cannot charge
         * the same month twice, and that property lives in a UNIQUE index on
         * (member_id, kind, period). Credits cannot share it — two payments in
         * one month are two real events — and NGV has no migration runner that
         * could backfill a discriminator column onto the payment rows already
         * deployed. Separate tables give each side the constraint it actually
         * needs, and cost one union in the domain layer.
         *
         * `kind` and `period` are VARCHAR, not TEXT, because MySQL cannot index a
         * TEXT column without a prefix length: declared TEXT, the unique index
         * below is silently dropped on MySQL by execSchema's benign-error path
         * and the accrual quietly loses its idempotency there. */
        CREATE TABLE IF NOT EXISTS ngv_charges (
          id          INTEGER PRIMARY KEY AUTOINCREMENT,
          member_id   INTEGER NOT NULL,
          kind        VARCHAR(24) NOT NULL DEFAULT 'commitment',
          amount      INTEGER NOT NULL DEFAULT 0,
          currency    TEXT NOT NULL DEFAULT 'NGN',
          period      VARCHAR(40) NOT NULL DEFAULT '',
          reason      VARCHAR(32) NOT NULL DEFAULT '',
          note        TEXT NOT NULL DEFAULT '',
          source      VARCHAR(16) NOT NULL DEFAULT 'accrual',
          created_by  INTEGER NOT NULL DEFAULT 0,
          voided      INTEGER NOT NULL DEFAULT 0,
          voided_by   INTEGER NOT NULL DEFAULT 0,
          voided_at   TEXT NOT NULL DEFAULT '',
          void_reason TEXT NOT NULL DEFAULT '',
          created_at  TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_ngv_chg_period ON ngv_charges (member_id, kind, period);
        CREATE INDEX IF NOT EXISTS idx_ngv_chg_member ON ngv_charges (member_id);

        /* ── What a participant says about their own account ─────────────────
         * The public page promises that no one is turned away for lack, and to
         * speak to your track lead or send a letter requesting consideration.
         * Until this table the dashboard could only REPEAT that sentence, which
         * makes it a dead end: the one person who most needs it is the one least
         * likely to walk up to staff and start the conversation.
         *
         * (No double quote or dollar sign anywhere in this comment: ddl() is one
         * double-quoted PHP string, so either would end it or interpolate — the
         * same class of trap as the semicolon noted above.)
         *
         * A row here is a MESSAGE, never a decision. Nothing a participant writes
         * changes a balance — the outcome is a waiver or a correction that staff
         * post separately, under their own name. */
        CREATE TABLE IF NOT EXISTS ngv_fee_requests (
          id          INTEGER PRIMARY KEY AUTOINCREMENT,
          member_id   INTEGER NOT NULL,
          kind        VARCHAR(16) NOT NULL DEFAULT 'consideration',
          amount      INTEGER NOT NULL DEFAULT 0,
          message     TEXT NOT NULL DEFAULT '',
          status      VARCHAR(12) NOT NULL DEFAULT 'open',
          outcome     TEXT NOT NULL DEFAULT '',
          handled_by  INTEGER NOT NULL DEFAULT 0,
          handled_at  TEXT NOT NULL DEFAULT '',
          created_at  TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_ngv_req_member ON ngv_fee_requests (member_id);
        CREATE INDEX IF NOT EXISTS idx_ngv_req_status ON ngv_fee_requests (status);

        /* ── Damage to equipment or premises ────────────────────────────────
         * A damage report is an INCIDENT, not a charge. Before this table the
         * only way to record one was a fine with reason `equipment` and a note,
         * which collapses three separate facts into one row: what happened and
         * when, what it turned out to cost, and what the participant is actually
         * being asked to pay. Those arrive days apart and are not the same
         * number — a fine posted on the day has to guess the cost, and a fine
         * posted when the quote lands loses the date it happened.
         *
         * So the incident is recorded first and costs nothing. `entry_id` points
         * at the ngv_charges row IF one is ever raised, which is a later and
         * separate decision. `assessed` is what it cost, `charged` is what is
         * being asked for, and they are allowed to differ: a programme that
         * bills a nineteen-year-old the full retail price of a laptop screen
         * has made a decision it should have to write down.
         */
        CREATE TABLE IF NOT EXISTS ngv_damages (
          id          INTEGER PRIMARY KEY AUTOINCREMENT,
          member_id   INTEGER NOT NULL,
          item        VARCHAR(120) NOT NULL DEFAULT '',
          occurred_on VARCHAR(10) NOT NULL DEFAULT '',
          place       VARCHAR(80) NOT NULL DEFAULT '',
          severity    VARCHAR(12) NOT NULL DEFAULT 'minor',
          description TEXT NOT NULL DEFAULT '',
          estimate    INTEGER NOT NULL DEFAULT 0,
          assessed    INTEGER NOT NULL DEFAULT 0,
          charged     INTEGER NOT NULL DEFAULT 0,
          entry_id    INTEGER NOT NULL DEFAULT 0,
          status      VARCHAR(12) NOT NULL DEFAULT 'reported',
          outcome     TEXT NOT NULL DEFAULT '',
          self_report INTEGER NOT NULL DEFAULT 0,
          reported_by INTEGER NOT NULL DEFAULT 0,
          handled_by  INTEGER NOT NULL DEFAULT 0,
          notify      INTEGER NOT NULL DEFAULT 1,
          notified_at VARCHAR(40) NOT NULL DEFAULT '',
          created_at  TEXT NOT NULL DEFAULT (datetime('now')),
          updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_ngv_dmg_member ON ngv_damages (member_id);
        CREATE INDEX IF NOT EXISTS idx_ngv_dmg_status ON ngv_damages (status);
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
    }

    /** Idempotent schema. Runs once per process; safe to call repeatedly. */
    private static function provision(): void
    {
        if (self::$provisioned) return;
        self::$provisioned = true;
        $ddl = self::ddl();
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

        /* `CREATE TABLE IF NOT EXISTS` is a no-op on a table that already exists, so
         * it cannot deliver a column added to the DDL later — which is how a
         * deployed database ends up without `ngv_participants.plan` while every
         * enrolment INSERT names it. This used to be one hand-written ALTER for that
         * single column; the sync below covers every column in the schema instead, so
         * the next one does not need anybody to remember.
         *
         * NGV needs this more than the main database does: there is no
         * schema.<driver>.sql and no out-of-band migrate script for it, so this is
         * the only additive path it has — on every engine, not just SQLite. */
        try {
            if (class_exists('Database')) {
                $n = Database::syncTablesFromDdl(self::$pdo, $ddl, self::driver(), 'ngvdb');
                if ($n > 0) error_log('[ngvdb] schema sync added ' . $n . ' column(s)');
            }
        } catch (Throwable $e) { error_log('[ngvdb] schema sync: ' . $e->getMessage()); }
    }

    /**
     * Driver-correct "insert, and do nothing if the unique index rejects it".
     *
     * `Database::insertIgnore()` exists but reads the MAIN connection's driver,
     * and NGV runs on its own — a site on MySQL with an SQLite NGV database (or
     * the reverse) would get the wrong dialect. This is the same expression
     * against `self::driver()`.
     *
     * `$valuesCsv` is the caller's, not built from a placeholder count, so a
     * column that must take a SQL expression rather than a bound value — the
     * portable `nowExpr()` for `created_at` — can be spliced in. Postgres puts
     * its conflict clause AFTER the values list, so there is no safe way to
     * rewrite the finished string from outside.
     */
    public static function insertIgnore(string $table, array $cols, ?string $valuesCsv = null): string
    {
        $list = implode(', ', $cols);
        $ph   = $valuesCsv ?? implode(', ', array_fill(0, count($cols), '?'));
        switch (self::driver()) {
            case 'mysql': return "INSERT IGNORE INTO {$table} ({$list}) VALUES ({$ph})";
            case 'pgsql': return "INSERT INTO {$table} ({$list}) VALUES ({$ph}) ON CONFLICT DO NOTHING";
            default:      return "INSERT OR IGNORE INTO {$table} ({$list}) VALUES ({$ph})";
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
