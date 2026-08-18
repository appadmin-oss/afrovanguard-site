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

## Additive schema sync

`CREATE TABLE IF NOT EXISTS` does nothing to a table that already exists, so a
column added to a `CREATE TABLE` later never reaches a database that was
provisioned before it. That is not a theoretical gap — it is what took out the
Academy admin (`courses.access_type`) and the Diary (`articles.cover_url`), on
both the Studio and the public pages.

`Database::syncTablesFromDdl(PDO $pdo, string $ddl, ?string $driver, string $tag)`
is the repair. Given a block of canonical SQLite DDL it will, for each
`CREATE TABLE` in it:

- create the table if it is missing (translated for the target driver), and
- `ALTER TABLE … ADD COLUMN` any column the live table does not have.

It never drops or rewrites anything, and it returns the number of columns added.

**Columns are softened so the ALTER cannot fail on a populated table.** Only the
type token and a *constant* `DEFAULT` are carried over; `NOT NULL` without a
constant default, `CHECK`, inline `REFERENCES` and non-constant defaults like
`(datetime('now'))` are dropped, and `PRIMARY KEY` / `AUTOINCREMENT` columns are
skipped entirely. A nullable column that exists beats a migration that aborts and
a 500 on every query naming it. On MySQL and Postgres a bare `TEXT` carrying a
default becomes `VARCHAR(191)`, because several MySQL builds reject a default on
`TEXT`.

Two callers, one parser:

| Caller | DDL source | Drivers |
|---|---|---|
| `Database::syncSchemaFromFile()` | `db/schema.sql` | SQLite only — server databases are provisioned from `schema.<driver>.sql` out of band |
| `NgvDb::provision()` | `NgvDb::ddl()` | **all** — NGV has no `schema.<driver>.sql` and no out-of-band migrate script, so this is its only additive path |

NGV is the case that needs it most. It runs on a separate connection, and its
schema used to be repaired by a single hand-written
`ALTER TABLE ngv_participants ADD COLUMN plan`. That covered one column on one
table; `ngv_applications.plan` was named in a fixed `INSERT` list with no repair
path at all. The sync covers every column in the NGV schema, so the next column
added to `NgvDb::ddl()` reaches deployed databases without anyone remembering to
write a matching ALTER.

If you add a column to a `CREATE TABLE` anywhere, that is now enough — but bump
`Database::SCHEMA_REV` when the column is delivered by one of the version-stamped
migration steps, or settled deployments will never re-run them. `tests/drift.test.php`
pins all of this, including that the two callers keep sharing one parser.

### Verified against a real server

The MySQL branches are exercised against MariaDB 10.11, not inferred from a SQLite
run — `tests/mysql.test.php`, which skips unless `AV_TEST_MYSQL_DSN` is set:

```
AV_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=av_test;charset=utf8mb4' \
AV_TEST_MYSQL_USER=… AV_TEST_MYSQL_PASS=… php tests/run.php
```

That run found a real defect the SQLite suite structurally could not: a **repaired**
`created_at` came back `TEXT NULL DEFAULT NULL`, while a **created** one was
`DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`. `translateDDL()` promotes that
column when it creates a table; the `ADD COLUMN` path was dropping the
non-constant default and leaving TEXT behind — so the same column had two
different types depending on whether its table had ever been repaired. The repair
path now applies the same promotion, and the test fails without it.

## How to switch (staging first!)

1. Create an empty MySQL/Postgres database + user.
2. Migrate schema **and** data in one step with the built-in tool:
   ```
   php db/migrate.php --to=mysql --host=… --port=3306 --db=… --user=… --pass=… --apply-schema
   php db/migrate.php --to=pgsql --host=… --port=5432 --db=… --user=… --pass=… --apply-schema
   ```
   `--apply-schema` provisions the target from `db/schema.<driver>.sql` (and the
   auxiliary tables); it then copies every table FK-safely, **preserving ids**,
   re-syncs Postgres sequences, and **verifies row counts** (non-zero exit on any
   mismatch). `--dry-run` previews; `--truncate` replaces an existing target;
   `--from=/path.sqlite` overrides the source (default `AV_DB_PATH`). Target
   connection can also come from the `AV_DB_*` env vars instead of flags.
3. Set the `AV_DB_*` env vars (driver + connection) to point the app at the target.
4. Smoke-test every flow (below) on staging before pointing production at it.

> The migrator is validated end-to-end against live **PostgreSQL 16** and
> **MariaDB 10.11** (schema apply + full copy + count verification, incl. the
> reserved `app_meta.key` column and the admin-managed tables).

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
