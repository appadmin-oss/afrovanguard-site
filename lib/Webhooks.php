<?php
/**
 * lib/Webhooks.php — outbound webhooks (signed, logged, retried).
 *
 * Endpoints (url + secret + subscribed events) are admin-managed (Studio →
 * Webhooks). When a domain event fires (via Events::emit), a delivery row is
 * enqueued for each matching endpoint and a best-effort send is attempted AFTER
 * the response is flushed to the user (fastcgi_finish_request when available),
 * so it never adds latency to the triggering action. Failed deliveries are
 * retried with exponential backoff by db/webhooks_run.php (cron).
 *
 * Signature (verify on your receiver):
 *   headers: X-AV-Event, X-AV-Delivery, X-AV-Timestamp, X-AV-Signature
 *   X-AV-Signature: sha256=hex( HMAC_SHA256( "{X-AV-Timestamp}.{raw body}", secret ) )
 */
declare(strict_types=1);

final class Webhooks
{
    const MAX_ATTEMPTS = 6;
    /** Backoff per attempt number (seconds): ~1m, 5m, 30m, 2h, 6h, 24h. */
    const BACKOFF = [60, 300, 1800, 7200, 21600, 86400];

    public static function available(): bool
    {
        return class_exists('Database');
    }

    /* ── schema (driver-aware, created on first use; SQLite byte-identical) ── */
    public static function ensure(?PDO $pdo = null): void
    {
        static $done = false;
        $db = $pdo ?: Database::pdo();
        if ($done && $pdo === null) return;
        $ddl = "CREATE TABLE IF NOT EXISTS webhook_endpoints (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            url VARCHAR(500) NOT NULL DEFAULT '',
            secret VARCHAR(120) NOT NULL DEFAULT '',
            events VARCHAR(500) NOT NULL DEFAULT '*',
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS webhook_deliveries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            endpoint_id INTEGER NOT NULL,
            event VARCHAR(80) NOT NULL DEFAULT '',
            payload TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            last_code INTEGER NOT NULL DEFAULT 0,
            last_error VARCHAR(300) NOT NULL DEFAULT '',
            next_attempt_at VARCHAR(32) NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        );";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        Database::execSchema($db, $ddl);
        // Index created separately + idempotently (MySQL lacks CREATE INDEX IF NOT EXISTS).
        Database::ensureIndex($db, 'idx_wh_deliv_status', 'webhook_deliveries', 'status, next_attempt_at');
        if ($pdo === null) $done = true;
    }

    /* ── dispatch: enqueue + flush-after-response ── */
    public static function dispatch(string $event, array $payload): void
    {
        if (!self::available()) return;
        $db = Database::pdo();
        self::ensure();
        $endpoints = self::endpointsForEvent($db, $event);
        if (!$endpoints) return;
        $body = json_encode([
            'event' => $event,
            'data'  => $payload,
            'site'  => defined('SITE_URL') ? SITE_URL : '',
            'at'    => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $now = gmdate('Y-m-d H:i:s');
        $ins = $db->prepare('INSERT INTO webhook_deliveries (endpoint_id, event, payload, status, next_attempt_at) VALUES (?,?,?,?,?)');
        $ids = [];
        foreach ($endpoints as $ep) { $ins->execute([(int) $ep['id'], $event, $body, 'pending', $now]); $ids[] = (int) $db->lastInsertId(); }

        // Try to deliver after the response is sent so the user never waits.
        register_shutdown_function(static function () use ($ids): void {
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            foreach ($ids as $id) { try { self::deliver($id); } catch (Throwable $e) { /* cron will retry */ } }
        });
    }

    /**
     * Send a one-off signed test event to ONE endpoint, synchronously, and
     * record the result. Works even if the endpoint is currently disabled (so
     * you can verify it before turning it on). Returns the delivery outcome.
     */
    public static function sendTest(int $endpointId): array
    {
        if (!self::available()) return ['ok' => false, 'error' => 'Webhooks unavailable.'];
        $db = Database::pdo(); self::ensure();
        $e = $db->prepare('SELECT * FROM webhook_endpoints WHERE id = ?'); $e->execute([$endpointId]);
        $ep = $e->fetch(PDO::FETCH_ASSOC);
        if (!$ep) return ['ok' => false, 'error' => 'Endpoint not found.'];
        $body = json_encode([
            'event' => 'webhook.test',
            'data'  => ['message' => 'Test delivery from the Afrovanguard Studio.', 'endpoint_id' => $endpointId],
            'site'  => defined('SITE_URL') ? SITE_URL : '',
            'at'    => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $db->prepare('INSERT INTO webhook_deliveries (endpoint_id, event, payload, status, next_attempt_at) VALUES (?,?,?,?,?)')
           ->execute([$endpointId, 'webhook.test', $body, 'pending', gmdate('Y-m-d H:i:s')]);
        $id = (int) $db->lastInsertId();
        [$code, $err] = self::send((string) $ep['url'], (string) $ep['secret'], 'webhook.test', $id, $body);
        $okStatus = $code >= 200 && $code < 300;
        self::finish($db, $id, $okStatus ? 'success' : 'failed', $code, $err, 1, $okStatus ? '' : gmdate('Y-m-d H:i:s', time() + 3600));
        return ['ok' => $okStatus, 'code' => $code, 'error' => $err, 'delivery_id' => $id];
    }

    /** Enabled endpoints subscribed to $event (or to '*'). */
    private static function endpointsForEvent(PDO $db, string $event): array
    {
        $out = [];
        foreach ($db->query('SELECT * FROM webhook_endpoints WHERE enabled = 1')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ep) {
            $evs = array_filter(array_map('trim', explode(',', (string) $ep['events'])));
            if (in_array('*', $evs, true) || in_array($event, $evs, true) || $evs === []) $out[] = $ep;
        }
        return $out;
    }

    /* ── deliver a single queued row (used by shutdown flush + cron) ── */
    public static function deliver(int $deliveryId): bool
    {
        $db = Database::pdo();
        $d = $db->prepare('SELECT * FROM webhook_deliveries WHERE id = ?'); $d->execute([$deliveryId]);
        $row = $d->fetch(PDO::FETCH_ASSOC);
        if (!$row || in_array($row['status'], ['success', 'dead'], true)) return false;
        $e = $db->prepare('SELECT * FROM webhook_endpoints WHERE id = ?'); $e->execute([(int) $row['endpoint_id']]);
        $ep = $e->fetch(PDO::FETCH_ASSOC);
        if (!$ep || (int) $ep['enabled'] !== 1) { self::finish($db, $deliveryId, 'dead', 0, 'endpoint missing or disabled', (int) $row['attempts']); return false; }

        [$code, $err] = self::send((string) $ep['url'], (string) $ep['secret'], (string) $row['event'], (int) $row['id'], (string) $row['payload']);
        $attempts = (int) $row['attempts'] + 1;
        if ($code >= 200 && $code < 300) { self::finish($db, $deliveryId, 'success', $code, '', $attempts); return true; }
        if ($attempts >= self::MAX_ATTEMPTS) { self::finish($db, $deliveryId, 'dead', $code, $err, $attempts); return false; }
        $delay = self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1];
        self::finish($db, $deliveryId, 'failed', $code, $err, $attempts, gmdate('Y-m-d H:i:s', time() + $delay));
        return false;
    }

    private static function finish(PDO $db, int $id, string $status, int $code, string $err, int $attempts, ?string $next = null): void
    {
        $db->prepare('UPDATE webhook_deliveries SET status=?, last_code=?, last_error=?, attempts=?, next_attempt_at=?, updated_at=? WHERE id=?')
           ->execute([$status, $code, mb_substr($err, 0, 280), $attempts, $next ?? '', gmdate('Y-m-d H:i:s'), $id]);
    }

    /** POST the signed payload. Returns [httpCode, errorString]. Never throws. */
    private static function send(string $url, string $secret, string $event, int $deliveryId, string $body): array
    {
        if (!preg_match('#^https?://#i', $url) || !function_exists('curl_init')) return [0, 'invalid url or curl missing'];
        $ts  = (string) time();
        $sig = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'User-Agent: Afrovanguard-Webhooks/1.0',
                'X-AV-Event: ' . $event,
                'X-AV-Delivery: ' . $deliveryId,
                'X-AV-Timestamp: ' . $ts,
                'X-AV-Signature: ' . $sig,
            ],
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $ok === false ? (string) curl_error($ch) : '';
        curl_close($ch);
        return [$code, $err];
    }

    /** Process due deliveries (cron). Returns counts. */
    public static function runQueue(int $max = 50): array
    {
        if (!self::available()) return ['processed' => 0, 'ok' => 0];
        $db = Database::pdo(); self::ensure();
        $now = gmdate('Y-m-d H:i:s');
        $sel = $db->prepare("SELECT id FROM webhook_deliveries WHERE status IN ('pending','failed') AND next_attempt_at <= ? ORDER BY id ASC LIMIT " . max(1, (int) $max));
        $sel->execute([$now]);
        $ids = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));
        $ok = 0;
        foreach ($ids as $id) { if (self::deliver($id)) $ok++; }
        return ['processed' => count($ids), 'ok' => $ok];
    }

    /* ── admin CRUD ── */
    public static function endpointsAll(): array
    {
        $db = Database::pdo(); self::ensure();
        return $db->query('SELECT * FROM webhook_endpoints ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    public static function endpointSave(array $in): int
    {
        $db = Database::pdo(); self::ensure();
        $events = trim((string) ($in['events'] ?? '*'));
        $f = [
            'url'     => trim((string) ($in['url'] ?? '')),
            'secret'  => trim((string) ($in['secret'] ?? '')) ?: bin2hex(random_bytes(24)),
            'events'  => $events !== '' ? $events : '*',
            'enabled' => isset($in['enabled']) ? (!empty($in['enabled']) ? 1 : 0) : 1,
        ];
        $id = (int) ($in['id'] ?? 0);
        if ($id > 0) {
            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($f)));
            $db->prepare("UPDATE webhook_endpoints SET $set WHERE id = :id")->execute($f + ['id' => $id]);
            return $id;
        }
        $cols = implode(', ', array_keys($f));
        $ph   = implode(', ', array_map(fn($k) => ":$k", array_keys($f)));
        $db->prepare("INSERT INTO webhook_endpoints ($cols) VALUES ($ph)")->execute($f);
        return (int) $db->lastInsertId();
    }
    public static function endpointDelete(int $id): void
    {
        $db = Database::pdo(); self::ensure();
        $db->prepare('DELETE FROM webhook_endpoints WHERE id = ?')->execute([$id]);
        $db->prepare('DELETE FROM webhook_deliveries WHERE endpoint_id = ?')->execute([$id]);
    }
    public static function recentDeliveries(int $limit = 25): array
    {
        $db = Database::pdo(); self::ensure();
        $s = $db->prepare('SELECT id, endpoint_id, event, status, attempts, last_code, last_error, updated_at FROM webhook_deliveries ORDER BY id DESC LIMIT ' . max(1, $limit));
        $s->execute();
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
