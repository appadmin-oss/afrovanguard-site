<?php
/**
 * inc/data.php — the single source of truth for STS site figures.
 *
 * Pages read figures from here instead of hardcoding them, so a number is
 * defined ONCE and can never drift between the home page, the impact page
 * and the get-involved tiles.
 *
 * It is also *live*: when the backend database is configured it overlays
 * the authoritative values from the same `site_stats` table that
 * /api/stats.php serves to the browser — so the server-rendered numbers
 * match the client and stay current without a redeploy. With no database
 * (or a misconfigured one) it falls back silently to the curated defaults
 * below, and never blocks or slows a page render.
 */
declare(strict_types=1);

if (!function_exists('sts_defaults')) {

function sts_defaults(): array {
    return [
        'org' => [
            'founded'   => 2021,
            'lgas'      => 4,
            'lga_names' => ['Alimosho', 'Ikeja', 'Kosofe', 'Agege'],
            'reg'       => 'IT-186151',
            'parent'    => 'Afrovanguard',
        ],
        // Keys mirror the site_stats table seeded in migrations/002_seed.sql.
        'stats' => [
            'children_reached'         => 31072,
            'schools_engaged'          => 23,
            'sessions_delivered'       => 156,
            'literacy_improvement'     => 89,
            'active_volunteers'        => 234,
            'institutional_partners'   => 12,
            'children_sponsored'       => 67,
            'raised_2025_ngn_millions' => 18,
        ],
    ];
}

/** Resolved stats: curated defaults, overlaid with live DB values when present. */
function sts_stats(): array {
    static $stats = null;
    if ($stats !== null) return $stats;
    $stats = sts_defaults()['stats'];

    $cfg = __DIR__ . '/../api/config.php';
    $dbf = __DIR__ . '/../api/db.php';
    if (is_file($cfg) && is_file($dbf)) {
        try {
            require_once $cfg;
            // Only touch the DB when it is actually configured — avoids a
            // connection hang on hosts where the marketing site ships
            // without a database.
            if (function_exists('env') && (string) env('DB_NAME', '') !== '') {
                require_once $dbf;
                $pdo = db();
                foreach ($pdo->query('SELECT stat_key, stat_value FROM site_stats') as $row) {
                    $stats[$row['stat_key']] = (int) $row['stat_value'];
                }
            }
        } catch (\Throwable $e) {
            // Keep the curated defaults; the page must always render.
        }
    }
    return $stats;
}

function sts_stat(string $key, int $fallback = 0): int {
    $s = sts_stats();
    return isset($s[$key]) ? (int) $s[$key] : $fallback;
}

/** Thousands-separated string, e.g. 31072 → "31,072". */
function sts_n(int $n): string { return number_format($n); }

/** Compact form, e.g. 31072 → "31k+". */
function sts_k(int $n): string {
    return $n >= 1000 ? (int) floor($n / 1000) . 'k+' : (string) $n;
}

function sts_org(string $key) {
    $o = sts_defaults()['org'];
    return $o[$key] ?? null;
}

/** Whole years of operation, derived from the founding year. */
function sts_years(): int {
    return max(1, ((int) date('Y')) - (int) sts_org('founded'));
}

/**
 * Contextual enrolment status, derived from today's date against the
 * Lagos programme calendar. Keeps the hero pill honest year-round with no
 * manual edits.
 */
function sts_term_label(): string {
    $now = time();
    $y   = (int) date('Y');
    // [start MM-DD, label] in calendar order.
    $phases = [
        ['01-01', '2026 planning · partnerships open'],
        ['04-01', 'School Storm term in session'],
        ['07-15', 'Summer School enrolment open'],
        ['08-30', 'Bootcamp & NextGen in session'],
        ['09-10', 'NextGen Genius enrolment open'],
        ['12-01', 'Next-year planning · partnerships open'],
    ];
    $label = $phases[0][1];
    foreach ($phases as $p) {
        if ($now >= strtotime("$y-{$p[0]} 00:00:00")) $label = $p[1];
    }
    return $label;
}

}
