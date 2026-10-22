<?php
/**
 * lib/QuestRecord.php — everything true of a member in the Vanguard Quest,
 * except their rank.
 *
 * The rank is left out on purpose. Which ladder a member climbs is an open
 * question with the owner: lib/Levels.php runs O–G on mentorship, tenure,
 * attendance and commitment and uses no points at all, while the Quest's
 * pyramid runs the same eight letters on points and active direct mentees.
 * Reconciling them is not this class's business. Assembling what EITHER
 * engine needs is.
 *
 * So: points by evidence, progress against the seventy, how many people this
 * member is actually mentoring, where they stand on the journey, and whether
 * anything is open against them.
 *
 * ── TWO SENSES OF "MENTEE", AND THE QUEST USES THE STRICTER ──────────────
 *   Mentorship already distinguishes them, and the distinction is the whole
 *   reason the quota is worth anything:
 *     PAIRED — a row with status 'active'. Says nothing about whether the two
 *              have ever met.
 *     ACTIVE — a pairing that is actually being mentored.
 *   A quota counted on PAIRED is gamed by opening pairings. The Quest counts
 *   ACTIVE, and `vquest` only: an Academy mentorship is valuable and is not
 *   the relationship the pyramid's quota is about.
 */
declare(strict_types=1);

final class QuestRecord
{
    /** Everybody is a part of the Quest, and everybody starts here. */
    public const JOURNEY_DEFAULT = 'visitor';

    /** What may be recorded against somebody. The owner has not settled this list. */
    public const FINDING_KINDS = [
        'dishonesty'   => 'Dishonesty',
        'commitments'  => 'Repeated failure to honour commitments',
        'resources'    => 'Deliberate misuse of resources',
        'other'        => 'Other (say what)',
    ];

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            $db = Database::pdo();
            Database::execSchema($db, "CREATE TABLE IF NOT EXISTS quest_journey (
                member_id INTEGER PRIMARY KEY,
                level     VARCHAR(20) NOT NULL DEFAULT '',
                note      VARCHAR(200) NOT NULL DEFAULT '',
                set_by    INTEGER NOT NULL DEFAULT 0,
                set_at    VARCHAR(32) NOT NULL DEFAULT ''
            )");
            /* An integrity finding is not a fine. A fine is money for lateness;
               this is a statement about somebody's character, and it is the one
               thing no quantity of points may buy past. */
            Database::execSchema($db, "CREATE TABLE IF NOT EXISTS quest_findings (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                member_id    INTEGER NOT NULL,
                kind         VARCHAR(24) NOT NULL,
                detail       TEXT NOT NULL,
                status       VARCHAR(12) NOT NULL DEFAULT 'open',
                raised_by    INTEGER NOT NULL DEFAULT 0,
                raised_at    VARCHAR(32) NOT NULL DEFAULT '',
                resolved_by  INTEGER NOT NULL DEFAULT 0,
                resolved_at  VARCHAR(32) NOT NULL DEFAULT '',
                resolve_note TEXT
            )");
            Database::ensureIndex($db, 'idx_quest_find_member', 'quest_findings', 'member_id, status');
        } catch (Throwable $e) {
            error_log('[questrecord] ensure: ' . $e->getMessage());
        }
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    /* ── Mentees ──────────────────────────────────────────────────────── */

    /** @return int[] mentee ids in a live `vquest` pairing with this mentor */
    public static function vquestPairedIds(int $mentorId): array
    {
        if ($mentorId <= 0) return [];
        try {
            $st = Database::pdo()->prepare("SELECT DISTINCT mentee_id FROM mentorships
                                            WHERE mentor_id = ? AND status = 'active' AND kind = 'vquest'");
            $st->execute([$mentorId]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) { return []; }
    }

    /** Pairings that exist. Not the quota — see the header. */
    public static function pairedMentees(int $mentorId): int
    {
        return count(self::vquestPairedIds($mentorId));
    }

    /**
     * The quota's number: `vquest` pairings that are actually being mentored.
     * Mentorship owns what "actually" means; this only narrows it to the Quest.
     */
    public static function activeMentees(int $mentorId, ?int $withinDays = null): int
    {
        return count(self::activeMenteeIds($mentorId, $withinDays));
    }

    /** @return int[] */
    public static function activeMenteeIds(int $mentorId, ?int $withinDays = null): array
    {
        $paired = self::vquestPairedIds($mentorId);
        if (!$paired || !class_exists('Mentorship')) return [];
        try {
            $live = array_map('intval', Mentorship::activeMenteeIds($mentorId, $withinDays));
        } catch (Throwable $e) { return []; }
        return array_values(array_intersect($paired, $live));
    }

    /* ── The journey ──────────────────────────────────────────────────── */

    /** Where this member stands. Everybody is a Visitor until somebody says otherwise. */
    public static function journey(int $memberId): string
    {
        self::ensure();
        if ($memberId <= 0) return self::JOURNEY_DEFAULT;
        try {
            $st = Database::pdo()->prepare('SELECT level FROM quest_journey WHERE member_id = ?');
            $st->execute([$memberId]);
            $v = (string) ($st->fetchColumn() ?: '');
        } catch (Throwable $e) { $v = ''; }
        return self::isJourneyLevel($v) ? $v : self::JOURNEY_DEFAULT;
    }

    public static function isJourneyLevel(string $id): bool
    {
        foreach (Quest::journey() as $l) if ((string) $l['id'] === $id) return true;
        return false;
    }

    /**
     * A person moves a member along the journey. Never arithmetic: the journey
     * is a reading of how close somebody stands to the culture, and no counter
     * expresses that.
     */
    public static function setJourney(int $memberId, string $level, array $by, string $note = ''): array
    {
        self::ensure();
        if ($memberId <= 0) return ['ok' => false, 'error' => 'No such member.'];
        if (!self::isJourneyLevel($level)) return ['ok' => false, 'error' => 'That is not one of the journey levels.'];
        if (!class_exists('Conduct') || !Conduct::canDecide($by)) {
            return ['ok' => false, 'error' => 'Only the compliance committee or a superadmin moves somebody along the journey.'];
        }
        try {
            $db = Database::pdo();
            $db->prepare(Database::insertIgnore('quest_journey', ['member_id', 'level', 'note', 'set_by', 'set_at']))
               ->execute([$memberId, $level, mb_substr($note, 0, 200), (int) $by['id'], self::now()]);
            $db->prepare('UPDATE quest_journey SET level = ?, note = ?, set_by = ?, set_at = ? WHERE member_id = ?')
               ->execute([$level, mb_substr($note, 0, 200), (int) $by['id'], self::now(), $memberId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'That could not be saved.'];
        }
        self::audit('journey', $memberId, $level . ($note !== '' ? ' — ' . $note : ''), (int) $by['id']);
        return ['ok' => true, 'level' => $level];
    }

    /* ── Integrity ────────────────────────────────────────────────────── */

    /**
     * Record something against a member. Compliance only, and never a mentor
     * alone: this is the one record that cannot be outweighed.
     */
    public static function raiseFinding(int $memberId, string $kind, string $detail, array $by): array
    {
        self::ensure();
        if ($memberId <= 0) return ['ok' => false, 'error' => 'No such member.'];
        if (!isset(self::FINDING_KINDS[$kind])) return ['ok' => false, 'error' => 'Say what kind of finding it is.'];
        $detail = trim($detail);
        if (mb_strlen($detail) < 30) return ['ok' => false, 'error' => 'Write what happened — what was done, when, and how it is known.'];
        if (!class_exists('Conduct') || !Conduct::canDecide($by)) {
            return ['ok' => false, 'error' => 'Only the compliance committee or a superadmin records a finding.'];
        }
        if ((int) $by['id'] === $memberId) return ['ok' => false, 'error' => 'This is about you, so somebody else must record it.'];
        try {
            $db = Database::pdo();
            $db->prepare('INSERT INTO quest_findings (member_id, kind, detail, status, raised_by, raised_at)
                          VALUES (?,?,?,?,?,?)')
               ->execute([$memberId, $kind, mb_substr($detail, 0, 3000), 'open', (int) $by['id'], self::now()]);
            $id = (int) $db->lastInsertId();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'That could not be saved.'];
        }
        self::audit('finding_raise', $memberId, $kind, (int) $by['id']);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Resolving is reachable on purpose. A system nobody can recover from is
     * not incorruptible, it is unforgiving, and it teaches people to hide
     * things. The finding stays on the record; it stops standing.
     */
    public static function resolveFinding(int $findingId, string $note, array $by): array
    {
        self::ensure();
        if (!class_exists('Conduct') || !Conduct::canDecide($by)) {
            return ['ok' => false, 'error' => 'Only the compliance committee or a superadmin resolves a finding.'];
        }
        $note = trim($note);
        if ($note === '') return ['ok' => false, 'error' => 'Say what changed — the finding stays on the record either way.'];
        try {
            $st = Database::pdo()->prepare("UPDATE quest_findings SET status = 'resolved', resolved_by = ?, resolved_at = ?, resolve_note = ?
                                            WHERE id = ? AND status = 'open'");
            $st->execute([(int) $by['id'], self::now(), mb_substr($note, 0, 3000), $findingId]);
            if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'That finding is not open.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'That could not be saved.'];
        }
        self::audit('finding_resolve', $findingId, $note, (int) $by['id']);
        return ['ok' => true];
    }

    /** @return array<int,array> open first, newest first */
    public static function findings(int $memberId, bool $openOnly = false): array
    {
        self::ensure();
        if ($memberId <= 0) return [];
        try {
            $sql = 'SELECT * FROM quest_findings WHERE member_id = ?' . ($openOnly ? " AND status = 'open'" : '')
                 . " ORDER BY CASE status WHEN 'open' THEN 0 ELSE 1 END, id DESC";
            $st = Database::pdo()->prepare($sql);
            $st->execute([$memberId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
    }

    /**
     * The gate. No quantity of points, mentees or awards clears it; only the
     * finding being resolved clears it.
     *
     * A gate and not a deduction, deliberately: a fifty-point penalty for
     * dishonesty says dishonesty costs fifty points, and sets the price at
     * which it is worth paying.
     */
    public static function holdsIntegrity(int $memberId): bool
    {
        return self::findings($memberId, true) === [];
    }

    /* ── The whole record ─────────────────────────────────────────────── */

    /**
     * What a rank engine needs, and what a member's page shows. No rank in it.
     */
    public static function of(int $memberId): array
    {
        $open = self::findings($memberId, true);
        return [
            'member_id'       => $memberId,
            'points'          => QuestPoints::total($memberId),
            'by_value'        => QuestPoints::byValue($memberId),
            'challenges'      => Quest::progress($memberId),
            'mentees_active'  => self::activeMentees($memberId),
            'mentees_paired'  => self::pairedMentees($memberId),
            'journey'         => self::journey($memberId),
            'integrity'       => $open === [],
            'open_findings'   => count($open),
        ];
    }

    private static function audit(string $action, int $subject, string $detail, int $byUid): void
    {
        if (!class_exists('AdminAudit')) return;
        try { AdminAudit::log('quest', $action, $subject ? 'member#' . $subject : '', mb_substr($detail, 0, 300), null, 'user#' . $byUid); }
        catch (Throwable $e) { error_log('[questrecord] audit: ' . $e->getMessage()); }
    }
}
