<?php
/**
 * lib/MemberRoster.php — the member desk: creating, managing and importing
 * Afrovanguard's members, the way NGG's Control Room does it.
 *
 * Members are lms_users. Until this, the Studio could make one from a name, an
 * email and an access level, and nothing else: no phone, centre or join date,
 * no validation beyond the email, no way in for a membership that already
 * lives in a spreadsheet, and no card the CACENTRE gate could read.
 *
 * Every write goes the same way, which is what makes it safe to hand to
 * somebody who is not a developer:
 *
 *   validate → dry run (what WOULD happen, and a digest naming it)
 *            → apply  (only the dry run that was read: the digest must match)
 *            → audit  (lms_audit, a line per member, and a run record)
 *
 * A single "add member" is the one-row case of the same thing: validated
 * first, refused in words, never half-written.
 *
 * ── WHAT IS DELIBERATELY NOT DONE ───────────────────────────────────────────
 *   · Two members with one email. Sign-in resolves an email to one account;
 *     a second would never be able to sign in. Refused, never merged.
 *   · Merging by name. "A. Bello" and "Ade Bello" may be one person or two;
 *     the duplicates list shows them, a person decides.
 *   · Changing a staff member's access from a spreadsheet. A coordinator or
 *     admin is not a row; their role, status, name and email stay as they are.
 *
 * ── ONE PERSON, ONE ACCOUNT, BY EMAIL ───────────────────────────────────────
 * Every member has an email: it is how they sign in, so an import row without
 * one is refused, not invented. An email that already has an account IS that
 * account — the row updates it and links it, never creates a second. Linking
 * also reaches the NGV side: an NGV application or enrolment held under the
 * same address is attached to the account (NgvMember::linkByEmail).
 *
 * NextGen Vanguards are members too, with the same @afrovanguard.org.ng
 * address as staff. They are told apart by their NGV record — enrolment or an
 * X-NGV-YY-NNNN card — never by the email (NgvMember::isVanguard).
 */
declare(strict_types=1);

final class MemberRoster
{
    public const MAX_ROWS = 2000;
    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        $pdo = Database::pdo();
        Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS member_profiles (
            user_id     INTEGER PRIMARY KEY,
            phone       VARCHAR(24) NOT NULL DEFAULT '',
            centre      VARCHAR(60) NOT NULL DEFAULT '',
            joined_on   VARCHAR(10) NOT NULL DEFAULT '',
            notes       TEXT,
            source      VARCHAR(16) NOT NULL DEFAULT '',
            updated_at  VARCHAR(32) NOT NULL DEFAULT ''
        )");
        Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS member_import_runs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            digest     VARCHAR(64) NOT NULL,
            source     VARCHAR(255) NOT NULL DEFAULT '',
            actor      VARCHAR(120) NOT NULL DEFAULT '',
            created    INTEGER NOT NULL DEFAULT 0,
            updated    INTEGER NOT NULL DEFAULT 0,
            unchanged  INTEGER NOT NULL DEFAULT 0,
            failed     INTEGER NOT NULL DEFAULT 0,
            cards      INTEGER NOT NULL DEFAULT 0,
            row_count  INTEGER NOT NULL DEFAULT 0,
            at         VARCHAR(32) NOT NULL DEFAULT ''
        )");
        if (class_exists('Levels')) Levels::ensure();
        if (class_exists('Birthdays')) Birthdays::ensure();
        MemberCards::ensure();
    }

    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
    private static function lms(): LmsRepository { return new LmsRepository(); }


    /** A Nigerian number that lost its leading zero to a spreadsheet's number column gets it back. */
    public static function cleanPhone(string $raw): string
    {
        $p = preg_replace('/[^\d+]/', '', trim($raw)) ?? '';
        if (preg_match('/^[789]\d{9}$/', $p)) $p = '0' . $p;
        return substr($p, 0, 24);
    }

    /* ══ Validation ═════════════════════════════════════════════════════════ */

    /**
     * One member's fields, checked. Returns the clean values and every problem
     * in words — not the first one, so a row is fixed in one pass.
     *
     * @return array{ok:bool, clean:array, errors:array<string,string>}
     */
    public static function validate(array $d, ?int $selfId = null): array
    {
        self::ensure();
        $e = []; $c = [];
        $c['name'] = trim(preg_replace('/\s+/', ' ', (string) ($d['name'] ?? '')) ?? '');
        if (mb_strlen($c['name']) < 2) $e['name'] = 'A member needs a name.';
        elseif (mb_strlen($c['name']) > 120) $e['name'] = 'That name is longer than 120 characters.';

        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if ($email === '') {
            $e['email'] = 'An email is how a member signs in. Every member needs one.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $e['email'] = '“' . $email . '” is not an email address.';
        } else {
            $st = Database::pdo()->prepare('SELECT id, name FROM lms_users WHERE LOWER(email) = ?');
            $st->execute([$email]);
            $dup = $st->fetch(PDO::FETCH_ASSOC);
            if ($dup && (int) $dup['id'] !== (int) $selfId) $e['email'] = $email . ' already belongs to ' . ($dup['name'] ?: 'another member') . '. One email, one member.';
        }
        $c['email'] = $email;

        if (array_key_exists('role', $d) && (string) $d['role'] !== '') {
            $c['role'] = strtolower(trim((string) $d['role']));
            if (!isset(LmsAuth::ROLE_RANK[$c['role']])) $e['role'] = 'Access level is one of ' . implode(', ', array_keys(LmsAuth::ROLE_RANK)) . '.';
        }
        if (array_key_exists('status', $d) && (string) $d['status'] !== '') {
            $c['status'] = strtolower(trim((string) $d['status']));
            if (!in_array($c['status'], ['active', 'suspended'], true)) $e['status'] = 'Status is active or suspended.';
        }
        if (array_key_exists('level', $d) && (string) $d['level'] !== '') {
            $c['level'] = strtoupper(trim((string) $d['level']));
            $order = class_exists('Levels') ? Levels::order() : ['O', 'A', 'B', 'C'];
            if (!in_array($c['level'], $order, true)) $e['level'] = 'Level is one of ' . implode(', ', $order) . '.';
        }
        if (array_key_exists('birthday', $d) && trim((string) $d['birthday']) !== '') {
            $b = trim((string) $d['birthday']);
            if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $b, $m)) $b = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);   // a spreadsheet's 14/07/1998
            if (!preg_match('/^(\d{4}-)?\d{2}-\d{2}$/', $b)) $e['birthday'] = 'Give a birthday like 1998-07-14, or 07-14 without the year.';
            else {
                $md = substr($b, -5); [$mm, $dd] = array_map('intval', explode('-', $md));
                if (!checkdate($mm, $dd, 2024)) $e['birthday'] = 'That birthday is not a real date.';
            }
            $c['birthday'] = $b;
        }
        if (array_key_exists('phone', $d)) {
            $c['phone'] = self::cleanPhone((string) $d['phone']);
            if ($c['phone'] !== '' && strlen(preg_replace('/\D/', '', $c['phone'])) < 7) $e['phone'] = 'That phone number is too short.';
        }
        if (array_key_exists('centre', $d)) $c['centre'] = mb_substr(trim((string) $d['centre']), 0, 60);
        if (array_key_exists('joined_on', $d) && trim((string) $d['joined_on']) !== '') {
            $j = trim((string) $d['joined_on']);
            if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $j, $m)) $j = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $j) || !checkdate((int) substr($j, 5, 2), (int) substr($j, 8, 2), (int) substr($j, 0, 4))) $e['joined_on'] = 'Joined is a date, like 2024-01-06.';
            elseif ($j > gmdate('Y-m-d')) $e['joined_on'] = 'Joined cannot be in the future.';
            $c['joined_on'] = $j;
        }
        if (array_key_exists('notes', $d)) $c['notes'] = mb_substr(trim((string) $d['notes']), 0, 4000);
        return ['ok' => !$e, 'clean' => $c, 'errors' => $e];
    }

    /* ══ One member ═════════════════════════════════════════════════════════ */

    /**
     * Add a member. Validated first; a secure gate card issued at once, so a
     * member made here is printed with a card nobody can guess.
     */
    public static function create(array $d, string $actor, string $source = 'studio'): array
    {
        /* An email that already has an account IS that account. Adding them
           again links to it: blanks are filled from what was typed, nothing
           already recorded is replaced, and nobody gets a second record. */
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::ensure();
            $st = Database::pdo()->prepare('SELECT id FROM lms_users WHERE LOWER(email) = ?');
            $st->execute([$email]);
            if ($existing = (int) $st->fetchColumn()) {
                $have = self::get($existing);
                $fill = [];
                foreach (['phone', 'centre', 'level', 'birthday', 'joined_on', 'notes'] as $k) {
                    $given = trim((string) ($d[$k] ?? ''));
                    /* Level is never blank — everybody starts at the base — so
                       "unset" for it means still at the base level. */
                    $unset = $k === 'level' ? (string) ($have['level'] ?? '') === (class_exists('Levels') ? Levels::base() : 'O')
                                            : trim((string) ($have[$k] ?? '')) === '';
                    if ($given !== '' && $unset) $fill[$k] = $given;
                }
                $r = $fill ? self::update($existing, $fill, $actor) : ['ok' => true, 'changed' => []];
                if (!$r['ok']) return $r;
                $card = MemberCards::ensureFor($existing, $actor, $source)['secure'];
                $linkedNgv = class_exists('NgvMember') ? NgvMember::linkByEmail($existing, $email) : 0;
                self::lms()->audit('member.link', $email, 'already a member — linked' . ($r['changed'] ? ', filled ' . implode(', ', $r['changed']) : ''), $actor);
                return ['ok' => true, 'id' => $existing, 'card' => $card, 'email' => $email, 'linked' => true,
                        'filled' => $r['changed'], 'ngv_linked' => $linkedNgv, 'name' => $have['name']];
            }
        }
        $v = self::validate($d, null);
        if (!$v['ok']) return ['ok' => false, 'error' => reset($v['errors']), 'errors' => $v['errors']];
        $c = $v['clean'];
        $email = $c['email'];
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$c['name'], $email, password_hash(bin2hex(random_bytes(18)), PASSWORD_BCRYPT), $c['role'] ?? 'member', $c['status'] ?? 'active']);
        $id = (int) $pdo->lastInsertId();
        self::writeProfile($id, $c, $source);
        if (isset($c['level']) && class_exists('Levels')) Levels::set($id, $c['level'], $actor);
        if (isset($c['birthday']) && class_exists('Birthdays')) Birthdays::set($id, $c['birthday']);
        $card = MemberCards::issue($id, $actor, 'created');
        self::lms()->audit('member.create', $email, 'via ' . $source . ' · role ' . ($c['role'] ?? 'member'), $actor);
        if (class_exists('Events')) Events::emit('member.created', ['email' => $email, 'name' => $c['name'], 'role' => $c['role'] ?? 'member', 'via' => $source]);
        $linked = class_exists('NgvMember') ? NgvMember::linkByEmail($id, $email) : 0;
        return ['ok' => true, 'id' => $id, 'card' => $card, 'email' => $email, 'ngv_linked' => $linked];
    }

    /** Change a member. Only the fields given; each change audited by name, not value. */
    public static function update(int $id, array $d, string $actor): array
    {
        self::ensure();
        $u = self::user($id);
        if (!$u) return ['ok' => false, 'error' => 'No such member.'];
        if (!array_key_exists('name', $d)) $d['name'] = $u['name'];
        $emailGiven = array_key_exists('email', $d);
        if (!$emailGiven) $d['email'] = $u['email'];
        $v = self::validate($d, $id);
        if (!$v['ok']) return ['ok' => false, 'error' => reset($v['errors']), 'errors' => $v['errors']];
        $c = $v['clean'];
        $changed = [];
        $pdo = Database::pdo();
        if ($c['name'] !== (string) $u['name']) { $pdo->prepare('UPDATE lms_users SET name = ? WHERE id = ?')->execute([$c['name'], $id]); $changed[] = 'name'; }
        if ($emailGiven && $c['email'] !== '' && $c['email'] !== strtolower((string) $u['email'])) { $pdo->prepare('UPDATE lms_users SET email = ? WHERE id = ?')->execute([$c['email'], $id]); $changed[] = 'email'; }
        if (isset($c['role']) && $c['role'] !== (string) $u['role']) { $pdo->prepare('UPDATE lms_users SET role = ? WHERE id = ?')->execute([$c['role'], $id]); $changed[] = 'role'; }
        if (isset($c['status']) && $c['status'] !== (string) $u['status']) {
            $pdo->prepare('UPDATE lms_users SET status = ? WHERE id = ?')->execute([$c['status'], $id]);
            /* Suspending withdraws the gate pass and the card at the gate, as the existing console does. */
            if ($c['status'] === 'suspended' && class_exists('GatePass')) { try { GatePass::revoke($id); } catch (Throwable $e) {} }
            $changed[] = 'status';
        }
        if (isset($c['level']) && class_exists('Levels') && Levels::of($id) !== $c['level']) { Levels::set($id, $c['level'], $actor); $changed[] = 'level'; }
        if (array_key_exists('birthday', $d) && class_exists('Birthdays')) {
            $was = Birthdays::of($id); Birthdays::set($id, (string) ($c['birthday'] ?? ''));
            if (Birthdays::of($id) !== $was) $changed[] = 'birthday';
        }
        $p = self::profile($id);
        foreach (['phone', 'centre', 'joined_on', 'notes'] as $k) if (array_key_exists($k, $c) && (string) $c[$k] !== (string) ($p[$k] ?? '')) $changed[] = $k;
        if (array_intersect($changed, ['phone', 'centre', 'joined_on', 'notes'])) self::writeProfile($id, $c + $p, (string) ($p['source'] ?? 'studio'));
        if ($changed) self::lms()->audit('member.update', (string) $u['email'], implode(', ', $changed), $actor);
        return ['ok' => true, 'changed' => $changed, 'member' => self::get($id)];
    }

    private static function writeProfile(int $id, array $c, string $source): void
    {
        $pdo = Database::pdo();
        $vals = [mb_substr((string) ($c['phone'] ?? ''), 0, 24), mb_substr((string) ($c['centre'] ?? ''), 0, 60), (string) ($c['joined_on'] ?? ''), (string) ($c['notes'] ?? ''), $source, self::now()];
        $st = $pdo->prepare('UPDATE member_profiles SET phone = ?, centre = ?, joined_on = ?, notes = ?, source = ?, updated_at = ? WHERE user_id = ?');
        $st->execute([...$vals, $id]);
        if ($st->rowCount() === 0) {
            $chk = $pdo->prepare('SELECT 1 FROM member_profiles WHERE user_id = ?'); $chk->execute([$id]);
            if (!$chk->fetchColumn()) $pdo->prepare('INSERT INTO member_profiles (phone, centre, joined_on, notes, source, updated_at, user_id) VALUES (?,?,?,?,?,?,?)')->execute([...$vals, $id]);
        }
    }

    private static function user(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT id, name, email, role, status, created_at, last_login FROM lms_users WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function profile(int $id): array
    {
        $st = Database::pdo()->prepare('SELECT phone, centre, joined_on, notes, source FROM member_profiles WHERE user_id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: ['phone' => '', 'centre' => '', 'joined_on' => '', 'notes' => '', 'source' => ''];
    }

    /** A member's whole record, for the drawer. */
    public static function get(int $id): ?array
    {
        self::ensure();
        $u = self::user($id);
        if (!$u) return null;
        $b = class_exists('Birthdays') ? Birthdays::of($id) : null;
        return $u + self::profile($id) + [
            'level' => class_exists('Levels') ? Levels::of($id) : 'O',
            'birthday' => $b ? (($b['year'] ?? 0) > 0 ? $b['year'] . '-' : '') . $b['birthday'] : '',
            'ngv' => class_exists('NgvMember') && NgvMember::isVanguard($id),
            'cards' => MemberCards::of($id),
        ];
    }

    /* ══ The roster ═════════════════════════════════════════════════════════ */

    private const SORTS = ['name' => 'u.name', 'joined' => 'u.created_at', 'role' => 'u.role', 'status' => 'u.status', 'centre' => 'p.centre'];

    /** Filtered, sorted and paged on the server — sorting a page in hand orders page three and calls it the roster. */
    public static function roster(array $f): array
    {
        self::ensure();
        $w = []; $a = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') { $like = '%' . str_replace(['%', '_', '\\'], '', mb_strtolower($q)) . '%'; $w[] = '(LOWER(u.name) LIKE ? OR LOWER(u.email) LIKE ? OR p.phone LIKE ?)'; array_push($a, $like, $like, $like); }
        foreach (['role' => 'u.role', 'status' => 'u.status', 'centre' => 'p.centre', 'level' => 'u.level'] as $k => $col) {
            if ((string) ($f[$k] ?? '') !== '') { $w[] = "$col = ?"; $a[] = (string) $f[$k]; }
        }
        $missing = [
            'phone' => "COALESCE(p.phone, '') = ''",
            'birthday' => "COALESCE(u.birthday, '') = ''",
            'card' => "NOT EXISTS (SELECT 1 FROM av_member_cards c WHERE c.member_id = u.id AND c.kind = 'secure' AND c.status = 'active')",
        ];
        if (isset($missing[(string) ($f['missing'] ?? '')])) $w[] = $missing[(string) $f['missing']];
        /* NextGen Vanguards, or members who are not. The NGV record lives in its
           own database, so the ids are read there and filtered here. */
        $kind = (string) ($f['kind'] ?? '');
        if ($kind === 'ngv' || $kind === 'member') {
            $ids = array_keys(self::vanguardIds());
            if ($kind === 'ngv') $w[] = $ids ? 'u.id IN (' . implode(',', array_map('intval', $ids)) . ')' : '1 = 0';
            elseif ($ids) $w[] = 'u.id NOT IN (' . implode(',', array_map('intval', $ids)) . ')';
        }
        $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
        $from = ' FROM lms_users u LEFT JOIN member_profiles p ON p.user_id = u.id' . $where;
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COUNT(*)' . $from); $st->execute($a);
        $total = (int) $st->fetchColumn();
        $sort = self::SORTS[(string) ($f['sort'] ?? '')] ?? 'u.id';
        $dir = strtolower((string) ($f['dir'] ?? '')) === 'asc' ? 'ASC' : 'DESC';
        $size = max(10, min(200, (int) ($f['page_size'] ?? 50)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $st = $pdo->prepare('SELECT u.id, u.name, u.email, u.role, u.status, u.created_at, u.last_login, u.level, u.birthday, u.birth_year, p.phone, p.centre, p.joined_on,
                (SELECT c.code FROM av_member_cards c WHERE c.member_id = u.id AND c.kind = \'secure\' AND c.status = \'active\' ORDER BY c.id DESC LIMIT 1) AS card'
            . $from . " ORDER BY $sort $dir, u.id DESC LIMIT $size OFFSET " . (($page - 1) * $size));
        $st->execute($a);
        $ngv = self::vanguardIds();
        $rows = array_map(static fn($r) => $r + ['ngv' => isset($ngv[(int) $r['id']])], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['members' => $rows, 'total' => $total, 'page' => $page, 'page_size' => $size];
    }

    /** Every NextGen Vanguard's member id, as a set: enrolled, or holding an NGV card. */
    public static function vanguardIds(): array
    {
        $ids = [];
        try { foreach (NgvDb::pdo()->query('SELECT member_id FROM ngv_participants WHERE member_id > 0')->fetchAll(PDO::FETCH_COLUMN) as $m) $ids[(int) $m] = true; } catch (Throwable $e) {}
        try { GateAttendance::ensure(); foreach (Database::pdo()->query("SELECT member_id FROM gate_member_cards WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $m) $ids[(int) $m] = true; } catch (Throwable $e) {}
        return $ids;
    }

    /**
     * How many members there are, where, growing how fast — and what is wrong
     * with the records. Only problems that exist are listed: a panel of zeroes
     * trains people to skip it, including on the day it is not zero.
     */
    public static function overview(): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $col = static fn(string $sql) => $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
        $one = static fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
        $total = $one('SELECT COUNT(*) FROM lms_users');
        $since = gmdate('Y-m-01', strtotime('-11 months'));
        $joined = [];
        foreach ($pdo->query("SELECT created_at FROM lms_users WHERE created_at >= '$since'")->fetchAll(PDO::FETCH_COLUMN) as $at) {
            $m = substr((string) $at, 0, 7); $joined[$m] = ($joined[$m] ?? 0) + 1;
        }
        $months = [];
        for ($i = 11; $i >= 0; $i--) { $m = gmdate('Y-m', strtotime(gmdate('Y-m-01') . " -$i months")); $months[] = ['month' => $m, 'joined' => $joined[$m] ?? 0]; }
        $quality = array_filter([
            'phone' => ['n' => $one("SELECT COUNT(*) FROM lms_users u LEFT JOIN member_profiles p ON p.user_id = u.id WHERE COALESCE(p.phone, '') = ''"), 'label' => 'have no phone number'],
            'birthday' => ['n' => $one("SELECT COUNT(*) FROM lms_users WHERE COALESCE(birthday, '') = ''"), 'label' => 'have no birthday recorded'],
            'card' => ['n' => $one("SELECT COUNT(*) FROM lms_users u WHERE u.status = 'active' AND NOT EXISTS (SELECT 1 FROM av_member_cards c WHERE c.member_id = u.id AND c.kind = 'secure' AND c.status = 'active')"), 'label' => 'have no card for the gate'],
            'duplicates' => ['n' => count(self::duplicates()), 'label' => 'names appear on more than one record'],
        ], static fn($x) => $x['n'] > 0);
        return [
            'total' => $total,
            'vanguards' => count(array_intersect_key(self::vanguardIds(), array_flip(array_map('intval', $pdo->query('SELECT id FROM lms_users')->fetchAll(PDO::FETCH_COLUMN))))),
            'by_status' => $col('SELECT status, COUNT(*) FROM lms_users GROUP BY status'),
            'by_role' => $col('SELECT role, COUNT(*) FROM lms_users GROUP BY role'),
            'by_level' => $col("SELECT COALESCE(level, 'O'), COUNT(*) FROM lms_users GROUP BY COALESCE(level, 'O')"),
            'by_centre' => $col("SELECT COALESCE(NULLIF(p.centre, ''), '(none)'), COUNT(*) FROM lms_users u LEFT JOIN member_profiles p ON p.user_id = u.id GROUP BY COALESCE(NULLIF(p.centre, ''), '(none)')"),
            'joined' => $months,
            'quality' => $quality,
        ];
    }

    /** Records that look like one person: the same name, or the same phone. Shown, never merged. */
    public static function duplicates(): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $groups = [];
        $rows = $pdo->query('SELECT u.id, u.name, u.email, p.phone FROM lms_users u LEFT JOIN member_profiles p ON p.user_id = u.id')->fetchAll(PDO::FETCH_ASSOC);
        $by = [];
        foreach ($rows as $r) {
            $n = preg_replace('/[^a-z]/', '', mb_strtolower((string) $r['name']));
            if ($n !== '' && strlen($n) >= 4) $by['name:' . $n][] = $r;
            $ph = preg_replace('/\D/', '', (string) ($r['phone'] ?? ''));
            if (strlen($ph) >= 7) $by['phone:' . substr($ph, -10)][] = $r;
        }
        foreach ($by as $k => $list) if (count($list) > 1) $groups[] = ['why' => str_starts_with($k, 'name:') ? 'same name' : 'same phone', 'members' => $list];
        return array_slice($groups, 0, 100);
    }

    /* ══ Import ═════════════════════════════════════════════════════════════ */

    /** Column names people actually use, to the field they mean. */
    private const HEADERS = [
        'name' => 'name', 'full name' => 'name', 'fullname' => 'name', 'member name' => 'name',
        'email' => 'email', 'email address' => 'email', 'e-mail' => 'email',
        'phone' => 'phone', 'phone number' => 'phone', 'mobile' => 'phone', 'mobile line' => 'phone', 'whatsapp' => 'phone',
        'centre' => 'centre', 'center' => 'centre', 'location' => 'centre',
        'role' => 'role', 'access level' => 'role', 'status' => 'status', 'level' => 'level',
        'birthday' => 'birthday', 'date of birth' => 'birthday', 'dob' => 'birthday',
        'joined' => 'joined_on', 'date joined' => 'joined_on', 'joined on' => 'joined_on',
        'ngv' => 'ngv', 'ngv number' => 'ngv', 'ngv id' => 'ngv', 'member id' => 'ngv', 'id number' => 'ngv',
        'card' => 'card_code', 'card code' => 'card_code', 'card number' => 'card_code', 'old card' => 'card_code',
        'notes' => 'notes',
    ];

    /**
     * A spreadsheet saved as CSV, to rows. The file arrives as ONE value: a
     * host's firewall that counts the values in a request (NGG measured this
     * on cPanel: a few thousand values is a 406) passes a whole roster as one.
     */
    public static function parseCsv(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;           // Excel's byte-order mark
        $lines = preg_split('/\r\n|\n|\r/', trim($text));
        if (!$lines || trim((string) $lines[0]) === '') return ['ok' => false, 'error' => 'The file is empty.'];
        $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $head = array_map(static fn($h) => strtolower(trim(preg_replace('/[_\s]+/', ' ', (string) $h) ?? '')), str_getcsv((string) array_shift($lines), $sep, '"', '\\'));
        $map = []; $unknown = [];
        foreach ($head as $i => $h) { if (isset(self::HEADERS[$h])) $map[$i] = self::HEADERS[$h]; elseif ($h !== '') $unknown[] = $h; }
        if (!in_array('name', $map, true)) return ['ok' => false, 'error' => 'There is no Name column. The first row must name the columns: Name, Email, Phone, Centre…'];
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cells = str_getcsv($line, $sep, '"', '\\');
            $r = [];
            foreach ($map as $i => $field) $r[$field] = trim((string) ($cells[$i] ?? ''));
            if (implode('', $r) !== '') $rows[] = $r;
            if (count($rows) > self::MAX_ROWS) return ['ok' => false, 'error' => 'More than ' . self::MAX_ROWS . ' rows — that is not this membership.'];
        }
        return ['ok' => true, 'rows' => $rows, 'columns' => array_values(array_unique($map)), 'unknown_columns' => $unknown];
    }

    /** What an import would DO, as a hash: row order, key order and blanks do not change it; the card format does. */
    public static function digest(array $rows, string $cardFormat, bool $overwrite): string
    {
        $norm = [];
        foreach ($rows as $r) {
            $r = array_filter(array_map(static fn($v) => trim((string) $v), (array) $r), static fn($v) => $v !== '');
            ksort($r); $norm[] = json_encode($r, JSON_UNESCAPED_UNICODE);
        }
        sort($norm);
        return hash('sha256', implode("\n", $norm) . "\n#" . $cardFormat . ($overwrite ? '#overwrite' : ''));
    }

    private static function match(array $c, array $row, string $cardFormat): ?int
    {
        $pdo = Database::pdo();
        if (($c['email'] ?? '') !== '') {
            $st = $pdo->prepare('SELECT id FROM lms_users WHERE LOWER(email) = ?'); $st->execute([$c['email']]);
            if ($id = $st->fetchColumn()) return (int) $id;
        }
        if (($row['ngv'] ?? '') !== '') {
            GateAttendance::ensure();
            $st = $pdo->prepare('SELECT member_id FROM gate_member_cards WHERE code = ?'); $st->execute([strtoupper(trim((string) $row['ngv']))]);
            if ($id = $st->fetchColumn()) return (int) $id;
        }
        if (($row['card_code'] ?? '') !== '' && $cardFormat !== '') {
            $hit = MemberCards::lookup(null, $cardFormat, (string) $row['card_code']);
            if ($hit) return $hit['member_id'];
        }
        return null;
    }

    /**
     * Import members. A dry run unless `apply`; an apply must name the dry
     * run it is applying (`expect_digest`), or it is refused.
     *
     * opts: apply, expect_digest, overwrite (replace values a member already
     * has; default fills blanks only), card_format (the format the `card_code`
     * column is read by), source, actor.
     */
    public static function import(array $rows, array $opts = []): array
    {
        self::ensure();
        $apply = !empty($opts['apply']); $overwrite = !empty($opts['overwrite']);
        $actor = (string) ($opts['actor'] ?? 'studio');
        $cardFormat = (string) ($opts['card_format'] ?? '');
        if ($cardFormat !== '' && !MemberCards::format($cardFormat)) return ['ok' => false, 'error' => 'No card format “' . $cardFormat . '”. Describe it first.'];
        if (!$rows) return ['ok' => false, 'error' => 'There are no rows to import.'];
        if (count($rows) > self::MAX_ROWS) return ['ok' => false, 'error' => 'More than ' . self::MAX_ROWS . ' rows — that is not this membership.'];
        $digest = self::digest($rows, $cardFormat, $overwrite);
        if ($apply) {
            $expect = trim((string) ($opts['expect_digest'] ?? ''));
            if ($expect === '') return ['ok' => false, 'error' => 'Read the dry run first — an import has to name the dry run it is applying.', 'code' => 'preview_required'];
            if (!hash_equals($digest, $expect)) return ['ok' => false, 'error' => 'This is not the file you checked. Check it again before importing.', 'code' => 'roster_changed', 'digest' => $digest];
        }
        $pdo = Database::pdo();
        $prev = $pdo->prepare('SELECT at, actor, created, updated FROM member_import_runs WHERE digest = ? ORDER BY id DESC LIMIT 1');
        $prev->execute([$digest]);
        $report = ['ok' => true, 'applied' => $apply, 'digest' => $digest, 'previous' => $prev->fetch(PDO::FETCH_ASSOC) ?: null,
                   'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'linked' => 0, 'rows' => [],
                   'cards' => ['issued' => 0, 'printed' => 0, 'ngv' => 0, 'taken' => 0, 'would_issue' => 0, 'format' => $cardFormat]];
        $seenEmail = [];
        foreach ($rows as $i => $row) {
            $row = array_map(static fn($v) => trim((string) $v), (array) $row);
            $label = ($row['ngv'] ?? '') ?: ($row['email'] ?? '') ?: 'row ' . ($i + 2);
            $id = null;
            $v0 = self::validate(['name' => $row['name'] ?? ''] + array_intersect_key($row, array_flip(['email', 'phone', 'centre', 'role', 'status', 'level', 'birthday', 'joined_on', 'notes'])), null);
            $c = $v0['clean'];
            $id = self::match($c, $row, $cardFormat);
            if ($id !== null) {
                /* The duplicate-email error is about another member; an existing
                   member matched on their own email is not a duplicate of themselves. */
                $v0 = self::validate(['name' => $row['name'] ?? ''] + array_intersect_key($row, array_flip(['email', 'phone', 'centre', 'role', 'status', 'level', 'birthday', 'joined_on', 'notes'])), $id);
                $c = $v0['clean'];
            }
            $errors = $v0['errors'];
            if ($c['email'] !== '' && isset($seenEmail[$c['email']])) $errors['email'] = $c['email'] . ' is also on row ' . $seenEmail[$c['email']] . '. One email, one member.';
            if ($c['email'] !== '') $seenEmail[$c['email']] = $i + 2;
            if (($row['ngv'] ?? '') !== '' && !preg_match(GateAttendance::CARD_PATTERN, strtoupper($row['ngv']))) $errors['ngv'] = 'An NGV number reads like A-NGV-25-0001.';
            if ($errors) {
                $report['failed']++;
                $report['rows'][] = ['ref' => $label, 'action' => 'failed', 'name' => $row['name'] ?? '', 'detail' => implode(' ', $errors)];
                continue;
            }
            $warn = [];
            if ($id === null) {
                $report['created']++;
                if (!$apply) $report['cards']['would_issue']++;
                $made = null;
                if ($apply) {
                    $made = self::create($c, $actor, 'import');
                    if (!$made['ok']) { $report['created']--; $report['failed']++; $report['rows'][] = ['ref' => $label, 'action' => 'failed', 'name' => $c['name'], 'detail' => $made['error']]; continue; }
                    $id = (int) $made['id']; $report['cards']['issued']++;
                }
                $report['rows'][] = ['ref' => $label, 'action' => 'create', 'name' => $c['name'], 'detail' => trim(($c['centre'] ?? '') . ' · ' . ($c['role'] ?? 'member'), ' ·'), 'warnings' => $warn];
            } else {
                $have = self::get($id);
                $isStaff = LmsAuth::rank((string) $have['role']) > LmsAuth::ROLE_RANK['member'];
                /* An email already on an account is that account: say so, so the
                   person reading the dry run sees the link before it is made. */
                $warn[] = 'linked to the existing account ' . $have['email'] . (!empty($have['ngv']) ? ' (NextGen Vanguard)' : '');
                if ($isStaff) $warn[] = 'holds the ' . $have['role'] . ' access level — name, email, access and status left as they are';
                $patch = [];
                foreach (['name', 'email', 'phone', 'centre', 'role', 'status', 'level', 'birthday', 'joined_on', 'notes'] as $k) {
                    if (!array_key_exists($k, $c) || (string) $c[$k] === '') continue;
                    if ($isStaff && in_array($k, ['name', 'email', 'role', 'status'], true)) continue;
                    $cur = (string) ($have[$k] ?? '');
                    if ($cur !== '' && !$overwrite) continue;
                    if (strcasecmp($cur, (string) $c[$k]) === 0) continue;
                    $patch[$k] = $c[$k];
                }
                if (!$apply && MemberCards::secure($id) === null) $report['cards']['would_issue']++;
                if ($patch) {
                    if ($apply) {
                        $r = self::update($id, $patch, $actor);
                        if (!$r['ok']) { $report['failed']++; $report['rows'][] = ['ref' => $label, 'action' => 'failed', 'name' => $c['name'], 'detail' => $r['error']]; continue; }
                    }
                    $report['updated']++;
                    $report['rows'][] = ['ref' => $label, 'action' => 'update', 'name' => $have['name'], 'detail' => implode(', ', array_keys($patch)), 'warnings' => $warn];
                } else {
                    $report['unchanged']++;
                    if ($warn) $report['rows'][] = ['ref' => $label, 'action' => 'unchanged', 'name' => $have['name'], 'detail' => '', 'warnings' => $warn];
                }
            }
            /* Cards: a secure one for everybody the import touches, and the card
               they already hold kept working — their NGV number, or an old card
               read by the format the importer named. */
            if ($apply && $id !== null) {
                /* The NGV side of the same person, by the same email. */
                if (($c['email'] ?? '') !== '' && class_exists('NgvMember')) $report['linked'] += NgvMember::linkByEmail($id, $c['email']);
                $r = MemberCards::ensureFor($id, $actor, 'import', $cardFormat !== '' ? $cardFormat : null, (string) ($row['card_code'] ?? ''));
                if ($r['issued']) $report['cards']['issued']++;
                if ($r['printed'] === 'recorded') $report['cards']['printed']++;
                if ($r['printed'] === 'taken') $report['cards']['taken']++;
                if (($row['ngv'] ?? '') !== '' && GateAttendance::cardFor($id) !== strtoupper($row['ngv'])) {
                    $g = GateAttendance::assignCard($id, $row['ngv'], 0);
                    if (!empty($g['ok'])) $report['cards']['ngv']++; else $report['cards']['taken']++;
                }
            }
        }
        if ($apply) {
            $pdo->prepare('INSERT INTO member_import_runs (digest, source, actor, created, updated, unchanged, failed, cards, row_count, at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$digest, mb_substr((string) ($opts['source'] ?? ''), 0, 255), mb_substr($actor, 0, 120), $report['created'], $report['updated'], $report['unchanged'], $report['failed'], $report['cards']['issued'], count($rows), self::now()]);
            self::lms()->audit('member.import', (string) ($opts['source'] ?? ''), sprintf('%d new · %d updated · %d unchanged · %d unusable · %d cards', $report['created'], $report['updated'], $report['unchanged'], $report['failed'], $report['cards']['issued']), $actor);
        }
        return $report;
    }
}
