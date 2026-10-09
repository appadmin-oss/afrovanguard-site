<?php
/**
 * how-it-works.php — "How Afrovanguard Works": the Progressive Growth &
 * Member Alignment Framework. A public page explaining the membership
 * progression (Level O → A → C…), advancement, contributions and the
 * servant-leadership culture. Served at /how-it-works.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$MONTHLY = defined('AV_DUES_MONTHLY_NGN') ? (int) AV_DUES_MONTHLY_NGN : 1000;
$ANNUAL  = defined('AV_DUES_ANNUAL_NGN')  ? (int) AV_DUES_ANNUAL_NGN  : 12000;

render_head([
    'title'     => 'How Afrovanguard Works — Progressive Growth & Member Alignment',
    'desc'      => 'How members grow at Afrovanguard: an algorithmic progression built on verifiable output, mentorship, invisible service, radical transparency and servant leadership — from Foundation Member (Level O) upward.',
    'canonical' => rtrim(SITE_URL, '/') . '/how-it-works',
    'body_class' => 'hiw-page',
]);
render_nav('about');
?>
<main id="main-content" class="hiw">
</main>

<?php render_footer(); ?>
