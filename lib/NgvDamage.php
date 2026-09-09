<?php
/**
 * lib/NgvDamage.php — damage to equipment or premises, from report to outcome,
 * with the participant told at every step.
 *
 * ── WHY THIS IS NOT JUST A FINE ──────────────────────────────────────────────
 * The ledger already has a fine with reason `equipment`, and until this existed
 * that was the only way to record damage. It collapses three separate facts into
 * one row:
 *
 *   what happened, and when      a laptop screen cracked on 3 September
 *   what it turned out to cost   ₦45,000, from a repairer, eight days later
 *   what is being asked for      ₦15,000, agreed with them, or nothing at all
 *
 * Those are different numbers and they arrive days apart. A fine posted on the
 * day has to guess the cost; a fine posted when the quote lands loses the date
 * it happened; and a single `charged` figure cannot say that the programme
 * decided to carry two thirds of it. So the INCIDENT is recorded first and costs
 * nothing, and a charge — if there is ever one — is a later, separate, audited
 * decision that this record points at.
 *
 * ── THE STATUS IS THE PRODUCT ────────────────────────────────────────────────
 *   reported    it happened. Nothing is charged. Nothing is even priced.
 *   assessing   somebody is finding out what it costs.
 *   charged     an amount is on their account, and they can see which entry.
 *   waived      assessed, and the programme is not asking for it.
 *   closed      nothing owed — wear and tear, not their fault, already fixed.
 *
 * Each move emails the participant. That is the whole point of the feature, and
 * the first email matters most: the natural fear on hearing "we have recorded
 * damage to a laptop" is a bill, so the `reported` email says in as many words
 * that nothing has been charged, that the cost is not yet known, and that they
 * will be told before anything is. A process nobody can see is
 * indistinguishable from a threat.
 *
 * Only real transitions send. Re-saving the same status does not re-email, and
 * `notify` turns it off per record for the case where the conversation is
 * already happening face to face.
 *
 * ── SELF-REPORTING IS TO THEIR CREDIT ────────────────────────────────────────
 * A participant can report damage themselves, and the record says so. This is a
 * leadership programme: somebody who breaks something and says so has done the
 * thing the programme is trying to teach, and a system where the only path is
 * "staff notice" quietly teaches the opposite. Staff see the flag, and it is
 * shown as a credit rather than a confession.
 *
 * ── WHAT DELIBERATELY DOES NOT HAPPEN ────────────────────────────────────────
 * Recording damage charges nothing, ever. Nothing here can post a charge without
 * a staff member choosing an amount, and nothing here can make a charge vanish —
 * a fine raised for damage is voided or waived through the ledger, under its own
 * name and reason, like every other charge.
 */
declare(strict_types=1);

final class NgvDamage
{
    /** Where a record can be. See the header: each move emails the participant. */
    public const STATUSES = ['reported', 'assessing', 'charged', 'waived', 'closed'];
    /** The ones still needing somebody. */
    public const OPEN_STATUSES = ['reported', 'assessing'];

    /** How bad, in the three words staff actually use. Drives triage and the
     *  wording of the email, nothing financial. */
    public const SEVERITIES = [
        'minor' => 'Minor — still usable',
        'major' => 'Major — needs repair',
        'lost'  => 'Lost or beyond repair',
    ];

    public const ITEM_MAX = 120;
    public const TEXT_MAX = 1200;
    /** Photos per record, and the ceiling on one file. Three is enough to show a
     *  crack from two angles and the serial number; more is somebody emptying a
     *  camera roll into a shared-hosting account with an inode limit. */
    public const PHOTOS_MAX = 3;
    public const PHOTO_BYTES_MAX = 6 * 1024 * 1024;
    public const PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic'];
    /** An assessment that has sat unpriced this long is a stalled process, and
     *  the person waiting on it has no way to chase. The cron says so. */
    public const STALE_DAYS = 14;
    public const PAGE = 200;

    /* ══ Recording ══════════════════════════════════════════════════════════ */

    /**
     * Record an incident. Charges nothing.
     *
     * `$byUid` of 0 with `$selfReport` true is the participant reporting their
     * own — see the header. Staff-reported records carry the reporter's id so
     * "who wrote this down" is answerable, which is the first question asked
     * when somebody disputes the description.
     */
    public static function report(int $memberId, array $in, int $byUid, bool $selfReport = false): array
    {
        if (!self::participant($memberId)) return ['ok' => false, 'error' => 'No such participant.'];
        $item = trim((string) ($in['item'] ?? ''));
        if ($item === '') return ['ok' => false, 'error' => 'Say what was damaged.'];
        $desc = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($desc) < 10) return ['ok' => false, 'error' => 'A sentence or two about what happened, please.'];
        $when = self::validDate((string) ($in['occurred_on'] ?? ''));
        if ($when === '') $when = self::today();
        /* A date in the future is a typo, not an incident. Clamped rather than
           refused, so a slip does not lose the report somebody just typed. */
        if ($when > self::today()) $when = self::today();
        $severity = isset(self::SEVERITIES[(string) ($in['severity'] ?? '')]) ? (string) $in['severity'] : 'minor';
        $now = NgvDb::nowExpr();
        NgvDb::pdo()->prepare(
            "INSERT INTO ngv_damages (member_id, item, occurred_on, place, severity, description, estimate,
                                      status, self_report, reported_by, notify, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?, 'reported', ?, ?, ?, {$now}, {$now})"
        )->execute([
            $memberId, mb_substr($item, 0, self::ITEM_MAX), $when,
            mb_substr(trim((string) ($in['place'] ?? '')), 0, 80), $severity,
            mb_substr($desc, 0, self::TEXT_MAX),
            NgvLedger::money($in['estimate'] ?? 0),
            $selfReport ? 1 : 0, max(0, $byUid),
            empty($in['notify']) && array_key_exists('notify', $in) ? 0 : 1,
        ]);
        $id = (int) NgvDb::pdo()->lastInsertId();
        self::audit('ngv_damage_report', $memberId,
            ($selfReport ? 'Self-reported' : 'Recorded') . ' damage — ' . $item
            . ' (' . $severity . ') on ' . $when);
        self::notify($id, 'reported');
        /* Only when the PARTICIPANT reported it — staff recording damage already
           know about it, and paging the people who just typed it in is how an
           alert becomes something everybody filters.
           And only when nothing of theirs is ALREADY waiting. Without that
           second condition one member can mail every admin once per report:
           the dashboard allows sixty writes in ten minutes, which is a hundred
           and twenty messages and a shared host's daily mail quota gone. Staff
           who already have an unactioned report from this person learn nothing
           from a second alert, so the honest rule is one until the queue is
           cleared. */
        if ($selfReport && class_exists('NgvLedger') && self::openCountFor($memberId) <= 1) {
            $who = trim((string) (self::participant($memberId)['name'] ?? '')) ?: ('member #' . $memberId);
            NgvLedger::notifyStaff(
                'NGV: ' . $who . ' has reported damage',
                $who . ' has reported damage themselves — ' . $item . ' (' . $severity . '), ' . $when . '.',
                '/academy/ngv/members.php?m=' . $memberId . '#damage');
        }
        return ['ok' => true, 'id' => $id, 'status' => 'reported'];
    }

    /**
     * Attach photos to a record.
     *
     * Separate from `report()` because the upload is a different kind of failure
     * from the text: a phone on a bad connection drops a 4MB JPEG far more often
     * than it drops a sentence, and losing the whole report because the picture
     * did not arrive would teach people not to bother reporting. The record is
     * saved first, and pictures are added to it.
     *
     * Takes the raw `$_FILES` shape. Everything about the file is checked HERE
     * rather than trusted from the browser: the MIME comes from the file's own
     * bytes via Storage::mime(), not from the upload's claimed type, because the
     * claimed type is attacker-supplied and a .php named .jpg would otherwise
     * land in a web-served directory.
     */
    public static function addPhotos(int $id, array $files, int $byUid): array
    {
        $d = self::get($id);
        if (!$d) return ['ok' => false, 'error' => 'No such damage record.'];
        if (!class_exists('Storage')) return ['ok' => false, 'error' => 'File storage is not available on this installation.'];
        $have = self::photosOf($d);
        $room = self::PHOTOS_MAX - count($have);
        if ($room <= 0) return ['ok' => false, 'error' => 'That record already has ' . self::PHOTOS_MAX . ' photos.'];

        /* Normalise the two shapes PHP produces — one file, and the [name][i]
           column layout for multiple — so the loop below sees one list either way. */
        $items = [];
        if (isset($files['tmp_name']) && is_array($files['tmp_name'])) {
            foreach ($files['tmp_name'] as $i => $tmp) {
                $items[] = ['tmp_name' => $tmp, 'name' => (string) ($files['name'][$i] ?? ''),
                            'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                            'size' => (int) ($files['size'][$i] ?? 0)];
            }
        } elseif (isset($files['tmp_name'])) {
            $items[] = ['tmp_name' => (string) $files['tmp_name'], 'name' => (string) ($files['name'] ?? ''),
                        'error' => (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE), 'size' => (int) ($files['size'] ?? 0)];
        }
        if (!$items) return ['ok' => false, 'error' => 'No file arrived. It may have been too large for the server.'];

        $added = 0; $errors = [];
        foreach (array_slice($items, 0, $room) as $f) {
            if ($f['error'] === UPLOAD_ERR_NO_FILE) continue;
            if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
                $errors[] = 'One photo was larger than this server accepts.'; continue;
            }
            if ($f['error'] !== UPLOAD_ERR_OK) { $errors[] = 'One photo did not upload.'; continue; }
            if ($f['size'] > self::PHOTO_BYTES_MAX) {
                $errors[] = 'One photo was over ' . (int) (self::PHOTO_BYTES_MAX / 1048576) . 'MB.'; continue;
            }
            /* is_uploaded_file, so a path cannot be smuggled in as a filename and
               read off the server's disk. */
            if (!is_uploaded_file($f['tmp_name']) && PHP_SAPI !== 'cli') { $errors[] = 'That file was not an upload.'; continue; }
            $mime = Storage::mime($f['tmp_name']);
            if (!isset(self::PHOTO_TYPES[$mime])) { $errors[] = 'Only photos can be attached (that one was ' . $mime . ').'; continue; }
            try {
                $put = Storage::put($f['tmp_name'], $f['name'] ?: ('damage.' . self::PHOTO_TYPES[$mime]), 'image', 'ngv-damage');
                $url = (string) ($put['url'] ?? '');
                if ($url === '') { $errors[] = 'One photo could not be stored.'; continue; }
                $have[] = $url; $added++;
            } catch (Throwable $e) {
                error_log('[ngvdamage] photo: ' . $e->getMessage());
                $errors[] = 'One photo could not be stored.';
            }
        }
        if ($added > 0) {
            NgvDb::pdo()->prepare('UPDATE ngv_damages SET photos = ?, updated_at = ' . NgvDb::nowExpr() . ' WHERE id = ?')
                ->execute([json_encode(array_values($have), JSON_UNESCAPED_SLASHES), $id]);
            self::audit('ngv_damage_photo', (int) $d['member_id'],
                $added . ' photo(s) attached to ' . $d['item']);
        }
        if ($added === 0) return ['ok' => false, 'error' => $errors ? implode(' ', array_unique($errors)) : 'Nothing was attached.'];
        return ['ok' => true, 'added' => $added, 'photos' => $have,
                'warning' => $errors ? implode(' ', array_unique($errors)) : ''];
    }

    /**
     * The stored URLs, tolerant of an empty or malformed column.
     *
     * Takes either a RAW row (photos is the JSON string) or a SHAPED one (photos
     * is already a list), because both are in circulation — `get()` returns the
     * shaped form and `addPhotos()` reads it back. Handling only the raw form
     * silently returned nothing for every shaped caller, which made the
     * three-photo cap look like a one-photo overwrite.
     */
    public static function photosOf(array $d): array
    {
        $v = $d['photosRaw'] ?? $d['photos'] ?? '';
        $list = is_array($v) ? $v : (trim((string) $v) === '' ? [] : json_decode((string) $v, true));
        if (!is_array($list)) return [];
        $out = [];
        foreach ($list as $u) { if (is_string($u) && $u !== '') $out[] = $u; }
        return array_slice($out, 0, self::PHOTOS_MAX);
    }

    /**
     * Move a record on, and tell the participant.
     *
     * The amounts are separate arguments rather than one, because `assessed` and
     * `charged` are different facts: what it cost, and what this person is being
     * asked for. A programme that bills a nineteen-year-old the full retail price
     * of a laptop screen has made a decision, and the decision should have to be
     * typed rather than defaulted.
     *
     * `charged` posts a fine through the LEDGER, with reason `equipment`, and
     * stores the entry id so the record and the charge point at each other. It
     * refuses to post a second one: the money moves once.
     */
    public static function advance(int $id, string $status, array $in, int $byUid): array
    {
        $d = self::get($id);
        if (!$d) return ['ok' => false, 'error' => 'No such damage record.'];
        if (!in_array($status, self::STATUSES, true)) return ['ok' => false, 'error' => 'Unknown status.'];
        if ($status === 'reported') return ['ok' => false, 'error' => 'A record cannot go back to just-reported.'];
        if ($d['status'] === $status && $status !== 'assessing') {
            return ['ok' => false, 'error' => 'It is already ' . $status . '.'];
        }
        $set = ['status = ?', 'handled_by = ?', 'updated_at = ' . NgvDb::nowExpr()];
        $args = [$status, max(0, $byUid)];
        $entryId = (int) $d['entry_id'];
        $charged = (int) $d['charged'];

        /* The assessment. Recorded whenever it is known, on any transition, so a
           record closed with no charge still says what it would have cost — which
           is the number a programme needs to know what it absorbs. */
        if (array_key_exists('assessed', $in) && $in['assessed'] !== '') {
            array_splice($set, 1, 0, ['assessed = ?']);
            array_splice($args, 1, 0, [NgvLedger::money($in['assessed'])]);
        }
        $outcome = trim((string) ($in['outcome'] ?? ''));

        if ($status === 'charged') {
            if ($entryId > 0) return ['ok' => false, 'error' => 'A charge has already been raised for this.'];
            /* Deliberately NOT defaulted to the assessed cost. Billing somebody
               the full retail price of a laptop screen is a decision, and a
               decision that happens by leaving a box empty is one nobody made.
               The figure has to be typed, even when it is the same figure. */
            $amount = NgvLedger::money($in['charged'] ?? 0);
            if ($amount <= 0) {
                return ['ok' => false, 'error' => 'Enter what they are being asked to pay — it is not assumed to be the full cost.'];
            }
            /* Through the ledger, as a fine with reason `equipment`. Not a
               bespoke charge kind: this IS a fine, it shows up in the fines line
               on their account, and it is voided and waived by the same paths as
               every other charge. */
            $note = $d['item'] . ($outcome !== '' ? ' — ' . $outcome : '');
            $r = NgvLedger::charge((int) $d['member_id'], 'fine', $amount, 'equipment', $note, $byUid);
            if (empty($r['ok'])) return $r;
            $entryId = (int) $r['entryId'];
            $charged = $amount;
            array_push($set, 'charged = ?', 'entry_id = ?');
            array_push($args, $charged, $entryId);
        }

        if ($status === 'waived') {
            /* Waiving a record that already carries a charge has to settle the
               charge too, or the account keeps asking for money the programme
               has just said it is not asking for. */
            if ($entryId > 0 && $charged > 0) {
                if ($outcome === '') return ['ok' => false, 'error' => 'Say why it is being waived — they see this.'];
                $w = NgvLedger::waive((int) $d['member_id'], 'fine', $charged, $outcome, $byUid);
                if (empty($w['ok'])) return $w;
            }
        }

        if ($outcome !== '') { $set[] = 'outcome = ?'; $args[] = mb_substr($outcome, 0, self::TEXT_MAX); }
        $args[] = $id;
        NgvDb::pdo()->prepare('UPDATE ngv_damages SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);

        self::audit('ngv_damage_' . $status, (int) $d['member_id'],
            ucfirst($status) . ' — ' . $d['item']
            . ($charged > 0 ? ' · ' . NgvLedger::money_text($charged) . ' charged' : '')
            . ($outcome !== '' ? ' · ' . mb_substr($outcome, 0, 160) : ''));
        /* Re-assessing does not re-email unless the figure moved: a coordinator
           correcting a typo should not send somebody a second letter about it. */
        $quiet = $status === 'assessing' && $d['status'] === 'assessing' && $outcome === '';
        if (!$quiet) self::notify($id, $status);
        return ['ok' => true, 'status' => $status, 'charged' => $charged, 'entryId' => $entryId];
    }

    /** Turn the emails off (or back on) for one record. */
    public static function setNotify(int $id, bool $on): array
    {
        if (!self::get($id)) return ['ok' => false, 'error' => 'No such damage record.'];
        NgvDb::pdo()->prepare('UPDATE ngv_damages SET notify = ?, updated_at = ' . NgvDb::nowExpr() . ' WHERE id = ?')
            ->execute([$on ? 1 : 0, $id]);
        return ['ok' => true, 'notify' => $on];
    }

    /* ══ Reading ════════════════════════════════════════════════════════════ */

    public static function get(int $id): ?array
    {
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_damages WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? self::shape($r) : null;
    }

    /** One participant's own records, newest incident first. */
    public static function forMember(int $memberId): array
    {
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_damages WHERE member_id = ? ORDER BY occurred_on DESC, id DESC');
        $st->execute([$memberId]);
        return array_map([self::class, 'shape'], $st->fetchAll() ?: []);
    }

    /** The queue staff work from: open first, then the rest by recency. */
    public static function all(bool $openOnly = false, int $limit = self::PAGE): array
    {
        $limit = max(1, min(1000, $limit));
        $sql = 'SELECT d.*, p.name AS name, p.email AS email FROM ngv_damages d
                LEFT JOIN ngv_participants p ON p.member_id = d.member_id';
        if ($openOnly) $sql .= " WHERE d.status IN ('reported','assessing')";
        $sql .= " ORDER BY CASE WHEN d.status IN ('reported','assessing') THEN 0 ELSE 1 END,
                  d.occurred_on DESC, d.id DESC LIMIT " . $limit;
        return array_map([self::class, 'shape'], NgvDb::pdo()->query($sql)->fetchAll() ?: []);
    }

    /** Open records for one participant, including the one just filed. The
     *  throttle on staff alerts reads this — see `report()`. */
    public static function openCountFor(int $memberId): int
    {
        try {
            $st = NgvDb::pdo()->prepare("SELECT COUNT(*) FROM ngv_damages
                                          WHERE member_id = ? AND status IN ('reported','assessing')");
            $st->execute([$memberId]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 99; }   // fail quiet, not loud
    }

    public static function openCount(): int
    {
        try { return (int) NgvDb::pdo()->query("SELECT COUNT(*) FROM ngv_damages WHERE status IN ('reported','assessing')")->fetchColumn(); }
        catch (Throwable $e) { return 0; }
    }

    /** Programme-wide, for the console's tiles: what damage has cost, and what
     *  share of it the programme absorbed rather than passed on. */
    public static function totals(): array
    {
        $pdo = NgvDb::pdo();
        $q = static function (string $sql) use ($pdo): int {
            try { return (int) $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
        };
        $assessed = $q('SELECT COALESCE(SUM(assessed),0) FROM ngv_damages');
        $charged  = $q('SELECT COALESCE(SUM(charged),0) FROM ngv_damages');
        return [
            'records'  => $q('SELECT COUNT(*) FROM ngv_damages'),
            'open'     => self::openCount(),
            'assessed' => $assessed,
            'charged'  => $charged,
            /* Named for what it is. A programme that never absorbs anything is
               a programme charging children retail for accidents. */
            'absorbed' => max(0, $assessed - $charged),
        ];
    }

    /**
     * Assessments that have stalled. Nobody is waiting on the programme more
     * patiently than somebody who has been told "we are finding out what it
     * costs" and heard nothing since.
     */
    public static function stale(?string $asOf = null): array
    {
        $cut = gmdate('Y-m-d', (int) strtotime(($asOf ?: self::today()) . ' -' . self::STALE_DAYS . ' days'));
        $st = NgvDb::pdo()->prepare("SELECT d.*, p.name AS name, p.email AS email FROM ngv_damages d
                                     LEFT JOIN ngv_participants p ON p.member_id = d.member_id
                                     WHERE d.status IN ('reported','assessing')
                                       AND substr(d.updated_at, 1, 10) <= ? ORDER BY d.updated_at LIMIT 100");
        $st->execute([$cut]);
        return array_map([self::class, 'shape'], $st->fetchAll() ?: []);
    }

    /** Say once that assessments have stalled. Journals; changes nothing. */
    public static function noteStale(?string $asOf = null): array
    {
        $rows = self::stale($asOf);
        if (!$rows) return ['ok' => false, 'error' => 'none_stale'];
        $names = [];
        foreach (array_slice($rows, 0, 5) as $r) $names[] = $r['item'] . ' (' . ($r['name'] ?: '#' . $r['member_id']) . ')';
        self::audit('ngv_damage_stale', 0,
            count($rows) . ' damage report(s) have had no update in ' . self::STALE_DAYS . ' days: '
            . implode(', ', $names) . (count($rows) > 5 ? ' …' : ''), 'cron');
        return ['ok' => true, 'stale' => count($rows)];
    }

    private static function shape(array $r): array
    {
        $status = (string) $r['status'];
        return [
            'id' => (int) $r['id'], 'member_id' => (int) $r['member_id'],
            'item' => (string) $r['item'], 'occurred_on' => (string) $r['occurred_on'],
            'place' => (string) $r['place'],
            'severity' => (string) $r['severity'],
            'severityLabel' => self::SEVERITIES[(string) $r['severity']] ?? (string) $r['severity'],
            'description' => (string) $r['description'],
            'estimate' => (int) $r['estimate'], 'assessed' => (int) $r['assessed'],
            'charged' => (int) $r['charged'], 'entry_id' => (int) $r['entry_id'],
            'status' => $status,
            'statusLabel' => self::statusLabel($status),
            'open' => in_array($status, self::OPEN_STATUSES, true),
            'outcome' => (string) $r['outcome'],
            'selfReport' => (int) $r['self_report'] === 1,
            'notify' => (int) $r['notify'] === 1,
            'photos' => self::photosOf(['photosRaw' => (string) ($r['photos'] ?? '')]),
            'notified_at' => (string) $r['notified_at'],
            'created_at' => (string) $r['created_at'], 'updated_at' => (string) $r['updated_at'],
            'name' => (string) ($r['name'] ?? ''), 'email' => (string) ($r['email'] ?? ''),
        ];
    }

    /** The words a participant reads, not the words in the column. */
    public static function statusLabel(string $status): string
    {
        switch ($status) {
            case 'reported':  return 'Recorded — nothing charged';
            case 'assessing': return 'Being assessed';
            case 'charged':   return 'On your account';
            case 'waived':    return 'Not being asked for';
            case 'closed':    return 'Closed — nothing owed';
        }
        return $status;
    }

    /* ══ The emails ═════════════════════════════════════════════════════════ */

    /**
     * Tell the participant where their damage report stands.
     *
     * Plainly, and — on the first one especially — with what is NOT happening
     * said out loud. Somebody who gets "we have recorded damage to a laptop"
     * and nothing else assumes a bill is coming, and assumes the worst figure.
     * Saying "nothing has been charged, we do not know the cost yet, and we will
     * tell you before anything is" costs one sentence and is the difference
     * between a process and a threat.
     */
    public static function notify(int $id, string $status): bool
    {
        $d = self::get($id);
        if (!$d || !$d['notify']) return false;
        $to = trim((string) $d['email']);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) $to = '';
        $first = trim(explode(' ', trim((string) $d['name']))[0] ?? '');
        if ($first === '') $first = 'there';
        $m = static fn(int $n) => NgvLedger::money_text($n);
        $item = self::esc((string) $d['item']);
        $when = (string) $d['occurred_on'];

        $rows = [];
        $subject = '';
        switch ($status) {
            case 'reported':
                $subject = 'Recorded: ' . $d['item'];
                $rows[] = 'Hi ' . self::esc($first) . ' — we have recorded the ' . $item
                        . ($when !== '' ? ' from ' . self::esc($when) : '') . '.'
                        . ($d['selfReport'] ? ' Thank you for telling us yourself — that is the right instinct, and it is on the record as such.' : '');
                /* The sentence this whole email exists for. */
                $rows[] = '<b>Nothing has been charged.</b> We do not know yet what it will cost to put right, and we will '
                        . 'tell you what we find before anything goes on your account.';
                break;
            case 'assessing':
                $subject = 'Being assessed: ' . $d['item'];
                $rows[] = 'Hi ' . self::esc($first) . ' — we are finding out what it costs to put the ' . $item . ' right.';
                $rows[] = 'Still nothing on your account. We will write again when we know.';
                if ((int) $d['assessed'] > 0) {
                    $rows[] = 'The figure we have so far is ' . $m((int) $d['assessed'])
                            . '. That is what it costs, not what you are being asked for — those are different, and the second is a conversation.';
                }
                break;
            case 'charged':
                $subject = $m((int) $d['charged']) . ' on your account — ' . $d['item'];
                $rows[] = 'Hi ' . self::esc($first) . ' — the ' . $item . ' has been assessed'
                        . ((int) $d['assessed'] > 0 ? ' at ' . $m((int) $d['assessed']) : '') . '.';
                $rows[] = '<b>' . $m((int) $d['charged']) . '</b> has been added to your account.'
                        . ((int) $d['assessed'] > (int) $d['charged']
                            ? ' The programme is carrying the rest — ' . $m((int) $d['assessed'] - (int) $d['charged']) . '.'
                            : '');
                break;
            case 'waived':
                $subject = 'Nothing to pay — ' . $d['item'];
                $rows[] = 'Hi ' . self::esc($first) . ' — about the ' . $item . ': you are not being asked to pay for it.';
                if ((int) $d['charged'] > 0) {
                    $rows[] = 'The ' . $m((int) $d['charged']) . ' that was on your account has been set aside. '
                            . 'Your account no longer carries it.';
                }
                break;
            case 'closed':
                $subject = 'Closed — ' . $d['item'];
                $rows[] = 'Hi ' . self::esc($first) . ' — the ' . $item . ' is closed with nothing owed.';
                break;
            default:
                return false;
        }
        if ((string) $d['outcome'] !== '') $rows[] = self::esc((string) $d['outcome']);
        $rows[] = 'If any of this is wrong, or you see it differently, reply to this message or speak to your track lead — '
                . 'we would rather hear from you than not.';

        $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        $ok = false;
        if ($to !== '' && class_exists('Mailer')) {
            $html = Mailer::shell('NextGen Vanguard — ' . self::statusLabel($status), $rows,
                ['url' => $site . '/academy/ngv/dashboard.php#damage', 'text' => 'See my record'],
                $subject);
            try { $ok = (bool) Mailer::send($to, $subject, $html); }
            catch (Throwable $e) { error_log('[ngvdamage] mail: ' . $e->getMessage()); }
        }
        /* In-app as well as by email: NGV participants have accounts here, and a
           notification they see when they next sign in beats an email that may
           never be opened. Keyed per record AND per status, so each real move
           lands once and a re-save does not stack duplicates. */
        if (class_exists('Notifications')) {
            try {
                Notifications::push((int) $d['member_id'], 'ngv_damage',
                    self::statusLabel($status) . ' — ' . $d['item'],
                    strip_tags((string) ($rows[1] ?? $rows[0] ?? '')),
                    '/academy/ngv/dashboard.php#damage',
                    'ngv_damage:' . $id . ':' . $status);
            } catch (Throwable $e) { error_log('[ngvdamage] notify: ' . $e->getMessage()); }
        }
        /* Stamped whether or not the transport worked. A mailer failing quietly
           plus an unstamped record is how somebody gets the same letter on every
           tick, and how staff come to believe a message was never attempted. */
        try {
            NgvDb::pdo()->prepare('UPDATE ngv_damages SET notified_at = ? WHERE id = ?')
                ->execute([gmdate('Y-m-d H:i:s'), $id]);
        } catch (Throwable $e) { error_log('[ngvdamage] stamp: ' . $e->getMessage()); }
        return $ok;
    }

    /* ══ Small shared pieces ════════════════════════════════════════════════ */

    private static function participant(int $memberId): ?array
    {
        if ($memberId <= 0) return null;
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_participants WHERE member_id = ?');
        $st->execute([$memberId]);
        return $st->fetch() ?: null;
    }

    private static function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

    private static function today(): string
    {
        return function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d');
    }

    private static function validDate(string $d): string
    {
        $d = substr(trim($d), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
    }

    private static function audit(string $action, int $memberId, string $detail, string $actor = 'admin'): void
    {
        if (!class_exists('AdminAudit')) return;
        try { AdminAudit::log('ngv', $action, $memberId > 0 ? 'ngv:member:' . $memberId : 'ngv:damage', $detail, null, $actor); }
        catch (Throwable $e) { error_log('[ngvdamage] audit: ' . $e->getMessage()); }
    }
}
