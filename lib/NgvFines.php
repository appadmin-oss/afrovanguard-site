<?php
/**
 * lib/NgvFines.php — fines, as a desk: issue, see, settle, import.
 *
 * The money is the NGV ledger's (lib/NgvLedger.php): a fine is an
 * ngv_charges row of kind `fine`, with a reason from NgvLedger::FINE_REASONS,
 * paid, waived and voided by the ledger's own paths. Nothing here adds money
 * up a second way. What this adds is everything around a fine that a person
 * running a programme needs:
 *
 *   · a CATALOGUE — the usual amount for each reason, so a fine for a late
 *     arrival is the same ₦ whoever issues it;
 *   · ISSUING to one member or many at once, with the day it happened, and
 *     the member TOLD (email + portal notification) with a way to ask about it;
 *   · ONE LIST of every fine — who, what, when, how much, and whether it is
 *     owing, settled, waived or voided;
 *   · IMPORT from a spreadsheet, with SMART ASSIGNMENT: each row is matched to
 *     the member it is for by NGV ID, gate card, email, phone or name — close
 *     spellings included — and a row that could be more than one person is
 *     never guessed at: it is shown with the candidates, and a person picks.
 *
 * An import is a dry run until the dry run that was read is applied, and a row
 * already imported is never charged twice (each row's identity is its member,
 * reason, amount, day and note, used as the ledger's idempotency key).
 */
declare(strict_types=1);

final class NgvFines
{
    private const META_CATALOGUE = 'ngv_fine_catalogue';
    public const MAX_ROWS = 1000;

    /* ══ Catalogue ══════════════════════════════════════════════════════════ */

    /** The usual amount for each reason. Equipment and "other" have none: they are priced each time. */
    public static function catalogue(): array
    {
        $late = class_exists('GateAttendance') ? (int) GateAttendance::lateFine() : 0;
        $absent = class_exists('GateAttendance') ? (int) GateAttendance::absentFine() : 0;
        $defaults = ['late' => $late ?: 1000, 'absent' => $absent ?: 2000, 'uniform' => 500, 'equipment' => 0, 'conduct' => 2000, 'other' => 0];
        $saved = json_decode((string) (Database::metaGet(self::META_CATALOGUE) ?? ''), true);
        $out = [];
        foreach (NgvLedger::FINE_REASONS as $k => $label) {
            $out[$k] = ['label' => $label, 'amount' => max(0, (int) (is_array($saved) && isset($saved[$k]) ? $saved[$k] : $defaults[$k] ?? 0))];
        }
        return $out;
    }

    public static function saveCatalogue(array $amounts): array
    {
        $clean = [];
        foreach (NgvLedger::FINE_REASONS as $k => $_) {
            if (!array_key_exists($k, $amounts)) continue;
            $raw = trim((string) $amounts[$k]);
            $n = (int) round((float) $raw);
            if (($raw !== '' && !is_numeric($raw)) || $n < 0 || $n > 10_000_000) return ['ok' => false, 'error' => 'Amounts are between ₦0 and ₦10,000,000.'];
            $clean[$k] = $n;
        }
        $saved = json_decode((string) (Database::metaGet(self::META_CATALOGUE) ?? ''), true);
        Database::metaSet(self::META_CATALOGUE, (string) json_encode(array_merge(is_array($saved) ? $saved : [], $clean)));
        return ['ok' => true, 'catalogue' => self::catalogue()];
    }

    /* ══ Issuing ════════════════════════════════════════════════════════════ */

    /**
     * Fine one member. `$key`, when given, makes it idempotent: the same key on
     * the same member is one fine however often it is sent (imports use it).
     *
     * @return array{ok:bool, status:string, entryId?:int, amount?:int, error?:string}
     */
    public static function issue(int $memberId, string $reason, $amount, string $note, string $occurredOn, bool $notify, int $by, string $key = '', string $importRun = ''): array
    {
        if (!isset(NgvLedger::FINE_REASONS[$reason])) return ['ok' => false, 'status' => 'refused', 'error' => 'Choose why the fine was issued.'];
        $day = self::day($occurredOn);
        if ($day === null) return ['ok' => false, 'status' => 'refused', 'error' => 'The day it happened is a date, today or earlier.'];
        $n = NgvLedger::money($amount);
        if ($n <= 0) $n = (int) self::catalogue()[$reason]['amount'];
        if ($n <= 0) return ['ok' => false, 'status' => 'refused', 'error' => 'This reason has no usual amount — enter one.'];
        if ($reason === 'other' && trim($note) === '') return ['ok' => false, 'status' => 'refused', 'error' => 'Say what the fine is for.'];
        $pdo = NgvDb::pdo();
        if ($key !== '') {
            $st = $pdo->prepare("SELECT id FROM ngv_charges WHERE member_id = ? AND kind = 'fine' AND period = ?");
            $st->execute([$memberId, 'imp:' . $key]);
            if (($id = $st->fetchColumn()) !== false) return ['ok' => true, 'status' => 'already', 'entryId' => (int) $id, 'amount' => $n];
        }
        $note = trim($note);
        $dated = $day !== self::today() ? ($note !== '' ? $note . ' ' : '') . '(' . date('j M Y', (int) strtotime($day . 'T12:00:00')) . ')' : $note;
        if ($key === '') {
            $r = NgvLedger::charge($memberId, 'fine', $n, $reason, $dated, $by);
            if (empty($r['ok'])) return ['ok' => false, 'status' => 'refused', 'error' => (string) ($r['error'] ?? 'Not posted.')];
            $id = (int) $r['entryId'];
        } else {
            /* An imported fine: the same row the ledger's own charge() writes,
               but under the row's identity, so a re-import is one fine. */
            if (!NgvMember::participant($memberId)) return ['ok' => false, 'status' => 'refused', 'error' => 'Not an NGV participant.'];
            $label = NgvLedger::FINE_REASONS[$reason];
            $id = NgvLedger::postCharge($memberId, 'fine', $n, 'imp:' . $key,
                ['reason' => $reason, 'note' => $dated !== '' ? $label . ' — ' . $dated : $label, 'source' => 'staff', 'by' => $by]);
            if ($id === null) return ['ok' => true, 'status' => 'already', 'amount' => $n];
            if (class_exists('AdminAudit')) {
                try { AdminAudit::log('ngv', 'ngv_fee_fine', 'ngv:member:' . $memberId, 'Fine ' . NgvLedger::money_text($n) . ' — ' . $label . ($dated !== '' ? ': ' . $dated : '') . ' (import ' . $importRun . ')', null, 'admin'); } catch (Throwable $e) {}
            }
            $r = ['overCap' => false];
        }
        $pdo->prepare('INSERT INTO ngv_fine_meta (charge_id, occurred_on, import_run) VALUES (?, ?, ?)')->execute([$id, $day, mb_substr($importRun, 0, 64)]);
        if ($notify) self::tell($memberId, $id, $reason, $n, $note, $day);
        return ['ok' => true, 'status' => 'fined', 'entryId' => $id, 'amount' => $n, 'overCap' => !empty($r['overCap'])];
    }

    /** Fine several members the same way. One result per member. */
    public static function issueMany(array $memberIds, string $reason, $amount, string $note, string $occurredOn, bool $notify, int $by): array
    {
        $out = ['ok' => true, 'fined' => 0, 'total' => 0, 'results' => []];
        foreach (array_values(array_unique(array_map('intval', $memberIds))) as $mid) {
            if ($mid <= 0) continue;
            $r = self::issue($mid, $reason, $amount, $note, $occurredOn, $notify, $by);
            $out['results'][] = ['member_id' => $mid, 'name' => self::nameOf($mid)] + $r;
            if ($r['ok']) { $out['fined']++; $out['total'] += (int) $r['amount']; }
        }
        if (!$out['results']) return ['ok' => false, 'error' => 'Choose who the fine is for.'];
        return $out;
    }

    /** Email and a portal notification: what, why, when, and where to ask. */
    private static function tell(int $memberId, int $chargeId, string $reason, int $amount, string $note, string $day): void
    {
        $p = NgvDb::pdo()->prepare('SELECT name, email FROM ngv_participants WHERE member_id = ?');
        $p->execute([$memberId]);
        $who = $p->fetch(PDO::FETCH_ASSOC) ?: [];
        $label = NgvLedger::FINE_REASONS[$reason] ?? 'Fine';
        $line = NgvLedger::money_text($amount) . ' — ' . $label . ($note !== '' ? ': ' . $note : '') . ', ' . date('j M Y', (int) strtotime($day . 'T12:00:00')) . '.';
        $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        if (class_exists('Mailer') && !empty($who['email'])) {
            $first = trim(explode(' ', trim((string) ($who['name'] ?? '')))[0]) ?: 'there';
            $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
            $html = Mailer::shell('A fine on your NGV account', [
                'Hi ' . $esc($first) . ',',
                'A fine has been recorded on your NextGen Vanguard account: <b>' . $esc($line) . '</b>',
                'You can see it, and everything else on your account, in your portal. If you think it is wrong, or this is a hard month, say so from there — a person will answer.',
            ], ['url' => $site . '/portal/#ngv-fines', 'text' => 'See my fines'], $line);
            try { @Mailer::send((string) $who['email'], 'A fine on your NGV account — ' . NgvLedger::money_text($amount), $html); } catch (Throwable $e) { error_log('[fines] mail: ' . $e->getMessage()); }
        }
        if (class_exists('Notifications')) {
            try { Notifications::push($memberId, 'ngv_fees', 'A fine on your NGV account', $line, '/portal/#ngv-fines'); } catch (Throwable $e) {}
        }
        NgvDb::pdo()->prepare('UPDATE ngv_fine_meta SET notified_at = ' . NgvDb::nowExpr() . ' WHERE charge_id = ?')->execute([$chargeId]);
    }

    /* ══ The list ═══════════════════════════════════════════════════════════ */

    /**
     * Every fine, newest first, with where it stands. A fine's standing is read
     * from the fines line the way the ledger reads it: what has been paid,
     * waived or written off on the line settles the oldest fines first.
     */
    public static function all(array $f = []): array
    {
        $pdo = NgvDb::pdo();
        /* One member's fines: their standing depends only on their own
           payments, so narrowing both reads gives the same answer, sooner. */
        $one = (int) ($f['member_id'] ?? 0);
        $st = $pdo->prepare("SELECT c.id, c.member_id, c.amount, c.reason, c.note, c.source, c.voided, c.void_reason, c.created_at,
                                    p.name, p.email, m.occurred_on, m.import_run, m.notified_at
                               FROM ngv_charges c LEFT JOIN ngv_participants p ON p.member_id = c.member_id
                               LEFT JOIN ngv_fine_meta m ON m.charge_id = c.id
                              WHERE c.kind = 'fine'" . ($one > 0 ? ' AND c.member_id = ?' : '') . " ORDER BY c.id ASC");
        $st->execute($one > 0 ? [$one] : []);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $credits = [];
        $st = $pdo->prepare("SELECT member_id, credit_kind, SUM(amount) s FROM ngv_payments WHERE kind = 'fine' AND voided = 0" . ($one > 0 ? ' AND member_id = ?' : '') . " GROUP BY member_id, credit_kind");
        $st->execute($one > 0 ? [$one] : []);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $credits[(int) $c['member_id']][(string) $c['credit_kind']] = (int) $c['s'];
        }
        $pool = [];
        foreach ($credits as $mid => $k) $pool[$mid] = ['paid' => (int) ($k['payment'] ?? 0), 'forgiven' => (int) ($k['waiver'] ?? 0) + (int) ($k['writeoff'] ?? 0)];
        foreach ($rows as &$r) {
            $mid = (int) $r['member_id']; $amt = (int) $r['amount'];
            $r['day'] = (string) ($r['occurred_on'] ?: substr((string) $r['created_at'], 0, 10));
            $r['label'] = NgvLedger::FINE_REASONS[(string) $r['reason']] ?? 'Fine';
            if ((int) $r['voided'] === 1) { $r['status'] = 'voided'; $r['owing'] = 0; continue; }
            $p = &$pool[$mid]; $p = $p ?? ['paid' => 0, 'forgiven' => 0];
            $byPay = min($amt, $p['paid']); $p['paid'] -= $byPay;
            $byMercy = min($amt - $byPay, $p['forgiven']); $p['forgiven'] -= $byMercy;
            $r['owing'] = $amt - $byPay - $byMercy;
            $r['status'] = $r['owing'] === 0 ? ($byMercy > 0 && $byPay === 0 ? 'waived' : 'settled') : ($byPay + $byMercy > 0 ? 'part' : 'owing');
            unset($p);
        }
        unset($r);
        $rows = array_reverse($rows);
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
        return array_values(array_filter($rows, static function ($r) use ($f, $q) {
            if ($q !== '' && !str_contains(mb_strtolower((string) $r['name'] . ' ' . (string) $r['email'] . ' ' . (string) $r['note']), $q)) return false;
            if (($f['reason'] ?? '') !== '' && $r['reason'] !== $f['reason']) return false;
            if (($f['status'] ?? '') === 'open' && !in_array($r['status'], ['owing', 'part'], true)) return false;
            if (($f['status'] ?? '') !== '' && $f['status'] !== 'open' && $r['status'] !== $f['status']) return false;
            if (($f['from'] ?? '') !== '' && $r['day'] < $f['from']) return false;
            if (($f['to'] ?? '') !== '' && $r['day'] > $f['to']) return false;
            if (($f['source'] ?? '') === 'import' && (string) $r['import_run'] === '') return false;
            return true;
        }));
    }

    /**
     * One vanguard's own fines, newest first, with where each stands — what
     * their portal shows. Standing is worked out per member, so this is the
     * same answer the desk gives for them.
     *
     * @return array{fines: array, owing: int, open: int}
     */
    public static function forMember(int $memberId): array
    {
        if ($memberId <= 0) return ['fines' => [], 'owing' => 0, 'open' => 0];
        try { $all = self::all(['member_id' => $memberId]); }
        catch (Throwable $e) { error_log('[ngvfines] forMember: ' . $e->getMessage()); $all = []; }
        $fines = array_map(static fn($r) => array_intersect_key($r, array_flip(['id', 'day', 'reason', 'label', 'note', 'amount', 'owing', 'status', 'void_reason', 'source'])), $all);
        $open = array_filter($fines, static fn($r) => in_array($r['status'], ['owing', 'part'], true));
        return ['fines' => $fines, 'owing' => array_sum(array_map(static fn($r) => (int) $r['owing'], $open)), 'open' => count($open)];
    }

    /** The desk's figures. */
    public static function summary(): array
    {
        $all = self::all();
        $month = substr(self::today(), 0, 7);
        $s = ['outstanding' => 0, 'owing_members' => 0, 'this_month' => 0, 'this_month_n' => 0, 'settled' => 0, 'waived' => 0];
        $who = [];
        foreach ($all as $r) {
            if ($r['status'] === 'voided') continue;
            $s['outstanding'] += (int) $r['owing'];
            if ((int) $r['owing'] > 0) $who[(int) $r['member_id']] = true;
            if (str_starts_with((string) $r['day'], $month)) { $s['this_month'] += (int) $r['amount']; $s['this_month_n']++; }
            if ($r['status'] === 'settled') $s['settled']++;
            if ($r['status'] === 'waived') $s['waived']++;
        }
        $s['owing_members'] = count($who);
        return $s;
    }

    /** Set what is left of one fine aside, with a reason. A recorded mercy — the fine stays on record. */
    public static function waiveOne(int $chargeId, string $reason, int $by): array
    {
        $f = self::find($chargeId);
        if (!$f) return ['ok' => false, 'error' => 'No such fine.'];
        if ((int) $f['owing'] <= 0) return ['ok' => false, 'error' => 'Nothing is owing on this fine.'];
        if (trim($reason) === '') return ['ok' => false, 'error' => 'Say why it is waived.'];
        return NgvLedger::waive((int) $f['member_id'], 'fine', (int) $f['owing'], $reason, $by);
    }

    /** It should never have been issued. */
    public static function voidOne(int $chargeId, string $reason, int $by): array
    {
        if (!self::find($chargeId)) return ['ok' => false, 'error' => 'No such fine.'];
        if (trim($reason) === '') return ['ok' => false, 'error' => 'Say why it is voided.'];
        return NgvLedger::void('charge', $chargeId, $reason, $by);
    }

    private static function find(int $chargeId): ?array
    {
        foreach (self::all() as $r) if ((int) $r['id'] === $chargeId) return $r;
        return null;
    }

    /* ══ Import, with smart assignment ═════════════════════════════════════ */

    private const HEADERS = [
        'name' => 'name', 'full name' => 'name', 'member' => 'name', 'member name' => 'name', 'vanguard' => 'name', 'who' => 'name',
        'email' => 'email', 'email address' => 'email', 'e-mail' => 'email',
        'phone' => 'phone', 'phone number' => 'phone', 'mobile' => 'phone', 'whatsapp' => 'phone',
        'ngv' => 'id', 'ngv id' => 'id', 'ngv number' => 'id', 'id' => 'id', 'id number' => 'id', 'card' => 'id', 'card number' => 'id', 'member id' => 'id',
        'amount' => 'amount', 'fine' => 'amount', 'naira' => 'amount', '₦' => 'amount', 'amount (₦)' => 'amount', 'fine amount' => 'amount',
        'reason' => 'reason', 'offence' => 'reason', 'offense' => 'reason', 'category' => 'reason', 'type' => 'reason', 'for' => 'reason', 'violation' => 'reason',
        'note' => 'note', 'notes' => 'note', 'details' => 'note', 'description' => 'note', 'comment' => 'note', 'remarks' => 'note',
        'date' => 'date', 'day' => 'date', 'when' => 'date', 'occurred' => 'date', 'date of offence' => 'date',
        'assign to' => 'assign',
    ];

    /** The columns, in the order an edited sheet is written back. */
    public const COLUMNS = ['name', 'email', 'phone', 'id', 'amount', 'reason', 'note', 'date', 'assign'];

    public static function parseCsv(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $lines = preg_split('/\r\n|\n|\r/', trim($text));
        if (!$lines || trim((string) $lines[0]) === '') return ['ok' => false, 'error' => 'The file is empty.'];
        $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : (substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : ',');
        $head = array_map(static fn($h) => strtolower(trim(preg_replace('/[_\s]+/', ' ', (string) $h) ?? '')), str_getcsv((string) array_shift($lines), $sep, '"', '\\'));
        $map = []; $unknown = [];
        foreach ($head as $i => $h) { if (isset(self::HEADERS[$h])) $map[$i] = self::HEADERS[$h]; elseif ($h !== '') $unknown[] = $h; }
        $whoCols = array_intersect($map, ['name', 'email', 'phone', 'id', 'assign']);
        if (!$whoCols) return ['ok' => false, 'error' => 'There is no column saying who: Name, Email, Phone or NGV ID.'];
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cells = str_getcsv($line, $sep, '"', '\\');
            $r = array_fill_keys(self::COLUMNS, '');
            foreach ($map as $i => $field) $r[$field] = trim((string) ($cells[$i] ?? ''));
            if (implode('', $r) !== '') $rows[] = $r;
            if (count($rows) > self::MAX_ROWS) return ['ok' => false, 'error' => 'More than ' . self::MAX_ROWS . ' rows in one import. Split the file.'];
        }
        if (!$rows) return ['ok' => false, 'error' => 'There are no fines in the file, only a header.'];
        return ['ok' => true, 'rows' => $rows, 'columns' => array_values(array_unique($map)), 'unknown_columns' => $unknown];
    }

    /** Free text to a reason: "came in late", "no ID card", "broke the projector". */
    public static function reasonOf(string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') return null;
        foreach (NgvLedger::FINE_REASONS as $k => $label) if ($t === $k || $t === mb_strtolower($label)) return $k;
        $words = [
            'late' => ['late', 'lateness', 'tardy', 'tardiness', 'after time', 'came in at'],
            'absent' => ['absent', 'absence', 'absentee', 'no show', 'no-show', 'missed', 'did not come', "didn't come", 'skipped'],
            'uniform' => ['uniform', 'dress', 'id card', 'no id', 'badge', 'tag', 'lanyard', 'attire', 'outfit'],
            'equipment' => ['equipment', 'damage', 'damaged', 'broke', 'broken', 'lost', 'laptop', 'projector', 'device', 'property'],
            'conduct' => ['conduct', 'behaviour', 'behavior', 'misconduct', 'rude', 'fight', 'disrespect', 'phone use', 'noise', 'indiscipline'],
        ];
        foreach ($words as $k => $list) foreach ($list as $w) if (str_contains($t, $w)) return $k;
        return 'other';
    }

    private static function today(): string { return function_exists('av_today_tz') ? av_today_tz() : date('Y-m-d'); }

    /** A day as people write it, to YYYY-MM-DD; blank is today; null if it is no date or in the future. */
    public static function day(string $raw): ?string
    {
        $s = trim($raw);
        if ($s === '') return self::today();
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) $d = [(int) $m[1], (int) $m[2], (int) $m[3]];
        elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$#', $s, $m)) $d = [(int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3], (int) $m[2], (int) $m[1]];
        elseif (($t = strtotime($s . ' 12:00')) !== false) $d = [(int) date('Y', $t), (int) date('n', $t), (int) date('j', $t)];
        else return null;
        if (!checkdate($d[1], $d[2], $d[0])) return null;
        $iso = sprintf('%04d-%02d-%02d', $d[0], $d[1], $d[2]);
        return $iso > self::today() ? null : $iso;
    }

    /** Names compared as people mean them: case, accents, punctuation and order do not matter. */
    public static function normName(string $n): string
    {
        $n = mb_strtolower(trim($n));
        $n = strtr($n, ['á' => 'a', 'à' => 'a', 'é' => 'e', 'è' => 'e', 'ẹ' => 'e', 'í' => 'i', 'ó' => 'o', 'ò' => 'o', 'ọ' => 'o', 'ú' => 'u', 'ṣ' => 's', 'ń' => 'n']);
        $parts = preg_split('/[^a-z]+/', $n, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($parts);
        return implode(' ', $parts);
    }

    /** How alike two names are, 0–100: the better of a spelling match and a word match. */
    public static function nameScore(string $a, string $b): int
    {
        $x = self::normName($a); $y = self::normName($b);
        if ($x === '' || $y === '') return 0;
        if ($x === $y) return 100;
        similar_text($x, $y, $pct);
        $pct = min(99, $pct);
        $wa = explode(' ', $x); $wb = explode(' ', $y);
        $hit = 0;
        foreach ($wa as $w) foreach ($wb as $v) {
            if ($w === $v) { $hit++; break; }
            /* One letter off counts nearly as much — never quite, so only an
               exact name is ever a 100. */
            if (strlen($w) > 3 && strlen($v) > 3 && levenshtein($w, $v) <= 1) { $hit += 0.9; break; }
        }
        $words = min(99, $hit / max(count($wa), count($wb)) * 100);
        /* "Ade Bello" on the sheet and "Adebayo Bello" on the roll share a
           surname and a first initial: likely, not certain. */
        if ($words < 100 && count($wa) >= 2 && count($wb) >= 2 && $hit >= 1 && $wa[0][0] === $wb[0][0]) $words = max($words, 70);
        return (int) round(max($pct, $words));
    }

    /** Everybody a fine can be for: the NGV participants, with what identifies them. */
    private static function population(): array
    {
        if (self::$pop !== null) return self::$pop;
        $pop = [];
        foreach (NgvDb::pdo()->query("SELECT member_id, name, email, status FROM ngv_participants")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $pop[(int) $p['member_id']] = ['id' => (int) $p['member_id'], 'name' => (string) $p['name'], 'email' => strtolower((string) $p['email']),
                'status' => (string) $p['status'], 'phone' => '', 'cards' => [], 'emails' => array_filter([strtolower((string) $p['email'])])];
        }
        if (!$pop) return self::$pop = $pop;
        $ids = implode(',', array_keys($pop));
        $main = Database::pdo();
        foreach ($main->query("SELECT id, name, email FROM lms_users WHERE id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $p = &$pop[(int) $u['id']];
            if ($p['name'] === '') $p['name'] = (string) $u['name'];
            $p['emails'] = array_values(array_unique(array_filter([$p['email'], strtolower((string) $u['email'])])));
            unset($p);
        }
        try { foreach ($main->query("SELECT user_id, phone FROM member_profiles WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC) as $r) $pop[(int) $r['user_id']]['phone'] = (string) $r['phone']; } catch (Throwable $e) {}
        try { foreach ($main->query("SELECT member_id, code FROM gate_member_cards WHERE status = 'active' AND member_id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC) as $r) $pop[(int) $r['member_id']]['cards'][] = strtoupper((string) $r['code']); } catch (Throwable $e) {}
        try { foreach ($main->query("SELECT member_id, code FROM av_member_cards WHERE status = 'active' AND member_id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC) as $r) $pop[(int) $r['member_id']]['cards'][] = strtoupper((string) $r['code']); } catch (Throwable $e) {}
        return self::$pop = $pop;
    }

    private static ?array $pop = null;

    /** Drop the cached roll (after members change, and between tests). */
    public static function forget(): void { self::$pop = null; }

    private static function phoneKey(string $p): string { $d = preg_replace('/\D/', '', $p) ?? ''; return strlen($d) >= 7 ? substr($d, -10) : ''; }

    private static function shape(array $p, int $score = 100): array
    {
        $card = $p['cards'][0] ?? '';
        return ['id' => $p['id'], 'name' => $p['name'], 'detail' => trim(implode(' · ', array_filter([$card, $p['emails'][0] ?? $p['email'], $p['status'] !== 'active' ? $p['status'] : '']))), 'score' => $score];
    }

    /**
     * Who a row is for. The strongest thing on the row decides: a choice a
     * person already made (Assign to), an NGV ID or gate card, an email, a
     * phone, then the name — exactly, then closely. A name close to two people
     * is ambiguous, never a coin toss.
     *
     * @return array{status:string, member?:array, how?:string, candidates?:array, warnings:array}
     */
    public static function assign(array $row): array
    {
        $pop = self::population();
        $warn = [];
        $nameCheck = static function (array $p) use ($row, &$warn): void {
            if (trim((string) $row['name']) !== '' && self::nameScore((string) $row['name'], $p['name']) < 60) {
                $warn[] = 'the sheet says “' . $row['name'] . '” but this is ' . $p['name'] . ' — check it is the right person';
            }
        };
        $assign = trim((string) $row['assign']);
        if ($assign !== '') {
            $id = (int) ltrim($assign, '#');
            if (isset($pop[$id])) return ['status' => 'matched', 'member' => self::shape($pop[$id]), 'how' => 'chosen by you', 'warnings' => []];
            return ['status' => 'unmatched', 'warnings' => ['the person chosen is not an NGV participant any more']];
        }
        $idv = strtoupper(preg_replace('/\s+/', '', (string) $row['id']) ?? '');
        if ($idv !== '') {
            foreach ($pop as $p) if (in_array($idv, $p['cards'], true)) { $nameCheck($p); return ['status' => 'matched', 'member' => self::shape($p), 'how' => 'NGV ID', 'warnings' => $warn]; }
            $warn[] = 'nobody holds ' . $idv;
        }
        $email = strtolower(trim((string) $row['email']));
        if ($email !== '') {
            foreach ($pop as $p) if (in_array($email, $p['emails'] ?? [$p['email']], true)) { $nameCheck($p); return ['status' => 'matched', 'member' => self::shape($p), 'how' => 'email', 'warnings' => $warn]; }
            $warn[] = 'no vanguard has the email ' . $email;
        }
        $ph = self::phoneKey((string) $row['phone']);
        if ($ph !== '') {
            $hits = array_values(array_filter($pop, static fn($p) => self::phoneKey($p['phone']) === $ph));
            if (count($hits) === 1) { $nameCheck($hits[0]); return ['status' => 'matched', 'member' => self::shape($hits[0]), 'how' => 'phone', 'warnings' => $warn]; }
            if (count($hits) > 1) return ['status' => 'ambiguous', 'candidates' => array_map([self::class, 'shape'], $hits), 'warnings' => array_merge($warn, ['that phone is on more than one record'])];
        }
        $name = trim((string) $row['name']);
        if ($name !== '') {
            $scored = [];
            foreach ($pop as $p) { $s = self::nameScore($name, $p['name']); if ($s >= 60) $scored[] = self::shape($p, $s); }
            usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
            $top = $scored[0] ?? null; $next = $scored[1] ?? null;
            if ($top && $top['score'] === 100 && (!$next || $next['score'] < 100)) return ['status' => 'matched', 'member' => $top, 'how' => 'name', 'warnings' => $warn];
            if ($top && $top['score'] >= 88 && (!$next || $next['score'] <= $top['score'] - 12)) {
                return ['status' => 'matched', 'member' => $top, 'how' => 'name, spelt differently (' . $top['score'] . '% alike)', 'warnings' => array_merge($warn, ['matched on a close spelling of the name'])];
            }
            if ($scored) return ['status' => 'ambiguous', 'candidates' => array_slice($scored, 0, 5), 'warnings' => $warn];
        }
        return ['status' => 'unmatched', 'warnings' => $warn ?: ['nothing on the row matches a vanguard']];
    }

    /** What an import would DO, as a hash: row order and blanks do not change it. */
    public static function digest(array $rows, bool $notify): string
    {
        $norm = array_map(static function ($r) { $r = array_filter(array_map('trim', (array) $r), static fn($v) => $v !== ''); ksort($r); return json_encode($r, JSON_UNESCAPED_UNICODE); }, $rows);
        sort($norm);
        return hash('sha256', implode("\n", $norm) . ($notify ? "\n#notify" : ''));
    }

    /**
     * Import fines. A dry run unless `apply`; an apply must name the dry run it
     * applies. Rows that are ambiguous or match nobody are never charged: they
     * come back with the candidates, for a person to choose (Assign to) and
     * check again.
     */
    public static function import(array $rows, array $opts = []): array
    {
        $apply = !empty($opts['apply']); $notify = !empty($opts['notify']); $by = (int) ($opts['by'] ?? 0);
        if (!$rows) return ['ok' => false, 'error' => 'There are no fines to import.'];
        $digest = self::digest($rows, $notify);
        if ($apply) {
            $expect = trim((string) ($opts['expect_digest'] ?? ''));
            if ($expect === '') return ['ok' => false, 'code' => 'preview_required', 'error' => 'Check the file first — an import has to name the check it is applying.'];
            if (!hash_equals($digest, $expect)) return ['ok' => false, 'code' => 'changed', 'error' => 'This is not what you checked. Check it again before importing.', 'digest' => $digest];
        }
        $run = $apply ? 'fimp-' . substr($digest, 0, 12) : '';
        $cat = self::catalogue();
        $rep = ['ok' => true, 'applied' => $apply, 'digest' => $digest, 'rows' => [],
                'counts' => ['ready' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'invalid' => 0, 'already' => 0, 'duplicate' => 0, 'fined' => 0], 'total' => 0];
        $seen = [];
        foreach ($rows as $i => $row) {
            $row = array_merge(array_fill_keys(self::COLUMNS, ''), array_map(static fn($v) => trim((string) $v), (array) $row));
            $out = ['i' => $i, 'errors' => [], 'warnings' => []];
            $reason = self::reasonOf($row['reason'] !== '' ? $row['reason'] : $row['note']);
            if ($reason === null) { $reason = 'other'; }
            $note = $row['note'];
            if ($reason === 'other' && $note === '' && $row['reason'] !== '') $note = $row['reason'];
            if ($row['reason'] !== '' && !in_array(mb_strtolower($row['reason']), [$reason, mb_strtolower(NgvLedger::FINE_REASONS[$reason])], true) && $reason !== 'other') {
                $out['warnings'][] = '“' . $row['reason'] . '” read as ' . NgvLedger::FINE_REASONS[$reason];
                if ($note === '') $note = $row['reason'];
            }
            if ($reason === 'other' && $note === '') $out['errors'][] = 'say what the fine is for (a reason or a note)';
            $amount = $row['amount'] !== '' ? NgvLedger::money(preg_replace('/[^\d.]/', '', $row['amount'])) : 0;
            if ($row['amount'] !== '' && $amount <= 0) $out['errors'][] = '“' . $row['amount'] . '” is not an amount';
            if ($amount <= 0 && $row['amount'] === '') {
                $amount = (int) $cat[$reason]['amount'];
                if ($amount > 0) $out['warnings'][] = 'no amount — the usual ' . NgvLedger::money_text($amount) . ' for ' . mb_strtolower($cat[$reason]['label']);
                else $out['errors'][] = 'no amount, and ' . mb_strtolower($cat[$reason]['label']) . ' has no usual amount';
            }
            $day = self::day($row['date']);
            if ($day === null) $out['errors'][] = '“' . $row['date'] . '” is not a date, or it is in the future';
            $who = self::assign($row);
            $out += ['status' => $who['status'], 'member' => $who['member'] ?? null, 'how' => $who['how'] ?? '', 'candidates' => $who['candidates'] ?? [],
                     'reason' => $reason, 'reason_label' => NgvLedger::FINE_REASONS[$reason], 'amount' => $amount, 'day' => $day, 'note' => $note];
            $out['warnings'] = array_merge($out['warnings'], $who['warnings']);
            if ($out['errors']) { $out['status'] = 'invalid'; $rep['counts']['invalid']++; $rep['rows'][] = $out; continue; }
            if ($who['status'] !== 'matched') { $rep['counts'][$who['status']]++; $rep['rows'][] = $out; continue; }
            $mid = (int) $who['member']['id'];
            $key = substr(hash('sha256', $mid . '|' . $reason . '|' . $amount . '|' . $day . '|' . mb_strtolower($note)), 0, 16);
            if (isset($seen[$key])) { $out['status'] = 'duplicate'; $out['warnings'][] = 'the same fine as row ' . ($seen[$key] + 2) . ' — charged once'; $rep['counts']['duplicate']++; $rep['rows'][] = $out; continue; }
            $seen[$key] = $i;
            $st = NgvDb::pdo()->prepare("SELECT 1 FROM ngv_charges WHERE member_id = ? AND kind = 'fine' AND period = ?");
            $st->execute([$mid, 'imp:' . $key]);
            if ($st->fetchColumn()) { $out['status'] = 'already'; $out['warnings'][] = 'already imported — not charged again'; $rep['counts']['already']++; $rep['rows'][] = $out; continue; }
            if ($apply) {
                $r = self::issue($mid, $reason, $amount, $note, (string) $day, $notify, $by, $key, $run);
                if (!$r['ok']) { $out['status'] = 'invalid'; $out['errors'][] = (string) ($r['error'] ?? 'not posted'); $rep['counts']['invalid']++; $rep['rows'][] = $out; continue; }
                $out['status'] = $r['status'] === 'already' ? 'already' : 'fined';
                $rep['counts'][$out['status']]++;
                if ($out['status'] === 'fined') $rep['total'] += $amount;
            } else {
                $out['status'] = 'ready'; $rep['counts']['ready']++; $rep['total'] += $amount;
            }
            $rep['rows'][] = $out;
        }
        if ($apply) {
            NgvDb::pdo()->prepare('INSERT INTO ngv_fine_imports (digest, source, actor, fined, total, skipped, row_count) VALUES (?,?,?,?,?,?,?)')
                ->execute([$digest, mb_substr((string) ($opts['source'] ?? ''), 0, 255), $by, $rep['counts']['fined'], $rep['total'],
                           count($rows) - $rep['counts']['fined'], count($rows)]);
            if (class_exists('AdminAudit')) {
                try { AdminAudit::log('ngv', 'ngv_fines_import', 'ngv:fines', sprintf('%d fined · %s · %d not charged', $rep['counts']['fined'], NgvLedger::money_text($rep['total']), count($rows) - $rep['counts']['fined']), null, 'admin'); } catch (Throwable $e) {}
            }
        }
        return $rep;
    }

    private static function nameOf(int $mid): string
    {
        $st = NgvDb::pdo()->prepare('SELECT name FROM ngv_participants WHERE member_id = ?');
        $st->execute([$mid]);
        return (string) ($st->fetchColumn() ?: ('#' . $mid));
    }

    /** Vanguards by name, for "who is this fine for" pickers. */
    public static function people(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $out = [];
        foreach (self::population() as $p) {
            $s = max(self::nameScore($q, $p['name']), str_contains(mb_strtolower($p['name']), mb_strtolower($q)) ? 95 : 0,
                     in_array(strtoupper($q), $p['cards'], true) ? 100 : 0, in_array(strtolower($q), $p['emails'] ?? [], true) ? 100 : 0);
            if ($s >= 60) $out[] = self::shape($p, $s);
        }
        usort($out, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, 10);
    }
}
