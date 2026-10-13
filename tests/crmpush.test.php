<?php
/**
 * tests/crmpush.test.php — Afrovanguard's people, pushed to the CACENTRE CRM.
 *
 * What this holds: the envelope is the one CACENTRE verifies; every source is
 * read past its cursor and only past it; a cursor moves only once CACENTRE
 * answered 200; an unsubscribe — whose row is deleted — still reaches the CRM;
 * and a site with no secret sends nothing at all.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/CrmAudiencePush.php';

$priv = sys_get_temp_dir() . '/av-crmpush-' . getmypid();
@mkdir($priv, 0700, true);
$oldPriv = getenv('AV_PRIVATE_DIR');
putenv('AV_PRIVATE_DIR=' . $priv);

$pdo = Database::pdo();
CrmAudiencePush::ensure();
$pdo->exec('DELETE FROM av_crm_outbox');
foreach (['crm_push.subscribers', 'crm_push.appeal_subs', 'crm_push.appeal_subs_ended', 'crm_push.gifts', 'crm_push.contacts', 'crm_push.last'] as $k) {
    Database::metaSet($k, '');
}
if (!Database::tableExists('subscribers')) {
    Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS subscribers (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(191) UNIQUE NOT NULL, source TEXT NOT NULL DEFAULT 'diary', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
}
$pdo->exec('DELETE FROM subscribers');
if (class_exists('Appeals') && method_exists('Appeals', 'ensure')) Appeals::ensure();
$hasSubs = Database::tableExists('av_appeal_subs');
if ($hasSubs) $pdo->exec('DELETE FROM av_appeal_subs');

$pdo->exec("INSERT INTO subscribers (email, source, created_at) VALUES ('ada@example.org', 'diary', '2026-10-01 09:00:00'), ('bola@example.org', 'footer', '2026-10-02 09:00:00')");
if ($hasSubs) {
    $pdo->exec("INSERT INTO av_appeal_subs (appeal_id, email, name, amount_ngn, interval_k, sub_code, status, started_at, last_at)
                VALUES (1, 'chi@example.org', 'Chi Eze', 5000, 'monthly', 'SUB_abc', 'active', '2026-09-01T10:00:00+01:00', '2026-10-01T10:00:00+01:00')");
}
file_put_contents($priv . '/donations.json', json_encode(['campaigns' => ['general' => []], 'donations' => [
    ['reference' => 'PS_2', 'email' => 'dayo@example.org', 'name' => 'Anonymous', 'anonymous' => true, 'amount' => 20000, 'currency' => 'NGN', 'campaign' => 'sts', 'status' => 'confirmed', 'created_at' => '2026-10-05T12:00:00+01:00'],
    ['reference' => 'PS_1', 'email' => 'ada@example.org', 'name' => 'Ada Obi', 'amount' => 5000, 'currency' => 'NGN', 'campaign' => 'general', 'status' => 'confirmed', 'created_at' => '2026-10-04T12:00:00+01:00'],
]]));
file_put_contents($priv . '/contacts.json', json_encode(['contacts' => [
    ['type' => 'newsletter', 'email' => 'eko@example.org', 'created' => '2026-10-03T08:00:00+01:00'],
    ['ref' => 'CTQ_PAR_1', 'purpose_label' => 'Partnership', 'subject' => 'A school visit', 'name' => 'Funmi', 'email' => 'funmi@example.org', 'phone' => '0803 000 1111', 'message' => 'private words', 'created_at' => '2026-10-06T08:00:00+01:00'],
]]));

/* ── No secret, nothing sent ─────────────────────────────────────────────── */
putenv('AV_CRM_INTAKE_SECRET');
$calls = 0;
CrmAudiencePush::$transport = static function () use (&$calls) { $calls++; return ['code' => 200, 'body' => '{"ok":true}']; };
$r = CrmAudiencePush::run();
ck('a site with no CRM secret sends nothing', !empty($r['skipped']) && $calls === 0);

/* ── The envelope CACENTRE reads ─────────────────────────────────────────── */
$secret = str_repeat('k', 43);
putenv('AV_CRM_INTAKE_SECRET=' . $secret);
$tok = CrmAudiencePush::sign([['kind' => 'subscriber', 'ref' => 'x', 'email' => 'x@example.org']], $secret, 1_800_000_000);
$p = explode('.', $tok);
$un = static fn(string $s): string => (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
$claims = json_decode($un($p[1] ?? ''), true);
ck('the token is v1.payload.signature', count($p) === 3 && $p[0] === 'v1');
ck('signed with HMAC-SHA256 over the payload segment, as CrmAudience::verifyPush() checks',
   hash_equals(hash_hmac('sha256', $p[1], $secret, true), $un($p[2])));
ck('it names this site as avg, lives five minutes and carries a nonce',
   ($claims['app'] ?? '') === 'avg' && $claims['exp'] - $claims['iat'] === 300 && strlen((string) $claims['nonce']) === 24);

/* ── What is collected ───────────────────────────────────────────────────── */
$c = CrmAudiencePush::collect();
$by = [];
foreach ($c['rows'] as $row) $by[$row['ref']] = $row;
ck('Diary subscribers are collected', ($by['sub:ada@example.org']['list'] ?? '') === 'The Vanguard Diary');
ck('a subscriber from another form says where', ($by['sub:bola@example.org']['list'] ?? '') === 'Afrovanguard · footer');
if ($hasSubs) ck('a monthly giver is a donor with their plan', ($by['plan:SUB_abc']['detail'] ?? '') === '₦5,000 monthly' && $by['plan:SUB_abc']['status'] === 'active');
ck('a one-off gift is a donor, with what they gave and to what', ($by['gift:PS_1']['detail'] ?? '') === 'Gave ₦5,000 to general');
ck('an anonymous gift sends no name', isset($by['gift:PS_2']) && ($by['gift:PS_2']['name'] ?? 'x') === '');
ck('a footer newsletter sign-up is a subscriber', ($by['news:eko@example.org']['list'] ?? '') === 'Afrovanguard newsletter');
ck('a contact-form message is an enquiry with its phone and subject', ($by['contact:CTQ_PAR_1']['phone'] ?? '') === '0803 000 1111'
   && ($by['contact:CTQ_PAR_1']['detail'] ?? '') === 'Partnership: A school visit');
ck('and the message itself is never sent', !str_contains(json_encode($c['rows']), 'private words'));

/* ── A failed push moves nothing ─────────────────────────────────────────── */
CrmAudiencePush::$transport = static fn() => ['code' => 403, 'body' => '{"ok":false,"error":"bad-signature"}'];
$r = CrmAudiencePush::run();
ck('a refused push is reported in words a person can act on', $r['ok'] === false && str_contains($r['error'], 'secret here differs'));
ck('and no cursor moved', (string) Database::metaGet('crm_push.subscribers') === '' && (string) Database::metaGet('crm_push.gifts') === '');
CrmAudiencePush::$transport = static fn() => ['code' => 0, 'body' => '', 'error' => 'timed out'];
ck('an unreachable CRM is said plainly', str_contains((string) (CrmAudiencePush::run()['error'] ?? ''), 'did not answer'));

/* ── A good push moves every cursor, and the next tick sends nothing new ── */
$sent = [];
CrmAudiencePush::$transport = static function (string $url, string $body) use (&$sent, $un) {
    $t = json_decode($body, true)['token'] ?? '';
    $sent[] = json_decode($un(explode('.', $t)[1] ?? ''), true)['rows'] ?? [];
    return ['code' => 200, 'body' => '{"ok":true,"created":1}'];
};
$r = CrmAudiencePush::run();
ck('a good push reports what it sent', $r['ok'] === true && $r['sent'] === count($c['rows']));
ck('to CACENTRE\'s intake', str_ends_with(CrmAudiencePush::url(), '/api/crm-intake.php'));
$sent = [];
$r = CrmAudiencePush::run();
ck('the next tick sends nothing it already sent', $r['ok'] === true && $r['sent'] === 0 && $sent === []);

$pdo->exec("INSERT INTO subscribers (email, source, created_at) VALUES ('new@example.org', 'diary', '2026-10-07 09:00:00')");
CrmAudiencePush::run();
ck('and only what is new after that', count($sent) === 1 && array_column($sent[0], 'ref') === ['sub:new@example.org']);

/* ── An unsubscribe reaches the CRM though its row is gone ───────────────── */
$pdo->exec("DELETE FROM subscribers WHERE email = 'ada@example.org'");
CrmAudiencePush::queue(['kind' => 'subscriber', 'ref' => CrmAudiencePush::subscriberRef('Ada@Example.org'), 'email' => 'ada@example.org', 'status' => 'unsubscribed']);
CrmAudiencePush::$transport = static fn() => ['code' => 503, 'body' => ''];
CrmAudiencePush::run();
ck('a queued unsubscribe survives a failed push', (int) $pdo->query('SELECT COUNT(*) FROM av_crm_outbox')->fetchColumn() === 1);
$sent = [];
CrmAudiencePush::$transport = static function (string $url, string $body) use (&$sent, $un) {
    $sent[] = json_decode($un(explode('.', json_decode($body, true)['token'])[1]), true)['rows'];
    return ['code' => 200, 'body' => '{"ok":true}'];
};
CrmAudiencePush::run();
ck('and is sent under the same ref the subscription was', ($sent[0][0]['ref'] ?? '') === 'sub:ada@example.org' && $sent[0][0]['status'] === 'unsubscribed');
ck('then leaves the outbox', (int) $pdo->query('SELECT COUNT(*) FROM av_crm_outbox')->fetchColumn() === 0);

$api = (string) file_get_contents(dirname(__DIR__) . '/diary/api.php');
ck('the Diary\'s unsubscribe queues the news for the CRM', str_contains($api, "CrmAudiencePush::queue(") && str_contains($api, "'status' => 'unsubscribed'"));
ck('the cron sends it', str_contains((string) file_get_contents(dirname(__DIR__) . '/tasks/cron.php'), 'CrmAudiencePush::run()'));
ck('and the secret is settable in the Studio, encrypted', (bool) preg_match("/'AV_CRM_INTAKE_SECRET' => \\[\\s*'group' => 'CACENTRE CRM'[^\\]]*'secret' => true/s",
   (string) file_get_contents(dirname(__DIR__) . '/lib/AvSettings.php')));

CrmAudiencePush::$transport = null;
putenv('AV_CRM_INTAKE_SECRET');
putenv($oldPriv === false ? 'AV_PRIVATE_DIR' : 'AV_PRIVATE_DIR=' . $oldPriv);
array_map('unlink', glob($priv . '/*') ?: []); @unlink($priv . '/.htaccess'); @rmdir($priv);
