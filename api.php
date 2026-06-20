<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 *  AFROVANGUARD — Team Directory API Proxy  (v3.0)
 *  File: api.php
 *
 *  GET api.php?action=members        → all active members list
 *  GET api.php?action=member&id=N    → single member full profile
 *
 *  Security: GAS URL server-side only · rate-limited · CORS-enforced
 *            · all output sanitised · integer-only IDs · action allowlist
 * ═══════════════════════════════════════════════════════════════════
 */
declare(strict_types=1);
require_once __DIR__ . '/security.php';

av_handle_preflight();
av_security_headers();
av_enforce_get();
av_rate_check();

define('GAS_ENDPOINT', 'https://script.google.com/macros/s/AKfycbxg7TgQJAdn9ngEyjOXU2YvdEVHE1xpR3HShzJiNEh6qU2ESdOXUKHgzkX08JXpqS_F0g/exec');
define('GAS_TIMEOUT',      15);
define('CLIENT_CACHE_TTL', 60);

// ── Route ──────────────────────────────────────────────────────────
$action = trim((string) ($_GET['action'] ?? 'members'));
av_validate_enum($action, ['members', 'member', 'votm'], 'action');

if ($action === 'member') {
    $rawId = (string) ($_GET['id'] ?? '');
    if ($rawId === '') av_json_fail('Member ID is required.', 400);
    $memberId = av_validate_member_id($rawId);
    $upstream = GAS_ENDPOINT . '?id=' . $memberId;
} elseif ($action === 'votm') {
    $upstream = GAS_ENDPOINT . '?action=votm';
} else {
    $upstream = GAS_ENDPOINT;
}

// ── Fetch from Google Apps Script ─────────────────────────────────
$raw = av_fetch_gas($upstream);

if ($raw === false || $raw === '') {
    av_log('api.php', 'Upstream fetch failed', $upstream);
    av_json_fail('Team directory is temporarily unavailable. Please try again shortly.', 503);
}

// ── Parse and validate ─────────────────────────────────────────────
$payload = @json_decode($raw, true);

if (!is_array($payload)) {
    av_log('api.php', 'Non-JSON upstream response', substr($raw, 0, 200));
    av_json_fail('Invalid response from directory service.', 502);
}

if (($payload['status'] ?? '') !== 'ok') {
    $gasMsg = $payload['message'] ?? 'Unknown error';
    av_log('api.php', "GAS error: {$gasMsg}", "action={$action}");
    $isNotFound = str_contains(strtolower($gasMsg), 'not found');
    av_json_fail(
        $isNotFound
            ? 'Member not found or is no longer active.'
            : 'Directory service error. Please try again.',
        $isNotFound ? 404 : 502
    );
}

// Sanitise all string values before forwarding to client
$payload = av_sanitise($payload);

http_response_code(200);
header('Cache-Control: public, max-age=' . CLIENT_CACHE_TTL . ', stale-while-revalidate=30');
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

// ─── cURL + fallback fetch helper ─────────────────────────────────
function av_fetch_gas(string $url): string|false
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => GAS_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: Afrovanguard-API/3.0 (+https://afrovanguard.org.ng)',
            ],
        ]);
        $result = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($err !== '' || $code !== 200) {
            av_log('api.php', "cURL error or non-200: {$err} HTTP={$code}", $url);
            return false;
        }
        return ($result !== '' && $result !== false) ? $result : false;
    }
    // Fallback
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET', 'timeout' => GAS_TIMEOUT, 'ignore_errors' => true,
            'follow_location' => true, 'max_redirects' => 5,
            'header' => "Accept: application/json\r\nUser-Agent: Afrovanguard-API/3.0\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $result = @file_get_contents($url, false, $ctx);
    return ($result !== false && $result !== '') ? $result : false;
}
