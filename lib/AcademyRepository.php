<?php
/**
 * lib/AcademyRepository.php — all course/enrolment queries in one place.
 */
declare(strict_types=1);

final class AcademyRepository
{
    private PDO $db;
    public function __construct(?PDO $pdo = null) { $this->db = $pdo ?? Database::pdo(); }

    private const COLS = 'id, slug, title, summary, cover_url, category, level, format, duration, price, location, gradient, featured, status, sort, outcomes, access_type, price_ngn, pass_code, cover_is_dark';

    private bool $ready = false;
    private ?array $colSet = null;

    /**
     * Make sure the columns this class selects actually exist.
     *
     * `COLS` names columns the access model added long after the LMS shipped.
     * They are provisioned by `Database`'s academy migration step — but that step
     * is version-stamped, so a database whose stamp already matched never ran it,
     * and then every academy query dies on `no such column: access_type`: the
     * catalogue will not list, a course will not save, and the curriculum cannot be
     * reached because you can never click through to it.
     *
     * So the schema is repaired when it is found wanting, rather than assumed.
     */
    private function ensure(): void
    {
        if ($this->ready) return; $this->ready = true;
        try {
            if (Database::columnExists('courses', 'access_type')) return;
            Database::ensureAcademySchema();
            $this->colSet = null;                       // it may have just changed
        } catch (Throwable $e) { error_log('[academy] schema heal: ' . $e->getMessage()); }
    }

    /** The `courses` columns this database actually has, as a name => true set. */
    private function courseCols(): array
    {
        $this->ensure();
        if ($this->colSet !== null) return $this->colSet;
        $set = [];
        try {
            foreach (['id', 'slug', 'title', 'summary', 'body_html', 'cover_url', 'og_image', 'category', 'level',
                      'format', 'duration', 'price', 'location', 'gradient', 'featured', 'status', 'sort', 'outcomes',
                      'cta_url', 'access_type', 'price_ngn', 'pass_code', 'cover_is_dark', 'instructor_id',
                      'created_at', 'updated_at'] as $c) {
                if (Database::columnExists('courses', $c)) $set[$c] = true;
            }
        } catch (Throwable $e) { error_log('[academy] column probe: ' . $e->getMessage()); }
        return $this->colSet = $set;
    }

    /**
     * `COLS`, minus anything this database does not have.
     *
     * If the heal above could not run — no ALTER privilege on shared hosting, say —
     * the academy still works with the columns that are there instead of returning
     * a 500 for every read.
     */
    private function cols(): string
    {
        $have = $this->courseCols();
        $keep = array_filter(array_map('trim', explode(',', self::COLS)), fn($c) => isset($have[$c]));
        return implode(', ', $keep);
    }

    /** Drop any field whose column is absent, so a write cannot name one. */
    private function onlyExistingCols(array $fields): array
    {
        $have = $this->courseCols();
        return array_intersect_key($fields, $have);
    }

    public function all(): array
    {
        return $this->db->query("SELECT " . $this->cols() . " FROM courses WHERE status='published' ORDER BY featured DESC, sort ASC, title ASC")->fetchAll();
    }
    public function allForAdmin(): array
    {
        return $this->db->query("SELECT " . $this->cols() . ", updated_at FROM courses ORDER BY sort ASC, updated_at DESC")->fetchAll();
    }
    public function featured(): ?array
    {
        $r = $this->db->query("SELECT " . $this->cols() . " FROM courses WHERE featured=1 AND status='published' ORDER BY sort LIMIT 1")->fetch();
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
        $st = $this->db->prepare("SELECT " . $this->cols() . " FROM courses WHERE status='published' AND slug <> ? ORDER BY featured DESC, sort LIMIT ?");
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
        $this->ensure();
        $slug = slugify(($d['slug'] ?? '') ?: ($d['title'] ?? ''));
        $now = date('Y-m-d H:i:s');
        // Null-coalesce every read so a partial save (e.g. just title + status) is safe.
        $f = [
            'slug' => $slug, 'title' => $d['title'] ?? '', 'summary' => $d['summary'] ?? '',
            'body_html' => $d['body_html'] ?? '', 'cover_url' => ($d['cover_url'] ?? '') ?: null, 'og_image' => ($d['og_image'] ?? '') ?: null,
            'category' => ($d['category'] ?? '') ?: 'Programme', 'level' => ($d['level'] ?? '') ?: 'All levels',
            'format' => ($d['format'] ?? '') ?: 'In-person', 'duration' => $d['duration'] ?? '', 'price' => ($d['price'] ?? '') ?: 'Free',
            'location' => ($d['location'] ?? '') ?: 'Alimosho, Lagos', 'gradient' => ($d['gradient'] ?? '') ?: 'g-gold',
            'outcomes' => $d['outcomes'] ?? '', 'cta_url' => ($d['cta_url'] ?? '') ?: null,
            'featured' => !empty($d['featured']) ? 1 : 0, 'status' => ($d['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
            'sort' => (int) ($d['sort'] ?? 0), 'updated_at' => $now,
        ];
        // Access model (only overwrite when provided, so partial saves are safe)
        if (array_key_exists('access_type', $d)) {
            $at = in_array($d['access_type'], ['open', 'tracked', 'membership', 'paid', 'restricted'], true) ? $d['access_type'] : 'open';
            $f['access_type'] = $at;
            $f['price_ngn'] = $at === 'paid' ? max(0, (int) ($d['price_ngn'] ?? 0)) : 0;
            // Restricted courses may name a pass that unlocks them (else access is
            // the explicit allowlist only). Slugified for tidy, shareable codes.
            if (Database::columnExists('courses', 'pass_code')) {
                $f['pass_code'] = $at === 'restricted' ? slugify((string) ($d['pass_code'] ?? '')) : '';
            }
        }
        if (array_key_exists('instructor_id', $d)) { $f['instructor_id'] = $d['instructor_id'] !== null ? (int) $d['instructor_id'] : null; }
        // Colour-aware cover: precompute luminance of the chip region (top) so the
        // catalogue overlay text picks a legible colour with no client work.
        if (array_key_exists('cover_url', $d) && Database::columnExists('courses', 'cover_is_dark')) {
            $f['cover_is_dark'] = $f['cover_url'] ? (av_cover_is_dark((string) $f['cover_url'], 'top') ?? -1) : -1;
        }
        // Resolve create-vs-edit by the ORIGINAL slug the editor was opened with,
        // so a new course can never silently overwrite an existing one, and an
        // edit can rename safely. De-dup the slug against OTHER courses.
        $editing = trim((string) ($d['_editing'] ?? ''));
        $exId = null;
        if ($editing !== '') { $e = $this->db->prepare('SELECT id FROM courses WHERE slug = ?'); $e->execute([$editing]); $exId = $e->fetchColumn() ?: null; }
        $dupe = $this->db->prepare('SELECT id FROM courses WHERE slug = ? AND slug <> ?');
        $base = $slug; $i = 2;
        while (true) { $dupe->execute([$slug, $editing]); if (!$dupe->fetchColumn()) break; $slug = $base . '-' . $i++; }
        $f['slug'] = $slug;
        if ($exId) {
            // Never name a column this database does not have: on a deployment that
            // missed the access-model migration that is the difference between a
            // course saving and the Studio reporting a server error.
            $f = $this->onlyExistingCols($f);
            $set = implode(', ', array_map(fn($k) => "$k=:$k", array_keys($f)));
            $this->db->prepare("UPDATE courses SET $set WHERE id=:id")->execute($f + ['id' => $exId]);
        } else {
            $f['created_at'] = $now;
            $f = $this->onlyExistingCols($f);
            $this->db->prepare('INSERT INTO courses (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_map(fn($k) => ":$k", array_keys($f))) . ')')->execute($f);
        }
        return $slug;
    }

    /** Deep-copy a course as a new draft (modules + lessons included). Returns the new slug or null. */
    public function duplicate(string $slug): ?string
    {
        $src = $this->bySlug($slug, true);
        if (!$src) return null;
        $now = date('Y-m-d H:i:s');
        $newSlug = $slug . '-copy'; $base = $newSlug; $i = 2;
        $chk = $this->db->prepare('SELECT 1 FROM courses WHERE slug = ?');
        while (true) { $chk->execute([$newSlug]); if (!$chk->fetchColumn()) break; $newSlug = $base . '-' . $i++; }
        $have = $this->courseCols();
        $cols = array_filter(
            ['slug', 'title', 'summary', 'body_html', 'cover_url', 'og_image', 'category', 'level', 'format', 'duration',
             'price', 'location', 'gradient', 'outcomes', 'cta_url', 'access_type', 'price_ngn', 'instructor_id'],
            fn($c) => isset($have[$c])
        );
        $vals = [];
        foreach ($cols as $c) { $vals[$c] = $src[$c] ?? null; }
        $vals['slug'] = $newSlug; $vals['title'] = ($src['title'] ?? 'Course') . ' (copy)';
        $vals['status'] = 'draft'; $vals['featured'] = 0; $vals['sort'] = (int) ($src['sort'] ?? 0);
        $vals['created_at'] = $now; $vals['updated_at'] = $now;
        $this->db->prepare('INSERT INTO courses (' . implode(',', array_keys($vals)) . ') VALUES (' . implode(',', array_map(fn($k) => ":$k", array_keys($vals))) . ')')->execute($vals);
        $newId = (int) $this->db->lastInsertId();
        $mods = $this->db->prepare('SELECT * FROM modules WHERE course_id = ? ORDER BY position, id'); $mods->execute([(int) $src['id']]);
        $insMod = $this->db->prepare('INSERT INTO modules (course_id, title, position) VALUES (?,?,?)');
        $lq = $this->db->prepare('SELECT * FROM lessons WHERE module_id = ? ORDER BY position, id');
        $insLes = $this->db->prepare('INSERT INTO lessons (module_id, course_id, slug, title, body_html, video_url, duration_min, is_preview, position, created_at, updated_at, quiz_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($mods->fetchAll() as $m) {
            $insMod->execute([$newId, $m['title'], $m['position']]);
            $newMod = (int) $this->db->lastInsertId();
            $lq->execute([$m['id']]);
            foreach ($lq->fetchAll() as $l) {
                $insLes->execute([$newMod, $newId, $l['slug'], $l['title'], $l['body_html'], $l['video_url'], $l['duration_min'], $l['is_preview'], $l['position'], $now, $now, $l['quiz_json']]);
            }
        }
        return $newSlug;
    }

    /** Toggle publish/unpublish without touching other fields (safe retirement). */
    public function setStatus(string $slug, string $status): bool
    {
        $status = $status === 'published' ? 'published' : 'draft';
        return $this->db->prepare('UPDATE courses SET status = ?, updated_at = ? WHERE slug = ?')->execute([$status, date('Y-m-d H:i:s'), $slug]);
    }
    public function delete(string $slug): bool
    {
        return $this->db->prepare('DELETE FROM courses WHERE slug = ?')->execute([$slug]);
    }
}
