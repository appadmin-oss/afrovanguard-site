<?php
/**
 * lib/celebrations.php — the automation behind the site's self-celebrating
 * behaviour. Computes, for any given day, which holidays / birthdays are
 * being celebrated, so the front-end can show a festive banner, a Google-style
 * logo doodle and confetti — with zero manual work.
 *
 *  - A curated built-in calendar of African, international and internal dates.
 *  - Team birthdays (from the people directory).
 *  - Admin-managed celebrations (custom dates + uploaded doodle art + the
 *    ability to disable a built-in), stored in the `celebrations` table.
 *
 * Served by api.php?action=celebrations and rendered by assets/site/celebrations.js.
 */
declare(strict_types=1);

/**
 * Built-in calendar. Each entry:
 *   key, name, md (MM-DD), scope (african|international|internal),
 *   emoji, theme (accent hex), message.
 */
function av_celebration_calendar(?int $year = null): array
{
    $year = $year ?: (int) date('Y');
    // Copy is the design’s (Afrovanguard Celebrations · 5a). Admin overrides win.
    $ng = ucfirst(av_celebration_words(max(0, $year - 1960)));
    $av = av_celebration_words(max(0, $year - AV_FOUNDED_YEAR));
    return [
        ['newyear',      'Happy New Year',                  '01-01', 'international', '🎉', '#f3b416', 'A new year of building a force for good. Thank you for showing up in the last one.'],
        ['womensday',    'Happy International Women’s Day', '03-08', 'international', '💜', '#7c3aed', 'Celebrating the women of Afrovanguard: the mentors, leaders and members shaping a better Africa.'],
        ['happiness',    'Happy Day of Happiness',          '03-20', 'international', '😊', '#f59e0b', 'Small acts of service add up. Thank you for being one of ours.'],
        ['earthday',     'Happy Earth Day',                 '04-22', 'international', '🌍', '#16a34a', 'Plant something, pick something up, or join a clean-up in Alimosho.'],
        ['workersday',   'Happy Workers’ Day',              '05-01', 'international', '🛠️', '#0ea5e9', 'To everyone who builds, teaches and serves: we see your work. Rest well today.'],
        ['africaday',    'Happy Africa Day',                '05-25', 'african',       '🌍', '#16a34a', 'One Africa, one destiny. Today we celebrate the continent we are working for.'],
        ['childrensday', 'Happy Children’s Day',            '05-27', 'african',       '🧒', '#f59e0b', 'For the children we mentor, and the child in every one of us. Thank you for showing up for them.'],
        ['democracyday', 'Happy Democracy Day',             '06-12', 'african',       '🇳🇬', '#16a34a', 'Democracy is a daily practice. Thank you for leading with integrity.'],
        ['youthday',     'Happy International Youth Day',   '08-12', 'international', '🚀', '#f3b416', 'Young people aren’t only the future. You’re already leading. Here’s to you.'],
        ['peaceday',     'International Day of Peace',      '09-21', 'international', '🕊️', '#0ea5e9', 'Peace starts in communities like ours. Thank you for building it.'],
        ['independence', 'Happy Independence Day',          '10-01', 'african',       '🇳🇬', '#16a34a', $ng . ' years of Nigeria, and ' . $av . ' of Afrovanguard marching with the young people of Alimosho.'],
        ['girlchild',    'International Day of the Girl',   '10-11', 'international', '🌸', '#ec4899', 'Every girl deserves a mentor and a fair chance. Thank you for being one.'],
        ['humanrights',  'Human Rights Day',                '12-10', 'international', '⚖️', '#0ea5e9', 'Dignity, equality and justice, for everyone, every day.'],
        ['christmas',    'Merry Christmas',                 '12-25', 'international', '🎄', '#16a34a', 'Peace and goodwill to you and your family this Christmas.'],
        ['founding',     ucfirst($av) . ' years of Afrovanguard', '07-01', 'internal', '🎂', '#f3b416', ucfirst($av) . ' years of raising incorruptible leaders. Thank you for being part of the story.'],
    ];
}

/** Built-in doodle art path for a holiday key (some keys share art). */
function av_builtin_doodle(string $key): string
{
    static $map = [
        'newyear' => 'newyear', 'eidfitr' => 'eid', 'eidadha' => 'eid', 'easter' => 'easter',
        'christmas' => 'christmas', 'africaday' => 'africaday', 'independence' => 'independence',
        'democracyday' => 'independence', 'founding' => 'founding', 'womensday' => 'womensday',
        'childrensday' => 'childrensday', 'youthday' => 'youthday',
    ];
    $file = $map[$key] ?? '';
    if ($file === '') return '';
    return is_file(AV_ROOT . '/assets/doodles/' . $file . '.svg') ? '/assets/doodles/' . $file . '.svg' : '';
}

/** Ensure the admin-managed celebrations table exists (idempotent, driver-aware).
 *  `key` is a reserved word in MySQL (back-quoted there); SQLite/Postgres take it
 *  bare. The SQLite branch is byte-identical to the original DDL. */
function av_celebrations_ensure(PDO $pdo): void
{
    static $done = false; if ($done) return; $done = true;
    switch ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
        case 'mysql':
            $pdo->exec("CREATE TABLE IF NOT EXISTS celebrations (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                `key` VARCHAR(191) NOT NULL DEFAULT '',
                name VARCHAR(191) NOT NULL DEFAULT '',
                md VARCHAR(5) NOT NULL DEFAULT '',
                scope VARCHAR(20) NOT NULL DEFAULT 'internal',
                emoji VARCHAR(16) NOT NULL DEFAULT '🎉',
                theme VARCHAR(9) NOT NULL DEFAULT '#f3b416',
                message VARCHAR(500) NOT NULL DEFAULT '',
                doodle_url VARCHAR(255) NOT NULL DEFAULT '',
                enabled INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            break;
        case 'pgsql':
            $pdo->exec("CREATE TABLE IF NOT EXISTS celebrations (
                id SERIAL PRIMARY KEY,
                key VARCHAR(191) NOT NULL DEFAULT '',
                name VARCHAR(191) NOT NULL DEFAULT '',
                md VARCHAR(5) NOT NULL DEFAULT '',
                scope VARCHAR(20) NOT NULL DEFAULT 'internal',
                emoji VARCHAR(16) NOT NULL DEFAULT '🎉',
                theme VARCHAR(9) NOT NULL DEFAULT '#f3b416',
                message TEXT NOT NULL DEFAULT '',
                doodle_url VARCHAR(255) NOT NULL DEFAULT '',
                enabled INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            break;
        default: // sqlite — byte-identical to the original DDL
            $pdo->exec("CREATE TABLE IF NOT EXISTS celebrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key TEXT NOT NULL DEFAULT '',
                name TEXT NOT NULL DEFAULT '',
                md TEXT NOT NULL DEFAULT '',
                scope TEXT NOT NULL DEFAULT 'internal',
                emoji TEXT NOT NULL DEFAULT '🎉',
                theme TEXT NOT NULL DEFAULT '#f3b416',
                message TEXT NOT NULL DEFAULT '',
                doodle_url TEXT NOT NULL DEFAULT '',
                enabled INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )");
    }
}

/** All admin rows (for the Studio). */
function av_celebrations_all(PDO $pdo): array
{
    av_celebrations_ensure($pdo);
    return $pdo->query('SELECT * FROM celebrations ORDER BY md ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function av_celebrations_save(PDO $pdo, array $in): int
{
    av_celebrations_ensure($pdo);
    $f = [
        'key' => trim((string) ($in['key'] ?? '')),
        'name' => trim((string) ($in['name'] ?? '')),
        'md' => preg_match('/^\d{2}-\d{2}$/', (string) ($in['md'] ?? '')) ? $in['md'] : '',
        'scope' => in_array(($in['scope'] ?? ''), ['african', 'international', 'internal'], true) ? $in['scope'] : 'internal',
        'emoji' => trim((string) ($in['emoji'] ?? '🎉')) ?: '🎉',
        'theme' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($in['theme'] ?? '')) ? $in['theme'] : '#f3b416',
        'message' => trim((string) ($in['message'] ?? '')),
        'doodle_url' => trim((string) ($in['doodle_url'] ?? '')),
        'enabled' => isset($in['enabled']) ? (!empty($in['enabled']) ? 1 : 0) : 1,
    ];
    $id = (int) ($in['id'] ?? 0);
    if ($id > 0) {
        // Quote each column ( `key` is reserved on MySQL ); placeholders stay bare.
        $set = implode(', ', array_map(fn($k) => Database::quoteIdent($k) . " = :$k", array_keys($f)));
        $pdo->prepare("UPDATE celebrations SET $set WHERE id = :id")->execute($f + ['id' => $id]);
        if (class_exists('Events')) Events::emit('celebration.updated', ['id' => $id]);
        return $id;
    }
    $cols = implode(', ', array_map(fn($k) => Database::quoteIdent($k), array_keys($f)));
    $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($f)));
    $pdo->prepare("INSERT INTO celebrations ($cols) VALUES ($ph)")->execute($f);
    $newId = (int) $pdo->lastInsertId();
    if (class_exists('Events')) Events::emit('celebration.updated', ['id' => $newId]);
    return $newId;
}

function av_celebrations_delete(PDO $pdo, int $id): void
{
    av_celebrations_ensure($pdo);
    $pdo->prepare('DELETE FROM celebrations WHERE id = ?')->execute([$id]);
    if (class_exists('Events')) Events::emit('celebration.updated', ['id' => $id, 'deleted' => true]);
}

/* ── Movable feasts (computed per year) ─────────────────────────────
   Easter (Computus) drives Good Friday / Easter / Easter Monday. Eid dates
   use the tabular (civil) Islamic calendar — accurate to within a day or
   two of the announced sighting; admins can fine-tune via the Studio. */

/** Gregorian Easter Sunday for a year (Anonymous Gregorian algorithm). */
function av_easter_date(int $y): string
{
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $y, $month, $day);
}

/** Julian Day Number → 'YYYY-MM-DD' (Gregorian). */
function av_jd_to_greg(int $jd): string
{
    $a = $jd + 32044; $b = intdiv(4 * $a + 3, 146097); $c = $a - intdiv(146097 * $b, 4);
    $d = intdiv(4 * $c + 3, 1461); $e = $c - intdiv(1461 * $d, 4); $m = intdiv(5 * $e + 2, 153);
    $day = $e - intdiv(153 * $m + 2, 5) + 1; $month = $m + 3 - 12 * intdiv($m, 10);
    $year = 100 * $b + $d - 4800 + intdiv($m, 10);
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/** Tabular (civil) Islamic date → Julian Day Number. */
function av_islamic_to_jd(int $iy, int $im, int $id): int
{
    return (int) (intdiv(11 * $iy + 3, 30) + 354 * $iy + 30 * $im - intdiv($im - 1, 2) + $id + 1948440 - 385);
}

/** Movable holidays falling in the given Gregorian year. */
function av_movable_holidays(int $year): array
{
    $out = [];
    $easter = av_easter_date($year);
    $e = new DateTime($easter);
    $out[] = [(clone $e)->modify('-2 days')->format('Y-m-d'), 'goodfriday', 'A peaceful Good Friday', 'international', '✝️', '#6b7280', 'Wishing our Christian members a quiet and reflective Good Friday.'];
    $out[] = [$easter, 'easter', 'Happy Easter', 'international', '🐣', '#16a34a', 'He is risen. Wishing you and your family a joyful Easter.'];
    $out[] = [(clone $e)->modify('+1 day')->format('Y-m-d'), 'eastermonday', 'Happy Easter Monday', 'international', '🌿', '#16a34a', 'Rest, family and a little more Easter. Enjoy the holiday.'];
    $hy = (int) round(($year - 622) * 33 / 32);
    foreach ([$hy - 1, $hy, $hy + 1] as $h) {
        $fitr = av_jd_to_greg(av_islamic_to_jd($h, 10, 1));   // 1 Shawwal
        $adha = av_jd_to_greg(av_islamic_to_jd($h, 12, 10));  // 10 Dhu al-Hijjah
        if (substr($fitr, 0, 4) === (string) $year) $out[] = [$fitr, 'eidfitr', 'Eid Mubarak', 'international', '🌙', '#16a34a', 'Eid al-Fitr Mubarak to our Muslim members, mentors and families. May it be a blessed celebration.'];
        if (substr($adha, 0, 4) === (string) $year) $out[] = [$adha, 'eidadha', 'Barka da Sallah', 'international', '🐑', '#16a34a', 'Eid al-Adha Mubarak to our Muslim members and families. May your sacrifice be accepted.'];
    }
    return $out;
}

/**
 * The celebration payload for a given date (default: today).
 * Returns null when there is nothing to celebrate.
 */
function av_celebration_today(PDO $pdo, ?string $date = null): ?array
{
    $date = $date ?: date('Y-m-d');
    $md = substr($date, 5); // MM-DD

    av_celebrations_ensure($pdo);
    $rows = $pdo->query('SELECT * FROM celebrations')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $override = [];   // key => row (admin override/disable of a built-in)
    $customs = [];    // admin custom celebrations for today
    foreach ($rows as $r) {
        if ($r['key'] !== '') $override[$r['key']] = $r;
        if ($r['md'] === $md && (int) $r['enabled'] === 1) $customs[] = $r;
    }

    $items = [];
    foreach (av_celebration_calendar((int) substr($date, 0, 4)) as [$key, $name, $cmd, $scope, $emoji, $theme, $message]) {
        if ($cmd !== $md) continue;
        $ov = $override[$key] ?? null;
        if ($ov && (int) $ov['enabled'] === 0) continue; // admin disabled this built-in
        $items[] = [
            'type' => 'holiday', 'key' => $key, 'scope' => $scope,
            'title' => $ov['name'] ?? $name,
            'message' => ($ov && $ov['message'] !== '') ? $ov['message'] : $message,
            'emoji' => ($ov && $ov['emoji'] !== '') ? $ov['emoji'] : $emoji,
            'theme' => ($ov && $ov['theme'] !== '') ? $ov['theme'] : $theme,
            'doodle' => ($ov && $ov['doodle_url'] !== '') ? $ov['doodle_url'] : av_builtin_doodle($key),
        ];
    }
    // Movable feasts computed for this year (Easter family + Eid), matched by full date.
    foreach (av_movable_holidays((int) substr($date, 0, 4)) as [$fdate, $key, $name, $scope, $emoji, $theme, $message]) {
        if ($fdate !== $date) continue;
        $ov = $override[$key] ?? null;
        if ($ov && (int) $ov['enabled'] === 0) continue;
        $items[] = [
            'type' => 'holiday', 'key' => $key, 'scope' => $scope,
            'title' => $ov['name'] ?? $name,
            'message' => ($ov && $ov['message'] !== '') ? $ov['message'] : $message,
            'emoji' => ($ov && $ov['emoji'] !== '') ? $ov['emoji'] : $emoji,
            'theme' => ($ov && $ov['theme'] !== '') ? $ov['theme'] : $theme,
            'doodle' => ($ov && $ov['doodle_url'] !== '') ? $ov['doodle_url'] : av_builtin_doodle($key),
        ];
    }
    foreach ($customs as $r) {
        if ($r['key'] !== '' && isset($override[$r['key']])) continue; // already handled as override
        $items[] = [
            'type' => 'holiday', 'key' => $r['key'] ?: ('custom-' . $r['id']), 'scope' => $r['scope'],
            'title' => $r['name'], 'message' => $r['message'], 'emoji' => $r['emoji'],
            'theme' => $r['theme'], 'doodle' => $r['doodle_url'],
        ];
    }

    // Birthdays (from the people directory)
    if (function_exists('av_birthdays_on') || is_file(AV_ROOT . '/lib/people.php')) {
        require_once AV_ROOT . '/lib/people.php';
        $bdays = av_birthdays_on($pdo, $md);
        if ($bdays) {
            $names = array_map(fn($m) => $m['name'], $bdays);
            $items[] = [
                'type' => 'birthday', 'key' => 'birthday', 'scope' => 'internal',
                'title' => count($names) === 1
                    ? ('It’s ' . av_celebration_first($names[0]) . '’s birthday')
                    : (ucfirst(av_celebration_words(count($names))) . ' birthdays today'),
                'message' => count($names) === 1
                    ? ($names[0] . ' is celebrating today. A short note from you will make their day.')
                    : (av_celebration_list(array_map('av_celebration_first', $names)) . ' are celebrating. Send one wish to all ' . av_celebration_words(count($names)) . '.'),
                'emoji' => '🎂', 'theme' => '#f3b416', 'doodle' => '',
                'people' => array_map(fn($m) => ['name' => $m['name'], 'role' => $m['role'], 'photo' => $m['photo'], 'id' => $m['id']], $bdays),
            ];
        }
    }

    if (!$items) return null;
    $weight = 'av_celebration_weight';
    usort($items, fn($a, $b) => $weight($b) <=> $weight($a));
    return ['date' => $date, 'items' => $items, 'primary' => $items[0]];
}

/** The year Afrovanguard began (the anniversary counts from it). */
if (!defined('AV_FOUNDED_YEAR')) define('AV_FOUNDED_YEAR', 2017);

/**
 * Which card wins when several fall on one day. Design (5a): “your birthday,
 * then a major holiday, then teammates’ birthdays, then other observances.”
 */
function av_celebration_weight(array $it): int
{
    if ($it['type'] === 'birthday') return !empty($it['mine']) ? 200 : 55;
    $major = ['eidfitr', 'eidadha', 'easter', 'christmas', 'newyear', 'founding'];
    $notable = ['goodfriday', 'eastermonday', 'africaday', 'independence', 'democracyday', 'childrensday', 'youthday'];
    if (in_array($it['key'], $major, true)) return 80;
    if (in_array($it['key'], $notable, true)) return 60;
    if (($it['scope'] ?? '') === 'african') return 50;
    if (($it['scope'] ?? '') === 'internal') return 45;
    return 30;
}

/** 0–99 in words (“sixty-six”), for copy that counts years and people. */
function av_celebration_words(int $n): string
{
    $u = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
          'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $t = [2 => 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    if ($n < 0 || $n > 99) return (string) $n;
    if ($n < 20) return $u[$n];
    return $t[intdiv($n, 10)] . ($n % 10 ? '-' . $u[$n % 10] : '');
}

/** 1st, 2nd, 3rd, 11th, 22nd … */
function av_celebration_ordinal(int $n): string
{
    $s = 'th';
    if (!in_array($n % 100, [11, 12, 13], true)) $s = [1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th';
    return $n . $s;
}

function av_celebration_first(string $name): string
{
    $p = preg_split('/\s+/', trim($name)) ?: [];
    return $p[0] ?? '';
}

/** “A”, “A and B”, “A, B and C”. */
function av_celebration_list(array $names): string
{
    $names = array_values(array_filter($names, fn($n) => $n !== ''));
    if (count($names) < 2) return $names[0] ?? '';
    $last = array_pop($names);
    return implode(', ', $names) . ' and ' . $last;
}

/**
 * Add what only the signed-in member should see: their own birthday (5a
 * “your birthday”), ranked first. $viewer is ['name', 'birthday' => 'MM-DD',
 * 'year' => int] or null for a visitor. Pure, so it is easy to test.
 * A member who is also on the Team page is not wished twice.
 */
function av_celebration_for_viewer(?array $cel, ?array $viewer, string $date, int $points = 0): ?array
{
    if (!$viewer) return $cel;
    $name = trim((string) ($viewer['name'] ?? ''));
    $md = (string) ($viewer['birthday'] ?? '');
    $mine = $md !== '' && (class_exists('Birthdays') ? Birthdays::matches($md, $date) : substr($date, 5) === $md);
    if (!$mine) {
        if ($cel) $cel['viewer'] = ['signed_in' => true];
        return $cel;
    }
    $year = (int) ($viewer['year'] ?? 0);
    $age = $year > 1900 ? ((int) substr($date, 0, 4) - $year) : 0;
    $first = av_celebration_first($name) ?: 'friend';
    $parts = preg_split('/\s+/', $name) ?: [];
    $ini = strtoupper(mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : ''));
    $item = [
        'type' => 'birthday', 'key' => 'birthday', 'scope' => 'internal', 'mine' => true,
        'title' => 'Happy ' . ($age > 0 ? av_celebration_ordinal($age) . ' ' : '') . 'birthday, ' . $first,
        'message' => 'From everyone at Afrovanguard, thank you for the way you serve. Have a wonderful day.',
        'emoji' => '🎂', 'theme' => '#f3b416', 'doodle' => '',
        'person' => ['name' => $name, 'first' => $first, 'initials' => $ini, 'age' => $age > 0 ? $age : null],
        'points' => max(0, $points),
    ];
    $items = [];
    foreach (($cel['items'] ?? []) as $it) {
        if ($it['type'] === 'birthday' && !empty($it['people'])) {
            $it['people'] = array_values(array_filter($it['people'], fn($p) => strcasecmp(trim((string) $p['name']), $name) !== 0));
            if (!$it['people']) continue;
        }
        $items[] = $it;
    }
    array_unshift($items, $item);
    usort($items, fn($a, $b) => av_celebration_weight($b) <=> av_celebration_weight($a));
    return ['date' => $date, 'items' => $items, 'primary' => $items[0], 'viewer' => ['signed_in' => true]];
}

/**
 * The quiet member-page theme (2b): the holiday nearest $date, from the day
 * before through the day after. Birthdays never theme a page.
 * @return array{key:string,title:string,message:string,theme:string,doodle:string,date:string,today:bool}|null
 */
function av_celebration_theme(PDO $pdo, string $date): ?array
{
    $t = strtotime($date . ' 12:00:00');
    foreach ([0, -1, 1] as $off) {
        $d = date('Y-m-d', $t + $off * 86400);
        $c = av_celebration_today($pdo, $d);
        foreach (($c['items'] ?? []) as $it) {
            if ($it['type'] !== 'holiday') continue;
            return ['key' => $it['key'], 'title' => $it['title'], 'message' => $it['message'], 'theme' => $it['theme'],
                    'doodle' => $it['doodle'], 'date' => $d, 'today' => $off === 0];
        }
    }
    return null;
}
