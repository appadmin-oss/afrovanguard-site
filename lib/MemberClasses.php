<?php
/**
 * lib/MemberClasses.php — what KIND of member somebody is, and what that kind
 * pays in monthly dues.
 *
 * Dues were one price for everybody (`av_dues_settings.monthly_ngn`), so a
 * student on an allowance and a working professional owed the same figure, and
 * the only way to charge them differently was to waive one of them by hand
 * every month. A class is the office's answer to "what kind of member is this",
 * and the price hangs off it:
 *
 *   Student    ₦x / month      Working    ₦y / month
 *   Volunteer  ₦z / month      Patron     owes nothing
 *
 * ── THE CLASS IS NOT THE ROLE, AND NOT THE LEVEL ────────────────────────────
 * The account role (learner / member / coordinator …) says what somebody may
 * DO in the software. The Level (O, A, B …) says how far they have come. The
 * class says what they pay. Tying the price to either of the other two would
 * mean a promotion changed somebody's bill, which is how an organisation ends
 * up not promoting people.
 *
 * ── A CLASS CHANGE IS DATED, AND THE PAST KEEPS ITS PRICE ───────────────────
 * `member_class_log` is the record: one row per change, with the day it takes
 * effect, who made it and why. Arrears walk the months owed and price EACH
 * MONTH at the class in force on the day it fell due (`Membership::owed()`).
 *
 * This is the whole reason for the log. Pricing arrears at today's class would
 * mean moving a student onto the working rate silently re-priced every month
 * they had already fallen behind — the office would type one correction and
 * somebody's arrears would treble, with nothing on the screen saying why.
 *
 * ── NOTHING CHANGES UNTIL THE OFFICE USES IT ────────────────────────────────
 * No class, or a class with no price of its own, reads the single price in
 * `av_dues_settings` exactly as before. A deployment that never opens this
 * screen is charged what it was charged yesterday, to the naira — and
 * `tests/memberclasses.test.php` asserts that, because a money change nobody
 * asked for is the worst kind.
 *
 * Self-provisioning and cross-engine (SQLite / MySQL / Postgres) through
 * Database::execSchema, like every other table here. `class_key` is spelled
 * out rather than `key`, which is reserved in MySQL.
 */
declare(strict_types=1);

final class MemberClasses
{
    public const NAME_MAX = 40;
    public const DESC_MAX = 500;
    public const KEY_MAX  = 32;
    /** The same ceiling dues use, so a typo cannot produce a nine-figure bill. */
    public const PRICE_MAX = Membership::PRICE_MAX_NGN;

    /**
     * The classes a fresh deployment starts with. They are a STARTING POINT,
     * not a fixed list: the office renames them, re-prices them, adds its own
     * and archives the ones it does not use. Prices are 0 so that nothing is
     * charged on a figure nobody chose — a class with no price of its own
     * falls back to the single dues price, which is what runs today.
     */
    public const SEED = [
        ['class_key' => 'student',   'name' => 'Student',   'owes' => 1, 'description' => 'In school, university or NYSC. The reduced rate.'],
        ['class_key' => 'working',   'name' => 'Working',   'owes' => 1, 'description' => 'In paid work or running a business. The standard rate.'],
        ['class_key' => 'volunteer', 'name' => 'Volunteer', 'owes' => 1, 'description' => 'Gives time to a team rather than money. Price it low or at zero.'],
        ['class_key' => 'associate', 'name' => 'Associate', 'owes' => 1, 'description' => 'Belongs, but is not on a team and not on a programme.'],
        ['class_key' => 'patron',    'name' => 'Patron',    'owes' => 0, 'description' => 'Supports the organisation on their own terms. Owes no dues.'],
        ['class_key' => 'honorary',  'name' => 'Honorary',  'owes' => 0, 'description' => 'Given the standing by leadership. Owes no dues.'],
    ];

    private static bool $ready = false;
    private static ?array $cache = null;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        $pdo = Database::pdo();
        Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS member_classes (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            class_key   VARCHAR(32) NOT NULL DEFAULT '',
            name        VARCHAR(40) NOT NULL DEFAULT '',
            description VARCHAR(500) NOT NULL DEFAULT '',
            monthly_ngn INTEGER NOT NULL DEFAULT 0,
            annual_ngn  INTEGER NOT NULL DEFAULT 0,
            owes        INTEGER NOT NULL DEFAULT 1,
            position    INTEGER NOT NULL DEFAULT 0,
            archived    INTEGER NOT NULL DEFAULT 0,
            created_at  VARCHAR(32) NOT NULL DEFAULT '',
            updated_at  VARCHAR(32) NOT NULL DEFAULT ''
        )");
        try { $pdo->exec('CREATE UNIQUE INDEX uq_member_class_key ON member_classes (class_key)'); } catch (Throwable $e) {}
        /* One row per change, never an edit: what somebody's arrears cost
           depends on which class they were in each month, so the history IS
           the price list for the past. */
        Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS member_class_log (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL DEFAULT 0,
            class_key  VARCHAR(32) NOT NULL DEFAULT '',
            from_day   VARCHAR(10) NOT NULL DEFAULT '',
            note       VARCHAR(500) NOT NULL DEFAULT '',
            set_by     VARCHAR(191) NOT NULL DEFAULT '',
            set_at     VARCHAR(32) NOT NULL DEFAULT ''
        )");
        try { $pdo->exec('CREATE INDEX idx_member_class_log_user ON member_class_log (user_id, from_day)'); } catch (Throwable $e) {}
        /* Where a member's CURRENT class lives — beside their phone and centre,
           because it is a fact about the person, not about a payment. */
        if (class_exists('MemberRoster')) MemberRoster::ensure();
        try {
            if (!Database::columnExists('member_profiles', 'member_class')) {
                $pdo->exec("ALTER TABLE member_profiles ADD COLUMN member_class VARCHAR(32) NOT NULL DEFAULT ''");
            }
        } catch (Throwable $e) { error_log('[memberclasses] member_profiles.member_class: ' . $e->getMessage()); }
        self::seed();
    }

    /** The starting list, once, and only into an empty table. */
    private static function seed(): void
    {
        try {
            if ((int) Database::pdo()->query('SELECT COUNT(*) FROM member_classes')->fetchColumn() > 0) return;
            $now = gmdate('Y-m-d H:i:s');
            $st = Database::pdo()->prepare('INSERT INTO member_classes (class_key, name, description, monthly_ngn, annual_ngn, owes, position, archived, created_at, updated_at)
                                            VALUES (?, ?, ?, 0, 0, ?, ?, 0, ?, ?)');
            foreach (self::SEED as $i => $c) {
                $st->execute([$c['class_key'], $c['name'], $c['description'], (int) $c['owes'], $i * 10, $now, $now]);
            }
        } catch (Throwable $e) { error_log('[memberclasses] seed: ' . $e->getMessage()); }
    }

    public static function forget(): void { self::$cache = null; }

    /** class_key => row, in the office's order. Archived ones included: a
     *  member may still be in one, and their past months must still price. */
    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;
        self::ensure();
        $out = [];
        try {
            $rows = Database::pdo()->query('SELECT * FROM member_classes ORDER BY position, name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) $out[(string) $r['class_key']] = self::shape($r);
        } catch (Throwable $e) { error_log('[memberclasses] all: ' . $e->getMessage()); }
        return self::$cache = $out;
    }

    /** The ones the office may put somebody in now. */
    public static function active(): array
    {
        return array_filter(self::all(), static fn($c) => !$c['archived']);
    }

    public static function get(string $key): ?array
    {
        $key = self::normaliseKey($key);
        return $key === '' ? null : (self::all()[$key] ?? null);
    }

    public static function label(string $key): string
    {
        $c = self::get($key);
        return $c ? $c['name'] : '';
    }

    private static function shape(array $r): array
    {
        $monthly = max(0, (int) ($r['monthly_ngn'] ?? 0));
        $annual  = max(0, (int) ($r['annual_ngn'] ?? 0));
        return [
            'id'          => (int) ($r['id'] ?? 0),
            'class_key'   => (string) ($r['class_key'] ?? ''),
            'name'        => (string) ($r['name'] ?? ''),
            'description' => (string) ($r['description'] ?? ''),
            'monthly_ngn' => $monthly,
            'annual_ngn'  => $annual,
            'owes'        => (int) ($r['owes'] ?? 1) === 1,
            'position'    => (int) ($r['position'] ?? 0),
            'archived'    => (int) ($r['archived'] ?? 0) === 1,
            /* A class with no price of its own is not free — it is on the
               single dues price, which is what every member is on today. */
            'own_price'   => $monthly > 0 || $annual > 0,
        ];
    }

    /** A key from a name: lower case, letters, digits and dashes. */
    public static function normaliseKey(string $k): string
    {
        $k = strtolower(trim($k));
        $k = (string) preg_replace('/[^a-z0-9\-]+/', '-', $k);
        return mb_substr(trim($k, '-'), 0, self::KEY_MAX);
    }

    /* ══ What a class costs ═══════════════════════════════════════════════ */

    /**
     * The monthly and yearly price for a class, falling back to the single
     * dues price where the class has none of its own. A class that owes
     * nothing costs nothing, whatever is typed in its price fields.
     *
     * @return array{monthly:int, annual:int, owes:bool, source:string}
     */
    public static function priceOf(string $key): array
    {
        $s = Membership::settings();
        $base = ['monthly' => (int) $s['monthly_ngn'], 'annual' => (int) $s['annual_ngn'], 'owes' => true, 'source' => 'dues'];
        $c = self::get($key);
        if (!$c) return $base;
        if (!$c['owes']) return ['monthly' => 0, 'annual' => 0, 'owes' => false, 'source' => 'class'];
        if (!$c['own_price']) return $base + ['source' => 'dues'];
        $monthly = $c['monthly_ngn'];
        /* A class priced by the month and not by the year gets twelve months,
           not a free year: a missing yearly price is a blank, not a discount. */
        $annual = $c['annual_ngn'] > 0 ? $c['annual_ngn'] : $monthly * 12;
        return ['monthly' => $monthly, 'annual' => $annual, 'owes' => true, 'source' => 'class'];
    }

    /**
     * What N months cost in one class: whole years at the yearly price, the
     * rest by the month — the same shape as Membership::price(), because a
     * year owed in one class is still a year.
     */
    public static function priceMonths(int $months, string $key): int
    {
        $months = max(0, $months);
        $p = self::priceOf($key);
        return intdiv($months, 12) * $p['annual'] + ($months % 12) * $p['monthly'];
    }

    /* ══ Who is in which class ════════════════════════════════════════════ */

    /** A member's class key now, or '' where the office has not set one. */
    public static function of(int $userId): string
    {
        if ($userId <= 0) return '';
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT member_class FROM member_profiles WHERE user_id = ?');
            $st->execute([$userId]);
            return self::normaliseKey((string) ($st->fetchColumn() ?: ''));
        } catch (Throwable $e) { return ''; }
    }

    /** Several members' classes in one query: user_id => class_key. */
    public static function ofMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if (!$ids) return [];
        self::ensure();
        $out = [];
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $st = Database::pdo()->query("SELECT user_id, member_class FROM member_profiles
                                               WHERE user_id IN (" . implode(',', $chunk) . ") AND member_class <> ''");
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(int) $r['user_id']] = (string) $r['member_class'];
            }
        } catch (Throwable $e) { error_log('[memberclasses] ofMany: ' . $e->getMessage()); }
        return $out;
    }

    /**
     * The class in force for a member on a given day, from the log.
     *
     * The log is only written from the day the office starts using classes, so
     * a day before the first entry has no class and reads '' — which prices at
     * the single dues figure, exactly as that month was priced at the time.
     * Backdating is what `from_day` is for when the office means to re-price.
     */
    public static function onDay(int $userId, string $day): string
    {
        $h = self::historyFor($userId);
        $in = '';
        foreach ($h as $row) {
            if ($row['from_day'] > $day) break;
            $in = $row['class_key'];
        }
        return $in;
    }

    /** One member's class changes, oldest first. Read once per member per request. */
    private static array $memo = [];

    public static function historyFor(int $userId): array
    {
        if ($userId <= 0) return [];
        if (isset(self::$memo[$userId])) return self::$memo[$userId];
        self::ensure();
        $out = [];
        try {
            $st = Database::pdo()->prepare('SELECT class_key, from_day, note, set_by, set_at FROM member_class_log
                                             WHERE user_id = ? ORDER BY from_day, id');
            $st->execute([$userId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $key = (string) $r['class_key'];
                $out[] = ['class_key' => $key, 'label' => $key === '' ? 'no class' : (self::label($key) ?: $key),
                          'from_day' => (string) $r['from_day'],
                          'note' => (string) $r['note'], 'set_by' => (string) $r['set_by'], 'set_at' => (string) $r['set_at']];
            }
        } catch (Throwable $e) { error_log('[memberclasses] historyFor: ' . $e->getMessage()); }
        return self::$memo[$userId] = $out;
    }

    /**
     * Put a member in a class, from a day. Returns what happened in words.
     *
     * `from_day` is the day the class takes effect, not the day it was typed:
     * an office correcting a record from the beginning of the year backdates
     * it and the arrears re-price from there. It is refused in the future,
     * because a price nobody is on yet is a plan, not a record.
     */
    public static function assign(int $userId, string $key, string $fromDay, string $by, string $note = ''): array
    {
        self::ensure();
        if ($userId <= 0) return ['ok' => false, 'error' => 'No such member.'];
        $key = self::normaliseKey($key);
        if ($key !== '' && !self::get($key)) return ['ok' => false, 'code' => 'no_class', 'error' => 'There is no class called that.'];
        if ($key !== '' && (self::get($key)['archived'] ?? false)) {
            return ['ok' => false, 'code' => 'archived', 'error' => 'That class has been archived. Choose one in use, or bring it back first.'];
        }
        $today = gmdate('Y-m-d');
        $fromDay = trim($fromDay) !== '' ? trim($fromDay) : $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDay)) return ['ok' => false, 'code' => 'bad_day', 'error' => 'Give the day as YYYY-MM-DD.'];
        if ($fromDay > $today) return ['ok' => false, 'code' => 'future', 'error' => 'A class takes effect on a day that has come, not a day ahead.'];
        $was = self::of($userId);
        if ($was === $key) return ['ok' => true, 'unchanged' => true, 'class' => $key, 'said' => 'Already in that class.'];
        $pdo = Database::pdo();
        try {
            /* The profile row may not exist for an account nobody has edited. */
            $st = $pdo->prepare('SELECT 1 FROM member_profiles WHERE user_id = ?');
            $st->execute([$userId]);
            if ($st->fetchColumn()) {
                $pdo->prepare('UPDATE member_profiles SET member_class = ?, updated_at = ? WHERE user_id = ?')
                    ->execute([$key, gmdate('Y-m-d H:i:s'), $userId]);
            } else {
                $pdo->prepare('INSERT INTO member_profiles (user_id, member_class, updated_at) VALUES (?, ?, ?)')
                    ->execute([$userId, $key, gmdate('Y-m-d H:i:s')]);
            }
            $pdo->prepare('INSERT INTO member_class_log (user_id, class_key, from_day, note, set_by, set_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $key, $fromDay, mb_substr($note, 0, self::DESC_MAX), mb_substr($by, 0, 191), gmdate('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            error_log('[memberclasses] assign: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not record that.'];
        }
        unset(self::$memo[$userId]);   /* the history just changed; the memo is stale */
        if (class_exists('AdminAudit')) {
            try {
                AdminAudit::log('members', 'member_class_set', 'user:' . $userId,
                    ($was !== '' ? self::label($was) . ' → ' : '') . ($key !== '' ? self::label($key) : 'no class') . ' from ' . $fromDay
                    . ($note !== '' ? ' — ' . $note : ''), null, 'admin');
            } catch (Throwable $e) {}
        }
        return ['ok' => true, 'class' => $key, 'was' => $was, 'from' => $fromDay,
                'said' => ($key === '' ? 'Class removed' : 'Now ' . self::label($key)) . ', from ' . $fromDay . '.'];
    }


    /* ══ The office's list ════════════════════════════════════════════════ */

    /**
     * Add a class (id 0) or change one. Validated whole; nothing is written
     * if anything is wrong, and the key is fixed once made — it is what the
     * history points at, so renaming it would orphan every past month.
     */
    public static function save(array $in, string $by): array
    {
        self::ensure();
        $id = (int) ($in['id'] ?? 0);
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') return ['ok' => false, 'error' => 'Give the class a name.'];
        if (mb_strlen($name) > self::NAME_MAX) return ['ok' => false, 'error' => 'That name is longer than ' . self::NAME_MAX . ' characters.'];
        $desc = mb_substr(trim((string) ($in['description'] ?? '')), 0, self::DESC_MAX);
        $monthly = (int) ($in['monthly_ngn'] ?? 0);
        $annual  = (int) ($in['annual_ngn'] ?? 0);
        foreach (['monthly' => $monthly, 'yearly' => $annual] as $what => $n) {
            if ($n < 0) return ['ok' => false, 'error' => 'A ' . $what . ' price cannot be negative.'];
            if ($n > self::PRICE_MAX) return ['ok' => false, 'error' => 'That ' . $what . ' price is above the ceiling (₦' . number_format(self::PRICE_MAX) . ').'];
        }
        $owes = !empty($in['owes']);
        $archived = !empty($in['archived']);
        $now = gmdate('Y-m-d H:i:s');
        $pdo = Database::pdo();
        try {
            if ($id > 0) {
                $st = $pdo->prepare('SELECT class_key FROM member_classes WHERE id = ?');
                $st->execute([$id]);
                $key = (string) ($st->fetchColumn() ?: '');
                if ($key === '') return ['ok' => false, 'error' => 'No such class.'];
                $pdo->prepare('UPDATE member_classes SET name = ?, description = ?, monthly_ngn = ?, annual_ngn = ?, owes = ?, archived = ?, updated_at = ? WHERE id = ?')
                    ->execute([$name, $desc, $monthly, $annual, $owes ? 1 : 0, $archived ? 1 : 0, $now, $id]);
            } else {
                $key = self::normaliseKey((string) ($in['class_key'] ?? '') !== '' ? (string) $in['class_key'] : $name);
                if ($key === '') return ['ok' => false, 'error' => 'That name has no letters or digits in it.'];
                if (self::get($key)) return ['ok' => false, 'code' => 'exists', 'error' => 'There is already a class with that key (' . $key . ').'];
                $pos = (int) ($pdo->query('SELECT COALESCE(MAX(position), 0) + 10 FROM member_classes')->fetchColumn() ?: 10);
                $pdo->prepare('INSERT INTO member_classes (class_key, name, description, monthly_ngn, annual_ngn, owes, position, archived, created_at, updated_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$key, $name, $desc, $monthly, $annual, $owes ? 1 : 0, $pos, $archived ? 1 : 0, $now, $now]);
            }
        } catch (Throwable $e) {
            error_log('[memberclasses] save: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save that.'];
        }
        self::forget();
        if (class_exists('AdminAudit')) {
            try {
                AdminAudit::log('members', $id > 0 ? 'member_class_edit' : 'member_class_add', 'class:' . $key,
                    $name . ' — ' . ($owes ? '₦' . number_format($monthly) . '/month' : 'owes nothing')
                    . ($archived ? ' (archived)' : ''), null, 'admin');
            } catch (Throwable $e) {}
        }
        return ['ok' => true, 'class_key' => $key, 'said' => $name . ' saved.'];
    }

    /**
     * How many members are in each class: class_key => n, plus '' for the
     * members the office has not classed yet — the number that says how much
     * of this is actually in use.
     */
    public static function counts(): array
    {
        self::ensure();
        $out = [];
        foreach (array_keys(self::all()) as $k) $out[$k] = 0;
        $out[''] = 0;
        try {
            $where = class_exists('MemberRoster') ? MemberRoster::memberWhere() : "u.role <> 'learner'";
            $rows = Database::pdo()->query("SELECT COALESCE(NULLIF(p.member_class, ''), '') AS k, COUNT(*) AS n
                                              FROM lms_users u LEFT JOIN member_profiles p ON p.user_id = u.id
                                             WHERE $where GROUP BY k")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $k = (string) $r['k'];
                $out[$k] = ($out[$k] ?? 0) + (int) $r['n'];
            }
        } catch (Throwable $e) { error_log('[memberclasses] counts: ' . $e->getMessage()); }
        return $out;
    }
}
