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
 *
 * A child (under 18, NDPA 2023 s.31) is not enrolled until a parent or
 * guardian has consented: NGG sends `minor` and `guardianConsent`
 * {status: granted|none|n/a, method, at, by}. A minor without a granted
 * consent is held as "needs consent" — the consent NGG later sends, or
 * the office records on the Members dashboard, releases it. The consent
 * itself is kept on the intake row as the record of it.
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
        foreach (['minor' => 'INTEGER NOT NULL DEFAULT 0', 'consent' => "VARCHAR(255) NOT NULL DEFAULT ''"] as $c => $def) {
            try { if (!Database::columnExists('ngv_intake', $c)) Database::pdo()->exec("ALTER TABLE ngv_intake ADD COLUMN $c $def"); } catch (Throwable $e) {}
        }
    }

    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

    /**
     * Take one promotion from NGG.
     *
     * $d: nggMemberId, name, email, phone, centre, dob (YYYY-MM-DD), track,
     *     volunteerId, displayId, minor (bool), guardianConsent
     *     {status, method, at, by}.
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
        /* A child, by NGG's say-so or by the birthday it sent. A consent NGG
           does not repeat on a retry is the one already on file. */
        $minor = !empty($d['minor']) || self::under18((string) ($d['dob'] ?? '')) || ($row && (int) ($row['minor'] ?? 0) === 1);
        $consent = is_array($d['guardianConsent'] ?? null) ? self::cleanConsent($d['guardianConsent'])
            : (($row && ($kept = json_decode((string) ($row['consent'] ?? ''), true))) ? $kept : null);
        $consented = $consent && $consent['status'] === 'granted';
        if ($row) self::keepConsent($nggId, $minor, $consent);
        if ($minor && !$consented && !($row && (string) $row['status'] === 'linked')) {
            if (!$row) {
                $pdo->prepare('INSERT INTO ngv_intake (delivery_id, ngg_member_id, name, email, status, received_at, updated_at) VALUES (?,?,?,?,?,?,?)')
                    ->execute([mb_substr($deliveryId, 0, 64), $nggId, mb_substr($name, 0, 160), mb_substr($email, 0, 191), 'received', self::now(), self::now()]);
                self::keepConsent($nggId, $minor, $consent);
            }
            self::mark($nggId, 'needs_consent', $row ? (int) $row['member_id'] : 0, 'Under 18: a parent or guardian has to consent before they are enrolled.');
            return ['ok' => true, 'status' => 'needs_consent'];
        }
        /* Promoted again after a demotion: the same account, back on the
           programme. Only this path reactivates — a staff withdrawal for any
           other reason is not undone by an intake retry. */
        if ($row && (string) $row['status'] === 'revoked' && (int) $row['member_id'] > 0) {
            $mid = (int) $row['member_id'];
            self::enrol($mid, $name, (string) $row['email'], $d);
            NgvMember::setAdmin($mid, ['status' => 'active']);
            if (GateAttendance::cardFor($mid) === null) GateAttendance::assignCard($mid, '', 0);
            self::mark($nggId, 'linked', $mid, 'Promoted again on NGG.');
            return ['ok' => true, 'status' => 'restored', 'member_id' => $mid];
        }
        if ($row && (string) $row['status'] === 'linked' && (int) $row['member_id'] > 0) {
            /* Already done (a retry, or promoted again): make sure the NGV
               enrolment is still there, and say so. */
            self::enrol((int) $row['member_id'], $name, (string) $row['email'], $d);
            return ['ok' => true, 'status' => 'already', 'member_id' => (int) $row['member_id']];
        }
        if (!$row) {
            $pdo->prepare('INSERT INTO ngv_intake (delivery_id, ngg_member_id, name, email, status, received_at, updated_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([mb_substr($deliveryId, 0, 64), $nggId, mb_substr($name, 0, 160), mb_substr($email, 0, 191), 'received', self::now(), self::now()]);
            self::keepConsent($nggId, $minor, $consent);
        } else {
            $pdo->prepare('UPDATE ngv_intake SET name = ?, email = ?, delivery_id = ?, updated_at = ? WHERE ngg_member_id = ?')
                ->execute([mb_substr($name, 0, 160), mb_substr($email, 0, 191), mb_substr($deliveryId, 0, 64), self::now(), $nggId]);
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::mark($nggId, 'needs_email', 0, $email === '' ? 'NGG has no email for them.' : '“' . $email . '” is not an email address.');
            return ['ok' => true, 'status' => 'needs_email'];
        }

        /*
         * An email NGG holds is not unique there — siblings share a parent's
         * address. Linking by email alone made the second sibling the FIRST
         * one's account: one participant, one card, and the participant's
         * name flipping to whoever arrived last. A staff account under the
         * address would have been enrolled as a vanguard too. Both are held
         * for a person rather than guessed.
         */
        $taken = $pdo->prepare("SELECT ngg_member_id FROM ngv_intake WHERE LOWER(email) = ? AND status IN ('linked','invited') AND ngg_member_id <> ? LIMIT 1");
        $taken->execute([$email, $nggId]);
        if (($other = $taken->fetchColumn()) !== false) {
            self::mark($nggId, 'needs_review', 0, 'The email ' . $email . ' is already NGG member ' . $other . '’s account. Give this member their own email, or link them by hand.');
            return ['ok' => true, 'status' => 'needs_review'];
        }
        $acct = $pdo->prepare('SELECT * FROM lms_users WHERE LOWER(email) = ? LIMIT 1');
        $acct->execute([$email]);
        if (($u = $acct->fetch(PDO::FETCH_ASSOC)) && MemberRoster::isStaff($u)) {
            self::mark($nggId, 'needs_review', 0, $email . ' is a staff account here. A vanguard needs their own account.');
            return ['ok' => true, 'status' => 'needs_review'];
        }

        $dob = (string) ($d['dob'] ?? '');
        /*
         * Promotion is an INVITATION now (29 Oct 2026), not an enrolment. A
         * vanguard registers in person at a CACENTRE, with a guarantor and the
         * current NGV MoU, whoever they are — NGG members included, because the
         * MoU they would sign is NGV's, with NGV's policies. So the promotion
         * becomes an application, already filled in and already invited; the
         * desk completes it (NgvRegistration::complete), and only then is the
         * account made and this row marked linked (registered()).
         */
        if ($row && (string) $row['status'] === 'invited') {
            return ['ok' => true, 'status' => 'already_invited'];
        }
        if (class_exists('NgvRegistration')) {
            /* They may already have applied themselves and been invited: that
               invitation stands, and is marked as NGG's. A second application
               would put two references for one person at the desk. */
            $open = NgvDb::pdo()->prepare("SELECT id, ref, name, status FROM ngv_applications WHERE LOWER(email) = ? AND status IN ('new','reviewing','invited','accepted','booked','attended') ORDER BY id DESC LIMIT 1");
            $open->execute([$email]);
            $have = $open->fetch(PDO::FETCH_ASSOC);
            /* Under the same address but somebody else — a sibling on a
               parent's email, usually. Held for a person, as above. */
            if ($have && self::nameKey((string) $have['name']) !== self::nameKey($name)) {
                self::mark($nggId, 'needs_review', 0, 'An NGV application from ' . (string) $have['name'] . ' is already open under ' . $email . '. Give this member their own email, or link them by hand.');
                return ['ok' => true, 'status' => 'needs_review'];
            }
            if ($have && in_array((string) $have['status'], ['new', 'reviewing'], true)) {
                NgvDb::pdo()->prepare("UPDATE ngv_applications SET source = 'ngg' WHERE id = ?")->execute([(int) $have['id']]);
                $inv = NgvRegistration::invite((int) $have['id'], 0);
                $ref = NgvRegistration::ensureRef((int) $have['id']);
                self::mark($nggId, 'invited', 0, 'Invited to register at a CACENTRE (' . $ref . ')' . (empty($inv['ok']) ? ' — ' . (string) ($inv['error'] ?? '') : '') . '.');
                return ['ok' => true, 'status' => 'invited', 'application_id' => (int) $have['id'], 'ref' => $ref];
            }
            if ($have) {
                NgvDb::pdo()->prepare("UPDATE ngv_applications SET source = 'ngg' WHERE id = ?")->execute([(int) $have['id']]);
                $ref = NgvRegistration::ensureRef((int) $have['id']);
                self::mark($nggId, 'invited', 0, 'Already invited to register (' . $ref . ').');
                return ['ok' => true, 'status' => 'invited', 'application_id' => (int) $have['id'], 'ref' => $ref];
            }
            $appId = NgvMember::submitApplication(array_filter([
                'name' => $name, 'email' => $email, 'phone' => (string) ($d['phone'] ?? ''),
                'dob' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) ? $dob : '', 'track' => (string) ($d['track'] ?? ''),
                'centre' => (string) ($d['centre'] ?? ''),
                'message' => 'Promoted from NextGen Genius (' . $nggId . (($d['displayId'] ?? '') !== '' ? ', ' . $d['displayId'] : '') . ').',
            ], static fn($v) => $v !== '') + ['source' => 'ngg', 'consent' => true]);
            if ($appId <= 0) {
                self::mark($nggId, 'failed', 0, 'Could not file their NGV application.');
                return ['ok' => false, 'status' => 'failed', 'error' => 'Could not file their NGV application.'];
            }
            $inv = NgvRegistration::invite($appId, 0);
            $ref = NgvRegistration::ensureRef($appId);
            self::mark($nggId, 'invited', 0, 'Invited to register at a CACENTRE (' . $ref . ')' . (empty($inv['ok']) ? ' — ' . (string) ($inv['error'] ?? '') : '') . '.');
            (new LmsRepository())->audit('ngv.intake', $email, 'promoted from NGG ' . $nggId . ' · invited to register (' . $ref . ')', 'ngg');
            return ['ok' => true, 'status' => 'invited', 'application_id' => $appId, 'ref' => $ref];
        }
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
        /* The name and email snapshot is set at enrolment and refreshed by the
           member themselves; an intake does not rename an existing participant. */
        $exists = NgvMember::participant($mid) !== null;
        $seed = $exists ? [] : ['name' => $name, 'email' => $email];
        $track = trim((string) ($d['track'] ?? ''));
        if ($track !== '' && !$exists) $seed['track'] = $track;
        NgvMember::ensureParticipant($mid, $seed);
        if (GateAttendance::cardFor($mid) === null) GateAttendance::assignCard($mid, '', 0);
        if ($email !== '') NgvMember::linkByEmail($mid, $email);
    }

    /**
     * NGG demoted them: withdrawn here, which stops accrual (after bringing it
     * up to date), voids their NGV card, and takes them off the member count.
     * Their record, payments and history stay. Idempotent; a member this site
     * never linked is acknowledged and nothing changes.
     */
    public static function revoke(array $d): array
    {
        self::ensure();
        $nggId = mb_substr(trim((string) ($d['nggMemberId'] ?? '')), 0, 64);
        if ($nggId === '') return ['ok' => true, 'status' => 'bad_event'];
        $row = self::row($nggId);
        /* Demoted while still only invited: the invitation is withdrawn, so the
           desk cannot register somebody NGG no longer puts forward. */
        if ($row && (string) $row['status'] === 'invited') {
            NgvDb::pdo()->prepare("UPDATE ngv_applications SET status = 'withdrawn' WHERE LOWER(email) = ? AND source = 'ngg' AND status IN ('invited','booked','attended')")
                ->execute([strtolower((string) $row['email'])]);
            self::mark($nggId, 'revoked', 0, 'Demoted on NGG before registering — invitation withdrawn.');
            return ['ok' => true, 'status' => 'revoked'];
        }
        if (!$row || (int) $row['member_id'] <= 0) return ['ok' => true, 'status' => 'unknown'];
        $mid = (int) $row['member_id'];
        try {
            $p = NgvMember::participant($mid);
            if ($p && (string) $p['status'] !== 'withdrawn') NgvMember::setAdmin($mid, ['status' => 'withdrawn']);
            self::mark($nggId, 'revoked', $mid, 'Demoted on NGG — withdrawn here, card voided.');
            (new LmsRepository())->audit('ngv.intake.revoked', (string) $row['email'], 'demoted on NGG ' . $nggId, 'ngg');
        } catch (Throwable $e) {
            error_log('[ngv] revoke: ' . $e->getMessage());
            return ['ok' => false, 'status' => 'failed', 'error' => 'Could not withdraw them yet.'];
        }
        return ['ok' => true, 'status' => 'revoked', 'member_id' => $mid];
    }

    private static function under18(string $dob): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) return false;
        $t = strtotime($dob . ' +18 years');
        return $t !== false && $t > time();
    }

    /** @return array{status:string, method:string, at:string, by:string} */
    private static function cleanConsent(array $c): array
    {
        $status = (string) ($c['status'] ?? '');
        return [
            'status' => in_array($status, ['granted', 'none', 'n/a'], true) ? $status : 'none',
            'method' => mb_substr(trim((string) ($c['method'] ?? '')), 0, 40),
            'at'     => mb_substr(trim((string) ($c['at'] ?? '')), 0, 32),
            'by'     => mb_substr(trim((string) ($c['by'] ?? '')), 0, 80),
        ];
    }

    private static function keepConsent(string $nggId, bool $minor, ?array $consent): void
    {
        Database::pdo()->prepare('UPDATE ngv_intake SET minor = ?, consent = ? WHERE ngg_member_id = ?')
            ->execute([$minor ? 1 : 0, $consent ? mb_substr((string) json_encode($consent), 0, 255) : '', $nggId]);
    }

    /**
     * The office has the guardian's consent in hand (a signed form, a call):
     * record who gave it and how, then finish the promotion as NGG sent it.
     */
    public static function recordConsent(string $nggId, string $method, string $guardian, string $actor): array
    {
        self::ensure();
        $row = self::row($nggId);
        if (!$row) return ['ok' => false, 'error' => 'No such promotion.'];
        if ((string) $row['status'] !== 'needs_consent') return ['ok' => false, 'error' => 'This promotion is not waiting for consent.'];
        $method = trim($method); $guardian = trim($guardian);
        if (!in_array($method, ['signed_form', 'in_person', 'phone', 'email'], true)) return ['ok' => false, 'error' => 'Say how the consent was given.'];
        if ($guardian === '') return ['ok' => false, 'error' => 'Name the parent or guardian who consented.'];
        $consent = ['status' => 'granted', 'method' => $method, 'at' => self::now(), 'by' => mb_substr($guardian, 0, 80)];
        self::keepConsent($nggId, true, $consent);
        (new LmsRepository())->audit('ngv.intake.consent', (string) $row['email'], 'guardian consent for NGG ' . $nggId . ' recorded (' . $method . ', ' . $consent['by'] . ')', $actor);
        return self::take(['nggMemberId' => $nggId, 'name' => (string) $row['name'], 'email' => (string) $row['email'], 'guardianConsent' => $consent]);
    }

    private static function nameKey(string $n): string
    {
        return preg_replace('/[^a-z]/', '', mb_strtolower($n)) ?? '';
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

    /**
     * The desk has registered somebody NGG promoted: the promotion is linked to
     * their new account, and their NGV ID card is theirs.
     */
    public static function registered(string $email, int $memberId): void
    {
        self::ensure();
        Database::pdo()->prepare("UPDATE ngv_intake SET status = 'linked', member_id = ?, detail = ?, updated_at = ? WHERE LOWER(email) = ? AND status = 'invited'")
            ->execute([$memberId, 'Registered at a CACENTRE.', self::now(), strtolower(trim($email))]);
    }

    /** Promotions waiting for something a person has to supply — for the Members dashboard. */
    public static function waiting(): array
    {
        self::ensure();
        return Database::pdo()->query("SELECT ngg_member_id, name, email, status, detail, received_at FROM ngv_intake WHERE status IN ('needs_email', 'needs_consent', 'needs_review', 'failed') ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
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
