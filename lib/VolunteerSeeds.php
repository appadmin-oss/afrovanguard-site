<?php
/**
 * lib/VolunteerSeeds.php — NGG volunteers put on the site from a spreadsheet,
 * waiting for the person to sign in.
 *
 * NGG's own volunteer export (volunteerId, name, email, phone, role,
 * programme, centre, faculty, courses, safeguarding, status, createdAt) is
 * uploaded in the Studio (Members → Volunteers → Seed from a file). Each row
 * becomes an NGG volunteer record here — the same table the signed NGG sync
 * fills (lib/NggVolunteers.php) — so everything that already knows an NGG
 * volunteer knows these: the Volunteer dashboard opens for them, the People
 * directory lists them, their NGG sessions count as hours, and a later sync
 * from NGG updates the same row by its volunteer ID rather than adding one.
 *
 * ── LINKED AS THEY SIGN IN ─────────────────────────────────────────────────
 * Nobody gets an account made for them. When somebody signs in (any way:
 * password, Google, a code by email), claim() looks for a seeded row with
 * their email, case-insensitive, and links it. Failing that, a row whose
 * phone matches the one phone on exactly one member profile — never a phone
 * shared by two accounts. People who already have an account are linked the
 * moment the file is applied.
 *
 * ── A DRY RUN FIRST ────────────────────────────────────────────────────────
 * import() reports what it would do: new, updated, unchanged, refused (no
 * email, a bad email, an email twice in the file), possible duplicates (same
 * name and phone under two IDs), and who already has an account. Applying
 * names the dry run's digest, so the file applied is the file read.
 *
 * The file itself is never stored in the repository: it names real people.
 */
declare(strict_types=1);

final class VolunteerSeeds
{
    public const MAX_ROWS = 5000;

    /** Header (lower-cased, spaces and underscores dropped) → field. */
    private const HEADERS = [
        'volunteerid' => 'id', 'volunteer' => 'id', 'id' => 'id', 'volid' => 'id',
        'name' => 'name', 'fullname' => 'name',
        'email' => 'email', 'emailaddress' => 'email',
        'phone' => 'phone', 'phonenumber' => 'phone', 'mobile' => 'phone',
        'role' => 'role', 'programme' => 'programme', 'program' => 'programme',
        'centre' => 'centre', 'center' => 'centre', 'faculty' => 'faculty',
        'courses' => 'courses', 'course' => 'courses', 'safeguarding' => 'safeguarding',
        'status' => 'status', 'createdat' => 'joined', 'joined' => 'joined', 'joinedat' => 'joined',
    ];

    /** How a programme reads to the volunteer. */
    public const PROGRAMMES = ['summer' => 'NGG Summer School', 'ngg' => 'NextGen Genius'];

    /** CSV text → rows. The file arrives as one value (see MemberRoster::parseCsv). */
    public static function parseCsv(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $lines = preg_split('/\r\n|\n|\r/', trim($text));
        if (!$lines || trim((string) $lines[0]) === '') return ['ok' => false, 'error' => 'The file is empty.'];
        $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $map = [];
        foreach (str_getcsv((string) array_shift($lines), $sep, '"', '\\') as $i => $h) {
            $k = strtolower(preg_replace('/[\s_\-]+/', '', (string) $h) ?? '');
            if (isset(self::HEADERS[$k])) $map[$i] = self::HEADERS[$k];
        }
        if (!in_array('id', $map, true) || !in_array('email', $map, true)) {
            return ['ok' => false, 'error' => 'The first row must name the columns, with at least volunteerId and email (NGG’s volunteer export has both).'];
        }
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cells = str_getcsv($line, $sep, '"', '\\');
            $r = [];
            foreach ($map as $i => $f) $r[$f] = trim((string) ($cells[$i] ?? ''));
            if (implode('', $r) !== '') $rows[] = $r;
            if (count($rows) > self::MAX_ROWS) return ['ok' => false, 'error' => 'More than ' . self::MAX_ROWS . ' rows.'];
        }
        return ['ok' => true, 'rows' => $rows];
    }

    /** One row, cleaned and checked: [clean, error|null]. */
    public static function clean(array $r): array
    {
        $s = static fn(string $k, int $n) => mb_substr(trim((string) ($r[$k] ?? '')), 0, $n);
        $role = strtolower($s('role', 40));
        $prog = strtolower($s('programme', 20));
        $c = [
            'id' => strtoupper($s('id', 40)), 'name' => $s('name', 160), 'email' => strtolower($s('email', 200)),
            'phone' => class_exists('MemberRoster') ? MemberRoster::cleanPhone($s('phone', 32)) : $s('phone', 32),
            'role' => $role !== '' ? $role : 'volunteer', 'programme' => isset(self::PROGRAMMES[$prog]) ? $prog : ($prog === '' ? 'ngg' : $prog),
            'centre' => $s('centre', 80), 'faculty' => $s('faculty', 80), 'courses' => $s('courses', 200),
            'safeguarding_cleared' => in_array(strtolower($s('safeguarding', 20)), ['cleared', 'yes', 'true', '1', 'done'], true) ? 1 : 0,
            'status' => in_array(strtolower($s('status', 16)), ['inactive', 'suspended'], true) ? strtolower($s('status', 16)) : 'active',
            'joined_at' => $s('joined', 40),
        ];
        $c['role_label'] = self::roleLabel($c);
        if ($c['id'] === '') return [$c, 'No volunteer ID.'];
        if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/_.]{1,39}$/', $c['id'])) return [$c, 'The volunteer ID “' . $c['id'] . '” has characters an ID cannot.'];
        if ($c['name'] === '') return [$c, 'No name.'];
        if ($c['email'] === '' || !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) return [$c, 'No usable email — it is how they are recognised when they sign in.'];
        return [$c, null];
    }

    /** "Instructor — Catering", "Mentor — Creative Arts", "Admin". */
    public static function roleLabel(array $c): string
    {
        $role = ucfirst((string) ($c['role'] ?? 'volunteer'));
        $what = (string) ($c['courses'] ?? '') !== '' ? (string) $c['courses'] : (string) ($c['faculty'] ?? '');
        return mb_substr($role . ($what !== '' ? ' — ' . $what : ''), 0, 80);
    }

    public static function digest(array $rows): string
    {
        $n = [];
        foreach ($rows as $r) { [$c] = self::clean((array) $r); ksort($c); $n[] = json_encode($c, JSON_UNESCAPED_UNICODE); }
        sort($n);
        return hash('sha256', implode("\n", $n));
    }

    /**
     * Seed the rows. A dry run unless opts.apply, and an apply must carry the
     * dry run's digest (opts.expect_digest).
     */
    public static function import(array $rows, array $opts = []): array
    {
        NggVolunteers::ensure();
        $pdo = Database::pdo();
        $apply = !empty($opts['apply']);
        $digest = self::digest($rows);
        if ($apply && (string) ($opts['expect_digest'] ?? '') !== $digest) {
            return ['ok' => false, 'error' => 'The file changed since the dry run. Run the dry run again, then apply.'];
        }
        $seenEmail = []; $seenId = []; $byNamePhone = [];
        $out = []; $count = ['new' => 0, 'update' => 0, 'same' => 0, 'refused' => 0, 'has_account' => 0];
        $now = gmdate('Y-m-d H:i:s');
        foreach (array_values($rows) as $i => $r) {
            [$c, $err] = self::clean((array) $r);
            $line = ['row' => $i + 2, 'id' => $c['id'], 'name' => $c['name'], 'email' => $c['email'], 'role' => $c['role_label'],
                     'programme' => self::PROGRAMMES[$c['programme']] ?? $c['programme'], 'centre' => $c['centre'], 'notes' => []];
            if ($err === null && isset($seenId[$c['id']])) $err = 'The ID is also on row ' . $seenId[$c['id']] . '.';
            if ($err === null && isset($seenEmail[$c['email']])) $err = 'The email is also on row ' . $seenEmail[$c['email']] . ' — one email is one person here.';
            if ($err !== null) { $line['action'] = 'refused'; $line['notes'][] = $err; $count['refused']++; $out[] = $line; continue; }
            $seenId[$c['id']] = $i + 2; $seenEmail[$c['email']] = $i + 2;
            $np = mb_strtolower($c['name']) . '|' . preg_replace('/\D/', '', $c['phone']);
            if ($c['phone'] !== '' && isset($byNamePhone[$np])) $line['notes'][] = 'Same name and phone as ' . $byNamePhone[$np] . ' — probably one person with two emails. Both are kept; whichever they sign in with is linked.';
            $byNamePhone[$np] = $c['id'];

            $st = $pdo->prepare('SELECT * FROM ngg_volunteers WHERE volunteer_id = ?');
            $st->execute([$c['id']]);
            $had = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            $line['action'] = !$had ? 'new' : (self::differs($had, $c) ? 'update' : 'same');
            if ($had && ($had['origin'] ?? 'ngg') === 'ngg' && (string) $had['synced_at'] !== '') $line['notes'][] = 'NGG already sent this volunteer; the file fills in programme, faculty and courses.';
            $count[$line['action']]++;
            $acct = self::accountByEmail($c['email']);
            if ($acct) { $line['account'] = $acct; $count['has_account']++; }
            if ($apply && $line['action'] !== 'same') self::write($c, $had, $now);
            $out[] = $line;
        }
        $linked = 0;
        if ($apply) {
            $linked = self::linkAll();
            if (class_exists('AdminAudit')) {
                try { AdminAudit::log('members', 'volunteers.seed', mb_substr((string) ($opts['source'] ?? 'file'), 0, 120),
                    $count['new'] . ' new, ' . $count['update'] . ' updated, ' . $linked . ' linked', null, (string) ($opts['actor'] ?? 'studio')); } catch (Throwable $e) {}
            }
        }
        return ['ok' => true, 'applied' => $apply, 'digest' => $digest, 'counts' => $count, 'linked' => $linked, 'rows' => $out];
    }

    private static function differs(array $had, array $c): bool
    {
        foreach (['name', 'email', 'phone', 'role', 'role_label', 'centre', 'programme', 'faculty', 'courses', 'safeguarding_cleared', 'status'] as $k) {
            if ((string) ($had[$k] ?? '') !== (string) $c[$k]) return true;
        }
        return false;
    }

    private static function write(array $c, ?array $had, string $now): void
    {
        $pdo = Database::pdo();
        if ($had) {
            /* A row NGG sent stays NGG's: the file only adds what NGG does not send. */
            if (($had['origin'] ?? 'ngg') === 'ngg' && (string) $had['synced_at'] !== '') {
                $pdo->prepare('UPDATE ngg_volunteers SET programme = ?, faculty = ?, courses = ? WHERE volunteer_id = ?')
                    ->execute([$c['programme'], $c['faculty'], $c['courses'], $c['id']]);
                return;
            }
            $pdo->prepare('UPDATE ngg_volunteers SET name=?, email=?, phone=?, role=?, role_label=?, centre=?, status=?, safeguarding_cleared=?, joined_at=?, programme=?, faculty=?, courses=?, seeded_at=? WHERE volunteer_id=?')
                ->execute([$c['name'], $c['email'], $c['phone'], $c['role'], $c['role_label'], $c['centre'], $c['status'], $c['safeguarding_cleared'], $c['joined_at'], $c['programme'], $c['faculty'], $c['courses'], $now, $c['id']]);
            return;
        }
        $pdo->prepare("INSERT INTO ngg_volunteers (volunteer_id, name, email, phone, role, role_label, centre, status, safeguarding_cleared, joined_at, programme, faculty, courses, origin, seeded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'seed',?)")
            ->execute([$c['id'], $c['name'], $c['email'], $c['phone'], $c['role'], $c['role_label'], $c['centre'], $c['status'], $c['safeguarding_cleared'], $c['joined_at'], $c['programme'], $c['faculty'], $c['courses'], $now]);
    }

    private static function accountByEmail(string $email): ?array
    {
        $st = Database::pdo()->prepare('SELECT id, name FROM lms_users WHERE LOWER(email) = ? LIMIT 1');
        $st->execute([$email]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id' => (int) $r['id'], 'name' => (string) $r['name']] : null;
    }

    /** Link every unlinked row to an account with its email. Returns how many. */
    public static function linkAll(): int
    {
        return NggVolunteers::link();
    }

    /**
     * At sign-in (LmsAuth::startSession): the seeded rows that are this
     * person — by email, else by a phone only their profile carries — are
     * linked to them. Cheap when there is nothing to do; never in the way.
     */
    public static function claim(int $uid): int
    {
        if ($uid <= 0) return 0;
        try {
            NggVolunteers::ensure();
            $pdo = Database::pdo();
            $st = $pdo->prepare('SELECT email FROM lms_users WHERE id = ?');
            $st->execute([$uid]);
            $email = strtolower(trim((string) $st->fetchColumn()));
            $n = 0;
            if ($email !== '') {
                $u = $pdo->prepare("UPDATE ngg_volunteers SET user_id = ?, linked_via = 'email' WHERE email = ? AND user_id <> ?");
                $u->execute([$uid, $email, $uid]);
                $n += $u->rowCount();
            }
            if ($n === 0) $n += self::claimByPhone($uid);
            return $n;
        } catch (Throwable $e) { error_log('[volunteer-seeds] claim: ' . $e->getMessage()); return 0; }
    }

    private static function claimByPhone(int $uid): int
    {
        $pdo = Database::pdo();
        try {
            $st = $pdo->prepare('SELECT phone FROM member_profiles WHERE user_id = ?');
            $st->execute([$uid]);
            $phone = (string) ($st->fetchColumn() ?: '');
        } catch (Throwable $e) { return 0; }
        $tail = substr(preg_replace('/\D/', '', $phone) ?? '', -10);
        if (strlen($tail) < 10) return 0;
        /* Only a phone that is this account's alone. */
        $owners = 0;
        foreach ($pdo->query("SELECT phone FROM member_profiles WHERE phone <> ''")->fetchAll(PDO::FETCH_COLUMN) as $p) {
            if (substr(preg_replace('/\D/', '', (string) $p) ?? '', -10) === $tail) $owners++;
        }
        if ($owners !== 1) return 0;
        $n = 0;
        $st = $pdo->query("SELECT volunteer_id, phone FROM ngg_volunteers WHERE user_id = 0 AND phone <> '' AND status = 'active'");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (substr(preg_replace('/\D/', '', (string) $r['phone']) ?? '', -10) !== $tail) continue;
            $pdo->prepare("UPDATE ngg_volunteers SET user_id = ?, linked_via = 'phone' WHERE volunteer_id = ? AND user_id = 0")->execute([$uid, $r['volunteer_id']]);
            $n++;
        }
        return $n;
    }

    /** For the Studio: how many were seeded, and how many have signed in. */
    public static function status(): array
    {
        NggVolunteers::ensure();
        $pdo = Database::pdo();
        $one = static fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
        return ['seeded' => $one("SELECT COUNT(*) FROM ngg_volunteers WHERE seeded_at <> ''"),
                'linked' => $one("SELECT COUNT(*) FROM ngg_volunteers WHERE seeded_at <> '' AND user_id > 0"),
                'waiting' => $one("SELECT COUNT(*) FROM ngg_volunteers WHERE seeded_at <> '' AND user_id = 0 AND status = 'active'")];
    }
}
