<?php /* portal/views/ngv.php — moved verbatim from portal/index.php v1 (row 14 shell
   rebuild). Markup and ids unchanged; restyled through portal.css + avp.css tokens.
   Rendered inside the shell, which defines the variables it reads. */ ?>
<?php
        /* The programme dashboard's cards, drawn into portal views — once each,
           the script once, with the portal's own headings in place of the
           standalone page's greeting and tiles (those are on Today). */
        $ngvRender = static function (array $v, array $parts, bool $script): void {
            (static function (array $__v): void { extract($__v); require AV_ROOT . '/academy/ngv/_dashboard-body.php'; })(
                $v + ['ngvParts' => $parts, 'ngvStandalone' => false, 'ngvBanner' => $parts[0] === 'track', 'ngvScript' => $script, 'ngvAccountHref' => '#ngv-account']);
        };
?>
        <section class="pview" id="view-ngv" data-view="ngv" hidden>
<?php
        /* Programme. Built from design "Afrovanguard Portal v4", then cut to what
           is about THIS member (owner, 2026-10-10): the programme's brochure
           (pipeline, the NGV 8, summer, summit, the list of tracks) is on the
           public NGV page, not here. Classes only — no inline
           styles; bars are <progress> so their value needs no style attribute.
           Data: academy/ngv/_dashboard-data.php ($ngvVars), lib/NgvJourney.php,
           the NGV content document, GateAttendance. A block with no data is not
           drawn. The book slots keep data-slot inside #books, so the existing
           claim sheet (academy/ngv/reading.js) opens from them. */
        $nv  = $ngvVars; $nc = Ngv::get();
        $nj  = NgvJourney::view((int) $u['id'], $nc);
        $nrp = NgvReading::progress((int) $u['id']);
        $nshelf = NgvReading::shelf((int) $u['id']);
        $natt = class_exists('GateAttendance') ? GateAttendance::summary((int) $u['id'], 30) : null;
        $nRate = $natt && $natt['rate'] !== null ? (int) round((float) $natt['rate']) : null;
        $nPunct = $natt && $natt['punctuality'] !== null ? (int) round((float) $natt['punctuality']) : null;
        $nOwed = (int) ($nv['account']['payable'] ?? 0);
        $nTrack = (string) $nv['myTrack'];
        $nProj = (array) ($nc['j_project'] ?? []); $nPast = (array) ($nc['j_project_past'] ?? []);
        $nChap = (array) ($nc['j_chapter'] ?? []); $nRoster = array_values(array_filter((array) ($nc['j_roster'] ?? []), static fn($r) => is_array($r) && trim((string) ($r['name'] ?? '')) !== ''));
        $nSched = (array) ($nv['sched'] ?? []);
        $nCerts = (array) ($nv['myCerts'] ?? []);
        $ini = static fn(string $n): string => mb_strtoupper(implode('', array_map(static fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/u', trim($n)) ?: [], 0, 2))));
?>
          <div class="view-head">
            <div><h1>Programme</h1><p class="view-sub">Where you stand in NextGen Vanguard, and what to do next.</p></div>
          </div>
          <div class="avng">
            <div class="avng-strip">
              <div class="avng-kpi"><span class="avng-kpi-k">Track</span><span class="avng-kpi-v<?= $nTrack === '' ? ' is-gold' : '' ?>"><?= $nTrack !== '' ? e($nTrack) : 'Not chosen' ?></span><span class="avng-kpi-s"><?= $nTrack !== '' ? e((string) $nv['myPlan']) : 'Set by the programme team' ?></span></div>
              <a class="avng-kpi" href="#books"><span class="avng-kpi-k">Reading</span><span class="avng-kpi-v av-num"><?= (int) $nrp['verified'] ?> / <?= (int) $nrp['total'] ?></span><span class="avng-kpi-s">books this year</span></a>
              <a class="avng-kpi" href="#ngv-account" data-goto="ngv-account"><span class="avng-kpi-k">Balance</span><span class="avng-kpi-v av-num<?= $nOwed > 0 ? ' is-gold' : ' is-green' ?>"><?= $nOwed > 0 ? '₦' . number_format($nOwed) : 'All clear' ?></span><span class="avng-kpi-s"><?= $nOwed > 0 ? 'outstanding' : 'nothing owed' ?></span></a>
              <div class="avng-kpi"><span class="avng-kpi-k">Attendance</span><span class="avng-kpi-v av-num"><?= $nRate !== null ? $nRate . '%' : '—' ?></span><span class="avng-kpi-s"><?= $nPunct !== null ? $nPunct . '% on time' : 'no sessions recorded yet' ?></span></div>
            </div>

            <div class="avng-grid">
              <div class="avng-col">
<?php if ($nj['levels']): ?>
                <section class="avng-card" aria-labelledby="avng-lv-h">
                  <div class="avng-card-h"><h2 id="avng-lv-h">Your level</h2><span>No evidence, no promotion</span></div>
                  <ol class="avng-levels">
<?php foreach ($nj['levels'] as $k => $lv): ?>
                    <li class="<?= $k < $nj['level'] ? 'is-done' : ($k === $nj['level'] ? 'is-now' : '') ?>"<?= $k === $nj['level'] ? ' aria-current="step"' : '' ?>><span><?= e($lv) ?></span></li>
<?php endforeach; ?>
                  </ol>
<?php if ($nj['nextIsVanguard']): ?>
                  <p class="avng-need-h">To become a <b><?= e($nj['levels'][2]) ?></b>, show:</p>
                  <ul class="avng-needs">
<?php foreach ($nj['needs'] as $nd): ?>
                    <li class="<?= $nd['ok'] ? 'is-ok' : '' ?>"><span class="avng-tick" aria-hidden="true"><?= $nd['ok'] ? '✓' : '' ?></span><span class="avng-need-t"><?= e($nd['t']) ?><span class="av-sr"><?= $nd['ok'] ? ' — done' : ' — not yet' ?></span></span><span class="avng-need-v av-num"><?= e($nd['v']) ?></span></li>
<?php endforeach; ?>
                  </ul>
<?php endif; ?>
                </section>
<?php endif; ?>

<?php
/* The reading card is ONE partial, drawn here and on the Reading page a
   member or a volunteer opens. Two copies of the shelf would drift within a
   season, and what drifts is what somebody is told their reading is worth. */
$rdKind = 'vanguard'; $rdProgress = $nrp; $rdShelf = $nshelf;
require AV_ROOT . '/portal/_reading-card.php';
?>

<?php if ($nj['scored']): ?>
                <section class="avng-card" aria-labelledby="avng-sc-h">
                  <div class="avng-card-h"><h2 id="avng-sc-h">The 8-point Vanguard score</h2><span class="av-num"><?= (int) $nj['total'] ?> / 800</span></div>
                  <ol class="avng-score">
<?php foreach ($nj['score'] as $k => $sc): ?>
                    <li><span class="avng-score-t"><span><?= $k + 1 ?> · <?= e($sc['label']) ?></span><b class="av-num"><?= (int) $sc['value'] ?></b></span><progress class="avng-bar" value="<?= (int) $sc['value'] ?>" max="100" aria-label="<?= e($sc['label']) ?>: <?= (int) $sc['value'] ?> of 100"></progress><span class="avng-score-e"><?= e($sc['note']) ?></span></li>
<?php endforeach; ?>
                  </ol>
                  <p class="avng-fine">Each area is scored out of 100 by the NGV office against evidence; the line under it says what it rests on.</p>
                </section>
<?php endif; ?>


<?php if (trim((string) ($nProj['title'] ?? '')) !== ''): $pStep = max(0, min(6, (int) ($nProj['step'] ?? 0))); ?>
                <section class="avng-card" aria-labelledby="avng-pj-h">
                  <div class="avng-card-h"><h2 id="avng-pj-h">Community project<?= trim((string) ($nProj['quarter'] ?? '')) !== '' ? ' · ' . e((string) $nProj['quarter']) : '' ?></h2><?php if (trim((string) ($nProj['theme'] ?? '')) !== ''): ?><span class="avng-chip" data-tone="gold"><?= e((string) $nProj['theme']) ?></span><?php endif; ?></div>
                  <p class="avng-pj-t"><?= e((string) $nProj['title']) ?></p>
                  <p class="avng-pj-d"><?= e((string) ($nProj['desc'] ?? '')) ?></p>
                  <ol class="avng-levels avng-levels--7">
<?php foreach (NgvJourney::PROJECT_STEPS as $k => $ps): ?>
                    <li class="<?= $k < $pStep ? 'is-done' : ($k === $pStep ? 'is-now' : '') ?>"<?= $k === $pStep ? ' aria-current="step"' : '' ?>><span><?= e($ps) ?></span></li>
<?php endforeach; ?>
                  </ol>
                  <dl class="avng-meta">
<?php foreach (['team' => 'Team', 'budget' => 'Budget', 'measure' => 'Measure'] as $mk => $ml): if (trim((string) ($nProj[$mk] ?? '')) === '') continue; ?>
                    <div><dt><?= $ml ?></dt><dd><?= e((string) $nProj[$mk]) ?></dd></div>
<?php endforeach; ?>
                  </dl>
<?php $nPast = array_values(array_filter($nPast, static fn($r) => is_array($r) && trim((string) ($r['title'] ?? '')) !== '')); if ($nPast): ?>
                  <p class="avng-sub-h">Chapter projects this year · one measurable problem solved every quarter</p>
                  <ul class="avng-past">
<?php foreach ($nPast as $pp): $done = stripos((string) ($pp['status'] ?? ''), 'report') !== false; ?>
                    <li><span class="avng-past-q"><?= e((string) ($pp['quarter'] ?? '')) ?></span><span class="avng-shelf-t"><span><?= e((string) $pp['title']) ?></span><span><?= e((string) ($pp['kind'] ?? '')) ?></span></span><?php if (trim((string) ($pp['status'] ?? '')) !== ''): ?><span class="avng-chip" data-tone="<?= $done ? 'green' : 'gold' ?>"><?= e((string) $pp['status']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
                  </ul>
<?php endif; ?>
                </section>
<?php endif; ?>

<?php if (array_filter($nj['schools'], static fn($x) => $x['done'] > 0)): ?>
                <section class="avng-card" aria-labelledby="avng-sch-h">
                  <div class="avng-card-h"><h2 id="avng-sch-h">Afrovanguard Academy · six schools</h2><span>You graduate on evidence, not attendance</span></div>
                  <ul class="avng-rows">
<?php foreach ($nj['schools'] as $sch): ?>
                    <li><span class="avng-shelf-t"><span><?= e($sch['name']) ?></span><span><?= e($sch['desc']) ?></span></span><progress class="avng-bar avng-bar--ink" value="<?= (int) $sch['done'] ?>" max="<?= (int) $sch['of'] ?>" aria-label="<?= e($sch['name']) ?>: <?= (int) $sch['done'] ?> of <?= (int) $sch['of'] ?> modules"></progress><span class="avng-rows-v av-num"><?= (int) $sch['done'] ?> / <?= (int) $sch['of'] ?></span></li>
<?php endforeach; ?>
                  </ul>
                </section>
<?php endif; ?>

              </div>

              <div class="avng-col avng-col--side">
<?php if ($nj['enginesSet']): ?>
                <section class="avng-card" aria-labelledby="avng-en-h">
                  <div class="avng-card-h"><h2 id="avng-en-h">The five engines</h2><span>This term</span></div>
                  <ul class="avng-rows avng-rows--stack">
<?php foreach ($nj['engines'] as $en): ?>
                    <li><span class="avng-shelf-t avng-shelf-t--row"><span><?= e($en['label']) ?></span><span><?= e($en['note']) ?></span></span><progress class="avng-bar" value="<?= (int) $en['value'] ?>" max="100" aria-label="<?= e($en['label']) ?>: <?= (int) $en['value'] ?>%"></progress></li>
<?php endforeach; ?>
                  </ul>
                </section>
<?php endif; ?>

<?php $schedRows = array_filter(['Attendance' => (string) ($nSched['days'] ?? ''), 'Daily schedule' => (string) ($nSched['time'] ?? ''), 'Dress code' => (string) ($nSched['uniform'] ?? '')], 'strlen'); if ($schedRows): ?>
                <section class="avng-card" aria-labelledby="avng-sd-h">
                  <div class="avng-card-h"><h2 id="avng-sd-h">Schedule</h2></div>
                  <dl class="avng-dl">
<?php foreach ($schedRows as $k => $v): ?>
                    <div><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd></div>
<?php endforeach; ?>
                  </dl>
                </section>
<?php endif; ?>

<?php if ($nRoster): ?>
                <section class="avng-card" aria-labelledby="avng-ch-h">
                  <div class="avng-card-h"><h2 id="avng-ch-h">Your chapter</h2><span><?= e(trim((string) ($nChap['name'] ?? '') . (trim((string) ($nChap['place'] ?? '')) !== '' ? ' · ' . $nChap['place'] : ''))) ?></span></div>
                  <ul class="avng-people">
<?php foreach ($nRoster as $rm): ?>
                    <li><span class="avng-av" aria-hidden="true"><?= e($ini((string) $rm['name'])) ?></span><span class="avng-shelf-t"><span><?= e((string) $rm['name']) ?></span><span><?= e((string) ($rm['role'] ?? '')) ?></span></span></li>
<?php endforeach; ?>
                  </ul>
                  <p class="avng-fine">Roles go to members with the evidence for them.</p>
                </section>
<?php endif; ?>

<?php $nAwards = class_exists('Conduct') ? Conduct::awardsFor((int) $u['id']) : []; if ($nAwards): ?>
                <section class="avng-card" aria-labelledby="avng-aw-h">
                  <div class="avng-card-h"><h2 id="avng-aw-h">Recognition</h2><span class="av-num"><?= array_sum(array_map(static fn($a) => (int) $a['points'], $nAwards)) ?> points</span></div>
                  <ul class="avng-rows">
<?php foreach ($nAwards as $aw): ?>
                    <li><span class="avng-shelf-t"><span><?= e($aw['title'] !== '' ? $aw['title'] : (Conduct::AWARD_REASONS[$aw['reason']] ?? 'Award')) ?></span><span><?= e(date('j M Y', (int) strtotime($aw['occurred_on']))) ?> · from your mentor</span></span><span class="avng-chip" data-tone="green">+<?= (int) $aw['points'] ?></span></li>
<?php endforeach; ?>
                  </ul>
                </section>
<?php endif; ?>

<?php if ($nCerts): ?>
                <section class="avng-card" aria-labelledby="avng-ce-h">
                  <div class="avng-card-h"><h2 id="avng-ce-h">Certifications</h2><span class="av-num"><?= count($nCerts) ?></span></div>
                  <ul class="avng-rows">
<?php foreach ($nCerts as $ct): ?>
                    <li><span class="avng-shelf-t"><span><?= e((string) ($ct['name'] ?? $ct['title'] ?? 'Certificate')) ?></span></span><span class="avng-chip" data-tone="green">Earned</span></li>
<?php endforeach; ?>
                  </ul>
                </section>
<?php endif; ?>
              </div>
            </div>
          </div>
        </section>
        <section class="pview" id="view-ngv-account" data-view="ngv-account" hidden>
          <div class="view-head">
            <div><h1>Fees &amp; account</h1><p class="view-sub">What the programme has charged and what you have paid, your receipts, and anything reported as damaged. Nothing here can be changed from your side — ask, and a person answers.</p></div>
          </div>
<?php $ngvRender($ngvVars, ['account', 'damage'], true); ?>
        </section>
        <section class="pview" id="view-ngv-fines" data-view="ngv-fines" hidden>
          <div class="view-head">
            <div><h1>Fines</h1><p class="view-sub">Every fine the NGV office has recorded for you — what for, the day, and where it stands. A fine is paid from Fees &amp; account, like any other fee.</p></div>
          </div>
<?php $ngvRender($ngvVars, ['fines'], false); ?>
        </section>
