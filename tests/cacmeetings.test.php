<?php
/**
 * tests/cacmeetings.test.php — meetings everybody sees; CACENTRE staff schedule them.
 *
 *   • A member or staff member sees every meeting; an Academy learner sees
 *     only the ones they are in.
 *   • The organiser can keep the MINUTES to the people in the meeting; the
 *     meeting itself stays visible, and says why its minutes are not shown.
 *   • Only the people in a meeting tick its agenda, not everybody who can see it.
 *   • CACENTRE's staff portal is let in by a token signed with the shared
 *     secret, bound to its body, spent once, for this door only; it schedules
 *     as the staff member it names, and may cancel only what they organised.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

$cmPdo = Database::pdo();
Meetings::ensure();
$cmPdo->exec("DELETE FROM meeting_attendees WHERE meeting_id IN (SELECT id FROM meetings WHERE title LIKE 'CM %')");
$cmPdo->exec("DELETE FROM meeting_transcripts WHERE meeting_id IN (SELECT id FROM meetings WHERE title LIKE 'CM %')");
$cmPdo->exec("DELETE FROM meetings WHERE title LIKE 'CM %'");
$cmPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@cm.test'");
$cmMk = function (string $name, string $role) use ($cmPdo): int {
    $cmPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?,?,?,?,?)')
        ->execute([$name, strtolower(str_replace(' ', '.', $name)) . '@cm.test', 'x', $role, 'active']);
    return (int) $cmPdo->lastInsertId();
};
$org = $cmMk('Ola Organiser', 'member');
$mem = $cmMk('Mo Member', 'member');
$lrn = $cmMk('Lee Learner', 'learner');
$when = gmdate('Y-m-d\TH:i', time() + 3 * 86400);

$open = Meetings::schedule($org, ['title' => 'CM Open', 'when' => $when, 'duration' => 30]);
$priv = Meetings::schedule($org, ['title' => 'CM Private', 'when' => $when, 'duration' => 30, 'minutes_private' => true]);
foreach ([$open, $priv] as $r) {
    $cmPdo->prepare("INSERT INTO meeting_transcripts (meeting_id, source, raw_text, summary, structured, created_at) VALUES (?, 'paste', 'x', 'We agreed the budget.', 1, ?)")
        ->execute([(int) $r['id'], gmdate('Y-m-d H:i:s')]);
}
$titles = static fn(array $l) => array_column($l, 'title');

/* ── Who sees what ──────────────────────────────────────────────────── */
ck('meetings: a member sees every meeting, invited or not', in_array('CM Open', $titles(Meetings::listFor($mem)), true) && in_array('CM Private', $titles(Meetings::listFor($mem)), true));
ck('meetings: an Academy learner sees only the meetings they are in', !in_array('CM Open', $titles(Meetings::listFor($lrn)), true) && Meetings::get($lrn, (int) $open['id']) === null);
$g = Meetings::get($mem, (int) $open['id']);
ck('meetings: …and opens one, with its minutes when they are not private', $g && ($g['transcript']['summary'] ?? '') === 'We agreed the budget.' && $g['organiser'] === 'Ola Organiser' && $g['invited'] === false);
$p = Meetings::get($mem, (int) $priv['id']);
ck('meetings: private minutes stay with the people in it — the meeting is still shown, and says so', $p && $p['transcript'] === null && $p['minutes_hidden'] === true && $p['minutes_private'] === true);
ck('meetings: the organiser still reads their own private minutes', (Meetings::get($org, (int) $priv['id'])['transcript']['summary'] ?? '') === 'We agreed the budget.');
ck('meetings: only the people in it tick the agenda', !(MeetingClock::setResolved($mem, (int) $open['id'], 0, true)['ok'] ?? false)
   && (MeetingClock::setResolved($mem, (int) $open['id'], 0, true)['error'] ?? '') === 'Not your meeting.');
$u = Meetings::update($org, (int) $open['id'], ['minutes_private' => true]);
ck('meetings: the organiser can make the minutes private afterwards', $u['ok'] && Meetings::get($mem, (int) $open['id'])['transcript'] === null);
Meetings::update($org, (int) $open['id'], ['minutes_private' => false]);

/* ── The CACENTRE door ──────────────────────────────────────────────── */
$cmSecret = str_repeat('s', 40);
$cmKeep = getenv('AV_SSO_SECRET');
if (!defined('AV_SSO_SECRET')) {
    putenv('AV_SSO_SECRET=' . $cmSecret);
    $b64 = static fn(string $r) => rtrim(strtr(base64_encode($r), '+/', '-_'), '=');
    /* Signed exactly as cac/includes/meetings/AvMeetings.php signs. */
    $tok = static function (string $signed, array $over = []) use ($b64, $cmSecret): string {
        $c = $b64((string) json_encode($over + ['iss' => 'cacentre', 'aud' => 'meetings', 'iat' => time(), 'exp' => time() + 60,
            'nonce' => bin2hex(random_bytes(16)), 'staff' => ['id' => '41', 'name' => 'Tola Staff', 'email' => 'tola@cacentre.test'], 'bh' => hash('sha256', $signed)]));
        return 'v1.' . $c . '.' . $b64(hash_hmac('sha256', $c, $cmSecret, true));
    };
    $body = '{"title":"CM From CACENTRE"}';
    $t = $tok($body);
    $s = CacMeetings::verify($t, $body);
    ck('cacentre door: a token signed with the shared secret names the staff member', is_array($s) && $s['email'] === 'tola@cacentre.test' && $s['id'] === '41');
    ck('cacentre door: the same token twice is refused', CacMeetings::verify($t, $body) === 'replayed');
    ck('cacentre door: a token for another body is refused', CacMeetings::verify($tok($body), '{"title":"Something else"}') === 'body-mismatch');
    ck('cacentre door: a token for another door is refused', CacMeetings::verify($tok($body, ['aud' => 'tasks']), $body) === 'wrong-door');
    ck('cacentre door: an expired token is refused', CacMeetings::verify($tok($body, ['iat' => time() - 600, 'exp' => time() - 500]), $body) === 'expired');
    ck('cacentre door: a forged signature is refused', CacMeetings::verify(substr($tok($body), 0, -4) . 'AAAA', $body) === 'bad-signature');

    $staff = ['id' => '41', 'name' => 'Tola Staff', 'email' => 'tola@cacentre.test'];
    $r = CacMeetings::handle($staff, 'schedule', ['title' => 'CM From CACENTRE', 'when' => $when, 'duration' => 45, 'attendees' => 'mo.member@cm.test']);
    ck('cacentre door: a staff member with no account here schedules a meeting, organised in their name', !empty($r['ok'])
       && (string) $cmPdo->query('SELECT organiser_name FROM meetings WHERE id = ' . (int) $r['id'])->fetchColumn() === 'Tola Staff');
    $l = CacMeetings::handle($staff, 'list', []);
    $mine = array_values(array_filter($l['meetings'], static fn($m) => $m['title'] === 'CM From CACENTRE'))[0] ?? [];
    ck('cacentre door: staff see every meeting, theirs marked as theirs', in_array('CM Open', $titles($l['meetings']), true) && !empty($mine['can_manage']) && !empty($mine['from_cacentre']) && !empty($mine['invited']));
    ck('cacentre door: members see the staff member\'s meeting in the portal, and the invited member is invited', ($x = array_values(array_filter(Meetings::listFor($mem), static fn($m) => $m['title'] === 'CM From CACENTRE'))[0] ?? null) && $x['organiser'] === 'Tola Staff' && $x['invited'] === true);
    $sp = CacMeetings::handle($staff, 'get', ['id' => (int) $priv['id']]);
    ck('cacentre door: staff see a private meeting, not its minutes', !empty($sp['ok']) && $sp['meeting']['minutes_hidden'] === true && $sp['meeting']['transcript'] === null);
    ck('cacentre door: staff may not cancel somebody else\'s meeting', empty(CacMeetings::handle($staff, 'cancel', ['id' => (int) $open['id']])['ok']));
    ck('cacentre door: …and may cancel their own', !empty(CacMeetings::handle($staff, 'cancel', ['id' => (int) $r['id']])['ok'])
       && (string) $cmPdo->query('SELECT status FROM meetings WHERE id = ' . (int) $r['id'])->fetchColumn() === 'cancelled');
    putenv($cmKeep === false ? 'AV_SSO_SECRET' : 'AV_SSO_SECRET=' . $cmKeep);
}

$cmPdo->exec("DELETE FROM meeting_attendees WHERE meeting_id IN (SELECT id FROM meetings WHERE title LIKE 'CM %')");
$cmPdo->exec("DELETE FROM meeting_transcripts WHERE meeting_id IN (SELECT id FROM meetings WHERE title LIKE 'CM %')");
$cmPdo->exec("DELETE FROM meetings WHERE title LIKE 'CM %'");
$cmPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@cm.test'");
