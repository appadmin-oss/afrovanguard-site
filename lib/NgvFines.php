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
 * ANY MEMBER can be fined, not only NGV participants: every active portal
 * member (MemberRoster::memberWhere) is on the roll. A member who is not in
 * NGV has the same ledger line (ngv_charges, kind `fine`, keyed by their
 * portal id — one id space for both), sees their fines under Fines in their
 * own portal, and pays at the office: the desk records it (payOne). The NGV
 * account's other lines — fees, Paystack, reminders — stay NGV-only.
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
        $isNgv = (bool) NgvMember::participant($memberId);
        if (!$isNgv && !self::isMember($memberId)) return ['ok' => false, 'status' => 'refused', 'error' => 'Not an active member.'];
        if ($key === '' && $isNgv) {
            $r = NgvLedger::charge($memberId, 'fine', $n, $reason, $dated, $by);
            if (empty($r['ok'])) return ['ok' => false, 'status' => 'refused', 'error' => (string) ($r['error'] ?? 'Not posted.')];
            $id = (int) $r['entryId'];
        } else {
            /* An imported fine, or one for a member outside NGV: the same row
               the ledger's own charge() writes. An import's is under the row's
               identity, so a re-import is one fine. */
            $label = NgvLedger::FINE_REASONS[$reason];
            $id = NgvLedger::postCharge($memberId, 'fine', $n, $key !== '' ? 'imp:' . $key : 'fine:' . bin2hex(random_bytes(5)),
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

    /** An active portal member, NGV or not: somebody a fine can be for. */
    public static function isMember(int $memberId): bool
    {
        if ($memberId <= 0) return false;
        try {
            $where = class_exists('MemberRoster') ? ' AND ' . MemberRoster::memberWhere() : '';
            $st = Database::pdo()->prepare("SELECT 1 FROM lms_users u WHERE u.id = ? AND u.status = 'active'{$where}");
            $st->execute([$memberId]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    /**
     * Money received for a fine — at the office, by transfer — recorded on
     * the member's fines line. Like every payment on the ledger it comes off
     * their oldest fine (the first recorded) first, not necessarily the one
     * clicked; capped at what is owing on the fine it was recorded from. Any
     * member: this is how one outside NGV pays.
     *
     * IT COMES WITH THE RECEIPT. This desk used to credit whatever figure was
     * typed into a prompt, while the member desk two pages away had already
     * stopped doing that ('evidence_required'): a fine could be cleared by
     * typing its amount, and the person fined could type it. Both ends are
     * closed here — the money goes through OfflinePayments, which reads the
     * receipt, checks it against the claim and credits only what passes
     * (purpose 'ngv', line 'fine': the same ledger call this made directly) —
     * and nobody clears their own.
     *
     * $evidence is ['bytes' => string, 'mime' => string] and $actor the name
     * recorded against the submission; $by is the staff member's account id,
     * for the self-check.
     */
    public static function payOne(int $chargeId, $amount, string $method, string $reference, int $by, array $evidence = [], string $actor = '', string $paidOn = ''): array
    {
        $f = self::find($chargeId);
        if (!$f) return ['ok' => false, 'error' => 'No such fine.'];
        if ($no = NgvLedger::selfDealing((int) $f['member_id'], $by, 'records the payment')) return ['ok' => false, 'code' => 'own_account', 'error' => $no];
        if ((int) $f['owing'] <= 0) return ['ok' => false, 'error' => 'Nothing is owing on this fine.'];
        $n = NgvLedger::money($amount);
        if ($n <= 0) $n = (int) $f['owing'];
        if ($n > (int) $f['owing']) return ['ok' => false, 'error' => 'That is more than is owing on this fine (' . NgvLedger::money_text((int) $f['owing']) . ').'];
        $method = in_array($method, OfflinePayments::METHODS, true) ? $method : 'cash';
        return OfflinePayments::submit((int) $f['member_id'], [
            'purpose' => 'ngv', 'line' => 'fine', 'amount_ngn' => $n, 'method' => $method,
            'reference' => trim($reference), 'paid_on' => trim($paidOn),
        ], (string) ($evidence['bytes'] ?? ''), (string) ($evidence['mime'] ?? ''), $actor !== '' ? $actor : 'staff', 'staff');
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
        $ngv = (bool) $who;
        if (!$who) {
            $u = Database::pdo()->prepare('SELECT name, email FROM lms_users WHERE id = ?');
            $u->execute([$memberId]);
            $who = $u->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $where = $ngv ? 'NGV account' : 'Afrovanguard account';
        $href = $ngv ? '/portal/#ngv-fines' : '/portal/#fines';
        $label = NgvLedger::FINE_REASONS[$reason] ?? 'Fine';
        $line = NgvLedger::money_text($amount) . ' — ' . $label . ($note !== '' ? ': ' . $note : '') . ', ' . date('j M Y', (int) strtotime($day . 'T12:00:00')) . '.';
        $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        if (class_exists('Mailer') && !empty($who['email'])) {
            $first = trim(explode(' ', trim((string) ($who['name'] ?? '')))[0]) ?: 'there';
            $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
            $html = Mailer::shell('A fine on your ' . $where, [
                'Hi ' . $esc($first) . ',',
                'A fine has been recorded on your ' . ($ngv ? 'NextGen Vanguard' : 'Afrovanguard') . ' account: <b>' . $esc($line) . '</b>',
                $ngv ? 'You can see it, and everything else on your account, in your portal. If you think it is wrong, or this is a hard month, say so from there — a person will answer.'
                     : 'You can see it in your portal, under Fines, with how to pay it. If you think it is wrong, tell the office — a person will answer.',
            ], ['url' => $site . $href, 'text' => 'See my fines'], $line);
            try { @Mailer::send((string) $who['email'], 'A fine on your ' . $where . ' — ' . NgvLedger::money_text($amount), $html); } catch (Throwable $e) { error_log('[fines] mail: ' . $e->getMessage()); }
        }
        if (class_exists('Notifications')) {
            try { Notifications::push($memberId, 'ngv_fees', 'A fine on your ' . $where, $line, $href); } catch (Throwable $e) {}
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
        /* A member outside NGV has no participant row: their name is the portal's. One query. */
        $missing = array_values(array_unique(array_map(static fn($r) => (int) $r['member_id'], array_filter($rows, static fn($r) => (string) ($r['name'] ?? '') === ''))));
        if ($missing) {
            $names = [];
            try { foreach (Database::pdo()->query('SELECT id, name, email FROM lms_users WHERE id IN (' . implode(',', $missing) . ')')->fetchAll(PDO::FETCH_ASSOC) as $u) $names[(int) $u['id']] = $u; }
            catch (Throwable $e) { /* names stay blank */ }
            foreach ($rows as &$r0) if ((string) ($r0['name'] ?? '') === '' && isset($names[(int) $r0['member_id']])) { $r0['name'] = (string) $names[(int) $r0['member_id']]['name']; $r0['email'] = (string) $names[(int) $r0['member_id']]['email']; }
            unset($r0);
        }
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

    /* ══ Reading any sheet ══════════════════════════════════════════════════
     *
     * People keep fines in whatever they have: an Excel workbook, a CSV, a
     * list typed into WhatsApp ("Tunde Bakare – late – 12 Oct – 2k"). The
     * desk takes all of them. Named columns are read by their names; a sheet
     * with no header row, or with headings nobody planned for, is read by
     * what is IN each column (an email, a phone, a date, an amount, a reason,
     * a name); and a pasted list is read a line at a time, each piece by what
     * it looks like. What was guessed is said, so a person can check it.
     */

    /**
     * Any sheet to rows: an .xlsx (base64), or text — CSV with or without a
     * header, or a typed list.
     *
     * @return array{ok:bool, rows?:array, columns?:array, unknown_columns?:array, read_as?:string, error?:string}
     */
    public static function parseAny(string $text, string $xlsxBase64 = ''): array
    {
        if ($xlsxBase64 !== '') {
            $bin = base64_decode($xlsxBase64, true);
            if ($bin === false || strlen($bin) > 5 * 1024 * 1024) return ['ok' => false, 'error' => 'That workbook could not be read. Save it again, or as CSV.'];
            $grid = self::xlsxGrid($bin);
            if ($grid === null) return ['ok' => false, 'error' => 'That workbook could not be read. Save it as CSV and try again.'];
            return self::parseGrid($grid, 'workbook');
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', trim($text)) ?: [], static fn($l) => trim($l) !== ''));
        if (!$lines) return ['ok' => false, 'error' => 'The file is empty.'];
        $first = $lines[0];
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        $head = array_map(static fn($h) => strtolower(trim(preg_replace('/[_\s]+/', ' ', (string) $h) ?? '')), str_getcsv($first, $sep, '"', '\\'));
        if (array_intersect($head, array_keys(self::HEADERS))) {
            $r = self::parseCsv($text);
            return $r + ['read_as' => 'columns by their headings'];
        }
        /* No header. A table when every line splits into the same number of
           cells; otherwise a typed list. */
        $widths = array_unique(array_map(static fn($l) => count(str_getcsv($l, $sep, '"', '\\')), $lines));
        if (count($widths) === 1 && reset($widths) >= 2 && $sep !== ',' || (count($widths) === 1 && reset($widths) >= 3)) {
            return self::parseGrid(array_map(static fn($l) => str_getcsv($l, $sep, '"', '\\'), $lines), 'a table with no headings');
        }
        $rows = [];
        foreach ($lines as $line) {
            $r = self::readLine($line);
            if ($r) $rows[] = $r;
            if (count($rows) > self::MAX_ROWS) return ['ok' => false, 'error' => 'More than ' . self::MAX_ROWS . ' fines in one go. Split the list.'];
        }
        if (!$rows) return ['ok' => false, 'error' => 'No fines could be read from that. Put one fine on each line: who, what for, and the day.'];
        return ['ok' => true, 'rows' => $rows, 'columns' => self::COLUMNS, 'unknown_columns' => [], 'read_as' => 'a typed list, one fine a line'];
    }

    /** What one cell looks like: email, phone, id, date, amount, reason, name or note. */
    public static function kindOf(string $cell): string
    {
        $t = trim($cell);
        if ($t === '') return 'blank';
        if (filter_var($t, FILTER_VALIDATE_EMAIL)) return 'email';
        if (preg_match('/^[A-Z]{1,4}-?NGV-?\d{2}-?\d{3,5}$/i', $t) || preg_match('/^[A-Z]{1,3}-\d{2}-\d{3,5}$/i', $t)) return 'id';
        $digits = preg_replace('/\D/', '', $t) ?? '';
        if (preg_match('/^\+?[\d\s()\-]{10,18}$/', $t) && strlen($digits) >= 10 && strlen($digits) <= 14) return 'phone';
        if (self::amountOf($t) !== null) return 'amount';
        if (preg_match('/\d/', $t) && (preg_match('#[/.\-]#', $t) || preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i', $t)) && self::day($t) !== null) return 'date';
        if (in_array(mb_strtolower($t), ['today', 'yesterday'], true)) return 'date';
        if (self::reasonOf($t) !== 'other') return 'reason';
        if (preg_match('/^[\p{L}][\p{L}\'’.\- ]{1,60}$/u', $t) && count(preg_split('/\s+/', $t) ?: []) <= 4) return 'name';
        return 'note';
    }

    /** "₦2,000", "2000", "2k", "N1500" to naira; null when it is not an amount. */
    public static function amountOf(string $t): ?int
    {
        $t = strtolower(trim(str_replace([',', ' '], '', $t)));
        if (!preg_match('/^(?:₦|n|ngn)?(\d+(?:\.\d+)?)(k)?(?:naira)?$/u', $t, $m)) return null;
        $n = (float) $m[1] * (!empty($m[2]) ? 1000 : 1);
        /* A bare four-to-five-digit number that is not a round amount is more
           likely a year or a serial than money; amounts are round. */
        if (empty($m[2]) && $n >= 1000 && fmod($n, 50) != 0.0) return null;
        return $n > 0 && $n <= 10_000_000 ? (int) round($n) : null;
    }

    /** One typed line — "Tunde Bakare – late – 12 Oct – 2k" — to a row. */
    private static function readLine(string $line): ?array
    {
        $parts = preg_split('/\s+[-–—|:]\s+|\t|;|,(?!\d{3})/u', trim($line)) ?: [];
        $r = array_fill_keys(self::COLUMNS, '');
        $notes = [];
        foreach ($parts as $p) {
            $p = trim($p, " \t.");
            if ($p === '') continue;
            $k = self::kindOf($p);
            if ($k === 'amount' && $r['amount'] === '') $r['amount'] = (string) self::amountOf($p);
            elseif (in_array($k, ['email', 'phone', 'id'], true) && $r[$k] === '') $r[$k] = $p;
            elseif ($k === 'date' && $r['date'] === '') $r['date'] = $p;
            elseif ($k === 'reason' && $r['reason'] === '') $r['reason'] = $p;
            elseif ($k === 'name' && $r['name'] === '') $r['name'] = $p;
            else $notes[] = $p;
        }
        if ($r['name'] === '' && $notes && self::kindOf($notes[0]) !== 'note') $r['name'] = array_shift($notes);
        $r['note'] = implode(' — ', $notes);
        if ($r['reason'] === '' && $r['note'] !== '' && self::reasonOf($r['note']) !== 'other') { $r['reason'] = $r['note']; $r['note'] = ''; }
        return ($r['name'] . $r['email'] . $r['phone'] . $r['id']) !== '' ? $r : null;
    }

    /** A grid of cells, with a header row or not, to rows. */
    private static function parseGrid(array $grid, string $how): array
    {
        $grid = array_values(array_filter($grid, static fn($r) => implode('', array_map('strval', (array) $r)) !== ''));
        if (!$grid) return ['ok' => false, 'error' => 'There are no fines in it.'];
        $head = array_map(static fn($h) => strtolower(trim(preg_replace('/[_\s]+/', ' ', (string) $h) ?? '')), (array) $grid[0]);
        $map = []; $unknown = [];
        foreach ($head as $i => $h) { if (isset(self::HEADERS[$h])) $map[$i] = self::HEADERS[$h]; }
        if ($map) {
            foreach ($head as $i => $h) if (!isset($map[$i]) && $h !== '') $unknown[] = $h;
            array_shift($grid);
            $read = $how . ', columns by their headings';
        } else {
            /* No headings we know: each column is what most of its cells are. */
            $width = max(array_map('count', $grid));
            $taken = [];
            for ($i = 0; $i < $width; $i++) {
                $votes = [];
                foreach ($grid as $row) { $k = self::kindOf((string) ($row[$i] ?? '')); if ($k !== 'blank') $votes[$k] = ($votes[$k] ?? 0) + 1; }
                arsort($votes);
                $k = (string) array_key_first($votes);
                /* An Excel date arrives as a day number (46000 is 2025). */
                if ($k === 'amount' && self::looksLikeSerials($grid, $i)) $k = 'date';
                $field = ['email' => 'email', 'phone' => 'phone', 'id' => 'id', 'date' => 'date', 'amount' => 'amount', 'reason' => 'reason', 'name' => 'name', 'note' => 'note'][$k] ?? null;
                if ($field === null) continue;
                if (isset($taken[$field])) { if ($field === 'name' || $field === 'reason') $field = 'note'; else continue; }
                if (isset($taken[$field])) continue;
                $map[$i] = $field; $taken[$field] = true;
            }
            $read = $how . ' — columns worked out from what is in them: ' . implode(', ', array_map(static fn($f) => $f === 'id' ? 'NGV ID' : ucfirst($f), $map));
        }
        if (!array_intersect($map, ['name', 'email', 'phone', 'id'])) return ['ok' => false, 'error' => 'There is no column saying who: a name, email, phone or NGV ID.'];
        $rows = [];
        foreach ($grid as $cells) {
            $r = array_fill_keys(self::COLUMNS, '');
            foreach ($map as $i => $field) {
                $v = trim((string) ($cells[$i] ?? ''));
                if ($field === 'date' && preg_match('/^\d{5}(\.\d+)?$/', $v)) $v = gmdate('Y-m-d', ((int) $v - 25569) * 86400);
                if ($field === 'amount' && $v !== '' && ($a = self::amountOf($v)) !== null) $v = (string) $a;
                $r[$field] = $v;
            }
            if (implode('', $r) !== '') $rows[] = $r;
            if (count($rows) > self::MAX_ROWS) return ['ok' => false, 'error' => 'More than ' . self::MAX_ROWS . ' rows in one import. Split the file.'];
        }
        if (!$rows) return ['ok' => false, 'error' => 'There are no fines in it, only a header.'];
        return ['ok' => true, 'rows' => $rows, 'columns' => array_values(array_unique($map)), 'unknown_columns' => $unknown, 'read_as' => $read];
    }

    private static function looksLikeSerials(array $grid, int $i): bool
    {
        $n = 0; $all = 0;
        foreach ($grid as $row) { $v = trim((string) ($row[$i] ?? '')); if ($v === '') continue; $all++; if (preg_match('/^\d{5}$/', $v) && (int) $v >= 40000 && (int) $v <= 60000) $n++; }
        return $all > 0 && $n === $all;
    }

    /** The first sheet of an .xlsx as rows of strings. Null when it is not one. */
    private static function xlsxGrid(string $bin): ?array
    {
        if (!class_exists('ZipArchive')) return null;
        $tmp = tempnam(sys_get_temp_dir(), 'fx');
        if ($tmp === false) return null;
        file_put_contents($tmp, $bin);
        $zip = new ZipArchive();
        try {
            if ($zip->open($tmp) !== true) return null;
            $strings = [];
            if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $x = @simplexml_load_string($ss, 'SimpleXMLElement', LIBXML_NONET);
                if ($x) foreach ($x->si as $si) { $t = ''; if (isset($si->t)) $t = (string) $si->t; else foreach ($si->r as $run) $t .= (string) $run->t; $strings[] = $t; }
            }
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheet === false) return null;
            $x = @simplexml_load_string($sheet, 'SimpleXMLElement', LIBXML_NONET);
            if (!$x || !isset($x->sheetData)) return null;
            $grid = [];
            foreach ($x->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $ref = (string) $c['r'];
                    $col = 0;
                    foreach (str_split(preg_replace('/\d+/', '', $ref) ?? '') as $ch) $col = $col * 26 + (ord($ch) - 64);
                    $type = (string) $c['t'];
                    $v = $type === 's' ? ($strings[(int) $c->v] ?? '') : ($type === 'inlineStr' ? (string) $c->is->t : (string) $c->v);
                    $cells[max(0, $col - 1)] = $v;
                    if (count($grid) > self::MAX_ROWS + 1) break 2;
                }
                if ($cells) { $w = max(array_keys($cells)); $grid[] = array_map(static fn($i) => (string) ($cells[$i] ?? ''), range(0, $w)); }
            }
            return $grid;
        } finally {
            $zip->close();
            @unlink($tmp);
        }
    }

    /* ══ Remembering who people meant ═════════════════════════════════════
     *
     * When a person picks who a row is for — a name the sheet spelled its own
     * way, a nickname, a phone the roll does not have — that choice is
     * remembered, so the next sheet that says the same thing is matched
     * without asking. Only choices that were imported are learned, and a
     * remembered name that has since become two people is asked again.
     */
    private const META_ALIASES = 'ngv_fine_aliases';
    private const ALIASES_MAX = 3000;

    /** @return array<string,int> */
    private static function aliases(): array
    {
        $v = json_decode((string) (Database::metaGet(self::META_ALIASES) ?? ''), true);
        return is_array($v) ? array_map('intval', $v) : [];
    }

    /** @return list<string> the keys a row is known by */
    private static function aliasKeys(array $row): array
    {
        $k = [];
        if (($n = self::normName((string) ($row['name'] ?? ''))) !== '') $k[] = 'n:' . $n;
        if (($e = strtolower(trim((string) ($row['email'] ?? '')))) !== '') $k[] = 'e:' . $e;
        if (($p = self::phoneKey((string) ($row['phone'] ?? ''))) !== '') $k[] = 'p:' . $p;
        return $k;
    }

    private static function remember(array $row, int $memberId): void
    {
        $keys = self::aliasKeys($row);
        if (!$keys || $memberId <= 0) return;
        $all = self::aliases();
        foreach ($keys as $k) { unset($all[$k]); $all[$k] = $memberId; }
        if (count($all) > self::ALIASES_MAX) $all = array_slice($all, -self::ALIASES_MAX, null, true);
        Database::metaSet(self::META_ALIASES, (string) json_encode($all));
    }

    /* ══ What else is on the member's record ══════════════════════════════ */

    /** Already fined for this reason on this day, any way (the gate, the desk, an import)? Says how. */
    private static function alreadyThatDay(int $memberId, string $reason, string $day, string $exceptPeriod): ?string
    {
        $st = NgvDb::pdo()->prepare("SELECT c.period, c.created_at, m.occurred_on FROM ngv_charges c LEFT JOIN ngv_fine_meta m ON m.charge_id = c.id
                                      WHERE c.member_id = ? AND c.kind = 'fine' AND c.reason = ? AND c.voided = 0 AND c.period <> ?");
        $st->execute([$memberId, $reason, $exceptPeriod]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $period = (string) $c['period'];
            $d = preg_match('/^(late|absent|nocard):(\d{4}-\d{2}-\d{2})$/', $period, $m) ? $m[2] : (string) ($c['occurred_on'] ?: substr((string) $c['created_at'], 0, 10));
            if ($d !== $day) continue;
            if ($m) return 'at the CACENTRE gate';
            if (str_starts_with($period, 'imp:')) return 'by an earlier import';
            return 'at the desk';
        }
        return null;
    }

    /** How many fines for this reason the member had in the 30 days up to $day. */
    private static function recentCount(int $memberId, string $reason, string $day): int
    {
        $from = date('Y-m-d', (int) strtotime($day . ' -30 days'));
        $st = NgvDb::pdo()->prepare("SELECT c.period, c.created_at, m.occurred_on FROM ngv_charges c LEFT JOIN ngv_fine_meta m ON m.charge_id = c.id
                                      WHERE c.member_id = ? AND c.kind = 'fine' AND c.reason = ? AND c.voided = 0");
        $st->execute([$memberId, $reason]);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $d = preg_match('/:(\d{4}-\d{2}-\d{2})$/', (string) $c['period'], $m) ? $m[1] : (string) ($c['occurred_on'] ?: substr((string) $c['created_at'], 0, 10));
            if ($d >= $from && $d <= $day) $n++;
        }
        return $n;
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
        elseif (($t = strtotime($s . ' 12:00')) !== false || ($t = strtotime($s)) !== false) {
            $d = [(int) date('Y', $t), (int) date('n', $t), (int) date('j', $t)];
            /* "12 Dec" written in October meant last December, not a future one. */
            if (!preg_match('/\b\d{4}\b/', $s) && sprintf('%04d-%02d-%02d', $d[0], $d[1], $d[2]) > self::today()) $d[0]--;
        }
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
                'status' => (string) $p['status'], 'phone' => '', 'cards' => [], 'emails' => array_filter([strtolower((string) $p['email'])]), 'ngv' => true];
        }
        /* And every active portal member who is not in NGV: anybody can be fined. */
        try {
            $where = class_exists('MemberRoster') ? ' AND ' . MemberRoster::memberWhere() : '';
            foreach (Database::pdo()->query("SELECT u.id, u.name, u.email FROM lms_users u WHERE u.status = 'active'{$where}")->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $id = (int) $u['id'];
                if (isset($pop[$id])) continue;
                $pop[$id] = ['id' => $id, 'name' => (string) $u['name'], 'email' => strtolower((string) $u['email']), 'status' => 'active',
                    'phone' => '', 'cards' => [], 'emails' => array_filter([strtolower((string) $u['email'])]), 'ngv' => false];
            }
        } catch (Throwable $e) { error_log('[ngvfines] members: ' . $e->getMessage()); }
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
        return ['id' => $p['id'], 'name' => $p['name'], 'ngv' => !empty($p['ngv']),
                'detail' => trim(implode(' · ', array_filter([empty($p['ngv']) ? 'member' : 'NGV', $card, $p['emails'][0] ?? $p['email'], $p['status'] !== 'active' ? $p['status'] : '']))), 'score' => $score];
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
            return ['status' => 'unmatched', 'warnings' => ['the person chosen is not an active member any more']];
        }
        $idv = strtoupper(preg_replace('/\s+/', '', (string) $row['id']) ?? '');
        if ($idv !== '') {
            foreach ($pop as $p) if (in_array($idv, $p['cards'], true)) { $nameCheck($p); return ['status' => 'matched', 'member' => self::shape($p), 'how' => 'NGV ID', 'warnings' => $warn]; }
            $warn[] = 'nobody holds ' . $idv;
        }
        $email = strtolower(trim((string) $row['email']));
        if ($email !== '') {
            foreach ($pop as $p) if (in_array($email, $p['emails'] ?? [$p['email']], true)) { $nameCheck($p); return ['status' => 'matched', 'member' => self::shape($p), 'how' => 'email', 'warnings' => $warn]; }
            $warn[] = 'no member has the email ' . $email;
        }
        $ph = self::phoneKey((string) $row['phone']);
        if ($ph !== '') {
            $hits = array_values(array_filter($pop, static fn($p) => self::phoneKey($p['phone']) === $ph));
            if (count($hits) === 1) { $nameCheck($hits[0]); return ['status' => 'matched', 'member' => self::shape($hits[0]), 'how' => 'phone', 'warnings' => $warn]; }
            if (count($hits) > 1) return ['status' => 'ambiguous', 'candidates' => array_map([self::class, 'shape'], $hits), 'warnings' => array_merge($warn, ['that phone is on more than one record'])];
        }
        /* Somebody chose who this name, email or phone meant before, and imported it. */
        $known = self::aliases();
        foreach (self::aliasKeys($row) as $k) {
            $id = $known[$k] ?? 0;
            if (!$id || !isset($pop[$id])) continue;
            /* A remembered name only stands while nobody else on the roll has it exactly. */
            if (str_starts_with($k, 'n:')) {
                $same = array_filter($pop, static fn($p) => $p['id'] !== $id && self::normName($p['name']) === substr($k, 2));
                if ($same) continue;
            }
            return ['status' => 'matched', 'member' => self::shape($pop[$id]), 'how' => 'what you chose before', 'warnings' => $warn];
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
        return ['status' => 'unmatched', 'warnings' => $warn ?: ['nothing on the row matches a member']];
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
            if ($how = self::alreadyThatDay($mid, $reason, (string) $day, 'imp:' . $key)) {
                $out['status'] = 'already'; $out['warnings'][] = 'already fined for ' . mb_strtolower(NgvLedger::FINE_REASONS[$reason]) . ' that day ' . $how . ' — not charged twice';
                $rep['counts']['already']++; $rep['rows'][] = $out; continue;
            }
            $before = self::recentCount($mid, $reason, (string) $day);
            if ($before >= 2) $out['warnings'][] = self::ordinal($before + 1) . ' ' . mb_strtolower(NgvLedger::FINE_REASONS[$reason]) . ' fine in 30 days';
            if ($apply) {
                $r = self::issue($mid, $reason, $amount, $note, (string) $day, $notify, $by, $key, $run);
                if (!$r['ok']) { $out['status'] = 'invalid'; $out['errors'][] = (string) ($r['error'] ?? 'not posted'); $rep['counts']['invalid']++; $rep['rows'][] = $out; continue; }
                $out['status'] = $r['status'] === 'already' ? 'already' : 'fined';
                $rep['counts'][$out['status']]++;
                /* A person chose who this row is for: next time, the sheet's words are enough. */
                if (trim((string) $row['assign']) !== '') self::remember($row, $mid);
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

    private static function ordinal(int $n): string
    {
        $s = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
        return $n . $s;
    }

    private static function nameOf(int $mid): string
    {
        $st = NgvDb::pdo()->prepare('SELECT name FROM ngv_participants WHERE member_id = ?');
        $st->execute([$mid]);
        $n = (string) ($st->fetchColumn() ?: '');
        if ($n === '') { $u = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?'); $u->execute([$mid]); $n = (string) ($u->fetchColumn() ?: ''); }
        return $n !== '' ? $n : '#' . $mid;
    }

    /** Members by name — NGV or not — for "who is this fine for" pickers. */
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
