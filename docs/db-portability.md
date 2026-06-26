# Database portability (SQLite → MySQL / Postgres)

The app ships on **SQLite** (zero-config, self-bootstrapping). It also runs on
**MySQL/MariaDB** and **PostgreSQL**: the connection layer, the whole runtime
query layer, and the per-driver schemas are in place and have been
**validated end-to-end against a live PostgreSQL 16 and a live MariaDB 10.11**
(see "Validation" below). SQLite remains the default and its behaviour is
byte-identical to before.

## What's done

- **Connection is driver-selectable** (`lib/Database.php`). Set:
  - `AV_DB_DRIVER` = `sqlite` (default) | `mysql` | `pgsql`
  - either `AV_DB_DSN` (full PDO DSN), or the parts:
    `AV_DB_HOST`, `AV_DB_NAME`, `AV_DB_PORT`, `AV_DB_USER`, `AV_DB_PASS`
  - (set these via `SetEnv` in `.htaccess`, like the other secrets)
- **Portable query helpers**: `Database::driver()`, `tableExists()`,
  `columnExists()`, `nowExpr()`, `insertIgnore()`, `insertIgnoreExpr()`,
  `quoteIdent()`.
- **The whole runtime query layer is driver-portable** (SQLite output stays
  byte-identical):
  - **Upserts** — `Database::metaSet()` and `DiaryRepository::addClaps()` branch
    to MySQL `ON DUPLICATE KEY UPDATE`; SQLite/Postgres use `ON CONFLICT … DO
    UPDATE` (Postgres references the existing row table-qualified, e.g.
    `reactions.claps`, since the bare name is ambiguous against `excluded`).
  - **Insert-ignore** — every `INSERT OR IGNORE` (Diary subscribe/reactions, the
    LMS `course_enrolment` / `lesson_progress` / `certificates`, and the seeders)
    goes through `insertIgnore()` / `insertIgnoreExpr()`.
  - **Timestamps** — runtime `datetime('now')` comparisons/writes against the
    ISO-text columns (`lms_sessions.expires_at`, `lms_users.verify_expires` /
    `last_login`, `memberships.expires_at`, `payments.paid_at`) bind a UTC
    `gmdate('Y-m-d H:i:s')` parameter — identical to SQLite's `datetime('now')`
    and a pure text comparison on every engine (avoids the `text > timestamp`
    error on Postgres). Writes into real timestamp columns
    (`reactions.updated_at`) use `nowExpr()`.
  - **Reserved words** — `app_meta.key` and `celebrations.key` are back-quoted on
    MySQL via `Database::quoteIdent()`; bare elsewhere.
- **Schema is complete in `db/schema.sql`.** Columns that used to be added only by
  the SQLite-gated `ensure*()` migrations — `lms_users.email_verified` /
  `verify_hash` / `verify_expires`, `courses.access_type` / `price_ngn` /
  `instructor_id`, `lessons.quiz_json` — are now in the canonical schema, so a
  non-SQLite deploy provisions them from the schema file. (The `ensure*()` calls
  remain as a SQLite safety-net for already-deployed older DBs.)
- **Indexed short-string columns are bounded `VARCHAR`** (`published_at`, `status`,
  `entry_date`, `kind`, `schedule_kind`, `course_slug`). SQLite gives `VARCHAR(n)`
  TEXT affinity (no behaviour change); MySQL needs a bounded length to index them
  (a composite index over a `TEXT` column blows MySQL's 3072-byte key limit).
- **Lazy/admin tables work on every engine.** `celebrations` and `team` (not in the
  base schema) self-create with **driver-aware DDL** when first used; `app_meta`
  likewise. `auth_illustrations`, `lms_audit`, `diary_entries` are in
  `db/schema.sql` (their lazy create is a SQLite-only safety-net).
  `LmsRepository::audit()` is best-effort regardless.
- **Per-driver schemas generated** from the canonical `db/schema.sql`:
  - `db/schema.mysql.sql`, `db/schema.pgsql.sql` (both applied cleanly to the live
    instances — 21 tables each).
  - Regenerate after editing `db/schema.sql`:
    ```
    php -r 'require "lib/bootstrap.php"; file_put_contents("db/schema.mysql.sql", Database::translateDDL(file_get_contents("db/schema.sql"),"mysql"));'
    ```
  - `translateDDL()` returns SQLite **unchanged**, so the live SQLite path is
    byte-identical.

## How to switch (staging first!)

1. Create an empty MySQL/Postgres database + user.
2. Apply the generated schema: `mysql db < db/schema.mysql.sql` (or
   `psql db -f db/schema.pgsql.sql`).
3. Set the `AV_DB_*` env vars to point at it.
4. Migrate existing data from `db/diary.sqlite` (articles, lms_users, etc.).
   This is the cutover path — not re-seeding.
5. Smoke-test every flow (below) on staging before pointing production at it.

## Validation

The full query layer was exercised against real engines with an automated harness
(register → verify → login → session-expiry, diary clap upsert + subscribe +
paginated/filtered feed, membership expiry, enrol / mark-complete / certificate
idempotency, payment finalize, and the `celebrations`/`team` lazy tables):

- **PostgreSQL 16** — 34/34 checks pass; `db/schema.pgsql.sql` applies cleanly.
- **MariaDB 10.11** — 34/34 checks pass; `db/schema.mysql.sql` applies cleanly.
- **SQLite** — 38/38 checks pass; behaviour byte-identical to before.

## Remaining caveats

- **MySQL/MariaDB version**: needs **InnoDB + `utf8mb4`** (the generated DDL sets
  this). `DEFAULT` on `TEXT` columns requires **MySQL ≥ 8.0.13 / MariaDB ≥ 10.2**;
  descending indexes are honoured on **MySQL ≥ 8.0 / MariaDB ≥ 10.8** (older
  versions treat them as ascending — harmless). Validated on MariaDB 10.11.
- **Data migration**: the schema files create empty tables only. Copy existing
  rows from `db/diary.sqlite` into the target before go-live. The PHP seeders
  (`db/seed.php`, `db/academy_seed.php`) are portable now (insert-ignore +
  `lastInsertId()` both verified on Postgres), but they only seed *default/sample*
  content — not your live data.
- **Date columns are ISO `VARCHAR`/`TEXT`** compared as strings (the year/month
  filter uses `substr(published_at,…)`). This is intentional and works on all three
  engines; only convert to native `DATE`/`DATETIME` if you also re-audit those
  comparisons.

## Smoke tests after switching

Homepage + Diary (list/map + filters + load-more), Academy catalogue, **login /
register / email-verify**, a donation, a contact submit, and (if used) the Studio
celebrations/team admin. All of these exercise the paths covered by the automated
cross-engine harness above.
