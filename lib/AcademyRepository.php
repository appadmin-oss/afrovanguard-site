<?php
/**
 * lib/AcademyRepository.php — all course/enrolment queries in one place.
 */
declare(strict_types=1);

final class AcademyRepository
{
    private PDO $db;
    public function __construct(?PDO $pdo = null) { $this->db = $pdo ?? Database::pdo(); }

    private const COLS = 'id, slug, title, summary, cover_url, category, level, format, duration, price, location, gradient, featured, status, sort';

    public function all(): array
    {
        return $this->db->query("SELECT " . self::COLS . " FROM courses WHERE status='published' ORDER BY featured DESC, sort ASC, title ASC")->fetchAll();
    }
    public function allForAdmin(): array
    {
        return $this->db->query("SELECT " . self::COLS . ", updated_at FROM courses ORDER BY sort ASC, updated_at DESC")->fetchAll();
    }
    public function featured(): ?array
    {
        $r = $this->db->query("SELECT " . self::COLS . " FROM courses WHERE featured=1 AND status='published' ORDER BY sort LIMIT 1")->fetch();
        return $r ?: ($this->all()[0] ?? null);
    }
    public function categories(): array
    {
        return array_column($this->db->query("SELECT DISTINCT category FROM courses WHERE status='published' ORDER BY category")->fetchAll(), 'category');
    }
    public function bySlug(string $slug, bool $drafts = false): ?array
    {
        $sql = "SELECT * FROM courses WHERE slug = ?" . ($drafts ? '' : " AND status='published'");
        $st = $this->db->prepare($sql); $st->execute([$slug]);
        return $st->fetch() ?: null;
    }
    public function others(string $slug, int $limit = 3): array
    {
        $st = $this->db->prepare("SELECT " . self::COLS . " FROM courses WHERE status='published' AND slug <> ? ORDER BY featured DESC, sort LIMIT ?");
        $st->bindValue(1, $slug); $st->bindValue(2, $limit, PDO::PARAM_INT); $st->execute();
        return $st->fetchAll();
    }
    public function enroll(string $slug, array $d): bool
    {
        $email = strtolower(trim($d['email'] ?? ''));
        $name = trim($d['name'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        $c = $this->bySlug($slug, true);
        $this->db->prepare('INSERT INTO enrollments (course_id, course_slug, name, email, phone, note) VALUES (?,?,?,?,?,?)')
            ->execute([$c['id'] ?? null, $slug, $name, $email, trim($d['phone'] ?? ''), trim($d['note'] ?? '')]);
        return true;
    }

    public function save(array $d): string
    {
        $slug = slugify($d['slug'] ?: $d['title']);
        $now = date('Y-m-d H:i:s');
        $f = [
            'slug' => $slug, 'title' => $d['title'], 'summary' => $d['summary'] ?? '',
            'body_html' => $d['body_html'] ?? '', 'cover_url' => $d['cover_url'] ?: null, 'og_image' => $d['og_image'] ?: null,
            'category' => $d['category'] ?: 'Programme', 'level' => $d['level'] ?: 'All levels',
            'format' => $d['format'] ?: 'In-person', 'duration' => $d['duration'] ?? '', 'price' => $d['price'] ?: 'Free',
            'location' => $d['location'] ?: 'Alimosho, Lagos', 'gradient' => $d['gradient'] ?: 'g-gold',
            'outcomes' => $d['outcomes'] ?? '', 'cta_url' => $d['cta_url'] ?: null,
            'featured' => !empty($d['featured']) ? 1 : 0, 'status' => ($d['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
            'sort' => (int) ($d['sort'] ?? 0), 'updated_at' => $now,
        ];
        // Access model (only overwrite when provided, so partial saves are safe)
        if (array_key_exists('access_type', $d)) {
            $at = in_array($d['access_type'], ['open', 'tracked', 'membership', 'paid'], true) ? $d['access_type'] : 'open';
            $f['access_type'] = $at;
            $f['price_ngn'] = $at === 'paid' ? max(0, (int) ($d['price_ngn'] ?? 0)) : 0;
        }
        if (array_key_exists('instructor_id', $d)) { $f['instructor_id'] = $d['instructor_id'] !== null ? (int) $d['instructor_id'] : null; }
        $ex = $this->db->prepare('SELECT id FROM courses WHERE slug = ?'); $ex->execute([$slug]); $id = $ex->fetchColumn();
        if ($id) {
            $set = implode(', ', array_map(fn($k) => "$k=:$k", array_keys($f)));
            $this->db->prepare("UPDATE courses SET $set WHERE id=:id")->execute($f + ['id' => $id]);
        } else {
            $f['created_at'] = $now;
            $this->db->prepare('INSERT INTO courses (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_map(fn($k) => ":$k", array_keys($f))) . ')')->execute($f);
        }
        return $slug;
    }
    public function delete(string $slug): bool
    {
        return $this->db->prepare('DELETE FROM courses WHERE slug = ?')->execute([$slug]);
    }
}
