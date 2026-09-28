<?php
/**
 * tests/cacsso.test.php — handing a signed-in member to the CACENTRE CRM.
 *
 * The assertions here are about one distinction, because that is where this
 * feature would go badly wrong: this site says WHO somebody is, and never what
 * they may do. The portal has learners, mentors and instructors on it — people
 * with every right to be here and none to be in a pipeline holding ninety
 * people's phone numbers. If permission were read off this token, adding a
 * role here would silently widen access there.
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/CacSso.php';

$src    = (string) @file_get_contents(AV_ROOT . '/lib/CacSso.php');
$portal = (string) @file_get_contents(AV_ROOT . '/portal/crm.php');
$door   = (string) @file_get_contents(AV_ROOT . '/cacentre.php');

/* ── Off unless a real secret is set ────────────────────────────────────── */
putenv('AV_SSO_SECRET=' . str_repeat('k', 48));
ck('CacSso: a 48-character secret is usable', CacSso::ready());
foreach (['' => 'empty', 'short' => 'too short', 'changeme-changeme-changeme-changeme' => 'a placeholder'] as $bad => $why) {
    putenv('AV_SSO_SECRET=' . $bad);
    ck("CacSso: $why counts as unset — a half-set key that shows the link and fails on every click is worse than no link",
       !CacSso::ready());
}
putenv('AV_SSO_SECRET=' . str_repeat('k', 48));

/* ── The assertion ──────────────────────────────────────────────────────── */
$u = ['id' => 7, 'email' => 'Ada@Afrovanguard.org.NG', 'name' => 'Ada Obi', 'role' => 'coordinator'];
$t = CacSso::mint($u);

ck('CacSso: the token is v1.<payload>.<sig>', substr_count($t, '.') === 2 && str_starts_with($t, 'v1.'));

$claims = json_decode((string) base64_decode(strtr(explode('.', $t)[1], '-_', '+/')), true);
ck('CacSso: the email is normalised to lower case, so the two sites agree on identity',
   ($claims['sub'] ?? '') === 'ada@afrovanguard.org.ng');
ck('CacSso: it names this site as the issuer', ($claims['iss'] ?? '') === 'av');
ck('CacSso: it lives 60 seconds — it travels in a URL, and a URL lands in history, Referer headers and access logs',
   ((int) $claims['exp'] - (int) $claims['iat']) === 60 && CacSso::TTL === 60);
ck('CacSso: every mint carries a fresh nonce, so the other side can refuse a replay',
   ($claims['nonce'] ?? '') !== ''
   && $claims['nonce'] !== json_decode((string) base64_decode(strtr(explode('.', CacSso::mint($u))[1], '-_', '+/')), true)['nonce']);

/* Signed, not merely encoded. */
$body = explode('.', $t)[1];
$want = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, str_repeat('k', 48), true)), '+/', '-_'), '=');
ck('CacSso: the signature is an HMAC over the payload with the shared secret',
   explode('.', $t)[2] === $want);

/* ── The redirect cannot be aimed off-site ──────────────────────────────── */
foreach (['https://evil.example.com/x', '//evil.example.com', 'javascript:alert(1)', ''] as $bad) {
    $q = [];
    parse_str((string) parse_url(CacSso::linkFor($u, $bad), PHP_URL_QUERY), $q);
    ck("CacSso: next=" . ($bad === '' ? '(empty)' : $bad) . " is refused and falls back to /crm/ — a redirect that "
     . 'follows anything it is handed is a phishing link that genuinely starts on cacentre.afrovanguard.org.ng',
       ($q['next'] ?? '') === '/crm/');
}
$q = [];
parse_str((string) parse_url(CacSso::linkFor($u, '/crm/leads.php'), PHP_URL_QUERY), $q);
ck('CacSso: a local path is passed through', ($q['next'] ?? '') === '/crm/leads.php');

/* ── Who, not what ──────────────────────────────────────────────────────── */
ck('CacSso: the role is sent for the record, and this side never decides access with it',
   !preg_match('/(?:if|&&|\|\|)[^\n]*\$u\[.role.\]/', $src));
/* The handoff moved to /cacentre — one door, reachable from the whole site
   rather than from one sidebar. portal/crm.php stays as an address because
   links outlive the page that made them, and forwards rather than keeping a
   second copy that would drift. */
ck('cacentre.php: mints on the click, not at page render — a token baked into a page '
 . 'would be dead by the time anybody read it and clicked',
   str_contains($door, 'CacSso::linkFor($u, $to)') && !str_contains($door, 'echo CacSso::mint'));
ck('cacentre.php: an unauthenticated visitor is sent to sign in and back to the door',
   str_contains($door, 'av_login_url(CacSso::door($to))'));
ck('cacentre.php: and it loads the file av_login_url() lives in — the version of this '
 . 'in portal/crm.php called it without loading it, so arriving signed out, the one '
 . 'case the branch existed for, was a fatal error',
   str_contains($door, "require_once __DIR__ . '/lib/partials.php';"));
ck('portal/crm.php: forwards to the door rather than keeping its own copy of the handoff',
   str_contains($portal, 'CacSso::door(') && !str_contains($portal, 'CacSso::linkFor('));
ck('portal/crm.php: still honours the next it was given, under either spelling',
   str_contains($portal, "\$_GET['next'] ?? \$_GET['to']"));
ck('the door does not try to decide CRM access itself — that rule lives on the side that holds the data',
   !preg_match('/isOrgMember|atLeast|canManageMembers/', $portal . $door));

/* ── The link is hidden when the bridge is off ──────────────────────────── */
$dash = (string) @file_get_contents(AV_ROOT . '/portal/index.php');
ck('portal: the workspace link only shows when a secret is set',
   str_contains($dash, 'CacSso::ready()') && str_contains($dash, 'CacSso::DOOR'));
ck('portal: and it is named for the place, not for one screen in it — behind it are '
 . 'the pipeline, the tasks, the register and writing for the site',
   str_contains($dash, 'CACENTRE workspace') && !str_contains($dash, '>CRM<'));

/* ── The format is mirrored, and the mirror is named ────────────────────── */
ck('CacSso: the file names its counterpart, because a wire format defined twice drifts',
   str_contains($src, 'CrmSso.php'));

/* ── What may cross as a destination ──────────────────────────────────────
   `to` arrives from whatever link somebody clicked and ends up in a Location
   header on a host that trusts this one. An open redirect here is a phishing
   link that genuinely begins on cacentre.afrovanguard.org.ng. */
foreach ([
    ''                          => CacSso::HOME,
    '/'                         => '/',
    '/crm/leads.php'            => '/crm/leads.php',
    '/crm/a?b=c#d'              => '/crm/a?b=c#d',
    '//evil.test'               => CacSso::HOME,   // protocol-relative: a host
    '/\\evil.test'              => CacSso::HOME,   // the same after a browser folds the slash
    'https://evil.test'         => CacSso::HOME,   // a host said out loud
    'crm/leads.php'             => CacSso::HOME,   // relative to nothing in particular
    "/crm/x\r\nSet-Cookie: a=b" => CacSso::HOME,   // a second header
    "/crm/x\nLocation: /evil"   => CacSso::HOME,
] as $in => $want) {
    ck('CacSso::path() refuses or keeps ' . json_encode($in) . ' → ' . $want,
       CacSso::path((string) $in) === $want);
}
ck('CacSso::door() points at the door, never at a minted link — an assertion lives '
 . 'sixty seconds and one printed into a page starts expiring as it renders',
   CacSso::door() === '/cacentre'
   && CacSso::door('/crm/leads.php') === '/cacentre?to=' . rawurlencode('/crm/leads.php')
   && !str_contains(CacSso::door('/crm/'), 'v1.'));
ck('CacSso::door() runs its destination through the same rule',
   CacSso::door('https://evil.test') === '/cacentre');
ck('linkFor() uses that one rule rather than repeating it',
   str_contains($src, 'self::path($next)') && substr_count($src, "str_starts_with(\$p, '//')") === 1);

/* ── The door is reachable, and routed on both the live host and in dev ──── */
$ht  = (string) @file_get_contents(AV_ROOT . '/.htaccess');
$rtr = (string) @file_get_contents(AV_ROOT . '/router.php');
ck('.htaccess routes /cacentre and /cacentre/<path> to the door',
   str_contains($ht, 'RewriteRule ^cacentre/?$ cacentre.php')
   && str_contains($ht, 'RewriteRule ^cacentre/(.+)$ cacentre.php?p=$1'));
ck('router.php routes the same two for the dev server, so a route cannot be true '
 . 'in production and missing locally',
   str_contains($rtr, "^/cacentre/?\$") && str_contains($rtr, "\$_GET['p'] = \$m[1];"));
/* The door is not advertised on the public site. Nearly everybody who would
   see a footer link cannot use it — CACENTRE refuses anyone without a CRM
   scope — so a link on every page is a door to a polite dead end. It is in
   the member portal, where the people who can use it already are. */
$partials = (string) @file_get_contents(AV_ROOT . '/lib/partials.php');
ck('the public footer does not advertise the door',
   !str_contains($partials, 'CacSso::DOOR') && !preg_match('~href="/cacentre~', $partials));
ck('nor does the sign-in page',
   !str_contains((string) @file_get_contents(AV_ROOT . '/login/index.php'), 'CacSso::door'));
