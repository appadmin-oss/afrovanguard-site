<?php
/**
 * tests/palette.test.php — the portal's command palette.
 *
 * ── WHAT IT REPLACED ────────────────────────────────────────────────────────
 * A text box in the sidebar that hid every navigation link which did not
 * match what you typed. NN/g measured discoverability roughly halving when
 * navigation is hidden — and hiding it as a SEARCH RESULT is that cost paid at
 * the exact moment somebody is already lost. It also only ever found links:
 * never a member, never an item in the register.
 *
 * ── THE FILE IS SHARED WITH CACENTRE ────────────────────────────────────────
 * portal/palette.js is a copy of the console's, because the two sites are two
 * repositories with nowhere shared to put it. Everything that differs is
 * passed in as data, so the copy needs no knowledge of either site — but a
 * change belongs in both, and these tests pin the behaviours this site
 * depends on so a half-copied change fails here.
 */
declare(strict_types=1);

$js     = (string) file_get_contents(dirname(__DIR__) . '/portal/palette.js');
$api    = (string) file_get_contents(dirname(__DIR__) . '/portal/palette.php');
$portal = (string) file_get_contents(dirname(__DIR__) . '/portal/index.php');
$css    = (string) file_get_contents(dirname(__DIR__) . '/portal/portal.css');

/* ── The box that hid the navigation is gone ─────────────────────────────── */
ck('the sidebar no longer hides navigation links as you type — the one pattern the evidence '
 . 'says measurably costs discoverability',
   !str_contains($portal, "a.style.display=(!q||t.indexOf(q)>=0)?'':'none'"));
ck('and what replaced it is a button, because it opens a dialog',
   str_contains($portal, '<button class="pside-search" type="button" data-cmdk="cmd">'));
ck('which says which key it is', str_contains($portal, '<kbd class="cmdk-mod">Ctrl</kbd>'));
ck('and the script corrects that on a Mac, where the server cannot know',
   str_contains($js, 'k.textContent = MOD;'));

/* ── One list of where you can go ────────────────────────────────────────── */
ck('the palette reads the sidebar rather than keeping a second copy of the panes',
   str_contains($portal, "'navFrom' => '.pnav-link[data-view]'"));
ck('a link with no pane behind it is not offered — Academy and the main site leave the app',
   str_contains($portal, '.pnav-link[data-view]'));
ck('a badge count is stripped from the label: "Today 3" stops matching the moment somebody '
 . 'clears one', str_contains($js, '.pnav-badge'));

/* ── What it searches, and what it deliberately does not ─────────────────── */
ck('it searches the member directory', str_contains($api, 'MemberDirectory::listMembers($uid, $q, 6)'));
ck('and the register', str_contains($api, 'CacInventory::search($uid'));
ck('follow-ups are not in it: they are a list somebody works down, not a thing they look '
 . 'up, and finding one would only take them to the card it is already on',
   !str_contains($api, 'CacLeads::'));
ck('exactly one cross-site call, because three would put nine seconds of worst case behind '
 . 'a keystroke', substr_count($api, 'CacInventory::') === 1);
ck('two letters before it asks anything', str_contains($api, "if (mb_strlen(\$q) < 2)"));
ck('and it is rate-limited', str_contains($api, "av_rate_ok('portal_palette', 90, 60)"));

/* ── It does not widen what anybody may see ──────────────────────────────── */
ck('the viewer id comes from the session, and the directory is already scoped to them',
   str_contains($api, 'MemberDirectory::listMembers($uid'));
ck('the register is for org members, the same rule the Inventory pane uses',
   str_contains($api, 'LmsAuth::isOrgMember($u)'));
ck('and signed-out gets nothing', str_contains($api, "if (!\$u) json_out(['ok' => false"));

/* ── The shared behaviours this site relies on ───────────────────────────── */
ck('shortcuts never fire while somebody is typing', str_contains($js, 'if (typing(e)) return;'));
ck('…including in a select, where a letter jumps to an option',
   str_contains($js, "n === 'INPUT' || n === 'TEXTAREA' || n === 'SELECT'"));
ck('it is a dialog and says so', str_contains($js, 'role="dialog" aria-modal="true"'));
ck('the row the arrows moved to is announced',
   str_contains($js, "input.setAttribute('aria-activedescendant', kids[cursor].id);"));
ck('focus goes back where it came from', str_contains($js, 'if (opener && opener.focus)'));
ck('Tab cannot land behind the scrim', str_contains($js, "if (e.key === 'Tab')"));
ck('the scraped navigation is deduped by destination', str_contains($js, 'if (byHref[n.href]) return false;'));

/* ── It hides when it is hidden ──────────────────────────────────────────── */
ck('.cmdk says what to do when hidden — `hidden` alone loses to any display rule, which '
 . 'this stylesheet already carries four other rules for',
   str_contains($css, '.cmdk[hidden] { display: none; }'));

/* ── It is drawn in the portal's colours, not the console's ──────────────── */
ck('the palette uses this site\'s tokens', str_contains($css, '.cmdk-box {') && str_contains($css, 'var(--shadow-md)'));
ck('and none of my earlier invented ones survive, which silently fell back to their '
 . 'light-mode values and left white input fields in dark mode',
   !str_contains($css, '--pc-border') && !str_contains($css, '--pc-field') && !str_contains($css, '--pc-dim'));
ck('the pills use the semantic colours the portal already had, so dark mode needs no second '
 . 'copy of them underneath',
   str_contains($css, '.inv-pill--pos  { background: var(--green-soft);  color: var(--green); }')
   && !str_contains($css, 'body.is-dark .inv-pill--pos'));
