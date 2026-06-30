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
            if (function_exists('env')) { // db() is always safe: MySQL when set, else local SQLite
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
/**
 * The four programmes — one source for the home grid and the programs hub.
 * `count` is pulled from live stats where it maps to a tracked figure.
 */
function sts_programs(): array {
    return [
        [
            'slug' => 'next-gen', 'name' => 'Next Generation Genius Club',
            'img' => 'https://afrovanguard.org.ng/Images/bootcamp1.png',
            'metric' => '248', 'metric_label' => 'active members', 'pill' => 'year-long',
            'desc' => 'Weekend Nation Builders Labs for ages 10–17, combining leadership, technology, creativity, entrepreneurship, and civic responsibility. Delivered through a structured year-long curriculum that culminates in a public showcase of projects, innovations, and community impact.',
        ],
        [
            'slug' => 'summer-school', 'name' => 'Alimosho Summer School',
            'img' => 'https://afrovanguard.org.ng/Images/summer6.jpg',
            'metric' => '412', 'metric_label' => 'summer 2024 enrolment', 'pill' => 'annual',
            'desc' => 'A holiday transformation experience for children and teenagers, turning free time into a season of discovery, discipline, and growth. Participants engage in hands-on learning, leadership development, creative exploration, and practical life lessons designed to prepare them for the next stage of their journey.',
        ],
        [
            'slug' => 'lcasp', 'name' => 'Lagos Community Advancement School Project',
            'img' => 'https://afrovanguard.org.ng/Images/storm1.png',
            'metric' => (string) sts_stat('schools_engaged', 23), 'metric_label' => 'partner schools', 'pill' => 'flagship',
            'desc' => 'Our flagship program designed to equip children and teenagers with the knowledge, character, and leadership skills needed to thrive and make a positive impact in society.',
        ],
        [
            'slug' => 'street-storm', 'name' => 'STREET Storm',
            'img' => 'https://afrovanguard.org.ng/Images/storm3.png',
            'metric' => '94', 'metric_label' => 'mentors active', 'pill' => 'youth',
            'desc' => 'Street Storm is a grassroots engagement campaign that takes inspiration, opportunity, and transformation directly to children and families in their communities. Through school visits, neighborhood activations, mentorship, performances, and awareness drives, the initiative identifies hidden potential and connects young people to pathways for growth and success.',
        ],
    ];
}

/** Curated field-note fallbacks (mirrors the /blog pages). */
function sts_posts_default(): array {
    return [
        ['slug'=>'summer-school-two-new-lgas','cat'=>'Education','date'=>'May 2026','img'=>'https://afrovanguard.org.ng/Images/summer6.jpg','title'=>'What we learned running Summer School across two new LGAs','excerpt'=>"Scaling Alimosho's six-week intensive into Kosofe and Ikeja taught us more about logistics than pedagogy. A field note."],
        ['slug'=>'publishing-what-didnt-work','cat'=>'Methodology','date'=>'Apr 2026','img'=>'https://afrovanguard.org.ng/Images/summer3.png','title'=>"Why we publish the studies that don't work the way we hoped",'excerpt'=>"An honest accounting of our 2024 numeracy pilot, the parts that worked, and the parts we've already retired."],
        ['slug'=>'parents-saturday-morning','cat'=>'Community','date'=>'Mar 2026','img'=>'https://afrovanguard.org.ng/Images/summer4.png','title'=>'Notes from the parents we meet every Saturday morning','excerpt'=>'Six months of NextGen Genius Club from the family-engagement desk. Recurring themes, surprises, and one quiet ask.'],
        ['slug'=>'street-storm-where-they-end-up','cat'=>'Youth Development','date'=>'Feb 2026','img'=>'https://afrovanguard.org.ng/Images/storm3.png','title'=>'Where STREET Storm participants actually end up','excerpt'=>'Following 87 youth six months after the program ended. The honest version of the apprenticeship pipeline.'],
        ['slug'=>'lcasp-baseline-endline','cat'=>'Methodology','date'=>'Jan 2026','img'=>'https://afrovanguard.org.ng/Images/gates1.png','title'=>'Inside the LCASP baseline-endline cycle','excerpt'=>'The exact protocol we use to measure literacy gains across 23 partner schools. Open to peer review.'],
        ['slug'=>'hiring-director-of-research','cat'=>'Leadership','date'=>'Dec 2025','img'=>'https://afrovanguard.org.ng/Images/summer6.jpg','title'=>'Hiring our first Director of Research, three years late','excerpt'=>"We waited too long. Here's what we got wrong about prioritising program delivery over measurement capacity."],
        ['slug'=>'cohort-tracking-google-sheets','cat'=>'Technology','date'=>'Nov 2025','img'=>'https://afrovanguard.org.ng/Images/bootcamp1.png','title'=>'Why we rebuilt our cohort-tracking on Google Sheets instead of buying software','excerpt'=>'An honest cost-benefit. Spoiler: the answer might be different at 2,000 children, but not at 412.'],
        ['slug'=>'girl-attendance-gap-lcasp','cat'=>'Gender','date'=>'Oct 2025','img'=>'https://afrovanguard.org.ng/Images/summer7.png','title'=>'Girl-attendance gap in LCASP cohorts: what we found, what we changed','excerpt'=>'A 6.4 percentage-point gap at P5 disappeared at P6 once we changed one logistical thing. A note for replicators.'],
    ];
}

/**
 * Field notes — published posts from the DB when available, otherwise the
 * curated list above. Pass a limit for the home page's "latest" strip.
 */
function sts_posts(int $limit = 0): array {
    static $posts = null;
    if ($posts === null) {
        $posts = sts_posts_default();
        $cfg = __DIR__ . '/../api/config.php';
        $dbf = __DIR__ . '/../api/db.php';
        if (is_file($cfg) && is_file($dbf)) {
            try {
                require_once $cfg;
                if (function_exists('env')) { // db() is always safe: MySQL when set, else local SQLite
                    require_once $dbf;
                    $pdo = db();
                    $rows = $pdo->query("SELECT slug, title, excerpt, cover_image, category, published_at FROM blog_posts WHERE status='published' ORDER BY published_at DESC")->fetchAll();
                    if ($rows) {
                        $posts = array_map(function ($r) {
                            return [
                                'slug' => $r['slug'],
                                'cat' => $r['category'] ?: 'Field note',
                                'date' => $r['published_at'] ? date('M Y', strtotime($r['published_at'])) : '',
                                'img' => $r['cover_image'] ?: 'https://afrovanguard.org.ng/Images/summer1.png',
                                'title' => $r['title'],
                                'excerpt' => (string) $r['excerpt'],
                            ];
                        }, $rows);
                    }
                }
            } catch (\Throwable $e) { /* keep curated fallback */ }
        }
    }
    return $limit > 0 ? array_slice($posts, 0, $limit) : $posts;
}

/**
 * Next open intake for a programme. Live from the program_sessions table
 * when the DB is connected ("12 Jul 2026 · Alimosho · 6 seats left"),
 * otherwise the curated per-programme fallback below.
 */
function sts_next_session(string $slug): string {
    $fallback = [
        'next-gen'      => 'August 2026 · enrolment opens 4 July',
        'summer-school' => 'July 2026 · applications open in May',
        'lcasp'         => 'Continuous · partner schools onboard new cohorts every term',
        'street-storm'  => 'October 2026 · referrals open in September',
    ];
    $cfg = __DIR__ . '/../api/config.php';
    $dbf = __DIR__ . '/../api/db.php';
    if (is_file($cfg) && is_file($dbf)) {
        try {
            require_once $cfg;
            if (function_exists('env')) { // db() is always safe: MySQL when set, else local SQLite
                require_once $dbf;
                $st = db()->prepare(
                    "SELECT session_date, location, capacity, volunteers_registered
                       FROM program_sessions
                      WHERE program_slug = ? AND status = 'open' AND session_date >= ?
                      ORDER BY session_date ASC LIMIT 1"
                );
                $st->execute([$slug, date('Y-m-d H:i:s')]);
                if ($r = $st->fetch()) {
                    $when  = date('j M Y', strtotime($r['session_date']));
                    $left  = max(0, (int) $r['capacity'] - (int) $r['volunteers_registered']);
                    $where = $r['location'] ? ' · ' . $r['location'] : '';
                    $seats = $left > 0 ? " · {$left} of {$r['capacity']} seats left" : ' · waitlist only';
                    return $when . $where . $seats;
                }
            }
        } catch (\Throwable $e) { /* fall through to curated */ }
    }
    return $fallback[$slug] ?? 'Dates announced each term';
}

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
