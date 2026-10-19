<?php
/**
 * give/og.php — the share card for one appeal (1200×630 PNG).
 *   /give/og/<slug>.png   or   ?slug=<slug>
 *
 * This image IS the appeal on WhatsApp, and on WhatsApp nobody reads past it.
 * So it carries the three things that make somebody tap: the title, the figure,
 * and the progress bar. Design: Afrovanguard OG Images · 1c (the project /
 * programme card — ink, photo on the right half), lib/AvOg.php::appeal().
 *
 * Cached against a key built from everything drawn, so it is regenerated when
 * the appeal moves and served from disk the rest of the time — a share card is
 * requested by a crawler for every person the link reaches.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/AvOg.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$fallback = rtrim(SITE_URL, '/') . '/assets/og/og-default.png';

$a = $slug !== '' ? Appeals::bySlug($slug) : null;
/* A draft has no share card. Redirecting to the site card rather than drawing
   one is deliberate: an unpublished appeal must not leak its title through an
   image either. */
if (!Appeals::isPublic($a) || !AvImage::available()) {
    header('Location: ' . $fallback, true, 302);
    exit;
}

$card = avog_appeal_card($a, Appeals::state($a));
AvImage::serveCached('giveog-' . $slug, md5(json_encode($card) . '|1c|' . @filemtime(AV_ROOT . '/lib/AvOg.php')), function () use ($card) {
    $card['photo'] = $card['cover'] !== '' ? AvImage::load($card['cover']) : null;
    return AvOg::appeal($card);
}, 3600);

/** What the card says, from the appeal and its verified state. */
function avog_appeal_card(array $a, array $st): array
{
    $figure = $st['percent'] !== null
        ? Appeals::naira($st['raised']) . ' raised of ' . Appeals::naira($st['goal']) . ' · ' . $st['percent'] . '%'
        : Appeals::naira($st['raised']) . ' raised so far';
    $figure .= ' · ' . $st['donors'] . ' donor' . ($st['donors'] === 1 ? '' : 's');
    if ($st['days_left'] !== null && $st['days_left'] >= 0 && empty($st['ended'])) $figure .= ' · ' . $st['days_left'] . ' days left';
    return [
        'title'   => (string) $a['title'],
        'kicker'  => ((string) ($a['kind'] ?? '') === 'emergency' ? 'Emergency appeal' : 'Appeal') . (!empty($a['location']) ? ' · ' . $a['location'] : ''),
        'figure'  => $figure,
        'percent' => $st['percent'],
        'met'     => !empty($st['met']),
        'url'     => 'afrovanguard.org.ng/give',
        'cover'   => (string) ($a['cover_url'] ?? ''),
        'status'  => (string) ($a['status'] ?? ''),
    ];
}
