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
ck('people map: Institutions — Academy, CACENTREs (no NGG)', array_keys(AV_TEAM_STRUCTURE['institutions']['bodies']) === ['academy', 'cacentre']);
ck('people tiers: NGG retired', !in_array('ngg', AV_TEAM_TIERS, true) && !isset(AV_TIER_LABELS['ngg']));
ck('people tiers: NGV spelled out', av_tier_label('ngv') === 'NextGen Vanguard');
ck('people map: Executive Volunteers before Official Volunteers',
   array_keys(AV_TEAM_STRUCTURE['volunteers']['bodies']) === ['executive-volunteers', 'official-volunteers']);
ck('people map: the Governing Council has its Chairperson first', array_key_first(AV_TEAM_STRUCTURE['council']['bodies']['council']['offices']) === 'chair');
$bk = array_keys(av_team_bodies());
ck('people map: body keys are unique across groups (one flat index)', count($bk) === count(array_unique($bk)) && count($bk) === 17);
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
ck('people fallback: ngv → Academy, old ngg → Official Volunteers', $pl(['tier' => 'ngv']) === 'academy/' && str_starts_with($pl(['tier' => 'ngg']), 'official-volunteers/'));
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
ck('people role: no office known → the tier', av_person_role_line(['role' => '', 'tier' => 'ngv']) === 'NextGen Vanguard');
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
ck('people page: sections in constitutional order, only the non-empty ones (spotlit people leave theirs)', $gm[1] === ['volunteers', 'honorary']);
ck('people page: a group emptied by the spotlight keeps its chip, so the filter still finds its leader', str_contains($html, 'class="ppl-chip" href="#ppl-leadership" data-group="executive"') && str_contains($html, 'class="ppl-chip" href="#ppl-leadership" data-group="council"'));
preg_match_all('~<h3 id="ppl-b-([a-z-]+)-h">~', $html, $bm);
ck('people page: multi-body groups show their bodies as sub-groups', $bm[1] === ['official-volunteers']);
ck('people page: a spotlit leader appears once, not again in their section', substr_count($html, '>Test Leader Okafor</a>') === 1);
ck('people page: sections carry our own one-line description and no count bubble',
   (bool) preg_match('~id="ppl-g-volunteers-h">Volunteers</h2>\s*<p class="ppl-sec-d">' . preg_quote(e(AV_TEAM_STRUCTURE['volunteers']['desc']), '~') . '</p>~', $html)
   && !str_contains($html, 'ppl-sec-n') && !str_contains($html, 'data-ppl-n'));
ck('people page: "Member" never reaches a card', !preg_match('~class="ppl-card-r">Member<~', $html) && str_contains($html, '<p class="ppl-card-r">Official Volunteer</p>'));
ck('people page: no tier badge inside the cards any more', !str_contains($html, 'pcard-tier'));
ck('people page: the hero is plain: no summary line, one button (membership lives in the closing band)',
   !str_contains($html, 'ppl-summary') && preg_match('~<header class="ppl-hero">(.*?)</header>~s', $html, $hm) === 1
   && substr_count($hm[1], 'class="ppl-btn"') === 1 && !str_contains($hm[1], 'ppl-votm') && !str_contains($hm[1], '/academy/#membership'));
ck('people page: directory cards carry no tagline (it stays on the profile)', !str_contains($html, 'ppl-card-t'));
ck('people page: initials avatar is decorative and tinted', (bool) preg_match('~<span class="ppl-av ppl-tint-[0-5]" aria-hidden="true">TV</span>~', $html));
ck('people page: a photo is used when there is one, laid over the initials so a missing file still shows them',
   (bool) preg_match('~<span class="ppl-av (ppl-tint-[0-5]) ppl-av--ph" aria-hidden="true">TE<img class="\1" src="/uploads/test-patron\.jpg" alt="" data-ini="TE"~', $html));
ck('people page: the search is hidden until script runs; the chips show without it, so no-JS shows everyone and the structure',
   str_contains($html, '<div class="ppl-tools" id="ppl-tools">') && str_contains($html, '<div class="ppl-search" data-ppl-search hidden>'));
ck('people page: the result count is a polite live region', str_contains($html, 'id="ppl-count" role="status" aria-live="polite"'));
ck('people page: the script is a stamped file, not inline', (bool) preg_match('~<script src="/people/people\.js\?v=[0-9a-f]{10}" defer></script>~', $html)
   && !str_contains($html, "getElementById('pplGrid')"));
ck('people page: Volunteer of the Month highlight, a strip after the hero', str_contains($html, 'class="ppl-votm"') && str_contains($html, 'Volunteer of the Month')
   && strpos($html, 'class="ppl-votm"') > strpos($html, '</header>', strpos($html, 'class="ppl-hero"')));
ck('people page: closes with the call to action to volunteer / membership', str_contains($html, 'Want to be part of the vanguard?')
   && str_contains($html, 'href="https://cacentre.afrovanguard.org.ng/volunteer"') && str_contains($html, 'href="/academy/#membership"'));
ck('people page: never links the members-only constitution', !str_contains($html, '/constitution/'));
ck('people page: an unsafe social URL is dropped, a real one kept with a name',
   !str_contains($html, 'javascript:alert') && str_contains($html, 'Test Leader Okafor on LinkedIn'));
ck('people page: breadcrumb uses the site pattern (› separators, aria-current)', str_contains($html, '<nav class="ppl-crumbs" aria-label="Breadcrumb"><ol class="av-crumbs"><li><a href="/">Home</a><span aria-hidden="true">›</span>')
   && str_contains($html, '<span aria-current="page">Our people</span>'));

/* ══ The chips: the structure and the filter in one row ═════════════════ */
preg_match('~<nav class="ppl-chips" aria-label="([^"]+)" data-ppl-chips>(.*?)</nav>~s', $html, $cm);
$chipRow = $cm[2] ?? '';
preg_match_all('~<a class="ppl-chip" href="(#[a-z-]+)" data-group="([a-z]+)">([^<]+) <span class="ppl-chip-n">(\d+)<span class="av-sr"> (person|people)</span></span></a>~', $chipRow, $ov, PREG_SET_ORDER);
ck('people chips: one row — the old "How we are organised" overview is gone, markup and styles',
   $chipRow !== '' && !str_contains($html, 'ppl-org') && !str_contains($html, 'How we are organised') && !str_contains($html, 'data-ppl-jump')
   && !str_contains((string) file_get_contents(AV_ROOT . '/people/people.css'), 'ppl-org') && substr_count($html, 'class="ppl-chip"') === 5);
ck('people chips: without script a named <nav> of plain links (no aria-pressed, no buttons), in the toolbar before Leadership',
   ($cm[1] ?? '') === 'Browse our people by group' && !str_contains($chipRow, 'aria-pressed') && !str_contains($chipRow, '<button')
   && strpos($html, 'data-ppl-chips') < strpos($html, 'id="ppl-leadership"') && strpos($html, 'class="ppl-votm"') < strpos($html, 'data-ppl-chips')
   && !str_contains($html, '>Browse by<'));
ck('people chips: "Everyone" first with the total, then only the groups with listable people, in constitutional order',
   array_column($ov, 2) === ['all', 'council', 'executive', 'volunteers', 'honorary'] && $ov[0][3] === 'Everyone' && (int) $ov[0][4] === 6);
ck('people chips: each counts everybody once, leaders included (Council 1: its Chair is in Leadership)',
   array_combine(array_column($ov, 2), array_map('intval', array_column($ov, 4))) === ['all' => 6, 'council' => 1, 'executive' => 1, 'volunteers' => 3, 'honorary' => 1]
   && $ov[1][5] === 'person' && $ov[3][5] === 'people');
ck('people chips: each links to its section without script, or to Leadership when the spotlight emptied it',
   array_column($ov, 1) === ['#ppl-leadership', '#ppl-leadership', '#ppl-leadership', '#ppl-g-volunteers', '#ppl-g-honorary']
   && str_contains($html, 'id="ppl-g-volunteers"') && str_contains($html, 'id="ppl-g-honorary"') && str_contains($html, 'id="ppl-leadership"'));
ck('people chips: the short group names', $ov[1][3] === 'Council' && $ov[4][3] === 'Patrons &amp; advisers');

/* ══ Layout: full-width sections, cards, launcher-safe column ═════════ */
ck('people layout: sections are a full-width header (name, then its line) over the people, no side column',
   !str_contains($html, 'ppl-sec-side') && str_contains($html, '<div class="ppl-sec-head">'));
ck('people layout: Leadership cards carry the tagline, socials and a "View profile" hint (visual only)',
   str_contains($html, 'class="ppl-lead-t">Systems, not heroes.</p>') && str_contains($html, '<span class="ppl-lead-go" aria-hidden="true">View profile →</span>'));
ck('people layout: cards are one link with an arrow hint, avatars 60px', str_contains($html, '<span class="ppl-go" aria-hidden="true">→</span>')
   && substr_count($html, '<span class="ppl-go"') === substr_count($html, '<li class="ppl-card"'));
ck('people layout: small sub-groups take as many columns as they have people', (bool) preg_match('~class="ppl-body ppl-body--n[1-4]" id="ppl-b-official-volunteers"~', $html));
$css = (string) file_get_contents(AV_ROOT . '/people/people.css');
ck('people layout: from 900px the content column keeps a gutter clear of both floating launchers',
   str_contains($css, '--ppl-fab-gutter:96px') && str_contains($css, 'calc(var(--ppl-fab-gutter) - max(0px,(100% - 1240px) / 2))')
   && (bool) preg_match('~@media \(min-width:900px\)\{\s*\.ppl-wrap\{--ppl-side:~', $css));
ck('people layout: Leadership names do not wrap at desktop widths', str_contains($css, '@media (min-width:960px){.ppl-lead-n{white-space:nowrap}}'));
ck('people layout: the chips scroll sideways on phones with a fade edge', str_contains($css, 'overflow-x:auto') && str_contains($css, 'mask-image:linear-gradient'));
ck('people toolbar: a Clear button, hidden until a search or filter is on', str_contains($html, '<button type="button" class="ppl-clear" data-ppl-clear hidden>Clear<span class="av-sr"> search and filters</span></button>'));

/* The directorate line on a card: shown when the role does not already name it. */
$dirP = $mk(['name' => 'Test Dir Officer', 'role' => 'Media officer', 'tier' => 'volunteer', 'body' => 'mediapro', 'office' => 'officer']);
$dirQ = $mk(['name' => 'Test Dir Named', 'role' => 'Deputy Director, MEDIAPRO', 'tier' => 'director', 'body' => 'mediapro', 'office' => 'deputy-director']);
$htmlD = render_page(AV_ROOT . '/people/index.php');
ck('people cards: a directorate person shows their directorate', (bool) preg_match('~Test Dir Officer</a></h4>\s*<p class="ppl-card-r">Media officer</p>\s*<p class="ppl-card-d">MEDIAPRO</p>~', $htmlD));
ck('people cards: not when the role already names it', (bool) preg_match('~Test Dir Named</a></h4>\s*<p class="ppl-card-r">Deputy Director, MEDIAPRO</p>\s*</div>~', $htmlD));
ck('people search: the search text includes the directorate and group', (bool) preg_match('~data-q="[^"]*mediapro[^"]*community advancement directorates~', $htmlD));
$pdo->prepare('DELETE FROM team WHERE id IN (?, ?)')->execute([$dirP, $dirQ]);

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
ck('people profile: says where they sit, linked to that place on the organogram',
   str_contains($prof, '<p class="ppl-eyebrow"><a class="ppl-where" href="/people/organogram/#org-along-honorary">Honorary Patrons and advisers<span class="av-sr"> (see where this sits on the organogram)</span></a></p>'));
ck('people profile: the breadcrumb is a list with › separators, not " · "', str_contains($prof, '<li><a href="/people/">Our people</a><span aria-hidden="true">›</span></li><li><span aria-current="page">Test Patron Eze</span>')
   && !str_contains($prof, '<span aria-hidden="true"> · </span>'));
ck('people profile: a link back to the directory (the script points it at the filtered view)', str_contains($prof, 'class="ppl-back" href="/people/" data-ppl-back'));
ck('people profile: loads the people script', (bool) preg_match('~<script src="/people/people\.js\?v=[0-9a-f]{10}" defer></script>~', $prof));
ck('people profile: nothing listed → the next step is Contact Afrovanguard, not "Get in touch"',
   str_contains($prof, 'id="ppl-next-h">Want to reach Test?</h2>') && str_contains($prof, '<a class="ppl-btn" href="/contact.html">Contact Afrovanguard</a>') && !str_contains($prof, '>Get in touch<'));
$next = static fn(string $h) => preg_match('~<section class="ppl-next".*?</section>~s', $h, $nm) ? $nm[0] : '';
$profL = $next(render_page(AV_ROOT . '/people/index.php', ['id' => (string) $lead]));
ck('people profile: a listed social → "Get in touch" with the named link, no Contact Afrovanguard',
   str_contains($profL, 'id="ppl-next-h">Get in touch</h2>') && str_contains($profL, '<span class="av-sr">Test Leader Okafor on </span>LinkedIn')
   && !str_contains($profL, 'Contact Afrovanguard') && !str_contains($profL, 'mailto:') && !str_contains($profL, 'javascript:alert'));
$mailer = $mk(['name' => 'Test Mailer Ade', 'role' => 'Patron', 'tier' => 'patron', 'socials' => json_encode(['email' => 'ade@x.example'])]);
$profM = render_page(AV_ROOT . '/people/index.php', ['id' => (string) $mailer]);
ck('people profile: a listed email → an "Email <first name>" button, and the icon is not repeated',
   str_contains($profM, '<a class="ppl-btn" href="mailto:ade@x.example">Email Test</a>') && substr_count($profM, 'mailto:ade@x.example') === 1 && str_contains($profM, '>Get in touch</h2>'));
$bad = $mk(['name' => 'Test Badmail Ade', 'role' => 'Patron', 'tier' => 'patron', 'email' => 'account@x.example', 'socials' => json_encode(['email' => 'not-an-email'])]);
$profB = $next(render_page(AV_ROOT . '/people/index.php', ['id' => (string) $bad]));
ck('people profile: an invalid listed email, or only an account email, never offers "Get in touch"',
   str_contains($profB, 'Contact Afrovanguard') && !str_contains($profB, 'mailto:') && !str_contains($profB, 'account@x.example'));
$pdo->prepare('DELETE FROM team WHERE id IN (?, ?)')->execute([$mailer, $bad]);
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
ck('people js: people are counted by id', str_contains($js, "getAttribute('data-id')"));
ck('people js: the search is revealed by the script', str_contains($js, 'searchBox.hidden = false'));
ck('people js: the chip links become a named group of toggle buttons (aria-pressed), nodes moved not re-parsed',
   str_contains($js, "row.setAttribute('role', 'group');") && str_contains($js, "row.setAttribute('aria-label', 'Show people from');")
   && str_contains($js, "createElement('button')") && str_contains($js, "b.setAttribute('aria-pressed', 'false');") && str_contains($js, 'nav.parentNode.replaceChild(row, nav);')
   && str_contains($js, 'while (a.firstChild) b.appendChild(a.firstChild);'));
ck('people js: the search and chip are kept in the URL (?q=, ?g=) with replaceState and read back on load',
   str_contains($js, 'history.replaceState(') && str_contains($js, "p.set('q', raw)") && str_contains($js, "p.set('g', group)")
   && str_contains($js, "params.get('q')") && str_contains($js, "setGroup(params.get('g') || 'all', true)"));
ck('people js: an unknown ?g= falls back to Everyone', str_contains($js, "groups.indexOf(g) !== -1 ? g : 'all'"));
ck('people js: accent-insensitive, typo-tolerant (one edit, words of 5+ letters)', str_contains($js, "normalize('NFD')") && str_contains($js, 'function within1(') && str_contains($js, 'w.length < 5'));
ck('people js: matches are marked with <mark>, built as nodes (no innerHTML)', str_contains($js, "createElement('mark')") && !str_contains($js, 'innerHTML'));
ck('people js: Clear shows whenever a search or filter is on', str_contains($js, 'clear.hidden = !active'));
ck('people js: the way back from a profile is same-origin /people/ only, rebuilt from q and g',
   str_contains($js, 'r.origin === location.origin && r.pathname === \'/people/\'') && str_contains($js, "['q', 'g'].forEach") && str_contains($js, "'/people/?' + qs"));
ck('people js: storage is guarded (private windows)', str_contains($js, 'try { if (v === undefined) return sessionStorage'));

$pdo->exec('DELETE FROM team');
$htmlE = render_page(AV_ROOT . '/people/index.php');
ck('people chips: an empty directory shows no chips and no toolbar', !str_contains($htmlE, 'class="ppl-chip"') && !str_contains($htmlE, 'id="ppl-tools"'));

/* ══ Initials tints: clearly a colour of their own on every ground ═════ */
$tintCss = (string) file_get_contents(AV_ROOT . '/people/people.css');
$tok = (string) file_get_contents(AV_ROOT . '/assets/site/av-tokens.css');
$hex = static function (string $src, string $name): string { return preg_match('~' . preg_quote($name, '~') . ':\s*(#[0-9A-Fa-f]{6})~', $src, $mm) ? $mm[1] : ''; };
$lum = static function (string $h): float {
    $c = array_map(static fn($i) => hexdec(substr($h, $i, 2)) / 255, [1, 3, 5]);
    $c = array_map(static fn($x) => $x <= 0.04045 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4, $c);
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$ratio = static function (string $a, string $b) use ($lum): float { $x = $lum($a); $y = $lum($b); return (max($x, $y) + 0.05) / (min($x, $y) + 0.05); };
$grounds = ['sand (profile hero)' => $hex($tok, '--av-sand'), 'paper (directory)' => $hex($tok, '--av-paper'), 'white (cards)' => $hex($tok, '--av-white'), 'stone-3' => $hex($tok, '--av-stone-3')];
ck('people tints: the grounds are read from the tokens', !in_array('', $grounds, true));
for ($t = 0; $t < 6; $t++) {
    $bg = $hex($tintCss, "--ppl-tint-$t"); $ink = $hex($tintCss, "--ppl-tint-$t-ink");
    $worst = $bg === '' ? 0.0 : min(array_map(static fn($g) => $ratio($bg, $g), $grounds));
    ck("people tints: tint $t ($bg) stands apart from every ground (≥ 1.4:1, worst " . round($worst, 2) . ')', $bg !== '' && $worst >= 1.4);
    ck("people tints: tint $t's initials ($ink) are ≥ 4.5:1 on it (" . ($bg && $ink ? round($ratio($bg, $ink), 2) : 0) . ')', $bg !== '' && $ink !== '' && $ratio($bg, $ink) >= 4.5);
    ck("people tints: .ppl-tint-$t uses its pair", str_contains($tintCss, ".ppl-tint-$t,.ppl img.ppl-tint-$t{background:var(--ppl-tint-$t);color:var(--ppl-tint-$t-ink)}"));
}
ck('people tints: no neutral stone or sand tint (it was nearly the profile hero\'s colour)', !preg_match('~\.ppl-tint-\d[^{]*\{background:var\(--av-(sand|stone)~', $tintCss));
ck('people tints: the profile hero is still sand, the tints\' reference ground', str_contains($tintCss, '.ppl-hero,.ppl-phero{position:relative;overflow:hidden;isolation:isolate;background:var(--av-sand);'));
ck('people tints: high contrast (light and dark) rings the avatar and the photo box, which it paints in the page ground',
   str_contains($tintCss, 'html.a11y-hc .ppl :is(.ppl-av,.ppl-pini){outline:2px solid var(--hc-fg);outline-offset:-2px}'));
