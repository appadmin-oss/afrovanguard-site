<?php
/**
 * lib/QuestDesk.php — the read model behind the Quest desk at
 * /mentorship/compliance/.
 *
 * ONE REGISTRY OF SECTIONS. The sidebar, the hash routes and who may see what
 * are all derived from SECTIONS, never from a second list — the same rule as
 * NGG's Control Room, for the same reason: parallel lists drift until a link
 * works and its page does not.
 *
 * Everything a section lists is assembled in grouped queries. A roster that
 * asked the database once per member was the defect NGG's handoff names
 * first; this desk does not repeat it.
 */
declare(strict_types=1);

final class QuestDesk
{
    /**
     * id => [group, label, title, who]. `who`: 'decide' — the compliance
     * committee and superadmins; 'edit' — superadmins only (prices and rules
     * are policy). A section a person cannot use is not in their sidebar.
     */
    public const SECTIONS = [
        'overview'    => ['Desk',     'Overview',          'The Vanguard Quest',             'decide'],
        'cases'       => ['Desk',     'Cases',             'Waiting for a decision',         'decide'],
        'suggestions' => ['Desk',     'Suggestions',       'What the manual calls for',      'decide'],
        'promotions'  => ['Desk',     'Promotions',        'Ready for the next level',       'decide'],
        'members'     => ['Register', 'Members',           'Everybody in the Quest',         'decide'],
        'money'       => ['Register', 'Money',             'Points against fines',           'decide'],
        'catalogue'   => ['Policy',   'Catalogue',         'Awards, deductions and fines',   'decide'],
        'rules'       => ['Policy',   'Rules',             'Every rule, switchable',         'edit'],
        'advisor'     => ['Policy',   'AI advisor',        'Optimise · strategise · rethink', 'edit'],
        'people'      => ['Setup',    'Committee & kinds', 'Who decides, and which mentors',  'decide'],
    ];

    /** @return array<string,array> the sections this person may open, in order */
    public static function sectionsFor(array $user): array
    {
        $edit = QuestRules::canEdit($user);
        $out = [];
        foreach (self::SECTIONS as $id => [$group, $label, $title, $who]) {
            if ($who === 'edit' && !$edit) continue;
            $out[$id] = ['group' => $group, 'label' => $label, 'title' => $title];
        }
        return $out;
    }

    /** The figures on the Overview, and the badges in the sidebar. */
    public static function overview(): array
    {
        $db = Database::pdo();
        $month = gmdate('Y-m');
        $one = static function (string $sql, array $a = []) use ($db): int {
            try { $st = $db->prepare($sql); $st->execute($a); return (int) $st->fetchColumn(); }
            catch (Throwable $e) { return 0; }
        };
        Conduct::ensure(); QuestPoints::ensure(); QuestLadder::ensure(); QuestRecord::ensure();
        return [
            'waiting'      => $one("SELECT COUNT(*) FROM conduct_cases WHERE status = 'proposed'"),
            'suggestions'  => $one("SELECT COUNT(*) FROM quest_suggestions WHERE status = 'open'"),
            'awarded'      => $one('SELECT COALESCE(SUM(points), 0) FROM quest_points WHERE points > 0 AND day LIKE ?', [$month . '-%']),
            'deducted'     => -$one('SELECT COALESCE(SUM(points), 0) FROM quest_points WHERE points < 0 AND day LIKE ?', [$month . '-%']),
            'members_pts'  => $one('SELECT COUNT(DISTINCT member_id) FROM quest_points'),
            'findings'     => $one("SELECT COUNT(*) FROM quest_findings WHERE status = 'open'"),
            'decided_30'   => $one("SELECT COUNT(*) FROM conduct_cases WHERE status IN ('approved','rejected') AND decided_at >= ?", [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]),
            'drift'        => QuestRules::drift(),
            'money_on'     => AvRules::bool('quest.money_enabled'),
            'money_ready'  => QuestMoney::ready(),
            'pool'         => QuestMoney::poolTotal(),
            'month'        => $month,
        ];
    }

    /**
     * The register: everybody with an account, with their standing in the
     * Quest. Six grouped queries for any number of members.
     *
     * @return array<int,array>
     */
    public static function roster(string $q = '', int $limit = 400): array
    {
        $db = Database::pdo();
        Levels::ensure(); QuestPoints::ensure(); QuestRecord::ensure(); QuestMoney::ensure(); Mentorship::ensure();
        $q = trim($q);
        $where = "COALESCE(u.status, 'active') = 'active'";
        $args = [];
        if ($q !== '') { $where .= ' AND (LOWER(u.name) LIKE ? OR LOWER(u.email) LIKE ?)'; $like = '%' . mb_strtolower($q) . '%'; $args = [$like, $like]; }
        try {
            $st = $db->prepare("SELECT u.id, u.name, u.email, u.level FROM lms_users u WHERE $where ORDER BY u.name LIMIT " . max(1, min(2000, $limit)));
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
        if (!$rows) return [];
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $in = implode(',', $ids);
        $map = static function (string $sql) use ($db): array {
            $out = [];
            try { foreach ($db->query($sql) ?: [] as $r) $out[(int) $r['k']] = $r['v']; } catch (Throwable $e) { /* none */ }
            return $out;
        };
        $points  = $map("SELECT member_id AS k, SUM(points) AS v FROM quest_points WHERE member_id IN ($in) GROUP BY member_id");
        $month   = $map("SELECT member_id AS k, SUM(points) AS v FROM quest_points WHERE points > 0 AND day LIKE '" . gmdate('Y-m') . "-%' AND member_id IN ($in) GROUP BY member_id");
        $journey = $map("SELECT member_id AS k, level AS v FROM quest_journey WHERE member_id IN ($in)");
        $finds   = $map("SELECT member_id AS k, COUNT(*) AS v FROM quest_findings WHERE status = 'open' AND member_id IN ($in) GROUP BY member_id");
        $bracket = $map("SELECT m.member_id AS k, b.label AS v FROM quest_member_brackets m JOIN quest_income_brackets b ON b.id = m.bracket_id WHERE m.member_id IN ($in)");
        $cut = gmdate('Y-m-d H:i:s', time() - max(1, AvRules::int('mentorship.active_mentee_requires_days')) * 86400);
        $mentees = $map("SELECT m.mentor_id AS k, COUNT(DISTINCT m.mentee_id) AS v FROM mentorships m JOIN mentor_sessions s ON s.mentorship_id = m.id
                         WHERE m.status = 'active' AND m.kind = 'vquest' AND s.attendance = 'attended' AND s.scheduled_at >= '$cut' AND m.mentor_id IN ($in)
                         GROUP BY m.mentor_id");
        $journeyNames = [];
        foreach (Quest::journey() as $j) $journeyNames[(string) $j['id']] = (string) $j['name'];
        $order = Levels::order(); $base = Levels::base();
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $lvl = in_array((string) $r['level'], $order, true) ? (string) $r['level'] : $base;
            $pts = (int) ($points[$id] ?? 0);
            $men = (int) ($mentees[$id] ?? 0);
            $out[] = [
                'id' => $id, 'name' => (string) $r['name'], 'email' => (string) $r['email'],
                'level' => $lvl, 'level_label' => Levels::labelOf($lvl),
                'qualifies' => Quest::rankFor($pts, $men),
                'journey' => $journeyNames[(string) ($journey[$id] ?? '')] ?? 'Visitor',
                'journey_id' => isset($journey[$id], $journeyNames[(string) $journey[$id]]) ? (string) $journey[$id] : QuestRecord::JOURNEY_DEFAULT,
                'points' => $pts, 'month' => (int) ($month[$id] ?? 0), 'mentees' => $men,
                'findings' => (int) ($finds[$id] ?? 0), 'bracket' => (string) ($bracket[$id] ?? ''),
            ];
        }
        return $out;
    }

    /** The rows of the "Vanguard Quest" rule group, as AvRules describes them. */
    public static function rules(): array
    {
        return AvRules::describe()['groups']['Vanguard Quest'] ?? [];
    }

    /** @return string[] the keys the Rules section may save — that group, and only that group */
    public static function ruleKeys(): array
    {
        return array_map(static fn($r) => (string) $r['key'], self::rules());
    }

    public static function ruleDef(string $key): ?array
    {
        foreach (self::rules() as $r) if ($r['key'] === $key) return $r;
        return null;
    }

    /**
     * The committee promotes a member one level, on a recommendation it can
     * see. Never oneself; never more than one rung; through Levels::set, so the
     * change is the same record the Studio's member desk writes — and the
     * mentor's "mentee rises" follows from it.
     */
    public static function promote(int $memberId, string $to, array $by): array
    {
        if (!Conduct::canDecide($by)) return ['ok' => false, 'error' => 'Only the compliance committee or a superadmin promotes.'];
        if ($memberId <= 0) return ['ok' => false, 'error' => 'No such member.'];
        if ((int) $by['id'] === $memberId) return ['ok' => false, 'error' => 'This is about you, so somebody else must decide it.'];
        $next = Levels::nextOf(Levels::of($memberId));
        if ($next === null || $to !== $next) return ['ok' => false, 'error' => 'A promotion is one level at a time, to the next one.'];
        if (!Levels::set($memberId, $to, (string) ($by['email'] ?? ('user#' . (int) $by['id'])))) return ['ok' => false, 'error' => 'The level could not be changed.'];
        if (class_exists('Notifications')) {
            try { Notifications::push($memberId, 'award', 'You have been promoted to ' . Levels::labelOf($to), 'Confirmed by the compliance committee.', '/portal/#quest', 'level:' . $to); }
            catch (Throwable $e) { /* best-effort */ }
        }
        if (class_exists('AdminAudit')) {
            try { AdminAudit::log('quest', 'promote', 'member#' . $memberId, 'to ' . $to, null, 'user#' . (int) $by['id']); } catch (Throwable $e) { /* best-effort */ }
        }
        return ['ok' => true, 'level' => $to];
    }

    /** "₦12,000" */
    public static function naira(?int $n): string
    {
        return $n === null ? 'priced each time' : '₦' . number_format($n);
    }
}
