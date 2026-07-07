<?php
/**
 * lib/Announcements.php — admin broadcasts to the membership.
 *
 * One announcement fans out over EVERY channel that actually reaches a person
 * on any device / browser:
 *   1. Email  — the universal channel (branded, sent from FROM_EMAIL = donations@).
 *   2. In-app — surfaced in the portal's notification bell for every member,
 *               on any browser, with an unread cursor.
 *   3. Native browser notification — when a member has the portal/PWA open and
 *      has granted permission (progressive enhancement; the SW carries the
 *      Web-Push path for when VAPID keys are configured).
 *
 * Storage is driver-aware (SQLite / MySQL / Postgres) and idempotent.
 */
declare(strict_types=1);

final class Announcements
{
    public const AUDIENCES = ['all', 'members', 'learners'];

    /** Create the table if missing (driver-aware; `key` words avoided). */
    public static function ensure(?PDO $pdo = null): void
    {
        static $done = false; if ($done) return; $done = true;
        $pdo = $pdo ?: Database::pdo();
        switch ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
                $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
                    id INTEGER PRIMARY KEY AUTO_INCREMENT,
                    title VARCHAR(180) NOT NULL DEFAULT '',
                    body TEXT NOT NULL,
                    url VARCHAR(300) NOT NULL DEFAULT '',
                    audience VARCHAR(20) NOT NULL DEFAULT 'all',
                    created_by VARCHAR(180) NOT NULL DEFAULT '',
                    email_count INTEGER NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                break;
            case 'pgsql':
                $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
                    id SERIAL PRIMARY KEY,
                    title VARCHAR(180) NOT NULL DEFAULT '',
                    body TEXT NOT NULL,
                    url VARCHAR(300) NOT NULL DEFAULT '',
                    audience VARCHAR(20) NOT NULL DEFAULT 'all',
                    created_by VARCHAR(180) NOT NULL DEFAULT '',
                    email_count INTEGER NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )");
                break;
            default:
                $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    title TEXT NOT NULL DEFAULT '',
                    body TEXT NOT NULL,
                    url TEXT NOT NULL DEFAULT '',
                    audience TEXT NOT NULL DEFAULT 'all',
                    created_by TEXT NOT NULL DEFAULT '',
                    email_count INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )");
        }
    }

    /** Persist an announcement. Returns its id. */
    public static function create(array $in): int
    {
        self::ensure();
        $title = trim(mb_substr(trim((string) ($in['title'] ?? '')), 0, 180));
        $body  = trim((string) ($in['body'] ?? ''));
        if ($title === '') throw new InvalidArgumentException('An announcement needs a title.');
        if ($body === '')  throw new InvalidArgumentException('Write the announcement message.');
        if (mb_strlen($body) > 5000) throw new InvalidArgumentException('Keep the message under 5,000 characters.');
        $url = trim((string) ($in['url'] ?? ''));
        if ($url !== '' && !preg_match('~^(https?://|/)~i', $url)) $url = '';
        $audience = in_array(($in['audience'] ?? 'all'), self::AUDIENCES, true) ? $in['audience'] : 'all';

        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO announcements (title, body, url, audience, created_by) VALUES (?,?,?,?,?)')
            ->execute([$title, $body, $url, $audience, mb_substr((string) ($in['created_by'] ?? ''), 0, 180)]);
        return (int) $pdo->lastInsertId();
    }

    /** Recent announcements (newest first) for the portal + Studio. */
    public static function recent(int $limit = 20): array
    {
        self::ensure();
        $limit = max(1, min(100, $limit));
        return Database::pdo()->query('SELECT * FROM announcements ORDER BY id DESC LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Announcements newer than a given id — for incremental "new" checks. */
    public static function since(int $sinceId, int $limit = 20): array
    {
        self::ensure();
        $limit = max(1, min(100, $limit));
        $s = Database::pdo()->prepare('SELECT * FROM announcements WHERE id > ? ORDER BY id DESC LIMIT ' . $limit);
        $s->execute([max(0, $sinceId)]);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Target recipients for an audience: [['name'=>,'email'=>], …] (active accounts). */
    public static function recipients(string $audience): array
    {
        $pdo = Database::pdo();
        $rows = $pdo->query("SELECT name, email, role FROM lms_users WHERE status = 'active' AND email <> '' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            if (!filter_var($r['email'], FILTER_VALIDATE_EMAIL)) continue;
            $isOrg = class_exists('LmsAuth') ? LmsAuth::isOrgMember($r) : false;
            if ($audience === 'members' && !$isOrg) continue;
            if ($audience === 'learners' && $isOrg) continue;
            $out[] = ['name' => (string) $r['name'], 'email' => (string) $r['email']];
        }
        return $out;
    }

    /**
     * Email an announcement to its audience. Best-effort, per-recipient (so a
     * bad address never blocks the rest), capped for one request. Returns
     * ['sent'=>int, 'failed'=>int, 'total'=>int, 'capped'=>bool].
     */
    public static function email(array $ann, int $cap = 400): array
    {
        if (!class_exists('Mailer') || !Mailer::configured()) {
            return ['sent' => 0, 'failed' => 0, 'total' => 0, 'capped' => false, 'skipped' => 'email not configured'];
        }
        $recipients = self::recipients((string) ($ann['audience'] ?? 'all'));
        $total = count($recipients);
        $capped = $total > $cap;
        $recipients = array_slice($recipients, 0, $cap);
        $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://afrovanguard.org.ng';
        $url  = (string) ($ann['url'] ?? '');
        if ($url !== '' && $url[0] === '/') $url = $site . $url;
        $cta  = $url !== '' ? ['url' => $url, 'text' => 'Open'] : null;
        $rows = array_map(fn($p) => nl2br(htmlspecialchars($p, ENT_QUOTES)), preg_split('/\n{2,}/', trim((string) $ann['body'])) ?: [(string) $ann['body']]);
        $subject = 'Afrovanguard — ' . (string) $ann['title'];
        // Announcements send from the GENERAL address, never donations@ (which is
        // reserved for donation receipts). FROM_GENERAL defaults to the org mailbox.
        $fromOpt = [];
        if (defined('FROM_GENERAL') && FROM_GENERAL !== '') $fromOpt = ['from' => FROM_GENERAL, 'fromName' => (defined('FROM_GENERAL_NAME') ? FROM_GENERAL_NAME : 'Afrovanguard')];
        $sent = 0; $failed = 0;
        foreach ($recipients as $r) {
            $html = Mailer::shell((string) $ann['title'], $rows, $cta, 'A message from Afrovanguard');
            if (Mailer::send($r['email'], $subject, $html, $fromOpt)) $sent++; else $failed++;
        }
        try {
            Database::pdo()->prepare('UPDATE announcements SET email_count = ? WHERE id = ?')->execute([$sent, (int) ($ann['id'] ?? 0)]);
        } catch (Throwable $e) { /* non-fatal */ }
        return ['sent' => $sent, 'failed' => $failed, 'total' => $total, 'capped' => $capped];
    }
}
