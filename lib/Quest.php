<?php
/**
 * lib/Quest.php — the Vanguard Quest's rules, read rather than restated.
 *
 * The rules live in appadmin-oss/van-quest: the seven values, the seventy
 * challenges, the ranks, the journey levels, the awardable actions and the
 * fines registry, as JSON with a schema each and a validator that holds them
 * to their invariants. lib/quest/ is a VENDORED COPY of that registry, and
 * this class is the only thing in this repository that reads it.
 *
 * WHY A COPY AND NOT A FETCH
 *   This site runs on shared hosting with no shell. A rule the Quest depends
 *   on cannot be behind a network call that must never fail. The copy is
 *   refreshed deliberately, and tests/quest.test.php holds it to the same
 *   invariants the source validator does, so a copy that has drifted into
 *   nonsense fails here rather than scoring somebody wrongly.
 *
 * NOTHING IS RESTATED HERE. No threshold, no rank band, no fine amount, no
 * count of seventy is written into this file. If a number appears in code that
 * also appears in the registry, one of them is already wrong.
 */
declare(strict_types=1);

final class Quest
{
    /** Tests replace the registry wholesale: ['challenges' => [...], ...]. */
    public static ?array $override = null;

    private static array $cache = [];

    private static function load(string $name): array
    {
        if (self::$override !== null && isset(self::$override[$name])) return self::$override[$name];
        if (isset(self::$cache[$name])) return self::$cache[$name];
        $p = __DIR__ . '/quest/' . $name . '.json';
        $d = is_file($p) ? json_decode((string) file_get_contents($p), true) : null;
        if (!is_array($d)) {
            error_log('[quest] registry ' . $name . ' is missing or not JSON');
            $d = [];
        }
        return self::$cache[$name] = $d;
    }

    /** Drop the cache. For tests, and after the vendored copy is refreshed. */
    public static function forget(): void { self::$cache = []; }

    /** @return array<string,array> the seven, keyed by id, in order */
    public static function values(): array
    {
        $out = [];
        foreach (self::load('values')['values'] ?? [] as $v) $out[(string) $v['id']] = $v;
        return $out;
    }

    /** @return array<string,array> the seventy, keyed by id, in order */
    public static function challenges(): array
    {
        $out = [];
        foreach (self::load('challenges')['challenges'] ?? [] as $c) $out[(string) $c['id']] = $c;
        return $out;
    }

    public static function challenge(string $id): ?array
    {
        return self::challenges()[$id] ?? null;
    }

    /**
     * Has this challenge been written yet?
     *
     * The seventy slots exist with stable ids and are deliberately empty until
     * the owner supplies the wording. A slot that says nothing cannot be signed
     * off against, because a member would be scored on something nobody wrote.
     */
    public static function isOfficial(string $id): bool
    {
        $c = self::challenge($id);
        return $c !== null && self::written($c);
    }

    private static function written(array $c): bool
    {
        return ($c['status'] ?? '') === 'official' && trim((string) ($c['text'] ?? '')) !== '';
    }

    /** The value a challenge belongs to, or '' if there is no such challenge. */
    public static function valueOf(string $id): string
    {
        return (string) (self::challenge($id)['value'] ?? '');
    }

    /** The challenges under one value, in order. @return array<string,array> */
    public static function challengesOf(string $valueId): array
    {
        return array_filter(self::challenges(), static fn($c) => (string) $c['value'] === $valueId);
    }

    /**
     * How many of the seventy this member has signed off, and out of how many.
     * One query: only ids that are still challenges count, so a grant under a
     * slot the registry no longer has does not inflate the number.
     */
    public static function progress(int $memberId): array
    {
        $all = self::challenges();
        $done = class_exists('QuestPoints') ? QuestPoints::challengesDone($memberId) : [];
        return ['done' => count(array_intersect_key($all, array_flip($done))), 'total' => count($all)];
    }

    /**
     * The same, per value, for a member's page: the seven in order, each with
     * its done and total, and whether any of its slots has been written yet.
     * @return array<string,array{name:string,done:int,total:int,written:int}>
     */
    public static function progressByValue(int $memberId): array
    {
        $done = array_flip(class_exists('QuestPoints') ? QuestPoints::challengesDone($memberId) : []);
        $out = [];
        foreach (self::values() as $id => $v) {
            $out[$id] = ['name' => (string) ($v['name'] ?? $id), 'done' => 0, 'total' => 0, 'written' => 0];
        }
        foreach (self::challenges() as $id => $c) {
            $v = (string) ($c['value'] ?? '');
            if (!isset($out[$v])) continue;
            $out[$v]['total']++;
            if (isset($done[$id])) $out[$v]['done']++;
            if (self::written($c)) $out[$v]['written']++;
        }
        return $out;
    }

    /** @return array<int,array> the ladder, lowest first */
    public static function ranks(): array { return self::load('ranks')['ranks'] ?? []; }

    /** @return array<int,array> the journey, Visitor (0) first */
    public static function journey(): array { return self::load('journey')['levels'] ?? []; }

    /** @return array<string,array> awardable actions, keyed by id */
    public static function awards(): array
    {
        $out = [];
        foreach (self::load('awardable-actions')['actions'] ?? [] as $a) $out[(string) $a['id']] = $a;
        return $out;
    }

    /** @return array<string,array> deductions, keyed by id — points taken away through the desk */
    public static function deductions(): array
    {
        $out = [];
        foreach (self::load('deductions')['deductions'] ?? [] as $d) $out[(string) $d['id']] = $d;
        return $out;
    }

    /** @return array<string,string> group id => label, in order, for 'awardable-actions' or 'fines' */
    public static function groups(string $registry): array
    {
        $out = [];
        foreach (self::load($registry)['groups'] ?? [] as $g) $out[(string) $g['id']] = (string) $g['label'];
        return $out;
    }

    /** One rank by its letter, or null. */
    public static function rank(string $id): ?array
    {
        foreach (self::ranks() as $r) if ((string) $r['id'] === $id) return $r;
        return null;
    }

    /**
     * The highest rank whose point floor AND mentee floor are both met.
     * Arithmetic only: what a member QUALIFIES for, never what they hold —
     * holding a rank is a decision (Levels::set), recorded by a person.
     */
    public static function rankFor(int $points, int $activeMentees): string
    {
        $best = '';
        foreach (self::ranks() as $r) {
            if ($points < (int) $r['points']['min']) break;
            /* The quota binds from Becoming upward (binding_mentees); below
               that a mentee floor is something to aim for, not a gate. */
            if (!empty($r['binding_mentees']) && $activeMentees < (int) $r['mentees']['min']) break;
            $best = (string) $r['id'];
        }
        return $best !== '' ? $best : (string) (self::ranks()[0]['id'] ?? 'O');
    }

    /** @return array<string,array> the fines registry, keyed by id */
    public static function fines(): array
    {
        $out = [];
        foreach (self::load('fines')['fines'] ?? [] as $f) $out[(string) $f['id']] = $f;
        return $out;
    }
}
