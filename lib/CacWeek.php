<?php
/**
 * lib/CacWeek.php — this week at CACENTRE, in the portal; portal tasks that
 * count toward it.
 *
 * CACENTRE's "task and goal for the week" is a COMMITMENT (a measurable
 * promise for the week — "Run 3 volunteer sessions") on one of the member's
 * postings, feeding a GOAL. This reads the member's commitments for the week
 * from CACENTRE (api/afrovanguard-week.php), with each goal and its progress,
 * so the portal's Tasks screen can show them; and it lets a portal task COUNT
 * toward one: attached there, ticking it here ticks it there, and a
 * commitment counted in tasks shows the tick in its delivered value.
 *
 * The same signed door as lib/CacTasks.php (CacSso's token, the shared
 * secret, checked without being spent), and the same rule: CACENTRE holds
 * the commitment; this site holds the task and remembers only which
 * commitment it counts toward (collab_tasks.cac_commitment_id) and for whom.
 *
 * Fails soft everywhere: the console being down never stops a task being
 * ticked here. A tick that could not be sent is retried from the cron
 * (cac_sync_pending), so the two do not drift.
 */
declare(strict_types=1);

final class CacWeek
{
    public const PATH = '/api/afrovanguard-week.php';
    private const TIMEOUT = 3;

    private static array $memo = [];
    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        $pdo = Database::pdo();
        Collab::myTasks(0, 1);   /* collab_tasks exists */
        foreach (['cac_commitment_id' => 'INTEGER NOT NULL DEFAULT 0', 'cac_by' => 'INTEGER NOT NULL DEFAULT 0', 'cac_sync_pending' => 'INTEGER NOT NULL DEFAULT 0'] as $c => $def) {
            try { if (!Database::columnExists('collab_tasks', $c)) $pdo->exec("ALTER TABLE collab_tasks ADD COLUMN $c $def"); } catch (Throwable $e) {}
        }
    }

    public static function ready(): bool { return class_exists('CacSso') && CacSso::ready(); }

    /** Where the member manages their week on CACENTRE. */
    public static function consoleUrl(): string { return CacSso::base() . '/staff/dna.php'; }

    private static function token(array $u): string
    {
        return CacSso::mint(['id' => (int) $u['id'], 'email' => (string) ($u['email'] ?? ''), 'name' => (string) ($u['name'] ?? '')], 'week');
    }

    /**
     * The member's week: ['ok', 'linked', 'week' => {start,end},
     * 'commitments' => [...], 'error', 'url'].
     */
    public static function forMember(array $u, ?string $on = null): array
    {
        $id = (int) ($u['id'] ?? 0);
        $base = ['ok' => false, 'linked' => false, 'week' => null, 'commitments' => [], 'error' => '', 'url' => self::ready() ? self::consoleUrl() : ''];
        if ($id <= 0) return $base;
        $key = $id . '|' . ($on ?? '');
        if (isset(self::$memo[$key])) return self::$memo[$key];
        if (!self::ready()) return ['error' => 'not-configured'] + $base;   /* not cached: setting it up must show at once */
        $q = '?t=' . rawurlencode(self::token($u)) . ($on !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) ? '&on=' . $on : '');
        $raw = self::http(CacSso::base() . self::PATH . $q, null);
        return self::$memo[$key] = self::read($raw, $base);
    }

    private static function read(?string $raw, array $base): array
    {
        if ($raw === null) return ['error' => 'unreachable'] + $base;
        $d = json_decode($raw, true);
        if (!is_array($d) || empty($d['ok'])) return ['error' => is_array($d) ? (string) ($d['error'] ?? 'refused') : 'unreadable'] + $base;
        $week = is_array($d['week'] ?? null) ? ['start' => (string) ($d['week']['start'] ?? ''), 'end' => (string) ($d['week']['end'] ?? '')] : null;
        return ['ok' => true, 'linked' => !empty($d['linked']), 'week' => $week, 'commitments' => self::clean($d['commitments'] ?? []), 'error' => ''] + $base;
    }

    /** Only what the portal draws, typed. */
    private static function clean($rows): array
    {
        if (!is_array($rows)) return [];
        $out = [];
        foreach (array_slice($rows, 0, 40) as $c) {
            if (!is_array($c) || (int) ($c['id'] ?? 0) <= 0) continue;
            $g = is_array($c['goal'] ?? null) ? $c['goal'] : null;
            $out[] = [
                'id' => (int) $c['id'], 'statement' => mb_substr((string) ($c['statement'] ?? ''), 0, 300),
                'period' => (string) ($c['period_kind'] ?? 'weekly'), 'end' => (string) ($c['period_end'] ?? ''),
                'target' => (float) ($c['target'] ?? 0), 'unit' => mb_substr((string) ($c['unit'] ?? ''), 0, 20),
                'delivered' => (float) ($c['delivered'] ?? 0), 'status' => mb_substr((string) ($c['status'] ?? 'open'), 0, 12),
                'posting' => mb_substr((string) ($c['posting'] ?? ''), 0, 120),
                'av_tasks' => array_values(array_map('intval', (array) ($c['av_tasks'] ?? []))),
                'goal' => $g ? ['code' => mb_substr((string) ($g['code'] ?? ''), 0, 40), 'title' => mb_substr((string) ($g['title'] ?? ''), 0, 200),
                    'progress' => (float) ($g['progress'] ?? 0), 'target' => isset($g['target']) ? (float) $g['target'] : null,
                    'unit' => mb_substr((string) ($g['unit'] ?? ''), 0, 20), 'due' => (string) ($g['due_on'] ?? ''), 'status' => (string) ($g['status'] ?? '')] : null,
            ];
        }
        return $out;
    }

    /* ══ Counting a portal task toward a commitment ═════════════════════════ */

    /**
     * Count one of the member's tasks toward a commitment of theirs ($cid),
     * or stop counting it ($cid = 0). Returns ['ok', 'error', 'week'].
     */
    public static function attach(array $u, int $taskId, int $cid): array
    {
        self::ensure();
        $uid = (int) $u['id'];
        $t = self::task($uid, $taskId);
        if (!$t) return ['ok' => false, 'error' => 'That task is not yours.'];
        if (!self::ready()) return ['ok' => false, 'error' => 'The link to CACENTRE is not set up here.'];
        $was = (int) $t['cac_commitment_id'];
        if ($was > 0 && $was !== $cid) {
            $r = self::post($u, ['action' => 'unlink', 'commitment_id' => $was, 'task_id' => $taskId]);
            if (!$r['ok'] && $r['error'] !== 'not-yours') return $r;
        }
        if ($cid > 0) {
            $r = self::post($u, ['action' => 'link', 'commitment_id' => $cid, 'task' => ['id' => $taskId, 'title' => (string) $t['title'], 'done' => (bool) $t['done']]]);
            if (!$r['ok']) return $r;
        } else {
            $r = ['ok' => true, 'error' => '', 'week' => null];
        }
        Database::pdo()->prepare('UPDATE collab_tasks SET cac_commitment_id = ?, cac_by = ?, cac_sync_pending = 0 WHERE id = ?')->execute([$cid, $cid > 0 ? $uid : 0, $taskId]);
        return $r;
    }

    /** A task counted toward a commitment was ticked, reopened or deleted here: tell CACENTRE. */
    public static function changed(int $taskId, bool $deleted = false): void
    {
        try {
            self::ensure();
            $st = Database::pdo()->prepare('SELECT * FROM collab_tasks WHERE id = ?');
            $st->execute([$taskId]);
            $t = $st->fetch(PDO::FETCH_ASSOC);
            if (!$t || (int) $t['cac_commitment_id'] <= 0 || !self::ready()) return;
            $u = self::user((int) $t['cac_by']);
            if (!$u) return;
            $r = $deleted
                ? self::post($u, ['action' => 'unlink', 'commitment_id' => (int) $t['cac_commitment_id'], 'task_id' => $taskId])
                : self::post($u, ['action' => 'state', 'task' => ['id' => $taskId, 'title' => (string) $t['title'], 'done' => (bool) $t['done']]]);
            if (!$deleted) Database::pdo()->prepare('UPDATE collab_tasks SET cac_sync_pending = ? WHERE id = ?')->execute([$r['ok'] ? 0 : 1, $taskId]);
        } catch (Throwable $e) { error_log('[cac-week] ' . $e->getMessage()); }
    }

    /** From the cron: send the ticks that could not be sent at the time. */
    public static function retryPending(int $max = 20): int
    {
        if (!self::ready()) return 0;
        self::ensure();
        $n = 0;
        foreach (Database::pdo()->query('SELECT id FROM collab_tasks WHERE cac_sync_pending = 1 AND cac_commitment_id > 0 LIMIT ' . max(1, $max))->fetchAll(PDO::FETCH_COLUMN) as $id) {
            self::changed((int) $id);
            $n++;
        }
        return $n;
    }

    /** taskId => commitmentId, for the member's tasks that count toward one. */
    public static function attachedFor(int $uid): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT id, cac_commitment_id FROM collab_tasks WHERE cac_commitment_id > 0 AND (assignee_id = ? OR creator_id = ?)');
        $st->execute([$uid, $uid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$id, $c]) $out[(int) $id] = (int) $c;
        return $out;
    }

    private static function task(int $uid, int $taskId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM collab_tasks WHERE id = ? AND (assignee_id = ? OR creator_id = ?)');
        $st->execute([$taskId, $uid, $uid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function user(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT id, name, email FROM lms_users WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function post(array $u, array $body): array
    {
        $raw = self::http(CacSso::base() . self::PATH . '?t=' . rawurlencode(self::token($u)), $body);
        unset(self::$memo[(int) $u['id'] . '|']);
        $r = self::read($raw, ['ok' => false, 'linked' => false, 'week' => null, 'commitments' => [], 'error' => '', 'url' => '']);
        return ['ok' => $r['ok'], 'error' => $r['error'], 'week' => $r['ok'] ? $r : null];
    }

    /** GET when $body is null, else POST JSON. The body comes back on a refusal too. */
    private static function http(string $url, ?array $body): ?string
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                 CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Afrovanguard/1.0 (+week)'];
        if (str_starts_with($url, 'https://')) { $opts[CURLOPT_SSL_VERIFYPEER] = true; $opts[CURLOPT_SSL_VERIFYHOST] = 2; }
        if ($body !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        if (class_exists('CacBridge')) CacBridge::see($ch, $out, 'week');
        curl_close($ch);
        return $out === false || $out === '' ? null : (string) $out;
    }
}
