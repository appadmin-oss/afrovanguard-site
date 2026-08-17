<?php
/**
 * lib/Gemini.php — Google Gemini (Flash) provider.
 *
 * A thin client for the Gemini generateContent API, used for the meeting
 * pipeline: transcribing recorded audio, and turning transcripts into
 * structured minutes / logs. Flash models are fast and cheap, which suits
 * high-volume meeting processing.
 *
 * Config:
 *   AV_GEMINI_API_KEY (or GEMINI_API_KEY / GOOGLE_AI_API_KEY)  enables it
 *   AV_GEMINI_MODEL        default gemini-2.0-flash
 *   AV_GEMINI_BASE_URL     override the API base (gateway / test mock)
 */
declare(strict_types=1);

final class Gemini
{
    const DEFAULT_MODEL = 'gemini-2.0-flash';
    const DEFAULT_BASE  = 'https://generativelanguage.googleapis.com/v1beta';

    private static function cfg(string $k, string $def = ''): string
    {
        if (class_exists('Config') && Config::has($k)) return Config::str($k, $def);
        return (string) (getenv($k) ?: $def);
    }

    public static function apiKey(): string
    {
        foreach (['AV_GEMINI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_AI_API_KEY'] as $k) {
            $v = self::cfg($k);
            if ($v !== '') return $v;
        }
        return '';
    }

    public static function configured(): bool { return self::apiKey() !== ''; }
    public static function model(): string { return self::cfg('AV_GEMINI_MODEL', self::DEFAULT_MODEL); }
    private static function base(): string { return rtrim(self::cfg('AV_GEMINI_BASE_URL', self::DEFAULT_BASE), '/'); }

    /**
     * Generate text. Returns ['ok'=>bool,'text'=>string,'error'=>?string].
     * $opts: system (string), max_tokens (int), temperature (float),
     *        parts (array of extra content parts, e.g. inline audio).
     */
    public static function generate(string $prompt, array $opts = []): array
    {
        // Master switch enforced at the network call so it covers every caller.
        // Kept out of configured(), which must stay truthful about credentials for
        // the System health page.
        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) {
            return ['ok' => false, 'text' => '', 'error' => 'AI assistance is switched off in the Studio rules.'];
        }
        if (!self::configured()) return ['ok' => false, 'text' => '', 'error' => 'Gemini is not configured (set AV_GEMINI_API_KEY).'];

        $parts = [];
        if (trim($prompt) !== '') $parts[] = ['text' => mb_substr($prompt, 0, 200000)];
        foreach (($opts['parts'] ?? []) as $p) { if (is_array($p)) $parts[] = $p; }
        if (!$parts) return ['ok' => false, 'text' => '', 'error' => 'Nothing to send.'];

        $payload = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'maxOutputTokens' => max(64, min(8192, (int) ($opts['max_tokens'] ?? 2048))),
                'temperature'     => (float) ($opts['temperature'] ?? 0.2),
            ],
        ];
        if (trim((string) ($opts['system'] ?? '')) !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => (string) $opts['system']]]];
        }

        $model = self::model();
        $url = self::base() . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode(self::apiKey());
        $res = self::http($url, $payload);
        if (isset($res['__error'])) return ['ok' => false, 'text' => '', 'error' => $res['__error']];

        // A blocked prompt returns promptFeedback.blockReason and no candidates.
        if (!empty($res['promptFeedback']['blockReason'])) {
            return ['ok' => false, 'text' => '', 'error' => 'blocked: ' . $res['promptFeedback']['blockReason']];
        }
        $text = '';
        foreach (($res['candidates'][0]['content']['parts'] ?? []) as $p) {
            if (isset($p['text'])) $text .= (string) $p['text'];
        }
        $text = trim($text);
        if ($text === '') return ['ok' => false, 'text' => '', 'error' => 'Empty Gemini response.'];
        return ['ok' => true, 'text' => $text, 'error' => null];
    }

    /**
     * Transcribe recorded audio to text. $mime like 'audio/mpeg','audio/wav',
     * 'audio/webm','audio/ogg','audio/mp4'. Returns the generate() shape.
     */
    public static function transcribeAudio(string $bytes, string $mime, string $instruction = ''): array
    {
        if ($bytes === '') return ['ok' => false, 'text' => '', 'error' => 'No audio provided.'];
        // Inline data is capped ~20MB by the API; guard so we fail cleanly.
        if (strlen($bytes) > 19 * 1024 * 1024) return ['ok' => false, 'text' => '', 'error' => 'Recording too large (max ~19MB inline). Use the Google Meet transcript instead.'];
        $mime = self::normMime($mime);
        $prompt = $instruction !== '' ? $instruction
            : 'Transcribe this meeting audio verbatim. Label speakers as Speaker 1, Speaker 2, … when distinguishable. Output plain text only.';
        return self::generate($prompt, [
            'max_tokens' => 8192,
            'temperature' => 0.0,
            'parts' => [['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]]],
        ]);
    }

    private static function normMime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        $ok = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/webm', 'audio/ogg', 'audio/mp4', 'audio/aac', 'audio/flac', 'video/mp4', 'video/webm'];
        if (in_array($mime, $ok, true)) return $mime === 'audio/mp3' ? 'audio/mpeg' : $mime;
        return 'audio/mpeg';
    }

    private static function http(string $url, array $payload): array
    {
        if (!function_exists('curl_init')) return ['__error' => 'curl unavailable'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['content-type: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) return ['__error' => 'transport: ' . $err];
        $d = json_decode($body, true);
        if (!is_array($d)) return ['__error' => 'bad response (HTTP ' . $code . ')'];
        if ($code >= 400) {
            $msg = $d['error']['message'] ?? ('HTTP ' . $code);
            error_log('[gemini] ' . $code . ': ' . substr($body, 0, 300));
            return ['__error' => (string) $msg];
        }
        return $d;
    }
}
