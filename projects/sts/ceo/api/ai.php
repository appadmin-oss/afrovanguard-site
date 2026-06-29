<?php
// ─────────────────────────────────────────────────────────────────────
// STS · AI Proxy
//
// Primary backend: Pollinations AI (text.pollinations.ai)
//   — Free, no API key, no quota tracking, no project setup.
//   — POST to /openai/chat/completions (OpenAI-compatible schema).
//
// Fallback: Gemini (if AI_USE_GEMINI is true AND GEMINI_API_KEY is set).
//
// Diagnostic mode:
//   https://yourdomain.com/api/ai.php?diag=1
//
// Handles: centre_match, session_brief, share_copy, note_suggest
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/_helpers.php';

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'AI server error.', 'fatal' => $e['message']]);
        }
        error_log('[STS AI FATAL] ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    }
});

sts_cors_and_json();

// ── Diagnostic mode ──────────────────────────────────────────────────
if (isset($_GET['diag'])) {
    sts_ai_diagnostic();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sts_fail('POST only', 405);
if (!sts_rate_limit('ai', AI_RATE_LIMIT)) sts_fail('AI rate limit reached.', 429);

if (!function_exists('curl_init')) sts_fail('PHP curl extension not loaded.', 500);

$body = sts_read_json_body();
$action = $body['action'] ?? '';
$data   = $body['data']   ?? [];

if (!is_array($data)) sts_fail('Invalid data payload.');

foreach ($data as $k => $v) {
    if (is_string($v)) $data[$k] = sts_sanitize($v, 400);
}

switch ($action) {
    case 'centre_match':  $prompt = sts_prompt_centre_match($data); break;
    case 'session_brief': $prompt = sts_prompt_session_brief($data); break;
    case 'share_copy':    $prompt = sts_prompt_share_copy($data); break;
    case 'note_suggest':  $prompt = sts_prompt_note_suggest($data); break;
    default: sts_fail('Unknown AI action.');
}

$result = sts_ai_call($prompt);
if ($result['ok']) sts_ok($result['text']);
else sts_fail($result['error'], $result['code'] ?? 502);


// ─── AI bridge — Pollinations primary, Gemini fallback ───────────────

function sts_ai_call($prompt) {
    // Try Pollinations first (no key required)
    $r = sts_pollinations_call($prompt);
    if ($r['ok']) return $r;
    $first_error = $r;

    // Fall back to Gemini only if explicitly enabled + configured
    if (defined('AI_USE_GEMINI') && AI_USE_GEMINI &&
        defined('GEMINI_API_KEY') && GEMINI_API_KEY !== 'YOUR_GEMINI_API_KEY_HERE' && !empty(GEMINI_API_KEY)) {
        $g = sts_gemini_call($prompt);
        if ($g['ok']) return $g;
    }
    return $first_error;
}

/**
 * Pollinations text API — OpenAI-compatible chat completions schema.
 * No API key, no quota. Returns ['ok'=>true,'text'=>...] or ['ok'=>false,'error'=>...].
 *
 * Endpoint: POST https://text.pollinations.ai/openai/chat/completions
 * Body: { messages: [...], model: "openai", seed?: int }
 */
function sts_pollinations_call($prompt) {
    $url = 'https://text.pollinations.ai/openai/chat/completions';
    $model = defined('POLLINATIONS_MODEL') ? POLLINATIONS_MODEL : 'openai';

    $payload = json_encode([
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'model'    => $model,
        'jsonMode' => false,
        'private'  => true,           // don't include in their public feed
        'seed'     => mt_rand(1, 999999),
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw   = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    // Retry without strict SSL only on CA-bundle errors
    if ($raw === false && ($errno === 60 || $errno === 77)) {
        error_log('[STS Pollinations] SSL CA error — retrying without verification');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $raw   = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err   = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
    }

    if ($raw === false) {
        error_log('[STS Pollinations] curl ' . $errno . ': ' . $err);
        return ['ok' => false, 'error' => 'Network error reaching AI service.', 'code' => 502];
    }
    if ($code >= 400) {
        $snippet = substr((string)$raw, 0, 300);
        error_log('[STS Pollinations] HTTP ' . $code . ' · ' . $snippet);
        return ['ok' => false, 'error' => 'AI service HTTP ' . $code, 'code' => 502];
    }

    $json = json_decode($raw, true);
    // OpenAI-compatible response shape: { choices: [{ message: { content } }] }
    $text = $json['choices'][0]['message']['content'] ?? null;
    // Some pollinations responses may return plain text instead of JSON
    if (!$text && is_string($raw) && strlen($raw) > 10 && $raw[0] !== '<') {
        $text = $raw;
    }
    if (!$text) {
        error_log('[STS Pollinations] empty/unparseable response · ' . substr($raw, 0, 200));
        return ['ok' => false, 'error' => 'AI returned empty response.', 'code' => 502];
    }

    // Strip markdown fences
    $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
    $text = preg_replace('/\s*```$/m', '', $text);
    return ['ok' => true, 'text' => trim($text), 'backend' => 'pollinations'];
}

/**
 * Gemini fallback — kept for hosts that have a configured key and want
 * to override Pollinations. Set AI_USE_GEMINI=true in config.php.
 */
function sts_gemini_call($prompt) {
    $models = [defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-1.5-flash-latest'];
    foreach (['gemini-1.5-flash-latest', 'gemini-1.5-flash', 'gemini-2.0-flash', 'gemini-2.5-flash'] as $m) {
        if (!in_array($m, $models)) $models[] = $m;
    }
    $payload = json_encode([
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 800, 'topP' => 0.9],
    ]);
    foreach ($models as $model) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_API_KEY],
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw   = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($raw === false) continue;
        if ($code === 404) continue;
        $json = json_decode($raw, true);
        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$text) continue;
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);
        return ['ok' => true, 'text' => trim($text), 'backend' => 'gemini:' . $model];
    }
    return ['ok' => false, 'error' => 'Gemini fallback also failed.', 'code' => 502];
}


// ─── Diagnostic ──────────────────────────────────────────────────────
function sts_ai_diagnostic() {
    $report = [
        'ok' => true,
        'environment' => [
            'php_version' => PHP_VERSION,
            'curl_loaded' => function_exists('curl_init'),
            'mbstring'    => function_exists('mb_substr'),
            'openssl'     => extension_loaded('openssl'),
        ],
        'config' => [
            'primary'          => 'pollinations',
            'pollinations_model' => defined('POLLINATIONS_MODEL') ? POLLINATIONS_MODEL : 'openai',
            'gemini_fallback'    => (defined('AI_USE_GEMINI') && AI_USE_GEMINI &&
                                     defined('GEMINI_API_KEY') && GEMINI_API_KEY !== 'YOUR_GEMINI_API_KEY_HERE'),
        ],
        'tests' => [],
    ];
    if (!$report['environment']['curl_loaded']) {
        $report['ok'] = false;
        $report['fix'] = 'PHP curl extension is required. Ask your host to enable php-curl.';
        echo json_encode($report, JSON_PRETTY_PRINT);
        return;
    }

    // Test 1: Pollinations
    $started = microtime(true);
    $r = sts_pollinations_call('Reply with the single word OK.');
    $report['tests'][] = [
        'backend'     => 'pollinations',
        'duration_ms' => round((microtime(true) - $started) * 1000),
        'success'     => $r['ok'],
        'response'    => $r['ok'] ? substr($r['text'], 0, 100) : null,
        'error'       => $r['ok'] ? null : $r['error'],
    ];

    // Test 2: Gemini (if enabled)
    if ($report['config']['gemini_fallback']) {
        $started = microtime(true);
        $g = sts_gemini_call('Reply with the single word OK.');
        $report['tests'][] = [
            'backend'     => 'gemini',
            'duration_ms' => round((microtime(true) - $started) * 1000),
            'success'     => $g['ok'],
            'response'    => $g['ok'] ? substr($g['text'], 0, 100) : null,
            'error'       => $g['ok'] ? null : $g['error'],
            'model_used'  => $g['backend'] ?? null,
        ];
    }

    $any_success = false;
    foreach ($report['tests'] as $t) { if ($t['success']) { $any_success = true; break; } }
    $report['ok'] = $any_success;
    if (!$any_success) {
        $first = $report['tests'][0];
        if (strpos($first['error'] ?? '', 'Network') !== false) {
            $report['fix'] = 'Your host cannot reach text.pollinations.ai. Check outbound HTTPS is allowed.';
        } else {
            $report['fix'] = 'Inspect the tests above. Pollinations should work with no setup; if it doesn\'t, your host may be blocking outbound HTTPS.';
        }
    }
    echo json_encode($report, JSON_PRETTY_PRINT);
}


// ─── Prompt builders ─────────────────────────────────────────────────

function sts_prompt_centre_match($d) {
    $name = $d['name'] ?? '';
    $role = $d['role'] ?? '';
    $org  = $d['org']  ?? '';
    return <<<P
You are an advisor for the Street-To-Stardom 2026 Leadership Series in Alimosho, Lagos.
A distinguished leader is choosing which community centre to speak at.

LEADER PROFILE:
- Name: {$name}
- Role: {$role}
- Organisation: {$org}

AVAILABLE CENTRES:
1. ikotun — Ikotun Hub — Tech & Future Leadership (150 children)
2. ayobo — Ayobo Centre — Civic Leadership & Service (130 children)
3. egbeda — Egbeda Academy — Entrepreneurship & Enterprise (120 children)
4. idimu — Idimu Centre — Arts, Voice & Self-Expression (110 children)
5. mosan — Mosan Community — Stewardship & Environment (95 children)
6. ijaiye — Ijaiye Centre — Discipline & Personal Mastery (145 children)

Suggest TOP 2 centres that match their professional profile.

Return ONLY valid JSON (no markdown, no backticks):
{
  "top_pick":     { "id": "centre_id", "reason": "One compelling sentence connecting their role to the centre's focus." },
  "runner_up":    { "id": "centre_id", "reason": "One alternative sentence." },
  "personal_note": "One warm sentence addressing them by name about the Lagos children they'll meet."
}
Valid centre IDs: ikotun, ayobo, egbeda, idimu, mosan, ijaiye
P;
}

function sts_prompt_session_brief($d) {
    $name = $d['name'] ?? ''; $role = $d['role'] ?? ''; $org = $d['org'] ?? '';
    $centre = $d['centre'] ?? ''; $focus = $d['focus'] ?? '';
    $date = $d['date'] ?? ''; $theme = $d['theme'] ?? ''; $children = $d['children'] ?? '';
    return <<<P
You are a speechwriting advisor for the Street-To-Stardom 2026 Leadership Series.

SPEAKER: {$name} · {$role} · {$org}
SESSION: {$centre} ({$focus})
DATE: {$date} · Theme: "{$theme}"
CHILDREN: {$children} Lagos children
TIME: 9:00–10:00 AM + 30 min mentorship circle

Return ONLY valid JSON (no markdown):
{
  "greeting": "Warm prestigious one-liner using their first name.",
  "talking_points": [
    "First point connecting their expertise to the theme.",
    "Second point connecting their career path to the Lagos children's aspirations.",
    "Third actionable piece of wisdom they could share."
  ],
  "opening_line": "Suggested powerful opening sentence.",
  "closing_prompt": "A closing question or call-to-action.",
  "mentorship_tip": "One specific tip for the 30-minute mentorship circle."
}
P;
}

function sts_prompt_share_copy($d) {
    $honor = $d['honorific'] ?? ''; $name = $d['name'] ?? ''; $role = $d['role'] ?? '';
    $org = $d['org'] ?? ''; $centre = $d['centre'] ?? ''; $date = $d['date'] ?? '';
    $theme = $d['theme'] ?? ''; $children = $d['children'] ?? '';
    $display = trim("$honor $name");
    return <<<P
Generate social media share copy for a leader who confirmed their speaking session
at the Street-To-Stardom 2026 Leadership Series in Alimosho, Lagos.

SPEAKER: {$display}
ROLE: {$role} at {$org}
CENTRE: {$centre}
DATE: {$date}
THEME: "{$theme}"
CHILDREN: {$children} Lagos children

Return ONLY valid JSON (no markdown):
{
  "linkedin": "Professional inspiring LinkedIn post (3-4 sentences). No hashtags, no emojis.",
  "whatsapp": "Warm WhatsApp message (2-3 sentences). No emojis.",
  "twitter":  "Concise X post (under 250 chars)."
}
P;
}

function sts_prompt_note_suggest($d) {
    $name = $d['name'] ?? ''; $role = $d['role'] ?? '';
    $centre = $d['centre'] ?? ''; $focus = $d['focus'] ?? '';
    return <<<P
A leader is writing a brief note to the Street-To-Stardom programme team.
Help craft 3 short (1-2 line) dignified note options.

SPEAKER: {$name} · {$role}
CENTRE: {$centre} (Focus: {$focus})

Return ONLY valid JSON (no markdown):
{
  "suggestions": [
    "Warm and collaborative option.",
    "Excitement about the focus area option.",
    "Brief and authoritative option."
  ]
}
P;
}
