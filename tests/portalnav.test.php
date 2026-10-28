<?php
/**
 * tests/portalnav.test.php — moving between the portal's views.
 *
 * Reported twice on 2026-10-11: "I cannot move between pages" and "the URL
 * just changes, the page doesn't". What keeps it working:
 *   • the router acts first (capture phase), so another script stopping or
 *     cancelling a click cannot leave the address changed and the view not;
 *   • Back/Forward (popstate) re-sync the view, as hash changes do;
 *   • scripts and styles are never served stale after a deploy — not from the
 *     browser's cache (.htaccess: no-cache) nor the service worker's;
 *   • nothing floats over the phone tab bar (the celebrations pill is gone).
 */
declare(strict_types=1);

$avp = (string) file_get_contents(AV_ROOT . '/portal/avp.js');
ck('portal nav: the view router listens in the capture phase', (bool) preg_match("~main\\.focus\\(\\{ preventScroll: true \\}\\);\\s*\\}, true\\);~", $avp));
ck('portal nav: a click cancelled by another script still switches the view', !preg_match("~closest\\('a\\[data-view\\], \\[data-goto\\]'\\);\\s*if \\(!a \\|\\| e\\.defaultPrevented\\)~", $avp));
ck('portal nav: Back and Forward re-sync the view', str_contains($avp, "addEventListener('popstate', fromUrl)") && str_contains($avp, "addEventListener('hashchange', fromUrl)"));

$ht = (string) file_get_contents(AV_ROOT . '/.htaccess');
ck('portal nav: scripts and styles are revalidated on every load', (bool) preg_match('~<FilesMatch "\\\\\.\(js\|mjs\|css\)\$">\s*Header unset Expires\s*Header set Cache-Control "no-cache"~', $ht));

$sw = (string) file_get_contents(AV_ROOT . '/sw.js');
ck('portal nav: the service worker fetches scripts and styles from the network first', (bool) preg_match('~\\\\\.\(js\|mjs\|css\)\$/i\.test\(url\.pathname\)\) \{\s*e\.respondWith\(\s*caches\.open\(VERSION\)\.then\(function \(c\) \{\s*return fetch\(req\)~', $sw));
ck('portal nav: the service worker cache was renamed, so old copies are dropped', !str_contains($sw, "'av-pwa-v4'"));

$idx = (string) file_get_contents(AV_ROOT . '/portal/index.php');
ck('portal nav: every section and tab names a view that exists', (function () use ($idx, $avp) {
    $views = [];
    foreach (array_merge([AV_ROOT . '/portal/index.php'], glob(AV_ROOT . '/portal/views/*.php') ?: []) as $f) if (preg_match_all('~class="pview[^"]*"[^>]*data-view="([a-z-]+)"|data-view="([a-z-]+)"[^>]*class="pview~', (string) file_get_contents($f), $m)) foreach (array_merge($m[1], $m[2]) as $v) if ($v !== '') $views[$v] = true;
    /* An alias in the router (avp.js ALIAS) is a view when what it points at is one. */
    if (preg_match('~var ALIAS = \{([^}]*)\}~', $avp, $am) && preg_match_all("~([a-z-]+): '([a-z-]+)'~", $am[1], $pairs, PREG_SET_ORDER)) {
        foreach ($pairs as $pp) if (isset($views[$pp[2]])) $views[$pp[1]] = true;
    }
    $ids = array_keys(PortalNav::SECTIONS);
    foreach (PortalNav::TABS as $slot) foreach ($slot as $t) $ids[] = $t;
    foreach (array_unique($ids) as $v) if (!isset($views[$v])) return false;
    foreach (PortalNav::SECTIONS as $s) if (!is_file(AV_ROOT . '/portal/views/' . $s['view'] . '.php')) return false;
    return count($ids) >= 8;
})());

/* ── one registry (lib/PortalNav.php) ── */
ck('portal nav: the shell draws its sidebar, views and tabs from the registry, and keeps no list of its own',
   str_contains($idx, 'PortalNav::groups($portalCtx)') && str_contains($idx, 'PortalNav::views($portalCtx)') && str_contains($idx, 'PortalNav::tabs($portalCtx)')
   && !preg_match("~require __DIR__ \. '/views/(?!meet-modal)[a-z-]+\.php'~", $idx) && !str_contains($idx, "\$nav['You'] = ["));
$guest = ['org' => false, 'ngv' => false, 'vol' => false, 'gate' => false, 'inventory' => false, 'fines' => false];
$all   = ['org' => true, 'ngv' => true, 'vol' => true, 'gate' => true, 'inventory' => true, 'fines' => true];
$ids = static fn(array $ctx): array => array_merge(...array_map(static fn($g) => array_column($g, 0), array_values(PortalNav::groups($ctx))));
ck('portal nav: a learner sees the Quest, mentorship, learning and their account — not Work, the programme or the gate',
   array_values(array_intersect(['quest', 'bills', 'mentorship', 'learning', 'membership'], $ids($guest))) === ['quest', 'bills', 'mentorship', 'learning', 'membership']
   && !array_intersect(['tasks', 'meetings', 'chat', 'ngv', 'ngv-fines', 'fines', 'attendance', 'volunteer', 'inventory'], $ids($guest))
   && PortalNav::label('membership', $guest) === 'Account');
ck('portal nav: everything the account opens is listed exactly once', count($ids($all)) === count(PortalNav::SECTIONS) && count(array_unique($ids($all))) === count($ids($all)));
ck('portal nav: the Vanguard Quest groups the Quest with what feeds it — bills, mentorship, attendance, fines',
   array_column(PortalNav::groups($all)['Vanguard Quest'], 0) === ['quest', 'bills', 'mentorship', 'attendance', 'ngv-fines', 'fines']);
ck('portal nav: the gate is open to a gate-pass holder or a vanguard, and to nobody else',
   PortalNav::visible('attendance', ['gate' => true] + $guest) && PortalNav::visible('attendance', ['ngv' => true] + $guest) && !PortalNav::visible('attendance', $guest));
ck('portal nav: each view file is drawn once, Today first', PortalNav::views($all)[0] === 'today' && count(PortalNav::views($all)) === count(array_unique(PortalNav::views($all))));
ck('portal nav: the phone bar is four tabs chosen by who is signed in',
   array_column(PortalNav::tabs($all), 0) === ['overview', 'tasks', 'chat', 'diary']
   && array_column(PortalNav::tabs(['ngv' => true] + $guest), 0) === ['overview', 'ngv', 'community', 'diary']
   && array_column(PortalNav::tabs($guest), 0) === ['overview', 'learning', 'community', 'diary']);
