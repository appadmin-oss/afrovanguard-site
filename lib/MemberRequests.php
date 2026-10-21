<?php
/**
 * lib/MemberRequests.php — a member asks, from their own portal, and an admin decides.
 *
 * Members could ask finance for money (lib/CfisRequests.php) but nothing
 * else: a member who moved to the Ayobo centre, changed their number, or
 * wanted to pause had to find an admin and ask them to type it. NGG lets a
 * member ask for these from their own record (api/_lib/domain/member-self.php);
 * this is the same here.
 *
 *   · The member asks in the portal → Requests. Nothing about their record
 *     changes until an admin approves.
 *   · An admin decides in the Studio → Members → Approvals, under "From
 *     members". Approved, a change of centre or of details is applied to the
 *     record by the member desk (MemberRoster::update) AS THE APPROVER, so
 *     the desk's checks run on it; a request with nothing to apply (pausing,
 *     anything else) is answered, and the admin acts on it.
 *   · Rejecting needs a reason, and the member sees it.
 *   · The member can withdraw a request nobody has decided.
 *   · A handful open at once, at most — a queue nobody can clear is no queue.
 */
declare(strict_types=1);

final class MemberRequests
{
    public const KINDS = [
        'centre'  => 'Move me to another centre',
        'details' => 'Correct my details',
        'pause'   => 'Pause or end my membership',
        'other'   => 'Something else',
    ];
    public const OPEN_MAX = 5;
    public const TEXT_MAX = 1000;

    public static function ensure(): void
    {
        static $done = false; if ($done) return; $done = true;
        Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS av_member_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL DEFAULT 0,
            kind VARCHAR(16) NOT NULL DEFAULT '',
            payload TEXT,
            message TEXT,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            decided_by VARCHAR(191) NOT NULL DEFAULT '',
            decided_at VARCHAR(32) NOT NULL DEFAULT '',
            reason VARCHAR(480) NOT NULL DEFAULT '',
            outcome VARCHAR(480) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    /**
     * A member asks.
     *
     * @param array{kind?:string,centre?:string,name?:string,phone?:string,message?:string} $in
     */
    public static function submit(int $userId, array $in): array
    {
        self::ensure();
        $u = MemberRoster::get($userId);
        if (!$u) return ['ok' => false, 'error' => 'Sign in as a member to ask.'];
        $kind = (string) ($in['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) return ['ok' => false, 'error' => 'Choose what you are asking for.'];
        $message = trim(mb_substr((string) ($in['message'] ?? ''), 0, self::TEXT_MAX));
        $payload = [];

        if ($kind === 'centre') {
            $want = Centres::canonical((string) ($in['centre'] ?? ''));
            if ($want === null || $want === '') return ['ok' => false, 'error' => 'Choose a centre from the list.'];
            if (mb_strtolower($want) === mb_strtolower((string) $u['centre'])) return ['ok' => false, 'error' => 'You are already at ' . $want . '.'];
            $payload['centre'] = $want;
        } elseif ($kind === 'details') {
            $patch = [];
            $name = trim(preg_replace('/\s+/', ' ', (string) ($in['name'] ?? '')) ?? '');
            if ($name !== '' && $name !== (string) $u['name']) $patch['name'] = $name;
            $phone = trim((string) ($in['phone'] ?? ''));
            if ($phone !== '' && MemberRoster::cleanPhone($phone) !== (string) $u['phone']) $patch['phone'] = $phone;
            if (!$patch && $message === '') return ['ok' => false, 'error' => 'Say what should change.'];
            if ($patch) {
                /* Checked now, as the desk would check it, so a request that
                   could never be applied is not left for somebody to approve. */
                $v = MemberRoster::validate(['name' => $patch['name'] ?? (string) $u['name'], 'email' => (string) $u['email']] + array_intersect_key($patch, ['phone' => 1]), $userId);
                if (!$v['ok']) return ['ok' => false, 'error' => reset($v['errors'])];
                if (isset($patch['phone'])) $patch['phone'] = $v['clean']['phone'];
            }
            $payload = $patch;
        } elseif ($message === '') {
            return ['ok' => false, 'error' => $kind === 'pause' ? 'Say whether you want to pause or to leave, and from when.' : 'Say what you are asking for.'];
        }

        $pdo = Database::pdo();
        $open = $pdo->prepare("SELECT COUNT(*) FROM av_member_requests WHERE user_id = ? AND status = 'pending'");
        $open->execute([$userId]);
        if ((int) $open->fetchColumn() >= self::OPEN_MAX) return ['ok' => false, 'error' => 'You already have ' . self::OPEN_MAX . ' requests waiting. Withdraw one, or wait for an answer.'];
        $same = $pdo->prepare("SELECT id FROM av_member_requests WHERE user_id = ? AND kind = ? AND status = 'pending' AND payload = ?");
        $same->execute([$userId, $kind, (string) json_encode($payload)]);
        if ($kind !== 'other' && $same->fetchColumn()) return ['ok' => false, 'error' => 'You have already asked for this. It is waiting for an admin.'];

        $pdo->prepare('INSERT INTO av_member_requests (user_id, kind, payload, message, status, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, $kind, (string) json_encode($payload), $message, 'pending', self::now()]);
        $id = (int) $pdo->lastInsertId();
        self::audit('member_request.asked', $id, self::summary($kind, $payload, $message, (string) $u['name']), (string) $u['email']);
        self::tellAdmins($id, self::summary($kind, $payload, $message, (string) $u['name']));
        return ['ok' => true, 'id' => $id];
    }

    /** The request in a sentence, for whoever decides it. */
    public static function summary(string $kind, array $payload, string $message, string $who): string
    {
        $what = match ($kind) {
            'centre' => 'move to ' . ($payload['centre'] ?? '?'),
            'details' => $payload ? 'change ' . implode(' and ', array_map(static fn($k, $v) => $k . ' to “' . $v . '”', array_keys($payload), $payload)) : 'correct their details',
            'pause' => 'pause or end their membership',
            default => 'something else',
        };
        return $who . ' asks to ' . $what . ($message !== '' ? ' — “' . mb_substr($message, 0, 160) . '”' : '');
    }

    private static function shape(array $r, ?array $u = null): array
    {
        $payload = json_decode((string) $r['payload'], true) ?: [];
        return [
            'id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'kind_label' => self::KINDS[$r['kind']] ?? $r['kind'],
            'payload' => $payload, 'message' => (string) $r['message'], 'status' => (string) $r['status'],
            'reason' => (string) $r['reason'], 'outcome' => (string) $r['outcome'], 'decided_at' => (string) $r['decided_at'],
            'created_at' => (string) $r['created_at'],
        ] + ($u ? ['member' => ['id' => (int) $u['id'], 'name' => (string) $u['name'], 'email' => (string) $u['email'], 'centre' => (string) $u['centre']],
                   'summary' => self::summary((string) $r['kind'], $payload, (string) $r['message'], (string) $u['name'])] : []);
    }

    /** A member's own requests, newest first. */
    public static function mine(int $userId): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM av_member_requests WHERE user_id = ? ORDER BY id DESC LIMIT 50');
        $st->execute([$userId]);
        return array_map(static fn($r) => self::shape($r), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function pendingCount(): int
    {
        self::ensure();
        return (int) Database::pdo()->query("SELECT COUNT(*) FROM av_member_requests WHERE status = 'pending'")->fetchColumn();
    }

    /** For the Studio: what members have asked, with who asked. */
    public static function queue(string $status = 'pending'): array
    {
        self::ensure();
        $ok = ['pending', 'approved', 'rejected', 'withdrawn', 'all'];
        if (!in_array($status, $ok, true)) $status = 'pending';
        $pdo = Database::pdo();
        $st = $status === 'all' ? $pdo->query('SELECT * FROM av_member_requests ORDER BY id DESC LIMIT 200')
                                : $pdo->prepare('SELECT * FROM av_member_requests WHERE status = ? ORDER BY id DESC LIMIT 200');
        if ($status !== 'all') $st->execute([$status]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $u = MemberRoster::get((int) $r['user_id']);
            if ($u) $out[] = self::shape($r, $u);
        }
        return ['ok' => true, 'requests' => $out, 'pending' => self::pendingCount()];
    }

    /** The member changes their mind. */
    public static function withdraw(int $userId, int $id): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("UPDATE av_member_requests SET status = 'withdrawn', decided_at = ? WHERE id = ? AND user_id = ? AND status = 'pending'");
        $st->execute([self::now(), $id, $userId]);
        return $st->rowCount() ? ['ok' => true] : ['ok' => false, 'error' => 'Only a request still waiting can be withdrawn.'];
    }

    /**
     * An admin decides. Approved, a change of centre or details is applied by
     * the member desk as the approver; anything else is answered, and acted on
     * by the admin.
     *
     * @param array{studio_role:string,self_id:int} $ctx the approver
     */
    public static function decide(int $id, string $decision, string $reason, string $actor, array $ctx): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM av_member_requests WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['ok' => false, 'error' => 'No such request.'];
        if ($r['status'] !== 'pending') return ['ok' => false, 'code' => 'already_decided', 'error' => 'This request is already ' . $r['status'] . '.'];
        if ((int) $r['user_id'] === (int) ($ctx['self_id'] ?? 0)) return ['ok' => false, 'code' => 'own_request', 'error' => 'Another admin decides your own request.'];
        $reason = mb_substr(trim($reason), 0, 480);
        if (!in_array($decision, ['approve', 'reject'], true)) return ['ok' => false, 'error' => 'Approve or reject.'];
        if ($decision === 'reject' && $reason === '') return ['ok' => false, 'code' => 'reason_required', 'error' => 'Say why — the member sees it.'];

        /* Claimed first, so it is decided once. */
        $claim = $pdo->prepare("UPDATE av_member_requests SET status = 'deciding', decided_by = ?, decided_at = ? WHERE id = ? AND status = 'pending'");
        $claim->execute([$actor, self::now(), $id]);
        if ($claim->rowCount() < 1) return ['ok' => false, 'code' => 'already_decided', 'error' => 'Somebody decided it a moment ago.'];

        $payload = json_decode((string) $r['payload'], true) ?: [];
        $status = $decision === 'reject' ? 'rejected' : 'approved';
        $outcome = '';
        if ($decision === 'approve' && in_array($r['kind'], ['centre', 'details'], true) && $payload) {
            $res = MemberRoster::update((int) $r['user_id'], $payload, $actor . ' (member request #' . $id . ')', $ctx);
            if (!$res['ok']) {
                /* It could not be applied — the centre was retired since, say.
                   Back to waiting, with the reason, so nobody thinks it is done. */
                $pdo->prepare("UPDATE av_member_requests SET status = 'pending', decided_by = '', decided_at = '' WHERE id = ?")->execute([$id]);
                return ['ok' => false, 'error' => 'It could not be applied: ' . ($res['error'] ?? 'refused') . ' Nothing was changed.'];
            }
            $outcome = $res['changed'] ? 'Changed: ' . implode(', ', $res['changed']) : 'Already so';
        } elseif ($decision === 'approve') {
            $outcome = 'Agreed — an admin acts on it';
        }
        $pdo->prepare('UPDATE av_member_requests SET status = ?, reason = ?, outcome = ? WHERE id = ?')->execute([$status, $reason, $outcome, $id]);
        $u = MemberRoster::get((int) $r['user_id']);
        $summary = self::summary((string) $r['kind'], $payload, (string) $r['message'], (string) ($u['name'] ?? 'A member'));
        self::audit('member_request.' . $status, $id, $summary . ($reason !== '' ? ' — ' . $reason : ''), $actor);
        if ($u) self::tellMember($u, $summary, $status, $reason, $outcome);
        return ['ok' => true, 'status' => $status, 'outcome' => $outcome];
    }

    /* ── telling people ─────────────────────────────────────────────── */

    private static function tellAdmins(int $id, string $summary): void
    {
        if (!class_exists('Mailer') || !class_exists('AdminRoles')) return;
        $link = (defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '') . '/admin/#members';
        foreach (AdminRoles::list() as $a) {
            if (empty($a['valid']) || !in_array((string) $a['role'], ['admin', 'superadmin'], true)) continue;
            try {
                Mailer::send((string) $a['email'], 'A member asks: ' . mb_substr($summary, 0, 80),
                    '<p><strong>' . htmlspecialchars($summary) . '</strong></p><p>Approve or reject it in the Studio → Members → Approvals: <a href="'
                    . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>');
            } catch (Throwable $e) { error_log('[member-requests] mail: ' . $e->getMessage()); }
        }
    }

    private static function tellMember(array $u, string $summary, string $status, string $reason, string $outcome): void
    {
        if (!class_exists('Mailer') || !filter_var((string) $u['email'], FILTER_VALIDATE_EMAIL)) return;
        try {
            Mailer::send((string) $u['email'], ($status === 'approved' ? 'Your request was approved' : 'Your request was not approved'),
                '<p>' . htmlspecialchars($summary) . '</p>'
                . ($status === 'approved' ? '<p>' . htmlspecialchars($outcome !== '' ? $outcome : 'Approved.') . '</p>' : '<p>Why: ' . htmlspecialchars($reason) . '</p>')
                . '<p>You can see your requests in your portal → Requests.</p>');
        } catch (Throwable $e) { error_log('[member-requests] mail: ' . $e->getMessage()); }
    }

    private static function audit(string $action, int $id, string $detail, string $actor): void
    {
        try { if (class_exists('AdminAudit')) AdminAudit::log('members', $action, 'member_request#' . $id, mb_substr($detail, 0, 900), null, $actor); }
        catch (Throwable $e) {}
    }
}
