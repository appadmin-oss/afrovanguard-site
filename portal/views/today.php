<?php
/**
 * portal/views/today.php — Today (design: Afrovanguard Portal v2, view "overview").
 *
 * Welcome, Needs your attention, the KPI row, Your calendar, Your journey,
 * Who's online, Team activity and Mentorship — all from what the shell has
 * already read for this member. Live parts keep the ids the panel scripts
 * write to: #kpiTasks, #kpiOnline, #onlineCount, #onlineList and
 * #activityList (portal/tasks.js), #calWeek / #calAgenda / #calSummary
 * (portal/avp.js) and #openMeetModal (portal/meet-modal.js).
 */
declare(strict_types=1);

/* Needs your attention: what genuinely needs this member now. Titles and
   subs are escaped here, once, because the row prints them as markup. */
$attn = [];
if ($nextSession) {
    $ns = $nextSession;
    $when = !empty($ns['live']) ? 'Live now' : date('D g:ia', strtotime((string) $ns['when'] . ' UTC') ?: time());
    $attn[] = ['ico' => '▶', 'tone' => !empty($ns['live']) ? 'green' : 'indigo',
        'title' => (!empty($ns['live']) ? 'Meeting live — ' : 'Next meeting — ') . e($ns['title']),
        'sub' => e($ns['role']) . ' ' . e($ns['with']) . ' · ' . e($when), 'cta' => 'Go', 'goto' => 'mentorship'];
}
if ($isOrg && ($tasksDue || $cacDue)) {
    $n = count($tasksDue) + $cacDue;
    $attn[] = ['ico' => '✓', 'tone' => $tasksOverdue ? 'red' : 'gold',
        'title' => $n . ' task' . ($n === 1 ? '' : 's') . ' due' . ($tasksOverdue ? ' · ' . $tasksOverdue . ' overdue' : ''),
        /* Named when some of it is in the console, so "Open" does not land
           somebody on a list shorter than the row just counted. */
        'sub' => $cacDue ? 'Due today or earlier · ' . $cacDue . ' in the console' : 'Due today or earlier',
        'cta' => 'Open', 'goto' => 'tasks'];
}
if ($isNgv && $ngvOwed > 0) {
    $attn[] = ['ico' => '₦', 'tone' => 'gold',
        'title' => '₦' . number_format($ngvOwed) . ' outstanding on your NGV account',
        'sub' => 'See what it is for, pay, or ask about it', 'cta' => 'Open', 'goto' => 'ngv-account'];
}
if ($gateWhy !== '' && GatePass::ready()) {
    $attn[] = ['ico' => '×', 'tone' => 'red', 'title' => 'The CACENTRE gate cannot let you in',
        'sub' => e($gateWhy), 'cta' => 'Why', 'goto' => 'attendance'];
}
if ($isNgv && (string) $ngvVars['myTrack'] === '') {
    $attn[] = ['ico' => '→', 'tone' => 'indigo', 'title' => 'Choose your NGV track and plan',
        'sub' => 'It sets your path and your training fee', 'cta' => 'Choose', 'goto' => 'ngv'];
}
if ($postsToday) {
    $attn[] = ['ico' => '#', 'tone' => 'green',
        'title' => $postsToday . ' new community post' . ($postsToday === 1 ? '' : 's') . ' today',
        'sub' => 'Catch up with members', 'cta' => 'Open', 'goto' => 'community'];
}

/* The KPI row. */
$kpis = [];
if ($isOrg) {
    $kpis[] = ['label' => 'Tasks open', 'value' => (string) ($openTasks + $cacOpen), 'id' => 'kpiTasks',
       'sub' => $cacOpen ? 'here and in the console' : 'across your list', 'chip' => 'Active', 'tone' => 'gold'];
    $kpis[] = ['label' => 'Members online', 'value' => (string) $onlineNow, 'id' => 'kpiOnline', 'sub' => 'right now', 'chip' => 'Live', 'tone' => 'green'];
}
$kpis[] = ['label' => 'Your stage', 'value' => (string) ($journey['label'] ?? 'Member'), 'sub' => ($stageCode === 'O' ? 'Level A up next' : 'Keep building'), 'chip' => 'Level ' . $stageCode, 'tone' => 'indigo'];
$kpis[] = ['label' => 'Mentorship hours', 'value' => $mentorHoursLabel, 'sub' => ((int) $mentorStats['attended']) . ' session' . (((int) $mentorStats['attended']) === 1 ? '' : 's') . ' attended', 'chip' => 'Logged', 'tone' => 'gold'];
if ($dues) {
    $duesOk = $duesState === 'active' || !empty($dues['lifetime']);
    $kpis[] = ['label' => 'Total dues paid', 'value' => '₦' . number_format($duesTotal), 'sub' => $duesVal, 'chip' => $duesOk ? 'Current' : 'Due', 'tone' => $duesOk ? 'green' : 'red'];
} else {
    $kpis[] = ['label' => 'Certificates', 'value' => (string) $certs, 'sub' => $inProgress . ' in progress', 'chip' => 'Learning', 'tone' => 'indigo'];
}
?>
      <section class="pview avp-view avp-today" id="view-overview" data-view="overview" aria-labelledby="avpTodayH">
        <div class="avp-hello">
          <div class="avp-hello-t">
            <h1 class="avp-h1" id="avpTodayH">Welcome back, <?= e($first) ?>.</h1>
            <p class="avp-lede"><?= $isOrg ? 'Here’s what’s happening across your Afrovanguard workspace today.' : 'Pick up where you left off in your learning.' ?></p>
          </div>
          <div class="avp-hello-actions">
<?php if ($isOrg): ?>
            <a class="avp-btn" href="#tasks" data-goto="tasks">+ New task</a>
            <button type="button" class="avp-btn" id="openMeetModal" aria-haspopup="dialog">Schedule meeting</button>
            <a class="avp-btn avp-btn--ink" href="https://meet.google.com/new" target="_blank" rel="noopener noreferrer">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="6" width="14" height="12" rx="2"/><path d="m16 10 6-3v10l-6-3"/></svg>Start a Meet</a>
<?php else: ?>
            <a class="avp-btn avp-btn--ink" href="/academy/">Browse the Academy →</a>
<?php endif; ?>
          </div>
        </div>

        <section class="avp-card avp-attn" aria-labelledby="avpAttnH">
          <div class="avp-card-head"><h2 id="avpAttnH">Needs your attention</h2><span class="avp-chip avp-chip--gold"><?= e(date('D, M j')) ?></span></div>
<?php if ($attn): ?>
          <ul class="avp-attn-list">
<?php foreach ($attn as $a): ?>
            <li class="avp-attn-row">
              <span class="avp-attn-ico avp-tone--<?= e($a['tone']) ?>" aria-hidden="true"><?= e($a['ico']) ?></span>
              <span class="avp-attn-t"><span class="avp-attn-title"><?= $a['title'] ?></span><span class="avp-attn-sub"><?= $a['sub'] ?></span></span>
              <a class="avp-pill-btn" href="#<?= e($a['goto']) ?>" data-goto="<?= e($a['goto']) ?>"><?= e($a['cta']) ?> →</a>
            </li>
<?php endforeach; ?>
          </ul>
<?php else: ?>
          <p class="avp-empty">Nothing needs you right now.</p>
<?php endif; ?>
        </section>

        <div class="avp-kpis" aria-label="At a glance">
<?php foreach ($kpis as $k): ?>
          <div class="avp-kpi">
            <div class="avp-kpi-top"><span class="avp-kpi-l"><?= e($k['label']) ?></span><span class="avp-chip avp-chip--sm avp-chip--<?= e($k['tone']) ?>"><?= e($k['chip']) ?></span></div>
            <div class="avp-kpi-v av-num"<?= isset($k['id']) ? ' id="' . e($k['id']) . '"' : '' ?>><?= e($k['value']) ?></div>
            <div class="avp-kpi-s"><?= e($k['sub']) ?></div>
          </div>
<?php endforeach; ?>
        </div>

        <div class="avp-cols">
          <div class="avp-col avp-col--main">
<?php if ($isNgv): ?>
            <!-- NextGen Vanguard at a glance: the programme in the same Today as everything else -->
            <section class="avp-card today-ngv" aria-labelledby="avpNgvH">
              <div class="avp-card-head"><h2 id="avpNgvH">NextGen Vanguard</h2><a class="avp-head-link" href="#ngv" data-goto="ngv">Programme →</a></div>
              <div class="avp-ngv-glance ngv-glance">
                <a href="#ngv" data-goto="ngv"><span>Track</span><b><?= $ngvVars['myTrack'] !== '' ? e((string) $ngvVars['myTrack']) : 'Not chosen' ?></b></a>
                <a href="#ngv" data-goto="ngv"><span>Reading</span><b class="av-num"><?= (int) $ngvVars['booksRead'] ?> / <?= (int) $ngvVars['BOOKS_TOTAL'] ?></b></a>
                <a href="#ngv-account" data-goto="ngv-account"><span>Account</span><b class="av-num <?= $ngvOwed > 0 ? 'is-due' : 'is-ok' ?>"><?= $ngvOwed > 0 ? '₦' . number_format($ngvOwed) . ' due' : 'All clear' ?></b></a>
                <a href="#attendance" data-goto="attendance"><span>Attendance</span><b class="av-num"><?= $gateSum && $gateSum['counted'] ? (int) $gateSum['rate'] . '% · ' . (int) $gateSum['punctuality'] . '% on time' : 'Nothing yet' ?></b></a>
              </div>
            </section>
<?php endif; ?>
<?php if ($isOrg): ?>
            <section class="avp-card" id="todayCal" data-csrf="<?= e($collabCsrf) ?>" aria-labelledby="avpCalH">
              <div class="avp-card-head">
                <div class="avp-head-pair"><h2 id="avpCalH">Your calendar</h2><span class="avp-head-sub" id="calSummary">Next 14 days</span></div>
                <a class="avp-head-link" href="#tools" data-goto="tools">Open calendar →</a>
              </div>
              <div class="avp-week" id="calWeek" role="group" aria-label="Pick a day"></div>
              <div class="avp-agenda" id="calAgenda" aria-live="polite">
                <div class="avp-skel" aria-hidden="true"></div><div class="avp-skel" aria-hidden="true"></div>
                <p class="av-sr">Loading your calendar…</p>
              </div>
            </section>
<?php endif; ?>
            <section class="avp-card" aria-labelledby="avpJourneyH">
              <div class="avp-card-head"><h2 id="avpJourneyH">Your journey</h2><a class="avp-head-link" href="/how-it-works">How progression works →</a></div>
              <div class="avp-card-body avp-journey">
                <ol class="avp-levels">
<?php foreach ($jOrder as $i => $code): $st = $i === $jHere ? ' is-here' : ($i < $jHere ? ' is-done' : ''); ?>
                  <li class="avp-level<?= $st ?>"<?= $i === $jHere ? ' aria-current="step"' : '' ?>><span class="avp-level-b"><?= e($code) ?></span><?= e(Levels::labelOf($code)) ?></li>
<?php endforeach; ?>
                </ol>
<?php if ($journey['level'] === $jBase): ?>
                <div class="avp-progress">
                  <div class="avp-bar" role="progressbar" aria-label="Actively mentored" aria-valuemin="0" aria-valuemax="<?= (int) ($journey['mentees_needed'] ?? 2) ?>" aria-valuenow="<?= (int) ($journey['active_mentees'] ?? 0) ?>"><span style="width:<?= $jPct ?>%"></span></div>
                  <span class="avp-progress-n av-num"><?= (int) ($journey['active_mentees'] ?? 0) ?> / <?= (int) ($journey['mentees_needed'] ?? 2) ?> actively mentored</span>
                </div>
                <p class="avp-note">Toward <strong><?= e($jNextLabel) ?></strong> — introduce members, then mentor them for real. Only mentees you actually meet count toward advancement.</p>
                <div class="avp-invite">
                  <span class="avp-invite-url" title="Your invite link"><?= e($journey['invite_url']) ?></span>
                  <button type="button" class="avp-btn avp-btn--ink avp-btn--sq" id="copyInvite" data-url="<?= e($journey['invite_url']) ?>">Copy</button>
                </div>
<?php else: ?>
                <p class="avp-note">You’re at <strong><?= e($journey['label']) ?></strong>. <?= e($journey['blurb']) ?></p>
<?php endif; ?>
              </div>
            </section>
          </div>

          <div class="avp-col avp-col--side">
<?php if ($isOrg): ?>
            <section class="avp-card" aria-labelledby="avpOnlineH">
              <div class="avp-card-head"><h2 id="avpOnlineH">Who’s online</h2><span class="avp-live" id="onlinePill"><span class="avp-online-dot" aria-hidden="true"></span><span><span class="av-num" id="onlineCount"><?= (int) $onlineNow ?></span> now</span></span></div>
              <div class="avp-card-body avp-online-list online-list" id="onlineList"><p class="pc-empty">Just you so far.</p></div>
            </section>

            <section class="avp-card" aria-labelledby="avpActH">
              <div class="avp-card-head"><h2 id="avpActH">Team activity</h2><a class="avp-head-link" href="#tasks" data-goto="tasks">Tasks →</a></div>
              <ul class="avp-card-body avp-activity activity-list" id="activityList"><li class="pc-empty">Loading…</li></ul>
            </section>
<?php endif; ?>
            <section class="avp-card" aria-labelledby="avpMentorH">
              <div class="avp-card-head"><h2 id="avpMentorH">Mentorship</h2><span class="avp-chip avp-chip--gold av-num"><?= e($mentorHoursLabel) ?> logged</span></div>
              <div class="avp-card-body">
                <dl class="avp-stats">
                  <div><dt>Hours logged</dt><dd class="av-num"><?= e($mentorHoursLabel) ?></dd></div>
                  <div><dt>Attended</dt><dd class="av-num"><?= (int) $mentorStats['attended'] ?></dd></div>
                  <div><dt>Consistency</dt><dd class="av-num"><?= $mentorStats['rate'] !== null ? (int) $mentorStats['rate'] . '%' : '—' ?></dd></div>
                </dl>
                <a class="avp-btn avp-btn--soft avp-btn--block" href="#mentorship" data-goto="mentorship">View mentorship →</a>
              </div>
            </section>
          </div>
        </div>
      </section>
