<?php
/**
 * events/view.php — /events/{slug}/: one of Afrovanguard's own events.
 *
 * Its date and time (Lagos), where, what it is, how to take part (RSVP, the
 * online link) and "add to calendar" (Google Calendar, or the .ics). Only a
 * published or cancelled event for everyone has a page; a members' or team
 * event is in the portal, and a draft is nowhere. lib/SiteEvents.php.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/partials/avh-chrome.php';

$slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$ev = $slug !== '' ? SiteEvents::bySlug($slug) : null;
if (!$ev || !in_array($ev['status'], ['published', 'cancelled'], true)) {
    http_response_code(404);
    require AV_ROOT . '/404.html';
    exit;
}
/* A members' or team event has a page too, for the people it is for. Signed out: the sign-in, and
   back here after. Signed in without the audience: the same 404 a stranger gets, so the page does
   not confirm that a team event exists. */
$private = $ev['audience'] !== 'public';
if ($private) {
    $viewer = LmsAuth::user();
    if (!$viewer) {
        header('Location: /login/?next=' . rawurlencode('/events/' . $ev['slug'] . '/'), true, 302);
        exit;
    }
    if (!in_array($ev['audience'], SiteEvents::audiencesFor($viewer), true)) {
        http_response_code(404);
        require AV_ROOT . '/404.html';
        exit;
    }
}
$e = SiteEvents::shape($ev);
$S = rtrim(SITE_URL, '/');
$canonical = $S . '/events/' . $ev['slug'] . '/';
$start = new DateTimeImmutable($ev['starts_at'], new DateTimeZone(SiteEvents::TZ));
$end = $ev['ends_at'] !== '' ? new DateTimeImmutable($ev['ends_at'], new DateTimeZone(SiteEvents::TZ)) : null;
$sameDay = !$end || $end->format('Y-m-d') === $start->format('Y-m-d');
if ($ev['all_day']) {
    $whenLine = $start->format('l, j F Y') . ($end && !$sameDay ? ' – ' . $end->format('l, j F Y') : '');
} else {
    $whenLine = $start->format('l, j F Y · g:ia') . ($end ? ' – ' . ($sameDay ? $end->format('g:ia') : $end->format('l, j F Y · g:ia')) : '') . ' (Lagos time)';
}
$past = !$e['ongoing'] && $e['iso'] < (new DateTimeImmutable('now'))->format(DATE_ATOM) && ($e['ends_iso'] === '' || $e['ends_iso'] < (new DateTimeImmutable('now'))->format(DATE_ATOM));
$title = $ev['title'] . ' — Afrovanguard events';
$desc = $ev['summary'] !== '' ? $ev['summary'] : mb_substr(trim((string) preg_replace('/\s+/', ' ', $ev['body'])), 0, 160);
$img = $e['image'] === '' ? $S . '/assets/og/og-default.png' : ($e['image'][0] === '/' ? $S . $e['image'] : $e['image']);
$ld = ['@context' => 'https://schema.org', '@type' => 'Event', 'name' => $ev['title'], 'startDate' => $e['iso'], 'url' => $canonical,
       'eventStatus' => $ev['status'] === 'cancelled' ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
       'eventAttendanceMode' => $ev['online_url'] !== '' && $e['location'] === '' ? 'https://schema.org/OnlineEventAttendanceMode' : ($ev['online_url'] !== '' ? 'https://schema.org/MixedEventAttendanceMode' : 'https://schema.org/OfflineEventAttendanceMode'),
       'organizer' => ['@type' => 'Organization', 'name' => 'Afrovanguard', 'url' => "$S/"], 'image' => [$img], 'description' => $desc];
if ($e['ends_iso'] !== '') $ld['endDate'] = $e['ends_iso'];
if ($e['location'] !== '') $ld['location'] = ['@type' => 'Place', 'name' => $e['location'], 'address' => $ev['location'] ?: $e['location']];
elseif ($ev['online_url'] !== '') $ld['location'] = ['@type' => 'VirtualLocation', 'url' => $ev['online_url']];

if (function_exists('send_security_headers')) send_security_headers('public');
/* A members' page is not for search engines or a shared cache. */
if ($private) { header('X-Robots-Tag: noindex, nofollow'); header('Cache-Control: private, no-store'); }
?>
<!DOCTYPE html>
<html lang="en-NG" prefix="og: https://ogp.me/ns#" class="no-js">
<head>
  <script>document.documentElement.classList.replace('no-js','js');if(/(^|; )av_si=1/.test(document.cookie))document.documentElement.classList.add('av-si')</script>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($desc) ?>" />
  <link rel="canonical" href="<?= e($canonical) ?>" />
  <meta name="theme-color" content="rgb(17,24,39)" />
  <meta property="og:type" content="website" />
  <meta property="og:site_name" content="Afrovanguard" />
  <meta property="og:title" content="<?= e($ev['title']) ?>" />
  <meta property="og:description" content="<?= e($whenLine . ($desc !== '' ? ' — ' . $desc : '')) ?>" />
  <meta property="og:url" content="<?= e($canonical) ?>" />
  <meta property="og:image" content="<?= e($img) ?>" />
  <meta name="twitter:card" content="summary_large_image" />
<?php if ($private): ?>
  <meta name="robots" content="noindex, nofollow" />
<?php else: ?>
  <script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endif; ?>
  <link rel="icon" href="/favicon.ico" sizes="any" />
  <link rel="stylesheet" href="<?= e(av_asset('/assets/site/fonts.css')) ?>" />
  <link rel="stylesheet" href="<?= e(av_asset('/assets/site/av-tokens.css')) ?>" />
  <link rel="stylesheet" href="<?= e(av_asset('/assets/site/avh.css')) ?>" />
  <link rel="stylesheet" href="<?= e(av_asset('/assets/site/avev.css')) ?>" />
  <script src="<?= e(av_asset('/assets/site/avh.js')) ?>" defer></script>
</head>
<body class="avh" id="top">
<a class="avh-skip" href="#main">Skip to content</a>
<div class="avh-page">
<?php avh_nav(); ?>
<main id="main" tabindex="-1">
<article class="avev-one avev-pad">
  <div class="avev-wrap">
    <nav aria-label="Breadcrumb"><ol class="avev-crumbs"><li><a href="/">Home</a></li><li><a href="/events/">Events</a></li><li><span aria-current="page"><?= e($ev['title']) ?></span></li></ol></nav>
    <div class="avev-one-grid">
      <div>
<?php if ($private): ?>
        <p class="avev-one-flag"><?= $ev['audience'] === 'org' ? 'Team only' : 'Members only' ?></p>
<?php endif; ?>
<?php if ($ev['status'] === 'cancelled'): ?>
        <p class="avev-one-flag is-off">Cancelled</p>
<?php elseif ($e['ongoing']): ?>
        <p class="avev-one-flag is-live">Happening now</p>
<?php elseif ($past): ?>
        <p class="avev-one-flag">This event has taken place</p>
<?php endif; ?>
        <h1 class="avev-one-h"><?= e($ev['title']) ?></h1>
<?php if ($ev['summary'] !== ''): ?>
        <p class="avev-one-lede"><?= e($ev['summary']) ?></p>
<?php endif; ?>
        <dl class="avev-one-facts">
          <div><dt>When</dt><dd><time datetime="<?= e($e['iso']) ?>"><?= e($whenLine) ?></time></dd></div>
<?php if ($e['location'] !== ''): ?>
          <div><dt>Where</dt><dd><?= e($e['location']) ?></dd></div>
<?php endif; ?>
<?php if ($ev['online_url'] !== ''): ?>
          <div><dt>Online</dt><dd><a href="<?= e($ev['online_url']) ?>" rel="noopener" target="_blank">Join online</a></dd></div>
<?php endif; ?>
          <div><dt>Hosted by</dt><dd>Afrovanguard</dd></div>
        </dl>
<?php if ($ev['status'] !== 'cancelled' && !$past): ?>
        <div class="avev-one-acts">
<?php if ($e['join_open']): ?>
          <a class="avev-vol" href="<?= e($ev['online_url']) ?>" rel="noopener" target="_blank">Join now<span class="avev-sr"> (opens the call in a new tab)</span></a>
<?php elseif ($ev['online_url'] !== ''): ?>
          <span class="avev-one-soon">The join button appears here 30 minutes before it starts.</span>
<?php endif; ?>
<?php if ($ev['rsvp_url'] !== ''): ?>
          <a class="<?= $e['join_open'] ? 'avev-touch' : 'avev-vol' ?>" href="<?= e($ev['rsvp_url']) ?>" rel="noopener" target="_blank">Reserve a place</a>
<?php endif; ?>
          <a class="avev-touch" href="<?= e(SiteEvents::googleAddUrl($ev)) ?>" rel="noopener" target="_blank">Add to Google Calendar</a>
<?php if (!$private): ?>
          <a class="avev-touch" href="/events/calendar.ics">Subscribe to our calendar (.ics)</a>
<?php endif; ?>
        </div>
<?php endif; ?>
<?php if (trim($ev['body']) !== ''): ?>
        <div class="avev-one-body">
<?php foreach (preg_split("/\n{2,}/", trim($ev['body'])) as $para): ?>
          <p><?= nl2br(e(trim($para))) ?></p>
<?php endforeach; ?>
        </div>
<?php endif; ?>
      </div>
<?php if ($e['image'] !== ''): ?>
      <figure class="avev-one-cover"><a href="<?= e($e['image']) ?>" target="_blank" rel="noopener"><img src="<?= e($e['image']) ?>" alt="Flier for <?= e($ev['title']) ?>" loading="eager" decoding="async" /><span class="avev-sr"> (opens the full flier)</span></a></figure>
<?php endif; ?>
    </div>
    <p class="avev-one-back"><a href="/events/">← All events</a></p>
  </div>
</article>
</main>
<?php avh_footer(); ?>
</div>
</body>
</html>
