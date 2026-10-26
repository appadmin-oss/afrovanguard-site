<?php
/**
 * vanquest/api.php — the VanQuest site's back end (vquest.afrovanguard.org.ng).
 * The journey and its rules are in lib/VanQuest.php.
 *
 *   POST {action:"join", email, placement?, xp?}       → first mail (vanquest_welcome)
 *   GET  ?action=seat&t=TOKEN                          → is the link good; name/sex if known
 *   POST {action:"seat", t, first_name, sex}           → confirm the seat → community page data
 *   GET  ?action=community&t=TOKEN                     → community page data
 *   POST {action:"interview", t, first_name, sex, phone, note?}
 *   GET  ?action=avatar&a=SEED&s=m|f                   → the pixel avatar, PNG
 *
 * Every call also runs VanQuest::tick(), so the onboarding mail goes out fifteen
 * minutes after confirming even on a host whose cron is not set up.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, [VanQuest::SITE, 'https://afrovanguard.org.ng', 'https://www.afrovanguard.org.ng'], true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }

$in = $method === 'POST' ? (json_decode((string) file_get_contents('php://input'), true) ?: $_POST) : $_GET;
$action = (string) ($in['action'] ?? '');

if ($action === 'avatar') {
    $png = VanQuest::avatarPng((string) ($in['a'] ?? ''), (string) ($in['s'] ?? 'm'), 4);
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=31536000, immutable');
    echo $png;
    exit;
}

header('Cache-Control: no-store');
$ip = preg_replace('/[^0-9a-fA-F.:]/', '', av_client_ip());
$limits = ['join' => [8, 3600], 'seat' => [40, 600], 'community' => [120, 600], 'interview' => [5, 3600]];
if (!isset($limits[$action])) json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
if (!av_rate_ok('vq_' . $action . '_' . $ip, ...$limits[$action])) json_out(['ok' => false, 'message' => 'Too many requests. Try again later.'], 429);

try { VanQuest::tick(5); } catch (Throwable $e) { error_log('[vanquest] tick: ' . $e->getMessage()); }

try {
    $t = (string) ($in['t'] ?? '');
    switch ($action) {
        case 'join':
            if ($method !== 'POST') json_out(['ok' => false, 'message' => 'POST required.'], 405);
            $r = VanQuest::join((string) ($in['email'] ?? ''), (string) ($in['placement'] ?? ''), (int) ($in['xp'] ?? 50));
            unset($r['token']);                       // the token travels by email only: that is the confirmation
            json_out($r, $r['ok'] ? 200 : 400);
        case 'seat':
            if ($method === 'GET') {
                $p = VanQuest::byToken($t);
                if (!$p) json_out(['ok' => false, 'message' => 'This link is not valid any more. Join again for a new one.'], 404);
                json_out(['ok' => true, 'seated' => $p['confirmed_at'] !== '', 'first_name' => $p['first_name'], 'sex' => $p['sex'], 'letter' => VanQuest::letter($p)]);
            }
            $r = VanQuest::confirm($t, (string) ($in['first_name'] ?? ''), (string) ($in['sex'] ?? ''));
            if (!$r['ok']) json_out($r, isset($r['errors']['token']) ? 404 : 400);
            json_out(['ok' => true] + VanQuest::community($r['player']));
        case 'community':
            $p = VanQuest::byToken($t);
            if (!$p) json_out(['ok' => false, 'message' => 'This link is not valid any more.'], 404);
            if ($p['confirmed_at'] === '') json_out(['ok' => false, 'message' => 'Confirm your seat first.', 'confirm' => true], 409);
            json_out(['ok' => true] + VanQuest::community($p));
        case 'interview':
            if ($method !== 'POST') json_out(['ok' => false, 'message' => 'POST required.'], 405);
            $r = VanQuest::requestInterview($t, (string) ($in['first_name'] ?? ''), (string) ($in['sex'] ?? ''), (string) ($in['phone'] ?? ''), (string) ($in['note'] ?? ''));
            json_out($r, $r['ok'] ? 200 : 400);
    }
} catch (Throwable $e) {
    error_log('[vanquest] ' . $action . ': ' . $e->getMessage());
    json_out(['ok' => false, 'message' => 'Something went wrong. Try again.'], 500);
}
