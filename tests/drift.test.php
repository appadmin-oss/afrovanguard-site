<?php
/**
 * tests/drift.test.php — surviving a database that missed a migration.
 *
 * The bug class, stated once: a repository names a fixed list of columns, some of
 * which are added by one of `Database`'s **version-stamped** migration steps. A
 * deployment whose `schema_state` already matched never runs those steps, so the
 * columns are never added, and then every query naming one returns a 500. It is
 * not a subtle degradation — the Studio page is empty and the public page is down.
 *
 * This bit the Academy (`courses.access_type`) and the Diary (`articles.cover_url`)
 * independently. The distinction that matters: columns added by a subsystem's own
 * on-demand `ensure()` — Mentorship, Meetings, IQ — are re-checked on every call
 * and so heal themselves already. Only the stamped steps can leave a gap.
 *
 * Each table is dropped down to its pre-migration shape and then exercised, so
 * these assertions fail if anyone reintroduces an unguarded fixed column list.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

$db = Database::pdo();

/** Drop $cols from $table, reporting how many actually went. */
$drift = function (string $table, array $cols) use ($db): int {
    $n = 0;
    foreach ($cols as $c) {
        try { $db->exec("ALTER TABLE $table DROP COLUMN $c"); $n++; } catch (Throwable $e) {}
    }
    return $n;
};

/* ══ articles — the Diary, admin and public ══════════════════════════════ */

// Exactly what Database::ensureColumns() adds, i.e. what a settled deployment
// created before those columns existed will be missing.
$articleLate = ['cover_url', 'og_image', 'audio_url', 'format', 'series_id', 'series_part', 'cover_is_dark'];
ck('drift: articles fixture dropped its late columns', $drift('articles', $articleLate) === count($articleLate));
ck('drift: articles.cover_url really is absent', !Database::columnExists('articles', 'cover_url'));

$repo = new DiaryRepository();
$threw = '';
$cards = null;
try { $cards = $repo->all(); } catch (Throwable $e) { $threw = $e->getMessage(); }
ck('drift: the public Diary listing does not throw', $threw === '');
ck('drift: it still returns rows', is_array($cards));
ck('drift: the articles schema healed itself', Database::columnExists('articles', 'cover_url'));

$threw2 = '';
try { $repo->allForAdmin(); } catch (Throwable $e) { $threw2 = $e->getMessage(); }
ck('drift: the Studio entry list does not throw', $threw2 === '');

// Writing must not name a column that is missing either.
$drift('articles', $articleLate);
$fresh = new DiaryRepository();
$threw3 = ''; $slug = '';
try {
    $slug = $fresh->save([
        'slug' => 'drift-entry', 'title' => 'Drift entry', 'dek' => 'd', 'category' => 'Dispatch',
        'authors_html' => 'The Afrovanguard Team', 'published' => 'Aug 18, 2026', 'published_at' => '2026-08-18',
        'read_minutes' => 3, 'gradient' => 'g-gold', 'mc_title' => 'X', 'cover_url' => '', 'og_image' => '',
        'body_html' => '<p>b</p>', 'audio_url' => '', 'featured' => false, 'status' => 'published',
        'format' => 'standard', 'series' => '', 'series_part' => 0, 'sections' => [], 'related' => [],
    ]);
} catch (Throwable $e) { $threw3 = $e->getMessage(); }
ck('drift: an entry saves against a drifted articles table', $threw3 === '' && $slug === 'drift-entry');
ck('drift: and the saved entry reads back', ($repo->bySlug('drift-entry', true)['title'] ?? '') === 'Drift entry');

/* ══ courses — the Academy ═══════════════════════════════════════════════ */

$courseLate = ['access_type', 'price_ngn', 'instructor_id', 'pass_code', 'cover_is_dark'];
ck('drift: courses fixture dropped its late columns', $drift('courses', $courseLate) === count($courseLate));

$ac = new AcademyRepository();
$threw4 = '';
try { $ac->all(); $ac->allForAdmin(); } catch (Throwable $e) { $threw4 = $e->getMessage(); }
ck('drift: the Academy catalogue and admin list do not throw', $threw4 === '');
ck('drift: the courses schema healed itself', Database::columnExists('courses', 'access_type'));

/* ══ The migration step itself must work on every engine ═════════════════ */

// ensureColumns() used to probe with `PRAGMA table_info`, which is not SQL on
// MySQL or Postgres — so the step threw there and a server database never gained
// these columns at all, which is the deployment most likely to be drifted.
$src = (string) file_get_contents(AV_ROOT . '/lib/Database.php');
$step = (string) (preg_split('/private static function ensureColumns/', $src)[1] ?? '');
$step = substr($step, 0, (int) strpos($step, "\n    }"));
// Comments are stripped first: the method's own comment explains what it used to
// do, and matching that would make this assertion pass for the wrong reason.
$code = (string) preg_replace('~^\s*//.*$~m', '', $step);
ck('drift: the articles migration no longer uses a SQLite-only PRAGMA',
   strpos($code, 'PRAGMA table_info') === false);
ck('drift: it probes portably instead', strpos($code, 'columnExists') !== false);
ck('drift: and it is driver-aware about column types', strpos($code, 'driver()') !== false);

// Both heals are reachable without a second copy of the DDL living elsewhere.
ck('drift: the articles heal is exposed', is_callable(['Database', 'ensureArticleSchema']));
ck('drift: the academy heal is exposed', is_callable(['Database', 'ensureAcademySchema']));

// Guard the guard: SCHEMA_REV must be past 1, or settled deployments never
// re-run the additive steps and every fix above only helps fresh installs.
$rev = (new ReflectionClass('Database'))->getConstant('SCHEMA_REV');
ck('drift: SCHEMA_REV was bumped so settled deployments re-migrate', is_int($rev) && $rev >= 2);

/* ══ Why the Portal and NGV are not exposed ══════════════════════════════
   Their subsystems re-run their own schema step on every request, so a missing
   column is re-added rather than being a permanent gap. That is not a happy
   accident — it is the difference between them and the two that broke, and it
   holds only while no subsystem starts gating its schema work on a flag stored
   in the database. These assertions fail if one starts to.
   ═══════════════════════════════════════════════════════════════════════ */

// The only persisted migration gate in the codebase is Database's own
// `schema_state`. Every other key in app_meta is content or config: a rules
// version counter, an AI knowledge cache, the auth policy, NGV page copy, the
// super-admin seed fingerprint, birthday sends. None of them gate DDL.
//
// This is an allowlist rather than a pattern match, because the risky thing is a
// key nobody has thought about. A NEW persisted key fails this assertion, and
// whoever adds it has to confirm it is not gating a migration — which is the
// mistake that took out the Academy and the Diary.
$knownMetaKeys = [
    'schema_state', 'schema_migrated_at',        // Database's own migration stamp
    'brand_theme', 'auth_policy',                // config
    'av_rules_ver', 'ai_knowledge', 'ai_knowledge_ver',
    'ngv_content', 'ngv_content_prev',
    'superadmin_seed_fp', 'superadmin_initial_password',
    'bday_sent', 'diary_ref_codes',
    // Accountability (§13/§14). Reviewed: neither gates a migration.
    // `accountability_from` is the cold-start watermark — escalations never look
    // at sessions before it, so a lost value re-stamps to today and under-reports
    // rather than escalating history at everyone. `accountability_last_run` is the
    // once-a-day guard; a lost value costs one extra sweep, which is idempotent.
    'accountability_from', 'accountability_last_run',
    // Chat bot (lib/ChatBot). Reviewed: a cache of Google's PUBLIC signing
    // certificates, TTL'd and refetched from Google's own endpoint. Losing it
    // costs one HTTPS request. It holds no secret and gates no migration —
    // and it is never trusted on its own: a key id absent from it is rejected
    // rather than assumed valid, so a stale cache fails closed.
    'chat_certs',
    // NGV fees (lib/NgvLedger). Reviewed: gates no migration. It holds the
    // ledger's on/off switch, the rollout guard (`accrueFrom`), the reminder
    // cadence and any pinned amount. Losing it fails SAFE in every direction —
    // `enabled` defaults to false, so nothing accrues and nothing is chased; the
    // amounts fall back to the ones on the public page. The one field worth
    // naming is `accrueFrom`: lost, it re-stamps to the month fees are next
    // switched on, which under-charges rather than back-charging a roster.
    'ngv_fees',
];
$foundKeys = [];
foreach (glob(AV_ROOT . '/lib/*.php') as $f) {
    $src = (string) file_get_contents($f);
    // Literal keys, plus the constants those files use for them.
    if (preg_match_all("~meta(?:Get|Set)\('([a-z_]+)'~", $src, $m)) {
        foreach ($m[1] as $k) $foundKeys[$k] = true;
    }
    if (preg_match_all("~private const [A-Z_]+ = '([a-z_]+)';~", $src, $m2)) {
        // Only count constants in files that actually touch app_meta.
        if (strpos($src, 'metaGet') !== false || strpos($src, 'metaSet') !== false) {
            foreach ($m2[1] as $k) $foundKeys[$k] = true;
        }
    }
}
$unknown = array_values(array_diff(array_keys($foundKeys), $knownMetaKeys));
ck('drift: no unreviewed persisted meta key has appeared', $unknown === []);
if ($unknown !== []) error_log('[test] unreviewed app_meta keys: ' . implode(', ', $unknown));

// NGV runs on its own database and its own provisioning, so it needs its own
// check. provision() must stay unstamped — guarded per process, never persisted —
// because its participants INSERT names `plan` in a fixed column list, so a
// database that missed that ALTER would fail every enrolment.
$ngvSrc = (string) @file_get_contents(AV_ROOT . '/lib/NgvDb.php');
ck('drift: NgvDb provisions on connect', strpos($ngvSrc, 'self::provision();') !== false);
ck('drift: and does not persist a migration stamp',
   strpos($ngvSrc, 'metaSet') === false && strpos($ngvSrc, 'schema_state') === false);
// Repair is now the whole-schema additive sync rather than one hand-written ALTER
// for one column. The behaviour is proved live below; this pins the wiring, and
// that the DDL stays the single source both the create and the sync read.
ck('drift: NgvDb runs the additive schema sync',
   strpos($ngvSrc, 'syncTablesFromDdl') !== false);
ck('drift: and the sync reads the same DDL provisioning uses',
   strpos($ngvSrc, '$ddl = self::ddl();') !== false);
ck('drift: no hand-written per-column ALTER is left to fall out of date',
   strpos($ngvSrc, 'ALTER TABLE ngv_participants ADD COLUMN') === false);

// Live proof rather than a source grep: drop the column, reconnect, expect it back.
if (class_exists('NgvDb')) {
    try {
        $npdo = NgvDb::pdo();
        $has = function () use ($npdo): bool {
            foreach ($npdo->query('PRAGMA table_info(ngv_participants)') as $r) {
                if (($r['name'] ?? '') === 'plan') return true;
            }
            return false;
        };
        ck('drift: ngv_participants.plan starts present', $has());
        $npdo->exec('ALTER TABLE ngv_participants DROP COLUMN plan');
        ck('drift: the NGV fixture dropped it', !$has());
        // provision() is per-process, so clear the flag to simulate a fresh request.
        $flag = new ReflectionProperty('NgvDb', 'provisioned');
        $flag->setAccessible(true); $flag->setValue(null, false);
        $m = new ReflectionMethod('NgvDb', 'provision'); $m->setAccessible(true); $m->invoke(null);
        ck('drift: a fresh request repairs it', $has());
    } catch (Throwable $e) {
        ck('drift: the NGV heal check ran', false);
        error_log('[test] ngv heal: ' . $e->getMessage());
    }
}

/* ══ The additive sync itself ════════════════════════════════════════════
   `CREATE TABLE IF NOT EXISTS` cannot deliver a column added to the DDL later, so
   a deployed database silently never gets it. `Database::syncTablesFromDdl()` is
   the general repair, and NGV depends on it more than the main database does: it
   has no schema.<driver>.sql and no out-of-band migrate script, so this is its
   only additive path.
   ═══════════════════════════════════════════════════════════════════════ */

if (class_exists('NgvDb')) {
    $npdo = NgvDb::pdo();
    $dm = new ReflectionMethod('NgvDb', 'ddl'); $dm->setAccessible(true);
    $ngvDdl = (string) $dm->invoke(null);
    $colsOf = function (string $t) use ($npdo): array {
        $out = [];
        foreach ($npdo->query('PRAGMA table_info(' . $t . ')') as $r) $out[] = (string) ($r['name'] ?? '');
        return $out;
    };

    // Every NGV table, not just the one that had a hand-written ALTER. `plan` on
    // ngv_applications is the case that motivated this: it is named in a fixed
    // INSERT list and had no repair path at all.
    $ngvDrop = [
        'ngv_participants'   => ['plan', 'focus_note', 'books', 'remind_off', 'training_total'],
        'ngv_applications'   => ['plan', 'education', 'reviewed_by'],
        // `credit_kind` is the one this repair path now genuinely carries: every
        // deployed NGV database has ngv_payments WITHOUT it, and the ledger reads
        // it to tell money received from money waived.
        'ngv_payments'       => ['voided', 'method', 'credit_kind', 'void_reason'],
        'ngv_charges'        => ['reason', 'source', 'void_reason'],
        'ngv_certifications' => ['reference', 'issued_by'],
    ];
    $droppedOk = true;
    foreach ($ngvDrop as $t => $cs) {
        foreach ($cs as $c) {
            try { $npdo->exec("ALTER TABLE $t DROP COLUMN $c"); } catch (Throwable $e) { $droppedOk = false; }
        }
    }
    ck('sync: the NGV fixture dropped columns from every table', $droppedOk);

    // A pre-ledger payment row: the repair has to leave it readable AND give it
    // the credit_kind the ledger's arithmetic reads, or every payment already
    // recorded stops counting as money received the day this ships.
    $npdo->exec("INSERT INTO ngv_payments (member_id, kind, amount) VALUES (4343, 'commitment', 750)");

    $addedN = Database::syncTablesFromDdl($npdo, $ngvDdl, 'sqlite', 'test');
    ck('sync: it reports how many columns it added', $addedN === 17);
    ck('sync: a payment row that predates credit_kind is repaired to a real payment',
       (string) $npdo->query('SELECT credit_kind FROM ngv_payments WHERE member_id = 4343')->fetchColumn() === 'payment');
    $allBack = true;
    foreach ($ngvDrop as $t => $cs) {
        $have = $colsOf($t);
        foreach ($cs as $c) if (!in_array($c, $have, true)) $allBack = false;
    }
    ck('sync: every dropped column is restored', $allBack);
    ck('sync: including ngv_applications.plan, which had no ALTER path',
       in_array('plan', $colsOf('ngv_applications'), true));

    // Running twice must be a no-op, or every request would churn the schema.
    ck('sync: it is idempotent', Database::syncTablesFromDdl($npdo, $ngvDdl, 'sqlite', 'test') === 0);

    // A missing table is created outright, not merely diffed.
    $npdo->exec('DROP TABLE IF EXISTS ngv_certifications');
    Database::syncTablesFromDdl($npdo, $ngvDdl, 'sqlite', 'test');
    ck('sync: a missing table is recreated', $colsOf('ngv_certifications') !== []);

    // Data already in the table must survive the repair — this runs against
    // populated tables in production, which is why the softening below exists.
    $npdo->exec("INSERT INTO ngv_payments (member_id, amount) VALUES (4242, 500)");
    $npdo->exec("ALTER TABLE ngv_payments DROP COLUMN note");
    Database::syncTablesFromDdl($npdo, $ngvDdl, 'sqlite', 'test');
    ck('sync: existing rows survive a repair',
       (int) $npdo->query('SELECT amount FROM ngv_payments WHERE member_id = 4242')->fetchColumn() === 500);
    ck('sync: and the column came back on the populated table',
       in_array('note', $colsOf('ngv_payments'), true));
}

// Softening: a column that cannot be added verbatim must still land. NOT NULL with
// no default and a non-constant DEFAULT both have to be relaxed, because ADD COLUMN
// refuses them on a populated table — and a nullable column beats an aborted
// migration and a 500 on every query that names it.
// A dedicated in-memory connection: the worker takes any PDO by design, and this
// keeps the DDL clear of read locks held by other statements in the suite.
$soft = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    $soft->exec('DROP TABLE IF EXISTS t_sync_soft');
    $soft->exec("CREATE TABLE t_sync_soft (id INTEGER PRIMARY KEY AUTOINCREMENT, a TEXT NOT NULL DEFAULT '')");
    $soft->exec("INSERT INTO t_sync_soft (a) VALUES ('existing row')");
    $softDdl = "CREATE TABLE IF NOT EXISTS t_sync_soft (id INTEGER PRIMARY KEY AUTOINCREMENT, "
             . "a TEXT NOT NULL DEFAULT '', b TEXT NOT NULL, c TEXT NOT NULL DEFAULT (datetime('now')), d INTEGER NOT NULL DEFAULT 7);";
    $n = Database::syncTablesFromDdl($soft, $softDdl, 'sqlite', 'test');
    $have = [];
    foreach ($soft->query('PRAGMA table_info(t_sync_soft)') as $r) $have[] = (string) $r['name'];
    ck('sync: NOT NULL without a default is softened and still added', in_array('b', $have, true));
    ck('sync: a non-constant DEFAULT is softened and still added', in_array('c', $have, true));
    ck('sync: a constant DEFAULT is preserved', in_array('d', $have, true));
    ck('sync: the existing row is untouched',
       (string) $soft->query("SELECT a FROM t_sync_soft WHERE id = 1")->fetchColumn() === 'existing row');
    ck('sync: and it reports the three additions', $n === 3);
    // PRIMARY KEY / AUTOINCREMENT columns are never added — SQLite cannot, and the
    // table already has its key anyway.
    ck('sync: it does not try to add a primary key', !in_array('id2', $have, true));
    $soft->exec('DROP TABLE IF EXISTS t_sync_soft');
} catch (Throwable $e) {
    ck('sync: the softening check ran', false);
    error_log('[test] softening: ' . $e->getMessage());
}

// One source of truth: syncSchemaFromFile() must delegate rather than keep a
// second copy of the parser that can drift from this one.
$dbSrc = (string) file_get_contents(AV_ROOT . '/lib/Database.php');
$fromFile = (string) (preg_split('/private static function syncSchemaFromFile/', $dbSrc)[1] ?? '');
$fromFile = substr($fromFile, 0, (int) strpos($fromFile, "\n    }"));
ck('sync: the main schema sync delegates to the shared worker',
   strpos($fromFile, 'syncTablesFromDdl') !== false);
ck('sync: and keeps no parser of its own', strpos($fromFile, 'PRAGMA table_info') === false);

try { $db->exec("DELETE FROM articles WHERE slug = 'drift-entry'"); } catch (Throwable $e) {}
