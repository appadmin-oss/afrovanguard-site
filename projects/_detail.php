<?php
/**
 * projects/_detail.php — one template for every project page (REPLACEMENT_MAP row 6).
 *
 * Design: design/Afrovanguard Project.dc.html. Each projects/<slug>/index.php is
 *     <?php $PROJECT_SLUG = 'techhome'; require dirname(__DIR__) . '/_detail.php';
 * Content: lib/projects_content.php (via lib/avpj.php). Styles: assets/site/avpj.css.
 * Chrome: the Home nav and footer (partials/avh-chrome.php).
 *
 * Kept from v1: the SEO head (title, description, canonical, OG/Twitter,
 * CreativeWork + breadcrumb JSON-LD) and the project's own live appeals
 * (Appeals::forProject), which render only when there are any.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/avpj.php';
require_once AV_ROOT . '/partials/avh-chrome.php';
require_once AV_ROOT . '/partials/av-cover.php';

$v = avpj_view(avpj_projects(), (string) ($PROJECT_SLUG ?? ''));
if ($v === null) {
    http_response_code(404);
    if (is_file(AV_ROOT . '/404.html')) readfile(AV_ROOT . '/404.html');
    return;
}
$P    = $v['p'];
$slug = $v['slug'];

$S         = rtrim(SITE_URL, '/');
$canonical = "$S/projects/$slug/";
$fullName  = $P['name'] . ($P['abbr'] !== $P['name'] ? ' (' . $P['abbr'] . ')' : '');
$title     = $fullName . ' — Afrovanguard';
$image     = $S . $P['img'];
$jsonld = ['@context' => 'https://schema.org', '@graph' => [
    schema_org(),
    ['@type' => 'CreativeWork', '@id' => $canonical . '#project',
     'name' => $fullName, 'headline' => $P['name'], 'description' => $P['d'],
     'about' => $P['tags'], 'image' => $image,
     'isPartOf' => ['@id' => $S . '/#organization'], 'publisher' => ['@id' => $S . '/#organization']],
    schema_breadcrumb([
        ['name' => 'Home', 'url' => "$S/"],
        ['name' => 'Projects', 'url' => "$S/projects/"],
        ['name' => $P['name'], 'url' => $canonical],
    ]),
]];

$appeals = class_exists('Appeals') ? Appeals::forProject($slug, 3) : [];

if (function_exists('send_security_headers')) send_security_headers('public');
?><!DOCTYPE html>
<html lang="en-NG" prefix="og: https://ogp.me/ns#" class="no-js">
<head>
  <script>document.documentElement.classList.replace('no-js','js');if(/(^|; )av_si=1/.test(document.cookie))document.documentElement.classList.add('av-si')</script>
  <meta charset="UTF-8" />
  <link rel="icon" href="/favicon.ico" sizes="any" />
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/site/icon-192.png" />
  <link rel="apple-touch-icon" href="/assets/site/icon-192.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($P['d']) ?>" />
  <meta name="keywords" content="<?= e(implode(', ', array_merge([$P['name']], $P['abbr'] !== $P['name'] ? [$P['abbr']] : [], $P['tags'], ['Afrovanguard']))) ?>" />
  <meta name="author" content="Afrovanguard — afrovanguard.org.ng" />
  <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1" />
  <link rel="canonical" href="<?= e($canonical) ?>" />
  <meta name="theme-color" content="rgb(17,24,39)" />
  <meta property="og:type" content="website" />
  <meta property="og:site_name" content="Afrovanguard" />
  <meta property="og:locale" content="en_NG" />
  <meta property="og:url" content="<?= e($canonical) ?>" />
  <meta property="og:title" content="<?= e($title) ?>" />
  <meta property="og:description" content="<?= e($P['d']) ?>" />
  <meta property="og:image" content="<?= e($image) ?>" />
  <meta property="og:image:alt" content="<?= e($P['name'] . ' — Afrovanguard') ?>" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:site" content="@afrovanguard" />
  <meta name="twitter:title" content="<?= e($title) ?>" />
  <meta name="twitter:description" content="<?= e($P['d']) ?>" />
  <meta name="twitter:image" content="<?= e($image) ?>" />
  <meta name="twitter:image:alt" content="<?= e($P['name'] . ' — Afrovanguard') ?>" />
  <script type="application/ld+json"><?= json_encode($jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
  <link rel="stylesheet" href="/assets/site/fonts.css" />
  <link rel="preload" as="image" href="<?= e($P['img']) ?>" fetchpriority="high" />
  <link rel="stylesheet" href="/assets/site/av-tokens.css" />
  <link rel="stylesheet" href="/assets/site/avh.css" />
  <link rel="stylesheet" href="/assets/site/avcv.css" />
  <link rel="stylesheet" href="/assets/site/avpj.css" />
  <script src="/assets/site/avh.js" defer></script>
</head>
<body class="avh avpj" id="top">
<a class="avh-skip" href="#main">Skip to content</a>
<div class="avh-page">
<?php avh_nav(); ?>
<main id="main" tabindex="-1">

<header class="avpj-hero">
  <img class="avpj-hero-img" src="<?= e($P['img']) ?>" alt="<?= e($P['name']) ?>" fetchpriority="high">
  <div class="avpj-hero-shade" aria-hidden="true"></div>
  <div class="avpj-hero-in avh-pad">
    <div class="avpj-hero-copy">
      <nav class="avpj-crumbs" aria-label="Breadcrumb"><a href="/projects/">Projects</a><span aria-hidden="true">›</span><span aria-current="page"><?= e($P['abbr']) ?></span></nav>
      <h1><?= e($P['name']) ?></h1>
    </div>
    <span class="avpj-pill"><i class="avpj-dot<?= $P['selective'] ? ' avpj-dot--sel' : '' ?>" aria-hidden="true"></i><?= e($P['status']) ?></span>
  </div>
</header>

<section class="avpj-intro avh-pad" aria-label="About <?= e($P['name']) ?>">
  <div class="avpj-intro-in">
    <div class="avpj-intro-main">
      <div class="avpj-eyebrow"><?= e($P['cat']) ?> · Afrovanguard project</div>
      <p class="avpj-lead"><?= e($P['d']) ?></p>
      <ul class="avpj-tags avpj-tags--lg" aria-label="Focus">
<?php foreach ($P['tags'] as $t): ?>        <li><?= e($t) ?></li>
<?php endforeach; ?>      </ul>
      <div class="avpj-btns">
        <a class="avpj-btn" href="<?= e($v['ctaHref']) ?>" target="_blank" rel="noopener"><?= e($v['cta']) ?> →<span class="av-sr"> (opens in a new tab)</span></a>
        <a class="avpj-btn avpj-btn--line" href="/donate.html">Fund this project</a>
      </div>
    </div>
    <aside class="avpj-facts" aria-label="Project facts">
      <dl>
<?php foreach ($v['facts'] as [$k, $val]): ?>        <div><dt><?= e($k) ?></dt><dd><?= e($val) ?></dd></div>
<?php endforeach; ?>      </dl>
      <div class="avpj-mail">
        <div>Talk to the team</div>
        <a href="mailto:<?= e($P['mail']) ?>"><?= e($P['mail']) ?></a>
      </div>
    </aside>
  </div>
</section>

<?php if ($appeals): /* Kept from v1: live appeals filed against this project, hidden when there are none. */ ?>
<section class="avpj-appeals avh-pad" aria-labelledby="avpj-appeals-h">
  <div class="avpj-wrap">
    <div class="avpj-eyebrow">Live appeals</div>
    <h2 class="avpj-h2 avpj-h2--sm" id="avpj-appeals-h">What <?= e($P['name']) ?> needs</h2>
    <p class="avpj-sub">What has been raised so far is read from the verified payment record.</p>
    <div class="avpj-others">
<?php foreach ($appeals as $a): $st = Appeals::state($a); ?>
      <a class="avpj-card" href="<?= e('/give/' . rawurlencode((string) $a['slug']) . '/') ?>">
<?php if (!empty($a['cover_url'])): ?>        <img src="<?= e((string) $a['cover_url']) ?>" alt="" loading="lazy" decoding="async" width="580" height="363">
<?php else: ?>        <?= av_cover_appeal($a, $st, '16:9', false) ?>
<?php endif; ?>        <div class="avpj-card-body">
          <div class="avpj-kicker"><?php
            if (!empty($a['urgent'])) echo 'Urgent';
            elseif ($st['ending_soon'] && !$st['ended']) echo '<span class="av-num">' . (int) $st['days_left'] . '</span> days left';
            else echo 'Appeal'; ?></div>
          <div class="avpj-card-t"><?= e((string) $a['title']) ?></div>
<?php if (!empty($a['tagline'])): ?>          <div class="avpj-card-d"><?= e(mb_strimwidth((string) $a['tagline'], 0, 116, '…')) ?></div>
<?php endif; if ($st['percent'] !== null): ?>          <div class="avpj-bar" role="progressbar" aria-valuenow="<?= (int) $st['percent'] ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= (int) $st['percent'] ?>% raised"><span style="width:<?= (int) $st['percent'] ?>%"></span></div>
<?php endif; ?>          <div class="avpj-raised av-num"><strong><?= e(Appeals::naira($st['raised'])) ?></strong><?php if ($st['goal'] > 0): ?> of <?= e(Appeals::naira($st['goal'])) ?><?php endif; ?></div>
        </div>
      </a>
<?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="avpj-join avh-pad" aria-labelledby="avpj-join-h">
  <div class="avh-topo" data-avh-topo="light" data-seed="7" aria-hidden="true"></div>
  <div class="avpj-join-in">
    <div class="avpj-join-head">
      <div class="avpj-eyebrow">How to join</div>
      <h2 class="avpj-h2" id="avpj-join-h">From first step to Vanguard</h2>
      <div class="avpj-gallery">
<?php foreach ($v['gallery'] as $g): ?>        <img src="<?= e($g) ?>" alt="" loading="lazy" decoding="async" width="400" height="400">
<?php endforeach; ?>      </div>
    </div>
    <ol class="avpj-steps">
<?php foreach ($v['steps'] as $i => [$t, $d]): ?>      <li><span class="avpj-step-n" aria-hidden="true"><?= $i + 1 ?></span><div><h3><?= e($t) ?></h3><p><?= e($d) ?></p></div></li>
<?php endforeach; ?>    </ol>
  </div>
</section>

<section class="avpj-more avh-pad" aria-labelledby="avpj-more-h">
  <div class="avpj-wrap">
    <div class="avpj-more-head">
      <h2 class="avpj-h2 avpj-h2--md" id="avpj-more-h">Other projects</h2>
      <a class="avpj-link" href="/projects/">All nine projects →</a>
    </div>
    <div class="avpj-others">
<?php foreach ($v['others'] as $o): ?>
      <a class="avpj-card" href="<?= e($o['href']) ?>"<?= $o['ext'] ? ' target="_blank" rel="noopener"' : '' ?>>
        <img src="<?= e($o['img']) ?>" alt="<?= e($o['name']) ?>" loading="lazy" decoding="async" width="580" height="363">
        <div class="avpj-card-body">
          <div class="avpj-kicker"><?= e($o['cat']) ?></div>
          <div class="avpj-card-t"><?= e($o['name']) ?><?= $o['ext'] ? '<span class="av-sr"> (opens in a new tab)</span>' : '' ?></div>
          <div class="avpj-card-d"><?= e($o['d']) ?></div>
        </div>
      </a>
<?php endforeach; ?>
    </div>
  </div>
</section>

<section class="avpj-involved avh-pad" aria-labelledby="avpj-inv-h">
  <div class="avh-topo" data-avh-topo="dark" data-seed="7" aria-hidden="true"></div>
  <div class="avpj-eyebrow avpj-eyebrow--light avpj-c">Get involved</div>
  <h2 class="avpj-h2 avpj-h2--xl avpj-c" id="avpj-inv-h">There Is a Place For You</h2>
  <p>Whether you give, serve, learn, or grow — Afrovanguard has a role for every person who believes in Africa’s next generation.</p>
  <div class="avpj-inv-ctas">
    <a class="avpj-gold" href="/donate.html">Donate</a>
    <a href="/contact.html">Volunteer</a>
    <a href="/projects/">Apply to a program</a>
  </div>
</section>

</main>
<?php avh_footer(); ?>
</div>
<script src="/assets/site/chioma.js" defer></script>
<script src="/assets/site/celebrations.js" defer></script>
</body>
</html>
