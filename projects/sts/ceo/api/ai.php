<?php
// ─────────────────────────────────────────────────────────────────────
// STS · AI Proxy (Gemini)
// Handles: ai_centre_match, ai_session_brief, ai_share_copy, ai_note_suggest
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/_helpers.php';
sts_cors_and_json();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sts_fail('POST only', 405);
if (!sts_rate_limit('ai', AI_RATE_LIMIT)) sts_fail('AI rate limit reached. Please try again later.', 429);

// Anti-abuse for this BILLABLE Gemini proxy. (1) The request must come from the
// STS dashboard's own host — blocks anonymous curl / cross-site callers.
// (2) If STS_AI_ACCESS_KEY is configured, require it in the X-STS-Key header.
// Together with the now-un-spoofable per-IP rate limit above this stops
// anonymous cost abuse; the complete fix is to gate the dashboard behind login.
$sts_allowed_host = parse_url(ALLOWED_ORIGIN, PHP_URL_HOST) ?: 'afrovanguard.org.ng';
$sts_req_host = '';
foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $sts_h) {
    if (!empty($_SERVER[$sts_h])) { $sts_req_host = (string) parse_url((string) $_SERVER[$sts_h], PHP_URL_HOST); break; }
}
$sts_norm = static fn($h) => strtolower(preg_replace('/^www\./', '', (string) $h));
if ($sts_norm($sts_req_host) !== $sts_norm($sts_allowed_host)) {
    sts_fail('Forbidden origin.', 403);
}
$sts_ai_key = defined('STS_AI_ACCESS_KEY') ? (string) STS_AI_ACCESS_KEY : (string) getenv('STS_AI_ACCESS_KEY');
if ($sts_ai_key !== '') {
    $sts_provided = (string) ($_SERVER['HTTP_X_STS_KEY'] ?? '');
    if ($sts_provided === '' || !hash_equals($sts_ai_key, $sts_provided)) {
        sts_fail('Unauthorized.', 401);
    }
}

if (GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE' || empty(GEMINI_API_KEY)) {
    sts_fail('AI not configured.', 503);
}

$body = sts_read_json_body();
$action = $body['action'] ?? '';
$data   = $body['data']   ?? [];

if (!is_array($data)) sts_fail('Invalid data payload.');

// Sanitize all string inputs
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

$text = sts_gemini_call($prompt);
if ($text === false) sts_fail('AI request failed. Please try again.', 502);

sts_ok($text);


// ─── Gemini bridge ───────────────────────────────────────────────────

function sts_gemini_call($prompt) {
    $payload = json_encode([
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 800, 'topP' => 0.9],
        'safetySettings'   => [
            ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
        ],
    ]);

    // Send the API key via the x-goog-api-key header instead of the URL query
    // string, so it can't leak through curl-verbose / proxy logs / stack traces.
    $url = rtrim(preg_replace('/([?&])key=[^&]*/', '$1', GEMINI_URL), '?&');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_API_KEY],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code >= 400) {
        error_log("[STS AI] HTTP $code");
        return false;
    }

    $json = json_decode($raw, true);
    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) return false;

    // Strip markdown fences
    $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
    $text = preg_replace('/\s*```$/m', '', $text);
    return trim($text);
}


// ─── Prompt builders ─────────────────────────────────────────────────

function sts_prompt_centre_match($d) {
    $name = $d['name']  ?? '';
    $role = $d['role']  ?? '';
    $org  = $d['org']   ?? '';
    return <<<P
You are an advisor for the Street-To-Stardom 2026 Leadership Series in Alimosho, Lagos.
A distinguished leader is choosing which community centre to speak at.

LEADER PROFILE:
- Name: {$name}
- Role: {$role}
- Organisation: {$org}

AVAILABLE CENTRES:
1. ikotun — Ikotun Hub — Tech & Future Leadership (150 children) — Computer literacy, robotics
2. ayobo — Ayobo Centre — Civic Leadership & Service (130 children) — Public charters, civic engagement
3. egbeda — Egbeda Academy — Entrepreneurship & Enterprise (120 children) — FULLY HONOURED (exclude from recommendation)
4. idimu — Idimu Centre — Arts, Voice & Self-Expression (110 children) — Storytelling, performance
5. mosan — Mosan Community — Stewardship & Environment (95 children) — Climate, water
6. ijaiye — Ijaiye Centre — Discipline & Personal Mastery (145 children) — Habits, time-mastery

Suggest TOP 2 centres that match their professional profile. EXCLUDE Egbeda.

Return ONLY valid JSON (no markdown, no backticks):
{
  "top_pick":     { "id": "centre_id", "reason": "One compelling sentence connecting their role to the centre's focus." },
  "runner_up":    { "id": "centre_id", "reason": "One alternative sentence." },
  "personal_note": "One warm sentence addressing them by name about the children they'll meet."
}
Valid centre IDs: ikotun, ayobo, idimu, mosan, ijaiye
P;
}

function sts_prompt_session_brief($d) {
    $name     = $d['name']     ?? '';
    $role     = $d['role']     ?? '';
    $org      = $d['org']      ?? '';
    $centre   = $d['centre']   ?? '';
    $focus    = $d['focus']    ?? '';
    $date     = $d['date']     ?? '';
    $theme    = $d['theme']    ?? '';
    $children = $d['children'] ?? '';
    return <<<P
You are a speechwriting advisor for the Street-To-Stardom 2026 Leadership Series.
A leader has confirmed their session. Generate their personalised brief.

SPEAKER: {$name} · {$role} · {$org}
SESSION: {$centre} ({$focus})
DATE: {$date} · Theme: "{$theme}"
CHILDREN: {$children}
TIME: 9:00–10:00 AM + 30 min mentorship circle

Return ONLY valid JSON (no markdown):
{
  "greeting": "Warm prestigious one-liner using their first name.",
  "talking_points": [
    "First point connecting their expertise to the theme.",
    "Second point connecting their career path to the children's aspirations.",
    "Third actionable piece of wisdom they could share."
  ],
  "opening_line": "Suggested powerful opening sentence they could use.",
  "closing_prompt": "A closing question or call-to-action for lasting impact.",
  "mentorship_tip": "One specific tip for the 30-minute mentorship circle."
}
P;
}

function sts_prompt_share_copy($d) {
    $honor    = $d['honorific'] ?? '';
    $name     = $d['name']      ?? '';
    $role     = $d['role']      ?? '';
    $org      = $d['org']       ?? '';
    $centre   = $d['centre']    ?? '';
    $date     = $d['date']      ?? '';
    $theme    = $d['theme']     ?? '';
    $children = $d['children']  ?? '';
    $display = trim("$honor $name");
    return <<<P
Generate social media share copy for a leader who confirmed their speaking session
at the Street-To-Stardom 2026 Leadership Series in Alimosho, Lagos.

SPEAKER: {$display}
ROLE: {$role} at {$org}
CENTRE: {$centre}
DATE: {$date}
THEME: "{$theme}"
CHILDREN: {$children}

Return ONLY valid JSON (no markdown):
{
  "linkedin": "Professional inspiring LinkedIn post (3-4 sentences, calls peers to join). No hashtags, no emojis.",
  "whatsapp": "Warm WhatsApp message (2-3 sentences). No emojis.",
  "twitter":  "Concise X post (under 250 chars, shareable)."
}
P;
}

function sts_prompt_note_suggest($d) {
    $name   = $d['name']   ?? '';
    $role   = $d['role']   ?? '';
    $centre = $d['centre'] ?? '';
    $focus  = $d['focus']  ?? '';
    return <<<P
A leader is writing a brief note to the Street-To-Stardom programme producer.
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
