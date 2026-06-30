<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Database handle.
 *
 * Uses MySQL when the host provides credentials (DB_NAME in /api/.env) —
 * the intended production path. With NO credentials it falls back to a
 * zero-config SQLite database under /api/data, self-creating its schema
 * and seed on first use, so the site is FULLY OPERATIONAL out of the box
 * on any PHP host (forms persist, stats/programmes/field-notes are live)
 * with nothing to provision. Both drivers speak PDO, so the endpoints are
 * driver-agnostic; the few dialect differences are handled via db_driver().
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $name = (string) env('DB_NAME', '');
    if ($name !== '') {
        $host = env('DB_HOST', 'localhost');
        $user = (string) env('DB_USER', '');
        $pass = (string) env('DB_PASS', '');
        $dsn  = "mysql:host=$host;dbname=$name;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $GLOBALS['__sts_db_driver'] = 'mysql';
        return $pdo;
    }

    // ── Zero-config fallback: SQLite ───────────────────────────────────
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file  = $dir . '/sts.sqlite';
    $fresh = !is_file($file);
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $GLOBALS['__sts_db_driver'] = 'sqlite';
    sts_sqlite_bootstrap($pdo, $fresh);
    return $pdo;
}

/** 'mysql' | 'sqlite' — set once db() has connected. */
function db_driver(): string
{
    if (!isset($GLOBALS['__sts_db_driver'])) db();
    return $GLOBALS['__sts_db_driver'];
}

/**
 * Create the SQLite schema (idempotent) and seed reference data the first
 * time the file is created. Mirrors migrations/*.sql in SQLite dialect.
 */
function sts_sqlite_bootstrap(PDO $pdo, bool $fresh): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS volunteers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  full_name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT NOT NULL,
  role_applied TEXT NOT NULL, motivation_text TEXT,
  skills TEXT, availability TEXT, ai_parsed_skills TEXT,
  status TEXT DEFAULT 'new', source_url TEXT, ip_address TEXT,
  created_at TEXT DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS sponsor_inquiries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  full_name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT,
  organization TEXT, intended_amount_ngn REAL, frequency TEXT,
  selected_program TEXT, referred_to_afrovanguard INTEGER DEFAULT 0,
  status TEXT DEFAULT 'new', created_at TEXT DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS partners (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  org_name TEXT NOT NULL, org_type TEXT NOT NULL, contact_name TEXT NOT NULL,
  contact_role TEXT, email TEXT NOT NULL, phone TEXT, partnership_interest TEXT,
  ai_proposal_draft TEXT, user_approved_proposal TEXT,
  status TEXT DEFAULT 'new', created_at TEXT DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS contact_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  full_name TEXT NOT NULL, email TEXT NOT NULL, subject TEXT, message TEXT NOT NULL,
  ai_category TEXT DEFAULT 'general', user_confirmed_category TEXT, routed_to TEXT,
  status TEXT DEFAULT 'new', created_at TEXT DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS newsletter_subscribers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT UNIQUE NOT NULL, full_name TEXT, status TEXT DEFAULT 'active',
  unsubscribe_token TEXT UNIQUE NOT NULL, created_at TEXT DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS program_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  program_slug TEXT NOT NULL, session_date TEXT NOT NULL, location TEXT,
  capacity INTEGER NOT NULL, volunteers_registered INTEGER DEFAULT 0,
  status TEXT DEFAULT 'open'
);
CREATE TABLE IF NOT EXISTS cost_ledger (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_key TEXT UNIQUE NOT NULL, description TEXT NOT NULL,
  unit_cost_ngn REAL NOT NULL, last_updated TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS site_stats (
  stat_key TEXT PRIMARY KEY, stat_value INTEGER NOT NULL,
  display_label TEXT NOT NULL, last_updated TEXT DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS blog_posts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT UNIQUE NOT NULL, title TEXT NOT NULL, excerpt TEXT, body TEXT NOT NULL,
  cover_image TEXT, category TEXT, author TEXT, status TEXT DEFAULT 'draft',
  published_at TEXT, created_at TEXT DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS api_rate_limits (
  ip_address TEXT NOT NULL, endpoint TEXT NOT NULL,
  request_count INTEGER DEFAULT 1, window_start TEXT DEFAULT (datetime('now')),
  PRIMARY KEY (ip_address, endpoint)
);
CREATE INDEX IF NOT EXISTS idx_sessions_prog ON program_sessions(program_slug, session_date);
CREATE INDEX IF NOT EXISTS idx_posts_status ON blog_posts(status, published_at);
SQL);

    if ($fresh) sts_sqlite_seed($pdo);
}

/** Seed reference tables (only on a freshly-created SQLite file). */
function sts_sqlite_seed(PDO $pdo): void
{
    $stats = [
        ['children_reached', 31072, 'Children reached'],
        ['schools_engaged', 23, 'Schools engaged'],
        ['sessions_delivered', 156, 'Sessions delivered'],
        ['literacy_improvement', 89, 'Literacy improvement (%)'],
        ['active_volunteers', 234, 'Active volunteers'],
        ['institutional_partners', 12, 'Institutional partners'],
        ['children_sponsored', 67, 'Children sponsored'],
        ['raised_2025_ngn_millions', 18, 'Raised in 2025 (NGN M)'],
    ];
    $st = $pdo->prepare('INSERT OR IGNORE INTO site_stats (stat_key, stat_value, display_label) VALUES (?,?,?)');
    foreach ($stats as $r) $st->execute($r);

    $costs = [
        ['materials_kit_term', 'Literacy materials kit, 1 child, 1 term', 12000],
        ['session_cohort', 'Single facilitator session for cohort of 24', 20000],
        ['mentor_session', 'One 1:1 mentor session (STREET Storm / NextGen)', 3000],
        ['term_4_children', 'Full term materials + 8 mentor sessions, 4 children', 50000],
        ['cohort_term', 'A cohort of 24 children, one full term', 120000],
        ['school_intervention', 'School-level intervention, 3 facilitators, 1 term', 300000],
        ['baseline_endline', 'Baseline-endline assessment cycle, one cohort', 24000],
    ];
    $today = date('Y-m-d');
    $st = $pdo->prepare('INSERT OR IGNORE INTO cost_ledger (item_key, description, unit_cost_ngn, last_updated) VALUES (?,?,?,?)');
    foreach ($costs as $c) $st->execute([$c[0], $c[1], $c[2], $today]);

    // Upcoming sessions — dates relative to now so "next intake" stays future.
    $sessions = [
        ['next-gen',      '+14 days', 'Alimosho · Cohort A', 24, 18],
        ['next-gen',      '+14 days', 'Ikeja · Cohort B', 24, 9],
        ['lcasp',         '+15 days', "Partner school · St. Anthony's", 12, 12],
        ['summer-school', '+21 days', 'Central · STS office', 30, 14],
        ['street-storm',  '+28 days', 'Agege · Community centre', 16, 6],
        ['next-gen',      '+35 days', 'Alimosho · Cohort A', 24, 21],
    ];
    $st = $pdo->prepare('INSERT INTO program_sessions (program_slug, session_date, location, capacity, volunteers_registered, status) VALUES (?,?,?,?,?,?)');
    foreach ($sessions as $s) {
        $st->execute([$s[0], date('Y-m-d H:i:s', strtotime($s[1])), $s[2], $s[3], $s[4], 'open']);
    }

    // Field notes — mirror the curated list (newest first).
    $posts = [
        ['summer-school-two-new-lgas', 'What we learned running Summer School across two new LGAs', "Scaling Alimosho's six-week intensive into Kosofe and Ikeja taught us more about logistics than pedagogy. A field note.", 'https://afrovanguard.org.ng/Images/summer6.jpg', 'Education', '2026-05-12'],
        ['publishing-what-didnt-work', "Why we publish the studies that don't work the way we hoped", "An honest accounting of our 2024 numeracy pilot, the parts that worked, and the parts we've already retired.", 'https://afrovanguard.org.ng/Images/summer3.png', 'Methodology', '2026-04-09'],
        ['parents-saturday-morning', 'Notes from the parents we meet every Saturday morning', 'Six months of NextGen Genius Club from the family-engagement desk. Recurring themes, surprises, and one quiet ask.', 'https://afrovanguard.org.ng/Images/summer4.png', 'Community', '2026-03-15'],
        ['street-storm-where-they-end-up', 'Where STREET Storm participants actually end up', 'Following 87 youth six months after the program ended. The honest version of the apprenticeship pipeline.', 'https://afrovanguard.org.ng/Images/storm3.png', 'Youth Development', '2026-02-18'],
        ['lcasp-baseline-endline', 'Inside the LCASP baseline-endline cycle', 'The exact protocol we use to measure literacy gains across 23 partner schools. Open to peer review.', 'https://afrovanguard.org.ng/Images/gates1.png', 'Methodology', '2026-01-20'],
        ['hiring-director-of-research', 'Hiring our first Director of Research, three years late', "We waited too long. Here's what we got wrong about prioritising program delivery over measurement capacity.", 'https://afrovanguard.org.ng/Images/summer6.jpg', 'Leadership', '2025-12-08'],
        ['cohort-tracking-google-sheets', 'Why we rebuilt our cohort-tracking on Google Sheets instead of buying software', 'An honest cost-benefit. Spoiler: the answer might be different at 2,000 children, but not at 412.', 'https://afrovanguard.org.ng/Images/bootcamp1.png', 'Technology', '2025-11-11'],
        ['girl-attendance-gap-lcasp', 'Girl-attendance gap in LCASP cohorts: what we found, what we changed', 'A 6.4 percentage-point gap at P5 disappeared at P6 once we changed one logistical thing. A note for replicators.', 'https://afrovanguard.org.ng/Images/summer7.png', 'Gender', '2025-10-14'],
    ];
    $st = $pdo->prepare('INSERT OR IGNORE INTO blog_posts (slug, title, excerpt, body, cover_image, category, author, status, published_at) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($posts as $p) {
        $st->execute([$p[0], $p[1], $p[2], $p[2], $p[3], $p[4], 'STS Research', 'published', $p[5] . ' 09:00:00']);
    }
}
