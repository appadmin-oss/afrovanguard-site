<?php
/**
 * tests/chatbot.test.php — the Google Chat task bot.
 *
 * Two things here are worth more than the rest.
 *
 * FIRST, the token check. This endpoint is the one place in the application
 * where an unauthenticated stranger on the internet can cause a write, so the
 * verification has to be exercised, not reviewed. It is tested against a real
 * RSA keypair generated here: a genuine RS256 JWT is signed and accepted, and
 * then every way of getting past it is attempted and rejected — a forged
 * signature, `alg: none`, an HS256 downgrade signed with the published public
 * key, a wrong audience, a wrong issuer, an expired token, an unknown key id,
 * a tampered payload. Security code that can only be tested in production is
 * security code nobody tests.
 *
 * SECOND, assignment. The requirement was to assign tasks *correctly*, and the
 * only way to get that wrong quietly is to guess. Every ambiguous case is
 * driven here — two members with the same name, a name that matches nobody, a
 * mention of the bot itself — and each must produce an unassigned task and a
 * reply that says why, never a plausible misfile.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

ChatBot::ensure();
Commitments::ensure();
$db = Database::pdo();

/* ══════════════════════════════════════════════════════════════════════
   0. A local certificate authority of one, so the JWT path is real
   ══════════════════════════════════════════════════════════════════════ */

$haveOpenSsl = function_exists('openssl_pkey_new') && function_exists('openssl_csr_new');
$KID = 'test-key-1';
$certs = [];
$privKey = null;

if ($haveOpenSsl) {
    $conf = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $privKey = @openssl_pkey_new($conf);
    if ($privKey) {
        $dn  = ['countryName' => 'NG', 'organizationName' => 'Afrovanguard Test', 'commonName' => 'chat-test'];
        $csr = @openssl_csr_new($dn, $privKey, $conf);
        $crt = $csr ? @openssl_csr_sign($csr, null, $privKey, 1, $conf) : false;
        if ($crt) { openssl_x509_export($crt, $pem); $certs[$KID] = $pem; }
    }
}

$b64u = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

/** Mint a JWT with these header and claim overrides, signed with the test key. */
$mint = static function (array $claims = [], array $head = [], $key = null) use ($b64u, $KID, &$privKey): string {
    $h = array_merge(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $KID], $head);
    $c = array_merge([
        'iss' => ChatBot::ISSUER, 'aud' => 'test-audience-42',
        'iat' => time() - 5, 'exp' => time() + 300,
    ], $claims);
    $signing = $b64u(json_encode($h)) . '.' . $b64u(json_encode($c));
    $sig = '';
    openssl_sign($signing, $sig, $key ?? $privKey, OPENSSL_ALGO_SHA256);
    return $signing . '.' . $b64u($sig);
};

putenv('AV_CHAT_AUDIENCE=test-audience-42');

if (!$haveOpenSsl || !$certs) {
    echo "  (skipped: openssl key generation unavailable)\n";
} else {
    $ok = static fn(string $tok): array => ChatBot::verifyBearer('Bearer ' . $tok, $certs);

    /* ── the happy path first, so a later rejection means something ── */
    $v = $ok($mint());
    ck('jwt/a genuine token verifies', $v['ok'] === true);
    ck('jwt/claims come back',         ($v['claims']['iss'] ?? '') === ChatBot::ISSUER);

    /* ── every way past it ── */
    $v = $ok($mint([], ['alg' => 'none']));
    ck('jwt/alg:none is refused', $v['ok'] === false && strpos($v['error'], 'algorithm') !== false);

    /* The classic: sign with HS256 using the PUBLIC key as the HMAC secret.
       It only works against a verifier that trusts the algorithm the token
       names — which is why this one does not. */
    $hHead = $b64u(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $KID]));
    $hBody = $b64u(json_encode(['iss' => ChatBot::ISSUER, 'aud' => 'test-audience-42', 'exp' => time() + 300]));
    $hSig  = $b64u(hash_hmac('sha256', $hHead . '.' . $hBody, $certs[$KID], true));
    $v = $ok($hHead . '.' . $hBody . '.' . $hSig);
    ck('jwt/HS256 downgrade is refused', $v['ok'] === false && strpos($v['error'], 'algorithm') !== false);

    /* A token signed by a perfectly valid key that is not Google's. */
    $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $v = $ok($mint([], [], $other));
    ck('jwt/a foreign signature is refused', $v['ok'] === false && strpos($v['error'], 'Signature') !== false);

    $v = $ok($mint(['aud' => 'someone-elses-chat-app']));
    ck('jwt/a wrong audience is refused', $v['ok'] === false && strpos($v['error'], 'audience') !== false);

    $v = $ok($mint(['iss' => 'attacker@example.com']));
    ck('jwt/a wrong issuer is refused', $v['ok'] === false && strpos($v['error'], 'issuer') !== false);

    $v = $ok($mint(['exp' => time() - 3600, 'iat' => time() - 7200]));
    ck('jwt/an expired token is refused', $v['ok'] === false && strpos($v['error'], 'expired') !== false);

    $v = $ok($mint(['iat' => time() + 7200, 'exp' => time() + 10800]));
    ck('jwt/a future token is refused', $v['ok'] === false);

    $v = $ok($mint([], ['kid' => 'a-key-we-have-never-seen']));
    ck('jwt/an unknown key id is refused', $v['ok'] === false && strpos($v['error'], 'Unknown signing key') !== false);

    $v = $ok($mint([], ['kid' => '']));
    ck('jwt/a missing key id is refused', $v['ok'] === false);

    /* Swap the payload for one that says something else, keep the signature. */
    $tok = $mint();
    [$h1, , $s1] = explode('.', $tok);
    $tampered = $h1 . '.' . $b64u(json_encode(['iss' => ChatBot::ISSUER, 'aud' => 'test-audience-42', 'exp' => time() + 300, 'sub' => 'admin'])) . '.' . $s1;
    $v = $ok($tampered);
    ck('jwt/a tampered payload is refused', $v['ok'] === false && strpos($v['error'], 'Signature') !== false);

    foreach (['', 'Bearer', 'Bearer ', 'Bearer notatoken', 'Bearer a.b', 'Bearer a.b.c.d',
              'Basic ' . base64_encode('u:p'), 'Bearer ...'] as $junk) {
        $v = ChatBot::verifyBearer($junk, $certs);
        ck('jwt/junk is refused: ' . ($junk === '' ? '(empty)' : substr($junk, 0, 22)), $v['ok'] === false);
    }

    /* An empty cert set must refuse, not fall open. A stale or unreachable
       certificate endpoint has to fail CLOSED. */
    $v = ChatBot::verifyBearer('Bearer ' . $mint(), []);
    ck('jwt/no certificates means refuse', $v['ok'] === false);

    /* And with no audience configured there is nothing to check against, so
       there is no safe way to accept anything. */
    putenv('AV_CHAT_AUDIENCE');
    $v = ChatBot::verifyBearer('Bearer ' . $mint(), $certs);
    ck('jwt/no audience configured means refuse', $v['ok'] === false);
    ck('bot/is disabled without an audience', ChatBot::enabled() === false);
    putenv('AV_CHAT_AUDIENCE=test-audience-42');
    ck('bot/is enabled with one', ChatBot::enabled() === true);
}

/* ══════════════════════════════════════════════════════════════════════
   1. Parsing — deterministic, no key, no network
   ══════════════════════════════════════════════════════════════════════ */

/* The date is computed, never written down. A hardcoded future date is a test
   that passes until it silently stops being a future date — this pair failed for
   exactly that reason, and the parser was right both times: it refuses a due
   date in the past, so the day the literal expired the title kept the trailing
   "by …" and due_days went null. */
$isoSoon = gmdate('Y-m-d', strtotime('+30 days'));
$p = ChatBot::parseTask('/task Submit the revised STS report by ' . $isoSoon);
ck('parse/strips the slash command', $p['title'] === 'Submit the revised STS report');
ck('parse/reads an ISO date',        $p['due_days'] !== null && $p['due_days'] > 0);
ck('parse/…and reads it as the right number of days away',
   $p['due_days'] >= 29 && $p['due_days'] <= 30);
/* The other half of the same behaviour, now that it is pinned deliberately
   rather than by the calendar: a date already gone is not a due date, and the
   text is left alone rather than half-parsed. */
$pPast = ChatBot::parseTask('/task Submit the revised STS report by ' . gmdate('Y-m-d', strtotime('-30 days')));
ck('parse/a date in the past is not a due date, and the title is left intact',
   $pPast['due_days'] === null
   && strpos($pPast['title'], 'Submit the revised STS report') === 0);

$p = ChatBot::parseTask('remind Bode to call the venue tomorrow');
ck('parse/reads "remind X to"',  $p['owner'] === 'Bode');
ck('parse/keeps the task only',  $p['title'] === 'Call the venue');
ck('parse/reads "tomorrow"',     $p['due_days'] === 1);

$p = ChatBot::parseTask('Ada to draft the Alimosho outreach plan');
ck('parse/reads "X to do Y"',        $p['owner'] === 'Ada');
ck('parse/capitalises the title',    $p['title'] === 'Draft the Alimosho outreach plan');
ck('parse/no date means no date',    $p['due_days'] === null);

$p = ChatBot::parseTask('Book the hall for Chidi');
ck('parse/reads a trailing "for X"', $p['owner'] === 'Chidi' && $p['title'] === 'Book the hall');

$p = ChatBot::parseTask('Chase the printers in 3 days');
ck('parse/an unparsed unit does not corrupt the title', strpos($p['title'], 'Chase the printers') === 0);

$p = ChatBot::parseTask('/task Send the minutes by end of week');
ck('parse/"end of week" is a real date', $p['due_days'] !== null && $p['due_days'] >= 1 && $p['due_days'] <= 7);
ck('parse/and is stripped from the title', stripos($p['title'], 'end of week') === false);

/* A mention Google resolved is the strongest signal there is. */
$p = ChatBot::parseTask('@Bode please write up the outreach notes', [['displayName' => 'Bode']]);
ck('parse/a mention sets the owner',    $p['owner'] === 'Bode');
ck('parse/a mention is authoritative',  $p['owner_explicit'] === true);
ck('parse/the mention leaves the title', stripos($p['title'], 'Bode') === false);
/* The bug this pins: a greedy leading-@ pattern matched to the LAST space and
   left the title as "notes". Assert the whole task survives, not a fragment. */
ck('parse/the rest survives',            $p['title'] === 'Please write up the outreach notes');

/* Addressing the bot is not naming an owner. */
$p = ChatBot::parseTask('@Afrovanguard Task Bot Ada to book the hall',
                        [['displayName' => 'Afrovanguard Task Bot'], ['displayName' => 'Ada']]);
ck('parse/the bot is not the owner', $p['owner'] === 'Ada');

$p = ChatBot::parseTask('   ');
ck('parse/empty input is empty output', $p['title'] === '' && $p['owner'] === '');

$p = ChatBot::parseTask(str_repeat('word ', 400));
ck('parse/a long message is bounded', mb_strlen($p['title']) <= 300);

/* ══════════════════════════════════════════════════════════════════════
   2. Owner resolution refuses to guess
   ══════════════════════════════════════════════════════════════════════ */

reset_users();          // 1 = Ada, 2 = Bode, 3 = Chidi
$db->exec('DELETE FROM commitments');
$db->exec('DELETE FROM av_chat_tasks');

$r = ChatBot::resolveOwner('Bode');
ck('owner/an exact unique name resolves', $r['id'] === 2 && $r['why'] === 'exact');
$r = ChatBot::resolveOwner('bode');
ck('owner/matching is case-insensitive',  $r['id'] === 2);
$r = ChatBot::resolveOwner('Nobody At All');
ck('owner/an unknown name does not resolve', $r['id'] === 0 && $r['why'] === 'unknown');
$r = ChatBot::resolveOwner('');
ck('owner/an empty name does not resolve',   $r['id'] === 0 && $r['why'] === 'none');

/* Two people with the same name is the case that must NOT be guessed. */
$db->exec("INSERT INTO lms_users (id,name,email,password_hash) VALUES (4,'Ada','ada2@x.co','x')");
$r = ChatBot::resolveOwner('Ada');
ck('owner/two matches means nobody is assigned', $r['id'] === 0);
ck('owner/and says it was ambiguous',            $r['why'] === 'ambiguous');
ck('owner/and names the candidates',             count($r['candidates']) === 2);
$db->exec('DELETE FROM lms_users WHERE id = 4');

/* A near miss is a suggestion, never an assignment. */
$r = ChatBot::resolveOwner('Bod');
ck('owner/a prefix does not resolve',      $r['id'] === 0);
ck('owner/but is offered as a suggestion', in_array('Bode', $r['candidates'], true));

ck('member/an email resolves to a member', ChatBot::memberByEmail('B@X.CO') === 2);
ck('member/an unknown email does not',     ChatBot::memberByEmail('ghost@x.co') === 0);

/* ══════════════════════════════════════════════════════════════════════
   3. End to end: an event in, a commitment out
   ══════════════════════════════════════════════════════════════════════ */

$event = static function (string $text, string $email = 'a@x.co', array $annotations = [], string $space = 'spaces/AAA'): array {
    return ['type' => 'MESSAGE', 'message' => [
        'name' => 'spaces/AAA/messages/' . substr(sha1($text . $email), 0, 10),
        'space' => ['name' => $space], 'thread' => ['name' => $space . '/threads/T1'],
        'sender' => ['email' => $email, 'displayName' => 'Someone'],
        'argumentText' => $text, 'annotations' => $annotations,
    ]];
};
$latest = static function () use ($db): ?array {
    $r = $db->query('SELECT * FROM commitments ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
};

$re = ChatBot::handleEvent($event('/task Bode to submit the revised STS report by 2026-12-01'));
$c  = $latest();
ck('e2e/a commitment is created',    $c !== null);
ck('e2e/the title is the task',      $c && (string) $c['title'] === 'Submit the revised STS report');
ck('e2e/it is filed as chat',        $c && (string) $c['source_kind'] === Commitments::SRC_CHAT);
ck('e2e/it is assigned to Bode',     $c && (int) $c['member_id'] === 2);
ck('e2e/it is open',                 $c && (string) $c['status'] === 'open');
ck('e2e/it has a due date',          $c && (string) $c['due'] !== '');
ck('e2e/the reply confirms it',      strpos($re['text'], 'Recorded:') !== false);
ck('e2e/the reply names the owner',  strpos($re['text'], 'Bode') !== false);

/* The chat row links back, so the commitment has a source of its own. */
$ct = $db->query('SELECT * FROM av_chat_tasks ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ck('e2e/the chat row is recorded',        $ct !== false);
ck('e2e/it links to the commitment',      $ct && (int) $ct['commitment_id'] === (int) $c['id']);
ck('e2e/it records the space',            $ct && (string) $ct['space'] === 'spaces/AAA');
ck('e2e/it records who sent it',          $ct && (int) $ct['sender_id'] === 1);
ck('e2e/the source id is the chat row',   $c && (int) $c['source_id'] === (int) $ct['id']);

/* Same words, months apart, must not collide — the reason the chat row exists
   rather than using the sender id as the source. */
$before = (int) $db->query('SELECT COUNT(*) FROM commitments')->fetchColumn();
ChatBot::handleEvent($event('/task Bode to submit the revised STS report by 2026-12-01'));
$after = (int) $db->query('SELECT COUNT(*) FROM commitments')->fetchColumn();
ck('e2e/the same task said twice files twice', $after === $before + 1);

/* An unresolvable owner files the task anyway, and says why it is unassigned.
   Losing the task because the name was wrong would be the worst outcome. */
$re = ChatBot::handleEvent($event('/task Zainab to book the hall'));
$c  = $latest();
ck('unassigned/the task is still recorded', $c && (string) $c['title'] === 'Book the hall');
ck('unassigned/nobody is assigned',         $c && (int) $c['member_id'] === 0);
ck('unassigned/the hint is kept',           $c && (string) $c['owner_hint'] === 'Zainab');
ck('unassigned/the reply says why',         strpos($re['text'], 'do not have a member called') !== false);
ck('unassigned/and names who was meant',    strpos($re['text'], 'Zainab') !== false);
ck('unassigned/but leads with the record',  strpos($re['text'], 'Recorded:') !== false
    && strpos($re['text'], 'Recorded:') < strpos($re['text'], 'Owner:'));

/* Ambiguity must not resolve to "the first Ada". */
$db->exec("INSERT INTO lms_users (id,name,email,password_hash) VALUES (4,'Ada','ada2@x.co','x')");
$re = ChatBot::handleEvent($event('/task Ada to chase the printers'));
$c  = $latest();
ck('ambiguous/the task is recorded',   $c && (string) $c['title'] === 'Chase the printers');
ck('ambiguous/nobody is assigned',     $c && (int) $c['member_id'] === 0);
ck('ambiguous/the reply asks which',   strpos($re['text'], 'more than one Ada') !== false);
$db->exec('DELETE FROM lms_users WHERE id = 4');

/* A mention beats everything, including a name in the prose. */
$re = ChatBot::handleEvent($event('@Chidi please handle the venue deposit', 'a@x.co',
    [['type' => 'USER_MENTION', 'userMention' => ['user' => ['displayName' => 'Chidi', 'type' => 'HUMAN']]]]));
$c = $latest();
ck('mention/assigns from the mention', $c && (int) $c['member_id'] === 3);
ck('mention/the name is not in the title', $c && stripos((string) $c['title'], 'Chidi') === false);

/* ══════════════════════════════════════════════════════════════════════
   4. Who may talk to it
   ══════════════════════════════════════════════════════════════════════ */

$before = (int) $db->query('SELECT COUNT(*) FROM commitments')->fetchColumn();
$re = ChatBot::handleEvent($event('/task Do the thing', 'stranger@elsewhere.com'));
$after = (int) $db->query('SELECT COUNT(*) FROM commitments')->fetchColumn();
ck('auth/a non-member writes nothing',   $after === $before);
ck('auth/and is told why',               strpos($re['text'], 'could not match you to a member') !== false);
ck('auth/and is told what to do',        strpos($re['text'], 'administrator') !== false);

/* A space allowlist, when set, is honoured. */
AvRules::save(['chat.allowed_spaces' => 'spaces/ONLYTHIS'], 'test');
AvRules::invalidate();
$before = (int) $db->query('SELECT COUNT(*) FROM commitments')->fetchColumn();
$re = ChatBot::handleEvent($event('/task Do the thing', 'a@x.co', [], 'spaces/SOMEWHEREELSE'));
$after = (int) $db->query('SELECT COUNT(*) FROM commitments')->fetchColumn();
ck('space/a space off the list writes nothing', $after === $before);
ck('space/and says so',                          strpos($re['text'], 'not on the allowed list') !== false);

$re = ChatBot::handleEvent($event('/task Do the allowed thing', 'a@x.co', [], 'spaces/ONLYTHIS'));
ck('space/a listed space works', strpos($re['text'], 'Recorded:') !== false);

/* Clearing the rule has to actually work. A csv rule that can be set but never
   unset is a one-way door in a settings page — AvRules::cast() rejected every
   empty csv until this rule needed one, which meant an administrator who once
   restricted the bot could never open it up again. */
$cleared = AvRules::save(['chat.allowed_spaces' => ''], 'test');
AvRules::invalidate();
ck('space/the allowlist can be cleared', !empty($cleared['ok']));
ck('space/and reads back as empty',      AvRules::list('chat.allowed_spaces') === []);
$re = ChatBot::handleEvent($event('/task Do another thing', 'a@x.co', [], 'spaces/ANYWHERE'));
ck('space/blank means any space', strpos($re['text'], 'Recorded:') !== false);

/* ══════════════════════════════════════════════════════════════════════
   5. Events that are not tasks
   ══════════════════════════════════════════════════════════════════════ */

$re = ChatBot::handleEvent(['type' => 'ADDED_TO_SPACE']);
ck('event/joining a space explains itself', strpos($re['text'], 'task bot') !== false);
ck('event/and shows the syntax',            strpos($re['text'], '/task') !== false);
/* The promise the help text makes has to be the promise the code keeps. */
ck('event/and promises not to guess',       stripos($re['text'], 'never guess') !== false);

$re = ChatBot::handleEvent(['type' => 'REMOVED_FROM_SPACE']);
ck('event/leaving says nothing', trim($re['text']) === '');

$re = ChatBot::handleEvent($event('help'));
ck('event/help is help', strpos($re['text'], '/task') !== false);

$before = (int) $db->query('SELECT COUNT(*) FROM commitments')->fetchColumn();
$re = ChatBot::handleEvent($event('   '));
ck('event/an empty message files nothing',
   (int) $db->query('SELECT COUNT(*) FROM commitments')->fetchColumn() === $before);

$re = ChatBot::handleEvent(['type' => 'CARD_CLICKED']);
ck('event/an unhandled type is silent, not an error', trim($re['text']) === '');

/* ══════════════════════════════════════════════════════════════════════
   6. The reply cannot be restyled by its own input
   ══════════════════════════════════════════════════════════════════════ */

$re = ChatBot::handleEvent($event('/task *Ship it* _now_ `code` <b|x> and more'));
ck('inject/markup is stripped from the reply',
   strpos($re['text'], '*Ship it*') === false && strpos($re['text'], '<b|x>') === false);
ck('inject/but the reply still confirms', strpos($re['text'], 'Recorded:') !== false);
$c = $latest();
ck('inject/the stored title keeps the words', $c && stripos((string) $c['title'], 'Ship it') !== false);

/* ══════════════════════════════════════════════════════════════════════
   7. Who may act on a chat commitment afterwards
   ══════════════════════════════════════════════════════════════════════ */

$db->exec('DELETE FROM commitments'); $db->exec('DELETE FROM av_chat_tasks');
ChatBot::handleEvent($event('/task Bode to file the returns', 'a@x.co'));   // filed by Ada (1)
$c = $latest();
ck('perm/the sender may manage it',   Commitments::canManage(1, $c) === true);
ck('perm/the assignee may manage it', Commitments::canManage(2, $c) === true);
ck('perm/a bystander may not',        Commitments::canManage(3, $c) === false);
ck('perm/nobody with id 0 may',       Commitments::canManage(0, $c) === false);

/* ══════════════════════════════════════════════════════════════════════
   8. The endpoint itself
   ══════════════════════════════════════════════════════════════════════ */

$hook = (string) file_get_contents(AV_ROOT . '/webhooks/chat.php');
ck('endpoint/requires POST',            strpos($hook, "!== 'POST'") !== false);
ck('endpoint/verifies the bearer',      strpos($hook, 'ChatBot::verifyBearer') !== false);
ck('endpoint/refuses when disabled',    strpos($hook, 'ChatBot::enabled()') !== false);
/* The verification must gate the handler, not run beside it. */
ck('endpoint/verification precedes handling',
   strpos($hook, 'verifyBearer') < strpos($hook, 'handleEvent'));
ck('endpoint/bounds the payload',       strpos($hook, 'payload too large') !== false);
/* A 5xx makes Chat retry, and a retried filing is a duplicate reply. */
ck('endpoint/a handler throw answers 200, not 500',
   preg_match('/catch \(Throwable[^}]*\$say\(\[.text.[^\]]*\]\);/s', $hook) === 1);
ck('endpoint/the rejection reason is not returned to the caller',
   strpos($hook, "\$say(['error' => 'unauthorized'], 401)") !== false
   && strpos($hook, "\$say(['error' => \$v['error']]") === false);

$router = (string) file_get_contents(AV_ROOT . '/router.php');
ck('endpoint/is routed', strpos($router, "webhooks/chat.php") !== false);
$ht = (string) file_get_contents(AV_ROOT . '/.htaccess');
ck('endpoint/is rewritten on Apache', strpos($ht, 'webhooks/chat') !== false);

putenv('AV_CHAT_AUDIENCE');
$db->exec('DELETE FROM commitments');
$db->exec('DELETE FROM av_chat_tasks');
