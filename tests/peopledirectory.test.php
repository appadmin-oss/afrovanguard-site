<?php
/**
 * tests/peopledirectory.test.php — who the public People page may show, and
 * how the redesigned directory and profile render.
 *
 * The listing rule (av_team_listable): active, AND curated by hand or opted in,
 * AND not a system/organisation account. Read at display time, because the old
 * directory sync inserted every org account with active = 1 and those rows are
 * still active on the live database: consent has to be read, never assumed.
 *
 * Run via tests/run.php (provides ck() and render_page()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/people.php';

$pdo = Database::pdo();
av_team_ensure($pdo);
$pdo->exec('DELETE FROM team');

$mk = static function (array $f) use ($pdo): int {
    $f += ['role' => '', 'tier' => 'volunteer', 'featured' => 0, 'tagline' => '', 'bio' => '', 'location' => '', 'photo_url' => '',
           'socials' => '', 'email' => '', 'grp' => '', 'synced' => 0, 'active' => 1, 'listed_consent_at' => '', 'votm_month' => '', 'position' => 0];
    $cols = implode(',', array_keys($f));
    $ph = implode(',', array_fill(0, count($f), '?'));
    $pdo->prepare("INSERT INTO team ($cols) VALUES ($ph)")->execute(array_values($f));
    return (int) $pdo->lastInsertId();
};

$lead    = $mk(['name' => 'Test Leader Okafor', 'role' => 'Executive Director', 'tier' => 'management', 'featured' => 1, 'tagline' => 'Systems, not heroes.', 'socials' => json_encode(['li' => 'https://www.linkedin.com/in/x', 'tw' => 'javascript:alert(1)'])]);
$dir     = $mk(['name' => 'Test Director Bello', 'role' => 'Director of Programmes', 'tier' => 'director', 'featured' => 1]);
$curated = $mk(['name' => 'Test Curated Volunteer', 'role' => 'Member', 'tier' => 'volunteer', 'grp' => 'Media team', 'votm_month' => date('Y-m')]);
$patron  = $mk(['name' => 'Test Patron Eze', 'role' => 'Patron', 'tier' => 'patron', 'photo_url' => '/uploads/test-patron.jpg']);
$vol2    = $mk(['name' => 'Test Second Volunteer', 'role' => 'School Storm volunteer', 'tier' => 'volunteer']);
$synNo   = $mk(['name' => 'Test Unconsented Synced', 'role' => 'Member', 'synced' => 1, 'email' => 'unconsented@x.example']);
$synYes  = $mk(['name' => 'Test Consented Synced', 'role' => 'Member', 'synced' => 1, 'email' => 'consented@x.example', 'listed_consent_at' => '2026-11-01 10:00:00']);
$sysAdm  = $mk(['name' => 'Super Admin', 'role' => 'Member', 'tier' => 'management', 'synced' => 1]);
$sysOrg  = $mk(['name' => 'CACENTRE AFROVANGUARD', 'role' => 'Member']);
$sysOrg2 = $mk(['name' => 'Afrovanguard', 'role' => 'Member', 'synced' => 1, 'listed_consent_at' => '2026-11-01 10:00:00']);
$off     = $mk(['name' => 'Test Hidden Curated', 'active' => 0]);
$synVotm = $mk(['name' => 'Test Unconsented Votm', 'synced' => 1, 'votm_month' => date('Y-m')]);

$ids = array_map(static fn($r) => (int) $r['id'], av_team_public_rows($pdo));

/* ══ The listing rule ══════════════════════════════════════════════════ */
ck('people rule: a curated (synced = 0) active row is shown', in_array($curated, $ids, true) && in_array($lead, $ids, true));
ck('people rule: an auto-synced row with no consent is hidden, though active = 1', !in_array($synNo, $ids, true));
ck('people rule: an auto-synced row WITH recorded consent is shown', in_array($synYes, $ids, true));
ck('people rule: "Super Admin" is never listed', !in_array($sysAdm, $ids, true));
ck('people rule: an account named like the organisation/centre is never listed, even curated or consented',
   !in_array($sysOrg, $ids, true) && !in_array($sysOrg2, $ids, true));
ck('people rule: an inactive row stays hidden', !in_array($off, $ids, true));
ck('people rule: the system-name test is conservative — a person with such a word in their name is kept',
   !av_team_is_system_account('Admin Okafor') && !av_team_is_system_account('Esther Afrovanguard-Smith')
   && !av_team_is_system_account('A') && av_team_is_system_account('Afrovanguard Team') && av_team_is_system_account('No-Reply'));

/* The Studio still sees every row. */
$all = array_map(static fn($r) => (int) $r['id'], av_team_rows($pdo, false));
ck('people rule: the Studio list keeps hidden rows (admin behaviour unchanged)',
   in_array($synNo, $all, true) && in_array($sysAdm, $all, true) && in_array($off, $all, true));
ck('people rule: the Studio single read still finds an unconsented synced row', (av_team_one($pdo, $synNo)['status'] ?? '') === 'ok');

/* Every public read goes through the rule. */
$apiIds = array_map(static fn($m) => $m['id'], av_team_members($pdo)['members']);
ck('people rule: the public members API uses it', in_array($synYes, $apiIds, true) && !in_array($synNo, $apiIds, true) && !in_array($sysAdm, $apiIds, true));
ck('people rule: the public single read 404s a hidden row', (av_team_public_one($pdo, $synNo)['status'] ?? '') === 'error'
   && (av_team_public_one($pdo, $sysOrg)['status'] ?? '') === 'error' && (av_team_public_one($pdo, $curated)['status'] ?? '') === 'ok');
$votm = av_votm($pdo)['votm'] ?? null;
ck('people rule: Volunteer of the Month skips a pick who may not be shown', $votm && (int) $votm['id'] === $curated);
ck('people rule: groups come from listable rows only', av_team_groups($pdo) === ['Media team']);
$apiSrc = (string) file_get_contents(AV_ROOT . '/api.php');
ck('people rule: api.php reads one member through the public read', str_contains($apiSrc, 'av_team_public_one($pdo, $id)'));
ck('people rule: site search indexes listable rows only',
   str_contains((string) file_get_contents(AV_ROOT . '/lib/SiteSearch.php'), 'av_team_public_rows(Database::pdo())'));

/* ══ The constitutional map ════════════════════════════════════════════ */
ck('people map: groups in constitutional order', array_keys(AV_TEAM_STRUCTURE)
   === ['council', 'trustees', 'board', 'executive', 'directorates', 'institutions', 'volunteers', 'honorary']);
ck('people map: Trustees then the Honorary Institutional Custodians as a sub-group',
   array_keys(AV_TEAM_STRUCTURE['trustees']['bodies']) === ['trustees', 'custodians']);
ck('people map: the Executive — President, COO, then the Chief Officers in order',
   array_keys(AV_TEAM_STRUCTURE['executive']['bodies']['executive']['offices'])
   === ['president', 'coo', 'cfo', 'cto', 'cpio', 'cpco', 'cpdo', 'cco', 'crso', 'counsel', 'safeguarding']);
ck('people map: the seven Directorates in order', array_keys(AV_TEAM_STRUCTURE['directorates']['bodies'])
   === ['sts', 'gates', 'techome', 'mediapro', 'kcap', 'bec', 'careerhub']);
ck('people map: directorate offices — Project Director, Deputy, Administrator, Finance, Volunteer Coordinator, officers',
   array_keys(AV_TEAM_STRUCTURE['directorates']['bodies']['kcap']['offices'])
   === ['project-director', 'deputy-director', 'administrator', 'finance', 'volunteer-coordinator', 'officer']);
ck('people map: Institutions — Academy, NGGC, CACENTREs', array_keys(AV_TEAM_STRUCTURE['institutions']['bodies']) === ['academy', 'nggc', 'cacentre']);
ck('people map: Executive Volunteers before Official Volunteers',
   array_keys(AV_TEAM_STRUCTURE['volunteers']['bodies']) === ['executive-volunteers', 'official-volunteers']);
ck('people map: the Governing Council has its Chairperson first', array_key_first(AV_TEAM_STRUCTURE['council']['bodies']['council']['offices']) === 'chair');
$bk = array_keys(av_team_bodies());
ck('people map: body keys are unique across groups (one flat index)', count($bk) === count(array_unique($bk)) && count($bk) === 18);
$allText = strtolower(json_encode(AV_TEAM_STRUCTURE));
ck('people map: no constitutional text or link on the public page (members-only document)',
   !str_contains($allText, 'constitution') && !str_contains($allText, 'shall'));
ck('people map: the constitution is still members-only, so the page must not link it',
   (!class_exists('ConstitutionDoc') || ConstitutionDoc::PUBLIC === false));

/* ══ Fallback placement from the old tier ══════════════════════════════ */
$pl = static fn(array $m) => (($p = av_team_placement($m + ['role' => '', 'grp' => ''])) ? $p['body'] . '/' . $p['office'] : '');
ck('people fallback: management → the Executive, office unknown', $pl(['tier' => 'management', 'role' => 'Head of Logistics']) === 'executive/');
ck('people fallback: role "CFO" → the CFO office', $pl(['tier' => 'director', 'role' => 'CFO']) === 'executive/cfo');
ck('people fallback: role "Chief Technology Officer" → CTO', $pl(['tier' => 'management', 'role' => 'Chief Technology Officer']) === 'executive/cto');
ck('people fallback: role "President and Chief Servant" → President', $pl(['tier' => 'management', 'role' => 'President and Chief Servant']) === 'executive/president');
ck('people fallback: director + "Board" → Board of Directors', $pl(['tier' => 'director', 'role' => 'Board Director']) === 'board/director');
ck('people fallback: director naming a directorate → that directorate', $pl(['tier' => 'director', 'role' => 'Project Director, Street-to-Stardom']) === 'sts/project-director'
   && $pl(['tier' => 'director', 'role' => 'Deputy Director, Techome']) === 'techome/deputy-director'
   && $pl(['tier' => 'director', 'role' => 'Director', 'grp' => 'Career Hub']) === 'careerhub/project-director'
   && $pl(['tier' => 'director', 'role' => 'Finance Officer, MediaPro']) === 'mediapro/finance'
   && $pl(['tier' => 'director', 'role' => 'Africa GATES lead']) === 'gates/project-director'
   && $pl(['tier' => 'director', 'role' => 'KCAP director']) === 'kcap/project-director'
   && $pl(['tier' => 'director', 'role' => 'BEC director']) === 'bec/project-director');
ck('people fallback: a director naming no directorate → the Executive', $pl(['tier' => 'director', 'role' => 'Director of Programmes']) === 'executive/');
ck('people fallback: patron → Honorary Patrons', $pl(['tier' => 'patron', 'role' => 'Patron']) === 'honorary/patron' && $pl(['tier' => 'patron', 'role' => 'Grand Patron']) === 'honorary/grand-patron');
ck('people fallback: ngv → Academy, ngg → NGGC', $pl(['tier' => 'ngv']) === 'academy/' && $pl(['tier' => 'ngg']) === 'nggc/');
ck('people fallback: volunteer (and the sync\'s "Member") → Official Volunteers', $pl(['tier' => 'volunteer', 'role' => 'Member']) === 'official-volunteers/official-volunteer');
ck('people fallback: a body set in the Studio wins over the tier', $pl(['tier' => 'volunteer', 'body' => 'board', 'office' => 'chair']) === 'board/chair');
ck('people fallback: an office from another body is dropped', $pl(['tier' => 'volunteer', 'body' => 'board', 'office' => 'cfo']) === 'board/');
ck('people fallback: an unknown body falls back to the tier', $pl(['tier' => 'patron', 'body' => 'nonsense']) === 'honorary/patron');
$sorted = array_map(static fn($m) => $m['name'], av_team_sort([
    ['id' => 1, 'name' => 'vol', 'tier' => 'volunteer'], ['id' => 2, 'name' => 'cto', 'tier' => 'management', 'role' => 'CTO'],
    ['id' => 3, 'name' => 'unknown-exec', 'tier' => 'management', 'role' => 'Head of Logistics'], ['id' => 4, 'name' => 'pres', 'body' => 'executive', 'office' => 'president'],
    ['id' => 5, 'name' => 'chair', 'body' => 'council', 'office' => 'chair'], ['id' => 6, 'name' => 'coo', 'tier' => 'management', 'role' => 'Chief Operating Officer'],
]));
ck('people sort: constitutional order, named offices before unknown ones', $sorted === ['chair', 'pres', 'coo', 'cto', 'unknown-exec', 'vol']);

/* ══ Role line never contradicts the section ══════════════════════════ */
ck('people role: the generic "Member" auto value shows the office instead', av_person_role_line(['role' => 'Member', 'tier' => 'volunteer']) === 'Official Volunteer');
ck('people role: no office known → the tier', av_person_role_line(['role' => '', 'tier' => 'ngv']) === 'NGV');
ck('people role: a real role is kept', av_person_role_line(['role' => 'NGV Coordinator', 'tier' => 'ngv']) === 'NGV Coordinator');
ck('people avatar: initials and a stable tint', av_person_initials('Divine-Joy Godwin') === 'DG' && av_person_initials('Ada') === 'A'
   && av_person_tint('Ada Okonkwo') === av_person_tint('ada okonkwo ') && av_person_tint('x') >= 0 && av_person_tint('x') < 6);
ck('people system names: one list, easy to extend', in_array('super admin', AV_TEAM_SYSTEM_NAMES, true) && in_array('cacentre', AV_TEAM_ORG_WORDS, true));

/* ══ The Studio save path ═════════════════════════════════════════════ */
$sid = av_team_save($pdo, ['name' => 'Test Saved Officer', 'tier' => 'volunteer', 'body' => 'executive', 'office' => 'cfo', 'active' => 1]);
$row = $pdo->query('SELECT body, office FROM team WHERE id = ' . $sid)->fetch(PDO::FETCH_ASSOC);
ck('people save: body and office are stored', $row['body'] === 'executive' && $row['office'] === 'cfo');
av_team_save($pdo, ['id' => $sid, 'name' => 'Test Saved Officer', 'body' => 'board', 'office' => 'cfo', 'active' => 1]);
$row = $pdo->query('SELECT body, office FROM team WHERE id = ' . $sid)->fetch(PDO::FETCH_ASSOC);
ck('people save: an office that is not in the body is cleared', $row['body'] === 'board' && $row['office'] === '');
av_team_save($pdo, ['id' => $sid, 'name' => 'Test Saved Officer', 'body' => 'made-up', 'office' => 'chair', 'active' => 1]);
$row = $pdo->query('SELECT body, office FROM team WHERE id = ' . $sid)->fetch(PDO::FETCH_ASSOC);
ck('people save: an unknown body is refused (blank → fallback)', $row['body'] === '' && $row['office'] === '');
ck('people save: the Studio read returns body and office', array_key_exists('body', av_team_one($pdo, $sid)['member']) && array_key_exists('office', av_team_one($pdo, $sid)['member']));
$pdo->prepare('DELETE FROM team WHERE id = ?')->execute([$sid]);
$adm = (string) file_get_contents(AV_ROOT . '/admin/index.php'); $admJs = (string) file_get_contents(AV_ROOT . '/admin/app.js');
ck('people studio: the form has Body and Office selects driven by the one map',
   str_contains($adm, 'id="p_body"') && str_contains($adm, 'id="p_office"') && str_contains($adm, 'foreach (AV_TEAM_STRUCTURE as') && str_contains($adm, 'av_team_bodies()'));
ck('people studio: the form loads and sends body and office', str_contains($admJs, "$('#p_body').value = m.body") && str_contains($admJs, "body: ($('#p_body')"));
ck('people studio: the list says where each row lands and whether it is public',
   str_contains((string) file_get_contents(AV_ROOT . '/admin/api.php'), "\$m['placement']") && str_contains($admJs, 'Not public'));
$colCheck = (string) file_get_contents(AV_ROOT . '/lib/people.php');
ck('people schema: body/office added through the idempotent ALTER list (SQLite + MySQL)',
   str_contains($colCheck, "\"ALTER TABLE team ADD COLUMN body TEXT NOT NULL DEFAULT ''\"") && str_contains($colCheck, "\"ALTER TABLE team ADD COLUMN office TEXT NOT NULL DEFAULT ''\""));

/* ══ The directory renders ═════════════════════════════════════════════ */
// Placements for the fixture: the lead is the President, the director chairs the Council.
$pdo->prepare('UPDATE team SET body = ?, office = ? WHERE id = ?')->execute(['executive', 'president', $lead]);
$pdo->prepare('UPDATE team SET body = ?, office = ? WHERE id = ?')->execute(['council', 'chair', $dir]);
$html = render_page(AV_ROOT . '/people/index.php');
ck('people page: renders', str_contains($html, 'The <em>Vanguard</em> Behind the Movement') && !str_contains($html, 'render_page: threw'));
ck('people page: listed people are on it', str_contains($html, 'Test Curated Volunteer') && str_contains($html, 'Test Consented Synced'));
ck('people page: hidden rows are not', !str_contains($html, 'Test Unconsented Synced') && !str_contains($html, 'Super Admin')
   && !str_contains($html, 'CACENTRE AFROVANGUARD') && !str_contains($html, 'Test Hidden Curated'));
preg_match_all('~<li class="ppl-lead"[^>]*>.*?class="ppl-lead-n"><a[^>]*>([^<]+)~s', $html, $lm);
ck('people page: the spotlight is the President, then the Council Chairperson', $lm[1] === ['Test Leader Okafor', 'Test Director Bello']);
preg_match_all('~<h2 id="ppl-g-([a-z]+)-h">~', $html, $gm);
ck('people page: sections in constitutional order, only the non-empty ones', $gm[1] === ['council', 'executive', 'volunteers', 'honorary']);
preg_match_all('~<h3 id="ppl-b-([a-z-]+)-h">~', $html, $bm);
ck('people page: multi-body groups show their bodies as sub-groups', $bm[1] === ['official-volunteers']);
ck('people page: a spotlit leader is still in their own section', substr_count($html, '>Test Leader Okafor</a>') === 2);
ck('people page: sections carry a count and our own one-line description',
   (bool) preg_match('~id="ppl-g-volunteers-h">Volunteers</h2>\s*<p class="ppl-sec-n"><span data-ppl-n>3</span>~', $html)
   && str_contains($html, '<p class="ppl-sec-d">' . e(AV_TEAM_STRUCTURE['volunteers']['desc']) . '</p>'));
ck('people page: "Member" never reaches a card', !preg_match('~class="ppl-card-r">Member<~', $html) && str_contains($html, '<p class="ppl-card-r">Official Volunteer</p>'));
ck('people page: no tier badge inside the cards any more', !str_contains($html, 'pcard-tier'));
ck('people page: the summary counts each group', str_contains($html, '<b>6 people</b>') && str_contains($html, '<span>Volunteers 3</span>') && str_contains($html, '<span>Council 1</span>'));
ck('people page: initials avatar is decorative and tinted', (bool) preg_match('~<span class="ppl-av ppl-tint-[0-5]" aria-hidden="true">TV</span>~', $html));
ck('people page: a photo is used when there is one', str_contains($html, 'src="/uploads/test-patron.jpg"'));
ck('people page: the toolbar is hidden until script runs, so no-JS shows everyone', (bool) preg_match('~<div class="ppl-tools" id="ppl-tools" hidden>~', $html));
ck('people page: the filter chips are the non-empty groups, toggle buttons with aria-pressed',
   substr_count($html, 'class="ppl-chip"') === 5 && str_contains($html, 'data-group="all" aria-pressed="true"')
   && str_contains($html, 'data-group="honorary" aria-pressed="false"') && !str_contains($html, 'data-group="board" aria-pressed') && !str_contains($html, 'ppl-pill'));
ck('people page: the result count is a polite live region', str_contains($html, 'id="ppl-count" role="status" aria-live="polite"'));
ck('people page: the script is a stamped file, not inline', (bool) preg_match('~<script src="/people/people\.js\?v=[0-9a-f]{10}" defer></script>~', $html)
   && !str_contains($html, "getElementById('pplGrid')"));
ck('people page: Volunteer of the Month highlight', str_contains($html, 'class="ppl-votm"') && str_contains($html, 'Volunteer of the Month'));
ck('people page: closes with the call to action to volunteer / membership', str_contains($html, 'Want to be part of the vanguard?')
   && str_contains($html, 'href="https://cacentre.afrovanguard.org.ng/volunteer"') && str_contains($html, 'href="/academy/#membership"'));
ck('people page: never links the members-only constitution', !str_contains($html, '/constitution/'));
ck('people page: an unsafe social URL is dropped, a real one kept with a name',
   !str_contains($html, 'javascript:alert') && str_contains($html, 'Test Leader Okafor on LinkedIn'));
ck('people page: breadcrumb uses the site pattern (› separators, aria-current)', str_contains($html, '<nav class="ppl-crumbs" aria-label="Breadcrumb"><ol class="av-crumbs"><li><a href="/">Home</a><span aria-hidden="true">›</span>')
   && str_contains($html, '<span aria-current="page">Our people</span>'));

/* No President / Chair / COO → whoever is featured; nobody featured → no spotlight. */
$pdo->exec("UPDATE team SET body = '', office = ''");
$html2 = render_page(AV_ROOT . '/people/index.php');
ck('people page: without the three offices, the spotlight falls back to featured people',
   preg_match('~<li class="ppl-lead"[^>]*>.*?Test Leader Okafor~s', $html2) === 1);
$pdo->exec('UPDATE team SET featured = 0');
$html3 = render_page(AV_ROOT . '/people/index.php');
ck('people page: with nobody to spotlight, the section is omitted cleanly', !str_contains($html3, 'id="ppl-lead-h"') && str_contains($html3, 'id="ppl-g-executive-h"'));

/* ══ The profile ═══════════════════════════════════════════════════════ */
$prof = render_page(AV_ROOT . '/people/index.php', ['id' => (string) $patron]);
ck('people profile: renders the person', str_contains($prof, '<h1>Test Patron Eze</h1>') && str_contains($prof, 'class="ppl ppl-profile"'));
ck('people profile: says where they sit', str_contains($prof, '<p class="ppl-eyebrow">Honorary Patrons and advisers</p>'));
ck('people profile: the breadcrumb is a list with › separators, not " · "', str_contains($prof, '<li><a href="/people/">Our people</a><span aria-hidden="true">›</span></li><li><span aria-current="page">Test Patron Eze</span>')
   && !str_contains($prof, '<span aria-hidden="true"> · </span>'));
ck('people profile: a link back to the directory', str_contains($prof, 'class="ppl-back" href="/people/"'));
$profV = render_page(AV_ROOT . '/people/index.php', ['id' => (string) $curated]);
ck('people profile: related people are from the same body and listable only',
   str_contains($profV, 'More from Official Volunteers') && str_contains($profV, 'Test Second Volunteer') && str_contains($profV, 'Test Consented Synced')
   && !str_contains($profV, 'Test Unconsented Synced') && !str_contains($profV, 'Test Patron Eze'));
ck('people profile: the role line shows the office for "Member"', str_contains($profV, '<p class="ppl-prole">Official Volunteer</p>'));
ck('people profile: the Volunteer of the Month badge', str_contains($profV, 'class="ppl-badge"'));
foreach (['unconsented synced' => $synNo, 'system account' => $sysAdm, 'organisation account' => $sysOrg, 'inactive' => $off, 'missing' => 999999] as $why => $hid) {
    /* The suite has sent output already, so the status line cannot be read
       back here; the page drawn is the 404 page (av_error_render, mood "lost"). */
    $h = render_page(AV_ROOT . '/people/index.php', ['id' => (string) $hid]);
    ck("people profile: 404 for a hidden row ($why)", str_contains($h, 'data-mood="lost"') && !str_contains($h, 'class="ppl ppl-profile"'));
}

/* ══ The script ════════════════════════════════════════════════════════ */
$js = (string) file_get_contents(AV_ROOT . '/people/people.js');
ck('people js: toggles aria-pressed and hides with the hidden attribute (no inline styles)',
   str_contains($js, "setAttribute('aria-pressed'") && str_contains($js, '.hidden =') && !str_contains($js, 'style.display'));
ck('people js: whole sections with nobody left are hidden', str_contains($js, 'sec.hidden = n === 0'));
ck('people js: a person shown twice (spotlight + section) is counted once', str_contains($js, "getAttribute('data-id')"));
ck('people js: the toolbar is revealed by the script', str_contains($js, 'tools.hidden = false'));

$pdo->exec('DELETE FROM team');
