<?php
/** academy/verify.php — public certificate verification. ?serial=AV-… */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$serial = trim((string) ($_GET['serial'] ?? ''));
// Rate-limit lookups so a serial can't be brute-forced against this public endpoint.
$cert = null; $limited = false;
if ($serial !== '') {
    if (!av_rate_ok('cert_verify', 40, 600)) $limited = true;
    /* AVP- serials are programme certificates (a series of courses); AV- are courses. */
    elseif (stripos($serial, 'AVP-') === 0) {
        $pc = (new LmsCourseware())->programmeCertBySerial($serial);
        if ($pc) $cert = ['learner' => $pc['learner'], 'course' => $pc['programme_title'] . ' (programme)', 'issued_at' => $pc['issued_at'], 'serial' => $pc['serial']];
    } else {
        $cert = (new LmsRepository())->certificateBySerial($serial);
    }
}
/* Three states, each saying what it is: nothing asked yet, verified, or not
   found. The page used to open on a heading reading "Not found" before
   anybody had typed anything — the first thing a screen reader announced. */
$state = $cert ? 'ok' : ($serial === '' ? 'ask' : ($limited ? 'limited' : 'none'));

render_head([
    'title' => 'Verify a certificate — Afrovanguard Academy',
    'desc'  => 'Verify the authenticity of an Afrovanguard Academy certificate.',
    'canonical' => academy_url('verify.php'), 'og_kind' => 'website',
    'css' => ['/academy/academy.css'], 'body_class' => 'academy',
]);
render_nav('academy');
?>
<main id="main" tabindex="-1">
  <div class="container avv-wrap">
    <span class="diary-eyebrow" style="justify-content:center">Certificate verification</span>
<?php if ($state === 'ok'): ?>
    <h1 class="avv-h1">Certificate verified</h1>
    <div class="enroll-card avv-card" role="status">
      <p class="avv-muted">This certificate is authentic.</p>
      <dl class="avv-dl">
        <div><dt>Learner</dt><dd><?= e($cert['learner']) ?></dd></div>
        <div><dt>Course or programme</dt><dd><?= e($cert['course']) ?></dd></div>
        <div><dt>Issued</dt><dd><?= e(date('j F Y', strtotime($cert['issued_at']))) ?></dd></div>
        <div><dt>Serial number</dt><dd class="av-num"><?= e($cert['serial']) ?></dd></div>
      </dl>
    </div>
    <p class="avv-again"><a href="/academy/verify.php">Check another certificate</a></p>
<?php else: ?>
    <h1 class="avv-h1"><?= $state === 'ask' ? 'Verify a certificate' : ($state === 'limited' ? 'Too many checks' : 'Certificate not found') ?></h1>
<?php if ($state === 'ask'): ?>
    <p class="avv-muted">Enter the serial number printed at the bottom of an Afrovanguard Academy certificate to check that it is genuine.</p>
<?php elseif ($state === 'limited'): ?>
    <p class="avv-muted" role="alert">Please wait a few minutes before checking again.</p>
<?php endif; ?>
    <form method="get" class="avv-form" action="/academy/verify.php">
      <label for="avv-serial" class="avv-label">Certificate serial number</label>
      <p id="avv-hint" class="avv-hint">It starts with AV- (a course) or AVP- (a programme), for example AV-3F9A1C27B0D4-2026.</p>
<?php if ($state === 'none'): ?>
      <p id="avv-err" class="avv-err" role="alert">We could not find a certificate with the serial “<?= e($serial) ?>”. Check each character and try again.</p>
<?php endif; ?>
      <div class="avv-row">
        <input id="avv-serial" name="serial" value="<?= e($serial) ?>" autocomplete="off" autocapitalize="characters" spellcheck="false" required
               aria-describedby="avv-hint<?= $state === 'none' ? ' avv-err' : '' ?>"<?= $state === 'none' ? ' aria-invalid="true" autofocus' : '' ?>>
        <button class="btn btn-primary" type="submit">Verify</button>
      </div>
    </form>
<?php endif; ?>
    <p class="avv-back"><a href="/academy/">← Back to the Academy</a></p>
  </div>
</main>
<?php render_footer();
