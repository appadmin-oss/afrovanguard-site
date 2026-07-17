<?php
/**
 * lib/Tts.php — neural text-to-speech for the Diary "listen" reader.
 *
 * Replaces the robotic browser speechSynthesis with real, human-like narration:
 * the server synthesises an audio clip per sentence, caches it, and the page
 * plays it through a normal <audio> element — so it works on every browser and
 * stays in sync with the existing word/sentence highlighting.
 *
 * Pluggable engine, selected by AV_TTS_ENGINE (default "openai"). Dependency-free
 * (curl). Configure entirely via .env (no SSH / Composer needed):
 *
 *   AV_TTS_ENGINE   openai | elevenlabs | mock        (default openai)
 *   AV_TTS_API_KEY  the engine's API key (falls back to AV_OPENAI_API_KEY)
 *   AV_TTS_VOICE    voice name      (openai: alloy|echo|fable|onyx|nova|shimmer; default nova)
 *   AV_TTS_VOICE_ID elevenlabs voice id
 *   AV_TTS_MODEL    override model  (openai default tts-1; 11labs default eleven_turbo_v2_5)
 *
 * "mock" is a built-in test engine that returns a short tone (no network, no
 * cost) so you can preview the reader UI before wiring a paid key.
 */
declare(strict_types=1);

final class Tts
{
    /**
     * Selected engine. If AV_TTS_ENGINE is set explicitly it wins; otherwise we
     * auto-detect from which key/voice is configured, so simply "adding the
     * ElevenLabs key" turns on ElevenLabs without a second setting.
     */
    public static function engine(): string
    {
        $e = strtolower(trim((string) getenv('AV_TTS_ENGINE')));
        if ($e !== '') return $e;
        if (self::elevenKey() !== '' || trim((string) getenv('AV_TTS_VOICE_ID')) !== '') return 'elevenlabs';
        // A generic key: ElevenLabs keys start "sk_"; OpenAI's start "sk-".
        $k = trim((string) getenv('AV_TTS_API_KEY'));
        if ($k !== '') return str_starts_with($k, 'sk_') ? 'elevenlabs' : 'openai';
        return 'openai';
    }

    /** Last provider failure (engine + HTTP code + short body), for diagnostics. */
    private static string $lastError = '';
    public static function lastError(): string { return self::$lastError; }

    /** Non-sensitive status, for the ?probe diagnostic (never returns the key). */
    public static function status(): array
    {
        return ['engine' => self::engine(), 'available' => self::available(), 'has_key' => self::key() !== '',
                'voice' => self::voice(), 'ext' => self::ext(), 'model' => (string) (getenv('AV_TTS_MODEL') ?: '')];
    }

    /** ElevenLabs key under any of the common variable names. */
    private static function elevenKey(): string
    {
        return (string) (getenv('AV_ELEVENLABS_API_KEY') ?: getenv('ELEVENLABS_API_KEY') ?: getenv('ELEVEN_API_KEY') ?: '');
    }

    /** The API key for the active engine. */
    private static function key(): string
    {
        if (self::engine() === 'elevenlabs') {
            return (string) (self::elevenKey() ?: getenv('AV_TTS_API_KEY') ?: '');
        }
        return (string) (getenv('AV_TTS_API_KEY') ?: getenv('AV_OPENAI_API_KEY') ?: getenv('OPENAI_API_KEY') ?: '');
    }

    /** Is the reader's neural voice usable right now? Drives graceful fallback. */
    public static function available(): bool
    {
        switch (self::engine()) {
            case 'mock':       return true;
            case 'openai':
            case 'elevenlabs': return self::key() !== '';
            default:           return false; // engine not wired → fall back to browser TTS
        }
    }

    public static function voice(): string
    {
        if (self::engine() === 'elevenlabs') {
            // ElevenLabs addresses voices by ID, not name. Default to "Rachel"
            // (a warm, clear narration voice). Override with AV_TTS_VOICE_ID.
            $vid = trim((string) getenv('AV_TTS_VOICE_ID'));
            return $vid !== '' ? $vid : '21m00Tcm4TlvDq8ikWAM';
        }
        $v = trim((string) getenv('AV_TTS_VOICE'));
        return $v !== '' ? $v : 'nova';
    }

    /** Content-Type for the produced audio. */
    public static function mimeType(): string { return self::engine() === 'mock' ? 'audio/wav' : 'audio/mpeg'; }
    public static function ext(): string { return self::engine() === 'mock' ? 'wav' : 'mp3'; }

    /**
     * Synthesise $text to audio bytes, or null on failure.
     */
    public static function synthesize(string $text, ?string $voice = null): ?string
    {
        $text = trim($text);
        if ($text === '') return null;
        $voice = $voice ?: self::voice();
        switch (self::engine()) {
            case 'mock':       return self::tone($text);
            case 'openai':     return self::openai($text, $voice);
            case 'elevenlabs': return self::elevenlabs($text, $voice);
            default:           return null;
        }
    }

    /* ── OpenAI (https://platform.openai.com/docs/guides/text-to-speech) ── */
    private static function openai(string $text, string $voice): ?string
    {
        $key = self::key();
        if ($key === '') return null;
        $payload = json_encode([
            'model' => getenv('AV_TTS_MODEL') ?: 'tts-1',
            'voice' => $voice ?: 'nova',
            'input' => $text,
            'response_format' => 'mp3',
        ]);
        [$body, $code, $ctype] = self::http('https://api.openai.com/v1/audio/speech', $payload,
            ['Authorization: Bearer ' . $key, 'Content-Type: application/json']);
        // Errors come back as JSON; audio comes back as binary.
        if ($code === 200 && $body !== '' && stripos($ctype, 'application/json') === false) return $body;
        if ($body !== '') {   // keep the network error from http() when there was no response
            self::$lastError = 'openai ' . $code . ': ' . substr(preg_replace('/\s+/', ' ', $body), 0, 200);
            error_log('[AV-TTS] OpenAI ' . $code . ': ' . substr($body, 0, 300));
        }
        return null;
    }

    /* ── ElevenLabs (https://elevenlabs.io/docs/api-reference/text-to-speech) ──
       Voice, model and voice_settings are all env-tunable so the narration can be
       dialed in without code changes:
         AV_TTS_VOICE_ID    the voice (default "Rachel" 21m00Tcm4TlvDq8ikWAM)
         AV_TTS_MODEL       eleven_turbo_v2_5 (fast/cheap, default) |
                            eleven_multilingual_v2 (highest quality)
         AV_TTS_STABILITY   0..1  (default 0.5)   — steadiness vs. expressiveness
         AV_TTS_SIMILARITY  0..1  (default 0.75)  — closeness to the source voice
         AV_TTS_STYLE       0..1  (default 0.0)   — style exaggeration           */
    private static function elevenlabs(string $text, string $voice): ?string
    {
        $key = self::key();
        if ($key === '') return null;
        $vid = trim((string) getenv('AV_TTS_VOICE_ID')) ?: ($voice ?: '21m00Tcm4TlvDq8ikWAM');
        $clamp = static fn($v, $d) => is_numeric($v) ? max(0.0, min(1.0, (float) $v)) : $d;
        $payload = json_encode([
            'text'     => $text,
            'model_id' => getenv('AV_TTS_MODEL') ?: 'eleven_turbo_v2_5',
            'voice_settings' => [
                'stability'        => $clamp(getenv('AV_TTS_STABILITY'), 0.5),
                'similarity_boost' => $clamp(getenv('AV_TTS_SIMILARITY'), 0.75),
                'style'            => $clamp(getenv('AV_TTS_STYLE'), 0.0),
                'use_speaker_boost' => true,
            ],
        ]);
        [$body, $code, $ctype] = self::http(
            'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($vid) . '?output_format=mp3_44100_128',
            $payload, ['xi-api-key: ' . $key, 'Content-Type: application/json', 'Accept: audio/mpeg']);
        if ($code === 200 && $body !== '' && stripos($ctype, 'application/json') === false) return $body;
        if ($body !== '') {   // keep the network error from http() when there was no response
            self::$lastError = 'elevenlabs ' . $code . ': ' . substr(preg_replace('/\s+/', ' ', $body), 0, 200);
            error_log('[AV-TTS] ElevenLabs ' . $code . ': ' . substr($body, 0, 300));
        }
        return null;
    }

    private static function http(string $url, string $payload, array $headers): array
    {
        if (!function_exists('curl_init')) return ['', 0, ''];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 12,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if ($body === false) { self::$lastError = 'network: ' . curl_error($ch); error_log('[AV-TTS] curl: ' . curl_error($ch)); }
        curl_close($ch);
        return [$body === false ? '' : $body, $code, $ctype];
    }

    /* ── Built-in "mock" engine: a short WAV tone, length scaled to the text,
         so the reader UI can be exercised with no key/network/cost. ── */
    private static function tone(string $text): string
    {
        $rate = 8000;
        $secs = max(0.6, min(8.0, str_word_count($text) / 2.7)); // ~ natural reading pace
        $n = (int) ($rate * $secs);
        $data = '';
        for ($i = 0; $i < $n; $i++) {
            // very quiet 220Hz sine so it's audibly "playing" without being annoying
            $data .= pack('s', (int) (1200 * sin(2 * M_PI * 220 * $i / $rate)));
        }
        $sz = strlen($data);
        return 'RIFF' . pack('V', 36 + $sz) . 'WAVEfmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1)
            . pack('V', $rate) . pack('V', $rate * 2) . pack('v', 2) . pack('v', 16)
            . 'data' . pack('V', $sz) . $data;
    }
}
