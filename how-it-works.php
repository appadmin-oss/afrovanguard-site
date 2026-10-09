<?php
/**
 * how-it-works.php — /how-it-works, row 11 of the site redesign
 * ("Afrovanguard How It Works.dc.html"): the Progressive Growth & Member
 * Alignment framework — principles, the O → A → C ladder, the contribution
 * ledger, the ego filter. Home nav and footer via partials/avh-chrome.php;
 * styles in assets/site/avhw.css (prefix avhw-, tokens only).
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once AV_ROOT . '/partials/avh-chrome.php';

if (function_exists('send_security_headers')) send_security_headers('public');

$title     = 'How Afrovanguard Works — Progressive Growth & Member Alignment';
$desc      = 'How members grow at Afrovanguard: an algorithmic progression built on verifiable output, mentorship, invisible service, radical transparency and servant leadership — from Foundation Member (Level O) upward.';
$canonical = rtrim(SITE_URL, '/') . '/how-it-works';
$image     = rtrim(SITE_URL, '/') . '/Images/og-image.png';

// Dues come from the same config as v1 and the portal (lib/bootstrap.php), so the page never quotes a stale amount.
$MONTHLY = defined('AV_DUES_MONTHLY_NGN') ? (int) AV_DUES_MONTHLY_NGN : 1000;
$ANNUAL  = defined('AV_DUES_ANNUAL_NGN')  ? (int) AV_DUES_ANNUAL_NGN  : 12000;

$ladder = [
    ['b' => 'O', 'k' => 'Where everyone begins', 't' => 'Level O — Foundation Member',
     'd' => 'Every new member enters the Afrovanguard ecosystem as a Level O Member. At this foundational stage you are stress-tested for consistency and teachability.',
     'cards' => [['Core obligations', false, [
         ['Mentorship alignment', 'Select an approved mentor from the verified structural registry.'],
         ['High-frequency execution', 'Take part in local programmes and complete at least one verified task or service responsibility every week.'],
         ['Behavioural baseline', 'Show absolute consistency, radical accountability and a rapid willingness to learn and internalise Afrovanguard culture.'],
     ]]]],
    ['b' => 'A', 'k' => 'Advancement', 't' => 'Level A Membership',
     'd' => 'A Level O Member becomes eligible for Level A only after demonstrating verifiable capacity and driving measurable ecosystem growth through the approved progression pathway.',
     'cards' => [
         ['Requirements for advancement', false, [
             ['Network expansion', 'Personally introduce two or more committed people who register through your unique referral link.'],
             ['Onboarding integrity', 'Support and mentor them through integration, so they keep up their Level O weekly tasks.'],
             ['Mission alignment', 'Show flawless participation, service and alignment with Afrovanguard’s core values.'],
         ]],
         ['Upon attaining Level A', true, [
             ['Sandboxed identity', 'Access to the official communication network on a secure tier that protects the brand.'],
             ['Accountability vector', 'Paired with an operational mentor for leadership development, performance audits and support.'],
             ['Operational eligibility', 'Eligible to compete for higher leadership responsibilities and regional opportunities.'],
         ]],
     ]],
    ['b' => 'C', 'k' => 'Toward leadership', 't' => 'Level C and beyond', 'goal' => true,
     'd' => 'As members ascend, dues become a non-negotiable responsibility of leadership and servant leadership — the ego filter — becomes the standard. Level C leaders who multiply leadership qualify to establish a CACENTRE and carry the model into new communities.',
     'cards' => []],
];
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
  <link rel="canonical" href="<?= e($canonical) ?>" />
  <meta name="theme-color" content="rgb(243 180 22)" />
  <meta property="og:type" content="article" />
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
  <meta name="twitter:image:alt" content="<?= e($title) ?>" />
  <link rel="icon" href="/favicon.ico" sizes="any" />
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/site/icon-192.png" />
  <link rel="apple-touch-icon" href="/assets/site/icon-192.png" />
  <link rel="stylesheet" href="/assets/site/fonts.css" />
  <link rel="stylesheet" href="/assets/site/av-tokens.css" />
  <link rel="stylesheet" href="/assets/site/avh.css" />
  <link rel="stylesheet" href="/assets/site/avhw.css" />
  <script src="/assets/site/avh.js" defer></script>
</head>
<body class="avh" id="top">
<a class="avh-skip" href="#main">Skip to content</a>
<div class="avh-page">
<?php avh_nav(); ?>

<main id="main" tabindex="-1" class="avhw">

<header class="avhw-hero">
  <div class="avh-topo" data-avh-topo="light" data-seed="7" aria-hidden="true"></div>
  <div class="avhw-wrap">
    <nav class="avhw-crumbs" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">›</span><a href="/about.html">About</a><span aria-hidden="true">›</span><span aria-current="page">How it works</span></nav>
    <div class="avhw-hero-grid">
      <h1>Progressive growth &amp; member alignment</h1>
      <div class="avhw-hero-side">
        <p>We develop leaders through a structured progression built on personal growth, accountability, service, leadership and alignment with our vision and values.</p>
        <div class="avhw-btns">
          <a class="avhw-btn" href="/academy/#membership">Become a member</a>
          <a class="avhw-btn avhw-btn--line" href="/mentorship/">Find a mentor</a>
        </div>
      </div>
    </div>
  </div>
</header>

<section class="avhw-principles" aria-label="Principles">
  <ul class="avhw-wrap">
    <li><b>Output, not tenure</b><span>You move up by what you can show you did — not by how long you have been here.</span></li>
    <li><b>A mentor from day one</b><span>Every member chooses an approved mentor from the verified registry.</span></li>
    <li><b>Earned, never granted</b><span>Each level has written requirements. Nobody is promoted as a favour.</span></li>
  </ul>
</section>

<section class="avhw-sec" aria-labelledby="avhw-path-h">
  <div class="avhw-wrap">
    <div class="avhw-split">
      <div><div class="avhw-eyebrow">The path</div><h2 class="avhw-h2" id="avhw-path-h">From your first day to organisational leadership</h2></div>
      <p>Advancement is strictly algorithmic — based on verifiable output, systemic growth and consistent contribution, <strong>never on length of membership or mere presence</strong>.</p>
    </div>
    <ol class="avhw-ladder">
<?php foreach ($ladder as $i => $lv): ?>
      <li class="avhw-step<?= !empty($lv['goal']) ? ' avhw-step--goal' : '' ?>">
        <div class="avhw-rail" aria-hidden="true"><span class="avhw-badge"><?= e($lv['b']) ?></span><span class="avhw-line"></span></div>
        <div class="avhw-step-b">
          <span class="avhw-eyebrow avhw-eyebrow--flat"><?= e($lv['k']) ?></span>
          <h3><?= e($lv['t']) ?></h3>
          <p><?= e($lv['d']) ?></p>
<?php if ($lv['cards']): ?>
          <div class="avhw-cards">
<?php foreach ($lv['cards'] as [$h, $done, $items]): ?>
            <div class="avhw-card<?= $done ? ' avhw-card--done' : '' ?>">
              <h4><?= e($h) ?></h4>
              <ul>
<?php foreach ($items as [$t, $d]): ?>
                <li><span class="avhw-mk" aria-hidden="true"><?= $done ? '✓' : '—' ?></span><span><b><?= e($t) ?></b><span><?= e($d) ?></span></span></li>
<?php endforeach; ?>
              </ul>
            </div>
<?php endforeach; ?>
          </div>
<?php endif; ?>
        </div>
      </li>
<?php endforeach; ?>
    </ol>
  </div>
</section>

<section class="avhw-ledger" aria-labelledby="avhw-ledger-h">
  <div class="avh-topo" data-avh-topo="light" data-seed="11" aria-hidden="true"></div>
  <div class="avhw-wrap">
    <div class="avhw-split avhw-split--tight">
      <div><div class="avhw-eyebrow">Membership contribution</div><h2 class="avhw-h2" id="avhw-ledger-h">An open, auditable ledger</h2></div>
      <p>Financial transparency is the ultimate weapon against institutional decay. The ecosystem runs on an immutable contribution ledger that every member can read.</p>
    </div>
    <div class="avhw-dues">
      <div class="avhw-due">
        <span class="avhw-due-k avhw-due-k--slate">Level A &amp; B</span>
        <span class="avhw-due-n">Voluntary</span>
        <span class="avhw-due-d">Members may contribute to fuel grassroots operations. Nothing is required.</span>
      </div>
      <div class="avhw-due">
        <span class="avhw-due-k">Level C and above · mandatory</span>
        <span class="avhw-due-amt"><span class="avhw-due-n av-num">₦<?= number_format($MONTHLY) ?><small> / month</small></span><span class="avhw-due-alt av-num">or ₦<?= number_format($ANNUAL) ?> / year</span></span>
        <span class="avhw-due-d">Dues become a non-negotiable responsibility of leadership. Pay or renew any time in the <a href="/portal/">member portal</a>.</span>
      </div>
      <div class="avhw-due avhw-due--ink">
        <span class="avhw-due-k">The radical transparency rule</span>
        <span class="avhw-due-n">100% visible</span>
        <span class="avhw-due-d">Every naira collected is logged on an open, real-time dashboard. Leadership must justify every expenditure. We fight corruption with absolute visibility.</span>
      </div>
    </div>
  </div>
</section>

<section class="avhw-sec" aria-labelledby="avhw-ego-h">
  <div class="avhw-wrap avhw-ego">
    <div class="avhw-ego-head">
      <div class="avhw-eyebrow">Leadership culture</div>
      <h2 class="avhw-h2" id="avhw-ego-h">The ego filter</h2>
      <p>Before qualifying for Level C, every member proves their ego is entirely subordinate to the mission — moving from consumer to architect of the system.</p>
    </div>
    <ol class="avhw-ego-list">
      <li><span class="avhw-ego-n">30 min</span><span><b>Pre-event architecture</b><span>Arrive at least 30 minutes before standard meetings — and 2–3 hours early for major programmes — to handle setup, logistics and invisible backend labour.</span></span></li>
      <li><span class="avhw-ego-n">Unseen</span><span><b>Invisible service</b><span>Work comfortably behind the scenes, in menial or uncredited roles, before earning the right to appear as an attendee or speaker.</span></span></li>
      <li><span class="avhw-ego-n">First</span><span><b>Mission primacy</b><span>Consistently and measurably place the velocity of the mission above personal convenience.</span></span></li>
    </ol>
  </div>
</section>

<section class="avhw-closing" aria-label="Closing">
  <div class="avhw-wrap avhw-closing-in">
    <blockquote>“Those who faithfully manage the friction of service are engineered to lead.”</blockquote>
    <div class="avhw-btns avhw-btns--c">
      <a class="avhw-btn" href="/portal/">Go to your portal</a>
      <a class="avhw-btn avhw-btn--line" href="/mentorship/">Find a mentor</a>
      <a class="avhw-btn avhw-btn--line" href="/franchise">Franchise a CACENTRE</a>
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
