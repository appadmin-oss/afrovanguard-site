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
    private bool $commentsReady = false;

    /* ── Comments ──────────────────────────────────────────────────────────
       Lazily-created so the feature needs no migration step. Comments are
       published on submit (status 'published') but carry a status column so an
       admin can hide/spam them later. */
    private function ensureComments(): void
    {
        if ($this->commentsReady) return; $this->commentsReady = true;
        $drv = Database::driver();
        $ddl = "CREATE TABLE IF NOT EXISTS diary_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            article_id INTEGER NOT NULL DEFAULT 0,
            user_id INTEGER NOT NULL DEFAULT 0,
            name VARCHAR(120) NOT NULL DEFAULT '',
            body TEXT NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'published',
            created_at TEXT NOT NULL DEFAULT ''
        )";
        try { Database::execSchema($this->db, $ddl); }
        catch (Throwable $e) { error_log('[diary] ensureComments: ' . $e->getMessage()); }
    }

    /** Published comments for an article slug, oldest first. */
    public function comments(string $slug): array
    {
        $this->ensureComments();
        $a = $this->bySlug($slug);
        if (!$a) return [];
        try {
            $st = $this->db->prepare(
                "SELECT id, name, body, created_at FROM diary_comments
                 WHERE article_id = ? AND status = 'published' ORDER BY id ASC"
            );
            $st->execute([(int) $a['id']]);
            return $st->fetchAll() ?: [];
        } catch (Throwable $e) { return []; }
    }

    public function commentCount(int $articleId): int
    {
        $this->ensureComments();
        try {
            $st = $this->db->prepare("SELECT COUNT(*) FROM diary_comments WHERE article_id = ? AND status = 'published'");
            $st->execute([$articleId]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /** Add a comment to a published article. Returns the stored row or null. */
    public function addComment(string $slug, string $name, string $body, int $userId = 0): ?array
    {
        $this->ensureComments();
        $a = $this->bySlug($slug);
        if (!$a) return null;
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)));
        $body = trim(strip_tags($body));
        $name = mb_substr($name, 0, 120);
        $body = mb_substr($body, 0, 4000);
        if ($name === '' || mb_strlen($body) < 2) return null;
        $now = gmdate('Y-m-d H:i:s');
        $this->db->prepare('INSERT INTO diary_comments (article_id, user_id, name, body, status, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([(int) $a['id'], $userId, $name, $body, 'published', $now]);
        return ['id' => (int) $this->db->lastInsertId(), 'name' => $name, 'body' => $body, 'created_at' => $now];
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

        // LIMIT/OFFSET are validated ints, inlined for cross-driver consistency.
        $items = $this->bind(
            'SELECT ' . $this->cardCols() . '
             FROM articles a JOIN categories c ON c.id = a.category_id
             WHERE ' . $where . '
             ORDER BY a.published_at DESC, a.id DESC
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
