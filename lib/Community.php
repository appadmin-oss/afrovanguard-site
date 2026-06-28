<?php
/**
 * lib/Community.php — the Afrovanguard Community: spaces, posts, replies,
 * reactions and a light poll, on the shared SQLite/MySQL/Postgres layer.
 *
 * Adapted from the supplied Community design but branded + wired into the real
 * account system (LmsAuth): posting is members-only, reading is public, the
 * official @afrovanguard.org.ng accounts read as verified, and every new post
 * emits a `community.post` event (→ webhooks / the bot / integrations).
 *
 * Replies are posts with reply_to set (one table, threaded). Like counts are
 * denormalised on the post with a per-user reactions table for the toggle.
 * Tables are created on first use, driver-aware (SQLite output byte-identical).
 */
declare(strict_types=1);

final class Community
{
    /** The official bot's email (an internal, passwordless account). */
    const BOT_EMAIL = 'community-bot@afrovanguard.org.ng';
    const BOT_NAME  = 'Afrovanguard';

    public static function ensure(?PDO $pdo = null): void
    {
        static $done = false;
        $db = $pdo ?: Database::pdo();
        if ($done && $pdo === null) return;
        $ddl = "CREATE TABLE IF NOT EXISTS community_spaces (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug VARCHAR(60) UNIQUE NOT NULL,
            name VARCHAR(80) NOT NULL DEFAULT '',
            color VARCHAR(9) NOT NULL DEFAULT '#b8860b',
            blurb VARCHAR(160) NOT NULL DEFAULT '',
            sort INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS community_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            author_id INTEGER NOT NULL,
            space_id INTEGER NOT NULL DEFAULT 0,
            reply_to INTEGER,
            body TEXT NOT NULL,
            poll_json TEXT,
            pinned INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'published',
            likes INTEGER NOT NULL DEFAULT 0,
            reply_count INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_cposts_feed ON community_posts(space_id, status, created_at);
        CREATE INDEX IF NOT EXISTS idx_cposts_thread ON community_posts(reply_to, created_at);
        CREATE TABLE IF NOT EXISTS community_reactions (
            post_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (post_id, user_id)
        );";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
        // Set $done BEFORE seeding so the nested ensure() inside botPost short-circuits.
        if ($pdo === null) { self::seedSpaces($db); $done = true; self::seedWelcome($db); }
    }

    private static function seedWelcome(PDO $db): void
    {
        if ((int) $db->query('SELECT COUNT(*) FROM community_posts')->fetchColumn() > 0) return;
        try {
            self::botPost('announcements',
                "Welcome to the Afrovanguard Community. 🌱 This is where members behind the movement talk, share field notes, and lift each other up.\n\nThree house rules: debate the work — never the person; sources beat opinions; keep canvassing out of the threads. Introduce yourself in Open floor.",
                true);
        } catch (Throwable $e) { error_log('[community] welcome seed skipped: ' . $e->getMessage()); }
    }

    private static function seedSpaces(PDO $db): void
    {
        if ((int) $db->query('SELECT COUNT(*) FROM community_spaces')->fetchColumn() > 0) return;
        $rows = [
            ['announcements', 'Announcements', '#b8860b', 'Official notices from the Afrovanguard team.'],
            ['education',     'Education',      '#1a6118', 'LCASP, the Academy, and learning across the movement.'],
            ['leadership',    'Leadership',     '#27607a', 'Integrity, civics and building incorruptible leaders.'],
            ['stories',       'Diary & Stories','#5b3a8a', 'Field notes, wins, and the work as we learn it.'],
            ['volunteering',  'Volunteering',   '#b03a5b', 'Mobilise, organise, and show up for the centres.'],
            ['events',        'Events',         '#2b373d', 'Town halls, the Gala, expos and meetups.'],
            ['open-floor',    'Open floor',     '#a47306', 'Everything else — introduce yourself and connect.'],
        ];
        $ins = $db->prepare('INSERT INTO community_spaces (slug, name, color, blurb, sort) VALUES (?,?,?,?,?)');
        foreach ($rows as $i => $r) { $ins->execute([$r[0], $r[1], $r[2], $r[3], $i]); }
    }

    /* ── bot ── */
    public static function botId(): int
    {
        $db = Database::pdo();
        $s = $db->prepare('SELECT id FROM lms_users WHERE email = ?'); $s->execute([self::BOT_EMAIL]);
        $id = (int) $s->fetchColumn();
        if ($id) return $id;
        $db->prepare("INSERT INTO lms_users (name, email, password_hash, role, email_verified) VALUES (?,?,?,?,1)")
           ->execute([self::BOT_NAME, self::BOT_EMAIL, password_hash(bin2hex(random_bytes(18)), PASSWORD_BCRYPT), 'member']);
        return (int) $db->lastInsertId();
    }

    /** Post as the official Afrovanguard bot (used by integrations / announcements). */
    public static function botPost(string $spaceSlug, string $body, bool $pinned = false): int
    {
        return self::createPost(self::botId(), $spaceSlug, $body, null, $pinned);
    }

    /* ── spaces ── */
    public static function spaces(): array
    {
        self::ensure();
        return Database::pdo()->query('SELECT * FROM community_spaces ORDER BY sort ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    private static function spaceId(string $slug): int
    {
        $s = Database::pdo()->prepare('SELECT id FROM community_spaces WHERE slug = ?'); $s->execute([$slug]);
        return (int) $s->fetchColumn();
    }

    /* ── posting ── */
    public static function createPost(int $authorId, string $spaceSlug, string $body, ?array $poll = null, bool $pinned = false): int
    {
        self::ensure();
        $body = trim($body);
        if ($body === '' || $authorId <= 0) return 0;
        $sid = self::spaceId($spaceSlug) ?: self::spaceId('open-floor');
        $db = Database::pdo();
        $db->prepare('INSERT INTO community_posts (author_id, space_id, body, poll_json, pinned, created_at) VALUES (?,?,?,?,?,?)')
           ->execute([$authorId, $sid, mb_substr($body, 0, 5000), $poll ? json_encode($poll) : null, $pinned ? 1 : 0, gmdate('Y-m-d H:i:s')]);
        $id = (int) $db->lastInsertId();
        if (class_exists('Events')) {
            Events::emit('community.post', ['id' => $id, 'space' => $spaceSlug, 'author_id' => $authorId, 'excerpt' => mb_substr($body, 0, 180)]);
        }
        return $id;
    }

    public static function reply(int $authorId, int $postId, string $body): int
    {
        self::ensure();
        $body = trim($body);
        if ($body === '' || $authorId <= 0 || $postId <= 0) return 0;
        $db = Database::pdo();
        $parent = $db->prepare('SELECT space_id FROM community_posts WHERE id = ? AND reply_to IS NULL AND status = ?');
        $parent->execute([$postId, 'published']);
        $sid = $parent->fetchColumn();
        if ($sid === false) return 0;
        $db->prepare('INSERT INTO community_posts (author_id, space_id, reply_to, body, created_at) VALUES (?,?,?,?,?)')
           ->execute([$authorId, (int) $sid, $postId, mb_substr($body, 0, 5000), gmdate('Y-m-d H:i:s')]);
        $rid = (int) $db->lastInsertId();
        $db->prepare('UPDATE community_posts SET reply_count = reply_count + 1 WHERE id = ?')->execute([$postId]);
        if (class_exists('Events')) Events::emit('community.reply', ['id' => $rid, 'post_id' => $postId, 'author_id' => $authorId]);
        return $rid;
    }

    /** Toggle a like; returns ['liked'=>bool,'likes'=>int]. */
    public static function toggleLike(int $postId, int $userId): array
    {
        self::ensure();
        $db = Database::pdo();
        $has = $db->prepare('SELECT 1 FROM community_reactions WHERE post_id = ? AND user_id = ?');
        $has->execute([$postId, $userId]);
        if ($has->fetchColumn()) {
            $db->prepare('DELETE FROM community_reactions WHERE post_id = ? AND user_id = ?')->execute([$postId, $userId]);
            $db->prepare('UPDATE community_posts SET likes = CASE WHEN likes > 0 THEN likes - 1 ELSE 0 END WHERE id = ?')->execute([$postId]);
            $liked = false;
        } else {
            $db->prepare(Database::insertIgnore('community_reactions', ['post_id', 'user_id']))->execute([$postId, $userId]);
            $db->prepare('UPDATE community_posts SET likes = likes + 1 WHERE id = ?')->execute([$postId]);
            $liked = true;
        }
        $n = (int) $db->query('SELECT likes FROM community_posts WHERE id = ' . (int) $postId)->fetchColumn();
        return ['liked' => $liked, 'likes' => $n];
    }

    /* ── reading ── */
    private const POST_COLS =
        'p.id, p.body, p.poll_json, p.pinned, p.likes, p.reply_count, p.created_at,
         s.slug AS space_slug, s.name AS space_name, s.color AS space_color,
         u.name AS author, u.email AS author_email, u.role AS author_role';

    /** Top-level feed. $space = slug|null. $sort = top|latest. */
    public static function feed(?string $space, string $sort = 'latest', int $limit = 15, int $offset = 0, int $viewerId = 0): array
    {
        self::ensure();
        $db = Database::pdo();
        $where = "p.reply_to IS NULL AND p.status = 'published'";
        $args = [];
        if ($space) { $where .= ' AND s.slug = ?'; $args[] = $space; }
        $order = $sort === 'top' ? 'p.pinned DESC, p.likes DESC, p.id DESC' : 'p.pinned DESC, p.id DESC';
        $limit = max(1, min(50, $limit)); $offset = max(0, $offset);
        $sql = 'SELECT ' . self::POST_COLS . '
                FROM community_posts p
                JOIN community_spaces s ON s.id = p.space_id
                JOIN lms_users u ON u.id = p.author_id
                WHERE ' . $where . ' ORDER BY ' . $order . " LIMIT $limit OFFSET $offset";
        $st = $db->prepare($sql); $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn($r) => self::shape($r, $viewerId), $rows);
    }

    public static function replies(int $postId, int $viewerId = 0): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT ' . self::POST_COLS . '
            FROM community_posts p JOIN community_spaces s ON s.id = p.space_id JOIN lms_users u ON u.id = p.author_id
            WHERE p.reply_to = ? AND p.status = \'published\' ORDER BY p.id ASC LIMIT 200');
        $st->execute([$postId]);
        return array_map(fn($r) => self::shape($r, $viewerId), $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public static function post(int $id, int $viewerId = 0): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT ' . self::POST_COLS . '
            FROM community_posts p JOIN community_spaces s ON s.id = p.space_id JOIN lms_users u ON u.id = p.author_id
            WHERE p.id = ? AND p.status = \'published\'');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::shape($r, $viewerId) : null;
    }

    /** Normalise a row into a view model (author identity, tier, liked-state, poll). */
    private static function shape(array $r, int $viewerId): array
    {
        $email = (string) $r['author_email'];
        $org   = defined('AV_ORG_DOMAIN') && str_ends_with(strtolower($email), '@' . strtolower((string) AV_ORG_DOMAIN));
        $isBot = strtolower($email) === self::BOT_EMAIL;
        $name  = (string) $r['author'];
        $liked = false;
        if ($viewerId > 0) {
            $s = Database::pdo()->prepare('SELECT 1 FROM community_reactions WHERE post_id = ? AND user_id = ?');
            $s->execute([(int) $r['id'], $viewerId]);
            $liked = (bool) $s->fetchColumn();
        }
        $poll = null;
        if (!empty($r['poll_json'])) { $p = json_decode((string) $r['poll_json'], true); if (is_array($p)) $poll = $p; }
        return [
            'id' => (int) $r['id'],
            'body' => (string) $r['body'],
            'pinned' => (int) $r['pinned'] === 1,
            'likes' => (int) $r['likes'],
            'reply_count' => (int) $r['reply_count'],
            'created_at' => (string) $r['created_at'],
            'ago' => self::ago((string) $r['created_at']),
            'space' => ['slug' => (string) $r['space_slug'], 'name' => (string) $r['space_name'], 'color' => (string) $r['space_color']],
            'author' => $name,
            'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
            'tier' => $isBot ? 'Official' : ($org ? 'Member' : 'Learner'),
            'verified' => $org || $isBot,
            'is_bot' => $isBot,
            'liked' => $liked,
            'poll' => $poll,
        ];
    }

    private static function ago(string $iso): string
    {
        $t = strtotime($iso . ' UTC') ?: time();
        $d = max(0, time() - $t);
        if ($d < 60) return 'just now';
        if ($d < 3600) return floor($d / 60) . 'm';
        if ($d < 86400) return floor($d / 3600) . 'h';
        if ($d < 604800) return floor($d / 86400) . 'd';
        return gmdate('M j', $t);
    }

    /* ── side panels ── */
    public static function pulse(): array
    {
        self::ensure();
        $db = Database::pdo();
        $today = gmdate('Y-m-d');
        $n = fn($sql, $a = []) => (function () use ($db, $sql, $a) { $s = $db->prepare($sql); $s->execute($a); return (int) $s->fetchColumn(); })();
        return [
            'posts_today'   => $n("SELECT COUNT(*) FROM community_posts WHERE reply_to IS NULL AND created_at >= ?", [$today]),
            'replies_today' => $n("SELECT COUNT(*) FROM community_posts WHERE reply_to IS NOT NULL AND created_at >= ?", [$today]),
            'members'       => $n("SELECT COUNT(*) FROM lms_users"),
            'spaces'        => $n("SELECT COUNT(*) FROM community_spaces"),
        ];
    }

    /** Spaces with their live top-level post counts (for the rail). */
    public static function spaceCounts(): array
    {
        self::ensure();
        $rows = Database::pdo()->query(
            "SELECT s.slug, COUNT(p.id) AS n
             FROM community_spaces s
             LEFT JOIN community_posts p ON p.space_id = s.id AND p.reply_to IS NULL AND p.status='published'
             GROUP BY s.slug"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        return $rows ?: [];
    }

    /* ── moderation (admin) ── */
    public static function setStatus(int $postId, string $status): void
    {
        self::ensure();
        if (!in_array($status, ['published', 'hidden', 'removed'], true)) return;
        Database::pdo()->prepare('UPDATE community_posts SET status = ? WHERE id = ?')->execute([$status, $postId]);
    }
    public static function setPinned(int $postId, bool $pinned): void
    {
        self::ensure();
        Database::pdo()->prepare('UPDATE community_posts SET pinned = ? WHERE id = ?')->execute([$pinned ? 1 : 0, $postId]);
    }
}
