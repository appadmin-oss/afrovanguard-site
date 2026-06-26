# Database portability (SQLite → MySQL / Postgres)

The app ships on **SQLite** (zero-config, self-bootstrapping). This document
covers running it on **MySQL** or **Postgres**. The connection layer and the
per-driver schemas are in place; a short **validation pass on your target DB**
is still required before switching production (see the checklist).

## What's done

- **Connection is driver-selectable** (`lib/Database.php`). Set:
  - `AV_DB_DRIVER` = `sqlite` (default) | `mysql` | `pgsql`
  - either `AV_DB_DSN` (full PDO DSN), or the parts:
    `AV_DB_HOST`, `AV_DB_NAME`, `AV_DB_PORT`, `AV_DB_USER`, `AV_DB_PASS`
  - (set these via `SetEnv` in `.htaccess`, like the other secrets)
- **Portable query helpers**: `Database::driver()`, `tableExists()`,
  `columnExists()`, `nowExpr()`, `insertIgnore()`. The Diary's pagination/filter
  SQL and its insert-ignore sites already use the portable forms.
- **Per-driver schemas generated** from the canonical `db/schema.sql`:
  - `db/schema.mysql.sql`, `db/schema.pgsql.sql`
  - Regenerate after editing `db/schema.sql`:
    ```
    php -r 'require "lib/bootstrap.php"; file_put_contents("db/schema.mysql.sql", Database::translateDDL(file_get_contents("db/schema.sql"),"mysql"));'
    ```
  - SQLite remains the source of truth; `translateDDL()` returns it **unchanged**
    for sqlite, so the live path is byte-identical.

## How to switch (staging first!)

1. Create an empty MySQL/Postgres database + user.
2. Apply the generated schema: `mysql db < db/schema.mysql.sql` (or
   `psql db -f db/schema.pgsql.sql`).
3. Set the `AV_DB_*` env vars to point at it.
4. Migrate existing data from `db/diary.sqlite` (articles, lms_users, etc.).
5. Smoke-test every flow (below) on staging before pointing production at it.

## Validation checklist / known caveats (do NOT skip)

The translated schema is **best-effort scaffolding** — review it and confirm
on your actual server:

- [ ] **Date columns without a default stay `TEXT`** (`published_at`,
  `expires_at`, `last_login`, `entry_date`, …). The app stores/compares ISO
  strings, and the year/month filter uses `substr(published_at,…)`, which works
  on `TEXT` in all three engines. Convert to `DATE`/`DATETIME` only if you also
  audit those comparisons.
- [ ] **MySQL** needs InnoDB + `utf8mb4` (the generated DDL sets this) for the
  foreign keys and unicode. Confirm FK column types match exactly.
- [ ] **Descending indexes** (`… (published_at DESC)`) need **MySQL 8.0+**;
  older MySQL silently treats them as ascending (harmless).
- [ ] **Remaining runtime idioms still to port** before a non-sqlite deploy is
  fully functional (these run **sqlite-only** today):
  - The `ON CONFLICT … DO UPDATE` upserts in `Database::metaSet()` and
    `DiaryRepository::addClaps()` → MySQL `ON DUPLICATE KEY UPDATE`.
  - `datetime('now')` used inside runtime queries (e.g. `LmsAuth` session/login,
    `verify_expires` check) → route through `Database::nowExpr()`.
  - The idempotent `ensure*()` migrations in `Database.php` (email-verification
    columns, academy tables) emit SQLite DDL and are gated to sqlite — the base
    schema file covers their tables, but post-release column additions must be
    applied to your target manually or the gate widened with translated DDL.
  - The `key` column in `app_meta` is a MySQL reserved word — quote it (`` `key` ``).
- [ ] Re-run the seeds (`db/seed.php`, academy/lessons seeds) against the target
  and confirm content loads.

## Smoke tests after switching

Homepage + Diary (list/map + filters + load-more), Academy catalogue, **login /
register / email-verify**, a donation, a contact submit. Watch the error log for
any `datetime('now')` / `ON CONFLICT` / reserved-word failures and port those
idioms as they surface (they're isolated and listed above).
