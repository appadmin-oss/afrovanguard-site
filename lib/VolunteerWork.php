<?php
/**
 * lib/VolunteerWork.php — what volunteers do between meetings, and for each other.
 *
 *   · OPPORTUNITIES: a coordinator (or a team's lead, for the team) posts a
 *     shift or an event — when, where, how many are needed. Volunteers sign
 *     up and withdraw; whoever posted it marks who came, and those who came
 *     are credited the hours by themselves. Signed-up volunteers are reminded
 *     the day before.
 *   · KUDOS: a volunteer thanks another — for teamwork, leadership, going the
 *     extra mile, or just thanks. The thanked person is told, it shows in the
 *     team's activity, and kudos received count toward milestones.
 *   · PEOPLE: the volunteers of Afrovanguard's teams and NextGen Genius in one
 *     directory (lib/NggVolunteers.php is where NGG's come from).
 *   · THE SERVICE LETTER: a PDF a volunteer can download and hand to a school,
 *     an employer or a visa office — their hours, meetings, roles and NGG
 *     service, with a code that verifies it.
 */
declare(strict_types=1);

final class VolunteerWork
{
    public const KUDOS = ['thanks' => ['Thank you', '🙏'], 'teamwork' => ['Teamwork', '🤝'], 'leadership' => ['Leadership', '🧭'], 'extra' => ['Above and beyond', '🌟']];
    public const KUDOS_PER_DAY = 10;

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        Volunteers::ensure();
        $pdo = Database::pdo();
        Database::execSchema($pdo, "
        CREATE TABLE IF NOT EXISTS vol_opportunities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(160) NOT NULL,
            details TEXT NOT NULL,
            location VARCHAR(160) NOT NULL DEFAULT '',
            starts_at VARCHAR(20) NOT NULL,
            duration_min INTEGER NOT NULL DEFAULT 120,
            slots INTEGER NOT NULL DEFAULT 0,
            team_id INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(12) NOT NULL DEFAULT 'open',
            reminded INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS vol_signups (
            opportunity_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'signed',
            at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS vol_kudos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_id INTEGER NOT NULL,
            to_id INTEGER NOT NULL,
            kind VARCHAR(16) NOT NULL DEFAULT 'thanks',
            message VARCHAR(400) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        try { $pdo->exec('CREATE UNIQUE INDEX uq_vol_signup ON vol_signups (opportunity_id, user_id)'); } catch (Throwable $e) {}
        try { $pdo->exec('CREATE INDEX idx_vol_kudos_to ON vol_kudos (to_id, created_at)'); } catch (Throwable $e) {}
        try { $pdo->exec('CREATE INDEX idx_vol_opp_when ON vol_opportunities (starts_at)'); } catch (Throwable $e) {}
        try { if (!Database::columnExists('vol_opportunities', 'project_id')) $pdo->exec('ALTER TABLE vol_opportunities ADD COLUMN project_id INTEGER NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    private static function push(int $uid, string $title, string $body, string $key): void
    {
        if ($uid <= 0 || !class_exists('Notifications')) return;
        try { Notifications::push($uid, 'volunteer', $title, $body, '/portal/#volunteer', $key); } catch (Throwable $e) {}
    }

    /* ══ Opportunities ═════════════════════════════════════════════════════ */

    public static function canPost(array $u, int $teamId): bool
    {
        return Volunteers::canRunTeams($u) || ($teamId > 0 && Volunteers::canManageTeam($u, $teamId));
    }

    private static function opp(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM vol_opportunities WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** $in: title, details, location, when (local "Y-m-dTH:i"), duration, slots, team_id. */
    public static function post(array $u, array $in): array
    {
        self::ensure();
        $team = (int) ($in['team_id'] ?? 0);
        if (!self::canPost($u, $team)) return ['ok' => false, 'error' => $team ? 'Only the team\'s lead or a coordinator posts for the team.' : 'Opportunities for everybody are posted by a coordinator.'];
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 160);
        if ($title === '') return ['ok' => false, 'error' => 'Give it a title.'];
        $when = trim((string) ($in['when'] ?? ''));
        try { $dt = new DateTime($when, new DateTimeZone(defined('AV_TZ') ? AV_TZ : 'Africa/Lagos')); } catch (Throwable $e) { return ['ok' => false, 'error' => 'Pick a date and time.']; }
        if ($when === '' || $dt->getTimestamp() < time() - 3600) return ['ok' => false, 'error' => 'Pick a date and time that has not passed.'];
        $pdo = Database::pdo();
        VolunteerProjects::ensure();
        $proj = (int) ($in['project_id'] ?? 0);
        if ($proj > 0 && !VolunteerProjects::byId($proj)) $proj = 0;
        $pdo->prepare('INSERT INTO vol_opportunities (title, details, location, starts_at, duration_min, slots, team_id, created_by, created_at, project_id) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$title, mb_substr(trim((string) ($in['details'] ?? '')), 0, 3000), mb_substr(trim((string) ($in['location'] ?? '')), 0, 160),
                       gmdate('Y-m-d H:i:s', $dt->getTimestamp()), max(15, min(720, (int) ($in['duration'] ?? 120))), max(0, min(500, (int) ($in['slots'] ?? 0))),
                       $team, (int) $u['id'], self::now(), $proj]);
        $id = (int) $pdo->lastInsertId();
        /* A team's opportunity is announced to the team. */
        if ($team > 0 && ($t = Volunteers::team($team))) {
            foreach ($t['members'] as $p) if ($p['id'] !== (int) $u['id']) self::push($p['id'], 'New opportunity: ' . $title, self::whenText(gmdate('Y-m-d H:i:s', $dt->getTimestamp())) . ' — sign up in your dashboard', 'opp:' . $id . ':' . $p['id']);
        }
        return ['ok' => true, 'id' => $id];
    }

    public static function cancel(array $u, int $id): array
    {
        self::ensure();
        $o = self::opp($id);
        if (!$o) return ['ok' => false, 'error' => 'No such opportunity.'];
        if ((int) $o['created_by'] !== (int) $u['id'] && !self::canPost($u, (int) $o['team_id'])) return ['ok' => false, 'error' => 'Only whoever posted it can cancel it.'];
        Database::pdo()->prepare("UPDATE vol_opportunities SET status = 'cancelled' WHERE id = ?")->execute([$id]);
        $st = Database::pdo()->prepare("SELECT user_id FROM vol_signups WHERE opportunity_id = ? AND status = 'signed'");
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) self::push((int) $uid, 'Cancelled: ' . $o['title'], self::whenText((string) $o['starts_at']), 'oppcx:' . $id . ':' . $uid);
        return ['ok' => true];
    }

    private static function visible(array $u, array $o): bool
    {
        return (int) $o['team_id'] === 0 || Volunteers::roleIn((int) $o['team_id'], (int) $u['id']) !== '' || Volunteers::canRunTeams($u);
    }

    private static function signedCount(int $id): int
    {
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM vol_signups WHERE opportunity_id = ? AND status IN ('signed', 'attended')");
        $st->execute([$id]);
        return (int) $st->fetchColumn();
    }

    public static function signup(array $u, int $id): array
    {
        self::ensure();
        $o = self::opp($id);
        if (!$o || (string) $o['status'] !== 'open' || !self::visible($u, $o)) return ['ok' => false, 'error' => 'No such opportunity.'];
        if (strtotime((string) $o['starts_at'] . ' UTC') < time()) return ['ok' => false, 'error' => 'It has already started.'];
        $mine = self::myStatus($id, (int) $u['id']);
        if ($mine === 'signed') return ['ok' => true, 'already' => true];
        if ((int) $o['slots'] > 0 && self::signedCount($id) >= (int) $o['slots']) return ['ok' => false, 'error' => 'It is full. Ask whoever posted it whether more are needed.'];
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM vol_signups WHERE opportunity_id = ? AND user_id = ?')->execute([$id, (int) $u['id']]);
        $pdo->prepare("INSERT INTO vol_signups (opportunity_id, user_id, status, at) VALUES (?, ?, 'signed', ?)")->execute([$id, (int) $u['id'], self::now()]);
        return ['ok' => true];
    }

    public static function withdraw(array $u, int $id): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("UPDATE vol_signups SET status = 'withdrawn', at = ? WHERE opportunity_id = ? AND user_id = ? AND status = 'signed'");
        $st->execute([self::now(), $id, (int) $u['id']]);
        return $st->rowCount() ? ['ok' => true] : ['ok' => false, 'error' => 'You were not signed up.'];
    }

    private static function myStatus(int $id, int $uid): string
    {
        $st = Database::pdo()->prepare('SELECT status FROM vol_signups WHERE opportunity_id = ? AND user_id = ?');
        $st->execute([$id, $uid]);
        return (string) ($st->fetchColumn() ?: '');
    }

    /** Whoever posted it marks who came. Those who came are credited its hours. */
    public static function mark(array $u, int $id, int $uid, string $status): array
    {
        self::ensure();
        $o = self::opp($id);
        if (!$o) return ['ok' => false, 'error' => 'No such opportunity.'];
        if ((int) $o['created_by'] !== (int) $u['id'] && !self::canPost($u, (int) $o['team_id'])) return ['ok' => false, 'error' => 'Only whoever posted it marks who came.'];
        if (!in_array($status, ['attended', 'absent'], true)) return ['ok' => false, 'error' => 'Came, or did not.'];
        if (strtotime((string) $o['starts_at'] . ' UTC') > time()) return ['ok' => false, 'error' => 'It has not happened yet.'];
        $st = Database::pdo()->prepare("UPDATE vol_signups SET status = ?, at = ? WHERE opportunity_id = ? AND user_id = ? AND status IN ('signed', 'attended', 'absent')");
        $st->execute([$status, self::now(), $id, $uid]);
        if (!$st->rowCount()) return ['ok' => false, 'error' => 'They were not signed up.'];
        if ($status === 'attended') {
            $day = function_exists('av_today_tz') ? substr(MeetingMinutes::local((string) $o['starts_at']), 0, 10) : substr((string) $o['starts_at'], 0, 10);
            $proj = (int) ($o['project_id'] ?? 0) ?: ((int) $o['team_id'] > 0 && ($tm = Volunteers::team((int) $o['team_id'])) ? (int) $tm['project_id'] : 0);
            if (Volunteers::autoHours($uid, $day, (int) $o['duration_min'], (string) $o['title'], 'opportunity', 'opp:' . $id, $proj)) {
                self::push($uid, 'Thank you for coming to ' . $o['title'], round((int) $o['duration_min'] / 60, 1) . ' hours added to your record.', 'oppdone:' . $id . ':' . $uid);
            }
        } else {
            Database::pdo()->prepare("DELETE FROM vol_hours WHERE user_id = ? AND source = 'opportunity' AND source_ref = ?")->execute([$uid, 'opp:' . $id]);
        }
        return ['ok' => true, 'roster' => self::roster($u, $id)['roster'] ?? []];
    }

    public static function roster(array $u, int $id): array
    {
        self::ensure();
        $o = self::opp($id);
        if (!$o || !self::visible($u, $o)) return ['ok' => false, 'error' => 'No such opportunity.'];
        $st = Database::pdo()->prepare("SELECT s.user_id, s.status, u.name FROM vol_signups s JOIN lms_users u ON u.id = s.user_id WHERE s.opportunity_id = ? AND s.status <> 'withdrawn' ORDER BY u.name");
        $st->execute([$id]);
        return ['ok' => true, 'roster' => array_map(static fn($r) => ['id' => (int) $r['user_id'], 'name' => (string) $r['name'], 'status' => (string) $r['status']], $st->fetchAll(PDO::FETCH_ASSOC)),
                'can_mark' => (int) $o['created_by'] === (int) $u['id'] || self::canPost($u, (int) $o['team_id'])];
    }

    public static function whenText(string $utc): string { return class_exists('MeetingMinutes') ? MeetingMinutes::when($utc) : $utc; }

    /** Opportunities still to come (and the last fortnight's, to mark), for this person. */
    public static function list(array $u): array
    {
        self::ensure();
        $rows = Database::pdo()->prepare("SELECT * FROM vol_opportunities WHERE status = 'open' AND starts_at >= ? ORDER BY starts_at LIMIT 200");
        $rows->execute([gmdate('Y-m-d H:i:s', time() - 14 * 86400)]);
        $out = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $o) {
            if (!self::visible($u, $o)) continue;
            $start = strtotime((string) $o['starts_at'] . ' UTC') ?: 0;
            $mineCan = (int) $o['created_by'] === (int) $u['id'] || self::canPost($u, (int) $o['team_id']);
            $past = $start + (int) $o['duration_min'] * 60 < time();
            $my = self::myStatus((int) $o['id'], (int) $u['id']);
            if ($past && !$mineCan && $my === '') continue;
            $team = (int) $o['team_id'] ? Volunteers::team((int) $o['team_id']) : null;
            $signed = self::signedCount((int) $o['id']);
            $out[] = ['id' => (int) $o['id'], 'title' => (string) $o['title'], 'details' => (string) $o['details'], 'location' => (string) $o['location'],
                      'when' => self::whenText((string) $o['starts_at']), 'ts' => $start, 'duration_min' => (int) $o['duration_min'], 'slots' => (int) $o['slots'],
                      'signed' => $signed, 'full' => (int) $o['slots'] > 0 && $signed >= (int) $o['slots'], 'team' => $team['name'] ?? '', 'past' => $past,
                      'mine' => $my, 'can_manage' => $mineCan];
        }
        return $out;
    }

    /** The day before: everyone signed up is reminded. Once per opportunity. */
    public static function sweep(?int $now = null): int
    {
        self::ensure();
        $now = $now ?? time();
        $st = Database::pdo()->prepare("SELECT * FROM vol_opportunities WHERE status = 'open' AND reminded = 0 AND starts_at > ? AND starts_at <= ?");
        $st->execute([gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now + 86400)]);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
            Database::pdo()->prepare('UPDATE vol_opportunities SET reminded = 1 WHERE id = ?')->execute([(int) $o['id']]);
            $s = Database::pdo()->prepare("SELECT user_id FROM vol_signups WHERE opportunity_id = ? AND status = 'signed'");
            $s->execute([(int) $o['id']]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $uid) { self::push((int) $uid, 'Tomorrow: ' . $o['title'], self::whenText((string) $o['starts_at']) . ((string) $o['location'] !== '' ? ' · ' . $o['location'] : ''), 'oppsoon:' . $o['id'] . ':' . $uid); $n++; }
        }
        return $n;
    }

    /* ══ Kudos ═════════════════════════════════════════════════════════════ */

    public static function give(array $u, int $to, string $kind, string $message): array
    {
        self::ensure();
        if ($to === (int) $u['id']) return ['ok' => false, 'error' => 'Kudos are for somebody else.'];
        if (!isset(self::KUDOS[$kind])) $kind = 'thanks';
        $message = mb_substr(trim($message), 0, 400);
        if (mb_strlen($message) < 3) return ['ok' => false, 'error' => 'Say what for — a line is enough.'];
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id, name FROM lms_users WHERE id = ?');
        $st->execute([$to]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || !Volunteers::isVolunteer(['id' => (int) $p['id'], 'name' => $p['name'], 'email' => self::emailOf((int) $p['id']), 'role' => self::roleOf((int) $p['id'])])) return ['ok' => false, 'error' => 'Kudos go to a volunteer.'];
        $st = $pdo->prepare('SELECT COUNT(*) FROM vol_kudos WHERE from_id = ? AND created_at >= ?');
        $st->execute([(int) $u['id'], gmdate('Y-m-d H:i:s', time() - 86400)]);
        if ((int) $st->fetchColumn() >= self::KUDOS_PER_DAY) return ['ok' => false, 'error' => 'That is a generous day — more tomorrow.'];
        $pdo->prepare('INSERT INTO vol_kudos (from_id, to_id, kind, message, created_at) VALUES (?,?,?,?,?)')->execute([(int) $u['id'], $to, $kind, $message, self::now()]);
        $id = (int) $pdo->lastInsertId();
        [$label, $emoji] = self::KUDOS[$kind];
        self::push($to, $emoji . ' ' . $u['name'] . ' sent you kudos', $label . ' — “' . mb_substr($message, 0, 140) . '”', 'kudos:' . $id);
        return ['ok' => true, 'id' => $id];
    }

    private static function emailOf(int $id): string { $s = Database::pdo()->prepare('SELECT email FROM lms_users WHERE id = ?'); $s->execute([$id]); return (string) ($s->fetchColumn() ?: ''); }
    private static function roleOf(int $id): string { $s = Database::pdo()->prepare('SELECT role FROM lms_users WHERE id = ?'); $s->execute([$id]); return (string) ($s->fetchColumn() ?: ''); }

    /** Kudos I received (newest first), and the kudos wall of my teams. */
    public static function kudosFor(array $u): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $shape = static fn($r) => ['id' => (int) $r['id'], 'from' => (string) $r['from_name'], 'from_id' => (int) $r['from_id'], 'to' => (string) $r['to_name'], 'to_id' => (int) $r['to_id'],
                                   'kind' => (string) $r['kind'], 'label' => self::KUDOS[(string) $r['kind']][0] ?? 'Thank you', 'emoji' => self::KUDOS[(string) $r['kind']][1] ?? '🙏',
                                   'message' => (string) $r['message'], 'at' => (string) $r['created_at']];
        $sql = 'SELECT k.*, f.name from_name, t.name to_name FROM vol_kudos k JOIN lms_users f ON f.id = k.from_id JOIN lms_users t ON t.id = k.to_id';
        $st = $pdo->prepare($sql . ' WHERE k.to_id = ? ORDER BY k.id DESC LIMIT 30');
        $st->execute([(int) $u['id']]);
        $mine = array_map($shape, $st->fetchAll(PDO::FETCH_ASSOC));
        $ids = [];
        foreach (Volunteers::teamsFor($u) as $t) foreach ($t['members'] as $p) $ids[$p['id']] = true;
        $wall = [];
        if ($ids) {
            $in = implode(',', array_map('intval', array_keys($ids)));
            $wall = array_map($shape, $pdo->query($sql . " WHERE k.to_id IN ($in) OR k.from_id IN ($in) ORDER BY k.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC));
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM vol_kudos WHERE to_id = ?');
        $st->execute([(int) $u['id']]);
        return ['received' => $mine, 'wall' => $wall, 'count' => (int) $st->fetchColumn()];
    }

    /* ══ People ════════════════════════════════════════════════════════════ */

    /** Afrovanguard's team volunteers and NGG's, one list. Kudos can go to anybody with an account here. */
    public static function people(array $u, string $q = '', string $source = ''): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $by = [];
        if ($source !== 'ngg') {
            foreach ($pdo->query("SELECT u.id, u.name, t.name team, m.role FROM vol_team_members m JOIN lms_users u ON u.id = m.user_id JOIN vol_teams t ON t.id = m.team_id AND t.archived = 0 ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = 'u' . $r['id'];
                $by[$k] = $by[$k] ?? ['user_id' => (int) $r['id'], 'name' => (string) $r['name'], 'teams' => [], 'ngg' => null, 'photo' => ''];
                $by[$k]['teams'][] = $r['team'] . ($r['role'] !== 'member' ? ' (' . $r['role'] . ')' : '');
            }
        }
        if ($source !== 'afrovanguard' && class_exists('NggVolunteers')) {
            foreach (NggVolunteers::directory() as $v) {
                $k = (int) $v['user_id'] > 0 ? 'u' . $v['user_id'] : 'n' . $v['volunteer_id'];
                $by[$k] = $by[$k] ?? ['user_id' => (int) $v['user_id'], 'name' => (string) $v['name'], 'teams' => [], 'ngg' => null, 'photo' => ''];
                $by[$k]['ngg'] = ['role' => (string) ($v['role_label'] ?: ucfirst((string) $v['role'])), 'centre' => (string) $v['centre'], 'level' => (string) $v['level'], 'hours' => (int) $v['hours']];
                if ((string) $v['photo_url'] !== '') $by[$k]['photo'] = (string) $v['photo_url'];
            }
        }
        $q = mb_strtolower(trim($q));
        $out = array_values(array_filter($by, static function ($p) use ($q) {
            if ($q === '') return true;
            return str_contains(mb_strtolower($p['name'] . ' ' . implode(' ', $p['teams']) . ' ' . ($p['ngg']['centre'] ?? '') . ' ' . ($p['ngg']['role'] ?? '')), $q);
        }));
        usort($out, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        foreach ($out as &$p) $p['me'] = $p['user_id'] === (int) $u['id'];
        return array_slice($out, 0, 400);
    }

    /* ══ The service letter ════════════════════════════════════════════════ */

    public static function letterCode(int $uid, string $issued): string
    {
        return strtoupper(substr(hash_hmac('sha256', 'service-letter|' . $uid . '|' . $issued, av_secret()), 0, 10));
    }

    /** The letter as a PDF (dompdf). Null when dompdf is not installed. */
    public static function serviceLetter(array $u): ?array
    {
        self::ensure();
        if (!class_exists('Dompdf\\Dompdf')) return null;
        $uid = (int) $u['id'];
        $pdo = Database::pdo();
        $e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $st = $pdo->prepare('SELECT MIN(day) first, COALESCE(SUM(minutes), 0) m FROM vol_hours WHERE user_id = ?');
        $st->execute([$uid]);
        $h = $st->fetch(PDO::FETCH_ASSOC) ?: ['first' => '', 'm' => 0];
        $st = $pdo->prepare("SELECT COUNT(*) FROM vol_hours WHERE user_id = ? AND source = 'meeting'");
        $st->execute([$uid]);
        $meetings = (int) $st->fetchColumn();
        $teams = array_map(static fn($t) => $t['name'] . ($t['my_role'] && $t['my_role'] !== 'member' ? ' — ' . $t['my_role'] : ''), Volunteers::teamsFor($u));
        $ngg = class_exists('NggVolunteers') ? NggVolunteers::forUser($u) : null;
        $issued = gmdate('Y-m-d');
        $code = self::letterCode($uid, $issued);
        $hours = round((int) $h['m'] / 60, 1);
        $since = (string) ($h['first'] ?: '');
        $rows = '<tr><td>Hours given</td><td><b>' . $e($hours) . '</b>' . ($since ? ' since ' . $e(date('j F Y', strtotime($since))) : '') . '</td></tr>'
              . '<tr><td>Meetings attended</td><td><b>' . $meetings . '</b></td></tr>';
        if ($teams) $rows .= '<tr><td>Teams</td><td>' . $e(implode('; ', $teams)) . '</td></tr>';
        if ($ngg) $rows .= '<tr><td>NextGen Genius</td><td>' . $e(trim(($ngg['role_label'] ?: ucfirst((string) $ngg['role'])) . ', ' . $ngg['centre'], ', ')) . ' — ' . (int) $ngg['sessions'] . ' sessions, ' . (int) $ngg['hours'] . ' hours' . ($ngg['level'] ? ', level ' . $e($ngg['level']) : '') . '</td></tr>';
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#15120e;line-height:1.6;margin:40px 48px}h1{font-size:22px;margin:0 0 4px}'
              . '.k{color:#9a5b00;text-transform:uppercase;letter-spacing:2px;font-size:10px;font-weight:bold}table{width:100%;border-collapse:collapse;margin:18px 0}td{padding:8px 6px;border-bottom:1px solid #e7e9ee;vertical-align:top}td:first-child{color:#5f6874;width:34%}'
              . '.f{margin-top:36px;font-size:10px;color:#5f6874}</style></head><body>'
              . '<p class="k">Afrovanguard</p><h1>Certificate of volunteer service</h1><p>This is to confirm that <b>' . $e($u['name']) . '</b> serves as a volunteer with Afrovanguard.</p>'
              . '<table>' . $rows . '</table>'
              . '<p>Their hours are recorded as they are given: meetings attended are counted from the published minutes, and other service as it is logged and confirmed. We are grateful for their time.</p>'
              . '<p class="f">Issued ' . $e(date('j F Y', strtotime($issued))) . ' · Verification code ' . $e($code) . ' · afrovanguard.org.ng</p></body></html>';
        try {
            $pdf = new \Dompdf\Dompdf(new \Dompdf\Options(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'tempDir' => sys_get_temp_dir()]));
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->setPaper('A4');
            $pdf->render();
            return ['bytes' => $pdf->output(), 'name' => 'volunteer-service-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $u['name'])) . '.pdf', 'code' => $code];
        } catch (Throwable $e2) { error_log('[volunteers] letter: ' . $e2->getMessage()); return null; }
    }
}
