<?php
/**
 * lib/QuestAdvisor.php — the AI advisor on the Quest's priced catalogue.
 *
 * The owner, 22 October 2026: Section 2 of the Pyramid Manual is the
 * catalogue, "+ AI features to optimize, strategize, update and rethink".
 *
 * Two layers, deliberately apart:
 *
 *   usage() / signals()  FIGURES, computed here in grouped queries: how often
 *                        each line was put forward, approved and rejected,
 *                        how many mentors use it and how lopsided that is,
 *                        what the monthly ceiling clipped, what has never
 *                        been used. Signals are rules over those figures, and
 *                        they work with no model at all.
 *   ask($mode)           the MODEL, given the catalogue, the figures and the
 *                        signals, in one of four modes — optimise, strategise,
 *                        update, rethink — returning proposals, ideas and the
 *                        questions only leadership can answer.
 *
 * Every proposal, from either layer, is validated against QuestRules (a line
 * that exists, a price inside its bounds) before it is stored, and applied
 * only when a superadmin presses Apply — through QuestRules::set, so it lands
 * in the same history as a change made by hand, with the advice cited.
 * Observe, record, analyse, recommend: the machine. Decide: a person.
 */
declare(strict_types=1);

final class QuestAdvisor
{
    public const MODES = [
        'optimize'   => 'Optimise — tune prices and switches to how the catalogue is actually used',
        'strategize' => 'Strategise — what the catalogue should reward more or less to move members up the pyramid',
        'update'     => 'Update — lines that are stale, unused, lopsided or clipped, and what to do about each',
        'rethink'    => 'Rethink — what the catalogue is missing or should stop pricing, from first principles',
    ];

    /** Tests replace the model: fn(string $system, string $user): array{ok,text,provider}. */
    public static ?Closure $model = null;

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS quest_advice (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                mode        VARCHAR(16) NOT NULL,
                summary     TEXT NOT NULL,
                proposals   TEXT NOT NULL,
                ideas       TEXT NOT NULL,
                questions   TEXT NOT NULL,
                source      VARCHAR(40) NOT NULL DEFAULT '',
                applied     TEXT NOT NULL,
                created_by  INTEGER NOT NULL DEFAULT 0,
                created_at  VARCHAR(32) NOT NULL DEFAULT ''
            )");
        } catch (Throwable $e) {
            error_log('[questadvisor] ensure: ' . $e->getMessage());
        }
    }

    public static function enabled(): bool
    {
        return AvRules::bool('quest.ai_advisor');
    }

    /* ── Figures ──────────────────────────────────────────────────────── */

    /**
     * Per catalogue line over the last $days: proposed, approved, rejected,
     * points (or ₦) granted, distinct mentors and members, the top mentor's
     * share, and how often the monthly ceiling clipped it. Two queries.
     *
     * @return array{days:int, lines:array<string,array>, totals:array}
     */
    public static function usage(int $days = 90): array
    {
        $days = max(7, min(730, $days));
        Conduct::ensure();
        $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $db = Database::pdo();
        $lines = [];
        foreach (array_keys(QuestRules::KINDS) as $k) {
            foreach (QuestRules::items($k) as $id => $i) {
                $lines[$k . ':' . $id] = ['kind' => $k, 'id' => $id, 'label' => (string) $i['label'], 'value' => $i['value'], 'base' => $i['base'],
                    'enabled' => (bool) $i['enabled'], 'proposed' => 0, 'approved' => 0, 'rejected' => 0, 'granted' => 0,
                    'mentors' => 0, 'members' => 0, 'top_mentor_share' => 0, 'clipped' => 0];
            }
        }
        try {
            $st = $db->prepare("SELECT kind, action_id,
                                       COUNT(*) AS proposed,
                                       SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
                                       SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                                       SUM(CASE WHEN status = 'approved' THEN (CASE WHEN kind = 'fine' THEN amount ELSE points END) ELSE 0 END) AS granted,
                                       SUM(CASE WHEN outcome LIKE '%monthly ceiling%' THEN 1 ELSE 0 END) AS clipped,
                                       COUNT(DISTINCT mentor_id) AS mentors, COUNT(DISTINCT mentee_id) AS members
                                  FROM conduct_cases
                                 WHERE action_id <> '' AND created_at >= ?
                                 GROUP BY kind, action_id");
            $st->execute([$since]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $key = $r['kind'] . ':' . $r['action_id'];
                if (!isset($lines[$key])) continue;
                foreach (['proposed', 'approved', 'rejected', 'granted', 'clipped', 'mentors', 'members'] as $f) $lines[$key][$f] = (int) $r[$f];
            }
            $st = $db->prepare("SELECT kind, action_id, mentor_id, COUNT(*) AS n FROM conduct_cases
                                 WHERE action_id <> '' AND created_at >= ? GROUP BY kind, action_id, mentor_id");
            $st->execute([$since]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $key = $r['kind'] . ':' . $r['action_id'];
                if (!isset($lines[$key]) || $lines[$key]['proposed'] < 1) continue;
                $share = (int) round(100 * (int) $r['n'] / $lines[$key]['proposed']);
                if ($share > $lines[$key]['top_mentor_share']) $lines[$key]['top_mentor_share'] = $share;
            }
        } catch (Throwable $e) { error_log('[questadvisor] usage: ' . $e->getMessage()); }

        $totals = ['proposed' => 0, 'approved' => 0, 'rejected' => 0, 'points_awarded' => 0, 'points_deducted' => 0, 'fined' => 0];
        foreach ($lines as $l) {
            $totals['proposed'] += $l['proposed']; $totals['approved'] += $l['approved']; $totals['rejected'] += $l['rejected'];
            if ($l['kind'] === 'award') $totals['points_awarded'] += $l['granted'];
            elseif ($l['kind'] === 'deduction') $totals['points_deducted'] += $l['granted'];
            else $totals['fined'] += $l['granted'];
        }
        return ['days' => $days, 'lines' => $lines, 'totals' => $totals, 'ladder' => self::ladder()];
    }

    /** How the register sits on the pyramid: held level vs. what points and mentees qualify for. */
    public static function ladder(): array
    {
        $out = ['held' => [], 'qualifies_above_held' => 0, 'members' => 0];
        try {
            $db = Database::pdo();
            Levels::ensure();
            $pts = [];
            foreach ($db->query('SELECT member_id, SUM(points) AS p FROM quest_points GROUP BY member_id') ?: [] as $r) $pts[(int) $r['member_id']] = (int) $r['p'];
            $order = Levels::order();
            foreach ($db->query("SELECT id, level FROM lms_users WHERE COALESCE(status, 'active') = 'active'") ?: [] as $r) {
                $lvl = in_array((string) $r['level'], $order, true) ? (string) $r['level'] : Levels::base();
                $out['held'][$lvl] = ($out['held'][$lvl] ?? 0) + 1;
                $out['members']++;
                $p = $pts[(int) $r['id']] ?? 0;
                if ($p <= 0) continue;
                /* Points alone, as an upper bound: mentees are checked per member
                   when a promotion is reviewed, not across the register here. */
                $q = Quest::rankFor($p, PHP_INT_MAX);
                if (array_search($q, $order, true) > array_search($lvl, $order, true)) $out['qualifies_above_held']++;
            }
        } catch (Throwable $e) { /* empty */ }
        return $out;
    }

    /**
     * Rules over the figures — what a careful person would flag first. Each
     * signal may carry a concrete proposal; none applies itself.
     * @return array<int,array{level:string, kind:string, id:string, text:string, proposal:?array}>
     */
    public static function signals(?array $usage = null): array
    {
        $u = $usage ?? self::usage();
        $out = [];
        $anyUse = $u['totals']['proposed'] > 0;
        $unused = [];
        foreach ($u['lines'] as $l) {
            if (!$l['enabled']) continue;
            if ($anyUse && $l['proposed'] === 0 && $l['kind'] !== 'fine' && $l['id'] !== 'mentee_rises') {
                $unused[] = '"' . $l['label'] . '"';
            }
            if ($l['proposed'] >= 6 && $l['rejected'] / max(1, $l['proposed']) >= 0.4) {
                $out[] = ['level' => 'warn', 'kind' => $l['kind'], 'id' => $l['id'],
                    'text' => '"' . $l['label'] . '" is rejected ' . (int) round(100 * $l['rejected'] / $l['proposed']) . '% of the time (' . $l['rejected'] . ' of ' . $l['proposed'] . '): mentors and the committee read it differently.',
                    'proposal' => null];
            }
            if ($l['proposed'] >= 8 && $l['top_mentor_share'] >= 60 && $l['mentors'] >= 1) {
                $out[] = ['level' => 'warn', 'kind' => $l['kind'], 'id' => $l['id'],
                    'text' => 'One mentor accounts for ' . $l['top_mentor_share'] . '% of "' . $l['label'] . '" — a habit of one person, not a standard.', 'proposal' => null];
            }
            if ($l['kind'] === 'award' && $l['approved'] >= 4 && $l['clipped'] / max(1, $l['approved']) >= 0.5 && is_int($l['value'])) {
                $to = max(1, (int) floor($l['value'] * 0.75));
                $out[] = ['level' => 'warn', 'kind' => 'award', 'id' => $l['id'],
                    'text' => '"' . $l['label'] . '" is clipped by the monthly ceiling in ' . $l['clipped'] . ' of ' . $l['approved'] . ' approvals: members earning it are already at their cap.',
                    'proposal' => ['kind' => 'award', 'id' => $l['id'], 'enabled' => null, 'value' => $to, 'why' => 'Clipped by the ceiling in ' . $l['clipped'] . ' of ' . $l['approved'] . ' approvals.']];
            }
        }
        /* One line for every deed nobody has used, not one line each — a list
           of twenty "never used" would bury what actually needs reading. */
        if ($unused) {
            $out[] = ['level' => 'info', 'kind' => '', 'id' => '',
                'text' => count($unused) . ' line(s) have not been put forward once in ' . $u['days'] . ' days: ' . implode(', ', array_slice($unused, 0, 8))
                        . (count($unused) > 8 ? ' and ' . (count($unused) - 8) . ' more' : '') . '.', 'proposal' => null];
        }
        $lad = $u['ladder'] ?? [];
        if (($lad['qualifies_above_held'] ?? 0) > 0) {
            $out[] = ['level' => 'info', 'kind' => '', 'id' => '',
                'text' => $lad['qualifies_above_held'] . ' member(s) have the points for a level above the one they hold — the promotion queue is where their mentees are checked.', 'proposal' => null];
        }
        return $out;
    }

    /* ── The model ────────────────────────────────────────────────────── */

    /**
     * Ask the model, in one mode. With no model available the advice is the
     * signals alone, marked as such — it never pretends a model looked.
     */
    public static function ask(string $mode, array $by): array
    {
        if (!QuestRules::canEdit($by)) return ['ok' => false, 'error' => 'Only a superadmin asks the advisor — its proposals change prices.'];
        if (!self::enabled()) return ['ok' => false, 'error' => 'The AI advisor is switched off.'];
        if (!isset(self::MODES[$mode])) return ['ok' => false, 'error' => 'Choose optimise, strategise, update or rethink.'];

        $u = self::usage();
        $sig = self::signals($u);
        $advice = ['summary' => '', 'proposals' => [], 'ideas' => [], 'questions' => [], 'source' => 'rules-only'];
        foreach ($sig as $s) if ($s['proposal']) $advice['proposals'][] = $s['proposal'];

        $model = self::$model !== null
              || (AvRules::bool('ai.enabled') && class_exists('AvPrompts') && AvPrompts::isKey('quest.advisor') && class_exists('AvAgent'));
        if ($model) {
            $sys = AvPrompts::render('quest.advisor', [
                'mode' => self::MODES[$mode], 'catalogue' => self::catalogueText(),
                'usage' => self::usageText($u), 'signals' => $sig ? implode("\n", array_map(static fn($s) => '- ' . $s['text'], $sig)) : '- nothing flagged',
            ]);
            $r = trim($sys) === '' ? ['ok' => false]
               : (self::$model !== null ? (self::$model)($sys, 'Advise now.')
                  : AvAgent::complete($sys, 'Advise now.', ['max_tokens' => 1600, 'temperature' => 0.3, 'actor' => 'quest-advisor']));
            $j = !empty($r['ok']) ? self::json((string) $r['text']) : null;
            if (is_array($j)) {
                $advice['summary'] = mb_substr(trim((string) ($j['summary'] ?? '')), 0, 2000);
                foreach ((array) ($j['proposals'] ?? []) as $p) {
                    $v = self::validProposal(is_array($p) ? $p : []);
                    if ($v) $advice['proposals'][] = $v;
                }
                foreach (array_slice((array) ($j['ideas'] ?? []), 0, 5) as $i) {
                    if (!is_array($i) || trim((string) ($i['label'] ?? '')) === '') continue;
                    $advice['ideas'][] = ['label' => mb_substr(trim((string) $i['label']), 0, 160),
                        'kind' => ($i['kind'] ?? '') === 'deduction' ? 'deduction' : 'award',
                        'points' => max(1, min(1000, (int) ($i['points'] ?? 0))), 'why' => mb_substr(trim((string) ($i['why'] ?? '')), 0, 400)];
                }
                foreach (array_slice((array) ($j['questions'] ?? []), 0, 6) as $q) {
                    $q = trim((string) $q);
                    if ($q !== '') $advice['questions'][] = mb_substr($q, 0, 300);
                }
                $advice['source'] = (string) ($r['provider'] ?? 'ai');
            }
        }
        if ($advice['summary'] === '') {
            $advice['summary'] = $sig
                ? count($sig) . ' thing(s) flagged by the figures alone' . ($model ? ' (the model gave no usable answer).' : ' (no model is switched on).')
                : 'Nothing in the figures to flag yet' . ($u['totals']['proposed'] < 10 ? ' — fewer than ten proposals in ' . $u['days'] . ' days is too thin to tune a catalogue on.' : '.');
        }
        $advice['proposals'] = self::dedupe($advice['proposals']);

        self::ensure();
        try {
            Database::pdo()->prepare('INSERT INTO quest_advice (mode, summary, proposals, ideas, questions, source, applied, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$mode, $advice['summary'], json_encode($advice['proposals']), json_encode($advice['ideas']), json_encode($advice['questions']),
                           mb_substr($advice['source'], 0, 40), '[]', (int) $by['id'], gmdate('Y-m-d H:i:s')]);
            $id = (int) Database::pdo()->lastInsertId();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'The advice could not be kept.'];
        }
        return ['ok' => true, 'advice' => self::find($id)];
    }

    public static function find(int $id): ?array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM quest_advice WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return null; }
        if (!$r) return null;
        foreach (['proposals', 'ideas', 'questions', 'applied'] as $f) $r[$f] = json_decode((string) $r[$f], true) ?: [];
        return $r;
    }

    /** @return array<int,array> newest first */
    public static function recent(int $limit = 10): array
    {
        self::ensure();
        try {
            $ids = Database::pdo()->query('SELECT id FROM quest_advice ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)))->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) { return []; }
        return array_values(array_filter(array_map(static fn($i) => self::find((int) $i), $ids)));
    }

    /**
     * A superadmin applies proposal $index of advice $id. Through QuestRules,
     * so it is in the same history as a hand-made change, with the advice
     * cited. Once each.
     */
    public static function apply(int $id, int $index, array $by): array
    {
        if (!QuestRules::canEdit($by)) return ['ok' => false, 'error' => 'Only a superadmin applies the advisor\'s proposals.'];
        $a = self::find($id);
        if (!$a) return ['ok' => false, 'error' => 'No such advice.'];
        $p = $a['proposals'][$index] ?? null;
        if (!$p) return ['ok' => false, 'error' => 'No such proposal.'];
        if (in_array($index, $a['applied'], true)) return ['ok' => false, 'error' => 'That proposal was already applied.'];
        if (!self::validProposal($p)) return ['ok' => false, 'error' => 'The catalogue has changed since; that proposal no longer fits it.'];
        $change = [];
        if ($p['enabled'] !== null) $change['enabled'] = (bool) $p['enabled'];
        if ($p['value'] !== null) $change['value'] = (int) $p['value'];
        $r = QuestRules::set((string) $p['kind'], (string) $p['id'], $change, $by, 'AI advisor #' . $id . ': ' . mb_substr((string) $p['why'], 0, 240));
        if (empty($r['ok'])) return $r;
        $applied = $a['applied']; $applied[] = $index;
        Database::pdo()->prepare('UPDATE quest_advice SET applied = ? WHERE id = ?')->execute([json_encode($applied), $id]);
        return ['ok' => true, 'change' => $r['change'] ?? 'unchanged'];
    }

    /* ── Helpers ──────────────────────────────────────────────────────── */

    /** A proposal that names a real line and a price inside its bounds, or null. */
    public static function validProposal(array $p): ?array
    {
        $kind = (string) ($p['kind'] ?? '');
        $id = (string) ($p['id'] ?? '');
        if (!isset(QuestRules::KINDS[$kind])) return null;
        $line = QuestRules::item($kind, $id);
        if (!$line) return null;
        $enabled = array_key_exists('enabled', $p) && $p['enabled'] !== null ? (bool) $p['enabled'] : null;
        $value = array_key_exists('value', $p) && $p['value'] !== null && is_numeric($p['value']) ? (int) $p['value'] : null;
        if ($value !== null && ($value < ($kind === 'fine' ? 0 : 1) || $value > QuestRules::KINDS[$kind][3])) return null;
        if ($enabled === null && $value === null) return null;
        if ($enabled === (bool) $line['enabled'] && ($value === null || $value === $line['value'])) return null;
        if ($enabled === null && $value === $line['value']) return null;
        return ['kind' => $kind, 'id' => $id, 'enabled' => $enabled, 'value' => $value, 'why' => mb_substr(trim((string) ($p['why'] ?? '')), 0, 400)];
    }

    private static function dedupe(array $ps): array
    {
        $seen = []; $out = [];
        foreach ($ps as $p) {
            $k = $p['kind'] . ':' . $p['id'];
            if (isset($seen[$k])) continue;
            $seen[$k] = true; $out[] = $p;
        }
        return $out;
    }

    private static function catalogueText(): string
    {
        $lines = [];
        foreach (array_keys(QuestRules::KINDS) as $k) {
            foreach (QuestRules::items($k) as $id => $i) {
                $unit = $k === 'fine' ? '₦' : 'pts ';
                $lines[] = $id . ' · ' . $k . ' · ' . ($i['value'] === null ? 'priced each time' : $unit . $i['value'])
                         . ' · manual ' . ($i['base'] === null ? 'each time' : $unit . $i['base']) . ' · ' . ($i['enabled'] ? 'on' : 'OFF') . ' · ' . $i['label'];
            }
        }
        return implode("\n", $lines);
    }

    private static function usageText(array $u): string
    {
        $t = $u['totals'];
        $lines = ['Last ' . $u['days'] . ' days: ' . $t['proposed'] . ' proposed, ' . $t['approved'] . ' approved, ' . $t['rejected'] . ' rejected; '
                . $t['points_awarded'] . ' points awarded, ' . $t['points_deducted'] . ' deducted, ₦' . number_format($t['fined']) . ' fined.'];
        foreach ($u['lines'] as $l) {
            if ($l['proposed'] === 0) continue;
            $lines[] = $l['id'] . ': proposed ' . $l['proposed'] . ', approved ' . $l['approved'] . ', rejected ' . $l['rejected']
                     . ', by ' . $l['mentors'] . ' mentor(s) for ' . $l['members'] . ' member(s), top mentor ' . $l['top_mentor_share'] . '%'
                     . ($l['clipped'] ? ', clipped by ceiling ' . $l['clipped'] . 'x' : '');
        }
        $lad = $u['ladder'];
        if ($lad['members'] ?? 0) {
            $held = [];
            foreach ($lad['held'] as $lvl => $n) $held[] = $lvl . ' ' . $n;
            $lines[] = 'Register by level held: ' . implode(', ', $held) . '. With points enough for a higher level: ' . $lad['qualifies_above_held'] . '.';
        }
        return implode("\n", $lines);
    }

    private static function json(string $text): ?array
    {
        $t = trim($text);
        if (preg_match('/\{.*\}/s', $t, $m)) $t = $m[0];
        $j = json_decode($t, true);
        return is_array($j) ? $j : null;
    }
}
