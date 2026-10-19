<?php
/**
 * give/unsubscribe.php — take an address off an appeal's update list.
 *
 * Deliberately unauthenticated beyond the HMAC in the link. Somebody who wants
 * out of a mailing list must not have to prove who they are first: an
 * unsubscribe behind a login is an unsubscribe that becomes a spam complaint,
 * and the complaint costs the whole domain its reputation.
 *
 * Answers both GET (a person clicking) and POST (a mail client acting on the
 * List-Unsubscribe-Post header, one-click, no page shown).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$email    = trim((string) ($_GET['e'] ?? $_POST['e'] ?? ''));
$appealId = (int) ($_GET['a'] ?? $_POST['a'] ?? 0);
$token    = trim((string) ($_GET['t'] ?? $_POST['t'] ?? ''));
$isPost   = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

/* Rate-limit the FAILURES only. A valid link must always work — this is the
   page somebody reaches when they are already annoyed, and a 429 there is how
   an unsubscribe becomes a report-as-spam. */
$done = Appeals::unsubscribe($email, $appealId, $token);
if (!$done && function_exists('av_rate_ok') && !av_rate_ok('give_unsub', 30, 600)) {
    http_response_code(429);
}

if ($isPost) {
    /* One-click from the mail client. It wants a 200 and nothing else. */
    http_response_code($done ? 200 : 400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $done ? 'Unsubscribed.' : 'Could not process that request.';
    exit;
}

$appeal = $appealId > 0 ? Appeals::byId($appealId) : null;
$name   = $appeal ? (string) $appeal['title'] : '';

render_head([
    'title'     => 'Email preferences · Afrovanguard',
    'desc'      => 'Manage the updates you get from Afrovanguard.',
    'canonical' => rtrim(SITE_URL, '/') . '/give/',
    'robots'    => 'noindex, nofollow',
    'css'       => ['/assets/site/editorial.css', '/give/give.css'],
    'body_class' => 'give',
]);
render_nav('involved');
?>
<main id="main" tabindex="-1" class="ed-wrap ed-section">
  <?php if ($done): ?>
    <span class="ed-kicker">Email preferences</span>
    <h1 class="ed-display">That's done</h1>
    <p class="ed-lede">
      <?php if ($name !== ''): ?>
        We won't email you about <strong><?= e($name) ?></strong> again.
      <?php else: ?>
        We won't email you about that appeal again.
      <?php endif; ?>
      It changes nothing else — your gift stands, and any receipt you have is still valid.
    </p>
    <p class="ed-lede">If you have a recurring gift set up, this does not stop it.
      Email <a href="mailto:<?= e(defined('DONATIONS_FROM_EMAIL') ? DONATIONS_FROM_EMAIL : 'cacentre@afrovanguard.org.ng') ?>">us</a>
      and we will cancel it the same day.</p>
    <p><a class="ed-link" href="/give/">See what we are raising for</a></p>
  <?php else: ?>
    <span class="ed-kicker ed-kicker--muted">Email preferences</span>
    <h1 class="ed-display">That link didn't work</h1>
    <p class="ed-lede">It may have been cut in half by an email client, or it may have expired.
      Either way we can sort it out by hand — send us a note and we will take you off the list today.</p>
    <p><a class="ed-link" href="mailto:<?= e(defined('DONATIONS_FROM_EMAIL') ? DONATIONS_FROM_EMAIL : 'cacentre@afrovanguard.org.ng') ?>?subject=<?= e(rawurlencode('Please stop emailing me about an appeal')) ?>">Email us to unsubscribe</a></p>
  <?php endif; ?>
</main>
<?php render_footer();
