<?php
/**
 * Africa GATES moved to its own site.
 *
 * A PHP fallback beside the .htaccess rule, the same belt-and-braces the
 * /blog → /diary move uses: this directory is real, so a host that does not
 * honour .htaccess would otherwise serve it, and the root rule cannot be
 * relied on alone.
 *
 * The demo page that used to live here is in the history —
 * `git log --diff-filter=D -- projects/africa-gates/index.html` — and nothing
 * on the site links to it any more.
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$rest = preg_replace('#^/?projects/africa-gates/?#', '', ltrim($path, '/'));
$qs   = $_SERVER['QUERY_STRING'] ?? '';
$dest = 'https://afg.afrovanguard.org.ng/' . $rest . ($qs !== '' ? '?' . $qs : '');

header('Location: ' . $dest, true, 301);
header('Cache-Control: max-age=86400');
echo 'Africa GATES has moved to <a href="' . htmlspecialchars($dest, ENT_QUOTES) . '">afg.afrovanguard.org.ng</a>.';
