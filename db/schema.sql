-- ============================================================
--  The Afrovanguard Diary — database schema (SQLite / PDO)
--  Single source of truth for diary content + engagement.
--  Applied automatically by lib/Database.php on first run,
--  or explicitly via:  php db/seed.php --fresh
-- ============================================================
PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS categories (
  id    INTEGER PRIMARY KEY AUTOINCREMENT,
  slug  TEXT UNIQUE NOT NULL,
  name  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS articles (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  slug          TEXT UNIQUE NOT NULL,
  title         TEXT NOT NULL,
  dek           TEXT NOT NULL,
  category_id   INTEGER NOT NULL REFERENCES categories(id),
  authors_html  TEXT NOT NULL,
  published     TEXT NOT NULL,          -- human display date, e.g. "Jun 18, 2026"
  published_at  TEXT NOT NULL,          -- ISO date for ordering, e.g. "2026-06-18"
  read_minutes  INTEGER NOT NULL DEFAULT 5,
  gradient      TEXT NOT NULL DEFAULT 'g-gold',
  mc_session    TEXT,
  mc_title      TEXT,
  mc_tag        TEXT,
  cover_url     TEXT,                   -- hero / cover image (e.g. Cloudinary)
  og_image      TEXT,                   -- optional custom social card
  body_html     TEXT NOT NULL,
  base_claps    INTEGER NOT NULL DEFAULT 0,
  featured      INTEGER NOT NULL DEFAULT 0,
  status        TEXT NOT NULL DEFAULT 'published',  -- draft | published
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_articles_published ON articles(published_at DESC);
CREATE INDEX IF NOT EXISTS idx_articles_category  ON articles(category_id);
CREATE INDEX IF NOT EXISTS idx_articles_status    ON articles(status);

-- Table-of-contents entries (one row per section heading)
CREATE TABLE IF NOT EXISTS sections (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  article_id  INTEGER NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  anchor      TEXT NOT NULL,
  label       TEXT NOT NULL,
  position    INTEGER NOT NULL DEFAULT 0
);

-- "More from the Diary" relationships
CREATE TABLE IF NOT EXISTS related (
  article_id    INTEGER NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  related_slug  TEXT NOT NULL,
  position      INTEGER NOT NULL DEFAULT 0
);

-- Live, server-side applause counts (shared across all readers)
CREATE TABLE IF NOT EXISTS reactions (
  article_id  INTEGER PRIMARY KEY REFERENCES articles(id) ON DELETE CASCADE,
  claps       INTEGER NOT NULL DEFAULT 0,
  updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Newsletter subscribers (captured from the Diary footer form)
CREATE TABLE IF NOT EXISTS subscribers (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  email       TEXT UNIQUE NOT NULL,
  source      TEXT NOT NULL DEFAULT 'diary',
  created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
