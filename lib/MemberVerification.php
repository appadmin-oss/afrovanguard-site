<?php
/**
 * lib/MemberVerification.php — being a member is something somebody DID, not
 * something an email address implies.
 *
 * ── WHAT WAS WRONG ───────────────────────────────────────────────────────────
 * An @afrovanguard.org.ng address WAS membership. Three places said so:
 *
 *   1. `LmsAuth::isOrgMember()` — rank ≥ member OR an org email. The portal's
 *      whole Work group hung off it.
 *   2. Signing in with a code made a NEW account at role `member` when the
 *      address was an org one, and `learner` otherwise.
 *   3. Signing in PROMOTED an existing learner to `member` on the same test.
 *
 * So anybody the organisation had ever given an address to — a contractor, a
 * one-week volunteer, somebody who left two years ago, a shared inbox — was a
 * member, for ever, with no record of anybody having decided it. "Who made
 * this person a member?" had no answer, because nobody had.
 *
 * ── WHAT IT IS NOW ───────────────────────────────────────────────────────────
 * An explicit act, with a record: who verified, when, and **on what basis**.
 * It is deliberately the same shape as `Volunteers::isVolunteer()`, which has
 * always been explicit — a roster an admin writes to, never inferred from an
 * address.
 *
 *   verified       a person at the office said so, and said why
 *   grandfathered  the email rule covered them on the day this shipped, so
 *                  they keep their access and the office still owes them a
 *                  decision. They are on the "to verify" list until it is made
 *   declined       considered and not a member. The email does not override it
 *   (none)         never considered; they are a member only if their ROLE says
 *                  so, which is itself an act by the office
 *
 * ── NOBODY IS LOCKED OUT OVERNIGHT ───────────────────────────────────────────
 * `grandfatherAll()` runs once and marks everybody the old rule covered. The
 * email stops conferring membership on anyone NEW from the same moment. That
 * was the deliberate choice: enforcing immediately would have locked out
 * everybody the office had not got to yet, and an organisation that cannot
 * sign in does not finish the clean-up — it turns the check off.
 *
 * ── WHAT THE ORG DOMAIN STILL DOES ───────────────────────────────────────────
 * It is still a real signal and still used for what it actually tells you:
 * whether to require a sign-in code (`AuthPolicy`), which Google Workspace
 * account somebody has, how the community segments its spaces, and which
 * addresses may hold an elevated role. None of that is membership, and none of
 * it changed.
 */
declare(strict_types=1);

final class MemberVerification
{
    public const STATES = ['verified', 'grandfathered', 'declined'];
    /** The states that make somebody a member by this route. */
    public const COUNTS = ['verified', 'grandfathered'];
    public const BASIS_MAX = 200;
    public const NOTE_MAX = 500;
    /** Marks the one-time backfill as done, so it never runs twice. */
    public const BACKFILL_KEY = 'av_member_verify_grandfathered';
    /** How long another process waits before deciding a claim died mid-pass. */
    public const CLAIM_SECONDS = 600;

    private static bool $ready = false;
    private static array $memo = [];

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        if (class_exists('MemberRoster')) MemberRoster::ensure();
        $pdo = Database::pdo();
        foreach (['verified_state' => "VARCHAR(16) NOT NULL DEFAULT ''",
                  'verified_by'    => "VARCHAR(191) NOT NULL DEFAULT ''",
                  'verified_at'    => "VARCHAR(32) NOT NULL DEFAULT ''",
                  'verified_basis' => "VARCHAR(200) NOT NULL DEFAULT ''"] as $col => $decl) {
            try { if (!Database::columnExists('member_profiles', $col)) $pdo->exec("ALTER TABLE member_profiles ADD COLUMN $col $decl"); }
            catch (Throwable $e) { error_log('[memberverify] ensure ' . $col . ': ' . $e->getMessage()); }
        }
        /* The record, one row per decision. The column above is the cache the
           roster reads; this is what answers "who decided, and why". */
        Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS member_verify_log (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id  INTEGER NOT NULL DEFAULT 0,
            action   VARCHAR(16) NOT NULL DEFAULT '',
            basis    VARCHAR(200) NOT NULL DEFAULT '',
            note     VARCHAR(500) NOT NULL DEFAULT '',
            acted_by VARCHAR(191) NOT NULL DEFAULT '',
            acted_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        try { $pdo->exec('CREATE INDEX idx_member_verify_user ON member_verify_log (user_id, id)'); } catch (Throwable $e) {}
        /* The backfill runs ITSELF, once, on the first request after a deploy.
           This is shared hosting with no shell: a migration somebody has to
           remember to run by hand is a migration that does not run, and the
           cost of this one not running is everybody on an org address losing
           their portal. One metaGet guards it afterwards. */
        try { self::grandfatherAll('system'); } catch (Throwable $e) { error_log('[memberverify] grandfather: ' . $e->getMessage()); }
    }

    /* ══ Reading ══════════════════════════════════════════════════════════ */

    /**
     * Drop the per-request memo. Writes through verify()/decline()/unverify()
     * clear their own entry; this is for anything that changes the rows
     * underneath — a bulk correction, or a test putting the database back.
     */
    public static function forget(?int $userId = null): void
    {
        if ($userId === null) self::$memo = [];
        else unset(self::$memo[$userId]);
    }

    /** '' | 'verified' | 'grandfathered' | 'declined'. */
    public static function state(int $userId): string
    {
        if ($userId <= 0) return '';
        if (isset(self::$memo[$userId])) return self::$memo[$userId];
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT verified_state FROM member_profiles WHERE user_id = ?');
            $st->execute([$userId]);
            $s = (string) ($st->fetchColumn() ?: '');
        } catch (Throwable $e) { $s = ''; }
        return self::$memo[$userId] = in_array($s, self::STATES, true) ? $s : '';
    }

    /** Verified or grandfathered: a member by this route. */
    public static function isVerified(int $userId): bool
    {
        return in_array(self::state($userId), self::COUNTS, true);
    }

    /** Considered and refused — the one state that OVERRIDES everything else. */
    public static function isDeclined(int $userId): bool
    {
        return self::state($userId) === 'declined';
    }

    /** Several at once: user_id => state, for the roster's page. */
    public static function states(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if (!$ids) return [];
        self::ensure();
        $out = [];
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $rows = Database::pdo()->query("SELECT user_id, verified_state FROM member_profiles
                                                 WHERE user_id IN (" . implode(',', $chunk) . ") AND verified_state <> ''")
                    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $r) $out[(int) $r['user_id']] = (string) $r['verified_state'];
            }
        } catch (Throwable $e) { error_log('[memberverify] states: ' . $e->getMessage()); }
        return $out;
    }

    /**
     * SQL fragment over `lms_users u` for the states that count. Used by
     * MemberRoster::memberWhere() so one definition answers everywhere.
     */
    public static function countsSql(): string
    {
        self::ensure();
        $in = "'" . implode("','", self::COUNTS) . "'";
        return "EXISTS (SELECT 1 FROM member_profiles mvp WHERE mvp.user_id = u.id AND mvp.verified_state IN ($in))";
    }

    /** SQL for the one state that takes membership AWAY. */
    public static function declinedSql(): string
    {
        self::ensure();
        return "EXISTS (SELECT 1 FROM member_profiles mvp WHERE mvp.user_id = u.id AND mvp.verified_state = 'declined')";
    }

    /** One person's decisions, newest first. */
    public static function historyFor(int $userId): array
    {
        self::ensure();
        $out = [];
        try {
            $st = Database::pdo()->prepare('SELECT action, basis, note, acted_by, acted_at FROM member_verify_log WHERE user_id = ? ORDER BY id DESC');
            $st->execute([$userId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[] = ['action' => (string) $r['action'], 'basis' => (string) $r['basis'], 'note' => (string) $r['note'],
                          'by' => (string) $r['acted_by'], 'at' => (string) $r['acted_at']];
            }
        } catch (Throwable $e) { error_log('[memberverify] historyFor: ' . $e->getMessage()); }
        return $out;
    }

    /* ══ Deciding ═════════════════════════════════════════════════════════ */

    /**
     * Verify somebody, with the basis on the record.
     *
     * The basis is what the office SAW — an ID, a signed form, a referee, "met
     * at the centre and known to the team". It is required, because the whole
     * point of the change is that "they have one of our email addresses" is
     * not a basis and never was; a verification with no stated basis is the
     * old rule with a button on it.
     */
    public static function verify(int $userId, string $basis, string $by, string $note = ''): array
    {
        $basis = trim($basis);
        if ($basis === '') {
            return ['ok' => false, 'code' => 'no_basis',
                    'error' => 'Say what this rests on — an ID, a form, a referee, or that they are known to the team. “They have our email address” is what this replaced.'];
        }
        return self::write($userId, 'verify', 'verified', $basis, $by, $note);
    }

    /**
     * Take it back. Not a punishment and not a suspension — it says nobody
     * has established this person is a member, so the state goes back to
     * nothing and their standing rests on their role, as a new account's does.
     */
    public static function unverify(int $userId, string $reason, string $by): array
    {
        $reason = trim($reason);
        if ($reason === '') return ['ok' => false, 'code' => 'no_reason', 'error' => 'Say why it is being taken back.'];
        return self::write($userId, 'unverify', '', $reason, $by, '');
    }

    /** Considered, and not a member. This beats an elevated role and the email. */
    public static function decline(int $userId, string $reason, string $by): array
    {
        $reason = trim($reason);
        if ($reason === '') return ['ok' => false, 'code' => 'no_reason', 'error' => 'Say why they are not a member.'];
        return self::write($userId, 'decline', 'declined', $reason, $by, '');
    }

    private static function write(int $userId, string $action, string $state, string $basis, string $by, string $note): array
    {
        self::ensure();
        if ($userId <= 0) return ['ok' => false, 'error' => 'No such member.'];
        $was = self::state($userId);
        if ($was === $state && $state !== '') return ['ok' => true, 'unchanged' => true, 'state' => $state, 'said' => 'Already ' . $state . '.'];
        $now = gmdate('Y-m-d H:i:s');
        $basis = mb_substr($basis, 0, self::BASIS_MAX);
        try {
            $pdo = Database::pdo();
            $st = $pdo->prepare('SELECT 1 FROM member_profiles WHERE user_id = ?');
            $st->execute([$userId]);
            if ($st->fetchColumn()) {
                $pdo->prepare('UPDATE member_profiles SET verified_state = ?, verified_by = ?, verified_at = ?, verified_basis = ?, updated_at = ? WHERE user_id = ?')
                    ->execute([$state, mb_substr($by, 0, 191), $now, $basis, $now, $userId]);
            } else {
                $pdo->prepare('INSERT INTO member_profiles (user_id, verified_state, verified_by, verified_at, verified_basis, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$userId, $state, mb_substr($by, 0, 191), $now, $basis, $now]);
            }
            $pdo->prepare('INSERT INTO member_verify_log (user_id, action, basis, note, acted_by, acted_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $action, $basis, mb_substr($note, 0, self::NOTE_MAX), mb_substr($by, 0, 191), $now]);
        } catch (Throwable $e) {
            error_log('[memberverify] write: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not record that.'];
        }
        unset(self::$memo[$userId]);
        if (class_exists('AdminAudit')) {
            try { AdminAudit::log('members', 'member_' . $action, 'user:' . $userId, $basis, null, 'admin'); } catch (Throwable $e) {}
        }
        return ['ok' => true, 'state' => $state, 'was' => $was,
                'said' => $action === 'verify' ? 'Verified.' : ($action === 'decline' ? 'Recorded as not a member.' : 'Verification taken back.')];
    }

    /* ══ The one-time backfill ════════════════════════════════════════════ */

    /**
     * Take the backfill for this process, or say who has it.
     *
     * CLAIM FIRST, then work. The pass runs on the first request after a
     * deploy, in whatever process gets there — and on a busy site that is
     * several processes at once. Reading the marker, scanning, and only then
     * writing it would have every one of them do the whole thing.
     *
     * The claim carries the time it was taken, so a process that dies
     * mid-pass does not leave the backfill unfinished for ever: after
     * CLAIM_SECONDS the next request takes it over.
     *
     * @return string 'mine' (go ahead) | 'already' (done) | 'running' (someone has it)
     */
    public static function claimBackfill(): string
    {
        try {
            $m = (string) (Database::metaGet(self::BACKFILL_KEY) ?? '');
            if ($m !== '' && !str_starts_with($m, 'running:')) return 'already';
            if (str_starts_with($m, 'running:') && (time() - (int) substr($m, 8)) < self::CLAIM_SECONDS) return 'running';
            Database::metaSet(self::BACKFILL_KEY, 'running:' . time());
            return 'mine';
        } catch (Throwable $e) {
            /* No store, no claim: do the work rather than skip it. A member
               locked out is worse than a pass that runs twice. */
            return 'mine';
        }
    }

    /**
     * Mark everybody the OLD rule covered, so the day this ships nobody loses
     * access they had the day before. Runs once; the marker is in app_meta.
     *
     * Covered = an elevated role, or an org-domain email, or a NextGen
     * Vanguard. That is exactly what `isOrgMember()` and `memberWhere()` meant
     * before, read together — deliberately generous, because the cost of
     * grandfathering somebody who should not be a member is a line on the
     * office's list, and the cost of missing somebody is a person locked out
     * of their own record with no idea why.
     *
     * @return array{ok:bool, marked:int, already?:bool}
     */
    public static function grandfatherAll(string $by = 'system', bool $force = false): array
    {
        self::ensure();
        if (!$force) {
            $claim = self::claimBackfill();
            if ($claim !== 'mine') return ['ok' => true, 'marked' => 0, $claim => true];
        }
        $pdo = Database::pdo();
        $domain = strtolower((string) (defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng'));
        $ids = [];
        try {
            $st = $pdo->prepare("SELECT u.id FROM lms_users u
                                  LEFT JOIN member_profiles p ON p.user_id = u.id
                                 WHERE COALESCE(p.verified_state, '') = ''
                                   AND (u.role <> 'learner' OR LOWER(u.email) LIKE ?)");
            $st->execute(['%@' . $domain]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) { error_log('[memberverify] grandfather scan: ' . $e->getMessage()); }
        /* The NGV database is a separate connection and may be unreachable on
           a site that does not run the programme; a backfill must not take
           the whole site down over it. */
        try {
            if (class_exists('MemberRoster')) {
                foreach (array_keys(MemberRoster::vanguardIds()) as $v) if (!in_array((int) $v, $ids, true)) $ids[] = (int) $v;
            }
        } catch (Throwable $e) { error_log('[memberverify] grandfather vanguards: ' . $e->getMessage()); }
        $n = 0;
        foreach ($ids as $id) {
            if (self::state($id) !== '') continue;
            $r = self::write($id, 'grandfather', 'grandfathered',
                'Covered by the old rule when verification was introduced', $by, '');
            if (!empty($r['ok'])) $n++;
        }
        try { Database::metaSet(self::BACKFILL_KEY, gmdate('Y-m-d H:i:s') . ' · ' . $n); } catch (Throwable $e) {}
        return ['ok' => true, 'marked' => $n];
    }

    /**
     * Who the office still owes a decision: everybody grandfathered, plus
     * learners on an org address — the people the old rule would have made
     * members and the new one does not.
     *
     * @return array<int, array{id:int,name:string,email:string,role:string,state:string,since:string,why:string}>
     */
    public static function toVerify(int $limit = 200): array
    {
        self::ensure();
        $limit = max(1, min(1000, $limit));
        $domain = strtolower((string) (defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng'));
        $out = [];
        /* The community bot is an account, not a person — Membership's own
           obligation check skips it for the same reason. A to-do list with
           something nobody can ever action at the top of it is a to-do list
           people stop reading. */
        $bot = strtolower(class_exists('Community') ? Community::BOT_EMAIL : 'community-bot@afrovanguard.org.ng');
        try {
            $st = Database::pdo()->prepare("SELECT u.id, u.name, u.email, u.role, u.created_at,
                                                   COALESCE(p.verified_state, '') AS st, COALESCE(p.verified_at, '') AS at
                                              FROM lms_users u LEFT JOIN member_profiles p ON p.user_id = u.id
                                             WHERE u.status = 'active'
                                               AND LOWER(u.email) <> ?
                                               AND (COALESCE(p.verified_state, '') = 'grandfathered'
                                                 OR (COALESCE(p.verified_state, '') = '' AND u.role = 'learner' AND LOWER(u.email) LIKE ?))
                                          ORDER BY u.id LIMIT $limit");
            $st->execute([$bot, '%@' . $domain]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $state = (string) $r['st'];
                $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'],
                          'role' => (string) $r['role'], 'state' => $state,
                          'since' => (string) ($state === '' ? $r['created_at'] : $r['at']),
                          'why' => $state === 'grandfathered'
                              ? 'Had access under the old rule. Nobody has checked who they are.'
                              : 'Has one of our email addresses, which is no longer enough on its own.'];
            }
        } catch (Throwable $e) { error_log('[memberverify] toVerify: ' . $e->getMessage()); }
        return $out;
    }

    /** How many are waiting — for the badge on the Studio's tab. */
    public static function waitingCount(): int
    {
        return count(self::toVerify(1000));
    }
}
