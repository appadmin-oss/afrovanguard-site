<?php
/**
 * lib/RecallBot.php — Recall.ai recording-bot provider.
 *
 * Recall.ai runs a bot that joins a meeting (Google Meet / Zoom / Teams),
 * records it, and transcribes it. We create a bot when a meeting is scheduled
 * with auto-record on, then either receive a webhook when it finishes or fetch
 * the transcript on demand. The transcript is handed to Meetings for Gemini
 * Flash structuring.
 *
 * Config:
 *   AV_RECALL_API_KEY        enables it
 *   AV_RECALL_REGION         API region host prefix (default us-west-2)
 *   AV_RECALL_BOT_NAME       display name in the meeting (default "Afrovanguard Notetaker")
 *   AV_RECALL_WEBHOOK_TOKEN  shared token appended to the webhook URL (auth)
 *   AV_RECALL_BOT_CONFIG     optional raw JSON merged into the create-bot body
 */
declare(strict_types=1);

final class RecallBot
{
    private static function cfg(string $k, string $def = ''): string
    {
        if (class_exists('Config') && Config::has($k)) return Config::str($k, $def);
        return (string) (getenv($k) ?: $def);
    }

    public static function apiKey(): string { return self::cfg('AV_RECALL_API_KEY'); }
    public static function configured(): bool { return self::apiKey() !== ''; }
    public static function webhookToken(): string { return self::cfg('AV_RECALL_WEBHOOK_TOKEN'); }
    private static function botName(): string { return self::cfg('AV_RECALL_BOT_NAME', 'Afrovanguard Notetaker'); }
    private static function base(): string
    {
        $region = self::cfg('AV_RECALL_REGION', 'us-west-2');
        return 'https://' . $region . '.recall.ai/api/v1';
    }

    /**
     * Send a bot to a meeting. Returns ['ok'=>bool,'bot_id'=>string,'error'=>?].
     * $webhookUrl (optional) is where Recall posts status/transcript events.
     */
    public static function createBot(string $meetingUrl, string $webhookUrl = ''): array
    {
        if (!self::configured()) return ['ok' => false, 'bot_id' => '', 'error' => 'Recall.ai is not configured (set AV_RECALL_API_KEY).'];
        if (trim($meetingUrl) === '') return ['ok' => false, 'bot_id' => '', 'error' => 'No meeting URL.'];

        // Ask Recall to transcribe using the meeting's own captions by default
        // (no extra ASR vendor needed); override wholesale via AV_RECALL_BOT_CONFIG.
        $body = [
            'meeting_url'        => $meetingUrl,
            'bot_name'           => self::botName(),
            'recording_config'   => ['transcript' => ['provider' => ['meeting_captions' => new stdClass()]]],
        ];
        if ($webhookUrl !== '') {
            $body['recording_config']['realtime_endpoints'] = [[
                'type'   => 'webhook',
                'url'    => $webhookUrl,
                'events' => ['transcript.data', 'bot.status_change'],
            ]];
        }
        $extra = self::cfg('AV_RECALL_BOT_CONFIG');
        if ($extra !== '') { $j = json_decode($extra, true); if (is_array($j)) $body = array_replace_recursive($body, $j); }

        $res = self::http('POST', self::base() . '/bot/', $body);
        if (isset($res['__error'])) return ['ok' => false, 'bot_id' => '', 'error' => $res['__error']];
        $id = (string) ($res['id'] ?? '');
        if ($id === '') return ['ok' => false, 'bot_id' => '', 'error' => 'Recall did not return a bot id.'];
        return ['ok' => true, 'bot_id' => $id, 'error' => null];
    }

    /** Fetch and flatten a bot's transcript into "Speaker: text" lines, or ''. */
    public static function fetchTranscript(string $botId): string
    {
        if (!self::configured() || trim($botId) === '') return '';
        // Newer API: GET /bot/{id}/transcript/ → list of segments.
        $res = self::http('GET', self::base() . '/bot/' . rawurlencode($botId) . '/transcript/');
        $segments = null;
        if (!isset($res['__error'])) {
            if (isset($res[0])) $segments = $res;                       // bare array
            elseif (isset($res['results'])) $segments = $res['results']; // paginated
            elseif (isset($res['transcript'])) $segments = $res['transcript'];
        }
        if (!is_array($segments)) {
            // Fallback: the bot object may embed transcript under different keys.
            $bot = self::http('GET', self::base() . '/bot/' . rawurlencode($botId));
            if (!isset($bot['__error'])) {
                $segments = $bot['transcript'] ?? ($bot['recording']['transcript'] ?? null);
            }
        }
        if (!is_array($segments)) return '';

        $lines = [];
        foreach ($segments as $seg) {
            if (!is_array($seg)) continue;
            $who = (string) ($seg['speaker'] ?? ($seg['participant']['name'] ?? ''));
            $txt = '';
            if (isset($seg['words']) && is_array($seg['words'])) {
                foreach ($seg['words'] as $w) { $txt .= (is_array($w) ? (string) ($w['text'] ?? '') : (string) $w) . ' '; }
            } elseif (isset($seg['text'])) {
                $txt = (string) $seg['text'];
            }
            $txt = trim($txt);
            if ($txt !== '') $lines[] = ($who !== '' ? $who . ': ' : '') . $txt;
        }
        return trim(implode("\n", $lines));
    }

    private static function http(string $method, string $url, ?array $body = null): array
    {
        if (!function_exists('curl_init')) return ['__error' => 'curl unavailable'];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Token ' . self::apiKey(), 'content-type: application/json', 'accept: application/json'],
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if (!is_string($resp)) return ['__error' => 'transport: ' . $err];
        $d = json_decode($resp, true);
        if ($code >= 400) {
            error_log('[recall] ' . $code . ': ' . substr($resp, 0, 300));
            return ['__error' => is_array($d) ? (string) ($d['detail'] ?? ('HTTP ' . $code)) : ('HTTP ' . $code)];
        }
        return is_array($d) ? $d : ['__error' => 'bad response (HTTP ' . $code . ')'];
    }
}
