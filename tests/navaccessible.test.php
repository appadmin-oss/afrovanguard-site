<?php
/**
 * tests/navaccessible.test.php — every navigation can be used with a screen
 * reader and a keyboard (WCAG 2.2 AA, docs/accessibility-navigation.md).
 *
 * Two halves. The shared chrome is rendered in-process and its widgets are
 * checked one by one. Then the real pages are fetched through router.php on a
 * loopback server sharing the suite's database — signed out, as a member who
 * teaches, and with a Studio session — and every page is held to the same
 * rules, because a nav is only accessible on the page it is actually drawn on:
 *
 *   - the first thing Tab reaches is a skip link, and it lands on the page's
 *     one visible <main>;
 *   - every <nav> has a name, no two share one, and none sits inside another;
 *   - the link for where you are says so (aria-current), not only its colour;
 *   - a button that opens something says what (aria-controls → a real id)
 *     and whether it is open (aria-expanded);
 *   - no link is nameless, and no icon in the chrome is read out;
 *   - a breadcrumb is an ordered list ending on the current page;
 *   - <html lang> and <title> are set.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/partials/avh-chrome.php';

/** Parse $html into a DOMXPath (HTML5 elements are fine; libxml's complaints are not the point). */
function navacc_dom(string $html): DOMXPath
{
    $d = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    return new DOMXPath($d);
}

/** Is $el, or anything above it, hidden from everybody (hidden / aria-hidden / inert)? */
function navacc_hidden(DOMElement $el): bool
{
    for ($n = $el; $n instanceof DOMElement; $n = $n->parentNode) {
        if ($n->hasAttribute('hidden') || $n->getAttribute('aria-hidden') === 'true' || $n->hasAttribute('inert')) return true;
        if ($n->tagName === 'template' || $n->tagName === 'noscript') return true;
    }
    return false;
}

/** The accessible name, as far as markup alone decides it. */
function navacc_name(DOMXPath $x, DOMElement $el): string
{
    $by = trim($el->getAttribute('aria-labelledby'));
    if ($by !== '') {
        $out = '';
        foreach (preg_split('/\s+/', $by) as $id) { $t = $x->query('//*[@id="' . $id . '"]')->item(0); if ($t) $out .= ' ' . $t->textContent; }
        if (trim($out) !== '') return trim($out);
    }
    if (trim($el->getAttribute('aria-label')) !== '') return trim($el->getAttribute('aria-label'));
    $text = '';
    $walk = static function (DOMNode $n) use (&$walk, &$text): void {
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) { $text .= $c->textContent; continue; }
            if (!$c instanceof DOMElement || $c->getAttribute('aria-hidden') === 'true' || $c->hasAttribute('hidden')) continue;
            if ($c->tagName === 'img') { $text .= ' ' . $c->getAttribute('alt'); continue; }
            if ($c->tagName === 'svg') { $t = $c->getElementsByTagName('title')->item(0); $text .= $t ? $t->textContent : ''; continue; }
            if (in_array($c->tagName, ['script', 'style'], true)) continue;
            $walk($c);
        }
    };
    $walk($el);
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    return $text !== '' ? $text : trim($el->getAttribute('title'));
}

/**
 * Every rule, for one page. $label prefixes each assertion; $expect may name
 * what must carry aria-current: ['current' => [href substring => value]].
 */
function navacc_page(string $label, string $html, array $expect = []): void
{
    $x = navacc_dom($html);
    $root = $x->query('//html')->item(0);
    ck("$label: <html lang> is set", $root instanceof DOMElement && trim($root->getAttribute('lang')) !== '');
    ck("$label: <title> is set", trim((string) ($x->query('//head/title')->item(0)?->textContent ?? '')) !== '');

    /* The first focusable thing in <body> (in source order — no page here
       uses a positive tabindex) is a skip link to the one visible <main>. */
    $first = null;
    foreach ($x->query('//body//*[self::a[@href] or self::button or self::input[not(@type="hidden")] or self::select or self::textarea or @tabindex]') as $el) {
        /** @var DOMElement $el */
        if ($el->getAttribute('tabindex') === '-1' || navacc_hidden($el)) continue;
        $first = $el; break;
    }
    $href = $first ? $first->getAttribute('href') : '';
    ck("$label: the first thing Tab reaches is a skip link (got <" . ($first?->tagName ?? 'nothing') . ' ' . $href . '>)',
       $first !== null && $first->tagName === 'a' && str_starts_with($href, '#') && preg_match('/^skip to (main content|(the )?lesson|sign in)$/i', navacc_name($x, $first)) === 1);
    $mains = iterator_to_array($x->query('//main | //*[@role="main"]'));
    $visibleMains = array_filter($mains, static fn(DOMElement $m) => !navacc_hidden($m));
    /* An app shell (the Studios) draws every view as its own <main hidden> and shows
       one from script: none showing in the markup is right there, two never is. */
    ck("$label: one <main> is showing (got " . count($visibleMains) . ')', count($visibleMains) === 1
        || (!empty($expect['app_views']) && count($visibleMains) === 0 && count($mains) >= 1));
    $target = $href !== '' && $href !== '#' ? $x->query('//*[@id="' . substr($href, 1) . '"]')->item(0) : null;
    /* On main itself, or (sign-in) on the one thing inside main the page is for; focusable either way. */
    ck("$label: …and the skip link lands on it ($href)", $target instanceof DOMElement
        && ($target->tagName === 'main' || $target->getAttribute('role') === 'main' || $target->hasAttribute('data-skip-target')
            || $x->query('ancestor::main', $target)->length === 1)
        && $target->getAttribute('tabindex') === '-1');

    /* Landmarks: every nav named, names unique, none nested. */
    $names = [];
    foreach ($x->query('//nav | //*[@role="navigation"]') as $n) {
        /** @var DOMElement $n */
        $nm = navacc_name($x, $n);
        $nm = trim($n->getAttribute('aria-label')) !== '' || $n->hasAttribute('aria-labelledby') ? $nm : '';
        ck("$label: a <nav class=\"" . $n->getAttribute('class') . "\"> has a name", $nm !== '');
        $names[] = strtolower($nm);
        ck("$label: <nav aria-label=\"$nm\"> is not inside another nav", $x->query('ancestor::nav | ancestor::*[@role="navigation"]', $n)->length === 0);
    }
    $dups = array_keys(array_filter(array_count_values($names), static fn($c) => $c > 1));
    ck("$label: no two navs share a name (" . implode(', ', $dups) . ')', $dups === []);
    ck("$label: a banner (header) landmark is present", $x->query('//body//header[not(ancestor::main or ancestor::article or ancestor::aside or ancestor::section or ancestor::nav)] | //*[@role="banner"]')->length >= 1 || !empty($expect['no_banner']));

    /* Buttons that open things say what and whether. */
    foreach ($x->query('//*[@aria-expanded][ancestor::nav or ancestor::header or ancestor::aside or ancestor::*[@data-nav-region]]') as $b) {
        /** @var DOMElement $b */
        $c = $b->getAttribute('aria-controls');
        $ok = $c !== '' && array_reduce(preg_split('/\s+/', $c), static fn($a, $id) => $a && $x->query('//*[@id="' . $id . '"]')->length === 1, true);
        ck("$label: <" . $b->tagName . ' class="' . $b->getAttribute('class') . "\"> with aria-expanded controls an element that exists ($c)", $ok);
        ck("$label: …and is a real button", $b->tagName === 'button' || $b->getAttribute('role') === 'button');
        if ($b->getAttribute('aria-haspopup') === 'true' || $b->getAttribute('aria-haspopup') === 'menu') {
            $menu = $c !== '' ? $x->query('//*[@id="' . $c . '"]')->item(0) : null;
            ck("$label: aria-haspopup=menu on <" . $b->getAttribute('class') . '> only for a real role=menu', $menu instanceof DOMElement && $menu->getAttribute('role') === 'menu');
        }
    }

    /* No nameless links; no icon in the chrome read out. */
    foreach ($x->query('//a[@href]') as $a) {
        /** @var DOMElement $a */
        if (navacc_hidden($a)) continue;
        if (navacc_name($x, $a) === '') ck("$label: link to " . $a->getAttribute('href') . ' has a name', false);
    }
    foreach ($x->query('//*[self::nav or self::header or @data-nav-region]//*[local-name()="svg"]') as $s) {
        /** @var DOMElement $s */
        if (navacc_hidden($s) || $s->getAttribute('role') === 'img' && $s->hasAttribute('aria-label')) continue;
        $p = $s->parentNode instanceof DOMElement ? $s->parentNode->getAttribute('class') : '';
        ck("$label: the icon in .$p is hidden from screen readers", false);
    }

    /* Breadcrumbs: an ordered list, ending on the page. */
    foreach ($x->query('//nav[@aria-label="Breadcrumb"]') as $bc) {
        $ol = $x->query('.//ol', $bc)->item(0);
        ck("$label: the breadcrumb is an ordered list", $ol !== null);
        $items = $ol ? $x->query('./li', $ol) : null;
        $last = $items && $items->length ? $items->item($items->length - 1) : null;
        ck("$label: …whose last item is marked as the current page", $last instanceof DOMElement && $x->query('.//*[@aria-current="page"] | self::*[@aria-current="page"]', $last)->length >= 1);
    }

    foreach ($expect['current'] ?? [] as $needle => $val) {
        $hit = false;
        foreach ($x->query('//*[@aria-current="' . $val . '"]') as $el) { /** @var DOMElement $el */ if (str_contains($el->getAttribute('href') . ' ' . $el->getAttribute('data-tab') . ' ' . $el->getAttribute('data-view') . ' ' . $el->textContent, $needle)) $hit = true; }
        ck("$label: \"$needle\" carries aria-current=\"$val\"", $hit);
    }
}

/* ── 1. The shared chrome, widget by widget ─────────────────────────────── */

$chrome = avh_chrome_html();
$nx = navacc_dom('<html><body>' . $chrome['nav'] . $chrome['foot'] . '</body></html>');
ck('chrome: the Home nav sits in a <header> (the banner landmark)', $nx->query('//header/nav[@aria-label="Main"]')->length === 1);
ck('chrome: the footer links are a <nav aria-label="Footer"> inside the footer', $nx->query('//footer//nav[@aria-label="Footer"]')->length === 1);
ck('chrome: the account links are not a nav nested in the Main nav', $nx->query('//nav//nav')->length === 0);
$disclosure = $nx->query('//*[@data-avh-menu] | //*[@data-avh-acct-btn] | //*[@data-avh-burger] | //*[@data-avh-acc]');
ck('chrome: there are disclosure buttons to check', $disclosure->length >= 12);
foreach ($disclosure as $b) {
    /** @var DOMElement $b */
    $panel = $nx->query('//*[@id="' . $b->getAttribute('aria-controls') . '"]')->item(0);
    $what = trim($b->textContent) !== '' ? trim($b->textContent) : $b->getAttribute('aria-label');
    ck("chrome: \"$what\" is a <button type=button> with aria-expanded=false", $b->tagName === 'button' && $b->getAttribute('type') === 'button' && $b->getAttribute('aria-expanded') === 'false');
    ck("chrome: \"$what\" controls a panel that exists and starts hidden", $panel instanceof DOMElement && $panel->hasAttribute('hidden'));
    ck("chrome: \"$what\" is a disclosure, not a menu (no aria-haspopup)", !$b->hasAttribute('aria-haspopup'));
}
ck('chrome: no role=menu anywhere in the site navigation', $nx->query('//*[@role="menu" or @role="menuitem"]')->length === 0);
navacc_page('chrome', '<!doctype html><html lang="en"><head><title>t</title></head><body><a href="#main">Skip to main content</a>'
    . $chrome['nav'] . '<main id="main" tabindex="-1"></main>' . $chrome['foot'] . '</body></html>');

/* Both heads open with the skip link, worded the same. */
$navaccHead = static function (array $o): string { ob_start(); render_head($o + ['title' => 'T', 'canonical' => 'https://x.test/']); return (string) ob_get_clean(); };
ck('head: a site page opens with "Skip to main content" → #main', str_contains($navaccHead([]), '<a class="avh-skip" href="#main">Skip to main content</a>'));
ck('head: an app shell opens with "Skip to main content" → #main-content', str_contains($navaccHead(['chrome' => 'app']), '<a href="#main-content" class="skip-link">Skip to main content</a>'));
av_chrome_mode('site');

/* The behaviour lives in script; its contract is pinned here and driven for real in Chromium (docs/accessibility-navigation.md). */
$avhJs = (string) file_get_contents(AV_ROOT . '/assets/site/avh.js');
ck('chrome js: a menu opened from the keyboard or a click moves focus into its panel', str_contains($avhJs, 'if (open === k) focusFirst(panelOf(b));') && str_contains($avhJs, 'if (!menu.hidden) focusFirst(menu);'));
ck('chrome js: a click outside closes an open mega menu', (bool) preg_match('~document\.addEventListener\(\'click\', e => \{ if \(open && !nav\.contains\(e\.target\)\)~', $avhJs));
ck('chrome js: static pages mark the current page too', str_contains($avhJs, "setAttribute('aria-current', 'page')"));

/* The static pages (the Home-chrome .html pages, the error pages, the built
   Street-To-Stardom site): every one with a navigation, held to the same rules. */
foreach (avh_chrome_pages(AV_ROOT) as $f) {
    $h = (string) file_get_contents($f);
    if (!preg_match('~<nav\b~i', $h)) continue;
    $rel = substr($f, strlen(AV_ROOT) + 1);
    if (str_starts_with($rel, 'projects/sts/') && preg_match('~^projects/sts/(.+/)?(googl|yandex)~', $rel)) continue;
    navacc_page("static $rel", $h, str_starts_with($rel, 'projects/sts/') || $rel === 'member.html' ? ['no_banner' => true] : []);
}

/* The mentor portal draws only for a mentor; its nav markup is held here. */
$navaccMentor = (string) file_get_contents(AV_ROOT . '/mentorship/mentor/index.php') . (string) file_get_contents(AV_ROOT . '/mentorship/mentor/views/_tabbar.php');
ck('mentor portal: the skip link lands on a focusable main', str_contains($navaccMentor, '<main class="avm-main" id="main-content" tabindex="-1">'));
ck('mentor portal: both navs are named, differently', str_contains($navaccMentor, '<nav class="avm-nav" aria-label="Mentor portal">') && str_contains($navaccMentor, '<nav class="avm-tabbar" aria-label="Sections" data-bottom-ui>'));
ck('mentor portal: the current section is marked in words', substr_count($navaccMentor, "' aria-current=\"page\"'") >= 5);
ck('mentor portal: the tab bar\'s More says it opens a dialog', str_contains($navaccMentor, 'data-avm-open="more" aria-haspopup="dialog"'));

/* ── 2. The real pages, through the router ──────────────────────────────── */

$naDb = Database::pdo();
$naDb->exec("DELETE FROM lms_users WHERE email LIKE '%@navacc.test'");
$naDb->prepare("INSERT INTO lms_users (name, email, password_hash, role, status, email_verified) VALUES (?,?,?,?,?,1)")
     ->execute(['Nadia Nav', 'nadia@navacc.test', 'x', 'admin', 'active']);
$naUid = (int) $naDb->lastInsertId();
$naTok = bin2hex(random_bytes(20));
$naDb->prepare('INSERT INTO lms_sessions (token_hash, user_id, ip, ua, expires_at) VALUES (?,?,?,?,?)')
     ->execute([hash('sha256', $naTok), $naUid, '', '', gmdate('Y-m-d H:i:s', time() + 3600)]);
$naCourse = (string) ($naDb->query('SELECT c.slug FROM courses c WHERE EXISTS (SELECT 1 FROM lessons l WHERE l.course_id = c.id) ORDER BY c.id LIMIT 1')->fetchColumn() ?: '');
if ($naCourse !== '') (new LmsRepository())->enrol($naUid, (int) $naDb->query('SELECT id FROM courses WHERE slug = ' . $naDb->quote($naCourse))->fetchColumn());

/* A Studio session: the break-glass token sign-in, bound to a token only this server knows. */
$naAdminToken = 'navacc-admin-token-' . bin2hex(random_bytes(8));
$naExp = time() + 3600;
$naPayload = $naExp . '.navacc.superadmin.t.0';
$naAdminCookie = $naPayload . '.' . av_mac('admin-cookie', $naPayload . '|' . substr(hash('sha256', 'admin-token-fp|' . $naAdminToken), 0, 16), av_secret());

$naPort = 0;
if ($probe = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2)) {
    $nm = (string) stream_socket_get_name($probe, false); $naPort = (int) substr($nm, strrpos($nm, ':') + 1); fclose($probe);
}
$naLog = tempnam(sys_get_temp_dir(), 'avna-log');
$naEnv = getenv() + ['AV_ADMIN_TOKEN' => $naAdminToken];
$naEnv['AV_ADMIN_TOKEN'] = $naAdminToken;
$naProc = $naPort > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $naPort, AV_ROOT . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $naLog, 'a'], 2 => ['file', $naLog, 'a']], $naPipes, AV_ROOT, $naEnv) : null;
$naUp = false;
if (is_resource($naProc)) {
    for ($i = 0; $i < 120 && !$naUp; $i++) { $c = @fsockopen('127.0.0.1', $naPort, $e1, $e2, 0.2); if ($c) { fclose($c); $naUp = true; } else usleep(50000); }
}
$naGet = static function (string $path, string $cookie = '') use ($naPort): array {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0,
        'header' => $cookie !== '' ? 'Cookie: ' . $cookie . "\r\n" : '']]);
    $raw = (string) @file_get_contents('http://127.0.0.1:' . $naPort . $path, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('~^HTTP/\S+ (\d{3})~', $h, $mm)) $code = (int) $mm[1];
    return [$code, $raw];
};

if (!$naUp) {
    echo "  (skipped the page sweep: could not start a loopback HTTP server)\n";
} else {
    $member = LmsAuth::COOKIE . '=' . $naTok;
    $pages = [
        // path, cookie, expectations
        ['/', '', []],
        ['/about.html', '', ['current' => ['/about.html' => 'page']]],
        ['/diary/', '', ['current' => ['/diary/' => 'page']]],
        ['/academy/', '', ['current' => ['/academy/' => 'page']]],
        ['/give/', '', ['current' => ['/give/' => 'page']]],
        ['/franchise', '', ['current' => ['/franchise' => 'page']]],
        ['/events/', '', []],
        ['/login', '', ['no_banner' => true]],   // a sign-in screen: no site nav, one task
        ['/mentorship/', '', []],
        ['/academy/teach/', $member, ['current' => ['/academy/teach/' => 'page']]],
        ['/portal/', $member, ['current' => ['Today' => 'page'], 'no_banner' => true]],
        ['/admin/', 'av_admin=' . $naAdminCookie, ['current' => ['overview' => 'page'], 'app_views' => true]],
        ['/academy/studio/', '', ['current' => ['overview' => 'page'], 'app_views' => true]],
    ];
    if ($naCourse !== '') {
        $pages[] = ['/academy/' . $naCourse . '/', '', []];
        $pages[] = ['/academy/' . $naCourse . '/learn/', $member, ['current' => ['/learn/' => 'page']]];
        $pages[] = ['/academy/' . $naCourse . '/grades/', $member, []];
        $pages[] = ['/academy/teach/' . $naCourse . '/', $member, ['current' => ['Learners' => 'page']]];
        $pages[] = ['/academy/teach/' . $naCourse . '/?tab=items', $member, ['current' => ['Graded items' => 'page']]];
        $pages[] = ['/academy/teach/' . $naCourse . '/?tab=gradebook', $member, ['current' => ['Gradebook' => 'page']]];
    } else {
        echo "  (no course with lessons in the test database: the Academy course pages were not swept)\n";
    }
    foreach ($pages as [$path, $cookie, $expect]) {
        [$code, $html] = $naGet($path, $cookie);
        ck("page $path: answers 200 (got $code)", $code === 200 && $html !== '');
        if ($code === 200 && $html !== '') navacc_page("page $path", $html, $expect);
    }
    // The portal's sidebar, its phone tab bar and the Studio's are app views switched in script: pinned in Chromium.
}
if (is_resource($naProc)) { proc_terminate($naProc); proc_close($naProc); }
@unlink($naLog);
$naDb->prepare('DELETE FROM lms_sessions WHERE user_id = ?')->execute([$naUid]);
$naDb->prepare('DELETE FROM lms_users WHERE id = ?')->execute([$naUid]);
