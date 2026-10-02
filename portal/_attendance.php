<?php
/**
 * portal/_attendance.php — "Attendance & pass", the CACENTRE gate inside the portal.
 *
 * The same record /gate-pass shows (lib/GateAttendance.php): the pass to hold
 * up at a desk, the member's NGV card, the last thirty days, points, and
 * telling the office about a day away — in the portal's own cards, so a
 * member does not leave the portal to see their own attendance. /gate-pass
 * stays as the full-screen pass for the desk, one tap away.
 *
 * Expects $u. Reads, and posts the "away" form to /gate-pass, which sends
 * the member back here.
 */
declare(strict_types=1);

$gpMid = (int) $u['id'];
$gpWhy = !GatePass::ready() ? 'Gate passes are not switched on yet.' : (GatePass::whyNot($u) ?? '');
$gpPass = $gpWhy === '' ? GatePass::mint($u) : null;
$gpCard = GateAttendance::cardFor($gpMid);
$gpSecure = class_exists('MemberCards') ? MemberCards::secure($gpMid) : null;
$gpSum = GateAttendance::summary($gpMid, 30);
$gpDays = array_slice(GateAttendance::history($gpMid, 60), 0, 14);
$gpAsks = GateAttendance::excusesFor($gpMid);
$gpExpected = GateAttendance::expected($gpMid);
$gpPts = GateAttendance::points($gpMid);
$gpRules = GateAttendance::pointRules();
$gpProb = $gpExpected ? GateAttendance::probationWhy($gpMid) : null;
$gpTz = new DateTimeZone(defined('AV_TZ') ? AV_TZ : 'Africa/Lagos');
$gpHm = static function (string $iso) use ($gpTz): string {
    if ($iso === '' || ($t = strtotime($iso)) === false) return '';
    return (new DateTime('@' . $t))->setTimezone($gpTz)->format('H:i');
};
$gpLabel = ['present' => 'On time', 'late' => 'Late', 'absent' => 'Absent', 'excused' => 'Excused'];
$gpTone = ['present' => 'green', 'late' => 'gold', 'absent' => 'red', 'excused' => 'indigo'];
$gpFlash = (string) ($_COOKIE['av_gp_flash'] ?? '');
if ($gpFlash !== '') setcookie('av_gp_flash', '', ['expires' => time() - 3600, 'path' => '/portal/']);
?>
          <div class="view-head">
            <div><h1>Attendance &amp; pass</h1><p class="view-sub">Your pass for the CACENTRE gate, and what the gate has recorded about you.</p></div>
<?php if ($gpPass): ?>            <a class="pbtn pbtn-gold" href="/gate-pass">Show my pass full screen</a>
<?php endif; ?>          </div>
<?php if ($gpFlash !== ''): ?>          <div class="ngv-banner" role="status"><?= e(mb_substr($gpFlash, 0, 300)) ?></div>
<?php endif; ?>
          <div class="pkpis" aria-label="The last 30 days">
            <div class="pkpi"><div class="pkpi-top"><span class="pkpi-label">Attendance</span></div><div class="pkpi-value"><?= $gpSum['counted'] ? (int) $gpSum['rate'] . '%' : '—' ?></div><div class="pkpi-sub">last 30 days</div></div>
            <div class="pkpi"><div class="pkpi-top"><span class="pkpi-label">On time</span></div><div class="pkpi-value"><?= $gpSum['counted'] ? (int) $gpSum['punctuality'] . '%' : '—' ?></div><div class="pkpi-sub"><?= (int) $gpSum['late'] ?> late</div></div>
            <div class="pkpi"><div class="pkpi-top"><span class="pkpi-label">Grade</span></div><div class="pkpi-value"><?= $gpSum['counted'] ? e((string) $gpSum['grade']) : '—' ?></div><div class="pkpi-sub"><?= (int) $gpSum['streak'] ?>-day on-time run</div></div>
            <div class="pkpi"><div class="pkpi-top"><span class="pkpi-label">Points</span></div><div class="pkpi-value"><?= number_format((int) $gpPts['month']) ?></div><div class="pkpi-sub"><?= number_format((int) $gpPts['total']) ?> in all</div></div>
          </div>
          <div class="ngv-grid">
            <section class="pcard" id="gp-pass">
              <div class="pcard-head"><h2>Your gate pass</h2><?= $gpPass ? '<span class="pchip pchip--green">Ready</span>' : '<span class="pchip pchip--red">No pass</span>' ?></div>
              <div class="pcard-body">
<?php if ($gpPass): ?>
                <div class="gp-qr" role="img" aria-label="Your gate pass QR code"><?= GatePass::svg($gpPass['url']) ?></div>
                <p class="pcard-note">Hold it up to the desk camera. Good until <b><?= e((new DateTime('@' . $gpPass['exp']))->setTimezone($gpTz)->format('D j M, H:i')) ?></b> — a new one is made each time you open it.</p>
<?php else: ?>
                <p class="pcard-note"><?= e($gpWhy) ?></p>
<?php endif; ?>
              </div>
            </section>
            <section class="pcard" id="gp-cards">
              <div class="pcard-head"><h2>Your cards</h2></div>
              <div class="pcard-body ngv-rows">
<?php if ($gpCard): ?>                <div class="ngv-row"><div><span class="k">NGV ID card</span><span class="d">The number printed on your card — scanned, or typed at the desk.</span></div><span class="amt gp-mono"><?= e($gpCard) ?></span></div>
<?php endif; ?>
<?php if ($gpSecure): ?>                <div class="ngv-row"><div><span class="k">Gate card</span><span class="d">The card the office prints for you. Lost it? Tell the office and it stops at once.</span></div><span class="amt gp-mono"><?= e($gpSecure) ?></span></div>
<?php endif; ?>
<?php if (!$gpCard && !$gpSecure): ?>                <p class="pcard-note">No card yet. The pass above works on its own, and the desk can find you by name.</p>
<?php endif; ?>
              </div>
            </section>
            <section class="pcard wide" id="gp-days">
              <div class="pcard-head"><h2>Recent days</h2><span class="pcard-sub"><?= (int) $gpSum['present'] ?> on time · <?= (int) $gpSum['late'] ?> late · <?= (int) $gpSum['absent'] ?> absent · <?= (int) $gpSum['excused'] ?> excused</span></div>
              <div class="pcard-body ngv-rows">
<?php if ($gpProb !== null): ?>                <div class="ngv-box"><?= e($gpProb) ?>.<?= GateAttendance::lateFineProbation() > 0 ? ' A late arrival is fined ₦' . number_format(GateAttendance::lateFineProbation()) . '.' : '' ?></div>
<?php endif; ?>
<?php foreach ($gpDays as $d): $s = (string) $d['status']; ?>
                <div class="ngv-row"><div><span class="k"><?= e(date('D j M', (int) strtotime((string) $d['day'] . 'T12:00:00'))) ?></span>
                  <span class="d"><?= $d['in_at'] !== '' ? 'In ' . e($gpHm((string) $d['in_at'])) . ($d['out_at'] !== '' ? ' · out ' . e($gpHm((string) $d['out_at'])) : '') : '&nbsp;' ?></span></div>
                  <span class="amt"><span class="pchip pchip--<?= e($gpTone[$s] ?? 'indigo') ?>"><?= e($gpLabel[$s] ?? $s) ?><?= $s === 'late' ? ' · ' . (int) $d['late_minutes'] . ' min' : '' ?></span></span></div>
<?php endforeach; ?>
<?php if (!$gpDays): ?>                <p class="pcard-note">Nothing recorded yet. Your first time through the gate shows here.</p>
<?php endif; ?>
              </div>
            </section>
<?php if ($gpPts['recent'] || array_sum($gpRules) > 0): ?>
            <section class="pcard" id="gp-points">
              <div class="pcard-head"><h2>Points</h2></div>
              <div class="pcard-body ngv-rows">
<?php foreach ($gpPts['recent'] as $a): ?>                <div class="ngv-row"><div><span class="k"><?= e((string) $a['note']) ?></span><span class="d"><?= e(date('j M', (int) strtotime((string) $a['day'] . 'T12:00:00'))) ?></span></div><span class="amt">+<?= (int) $a['points'] ?></span></div>
<?php endforeach; ?>
<?php if (!$gpPts['recent']): ?>                <p class="pcard-note">Arrive on time to earn them — more for a run of days, and for a perfect week.</p>
<?php endif; ?>
              </div>
            </section>
<?php endif; ?>
<?php if ($gpExpected): ?>
            <section class="pcard" id="gp-away">
              <div class="pcard-head"><h2>Away on a programme day?</h2></div>
              <div class="pcard-body">
                <p class="pcard-note">Tell the NGV office before, or within two weeks after. An excused day counts against nothing.</p>
                <form method="post" action="/gate-pass" class="gp-form">
                  <input type="hidden" name="csrf" value="<?= e(av_csrf_token()) ?>">
                  <input type="hidden" name="return" value="portal">
                  <label class="ngv-sub-h" for="gpDay">The day</label>
                  <input class="ngv-input" type="date" id="gpDay" name="day" required value="<?= e(function_exists('av_today_tz') ? av_today_tz() : date('Y-m-d')) ?>">
                  <label class="ngv-sub-h" for="gpWhy">Why</label>
                  <textarea class="ngv-input" id="gpWhy" name="reason" rows="2" maxlength="300" required placeholder="Exam at school, hospital appointment…"></textarea>
                  <button class="pbtn pbtn-gold" type="submit">Send to the NGV office</button>
                </form>
<?php foreach ($gpAsks as $a): $st = (string) $a['status']; ?>
                <div class="ngv-row"><div><span class="k"><?= e(date('D j M', (int) strtotime((string) $a['day'] . 'T12:00:00'))) ?></span><?= (string) $a['outcome'] !== '' ? '<span class="d">' . e((string) $a['outcome']) . '</span>' : '' ?></div>
                  <span class="amt"><span class="pchip pchip--<?= $st === 'approved' ? 'green' : ($st === 'declined' ? 'red' : 'gold') ?>"><?= e(['pending' => 'Waiting', 'approved' => 'Excused', 'declined' => 'Not excused'][$st] ?? $st) ?></span></span></div>
<?php endforeach; ?>
              </div>
            </section>
<?php endif; ?>
          </div>
