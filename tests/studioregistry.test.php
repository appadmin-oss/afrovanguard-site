<?php
/**
 * tests/studioregistry.test.php — one list of Studio sections, and a server
 * gate that is closed by default.
 *
 * The Studio's screens were listed three times (sidebar, the screen→role table
 * in app.js, the server's gates) and had already drifted: the sidebar hid
 * Celebrations and Communities from editors while the server let an editor
 * save and delete them, because the gate was "anything not listed is an
 * editor's". The promises pinned here:
 *
 *   • The sidebar is drawn from lib/StudioSections.php; there is no second
 *     hand-written list of tabs, and no screen→role table in app.js.
 *   • Every section has a screen (#<id>View), a place in the views map and a
 *     loader in activateTab; ids are unique; every role is a real one.
 *   • Every action the Studio API answers is assigned to exactly one of
 *     editor / management / Super Admin — none is left to a default, and an
 *     unassigned one is refused below Super Admin.
 *   • Where a section is hidden from editors, the actions behind it are too.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);
require_once AV_ROOT . '/lib/StudioSections.php';

$srIdx = (string) file_get_contents(AV_ROOT . '/admin/index.php');
$srJs = (string) file_get_contents(AV_ROOT . '/admin/app.js');
$srApi = (string) file_get_contents(AV_ROOT . '/admin/api.php');
$srAll = StudioSections::all();
$srIds = array_column($srAll, 'id');

ck('studio sections: ids are unique', count($srIds) === count(array_unique($srIds)) && count($srIds) > 20);
ck('studio sections: every least role is a real Studio role',
    !array_filter($srAll, static fn($s) => !isset(AdminRoles::RANK[$s['min']])));
ck('studio sections: the sidebar is drawn from the registry — no hand-written tab in the page',
    str_contains($srIdx, 'StudioSections::navHtml()') && !preg_match('/<button class="tab[^"]*" data-tab=/', $srIdx));
ck('studio sections: app.js keeps no screen→role table of its own; it reads the page\'s JSON',
    !preg_match('/TAB_MIN = \{\s*[a-z]+:/', $srJs) && str_contains($srJs, "getElementById('studioSections')") && str_contains($srIdx, 'id="studioSections"'));
ck('studio sections: …and a screen missing from it needs a Super Admin (closed, not open)', str_contains($srJs, "TAB_MIN[tab] || 'superadmin'"));

$srMissing = [];
foreach ($srIds as $id) {
    $view = str_contains($srIdx, 'id="' . $id . 'View"');
    $map = (bool) preg_match('/\b' . $id . ': \$\(\'#' . $id . 'View\'\)/', $srJs);
    $loader = str_contains($srJs, "which === '" . $id . "'");
    if (!$view || !$map || !$loader) $srMissing[] = $id . ($view ? '' : ' (no view)') . ($map ? '' : ' (not in views)') . ($loader ? '' : ' (no loader)');
}
ck('studio sections: every section has a screen, a place in the views map and a loader' . ($srMissing ? ' — ' . implode(', ', $srMissing) : ''), !$srMissing);
ck('studio sections: a name that is not a section lands on the Overview, not on another screen',
    (bool) preg_match("/else if \\(which === 'inbox'\\) \\{[^}]*\\}\\s*(?:\\/\\*.*?\\*\\/\\s*)?else \\{ show\\('overview'\\); loadOverview\\(\\); \\}/s", $srJs));
$srNav = StudioSections::navHtml();
ck('studio sections: the drawn sidebar has one tab per section, the first one current',
    substr_count($srNav, 'data-tab="') === count($srIds) && (bool) preg_match('/^\s*<div class="nav-group">\s*<button class="tab active" data-tab="' . $srIds[0] . '" aria-current="page">/', $srNav));
$srMin = StudioSections::minRoles();
ck('studio sections: old names still open their screen (homepage, pages → site)', ($srMin['homepage'] ?? '') === $srMin['site'] && ($srMin['pages'] ?? '') === $srMin['site']);

/* The server: every action assigned, exactly once. */
$srList = static function (string $name) use ($srApi): array {
    if (!preg_match('/\$' . $name . ' = \[(.*?)\n    \];/s', $srApi, $m)) return [];
    $body = (string) preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $m[1]);
    preg_match_all("/'([a-z0-9_]+)'/", $body, $mm);
    return $mm[1];
};
$srSuper = $srList('superadminOnly'); $srMgmt = $srList('managementOnly'); $srEd = $srList('editorAllowed');
$srSwitch = substr($srApi, (int) strpos($srApi, 'switch ($action)'));
preg_match_all("/case '([a-z0-9_]+)':/", $srSwitch, $srCases);
$srCases = array_values(array_unique($srCases[1]));
$srUnassigned = array_values(array_diff($srCases, $srSuper, $srMgmt, $srEd));
ck('studio api: every action is assigned to editor, management or Super Admin' . ($srUnassigned ? ' — unassigned: ' . implode(', ', $srUnassigned) : ''), !$srUnassigned && count($srCases) > 200);
$srTwice = array_values(array_intersect($srEd, array_merge($srSuper, $srMgmt)));
ck('studio api: no action is both an editor\'s and restricted' . ($srTwice ? ' — ' . implode(', ', $srTwice) : ''), !$srTwice);
ck('studio api: an unassigned action is refused below Super Admin, and logged',
    str_contains($srApi, "if (\$needs === '') {") && str_contains($srApi, "\$needs = 'superadmin';") && str_contains($srApi, 'unlisted action'));

/* Where the sidebar hides a section from editors, its actions are hidden too. */
$srBehind = ['celebrations' => ['cel_list', 'cel_save', 'cel_delete'], 'communities' => ['comm_list', 'comm_save', 'comm_delete'],
             'gifts' => ['gifts_list', 'gifts_submit', 'gifts_decide']];
$srLeak = [];
foreach ($srBehind as $sec => $acts) {
    if (($srMin[$sec] ?? 'editor') === 'editor') continue;
    foreach ($acts as $a) if (in_array($a, $srEd, true) || !in_array($a, array_merge($srMgmt, $srSuper), true)) $srLeak[] = $a;
}
ck('studio api: a section hidden from editors is closed to them on the server too' . ($srLeak ? ' — ' . implode(', ', $srLeak) : ''), !$srLeak);

/* The screen is in the address, and one search reaches everything. */
ck('studio links: opening a screen writes it to the address, and Back/Forward reopen it',
    str_contains($srJs, 'history.pushState(null') && str_contains($srJs, "window.addEventListener('popstate'") && str_contains($srJs, 'function openFromHash()'));
ck('studio links: a member-desk pane is in the address too (#members/offline)', str_contains($srJs, "setHash('members', which, true)"));
ck('studio links: a screen the role may not open is dropped from the address, not shown under its name',
    (bool) preg_match('/if \(location\.hash\) \{ try \{ history\.replaceState\(null, \'\', location\.pathname/', $srJs));
$srPage = StudioSections::forPage();
ck('studio search: the page carries every section and the other staff consoles for Ctrl+K',
    count($srPage['sections']) === count($srIds) && count($srPage['consoles']) > 0
    && !array_filter($srPage['consoles'], static fn($c) => !isset(AdminRoles::RANK[$c['min']]) || !str_starts_with($c['url'], '/')));
ck('studio search: it is a labelled dialog that Escape closes, giving focus back',
    str_contains($srIdx, 'role="dialog" aria-modal="true" aria-labelledby="findTitle"') && str_contains($srJs, "if (e.key === 'Escape') { e.preventDefault(); findClose(true); }")
    && str_contains($srJs, 'findTrigger.focus()'));
