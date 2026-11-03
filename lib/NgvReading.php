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

    /** And a ceiling, so nobody pastes a chapter to clear the floor. Over it
     *  is a refusal with the number in it, never a silent truncation. */
    public const MAX_REFLECTION = 6000;
    public const MAX_TAKEAWAY = 600;

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

    /* ── A SITTING, not just a summary ──────────────────────────────────
     *
     * A book used to leave one record: the reflection written at the end. So
     * the programme could see that somebody finished twenty-four books and
     * nothing about the reading itself — not when it happened, not how long
     * it took, not what they made of it as they went. A claim backed by eight
     * dated sittings is also far harder to invent than one backed by nothing,
     * which is why at least one is now part of the evidence floor.
     *
     * Each sitting answers the questions the programme actually cares about,
     * and they are not comprehension questions:
     *
     *   what it covered        the sitting in their own words
     *   where they stand       what they AGREE with or are AGAINST, and why
     *   what they will do      the life application of this reading
     *
     * The seven values are NOT asked for on a sitting. The book list carries
     * them (`ngv_books.value_keys`) so the programme can curate what it puts
     * in front of people; what a given book grew in a given person is a
     * judgement for a conversation, not a dropdown, and the spoken check is
     * where that conversation already happens.
     */

    /** The floor for a sitting. Smaller than the book's, because it is one sitting. */
    public const MIN_SESSION_NOTES = 120;
    public const MIN_SESSION_STANCE = 80;
    public const MIN_SESSION_APPLICATION = 60;
    private const MAX_SESSION_TEXT = 4000;
    public const MAX_SESSION_MINUTES = 600;

    /** Optional, and the only optional one. */
    public const MOODS = [
        'absorbed'   => 'Absorbed — I did not want to stop',
        'steady'     => 'Steady — it went fine',
        'hard'       => 'Hard going — I had to push',
        'bored'      => 'Bored — I read it anyway',
        'unsettled'  => 'Unsettled — it argued with me',
    ];

    /**
     * The seven values, from the one place they are declared
     * (MentorPortal::VALUES), never a second copy. The mentor portal, the
     * Quest's points and the conduct awards all read that list; reading
     * joins them rather than inventing its own seven.
     */
    public static function values(): array
    {
        return class_exists('MentorPortal') ? MentorPortal::VALUES : [];
    }

    public static function valueLabel(string $key): string
    {
        return (string) (self::values()[$key] ?? $key);
    }

    /** The sentence each value is taught in, for the person choosing one. */
    public static function valueMotto(string $key): string
    {
        return class_exists('MentorPortal') ? (string) (MentorPortal::VALUE_MOTTO[$key] ?? '') : '';
    }

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
  value_keys  VARCHAR(200) NOT NULL DEFAULT '',
  cover_url   VARCHAR(300) NOT NULL DEFAULT '',
  ebook_url   VARCHAR(500) NOT NULL DEFAULT '',
  ebook_kind  VARCHAR(10) NOT NULL DEFAULT '',
  ebook_note  VARCHAR(200) NOT NULL DEFAULT '',
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
CREATE TABLE IF NOT EXISTS ngv_reading_sessions (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  member_id    INTEGER NOT NULL,
  claim_id     INTEGER NOT NULL DEFAULT 0,
  book_id      INTEGER NOT NULL DEFAULT 0,
  slot         INTEGER NOT NULL DEFAULT 0,
  day          VARCHAR(10) NOT NULL DEFAULT '',
  minutes      INTEGER NOT NULL DEFAULT 0,
  chapter_from INTEGER NOT NULL DEFAULT 0,
  chapter_to   INTEGER NOT NULL DEFAULT 0,
  pages        VARCHAR(40) NOT NULL DEFAULT '',
  place        VARCHAR(80) NOT NULL DEFAULT '',
  mood         VARCHAR(16) NOT NULL DEFAULT '',
  notes        TEXT NOT NULL DEFAULT '',
  stance       TEXT NOT NULL DEFAULT '',
  application  TEXT NOT NULL DEFAULT '',
  created_at   VARCHAR(32) NOT NULL DEFAULT '',
  updated_at   VARCHAR(32) NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_rsession_claim ON ngv_reading_sessions (claim_id, day);
CREATE INDEX IF NOT EXISTS idx_rsession_member ON ngv_reading_sessions (member_id, day);
CREATE TABLE IF NOT EXISTS ngv_reading_reviewers (
  member_id   INTEGER PRIMARY KEY,
  added_by    INTEGER NOT NULL DEFAULT 0,
  created_at  VARCHAR(32) NOT NULL DEFAULT ''
);
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

    /* ── who may review ────────────────────────────────────────────────────
     *
     * Every member-facing string in this programme says the TRACK LEAD checks
     * the book — the dashboard, the portal, the verdict emails. There was no
     * track lead in the code. The only door to review() was the NGV console,
     * which is gated on `admin`/`superadmin`, so either a track lead was given
     * full NGV admin — the roster, the fines desk, the ledger, the offline
     * payments queue — or the reading work funnelled to the two or three
     * people who had it. The second is what happened, and it is why nobody
     * noticed that three spoken checks in four were never opening.
     *
     * So: a grant that is exactly this and nothing else. A named member may
     * review reading claims and hold the spoken conversations. They get
     * academy/ngv/reading-review.php and no other NGV screen.
     */

    /** Members granted the reading review, newest first. */
    public static function reviewers(): array
    {
        self::ensure();
        try {
            $st = NgvDb::pdo()->query(
                'SELECT r.*, p.name, p.email FROM ngv_reading_reviewers r
                   LEFT JOIN ngv_participants p ON p.member_id = r.member_id
                  ORDER BY r.created_at DESC, r.member_id');
            /* Same join, same gap: a checker who is not a vanguard — which a
               member granted the review on this very page is — read "Member
               #66" on the screen that granted it. */
            return self::nameRows($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'name', 'email');
        } catch (Throwable $e) { error_log('[ngvreading] reviewers: ' . $e->getMessage()); return []; }
    }

    public static function isReviewer(int $memberId): bool
    {
        if ($memberId <= 0) return false;
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare('SELECT 1 FROM ngv_reading_reviewers WHERE member_id = ?');
            $st->execute([$memberId]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    /**
     * May this request review reading? An NGV admin always may; so does a
     * member who has been granted it. Both doors, one sentence, so a surface
     * cannot accidentally open to one and not the other.
     */
    public static function canReview(int $memberId): bool
    {
        $role = function_exists('av_admin_role') ? av_admin_role() : '';
        if (in_array($role, ['admin', 'superadmin'], true)) return true;
        return self::isReviewer($memberId);
    }

    public static function setReviewer(int $memberId, bool $on, int $by): array
    {
        self::ensure();
        if ($memberId <= 0) return ['ok' => false, 'error' => 'Which member?'];
        if ($on && !NgvMember::participant($memberId) && !self::memberExists($memberId)) {
            return ['ok' => false, 'error' => 'No such member.'];
        }
        try {
            $pdo = NgvDb::pdo();
            if ($on) {
                $st = $pdo->prepare('SELECT 1 FROM ngv_reading_reviewers WHERE member_id = ?');
                $st->execute([$memberId]);
                if (!$st->fetchColumn()) {
                    $pdo->prepare('INSERT INTO ngv_reading_reviewers (member_id, added_by, created_at) VALUES (?,?,?)')
                        ->execute([$memberId, max(0, $by), self::now()]);
                }
            } else {
                $pdo->prepare('DELETE FROM ngv_reading_reviewers WHERE member_id = ?')->execute([$memberId]);
            }
        } catch (Throwable $e) {
            error_log('[ngvreading] setReviewer: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save that.'];
        }
        self::audit($on ? 'ngv_reading_reviewer_add' : 'ngv_reading_reviewer_remove', $memberId,
            ($on ? 'May now check reading claims and hold the spoken conversations'
                 : 'No longer checks reading claims'));
        return ['ok' => true];
    }

    private static function memberExists(int $memberId): bool
    {
        try {
            $st = Database::pdo()->prepare('SELECT 1 FROM lms_users WHERE id = ?');
            $st->execute([$memberId]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) { return false; }
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
        return array_map([self::class, 'shapeBook'], $rows);
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
        return self::shapeBook($b);
    }

    private static function shapeBook(array $b): array
    {
        foreach (['id', 'chapters', 'active', 'created_by'] as $k) $b[$k] = (int) ($b[$k] ?? 0);
        $b['value_list'] = array_values(array_filter(explode(',', (string) ($b['value_keys'] ?? ''))));
        $b['value_labels'] = array_map([self::class, 'valueLabel'], $b['value_list']);
        $b['cover_url'] = (string) ($b['cover_url'] ?? '');
        $b['ebook_url']  = (string) ($b['ebook_url'] ?? '');
        $b['ebook_kind'] = (string) ($b['ebook_kind'] ?? '');
        $b['ebook_note'] = (string) ($b['ebook_note'] ?? '');
        $b['ebook_label'] = self::EBOOK_LABELS[$b['ebook_kind']] ?? '';
        return $b;
    }

    /** What a cover may be, and how big. A cover is decoration: it must never
     *  be the reason a book cannot be added. */
    public const COVER_MAX_BYTES = 4 * 1024 * 1024;
    public const COVER_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Put a cover on a book, or take it off.
     *
     * Stored through Storage::put — Cloudinary where it is configured, this
     * server's own /uploads where it is not — the same path the ID card photo
     * takes, so a deployment with no third-party storage still works.
     *
     * A book is a title and an author; the cover is how somebody finds it on a
     * shelf. So a failure to store one is reported in words and changes
     * nothing else about the book.
     */
    public static function setBookCover(int $id, ?array $file, int $by, bool $remove = false): array
    {
        self::ensure();
        $book = self::book($id);
        if (!$book) return ['ok' => false, 'error' => 'No such book.'];
        if ($remove) {
            try {
                NgvDb::pdo()->prepare('UPDATE ngv_books SET cover_url = ?, updated_at = ? WHERE id = ?')
                    ->execute(['', self::now(), $id]);
            } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not remove the cover.']; }
            self::auditBook('ngv_book_cover_clear', $id, (string) $book['title'], $by);
            return ['ok' => true, 'cover_url' => '', 'said' => 'Cover removed.'];
        }
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return ['ok' => false, 'error' => 'No image arrived. Choose a file and try again.'];
        }
        if ((int) ($file['size'] ?? 0) > self::COVER_MAX_BYTES) {
            return ['ok' => false, 'error' => 'That image is larger than ' . (int) (self::COVER_MAX_BYTES / 1048576) . 'MB.'];
        }
        $tmp = (string) $file['tmp_name'];
        /* What the bytes ARE, not what the name says — /uploads is under the
           web root, so a .php named .jpg is the thing to refuse here. */
        $mime = class_exists('Storage') ? Storage::mime($tmp) : (string) (@getimagesize($tmp)['mime'] ?? '');
        if (!in_array($mime, self::COVER_TYPES, true)) {
            return ['ok' => false, 'error' => 'A cover must be a JPEG, PNG, WebP or GIF image.'];
        }
        $url = '';
        try {
            $put = Storage::put($tmp, 'book-' . $id . '-cover', 'image', 'books');
            $url = (string) ($put['url'] ?? '');
        } catch (Throwable $e) {
            error_log('[ngvreading] cover: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The cover could not be stored: ' . $e->getMessage() . ' Check that uploads/ is writable, or the Cloudinary keys.'];
        }
        if ($url === '') return ['ok' => false, 'error' => 'The cover could not be stored — no storage answered.'];
        if (mb_strlen($url) > 300) return ['ok' => false, 'error' => 'The image address is too long to keep.'];
        try {
            NgvDb::pdo()->prepare('UPDATE ngv_books SET cover_url = ?, updated_at = ? WHERE id = ?')
                ->execute([$url, self::now(), $id]);
        } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not record the cover.']; }
        self::auditBook('ngv_book_cover', $id, (string) $book['title'], $by);
        return ['ok' => true, 'cover_url' => $url, 'said' => 'Cover saved.'];
    }

    /* ══ The e-version ════════════════════════════════════════════════════
       A copy somebody can actually open, because most of the people this
       challenge is for do not have the book. It is either a FILE the office
       holds (a PDF or an EPUB) or a LINK to where it is free and legitimate
       — Project Gutenberg, a publisher's own page, an author's site. Both,
       because the office has some of each and a field that takes only one of
       them would push the other into the note where nothing can open it.

       It is never required. A book with no e-version is a book somebody
       borrows or buys, which is how most of the list works. */

    public const EBOOK_MAX_BYTES = 25 * 1024 * 1024;
    public const EBOOK_TYPES = ['application/pdf', 'application/epub+zip'];
    public const EBOOK_LABELS = ['pdf' => 'PDF', 'epub' => 'EPUB', 'link' => 'Read online'];

    /**
     * What this server will actually accept, in bytes.
     *
     * PHP refuses an upload larger than upload_max_filesize or post_max_size
     * BEFORE any of our code runs — $_FILES comes back empty and the page can
     * only say "no file arrived", which sends an operator looking for a
     * problem with the file. Shared hosting commonly caps this at 2M, well
     * under a scanned book. So the screen states the real limit, and a file
     * over it is refused in those words rather than vanishing.
     */
    public static function uploadLimitBytes(): int
    {
        $toBytes = static function (string $v): int {
            $v = trim($v);
            if ($v === '') return 0;
            $n = (int) $v;
            return match (strtolower(substr($v, -1))) {
                'g' => $n * 1024 * 1024 * 1024,
                'm' => $n * 1024 * 1024,
                'k' => $n * 1024,
                default => $n,
            };
        };
        $limits = array_filter([
            $toBytes((string) ini_get('upload_max_filesize')),
            $toBytes((string) ini_get('post_max_size')),
            self::EBOOK_MAX_BYTES,
        ], static fn($n) => $n > 0);
        return $limits ? (int) min($limits) : self::EBOOK_MAX_BYTES;
    }

    /** A size a person reads, not a number of bytes. */
    public static function sizeWords(int $bytes): string
    {
        if ($bytes >= 1048576) return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . 'MB';
        return max(1, (int) round($bytes / 1024)) . 'KB';
    }

    /**
     * Is this really an EPUB?
     *
     * An EPUB is a zip, and fileinfo on a shared host often calls it
     * application/zip — so trusting the sniffed type alone rejects real
     * books, and trusting the .epub NAME accepts anything at all. The format
     * settles it: the spec requires the first entry to be an uncompressed
     * file called "mimetype" holding exactly "application/epub+zip", which
     * puts that string at byte 30. That is a property of the bytes, which is
     * the only thing worth checking on a file going under the web root.
     */
    public static function isEpub(string $tmp): bool
    {
        $fh = @fopen($tmp, 'rb');
        if (!$fh) return false;
        $head = (string) fread($fh, 58);
        fclose($fh);
        return str_starts_with($head, "PK\x03\x04") && str_contains($head, 'mimetypeapplication/epub+zip');
    }

    /**
     * Give a book an e-version, or take it away.
     *
     * $link wins when it is given, because a link needs no upload; otherwise
     * a file is stored through Storage::put, the same path the cover takes.
     * The note is for what neither can say — "chapters 1-8 only", "the 1994
     * edition", "free on Gutenberg".
     */
    public static function setBookEbook(int $id, ?array $file, int $by, bool $remove = false, string $link = '', string $note = ''): array
    {
        self::ensure();
        $book = self::book($id);
        if (!$book) return ['ok' => false, 'error' => 'No such book.'];
        $note = mb_substr(trim(preg_replace('/\s+/u', ' ', $note) ?? ''), 0, 200);

        if ($remove) {
            try {
                NgvDb::pdo()->prepare("UPDATE ngv_books SET ebook_url = '', ebook_kind = '', ebook_note = '', updated_at = ? WHERE id = ?")
                    ->execute([self::now(), $id]);
            } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not remove it.']; }
            self::auditBook('ngv_book_ebook_clear', $id, (string) $book['title'], $by);
            return ['ok' => true, 'ebook_url' => '', 'said' => 'The e-version was removed.'];
        }

        /* "No file" and "a file that failed" are different answers, and
           conflating them read a 12MB upload rejected by PHP as somebody
           quietly editing the note — the operator was told "Note saved" and
           the book still had no copy. Only a field with NO file in it falls
           through to the note. */
        $err = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        $haveFile = $err === UPLOAD_ERR_OK;
        $triedFile = is_array($file) && $err !== UPLOAD_ERR_NO_FILE;
        if ($link === '' && !$haveFile && !$triedFile && (string) $book['ebook_url'] !== '') {
            try {
                NgvDb::pdo()->prepare('UPDATE ngv_books SET ebook_note = ?, updated_at = ? WHERE id = ?')
                    ->execute([$note, self::now(), $id]);
            } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not save the note.']; }
            return ['ok' => true, 'ebook_url' => (string) $book['ebook_url'], 'said' => 'Note saved.'];
        }

        if ($link !== '') {
            $link = trim($link);
            if (mb_strlen($link) > 500) return ['ok' => false, 'error' => 'That address is too long to keep.'];
            /* https only, as the course links are: an http link to a book is
               a link somebody else can rewrite in transit. */
            if (!preg_match('~^https://~i', $link) || !filter_var($link, FILTER_VALIDATE_URL)) {
                return ['ok' => false, 'error' => 'A link must be a full https:// web address.'];
            }
            try {
                NgvDb::pdo()->prepare("UPDATE ngv_books SET ebook_url = ?, ebook_kind = 'link', ebook_note = ?, updated_at = ? WHERE id = ?")
                    ->execute([$link, $note, self::now(), $id]);
            } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not record the link.']; }
            self::auditBook('ngv_book_ebook_link', $id, (string) $book['title'], $by);
            return ['ok' => true, 'ebook_url' => $link, 'ebook_kind' => 'link', 'said' => 'The link was saved.'];
        }

        $cap = self::uploadLimitBytes();
        if (!$haveFile || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            /* UPLOAD_ERR_INI_SIZE / FORM_SIZE, and the empty $_FILES a file
               over post_max_size produces, all land here. Say the number. */
            return ['ok' => false, 'code' => 'no_file',
                    'error' => 'No file arrived. This server accepts uploads up to ' . self::sizeWords($cap)
                             . ' — a larger book has to go in as a link instead.'];
        }
        if ((int) ($file['size'] ?? 0) > $cap) {
            return ['ok' => false, 'error' => 'That file is ' . self::sizeWords((int) $file['size'])
                . ' and this server accepts up to ' . self::sizeWords($cap) . '. Put it in as a link instead.'];
        }
        $tmp  = (string) $file['tmp_name'];
        $mime = class_exists('Storage') ? Storage::mime($tmp) : '';
        $kind = '';
        if ($mime === 'application/pdf') $kind = 'pdf';
        elseif (self::isEpub($tmp)) { $kind = 'epub'; $mime = 'application/epub+zip'; }
        if ($kind === '') return ['ok' => false, 'error' => 'An e-version must be a PDF or an EPUB.'];

        try {
            $put = Storage::put($tmp, 'book-' . $id . '.' . $kind, 'document', 'books');
            $url = (string) ($put['url'] ?? '');
        } catch (Throwable $e) {
            error_log('[ngvreading] ebook: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'It could not be stored: ' . $e->getMessage()];
        }
        if ($url === '' || mb_strlen($url) > 500) return ['ok' => false, 'error' => 'It could not be stored.'];
        try {
            NgvDb::pdo()->prepare('UPDATE ngv_books SET ebook_url = ?, ebook_kind = ?, ebook_note = ?, updated_at = ? WHERE id = ?')
                ->execute([$url, $kind, $note, self::now(), $id]);
        } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not record it.']; }
        self::auditBook('ngv_book_ebook', $id, (string) $book['title'] . ' (' . $kind . ')', $by);
        return ['ok' => true, 'ebook_url' => $url, 'ebook_kind' => $kind, 'said' => strtoupper($kind) . ' saved.'];
    }

    private static function auditBook(string $what, int $id, string $title, int $by): void
    {
        if (!class_exists('AdminAudit')) return;
        try { AdminAudit::log('ngv', $what, 'ngv:book:' . $id, $title, null, $by > 0 ? 'admin' : 'system'); } catch (Throwable $e) {}
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
        /* Which of the seven this book is on the list FOR. The challenge
           exists to grow them, so the list is curated against them: a
           programme can see it has eleven books on Diligence and none on
           Communal Spirit before a cohort reads its way into the same gap. */
        $vals = array_values(array_intersect(
            array_map('strval', (array) ($in['value_keys'] ?? [])), array_keys(self::values())));
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
                $pdo->prepare('UPDATE ngv_books SET title = ?, author = ?, chapters = ?, note = ?, value_keys = ?, updated_at = ? WHERE id = ?')
                    ->execute([$title, $author, $chapters, $note, implode(',', $vals), self::now(), $id]);
                self::audit('ngv_book_list_edit', 0, 'Book list: ' . $title . ' — ' . $chapters . ' chapters');
            } else {
                $pdo->prepare('INSERT INTO ngv_books (title, author, chapters, note, value_keys, active, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)')
                    ->execute([$title, $author, $chapters, $note, implode(',', $vals), $by, self::now(), self::now()]);
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

    /** The chapter summaries as sent: one string per chapter, trimmed. */
    private static function chapterNotes($raw, int $chapters): array
    {
        $raw = is_array($raw) ? array_values($raw) : [];
        $out = [];
        for ($i = 0; $i < $chapters; $i++) $out[] = trim((string) (is_scalar($raw[$i] ?? null) ? $raw[$i] : ''));
        return $out;
    }

    /** The first chapter summary that is over the ceiling, as a sentence, or ''. */
    private static function chapterTooLong(array $notes): string
    {
        foreach (array_values($notes) as $i => $n) {
            if (mb_strlen((string) $n) > self::MAX_CHAPTER) {
                return 'Your summary of chapter ' . ($i + 1) . ' is ' . mb_strlen((string) $n)
                     . ' characters and the most we can keep is ' . self::MAX_CHAPTER . '. Nothing has been saved.';
            }
        }
        return '';
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

        /* WHAT THE MEMBER MAY TOUCH. A draft is theirs; one sent back is
           theirs again. A submitted one is not — a claim a reviewer is
           reading must not change underneath them. A verified one never is.
           And a REJECTED one is not either: "send back to rewrite" and
           "reject" are offered as two different decisions and used to do the
           same thing, because a rejected claim was fully editable and could
           be resubmitted — so the only difference between them was the word
           in the email. A rejection closes the claim. A reviewer who got it
           wrong, or a member who makes their case, has a door: a reviewer can
           reopen it ('resubmit'), and that is recorded like any verdict. */
        $have = self::claim($memberId, $slot);
        if ($have && in_array((string) $have['status'], ['submitted', 'verified', 'rejected'], true)) {
            $msg = [
                'verified'  => 'This one is already verified. It cannot be changed.',
                'submitted' => 'This one is with your track lead. You will be able to edit it if they send it back.',
                'rejected'  => 'This claim was not accepted, so it is closed. Speak to your track lead — they can reopen it if you have more to say.',
            ];
            return ['ok' => false, 'code' => 'closed', 'error' => $msg[(string) $have['status']]];
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
        if ($why = self::chapterTooLong($notes)) return ['ok' => false, 'code' => 'too_long', 'error' => $why];
        $started  = self::date((string) ($in['started_on'] ?? ''));
        $finished = self::date((string) ($in['finished_on'] ?? ''));
        /* NOT silently cut. The floor is reported in careful words ("your
           reflection is 212 characters, we need 320"); the ceiling used to be
           an mb_substr and nothing — so somebody who wrote at length lost the
           end of it without being told, and could then be asked in a spoken
           check about a part the record no longer held. */
        $refl = trim((string) ($in['reflection'] ?? ''));
        $take = trim((string) ($in['takeaway'] ?? ''));
        if (mb_strlen($refl) > self::MAX_REFLECTION) {
            return ['ok' => false, 'code' => 'too_long', 'error' => 'Your reflection is ' . mb_strlen($refl)
                . ' characters and the most we can keep is ' . self::MAX_REFLECTION
                . '. Shorten it before you lose the end of it — nothing has been saved.'];
        }
        if (mb_strlen($take) > self::MAX_TAKEAWAY) {
            return ['ok' => false, 'code' => 'too_long', 'error' => 'What you did with it is ' . mb_strlen($take)
                . ' characters and the most we can keep is ' . self::MAX_TAKEAWAY . '. Nothing has been saved.'];
        }

        if ($submit) {
            $why = self::whyNotReady($title, $author, $started, $finished, $refl, $take, $notes);
            if ($why !== '') return ['ok' => false, 'error' => $why];
            /* AND THE SITTINGS. A book used to leave one record, written at
               the end, so the programme could see that twenty-four were
               finished and nothing about the reading. A claim with dated
               sittings behind it is also much harder to invent than one with
               none. Checked against the row, which is why it is here rather
               than in whyNotReady() — that function is pure. */
            if ($have && self::sessionCount((int) $have['id']) < 1) {
                return ['ok' => false, 'code' => 'no_sessions',
                        'error' => 'Log at least one reading sitting before you send this — when you read, how far you got, and what you made of it.'];
            }
            if (!$have) {
                return ['ok' => false, 'code' => 'no_sessions',
                        'error' => 'Save this as a draft first, log your reading sittings against it, then send it.'];
            }
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

    /* ── how the challenge is going ────────────────────────────────────────
     *
     * A reading challenge is a thing a programme MANAGES, and until this the
     * only way to ask "how is it going" was to open the console and count
     * tiles. Three questions, answered in four queries rather than one per
     * participant — this is a roster read, and a per-row query inside one is
     * how a page stops being opened.
     */
    public static function cohort(int $stalledDays = 28): array
    {
        self::ensure();
        $pdo = NgvDb::pdo();
        $out = ['people' => [], 'verified' => 0, 'waiting' => 0, 'stalled' => 0, 'oldest_days' => 0, 'minutes' => 0];
        try {
            $rows = $pdo->query("SELECT member_id, name, track, start_date FROM ngv_participants WHERE status = 'active' ORDER BY name")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$rows) return $out;

            $by = [];
            foreach ($rows as $r) {
                $by[(int) $r['member_id']] = ['member_id' => (int) $r['member_id'], 'name' => (string) $r['name'],
                    'track' => (string) ($r['track'] ?? ''), 'verified' => 0, 'waiting' => 0, 'needs_work' => 0,
                    'sessions' => 0, 'minutes' => 0, 'last_read' => ''];
            }
            foreach ($pdo->query("SELECT member_id, status, COUNT(*) n FROM ngv_book_claims GROUP BY member_id, status")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $m = (int) $r['member_id']; if (!isset($by[$m])) continue;
                $k = ['verified' => 'verified', 'submitted' => 'waiting', 'resubmit' => 'needs_work', 'rejected' => 'needs_work'][(string) $r['status']] ?? '';
                if ($k !== '') $by[$m][$k] += (int) $r['n'];
            }
            foreach ($pdo->query("SELECT member_id, COUNT(*) n, COALESCE(SUM(minutes),0) m, MAX(day) d
                                    FROM ngv_reading_sessions GROUP BY member_id")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $m = (int) $r['member_id']; if (!isset($by[$m])) continue;
                $by[$m]['sessions'] = (int) $r['n'];
                $by[$m]['minutes']  = (int) $r['m'];
                $by[$m]['last_read'] = (string) $r['d'];
            }
            $today = strtotime(self::today());
            foreach ($by as &$p) {
                $p['stalled_days'] = $p['last_read'] !== '' ? (int) floor(($today - strtotime($p['last_read'])) / 86400) : null;
                /* Stalled means they started and stopped. Somebody who has
                   never logged a sitting has not stalled, they have not
                   begun — a different conversation, and this says which. */
                $p['stalled'] = $p['verified'] < self::TOTAL && $p['stalled_days'] !== null && $p['stalled_days'] >= $stalledDays;
                $out['verified'] += $p['verified'];
                $out['waiting']  += $p['waiting'];
                $out['minutes']  += $p['minutes'];
                if ($p['stalled']) $out['stalled']++;
            }
            unset($p);
            $out['people'] = array_values($by);

            $oldest = (string) $pdo->query("SELECT MIN(submitted_at) FROM ngv_book_claims WHERE status = 'submitted' AND submitted_at <> ''")->fetchColumn();
            $out['oldest_days'] = $oldest !== '' ? max(0, (int) floor(($today - strtotime($oldest)) / 86400)) : 0;
        } catch (Throwable $e) { error_log('[ngvreading] cohort: ' . $e->getMessage()); }
        return $out;
    }

    /* ── the sittings ──────────────────────────────────────────────────── */

    /**
     * Log one sitting against a claim, or correct one ($in['id']).
     *
     * A sitting belongs to the claim and the claim belongs to the member, so
     * both are checked here rather than trusted from the request. Once the
     * claim is out of the member's hands — submitted, verified, rejected —
     * its sittings are too: the record a reviewer is reading must not change
     * underneath them, and that includes the evidence under it.
     */
    public static function logSession(int $memberId, array $in): array
    {
        self::ensure();
        if ($memberId <= 0) return ['ok' => false, 'error' => 'Sign in first.'];
        $slot  = (int) ($in['slot'] ?? 0);
        $claim = self::claim($memberId, $slot);
        /* LOGGING A SITTING IS STARTING THE BOOK.
           This used to refuse with "Start the book first, then log your
           reading of it", which was true of the data and false of the
           screen: the sheet offers "Log a sitting" the moment a book is
           picked, so somebody chose their book, wrote out three paragraphs
           about an evening's reading, pressed save and lost it to a sentence
           telling them to have done something they had no way of knowing
           about. The draft is opened here instead, from the book they picked
           — which is what they meant by logging against it. */
        if (!$claim) {
            $bookId = (int) ($in['book_id'] ?? 0);
            if ($bookId <= 0) return ['ok' => false, 'error' => 'Choose the book from the list, then log your reading of it.'];
            $made = self::save($memberId, ['slot' => $slot, 'book_id' => $bookId,
                                           'started_on' => (string) ($in['started_on'] ?? '')], false);
            if (empty($made['ok'])) return $made;
            $claim = self::claim($memberId, $slot);
            if (!$claim) return ['ok' => false, 'error' => 'Could not start that book.'];
        }
        if (in_array((string) $claim['status'], ['submitted', 'verified', 'rejected'], true)) {
            return ['ok' => false, 'code' => 'closed', 'error' => 'This book is no longer yours to change.'];
        }

        $id  = (int) ($in['id'] ?? 0);
        $was = $id > 0 ? self::session($id) : null;
        if ($id > 0 && (!$was || (int) $was['member_id'] !== $memberId || (int) $was['claim_id'] !== (int) $claim['id'])) {
            return ['ok' => false, 'error' => 'No such sitting.'];
        }

        $row = [
            'day'          => self::date((string) ($in['day'] ?? '')),
            'minutes'      => (int) ($in['minutes'] ?? 0),
            'chapter_from' => max(0, (int) ($in['chapter_from'] ?? 0)),
            'chapter_to'   => max(0, (int) ($in['chapter_to'] ?? 0)),
            'pages'        => mb_substr(trim((string) ($in['pages'] ?? '')), 0, 40),
            'place'        => mb_substr(trim((string) ($in['place'] ?? '')), 0, 80),
            'mood'         => isset(self::MOODS[(string) ($in['mood'] ?? '')]) ? (string) $in['mood'] : '',
            'notes'        => trim((string) ($in['notes'] ?? '')),
            'stance'       => trim((string) ($in['stance'] ?? '')),
            'application'  => trim((string) ($in['application'] ?? '')),
        ];
        if ($why = self::whySessionNotReady($row, $claim)) return ['ok' => false, 'error' => $why];

        $row['member_id'] = $memberId;
        $row['claim_id']  = (int) $claim['id'];
        $row['book_id']   = (int) $claim['book_id'];
        $row['slot']      = (int) $claim['slot'];
        $row['updated_at'] = self::now();
        try {
            $pdo = NgvDb::pdo();
            if ($id > 0) {
                $set = implode(', ', array_map(static fn($c) => $c . ' = ?', array_keys($row)));
                $args = array_values($row); $args[] = $id;
                $pdo->prepare('UPDATE ngv_reading_sessions SET ' . $set . ' WHERE id = ?')->execute($args);
            } else {
                $row['created_at'] = self::now();
                $names = implode(',', array_keys($row));
                $marks = implode(',', array_fill(0, count($row), '?'));
                $pdo->prepare("INSERT INTO ngv_reading_sessions ($names) VALUES ($marks)")->execute(array_values($row));
                $id = (int) $pdo->lastInsertId();
            }
        } catch (Throwable $e) {
            error_log('[ngvreading] logSession: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save that sitting.'];
        }
        return ['ok' => true, 'id' => $id, 'sessions' => self::sessions((int) $claim['id'])];
    }

    /** Why this sitting is not ready — '' when it is. */
    public static function whySessionNotReady(array $r, array $claim): string
    {
        if ((string) $r['day'] === '') return 'Which day did you read?';
        if ((string) $r['day'] > self::today()) return 'You cannot log a sitting in the future.';
        $started = (string) ($claim['started_on'] ?? '');
        if ($started !== '' && (string) $r['day'] < $started) {
            return 'That is before ' . $started . ', the day you said you started this book. Fix one or the other.';
        }
        $m = (int) $r['minutes'];
        if ($m < 1 || $m > self::MAX_SESSION_MINUTES) {
            return 'How many minutes did you read for? Between 1 and ' . self::MAX_SESSION_MINUTES . '.';
        }
        $chapters = (int) ($claim['chapters'] ?? 0);
        $from = (int) $r['chapter_from']; $to = (int) $r['chapter_to'];
        if ($from > 0 || $to > 0) {
            if ($from < 1 || $to < 1) return 'Say which chapters you read — from and to.';
            if ($to < $from) return 'The last chapter you read comes before the first one. Check those.';
            if ($chapters > 0 && $to > $chapters) return 'This book has ' . $chapters . ' chapters, so there is no chapter ' . $to . '.';
        } elseif ((string) $r['pages'] === '') {
            return 'How far did you get? Give the chapters, or the pages.';
        }
        if ((string) $r['place'] === '') return 'Where did you read? A word is enough — at the centre, at home, on the bus.';
        foreach ([
            ['notes', self::MIN_SESSION_NOTES, 'What did this sitting cover? In your own words — at least %d characters.'],
            ['stance', self::MIN_SESSION_STANCE, 'What do you agree with here, or argue against, and why? At least %d characters.'],
            ['application', self::MIN_SESSION_APPLICATION, 'What will you do differently because of this sitting? At least %d characters.'],
        ] as [$k, $min, $msg]) {
            $have = self::substance((string) $r[$k]);
            if ($have < $min) return sprintf($msg, $min) . ' You have ' . $have . '.';
            if (mb_strlen((string) $r[$k]) > self::MAX_SESSION_TEXT) {
                return 'That is longer than we can keep (' . self::MAX_SESSION_TEXT . ' characters). Nothing has been saved.';
            }
        }
        return '';
    }

    public static function session(int $id): ?array
    {
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_reading_sessions WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? self::shapeSession($r) : null;
        } catch (Throwable $e) { return null; }
    }

    /** Every sitting on one claim, oldest first — the way it was read. */
    public static function sessions(int $claimId): array
    {
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_reading_sessions WHERE claim_id = ? ORDER BY day, id');
            $st->execute([$claimId]);
            return array_map([self::class, 'shapeSession'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { return []; }
    }

    /** One member's whole reading log, newest first. What the diary shows. */
    public static function sessionsFor(int $memberId, int $limit = 500): array
    {
        self::ensure();
        $limit = max(1, min(2000, $limit));
        try {
            $st = NgvDb::pdo()->prepare(
                'SELECT s.*, c.title, c.author, c.status AS claim_status
                   FROM ngv_reading_sessions s
                   LEFT JOIN ngv_book_claims c ON c.id = s.claim_id
                  WHERE s.member_id = ? ORDER BY s.day DESC, s.id DESC LIMIT ' . $limit);
            $st->execute([$memberId]);
            return array_map([self::class, 'shapeSession'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { error_log('[ngvreading] sessionsFor: ' . $e->getMessage()); return []; }
    }

    public static function dropSession(int $memberId, int $id): array
    {
        self::ensure();
        $s = self::session($id);
        if (!$s || (int) $s['member_id'] !== $memberId) return ['ok' => false, 'error' => 'No such sitting.'];
        $claim = self::claimById((int) $s['claim_id']);
        if ($claim && in_array((string) $claim['status'], ['submitted', 'verified', 'rejected'], true)) {
            return ['ok' => false, 'code' => 'closed', 'error' => 'This book is no longer yours to change.'];
        }
        try { NgvDb::pdo()->prepare('DELETE FROM ngv_reading_sessions WHERE id = ?')->execute([$id]); }
        catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not remove that.']; }
        return ['ok' => true, 'sessions' => self::sessions((int) $s['claim_id'])];
    }

    private static function shapeSession(array $r): array
    {
        foreach (['id', 'member_id', 'claim_id', 'book_id', 'slot', 'minutes', 'chapter_from', 'chapter_to'] as $k) {
            $r[$k] = (int) ($r[$k] ?? 0);
        }
        $r['mood_label']  = (string) (self::MOODS[(string) ($r['mood'] ?? '')] ?? '');
        $r['covered']     = $r['chapter_from'] > 0
            ? ($r['chapter_from'] === $r['chapter_to'] ? 'Chapter ' . $r['chapter_from'] : 'Chapters ' . $r['chapter_from'] . '–' . $r['chapter_to'])
            : ((string) ($r['pages'] ?? '') !== '' ? 'Pages ' . $r['pages'] : '');
        return $r;
    }

    /* ── who may read, and the one handler both doors use ─────────────────── */

    /**
     * Who the 24-book challenge is for.
     *
     * It began as a NextGen Vanguard thing and the code said so: the only
     * door was the NGV dashboard, gated on being enrolled. But reading is not
     * a thing the programme does TO vanguards — it is what the organisation
     * asks of the people in it, and a member or a volunteer who wants to read
     * twenty-four books and have somebody check them was turned away by an
     * `isVanguard()` that had nothing to do with reading.
     *
     *   a NextGen Vanguard      their programme includes it
     *   a VERIFIED member       somebody established they are a member
     *   a volunteer             explicitly one (Volunteers::isVolunteer)
     *
     * A learner nobody has verified is not included: the reading ends in a
     * conversation with somebody at the organisation, and there has to BE a
     * relationship for that conversation to happen.
     */
    public static function mayRead(int $memberId): bool
    {
        if ($memberId <= 0) return false;
        try { if (class_exists('NgvMember') && NgvMember::isVanguard($memberId)) return true; } catch (Throwable $e) {}
        try { if (class_exists('MemberVerification') && MemberVerification::isVerified($memberId)) return true; } catch (Throwable $e) {}
        try {
            if (class_exists('Volunteers') && Volunteers::isVolunteer(['id' => $memberId])) return true;
        } catch (Throwable $e) {}
        return false;
    }

    /**
     * Put a NAME on every row of a review queue.
     *
     * The queues join ngv_participants for it, which answers for a vanguard
     * and for nobody else — and reading (and now courses) is a member's and a
     * volunteer's too. So every member or volunteer on the desk read
     * "Member #412", which is not somebody a reviewer can go and talk to.
     *
     * The participants table is in the SEPARATE NGV database, so this cannot
     * be one more join: it is ONE query against the main table for the rows
     * the join left empty, never one per row.
     *
     * @param array  $rows     Rows carrying member_id.
     * @param string $nameKey  Which key holds the name on these rows.
     * @param string $emailKey Which holds the email, when the screen shows one.
     */
    public static function nameRows(array $rows, string $nameKey = 'member_name', string $emailKey = ''): array
    {
        $need = [];
        foreach ($rows as $r) {
            if (trim((string) ($r[$nameKey] ?? '')) === '' && (int) ($r['member_id'] ?? 0) > 0) {
                $need[(int) $r['member_id']] = true;
            }
        }
        if (!$need) return $rows;
        $ids = array_keys($need);
        $found = [];
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = Database::pdo()->prepare('SELECT id, name, email FROM lms_users WHERE id IN (' . $in . ')');
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) $found[(int) $u['id']] = $u;
        } catch (Throwable $e) { return $rows; }
        foreach ($rows as $k => $r) {
            $id = (int) ($r['member_id'] ?? 0);
            if (trim((string) ($r[$nameKey] ?? '')) !== '' || !isset($found[$id])) continue;
            $rows[$k][$nameKey] = (string) $found[$id]['name'];
            if ($emailKey !== '' && trim((string) ($r[$emailKey] ?? '')) === '') {
                $rows[$k][$emailKey] = (string) $found[$id]['email'];
            }
        }
        return $rows;
    }

    /** Which of the three somebody is, for the copy on their own screen. */
    public static function readerKind(int $memberId): string
    {
        try { if (class_exists('NgvMember') && NgvMember::isVanguard($memberId)) return 'vanguard'; } catch (Throwable $e) {}
        try { if (class_exists('Volunteers') && Volunteers::isVolunteer(['id' => $memberId])) return 'volunteer'; } catch (Throwable $e) {}
        return 'member';
    }

    /**
     * Every reading action, in ONE place, so the NGV dashboard and the
     * portal's Reading page cannot drift into doing them differently.
     *
     * Returns null when `book_action` is not one of ours, so a route can call
     * it first and carry on with its own actions. The member id is the
     * caller's — it is never read from the body, because a claim belongs to
     * whoever is signed in.
     *
     * @return array|null The response to print, or null for "not mine".
     */
    public static function action(int $uid, array $in): ?array
    {
        $act = (string) ($in['book_action'] ?? '');
        if ($act === '') return null;
        if (!self::mayRead($uid)) {
            return ['ok' => false, 'code' => 'not_reading', 'status' => 403,
                    'error' => 'The 24-book challenge is for NextGen Vanguards, members and volunteers. Ask the office if this looks wrong.'];
        }
        if ($act === 'save' || $act === 'submit') {
            $r = self::save($uid, is_array($in['book'] ?? null) ? $in['book'] : [], $act === 'submit');
            if (!empty($r['ok'])) $r['progress'] = self::progress($uid);
            return $r;
        }
        if ($act === 'session') {
            $r = self::logSession($uid, is_array($in['session'] ?? null) ? $in['session'] : []);
            if (!empty($r['ok'])) $r['progress'] = self::progress($uid);
            return $r;
        }
        if ($act === 'session_drop') {
            return self::dropSession($uid, (int) ($in['id'] ?? 0));
        }
        if ($act === 'get') {
            $slot = (int) ($in['slot'] ?? 0);
            $c = self::claim($uid, $slot);
            /* The books they may choose: the list, plus the one this claim
               already names if it has since been retired. */
            $books = array_map(static fn($b) => ['id' => $b['id'], 'title' => $b['title'], 'author' => $b['author'],
                                                 'chapters' => $b['chapters'], 'note' => $b['note'],
                                                 'values' => $b['value_labels'],
                                                 'cover' => $b['cover_url'],
                                                 /* So the sheet can offer to OPEN the book it is
                                                    asking about, rather than making somebody go
                                                    and find it. */
                                                 'ebook' => $b['ebook_url'], 'ebook_label' => $b['ebook_label'],
                                                 'ebook_note' => $b['ebook_note']], self::books());
            if ($c && (int) $c['book_id'] > 0 && !in_array((int) $c['book_id'], array_column($books, 'id'), true)
                && ($old = self::book((int) $c['book_id']))) {
                $books[] = ['id' => $old['id'], 'title' => $old['title'], 'author' => $old['author'],
                            'chapters' => $old['chapters'], 'note' => $old['note'],
                            'ebook' => $old['ebook_url'], 'ebook_label' => $old['ebook_label'],
                            'ebook_note' => $old['ebook_note']];
            }
            return ['ok' => true, 'claim' => $c, 'books' => $books, 'min' => self::MIN_REFLECTION,
                    'minTake' => self::MIN_TAKEAWAY, 'minChapter' => self::MIN_CHAPTER,
                    'sessions' => $c ? $c['sessions'] : [],
                    'moods' => self::MOODS, 'today' => function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d'),
                    'minSit' => ['notes' => self::MIN_SESSION_NOTES, 'stance' => self::MIN_SESSION_STANCE,
                                 'application' => self::MIN_SESSION_APPLICATION, 'minutes' => self::MAX_SESSION_MINUTES]];
        }
        return null;
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
     * Nobody signs off their own reading.
     *
     * The whole design is "a human OTHER THAN the claimant decides", and this
     * was the one place it was not enforced: review() took the reviewer's id
     * and never compared it with the claim's. Leads come up through the
     * programme, so a lead with twenty-four slots of their own is the likely
     * case rather than the exotic one.
     *
     * A $byUid of 0 is the system — the nightly job, a migration — and is not
     * caught, exactly as on the ledger (NgvLedger::selfDealing, which this
     * mirrors deliberately: one sentence, two places money and merit are
     * signed off).
     */
    public static function selfDealing(int $memberId, int $byUid, string $what): string
    {
        if ($byUid <= 0 || $byUid !== $memberId) return '';
        return 'This is your own reading. Another member of staff ' . $what . '.';
    }

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
        /* `resubmit` on a REJECTED claim is the reopen: the one way back from
           a rejection, through a person, with a reason, on the record. */
        $c = self::claimById($claimId);
        if (!$c) return ['ok' => false, 'error' => 'No such claim.'];
        if ((string) $c['status'] === 'draft') {
            return ['ok' => false, 'error' => 'That one has not been submitted yet.'];
        }
        if ($no = self::selfDealing((int) $c['member_id'], $byUid, 'checks it')) {
            return ['ok' => false, 'code' => 'own_account', 'error' => $no];
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
            /* The cover comes along on the same query. A lookup per slot
               would be 24 queries to draw one shelf. */
            $st = NgvDb::pdo()->prepare('SELECT c.*, b.cover_url, b.ebook_url, b.ebook_kind FROM ngv_book_claims c
                                           LEFT JOIN ngv_books b ON b.id = c.book_id
                                          WHERE c.member_id = ? ORDER BY c.slot');
            $st->execute([$memberId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $row = self::shape($r);
                $row['cover_url'] = (string) ($r['cover_url'] ?? '');
                $row['ebook_url'] = (string) ($r['ebook_url'] ?? '');
                $row['ebook_label'] = self::EBOOK_LABELS[(string) ($r['ebook_kind'] ?? '')] ?? '';
                $by[(int) $r['slot']] = $row;
            }
        } catch (Throwable $e) { }
        $out = [];
        for ($i = 1; $i <= self::TOTAL; $i++) {
            $out[$i] = $by[$i] ?? ['slot' => $i, 'status' => 'empty', 'title' => '', 'cover_url' => '',
                                   'ebook_url' => '', 'ebook_label' => '', 'flags_list' => []];
        }
        return $out;
    }

    public static function sessionCount(int $claimId): int
    {
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare('SELECT COUNT(*) FROM ngv_reading_sessions WHERE claim_id = ?');
            $st->execute([$claimId]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 0; }
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
                /* The participant's name comes with the row. The console used
                   to call NgvMember::participant() per claim — forty queries
                   to put forty names on a page — while the spot queue below
                   had always joined for it. */
                "SELECT c.*, p.name AS member_name, p.email AS member_email
                   FROM ngv_book_claims c
                   LEFT JOIN ngv_participants p ON p.member_id = c.member_id
                  WHERE c.status = 'submitted'
                  ORDER BY (CASE WHEN c.flags <> '' THEN 0 ELSE 1 END), c.submitted_at ASC, c.id ASC LIMIT " . $limit);
            $st->execute();
            return self::nameRows(array_map([self::class, 'shape'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []));
        } catch (Throwable $e) { error_log('[ngvreading] queue: ' . $e->getMessage()); return []; }
    }

    /**
     * Claims that were not accepted, newest first. A rejection closes the
     * claim to the member, so this is the door back: a reviewer who got it
     * wrong, or a member who has made their case, is answered with a
     * `resubmit` from here, recorded like any other verdict.
     */
    public static function rejected(int $limit = 25): array
    {
        self::ensure();
        $limit = max(1, min(200, $limit));
        try {
            $st = NgvDb::pdo()->prepare(
                "SELECT c.*, p.name AS member_name
                   FROM ngv_book_claims c
                   LEFT JOIN ngv_participants p ON p.member_id = c.member_id
                  WHERE c.status = 'rejected' ORDER BY c.reviewed_at DESC, c.id DESC LIMIT " . $limit);
            $st->execute();
            return self::nameRows(array_map([self::class, 'shape'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []));
        } catch (Throwable $e) { error_log('[ngvreading] rejected: ' . $e->getMessage()); return []; }
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
        if ($r['id'] > 0) {
            $r['sessions'] = self::sessions($r['id']);
            $r['session_minutes'] = array_sum(array_map(static fn($x) => (int) $x['minutes'], $r['sessions']));
        } else {
            $r['sessions'] = []; $r['session_minutes'] = 0;
        }
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
     * Open a check if the participant has reached a milestone nobody has asked
     * about yet and does not already have one waiting. Called after a
     * verification, and again after a check is RECORDED — never from anything
     * the participant can trigger.
     *
     * THE MILESTONE IS THE LOWEST ONE STILL UNSERVED, not the one the current
     * count sits on. This used to be `intdiv($n, 6) * 6`, which combined with
     * the one-at-a-time rule below meant a participant who finished all
     * twenty-four books got ONE conversation instead of four: a lead who
     * verifies faster than they talk — which is how the work actually goes —
     * left milestone 6 open while the count went past 12 and 18, and nothing
     * ever came back for them. The checks are the only part of this that
     * reaches somebody who read a summary and wrote well about it, so three
     * of the four quietly not happening was the whole scheme's hole.
     *
     * Taking the lowest unserved one keeps the queue draining in order, and
     * spotRecord() calling this again is what makes the queue drain at all.
     */
    private static function spotMaybeOpen(int $memberId): void
    {
        $n = self::verifiedCount($memberId);
        if ($n < self::SPOT_EVERY) return;

        try {
            $pdo = NgvDb::pdo();
            /* Never two open at once — a track lead facing a backlog of them
               does none of them. */
            $st = $pdo->prepare("SELECT COUNT(*) FROM ngv_book_spot_checks WHERE member_id = ? AND outcome = 'open'");
            $st->execute([$memberId]);
            if ((int) $st->fetchColumn() > 0) return;

            $st = $pdo->prepare('SELECT milestone FROM ngv_book_spot_checks WHERE member_id = ?');
            $st->execute([$memberId]);
            $served = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $milestone = 0;
            for ($m = self::SPOT_EVERY; $m <= $n; $m += self::SPOT_EVERY) {
                if (!in_array($m, $served, true)) { $milestone = $m; break; }
            }
            if ($milestone === 0) return;            // every milestone reached has been asked about

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
            if ($no = self::selfDealing((int) $s['member_id'], $byUid, 'has the conversation and records it')) {
                return ['ok' => false, 'code' => 'own_account', 'error' => $no];
            }

            NgvDb::pdo()->prepare('UPDATE ngv_book_spot_checks SET outcome = ?, asked_by = ?, asked_at = ?, note = ? WHERE id = ?')
                ->execute([$outcome, max(0, $byUid), self::now(), $note, $id]);

            if ($outcome === 'failed' && (int) $s['claim_id'] > 0) {
                self::review((int) $s['claim_id'], 'resubmit', $byUid,
                    'Asked about this one in person: ' . $note);
            }
            self::audit('ngv_spot_check_' . $outcome, (int) $s['member_id'],
                'Spoken check at ' . (int) $s['milestone'] . ' books' . ($note !== '' ? ' — ' . mb_substr($note, 0, 120) : ''));
            /* The queue drains. A milestone passed while this one was open is
               due now — without this, one slow conversation excused every
               check that came after it. */
            self::spotMaybeOpen((int) $s['member_id']);
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
