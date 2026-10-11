<?php
/**
 * tools/cms-annotate.php — mark what the Studio's Pages editor may change.
 *
 *   php tools/cms-annotate.php           add data-cms marks to every page in SitePages::PAGES
 *   php tools/cms-annotate.php --check   exit 1 if a page has an editable element left unmarked
 *
 * Run it after adding copy to a page, then commit the result: the marks are
 * part of the page source. Rules are in SitePages::annotate().
 */
declare(strict_types=1);

define('AV_ROOT', dirname(__DIR__));
require AV_ROOT . '/lib/SiteHome.php';
require AV_ROOT . '/lib/SitePages.php';

$check = in_array('--check', $argv, true);
$drift = 0;
foreach (SitePages::PAGES as $file => $name) {
    $path = AV_ROOT . '/' . $file;
    if (!is_file($path)) { echo "missing: $file\n"; continue; }
    $html = (string) file_get_contents($path);
    $new = SitePages::annotate($html, SitePages::slug($file));
    $n = count(SitePages::scan($new));
    if ($new === $html) { echo "ok: $file ($n fields)\n"; continue; }
    $drift++;
    if ($check) { echo "unmarked: $file\n"; continue; }
    file_put_contents($path, $new);
    echo "marked: $file ($n fields)\n";
}
exit($check && $drift ? 1 : 0);
