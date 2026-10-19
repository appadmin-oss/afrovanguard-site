<?php
/**
 * tools/build-chrome.php — RETIRED.
 *
 * This used to splice the old site-header nav and site-footer (render_nav() and
 * av_footer_inner() from lib/partials.php) into the static marketing pages. Since
 * the redesign every page wears the Home nav and footer instead:
 *
 *   PHP pages     render_nav() / render_footer() emit partials/avh-chrome.php
 *   static pages  <!-- avh:nav --> / <!-- avh:foot --> markers, filled by
 *                 php tools/build-avh-chrome.php  (tests/avhchrome.test.php)
 *
 * Its page list had been empty since rows 4–8 moved the last static pages over,
 * and running it again could only put the old chrome back, so it no longer does
 * anything but point at the tool that replaced it. --check exits 0: there is
 * nothing of its own left to drift.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

echo "tools/build-chrome.php is retired: the site chrome is the Home nav and footer.\n"
   . "Static pages: php tools/build-avh-chrome.php" . (in_array('--check', $argv, true) ? ' --check' : '') . "\n";
exit(0);
