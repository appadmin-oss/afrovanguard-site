<?php
/**
 * portal/_pulse-card.php — "Share my growth": the Vanguard Pulse card.
 *
 * From the design's "Share my growth — Vanguard Pulse card" board. The member
 * sees the card BEFORE anything is shared — it opens in a dialog, and nothing
 * leaves the page until they press a share button (portal/home.js). What is
 * shared is their own numbers and their invite link; nothing about anybody else.
 *
 * Expects $pulse from portal/views/today.php:
 *   name, initials, photo, level, level_label, pct (to the next level),
 *   next_label, week, books, books_total, courses, meetings, meet_rate, mentees, invite
 *
 * The QR is drawn here, by the encoder already in the tree (chillerlan, as for
 * the gate passes and the MFA codes), so the link is never sent to a chart service.
 */
declare(strict_types=1);

$pqr = '';
if (class_exists(\chillerlan\QRCode\QRCode::class) && $pulse['invite'] !== '') {
    try {
        $pqr = (new \chillerlan\QRCode\QRCode(new \chillerlan\QRCode\QROptions([
            'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class,
            'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
            'addQuietzone' => true, 'quietzoneSize' => 2,
            'outputBase64' => false, 'svgAddXmlHeader' => false,
            'drawLightModules' => false, 'connectPaths' => true,
        ])))->render((string) $pulse['invite']);
    } catch (Throwable $e) { error_log('[pulse] qr: ' . $e->getMessage()); }
}
$pArc = 2 * M_PI * 62;   /* the ring round the photo, r = 62 */
$pTxt = $pulse['name'] . ' on Vanguard Pulse — ' . $pulse['level_label']
      . ($pulse['books'] ? ' · ' . $pulse['books'] . ' book' . ($pulse['books'] === 1 ? '' : 's') . ' read' : '')
      . ($pulse['courses'] ? ' · ' . $pulse['courses'] . ' course' . ($pulse['courses'] === 1 ? '' : 's') . ' completed' : '')
      . ($pulse['meetings'] ? ' · ' . $pulse['meetings'] . ' meeting' . ($pulse['meetings'] === 1 ? '' : 's') . ' attended' : '')
      . '. Raising 1 million incorruptible leaders for Africa. Join: ' . $pulse['invite'];
?>
<dialog class="hv-pulse-dlg" id="hvPulse" aria-labelledby="hvPulseName" data-text="<?= e($pTxt) ?>" data-url="<?= e((string) $pulse['invite']) ?>">
  <div class="hv-pulse-wrap">
    <article class="hv-pulse" id="hvPulseCard">
      <header class="hv-pulse-hd">
        <span><img src="/assets/site/av-seal-96.png" alt="" width="36" height="36"><span class="hv-pulse-brand"><b>Vanguard Pulse</b><span>by Afrovanguard</span></span></span>
        <span class="hv-pulse-wk"><?= e($pulse['week']) ?></span>
      </header>

      <section class="hv-tile hv-tile-me">
        <span class="hv-ring" aria-hidden="true">
          <svg width="132" height="132" viewBox="0 0 132 132"><circle cx="66" cy="66" r="62" fill="none" stroke="#F1EADB" stroke-width="6"/><?php if ((int) $pulse['pct'] > 0): ?><circle cx="66" cy="66" r="62" fill="none" stroke="#F3B416" stroke-width="6" stroke-dasharray="<?= round($pArc * $pulse['pct'] / 100, 1) ?> <?= round($pArc, 1) ?>" stroke-linecap="round" transform="rotate(-90 66 66)"/><?php endif; ?></svg>
          <span class="hv-avatar"><?php if ($pulse['photo'] !== ''): ?><img src="<?= e($pulse['photo']) ?>" alt=""><?php else: ?><?= e($pulse['initials']) ?><?php endif; ?></span>
        </span>
        <span style="flex:1;min-width:0;display:flex;flex-direction:column;gap:8px">
          <h1 id="hvPulseName"><?= e($pulse['name']) ?></h1>
          <span class="hv-tile-lvl">Level <?= e($pulse['level']) ?> · <?= e($pulse['level_label']) ?></span>
<?php if ($pulse['next_label'] !== ''): ?>
          <span style="display:flex;flex-direction:column;gap:5px;margin-top:2px"><span class="hv-tile-bar"><i style="width:<?= (int) $pulse['pct'] ?>%"></i></span><span class="hv-tile-pct"><?= (int) $pulse['pct'] ?>% of the way to <?= e($pulse['next_label']) ?></span></span>
<?php endif; ?>
        </span>
      </section>

      <div class="hv-tile hv-tile--ink">
        <span class="hv-tile-ic" aria-hidden="true"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 5a2 2 0 0 1 2-2h5a2 2 0 0 1 2 2v16a2 2 0 0 0-2-2H3zM21 5a2 2 0 0 0-2-2h-5a2 2 0 0 0-2 2v16a2 2 0 0 1 2-2h7z"/></svg></span>
        <span class="hv-tile-n"><?= (int) $pulse['books'] ?></span><span class="hv-tile-l">book<?= $pulse['books'] === 1 ? '' : 's' ?> read</span><span class="hv-tile-s">of <?= (int) $pulse['books_total'] ?> in the challenge</span>
      </div>
      <div class="hv-tile hv-tile--gold">
        <span class="hv-tile-ic" aria-hidden="true"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11v5c3 2 9 2 12 0v-5"/></svg></span>
        <span class="hv-tile-n"><?= (int) $pulse['courses'] ?></span><span class="hv-tile-l">course<?= $pulse['courses'] === 1 ? '' : 's' ?> completed</span><span class="hv-tile-s">with certificates</span>
      </div>
      <div class="hv-tile hv-tile--white">
        <span class="hv-tile-ic" aria-hidden="true"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg></span>
        <span class="hv-tile-n"><?= (int) $pulse['meetings'] ?></span><span class="hv-tile-l">meeting<?= $pulse['meetings'] === 1 ? '' : 's' ?> attended</span><span class="hv-tile-s"><?= $pulse['meet_rate'] !== null ? (int) $pulse['meet_rate'] . '% attendance' : 'mentorship sessions' ?></span>
      </div>
      <div class="hv-tile hv-tile--green">
        <span class="hv-tile-ic" aria-hidden="true"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6"/></svg></span>
        <span class="hv-tile-n"><?= (int) $pulse['mentees'] ?></span><span class="hv-tile-l">mentee<?= $pulse['mentees'] === 1 ? '' : 's' ?> guided</span><span class="hv-tile-s">actively mentored</span>
      </div>

      <p class="hv-pulse-q">“I’m not merely counting my days. I’m making my days count.”</p>

      <footer class="hv-pulse-ft">
        <p><b>Raising 1 million incorruptible leaders for Africa.</b>Scan to join · afrovanguard.org.ng</p>
<?php if ($pqr !== ''): ?>
        <span role="img" aria-label="QR code: your invite link" style="display:block;width:66px;height:66px;background:#FFFFFF;border-radius:12px;overflow:hidden;flex-shrink:0"><?= str_replace('<svg ', '<svg width="66" height="66" ', $pqr) ?></span>
<?php endif; ?>
      </footer>
    </article>

    <p class="hv-pulse-note">This is exactly what will be shared — your own numbers and your invite link, nothing else.</p>
    <div class="hv-pulse-acts">
      <a class="hv-btn hv-btn--ink" id="hvPulseWa" href="https://wa.me/?text=<?= rawurlencode($pTxt) ?>" target="_blank" rel="noopener noreferrer">Send on WhatsApp</a>
      <button type="button" class="hv-btn" id="hvPulseShare" hidden>Share…</button>
      <button type="button" class="hv-btn" id="hvPulseCopy">Copy text</button>
      <button type="button" class="hv-btn hv-btn--ghost" id="hvPulseClose">Close</button>
    </div>
    <p class="hv-pulse-note" id="hvPulseMsg" role="status" aria-live="polite"></p>
  </div>
</dialog>
