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
  `columnExists()`, `nowExpr()`, `insertIgnore()`, `insertIgnoreExpr()`.
- **The whole runtime query layer is now driver-portable** (SQLite output stays
  byte-identical):
  - **Upserts** — `Database::metaSet()` and `DiaryRepository::addClaps()` branch
    to MySQL `ON DUPLICATE KEY UPDATE` / keep `ON CONFLICT … DO UPDATE` elsewhere.
  - **Insert-ignore** — every `INSERT OR IGNORE` (Diary subscribe/reactions, the
    LMS `course_enrolment` / `lesson_progress` / `certificates`, and the seeders)
    goes through `insertIgnore()` / `insertIgnoreExpr()`.
  - **Timestamps** — runtime `datetime('now')` comparisons/writes against the
    ISO-text columns (`lms_sessions.expires_at`, `lms_users.verify_expires` /
    `last_login`, `memberships.expires_at`, `payments.paid_at`) now bind a UTC
    `gmdate('Y-m-d H:i:s')` parameter — identical to SQLite's `datetime('now')`
    and a pure text comparison on every engine (avoids the `text > timestamp`
    error you'd hit on Postgres). Writes into real timestamp columns
    (`reactions.updated_at`) use `nowExpr()`.
  - **Reserved words** — `app_meta.key` is back-quoted on MySQL.
- **Lazy `ensure*()` table-creation is gated to SQLite.** The auto-create DDL for
  `lms_audit`, `celebrations`, `team`, and `auth_illustrations` is SQLite-only and
  is now skipped on MySQL/Postgres (so a non-SQLite deploy never errors on invalid
  DDL); those tables must be **provisioned out-of-band** there (see caveats).
  `LmsRepository::audit()` is best-effort — a missing audit table never breaks the
  admin action it records.
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
- [ ] **Runtime SQL idioms are all ported** (see "What's done") — no further code
  changes are needed for the query layer. The items below are the *remaining*
  things to do **on the target**, not in the code.
- [ ] **Provision the SQLite-auto tables.** The base `db/schema.sql` (and the
  generated per-driver files) cover the core tables. These additional tables are
  auto-created **only on SQLite** and must be created manually on MySQL/Postgres
  (or the SQLite DDL widened into the schema files with `translateDDL()` + tested):
  `app_meta`, `diary_entries`, `lms_audit`, `team`, `auth_illustrations`,
  `celebrations`. ⚠️ `celebrations` has a column literally named **`key`** (a MySQL
  reserved word) used throughout its INSERT/UPDATE/SELECT — that one feature needs
  reserved-word quoting added across the module before it runs on MySQL; it's the
  only feature not yet MySQL-clean. (The app degrades gracefully if these tables
  are absent: audit is best-effort; the others only power optional admin surfaces.)
- [ ] **Post-release column additions** still ship as SQLite-gated `ensure*()`
  migrations (e.g. email-verification columns, academy access columns). Apply the
  equivalent `ALTER TABLE`s to your target manually, or widen the gate with
  translated DDL once validated.
- [ ] **Seeds use the portable insert-ignore now**, but `db/seed.php` relies on
  `PDO::lastInsertId()` after plain inserts — that works on SQLite/MySQL; on
  **Postgres** verify it returns the new id (or switch those inserts to
  `RETURNING id`). The production cutover path is **data migration from
  `db/diary.sqlite`**, not re-seeding, so this only matters for a fresh empty
  Postgres deploy.

## Smoke tests after switching

Homepage + Diary (list/map + filters + load-more), Academy catalogue, **login /
register / email-verify**, a donation, a contact submit. Watch the error log for
any `datetime('now')` / `ON CONFLICT` / reserved-word failures and port those
idioms as they surface (they're isolated and listed above).
