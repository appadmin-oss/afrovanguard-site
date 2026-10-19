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
    ];
}
