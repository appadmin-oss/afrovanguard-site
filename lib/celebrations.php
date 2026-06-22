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

/** Ensure the admin-managed celebrations table exists (idempotent). */
function av_celebrations_ensure(PDO $pdo): void
{
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
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($f)));
        $pdo->prepare("UPDATE celebrations SET $set WHERE id = :id")->execute($f + ['id' => $id]);
        return $id;
    }
    $cols = implode(', ', array_keys($f));
    $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($f)));
    $pdo->prepare("INSERT INTO celebrations ($cols) VALUES ($ph)")->execute($f);
    return (int) $pdo->lastInsertId();
}

function av_celebrations_delete(PDO $pdo, int $id): void
{
    av_celebrations_ensure($pdo);
    $pdo->prepare('DELETE FROM celebrations WHERE id = ?')->execute([$id]);
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
            'doodle' => $ov['doodle_url'] ?? '',
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
    // Birthdays take visual priority, else the first holiday.
    usort($items, fn($a, $b) => ($b['type'] === 'birthday' ? 1 : 0) - ($a['type'] === 'birthday' ? 1 : 0));
    return ['date' => $date, 'items' => $items, 'primary' => $items[0]];
}
