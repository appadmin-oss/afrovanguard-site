<?php
/**
 * tests/errorpages.test.php — the error pages and the search page wear the Home chrome.
 *
 * Apache serves 403/404/429/500/503.html when PHP may be down; they are written
 * from lib/errors.php by tools/build-error-pages.php, so the static copy and the
 * page PHP renders cannot drift apart.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/errors.php';
require_once AV_ROOT . '/partials/avh-chrome.php';
$chrome = avh_chrome_html();

foreach (av_error_static_codes() as $code) {
    $file = (string) @file_get_contents(AV_ROOT . "/$code.html");
    ck("errors: $code.html is in step with lib/errors.php (php tools/build-error-pages.php)", $file === av_error_static_html($code));
    ck("errors: $code.html carries the Home nav and footer between markers",
       str_contains($file, "<!-- avh:nav -->\n" . $chrome['nav'] . "\n<!-- /avh:nav -->")
       && str_contains($file, "<!-- avh:foot -->\n" . $chrome['foot'] . "\n<!-- /avh:foot -->"));
    ck("errors: $code.html loads the new system and no inline <style>",
       str_contains($file, 'href="/assets/site/av-tokens.css"') && str_contains($file, 'href="/assets/site/averr.css"') && !str_contains($file, '<style'));
    ck("errors: $code.html has Home's skip link and main", str_contains($file, '<a class="avh-skip" href="#main">') && str_contains($file, '<main id="main" tabindex="-1"'));
    ck("errors: $code.html carries no frozen poem", !str_contains($file, 'averr-poem'));
}
ck('errors: the 404 offers a search, sent to the search page', str_contains(av_error_static_html(404), 'action="/search.php"'));
ck('errors: …and only the 404', !str_contains(av_error_static_html(500), 'action="/search.php"'));

/* The search page: a browser gets a page, the modal's fetch keeps its JSON. */
$src = (string) file_get_contents(AV_ROOT . '/search.php');
ck('search: a text/html request is served the page view', str_contains($src, "stripos(\$accept, 'text/html')") && str_contains($src, "lib/search-page.php"));
ck('search: ?format=json keeps the JSON for anyone who asks', str_contains($src, "(\$_GET['format'] ?? '') !== 'json'"));
$page = (string) file_get_contents(AV_ROOT . '/lib/search-page.php');
ck('search: the page view is drawn in the site chrome', str_contains($page, 'render_nav(') && str_contains($page, 'render_footer('));
ck('search: …with a labelled search form and a live result count', str_contains($page, 'role="search"') && str_contains($page, '<label class="avsr-label" for="avsr-q">') && str_contains($page, 'role="status"'));
ck('search: …and an honest error when search fails', str_contains($page, 'Search is unavailable right now.'));
