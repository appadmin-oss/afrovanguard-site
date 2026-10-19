<?php
/**
 * tests/navmember.test.php — the signed-in Home nav (PAGES.md "Signed-in nav
 * (2026-10-11)", CHECKLIST NAV-M-01…09).
 *
 * avh.js run('member') asks /academy/api.php?action=me and, for a user, adds
 * body.is-member: an account button + popover on the wide nav, a 44px initial
 * disc → /portal/ below 1180px, a member card and Sign out in the drawer. The
 * markup is in partials/avh-chrome.php, so every page that reuses the Home nav
 * carries it; the av_si hint's "My portal" stays as the pre-confirmation state.
 */
declare(strict_types=1);
require_once AV_ROOT . '/partials/avh-chrome.php';

$nav = (string) (avh_chrome_html()['nav'] ?? '');
$hooks = [
    'account wrapper'      => 'data-avh-acct>',
    'account button'       => 'data-avh-acct-btn',
    'popover'              => 'id="avh-acct-menu" data-avh-acct-menu hidden',
    'name slot'            => 'data-avh-me-name',
    'first-name slot'      => 'data-avh-me-first',
    'email slot (hidden)'  => 'data-avh-me-email hidden',
    'narrow disc'          => '<a class="avh-acct-mini" href="/portal/" aria-label="Your portal">',
    'drawer member card'   => 'class="avh-drawer-me"',
    'drawer portal link'   => '<a class="avh-drawer-portal" href="/portal/">Your portal',
    'drawer Sign out'      => '<button type="button" class="avh-drawer-out" data-avh-logout>Sign out</button>',
    'popover Sign out'     => '<button type="button" class="avh-acct-out" data-avh-logout>Sign out</button>',
];
foreach ($hooks as $label => $needle) ck("navmember: shared nav has the $label", str_contains($nav, $needle));
ck('navmember: the button starts collapsed and names its popover',
   str_contains($nav, 'aria-expanded="false" aria-haspopup="true" aria-controls="avh-acct-menu"'));
ck('navmember: popover links match nav.js (portal, academy, diary, community)',
   str_contains($nav, '<a class="avh-acct-cta" href="/portal/">Your portal')
   && str_contains($nav, '<a href="/academy/">Academy</a><a href="/diary/">The Diary</a><a href="/community/">Community</a>'));
ck('navmember: both Sign in links get ?next= (data-avh-login)', substr_count($nav, 'data-avh-login') === 2);
ck('navmember: the shared nav has no inline style', !preg_match('/\sstyle\s*=|<style/i', $nav));

foreach (['index.html', 'about.html'] as $page) {
    $h = (string) file_get_contents(AV_ROOT . '/' . $page);
    ck("navmember: $page carries the account button", str_contains($h, 'data-avh-acct-btn'));
    ck("navmember: $page carries the drawer member card and Sign out", str_contains($h, 'class="avh-drawer-me"') && str_contains($h, 'avh-drawer-out'));
    ck("navmember: $page nav is the shared nav", str_contains($h, $nav));
}

$js = (string) file_get_contents(AV_ROOT . '/assets/site/avh.js');
$m = preg_match("~run\('member', \(\) => \{(.*?)\n  \}\);~s", $js, $mm) ? $mm[1] : '';
ck('navmember: avh.js has run(\'member\')', $m !== '');
ck('navmember: it asks /academy/api.php?action=me', str_contains($m, "'/academy/api.php?action=me'"));
ck('navmember: Sign out POSTs logout and reloads', str_contains($m, "'/academy/api.php?action=logout', { method: 'POST'") && str_contains($m, 'location.reload()'));
ck('navmember: Sign out disables itself', str_contains($m, 'out.disabled = true'));
ck('navmember: the name goes in with textContent', str_contains($m, 'n.textContent = name') && str_contains($m, 'n.textContent = first'));
ck('navmember: no innerHTML, no inline style in the member code', !str_contains($m, 'innerHTML') && !str_contains($m, '.style.') && !str_contains($m, 'cssText'));
ck('navmember: is-member is added only for a returned user', str_contains($m, 'd && d.ok && d.user'));
ck('navmember: a confirmed "no user" drops the av_si hint', str_contains($m, "classList.remove('av-si')"));
ck('navmember: Esc / outside click / breakpoint close the popover', str_contains($m, 'pushLayer(layer)') && str_contains($m, '!wrap.contains(e.target)') && str_contains($m, 'onMq(deskMq'));

$css = (string) file_get_contents(AV_ROOT . '/assets/site/avh.css');
ck('navmember: account UI is hidden until body.is-member', str_contains($css, '.avh-acct,.avh-acct-mini,.avh-drawer-me,.avh-drawer-out{display:none}'));
ck('navmember: the narrow disc is 44×44', str_contains($css, '.avh-acct-mini{width:44px;height:44px'));
ck('navmember: long name/email truncate', substr_count($css, 'text-overflow:ellipsis') >= 3);
ck('navmember: the av_si "My portal" gives way to the account UI', str_contains($css, 'html.av-si body.avh.is-member .avh-member{display:none}'));
ck('navmember: home grids are balanced (2-up tablet, 4-up desktop)',
   str_contains($css, '@media (min-width:640px) and (max-width:959px){') && str_contains($css, '.avh-cards{grid-template-columns:repeat(4,minmax(0,1fr))}'));
