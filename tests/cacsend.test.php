<?php
/**
 * tests/cacsend.test.php — CACENTRE's email, sent by this site's mail.
 *
 *   • The "mail-send" door takes a token signed with the bridge secret, for
 *     that door, bound to the message, once.
 *   • A message is sent with this site's own mail, named as CACENTRE, with
 *     replies to CACENTRE; only list headers are carried over.
 *   • A daily ceiling keeps CACENTRE from spending this site's quota; past it
 *     the answer says so, and CACENTRE uses its own roads.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

if (!defined('AV_SSO_SECRET')) {
    $csKeep = ['AV_SSO_SECRET' => getenv('AV_SSO_SECRET'), 'AV_MAIL_DISABLED' => getenv('AV_MAIL_DISABLED'), 'AV_CAC_SEND_DAILY' => getenv('AV_CAC_SEND_DAILY')];
    $csSecret = str_repeat('m', 40);
    putenv('AV_SSO_SECRET=' . $csSecret);
    $b64 = static fn(string $r) => rtrim(strtr(base64_encode($r), '+/', '-_'), '=');
    /* Signed exactly as cac/includes/mail-transport.php avToken() signs. */
    $tok = static function (string $signed, array $over = []) use ($b64, $csSecret): string {
        $c = $b64((string) json_encode($over + ['iss' => 'cacentre', 'aud' => 'mail-send', 'iat' => time(), 'exp' => time() + 60,
            'nonce' => bin2hex(random_bytes(16)), 'bh' => hash('sha256', $signed)]));
        return 'v1.' . $c . '.' . $b64(hash_hmac('sha256', $c, $csSecret, true));
    };
    $body = '{"to":"ada@example.org"}';
    $t = $tok($body);
    ck('cacentre send: a token for the mail door is accepted', is_array(CacInbound::verify($t, $body, CacSend::AUD)));
    ck('cacentre send: …once', CacInbound::verify($t, $body, CacSend::AUD) === 'replayed');
    ck('cacentre send: a meetings token is not a mail token', CacInbound::verify($tok($body, ['aud' => 'meetings']), $body, CacSend::AUD) === 'wrong-door');
    ck('cacentre send: a token for another message is refused', CacInbound::verify($tok($body), '{"to":"someone@else.org"}', CacSend::AUD) === 'body-mismatch');

    /* Sending, on fake roads: nothing real leaves. */
    putenv('AV_MAIL_DISABLED=0');
    $seen = [];
    Mailer::fakeRoads(['smtp' => static function (array $m) use (&$seen): bool { $seen[] = $m; return true; }]);
    CacSend::ensure();
    Database::pdo()->exec('DELETE FROM av_cac_send');
    $r = CacSend::send(['to' => 'Ada@Example.org', 'subject' => 'Reset your password', 'html' => '<p>Your link</p>', 'text' => 'Your link',
        'from_name' => 'CACENTRE', 'reply_to' => 'info@cacentre.test', 'headers' => ['List-Unsubscribe' => '<https://x/u>', 'Bcc' => 'spy@evil.test']]);
    $m = $seen[0] ?? [];
    $flat = json_encode($m);
    ck('cacentre send: sent with this site\'s mail', !empty($r['ok']) && count($seen) === 1 && str_contains($flat, 'ada@example.org'));
    ck('cacentre send: named as CACENTRE, replies to CACENTRE', str_contains($flat, 'CACENTRE') && str_contains($flat, 'info@cacentre.test'));
    ck('cacentre send: only list headers are carried — never a Bcc', !str_contains($flat, 'spy@evil.test'));
    ck('cacentre send: an address that is not one is refused before any road', (CacSend::send(['to' => 'nope', 'subject' => 's', 'html' => 'h'])['code'] ?? '') === 'bad_recipient' && count($seen) === 1);
    putenv('AV_CAC_SEND_DAILY=1');
    $cap = CacSend::send(['to' => 'bo@example.org', 'subject' => 's', 'html' => '<p>h</p>']);
    ck('cacentre send: past the daily ceiling it says so and sends nothing', ($cap['code'] ?? '') === 'daily_cap' && count($seen) === 1);
    Mailer::fakeRoads(null);
    Database::pdo()->exec('DELETE FROM av_cac_send');
    foreach ($csKeep as $k => $v) putenv($v === false ? $k : $k . '=' . $v);
}
