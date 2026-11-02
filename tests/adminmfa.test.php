<?php
/**
 * tests/adminmfa.test.php — a second factor on the Studio (lib/AdminMfa).
 *
 * Ported from NGG, where every part of it was a fix:
 *
 *   • Enrolment is ONCE. Writing a fresh secret over an existing one, from a
 *     session that has not yet passed the factor, makes the factor optional:
 *     sign in with the password, enrol again, satisfy the gate with the secret
 *     you chose a moment ago.
 *   • Eight backup codes, hashed, each good once.
 *   • Attempts are counted against the ADMIN, not the address, because a
 *     per-IP ceiling buys another handful of guesses at a six-digit space.
 *   • The "passed" flag lives INSIDE the signed admin cookie, and the gate is
 *     av_admin_role() — so require_admin() and every page that asks for a role
 *     is gated by one sentence rather than forty.
 *   • The break-glass ADMIN_TOKEN is exempt, deliberately.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$mfResetAuth = static function (): void {
    $r = new ReflectionClass('LmsAuth');
    foreach (['cache' => null, 'checked' => false] as $k => $v) { $pp = $r->getProperty($k); $pp->setAccessible(true); $pp->setValue(null, $v); }
};
$mfDb = Database::pdo();
AdminMfa::ensure();
$mfEmail = 'mfa.admin.' . bin2hex(random_bytes(3)) . '@afrovanguard.org.ng';
$mfOther = 'mfa.boss.' . bin2hex(random_bytes(3)) . '@afrovanguard.org.ng';
$mfDb->prepare('INSERT INTO lms_users (name, email, password_hash, role, status, email_verified) VALUES (?,?,?,?,?,1)')
     ->execute(['Mfa Admin', $mfEmail, 'x', 'member', 'active']);
$mfUid = (int) $mfDb->lastInsertId();
$mfTok = bin2hex(random_bytes(20));
$mfDb->prepare('INSERT INTO lms_sessions (token_hash, user_id, ip, ua, expires_at) VALUES (?,?,?,?,?)')
     ->execute([hash('sha256', $mfTok), $mfUid, '', '', gmdate('Y-m-d H:i:s', time() + 3600)]);
$mfDb->prepare('DELETE FROM admin_mfa WHERE email IN (?,?)')->execute([$mfEmail, $mfOther]);

/* ── The TOTP itself ──────────────────────────────────────────────────── */
$mfSecret = AdminMfa::randomSecret(20);
ck('mfa: a secret is base32 and long enough to be one', preg_match('/^[A-Z2-7]{32}$/', $mfSecret) === 1);
$mfStep = intdiv(time(), 30);
ck('mfa: the code for this moment verifies', AdminMfa::totpValid($mfSecret, AdminMfa::code($mfSecret, $mfStep)));
ck('mfa: …and one step either side, because clocks drift',
    AdminMfa::totpValid($mfSecret, AdminMfa::code($mfSecret, $mfStep - 1))
    && AdminMfa::totpValid($mfSecret, AdminMfa::code($mfSecret, $mfStep + 1)));
ck('mfa: …but not two steps away', !AdminMfa::totpValid($mfSecret, AdminMfa::code($mfSecret, $mfStep - 2)));
ck('mfa: a code for another secret does not pass', !AdminMfa::totpValid($mfSecret, AdminMfa::code(AdminMfa::randomSecret(20), $mfStep)));
ck('mfa: and nor does anything that is not six digits',
    !AdminMfa::totpValid($mfSecret, '') && !AdminMfa::totpValid($mfSecret, '12345') && !AdminMfa::totpValid($mfSecret, 'abcdef'));
/* RFC 6238's own vectors, so the implementation is right and not merely
   self-consistent. The SHA-1 seed is the ASCII "12345678901234567890", which
   is GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ in base32; the published table gives
   94287082 at T=59 (step 1), 07081804 at T=1111111109 (step 37037036) and
   89005924 at T=1234567890 (step 41152263), of which this takes six digits. */
$mfRfc = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
ck('mfa: it is RFC 6238, checked against the specification\'s own vectors',
    AdminMfa::base32Decode($mfRfc) === '12345678901234567890'
    && AdminMfa::code($mfRfc, 1) === '287082'
    && AdminMfa::code($mfRfc, 37037036) === '081804'
    && AdminMfa::code($mfRfc, 41152263) === '005924');

/* ── Enrolment is once ────────────────────────────────────────────────── */
ck('mfa: only a Studio role enrols', (AdminMfa::enrol($mfEmail, '')['code'] ?? '') === 'not_admin');
$mfSetup = AdminMfa::enrol($mfEmail, 'admin');
ck('mfa: enrolling returns a secret, a QR and eight backup codes', !empty($mfSetup['ok'])
    && preg_match('/^[A-Z2-7]{32}$/', (string) $mfSetup['secret']) === 1
    && count($mfSetup['backup_codes']) === AdminMfa::BACKUP_CODES
    && str_contains((string) $mfSetup['otpauth'], 'otpauth://totp/'));
ck('mfa: the QR is drawn here, so the secret never goes to a chart service',
    str_contains((string) ($mfSetup['qr_svg'] ?? ''), '<svg'));
ck('mfa: …and the account is enrolled', AdminMfa::isEnrolled($mfEmail));
$mfWas = (string) $mfSetup['secret'];
$mfAgain = AdminMfa::enrol($mfEmail, 'admin');
ck('mfa: enrolling twice is refused — that is how a second factor becomes optional',
    empty($mfAgain['ok']) && ($mfAgain['code'] ?? '') === 'already_enrolled');
ck('mfa: …and the secret already in the authenticator is untouched',
    (string) (AdminMfa::row($mfEmail)['secret'] ?? '') === $mfWas);
/* The PRIMARY KEY would refuse the second insert on its own, so the explicit
   check earns its place by SAYING SO: a second attempt on an enrolled account
   is either somebody who has lost their phone and needs telling where the
   door is, or somebody else at their desk — and both belong in the trail. */
ck('mfa: …and the refusal is recorded, not just returned',
    (bool) $mfDb->query("SELECT 1 FROM lms_audit WHERE action = 'mfa_enrol_refused' AND target = " . $mfDb->quote($mfEmail))->fetchColumn());

/* ── Passing it ───────────────────────────────────────────────────────── */
ck('mfa: somebody with no enrolment has nothing to verify',
    (AdminMfa::verify($mfOther, '123456')['code'] ?? '') === 'not_enrolled');
ck('mfa: the code from the authenticator passes', !empty(AdminMfa::verify($mfEmail, AdminMfa::code($mfWas, intdiv(time(), 30)))['ok']));
$mfBackup = $mfSetup['backup_codes'][0];
$mfB = AdminMfa::verify($mfEmail, $mfBackup);
ck('mfa: a backup code passes too', !empty($mfB['ok']) && !empty($mfB['backup_used'])
    && (int) $mfB['backup_remaining'] === AdminMfa::BACKUP_CODES - 1);
ck('mfa: …once', (AdminMfa::verify($mfEmail, $mfBackup)['code'] ?? '') === 'invalid_code');
ck('mfa: a backup code is taken however it is typed',
    !empty(AdminMfa::verify($mfEmail, strtolower(str_replace('-', ' ', (string) $mfSetup['backup_codes'][1])))['ok']));

/* ── The ceiling is on the admin, not the address ─────────────────────── */
for ($i = 0; $i < AdminMfa::LOCK_AFTER - 1; $i++) AdminMfa::verify($mfEmail, '000000');
ck('mfa: wrong codes short of the ceiling are just wrong', AdminMfa::lockedFor($mfEmail) === 0);
$mfLock = AdminMfa::verify($mfEmail, '000000');
ck('mfa: the ' . AdminMfa::LOCK_AFTER . 'th wrong code buys a pause', ($mfLock['code'] ?? '') === 'too_many_attempts' && AdminMfa::lockedFor($mfEmail) > 0);
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(2, 250);
ck('mfa: …which a different address does not get around',
    (AdminMfa::verify($mfEmail, AdminMfa::code($mfWas, intdiv(time(), 30)))['code'] ?? '') === 'too_many_attempts');
$mfDb->prepare('DELETE FROM lms_login_throttle WHERE ident = ?')->execute(['mfa:' . sha1($mfEmail)]);
ck('mfa: …and a right code works again once the pause is over', !empty(AdminMfa::verify($mfEmail, AdminMfa::code($mfWas, intdiv(time(), 30)))['ok']));

/* ── The gate ─────────────────────────────────────────────────────────── */
unset($_COOKIE[AV_ADMIN_COOKIE]);
$_COOKIE[LmsAuth::COOKIE] = $mfTok; $mfResetAuth();
AdminRoles::add($mfEmail, 'admin', 'test');
ck('mfa: the admin holds their role', AdminRoles::current() === 'admin');
ck('mfa: …but may not act with it until the factor is passed', av_admin_role() === '' && !AdminRoles::can('editor'));
ck('mfa: the Studio can tell "needs a code" from "not an admin"',
    AdminMfa::status($mfEmail, AdminRoles::current(), AdminRoles::mfaPassed())['need'] === 'code');
AdminRoles::markMfaPassed();
ck('mfa: once passed, the role acts', av_admin_role() === 'admin' && AdminRoles::can('editor') && AdminRoles::mfaPassed());
ck('mfa: and the flag rides inside the signature, where the browser cannot set it',
    (av_admin_cookie_parse()['mfa'] ?? null) === true
    && count(explode('.', (string) $_COOKIE[AV_ADMIN_COOKIE])) === 6);
/* Flip the flag in the cookie by hand: the MAC covers it. */
$mfParts = explode('.', (string) $_COOKIE[AV_ADMIN_COOKIE]);
$mfParts[4] = '1';
$mfForged = implode('.', [$mfParts[0], $mfParts[1], $mfParts[2], $mfParts[3], '1', $mfParts[5]]);
$_COOKIE[AV_ADMIN_COOKIE] = av_admin_cookie_value((int) $mfParts[0], $mfParts[1], 'admin', 'm', av_secret(), false);
$mfFlipped = explode('.', (string) $_COOKIE[AV_ADMIN_COOKIE]);
$_COOKIE[AV_ADMIN_COOKIE] = implode('.', [$mfFlipped[0], $mfFlipped[1], $mfFlipped[2], $mfFlipped[3], '1', $mfFlipped[5]]);
ck('mfa: turning the flag on in the cookie invalidates it altogether', av_admin_cookie_parse() === null);
/* A bridged cookie refreshed because the ROLE changed keeps the factor. */
AdminRoles::markMfaPassed();
AdminRoles::add($mfEmail, 'superadmin', 'test');
ck('mfa: a role change re-bridges without asking for the code again',
    AdminRoles::current() === 'superadmin' && av_admin_role() === 'superadmin');

/* ── A lost authenticator ─────────────────────────────────────────────── */
ck('mfa: nobody resets their own second factor', (AdminMfa::reset($mfEmail, $mfEmail)['code'] ?? '') === 'own_account');
ck('mfa: another Super Admin can', !empty(AdminMfa::reset($mfEmail, $mfOther)['ok']) && !AdminMfa::isEnrolled($mfEmail));
ck('mfa: …and the Studio then asks them to set one up',
    AdminMfa::status($mfEmail, 'admin', false)['need'] === 'enrol');
ck('mfa: …which they now can, because there is nothing behind it', !empty(AdminMfa::enrol($mfEmail, 'admin')['ok']));

/* ── The break-glass token is exempt, on purpose ──────────────────────── */
$mfTokenRole = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    'require ' . var_export(AV_ROOT . '/lib/bootstrap.php', true) . ';'
    . ' if (!defined("ADMIN_TOKEN")) define("ADMIN_TOKEN", "mfa-test-token-0123456789abcdef0123456789");'
    . ' $_COOKIE[AV_ADMIN_COOKIE] = av_admin_cookie_value(time() + 600, "n", "superadmin", "t", av_secret(), false);'
    . ' echo "role=" . av_admin_role() . ",passed=" . (AdminRoles::mfaPassed() ? "1" : "0");') . ' 2>/dev/null');
ck('mfa: the break-glass token sign-in stands on its own credential and is not asked for a code',
    $mfTokenRole === 'role=superadmin,passed=1');

/* ── The trail ────────────────────────────────────────────────────────── */
$mfTrail = (string) $mfDb->query("SELECT GROUP_CONCAT(action) FROM lms_audit WHERE target = " . $mfDb->quote($mfEmail))->fetchColumn();
ck('mfa: setting one up, passing it, spending a backup code and resetting it are all in the trail',
    str_contains($mfTrail, 'mfa_enrolled') && str_contains($mfTrail, 'mfa_passed')
    && str_contains($mfTrail, 'mfa_backup_used') && str_contains($mfTrail, 'mfa_reset')
    && str_contains($mfTrail, 'mfa_lockout'));

AdminRoles::remove($mfEmail);
unset($_COOKIE[AV_ADMIN_COOKIE], $_COOKIE[LmsAuth::COOKIE]); $mfResetAuth();
$mfDb->prepare('DELETE FROM admin_mfa WHERE email IN (?,?)')->execute([$mfEmail, $mfOther]);
$mfDb->prepare('DELETE FROM lms_sessions WHERE user_id = ?')->execute([$mfUid]);
$mfDb->prepare('DELETE FROM lms_users WHERE id = ?')->execute([$mfUid]);
$mfDb->prepare('DELETE FROM lms_audit WHERE target = ?')->execute([$mfEmail]);
