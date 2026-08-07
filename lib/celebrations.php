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
function av_celebration_calendar(): array
{
    return [
        ['newyear',      'Happy New Year',                 '01-01', 'international', '🎉', '#f3b416', 'A new year of building a force for good. Happy New Year!'],
        ['womensday',    "International Women's Day",       '03-08', 'international', '💜', '#7c3aed', 'Celebrating the women shaping a better Africa.'],
        ['happiness',    'International Day of Happiness',  '03-20', 'international', '😊', '#f59e0b', 'Choosing joy and service today.'],
        ['earthday',     'Earth Day',                       '04-22', 'international', '🌍', '#16a34a', 'Stewards of the earth — happy Earth Day.'],
        ['workersday',   "Workers' Day",                    '05-01', 'international', '🛠️', '#0ea5e9', 'Honouring the dignity of work.'],
        ['africaday',    'Africa Day',                      '05-25', 'african',       '🌍', '#16a34a', 'One Africa, one destiny. Happy Africa Day!'],
        ['childrensday', "Children's Day",                  '05-27', 'african',       '🧒', '#f59e0b', 'For every child we serve — Happy Children’s Day.'],
        ['democracyday', 'Democracy Day (Nigeria)',         '06-12', 'african',       '🇳🇬', '#16a34a', 'Celebrating accountable, people-centred governance.'],
        ['youthday',     'International Youth Day',          '08-12', 'international', '🚀', '#f3b416', 'To the young leaders rising — this day is yours.'],
        ['peaceday',     'International Day of Peace',       '09-21', 'international', '🕊️', '#0ea5e9', 'Peacebuilders and bridge-builders, today is ours.'],
        ['independence', 'Nigeria Independence Day',         '10-01', 'african',       '🇳🇬', '#16a34a', 'Happy Independence Day, Nigeria.'],
        ['girlchild',    'International Day of the Girl',    '10-11', 'international', '🌸', '#ec4899', 'Investing in every girl’s potential.'],
        ['humanrights',  'Human Rights Day',                '12-10', 'international', '⚖️', '#0ea5e9', 'Human dignity and justice for all.'],
        ['christmas',    'Merry Christmas',                 '12-25', 'international', '🎄', '#16a34a', 'Peace and goodwill to all. Merry Christmas!'],
        ['founding',     'Afrovanguard Anniversary',        '07-01', 'internal',      '🎂', '#f3b416', 'Another year of raising incorruptible leaders. Happy anniversary, Afrovanguard!'],
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
    $out[] = [(clone $e)->modify('-2 days')->format('Y-m-d'), 'goodfriday', 'Good Friday', 'international', '✝️', '#6b7280', 'A reflective Good Friday.'];
    $out[] = [$easter, 'easter', 'Happy Easter', 'international', '🐣', '#16a34a', 'He is risen — Happy Easter!'];
    $out[] = [(clone $e)->modify('+1 day')->format('Y-m-d'), 'eastermonday', 'Easter Monday', 'international', '🌿', '#16a34a', 'Happy Easter Monday.'];
    $hy = (int) round(($year - 622) * 33 / 32);
    foreach ([$hy - 1, $hy, $hy + 1] as $h) {
        $fitr = av_jd_to_greg(av_islamic_to_jd($h, 10, 1));   // 1 Shawwal
        $adha = av_jd_to_greg(av_islamic_to_jd($h, 12, 10));  // 10 Dhu al-Hijjah
        if (substr($fitr, 0, 4) === (string) $year) $out[] = [$fitr, 'eidfitr', 'Eid Mubarak', 'international', '🌙', '#16a34a', 'Eid al-Fitr Mubarak to our Muslim community.'];
        if (substr($adha, 0, 4) === (string) $year) $out[] = [$adha, 'eidadha', 'Eid al-Adha Mubarak', 'international', '🐑', '#16a34a', 'Eid al-Adha Mubarak — a blessed celebration of sacrifice.'];
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
    foreach (av_celebration_calendar() as [$key, $name, $cmd, $scope, $emoji, $theme, $message]) {
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
                'title' => count($names) === 1 ? ('Happy Birthday, ' . $names[0] . '!') : 'Happy Birthday to our team!',
                'message' => count($names) === 1
                    ? ('Wishing ' . $names[0] . ' a wonderful birthday from the whole movement.')
                    : ('Celebrating ' . implode(', ', $names) . ' today!'),
                'emoji' => '🎂', 'theme' => '#f3b416', 'doodle' => '',
                'people' => array_map(fn($m) => ['name' => $m['name'], 'role' => $m['role'], 'photo' => $m['photo'], 'id' => $m['id']], $bdays),
            ];
        }
    }

    if (!$items) return null;
    // Rank so birthdays and major/religious days outrank generic observances.
    $weight = function (array $it): int {
        if ($it['type'] === 'birthday') return 100;
        $major = ['eidfitr', 'eidadha', 'easter', 'christmas', 'newyear', 'founding'];
        $notable = ['goodfriday', 'eastermonday', 'africaday', 'independence', 'democracyday', 'childrensday', 'youthday'];
        if (in_array($it['key'], $major, true)) return 80;
        if (in_array($it['key'], $notable, true)) return 60;
        if (($it['scope'] ?? '') === 'african') return 50;
        if (($it['scope'] ?? '') === 'internal') return 45;
        return 30;
    };
    usort($items, fn($a, $b) => $weight($b) <=> $weight($a));
    return ['date' => $date, 'items' => $items, 'primary' => $items[0]];
}
