<?php
/**
 * tools/build-error-pages.php — write the static error pages Apache serves
 * (.htaccess ErrorDocument 403/404/429/500/503) from lib/errors.php, so they
 * are the same page PHP renders, in the Home chrome.
 *
 *   php tools/build-error-pages.php          # rewrite 403/404/429/500/503.html
 *   php tools/build-error-pages.php --check  # exit 1 if any is out of date
 *
 * The nav and footer sit between <!-- avh:nav/foot --> markers, so
 * tools/build-avh-chrome.php keeps them in step with Home afterwards too.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require __DIR__ . '/../lib/errors.php';

$root  = dirname(__DIR__);
$check = in_array('--check', $argv, true);
$drift = 0;
foreach (av_error_static_codes() as $code) {
    $html = av_error_static_html($code);
    $path = "$root/$code.html";
    if ((string) @file_get_contents($path) === $html) continue;
    $drift++;
    if ($check) { echo "drift: $code.html\n"; continue; }
    file_put_contents($path, $html);
    echo "updated: $code.html\n";
}
if ($check && $drift) exit(1);
echo $check ? "error pages: in sync\n" : "error pages: $drift updated\n";
