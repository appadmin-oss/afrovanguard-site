<?php
/**
 * academy/_helpers.php — Academy-only view helpers.
 *
 * Self-contained presentation helpers used by index.php / course.php so the
 * catalogue card can carry richer, Coursera-style metadata (lesson counts +
 * a signed-in learner's enrolment / progress state) WITHOUT editing the shared
 * lib/partials.php (which is owned elsewhere). Pure rendering — no schema
 * changes, no new endpoints; it reads only the existing AcademyRepository /
 * LmsRepository / LmsAuth APIs.
 */
declare(strict_types=1);

/** Human label + CSS modifier for a course's access model. */
function ac_access_meta(string $access): array
{
    return [
        'open'       => ['label' => 'Free',          'cls' => 'open'],
        'tracked'    => ['label' => 'Free',          'cls' => 'tracked'],
        'membership' => ['label' => 'Members only',  'cls' => 'membership'],
        'paid'       => ['label' => 'Paid',          'cls' => 'paid'],
        'restricted' => ['label' => 'Restricted',    'cls' => 'restricted'],
    ][$access] ?? ['label' => 'Free', 'cls' => 'open'];
}

/** Price string for a card foot: free, members-only, or the NGN amount. */
function ac_price_label(array $c): string
{
    $access = $c['access_type'] ?? 'open';
    if ($access === 'paid') {
        $n = (int) ($c['price_ngn'] ?? 0);
        return $n > 0 ? '₦' . number_format($n) : ($c['price'] ?: 'Paid');
    }
    if ($access === 'membership') return 'Members';
    if ($access === 'restricted') return 'Restricted';
    return 'Free';
}

/**
 * Render one Academy catalogue card (Coursera-style).
 *
 * $opts:
 *   lessons  int    — lesson count (0 hides the count chip)
 *   state    string — '', 'enrolled', 'continue', 'done' (signed-in learners)
 *   pct      int    — progress percent (shown when state is enrolled/continue/done)
 *   cta      string — call-to-action label override
 */
function ac_course_card(array $c, array $opts = []): void
{
    $url     = '/academy/' . e($c['slug']) . '/';
    $cover   = $c['cover_url'] ?? '';
    $lessons = (int) ($opts['lessons'] ?? 0);
    $state   = (string) ($opts['state'] ?? '');
    $pct     = max(0, min(100, (int) ($opts['pct'] ?? 0)));
    $access  = $c['access_type'] ?? 'open';
    $am      = ac_access_meta($access);
    $search  = strtolower(($c['title'] ?? '') . ' ' . ($c['summary'] ?? '') . ' ' . ($c['category'] ?? '') . ' ' . ($c['level'] ?? ''));
    // Stable, comparable sort keys exposed to the client-side sorter.
    $featured = (int) ($c['featured'] ?? 0);
    $cta = $opts['cta'] ?? match ($state) {
        'done'      => 'Review',
        'continue'  => 'Continue',
        'enrolled'  => 'Continue',
        default     => 'View programme',
    };
    ?>
        <article class="ac-card" data-reveal
                 data-cat="<?= e(slugify((string) $c['category'])) ?>"
                 data-level="<?= e(slugify((string) $c['level'])) ?>"
                 data-search="<?= e($search) ?>"
                 data-title="<?= e(strtolower((string) $c['title'])) ?>"
                 data-featured="<?= $featured ?>"
                 data-lessons="<?= $lessons ?>">
          <a class="ac-thumb <?= $cover ? 'has-cover' : e($c['gradient'] ?: 'g-gold') . ' g-grain' ?>" href="<?= $url ?>" aria-label="<?= e($c['title']) ?>"<?= $cover ? ' style="background-image:url(\'' . e($cover) . '\')"' : '' ?>>
            <span class="ac-cat"><?= e($c['category']) ?></span>
<?php if ($state === 'done'): ?>            <span class="ac-state ac-state-done">✓ Completed</span>
<?php elseif ($state === 'continue' || $state === 'enrolled'): ?>            <span class="ac-state ac-state-go">In progress</span>
<?php endif; ?>
          </a>
          <div class="ac-body">
            <a class="ac-title" href="<?= $url ?>"><?= e($c['title']) ?></a>
            <p class="ac-summary"><?= e($c['summary']) ?></p>
<?php $enrolled = (int) ($opts['enrolled'] ?? 0); ?>
            <div class="ac-meta">
              <span class="ac-meta-item" title="Level"><?= e($c['level']) ?></span>
<?php if (!empty($c['duration'])): ?>              <span class="ac-dot" aria-hidden="true">·</span><span class="ac-meta-item" title="Duration"><?= e($c['duration']) ?></span>
<?php endif; ?>
<?php if ($lessons): ?>              <span class="ac-dot" aria-hidden="true">·</span><span class="ac-meta-item" title="Lessons"><?= $lessons ?> lesson<?= $lessons === 1 ? '' : 's' ?></span>
<?php endif; ?>            </div>
<?php if (($state === 'continue' || $state === 'enrolled' || $state === 'done') && $lessons): ?>
            <div class="ac-progress" aria-label="<?= $pct ?>% complete">
              <div class="ac-progress-bar"><span style="width:<?= $pct ?>%"></span></div>
              <span class="ac-progress-pct"><?= $pct ?>%</span>
            </div>
<?php endif; ?>
            <div class="ac-foot">
              <span class="ac-price ac-price-<?= e($am['cls']) ?>"><?= e(ac_price_label($c)) ?></span>
              <span class="ac-foot-note"><?= $enrolled > 0 ? number_format($enrolled) . ' enrolled' : 'New' ?></span>
            </div>
          </div>
        </article>
<?php }

/**
 * Decorate a list of course rows with lesson counts + per-learner state so the
 * catalogue grid can render them with one query batch. $user may be null.
 */
function ac_decorate_courses(array $courses, LmsRepository $lms, ?array $user): array
{
    $out = [];
    foreach ($courses as $c) {
        $id = (int) $c['id'];
        $lessons = $lms->lessonCount($id);
        $opt = ['lessons' => $lessons, 'enrolled' => $lms->enrolledCount($id)];
        if ($user && $lessons) {
            $access = $c['access_type'] ?? 'open';
            // "Enrolled" = explicit paid enrolment, OR any access where the
            // learner has started (has progress). Members/org accounts and open
            // courses count as enrolled-on-access so they get "Continue".
            $pr = $lms->progress((int) $user['id'], $id);
            $started = ($pr['completed'] ?? 0) > 0;
            $member  = $lms->isMember((int) $user['id']) || LmsAuth::isOrgMember($user);
            $enrolled = $lms->isEnrolled((int) $user['id'], $id);
            $hasAccess = $access === 'open' || $access === 'tracked' || $member || $enrolled;
            if (!empty($pr['complete'])) {
                $opt['state'] = 'done';
            } elseif ($started || ($enrolled && $access === 'paid')) {
                $opt['state'] = 'continue';
            } elseif ($hasAccess && $access !== 'open' && $access !== 'tracked') {
                $opt['state'] = 'enrolled';
            }
            $opt['pct'] = $pr['pct'] ?? 0;
        }
        $out[] = ['course' => $c, 'opts' => $opt];
    }
    return $out;
}
