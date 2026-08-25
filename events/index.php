<?php
/**
 * events/index.php — Afrovanguard events & gatherings.
 *
 * A public events page that is never empty: it leads with the org's live Google
 * Calendar embed when one is configured (lib/workspace.php), always shows the
 * kinds of gatherings we host, and points to the community Events space + a way
 * to host/partner. Degrades gracefully with nothing configured.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/lib/workspace.php';

$S         = rtrim(SITE_URL, '/');
$canonical = "$S/events/";

$embeds   = function_exists('av_workspace_embeds') ? av_workspace_embeds() : [];
$calEmbed = $embeds['calendar'] ?? '';

/* Optional: the latest few posts from the community "Events" space (public read). */
$announcements = [];
try {
    if (class_exists('Community')) {
        foreach (Community::feed('events', 'latest', 4, 0, 0) as $p) {
            $announcements[] = [
                'body' => mb_substr(trim((string) ($p['body'] ?? '')), 0, 220),
                'when' => (string) ($p['created_at'] ?? ''),
                'who'  => (string) ($p['author'] ?? 'Afrovanguard'),
            ];
        }
    }
} catch (Throwable $e) { /* community not ready → section simply hidden */ }

/* Our own flagship summit — featured at the top of the page while it is still
   ahead of us. Summit::feedEntry() is null once it is over, so the band simply
   disappears rather than advertising a finished event. */
$summit = null;
try { if (class_exists('Summit') && !Summit::isPast()) $summit = Summit::facts(); }
catch (Throwable $e) { $summit = null; }

$kinds = [
    ['Town halls',        'Open sessions where members and the team think out loud about the work and what’s next.'],
    ['The Annual Gala',   'Our flagship celebration of the movement — partners, mentors and the young leaders we serve.'],
    ['Programme expos',   'Showcases from Techome, MediaPro, Africa GATES and the Academy — built in the open.'],
    ['Community meetups',  'Local gatherings across Lagos chapters — volunteer, organise, and show up for the centres.'],
    ['Workshops',         'Hands-on skill sessions in technology, creativity, civics and leadership.'],
    ['Street-To-Stardom', 'Talent, mentorship and opportunity for young people — on the streets where they are.'],
];

render_head([
    'title'     => 'Events & gatherings — Afrovanguard',
    'desc'      => 'Town halls, the Annual Gala, programme expos, workshops and community meetups across the Afrovanguard movement. See what’s coming up and join us.',
    'canonical' => $canonical,
    'keywords'  => 'Afrovanguard events, Lagos nonprofit events, community meetups, gala, town hall',
    'css'       => ['/events/events.css'],
]);
render_nav('about');
?>
  <main id="main-content" class="ev-main">

    <header class="ev-hero">
      <div class="container">
        <span class="ev-kicker">Events &amp; gatherings</span>
        <h1>Where the movement meets.</h1>
        <p class="ev-lede">From open town halls to the Annual Gala, our events are where members, mentors and the young leaders we serve come together. Public events are listed here and announced in the community.</p>
        <div class="ev-cta-row">
          <a class="btn btn-primary" href="/portal/?space=events#community">See community announcements</a>
          <a class="btn btn-outline" href="<?= e($S) ?>/contact.html">Host or partner with us</a>
        </div>
      </div>
    </header>

<?php if ($summit): $sv = $summit['venue']; $slive = Summit::isLive(); ?>
    <section class="ev-featured" aria-labelledby="ev-summit-h">
      <div class="container ev-featured-inner">
        <div class="ev-featured-body">
          <span class="ev-featured-kicker">
<?php if ($slive): ?>            <span class="ev-featured-dot" aria-hidden="true"></span> Happening now
<?php else: ?>            <?= e($summit['presenter']) ?> presents <?= e($summit['edition']) ?>
<?php endif; ?>
          </span>
          <h2 id="ev-summit-h"><?= e($summit['name']) ?></h2>
          <p class="ev-featured-triad"><?= e($summit['triad']) ?></p>
          <p class="ev-featured-lede"><?= e($summit['lede']) ?></p>
          <dl class="ev-featured-facts">
            <div><dt>Dates</dt><dd><?= e($summit['date_label']) ?></dd></div>
            <div><dt>Venue</dt><dd><?= e($sv['name']) ?>, <?= e($sv['area']) ?>, <?= e($sv['city']) ?></dd></div>
            <div><dt>Pass</dt><dd><?= e($summit['pass']['label']) ?> · <?= e($summit['pass']['note']) ?></dd></div>
          </dl>
          <div class="ev-cta-row">
            <a class="ev-featured-btn" href="/academy/dns/#register">Claim your seat at <?= e($summit['edition']) ?></a>
            <a class="ev-featured-btn ev-featured-btn-ghost" href="/academy/dns/">Full summit details</a>
          </div>
        </div>
      </div>
    </section>
<?php endif; ?>

<?php if ($calEmbed !== ''): ?>
    <section class="ev-section container" aria-labelledby="ev-cal-h">
      <h2 id="ev-cal-h" class="ev-h">Upcoming — our calendar</h2>
      <p class="ev-sub">Straight from the Afrovanguard team calendar. Add it to your own to never miss a date.</p>
      <div class="ev-calendar">
        <iframe src="<?= e($calEmbed) ?>" title="Afrovanguard events calendar" loading="lazy" style="border:0" width="100%" height="600" frameborder="0" scrolling="no"></iframe>
      </div>
    </section>
<?php endif; ?>

<?php if ($announcements): ?>
    <section class="ev-section container" aria-labelledby="ev-ann-h">
      <h2 id="ev-ann-h" class="ev-h">Latest from the Events space</h2>
      <p class="ev-sub">Recent announcements from the community. <a href="/portal/?space=events#community">Open the Events space →</a></p>
      <ul class="ev-ann-list" role="list">
<?php foreach ($announcements as $a): ?>
        <li class="ev-ann">
          <p class="ev-ann-body"><?= e($a['body']) ?><?= mb_strlen($a['body']) >= 220 ? '…' : '' ?></p>
          <p class="ev-ann-meta"><?= e($a['who']) ?><?php if ($a['when'] !== ''): ?> · <time datetime="<?= e($a['when']) ?>"><?= e(substr($a['when'], 0, 10)) ?></time><?php endif; ?></p>
        </li>
<?php endforeach; ?>
      </ul>
    </section>
<?php endif; ?>

    <section class="ev-section container" aria-labelledby="ev-kinds-h">
      <h2 id="ev-kinds-h" class="ev-h">What we host</h2>
      <p class="ev-sub">A rhythm of gatherings through the year — most are free and open. Watch this page and the community for dates.</p>
      <div class="ev-grid">
<?php foreach ($kinds as $k): ?>
        <article class="ev-card">
          <h3><?= e($k[0]) ?></h3>
          <p><?= e($k[1]) ?></p>
        </article>
<?php endforeach; ?>
      </div>
    </section>

    <section class="ev-cta-band">
      <div class="container">
        <h2>Bringing people together for good?</h2>
        <p>Host an event with us, partner on a programme, or volunteer at the next gathering.</p>
        <div class="ev-cta-row">
          <a class="btn btn-primary" href="<?= e(AV_VOLUNTEER_URL) ?>">Volunteer with us</a>
          <a class="btn btn-ghost" href="<?= e($S) ?>/contact.html">Get in touch</a>
        </div>
      </div>
    </section>

  </main>
<?php render_footer(); ?>
