-- GENERATED from db/schema.sql by Database::translateDDL().
-- Validated against a live MariaDB 10.11 instance (see docs/db-portability.md).

-- ============================================================
--  The Afrovanguard Diary — database schema (SQLite / PDO)
--  Single source of truth for diary content + engagement.
--  Applied automatically by lib/Database.php on first run,
--  or explicitly via:  php db/seed.php --fresh
-- ============================================================


CREATE TABLE IF NOT EXISTS categories (
  id    INTEGER PRIMARY KEY AUTO_INCREMENT,
  slug  VARCHAR(191) UNIQUE NOT NULL,
  name  TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS articles (
  id            INTEGER PRIMARY KEY AUTO_INCREMENT,
  slug          VARCHAR(191) UNIQUE NOT NULL,
  title         TEXT NOT NULL,
  dek           TEXT NOT NULL,
  category_id   INTEGER NOT NULL REFERENCES categories(id),
  authors_html  TEXT NOT NULL,
  published     TEXT NOT NULL,          -- human display date, e.g. "Jun 18, 2026"
  published_at  VARCHAR(32) NOT NULL,   -- ISO date for ordering, e.g. "2026-06-18" (VARCHAR so MySQL can index it)
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
  status        VARCHAR(32) NOT NULL DEFAULT 'published',  -- draft | published (VARCHAR so MySQL can index it)
  format        TEXT NOT NULL DEFAULT 'standard',   -- standard | qa | feature
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_articles_published ON articles(published_at DESC);
CREATE INDEX idx_articles_category  ON articles(category_id);
CREATE INDEX idx_articles_status    ON articles(status);

-- Table-of-contents entries (one row per section heading)
CREATE TABLE IF NOT EXISTS sections (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  article_id  INTEGER NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  anchor      TEXT NOT NULL,
  label       TEXT NOT NULL,
  position    INTEGER NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "More from the Diary" relationships
CREATE TABLE IF NOT EXISTS related (
  article_id    INTEGER NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
  related_slug  TEXT NOT NULL,
  position      INTEGER NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Live, server-side applause counts (shared across all readers)
CREATE TABLE IF NOT EXISTS reactions (
  article_id  INTEGER PRIMARY KEY REFERENCES articles(id) ON DELETE CASCADE,
  claps       INTEGER NOT NULL DEFAULT 0,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Newsletter subscribers (captured from the Diary footer form)
CREATE TABLE IF NOT EXISTS subscribers (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  email       VARCHAR(191) UNIQUE NOT NULL,
  source      TEXT NOT NULL DEFAULT 'diary',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Academy ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS courses (
  id            INTEGER PRIMARY KEY AUTO_INCREMENT,
  slug          VARCHAR(191) UNIQUE NOT NULL,
  title         TEXT NOT NULL,
  summary       TEXT NOT NULL DEFAULT '',
  body_html     TEXT NOT NULL DEFAULT '',
  cover_url     TEXT,
  og_image      TEXT,
  category      TEXT NOT NULL DEFAULT 'Programme',
  level         TEXT NOT NULL DEFAULT 'All levels',
  format        TEXT NOT NULL DEFAULT 'In-person',
  duration      TEXT NOT NULL DEFAULT '',
  price         TEXT NOT NULL DEFAULT 'Free',
  location      TEXT NOT NULL DEFAULT 'Alimosho, Lagos',
  gradient      TEXT NOT NULL DEFAULT 'g-gold',
  outcomes      TEXT NOT NULL DEFAULT '',   -- newline-separated
  cta_url       TEXT,
  featured      INTEGER NOT NULL DEFAULT 0,
  status        VARCHAR(32) NOT NULL DEFAULT 'published',
  sort          INTEGER NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  access_type   TEXT NOT NULL DEFAULT 'open',   -- open | tracked | membership | paid (also added by ensureAcademy)
  price_ngn     INTEGER NOT NULL DEFAULT 0,
  instructor_id INTEGER
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_courses_status ON courses(status);

CREATE TABLE IF NOT EXISTS enrollments (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  course_id   INTEGER REFERENCES courses(id) ON DELETE SET NULL,
  course_slug VARCHAR(191),
  name        TEXT NOT NULL,
  email       TEXT NOT NULL,
  phone       TEXT,
  note        TEXT,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_enroll_course ON enrollments(course_slug);

-- ── Academy LMS ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lms_users (
  id            INTEGER PRIMARY KEY AUTO_INCREMENT,
  name          TEXT NOT NULL,
  email         VARCHAR(191) UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'learner',   -- learner | instructor | admin
  status        TEXT NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login    TEXT,
  email_verified INTEGER NOT NULL DEFAULT 0,   -- email-verification (also added by ensureLmsVerify on old DBs)
  verify_hash    TEXT,                          -- sha256 of the pending verification token
  verify_expires TEXT                           -- ISO expiry for the token
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS lms_sessions (
  token_hash  VARCHAR(191) PRIMARY KEY,
  user_id     INTEGER NOT NULL REFERENCES lms_users(id) ON DELETE CASCADE,
  ip          TEXT, ua TEXT,
  expires_at  TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS modules (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  title       TEXT NOT NULL,
  position    INTEGER NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS lessons (
  id           INTEGER PRIMARY KEY AUTO_INCREMENT,
  module_id    INTEGER NOT NULL REFERENCES modules(id) ON DELETE CASCADE,
  course_id    INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  slug         TEXT NOT NULL,
  title        TEXT NOT NULL,
  body_html    TEXT NOT NULL DEFAULT '',
  video_url    TEXT,
  duration_min INTEGER NOT NULL DEFAULT 0,
  is_preview   INTEGER NOT NULL DEFAULT 0,
  position     INTEGER NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  quiz_json    TEXT                            -- optional quiz spec (also added by ensureAcademy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_lessons_course ON lessons(course_id);
CREATE TABLE IF NOT EXISTS lesson_progress (
  user_id      INTEGER NOT NULL REFERENCES lms_users(id) ON DELETE CASCADE,
  lesson_id    INTEGER NOT NULL REFERENCES lessons(id) ON DELETE CASCADE,
  course_id    INTEGER NOT NULL,
  completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, lesson_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS course_enrolment (
  user_id    INTEGER NOT NULL REFERENCES lms_users(id) ON DELETE CASCADE,
  course_id  INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS memberships (
  id         INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id    INTEGER NOT NULL REFERENCES lms_users(id) ON DELETE CASCADE,
  tier       TEXT NOT NULL DEFAULT 'member',
  status     TEXT NOT NULL DEFAULT 'active',     -- active | expired | cancelled
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS certificates (
  id         INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id    INTEGER NOT NULL REFERENCES lms_users(id) ON DELETE CASCADE,
  course_id  INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  serial     VARCHAR(191) UNIQUE NOT NULL,
  issued_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS quiz_attempts (
  id         INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id    INTEGER NOT NULL REFERENCES lms_users(id) ON DELETE CASCADE,
  lesson_id  INTEGER NOT NULL REFERENCES lessons(id) ON DELETE CASCADE,
  score      INTEGER NOT NULL DEFAULT 0,
  passed     INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS payments (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  reference   VARCHAR(191) UNIQUE NOT NULL,
  user_id     INTEGER NOT NULL REFERENCES lms_users(id) ON DELETE CASCADE,
  provider    TEXT NOT NULL DEFAULT 'paystack',   -- paystack | flutterwave
  kind        TEXT NOT NULL,                       -- course | membership
  course_id   INTEGER,
  amount_kobo INTEGER NOT NULL DEFAULT 0,
  currency    TEXT NOT NULL DEFAULT 'NGN',
  status      TEXT NOT NULL DEFAULT 'pending',     -- pending | paid | failed
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at     TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_payments_user ON payments(user_id);

-- ── Vanguard Diary — member-contributed entries (categories + moderation) ──
-- Deliberately SEPARATE from `articles` so private/event logs can never leak
-- into the public editorial feed. A `public` entry, once an admin approves it,
-- is PROMOTED into `articles` (the public feed) — see lib/DiaryJournal::approve().
CREATE TABLE IF NOT EXISTS diary_entries (
  id             INTEGER PRIMARY KEY AUTO_INCREMENT,
  author_id      INTEGER NOT NULL REFERENCES lms_users(id) ON DELETE CASCADE,
  kind           VARCHAR(32) NOT NULL DEFAULT 'private',  -- event | private | public (VARCHAR so MySQL can index it)
  title          TEXT NOT NULL DEFAULT '',
  body           TEXT NOT NULL,                       -- plain text; escaped on render
  entry_date     VARCHAR(32) NOT NULL,                -- ISO date, backdatable (VARCHAR so MySQL can index it)
  status         VARCHAR(32) NOT NULL DEFAULT 'logged',   -- logged | pending | approved | rejected (VARCHAR so MySQL can index it)
  published_slug TEXT,                                -- article slug once promoted to the feed
  review_note    TEXT,                                -- admin note (e.g. on reject)
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_diary_entries_author ON diary_entries(author_id, entry_date DESC);
CREATE INDEX idx_diary_entries_mod    ON diary_entries(kind, status);

-- ── Sign-in page illustrations (admin-managed + schedulable) ──
-- Powers the /login illustration aside. `always` art is the year-round
-- rotation; `range`/`annual` art shows only on its schedule (annual supports a
-- year wrap for festive windows). See lib/AuthArt.php.
CREATE TABLE IF NOT EXISTS auth_illustrations (
  id            INTEGER PRIMARY KEY AUTO_INCREMENT,
  label         TEXT NOT NULL DEFAULT '',
  image_url     TEXT NOT NULL,
  active        INTEGER NOT NULL DEFAULT 1,
  schedule_kind VARCHAR(32) NOT NULL DEFAULT 'always',  -- always | range | annual (VARCHAR so MySQL can index it)
  start_date    TEXT,                              -- YYYY-MM-DD (range)
  end_date      TEXT,                              -- YYYY-MM-DD (range)
  start_md      TEXT,                              -- MM-DD (annual)
  end_md        TEXT,                              -- MM-DD (annual)
  sort          INTEGER NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_auth_art_active ON auth_illustrations(active, schedule_kind);

-- ── Member-management audit trail (Studio) ──
CREATE TABLE IF NOT EXISTS lms_audit (
  id         INTEGER PRIMARY KEY AUTO_INCREMENT,
  actor      TEXT NOT NULL DEFAULT 'admin',
  action     TEXT NOT NULL,
  target     TEXT NOT NULL DEFAULT '',
  detail     TEXT NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
