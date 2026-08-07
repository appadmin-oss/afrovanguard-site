<?php
/**
 * lib/GoogleWatch.php — real-time Google push (watch) channels.
 *
 * Registers Calendar/Drive "watch" channels so Google POSTs to our webhook
 * (webhooks/google.php) whenever a member's calendar or Drive changes — no
 * polling. Channels are per-user (using their connected OAuth token) and expire
 * after ~a week, so a cron renews the ones nearing expiry.
 *
 *   php tools/gws-watch.php start <userId>   # start calendar+drive channels
 *   php tools/gws-watch.php renew            # renew channels expiring soon
 *   php tools/gws-watch.php stop <userId>    # stop + forget a user's channels
 *   php tools/gws-watch.php list
 *
 * Going live needs a PUBLIC https webhook (SITE_URL) and, for Drive/Calendar
 * push, a domain verified in Google Search Console and added as an allowed
 * push domain. Until then this is inert — nothing registers, nothing breaks.
 */
declare(strict_types=1);

final class GoogleWatch
{
    const TTL = 604800; // request ~7-day channels (Google caps calendar at 30d, drive ~ a week)
    const RENEW_WITHIN = 86400; // renew channels expiring within 24h

    public static function ensure(): void
    {
        static $done = false; if ($done) return; $done = true;
        $pdo = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS google_channels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind VARCHAR(20) NOT NULL DEFAULT '',
            user_id INTEGER NOT NULL DEFAULT 0,
            channel_id VARCHAR(64) NOT NULL DEFAULT '',
            resource_id VARCHAR(191) NOT NULL DEFAULT '',
            token VARCHAR(64) NOT NULL DEFAULT '',
            expiration INTEGER NOT NULL DEFAULT 0,
            page_token VARCHAR(191) NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT ''
        )";
        $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pdo->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
    }

    /** Public https endpoint Google will POST change notifications to. */
    public static function webhookAddress(): string
    {
        $o = trim((string) getenv('AV_WS_WEBHOOK_URL'));
        return $o !== '' ? $o : rtrim(SITE_URL, '/') . '/webhooks/google';
    }

    /** Look up a live channel by the id we generated (for webhook validation). */
    public static function findByChannelId(string $channelId): ?array
    {
        self::ensure();
        $s = Database::pdo()->prepare('SELECT * FROM google_channels WHERE channel_id = ?');
        $s->execute([$channelId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function forUser(int $uid): array
    {
        self::ensure();
        $s = Database::pdo()->prepare('SELECT * FROM google_channels WHERE user_id = ? ORDER BY id');
        $s->execute([$uid]);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function all(): array
    {
        self::ensure();
        return Database::pdo()->query('SELECT * FROM google_channels ORDER BY expiration')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Start watching a member's primary calendar. Returns the stored row or null. */
    public static function startCalendar(int $uid): ?array
    {
        if (!GoogleWorkspaceUser::connected($uid)) return null;
        $ch = self::newChannel();
        $body = [
            'id'      => $ch['channel_id'],
            'type'    => 'web_hook',
            'address' => self::webhookAddress(),
            'token'   => $ch['token'],
            'params'  => ['ttl' => (string) self::TTL],
        ];
        $r = GoogleWorkspaceUser::apiPost($uid, '/calendar/v3/calendars/primary/events/watch', $body);
        if (!is_array($r) || empty($r['resourceId'])) return null;
        return self::save('calendar', $uid, $ch, (string) $r['resourceId'], self::expFrom($r), '');
    }

    /** Start watching a member's Drive changes. Needs a start page token. */
    public static function startDrive(int $uid): ?array
    {
        if (!GoogleWorkspaceUser::connected($uid)) return null;
        $start = GoogleWorkspaceUser::apiGet($uid, '/drive/v3/changes/startPageToken');
        $pageToken = is_array($start) ? (string) ($start['startPageToken'] ?? '') : '';
        if ($pageToken === '') return null;
        $ch = self::newChannel();
        $body = [
            'id'      => $ch['channel_id'],
            'type'    => 'web_hook',
            'address' => self::webhookAddress(),
            'token'   => $ch['token'],
        ];
        $r = GoogleWorkspaceUser::apiPost($uid, '/drive/v3/changes/watch?pageToken=' . rawurlencode($pageToken), $body);
        if (!is_array($r) || empty($r['resourceId'])) return null;
        return self::save('drive', $uid, $ch, (string) $r['resourceId'], self::expFrom($r), $pageToken);
    }

    /** Start both channels for a user (each best-effort). */
    public static function startAll(int $uid): array
    {
        return ['calendar' => self::startCalendar($uid) !== null, 'drive' => self::startDrive($uid) !== null];
    }

    /** Stop a single channel with Google, then forget it. */
    public static function stopChannel(array $row): void
    {
        $uid = (int) $row['user_id'];
        try {
            GoogleWorkspaceUser::apiPost($uid, '/calendar/v3/channels/stop', [
                'id' => (string) $row['channel_id'], 'resourceId' => (string) $row['resource_id'],
            ]);
        } catch (Throwable $e) { error_log('[gws-watch] stop: ' . $e->getMessage()); }
        Database::pdo()->prepare('DELETE FROM google_channels WHERE id = ?')->execute([(int) $row['id']]);
    }

    /** Stop + forget everything for a user (e.g. on disconnect). */
    public static function stopForUser(int $uid): void
    {
        foreach (self::forUser($uid) as $row) self::stopChannel($row);
    }

    /** Renew channels expiring within RENEW_WITHIN: start fresh, drop the old. */
    public static function renewExpiring(): array
    {
        self::ensure();
        $cut = time() + self::RENEW_WITHIN;
        $rows = Database::pdo()->prepare('SELECT * FROM google_channels WHERE expiration > 0 AND expiration < ?');
        $rows->execute([$cut]);
        $out = ['renewed' => 0, 'failed' => 0];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uid = (int) $row['user_id'];
            $fresh = $row['kind'] === 'calendar' ? self::startCalendar($uid) : self::startDrive($uid);
            if ($fresh) { self::stopChannel($row); $out['renewed']++; }
            else $out['failed']++;
        }
        return $out;
    }

    /* ── helpers ── */
    private static function newChannel(): array
    {
        return ['channel_id' => bin2hex(random_bytes(16)), 'token' => bin2hex(random_bytes(16))];
    }

    private static function expFrom(array $r): int
    {
        // Google returns expiration in MILLISECONDS since epoch.
        $ms = isset($r['expiration']) ? (int) $r['expiration'] : 0;
        return $ms > 0 ? intdiv($ms, 1000) : time() + self::TTL;
    }

    private static function save(string $kind, int $uid, array $ch, string $resourceId, int $exp, string $pageToken): array
    {
        self::ensure();
        $now = gmdate('Y-m-d H:i:s');
        Database::pdo()->prepare('INSERT INTO google_channels (kind, user_id, channel_id, resource_id, token, expiration, page_token, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$kind, $uid, $ch['channel_id'], $resourceId, $ch['token'], $exp, $pageToken, $now]);
        return [
            'kind' => $kind, 'user_id' => $uid, 'channel_id' => $ch['channel_id'],
            'resource_id' => $resourceId, 'expiration' => $exp, 'page_token' => $pageToken,
        ];
    }
}
