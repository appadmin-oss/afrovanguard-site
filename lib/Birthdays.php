<?php
/**
 * lib/Birthdays.php — members' birthdays.
 *
 * The team directory (lib/people.php) has always had birthdays, for the
 * people on the public Team page. Members' accounts did not, so nothing that
 * knows a member — the portal, the CACENTRE gate — could wish them a happy
 * birthday. This is that, kept small:
 *
 *   lms_users.birthday    'MM-DD' — the day that is celebrated
 *   lms_users.birth_year  optional; 0 when the member would rather not say
 *
 * Month and day are stored apart from the year on purpose: a celebration
 * needs only the day, and a member can give that without giving their age.
 * Somebody born on 29 February is celebrated on the 28th in other years.
 *
 * The member sets it themselves on their Account card; NGV staff can set it
 * from the attendance console for a participant who told them in person.
 * It is never shown to other members and never sent to the CACENTRE gate:
 * the gate is told only that today is somebody's birthday, by the points.
 */
declare(strict_types=1);

final class Birthdays
{
    private const SENT_META = 'member_bday_sent';
    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        $pdo = Database::pdo();
        foreach (['birthday' => "VARCHAR(5) NOT NULL DEFAULT ''", 'birth_year' => 'INTEGER NOT NULL DEFAULT 0'] as $col => $decl) {
            if (!Database::columnExists('lms_users', $col)) {
                try { $pdo->exec('ALTER TABLE lms_users ADD COLUMN ' . $col . ' ' . $decl); }
                catch (Throwable $e) { /* raced or already present */ }
            }
        }
        try { $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lms_users_birthday ON lms_users(birthday)'); } catch (Throwable $e) { /* already there */ }
    }

    /** @return array{birthday:string, year:int}|null */
    public static function of(int $userId): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT birthday, birth_year FROM lms_users WHERE id = ?');
        $st->execute([$userId]);
        $r = $st->fetch();
        if (!$r || (string) $r['birthday'] === '') return null;
        return ['birthday' => (string) $r['birthday'], 'year' => (int) $r['birth_year']];
    }

    /**
     * Set or clear a birthday. Takes 'YYYY-MM-DD' (from a date picker) or
     * 'MM-DD' (no year), and an empty string to remove it.
     */
    public static function set(int $userId, string $value, bool $keepYear = true): array
    {
        self::ensure();
        $value = trim($value);
        if ($value === '') {
            Database::pdo()->prepare("UPDATE lms_users SET birthday = '', birth_year = 0 WHERE id = ?")->execute([$userId]);
            return ['ok' => true, 'birthday' => null];
        }
        $year = 0;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) { $year = (int) $m[1]; $md = $m[2] . '-' . $m[3]; }
        elseif (preg_match('/^(\d{2})-(\d{2})$/', $value, $m)) { $md = $m[1] . '-' . $m[2]; }
        else return ['ok' => false, 'error' => 'Give the date, like 1998-07-14 — or 07-14 without the year.'];
        [$mm, $dd] = array_map('intval', explode('-', $md));
        if (!checkdate($mm, $dd, 2024)) return ['ok' => false, 'error' => 'That is not a real date.'];   // 2024: a leap year, so 29 Feb is allowed
        $nowY = (int) gmdate('Y');
        if ($year !== 0 && ($year < $nowY - 110 || $year > $nowY - 5 || !checkdate($mm, $dd, $year))) return ['ok' => false, 'error' => 'Check the year.'];
        if (!$keepYear) $year = 0;
        Database::pdo()->prepare('UPDATE lms_users SET birthday = ?, birth_year = ? WHERE id = ?')->execute([$md, $year, $userId]);
        return ['ok' => true, 'birthday' => $md, 'year' => $year];
    }

    /** Is $day (Y-m-d) this member's birthday? 29 February falls on the 28th in other years. */
    public static function isOn(int $userId, string $day): bool
    {
        $b = self::of($userId);
        return $b !== null && self::matches($b['birthday'], $day);
    }

    public static function matches(string $md, string $day): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2}-\d{2})$/', $day, $m)) return false;
        if ($md === $m[2]) return true;
        $leap = checkdate(2, 29, (int) $m[1]);
        return $md === '02-29' && !$leap && $m[2] === '02-28';
    }

    /** Members whose birthday is $day. @return array<int, array{id:int,name:string,email:string}> */
    public static function on(string $day): array
    {
        self::ensure();
        $mds = [substr($day, 5)];
        if (substr($day, 5) === '02-28' && !checkdate(2, 29, (int) substr($day, 0, 4))) $mds[] = '02-29';
        $in = implode(',', array_fill(0, count($mds), '?'));
        $st = Database::pdo()->prepare("SELECT id, name, email FROM lms_users WHERE status = 'active' AND birthday IN ({$in})");
        $st->execute($mds);
        return $st->fetchAll();
    }

    /** How a birthday reads to its owner: "14 July 1998", or "14 July". */
    public static function label(?array $b): string
    {
        if (!$b) return '';
        [$mm, $dd] = array_map('intval', explode('-', $b['birthday']));
        $s = $dd . ' ' . date('F', mktime(0, 0, 0, $mm, 1, 2024));
        return $b['year'] ? $s . ' ' . $b['year'] : $s;
    }

    /**
     * Email today's birthday members, once each per day, from the same letter
     * the team gets. Somebody also on the Team page is left to that email, so
     * nobody gets two. Idempotent on every cron tick.
     */
    public static function emailToday(?string $day = null): int
    {
        $day = $day ?? (function_exists('av_today_tz') ? av_today_tz() : date('Y-m-d'));
        $people = self::on($day);
        if (!$people) return 0;
        $sent = [];
        try {
            $d = json_decode((string) (Database::metaGet(self::SENT_META) ?? ''), true);
            if (is_array($d) && ($d['date'] ?? '') === $day) $sent = array_map('intval', (array) ($d['ids'] ?? []));
        } catch (Throwable $e) { /* start fresh */ }
        $team = [];
        try { if (function_exists('av_birthdays_on')) foreach (av_birthdays_on(Database::pdo(), substr($day, 5)) as $t) $team[] = strtolower(trim((string) ($t['email'] ?? ''))); }
        catch (Throwable $e) { /* no team table */ }
        $n = 0;
        foreach ($people as $p) {
            $id = (int) $p['id'];
            if (in_array($id, $sent, true)) continue;
            $sent[] = $id;
            if (in_array(strtolower((string) $p['email']), $team, true)) continue;
            if (class_exists('Notify')) Notify::birthday(['name' => (string) $p['name'], 'email' => (string) $p['email']]);
            $n++;
        }
        try { Database::metaSet(self::SENT_META, json_encode(['date' => $day, 'ids' => array_values(array_unique($sent))])); } catch (Throwable $e) { /* best effort */ }
        return $n;
    }
}
