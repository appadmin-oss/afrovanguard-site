<?php
/**
 * tests/sitesearch.test.php — the site search: ranking, typing as you go,
 * typos, what is (and is not) indexed, escaping, the JSON the palette reads,
 * and the palette and header control themselves (lib/SiteSearch.php,
 * search.php, lib/search-page.php, assets/site/avsearch.{js,css}). The
 * palette is driven for real in Chromium by tests/a11y/axe-ci.js.
 */
declare(strict_types=1);
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/partials/avh-chrome.php';
$r  = AV_ROOT;
$db = Database::pdo();
$titles = static fn(array $rows): array => array_map(static fn($x) => $x['title'], $rows);
$has = static fn(array $rows, string $title, string $type = ''): bool => (bool) array_filter($rows, static fn($x) => $x['title'] === $title && ($type === '' || $x['type'] === $type));

/* ── Fixtures: a published and a draft Diary entry, a published and a draft
      course, a public, a members' and a draft event. ─────────────────── */
$cat = (int) $db->query('SELECT id FROM categories LIMIT 1')->fetchColumn();
$ins = $db->prepare('INSERT INTO articles (slug,title,dek,category_id,authors_html,published,published_at,read_minutes,gradient,mc_title,body_html,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$ins->execute(['ss-river', 'Rivers of Alimosho', 'How the rains shape the Saturday clean-up', $cat, 'X', 'Jul 5, 2026', '2026-07-05', 3, 'g-gold', 'S', '<p>s</p>', 'published']);
$ins->execute(['ss-secret', 'Zanzibarian secret plans', 'Not out yet', $cat, 'X', 'Jul 6, 2026', '2026-07-06', 3, 'g-gold', 'S', '<p>s</p>', 'draft']);
$ins->execute(['ss-xss', 'Quilting <script>alert(1)</script> & co', 'Dek with <b>tags</b>', $cat, 'X', 'Jul 7, 2026', '2026-07-07', 3, 'g-gold', 'S', '<p>s</p>', 'published']);
$db->exec("INSERT INTO courses (slug, title, summary, status, access_type) VALUES ('ss-photo','Photography basics','Light, framing and the camera','published','open')");
$db->exec("INSERT INTO courses (slug, title, summary, status, access_type) VALUES ('ss-hidden','Xylophonic orchestration','Unreleased','draft','open')");
$day = static fn(int $d): string => (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->modify("+$d days")->format('Y-m-d') . ' 16:00';
SiteEvents::save(['title' => 'Quarterly Gathering Lagos', 'summary' => 'Open floor', 'starts_at' => $day(9), 'audience' => 'public', 'status' => 'published', 'category' => 'Community'], 'test');
SiteEvents::save(['title' => 'Quarterly Members Mixer', 'starts_at' => $day(10), 'audience' => 'members', 'status' => 'published'], 'test');
SiteEvents::save(['title' => 'Quarterly Team Retreat', 'starts_at' => $day(11), 'audience' => 'org', 'status' => 'published'], 'test');
SiteEvents::save(['title' => 'Quarterly Draft Idea', 'starts_at' => $day(12), 'audience' => 'public', 'status' => 'draft'], 'test');

/* ── The shape stays what Chioma's site_search and the palette read ──── */
$rows = SiteSearch::query('donate');
ck('sitesearch: every result has type, title, url, excerpt and crumb', $rows && !array_filter($rows, static fn($x) => !isset($x['type'], $x['title'], $x['url'], $x['excerpt'], $x['crumb'])));
ck('sitesearch: the best hit for "donate" is still the donate page', ($rows[0]['url'] ?? '') === '/donate.html');
ck('sitesearch: a one-character or empty query returns nothing', SiteSearch::query('a') === [] && SiteSearch::query('   ') === []);
ck('sitesearch: Diary results keep their reference code', array_key_exists('code', SiteSearch::query('Rivers of Alimosho')[0] ?? []));

/* ── Ranking ─────────────────────────────────────────────────────────── */
$photo = SiteSearch::query('photography');
ck('ranking: a title match outranks a body match', ($photo[0]['title'] ?? '') === 'Photography basics');
ck('ranking: the title that IS the query comes first', (SiteSearch::query('Events')[0]['url'] ?? '') === '/events/');
ck('ranking: every word must match before any-word results are shown', $titles(SiteSearch::query('rivers alimosho'))[0] === 'Rivers of Alimosho');
ck('ranking: filler words do not stop a match ("the diary")', (SiteSearch::query('the diary')[0]['url'] ?? '') === '/diary/');
ck('ranking: a type filter keeps only that type', SiteSearch::query('quarterly', 12, 'Event') && !array_filter(SiteSearch::query('quarterly', 12, 'Event'), static fn($x) => $x['type'] !== 'Event'));
ck('ranking: a limit is honoured', count(SiteSearch::query('a b c the and', 3)) <= 3 && count(SiteSearch::query('project', 2)) <= 2);

/* ── As you type: prefixes ───────────────────────────────────────────── */
ck('prefix: "photog" finds Photography basics', $has(SiteSearch::query('photog'), 'Photography basics', 'Academy'));
ck('prefix: "mentors" finds Mentorship', $has(SiteSearch::query('mentors'), 'Mentorship', 'Page'));
ck('prefix: "volunt" finds a page about volunteering', (bool) SiteSearch::query('volunt'));

/* ── Typos: one letter off, for words of five letters or more ─────────── */
ck('typo: a missing letter ("mentrship")', ($titles(SiteSearch::query('mentrship'))[0] ?? '') === 'Mentorship');
ck('typo: a changed letter ("photograpky")', $has(SiteSearch::query('photograpky'), 'Photography basics'));
ck('typo: two letters swapped ("donaet")', ($titles(SiteSearch::query('donaet'))[0] ?? '') === 'Donate');
ck('typo: a doubled letter ("acaddemy")', $has(SiteSearch::query('acaddemy'), 'Academy', 'Page'));
ck('typo: short words are not guessed at ("doante" is 6, "dnt" is not)', SiteSearch::query('dnt') === [] || !$has(SiteSearch::query('dnt'), 'Donate'));
ck('typo: one edit, not two ("dxnxte" finds nothing like Donate)', !$has(SiteSearch::query('dxnxte'), 'Donate'));
ck('typo: nearly() — the edit rules', SiteSearch::nearly('mentor', 'mentro') && SiteSearch::nearly('donate', 'donat') && SiteSearch::nearly('donate', 'donatte') && !SiteSearch::nearly('donate', 'dxnxte'));
ck('typo: "Did you mean" offers the nearest word the site uses', SiteSearch::suggest('photogrxphy') === 'photography' && SiteSearch::suggest('photography') === '');
ck('fold: accents do not stop a match', SiteSearch::fold('Ọ̀ṣun') === (class_exists('Normalizer') ? 'osun' : mb_strtolower('Ọ̀ṣun')));

/* ── New sources: projects and public events ─────────────────────────── */
ck('projects: a project is found by name', $has(SiteSearch::query('Techome'), 'Techome', 'Project'));
ck('projects: …and by a programme run under it ("Next Generation Genius" → Street-To-Stardom)', $has(SiteSearch::query('next generation genius'), 'Street-To-Stardom', 'Project'));
$q = SiteSearch::query('quarterly');
ck('events: a published public event is found, with its page', $has($q, 'Quarterly Gathering Lagos', 'Event') && str_starts_with((string) (array_values(array_filter($q, static fn($x) => $x['type'] === 'Event'))[0]['url'] ?? ''), '/events/'));

/* ── Nothing private ─────────────────────────────────────────────────── */
ck('private: a draft Diary entry is not found', !$has(SiteSearch::query('Zanzibarian'), 'Zanzibarian secret plans'));
ck('private: a draft course is not found', !$has(SiteSearch::query('Xylophonic'), 'Xylophonic orchestration'));
ck('private: members\' and team events are not found', !$has($q, 'Quarterly Members Mixer') && !$has($q, 'Quarterly Team Retreat'));
ck('private: a draft event is not found', !$has($q, 'Quarterly Draft Idea'));
ck('private: the constitution is not indexed while it is members-only', ConstitutionDoc::PUBLIC || !array_filter(SiteSearch::query('constitution article membership', 60), static fn($x) => $x['type'] === 'Constitution'));
$src = (string) file_get_contents("$r/lib/SiteSearch.php");
ck('private: People results never carry contact details', !preg_match("~'(email|phone|whatsapp)'~", substr($src, strpos($src, '6) The public People'), 900)));
ck('private: Africa GATES\' feed is not fetched on a search (no network call per keystroke)', !str_contains($src, 'AvEvents::'));

/* ── Safe input, escaped output ──────────────────────────────────────── */
ck('safe: the query is cut to 120 characters', mb_strlen(SiteSearch::normalise(str_repeat('é', 500))) === 120);
ck('safe: control characters and line breaks become spaces', SiteSearch::normalise("a\x00b\nc\td") === 'a b c d');
ck('safe: at most 8 words are searched', count(SiteSearch::terms('one two three four five six seven eight nine ten')) === 8);
ck('safe: invalid UTF-8 does not break the search', is_array(SiteSearch::query("tech\xC3\x28")));
ck('safe: a regex-looking query is just text', is_array(SiteSearch::query('(.*)+[a-z]{2,}\\')) && is_array(SiteSearch::query('/[/')));
$hl = SiteSearch::highlight('Quilting <script>alert(1)</script> & co', 'quilting script');
ck('escape: highlight() escapes every piece and marks only the matches', $hl === '<mark>Quilting</mark> &lt;<mark>script</mark>&gt;alert(1)&lt;/<mark>script</mark>&gt; &amp; co');
ck('escape: a query with HTML in it is never echoed raw', !str_contains(SiteSearch::highlight('a <b>bold</b> move', '<img src=x onerror=1>'), '<img'));
ck('escape: entities are not split by a match ("amp" in "&")', !str_contains(SiteSearch::highlight('Rock & roll', 'amp'), '<mark>'));
ck('escape: highlight marks the start of a word, not the middle', SiteSearch::highlight('Technology and biotech', 'tech') === '<mark>Tech</mark>nology and biotech');
$x = SiteSearch::query('quilting');
ck('escape: results are plain text (the excerpt has its tags stripped)', $x && !str_contains((string) $x[0]['excerpt'], '<b>'));

/* ── The JSON (search.php) ───────────────────────────────────────────── */
$json = static function (array $get): array {
    $_GET = $get; $_SERVER['HTTP_ACCEPT'] = 'application/json';
    set_error_handler(static fn(int $n, string $m): bool => str_contains($m, 'Cannot modify header information'));
    ob_start();
    (static function () { require AV_ROOT . '/search.php'; })();
    $out = (string) ob_get_clean();
    restore_error_handler();
    return json_decode($out, true) ?: [];
};
$j = $json(['q' => 'photography']);
ck('json: ok, q, results, suggest and ai', ($j['ok'] ?? false) === true && $j['q'] === 'photography' && is_array($j['results']) && array_key_exists('suggest', $j) && array_key_exists('ai', $j) && $j['ai'] === null);
ck('json: results carry the same fields as SiteSearch::query', isset($j['results'][0]['type'], $j['results'][0]['title'], $j['results'][0]['url'], $j['results'][0]['excerpt'], $j['results'][0]['crumb']));
$j = $json(['q' => 'photogrxphy zzzqqq']);
ck('json: no results comes with a suggestion', $j['results'] === [] ? $j['suggest'] !== '' : true);
$j = $json(['q' => 'x']);
ck('json: a short query is an empty answer, not an error', ($j['ok'] ?? false) === true && $j['results'] === []);
$j = $json(['q' => ['array' => 'attack'], 'type' => ['x']]);
ck('json: a query sent as an array is ignored safely', ($j['ok'] ?? false) === true && $j['results'] === []);
$j = $json(['q' => 'academy', 'type' => 'Nonsense']);
ck('json: an unknown type filter is ignored, not trusted', ($j['ok'] ?? false) === true && count($j['results']) > 0);
$j = $json(['q' => '<script>alert(1)</script>']);
$raw = json_encode($j);
$_GET = []; unset($_SERVER['HTTP_ACCEPT']);
$searchSrc = (string) file_get_contents("$r/search.php");
ck('json: <, > and & are escaped inside the JSON (JSON_HEX_TAG | JSON_HEX_AMP)', str_contains($searchSrc, 'JSON_HEX_TAG | JSON_HEX_AMP'));
ck('json: the AI answer is still only on ?ai=1, behind the rate limit', str_contains($searchSrc, "(string) (\$_GET['ai'] ?? '') === '1'") && strpos($searchSrc, "av_rate_ok('search_ai'") < strpos($searchSrc, 'AvBot::reply('));
ck('json: nothing is cached by a proxy', str_contains($searchSrc, "header('Cache-Control: no-store');"));

/* ── The results page ────────────────────────────────────────────────── */
$page = static fn(array $get): string => render_page(AV_ROOT . '/lib/search-page.php', $get);
$h = $page(['q' => 'photography']);
ck('page: grouped by type, with filter chips and their counts', str_contains($h, '<h2 class="avsr-gh">Academy courses</h2>') && (bool) preg_match('~aria-current="true">All <span class="avsr-n">\d+</span>~', $h));
ck('page: the matched words are marked', str_contains($h, '<mark>Photography</mark>'));
ck('page: the Ask Chioma row is a button, hidden until the script shows it (opt-in, never automatic)', str_contains($h, 'data-avs-ask-row hidden><button type="button" class="avsr-ask-btn" data-avs-ask="photography"'));
$h = $page(['q' => '"><script>alert(1)</script>']);
ck('page: the query is escaped wherever it is echoed', !str_contains($h, '<script>alert(1)</script>') && str_contains($h, '&lt;script&gt;'));
$h = $page(['q' => 'phxtogrxphy']);   // two letters off: too far for the search, near enough to suggest
ck('page: no results offers "Did you mean" and places to browse', str_contains($h, 'Did you mean <a class="avsr-sugg"') && str_contains($h, 'href="/events/">events</a>'));
$h = $page([]);
ck('page: with no query, popular links', str_contains($h, '<h2 class="avsr-gh">Popular</h2>'));
$h = $page(['q' => 'quarterly', 'type' => 'Event']);
ck('page: a type filter shows only that type', str_contains($h, '<mark>Quarterly</mark> Gathering Lagos') && !str_contains($h, '<h2 class="avsr-gh">Pages</h2>'));

/* ── The header control, on every kind of page ───────────────────────── */
$chrome = avh_chrome_html();
ck('trigger: the Home header has the field-like control on a wide screen and the icon on a phone',
   str_contains($chrome['nav'], '<div class="avh-wide">') && substr_count($chrome['nav'], 'data-search-open>') === 2
   && str_contains($chrome['nav'], '<span class="av-st-t">Search…</span><kbd class="av-st-k" aria-hidden="true">Ctrl K</kbd>') && str_contains($chrome['nav'], 'class="av-st av-st--icon"'));
ck('trigger: without JavaScript it is a link to the search page', str_contains(SiteSearch::trigger(), 'href="/search.php" data-search-open') && str_contains(SiteSearch::trigger('icon'), '<span class="av-sr">Search Afrovanguard</span>'));
ck('trigger: the lesson player and the portal have it', str_contains((string) file_get_contents("$r/academy/learn.php"), "<div class=\"avle-top-r\">\n    <?= SiteSearch::trigger() ?>")
   && (bool) preg_match('~<div class="avp-top-actions avn-top-actions">\s*<\?= SiteSearch::trigger\(\'\', \'Search the site…\'\) \?>~', (string) file_get_contents("$r/portal/index.php")));
foreach (['index.html', 'about.html', 'contact.html', 'donate.html', 'projects/index.html', '404.html', '500.html'] as $f) {
    $s = (string) file_get_contents("$r/$f");
    ck("trigger: $f has the control and the palette's files", substr_count($s, 'data-search-open>') === 2 && (bool) preg_match('~/assets/site/avsearch\.js\?v=[0-9a-f]{10}" defer~', $s) && (bool) preg_match('~/assets/site/avsearch\.css\?v=~', $s));
}
A11y::resetForTest();
ob_start(); render_head(['title' => 'T', 'canonical' => 'https://x.test/', 'chrome' => 'app']); $appHead = (string) ob_get_clean();
av_chrome_mode('site'); A11y::resetForTest();
ck('trigger: every page drawn with render_head loads the palette (A11y::head)', str_contains($appHead, '/assets/site/avsearch.js?v=') && str_contains($appHead, '/assets/site/avsearch.css?v='));
ck('trigger: the old site-header search modal is gone (one search, everywhere)', !str_contains((string) file_get_contents("$r/lib/partials.php"), 'id="avSearch"') && !str_contains((string) file_get_contents("$r/assets/site/nav.js"), "getElementById('avSearch')"));

/* ── The palette, in script ──────────────────────────────────────────── */
$js = (string) file_get_contents("$r/assets/site/avsearch.js");
ck('palette: the combobox pattern — the field owns one listbox and points at the active option', str_contains($js, ' role="combobox" aria-expanded="false" aria-controls="avs-list" aria-autocomplete="list"') && str_contains($js, 'role="listbox"') && str_contains($js, "input.setAttribute('aria-activedescendant', 'avs-o' + i)"));
ck('palette: options are grouped, labelled, and out of the tab order', str_contains($js, 'role="group" aria-labelledby="avs-g-') && str_contains($js, 'role="option" id="avs-o') && str_contains($js, 'tabindex="-1"'));
ck('palette: ↑/↓ move, Enter opens, Esc closes (native dialog), Tab stays inside', str_contains($js, "e.key === 'ArrowDown' || e.key === 'ArrowUp'") && str_contains($js, "e.key === 'Enter' && !e.isComposing") && str_contains($js, 'dlg.showModal()') && str_contains($js, "dlg.addEventListener('keydown', trap)"));
ck('palette: focus goes back to whatever opened it', str_contains($js, "dlg.addEventListener('close', restore)") && str_contains($js, 'o.focus({ preventScroll: true })'));
ck('palette: Ctrl/⌘+K anywhere, "/" only when not typing; a page with its own palette keeps its keys', str_contains($js, "(e.metaKey || e.ctrlKey) && !e.altKey && !e.shiftKey && (e.key === 'k' || e.key === 'K')") && str_contains($js, "e.key === '/' && !e.metaKey && !e.ctrlKey && !e.altKey && !typing(e.target)") && str_contains($js, 'script[src*="/portal/palette.js"]'));
ck('palette: 150ms after the last key, and a newer query aborts the one in flight', str_contains($js, 'setTimeout(function () { run(false); }, 150)') && str_contains($js, 'ctrl.abort()') && str_contains($js, 'new AbortController()'));
ck('palette: filter chips are toggle buttons in a toolbar (one Tab stop, arrows inside)', str_contains($js, 'role="toolbar" aria-label="Show only"') && str_contains($js, 'aria-pressed="') && str_contains($js, "e.key === 'ArrowRight'"));
ck('palette: recent searches stay in this browser and can be cleared', str_contains($js, "RECENT = 'av.search.recent'") && str_contains($js, "title: 'Clear recent searches'") && !preg_match('~fetch\([^)]*recent~', $js));
ck('palette: result counts are announced politely', str_contains($js, 'role="status" aria-live="polite" data-avs-live') && str_contains($js, "(n === 1 ? ' result' : ' results')"));
ck('palette: loading, no results (with a suggestion and places to browse) and an honest error', str_contains($js, "render('loading')") && str_contains($js, 'Did you mean <button type="button" class="avs-sugg"') && str_contains($js, "'Browse instead'") && str_contains($js, 'Search is unavailable right now.'));
ck('palette: "See all results" goes to the search page; without a choice Enter submits the form there', str_contains($js, "'See all results for “' + esc(q)") && str_contains($js, 'action="/search.php" method="get"'));
ck('palette: Ask Chioma is a row the visitor chooses, never automatic', str_contains($js, "act: 'ask'") && str_contains($js, "if (it.act === 'ask') { ask(state.q); return; }") && substr_count($js, 'ai=1') === 1);
ck('palette: every server string is escaped before it is drawn', str_contains($js, "function esc(s)") && str_contains($js, "out += esc(text.slice(i, s)) + '<mark>' + esc(text.slice(s, e)) + '</mark>'") && !preg_match('~innerHTML\s*=\s*[a-z]+\.(title|excerpt|url)~', $js));
$css = (string) file_get_contents("$r/assets/site/avsearch.css");
ck('palette: on a phone the sheet follows the visual viewport (the keyboard cannot cover the results)', str_contains($js, "dlg.style.setProperty('--avs-vv-h', Math.round(vv.height) + 'px');") && str_contains($css ?? (string) file_get_contents("$r/assets/site/avsearch.css"), 'height:var(--avs-vv-h,100%)'));
ck('palette css: a full-screen sheet on a phone, calm motion only when allowed, out of print', str_contains($css, '@media (max-width:639px){') && str_contains($css, '@media (prefers-reduced-motion:no-preference){.avs[open]') && str_contains($css, '@media print{.av-st,.avs{display:none!important}}'));
ck('palette assets: small', filesize("$r/assets/site/avsearch.js") < 32000 && filesize("$r/assets/site/avsearch.css") < 16000);
$axe = (string) file_get_contents("$r/tests/a11y/axe-ci.js");
ck('CI: axe also checks the palette open, with results, at both widths', str_contains($axe, 'const PALETTE = ') && str_contains($axe, "Control+k"));

$db->exec("DELETE FROM articles WHERE slug LIKE 'ss-%'");
$db->exec("DELETE FROM courses WHERE slug LIKE 'ss-%'");
$db->exec("DELETE FROM av_site_events WHERE title LIKE 'Quarterly %'");
