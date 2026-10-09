<?php
/**
 * ethos/index.php — /ethos/, row 12 of the site redesign ("Afrovanguard Ethos.dc.html"):
 * The Global Ethos of Afrovanguardism ("The Force for Good").
 *
 * Every word of the ethos comes from lib/ethos_content.php, the single source
 * of truth. ?format=txt (or Accept: text/plain without text/html) still returns
 * the whole document as plain text. Home nav and footer via
 * partials/avh-chrome.php; styles in assets/site/aveth.css (prefix aveth-),
 * behaviour in assets/site/aveth.js. #leadership is linked from the site nav.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/partials/avh-chrome.php';

$S = rtrim(SITE_URL, '/');
$canonical = "$S/ethos/";
$pdf = "$S/assets/docs/afrovanguard-ethos.pdf";
$cover = "$S/assets/img/ethos-cover.webp";
$ogcard = "$S/assets/img/ethos-og.webp";

$ethos       = require AV_ROOT . '/lib/ethos_content.php';
$preamble    = $ethos['preamble'];
$commitments = $ethos['commitments'];
$leadership  = $ethos['leadership'];
$values      = $ethos['values'];
$creed       = $ethos['creed'];

/** The whole ethos as plain text (faithful to the PDF), for clients that ask for text. */
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

$fmt       = strtolower((string) ($_GET['format'] ?? ''));
$accept    = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
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

$title = 'The Ethos of Afrovanguardism — The Force for Good';
$desc  = 'The global ethos of Afrovanguardism: human dignity, selfless service, integrity, cultural heritage, good governance, peace, knowledge and environmental stewardship — nine commitments, a leadership model, seven core values and the Afrovanguard Creed.';
$jsonld = [
  schema_org(),
  ['@type' => 'Article', '@id' => $canonical . '#ethos', 'headline' => 'The Global Ethos of Afrovanguardism — The Force for Good',
   'description' => $preamble, 'image' => [$ogcard, $cover], 'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
   'author' => ['@id' => $S . '/#organization'], 'publisher' => ['@id' => $S . '/#organization'],
   'about' => ['ethics', 'leadership', 'culture', 'governance', 'sustainable development'],
   'associatedMedia' => ['@type' => 'MediaObject', 'contentUrl' => $pdf, 'encodingFormat' => 'application/pdf', 'name' => 'Afrovanguard Ethos (PDF)']],
  schema_breadcrumb([['name' => 'Home', 'url' => "$S/"], ['name' => 'About', 'url' => "$S/about.html"], ['name' => 'Ethos', 'url' => $canonical]]),
];
$two = static fn(int $i): string => str_pad((string) $i, 2, '0', STR_PAD_LEFT);

if (function_exists('send_security_headers')) send_security_headers('public');
?>
<!DOCTYPE html>
<html lang="en-NG" prefix="og: https://ogp.me/ns#" class="no-js">
<head>
  <script>document.documentElement.classList.replace('no-js','js')</script>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($desc) ?>" />
  <meta name="author" content="Afrovanguard — afrovanguard.org.ng" />
  <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1" />
  <meta name="keywords" content="Afrovanguardism, Afrovanguard ethos, The Force for Good, ethical leadership Africa, nine commitments, Afrovanguard Creed, core values" />
  <link rel="canonical" href="<?= e($canonical) ?>" />
  <link rel="alternate" type="text/plain" href="<?= e($canonical) ?>?format=txt" title="The ethos as plain text" />
  <meta name="theme-color" content="rgb(243 180 22)" />
  <meta property="og:type" content="article" />
  <meta property="og:site_name" content="Afrovanguard" />
  <meta property="og:locale" content="en_NG" />
  <meta property="og:title" content="<?= e($title) ?>" />
  <meta property="og:description" content="<?= e($desc) ?>" />
  <meta property="og:url" content="<?= e($canonical) ?>" />
  <meta property="og:image" content="<?= e($ogcard) ?>" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />
  <meta property="og:image:alt" content="The Ethos of Afrovanguardism" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:site" content="@afrovanguard" />
  <meta name="twitter:title" content="<?= e($title) ?>" />
  <meta name="twitter:description" content="<?= e($desc) ?>" />
  <meta name="twitter:image" content="<?= e($ogcard) ?>" />
  <meta name="twitter:image:alt" content="The Ethos of Afrovanguardism" />
  <script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@graph' => $jsonld], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
  <link rel="icon" href="/favicon.ico" sizes="any" />
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/site/icon-192.png" />
  <link rel="apple-touch-icon" href="/assets/site/icon-192.png" />
  <link rel="stylesheet" href="/assets/site/fonts.css" />
  <link rel="stylesheet" href="/assets/site/av-tokens.css" />
  <link rel="stylesheet" href="/assets/site/avh.css" />
  <link rel="stylesheet" href="/assets/site/aveth.css" />
  <script src="/assets/site/avh.js" defer></script>
</head>
<body class="avh" id="top">
<a class="avh-skip" href="#main">Skip to content</a>
<div class="avh-page">
<?php avh_nav(); ?>

<main id="main" tabindex="-1" class="aveth">

<header class="aveth-hero">
  <div class="avh-topo" data-avh-topo="light" data-seed="7" aria-hidden="true"></div>
  <div class="aveth-wrap">
    <nav class="aveth-crumbs" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">›</span><a href="/about.html">About</a><span aria-hidden="true">›</span><span aria-current="page">Ethos</span></nav>
    <div class="aveth-hero-grid">
      <div class="aveth-hero-copy">
        <div class="aveth-eyebrow">The Force for Good</div>
        <h1>The Ethos of Afrovanguardism</h1>
        <p><?= e($preamble) ?></p>
        <div class="aveth-btns">
          <a class="aveth-btn" href="#commitments">Read the nine commitments</a>
          <a class="aveth-btn aveth-btn--line" href="/assets/docs/afrovanguard-ethos.pdf" download>Download PDF <span aria-hidden="true">↓</span></a>
        </div>
      </div>
      <a class="aveth-cover" href="/assets/docs/afrovanguard-ethos.pdf" aria-label="Open the ethos document (PDF)">
        <picture><source srcset="/assets/img/ethos-cover.webp" type="image/webp" /><img src="/assets/img/ethos-cover.png" alt="The Global Ethos of Afrovanguardism — cover" width="420" height="560" /></picture>
      </a>
    </div>
  </div>
</header>

<section class="aveth-quote" aria-label="Our vision of the world">
  <figure>
    <span class="aveth-diamond" aria-hidden="true"></span>
    <blockquote><?= e($ethos['quote']) ?></blockquote>
  </figure>
</section>

<section id="purpose" class="aveth-vm" aria-label="Vision and mission">
  <div class="aveth-wrap aveth-vm-grid">
    <div class="aveth-vm-card aveth-vm-card--ink"><h2 class="aveth-eyebrow">The Vision</h2><p><?= e($ethos['vision']) ?></p></div>
    <div class="aveth-vm-card"><h2 class="aveth-eyebrow">The Mission</h2><p><?= e($ethos['mission']) ?></p></div>
  </div>
</section>

<section id="commitments" class="aveth-commit" aria-labelledby="aveth-commit-h">
  <div class="avh-topo" data-avh-topo="light" data-seed="11" aria-hidden="true"></div>
  <div class="aveth-wrap">
    <div class="aveth-split">
      <div><div class="aveth-eyebrow">Nine guiding commitments</div><h2 class="aveth-h2" id="aveth-commit-h">Each builds upon the last</h2></div>
      <p>From the dignity of the person to the stewardship of the earth — nine commitments every Afrovanguard holds, in this order.</p>
    </div>
    <div class="aveth-commit-layout">
      <nav class="aveth-toc" aria-label="Commitments" data-aveth-toc>
<?php foreach ($commitments as $i => $c): ?>
        <a href="#commitment-<?= $i + 1 ?>" data-aveth-toc-link="<?= $i ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>><span class="av-num"><?= $two($i + 1) ?></span><span><?= e($c[0]) ?></span></a>
<?php endforeach; ?>
      </nav>
      <div class="aveth-commit-list">
<?php foreach ($commitments as $i => $c): ?>
        <article id="commitment-<?= $i + 1 ?>" class="aveth-c" data-aveth-commit="<?= $i ?>" aria-labelledby="aveth-c<?= $i + 1 ?>">
          <div class="aveth-c-n" aria-hidden="true"><?= $two($i + 1) ?></div>
          <div class="aveth-c-b">
            <h3 id="aveth-c<?= $i + 1 ?>"><?= e($c[0]) ?></h3>
            <p class="aveth-c-motto"><?= e($c[1]) ?></p>
            <p><?= e($c[2]) ?></p>
<?php if (!empty($c[3])): ?>
            <ul class="aveth-chips"><?php foreach ($c[3] as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
<?php endif; if (!empty($c[4])): ?>
            <p class="aveth-c-close"><?= e($c[4]) ?></p>
<?php endif; ?>
          </div>
        </article>
<?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<section id="leadership" class="aveth-lead" aria-labelledby="aveth-lead-h">
  <div class="avh-topo" data-avh-topo="dark" data-seed="15" aria-hidden="true"></div>
  <div class="aveth-wrap">
    <div class="aveth-split aveth-split--ink">
      <div><div class="aveth-eyebrow aveth-eyebrow--light">The leadership model</div><h2 class="aveth-h2" id="aveth-lead-h">Six marks of an Afrovanguard leader</h2></div>
      <p>Taken together, the nine commitments call for a distinct model of leadership.</p>
    </div>
    <ol class="aveth-marks">
<?php foreach ($leadership as $i => $l): ?>
      <li><span class="av-num"><?= $two($i + 1) ?></span><h3><?= e($l[0]) ?></h3><p><?= e($l[1]) ?></p></li>
<?php endforeach; ?>
    </ol>
  </div>
</section>

<section id="values" class="aveth-sec" aria-labelledby="aveth-values-h">
  <div class="aveth-wrap aveth-values">
    <div class="aveth-values-head">
      <div class="aveth-eyebrow">The seven core values</div>
      <h2 class="aveth-h2" id="aveth-values-h">Imbibe. Impact. Influence.</h2>
      <p>The values a member lives first, then carries into their community, then multiplies in others.</p>
    </div>
    <ol class="aveth-values-list">
<?php foreach ($values as $n => $v): ?>
      <li><span class="aveth-v-n"><?= $n + 1 ?></span><span><b><?= e($v[0]) ?></b><span><?= e($v[1]) ?></span></span></li>
<?php endforeach; ?>
    </ol>
  </div>
</section>

<section id="creed" class="aveth-creed" aria-labelledby="aveth-creed-h">
  <div class="aveth-wrap aveth-creed-in">
    <div class="aveth-eyebrow">The Afrovanguard Creed</div>
    <h2 id="aveth-creed-h">A daily affirmation of who we choose to be</h2>
    <ol>
<?php foreach ($creed as $c): ?>
      <li><?= e($c) ?></li>
<?php endforeach; ?>
    </ol>
    <p class="aveth-affirm">This I affirm — in character, in conduct, and in community</p>
  </div>
</section>

<section class="aveth-next" aria-labelledby="aveth-next-h">
  <div class="aveth-wrap">
    <div class="aveth-next-head">
      <div><div class="aveth-eyebrow">Live the ethos</div><h2 class="aveth-h2" id="aveth-next-h">Where it becomes real</h2></div>
      <a class="aveth-btn aveth-btn--line" href="/assets/docs/afrovanguard-ethos.pdf" download>Download the full ethos <span aria-hidden="true">↓</span></a>
    </div>
    <div class="aveth-next-grid">
      <a href="/academy/"><img src="/Images/bootcamp1.png" alt="" width="640" height="400" loading="lazy"><span><b>Learn &amp; lead <span aria-hidden="true">→</span></b><span>Free, hands-on programmes in the Afrovanguard Academy.</span></span></a>
      <a href="https://cacentre.afrovanguard.org.ng/volunteer"><img src="/Images/storm2.jpg" alt="" width="640" height="400" loading="lazy"><span><b>Join the movement <span aria-hidden="true">→</span></b><span>Volunteer your time, skills and presence in the community.</span></span></a>
      <a href="/diary/"><img src="/Images/alimosho.jpg" alt="" width="640" height="400" loading="lazy"><span><b>Read the Diary <span aria-hidden="true">→</span></b><span>Honest field notes on building leaders, in the open.</span></span></a>
    </div>
  </div>
</section>

</main>
<?php avh_footer(); ?>
</div>
<script src="/assets/site/chioma.js" defer></script>
<script src="/assets/site/celebrations.js" defer></script>
</body>
</html>
