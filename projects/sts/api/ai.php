<?php
/**
 * STS · AI — two-tier provider with Gemini → Pollinations fallback.
 *
 * Usage:
 *   $text = ai_generate("Prompt", ['timeout' => 8, 'json' => false]);
 *
 * Returns string|null. Errors are logged silently; callers degrade gracefully.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

function ai_generate(string $prompt, array $opts = []): ?string
{
    $timeout = (int)($opts['timeout'] ?? 8);
    $json = (bool)($opts['json'] ?? false);

    // Try Gemini 2.0 Flash first
    $out = ai_call_gemini($prompt, $timeout, $json);
    if ($out !== null && trim($out) !== '') return trim($out);

    // Retry once on transient
    usleep(250000);
    $out = ai_call_gemini($prompt, $timeout, $json);
    if ($out !== null && trim($out) !== '') return trim($out);

    // Fall back to Pollinations
    $out = ai_call_pollinations($prompt, $timeout);
    if ($out !== null && trim($out) !== '') return trim($out);

    return null;
}

function ai_call_gemini(string $prompt, int $timeout, bool $json): ?string
{
    $key = env('GEMINI_API_KEY');
    if (!$key) return null;

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($key);
    $payload = [
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature' => 0.4,
            'maxOutputTokens' => 800,
        ],
    ];
    if ($json) $payload['generationConfig']['responseMimeType'] = 'application/json';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code === 0) {
        log_line('ai', 'gemini transport error', ['err' => $err]);
        return null;
    }
    if ($code === 429 || $code >= 500) {
        log_line('ai', "gemini transient $code");
        return null;
    }
    if ($code >= 400) {
        log_line('ai', "gemini hard $code");
        return null;
    }
    $data = json_decode($resp, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    return is_string($text) ? $text : null;
}

function ai_call_pollinations(string $prompt, int $timeout): ?string
{
    $url = 'https://text.pollinations.ai/' . rawurlencode($prompt) . '?model=openai&seed=42';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 400) {
        log_line('ai', "pollinations $code");
        return null;
    }
    return $resp;
}

/** Try to extract a JSON array of strings from a model response, even if the model wrapped it. */
function ai_extract_json_array(string $text): ?array
{
    $text = trim($text);
    // Strip code fences
    $text = preg_replace('/^```(json)?\s*|\s*```$/m', '', $text) ?? $text;
    if (preg_match('/\[[\s\S]*?\]/', $text, $m)) {
        $arr = json_decode($m[0], true);
        if (is_array($arr)) return array_values(array_filter($arr, 'is_string'));
    }
    return null;
}
