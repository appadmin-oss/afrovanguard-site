<?php
/**
 * portal/views/today.php — Today (design: Afrovanguard Portal v2, view "overview").
 *
 * Running order, and the reason for it: the page opens with what needs this
 * member now, because that is the question Today answers. Greeting, Needs
 * your attention, What are you working on (only when nothing is due), At a
 * glance, Ask finance for money, then the columns — Your calendar, Your
 * journey, Who's online, Team activity, Mentorship. tests/portalviews.test.php
 * pins that order.
 *
 * No fact is printed twice. The online count lives in the top bar and the
 * Who's online card, so there is no KPI for it; mentorship hours are the
 * KPI and the Mentorship card's own stat, not a third copy in its heading;
 * today's date appears once, on the lead card.
 *
 * Live parts keep the ids the panel scripts write to: #kpiTasks,
 * #onlineCount, #onlineList and #activityList (portal/tasks.js), #calWeek /
 * #calAgenda / #calSummary (portal/avp.js) and #openMeetModal
 * (portal/meet-modal.js). tasks.js null-guards each one, which is why
 * dropping the online KPI's element is safe.
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
/* The Quest's bills: one waiting for the member's answer, or a decision
   whose appeal window is closing. The decision is the member's; Today says so. */
if (!empty($billsUnanswered)) {
    $b0 = $billsUnanswered[0];
    $bLeft = Conduct::noticeLeft($b0);
    $attn[] = ['ico' => '!', 'tone' => 'red',
        'title' => count($billsUnanswered) === 1
            ? ($b0['kind'] === 'fine' ? 'A fine of ₦' . number_format((int) $b0['amount']) : 'A deduction of ' . (int) $b0['points'] . ' points') . ' has been proposed'
            : count($billsUnanswered) . ' fines or deductions are waiting for your answer',
        'sub' => 'Accept it or give your side' . ($bLeft !== '' ? ' — the committee may decide after ' . $bLeft : ''),
        'cta' => 'Answer', 'goto' => 'bills'];
}
if (!empty($billsClosing)) {
    $attn[] = ['ico' => '⚖', 'tone' => 'gold',
        'title' => count($billsClosing) === 1 ? 'The time to appeal ' . e(Conduct::caseLabel($billsClosing[0])) . ' ends ' . Conduct::appealOpenUntil($billsClosing[0])
            : count($billsClosing) . ' decisions can be appealed for three more days at most',
        'sub' => 'Only if you think it is wrong. Somebody new hears it.', 'cta' => 'See', 'goto' => 'bills'];
}
if ($isNgv && $ngvOwed > 0) {
    $attn[] = ['ico' => '₦', 'tone' => 'gold',
        'title' => '₦' . number_format($ngvOwed) . ' outstanding on your NGV account',
        'sub' => 'See what it is for, pay, or ask about it', 'cta' => 'Open', 'goto' => 'ngv-account'];
}
$mrq = CfisRequests::summaryFor((int) $u['id']);
if ($mrq['unsent'] > 0) {
    /* We know this one for certain without asking finance: we are the
       ones who could not send it. Everything else about a request is
       finance's to say, and is fetched when the card is drawn. */
    $attn[] = ['ico' => '!', 'tone' => 'red',
        'title' => $mrq['unsent'] . ' money request' . ($mrq['unsent'] === 1 ? '' : 's') . ' did not reach finance',
        'sub' => 'Still saved here — send ' . ($mrq['unsent'] === 1 ? 'it' : 'them') . ' again',
        'cta' => 'Send', 'goto' => 'requests'];
}
if ($gateWhy !== '' && GatePass::ready()) {
    $attn[] = ['ico' => '×', 'tone' => 'red', 'title' => 'The CACENTRE gate cannot let you in',
        'sub' => e($gateWhy), 'cta' => 'Why', 'goto' => 'attendance'];
}
if ($isNgv && (string) $ngvVars['myTrack'] === '') {
    $attn[] = ['ico' => '→', 'tone' => 'indigo', 'title' => 'Choose your NGV track and plan',
        'sub' => 'It sets your path and your training fee', 'cta' => 'Choose', 'goto' => 'ngv'];
}
if (!empty($isVol) && !empty($volWaiting)) {
    $attn[] = ['ico' => '◆', 'tone' => 'indigo',
        'title' => $volWaiting . ' thing' . ($volWaiting === 1 ? '' : 's') . ' waiting in your Volunteer hub',
        'sub' => 'Meetings to answer, minutes to write or approve, actions overdue', 'cta' => 'Open', 'goto' => 'volunteer'];
}
if ($postsToday) {
    $attn[] = ['ico' => '#', 'tone' => 'green',
        'title' => $postsToday . ' new community post' . ($postsToday === 1 ? '' : 's') . ' today',
        'sub' => 'Catch up with members', 'cta' => 'Open', 'goto' => 'community'];
}

/* The KPI row. Each number appears here or in a card below, never both:
   members online lives in the top bar and the Who's online card, and
   mentorship hours are spelled out in the Mentorship card's own stats. */
$kpis = [];
if ($isOrg) {
    $kpis[] = ['label' => 'Tasks open', 'value' => (string) ($openTasks + $cacOpen), 'id' => 'kpiTasks',
       'sub' => $cacOpen ? 'here and in the console' : 'across your list', 'chip' => 'Active', 'tone' => 'gold'];
}
/* The level is the Quest's (Levels, on the Pyramid Manual's floors): one
   word for it everywhere, and the way to it is the Quest page. */
$jNeedPts = $journey['next_points'] ?? null;
$jHavePts = max(0, (int) ($journey['quest_points'] ?? 0));
$kpis[] = ['label' => 'Your level', 'value' => (string) ($journey['label'] ?? 'Member'), 'text' => true, 'goto' => 'quest',
    'sub' => $journey['next'] === null ? 'The top of the pyramid'
        : ($jNeedPts === null ? $jNextLabel . ' up next'
            : ($jHavePts >= (int) $jNeedPts ? 'Points reached for ' . $jNextLabel . '; mentees next'
                : number_format((int) $jNeedPts - $jHavePts) . ' Quest points to ' . $jNextLabel)),
    'chip' => 'Level ' . $stageCode, 'tone' => 'indigo'];
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


<?php if ($attn): ?>
        <section class="avp-card avp-attn" aria-labelledby="avpAttnH">
          <div class="avp-card-head"><h2 id="avpAttnH">Needs your attention</h2><span class="avp-chip avp-chip--gold"><?= e(date('D, M j')) ?></span></div>
          <ul class="avp-attn-list">
<?php foreach ($attn as $a): ?>
            <li class="avp-attn-row">
              <span class="avp-attn-ico avp-tone--<?= e($a['tone']) ?>" aria-hidden="true"><?= e($a['ico']) ?></span>
              <span class="avp-attn-t"><span class="avp-attn-title"><?= $a['title'] ?></span><span class="avp-attn-sub"><?= $a['sub'] ?></span></span>
              <a class="avp-pill-btn" href="#<?= e($a['goto']) ?>" data-goto="<?= e($a['goto']) ?>"><?= e($a['cta']) ?> →</a>
            </li>
<?php endforeach; ?>
          </ul>
        </section>
<?php else: ?>
        <p class="avp-allclear"><span aria-hidden="true">✓</span> Nothing needs you right now — <?= e(date('D, M j')) ?>.</p>
<?php endif; ?>


<?php
/* ── "WHAT ARE YOU WORKING ON?" ──────────────────────────────────────────
   The centre asked for a to-do prompt on the member's screen. The answer
   becomes an ordinary task — CACENTRE already reads this list over the
   bridge the two sites share (CrmAvTasks → integrations/cacentre-tasks.php),
   so there is still one list rather than two.

   IT ASKS ONLY WHEN THERE IS NOTHING TO SHOW: no open task due today or
   overdue, on either side of the bridge. */
$todayKey = date('Y-m-d');
$hasPlan  = false;
foreach (array_merge($myTasks, $cacTasks) as $t) {
    if (!empty($t['done'])) continue;
    $due = trim((string) ($t['due'] ?? ''));
    if ($due === '' || $due <= $todayKey) { $hasPlan = true; break; }
}
if ($isOrg && !$hasPlan):
?>
        <section class="pcard today-ask" id="askTask" data-csrf="<?= e($collabCsrf) ?>" data-today="<?= e($todayKey) ?>" aria-labelledby="askTaskH">
          <div class="pcard-head"><h2 id="askTaskH">What are you working on?</h2></div>
          <div class="pcard-body">
            <p class="pcard-note">Nothing on your list for today. Write down one thing — it shows up here and in the centre's console, and you can tick it off from either.</p>
            <form class="ask-row" id="askTaskForm" autocomplete="off">
              <label class="sr-only" for="askTaskTitle">Your task</label>
              <input class="pinp" id="askTaskTitle" maxlength="200" placeholder="e.g. Call the three families from Saturday" required>
              <div class="ask-btns">
                <button class="pbtn pbtn-gold" type="submit" data-when="today">For today</button>
                <button class="pbtn pbtn-soft" type="button" id="askTaskNext" data-when="next">Next, no date</button>
              </div>
            </form>
            <p class="pc-empty" id="askTaskMsg" role="status" aria-live="polite" hidden></p>
          </div>
        </section>
<?php endif; ?>

        <div class="avp-kpis" aria-label="At a glance">
<?php foreach ($kpis as $k): ?>
<?php $kTag = isset($k['goto']) ? 'a' : 'div'; ?>
          <<?= $kTag ?> class="avp-kpi<?= isset($k['goto']) ? ' avp-kpi--link' : '' ?>"<?= isset($k['goto']) ? ' href="#' . e($k['goto']) . '" data-goto="' . e($k['goto']) . '"' : '' ?>>
            <div class="avp-kpi-top"><span class="avp-kpi-l"><?= e($k['label']) ?></span><span class="avp-chip avp-chip--sm avp-chip--<?= e($k['tone']) ?>"><?= e($k['chip']) ?></span></div>
            <div class="avp-kpi-v<?= empty($k['text']) ? ' av-num' : ' avp-kpi-v--text' ?>"<?= isset($k['id']) ? ' id="' . e($k['id']) . '"' : '' ?>><?= e($k['value']) ?></div>
            <div class="avp-kpi-s"><?= e($k['sub']) ?></div>
          </<?= $kTag ?>>
<?php endforeach; ?>
        </div>

        <!-- Asking finance for money is its own page: Finance requests
             (views/requests.php). A change to your record is in Membership. -->
        <p class="avp-today-ask"><a href="#requests" data-goto="requests">Ask finance for money →</a></p>


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
              <div class="avp-card-head"><h2 id="avpJourneyH">Your level</h2><a class="avp-head-link" href="#quest" data-goto="quest">The Quest →</a></div>
              <div class="avp-card-body avp-journey">
                <ol class="avp-levels">
<?php foreach ($jOrder as $i => $code): $st = $i === $jHere ? ' is-here' : ($i < $jHere ? ' is-done' : ''); ?>
                  <li class="avp-level<?= $st ?>"<?= $i === $jHere ? ' aria-current="step"' : '' ?>><span class="avp-level-b"><?= e($code) ?></span><?= e(Levels::labelOf($code)) ?></li>
<?php endforeach; ?>
                </ol>
<?php if ($jNeedPts !== null && $journey['next'] !== null): $jPtsPct = (int) $jNeedPts > 0 ? min(100, (int) round(100 * $jHavePts / (int) $jNeedPts)) : 100; ?>
                <div class="avp-progress">
                  <div class="avp-bar" role="progressbar" aria-label="Quest points toward <?= e($jNextLabel) ?>" aria-valuemin="0" aria-valuemax="<?= (int) $jNeedPts ?>" aria-valuenow="<?= min((int) $jNeedPts, $jHavePts) ?>"><span style="width:<?= $jPtsPct ?>%"></span></div>
                  <span class="avp-progress-n av-num"><?= number_format($jHavePts) ?> / <?= number_format((int) $jNeedPts) ?> Quest points</span>
                </div>
<?php endif; ?>
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
              <div class="avp-card-head"><h2 id="avpMentorH">Mentorship</h2></div>
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
