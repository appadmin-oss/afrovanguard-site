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

$preamble = 'Afrovanguard is a values-driven civic, cultural, leadership, and human-development movement committed to advancing human flourishing, ethical leadership, cultural dignity, accountable governance, sustainable development, and collective prosperity.';

$commitments = [
  ['Human Dignity & the Common Good', 'We exist for the good of humanity.', 'Afrovanguard affirms the inherent worth of every human being — societies flourish when individual well-being is bound to the well-being of communities. Genuine development is measured not by economic growth alone, but by human flourishing, social cohesion, justice, and quality of life.', ['Human-centered development','Equal opportunity','Intergenerational responsibility','Social inclusion','Community empowerment','Protection of the vulnerable'], 'For an Afrovanguard, success is meaningful only when it advances others.'],
  ['Selfless Service & Social Impact', 'We are radically selfless.', 'Afrovanguard promotes servant leadership as the highest expression of influence: leadership is a responsibility to serve, not an opportunity to dominate. Members dedicate their knowledge, resources, influence, skills, and networks to solving societal challenges.', ['Service precedes status','Contribution over consumption','Impact outweighs recognition','Legacy over popularity'], 'Service is not charity alone but a strategic investment in human development and social progress.'],
  ['Sustainable Community Prosperity', 'We prosper communities.', 'Prosperity is measured not by wealth accumulation but by the capacity of communities to thrive economically, socially, culturally, and environmentally.', ['Economic empowerment','Educational advancement','Community resilience','Sustainable livelihoods','Entrepreneurship development','Skills & youth empowerment','Innovation ecosystems'], 'We support models that create long-term value over short-term gains, and reject systems that enrich a few while impoverishing the majority.'],
  ['Integrity, Accountability & Anti-Corruption', 'We wage war against corruption.', 'Corruption is among the greatest barriers to development, social trust, and institutional effectiveness — so Afrovanguard maintains zero tolerance for it in all forms.', ['Financial misconduct','Abuse of authority','Nepotism','Bribery & fraud','Misappropriation','Manipulation of systems','Exploitation of trust','Ethical negligence'], 'Every member upholds transparency, accountable governance, and fiduciary responsibility — trained to identify, challenge, and dismantle corrupt practices wherever they arise.'],
  ['Cultural Dignity & Heritage Preservation', 'We protect our divine heritage.', 'Culture is a strategic asset for development and identity. Every civilization holds unique knowledge systems, traditions, and innovations — so we promote, protect, document, and revitalize:', ['Indigenous languages','Cultural arts','Community institutions','Traditional knowledge','Indigenous medicine','Traditional technologies','Local food systems','Historical narratives','Dignifying practices'], 'We reject cultural erasure and inferiority complexes, while embracing innovation that keeps cultures relevant — participating in the world through a confident cultural identity.'],
  ['Responsible Citizenship & Good Governance', 'We build just and responsible systems.', 'An Afrovanguard does not merely criticize broken systems — they actively participate in building better ones, grounded in the rule of law, transparency, equity, and justice.', ['Lawful conduct','Community engagement','Democratic participation','Public accountability','Policy advocacy','Ethical leadership'], 'Good governance is everyone’s responsibility, not the burden of a few.'],
  ['Peacebuilding & Social Cohesion', 'We are ambassadors of excellence.', 'Peace is built upon justice, inclusion, dialogue, and mutual respect.', ['Community mediators','Bridge-builders','Consensus facilitators','Peace advocates','Agents of reconciliation'], 'We reject violence, tribalism, and every form of division — working so that diversity becomes a source of strength rather than conflict.'],
  ['Innovation, Knowledge & Future Readiness', 'We renew through knowledge.', 'Afrovanguard embraces knowledge as a catalyst for transformation — encouraging lifelong learning, research, creativity, and innovation.', ['Educational excellence','Scientific advancement','Digital transformation','Research & development','Technology-driven solutions','Future-focused leadership'], 'Societies that invest in knowledge create sustainable pathways for prosperity and resilience.'],
  ['Environmental Stewardship', 'We are stewards of the earth.', 'Humanity bears responsibility to protect and preserve the natural environment.', ['Environmental sustainability','Climate resilience','Conservation','Responsible resource use','Sustainable agriculture','Ecological restoration'], 'Stewardship of the earth is both a moral and a civic responsibility.'],
];

$leadership = [
  ['Moral Courage', 'The willingness to defend truth and justice despite opposition.'],
  ['Ethical Competence', 'The ability to make responsible decisions rooted in values and principles.'],
  ['Cultural Intelligence', 'The ability to understand, respect, and engage diverse cultural realities.'],
  ['Social Responsibility', 'The commitment to contribute positively to society.'],
  ['Transformational Influence', 'The ability to inspire positive change in individuals and institutions.'],
  ['Stewardship', 'The responsible management of people, resources, opportunities, and trust.'],
];

$values = [
  ['Individuation', 'The pursuit of self-discovery, self-mastery, and purpose fulfilment.'],
  ['Faith', 'Confidence in God, truth, possibility, and the power of righteous action.'],
  ['Diligence', 'A consistent commitment to excellence, discipline, and productivity.'],
  ['Accountability', 'Ownership of actions, responsibilities, decisions, and outcomes.'],
  ['Responsibility', 'An active commitment to solving problems and advancing society.'],
  ['Cultural Appreciation', 'Respecting, preserving, and advancing humanity’s diverse heritage.'],
  ['Communal Spirit', 'Promoting cooperation, solidarity, collective prosperity, and cohesion.'],
];

$creed = [
  'I am an African model.',
  'I am a selfless one.',
  'I generate new ideas and solutions for my community.',
  'I defend and project Africa’s culture through my land, language, and lifestyle.',
  'I am a Force for Good and the model of our values.',
  'I help people achieve their goals daily.',
  'I put my environment into order.',
  'I take responsibility for every complacent, irresponsible, and uncultured one in my space.',
];

$jsonld = [
  schema_org(),
  ['@type' => 'Article', '@id' => $canonical . '#ethos', 'headline' => 'The Global Ethos of Afrovanguardism — The Force for Good',
   'description' => $preamble, 'image' => [$cover], 'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
   'author' => ['@id' => $S . '/#organization'], 'publisher' => ['@id' => $S . '/#organization'],
   'about' => ['ethics','leadership','culture','governance','sustainable development'],
   'associatedMedia' => ['@type' => 'MediaObject', 'contentUrl' => $pdf, 'encodingFormat' => 'application/pdf', 'name' => 'Afrovanguard Ethos (PDF)']],
  schema_breadcrumb([['name' => 'Home', 'url' => "$S/"], ['name' => 'About', 'url' => "$S/about/"], ['name' => 'Ethos', 'url' => $canonical]]),
];

render_head([
  'title' => 'The Ethos of Afrovanguardism — The Force for Good',
  'desc'  => 'The global ethos of Afrovanguardism: human dignity, selfless service, integrity, cultural heritage, good governance, peace, knowledge and environmental stewardship — nine commitments, a leadership model, seven core values and the Afrovanguard Creed.',
  'canonical' => $canonical, 'og_kind' => 'article', 'image' => $cover, 'image_alt' => 'The Ethos of Afrovanguardism',
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
      “Afrovanguard envisions a world where communities are empowered, institutions are accountable, cultures are respected, opportunities are accessible — and leadership is exercised as a sacred responsibility rather than a privilege.”
    </figure>

    <section class="ethos-sec" id="purpose">
      <h2>Vision &amp; Mission</h2>
      <div class="ethos-vm">
        <div><h3>The Vision</h3><p>To raise a generation of ethical, competent, culturally grounded, and socially responsible leaders who become a transformative force for good in every sphere of human endeavour.</p></div>
        <div><h3>The Mission</h3><p>To cultivate individuals and institutions that advance justice, accountability, cultural dignity, sustainable prosperity, community development, and responsible leadership — through education, service, innovation, advocacy, and ethical action.</p></div>
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
  </div>
</main>
<?php render_footer();
