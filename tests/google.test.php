<?php
/**
 * tests/google.test.php — Google sign-in and Workspace, end to end against a stub.
 *
 * There is no route to Google from here, so tests/fixtures/google-stub.php plays
 * the token endpoint and the REST reads, and the site is pointed at it with the
 * AV_WS_* overrides it already honours. What is pinned:
 *   • credentials are settable from the Studio (no shell on the host);
 *   • the id_token is checked for audience, issuer, expiry and nonce;
 *   • an unverified Google address signs nobody in; the org role needs `hd`;
 *   • connect-on-sign-in asks for Workspace scopes only from org addresses;
 *   • a dead refresh token clears the connection instead of reading as live;
 *   • the Setup tests say WHY Google refused; the connect page shows outcomes;
 *   • the daily directory sync runs from the web cron and skips suspended users.
 */
declare(strict_types=1);

$gPdo = Database::pdo();
$gB64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$gIdToken = static fn(array $claims): string => $gB64('{"alg":"RS256"}') . '.' . $gB64((string) json_encode($claims)) . '.sig';
$gClient = '123456789012-abcdefghijklmnop.apps.googleusercontent.com';
$gEnvKeep = [];
foreach (['AV_GOOGLE_CLIENT_ID', 'AV_GOOGLE_CLIENT_SECRET', 'AV_WS_TOKEN_URL', 'AV_WS_BASE_URL', 'AV_REPORTS_BASE_URL', 'AV_GWS_CONNECT_ON_SIGNIN', 'AV_GDRIVE_SERVICE_ACCOUNT', 'AV_WS_SUBJECT', 'AV_WS_DIRECTORY_SYNC'] as $k) $gEnvKeep[$k] = getenv($k);
/* $_SERVER as well as putenv(): that is where SetEnv puts a value, and where
   AvSettings hands a key back to when a Studio value is cleared. */
putenv('AV_GOOGLE_CLIENT_ID=' . $gClient); $_SERVER['AV_GOOGLE_CLIENT_ID'] = $gClient;
putenv('AV_GOOGLE_CLIENT_SECRET=good-secret');

/* ══ 1. Settable from the Studio ══════════════════════════════════════════ */
ck('google: configured from the environment through Config', GoogleAuth::configured() && GoogleAuth::clientId() === $gClient);
$gDefs = array_column(AvSettings::describe()['groups'], 'fields', 'group')['Google sign-in & Workspace'] ?? [];
$gKeys = array_column($gDefs, 'key');
foreach (['AV_GOOGLE_CLIENT_ID', 'AV_GOOGLE_CLIENT_SECRET', 'AV_GDRIVE_SERVICE_ACCOUNT', 'AV_WS_SUBJECT', 'AV_WS_CALENDAR_ID', 'AV_GDRIVE_FOLDER_ID', 'AV_WS_DIRECTORY_SYNC'] as $k) {
    ck("google: $k is on the Studio's Setup screen", in_array($k, $gKeys, true));
}
ck('google: the client secret and the service account key are secrets',
    AvSettings::isSecret('AV_GOOGLE_CLIENT_SECRET') && AvSettings::isSecret('AV_GDRIVE_SERVICE_ACCOUNT') && !AvSettings::isSecret('AV_GOOGLE_CLIENT_ID'));
$gSave = AvSettings::save(['AV_GOOGLE_CLIENT_ID' => '987654321098-zyxwvutsrqponmlk.apps.googleusercontent.com'], 'test');
ck('google: a client ID saved in the Studio is the one in force', $gSave['ok'] && GoogleAuth::clientId() === '987654321098-zyxwvutsrqponmlk.apps.googleusercontent.com');
AvSettings::save(['AV_GOOGLE_CLIENT_ID' => ''], 'test');
ck('google: clearing it hands back to the environment', GoogleAuth::clientId() === $gClient);
ck('google: a client ID of the wrong shape is refused with a reason',
    !empty(AvSettings::save(['AV_GOOGLE_CLIENT_ID' => 'GOCSPX-thisisthesecret'], 'test')['errors']['AV_GOOGLE_CLIENT_ID']));
$gKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($gKey, $gPem);
$gSa = json_encode(['type' => 'service_account', 'client_email' => 'svc@av-test.iam.gserviceaccount.com', 'client_id' => '1122334455', 'private_key' => $gPem], JSON_PRETTY_PRINT);
$gBad = AvSettings::save(['AV_GDRIVE_SERVICE_ACCOUNT' => '{"web":{"client_id":"x"}}'], 'test');
ck('google: an OAuth client file pasted as the service account is refused, and says so',
    str_contains((string) ($gBad['errors']['AV_GDRIVE_SERVICE_ACCOUNT'] ?? ''), 'service_account'));
ck('google: a key whose private key cannot be read is refused',
    !empty(AvSettings::save(['AV_GDRIVE_SERVICE_ACCOUNT' => json_encode(['type' => 'service_account', 'client_email' => 'a@b.iam.gserviceaccount.com', 'private_key' => 'nope'])], 'test')['errors']));
$gOk = AvSettings::save(['AV_GDRIVE_SERVICE_ACCOUNT' => (string) $gSa], 'test');
ck('google: a real service-account key, pasted with its line breaks, is accepted', $gOk['ok'] && GoogleWorkspace::configured());
ck('google: …and stored encrypted, not in the clear',
    !str_contains((string) $gPdo->query("SELECT value FROM av_settings WHERE setting_key = 'AV_GDRIVE_SERVICE_ACCOUNT'")->fetchColumn(), 'PRIVATE KEY'));
ck('google: …and never returned to the browser',
    !str_contains((string) json_encode(AvSettings::describe()), 'PRIVATE KEY'));
ck('google: Drive reads the same key as Workspace now (it read only config.php constants)',
    str_contains((string) file_get_contents(AV_ROOT . '/lib/Drive.php'), "self::cfg('AV_GDRIVE_SERVICE_ACCOUNT')"));
ck('google: the admin field for a key file is a textarea', str_contains((string) file_get_contents(AV_ROOT . '/admin/app.js'), "f.type === 'json'"));

/* ══ 2. The consent URL ═══════════════════════════════════════════════════ */
$gState = GoogleAuth::makeState('/portal/');
$gUrl = GoogleAuth::authUrl($gState, 'someone@gmail.com');
parse_str((string) parse_url($gUrl, PHP_URL_QUERY), $gQ);
ck('google: the OIDC nonce is the state\'s own nonce', ($gQ['nonce'] ?? '') !== '' && $gQ['nonce'] === GoogleAuth::stateNonce($gState));
ck('google: a personal address is not sent as hd=gmail.com', !isset($gQ['hd']));
parse_str((string) parse_url(GoogleAuth::authUrl($gState, 'staff@afrovanguard.org.ng'), PHP_URL_QUERY), $gQ2);
ck('google: an org address scopes the chooser to the org domain', ($gQ2['hd'] ?? '') === 'afrovanguard.org.ng');
ck('google: the redirect URI is this site\'s /auth/google/callback', GoogleAuth::redirectUri() === rtrim(SITE_URL, '/') . '/auth/google/callback');
putenv('AV_GWS_CONNECT_ON_SIGNIN');
ck('google: by default a personal account is NOT asked for Gmail/Drive at sign-in', !GoogleWorkspaceUser::connectOnSignin('someone@gmail.com') && !GoogleWorkspaceUser::connectOnSignin(''));
ck('google: …an org address is', GoogleWorkspaceUser::connectOnSignin('staff@afrovanguard.org.ng'));
putenv('AV_GWS_CONNECT_ON_SIGNIN=all');
ck('google: "all" asks everybody', GoogleWorkspaceUser::connectOnSignin('someone@gmail.com'));
putenv('AV_GWS_CONNECT_ON_SIGNIN=0');
ck('google: "0" asks nobody', !GoogleWorkspaceUser::connectOnSignin('staff@afrovanguard.org.ng'));
putenv('AV_GWS_CONNECT_ON_SIGNIN');

/* ══ 3. The id_token ══════════════════════════════════════════════════════ */
$gNonce = GoogleAuth::stateNonce($gState);
$gGood = ['iss' => 'https://accounts.google.com', 'aud' => $gClient, 'exp' => time() + 3600, 'nonce' => $gNonce,
          'email' => 'Staff@afrovanguard.org.ng', 'email_verified' => true, 'hd' => 'afrovanguard.org.ng', 'sub' => '42', 'name' => 'Staff'];
$gProf = static fn(array $c, ?string $n = null) => GoogleAuth::profileFromTokens(['id_token' => $gIdToken($c)], $n ?? $gNonce);
ck('google: a good id_token reads', is_array($gProf($gGood)));
ck('google: another client\'s token is refused', $gProf(['aud' => '1-x.apps.googleusercontent.com'] + $gGood) === null);
ck('google: an issuer that is not Google is refused', $gProf(['iss' => 'https://evil.example'] + $gGood) === null);
ck('google: an expired token is refused', $gProf(['exp' => time() - 3600] + $gGood) === null);
ck('google: a token minted for another sign-in (wrong nonce) is refused', $gProf(['nonce' => 'deadbeefdeadbeef'] + $gGood) === null);
ck('google: a token with no nonce is refused when one was sent', $gProf(array_diff_key($gGood, ['nonce' => 1])) === null);
ck('google: the hosted domain is read', ($gProf($gGood)['hd'] ?? '') === 'afrovanguard.org.ng');
ck('google: an org address managed by the tenant is org-verified', GoogleAuth::orgVerified($gProf($gGood)));
ck('google: an org address with no hd (an unmanaged account) is not', !GoogleAuth::orgVerified($gProf(array_diff_key($gGood, ['hd' => 1]))));
ck('google: an unverified org address is not', !GoogleAuth::orgVerified($gProf(['email_verified' => false] + $gGood)));

$gEmail = 'victim.' . bin2hex(random_bytes(3)) . '@example.test';
$gPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status, email_verified) VALUES (?,?,?,?,?,1)')->execute(['Victim', $gEmail, 'x', 'member', 'active']);
$gRes = LmsAuth::oauthSignIn($gEmail, 'Attacker', false, 'google');
ck('google: an unverified address does not sign anybody in as its owner', empty($gRes['ok']) && ($gRes['code'] ?? '') === 'unverified');
$gOrg = 'unmanaged.' . bin2hex(random_bytes(3)) . '@afrovanguard.org.ng';
$gR2 = @LmsAuth::oauthSignIn($gOrg, 'Unmanaged', true, 'google', false);
ck('google: an org address without the tenant\'s hd signs in as a learner, not a member',
    !empty($gR2['ok']) && (string) $gPdo->query('SELECT role FROM lms_users WHERE email = ' . $gPdo->quote($gOrg))->fetchColumn() === 'learner');
@LmsAuth::logout();

/* ══ 4. Over HTTP: the callback, the Setup probes, refresh, the directory ═ */
$gPort = 0;
if ($gProbe = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2)) {
    $n = (string) stream_socket_get_name($gProbe, false); $gPort = (int) substr($n, strrpos($n, ':') + 1); fclose($gProbe);
}
$gSitePort = 0;
if ($gProbe = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2)) {
    $n = (string) stream_socket_get_name($gProbe, false); $gSitePort = (int) substr($n, strrpos($n, ':') + 1); fclose($gProbe);
}
$gLog = tempnam(sys_get_temp_dir(), 'avgs');
$gStub = $gPort > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $gPort, AV_ROOT . '/tests/fixtures/google-stub.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $gLog, 'a'], 2 => ['file', $gLog, 'a']], $gPipes) : null;
$gStubBase = 'http://127.0.0.1:' . $gPort;
$gSiteEnv = getenv() + [];
$gSiteEnv['AV_WS_TOKEN_URL'] = $gStubBase . '/token';
$gSiteEnv['AV_GOOGLE_CLIENT_ID'] = $gClient; $gSiteEnv['AV_GOOGLE_CLIENT_SECRET'] = 'good-secret';
$gSiteEnv['APP_ENV'] = 'dev';
$gSite = $gSitePort > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $gSitePort, AV_ROOT . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $gLog, 'a'], 2 => ['file', $gLog, 'a']], $gPipes2, AV_ROOT, $gSiteEnv) : null;
$gUp = static function (int $port): bool {
    for ($i = 0; $i < 100; $i++) { $c = @fsockopen('127.0.0.1', $port, $a, $b, 0.2); if ($c) { fclose($c); return true; } usleep(50000); }
    return false;
};
$gGet = static function (string $url, string $cookie = ''): array {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30,
        'header' => $cookie !== '' ? 'Cookie: ' . $cookie . "\r\n" : '']]);
    @file_get_contents($url, false, $ctx);
    $h = implode("\n", $http_response_header ?? []);
    preg_match('~^Location:\s*(\S+)~mi', $h, $m);
    return ['loc' => $m[1] ?? '', 'headers' => $h];
};

if (!is_resource($gStub) || !is_resource($gSite) || !$gUp($gPort) || !$gUp($gSitePort)) {
    echo "  (skipped: could not start the loopback servers)\n";
} else {
    putenv('AV_WS_TOKEN_URL=' . $gStubBase . '/token');
    putenv('AV_WS_BASE_URL=' . $gStubBase);
    putenv('AV_REPORTS_BASE_URL=' . $gStubBase);
    $site = 'http://127.0.0.1:' . $gSitePort;
    $code = static fn(array $c): string => rtrim(strtr(base64_encode((string) json_encode($c)), '+/', '-_'), '=');

    /* The callback, end to end, with a state this suite signed (same APP_KEY). */
    $st = GoogleAuth::makeState('/portal/');
    $claims = ['iss' => 'https://accounts.google.com', 'aud' => $gClient, 'exp' => time() + 3600, 'nonce' => GoogleAuth::stateNonce($st),
               'email' => 'new.' . bin2hex(random_bytes(3)) . '@example.test', 'email_verified' => true, 'name' => 'New Person', 'sub' => '7'];
    $r = $gGet($site . '/auth/google/callback?state=' . rawurlencode($st) . '&code=' . $code($claims), 'av_oauth=' . rawurlencode($st));
    ck('google http: a verified sign-in lands where it started, with a session', $r['loc'] === '/portal/' && str_contains($r['headers'], 'av_lms='));

    $st = GoogleAuth::makeState('/portal/');
    $r = $gGet($site . '/auth/google/callback?state=' . rawurlencode($st) . '&code=' . $code(['email' => $gEmail, 'email_verified' => false, 'nonce' => GoogleAuth::stateNonce($st)] + $claims), 'av_oauth=' . rawurlencode($st));
    ck('google http: an unverified Google address is turned away with its own message', str_contains($r['loc'], 'e=google_unverified') && !str_contains($r['headers'], 'av_lms=') );

    $st = GoogleAuth::makeState('/portal/');
    $r = $gGet($site . '/auth/google/callback?state=' . rawurlencode($st) . '&code=' . $code(['nonce' => 'feedfacefeedface'] + $claims), 'av_oauth=' . rawurlencode($st));
    ck('google http: an id_token carrying another flow\'s nonce is refused', str_contains($r['loc'], 'e=google_failed') && !str_contains($r['headers'], 'av_lms='));
    ck('google http: …and the reason is kept for the System page', str_contains((string) (GoogleAuth::lastFailure()['why'] ?? ''), 'nonce'));
    $diag = (string) json_encode(Config::diagnostics(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ck('google: the System page shows the redirect URI and the last failure', str_contains($diag, 'Last Google failure') && str_contains($diag, 'nonce') && str_contains($diag, '/auth/google/callback'));

    $r = $gGet($site . '/auth/google/callback?state=' . rawurlencode($st) . '&code=x', 'av_oauth=somethingelse');
    ck('google http: a state that does not match its cookie is refused', str_contains($r['loc'], 'e=google_state'));
    $r = $gGet($site . '/login?e=google_unverified');
    $loginHtml = (string) @file_get_contents($site . '/login?e=google_unverified');
    ck('google http: the login page explains an unverified address', str_contains($loginHtml, 'verified that email address'));

    /* The Setup screen's tests. */
    $p = GoogleAuth::probeClient();
    ck('google setup: a good client is reported as accepted, with the URI to register', $p['ok'] && str_contains($p['detail'], '/auth/google/callback'));
    putenv('AV_GOOGLE_CLIENT_SECRET=wrong');
    $p = GoogleAuth::probeClient();
    ck('google setup: a wrong secret is reported as rejected', !$p['ok'] && str_contains($p['detail'], 'invalid_client'));
    putenv('AV_GOOGLE_CLIENT_SECRET=good-secret');

    putenv('AV_WS_SUBJECT=nodwd-admin@afrovanguard.org.ng');
    $t = AvSettings::test('google_workspace');
    ck('google setup: missing domain-wide delegation is named, with the client ID to grant',
        !$t['ok'] && str_contains($t['detail'], 'unauthorized_client') && str_contains($t['detail'], '1122334455'));
    putenv('AV_WS_SUBJECT=admin@afrovanguard.org.ng');
    $t = AvSettings::test('google_workspace');
    ck('google setup: with delegation the probe reads calendar, Drive and the directory', $t['ok'] && str_contains($t['detail'], 'calendar ✓') && str_contains($t['detail'], 'directory ✓'));
    ck('google: the calendar is read from the overridable API host', count(GoogleWorkspace::calendarEvents(null, 1)) === 1);

    /* The directory, from the web cron, once a day. */
    putenv('AV_WS_DIRECTORY_SYNC=daily');
    Database::metaSet('gws_directory_sync', '');
    $gPdo->exec("DELETE FROM lms_users WHERE email IN ('dir.one@afrovanguard.org.ng','dir.gone@afrovanguard.org.ng')");
    ck('google cron: the directory sync is due when switched on', GoogleWorkspace::directorySyncDue());
    $sync = GoogleWorkspace::runDirectorySync();
    ck('google cron: an active Workspace user becomes a member', !empty($sync['ok'])
        && (string) $gPdo->query("SELECT role FROM lms_users WHERE email = 'dir.one@afrovanguard.org.ng'")->fetchColumn() === 'member');
    ck('google cron: a suspended one does not', (int) $gPdo->query("SELECT COUNT(*) FROM lms_users WHERE email = 'dir.gone@afrovanguard.org.ng'")->fetchColumn() === 0);
    ck('google cron: …and it has run for today', !GoogleWorkspace::directorySyncDue());
    ck('google cron: the web cron runs it', str_contains((string) file_get_contents(AV_ROOT . '/tasks/cron.php'), 'GoogleWorkspace::runDirectorySync()'));
    ck('google cron: the web cron renews push channels', str_contains((string) file_get_contents(AV_ROOT . '/tasks/cron.php'), 'GoogleWatch::renewExpiring()'));
    putenv('AV_WS_DIRECTORY_SYNC=off');
    ck('google cron: off means off', !GoogleWorkspace::directorySyncDue());
    $gPdo->exec("DELETE FROM lms_users WHERE email IN ('dir.one@afrovanguard.org.ng','dir.gone@afrovanguard.org.ng')");

    /* A refresh token Google has revoked. */
    $uid = (int) $gPdo->query('SELECT id FROM lms_users WHERE email = ' . $gPdo->quote($gEmail))->fetchColumn();
    GoogleWorkspaceUser::ensure();
    $gPdo->prepare('DELETE FROM google_connections WHERE user_id = ?')->execute([$uid]);
    $enc = new ReflectionMethod('GoogleWorkspaceUser', 'enc'); $enc->setAccessible(true);
    $gPdo->prepare('INSERT INTO google_connections (user_id, google_sub, email, scopes, refresh_token, access_token, access_expires, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
         ->execute([$uid, '1', $gEmail, 'openid', $enc->invoke(null, 'live'), '', 0, '', '']);
    ck('google refresh: a live refresh token mints an access token', GoogleWorkspaceUser::accessTokenFor($uid) === 'at-refreshed');
    $gPdo->prepare('UPDATE google_connections SET refresh_token = ?, access_expires = 0 WHERE user_id = ?')->execute([$enc->invoke(null, 'dead'), $uid]);
    ck('google refresh: a revoked one returns nothing', GoogleWorkspaceUser::accessTokenFor($uid) === null);
    ck('google refresh: …and the connection stops claiming to be live, so /workspace offers to reconnect', !GoogleWorkspaceUser::connected($uid));
    $gPdo->prepare('DELETE FROM google_connections WHERE user_id = ?')->execute([$uid]);
}
if (is_resource($gStub)) { proc_terminate($gStub); proc_close($gStub); }
if (is_resource($gSite)) { proc_terminate($gSite); proc_close($gSite); }
@unlink($gLog);

/* ══ 5. The connect page says what happened ═══════════════════════════════ */
require_once AV_ROOT . '/lib/workspace.php';
ck('workspace: a failed connect is told, not silent', (av_workspace_notice('connect_failed', false)['tone'] ?? '') === 'err');
ck('workspace: a successful connect is told', (av_workspace_notice('', true)['tone'] ?? '') === 'ok');
ck('workspace: an unknown code shows nothing', av_workspace_notice('<script>', false) === null);
ck('workspace: the page renders the notice', str_contains((string) file_get_contents(AV_ROOT . '/workspace.php'), 'av_workspace_notice('));

AvSettings::save(['AV_GDRIVE_SERVICE_ACCOUNT' => ''], 'test');
foreach ($gEnvKeep as $k => $v) { if ($v === false) putenv($k); else putenv($k . '=' . $v); }
unset($_SERVER['AV_GOOGLE_CLIENT_ID']);
