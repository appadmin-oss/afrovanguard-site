<?php
/**
 * tests/fixtures/google-stub.php — a stand-in for Google's token endpoint and
 * the few REST reads the site makes, for `php -S … google-stub.php`.
 *
 * There is no network to Google from the test host, so tests/google.test.php
 * points AV_WS_TOKEN_URL / AV_WS_BASE_URL / AV_REPORTS_BASE_URL here. It answers
 * the way Google answers, including the failures that matter:
 *
 *   authorization_code  code "av-setup-probe" → invalid_grant if the client
 *                       secret is "good-secret", else invalid_client.
 *                       Any other code is base64url(JSON claims): the id_token
 *                       is minted from exactly those claims.
 *   refresh_token       "dead" → invalid_grant; anything else → a new token.
 *   jwt-bearer          a `sub` starting "nodwd" → unauthorized_client (domain-
 *                       wide delegation not granted); otherwise a token.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit("dev server only\n"); }

header('Content-Type: application/json');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$b64u = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$out  = static function (int $code, array $body): void { http_response_code($code); echo json_encode($body); };

if ($path === '/token') {
    $g = (string) ($_POST['grant_type'] ?? '');
    if ($g === 'authorization_code') {
        $code = (string) ($_POST['code'] ?? '');
        if ($code === 'av-setup-probe') {
            ($_POST['client_secret'] ?? '') === 'good-secret'
                ? $out(400, ['error' => 'invalid_grant', 'error_description' => 'Malformed auth code.'])
                : $out(401, ['error' => 'invalid_client', 'error_description' => 'The OAuth client was not found.']);
            return true;
        }
        $claims = json_decode((string) base64_decode(strtr($code, '-_', '+/')), true);
        if (!is_array($claims)) { $out(400, ['error' => 'invalid_grant']); return true; }
        $jwt = $b64u('{"alg":"RS256","typ":"JWT"}') . '.' . $b64u((string) json_encode($claims)) . '.sig';
        $out(200, ['access_token' => 'at-' . bin2hex(random_bytes(4)), 'expires_in' => 3599, 'id_token' => $jwt,
                   'scope' => (string) ($claims['__scope'] ?? 'openid email profile'), 'token_type' => 'Bearer']
                  + (isset($claims['__refresh']) ? ['refresh_token' => (string) $claims['__refresh']] : []));
        return true;
    }
    if ($g === 'refresh_token') {
        ($_POST['refresh_token'] ?? '') === 'dead'
            ? $out(400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'])
            : $out(200, ['access_token' => 'at-refreshed', 'expires_in' => 3599]);
        return true;
    }
    if ($g === 'urn:ietf:params:oauth:grant-type:jwt-bearer') {
        $p = explode('.', (string) ($_POST['assertion'] ?? ''));
        $c = json_decode((string) base64_decode(strtr($p[1] ?? '', '-_', '+/')), true) ?: [];
        if (str_starts_with((string) ($c['sub'] ?? ''), 'nodwd')) {
            $out(401, ['error' => 'unauthorized_client', 'error_description' => 'Client is unauthorized to retrieve access tokens using this method, or client not authorized for any of the scopes requested.']);
        } else {
            $out(200, ['access_token' => 'sa-' . bin2hex(random_bytes(4)), 'expires_in' => 3599, 'token_type' => 'Bearer']);
        }
        return true;
    }
    $out(400, ['error' => 'unsupported_grant_type']); return true;
}
if (str_starts_with($path, '/calendar/v3/calendars/')) {
    $out(200, ['items' => [['id' => 'ev1', 'summary' => 'Stub event', 'start' => ['dateTime' => '2030-01-01T09:00:00Z'], 'end' => ['dateTime' => '2030-01-01T10:00:00Z']]]]);
    return true;
}
if ($path === '/drive/v3/files') { $out(200, ['files' => []]); return true; }
if ($path === '/admin/directory/v1/users') {
    $out(200, ['users' => [
        ['primaryEmail' => 'dir.one@afrovanguard.org.ng', 'name' => ['fullName' => 'Dir One'], 'suspended' => false],
        ['primaryEmail' => 'dir.gone@afrovanguard.org.ng', 'name' => ['fullName' => 'Dir Gone'], 'suspended' => true],
    ]]);
    return true;
}
if ($path === '/admin/directory/v1/groups') { $out(200, ['groups' => []]); return true; }
$out(404, ['error' => ['code' => 404, 'message' => 'stub: no route ' . $path, 'status' => 'NOT_FOUND']]);
return true;
