<?php
/**
 * lib/VolunteerProjects.php — which projects a volunteer serves, and as what.
 *
 * Afrovanguard's volunteers are not one pool: they serve Street-To-Stardom,
 * Techome, MediaPro, NextGen Genius… each in a different capacity (a mentor
 * on one, the media volunteer on another). So a volunteer is placed on a
 * PROJECT with a TYPE:
 *
 *   vol_projects     the projects (seeded from the site's projects; the
 *                    office adds more in the Studio)
 *   vol_assignments  one row per (volunteer, project): their type, since
 *                    when, who placed them, and whether it has ended
 *
 * NGG volunteers are on the NextGen Genius project by NGG's say-so (lib/
 * NggVolunteers), with the type their NGG role maps to; that placement is
 * read from the synced list, never stored twice. Teams and hours carry the
 * project they belong to (vol_teams.project_id, vol_hours.project_id), so a
 * volunteer's hours read per project.
 *
 * Placing somebody on a project makes them a volunteer (the roster in
 * lib/Volunteers); taking them off the roster ends their placements.
 */
declare(strict_types=1);

final class VolunteerProjects
{
    /** The capacities a volunteer serves in. Keys are stored; labels are shown. */
    public const TYPES = [
        'coordinator' => 'Coordinator',
        'mentor'      => 'Mentor',
        'facilitator' => 'Facilitator / instructor',
        'operations'  => 'Operations & admin',
        'events'      => 'Events & logistics',
        'media'       => 'Media & communications',
        'outreach'    => 'Outreach & community',
        'tech'        => 'Technology',
        'fundraising' => 'Fundraising & partnerships',
        'general'     => 'General volunteer',
    ];

    /** The site's own projects (partials/avh-chrome.php), seeded once by slug. */
    private const SEED = [
        ['afrovanguard', 'Afrovanguard (organisation-wide)', 'Work for the organisation as a whole.', '/', 'site'],
        ['ngg', 'Next Generation Genius', 'NGG — clubs, mentoring and the Summer School for teenagers.', 'https://next.afrovanguard.org.ng/', 'ngg'],
        ['ngv', 'NextGen Vanguard', 'The NGV leadership programme.', '/academy/ngv/', 'site'],
        ['sts', 'Street-To-Stardom', 'Talent development, from the street to the stage.', '/projects/sts/', 'site'],
        ['techhome', 'Techome', 'Technology skills and digital careers.', '/projects/techhome/', 'site'],
        ['mediapro', 'MediaPro', 'Media production and storytelling.', '/projects/mediapro/', 'site'],
        ['africa-gates', 'Africa GATES', 'Africa GATES.', 'https://afg.afrovanguard.org.ng', 'site'],
        ['bec', 'Business Executive Club', 'Business and enterprise.', '/projects/bec/', 'site'],
        ['career-hub', 'Career Hub', 'Careers, CVs and placements.', '/projects/career-hub/', 'site'],
        ['kap', 'Kingdom Advancement', 'Kingdom Advancement Project.', '/projects/kap/', 'site'],
    ];

    /** NGG's role → the type it is here. */
    private const NGG_TYPE = ['mentor' => 'mentor', 'instructor' => 'facilitator', 'admin' => 'operations', 'volunteer' => 'general'];

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        Volunteers::ensure();
        $pdo = Database::pdo();
        Database::execSchema($pdo, "
        CREATE TABLE IF NOT EXISTS vol_projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug VARCHAR(40) NOT NULL,
            name VARCHAR(120) NOT NULL,
            blurb VARCHAR(300) NOT NULL DEFAULT '',
            url VARCHAR(300) NOT NULL DEFAULT '',
            source VARCHAR(12) NOT NULL DEFAULT 'site',
            active INTEGER NOT NULL DEFAULT 1,
            sort INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS vol_assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            project_id INTEGER NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'general',
            status VARCHAR(12) NOT NULL DEFAULT 'active',
            note VARCHAR(300) NOT NULL DEFAULT '',
            added_by INTEGER NOT NULL DEFAULT 0,
            since VARCHAR(32) NOT NULL DEFAULT '',
            ended_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        Database::ensureIndex($pdo, 'uq_vol_projects_slug', 'vol_projects', 'slug', true);
        Database::ensureIndex($pdo, 'uq_vol_assign', 'vol_assignments', 'user_id, project_id', true);
        Database::ensureIndex($pdo, 'idx_vol_assign_project', 'vol_assignments', 'project_id, status');
        foreach (['vol_teams', 'vol_hours'] as $t) {
            try { if (!Database::columnExists($t, 'project_id')) $pdo->exec("ALTER TABLE $t ADD COLUMN project_id INTEGER NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
        }
        $sql = Database::insertIgnore('vol_projects', ['slug', 'name', 'blurb', 'url', 'source', 'active', 'sort', 'created_at']);
        $st = $pdo->prepare($sql);
        foreach (self::SEED as $i => [$slug, $name, $blurb, $url, $src]) {
            try { $st->execute([$slug, $name, $blurb, $url, $src, 1, $i * 10, gmdate('Y-m-d H:i:s')]); } catch (Throwable $e) {}
        }
    }

    public static function typeLabel(string $type): string { return self::TYPES[$type] ?? 'Volunteer'; }

    /* ══ Projects ═══════════════════════════════════════════════════════════ */

    /** Projects with how many volunteers each has (active placements + NGG's). */
    public static function projects(bool $activeOnly = true): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $rows = $pdo->query('SELECT p.*, (SELECT COUNT(*) FROM vol_assignments a WHERE a.project_id = p.id AND a.status = \'active\') AS volunteers FROM vol_projects p'
            . ($activeOnly ? ' WHERE p.active = 1' : '') . ' ORDER BY p.sort, p.name')->fetchAll(PDO::FETCH_ASSOC);
        $ngg = 0;
        if (class_exists('NggVolunteers')) { try { NggVolunteers::ensure(); $ngg = (int) $pdo->query("SELECT COUNT(*) FROM ngg_volunteers WHERE status = 'active'")->fetchColumn(); } catch (Throwable $e) {} }
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'slug' => (string) $r['slug'], 'name' => (string) $r['name'], 'blurb' => (string) $r['blurb'],
            'url' => (string) $r['url'], 'source' => (string) $r['source'], 'active' => (int) $r['active'] === 1,
            'volunteers' => (int) $r['volunteers'] + ($r['source'] === 'ngg' ? $ngg : 0)], $rows);
    }

    public static function bySlug(string $slug): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM vol_projects WHERE slug = ?');
        $st->execute([$slug]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function byId(int $id): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM vol_projects WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Add or edit a project (the office). The NGG project's identity is NGG's. */
    public static function saveProject(array $in, string $actor = 'admin'): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
        if (mb_strlen($name) < 2) return ['ok' => false, 'error' => 'Give the project a name.'];
        $blurb = mb_substr(trim((string) ($in['blurb'] ?? '')), 0, 300);
        $active = !isset($in['active']) || !empty($in['active']) ? 1 : 0;
        $id = (int) ($in['id'] ?? 0);
        if ($id > 0) {
            $p = self::byId($id);
            if (!$p) return ['ok' => false, 'error' => 'No such project.'];
            $pdo->prepare('UPDATE vol_projects SET name = ?, blurb = ?, active = ? WHERE id = ?')->execute([$name, $blurb, $active, $id]);
        } else {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'project';
            $base = $slug; $n = 2;
            while (self::bySlug($slug)) $slug = $base . '-' . $n++;
            $pdo->prepare('INSERT INTO vol_projects (slug, name, blurb, url, source, active, sort, created_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$slug, $name, $blurb, '', 'site', $active, 500, gmdate('Y-m-d H:i:s')]);
            $id = (int) $pdo->lastInsertId();
        }
        if (class_exists('AdminAudit')) { try { AdminAudit::log('members', 'volunteers.project', $name, $active ? 'saved' : 'saved (inactive)', null, $actor); } catch (Throwable $e) {} }
        return ['ok' => true, 'id' => $id, 'projects' => self::projects(false)];
    }

    /* ══ Placements ═════════════════════════════════════════════════════════ */

    /**
     * Place volunteers on a project as a type. Makes them volunteers if they
     * were not. Somebody already placed there has their type updated.
     */
    public static function assign(array $userIds, int $projectId, string $type, int $by, string $note = ''): array
    {
        self::ensure();
        $p = self::byId($projectId);
        if (!$p || (int) $p['active'] !== 1) return ['ok' => false, 'error' => 'Choose a project.'];
        if ((string) $p['source'] === 'ngg') return ['ok' => false, 'error' => 'NGG volunteers are placed by NGG — they appear here when NGG lists them.'];
        if (!isset(self::TYPES[$type])) return ['ok' => false, 'error' => 'Choose what they volunteer as.'];
        $pdo = Database::pdo();
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($i) => $i > 0)));
        $promoted = Volunteers::promote($ids, $by, $note !== '' ? $note : (string) $p['name']);
        $placed = 0; $updated = 0; $now = gmdate('Y-m-d H:i:s');
        foreach ($ids as $id) {
            $st = $pdo->prepare('SELECT id, status, type FROM vol_assignments WHERE user_id = ? AND project_id = ?');
            $st->execute([$id, $projectId]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) {
                $pdo->prepare('INSERT INTO vol_assignments (user_id, project_id, type, status, note, added_by, since) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$id, $projectId, $type, 'active', mb_substr($note, 0, 300), $by, $now]);
                $placed++;
            } elseif ($cur['status'] !== 'active' || $cur['type'] !== $type) {
                $pdo->prepare("UPDATE vol_assignments SET type = ?, status = 'active', ended_at = '', added_by = ?, since = CASE WHEN status = 'active' THEN since ELSE ? END WHERE id = ?")
                    ->execute([$type, $by, $now, (int) $cur['id']]);
                $cur['status'] === 'active' ? $updated++ : $placed++;
            }
        }
        return ['ok' => true, 'placed' => $placed, 'updated' => $updated, 'new_volunteers' => (int) $promoted['added']];
    }

    /** End one placement. They stay a volunteer (other projects, the roster). */
    public static function unassign(int $userId, int $projectId, int $by): array
    {
        self::ensure();
        Database::pdo()->prepare("UPDATE vol_assignments SET status = 'ended', ended_at = ? WHERE user_id = ? AND project_id = ? AND status = 'active'")
            ->execute([gmdate('Y-m-d H:i:s'), $userId, $projectId]);
        return ['ok' => true];
    }

    /** End every placement (the roster removal). */
    public static function endAll(int $userId): void
    {
        self::ensure();
        Database::pdo()->prepare("UPDATE vol_assignments SET status = 'ended', ended_at = ? WHERE user_id = ? AND status = 'active'")->execute([gmdate('Y-m-d H:i:s'), $userId]);
    }

    /**
     * The projects somebody serves, each with their type: their placements,
     * and NGG if NGG lists them. [{project_id, slug, name, type, type_label, since, source}]
     */
    public static function forUser(array $u): array
    {
        self::ensure();
        $uid = (int) ($u['id'] ?? 0);
        $out = [];
        if ($uid > 0) {
            $st = Database::pdo()->prepare("SELECT a.type, a.since, p.id, p.slug, p.name FROM vol_assignments a JOIN vol_projects p ON p.id = a.project_id WHERE a.user_id = ? AND a.status = 'active' ORDER BY p.sort, p.name");
            $st->execute([$uid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['project_id' => (int) $r['id'], 'slug' => (string) $r['slug'], 'name' => (string) $r['name'], 'type' => (string) $r['type'],
                          'type_label' => self::typeLabel((string) $r['type']), 'since' => substr((string) $r['since'], 0, 10), 'source' => 'placed'];
            }
        }
        if (class_exists('NggVolunteers')) {
            try {
                if ($n = NggVolunteers::forUser($u)) {
                    $p = self::bySlug('ngg');
                    $type = self::NGG_TYPE[(string) $n['role']] ?? 'general';
                    if ($p) array_unshift($out, ['project_id' => (int) $p['id'], 'slug' => 'ngg', 'name' => (string) $p['name'], 'type' => $type,
                        'type_label' => trim((string) ($n['role_label'] ?? '')) ?: self::typeLabel($type), 'since' => substr((string) $n['joined_at'], 0, 10),
                        'source' => 'ngg', 'centre' => (string) $n['centre']]);
                }
            } catch (Throwable $e) {}
        }
        return $out;
    }

    /** The project id for the NGG project (where NGG sessions are credited). */
    public static function nggId(): int
    {
        $p = self::bySlug('ngg');
        return $p ? (int) $p['id'] : 0;
    }

    /* ══ Hours per project ══════════════════════════════════════════════════ */

    /** A volunteer's hours, split by project (0 = not tied to one). */
    public static function hoursByProject(int $uid): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT h.project_id, p.name, SUM(h.minutes) m FROM vol_hours h LEFT JOIN vol_projects p ON p.id = h.project_id WHERE h.user_id = ? GROUP BY h.project_id, p.name ORDER BY m DESC');
        $st->execute([$uid]);
        return array_map(static fn($r) => ['project_id' => (int) $r['project_id'], 'name' => (string) ($r['name'] ?? '') ?: 'Other', 'hours' => round((int) $r['m'] / 60, 1)], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Volunteers placed on a project (for the Studio and the People page). */
    public static function volunteersOf(int $projectId): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT u.id, u.name, u.email, a.type, a.since FROM vol_assignments a JOIN lms_users u ON u.id = a.user_id WHERE a.project_id = ? AND a.status = 'active' ORDER BY u.name");
        $st->execute([$projectId]);
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'], 'type' => (string) $r['type'],
            'type_label' => self::typeLabel((string) $r['type']), 'since' => substr((string) $r['since'], 0, 10)], $st->fetchAll(PDO::FETCH_ASSOC));
    }
}
