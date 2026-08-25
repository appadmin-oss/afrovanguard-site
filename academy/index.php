<?php
/**
 * academy/index.php — Afrovanguard Academy course catalogue (from the DB).
 *
 * Coursera-style catalogue with an AWS-Bedrock-inspired light hero: breadcrumb,
 * large serif title, one-line subtitle, dark pill CTA; then a filter/sort bar
 * and a responsive grid of course cards that show lesson counts and a signed-in
 * learner's enrolment / progress state.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once __DIR__ . '/_helpers.php';

Sitemap::ensureFresh();
$repo = new AcademyRepository();
$lms  = new LmsRepository();
$courses = $repo->all();
$featured = $repo->featured();
$categories = $repo->categories();
$canonical = rtrim(SITE_URL, '/') . '/academy/';

/* The summit promo runs above the flagship band while the summit is ahead of
   us; once it is over the band disappears rather than advertising a past date. */
$summit = null;
try { if (class_exists('Summit') && !Summit::isPast()) $summit = Summit::facts(); }
catch (Throwable $e) { $summit = null; }

$user = LmsAuth::user();
$member = false;
if ($user) { $member = $lms->isMember((int) $user['id']) || LmsAuth::isOrgMember($user); }
$cards = ac_decorate_courses($courses, $lms, $user);

// Distinct levels (for the sort/filter affordance) — only when there's variety.
$levels = [];
foreach ($courses as $c) { $lv = trim((string) ($c['level'] ?? '')); if ($lv !== '') $levels[$lv] = true; }
$levels = array_keys($levels);

$itemList = ['@type' => 'ItemList', 'itemListElement' => []];
foreach ($courses as $i => $c) {
    $itemList['itemListElement'][] = ['@type' => 'ListItem', 'position' => $i + 1,
        'url' => rtrim(SITE_URL, '/') . '/academy/' . $c['slug'] . '/', 'name' => $c['title']];
}
$crumbs = schema_breadcrumb([
    ['name' => 'Home', 'url' => rtrim(SITE_URL, '/') . '/'],
    ['name' => 'Academy', 'url' => $canonical],
]);

render_head([
    'title' => 'Afrovanguard Academy — Free programmes for young Africans',
    'desc'  => 'Technology, creative, and leadership programmes raising one million incorruptible leaders for Africa. Learn, build, and lead with Afrovanguard.',
    'canonical' => $canonical, 'og_kind' => 'website',
    'image' => $featured ? rtrim(SITE_URL, '/') . '/academy/og/' . $featured['slug'] . '.png' : null,
    'keywords' => 'Afrovanguard Academy, NextGen Vanguard, free training Lagos, skills training Alimosho, Egbeda youth programme, NYSC skills Nigeria, learn and earn Nigeria, Techome, MediaPro, Africa GATES, digital marketing training Lagos, tech internship Lagos, youth programmes Nigeria',
    'jsonld' => [schema_org(), schema_website(), $itemList, $crumbs],
    'css' => ['/academy/academy.css'], 'body_class' => 'academy',
]);
render_nav('academy');
?>
  <main id="main-content">
    <section class="ac-hero">
      <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
          <a href="/">Home</a><span class="sep">›</span><span aria-current="page">Academy</span>
        </nav>
        <p class="ac-hero-eyebrow">The Afrovanguard Academy</p>
        <h1>Learn. Build. Lead Africa.</h1>
        <p class="ac-hero-sub">Free, hands-on programmes in technology, the creative arts and leadership — the formation behind one million incorruptible leaders by 2040.</p>
        <div class="ac-hero-cta">
          <a class="btn btn-pill" href="/academy/ngv/">NextGen Vanguard →</a>
          <a class="btn btn-pill-ghost" href="#catalogue">Browse courses</a>
          <a class="btn btn-pill-ghost" href="/academy/teach/">Teach with us</a>
        </div>
        <dl class="ac-hero-stats" aria-label="Academy at a glance">
          <div><dt><?= count($courses) ?></dt><dd>Programme<?= count($courses) === 1 ? '' : 's' ?></dd></div>
<?php if ($categories): ?>          <div><dt><?= count($categories) ?></dt><dd>Track<?= count($categories) === 1 ? '' : 's' ?></dd></div>
<?php endif; ?>
          <div><dt>Free</dt><dd>To get started</dd></div>
        </dl>
      </div>
    </section>

<?php if ($summit): $sv = $summit['venue']; ?>
    <a class="ngv-promo dns-promo" href="/academy/dns/"
       aria-label="<?= e($summit['name']) ?> — <?= e($summit['date_label']) ?>, <?= e($sv['name']) ?>, <?= e($sv['area']) ?>">
      <div class="container ngv-promo-inner">
        <div class="ngv-promo-copy">
          <span class="ngv-promo-tag"><?= Summit::isLive() ? 'Happening now' : 'The summit' ?> · <?= e($summit['edition']) ?> · <?= e($summit['triad']) ?></span>
          <strong><?= e($summit['name']) ?></strong>
          <span class="ngv-promo-sub"><?= e($summit['date_label']) ?> · <?= e($sv['name']) ?>, <?= e($sv['area']) ?>, <?= e($sv['city']) ?> · <?= e($summit['pass']['label']) ?> for all four days.</span>
        </div>
        <span class="ngv-promo-cta">Claim your seat →</span>
      </div>
    </a>
<?php endif; ?>

    <a class="ngv-promo" href="/academy/ngv/" aria-label="NextGen Vanguard — our flagship transformation programme">
      <div class="container ngv-promo-inner">
        <div class="ngv-promo-copy">
          <span class="ngv-promo-tag">★ Flagship programme · Now enrolling</span>
          <strong>NextGen Vanguard — stop scrolling, start earning.</strong>
          <span class="ngv-promo-sub">Future-ready tech, media, business &amp; leadership skills for young Africans — with weekly stipends, six global certifications and a real internship.</span>
        </div>
        <span class="ngv-promo-cta">Explore NextGen Vanguard →</span>
      </div>
    </a>

    <div class="container" id="catalogue">
      <div class="ac-toolbar">
        <div class="ac-toolbar-head">
          <h2 class="ac-toolbar-title">Explore programmes</h2>
          <p class="ac-toolbar-count" data-count>Showing all <?= count($courses) ?></p>
        </div>
        <div class="ac-toolbar-controls">
          <div class="search-wrap"><?= Icons::SEARCH ?><input type="search" class="search-input" placeholder="Search programmes…" aria-label="Search programmes" /></div>
          <label class="ac-sort">
            <span class="ac-sort-lbl">Sort</span>
            <select class="ac-sort-select" aria-label="Sort programmes">
              <option value="featured">Recommended</option>
              <option value="title">Title (A–Z)</option>
              <option value="lessons">Most lessons</option>
            </select>
          </label>
        </div>
        <div class="diary-filters ac-filters" role="tablist" aria-label="Filter programmes by track">
          <button class="chip active" data-filter="all" role="tab" aria-selected="true">All tracks</button>
<?php foreach ($categories as $cat): ?>
          <button class="chip" data-filter="<?= e(slugify($cat)) ?>" role="tab" aria-selected="false"><?= e($cat) ?></button>
<?php endforeach; ?>
        </div>
      </div>

<?php if ($cards): ?>
      <section class="ac-grid" aria-label="Programmes">
<?php foreach ($cards as $row): ac_course_card($row['course'], $row['opts']); endforeach; ?>
      </section>
      <div class="no-results ac-empty-inline">
        <p>No programmes match your search.</p>
        <button type="button" class="btn btn-pill-ghost btn-sm" data-clear-filters>Clear filters</button>
      </div>
<?php else: ?>
      <div class="ac-empty">
        <h2>New programmes are on the way</h2>
        <p>We're preparing the next cohort of free technology, creative and leadership programmes. Check back soon — or join the movement to be the first to know.</p>
        <a class="btn btn-pill" href="<?= e(AV_VOLUNTEER_URL) ?>">Join the movement</a>
      </div>
<?php endif; ?>
    </div>

    <section class="ac-membership" id="membership">
      <div class="container membership-card" data-reveal>
        <div class="membership-copy">
          <span class="ac-hero-eyebrow ac-hero-eyebrow--light">Academy membership</span>
          <h2>One membership. Every programme.</h2>
          <p>Unlock the full catalogue, priority cohorts and your verifiable certificates — and back the mission to raise one million incorruptible leaders.</p>
          <p class="membership-price"><?= '₦' . number_format((int) AV_MEMBERSHIP_NGN) ?><span> / year</span></p>
        </div>
        <div class="membership-cta pay-card">
<?php if ($member): ?>
          <p class="membership-active">✓ You're an active member. Thank you for building Africa with us.</p>
<?php elseif ($user): ?>
          <button type="button" class="btn btn-pill-gold pay-btn" data-pay="membership">Become a member →</button>
<?php else: ?>
          <button type="button" class="btn btn-pill-gold" data-auth="register">Create an account to join →</button>
          <p class="enroll-tiny">Already have an account? <a href="#" data-auth="login">Sign in</a></p>
<?php endif; ?>
          <p class="enroll-msg" hidden></p>
        </div>
      </div>
    </section>
  </main>
<?php echo '<script src="/academy/academy.js" defer></script>'; render_footer();
