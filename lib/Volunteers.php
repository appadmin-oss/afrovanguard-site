<?php
/**
 * lib/Volunteers.php — the volunteer side of the portal: teams, roles, hours.
 *
 * Until now a volunteer was a word on the public People page (team.tier) and
 * an @afrovanguard.org.ng sign-in. Neither said which TEAM somebody serves on,
 * who leads it, who writes its minutes, or how much time anybody gives. This
 * adds exactly that, and no more:
 *
 *   · TEAMS (units, committees, project crews) with members in one of three
 *     roles — lead, secretary, member. A team's meetings invite the whole
 *     team, and its secretary is who the minutes are asked of.
 *   · HOURS, mostly logged by themselves: attending a meeting whose minutes
 *     are published counts its length for everyone marked present. Anything
 *     else (an outreach day, an event) is logged by the volunteer.
 *   · The HUB — one call that answers "what do I need to do": the next
 *     meetings to answer, minutes waiting on me, my open actions, my teams,
 *     my hours.
 *
 * Who is a volunteer: an organisation member (LmsAuth::isOrgMember — an
 * elevated role or an org email), anybody on a volunteer team, or anybody the
 * People page lists as a volunteer. Teams are created by coordinators and up;
 * a team's lead manages its members.
 */
declare(strict_types=1);

final class Volunteers
{
    public const ROLES = ['lead' => 'Lead', 'secretary' => 'Secretary', 'member' => 'Member'];
    public const MAX_HOURS_DAY = 16;

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        $pdo = Database::pdo();
        Database::execSchema($pdo, "
        CREATE TABLE IF NOT EXISTS vol_teams (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(120) NOT NULL,
            purpose VARCHAR(400) NOT NULL DEFAULT '',
            created_by INTEGER NOT NULL DEFAULT 0,
            archived INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS vol_team_members (
            team_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role VARCHAR(12) NOT NULL DEFAULT 'member',
            joined_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS vol_hours (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            day VARCHAR(10) NOT NULL,
            minutes INTEGER NOT NULL DEFAULT 0,
            activity VARCHAR(200) NOT NULL DEFAULT '',
            source VARCHAR(16) NOT NULL DEFAULT 'manual',
            source_ref VARCHAR(64) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        try { $pdo->exec('CREATE UNIQUE INDEX uq_vol_team_member ON vol_team_members (team_id, user_id)'); } catch (Throwable $e) {}
        try { $pdo->exec('CREATE INDEX idx_vol_team_user ON vol_team_members (user_id)'); } catch (Throwable $e) {}
        try { $pdo->exec('CREATE INDEX idx_vol_hours_user ON vol_hours (user_id, day)'); } catch (Throwable $e) {}
        /* An automated entry (a meeting attended) is one row however often the
           thing that logs it runs; a manual one carries an empty ref and is not
           constrained. */
        try { $pdo->exec("CREATE UNIQUE INDEX uq_vol_hours_src ON vol_hours (user_id, source, source_ref)"); } catch (Throwable $e) {}
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    private static function user(int $uid): ?array
    {
        $st = Database::pdo()->prepare('SELECT id, name, email, role FROM lms_users WHERE id = ?');
        $st->execute([$uid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /* ══ Who ════════════════════════════════════════════════════════════════ */

    public static function isVolunteer(array $u): bool
    {
        if (empty($u['id'])) return false;
        if (LmsAuth::isOrgMember($u)) return true;
        self::ensure();
        $st = Database::pdo()->prepare('SELECT 1 FROM vol_team_members m JOIN vol_teams t ON t.id = m.team_id WHERE m.user_id = ? AND t.archived = 0 LIMIT 1');
        $st->execute([(int) $u['id']]);
        if ($st->fetchColumn()) return true;
        try {
            $st = Database::pdo()->prepare("SELECT 1 FROM team WHERE LOWER(email) = ? AND tier = 'volunteer' LIMIT 1");
            $st->execute([strtolower((string) ($u['email'] ?? ''))]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    /** Coordinators and up start teams and manage any of them. */
    public static function canRunTeams(array $u): bool
    {
        return LmsAuth::rank((string) ($u['role'] ?? '')) >= LmsAuth::ROLE_RANK['coordinator'];
    }

    public static function roleIn(int $teamId, int $uid): string
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT role FROM vol_team_members WHERE team_id = ? AND user_id = ?');
        $st->execute([$teamId, $uid]);
        return (string) ($st->fetchColumn() ?: '');
    }

    public static function canManageTeam(array $u, int $teamId): bool
    {
        return self::canRunTeams($u) || self::roleIn($teamId, (int) $u['id']) === 'lead';
    }

    /* ══ Teams ══════════════════════════════════════════════════════════════ */

    public static function createTeam(array $u, string $name, string $purpose = ''): array
    {
        self::ensure();
        if (!self::canRunTeams($u)) return ['ok' => false, 'error' => 'Teams are started by a coordinator.'];
        $name = mb_substr(trim($name), 0, 120);
        if ($name === '') return ['ok' => false, 'error' => 'Give the team a name.'];
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id FROM vol_teams WHERE LOWER(name) = ? AND archived = 0');
        $st->execute([mb_strtolower($name)]);
        if ($st->fetchColumn()) return ['ok' => false, 'error' => 'There is already a team called ' . $name . '.'];
        $pdo->prepare('INSERT INTO vol_teams (name, purpose, created_by, created_at) VALUES (?,?,?,?)')
            ->execute([$name, mb_substr(trim($purpose), 0, 400), (int) $u['id'], self::now()]);
        $id = (int) $pdo->lastInsertId();
        self::audit('vol.team.create', 'team:' . $id, $name);
        return ['ok' => true, 'id' => $id, 'team' => self::team($id)];
    }

    public static function updateTeam(array $u, int $teamId, array $in): array
    {
        self::ensure();
        if (!self::team($teamId)) return ['ok' => false, 'error' => 'No such team.'];
        if (!self::canManageTeam($u, $teamId)) return ['ok' => false, 'error' => 'Only the team lead or a coordinator can change the team.'];
        $sets = []; $args = [];
        if (isset($in['name'])) { $n = mb_substr(trim((string) $in['name']), 0, 120); if ($n === '') return ['ok' => false, 'error' => 'A team needs a name.']; $sets[] = 'name = ?'; $args[] = $n; }
        if (isset($in['purpose'])) { $sets[] = 'purpose = ?'; $args[] = mb_substr(trim((string) $in['purpose']), 0, 400); }
        if (isset($in['archived']) && self::canRunTeams($u)) { $sets[] = 'archived = ?'; $args[] = !empty($in['archived']) ? 1 : 0; }
        if (!$sets) return ['ok' => true, 'team' => self::team($teamId)];
        $args[] = $teamId;
        Database::pdo()->prepare('UPDATE vol_teams SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        self::audit('vol.team.update', 'team:' . $teamId, implode(', ', array_keys(array_intersect_key($in, ['name' => 1, 'purpose' => 1, 'archived' => 1]))));
        return ['ok' => true, 'team' => self::team($teamId)];
    }

    /** Add somebody (by id or email), or change their role. One secretary and any number of leads. */
    public static function setMember(array $u, int $teamId, string $who, string $role = 'member'): array
    {
        self::ensure();
        if (!self::team($teamId)) return ['ok' => false, 'error' => 'No such team.'];
        if (!self::canManageTeam($u, $teamId)) return ['ok' => false, 'error' => 'Only the team lead or a coordinator can change who is on the team.'];
        if (!isset(self::ROLES[$role])) return ['ok' => false, 'error' => 'A role is lead, secretary or member.'];
        $who = trim($who);
        $st = Database::pdo()->prepare(ctype_digit($who) ? 'SELECT id, name FROM lms_users WHERE id = ?' : 'SELECT id, name FROM lms_users WHERE LOWER(email) = ?');
        $st->execute([ctype_digit($who) ? (int) $who : strtolower($who)]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return ['ok' => false, 'error' => 'Nobody has an account with that email. They sign in to the portal once, then can be added.'];
        $pid = (int) $p['id'];
        $pdo = Database::pdo();
        if ($role === 'secretary') $pdo->prepare("UPDATE vol_team_members SET role = 'member' WHERE team_id = ? AND role = 'secretary' AND user_id <> ?")->execute([$teamId, $pid]);
        $was = self::roleIn($teamId, $pid);
        if ($was === '') {
            $pdo->prepare('INSERT INTO vol_team_members (team_id, user_id, role, joined_at) VALUES (?,?,?,?)')->execute([$teamId, $pid, $role, self::now()]);
            /* Invited to the team's meetings still to come. */
            if (class_exists('MeetingMinutes')) { try { MeetingMinutes::syncTeamAttendees($teamId); } catch (Throwable $e) {} }
            $t = self::team($teamId);
            if (class_exists('Notifications') && $pid !== (int) $u['id']) {
                try { Notifications::push($pid, 'volunteer', 'You were added to ' . $t['name'], 'As ' . strtolower(self::ROLES[$role]) . '. Its meetings and minutes are in your Volunteer hub.', '/portal/#volunteer', 'volteam:' . $teamId . ':' . $pid); } catch (Throwable $e) {}
            }
        } else {
            if ($was === 'lead' && $role !== 'lead' && self::leadCount($teamId) <= 1 && !self::canRunTeams($u)) {
                return ['ok' => false, 'error' => 'A team keeps at least one lead. Make somebody else lead first.'];
            }
            $pdo->prepare('UPDATE vol_team_members SET role = ? WHERE team_id = ? AND user_id = ?')->execute([$role, $teamId, $pid]);
        }
        self::audit('vol.team.member', 'team:' . $teamId, $p['name'] . ' → ' . $role);
        return ['ok' => true, 'team' => self::team($teamId)];
    }

    public static function removeMember(array $u, int $teamId, int $uid): array
    {
        self::ensure();
        $self = $uid === (int) $u['id'];
        if (!$self && !self::canManageTeam($u, $teamId)) return ['ok' => false, 'error' => 'Only the team lead or a coordinator can remove somebody.'];
        if (self::roleIn($teamId, $uid) === 'lead' && self::leadCount($teamId) <= 1 && !self::canRunTeams($u)) {
            return ['ok' => false, 'error' => 'The team\'s only lead cannot leave. Make somebody else lead first.'];
        }
        Database::pdo()->prepare('DELETE FROM vol_team_members WHERE team_id = ? AND user_id = ?')->execute([$teamId, $uid]);
        self::audit('vol.team.remove', 'team:' . $teamId, 'user ' . $uid . ($self ? ' (left)' : ''));
        return ['ok' => true, 'team' => self::team($teamId)];
    }

    private static function leadCount(int $teamId): int
    {
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM vol_team_members WHERE team_id = ? AND role = 'lead'");
        $st->execute([$teamId]);
        return (int) $st->fetchColumn();
    }

    public static function team(int $id): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM vol_teams WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) return null;
        $m = Database::pdo()->prepare("SELECT u.id, u.name, u.email, m.role FROM vol_team_members m JOIN lms_users u ON u.id = m.user_id WHERE m.team_id = ?
                                       ORDER BY CASE m.role WHEN 'lead' THEN 0 WHEN 'secretary' THEN 1 ELSE 2 END, u.name");
        $m->execute([$id]);
        return ['id' => (int) $t['id'], 'name' => (string) $t['name'], 'purpose' => (string) $t['purpose'], 'archived' => (int) $t['archived'] === 1,
                'members' => array_map(static fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'], 'role' => (string) $r['role']], $m->fetchAll(PDO::FETCH_ASSOC))];
    }

    /** The teams somebody is on — or, for a coordinator, every live team. */
    public static function teamsFor(array $u, bool $all = false): array
    {
        self::ensure();
        $uid = (int) $u['id'];
        $sql = $all && self::canRunTeams($u)
            ? 'SELECT id FROM vol_teams WHERE archived = 0 ORDER BY name'
            : 'SELECT t.id FROM vol_teams t JOIN vol_team_members m ON m.team_id = t.id WHERE m.user_id = ? AND t.archived = 0 ORDER BY t.name';
        $st = Database::pdo()->prepare($sql);
        $st->execute($all && self::canRunTeams($u) ? [] : [$uid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $t = self::team((int) $id);
            if (!$t) continue;
            $t['my_role'] = self::roleIn((int) $id, $uid);
            $t['can_manage'] = self::canManageTeam($u, (int) $id);
            $out[] = $t;
        }
        return $out;
    }

    /** Volunteers by name or email, for "add to team" and "who owns this action". */
    public static function people(string $q, int $limit = 10): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $like = '%' . mb_strtolower($q) . '%';
        $st = Database::pdo()->prepare("SELECT id, name, email FROM lms_users WHERE (LOWER(name) LIKE ? OR LOWER(email) LIKE ?) AND COALESCE(status, 'active') <> 'suspended' ORDER BY name LIMIT " . max(1, min(25, $limit)));
        $st->execute([$like, $like]);
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'email' => (string) $r['email']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /* ══ Hours ══════════════════════════════════════════════════════════════ */

    /** Hours logged by a volunteer for something done outside a meeting. */
    public static function logHours(int $uid, string $day, $hours, string $activity): array
    {
        self::ensure();
        $day = trim($day) === '' ? (function_exists('av_today_tz') ? av_today_tz() : date('Y-m-d')) : trim($day);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) return ['ok' => false, 'error' => 'Pick the day.'];
        if ($day > (function_exists('av_today_tz') ? av_today_tz() : date('Y-m-d'))) return ['ok' => false, 'error' => 'Hours are logged for a day that has happened.'];
        $min = (int) round((float) $hours * 60);
        if ($min < 15 || $min > self::MAX_HOURS_DAY * 60) return ['ok' => false, 'error' => 'Between a quarter of an hour and ' . self::MAX_HOURS_DAY . ' hours.'];
        $activity = mb_substr(trim($activity), 0, 200);
        if ($activity === '') return ['ok' => false, 'error' => 'Say what you did.'];
        Database::pdo()->prepare("INSERT INTO vol_hours (user_id, day, minutes, activity, source, source_ref, created_at) VALUES (?,?,?,?,'manual',?,?)")
            ->execute([$uid, $day, $min, $activity, 'm' . bin2hex(random_bytes(6)), self::now()]);
        return ['ok' => true, 'hours' => self::hoursFor($uid)];
    }

    /** Logged by the system — once per (person, source, ref), however often it runs. */
    public static function autoHours(int $uid, string $day, int $minutes, string $activity, string $source, string $ref): bool
    {
        self::ensure();
        if ($uid <= 0 || $minutes <= 0) return false;
        try {
            $sql = Database::insertIgnore('vol_hours', ['user_id', 'day', 'minutes', 'activity', 'source', 'source_ref', 'created_at']);
            $st = Database::pdo()->prepare($sql);
            $st->execute([$uid, $day, min($minutes, self::MAX_HOURS_DAY * 60), mb_substr($activity, 0, 200), $source, mb_substr($ref, 0, 64), self::now()]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) { error_log('[volunteers] hours: ' . $e->getMessage()); return false; }
    }

    public static function removeHours(int $uid, int $id): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("DELETE FROM vol_hours WHERE id = ? AND user_id = ? AND source = 'manual'");
        $st->execute([$id, $uid]);
        return $st->rowCount() ? ['ok' => true, 'hours' => self::hoursFor($uid)] : ['ok' => false, 'error' => 'Only hours you logged yourself can be removed.'];
    }

    public static function hoursFor(int $uid): array
    {
        self::ensure();
        $month = substr(function_exists('av_today_tz') ? av_today_tz() : date('Y-m-d'), 0, 7);
        $year = substr($month, 0, 4);
        $pdo = Database::pdo();
        $sum = static function (string $prefix) use ($pdo, $uid): float {
            $st = $pdo->prepare('SELECT COALESCE(SUM(minutes), 0) FROM vol_hours WHERE user_id = ? AND day LIKE ?');
            $st->execute([$uid, $prefix . '%']);
            return round((int) $st->fetchColumn() / 60, 1);
        };
        $st = $pdo->prepare('SELECT id, day, minutes, activity, source FROM vol_hours WHERE user_id = ? ORDER BY day DESC, id DESC LIMIT 12');
        $st->execute([$uid]);
        return ['month' => $sum($month), 'year' => $sum($year), 'total' => $sum(''),
                'recent' => array_map(static fn($r) => ['id' => (int) $r['id'], 'day' => (string) $r['day'], 'hours' => round((int) $r['minutes'] / 60, 2), 'activity' => (string) $r['activity'], 'source' => (string) $r['source']], $st->fetchAll(PDO::FETCH_ASSOC))];
    }

    /** Hours per team member this month, for a lead. */
    public static function teamHours(int $teamId): array
    {
        self::ensure();
        $month = substr(function_exists('av_today_tz') ? av_today_tz() : date('Y-m-d'), 0, 7);
        $st = Database::pdo()->prepare('SELECT u.id, u.name, COALESCE(SUM(h.minutes), 0) m FROM vol_team_members tm JOIN lms_users u ON u.id = tm.user_id
                                        LEFT JOIN vol_hours h ON h.user_id = u.id AND h.day LIKE ? WHERE tm.team_id = ? GROUP BY u.id, u.name ORDER BY m DESC, u.name');
        $st->execute([$month . '%', $teamId]);
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'hours' => round((int) $r['m'] / 60, 1)], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /* ══ The hub ════════════════════════════════════════════════════════════ */

    /** Everything the Volunteer hub shows, for one person, in one call. */
    public static function hub(array $u): array
    {
        $uid = (int) $u['id'];
        $teams = self::teamsFor($u);
        $meet = MeetingMinutes::forHub($u);
        $actions = [];
        if (class_exists('Commitments')) {
            foreach (Commitments::forMember($uid, 'open', 30) as $c) {
                $actions[] = ['id' => (int) $c['id'], 'title' => (string) $c['title'], 'due' => (string) $c['due'],
                              'overdue' => (string) $c['due'] !== '' && (string) $c['due'] < gmdate('Y-m-d'), 'from' => MeetingMinutes::sourceTitle($c)];
            }
        }
        return ['ok' => true, 'me' => ['id' => $uid, 'name' => (string) $u['name'], 'can_run_teams' => self::canRunTeams($u)],
                'teams' => $teams, 'all_teams' => self::canRunTeams($u) ? self::teamsFor($u, true) : [],
                'upcoming' => $meet['upcoming'], 'to_write' => $meet['to_write'], 'to_approve' => $meet['to_approve'], 'published' => $meet['published'],
                'actions' => $actions, 'hours' => self::hoursFor($uid),
                'counts' => ['rsvp' => count(array_filter($meet['upcoming'], static fn($m) => $m['rsvp'] === '')), 'minutes' => count($meet['to_write']) + count($meet['to_approve']),
                             'actions' => count($actions), 'overdue' => count(array_filter($actions, static fn($a) => $a['overdue']))]];
    }

    /** The number on the nav item: things waiting on this person. */
    public static function waitingCount(array $u): int
    {
        try { $h = self::hub($u); return $h['counts']['rsvp'] + $h['counts']['minutes'] + $h['counts']['overdue']; } catch (Throwable $e) { return 0; }
    }

    private static function audit(string $action, string $target, string $detail): void
    {
        if (!class_exists('AdminAudit')) return;
        try { AdminAudit::log('volunteers', $action, $target, $detail, null, function_exists('av_admin_actor') ? av_admin_actor() : 'member'); } catch (Throwable $e) {}
    }
}
