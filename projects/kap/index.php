<?php
/**
 * projects/kap/index.php — Kingdom Advancement Project (KAP).
 *
 * Heritage- and culture-first leadership: carrying African heritage with depth,
 * wisdom, and strategic intelligence. Mirrors the ethos page's structure so it
 * sits natively in the site (shared chrome via render_head/render_nav/render_footer).
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$S         = rtrim(SITE_URL, '/');
$canonical = "$S/projects/kap/";
$lead      = 'Understand and carry your African heritage with depth, wisdom, and strategic intelligence. Kingdom Advancement equips leaders with the cultural foundations that last.';

// The four foundations. Names are the brief; the one-liners are written to fit
// KAP's heritage / cultural-leadership context.
$pillars = [
    ['Cultural Intelligence',         'Read culture as fluently as strategy — the customs, languages, and unspoken codes that move people — and lead with context instead of assumption.'],
    ['Protocol & Leadership Ethics',  'The etiquette, discretion, and integrity expected in palaces, boardrooms, and public life — so you carry authority without ever losing character.'],
    ['Heritage Development',          'Turn inheritance into momentum: document, protect, and build on the traditions and institutions entrusted to your generation.'],
    ['Traditional Support Systems',   'The councils, elders, and community structures that keep leaders accountable — and carry them when the work grows heavy.'],
];

$jsonld = [
    schema_org(),
    ['@type' => 'CreativeWork', '@id' => $canonical . '#kap',
     'name' => 'Kingdom Advancement Project (KAP)', 'headline' => 'Kingdom Advancement Project',
     'description' => $lead,
     'about' => ['African heritage', 'cultural intelligence', 'traditional leadership', 'leadership ethics'],
     'isPartOf' => ['@id' => $S . '/#organization'], 'publisher' => ['@id' => $S . '/#organization']],
    schema_breadcrumb([
        ['name' => 'Home', 'url' => "$S/"],
        ['name' => 'Projects', 'url' => "$S/projects/"],
        ['name' => 'Kingdom Advancement', 'url' => $canonical],
    ]),
];

render_head([
    'title' => 'Kingdom Advancement Project (KAP) — Afrovanguard',
    'desc'  => $lead,
    'canonical' => $canonical, 'og_kind' => 'website',
    'image_alt' => 'Kingdom Advancement Project — Afrovanguard',
    'keywords' => 'Kingdom Advancement Project, KAP, African heritage, cultural intelligence, traditional leadership, leadership ethics, heritage development, Afrovanguard',
    'css' => ['/projects/kap/kap.css'], 'jsonld' => $jsonld,
]);
render_nav('projects');
?>
<main id="main-content">
  <section class="kap-hero">
    <div class="container">
      <span class="diary-eyebrow">Kingdom Advancement Project · KAP</span>
      <h1>What is Kingdom<br>Advancement?</h1>
      <p class="kap-lead"><?= e($lead) ?></p>
      <div class="kap-cta">
        <a class="btn btn-primary" href="/contact/">Get involved</a>
        <a class="btn btn-outline" href="/projects/">All projects</a>
      </div>
    </div>
  </section>

  <div class="container kap-body">
    <section class="kap-sec" id="foundations">
      <h2>Four cultural foundations</h2>
      <p class="kap-sub">Heritage is not nostalgia — it is a working operating system for leadership. Kingdom Advancement builds it on four foundations that outlast any title.</p>
      <div class="kap-grid">
<?php foreach ($pillars as $i => $p): ?>        <article class="kap-tile">
          <span class="kap-no"><?= $i + 1 ?></span>
          <h3><?= e($p[0]) ?></h3>
          <p><?= e($p[1]) ?></p>
        </article>
<?php endforeach; ?>
      </div>
    </section>

    <section class="kap-sec" id="who">
      <h2>Who it's for</h2>
      <p class="kap-sub">Traditional-institution leaders, cultural ambassadors, and heritage advocates — anyone who wants to lead from a foundation that lasts, where character precedes competence and conviction precedes calling.</p>
    </section>

    <section class="kap-sec kap-next" aria-label="Take the next step">
      <h2>Carry it forward</h2>
      <p class="kap-sub">The foundations are only the beginning. Here is where the work continues.</p>
      <div class="kap-grid kap-grid--links">
        <a class="kap-tile kap-tile--link" href="/academy/"><h3>Learn &amp; lead →</h3><p>Free, hands-on programmes in the Afrovanguard Academy.</p></a>
        <a class="kap-tile kap-tile--link" href="/contact/"><h3>Partner with KAP →</h3><p>For palaces, cultural bodies, and heritage institutions.</p></a>
        <a class="kap-tile kap-tile--link" href="/ethos/"><h3>Read the ethos →</h3><p>The values beneath everything Afrovanguard builds.</p></a>
      </div>
    </section>
  </div>
</main>
<?php render_footer();
