<?php
/**
 * lib/people.php — native Team / People directory (replaces the old Google
 * Apps Script proxy). Backs the About people section, the /people directory,
 * Volunteer of the Month, and birthday celebrations. Managed in the Studio
 * (admin) and served as JSON by api.php.
 */
declare(strict_types=1);

const AV_TEAM_TIERS = ['management', 'director', 'patron', 'ngv', 'ngg', 'volunteer'];
const AV_TEAM_LEAD_TIERS = ['management', 'director'];
const AV_TIER_LABELS = ['management' => 'Management', 'director' => 'Director', 'patron' => 'Patron', 'ngv' => 'NGV', 'ngg' => 'NGG', 'volunteer' => 'Volunteer'];

/** URL slug + canonical profile URL for a member dict. */
function av_person_slug(array $m): string {
    $s = strtolower(trim((string) ($m['name'] ?? '')));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string) $s, '-');
}
function av_person_url(array $m): string {
    return '/people/' . (int) $m['id'] . '-' . av_person_slug($m) . '/';
}
function av_tier_label(string $t): string { return AV_TIER_LABELS[$t] ?? ucfirst($t); }

/*
 * ── The constitutional structure ─────────────────────────────────────────
 *
 * ONE map, in constitutional order, used for the directory's sections, its
 * sort order, its labels and the Studio's Body / Office selects:
 *
 *   group   — a section of the public page (Governing Council, … Honorary);
 *             'short' names it in the summary line and the filter
 *     bodies — where a person sits; a group with several bodies (the seven
 *              Directorates, the Institutions, Trustees and Custodians, the
 *              two volunteer classes) shows each as a sub-group
 *       offices — the posts within a body, in order; unknown/blank sorts last
 *
 * Source: the Constitution (docs/governance/constitution.md) — Art. IV §1,
 * VII–XIII and Schedule B. The constitution is members-only
 * (ConstitutionDoc::PUBLIC = false), so the public page uses only the names
 * of the bodies and offices; every description here is our own plain line,
 * not constitutional text. A row's body and office are stored in
 * team.body / team.office (av_team_ensure); a row without them is placed by
 * av_team_placement()'s fallback from its tier and role text.
 */
const AV_TEAM_DIRECTORATE_OFFICES = ['project-director' => 'Project Director', 'deputy-director' => 'Deputy Director',
    'administrator' => 'Project Administrator', 'finance' => 'Finance Officer', 'volunteer-coordinator' => 'Volunteer Coordinator',
    'officer' => 'Programme Officer'];
const AV_TEAM_INSTITUTION_OFFICES = ['head' => 'Head', 'coordinator' => 'Coordinator', 'officer' => 'Officer'];
const AV_TEAM_STRUCTURE = [
    'council' => ['short' => 'Council', 'label' => 'Governing Council', 'desc' => 'Keeps the vision, the values and the long-term direction on course.',
        'bodies' => ['council' => ['label' => 'Governing Council', 'offices' => [
            'chair' => 'Chairperson', 'deputy-chair' => 'Deputy Chairperson', 'member' => 'Council Member']]]],
    'trustees' => ['short' => 'Trustees', 'label' => 'Registered Trustees', 'desc' => 'Hold what Afrovanguard owns in trust for its mission, never for themselves.',
        'bodies' => [
            'trustees' => ['label' => 'Registered Trustees', 'offices' => ['trustee' => 'Trustee']],
            'custodians' => ['label' => 'Honorary Institutional Custodians', 'offices' => ['custodian' => 'Honorary Institutional Custodian']],
        ]],
    'board' => ['short' => 'Board', 'label' => 'Board of Directors', 'desc' => 'Oversees governance, performance and risk, and holds the Executive to account.',
        'bodies' => ['board' => ['label' => 'Board of Directors', 'offices' => ['chair' => 'Chair of the Board', 'director' => 'Director']]]],
    'executive' => ['short' => 'Executive', 'label' => 'The Executive', 'desc' => 'Runs Afrovanguard day to day, led by the President and Chief Servant.',
        'bodies' => ['executive' => ['label' => 'The Executive', 'offices' => [
            'president' => 'President and Chief Servant',
            'coo' => 'Chief Operating Officer',
            'cfo' => 'Chief Financial Officer',
            'cto' => 'Chief Technology Officer',
            'cpio' => 'Chief Programmes and Impact Officer',
            'cpco' => 'Chief People and Culture Officer',
            'cpdo' => 'Chief Partnerships and Development Officer',
            'cco' => 'Chief Communications and Public Affairs Officer',
            'crso' => 'Chief Research and Strategy Officer',
            'counsel' => 'General Counsel and Compliance Officer',
            'safeguarding' => 'Chief Safeguarding and Welfare Officer',
        ]]]],
    'directorates' => ['short' => 'Directorates', 'label' => 'Community Advancement Directorates', 'desc' => 'Seven directorates, each led by a Project Director, carry the work into communities.',
        'bodies' => [
            'sts' => ['label' => 'Street-to-Stardom (STS)', 'desc' => 'Children, families and character.', 'offices' => AV_TEAM_DIRECTORATE_OFFICES],
            'gates' => ['label' => 'Africa GATES', 'desc' => 'Finding, growing and showcasing talent.', 'offices' => AV_TEAM_DIRECTORATE_OFFICES],
            'techome' => ['label' => 'TECHOME', 'desc' => 'Technology, research and engineering.', 'offices' => AV_TEAM_DIRECTORATE_OFFICES],
            'mediapro' => ['label' => 'MEDIAPRO', 'desc' => 'Media, design and documentation.', 'offices' => AV_TEAM_DIRECTORATE_OFFICES],
            'kcap' => ['label' => 'KCAP', 'desc' => 'Culture, languages and heritage.', 'offices' => AV_TEAM_DIRECTORATE_OFFICES],
            'bec' => ['label' => 'BEC', 'desc' => 'Enterprise, projects and analytics.', 'offices' => AV_TEAM_DIRECTORATE_OFFICES],
            'careerhub' => ['label' => 'Career Hub', 'desc' => 'Preparing people for professional life.', 'offices' => AV_TEAM_DIRECTORATE_OFFICES],
        ]],
    'institutions' => ['short' => 'Institutions', 'label' => 'Institutions', 'desc' => 'The Academy, the Next Generation Genius Club and our community centres.',
        'bodies' => [
            'academy' => ['label' => 'Afrovanguard Academy', 'offices' => AV_TEAM_INSTITUTION_OFFICES],
            'nggc' => ['label' => 'Next Generation Genius Club (NGGC)', 'offices' => AV_TEAM_INSTITUTION_OFFICES],
            'cacentre' => ['label' => 'CACENTREs', 'offices' => AV_TEAM_INSTITUTION_OFFICES],
        ]],
    'volunteers' => ['short' => 'Volunteers', 'label' => 'Volunteers', 'desc' => 'The people who give their time and lead the work on the ground.',
        'bodies' => [
            'executive-volunteers' => ['label' => 'Executive Volunteers', 'offices' => ['executive-volunteer' => 'Executive Volunteer']],
            'official-volunteers' => ['label' => 'Official Volunteers', 'offices' => ['official-volunteer' => 'Official Volunteer']],
        ]],
    'honorary' => ['short' => 'Patrons & advisers', 'label' => 'Honorary Patrons and advisers', 'desc' => 'Friends who lend their counsel, influence and support.',
        'bodies' => ['honorary' => ['label' => 'Honorary Patrons and advisers', 'offices' => [
            'grand-patron' => 'Grand Patron', 'patron' => 'Patron', 'adviser' => 'Adviser']]]],
];

/** body key => [group key, body definition, position in constitutional order]. */
function av_team_bodies(): array
{
    static $b = null;
    if ($b !== null) return $b;
    $b = []; $i = 0;
    foreach (AV_TEAM_STRUCTURE as $g => $grp) foreach ($grp['bodies'] as $k => $def) $b[$k] = ['group' => $g, 'def' => $def, 'rank' => $i++];
    return $b;
}
function av_team_body_label(string $body): string { return av_team_bodies()[$body]['def']['label'] ?? ''; }
function av_team_office_label(string $body, string $office): string { return av_team_bodies()[$body]['def']['offices'][$office] ?? ''; }

/** The directorate a piece of text names (role or group), or ''. */
function av_team_directorate_in(string $text): string
{
    $t = strtolower($text);
    foreach ([
        'sts' => '/\bsts\b|street[\s-]*to[\s-]*stardom/', 'gates' => '/\bafrica\s*gates\b|\bgates\b/', 'techome' => '/\btechome\b/',
        'mediapro' => '/\bmedia\s*pro\b/', 'kcap' => '/\bkcap\b|kingdom and cultural/', 'bec' => '/\bbec\b|business (and|&) enterprise/',
        'careerhub' => '/career\s*hub/',
    ] as $k => $re) if (preg_match($re, $t)) return $k;
    return '';
}

/** The executive office a role names ("CFO", "Chief Technology Officer"), or ''. */
function av_team_exec_office_in(string $role): string
{
    $t = strtolower($role);
    foreach ([
        'president' => '/\bpresident\b|chief servant/', 'coo' => '/\bcoo\b|chief operating/', 'cfo' => '/\bcfo\b|chief financ/',
        'cto' => '/\bcto\b|chief technology/', 'cpio' => '/\bcpio\b|chief programmes?\b/', 'cpco' => '/\bcpco\b|chief people/',
        'cpdo' => '/\bcpdo\b|chief partnerships?\b/', 'cco' => '/\bcco\b|chief communications?\b/', 'crso' => '/\bcrso\b|chief research/',
        'counsel' => '/general counsel|compliance officer/', 'safeguarding' => '/safeguarding/',
    ] as $k => $re) if (preg_match($re, $t)) return $k;
    return '';
}

/**
 * Where somebody sits: ['group', 'body', 'office', 'inferred' => bool].
 *
 * A body (and office) set in the Studio wins. Otherwise — every live row
 * today — it is worked out from the old tier and the role text:
 *   - role names a Chief Officer ("CFO", "Chief Technology Officer") → that office in the Executive;
 *   - role mentions the Governing Council / a Trustee / a Custodian → that body;
 *   - management → the Executive (office unknown, sorted after named offices);
 *   - director → Board of Directors if the role says "Board"; else the Directorate the
 *     role or group names (STS, Africa GATES, Techome, MediaPro, KCAP, BEC, Career Hub);
 *     else the Executive;
 *   - patron → Honorary Patrons and advisers;
 *   - ngv → Afrovanguard Academy; ngg → NGGC;
 *   - volunteer → Official Volunteers (Executive Volunteers if the role says so).
 */
function av_team_placement(array $m): array
{
    $bodies = av_team_bodies();
    $body = trim((string) ($m['body'] ?? ''));
    $office = trim((string) ($m['office'] ?? ''));
    if (isset($bodies[$body])) {
        if (!isset($bodies[$body]['def']['offices'][$office])) $office = '';
        return ['group' => $bodies[$body]['group'], 'body' => $body, 'office' => $office, 'inferred' => false];
    }
    $role = strtolower(trim((string) ($m['role'] ?? '')));
    $tier = (string) ($m['tier'] ?? 'volunteer');
    $at = static fn(string $b, string $o = '') => ['group' => $bodies[$b]['group'], 'body' => $b, 'office' => $o, 'inferred' => true];
    $chair = (bool) preg_match('/\bchair/', $role);

    if ($tier !== 'patron' && ($o = av_team_exec_office_in($role)) !== '') return $at('executive', $o);
    if (str_contains($role, 'governing council')) return $at('council', $chair ? (str_contains($role, 'deputy') ? 'deputy-chair' : 'chair') : 'member');
    if (str_contains($role, 'custodian')) return $at('custodians', 'custodian');
    if (preg_match('/\btrustee/', $role)) return $at('trustees', 'trustee');
    switch ($tier) {
        case 'management': return $at('executive');
        case 'director':
            if (str_contains($role, 'board')) return $at('board', $chair ? 'chair' : 'director');
            $d = av_team_directorate_in($role) ?: av_team_directorate_in((string) ($m['grp'] ?? ''));
            if ($d !== '') {
                $o = str_contains($role, 'deputy') ? 'deputy-director'
                   : (str_contains($role, 'administrator') ? 'administrator'
                   : (preg_match('/financ/', $role) ? 'finance'
                   : (str_contains($role, 'volunteer coordinator') ? 'volunteer-coordinator' : 'project-director')));
                return $at($d, $o);
            }
            return $at('executive');
        case 'patron':
            return $at('honorary', str_contains($role, 'grand') ? 'grand-patron' : (preg_match('/advis/', $role) ? 'adviser' : 'patron'));
        case 'ngv': return $at('academy');
        case 'ngg': return $at('nggc');
        default:
            return str_contains($role, 'executive volunteer') ? $at('executive-volunteers', 'executive-volunteer') : $at('official-volunteers', 'official-volunteer');
    }
}

/** Sort key: constitutional body order, then office order (unknown last), then the Studio's position. */
function av_team_sort_key(array $m): array
{
    $p = av_team_placement($m);
    $offices = array_keys(av_team_bodies()[$p['body']]['def']['offices']);
    $oi = $p['office'] === '' ? 999 : (int) array_search($p['office'], $offices, true);
    return [av_team_bodies()[$p['body']]['rank'], $oi, (int) ($m['position'] ?? 0), !empty($m['featured']) ? 0 : 1, (int) ($m['id'] ?? 0)];
}

/** People sorted into constitutional order. */
function av_team_sort(array $people): array
{
    usort($people, static fn($a, $b) => av_team_sort_key($a) <=> av_team_sort_key($b));
    return $people;
}

/**
 * The line under somebody's name. The directory sync writes role = 'Member'
 * for every auto-enrolled account, so the old card said "VOLUNTEER" on the
 * badge and "Member" underneath. A real role wins; the generic auto value
 * (or none) falls back to the office, then the tier, so the line never
 * contradicts where the person is shown.
 */
function av_person_role_line(array $m): string
{
    $role = trim((string) ($m['role'] ?? ''));
    if ($role !== '' && strcasecmp($role, 'member') !== 0) return $role;
    $p = av_team_placement($m);
    return av_team_office_label($p['body'], $p['office']) ?: av_tier_label((string) ($m['tier'] ?? 'volunteer'));
}

/*
 * Names that are accounts, not people. Two lists, easy to extend:
 *   AV_TEAM_SYSTEM_NAMES — matched against the WHOLE name, normalised
 *     (lower case, punctuation to spaces): "Super Admin", "No-Reply", "Test User"…
 *   AV_TEAM_ORG_WORDS — a name containing "afrovanguard" and made ONLY of these
 *     words is the organisation or a centre ("Afrovanguard", "CACENTRE
 *     AFROVANGUARD", "Afrovanguard Academy", "Afrovanguard HQ").
 * Conservative on purpose — a false positive hides a real volunteer — so
 * "Admin Okafor" or "Esther Afrovanguard-Smith" stay: a given name or surname
 * beside the word makes it a person.
 */
const AV_TEAM_SYSTEM_NAMES = ['admin', 'administrator', 'super admin', 'superadmin', 'site admin', 'sys admin', 'sysadmin',
    'system', 'root', 'webmaster', 'web master', 'postmaster', 'no reply', 'noreply', 'do not reply',
    'test', 'test user', 'test account', 'demo', 'demo user', 'bot', 'community bot', 'info', 'support', 'helpdesk'];
const AV_TEAM_ORG_WORDS = ['afrovanguard', 'cacentre', 'cacentres', 'centre', 'center', 'cac', 'the', 'team', 'official', 'hq',
    'org', 'ng', 'admin', 'office', 'foundation', 'initiative', 'academy', 'community', 'bot', 'lagos', 'chapter', 'nggc'];

/** Whether a team row's name is an account rather than a person (see the lists above). */
function av_team_is_system_account(string $name): bool
{
    $n = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', ' ', $name)));
    if ($n === '') return true;
    if (in_array($n, AV_TEAM_SYSTEM_NAMES, true)) return true;
    $words = explode(' ', $n);
    return in_array('afrovanguard', $words, true) && !array_diff($words, AV_TEAM_ORG_WORDS);
}

/**
 * May this row appear on the PUBLIC directory, a public profile, the public API
 * or site search? All of:
 *   1. active = 1 (the Studio's own show/hide switch);
 *   2. curated by hand (synced = 0), OR auto-synced from an org account AND the
 *      person said yes (listed_consent_at set by av_team_opt_in()). Rows the old
 *      sync inserted with active = 1 and nobody asked stay off the public page
 *      even though the database still says active — consent is read, never
 *      assumed;
 *   3. not a system or organisation account (av_team_is_system_account()).
 * The Studio still lists every row (av_team_rows / av_team_one are unchanged).
 */
function av_team_listable(array $r): bool
{
    if ((int) ($r['active'] ?? 0) !== 1) return false;
    if ((int) ($r['synced'] ?? 0) === 1 && trim((string) ($r['listed_consent_at'] ?? '')) === '') return false;
    return !av_team_is_system_account((string) ($r['name'] ?? ''));
}

/** The rows the public may see, in constitutional order (av_team_sort). */
function av_team_public_rows(PDO $pdo): array
{
    return av_team_sort(array_values(array_filter(av_team_rows($pdo, true), 'av_team_listable')));
}

/** One publicly listable member by id, or the not-found shape (public profile + API). */
function av_team_public_one(PDO $pdo, int $id): array
{
    av_team_ensure($pdo);
    $s = $pdo->prepare('SELECT * FROM team WHERE id = ? AND active = 1');
    $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r || !av_team_listable($r)) return ['status' => 'error', 'error' => 'Not found'];
    return ['status' => 'ok', 'member' => av_team_member_dict($r)];
}

/**
 * The initials avatar's tint: one of six pairs from the site palette, chosen
 * from the name so the same person always gets the same colour. Every pair is
 * at least 4.5:1 (text on background).
 */
function av_person_tint(string $name): int
{
    return (int) (crc32(strtolower(trim($name))) % 6);
}

/** Up to two initials for an avatar ("Ada Okonkwo" → "AO"). */
function av_person_initials(string $name): string
{
    $p = preg_split('/\s+/', trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name))) ?: [];
    $p = array_values(array_filter($p, 'strlen'));
    if (!$p) return '?';
    $a = mb_substr($p[0], 0, 1); $b = count($p) > 1 ? mb_substr($p[count($p) - 1], 0, 1) : '';
    return mb_strtoupper($a . $b);
}

/** Create the team table if missing (idempotent; safe on deployed DBs). */
/*
 * listed_consent_at / listed_consent_text record when somebody agreed to
 * be on the public page, and to what words. Recorded rather than
 * assumed: holding a work address is not consent to be published, and an
 * auditor asking who said yes, when, and to what needs an answer that is
 * not a shrug.
 */
function av_team_ensure(PDO $pdo): void
{
    $ddl = "CREATE TABLE IF NOT EXISTS team (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT '',
        tier TEXT NOT NULL DEFAULT 'volunteer',
        featured INTEGER NOT NULL DEFAULT 0,
        operations INTEGER NOT NULL DEFAULT 0,
        tagline TEXT NOT NULL DEFAULT '',
        bio TEXT NOT NULL DEFAULT '',
        location TEXT NOT NULL DEFAULT '',
        photo_url TEXT NOT NULL DEFAULT '',
        socials TEXT NOT NULL DEFAULT '',
        birthday TEXT NOT NULL DEFAULT '',
        votm_month TEXT NOT NULL DEFAULT '',
        votm_reason TEXT NOT NULL DEFAULT '',
        votm_quote TEXT NOT NULL DEFAULT '',
        position INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1,
        listed_consent_at TEXT NOT NULL DEFAULT '',
        listed_consent_text TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )";
    $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    Database::execSchema($pdo, $ddl);
    // Columns added after the initial release (idempotent — ADD COLUMN errors if
    // it already exists, which we swallow). `email` powers birthday emails;
    // `grp` lets admins group members; `synced` marks rows auto-created from an
    // @afrovanguard.org.ng account so a manual edit is never overwritten.
    foreach ([
        "ALTER TABLE team ADD COLUMN email TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE team ADD COLUMN grp TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE team ADD COLUMN synced INTEGER NOT NULL DEFAULT 0",
        /* An install that predates the opt-in needs these too, or
           av_team_opt_in() writes to a column that is not there and the
           consent is lost silently. */
        "ALTER TABLE team ADD COLUMN listed_consent_at TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE team ADD COLUMN listed_consent_text TEXT NOT NULL DEFAULT ''",
        /* Where somebody sits in the constitutional structure (AV_TEAM_STRUCTURE):
           a body key and an office key. Blank = not placed yet, and
           av_team_placement() works it out from the tier and role. */
        "ALTER TABLE team ADD COLUMN body TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE team ADD COLUMN office TEXT NOT NULL DEFAULT ''",
    ] as $sql) {
        /* Through execSchema, not a bare exec: these are SQLite DDL, and a
           literal default on TEXT is error 1101 on MySQL 8 until translateDDL
           has rewritten it. A column already present is a benign error there. */
        try { Database::execSchema($pdo, $sql); } catch (\Throwable $e) { /* column already present */ }
    }
}

/**
 * Auto-enrol @afrovanguard.org.ng members onto the People directory.
 *
 * Every verified org account becomes a team row (matched by email) if one does
 * not already exist — so members appear on /people automatically. Existing rows
 * are left untouched (admins can rename, re-tier, group, feature or hide them);
 * we only refresh the display name of still-auto rows that haven't been edited.
 * Idempotent and cheap — safe to call on every directory render.
 */
function av_team_sync_org_members(PDO $pdo): int
{
    av_team_ensure($pdo);
    if (!Database::tableExists('lms_users')) return 0;
    $domain = strtolower((string) (defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng'));
    // Existing team emails (lower-cased) so we never double-insert.
    $have = [];
    foreach ($pdo->query("SELECT LOWER(email) e FROM team WHERE email <> ''")->fetchAll(PDO::FETCH_COLUMN) as $e) { $have[$e] = true; }
    // Verified org accounts. Tolerate schemas without an explicit verified flag.
    $verCol = Database::columnExists('lms_users', 'email_verified') ? 'email_verified'
            : (Database::columnExists('lms_users', 'verified') ? 'verified' : '');
    $sql = "SELECT id, name, email, role FROM lms_users WHERE LOWER(email) LIKE " . $pdo->quote('%@' . $domain);
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $added = 0; $now = gmdate('Y-m-d H:i:s');
    /*
     * active = 0. HIDDEN until the person says yes.
     *
     * This inserted with active = 1, so every load of /people quietly
     * published anybody holding an organisation address — their name and
     * their photograph, on a public page, with nobody asked and nobody
     * told. Having a work email is not consent to be published, and the
     * page is the first place most people would ever find out.
     *
     * The row is still created, because the directory is genuinely useful
     * to the Studio and because a row that exists can be opted into. It
     * simply is not public until av_team_opt_in() records that somebody
     * agreed, and to what wording.
     */
    $ins = $pdo->prepare("INSERT INTO team (name, role, tier, email, grp, synced, active, position, created_at) VALUES (?,?,?,?,?,1,0,?,?)");
    foreach ($rows as $r) {
        $email = strtolower(trim((string) $r['email']));
        if ($email === '' || isset($have[$email])) continue;
        $name = trim((string) $r['name']) ?: ucfirst(explode('@', $email)[0]);
        $tier = in_array((string) ($r['role'] ?? ''), ['admin', 'director', 'management'], true) ? 'management' : 'volunteer';
        try { $ins->execute([$name, 'Member', $tier, $email, 'Members', 9999, $now]); $added++; $have[$email] = true; }
        catch (\Throwable $e) { error_log('[people] sync: ' . $e->getMessage()); }
    }
    return $added;
}

/**
 * The wording somebody is agreeing to. One string, so the record of what
 * they agreed to cannot drift from what they were shown.
 */
const AV_TEAM_LISTING_CONSENT = 'Show my name, role and photo on the public People page at /people. '
    . 'I can take it down again at any time from my portal.';

/**
 * Put somebody on the public page because they said so.
 *
 * Returns false when there is no row for that address — which is not an
 * error worth shouting about: the directory sync creates it, and somebody
 * who has never signed in has nothing to opt in.
 */
function av_team_opt_in(PDO $pdo, string $email, string $wording = AV_TEAM_LISTING_CONSENT): bool
{
    av_team_ensure($pdo);
    $email = strtolower(trim($email));
    if ($email === '') return false;

    $st = $pdo->prepare("UPDATE team SET active = 1, listed_consent_at = ?, listed_consent_text = ?
                          WHERE LOWER(email) = ?");
    $st->execute([gmdate('Y-m-d H:i:s'), mb_substr($wording, 0, 500), $email]);

    return $st->rowCount() > 0;
}

/**
 * Take somebody off it.
 *
 * The consent record is CLEARED, not kept with a flag beside it: the
 * question the row answers is "may we publish this person", and a stale
 * yes sitting next to a no is the kind of thing that gets read the wrong
 * way at the wrong moment. That they were once listed is in the audit
 * trail, which is where a history belongs.
 */
function av_team_opt_out(PDO $pdo, string $email): bool
{
    av_team_ensure($pdo);
    $email = strtolower(trim($email));
    if ($email === '') return false;

    $st = $pdo->prepare("UPDATE team SET active = 0, listed_consent_at = '', listed_consent_text = ''
                          WHERE LOWER(email) = ?");
    $st->execute([$email]);

    return $st->rowCount() > 0;
}

/**
 * Whether somebody is listed, and what they agreed to — for the portal
 * control that lets them change their mind.
 *
 * @return array{known:bool, listed:bool, at:string, wording:string}
 */
function av_team_listing_state(PDO $pdo, string $email): array
{
    av_team_ensure($pdo);
    $st = $pdo->prepare('SELECT active, listed_consent_at, listed_consent_text FROM team WHERE LOWER(email) = ? LIMIT 1');
    $st->execute([strtolower(trim($email))]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return [
        'known' => (bool) $row,
        'listed' => $row ? (int) $row['active'] === 1 : false,
        'at' => $row ? (string) $row['listed_consent_at'] : '',
        'wording' => AV_TEAM_LISTING_CONSENT,
    ];
}

/** Distinct non-empty groups among the publicly listed people, in display order. */
function av_team_groups(PDO $pdo): array
{
    av_team_ensure($pdo);
    /* From the public rows, so a group made only of hidden accounts is not
       offered as a filter that shows nobody. */
    $out = [];
    foreach (av_team_public_rows($pdo) as $r) {
        $g = trim((string) ($r['grp'] ?? ''));
        if ($g !== '') $out[$g] = true;
    }
    $out = array_keys($out);
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/** Normalise/validate a tier string. */
function av_team_tier(string $t): string
{
    $t = strtolower(trim($t));
    return in_array($t, AV_TEAM_TIERS, true) ? $t : 'volunteer';
}

/** A DB row → the public member shape consumed by the front-end. */
function av_team_member_dict(array $r): array
{
    $socials = [];
    if (!empty($r['socials'])) {
        $d = json_decode((string) $r['socials'], true);
        if (is_array($d)) {
            foreach ($d as $k => $v) { if (is_string($v) && trim($v) !== '') $socials[$k] = trim($v); }
        }
    }
    return [
        'id'         => (int) $r['id'],
        'name'       => (string) $r['name'],
        'role'       => (string) $r['role'],
        'tier'       => av_team_tier((string) $r['tier']),
        'featured'   => (bool) (int) $r['featured'],
        'operations' => (bool) (int) $r['operations'],
        'tagline'    => (string) $r['tagline'],
        'bio'        => (string) $r['bio'],
        'location'   => (string) $r['location'],
        'photo'      => (string) $r['photo_url'],
        'birthday'   => (string) $r['birthday'],
        'email'      => (string) ($r['email'] ?? ''),
        'grp'        => (string) ($r['grp'] ?? ''),
        'body'       => (string) ($r['body'] ?? ''),
        'office'     => (string) ($r['office'] ?? ''),
        'position'   => (int) ($r['position'] ?? 0),
        'active'     => (bool) (int) ($r['active'] ?? 1),
        'socials'    => $socials,
    ];
}

/** All rows (optionally active-only), ordered for display. */
function av_team_rows(PDO $pdo, bool $activeOnly = true): array
{
    av_team_ensure($pdo);
    $sql = 'SELECT * FROM team' . ($activeOnly ? ' WHERE active = 1' : '')
         . ' ORDER BY position ASC, featured DESC, id ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Public members payload (matches the legacy api.php contract): listable rows only. */
function av_team_members(PDO $pdo): array
{
    return ['status' => 'ok', 'members' => array_map('av_team_member_dict', av_team_public_rows($pdo))];
}

/**
 * The public shape of a team member: everything the directory shows, minus the
 * private address birthday emails go to and the birthday itself. The public
 * API served both to anyone, though nothing on the site displays either and
 * process-wish.php exists precisely so a visitor never sees that address.
 */
function av_team_public_dict(array $m): array
{
    unset($m['email'], $m['birthday']);
    return $m;
}

/** A single active member by id, listable or not — the Studio's read. Public pages use av_team_public_one(). */
function av_team_one(PDO $pdo, int $id): array
{
    av_team_ensure($pdo);
    $s = $pdo->prepare('SELECT * FROM team WHERE id = ? AND active = 1');
    $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['status' => 'error', 'error' => 'Not found'];
    return ['status' => 'ok', 'member' => av_team_member_dict($r)];
}

/** Current Volunteer of the Month: this month's pick, else the most recent. */
function av_votm(PDO $pdo, ?string $month = null): array
{
    av_team_ensure($pdo);
    $month = $month ?: date('Y-m');
    /* The newest pick who may be shown publicly (av_team_listable): the
       highlight links to their profile, which would 404 for anybody else. */
    $s = $pdo->prepare("SELECT * FROM team WHERE active = 1 AND votm_month <> '' AND votm_month <= ? ORDER BY votm_month DESC, id DESC LIMIT 25");
    $s->execute([$month]);
    $r = null;
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cand) { if (av_team_listable($cand)) { $r = $cand; break; } }
    if (!$r) return ['status' => 'ok', 'votm' => null];
    [$yy, $mm] = array_pad(explode('-', (string) $r['votm_month']), 2, '');
    $m = av_team_member_dict($r);
    return ['status' => 'ok', 'votm' => [
        'id'       => $m['id'],
        'name'     => $m['name'],
        'role'     => $m['role'],
        'tier'     => $m['tier'],
        'photo'    => $m['photo'],
        'location' => $m['location'],
        'month'    => (int) ($mm ?: date('n')),
        'year'     => (int) ($yy ?: date('Y')),
        'reason'   => (string) $r['votm_reason'],
        'quote'    => (string) $r['votm_quote'],
    ]];
}

/** Active people whose birthday (MM-DD) matches the given date (default today). */
function av_birthdays_on(PDO $pdo, ?string $mmdd = null): array
{
    av_team_ensure($pdo);
    $mmdd = $mmdd ?: date('m-d');
    $s = $pdo->prepare("SELECT * FROM team WHERE active = 1 AND birthday = ?");
    $s->execute([$mmdd]);
    return array_map('av_team_member_dict', $s->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/**
 * Send each of today's birthday people a personalised birthday email — once
 * per person per day, idempotently. Safe to call from cron AND lazily from a
 * page request: a per-day "sent" ledger in app_meta guarantees no duplicates
 * even if both fire. Returns the number of emails actually sent this call.
 */
function av_birthday_emails_run(PDO $pdo): int
{
    $people = av_birthdays_on($pdo);
    if (!$people) return 0;

    $today = date('Y-m-d');
    $sent = [];
    try {
        $raw = class_exists('Database') ? Database::metaGet('bday_sent') : null;
        $d = $raw ? json_decode($raw, true) : null;
        if (is_array($d) && ($d['date'] ?? '') === $today && is_array($d['ids'] ?? null)) {
            $sent = array_map('intval', $d['ids']);
        }
    } catch (\Throwable $e) { /* start fresh */ }

    $n = 0;
    foreach ($people as $p) {
        $id = (int) ($p['id'] ?? 0);
        $email = trim((string) ($p['email'] ?? ''));
        if ($id <= 0 || $email === '' || in_array($id, $sent, true)) continue;
        if (class_exists('Notify') && method_exists('Notify', 'birthday')) {
            Notify::birthday($p);         // best-effort; Notify swallows failures
        }
        $sent[] = $id; $n++;
    }
    if ($n > 0) {
        try { Database::metaSet('bday_sent', json_encode(['date' => $today, 'ids' => array_values(array_unique($sent))])); }
        catch (\Throwable $e) { /* best-effort */ }
    }
    return $n;
}

/** Persist a member (insert or update). Returns the id. */
function av_team_save(PDO $pdo, array $in): int
{
    av_team_ensure($pdo);
    $socials = is_array($in['socials'] ?? null) ? json_encode($in['socials']) : (string) ($in['socials'] ?? '');
    $fields = [
        'name' => trim((string) ($in['name'] ?? '')),
        'role' => trim((string) ($in['role'] ?? '')),
        'tier' => av_team_tier((string) ($in['tier'] ?? 'volunteer')),
        'featured' => !empty($in['featured']) ? 1 : 0,
        'operations' => !empty($in['operations']) ? 1 : 0,
        'tagline' => trim((string) ($in['tagline'] ?? '')),
        'bio' => trim((string) ($in['bio'] ?? '')),
        'location' => trim((string) ($in['location'] ?? '')),
        'photo_url' => trim((string) ($in['photo'] ?? $in['photo_url'] ?? '')),
        'grp' => trim((string) ($in['grp'] ?? '')),
        /* Only keys from AV_TEAM_STRUCTURE; an office must belong to its body. */
        'body' => $body = (isset(av_team_bodies()[trim((string) ($in['body'] ?? ''))]) ? trim((string) $in['body']) : ''),
        'office' => ($body !== '' && av_team_office_label($body, trim((string) ($in['office'] ?? ''))) !== '') ? trim((string) $in['office']) : '',
        'socials' => $socials,
        'email' => filter_var(trim((string) ($in['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
        'birthday' => preg_match('/^\d{2}-\d{2}$/', (string) ($in['birthday'] ?? '')) ? $in['birthday'] : '',
        'votm_month' => preg_match('/^\d{4}-\d{2}$/', (string) ($in['votm_month'] ?? '')) ? $in['votm_month'] : '',
        'votm_reason' => trim((string) ($in['votm_reason'] ?? '')),
        'votm_quote' => trim((string) ($in['votm_quote'] ?? '')),
        'position' => (int) ($in['position'] ?? 0),
        'active' => isset($in['active']) ? (!empty($in['active']) ? 1 : 0) : 1,
    ];
    $id = (int) ($in['id'] ?? 0);
    if ($id > 0) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        $stmt = $pdo->prepare("UPDATE team SET $set WHERE id = :id");
        $stmt->execute($fields + ['id' => $id]);
        return $id;
    }
    $cols = implode(', ', array_keys($fields));
    $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
    $pdo->prepare("INSERT INTO team ($cols) VALUES ($ph)")->execute($fields);
    return (int) $pdo->lastInsertId();
}

function av_team_delete(PDO $pdo, int $id): void
{
    av_team_ensure($pdo);
    $pdo->prepare('DELETE FROM team WHERE id = ?')->execute([$id]);
}
