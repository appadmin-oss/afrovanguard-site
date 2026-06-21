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

        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('pdo_sqlite extension is required for the Diary database.');
        }

        $path = AV_DB_PATH;
        $dir  = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $fresh = !is_file($path);

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::$pdo = $pdo;

        // Auto-migrate + seed on a brand-new database, or if the core
        // table is somehow missing.
        if ($fresh || !self::tableExists('articles')) {
            self::migrate();
            self::seedIfEmpty();
        }
        self::ensureColumns(); // additive upgrades for already-deployed DBs
        self::ensureAcademy(); // create + seed academy tables if missing
        return self::$pdo;
    }

    /** Create the Academy tables (idempotent) and seed them once. */
    private static function ensureAcademy(): void
    {
        if (!self::tableExists('courses')) {
            self::$pdo->exec(file_get_contents(AV_ROOT . '/db/schema.sql'));
        }
        $n = (int) self::$pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
        if ($n === 0 && is_file(AV_ROOT . '/db/academy_content.php')) {
            require_once AV_ROOT . '/db/academy_seed.php';
            av_seed_courses(self::$pdo);
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

    public static function tableExists(string $name): bool
    {
        $st = self::$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
        $st->execute([$name]);
        return (bool) $st->fetchColumn();
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
