<?php
/**
 * lib/NgvJourney.php — a vanguard's journey through NextGen Vanguard 2.0:
 * the level ladder, the 8-point Vanguard score, the five engines, the six
 * Academy schools and the evidence for the next level (design "Afrovanguard
 * Portal v4", Programme).
 *
 * WHERE THE NUMBERS COME FROM
 *   · Staff set them, one key per member, each with a note saying what the
 *     number rests on ("2 peer commendations", "led 1 chapter session"). The
 *     NGV office desk (academy/ngv/members.php → Journey) is the only writer.
 *   · Knowledge is the one figure derived when staff have not set it: verified
 *     books × 4 (24 books → 96), because the reading challenge already has a
 *     checked record of it. A staff value replaces it.
 *   · Nothing here is invented for a member: a key nobody has set reads 0 and
 *     the block that has no data at all is not shown.
 *
 * THE DEFAULT SCORING RULE (8 areas × 100 = 800)
 *   Each area is scored 0–100 by the office against evidence, and the note
 *   says which. Character: conduct and commendations (a breach costs points);
 *   Knowledge: books and courses; Skill: competencies signed off; Work: CV,
 *   portfolio, placements; Creation: things built and shipped; Service: hours
 *   and community-project steps; Leadership: sessions and roles led;
 *   Mentorship: members mentored to a milestone. The office can change any
 *   figure at any time; the history of who set it is kept.
 *
 * Programme-wide content (levels, pipeline, schools, the NGV 8, this quarter's
 * project, the chapter roster, summer mentorship, the summit) lives in the
 * NGV content document (lib/Ngv.php, edited at /academy/ngv/edit.php).
 */
declare(strict_types=1);

final class NgvJourney
{
    public const SCORE = [
        'character'  => 'Character',  'knowledge' => 'Knowledge', 'skill'   => 'Skill',      'work'       => 'Work',
        'creation'   => 'Creation',   'service'   => 'Service',   'leadership' => 'Leadership', 'mentorship' => 'Mentorship',
    ];
    public const ENGINES = ['learn' => 'Learn', 'build' => 'Build', 'serve' => 'Serve', 'connect' => 'Connect', 'lead' => 'Lead'];
    /** What a Member shows to become a Vanguard (counts staff record). */
    public const NEEDS = ['books' => 6, 'mentor' => 3, 'lead' => 1];
    public const PROJECT_STEPS = ['Problem', 'Research', 'Intervention', 'Budget', 'Execution', 'Measurement', 'Report'];

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        NgvDb::pdo()->exec('CREATE TABLE IF NOT EXISTS ngv_journey (
            member_id INTEGER NOT NULL,
            jkey VARCHAR(40) NOT NULL,
            value INTEGER NOT NULL DEFAULT 0,
            note VARCHAR(200) NOT NULL DEFAULT \'\',
            updated_by INTEGER NOT NULL DEFAULT 0,
            updated_at VARCHAR(19) NOT NULL,
            PRIMARY KEY (member_id, jkey)
        )');
    }

    /** Every key a member may carry, with its range. */
    public static function keys(): array
    {
        $k = ['level' => [0, 5], 'need:mentor' => [0, 50], 'need:lead' => [0, 50], 'need:project' => [0, 1]];
        foreach (self::SCORE as $id => $_)   $k['score:' . $id]  = [0, 100];
        foreach (self::ENGINES as $id => $_) $k['engine:' . $id] = [0, 100];
        for ($i = 0; $i < 12; $i++)          $k['school:' . $i]  = [0, 50];
        return $k;
    }

    /** How a key reads to staff: "Skill (score)", not "score:skill". */
    public static function label(string $k): string
    {
        [$g, $id] = array_pad(explode(':', $k, 2), 2, '');
        return match ($g) {
            'score'  => (self::SCORE[$id] ?? $id) . ' (score)',
            'engine' => (self::ENGINES[$id] ?? $id) . ' (engine)',
            'school' => 'School ' . ((int) $id + 1) . ' modules',
            'need'   => ['mentor' => 'Members mentored', 'lead' => 'Chapter sessions led', 'project' => 'Community project'][$id] ?? $k,
            'level'  => 'Level',
            default  => $k,
        };
    }

    /** @return array<string,array{value:int,note:string}> */
    public static function raw(int $memberId): array
    {
        self::ensure();
        $st = NgvDb::pdo()->prepare('SELECT jkey, value, note FROM ngv_journey WHERE member_id = ?');
        $st->execute([$memberId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(string) $r['jkey']] = ['value' => (int) $r['value'], 'note' => (string) $r['note']];
        return $out;
    }

    /**
     * Staff set some keys at once. Validated first, then written: an unknown key
     * or an out-of-range value refuses the whole set and names it.
     * @param array<string,array{0:int|string,1?:string}|int> $values
     */
    public static function set(int $memberId, array $values, int $byUid): array
    {
        self::ensure();
        $keys = self::keys(); $rows = [];
        foreach ($values as $k => $v) {
            $k = (string) $k;
            if (!isset($keys[$k])) return ['ok' => false, 'error' => 'Unknown journey key: ' . $k];
            [$val, $note] = is_array($v) ? [$v[0] ?? 0, (string) ($v[1] ?? '')] : [$v, null];
            if (!is_numeric($val)) return ['ok' => false, 'error' => self::label($k) . ' must be a number.'];
            $val = (int) $val; [$lo, $hi] = $keys[$k];
            if ($val < $lo || $val > $hi) return ['ok' => false, 'error' => self::label($k) . ' must be between ' . $lo . ' and ' . $hi . '.'];
            $rows[$k] = [$val, $note === null ? null : mb_substr(trim($note), 0, 200)];
        }
        $db = NgvDb::pdo(); $now = date('Y-m-d H:i:s');
        $db->beginTransaction();
        try {
            foreach ($rows as $k => [$val, $note]) {
                $old = $db->prepare('SELECT note FROM ngv_journey WHERE member_id = ? AND jkey = ?');
                $old->execute([$memberId, $k]);
                $prev = $old->fetchColumn();
                $note = $note ?? ($prev === false ? '' : (string) $prev);
                $db->prepare('DELETE FROM ngv_journey WHERE member_id = ? AND jkey = ?')->execute([$memberId, $k]);
                $db->prepare('INSERT INTO ngv_journey (member_id, jkey, value, note, updated_by, updated_at) VALUES (?,?,?,?,?,?)')
                   ->execute([$memberId, $k, $val, $note, $byUid, $now]);
            }
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); return ['ok' => false, 'error' => 'Could not save: ' . $e->getMessage()]; }
        return ['ok' => true, 'saved' => count($rows)];
    }

    /**
     * Everything the Programme view draws, for one member.
     * @param array $c the NGV content document (Ngv::get())
     */
    public static function view(int $memberId, array $c): array
    {
        $r = self::raw($memberId);
        $val  = static fn(string $k): int => (int) ($r[$k]['value'] ?? 0);
        $note = static fn(string $k): string => (string) ($r[$k]['note'] ?? '');
        $read = class_exists('NgvReading') ? NgvReading::verifiedCount($memberId) : 0;

        $levels = array_values(array_filter(array_map('strval', (array) ($c['j_levels'] ?? [])), 'strlen'));
        $level  = max(0, min(count($levels) - 1, $val('level')));

        $score = []; $total = 0;
        foreach (self::SCORE as $id => $label) {
            $set = isset($r['score:' . $id]);
            $v = $set ? $val('score:' . $id) : ($id === 'knowledge' ? min(100, $read * 4) : 0);
            $e = $set ? $note('score:' . $id) : ($id === 'knowledge' ? $read . ' verified ' . ($read === 1 ? 'book' : 'books') : '');
            $score[] = ['id' => $id, 'label' => $label, 'value' => $v, 'note' => $e];
            $total += $v;
        }
        $engines = [];
        foreach (self::ENGINES as $id => $label) $engines[] = ['label' => $label, 'value' => $val('engine:' . $id), 'note' => $note('engine:' . $id)];

        $schools = [];
        foreach (array_values((array) ($c['j_schools'] ?? [])) as $i => $s) {
            if (!is_array($s) || trim((string) ($s['name'] ?? '')) === '') continue;
            $of = max(1, (int) ($s['modules'] ?? 6));
            $schools[] = ['name' => (string) $s['name'], 'desc' => (string) ($s['desc'] ?? ''), 'done' => min($of, $val('school:' . $i)), 'of' => $of];
        }

        /* The evidence for Vanguard. Each line says where it stands; a line is
           ticked only when the record shows it. Conduct reads the fines desk:
           an open fine this term is a breach on record. */
        $openFines = 0;
        if (class_exists('NgvFines')) {
            try { $openFines = (int) (NgvFines::forMember($memberId)['open'] ?? 0); } catch (Throwable $e) {}
        }
        $mentor = $val('need:mentor'); $lead = $val('need:lead'); $proj = $val('need:project');
        $needs = [
            ['t' => 'Read ' . self::NEEDS['books'] . ' verified books', 'v' => min($read, 99) . ' / ' . self::NEEDS['books'], 'ok' => $read >= self::NEEDS['books']],
            ['t' => 'Contribute to a community project', 'v' => $proj ? 'Done' : 'In progress', 'ok' => (bool) $proj],
            ['t' => 'Mentor ' . self::NEEDS['mentor'] . ' members', 'v' => $mentor . ' / ' . self::NEEDS['mentor'], 'ok' => $mentor >= self::NEEDS['mentor']],
            ['t' => 'Lead a chapter session', 'v' => $lead . ' / ' . self::NEEDS['lead'], 'ok' => $lead >= self::NEEDS['lead']],
            ['t' => 'Hold the values, with no breaches this term', 'v' => $openFines ? $openFines . ' on record' : 'Clear', 'ok' => $openFines === 0],
        ];

        return [
            'levels' => $levels, 'level' => $level, 'needs' => $needs,
            'nextIsVanguard' => isset($levels[2]) && $level < 2,
            'score' => $score, 'total' => $total, 'scored' => count(array_filter(array_keys($r), static fn($k) => str_starts_with((string) $k, 'score:'))) > 0 || $read > 0,
            'engines' => $engines, 'enginesSet' => count(array_filter($engines, static fn($e) => $e['value'] > 0)) > 0,
            'schools' => $schools, 'read' => $read,
        ];
    }
}
