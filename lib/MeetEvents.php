<?php
/**
 * lib/MeetEvents.php — Google Meet's own events, as they happen.
 *
 * The cron already asks Google about a meeting while it is on and after it
 * ends (MeetingLive::pollGoogle / finish). This makes it immediate: the
 * Workspace Events API sends every Meet event in the organiser's spaces —
 * a conference starting or ending, someone joining or leaving, a transcript,
 * recording or Gemini notes being ready — to a Cloud Pub/Sub topic.
 *
 *   PULL (default, shared hosting): the cron drains a pull subscription
 *        every tick (drain()). No public URL is needed.
 *   PUSH (optional): Pub/Sub posts to /webhooks/google-meet.php, which checks
 *        Google's OIDC token (firebase/php-jwt, Google's published keys) and
 *        hands the message to handle().
 *
 * Either way an event is only a nudge: it names a conference, and the
 * meeting it belongs to is read again from Google (so a forged or replayed
 * message can at most make the site look sooner). The subscription itself
 * lives at most seven days; renewIfDue() keeps it alive from the cron.
 *
 * Settings (Studio → Setup → Google Meet): AV_MEET_PUBSUB_TOPIC,
 * AV_MEET_PUBSUB_SUB, AV_MEET_PUSH_AUDIENCE. Nothing here runs without them.
 */
declare(strict_types=1);

final class MeetEvents
{
    /** Renew the subscription when it is this old (Google's limit is 7 days). */
    private const RENEW_AFTER = 4 * 86400;

    public static function topic(): string { return trim((string) Config::get('AV_MEET_PUBSUB_TOPIC', '')); }
    public static function pullSubscription(): string { return trim((string) Config::get('AV_MEET_PUBSUB_SUB', '')); }
    public static function pushAudience(): string { return trim((string) Config::get('AV_MEET_PUSH_AUDIENCE', '')); }

    public static function configured(): bool
    {
        return class_exists('GoogleWorkspace') && GoogleWorkspace::meetEnabled() && self::topic() !== '' && GoogleWorkspace::organiser() !== '';
    }

    /** The subscription this site holds: ['name' => 'subscriptions/…', 'at' => unix] or null. */
    public static function state(): ?array
    {
        $j = json_decode((string) (Database::metaGet('meet_events_sub') ?? ''), true);
        return is_array($j) && !empty($j['name']) ? $j : null;
    }

    /** Subscribe if there is none; renew one that is getting old. Run by the cron. */
    public static function renewIfDue(?int $now = null): ?array
    {
        if (!self::configured()) return null;
        $now = $now ?? time();
        $s = self::state();
        if ($s && $now - (int) ($s['at'] ?? 0) < self::RENEW_AFTER) return null;
        if ($s && GoogleWorkspace::eventsRenew((string) $s['name'])) {
            Database::metaSet('meet_events_sub', (string) json_encode(['name' => $s['name'], 'at' => $now]));
            return ['renewed' => $s['name']];
        }
        $name = GoogleWorkspace::eventsSubscribe(self::topic());
        if ($name === '') return ['error' => GoogleWorkspace::lastError() ?: 'Google refused the subscription.'];
        Database::metaSet('meet_events_sub', (string) json_encode(['name' => $name, 'at' => $now]));
        return ['subscribed' => $name];
    }

    /** Empty the pull subscription: handle each event, then acknowledge it. Run by the cron. */
    public static function drain(int $rounds = 3): array
    {
        $sub = self::pullSubscription();
        $out = ['received' => 0, 'handled' => 0];
        if (!self::configured() || $sub === '') return $out;
        for ($i = 0; $i < $rounds; $i++) {
            $msgs = GoogleWorkspace::pubsubPull($sub, 100);
            if (!$msgs) break;
            $ack = [];
            foreach ($msgs as $rm) {
                $out['received']++;
                try { if (self::handle((array) ($rm['message'] ?? []))) $out['handled']++; }
                catch (Throwable $e) { error_log('[meet-events] ' . $e->getMessage()); }
                if (!empty($rm['ackId'])) $ack[] = (string) $rm['ackId'];
            }
            GoogleWorkspace::pubsubAck($sub, $ack);
            if (count($msgs) < 100) break;
        }
        return $out;
    }

    /**
     * One Pub/Sub message carrying a Workspace event (CloudEvents: the type in
     * the "ce-type" attribute, the resource names in the base64 data).
     * Returns true when it was about one of this site's meetings.
     */
    public static function handle(array $message): bool
    {
        $type = (string) ($message['attributes']['ce-type'] ?? '');
        if (!str_starts_with($type, 'google.workspace.meet.')) return false;
        $data = json_decode((string) base64_decode((string) ($message['data'] ?? ''), true), true);
        $conf = self::conferenceIn(is_array($data) ? $data : []) ?: self::conferenceIn(['s' => (string) ($message['attributes']['ce-subject'] ?? '')]);
        if ($conf === '') return false;
        $hit = self::meetingFor($conf);
        if (!$hit) return false;
        [$m, $occ] = $hit;
        if (str_contains($type, '.participant.') || str_contains($type, '.conference.v2.started')) {
            MeetingLive::pollGoogle($m, $occ);
            return true;
        }
        /* Ended, or a transcript / notes / recording is ready: finish now. */
        if (!MeetingMinutes::nudged((int) $m['id'], $occ, 'finished')) {
            $r = MeetingLive::finish($m, $occ);
            if ($r !== MeetingLive::WAITING) MeetingMinutes::nudgeOnce((int) $m['id'], $occ, 'finished');
        }
        return true;
    }

    /** The first "conferenceRecords/<id>" named anywhere in an event's data. */
    private static function conferenceIn(array $data): string
    {
        $found = '';
        array_walk_recursive($data, static function ($v) use (&$found) {
            if ($found === '' && is_string($v) && preg_match('#(conferenceRecords/[\w-]+)#', $v, $mm)) $found = $mm[1];
        });
        return $found;
    }

    /** The meeting (and which occurrence) a conference belongs to, by the Meet space it was held in. */
    private static function meetingFor(string $conference): ?array
    {
        $c = GoogleWorkspace::conferenceRecord($conference);
        if (!$c || $c['space'] === '') return null;
        Meetings::ensure();
        $st = Database::pdo()->prepare("SELECT * FROM meetings WHERE meet_space = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
        $st->execute([$c['space']]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) return null;
        /* The occurrence nearest the conference's start. */
        $best = null;
        foreach (MeetingMinutes::occurrences($m, $c['start'] - 12 * 3600, $c['start'] + 12 * 3600) as $ts) {
            if ($best === null || abs($ts - $c['start']) < abs($best - $c['start'])) $best = $ts;
        }
        return $best === null ? null : [$m, MeetingMinutes::occurrenceKey($best)];
    }

    /* ══ Push (optional) ═══════════════════════════════════════════════════ */

    /**
     * Check a Pub/Sub push's OIDC token: signed by Google (its published
     * keys, cached), for our audience, from an accounts.google.com issuer,
     * with a verified email. Returns the claims, or null.
     */
    public static function verifyPush(string $authorization): ?array
    {
        $aud = self::pushAudience();
        if ($aud === '' || !preg_match('/^Bearer\s+(\S+)$/', trim($authorization), $m)) return null;
        if (!class_exists('Firebase\JWT\JWT')) return null;
        $keys = self::googleKeys();
        if (!$keys) return null;
        try {
            \Firebase\JWT\JWT::$leeway = 60;
            $claims = (array) \Firebase\JWT\JWT::decode($m[1], \Firebase\JWT\JWK::parseKeySet($keys, 'RS256'));
        } catch (Throwable $e) { return null; }
        if (!in_array((string) ($claims['iss'] ?? ''), ['https://accounts.google.com', 'accounts.google.com'], true)) return null;
        if ((string) ($claims['aud'] ?? '') !== $aud || empty($claims['email_verified'])) return null;
        return $claims;
    }

    /** Google's OIDC signing keys (JWKS), cached for six hours. */
    private static function googleKeys(): ?array
    {
        $c = json_decode((string) (Database::metaGet('google_oidc_jwks') ?? ''), true);
        if (is_array($c) && ($c['at'] ?? 0) > time() - 6 * 3600 && !empty($c['keys']['keys'])) return $c['keys'];
        $url = (string) (Config::get('AV_GOOGLE_CERTS_URL', '') ?: 'https://www.googleapis.com/oauth2/v3/certs');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        $body = curl_exec($ch);
        curl_close($ch);
        $keys = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($keys) || empty($keys['keys'])) return is_array($c) && !empty($c['keys']['keys']) ? $c['keys'] : null;
        Database::metaSet('google_oidc_jwks', (string) json_encode(['at' => time(), 'keys' => $keys]));
        return $keys;
    }

    /** For the Studio: what is set up, and the subscription's age. */
    public static function status(): array
    {
        $s = self::state();
        return ['configured' => self::configured(), 'topic' => self::topic(), 'pull' => self::pullSubscription(), 'push' => self::pushAudience() !== '',
                'subscription' => $s['name'] ?? '', 'renewed_at' => isset($s['at']) ? gmdate('Y-m-d H:i', (int) $s['at']) . ' UTC' : ''];
    }
}
