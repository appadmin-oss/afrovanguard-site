<?php
/**
 * academy/course.php — a single programme (from the DB).
 * Reached via /academy/<slug>/ (see .htaccess) or ?slug=<slug>.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

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
$accessLabels = ['open' => 'Open · free', 'tracked' => 'Free · sign in to track', 'membership' => 'Members only', 'paid' => 'Paid programme'];
$progress = ($user && $lessonTotal) ? $lms->progress((int) $user['id'], (int) $c['id']) : null;
$doneIds = $progress['ids'] ?? [];
$firstLesson = $ordered[0]['slug'] ?? '';

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
    <div class="container"><div class="pay-flash err" role="status">We couldn’t confirm that payment. If you were charged, contact us and we’ll sort it right away.</div></div>
<?php endif; ?>
    <article>
      <section class="course-hero <?= $cover ? 'has-cover' : e($c['gradient']) . ' g-grain' ?>"<?= $cover ? ' style="background-image:url(\'' . e($cover) . '\')"' : '' ?>>
        <div class="container">
          <nav class="breadcrumb light"><a href="/academy/">Academy</a><span class="sep">/</span><span><?= e($c['category']) ?></span></nav>
          <h1><?= e($c['title']) ?></h1>
          <p class="course-dek"><?= e($c['summary']) ?></p>
          <div class="course-badges">
            <span>◆ <?= e($c['level']) ?></span><span>● <?= e($c['format']) ?></span><span>◷ <?= e($c['duration']) ?></span>
<?php if ($lessonTotal): ?><span>📚 <?= $lessonTotal ?> lessons</span><?php endif; ?>
            <span class="badge-price"><?= e($accessLabels[$access] ?? $c['price']) ?></span>
          </div>
          <div class="course-hero-cta">
<?php if ($lessonTotal && $firstLesson): ?>
            <a class="btn btn-primary" href="<?= e(academy_url($c['slug'] . '/learn/' . $firstLesson)) ?>"><?= $progress && $progress['completed'] ? 'Continue learning' : 'Start learning' ?> →</a>
<?php else: ?>
            <a class="btn btn-primary" href="#enroll">Apply / enrol</a>
<?php endif; ?>
<?php if (!empty($c['cta_url'])): ?><a class="btn btn-ghost-light" href="<?= e($c['cta_url']) ?>" target="_blank" rel="noopener">Programme site ↗</a><?php endif; ?></div>
        </div>
      </section>

      <div class="container">
        <div class="course-layout">
          <div class="course-main article-body">
<?= $c['body_html'] ?>
<?php if ($outcomes): ?>
            <h2>What you will gain</h2>
            <ul class="outcomes">
<?php foreach ($outcomes as $o): ?>              <li><?= e($o) ?></li>
<?php endforeach; ?>
            </ul>
<?php endif; ?>

<?php if ($curriculum): ?>
            <div class="curriculum">
              <h2>Curriculum</h2>
<?php if ($progress): ?>
              <div class="cur-progress"><span><?= (int)$progress['completed'] ?>/<?= (int)$progress['total'] ?> done</span><div class="cur-bar"><span style="width:<?= (int)$progress['pct'] ?>%"></span></div><span><?= (int)$progress['pct'] ?>%</span></div>
<?php if (!empty($progress['complete'])): ?>
              <div class="cert-banner">🎓 You’ve completed this programme. <a class="btn btn-primary btn-sm" href="<?= e(academy_url($c['slug'] . '/certificate')) ?>" target="_blank" rel="noopener">Get your certificate →</a></div>
<?php endif; ?>
<?php endif; ?>
<?php foreach ($curriculum as $m): ?>
              <div class="module">
                <div class="module-head"><span><?= e($m['title']) ?></span><span class="m-count"><?= count($m['lessons']) ?> lessons</span></div>
<?php foreach ($m['lessons'] as $l):
                $open = !empty($l['is_preview']) || $access === 'open' || ($user && $lms->canAccess($user, $c, $l));
                $done = in_array((int)$l['id'], $doneIds, true);
                $href = academy_url($c['slug'] . '/learn/' . $l['slug']);
?>
                <a class="lesson-row<?= $done ? ' done' : '' ?><?= $open ? '' : ' locked' ?>" href="<?= e($href) ?>">
                  <span class="l-ico"><?= $done ? '✓' : ($open ? '▸' : '🔒') ?></span>
                  <span class="l-title"><?= e($l['title']) ?></span>
                  <span class="l-meta"><?php if (!empty($l['is_preview'])): ?><span class="l-preview">Preview</span><?php endif; ?><?php if ((int)$l['duration_min']): ?><span><?= (int)$l['duration_min'] ?> min</span><?php endif; ?></span>
                </a>
<?php endforeach; ?>
              </div>
<?php endforeach; ?>
            </div>
<?php endif; ?>
          </div>
          <aside class="course-side">
<?php
            $price = (int) ($c['price_ngn'] ?? 0);
            $hasAccess = $user && $lms->canAccess($user, $c, ['is_preview' => 0]);
            $isMember = $user ? $lms->isMember((int) $user['id']) : false;
            $fmtNgn = fn(int $n) => '₦' . number_format($n);
?>
<?php if ($access === 'paid'): ?>
            <div class="enroll-card pay-card" id="enroll" data-course="<?= e($c['slug']) ?>" data-kind="course">
<?php if ($hasAccess): ?>
              <h3>You’re enrolled ✓</h3>
              <p>You have full access to <?= e($c['title']) ?>.</p>
<?php if ($firstLesson): ?><a class="btn btn-primary" style="width:100%" href="<?= e(academy_url($c['slug'] . '/learn/' . $firstLesson)) ?>">Continue learning →</a><?php endif; ?>
<?php else: ?>
              <h3>Enrol in <?= e($c['title']) ?></h3>
              <p class="pay-price"><?= $price > 0 ? $fmtNgn($price) : e($c['price']) ?><span> · one-time</span></p>
              <p>Pay securely with card or transfer to unlock every lesson, quiz and your certificate.</p>
<?php if ($user): ?>
              <button type="button" class="btn btn-primary pay-btn" data-pay="course" data-course="<?= e($c['slug']) ?>" style="width:100%">Enrol — <?= $price > 0 ? $fmtNgn($price) : 'pay now' ?> →</button>
<?php else: ?>
              <button type="button" class="btn btn-primary" data-auth="register" style="width:100%">Create an account to enrol →</button>
              <p class="enroll-tiny">Already have an account? <a href="#" data-auth="login">Sign in</a></p>
<?php endif; ?>
              <p class="enroll-tiny">Members get this course included. <a href="<?= e(academy_url('')) ?>#membership">See membership →</a></p>
              <p class="enroll-msg" hidden></p>
<?php endif; ?>
            </div>
<?php elseif ($access === 'membership'): ?>
            <div class="enroll-card pay-card" id="enroll" data-kind="membership">
<?php if ($isMember): ?>
              <h3>Members’ programme ✓</h3>
              <p>Your membership unlocks <?= e($c['title']) ?> in full.</p>
<?php if ($firstLesson): ?><a class="btn btn-primary" style="width:100%" href="<?= e(academy_url($c['slug'] . '/learn/' . $firstLesson)) ?>">Start learning →</a><?php endif; ?>
<?php else: ?>
              <h3>Members only</h3>
              <p class="pay-price"><?= $fmtNgn((int) AV_MEMBERSHIP_NGN) ?><span> · per year</span></p>
              <p>Become an Afrovanguard Academy member to unlock <?= e($c['title']) ?> and every members’ programme.</p>
<?php if ($user): ?>
              <button type="button" class="btn btn-primary pay-btn" data-pay="membership" style="width:100%">Become a member →</button>
<?php else: ?>
              <button type="button" class="btn btn-primary" data-auth="register" style="width:100%">Create an account to join →</button>
              <p class="enroll-tiny">Already a member? <a href="#" data-auth="login">Sign in</a></p>
<?php endif; ?>
              <p class="enroll-msg" hidden></p>
<?php endif; ?>
            </div>
<?php else: ?>
            <div class="enroll-card" id="enroll">
              <h3>Apply to <?= e($c['title']) ?></h3>
              <p>Free to join. Tell us a little about you and our team will reach out.</p>
              <form class="enroll-form" data-course="<?= e($c['slug']) ?>">
                <input name="name" placeholder="Full name" required autocomplete="name" />
                <input name="email" type="email" placeholder="Email address" required autocomplete="email" />
                <input name="phone" placeholder="Phone (optional)" autocomplete="tel" />
                <textarea name="note" rows="3" placeholder="Why are you interested? (optional)"></textarea>
                <button type="submit" class="btn btn-primary" style="width:100%">Submit application →</button>
                <p class="enroll-msg" hidden></p>
              </form>
            </div>
<?php endif; ?>
          </aside>
        </div>
      </div>

<?php if ($others): ?>
      <section class="similar">
        <div class="container">
          <h2>More programmes</h2>
          <section class="ac-grid"><?php foreach ($others as $o) render_course_card($o); ?></section>
        </div>
      </section>
<?php endif; ?>
    </article>
  </main>
<?php echo '<script src="/academy/academy.js" defer></script>'; render_footer();
