<?php
/**
 * academy/ngv/index.php — NextGen Vanguard programme landing page.
 *
 * Afrovanguard Academy's flagship transformation programme. Every word,
 * section, track, phase, plan (each with its own duration + fee), testimonial,
 * FAQ and office is read from lib/Ngv.php (DB-backed via app_meta) — nothing
 * here is hard-coded, and an admin edits it all at /academy/ngv/edit.php.
 * Lives in a real directory so the Academy's course catch-all serves it
 * directly at /academy/ngv/.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

Sitemap::ensureFresh();

$c       = Ngv::get();
$canon   = rtrim(SITE_URL, '/') . '/academy/ngv/';
$isAdmin = function_exists('av_admin_role') && av_admin_role() !== '';
$ngvMember = class_exists('LmsAuth') ? LmsAuth::user() : null;
$ngvFirst  = $ngvMember ? (trim(explode(' ', trim((string) ($ngvMember['name'] ?? '')))[0]) ?: 'Vanguard') : '';
$g = static fn(array $a, string $k, string $d = ''): string => (string) ($a[$k] ?? $d);

// ── SEO / structured data ────────────────────────────────────────────────
$seo     = $c['seo'] ?? [];
$offices = $c['offices'] ?? [];
$ct      = $c['contact'] ?? [];
$ogImage = $seo['og_image'] ?? '/assets/site/ngv/ngv-flyer.png';
if ($ogImage !== '' && $ogImage[0] === '/') $ogImage = rtrim(SITE_URL, '/') . $ogImage;

$crumbs = schema_breadcrumb([
    ['name' => 'Home', 'url' => rtrim(SITE_URL, '/') . '/'],
    ['name' => 'Academy', 'url' => rtrim(SITE_URL, '/') . '/academy/'],
    ['name' => 'NextGen Vanguard', 'url' => $canon],
]);
$courseLocations = [];
foreach ($offices as $o) {
    if (empty($o['name'])) continue;
    $courseLocations[] = ['@type' => 'Place', 'name' => (string) $o['name'],
        'address' => (string) ($o['address'] ?? '')];
}
$courseSchema = [
    '@type' => 'Course', 'name' => 'NextGen Vanguard', 'description' => $g($seo, 'desc'),
    'provider' => ['@type' => 'Organization', 'name' => 'Afrovanguard Academy', '@id' => rtrim(SITE_URL, '/') . '/#organization'],
    'url' => $canon, 'educationalCredentialAwarded' => 'Six global certifications',
    'hasCourseInstance' => ['@type' => 'CourseInstance', 'courseMode' => 'onsite',
        'location' => $courseLocations ?: ['@type' => 'Place', 'name' => 'CACENTRE, Lagos']],
];
$jsonld = [schema_org(), $courseSchema, $crumbs];
if (Ngv::section('faq') && !empty($c['faq'])) {
    $q = [];
    foreach ($c['faq'] as $f) { if (empty($f['q'])) continue;
        $q[] = ['@type' => 'Question', 'name' => (string) $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string) ($f['a'] ?? '')]]; }
    if ($q) $jsonld[] = ['@type' => 'FAQPage', 'mainEntity' => $q];
}

render_head([
    'title'     => $g($seo, 'title', 'NextGen Vanguard — Afrovanguard Academy'),
    'desc'      => $g($seo, 'desc'),
    'canonical' => $canon, 'og_kind' => 'website',
    'image'     => $ogImage, 'image_alt' => 'NextGen Vanguard — Afrovanguard Academy',
    'keywords'  => $g($seo, 'keywords'),
    'jsonld'    => $jsonld,
    'css'       => ['/academy/academy.css', '/academy/ngv.css'],
    'body_class'=> 'academy ngv', 'slug' => 'academy-ngv',
]);
render_nav('academy');

$hero = $c['hero'] ?? [];
?>
<main id="main-content">

<?php if ($isAdmin): ?>
  <div class="ngv-adminbar"><div class="ngv-wrap">
    <span>✏️ <b>Admin</b> — every part of this page is editable.</span>
    <a href="/academy/ngv/edit.php">Edit page →</a>
    <a href="/academy/ngv/members.php">Vanguards &amp; applications →</a>
    <a href="/academy/ngv/dashboard.php">Member dashboard →</a>
    <span class="tag"><?= Ngv::isEnabled() ? 'Published' : 'Hidden (draft)' ?></span>
  </div></div>
<?php elseif ($ngvMember): ?>
  <div class="ngv-adminbar"><div class="ngv-wrap">
    <span>👋 Signed in as <b><?= e($ngvFirst) ?></b></span>
    <a href="/academy/ngv/dashboard.php">Go to my dashboard →</a>
  </div></div>
<?php endif; ?>

<?php if (!Ngv::isEnabled() && !$isAdmin): ?>
  <section class="ngv-hero"><div class="ngv-wrap ngv-hero-inner">
    <span class="ngv-pill"><span class="dot"></span> NextGen Vanguard</span>
    <h1>Something big is <span class="ngv-grad-text">on the way.</span></h1>
    <p class="ngv-hero-sub">Our next cohort is being prepared. Register your interest to be first to know.</p>
    <div class="ngv-hero-cta"><a class="ngv-btn ngv-btn-primary ngv-btn-lg" href="<?= e($g($ct, 'apply_url', '/academy/ngv/register.php')) ?>">Register your interest</a></div>
  </div></section>
<?php else: ?>

  <!-- Hero -->
  <section class="ngv-hero">
    <div class="ngv-wrap ngv-hero-inner">
<?php if ($g($hero, 'promo_tagline')): ?>      <span class="ngv-pill"><span class="dot"></span> <?= e($g($hero, 'promo_tagline')) ?></span>
<?php endif; ?>
      <p class="ngv-hero-eyebrow" style="margin-top:18px"><?= e($g($hero, 'eyebrow')) ?></p>
      <h1><?= e($g($hero, 'title_top')) ?> <span class="ngv-grad-text"><?= e($g($hero, 'title_bottom')) ?></span></h1>
      <p class="ngv-hero-sub"><?= e($g($hero, 'sub')) ?></p>
      <div class="ngv-hero-cta">
<?php if ($g($hero, 'cta_primary_url')): ?>        <a class="ngv-btn ngv-btn-primary ngv-btn-lg" href="<?= e($g($hero, 'cta_primary_url')) ?>" rel="noopener"><?= e($g($hero, 'cta_primary_label', 'Apply now')) ?></a>
<?php endif; ?>
<?php if ($g($hero, 'cta_secondary_url')): ?>        <a class="ngv-btn ngv-btn-ghost ngv-btn-lg" href="<?= e($g($hero, 'cta_secondary_url')) ?>" rel="noopener"><?= e($g($hero, 'cta_secondary_label')) ?></a>
<?php endif; ?>
      </div>
<?php if (!empty($c['perks'])): ?>
      <div class="ngv-perks">
<?php foreach ($c['perks'] as $p): ?>        <div class="ngv-perk"><b><?= e((string) ($p['num'] ?? '')) ?></b><span><?= e((string) ($p['label'] ?? '')) ?></span></div>
<?php endforeach; ?>
      </div>
<?php endif; ?>
    </div>
  </section>

  <!-- Skills strip -->
<?php if (!empty($c['marquee'])): $sk = $c['marquee']; ?>
  <div class="ngv-skills" aria-label="What you'll learn">
    <div class="ngv-skills-track">
      <span><?= implode('</span><span>', array_map('e', $sk)) ?></span>
      <span aria-hidden="true"><?= implode('</span><span>', array_map('e', $sk)) ?></span>
    </div>
  </div>
<?php endif; ?>

  <!-- About -->
<?php $ab = $c['about'] ?? []; if ($g($ab, 'title') || $g($ab, 'body')): ?>
  <section class="ngv-section" id="about">
    <div class="ngv-wrap ngv-about">
      <div>
        <span class="ngv-eyebrow">About the programme</span>
        <h2 class="ngv-h2"><?= e($g($ab, 'title')) ?></h2>
        <p class="ngv-lead" style="margin-bottom:16px"><?= e($g($ab, 'body')) ?></p>
        <p class="ngv-lead"><?= e($g($ab, 'body2')) ?></p>
      </div>
<?php if (!empty($c['stats'])): ?>
      <div class="ngv-stats">
<?php foreach ($c['stats'] as $s): ?>        <div class="ngv-stat"><b><?= e((string) ($s['num'] ?? '')) ?></b><span><?= e((string) ($s['label'] ?? '')) ?></span></div>
<?php endforeach; ?>
      </div>
<?php endif; ?>
    </div>
  </section>
<?php endif; ?>

  <!-- Tracks -->
<?php if (Ngv::section('tracks') && !empty($c['tracks'])): ?>
  <section class="ngv-section ngv-section--alt" id="tracks">
    <div class="ngv-wrap">
      <div class="ngv-head">
        <span class="ngv-eyebrow">Passion-aligned learning</span>
        <h2 class="ngv-h2"><?= e($g($c, 'tracks_title', 'Choose your track')) ?></h2>
        <p class="ngv-lead"><?= e($g($c, 'tracks_intro')) ?></p>
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

  <!-- Phases -->
<?php if (Ngv::section('phases') && !empty($c['phases'])): ?>
  <section class="ngv-section" id="journey">
    <div class="ngv-wrap">
      <div class="ngv-head">
        <span class="ngv-eyebrow">The journey</span>
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

  <!-- Plans -->
<?php $plans = Ngv::activePlans(); if (Ngv::section('plans') && $plans): ?>
  <section class="ngv-section ngv-section--alt" id="plans">
    <div class="ngv-wrap">
      <div class="ngv-head">
        <span class="ngv-eyebrow">Programme options</span>
        <h2 class="ngv-h2"><?= e($g($c, 'plans_title', 'Choose your plan')) ?></h2>
        <p class="ngv-lead"><?= e($g($c, 'plans_intro')) ?></p>
      </div>
      <div class="ngv-plans">
<?php foreach ($plans as $p): $feat = !empty($p['featured']); ?>
        <article class="ngv-plan<?= $feat ? ' ngv-plan--featured' : '' ?>">
<?php if ($feat): ?>          <span class="ngv-plan-flag">Most popular</span>
<?php endif; ?>
          <div class="ngv-plan-top">
            <span class="ngv-plan-name"><?= e((string) ($p['name'] ?? '')) ?></span>
<?php if (!empty($p['duration'])): ?>            <span class="ngv-plan-dur"><?= e((string) $p['duration']) ?></span>
<?php endif; ?>
          </div>
          <div class="ngv-plan-price"><?= e((string) ($p['price'] ?? '')) ?><?php if (!empty($p['price_note'])): ?><small><?= e((string) $p['price_note']) ?></small><?php endif; ?></div>
          <p class="ngv-plan-desc"><?= e((string) ($p['desc'] ?? '')) ?></p>
<?php if (!empty($p['features'])): ?>
          <ul class="ngv-plan-feats">
<?php foreach ((array) $p['features'] as $f): ?>            <li><?= e((string) $f) ?></li>
<?php endforeach; ?>
          </ul>
<?php endif; ?>
<?php if (!empty($p['cta_url'])): ?>          <a class="ngv-btn <?= $feat ? 'ngv-btn-primary' : 'ngv-btn-dark' ?>" href="<?= e((string) $p['cta_url']) ?>" rel="noopener"><?= e((string) ($p['cta_label'] ?? 'Apply')) ?></a>
<?php endif; ?>
        </article>
<?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

  <!-- Why choose us -->
<?php if (Ngv::section('why') && !empty($c['why'])): ?>
  <section class="ngv-section" id="why">
    <div class="ngv-wrap">
      <div class="ngv-head">
        <span class="ngv-eyebrow">The Vanguard difference</span>
        <h2 class="ngv-h2"><?= e($g($c, 'why_title', 'Why choose us')) ?></h2>
      </div>
      <ul class="ngv-ticks">
<?php foreach ($c['why'] as $w): ?>        <li><?= e((string) $w) ?></li>
<?php endforeach; ?>
      </ul>
    </div>
  </section>
<?php endif; ?>

  <!-- Testimonials -->
<?php if (Ngv::section('testimonials') && !empty($c['testimonials'])): ?>
  <section class="ngv-section ngv-section--alt" id="testimonials">
    <div class="ngv-wrap">
      <div class="ngv-head">
        <span class="ngv-eyebrow">Members testimonial</span>
        <h2 class="ngv-h2"><?= e($g($c, 'testimonials_title', 'What they say')) ?></h2>
      </div>
      <div class="ngv-tests">
<?php foreach ($c['testimonials'] as $tm): $nm = (string) ($tm['name'] ?? ''); $rt = (float) ($tm['rating'] ?? 0); ?>
        <figure class="ngv-test">
<?php if ($rt > 0): $full = (int) floor($rt); ?>          <div class="ngv-test-stars" aria-label="<?= e((string) $tm['rating']) ?> out of 5"><?= str_repeat('★', max(1, min(5, $full))) ?> <span style="color:var(--ngv-muted);font-weight:600"><?= e((string) $tm['rating']) ?></span></div>
<?php endif; ?>
          <blockquote class="ngv-test-quote"><?= e((string) ($tm['quote'] ?? '')) ?></blockquote>
          <figcaption class="ngv-test-by">
            <span class="ngv-test-avatar"><?= e(mb_strtoupper(mb_substr($nm, 0, 1))) ?></span>
            <span><span class="ngv-test-name"><?= e($nm) ?></span><br><span class="ngv-test-role"><?= e((string) ($tm['role'] ?? '')) ?></span></span>
          </figcaption>
        </figure>
<?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

  <!-- Fees + schedule -->
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
          <p class="ngv-lead" style="margin-top:8px;font-size:.96rem"><?= e((string) ($fee['desc'] ?? '')) ?></p>
        </div>
<?php endforeach; ?>
      </div>
<?php if ($g($c, 'fees_note')): ?>      <p class="ngv-note"><?= e($g($c, 'fees_note')) ?></p>
<?php endif; ?>
<?php if ($sch): ?>
      <div class="ngv-sched">
<?php foreach (['days'=>'Attendance','time'=>'Daily schedule','uniform'=>'Dress code','payment'=>'Payments'] as $key=>$lbl): if ($g($sch,$key)): ?>
        <div class="ngv-card"><b><?= e($lbl) ?></b><span><?= e($g($sch, $key)) ?></span></div>
<?php endif; endforeach; ?>
      </div>
<?php endif; ?>
    </div>
  </section>
<?php endif; ?>

  <!-- FAQ -->
<?php if (Ngv::section('faq') && !empty($c['faq'])): ?>
  <section class="ngv-section ngv-section--alt" id="faq">
    <div class="ngv-wrap">
      <div class="ngv-head">
        <span class="ngv-eyebrow">FAQ</span>
        <h2 class="ngv-h2"><?= e($g($c, 'faq_title', 'Questions, answered')) ?></h2>
      </div>
      <div class="ngv-faq">
<?php foreach ($c['faq'] as $f): if (empty($f['q'])) continue; ?>
        <details><summary><?= e((string) $f['q']) ?></summary><p><?= e((string) ($f['a'] ?? '')) ?></p></details>
<?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

  <!-- Final CTA -->
<?php $cta = $c['cta'] ?? []; ?>
  <section class="ngv-section" id="apply">
    <div class="ngv-wrap">
      <div class="ngv-cta">
        <h2><?= e($g($cta, 'title', 'Your future is waiting')) ?></h2>
        <p><?= e($g($cta, 'text')) ?></p>
        <div class="ngv-hero-cta">
<?php if ($g($cta, 'button_url')): ?>          <a class="ngv-btn ngv-btn-dark ngv-btn-lg" style="background:#14100c;color:#fff;border:0" href="<?= e($g($cta, 'button_url')) ?>" rel="noopener"><?= e($g($cta, 'button_label', 'Apply now')) ?></a>
<?php endif; ?>
<?php if ($g($ct, 'phone')): ?>          <a class="ngv-btn ngv-btn-ghost ngv-btn-lg" style="color:#fff;border-color:rgba(255,255,255,.6)" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $g($ct, 'phone'))) ?>">Call <?= e($g($ct, 'phone')) ?></a>
<?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- Contact + offices -->
  <section class="ngv-section ngv-section--alt" id="contact" style="padding-block:clamp(40px,5vw,64px)">
    <div class="ngv-wrap">
      <div class="ngv-head" style="margin-bottom:28px">
        <span class="ngv-eyebrow">Visit or reach us</span>
        <h2 class="ngv-h2">Come and see us</h2>
      </div>
      <div class="ngv-contact">
<?php foreach ($offices as $o): if (empty($o['name'])) continue; ?>
        <div class="ngv-card"><b>Office</b><div style="font-weight:700;margin-bottom:4px"><?= e((string) $o['name']) ?></div><div class="ngv-office-addr"><?= e((string) ($o['address'] ?? '')) ?></div></div>
<?php endforeach; ?>
<?php if ($g($ct, 'phone')): ?>        <div class="ngv-card"><b>Call / WhatsApp</b><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $g($ct, 'phone'))) ?>"><?= e($g($ct, 'phone')) ?></a></div>
<?php endif; ?>
<?php if ($g($ct, 'email')): ?>        <div class="ngv-card"><b>Email</b><a href="mailto:<?= e($g($ct, 'email')) ?>"><?= e($g($ct, 'email')) ?></a></div>
<?php endif; ?>
      </div>
    </div>
  </section>

<?php endif; /* enabled */ ?>
</main>
<?php render_footer();
