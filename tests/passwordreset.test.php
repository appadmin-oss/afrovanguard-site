<?php
/**
 * tests/passwordreset.test.php — forgetting a password, and getting back in.
 *
 * The site signs people in by emailed code as well as by password, so a
 * reset was never the only way back — but an account WITH a password and no
 * way to change it is a dead end, and it was one.
 *
 * What is pinned here is the part that is security, not convenience:
 *
 *   • The form never says whether an address has an account. Otherwise
 *     "forgot password" is a way to ask the site who its members are.
 *   • The token is stored hashed. A reset link in a mailbox is a password;
 *     a dump of the table must not be a pile of them.
 *   • One use, one hour, one live token per account — asking again retires
 *     the link already sent.
 *   • Resetting ends every other session. Whoever took the account is
 *     holding a cookie, and a new password that leaves them signed in has
 *     changed nothing.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

reset_users();
$db = Database::pdo();

$sent = [];
$cap  = static function (array $m) use (&$sent): bool { $sent[] = $m; return true; };
Mailer::fakeRoads(['smtp' => $cap, 'resend' => $cap, 'relay' => $cap, 'host' => $cap]);
putenv('AV_MAIL_DISABLED=0'); unset($_ENV['AV_MAIL_DISABLED'], $_SERVER['AV_MAIL_DISABLED']);

$db->exec("INSERT INTO lms_users (id,name,email,password_hash,status,has_password,email_verified)
           VALUES (401,'Ada Obi','ada401@x.co','x','active',1,1)");

$tokenFrom = static function (array $sent): string {
    foreach ($sent as $m) {
        if (preg_match('/reset=([a-f0-9]{64})/', (string) ($m['html'] ?? ''), $x)) return $x[1];
    }
    return '';
};

/* ══ ENUMERATION ══════════════════════════════════════════════════════════ */

$sent = [];
$real = LmsAuth::requestPasswordReset('ada401@x.co');
$realCount = count($sent);
$ghost = LmsAuth::requestPasswordReset('nobody-at-all@x.co');
ck('reset: the answer is the same whether or not the address has an account — '
 . 'otherwise the form is a way to ask who the members are',
    ($real['message'] ?? 'a') === ($ghost['message'] ?? 'b') && ($real['ok'] ?? false));
ck('reset: …but only the real account is actually emailed',
    $realCount === 1 && count($sent) === 1);
ck('reset: a malformed address is accepted politely and mails nobody',
    (LmsAuth::requestPasswordReset('not-an-address')['ok'] ?? false) && count($sent) === 1);

/* ══ THE TOKEN ════════════════════════════════════════════════════════════ */

$tok = $tokenFrom($sent);
ck('reset: the email carries a 64-hex token', (bool) preg_match('/^[a-f0-9]{64}$/', $tok));
ck('reset: the database stores only its hash, never the token itself',
    (int) $db->query("SELECT COUNT(*) FROM lms_password_resets WHERE token_hash = '" . hash('sha256', $tok) . "'")->fetchColumn() === 1
    && (int) $db->query("SELECT COUNT(*) FROM lms_password_resets WHERE token_hash = '" . $tok . "'")->fetchColumn() === 0);
ck('reset: a junk token resolves to nobody', LmsAuth::resetTokenUser('nope') === null);
ck('reset: the real token resolves to its owner',
    (int) (LmsAuth::resetTokenUser($tok)['user_id'] ?? 0) === 401);

/* Asking again retires the first link. */
$sent = [];
LmsAuth::requestPasswordReset('ada401@x.co');
$tok2 = $tokenFrom($sent);
ck('reset: asking again issues a different link', $tok2 !== '' && $tok2 !== $tok);
ck('reset: …and the first one stops working, so a link logged or forwarded '
 . 'earlier is already dead', LmsAuth::resetTokenUser($tok) === null);

/* ══ COMPLETING IT ════════════════════════════════════════════════════════ */

ck('reset: a password that fails the policy is refused',
    (LmsAuth::completePasswordReset($tok2, 'abc')['ok'] ?? true) === false);
ck('reset: …and the token survives that refusal, so one typo does not cost '
 . 'the whole link', LmsAuth::resetTokenUser($tok2) !== null);

/* A live session that should not survive the reset. */
try {
    $db->prepare('INSERT INTO lms_sessions (user_id, token_hash, created_at, expires_at, ip, ua) VALUES (?,?,?,?,?,?)')
       ->execute([401, hash('sha256', 'stolen-session'), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + 86400), '', '']);
} catch (Throwable $e) {}
$before = (int) $db->query('SELECT COUNT(*) FROM lms_sessions WHERE user_id = 401')->fetchColumn();

$done = LmsAuth::completePasswordReset($tok2, 'a-Strong-Passphrase-9');
/* Counted here, BEFORE signing in: a successful login opens a session of its
   own, and counting after it would hide exactly the bug this is for. */
$after = (int) $db->query('SELECT COUNT(*) FROM lms_sessions WHERE user_id = 401')->fetchColumn();
ck('reset: a good password completes it', (bool) ($done['ok'] ?? false));
ck('reset: every other session is ended — a new password that leaves the '
 . 'thief signed in has changed nothing', $before > 0 && $after === 0);
ck('reset: the new password signs in',
    (bool) (LmsAuth::login('ada401@x.co', 'a-Strong-Passphrase-9')['ok'] ?? false));
ck('reset: the link cannot be used twice',
    (LmsAuth::completePasswordReset($tok2, 'another-Strong-One-9')['ok'] ?? true) === false);

/* ══ EXPIRY ═══════════════════════════════════════════════════════════════ */

$sent = [];
LmsAuth::requestPasswordReset('ada401@x.co');
$tok3 = $tokenFrom($sent);
$db->prepare('UPDATE lms_password_resets SET expires_at = ? WHERE token_hash = ?')
   ->execute([gmdate('Y-m-d H:i:s', time() - 60), hash('sha256', $tok3)]);
ck('reset: an hour-old link is dead', LmsAuth::resetTokenUser($tok3) === null);
ck('reset: …and says so rather than failing quietly',
    str_contains((string) (LmsAuth::completePasswordReset($tok3, 'a-Strong-Passphrase-9')['error'] ?? ''), 'expired'));

/* ══ THE MAILBOX IS PROVEN ════════════════════════════════════════════════ */

$db->exec("INSERT INTO lms_users (id,name,email,password_hash,status,has_password,email_verified)
           VALUES (402,'Unverified Soul','unver402@x.co','x','active',1,0)");
$sent = [];
LmsAuth::requestPasswordReset('unver402@x.co');
LmsAuth::completePasswordReset($tokenFrom($sent), 'a-Strong-Passphrase-9');
ck('reset: completing a reset verifies the address too — clicking a link we '
 . 'posted there IS proof of the mailbox, and without this the road out of '
 . 'one dead end ends in another',
    (bool) (LmsAuth::login('unver402@x.co', 'a-Strong-Passphrase-9')['ok'] ?? false));

Mailer::fakeRoads(null);
