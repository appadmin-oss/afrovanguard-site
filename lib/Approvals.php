<?php
/**
 * lib/Approvals.php — an admin's sensitive member actions wait for a second person.
 *
 * NGG queues a club admin's acts that take somebody's access away
 * (api/_lib/domain/approvals.php); this is the same rule for the Studio. An
 * admin who suspends members, or ends their dues in a crowd, is not refused —
 * the request is KEPT, exactly as it was made and by whom, and runs only when
 * somebody else approves it. A Super Admin's acts run at once, as NGG's full
 * admins' do.
 *
 *   · One seam: admin/api.php asks intercept() before the member desk's write
 *     actions, so a sensitive act cannot reach around the queue by another
 *     screen.
 *   · Two people, always. The approver is a Super Admin or another admin; the
 *     requester never approves their own.
 *   · On approval the act runs AS THE REQUESTER: the desk's own checks (your
 *     own record, staff accounts, the role ceiling) still apply to the person
 *     who asked, and the approver is named in the audit.
 *   · Claimed before it runs, so two approvers pressing at once run it once.
 *   · A request nobody decided in EXPIRE_DAYS expires rather than waiting to
 *     surprise somebody months later.
 */
declare(strict_types=1);

final class Approvals
{
    public const EXPIRE_DAYS = 14;
    public const STATUSES = ['pending', 'running', 'approved', 'rejected', 'failed', 'withdrawn', 'expired'];

    /** Whose acts wait: the Studio roles that are not trusted to act alone. */
    public const BOUND_ROLES = ['admin'];

    private static bool $running = false;

    public static function ensure(): void
    {
        static $done = false; if ($done) return; $done = true;
        Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS av_approvals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            op VARCHAR(40) NOT NULL DEFAULT '',
            args TEXT NOT NULL,
            summary VARCHAR(480) NOT NULL DEFAULT '',
            requester VARCHAR(191) NOT NULL DEFAULT '',
            requester_role VARCHAR(20) NOT NULL DEFAULT '',
            requester_id INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            decided_by VARCHAR(191) NOT NULL DEFAULT '',
            decided_at VARCHAR(32) NOT NULL DEFAULT '',
            reason VARCHAR(480) NOT NULL DEFAULT '',
            result TEXT,
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    public static function bound(string $role): bool { return in_array($role, self::BOUND_ROLES, true); }

    /* ══ What is sensitive ═════════════════════════════════════════════════ */

    /** The members a request would actually change: not the requester, not staff (the desk refuses those itself). */
    private static function live(array $ids, int $selfId): array
    {
        $out = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            if ($id <= 0 || $id === $selfId) continue;
            $u = MemberRoster::get($id);
            if (!$u || MemberRoster::isStaff($u)) continue;
            $out[$id] = $u;
        }
        return $out;
    }

    private static function names(array $members): string
    {
        $n = array_map(static fn($u) => (string) $u['name'], array_values($members));
        return count($n) <= 3 ? implode(', ', $n) : implode(', ', array_slice($n, 0, 3)) . ' and ' . (count($n) - 3) . ' more';
    }

    /**
     * A sentence saying what $op with $args would do, when it is sensitive.
     * Null means it runs straight away: not sensitive, or nothing would change.
     */
    public static function summary(string $op, array $args, int $selfId): ?string
    {
        if ($op === 'roster_bulk') {
            $action = (string) ($args['action'] ?? '');
            $value = (array) ($args['value'] ?? []);
            $isSuspend = $action === 'status' && (string) ($value['status'] ?? '') === 'suspended';
            if (!$isSuspend && $action !== 'membership_cancel') return null;
            $live = self::live((array) ($args['ids'] ?? []), $selfId);
            if ($isSuspend) $live = array_filter($live, static fn($u) => (string) $u['status'] !== 'suspended');
            if (!$live) return null;
            $n = count($live);
            return $isSuspend
                ? 'Suspend ' . $n . ' member' . ($n === 1 ? '' : 's') . ': ' . self::names($live) . ' — they are signed out and their gate pass stops'
                : 'End the dues of ' . $n . ' member' . ($n === 1 ? '' : 's') . ': ' . self::names($live) . ' — “' . mb_substr(trim((string) ($value['note'] ?? '')), 0, 120) . '”';
        }
        if ($op === 'roster_update') {
            $live = self::live([(int) ($args['id'] ?? 0)], $selfId);
            $u = reset($live);
            if (!$u) return null;
            $says = [];
            if ((string) ($args['status'] ?? '') === 'suspended' && (string) $u['status'] !== 'suspended') {
                $says[] = 'Suspend ' . $u['name'] . ' (' . $u['email'] . ') — they are signed out and their gate pass stops';
            }
            /* An email address is who the account is: the organisation's Google, the
               Studio's admin list and every sign-in follow it. Moving it is a second
               person's decision, like a suspension. */
            $to = strtolower(trim((string) ($args['email'] ?? '')));
            if ($to !== '' && strcasecmp($to, (string) $u['email']) !== 0) {
                $says[] = 'Change the email of ' . $u['name'] . ' from ' . $u['email'] . ' to ' . $to . ' — they are signed out and must confirm the new address';
            }
            return $says ? implode('; ', $says) : null;
        }
        return null;
    }

    /**
     * Before a member-desk write runs. Null lets it run; otherwise the reply
     * for a request that has been kept for a second person instead.
     *
     * @param array{studio_role:string,self_id:int} $ctx
     */
    public static function intercept(string $op, array $args, array $ctx, string $actor): ?array
    {
        if (self::$running || !self::bound((string) ($ctx['studio_role'] ?? ''))) return null;
        $summary = self::summary($op, $args, (int) ($ctx['self_id'] ?? 0));
        if ($summary === null) return null;
        self::ensure();
        $keep = array_intersect_key($args, array_flip(['ids', 'action', 'value', 'id', 'name', 'email', 'phone', 'centre', 'role', 'status', 'level', 'birthday', 'joined_on', 'notes', 'work_roles', 'about']));
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO av_approvals (op, args, summary, requester, requester_role, requester_id, status, result, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$op, (string) json_encode($keep, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), mb_substr($summary, 0, 480),
                       mb_substr($actor, 0, 191), (string) $ctx['studio_role'], (int) ($ctx['self_id'] ?? 0), 'pending', '', self::now()]);
        $id = (int) $pdo->lastInsertId();
        self::audit('approval.requested', $id, $summary, $actor);
        self::tellApprovers($id, $summary, $actor);
        return ['ok' => false, 'code' => 'approval_pending', 'approval_id' => $id, 'summary' => $summary,
                'error' => 'Sent for approval. Nothing changes until a second admin approves it.'];
    }

    /* ══ The queue ═════════════════════════════════════════════════════════ */

    /** Requests nobody decided in time stop waiting. */
    private static function expire(): void
    {
        $cut = gmdate('Y-m-d H:i:s', time() - self::EXPIRE_DAYS * 86400);
        Database::pdo()->prepare("UPDATE av_approvals SET status = 'expired', decided_at = ? WHERE status = 'pending' AND created_at < ?")->execute([self::now(), $cut]);
    }

    private static function row(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM av_approvals WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function publicRow(array $r, string $me): array
    {
        $res = json_decode((string) ($r['result'] ?? ''), true);
        $mine = strcasecmp((string) $r['requester'], $me) === 0;
        return [
            'id' => (int) $r['id'], 'op' => (string) $r['op'], 'summary' => (string) $r['summary'], 'status' => (string) $r['status'],
            'requester' => (string) $r['requester'], 'mine' => $mine, 'created_at' => (string) $r['created_at'],
            'decided_by' => (string) $r['decided_by'], 'decided_at' => (string) $r['decided_at'], 'reason' => (string) $r['reason'],
            'outcome' => is_array($res) ? self::outcome($res) : '',
        ];
    }

    /** What happened when it ran, in a line. */
    private static function outcome(array $res): string
    {
        if (empty($res['ok'])) return 'Could not run: ' . (string) ($res['error'] ?? 'refused');
        if (isset($res['applied'])) return (int) $res['applied'] . ' changed · ' . (int) ($res['unchanged'] ?? 0) . ' already so · ' . (int) ($res['refused'] ?? 0) . ' refused';
        return 'Done';
    }

    /**
     * The queue: pending first, then the last decisions. Everybody on the desk
     * sees it; `can_decide` is true on a row only for somebody who may decide it.
     */
    public static function list(string $status, string $me): array
    {
        self::ensure();
        self::expire();
        if (!in_array($status, [...self::STATUSES, 'all'], true)) $status = 'pending';
        $pdo = Database::pdo();
        $st = $status === 'all'
            ? $pdo->query('SELECT * FROM av_approvals ORDER BY id DESC LIMIT 200')
            : $pdo->prepare('SELECT * FROM av_approvals WHERE status = ? ORDER BY id DESC LIMIT 200');
        if ($status !== 'all') $st->execute([$status]);
        $rows = array_map(fn($r) => self::publicRow($r, $me) + ['can_decide' => $r['status'] === 'pending' && strcasecmp((string) $r['requester'], $me) !== 0],
            $st->fetchAll(PDO::FETCH_ASSOC));
        return ['ok' => true, 'approvals' => $rows, 'pending' => self::pendingCount()];
    }

    public static function pendingCount(): int
    {
        self::ensure();
        return (int) Database::pdo()->query("SELECT COUNT(*) FROM av_approvals WHERE status = 'pending'")->fetchColumn();
    }

    /**
     * Approve (run it, as the requester) or reject it. Never the requester's
     * own: that is one person, and the point is two.
     *
     * @param callable(string $op, array $args, string $actor, array $ctx): array $run  how an op is run (admin/api.php)
     */
    public static function decide(int $id, string $decision, string $reason, string $me, callable $run): array
    {
        self::ensure();
        self::expire();
        $r = self::row($id);
        if (!$r) return ['ok' => false, 'error' => 'No such request.'];
        if ($r['status'] !== 'pending') return ['ok' => false, 'code' => 'already_decided', 'error' => 'This request is already ' . $r['status'] . '.'];
        if (strcasecmp((string) $r['requester'], $me) === 0) return ['ok' => false, 'code' => 'own_request', 'error' => 'Somebody else decides your own request. That is the point of asking.'];
        if (!in_array($decision, ['approve', 'reject'], true)) return ['ok' => false, 'error' => 'Approve or reject.'];
        $reason = mb_substr(trim($reason), 0, 480);
        $pdo = Database::pdo();
        if ($decision === 'reject') {
            if ($reason === '') return ['ok' => false, 'code' => 'reason_required', 'error' => 'Say why, so the person who asked knows.'];
            $pdo->prepare("UPDATE av_approvals SET status = 'rejected', decided_by = ?, decided_at = ?, reason = ? WHERE id = ? AND status = 'pending'")->execute([$me, self::now(), $reason, $id]);
            self::audit('approval.rejected', $id, $r['summary'] . ' — ' . $reason, $me);
            self::tellRequester($r, 'rejected', $reason, $me);
            return ['ok' => true, 'status' => 'rejected'];
        }
        /* Claimed first: two approvers pressing at once run it once. */
        $claim = $pdo->prepare("UPDATE av_approvals SET status = 'running', decided_by = ?, decided_at = ? WHERE id = ? AND status = 'pending'");
        $claim->execute([$me, self::now(), $id]);
        if ($claim->rowCount() < 1) return ['ok' => false, 'code' => 'already_decided', 'error' => 'Somebody decided it a moment ago.'];
        $args = json_decode((string) $r['args'], true);
        $args = is_array($args) ? $args : [];
        $ctx = ['studio_role' => (string) $r['requester_role'], 'self_id' => (int) $r['requester_id']];
        self::$running = true;
        try { $res = $run((string) $r['op'], $args, $r['requester'] . ' (approved by ' . $me . ')', $ctx); }
        catch (Throwable $e) { $res = ['ok' => false, 'error' => 'It failed while running: ' . $e->getMessage()]; }
        finally { self::$running = false; }
        $ok = !empty($res['ok']);
        $pdo->prepare('UPDATE av_approvals SET status = ?, reason = ?, result = ? WHERE id = ?')
            ->execute([$ok ? 'approved' : 'failed', $reason, (string) json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $id]);
        self::audit('approval.approved', $id, $r['summary'] . ' — ' . self::outcome($res), $me);
        self::tellRequester($r, $ok ? 'approved' : 'failed', $ok ? self::outcome($res) : (string) ($res['error'] ?? 'refused'), $me);
        return ['ok' => true, 'status' => $ok ? 'approved' : 'failed', 'outcome' => self::outcome($res), 'result' => $res];
    }

    /** The requester changes their mind before anybody decides. */
    public static function withdraw(int $id, string $me): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("UPDATE av_approvals SET status = 'withdrawn', decided_by = ?, decided_at = ? WHERE id = ? AND status = 'pending' AND LOWER(requester) = ?");
        $st->execute([$me, self::now(), $id, strtolower($me)]);
        if ($st->rowCount() < 1) return ['ok' => false, 'error' => 'Only a pending request of your own can be withdrawn.'];
        self::audit('approval.withdrawn', $id, '', $me);
        return ['ok' => true, 'status' => 'withdrawn'];
    }

    /* ══ Telling people ════════════════════════════════════════════════════ */

    /** Everybody who could decide it, by email. Best effort: the Studio badge is the record. */
    private static function tellApprovers(int $id, string $summary, string $requester): void
    {
        if (!class_exists('Mailer') || !class_exists('AdminRoles')) return;
        $link = (defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '') . '/admin/#members';
        foreach (AdminRoles::list() as $a) {
            $to = strtolower((string) $a['email']);
            if (empty($a['valid']) || $to === strtolower($requester) || !in_array((string) $a['role'], ['admin', 'superadmin'], true)) continue;
            try {
                Mailer::send($to, 'Waiting for your approval: ' . mb_substr($summary, 0, 80),
                    '<p>' . htmlspecialchars($requester) . ' asked for this, and it needs a second admin before it happens:</p>'
                    . '<p><strong>' . htmlspecialchars($summary) . '</strong></p>'
                    . '<p>Approve or reject it in the Studio → Members → Approvals: <a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>');
            } catch (Throwable $e) { error_log('[approvals] mail: ' . $e->getMessage()); }
        }
    }

    private static function tellRequester(array $r, string $outcome, string $why, string $by): void
    {
        $to = strtolower((string) $r['requester']);
        if (!class_exists('Mailer') || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
        $title = ['approved' => 'Approved and done', 'rejected' => 'Not approved', 'failed' => 'Approved, but it could not run'][$outcome] ?? 'Decided';
        try {
            Mailer::send($to, $title . ': ' . mb_substr((string) $r['summary'], 0, 80),
                '<p>' . htmlspecialchars($by) . ' looked at your request:</p><p><strong>' . htmlspecialchars((string) $r['summary']) . '</strong></p>'
                . ($why !== '' ? '<p>' . ($outcome === 'rejected' ? 'Why: ' : ($outcome === 'failed' ? 'What went wrong: ' : 'Result: ')) . htmlspecialchars($why) . '</p>' : ''));
        } catch (Throwable $e) { error_log('[approvals] mail: ' . $e->getMessage()); }
    }

    private static function audit(string $action, int $id, string $detail, string $actor): void
    {
        try { if (class_exists('AdminAudit')) AdminAudit::log('members', $action, 'approval#' . $id, mb_substr($detail, 0, 900), null, $actor); }
        catch (Throwable $e) {}
    }
}
