<?php
/**
 * lib/AttendeeBot.php — Attendee: the free, self-hostable meeting notetaker.
 *
 * Recall.ai works well and is billed per meeting-hour. For a volunteer
 * organisation running weekly mentorship sessions across a whole movement, that
 * is a bill that grows with exactly the behaviour the system is trying to
 * encourage — which is the wrong shape of cost.
 *
 * Attendee (github.com/attendee-labs/attendee) is the same idea as an open
 * project: a bot that joins Google Meet, Zoom or Teams, records, and transcribes
 * with Whisper bundled in. Self-hosted it is genuinely free — you pay for the
 * container it runs in and nothing per meeting. There is also a hosted service
 * if you would rather not operate it.
 *
 * Config:
 *   AV_ATTENDEE_API_KEY   enables it
 *   AV_ATTENDEE_BASE_URL  your instance, e.g. https://meetbot.example.org
 *                         (defaults to the hosted app.attendee.dev)
 *   AV_ATTENDEE_BOT_NAME  display name in the meeting
 *
 * Shaped deliberately like RecallBot — createBot / botStatus / removeBot /
 * fetchTranscript — so Meetings treats the two interchangeably and switching
 * between them is a dropdown, not a migration.
 *
 * One difference worth knowing: Attendee has no dependable "join later" field
 * across versions, so Meetings does not ask it to schedule ahead. The cron sweep
 * sends the bot when the meeting is actually about to start, which is correct
 * regardless of what the instance supports.
 */
declare(strict_types=1);

final class AttendeeBot
{
    const DEFAULT_BASE = 'https://app.attendee.dev';

    private static function cfg(string $k, string $def = ''): string
    {
        if (class_exists('Config') && Config::has($k)) return Config::str($k, $def);
        return (string) (getenv($k) ?: $def);
    }

    public static function apiKey(): string { return self::cfg('AV_ATTENDEE_API_KEY'); }
    public static function configured(): bool { return self::apiKey() !== ''; }
    public static function botName(): string { return self::cfg('AV_ATTENDEE_BOT_NAME', 'Afrovanguard Notetaker'); }
    public static function base(): string { return rtrim(self::cfg('AV_ATTENDEE_BASE_URL', self::DEFAULT_BASE), '/') . '/api/v1'; }
    /** True when pointed at a self-hosted instance rather than the hosted service. */
    public static function selfHosted(): bool
    {
        $b = self::cfg('AV_ATTENDEE_BASE_URL');
        return $b !== '' && stripos($b, 'attendee.dev') === false;
    }

    /**
     * Send a bot into a meeting. Returns ['ok','bot_id','error'].
     *
     * $joinAtIso is accepted for interface parity with RecallBot and passed
     * through when supplied, but Meetings dispatches Attendee bots at the time
     * the meeting starts rather than relying on it.
     */
    public static function createBot(string $meetingUrl, string $webhookUrl = '', string $joinAtIso = ''): array
    {
        if (!self::configured()) return ['ok' => false, 'bot_id' => '', 'error' => 'Attendee is not configured (set AV_ATTENDEE_API_KEY).'];
        if (trim($meetingUrl) === '') return ['ok' => false, 'bot_id' => '', 'error' => 'No meeting URL.'];

        $body = ['meeting_url' => $meetingUrl, 'bot_name' => self::botName()];
        if (trim($joinAtIso) !== '') $body['join_at'] = trim($joinAtIso);
        // Attendee posts state changes to a webhook when one is configured; the
        // transcript is also pollable, which is the path Meetings actually uses
        // so a site behind shared hosting needs no public callback at all.
        if (trim($webhookUrl) !== '') $body['webhook_url'] = trim($webhookUrl);

        $res = self::http('POST', self::base() . '/bots', $body);
        if (isset($res['__error'])) return ['ok' => false, 'bot_id' => '', 'error' => (string) $res['__error']];
        $id = (string) ($res['id'] ?? '');
        if ($id === '') return ['ok' => false, 'bot_id' => '', 'error' => 'Attendee did not return a bot id.'];
        return ['ok' => true, 'bot_id' => $id, 'error' => null];
    }

    /**
     * Normalised status: scheduled | joining | in_call | done | error | ''.
     * Mapped to the same words RecallBot reports, so callers need no branch.
     */
    public static function botStatus(string $botId): string
    {
        if (!self::configured() || trim($botId) === '') return '';
        $b = self::http('GET', self::base() . '/bots/' . rawurlencode($botId));
        if (isset($b['__error'])) return '';
        $state = strtolower((string) ($b['state'] ?? $b['status'] ?? ''));
        switch ($state) {
            case 'ready': case 'scheduled':                      return 'scheduled';
            case 'joining': case 'joining_call': case 'waiting_room': return 'joining';
            case 'joined_recording': case 'joined_not_recording':
            case 'in_call': case 'in_meeting':                   return 'in_call';
            case 'ended': case 'post_processing': case 'done':   return 'done';
            case 'fatal_error': case 'error':                    return 'error';
            default:                                             return $state !== '' ? 'joining' : '';
        }
    }

    /** True once the transcript is ready to fetch. */
    public static function transcriptReady(string $botId): bool
    {
        if (!self::configured() || trim($botId) === '') return false;
        $b = self::http('GET', self::base() . '/bots/' . rawurlencode($botId));
        if (isset($b['__error'])) return false;
        $t = strtolower((string) ($b['transcription_state'] ?? ''));
        if ($t !== '') return $t === 'complete';
        // Older instances do not report a transcription state; fall back to the
        // bot having finished, and let an empty fetch mean "not yet".
        return in_array(self::botStatus($botId), ['done'], true);
    }

    /** Flatten the transcript into "Speaker: text" lines, or '' when not ready. */
    public static function fetchTranscript(string $botId): string
    {
        if (!self::configured() || trim($botId) === '') return '';
        $res = self::http('GET', self::base() . '/bots/' . rawurlencode($botId) . '/transcript');
        if (isset($res['__error'])) return '';

        $segments = null;
        if (isset($res[0])) $segments = $res;
        elseif (isset($res['results'])) $segments = $res['results'];
        elseif (isset($res['transcript'])) $segments = $res['transcript'];
        if (!is_array($segments)) return '';

        $lines = [];
        foreach ($segments as $seg) {
            if (!is_array($seg)) continue;
            $who = (string) ($seg['speaker_name'] ?? ($seg['speaker'] ?? ($seg['participant']['name'] ?? '')));
            $txt = '';
            if (isset($seg['transcription']['transcript'])) $txt = (string) $seg['transcription']['transcript'];
            elseif (isset($seg['text'])) $txt = (string) $seg['text'];
            elseif (isset($seg['words']) && is_array($seg['words'])) {
                foreach ($seg['words'] as $w) $txt .= (is_array($w) ? (string) ($w['text'] ?? '') : (string) $w) . ' ';
            }
            $txt = trim($txt);
            if ($txt !== '') $lines[] = ($who !== '' ? $who . ': ' : '') . $txt;
        }
        return trim(implode("\n", $lines));
    }

    /**
     * Take the bot out. Asks it to leave, then deletes — either outcome means it
     * is no longer in the room, which is what the caller asked for.
     */
    public static function removeBot(string $botId): array
    {
        if (!self::configured()) return ['ok' => false, 'error' => 'Attendee is not configured.'];
        if (trim($botId) === '') return ['ok' => false, 'error' => 'No bot id.'];

        $leave = self::http('POST', self::base() . '/bots/' . rawurlencode($botId) . '/leave', []);
        if (!isset($leave['__error'])) return ['ok' => true, 'error' => null];

        $del = self::http('DELETE', self::base() . '/bots/' . rawurlencode($botId));
        if (!isset($del['__error'])) return ['ok' => true, 'error' => null];

        return ['ok' => false, 'error' => (string) $del['__error']];
    }

    /**
     * Is the instance reachable and the key accepted?
     *
     * Asks about a bot id that cannot exist. A working instance answers 404 —
     * that is the pass. A rejected key, a wrong URL or a container that is not
     * running all fail, and must be reported as failures: "reachable" when it is
     * not is the false green a setup screen exists to prevent.
     *
     * @return array{ok:bool, error:string}
     */
    public static function ping(): array
    {
        if (!self::configured()) return ['ok' => false, 'error' => 'Attendee is not configured.'];
        $r = self::http('GET', self::base() . '/bots/avsetup-probe-000000000000');
        if (!isset($r['__error'])) return ['ok' => true, 'error' => ''];   // answered, oddly, but answered
        $e = (string) $r['__error'];
        // 404 means the instance answered and simply has no such bot.
        if (stripos($e, '404') !== false || stripos($e, 'not found') !== false) return ['ok' => true, 'error' => ''];
        return ['ok' => false, 'error' => $e];
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
            CURLOPT_HTTPHEADER     => [
                'Authorization: Token ' . self::apiKey(),
                'content-type: application/json',
                'accept: application/json',
            ],
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (!is_string($resp)) return ['__error' => 'transport: ' . $err];
        if ($code >= 400) {
            error_log('[attendee] ' . $code . ': ' . substr($resp, 0, 300));
            $d = json_decode($resp, true);
            return ['__error' => is_array($d) ? (string) ($d['detail'] ?? ($d['error'] ?? ('HTTP ' . $code))) : ('HTTP ' . $code)];
        }
        // A 204 / empty 2xx is success — leave and delete both answer that way.
        if (trim($resp) === '') return [];
        $d = json_decode($resp, true);
        return is_array($d) ? $d : ['__error' => 'bad response (HTTP ' . $code . ')'];
    }
}
