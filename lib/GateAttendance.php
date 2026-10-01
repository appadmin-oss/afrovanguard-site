<?php
/**
 * lib/GateAttendance.php — members' attendance at the CACENTRE gate.
 *
 * The gate (a Cloudflare Worker at CACENTRE's door) keeps a log of who came
 * in and when, judged against the desk's calendar, and sends each passage to
 * whoever issued the card. For Afrovanguard's members that is this site:
 * integrations/cacentre-gate.php hands the passages to report() here, and
 * this is where they turn into a member's register, for the member to see
 * and for NGV staff to act on.
 *
 * ── WHAT THIS REPLACES ──────────────────────────────────────────────────────
 * Two Google Apps Script systems ("CACENTRE Attendance" v3 and "AFROVANGUARD
 * Attendance" v5) kept NGV attendance in a spreadsheet: a 7:00 resumption,
 * Monday to Friday, X-NGV-YY-NNNN ID cards, a late fine, an absence fine,
 * check-in refused for fines more than fourteen days unpaid, excused days and
 * a punctuality grade. Those rules carry over — but as AvRules, so leadership
 * sets them, and with every one that costs money OFF until somebody sets a
 * figure. Fines post to the NGV ledger (lib/NgvLedger.php) like any other,
 * where staff can see, waive and void them.
 *
 * What does NOT carry over is the spreadsheet's trust model: anybody could
 * check anybody in by typing their ID, and the admin screens were open to
 * the internet. Here a passage arrives only from the gate, signed.
 *
 * ── WHO IS EXPECTED ─────────────────────────────────────────────────────────
 * Any member may come through the gate, and every passage is recorded. Only
 * active NGV participants are EXPECTED: only they are marked absent, and
 * only they can be fined, because only they have an account to fine.
 */
declare(strict_types=1);

final class GateAttendance
{
    public const STATUSES = ['present', 'late', 'absent', 'excused'];
    /** A printed NGV member ID card, as the spreadsheet days issued them. */
    public const CARD_PATTERN = '/^[A-Z]-NGV-\d{2}-\d{4}$/';
    private const SWEPT_META = 'gate_absent_swept_through';
    private const SWEEP_MAX_DAYS = 14;

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        $pdo = Database::pdo();
        Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS gate_attendance (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id    INTEGER NOT NULL,
            day          VARCHAR(10) NOT NULL,
            status       VARCHAR(10) NOT NULL DEFAULT 'present',
            late_minutes INTEGER NOT NULL DEFAULT 0,
            in_at        VARCHAR(32) NOT NULL DEFAULT '',
            out_at       VARCHAR(32) NOT NULL DEFAULT '',
            centre       VARCHAR(60) NOT NULL DEFAULT '',
            desk         VARCHAR(40) NOT NULL DEFAULT '',
            method       VARCHAR(16) NOT NULL DEFAULT '',
            gate_in_id   VARCHAR(64) NOT NULL DEFAULT '',
            gate_out_id  VARCHAR(64) NOT NULL DEFAULT '',
            fine_id      INTEGER NOT NULL DEFAULT 0,
            note         VARCHAR(255) NOT NULL DEFAULT '',
            created_at   VARCHAR(32) NOT NULL DEFAULT '',
            updated_at   VARCHAR(32) NOT NULL DEFAULT ''
        )");
        Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS gate_member_cards (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id  INTEGER NOT NULL,
            code       VARCHAR(20) NOT NULL,
            status     VARCHAR(10) NOT NULL DEFAULT 'active',
            issued_by  INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS gate_excuses (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id   INTEGER NOT NULL,
            day         VARCHAR(10) NOT NULL,
            reason      VARCHAR(300) NOT NULL DEFAULT '',
            status      VARCHAR(10) NOT NULL DEFAULT 'pending',
            decided_by  INTEGER NOT NULL DEFAULT 0,
            decided_at  VARCHAR(32) NOT NULL DEFAULT '',
            outcome     VARCHAR(300) NOT NULL DEFAULT '',
            created_at  VARCHAR(32) NOT NULL DEFAULT ''
        )");
        Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS gate_probation (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id  INTEGER NOT NULL,
            since      VARCHAR(10) NOT NULL DEFAULT '',
            until      VARCHAR(10) NOT NULL DEFAULT '',
            reason     VARCHAR(300) NOT NULL DEFAULT '',
            status     VARCHAR(10) NOT NULL DEFAULT 'active',
            set_by     INTEGER NOT NULL DEFAULT 0,
            lifted_by  INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS gate_points (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id  INTEGER NOT NULL,
            day        VARCHAR(10) NOT NULL,
            period     VARCHAR(40) NOT NULL,
            points     INTEGER NOT NULL DEFAULT 0,
            note       VARCHAR(160) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        /* The register's one-row-per-member-per-day is the database's promise,
           not the code's: a passage delivered twice, or two desks racing, can
           only ever land on the same row. */
        foreach (['CREATE UNIQUE INDEX IF NOT EXISTS idx_gate_att_day ON gate_attendance(member_id, day)',
                  'CREATE INDEX IF NOT EXISTS idx_gate_att_on ON gate_attendance(day)',
                  'CREATE UNIQUE INDEX IF NOT EXISTS idx_gate_card_code ON gate_member_cards(code)',
                  'CREATE INDEX IF NOT EXISTS idx_gate_card_member ON gate_member_cards(member_id)',
                  'CREATE UNIQUE INDEX IF NOT EXISTS idx_gate_excuse_day ON gate_excuses(member_id, day)',
                  /* An award is once per period — a day, a run, a week — however
                     often the passage that earned it is delivered. */
                  'CREATE UNIQUE INDEX IF NOT EXISTS idx_gate_points_once ON gate_points(member_id, period)',
                  'CREATE INDEX IF NOT EXISTS idx_gate_points_day ON gate_points(day)',
                  'CREATE INDEX IF NOT EXISTS idx_gate_probation_member ON gate_probation(member_id)'] as $ix) {
            try { $pdo->exec($ix); } catch (Throwable $e) { /* already there */ }
        }
    }

    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
    private static function today(): string { return function_exists('av_today_tz') ? av_today_tz() : date('Y-m-d'); }
    private static function rule(string $k, $fallback)
    {
        try { return class_exists('AvRules') ? AvRules::get($k) : $fallback; } catch (Throwable $e) { return $fallback; }
    }
    public static function lateFine(): int       { return max(0, (int) self::rule('gate.late_fine', 0)); }
    public static function absentFine(): int     { return max(0, (int) self::rule('gate.absent_fine', 0)); }
    public static function lateFineProbation(): int { return max(0, (int) self::rule('gate.late_fine_probation', 0)); }
    /** @return string[] level codes */
    public static function probationLevels(): array
    {
        $v = self::rule('gate.probation_levels', 'O');
        $list = is_array($v) ? $v : explode(',', (string) $v);
        /* "none" rather than empty: an empty rule reads as unset, and falls back to O. */
        return array_values(array_filter(array_map(static fn($l) => strtoupper(trim((string) $l)), $list), static fn($l) => $l !== '' && $l !== 'NONE'));
    }
    /** @return array{on_time:int, streak3:int, streak5:int, week:int} */
    public static function pointRules(): array
    {
        return ['on_time' => max(0, (int) self::rule('gate.points_on_time', 5)), 'streak3' => max(0, (int) self::rule('gate.points_streak3', 15)),
                'streak5' => max(0, (int) self::rule('gate.points_streak5', 30)), 'week' => max(0, (int) self::rule('gate.points_perfect_week', 30))];
    }
    public static function marksAbsent(): bool   { return (bool) self::rule('gate.mark_absent', false); }
    public static function blockOverdueDays(): int { return max(0, (int) self::rule('gate.block_overdue_days', 0)); }
    /** @return string[] three-letter days */
    public static function programmeDays(): array
    {
        $v = self::rule('gate.programme_days', 'mon,tue,wed,thu,fri');
        $list = is_array($v) ? $v : explode(',', (string) $v);
        return array_values(array_filter(array_map(static fn($d) => strtolower(trim((string) $d)), $list)));
    }
    /** @return string[] YYYY-MM-DD */
    public static function holidays(): array
    {
        $v = self::rule('gate.holidays', '');
        $list = is_array($v) ? $v : explode(',', (string) $v);
        return array_values(array_filter(array_map('trim', $list), static fn($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)));
    }
    public static function isProgrammeDay(string $day): bool
    {
        $t = strtotime($day . 'T12:00:00Z');
        if ($t === false) return false;
        return in_array(strtolower(gmdate('D', $t)), self::programmeDays(), true) && !in_array($day, self::holidays(), true);
    }

    /* ══ Who somebody is, to the gate ═══════════════════════════════════════ */

    private static function user(int $id): ?array
    {
        if ($id <= 0) return null;
        $st = Database::pdo()->prepare('SELECT id, name, email, role, status FROM lms_users WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    private static function participant(int $memberId): ?array
    {
        if (!class_exists('NgvDb')) return null;
        try {
            $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_participants WHERE member_id = ?');
            $st->execute([$memberId]);
            return $st->fetch() ?: null;
        } catch (Throwable $e) { return null; }
    }

    /** An active NGV participant: expected on programme days, and finable. */
    public static function expected(int $memberId): bool
    {
        $p = self::participant($memberId);
        return $p !== null && (string) $p['status'] === 'active';
    }

    /**
     * Why this member may not come in through the gate, in words for the
     * person at the desk — or null when they may.
     */
    public static function whyNot(?array $u): ?string
    {
        if (!$u) return 'Not an Afrovanguard member.';
        if ((string) ($u['status'] ?? 'active') !== 'active') return 'This account is suspended. Ask at the office.';
        if (class_exists('GatePass') && !in_array(strtolower((string) ($u['role'] ?? 'learner')), GatePass::roles(), true)) {
            return 'Gate passes are for Afrovanguard members.';
        }
        $overdue = self::overdueFine((int) $u['id']);
        if ($overdue > 0) {
            return 'A fine of ₦' . number_format($overdue) . ' has been unpaid for more than ' . self::blockOverdueDays() . ' days. Settle it with the NGV office to come in.';
        }
        return null;
    }

    /**
     * How much of this participant's fines is older than the overdue limit
     * and still unpaid. Payments settle the oldest fines first, so whatever is
     * owed beyond the fines raised inside the window is owed on older ones.
     */
    public static function overdueFine(int $memberId): int
    {
        $days = self::blockOverdueDays();
        if ($days <= 0 || !class_exists('NgvLedger') || !self::participant($memberId)) return 0;
        try {
            $b = NgvLedger::balance($memberId);
            $due = (int) ($b['chargedBy']['fine'] ?? 0) - (int) ($b['paidBy']['fine'] ?? 0) - (int) ($b['unallocated'] ?? 0);
            if ($due <= 0) return 0;
            $cut = gmdate('Y-m-d H:i:s', time() - $days * 86400);
            $recent = 0;
            foreach (NgvLedger::charges($memberId) as $c) {
                if ((string) $c['kind'] !== 'fine') continue;
                $at = str_replace('T', ' ', substr((string) $c['created_at'], 0, 19));
                if ($at > $cut) $recent += (int) $c['amount'];
            }
            return max(0, $due - $recent);
        } catch (Throwable $e) {
            error_log('[gate] overdueFine: ' . $e->getMessage());
            return 0;
        }
    }

    /** What the gate is told about a member: the least a desk needs. */
    public static function personFor(array $u): array
    {
        $why = self::whyNot($u);
        $p = self::participant((int) $u['id']);
        $detail = $p ? trim('NextGen Vanguard' . ((string) $p['track'] !== '' ? ' · ' . $p['track'] : '')) : ucfirst(strtolower((string) $u['role']));
        return ['ref' => (string) (int) $u['id'], 'name' => (string) $u['name'], 'kind' => 'member',
                'status' => $why === null ? 'active' : $why, 'detail' => $detail];
    }

    public static function resolveCard(string $code): array
    {
        $code = strtoupper(trim($code));
        if (!preg_match(self::CARD_PATTERN, $code)) return ['ok' => false, 'code' => 'unknown', 'error' => 'That is not an Afrovanguard member card.'];
        self::ensure();
        $st = Database::pdo()->prepare('SELECT member_id, status FROM gate_member_cards WHERE code = ?');
        $st->execute([$code]);
        $c = $st->fetch();
        if (!$c) return ['ok' => false, 'code' => 'unknown', 'error' => 'Afrovanguard has no member card ' . $code . '.'];
        if ((string) $c['status'] !== 'active') return ['ok' => false, 'code' => 'void_card', 'error' => 'Card ' . $code . ' has been replaced. Use the newer card or the gate pass.'];
        $u = self::user((int) $c['member_id']);
        if (!$u) return ['ok' => false, 'code' => 'unknown', 'error' => 'This card’s holder is no longer a member.'];
        return ['ok' => true, 'person' => self::personFor($u)];
    }

    public static function resolveRef(string $ref): array
    {
        $u = ctype_digit($ref) ? self::user((int) $ref) : null;
        return $u ? ['ok' => true, 'person' => self::personFor($u)] : ['ok' => false, 'code' => 'unknown', 'error' => 'Afrovanguard does not know member ' . $ref . '.'];
    }

    /** Find members by name, for one who left card and phone at home. Eight at most; nobody who may not come in. */
    public static function search(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        /* Wildcards dropped rather than escaped: LIKE escaping differs between
           SQLite, MySQL and Postgres, and nobody's name needs a % in it. */
        $like = '%' . str_replace(['%', '_', '\\'], '', mb_strtolower($q)) . '%';
        $st = Database::pdo()->prepare("SELECT id, name, email, role, status FROM lms_users WHERE status = 'active' AND LOWER(name) LIKE ? ORDER BY name LIMIT 25");
        $st->execute([$like]);
        $out = [];
        foreach ($st->fetchAll() as $u) {
            $p = self::personFor($u);
            if ($p['status'] === 'active') $out[] = $p;
            if (count($out) >= 8) break;
        }
        return $out;
    }

    /* ══ Member ID cards ═════════════════════════════════════════════════════ */

    public static function cardFor(int $memberId): ?string
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT code FROM gate_member_cards WHERE member_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $st->execute([$memberId]);
        $c = $st->fetchColumn();
        return $c === false ? null : (string) $c;
    }

    /**
     * Give a member an ID card number: the one printed on a card they already
     * hold (cards from the spreadsheet days keep working), or the next free one
     * when $code is empty. Any earlier card of theirs stops working.
     */
    public static function assignCard(int $memberId, string $code, int $by): array
    {
        self::ensure();
        if (!self::user($memberId)) return ['ok' => false, 'error' => 'No such member.'];
        $code = strtoupper(trim($code));
        if ($code === '') $code = self::nextCode();
        if (!preg_match(self::CARD_PATTERN, $code)) return ['ok' => false, 'error' => 'A member card reads like A-NGV-25-0001.'];
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT member_id, status FROM gate_member_cards WHERE code = ?');
        $st->execute([$code]);
        $held = $st->fetch();
        if ($held && (int) $held['member_id'] !== $memberId) return ['ok' => false, 'error' => $code . ' belongs to somebody else.'];
        if ($held && (string) $held['status'] === 'active') return ['ok' => true, 'code' => $code, 'already' => true];
        $pdo->prepare("UPDATE gate_member_cards SET status = 'void' WHERE member_id = ? AND status = 'active'")->execute([$memberId]);
        if ($held) $pdo->prepare("UPDATE gate_member_cards SET status = 'active' WHERE code = ?")->execute([$code]);
        else $pdo->prepare('INSERT INTO gate_member_cards (member_id, code, status, issued_by, created_at) VALUES (?, ?, ?, ?, ?)')
                 ->execute([$memberId, $code, 'active', $by, self::now()]);
        self::audit('gate.card', $memberId, 'Member card ' . $code);
        return ['ok' => true, 'code' => $code];
    }

    private static function nextCode(): string
    {
        $yy = gmdate('y');
        $st = Database::pdo()->prepare('SELECT code FROM gate_member_cards WHERE code LIKE ?');
        $st->execute(['A-NGV-' . $yy . '-%']);
        $max = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $max = max($max, (int) substr((string) $c, -4));
        return sprintf('A-NGV-%s-%04d', $yy, $max + 1);
    }

    /* ══ Passages from the gate ══════════════════════════════════════════════ */

    /**
     * Passages the gate reports. One result per passage, in order, with the
     * passage id: `recorded`, `duplicate` (delivered before — the gate stops),
     * `unknown` (not a member — the gate stops), `not_checked_in` (a
     * departure ahead of its arrival — the gate tries again), `bad_event`.
     */
    public static function report(array $passages): array
    {
        self::ensure();
        $out = [];
        foreach (array_slice($passages, 0, 200) as $p) {
            $id = is_array($p) ? substr((string) ($p['id'] ?? ''), 0, 64) : '';
            try {
                $out[] = ['id' => $id, 'status' => is_array($p) ? self::one($p) : 'bad_event'];
            } catch (Throwable $e) {
                error_log('[gate] report: ' . $e->getMessage());
                $out[] = ['id' => $id, 'status' => 'retry'];
            }
        }
        return $out;
    }

    private static function one(array $p): string
    {
        $id = substr((string) ($p['id'] ?? ''), 0, 64);
        $day = (string) ($p['day'] ?? '');
        $ref = (string) ($p['ref'] ?? '');
        $action = (string) ($p['action'] ?? 'in');
        if ($id === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !in_array($action, ['in', 'out'], true)) return 'bad_event';
        if (!ctype_digit($ref) || !self::user((int) $ref)) return 'unknown';
        $mid = (int) $ref;
        $at = (string) ($p['at'] ?? self::now());
        $pdo = Database::pdo();
        $row = self::row($mid, $day);

        if ($action === 'out') {
            if (!$row || (in_array((string) $row['status'], ['absent', 'excused'], true) && (string) $row['in_at'] === '')) return 'not_checked_in';
            if ((string) $row['gate_out_id'] === $id || (string) $row['out_at'] !== '') return 'duplicate';
            $pdo->prepare('UPDATE gate_attendance SET out_at = ?, gate_out_id = ?, updated_at = ? WHERE id = ?')->execute([$at, $id, self::now(), $row['id']]);
            return 'recorded';
        }

        $status = (string) ($p['status'] ?? '') === 'late' ? 'late' : 'present';
        $late = $status === 'late' ? max(0, (int) ($p['late_minutes'] ?? 0)) : 0;
        $vals = [$status, $late, $at, mb_substr((string) ($p['centre'] ?? ''), 0, 60), mb_substr((string) ($p['desk'] ?? ''), 0, 40), mb_substr((string) ($p['method'] ?? ''), 0, 16), $id];
        if ($row) {
            if ((string) $row['gate_in_id'] === $id || (string) $row['in_at'] !== '') return 'duplicate';
            /* Marked absent (or excused) before the arrival reached us — a
               delivery held up by an outage. The arrival is the truth, and an
               absence fine for a day they were in is voided. */
            $pdo->prepare('UPDATE gate_attendance SET status = ?, late_minutes = ?, in_at = ?, centre = ?, desk = ?, method = ?, gate_in_id = ?, note = ?, updated_at = ? WHERE id = ?')
                ->execute(array_merge($vals, ['', self::now(), $row['id']]));
            if ((int) $row['fine_id'] > 0) self::voidFine((int) $row['fine_id'], 'The gate recorded them in that day.');
            $pdo->prepare('UPDATE gate_attendance SET fine_id = 0 WHERE id = ?')->execute([$row['id']]);
        } else {
            $sql = Database::insertIgnore('gate_attendance', ['member_id', 'day', 'status', 'late_minutes', 'in_at', 'centre', 'desk', 'method', 'gate_in_id', 'created_at', 'updated_at']);
            $pdo->prepare($sql)->execute(array_merge([$mid, $day], $vals, [self::now(), self::now()]));
            $row = self::row($mid, $day);
            if (!$row || (string) $row['gate_in_id'] !== $id) return 'duplicate';   // a racing delivery got there first
        }
        if ($status === 'late') self::fineLate($mid, $day, $late);
        else self::award($mid, $day);
        return 'recorded';
    }

    private static function row(int $memberId, string $day): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM gate_attendance WHERE member_id = ? AND day = ?');
        $st->execute([$memberId, $day]);
        return $st->fetch() ?: null;
    }

    /* ══ Consequences ════════════════════════════════════════════════════════ */

    private static function fineLate(int $memberId, string $day, int $minutes): void
    {
        /* On probation the probation figure, where one is set; otherwise the
           ordinary one. The note says which, so the member reading their
           account can see why this fine is bigger than a friend's. */
        $prob = self::onProbation($memberId, $day);
        $amount = $prob && self::lateFineProbation() > 0 ? self::lateFineProbation() : self::lateFine();
        if ($amount <= 0 || !class_exists('NgvLedger') || !self::expected($memberId)) return;
        $fid = NgvLedger::postCharge($memberId, 'fine', $amount, 'late:' . $day,
            ['reason' => 'late', 'note' => 'Late arrival' . ($prob && self::lateFineProbation() > 0 ? ' on probation' : '') . ' — ' . $day . ', ' . $minutes . ' min (CACENTRE gate)', 'source' => 'accrual']);
        if ($fid) Database::pdo()->prepare('UPDATE gate_attendance SET fine_id = ? WHERE member_id = ? AND day = ?')->execute([$fid, $memberId, $day]);
    }

    private static function voidFine(int $chargeId, string $why): void
    {
        if ($chargeId <= 0 || !class_exists('NgvLedger')) return;
        try { NgvLedger::void('charge', $chargeId, $why, 0); } catch (Throwable $e) { error_log('[gate] voidFine: ' . $e->getMessage()); }
    }

    /**
     * Mark the absences of every programme day since the last sweep, up to
     * yesterday: an active NGV participant with no passage and no approved
     * excuse. A pending excuse holds the fine back until it is decided. Catches
     * up a fortnight at most per run, so a long outage is one bounded write.
     */
    public static function sweep(?string $asOf = null): array
    {
        self::ensure();
        if (!self::marksAbsent()) return ['ok' => true, 'off' => true, 'days' => 0, 'absences' => 0];
        $today = $asOf ?? self::today();
        $through = date('Y-m-d', strtotime($today . ' -1 day'));
        $from = (string) (Database::metaGet(self::SWEPT_META) ?? '');
        $from = $from !== '' ? date('Y-m-d', strtotime($from . ' +1 day')) : $through;
        if ($from > $through) return ['ok' => true, 'days' => 0, 'absences' => 0];
        $days = [];
        for ($d = $from; $d <= $through && count($days) < self::SWEEP_MAX_DAYS; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $days[] = $d;
        $members = [];
        try { $members = array_map('intval', NgvDb::pdo()->query("SELECT member_id FROM ngv_participants WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN)); }
        catch (Throwable $e) { error_log('[gate] sweep: ' . $e->getMessage()); }
        $pdo = Database::pdo(); $n = 0;
        foreach ($days as $d) {
            if (!self::isProgrammeDay($d)) continue;
            foreach ($members as $mid) {
                if (self::row($mid, $d)) continue;
                $ex = self::excuse($mid, $d);
                $status = $ex && (string) $ex['status'] === 'approved' ? 'excused' : 'absent';
                $pdo->prepare(Database::insertIgnore('gate_attendance', ['member_id', 'day', 'status', 'note', 'created_at', 'updated_at']))
                    ->execute([$mid, $d, $status, $status === 'excused' ? 'Excused' : 'No passage on a programme day', self::now(), self::now()]);
                if ($status === 'absent') { $n++; if (!$ex) self::fineAbsent($mid, $d); }
            }
        }
        Database::metaSet(self::SWEPT_META, end($days));
        return ['ok' => true, 'days' => count($days), 'absences' => $n, 'through' => end($days)];
    }

    private static function fineAbsent(int $memberId, string $day): void
    {
        $amount = self::absentFine();
        if ($amount <= 0 || !class_exists('NgvLedger')) return;
        $fid = NgvLedger::postCharge($memberId, 'fine', $amount, 'absent:' . $day,
            ['reason' => 'absent', 'note' => 'Absent without notice — ' . $day, 'source' => 'accrual']);
        if ($fid) Database::pdo()->prepare('UPDATE gate_attendance SET fine_id = ? WHERE member_id = ? AND day = ?')->execute([$fid, $memberId, $day]);
    }

    /* ══ Excuses ═════════════════════════════════════════════════════════════ */

    private static function excuse(int $memberId, string $day): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM gate_excuses WHERE member_id = ? AND day = ?');
        $st->execute([$memberId, $day]);
        return $st->fetch() ?: null;
    }

    /** A member says they will be, or were, away. A message, never a decision. */
    public static function requestExcuse(int $memberId, string $day, string $reason): array
    {
        self::ensure();
        $reason = trim($reason);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || strtotime($day) === false) return ['ok' => false, 'error' => 'Choose the day.'];
        if (mb_strlen($reason) < 3) return ['ok' => false, 'error' => 'Say why, in a few words.'];
        if ($day < date('Y-m-d', strtotime(self::today() . ' -14 days'))) return ['ok' => false, 'error' => 'That day is more than two weeks ago. Speak to the NGV office.'];
        if ($day > date('Y-m-d', strtotime(self::today() . ' +90 days'))) return ['ok' => false, 'error' => 'That is too far ahead.'];
        $row = self::row($memberId, $day);
        if ($row && in_array((string) $row['status'], ['present', 'late'], true)) return ['ok' => false, 'error' => 'The gate has you in that day.'];
        if (self::excuse($memberId, $day)) return ['ok' => false, 'error' => 'You have already asked about that day.'];
        Database::pdo()->prepare('INSERT INTO gate_excuses (member_id, day, reason, status, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$memberId, $day, mb_substr($reason, 0, 300), 'pending', self::now()]);
        return ['ok' => true];
    }

    /** Staff decide. Approving excuses the day and voids an absence fine on it; declining charges one that was held back. */
    public static function decideExcuse(int $id, bool $approve, string $outcome, int $by): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM gate_excuses WHERE id = ?');
        $st->execute([$id]);
        $ex = $st->fetch();
        if (!$ex) return ['ok' => false, 'error' => 'No such request.'];
        if ((string) $ex['status'] !== 'pending') return ['ok' => false, 'error' => 'That request has already been decided.'];
        $pdo->prepare('UPDATE gate_excuses SET status = ?, decided_by = ?, decided_at = ?, outcome = ? WHERE id = ?')
            ->execute([$approve ? 'approved' : 'declined', $by, self::now(), mb_substr(trim($outcome), 0, 300), $id]);
        $mid = (int) $ex['member_id']; $day = (string) $ex['day'];
        $row = self::row($mid, $day);
        if ($approve && $row && (string) $row['status'] === 'absent') {
            if ((int) $row['fine_id'] > 0) self::voidFine((int) $row['fine_id'], 'Day excused.');
            $pdo->prepare("UPDATE gate_attendance SET status = 'excused', fine_id = 0, note = 'Excused', updated_at = ? WHERE id = ?")->execute([self::now(), $row['id']]);
        } elseif (!$approve && $row && (string) $row['status'] === 'absent' && (int) $row['fine_id'] === 0) {
            self::fineAbsent($mid, $day);
        }
        self::audit('gate.excuse', $mid, ($approve ? 'Excused ' : 'Declined excuse for ') . $day);
        return ['ok' => true];
    }

    /** Staff excuse a day directly, for a member who told them in person. */
    public static function excuseDay(int $memberId, string $day, string $why, int $by): array
    {
        self::ensure();
        if (!self::excuse($memberId, $day)) {
            Database::pdo()->prepare('INSERT INTO gate_excuses (member_id, day, reason, status, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$memberId, $day, mb_substr(trim($why) ?: 'Excused by staff', 0, 300), 'pending', self::now()]);
        }
        $ex = self::excuse($memberId, $day);
        return (string) $ex['status'] === 'pending' ? self::decideExcuse((int) $ex['id'], true, $why, $by) : ['ok' => true, 'already' => true];
    }

    public static function excusesFor(int $memberId): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM gate_excuses WHERE member_id = ? ORDER BY day DESC LIMIT 30');
        $st->execute([$memberId]);
        return $st->fetchAll();
    }

    public static function pendingExcuses(): array
    {
        self::ensure();
        return Database::pdo()->query("SELECT e.*, u.name FROM gate_excuses e LEFT JOIN lms_users u ON u.id = e.member_id WHERE e.status = 'pending' ORDER BY e.day LIMIT 200")->fetchAll();
    }

    /* ══ Probation ═══════════════════════════════════════════════════════════ */

    /**
     * On probation on a day: put there by name (from `since`, until `until`
     * when one is set), or at a level the rules name — O, the first level, as
     * the spreadsheet had it.
     */
    public static function onProbation(int $memberId, ?string $day = null): bool
    {
        return self::probationWhy($memberId, $day) !== null;
    }

    /** Why a member is on probation, in words — or null. */
    public static function probationWhy(int $memberId, ?string $day = null): ?string
    {
        self::ensure();
        $day = $day ?? self::today();
        $p = self::probationFor($memberId);
        if ($p && (string) $p['since'] <= $day && ((string) $p['until'] === '' || (string) $p['until'] >= $day)) {
            return 'On probation' . ((string) $p['until'] !== '' ? ' until ' . $p['until'] : '') . ((string) $p['reason'] !== '' ? ' — ' . $p['reason'] : '');
        }
        $levels = self::probationLevels();
        if ($levels && class_exists('Levels')) {
            try {
                $lv = Levels::of($memberId);
                if (in_array(strtoupper($lv), $levels, true)) return 'On probation at level ' . $lv;
            } catch (Throwable $e) { /* no levels: nobody by level */ }
        }
        return null;
    }

    public static function probationFor(int $memberId): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT * FROM gate_probation WHERE member_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $st->execute([$memberId]);
        return $st->fetch() ?: null;
    }

    /** Put a member on probation by name, from today, until a day or until lifted. */
    public static function setProbation(int $memberId, string $until, string $reason, int $by): array
    {
        self::ensure();
        if (!self::user($memberId)) return ['ok' => false, 'error' => 'No such member.'];
        $until = trim($until);
        if ($until !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until < self::today())) return ['ok' => false, 'error' => 'The end date must be today or later — or leave it empty.'];
        if (mb_strlen(trim($reason)) < 3) return ['ok' => false, 'error' => 'Say why — the member sees it.'];
        $pdo = Database::pdo();
        $pdo->prepare("UPDATE gate_probation SET status = 'lifted', lifted_by = ? WHERE member_id = ? AND status = 'active'")->execute([$by, $memberId]);
        $pdo->prepare('INSERT INTO gate_probation (member_id, since, until, reason, status, set_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$memberId, self::today(), $until, mb_substr(trim($reason), 0, 300), 'active', $by, self::now()]);
        self::audit('gate.probation', $memberId, 'On probation' . ($until !== '' ? ' until ' . $until : '') . ': ' . trim($reason));
        return ['ok' => true];
    }

    public static function liftProbation(int $memberId, int $by): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("UPDATE gate_probation SET status = 'lifted', lifted_by = ? WHERE member_id = ? AND status = 'active'");
        $st->execute([$by, $memberId]);
        if ($st->rowCount() === 0) return ['ok' => false, 'error' => 'Not on probation by name.' . (self::probationWhy($memberId) ? ' Their level puts them on probation — that is changed by promoting them.' : '')];
        self::audit('gate.probation', $memberId, 'Probation lifted');
        return ['ok' => true];
    }

    /* ══ Points ══════════════════════════════════════════════════════════════ */

    /**
     * Points for an on-time arrival, as the spreadsheet gave them: for the
     * day, for a run of three and of five programme days on time, and for a
     * week with every programme day on time. Each is once per day, run or
     * week — the unique index sees to it — so a passage delivered twice, or a
     * week re-checked on every arrival in it, never pays twice. An excused day
     * neither breaks a run nor counts in it; a late or missed one breaks it.
     */
    public static function award(int $memberId, string $day): void
    {
        if (!self::isProgrammeDay($day)) return;
        $r = self::pointRules();
        if ($r['on_time'] > 0) self::give($memberId, $day, 'day:' . $day, $r['on_time'], 'On time');
        [$run, $start] = self::runEndingOn($memberId, $day);
        if ($run >= 3 && $r['streak3'] > 0) self::give($memberId, $day, 'run3:' . $start, $r['streak3'], 'Three days on time in a row');
        if ($run >= 5 && $r['streak5'] > 0) self::give($memberId, $day, 'run5:' . $start, $r['streak5'], 'Five days on time in a row');
        if ($r['week'] > 0 && self::perfectWeek($memberId, $day)) {
            $t = strtotime($day . 'T12:00:00Z');
            self::give($memberId, $day, 'week:' . gmdate('o-\WW', $t), $r['week'], 'A perfect week');
        }
    }

    private static function give(int $memberId, string $day, string $period, int $points, string $note): void
    {
        Database::pdo()->prepare(Database::insertIgnore('gate_points', ['member_id', 'day', 'period', 'points', 'note', 'created_at']))
            ->execute([$memberId, $day, $period, $points, $note, self::now()]);
    }

    /** @return array<string,string> day => status, for a span */
    private static function statuses(int $memberId, string $from, string $to): array
    {
        $st = Database::pdo()->prepare('SELECT day, status FROM gate_attendance WHERE member_id = ? AND day BETWEEN ? AND ?');
        $st->execute([$memberId, $from, $to]);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[(string) $r['day']] = (string) $r['status'];
        return $out;
    }

    /** How many programme days on time end on $day, and the first of them. @return array{0:int,1:string} */
    private static function runEndingOn(int $memberId, string $day): array
    {
        $from = date('Y-m-d', strtotime($day . ' -60 days'));
        $s = self::statuses($memberId, $from, $day);
        $n = 0; $start = $day;
        for ($d = $day; $d >= $from; $d = date('Y-m-d', strtotime($d . ' -1 day'))) {
            if (!self::isProgrammeDay($d)) continue;
            $v = $s[$d] ?? '';
            if ($v === 'excused') continue;
            if ($v !== 'present') break;
            $n++; $start = $d;
        }
        return [$n, $start];
    }

    /** Every programme day of $day's week on time (excused aside), with at least one of them. */
    private static function perfectWeek(int $memberId, string $day): bool
    {
        $t = strtotime($day . 'T12:00:00Z');
        $mon = gmdate('Y-m-d', $t - ((int) gmdate('N', $t) - 1) * 86400);
        $sun = date('Y-m-d', strtotime($mon . ' +6 days'));
        $s = self::statuses($memberId, $mon, $sun);
        $any = false;
        for ($d = $mon; $d <= $sun; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if (!self::isProgrammeDay($d)) continue;
            $v = $s[$d] ?? '';
            if ($v === 'excused') continue;
            if ($v !== 'present') return false;
            $any = true;
        }
        return $any;
    }

    /** A member's points: in all, this month, and the latest awards. */
    public static function points(int $memberId): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM gate_points WHERE member_id = ?');
        $st->execute([$memberId]);
        $total = (int) $st->fetchColumn();
        $st = $pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM gate_points WHERE member_id = ? AND day >= ?');
        $st->execute([$memberId, substr(self::today(), 0, 7) . '-01']);
        $month = (int) $st->fetchColumn();
        $st = $pdo->prepare('SELECT day, points, note FROM gate_points WHERE member_id = ? ORDER BY day DESC, id DESC LIMIT 8');
        $st->execute([$memberId]);
        return ['total' => $total, 'month' => $month, 'recent' => $st->fetchAll()];
    }

    /** The month's most points, for the staff register. */
    public static function leaderboard(?string $month = null, int $limit = 10): array
    {
        self::ensure();
        $m = $month ?? substr(self::today(), 0, 7);
        $st = Database::pdo()->prepare('SELECT p.member_id, u.name, SUM(p.points) AS points FROM gate_points p LEFT JOIN lms_users u ON u.id = p.member_id
                                        WHERE p.day BETWEEN ? AND ? GROUP BY p.member_id, u.name ORDER BY points DESC, u.name LIMIT ' . max(1, min(50, $limit)));
        $st->execute([$m . '-01', $m . '-31']);
        return $st->fetchAll();
    }

    /* ══ Reading it back ═════════════════════════════════════════════════════ */

    public static function history(int $memberId, int $days = 90): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM gate_attendance WHERE member_id = ? AND day >= ? ORDER BY day DESC');
        $st->execute([$memberId, date('Y-m-d', strtotime(self::today() . ' -' . max(1, $days) . ' days'))]);
        return $st->fetchAll();
    }

    /**
     * The numbers a member is shown about themselves. Excused days count for
     * nothing either way. The rate is days in over days counted; punctuality
     * is days on time over days counted; the grade is the spreadsheet's
     * (A+ 95, A 85, B 75, C 65, D 50) applied to punctuality.
     */
    public static function summary(int $memberId, int $days = 30): array
    {
        $rows = self::history($memberId, $days);
        $c = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0, 'late_minutes' => 0];
        foreach ($rows as $r) { $s = (string) $r['status']; if (isset($c[$s])) $c[$s]++; if ($s === 'late') $c['late_minutes'] += (int) $r['late_minutes']; }
        $counted = $c['present'] + $c['late'] + $c['absent'];
        $rate = $counted ? (int) round(100 * ($c['present'] + $c['late']) / $counted) : null;
        $punct = $counted ? (int) round(100 * $c['present'] / $counted) : null;
        $streak = 0;
        foreach ($rows as $r) { if ((string) $r['status'] === 'present') $streak++; elseif ((string) $r['status'] !== 'excused') break; }
        return $c + ['days' => $days, 'counted' => $counted, 'rate' => $rate, 'punctuality' => $punct,
                     'grade' => $punct === null ? null : self::grade($punct), 'streak' => $streak];
    }

    public static function grade(int $pct): string
    {
        foreach ([95 => 'A+', 85 => 'A', 75 => 'B', 65 => 'C', 50 => 'D'] as $min => $g) if ($pct >= $min) return $g;
        return 'F';
    }

    /** One day, for NGV staff: everybody recorded, and every expected participant not yet. */
    public static function register(string $day): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT a.*, u.name FROM gate_attendance a LEFT JOIN lms_users u ON u.id = a.member_id WHERE a.day = ? ORDER BY a.in_at, u.name');
        $st->execute([$day]);
        $rows = $st->fetchAll();
        $seen = array_map(static fn($r) => (int) $r['member_id'], $rows);
        $missing = [];
        try {
            foreach (NgvDb::pdo()->query("SELECT member_id, name FROM ngv_participants WHERE status = 'active' ORDER BY name")->fetchAll() as $p) {
                if (!in_array((int) $p['member_id'], $seen, true)) $missing[] = ['member_id' => (int) $p['member_id'], 'name' => (string) $p['name']];
            }
        } catch (Throwable $e) { /* no NGV database: nobody is expected */ }
        $n = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
        foreach ($rows as $r) if (isset($n[(string) $r['status']])) $n[(string) $r['status']]++;
        return ['day' => $day, 'programme_day' => self::isProgrammeDay($day), 'rows' => $rows, 'not_in' => $missing, 'counts' => $n];
    }

    private static function audit(string $action, int $memberId, string $what): void
    {
        try { if (class_exists('AdminAudit')) AdminAudit::log('attendance', $action, 'member:' . $memberId, $what); } catch (Throwable $e) { /* best effort */ }
    }
}
