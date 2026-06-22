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
