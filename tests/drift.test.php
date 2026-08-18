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

try { $db->exec("DELETE FROM articles WHERE slug = 'drift-entry'"); } catch (Throwable $e) {}
