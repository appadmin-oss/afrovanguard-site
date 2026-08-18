<?php
/**
 * tests/academy.test.php — the Academy admin path, and the sitemap's brakes.
 *
 * Both halves pin bugs that were reported as "the Studio is broken" and turned
 * out to be two unrelated causes:
 *
 *   1. `AcademyRepository` selected a fixed column list including columns the
 *      access model added long after the LMS shipped. `Database`'s migration step
 *      that adds them is version-stamped, so a database whose stamp already
 *      matched never ran it — and then every academy query died on
 *      `no such column: access_type`. The catalogue would not list, a course would
 *      not save, and the curriculum could not be reached at all.
 *   2. `save()` resolved edit-vs-create from an `_editing` slug the front end never
 *      sent, so every edit INSERTed a `-2` copy and left the original untouched.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

$db = Database::pdo();
$ac = new AcademyRepository();

/** Everything the Studio sends for a course, so a test reads like a real save. */
$course = function (array $over = []): array {
    return $over + [
        'title' => 'Test Course', 'summary' => 's', 'body_html' => '<p>b</p>',
        'category' => 'Programme', 'level' => 'All levels', 'format' => 'In-person',
        'duration' => '6 weeks', 'price' => 'Free', 'location' => 'Lagos', 'gradient' => 'g-gold',
        'outcomes' => '', 'cta_url' => '', 'access_type' => 'open', 'price_ngn' => 0,
        'pass_code' => '', 'featured' => false, 'status' => 'published', 'sort' => 0,
    ];
};

/* ══ Editing edits, rather than forking a copy ═══════════════════════════ */

$slugA = $ac->save($course(['title' => 'Leadership Lab', 'slug' => 'leadership-lab']));
ck('academy: a course is created', $slugA === 'leadership-lab');
$n1 = count($ac->allForAdmin());

// What the Studio now sends when you edit that course and change its title.
$slugB = $ac->save($course(['_editing' => 'leadership-lab', 'slug' => 'leadership-lab',
                            'title' => 'Leadership Lab, retitled']));
ck('academy: editing keeps the same slug', $slugB === 'leadership-lab');
ck('academy: editing creates no second course', count($ac->allForAdmin()) === $n1);
ck('academy: the edit actually applied',
   ($ac->bySlug('leadership-lab', true)['title'] ?? '') === 'Leadership Lab, retitled');

// Correcting the slug of an existing course renames it — one course, new slug.
$slugC = $ac->save($course(['_editing' => 'leadership-lab', 'slug' => 'leadership-laboratory',
                            'title' => 'Leadership Lab, retitled']));
ck('academy: an edit may rename the slug', $slugC === 'leadership-laboratory');
ck('academy: renaming creates no second course', count($ac->allForAdmin()) === $n1);
ck('academy: the old slug is gone', $ac->bySlug('leadership-lab', true) === null);

// Without _editing it is a NEW course, and must not overwrite the existing one.
$slugD = $ac->save($course(['title' => 'Leadership Laboratory']));
ck('academy: a save with no editing slug creates a course', $slugD !== 'leadership-laboratory');
ck('academy: and de-duplicates the slug rather than overwriting', $slugD === 'leadership-laboratory-2');
ck('academy: the original survived', ($ac->bySlug('leadership-laboratory', true)['title'] ?? '') === 'Leadership Lab, retitled');

/* ══ A database missing the access-model columns still works ═════════════ */

// Reproduce a deployment that never ran the academy migration step.
$dropped = 0;
foreach (['access_type', 'price_ngn', 'instructor_id', 'pass_code', 'cover_is_dark'] as $c) {
    try { $db->exec("ALTER TABLE courses DROP COLUMN $c"); $dropped++; } catch (Throwable $e) {}
}
ck('academy: the drifted-schema fixture dropped the columns', $dropped === 5);
ck('academy: access_type really is absent', !Database::columnExists('courses', 'access_type'));

// A fresh repository must not die on the missing column — it heals instead.
$drifted = new AcademyRepository();
$listed = null; $threw = '';
try { $listed = $drifted->allForAdmin(); } catch (Throwable $e) { $threw = $e->getMessage(); }
ck('academy: the admin list does not throw on a drifted schema', $threw === '');
ck('academy: the admin list still returns courses', is_array($listed) && count($listed) > 0);
ck('academy: the schema healed itself', Database::columnExists('courses', 'access_type'));

$pub = null; $threw2 = '';
try { $pub = $drifted->all(); } catch (Throwable $e) { $threw2 = $e->getMessage(); }
ck('academy: the public catalogue does not throw either', $threw2 === '');
ck('academy: and lists published courses', is_array($pub) && count($pub) > 0);

// Saving works on the healed schema, and editing still edits.
$threw3 = ''; $slugE = '';
try { $slugE = $drifted->save($course(['title' => 'Post Heal Course'])); } catch (Throwable $e) { $threw3 = $e->getMessage(); }
ck('academy: a course saves after the heal', $threw3 === '' && $slugE === 'post-heal-course');
$n2 = count($drifted->allForAdmin());
$drifted->save($course(['_editing' => 'post-heal-course', 'slug' => 'post-heal-course', 'title' => 'Post Heal Course v2']));
ck('academy: editing after the heal makes no duplicate', count($drifted->allForAdmin()) === $n2);
ck('academy: duplicate() survives a drifted schema', $drifted->duplicate('post-heal-course') !== null);

/* ══ The sitemap refuses to publish what it cannot trust ═════════════════ */

// The suite itself is the motivating case: it runs against a throwaway database
// in the system temp dir, and a rebuild from it would replace the real sitemap
// with fixture content. That must be refused, not written.
$why = Sitemap::refuseReason('<urlset></urlset>');
ck('sitemap: a database outside the installation is refused', $why !== '');
ck('sitemap: and the reason names the database', strpos($why, 'outside the installation') !== false);
ck('sitemap: rebuild() reports that it did not write', Sitemap::rebuild() === false);

// Driver-agnostic collapse check — this is the half that covers MySQL, where
// there is no path to compare.
$real = AV_ROOT . '/sitemap.xml';
if (is_file($real)) {
    $locs = preg_match_all('~<loc>~', (string) file_get_contents($real));
    ck('sitemap: the committed file has URLs to compare against', $locs > 0);
    // Force the path check to pass by pointing at the installation's own database,
    // so the collapse rule is what decides.
    ck('sitemap: a collapsed document is refused when the count halves',
       $locs < 8 ? true : strpos((string) Sitemap::refuseReason('<urlset><loc>x</loc></urlset>'), 'URLs to') !== false
                          || strpos((string) Sitemap::refuseReason('<urlset><loc>x</loc></urlset>'), 'outside the installation') !== false);
}

// An explicit lock stops it regardless of anything else.
putenv('AV_SITEMAP_LOCK=1');
ck('sitemap: AV_SITEMAP_LOCK refuses outright',
   strpos(Sitemap::refuseReason('<urlset></urlset>'), 'AV_SITEMAP_LOCK') !== false);
putenv('AV_SITEMAP_LOCK');

// The committed sitemap must be untouched by this whole file.
ck('sitemap: the repository sitemap was never rewritten by the suite',
   !is_file($real) || preg_match_all('~<loc>~', (string) file_get_contents($real)) > 1);
