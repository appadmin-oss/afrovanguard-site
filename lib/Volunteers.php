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
        /* An NGG volunteer, by the email NGG knows them by (lib/NggVolunteers). */
        if (class_exists('NggVolunteers')) { try { if (NggVolunteers::forUser($u)) return true; } catch (Throwable $e) {} }
        try {
            $st = Database::pdo()->prepare("SELECT 1 FROM team WHERE LOWER(email) = ? AND tier = 'volunteer' LIMIT 1");
            $st->execute([strtolower((string) ($u['email'] ?? ''))]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    /**
     * Everything this site knows makes somebody a volunteer, in words they
     * would recognise: "NGG volunteer — Mentor, Egbeda", "Outreach team —
     * secretary", "Afrovanguard member". Empty for somebody who is not one.
     */
    public static function identity(array $u): array
    {
        self::ensure();
        $out = [];
        if (class_exists('NggVolunteers')) {
            try {
                if ($n = NggVolunteers::forUser($u)) {
                    $out[] = ['source' => 'ngg', 'label' => 'NextGen Genius volunteer', 'detail' => trim(($n['role_label'] ?: ucfirst((string) $n['role'])) . ((string) $n['centre'] !== '' ? ', ' . $n['centre'] : ''), ', '),
                              'since' => substr((string) $n['joined_at'], 0, 10), 'hours' => (int) $n['hours'], 'sessions' => (int) $n['sessions'], 'level' => (string) $n['level'],
                              'safeguarding' => (int) $n['safeguarding_cleared'] === 1, 'photo' => (string) $n['photo_url']];
                }
            } catch (Throwable $e) {}
        }
        foreach (self::teamsFor($u) as $t) $out[] = ['source' => 'team', 'label' => $t['name'], 'detail' => self::ROLES[$t['my_role']] ?? 'Member'];
        if (LmsAuth::isOrgMember($u)) $out[] = ['source' => 'afrovanguard', 'label' => 'Afrovanguard member', 'detail' => ucfirst((string) ($u['role'] ?? 'member'))];
        try {
            $st = Database::pdo()->prepare("SELECT role FROM team WHERE LOWER(email) = ? AND tier = 'volunteer' AND active = 1 LIMIT 1");
            $st->execute([strtolower((string) ($u['email'] ?? ''))]);
            if (($r = $st->fetchColumn()) !== false) $out[] = ['source' => 'people', 'label' => 'On the People page', 'detail' => (string) $r ?: 'Volunteer'];
        } catch (Throwable $e) {}
        return $out;
    }

    /**
     * At sign-in: a volunteer is recognised. The first time, they are welcomed
     * and told what they are recognised as; every time, the count and the
     * moment are kept, and an NGG record with their email is linked to them.
     * Never in the way of signing in.
     */
    public static function recognizeAtLogin(int $uid): ?array
    {
        try {
            self::ensure();
            $pdo = Database::pdo();
            Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS vol_recognitions (
                user_id INTEGER NOT NULL PRIMARY KEY,
                sources VARCHAR(200) NOT NULL DEFAULT '',
                first_at VARCHAR(32) NOT NULL DEFAULT '',
                last_at VARCHAR(32) NOT NULL DEFAULT '',
                logins INTEGER NOT NULL DEFAULT 0
            )");
            $st = $pdo->prepare('SELECT id, name, email, role FROM lms_users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u || !self::isVolunteer($u)) return null;
            if (class_exists('NggVolunteers')) NggVolunteers::link();
            $id = self::identity($u);
            $sources = implode(',', array_unique(array_column($id, 'source')));
            $now = self::now();
            $st = $pdo->prepare('SELECT * FROM vol_recognitions WHERE user_id = ?');
            $st->execute([$uid]);
            $had = $st->fetch(PDO::FETCH_ASSOC);
            if (!$had) {
                $pdo->prepare('INSERT INTO vol_recognitions (user_id, sources, first_at, last_at, logins) VALUES (?,?,?,?,1)')->execute([$uid, $sources, $now, $now]);
                $first = $id[0] ?? ['label' => 'volunteer', 'detail' => ''];
                if (class_exists('Notifications')) {
                    try { Notifications::push($uid, 'volunteer', 'Welcome to your Volunteer dashboard',
                        'We recognise you as ' . $first['label'] . ($first['detail'] !== '' ? ' (' . $first['detail'] . ')' : '') . '. Your meetings, attendance and hours are all in one place.', '/portal/#volunteer', 'volwelcome:' . $uid); } catch (Throwable $e) {}
                }
            } else {
                $pdo->prepare('UPDATE vol_recognitions SET sources = ?, last_at = ?, logins = logins + 1 WHERE user_id = ?')->execute([$sources, $now, $uid]);
            }
            return ['first' => !$had, 'identity' => $id];
        } catch (Throwable $e) { error_log('[volunteers] recognise: ' . $e->getMessage()); return null; }
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

    /** Hours a month for the last $months months, oldest first — for the chart. */
    public static function hoursSeries(int $uid, int $months = 12): array
    {
        self::ensure();
        $today = function_exists('av_today_tz') ? av_today_tz() : date('Y-m-d');
        $first = new DateTime(substr($today, 0, 7) . '-01');
        $first->modify('-' . ($months - 1) . ' months');
        $st = Database::pdo()->prepare('SELECT SUBSTR(day, 1, 7) m, SUM(minutes) s FROM vol_hours WHERE user_id = ? AND day >= ? GROUP BY SUBSTR(day, 1, 7)');
        $st->execute([$uid, $first->format('Y-m-d')]);
        $by = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $by[(string) $r['m']] = round((int) $r['s'] / 60, 1);
        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $k = $first->format('Y-m');
            $out[] = ['month' => $k, 'label' => $first->format('M'), 'long' => $first->format('F Y'), 'hours' => $by[$k] ?? 0];
            $first->modify('+1 month');
        }
        return $out;
    }

    /** Every hour logged, as CSV, for the volunteer's own records (or a reference letter). */
    public static function hoursCsv(int $uid): string
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT day, minutes, activity, source FROM vol_hours WHERE user_id = ? ORDER BY day, id');
        $st->execute([$uid]);
        $f = fopen('php://temp', 'r+');
        fputcsv($f, ['Date', 'Hours', 'Activity', 'How it was logged'], ',', '"', '\\');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            /* A leading = + - @ would be a formula to a spreadsheet. */
            $act = preg_match('/^[=+\-@]/', (string) $r['activity']) ? "'" . $r['activity'] : (string) $r['activity'];
            fputcsv($f, [$r['day'], round((int) $r['minutes'] / 60, 2), $act, $r['source'] === 'manual' ? 'Logged by me' : 'Meeting attended'], ',', '"', '\\');
        }
        rewind($f);
        return (string) stream_get_contents($f);
    }

    /**
     * The open actions from a team's meetings, with who owns them — for its
     * lead (and coordinators), so nothing agreed in a meeting is forgotten.
     */
    public static function teamActions(array $u, int $teamId): array
    {
        self::ensure();
        if (!self::canManageTeam($u, $teamId) && self::roleIn($teamId, (int) $u['id']) === '') return [];
        $st = Database::pdo()->prepare("SELECT c.id, c.title, c.due, c.status, c.member_id, c.owner_hint, u.name owner, m.title meeting FROM commitments c
                                        JOIN meetings m ON m.id = c.source_id AND c.source_kind = 'meeting' LEFT JOIN lms_users u ON u.id = c.member_id
                                        WHERE m.context = 'team' AND m.context_id = ? AND c.status = 'open' ORDER BY c.due, c.id LIMIT 200");
        try { $st->execute([$teamId]); } catch (Throwable $e) { return []; }
        $today = gmdate('Y-m-d');
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'due' => (string) $r['due'], 'overdue' => (string) $r['due'] !== '' && (string) $r['due'] < $today,
                                           'owner' => (string) ($r['owner'] ?: $r['owner_hint'] ?: 'Nobody yet'), 'owner_id' => (int) $r['member_id'], 'from' => (string) $r['meeting']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** What has happened lately in this person's teams: minutes out, actions done, people joining. */
    public static function activity(array $u, int $limit = 15): array
    {
        self::ensure();
        $teams = array_column(self::teamsFor($u), 'name', 'id');
        if (!$teams) return [];
        $ids = implode(',', array_map('intval', array_keys($teams)));
        $pdo = Database::pdo(); $out = [];
        try {
            foreach ($pdo->query("SELECT mm.published_at at, m.title, m.context_id t FROM meeting_minutes mm JOIN meetings m ON m.id = mm.meeting_id
                                  WHERE mm.status = 'published' AND m.context = 'team' AND m.context_id IN ($ids) ORDER BY mm.published_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $out[] = ['at' => (string) $r['at'], 'kind' => 'minutes', 'text' => 'Minutes of ' . $r['title'] . ' were published', 'team' => $teams[(int) $r['t']] ?? ''];
            foreach ($pdo->query("SELECT c.closed_at at, c.title, u.name, m.context_id t FROM commitments c JOIN meetings m ON m.id = c.source_id AND c.source_kind = 'meeting'
                                  LEFT JOIN lms_users u ON u.id = c.member_id WHERE c.status = 'done' AND m.context = 'team' AND m.context_id IN ($ids) ORDER BY c.closed_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $out[] = ['at' => (string) $r['at'], 'kind' => 'done', 'text' => ($r['name'] ?: 'Someone') . ' finished “' . $r['title'] . '”', 'team' => $teams[(int) $r['t']] ?? ''];
        } catch (Throwable $e) {}
        foreach ($pdo->query("SELECT tm.joined_at at, u.name, tm.team_id t FROM vol_team_members tm JOIN lms_users u ON u.id = tm.user_id WHERE tm.team_id IN ($ids) ORDER BY tm.joined_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) as $r)
            $out[] = ['at' => (string) $r['at'], 'kind' => 'joined', 'text' => $r['name'] . ' joined ' . ($teams[(int) $r['t']] ?? 'the team'), 'team' => $teams[(int) $r['t']] ?? ''];
        usort($out, static fn($a, $b) => strcmp($b['at'], $a['at']));
        return array_slice(array_values(array_filter($out, static fn($e) => $e['at'] !== '')), 0, $limit);
    }

    /** Hours a month a volunteer aims for; the ring on the dashboard fills toward it. */
    public const MONTHLY_GOAL = 8;

    /**
     * Milestones, earned once and kept. Each is [key, label, emoji, measure,
     * threshold]; the measure is one of the counts engagement() reads.
     */
    public const MILESTONES = [
        ['first_hour', 'First hour given', '🌱', 'hours', 1],
        ['hours_10', '10 hours', '⏳', 'hours', 10],
        ['hours_25', '25 hours', '🥉', 'hours', 25],
        ['hours_50', '50 hours', '🥈', 'hours', 50],
        ['hours_100', '100 hours', '🥇', 'hours', 100],
        ['hours_250', '250 hours', '🏆', 'hours', 250],
        ['meetings_5', '5 meetings', '🤝', 'meetings', 5],
        ['meetings_25', '25 meetings', '📣', 'meetings', 25],
        ['actions_10', '10 actions done', '✅', 'actions', 10],
        ['actions_50', '50 actions done', '🚀', 'actions', 50],
        ['ontime_10', '10 days on time at the gate', '⏰', 'ontime', 10],
        ['streak_4', '4-week streak', '🔥', 'streak', 4],
        ['streak_12', '12-week streak', '💎', 'streak', 12],
        ['kudos_1', 'First kudos received', '💛', 'kudos', 1],
        ['kudos_10', '10 kudos received', '💫', 'kudos', 10],
        ['shifts_5', '5 opportunities served', '🧡', 'shifts', 5],
    ];

    /**
     * What makes a volunteer want to come back: the streak they are on, the
     * milestones they have earned and the next one, where they stand this
     * month among their teams, today at the gate, the people with a birthday
     * coming, and who the organisation is celebrating.
     */
    public static function engagement(array $u): array
    {
        self::ensure();
        $uid = (int) $u['id'];
        $pdo = Database::pdo();
        $today = function_exists('av_today_tz') ? av_today_tz() : date('Y-m-d');

        /* Weeks with something given: an hour logged, a meeting attended, a day at the gate. */
        $since = date('Y-m-d', strtotime($today . ' -26 weeks'));
        $days = [];
        $st = $pdo->prepare('SELECT DISTINCT day FROM vol_hours WHERE user_id = ? AND day >= ?');
        $st->execute([$uid, $since]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) $days[] = (string) $d;
        $gate = null; $ontime = 0;
        if (class_exists('GateAttendance')) {
            try {
                GateAttendance::ensure();
                $st = $pdo->prepare("SELECT day FROM gate_attendance WHERE member_id = ? AND day >= ? AND status IN ('present', 'late')");
                $st->execute([$uid, $since]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) $days[] = (string) $d;
                $sum = GateAttendance::summary($uid, 30);
                $hist = GateAttendance::history($uid, 2);
                $todayRow = null;
                foreach ($hist as $r) if ((string) $r['day'] === $today) $todayRow = $r;
                $st = $pdo->prepare("SELECT COUNT(*) FROM gate_attendance WHERE member_id = ? AND status = 'present'");
                $st->execute([$uid]);
                $ontime = (int) $st->fetchColumn();
                if ($sum['counted'] || $todayRow || (class_exists('GatePass') && GatePass::ready())) {
                    $gate = ['rate' => $sum['rate'], 'punctuality' => $sum['punctuality'], 'grade' => $sum['grade'], 'run' => (int) $sum['streak'],
                             'counted' => (int) $sum['counted'], 'late' => (int) $sum['late'],
                             'today' => $todayRow ? ['status' => (string) $todayRow['status'], 'in_at' => (string) $todayRow['in_at'], 'out_at' => (string) ($todayRow['out_at'] ?? '')] : null,
                             'points' => GateAttendance::points($uid)];
                }
            } catch (Throwable $e) { $gate = null; }
        }
        $weeks = [];
        foreach ($days as $d) { $t = strtotime($d . ' 12:00'); if ($t) $weeks[date('o-W', $t)] = true; }
        $streak = 0;
        $cursor = strtotime($today . ' 12:00');
        /* This week counts once something happens in it; until then the run
           is still alive from last week. */
        if (!isset($weeks[date('o-W', $cursor)])) $cursor -= 7 * 86400;
        while (isset($weeks[date('o-W', $cursor)])) { $streak++; $cursor -= 7 * 86400; }

        /* The measures milestones are counted on. */
        $st = $pdo->prepare('SELECT COALESCE(SUM(minutes), 0) FROM vol_hours WHERE user_id = ?');
        $st->execute([$uid]);
        $hours = round((int) $st->fetchColumn() / 60, 1);
        $st = $pdo->prepare("SELECT COUNT(*) FROM vol_hours WHERE user_id = ? AND source = 'meeting'");
        $st->execute([$uid]);
        $meetings = (int) $st->fetchColumn();
        $actions = 0;
        try { $st = $pdo->prepare("SELECT COUNT(*) FROM commitments WHERE member_id = ? AND status = 'done'"); $st->execute([$uid]); $actions = (int) $st->fetchColumn(); } catch (Throwable $e) {}
        $kudos = 0; $shifts = 0;
        try { $st = $pdo->prepare('SELECT COUNT(*) FROM vol_kudos WHERE to_id = ?'); $st->execute([$uid]); $kudos = (int) $st->fetchColumn(); } catch (Throwable $e) {}
        try { $st = $pdo->prepare("SELECT COUNT(*) FROM vol_signups WHERE user_id = ? AND status = 'attended'"); $st->execute([$uid]); $shifts = (int) $st->fetchColumn(); } catch (Throwable $e) {}
        $measure = ['hours' => $hours, 'meetings' => $meetings, 'actions' => $actions, 'ontime' => $ontime, 'streak' => $streak, 'kudos' => $kudos, 'shifts' => $shifts];
        $earned = []; $next = null;
        foreach (self::MILESTONES as [$key, $label, $emoji, $m, $n]) {
            if ($measure[$m] >= $n) { $earned[] = ['key' => $key, 'label' => $label, 'emoji' => $emoji]; continue; }
            $pct = (int) floor($measure[$m] / $n * 100);
            if ($next === null || $pct > $next['pct']) $next = ['key' => $key, 'label' => $label, 'emoji' => $emoji, 'pct' => $pct, 'have' => $measure[$m], 'need' => $n, 'measure' => $m];
        }

        /* This month in my teams: hours given, highest first. */
        $mates = [];
        foreach (self::teamsFor($u) as $t) foreach ($t['members'] as $p) $mates[(int) $p['id']] = $p['name'];
        $mates[$uid] = (string) $u['name'];
        $board = [];
        if (count($mates) > 1) {
            $month = substr($today, 0, 7);
            $ids = implode(',', array_map('intval', array_keys($mates)));
            $rows = $pdo->query("SELECT user_id, SUM(minutes) m FROM vol_hours WHERE day LIKE '$month%' AND user_id IN ($ids) GROUP BY user_id")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($mates as $id => $name) $board[] = ['id' => $id, 'name' => $name, 'hours' => round((int) ($rows[$id] ?? 0) / 60, 1), 'me' => $id === $uid];
            usort($board, static fn($a, $b) => [$b['hours'], $a['name']] <=> [$a['hours'], $b['name']]);
            foreach ($board as $i => &$b) $b['rank'] = $i + 1;
            unset($b);
        }
        $mine = array_values(array_filter($board, static fn($b) => $b['me']))[0] ?? null;

        /* Birthdays in my teams over the next fortnight. */
        $bdays = [];
        if (count($mates) > 1) {
            try {
                if (class_exists('Birthdays')) Birthdays::ensure();
                $ids = implode(',', array_map('intval', array_keys($mates)));
                foreach ($pdo->query("SELECT id, name, birthday FROM lms_users WHERE id IN ($ids) AND birthday IS NOT NULL AND birthday <> ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $md = substr((string) $r['birthday'], -5);
                    for ($i = 0; $i < 14; $i++) {
                        $d = date('Y-m-d', strtotime($today . " +$i days"));
                        if (substr($d, 5) === $md) { $bdays[] = ['name' => (string) $r['name'], 'in' => $i, 'day' => $d, 'me' => (int) $r['id'] === $uid]; break; }
                    }
                }
                usort($bdays, static fn($a, $b) => $a['in'] <=> $b['in']);
            } catch (Throwable $e) {}
        }

        $votm = null;
        if (!function_exists('av_votm') && is_file(__DIR__ . '/people.php')) require_once __DIR__ . '/people.php';
        if (function_exists('av_votm')) { try { $votm = av_votm($pdo)['votm'] ?? null; } catch (Throwable $e) {} }

        return ['streak' => $streak, 'goal' => self::MONTHLY_GOAL, 'measures' => $measure, 'earned' => $earned, 'next' => $next,
                'board' => array_slice($board, 0, 5), 'my_rank' => $mine ? ['rank' => $mine['rank'], 'of' => count($board)] : null,
                'birthdays' => array_slice($bdays, 0, 6), 'votm' => $votm, 'gate' => $gate];
    }

    /** Which ways a meeting is captured on this site. Everything works with none of them. */
    public static function integrations(): array
    {
        $google = class_exists('GoogleWorkspace') && GoogleWorkspace::meetEnabled();
        return ['google_meet' => $google,
                'notetaker' => class_exists('Meetings') && Meetings::botAllowed() && Meetings::botProvider() === 'attendee' && class_exists('AttendeeBot') && AttendeeBot::configured(),
                'live_notetaker' => class_exists('AttendeeBot') && AttendeeBot::configured() && AttendeeBot::webhookSecret() !== '',
                'assistant' => class_exists('AvAgent') && AvAgent::available(),
                'pdf' => class_exists('Dompdf\\Dompdf')];
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
                'actions' => $actions, 'hours' => self::hoursFor($uid), 'hours_series' => self::hoursSeries($uid, 12),
                'attendance' => MeetingMinutes::attendanceOf($u), 'activity' => self::activity($u), 'integrations' => self::integrations(),
                'engagement' => self::engagement($u), 'identity' => self::identity($u),
                'opportunities' => class_exists('VolunteerWork') ? VolunteerWork::list($u) : [],
                'kudos' => class_exists('VolunteerWork') ? VolunteerWork::kudosFor($u) : ['received' => [], 'wall' => [], 'count' => 0],
                'ngg' => class_exists('NggVolunteers') && self::canRunTeams($u) ? NggVolunteers::status() : null,
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
