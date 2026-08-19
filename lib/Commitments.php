<?php
/**
 * lib/Commitments.php — G-1: promises made in a room, tracked until they are kept.
 *
 * THE GAP THIS CLOSES. `Meetings::structure()` and `Mentorship::structureSession()`
 * already ask the model for action items, and already store them — as a JSON blob
 * on the transcript, which nothing ever reads again. So the system records that
 * somebody promised something and then forgets, which is verbatim the failure the
 * concept report's §11 names: accountability that stops at minute-taking.
 *
 * A commitment is that same action item, promoted to a row with an owner, a
 * deadline and a status, so it can be chased. Nothing here invents work: it only
 * gives a shape to what the AI already extracted from what people actually said.
 *
 * FOUR PROPERTIES, each of which is a decision rather than an implementation
 * detail — change any of them and this stops being the thing that was designed:
 *
 * 1. EXTRACTION NEVER ASSIGNS. `commitments.auto_assign_owner` ships OFF. The AI
 *    matches an owner *name* it heard in a transcript; silently assigning work to
 *    the wrong Ada is worse than assigning it to nobody. So a commitment arrives
 *    with `owner_hint` set and `member_id` 0, and a human confirms. That is §23's
 *    division of labour: it proposes, a person decides.
 *
 * 2. RE-EXTRACTION IS IDEMPOTENT. Minutes get re-structured — a bot re-uploads, an
 *    admin re-runs the bench. Commitments key on (source, normalised title), so a
 *    second pass updates and never duplicates. A duplicated commitment is worse
 *    than a missed one: it makes the record untrustworthy.
 *
 * 3. A MISS REASON IS CONFIDENTIAL. `commitments.require_miss_reason` implements
 *    the report's §13 step 5 ("what did you fail to accomplish? why?"), which
 *    means this column will collect disclosures about illness, money and family.
 *    It is never returned by an AI tool, never included in a leadership brief, and
 *    `redactedFor()` is the only way it reaches a caller that is not the member.
 *
 * 4. HONESTY IS NOT PUNISHED. A member who says "I did not do this" before being
 *    asked sets `self_reported`, and `completion()` reports it separately rather
 *    than folding it into the failure rate. Otherwise the system teaches people
 *    that silence scores better than candour, which is the opposite of the point.
 *
 * Thresholds live in `AvRules` (Studio → Rules & AI → Commitments), so leadership
 * tunes the deadline, the grace period and the rest without a deploy.
 */
declare(strict_types=1);

final class Commitments
{
    /** Where a commitment came from. */
    public const SRC_MEETING = 'meeting';
    public const SRC_SESSION = 'session';

    /** Lifecycle. `missed` is terminal-with-a-reason; `open` is the only chaseable state. */
    public const STATUSES = ['open', 'done', 'missed', 'cancelled'];

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        $pdo = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS commitments (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            source_kind  VARCHAR(16) NOT NULL DEFAULT 'meeting',
            source_id    INTEGER NOT NULL DEFAULT 0,
            dedupe_key   VARCHAR(191) NOT NULL DEFAULT '',
            title        VARCHAR(300) NOT NULL DEFAULT '',
            member_id    INTEGER NOT NULL DEFAULT 0,
            owner_hint   VARCHAR(120) NOT NULL DEFAULT '',
            due          VARCHAR(32) NOT NULL DEFAULT '',
            status       VARCHAR(16) NOT NULL DEFAULT 'open',
            self_reported INTEGER NOT NULL DEFAULT 0,
            miss_reason  TEXT NOT NULL DEFAULT '',
            confirmed_by INTEGER NOT NULL DEFAULT 0,
            confirmed_at VARCHAR(32) NOT NULL DEFAULT '',
            closed_at    VARCHAR(32) NOT NULL DEFAULT '',
            created_at   VARCHAR(32) NOT NULL DEFAULT '',
            updated_at   VARCHAR(32) NOT NULL DEFAULT ''
        )";
        Database::execSchema($pdo, $ddl);
        // Unique on the dedupe key so a re-run cannot double-file even if two
        // requests race: the database refuses, rather than the code remembering to.
        try { $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_commitments_dedupe ON commitments(dedupe_key)'); }
        catch (Throwable $e) { /* already there */ }
        // The two lookups that run on every portal load and every cron tick.
        foreach (['idx_commitments_member ON commitments(member_id)',
                  'idx_commitments_status ON commitments(status)'] as $ix) {
            try { $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $ix); } catch (Throwable $e) { /* already there */ }
        }
    }

    /* ── rules ─────────────────────────────────────────────────────────── */

    private static function ruleInt(string $key, int $fallback): int
    {
        try { return class_exists('AvRules') ? (int) AvRules::get($key) : $fallback; }
        catch (Throwable $e) { return $fallback; }
    }

    private static function ruleBool(string $key, bool $fallback): bool
    {
        try { return class_exists('AvRules') ? (bool) AvRules::get($key) : $fallback; }
        catch (Throwable $e) { return $fallback; }
    }

    public static function defaultDueDays(): int  { return self::ruleInt('commitments.default_due_days', 7); }
    public static function graceDays(): int       { return self::ruleInt('commitments.overdue_grace_days', 1); }
    public static function requireMissReason(): bool { return self::ruleBool('commitments.require_miss_reason', true); }
    public static function autoAssignOwner(): bool   { return self::ruleBool('commitments.auto_assign_owner', false); }

    /* ── extraction ────────────────────────────────────────────────────── */

    /**
     * A stable identity for "this promise, from this meeting".
     *
     * Normalised hard — case, punctuation and whitespace all collapse — because the
     * model will not reproduce a title byte-for-byte between runs, and a dedupe key
     * that only matches exact strings would duplicate on every re-structure.
     */
    public static function dedupeKey(string $kind, int $sourceId, string $title): string
    {
        $norm = strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', $title));
        $norm = trim((string) preg_replace('/\s+/', ' ', $norm));
        return $kind . ':' . $sourceId . ':' . substr(sha1($norm), 0, 32);
    }

    /**
     * Turn extracted action items into tracked commitments.
     *
     * $items: [['task' => string, 'owner' => string|null, 'due_days' => int|null], …]
     * Returns ['created' => int, 'updated' => int, 'skipped' => int, 'ids' => int[]].
     */
    public static function fileMany(string $kind, int $sourceId, array $items, int $filedBy = 0): array
    {
        self::ensure();
        $kind = $kind === self::SRC_SESSION ? self::SRC_SESSION : self::SRC_MEETING;
        $out  = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'ids' => []];
        if ($sourceId <= 0 || !$items) return $out;

        $pdo  = Database::pdo();
        $now  = gmdate('Y-m-d H:i:s');
        $days = self::defaultDueDays();

        foreach ($items as $it) {
            $title = trim((string) (is_array($it) ? ($it['task'] ?? $it['title'] ?? '') : $it));
            if ($title === '') { $out['skipped']++; continue; }
            $title = mb_substr($title, 0, 300);
            $hint  = mb_substr(trim((string) (is_array($it) ? ($it['owner'] ?? '') : '')), 0, 120);
            $dueIn = is_array($it) && isset($it['due_days']) && $it['due_days'] !== null
                ? max(1, min(365, (int) $it['due_days']))
                : $days;
            $due   = gmdate('Y-m-d', time() + $dueIn * 86400);
            $key   = self::dedupeKey($kind, $sourceId, $title);

            $st = $pdo->prepare('SELECT id, status FROM commitments WHERE dedupe_key = ?');
            $st->execute([$key]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                // Already filed. Refresh only the wording and the hint — never the
                // status, the owner or the deadline, because a human may have acted
                // on this since and a re-structure must not undo their decision.
                $pdo->prepare('UPDATE commitments SET title = ?, owner_hint = CASE WHEN owner_hint = \'\' THEN ? ELSE owner_hint END, updated_at = ? WHERE id = ?')
                    ->execute([$title, $hint, $now, (int) $row['id']]);
                $out['updated']++; $out['ids'][] = (int) $row['id'];
                continue;
            }

            // Owner resolution: only when the rule allows it AND the name matches
            // exactly one member. Anything else waits for a human.
            $memberId = 0;
            if ($hint !== '' && self::autoAssignOwner()) $memberId = self::suggestOwner($kind, $sourceId, $hint);

            try {
                $pdo->prepare(
                    'INSERT INTO commitments (source_kind, source_id, dedupe_key, title, member_id, owner_hint, due, status, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,\'open\',?,?)'
                )->execute([$kind, $sourceId, $key, $title, $memberId, $hint, $due, $now, $now]);
                $id = (int) $pdo->lastInsertId();
                $out['created']++; $out['ids'][] = $id;
                if ($memberId > 0) self::notifyOwner($id, $memberId, $title, $due);
            } catch (Throwable $e) {
                // The unique index is the backstop against a race — a loser here is
                // a duplicate avoided, not an error worth surfacing.
                $out['skipped']++;
                error_log('[commitments] file: ' . $e->getMessage());
            }
        }
        if ($filedBy > 0 && ($out['created'] > 0) && class_exists('Events')) {
            try { Events::emit('commitments.filed', ['kind' => $kind, 'source_id' => $sourceId, 'created' => $out['created']]); }
            catch (Throwable $e) {}
        }
        return $out;
    }

    /**
     * Match an owner name to exactly one member, or 0.
     *
     * Deliberately strict: an exact name match, and only when it is unique. Two
     * members called Ada means nobody is assigned — the chair decides which one.
     */
    public static function resolveOwner(string $name): int
    {
        $name = trim($name);
        if ($name === '') return 0;
        try {
            $st = Database::pdo()->prepare('SELECT id FROM lms_users WHERE LOWER(name) = ? LIMIT 2');
            $st->execute([strtolower($name)]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
            return count($ids) === 1 ? (int) $ids[0] : 0;
        } catch (Throwable $e) { return 0; }
    }

    /**
     * Who this commitment probably belongs to — a SUGGESTION, never an assignment.
     *
     * A mentorship session names its owner by ROLE ('mentor' / 'mentee'), and a
     * session has exactly one of each, so that resolves with certainty rather than
     * by guessing at a name. A meeting names a person, which does not.
     *
     * Both are still only suggestions. It would be defensible to auto-assign the
     * role case — the ambiguity `commitments.auto_assign_owner` exists to guard
     * against genuinely is not present there — but that is leadership's toggle to
     * interpret, not this class's. So the certainty is spent on pre-filling the
     * confirmation instead of on skipping it.
     */
    public static function suggestOwner(string $kind, int $sourceId, string $hint): int
    {
        $hint = strtolower(trim($hint));
        if ($hint === '') return 0;
        if ($kind === self::SRC_SESSION && ($hint === 'mentor' || $hint === 'mentee')) {
            try {
                $st = Database::pdo()->prepare(
                    'SELECT m.mentor_id, m.mentee_id FROM mentor_sessions s
                     JOIN mentorships m ON m.id = s.mentorship_id WHERE s.id = ?'
                );
                $st->execute([$sourceId]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r) return (int) ($hint === 'mentor' ? $r['mentor_id'] : $r['mentee_id']);
            } catch (Throwable $e) { return 0; }
            return 0;
        }
        return self::resolveOwner($hint);
    }

    /* ── the human decisions ───────────────────────────────────────────── */

    /** Confirm (or correct) who owns a commitment. This is the gate extraction cannot pass. */
    public static function assign(int $id, int $memberId, int $byUserId): bool
    {
        self::ensure();
        if ($id <= 0 || $memberId <= 0) return false;
        $now = gmdate('Y-m-d H:i:s');
        $ok = Database::pdo()->prepare(
            'UPDATE commitments SET member_id = ?, confirmed_by = ?, confirmed_at = ?, updated_at = ? WHERE id = ?'
        )->execute([$memberId, $byUserId, $now, $now, $id]);
        if ($ok) {
            $c = self::get($id);
            if ($c) self::notifyOwner($id, $memberId, (string) $c['title'], (string) $c['due']);
        }
        return (bool) $ok;
    }

    /** Mark it kept. */
    public static function complete(int $id, int $byUserId): bool
    {
        self::ensure();
        $now = gmdate('Y-m-d H:i:s');
        return (bool) Database::pdo()->prepare(
            "UPDATE commitments SET status = 'done', closed_at = ?, updated_at = ? WHERE id = ? AND status = 'open'"
        )->execute([$now, $now, $id]);
    }

    /**
     * Mark it missed.
     *
     * $selfReported records that the member said so before anyone asked —
     * `completion()` counts that separately, because a system that scores candour
     * the same as silence teaches people to stay silent.
     */
    public static function miss(int $id, string $reason, bool $selfReported = false): array
    {
        self::ensure();
        $reason = trim($reason);
        if (self::requireMissReason() && $reason === '') {
            return ['ok' => false, 'error' => 'A short reason is required — it is what makes the miss useful rather than just recorded.'];
        }
        $now = gmdate('Y-m-d H:i:s');
        $ok = Database::pdo()->prepare(
            "UPDATE commitments SET status = 'missed', miss_reason = ?, self_reported = ?, closed_at = ?, updated_at = ? WHERE id = ? AND status = 'open'"
        )->execute([mb_substr($reason, 0, 1000), $selfReported ? 1 : 0, $now, $now, $id]);
        return ['ok' => (bool) $ok];
    }

    public static function cancel(int $id): bool
    {
        self::ensure();
        $now = gmdate('Y-m-d H:i:s');
        return (bool) Database::pdo()->prepare(
            "UPDATE commitments SET status = 'cancelled', closed_at = ?, updated_at = ? WHERE id = ? AND status = 'open'"
        )->execute([$now, $now, $id]);
    }

    /* ── reading ───────────────────────────────────────────────────────── */

    public static function get(int $id): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM commitments WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** A member's commitments, newest deadline first. */
    public static function forMember(int $memberId, string $status = 'open', int $limit = 50): array
    {
        self::ensure();
        $sql = 'SELECT * FROM commitments WHERE member_id = ?';
        $args = [$memberId];
        if ($status !== '' && in_array($status, self::STATUSES, true)) { $sql .= ' AND status = ?'; $args[] = $status; }
        $sql .= ' ORDER BY due ASC, id ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($args);
        return array_slice($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 0, max(1, min(200, $limit)));
    }

    /** Everything filed from one meeting or session. */
    public static function forSource(string $kind, int $sourceId): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM commitments WHERE source_kind = ? AND source_id = ? ORDER BY id ASC');
        $st->execute([$kind, $sourceId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Filed but nobody owns it yet — the queue a chair works through.
     *
     * Each row carries `suggested_member_id`, so confirming an owner is a decision
     * about a name already on screen rather than a search. That is the whole point
     * of holding the assignment back: make the human step cheap, not absent.
     */
    public static function unassigned(int $limit = 50): array
    {
        self::ensure();
        $st = Database::pdo()->query("SELECT * FROM commitments WHERE member_id = 0 AND status = 'open' ORDER BY created_at ASC, id ASC");
        $rows = array_slice($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 0, max(1, min(200, $limit)));
        foreach ($rows as &$r) {
            $r['suggested_member_id'] = self::suggestOwner((string) $r['source_kind'], (int) $r['source_id'], (string) $r['owner_hint']);
        }
        return $rows;
    }

    /** Open, owned, and past its deadline plus the grace the rules allow. */
    public static function overdue(int $limit = 200): array
    {
        self::ensure();
        $cut = gmdate('Y-m-d', time() - self::graceDays() * 86400);
        $st = Database::pdo()->prepare(
            "SELECT * FROM commitments WHERE status = 'open' AND member_id > 0 AND due <> '' AND due < ? ORDER BY due ASC"
        );
        $st->execute([$cut]);
        return array_slice($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 0, max(1, min(500, $limit)));
    }

    /**
     * A member's completion picture.
     *
     * `self_reported_misses` is reported next to the rate rather than inside it, so
     * a leader reading this sees behaviour and not a single number — the same
     * reason `ai.character_scores` ships off.
     */
    public static function completion(int $memberId): array
    {
        self::ensure();
        $out = ['total' => 0, 'done' => 0, 'missed' => 0, 'open' => 0, 'overdue' => 0,
                'self_reported_misses' => 0, 'rate' => null];
        try {
            $st = Database::pdo()->prepare('SELECT status, self_reported, due FROM commitments WHERE member_id = ?');
            $st->execute([$memberId]);
            $cut = gmdate('Y-m-d', time() - self::graceDays() * 86400);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out['total']++;
                $s = (string) $r['status'];
                if ($s === 'done')   $out['done']++;
                if ($s === 'missed') { $out['missed']++; if ((int) $r['self_reported'] === 1) $out['self_reported_misses']++; }
                if ($s === 'open') {
                    $out['open']++;
                    if ((string) $r['due'] !== '' && (string) $r['due'] < $cut) $out['overdue']++;
                }
            }
            $settled = $out['done'] + $out['missed'];
            if ($settled > 0) $out['rate'] = (int) round($out['done'] / $settled * 100);
        } catch (Throwable $e) { error_log('[commitments] completion: ' . $e->getMessage()); }
        return $out;
    }

    /**
     * A commitment as a caller other than its owner may see it.
     *
     * The miss reason is removed — not blanked in place but replaced by a flag, so
     * a caller can tell that a reason exists without reading it. Every path that
     * shows a commitment to anyone but the member must go through this.
     */
    public static function redactedFor(array $row): array
    {
        $r = $row;
        $r['has_miss_reason'] = trim((string) ($row['miss_reason'] ?? '')) !== '';
        unset($r['miss_reason']);
        return $r;
    }

    /* ── follow-up ─────────────────────────────────────────────────────── */

    private static function notifyOwner(int $id, int $memberId, string $title, string $due): void
    {
        if (!class_exists('Notifications')) return;
        try {
            Notifications::push($memberId, 'commitment',
                'You have a commitment',
                mb_substr($title, 0, 180) . ($due !== '' ? ' — due ' . $due : ''),
                '/portal/', 'commitment:' . $id);
        } catch (Throwable $e) { error_log('[commitments] notify: ' . $e->getMessage()); }
    }

    /**
     * Chase what is overdue. Called by the cron sweep.
     *
     * One notification per commitment per day, deduped by Notifications' own key,
     * so a commitment that stays overdue for a week nags once a day and not once a
     * tick. Returns the number of nudges sent.
     */
    public static function sweepOverdue(): int
    {
        self::ensure();
        if (!class_exists('Notifications')) return 0;
        $n = 0;
        foreach (self::overdue() as $c) {
            $key = 'commitment_overdue:' . (int) $c['id'] . ':' . gmdate('Y-m-d');
            try {
                $sent = Notifications::push((int) $c['member_id'], 'commitment_overdue',
                    'Overdue commitment',
                    mb_substr((string) $c['title'], 0, 180) . ' — was due ' . (string) $c['due'],
                    '/portal/', $key);
                if ($sent) $n++;
            } catch (Throwable $e) { error_log('[commitments] sweep: ' . $e->getMessage()); }
        }
        return $n;
    }
}
