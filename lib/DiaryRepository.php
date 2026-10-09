<?php
/**
 * lib/DiaryRepository.php — every Diary query in one place.
 *
 * Pages and the API talk to the database only through this repository,
 * so reads/writes stay consistent and the data layer is swappable.
 */
declare(strict_types=1);

final class DiaryRepository
{
    private PDO $db;
    public function __construct(?PDO $pdo = null) { $this->db = $pdo ?? Database::pdo(); }

    private const CARD_COLS =
        'a.slug, a.title, a.dek, a.authors_html, a.published, a.published_at,
         a.read_minutes, a.gradient, a.mc_title, a.cover_url, c.name AS category, c.slug AS category_slug,
         a.featured, a.status';

    private bool $colsReady = false;
    private ?array $articleColSet = null;

    /**
     * Make sure the columns the card queries name actually exist.
     *
     * `cover_url`, `og_image`, `audio_url`, `format`, the series pair and
     * `cover_is_dark` are all added by `Database`'s articles migration step — and
     * that step is version-stamped, so a database whose stamp already matched never
     * ran it. Every Diary query then dies on `no such column: a.cover_url`: the
     * Studio list, the editor's save, and the public /diary/ page alike.
     *
     * Same failure the Academy had, same treatment: repair it when it is found
     * wanting rather than trusting that a migration ran.
     */
    private function ensureArticleCols(): void
    {
        if ($this->colsReady) return; $this->colsReady = true;
        try {
            if (Database::columnExists('articles', 'cover_url')) return;
            Database::ensureArticleSchema();
            $this->articleColSet = null;                 // it may have just changed
        } catch (Throwable $e) { error_log('[diary] schema heal: ' . $e->getMessage()); }
    }

    /** The `articles` columns this database actually has, as a name => true set. */
    private function articleCols(): array
    {
        $this->ensureArticleCols();
        if ($this->articleColSet !== null) return $this->articleColSet;
        $set = [];
        try {
            foreach (['id', 'slug', 'ref_code', 'title', 'dek', 'category_id', 'authors_html', 'published',
                      'published_at', 'read_minutes', 'gradient', 'mc_session', 'mc_title', 'mc_tag',
                      'cover_url', 'og_image', 'audio_url', 'body_html', 'base_claps', 'featured', 'status',
                      'format', 'series_id', 'series_part', 'cover_is_dark', 'created_at', 'updated_at'] as $c) {
                if (Database::columnExists('articles', $c)) $set[$c] = true;
            }
        } catch (Throwable $e) { error_log('[diary] column probe: ' . $e->getMessage()); }
        return $this->articleColSet = $set;
    }

    /**
     * The card column list, minus anything this database does not have.
     *
     * If the heal above could not run — no ALTER privilege on shared hosting, say —
     * the Diary still renders with the columns that are there rather than returning
     * a 500 for every read, public pages included.
     */
    private function cardCols(): string
    {
        $have = $this->articleCols();
        $keep = [];
        foreach (array_map('trim', explode(',', preg_replace('/\s+/', ' ', self::CARD_COLS) ?? '')) as $c) {
            // Only the `a.<column>` entries are conditional; the joined category
            // aliases always exist because they come from `categories`.
            if (preg_match('/^a\.([a-z_]+)$/', $c, $m)) { if (isset($have[$m[1]])) $keep[] = $c; }
            else $keep[] = $c;
        }
        if (isset($have['ref_code']) && $this->refCodesEnabled()) $keep[] = 'a.ref_code';
        return implode(', ', $keep);
    }

    /** Drop any field whose column is absent, so a write cannot name one. */
    private function onlyExistingArticleCols(array $fields): array
    {
        return array_intersect_key($fields, $this->articleCols());
    }

    /** All published entries, newest first (lightweight card fields). */
    public function all(): array
    {
        return $this->db->query(
            'SELECT ' . $this->cardCols() . '
             FROM articles a JOIN categories c ON c.id = a.category_id
             WHERE a.status = \'published\'
             ORDER BY a.published_at DESC, a.id DESC'
        )->fetchAll();
    }

    /** All published articles WITH full body, for export/Journal. ASC = journal order. */
    public function allForExport(string $order = 'ASC'): array
    {
        $dir = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        return $this->db->query(
            "SELECT a.slug, a.title, a.dek, a.authors_html, a.published, a.published_at,
                    a.read_minutes, a.body_html, c.name AS category
             FROM articles a JOIN categories c ON c.id = a.category_id
             WHERE a.status = 'published'
             ORDER BY a.published_at $dir, a.id $dir"
        )->fetchAll();
    }

    /** Every entry incl. drafts (admin only). */
    public function allForAdmin(): array
    {
        return $this->db->query(
            'SELECT ' . $this->cardCols() . ', a.updated_at
             FROM articles a JOIN categories c ON c.id = a.category_id
             ORDER BY a.updated_at DESC, a.id DESC'
        )->fetchAll();
    }

    /** The single featured published dispatch (falls back to newest). */
    public function featured(): ?array
    {
        $row = $this->db->query(
            'SELECT ' . $this->cardCols() . '
             FROM articles a JOIN categories c ON c.id = a.category_id
             WHERE a.featured = 1 AND a.status = \'published\' ORDER BY a.published_at DESC LIMIT 1'
        )->fetch();
        return $row ?: ($this->all()[0] ?? null);
    }

    /** Distinct categories present in the diary, in publishing order. */
    public function categories(): array
    {
        return $this->db->query(
            'SELECT DISTINCT c.slug, c.name FROM categories c
             JOIN articles a ON a.category_id = c.id ORDER BY c.name'
        )->fetchAll();
    }

    /** Full article (with category) + sections + live clap total. */
    public function bySlug(string $slug, bool $includeDrafts = false): ?array
    {
        $sql = 'SELECT a.*, c.name AS category, c.slug AS category_slug
                FROM articles a JOIN categories c ON c.id = a.category_id
                WHERE a.slug = ?';
        if (!$includeDrafts) $sql .= " AND a.status = 'published'";
        $st = $this->db->prepare($sql);
        $st->execute([$slug]);
        $a = $st->fetch();
        if (!$a) return null;

        $sec = $this->db->prepare('SELECT anchor, label FROM sections WHERE article_id = ? ORDER BY position');
        $sec->execute([$a['id']]);
        $a['sections'] = $sec->fetchAll();

        $a['claps'] = $this->claps($slug);
        $a['series'] = $this->seriesContext($a);
        return $a;
    }

    /** Card data for an article's "More from the Diary" list. */
    public function relatedCards(int $articleId): array
    {
        $st = $this->db->prepare(
            'SELECT ' . $this->cardCols() . '
             FROM related r
             JOIN articles a ON a.slug = r.related_slug
             JOIN categories c ON c.id = a.category_id
             WHERE r.article_id = ? AND a.status = \'published\' ORDER BY r.position'
        );
        $st->execute([$articleId]);
        $rows = $st->fetchAll();
        // Fall back to recent published entries if no explicit relations resolve.
        if (count($rows) < 3) {
            $have = array_column($rows, 'slug');
            foreach ($this->all() as $a) {
                if (count($rows) >= 3) break;
                if (in_array($a['slug'], $have, true)) continue;
                $self = $this->db->prepare('SELECT slug FROM articles WHERE id = ?');
                $self->execute([$articleId]);
                if ($a['slug'] === $self->fetchColumn()) continue;
                $rows[] = $a;
            }
        }
        return $rows;
    }

    /** Full row incl. drafts + sections + related slugs (for the editor). */
    public function getRaw(string $slug): ?array
    {
        $a = $this->bySlug($slug, true);
        if (!$a) return null;
        $rel = $this->db->prepare('SELECT related_slug FROM related WHERE article_id = ? ORDER BY position');
        $rel->execute([$a['id']]);
        $a['related'] = array_column($rel->fetchAll(), 'related_slug');
        // Editor convenience: expose the series title + part at the top level.
        $a['series_title'] = $a['series']['title'] ?? '';
        $a['series_part'] = (int) ($a['series_part'] ?? 0);
        return $a;
    }

    private function categoryId(string $name): int
    {
        $slug = slugify($name);
        $this->db->prepare(Database::insertIgnore('categories', ['slug', 'name']))->execute([$slug, $name]);
        $f = $this->db->prepare('SELECT id FROM categories WHERE slug = ?');
        $f->execute([$slug]);
        return (int) $f->fetchColumn();
    }

    /* ── Series: group posts into an ordered, numbered series ─────────────── */
    private bool $seriesReady = false;
    private bool $engagementReady = false;

    /* ── Engagement: views, comments, likes, saves ─────────────────────────
       The same DDL as db/schema.sql, kept here so an installation that was
       migrated before this feature existed repairs itself on first use rather
       than dying on `no such column: parent_id`. syncTablesFromDdl() creates
       what is missing and ADDS missing columns to what is already there, which
       is what upgrades the v1 diary_comments table in place: the comments
       already published keep their rows, their text and their status. */
    private const ENGAGEMENT_DDL = <<<'SQL'
CREATE TABLE IF NOT EXISTS diary_views (
  article_id INTEGER NOT NULL,
  day        VARCHAR(10) NOT NULL,
  count      INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (article_id, day)
);
CREATE TABLE IF NOT EXISTS diary_comments (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  article_id INTEGER NOT NULL DEFAULT 0,
  parent_id  INTEGER,
  user_id    INTEGER NOT NULL DEFAULT 0,
  name       VARCHAR(120) NOT NULL DEFAULT '',
  email      TEXT NOT NULL DEFAULT '',
  body       TEXT NOT NULL DEFAULT '',
  status     VARCHAR(16) NOT NULL DEFAULT 'pending',
  likes      INTEGER NOT NULL DEFAULT 0,
  reports    INTEGER NOT NULL DEFAULT 0,
  ip_hash    VARCHAR(64) NOT NULL DEFAULT '',
  owner_hash VARCHAR(64) NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS diary_comment_likes (
  comment_id INTEGER NOT NULL,
  voter_hash VARCHAR(64) NOT NULL,
  PRIMARY KEY (comment_id, voter_hash)
);
CREATE TABLE IF NOT EXISTS diary_saves (
  user_id      INTEGER NOT NULL DEFAULT 0,
  article_id   INTEGER NOT NULL,
  visitor_hash VARCHAR(64) NOT NULL DEFAULT '',
  created_at   TEXT NOT NULL DEFAULT '',
  PRIMARY KEY (user_id, article_id, visitor_hash)
);
SQL;

    private function ensureEngagement(): void
    {
        if ($this->engagementReady) return; $this->engagementReady = true;
        try {
            Database::syncTablesFromDdl($this->db, self::ENGAGEMENT_DDL, Database::driver(), 'diary');
            Database::ensureIndex($this->db, 'idx_diary_views_article',    'diary_views',    'article_id');
            Database::ensureIndex($this->db, 'idx_diary_comments_article', 'diary_comments', 'article_id, status');
            Database::ensureIndex($this->db, 'idx_diary_comments_parent',  'diary_comments', 'parent_id');
            Database::ensureIndex($this->db, 'idx_diary_saves_article',    'diary_saves',    'article_id');
        } catch (Throwable $e) { error_log('[diary] ensureEngagement: ' . $e->getMessage()); }
    }

    /* ── Counts ────────────────────────────────────────────────────────────
       Returned together because they are always shown together, and null when
       they cannot be read at all. Null is not zero: the card renders nothing
       rather than telling a reader that an entry hundreds of people have read
       has never been read. */

    /** ['views','comments','claps'] for one entry, or null if unavailable. */
    public function counts(int $articleId): ?array
    {
        $all = $this->countsFor([$articleId]);
        return $all[$articleId] ?? null;
    }

    /**
     * The same, for a page of cards — three queries for the whole page rather
     * than three per card, so a listing of 48 entries is not 144 round trips.
     */
    public function countsFor(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $this->ensureEngagement();
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        try {
            // The row set starts from ARTICLES, so an id that names no entry
            // comes back absent rather than as three zeroes — which would read
            // on a card as a real entry nobody has ever opened.
            $st = $this->db->prepare("SELECT a.id, a.base_claps + COALESCE(r.claps, 0) n
                                        FROM articles a LEFT JOIN reactions r ON r.article_id = a.id
                                       WHERE a.id IN ($in)");
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) $out[(int) $r['id']] = ['views' => 0, 'comments' => 0, 'claps' => (int) $r['n']];
            if (!$out) return [];

            $st = $this->db->prepare("SELECT article_id, COALESCE(SUM(count),0) n FROM diary_views WHERE article_id IN ($in) GROUP BY article_id");
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) if (isset($out[(int) $r['article_id']])) $out[(int) $r['article_id']]['views'] = (int) $r['n'];

            $st = $this->db->prepare("SELECT article_id, COUNT(*) n FROM diary_comments WHERE article_id IN ($in) AND status = 'published' GROUP BY article_id");
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) if (isset($out[(int) $r['article_id']])) $out[(int) $r['article_id']]['comments'] = (int) $r['n'];
        } catch (Throwable $e) { error_log('[diary] countsFor: ' . $e->getMessage()); return []; }
        return $out;
    }

    /** slug → row id, for the one page of cards being rendered. */
    public function idsForSlugs(array $slugs): array
    {
        $slugs = array_values(array_unique(array_filter(array_map('strval', $slugs))));
        if (!$slugs) return [];
        try {
            $in = implode(',', array_fill(0, count($slugs), '?'));
            $st = $this->db->prepare("SELECT id, slug FROM articles WHERE slug IN ($in)");
            $st->execute($slugs);
            $out = [];
            foreach ($st->fetchAll() as $r) $out[(string) $r['slug']] = (int) $r['id'];
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /* ── Views ─────────────────────────────────────────────────────────────
       One view per reader per entry per 30 minutes. A reader who opens an
       entry, follows a link and comes back has read it once; a reader who
       returns the next morning has read it twice, which is true.

       Robots and admins never count. An editor refreshing their own draft is
       the single easiest way to make a view counter meaningless. */
    public const VIEW_WINDOW = 1800;

    /** Count a view. Returns true when one was actually recorded. */
    public function recordView(int $articleId): bool
    {
        if ($articleId <= 0) return false;
        if (DiaryVisitor::isBot() || DiaryVisitor::isAdmin()) return false;

        $seen = DiaryVisitor::map('dv');
        $key  = (string) $articleId;
        $now  = time();
        if (isset($seen[$key]) && ($now - (int) $seen[$key]) < self::VIEW_WINDOW) return false;
        DiaryVisitor::mapSet('dv', $key, $now);

        $this->ensureEngagement();
        $day = gmdate('Y-m-d');
        $now = Database::nowExpr();
        switch (Database::driver()) {
            case 'mysql':
                $sql = 'INSERT INTO diary_views (article_id, day, count) VALUES (?, ?, 1)
                        ON DUPLICATE KEY UPDATE count = count + 1';
                break;
            case 'pgsql':
                $sql = 'INSERT INTO diary_views (article_id, day, count) VALUES (?, ?, 1)
                        ON CONFLICT(article_id, day) DO UPDATE SET count = diary_views.count + 1';
                break;
            default:
                $sql = 'INSERT INTO diary_views (article_id, day, count) VALUES (?, ?, 1)
                        ON CONFLICT(article_id, day) DO UPDATE SET count = count + 1';
        }
        unset($now);
        try { $this->db->prepare($sql)->execute([$articleId, $day]); }
        catch (Throwable $e) { error_log('[diary] recordView: ' . $e->getMessage()); return false; }
        return true;
    }

    /* ── Applause ──────────────────────────────────────────────────────────
       Fifty per reader per entry, counted server-side. The browser caps at
       fifty too, but a cap that only the browser enforces is not a cap. */
    public const CLAP_CAP = 50;

    /** One clap. Returns the new total, or null once this reader has given 50. */
    public function clapOnce(string $slug): ?int
    {
        $a = $this->bySlug($slug);
        if (!$a) return null;
        $key  = (string) (int) $a['id'];
        $mine = (int) (DiaryVisitor::map('dk')[$key] ?? 0);
        if ($mine >= self::CLAP_CAP) return null;
        DiaryVisitor::mapSet('dk', $key, $mine + 1);
        return $this->addClaps($slug, 1);
    }

    /** How many claps this reader has already given this entry. */
    public function myClaps(int $articleId): int
    {
        return (int) (DiaryVisitor::map('dk')[(string) $articleId] ?? 0);
    }

    /* ── Saves ─────────────────────────────────────────────────────────────
       Keyed to the account when there is one and to the reader's cookie when
       there is not, so Save works for a reader who has not signed in instead
       of appearing to work and losing the entry on the next page. */

    public function isSaved(int $articleId, int $userId = 0): bool
    {
        $this->ensureEngagement();
        try {
            $st = $this->db->prepare('SELECT 1 FROM diary_saves WHERE article_id = ? AND ' . ($userId > 0 ? 'user_id = ?' : 'visitor_hash = ?') . ' LIMIT 1');
            $st->execute([$articleId, $userId > 0 ? $userId : DiaryVisitor::hash('save')]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    public function addSave(int $articleId, int $userId = 0): bool
    {
        if ($articleId <= 0) return false;
        $this->ensureEngagement();
        try {
            $sql = Database::insertIgnore('diary_saves', ['user_id', 'article_id', 'visitor_hash', 'created_at']);
            $this->db->prepare($sql)->execute([$userId, $articleId, $userId > 0 ? '' : DiaryVisitor::hash('save'), gmdate('Y-m-d H:i:s')]);
            return true;
        } catch (Throwable $e) { error_log('[diary] addSave: ' . $e->getMessage()); return false; }
    }

    public function removeSave(int $articleId, int $userId = 0): bool
    {
        $this->ensureEngagement();
        try {
            $st = $this->db->prepare('DELETE FROM diary_saves WHERE article_id = ? AND ' . ($userId > 0 ? 'user_id = ?' : 'visitor_hash = ?'));
            $st->execute([$articleId, $userId > 0 ? $userId : DiaryVisitor::hash('save')]);
            return true;
        } catch (Throwable $e) { return false; }
    }

    /* ── Comments ──────────────────────────────────────────────────────────
       New comments arrive `pending` and are shown to nobody but the person who
       wrote them, under "Only you can see this until it's reviewed." That is
       the whole moderation model: a comment is never published by the act of
       posting it, so the page can never be used to publish something the team
       has not read.

       One level of replies. parent_id is resolved to the TOP-LEVEL ancestor
       before it is stored, so a reply to a reply becomes a reply to the thread
       rather than a third level the renderer cannot draw. */

    public function commentCount(int $articleId): int
    {
        $this->ensureEngagement();
        try {
            $st = $this->db->prepare("SELECT COUNT(*) FROM diary_comments WHERE article_id = ? AND status = 'published'");
            $st->execute([$articleId]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /**
     * The thread, ready to render: top-level comments with their replies, in
     * the shape diary/partials.php draws.
     *
     * `$all` false returns the first three top-level comments (every reply to
     * them comes with them — a conversation cut off mid-answer reads as if the
     * reply were deleted).
     */
    public function commentTree(int $articleId, string $sort = 'top', bool $all = false): array
    {
        $this->ensureEngagement();
        $owner = DiaryVisitor::hash('comment');
        $order = $sort === 'new' ? 'c.id DESC' : 'c.likes DESC, c.id DESC';
        try {
            $st = $this->db->prepare(
                "SELECT c.id, c.parent_id, c.user_id, c.name, c.body, c.status, c.likes, c.created_at, c.owner_hash,
                        u.role AS author_role
                   FROM diary_comments c
                   LEFT JOIN lms_users u ON u.id = c.user_id
                  WHERE c.article_id = ?
                    AND (c.status = 'published' OR (c.status = 'pending' AND c.owner_hash = ?))
                  ORDER BY {$order}"
            );
            $st->execute([$articleId, $owner]);
            $rows = $st->fetchAll() ?: [];
        } catch (Throwable $e) { error_log('[diary] commentTree: ' . $e->getMessage()); return []; }

        $liked = $this->likedSet(array_map(fn($r) => (int) $r['id'], $rows));
        $tops = []; $kids = [];
        foreach ($rows as $r) {
            $view = $this->commentView($r, $liked);
            if ((int) ($r['parent_id'] ?? 0) > 0) $kids[(int) $r['parent_id']][] = $view;
            else $tops[(int) $r['id']] = $view;
        }
        // Replies read oldest-first whatever the sort: a conversation is not a
        // leaderboard, and "Top" sorting the answers scrambles the exchange.
        foreach ($tops as $id => &$t) {
            $t['replies'] = $kids[$id] ?? [];
            usort($t['replies'], fn($x, $y) => $x['id'] <=> $y['id']);
        }
        unset($t);
        $tops = array_values($tops);
        return $all ? $tops : array_slice($tops, 0, 3);
    }

    /** One stored row as the view wants it. */
    private function commentView(array $r, array $liked): array
    {
        $name = (string) $r['name'];
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $initials = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : ''));
        $ts = strtotime((string) $r['created_at']) ?: time();
        return [
            'id'      => (int) $r['id'],
            'name'    => $name,
            'initials'=> $initials,
            'is_team' => in_array((string) ($r['author_role'] ?? ''), ['admin', 'instructor'], true),
            'date'    => date('j M Y', $ts),
            'body'    => (string) $r['body'],
            'likes'   => (int) $r['likes'],
            'liked'   => isset($liked[(int) $r['id']]),
            'pending' => (string) $r['status'] === 'pending',
            'replies' => [],
        ];
    }

    /** Which of these comments this reader has already liked. */
    private function likedSet(array $ids): array
    {
        $ids = array_values(array_filter($ids));
        if (!$ids) return [];
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $this->db->prepare("SELECT comment_id FROM diary_comment_likes WHERE voter_hash = ? AND comment_id IN ($in)");
            $st->execute(array_merge([DiaryVisitor::hash('clike')], $ids));
            $out = [];
            foreach ($st->fetchAll() as $r) $out[(int) $r['comment_id']] = true;
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /** How many top-level comments are visible to this reader right now. */
    public function threadCount(int $articleId): int
    {
        $this->ensureEngagement();
        try {
            $st = $this->db->prepare(
                "SELECT COUNT(*) FROM diary_comments
                  WHERE article_id = ? AND parent_id IS NULL
                    AND (status = 'published' OR (status = 'pending' AND owner_hash = ?))"
            );
            $st->execute([$articleId, DiaryVisitor::hash('comment')]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /**
     * Post a comment or a reply. Always stored `pending`.
     *
     * The email is stored and never rendered — the view model built by
     * commentView() has no field to put it in, so there is no later edit that
     * accidentally prints it.
     */
    public function addThreadComment(string $slug, string $name, string $body, string $email = '', int $parentId = 0, int $userId = 0): ?array
    {
        $this->ensureEngagement();
        $a = $this->bySlug($slug);
        if (!$a) return null;
        $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($name))), 0, 120);
        $body = mb_substr(trim(strip_tags($body)), 0, 4000);
        if ($name === '' || mb_strlen($body) < 3) return null;

        // One level only: a reply to a reply joins the thread it belongs to.
        $parent = null;
        if ($parentId > 0) {
            $st = $this->db->prepare('SELECT id, parent_id FROM diary_comments WHERE id = ? AND article_id = ?');
            $st->execute([$parentId, (int) $a['id']]);
            $row = $st->fetch();
            if ($row) $parent = (int) ($row['parent_id'] ?: $row['id']);
        }

        $now   = gmdate('Y-m-d H:i:s');
        $owner = DiaryVisitor::hash('comment');
        $ip    = function_exists('av_client_ip') ? av_client_ip() : '';
        $this->db->prepare(
            'INSERT INTO diary_comments (article_id, parent_id, user_id, name, email, body, status, likes, reports, ip_hash, owner_hash, created_at)
             VALUES (?,?,?,?,?,?,?,0,0,?,?,?)'
        )->execute([
            (int) $a['id'], $parent, $userId, $name,
            mb_substr(trim($email), 0, 190), $body, 'pending',
            $ip === '' ? '' : hash('sha256', 'ip|' . $ip . '|' . av_secret()), $owner, $now,
        ]);

        $id = (int) $this->db->lastInsertId();
        return $this->commentView([
            'id' => $id, 'parent_id' => $parent, 'user_id' => $userId, 'name' => $name,
            'body' => $body, 'status' => 'pending', 'likes' => 0, 'created_at' => $now,
            'owner_hash' => $owner, 'author_role' => '',
        ], []) + ['parent_id' => $parent];
    }

    /** Like or unlike a comment. Returns the new like count. */
    public function likeComment(int $commentId, bool $on): int
    {
        $this->ensureEngagement();
        $voter = DiaryVisitor::hash('clike');
        try {
            if ($on) {
                $st = $this->db->prepare(Database::insertIgnore('diary_comment_likes', ['comment_id', 'voter_hash']));
                $st->execute([$commentId, $voter]);
                if ($st->rowCount() > 0) $this->db->prepare('UPDATE diary_comments SET likes = likes + 1 WHERE id = ?')->execute([$commentId]);
            } else {
                $st = $this->db->prepare('DELETE FROM diary_comment_likes WHERE comment_id = ? AND voter_hash = ?');
                $st->execute([$commentId, $voter]);
                if ($st->rowCount() > 0) $this->db->prepare('UPDATE diary_comments SET likes = CASE WHEN likes > 0 THEN likes - 1 ELSE 0 END WHERE id = ?')->execute([$commentId]);
            }
            $q = $this->db->prepare('SELECT likes FROM diary_comments WHERE id = ?');
            $q->execute([$commentId]);
            return (int) $q->fetchColumn();
        } catch (Throwable $e) { error_log('[diary] likeComment: ' . $e->getMessage()); return 0; }
    }

    /**
     * Flag a comment. A report hides nothing by itself — a comment that three
     * different readers report goes back to `pending` for the team to look at,
     * because one reader with a grudge is not a moderation decision.
     */
    public const REPORTS_TO_HIDE = 3;

    public function reportComment(int $commentId): bool
    {
        $this->ensureEngagement();
        try {
            $this->db->prepare('UPDATE diary_comments SET reports = reports + 1 WHERE id = ?')->execute([$commentId]);
            $this->db->prepare("UPDATE diary_comments SET status = 'pending' WHERE id = ? AND reports >= ? AND status = 'published'")
                ->execute([$commentId, self::REPORTS_TO_HIDE]);
            return true;
        } catch (Throwable $e) { return false; }
    }

    /* ── Moderation (Diary Studio) ─────────────────────────────────────────
       Everything a reader posts lands here first. A comment three readers have
       reported comes back here too, which is why the queue carries the report
       count: a returning comment is not the same thing as a new one. */

    /** The queue: pending first, then anything published that is being reported. */
    public function moderationQueue(int $limit = 100): array
    {
        $this->ensureEngagement();
        $limit = max(1, min(500, $limit));
        try {
            $rows = $this->db->query(
                "SELECT c.id, c.article_id, c.parent_id, c.name, c.email, c.body, c.status,
                        c.likes, c.reports, c.created_at, a.title AS article_title, a.slug AS article_slug
                   FROM diary_comments c
                   LEFT JOIN articles a ON a.id = c.article_id
                  WHERE c.status = 'pending' OR (c.status = 'published' AND c.reports > 0)
                  ORDER BY c.reports DESC, c.id DESC
                  LIMIT {$limit}"
            )->fetchAll() ?: [];
        } catch (Throwable $e) { error_log('[diary] moderationQueue: ' . $e->getMessage()); return []; }
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['reports'] = (int) $r['reports'];
            $r['is_reply'] = ((int) ($r['parent_id'] ?? 0)) > 0;
            $r['url'] = '/diary/' . (string) $r['article_slug'] . '/#comments';
        }
        return $rows;
    }

    /** How many comments are waiting. Drives the Studio's badge. */
    public function moderationCount(): int
    {
        $this->ensureEngagement();
        try {
            return (int) $this->db->query(
                "SELECT COUNT(*) FROM diary_comments WHERE status = 'pending' OR (status = 'published' AND reports > 0)"
            )->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /**
     * Publish or remove one comment.
     *
     * Publishing clears the report count: the team has now looked, and leaving
     * the old reports in place would send the comment straight back into the
     * queue the next time anybody flagged it, with no way to tell a decision
     * that had been made from one that had not.
     *
     * Removing a top-level comment removes its replies with it. A reply left
     * under a deleted question reads as an answer to whatever is above it now.
     */
    public function setCommentStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['published', 'removed', 'pending'], true)) return false;
        $this->ensureEngagement();
        try {
            if ($status === 'published') {
                $this->db->prepare("UPDATE diary_comments SET status = 'published', reports = 0 WHERE id = ?")->execute([$id]);
            } else {
                $this->db->prepare('UPDATE diary_comments SET status = ? WHERE id = ? OR parent_id = ?')->execute([$status, $id, $id]);
            }
            return true;
        } catch (Throwable $e) { error_log('[diary] setCommentStatus: ' . $e->getMessage()); return false; }
    }

    /* ── Keep reading ──────────────────────────────────────────────────────
       The related entries the article already names, with their counts, in the
       shape the Keep reading cards render. */
    public function keepReading(int $articleId, int $n = 3): array
    {
        $cards = array_slice($this->relatedCards($articleId), 0, max(1, $n));
        if (!$cards) return [];
        // Cards carry no row id (the public JSON shape has never exposed one), so
        // the ids are looked up by slug in one query rather than per card.
        $ids = $this->idsForSlugs(array_column($cards, 'slug'));
        $counts = $ids ? $this->countsFor(array_values($ids)) : [];
        $out = [];
        foreach ($cards as $c) {
            $id = (int) ($ids[$c['slug']] ?? 0);
            $out[] = [
                'category' => (string) $c['category'],
                'title'    => (string) $c['title'],
                'url'      => '/diary/' . $c['slug'] . '/',
                'date'     => (string) $c['published'],
                'minutes'  => (int) $c['read_minutes'],
                'views'    => (int) ($counts[$id]['views'] ?? 0),
            ];
        }
        return $out;
    }

    /* ── The author card ───────────────────────────────────────────────────
       The byline is prose, so this reads the first name out of it and asks the
       accounts table whether that person is on the team. When it cannot tell,
       the role comes back EMPTY rather than invented — a card that calls
       somebody "Contributor" because nothing was known is a card that lies in
       a typeface. The stylesheet hides an empty role line. */
    public function authorCard(array $article): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($article['authors_html'] ?? ''))));
        if ($name === '') $name = 'The Afrovanguard Team';
        // "Ada Nwosu and Tunde Afolabi" credits the card to the first name.
        $name = trim((string) preg_split('/\s+(?:and|&|,)\s+/iu', $name)[0]);
        $parts = preg_split('/\s+/u', $name) ?: [$name];

        $role = '';
        try {
            $st = $this->db->prepare('SELECT role FROM lms_users WHERE LOWER(name) = ? LIMIT 1');
            $st->execute([mb_strtolower($name)]);
            $r = (string) ($st->fetchColumn() ?: '');
            if ($r === 'admin' || $r === 'instructor') $role = 'Afrovanguard team';
        } catch (Throwable $e) { /* no accounts table on this install: no role */ }

        return [
            'name'     => $name,
            'slug'     => slugify($name),
            'role'     => $role,
            'initials' => mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '')),
        ];
    }

    /* ── Audio ─────────────────────────────────────────────────────────────
       What the Listen button needs before anything has been synthesised: a
       source and a length. The length is an ESTIMATE from the word count at a
       narration pace — the exact duration is only knowable once the file
       exists, and the bar replaces it with the real one from the audio element
       as soon as it loads. The button is labelled "Listen · 8 min", which is a
       claim about how long listening takes, not a measurement.

       Null means there is no audio at all, which hides the button — the state
       §7 calls "audio unavailable". */
    public const NARRATION_WPM = 150;

    public function audioMeta(int $articleId): ?array
    {
        try {
            $st = $this->db->prepare('SELECT slug, body_html, title, audio_url FROM articles WHERE id = ?');
            $st->execute([$articleId]);
            $a = $st->fetch();
        } catch (Throwable $e) { return null; }
        if (!$a) return null;

        $words = max(1, str_word_count(strip_tags((string) $a['title'] . ' ' . (string) $a['body_html'])));
        $seconds = (int) max(30, round($words / self::NARRATION_WPM * 60));

        $narration = trim((string) ($a['audio_url'] ?? ''));
        if ($narration !== '') return ['kind' => 'narration', 'src' => $narration, 'seconds' => $seconds];

        $tts = class_exists('Tts') && Tts::available() && Tts::engine() !== 'mock' && Tts::ext() === 'mp3';
        if (!$tts) return null;
        return ['kind' => 'tts', 'src' => '/diary/audio.php?slug=' . rawurlencode((string) $a['slug']) . '&play=1', 'seconds' => $seconds];
    }
    /* ══ Reference codes ═══════════════════════════════════════════════════
       Every entry carries a short, quotable identifier: AVD-2608-0003 is the
       third Diary entry of August 2026.

       It exists because a slug is not an identifier. A slug gets rewritten for
       SEO, a title gets corrected, and a URL someone wrote on a printout stops
       resolving. A reference code is assigned once, on creation, and never
       changes — so it can be quoted in a meeting, cited in a report, or typed
       into search a year later and still land on the right entry.
       ═════════════════════════════════════════════════════════════════════ */

    /** Prefix for a Diary reference code. */
    public const REF_PREFIX = 'AVD';

    private bool $refReady = false;

    /** Add the column and its unique index, portably, then backfill once. */
    private function ensureRefCodes(): void
    {
        if ($this->refReady) return; $this->refReady = true;
        if (!Database::columnExists('articles', 'ref_code')) {
            // Nullable, not NOT NULL DEFAULT '': the index below is unique, and an
            // empty string is a real value, so two not-yet-assigned rows would
            // collide. All three engines allow repeated NULLs in a unique index.
            try { $this->db->exec('ALTER TABLE articles ADD COLUMN ref_code VARCHAR(32) DEFAULT NULL'); }
            catch (Throwable $e) { error_log('[diary] add ref_code: ' . $e->getMessage()); return; }
            try { $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_articles_refcode ON articles(ref_code)'); }
            catch (Throwable $e) { error_log('[diary] index ref_code: ' . $e->getMessage()); }
        }

        // Assign anything still missing one. This is a probe against the unique
        // index rather than a one-time flag, so entries that arrive by a route
        // that never calls save() — the installer's seed, a direct import, a hand
        // -written INSERT — get codes too, instead of being permanently missed
        // because a flag was already set.
        try {
            $missing = $this->db->query(
                "SELECT 1 FROM articles WHERE ref_code IS NULL OR ref_code = '' LIMIT 1"
            )->fetchColumn();
            if ($missing) $this->backfillRefCodes();
        } catch (Throwable $e) { error_log('[diary] ref_code backfill: ' . $e->getMessage()); }
    }

    /** Whether reference codes are available on this database. */
    public function refCodesEnabled(): bool
    {
        $this->ensureRefCodes();
        return Database::columnExists('articles', 'ref_code');
    }

    /** `2608` for August 2026 — the month the entry was published. */
    private static function refMonth(string $publishedAt): string
    {
        $ts = trim($publishedAt) !== '' ? strtotime($publishedAt) : false;
        return gmdate('ym', $ts !== false ? $ts : time());
    }

    /**
     * Assemble a code. The serial is four digits, and widens rather than wraps
     * if a single month ever carries more than 9999 entries.
     */
    private static function formatRef(string $ym, int $seq): string
    {
        return self::REF_PREFIX . '-' . $ym . '-' . str_pad((string) max(1, $seq), 4, '0', STR_PAD_LEFT);
    }

    /**
     * The next unused serial for an entry published in $publishedAt's month.
     *
     * The max is computed in PHP rather than SQL because the tail is a substring
     * and `MAX(CAST(SUBSTR(...)))` is spelled differently on all three engines
     * for no benefit — a month holds tens of entries, not millions.
     */
    private function nextRefCode(string $publishedAt): string
    {
        $ym = self::refMonth($publishedAt);
        $st = $this->db->prepare('SELECT ref_code FROM articles WHERE ref_code LIKE ?');
        $st->execute([self::REF_PREFIX . '-' . $ym . '-%']);
        $head = strlen(self::REF_PREFIX) + 6;                 // 'AVD' + '-YYMM-'
        $max = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $n = (int) substr((string) $code, $head);
            if ($n > $max) $max = $n;
        }
        return self::formatRef($ym, $max + 1);
    }

    /**
     * Read a code the way a human will actually type it: any case, spaces or
     * dashes anywhere or nowhere, with or without the AVD prefix. Returns the
     * canonical form, or '' if it is not a code at all.
     *
     *   'avd 2608 0003' · 'AVD-2608-0003' · '26080003' → 'AVD-2608-0003'
     */
    public static function normaliseRefCode(string $raw): string
    {
        $s = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $raw));
        if ($s === '') return '';
        $p = self::REF_PREFIX;
        if (strncmp($s, $p, strlen($p)) === 0) $s = substr($s, strlen($p));
        if (!preg_match('/^(\d{4})(\d{4,})$/', $s, $m)) return '';
        return self::formatRef($m[1], (int) $m[2]);
    }

    /** Does this string look like a reference code rather than a search phrase? */
    public static function looksLikeRefCode(string $raw): bool
    {
        return self::normaliseRefCode($raw) !== '';
    }

    /** Resolve a code to its slug ('' when no entry answers to it). */
    public function slugForRefCode(string $code): string
    {
        $code = self::normaliseRefCode($code);
        if ($code === '' || !$this->refCodesEnabled()) return '';
        $st = $this->db->prepare('SELECT slug FROM articles WHERE ref_code = ?');
        $st->execute([$code]);
        return (string) ($st->fetchColumn() ?: '');
    }

    /** The full entry behind a code, or null. Drafts are included on request. */
    public function byRefCode(string $code, bool $includeDrafts = false): ?array
    {
        $slug = $this->slugForRefCode($code);
        return $slug === '' ? null : $this->bySlug($slug, $includeDrafts);
    }

    /**
     * Give every code-less entry one, oldest first, so serials read
     * chronologically within each month. Idempotent. Returns the number assigned.
     */
    public function backfillRefCodes(): int
    {
        if (!Database::columnExists('articles', 'ref_code')) return 0;
        $rows = $this->db->query(
            "SELECT id, published_at FROM articles
             WHERE ref_code IS NULL OR ref_code = ''
             ORDER BY published_at ASC, id ASC"
        )->fetchAll();
        if (!$rows) return 0;
        $up = $this->db->prepare('UPDATE articles SET ref_code = ? WHERE id = ?');
        $n = 0;
        foreach ($rows as $r) {
            $code = $this->nextRefCode((string) ($r['published_at'] ?? ''));
            try { $up->execute([$code, (int) $r['id']]); $n++; }
            catch (Throwable $e) { error_log('[diary] ref_code for #' . $r['id'] . ': ' . $e->getMessage()); }
        }
        return $n;
    }

    private function ensureSeries(): void
    {
        if ($this->seriesReady) return; $this->seriesReady = true;
        $drv = Database::driver();
        $ddl = "CREATE TABLE IF NOT EXISTS diary_series (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug VARCHAR(191) NOT NULL DEFAULT '',
            title VARCHAR(200) NOT NULL DEFAULT '',
            description VARCHAR(500) NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT ''
        )";
        Database::execSchema($this->db, $ddl);
        $intType = $drv === 'sqlite' ? 'INTEGER' : 'INT';
        foreach (['series_id', 'series_part'] as $col) {
            if (!Database::columnExists('articles', $col)) {
                try { $this->db->exec("ALTER TABLE articles ADD COLUMN $col $intType NOT NULL DEFAULT 0"); }
                catch (Throwable $e) { error_log('[diary] add ' . $col . ': ' . $e->getMessage()); }
            }
        }
    }

    /** Whether the series columns are usable on this engine. */
    private function seriesEnabled(): bool
    {
        $this->ensureSeries();
        return Database::columnExists('articles', 'series_id');
    }

    /** Find-or-create a series by title; returns its id (0 for an empty title). */
    public function seriesResolve(string $title, string $description = ''): int
    {
        $title = trim($title);
        if ($title === '') return 0;
        $this->ensureSeries();
        $slug = slugify($title);
        $f = $this->db->prepare('SELECT id FROM diary_series WHERE slug = ?');
        $f->execute([$slug]);
        $id = (int) ($f->fetchColumn() ?: 0);
        if ($id) {
            if ($description !== '') $this->db->prepare('UPDATE diary_series SET description = ? WHERE id = ?')->execute([mb_substr($description, 0, 500), $id]);
            return $id;
        }
        $this->db->prepare('INSERT INTO diary_series (slug, title, description, created_at) VALUES (?,?,?,?)')
            ->execute([$slug, mb_substr($title, 0, 200), mb_substr($description, 0, 500), date('Y-m-d H:i:s')]);
        return (int) $this->db->lastInsertId();
    }

    /** All series with their published-post counts (for editor pickers + index). */
    public function seriesList(): array
    {
        if (!$this->seriesEnabled()) return [];
        return $this->db->query(
            "SELECT s.id, s.slug, s.title, s.description,
                    (SELECT COUNT(*) FROM articles a WHERE a.series_id = s.id AND a.status = 'published') AS n
             FROM diary_series s ORDER BY s.title"
        )->fetchAll() ?: [];
    }

    /** A series by slug + its published posts in order. Null if unknown. */
    public function seriesBySlug(string $slug): ?array
    {
        if (!$this->seriesEnabled()) return null;
        $st = $this->db->prepare('SELECT * FROM diary_series WHERE slug = ?');
        $st->execute([$slug]);
        $s = $st->fetch();
        if (!$s) return null;
        $s['posts'] = $this->seriesPosts((int) $s['id']);
        return $s;
    }

    /** Published posts in a series, ordered by part then date. */
    private function seriesPosts(int $seriesId): array
    {
        $st = $this->db->prepare(
            "SELECT a.slug, a.title, a.dek, a.series_part, a.published, a.published_at
             FROM articles a
             WHERE a.series_id = ? AND a.status = 'published'
             ORDER BY a.series_part ASC, a.published_at ASC, a.id ASC"
        );
        $st->execute([$seriesId]);
        return $st->fetchAll() ?: [];
    }

    /**
     * Series context for a single article row (needs series_id/series_part):
     * ['title','slug','part','count','posts'[],'prev','next'] or null.
     */
    public function seriesContext(array $a): ?array
    {
        if (!$this->seriesEnabled()) return null;
        $sid = (int) ($a['series_id'] ?? 0);
        if ($sid <= 0) return null;
        $st = $this->db->prepare('SELECT slug, title, description FROM diary_series WHERE id = ?');
        $st->execute([$sid]);
        $s = $st->fetch();
        if (!$s) return null;
        $posts = $this->seriesPosts($sid);
        $prev = $next = null;
        foreach ($posts as $i => $p) {
            if ($p['slug'] === ($a['slug'] ?? '')) {
                if ($i > 0) $prev = $posts[$i - 1];
                if ($i < count($posts) - 1) $next = $posts[$i + 1];
                break;
            }
        }
        return [
            'title' => (string) $s['title'], 'slug' => (string) $s['slug'],
            'description' => (string) $s['description'],
            'part' => (int) ($a['series_part'] ?? 0), 'count' => count($posts),
            'posts' => $posts, 'prev' => $prev, 'next' => $next,
        ];
    }

    /**
     * Create or update an article from editor input.
     * $d keys: slug, title, dek, category, authors_html, published, published_at,
     *          read_minutes, gradient, mc_title, cover_url, og_image, body_html,
     *          featured, status, sections[[anchor,label]], related[slug]
     * Returns the saved slug.
     */
    public function save(array $d): string
    {
        $slug = slugify($d['slug'] ?: $d['title']);
        $catId = $this->categoryId($d['category'] ?: 'Dispatch');
        $now = date('Y-m-d H:i:s');
        // Resolved before the transaction: refCodesEnabled() can run DDL, and DDL
        // inside an open transaction is a different conversation on every engine.
        $refCodes = $this->refCodesEnabled();

        // An entry is identified by its reference code when the caller knows it,
        // which is what lets a slug be corrected instead of forking the entry into
        // a second copy — the old behaviour, because the only key was the slug
        // itself. Callers that don't send one (imports, the seed) still match by
        // slug exactly as before.
        $id = 0;
        $refIn = $refCodes ? self::normaliseRefCode((string) ($d['ref_code'] ?? '')) : '';
        if ($refIn !== '') {
            $st = $this->db->prepare('SELECT id FROM articles WHERE ref_code = ?');
            $st->execute([$refIn]);
            $id = (int) ($st->fetchColumn() ?: 0);
        }
        if (!$id) {
            $exists = $this->db->prepare('SELECT id FROM articles WHERE slug = ?');
            $exists->execute([$slug]);
            $id = (int) ($exists->fetchColumn() ?: 0);
        } else {
            // Renaming into a slug another entry already owns would fail on the
            // unique index deep inside the transaction. Say so plainly instead.
            $clash = $this->db->prepare('SELECT id FROM articles WHERE slug = ? AND id <> ?');
            $clash->execute([$slug, $id]);
            if ($clash->fetchColumn()) {
                throw new RuntimeException('slug-taken: another entry already uses the slug "' . $slug . '".');
            }
        }

        $fields = [
            'slug' => $slug, 'title' => $d['title'], 'dek' => $d['dek'],
            'category_id' => $catId, 'authors_html' => $d['authors_html'],
            'published' => $d['published'], 'published_at' => $d['published_at'],
            'read_minutes' => (int) $d['read_minutes'], 'gradient' => $d['gradient'] ?: 'g-gold',
            'mc_title' => $d['mc_title'], 'cover_url' => $d['cover_url'] ?: null,
            'og_image' => $d['og_image'] ?: null, 'body_html' => $d['body_html'],
            'featured' => !empty($d['featured']) ? 1 : 0, 'status' => $d['status'] === 'draft' ? 'draft' : 'published',
            'format' => in_array($d['format'] ?? 'standard', ['standard', 'qa', 'feature'], true) ? $d['format'] : 'standard',
            'updated_at' => $now,
        ];
        // Author-provided narration/podcast audio — only bind the column when the
        // DB actually has it (an un-migrated MySQL/Postgres target degrades to
        // "no audio" instead of throwing on the INSERT/UPDATE).
        if (array_key_exists('audio_url', $d) && Database::columnExists('articles', 'audio_url')) {
            $fields['audio_url'] = trim((string) $d['audio_url']) ?: null;
        }
        // Colour-aware feature hero: precompute luminance of the bottom region
        // (where the title sits) so the hero text picks a legible colour.
        if (Database::columnExists('articles', 'cover_is_dark')) {
            $fields['cover_is_dark'] = $fields['cover_url'] ? (av_cover_is_dark((string) $fields['cover_url'], 'bottom') ?? -1) : -1;
        }
        // Series: a post can belong to an ordered, numbered series. The series is
        // created on first use (by title). Guarded so un-migrated engines degrade.
        if ($this->seriesEnabled()) {
            $fields['series_id'] = $this->seriesResolve((string) ($d['series'] ?? ''), (string) ($d['series_desc'] ?? ''));
            $fields['series_part'] = max(0, (int) ($d['series_part'] ?? 0));
        }

        // Never name a column this database does not have: on a deployment that
        // missed the articles migration that is the difference between an entry
        // saving and the Studio reporting a server error.
        $fields = $this->onlyExistingArticleCols($fields);

        $this->db->beginTransaction();
        try {
            if ($id) {
                $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
                $st = $this->db->prepare("UPDATE articles SET $set WHERE id = :id");
                $st->execute($fields + ['id' => $id]);
            } else {
                $fields['mc_session'] = strtoupper($d['category'] ?: 'Dispatch');
                $fields['mc_tag'] = 'ALIMOSHO · LAGOS';
                $fields['base_claps'] = 0;
                $fields['created_at'] = $now;
                // Assigned on creation only, and never on update: the whole point
                // of the code is that it still resolves after the slug, the title
                // or the publication date has been changed. Computed inside the
                // transaction, with the unique index as the real guarantee.
                if ($refCodes) $fields['ref_code'] = $this->nextRefCode((string) $d['published_at']);
                $fields = $this->onlyExistingArticleCols($fields);
                $cols = implode(', ', array_keys($fields));
                $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
                $this->db->prepare("INSERT INTO articles ($cols) VALUES ($ph)")->execute($fields);
                $id = (int) $this->db->lastInsertId();
                $this->db->prepare(Database::insertIgnore('reactions', ['article_id', 'claps']))->execute([$id, 0]);
            }
            // Replace sections + related
            $this->db->prepare('DELETE FROM sections WHERE article_id = ?')->execute([$id]);
            $insSec = $this->db->prepare('INSERT INTO sections (article_id, anchor, label, position) VALUES (?,?,?,?)');
            foreach (($d['sections'] ?? []) as $i => $s) { $insSec->execute([$id, $s[0], $s[1], $i]); }

            $this->db->prepare('DELETE FROM related WHERE article_id = ?')->execute([$id]);
            $insRel = $this->db->prepare('INSERT INTO related (article_id, related_slug, position) VALUES (?,?,?)');
            foreach (($d['related'] ?? []) as $i => $rs) { if ($rs && $rs !== $slug) $insRel->execute([$id, $rs, $i]); }

            $this->db->commit();
        } catch (Throwable $ex) { $this->db->rollBack(); throw $ex; }
        if (($d['status'] ?? '') === 'published' && class_exists('Events')) {
            Events::emit('diary.published', ['slug' => $slug, 'title' => (string) ($d['title'] ?? ''),
                'url' => function_exists('diary_url') ? diary_url($slug . '/') : $slug]);
        }
        return $slug;
    }

    public function delete(string $slug): bool
    {
        $st = $this->db->prepare('SELECT id FROM articles WHERE slug = ?');
        $st->execute([$slug]);
        $id = $st->fetchColumn();
        if ($id === false) return false;
        $this->db->prepare('DELETE FROM sections WHERE article_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM related WHERE article_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM reactions WHERE article_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM articles WHERE id = ?')->execute([$id]);
        return true;
    }

    /** Live applause total = seeded base + server-side increments. */
    public function claps(string $slug): int
    {
        $st = $this->db->prepare(
            'SELECT a.base_claps + COALESCE(r.claps, 0)
             FROM articles a LEFT JOIN reactions r ON r.article_id = a.id
             WHERE a.slug = ?'
        );
        $st->execute([$slug]);
        $v = $st->fetchColumn();
        return $v === false ? 0 : (int) $v;
    }

    /** Increment applause for an article; returns the new live total. */
    public function addClaps(string $slug, int $n = 1): int
    {
        $n = max(1, min($n, 20));
        $id = $this->db->prepare('SELECT id FROM articles WHERE slug = ?');
        $id->execute([$slug]);
        $aid = $id->fetchColumn();
        if ($aid === false) return 0;

        // Upsert applause. Each engine references the existing row differently:
        // MySQL uses ON DUPLICATE KEY UPDATE; SQLite reads the bare column;
        // Postgres requires the table-qualified `reactions.claps` (bare is
        // ambiguous against the `excluded` pseudo-row). nowExpr() keeps the
        // timestamp portable (reactions.updated_at is DATETIME/TIMESTAMP there).
        $now = Database::nowExpr();
        switch (Database::driver()) {
            case 'mysql':
                $sql = "INSERT INTO reactions (article_id, claps, updated_at) VALUES (?, ?, {$now})
                        ON DUPLICATE KEY UPDATE claps = claps + VALUES(claps), updated_at = {$now}";
                break;
            case 'pgsql':
                $sql = "INSERT INTO reactions (article_id, claps, updated_at) VALUES (?, ?, {$now})
                        ON CONFLICT(article_id) DO UPDATE SET claps = reactions.claps + excluded.claps, updated_at = {$now}";
                break;
            default: // sqlite — byte-identical to the original
                $sql = "INSERT INTO reactions (article_id, claps, updated_at) VALUES (?, ?, {$now})
                        ON CONFLICT(article_id) DO UPDATE SET claps = claps + excluded.claps, updated_at = {$now}";
        }
        $this->db->prepare($sql)->execute([$aid, $n]);

        return $this->claps($slug);
    }

    /** Store a newsletter subscriber (idempotent on email). */
    public function subscribe(string $email, string $source = 'diary'): bool
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        $this->db->prepare(Database::insertIgnore('subscribers', ['email', 'source']))
                 ->execute([$email, $source]);
        return true;
    }

    /* ─────────────────────────────────────────────────────────────────────
       Paginated, filterable feed (powers progressive loading + year/month UI)
       ───────────────────────────────────────────────────────────────────── */

    /**
     * One page of published entries, newest first, with optional filters.
     *
     * $opts: year ('YYYY'), month ('MM'), cat (category slug), q (search),
     *        limit (1..48, default 12), offset (>=0).
     * Returns ['items'=>card[], 'total'=>int, 'limit'=>int, 'offset'=>int].
     *
     * Date filters use substr() on published_at ('YYYY-MM-DD HH:MM:SS') rather
     * than strftime()/YEAR() so the same SQL runs on SQLite, MySQL and Postgres
     * (ties into the DB-portability work).
     */
    public function page(array $opts): array
    {
        [$where, $params] = $this->filterClause($opts);
        $limit  = max(1, min(48, (int) ($opts['limit'] ?? 12)));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $total = (int) $this->bind(
            'SELECT COUNT(*) FROM articles a JOIN categories c ON c.id = a.category_id WHERE ' . $where,
            $params
        )->fetchColumn();

        /* Sort. "Most read" and "most discussed" are counts the reader can see on
           every card, so they are ordered in SQL against the same aggregates
           rather than by fetching a page and re-sorting it — a page sorted
           after the LIMIT is the twelve newest entries in a different order,
           not the twelve most read. Both fall back to newest on a tie, so the
           order is stable while every entry still has nought views. */
        $join = '';
        $order = 'a.published_at DESC, a.id DESC';
        $sort = (string) ($opts['sort'] ?? 'latest');
        if ($sort === 'read' || $sort === 'discussed') {
            $this->ensureEngagement();
            if ($sort === 'read') {
                $join  = ' LEFT JOIN (SELECT article_id, SUM(count) n FROM diary_views GROUP BY article_id) agg ON agg.article_id = a.id';
            } else {
                $join  = " LEFT JOIN (SELECT article_id, COUNT(*) n FROM diary_comments WHERE status = 'published' GROUP BY article_id) agg ON agg.article_id = a.id";
            }
            $order = 'COALESCE(agg.n, 0) DESC, ' . $order;
        }

        // LIMIT/OFFSET are validated ints, inlined for cross-driver consistency.
        $items = $this->bind(
            'SELECT ' . $this->cardCols() . '
             FROM articles a JOIN categories c ON c.id = a.category_id' . $join . '
             WHERE ' . $where . '
             ORDER BY ' . $order . '
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        )->fetchAll();

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /** Filter facets for the controls UI: years, months-per-year, category counts. */
    public function facets(): array
    {
        $years = $this->db->query(
            "SELECT DISTINCT substr(published_at, 1, 4) AS y
             FROM articles WHERE status = 'published' ORDER BY y DESC"
        )->fetchAll(PDO::FETCH_COLUMN);

        $monthsByYear = [];
        $ym = $this->db->query(
            "SELECT DISTINCT substr(published_at, 1, 4) AS y, substr(published_at, 6, 2) AS m
             FROM articles WHERE status = 'published' ORDER BY y DESC, m DESC"
        )->fetchAll();
        foreach ($ym as $r) { $monthsByYear[(string) $r['y']][] = (string) $r['m']; }

        $categories = $this->db->query(
            "SELECT c.slug, c.name, COUNT(*) AS n
             FROM articles a JOIN categories c ON c.id = a.category_id
             WHERE a.status = 'published'
             GROUP BY c.id, c.slug, c.name ORDER BY c.name"
        )->fetchAll();

        return ['years' => $years, 'monthsByYear' => $monthsByYear, 'categories' => $categories];
    }

    /** Build the shared WHERE clause + bound params for page()/count(). */
    private function filterClause(array $o): array
    {
        $where = ["a.status = 'published'"];
        $p = [];
        if (!empty($o['year']) && preg_match('/^\d{4}$/', (string) $o['year'])) {
            $where[] = 'substr(a.published_at, 1, 4) = ?';
            $p[] = (string) $o['year'];
        }
        if (!empty($o['month']) && preg_match('/^\d{2}$/', (string) $o['month'])) {
            $where[] = 'substr(a.published_at, 6, 2) = ?';
            $p[] = (string) $o['month'];
        }
        if (!empty($o['cat'])) {
            $where[] = 'c.slug = ?';
            $p[] = (string) $o['cat'];
        }
        if (!empty($o['author'])) {
            // The byline is prose ("Tunde Afolabi and Ada Nwosu"), not a key, so
            // "more by this writer" matches the slug's words inside it. An entry
            // with two bylines is correctly found under either name.
            $words = array_filter(explode('-', strtolower((string) $o['author'])));
            if ($words) {
                $where[] = 'LOWER(a.authors_html) LIKE ?';
                $p[] = '%' . implode('%', $words) . '%';
            }
        }
        if (!empty($o['q'])) {
            // Escape LIKE wildcards in user input; match title or dek.
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $o['q'])) . '%';
            $where[] = '(a.title LIKE ? ESCAPE \'\\\' OR a.dek LIKE ? ESCAPE \'\\\')';
            $p[] = $like;
            $p[] = $like;
        }
        return [implode(' AND ', $where), $p];
    }

    private function bind(string $sql, array $params): PDOStatement
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st;
    }
}
