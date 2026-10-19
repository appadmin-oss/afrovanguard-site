<?php
/**
 * tests/assetstamp.test.php — every page asks for the CSS and JS it was built with.
 *
 * The site is uploaded by hand, so markup and stylesheet can reach the host (or a
 * browser's cache) at different times. A page from the current nav markup drawn
 * with an older avh.css showed "Sign in", "My portal" and an unstyled account
 * button at once. A content hash in each asset URL makes that pairing impossible.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$css = av_asset('/assets/site/avh.css');
ck('assets: a local stylesheet is stamped with its content hash',
   (bool) preg_match('~^/assets/site/avh\.css\?v=[0-9a-f]{10}$~', $css)
   && substr($css, -10) === substr(md5_file(AV_ROOT . '/assets/site/avh.css'), 0, 10));
ck('assets: a missing file keeps its plain URL', av_asset('/assets/site/no-such-file.css') === '/assets/site/no-such-file.css');
ck('assets: nothing outside a plain local path is touched',
   av_asset('https://cdn.example/x.css') === 'https://cdn.example/x.css'
   && av_asset('/assets/../lib/x.css') === '/assets/../lib/x.css');

$html = '<link rel="stylesheet" href="/assets/site/avh.css?v=0000000000" /><script src="/assets/site/avh.js" defer></script>';
$out  = av_stamp_html($html);
ck('assets: a stale stamp is replaced, a missing one added',
   str_contains($out, av_asset('/assets/site/avh.css')) && str_contains($out, av_asset('/assets/site/avh.js'))
   && !str_contains($out, '0000000000'));

foreach (av_static_pages() as $rel) {
    $src = (string) @file_get_contents(AV_ROOT . '/' . $rel);
    if ($src === '') continue;
    ck('assets: ' . $rel . ' links the files it was stamped against (php tools/stamp-assets.php)', av_stamp_html($src) === $src);
}

// The PHP pages that draw the site nav stamp it too.
foreach (['lib/partials.php', 'lib/errors.php', 'how-it-works.php', 'events/index.php', 'ethos/index.php', 'give/index.php', 'projects/_detail.php'] as $rel) {
    $src = (string) @file_get_contents(AV_ROOT . '/' . $rel);
    ck('assets: ' . $rel . ' asks for avh.css through av_asset()',
       (str_contains($src, "av_asset('/assets/site/avh.css')") || str_contains($src, "av_err_asset('/assets/site/avh.css')"))
       && !str_contains($src, 'href="/assets/site/avh.css"'));
}
