<?php
/** academy/verify.php — public certificate verification. ?serial=AV-… */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$serial = trim((string) ($_GET['serial'] ?? ''));
$cert = $serial !== '' ? (new LmsRepository())->certificateBySerial($serial) : null;

render_head([
    'title' => 'Verify a certificate — Afrovanguard Academy',
    'desc'  => 'Verify the authenticity of an Afrovanguard Academy certificate.',
    'canonical' => academy_url('verify.php'), 'og_kind' => 'website',
    'css' => ['/academy/academy.css'], 'body_class' => 'academy',
]);
render_nav('academy');
?>
<main id="main-content">
  <div class="container" style="max-width:680px;padding:64px 24px 96px;text-align:center">
    <span class="diary-eyebrow" style="justify-content:center">Certificate verification</span>
<?php if ($cert): ?>
    <h1 style="font-size:clamp(32px,5vw,52px);margin:10px 0 18px">Verified ✓</h1>
    <div class="enroll-card" style="text-align:left;max-width:520px;margin:0 auto">
      <p style="margin:0 0 6px;color:var(--muted)">This certificate is authentic.</p>
      <p style="margin:0 0 4px"><strong>Learner:</strong> <?= e($cert['learner']) ?></p>
      <p style="margin:0 0 4px"><strong>Programme:</strong> <?= e($cert['course']) ?></p>
      <p style="margin:0 0 4px"><strong>Issued:</strong> <?= e(date('F j, Y', strtotime($cert['issued_at']))) ?></p>
      <p style="margin:0;color:var(--muted)"><strong>Serial:</strong> <?= e($cert['serial']) ?></p>
    </div>
<?php else: ?>
    <h1 style="font-size:clamp(32px,5vw,52px);margin:10px 0 18px">Not found</h1>
    <p style="color:var(--muted)">We couldn’t verify a certificate with that serial. Check the code and try again.</p>
    <form method="get" style="display:flex;gap:10px;max-width:420px;margin:24px auto 0">
      <input name="serial" placeholder="AV-XXXX-2026-…" value="<?= e($serial) ?>" style="flex:1;padding:13px 15px;border:1px solid var(--divider);border-radius:var(--radius-sm);background:var(--bg);color:var(--ink);font-family:var(--font-body)" />
      <button class="btn btn-primary" type="submit">Verify</button>
    </form>
<?php endif; ?>
    <p style="margin-top:28px"><a href="/academy/" style="color:var(--gold-deep);font-weight:600">← Back to the Academy</a></p>
  </div>
</main>
<?php render_footer();
