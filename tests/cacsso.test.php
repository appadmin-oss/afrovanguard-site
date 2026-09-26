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
ck('portal/crm.php: mints on the click, not at page render — a token baked into the dashboard '
 . 'would be dead by the time anybody read the page and clicked',
   str_contains($portal, 'CacSso::linkFor($u, $next)') && !str_contains($portal, 'echo CacSso::mint'));
ck('portal/crm.php: an unauthenticated visitor is sent to sign in and back here, not to the dashboard',
   str_contains($portal, "av_login_url(\$self)"));
ck('portal/crm.php: it does not try to decide CRM access itself — that rule lives on the side that holds the data',
   !preg_match('/isOrgMember|atLeast|canManageMembers/', $portal));

/* ── The link is hidden when the bridge is off ──────────────────────────── */
$dash = (string) @file_get_contents(AV_ROOT . '/portal/index.php');
ck('portal: the CRM link only shows when a secret is set',
   str_contains($dash, 'CacSso::ready()') && str_contains($dash, '/portal/crm.php'));

/* ── The format is mirrored, and the mirror is named ────────────────────── */
ck('CacSso: the file names its counterpart, because a wire format defined twice drifts',
   str_contains($src, 'CrmSso.php'));
