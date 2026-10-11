<?php
/**
 * tests/sitepages.test.php — every public page's words, links and pictures as
 * content (lib/SitePages.php, Studio → Content → Pages).
 *
 *   • Every page in the list is marked, and marking again changes nothing.
 *   • Publishing nothing changes nothing: an untouched page keeps its bytes.
 *   • What an editor types is words: escaped, never markup, never code.
 *   • A publish changes only the elements edited, and can be put back.
 */
declare(strict_types=1);
$root = dirname(__DIR__);

foreach (array_keys(SitePages::PAGES) as $f) Database::metaSet(SitePages::META . $f, '');

/* ── the pages as shipped ── */
foreach (SitePages::PAGES as $file => $name) {
    $html = (string) @file_get_contents("$root/$file");
    $marks = SitePages::scan($html);
    ck("pages: $name is marked", count($marks) > 5);
    ck("pages: marking $name again changes nothing", SitePages::annotate($html, SitePages::slug($file)) === $html);
    ck("pages: an empty draft leaves $name byte-identical", SitePages::apply($html, []) === $html);
    $byKey = [];
    $sameCopies = true;
    foreach ($marks as $m) {
        $v = $m['type'] === 'image' ? $m['src'] : $m['value'];
        if (isset($byKey[$m['key']]) && $byKey[$m['key']] !== $v) $sameCopies = false;
        $byKey[$m['key']] = $v;
    }
    ck("pages: a key on $name repeats only on an identical copy", $sameCopies);
    // Every value put back as it is must give the same bytes (the round trip is exact).
    $echo = [];
    foreach (SitePages::fields($file) as $f) $echo[$f['key']] = $f['type'] === 'image' ? ['src' => $f['src'], 'alt' => $f['alt']] : (['value' => $f['value']] + (isset($f['href']) ? ['href' => $f['href']] : []));
    ck("pages: every field of $name round-trips to the same bytes", SitePages::apply($html, $echo) === $html);
}
ck('pages: the homepage hero is not a Pages field (it has its own editor)', !preg_match('~<!-- cms:hero -->.*data-cms=.*<!-- /cms:hero -->~s', (string) file_get_contents("$root/index.html")));
ck('pages: the generated Projects page is not in the list', !SitePages::known('projects/index.html'));

/* ── on a temporary copy of the site ── */
$tr = sys_get_temp_dir() . '/av-pages-' . getmypid();
@mkdir("$tr/terms", 0775, true);
copy("$root/about.html", "$tr/about.html");
copy("$root/index.html", "$tr/index.html");
copy("$root/terms/index.php", "$tr/terms/index.php");
SitePages::$root = $tr;
$about = (string) file_get_contents("$tr/about.html");
$f = SitePages::fields('about.html');
$p = array_values(array_filter($f, fn($x) => $x['type'] === 'text' && $x['tag'] === 'p'))[0];
$a = array_values(array_filter($f, fn($x) => $x['type'] === 'link'))[0];
$img = array_values(array_filter($f, fn($x) => $x['type'] === 'image'))[0];

$c = SitePages::clean('about.html', [$a['key'] => ['href' => 'javascript:alert(1)']]);
ck('pages: a javascript: link is refused', !$c['ok']);
ck('pages: mailto: and tel: links are allowed', SitePages::link('mailto:cacentre@afrovanguard.org.ng') !== '' && SitePages::link('tel:+234 800 000 0000') !== '');
ck('pages: a data: picture is refused', !SitePages::clean('about.html', [$img['key'] => ['src' => 'data:image/png;base64,AA']])['ok']);
ck('pages: emptying a piece of text is refused', !SitePages::clean('about.html', [$p['key'] => ['value' => '   ']])['ok']);
ck('pages: a field that is not on the page is dropped', SitePages::clean('about.html', ['about-p-9999' => ['value' => 'x']])['draft'] === []);
ck('pages: an unknown page is refused', !SitePages::clean('../config.php', [])['ok']);

$out = SitePages::apply($about, [$p['key'] => ['value' => '<script>alert(1)</script> Tom & Jerry']]);
ck('pages: typed text is escaped, never markup', str_contains($out, '&lt;script&gt;alert(1)&lt;/script&gt; Tom &amp; Jerry') && !str_contains($out, '<script>alert(1)'));
ck('pages: …and only that element changed', strlen($out) - strlen($about) === strlen('&lt;script&gt;alert(1)&lt;/script&gt; Tom &amp; Jerry') - strlen(htmlspecialchars($p['value'], ENT_NOQUOTES)) || substr_count($out, 'data-cms=') === substr_count($about, 'data-cms='));
ck('pages: plain text keeps what was typed, a < sign included', SitePages::clean('about.html', [$p['key'] => ['value' => 'Love x<3 & more']])['draft'][$p['key']]['value'] === 'Love x<3 & more');
ck('pages: rich text keeps emphasis and nothing else', SitePages::rich('<em onclick="x">Hi</em> <strong>there</strong><script>bad()</script><a href="/x">link</a>') === '<em>Hi</em> <strong>there</strong>bad()link');
ck('pages: rich text closes what it opens', SitePages::rich('<em>open') === '<em>open</em>');
$out = SitePages::apply($about, [$img['key'] => ['src' => '/uploads/media/2026/10/new.jpg', 'alt' => 'A "quoted" alt']]);
ck('pages: a new picture replaces the address and drops stale sizes', str_contains($out, 'src="/uploads/media/2026/10/new.jpg"') && str_contains($out, 'alt="A &quot;quoted&quot; alt"'));
$out = SitePages::apply($about, [$a['key'] => ['value' => 'Read on', 'href' => '/ethos/']]);
ck('pages: a link changes its words and where it goes', str_contains($out, 'href="/ethos/"') && str_contains($out, '>Read on</a>'));

/* a picture swapped and put back returns exactly, size and all */
copy("$tr/about.html", "$tr/about.bak");
SitePages::saveDraft('about.html', [$img['key'] => ['src' => '/uploads/media/2026/10/new.jpg']]);
SitePages::publish('about.html', 'test@x');
SitePages::restore('about.html', 0); SitePages::publish('about.html', 'test@x');
ck('pages: putting a picture back restores it byte for byte', file_get_contents("$tr/about.html") === file_get_contents("$tr/about.bak"));
@unlink("$tr/about.bak"); Database::metaSet(SitePages::META . 'about.html', '');

/* the ticker's copy follows the original */
$idx = (string) file_get_contents("$tr/index.html");
$out = SitePages::apply($idx, ['index-li-1' => ['value' => '12,000+ Lives Transformed']]);
ck('pages: one edit changes the ticker and its scrolling copy', substr_count($out, '12,000+ Lives Transformed') === 2);

/* search and sharing */
$seo = SitePages::seo($about);
ck('pages: the title, description and share picture are read', ($seo['title'] ?? '') !== '' && ($seo['description'] ?? '') !== '');
$out = SitePages::apply($about, ['seo' => ['title' => 'About Afrovanguard', 'description' => 'Who we are.']]);
ck('pages: the title and description change', SitePages::seo($out)['title'] === 'About Afrovanguard' && SitePages::seo($out)['description'] === 'Who we are.');

/* a PHP template: words change, code never does */
$terms = (string) file_get_contents("$tr/terms/index.php");
$tp = array_values(array_filter(SitePages::fields('terms/index.php'), fn($x) => $x['type'] === 'text'))[0];
ck('pages: a draft saves', SitePages::saveDraft('terms/index.php', [$tp['key'] => ['value' => '<?php echo "pwned"; ?> Plain words']])['ok']);
ck('pages: a draft does not touch the page', file_get_contents("$tr/terms/index.php") === $terms);
ck('pages: the preview shows the draft, as words', str_contains((string) SitePages::previewHtml('terms/index.php'), '&lt;?php echo "pwned"; ?&gt; Plain words'));
$r = SitePages::publish('terms/index.php', 'test@x');
$after = (string) file_get_contents("$tr/terms/index.php");
ck('pages: publishing writes the page', $r['ok'] && str_contains($after, 'Plain words'));
ck('pages: …and typed PHP stays words', substr_count($after, '<?') === substr_count($terms, '<?') && !str_contains($after, '<?php echo "pwned"'));
$lint = @shell_exec('php -l ' . escapeshellarg("$tr/terms/index.php") . ' 2>&1');
ck('pages: …and the template still parses', $lint === null || str_contains((string) $lint, 'No syntax errors'));
ck('pages: publishing keeps a version to put back', count(SitePages::history('terms/index.php')) === 1 && SitePages::draft('terms/index.php') === []);
ck('pages: publishing nothing is refused', !SitePages::publish('terms/index.php', 'test@x')['ok']);
ck('pages: putting it back makes the old words the draft', SitePages::restore('terms/index.php', 0)['ok'] && (SitePages::draft('terms/index.php')[$tp['key']]['value'] ?? '') === $tp['value']);
ck('pages: …and publishing that returns the exact bytes', SitePages::publish('terms/index.php', 'test@x')['ok'] && file_get_contents("$tr/terms/index.php") === $terms);
ck('pages: a draft equal to the live page is no draft', SitePages::saveDraft('terms/index.php', [$tp['key'] => ['value' => $tp['value']]])['changed'] === 0);
ck('pages: discarding empties the draft', (function () use ($tp) { SitePages::saveDraft('terms/index.php', [$tp['key'] => ['value' => 'Other']]); SitePages::discard('terms/index.php'); return SitePages::draft('terms/index.php') === []; })());

/* the library keeps a picture a page shows */
ck('pages: a picture on a page is reported as in use', in_array('the About page', SitePages::usesUrl([$img['src']]), true));

array_map('unlink', array_filter([...glob("$tr/*.*") ?: [], ...glob("$tr/terms/*") ?: []], 'is_file'));
@rmdir("$tr/terms"); @rmdir($tr);
SitePages::$root = null;
foreach (array_keys(SitePages::PAGES) as $f) Database::metaSet(SitePages::META . $f, '');

/* ── the Studio ── */
$api = (string) file_get_contents("$root/admin/api.php");
$ui = (string) file_get_contents("$root/admin/index.php");
$js = (string) file_get_contents("$root/admin/site.js");
ck('pages: the Studio has the actions', str_contains($api, "case 'pages_list'") && str_contains($api, "case 'page_publish'") && str_contains($api, "case 'page_restore'"));
ck('pages: editors draft; publishing and putting back are management-only', (bool) preg_match("~managementOnly = \\[.*'page_publish', 'page_restore'~s", $api));
ck('pages: every content write must be a POST (CSRF always checked)', (bool) preg_match("~\\\$method !== 'POST' && in_array\\(\\\$action, \\[[^\\]]*'home_publish'[^\\]]*'page_publish'~s", $api));
ck('pages: the Pages screen is in the Content menu', str_contains($ui, 'data-tab="pages"') && str_contains($ui, 'id="pagesView"'));
ck('pages: no inline style on the Pages screen', !preg_match('~id="pagesView".*?</main>~s', $ui, $m) || !str_contains($m[0], 'style='));
ck('pages: the editor sets no inline style', !preg_match('/style\s*=|\.style\./', $js));
ck('pages: the preview takes a page', str_contains((string) file_get_contents("$root/admin/preview.php"), 'SitePages::known($page)'));
