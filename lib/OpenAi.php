<?php
/**
 * lib/OpenAi.php — OpenAI provider (chat, tool calling, Whisper transcription).
 *
 * The third model provider alongside Claude and Gemini, and the one that brings
 * two things neither of the others does:
 *
 *   • A very cheap tier. The mini models cost a fraction of a frontier model per
 *     transcript, which matters when the meeting pipeline runs on every meeting
 *     the organisation holds.
 *   • Whisper. A dedicated speech-to-text endpoint that takes a recording
 *     directly, so a meeting with no live notetaker can still be transcribed
 *     from an uploaded file — and it accepts larger uploads than Gemini's
 *     inline-audio path, which caps at about 19MB.
 *
 * Deliberately shaped like Gemini and AvBot so the three are interchangeable:
 * configured() / model() / generate() / transcribeAudio(), plus rawChat() for
 * AvAgent's tool loop. Anything that already falls back between providers picks
 * this up by adding one branch, not by learning a new interface.
 *
 * Config:
 *   OPENAI_API_KEY (or AV_OPENAI_API_KEY)  enables it
 *   AV_OPENAI_MODEL        default gpt-4o-mini — cheap, and enough for minutes
 *   AV_OPENAI_TRANSCRIBE_MODEL  default whisper-1
 *   AV_OPENAI_BASE_URL     override for a gateway, Azure, or any OpenAI-compatible
 *                          endpoint (Groq, Together, OpenRouter, a local llama.cpp)
 */
declare(strict_types=1);

final class OpenAi
{
    const DEFAULT_MODEL      = 'gpt-4o-mini';
    const DEFAULT_TRANSCRIBE = 'whisper-1';
    const DEFAULT_BASE       = 'https://api.openai.com/v1';

    private static function cfg(string $k, string $def = ''): string
    {
        if (class_exists('Config') && Config::has($k)) return Config::str($k, $def);
        return (string) (getenv($k) ?: $def);
    }

    public static function apiKey(): string
    {
        foreach (['OPENAI_API_KEY', 'AV_OPENAI_API_KEY'] as $k) {
            $v = self::cfg($k);
            if ($v !== '') return $v;
        }
        return '';
    }

    public static function configured(): bool { return self::apiKey() !== ''; }
    public static function model(): string { return self::cfg('AV_OPENAI_MODEL', self::DEFAULT_MODEL); }
    public static function transcribeModel(): string { return self::cfg('AV_OPENAI_TRANSCRIBE_MODEL', self::DEFAULT_TRANSCRIBE); }
    private static function base(): string { return rtrim(self::cfg('AV_OPENAI_BASE_URL', self::DEFAULT_BASE), '/'); }

    /**
     * Generate text. Same shape as Gemini::generate() and AvBot::reply().
     * $opts: system (string), max_tokens (int), temperature (float).
     */
    public static function generate(string $prompt, array $opts = []): array
    {
        // The Studio master switch is enforced at the network call so it covers
        // every caller, not only the ones that remember to ask.
        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) {
            return ['ok' => false, 'text' => '', 'error' => 'AI assistance is switched off in the Studio rules.'];
        }
        if (!self::configured()) return ['ok' => false, 'text' => '', 'error' => 'OpenAI is not configured (set OPENAI_API_KEY).'];
        if (trim($prompt) === '') return ['ok' => false, 'text' => '', 'error' => 'Nothing to send.'];

        $messages = [];
        $sys = trim((string) ($opts['system'] ?? ''));
        if ($sys !== '') $messages[] = ['role' => 'system', 'content' => $sys];
        $messages[] = ['role' => 'user', 'content' => mb_substr($prompt, 0, 200000)];

        $res = self::rawChat([
            'messages'    => $messages,
            'max_tokens'  => max(64, min(16384, (int) ($opts['max_tokens'] ?? 2048))),
            'temperature' => (float) ($opts['temperature'] ?? 0.2),
        ]);
        if (isset($res['__error'])) return ['ok' => false, 'text' => '', 'error' => (string) $res['__error']];

        $text = trim((string) ($res['choices'][0]['message']['content'] ?? ''));
        if ($text === '') {
            // A refusal comes back as an empty content with a refusal field —
            // report that honestly rather than as an empty success.
            $refusal = (string) ($res['choices'][0]['message']['refusal'] ?? '');
            return ['ok' => false, 'text' => '', 'error' => $refusal !== '' ? 'declined: ' . $refusal : 'Empty OpenAI response.'];
        }
        return ['ok' => true, 'text' => $text, 'error' => null];
    }

    /**
     * The raw chat/completions call, for callers that need multi-turn messages or
     * tool calling — principally AvAgent. Keeps the key, endpoint and master
     * switch here rather than growing a second HTTP client beside this one.
     *
     * @return array decoded response, or ['__error'=>string]
     */
    public static function rawChat(array $payload): array
    {
        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) {
            return ['__error' => 'AI assistance is switched off in the Studio rules.'];
        }
        if (!self::configured()) return ['__error' => 'OpenAI is not configured (set OPENAI_API_KEY).'];
        if (empty($payload['model'])) $payload['model'] = self::model();
        return self::http(self::base() . '/chat/completions', $payload);
    }

    /**
     * Transcribe a recording with Whisper.
     *
     * Unlike Gemini's inline-audio path this is a real multipart upload, so it
     * takes the ~25MB the API allows rather than the ~19MB base64 inlining
     * permits — which is the difference between "most meetings" and "short ones".
     */
    public static function transcribeAudio(string $bytes, string $mime, string $instruction = ''): array
    {
        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) {
            return ['ok' => false, 'text' => '', 'error' => 'AI assistance is switched off in the Studio rules.'];
        }
        if (!self::configured()) return ['ok' => false, 'text' => '', 'error' => 'OpenAI is not configured (set OPENAI_API_KEY).'];
        if ($bytes === '') return ['ok' => false, 'text' => '', 'error' => 'No audio provided.'];
        if (strlen($bytes) > 25 * 1024 * 1024) {
            return ['ok' => false, 'text' => '', 'error' => 'Recording too large (max ~25MB). Use the Google Meet transcript, or split the file.'];
        }
        if (!function_exists('curl_init')) return ['ok' => false, 'text' => '', 'error' => 'cURL is unavailable.'];

        $ext = self::extFor($mime);
        $boundary = '----av' . bin2hex(random_bytes(8));
        $parts = [
            ['name' => 'model', 'value' => self::transcribeModel()],
            ['name' => 'response_format', 'value' => 'text'],
        ];
        // Whisper's "prompt" biases spelling of names and jargon — worth passing
        // when the caller knows what the meeting was about.
        if (trim($instruction) !== '') $parts[] = ['name' => 'prompt', 'value' => mb_substr(trim($instruction), 0, 800)];

        $body = '';
        foreach ($parts as $p) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$p['name']}\"\r\n\r\n{$p['value']}\r\n";
        }
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"audio.{$ext}\"\r\n"
               . "Content-Type: " . self::normMime($mime) . "\r\n\r\n" . $bytes . "\r\n--{$boundary}--\r\n";

        $ch = curl_init(self::base() . '/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 300,      // a long recording genuinely takes minutes
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . self::apiKey(),
                'Content-Type: multipart/form-data; boundary=' . $boundary,
            ],
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (!is_string($resp)) return ['ok' => false, 'text' => '', 'error' => 'transport: ' . $err];
        if ($code >= 400) {
            error_log('[openai] transcribe ' . $code . ': ' . substr($resp, 0, 300));
            $d = json_decode($resp, true);
            return ['ok' => false, 'text' => '', 'error' => is_array($d) ? (string) ($d['error']['message'] ?? ('HTTP ' . $code)) : ('HTTP ' . $code)];
        }
        // response_format=text returns the transcript as a bare body.
        $text = trim($resp);
        if ($text === '') return ['ok' => false, 'text' => '', 'error' => 'Empty transcript.'];
        return ['ok' => true, 'text' => $text, 'error' => null];
    }

    private static function normMime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        $ok = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/webm', 'audio/ogg',
               'audio/mp4', 'audio/m4a', 'audio/x-m4a', 'audio/flac', 'video/mp4', 'video/webm'];
        if (in_array($mime, $ok, true)) return $mime === 'audio/mp3' ? 'audio/mpeg' : $mime;
        return 'audio/mpeg';
    }

    /** Whisper decides the decoder partly from the filename, so it must match. */
    private static function extFor(string $mime): string
    {
        switch (self::normMime($mime)) {
            case 'audio/wav': case 'audio/x-wav':   return 'wav';
            case 'audio/webm': case 'video/webm':   return 'webm';
            case 'audio/ogg':                       return 'ogg';
            case 'audio/mp4': case 'video/mp4':     return 'mp4';
            case 'audio/m4a': case 'audio/x-m4a':   return 'm4a';
            case 'audio/flac':                      return 'flac';
            default:                                return 'mp3';
        }
    }

    /** POST JSON. Returns the decoded body or ['__error'=>string]. */
    private static function http(string $url, array $payload): array
    {
        if (!function_exists('curl_init')) return ['__error' => 'curl unavailable'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'authorization: Bearer ' . self::apiKey(),
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) return ['__error' => 'transport: ' . $err];
        $d = json_decode($body, true);
        if (!is_array($d)) return ['__error' => 'bad response (HTTP ' . $code . ')'];
        if ($code >= 400) {
            error_log('[openai] ' . $code . ': ' . substr($body, 0, 300));
            return ['__error' => (string) ($d['error']['message'] ?? ('HTTP ' . $code))];
        }
        return $d;
    }
}
