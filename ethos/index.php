<?php
/**
 * ethos/index.php — The Global Ethos of Afrovanguardism ("The Force for Good").
 * Faithful to assets/docs/afrovanguard-ethos.pdf, with view/download + strong SEO.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$S = rtrim(SITE_URL, '/');
$canonical = "$S/ethos/";
$pdf = "$S/assets/docs/afrovanguard-ethos.pdf";
$cover = "$S/assets/img/ethos-cover.webp";
$ogcard = "$S/assets/img/ethos-og.webp";

// Single source of truth (shared with the About page via tools/build-chrome.php)
$ethos       = require AV_ROOT . '/lib/ethos_content.php';
$preamble    = $ethos['preamble'];
$vision      = $ethos['vision'];
$mission     = $ethos['mission'];
$commitments = $ethos['commitments'];
$leadership  = $ethos['leadership'];
$values      = $ethos['values'];
$creed       = $ethos['creed'];

/**
 * Plain-text rendering of the full ethos (faithful to the PDF). Served when a
 * client asks for text — Accept: text/plain (and not text/html), or ?format=txt
 * — so the whole document is fetchable as text over HTTP without the page chrome.
 * The HTML page below is unchanged for browsers.
 */
function ethos_to_text(array $e): string {
    $nl = "\n"; $hr = str_repeat('=', 72);
    $w  = static fn(string $s): string => wordwrap($s, 78);
    $out  = 'THE GLOBAL ETHOS OF AFROVANGUARDISM — THE FORCE FOR GOOD' . $nl . $hr . $nl . $nl;
    $out .= $w($e['preamble']) . $nl . $nl;
    if (!empty($e['quote'])) $out .= $w('"' . trim($e['quote']) . '"') . $nl . $nl;
    $out .= 'VISION' . $nl . $w($e['vision']) . $nl . $nl;
    $out .= 'MISSION' . $nl . $w($e['mission']) . $nl . $nl;
    $out .= 'NINE GUIDING COMMITMENTS' . $nl . str_repeat('-', 24) . $nl . $nl;
    foreach ($e['commitments'] as $i => $c) {
        $out .= ($i + 1) . '. ' . $c[0] . $nl;
        if (!empty($c[1])) $out .= '   ' . $c[1] . $nl;
        $out .= $w($c[2]) . $nl;
        foreach (($c[3] ?? []) as $b) $out .= '   - ' . $b . $nl;
        if (!empty($c[4])) $out .= $w($c[4]) . $nl;
        $out .= $nl;
    }
    $out .= 'THE LEADERSHIP MODEL' . $nl . str_repeat('-', 20) . $nl . $nl;
    foreach ($e['leadership'] as $l) $out .= '- ' . $l[0] . ' — ' . $l[1] . $nl;
    $out .= $nl . 'THE SEVEN CORE VALUES' . $nl . str_repeat('-', 21) . $nl . $nl;
    foreach ($e['values'] as $n => $v) $out .= ($n + 1) . '. ' . $v[0] . ' — ' . $v[1] . $nl;
    $out .= $nl . 'THE AFROVANGUARD CREED' . $nl . str_repeat('-', 22) . $nl . $nl;
    foreach ($e['creed'] as $c) $out .= '- ' . $c . $nl;
    $out .= $nl . 'This I affirm — in character, in conduct, and in community.' . $nl;
    $out .= $nl . $hr . $nl . 'Source: ' . rtrim(SITE_URL, '/') . '/assets/docs/afrovanguard-ethos.pdf' . $nl;
    return $out;
}

$fmt       = strtolower((string)($_GET['format'] ?? ''));
$accept    = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
$wantsText = in_array($fmt, ['txt', 'text', 'plain'], true)
          || ($accept !== '' && stripos($accept, 'text/plain') !== false && stripos($accept, 'text/html') === false);
if ($wantsText) {
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Link: <' . $canonical . '>; rel="canonical"');
    }
    echo ethos_to_text($ethos);
    return;
}

$jsonld = [
  schema_org(),
  ['@type' => 'Article', '@id' => $canonical . '#ethos', 'headline' => 'The Global Ethos of Afrovanguardism — The Force for Good',
   'description' => $preamble, 'image' => [$ogcard, $cover], 'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
   'author' => ['@id' => $S . '/#organization'], 'publisher' => ['@id' => $S . '/#organization'],
   'about' => ['ethics','leadership','culture','governance','sustainable development'],
   'associatedMedia' => ['@type' => 'MediaObject', 'contentUrl' => $pdf, 'encodingFormat' => 'application/pdf', 'name' => 'Afrovanguard Ethos (PDF)']],
  schema_breadcrumb([['name' => 'Home', 'url' => "$S/"], ['name' => 'About', 'url' => "$S/about/"], ['name' => 'Ethos', 'url' => $canonical]]),
];

render_head([
  'title' => 'The Ethos of Afrovanguardism — The Force for Good',
  'desc'  => 'The global ethos of Afrovanguardism: human dignity, selfless service, integrity, cultural heritage, good governance, peace, knowledge and environmental stewardship — nine commitments, a leadership model, seven core values and the Afrovanguard Creed.',
  'canonical' => $canonical, 'og_kind' => 'article', 'image' => $ogcard, 'image_alt' => 'The Ethos of Afrovanguardism',
  'keywords' => 'Afrovanguardism, Afrovanguard ethos, The Force for Good, ethical leadership Africa, nine commitments, Afrovanguard Creed, core values',
  'css' => ['/ethos/ethos.css'], 'jsonld' => $jsonld,
]);
render_nav('about');
?>
<main id="main-content">
</main>
<?php render_footer();
