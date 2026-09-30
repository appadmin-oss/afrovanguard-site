<?php
/**
 * tests/cacinventory.test.php — the console's register, read into the portal.
 *
 * ── THE COMPLAINT THIS ANSWERS ──────────────────────────────────────────────
 * A member could already open the console's inventory: it is in
 * AdminRole::MEMBER_PAGES over there, and membership is the grant. What cost
 * them was the journey — sign across, land in a different shell, find the
 * way back — for a question that takes four seconds to ask. So they stopped
 * asking the system and started asking each other, which is how a register
 * becomes fiction.
 *
 * This is therefore NOT new access, and the tests below are mostly about
 * keeping it that way: the same rows, reached from here, with nothing copied
 * and nothing widened.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/CacSso.php';
require_once dirname(__DIR__) . '/lib/CacTasks.php';
require_once dirname(__DIR__) . '/lib/CacInventory.php';

$src   = (string) file_get_contents(dirname(__DIR__) . '/lib/CacInventory.php');
$api   = (string) file_get_contents(dirname(__DIR__) . '/portal/cac-inventory.php');
$portal= (string) file_get_contents(dirname(__DIR__) . '/portal/index.php');
$js    = (string) file_get_contents(dirname(__DIR__) . '/portal/inventory.js');
$css   = (string) file_get_contents(dirname(__DIR__) . '/portal/portal.css');

/* ── Nothing is copied here ──────────────────────────────────────────────── */
foreach (['INSERT', 'UPDATE ', 'DELETE'] as $write) {
    ck('nothing here writes to a local table (' . $write . '): CACENTRE stays the one place an '
     . 'item is true', !str_contains($src, $write));
}
ck('and there is no table on this side holding items — a copy is a second answer waiting to '
 . 'be wrong', !str_contains($src, 'CREATE TABLE') && !str_contains($src, 'inv_items'));

/* ── It is read-only, and refuses rather than ignores ────────────────────── */
ck('the portal endpoint takes GET only', str_contains($api, "!== 'GET'"));
ck('…and says where an item IS changed, rather than failing silently',
   str_contains($api, 'Items are changed in the console.'));
ck('nothing in the client posts', !str_contains($js, "method: 'POST'") && !str_contains($js, 'method:"POST"'));

/* ── It fails soft, in every way it can fail ─────────────────────────────── */
$r = CacInventory::search(0);
ck('a member id of nothing makes no request at all', $r['ok'] === false && $r['items'] === []);
ck('…and still answers with the shape the screen expects, rather than a missing key',
   isset($r['facets']['categories'], $r['total'], $r['pages'], $r['url']));

ck('no shared secret here has its own answer', str_contains($src, "'error' => 'not-configured'"));
ck('the console being down has another, distinct from nothing matching',
   str_contains($src, "'error' => 'unreachable'"));
ck('and the centre not keeping a register at all has a third',
   str_contains($src, "'off'") && str_contains($api, "'off'"));
ck('each of those becomes a sentence a member can act on, not the word the wire used',
   str_contains($api, 'The console did not answer.'));

/* ── The member is never named in a query string ─────────────────────────── */
ck('the member id comes from this site\'s session', str_contains($api, '$uid = (int) $u[\'id\'];'));
ck('…and travels inside the signature, where the caller cannot change it',
   str_contains($src, "CacSso::mint(['id' => \$memberId])"));
ck('only the filters the far side reads are forwarded, so a stray query parameter on the '
 . 'portal\'s own URL cannot be pushed into somebody else\'s API',
   str_contains($src, "foreach (['q', 'category', 'site', 'status', 'page'] as \$k)"));

/* ── An endpoint that calls another site on demand is rate-limited ───────── */
ck('the endpoint is rate-limited, because one that reaches another site on demand can be '
 . 'used to reach it a great many times', str_contains($api, "av_rate_ok('cac_inventory'"));
ck('and it is for org members, the same rule the workspace link uses',
   str_contains($api, 'LmsAuth::isOrgMember($u)'));

/* ── Another system's data is bounded before a page renders it ───────────── */
ck('every field is length-capped on the way in', substr_count($src, 'mb_substr(') >= 8);
ck('a tone the far side invents becomes nothing rather than an unstyled word — or an '
 . 'attribute injected into a class list',
   str_contains($src, "in_array((string) (\$r['tone'] ?? ''), ['pos', 'warn', 'neg'], true)"));
ck('and the row count is capped, so one answer cannot become a page that never ends',
   str_contains($src, 'array_slice($rows, 0, 60)'));

/* ── The pane costs nothing until it is opened ───────────────────────────── */
ck('the register is not read while the portal page is built — the dashboard is the first '
 . 'thing a member sees and must not wait on the other site for a pane most visits never open',
   !str_contains($portal, 'CacInventory::search('));
ck('the pane loads itself the first time somebody looks at it',
   str_contains($js, "if (view === 'inventory' && !loaded) load();"));
ck('…and not again after that, so coming back does not throw away what they searched for',
   str_contains($js, 'loaded = true;'));
ck('showView says which pane it opened, which is how the pane knows',
   str_contains($portal, "document.dispatchEvent(new CustomEvent('portal:view'"));
ck('and arriving straight at #inventory, where no view event fires, still loads it',
   str_contains($js, "if (location.hash === '#inventory') maybeLoad('inventory');"));

/* ── The nav entry is honest about whether it leads anywhere ─────────────── */
ck('the entry appears only when the bridge is configured — one that leads to "the link is '
 . 'not set up" is a worse answer than no entry',
   str_contains($portal, "if (CacSso::ready()) {\n        \$nav['Work'][] = ['inventory', 'Inventory', 'gray', ''];"));
ck('and it is inside the block that only org members get',
   strpos($portal, "\$nav['Work'][] = ['inventory'") > strpos($portal, 'if ($isOrg) {'));

/* ── The pickers are the centre's, not a second copy ─────────────────────── */
ck('the categories and sites come from CACENTRE with the rows',
   str_contains($src, "'categories' => self::strings(\$data['facets']['categories'] ?? [])"));
ck('…and are filled once, so a search does not reset a picker somebody is using',
   str_contains($js, 'if (!facetsDone && d.facets)'));
ck('the options are replaced rather than appended, so re-running leaves no duplicates',
   str_contains($js, "el.textContent = '';"));

/* ── It reads like the portal, and says where it is looking ──────────────── */
ck('the pane keeps a link to the console for the things that are not read-only',
   str_contains($portal, 'Change an item in the console'));
ck('and the table is styled here rather than borrowed from the console',
   str_contains($css, '.inv-table'));
ck('a value the register does not hold looks like a missing value, not an entered one',
   str_contains($js, "'Not recorded'") && str_contains($css, '.inv-none'));
ck('the tag is monospaced, because it is a code read off a sticker character by character',
   str_contains($css, '.inv-tag') && str_contains($css, 'ui-monospace'));
ck('the table scrolls sideways rather than pushing the portal off its own width on a phone',
   str_contains($css, '.inv-wrap { overflow-x: auto; }'));

/* ── Anything this pane hides must actually hide ─────────────────────────────
 * `hidden` is only display:none in the browser's own stylesheet, and any
 * display rule here beats it. .inv-more is `display: flex`, so the pager was
 * drawn on a one-page result. portal.css already carried .pview[hidden] and
 * .tc-thread[hidden] for exactly this.
 */
ck('.inv-more says what to do when it is hidden, rather than relying on the attribute alone',
   str_contains($css, '.inv-more[hidden] { display: none; }'));
