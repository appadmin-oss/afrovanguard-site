<?php
/**
 * tests/a11ypanel.test.php — the Accessibility panel, Listen, and the fixes
 * from the November 2026 audit (lib/A11y.php, assets/site/a11y.{css,js},
 * assets/site/listen.js). The behaviour is driven for real in Chromium by
 * tests/a11y/axe-ci.js; this pins the contract.
 */
declare(strict_types=1);
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/partials/avh-chrome.php';
$r = AV_ROOT;

/* ── The cookie: an allow-list, and garbage is ignored ───────────────── */
$p = A11y::parse('t150.spacing.font.hc-auto.links.focus.still.targets');
ck('a11y cookie: every allowed word is read', $p['text'] === 150 && $p['contrast'] === 'hc-auto'
   && $p['flags'] === ['spacing', 'font', 'links', 'focus', 'still', 'targets']);
ck('a11y cookie: "0" and empty mean standard', A11y::parse('0') === ['text' => 100, 'contrast' => '', 'flags' => []] && A11y::parse('') === A11y::parse(null));
ck('a11y cookie: unknown words are dropped, known ones kept', A11y::parse('t150.bogus.spacing.t300') === ['text' => 150, 'contrast' => '', 'flags' => ['spacing']]);
foreach (['<script>alert(1)</script>', 't150;path=/', 't150 spacing', 'T150.SPACING', "t150\nspacing", str_repeat('spacing.', 20), 'hc-auto"onload="x', '../../etc', 't150%20spacing'] as $junk)
    ck('a11y cookie: rejected whole — ' . json_encode($junk), A11y::parse($junk) === ['text' => 100, 'contrast' => '', 'flags' => []]);
ck('a11y cookie: a text size not offered is ignored', A11y::parse('t110')['text'] === 100 && A11y::parse('t999')['text'] === 100);
ck('a11y cookie: duplicates and order do not matter', A11y::serialize(A11y::parse('links.spacing.links.t200')) === 't200.spacing.links');
ck('a11y cookie: serialize round-trips', A11y::serialize(A11y::parse('t125.font.hc-dark')) === 't125.font.hc-dark' && A11y::serialize(A11y::parse('')) === '0');
ck('a11y cookie: serialize refuses what it was not given leave to', A11y::serialize(['text' => 300, 'contrast' => 'neon', 'flags' => ['spacing', 'x" onload']]) === 'spacing');
ck('a11y account pref: value and time are read apart', A11y::valueOf('t150.links@1732450000') === 't150.links' && A11y::stampOf('t150.links@1732450000') === 1732450000
   && A11y::valueOf('<b>@x') === '' && A11y::stampOf('t150') === 0);

/* ── The classes PHP puts on <html> ──────────────────────────────────── */
$withCookie = static function (?string $v): string {
    if ($v === null) unset($_COOKIE[A11y::COOKIE]); else $_COOKIE[A11y::COOKIE] = $v;
    A11y::resetForTest();
    return A11y::htmlClass('no-js');
};
ck('a11y html: the classes for the settings', $withCookie('t175.spacing.hc-light') === ' class="no-js a11y-t175 a11y-spacing a11y-hc-light a11y-hc"');
ck('a11y html: none for a garbage cookie', $withCookie('"><script>') === ' class="no-js"');
ck('a11y html: none with no cookie', $withCookie(null) === ' class="no-js"' && A11y::htmlClass() === '');
ck('a11y classes: exactly what the boot script would add', A11y::classes(A11y::parse('t200.still.hc-auto')) === 'a11y-t200 a11y-still a11y-hc-auto a11y-hc');

$_COOKIE[A11y::COOKIE] = 't150.links.hc-dark'; A11y::resetForTest();
ob_start(); render_head(['title' => 'T', 'canonical' => 'https://x.test/']); $head = (string) ob_get_clean();
ck('a11y render_head: <html> carries the classes server-side (works with JavaScript off)', str_contains($head, 'class="no-js a11y-t150 a11y-links a11y-hc-dark a11y-hc" data-theme="light"'));
ck('a11y render_head: the boot script runs before any stylesheet', strpos($head, A11y::BOOT) !== false && strpos($head, A11y::BOOT) < strpos($head, 'rel="stylesheet"'));
ck('a11y render_head: the panel stylesheet and script, cache-busted', (bool) preg_match('~/assets/site/a11y\.css\?v=[0-9a-f]{10}~', $head) && (bool) preg_match('~/assets/site/a11y\.js\?v=[0-9a-f]{10}" defer~', $head));
A11y::resetForTest();
ob_start(); render_head(['title' => 'T', 'canonical' => 'https://x.test/', 'chrome' => 'app']); $appHead = (string) ob_get_clean();
ck('a11y render_head: the app chrome (portal) gets them too', str_contains($appHead, '<html lang="en-NG" prefix="og: https://ogp.me/ns#" class="a11y-t150 a11y-links a11y-hc-dark a11y-hc">') && str_contains($appHead, 'a11y.js'));
av_chrome_mode('site');
unset($_COOKIE[A11y::COOKIE]); A11y::resetForTest();
ck('a11y boot: its allow-list is the PHP one', str_contains(A11y::BOOT, '(t125|t150|t175|t200|spacing|font|links|focus|still|targets|hc-auto|hc-light|hc-dark)')
   && A11y::TEXT === [100, 125, 150, 175, 200] && A11y::FLAGS === ['spacing', 'font', 'links', 'focus', 'still', 'targets']);
ck('a11y boot: it only writes a cookie value it has checked', str_contains(A11y::BOOT, "/^[a-z0-9.-]{1,80}$/.test(v)"));

/* ── The panel on every kind of page ─────────────────────────────────── */
$chrome = avh_chrome_html();
ck('a11y panel: the Home header has the control, at both widths, icon plus "Accessibility"',
   substr_count($chrome['nav'], 'data-a11y-open>') === 2 && substr_count($chrome['nav'], '<span class="av-a11y-btn-t">Accessibility</span>') === 2 && str_contains($chrome['nav'], 'class="av-a11y-ico"'));
ck('a11y panel: it sits in the same place on every page — before Sign in, and before the menu button', (bool) preg_match('~data-a11y-open>.*?</a>\s*<a class="avh-signin"~s', $chrome['nav'])
   && (bool) preg_match('~<div class="avh-narrow">\s*<a class="av-a11y-btn av-a11y-btn--compact"~', $chrome['nav']));
ck('a11y panel: without JavaScript the control is a link to the settings form', str_contains(A11y::button(), 'href="/accessibility/#settings"'));
ck('a11y panel: the Home footer links to it', str_contains($chrome['foot'], '>Accessibility settings</a>'));
foreach (['index.html', 'about.html', 'contact.html', 'donate.html', 'projects/index.html', '404.html', '500.html'] as $f) {
    $h = (string) file_get_contents("$r/$f");
    ck("a11y panel: $f has the boot script, the stylesheet and script, the header control and the footer link",
       str_contains($h, A11y::BOOT) && (bool) preg_match('~/assets/site/a11y\.css\?v=~', $h) && (bool) preg_match('~/assets/site/a11y\.js\?v=~', $h)
       && str_contains($h, 'data-a11y-open>') && str_contains($h, '>Accessibility settings</a>'));
}
$acc = render_page(AV_ROOT . '/accessibility/index.php');
ck('a11y panel: a main-site page (accessibility statement) renders it', str_contains($acc, 'a11y.js') && str_contains($acc, 'class="av-a11y-btn"') && str_contains($acc, '>Accessibility settings</a>'));
A11y::resetForTest();
$cat = render_page(AV_ROOT . '/academy/index.php');
ck('a11y panel: an Academy page renders it (head, header control, footer link)', str_contains($cat, 'a11y.js') && str_contains($cat, 'data-a11y-open>') && str_contains($cat, '>Accessibility settings</a>'));
A11y::resetForTest();
foreach (['academy/_avac.php' => 'avac_head', 'events/index.php' => '', 'ethos/index.php' => '', 'give/index.php' => '', 'how-it-works.php' => '', 'projects/_detail.php' => '', 'login/index.php' => '', 'constitution/index.php' => ''] as $f => $_)
    ck("a11y panel: $f loads it", str_contains((string) file_get_contents("$r/$f"), 'A11y::head()') || str_contains((string) file_get_contents("$r/$f"), 'render_head('));
$portal = (string) file_get_contents("$r/portal/index.php");
ck('a11y panel: the portal top bar has the control first, and its footer the link', (bool) preg_match('~<div class="avp-top-actions avn-top-actions">\s*<\?= A11y::button~', $portal) && str_contains($portal, 'A11y::footerLink()'));
ck('a11y panel: the portal is drawn with render_head (so the head and <html> classes)', str_contains($portal, "render_head([") && str_contains($portal, "'chrome'     => 'app'"));
ck('a11y panel: the lesson player header has the control', str_contains((string) file_get_contents("$r/academy/learn.php"), "<div class=\"avle-top-r\">\n    <?= A11y::button("));

/* The dialog, in script: native controls, focus in and back, Escape, the shortcut. */
$js = (string) file_get_contents("$r/assets/site/a11y.js");
ck('a11y dialog: a native <dialog> opened modal, labelled and described', str_contains($js, "el('dialog'") && str_contains($js, 'showModal()') && str_contains($js, "'aria-labelledby': 'av-a11y-h'") && str_contains($js, "'aria-describedby': 'av-a11y-d'"));
ck('a11y dialog: radios and checkboxes only', str_contains($js, 'type="radio" name="av-a11y-text"') && str_contains($js, 'type="radio" name="av-a11y-hc"') && str_contains($js, 'type="checkbox" name="av-a11y-'));
ck('a11y dialog: focus is kept inside and goes back to the opener', str_contains($js, "if (e.key !== 'Tab') return;") && str_contains($js, "dlg.addEventListener('close', restore)") && str_contains($js, 'o.focus()'));
ck('a11y dialog: Alt+Shift+A opens it (e.code for a Mac\'s Option key)', str_contains($js, "e.altKey && e.shiftKey && !e.ctrlKey && !e.metaKey && (e.code === 'KeyA'"));
ck('a11y dialog: Alt+Shift+A clashes with no other shortcut on the site', (function () use ($r): bool {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r, FilesystemIterator::SKIP_DOTS)) as $f) {
        $p = $f->getPathname();
        if (!str_ends_with($p, '.js') || preg_match('~/(vendor|node_modules|\.git)/|\.min\.js$|/a11y\.js$~', $p)) continue;
        if (preg_match('~altKey\s*&&\s*e\.shiftKey\s*&&\s*\(?\s*e\.(key|code)\s*===\s*.(A|a|KeyA).~', (string) file_get_contents($p))) return false;
    }
    return true;
})());
ck('a11y dialog: it says what it is, plainly, and claims nothing more', str_contains($js, 'These change how this site looks for you. Your own device’s settings, screen reader and magnifier work here too.')
   && !preg_match('~complian|ADA|certif|fully accessible~i', $js . file_get_contents("$r/assets/site/a11y.css")));
ck('a11y dialog: the shortcut is announced in the panel', str_contains($js, "<kbd>' + (mac ? 'Option' : 'Alt') + '</kbd> + <kbd>Shift</kbd> + <kbd>A</kbd>"));
$css = (string) file_get_contents("$r/assets/site/a11y.css");
ck('a11y css: text sizes scale rem from <html>', str_contains($css, 'html.a11y-t200{font-size:200%!important}') && str_contains($css, 'html.a11y-t125{font-size:125%!important}'));
ck('a11y css: comfortable spacing is the WCAG 1.4.12 values', str_contains($css, 'line-height:1.5!important;letter-spacing:.12em!important;word-spacing:.16em!important') && str_contains($css, 'margin-bottom:2em!important'));
ck('a11y css: high contrast has light and dark and follows the device', str_contains($css, 'html.a11y-hc-dark{--hc-bg:#000;--hc-fg:#fff') && str_contains($css, '@media (prefers-color-scheme:dark){html.a11y-hc-auto{'));
ck('a11y css: the font is self-hosted (font-src \'self\'), OFL licence alongside', str_contains($css, "src:url(fonts/AtkinsonHyperlegible-400.woff2)") && is_file("$r/assets/site/fonts/AtkinsonHyperlegible-400.woff2")
   && is_file("$r/assets/site/fonts/AtkinsonHyperlegible-700.woff2") && str_contains((string) file_get_contents("$r/assets/site/fonts/licenses/atkinson-hyperlegible-OFL.txt"), 'SIL Open Font License'));
ck('a11y css: bigger targets are 44px; still stops animation and the ticker', str_contains($css, 'min-height:44px!important') && str_contains($css, 'html.a11y-still .avh-tick-track{animation:none!important}'));
ck('a11y assets: small', filesize("$r/assets/site/a11y.js") < 20000 && filesize("$r/assets/site/a11y.css") < 22000 && filesize("$r/assets/site/listen.js") < 10000);

/* No-JS settings form on the statement, and the account store. */
ck('a11y statement: the settings form is there, with a token', str_contains($acc, 'id="settings"') && str_contains($acc, '<form class="a11y-form" method="post"') && (bool) preg_match('~name="csrf" value="[0-9]+\.[0-9a-f]+\.~', $acc));
$st = (string) file_get_contents("$r/accessibility/index.php");
ck('a11y statement: the form checks the token and the site', str_contains($st, "av_csrf_valid((string) (\$_POST['csrf'] ?? ''))") && str_contains($st, 'HTTP_SEC_FETCH_SITE'));
ck('a11y statement: reviewed 24 November 2026', str_contains($st, "\$reviewed  = '24 November 2026';"));
ck('a11y statement: the new features are listed, as conveniences', str_contains($st, '<strong>Change how this site looks for you</strong>') && str_contains($st, '<strong>Listen</strong>') && str_contains($st, 'they are a convenience, not a substitute'));
ck('a11y statement: nothing known was taken off the list', str_contains($st, '<strong>Some older videos</strong>') && str_contains($st, '<strong>Annual reports and other PDFs</strong>') && str_contains($st, '<strong>Things we embed from other companies</strong>') && str_contains($st, '<strong>Sign-in</strong>'));
$prefs = (string) file_get_contents("$r/portal/prefs.php");
ck('a11y account: prefs.php keeps it, allow-listed, behind the write guard', str_contains($prefs, "case 'set_a11y':") && str_contains($prefs, "av_require_write(\$uid, 'prefs', 60);") && str_contains($prefs, 'A11y::serialize(A11y::parse('));
Database::pdo()->prepare("INSERT INTO lms_users (name,email,password_hash) VALUES ('A11y Member','a11y.panel@x.test','x')")->execute();
$a11yUid = (int) Database::pdo()->query("SELECT id FROM lms_users WHERE email = 'a11y.panel@x.test'")->fetchColumn();
Prefs::set($a11yUid, A11y::PREF, 't200.font@' . time());
gt_sign_in_a11y($a11yUid);
unset($_COOKIE[A11y::COOKIE]); A11y::resetForTest();
ck('a11y account: a signed-in member with no cookie on this device gets the account\'s settings', A11y::current()['text'] === 200 && A11y::current()['flags'] === ['font'] && ($_COOKIE[A11y::COOKIE] ?? '') === 't200.font');
gt_sign_in_a11y(0); unset($_COOKIE[A11y::COOKIE]); A11y::resetForTest();

/* ── Listen ──────────────────────────────────────────────────────────── */
$ls = (string) file_get_contents("$r/assets/site/listen.js");
ck('listen: the browser\'s own speech, nothing sent anywhere', str_contains($ls, 'window.speechSynthesis') && !preg_match('~fetch\(|XMLHttpRequest|sendBeacon~', $ls));
ck('listen: hidden unless the browser can speak; never starts by itself', str_contains(A11y::listen('#x'), ' hidden></div>') && str_contains($ls, "if (!boxes.length || !synth || typeof window.SpeechSynthesisUtterance !== 'function') return;") && !str_contains($ls, 'start();' . "\n  });"));
ck('listen: play/pause, stop, speed; Nigerian then British English', str_contains($ls, 'data-act="play"') && str_contains($ls, 'data-act="stop"') && str_contains($ls, 'data-act="rate"') && str_contains($ls, "by(/^en-NG/i) || by(/^en-GB/i)"));
ck('listen: skips navigation, footers and hidden text; highlights and scrolls without motion when asked', str_contains($ls, "nav,footer,aside") && str_contains($ls, '[aria-hidden="true"]') && str_contains($ls, "av-listen-on") && str_contains($ls, "behavior: reduce() ? 'auto' : 'smooth'"));
ck('listen: a polite status', str_contains($ls, 'role="status" aria-live="polite"'));
foreach (['diary/article.php' => 'article.avd-article', 'academy/learn.php' => '.avle-main .avle-body', 'constitution/index.php' => '.const-doc', 'projects/_detail.php' => '#main', 'accessibility/index.php' => '.legal-content'] as $f => $sel)
    ck("listen: on $f", str_contains((string) file_get_contents("$r/$f"), "A11y::listen('$sel'"));
ck('listen: the statement page renders the control', str_contains($acc, 'data-av-listen=".legal-content"') && str_contains($acc, '/assets/site/listen.js?v='));

/* ── Fixes from the audit ────────────────────────────────────────────── */
$home = (string) file_get_contents("$r/index.html");
ck('ticker: a Pause/Play button (WCAG 2.2.2)', str_contains($home, '<button type="button" class="avh-tick-pause" data-avh-tick-toggle hidden>') && str_contains($home, '<span data-avh-tick-label>Pause</span>'));
ck('ticker: the strip is focusable and named, for when it scrolls (WCAG 2.1.1)', str_contains($home, '<div class="avh-tick-view" role="region" aria-label="Movement highlights" tabindex="0">'));
$avhJs = (string) file_get_contents("$r/assets/site/avh.js");
ck('ticker: the choice is remembered', str_contains($avhJs, "localStorage.setItem('av.ticker', paused ? 'paused' : 'playing')") && str_contains($avhJs, "localStorage.getItem('av.ticker')"));
ck('motion: the panel\'s "stop moving content" counts as reduce motion for the slideshow', str_contains($avhJs, "document.documentElement.classList.contains('a11y-still')") && str_contains($avhJs, "document.addEventListener('av:a11y', fire)"));
ck('portal: the closed notebook drawer is display:none (focus never under the sidebar, WCAG 2.4.11)', str_contains((string) file_get_contents("$r/portal/avdy.css"), 'visibility: hidden; display: none; transition:'));
ck('portal: focus stops clear of the sticky top bar', str_contains((string) file_get_contents("$r/portal/avp.css"), 'html { scroll-padding-top: 84px; }'));
ck('landmarks: no complementary landmark inside main on Contact or Community', !str_contains((string) file_get_contents("$r/contact.html"), '<aside class="avct-lines"') && !str_contains((string) file_get_contents("$r/lib/community_view.php"), '<aside'));
ck('headings: Community\'s "Behind the movement" is an h2 (no skipped level)', str_contains((string) file_get_contents("$r/lib/community_view.php"), '<h2 class="cm-event-h">Behind the movement</h2>'));
ck('landmarks: Membership\'s card and view have different names', str_contains((string) file_get_contents("$r/portal/views/membership.php"), "'Membership details' : 'Account details'"));
$axe = (string) file_get_contents("$r/tests/a11y/axe-ci.js");
ck('CI: the constitution and the portal views are covered, as a member', str_contains($axe, "const MEMBER = ['/constitution/'].concat(PORTAL_VIEWS") && str_contains((string) file_get_contents("$r/.github/workflows/ci.yml"), 'ci-session.php --member'));
ck('CI: and again with high contrast, 200% text and spacing', str_contains($axe, "const SETTINGS = ['t200.spacing.hc-light', 't200.spacing.hc-dark'];"));

/** Sign a user in for A11y::current() (LmsAuth memoises per request). 0 signs out. */
function gt_sign_in_a11y(int $uid): void
{
    if ($uid > 0) {
        $tok = substr(hash('sha256', 'a11y' . $uid . microtime()), 0, 40);
        Database::pdo()->prepare('INSERT INTO lms_sessions (user_id, token_hash, expires_at) VALUES (?,?,?)')
            ->execute([$uid, hash('sha256', $tok), gmdate('Y-m-d H:i:s', time() + 3600)]);
        $_COOKIE[LmsAuth::COOKIE] = $tok;
    } else {
        unset($_COOKIE[LmsAuth::COOKIE]);
    }
    (new ReflectionProperty('LmsAuth', 'checked'))->setValue(null, false);
    (new ReflectionProperty('LmsAuth', 'cache'))->setValue(null, null);
}
