<?php
/**
 * tools/build-avh-chrome.php — put the Home nav and footer into the
 * redesigned static pages (partials/avh-chrome.php is the source).
 *
 * A page opts in with two marker pairs; everything between them is replaced:
 *   <!-- avh:nav --> … <!-- /avh:nav -->
 *   <!-- avh:foot --> … <!-- /avh:foot -->
 *
 *   php tools/build-avh-chrome.php          # rewrite the pages
 *   php tools/build-avh-chrome.php --check  # exit 1 on drift, change nothing
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require __DIR__ . '/../partials/avh-chrome.php';

$root  = dirname(__DIR__);
$check = in_array('--check', $argv, true);
$c = avh_chrome_html();
$drift = 0;

foreach (avh_chrome_pages($root) as $p) {
    $html = (string) file_get_contents($p);
    if (!str_contains($html, '<!-- avh:nav -->') && !str_contains($html, '<!-- avh:foot -->')) continue;
    $new = avh_chrome_splice($html, $c);
    if ($new === $html) continue;
    $drift++;
    $rel = substr($p, strlen($root) + 1);
    if ($check) { echo "drift: $rel\n"; continue; }
    file_put_contents($p, $new);
    echo "updated: $rel\n";
}
if ($check && $drift) exit(1);
echo $check ? "avh chrome: in sync\n" : "avh chrome: $drift page(s) updated\n";
