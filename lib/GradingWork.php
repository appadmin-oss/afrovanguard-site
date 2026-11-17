<?php
/**
 * lib/GradingWork.php — submissions, marking, moderation and release.
 *
 * The life of a piece of work:
 *
 *   submit   a learner hands in text, a link and/or one file, inside the item's
 *            window and within its attempts. After the due date (but inside
 *            the late window) it is marked LATE; after the window it is refused.
 *   mark     each member of the team who marks it records criterion scores and
 *            feedback — one mark per marker per submission (marking again
 *            edits your own mark).
 *   final    one mark stands on its own. Two or more within the item's
 *            moderation threshold (default 15% of the maximum) are averaged.
 *            Two or more further apart than that FLAG the submission, and a
 *            lead or instructor agrees the final score with a reason.
 *            Peer items take the median of their peer reviews instead, unless
 *            an instructor marks them (an override), and a submission still
 *            short of reviews when the grace period ends drops into the
 *            marking queue for an instructor (lib/PeerReview.php).
 *   release  a lead or instructor releases an item's grades. Only then does
 *            the learner see a score: what they see is a SNAPSHOT taken at
 *            release (score, late penalty, feedback), so a marker changing a
 *            mark afterwards changes nothing the learner sees until the next
 *            release.
 *
 * Files live outside the web root (av_private_path), are size-capped and
 * type-checked from their bytes, and are served only through
 * academy/submission-file.php to the learner, the course team, Academy staff
 * and the peers assigned to review them — canSeeFile() is that rule.
 */
declare(strict_types=1);

final class GradingWork
{
    /** Largest file a learner may attach. */
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;
    /** Longest text answer. */
    public const MAX_TEXT = 20000;
    /** A submission waiting this many days unmarked is in the team's daily digest. */
    public const DIGEST_DAYS = 3;

    /** Extension => the types the file's own bytes may be read as. */
    public const FILE_TYPES = [
        'pdf'  => ['application/pdf'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'],
        'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'odt'  => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp'  => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];

    /* ══ Submitting ═══════════════════════════════════════════════════════ */

    /** Whether this learner may hand in (another) attempt now, and if not, why. */
    public static function canSubmit(array $item, int $userId): array
    {
        if ($item['status'] !== 'published' || $item['kind'] === 'quiz') return ['ok' => false, 'error' => 'This item does not take submissions.'];
        if ($item['kind'] === 'live') return ['ok' => false, 'error' => 'A live assessment is scored in its session — book a slot instead.'];
        if (!(new LmsRepository())->isEnrolled($userId, (int) $item['course_id'])) return ['ok' => false, 'error' => 'Join the course to hand in work.'];
        if (CourseTeam::roleOf((int) $item['course_id'], $userId) !== null) return ['ok' => false, 'error' => 'You teach this course, so you cannot submit to it.'];
        $w = Grading::window($item);
        if ($w['closes'] !== null && Grading::now() > $w['closes']) return ['ok' => false, 'code' => 'closed', 'error' => 'Submissions for this item have closed.'];
        $used = self::attemptsUsed((int) $item['id'], $userId);
        if ($used >= max(1, (int) $item['max_attempts'])) {
            return ['ok' => false, 'code' => 'attempts', 'error' => 'You have used all ' . max(1, (int) $item['max_attempts']) . ' attempt' . ((int) $item['max_attempts'] === 1 ? '' : 's') . ' for this item.'];
        }
        return ['ok' => true, 'attempt' => $used + 1, 'late' => $w['due'] !== null && Grading::now() > $w['due']];
    }

    public static function attemptsUsed(int $itemId, int $userId): int
    {
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM grade_submissions WHERE item_id = ? AND user_id = ?');
        $st->execute([$itemId, $userId]);
        return (int) $st->fetchColumn();
    }

    /**
     * Hand in an attempt. $d: text, link. $file: ['name' => original name,
     * 'bytes' => contents] or null.
     * @return array{ok:bool, id?:int, late?:bool, error?:string}
     */
    public static function submit(int $itemId, int $userId, array $d, ?array $file = null): array
    {
        Grading::ensure();
        $item = Grading::item($itemId);
        if (!$item) return ['ok' => false, 'error' => 'No such item.'];
        $can = self::canSubmit($item, $userId);
        if (!$can['ok']) return $can;

        $accepts = $item['accepts_list'];
        $text = trim((string) ($d['text'] ?? ''));
        $link = trim((string) ($d['link'] ?? ''));
        if ($text !== '' && !in_array('text', $accepts, true)) return ['ok' => false, 'error' => 'This item does not take a written answer.'];
        if ($link !== '' && !in_array('link', $accepts, true)) return ['ok' => false, 'error' => 'This item does not take a link.'];
        if ($file !== null && !in_array('file', $accepts, true)) return ['ok' => false, 'error' => 'This item does not take a file.'];
        if (mb_strlen($text) > self::MAX_TEXT) return ['ok' => false, 'error' => 'A written answer is at most ' . number_format(self::MAX_TEXT) . ' characters.'];
        if ($link !== '' && (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $link) || strlen($link) > 500)) {
            return ['ok' => false, 'error' => 'The link must be a full web address starting with https://.'];
        }
        $stored = null;
        if ($file !== null) {
            $chk = self::checkFile((string) ($file['name'] ?? ''), (string) ($file['bytes'] ?? ''));
            if (!$chk['ok']) return $chk;
            $stored = $chk;
        }
        if ($text === '' && $link === '' && $stored === null) return ['ok' => false, 'error' => 'Add your answer, a link or a file before submitting.'];

        if ($stored !== null) {
            $name = 'sub-' . bin2hex(random_bytes(12)) . '.' . $stored['ext'];
            $path = self::storeDir() . '/' . $name;
            if (@file_put_contents($path, $stored['bytes']) === false) return ['ok' => false, 'error' => 'The file could not be stored. Try again.'];
            @chmod($path, 0600);
            $stored['store'] = $name;
        }
        $db = Database::pdo();
        try {
            $db->prepare('INSERT INTO grade_submissions (item_id, course_id, user_id, attempt, body, link, file_name, file_store, file_mime, file_size, submitted_at, is_late)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([$itemId, (int) $item['course_id'], $userId, (int) $can['attempt'], $text, $link,
                          $stored['name'] ?? '', $stored['store'] ?? '', $stored['mime'] ?? '', $stored['size'] ?? 0,
                          Grading::stamp(), $can['late'] ? 1 : 0]);
        } catch (Throwable $e) {
            if ($stored !== null) @unlink(self::storeDir() . '/' . $stored['store']);
            return ['ok' => false, 'error' => 'That attempt was already handed in.'];
        }
        $id = (int) $db->lastInsertId();
        if ($item['kind'] === 'peer') PeerReview::assign($itemId, $userId);
        return ['ok' => true, 'id' => $id, 'late' => (bool) $can['late'], 'attempt' => (int) $can['attempt']];
    }

    /** Validate an uploaded file by size, extension and the type its bytes actually are. */
    public static function checkFile(string $name, string $bytes): array
    {
        if ($bytes === '') return ['ok' => false, 'error' => 'The file is empty.'];
        if (strlen($bytes) > self::MAX_FILE_BYTES) return ['ok' => false, 'error' => 'Files are at most ' . (self::MAX_FILE_BYTES / 1048576) . ' MB.'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::FILE_TYPES[$ext])) return ['ok' => false, 'error' => 'Attach an image, a PDF, an office document or a zip.'];
        $mime = function_exists('finfo_open') ? (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) : '';
        if (!in_array($mime, self::FILE_TYPES[$ext], true)) return ['ok' => false, 'error' => 'That file is not really a .' . $ext . '.'];
        $clean = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($name)) ?: ('file.' . $ext);
        return ['ok' => true, 'ext' => $ext, 'mime' => $mime, 'size' => strlen($bytes), 'name' => mb_substr($clean, 0, 191), 'bytes' => $bytes];
    }

    /** Where submitted files live: beside the other private files, never in the web root. */
    public static function storeDir(): string
    {
        $dir = dirname(av_private_path('academy-submissions.keep')) . '/academy-submissions';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        return $dir;
    }

    public static function submission(int $id): ?array
    {
        Grading::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM grade_submissions WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** May this user download (or read) this submission? The learner, the team, staff, and assigned peer reviewers. */
    public static function canSee(array $sub, int $userId): bool
    {
        if ($userId <= 0) return false;
        if ((int) $sub['user_id'] === $userId) return true;
        if (CourseTeam::can((int) $sub['course_id'], $userId, 'mark')) return true;
        $st = Database::pdo()->prepare('SELECT 1 FROM peer_reviews WHERE submission_id = ? AND reviewer_id = ?');
        $st->execute([(int) $sub['id'], $userId]);
        return (bool) $st->fetchColumn();
    }

    /** The stored file's path, if the submission has one and it is still there. */
    public static function filePath(array $sub): ?string
    {
        $store = basename((string) $sub['file_store']);
        if ($store === '') return null;
        $p = self::storeDir() . '/' . $store;
        return is_file($p) ? $p : null;
    }

    /* ══ Marking ══════════════════════════════════════════════════════════ */

    /** Marks recorded against a submission, earliest first. */
    public static function marks(int $subId): array
    {
        $st = Database::pdo()->prepare('SELECT m.*, u.name AS marker_name FROM grade_marks m LEFT JOIN lms_users u ON u.id = m.marker_id WHERE m.submission_id = ? ORDER BY m.id');
        $st->execute([$subId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Record (or revise) this marker's mark. One mark per marker per submission.
     * @param array<int,float|string> $scores criterion index => points
     */
    public static function mark(int $subId, int $markerId, array $scores, string $feedback): array
    {
        $sub = self::submission($subId);
        if (!$sub) return ['ok' => false, 'error' => 'No such submission.'];
        $item = Grading::item((int) $sub['item_id']);
        if (!$item || $item['kind'] === 'quiz') return ['ok' => false, 'error' => 'This item is not marked by hand.'];
        if (!CourseTeam::can((int) $sub['course_id'], $markerId, 'mark')) return ['ok' => false, 'error' => 'Only the course team can mark.'];
        if ((int) $sub['user_id'] === $markerId) return ['ok' => false, 'error' => 'You cannot mark your own work.'];
        $sc = Grading::scoreRubric($item['rubric'], array_values($scores));
        if (!$sc['ok']) return $sc;
        $feedback = mb_substr(trim($feedback), 0, 10000);
        $db = Database::pdo();
        $now = Grading::stamp();
        $json = json_encode($sc['scores']);
        /* Saving the same mark twice — a retry on a flaky connection, a double
           tap — is a no-op: no second row (the key is submission + marker), no
           second audit entry, nothing recomputed. */
        $ex = $db->prepare('SELECT scores_json, feedback FROM grade_marks WHERE submission_id = ? AND marker_id = ?');
        $ex->execute([$subId, $markerId]);
        $prev = $ex->fetch(PDO::FETCH_ASSOC);
        if ($prev && (string) $prev['scores_json'] === $json && (string) $prev['feedback'] === $feedback) {
            $s = self::submission($subId);
            return ['ok' => true, 'unchanged' => true, 'final' => $s['final_pct'] === null ? null : (float) $s['final_pct'], 'flagged' => (bool) $s['flagged']];
        }
        $up = $db->prepare('UPDATE grade_marks SET scores_json = ?, points = ?, max_points = ?, feedback = ?, updated_at = ? WHERE submission_id = ? AND marker_id = ?');
        $up->execute([$json, $sc['points'], $sc['max'], $feedback, $now, $subId, $markerId]);
        if ($up->rowCount() === 0) {
            $db->prepare(Database::insertIgnore('grade_marks', ['submission_id', 'marker_id', 'scores_json', 'points', 'max_points', 'feedback', 'created_at', 'updated_at']))
               ->execute([$subId, $markerId, $json, $sc['points'], $sc['max'], $feedback, $now, $now]);
        }
        Grading::audit('grading.mark', (int) $sub['course_id'], 'submission#' . $subId,
            Grading::num($sc['points']) . '/' . Grading::num($sc['max']) . ' on item#' . $item['id'], $markerId);
        $state = self::recompute($subId);
        return ['ok' => true] + $state;
    }

    /**
     * Work out a submission's final score from its marks (and, for a peer item,
     * its reviews). The moderation rule lives here.
     */
    public static function recompute(int $subId): array
    {
        $sub = self::submission($subId);
        if (!$sub) return ['final' => null, 'flagged' => false];
        $item = Grading::item((int) $sub['item_id']);
        $marks = self::marks($subId);
        $pcts = [];
        foreach ($marks as $m) { if ((float) $m['max_points'] > 0) $pcts[] = (float) $m['points'] / (float) $m['max_points'] * 100; }

        $final = null; $source = ''; $flagged = 0;
        if ((string) $sub['final_source'] === 'moderated') {
            // A score agreed with a reason stands until it is agreed again.
            return ['final' => (float) $sub['final_pct'], 'flagged' => false, 'source' => 'moderated'];
        }
        if ($pcts === [] && $item && $item['kind'] === 'peer') {
            $peer = PeerReview::doneScores($subId);
            if (count($peer) >= max(1, (int) $item['peer_count'])) { $final = self::median($peer); $source = 'peer'; }
        } elseif (count($pcts) === 1) {
            $final = $pcts[0]; $source = 'single';
        } elseif (count($pcts) > 1) {
            $threshold = $item ? (int) $item['moderation_pct'] : Grading::DEFAULT_MODERATION;
            if (max($pcts) - min($pcts) > $threshold) { $flagged = 1; }
            else { $final = array_sum($pcts) / count($pcts); $source = 'average'; }
        }
        if ($final !== null) $final = round($final, 2);
        Database::pdo()->prepare('UPDATE grade_submissions SET final_pct = ?, final_source = ?, flagged = ?, final_at = ? WHERE id = ?')
            ->execute([$final, $source, $flagged, $final === null ? '' : Grading::stamp(), $subId]);
        return ['final' => $final, 'flagged' => (bool) $flagged, 'source' => $source];
    }

    public static function median(array $v): float
    {
        sort($v);
        $n = count($v);
        if ($n === 0) return 0.0;
        $mid = intdiv($n, 2);
        return $n % 2 ? (float) $v[$mid] : ((float) $v[$mid - 1] + (float) $v[$mid]) / 2;
    }

    /**
     * A lead or instructor agrees the final score (in rubric points) with a
     * reason — for a flagged submission, or to settle any other.
     */
    public static function agree(int $subId, int $by, $points, string $reason): array
    {
        $sub = self::submission($subId);
        if (!$sub) return ['ok' => false, 'error' => 'No such submission.'];
        if (!CourseTeam::can((int) $sub['course_id'], $by, 'moderate')) return ['ok' => false, 'error' => 'Only a lead or instructor can agree a final score.'];
        $item = Grading::item((int) $sub['item_id']);
        $max = (float) ($item['rubric_max'] ?? 0);
        $reason = trim($reason);
        if ($reason === '') return ['ok' => false, 'error' => 'Say why this is the agreed score — it is recorded.'];
        if (!is_numeric($points) || (float) $points < 0 || (float) $points > $max || $max <= 0) {
            return ['ok' => false, 'error' => 'The agreed score is between 0 and ' . Grading::num($max) . '.'];
        }
        $pct = round((float) $points / $max * 100, 2);
        Database::pdo()->prepare("UPDATE grade_submissions SET final_pct = ?, final_source = 'moderated', final_reason = ?, final_by = ?, final_at = ?, flagged = 0 WHERE id = ?")
            ->execute([$pct, mb_substr($reason, 0, 2000), max(0, $by), Grading::stamp(), $subId]);
        Grading::audit('grading.agree', (int) $sub['course_id'], 'submission#' . $subId, Grading::num((float) $points) . '/' . Grading::num($max) . ' — ' . $reason, $by);
        return ['ok' => true, 'final' => $pct];
    }

    /* ══ Release ══════════════════════════════════════════════════════════ */

    /** What a learner reads as feedback: each marker's (unnamed), or each peer's. */
    private static function feedbackFor(array $sub): string
    {
        $parts = [];
        if ((string) $sub['final_source'] === 'peer') {
            foreach (PeerReview::doneReviews((int) $sub['id']) as $n => $r) {
                $f = trim((string) ($r['feedback'] ?? ''));
                if ($f !== '') $parts[] = 'Peer reviewer ' . ($n + 1) . ': ' . $f;
            }
        } else {
            foreach (self::marks((int) $sub['id']) as $m) {
                $f = trim((string) ($m['feedback'] ?? ''));
                if ($f !== '') $parts[] = $f;
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * Release an item's grades: every submission with an agreed final score
     * that the learner has not yet seen (or has seen an older version of) is
     * snapshotted and the learner told. Flagged submissions wait.
     */
    public static function release(int $itemId, int $by): array
    {
        $item = Grading::item($itemId);
        if (!$item) return ['ok' => false, 'error' => 'No such item.'];
        $courseId = (int) $item['course_id'];
        if (!CourseTeam::can($courseId, $by, 'release')) return ['ok' => false, 'error' => 'Only a lead or instructor can release grades.'];
        if ($item['kind'] === 'quiz') return ['ok' => false, 'error' => 'Quiz scores count as soon as the quiz is taken.'];
        $db = Database::pdo();
        $st = $db->prepare('SELECT * FROM grade_submissions WHERE item_id = ? ORDER BY id');
        $st->execute([$itemId]);
        $released = 0; $held = 0; $waiting = 0; $now = Grading::stamp();
        $up = $db->prepare('UPDATE grade_submissions SET released_raw = ?, released_pct = ?, released_penalty = ?, released_feedback = ?, released_at = ?, released_by = ? WHERE id = ?');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            if ((int) $s['flagged']) { $held++; continue; }
            if ($s['final_pct'] === null) { $waiting++; continue; }
            $raw = round((float) $s['final_pct'], 2);
            $pen = (int) $s['is_late'] ? (int) $item['late_penalty'] : 0;
            $pct = round($raw * (100 - $pen) / 100, 2);
            $fb = self::feedbackFor($s);
            $same = (string) $s['released_at'] !== '' && abs((float) $s['released_raw'] - $raw) < 0.005
                 && (int) $s['released_penalty'] === $pen && (string) ($s['released_feedback'] ?? '') === $fb;
            if ($same) continue;
            $first = (string) $s['released_at'] === '';
            $up->execute([$raw, $pct, $pen, $fb, $now, max(0, $by), (int) $s['id']]);
            $released++;
            self::notifyReleased($item, $s, $pct, $pen, $first);
            // A passed course earns its certificate the moment the last grade lands.
            try {
                $lms = new LmsRepository();
                $had = (bool) $lms->getCertificate((int) $s['user_id'], $courseId);
                $cert = $lms->issueCertificate((int) $s['user_id'], $courseId);
                if ($cert && !$had) self::notifyCertificate((int) $s['user_id'], $courseId);
            } catch (Throwable $e) { error_log('[grading] certificate on release: ' . $e->getMessage()); }
        }
        Grading::audit('grading.release', $courseId, 'item#' . $itemId, $released . ' released, ' . $held . ' held for moderation, ' . $waiting . ' unmarked', $by);
        return ['ok' => true, 'released' => $released, 'held' => $held, 'waiting' => $waiting];
    }

    /* ══ The marking queue ════════════════════════════════════════════════ */

    /**
     * A course's marking queue, oldest first. Only each learner's latest
     * attempt on each item is listed (an earlier attempt superseded by a newer
     * one needs no mark). $state: 'unmarked' (needs a mark: no final yet — for
     * a peer item, only once its grace period has ended short of reviews),
     * 'moderation' (flagged), 'unreleased' (final but not seen), or 'all'.
     */
    public static function queue(int $courseId, string $state = 'unmarked', int $itemId = 0, int $forMarker = 0): array
    {
        Grading::ensure();
        $items = [];
        foreach (Grading::items($courseId, 'all') as $i) if ($i['kind'] !== 'quiz' && $i['status'] !== 'draft') $items[(int) $i['id']] = $i;
        if (!$items) return [];
        $ids = $itemId > 0 ? (isset($items[$itemId]) ? [$itemId] : []) : array_keys($items);
        if (!$ids) return [];
        $in = implode(',', array_map('intval', $ids));
        $rows = Database::pdo()->query(
            "SELECT s.*, u.name AS learner_name, u.email AS learner_email,
                    (SELECT COUNT(*) FROM grade_marks m WHERE m.submission_id = s.id) AS mark_count,
                    (SELECT COUNT(*) FROM peer_reviews r WHERE r.submission_id = s.id AND r.done_at <> '') AS reviews_done
               FROM grade_submissions s JOIN lms_users u ON u.id = s.user_id
              WHERE s.item_id IN ($in)
                AND s.attempt = (SELECT MAX(s2.attempt) FROM grade_submissions s2 WHERE s2.item_id = s.item_id AND s2.user_id = s.user_id)
              ORDER BY s.submitted_at, s.id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $mine = [];
        if ($forMarker > 0) {
            $st = Database::pdo()->prepare('SELECT submission_id FROM grade_marks WHERE marker_id = ?');
            $st->execute([$forMarker]);
            $mine = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        }
        $out = [];
        foreach ($rows as $r) {
            $item = $items[(int) $r['item_id']];
            $r['item'] = $item;
            $r['needs_instructor'] = self::needsInstructor($item, $r);
            $r['marked_by_me'] = isset($mine[(int) $r['id']]);
            $r['state'] = (int) $r['flagged'] ? 'moderation'
                : ($r['final_pct'] === null ? 'unmarked' : ((string) $r['released_at'] === '' ? 'unreleased' : 'released'));
            $keep = match ($state) {
                'moderation' => $r['state'] === 'moderation',
                'unreleased' => $r['state'] === 'unreleased',
                'unmarked'   => $r['state'] === 'unmarked' && $r['needs_instructor'],
                default      => true,
            };
            if ($keep) $out[] = $r;
        }
        return $out;
    }

    /** Whether a submission without a final score is waiting on the TEAM (not on peers). */
    public static function needsInstructor(array $item, array $sub): bool
    {
        if ($sub['final_pct'] !== null) return false;
        if ($item['kind'] !== 'peer') return true;
        $w = Grading::window($item);
        $done = (int) ($sub['reviews_done'] ?? count(PeerReview::doneScores((int) $sub['id'])));
        return $w['grace_ends'] !== null && Grading::now() > $w['grace_ends'] && $done < (int) $item['peer_count'];
    }

    /* ══ Notices ══════════════════════════════════════════════════════════ */

    private static function userRow(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT id, name, email FROM lms_users WHERE id = ?'); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function courseRow(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT id, slug, title FROM courses WHERE id = ?'); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function first(string $name): string { return explode(' ', trim($name))[0] ?: 'there'; }

    /** Best effort: a released grade (or new feedback on one) is mailed to the learner. */
    private static function notifyReleased(array $item, array $sub, float $pct, int $pen, bool $first): void
    {
        try {
            $u = self::userRow((int) $sub['user_id']); $c = self::courseRow((int) $item['course_id']);
            if (!$u || !$c || !class_exists('Mailer')) return;
            $url = academy_url($c['slug'] . '/grades/?item=' . (int) $item['id']);
            $subject = ($first ? 'Your grade for ' : 'Updated grade and feedback for ') . $item['title'];
            $rows = [
                'Hi ' . htmlspecialchars(self::first((string) $u['name']), ENT_QUOTES, 'UTF-8') . ',',
                ($first ? 'Your grade' : 'An updated grade') . ' for <strong>' . htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8') . '</strong> in '
                    . htmlspecialchars((string) $c['title'], ENT_QUOTES, 'UTF-8') . ' has been released: <strong>' . Grading::num($pct) . '%</strong>'
                    . ($pen ? ' (after a ' . $pen . '% late penalty)' : '') . '.',
                'Your feedback is on your grades page.',
            ];
            Mailer::send((string) $u['email'], $subject, Mailer::shell($subject, $rows, ['url' => $url, 'text' => 'See your grade and feedback'], 'Grade released', ['eyebrow' => 'Afrovanguard Academy']));
        } catch (Throwable $e) { error_log('[grading] release notice: ' . $e->getMessage()); }
    }

    private static function notifyCertificate(int $userId, int $courseId): void
    {
        try {
            $u = (new LmsRepository())->memberById($userId); $c = self::courseRow($courseId);
            if ($u && $c && class_exists('Notify')) Notify::completed($u, $c, academy_url($c['slug'] . '/certificate'));
        } catch (Throwable $e) { error_log('[grading] certificate notice: ' . $e->getMessage()); }
    }

    /**
     * The daily digest: for every course with work waiting more than
     * DIGEST_DAYS unmarked (or held for moderation), one email to each member
     * of its team. Claimed per course per day in grading_digests BEFORE
     * sending, so a cron that runs every five minutes still sends one.
     */
    public static function digest(): int
    {
        Grading::ensure();
        $db = Database::pdo();
        $day = (new DateTime('@' . Grading::now()))->setTimezone(new DateTimeZone(defined('AV_TZ') ? (string) AV_TZ : 'UTC'))->format('Y-m-d');
        $cut = Grading::stamp(Grading::now() - self::DIGEST_DAYS * 86400);
        $courses = $db->prepare("SELECT DISTINCT course_id FROM grade_submissions WHERE final_pct IS NULL AND submitted_at <> '' AND submitted_at < ?");
        $courses->execute([$cut]);
        $sent = 0;
        foreach (array_map('intval', $courses->fetchAll(PDO::FETCH_COLUMN)) as $cid) {
            $waiting = array_values(array_filter(self::queue($cid, 'all'), static fn($r) =>
                ($r['state'] === 'moderation' || ($r['state'] === 'unmarked' && $r['needs_instructor'])) && (string) $r['submitted_at'] < $cut));
            if (!$waiting) continue;
            $claim = $db->prepare(Database::insertIgnore('grading_digests', ['course_id', 'digest_day', 'sent_at', 'recipients']));
            $claim->execute([$cid, $day, Grading::stamp(), 0]);
            if ($claim->rowCount() !== 1) continue;   // already sent today
            $c = self::courseRow($cid);
            if (!$c) continue;
            $n = 0;
            foreach (CourseTeam::members($cid) as $m) {
                try {
                    $subject = count($waiting) . ' submission' . (count($waiting) === 1 ? '' : 's') . ' waiting in ' . $c['title'];
                    $list = implode('<br>', array_map(static fn($r) => htmlspecialchars((string) $r['item']['title'], ENT_QUOTES, 'UTF-8')
                        . ' · submitted ' . Grading::toLocal((string) $r['submitted_at'], 'j M') . ($r['state'] === 'moderation' ? ' · needs moderation' : ''), array_slice($waiting, 0, 20)));
                    $ok = Mailer::send((string) $m['email'], $subject, Mailer::shell($subject, [
                        'Hi ' . htmlspecialchars(self::first((string) $m['name']), ENT_QUOTES, 'UTF-8') . ',',
                        'These have been waiting more than ' . self::DIGEST_DAYS . ' days:', $list,
                    ], ['url' => academy_url('teach/' . $c['slug'] . '/?tab=marking'), 'text' => 'Open the marking queue'], 'Marking waiting', ['eyebrow' => 'Afrovanguard Academy']));
                    if ($ok) $n++;
                } catch (Throwable $e) { error_log('[grading] digest: ' . $e->getMessage()); }
            }
            $db->prepare('UPDATE grading_digests SET recipients = ? WHERE course_id = ? AND digest_day = ?')->execute([$n, $cid, $day]);
            $sent++;
        }
        return $sent;
    }

    /** One tick for tasks/cron.php: the team's daily digest and the learners' live-session reminders. Both send once. */
    public static function cronTick(): array
    {
        return ['digests' => self::digest(), 'live_reminders' => LiveAssess::remind()];
    }
}
