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

    /** All published entries, newest first (lightweight card fields). */
    public function all(): array
    {
        return $this->db->query(
            'SELECT ' . self::CARD_COLS . '
             FROM articles a JOIN categories c ON c.id = a.category_id
             WHERE a.status = \'published\'
             ORDER BY a.published_at DESC, a.id DESC'
        )->fetchAll();
    }

    /** Every entry incl. drafts (admin only). */
    public function allForAdmin(): array
    {
        return $this->db->query(
            'SELECT ' . self::CARD_COLS . ', a.updated_at
             FROM articles a JOIN categories c ON c.id = a.category_id
             ORDER BY a.updated_at DESC, a.id DESC'
        )->fetchAll();
    }

    /** The single featured published dispatch (falls back to newest). */
    public function featured(): ?array
    {
        $row = $this->db->query(
            'SELECT ' . self::CARD_COLS . '
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
        return $a;
    }

    /** Card data for an article's "More from the Diary" list. */
    public function relatedCards(int $articleId): array
    {
        $st = $this->db->prepare(
            'SELECT ' . self::CARD_COLS . '
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

        $exists = $this->db->prepare('SELECT id FROM articles WHERE slug = ?');
        $exists->execute([$slug]);
        $id = $exists->fetchColumn();

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

        // Upsert applause. SQLite/Postgres use ON CONFLICT; MySQL uses its
        // ON DUPLICATE KEY UPDATE form. nowExpr() keeps the timestamp portable
        // (reactions.updated_at is a real DATETIME/TIMESTAMP on MySQL/Postgres).
        $now = Database::nowExpr();
        $sql = Database::driver() === 'mysql'
            ? "INSERT INTO reactions (article_id, claps, updated_at) VALUES (?, ?, {$now})
               ON DUPLICATE KEY UPDATE claps = claps + VALUES(claps), updated_at = {$now}"
            : "INSERT INTO reactions (article_id, claps, updated_at) VALUES (?, ?, {$now})
               ON CONFLICT(article_id) DO UPDATE SET claps = claps + excluded.claps, updated_at = {$now}";
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
            'SELECT ' . self::CARD_COLS . '
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
