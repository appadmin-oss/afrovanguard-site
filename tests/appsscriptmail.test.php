<?php
/**
 * tests/appsscriptmail.test.php — mail through the site's own Google Apps Script.
 *
 * What is pinned:
 *   1. The request the script reads: {action, token, data, source}, and data in
 *      the shape mailSend() expects — attachments base64, the Bcc, the Reply-To.
 *   2. Every failure is a sentence an operator can act on: no URL, no secret,
 *      an old deployment (non-JSON), an older script (Unknown action), a spent
 *      quota, a wrong secret, a deployment that is not public.
 *   3. The road order: SMTP → Apps Script → the old NGG relay → Resend → mail(),
 *      and that an announcement NEVER takes either Apps Script road.
 *   4. The settings resolve from the Studio, the env, and the GATES names.
 *
 * No network: the Apps Script client is given a mocked HTTP closure, and every
 * other road is replaced by Mailer::fakeRoads().
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

const GAS_TEST_URL = 'https://script.google.com/macros/s/AKfy-test/exec';

/** A mocked HTTP closure: records each request, answers with $answer (array → JSON). */
$mock = static function (&$seen, $answer, int $status = 200, string $error = ''): Closure {
    $seen = [];
    return static function (string $url, array $payload, int $timeout) use (&$seen, $answer, $status, $error): array {
        $seen[] = ['url' => $url, 'payload' => $payload, 'timeout' => $timeout];
        $body = is_array($answer) ? (string) json_encode($answer) : (string) $answer;
        return ['status' => $status, 'body' => $body, 'error' => $error];
    };
};

$gasReset = static function (): void {
    AvSettings::ensure();
    try { Database::pdo()->exec("DELETE FROM av_settings WHERE setting_key IN ('AV_GAS_URL','AV_GAS_SECRET','AV_MAIL_TRANSPORT')"); } catch (Throwable $e) {}
    AvSettings::flush(); AvSettings::apply();
    foreach (['AV_GAS_URL', 'AV_GAS_SECRET', 'AV_MAIL_TRANSPORT', 'GAS_URL', 'GAS_SECRET', 'MAIL_TRANSPORT'] as $k) {
        putenv($k); unset($_ENV[$k], $_SERVER[$k]);
    }
    Mailer::fakeRoads(null);
    Mailer::useAppsScript(null);
};
$gasReset();

/* ══ 1. The request the script reads ══════════════════════════════════════ */

$tmp = tempnam(sys_get_temp_dir(), 'avgas');
file_put_contents($tmp, "%PDF-1.4 receipt");
$g = new AppsScriptMail(GAS_TEST_URL, 's3cret-value', $mock($seen, ['success' => true, 'message' => 'Sent', 'remaining' => 97]));
$left = $g->send([
    'to' => 'ada@example.org', 'subject' => 'Your receipt', 'html' => '<p>Thanks</p>', 'text' => 'Thanks',
    'name' => 'Afrovanguard', 'reply_to' => 'donations@afrovanguard.org.ng', 'bcc' => 'audit@afrovanguard.org.ng',
    'attachments' => [['path' => $tmp, 'name' => 'receipt.pdf']],
]);
$p = $seen[0]['payload'] ?? [];
ck('gas: one request is made', count($seen) === 1);
ck('gas: it goes to the /exec URL', ($seen[0]['url'] ?? '') === GAS_TEST_URL);
ck('gas: action is mail.send', ($p['action'] ?? '') === 'mail.send');
ck('gas: the secret travels as token', ($p['token'] ?? '') === 's3cret-value');
ck('gas: a source is named', ($p['source'] ?? '') !== '');
ck('gas: data carries to/subject/html/text', ($p['data']['to'] ?? '') === 'ada@example.org'
    && ($p['data']['subject'] ?? '') === 'Your receipt' && ($p['data']['html'] ?? '') === '<p>Thanks</p>'
    && ($p['data']['text'] ?? '') === 'Thanks');
ck('gas: data carries the From name and Reply-To', ($p['data']['name'] ?? '') === 'Afrovanguard'
    && ($p['data']['reply_to'] ?? '') === 'donations@afrovanguard.org.ng');
ck('gas: data carries the Bcc', ($p['data']['bcc'] ?? '') === 'audit@afrovanguard.org.ng');
$att = $p['data']['attachments'][0] ?? [];
ck('gas: the attachment is named', ($att['name'] ?? '') === 'receipt.pdf');
ck('gas: the attachment is base64 of the file', base64_decode((string) ($att['content'] ?? ''), true) === "%PDF-1.4 receipt");
ck('gas: the attachment has a MIME type', ($att['mime'] ?? '') !== '');
ck('gas: send() returns the remaining allowance', $left === 97);
$pl = AppsScriptMail::payload(['to' => 'x@example.org', 'attachments' => [['path' => '/no/such/file.pdf']]]);
ck('gas: an unreadable attachment is left out, not sent empty', $pl['attachments'] === []);
@unlink($tmp);

/* ══ 2. Every failure says what to do ═════════════════════════════════════ */

$fails = static function (AppsScriptMail $g): string {
    try { $g->send(['to' => 'a@example.org', 'subject' => 's', 'html' => 'h']); return ''; }
    catch (RuntimeException $e) { return $e->getMessage(); }
};
$m = $fails(new AppsScriptMail('', 'x', $mock($seen, [])));
ck('gas: no URL is named, and where to set it', str_contains($m, 'no Apps Script address') && str_contains($m, 'Setup → Email'));
ck('gas: no URL makes no request', $seen === []);
$m = $fails(new AppsScriptMail(GAS_TEST_URL, '', $mock($seen, [])));
ck('gas: no secret is named', str_contains($m, 'no Apps Script secret'));
ck('gas: no secret makes no request', $seen === []);
$m = $fails(new AppsScriptMail(GAS_TEST_URL, 's', $mock($seen, '<html><body>Script function not found: doPost</body></html>')));
ck('gas: a non-JSON answer reads as an old deployment', str_contains($m, 'did not answer JSON') && str_contains($m, 'New version'));
$m = $fails(new AppsScriptMail(GAS_TEST_URL, 's', $mock($seen, ['success' => false, 'message' => 'Unknown action: mail.send'])));
ck('gas: Unknown action reads as an older script', str_contains($m, 'older than the mail.send action') && str_contains($m, AppsScriptMail::SCRIPT));
$m = $fails(new AppsScriptMail(GAS_TEST_URL, 's', $mock($seen, ['success' => false, 'message' => 'Apps Script daily email quota exceeded for this Google account.', 'remaining' => 0])));
ck('gas: a spent quota is explained with the allowance', str_contains($m, 'daily MailApp allowance') && str_contains($m, '100'));
$m = $fails(new AppsScriptMail(GAS_TEST_URL, 's', $mock($seen, ['success' => false, 'message' => 'Bad token'])));
ck('gas: a wrong secret is explained', str_contains($m, 'refused the secret') && str_contains($m, 'const SECRET'));
$m = $fails(new AppsScriptMail(GAS_TEST_URL, 's', $mock($seen, ['success' => false, 'message' => 'This Apps Script has no SECRET set, so it refuses every request.'])));
ck('gas: a script with no SECRET of its own is explained', str_contains($m, 'no SECRET of its own'));
$m = $fails(new AppsScriptMail(GAS_TEST_URL, 's', $mock($seen, '', 0, 'Could not resolve host')));
ck('gas: an unreachable script says so', str_contains($m, 'could not reach it') && str_contains($m, 'Could not resolve host'));
$m = $fails(new AppsScriptMail(GAS_TEST_URL, 's', $mock($seen, 'Not Found', 404)));
ck('gas: a 404 points at the /exec address', str_contains($m, 'HTTP 404') && str_contains($m, '/exec'));
$m = $fails(new AppsScriptMail(GAS_TEST_URL, 's', $mock($seen, '<html>https://accounts.google.com/ServiceLogin?continue=…</html>')));
ck('gas: a sign-in page reads as a deployment that is not public', str_contains($m, 'Who has access: Anyone'));
ck('gas: every failure is prefixed so the mail log names the road', str_starts_with($m, 'Apps Script: '));

/* check(): the read the Studio shows */
$c = (new AppsScriptMail(GAS_TEST_URL, 's', $mock($seen, ['success' => true, 'remaining' => 42, 'account' => 'cacentre@afrovanguard.org.ng'])))->check();
ck('gas check: asks mail.quota', ($seen[0]['payload']['action'] ?? '') === 'mail.quota');
ck('gas check: ok, reachable, account and allowance', $c['ok'] && $c['reachable'] && $c['remaining'] === 42
    && $c['account'] === 'cacentre@afrovanguard.org.ng' && str_contains($c['detail'], '42 more recipients'));
$c = (new AppsScriptMail(GAS_TEST_URL, 's', $mock($seen, ['success' => true, 'remaining' => 0, 'account' => 'a@b.org'])))->check();
ck('gas check: a spent allowance is not ok, but is reachable', !$c['ok'] && $c['reachable'] && $c['remaining'] === 0);
$c = (new AppsScriptMail(GAS_TEST_URL, 's', $mock($seen, ['success' => false, 'message' => 'Unknown action: mail.quota'])))->check();
ck('gas check: an older script is named', !$c['ok'] && str_contains($c['detail'], 'older than the mail.quota action'));

/* ══ 3. Which road a message takes ════════════════════════════════════════ */

ck('plan: auto, one-to-one — SMTP, Apps Script, relay, Resend, mail()',
   Mailer::plan('auto', false) === ['smtp', 'gas', 'relay', 'resend', 'host']);
ck('plan: auto, announcement — no Apps Script road at all',
   Mailer::plan('auto', true) === ['smtp', 'resend', 'host']);
ck('plan: gas — Apps Script only (the old relay stands in)', Mailer::plan('gas', false) === ['gas', 'relay']);
ck('plan: gas, announcement — nothing', Mailer::plan('gas', true) === []);
ck('plan: smtp — SMTP only', Mailer::plan('smtp', false) === ['smtp']);
ck('plan: resend / host are single roads', Mailer::plan('resend', false) === ['resend'] && Mailer::plan('host', true) === ['host']);
foreach (Mailer::TRANSPORTS as $t) {
    ck("plan: no announcement ever takes Apps Script ($t)", !array_intersect(Mailer::plan($t, true), ['gas', 'relay']));
}
ck('bulk: the bulk option marks an announcement', Mailer::isBulk(['bulk' => true]));
ck('bulk: a List-Unsubscribe header marks an announcement', Mailer::isBulk(['headers' => ['List-Unsubscribe' => '<https://x/u>']]));
ck('bulk: a plain message is one-to-one', !Mailer::isBulk([]) && !Mailer::isBulk(['bcc' => 'a@b.org']));

/* Sends, with fake roads. AV_MAIL_DISABLED is lifted only while fakes are in
   place, so nothing real can leave. */
putenv('AV_MAIL_DISABLED=0');
$roads = [];
$fail = static function (string $name) use (&$roads): Closure {
    return static function (array $m) use ($name, &$roads): bool { $roads[] = $name; throw new RuntimeException($name . ' is down'); };
};
$okRoad = static function (string $name) use (&$roads): Closure {
    return static function (array $m) use ($name, &$roads): bool { $roads[] = $name; return true; };
};

$gasCalls = [];
Mailer::fakeRoads(['smtp' => $fail('smtp'), 'resend' => $okRoad('resend'), 'host' => $okRoad('host')]);
Mailer::useAppsScript(new AppsScriptMail(GAS_TEST_URL, 's', $mock($gasCalls, ['success' => true, 'remaining' => 10])));

$roads = [];
$sent = Mailer::send('ada@example.org', 'Your sign-in code', '<p>123456</p>');
ck('send: when SMTP fails, a sign-in code goes by Apps Script', $sent && Mailer::lastTransport() === 'gas');
ck('send: SMTP was tried first, then Apps Script', Mailer::lastTried() === ['smtp', 'gas']);
ck('send: Resend was not needed', !in_array('resend', $roads, true));
ck('send: the SMTP failure is kept for the Studio', str_contains(implode(' ', Mailer::lastFailures()), 'smtp is down'));
ck('send: the HTML and a text part reach the script', ($gasCalls[0]['payload']['data']['html'] ?? '') === '<p>123456</p>'
    && trim((string) ($gasCalls[0]['payload']['data']['text'] ?? '')) === '123456');

$roads = []; $gasCalls = [];
$tmp = tempnam(sys_get_temp_dir(), 'avgas'); file_put_contents($tmp, 'certificate');
$sent = Mailer::send('ada@example.org', 'Your certificate', '<p>Attached</p>', ['attachment' => ['path' => $tmp, 'name' => 'cert.pdf']]);
ck('send: attachments now go through Apps Script', $sent && Mailer::lastTransport() === 'gas'
    && base64_decode((string) ($gasCalls[0]['payload']['data']['attachments'][0]['content'] ?? ''), true) === 'certificate');
@unlink($tmp);

$roads = []; $gasCalls = [];
$sent = Mailer::send('ada@example.org', 'Appeal update', '<p>news</p>', ['headers' => ['List-Unsubscribe' => '<https://x/u>']]);
ck('send: an announcement skips Apps Script and goes by Resend', $sent && Mailer::lastTransport() === 'resend');
ck('send: the Apps Script was never called for it', $gasCalls === []);
ck('send: the announcement tried SMTP then Resend', Mailer::lastTried() === ['smtp', 'resend']);

$roads = []; $gasCalls = [];
Mailer::send('ada@example.org', 'Appeal update', '<p>news</p>', ['bulk' => true]);
ck('send: bulk => true also keeps it off Apps Script', $gasCalls === [] && Mailer::lastTransport() === 'resend');

/* Apps Script refuses → the old NGG relay, still honoured, comes next */
$relaySeen = [];
Mailer::fakeRoads(['smtp' => $fail('smtp'), 'relay' => static function (array $m) use (&$relaySeen): bool { $relaySeen[] = $m['to']; return true; },
                   'resend' => $okRoad('resend')]);
Mailer::useAppsScript(new AppsScriptMail(GAS_TEST_URL, 's', $mock($gasCalls, ['success' => false, 'message' => 'Apps Script daily email quota exceeded for this Google account.'])));
$sent = Mailer::send('ada@example.org', 'Code', '<p>1</p>');
ck('send: a spent Apps Script falls to the legacy relay after it', $sent && Mailer::lastTransport() === 'relay'
    && Mailer::lastTried() === ['smtp', 'gas', 'relay']);
ck('send: the quota reason is kept', str_contains(implode(' ', Mailer::lastFailures()), 'daily MailApp allowance'));
$tmp = tempnam(sys_get_temp_dir(), 'avgas'); file_put_contents($tmp, 'x');
Mailer::send('ada@example.org', 'Code', '<p>1</p>', ['attachment' => ['path' => $tmp]]);
ck('send: the legacy relay still cannot carry an attachment', Mailer::lastTransport() === 'resend' && !in_array('relay', Mailer::lastTried(), true));
@unlink($tmp);

/* AV_MAIL_TRANSPORT */
Mailer::fakeRoads(['smtp' => $okRoad('smtp'), 'resend' => $okRoad('resend'), 'host' => $okRoad('host')]);
Mailer::useAppsScript(new AppsScriptMail(GAS_TEST_URL, 's', $mock($gasCalls, ['success' => true, 'remaining' => 5])));
putenv('AV_MAIL_TRANSPORT=gas');
$roads = []; $gasCalls = [];
$sent = Mailer::send('ada@example.org', 'Code', '<p>1</p>');
ck('transport gas: Apps Script carries it even though SMTP works', $sent && Mailer::lastTransport() === 'gas' && $roads === []);
$roads = []; $gasCalls = [];
$sent = Mailer::send('ada@example.org', 'Appeal update', '<p>n</p>', ['bulk' => true]);
ck('transport gas: an announcement is not sent', !$sent && $gasCalls === [] && $roads === []);
ck('transport gas: and the reason says so', str_contains(Mailer::lastError(), 'one-to-one mail only') && str_contains(Mailer::lastError(), 'auto or smtp'));
Mailer::useAppsScript(null);
Mailer::fakeRoads(['smtp' => $okRoad('smtp')]);
$sent = Mailer::send('ada@example.org', 'Code', '<p>1</p>');
ck('transport gas, not set up: fails and says where to set it', !$sent && str_contains(Mailer::lastError(), 'gas: not set up'));

putenv('AV_MAIL_TRANSPORT=smtp');
Mailer::fakeRoads(['smtp' => $fail('smtp'), 'resend' => $okRoad('resend')]);
Mailer::useAppsScript(new AppsScriptMail(GAS_TEST_URL, 's', $mock($gasCalls, ['success' => true])));
$gasCalls = [];
$sent = Mailer::send('ada@example.org', 'Code', '<p>1</p>');
ck('transport smtp: an SMTP failure is final', !$sent && $gasCalls === [] && Mailer::lastTried() === ['smtp']);

putenv('AV_MAIL_TRANSPORT=carrier-pigeon');
ck('transport: an unknown value is auto', Mailer::transport() === 'auto');
putenv('AV_MAIL_TRANSPORT'); putenv('MAIL_TRANSPORT=HOST');
ck('transport: MAIL_TRANSPORT is accepted, any case', Mailer::transport() === 'host');
putenv('MAIL_TRANSPORT');
ck('transport: unset is auto', Mailer::transport() === 'auto');

Mailer::fakeRoads(null);
Mailer::useAppsScript(null);
putenv('AV_MAIL_DISABLED=1');
ck('send: with mail disabled, nothing is tried', !Mailer::send('ada@example.org', 's', '<p>x</p>') && Mailer::lastTried() === []);

/* ══ 4. Settings resolution ═══════════════════════════════════════════════ */

$gasReset();
ck('settings: Apps Script is not configured by default', !AppsScriptMail::configured() && !Mailer::gasConfigured());
ck('settings: the three email settings are registered', AvSettings::defined('AV_GAS_URL') && AvSettings::defined('AV_GAS_SECRET') && AvSettings::defined('AV_MAIL_TRANSPORT'));
ck('settings: the secret is a secret, the URL is not', AvSettings::isSecret('AV_GAS_SECRET') && !AvSettings::isSecret('AV_GAS_URL'));

$r = AvSettings::save(['AV_GAS_URL' => GAS_TEST_URL, 'AV_GAS_SECRET' => 'a-long-random-secret-0123456789', 'AV_MAIL_TRANSPORT' => 'gas'], 'test');
ck('settings: URL, secret and road save from the Studio', !empty($r['ok']) && $r['saved'] === 3);
ck('settings: AppsScriptMail reads the Studio URL', AppsScriptMail::url() === GAS_TEST_URL);
ck('settings: and the Studio secret', AppsScriptMail::secret() === 'a-long-random-secret-0123456789');
ck('settings: so it is configured', AppsScriptMail::configured() && Mailer::configured());
ck('settings: the Studio road is in force', Mailer::transport() === 'gas');
$raw = (string) Database::pdo()->query("SELECT value FROM av_settings WHERE setting_key = 'AV_GAS_SECRET'")->fetchColumn();
ck('settings: the secret is not stored in plain text', $raw !== '' && !str_contains($raw, 'a-long-random-secret'));
$desc = json_encode(AvSettings::describe());
ck('settings: the secret is never described back', !str_contains((string) $desc, 'a-long-random-secret-0123456789'));
ck('settings: there is an Email group', str_contains((string) $desc, '"group":"Email"'));

$r = AvSettings::save(['AV_GAS_URL' => 'https://script.google.com/macros/s/AKfy-test/dev']);
ck('settings: a /dev address is refused with the reason', empty($r['ok']) && str_contains($r['errors']['AV_GAS_URL'] ?? '', '/exec'));
$r = AvSettings::save(['AV_MAIL_TRANSPORT' => 'carrier-pigeon']);
ck('settings: an unknown road is refused', empty($r['ok']) && isset($r['errors']['AV_MAIL_TRANSPORT']));
ck('settings: the stored road is kept after a refusal', Mailer::transport() === 'gas');

$tests = array_column(AvSettings::testable(), 'ready', 'key');
ck('settings: Apps Script mail is offered as a test, ready', ($tests['apps_script'] ?? null) === true);

$gasReset();
putenv('GAS_URL=' . GAS_TEST_URL); putenv('GAS_SECRET=gates-name');
ck('settings: the Africa GATES names (GAS_URL/GAS_SECRET) are accepted', AppsScriptMail::url() === GAS_TEST_URL && AppsScriptMail::secret() === 'gates-name');
putenv('AV_GAS_URL=https://script.google.com/macros/s/other/exec');
ck('settings: AV_GAS_URL wins over GAS_URL', AppsScriptMail::url() === 'https://script.google.com/macros/s/other/exec');
$gasReset();
ck('settings: cleared, nothing is left configured', !AppsScriptMail::configured() && Mailer::transport() === 'auto');

/* ══ 5. The shipped script ════════════════════════════════════════════════ */

$gs = (string) @file_get_contents(AV_ROOT . '/' . AppsScriptMail::SCRIPT);
ck('script: it is shipped', $gs !== '');
ck('script: it ships with an EMPTY secret', str_contains($gs, "const SECRET = '';"));
ck('script: it refuses everything without a SECRET', (bool) preg_match('/if\s*\(!SECRET\)\s*\{?\s*return respond\(false/', $gs));
ck('script: it checks the token', str_contains($gs, 'body.token !== SECRET'));
ck('script: mail.send, mail.quota and ping', str_contains($gs, "'mail.send'") && str_contains($gs, "'mail.quota'") && str_contains($gs, "'ping'"));
ck('script: the quota is checked before sending', strpos($gs, 'getRemainingDailyQuota()') < strpos($gs, 'MailApp.sendEmail(opts)'));
ck('script: attachments are decoded from base64', str_contains($gs, 'Utilities.base64Decode'));
ck('script: doGet answers a health check', str_contains($gs, 'function doGet('));
ck('script: the header tells the owner how to deploy', str_contains($gs, 'Execute as:     Me') && str_contains($gs, 'Who has access: Anyone')
    && str_contains($gs, '/exec') && str_contains($gs, 'New version'));
ck('script: the setup guide exists', is_file(AV_ROOT . '/docs/EMAIL-APPS-SCRIPT.md'));
