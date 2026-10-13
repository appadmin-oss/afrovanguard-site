<?php
/**
 * lib/NggVolunteers.php — NextGen Genius's volunteers, known here.
 *
 * NGG volunteers serve Afrovanguard too, and sign in here with the email NGG
 * knows them by. This keeps a copy of NGG's volunteer list so that, when they
 * sign in, they are recognised as volunteers (Volunteers::isVolunteer) and
 * see their NGG role, centre and service in their dashboard; and so the
 * dashboard's People can list them beside Afrovanguard's own.
 *
 * NGG volunteers only — never Summer School's. NGG decides which is which
 * (api/hooks/afrovanguard.php there); this site never sees the others.
 *
 * Fetched, signed with the secret the two sites already share
 * (NGG_WEBHOOK_SECRET here, afrovanguard.ngv_webhook_secret there), under its
 * own purpose so the signature cannot be replayed at another endpoint:
 *   POST {NGG_URL}/api/hooks/afrovanguard.php   {"op":"volunteers","after":…,"limit":…}
 *   X-NGG-Timestamp: unix seconds
 *   X-NGG-Signature: sha256=hex(HMAC_SHA256(secret, "volunteers." + ts + "." + body))
 * Every six hours from the cron, or at once from the dashboard ("Sync now").
 * A volunteer NGG stops listing is kept, marked gone, so history stays true.
 */
declare(strict_types=1);

final class NggVolunteers
{
    public const EVERY_HOURS = 6;
    public const DEFAULT_URL = 'https://next.afrovanguard.org.ng';
    private const PAGE = 200;
    private const MAX_PAGES = 50;

    /** For tests: replaces the HTTP call with fn(string $url, string $body, array $headers): array{code:int, body:string}. */
    public static $transport = null;

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        $pdo = Database::pdo();
        Database::execSchema($pdo, "
        CREATE TABLE IF NOT EXISTS ngg_volunteers (
            volunteer_id VARCHAR(40) NOT NULL PRIMARY KEY,
            member_id VARCHAR(40) NOT NULL DEFAULT '',
            name VARCHAR(160) NOT NULL DEFAULT '',
            email VARCHAR(200) NOT NULL DEFAULT '',
            phone VARCHAR(32) NOT NULL DEFAULT '',
            role VARCHAR(40) NOT NULL DEFAULT '',
            role_label VARCHAR(80) NOT NULL DEFAULT '',
            centre VARCHAR(80) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            safeguarding_cleared INTEGER NOT NULL DEFAULT 0,
            photo_url VARCHAR(500) NOT NULL DEFAULT '',
            joined_at VARCHAR(40) NOT NULL DEFAULT '',
            sessions INTEGER NOT NULL DEFAULT 0,
            hours INTEGER NOT NULL DEFAULT 0,
            certificates INTEGER NOT NULL DEFAULT 0,
            points INTEGER NOT NULL DEFAULT 0,
            level VARCHAR(40) NOT NULL DEFAULT '',
            user_id INTEGER NOT NULL DEFAULT 0,
            synced_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS ngg_volunteer_syncs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            at VARCHAR(32) NOT NULL DEFAULT '',
            ok INTEGER NOT NULL DEFAULT 0,
            received INTEGER NOT NULL DEFAULT 0,
            linked INTEGER NOT NULL DEFAULT 0,
            gone INTEGER NOT NULL DEFAULT 0,
            error VARCHAR(255) NOT NULL DEFAULT ''
        )");
        try { $pdo->exec('CREATE INDEX idx_ngg_vol_email ON ngg_volunteers (email)'); } catch (Throwable $e) {}
        try { $pdo->exec('CREATE INDEX idx_ngg_vol_user ON ngg_volunteers (user_id)'); } catch (Throwable $e) {}
    }

    /** Where NGG lives: the NGG_URL setting, else NGG's public address. */
    public static function baseUrl(): string
    {
        $v = (string) (getenv('NGG_URL') ?: ($_SERVER['NGG_URL'] ?? ''));
        if ($v === '' && class_exists('AvSettings')) { try { $v = (string) AvSettings::get('NGG_URL'); } catch (Throwable $e) {} }
        return rtrim($v !== '' ? $v : self::DEFAULT_URL, '/');
    }

    public static function configured(): bool { return function_exists('av_ngg_secret') && av_ngg_secret() !== ''; }

    private static function post(array $body): array
    {
        $secret = av_ngg_secret();
        $raw = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-NGG-Timestamp: ' . $ts,
                    'X-NGG-Signature: sha256=' . hash_hmac('sha256', 'volunteers.' . $ts . '.' . $raw, $secret)];
        $url = self::baseUrl() . '/api/hooks/afrovanguard.php';
        if (is_callable(self::$transport)) {
            $r = (self::$transport)($url, $raw, $headers);
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8]);
            $out = curl_exec($ch);
            $r = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($out) ? $out : '', 'error' => curl_error($ch)];
            curl_close($ch);
        }
        $j = json_decode((string) ($r['body'] ?? ''), true);
        if ((int) ($r['code'] ?? 0) !== 200 || !is_array($j)) {
            $why = (int) ($r['code'] ?? 0) === 401 ? 'NGG refused the signature — the shared secret differs between the two sites.'
                 : ((int) ($r['code'] ?? 0) === 404 ? 'NGG has no shared secret set (afrovanguard.ngv_webhook_secret), or NGG_URL is wrong.'
                 : 'NGG did not answer (' . ((int) ($r['code'] ?? 0) ?: ($r['error'] ?? 'no connection')) . ').');
            return ['ok' => false, 'error' => $why];
        }
        return ['ok' => true] + $j;
    }

    /** Fetch the whole list and bring the copy up to date. */
    public static function sync(): array
    {
        self::ensure();
        if (!self::configured()) return ['ok' => false, 'error' => 'NGG_WEBHOOK_SECRET is not set, so NGG cannot be asked.'];
        $pdo = Database::pdo();
        $now = gmdate('Y-m-d H:i:s');
        $after = 0; $seen = []; $received = 0;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $r = self::post(['op' => 'volunteers', 'after' => $after, 'limit' => self::PAGE]);
            if (!$r['ok']) return self::log(false, $received, 0, 0, (string) $r['error']);
            foreach ((array) ($r['volunteers'] ?? []) as $v) {
                $id = mb_substr(trim((string) ($v['volunteerId'] ?? '')), 0, 40);
                if ($id === '') continue;
                self::upsert($id, $v, $now);
                $seen[$id] = true; $received++;
            }
            if (!empty($r['done']) || ($r['next'] ?? null) === null) break;
            $after = (int) $r['next'];
        }
        /* NGG no longer lists them: kept, marked gone. */
        $gone = 0;
        foreach ($pdo->query("SELECT volunteer_id FROM ngg_volunteers WHERE status <> 'gone'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (!isset($seen[(string) $id])) { $pdo->prepare("UPDATE ngg_volunteers SET status = 'gone', synced_at = ? WHERE volunteer_id = ?")->execute([$now, $id]); $gone++; }
        }
        $linked = self::link();
        return self::log(true, $received, $linked, $gone, '');
    }

    private static function upsert(string $id, array $v, string $now): void
    {
        $pdo = Database::pdo();
        $s = static fn($k, $n) => mb_substr(trim((string) ($v[$k] ?? '')), 0, $n);
        $row = [$s('memberId', 40), $s('name', 160), strtolower($s('email', 200)), $s('phone', 32), $s('role', 40), $s('roleLabel', 80), $s('centre', 80),
                in_array((string) ($v['status'] ?? 'active'), ['active', 'inactive', 'suspended'], true) ? (string) $v['status'] : 'active',
                !empty($v['safeguardingCleared']) ? 1 : 0, preg_match('#^https://#i', (string) ($v['photoUrl'] ?? '')) ? $s('photoUrl', 500) : '',
                $s('joinedAt', 40), max(0, (int) ($v['sessions'] ?? 0)), max(0, (int) ($v['hours'] ?? 0)), max(0, (int) ($v['certificates'] ?? 0)),
                max(0, (int) ($v['points'] ?? 0)), $s('level', 40), $now];
        $st = $pdo->prepare('SELECT 1 FROM ngg_volunteers WHERE volunteer_id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn()) {
            $pdo->prepare('UPDATE ngg_volunteers SET member_id=?, name=?, email=?, phone=?, role=?, role_label=?, centre=?, status=?, safeguarding_cleared=?, photo_url=?, joined_at=?, sessions=?, hours=?, certificates=?, points=?, level=?, synced_at=? WHERE volunteer_id=?')
                ->execute(array_merge($row, [$id]));
        } else {
            $pdo->prepare('INSERT INTO ngg_volunteers (member_id, name, email, phone, role, role_label, centre, status, safeguarding_cleared, photo_url, joined_at, sessions, hours, certificates, points, level, synced_at, volunteer_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge($row, [$id]));
        }
    }

    /** Each NGG volunteer to the account with their email here. Returns how many were newly linked. */
    public static function link(): int
    {
        self::ensure();
        $pdo = Database::pdo();
        $n = 0;
        foreach ($pdo->query("SELECT v.volunteer_id, u.id FROM ngg_volunteers v JOIN lms_users u ON LOWER(u.email) = v.email WHERE v.email <> '' AND v.user_id <> u.id")->fetchAll(PDO::FETCH_NUM) as [$vid, $uid]) {
            $pdo->prepare('UPDATE ngg_volunteers SET user_id = ? WHERE volunteer_id = ?')->execute([(int) $uid, $vid]);
            $n++;
        }
        return $n;
    }

    private static function log(bool $ok, int $received, int $linked, int $gone, string $error): array
    {
        Database::pdo()->prepare('INSERT INTO ngg_volunteer_syncs (at, ok, received, linked, gone, error) VALUES (?,?,?,?,?,?)')
            ->execute([gmdate('Y-m-d H:i:s'), $ok ? 1 : 0, $received, $linked, $gone, mb_substr($error, 0, 255)]);
        return $ok ? ['ok' => true, 'received' => $received, 'linked' => $linked, 'gone' => $gone] : ['ok' => false, 'error' => $error, 'received' => $received];
    }

    /** Run by the cron: a sync when the last good one is older than EVERY_HOURS. */
    public static function syncIfDue(): ?array
    {
        self::ensure();
        if (!self::configured()) return null;
        $last = (string) (Database::pdo()->query('SELECT MAX(at) FROM ngg_volunteer_syncs')->fetchColumn() ?: '');
        if ($last !== '' && strtotime($last . ' UTC') > time() - self::EVERY_HOURS * 3600) return null;
        return self::sync();
    }

    public static function status(): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $last = $pdo->query('SELECT * FROM ngg_volunteer_syncs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
        $good = (string) ($pdo->query('SELECT MAX(at) FROM ngg_volunteer_syncs WHERE ok = 1')->fetchColumn() ?: '');
        return ['configured' => self::configured(), 'url' => self::baseUrl(), 'last' => $last, 'last_good' => $good,
                'count' => (int) $pdo->query("SELECT COUNT(*) FROM ngg_volunteers WHERE status = 'active'")->fetchColumn(),
                'linked' => (int) $pdo->query("SELECT COUNT(*) FROM ngg_volunteers WHERE status = 'active' AND user_id > 0")->fetchColumn()];
    }

    /** This person's NGG volunteer record, active, by account or by email. */
    public static function forUser(array $u): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT * FROM ngg_volunteers WHERE status = 'active' AND (user_id = ? OR (email <> '' AND email = ?)) ORDER BY user_id DESC LIMIT 1");
        $st->execute([(int) ($u['id'] ?? 0), strtolower(trim((string) ($u['email'] ?? '')))]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** NGG volunteers for the People directory. */
    public static function directory(string $q = '', string $centre = ''): array
    {
        self::ensure();
        $sql = "SELECT volunteer_id, name, role_label, role, centre, photo_url, user_id, hours, level, joined_at FROM ngg_volunteers WHERE status = 'active'";
        $args = [];
        if (trim($q) !== '') { $sql .= ' AND (LOWER(name) LIKE ? OR LOWER(role_label) LIKE ? OR LOWER(centre) LIKE ?)'; $l = '%' . mb_strtolower(trim($q)) . '%'; array_push($args, $l, $l, $l); }
        if (trim($centre) !== '') { $sql .= ' AND centre = ?'; $args[] = trim($centre); }
        $st = Database::pdo()->prepare($sql . ' ORDER BY name LIMIT 300');
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
