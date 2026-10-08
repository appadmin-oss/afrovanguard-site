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

    /** A summary for each chapter, as well as the reflection on the whole
     *  book. A few sentences: what the chapter said. Short enough that a long
     *  book is not a punishment, long enough that it cannot be written from
     *  the chapter's title. */
    public const MIN_CHAPTER = 80;
    private const MAX_CHAPTER = 1500;

    /** The most chapters a book on the list may have. */
    public const MAX_CHAPTERS = 80;

    /** Nobody finishes more than this many books in seven days honestly. It is
     *  a FLAG, not a block: a genuine fast reader on holiday exists, and the
     *  reviewer should see the claim and decide. */
    private const PACE_PER_WEEK = 4;

    /**
     * Every sixth verified book, the track lead is asked to raise ONE of them
     * in conversation. Six is a balance: often enough that a participant
     * cannot get through the challenge without being asked several times,
     * rare enough that a volunteer track lead will actually do it.
     *
     * The book is chosen AT RANDOM, server-side, and the participant is never
     * told which one. That is the entire mechanism — not the asking, but the
     * not knowing what will be asked. Somebody who read a summary can prepare
     * a book they know is coming; they cannot prepare six.
     */
    public const SPOT_EVERY = 6;

    /** open → passed | failed. */
    public const SPOT_OUTCOMES = ['open', 'passed', 'failed'];

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
  updated_at   TEXT NOT NULL DEFAULT '',
  book_id      INTEGER NOT NULL DEFAULT 0,
  chapters     INTEGER NOT NULL DEFAULT 0,
  chapter_notes TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS ngv_books (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  title       VARCHAR(200) NOT NULL DEFAULT '',
  author      VARCHAR(120) NOT NULL DEFAULT '',
  chapters    INTEGER NOT NULL DEFAULT 0,
  note        VARCHAR(300) NOT NULL DEFAULT '',
  active      INTEGER NOT NULL DEFAULT 1,
  created_by  INTEGER NOT NULL DEFAULT 0,
  created_at  VARCHAR(32) NOT NULL DEFAULT '',
  updated_at  VARCHAR(32) NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_books_active ON ngv_books (active, title);
CREATE UNIQUE INDEX IF NOT EXISTS idx_claim_slot ON ngv_book_claims (member_id, slot);
CREATE INDEX IF NOT EXISTS idx_claim_status ON ngv_book_claims (status, id);
CREATE INDEX IF NOT EXISTS idx_claim_print ON ngv_book_claims (fingerprint);
CREATE TABLE IF NOT EXISTS ngv_book_spot_checks (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  member_id   INTEGER NOT NULL,
  claim_id    INTEGER NOT NULL DEFAULT 0,
  milestone   INTEGER NOT NULL DEFAULT 0,
  outcome     VARCHAR(12) NOT NULL DEFAULT 'open',
  asked_by    INTEGER NOT NULL DEFAULT 0,
  asked_at    TEXT NOT NULL DEFAULT '',
  note        TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_spot_stone ON ngv_book_spot_checks (member_id, milestone);
CREATE INDEX IF NOT EXISTS idx_spot_open ON ngv_book_spot_checks (outcome, id);
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

    /* ── the book list ─────────────────────────────────────────────────────
     *
     * Admins prepare the books: title, author and how many chapters. A
     * vanguard chooses from the list rather than typing a title, so every
     * claim names a real book on the programme, a reviewer knows what the
     * book is, and a chapter summary can be asked for chapter by chapter.
     * A book is retired, never deleted — claims already made on it keep it.
     */

    /** The books on the list. Active ones only, unless $all. */
    public static function books(bool $all = false): array
    {
        self::ensure();
        try {
            $rows = NgvDb::pdo()->query('SELECT * FROM ngv_books' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY active DESC, title, id')
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[ngvreading] books: ' . $e->getMessage()); return []; }
        return array_map(static function (array $b): array {
            foreach (['id', 'chapters', 'active', 'created_by'] as $k) $b[$k] = (int) ($b[$k] ?? 0);
            return $b;
        }, $rows);
    }

    public static function book(int $id): ?array
    {
        if ($id <= 0) return null;
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_books WHERE id = ?');
            $st->execute([$id]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return null; }
        if (!$b) return null;
        foreach (['id', 'chapters', 'active', 'created_by'] as $k) $b[$k] = (int) ($b[$k] ?? 0);
        return $b;
    }

    /** How many claims name a book — the list shows it, and it decides what an edit may change. */
    public static function bookUse(int $id): int
    {
        try {
            $st = NgvDb::pdo()->prepare('SELECT COUNT(*) FROM ngv_book_claims WHERE book_id = ?');
            $st->execute([$id]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /**
     * Add a book to the list, or correct one ($in['id']). A claim keeps the
     * chapter count it was made with, so correcting the count changes what
     * new claims ask for and leaves a summary somebody has written alone.
     */
    public static function saveBook(array $in, int $by): array
    {
        self::ensure();
        $id       = (int) ($in['id'] ?? 0);
        $title    = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in['title'] ?? '')) ?? ''), 0, 200);
        $author   = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in['author'] ?? '')) ?? ''), 0, 120);
        $chapters = (int) ($in['chapters'] ?? 0);
        $note     = mb_substr(trim((string) ($in['note'] ?? '')), 0, 300);
        if ($title === '') return ['ok' => false, 'error' => 'Give the book its title.'];
        if ($author === '') return ['ok' => false, 'error' => 'Who wrote it?'];
        if ($chapters < 1 || $chapters > self::MAX_CHAPTERS) return ['ok' => false, 'error' => 'How many chapters? Between 1 and ' . self::MAX_CHAPTERS . '.'];
        $pdo = NgvDb::pdo();
        $st = $pdo->prepare('SELECT id FROM ngv_books WHERE LOWER(title) = ? AND LOWER(author) = ? AND id <> ?');
        $st->execute([mb_strtolower($title), mb_strtolower($author), $id]);
        if ($st->fetchColumn()) return ['ok' => false, 'error' => 'That book is already on the list.'];
        try {
            if ($id > 0) {
                if (!self::book($id)) return ['ok' => false, 'error' => 'No such book.'];
                $pdo->prepare('UPDATE ngv_books SET title = ?, author = ?, chapters = ?, note = ?, updated_at = ? WHERE id = ?')
                    ->execute([$title, $author, $chapters, $note, self::now(), $id]);
                self::audit('ngv_book_list_edit', 0, 'Book list: ' . $title . ' — ' . $chapters . ' chapters');
            } else {
                $pdo->prepare('INSERT INTO ngv_books (title, author, chapters, note, active, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?)')
                    ->execute([$title, $author, $chapters, $note, $by, self::now(), self::now()]);
                $id = (int) $pdo->lastInsertId();
                self::audit('ngv_book_list_add', 0, 'Book list: added ' . $title . ' — ' . $chapters . ' chapters');
            }
        } catch (Throwable $e) {
            error_log('[ngvreading] saveBook: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save that book.'];
        }
        return ['ok' => true, 'book' => self::book($id)];
    }

    /** Take a book off the list for new claims, or put it back. */
    public static function setBookActive(int $id, bool $active): array
    {
        $b = self::book($id);
        if (!$b) return ['ok' => false, 'error' => 'No such book.'];
        NgvDb::pdo()->prepare('UPDATE ngv_books SET active = ?, updated_at = ? WHERE id = ?')->execute([$active ? 1 : 0, self::now(), $id]);
        self::audit($active ? 'ngv_book_list_restore' : 'ngv_book_list_retire', 0, 'Book list: ' . ($active ? 'restored ' : 'retired ') . $b['title']);
        return ['ok' => true];
    }

    /** The chapter summaries as sent: one string per chapter, trimmed and cut to length. */
    private static function chapterNotes($raw, int $chapters): array
    {
        $raw = is_array($raw) ? array_values($raw) : [];
        $out = [];
        for ($i = 0; $i < $chapters; $i++) $out[] = mb_substr(trim((string) (is_scalar($raw[$i] ?? null) ? $raw[$i] : '')), 0, self::MAX_CHAPTER);
        return $out;
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

        /* The book comes from the list. A claim made before there was a list
           names its book in its own words, and keeps doing so — nobody is made
           to re-record a book they already wrote about. */
        $legacy = $have && (int) ($have['book_id'] ?? 0) === 0 && trim((string) $have['title']) !== '';
        $bookId = (int) ($in['book_id'] ?? 0);
        $chapters = 0;
        if ($legacy && $bookId === 0) {
            $title  = mb_substr(trim((string) ($in['title'] ?? '')), 0, 200);
            $author = mb_substr(trim((string) ($in['author'] ?? '')), 0, 120);
        } else {
            $book = self::book($bookId);
            $same = $have && (int) ($have['book_id'] ?? 0) === $bookId;
            if (!$book || (!$book['active'] && !$same)) return ['ok' => false, 'error' => 'Choose the book from the list.'];
            $st = NgvDb::pdo()->prepare("SELECT slot FROM ngv_book_claims WHERE member_id = ? AND book_id = ? AND slot <> ? AND status <> 'rejected' LIMIT 1");
            $st->execute([$memberId, $bookId, $slot]);
            $other = $st->fetchColumn();
            if ($other !== false) return ['ok' => false, 'error' => 'You have that book as book ' . (int) $other . ' already. Choose another.'];
            $title = (string) $book['title']; $author = (string) $book['author'];
            /* The count the claim was started with stands, so a corrected list
               does not reshape a summary half written. */
            $chapters = $same && (int) $have['chapters'] > 0 ? (int) $have['chapters'] : (int) $book['chapters'];
        }
        $notes    = self::chapterNotes($in['chapter_notes'] ?? [], $chapters);
        $started  = self::date((string) ($in['started_on'] ?? ''));
        $finished = self::date((string) ($in['finished_on'] ?? ''));
        $refl     = mb_substr(trim((string) ($in['reflection'] ?? '')), 0, self::MAX_REFLECTION);
        $take     = mb_substr(trim((string) ($in['takeaway'] ?? '')), 0, 600);

        if ($submit) {
            $why = self::whyNotReady($title, $author, $started, $finished, $refl, $take, $notes);
            if ($why !== '') return ['ok' => false, 'error' => $why];
        }

        $row = [
            'member_id'   => $memberId,
            'slot'        => $slot,
            'title'       => $title,
            'author'      => $author,
            'book_id'     => $legacy && $bookId === 0 ? 0 : $bookId,
            'chapters'    => $chapters,
            'chapter_notes' => $chapters > 0 ? (string) json_encode($notes, JSON_UNESCAPED_UNICODE) : '',
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
    public static function whyNotReady(string $title, string $author, string $started, string $finished, string $refl, string $take, array $chapterNotes = []): string
    {
        if ($title === '')  return 'Which book? Put the title in.';
        if ($author === '') return 'Who wrote it?';
        if ($started === '' || $finished === '') return 'When did you start it, and when did you finish?';
        if ($finished < $started) return 'You have finished it before you started it — check those dates.';
        if ($finished > self::today()) return 'You cannot finish a book in the future.';
        foreach (array_values($chapterNotes) as $i => $n) {
            $c = self::substance((string) $n);
            if ($c < self::MIN_CHAPTER) {
                return 'Your summary of chapter ' . ($i + 1) . ' is ' . $c . ' characters. Each chapter needs at least '
                     . self::MIN_CHAPTER . ' — what the chapter said, in your words.';
            }
        }
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

        /* 1b. A chapter summary in the same words as somebody else's summary of
               the same book. Everybody reads from one list now, so this is
               the copy that is easiest to make. */
        if ((int) ($c['book_id'] ?? 0) > 0 && $c['chapter_notes_list']) {
            $mine = array_filter(array_map([self::class, 'fingerprint'], $c['chapter_notes_list']));
            if ($mine) {
                try {
                    $st = NgvDb::pdo()->prepare("SELECT member_id, chapter_notes FROM ngv_book_claims WHERE book_id = ? AND id <> ? AND status <> 'draft'");
                    $st->execute([(int) $c['book_id'], $claimId]);
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $o) {
                        $theirs = array_map([self::class, 'fingerprint'], (array) (json_decode((string) $o['chapter_notes'], true) ?: []));
                        if (array_intersect($mine, array_filter($theirs))) {
                            $flags[] = (int) $o['member_id'] === (int) $c['member_id'] ? 'same-chapter-summary-as-own' : 'same-chapter-summary-as-another-participant';
                            break;
                        }
                    }
                } catch (Throwable $e) { error_log('[ngvreading] chapter dupe: ' . $e->getMessage()); }
            }
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
            'same-chapter-summary-as-another-participant' => 'A chapter summary in the same words as another participant’s — check both.',
            'same-chapter-summary-as-own'       => 'A chapter summary they have used before.',
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
        if ($verdict === 'verified') self::spotMaybeOpen((int) $c['member_id']);
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
        /* `spot_pending` is a BOOLEAN on purpose. The member's page may say
           that a conversation is due — it must never say which book, or the
           randomness that makes the check worth anything is gone. */
        return ['verified' => $v, 'waiting' => $w, 'needs_work' => $back, 'drafts' => $draft,
                'total' => self::TOTAL, 'spot_pending' => self::spotOpen($memberId) !== null];
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
        foreach (['book_id', 'chapters'] as $k) $r[$k] = (int) ($r[$k] ?? 0);
        $notes = json_decode((string) ($r['chapter_notes'] ?? ''), true);
        $r['chapter_notes_list'] = is_array($notes) ? array_values(array_map('strval', $notes)) : [];
        $r['reflection_len'] = self::substance((string) ($r['reflection'] ?? ''));
        return $r;
    }

    /* ── the spoken check ────────────────────────────────────────────────
     *
     * This is the one part of the system that reaches the case none of the
     * automatic checks can: somebody who read a summary and wrote well about
     * it. Nothing in software distinguishes them from a reader. A two-minute
     * conversation does, and always has — it is how every viva, every seminar
     * and every reading group has ever worked.
     *
     * So the software does not try to be the check. It does the parts a person
     * is bad at: remembering that a check is due, choosing the book WITHOUT
     * letting the participant influence or foresee the choice, and keeping
     * the result where it cannot be quietly edited. The judgement stays human.
     *
     * The randomness is the load-bearing part. A participant who knows which
     * book they will be asked about can read that one properly and summarise
     * the rest. Choosing at random from everything they have claimed, and
     * never telling them in advance, means the only reliable way to pass every
     * check is to have read every book — which is the outcome the programme
     * wanted in the first place.
     */

    /** The open spot check for a participant, or null. */
    public static function spotOpen(int $memberId): ?array
    {
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare(
                "SELECT s.*, c.title, c.author, c.slot, c.takeaway, c.reflection
                   FROM ngv_book_spot_checks s
                   LEFT JOIN ngv_book_claims c ON c.id = s.claim_id
                  WHERE s.member_id = ? AND s.outcome = 'open' ORDER BY s.id ASC LIMIT 1");
            $st->execute([$memberId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) { return null; }
    }

    /**
     * Open a check if the participant has just crossed a multiple of six and
     * does not already have one waiting. Called after a verification — never
     * from anything the participant can trigger.
     */
    private static function spotMaybeOpen(int $memberId): void
    {
        $n = self::verifiedCount($memberId);
        if ($n < self::SPOT_EVERY) return;
        $milestone = intdiv($n, self::SPOT_EVERY) * self::SPOT_EVERY;

        try {
            $pdo = NgvDb::pdo();
            /* One check per milestone, and never two open at once — a track
               lead facing a backlog of them does none of them. */
            $st = $pdo->prepare('SELECT COUNT(*) FROM ngv_book_spot_checks WHERE member_id = ? AND (milestone = ? OR outcome = ?)');
            $st->execute([$memberId, $milestone, 'open']);
            if ((int) $st->fetchColumn() > 0) return;

            /* Prefer a book nobody has asked about yet, so a long-running
               participant is not asked about the same one twice. */
            $st = $pdo->prepare(
                "SELECT id FROM ngv_book_claims
                  WHERE member_id = ? AND status = 'verified'
                    AND id NOT IN (SELECT claim_id FROM ngv_book_spot_checks WHERE member_id = ?)");
            $st->execute([$memberId, $memberId]);
            $pool = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if ($pool === []) {
                $st = $pdo->prepare("SELECT id FROM ngv_book_claims WHERE member_id = ? AND status = 'verified'");
                $st->execute([$memberId]);
                $pool = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            }
            if ($pool === []) return;

            /* random_int, not rand: the choice must not be predictable from
               anything the participant can see or time. */
            $pick = (int) $pool[random_int(0, count($pool) - 1)];

            $pdo->prepare('INSERT INTO ngv_book_spot_checks (member_id,claim_id,milestone,outcome,created_at) VALUES (?,?,?,?,?)')
                ->execute([$memberId, $pick, $milestone, 'open', self::now()]);
            self::audit('ngv_spot_check_due', $memberId, 'Spoken check due at ' . $milestone . ' books');
        } catch (Throwable $e) { error_log('[ngvreading] spot open: ' . $e->getMessage()); }
    }

    /**
     * Record how the conversation went.
     *
     * A failed check sends that book back for resubmission rather than
     * deleting it or touching the rest. It is one data point from one
     * conversation: it may mean somebody did not read the book, or that they
     * were nervous, or read it eight months ago. Treating it as proof of
     * dishonesty would be the same overreach as treating a paste count as
     * proof — and a programme that quietly voids a participant's record on
     * one awkward exchange deserves the reputation it will get. The note says
     * what happened; a person decides what it means.
     */
    public static function spotRecord(int $id, string $outcome, int $byUid, string $note = ''): array
    {
        self::ensure();
        if (!in_array($outcome, ['passed', 'failed'], true)) return ['ok' => false, 'error' => 'Unknown outcome.'];
        $note = mb_substr(trim($note), 0, 1000);
        if ($outcome === 'failed' && $note === '') {
            return ['ok' => false, 'error' => 'Write what they could not answer — a failed check with no note is not reviewable.'];
        }

        try {
            $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_book_spot_checks WHERE id = ?');
            $st->execute([$id]);
            $s = $st->fetch(PDO::FETCH_ASSOC);
            if (!$s) return ['ok' => false, 'error' => 'No such check.'];
            if ((string) $s['outcome'] !== 'open') return ['ok' => false, 'error' => 'That check is already recorded.'];

            NgvDb::pdo()->prepare('UPDATE ngv_book_spot_checks SET outcome = ?, asked_by = ?, asked_at = ?, note = ? WHERE id = ?')
                ->execute([$outcome, max(0, $byUid), self::now(), $note, $id]);

            if ($outcome === 'failed' && (int) $s['claim_id'] > 0) {
                self::review((int) $s['claim_id'], 'resubmit', $byUid,
                    'Asked about this one in person: ' . $note);
            }
            self::audit('ngv_spot_check_' . $outcome, (int) $s['member_id'],
                'Spoken check at ' . (int) $s['milestone'] . ' books' . ($note !== '' ? ' — ' . mb_substr($note, 0, 120) : ''));
            return ['ok' => true, 'outcome' => $outcome];
        } catch (Throwable $e) {
            error_log('[ngvreading] spot record: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not record that.'];
        }
    }

    /** Everything waiting to be asked, oldest first. */
    public static function spotQueue(int $limit = 50): array
    {
        self::ensure();
        $limit = max(1, min(200, $limit));
        try {
            $st = NgvDb::pdo()->prepare(
                "SELECT s.*, c.title, c.author, c.slot, c.takeaway, p.name AS member_name
                   FROM ngv_book_spot_checks s
                   LEFT JOIN ngv_book_claims c ON c.id = s.claim_id
                   LEFT JOIN ngv_participants p ON p.member_id = s.member_id
                  WHERE s.outcome = 'open' ORDER BY s.id ASC LIMIT " . $limit);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[ngvreading] spot queue: ' . $e->getMessage()); return []; }
    }

    public static function spotCount(): int
    {
        self::ensure();
        try { return (int) NgvDb::pdo()->query("SELECT COUNT(*) FROM ngv_book_spot_checks WHERE outcome = 'open'")->fetchColumn(); }
        catch (Throwable $e) { return 0; }
    }

    /** A participant's past checks — shown on their record, not on their shelf. */
    public static function spotHistory(int $memberId): array
    {
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare(
                "SELECT s.*, c.title FROM ngv_book_spot_checks s
                   LEFT JOIN ngv_book_claims c ON c.id = s.claim_id
                  WHERE s.member_id = ? AND s.outcome <> 'open' ORDER BY s.id DESC");
            $st->execute([$memberId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
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
