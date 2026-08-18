<?php
/**
 * tests/refcodes.test.php — the Diary's permanent reference code.
 *
 * A slug is not an identifier: it gets rewritten for SEO, and the URL somebody
 * wrote on a printout stops resolving. `AVD-2608-0003` — the third Diary entry of
 * August 2026 — is assigned once and never changes.
 *
 * The assertions worth reading are the ones about what does NOT change it. A
 * title correction, a date change and a slug rename must all leave the code
 * alone, because the moment a code can change it is no longer an identifier and
 * every place it was written down is wrong.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

$repo = new DiaryRepository();
$db   = Database::pdo();
$db->exec('DELETE FROM articles');

/** Save an entry, returning the row back. */
$mk = function (string $slug, string $title, string $publishedAt) use ($repo): array {
    $s = $repo->save([
        'slug' => $slug, 'title' => $title, 'dek' => 'Dek', 'category' => 'Dispatch',
        'authors_html' => 'The Afrovanguard Team',
        'published' => date('M j, Y', (int) strtotime($publishedAt)), 'published_at' => $publishedAt,
        'read_minutes' => 3, 'gradient' => 'g-gold', 'mc_title' => 'X',
        'cover_url' => '', 'og_image' => '', 'body_html' => '<p>Body</p>', 'audio_url' => '',
        'featured' => false, 'status' => 'published', 'format' => 'standard',
        'series' => '', 'series_part' => 0, 'sections' => [], 'related' => [],
    ]);
    return $repo->bySlug($s, true) ?: [];
};

ck('ref: the column and index are provisioned', $repo->refCodesEnabled());

/* ---- Shape: a dated prefix and a serial within that month ---- */

$a = $mk('first-august', 'First August entry', '2026-08-03');
$b = $mk('second-august', 'Second August entry', '2026-08-11');
$c = $mk('third-august', 'Third August entry', '2026-08-29');
$d = $mk('first-september', 'First September entry', '2026-09-02');

ck('ref: the first entry of a month is 0001', $a['ref_code'] === 'AVD-2608-0001');
ck('ref: the serial counts up within the month', $b['ref_code'] === 'AVD-2608-0002');
ck('ref: and keeps counting', $c['ref_code'] === 'AVD-2608-0003');
ck('ref: a new month restarts the serial', $d['ref_code'] === 'AVD-2609-0001');
ck('ref: the month comes from the publication date, not today',
   strpos((string) $a['ref_code'], 'AVD-2608-') === 0);
ck('ref: every code is distinct',
   count(array_unique([$a['ref_code'], $b['ref_code'], $c['ref_code'], $d['ref_code']])) === 4);

/* ---- Immutability: the point of the whole feature ---- */

$before = (string) $b['ref_code'];
// A title correction, a different date, a different category, a new gradient.
$repo->save([
    'ref_code' => $before, 'slug' => 'second-august', 'title' => 'Second August entry, retitled',
    'dek' => 'New dek', 'category' => 'Field note', 'authors_html' => 'Someone Else',
    'published' => 'Dec 1, 2026', 'published_at' => '2026-12-01', 'read_minutes' => 9,
    'gradient' => 'g-ink', 'mc_title' => 'Y', 'cover_url' => '', 'og_image' => '',
    'body_html' => '<p>Rewritten</p>', 'audio_url' => '', 'featured' => true, 'status' => 'published',
    'format' => 'feature', 'series' => '', 'series_part' => 0, 'sections' => [], 'related' => [],
]);
$after = $repo->bySlug('second-august', true);
ck('ref: a rewrite of everything else leaves the code alone', (string) $after['ref_code'] === $before);
ck('ref: the rewrite really did apply', (string) $after['title'] === 'Second August entry, retitled');
ck('ref: a later publication date does not re-key the code',
   strpos((string) $after['ref_code'], 'AVD-2608-') === 0);

/* ---- A slug rename renames, rather than forking into a second entry ---- */

$countBefore = (int) $db->query('SELECT COUNT(*) FROM articles')->fetchColumn();
$repo->save([
    'ref_code' => $before, 'slug' => 'second-august-renamed-for-seo', 'title' => 'Second August entry, retitled',
    'dek' => 'New dek', 'category' => 'Field note', 'authors_html' => 'Someone Else',
    'published' => 'Aug 11, 2026', 'published_at' => '2026-08-11', 'read_minutes' => 9,
    'gradient' => 'g-ink', 'mc_title' => 'Y', 'cover_url' => '', 'og_image' => '',
    'body_html' => '<p>Rewritten</p>', 'audio_url' => '', 'featured' => true, 'status' => 'published',
    'format' => 'feature', 'series' => '', 'series_part' => 0, 'sections' => [], 'related' => [],
]);
ck('ref: renaming the slug creates no second entry',
   (int) $db->query('SELECT COUNT(*) FROM articles')->fetchColumn() === $countBefore);
ck('ref: the old slug is gone', $repo->bySlug('second-august', true) === null);
ck('ref: the new slug serves the entry', ($repo->bySlug('second-august-renamed-for-seo', true)['title'] ?? '') === 'Second August entry, retitled');
ck('ref: and the code still resolves to it after the rename',
   $repo->slugForRefCode($before) === 'second-august-renamed-for-seo');
// Renaming onto a slug another entry owns must be refused, not silently merged.
$clashed = false;
try {
    $repo->save([
        'ref_code' => $before, 'slug' => 'first-august', 'title' => 'Trying to steal a slug',
        'dek' => 'd', 'category' => 'Dispatch', 'authors_html' => 'X', 'published' => 'Aug 11, 2026',
        'published_at' => '2026-08-11', 'read_minutes' => 3, 'gradient' => 'g-gold', 'mc_title' => 'Y',
        'cover_url' => '', 'og_image' => '', 'body_html' => '<p>x</p>', 'audio_url' => '',
        'featured' => false, 'status' => 'published', 'format' => 'standard', 'series' => '',
        'series_part' => 0, 'sections' => [], 'related' => [],
    ]);
} catch (Throwable $e) { $clashed = strpos($e->getMessage(), 'slug-taken:') === 0; }
ck('ref: renaming onto a taken slug is refused with a reason', $clashed);
ck('ref: the refused rename changed nothing', ($repo->bySlug('first-august', true)['title'] ?? '') === 'First August entry');

/* ---- Lookup: however a human types it ---- */

foreach ([
    'AVD-2608-0001', 'avd-2608-0001', 'avd 2608 0001', 'AVD26080001',
    '2608-0001', '26080001', ' AVD-2608-0001 ',
] as $typed) {
    ck('ref: normalises ' . var_export($typed, true), DiaryRepository::normaliseRefCode($typed) === 'AVD-2608-0001');
}
foreach (['', 'not a code', 'AVD-26-1', 'first-august', '123', 'AVD'] as $notCode) {
    ck('ref: rejects ' . var_export($notCode, true) . ' as a code', DiaryRepository::normaliseRefCode($notCode) === '');
}
ck('ref: looksLikeRefCode separates a code from a search phrase',
   DiaryRepository::looksLikeRefCode('AVD-2608-0001') && !DiaryRepository::looksLikeRefCode('august entry'));

ck('ref: byRefCode fetches the entry', ($repo->byRefCode('avd 2608 0001', true)['slug'] ?? '') === 'first-august');
ck('ref: an unknown code resolves to nothing', $repo->byRefCode('AVD-9912-9999', true) === null);
ck('ref: a non-code resolves to nothing', $repo->slugForRefCode('first-august') === '');

/* ---- Codes reach the callers that display and search them ---- */

$cards = $repo->all();
ck('ref: card queries carry the code', !empty($cards) && array_key_exists('ref_code', $cards[0]));
$adminRows = $repo->allForAdmin();
ck('ref: the Studio list carries the code', !empty($adminRows) && array_key_exists('ref_code', $adminRows[0]));

/* ---- Backfill: entries that never went through save() ---- */

$cat = (int) $db->query('SELECT id FROM categories LIMIT 1')->fetchColumn();
$ins = $db->prepare(
    'INSERT INTO articles (slug,title,dek,category_id,authors_html,published,published_at,read_minutes,gradient,mc_title,body_html,status)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
);
$ins->execute(['seeded-one', 'Seeded one', 'd', $cat, 'X', 'Jul 5, 2026', '2026-07-05', 3, 'g-gold', 'S', '<p>s</p>', 'published']);
$ins->execute(['seeded-two', 'Seeded two', 'd', $cat, 'X', 'Jul 6, 2026', '2026-07-06', 3, 'g-gold', 'S', '<p>s</p>', 'published']);
ck('ref: a direct INSERT starts with no code',
   (int) $db->query("SELECT COUNT(*) FROM articles WHERE ref_code IS NULL OR ref_code = ''")->fetchColumn() === 2);

// A fresh repository probes for missing codes and fills them — so entries that
// arrive by the installer's seed or an import are not permanently code-less.
(new DiaryRepository())->refCodesEnabled();
ck('ref: the backfill catches them',
   (int) $db->query("SELECT COUNT(*) FROM articles WHERE ref_code IS NULL OR ref_code = ''")->fetchColumn() === 0);
ck('ref: backfilled codes use the entry\'s own month',
   (string) $db->query("SELECT ref_code FROM articles WHERE slug='seeded-one'")->fetchColumn() === 'AVD-2607-0001');
ck('ref: and are ordered oldest-first within it',
   (string) $db->query("SELECT ref_code FROM articles WHERE slug='seeded-two'")->fetchColumn() === 'AVD-2607-0002');
ck('ref: the backfill is idempotent', $repo->backfillRefCodes() === 0);

/* ---- Uniqueness is enforced by the database, not just by the generator ---- */

$dup = false;
try { $db->exec("UPDATE articles SET ref_code = 'AVD-2608-0001' WHERE slug = 'third-august'"); }
catch (Throwable $e) { $dup = true; }
ck('ref: a duplicate code is refused by the unique index', $dup);

$db->exec('DELETE FROM articles');
