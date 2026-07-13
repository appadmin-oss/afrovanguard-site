<?php
/**
 * academy/course.php — a single programme (from the DB).
 * Reached via /academy/<slug>/ (see .htaccess) or ?slug=<slug>.
 *
 * Coursera-style detail page: a light hero (breadcrumb, serif title, subtitle,
 * dark pill CTA), a tabbed body (Overview · Curriculum · Certificate) with a
 * "What you'll learn" panel and a module → lesson accordion (lock icons for
 * gated lessons), and a sticky enrol / continue card with price + progress.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once __DIR__ . '/_helpers.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$repo = new AcademyRepository();
$c = $slug ? $repo->bySlug($slug) : null;

if (!$c) {
    require_once AV_ROOT . '/lib/errors.php';
    av_error_render(404);
    exit;
}

$canonical = rtrim(SITE_URL, '/') . '/academy/' . $c['slug'] . '/';
$cover = $c['cover_url'] ?: '';
$ogImage = $c['og_image'] ?: (rtrim(SITE_URL, '/') . '/academy/og/' . $c['slug'] . '.png');
$outcomes = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $c['outcomes']))));
$others = $repo->others($c['slug']);

$lms = new LmsRepository();
$user = LmsAuth::user();
$curriculum = $lms->curriculum((int) $c['id']);
$lessonTotal = $lms->lessonCount((int) $c['id']);
$ordered = $lms->orderedLessons((int) $c['id']);
$access = $c['access_type'] ?? 'open';
$am = ac_access_meta($access);
$progress = ($user && $lessonTotal) ? $lms->progress((int) $user['id'], (int) $c['id']) : null;
$doneIds = $progress['ids'] ?? [];
$firstLesson = $ordered[0]['slug'] ?? '';
$moduleCount = count($curriculum);
// Total runtime across lessons that declare a duration (minutes).
$totalMins = 0;
foreach ($curriculum as $m) { foreach ($m['lessons'] as $l) { $totalMins += (int) ($l['duration_min'] ?? 0); } }

$isMember = $user ? ($lms->isMember((int) $user['id']) || LmsAuth::isOrgMember($user)) : false;
$hasAccess = $user && $lms->canAccess($user, $c, ['is_preview' => 0]);
$price = (int) ($c['price_ngn'] ?? 0);
$fmtNgn = fn(int $n) => '₦' . number_format($n);
// Coursera-style trust signals for the hero.
$enrolledCount  = $lms->enrolledCount((int) $c['id']);
$instructorName = $lms->instructorName($c['instructor_id'] ?? null);
$skills = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($c['outcomes'] ?? '')))));

// Primary CTA target / label depends on access + progress.
$resumeUrl = $firstLesson ? academy_url($c['slug'] . '/learn/' . $firstLesson) : '#enroll';
$ctaLabel = 'Enrol now';
if ($lessonTotal && $firstLesson) {
    if ($progress && !empty($progress['completed'])) $ctaLabel = 'Continue learning';
    elseif ($hasAccess || $access === 'open' || $access === 'tracked') $ctaLabel = 'Start learning';
    else $ctaLabel = 'Enrol now';
}

$courseSchema = [
    '@type' => 'Course', 'name' => $c['title'], 'description' => $c['summary'],
    'provider' => ['@type' => 'Organization', 'name' => 'Afrovanguard', '@id' => SITE_URL . '/#organization'],
    'url' => $canonical,
    'hasCourseInstance' => ['@type' => 'CourseInstance', 'courseMode' => $c['format'], 'location' => $c['location']],
];
if (strtolower($c['price']) === 'free') $courseSchema['isAccessibleForFree'] = true;
$crumbs = schema_breadcrumb([
    ['name' => 'Home', 'url' => rtrim(SITE_URL, '/') . '/'],
    ['name' => 'Academy', 'url' => rtrim(SITE_URL, '/') . '/academy/'],
    ['name' => $c['title'], 'url' => $canonical],
]);

render_head([
    'title' => $c['title'] . ' — Afrovanguard Academy',
    'desc' => $c['summary'],
    'canonical' => $canonical, 'slug' => $c['slug'], 'og_kind' => 'website',
    'image' => $ogImage, 'image_alt' => $c['title'],
    'keywords' => $c['title'] . ', ' . $c['category'] . ', Afrovanguard Academy, free training, Lagos',
    'jsonld' => [schema_org(), $courseSchema, $crumbs],
    'css' => ['/academy/academy.css'], 'body_class' => 'academy',
]);
render_nav('academy');
?>
  <main id="main-content">
<?php $payFlag = preg_replace('/[^a-z]/', '', strtolower((string) ($_GET['pay'] ?? ''))); ?>
<?php if ($payFlag === 'paid'): ?>
    <div class="container"><div class="pay-flash ok" role="status">🎉 Payment confirmed — you now have full access. Welcome aboard!</div></div>
<?php elseif ($payFlag === 'failed'): ?>
    <div class="container"><div class="pay-flash err" role="status">We couldn't confirm that payment. If you were charged, contact us and we'll sort it right away.</div></div>
<?php endif; ?>
    <article class="course-detail">
      <section class="course-hero">
        <div class="container">
          <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="/academy/">Academy</a><span class="sep">›</span><span aria-current="page"><?= e($c['title']) ?></span>
          </nav>
          <div class="course-hero-grid">
            <div class="course-hero-copy">
              <p class="ac-hero-eyebrow"><?= e($c['category']) ?></p>
              <h1><?= e($c['title']) ?></h1>
              <div class="course-partner"><span class="course-partner-mark" aria-hidden="true">A</span><span>Afrovanguard Academy<?= $instructorName ? ' · Taught by ' . e($instructorName) : '' ?></span></div>
              <p class="course-dek"><?= e($c['summary']) ?></p>
              <div class="course-trust">
<?php if ($enrolledCount > 0): ?>                <span class="course-trust-item"><strong><?= number_format($enrolledCount) ?></strong> already enrolled</span>
<?php else: ?>                <span class="course-trust-item course-trust-new">New programme — be among the first</span>
<?php endif; ?>
                <span class="course-trust-item"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="6"/><path d="M8.21 13.89 7 22l5-3 5 3-1.21-8.11"/></svg> Certificate on completion</span>
              </div>
              <div class="course-badges">
                <span class="cb"><?= e($c['level']) ?></span>
                <span class="cb"><?= e($c['format']) ?></span>
<?php if (!empty($c['duration'])): ?>                <span class="cb"><?= e($c['duration']) ?></span>
<?php endif; ?>
<?php if ($lessonTotal): ?>                <span class="cb"><?= $lessonTotal ?> lesson<?= $lessonTotal === 1 ? '' : 's' ?></span>
<?php endif; ?>
                <span class="cb badge-access access-<?= e($am['cls']) ?>"><?= e($am['label']) ?></span>
              </div>
              <div class="course-hero-cta">
<?php if ($lessonTotal && $firstLesson): ?>
                <a class="btn btn-pill" href="<?= e($resumeUrl) ?>"><?= e($ctaLabel) ?> →</a>
<?php else: ?>
                <a class="btn btn-pill" href="#enroll">Apply / enrol →</a>
<?php endif; ?>
<?php if (!empty($c['cta_url'])): ?>                <a class="btn btn-pill-ghost" href="<?= e($c['cta_url']) ?>" target="_blank" rel="noopener">Programme site ↗</a><?php endif; ?>
              </div>
            </div>
            <div class="course-hero-media <?= $cover ? 'has-cover' : e($c['gradient'] ?: 'g-gold') . ' g-grain' ?>"<?= $cover ? ' style="background-image:url(\'' . e($cover) . '\')"' : '' ?> aria-hidden="true">
<?php if (!$cover): ?>              <span class="chm-mark"><?= e($c['title']) ?></span>
<?php endif; ?>            </div>
          </div>
        </div>
      </section>

      <div class="container">
        <div class="course-layout">
          <div class="course-main">
            <div class="course-tabs" role="tablist" aria-label="Course sections">
              <button class="course-tab active" role="tab" aria-selected="true" data-tab="overview">Overview</button>
<?php if ($curriculum): ?>              <button class="course-tab" role="tab" aria-selected="false" data-tab="curriculum">Curriculum</button>
<?php endif; ?>
              <button class="course-tab" role="tab" aria-selected="false" data-tab="certificate">Certificate</button>
            </div>

            <section class="course-panel" data-panel="overview">
<?php if ($outcomes): ?>
              <div class="learn-card">
                <h2>What you'll learn</h2>
                <ul class="outcomes">
<?php foreach ($outcomes as $o): ?>                  <li><?= e($o) ?></li>
<?php endforeach; ?>
                </ul>
              </div>
<?php endif; ?>
              <div class="article-body course-about">
<?= $c['body_html'] ?>
              </div>

<?php if ($skills): ?>
              <div class="skills-card">
                <h2>Skills you'll gain</h2>
                <div class="skills-tags">
<?php foreach ($skills as $sk): ?>                  <span class="skill-tag"><?= e($sk) ?></span>
<?php endforeach; ?>
                </div>
              </div>
<?php endif; ?>

              <div class="instructor-card">
                <h2>Your instructor</h2>
                <div class="instructor-row">
                  <span class="instructor-avatar" aria-hidden="true"><?= e(mb_substr($instructorName ?: 'Afrovanguard', 0, 1)) ?></span>
                  <div class="instructor-info">
                    <span class="instructor-name"><?= e($instructorName ?: 'The Afrovanguard Academy Faculty') ?></span>
                    <span class="instructor-role"><?= $instructorName ? 'Programme instructor · Afrovanguard Academy' : 'Practitioners and mentors raising one million incorruptible leaders' ?></span>
                  </div>
                </div>
              </div>

              <div class="faq-card">
                <h2>Frequently asked questions</h2>
<?php
                $faqs = [
                    ['Do I earn a certificate?', 'Yes. Complete every lesson to earn a verifiable Afrovanguard Academy certificate with a unique serial you can share on LinkedIn and your CV.'],
                    ['How much does it cost?', $access === 'paid'
                        ? 'This programme is ' . ($price > 0 ? $fmtNgn($price) . ' (one-time)' : 'paid') . '. Academy members get it included — see membership.'
                        : ($access === 'membership'
                            ? 'This is a members’ programme, unlocked by Academy membership (' . $fmtNgn((int) AV_MEMBERSHIP_NGN) . '/year).'
                            : 'This programme is free. ' . ($access === 'tracked' ? 'Create a free account to save your progress and earn your certificate.' : 'You can start straight away.'))],
                    ['How long does it take?', ($c['duration'] ? 'About ' . $c['duration'] . '. ' : '') . 'It’s self-paced' . ($lessonTotal ? ' across ' . $lessonTotal . ' lesson' . ($lessonTotal === 1 ? '' : 's') : '') . ', so you can learn on your own schedule.'],
                    ['Do I need any prior experience?', 'This programme is pitched at ' . strtolower((string) $c['level']) . '. Come curious and ready to build — we take it step by step.'],
                ];
                foreach ($faqs as $fi => $f): ?>
                <details class="faq-item"<?= $fi === 0 ? ' open' : '' ?>>
                  <summary><?= e($f[0]) ?><span class="faq-ico" aria-hidden="true"></span></summary>
                  <p><?= e(str_replace('’', '’', $f[1])) ?></p>
                </details>
<?php endforeach; ?>
              </div>
            </section>

<?php if ($curriculum): ?>
            <section class="course-panel" data-panel="curriculum" hidden>
              <div class="curriculum">
                <div class="curriculum-head">
                  <h2>Curriculum</h2>
                  <p class="curriculum-meta"><?= $moduleCount ?> module<?= $moduleCount === 1 ? '' : 's' ?> · <?= $lessonTotal ?> lesson<?= $lessonTotal === 1 ? '' : 's' ?><?= $totalMins ? ' · ' . $totalMins . ' min' : '' ?></p>
                  <button type="button" class="cur-expand" data-expand-all aria-expanded="false">Expand all</button>
                </div>
<?php if ($progress): ?>
                <div class="cur-progress"><span><?= (int)$progress['completed'] ?>/<?= (int)$progress['total'] ?> done</span><div class="cur-bar"><span style="width:<?= (int)$progress['pct'] ?>%"></span></div><span><?= (int)$progress['pct'] ?>%</span></div>
<?php if (!empty($progress['complete'])): ?>
                <div class="cert-banner">🎓 You've completed this programme. <a class="btn btn-pill-gold btn-sm" href="<?= e(academy_url($c['slug'] . '/certificate')) ?>" target="_blank" rel="noopener">Get your certificate →</a></div>
<?php endif; ?>
<?php endif; ?>
<?php foreach ($curriculum as $mi => $m):
                $mLessons = $m['lessons'];
                $mDone = 0; foreach ($mLessons as $l) { if (in_array((int)$l['id'], $doneIds, true)) $mDone++; }
                $open = $mi === 0; // first module open by default
?>
                <div class="module<?= $open ? ' is-open' : '' ?>">
                  <button type="button" class="module-head" aria-expanded="<?= $open ? 'true' : 'false' ?>">
                    <span class="module-toggle" aria-hidden="true"></span>
                    <span class="module-title"><?= e($m['title']) ?></span>
                    <span class="m-count"><?php if ($progress && $mDone): ?><span class="m-done"><?= $mDone ?>/<?= count($mLessons) ?></span> · <?php endif; ?><?= count($mLessons) ?> lesson<?= count($mLessons) === 1 ? '' : 's' ?></span>
                  </button>
                  <div class="module-body">
<?php foreach ($mLessons as $l):
                    $lOpen = !empty($l['is_preview']) || $access === 'open' || ($user && $lms->canAccess($user, $c, $l));
                    $done = in_array((int)$l['id'], $doneIds, true);
                    $href = academy_url($c['slug'] . '/learn/' . $l['slug']);
?>
                    <a class="lesson-row<?= $done ? ' done' : '' ?><?= $lOpen ? '' : ' locked' ?>" href="<?= e($href) ?>">
                      <span class="l-ico" aria-hidden="true"><?= $done ? '✓' : ($lOpen ? '▸' : '🔒') ?></span>
                      <span class="l-title"><?= e($l['title']) ?></span>
                      <span class="l-meta"><?php if (!empty($l['is_preview'])): ?><span class="l-preview">Preview</span><?php endif; ?><?php if ((int)$l['duration_min']): ?><span><?= (int)$l['duration_min'] ?> min</span><?php endif; ?></span>
                    </a>
<?php endforeach; ?>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
            </section>
<?php endif; ?>

            <section class="course-panel" data-panel="certificate" hidden>
              <div class="cert-panel">
                <div class="cert-panel-mark" aria-hidden="true">🎓</div>
                <h2>Earn a verifiable certificate</h2>
                <p>Complete every lesson in <strong><?= e($c['title']) ?></strong> to earn an Afrovanguard Academy Certificate of Completion — issued with a unique serial you can share and that anyone can verify online.</p>
                <ul class="cert-points">
                  <li>Personalised, branded certificate (PDF/PNG)</li>
                  <li>Unique serial number, publicly verifiable</li>
                  <li>Shareable on LinkedIn and your CV</li>
                </ul>
<?php if ($progress && !empty($progress['complete'])): ?>
                <a class="btn btn-pill-gold" href="<?= e(academy_url($c['slug'] . '/certificate')) ?>" target="_blank" rel="noopener">Get your certificate →</a>
<?php elseif ($progress): ?>
                <p class="cert-progress-note">You're <?= (int)$progress['pct'] ?>% of the way there — finish the remaining lessons to unlock it.</p>
<?php endif; ?>
              </div>
            </section>
          </div>

          <aside class="course-side">
            <div class="enroll-card<?= $access === 'paid' || $access === 'membership' ? ' pay-card' : '' ?>" id="enroll"<?= $access === 'paid' ? ' data-course="' . e($c['slug']) . '" data-kind="course"' : ($access === 'membership' ? ' data-kind="membership"' : '') ?>>
<?php if ($cover): ?>
              <div class="enroll-cover" style="background-image:url('<?= e($cover) ?>')" aria-hidden="true"></div>
<?php endif; ?>
              <div class="enroll-body">
<?php
                // ── Price / status line ──
                if ($access === 'paid') {
                    $priceTxt = $price > 0 ? $fmtNgn($price) : ($c['price'] ?: 'Paid');
                    $priceSub = 'one-time';
                } elseif ($access === 'membership') {
                    $priceTxt = $fmtNgn((int) AV_MEMBERSHIP_NGN);
                    $priceSub = 'per year';
                } else {
                    $priceTxt = 'Free';
                    $priceSub = $access === 'tracked' ? 'sign in to track progress' : 'open programme';
                }
?>
                <p class="enroll-price"><?= e($priceTxt) ?><span><?= e($priceSub) ?></span></p>

<?php if ($progress): ?>
                <div class="enroll-progress">
                  <div class="enroll-progress-row"><span><?= (int)$progress['completed'] ?> of <?= (int)$progress['total'] ?> lessons</span><span><?= (int)$progress['pct'] ?>%</span></div>
                  <div class="cur-bar"><span style="width:<?= (int)$progress['pct'] ?>%"></span></div>
                </div>
<?php endif; ?>

<?php /* ── Primary action ── */ ?>
<?php if ($access === 'paid'): ?>
<?php if ($hasAccess): ?>
                <p class="enroll-state">✓ You're enrolled — full access unlocked.</p>
<?php if ($firstLesson): ?>                <a class="btn btn-pill enroll-go" href="<?= e($resumeUrl) ?>"><?= $progress && !empty($progress['completed']) ? 'Continue learning' : 'Start learning' ?> →</a><?php endif; ?>
<?php elseif ($user): ?>
                <button type="button" class="btn btn-pill pay-btn" data-pay="course" data-course="<?= e($c['slug']) ?>">Enrol — <?= $price > 0 ? $fmtNgn($price) : 'pay now' ?> →</button>
                <p class="enroll-tiny">Members get this course included. <a href="<?= e(academy_url('')) ?>#membership">See membership →</a></p>
<?php else: ?>
                <button type="button" class="btn btn-pill" data-auth="register">Create an account to enrol →</button>
                <p class="enroll-tiny">Already have an account? <a href="#" data-auth="login">Sign in</a></p>
<?php endif; ?>
<?php elseif ($access === 'membership'): ?>
<?php if ($isMember): ?>
                <p class="enroll-state">✓ Your membership unlocks this programme.</p>
<?php if ($firstLesson): ?>                <a class="btn btn-pill enroll-go" href="<?= e($resumeUrl) ?>"><?= $progress && !empty($progress['completed']) ? 'Continue learning' : 'Start learning' ?> →</a><?php endif; ?>
<?php elseif ($user): ?>
                <button type="button" class="btn btn-pill pay-btn" data-pay="membership">Become a member →</button>
                <p class="enroll-tiny">Unlocks every members' programme.</p>
<?php else: ?>
                <button type="button" class="btn btn-pill" data-auth="register">Create an account to join →</button>
                <p class="enroll-tiny">Already a member? <a href="#" data-auth="login">Sign in</a></p>
<?php endif; ?>
<?php else: /* open / tracked */ ?>
<?php if ($lessonTotal && $firstLesson): ?>
                <a class="btn btn-pill enroll-go" href="<?= e($resumeUrl) ?>"><?= e($ctaLabel) ?> →</a>
<?php if ($access === 'tracked' && !$user): ?>                <p class="enroll-tiny">Free to join. <a href="#" data-auth="login">Sign in</a> to save your progress.</p>
<?php endif; ?>
<?php else: ?>
<?php /* No lessons yet → lead-capture application form */ ?>
                <p class="enroll-state">Free to join. Tell us a little about you and our team will reach out.</p>
                <form class="enroll-form" data-course="<?= e($c['slug']) ?>">
                  <input name="name" placeholder="Full name" required autocomplete="name" />
                  <input name="email" type="email" placeholder="Email address" required autocomplete="email" />
                  <input name="phone" placeholder="Phone (optional)" autocomplete="tel" />
                  <textarea name="note" rows="3" placeholder="Why are you interested? (optional)"></textarea>
                  <button type="submit" class="btn btn-pill">Submit application →</button>
                </form>
<?php endif; ?>
<?php endif; ?>
                <p class="enroll-msg" hidden></p>

                <ul class="enroll-meta">
                  <li><span>Level</span><strong><?= e($c['level']) ?></strong></li>
                  <li><span>Format</span><strong><?= e($c['format']) ?></strong></li>
<?php if (!empty($c['duration'])): ?>                  <li><span>Duration</span><strong><?= e($c['duration']) ?></strong></li>
<?php endif; ?>
<?php if ($lessonTotal): ?>                  <li><span>Lessons</span><strong><?= $lessonTotal ?></strong></li>
<?php endif; ?>
<?php if (!empty($c['location'])): ?>                  <li><span>Location</span><strong><?= e($c['location']) ?></strong></li>
<?php endif; ?>
                  <li><span>Certificate</span><strong>Yes, on completion</strong></li>
                </ul>
              </div>
            </div>
          </aside>
        </div>
      </div>

<?php if ($others): ?>
      <section class="similar">
        <div class="container">
          <h2>More programmes</h2>
          <section class="ac-grid">
<?php foreach ($others as $o): ?>
<?php ac_course_card($o, ['lessons' => $lms->lessonCount((int) $o['id']), 'enrolled' => $lms->enrolledCount((int) $o['id'])]); ?>
<?php endforeach; ?>
          </section>
        </div>
      </section>
<?php endif; ?>
    </article>
  </main>
<?php echo '<script src="/academy/academy.js" defer></script>'; render_footer();
