<?php
/**
 * lib/NgvIntake.php — an NGG member promoted to NGV gets their NGV account here.
 *
 * NextGen Genius promotes a member to NextGen Vanguard in its own Control
 * Room. NGV runs on this site, so until now the new vanguard had no account
 * here until somebody made one by hand. NGG now sends the promotion
 * (integrations/ngg.php, signed, retried by NGG's bus), and this:
 *
 *   1. finds the account by email — an email that already has an account IS
 *      that account (MemberRoster::create links it and fills its blanks) — or
 *      creates one, with a secure gate card;
 *   2. enrols them in NGV (ngv_participants) — which is what makes somebody a
 *      vanguard here (NgvMember::isVanguard) and opens their NGV views in the
 *      portal;
 *   3. gives them an NGV ID card number (X-NGV-YY-NNNN) if they have none;
 *   4. links any NGV application they made under the same email.
 *
 * Idempotent by NGG's delivery id and by NGG member id: the bus retries, and
 * a member promoted, demoted and promoted again is one account. A promotion
 * with no email cannot become a sign-in, so it is kept as "needs an email"
 * and shown on the Members dashboard rather than inventing an address.
 */
declare(strict_types=1);

final class NgvIntake
{
    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS ngv_intake (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            delivery_id   VARCHAR(64) NOT NULL DEFAULT '',
            ngg_member_id VARCHAR(64) NOT NULL,
            name          VARCHAR(160) NOT NULL DEFAULT '',
            email         VARCHAR(191) NOT NULL DEFAULT '',
            status        VARCHAR(16) NOT NULL DEFAULT 'received',
            member_id     INTEGER NOT NULL DEFAULT 0,
            detail        VARCHAR(255) NOT NULL DEFAULT '',
            received_at   VARCHAR(32) NOT NULL DEFAULT '',
            updated_at    VARCHAR(32) NOT NULL DEFAULT ''
        )");
        try { Database::pdo()->exec('CREATE UNIQUE INDEX uq_ngv_intake_ngg ON ngv_intake (ngg_member_id)'); } catch (Throwable $e) {}
    }

    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

    /**
     * Take one promotion from NGG.
     *
     * $d: nggMemberId, name, email, phone, centre, dob (YYYY-MM-DD), track,
     *     volunteerId, displayId.
     * @return array{ok:bool, status:string, member_id?:int, error?:string}
     */
    public static function take(array $d, string $deliveryId = ''): array
    {
        self::ensure();
        $nggId = mb_substr(trim((string) ($d['nggMemberId'] ?? '')), 0, 64);
        $name = trim((string) ($d['name'] ?? ''));
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if ($nggId === '' || $name === '') return ['ok' => false, 'status' => 'bad_event', 'error' => 'An NGG member id and a name are needed.'];
        $pdo = Database::pdo();

        $row = self::row($nggId);
        if ($row && (string) $row['status'] === 'linked' && (int) $row['member_id'] > 0) {
            /* Already done (a retry, or promoted again): make sure the NGV
               enrolment is still there, and say so. */
            self::enrol((int) $row['member_id'], $name, (string) $row['email'], $d);
            return ['ok' => true, 'status' => 'already', 'member_id' => (int) $row['member_id']];
        }
        if (!$row) {
            $pdo->prepare('INSERT INTO ngv_intake (delivery_id, ngg_member_id, name, email, status, received_at, updated_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([mb_substr($deliveryId, 0, 64), $nggId, mb_substr($name, 0, 160), mb_substr($email, 0, 191), 'received', self::now(), self::now()]);
        } else {
            $pdo->prepare('UPDATE ngv_intake SET name = ?, email = ?, delivery_id = ?, updated_at = ? WHERE ngg_member_id = ?')
                ->execute([mb_substr($name, 0, 160), mb_substr($email, 0, 191), mb_substr($deliveryId, 0, 64), self::now(), $nggId]);
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::mark($nggId, 'needs_email', 0, $email === '' ? 'NGG has no email for them.' : '“' . $email . '” is not an email address.');
            return ['ok' => true, 'status' => 'needs_email'];
        }

        $dob = (string) ($d['dob'] ?? '');
        $made = MemberRoster::create(array_filter([
            'name' => $name, 'email' => $email,
            'phone' => (string) ($d['phone'] ?? ''), 'centre' => (string) ($d['centre'] ?? ''),
            'birthday' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) ? $dob : '',
            'notes' => 'Promoted from NextGen Genius (' . $nggId . ($d['displayId'] ?? '' ? ', ' . $d['displayId'] : '') . ').',
        ], static fn($v) => $v !== ''), 'ngg:promotion', 'ngg');
        if (!$made['ok']) {
            /* Usually a phone or birthday NGG holds in a shape this site does
               not take. The account matters more than those: try again with
               the name and email alone. */
            $made = MemberRoster::create(['name' => $name, 'email' => $email], 'ngg:promotion', 'ngg');
        }
        if (!$made['ok']) {
            self::mark($nggId, 'failed', 0, mb_substr((string) ($made['error'] ?? 'Could not be created.'), 0, 255));
            return ['ok' => false, 'status' => 'failed', 'error' => (string) ($made['error'] ?? '')];
        }
        $mid = (int) $made['id'];
        self::enrol($mid, $name, $email, $d);
        self::mark($nggId, 'linked', $mid, !empty($made['linked']) ? 'Linked to the existing account.' : 'Account created.');
        (new LmsRepository())->audit('ngv.intake', $email, 'promoted from NGG ' . $nggId . (!empty($made['linked']) ? ' · linked to existing account' : ' · account created'), 'ngg');
        return ['ok' => true, 'status' => !empty($made['linked']) ? 'linked' : 'created', 'member_id' => $mid];
    }

    /** The NGV side: enrolment, an NGV ID card, any application under the email. */
    private static function enrol(int $mid, string $name, string $email, array $d): void
    {
        $seed = ['name' => $name, 'email' => $email];
        $track = trim((string) ($d['track'] ?? ''));
        if ($track !== '' && !NgvMember::participant($mid)) $seed['track'] = $track;
        NgvMember::ensureParticipant($mid, $seed);
        if (GateAttendance::cardFor($mid) === null) GateAttendance::assignCard($mid, '', 0);
        if ($email !== '') NgvMember::linkByEmail($mid, $email);
    }

    private static function row(string $nggId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM ngv_intake WHERE ngg_member_id = ?');
        $st->execute([$nggId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function mark(string $nggId, string $status, int $mid, string $detail): void
    {
        Database::pdo()->prepare('UPDATE ngv_intake SET status = ?, member_id = ?, detail = ?, updated_at = ? WHERE ngg_member_id = ?')
            ->execute([$status, $mid, mb_substr($detail, 0, 255), self::now(), $nggId]);
    }

    /** Promotions waiting for something a person has to supply — for the Members dashboard. */
    public static function waiting(): array
    {
        self::ensure();
        return Database::pdo()->query("SELECT ngg_member_id, name, email, status, detail, received_at FROM ngv_intake WHERE status IN ('needs_email', 'failed') ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Finish a promotion that was waiting for an email: the office types the
     * address and the account is made exactly as if NGG had sent it.
     */
    public static function supplyEmail(string $nggId, string $email): array
    {
        self::ensure();
        $row = self::row($nggId);
        if (!$row) return ['ok' => false, 'error' => 'No such promotion.'];
        return self::take(['nggMemberId' => $nggId, 'name' => (string) $row['name'], 'email' => $email]);
    }
}
