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
  <section class="ethos-hero">
    <div class="container ethos-hero-grid">
      <div>
        <span class="diary-eyebrow">The Force for Good</span>
        <h1>The Ethos of<br/>Afrovanguardism</h1>
        <p class="ethos-lead"><?= e($preamble) ?></p>
        <div class="ethos-cta">
          <a class="btn btn-primary" href="#pdf">Read the full ethos</a>
          <a class="btn btn-outline" href="/assets/docs/afrovanguard-ethos.pdf" download>Download PDF ↓</a>
        </div>
      </div>
      <a class="ethos-cover" href="#pdf" aria-label="Open the ethos document">
        <picture><source srcset="/assets/img/ethos-cover.webp" type="image/webp" /><img src="/assets/img/ethos-cover.png" alt="The Global Ethos of Afrovanguardism cover" loading="eager" /></picture>
      </a>
    </div>
  </section>

  <div class="container ethos-body">
    <figure class="ethos-quote">
      <?= e($ethos["quote"]) ?>
    </figure>

    <section class="ethos-sec" id="purpose">
      <h2>Vision &amp; Mission</h2>
      <div class="ethos-vm">
        <div><h3>The Vision</h3><p><?= e($vision) ?></p></div>
        <div><h3>The Mission</h3><p><?= e($mission) ?></p></div>
      </div>
    </section>

    <section class="ethos-sec" id="commitments">
      <h2>Nine Guiding Commitments</h2>
      <p class="ethos-sub">Each builds upon the last — from the dignity of the person to the stewardship of the earth.</p>
<?php foreach ($commitments as $i => $c): ?>
      <article class="commit" id="commitment-<?= $i+1 ?>">
        <div class="commit-no"><?= $i+1 ?></div>
        <div class="commit-body">
          <h3><?= e($c[0]) ?></h3>
          <p class="commit-motto"><?= e($c[1]) ?></p>
          <p><?= e($c[2]) ?></p>
          <ul class="commit-list"><?php foreach ($c[3] as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
          <p class="commit-close"><?= e($c[4]) ?></p>
        </div>
      </article>
<?php endforeach; ?>
    </section>

    <section class="ethos-sec" id="leadership">
      <h2>The Leadership Model</h2>
      <p class="ethos-sub">Taken together, the nine commitments call for a distinct model of leadership.</p>
      <div class="ethos-grid">
<?php foreach ($leadership as $l): ?>        <div class="ethos-tile"><h3><?= e($l[0]) ?></h3><p><?= e($l[1]) ?></p></div>
<?php endforeach; ?>
      </div>
    </section>

    <section class="ethos-sec" id="values">
      <h2>The Seven Core Values</h2>
      <p class="ethos-sub">Imbibe · Impact · Influence.</p>
      <div class="ethos-grid values">
<?php foreach ($values as $n => $v): ?>        <div class="ethos-tile"><span class="val-no"><?= $n+1 ?></span><h3><?= e($v[0]) ?></h3><p><?= e($v[1]) ?></p></div>
<?php endforeach; ?>
      </div>
    </section>

    <section class="ethos-sec creed" id="creed">
      <h2>The Afrovanguard Creed</h2>
      <p class="ethos-sub">A daily affirmation of who an Afrovanguard chooses to be.</p>
      <ol class="creed-list">
<?php foreach ($creed as $c): ?>        <li><?= e($c) ?></li>
<?php endforeach; ?>
      </ol>
      <p class="creed-sign">This I affirm — in character, in conduct, and in community.</p>
    </section>

    <section class="ethos-sec" id="pdf">
      <h2>The full ethos</h2>
      <p class="ethos-sub">Read the complete, designed document below, or download it to share.</p>
      <div class="pdf-frame"><iframe src="/assets/docs/afrovanguard-ethos.pdf#view=FitH" title="The Global Ethos of Afrovanguardism" loading="lazy"></iframe></div>
      <noscript><p style="color:var(--muted)"><a href="/assets/docs/afrovanguard-ethos.pdf">Open the ethos PDF →</a></p></noscript>
      <div class="ethos-cta" style="margin-top:18px">
        <a class="btn btn-primary" href="/assets/docs/afrovanguard-ethos.pdf" target="_blank" rel="noopener">Open full screen ↗</a>
        <a class="btn btn-outline" href="/assets/docs/afrovanguard-ethos.pdf" download>Download PDF ↓</a>
      </div>
    </section>

    <section class="ethos-sec ethos-next" aria-label="Live the ethos">
      <h2>Live the ethos</h2>
      <p class="ethos-sub">The ethos is not a statement to admire — it is a way to act. Here is where it becomes real.</p>
      <div class="ethos-grid">
        <a class="ethos-tile next-tile" href="/academy/"><h3>Learn &amp; lead →</h3><p>Free, hands-on programmes in the Afrovanguard Academy.</p></a>
        <a class="ethos-tile next-tile" href="https://cacentre.afrovanguard.org.ng/volunteer"><h3>Join the movement →</h3><p>Volunteer your time, skills and presence in the community.</p></a>
        <a class="ethos-tile next-tile" href="/diary/"><h3>Read the Diary →</h3><p>Honest field notes on building leaders, in the open.</p></a>
      </div>
    </section>
  </div>
</main>
<?php render_footer();
