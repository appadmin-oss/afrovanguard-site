<?php
/**
 * academy/dns/index.php — D'Vanguard National Summit (DNS '26) landing page.
 *
 * Afrovanguard's national summit: 1–4 September 2026, Effortwill Schools,
 * Ejigbo, Lagos. Everything the page says lives in the $SUMMIT / $PILLARS /
 * $SESSIONS / $SPEAKERS / $FAQ arrays directly below — edit those, not the
 * markup. Seat claims are the only part that touches a database (lib/Summit.php).
 *
 * Lives in a real directory so the Academy's course catch-all serves it at
 * /academy/dns/ (see academy/.htaccess), the same arrangement as /academy/ngv/.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

Sitemap::ensureFresh();

/* ══════════════════════════════════════════════════════════════════════════
   CONTENT — the whole summit, in one editable block.
   ══════════════════════════════════════════════════════════════════════════ */

// The summit's facts (dates, venue, pass, contact) are shared with the home
// page's events rail, the events page and the Academy catalogue, so they live in
// lib/Summit.php. Everything below — the pillars, sessions, speakers and FAQ —
// is page-only content and is edited right here.
$SUMMIT = Summit::facts();
$SUMMIT['og_image'] = '/academy/dns/og.png';   // generated; see academy/dns/og.php

/* The three-word theme, expanded. */
$PILLARS = [
    ['word' => 'Master', 'n' => '01',
     'text' => 'Influence is earned, not announced. Learn how to build a community that listens, moves and stays — and how to keep leading it once it grows past the people you know personally.'],
    ['word' => 'Tame', 'n' => '02',
     'text' => 'Every space has a quiet compromise everyone has agreed not to name. We name it — and work through the systems, habits and boundaries that make integrity survivable in the room you actually work in.'],
    ['word' => 'Own', 'n' => '03',
     'text' => 'Stop renting your future. Own the audience, the asset, the process and the upside of what you build, so the work compounds for you instead of for a platform.'],
];

/* Sessions — the flyer's feature list. Titles are the summit's; the one-line
   descriptions are page copy. */
$SESSIONS = [
    ['Master your community',            'Build, grow and hold a community that trusts you — and turn that trust into real momentum.'],
    ['Tame the corruption in your space', 'Practical integrity: how to refuse the quiet compromise without losing the room or the opportunity.'],
    ['Own everything you build',          'Ownership over access — your audience, your data, your work and the upside that comes with it.'],
    ['Enterprise marketing',              'Marketing that moves an enterprise, not just a post: positioning, offer and repeatable demand.'],
    ['Mastering automation for business',  'Put the repeatable parts of your business on rails so your hours go to the work only you can do.'],
    ['Creating AI videos',                'Produce video with AI tools end to end — from idea and script to a finished, publishable cut.'],
    ['Content mastery',                   'A content practice you can sustain: what to make, how often, and how to make it count.'],
];

/* People, in the order they appear on the flyer. `photo` is optional — leave it
   empty and the card renders a designed monogram instead of a broken image. */
$SPEAKERS = [
    ['name' => 'Adesola Aladesawe', 'role' => 'Speaker · Communication', 'tag' => 'Speaker',  'tag_cls' => 'dns-tag-lime', 'photo' => '', 'lead' => false],
    ['name' => 'Ujagbe Onofua',     'role' => 'Speaker · Enterprise',    'tag' => 'Speaker',  'tag_cls' => 'dns-tag-red',  'photo' => '', 'lead' => false],
    ['name' => 'Van. Babatunde Adeola', 'role' => 'Convener',            'tag' => 'Convener', 'tag_cls' => 'dns-tag-gold', 'photo' => '', 'lead' => true],
];

$FAQ = [
    ['Who is DNS &rsquo;26 for?',
     'Founders, creators, community leaders, professionals and students who are building something and want it to last. If you lead people — online or in a room — the four days are built for you.'],
    ['What does the pass cover?',
     'One pass is $30 and covers all four days of the summit at Effortwill Schools, Ejigbo. Claim your seat below and the team will confirm your place and payment details with you directly.'],
    ['How do I pay?',
     'Payment is confirmed after you claim your seat — our team reaches out by email or on WhatsApp (+234 903 777 6318) with the details. Nothing is charged on this page.'],
    ['Can I register a group or a team?',
     'Yes. Put the number of seats you need on the form and tell us who they are in the message box, and we will hold them together.'],
    ['Is the summit in person?',
     'Yes — DNS &rsquo;26 is an in-person summit at Effortwill Schools, Ejigbo, Lagos, running from 9:00 AM.'],
    ['What does Master | Tame | Own mean?',
     'It is the spine of the whole programme: master the community you lead, tame the corruption in the space you occupy, and own what you build. Every session sits under one of the three.'],
];

/* ══════════════════════════════════════════════════════════════════════════
   Derived state
   ══════════════════════════════════════════════════════════════════════════ */

$S      = rtrim(SITE_URL, '/');
$canon  = $S . '/academy/dns/';
$venue  = $SUMMIT['venue'];
$pass   = $SUMMIT['pass'];
$wa     = $SUMMIT['whatsapp'];
$waUrl  = 'https://wa.me/' . $wa['e164'];
$venueLine = $venue['name'] . ', ' . $venue['area'] . ', ' . $venue['city'];

$shareText = $SUMMIT['name'] . ' (' . $SUMMIT['edition'] . ') — ' . $SUMMIT['triad']
           . '. ' . $SUMMIT['date_label'] . ', ' . $venueLine . '.';
$shareUrl  = $canon;
$share = [
    ['WhatsApp', 'https://wa.me/?text=' . rawurlencode($shareText . ' ' . $shareUrl)],
    ['X',        'https://twitter.com/intent/tweet?text=' . rawurlencode($shareText) . '&url=' . rawurlencode($shareUrl)],
    ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($shareUrl)],
    ['LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($shareUrl)],
];

$isPast = Summit::isPast();
$isLive = Summit::isLive();
$isOpen = Summit::isOpen();   // seat claims close once the summit has ended

/** Initials for the monogram fallback, skipping honorifics like "Van.". */
$initials = static function (string $name): string {
    $out = '';
    foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) {
        if ($part === '' || str_ends_with($part, '.')) continue;   // "Van." is a title
        $out .= mb_strtoupper(mb_substr($part, 0, 1));
        if (mb_strlen($out) >= 2) break;
    }
    return $out !== '' ? $out : '·';
};

/* ══════════════════════════════════════════════════════════════════════════
   Seat claim — public, unauthenticated intake.
   Same defenses as the other anonymous forms on the site (process-contact.php,
   academy/ngv/register.php): honeypot, same-origin, per-IP rate limit. No CSRF
   token, because there is no session to ride and no account to act on.
   ══════════════════════════════════════════════════════════════════════════ */

/** Acknowledge the registrant and alert staff. Best-effort — the seat is
 *  already saved, so a mail hiccup must never surface as a failure. */
function dns_notify_registration(int $id, array $d, array $summit): void
{
    if (!class_exists('Mailer')) return;
    $esc   = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $name  = trim((string) ($d['name'] ?? '')) ?: 'there';
    $first = $esc(explode(' ', $name)[0]);
    $email = trim((string) ($d['email'] ?? ''));
    $v     = $summit['venue'];
    $where = $esc($v['name'] . ', ' . $v['area'] . ', ' . $v['city']);
    $when  = $esc($summit['date_label']);
    $wa    = $esc($summit['whatsapp']['display']);
    $seats = max(1, (int) ($d['seats'] ?? 1));

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $html = "<p>Hi {$first},</p>"
              . '<p>Your seat at the <b>' . $esc($summit['name']) . ' (' . $esc($summit['edition']) . ')</b> is reserved.</p>'
              . "<p><b>When:</b> {$when}, from " . $esc($summit['time_label']) . '<br>'
              . "<b>Where:</b> {$where}<br>"
              . '<b>Seats held:</b> ' . $seats . '<br>'
              . '<b>Pass:</b> ' . $esc($summit['pass']['label']) . ' — ' . $esc($summit['pass']['note']) . '</p>'
              . "<p><b>Next step:</b> our team will confirm your place and share payment details. If you would rather sort it out now, message us on WhatsApp at {$wa}.</p>"
              . '<p>Master. Tame. Own.<br>— Afrovanguard</p>';
        try { Mailer::send($email, 'Your seat at ' . $summit['edition'] . ' is reserved', $html); } catch (Throwable $e) {}
    }

    $admin = defined('ADMIN_EMAIL') && ADMIN_EMAIL ? (string) ADMIN_EMAIL
           : (defined('FROM_EMAIL') ? (string) FROM_EMAIL : '');
    if ($admin === '') return;
    $rows = '';
    foreach (['name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'location' => 'Location',
              'organisation' => 'Organisation', 'pillar' => 'Pillar', 'heard' => 'Heard via',
              'message' => 'Message'] as $k => $label) {
        $val = trim((string) ($d[$k] ?? ''));
        if ($val !== '') $rows .= '<tr><td><b>' . $label . '</b></td><td>' . $esc($val) . '</td></tr>';
    }
    $html = '<p>New ' . $esc($summit['edition']) . " seat claim (#{$id}) — {$seats} seat(s).</p>"
          . '<table cellpadding="6" border="0">' . $rows . '</table>';
    try { Mailer::send($admin, 'DNS ' . $summit['edition'] . " seat claim #{$id}", $html); } catch (Throwable $e) {}
}

$sent = false;
$dupe = false;
$err  = '';
$old  = ['name' => '', 'email' => '', 'phone' => '', 'location' => '', 'organisation' => '',
         'pillar' => '', 'seats' => '1', 'heard' => '', 'message' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_same_origin();
    foreach ($old as $k => $_) $old[$k] = (string) ($_POST[$k] ?? $old[$k]);

    $hp      = trim((string) ($_POST['website'] ?? ''));                       // honeypot
    $ip      = function_exists('av_client_ip') ? av_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0');
    $limited = function_exists('av_rate_ok') && !av_rate_ok('dns_seat_' . $ip, 5, 900);  // 5 / 15 min
    $email   = trim($old['email']);

    if (!$isOpen) {
        $err = 'Registration for this edition has closed.';
    } elseif ($hp !== '') {
        $sent = true;                       // absorb bots: looks successful, stores nothing
    } elseif ($limited) {
        $err = 'You have submitted a few times already. Please wait a little while, or message us on WhatsApp.';
    } elseif (trim($old['name']) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter your full name and a valid email address.';
    } elseif (Summit::alreadyRegistered($email)) {
        $sent = true;
        $dupe = true;                       // already holds a seat — reassure, don't duplicate
    } else {
        $id = Summit::register($old + ['source' => 'dns-page']);
        if ($id > 0) {
            $sent = true;
            try { dns_notify_registration($id, $old, $SUMMIT); } catch (Throwable $e) {}
            if (class_exists('Events')) {
                try { Events::emit('summit.registration', ['id' => $id, 'edition' => Summit::EDITION]); } catch (Throwable $e) {}
            }
        } else {
            $err = 'Something went wrong saving your seat. Please try again, or message us on WhatsApp.';
        }
    }
}

/* Social proof only once it means something — an empty counter says nothing good. */
$claimed = Summit::seatsClaimed();
$showClaimed = $claimed >= 25;

/* ══════════════════════════════════════════════════════════════════════════
   SEO / structured data
   ══════════════════════════════════════════════════════════════════════════ */

$ogImage = $SUMMIT['og_image'];
if ($ogImage !== '' && $ogImage[0] === '/') $ogImage = $S . $ogImage;
// Kept inside what search results actually render: ~60 characters of title and
// ~160 of description. Both lead with the name people will type, and carry the
// year, the city and the price — the things an event query is made of.
$title = $SUMMIT['name'] . ' 2026 (' . $SUMMIT['edition'] . ') — Afrovanguard';
$desc  = 'Afrovanguard\'s national summit, ' . $SUMMIT['date_short'] . ' at '
       . $venue['name'] . ', ' . $venue['area'] . ', ' . $venue['city']
       . '. Master your community, tame corruption, own what you build. Pass '
       . $pass['label'] . '.';

$performers = [];
foreach ($SPEAKERS as $sp) $performers[] = ['@type' => 'Person', 'name' => $sp['name']];

$eventSchema = [
    '@type'               => 'EducationEvent',
    'name'                => $SUMMIT['name'] . ' ' . $SUMMIT['edition'],
    'alternateName'       => $SUMMIT['edition'],
    'description'         => $SUMMIT['lede'],
    'startDate'           => $SUMMIT['starts'],
    'endDate'             => $SUMMIT['ends'],
    'eventStatus'         => 'https://schema.org/EventScheduled',
    'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
    'url'                 => $canon,
    'image'               => $ogImage,
    'inLanguage'          => 'en-NG',
    'location' => [
        '@type'   => 'Place',
        'name'    => $venue['name'],
        'address' => [
            '@type'           => 'PostalAddress',
            'streetAddress'   => $venue['area'],
            'addressLocality' => $venue['city'],
            'addressRegion'   => $venue['region'],
            'addressCountry'  => $venue['country'],
        ],
    ],
    'isAccessibleForFree' => false,
    'audience' => ['@type' => 'Audience',
                   'audienceType' => 'Founders, creators, community leaders, professionals and students'],
    'organizer' => ['@type' => 'Organization', 'name' => 'Afrovanguard',
                    '@id' => $S . '/#organization', 'url' => $S . '/'],
    'performer' => $performers,
    'offers'    => [
        '@type'         => 'Offer',
        'name'          => 'Summit pass',
        'price'         => (string) $pass['amount'],
        'priceCurrency' => $pass['currency'],
        'availability'  => $isOpen ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
        'url'           => $canon . '#register',
        'validThrough'  => $SUMMIT['ends'],
    ],
];

$faqSchema = ['@type' => 'FAQPage', 'mainEntity' => array_map(
    static fn(array $f) => [
        '@type'          => 'Question',
        'name'           => html_entity_decode(strip_tags($f[0]), ENT_QUOTES, 'UTF-8'),
        'acceptedAnswer' => ['@type' => 'Answer',
                             'text'  => html_entity_decode(strip_tags($f[1]), ENT_QUOTES, 'UTF-8')],
    ], $FAQ)];

$crumbs = schema_breadcrumb([
    ['name' => 'Home',    'url' => $S . '/'],
    ['name' => 'Academy', 'url' => $S . '/academy/'],
    ['name' => $SUMMIT['edition'], 'url' => $canon],
]);

render_head([
    'title'      => $title,
    'desc'       => $desc,
    'canonical'  => $canon,
    'og_kind'    => 'website',
    'image'      => $ogImage,
    'image_alt'  => $SUMMIT['name'] . ' ' . $SUMMIT['edition'] . ' — ' . $SUMMIT['triad'],
    'keywords'   => "D'Vanguard National Summit, DNS 26, Afrovanguard summit, Lagos summit 2026, Ejigbo, leadership summit Nigeria, enterprise marketing, AI video, content mastery",
    'jsonld'     => [schema_org(), $eventSchema, $faqSchema, $crumbs],
    'css'        => ['/academy/academy.css', '/academy/dns.css'],
    'body_class' => 'academy dns',
    'slug'       => 'academy-dns',
]);
render_nav('academy');
?>
<main id="main-content">

  <!-- ── Hero ───────────────────────────────────────────────────────────── -->
  <section class="dns-hero dns-on-dark">
    <div class="dns-wrap">
      <div class="dns-hero-grid">
        <div>
          <p class="dns-presents"><b><?= e($SUMMIT['presenter']) ?></b> presents <?= e($SUMMIT['edition']) ?></p>
          <h1><span>D&rsquo;Vanguard</span><span>National</span><span class="dns-grad">Summit</span></h1>
          <p class="dns-triad"><?= e($SUMMIT['triad']) ?></p>
          <p class="dns-hero-sub"><?= e($SUMMIT['lede']) ?></p>

<?php if ($isPast): ?>
          <div class="dns-cta-row">
            <a class="dns-btn dns-btn-ghost dns-btn-lg" href="/academy/">Explore the Academy</a>
            <a class="dns-btn dns-btn-ghost dns-btn-lg" href="<?= e($waUrl) ?>" rel="noopener">Ask about the next edition</a>
          </div>
          <p class="dns-hero-note"><?= e($SUMMIT['edition']) ?> has ended. Thank you to everyone who came.</p>
<?php else: ?>
          <div class="dns-cta-row">
            <a class="dns-btn dns-btn-primary dns-btn-lg" href="#register">Claim your seat</a>
            <a class="dns-btn dns-btn-ghost dns-btn-lg" href="<?= e($waUrl) ?>" rel="noopener">WhatsApp us</a>
          </div>
          <p class="dns-hero-note">
            <?= e($pass['label']) ?> &middot; <?= e($pass['note']) ?> &middot;
            <a href="<?= e($waUrl) ?>" rel="noopener"><?= e($wa['display']) ?></a>
<?php if ($showClaimed): ?>            &middot; <?= number_format($claimed) ?> seats claimed
<?php endif; ?>
          </p>
<?php endif; ?>
        </div>
        <p class="dns-badge"><?= e($SUMMIT['edition']) ?></p>
      </div>

<?php if ($isLive && !$isPast): ?>
      <p class="dns-count-live"><span class="dns-count-dot" aria-hidden="true"></span> Happening now in Ejigbo</p>
<?php elseif (!$isPast): ?>
      <!-- Countdown: the server-rendered date is the fallback; JS upgrades it. -->
      <div class="dns-count" id="dns-count" data-start="<?= e($SUMMIT['starts']) ?>" aria-live="off">
        <p class="dns-count-l" style="letter-spacing:.18em;align-self:center">
          Doors open <?= e($SUMMIT['date_label']) ?>, <?= e($SUMMIT['time_label']) ?>
        </p>
      </div>
<?php endif; ?>

      <dl class="dns-facts">
        <div class="dns-fact"><dt>Dates</dt><dd><?= e($SUMMIT['date_label']) ?><small><?= e($SUMMIT['days']) ?>, from <?= e($SUMMIT['time_label']) ?></small></dd></div>
        <div class="dns-fact"><dt>Venue</dt><dd><?= e($venue['name']) ?><small><?= e($venue['area']) ?>, <?= e($venue['city']) ?></small></dd></div>
        <div class="dns-fact"><dt>Pass</dt><dd><?= e($pass['label']) ?><small><?= e($pass['note']) ?></small></dd></div>
        <div class="dns-fact"><dt>Theme</dt><dd><?= e($SUMMIT['triad']) ?><small>Four days of work, one idea</small></dd></div>
      </dl>
    </div>
  </section>

  <!-- ── The three words ────────────────────────────────────────────────── -->
  <section class="dns-section" id="theme">
    <div class="dns-wrap">
      <div class="dns-head dns-head--center">
        <span class="dns-kicker">The theme</span>
        <h2 class="dns-h2">Three words. Four days.</h2>
        <p class="dns-lead">Every session at <?= e($SUMMIT['edition']) ?> sits under one of these three. They are not slogans — they are the order the work happens in.</p>
      </div>
      <div class="dns-pillars">
<?php foreach ($PILLARS as $p): ?>
        <article class="dns-pillar" data-reveal>
          <span class="dns-pillar-n"><?= e($p['n']) ?></span>
          <h3><?= e($p['word']) ?></h3>
          <p><?= e($p['text']) ?></p>
        </article>
<?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ── Sessions ───────────────────────────────────────────────────────── -->
  <section class="dns-section dns-section--alt" id="sessions">
    <div class="dns-wrap">
      <div class="dns-head">
        <span class="dns-kicker">What you&rsquo;ll work on</span>
        <h2 class="dns-h2">Seven sessions you can use on Monday.</h2>
        <p class="dns-lead">No abstractions. Each session ends with something you can apply to the community, business or body of work you already have.</p>
      </div>
      <div class="dns-sessions">
<?php foreach ($SESSIONS as [$stitle, $sdesc]): ?>
        <article class="dns-session" data-reveal>
          <span class="dns-session-mark" aria-hidden="true"></span>
          <div>
            <h3><?= e($stitle) ?></h3>
            <p><?= e($sdesc) ?></p>
          </div>
        </article>
<?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ── Speakers ───────────────────────────────────────────────────────── -->
  <section class="dns-section dns-section--dark" id="speakers">
    <div class="dns-wrap">
      <div class="dns-head">
        <span class="dns-kicker">On the stage</span>
        <h2 class="dns-h2">Who you&rsquo;ll hear from.</h2>
        <p class="dns-lead">Convened by Afrovanguard, with speakers working in the rooms they teach about.</p>
      </div>
      <div class="dns-speakers">
<?php foreach ($SPEAKERS as $sp): ?>
        <article class="dns-speaker<?= !empty($sp['lead']) ? ' dns-speaker--lead' : '' ?>" data-reveal>
          <div class="dns-portrait">
<?php if (!empty($sp['photo'])): ?>
            <img src="<?= e($sp['photo']) ?>" alt="<?= e($sp['name']) ?>, <?= e($sp['role']) ?>" loading="lazy" width="360" height="480">
<?php else: ?>
            <span class="dns-monogram" aria-hidden="true"><?= e($initials($sp['name'])) ?></span>
<?php endif; ?>
          </div>
          <span class="dns-tag <?= e($sp['tag_cls']) ?>"><?= e($sp['name']) ?></span>
          <p class="dns-speaker-role"><?= e($sp['role']) ?></p>
        </article>
<?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ── Details ────────────────────────────────────────────────────────── -->
  <section class="dns-section" id="details">
    <div class="dns-wrap">
      <div class="dns-head">
        <span class="dns-kicker">The details</span>
        <h2 class="dns-h2">Where, when, how much.</h2>
      </div>
      <dl class="dns-details">
        <div class="dns-detail">
          <dt>Venue</dt>
          <dd><?= e($venue['name']) ?>
            <span><?= e($venue['area']) ?>, <?= e($venue['city']) ?>, <?= e($venue['region']) ?><br>
              <a href="<?= e($venue['map']) ?>" rel="noopener nofollow">Open in Maps &rarr;</a></span>
          </dd>
        </div>
        <div class="dns-detail">
          <dt>Dates &amp; time</dt>
          <dd><?= e($SUMMIT['date_label']) ?>
            <span><?= e($SUMMIT['days']) ?>, starting <?= e($SUMMIT['time_label']) ?> each day.</span>
          </dd>
        </div>
        <div class="dns-detail">
          <dt>Pass</dt>
          <dd><span class="dns-price"><?= e($pass['label']) ?></span>
            <span><?= e($pass['note']) ?>. Payment details are confirmed with you after you claim your seat.</span>
          </dd>
        </div>
        <div class="dns-detail">
          <dt>Put it in your diary</dt>
          <dd><a href="/academy/dns/summit.ics">Add to calendar &darr;</a>
            <span>Downloads a calendar invitation that works in Google Calendar, Apple Calendar and Outlook.</span>
          </dd>
        </div>
        <div class="dns-detail">
          <dt>Talk to a human</dt>
          <dd><a href="<?= e($waUrl) ?>" rel="noopener"><?= e($wa['display']) ?></a>
            <span>WhatsApp for anything urgent, or use our
              <a href="/contact.html">contact page</a> for partnerships, sponsorship and group bookings.</span>
          </dd>
        </div>
      </dl>
    </div>
  </section>

  <!-- ── Register ───────────────────────────────────────────────────────── -->
  <section class="dns-section dns-section--dark" id="register">
    <div class="dns-wrap">
      <div class="dns-form-grid">
        <div class="dns-form-aside">
          <span class="dns-kicker">Claim your seat</span>
          <h2 class="dns-h2"><?= $isPast ? 'This edition has ended.' : 'Take a seat at DNS &rsquo;26.' ?></h2>
<?php if ($isPast): ?>
          <p><?= e($SUMMIT['name']) ?> <?= e($SUMMIT['edition']) ?> ran <?= e($SUMMIT['date_label']) ?>. Registration is closed — message us on WhatsApp or through the contact page to hear about the next edition first.</p>
          <div class="dns-cta-row" style="margin-top:26px">
            <a class="dns-btn dns-btn-lime" href="<?= e($waUrl) ?>" rel="noopener">WhatsApp us</a>
            <a class="dns-btn dns-btn-ghost" href="/contact.html">Contact the team</a>
          </div>
<?php else: ?>
          <p>Tell us who you are and we will hold your place. The pass is <?= e($pass['label']) ?> for all four days — our team confirms payment with you directly afterwards.</p>
          <ul class="dns-checklist">
            <li>All seven sessions across the four days</li>
            <li><?= e($venueLine) ?>, from <?= e($SUMMIT['time_label']) ?></li>
            <li>Group seats held together — just say how many</li>
            <li>Prefer to talk first? <a href="/contact.html" style="color:var(--dns-gold)">Use the contact page</a></li>
          </ul>
<?php endif; ?>
        </div>

<?php if (!$isPast): ?>
        <div class="dns-form">
<?php if ($sent): ?>
          <div class="dns-done">
            <div class="dns-done-mark" aria-hidden="true">&#10003;</div>
<?php if ($dupe): ?>
            <h3>You already have a seat.</h3>
            <p>We found <?= e(trim($old['email'])) ?> on the <?= e($SUMMIT['edition']) ?> list already — no need to register twice. If anything has changed, message us on WhatsApp and we will update it.</p>
<?php else: ?>
            <h3>Your seat is reserved.</h3>
            <p>Thank you<?= trim($old['name']) !== '' ? ', ' . e(trim(explode(' ', trim($old['name']))[0])) : '' ?> — you are on the <?= e($SUMMIT['edition']) ?> list. We have sent a confirmation<?= trim($old['email']) !== '' ? ' to ' . e(trim($old['email'])) : '' ?>, and our team will follow up with payment details.</p>
<?php endif; ?>
            <p><b><?= e($SUMMIT['date_label']) ?></b><br><?= e($venueLine) ?>, <?= e($SUMMIT['time_label']) ?></p>
            <div class="dns-cta-row" style="justify-content:center;margin-top:22px">
              <a class="dns-btn dns-btn-lime" href="<?= e($waUrl) ?>" rel="noopener">Message us on WhatsApp</a>
            </div>
          </div>
<?php else: ?>
<?php if ($err !== ''): ?>          <p class="dns-alert dns-alert-err" role="alert"><?= e($err) ?></p>
<?php endif; ?>
          <form method="post" action="/academy/dns/#register" novalidate>
            <div class="dns-hp" aria-hidden="true">
              <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            <div class="dns-row2">
              <div class="dns-fld">
                <label for="dns-name">Full name <span class="dns-req" aria-hidden="true">*</span></label>
                <input id="dns-name" type="text" name="name" required maxlength="120" autocomplete="name" value="<?= e($old['name']) ?>">
              </div>
              <div class="dns-fld">
                <label for="dns-email">Email <span class="dns-req" aria-hidden="true">*</span></label>
                <input id="dns-email" type="email" name="email" required maxlength="160" autocomplete="email" value="<?= e($old['email']) ?>">
              </div>
            </div>

            <div class="dns-row2">
              <div class="dns-fld">
                <label for="dns-phone">Phone / WhatsApp</label>
                <input id="dns-phone" type="tel" name="phone" maxlength="40" autocomplete="tel" placeholder="+234…" value="<?= e($old['phone']) ?>">
              </div>
              <div class="dns-fld">
                <label for="dns-location">Where you&rsquo;re coming from</label>
                <input id="dns-location" type="text" name="location" maxlength="120" placeholder="e.g. Ejigbo, Lagos" value="<?= e($old['location']) ?>">
              </div>
            </div>

            <div class="dns-row2">
              <div class="dns-fld">
                <label for="dns-org">Business, brand or organisation</label>
                <input id="dns-org" type="text" name="organisation" maxlength="160" autocomplete="organization" value="<?= e($old['organisation']) ?>">
              </div>
              <div class="dns-fld">
                <label for="dns-seats">Seats</label>
                <input id="dns-seats" type="number" name="seats" min="1" max="20" step="1" inputmode="numeric" value="<?= e($old['seats'] !== '' ? $old['seats'] : '1') ?>">
                <p class="dns-hint">Booking for a team? Name them in the message.</p>
              </div>
            </div>

            <div class="dns-row2">
              <div class="dns-fld">
                <label for="dns-pillar">What brought you</label>
                <select id="dns-pillar" name="pillar">
                  <option value="">&mdash; All three &mdash;</option>
<?php foreach (Summit::PILLARS as $pl): ?>
                  <option value="<?= e($pl) ?>"<?= $old['pillar'] === $pl ? ' selected' : '' ?>><?= e($pl) ?></option>
<?php endforeach; ?>
                </select>
              </div>
              <div class="dns-fld">
                <label for="dns-heard">How did you hear about DNS &rsquo;26?</label>
                <select id="dns-heard" name="heard">
<?php $HEARD = ['', 'Instagram', 'WhatsApp', 'Facebook', 'X (Twitter)', 'A friend', 'Afrovanguard', 'Other'];
      foreach ($HEARD as $h): ?>
                  <option value="<?= e($h) ?>"<?= $old['heard'] === $h ? ' selected' : '' ?>><?= $h === '' ? '— Select —' : e($h) ?></option>
<?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="dns-fld">
              <label for="dns-message">Anything we should know?</label>
              <textarea id="dns-message" name="message" maxlength="1500" placeholder="Access needs, who you&rsquo;re bringing, what you want to walk away with…"><?= e($old['message']) ?></textarea>
            </div>

            <button class="dns-btn dns-btn-primary dns-btn-lg" type="submit">Claim my seat</button>
            <p class="dns-hint" style="text-align:center;margin-top:14px">
              We only use this to hold your seat and reach you about <?= e($SUMMIT['edition']) ?>.
              See our <a href="/privacy-policy/">privacy policy</a>.
            </p>
          </form>
<?php endif; ?>
        </div>
<?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ── FAQ ────────────────────────────────────────────────────────────── -->
  <section class="dns-section" id="faq">
    <div class="dns-wrap dns-narrow">
      <div class="dns-head">
        <span class="dns-kicker">Questions</span>
        <h2 class="dns-h2">Before you come.</h2>
      </div>
      <div class="dns-faq">
<?php foreach ($FAQ as $i => [$q, $a]): ?>
        <details<?= $i === 0 ? ' open' : '' ?>>
          <summary><?= $q ?></summary>
          <p><?= $a ?></p>
        </details>
<?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ── Share ──────────────────────────────────────────────────────────── -->
  <section class="dns-section dns-section--alt" id="share">
    <div class="dns-wrap dns-narrow">
      <div class="dns-head dns-head--center">
        <span class="dns-kicker">Spread the word</span>
        <h2 class="dns-h2">Someone you know needs this room.</h2>
        <p class="dns-lead">The summit grows the way it always has — one person telling another. Send it on.</p>
      </div>
      <div class="dns-share">
<?php foreach ($share as [$label, $href]): ?>
        <a class="dns-share-btn" href="<?= e($href) ?>" target="_blank" rel="noopener"
           aria-label="Share <?= e($SUMMIT['edition']) ?> on <?= e($label) ?>"><?= e($label) ?></a>
<?php endforeach; ?>
        <a class="dns-share-btn" href="/academy/dns/summit.ics">Add to calendar</a>
      </div>
      <p class="dns-share-link">
        <label for="dns-url">Or copy the link</label>
        <input id="dns-url" type="text" value="<?= e($shareUrl) ?>" readonly
               onfocus="this.select()" aria-describedby="dns-url-note">
        <span id="dns-url-note" class="dns-hint">Select the box to copy the address.</span>
      </p>
    </div>
  </section>

  <!-- ── Closing band ───────────────────────────────────────────────────── -->
  <section class="dns-closer dns-on-dark">
    <div class="dns-wrap">
      <span class="dns-kicker">Afrovanguard &middot; <?= e($SUMMIT['edition']) ?></span>
      <h2>Master. Tame. Own.</h2>
      <p><?= $isPast
          ? 'The next edition is already being built. Be first to know when seats open.'
          : e($SUMMIT['date_label']) . ' &middot; ' . e($venueLine) ?></p>
      <div class="dns-cta-row">
<?php if ($isPast): ?>
        <a class="dns-btn dns-btn-lime dns-btn-lg" href="<?= e($waUrl) ?>" rel="noopener">Tell me about the next one</a>
        <a class="dns-btn dns-btn-ghost dns-btn-lg" href="/academy/">Explore the Academy</a>
<?php else: ?>
        <a class="dns-btn dns-btn-primary dns-btn-lg" href="#register">Claim your seat &mdash; <?= e($pass['label']) ?></a>
        <a class="dns-btn dns-btn-ghost dns-btn-lg" href="<?= e($waUrl) ?>" rel="noopener">WhatsApp <?= e($wa['display']) ?></a>
<?php endif; ?>
      </div>
    </div>
  </section>

</main>

<?php if (!$isPast && !$isLive): ?>
<script>
/* Countdown to the summit. Progressive: the server already rendered the date,
   so with JS off (or if anything here throws) the fallback line stands. */
(function () {
  var box = document.getElementById('dns-count');
  if (!box) return;
  var start = Date.parse(box.getAttribute('data-start') || '');
  if (isNaN(start)) return;

  var UNITS = [['Days', 864e5], ['Hours', 36e5], ['Minutes', 6e4], ['Seconds', 1e3]];
  var nodes = [];
  var frag = document.createDocumentFragment();
  UNITS.forEach(function (u) {
    var d = document.createElement('div');
    d.className = 'dns-count-unit';
    var n = document.createElement('span'); n.className = 'dns-count-n'; n.textContent = '—';
    var l = document.createElement('span'); l.className = 'dns-count-l'; l.textContent = u[0];
    d.appendChild(n); d.appendChild(l); frag.appendChild(d); nodes.push(n);
  });
  box.textContent = '';
  box.appendChild(frag);
  box.setAttribute('aria-label', 'Time until the summit begins');

  var timer = setInterval(tick, 1000);
  tick();

  function tick() {
    var left = start - Date.now();
    if (left <= 0) { clearInterval(timer); location.reload(); return; }
    UNITS.forEach(function (u, i) {
      var v = Math.floor(left / u[1]);
      left -= v * u[1];
      nodes[i].textContent = v < 10 ? '0' + v : String(v);
    });
  }
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
