<?php
/**
 * events/index.php — Events (route /events/).
 *
 * Rebuilt to the design "Afrovanguard Events" (REPLACEMENT_MAP row 13), inside
 * the shared Home chrome. Sections: hero → featured summit (while it is ahead
 * of us) → What's coming up (the events feed, filtered in the browser, with
 * loading / empty / error states) → What we host → Host with us.
 * Styles: assets/site/avev.css · behaviour: assets/site/avev.js.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/partials/avh-chrome.php';
require_once AV_ROOT . '/partials/avev-cover.php';

$S         = rtrim(SITE_URL, '/');
$canonical = "$S/events/";

/* @wire: summit, team calendar, Events space posts and the feed */
$summit        = null;
$calEmbed      = '';
$announcements = [];
$feedUrl       = '';

$kinds = [
    ['Town halls',        'townhall', 'Open sessions where members and the team think out loud about the work and what’s next.'],
    ['The Annual Gala',   'gala',     'Our flagship celebration of the movement — partners, mentors and the young leaders we serve.'],
    ['Programme expos',   'arts',     'Showcases from Techome, MediaPro, Africa GATES and the Academy — built in the open.'],
    ['Community meetups', 'meetup',   'Local gatherings across Lagos chapters — volunteer, organise, and show up for the centres.'],
    ['Workshops',         'workshop', 'Hands-on skill sessions in technology, creativity, civics and leadership.'],
    ['Street-To-Stardom', 'arts',     'Talent, mentorship and opportunity for young people — on the streets where they are.'],
];

$title = 'Events & gatherings — Afrovanguard';
$desc  = 'Town halls, the Annual Gala, programme expos, workshops and community meetups across the Afrovanguard movement. See what’s coming up and join us.';
$curly = static fn(string $s): string => str_replace("'", '’', $s);

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
  <meta name="keywords" content="Afrovanguard events, Lagos nonprofit events, community meetups, gala, town hall" />
  <meta name="author" content="Afrovanguard — afrovanguard.org.ng" />
  <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1" />
  <link rel="canonical" href="<?= e($canonical) ?>" />
  <meta name="theme-color" content="rgb(17,24,39)" />
  <meta property="og:type" content="website" />
  <meta property="og:site_name" content="Afrovanguard" />
  <meta property="og:locale" content="en_NG" />
  <meta property="og:title" content="<?= e($title) ?>" />
  <meta property="og:description" content="<?= e($desc) ?>" />
  <meta property="og:url" content="<?= e($canonical) ?>" />
  <meta property="og:image" content="<?= e($S) ?>/Images/og-image.png" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />
  <meta property="og:image:alt" content="<?= e($title) ?>" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:site" content="@afrovanguard" />
  <meta name="twitter:title" content="<?= e($title) ?>" />
  <meta name="twitter:description" content="<?= e($desc) ?>" />
  <meta name="twitter:image" content="<?= e($S) ?>/Images/og-image.png" />
  <script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $title, 'description' => $desc, 'url' => $canonical,
      'isPartOf' => ['@type' => 'WebSite', 'name' => 'Afrovanguard', 'url' => "$S/"]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
  <link rel="icon" href="/favicon.ico" sizes="any" />
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/site/icon-192.png" />
  <link rel="apple-touch-icon" href="/assets/site/icon-192.png" />
  <link rel="stylesheet" href="/assets/site/fonts.css" />
  <link rel="stylesheet" href="/assets/site/av-tokens.css" />
  <link rel="stylesheet" href="/assets/site/avh.css" />
  <link rel="stylesheet" href="/assets/site/avev.css" />
  <script src="/assets/site/avh.js" defer></script>
  <script src="/assets/site/avev.js" defer></script>
</head>
<body class="avh" id="top">
<a class="avh-skip" href="#main">Skip to content</a>
<div class="avh-page">
<?php avh_nav(); ?>
<main id="main" tabindex="-1">

<header class="avev-hero avev-pad">
  <div class="avh-topo" data-avh-topo="light" data-seed="7" aria-hidden="true"></div>
  <div class="avev-wrap">
    <nav aria-label="Breadcrumb"><ol class="avev-crumbs"><li><a href="/">Home</a></li><li><span aria-current="page">Events</span></li></ol></nav>
    <div class="avev-hero-grid">
      <h1>Where the movement meets</h1>
      <div class="avev-hero-side">
        <p>Town halls, the Annual Gala, programme expos, workshops and community meetups — where members, mentors and the young leaders we serve come together.</p>
        <span class="avev-live">Live from Africa GATES · updated every 30 minutes</span>
      </div>
    </div>
  </div>
</header>

<?php if ($summit): $sv = $summit['venue']; $sts = strtotime((string) $summit['starts']) ?: time(); ?>
<section class="avev-feat-sec avev-pad" aria-labelledby="avev-summit-h">
  <a class="avev-feat" href="/academy/dns/#register">
    <?= avev_cover([
        'title' => $curly($summit['name']) . ' ' . date('Y', $sts), 'category' => 'summit',
        'date' => date('Y-m-d', $sts), 'end' => (string) $summit['ends'],
        'time' => strtolower(str_replace(' ', '', (string) $summit['time_label'])),
        'location' => $sv['area'] . ', ' . $sv['city'],
    ]) ?>
    <div class="avev-feat-body">
<?php if (!empty($summitLive)): ?>
      <div class="avev-feat-kick"><span class="avev-dot" aria-hidden="true"></span>Happening now</div>
<?php else: ?>
      <div class="avev-feat-kick"><?= e($summit['presenter']) ?> presents · <?= e($curly($summit['edition'])) ?></div>
<?php endif; ?>
      <h2 id="avev-summit-h"><?= e($curly($summit['name'])) ?></h2>
      <p class="avev-feat-triad"><?= e(str_replace(' | ', ' · ', (string) $summit['triad'])) ?></p>
      <p class="avev-feat-lede"><?= e($curly($summit['lede'])) ?></p>
      <dl class="avev-facts">
        <div><dt>Dates</dt><dd><?= e($summit['date_short']) ?></dd></div>
        <div><dt>Venue</dt><dd><?= e($sv['name'] . ', ' . $sv['area'] . ', ' . $sv['city']) ?></dd></div>
        <div><dt>Pass</dt><dd><?= e($summit['pass']['label'] . ' · ' . $summit['pass']['note']) ?></dd></div>
      </dl>
      <span class="avev-feat-cta">Claim your seat →</span>
    </div>
  </a>
</section>
<?php endif; ?>

<section class="avev-up avev-pad" aria-labelledby="avev-up-h">
  <div class="avev-wrap">
    <div class="avev-up-head">
      <div><div class="avev-eyebrow">Calendar</div><h2 class="avev-h2" id="avev-up-h">What’s coming up</h2></div>
      <a class="avev-full" href="https://afg.afrovanguard.org.ng/events" target="_blank" rel="noopener">Full calendar on Africa GATES ↗</a>
    </div>
    <div class="avev-bar">
      <div class="avev-seg" role="group" aria-label="When" data-avev-when>
        <button type="button" aria-pressed="true" data-v="up">Upcoming</button>
        <button type="button" aria-pressed="false" data-v="month">This month</button>
        <button type="button" aria-pressed="false" data-v="past">Past</button>
      </div>
      <div class="avev-chips" role="group" aria-label="Category" data-avev-cats>
        <button type="button" aria-pressed="true" data-v="All">All</button>
        <button type="button" aria-pressed="false" data-v="Learning">Learning</button>
        <button type="button" aria-pressed="false" data-v="Community">Community</button>
        <button type="button" aria-pressed="false" data-v="Arts">Arts</button>
        <button type="button" aria-pressed="false" data-v="Leadership">Leadership</button>
      </div>
    </div>
    <div data-avev-list data-feed="<?= e($feedUrl) ?>" data-today="<?= e(date('Y-m-d')) ?>" aria-live="polite" aria-busy="true">
      <ul class="avev-grid" aria-label="Loading events">
<?php for ($i = 0; $i < 3; $i++): ?>
        <li class="avev-sk" aria-hidden="true"><span></span><span></span><span></span><span></span></li>
<?php endfor; ?>
      </ul>
    </div>
  </div>
</section>

<?php if ($calEmbed !== '' || $announcements): ?>
<section class="avev-more avev-pad" aria-label="More from the team">
  <div class="avev-wrap">
<?php if ($calEmbed !== ''): ?>
    <div class="avev-more-block">
      <h2>Upcoming — our calendar</h2>
      <p class="avev-sub">Straight from the Afrovanguard team calendar. Add it to your own to never miss a date.</p>
      <div class="avev-cal"><iframe src="<?= e($calEmbed) ?>" title="Afrovanguard events calendar" loading="lazy"></iframe></div>
    </div>
<?php endif; if ($announcements): ?>
    <div class="avev-more-block">
      <h2>Latest from the Events space</h2>
      <p class="avev-sub">Recent announcements from the community. <a href="/portal/?space=events#community">Open the Events space →</a></p>
      <ul class="avev-ann">
<?php foreach ($announcements as $a): ?>
        <li><p><?= e($a['body']) ?><?= mb_strlen($a['body']) >= 220 ? '…' : '' ?></p><p><?= e($a['who']) ?><?php if ($a['when'] !== ''): ?> · <time datetime="<?= e($a['when']) ?>"><?= e(substr($a['when'], 0, 10)) ?></time><?php endif; ?></p></li>
<?php endforeach; ?>
      </ul>
    </div>
<?php endif; ?>
  </div>
</section>
<?php endif; ?>

<section class="avev-host avev-pad" aria-labelledby="avev-host-h">
  <div class="avh-topo" data-avh-topo="light" data-seed="11" aria-hidden="true"></div>
  <div class="avev-wrap">
    <div class="avev-host-head"><div class="avev-eyebrow">A rhythm through the year</div><h2 class="avev-h2" id="avev-host-h">What we host</h2></div>
    <ul class="avev-kinds">
<?php foreach ($kinds as [$kt, $kc, $kd]): ?>
      <li><?= avev_cover(['title' => $kt, 'category' => $kc, 'ratio' => '1.91:1']) ?><span><?= e($kd) ?></span></li>
<?php endforeach; ?>
    </ul>
  </div>
</section>

<section class="avev-cta-sec avev-pad" aria-labelledby="avev-cta-h">
  <div class="avev-cta">
    <div><h2 id="avev-cta-h">Bringing people together for good?</h2><p>Host an event with us, partner on a programme, or volunteer at the next gathering.</p></div>
    <div class="avev-cta-btns"><a class="avev-vol" href="<?= e(AV_VOLUNTEER_URL) ?>">Volunteer with us</a><a class="avev-touch" href="/contact.html">Get in touch</a></div>
  </div>
</section>

</main>
<?php avh_footer(); ?>
</div>
<script src="/assets/site/chioma.js" defer></script>
<script src="/assets/site/celebrations.js" defer></script>
</body>
</html>
