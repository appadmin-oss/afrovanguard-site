<?php
/**
 * give/index.php — live appeals, /give/ (row 9 of the redesign, prefix avgv-).
 * Design: Afrovanguard Give.dc.html. Data: give/avgv-view.php (Appeals, unchanged from v1).
 * Chrome: partials/avh-chrome.php. Paying for one item: assets/site/give-pay.js (window.avGive) driven by avgv.js.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/partials/avh-chrome.php';
require_once __DIR__ . '/avgv-view.php';
require_once AV_ROOT . '/partials/av-cover.php';

$d = avgv_load();
$appeals = $d['appeals']; $summary = $d['summary']; $needs = $d['needs'];
$needTotal = $d['need_total']; $itemCats = $d['item_cats']; $itemSum = $d['item_sum'];
$N = static fn($n): string => Appeals::naira((int) $n);

$site = rtrim(SITE_URL, '/');
$canonical = $site . '/give/';
$title = 'Give — live appeals · Afrovanguard';
$desc = 'Live appeals from Afrovanguard — what we need today, this week and this season, and exactly what your gift pays for.';
if ($summary['appeals'] > 0 && $summary['raised'] > 0) {
    $desc = $summary['appeals'] . ' live appeal' . ($summary['appeals'] === 1 ? '' : 's')
          . ' · ' . $N($summary['raised']) . ' raised from ' . $summary['donors'] . ' donors. '
          . 'See what we need today and what your gift pays for.';
}
$desc = mb_substr($desc, 0, 185);
$image = $site . '/assets/og/og-default.png';
$jsonld = [
    schema_org(),
    schema_breadcrumb([['name' => 'Home', 'url' => $site . '/'], ['name' => 'Give', 'url' => $canonical]]),
    ['@type' => 'ItemList', 'name' => 'Afrovanguard appeals', 'itemListElement' => array_values(array_map(
        static fn(int $i, array $a): array => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => (string) $a['title'], 'url' => Appeals::url($a)],
        array_keys($appeals), $appeals))],
];
if (function_exists('send_security_headers')) send_security_headers('public');
?><!DOCTYPE html>
<html lang="en-NG" prefix="og: https://ogp.me/ns#" class="no-js">
<head>
  <script>document.documentElement.classList.replace('no-js','js');if(/(^|; )av_si=1/.test(document.cookie))document.documentElement.classList.add('av-si')</script>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($desc) ?>" />
  <meta name="author" content="Afrovanguard — afrovanguard.org.ng" />
  <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1" />
  <meta name="keywords" content="donate Nigeria, charity appeal, Afrovanguard, give, fundraising, community development" />
  <link rel="canonical" href="<?= e($canonical) ?>" />
  <meta name="theme-color" content="rgb(17 24 39)" />
  <meta property="og:type" content="website" />
  <meta property="og:site_name" content="Afrovanguard" />
  <meta property="og:locale" content="en_NG" />
  <meta property="og:title" content="<?= e($title) ?>" />
  <meta property="og:description" content="<?= e($desc) ?>" />
  <meta property="og:url" content="<?= e($canonical) ?>" />
  <meta property="og:image" content="<?= e($image) ?>" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />
  <meta property="og:image:alt" content="<?= e($title) ?>" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:site" content="@afrovanguard" />
  <meta name="twitter:title" content="<?= e($title) ?>" />
  <meta name="twitter:description" content="<?= e($desc) ?>" />
  <meta name="twitter:image" content="<?= e($image) ?>" />
  <link rel="alternate" type="application/rss+xml" title="Afrovanguard appeals" href="/give/feed.xml" />
  <script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@graph' => $jsonld], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
  <link rel="icon" href="/favicon.ico" sizes="any" />
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/site/icon-192.png" />
  <link rel="apple-touch-icon" href="/assets/site/icon-192.png" />
  <link rel="stylesheet" href="/assets/site/fonts.css" />
  <link rel="stylesheet" href="/assets/site/av-tokens.css" />
  <link rel="stylesheet" href="/assets/site/avh.css" />
  <link rel="stylesheet" href="/assets/site/avcv.css" />
  <link rel="stylesheet" href="/assets/site/avgv.css" />
  <script src="/assets/site/avh.js" defer></script>
  <script src="/assets/site/give-pay.js" defer></script>
  <script src="/assets/site/avgv.js" defer></script>
</head>
<body class="avh" id="top">
<a class="avh-skip" href="#main">Skip to content</a>
<div class="avh-page">
<?php avh_nav(); ?>

<main id="main" tabindex="-1" class="avgv">

<header class="avgv-mast" aria-labelledby="avgv-h1">
  <div class="avh-topo" data-avh-topo="light" data-seed="7" aria-hidden="true"></div>
  <div class="avgv-wrap">
    <nav class="avgv-crumbs" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">›</span><a href="/donate.html">Get involved</a><span aria-hidden="true">›</span><span aria-current="page">Live appeals</span></nav>
    <div class="avgv-mast-top">
      <h1 id="avgv-h1">What we need, and what it costs</h1>
      <p>Every appeal says exactly what it is for, how far along it is, and what a given amount pays for. Nothing is rounded up and nothing is guessed.</p>
    </div>
    <?php if ($summary['appeals'] > 0): ?>
      <dl class="avgv-stats">
        <div><dt>Live appeals</dt><dd class="av-num"><?= (int) $summary['appeals'] ?></dd></div>
        <div><dt>Raised</dt><dd class="av-num"><?= e($N($summary['raised'])) ?></dd></div>
        <?php /* Offline gifts carry no donor count: never print "0 donors" beside a raised total. */ ?>
        <?php if ($summary['donors'] > 0): ?>
          <div><dt>Donors</dt><dd class="av-num"><?= number_format((int) $summary['donors']) ?></dd></div>
        <?php elseif ($needTotal['today'] > 0): ?>
          <div><dt>Needed today</dt><dd class="av-num"><?= e($N($needTotal['today'])) ?></dd></div>
        <?php endif; ?>
        <?php if ($summary['goal'] > 0): ?>
          <div><dt>Together aiming for</dt><dd class="av-num"><?= e($N($summary['goal'])) ?></dd></div>
        <?php endif; ?>
      </dl>
    <?php endif; ?>
  </div>
</header>

<?php if ($d['error']): ?>
  <section class="avgv-sec" aria-labelledby="avgv-err-h">
    <div class="avgv-wrap avgv-state" role="alert">
      <h2 id="avgv-err-h">We couldn’t load the appeals just now</h2>
      <p>This is on our side, not yours. Try again in a moment, or give to the general fund in the meantime.</p>
      <p class="avgv-state-actions"><a class="avgv-btn" href="/give/">Try again</a><a class="avgv-btn avgv-btn--ghost" href="/donate.html">Give to the general fund</a></p>
    </div>
  </section>
<?php endif; ?>

<?php if ($needs): ?>
  <section class="avgv-needs" aria-labelledby="avgv-needs-h">
    <div class="avh-topo" data-avh-topo="dark" data-seed="11" aria-hidden="true"></div>
    <div class="avgv-wrap">
      <div class="avgv-needs-head">
        <div><div class="avgv-eyebrow avgv-eyebrow--light">Right now</div><h2 id="avgv-needs-h">What today and this week actually cost</h2></div>
        <?php if ($needTotal['today'] > 0 || $needTotal['week'] > 0): ?>
          <p class="avgv-needs-tot">
            <?php if ($needTotal['today'] > 0): ?><span><strong class="av-num"><?= e($N($needTotal['today'])) ?></strong> needed today</span><?php endif; ?>
            <?php if ($needTotal['week'] > 0): ?><span><strong class="av-num"><?= e($N($needTotal['week'])) ?></strong> this week</span><?php endif; ?>
          </p>
        <?php endif; ?>
      </div>
      <ul class="avgv-needs-list">
        <?php foreach ($needs as $n): $c = (string) $n['cadence']; ?>
          <li><a href="<?= e((string) $n['appeal']['url']) ?>">
            <span class="avgv-when avgv-when--<?= e($c === 'daily' ? 'today' : ($c === 'weekly' ? 'week' : 'open')) ?>"><span aria-hidden="true"></span><?= e(avgv_need_when($c)) ?></span>
            <span class="avgv-fig av-num"><?= e($N($n['target_ngn'])) ?></span>
            <span class="avgv-need-t"><?= e((string) $n['title']) ?></span>
            <?php if ((int) $n['units_target'] > 0 && (string) $n['unit_label'] !== ''): ?>
              <span class="avgv-need-u"><?= e(number_format((int) $n['units_target']) . ' ' . (string) $n['unit_label'] . ' at ' . $N($n['unit_cost']) . ' each') ?></span>
            <?php endif; ?>
            <span class="avgv-need-for">For · <?= e((string) $n['appeal']['title']) ?></span>
          </a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
<?php endif; ?>

<?php if (!$appeals && !$d['error']): ?>
  <section class="avgv-sec" aria-labelledby="avgv-empty-h">
    <div class="avgv-wrap avgv-state">
      <h2 id="avgv-empty-h">No appeals are running right now</h2>
      <p>When there is something specific to raise for, it will appear here with the full figures. In the meantime you can give to Afrovanguard’s general fund.</p>
      <p class="avgv-state-actions"><a class="avgv-btn" href="/donate.html">Give to the general fund</a></p>
    </div>
  </section>
<?php elseif ($appeals):
    $lead = $appeals[0]; $rest = array_slice($appeals, 1); $ls = Appeals::state($lead); $lp = $ls['percent']; ?>
  <section id="lead" class="avgv-sec avgv-sec--lead" aria-labelledby="avgv-lead-h">
    <a class="avgv-wrap avgv-lead" href="<?= e('/give/' . rawurlencode((string) $lead['slug']) . '/') ?>">
      <span class="avgv-lead-img"><?php if ((string) $lead['cover_url'] !== ''): ?><img src="<?= e((string) $lead['cover_url']) ?>" alt="" width="960" height="660" fetchpriority="high" decoding="async"><?php else: ?><?= av_cover_appeal($lead, $ls) ?><?php endif; ?></span>
      <span class="avgv-lead-body">
        <span class="avgv-kick"><?php if ($ls['urgent']): ?><span class="avgv-urgent">Urgent</span><?php endif; ?><span>Leading appeal<?= !empty($lead['location']) ? ' · ' . e((string) $lead['location']) : '' ?></span></span>
        <h2 id="avgv-lead-h"><?= e((string) $lead['title']) ?></h2>
        <?php if (!empty($lead['tagline'])): ?><span class="avgv-lead-d"><?= e((string) $lead['tagline']) ?></span><?php endif; ?>
        <span class="avgv-lead-meter">
          <?php if ($lp !== null): ?>
            <span class="avgv-bar<?= $ls['met'] ? ' is-met' : '' ?>" role="progressbar" aria-valuenow="<?= avgv_pct($lp) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= avgv_pct($lp) ?>% raised"><span style="width:<?= avgv_pct($lp) ?>%"></span></span>
          <?php endif; ?>
          <span class="avgv-lead-figs"><span><strong class="av-num"><?= e($N($ls['raised'])) ?></strong> <?= $ls['goal'] > 0 ? 'of ' . e($N($ls['goal'])) : 'raised so far' ?></span><span><?= e(avgv_lead_meta($ls)) ?></span></span>
        </span>
        <span class="avgv-btn">See this appeal →</span>
      </span>
    </a>
  </section>

  <?php if ($rest): ?>
    <section class="avgv-sec" aria-labelledby="avgv-more-h">
      <div class="avgv-wrap">
        <div class="avgv-more-head">
          <div><div class="avgv-eyebrow">Also open</div><h2 id="avgv-more-h" class="avgv-h2">More ways to give</h2></div>
          <a class="avgv-link" href="/donate.html">Give to the general fund →</a>
        </div>
        <ul class="avgv-tiles">
          <?php foreach ($rest as $a): $st = Appeals::state($a); $funded = (string) $a['status'] === 'funded'; ?>
            <li><a href="<?= e('/give/' . rawurlencode((string) $a['slug']) . '/') ?>">
              <span class="avgv-tile-img"><?php if ((string) $a['cover_url'] !== ''): ?><img src="<?= e((string) $a['cover_url']) ?>" alt="" width="580" height="387" loading="lazy" decoding="async"><?php else: ?><?= av_cover_appeal($a, $st) ?><?php endif; ?><?php if ($funded): ?><span class="avgv-funded">Funded ✓</span><?php endif; ?></span>
              <span class="avgv-kick<?= $funded ? ' is-funded' : '' ?>"><?= e(avgv_kicker($a, $st)) ?></span>
              <h3><?= e((string) $a['title']) ?></h3>
              <?php if (!empty($a['tagline'])): ?><span class="avgv-tile-d"><?= e(mb_strimwidth((string) $a['tagline'], 0, 116, '…')) ?></span><?php endif; ?>
              <?php if ($st['percent'] !== null): ?>
                <span class="avgv-bar avgv-bar--thin<?= $st['met'] ? ' is-met' : '' ?>" role="progressbar" aria-valuenow="<?= avgv_pct($st['percent']) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= avgv_pct($st['percent']) ?>% raised"><span style="width:<?= avgv_pct($st['percent']) ?>%"></span></span>
              <?php endif; ?>
              <span class="avgv-tile-figs"><strong class="av-num"><?= e($N($st['raised'])) ?></strong> <?php
                if ($st['goal'] > 0) echo 'of ' . e($N($st['goal']));
                elseif ($st['donors'] > 0) echo (int) $st['donors'] . ' donors';
                else echo 'raised so far'; ?></span>
            </a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>
  <?php endif; ?>
<?php endif; ?>

<?php if ($itemCats): ?>
  <section id="items" class="avgv-sec avgv-sec--paper" aria-labelledby="avgv-items-h">
    <div class="avh-topo" data-avh-topo="light" data-seed="15" aria-hidden="true"></div>
    <div class="avgv-wrap">
      <div class="avgv-items-head">
        <div><div class="avgv-eyebrow">The list</div><h2 id="avgv-items-h" class="avgv-h2">Exactly what we need, and what each thing costs</h2></div>
        <div><p>Give the money, or give the thing itself — both count the same here.</p>
          <?php if ($itemSum['outstanding_ngn'] > 0): ?><strong class="av-num"><?= e($N($itemSum['outstanding_ngn'])) ?> outstanding across <?= (int) $itemSum['total'] ?> items</strong><?php endif; ?></div>
      </div>
      <?php foreach ($itemCats as $cat => $list): ?>
        <div class="avgv-cat">
          <h3><?= e((string) $cat) ?></h3>
          <ul>
            <?php foreach ($list as $it):
              $money = (string) $it['kind'] === 'money' && (int) $it['unit_cost'] > 0;
              $need = (int) $it['qty_needed']; $left = (int) $it['qty_left']; $open = !empty($it['is_open']); ?>
              <li class="avgv-item<?= $open ? '' : ' is-done' ?>">
                <div class="avgv-item-main">
                  <?php if (trim((string) $it['image_url']) !== ''): ?><img src="<?= e((string) $it['image_url']) ?>" alt="" width="56" height="56" loading="lazy" decoding="async"><?php endif; ?>
                  <div>
                    <span class="avgv-item-t"><?= e((string) $it['title']) ?></span>
                    <?php if (trim((string) $it['detail']) !== ''): ?><span class="avgv-item-d"><?= e((string) $it['detail']) ?></span><?php endif; ?>
                    <span class="avgv-item-meter">
                      <?php if ($need > 0): ?>
                        <span class="avgv-bar avgv-bar--item<?= $open ? '' : ' is-met' ?>" role="progressbar" aria-valuenow="<?= avgv_pct($it['pct']) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= (int) $it['qty_funded'] ?> of <?= $need ?> covered"><span style="width:<?= avgv_pct($it['pct']) ?>%"></span></span>
                      <?php endif; ?>
                      <span class="av-num"><?= !$open ? 'All ' . $need . ' covered' : ($need > 0 ? $left . ' of ' . $need . ' still needed' : 'Any number welcome') ?></span>
                    </span>
                  </div>
                </div>
                <div class="avgv-item-side">
                  <span class="avgv-price"><b class="av-num"><?= $money ? e($N($it['unit_cost'])) : 'In kind' ?></b><small><?= $money ? e('per ' . (trim((string) $it['unit_label']) !== '' ? (string) $it['unit_label'] : 'item')) : 'we collect' ?></small></span>
                  <?php if (!$open): ?>
                    <span class="avgv-covered">Covered ✓</span>
                  <?php elseif ($money): ?>
                    <a class="avgv-fund" href="<?= e('/donate.html?amount=' . (int) $it['unit_cost'] . '&for=' . rawurlencode((string) $it['slug'])) ?>"
                       data-avgv-pay="<?= (int) $it['unit_cost'] ?>" data-avgv-item="<?= e((string) $it['title']) ?>" data-avgv-slug="<?= e((string) $it['slug']) ?>"
                       data-avgv-unit="<?= e(trim((string) $it['unit_label']) !== '' ? (string) $it['unit_label'] : 'item') ?>" data-avgv-left="<?= $need > 0 ? $left : 99 ?>">Fund one</a>
                  <?php else: ?>
                    <a class="avgv-fund avgv-fund--ghost" href="<?= e('/contact.html?about=' . rawurlencode('Donating: ' . (string) $it['title'])) ?>">Offer one</a>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<section class="avgv-sec avgv-sec--how" aria-labelledby="avgv-how-h">
  <div class="avgv-wrap avgv-how">
    <div>
      <div class="avgv-eyebrow">How this works</div>
      <h2 id="avgv-how-h" class="avgv-h2">Every appeal here is ours, and every figure is the verified one</h2>
      <p>Afrovanguard runs each of these itself — no third-party fundraisers, and nobody outside the organisation can start one here. Payments are taken by Paystack, and the totals are read from the verified payment record, not typed in by us.</p>
      <a class="avgv-link" href="/contact.html">Ask us anything about an appeal →</a>
    </div>
    <figure>
      <span class="avgv-diamond" aria-hidden="true"></span>
      <blockquote>“Tell people exactly what today costs, and they will pay for today.”</blockquote>
      <figcaption><strong>Afrovanguard</strong> · Ambassadors for Community, Tech &amp; Cultural Advancements</figcaption>
    </figure>
  </div>
</section>

</main>
<?php avh_footer(); ?>
</div>

<?php /* One sheet for every "Fund one". Each link keeps its /donate.html href, so with no
         JavaScript (or a blocked Paystack) there is still a way to give. */ ?>
<div class="avgv-scrim" data-avgv-scrim hidden></div>
<div class="avgv-sheet" role="dialog" aria-modal="true" aria-labelledby="avgv-pay-h" data-avgv-sheet hidden>
  <button type="button" class="avgv-x" data-avgv-close aria-label="Close">×</button>
  <form novalidate data-avgv-form>
    <span class="avgv-eyebrow">Fund one</span>
    <h2 id="avgv-pay-h" tabindex="-1" data-avgv-pay-item></h2>
    <div class="avgv-qty"><span id="avgv-qty-l">How many?</span>
      <span class="avgv-stepper" role="group" aria-labelledby="avgv-qty-l"><button type="button" data-avgv-qty="-1" aria-label="Fewer">−</button><output class="av-num" data-avgv-qty-n aria-live="polite">1</output><button type="button" data-avgv-qty="1" aria-label="More">+</button></span></div>
    <label class="avgv-field"><span>Your email <small>for the receipt</small></span><input type="email" name="email" autocomplete="email" placeholder="you@example.com" required aria-describedby="avgv-pay-err"></label>
    <label class="avgv-field"><span>Your name <small>optional — so we can thank you properly</small></span><input type="text" name="name" autocomplete="name"></label>
    <p class="avgv-err" id="avgv-pay-err" role="alert" data-avgv-err hidden></p>
    <button type="submit" class="avgv-go" data-avgv-go><span data-avgv-go-idle>Give <span class="av-num" data-avgv-amt></span></span><span data-avgv-go-busy hidden>Opening the card form…</span></button>
    <span class="avgv-fine">Card, bank transfer and USSD · Secured by Paystack · You stay on this page</span>
  </form>
  <div class="avgv-done" data-avgv-done hidden>
    <span class="avgv-tick" aria-hidden="true">✓</span>
    <h2 tabindex="-1" data-avgv-done-h>Thank you</h2>
    <p data-avgv-done-p></p>
    <button type="button" class="avgv-btn avgv-btn--ghost" data-avgv-finish>Done</button>
  </div>
</div>

<script src="/assets/site/chioma.js" defer></script>
<script src="/assets/site/celebrations.js" defer></script>
</body>
</html>
