<?php
/**
 * lib/avpj.php — read model for the project pages (REPLACEMENT_MAP row 6).
 *
 * Behaviour ported from the projectVals() of design/Afrovanguard Project.dc.html:
 * the facts panel, the three join steps, the call to action, the How to join
 * images and the three "Other projects" all derive from one project entry in
 * lib/projects_content.php. No markup here; projects/_detail.php renders it.
 */
declare(strict_types=1);

const AVPJ_VOLUNTEER_URL = 'https://cacentre.afrovanguard.org.ng/volunteer';

/** Every project, keyed by slug, in the design's card order. */
function avpj_projects(): array
{
    static $all = null;
    if ($all === null) {
        $all = require AV_ROOT . '/lib/projects_content.php';
    }
    return $all;
}

/** The filter categories, in the design's order, with their counts. */
function avpj_filters(array $all): array
{
    $out = ['All' => count($all)];
    foreach (['Arts & Media', 'Leadership', 'Technology', 'Business', 'Culture'] as $c) {
        $out[$c] = count(array_filter($all, static fn (array $p): bool => $p['cat'] === $c));
    }
    return $out;
}

/**
 * What each shared photograph actually shows — one description, used for its
 * alt text wherever it appears (tests/siteaudit.test.php holds the static
 * pages to it). A photo lent to a project is still described as what it is.
 */
const AVPJ_IMG_ALT = [
    '/Images/Afrovanguard Volunteers Get-Together- Lagos ChapterOn December 8th, 2024, the Lagos Chapter of  (1).webp' => 'Afrovanguard volunteers at the Lagos Chapter get-together, December 2024',
    '/Images/Afrovanguard Volunteers Get-Together- Lagos ChapterOn December 8th, 2024, the Lagos Chapter of  (4).webp' => 'Afrovanguard volunteers at the Lagos Chapter get-together, December 2024',
    '/Images/Afrovanguard Volunteers Get-Together- Lagos ChapterOn December 8th, 2024, the Lagos Chapter of  (5).webp' => 'Afrovanguard volunteers at the Lagos Chapter get-together, December 2024',
    '/Images/Afrovanguard Volunteers Get-Together- Lagos ChapterOn December 8th, 2024, the Lagos Chapter of .webp' => 'Afrovanguard volunteers at the Lagos Chapter get-together, December 2024',
    '/Images/alimosho.jpg' => 'An Alimosho community celebration of Nigeria at 60',
    '/Images/bootcamp1.png' => 'Children at an Afrovanguard community programme in Alimosho',
    '/Images/career1.png' => 'Three volunteers in Alimosho T-shirts',
    '/Images/culture1.png' => 'A cultural dance performance with drummers',
    '/Images/gates1.png' => 'A speaker on stage at an Africa GATES event',
    '/Images/gates2.png' => 'Young volunteers in green Alimosho T-shirts celebrating',
    '/Images/storm2.jpg' => 'Students and volunteers at a School Storm outreach in a Lagos secondary school',
    '/Images/storm3.png' => 'A volunteer speaking to pupils at a School Storm outreach',
    '/Images/summer1.png' => 'Two smiling girls in Alimosho T-shirts',
    '/Images/summer4.png' => 'A young person holding a hand-written “Support us” sign',
    '/Images/summer6.jpg' => 'Teenagers holding hand-written signs about character and accountability',
];

function avpj_img_alt(string $src, string $fallback = ''): string
{
    return AVPJ_IMG_ALT[rawurldecode((string) strtok($src, '?'))] ?? $fallback;
}

/** Everything the detail page shows for $slug, or null when it has no page here. */
function avpj_view(array $all, string $slug): ?array
{
    $p = $all[$slug] ?? null;
    if ($p === null || empty($p['local'])) return null;
    $sel = !empty($p['selective']);

    $gallery = $p['gallery'] ?? array_slice(array_values(array_filter(
        ['/Images/storm2.jpg', '/Images/summer6.jpg', '/Images/gates1.png'],
        static fn (string $g): bool => $g !== $p['img'])), 0, 2);

    $others = [];
    foreach ($all as $s => $o) {
        if ($s === $slug) continue;
        $others[$s] = $o;
        if (count($others) === 3) break;
    }

    return [
        'slug'    => $slug,
        'p'       => $p,
        'facts'   => [
            ['Category', $p['cat']],
            ['Status', $p['status']],
            ['Entry', $sel ? 'Application' : 'Open registration'],
            ['Base', 'Alimosho, Lagos'],
        ],
        'steps'   => [
            [$sel ? 'Apply' : 'Register', $sel
                ? 'Submit a short application — we look for genuine desire to lead and serve.'
                : 'Registration is open and most places are free.'],
            ['Show up', 'Join sessions at a CACENTRE with mentors who are doing the work themselves.'],
            ['Grow into a Vanguard', 'Build real skills and character — and return to mentor the next group.'],
        ],
        'cta'     => $sel ? 'Apply now' : 'Register free',
        'ctaHref' => !empty($p['ext']) ? $p['href'] : AVPJ_VOLUNTEER_URL,
        'gallery' => $gallery,
        'others'  => $others,
        'programmes' => $p['programmes'] ?? [],
    ];
}
