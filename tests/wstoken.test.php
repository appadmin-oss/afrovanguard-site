<?php
/**
 * tests/wstoken.test.php — the token the site asks Google for is one Google
 * would accept, and every way it can fail says which.
 *
 * A stand-in for Google's token endpoint runs under PHP's own server. It does
 * what Google does with a service-account assertion: checks the RS256
 * signature against the key's public half, the audience, and the times —
 * refusing a token issued in the future, or valid for more than an hour —
 * and answers with Google's own error words. Its clock can be set ahead to
 * reproduce a shared host whose clock runs fast.
 *
 * The failures pinned, each of which used to reach the screen as nothing, or
 * as the wrong advice:
 *   · a key whose "\n" escapes were doubled when pasted into config.php
 *   · a server whose clock is a few seconds ahead of Google's
 *   · a server that cannot reach Google at all
 *   · a key Google does not recognise
 */
declare(strict_types=1);

$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($res, $pem);
$pub = (string) openssl_pkey_get_details($res)['key'];

$dir = sys_get_temp_dir() . '/av-wstoken-' . getmypid();
@mkdir($dir, 0775, true);
file_put_contents("$dir/pub.pem", $pub);
file_put_contents("$dir/skew", '0');
file_put_contents("$dir/router.php", <<<'PHP'
<?php
/* Google's token endpoint, as far as a service-account assertion goes. */
$dir = __DIR__;
$skew = (int) file_get_contents("$dir/skew");          // Google's clock, relative to this machine's
$now = time() + $skew;
header('Date: ' . gmdate('D, d M Y H:i:s', $now) . ' GMT');
header('Content-Type: application/json');
$no = static function (string $e, string $d) { http_response_code(400); echo json_encode(['error' => $e, 'error_description' => $d]); exit; };
/* The APIs, answering with whatever $dir/api says — Google's own error bodies. */
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/token') {
    $mode = is_file("$dir/api") ? trim((string) file_get_contents("$dir/api")) : 'ok';
    if ($mode === 'disabled' && str_starts_with($path, '/calendar/')) {
        http_response_code(403);
        echo json_encode(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'Google Calendar API has not been used in project 123456 before or it is disabled. Enable it by visiting https://console.developers.google.com/apis/api/calendar-json.googleapis.com/overview?project=123456 then retry.']]);
        exit;
    }
    if ($mode === 'notadmin' && str_starts_with($path, '/admin/directory/')) {
        http_response_code(403);
        echo json_encode(['error' => ['code' => 403, 'message' => 'Not Authorized to access this resource/api', 'errors' => [['reason' => 'forbidden']]]]);
        exit;
    }
    echo json_encode(['items' => [], 'files' => [], 'users' => [], 'groups' => []]);
    exit;
}
parse_str((string) file_get_contents('php://input'), $f);
if (($f['grant_type'] ?? '') !== 'urn:ietf:params:oauth:grant-type:jwt-bearer') $no('unsupported_grant_type', 'Invalid grant_type');
$parts = explode('.', (string) ($f['assertion'] ?? ''));
if (count($parts) !== 3) $no('invalid_grant', 'Invalid JWT');
$d = static fn($s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
$h = json_decode($d($parts[0]), true); $c = json_decode($d($parts[1]), true);
if (($h['alg'] ?? '') !== 'RS256') $no('invalid_grant', 'Invalid JWT: unsupported alg');
if (openssl_verify($parts[0] . '.' . $parts[1], $d($parts[2]), (string) file_get_contents("$dir/pub.pem"), OPENSSL_ALGO_SHA256) !== 1) $no('invalid_grant', 'Invalid JWT Signature.');
if (($c['aud'] ?? '') !== 'https://oauth2.googleapis.com/token') $no('invalid_grant', 'Invalid JWT: audience');
if ((int) $c['iat'] > $now || (int) $c['exp'] - (int) $c['iat'] > 3600 || (int) $c['exp'] < $now)
    $no('invalid_grant', 'Invalid JWT: Token must be a short-lived token (60 minutes) and in a reasonable timeframe. Check your iat and exp values in the JWT claim.');
file_put_contents("$dir/last.json", json_encode($c));
echo json_encode(['access_token' => 'ya29.test-' . bin2hex(random_bytes(4)), 'expires_in' => 3599, 'token_type' => 'Bearer']);
PHP);

$port = 18600 + random_int(0, 300);
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/router.php"], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);

$oldNo = getenv('no_proxy');
putenv('no_proxy=*'); putenv('NO_PROXY=*');
putenv("AV_WS_TOKEN_URL=http://127.0.0.1:$port/token");
putenv("AV_WS_BASE_URL=http://127.0.0.1:$port");
$sa = static fn(string $key) => (string) json_encode(['type' => 'service_account', 'client_email' => 'ws@test.iam.gserviceaccount.com', 'client_id' => '1234567890', 'private_key' => $key]);
$use = static function (string $json) {
    AvSettings::save(['AV_GDRIVE_SERVICE_ACCOUNT' => $json, 'AV_WS_SUBJECT' => 'admin@afrovanguard.org.ng'], 'test');
    AvSettings::flush(); AvSettings::apply(); GoogleWorkspace::forgetTokens();
};
$token = static function (): ?string {
    $m = new ReflectionMethod(GoogleWorkspace::class, 'accessToken');
    return $m->invoke(null, GoogleWorkspace::SCOPE_CALENDAR, true);
};

/* ── a sound key gets a token Google accepts ──────────────────────────── */
$use($sa($pem));
$t = $token();
$last = json_decode((string) @file_get_contents("$dir/last.json"), true) ?: [];
ck('wstoken: Google’s checks pass — RS256 signature, audience, times — and a token comes back', is_string($t) && str_starts_with($t, 'ya29.test-'));
ck('wstoken: the assertion acts for the admin, for the scope asked', ($last['sub'] ?? '') === 'admin@afrovanguard.org.ng' && ($last['scope'] ?? '') === GoogleWorkspace::SCOPE_CALENDAR
    && ($last['iss'] ?? '') === 'ws@test.iam.gserviceaccount.com');
ck('wstoken: it is valid for exactly the hour Google allows, issued just before now', ($last['exp'] - $last['iat']) === 3600 && $last['iat'] <= time() - 30);

/* ── a server whose clock runs fast ───────────────────────────────────── */
file_put_contents("$dir/skew", '-45');          // Google is 45s behind this server
GoogleWorkspace::forgetTokens();
ck('wstoken: a server clock 45 seconds fast is still accepted (it used to be refused as "issued in the future")', is_string($token()));
file_put_contents("$dir/skew", '-600');         // ten minutes fast: no allowance covers that
GoogleWorkspace::forgetTokens();
ck('wstoken: ten minutes fast is refused…', $token() === null && str_contains(GoogleWorkspace::lastError(), 'Invalid JWT'));
ck('wstoken: …and this server knows how far off its clock is, from Google’s Date header', abs((int) GoogleWorkspace::clockSkew() - 600) <= 5);
ck('wstoken: the advice is the clock, not "the key was deleted"', str_contains(GoogleWorkspace::explain(), 'clock') && !str_contains(GoogleWorkspace::explain(), 'deleted'));
$r = AvSettings::test('google_workspace');
ck('wstoken: the Test button names the service account, the client ID and the clock', !$r['ok'] && str_contains($r['detail'], '1234567890')
    && str_contains($r['detail'], 'seconds ahead of Google'));
file_put_contents("$dir/skew", '0');

/* ── a key pasted into config.php with its \n doubled ─────────────────── */
$doubled = str_replace("\n", '\\n', $pem);       // one line, literal backslash-n
ck('wstoken: (a doubled key is unreadable as it stands)', openssl_pkey_get_private($doubled) === false);
$use($sa($doubled));
ck('wstoken: a key whose line breaks were doubled is read and signs', is_string($token()));

/* ── a key Google does not recognise ──────────────────────────────────── */
$other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($other, $otherPem);
$use($sa($otherPem));
ck('wstoken: a key Google does not recognise is refused in Google’s words', $token() === null && str_contains(GoogleWorkspace::lastError(), 'Invalid JWT Signature'));
ck('wstoken: and the advice is to make a new key, not to fix the clock', str_contains(GoogleWorkspace::explain(), 'new JSON key') && !str_contains(GoogleWorkspace::explain(), 'clock'));

/* ── a private key that is not a key at all ───────────────────────────── */
/* The Studio refuses to store such a key (AvSettings::validate), so it can
   only arrive from config.php or the environment — as it does here. */
Database::pdo()->prepare("DELETE FROM av_settings WHERE setting_key = 'AV_GDRIVE_SERVICE_ACCOUNT'")->execute();
AvSettings::flush(); AvSettings::apply();
putenv('AV_GDRIVE_SERVICE_ACCOUNT=' . $sa("-----BEGIN PRIVATE KEY-----\nnot a key\n-----END PRIVATE KEY-----\n"));
GoogleWorkspace::forgetTokens();
$t = $token();
putenv('AV_GDRIVE_SERVICE_ACCOUNT');
ck('wstoken: a key that cannot sign says so — it used to fail with no reason at all', $t === null && str_contains(GoogleWorkspace::lastError(), 'could not sign'));

/* ── a server that cannot reach Google ────────────────────────────────── */
$use($sa($pem));
putenv('AV_WS_TOKEN_URL=http://127.0.0.1:1/token');
GoogleWorkspace::forgetTokens();
ck('wstoken: no answer at all is reported with curl’s own reason, not only "network or TLS"', $token() === null
    && (bool) preg_match('/no response from Google \(network or TLS\): .{5,}/', GoogleWorkspace::lastError()));

/* ── the APIs: Google's own refusals, named ────────────────────────────── */
putenv("AV_WS_TOKEN_URL=http://127.0.0.1:$port/token");
putenv("AV_REPORTS_BASE_URL=http://127.0.0.1:$port");
file_put_contents("$dir/api", 'disabled');
GoogleWorkspace::forgetTokens();
$r = AvSettings::test('google_workspace');
ck('wstoken: a Calendar API switched off in Google Cloud is named, with the page that turns it on',
    !$r['ok'] && str_contains($r['detail'], 'has not been used in project') && str_contains(GoogleWorkspace::explain('GET /calendar — HTTP 403: Google Calendar API has not been used in project 1 before or it is disabled. Enable it by visiting https://console.developers.google.com/apis/api/calendar-json.googleapis.com/overview?project=1 then retry.'), 'https://console.developers.google.com/apis/api/calendar-json.googleapis.com/overview?project=1'));
file_put_contents("$dir/api", 'notadmin');
GoogleWorkspace::forgetTokens();
$r = AvSettings::test('google_workspace');
ck('wstoken: an "act as" account without the Admin API privilege is named as the cause of the directory refusal',
    str_contains($r['detail'], 'directory ✗') && str_contains(GoogleWorkspace::explain('GET /admin/directory/v1/users — HTTP 403: Not Authorized to access this resource/api'), 'Admin API privilege'));
file_put_contents("$dir/api", 'ok');
GoogleWorkspace::forgetTokens();
$r = AvSettings::test('google_workspace');
ck('wstoken: with no Drive folder set, the test says so rather than ticking it', str_contains($r['detail'], 'Drive folder not set'));
AvSettings::save(['AV_WS_SUBJECT' => ''], 'test'); AvSettings::flush(); AvSettings::apply(); GoogleWorkspace::forgetTokens();
$r = AvSettings::test('google_workspace');
ck('wstoken: with no calendar to read, the calendar is not ticked', !str_contains($r['detail'], 'calendar ✓'));
putenv('AV_REPORTS_BASE_URL');

/* ── the admin to act as ──────────────────────────────────────────────── */
$subj = new ReflectionMethod(GoogleWorkspace::class, 'subject');
AvSettings::save(['AV_WS_SUBJECT' => ''], 'test'); AvSettings::flush(); AvSettings::apply();
ck('wstoken: with no admin to act as, a personal ADMIN_EMAIL is not impersonated (Google would refuse it)',
    !defined('ADMIN_EMAIL') || LmsAuth::isOrgEmail((string) ADMIN_EMAIL) || $subj->invoke(null) === '');

proc_terminate($srv); proc_close($srv);
array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir);
putenv('AV_WS_TOKEN_URL'); putenv('AV_WS_BASE_URL');
if ($oldNo === false) { putenv('no_proxy'); putenv('NO_PROXY'); } else { putenv('no_proxy=' . $oldNo); putenv('NO_PROXY=' . $oldNo); }
Database::pdo()->prepare("DELETE FROM av_settings WHERE setting_key IN ('AV_GDRIVE_SERVICE_ACCOUNT','AV_WS_SUBJECT')")->execute();
AvSettings::flush(); AvSettings::apply(); GoogleWorkspace::forgetTokens();
