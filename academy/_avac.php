<?php
/**
 * academy/_avac.php — shared view helpers for the redesigned Academy pages
 * (catalogue avac-, course avco-, lesson player avle-).
 *
 * Designs: "Afrovanguard Academy", "Afrovanguard Course", "Afrovanguard Lesson".
 * Pure presentation over the existing contracts — AcademyRepository,
 * LmsRepository, LmsAuth. No schema, no endpoints: every write still goes
 * through academy/api.php and payments return through academy/pay.php.
 *
 * The pages are light by design. The shared site theme (THEME_BOOT) is not
 * loaded, so `data-theme="dark"` is never set here; `color-scheme: light` in the
 * page CSS keeps native controls light as well.
 */
declare(strict_types=1);

/** Access model → label + chip tone. */
function avac_access(string $access): array
{
    return [
        'open'       => ['label' => 'Free',         'tone' => 'free'],
        'tracked'    => ['label' => 'Free',         'tone' => 'free'],
        'membership' => ['label' => 'Members only', 'tone' => 'members'],
        'paid'       => ['label' => 'Paid',         'tone' => 'paid'],
        'restricted' => ['label' => 'Restricted',   'tone' => 'paid'],
    ][$access] ?? ['label' => 'Free', 'tone' => 'free'];
}

/** Short price for a card: Free, Members, Restricted, or the naira amount. */
function avac_price(array $c): string
{
    $access = (string) ($c['access_type'] ?? 'open');
    if ($access === 'paid') {
        $n = (int) ($c['price_ngn'] ?? 0);
        return $n > 0 ? '₦' . number_format($n) : ((string) ($c['price'] ?? '') ?: 'Paid');
    }
    if ($access === 'membership') return 'Members';
    if ($access === 'restricted') return 'Restricted';
    return 'Free';
}

/** "1 lesson" / "6 lessons". */
function avac_plural(int $n, string $word): string
{
    return $n . ' ' . $word . ($n === 1 ? '' : 's');
}

/**
 * A learner's state on a course: '', 'enrolled', 'continue' or 'done', plus %.
 * As v1's catalogue: anyone with access to a gated course counts as enrolled,
 * anyone who has started counts as "continue".
 */
function avac_state(array $c, LmsRepository $lms, ?array $user, int $lessons): array
{
    if (!$user || !$lessons) return ['state' => '', 'pct' => 0];
    $id = (int) $c['id'];
    $access = (string) ($c['access_type'] ?? 'open');
    $pr = $lms->progress((int) $user['id'], $id);
    $enrolled = $lms->isEnrolled((int) $user['id'], $id);
    // Access is the LMS's own answer (v1 counted any member as enrolled, which
    // showed "In progress" on a restricted course a member cannot open).
    $hasAccess = $lms->canAccess($user, $c, ['is_preview' => 0]);
    $state = '';
    if (!empty($pr['complete'])) $state = 'done';
    elseif (($pr['completed'] ?? 0) > 0 || ($enrolled && $access === 'paid')) $state = 'continue';
    elseif ($hasAccess && $access !== 'open' && $access !== 'tracked') $state = 'enrolled';
    return ['state' => $state, 'pct' => (int) ($pr['pct'] ?? 0)];
}

/** Courses + lesson counts + enrolment counts + the learner's state, for a grid. */
function avac_decorate(array $courses, LmsRepository $lms, ?array $user): array
{
    $out = [];
    foreach ($courses as $c) {
        $lessons = $lms->lessonCount((int) $c['id']);
        $out[] = ['course' => $c, 'lessons' => $lessons, 'enrolled' => $lms->enrolledCount((int) $c['id'])]
               + avac_state($c, $lms, $user, $lessons);
    }
    return $out;
}

/** CTA label for a learner state. */
function avac_cta(string $state, string $fallback = 'View course'): string
{
    return match ($state) { 'done' => 'Review', 'continue', 'enrolled' => 'Continue', default => $fallback };
}

/** Cover image or the course's gradient tile (with the title as a mark). */
function avac_cover(array $c, string $cls, bool $mark = true): string
{
    $cover = (string) ($c['cover_url'] ?? '');
    if ($cover !== '') {
        return '<img class="' . e($cls) . '" src="' . e($cover) . '" alt="" loading="lazy" decoding="async">';
    }
    return '<span class="' . e($cls) . ' avac-tile" aria-hidden="true">' . ($mark ? '<span>' . e((string) $c['title']) . '</span>' : '') . '</span>';
}

/** One catalogue / "Keep learning" card. $row is an avac_decorate() row. */
function avac_card(array $row): void
{
    $c = $row['course'];
    $url = '/academy/' . rawurlencode((string) $c['slug']) . '/';
    $lessons = (int) $row['lessons'];
    $state = (string) ($row['state'] ?? '');
    $pct = max(0, min(100, (int) ($row['pct'] ?? 0)));
    $tone = avac_access((string) ($c['access_type'] ?? 'open'))['tone'];
    $meta = array_filter([(string) ($c['level'] ?? ''), (string) ($c['duration'] ?? ''), $lessons ? avac_plural($lessons, 'lesson') : '']);
    $search = strtolower(($c['title'] ?? '') . ' ' . ($c['summary'] ?? '') . ' ' . ($c['category'] ?? '') . ' ' . ($c['level'] ?? ''));
    $enrolled = (int) ($row['enrolled'] ?? 0);
    ?>
      <li class="avac-card" data-cat="<?= e(slugify((string) $c['category'])) ?>" data-search="<?= e($search) ?>"
          data-title="<?= e(strtolower((string) $c['title'])) ?>" data-featured="<?= (int) ($c['featured'] ?? 0) ?>" data-lessons="<?= $lessons ?>">
        <a class="avac-card-a" href="<?= e($url) ?>">
          <span class="avac-card-media"><?= avac_cover($c, 'avac-card-img') ?>
            <span class="avac-chip avac-chip--<?= e($tone) ?>"><?= e(avac_price($c)) ?></span>
<?php if ($state === 'done'): ?>            <span class="avac-state">Completed</span>
<?php elseif ($state !== ''): ?>            <span class="avac-state">In progress</span>
<?php endif; ?>
          </span>
          <span class="avac-card-body">
            <span class="avac-card-kick"><?= e((string) $c['category']) ?></span>
            <span class="avac-card-t"><?= e((string) $c['title']) ?></span>
            <span class="avac-card-d"><?= e((string) $c['summary']) ?></span>
<?php if ($meta): ?>            <span class="avac-card-meta"><?= e(implode(' · ', $meta)) ?></span>
<?php endif; ?>
<?php if ($state !== '' && $lessons): ?>
            <span class="avac-prog"><span class="avac-bar"><span style="width:<?= $pct ?>%"></span></span><span class="av-num"><?= $pct ?>%</span><span class="av-sr"> complete</span></span>
<?php endif; ?>
            <span class="avac-card-foot"><span class="av-num"><?= $enrolled > 0 ? e(number_format($enrolled)) . ' enrolled' : 'New' ?></span><span class="avac-card-go"><?= e(avac_cta($state)) ?> →</span></span>
          </span>
        </a>
      </li>
<?php }

/** Pay return flag (?pay=paid|failed) → a status line, or nothing. */
function avac_pay_flash(string $cls): void
{
    $flag = preg_replace('/[^a-z]/', '', strtolower((string) ($_GET['pay'] ?? '')));
    if ($flag === 'paid') {
        echo '<div class="' . e($cls) . ' is-ok" role="status">Payment confirmed — you now have full access. Welcome aboard.</div>';
    } elseif ($flag === 'failed') {
        echo '<div class="' . e($cls) . ' is-err" role="status">We couldn’t confirm that payment. If you were charged, contact us and we’ll sort it right away.</div>';
    }
}

/**
 * The <head> for the three pages (SEO kept from v1: title, description,
 * canonical, OG/Twitter, JSON-LD, keywords) and the opening of <body>.
 *   $o: title, desc, canonical, image, image_alt, keywords, jsonld[], css[], js[], body
 */
function avac_head(array $o): void
{
    $S = rtrim(SITE_URL, '/');
    $title = (string) ($o['title'] ?? 'Afrovanguard Academy');
    $desc = (string) ($o['desc'] ?? '');
    $canonical = (string) ($o['canonical'] ?? $S . '/academy/');
    $image = (string) ($o['image'] ?? $S . '/Images/og-image.png');
    $imageAlt = (string) ($o['image_alt'] ?? $title);
    $jsonld = $o['jsonld'] ?? [];
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
<?php if (!empty($o['keywords'])): ?>  <meta name="keywords" content="<?= e((string) $o['keywords']) ?>" />
<?php endif; ?>
  <meta name="author" content="Afrovanguard — afrovanguard.org.ng" />
  <meta name="robots" content="<?= e((string) ($o['robots'] ?? 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1')) ?>" />
  <link rel="canonical" href="<?= e($canonical) ?>" />
  <meta name="theme-color" content="rgb(17,24,39)" />
  <meta property="og:type" content="website" />
  <meta property="og:site_name" content="Afrovanguard" />
  <meta property="og:locale" content="en_NG" />
  <meta property="og:title" content="<?= e($title) ?>" />
  <meta property="og:description" content="<?= e($desc) ?>" />
  <meta property="og:url" content="<?= e($canonical) ?>" />
  <meta property="og:image" content="<?= e($image) ?>" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />
  <meta property="og:image:alt" content="<?= e($imageAlt) ?>" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:site" content="@afrovanguard" />
  <meta name="twitter:title" content="<?= e($title) ?>" />
  <meta name="twitter:description" content="<?= e($desc) ?>" />
  <meta name="twitter:image" content="<?= e($image) ?>" />
  <meta name="twitter:image:alt" content="<?= e($imageAlt) ?>" />
<?php if ($jsonld): ?>  <script type="application/ld+json"><?= json_encode(count($jsonld) === 1 ? $jsonld[0] : ['@context' => 'https://schema.org', '@graph' => $jsonld], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
  <link rel="icon" href="/favicon.ico" sizes="any" />
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/site/icon-192.png" />
  <link rel="apple-touch-icon" href="/assets/site/icon-192.png" />
  <link rel="stylesheet" href="/assets/site/fonts.css" />
  <link rel="stylesheet" href="/assets/site/av-tokens.css" />
<?php foreach (($o['css'] ?? []) as $href): ?>  <link rel="stylesheet" href="<?= e((string) $href) ?>" />
<?php endforeach; foreach (($o['js'] ?? []) as $src): ?>  <script src="<?= e((string) $src) ?>" defer></script>
<?php endforeach; ?>
</head>
<?php }
