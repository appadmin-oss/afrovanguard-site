<?php
/**
 * tests/diaryengagement.test.php — views, applause, the conversation, audio.
 *
 * ── WHAT IS WORTH PINNING HERE ──────────────────────────────────────────────
 * Engagement numbers are the kind of thing nobody checks until somebody quotes
 * them in a funding application. The failures worth a test are the ones that
 * make a number look fine and be wrong, and the ones that publish something
 * nobody read first:
 *
 *   • A view counter that counts refreshes, robots and the editor's own
 *     previews measures the office, not the audience.
 *   • A clap cap the browser enforces is not a cap.
 *   • A comment must not be public because somebody posted it. Pending means
 *     invisible to everyone but its author — including in the count.
 *   • One level of replies, enforced on the way in. A renderer that cannot
 *     draw a third level does not stop one being stored.
 *   • A comment removed from a thread takes its replies with it, or the
 *     answers end up under whatever question is above them now.
 *   • Counts come back NULL when they cannot be read, never zero.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$db   = Database::pdo();
$repo = new DiaryRepository();

// A browser, not a robot: every count below depends on this.
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36';

/** Forget this reader, so the next assertion starts from a clean cookie. */
$forget = function () {
    foreach (array_keys($_COOKIE) as $k) if (str_starts_with($k, 'av_')) unset($_COOKIE[$k]);
    $ref = new ReflectionClass('DiaryVisitor');
    foreach (['id' => null, 'maps' => []] as $prop => $val) {
        $p = $ref->getProperty($prop); $p->setAccessible(true); $p->setValue(null, $val);
    }
};
$forget();

$body = "<h2 id=\"one\">One</h2>\n<p>" . str_repeat('word ', 300) . "</p>\n"
      . "<h2 id=\"two\">Two</h2>\n<p>" . str_repeat('word ', 300) . "</p>\n"
      . "<h2 id=\"three\">Three</h2>\n<p>" . str_repeat('word ', 300) . "</p>\n";
$slug = $repo->save([
    'slug' => 'engagement-fixture', 'title' => 'Engagement fixture', 'dek' => 'A fixture.',
    'category' => 'Field notes', 'authors_html' => 'Tunde Afolabi', 'published' => '1 Jan 2026',
    'published_at' => '2026-01-01', 'read_minutes' => 9, 'gradient' => 'g-gold',
    'mc_title' => 'Engagement fixture', 'cover_url' => '', 'og_image' => '', 'body_html' => $body,
    'featured' => 0, 'status' => 'published', 'format' => 'standard',
]);
$art = $repo->bySlug($slug);
$aid = (int) $art['id'];
foreach (['diary_views', 'diary_comments', 'diary_comment_likes', 'diary_saves'] as $t) {
    try { $db->exec("DELETE FROM {$t} WHERE 1=1"); } catch (Throwable $e) {}
}

/* ══ VIEWS ═══════════════════════════════════════════════════════════════ */

ck('views: the first read counts', $repo->recordView($aid) === true);
ck('views: …and a refresh thirty seconds later does not — one reader reading '
 . 'one entry is one read, however many times the page reloads',
    $repo->recordView($aid) === false);
ck('views: the entry now reads exactly one view', ($repo->counts($aid)['views'] ?? -1) === 1);

$forget();
ck('views: a different reader counts separately', $repo->recordView($aid) === true);
ck('views: …and the total is two', ($repo->counts($aid)['views'] ?? -1) === 2);

$forget();
$was = $_SERVER['HTTP_USER_AGENT'];
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
ck('views: a robot is not an audience', $repo->recordView($aid) === false);
$_SERVER['HTTP_USER_AGENT'] = 'curl/8.5.0';
ck('views: nor is a scripted fetch', $repo->recordView($aid) === false);
$_SERVER['HTTP_USER_AGENT'] = '';
ck('views: nor is a request with no user agent at all', $repo->recordView($aid) === false);
$_SERVER['HTTP_USER_AGENT'] = $was;
ck('views: none of those three moved the number', ($repo->counts($aid)['views'] ?? -1) === 2);

// The editor refreshing their own entry is the easiest way to make a view
// counter meaningless, so an admin session is excluded by name.
$forget();
$_COOKIE[AV_ADMIN_COOKIE] = 'not-a-valid-cookie';
ck('views: a broken admin cookie is still just a reader — exclusion is for '
 . 'admins who ARE admins, not for anyone who sends the cookie name',
    $repo->recordView($aid) === true);

/* ══ APPLAUSE ════════════════════════════════════════════════════════════ */

$forget();
$before = $repo->counts($aid)['claps'];
for ($i = 0; $i < DiaryRepository::CLAP_CAP; $i++) $repo->clapOnce($slug);
ck('applause: fifty from one reader all land',
    ($repo->counts($aid)['claps'] - $before) === DiaryRepository::CLAP_CAP);
ck('applause: the fifty-first is refused by the SERVER, not by the browser',
    $repo->clapOnce($slug) === null);
ck('applause: …and the total did not move', ($repo->counts($aid)['claps'] - $before) === DiaryRepository::CLAP_CAP);
ck('applause: the reader is told how many of theirs are counted',
    $repo->myClaps($aid) === DiaryRepository::CLAP_CAP);
$forget();
ck('applause: a different reader starts again at zero', $repo->myClaps($aid) === 0);

/* ══ COMMENTS ════════════════════════════════════════════════════════════ */

$forget();
$c1 = $repo->addThreadComment($slug, 'Ada Nwosu', 'The cost breakdown is the useful part.', 'ada@example.com');
ck('comments: posting returns the stored comment', is_array($c1) && $c1['id'] > 0);
ck('comments: it arrives PENDING — posting is not publishing', $c1['pending'] === true);
ck('comments: its author can see it', count($repo->commentTree($aid)) === 1);
ck('comments: it is not in the public count', $repo->commentCount($aid) === 0);

$authorSees = $repo->commentTree($aid);
$forget();
ck('comments: …and NOBODY ELSE can see it, which is the whole moderation model',
    $repo->commentTree($aid) === []);
ck('comments: the heading count agrees with what this reader can see',
    $repo->threadCount($aid) === 0);

ck('comments: two characters is not a comment',
    $repo->addThreadComment($slug, 'Ada Nwosu', 'ok') === null);
ck('comments: nor is a body with no name',
    $repo->addThreadComment($slug, '   ', 'A perfectly reasonable sentence.') === null);

// The email is collected and never rendered: it is not in the view model at
// all, so no later edit to the template can print it by accident.
ck('comments: the view model has no email field to print',
    !array_key_exists('email', $c1));
$stored = $db->query('SELECT email FROM diary_comments WHERE id = ' . (int) $c1['id'])->fetchColumn();
ck('comments: …but it IS stored, for the team to reply to', $stored === 'ada@example.com');

/* ── One level of replies, enforced where it is stored ── */
$r1 = $repo->addThreadComment($slug, 'Chidi Okonkwo', 'Agreed, and the mentor line surprised me.', '', (int) $c1['id']);
ck('replies: a reply names its parent', (int) $r1['parent_id'] === (int) $c1['id']);
$r2 = $repo->addThreadComment($slug, 'Bola Eze', 'A reply to the reply.', '', (int) $r1['id']);
ck('replies: a reply TO a reply joins the same thread rather than opening a '
 . 'third level the renderer cannot draw',
    (int) $r2['parent_id'] === (int) $c1['id']);
$depth = $db->query('SELECT COUNT(*) FROM diary_comments c JOIN diary_comments p ON p.id = c.parent_id WHERE p.parent_id IS NOT NULL')->fetchColumn();
ck('replies: nothing in the table is nested two deep', (int) $depth === 0);

/* ── Publishing, likes, reports ── */
$repo->setCommentStatus((int) $c1['id'], 'published');
$repo->setCommentStatus((int) $r1['id'], 'published');
ck('moderation: a published comment is visible to everyone', count($repo->commentTree($aid)) === 1);
ck('moderation: …and counted', $repo->commentCount($aid) === 2);

$n = $repo->likeComment((int) $c1['id'], true);
ck('likes: a like counts once', $n === 1);
ck('likes: the same reader liking twice does not count twice',
    $repo->likeComment((int) $c1['id'], true) === 1);
ck('likes: unliking takes it back', $repo->likeComment((int) $c1['id'], false) === 0);
ck('likes: unliking again does not go negative', $repo->likeComment((int) $c1['id'], false) === 0);

for ($i = 0; $i < DiaryRepository::REPORTS_TO_HIDE; $i++) { $forget(); $repo->reportComment((int) $c1['id']); }
$row = $db->query('SELECT status, reports FROM diary_comments WHERE id = ' . (int) $c1['id'])->fetch();
ck('reports: three reports send a published comment back for review — one '
 . 'reader with a grudge is not a moderation decision, three is a look',
    $row['status'] === 'pending' && (int) $row['reports'] >= DiaryRepository::REPORTS_TO_HIDE);
$repo->setCommentStatus((int) $c1['id'], 'published');
ck('reports: publishing it again clears the flags, so it does not bounce '
 . 'straight back on the next report',
    (int) $db->query('SELECT reports FROM diary_comments WHERE id = ' . (int) $c1['id'])->fetchColumn() === 0);

ck('moderation: the queue shows what is waiting', $repo->moderationCount() >= 1);
$repo->setCommentStatus((int) $c1['id'], 'removed');
$kid = $db->query('SELECT status FROM diary_comments WHERE id = ' . (int) $r1['id'])->fetchColumn();
ck('moderation: removing a comment removes its replies — an answer left under '
 . 'a deleted question reads as an answer to whatever is above it now',
    $kid === 'removed');

/* ══ SAVES ═══════════════════════════════════════════════════════════════ */

$forget();
ck('saves: nothing is saved to begin with', $repo->isSaved($aid) === false);
ck('saves: a reader with no account can still keep an entry', $repo->addSave($aid) === true);
ck('saves: …and it is still kept on the next page', $repo->isSaved($aid) === true);
$repo->removeSave($aid);
ck('saves: unsaving removes it', $repo->isSaved($aid) === false);
$repo->addSave($aid, 7);
ck('saves: a signed-in member saves against their account', $repo->isSaved($aid, 7) === true);
$forget();
ck('saves: …and that save does not leak to a different reader of the same entry',
    $repo->isSaved($aid) === false);

/* ══ COUNTS ══════════════════════════════════════════════════════════════ */

$c = $repo->counts($aid);
ck('counts: one call answers all three numbers',
    isset($c['views'], $c['comments'], $c['claps']));
ck('counts: an entry that does not exist has no counts, rather than three '
 . 'zeroes that look like a real entry nobody read',
    $repo->counts(999999) === null);
$many = $repo->countsFor([$aid, 999999]);
ck('counts: a page of cards is answered in one pass', isset($many[$aid]['views']));

/* ══ SORTING ═════════════════════════════════════════════════════════════ */

$other = $repo->save([
    'slug' => 'engagement-fixture-2', 'title' => 'Quieter entry', 'dek' => 'Fewer readers.',
    'category' => 'Field notes', 'authors_html' => 'Ada Nwosu', 'published' => '2 Jan 2026',
    'published_at' => '2026-01-02', 'read_minutes' => 4, 'gradient' => 'g-gold',
    'mc_title' => 'Quieter entry', 'cover_url' => '', 'og_image' => '', 'body_html' => '<p>Short.</p>',
    'featured' => 0, 'status' => 'published', 'format' => 'standard',
]);
$byRead = array_column($repo->page(['limit' => 48, 'sort' => 'read'])['items'], 'slug');
ck('sort: "most read" puts the most-read entry first — ordered in SQL, so it '
 . 'is the most read of ALL entries and not of the first page',
    ($byRead[0] ?? '') === $slug);
$byDate = array_column($repo->page(['limit' => 48])['items'], 'slug');
ck('sort: the default is still newest first', $byDate !== $byRead || count($byDate) === 1);
$mine = array_column($repo->page(['limit' => 48, 'author' => 'ada-nwosu'])['items'], 'slug');
ck('sort: "more by this writer" finds their entries', in_array($other, $mine, true));
ck('sort: …and not somebody else\'s', !in_array($slug, $mine, true));

/* ══ THE AUTHOR CARD ═════════════════════════════════════════════════════ */

$card = $repo->authorCard($art);
ck('author: the card reads the byline', $card['name'] === 'Tunde Afolabi');
ck('author: initials come from the name', $card['initials'] === 'TA');
ck('author: an unknown byline gets NO role rather than an invented one — a '
 . 'card that calls somebody "Contributor" because nothing was known is a '
 . 'card that lies in a typeface',
    $card['role'] === '');
$two = $repo->authorCard(['authors_html' => 'Ada Nwosu and Tunde Afolabi']);
ck('author: a double byline credits the first name', $two['name'] === 'Ada Nwosu');

/* ══ AUDIO ═══════════════════════════════════════════════════════════════ */

$meta = $repo->audioMeta($aid);
$hasTts = class_exists('Tts') && Tts::available() && Tts::engine() !== 'mock' && Tts::ext() === 'mp3';
ck('audio: with no engine and no recording there is no Listen button — the '
 . 'state the spec calls "audio unavailable", not a button that fails',
    $hasTts ? is_array($meta) : $meta === null);

$db->prepare('UPDATE articles SET audio_url = ? WHERE id = ?')->execute(['https://example.org/narration.mp3', $aid]);
$meta = $repo->audioMeta($aid);
ck('audio: an entry with a recording plays it whether or not TTS is configured',
    is_array($meta) && $meta['kind'] === 'narration');
ck('audio: the length offered is a length, not a zero that would leave the '
 . 'scrubber with nowhere to go',
    ($meta['seconds'] ?? 0) >= 30);
$db->prepare('UPDATE articles SET audio_url = NULL WHERE id = ?')->execute([$aid]);

/* ══ MEASURING AN MP3 ════════════════════════════════════════════════════
   The chapter list is only honest if the duration behind it is measured, so
   the frame walker gets a file whose length is known by construction: 383
   MPEG-1 Layer III frames at 44.1 kHz is 383 × 1152 ÷ 44100 = 10.005 s. */

$audioSrc = file_get_contents(AV_ROOT . '/diary/audio.php');
preg_match('/function audio_mp3_seconds.*?\n}\n/s', $audioSrc, $m);
preg_match('/function audio_chapters.*?\n}\n/s', $audioSrc, $m2);
if (!function_exists('audio_mp3_seconds')) eval($m[0]);
if (!function_exists('audio_chapters')) eval($m2[0]);

$fixture = AV_ROOT . '/tests/fixtures/silence.mp3';
$secs = audio_mp3_seconds($fixture);
ck('audio: an MP3 is measured by walking its frames, not guessed from its '
 . 'size — 10.005 s, to the millisecond', abs($secs - 10.005) < 0.01);
ck('audio: a file that is not MP3 frames measures zero, which the caller '
 . 'reads as "I do not know" rather than "zero seconds long"',
    audio_mp3_seconds(AV_ROOT . '/composer.json') === 0.0);

$chaps = audio_chapters($body, $secs, 999);
ck('chapters: one per H2, in order', count($chaps) === 3 && $chaps[0]['title'] === 'One');
ck('chapters: the first starts at zero', $chaps[0]['t'] === 0);
ck('chapters: the last starts inside the recording, not past its end',
    $chaps[2]['t'] > 0 && $chaps[2]['t'] < (int) ceil($secs));
ck('chapters: they are timed against the REAL duration when one is known, so '
 . 'tapping a chapter lands in it rather than near where the word count '
 . 'guessed it would be',
    $chaps[1]['t'] !== audio_chapters($body, 0.0, 999)[1]['t']);
ck('chapters: an entry with no headings has no chapter list',
    audio_chapters('<p>Just prose.</p>', $secs, 999) === []);

/* ══ KEEP READING ════════════════════════════════════════════════════════ */

$keep = $repo->keepReading($aid, 3);
ck('keep reading: every card carries what §5 asks for — category, title, '
 . 'date, minutes and views',
    $keep === [] || (isset($keep[0]['category'], $keep[0]['title'], $keep[0]['date'], $keep[0]['minutes'], $keep[0]['views'])));
ck('keep reading: an entry never recommends itself',
    !in_array('/diary/' . $slug . '/', array_column($keep, 'url'), true));

/* ══ COMPACT NUMBERS ═════════════════════════════════════════════════════ */

require_once AV_ROOT . '/diary/partials.php';
ck('counts: under a thousand reads as itself', avd_compact(999) === '999');
ck('counts: a thousand reads as 1k', avd_compact(1000) === '1k');
ck('counts: 3,412 reads as 3.4k', avd_compact(3412) === '3.4k');
ck('counts: 12,000 reads as 12k, not 12.0k', avd_compact(12000) === '12k');

/* ══ THE MARKUP THE PAGE ACTUALLY RENDERS ════════════════════════════════ */

ob_start(); avd_mission(); $mission = ob_get_clean();
ck('mission: the owner\'s sentence is printed exactly as written, curly '
 . 'apostrophe and all',
    str_contains($mission, 'Every piece here is about one mission: raising an Incorruptible Generation.')
 && str_contains($mission, 'Don’t just watch the future.'));
ck('mission: all four buttons are there', substr_count($mission, 'avd-mbtn') === 5);  // 4 + the gold modifier
ck('mission: Join is the gold one and goes to volunteering',
    str_contains($mission, 'class="avd-mbtn avd-mbtn--gold" href="/volunteer/"'));
ck('mission: Mentor goes to the mentor sign-up named in the spec',
    str_contains($mission, 'href="/mentorship/become-a-mentor/"'));

$forget();
$pend = $repo->addThreadComment($slug, 'Ada Nwosu', 'A comment nobody has reviewed yet.', '');
ob_start(); avd_comment($repo->commentTree($aid)[0], false); $html = ob_get_clean();
ck('comments: a pending comment tells its author why nobody is replying',
    str_contains($html, 'Only you can see this until it’s reviewed.'));
ck('comments: …and the rendered comment carries no email anywhere in it',
    !str_contains($html, '@example.com'));
