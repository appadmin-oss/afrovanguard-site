<?php
/**
 * academy/index.php — Afrovanguard Academy catalogue (route /academy/).
 *
 * Rebuilt to the design "Afrovanguard Academy" inside the shared Home chrome.
 * Sections: hero (+ the summit band while the summit is ahead) → NextGen
 * Vanguard band → Courses (featured course, then the whole catalogue with track
 * filter, search and sort) → Projects link → Membership → Academy links → Get
 * involved. Data as v1: AcademyRepository (published courses, featured,
 * categories), LmsRepository (lesson + enrolment counts, a learner's progress,
 * membership), Summit::facts(). Membership checkout: academy/api.php pay_init.
 * Styles: academy/avac.css · behaviour: academy/avac.js.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/partials/avh-chrome.php';
require_once __DIR__ . '/_avac.php';

Sitemap::ensureFresh();
$repo = new AcademyRepository();
$lms  = new LmsRepository();
$courses = $repo->all();
$featured = $repo->featured();
$categories = $repo->categories();
$S = rtrim(SITE_URL, '/');
$canonical = $S . '/academy/';

/* The summit band runs while the summit is ahead of us; once it is over it
   disappears rather than advertising a past date. */
$summit = null;
try { if (class_exists('Summit') && !Summit::isPast()) $summit = Summit::facts(); }
catch (Throwable $e) { $summit = null; }

$user = LmsAuth::user();
$member = $user ? ($lms->isMember((int) $user['id']) || LmsAuth::isOrgMember($user)) : false;
$rows = avac_decorate($courses, $lms, $user);
$feat = null;
$grid = [];
foreach ($rows as $r) {
    if (!$feat && $featured && (int) $r['course']['id'] === (int) $featured['id']) { $feat = $r; continue; }
    $grid[] = $r;
}
/* The track chips only list tracks that still have a course in the grid. */
$gridCats = array_values(array_filter($categories, static function ($cat) use ($grid): bool {
    foreach ($grid as $r) { if ((string) $r['course']['category'] === (string) $cat) return true; }
    return false;
}));

$itemList = ['@type' => 'ItemList', 'itemListElement' => []];
foreach ($courses as $i => $c) {
    $itemList['itemListElement'][] = ['@type' => 'ListItem', 'position' => $i + 1,
        'url' => $S . '/academy/' . $c['slug'] . '/', 'name' => $c['title']];
}
$crumbs = schema_breadcrumb([['name' => 'Home', 'url' => $S . '/'], ['name' => 'Academy', 'url' => $canonical]]);

avac_head([
    'title' => 'Afrovanguard Academy — Free programmes for young Africans',
    'desc'  => 'Technology, creative, and leadership programmes raising one million incorruptible leaders for Africa. Learn, build, and lead with Afrovanguard.',
    'canonical' => $canonical,
    'image' => $featured ? $S . '/academy/og/' . $featured['slug'] . '.png' : null,
    'keywords' => 'Afrovanguard Academy, NextGen Vanguard, free training Lagos, skills training Alimosho, Egbeda youth programme, NYSC skills Nigeria, learn and earn Nigeria, Techome, MediaPro, Africa GATES, digital marketing training Lagos, tech internship Lagos, youth programmes Nigeria',
    'jsonld' => [schema_org(), schema_website(), $itemList, $crumbs],
    'css' => ['/assets/site/avh.css', '/academy/avac.css'],
    'js'  => ['/assets/site/avh.js', '/academy/avac.js'],
]);
$fc = $feat['course'] ?? null;
$projects = [
    ['/Images/bootcamp1.png', 'Technology', 'Techome', '/projects/techhome/'],
    ['/Images/summer4.png', 'Arts & Media', 'MediaPro', '/projects/mediapro/'],
    ['/Images/culture1.png', 'Arts & Media', 'Street-To-Stardom', '/projects/sts/'],
    ['/Images/gates2.png', 'Leadership', 'Africa GATES', 'https://afg.afrovanguard.org.ng'],
];
?>
<body class="avh avac" id="top">
<a class="avh-skip" href="#main">Skip to content</a>
<div class="avh-page">
<?php avh_nav(); ?>
<main id="main" tabindex="-1">
<?php avac_pay_flash('avac-flash'); ?>

<header class="avac-hero avh-pad">
  <div class="avh-topo" data-avh-topo="light" data-seed="7" aria-hidden="true"></div>
  <div class="avh-wrap">
    <nav aria-label="Breadcrumb"><ol class="avac-crumbs"><li><a href="/">Home</a></li><li><span aria-current="page">Academy</span></li></ol></nav>
    <div class="avac-hero-grid">
      <h1>Learn. Build. Lead Africa.</h1>
      <div class="avac-hero-side">
        <p>Courses, certificates and leadership formation — the training behind one million incorruptible leaders by 2040.</p>
        <div class="avac-btns">
          <a class="avac-btn" href="#catalogue">Browse courses</a>
          <a class="avac-btn avac-btn--line" href="/academy/teach/">Teach with us</a>
        </div>
      </div>
    </div>
<?php if ($summit): $sv = $summit['venue']; ?>
    <a class="avac-summit" href="/academy/dns/">
      <span class="avac-summit-tag"><?= Summit::isLive() ? 'Happening now' : 'The summit' ?> · <?= e($summit['edition']) ?></span>
      <span class="avac-summit-t"><?= e($summit['name']) ?></span>
      <span class="avac-summit-d"><?= e($summit['date_label']) ?> · <?= e($sv['name']) ?>, <?= e($sv['area']) ?>, <?= e($sv['city']) ?> · <?= e($summit['pass']['label']) ?> for all four days</span>
      <span class="avac-summit-go">Claim your seat →</span>
    </a>
<?php endif; ?>
    <a class="avac-ngv" href="/academy/ngv/">
      <span class="avac-ngv-copy">
        <span class="avac-ngv-tag"><span class="avac-live" aria-hidden="true"></span>Flagship programme · Now enrolling</span>
        <span class="avac-ngv-h">NextGen Vanguard — stop scrolling, start earning.</span>
        <span class="avac-ngv-p">Future-ready tech, media, business &amp; leadership skills for young Africans — with weekly stipends, six global certifications and a real internship.</span>
        <span class="avac-ngv-pts">
          <span><b>Weekly</b><i>Stipends while you learn</i></span>
          <span><b>6</b><i>Global certifications</i></span>
          <span><b>Real</b><i>Internship placement</i></span>
        </span>
        <span class="avac-gold">Explore NextGen Vanguard →</span>
      </span>
      <img class="avac-ngv-img" src="/Images/gates2.png" alt="NextGen Vanguard cohort" loading="lazy" decoding="async">
    </a>
  </div>
</header>

<section class="avac-courses avh-pad" id="catalogue" aria-labelledby="avac-courses-h">
  <div class="avh-wrap">
    <div class="avac-head">
      <div><div class="avac-eyebrow">Courses</div><h2 class="avac-h2" id="avac-courses-h">Learn with the Academy</h2></div>
      <p>Structured courses with verifiable certificates. New courses are added each cohort — membership unlocks every one.</p>
    </div>

<?php if ($fc): $fUrl = '/academy/' . rawurlencode((string) $fc['slug']) . '/'; $ftone = avac_access((string) ($fc['access_type'] ?? 'open'))['tone']; ?>
    <a class="avac-feat" href="<?= e($fUrl) ?>">
      <span class="avac-feat-media"><?= avac_cover($fc, 'avac-feat-img') ?>
        <span class="avac-chip avac-chip--<?= e($ftone) ?>"><?= e(avac_price($fc)) ?><?= (int) $feat['enrolled'] === 0 ? ' · New' : '' ?></span>
      </span>
      <span class="avac-feat-body">
        <span class="avac-kick"><?= e((string) $fc['category']) ?> · Featured course</span>
        <span class="avac-feat-h"><?= e((string) $fc['title']) ?></span>
        <span class="avac-feat-p"><?= e((string) $fc['summary']) ?></span>
        <span class="avac-pills">
<?php foreach (array_filter([(string) ($fc['level'] ?? ''), (string) ($fc['duration'] ?? ''), 'Certificate']) as $pill): ?>
          <span><?= e($pill) ?></span>
<?php endforeach; ?>
        </span>
<?php if ($feat['state'] !== '' && $feat['lessons']): ?>
        <span class="avac-prog"><span class="avac-bar"><span style="width:<?= (int) $feat['pct'] ?>%"></span></span><span class="av-num"><?= (int) $feat['pct'] ?>%</span><span class="av-sr"> complete</span></span>
<?php endif; ?>
        <span class="avac-btn"><?= e(avac_cta((string) $feat['state'])) ?> →</span>
      </span>
    </a>
<?php endif; ?>

<?php if ($grid): ?>
    <div class="avac-tools">
      <div class="avac-tracks" role="group" aria-label="Filter courses by track">
        <button type="button" class="avac-track" data-avac-track="all" aria-pressed="true">All tracks</button>
<?php if (count($gridCats) > 1): foreach ($gridCats as $cat): ?>
        <button type="button" class="avac-track" data-avac-track="<?= e(slugify((string) $cat)) ?>" aria-pressed="false"><?= e((string) $cat) ?></button>
<?php endforeach; endif; ?>
      </div>
      <div class="avac-tools-r" role="search">
        <label class="avac-search"><span class="av-sr">Search courses</span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="m20 20-3.5-3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          <input type="search" placeholder="Search courses" data-avac-q autocomplete="off">
        </label>
        <label class="avac-sort"><span>Sort</span>
          <select data-avac-sort>
            <option value="featured">Recommended</option>
            <option value="title">Title (A–Z)</option>
            <option value="lessons">Most lessons</option>
          </select>
        </label>
      </div>
    </div>
    <p class="avac-count" data-avac-count role="status" aria-live="polite">Showing all <?= count($grid) ?></p>
    <ul class="avac-grid" data-avac-grid>
<?php foreach ($grid as $r) avac_card($r); ?>
    </ul>
    <div class="avac-none" data-avac-none hidden>
      <p>No courses match your search.</p>
      <button type="button" class="avac-btn avac-btn--line" data-avac-clear>Clear filters</button>
    </div>
<?php elseif (!$feat): ?>
    <div class="avac-empty">
      <h3>New programmes are on the way</h3>
      <p>We’re preparing the next cohort of free technology, creative and leadership programmes. Check back soon — or join the movement to be the first to know.</p>
      <a class="avac-btn" href="<?= e(AV_VOLUNTEER_URL) ?>">Join the movement</a>
    </div>
<?php endif; ?>

    <div class="avac-soon">
      <div><b>More courses are on the way</b><span>Members get priority places when new cohorts open.</span></div>
      <a href="#membership">Become a member →</a>
    </div>
  </div>
</section>

<section class="avac-proj avh-pad" aria-labelledby="avac-proj-h">
  <div class="avh-wrap">
    <div class="avac-proj-head">
      <div><div class="avac-eyebrow">Beyond the classroom</div><h2 class="avac-h2 avac-h2--sm" id="avac-proj-h">Learn by doing in our projects</h2></div>
      <a class="avac-more" href="/projects/">All nine projects →</a>
    </div>
    <ul class="avac-proj-grid">
<?php foreach ($projects as [$img, $k, $t, $h]): $ext = str_starts_with($h, 'http'); ?>
      <li><a href="<?= e($h) ?>"<?= $ext ? ' target="_blank" rel="noopener"' : '' ?>>
        <img src="<?= e($img) ?>" alt="" loading="lazy" decoding="async">
        <span><i><?= e($k) ?></i><b><?= e($t) ?><?= $ext ? '<span class="av-sr"> (opens in a new tab)</span>' : '' ?></b></span>
      </a></li>
<?php endforeach; ?>
    </ul>
  </div>
</section>

<section class="avac-member avh-pad" id="membership" aria-labelledby="avac-member-h">
  <div class="avh-topo" data-avh-topo="light" data-seed="11" aria-hidden="true"></div>
  <div class="avh-wrap avac-member-grid">
    <div>
      <div class="avac-eyebrow">Academy membership</div>
      <h2 class="avac-h2" id="avac-member-h">One membership. Every programme.</h2>
      <p class="avac-member-p">Unlock the full catalogue, priority cohorts and your verifiable certificates — and back the mission to raise one million incorruptible leaders.</p>
    </div>
    <div class="avac-plan" data-avac-paycard>
      <p class="avac-plan-price"><span class="av-num">₦<?= e(number_format((int) AV_MEMBERSHIP_NGN)) ?></span><span>/ year</span></p>
      <ul class="avac-perks">
        <li>Unlock the full catalogue</li>
        <li>Priority places in new cohorts</li>
        <li>Verifiable certificates</li>
        <li>Back the 2040 mission</li>
      </ul>
<?php if ($member): ?>
      <p class="avac-plan-on">You’re an active member. Thank you for building Africa with us.</p>
<?php elseif ($user): ?>
      <button type="button" class="avac-plan-btn" data-avac-pay="membership">Become a member →</button>
<?php else: ?>
      <a class="avac-plan-btn" href="/login?next=%2Facademy%2F&amp;mode=register" data-avac-auth="register">Create an account to join →</a>
      <p class="avac-plan-note">Already have an account? <a href="/login?next=%2Facademy%2F" data-avac-auth="login">Sign in</a></p>
<?php endif; ?>
      <p class="avac-msg" data-avac-msg role="status" aria-live="polite" hidden></p>
    </div>
  </div>
</section>

<section class="avac-links avh-pad" aria-label="More from the Academy">
  <ul class="avh-wrap avac-links-grid">
    <li><a href="/academy/dns/"><i>DNS ’26</i><b>D’Vanguard National Summit</b><span>Learn more →</span></a></li>
    <li><a href="/academy/teach/"><i>Teach</i><b>Teach with us</b><span>Apply to teach →</span></a></li>
    <li><a href="/mentorship/become-a-mentor/"><i>Mentorship</i><b>Become a mentor</b><span>Get started →</span></a></li>
    <li><a href="/academy/verify.php"><i>Certificates</i><b>Verify a certificate</b><span>Check now →</span></a></li>
  </ul>
</section>

<section class="avac-involved avh-pad" aria-labelledby="avac-inv-h">
  <div class="avh-topo" data-avh-topo="dark" data-seed="11" aria-hidden="true"></div>
  <div class="avac-eyebrow avac-eyebrow--light">Get involved</div>
  <h2 id="avac-inv-h">There Is a Place For You</h2>
  <p>Whether you give, serve, learn, or grow — Afrovanguard has a role for every person who believes in Africa’s next generation.</p>
  <div class="avac-inv-ctas">
    <a class="avac-inv-gold" href="/donate.html">Donate</a>
    <a href="/contact.html">Volunteer</a>
    <a href="/projects/">Apply to a program</a>
  </div>
</section>

</main>
<?php avh_footer(); ?>
</div>
<div class="avac-toast" data-avac-toast role="status" aria-live="polite" hidden></div>
<script src="/assets/site/chioma.js" defer></script>
<script src="/assets/site/celebrations.js" defer></script>
</body>
</html>
