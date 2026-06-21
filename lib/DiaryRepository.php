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
         a.read_minutes, a.gradient, a.mc_title, c.name AS category, c.slug AS category_slug,
         a.featured';

    /** All entries, newest first (lightweight card fields). */
    public function all(): array
    {
        return $this->db->query(
            'SELECT ' . self::CARD_COLS . '
             FROM articles a JOIN categories c ON c.id = a.category_id
             ORDER BY a.published_at DESC, a.id DESC'
        )->fetchAll();
    }

    /** The single featured dispatch (falls back to newest). */
    public function featured(): ?array
    {
        $row = $this->db->query(
            'SELECT ' . self::CARD_COLS . '
             FROM articles a JOIN categories c ON c.id = a.category_id
             WHERE a.featured = 1 ORDER BY a.published_at DESC LIMIT 1'
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
    public function bySlug(string $slug): ?array
    {
        $st = $this->db->prepare(
            'SELECT a.*, c.name AS category, c.slug AS category_slug
             FROM articles a JOIN categories c ON c.id = a.category_id
             WHERE a.slug = ?'
        );
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
             WHERE r.article_id = ? ORDER BY r.position'
        );
        $st->execute([$articleId]);
        return $st->fetchAll();
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

        $this->db->prepare(
            'INSERT INTO reactions (article_id, claps, updated_at) VALUES (?, ?, datetime(\'now\'))
             ON CONFLICT(article_id) DO UPDATE SET claps = claps + excluded.claps, updated_at = datetime(\'now\')'
        )->execute([$aid, $n]);

        return $this->claps($slug);
    }

    /** Store a newsletter subscriber (idempotent on email). */
    public function subscribe(string $email, string $source = 'diary'): bool
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        $this->db->prepare('INSERT OR IGNORE INTO subscribers (email, source) VALUES (?, ?)')
                 ->execute([$email, $source]);
        return true;
    }
}
