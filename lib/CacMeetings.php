<?php
/**
 * lib/CacMeetings.php — CACENTRE's staff portal scheduling meetings here.
 *
 * ── ONE LIST OF MEETINGS ────────────────────────────────────────────────────
 * Meetings live here (lib/Meetings.php): Google Meet links, invites, minutes.
 * CACENTRE staff schedule and read them from their own staff portal
 * (cac/staff/meetings.php) rather than keeping a second list that would
 * disagree with this one by the end of the week. Every member and staff
 * member sees every meeting; the organiser decides whether the MINUTES are
 * for everybody or only for the people in it.
 *
 * ── TRUST ───────────────────────────────────────────────────────────────────
 * The same shared secret as single sign-on (AV_SSO_SECRET), the same
 * v1.<claims>.<hmac> token, in the other direction, for one door:
 *
 *   claims  iss "cacentre", aud "meetings", iat, exp (a minute), nonce,
 *           staff {id, name, email}, bh = sha256 of the request body
 *
 * The staff member is taken from inside the signed token, never from the
 * body. `bh` binds the token to the body it came with, and the nonce is
 * spent, so a captured request cannot be replayed or re-aimed.
 *
 * A staff member with an account here (matched by email) acts as that
 * account: their meetings are theirs in the portal too. One without an
 * account is recorded as the organiser by name and email.
 */
declare(strict_types=1);

final class CacMeetings
{
    public const AUD = 'meetings';
    private const SKEW = 60;

    private static function unb64(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        return (string) base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /**
     * The staff member a token speaks for, or the reason it was refused.
     *
     * @return array{id:string,name:string,email:string}|string
     */
    public static function verify(string $token, string $body, ?int $now = null)
    {
        if (!class_exists('CacSso') || !CacSso::ready()) return 'not-configured';
        $p = explode('.', $token);
        if (count($p) !== 3 || $p[0] !== 'v1') return 'bad-token';
        if (!hash_equals(hash_hmac('sha256', $p[1], CacSso::secret(), true), self::unb64($p[2]))) return 'bad-signature';
        $c = json_decode(self::unb64($p[1]), true);
        if (!is_array($c)) return 'bad-token';
        $now = $now ?? time();
        if ((string) ($c['iss'] ?? '') !== 'cacentre' || (string) ($c['aud'] ?? '') !== self::AUD) return 'wrong-door';
        if ((int) ($c['exp'] ?? 0) < $now - self::SKEW) return 'expired';
        if ((int) ($c['iat'] ?? 0) > $now + self::SKEW) return 'not-yet';
        if (!hash_equals((string) ($c['bh'] ?? ''), hash('sha256', $body))) return 'body-mismatch';
        $s = (array) ($c['staff'] ?? []);
        $email = strtolower(trim((string) ($s['email'] ?? '')));
        if (trim((string) ($s['id'] ?? '')) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'no-staff';
        if (!self::spend((string) ($c['nonce'] ?? ''), $now)) return 'replayed';
        return ['id' => mb_substr((string) $s['id'], 0, 64), 'name' => mb_substr(trim((string) ($s['name'] ?? '')), 0, 160), 'email' => $email];
    }

    /** A nonce is good once. Kept a day, which is far past any token's life. */
    private static function spend(string $nonce, int $now): bool
    {
        if (!preg_match('/^[a-f0-9]{16,64}$/', $nonce)) return false;
        $db = Database::pdo();
        Database::execSchema($db, "CREATE TABLE IF NOT EXISTS cac_meeting_nonces (nonce VARCHAR(64) PRIMARY KEY, at INTEGER NOT NULL DEFAULT 0)");
        try { $db->prepare('INSERT INTO cac_meeting_nonces (nonce, at) VALUES (?, ?)')->execute([$nonce, $now]); }
        catch (Throwable $e) { return false; }
        if (random_int(1, 50) === 1) $db->prepare('DELETE FROM cac_meeting_nonces WHERE at < ?')->execute([$now - 86400]);
        return true;
    }

    /** Their account here, by email, when they have an active one. */
    public static function accountOf(array $staff): int
    {
        $st = Database::pdo()->prepare("SELECT id FROM lms_users WHERE LOWER(email) = ? AND COALESCE(status, 'active') = 'active' LIMIT 1");
        $st->execute([strtolower($staff['email'])]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    /** Did this staff member organise it — from the staff portal, or as their account here? */
    public static function organisedBy(array $staff, int $meetingId): bool
    {
        $st = Database::pdo()->prepare('SELECT creator_id, origin, origin_ref, organiser_email FROM meetings WHERE id = ?');
        $st->execute([$meetingId]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) return false;
        if ((string) $m['origin'] === 'cacentre' && (string) $m['origin_ref'] === $staff['id']) return true;
        $acc = self::accountOf($staff);
        return $acc > 0 && Meetings::mayManage($acc, $meetingId);
    }

    /** One request from the staff portal, answered. */
    public static function handle(array $staff, string $action, array $in): array
    {
        Meetings::ensure();
        $acc = self::accountOf($staff);
        $as = ['name' => $staff['name'], 'email' => $staff['email'], 'origin' => 'cacentre', 'ref' => $staff['id'], 'tz' => 'Africa/Lagos'];
        $mark = static function (array $m) use ($staff): array {
            $m['can_manage'] = self::organisedBy($staff, (int) $m['id']);
            return $m;
        };
        switch ($action) {
            case 'list':
                return ['ok' => true, 'meetings' => array_map($mark, Meetings::listAll($staff['email'], $acc))];
            case 'get':
                $m = Meetings::view((int) ($in['id'] ?? 0), null, $staff['email']);
                return $m ? ['ok' => true, 'meeting' => $mark($m)] : ['ok' => false, 'error' => 'Meeting not found.'];
            case 'schedule':
                $r = Meetings::schedule($acc, array_intersect_key($in, array_flip(['title', 'when', 'duration', 'frequency', 'agenda', 'attendees', 'minutes_private'])) + ['context' => 'workspace'], $as);
                if (!empty($r['ok'])) self::audit('meeting.cacentre_schedule', (string) $r['id'], $staff);
                return $r;
            case 'cancel':
            case 'privacy':
                $id = (int) ($in['id'] ?? 0);
                if (!self::organisedBy($staff, $id)) return ['ok' => false, 'error' => 'Only the organiser can change this meeting.'];
                $owner = (int) (Database::pdo()->query('SELECT creator_id FROM meetings WHERE id = ' . $id)->fetchColumn() ?: 0);
                $r = $action === 'cancel'
                    ? Meetings::cancel($owner, $id, true)
                    : Meetings::update($owner, $id, ['minutes_private' => !empty($in['minutes_private'])], true);
                if (!empty($r['ok'])) self::audit('meeting.cacentre_' . $action, (string) $id, $staff);
                return $r;
        }
        return ['ok' => false, 'error' => 'Unknown action.'];
    }

    private static function audit(string $what, string $id, array $staff): void
    {
        if (!class_exists('AdminAudit')) return;
        try { AdminAudit::log('meetings', $what, $id, 'by CACENTRE staff ' . $staff['name'] . ' <' . $staff['email'] . '>', null, 'cacentre:' . $staff['id']); }
        catch (Throwable $e) {}
    }
}
