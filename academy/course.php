<?php
/**
 * academy/course.php — one programme. /academy/<slug>/ (or ?slug=<slug>).
 *
 * Rebuilt to the design "Afrovanguard Course" inside the shared Home chrome.
 * Sections: course hero → body (Overview · Curriculum · Certificate tabs, FAQ)
 * with the sticky enrol card → Keep learning. Data as v1: AcademyRepository
 * (course, others), LmsRepository (curriculum, lesson + enrolment counts,
 * progress, access, membership, instructor), LmsAuth. Every access branch of
 * v1 is kept: open / tracked / membership / paid (checkout) / restricted (pass
 * code) and the lead-capture form for a programme with no lessons yet.
 * Styles: academy/avac.css + academy/avco.css · behaviour: avac.js + avco.js.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/partials/avh-chrome.php';
require_once __DIR__ . '/_avac.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$repo = new AcademyRepository();
$c = $slug ? $repo->bySlug($slug) : null;
if (!$c) {
    require_once AV_ROOT . '/lib/errors.php';
    av_error_render(404);
    exit;
}

$S = rtrim(SITE_URL, '/');
$canonical = $S . '/academy/' . $c['slug'] . '/';
$ogImage = ($c['og_image'] ?? '') ?: ($S . '/academy/og/' . $c['slug'] . '.png');
$outcomes = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($c['outcomes'] ?? '')))));
$lms = new LmsRepository();
$user = LmsAuth::user();
$id = (int) $c['id'];
$curriculum = $lms->curriculum($id);
$lessonTotal = $lms->lessonCount($id);
$ordered = $lms->orderedLessons($id);
$access = (string) ($c['access_type'] ?? 'open');
$am = avac_access($access);
$progress = ($user && $lessonTotal) ? $lms->progress((int) $user['id'], $id) : null;
$doneIds = $progress['ids'] ?? [];
$firstLesson = $ordered[0]['slug'] ?? '';
$moduleCount = count($curriculum);
$totalMins = 0;
foreach ($curriculum as $m) { foreach ($m['lessons'] as $l) { $totalMins += (int) ($l['duration_min'] ?? 0); } }

$isMember = $user ? ($lms->isMember((int) $user['id']) || LmsAuth::isOrgMember($user)) : false;
$hasAccess = $user && $lms->canAccess($user, $c, ['is_preview' => 0]);
$price = (int) ($c['price_ngn'] ?? 0);
$ngn = static fn(int $n): string => '₦' . number_format($n);
$enrolledCount = $lms->enrolledCount($id);
$instructorName = $lms->instructorName(isset($c['instructor_id']) ? (int) $c['instructor_id'] : null);
$started = $progress && !empty($progress['completed']);
$complete = $progress && !empty($progress['complete']);
$certUrl = academy_url($c['slug'] . '/certificate');

/* Primary call to action: the same rules as v1. */
$resumeUrl = $firstLesson ? academy_url($c['slug'] . '/learn/' . $firstLesson) : '#enrol';
$ctaLabel = 'Enrol now';
if ($lessonTotal && $firstLesson) {
    if ($started) $ctaLabel = 'Continue learning';
    elseif ($hasAccess || $access === 'open' || $access === 'tracked') $ctaLabel = 'Start learning';
}
$heroHref = ($lessonTotal && $firstLesson) ? $resumeUrl : '#enrol';
$heroLabel = ($lessonTotal && $firstLesson) ? $ctaLabel : 'Apply / enrol';
// A gated course the learner cannot open yet sends the hero button to the enrol card.
if ($lessonTotal && $firstLesson && !$hasAccess && !in_array($access, ['open', 'tracked'], true)) { $heroHref = '#enrol'; }

/* Price block. */
if ($access === 'paid') { $priceBig = $price > 0 ? $ngn($price) : ((string) ($c['price'] ?? '') ?: 'Paid'); $priceNote = 'One-time fee · certificate included.'; }
elseif ($access === 'membership') { $priceBig = $ngn((int) AV_MEMBERSHIP_NGN) . ' / yr'; $priceNote = 'Included with Academy membership.'; }
elseif ($access === 'restricted') { $priceBig = 'Restricted'; $priceNote = $hasAccess ? 'Access unlocked.' : 'Invite or pass required.'; }
else { $priceBig = 'Free'; $priceNote = $access === 'tracked' ? ($user ? 'Free — your progress is saved to your account.' : 'Free to join — sign in to save your progress.') : 'Open programme — start straight away.'; }

$facts = array_filter([
    'Level' => (string) ($c['level'] ?? ''), 'Format' => (string) ($c['format'] ?? ''),
    'Duration' => (string) ($c['duration'] ?? ''), 'Lessons' => $lessonTotal ? (string) $lessonTotal : '',
    'Location' => (string) ($c['location'] ?? ''), 'Certificate' => 'Yes, on completion',
]);
$badges = array_filter([(string) ($c['level'] ?? ''), (string) ($c['format'] ?? ''), (string) ($c['duration'] ?? ''), $lessonTotal ? avac_plural($lessonTotal, 'lesson') : '']);

$faqs = [
    ['Do I earn a certificate?', 'Yes. Complete every lesson to earn a verifiable Afrovanguard Academy certificate with a unique serial you can share on LinkedIn and your CV.'],
    ['How much does it cost?', $access === 'paid'
        ? 'This programme is ' . ($price > 0 ? $ngn($price) . ' (one-time)' : 'paid') . '. Academy members get it included — see membership.'
        : ($access === 'membership'
            ? 'This programme is included with Academy membership (' . $ngn((int) AV_MEMBERSHIP_NGN) . ' / year).'
            : ($access === 'restricted'
                ? 'This programme is restricted to invited members or those holding a pass.'
                : 'This programme is free. ' . ($access === 'tracked' ? 'Create a free account to save your progress and earn your certificate.' : 'You can start straight away.')))],
    ['How long does it take?', ($c['duration'] ? 'About ' . strtolower((string) $c['duration']) . ', ' : '') . 'self-paced' . ($lessonTotal ? ' across ' . avac_plural($lessonTotal, 'lesson') : '') . ', so you can learn on your own schedule.'],
    ['Do I need any prior experience?', 'This programme is pitched at ' . strtolower((string) $c['level']) . '. Come curious and ready to build — we take it step by step.'],
];

$othersRows = avac_decorate($repo->others($c['slug']), $lms, $user);

$courseSchema = [
    '@type' => 'Course', 'name' => $c['title'], 'description' => $c['summary'],
    'provider' => ['@type' => 'Organization', 'name' => 'Afrovanguard', '@id' => SITE_URL . '/#organization'],
    'url' => $canonical,
    'hasCourseInstance' => ['@type' => 'CourseInstance', 'courseMode' => $c['format'], 'location' => $c['location']],
];
if (strtolower((string) $c['price']) === 'free') $courseSchema['isAccessibleForFree'] = true;
$crumbs = schema_breadcrumb([
    ['name' => 'Home', 'url' => $S . '/'],
    ['name' => 'Academy', 'url' => $S . '/academy/'],
    ['name' => $c['title'], 'url' => $canonical],
]);

avac_head([
    'title' => $c['title'] . ' — Afrovanguard Academy',
    'desc' => (string) $c['summary'],
    'canonical' => $canonical, 'image' => $ogImage, 'image_alt' => (string) $c['title'],
    'keywords' => $c['title'] . ', ' . $c['category'] . ', Afrovanguard Academy, free training, Lagos',
    'jsonld' => [schema_org(), $courseSchema, $crumbs],
    'css' => ['/assets/site/avh.css', '/academy/avac.css', '/academy/avco.css'],
    'js'  => ['/assets/site/avh.js', '/academy/avac.js', '/academy/avco.js'],
]);
$cta = trim((string) ($c['cta_url'] ?? ''));
$startBtn = static function (string $cls) use ($resumeUrl, $started): void {
    echo '<a class="' . e($cls) . '" href="' . e($resumeUrl) . '">' . ($started ? 'Continue learning' : 'Start learning') . ' →</a>';
};
?>
<body class="avh avac avco" id="top" data-slug="<?= e((string) $c['slug']) ?>">
<a class="avh-skip" href="#main">Skip to content</a>
<div class="avh-page">
<?php avh_nav(); ?>
<main id="main" tabindex="-1">
<?php avac_pay_flash('avac-flash'); ?>

<header class="avco-hero avh-pad">
  <div class="avh-topo" data-avh-topo="light" data-seed="7" aria-hidden="true"></div>
  <div class="avh-wrap">
    <nav aria-label="Breadcrumb"><ol class="avac-crumbs avco-crumbs"><li><a href="/academy/">Academy</a></li><li><span aria-current="page"><?= e((string) $c['title']) ?></span></li></ol></nav>
    <div class="avco-hero-grid">
      <div class="avco-hero-copy">
        <div class="avac-eyebrow"><?= e((string) $c['category']) ?> · Afrovanguard Academy</div>
        <h1><?= e((string) $c['title']) ?></h1>
        <p class="avco-dek"><?= e((string) $c['summary']) ?></p>
        <div class="avco-badges">
<?php foreach ($badges as $b): ?>          <span><?= e($b) ?></span>
<?php endforeach; ?>
          <span class="avco-badge-price"><?= e($access === 'paid' ? avac_price($c) : $am['label']) ?></span>
        </div>
        <div class="avac-btns">
          <a class="avac-btn" href="<?= e($heroHref) ?>"><?= e($heroLabel) ?> →</a>
<?php if ($cta !== ''): ?>          <a class="avac-btn avac-btn--line" href="<?= e($cta) ?>" target="_blank" rel="noopener">Programme site ↗<span class="av-sr"> (opens in a new tab)</span></a>
<?php endif; ?>
        </div>
        <p class="avco-trust"><span class="avac-live" aria-hidden="true"></span><?= $enrolledCount > 0 ? '<b class="av-num">' . e(number_format($enrolledCount)) . '</b> already enrolled' : 'New programme — be among the first' ?> · Certificate on completion<?= $instructorName ? ' · Taught by ' . e($instructorName) : '' ?></p>
      </div>
      <div class="avco-hero-media"><?= avac_cover($c, 'avco-hero-img') ?></div>
    </div>
  </div>
</header>

<section class="avco-body avh-pad">
  <div class="avh-wrap avco-layout">
    <div class="avco-main">
      <div class="avco-tabs" role="tablist" aria-label="Course sections">
        <button type="button" class="avco-tab" role="tab" id="avco-t-overview" aria-controls="avco-p-overview" aria-selected="true" data-avco-tab="overview">Overview</button>
<?php if ($curriculum): ?>        <button type="button" class="avco-tab" role="tab" id="avco-t-curriculum" aria-controls="avco-p-curriculum" aria-selected="false" tabindex="-1" data-avco-tab="curriculum">Curriculum</button>
<?php endif; ?>
        <button type="button" class="avco-tab" role="tab" id="avco-t-certificate" aria-controls="avco-p-certificate" aria-selected="false" tabindex="-1" data-avco-tab="certificate">Certificate</button>
      </div>

      <section class="avco-panel" role="tabpanel" id="avco-p-overview" aria-labelledby="avco-t-overview" data-avco-panel="overview">
<?php if ($outcomes): ?>
        <div>
          <h2 class="avco-h2">What you’ll learn</h2>
          <ul class="avco-learn">
<?php foreach ($outcomes as $o): ?>            <li><?= e($o) ?></li>
<?php endforeach; ?>
          </ul>
        </div>
<?php endif; ?>
<?php if (trim(strip_tags((string) ($c['body_html'] ?? ''))) !== ''): ?>
        <div class="avco-about"><?= $c['body_html'] ?></div>
<?php endif; ?>
        <div class="avco-instructor">
          <span class="avco-avatar" aria-hidden="true"><?= e(mb_substr($instructorName ?: 'Afrovanguard', 0, 1)) ?></span>
          <div><div class="avco-ins-k">Your instructor</div><div class="avco-ins-n"><?= e($instructorName ?: 'The Afrovanguard Academy Faculty') ?></div><div class="avco-ins-r"><?= $instructorName ? 'Programme instructor · Afrovanguard Academy' : 'Practitioners and mentors raising one million incorruptible leaders' ?></div></div>
        </div>
      </section>

<?php if ($curriculum): ?>
      <section class="avco-panel" role="tabpanel" id="avco-p-curriculum" aria-labelledby="avco-t-curriculum" data-avco-panel="curriculum" hidden>
        <h2 class="avco-h2 avco-h2--tight">Curriculum</h2>
        <p class="avco-sub av-num"><?= e(avac_plural($moduleCount, 'module')) ?> · <?= e(avac_plural($lessonTotal, 'lesson')) ?><?= $totalMins ? ' · ' . $totalMins . ' min' : '' ?> · self-paced</p>
<?php if ($progress): ?>
        <div class="avac-prog avco-prog"><span class="av-num"><?= (int) $progress['completed'] ?>/<?= (int) $progress['total'] ?> done</span><span class="avac-bar"><span style="width:<?= (int) $progress['pct'] ?>%"></span></span><span class="av-num"><?= (int) $progress['pct'] ?>%</span></div>
<?php if ($complete): ?>
        <div class="avco-done">You’ve completed this programme. <a href="<?= e($certUrl) ?>" target="_blank" rel="noopener">Get your certificate →</a></div>
<?php endif; ?>
<?php endif; ?>
<?php $n = 0; foreach ($curriculum as $m):
        $mDone = 0; foreach ($m['lessons'] as $l) { if (in_array((int) $l['id'], $doneIds, true)) $mDone++; } ?>
        <h3 class="avco-mod"><span><?= e((string) $m['title']) ?></span><span class="av-num"><?= $progress ? $mDone . '/' : '' ?><?= e(avac_plural(count($m['lessons']), 'lesson')) ?></span></h3>
        <ol class="avco-lessons">
<?php foreach ($m['lessons'] as $l):
            $n++;
            $lOpen = !empty($l['is_preview']) || $access === 'open' || ($user && $lms->canAccess($user, $c, $l));
            $done = in_array((int) $l['id'], $doneIds, true);
            $type = (string) ($l['type'] ?? 'reading');
            $sub = array_filter([ucfirst($type), (int) $l['duration_min'] ? (int) $l['duration_min'] . ' min' : '', !empty($l['is_preview']) ? 'Preview · open to everyone' : ($lOpen ? '' : 'Locked')]); ?>
          <li><a class="avco-lesson<?= $done ? ' is-done' : '' ?><?= $lOpen ? '' : ' is-locked' ?>" href="<?= e(academy_url($c['slug'] . '/learn/' . $l['slug'])) ?>">
            <span class="avco-n av-num"><?= str_pad((string) $n, 2, '0', STR_PAD_LEFT) ?></span>
            <span><span class="avco-lt"><?= e((string) $l['title']) ?></span><span class="avco-ls"><?= e(implode(' · ', $sub)) ?></span></span>
            <span class="avco-li" aria-hidden="true"><?= $done ? '✓' : ($lOpen ? '▶' : '—') ?></span><?= $done ? '<span class="av-sr"> (completed)</span>' : ($lOpen ? '' : '<span class="av-sr"> (locked)</span>') ?>
          </a></li>
<?php endforeach; ?>
        </ol>
<?php endforeach; ?>
      </section>
<?php endif; ?>

      <section class="avco-panel" role="tabpanel" id="avco-p-certificate" aria-labelledby="avco-t-certificate" data-avco-panel="certificate" hidden>
        <h2 class="avco-h2 avco-h2--tight">Your certificate</h2>
        <p class="avco-lede">Complete every lesson to earn a verifiable Afrovanguard Academy certificate with a unique serial you can share on LinkedIn and your CV.</p>
        <div class="avco-cert" aria-hidden="true">
          <div class="avco-cert-k">Afrovanguard Academy · Certificate of completion</div>
          <div class="avco-cert-n"><?= $user ? e((string) $user['name']) : 'Your name here' ?></div>
          <div class="avco-cert-c">has completed <strong><?= e((string) $c['title']) ?></strong></div>
          <div class="avco-cert-f"><span>Serial · AVA-XXXX-XXXX</span><span>Verify at academy/verify</span></div>
        </div>
<?php if ($complete): ?>
        <a class="avac-btn" href="<?= e($certUrl) ?>" target="_blank" rel="noopener">Get your certificate →</a>
<?php elseif ($progress): ?>
        <p class="avco-sub">You’re <?= (int) $progress['pct'] ?>% of the way there — finish the remaining lessons to unlock it.</p>
<?php endif; ?>
        <a class="avac-more" href="/academy/verify.php">Verify a certificate →</a>
      </section>

      <div class="avco-faq">
        <h2 class="avco-h2 avco-h2--tight">Frequently asked questions</h2>
<?php foreach ($faqs as $i => [$q, $a]): ?>
        <div class="avco-q">
          <h3><button type="button" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>" aria-controls="avco-a<?= $i ?>" data-avco-faq><span><?= e($q) ?></span><span class="avco-plus" aria-hidden="true"></span></button></h3>
          <p id="avco-a<?= $i ?>"<?= $i === 0 ? '' : ' hidden' ?>><?= e($a) ?></p>
        </div>
<?php endforeach; ?>
      </div>
    </div>

    <aside class="avco-side" id="enrol" aria-label="Enrol" data-avac-paycard>
      <p class="avco-price"><?= e($priceBig) ?></p>
<?php if ($progress): ?>
      <div class="avco-side-prog"><div class="avco-side-row"><span class="av-num"><?= (int) $progress['completed'] ?> of <?= (int) $progress['total'] ?> lessons</span><span class="av-num"><?= (int) $progress['pct'] ?>%</span></div><span class="avac-bar"><span style="width:<?= (int) $progress['pct'] ?>%"></span></span></div>
<?php endif; ?>
<?php if ($access === 'paid'): ?>
<?php if ($hasAccess): ?>
      <p class="avco-state">You’re enrolled — full access unlocked.</p>
<?php if ($firstLesson) $startBtn('avco-go'); ?>
<?php elseif ($user): ?>
      <button type="button" class="avco-go" data-avac-pay="course" data-course="<?= e((string) $c['slug']) ?>">Enrol — <?= $price > 0 ? e($ngn($price)) : 'pay now' ?> →</button>
      <p class="avco-note">Members get this course included. <a href="/academy/#membership">See membership →</a></p>
<?php else: ?>
      <a class="avco-go" href="/login?mode=register" data-avac-auth="register">Create an account to enrol →</a>
      <p class="avco-note">Already have an account? <a href="/login" data-avac-auth="login">Sign in</a></p>
<?php endif; ?>
<?php elseif ($access === 'membership'): ?>
<?php if ($isMember): ?>
      <p class="avco-state">Your membership unlocks this programme.</p>
<?php if ($firstLesson) $startBtn('avco-go'); ?>
<?php elseif ($user): ?>
      <button type="button" class="avco-go" data-avac-pay="membership">Become a member →</button>
      <p class="avco-note">Unlocks every members’ programme.</p>
<?php else: ?>
      <a class="avco-go" href="/login?mode=register" data-avac-auth="register">Create an account to join →</a>
      <p class="avco-note">Already a member? <a href="/login" data-avac-auth="login">Sign in</a></p>
<?php endif; ?>
<?php elseif ($access === 'restricted'): ?>
<?php if ($hasAccess): ?>
      <p class="avco-state">You have access to this restricted programme.</p>
<?php if ($firstLesson) $startBtn('avco-go'); ?>
<?php elseif ($user): ?>
      <p class="avco-state avco-state--lock">This programme is restricted. If you were given a pass code, enter it below — otherwise ask an Academy admin to grant you access.</p>
<?php if (trim((string) ($c['pass_code'] ?? '')) !== ''): ?>
      <form class="avco-form" data-avac-pass="<?= e((string) $c['slug']) ?>">
        <label><span class="av-sr">Pass code</span><input name="code" placeholder="Enter your pass code" autocomplete="off" required></label>
        <button type="submit" class="avco-go">Unlock →</button>
      </form>
<?php endif; ?>
<?php else: ?>
      <p class="avco-state avco-state--lock">This is a restricted programme.</p>
      <a class="avco-go" href="/login" data-avac-auth="login">Sign in to continue →</a>
      <p class="avco-note">Access is limited to invited members or those holding a pass.</p>
<?php endif; ?>
<?php else: /* open / tracked */ ?>
<?php if ($lessonTotal && $firstLesson): ?>
      <a class="avco-go" href="<?= e($resumeUrl) ?>"><?= e($ctaLabel) ?> →</a>
<?php else: ?>
      <p class="avco-state">Free to join. Tell us a little about you and our team will reach out.</p>
      <form class="avco-form" data-avac-lead="<?= e((string) $c['slug']) ?>">
        <label><span class="av-sr">Full name</span><input name="name" placeholder="Full name" required autocomplete="name"></label>
        <label><span class="av-sr">Email address</span><input name="email" type="email" placeholder="Email address" required autocomplete="email"></label>
        <label><span class="av-sr">Phone (optional)</span><input name="phone" placeholder="Phone (optional)" autocomplete="tel"></label>
        <label><span class="av-sr">Why are you interested? (optional)</span><textarea name="note" rows="3" placeholder="Why are you interested? (optional)"></textarea></label>
        <button type="submit" class="avco-go">Submit application →</button>
      </form>
<?php endif; ?>
<?php endif; ?>
<?php if ($cta !== ''): ?>      <a class="avco-site" href="<?= e($cta) ?>" target="_blank" rel="noopener">Programme site ↗<span class="av-sr"> (opens in a new tab)</span></a>
<?php endif; ?>
      <p class="avco-note"><?= e($priceNote) ?><?php if ($access === 'tracked' && !$user && $lessonTotal): ?> <a href="/login" data-avac-auth="login">Sign in</a><?php endif; ?></p>
      <p class="avac-msg" data-avac-msg role="status" aria-live="polite" hidden></p>
      <dl class="avco-facts">
<?php foreach ($facts as $k => $v): ?>        <div><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd></div>
<?php endforeach; ?>
      </dl>
    </aside>
  </div>
</section>

<?php if ($othersRows): ?>
<section class="avco-more avh-pad" aria-labelledby="avco-more-h">
  <div class="avh-topo" data-avh-topo="light" data-seed="11" aria-hidden="true"></div>
  <div class="avh-wrap">
    <div class="avco-more-head"><h2 class="avco-h2 avco-h2--more" id="avco-more-h">Keep learning</h2><a class="avac-more" href="/academy/#catalogue">View the full catalogue →</a></div>
    <ul class="avac-grid">
<?php foreach ($othersRows as $r) avac_card($r); ?>
    </ul>
  </div>
</section>
<?php endif; ?>

</main>
<?php avh_footer(); ?>
</div>
<div class="avac-toast" data-avac-toast role="status" aria-live="polite" hidden></div>
<script src="/assets/site/chioma.js" defer></script>
<script src="/assets/site/celebrations.js" defer></script>
</body>
</html>
