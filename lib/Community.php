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
        );
        CREATE TABLE IF NOT EXISTS community_chat (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            author_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_cchat_feed ON community_chat(id);";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
        // Chat channels are a later addition — add the column idempotently.
        try { if (!Database::columnExists('community_chat', 'channel')) $db->exec("ALTER TABLE community_chat ADD COLUMN channel VARCHAR(24) NOT NULL DEFAULT 'general'"); }
        catch (Throwable $e) { /* already there / driver quirk */ }
        // Threads: a reply points at its parent message (0 = top-level).
        try { if (!Database::columnExists('community_chat', 'parent_id')) $db->exec("ALTER TABLE community_chat ADD COLUMN parent_id INTEGER NOT NULL DEFAULT 0"); }
        catch (Throwable $e) { /* already there */ }
        // Emoji reactions on chat messages (Slack-style). One row per (message,user,emoji).
        try {
            $rddl = "CREATE TABLE IF NOT EXISTS community_chat_reactions (
                chat_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                emoji VARCHAR(16) NOT NULL DEFAULT '',
                created_at VARCHAR(32) NOT NULL DEFAULT '',
                PRIMARY KEY (chat_id, user_id, emoji)
            );";
            $db->exec($drv === 'sqlite' ? $rddl : Database::translateDDL($rddl, $drv));
        } catch (Throwable $e) { /* already there */ }
        // Typing indicators — one short-lived row per (user,channel), refreshed
        // while a member is composing. Read back within a few seconds' window.
        try {
            $tddl = "CREATE TABLE IF NOT EXISTS community_typing (
                user_id INTEGER NOT NULL,
                channel VARCHAR(24) NOT NULL DEFAULT 'general',
                updated_at INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, channel)
            );";
            $db->exec($drv === 'sqlite' ? $tddl : Database::translateDDL($tddl, $drv));
        } catch (Throwable $e) { /* already there */ }
        // Data-classification is a later addition — add the column idempotently.
        try { if (!Database::columnExists('community_posts', 'classification')) $db->exec("ALTER TABLE community_posts ADD COLUMN classification VARCHAR(16) NOT NULL DEFAULT 'members'"); }
        catch (Throwable $e) { /* already there / driver quirk */ }
        // Set $done BEFORE seeding so the nested ensure() inside botPost short-circuits.
        if ($pdo === null) { self::seedSpaces($db); $done = true; self::seedWelcome($db); }
    }

    /** The members-chat channels (fixed set, keeps the space tidy). */
    const CHAT_CHANNELS = ['general' => 'General', 'announcements' => 'Announcements', 'mentorship' => 'Mentorship', 'random' => 'Random'];
    private static function normChannel(string $c): string { $c = strtolower(trim($c)); return isset(self::CHAT_CHANNELS[$c]) ? $c : 'general'; }

    /** Channel list with the latest message id in each (for unread dots). */
    public static function chatChannels(): array
    {
        self::ensure();
        $last = [];
        try {
            $rows = Database::pdo()->query('SELECT channel, MAX(id) AS mx FROM community_chat GROUP BY channel')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) $last[(string) $r['channel']] = (int) $r['mx'];
        } catch (Throwable $e) {}
        $out = [];
        foreach (self::CHAT_CHANNELS as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label, 'last_id' => $last[$key] ?? 0];
        }
        return $out;
    }

    /** Short per-channel descriptions shown under the channel title. */
    const CHAT_TOPICS = [
        'general'       => 'The whole team — announcements, questions, wins.',
        'announcements' => 'Official updates from the Afrovanguard team.',
        'mentorship'    => 'Mentors and mentees — sessions, notes, guidance.',
        'random'        => 'Off-topic. Say hi, share a link, take a breather.',
    ];

    /**
     * Org members for the chat members rail: mentors first, then everyone else,
     * each with role + live presence. Uses the Collab presence heartbeat so the
     * green dots match "who's online".
     */
    public static function chatMembers(int $viewerId, int $limit = 60): array
    {
        self::ensure();
        try {
            $like = self::orgEmailLike();
            $window = class_exists('Collab') ? 180 : 180;   // seconds → "online"
            $cut = time() - $window;
            $sql = "SELECT u.id, u.name, u.email, u.role,
                           (SELECT p.last_seen FROM presence p WHERE p.user_id = u.id) AS last_seen
                    FROM lms_users u
                    WHERE u.status = 'active' AND LOWER(u.email) LIKE ? AND u.email <> ?
                    ORDER BY u.name ASC LIMIT " . max(1, min(200, $limit));
            $st = Database::pdo()->prepare($sql);
            $st->execute([$like, self::BOT_EMAIL]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[community] chatMembers: ' . $e->getMessage()); return ['mentors' => [], 'members' => [], 'online' => 0]; }
        $mentorRoles = ['mentor', 'instructor', 'coordinator', 'admin'];
        $mentors = []; $members = []; $online = 0;
        foreach ($rows as $r) {
            $role = strtolower((string) ($r['role'] ?? 'member'));
            $isOnline = ((int) ($r['last_seen'] ?? 0)) >= $cut;
            if ($isOnline) $online++;
            $name = (string) $r['name'];
            $m = [
                'id'      => (int) $r['id'],
                'name'    => $name,
                'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
                'role'    => $role,
                'online'  => $isOnline,
                'is_me'   => (int) $r['id'] === $viewerId,
            ];
            if (in_array($role, $mentorRoles, true)) $mentors[] = $m; else $members[] = $m;
        }
        // Online first within each group.
        $byOnline = fn($a, $b) => ($b['online'] <=> $a['online']) ?: strcmp($a['name'], $b['name']);
        usort($mentors, $byOnline); usort($members, $byOnline);
        return ['mentors' => $mentors, 'members' => $members, 'online' => $online];
    }

    /* ── Typing indicators ─────────────────────────────────────────── */
    public static function setTyping(int $uid, string $channel): void
    {
        if ($uid <= 0) return;
        self::ensure();
        $channel = self::normChannel($channel);
        $now = time();
        try {
            $db = Database::pdo();
            $n = $db->prepare('UPDATE community_typing SET updated_at = ? WHERE user_id = ? AND channel = ?');
            $n->execute([$now, $uid, $channel]);
            if ($n->rowCount() === 0) {
                try { $db->prepare('INSERT INTO community_typing (user_id, channel, updated_at) VALUES (?,?,?)')->execute([$uid, $channel, $now]); }
                catch (Throwable $e) { /* raced */ }
            }
        } catch (Throwable $e) {}
    }

    /** Names of members typing in $channel within the last few seconds (excl. viewer). */
    public static function whoTyping(int $viewerId, string $channel): array
    {
        self::ensure();
        $channel = self::normChannel($channel);
        try {
            $st = Database::pdo()->prepare(
                "SELECT u.name FROM community_typing t JOIN lms_users u ON u.id = t.user_id
                 WHERE t.channel = ? AND t.updated_at >= ? AND t.user_id <> ? ORDER BY t.updated_at DESC LIMIT 5"
            );
            $st->execute([$channel, time() - 6, $viewerId]);
            return array_map(fn($n) => (string) $n, $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) { return []; }
    }

    private static function clearTyping(int $uid, string $channel): void
    {
        try { Database::pdo()->prepare('DELETE FROM community_typing WHERE user_id = ? AND channel = ?')->execute([$uid, self::normChannel($channel)]); }
        catch (Throwable $e) {}
    }

    /* ── "Catch me up" — AI recap of recent channel activity ───────── */
    public static function chatAiAvailable(): bool
    {
        return (class_exists('AvBot') && AvBot::configured()) || (class_exists('Gemini') && Gemini::configured());
    }

    /**
     * Summarise the recent conversation in a channel into a few crisp bullets:
     * decisions, questions still open, and any action items. Returns
     * ['ok'=>bool, 'summary'=>string, 'error'=>?string]. Uses whichever AI key
     * is configured (Claude first, else Gemini).
     */
    public static function chatRecap(int $viewerId, string $channel, int $limit = 40): array
    {
        self::ensure();
        if (!self::isOrgMember($viewerId)) return ['ok' => false, 'summary' => '', 'error' => 'Members only.'];
        if (!self::chatAiAvailable()) return ['ok' => false, 'summary' => '', 'error' => 'AI is not configured (set ANTHROPIC_API_KEY or AV_GEMINI_API_KEY).'];
        $channel = self::normChannel($channel);
        $msgs = self::chatList($viewerId, 0, max(10, min(80, $limit)), $channel);
        if (count($msgs) < 2) return ['ok' => false, 'summary' => '', 'error' => 'Not enough messages to summarise yet.'];
        $transcript = '';
        foreach ($msgs as $m) {
            $transcript .= $m['author'] . ': ' . trim(mb_substr((string) $m['body'], 0, 500)) . "\n";
            if (!empty($m['reply_count'])) $transcript .= '  (' . $m['reply_count'] . ' thread replies)' . "\n";
        }
        $system = <<<SYS
You catch a busy member up on a team chat channel. Read the transcript and produce a SHORT briefing in Markdown with these sections (omit a section if it has nothing):
**TL;DR** — 1-2 sentences.
**Decisions** — bullets of what was decided.
**Open questions** — bullets of anything unresolved or awaiting someone.
**Action items** — bullets as "who — what" when an owner is clear.
Be concise and factual. Do NOT invent anything not in the transcript. No preamble.
SYS;
        $prompt = "Channel: #{$channel}\n\nTranscript (oldest first):\n" . mb_substr($transcript, 0, 11000);

        $res = null; $via = '';
        if (class_exists('AvBot') && AvBot::configured()) {
            $res = AvBot::reply($prompt, [], ['system' => $system, 'max_tokens' => 700]); $via = 'claude';
            if (empty($res['ok']) && class_exists('Gemini') && Gemini::configured()) $res = null;
        }
        if ($res === null && class_exists('Gemini') && Gemini::configured()) {
            $res = Gemini::generate($prompt, ['system' => $system, 'max_tokens' => 700, 'temperature' => 0.2]); $via = 'gemini';
        }
        if (!$res || empty($res['ok'])) return ['ok' => false, 'summary' => '', 'error' => (string) ($res['error'] ?? 'AI request failed.')];
        return ['ok' => true, 'summary' => trim((string) $res['text']), 'error' => null, 'via' => $via];
    }

    /** Data-classification levels for posts (least → most sensitive). */
    const CLASSES = ['public' => 'Public', 'members' => 'Members-only', 'confidential' => 'Confidential'];
    private static function normClass(string $c): string { $c = strtolower(trim($c)); return isset(self::CLASSES[$c]) ? $c : 'members'; }

    /** A viewer's clearance: 0 = public only, 1 = + members, 2 = + confidential. */
    public static function clearance(int $uid): int
    {
        if ($uid <= 0) return 0;
        try {
            $st = Database::pdo()->prepare('SELECT role, email FROM lms_users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u) return 0;
            $rank = class_exists('LmsAuth') ? LmsAuth::rank((string) ($u['role'] ?? '')) : 0;
            if ($rank >= 30) return 2;   // coordinator / admin
            $isOrg = $rank >= 10 || (class_exists('LmsAuth') && LmsAuth::isOrgEmail((string) ($u['email'] ?? '')));
            return $isOrg ? 1 : 0;
        } catch (Throwable $e) { return self::isOrgMember($uid) ? 1 : 0; }
    }

    /** The classification values a given clearance may see / post. */
    public static function allowedClasses(int $clearance): array
    {
        if ($clearance >= 2) return ['public', 'members', 'confidential'];
        if ($clearance >= 1) return ['public', 'members'];
        return ['public'];
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
    public static function createPost(int $authorId, string $spaceSlug, string $body, ?array $poll = null, bool $pinned = false, string $classification = 'members'): int
    {
        self::ensure();
        $body = trim($body);
        if ($body === '' || $authorId <= 0) return 0;
        $sid = self::spaceId($spaceSlug) ?: self::spaceId('open-floor');
        // Cap the chosen classification to what the author is cleared to post.
        $cls = self::normClass($classification);
        $allowed = self::allowedClasses(self::clearance($authorId));
        if (!in_array($cls, $allowed, true)) $cls = in_array('members', $allowed, true) ? 'members' : 'public';
        $db = Database::pdo();
        $db->prepare('INSERT INTO community_posts (author_id, space_id, body, poll_json, pinned, classification, created_at) VALUES (?,?,?,?,?,?,?)')
           ->execute([$authorId, $sid, mb_substr($body, 0, 5000), $poll ? json_encode($poll) : null, $pinned ? 1 : 0, $cls, gmdate('Y-m-d H:i:s')]);
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
        'p.id, p.body, p.poll_json, p.pinned, p.likes, p.reply_count, p.created_at, p.classification,
         s.slug AS space_slug, s.name AS space_name, s.color AS space_color,
         u.id AS author_id, u.name AS author, u.email AS author_email, u.role AS author_role';

    /** Top-level feed. $space = slug|null. $sort = top|latest. */
    public static function feed(?string $space, string $sort = 'latest', int $limit = 15, int $offset = 0, int $viewerId = 0): array
    {
        self::ensure();
        $db = Database::pdo();
        $where = "p.reply_to IS NULL AND p.status = 'published'";
        $args = [];
        if ($space) { $where .= ' AND s.slug = ?'; $args[] = $space; }
        // Hide posts above the viewer's clearance (public < members < confidential).
        $allowed = self::allowedClasses(self::clearance($viewerId));
        $where .= ' AND p.classification IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
        foreach ($allowed as $a) $args[] = $a;
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
        if (!$r) return null;
        // Respect classification: don't reveal a post above the viewer's clearance.
        if (!in_array(self::normClass((string) ($r['classification'] ?? 'members')), self::allowedClasses(self::clearance($viewerId)), true)) return null;
        return self::shape($r, $viewerId);
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
            'author_id' => (int) ($r['author_id'] ?? 0),
            'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
            'classification' => self::normClass((string) ($r['classification'] ?? 'members')),
            'class_label' => self::CLASSES[self::normClass((string) ($r['classification'] ?? 'members'))],
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

    /* ════════════════════════════════════════════════════════════════
       ORG-ONLY: member directory + live group chat + @mentions.

       The Community splits by audience. ORG members (@afrovanguard.org.ng)
       get the full experience — they can SEE each other (directory) and chat
       in real time with @mentions. EXTERNAL members get the forum (feed) only.
       Every method here is the data layer for an ORG-gated surface; the API
       (community/api.php) refuses these for external members BEFORE calling in,
       and these methods themselves only ever return ORG members, so a directory
       leak or a cross-segment mention is impossible even if a gate is missed.
       ════════════════════════════════════════════════════════════════ */

    /** True if this user id is an ORG member (afrovanguard.org.ng). The single
     *  server-side gate for the directory + chat. Mirrors Mentorship::segmentOf
     *  but answers the boolean the chat/directory need. Fail-safe: false. */
    public static function isOrgMember(int $uid): bool
    {
        if ($uid <= 0) return false;
        try {
            $s = Database::pdo()->prepare('SELECT email FROM lms_users WHERE id = ?');
            $s->execute([$uid]);
            $email = (string) $s->fetchColumn();
            return $email !== '' && class_exists('LmsAuth') && LmsAuth::isOrgEmail($email);
        } catch (Throwable $e) { return false; }
    }

    /** The org-domain SQL fragment (driver-portable: '%@domain'). The bot is an
     *  org-domain account but is excluded from the directory (it's not a person). */
    private static function orgEmailLike(): string
    {
        $domain = defined('AV_ORG_DOMAIN') ? strtolower((string) AV_ORG_DOMAIN) : 'afrovanguard.org.ng';
        return '%@' . $domain;
    }

    /**
     * The ORG member directory — who's in the org community. Names, an initial
     * for the avatar, and a headline if the member opted into mentorship (we
     * READ mentor_profiles.headline best-effort; never write it). The bot and
     * the viewer themselves are excluded. ORG members only — never external.
     */
    public static function directory(int $viewerId = 0, int $limit = 200): array
    {
        self::ensure();
        $like = self::orgEmailLike();
        $hasMentor = false;
        try { $hasMentor = Database::columnExists('mentor_profiles', 'headline'); } catch (Throwable $e) { $hasMentor = false; }
        $limit = max(1, min(500, $limit));
        try {
            if ($hasMentor) {
                $sql = "SELECT u.id, u.name, u.email, mp.headline AS headline
                        FROM lms_users u
                        LEFT JOIN mentor_profiles mp ON mp.user_id = u.id
                        WHERE u.status = 'active' AND LOWER(u.email) LIKE ? AND u.email <> ?
                        ORDER BY u.name ASC LIMIT $limit";
            } else {
                $sql = "SELECT u.id, u.name, u.email, '' AS headline
                        FROM lms_users u
                        WHERE u.status = 'active' AND LOWER(u.email) LIKE ? AND u.email <> ?
                        ORDER BY u.name ASC LIMIT $limit";
            }
            $st = Database::pdo()->prepare($sql);
            $st->execute([$like, self::BOT_EMAIL]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[community] directory: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $name = (string) $r['name'];
            $out[] = [
                'id'       => (int) $r['id'],
                'name'     => $name,
                'initial'  => mb_strtoupper(mb_substr($name, 0, 1)),
                'headline' => mb_substr(trim((string) ($r['headline'] ?? '')), 0, 160),
                'is_me'    => (int) $r['id'] === $viewerId,
            ];
        }
        return $out;
    }

    /** Autocomplete source for @mentions: ORG members matching a name/email
     *  query. ORG-only, bot excluded. Returns a small, lightweight list. */
    public static function mentionSearch(string $q, int $viewerId = 0, int $limit = 8): array
    {
        self::ensure();
        $q = trim($q);
        $like = self::orgEmailLike();
        $limit = max(1, min(20, $limit));
        try {
            if ($q === '') {
                $sql = "SELECT id, name, email FROM lms_users
                        WHERE status = 'active' AND LOWER(email) LIKE ? AND email <> ?
                        ORDER BY name ASC LIMIT $limit";
                $st = Database::pdo()->prepare($sql);
                $st->execute([$like, self::BOT_EMAIL]);
            } else {
                $term = '%' . $q . '%';
                $sql = "SELECT id, name, email FROM lms_users
                        WHERE status = 'active' AND LOWER(email) LIKE ? AND email <> ?
                          AND (name LIKE ? OR email LIKE ?)
                        ORDER BY (name LIKE ?) DESC, name ASC LIMIT $limit";
                $st = Database::pdo()->prepare($sql);
                $st->execute([$like, self::BOT_EMAIL, $term, $term, $q . '%']);
            }
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[community] mentionSearch: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $name = (string) $r['name'];
            $out[] = [
                'id'      => (int) $r['id'],
                'name'    => $name,
                'handle'  => self::handleFor($name),
                'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
                'is_me'   => (int) $r['id'] === $viewerId,
            ];
        }
        return $out;
    }

    /** A stable @handle for a member name (used in autocomplete + mention text):
     *  lowercase, spaces→dots, ascii-ish. Display only; resolution is by id. */
    private static function handleFor(string $name): string
    {
        $h = strtolower(trim($name));
        $h = preg_replace('/[^a-z0-9]+/', '.', $h) ?? $h;
        return trim($h, '.') ?: 'member';
    }

    /**
     * Send a chat message to the org group channel. ORG-only (caller must gate;
     * we re-check here too). Parses @mentions against ORG members, stores the
     * raw body, and notifies each mentioned member best-effort. Returns the
     * shaped message (with resolved mentions) or null on failure — never throws.
     */
    public static function chatSend(int $authorId, string $body, string $channel = 'general', int $parentId = 0): ?array
    {
        self::ensure();
        $body = trim($body);
        if ($body === '' || $authorId <= 0) return null;
        if (!self::isOrgMember($authorId)) return null; // server-side gate (defence-in-depth)
        $body = mb_substr($body, 0, 2000);
        $channel = self::normChannel($channel);
        // A reply must point at a real top-level message; it inherits its channel.
        $parentId = max(0, $parentId);
        if ($parentId > 0) {
            try {
                $p = Database::pdo()->prepare('SELECT channel, parent_id FROM community_chat WHERE id = ?');
                $p->execute([$parentId]);
                $pr = $p->fetch(PDO::FETCH_ASSOC);
                if (!$pr || (int) $pr['parent_id'] !== 0) { $parentId = 0; }   // ignore replies-to-replies / missing
                else { $channel = self::normChannel((string) $pr['channel']); }
            } catch (Throwable $e) { $parentId = 0; }
        }
        try {
            $db = Database::pdo();
            $db->prepare('INSERT INTO community_chat (author_id, body, channel, parent_id, created_at) VALUES (?,?,?,?,?)')
               ->execute([$authorId, $body, $channel, $parentId, gmdate('Y-m-d H:i:s')]);
            $id = (int) $db->lastInsertId();
        } catch (Throwable $e) {
            error_log('[community] chatSend: ' . $e->getMessage());
            return null;
        }
        self::clearTyping($authorId, $channel);   // stop showing "X is typing" once sent
        // Resolve + notify mentions (best-effort; failure never blocks the send).
        try {
            $mentions = self::resolveMentions($body, $authorId);
            if ($mentions) self::notifyMentions($mentions, $authorId, $body, $id);
        } catch (Throwable $e) { error_log('[community] mention notify: ' . $e->getMessage()); }
        if (class_exists('Events')) {
            try { Events::emit('community.chat', ['id' => $id, 'author_id' => $authorId, 'excerpt' => mb_substr($body, 0, 180)]); } catch (Throwable $e) {}
        }
        $m = self::chatOne($id, $authorId);
        return $m;
    }

    /**
     * Poll the chat channel. ORG-only (caller gates). $sinceId returns only
     * messages with id > sinceId (ascending) for cheap incremental polling;
     * $sinceId = 0 returns the most recent page (ascending). Fail-safe: []. */
    public static function chatList(int $viewerId, int $sinceId = 0, int $limit = 50, string $channel = 'general'): array
    {
        self::ensure();
        if (!self::isOrgMember($viewerId)) return [];
        $limit = max(1, min(100, $limit));
        $channel = self::normChannel($channel);
        try {
            $db = Database::pdo();
            if ($sinceId > 0) {
                $st = $db->prepare(self::CHAT_SELECT . ' WHERE c.channel = ? AND c.parent_id = 0 AND c.id > ? ORDER BY c.id ASC LIMIT ' . $limit);
                $st->execute([$channel, $sinceId]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                // Most recent $limit top-level messages, ascending (oldest→newest) so the UI appends.
                $st = $db->prepare(self::CHAT_SELECT . ' WHERE c.channel = ? AND c.parent_id = 0 ORDER BY c.id DESC LIMIT ' . $limit);
                $st->execute([$channel]);
                $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
            }
        } catch (Throwable $e) {
            error_log('[community] chatList: ' . $e->getMessage());
            return [];
        }
        $msgs = self::attachReactions(array_map(fn($r) => self::shapeChat($r, $viewerId), $rows), $viewerId);
        return self::attachThreadMeta($msgs);
    }

    /** A thread: replies to $parentId (ascending). $sinceId for cheap live polling. */
    public static function chatThread(int $viewerId, int $parentId, int $sinceId = 0, int $limit = 100): array
    {
        self::ensure();
        if (!self::isOrgMember($viewerId) || $parentId <= 0) return [];
        $limit = max(1, min(200, $limit));
        try {
            $st = Database::pdo()->prepare(self::CHAT_SELECT . ' WHERE c.parent_id = ? AND c.id > ? ORDER BY c.id ASC LIMIT ' . $limit);
            $st->execute([$parentId, max(0, $sinceId)]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[community] chatThread: ' . $e->getMessage()); return []; }
        return self::attachReactions(array_map(fn($r) => self::shapeChat($r, $viewerId), $rows), $viewerId);
    }

    /** Attach reply_count + last reply time to a page of top-level messages. */
    private static function attachThreadMeta(array $msgs): array
    {
        if (!$msgs) return $msgs;
        $ids = array_map(fn($m) => (int) $m['id'], $msgs);
        $place = implode(',', array_fill(0, count($ids), '?'));
        $meta = [];
        try {
            $st = Database::pdo()->prepare('SELECT parent_id, COUNT(*) AS n, MAX(created_at) AS last FROM community_chat WHERE parent_id IN (' . $place . ') GROUP BY parent_id');
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $meta[(int) $r['parent_id']] = ['n' => (int) $r['n'], 'last' => (string) $r['last']];
        } catch (Throwable $e) {}
        foreach ($msgs as &$m) {
            $mm = $meta[(int) $m['id']] ?? null;
            $m['reply_count'] = $mm ? $mm['n'] : 0;
            $m['last_reply']  = $mm ? self::ago($mm['last']) : '';
        }
        unset($m);
        return $msgs;
    }

    private const CHAT_SELECT =
        'SELECT c.id, c.body, c.author_id, c.created_at, c.parent_id, u.name AS author, u.email AS author_email
         FROM community_chat c JOIN lms_users u ON u.id = c.author_id';

    private static function chatOne(int $id, int $viewerId): ?array
    {
        try {
            $st = Database::pdo()->prepare(self::CHAT_SELECT . ' WHERE c.id = ?');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $m = self::shapeChat($r, $viewerId);
            $rx = self::reactionsFor([(int) $m['id']], $viewerId);
            $m['reactions'] = $rx[(int) $m['id']] ?? [];
            return $m;
        } catch (Throwable $e) { return null; }
    }

    /** Normalise a chat row → view model, resolving @mentions to chips. */
    private static function shapeChat(array $r, int $viewerId): array
    {
        $name  = (string) $r['author'];
        $email = (string) $r['author_email'];
        $org   = class_exists('LmsAuth') && LmsAuth::isOrgEmail($email);
        $body  = (string) $r['body'];
        return [
            'id'         => (int) $r['id'],
            'author_id'  => (int) $r['author_id'],
            'author'     => $name,
            'initial'    => mb_strtoupper(mb_substr($name, 0, 1)),
            'body'       => $body,
            'mentions'   => self::resolveMentions($body, 0),
            'verified'   => $org,
            'is_me'      => (int) $r['author_id'] === $viewerId,
            'created_at' => (string) $r['created_at'],
            'ago'        => self::ago((string) $r['created_at']),
            'reactions'  => [],
            'parent_id'  => (int) ($r['parent_id'] ?? 0),
            'reply_count'=> 0,
            'last_reply' => '',
        ];
    }

    /** Emoji allowed as reactions (a curated Slack-style quick set). */
    const REACT_EMOJI = ['👍', '❤️', '🎉', '🙌', '🔥', '✅', '👀', '😂'];

    /**
     * Toggle an emoji reaction on a chat message (org-only). Returns the updated
     * reaction list for that message: [{emoji,count,mine}], or null on failure.
     */
    public static function chatReact(int $uid, int $chatId, string $emoji): ?array
    {
        self::ensure();
        if ($uid <= 0 || $chatId <= 0 || !self::isOrgMember($uid)) return null;
        if (!in_array($emoji, self::REACT_EMOJI, true)) return null;
        try {
            $db = Database::pdo();
            $has = $db->prepare('SELECT 1 FROM community_chat_reactions WHERE chat_id = ? AND user_id = ? AND emoji = ?');
            $has->execute([$chatId, $uid, $emoji]);
            if ($has->fetchColumn()) {
                $db->prepare('DELETE FROM community_chat_reactions WHERE chat_id = ? AND user_id = ? AND emoji = ?')->execute([$chatId, $uid, $emoji]);
            } else {
                $db->prepare('INSERT INTO community_chat_reactions (chat_id, user_id, emoji, created_at) VALUES (?,?,?,?)')
                   ->execute([$chatId, $uid, $emoji, gmdate('Y-m-d H:i:s')]);
            }
        } catch (Throwable $e) { error_log('[community] chatReact: ' . $e->getMessage()); return null; }
        $out = self::reactionsFor([$chatId], $uid);
        return $out[$chatId] ?? [];
    }

    /** Batch-load reactions for a set of message ids → [chatId => [{emoji,count,mine}]]. */
    private static function reactionsFor(array $chatIds, int $viewerId): array
    {
        $chatIds = array_values(array_unique(array_map('intval', $chatIds)));
        if (!$chatIds) return [];
        $place = implode(',', array_fill(0, count($chatIds), '?'));
        try {
            $st = Database::pdo()->prepare(
                'SELECT chat_id, emoji, COUNT(*) AS n, MAX(CASE WHEN user_id = ? THEN 1 ELSE 0 END) AS mine
                 FROM community_chat_reactions WHERE chat_id IN (' . $place . ') GROUP BY chat_id, emoji ORDER BY n DESC, emoji ASC'
            );
            $st->execute(array_merge([$viewerId], $chatIds));
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cid = (int) $row['chat_id'];
                $out[$cid][] = ['emoji' => (string) $row['emoji'], 'count' => (int) $row['n'], 'mine' => (int) $row['mine'] === 1];
            }
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /** Attach reactions to a page of shaped messages (in place). */
    private static function attachReactions(array $msgs, int $viewerId): array
    {
        if (!$msgs) return $msgs;
        $ids = array_map(fn($m) => (int) $m['id'], $msgs);
        $byId = self::reactionsFor($ids, $viewerId);
        foreach ($msgs as &$m) { $m['reactions'] = $byId[(int) $m['id']] ?? []; }
        unset($m);
        return $msgs;
    }

    /**
     * Parse @mentions out of a body and resolve them to ORG members. Matches
     * `@email@domain` (an org email) or `@handle` / `@first.last` tokens against
     * org member names/handles. Returns a de-duplicated list of resolved
     * members: [['id','name','handle','token']]. ORG members only — a token
     * that resolves to an external account or a stranger is dropped (never a
     * cross-segment leak). $excludeId drops self-mentions from notifications.
     */
    public static function resolveMentions(string $body, int $excludeId = 0): array
    {
        if (strpos($body, '@') === false) return [];
        // Token charset: letters, digits, dot, underscore, hyphen, and @ (for emails).
        if (!preg_match_all('/@([A-Za-z0-9._@\-]+)/u', $body, $m)) return [];
        $tokens = array_values(array_unique($m[1]));
        if (!$tokens) return [];
        $like = self::orgEmailLike();
        $out = [];
        $seen = [];
        foreach ($tokens as $tok) {
            $tok = rtrim($tok, '.-_'); // trailing punctuation isn't part of the handle
            if ($tok === '') continue;
            try {
                $member = null;
                if (strpos($tok, '@') !== false && filter_var($tok, FILTER_VALIDATE_EMAIL)) {
                    // @someone@afrovanguard.org.ng — exact org email.
                    if (class_exists('LmsAuth') && LmsAuth::isOrgEmail($tok)) {
                        $s = Database::pdo()->prepare("SELECT id, name, email FROM lms_users WHERE LOWER(email) = LOWER(?) AND email <> ? AND status='active'");
                        $s->execute([$tok, self::BOT_EMAIL]);
                        $member = $s->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
                } else {
                    // @handle — match a derived handle (first.last) or a name prefix,
                    // but ONLY among org members (the LIKE on email enforces it).
                    $needle = str_replace(['.', '_', '-'], ' ', $tok);
                    $s = Database::pdo()->prepare(
                        "SELECT id, name, email FROM lms_users
                         WHERE status='active' AND LOWER(email) LIKE ? AND email <> ?
                           AND (REPLACE(REPLACE(LOWER(name),' ','.'),'''','') = LOWER(?) OR LOWER(name) = LOWER(?))
                         ORDER BY name ASC LIMIT 1");
                    $s->execute([$like, self::BOT_EMAIL, $tok, $needle]);
                    $member = $s->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            } catch (Throwable $e) { $member = null; }
            if (!$member) continue;
            $id = (int) $member['id'];
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $out[] = [
                'id'     => $id,
                'name'   => (string) $member['name'],
                'handle' => self::handleFor((string) $member['name']),
                'token'  => $tok,
            ];
        }
        return $out;
    }

    /** Email each mentioned member (best-effort, reusing Mailer like Mentorship). */
    private static function notifyMentions(array $mentions, int $authorId, string $body, int $msgId): void
    {
        // In-app push always fires below; email only when a transport is configured.
        $canEmail = class_exists('Mailer') && Mailer::configured();
        try {
            $a = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?'); $a->execute([$authorId]);
            $authorName = (string) ($a->fetchColumn() ?: 'A member');
        } catch (Throwable $e) { $authorName = 'A member'; }
        $site = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : 'https://afrovanguard.org.ng';
        $url  = $site . '/portal/#chat';
        $excerpt = mb_substr(trim($body), 0, 240);
        foreach ($mentions as $mn) {
            if ((int) $mn['id'] === $authorId) continue; // no self-notify
            // In-app notification (the bell), so a tag lands even without email.
            if (class_exists('Notifications')) {
                try { Notifications::push((int) $mn['id'], 'mention', $authorName . ' mentioned you', $excerpt, '/portal/#chat', 'chatmention:' . $msgId . ':' . (int) $mn['id']); }
                catch (Throwable $e) {}
            }
            if (!$canEmail) continue;
            try {
                $s = Database::pdo()->prepare('SELECT name, email FROM lms_users WHERE id = ?');
                $s->execute([(int) $mn['id']]);
                $u = $s->fetch(PDO::FETCH_ASSOC);
                if (!$u || empty($u['email'])) continue;
                if (class_exists('Mailer') && method_exists('Mailer', 'shell')) {
                    $html = Mailer::shell(
                        'You were mentioned in the Community',
                        [
                            'Hi ' . htmlspecialchars((string) $u['name'], ENT_QUOTES) . ',',
                            htmlspecialchars($authorName, ENT_QUOTES) . ' mentioned you in the Afrovanguard community chat:',
                            '“' . htmlspecialchars($excerpt, ENT_QUOTES) . '”',
                        ],
                        ['url' => $url, 'text' => 'Open the chat'],
                        $authorName . ' mentioned you in the Community chat.'
                    );
                    Mailer::send((string) $u['email'], 'You were mentioned — Afrovanguard Community', $html);
                }
            } catch (Throwable $e) { error_log('[community] mention mail: ' . $e->getMessage()); }
        }
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

    /** Coordinators/admins (clearance ≥ 2) are the community moderators. */
    public static function isAdmin(int $uid): bool { return self::clearance($uid) >= 2; }

    private static function authorOf(int $postId): int
    {
        $st = Database::pdo()->prepare('SELECT author_id FROM community_posts WHERE id = ?');
        $st->execute([$postId]);
        $a = $st->fetchColumn();
        return $a === false ? 0 : (int) $a;
    }

    /** Remove a post (soft-delete). Admin, or the post's own author. */
    public static function moderateDelete(int $actorUid, int $postId): bool
    {
        self::ensure();
        if ($actorUid <= 0 || $postId <= 0) return false;
        if (!self::isAdmin($actorUid) && self::authorOf($postId) !== $actorUid) return false;
        self::setStatus($postId, 'removed');
        return true;
    }

    /** Pin / unpin any post. Admin only. */
    public static function moderatePin(int $actorUid, int $postId, bool $pin): bool
    {
        self::ensure();
        if (!self::isAdmin($actorUid) || $postId <= 0) return false;
        self::setPinned($postId, $pin);
        return true;
    }

    /** Change a post's data classification. Admin only. */
    public static function moderateClassify(int $actorUid, int $postId, string $classification): bool
    {
        self::ensure();
        if (!self::isAdmin($actorUid) || $postId <= 0) return false;
        $cls = self::normClass($classification);
        $st = Database::pdo()->prepare('UPDATE community_posts SET classification = ? WHERE id = ?');
        $st->execute([$cls, $postId]);
        return $st->rowCount() > 0;
    }

    /**
     * Publish an official Afrovanguard announcement (posted as the bot voice),
     * optionally pinned. Admin only — this is the "official / AI" management voice.
     */
    public static function announce(int $actorUid, string $spaceSlug, string $body, bool $pin = false): int
    {
        self::ensure();
        if (!self::isAdmin($actorUid)) return 0;
        $body = trim($body);
        if ($body === '') return 0;
        return self::botPost($spaceSlug ?: 'announcements', $body, $pin);
    }
}
