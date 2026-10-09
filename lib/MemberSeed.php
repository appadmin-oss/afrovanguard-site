<?php
/**
 * lib/MemberSeed.php — the founding members, put on the system once (owner, 2026-10-09).
 *
 * Each row is a printed ID, a name and an email. The ID's first letter is the
 * member's LEVEL (Levels), "AVM" an Afrovanguard member and "NGV" a NextGen
 * Vanguard, the two digits after it the year they joined:
 *
 *     E-AVM-17-0001   Level E, Afrovanguard member since 2017
 *     B-NGV-25-0005   Level B, NextGen Vanguard since 2025
 *
 * ── THE ACCOUNT IS THE ONE THEY ALREADY HAVE ────────────────────────────────
 * Several of them have signed in already. MemberRoster::create() finds an
 * account by email (case-insensitive) and LINKS to it — it never makes a
 * second one; only somebody with no account gets a new one. Then:
 *   · the name set to the one in this list — it is what the card prints, and
 *     a sign-in may have left a short form ("Anu O.") that prints as a surname;
 *   · role raised to `member` only if it is below member — an admin or a
 *     coordinator is never lowered, and a learner who signed up through the
 *     Academy becomes the member they are;
 *   · level set to the ID's letter;
 *   · NGV: enrolled as a participant and given exactly this NGV number at the
 *     gate (GateAttendance::assignCard — never the next minted one);
 *   · AVM: the ID recorded as their printed member ID under the
 *     "Afrovanguard member IDs" format (A-AVM-99-9999), so the gate reads it;
 *   · a secure card (AVQR-…) if they have none.
 *
 * Every step is idempotent: run twice, nothing changes the second time.
 *
 * ── WHEN IT RUNS ────────────────────────────────────────────────────────────
 * The live site is shared hosting with no shell, so it runs itself: on the
 * first request after deploy (lib/bootstrap.php), once, claimed in app_meta
 * before the work so two simultaneous requests do not both run it. A failure
 * releases the claim to try again, at most three times. An admin can re-run
 * it from the member desk's API (`member_seed`) — harmless, by the above.
 */
declare(strict_types=1);

final class MemberSeed
{
    public const META = 'seed_founding_members_v1';
    public const ID_FORMAT_LABEL = 'Afrovanguard member IDs';
    public const ID_FORMAT_MASK  = 'A-AVM-99-9999';

    /** [printed ID, name, email] */
    public const MEMBERS = [
        ['E-AVM-17-0001', 'Babatunde Adeola',     'boja@afrovanguard.org.ng'],
        ['D-AVM-17-0002', 'Anuoluwapo Ogunbanjo', 'anu@afrovanguard.org.ng'],
        ['D-AVM-17-0003', 'Ujagbe Onofua',        'ujagbe@afrovanguard.org.ng'],
        ['B-NGV-25-0005', 'Divine-Joy Godwin',    'imabong@afrovanguard.org.ng'],
        ['A-NGV-25-0007', 'Chioma Ruth Nwazi',    'chioma@afrovanguard.org.ng'],
        ['A-NGV-25-0009', 'Josiah Eleyinmi',      'seun@afrovanguard.org.ng'],
        ['A-NGV-26-0013', 'Pelumi Odikayo',       'pelumi@afrovanguard.org.ng'],
        ['A-NGV-26-0017', 'Ohunene Muhammed',     'ohunene@afrovanguard.org.ng'],
        ['A-NGV-26-0018', 'Omolade Oyedokun',     'omolade@afrovanguard.org.ng'],
    ];

    /** Once per site, from the bootstrap. Cheap when done: one indexed read. */
    public static function boot(): void
    {
        try {
            $state = (string) (Database::metaGet(self::META) ?? '');
            if (str_starts_with($state, 'done') || str_starts_with($state, 'claimed') || substr_count($state, 'failed') >= 3) return;
            Database::metaSet(self::META, 'claimed ' . gmdate('c'));
            $r = self::run('seed');
            Database::metaSet(self::META, 'done ' . gmdate('c') . ' · ' . $r['created'] . ' created, ' . $r['linked'] . ' linked');
        } catch (Throwable $e) {
            error_log('[member-seed] ' . $e->getMessage());
            /* Release the claim so a later request tries again; count the failures. */
            try { Database::metaSet(self::META, trim($state . ' failed')); } catch (Throwable $e2) {}
        }
    }

    /**
     * Put every row on the system. Returns a line per member.
     *
     * @return array{ok:bool, created:int, linked:int, rows:list<array>}
     */
    public static function run(string $actor = 'seed'): array
    {
        $format = self::idFormat($actor);
        $out = ['ok' => true, 'created' => 0, 'linked' => 0, 'rows' => []];
        foreach (self::MEMBERS as [$code, $name, $email]) {
            $row = self::one($code, $name, $email, $format, $actor);
            if (!$row['ok']) $out['ok'] = false;
            elseif (!empty($row['linked'])) $out['linked']++;
            else $out['created']++;
            $out['rows'][] = $row;
        }
        if (class_exists('AdminAudit')) {
            try { AdminAudit::log('members', 'member_seed', '', $out['created'] . ' created, ' . $out['linked'] . ' linked', null, $actor); } catch (Throwable $e) {}
        }
        return $out;
    }

    /** The ID format AVM numbers are recorded under, made once. */
    private static function idFormat(string $actor): string
    {
        foreach (MemberCards::formats() as $f) {
            if ((string) ($f['mask'] ?? '') === self::ID_FORMAT_MASK && str_starts_with((string) ($f['id'] ?? ''), 'id-')) return (string) $f['id'];
        }
        $r = MemberCards::saveIdFormat(self::MEMBERS[0][0], self::ID_FORMAT_LABEL, self::ID_FORMAT_MASK, $actor);
        if (empty($r['ok'])) throw new RuntimeException('ID format: ' . (string) ($r['error'] ?? 'refused'));
        return (string) ($r['format']['id'] ?? '');
    }

    private static function one(string $code, string $name, string $email, string $format, string $actor): array
    {
        if (!preg_match('/^([A-Z])-(AVM|NGV)-\d{2}-\d{4}$/', $code, $m)) return ['ok' => false, 'code' => $code, 'error' => 'not an ID'];
        [, $level, $kind] = $m;

        $c = MemberRoster::create(['name' => $name, 'email' => $email, 'level' => $level, 'role' => 'member'], $actor, 'seed');
        if (empty($c['ok'])) return ['ok' => false, 'code' => $code, 'email' => $email, 'error' => (string) ($c['error'] ?? 'refused')];
        $id = (int) $c['id'];

        /* The name in this list is the one the card prints (owner's records).
           An account made at sign-in may carry a short form ("Anu O.") that
           would print as the surname, so the list's name wins. */
        $had = (string) (Database::pdo()->query('SELECT name FROM lms_users WHERE id = ' . $id)->fetchColumn() ?: '');
        if ($had !== $name) {
            Database::pdo()->prepare('UPDATE lms_users SET name = ? WHERE id = ?')->execute([$name, $id]);
            if (class_exists('AdminAudit')) { try { AdminAudit::log('members', 'member_seed_name', (string) $id, $had . ' → ' . $name, null, $actor); } catch (Throwable $e) {} }
        }

        /* Raise, never lower: a learner becomes a member; staff stay staff. */
        $role = (string) (Database::pdo()->query('SELECT role FROM lms_users WHERE id = ' . $id)->fetchColumn() ?: 'learner');
        if (LmsAuth::rank($role) < LmsAuth::rank('member')) {
            Database::pdo()->prepare("UPDATE lms_users SET role = 'member' WHERE id = ?")->execute([$id]);
        }
        if (class_exists('Levels') && Levels::of($id) !== $level) Levels::set($id, $level, $actor);

        $number = 'none';
        if ($kind === 'NGV') {
            if (class_exists('NgvMember')) NgvMember::ensureParticipant($id, ['name' => $name, 'email' => $email]);
            $a = GateAttendance::assignCard($id, $code, 0);
            $number = !empty($a['ok']) ? (!empty($a['already']) ? 'already' : 'assigned') : 'refused: ' . (string) ($a['error'] ?? '');
        } else {
            $number = MemberCards::recordPrinted($id, $format, $code, $actor, 'seed');
        }
        MemberCards::ensureFor($id, $actor, 'seed');

        return ['ok' => !str_starts_with($number, 'refused') && $number !== 'taken' && $number !== 'skipped',
                'code' => $code, 'email' => $email, 'id' => $id, 'linked' => !empty($c['linked']), 'number' => $number];
    }
}
