<?php
/**
 * portal/views/today.php — Today, Home v4 (design: "Afrovanguard Portal
 * Redesign", the hi-fi boards). Its components are portal/home.css (hv-) and
 * portal/home.js; nothing here borrows the older avp- card styles.
 *
 * Running order, and the reason for it — you, then what needs you, then how
 * you are growing, then the record that proves it:
 *
 *   You           avatar in its level ring, the greeting (lib/Greeting.php),
 *                 the week and session, level / verified / programme, the
 *                 motto, and today's plan as a ring
 *   Composer      "What are you working on today?" — Enter adds a task for
 *                 today; quick access to the sections this member has
 *   Announcement  the latest from the Announcements space, dismissible
 *   Act now       Needs you (most urgent first, one primary button) and
 *                 Your week (calendar feed + events)
 *   Your growth   Reading, Courses, Achievements, the growth signature
 *                 (lib/GrowthSignature.php), Your journey so far + Share my growth
 *   Your record   Quest points, attendance, mentorship
 *
 * Colour means something or it isn't there: ink for the one next thing to
 * press, gold for the member and their progress, amber due, red late, green
 * live, indigo learning — and every colour has its word beside it.
 *
 * No fact is printed twice. The online count lives in the top bar; today's
 * date is in the week line; mentorship hours are in the record only.
 *
 * Live parts: #hvAsk (composer), #hvWeek (calendar feed), #hvPulse (share
 * dialog) — portal/home.js; #openMeetModal (portal/meet-modal.js); the
 * Google card's ids (portal/google.js).
 */
declare(strict_types=1);

$hvTz  = new DateTimeZone(defined('AV_TZ') ? (string) AV_TZ : 'Africa/Lagos');
$hvNow = new DateTimeImmutable('now', $hvTz);
$todayKey = $hvNow->format('Y-m-d');

/* The programme session running now, or the next to start (lib/ProgrammeSessions.php). */
$prog = null;
try { $pn = ProgrammeSessions::now(); $prog = $pn['current'] ?: $pn['next']; } catch (Throwable $e) { error_log('[portal] sessions: ' . $e->getMessage()); }

/* ── Reading: where the member is in the 24 ─────────────────────────── */
$hvReads = $isNgv || !empty($mayRead);
$hvBook = null; $hvBooksDone = 0; $hvLastVerified = '';
if ($hvReads && class_exists('NgvReading')) {
    try {
        foreach (NgvReading::shelf((int) $u['id']) as $slot => $c) {
            $st = (string) ($c['status'] ?? 'empty');
            if ($st === 'verified') {
                $hvBooksDone++;
                if ((string) ($c['reviewed_at'] ?? '') > $hvLastVerified) $hvLastVerified = (string) $c['reviewed_at'];
            } elseif ($hvBook === null && in_array($st, ['draft', 'resubmit', 'submitted'], true)) {
                $hvBook = $c;
            } elseif ($hvBook === null && $st === 'empty') {
                $hvBook = $c;   /* the next slot to start */
            }
        }
    } catch (Throwable $e) { error_log('[today] reading: ' . $e->getMessage()); }
}
$hvBookPct = 0; $hvSittings = 0;
if ($hvBook && (int) ($hvBook['chapters'] ?? 0) > 0) {
    $hvTo = 0;
    foreach ($hvBook['sessions'] ?? [] as $s) $hvTo = max($hvTo, (int) ($s['chapter_to'] ?? 0));
    $hvBookPct = min(100, (int) round(100 * $hvTo / (int) $hvBook['chapters']));
}
if ($hvBook) $hvSittings = count($hvBook['sessions'] ?? []);
$hvReadGoto = $isNgv ? 'ngv' : 'reading';

/* ── The greeting (lib/Greeting.php): picked, not stored ────────────── */
$hvGreet = Greeting::pick([
    'id' => (int) $u['id'], 'first' => $first, 'date' => $todayKey, 'hour' => (int) $hvNow->format('G'),
    'volunteer' => !empty($isVol),
    'birthday' => class_exists('Birthdays') && Birthdays::isOn((int) $u['id'], $todayKey),
    'session_day_one' => $prog && !empty($prog['live']) && (string) ($prog['start_date'] ?? '') === $todayKey,
    'session_final_week' => $prog && !empty($prog['live']) && (int) $prog['week_of'] === (int) $prog['total_weeks'] && (int) $prog['total_weeks'] > 1,
    'reading' => $hvBook !== null && (string) ($hvBook['status'] ?? '') === 'draft',
    'formal' => !empty($billsUnanswered),
]);
$hvWeekLine = Greeting::sublineParts($todayKey, $prog);

/* ── You: the ring is the way to the next level ─────────────────────── */
$jNeedPts = $journey['next_points'] ?? null;
$jHavePts = max(0, (int) ($journey['quest_points'] ?? 0));
$hvLevelPct = $journey['next'] === null ? 100
    : ($jNeedPts !== null && (int) $jNeedPts > 0 ? min(100, (int) round(100 * $jHavePts / (int) $jNeedPts)) : $jPct);
$hvLevelLabel = (string) ($journey['label'] ?? 'Member');
$hvPhoto = '';
if (class_exists('CardPhoto')) { try { $hvPhoto = CardPhoto::deliver((string) (CardPhoto::of((int) $u['id'])['photo'] ?? '')); } catch (Throwable $e) {} }
$hvVerified = class_exists('MemberVerification') && MemberVerification::isVerified((int) $u['id']);
$hvTrack = $isNgv ? trim((string) ($ngvVars['myTrack'] ?? '')) : '';
/* The printed ID, as on the card (lib/IdCard.php) — the desktop board shows it after the programme. */
$hvIdNo = '';
if (class_exists('IdCard')) { try { $hvIdNo = IdCard::printedId((int) $u['id']); } catch (Throwable $e) {} }
$hvAbout = $isNgv ? 'NGV' . ($hvTrack !== '' ? ' · ' . $hvTrack : '') : (!empty($isVol) ? 'Volunteer' : ($isOrg ? (string) $accessLevel : 'Learner'));

/* Today's plan: the tasks due today, on both sides of the CACENTRE bridge.
   No plan, no ring — an empty ring reads as a failing grade. */
$hvPlanAll = 0; $hvPlanDone = 0; $hvPlanNext = '';
foreach ($myTasks as $t) {
    if ((string) ($t['due'] ?? '') !== $todayKey) continue;
    $hvPlanAll++;
    if (!empty($t['done'])) $hvPlanDone++; elseif ($hvPlanNext === '') $hvPlanNext = (string) $t['title'];
}
foreach ($cacTasks as $t) {
    if ((string) ($t['due'] ?? '') !== $todayKey) continue;
    $hvPlanAll++;
    if ($hvPlanNext === '') $hvPlanNext = (string) ($t['title'] ?? '');
}
$hvPlanPct = $hvPlanAll ? (int) round(100 * $hvPlanDone / $hvPlanAll) : 0;
$hvPlanLeft = $hvPlanAll - $hvPlanDone;

/* ── Needs you: what genuinely needs this member now ────────────────── */
/* tone: warn (due, not late) · bad (late, refused) · ok (live) · info (learning) · n (plain).
   Titles and subs are escaped here, once, because the row prints them as markup. */
$hvIcons = [
    'card'  => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="12" r="2.5"/><path d="M14 10h4M14 14h4"/>',
    'alert' => '<path d="M12 8v5M12 16.5h.01"/><circle cx="12" cy="12" r="9"/>',
    'check' => '<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"/>',
    'video' => '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="M16 10l6-3v10l-6-3"/>',
    'scale' => '<path d="M12 3v18M5 7h14M7 7l-3 7h6zM17 7l-3 7h6z"/>',
    'naira' => '<path d="M12 3v18M16 7H10a3 3 0 0 0 0 6h4a3 3 0 0 1 0 6H8"/>',
    'path'  => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    'hands' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6"/>',
    'chat'  => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
    'book'  => '<path d="M3 5a2 2 0 0 1 2-2h5a2 2 0 0 1 2 2v16a2 2 0 0 0-2-2H3zM21 5a2 2 0 0 0-2-2h-5a2 2 0 0 0-2 2v16a2 2 0 0 1 2-2h7z"/>',
    'cap'   => '<path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11v5c3 2 9 2 12 0v-5"/>',
    'tasks' => '<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"/>',
    'cal'   => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
    'mentor'=> '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/>',
    'wallet'=> '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18"/>',
    'user'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    'megaphone' => '<path d="M3 11v2l13 5V6zM16 9a3 3 0 0 1 0 6M6 14l1 5h3l-1-4"/>',
    'share' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>',
    'shield'=> '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
    'medal' => '<path d="M12 15l-3 6 3-1.5 3 1.5z"/><circle cx="12" cy="9" r="6"/>',
    'target'=> '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
];
$hvSvg = static fn(string $k, int $s = 18): string => '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($hvIcons[$k] ?? '') . '</svg>';

$attn = [];
if ($dues && $duesState !== 'active' && empty($dues['lifetime'])) {
    $attn[] = ['ico' => 'card', 'tone' => 'warn', 'chip' => $duesState === 'none' ? 'Not paid' : 'Due now',
        'title' => 'Membership dues', 'sub' => 'Renew to stay verified · card or transfer', 'cta' => 'Pay dues', 'goto' => 'membership'];
}
if (!empty($billsUnanswered)) {
    $b0 = $billsUnanswered[0];
    $bLeft = Conduct::noticeLeft($b0);
    $attn[] = ['ico' => 'scale', 'tone' => 'bad', 'chip' => 'Waiting for you',
        'title' => count($billsUnanswered) === 1
            ? ($b0['kind'] === 'fine' ? 'A fine of ₦' . number_format((int) $b0['amount']) : 'A deduction of ' . (int) $b0['points'] . ' points') . ' has been proposed'
            : count($billsUnanswered) . ' fines or deductions are waiting for your answer',
        'sub' => 'Accept it or give your side' . ($bLeft !== '' ? ' — the committee may decide after ' . $bLeft : ''),
        'cta' => 'Answer', 'goto' => 'bills'];
}
if ($isOrg && ($tasksDue || $cacDue)) {
    $n = count($tasksDue) + $cacDue;
    $attn[] = ['ico' => $tasksOverdue ? 'alert' : 'check', 'tone' => $tasksOverdue ? 'bad' : 'n',
        'chip' => $tasksOverdue ? $tasksOverdue . ' overdue' : 'Today',
        'title' => $n . ' task' . ($n === 1 ? '' : 's') . ' due',
        /* Named when some of it is in the console, so "Open" does not land
           somebody on a list shorter than the row just counted. */
        'sub' => $cacDue ? 'Due today or earlier · ' . $cacDue . ' in the console' : 'Due today or earlier',
        'cta' => 'Open', 'goto' => 'tasks'];
}
if ($nextSession) {
    $ns = $nextSession;
    $live = !empty($ns['live']);
    $attn[] = ['ico' => 'video', 'tone' => $live ? 'ok' : 'info', 'chip' => $live ? 'Live now' : date('D g:ia', strtotime((string) $ns['when'] . ' UTC') ?: time()),
        'title' => ($live ? 'Meeting live — ' : 'Next meeting — ') . e($ns['title']),
        'sub' => e($ns['role']) . ' ' . e($ns['with']), 'cta' => $live ? 'Join' : 'Go', 'goto' => 'mentorship'];
}
/* The Quest's decision whose appeal window is closing. The decision is the member's; Today says so. */
if (!empty($billsClosing)) {
    $attn[] = ['ico' => 'scale', 'tone' => 'warn', 'chip' => 'Closes ' . Conduct::appealOpenUntil($billsClosing[0]),
        'title' => count($billsClosing) === 1 ? 'You can appeal ' . e(Conduct::caseLabel($billsClosing[0]))
            : count($billsClosing) . ' decisions can be appealed for three more days at most',
        'sub' => 'Only if you think it is wrong. Somebody new hears it.', 'cta' => 'See', 'goto' => 'bills'];
}
if ($isNgv && $ngvOwed > 0) {
    $attn[] = ['ico' => 'naira', 'tone' => 'warn', 'chip' => 'Owed',
        'title' => '₦' . number_format($ngvOwed) . ' outstanding on your NGV account',
        'sub' => 'See what it is for, pay, or ask about it', 'cta' => 'Open', 'goto' => 'ngv-account'];
}
$mrq = CfisRequests::summaryFor((int) $u['id']);
if ($mrq['unsent'] > 0) {
    /* We know this one for certain without asking finance: we are the
       ones who could not send it. Everything else about a request is
       finance's to say, and is fetched when the card is drawn. */
    $attn[] = ['ico' => 'alert', 'tone' => 'bad', 'chip' => 'Not sent',
        'title' => $mrq['unsent'] . ' money request' . ($mrq['unsent'] === 1 ? '' : 's') . ' did not reach finance',
        'sub' => 'Still saved here — send ' . ($mrq['unsent'] === 1 ? 'it' : 'them') . ' again',
        'cta' => 'Send', 'goto' => 'requests'];
}
if ($gateWhy !== '' && GatePass::ready()) {
    $attn[] = ['ico' => 'shield', 'tone' => 'bad', 'chip' => 'Refused', 'title' => 'The CACENTRE gate cannot let you in',
        'sub' => e($gateWhy), 'cta' => 'Why', 'goto' => 'attendance'];
}
if ($isNgv && $hvTrack === '') {
    $attn[] = ['ico' => 'path', 'tone' => 'info', 'chip' => 'To choose', 'title' => 'Choose your NGV track and plan',
        'sub' => 'It sets your path and your training fee', 'cta' => 'Choose', 'goto' => 'ngv'];
}
if (!empty($isVol) && !empty($volWaiting)) {
    $attn[] = ['ico' => 'hands', 'tone' => 'info', 'chip' => 'Waiting',
        'title' => $volWaiting . ' thing' . ($volWaiting === 1 ? '' : 's') . ' waiting in your Volunteer hub',
        'sub' => 'Meetings to answer, minutes to write or approve, actions overdue', 'cta' => 'Open', 'goto' => 'volunteer'];
}
if ($postsToday) {
    $attn[] = ['ico' => 'chat', 'tone' => 'ok', 'chip' => 'New',
        'title' => $postsToday . ' new community post' . ($postsToday === 1 ? '' : 's') . ' today',
        'sub' => 'Catch up with members', 'cta' => 'Open', 'goto' => 'community'];
}

/* ── Quick access: only the sections this member has ────────────────── */
$hvQuick = [];
foreach ([[$hvReadGoto, 'Book Club', 'book'], ['learning', 'Courses', 'cap'], ['tasks', 'Tasks', 'tasks'], ['meetings', 'Meetings', 'video'],
          ['mentorship', 'Mentorship', 'mentor'], ['wallet', 'Finances', 'wallet'], ['membership', 'Profile', 'user']] as [$qid, $ql, $qi]) {
    if ($qid === $hvReadGoto && !$hvReads) continue;
    if (PortalNav::visible($qid, $portalCtx)) $hvQuick[] = ['#' . $qid, $qid, $ql, $qi];
}
array_splice($hvQuick, max(0, count($hvQuick) - 2), 0, [['/events/', '', 'Events', 'cal']]);

/* ── The announcement: the latest from the Announcements space, a fortnight at most ── */
$hvNotice = null;
if (class_exists('Community')) {
    try {
        foreach (Community::feed('announcements', 'latest', 1, 0, (int) $u['id']) as $p) {
            if (strtotime((string) $p['created_at']) >= time() - 14 * 86400) $hvNotice = $p;
        }
    } catch (Throwable $e) { error_log('[today] notice: ' . $e->getMessage()); }
}

/* ── Events (lib/SiteEvents.php): ours this member may see — members-only
   and team ones included — and Africa GATES', soonest first. Never fails the page. */
$todayEvents = [];
try { $todayEvents = SiteEvents::listFor($u, 'upcoming', 4); } catch (Throwable $e) { error_log('[today] events: ' . $e->getMessage()); }

/* ── Your growth ────────────────────────────────────────────────────── */
$hvCoursesDone = count(array_filter($courses, static fn($c) => !empty($c['complete'])));
$hvCourseNow = null; $hvCourseLast = null;
foreach ($courses as $c) {
    if (empty($c['complete'])) { if (!$hvCourseNow || (int) $c['pct'] > (int) $hvCourseNow['pct']) $hvCourseNow = $c; }
    elseif (!$hvCourseLast) $hvCourseLast = $c;
}
$hvSig = GrowthSignature::compute([
    'books_verified' => $hvBooksDone, 'books_total' => $hvReads ? NgvReading::TOTAL : 0,
    'courses' => count($courses), 'courses_done' => $hvCoursesDone, 'courses_certified' => $certs,
    'tasks' => $myTasks, 'today' => $todayKey,
    'attendance_rate' => $gateSum && $gateSum['counted'] ? (int) $gateSum['rate'] : null,
    'mentor_rate' => $mentorStats['rate'] !== null ? (int) $mentorStats['rate'] : null,
    'mentees' => (int) ($journey['active_mentees'] ?? 0),
    'mentees_needed' => $journey['next'] !== null ? (int) ($journey['mentees_needed'] ?? 0) : 0,
]);
$hvSigRows = ['knowledge' => ['Knowledge', 'books, courses', 'book'], 'competence' => ['Competence', 'assessments passed', 'medal'],
              'responsibility' => ['Responsibility', 'tasks on time', 'shield'], 'consistency' => ['Consistency', 'attendance, sessions', 'cal'],
              'impact' => ['Impact', 'mentees', 'target']];
$hvMentees = (int) ($journey['active_mentees'] ?? 0);
$hvMeetings = (int) $mentorStats['attended'];
$hvMentor = null;
if (class_exists('Mentorship')) { try { foreach (Mentorship::myMentors((int) $u['id']) as $m) if ($m['status'] === 'active') { $hvMentor = $m; break; } } catch (Throwable $e) {} }

$hvRing = static function (int $size, int $r, int $w, int $pct, string $track = 'hv-ring-track'): string {
    $c = 2 * M_PI * $r; $h = $size / 2;
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '" aria-hidden="true">'
        . '<circle class="' . $track . '" cx="' . $h . '" cy="' . $h . '" r="' . $r . '" fill="none" stroke-width="' . $w . '"/>'
        . ($pct > 0 ? '<circle class="hv-ring-fill" cx="' . $h . '" cy="' . $h . '" r="' . $r . '" fill="none" stroke-width="' . $w . '" stroke-dasharray="' . round($c * $pct / 100, 1) . ' ' . round($c, 1) . '" stroke-linecap="round" transform="rotate(-90 ' . $h . ' ' . $h . ')"/>' : '')
        . '</svg>';
};
?>
      <section class="pview avp-view hv" id="view-overview" data-view="overview" aria-labelledby="hvHello">

        <section class="hv-hero" aria-label="You">
          <div class="hv-hero-me">
            <a href="#membership" data-goto="membership" class="hv-ring" style="width:120px;height:120px" aria-label="Your profile. Level <?= e($stageCode) ?>, <?= e($hvLevelLabel) ?>.<?= $journey['next'] !== null ? ' ' . $hvLevelPct . '% of the way to ' . e($jNextLabel) . '.' : '' ?>">
              <?= $hvRing(120, 56, 5, $hvLevelPct) ?>
              <span class="hv-avatar"><?php if ($hvPhoto !== ''): ?><img src="<?= e($hvPhoto) ?>" alt=""><?php else: ?><?= e($pInitials) ?><?php endif; ?></span>
              <span class="hv-level" aria-hidden="true"><?= e($stageCode) ?></span>
            </a>
            <div class="hv-hero-t">
              <p class="hv-hero-week" id="hvWeekLine"><?= str_replace(' · ', '<span class="hv-sep"> · </span>', e($hvWeekLine['main'])) ?><?php if ($hvWeekLine['tail'] !== ''): ?><span class="hv-wide"><span class="hv-sep"> · </span><?= e($hvWeekLine['tail']) ?></span><?php endif; ?></p>
              <div class="hv-hello"><h1 id="hvHello" data-kind="<?= e($hvGreet['kind']) ?>"><?= e($hvGreet['text']) ?></h1></div>
              <p class="hv-tags">
                <span class="hv-tag hv-tag--you"><span class="hv-wide">Vanguard </span>Level <?= e($stageCode) ?><span class="hv-wide"> —</span><span class="hv-narrow"> ·</span> <?= e($hvLevelLabel) ?></span>
<?php if ($hvVerified): ?>                <span class="hv-tag hv-tag--ok">Verified<span class="hv-wide"> member</span></span>
<?php endif; ?>                <span class="hv-tag hv-tag--n"><?= e($hvAbout) ?><?php if ($hvIdNo !== ''): ?><span class="hv-wide"> · ID <?= e($hvIdNo) ?></span><?php endif; ?></span>
              </p>
              <p class="hv-motto">Your life is becoming the evidence of your leadership.</p>
            </div>
          </div>
<?php if ($hvPlanAll): ?>
          <a href="#tasks" data-goto="tasks" class="hv-plan" aria-label="Today: <?= $hvPlanDone ?> of <?= $hvPlanAll ?> done, <?= $hvPlanPct ?>% of today’s plan">
            <svg width="156" height="156" viewBox="0 0 156 156" aria-hidden="true"><circle class="hv-ring-track" cx="78" cy="78" r="70" fill="none" stroke-width="6"/><?php if ($hvPlanPct > 0): ?><circle class="hv-ring-fill" cx="78" cy="78" r="70" fill="none" stroke-width="6" stroke-dasharray="<?= round(2 * M_PI * 70 * $hvPlanPct / 100, 1) ?> <?= round(2 * M_PI * 70, 1) ?>" stroke-linecap="round" transform="rotate(-90 78 78)"/><?php endif; ?></svg>
            <span class="hv-plan-t"><b><?= $hvPlanPct ?>%</b><span>of today’s plan</span></span>
          </a>
          <a href="#tasks" data-goto="tasks" class="hv-plan-row">
            <span><?= $hvRing(44, 19, 5, $hvPlanPct) ?><b class="n"><?= $hvPlanDone ?>/<?= $hvPlanAll ?></b></span>
            <span class="hv-stack"><b>Today’s plan</b><span class="hv-small hv-muted"><?= $hvPlanLeft ? ($hvPlanLeft === 1 ? 'One left: ' . e($hvPlanNext) : $hvPlanLeft . ' left · next: ' . e($hvPlanNext)) : 'All done for today' ?></span></span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8C9096" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
          </a>
<?php endif; ?>
        </section>

        <div class="hv-ask">
<?php if ($isOrg): ?>
          <form class="hv-composer" id="hvAsk" data-csrf="<?= e($collabCsrf) ?>" data-today="<?= e($todayKey) ?>" autocomplete="off" aria-label="Add a task">
            <label class="hv-sr" for="hvAskTitle">What are you working on today?</label>
            <input id="hvAskTitle" type="text" maxlength="200" placeholder="What are you working on today?" required>
            <span class="hv-composer-hint">Enter adds a task · <kbd class="hv-kbd">Ctrl K</kbd> jumps</span>
            <button type="submit" aria-label="Add task" class="hv-btn hv-btn--ink"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>
          </form>
          <p class="hv-ask-msg" id="hvAskMsg" role="status" aria-live="polite" hidden></p>
<?php endif; ?>
          <nav class="hv-quick" aria-label="Quick access">
<?php foreach ($hvQuick as [$qh, $qid, $ql, $qi]): ?>
            <a href="<?= e($qh) ?>"<?= $qid !== '' ? ' data-goto="' . e($qid) . '"' : '' ?> class="hv-mod"><i><?= $hvSvg($qi, 15) ?></i><?= e($ql) ?></a>
<?php endforeach; ?>
          </nav>
        </div>

        <div class="hv-grid">
<?php if ($hvNotice):
    /* The headline only: the post's first line, cut at its first sentence. The rest,
       and who wrote it, are on the reading page. */
    $hvNTitle = trim((string) strtok(trim((string) $hvNotice['body']), "\n"));
    if (preg_match('/^(.{12,}?[.!?])(?:\s|$)/u', $hvNTitle, $hvNm)) $hvNTitle = rtrim($hvNm[1], '.');
    $hvNTitle = mb_strlen($hvNTitle) > 120 ? rtrim(mb_substr($hvNTitle, 0, 118)) . '…' : $hvNTitle; ?>
          <aside class="hv-notice" id="hvNotice" data-id="<?= (int) $hvNotice['id'] ?>" aria-label="Announcement">
            <span class="hv-notice-ico" aria-hidden="true"><?= $hvSvg('megaphone', 17) ?></span>
            <span class="hv-lbl">Announcement</span>
            <?php /* One line, always: a long one scrolls (portal/home.js measures it), the whole of it is a page away. */ ?>
            <a class="hv-notice-t" href="/portal/announcement.php?id=<?= (int) $hvNotice['id'] ?>" title="<?= e($hvNTitle) ?>">
              <span class="hv-marq"><b><?= e($hvNTitle) ?></b><b class="hv-marq-copy" aria-hidden="true"><?= e($hvNTitle) ?></b></span>
            </a>
            <a href="/portal/announcement.php?id=<?= (int) $hvNotice['id'] ?>" class="hv-more" style="padding:0 10px">Read<span class="hv-sr"> the announcement</span></a>
            <button type="button" class="hv-btn hv-btn--sm hv-btn--ghost hv-btn--icon" data-hv-dismiss aria-label="Dismiss announcement"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
          </aside>
<?php endif; ?>

          <div class="hv-zone"><h2>Act now</h2></div>

          <section class="hv-card hv-s7" aria-labelledby="hvNeedsH">
            <div class="hv-card-hd"><h2 class="hv-h2" id="hvNeedsH">Needs you<?php if (count($attn) > 1): ?> <small>· <?= count($attn) ?>, most urgent first</small><?php endif; ?></h2><?php if ($isOrg): ?><a href="#tasks" data-goto="tasks" class="hv-more">All tasks →</a><?php endif; ?></div>
<?php if ($attn): ?>
            <ul>
<?php foreach ($attn as $ai => $a): ?>
              <li class="hv-task">
                <span class="hv-ico hv-ico--<?= e($a['tone']) ?>"><?= $hvSvg($a['ico']) ?></span>
                <span class="hv-task-t"><b><?= $a['title'] ?></b><span class="hv-small hv-muted"><span class="hv-chip hv-chip--<?= e($a['tone']) ?>"><?= e($a['chip']) ?></span><?= $a['sub'] ?></span></span>
                <a href="#<?= e($a['goto']) ?>" data-goto="<?= e($a['goto']) ?>" class="hv-btn hv-btn--sm<?= $ai === 0 ? ' hv-btn--ink' : '' ?>"><?= e($a['cta']) ?></a>
              </li>
<?php endforeach; ?>
            </ul>
<?php else: ?>
            <p class="hv-clear"><span class="hv-ico hv-ico--ok"><?= $hvSvg('check') ?></span>Nothing needs you right now.</p>
<?php endif; ?>
          </section>

          <section class="hv-card hv-s5" aria-labelledby="hvWeekH">
            <div class="hv-card-hd"><h2 class="hv-h2" id="hvWeekH">Your week</h2><?php if ($isOrg): ?><a href="#meetings" data-goto="meetings" class="hv-more">Calendar →</a><?php endif; ?></div>
<?php if ($isOrg): ?>
            <?php /* The feed is YOUR calendar — meetings and sessions. The
                     Events list below it is the programme's, and the two come
                     from different places. The feed has to know whether
                     events follow, or its empty line ("a clear run") prints
                     directly above one. */ ?>
            <ol id="hvWeek" data-today="<?= e($todayKey) ?>"<?= $todayEvents ? ' data-has-events="1"' : '' ?> aria-live="polite"><li class="hv-skel" aria-hidden="true"></li><li class="hv-skel" aria-hidden="true"></li><li class="hv-sr">Loading your calendar…</li></ol>
<?php endif; ?>
<?php if ($todayEvents): ?>
            <ol aria-label="Events">
              <li class="hv-day">Events</li>
<?php
    /* Each event does something: Join from half an hour before, Reserve,
       Details, Add to calendar — and the flier somebody made for it, when there is one. */
    $evTomorrow = $hvNow->modify('+1 day')->format('Y-m-d');
    foreach ($todayEvents as $ev):
        $evUrl = (string) $ev['url'];
        $evExt = ($ev['source'] ?? '') === 'gates' || preg_match('~^https?://~', $evUrl) && !str_starts_with($evUrl, rtrim((string) (defined('SITE_URL') ? SITE_URL : ''), '/') . '/');
        $evTgt = $evExt ? ' target="_blank" rel="noopener"' : '';
        $evOff = !empty($ev['cancelled']);
        $evFlag = $evOff ? 'Cancelled' : (!empty($ev['ongoing']) ? 'On now' : ((string) ($ev['date'] ?? '') === $todayKey ? 'Today' : ((string) ($ev['date'] ?? '') === $evTomorrow ? 'Tomorrow' : '')));
        $evWho = ($ev['host'] ?? '') !== 'Afrovanguard' ? (string) ($ev['host'] ?? '') : (($ev['audience'] ?? 'public') === 'org' ? 'Team only' : (($ev['audience'] ?? 'public') === 'members' ? 'Members only' : ''));
        $evImg = (string) ($ev['image'] ?? ''); ?>
              <li class="hv-ag<?= $evOff ? ' is-off' : '' ?>">
<?php if ($evImg !== ''): ?>
                <a class="hv-ag-art" href="<?= e($evUrl) ?>"<?= $evTgt ?> tabindex="-1" aria-hidden="true"><img src="<?= e($evImg) ?>" alt="" loading="lazy" decoding="async"></a>
<?php else: ?>
                <span class="hv-ag-when<?= $evFlag === 'On now' ? ' is-live' : '' ?>"><b><?= e((string) $ev['day']) ?> <?= e((string) $ev['month']) ?></b><?php if ($evFlag !== '' && !$evOff): ?><span class="<?= $evFlag === 'On now' ? 'is-soon' : 'hv-small hv-muted' ?>"><?= e($evFlag) ?></span><?php endif; ?></span>
<?php endif; ?>
                <span class="hv-ag-t"><b><a href="<?= e($evUrl) ?>"<?= $evTgt ?>><?= e((string) $ev['title']) ?></a></b><span class="hv-small hv-muted"><time datetime="<?= e((string) ($ev['iso'] ?? '')) ?>"><?= e((string) $ev['when']) ?></time><?= ($ev['location'] ?? '') !== '' ? ' · ' . e((string) $ev['location']) : '' ?><?= $evWho !== '' ? ' · ' . e($evWho) : '' ?><?= $evOff ? ' · Cancelled' : '' ?></span></span>
<?php if (!$evOff): ?>
                <span class="hv-ag-acts">
<?php if (!empty($ev['join_open'])): ?>
                  <a class="hv-btn hv-btn--ink" href="<?= e((string) $ev['online']) ?>" target="_blank" rel="noopener">Join now<span class="hv-sr">: <?= e((string) $ev['title']) ?>, opens the call</span></a>
<?php elseif (($ev['rsvp'] ?? '') !== ''): ?>
                  <a class="hv-btn" href="<?= e((string) $ev['rsvp']) ?>" target="_blank" rel="noopener">Reserve<span class="hv-sr">: <?= e((string) $ev['title']) ?></span></a>
<?php endif; ?>
                  <a class="hv-btn hv-btn--ghost" href="<?= e($evUrl) ?>"<?= $evTgt ?>>Details<span class="hv-sr">: <?= e((string) $ev['title']) ?></span></a>
<?php if (($ev['calendar'] ?? '') !== ''): ?>
                  <a class="hv-btn hv-btn--ghost" href="<?= e((string) $ev['calendar']) ?>" target="_blank" rel="noopener">Add to calendar<span class="hv-sr">: <?= e((string) $ev['title']) ?></span></a>
<?php endif; ?>
                </span>
<?php endif; ?>
              </li>
<?php endforeach; ?>
            </ol>
<?php elseif (!$isOrg): ?>
            <p class="hv-empty">Nothing coming up yet. <a href="/events/">See all events →</a></p>
<?php endif; ?>
            <div class="hv-week-ft">
<?php if ($isOrg): ?>              <button type="button" class="hv-btn hv-btn--sm" id="openMeetModal" aria-haspopup="dialog">Schedule meeting</button>
<?php endif; ?>              <a href="/events/" class="hv-more">All events →</a>
            </div>
          </section>

<?php if ($isOrg && ($google['oauth'] || $google['org'])):
    /* Google, on Today (lib/GoogleHub.php). Filled by portal/google.js
       from /portal/workspace.php?action=me once connected. */
    $gDomain = (string) $google['domain'];
    $gAct = [
        ['Compose', av_ws_link('AV_WS_MAIL_URL', 'https://mail.google.com/a/' . $gDomain) . '#compose'],
        ['New doc', 'https://docs.google.com/document/create'],
        ['New event', 'https://calendar.google.com/calendar/u/0/r/eventedit'],
        ['Start a Meet', 'https://meet.google.com/new'],
    ];
?>
          <section class="hv-card hv-full" id="avpGoogleCard" aria-labelledby="avpGoogleH">
            <div class="hv-card-hd"><h2 class="hv-h2" id="avpGoogleH">Google <small>· <?= $google['connected'] ? e($google['email']) : 'mail, calendar, Drive and Chat' ?></small></h2><?php if ($google['connected']): ?><span class="hv-chip hv-chip--ok">Connected</span><?php endif; ?></div>
<?php if ($google['connected']): ?>
            <div class="hv-g-kpis" id="gKpis">
              <div><span>Unread mail</span><b class="av-num" id="gUnread">—</b></div>
              <div><span>Next event</span><b id="gNext">—</b><small id="gNextWhen"></small></div>
              <div><span>Recent files</span><b class="av-num" id="gFileCount">—</b></div>
            </div>
            <div class="hv-g-cols">
              <div><h3>Inbox</h3><ul class="avp-g-list" id="gMail" aria-live="polite"><li class="avp-g-empty">Loading…</li></ul></div>
              <div><h3>Coming up</h3><ul class="avp-g-list" id="gEvents"><li class="avp-g-empty">Loading…</li></ul></div>
              <div><h3>Recent files</h3><ul class="avp-g-list" id="gFiles"><li class="avp-g-empty">Loading…</li></ul></div>
            </div>
            <div class="hv-acts">
<?php foreach ($gAct as [$gl, $gu]): ?>
              <a class="hv-btn hv-btn--sm" href="<?= e($gu) ?>" target="_blank" rel="noopener noreferrer"><?= e($gl) ?> <span aria-hidden="true">↗</span></a>
<?php endforeach; ?>
              <a class="hv-btn hv-btn--sm" href="#chat" data-goto="chat">Google Chat in Team Chat</a>
            </div>
<?php elseif ($google['oauth']): ?>
            <p class="hv-muted">Connect your Google account to see your inbox, what is coming up in your calendar and your recent Drive files here, and your Google Chat spaces in Team Chat. You sign in with Google once and can disconnect at any time.</p>
            <div><a class="hv-btn hv-btn--ink" href="<?= e($google['connect']) ?>">Connect Google</a></div>
<?php else: ?>
            <p class="hv-muted">Your own Google account cannot be connected here yet<?= $google['admin'] ? ': ' . e($google['problem']) : ' — an administrator needs to switch it on' ?>. The Google apps are in the Suite.</p>
            <div><a class="hv-btn" href="#tools" data-goto="tools">Open the Suite</a></div>
<?php endif; ?>
          </section>
<?php endif; ?>

          <div class="hv-zone"><h2>Your growth</h2></div>

<?php /* The growth cards share the row between them: three a third each, two a half, one the lot. */
$hvGrowN = ($hvReads && $hvBook ? 1 : 0) + 1 + ($hvBooksDone || $certs ? 1 : 0);
$hvGrowS = ['', 'hv-full', 'hv-s6', 'hv-s4'][$hvGrowN]; ?>
          <div class="hv-growth">
<?php if ($hvReads && $hvBook): $hvBookN = (int) $hvBook['slot']; $hvStarted = (string) ($hvBook['status'] ?? 'empty') !== 'empty'; ?>
            <section class="hv-card <?= $hvGrowS ?>" aria-labelledby="hvReadH">
              <div class="hv-card-hd"><h2 class="hv-h2" id="hvReadH">Reading</h2><span class="hv-chip hv-chip--info">Book <?= $hvBookN ?> of <?= NgvReading::TOTAL ?></span></div>
              <div class="hv-row">
                <span class="hv-cover" aria-hidden="true"><?= $hvBookN ?></span>
                <div class="hv-stack">
                  <b style="font-size:16px"><?= $hvStarted && (string) $hvBook['title'] !== '' ? e((string) $hvBook['title']) : 'Your next book' ?></b>
                  <span class="hv-small hv-muted"><?= $hvStarted ? (((string) ($hvBook['author'] ?? '') !== '' ? e((string) $hvBook['author']) . ' · ' : '') . $hvSittings . ' sitting' . ($hvSittings === 1 ? '' : 's')) : 'Choose it from the list and start' ?></span>
<?php if ($hvStarted && (int) ($hvBook['chapters'] ?? 0) > 0): ?>
                  <span class="hv-meter" style="margin-top:auto"><span class="hv-bar" role="progressbar" aria-label="Chapters read" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $hvBookPct ?>"><i style="width:<?= $hvBookPct ?>%"></i></span><b class="hv-small"><?= $hvBookPct ?>%</b></span>
<?php endif; ?>
                </div>
              </div>
              <div class="hv-card-ft"><a href="#<?= e($hvReadGoto) ?>" data-goto="<?= e($hvReadGoto) ?>" class="hv-btn hv-btn--sm hv-btn--ink"><?= $hvStarted ? 'Log a sitting' : 'Start a book' ?></a><a href="#<?= e($hvReadGoto) ?>" data-goto="<?= e($hvReadGoto) ?>" class="hv-more">Shelf →</a></div>
            </section>
<?php endif; ?>

            <section class="hv-card <?= $hvGrowS ?>" aria-labelledby="hvCourseH">
              <div class="hv-card-hd"><h2 class="hv-h2" id="hvCourseH">Courses</h2><span class="hv-small hv-muted"><b style="color:var(--hv-ink)"><?= $hvCoursesDone ?></b> completed</span></div>
<?php if ($hvCourseNow): ?>
              <div class="hv-row">
                <span class="hv-ico hv-ico--info"><?= $hvSvg('cap', 20) ?></span>
                <span class="hv-stack" style="gap:6px"><b><?= e((string) $hvCourseNow['title']) ?></b><span class="hv-meter"><span class="hv-bar" role="progressbar" aria-label="Course progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $hvCourseNow['pct'] ?>"><i style="width:<?= (int) $hvCourseNow['pct'] ?>%"></i></span><b class="hv-small"><?= (int) $hvCourseNow['pct'] ?>%</b></span></span>
              </div>
<?php endif; ?>
<?php if ($hvCourseLast): ?>
              <div class="hv-row">
                <span class="hv-ico hv-ico--ok"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg></span>
                <span class="hv-stack" style="gap:0"><b><?= e((string) $hvCourseLast['title']) ?></b><span class="hv-small hv-muted">Completed<?= !empty($hvCourseLast['certified']) ? ' · with a certificate' : '' ?></span></span>
              </div>
<?php endif; ?>
<?php if (!$hvCourseNow && !$hvCourseLast): ?>
              <p class="hv-empty">You haven’t joined a course yet.</p>
<?php endif; ?>
              <div class="hv-card-ft"><?php if ($hvCourseNow): ?><a href="/academy/<?= e((string) $hvCourseNow['slug']) ?>/learn/" class="hv-btn hv-btn--sm hv-btn--ink">Resume</a><?php else: ?><a href="/academy/" class="hv-btn hv-btn--sm hv-btn--ink">Browse the Academy</a><?php endif; ?><a href="#learning" data-goto="learning" class="hv-more">All courses →</a></div>
            </section>

<?php if ($hvBooksDone || $certs): ?>
            <section class="hv-card <?= $hvGrowS ?>" aria-labelledby="hvAchH">
              <div class="hv-card-hd"><h2 class="hv-h2" id="hvAchH">Achievements</h2><?php if ($hvLastVerified !== '' && strtotime($hvLastVerified) >= time() - 14 * 86400): ?><span class="hv-chip hv-chip--you">New</span><?php endif; ?></div>
              <div class="hv-row">
                <span class="hv-badge" aria-hidden="true"><img src="/assets/site/av-seal-96.png" alt="" width="30" height="30"><i></i></span>
                <span class="hv-stack" style="gap:3px">
<?php if ($hvBooksDone): ?>
                  <b style="font-size:15px"><?= $hvBooksDone ?> book<?= $hvBooksDone === 1 ? '' : 's' ?> read</b><span class="hv-small hv-muted">Verified<?= $hvLastVerified !== '' ? ' ' . e(date('j M', strtotime($hvLastVerified))) : '' ?> by <?= $isNgv ? 'your track lead' : 'the reading checker' ?></span>
<?php else: ?>
                  <b style="font-size:15px"><?= $certs ?> certificate<?= $certs === 1 ? '' : 's' ?></b><span class="hv-small hv-muted">From the Academy</span>
<?php endif; ?>
                </span>
              </div>
              <p class="hv-small hv-muted">You see the card before anything is shared.</p>
              <div class="hv-card-ft"><button type="button" class="hv-btn hv-btn--sm hv-btn--ink" data-hv-pulse aria-haspopup="dialog">Share card</button><a href="#quest" data-goto="quest" class="hv-more">All →</a></div>
            </section>
<?php endif; ?>
          </div>

<?php if ($hvSig): ?>
          <section class="hv-card hv-s7" aria-labelledby="hvSigH">
            <div class="hv-card-hd"><h2 class="hv-h2" id="hvSigH">Your growth signature</h2></div>
            <ul>
<?php foreach ($hvSig as $sk => $sv): [$sl, $ss, $si] = $hvSigRows[$sk]; ?>
              <li class="hv-sig"><i aria-hidden="true"><?= $hvSvg($si, 16) ?></i><span class="hv-sig-l"><b><?= e($sl) ?></b><span class="hv-small hv-muted"><?= e($ss) ?></span></span><span class="hv-bar" role="img" aria-label="<?= e($sl) ?> <?= $sv ?>%"><i style="width:<?= $sv ?>%"></i></span><b><?= $sv ?>%</b></li>
<?php endforeach; ?>
            </ul>
            <details class="hv-how">
              <summary>How it’s worked out →</summary>
              <dl>
<?php foreach ($hvSig as $sk => $sv): ?>
                <dt><?= e($hvSigRows[$sk][0]) ?></dt><dd><?= e(GrowthSignature::HOW[$sk]) ?></dd>
<?php endforeach; ?>
              </dl>
            </details>
            <p class="hv-small hv-muted">Counted from your verified record — what was done, not a judgement of who you are.</p>
          </section>
<?php endif; ?>

          <section class="hv-journey <?= $hvSig ? 'hv-s5' : 'hv-full' ?>" aria-labelledby="hvJourneyH">
            <div class="hv-card-hd hv-journey-hd"><h2 class="hv-h2" id="hvJourneyH">Your journey so far</h2><?php if (!empty($u['created_at'])): ?><span class="hv-small">since <?= e(date('Y', strtotime((string) $u['created_at']) ?: time())) ?></span><?php endif; ?></div>
            <dl>
              <div><dt class="hv-small hv-muted">book<?= $hvBooksDone === 1 ? '' : 's' ?> read</dt><dd class="hv-num"><?= $hvBooksDone ?></dd></div>
              <div><dt class="hv-small hv-muted">course<?= $hvCoursesDone === 1 ? '' : 's' ?> completed</dt><dd class="hv-num"><?= $hvCoursesDone ?></dd></div>
              <div><dt class="hv-small hv-muted">mentee<?= $hvMentees === 1 ? '' : 's' ?> guided</dt><dd class="hv-num"><?= $hvMentees ?></dd></div>
              <div><dt class="hv-small hv-muted">meeting<?= $hvMeetings === 1 ? '' : 's' ?> attended</dt><dd class="hv-num"><?= $hvMeetings ?></dd></div>
            </dl>
            <button type="button" class="hv-share" data-hv-pulse aria-haspopup="dialog"><?= $hvSvg('share') ?>Share my growth</button>
            <p class="hv-share-note">Makes your Vanguard Pulse card — you check it before it goes to WhatsApp or anywhere else.</p>
          </section>

          <div class="hv-zone"><h2>Your record</h2></div>

          <section class="hv-record" aria-label="Your record">
            <div class="hv-rec">
              <span class="hv-lbl">Quest points</span>
              <span class="hv-rec-n"><span class="hv-num"><?= number_format($jHavePts) ?></span><?php if ($questMonth > 0): ?><span class="hv-you-t">+<?= number_format((int) $questMonth) ?> this month</span><?php endif; ?></span>
<?php if ($jNeedPts !== null && $journey['next'] !== null): ?>
              <span class="hv-bar" style="flex:none" role="progressbar" aria-label="Quest points toward <?= e($jNextLabel) ?>" aria-valuemin="0" aria-valuemax="<?= (int) $jNeedPts ?>" aria-valuenow="<?= min((int) $jNeedPts, $jHavePts) ?>"><i style="width:<?= min(100, (int) round(100 * $jHavePts / max(1, (int) $jNeedPts))) ?>%"></i></span>
              <span class="hv-small hv-muted"><?= $jHavePts >= (int) $jNeedPts ? 'Points reached for ' . e($jNextLabel) : '<b style="color:var(--hv-ink)">' . number_format((int) $jNeedPts - $jHavePts) . '</b> to ' . e($jNextLabel) ?><?= $journey['level'] === $jBase && $hvMentees < (int) ($journey['mentees_needed'] ?? 2) ? ' · plus ' . ((int) ($journey['mentees_needed'] ?? 2) - $hvMentees) . ' more active mentee' . (((int) ($journey['mentees_needed'] ?? 2) - $hvMentees) === 1 ? '' : 's') : '' ?></span>
<?php elseif ($journey['next'] === null): ?>
              <span class="hv-small hv-muted">The top of the pyramid</span>
<?php endif; ?>
            </div>
            <div class="hv-rec">
              <span class="hv-lbl">Attendance<?= $prog && !empty($prog['live']) ? ' · ' . e((string) $prog['label']) : '' ?></span>
<?php if ($gateSum && $gateSum['counted']): ?>
              <span class="hv-num"><?= (int) $gateSum['rate'] ?>%</span>
              <span class="hv-small hv-muted hv-split"><span><b><?= (int) $gateSum['present'] ?></b> present</span><span><b class="<?= (int) $gateSum['late'] ? 'is-bad' : '' ?>"><?= (int) $gateSum['late'] ?></b> late</span><span><b><?= (int) $gateSum['excused'] ?></b> excused</span><span><b><?= (int) $gateSum['absent'] ?></b> absent</span></span>
<?php else: ?>
              <span class="hv-num">—</span><span class="hv-small hv-muted">Nothing counted yet</span>
<?php endif; ?>
            </div>
            <div class="hv-rec">
              <span class="hv-lbl">Mentorship</span>
              <span class="hv-num"><?= e($mentorHoursLabel) ?></span>
              <span class="hv-small hv-muted"><?= $hvMeetings ?> session<?= $hvMeetings === 1 ? '' : 's' ?><?= $mentorStats['rate'] !== null ? ' · <span class="hv-ok-t">' . (int) $mentorStats['rate'] . '% consistent</span>' : '' ?><?= $hvMentor ? ' · ' . e((string) $hvMentor['name']) : '' ?></span>
            </div>
            <div class="hv-rec hv-rec--end"><a href="#quest" data-goto="quest" class="hv-btn hv-btn--sm">Full record →</a></div>
          </section>
        </div>

        <p class="hv-motto-ft">Your life is becoming the evidence of your leadership.</p>
        <!-- Asking finance for money is its own page: Finance requests (views/requests.php). -->
        <p class="hv-small" style="margin-top:20px"><a href="#requests" data-goto="requests" class="hv-more">Ask finance for money →</a></p>

<?php
$pulse = [
    'name' => trim((string) $u['name']), 'initials' => $pInitials, 'photo' => $hvPhoto,
    'level' => $stageCode, 'level_label' => $hvLevelLabel, 'pct' => $hvLevelPct, 'next_label' => $journey['next'] !== null ? $jNextLabel : '',
    'week' => 'Week ' . (int) $hvNow->format('W') . ' · ' . $hvNow->format('M Y'),
    'books' => $hvBooksDone, 'books_total' => NgvReading::TOTAL, 'courses' => $hvCoursesDone,
    'meetings' => $hvMeetings, 'meet_rate' => $mentorStats['rate'] !== null ? (int) $mentorStats['rate'] : null,
    'mentees' => $hvMentees, 'invite' => (string) ($journey['invite_url'] ?? ''),
];
require dirname(__DIR__) . '/_pulse-card.php';
?>
      </section>
