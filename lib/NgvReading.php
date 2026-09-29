<?php
/**
 * lib/NgvReading.php — the 24-book challenge, as a claim that gets verified.
 *
 * WHAT THIS REPLACES. `ngv_participants.books` was twenty-four characters of
 * '0' and '1' that the participant toggled themselves. Tapping a box was the
 * whole of it: no record of which book, nothing written, nobody asked, and a
 * count on the dashboard that meant only "this person tapped twenty-four
 * times". The bitstring is still written for anything that reads it, but it is
 * now DERIVED from verified claims rather than being the thing anybody edits.
 *
 * WHAT CANNOT BE PROMISED. No software can establish that a human read a book.
 * Someone can read a summary, have a friend write the reflection, or ask a
 * model. Anybody claiming a reading tracker is "100% fraud-free" is selling
 * something. What is achievable — and what this does — is:
 *
 *   1. every claim carries EVIDENCE a reader can weigh,
 *   2. a HUMAN signs it off, and the count only counts what they signed,
 *   3. the CHEAP fakes are caught automatically and put in front of that human,
 *   4. the whole thing leaves an AUDIT TRAIL nobody can quietly edit.
 *
 * That moves the cost of faking above the cost of reading a short book, which
 * is the actual goal. It does not make it impossible.
 *
 * THE THREAT MODEL, and what answers each:
 *
 *   The lazy tap — twenty-four boxes in one sitting.
 *       → A claim needs a title, dates and writing. Pacing flags a burst.
 *   The thin claim — "good book, learned a lot".
 *       → A substance floor, and a reviewer who sees it.
 *   The copier — a friend's reflection, pasted.
 *       → Reflections are fingerprinted; a match against ANY other
 *         participant's claim is flagged before a reviewer sees it.
 *   The recycler — their own words again on the next book.
 *       → Same fingerprint check, against their own history.
 *   The backdater — finished before they started, or read it in an hour.
 *       → Dates are checked against each other and against enrolment.
 *   The summariser — read a blurb, wrote something plausible.
 *       → ONLY a human catches this, by asking about the specific takeaway.
 *         It is the irreducible case and the reviewer is told so.
 *
 * CLIENT SIGNALS ARE TRIAGE, NEVER PROOF. `typed_ms` and `paste_count` come
 * from the browser and anybody who can open developer tools can send whatever
 * they like. They order the review queue; they never decide an outcome, and
 * the console says so where a reviewer can read it.
 */
declare(strict_types=1);

final class NgvReading
{
    public const TOTAL = 24;

    /** draft → submitted → verified | rejected | resubmit (back to the member). */
    public const STATUSES = ['draft', 'submitted', 'verified', 'rejected', 'resubmit'];

    /** Below this a reflection is not evidence, it is a gesture. Roughly a
     *  solid paragraph — enough that writing it about a book you did not read
     *  costs more than skimming the book would have. */
    public const MIN_REFLECTION = 320;

    /** And a ceiling, so nobody pastes a chapter to clear the floor. */
    private const MAX_REFLECTION = 6000;

    /** The specific thing they did with it. Short, but it must exist — this is
     *  the field a reviewer can ask about out loud. */
    public const MIN_TAKEAWAY = 60;

    /** Nobody finishes more than this many books in seven days honestly. It is
     *  a FLAG, not a block: a genuine fast reader on holiday exists, and the
     *  reviewer should see the claim and decide. */
    private const PACE_PER_WEEK = 4;

    private static bool $ready = false;

    /* ── schema ──────────────────────────────────────────────────────────── */

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            $pdo = NgvDb::pdo();
            Database::execSchema($pdo, self::ddl());
            /* Columns too, not just tables: CREATE TABLE IF NOT EXISTS is a
               no-op on a database that already has the table, so anything added
               to the DDL later would never arrive. */
            Database::syncTablesFromDdl($pdo, self::ddl(), null, 'ngvreading');
        } catch (Throwable $e) { error_log('[ngvreading] schema: ' . $e->getMessage()); }
    }

    /** No semicolon inside any comment here — execSchema splits on ';'. */
    private static function ddl(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS ngv_book_claims (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  member_id    INTEGER NOT NULL,
  slot         INTEGER NOT NULL DEFAULT 0,
  title        TEXT NOT NULL DEFAULT '',
  author       TEXT NOT NULL DEFAULT '',
  started_on   TEXT NOT NULL DEFAULT '',
  finished_on  TEXT NOT NULL DEFAULT '',
  reflection   TEXT NOT NULL DEFAULT '',
  takeaway     TEXT NOT NULL DEFAULT '',
  status       VARCHAR(12) NOT NULL DEFAULT 'draft',
  fingerprint  VARCHAR(64) NOT NULL DEFAULT '',
  flags        TEXT NOT NULL DEFAULT '',
  typed_ms     INTEGER NOT NULL DEFAULT 0,
  paste_count  INTEGER NOT NULL DEFAULT 0,
  reviewed_by  INTEGER NOT NULL DEFAULT 0,
  reviewed_at  TEXT NOT NULL DEFAULT '',
  review_note  TEXT NOT NULL DEFAULT '',
  submitted_at TEXT NOT NULL DEFAULT '',
  created_at   TEXT NOT NULL DEFAULT '',
  updated_at   TEXT NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_claim_slot ON ngv_book_claims (member_id, slot);
CREATE INDEX IF NOT EXISTS idx_claim_status ON ngv_book_claims (status, id);
CREATE INDEX IF NOT EXISTS idx_claim_print ON ngv_book_claims (fingerprint);
SQL;
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    private static function now(): string { return function_exists('av_now_tz') ? av_now_tz('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s'); }
    private static function today(): string { return function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d'); }

    private static function date(string $s): string
    {
        $s = trim($s);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : '';
    }

    /**
     * A fingerprint of what was written, for catching the same words twice.
     *
     * Normalised hard on purpose: lowercased, punctuation dropped, whitespace
     * collapsed. Somebody who copies a friend's paragraph and changes the
     * capitals and a comma has still copied it, and the check should say so.
     * It is not a plagiarism detector — it catches the copy-paste, which is
     * what actually happens in a cohort.
     */
    public static function fingerprint(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $t = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = trim((string) $t);
        if (mb_strlen($t) < 40) return '';          // too short to be evidence of anything
        return hash('sha256', $t);
    }

    /** How many characters of actual writing, ignoring padding whitespace. */
    private static function substance(string $s): int
    {
        return mb_strlen(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }

    /* ── making a claim ──────────────────────────────────────────────────── */

    /**
     * Save or submit a claim for one of the twenty-four slots.
     *
     * A draft can be edited freely. Once SUBMITTED it is out of the member's
     * hands: they cannot edit it, delete it or re-slot it, because a claim a
     * reviewer has seen must not change underneath them. The one way back is a
     * reviewer returning it with `resubmit`.
     */
    public static function save(int $memberId, array $in, bool $submit = false): array
    {
        self::ensure();
        if ($memberId <= 0) return ['ok' => false, 'error' => 'Sign in first.'];
        $slot = (int) ($in['slot'] ?? 0);
        if ($slot < 1 || $slot > self::TOTAL) return ['ok' => false, 'error' => 'That is not one of the twenty-four.'];

        $have = self::claim($memberId, $slot);
        if ($have && in_array((string) $have['status'], ['submitted', 'verified'], true)) {
            return ['ok' => false, 'error' => (string) $have['status'] === 'verified'
                ? 'This one is already verified. It cannot be changed.'
                : 'This one is with your track lead. You will be able to edit it if they send it back.'];
        }

        $title    = mb_substr(trim((string) ($in['title'] ?? '')), 0, 200);
        $author   = mb_substr(trim((string) ($in['author'] ?? '')), 0, 120);
        $started  = self::date((string) ($in['started_on'] ?? ''));
        $finished = self::date((string) ($in['finished_on'] ?? ''));
        $refl     = mb_substr(trim((string) ($in['reflection'] ?? '')), 0, self::MAX_REFLECTION);
        $take     = mb_substr(trim((string) ($in['takeaway'] ?? '')), 0, 600);

        if ($submit) {
            $why = self::whyNotReady($title, $author, $started, $finished, $refl, $take);
            if ($why !== '') return ['ok' => false, 'error' => $why];
        }

        $row = [
            'member_id'   => $memberId,
            'slot'        => $slot,
            'title'       => $title,
            'author'      => $author,
            'started_on'  => $started,
            'finished_on' => $finished,
            'reflection'  => $refl,
            'takeaway'    => $take,
            'status'      => $submit ? 'submitted' : 'draft',
            'fingerprint' => self::fingerprint($refl),
            /* Clamped. These come from a browser, so a hostile value is a
               value somebody chose — a bounded one cannot poison a sort. */
            'typed_ms'    => max(0, min(86400000, (int) ($in['typed_ms'] ?? 0))),
            'paste_count' => max(0, min(999, (int) ($in['paste_count'] ?? 0))),
            'updated_at'  => self::now(),
        ];
        if ($submit) $row['submitted_at'] = self::now();

        try {
            $pdo = NgvDb::pdo();
            if ($have) {
                $set = implode(', ', array_map(static fn($c) => $c . ' = ?', array_keys($row)));
                $args = array_values($row); $args[] = (int) $have['id'];
                $pdo->prepare('UPDATE ngv_book_claims SET ' . $set . ' WHERE id = ?')->execute($args);
                $id = (int) $have['id'];
            } else {
                $row['created_at'] = self::now();
                $names = implode(',', array_keys($row));
                $marks = implode(',', array_fill(0, count($row), '?'));
                $pdo->prepare("INSERT INTO ngv_book_claims ($names) VALUES ($marks)")->execute(array_values($row));
                $id = (int) $pdo->lastInsertId();
            }
            /* Flags are computed AFTER the row exists, so the duplicate check
               can see the whole cohort including this claim's own history. */
            if ($submit) self::flag($id);
            return ['ok' => true, 'id' => $id, 'status' => $row['status'],
                    'flags' => $submit ? self::claimById($id)['flags_list'] : []];
        } catch (Throwable $e) {
            error_log('[ngvreading] save: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save that.'];
        }
    }

    /** Why this claim is not ready to submit — '' when it is. */
    public static function whyNotReady(string $title, string $author, string $started, string $finished, string $refl, string $take): string
    {
        if ($title === '')  return 'Which book? Put the title in.';
        if ($author === '') return 'Who wrote it?';
        if ($started === '' || $finished === '') return 'When did you start it, and when did you finish?';
        if ($finished < $started) return 'You have finished it before you started it — check those dates.';
        if ($finished > self::today()) return 'You cannot finish a book in the future.';
        $r = self::substance($refl);
        if ($r < self::MIN_REFLECTION) {
            return 'Your reflection is ' . $r . ' characters. We need at least ' . self::MIN_REFLECTION
                 . ' — what the book argued, and whether you agreed.';
        }
        $t = self::substance($take);
        if ($t < self::MIN_TAKEAWAY) {
            return 'Add one specific thing you have done or will do because of this book — at least '
                 . self::MIN_TAKEAWAY . ' characters. Your track lead may ask you about it.';
        }
        return '';
    }

    /* ── the automatic checks ────────────────────────────────────────────── */

    /**
     * Work out what is suspicious about a submitted claim and record it.
     *
     * Every one of these is a REASON TO LOOK, never a verdict. The reviewer
     * sees the flags and the writing together and decides. A flagged claim from
     * somebody who genuinely reads fast is approved, and the flag stays on the
     * record as part of what was considered.
     */
    public static function flag(int $claimId): array
    {
        self::ensure();
        $c = self::claimById($claimId);
        if (!$c) return [];
        $flags = [];

        /* 1. The same words as somebody else's claim, or an earlier one of
              their own. This is the copy-paste that actually happens. */
        if ((string) $c['fingerprint'] !== '') {
            try {
                $st = NgvDb::pdo()->prepare(
                    'SELECT member_id, slot FROM ngv_book_claims
                      WHERE fingerprint = ? AND id <> ? AND status <> ? LIMIT 1');
                $st->execute([(string) $c['fingerprint'], $claimId, 'draft']);
                $dup = $st->fetch(PDO::FETCH_ASSOC);
                if ($dup) {
                    $flags[] = ((int) $dup['member_id'] === (int) $c['member_id'])
                        ? 'same-words-as-own-book-' . (int) $dup['slot']
                        : 'same-words-as-another-participant';
                }
            } catch (Throwable $e) { error_log('[ngvreading] dupe: ' . $e->getMessage()); }
        }

        /* 2. Read in a day, or finished before they joined the programme. */
        $s = (string) $c['started_on']; $f = (string) $c['finished_on'];
        if ($s !== '' && $f !== '') {
            $days = (int) floor((strtotime($f) - strtotime($s)) / 86400);
            if ($days <= 0) $flags[] = 'started-and-finished-same-day';
        }
        try {
            $p = NgvMember::participant((int) $c['member_id']);
            $start = (string) ($p['start_date'] ?? '');
            if ($start !== '' && $f !== '' && $f < $start) $flags[] = 'finished-before-they-enrolled';
        } catch (Throwable $e) { }

        /* 3. Pace. Four in a week is a flag, not a bar — a genuine fast reader
              on holiday exists and the reviewer should see the claim. */
        if ($f !== '') {
            try {
                $st = NgvDb::pdo()->prepare(
                    "SELECT COUNT(*) FROM ngv_book_claims
                      WHERE member_id = ? AND id <> ? AND status IN ('submitted','verified')
                        AND finished_on <> '' AND finished_on >= ? AND finished_on <= ?");
                $st->execute([(int) $c['member_id'], $claimId,
                              gmdate('Y-m-d', strtotime($f . ' -7 days')), $f]);
                if (((int) $st->fetchColumn()) >= self::PACE_PER_WEEK) $flags[] = 'more-than-four-in-a-week';
            } catch (Throwable $e) { }
        }

        /* 4. Browser signals. TRIAGE ONLY — trivially spoofable by anybody who
              opens developer tools, so they order the queue and nothing else. */
        if ((int) $c['paste_count'] > 0 && (int) $c['typed_ms'] < 20000) $flags[] = 'pasted-rather-than-typed';
        if ((int) $c['typed_ms'] > 0 && (int) $c['typed_ms'] < 45000
            && self::substance((string) $c['reflection']) > 800) $flags[] = 'written-implausibly-fast';

        $csv = implode(',', array_unique($flags));
        try {
            NgvDb::pdo()->prepare('UPDATE ngv_book_claims SET flags = ? WHERE id = ?')->execute([$csv, $claimId]);
        } catch (Throwable $e) { error_log('[ngvreading] flag write: ' . $e->getMessage()); }
        return $flags;
    }

    /** Flags in words a reviewer can act on. */
    public static function flagLabel(string $flag): string
    {
        $map = [
            'same-words-as-another-participant' => 'The same reflection as another participant — check both.',
            'started-and-finished-same-day'     => 'Started and finished on the same day.',
            'finished-before-they-enrolled'     => 'Finished before they joined the programme.',
            'more-than-four-in-a-week'          => 'More than four books finished in one week.',
            'pasted-rather-than-typed'          => 'Pasted rather than typed (browser signal — not proof).',
            'written-implausibly-fast'          => 'A long reflection typed very fast (browser signal — not proof).',
        ];
        if (isset($map[$flag])) return $map[$flag];
        if (str_starts_with($flag, 'same-words-as-own-book-')) {
            return 'The same reflection they used for book ' . (int) substr($flag, 23) . '.';
        }
        return $flag;
    }

    /* ── the human decision ──────────────────────────────────────────────── */

    /**
     * A reviewer's verdict. This is the ONLY thing that makes a book count.
     *
     * `verified` is final and deliberately one-way from the member's side: they
     * cannot edit a verified claim afterwards, and neither can they un-verify
     * it. A reviewer can still correct their own mistake, and the correction is
     * recorded rather than replacing what was there.
     *
     * A note is required for anything that is not an approval. "Rejected" with
     * no reason is how a programme loses a participant who was telling the
     * truth and never found out what was wrong.
     */
    public static function review(int $claimId, string $verdict, int $byUid, string $note = ''): array
    {
        self::ensure();
        if (!in_array($verdict, ['verified', 'rejected', 'resubmit'], true)) {
            return ['ok' => false, 'error' => 'Unknown verdict.'];
        }
        $c = self::claimById($claimId);
        if (!$c) return ['ok' => false, 'error' => 'No such claim.'];
        if ((string) $c['status'] === 'draft') {
            return ['ok' => false, 'error' => 'That one has not been submitted yet.'];
        }
        $note = mb_substr(trim($note), 0, 1000);
        if ($verdict !== 'verified' && $note === '') {
            return ['ok' => false, 'error' => $verdict === 'rejected'
                ? 'Say why it was rejected — they cannot put right what they are not told.'
                : 'Say what needs changing before they resubmit.'];
        }

        try {
            NgvDb::pdo()->prepare(
                'UPDATE ngv_book_claims SET status = ?, reviewed_by = ?, reviewed_at = ?, review_note = ?, updated_at = ? WHERE id = ?')
                ->execute([$verdict, max(0, $byUid), self::now(), $note, self::now(), $claimId]);
        } catch (Throwable $e) {
            error_log('[ngvreading] review: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not record that.'];
        }

        self::syncBitstring((int) $c['member_id']);
        self::audit('ngv_book_' . $verdict, (int) $c['member_id'],
            'Book ' . (int) $c['slot'] . ' — ' . mb_substr((string) $c['title'], 0, 80)
            . ($note !== '' ? ' — ' . mb_substr($note, 0, 120) : ''));
        self::notify($claimId, $verdict);
        return ['ok' => true, 'status' => $verdict, 'verified' => self::verifiedCount((int) $c['member_id'])];
    }

    /**
     * Keep `ngv_participants.books` in step with what has been VERIFIED.
     *
     * The bitstring stays because other things read it, but it is now a
     * derived view rather than the record — and it is derived from verified
     * claims only, so nothing a member does to their own dashboard can move
     * it. That is the whole point: the count and the evidence cannot disagree.
     */
    public static function syncBitstring(int $memberId): string
    {
        self::ensure();
        $bits = str_repeat('0', self::TOTAL);
        try {
            $st = NgvDb::pdo()->prepare("SELECT slot FROM ngv_book_claims WHERE member_id = ? AND status = 'verified'");
            $st->execute([$memberId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $slot) {
                $i = (int) $slot - 1;
                if ($i >= 0 && $i < self::TOTAL) $bits[$i] = '1';
            }
            NgvDb::pdo()->prepare('UPDATE ngv_participants SET books = ? WHERE member_id = ?')
                ->execute([$bits, $memberId]);
        } catch (Throwable $e) { error_log('[ngvreading] sync: ' . $e->getMessage()); }
        return $bits;
    }

    /* ── reading it back ─────────────────────────────────────────────────── */

    public static function claim(int $memberId, int $slot): ?array
    {
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_book_claims WHERE member_id = ? AND slot = ?');
            $st->execute([$memberId, $slot]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? self::shape($r) : null;
        } catch (Throwable $e) { return null; }
    }

    public static function claimById(int $id): ?array
    {
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_book_claims WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? self::shape($r) : null;
        } catch (Throwable $e) { return null; }
    }

    /** Every slot 1..24 for one member, claimed or not — what the page renders. */
    public static function shelf(int $memberId): array
    {
        self::ensure();
        $by = [];
        try {
            $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_book_claims WHERE member_id = ? ORDER BY slot');
            $st->execute([$memberId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $by[(int) $r['slot']] = self::shape($r);
        } catch (Throwable $e) { }
        $out = [];
        for ($i = 1; $i <= self::TOTAL; $i++) {
            $out[$i] = $by[$i] ?? ['slot' => $i, 'status' => 'empty', 'title' => '', 'flags_list' => []];
        }
        return $out;
    }

    public static function verifiedCount(int $memberId): int
    {
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare("SELECT COUNT(*) FROM ngv_book_claims WHERE member_id = ? AND status = 'verified'");
            $st->execute([$memberId]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /** What the member's own page says: verified, waiting, and needing work. */
    public static function progress(int $memberId): array
    {
        $v = 0; $w = 0; $back = 0; $draft = 0;
        foreach (self::shelf($memberId) as $c) {
            switch ((string) $c['status']) {
                case 'verified':  $v++;    break;
                case 'submitted': $w++;    break;
                case 'resubmit':
                case 'rejected':  $back++; break;
                case 'draft':     $draft++; break;
            }
        }
        return ['verified' => $v, 'waiting' => $w, 'needs_work' => $back, 'drafts' => $draft, 'total' => self::TOTAL];
    }

    /**
     * The review queue. Flagged claims first — that is the whole point of
     * flagging them — then oldest, so nothing waits forever behind a pile of
     * suspicious ones.
     */
    public static function queue(int $limit = 50): array
    {
        self::ensure();
        $limit = max(1, min(200, $limit));
        try {
            $st = NgvDb::pdo()->prepare(
                "SELECT * FROM ngv_book_claims WHERE status = 'submitted'
                  ORDER BY (CASE WHEN flags <> '' THEN 0 ELSE 1 END), submitted_at ASC, id ASC LIMIT " . $limit);
            $st->execute();
            return array_map([self::class, 'shape'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { error_log('[ngvreading] queue: ' . $e->getMessage()); return []; }
    }

    public static function queueCount(): int
    {
        self::ensure();
        try { return (int) NgvDb::pdo()->query("SELECT COUNT(*) FROM ngv_book_claims WHERE status = 'submitted'")->fetchColumn(); }
        catch (Throwable $e) { return 0; }
    }

    private static function shape(array $r): array
    {
        foreach (['id', 'member_id', 'slot', 'typed_ms', 'paste_count', 'reviewed_by'] as $k) $r[$k] = (int) ($r[$k] ?? 0);
        $r['flags_list'] = array_values(array_filter(explode(',', (string) ($r['flags'] ?? ''))));
        $r['reflection_len'] = self::substance((string) ($r['reflection'] ?? ''));
        return $r;
    }

    /* ── letting people know ─────────────────────────────────────────────── */

    private static function notify(int $claimId, string $verdict): void
    {
        try {
            if (!class_exists('Mailer') || Mailer::disabled()) return;
            $c = self::claimById($claimId);
            if (!$c) return;
            $p = NgvMember::participant((int) $c['member_id']);
            $to = trim((string) ($p['email'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;

            $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $book = $esc((string) $c['title']);
            if ($verdict === 'verified') {
                $rows = ['Your reflection on <strong>' . $book . '</strong> has been checked and counted.',
                         'That is ' . self::verifiedCount((int) $c['member_id']) . ' of ' . self::TOTAL . ' verified.'];
                $subject = 'Book ' . (int) $c['slot'] . ' verified — ' . (string) $c['title'];
            } else {
                $rows = ['Your track lead has looked at your reflection on <strong>' . $book . '</strong>.',
                         '<strong>' . $esc((string) $c['review_note']) . '</strong>'];
                $rows[] = $verdict === 'resubmit'
                    ? 'Open your dashboard, put that right and send it again.'
                    : 'If you think this is wrong, speak to your track lead — it is a conversation, not a verdict on you.';
                $subject = 'Your reflection on ' . (string) $c['title'] . ' needs another look';
            }
            @Mailer::send($to, $subject,
                Mailer::shell('NextGen Vanguard', $rows,
                    ['url' => rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/academy/ngv/dashboard.php#reading',
                     'text' => 'Open my reading list']));
        } catch (Throwable $e) { error_log('[ngvreading] notify: ' . $e->getMessage()); }
    }

    private static function audit(string $action, int $memberId, string $detail): void
    {
        try {
            if (class_exists('AdminAudit')) AdminAudit::log('ngv', $action, 'ngv:member:' . $memberId, $detail);
        } catch (Throwable $e) { }
    }
}
