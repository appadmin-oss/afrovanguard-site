<?php
/**
 * lib/CrmAudiencePush.php — Afrovanguard's people, sent to the CACENTRE CRM.
 *
 * CACENTRE's CRM keeps one list of everybody every site knows (its Audience
 * screen). This is Afrovanguard's half: the Diary's subscribers, every gift,
 * every monthly giver and every contact-form enquiry, pushed to
 * /api/crm-intake.php on CACENTRE in the v1 envelope and signed with this
 * site's own secret.
 *
 * ── PUSHED, NEVER PULLED ────────────────────────────────────────────────────
 * Nothing on CACENTRE can read this database. The CRM learns what this site
 * chooses to send, signed, and a stolen CRM secret for another site cannot
 * forge a push from here.
 *
 * ── CURSORS MOVE ONLY ON A 200 ──────────────────────────────────────────────
 * Each source keeps how far it has been sent (Database::metaSet). A cursor is
 * written only after CACENTRE answered 200 — so a push that timed out is sent
 * again next tick, and the CRM's own key (site, kind, ref) makes the repeat an
 * update rather than a second person.
 *
 * ── AN EVENT THAT LEAVES NO ROW GOES THROUGH THE OUTBOX ─────────────────────
 * Unsubscribing from the Diary DELETES the subscriber row, so a cursor over
 * the table cannot see it. It is queued at the moment it happens (queue())
 * and drained here first. An unsubscribe that never reached the CRM is the
 * failure that matters most: it is how somebody who said stop goes on being
 * written to from another site.
 *
 * Gifts, enquiries and the footer newsletter live in private JSON files
 * (donations.json, contacts.json), read here with a cursor on their own
 * timestamps.
 *
 * ── NO CHILDREN ─────────────────────────────────────────────────────────────
 * Nothing here reads a learner, a member roster or an NGV participant. Every
 * source is somebody who gave us their own address on purpose.
 */
declare(strict_types=1);

final class CrmAudiencePush
{
    public const APP = 'avg';
    public const PATH = '/api/crm-intake.php';
    /** The CRM's own cap per push. */
    public const BATCH = 500;
    /** How many pushes one tick may send before leaving the rest for the next. */
    private const MAX_PUSHES = 6;

    /** For tests: fn(string $url, string $body): array{code:int, body:string}. */
    public static $transport = null;

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        Database::execSchema(Database::pdo(), "
        CREATE TABLE IF NOT EXISTS av_crm_outbox (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            row_json TEXT NOT NULL,
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
    }

    /** The secret CACENTRE generated for this site — Studio first, then the environment. */
    public static function secret(): string
    {
        $v = class_exists('Config') ? Config::str('AV_CRM_INTAKE_SECRET') : '';
        if ($v === '') $v = (string) (getenv('AV_CRM_INTAKE_SECRET') ?: '');
        return trim($v);
    }

    public static function ready(): bool { return self::secret() !== ''; }

    public static function url(): string { return CacSso::base() . self::PATH; }

    /**
     * Remember something that leaves no row behind. Never throws: an
     * unsubscribe must not fail because the CRM queue did.
     */
    public static function queue(array $row): void
    {
        try {
            self::ensure();
            Database::pdo()->prepare('INSERT INTO av_crm_outbox (row_json, created_at) VALUES (?, ?)')
                ->execute([json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), gmdate('c')]);
        } catch (Throwable $e) { error_log('[crm-push] queue: ' . $e->getMessage()); }
    }

    /**
     * The subscriber row's ref — derived from the address, so an unsubscribe after the row
     * is gone names the same source. Hashed, because CACENTRE refuses a ref over 64
     * characters and an address may be 190.
     */
    public static function subscriberRef(string $email): string { return 'sub:' . sha1(strtolower(trim($email))); }

    /** A ref that always fits CACENTRE's 64 characters, and is the same every time for the same key. */
    public static function ref(string $prefix, string $key): string
    {
        return strlen($prefix . $key) <= 64 ? $prefix . $key : $prefix . sha1($key);
    }

    /** A push, signed exactly as CACENTRE's CrmAudience::verifyPush() reads it. */
    public static function sign(array $rows, string $secret, ?int $now = null): string
    {
        $now ??= time();
        $payload = self::b64((string) json_encode(
            ['app' => self::APP, 'iat' => $now, 'exp' => $now + 300, 'nonce' => bin2hex(random_bytes(12)), 'rows' => $rows],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return 'v1.' . $payload . '.' . self::b64(hash_hmac('sha256', $payload, $secret, true));
    }

    /* ══ What is sent ════════════════════════════════════════════════════ */

    /**
     * Up to $limit rows from every source past its cursor, and the cursors
     * they would move to. Nothing is written here.
     *
     * @return array{rows:list<array>, cursors:array<string,string>, outbox:list<int>}
     */
    public static function collect(int $limit = self::BATCH): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $rows = []; $cursors = []; $outbox = [];

        /* The outbox first: an unsubscribe must never wait behind a backlog. */
        $st = $pdo->prepare('SELECT id, row_json FROM av_crm_outbox ORDER BY id LIMIT ' . (int) $limit);
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = json_decode((string) $r['row_json'], true);
            $outbox[] = (int) $r['id'];
            if (is_array($row)) $rows[] = $row;
        }

        /* Diary subscribers, by id. */
        if (count($rows) < $limit && Database::tableExists('subscribers')) {
            $from = (int) (Database::metaGet('crm_push.subscribers') ?? 0);
            $st = $pdo->prepare('SELECT id, email, source, created_at FROM subscribers WHERE id > ? ORDER BY id LIMIT ' . ($limit - count($rows)));
            $st->execute([$from]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = ['kind' => 'subscriber', 'ref' => self::subscriberRef((string) $r['email']), 'email' => (string) $r['email'],
                           'list' => (string) $r['source'] === 'diary' ? 'The Vanguard Diary' : 'Afrovanguard · ' . (string) $r['source'],
                           'status' => 'subscribed', 'at' => self::at((string) $r['created_at'])];
                $cursors['crm_push.subscribers'] = (string) $r['id'];
            }
        }

        /* Monthly givers, by id — and again when one ends, by when it ended. */
        if (count($rows) < $limit && Database::tableExists('av_appeal_subs')) {
            $from  = (int) (Database::metaGet('crm_push.appeal_subs') ?? 0);
            $ended = (string) (Database::metaGet('crm_push.appeal_subs_ended') ?? '');
            $st = $pdo->prepare("SELECT * FROM av_appeal_subs WHERE email <> '' AND (id > ? OR (ended_at <> '' AND ended_at > ?))
                                 ORDER BY id LIMIT " . ($limit - count($rows)));
            $st->execute([$from, $ended]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $live = (string) $r['status'] === 'active';
                $rows[] = ['kind' => 'donor', 'ref' => self::ref('plan:', (string) $r['sub_code'] !== '' ? (string) $r['sub_code'] : (string) $r['id']),
                           'email' => (string) $r['email'], 'name' => (string) $r['name'], 'list' => 'Monthly givers',
                           'status' => $live ? 'active' : 'closed',
                           'detail' => '₦' . number_format((int) $r['amount_ngn']) . ' ' . (string) $r['interval_k']
                                     . ($live ? '' : ', ended'),
                           'at' => self::at((string) ($r['last_at'] ?: $r['started_at']))];
                if ((int) $r['id'] > (int) ($cursors['crm_push.appeal_subs'] ?? $from)) $cursors['crm_push.appeal_subs'] = (string) $r['id'];
                if ((string) $r['ended_at'] > (string) ($cursors['crm_push.appeal_subs_ended'] ?? $ended)) $cursors['crm_push.appeal_subs_ended'] = (string) $r['ended_at'];
            }
        }

        /* One-off gifts, from the private donations file, newest last. */
        if (count($rows) < $limit && function_exists('av_private_path')) {
            $since = (string) (Database::metaGet('crm_push.gifts') ?? '');
            foreach (self::gifts($since, $limit - count($rows)) as $g) {
                $rows[] = $g['row'];
                $cursors['crm_push.gifts'] = $g['at'];
            }
        }

        /* Contact-form enquiries and footer newsletter sign-ups, from contacts.json. */
        if (count($rows) < $limit && function_exists('av_private_path')) {
            $since = (string) (Database::metaGet('crm_push.contacts') ?? '');
            foreach (self::contacts($since, $limit - count($rows)) as $g) {
                $rows[] = $g['row'];
                $cursors['crm_push.contacts'] = $g['at'];
            }
        }

        return ['rows' => $rows, 'cursors' => $cursors, 'outbox' => $outbox];
    }

    /** Enquiries and newsletter sign-ups newer than $since, oldest first. The message itself stays here. */
    private static function contacts(string $since, int $limit): array
    {
        $file = av_private_path('contacts.json');
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $all = [];
        foreach ((array) ($data['contacts'] ?? []) as $c) {
            $at = (string) ($c['created_at'] ?? $c['created'] ?? '');
            $email = (string) ($c['email'] ?? '');
            if ($at === '' || $at <= $since || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            if (($c['type'] ?? '') === 'newsletter') {
                $row = ['kind' => 'subscriber', 'ref' => 'news:' . sha1(strtolower($email)), 'email' => $email,
                        'list' => 'Afrovanguard newsletter', 'status' => 'subscribed', 'at' => self::at($at)];
            } else {
                $ref = (string) ($c['ref'] ?? $c['id'] ?? '');
                if ($ref === '') continue;
                $row = ['kind' => 'enquiry', 'ref' => self::ref('contact:', $ref), 'email' => $email,
                        'phone' => (string) ($c['phone'] ?? ''), 'name' => (string) ($c['name'] ?? ''),
                        'list' => 'Contact form', 'status' => 'active',
                        'detail' => trim((string) ($c['purpose_label'] ?? '') . ': ' . (string) ($c['subject'] ?? ''), ': '),
                        'at' => self::at($at)];
            }
            $all[] = ['at' => $at, 'row' => $row];
        }
        usort($all, static fn($a, $b) => strcmp($a['at'], $b['at']));
        return array_slice($all, 0, $limit);
    }

    /** Gifts newer than $since from donations.json, oldest first. Anonymous givers keep their anonymity on the wall, not from us. */
    private static function gifts(string $since, int $limit): array
    {
        $file = av_private_path('donations.json');
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $out = [];
        foreach (array_reverse((array) ($data['donations'] ?? [])) as $d) {
            $at = (string) ($d['created_at'] ?? '');
            $email = (string) ($d['email'] ?? '');
            if ($at === '' || $at <= $since || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            if (($d['status'] ?? 'confirmed') !== 'confirmed') continue;
            $name = !empty($d['anonymous']) ? '' : (string) ($d['name'] ?? '');
            $amt  = (string) ($d['currency'] ?? 'NGN') === 'NGN' ? '₦' . number_format((float) ($d['amount'] ?? 0))
                                                                  : (string) ($d['currency'] ?? '') . ' ' . (string) ($d['amount'] ?? '');
            $out[] = ['at' => $at, 'row' => [
                'kind' => 'donor', 'ref' => self::ref('gift:', (string) ($d['reference'] ?? $d['id'] ?? md5($at . $email))),
                'email' => $email, 'name' => $name, 'list' => 'Donors', 'status' => 'active',
                'detail' => 'Gave ' . $amt . ' to ' . (string) ($d['campaign'] ?? 'general'), 'at' => self::at($at)]];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /* ══ Sending ═════════════════════════════════════════════════════════ */

    /**
     * Send what is waiting, a batch at a time, until it is all sent or a push
     * fails. Called from tasks/cron.php.
     *
     * @return array{ok:bool, sent:int, pushes:int, error?:string, skipped?:bool}
     */
    public static function run(): array
    {
        if (!self::ready()) return ['ok' => true, 'skipped' => true, 'sent' => 0, 'pushes' => 0, 'why' => 'no CRM secret set'];
        $sent = 0; $refused = 0;
        for ($i = 0; $i < self::MAX_PUSHES; $i++) {
            $c = self::collect();
            if (!$c['rows'] && !$c['outbox']) break;
            $r = $c['rows'] ? self::post($c['rows']) : ['ok' => true, 'refused' => []];
            if (!$r['ok']) {
                self::note('failed: ' . $r['error']);
                return ['ok' => false, 'sent' => $sent, 'pushes' => $i, 'error' => $r['error']];
            }
            /* A row CACENTRE refused BY NAME is not resent — it would be refused for ever
               and hold everything behind it — but it is counted and said, never reported
               as sent. */
            $refused += count($r['refused']);
            if ($r['refused']) error_log('[crm-push] refused: ' . json_encode(array_slice($r['refused'], 0, 5)));
            /* Only now: the CRM has it. */
            foreach ($c['cursors'] as $k => $v) Database::metaSet($k, $v);
            if ($c['outbox']) {
                $in = implode(',', array_map('intval', $c['outbox']));
                Database::pdo()->exec("DELETE FROM av_crm_outbox WHERE id IN ({$in})");
            }
            $sent += count($c['rows']) - count($r['refused']);
            if (count($c['rows']) < self::BATCH) break;
        }
        self::note('sent ' . $sent . ($refused ? ', ' . $refused . ' refused by CACENTRE (see the error log)' : ''));
        return ['ok' => true, 'sent' => $sent, 'refused' => $refused, 'pushes' => $i + 1];
    }

    /** @return array{ok:bool, error?:string, refused?:list<array{ref:string,why:string}>} */
    private static function post(array $rows): array
    {
        $body = (string) json_encode(['token' => self::sign($rows, self::secret())], JSON_UNESCAPED_SLASHES);
        if (is_callable(self::$transport)) {
            $r = (self::$transport)(self::url(), $body);
        } elseif (function_exists('curl_init')) {
            $ch = curl_init(self::url());
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'Afrovanguard/1.0 (+crm-audience)']);
            $out = curl_exec($ch);
            if (class_exists('CacBridge')) CacBridge::see($ch, $out, 'audiences');
            $r = ['code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => is_string($out) ? $out : '', 'error' => curl_error($ch)];
            curl_close($ch);
        } else {
            return ['ok' => false, 'error' => 'this server has no curl'];
        }
        $j = json_decode((string) ($r['body'] ?? ''), true);
        $code = (int) ($r['code'] ?? 0);
        if ($code === 200 && is_array($j) && !empty($j['ok'])) {
            return ['ok' => true, 'refused' => array_values(array_filter((array) ($j['refused'] ?? []), 'is_array'))];
        }
        $err = is_array($j) ? (string) ($j['error'] ?? '') : '';
        return ['ok' => false, 'error' => match (true) {
            $err === 'bad-signature'  => 'CACENTRE refused the signature — the secret here differs from the one it generated.',
            $err === 'not-configured' => 'CACENTRE has no secret for Afrovanguard yet — generate one in the CRM under Audience → Sites.',
            $code === 0               => 'CACENTRE did not answer (' . (string) ($r['error'] ?? 'no connection') . ').',
            default                   => 'CACENTRE answered ' . $code . ($err !== '' ? ' (' . $err . ')' : '') . '.',
        }];
    }

    /** The last result, for the Studio's diagnostics. */
    public static function status(): array
    {
        return ['ready' => self::ready(), 'url' => self::url(), 'last' => (string) (Database::metaGet('crm_push.last') ?? '')];
    }

    private static function note(string $what): void
    {
        try { Database::metaSet('crm_push.last', gmdate('Y-m-d H:i') . ' UTC · ' . mb_substr($what, 0, 200)); } catch (Throwable $e) {}
    }

    private static function at(string $v): string
    {
        $t = strtotime($v);
        return $t ? gmdate('Y-m-d H:i:s', $t) : '';
    }

    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
}
