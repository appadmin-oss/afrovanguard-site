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
$p = A11y::parse('t150.spacing.font.hc-auto.links.focus.still.targets.nokeys');
ck('a11y cookie: every allowed word is read', $p['text'] === 150 && $p['contrast'] === 'hc-auto'
   && $p['flags'] === ['spacing', 'font', 'links', 'focus', 'targets', 'still', 'nokeys']);
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
ck('a11y boot: its allow-list is the PHP one', str_contains(A11y::BOOT, '(t125|t150|t175|t200|spacing|font|links|focus|targets|still|nokeys|hc-auto|hc-light|hc-dark)')
   && A11y::TEXT === [100, 125, 150, 175, 200] && A11y::FLAGS === ['spacing', 'font', 'links', 'focus', 'targets', 'still', 'nokeys']);
ck('a11y keys: the panel offers turning single-key shortcuts off (WCAG 2.1.4), and the search obeys it', str_contains((string) file_get_contents("$r/assets/site/a11y.js"), "['nokeys', 'Single-key shortcuts'") && str_contains((string) file_get_contents("$r/assets/site/avsearch.js"), "if (e.key === '/' && singleKeys() &&"));
ck('a11y boot: it only writes a cookie value it has checked', str_contains(A11y::BOOT, "/^[a-z0-9.-]{1,80}$/.test(v)"));

/* ── The way in on every kind of page: a round button, the footer link, the shortcut ── */
$chrome = avh_chrome_html();
ck('a11y fab: the header no longer carries an Accessibility pill (it is the round button now)', !str_contains($chrome['nav'], 'data-a11y-open') && !str_contains($chrome['nav'], 'av-a11y-btn'));
ck('a11y fab: there is no header-button helper left to bring it back', !method_exists('A11y', 'button'));
ck('a11y panel: the Home footer links to it', str_contains($chrome['foot'], '>Accessibility settings</a>') && str_contains(A11y::footerLink(), 'href="/accessibility/#settings"'));
foreach (['index.html', 'about.html', 'contact.html', 'donate.html', 'projects/index.html', '404.html', '500.html'] as $f) {
    $h = (string) file_get_contents("$r/$f");
    ck("a11y panel: $f has the boot script, the stylesheet and script, and the footer link (the script draws the button)",
       str_contains($h, A11y::BOOT) && (bool) preg_match('~/assets/site/a11y\.css\?v=~', $h) && (bool) preg_match('~/assets/site/a11y\.js\?v=~', $h)
       && str_contains($h, '>Accessibility settings</a>') && !str_contains($h, 'av-a11y-btn'));
}
$acc = render_page(AV_ROOT . '/accessibility/index.php');
ck('a11y panel: a main-site page (accessibility statement) loads it and has the footer link', str_contains($acc, 'a11y.js') && str_contains($acc, '>Accessibility settings</a>'));
A11y::resetForTest();
$cat = render_page(AV_ROOT . '/academy/index.php');
ck('a11y panel: an Academy page loads it (head, footer link)', str_contains($cat, 'a11y.js') && str_contains($cat, '>Accessibility settings</a>'));
A11y::resetForTest();
foreach (['academy/_avac.php' => 'avac_head', 'events/index.php' => '', 'ethos/index.php' => '', 'give/index.php' => '', 'how-it-works.php' => '', 'projects/_detail.php' => '', 'login/index.php' => '', 'constitution/index.php' => ''] as $f => $_)
    ck("a11y panel: $f loads it", str_contains((string) file_get_contents("$r/$f"), 'A11y::head()') || str_contains((string) file_get_contents("$r/$f"), 'render_head('));
$portal = (string) file_get_contents("$r/portal/index.php");
ck('a11y panel: the portal footer has the link', str_contains($portal, 'A11y::footerLink()'));
ck('a11y panel: the portal is drawn with render_head (so the head and <html> classes)', str_contains($portal, "render_head([") && str_contains($portal, "'chrome'     => 'app'"));
ck('a11y fab: on the portal\'s wide screen it moves past the sidebar; its phone tab bar is a measured bottom bar',
   str_contains((string) file_get_contents("$r/portal/avp.css"), '@media (min-width: 960px) { :root { --av-fab-left: calc(264px + 16px); } }')
   && str_contains($portal, '<nav class="avp-tabs avn-tabs" aria-label="Primary" data-bottom-ui>'));

/* The button and the panel, in script. */
$js = (string) file_get_contents("$r/assets/site/a11y.js");
$core = (string) file_get_contents("$r/assets/site/a11y-core.js");
ck('a11y fab: a real button, named, saying it opens a dialog and its shortcut', str_contains($js, "el('button', { type: 'button', 'class': 'av-a11y-fab', 'data-a11y-open': '', 'aria-label': 'Accessibility settings', 'aria-haspopup': 'dialog', 'aria-expanded': 'false', 'aria-controls': 'av-a11y', 'aria-keyshortcuts': 'Alt+Shift+A' }"));
ck('a11y fab: its visible label is a tooltip that Escape dismisses (WCAG 1.4.13)', str_contains($js, '<span class="av-a11y-tip" aria-hidden="true">Accessibility settings') && str_contains($js, "if (e.key === 'Escape') fab.classList.add('is-quiet');"));
/* Bottom clearance: one measured variable (assets/site/bottom-ui.js), after africa-gates' Gee. */
$bu = (string) file_get_contents("$r/assets/site/bottom-ui.js");
ck('bottom ui: the figure is the highest top edge of the visible bars, not the tallest height', str_contains($bu, "h = Math.max(h, Math.round(vh - r.top));") && !str_contains($bu, 'r.height)'));
ck('bottom ui: visible means getClientRects(), never offsetParent (null for every fixed bar)', str_contains($bu, 'if (!el.getClientRects().length) continue;') && !preg_match('~offsetParent\s*[!=]==~', $bu));
ck('bottom ui: mount, unmount, size and resize are all seen', str_contains($bu, "subtree: true, childList: true") && str_contains($bu, "attributeFilter: ['hidden', 'class', 'style', 'data-open', 'data-bottom-ui']") && str_contains($bu, 'new ResizeObserver(set)') && str_contains($bu, "window.addEventListener('resize', set"));
ck('bottom ui: it writes only when the figure changes (its own write would re-trigger it)', str_contains($bu, "if (v === last) return;") && str_contains($bu, "document.body.style.setProperty('--av-bottom-ui', v);"));
ck('bottom ui: every page loads it, before the panel', (bool) preg_match('~bottom-ui\.js[^\n]*\n[^\n]*a11y\.css~', A11y::head() . '') || str_contains((string) file_get_contents("$r/lib/A11y.php"), "av_asset('/assets/site/bottom-ui.js')"));
foreach (['assets/site/ga.js' => "box.setAttribute('data-bottom-ui', '')", 'portal/index.php' => 'class="avp-tabs avn-tabs" aria-label="Primary" data-bottom-ui', 'mentorship/mentor/views/_tabbar.php' => '<nav class="avm-tabbar" aria-label="Sections" data-bottom-ui>', 'academy/learn.php' => '<div class="avle-bar-wrap" data-bottom-ui>', 'give/appeal.php' => '<div class="give-sticky" data-bottom-ui>'] as $f => $needle)
    ck("bottom ui: $f marks its bottom bar", str_contains((string) file_get_contents("$r/$f"), $needle));
foreach (['index.html', 'about.html', '404.html'] as $f) ck("bottom ui: $f loads it", (bool) preg_match('~/assets/site/bottom-ui\.js\?v=[0-9a-f]{10}" defer~', (string) file_get_contents("$r/$f")));
ck('bottom ui: Chioma\'s launcher clears the same bars, so the two corners never collide', str_contains((string) file_get_contents("$r/assets/site/chioma.css"), 'bottom: calc(var(--av-bottom-ui, 0px) + 16px + env(safe-area-inset-bottom));'));
/* Tuck on scroll, and never over a control: one shared module (launcher-tuck.js), used by both launchers. */
$tk = (string) file_get_contents("$r/assets/site/launcher-tuck.js");
$chJs = (string) file_get_contents("$r/assets/site/chioma.js");
$chCss = (string) file_get_contents("$r/assets/site/chioma.css");
ck('launcher tuck: reading down past 80px tucks it; scrolling up brings it back', str_contains($tk, 'reading = dy > 0 && y > 80;') && str_contains($tk, "if ((reading || blocked) && document.activeElement !== fab && !held()) {") && str_contains($tk, "fab.setAttribute('data-tuck', '');"));
ck('launcher tuck: from 600px it returns ~900ms after scrolling stops; on a phone it waits for a scroll up', str_contains($tk, "if (!(phone && phone.matches && y > 80)) reading = false;") && str_contains($tk, '}, 900);') && str_contains($tk, "var PHONE = '(max-width: 599px)';"));
ck('launcher tuck: never tucked while it has focus, nor while the caller holds it', str_contains($tk, "fab.addEventListener('focus', function () { fab.removeAttribute('data-tuck'); });") && str_contains($tk, 'return !!(opts.hold && opts.hold());'));
ck('launcher tuck: nine points of its resting box, any control under one keeps it tucked (ignoring every launcher and what the caller names)',
   str_contains($tk, 'var xs = [b.x + 3, b.x + b.w / 2, b.x + b.w - 3], ys = [b.y + 3, b.y + b.h / 2, b.y + b.h - 3];') && str_contains($tk, 'document.elementsFromPoint(xs[i], ys[j])')
   && str_contains($tk, "var CONTROL = 'a[href], button, input, select, textarea, summary, label, [role=\"button\"], [role=\"link\"], [tabindex]:not([tabindex=\"-1\"])';")
   && str_contains($tk, "e.closest('[data-av-launcher]') || (opts.ignore && opts.ignore(e))") && str_contains($tk, 'while (e) { x += e.offsetLeft; y += e.offsetTop; e = e.offsetParent; }'));
ck('launcher tuck: it looks on load, resize, the load event and again at 1.6s', str_contains($tk, "window.addEventListener('load', look)") && str_contains($tk, 'setTimeout(look, 1600);') && str_contains($tk, 'requestAnimationFrame(look)'));
ck('launcher tuck: the Accessibility button uses the shared module (its panel ignored), with no copy of its own',
   str_contains($js, "tuck = window.AvTuck(fab, { ignore: function (e) { return !!(dlg && dlg.contains(e)); } });") && !str_contains($js, 'elementsFromPoint') && !str_contains($js, 'reading = dy > 0'));
ck('launcher tuck: Chioma\'s launcher uses the same module: never while its panel is open, its nudge goes with it, no copy of its own',
   str_contains($chJs, "var tuck = window.AvTuck ? window.AvTuck(fab, {") && str_contains($chJs, 'scope: root,') && str_contains($chJs, 'hold: function () { return open; },')
   && str_contains($chJs, 'onTuck: hideGreet') && str_contains($chJs, 'if (tuck) tuck.paint();') && !str_contains($chJs, 'elementsFromPoint') && !str_contains($chJs, 'reading = dy > 0'));
ck('launcher tuck: Chioma\'s nudge waits while the launcher has stepped aside', str_contains($chJs, "if (tucked()) { schedule(8000); return; }") && str_contains($chJs, "if (tucked()) { bubbleTimer = setTimeout(first, 4000); return; }"));
ck('launcher tuck: Chioma\'s launcher slides off its own (inline-end) edge, faded, mirrored for RTL, back on focus, no slide with reduced motion or Stop moving content, never while open',
   str_contains($chCss, '.chioma-root:not(.is-open) .chioma-fab[data-tuck] { transform: translateX(calc(100% + 24px)); opacity: 0; pointer-events: none; }')
   && str_contains($chCss, '[dir="rtl"] .chioma-root:not(.is-open) .chioma-fab[data-tuck] { transform: translateX(calc(-100% - 24px)); }')
   && str_contains($chCss, '.chioma-root:not(.is-open) .chioma-fab[data-tuck]:focus-visible { transform: none; opacity: 1; pointer-events: auto; }')
   && str_contains($chCss, '@media (prefers-reduced-motion: reduce) { .chioma-root:not(.is-open) .chioma-fab[data-tuck] { transform: none; } }')
   && str_contains($chCss, 'html.a11y-still .chioma-root:not(.is-open) .chioma-fab[data-tuck] { transform: none; }')
   && str_contains($chCss, '@media print { .chioma-root { display: none !important; } }') && str_contains($chCss, 'inset-inline-end: max(16px, env(safe-area-inset-right));'));
ck('launcher tuck: every page loads the module before either launcher (A11y head, error pages, static pages)',
   str_contains((string) file_get_contents("$r/lib/A11y.php"), "av_asset('/assets/site/launcher-tuck.js')")
   && strpos((string) file_get_contents("$r/lib/A11y.php"), 'launcher-tuck.js') < strpos((string) file_get_contents("$r/lib/A11y.php"), "av_asset('/assets/site/a11y.js')")
   && str_contains((string) file_get_contents("$r/lib/errors.php"), "av_err_asset('/assets/site/launcher-tuck.js')"));
foreach (['index.html', 'about.html', 'contact.html', 'donate.html', 'projects/index.html', '404.html', '500.html'] as $f) {
    $src = (string) file_get_contents("$r/$f");
    ck("launcher tuck: $f loads it before a11y.js and chioma.js", (bool) preg_match('~/assets/site/launcher-tuck\.js\?v=[0-9a-f]{10}" defer~', $src)
       && strpos($src, 'launcher-tuck.js') < strpos($src, '/assets/site/a11y.js') && (!str_contains($src, 'chioma.js') || strpos($src, 'launcher-tuck.js') < strpos($src, 'chioma.js')));
}
ck('launcher tuck: small', filesize("$r/assets/site/launcher-tuck.js") < 6000);
ck('a11y sheet: on a phone it follows the visual viewport', str_contains($js, "dlg.style.setProperty('--av-vv-h', Math.round(vv.height) + 'px');") && str_contains($js, "vv.addEventListener('resize', fit)"));
ck('a11y dialog: a native <dialog> opened modal through the shared core, labelled and described', str_contains($js, "el('dialog'") && str_contains($js, 'A.dialog(dlg, {') && str_contains($core, 'dlg.showModal();') && str_contains($js, "'aria-labelledby': 'av-a11y-h'") && str_contains($js, "'aria-describedby': 'av-a11y-d'"));
ck('a11y dialog: native radios for size and contrast (a segmented control), checkboxes with role="switch"', str_contains($js, 'type="radio" name="av-a11y-text"') && str_contains($js, 'type="radio" name="av-a11y-hc"') && str_contains($js, '<input type="checkbox" role="switch" name="\' + id + \'" value="\' + f[0] + \'" aria-labelledby="\' + id + \'-l" aria-describedby="\' + id + \'-d"') && str_contains($js, 'class="av-seg"'));
ck('a11y dialog: focus is kept inside and goes back to the opener (a11y-core.js)', str_contains($core, "if (e.key !== 'Tab') return;") && str_contains($core, "dlg.addEventListener('close', function () {") && str_contains($core, 'el.focus({ preventScroll: true })') && str_contains($js, 'fallback: function () { return fab; }'));
ck('a11y dialog: Alt+Shift+A opens it (e.code for a Mac\'s Option key)', str_contains($js, "e.altKey && e.shiftKey && !e.ctrlKey && !e.metaKey && (e.code === 'KeyA'"));
ck('a11y dialog: Alt+Shift+A clashes with no other shortcut on the site', (function () use ($r): bool {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r, FilesystemIterator::SKIP_DOTS)) as $f) {
        $p = $f->getPathname();
        if (!str_ends_with($p, '.js') || preg_match('~/(vendor|node_modules|\.git)/|\.min\.js$|/a11y\.js$~', $p)) continue;
        if (preg_match('~altKey\s*&&\s*e\.shiftKey\s*&&\s*\(?\s*e\.(key|code)\s*===\s*.(A|a|KeyA).~', (string) file_get_contents($p))) return false;
    }
    return true;
})());
ck('a11y dialog: it says what it is, plainly, and claims nothing more', str_contains($js, 'These change how this site looks for you. Your device’s own settings, screen reader and magnifier keep working.')
   && !preg_match('~complian|ADA|certif|fully accessible~i', $js . file_get_contents("$r/assets/site/a11y.css")));
ck('a11y dialog: the shortcut is shown in the panel', str_contains($js, "<kbd>' + ALT + '</kbd> <kbd>Shift</kbd> <kbd>A</kbd>"));
ck('a11y dialog: a quiet reset and the help page', str_contains($js, 'data-a11y-reset>Reset to standard</button>') && str_contains($js, 'href="/accessibility/">Accessibility help</a>'));
ck('a11y dialog: four labelled groups (Text size, Contrast, Reading, Moving around)', str_contains($js, '<legend>Text size</legend>') && str_contains($js, '<legend>Contrast</legend>') && str_contains($js, '<legend>Reading</legend>') && str_contains($js, '<legend>Moving around</legend>'));
$css = (string) file_get_contents("$r/assets/site/a11y.css");
ck('a11y css: text sizes scale rem from <html>', str_contains($css, 'html.a11y-t200{font-size:200%!important}') && str_contains($css, 'html.a11y-t125{font-size:125%!important}'));
ck('a11y css: comfortable spacing is the WCAG 1.4.12 values', str_contains($css, 'line-height:1.5!important;letter-spacing:.12em!important;word-spacing:.16em!important') && str_contains($css, 'margin-bottom:2em!important'));
ck('a11y css: high contrast has light and dark and follows the device', str_contains($css, 'html.a11y-hc-dark{--hc-bg:#000;--hc-fg:#fff') && str_contains($css, '@media (prefers-color-scheme:dark){html.a11y-hc-auto{'));
ck('a11y css: the font is self-hosted (font-src \'self\'), OFL licence alongside', str_contains($css, "src:url(fonts/AtkinsonHyperlegible-400.woff2)") && is_file("$r/assets/site/fonts/AtkinsonHyperlegible-400.woff2")
   && is_file("$r/assets/site/fonts/AtkinsonHyperlegible-700.woff2") && str_contains((string) file_get_contents("$r/assets/site/fonts/licenses/atkinson-hyperlegible-OFL.txt"), 'SIL Open Font License'));
ck('a11y css: bigger targets are 44px; still stops animation and the ticker', str_contains($css, 'min-height:44px!important') && str_contains($css, 'html.a11y-still .avh-tick-track{animation:none!important}'));
ck('a11y css: bottom left in logical properties, clear of the measured bottom bars and the safe area; out of print',
   str_contains($css, '.av-a11y-fab{position:fixed;inset-inline-start:var(--av-fab-left,max(16px,env(safe-area-inset-left)));bottom:calc(var(--av-bottom-ui,0px) + 16px + env(safe-area-inset-bottom));')
   && str_contains($css, '@media print{.av-a11y-fab,') && !str_contains($css, 'left:var(--av-fab'));
ck('a11y css: tucked off the edge and faded (not display:none), mirrored for right-to-left, back on focus, no slide with reduced motion',
   str_contains($css, '.av-a11y-fab[data-tuck]{transform:translateX(calc(-100% - 24px));opacity:0;pointer-events:none}') && str_contains($css, '[dir="rtl"] .av-a11y-fab[data-tuck]{transform:translateX(calc(100% + 24px))}')
   && str_contains($css, '.av-a11y-fab[data-tuck]:focus-visible{transform:none;opacity:1;pointer-events:auto}') && str_contains($css, '@media (prefers-reduced-motion:reduce){.av-a11y-fab{transition:none}.av-a11y-fab[data-tuck]{transform:none}}'));
ck('a11y css: a phone gets a 44px tab flush with the edge, and it waits while the cookie notice asks',
   str_contains($css, '@media (max-width:599px){') && str_contains($css, '.av-a11y-fab{inset-inline-start:0;width:44px;height:44px;') && str_contains($css, 'border-radius:0 999px 999px 0') && str_contains($css, 'body:has(.av-cc:not([hidden])) .av-a11y-fab{display:none}'));
ck('a11y css: the launcher is not drawn while its panel is open', str_contains($css, 'html:has(#av-a11y[open]) .av-a11y-fab{display:none}'));
ck('a11y css: the button is at least 44px; focus scrolled into view stops above it (WCAG 2.4.11)', str_contains($css, '--av-fab:44px') && str_contains($css, 'html{scroll-padding-bottom:80px}'));
ck('a11y css: one set of tokens and one focus ring for every template', str_contains($css, '--av-ring:#2F6FDB;--av-ring-w:3px;--av-ring-offset:2px;') && str_contains($css, 'html body :focus-visible:not([tabindex="-1"]):not(.tox *){outline:var(--av-ring-w) solid var(--av-ring)!important;') && str_contains($css, '@media (prefers-reduced-motion:reduce){:root{--av-motion:0s}}'));
ck('CI: the launcher and palette are driven by keyboard in Chromium (tests/a11y/launchers-ci.js)', is_file("$r/tests/a11y/launchers-ci.js")
   && str_contains((string) file_get_contents("$r/.github/workflows/ci.yml"), 'node launchers-ci.js')
   && str_contains((string) file_get_contents("$r/tests/a11y/launchers-ci.js"), "a button under the resting spot keeps it tucked")
   && str_contains((string) file_get_contents("$r/tests/a11y/launchers-ci.js"), "on a phone the tab waits while the notice asks")
   && str_contains((string) file_get_contents("$r/tests/a11y/launchers-ci.js"), "the tab bar is measured"));
ck('bottom ui: on a phone Chioma uses the same figure, and waits while the cookie notice asks', str_contains((string) file_get_contents("$r/assets/site/chioma.css"), '.chioma-root { inset-inline-end: 12px; bottom: calc(var(--av-bottom-ui, 0px) + 12px + env(safe-area-inset-bottom)); }')
   && str_contains((string) file_get_contents("$r/assets/site/chioma.css"), 'body:has(.av-cc:not([hidden])) .chioma-root:not(.is-open) { display: none; }'));
ck('a11y fab: on a phone Chioma\'s nudge sits above its own launcher, clear of the bottom-left button',
   str_contains((string) file_get_contents("$r/assets/site/chioma.css"), "@media (max-width: 479px) {\n  .chioma-greet { inset-inline-end: 0; bottom: 70px;"));
ck('a11y css: the panel opens in the launcher\'s corner, and as a bottom sheet over the visual viewport on a phone', str_contains($css, 'position:fixed;inset:auto;inset-inline-start:var(--av-fab-left,max(16px,env(safe-area-inset-left)));bottom:calc(var(--av-bottom-ui,0px) + 16px + env(safe-area-inset-bottom));') && str_contains($css, '.av-a11y{inset:auto 0 var(--av-vv-b,0px) 0;') && str_contains($css, 'border-radius:20px 20px 0 0'));
ck('a11y assets: small', filesize("$r/assets/site/a11y.js") < 26000 && filesize("$r/assets/site/a11y.css") < 42000 && filesize("$r/assets/site/a11y-core.js") < 14000 && filesize("$r/assets/site/bottom-ui.js") < 5000 && filesize("$r/assets/site/listen.js") < 10000);

/* No-JS settings form on the statement, and the account store. */
ck('a11y statement: the settings form is there, with a token', str_contains($acc, 'id="settings"') && str_contains($acc, '<form class="a11y-form" method="post"') && (bool) preg_match('~name="csrf" value="[0-9]+\.[0-9a-f]+\.~', $acc));
$st = (string) file_get_contents("$r/accessibility/index.php");
ck('a11y statement: the form checks the token and the site', str_contains($st, "av_csrf_valid((string) (\$_POST['csrf'] ?? ''))") && str_contains($st, 'HTTP_SEC_FETCH_SITE'));
ck('a11y statement: prepared and reviewed 25 November 2026', str_contains($st, "\$reviewed  = '25 November 2026';") && str_contains($st, "\$prepared  = '25 November 2026';"));
ck('a11y statement: the settings are described as conveniences, not compliance', str_contains($st, 'Display settings</h2>') && str_contains($st, '<strong>Listen</strong>') && str_contains($st, 'They are a convenience, not a substitute') && str_contains($st, 'they do not make the site “compliant”'));
ck('a11y statement: nothing known was taken off the list', str_contains($st, '<strong>Some older videos</strong>') && str_contains($st, '<strong>Annual reports and other PDFs</strong>') && str_contains($st, '<strong>Things we embed from other companies</strong>') && str_contains($st, '<strong>Staff and printing tools</strong>'));
ck('a11y statement: the GOV.UK model — scope, compliance status, non-accessible content, how tested, enforcement, preparation', str_contains($st, 'This statement applies to') && str_contains($st, '<h3>Compliance status</h3>') && str_contains($st, '<strong>partially compliant</strong>') && str_contains($st, '<h3>Non-accessible content</h3>') && str_contains($st, '<h3>How we tested this website</h3>') && str_contains($st, '<h3>Enforcement</h3>') && str_contains($st, '<h3>Preparation of this statement</h3>'));
ck('a11y statement: honest that real screen-reader testing by users has not been done', str_contains($st, 'We have not yet tested with real screen readers and their users') && !preg_match('~fully compliant with|meets WCAG|is compliant~i', strip_tags($st)));
ck('a11y statement: one table of every keyboard shortcut', str_contains($st, 'id="shortcuts"') && str_contains($st, '<caption class="av-sr">Keyboard shortcuts on this site</caption>'));
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
ck('listen: announces through the one shared polite announcer, named so a screen-reader user can pass it by', str_contains($ls, 'window.AvA11y.announce(t)') && !str_contains($ls, 'aria-live') && str_contains($ls, "'Read aloud with your browser’s voice'"));
ck('listen: stops when the text it was reading leaves the page, and when the page goes', str_contains($ls, 'if (!root.isConnected) { halt(true); return; }') && str_contains($ls, "addEventListener('pagehide', function () { synth.cancel(); });"));
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
ck('CI: the constitution and the portal views are covered, as a member', str_contains($axe, "const MEMBER = { 'constitution': ['/constitution/'], 'member portal': PORTAL_VIEWS.map(v => '/portal/#' + v) };") && str_contains((string) file_get_contents("$r/.github/workflows/ci.yml"), 'ci-session.php --member'));
ck('CI: every template family, focus-not-obscured, and the accessibility-tree snapshots', str_contains($axe, "'error pages':") && str_contains($axe, "'lesson player':") && str_contains($axe, 'focus obscured:') && is_file("$r/tests/a11y/tree-ci.js") && str_contains((string) file_get_contents("$r/.github/workflows/ci.yml"), 'node tree-ci.js'));
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
