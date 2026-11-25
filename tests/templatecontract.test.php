<?php
/**
 * tests/templatecontract.test.php — every page template keeps the same
 * accessibility contract (docs/accessibility/AUDIT-2026-11-25.md).
 *
 * The site is drawn by many templates written in different rounds: the Home
 * chrome (PHP and static .html), the policy pages, the Academy chrome, the
 * lesson player and the learner's pages, the member portal, the constitution,
 * sign-in, the error pages and the offline page. A screen reader user moves
 * between all of them, so each one is rendered for real — through router.php
 * on a loopback server sharing the suite's database, signed out, as a learner
 * and as a member — or read from disk (the static pages), and held to:
 *
 *   - <html lang> is English (en, en-NG);
 *   - exactly one <h1> and exactly one <main> showing;
 *   - a banner (top-level <header>) and a contentinfo (top-level <footer>),
 *     except on the single-task screens named below;
 *   - the first thing Tab reaches is "Skip to main content", and its target
 *     exists, is focusable and is (or is in) the main;
 *   - the <title> is "Page — Section — Afrovanguard" (A11y::title());
 *   - a breadcrumb is <nav aria-label="Breadcrumb"><ol>…, ending on the
 *     current page (aria-current="page");
 *   - no positive tabindex;
 *   - every <img> has an alt attribute;
 *   - every button and link has a name, icon-only ones included.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/partials/avh-chrome.php';

function tc_dom(string $html): DOMXPath
{
    $d = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    return new DOMXPath($d);
}
function tc_hidden(DOMElement $el): bool
{
    for ($n = $el; $n instanceof DOMElement; $n = $n->parentNode) {
        if ($n->hasAttribute('hidden') || $n->getAttribute('aria-hidden') === 'true' || $n->hasAttribute('inert')) return true;
        if (in_array($n->tagName, ['template', 'noscript', 'dialog'], true) && !$n->hasAttribute('open')) return true;
    }
    return false;
}
function tc_name(DOMXPath $x, DOMElement $el): string
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
    if ($text === '' && $el->tagName === 'input') $text = trim($el->getAttribute('value'));
    return $text !== '' ? $text : trim($el->getAttribute('title'));
}

/** The whole contract, for one rendered page. $o: single_task (no banner/contentinfo needed). */
function tc_contract(string $label, string $html, array $o = []): void
{
    $x = tc_dom($html);
    $root = $x->query('//html')->item(0);
    $lang = $root instanceof DOMElement ? $root->getAttribute('lang') : '';
    ck("$label: <html lang> is English (got \"$lang\")", (bool) preg_match('/^en(-[A-Z]{2})?$/', $lang));

    $h1 = array_filter(iterator_to_array($x->query('//body//h1')), static fn(DOMElement $h) => !tc_hidden($h));
    ck("$label: exactly one <h1> (got " . count($h1) . ')', count($h1) === 1);
    $mains = array_filter(iterator_to_array($x->query('//main | //*[@role="main"]')), static fn(DOMElement $m) => !tc_hidden($m));
    ck("$label: exactly one <main> (got " . count($mains) . ')', count($mains) === 1);
    $top = 'not(ancestor::main or ancestor::article or ancestor::aside or ancestor::section or ancestor::nav or ancestor::*[@role="main"])';
    if (empty($o['single_task'])) {
        ck("$label: a banner landmark (top-level <header>)", $x->query("//body//header[$top] | //*[@role=\"banner\"]")->length >= 1);
        ck("$label: a contentinfo landmark (top-level <footer>)", $x->query("//body//footer[$top] | //*[@role=\"contentinfo\"]")->length >= 1);
    }

    $first = null;
    foreach ($x->query('//body//*[self::a[@href] or self::button or self::input[not(@type="hidden")] or self::select or self::textarea or @tabindex]') as $el) {
        /** @var DOMElement $el */
        if ($el->getAttribute('tabindex') === '-1' || tc_hidden($el)) continue;
        $first = $el; break;
    }
    $href = $first ? $first->getAttribute('href') : '';
    ck("$label: the first thing Tab reaches is \"Skip to main content\" (got <" . ($first?->tagName ?? 'nothing') . '> "' . ($first ? tc_name($x, $first) : '') . '")',
        $first !== null && $first->tagName === 'a' && str_starts_with($href, '#') && strcasecmp(tc_name($x, $first), 'Skip to main content') === 0);
    $target = $href !== '' && $href !== '#' ? $x->query('//*[@id="' . substr($href, 1) . '"]')->item(0) : null;
    ck("$label: …its target $href exists, is focusable, and is (or is in) the main", $target instanceof DOMElement
        && $target->getAttribute('tabindex') === '-1'
        && ($target->tagName === 'main' || $target->getAttribute('role') === 'main' || $x->query('ancestor::main', $target)->length === 1));

    $title = trim((string) ($x->query('//head/title')->item(0)?->textContent ?? ''));
    ck("$label: the title is \"Page — Section — Afrovanguard\" (got \"$title\")",
        (bool) preg_match('/^[^|·]+ — Afrovanguard$/u', $title) && !str_contains($title, ' | '));

    foreach ($x->query('//nav[contains(translate(@aria-label,"B","b"),"breadcrumb")] | //nav[.//ol[contains(@class,"crumbs")]]') as $bc) {
        /** @var DOMElement $bc */
        ck("$label: a breadcrumb nav is named \"Breadcrumb\"", $bc->getAttribute('aria-label') === 'Breadcrumb');
        $ol = $x->query('.//ol', $bc)->item(0);
        $items = $ol ? $x->query('./li', $ol) : null;
        $last = $items && $items->length ? $items->item($items->length - 1) : null;
        ck("$label: …an ordered list whose last item is the current page", $last instanceof DOMElement && $x->query('.//*[@aria-current="page"] | self::*[@aria-current="page"]', $last)->length >= 1);
    }

    $pos = 0; foreach ($x->query('//*[@tabindex]') as $t) if ((int) $t->getAttribute('tabindex') > 0) $pos++;
    ck("$label: no positive tabindex (got $pos)", $pos === 0);
    $noAlt = $x->query('//body//img[not(@alt)]')->length;
    ck("$label: every <img> has an alt attribute ($noAlt without)", $noAlt === 0);
    $nameless = [];
    foreach ($x->query('//body//a[@href] | //body//button | //body//*[@role="button"]') as $c) {
        /** @var DOMElement $c */
        if (tc_hidden($c)) continue;
        if (tc_name($x, $c) === '') $nameless[] = '<' . $c->tagName . ' class="' . $c->getAttribute('class') . '">';
    }
    ck("$label: every button and link has a name (" . implode(' ', array_slice($nameless, 0, 3)) . ')', $nameless === []);
}

/* ── The title pattern itself ─────────────────────────────────────────── */
ck('title: a strapline after | is dropped', A11y::title('About — Afrovanguard | Raising 1 Million Incorruptible Leaders') === 'About — Afrovanguard');
ck('title: "Afrovanguard Academy" is the section Academy, at the end comes the site', A11y::title('Grades — Techome · Afrovanguard Academy') === 'Grades — Techome — Academy — Afrovanguard');
ck('title: " · Afrovanguard" becomes " — Afrovanguard"', A11y::title('Give — live appeals · Afrovanguard') === 'Give — live appeals — Afrovanguard');
ck('title: a bare page name gains the site', A11y::title('Accessibility') === 'Accessibility — Afrovanguard');
ck('title: the site is never named twice', A11y::title('Afrovanguard — Afrovanguard') === 'Afrovanguard');

/* ── The static pages, from disk ─────────────────────────────────────── */
foreach (['index.html', 'about.html', 'contact.html', 'donate.html', 'projects/index.html', '403.html', '404.html', '429.html', '500.html', '503.html'] as $f) {
    tc_contract("static $f", (string) file_get_contents(AV_ROOT . '/' . $f));
}
tc_contract('static assets/site/offline.html', (string) file_get_contents(AV_ROOT . '/assets/site/offline.html'), ['single_task' => true]);

/* ── The PHP templates, through the router ───────────────────────────── */
$tcDb = Database::pdo();
$tcDb->exec("DELETE FROM lms_users WHERE email LIKE '%@tcontract.test'");
$tcMk = static function (string $name, string $email, string $role) use ($tcDb): array {
    $tcDb->prepare("INSERT INTO lms_users (name, email, password_hash, role, status, email_verified) VALUES (?,?,?,?,?,1)")->execute([$name, $email, 'x', $role, 'active']);
    $uid = (int) $tcDb->lastInsertId();
    $tok = bin2hex(random_bytes(20));
    $tcDb->prepare('INSERT INTO lms_sessions (token_hash, user_id, ip, ua, expires_at) VALUES (?,?,?,?,?)')->execute([hash('sha256', $tok), $uid, '', '', gmdate('Y-m-d H:i:s', time() + 3600)]);
    return [$uid, LmsAuth::COOKIE . '=' . $tok];
};
[$tcLearnerId, $tcLearner] = $tcMk('Lara Learner', 'lara@tcontract.test', 'learner');
[$tcMemberId, $tcMember] = $tcMk('Mo Member', 'mo@tcontract.test', 'member');
$tcCourse = (string) ($tcDb->query('SELECT c.slug FROM courses c WHERE EXISTS (SELECT 1 FROM lessons l WHERE l.course_id = c.id) ORDER BY c.id LIMIT 1')->fetchColumn() ?: '');
$tcLesson = '';
if ($tcCourse !== '') {
    $cid = (int) $tcDb->query('SELECT id FROM courses WHERE slug = ' . $tcDb->quote($tcCourse))->fetchColumn();
    (new LmsRepository())->enrol($tcLearnerId, $cid);
    $tcLesson = (string) ($tcDb->query('SELECT slug FROM lessons WHERE course_id = ' . $cid . ' ORDER BY id LIMIT 1')->fetchColumn() ?: '');
}

$tcPort = 0;
if ($probe = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2)) {
    $nm = (string) stream_socket_get_name($probe, false); $tcPort = (int) substr($nm, strrpos($nm, ':') + 1); fclose($probe);
}
$tcLog = tempnam(sys_get_temp_dir(), 'avtc-log');
$tcProc = $tcPort > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $tcPort, AV_ROOT . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $tcLog, 'a'], 2 => ['file', $tcLog, 'a']], $tcPipes, AV_ROOT, getenv()) : null;
$tcUp = false;
if (is_resource($tcProc)) {
    for ($i = 0; $i < 120 && !$tcUp; $i++) { $c = @fsockopen('127.0.0.1', $tcPort, $e1, $e2, 0.2); if ($c) { fclose($c); $tcUp = true; } else usleep(50000); }
}
$tcGet = static function (string $path, string $cookie = '') use ($tcPort): array {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0,
        'header' => "Accept: text/html\r\n" . 'Cookie: av_consent=analytics%3D0' . ($cookie !== '' ? '; ' . $cookie : '') . "\r\n"]]);
    $raw = (string) @file_get_contents('http://127.0.0.1:' . $tcPort . $path, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('~^HTTP/\S+ (\d{3})~', $h, $mm)) $code = (int) $mm[1];
    return [$code, $raw];
};

if (!$tcUp) {
    echo "  (skipped the template sweep: could not start a loopback HTTP server)\n";
} else {
    /* [family, path, cookie, options] — one or more pages of each template. */
    $tcPages = [
        ['home chrome (PHP)', '/people/', '', []],
        ['home chrome (PHP)', '/ethos/', '', []],
        ['home chrome (PHP)', '/diary/', '', []],
        ['home chrome (PHP)', '/give/', '', []],
        ['home chrome (PHP)', '/IQ/', '', []],
        ['home chrome (PHP)', '/search.php?q=tech', '', []],
        ['policy pages', '/accessibility/', '', []],
        ['policy pages', '/terms/', '', []],
        ['policy pages', '/privacy-policy/', '', []],
        ['academy chrome', '/academy/', '', []],
        ['academy chrome', '/academy/programmes/', '', []],
        ['academy chrome', '/academy/verify.php', '', []],
        ['sign-in', '/login', '', ['single_task' => true]],
        ['error page (PHP)', '/no-such-page-for-the-contract', '', []],
        ['member portal', '/portal/', $tcMember, []],
        ['constitution', '/constitution/', $tcMember, []],
        ['learner pages', '/academy/my', $tcLearner, []],
    ];
    if ($tcCourse !== '') {
        $tcPages[] = ['academy chrome', '/academy/' . $tcCourse . '/', '', []];
        $tcPages[] = ['learner pages', '/academy/' . $tcCourse . '/grades/', $tcLearner, []];
        if ($tcLesson !== '') $tcPages[] = ['lesson player', '/academy/' . $tcCourse . '/learn/' . $tcLesson, $tcLearner, []];
    } else {
        echo "  (no course with lessons in the test database: the lesson player was not swept)\n";
    }
    foreach ($tcPages as [$fam, $path, $cookie, $o]) {
        [$code, $html] = $tcGet($path, $cookie);
        $okCode = $code === 200 || ($code === 404 && str_starts_with($fam, 'error'));
        ck("template $fam $path: answers (got $code)", $okCode && $html !== '');
        if ($okCode && $html !== '') tc_contract("template $fam $path", $html, $o);
    }
}
if (is_resource($tcProc)) { proc_terminate($tcProc); proc_close($tcProc); }
@unlink($tcLog);
foreach ([$tcLearnerId, $tcMemberId] as $uid) {
    $tcDb->prepare('DELETE FROM lms_sessions WHERE user_id = ?')->execute([$uid]);
    try { $tcDb->prepare('DELETE FROM course_enrolment WHERE user_id = ?')->execute([$uid]); } catch (Throwable $e) {}
    $tcDb->prepare('DELETE FROM lms_users WHERE id = ?')->execute([$uid]);
}
