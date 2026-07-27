<?php
/**
 * academy/ngv/index.php — NextGen Vanguard programme landing page.
 *
 * Afrovanguard Academy's flagship transformation programme. Every word,
 * section, track, phase, plan, testimonial and FAQ is read from lib/Ngv.php
 * (DB-backed via app_meta) — nothing here is hard-coded, and an admin edits it
 * all in the Studio (see /academy/ngv/edit.php). Lives in a real directory so
 * the Academy's course catch-all rewrite serves it directly at /academy/ngv/.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

Sitemap::ensureFresh();

$c        = Ngv::get();
$canonical= rtrim(SITE_URL, '/') . '/academy/ngv/';
$isAdmin  = function_exists('av_admin_role') && av_admin_role() !== '';

// A tiny, safe accessor for nested content with an HTML-escaped default.
$g  = static fn(array $a, string $k, string $d = ''): string => (string) ($a[$k] ?? $d);

// ── SEO / structured data ────────────────────────────────────────────────
$seo     = $c['seo'] ?? [];
$ogImage = $seo['og_image'] ?? '/assets/site/ngv/ngv-flyer.png';
if ($ogImage !== '' && $ogImage[0] === '/') $ogImage = rtrim(SITE_URL, '/') . $ogImage;

$crumbs = schema_breadcrumb([
    ['name' => 'Home', 'url' => rtrim(SITE_URL, '/') . '/'],
    ['name' => 'Academy', 'url' => rtrim(SITE_URL, '/') . '/academy/'],
    ['name' => 'NextGen Vanguard', 'url' => $canonical],
]);

$courseSchema = [
    '@type'       => 'Course',
    'name'        => 'NextGen Vanguard',
    'description' => $g($seo, 'desc'),
    'provider'    => ['@type' => 'Organization', 'name' => 'Afrovanguard Academy', '@id' => rtrim(SITE_URL, '/') . '/#organization'],
    'url'         => $canonical,
    'educationalCredentialAwarded' => 'Six global certifications',
    'hasCourseInstance' => [
        '@type'        => 'CourseInstance',
        'courseMode'   => 'onsite',
        'courseWorkload' => 'P12M',
        'location'     => ['@type' => 'Place', 'name' => 'CACENTRE Egbeda', 'address' => $g($c['contact'] ?? [], 'address')],
    ],
];
$jsonld = [schema_org(), $courseSchema, $crumbs];

// FAQPage schema (rich results) — only when the FAQ section is on and populated.
if (Ngv::section('faq') && !empty($c['faq'])) {
    $faqEntities = [];
    foreach ($c['faq'] as $f) {
        if (empty($f['q'])) continue;
        $faqEntities[] = ['@type' => 'Question', 'name' => (string) $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string) ($f['a'] ?? '')]];
    }
    if ($faqEntities) $jsonld[] = ['@type' => 'FAQPage', 'mainEntity' => $faqEntities];
}

render_head([
    'title'     => $g($seo, 'title', 'NextGen Vanguard — Afrovanguard Academy'),
    'desc'      => $g($seo, 'desc'),
    'canonical' => $canonical,
    'og_kind'   => 'website',
    'image'     => $ogImage,
    'image_alt' => 'NextGen Vanguard — Stop scrolling, start earning',
    'keywords'  => $g($seo, 'keywords'),
    'jsonld'    => $jsonld,
    'css'       => ['/academy/academy.css', '/academy/ngv.css'],
    'body_class'=> 'academy ngv',
    'slug'      => 'academy-ngv',
]);
render_nav('academy');

$hero = $c['hero'] ?? [];
$ct   = $c['contact'] ?? [];
?>
<main id="main-content">

<?php if ($isAdmin): ?>
  <div class="ngv-adminbar">
    <div class="ngv-wrap">
      <span>✏️ <b>Admin</b> — this whole page is editable.</span>
      <a href="/academy/ngv/edit.php">Edit NextGen Vanguard →</a>
      <span class="ngv-pill"><?= Ngv::isEnabled() ? 'Published' : 'Hidden (draft)' ?></span>
    </div>
  </div>
<?php endif; ?>

<?php if (!Ngv::isEnabled() && !$isAdmin): ?>
  <section class="ngv-hero"><div class="ngv-wrap ngv-hero-inner"><div>
    <span class="ngv-hero-badge">NextGen Vanguard</span>
    <h1>Something big is<span>on the way.</span></h1>
    <p class="ngv-hero-sub">Our next cohort is being prepared. Join the movement to be the first to know.</p>
    <div class="ngv-hero-cta">
      <a class="ngv-btn ngv-btn-gold ngv-btn-lg" href="<?= e($g($ct, 'apply_url', 'https://bit.ly/ngv')) ?>">Register your interest</a>
    </div>
  </div></div></section>
<?php else: ?>

  <!-- ── Hero ──────────────────────────────────────────────────────── -->
  <section class="ngv-hero">
    <div class="ngv-wrap ngv-hero-inner">
      <div>
        <span class="ngv-hero-badge">→ <?= e($g($hero, 'audience')) ?></span>
        <p class="ngv-eyebrow" style="color:rgba(255,255,255,.85)"><?= e($g($hero, 'eyebrow')) ?></p>
        <h1><?= e($g($hero, 'title_top')) ?><span><?= e($g($hero, 'title_bottom')) ?></span></h1>
        <p class="ngv-hero-sub"><?= e($g($hero, 'sub')) ?></p>
        <div class="ngv-hero-cta">
<?php if ($g($hero, 'cta_primary_url')): ?>          <a class="ngv-btn ngv-btn-gold ngv-btn-lg" href="<?= e($g($hero, 'cta_primary_url')) ?>" rel="noopener"><?= e($g($hero, 'cta_primary_label', 'Apply now')) ?></a>
<?php endif; ?>
<?php if ($g($hero, 'cta_secondary_url')): ?>          <a class="ngv-btn ngv-btn-ghost ngv-btn-lg" href="<?= e($g($hero, 'cta_secondary_url')) ?>" rel="noopener"><?= e($g($hero, 'cta_secondary_label')) ?></a>
<?php endif; ?>
        </div>
      </div>
<?php if (!empty($c['perks'])): ?>
      <aside class="ngv-hero-card">
        <h3>Why join us</h3>
        <ul class="ngv-perks">
<?php foreach ($c['perks'] as $p): ?>          <li><b><?= e((string) ($p['num'] ?? '')) ?></b><?= e((string) ($p['label'] ?? '')) ?></li>
<?php endforeach; ?>
        </ul>
      </aside>
<?php endif; ?>
    </div>
  </section>

  <!-- ── Skills marquee ────────────────────────────────────────────── -->
<?php if (!empty($c['marquee'])): $sk = $c['marquee']; ?>
  <div class="ngv-marquee" aria-label="What you'll learn and earn">
    <div class="ngv-marquee-track">
      <span><?= implode('</span><span>', array_map('e', $sk)) ?></span>
      <span aria-hidden="true"><?= implode('</span><span>', array_map('e', $sk)) ?></span>
    </div>
  </div>
<?php endif; ?>

  <!-- ── About ─────────────────────────────────────────────────────── -->
<?php $ab = $c['about'] ?? []; if ($g($ab, 'title') || $g($ab, 'body')): ?>
  <section class="ngv-section" id="about">
    <div class="ngv-wrap">
      <div class="ngv-head">
        <span class="ngv-eyebrow">About the programme</span>
        <h2 class="ngv-h2"><?= e($g($ab, 'title')) ?></h2>
      </div>
      <div class="ngv-grid ngv-grid-2">
        <p class="ngv-lead"><?= e($g($ab, 'body')) ?></p>
        <p class="ngv-lead"><?= e($g($ab, 'body2')) ?></p>
      </div>
<?php if (!empty($c['stats'])): ?>
      <div class="ngv-stats" style="margin-top:clamp(28px,4vw,44px)">
<?php foreach ($c['stats'] as $s): ?>        <div class="ngv-stat"><b><?= e((string) ($s['num'] ?? '')) ?></b><span><?= e((string) ($s['label'] ?? '')) ?></span></div>
<?php endforeach; ?>
      </div>
<?php endif; ?>
    </div>
  </section>
<?php endif; ?>

  <!-- ── Tracks ────────────────────────────────────────────────────── -->
<?php if (Ngv::section('tracks') && !empty($c['tracks'])): ?>
  <section class="ngv-section ngv-section--tight" id="tracks" style="background:var(--ngv-card-2)">
    <div class="ngv-wrap">
      <div class="ngv-head">
        <span class="ngv-eyebrow">Passion-aligned learning</span>
        <h2 class="ngv-h2"><?= e($g($c, 'tracks_title', 'Choose your track')) ?></h2>
        <p class="ngv-lead ngv-center"><?= e($g($c, 'tracks_intro')) ?></p>
      </div>
      <div class="ngv-grid ngv-grid-4">
<?php foreach ($c['tracks'] as $t): ?>
        <article class="ngv-card">
          <div class="ngv-ico"><?= e((string) ($t['icon'] ?? '★')) ?></div>
          <h3><?= e((string) ($t['name'] ?? '')) ?></h3>
          <p><?= e((string) ($t['desc'] ?? '')) ?></p>
        </article>
<?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

  <!-- ── Phases ────────────────────────────────────────────────────── -->
<?php if (Ngv::section('phases') && !empty($c['phases'])): ?>
  <section class="ngv-section" id="journey">
    <div class="ngv-wrap">
      <div class="ngv-head">
        <span class="ngv-eyebrow">The 12-month journey</span>
        <h2 class="ngv-h2"><?= e($g($c, 'phases_title', 'How the journey works')) ?></h2>
      </div>
      <div class="ngv-phases">
<?php foreach ($c['phases'] as $ph): ?>
        <article class="ngv-phase">
          <span class="ngv-phase-tag"><?= e((string) ($ph['tag'] ?? '')) ?></span>
          <h3><?= e((string) ($ph['title'] ?? '')) ?></h3>
          <span class="ngv-when"><?= e((string) ($ph['when'] ?? '')) ?></span>
          <ul>
<?php foreach ((array) ($ph['items'] ?? []) as $it): ?>            <li><?= e((string) $it) ?></li>
<?php endforeach; ?>
          </ul>
        </article>
<?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

  <!-- ── Plans ─────────────────────────────────────────────────────── -->
<?php $plans = Ngv::activePlans(); if (Ngv::section('plans') && $plans): ?>
  <section class="ngv-section" id="plans" style="background:var(--ngv-card-2)">
    <div class="ngv-wrap">
      <div class="ngv-head">
        <span class="ngv-eyebrow">Programme options</span>
        <h2 class="ngv-h2"><?= e($g($c, 'plans_title', 'Programme options')) ?></h2>
        <p class="ngv-lead ngv-center"><?= e($g($c, 'plans_intro')) ?></p>
      </div>
      <div class="ngv-plans">
<?php foreach ($plans as $p): $feat = !empty($p['featured']); ?>
        <article class="ngv-plan<?= $feat ? ' ngv-plan--featured' : '' ?>">
<?php if ($feat): ?>          <span class="ngv-plan-flag">Most popular</span>
<?php endif; ?>
          <div class="ngv-plan-name"><?= e((string) ($p['name'] ?? '')) ?></div>
          <div class="ngv-plan-price"><?= e((string) ($p['price'] ?? '')) ?><?php if (!empty($p['price_note'])): ?><small><?= e((string) $p['price_note']) ?></small><?php endif; ?></div>
          <p class="ngv-plan-desc"><?= e((string) ($p['desc'] ?? '')) ?></p>
<?php if (!empty($p['features'])): ?>
          <ul class="ngv-plan-feats">
<?php foreach ((array) $p['features'] as $f): ?>            <li><?= e((string) $f) ?></li>
<?php endforeach; ?>
          </ul>
<?php endif; ?>
<?php if (!empty($p['cta_url'])): ?>          <a class="ngv-btn <?= $feat ? 'ngv-btn-gold' : 'ngv-btn-dark' ?>" href="<?= e((string) $p['cta_url']) ?>" rel="noopener"><?= e((string) ($p['cta_label'] ?? 'Apply')) ?></a>
<?php endif; ?>
        </article>
<?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

  <!-- ── Why choose us ─────────────────────────────────────────────── -->
<?php if (Ngv::section('why') && !empty($c['why'])): ?>
  <section class="ngv-section" id="why">
    <div class="ngv-wrap">
      <div class="ngv-head">
        <span class="ngv-eyebrow">The Vanguard difference</span>
        <h2 class="ngv-h2"><?= e($g($c, 'why_title', 'Why choose us')) ?></h2>
      </div>
      <ul class="ngv-ticks" style="max-width:760px;margin-inline:auto">
<?php foreach ($c['why'] as $w): ?>        <li><?= e((string) $w) ?></li>
<?php endforeach; ?>
      </ul>
    </div>
  </section>
<?php endif; ?>

  <!-- ── Testimonials ──────────────────────────────────────────────── -->
<?php if (Ngv::section('testimonials') && !empty($c['testimonials'])): ?>
  <section class="ngv-section ngv-section--tight" id="testimonials" style="background:var(--ngv-card-2)">
    <div class="ngv-wrap">
      <div class="ngv-head">
        <span class="ngv-eyebrow">Members testimonial</span>
        <h2 class="ngv-h2"><?= e($g($c, 'testimonials_title', 'What they say')) ?></h2>
      </div>
      <div class="ngv-tests">
<?php foreach ($c['testimonials'] as $tm): $nm = (string) ($tm['name'] ?? ''); ?>
        <figure class="ngv-test">
          <blockquote class="ngv-test-quote"><?= e((string) ($tm['quote'] ?? '')) ?></blockquote>
          <figcaption class="ngv-test-by">
            <span class="ngv-test-avatar"><?= e(mb_strtoupper(mb_substr($nm, 0, 1))) ?></span>
            <span>
              <span class="ngv-test-name"><?= e($nm) ?></span><br>
              <span class="ngv-test-role"><?= e((string) ($tm['role'] ?? '')) ?></span>
            </span>
<?php if (!empty($tm['rating'])): ?>            <span class="ngv-stars">★ <?= e((string) $tm['rating']) ?></span>
<?php endif; ?>
          </figcaption>
        </figure>
<?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

  <!-- ── Fees & schedule ───────────────────────────────────────────── -->
<?php if (Ngv::section('fees') && !empty($c['fees'])): $sch = $c['schedule'] ?? []; ?>
  <section class="ngv-section" id="fees">
    <div class="ngv-wrap">
      <div class="ngv-head">
        <span class="ngv-eyebrow">Commitment, not cost</span>
        <h2 class="ngv-h2"><?= e($g($c, 'fees_title', 'Simple, purposeful commitment')) ?></h2>
      </div>
      <div class="ngv-fees">
<?php foreach ($c['fees'] as $fee): ?>
        <div class="ngv-fee">
          <h3><?= e((string) ($fee['name'] ?? '')) ?></h3>
          <div class="ngv-fee-amt"><?= e((string) ($fee['amount'] ?? '')) ?></div>
          <p class="ngv-lead" style="margin-top:8px"><?= e((string) ($fee['desc'] ?? '')) ?></p>
        </div>
<?php endforeach; ?>
      </div>
<?php if ($g($c, 'fees_note')): ?>      <p class="ngv-note"><?= e($g($c, 'fees_note')) ?></p>
<?php endif; ?>
<?php if ($sch): ?>
      <div class="ngv-grid ngv-grid-4" style="margin-top:22px">
<?php foreach (['days'=>'Attendance','time'=>'Daily schedule','uniform'=>'Dress code','payment'=>'Payments'] as $key=>$lbl): if ($g($sch,$key)): ?>
        <div class="ngv-card"><div class="ngv-ico">•</div><h3 style="font-size:.95rem"><?= e($lbl) ?></h3><p><?= e($g($sch, $key)) ?></p></div>
<?php endif; endforeach; ?>
      </div>
<?php endif; ?>
    </div>
  </section>
<?php endif; ?>

  <!-- ── FAQ ───────────────────────────────────────────────────────── -->
<?php if (Ngv::section('faq') && !empty($c['faq'])): ?>
  <section class="ngv-section ngv-section--tight" id="faq" style="background:var(--ngv-card-2)">
    <div class="ngv-wrap" style="max-width:820px">
      <div class="ngv-head">
        <span class="ngv-eyebrow">FAQ</span>
        <h2 class="ngv-h2"><?= e($g($c, 'faq_title', 'Questions, answered')) ?></h2>
      </div>
      <div class="ngv-grid" style="gap:12px">
<?php foreach ($c['faq'] as $f): if (empty($f['q'])) continue; ?>
        <details class="ngv-card"><summary style="cursor:pointer;font-weight:800;color:var(--ngv-text)"><?= e((string) $f['q']) ?></summary><p style="margin-top:10px"><?= e((string) ($f['a'] ?? '')) ?></p></details>
<?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

  <!-- ── Final CTA ─────────────────────────────────────────────────── -->
<?php $cta = $c['cta'] ?? []; ?>
  <section class="ngv-section" id="apply">
    <div class="ngv-wrap">
      <div class="ngv-cta">
        <h2><?= e($g($cta, 'title', 'Your future is waiting')) ?></h2>
        <p><?= e($g($cta, 'text')) ?></p>
        <div class="ngv-hero-cta">
<?php if ($g($cta, 'button_url')): ?>          <a class="ngv-btn ngv-btn-gold ngv-btn-lg" href="<?= e($g($cta, 'button_url')) ?>" rel="noopener"><?= e($g($cta, 'button_label', 'Apply now')) ?></a>
<?php endif; ?>
<?php if ($g($ct, 'phone')): ?>          <a class="ngv-btn ngv-btn-ghost ngv-btn-lg" style="color:#fff" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $g($ct, 'phone'))) ?>">Call <?= e($g($ct, 'phone')) ?></a>
<?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- ── Contact ───────────────────────────────────────────────────── -->
  <section class="ngv-section ngv-section--tight" id="contact">
    <div class="ngv-wrap">
      <div class="ngv-contact">
<?php if ($g($ct, 'phone')): ?>        <div class="ngv-card"><b>Call / WhatsApp</b><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $g($ct, 'phone'))) ?>"><?= e($g($ct, 'phone')) ?></a></div>
<?php endif; ?>
<?php if ($g($ct, 'email')): ?>        <div class="ngv-card"><b>Email</b><a href="mailto:<?= e($g($ct, 'email')) ?>"><?= e($g($ct, 'email')) ?></a></div>
<?php endif; ?>
<?php if ($g($ct, 'address')): ?>        <div class="ngv-card"><b>Visit us</b><span><?= e($g($ct, 'address')) ?></span></div>
<?php endif; ?>
      </div>
    </div>
  </section>

<?php endif; /* enabled */ ?>
</main>
<?php render_footer();
