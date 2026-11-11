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
function avac_state(array $c, LmsRepository $lms, ?array $user, int $lessons, ?array $pre = null): array
{
    if (!$user || !$lessons) return ['state' => '', 'pct' => 0];
    $id = (int) $c['id'];
    $access = (string) ($c['access_type'] ?? 'open');
    /* $pre carries figures the caller already fetched for a whole grid
       (done count, enrolled) so a catalogue is not four queries a course. */
    if ($pre !== null) {
        $done = (int) ($pre['done'] ?? 0);
        $pr = ['completed' => $done, 'pct' => (int) round(min($done, $lessons) / $lessons * 100), 'complete' => $done >= $lessons];
        $enrolled = !empty($pre['enrolled']);
    } else {
        $pr = $lms->progress((int) $user['id'], $id);
        $enrolled = $lms->isEnrolled((int) $user['id'], $id);
    }
    $state = '';
    if (!empty($pr['complete'])) $state = 'done';
    elseif (($pr['completed'] ?? 0) > 0 || ($enrolled && $access === 'paid')) $state = 'continue';
    // Access is the LMS's own answer (v1 counted any member as enrolled, which
    // showed "In progress" on a restricted course a member cannot open).
    elseif ($access !== 'open' && $access !== 'tracked' && $lms->canAccess($user, $c, ['is_preview' => 0])) $state = 'enrolled';
    return ['state' => $state, 'pct' => (int) ($pr['pct'] ?? 0)];
}

/** Courses + lesson, enrolment and rating figures + the learner's state, for a grid. Grouped queries. */
function avac_decorate(array $courses, LmsRepository $lms, ?array $user): array
{
    $cw = new LmsCourseware(null, $lms);
    $st = $cw->stats(array_column($courses, 'id'));
    $done = $user ? $cw->doneCounts((int) $user['id']) : [];
    $mine = $user ? $cw->enrolledIds((int) $user['id']) : [];
    $out = [];
    foreach ($courses as $c) {
        $id = (int) $c['id'];
        $f = $st[$id] ?? ['lessons' => 0, 'minutes' => 0, 'enrolled' => 0, 'rating' => 0.0, 'ratings' => 0];
        $out[] = ['course' => $c, 'lessons' => $f['lessons'], 'enrolled' => $f['enrolled'], 'minutes' => $f['minutes'],
                  'rating' => $f['rating'], 'ratings' => $f['ratings']]
               + avac_state($c, $lms, $user, $f['lessons'], ['done' => $done[$id] ?? 0, 'enrolled' => isset($mine[$id])]);
    }
    return $out;
}

/** "4.6 ★ (12)" as accessible markup, or '' with no ratings. */
function avac_stars(float $avg, int $n): string
{
    if ($n < 1) return '';
    return '<span class="avac-rate"><b class="av-num">' . e(number_format($avg, 1)) . '</b><span aria-hidden="true">★</span>'
         . '<span class="av-num">(' . e(number_format($n)) . ')</span><span class="av-sr"> — rated ' . e(number_format($avg, 1)) . ' out of 5 by ' . e(avac_plural($n, 'learner')) . '</span></span>';
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
        /* The size it is shown at, not the size it was uploaded (lib/Thumbs.php). */
        $hero = str_contains($cls, 'hero');
        $set = Thumbs::srcset($cover, $hero ? [640, 960, 1280] : [320, 640, 960]);
        $sizes = $hero ? '(min-width: 960px) 600px, 100vw' : '(min-width: 1100px) 360px, (min-width: 640px) 50vw, 100vw';
        return '<img class="' . e($cls) . '" src="' . e(Thumbs::url($cover, $hero ? 960 : 640)) . '"'
             . ($set !== '' ? ' srcset="' . e($set) . '" sizes="' . e($sizes) . '"' : '')
             . ' alt="" ' . ($hero ? 'fetchpriority="high"' : 'loading="lazy"') . ' decoding="async">';
    }
    return '<span class="' . e($cls) . ' avac-tile" aria-hidden="true">' . ($mark ? '<span>' . e((string) $c['title']) . '</span>' : '') . '</span>';
}

/** One catalogue / "Keep learning" card. $row is an avac_decorate() row. */
function avac_card(array $row, bool $hidden = false): void
{
    $c = $row['course'];
    $url = '/academy/' . rawurlencode((string) $c['slug']) . '/';
    $lessons = (int) $row['lessons'];
    $state = (string) ($row['state'] ?? '');
    $pct = max(0, min(100, (int) ($row['pct'] ?? 0)));
    $tone = avac_access((string) ($c['access_type'] ?? 'open'))['tone'];
    $meta = array_filter([(string) ($c['level'] ?? ''), (string) ($c['duration'] ?? ''), $lessons ? avac_plural($lessons, 'lesson') : '']);
    $search = strtolower(($c['title'] ?? '') . ' ' . ($c['summary'] ?? '') . ' ' . ($c['category'] ?? '') . ' ' . ($c['level'] ?? '') . ' ' . str_replace("\n", ' ', (string) ($c['skills'] ?? '')));
    $enrolled = (int) ($row['enrolled'] ?? 0);
    $stars = avac_stars((float) ($row['rating'] ?? 0), (int) ($row['ratings'] ?? 0));
    /* A screen reader says a link's name every time it lands on it. The whole
       card used to be the name — price, track, summary, length, "View course" —
       so a learner tabbing the catalogue heard a paragraph per course. The
       course's title is the name now; the rest is its description, read after
       a pause or on request. */
    static $n = 0; $id = 'avac-card-' . (++$n);
    ?>
      <li class="avac-card"<?= $hidden ? ' hidden' : '' ?> data-cat="<?= e(slugify((string) $c['category'])) ?>" data-search="<?= e($search) ?>"
          data-title="<?= e(strtolower((string) $c['title'])) ?>" data-featured="<?= (int) ($c['featured'] ?? 0) ?>" data-lessons="<?= $lessons ?>"
          data-level="<?= e(slugify((string) ($c['level'] ?? ''))) ?>" data-price="<?= e(LmsCourseware::priceTone((string) ($c['access_type'] ?? 'open'))) ?>"
          data-enrolled="<?= $enrolled ?>" data-rating="<?= e((string) ($row['rating'] ?? 0)) ?>" data-ratings="<?= (int) ($row['ratings'] ?? 0) ?>"
          data-minutes="<?= (int) ($row['minutes'] ?? 0) ?>" data-created="<?= e((string) ($c['created_at'] ?? '')) ?>">
        <a class="avac-card-a" href="<?= e($url) ?>" aria-labelledby="<?= $id ?>-t" aria-describedby="<?= $id ?>-p <?= $id ?>-k <?= $id ?>-d<?= $meta ? ' ' . $id . '-m' : '' ?>">
          <span class="avac-card-media"><?= avac_cover($c, 'avac-card-img') ?>
            <span class="avac-chip avac-chip--<?= e($tone) ?>" id="<?= $id ?>-p"><?= e(avac_price($c)) ?></span>
<?php if ($state === 'done'): ?>            <span class="avac-state">Completed</span>
<?php elseif ($state !== ''): ?>            <span class="avac-state">In progress</span>
<?php endif; ?>
          </span>
          <span class="avac-card-body">
            <span class="avac-card-kick" id="<?= $id ?>-k"><?= e((string) $c['category']) ?></span>
            <span class="avac-card-t" id="<?= $id ?>-t"><?= e((string) $c['title']) ?></span>
            <span class="avac-card-d" id="<?= $id ?>-d"><?= e((string) $c['summary']) ?></span>
<?php if ($meta): ?>            <span class="avac-card-meta" id="<?= $id ?>-m"><?= e(implode(' · ', $meta)) ?></span>
<?php endif; ?>
<?php if ($stars !== ''): ?>            <?= $stars ?>
<?php endif; ?>
<?php if ($state !== '' && $lessons): ?>
            <span class="avac-prog"><span class="avac-bar"><span style="width:<?= $pct ?>%"></span></span><span class="av-num"><?= $pct ?>%</span><span class="av-sr"> complete</span></span>
<?php endif; ?>
            <span class="avac-card-foot"><span class="av-num"><?= $enrolled > 0 ? e(number_format($enrolled)) . ' enrolled' : 'New' ?></span><span class="avac-card-go"><?= e(avac_cta($state)) ?> →</span></span>
          </span>
        </a>
      </li>
<?php }

/**
 * The LMS footer: minimal on purpose. A learner in the Academy needs the
 * Academy's own ways around (catalogue, programmes, their learning, verify,
 * help) and the policies — not the whole organisation's sitemap and a
 * newsletter box under every lesson list. The site header still reaches the rest.
 */
function avac_footer(): void
{
    $set = function_exists('avh_settings') ? avh_settings() : [];
    $links = [['/academy/', 'Catalogue'], ['/academy/programmes/', 'Programmes'], ['/academy/my', 'My learning'],
              ['/academy/verify.php', 'Verify a certificate'], ['/academy/teach/', 'Teach'], ['/contact.html', 'Help']];
    ?>
<footer class="avac-foot">
  <div class="avac-foot-in avh-pad">
    <a class="avac-foot-brand" href="/academy/"><span class="avac-foot-mark" aria-hidden="true"></span>Afrovanguard Academy</a>
    <nav class="avac-foot-nav" aria-label="Academy">
<?php foreach ($links as [$href, $label]): ?>      <a href="<?= e($href) ?>"><?= e($label) ?></a>
<?php endforeach; ?>
    </nav>
    <div class="avac-foot-legal">
      <span><?= e((string) ($set['copyright'] ?? ('© ' . date('Y') . ' Afrovanguard'))) ?></span>
      <a href="/privacy-policy/">Privacy</a><a href="/terms/">Terms</a><a href="/privacy-policy/#cookies" data-av-consent-open>Cookie settings</a>
    </div>
  </div>
</footer>
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
  <script>document.documentElement.classList.replace('no-js','js');if(/(^|; )av_si=1/.test(document.cookie))document.documentElement.classList.add('av-si')</script>
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
<?php if ($jsonld): ?>  <script type="application/ld+json"><?= json_encode(count($jsonld) === 1 ? $jsonld[0] : ['@context' => 'https://schema.org', '@graph' => $jsonld], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
  <link rel="icon" href="/favicon.ico" sizes="any" />
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/site/icon-192.png" />
  <link rel="apple-touch-icon" href="/assets/site/icon-192.png" />
  <link rel="stylesheet" href="<?= e(av_asset('/assets/site/fonts.css')) ?>" />
  <link rel="stylesheet" href="<?= e(av_asset('/assets/site/av-tokens.css')) ?>" />
<?php foreach (($o['css'] ?? []) as $href): ?>  <link rel="stylesheet" href="<?= e(av_asset((string) $href)) ?>" />
<?php endforeach; foreach (($o['js'] ?? []) as $src): ?>  <script src="<?= e(av_asset((string) $src)) ?>" defer></script>
<?php endforeach; ?>
</head>
<?php }
