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
  ref_code      VARCHAR(32) DEFAULT NULL,          
  title         TEXT NOT NULL,
  dek           TEXT NOT NULL,
  category_id   INTEGER NOT NULL,
  authors_html  TEXT NOT NULL,
  published     TEXT NOT NULL,          
  published_at  VARCHAR(32) NOT NULL,   
  read_minutes  INTEGER NOT NULL DEFAULT 5,
  gradient      TEXT NOT NULL DEFAULT ('g-gold'),
  mc_session    TEXT,
  mc_title      TEXT,
  mc_tag        TEXT,
  cover_url     TEXT,                   
  og_image      TEXT,                   
  audio_url     TEXT,                   
  body_html     TEXT NOT NULL,
  base_claps    INTEGER NOT NULL DEFAULT 0,
  featured      INTEGER NOT NULL DEFAULT 0,
  status        VARCHAR(32) NOT NULL DEFAULT 'published',  
  format        TEXT NOT NULL DEFAULT ('standard'),   
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_articles_published ON articles(published_at DESC);
CREATE INDEX idx_articles_category  ON articles(category_id);
CREATE INDEX idx_articles_status    ON articles(status);
CREATE UNIQUE INDEX idx_articles_refcode ON articles(ref_code);

-- Table-of-contents entries (one row per section heading)
CREATE TABLE IF NOT EXISTS sections (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  article_id  INTEGER NOT NULL,
  anchor      TEXT NOT NULL,
  label       TEXT NOT NULL,
  position    INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (`article_id`) REFERENCES `articles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "More from the Diary" relationships
CREATE TABLE IF NOT EXISTS related (
  article_id    INTEGER NOT NULL,
  related_slug  TEXT NOT NULL,
  position      INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (`article_id`) REFERENCES `articles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Live, server-side applause counts (shared across all readers)
CREATE TABLE IF NOT EXISTS reactions (
  article_id  INTEGER PRIMARY KEY,
  claps       INTEGER NOT NULL DEFAULT 0,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`article_id`) REFERENCES `articles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Newsletter subscribers (captured from the Diary footer form)
CREATE TABLE IF NOT EXISTS subscribers (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  email       VARCHAR(191) UNIQUE NOT NULL,
  source      TEXT NOT NULL DEFAULT ('diary'),
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Academy ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS courses (
  id            INTEGER PRIMARY KEY AUTO_INCREMENT,
  slug          VARCHAR(191) UNIQUE NOT NULL,
  title         TEXT NOT NULL,
  summary       TEXT NOT NULL DEFAULT (''),
  body_html     TEXT NOT NULL DEFAULT (''),
  cover_url     TEXT,
  og_image      TEXT,
  category      TEXT NOT NULL DEFAULT ('Programme'),
  level         TEXT NOT NULL DEFAULT ('All levels'),
  format        TEXT NOT NULL DEFAULT ('In-person'),
  duration      TEXT NOT NULL DEFAULT (''),
  price         TEXT NOT NULL DEFAULT ('Free'),
  location      TEXT NOT NULL DEFAULT ('Alimosho, Lagos'),
  gradient      TEXT NOT NULL DEFAULT ('g-gold'),
  outcomes      TEXT NOT NULL DEFAULT (''),   -- newline-separated
  cta_url       TEXT,
  featured      INTEGER NOT NULL DEFAULT 0,
  status        VARCHAR(32) NOT NULL DEFAULT 'published',
  sort          INTEGER NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  access_type   TEXT NOT NULL DEFAULT ('open'),   -- open | tracked | membership | paid | restricted (also added by ensureAcademy)
  price_ngn     INTEGER NOT NULL DEFAULT 0,
  instructor_id INTEGER,
  pass_code     TEXT NOT NULL DEFAULT (''),        -- access_type=restricted: any member holding this pass gets in
  cover_is_dark INTEGER NOT NULL DEFAULT -1,     -- -1 unknown, 0 light, 1 dark (colour-aware overlay text)
  language      VARCHAR(64) NOT NULL DEFAULT 'English',
  skills        TEXT NOT NULL DEFAULT (''),        -- newline-separated: "Skills you'll gain"
  prerequisites TEXT NOT NULL DEFAULT (''),        -- newline-separated; empty = none
  pass_mark     INTEGER NOT NULL DEFAULT 70,     -- overall weighted grade needed to pass (0-100)
  pace_weeks    INTEGER NOT NULL DEFAULT 0,      -- suggested weeks to finish; 0 = one week per module
  sequential    INTEGER NOT NULL DEFAULT 0       -- 1 = items open in order, each once the one before is done
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_courses_status ON courses(status);

CREATE TABLE IF NOT EXISTS enrollments (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  course_id   INTEGER,
  course_slug VARCHAR(191),
  name        TEXT NOT NULL,
  email       TEXT NOT NULL,
  phone       TEXT,
  note        TEXT,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_enroll_course ON enrollments(course_slug);

-- ── Academy LMS ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lms_users (
  id            INTEGER PRIMARY KEY AUTO_INCREMENT,
  name          TEXT NOT NULL,
  email         VARCHAR(191) UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT ('learner'),   -- learner | instructor | admin
  status        TEXT NOT NULL DEFAULT ('active'),
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login    TEXT,
  email_verified INTEGER NOT NULL DEFAULT 0,   -- email-verification (also added by ensureLmsVerify on old DBs)
  verify_hash    TEXT,                          -- sha256 of the pending verification token
  verify_expires TEXT,                          -- ISO expiry for the token
  has_password   INTEGER NOT NULL DEFAULT 0     -- 1 once a member sets a real password (OTP/OAuth accounts stay 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS lms_sessions (
  token_hash  VARCHAR(191) PRIMARY KEY,
  user_id     INTEGER NOT NULL,
  ip          TEXT, ua TEXT,
  expires_at  TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `lms_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_lms_sessions_user ON lms_sessions(user_id);
CREATE TABLE IF NOT EXISTS modules (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  course_id   INTEGER NOT NULL,
  title       TEXT NOT NULL,
  position    INTEGER NOT NULL DEFAULT 0,
  summary     TEXT NOT NULL DEFAULT (''),          
  objectives  TEXT NOT NULL DEFAULT ('')           ,
  FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS lessons (
  id           INTEGER PRIMARY KEY AUTO_INCREMENT,
  module_id    INTEGER NOT NULL,
  course_id    INTEGER NOT NULL,
  slug         TEXT NOT NULL,
  title        TEXT NOT NULL,
  body_html    TEXT NOT NULL DEFAULT (''),
  video_url    TEXT,
  duration_min INTEGER NOT NULL DEFAULT 0,
  is_preview   INTEGER NOT NULL DEFAULT 0,
  position     INTEGER NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  quiz_json    TEXT,                           
  kind         VARCHAR(32) NOT NULL DEFAULT '',  
  weight       INTEGER NOT NULL DEFAULT 1,       
  assignment_json TEXT,                          
  transcript   TEXT NOT NULL DEFAULT (''),         
  captions_url TEXT,                             
  resources_json TEXT,                           
  available_at VARCHAR(32) NOT NULL DEFAULT '',  
  ai_summary      TEXT,                          
  ai_summary_hash VARCHAR(64)                    ,
  FOREIGN KEY (`module_id`) REFERENCES `modules`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_lessons_course ON lessons(course_id);
CREATE TABLE IF NOT EXISTS lesson_progress (
  user_id      INTEGER NOT NULL,
  lesson_id    INTEGER NOT NULL,
  course_id    INTEGER NOT NULL,
  completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, lesson_id),
  FOREIGN KEY (`user_id`) REFERENCES `lms_users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS course_enrolment (
  user_id    INTEGER NOT NULL,
  course_id  INTEGER NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, course_id),
  FOREIGN KEY (`user_id`) REFERENCES `lms_users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Per-learner lesson notes (cross-device for signed-in members).
CREATE TABLE IF NOT EXISTS lesson_notes (
  user_id    INTEGER NOT NULL,
  lesson_id  INTEGER NOT NULL,
  course_id  INTEGER NOT NULL,
  body       TEXT NOT NULL DEFAULT (''),
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, lesson_id),
  FOREIGN KEY (`user_id`) REFERENCES `lms_users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS memberships (
  id         INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id    INTEGER NOT NULL,
  tier       TEXT NOT NULL DEFAULT ('member'),
  status     TEXT NOT NULL DEFAULT ('active'),     
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TEXT,
  FOREIGN KEY (`user_id`) REFERENCES `lms_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_memberships_user ON memberships(user_id);
-- Restricted-course access: an explicit per-member allowlist, and named "passes"
-- an admin grants that unlock any course requiring that pass (also ensured at runtime).
CREATE TABLE IF NOT EXISTS course_access (
  id         INTEGER PRIMARY KEY AUTO_INCREMENT,
  course_id  INTEGER NOT NULL,
  user_id    INTEGER NOT NULL,
  granted_by INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (course_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS member_passes (
  id         INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id    INTEGER NOT NULL,
  code       TEXT NOT NULL DEFAULT (''),
  label      TEXT NOT NULL DEFAULT (''),
  granted_by INTEGER NOT NULL DEFAULT 0,
  expires_at TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS certificates (
  id         INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id    INTEGER NOT NULL,
  course_id  INTEGER NOT NULL,
  serial     VARCHAR(191) UNIQUE NOT NULL,
  issued_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, course_id),
  FOREIGN KEY (`user_id`) REFERENCES `lms_users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS quiz_attempts (
  id         INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id    INTEGER NOT NULL,
  lesson_id  INTEGER NOT NULL,
  score      INTEGER NOT NULL DEFAULT 0,
  passed     INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `lms_users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_quiz_attempts_user_lesson ON quiz_attempts(user_id, lesson_id);
-- ── Academy courseware: assignments, discussions, reviews, programmes ──
-- One current submission per learner per assignment; a resubmission replaces it.
CREATE TABLE IF NOT EXISTS assignment_submissions (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id     INTEGER NOT NULL,
  lesson_id   INTEGER NOT NULL,
  course_id   INTEGER NOT NULL,
  body        TEXT NOT NULL DEFAULT (''),
  link_url    TEXT NOT NULL DEFAULT (''),
  status      VARCHAR(32) NOT NULL DEFAULT 'submitted',   
  score       INTEGER,                                    
  feedback    TEXT NOT NULL DEFAULT (''),
  graded_by   INTEGER,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  graded_at   TEXT,
  UNIQUE (user_id, lesson_id),
  FOREIGN KEY (`user_id`) REFERENCES `lms_users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS forum_threads (
  id           INTEGER PRIMARY KEY AUTO_INCREMENT,
  course_id    INTEGER NOT NULL,
  lesson_id    INTEGER,                                   
  user_id      INTEGER NOT NULL DEFAULT 0,                
  title        TEXT NOT NULL,
  body         TEXT NOT NULL DEFAULT (''),
  pinned       INTEGER NOT NULL DEFAULT 0,
  locked       INTEGER NOT NULL DEFAULT 0,
  hidden       INTEGER NOT NULL DEFAULT 0,
  reply_count  INTEGER NOT NULL DEFAULT 0,
  last_post_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS forum_posts (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  thread_id   INTEGER NOT NULL,
  course_id   INTEGER NOT NULL,
  user_id     INTEGER NOT NULL,
  body        TEXT NOT NULL,
  hidden      INTEGER NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`thread_id`) REFERENCES `forum_threads`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `lms_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS course_reviews (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id     INTEGER NOT NULL,
  course_id   INTEGER NOT NULL,
  rating      INTEGER NOT NULL DEFAULT 5,                 
  body        TEXT NOT NULL DEFAULT (''),
  hidden      INTEGER NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, course_id),
  FOREIGN KEY (`user_id`) REFERENCES `lms_users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- A programme is a series of courses with its own certificate.
CREATE TABLE IF NOT EXISTS programmes (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  slug        VARCHAR(191) UNIQUE NOT NULL,
  title       TEXT NOT NULL,
  summary     TEXT NOT NULL DEFAULT (''),
  body_html   TEXT NOT NULL DEFAULT (''),
  cover_url   TEXT,
  status      VARCHAR(32) NOT NULL DEFAULT 'draft',       -- draft | published
  sort        INTEGER NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS programme_courses (
  programme_id INTEGER NOT NULL,
  course_id    INTEGER NOT NULL,
  position     INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (programme_id, course_id),
  FOREIGN KEY (`programme_id`) REFERENCES `programmes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS programme_certificates (
  id           INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id      INTEGER NOT NULL,
  programme_id INTEGER NOT NULL,
  serial       VARCHAR(191) UNIQUE NOT NULL,
  issued_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, programme_id),
  FOREIGN KEY (`user_id`) REFERENCES `lms_users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`programme_id`) REFERENCES `programmes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_asub_course ON assignment_submissions(course_id, status);
CREATE INDEX idx_fthread_course ON forum_threads(course_id, last_post_at);
CREATE INDEX idx_fpost_thread ON forum_posts(thread_id, id);
CREATE INDEX idx_review_course ON course_reviews(course_id);
CREATE TABLE IF NOT EXISTS payments (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  reference   VARCHAR(191) UNIQUE NOT NULL,
  user_id     INTEGER NOT NULL,
  provider    TEXT NOT NULL DEFAULT ('paystack'),   
  kind        TEXT NOT NULL,                       
  course_id   INTEGER,
  amount_kobo INTEGER NOT NULL DEFAULT 0,
  currency    TEXT NOT NULL DEFAULT ('NGN'),
  status      TEXT NOT NULL DEFAULT ('pending'),     
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at     TEXT,
  FOREIGN KEY (`user_id`) REFERENCES `lms_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_payments_user ON payments(user_id);

-- ── Vanguard Diary — member-contributed entries (categories + moderation) ──
-- Deliberately SEPARATE from `articles` so private/event logs can never leak
-- into the public editorial feed. A `public` entry, once an admin approves it,
-- is PROMOTED into `articles` (the public feed) — see lib/DiaryJournal::approve().
CREATE TABLE IF NOT EXISTS diary_entries (
  id             INTEGER PRIMARY KEY AUTO_INCREMENT,
  author_id      INTEGER NOT NULL,
  kind           VARCHAR(32) NOT NULL DEFAULT 'private',  
  title          TEXT NOT NULL DEFAULT (''),
  body           TEXT NOT NULL,                       
  entry_date     VARCHAR(32) NOT NULL,                
  status         VARCHAR(32) NOT NULL DEFAULT 'logged',   
  published_slug TEXT,                                
  review_note    TEXT,                                
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`author_id`) REFERENCES `lms_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_diary_entries_author ON diary_entries(author_id, entry_date DESC);
CREATE INDEX idx_diary_entries_mod    ON diary_entries(kind, status);

-- ── Sign-in page illustrations (admin-managed + schedulable) ──
-- Powers the /login illustration aside. `always` art is the year-round
-- rotation; `range`/`annual` art shows only on its schedule (annual supports a
-- year wrap for festive windows). See lib/AuthArt.php.
CREATE TABLE IF NOT EXISTS auth_illustrations (
  id            INTEGER PRIMARY KEY AUTO_INCREMENT,
  label         TEXT NOT NULL DEFAULT (''),
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
  actor      TEXT NOT NULL DEFAULT ('admin'),
  action     TEXT NOT NULL,
  target     TEXT NOT NULL DEFAULT (''),
  detail     TEXT NOT NULL DEFAULT (''),
  ip         TEXT NOT NULL DEFAULT (''),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_lms_audit_created ON lms_audit(id DESC);

-- ── Diary engagement: how many read it, what they said, what they kept ──
-- Views are stored per article per day, never per visitor: the counter on the
-- card is the only thing anyone needs, and a per-visitor row would be a log of
-- who read what. Which entries a reader has already been counted for lives in
-- a signed cookie (lib/DiaryVisitor.php), not here.
CREATE TABLE IF NOT EXISTS diary_views (
  article_id INTEGER NOT NULL,
  day        VARCHAR(10) NOT NULL,              -- YYYY-MM-DD (VARCHAR so MySQL can index it)
  count      INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (article_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_diary_views_article ON diary_views(article_id);

-- One level of replies only: parent_id always names a top-level comment, so a
-- thread can be read top to bottom without recursion and cannot be nested into
-- a column one character wide.
CREATE TABLE IF NOT EXISTS diary_comments (
  id         INTEGER PRIMARY KEY AUTO_INCREMENT,
  article_id INTEGER NOT NULL DEFAULT 0,
  parent_id  INTEGER,                           -- NULL for a top-level comment
  user_id    INTEGER NOT NULL DEFAULT 0,
  name       VARCHAR(120) NOT NULL DEFAULT '',
  email      TEXT NOT NULL DEFAULT (''),          -- collected, never rendered
  body       TEXT NOT NULL DEFAULT (''),
  status     VARCHAR(16) NOT NULL DEFAULT 'pending',   -- pending | published | removed
  likes      INTEGER NOT NULL DEFAULT 0,
  reports    INTEGER NOT NULL DEFAULT 0,
  ip_hash    VARCHAR(64) NOT NULL DEFAULT '',   -- for abuse only; never joined to a person
  owner_hash VARCHAR(64) NOT NULL DEFAULT '',   -- lets the author see their own pending comment
  created_at TEXT NOT NULL DEFAULT ('')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_diary_comments_article ON diary_comments(article_id, status);
CREATE INDEX idx_diary_comments_parent  ON diary_comments(parent_id);

CREATE TABLE IF NOT EXISTS diary_comment_likes (
  comment_id INTEGER NOT NULL,
  voter_hash VARCHAR(64) NOT NULL,
  PRIMARY KEY (comment_id, voter_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A save belongs to an account when there is one, and to the reader's cookie
-- when there is not — so Save works before sign-in instead of looking broken.
CREATE TABLE IF NOT EXISTS diary_saves (
  user_id      INTEGER NOT NULL DEFAULT 0,
  article_id   INTEGER NOT NULL,
  visitor_hash VARCHAR(64) NOT NULL DEFAULT '',
  created_at   TEXT NOT NULL DEFAULT (''),
  PRIMARY KEY (user_id, article_id, visitor_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_diary_saves_article ON diary_saves(article_id);
