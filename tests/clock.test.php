<?php
/**
 * tests/clock.test.php — in-meeting time management (report §10).
 *
 * §10's examples all count OUTSTANDING AGENDA ITEMS, not elapsed minutes:
 * "5 minutes remaining. Outstanding items: 2." A bare countdown is a clock;
 * that sentence is what changes what a chair does next. So most of what is
 * pinned here is the join between the agenda §7 produced and the clock.
 *
 * The rest is about not lying:
 *
 *   • a warning that missed its moment is dropped, not delivered late — cron
 *     ticks every few minutes and "5 minutes remaining" arriving after the end
 *     is worse than silence;
 *   • each mark fires once per occurrence, so a recurring meeting gets a fresh
 *     clock each week rather than inheriting last week's ticks;
 *   • a meeting running over is visible, including a recurring one, which is
 *     the case that quietly rolls to next week if nobody thinks about it.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

$db = Database::pdo();

$ckReset = static function () use ($db): void {
    reset_users();
    Meetings::ensure(); MeetingClock::ensure();
    foreach (['meetings', 'meeting_attendees', 'av_meeting_clock', 'user_notifications'] as $t) {
        try { $db->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
    AvRules::ensure(); AvRules::resetAll('test');
};
/** A meeting that started $agoMin ago and runs $dur minutes. */
$ckMeeting = static function (float $agoMin, int $dur, string $agenda = '', string $freq = 'once') use ($db): int {
    $db->prepare("INSERT INTO meetings (creator_id,title,agenda,scheduled_at,duration_min,frequency,context,context_id,status,created_at) VALUES (1,'Standup',?,?,?,?,'workspace',7,'scheduled',?)")
       ->execute([$agenda, gmdate('Y-m-d H:i:s', time() - (int) round($agoMin * 60)), $dur, $freq, gmdate('Y-m-d H:i:s')]);
    return (int) $db->lastInsertId();
};
$AGENDA = "1. Minutes of the previous meeting (5 min)\n2. STS shortlist (15 min)\n3. Venue budget (10 min)\n4. Any other business (5 min)";

/* ══ The warning points come from the rule ═════════════════════════════ */

$ckReset();
ck('marks: the default schedule is read from the rule', MeetingClock::marks(45) === [20, 10, 5]);
ck('marks: a mark longer than the meeting is dropped', MeetingClock::marks(12) === [10, 5]);
ck('marks: they come back largest first', MeetingClock::marks(60) === [20, 10, 5]);
AvRules::save(['meetings.warn_minutes' => '15,2'], 'test');
ck('marks: a changed rule takes effect', MeetingClock::marks(45) === [15, 2]);
AvRules::save(['meetings.warn_minutes' => '0,-4,10'], 'test');
ck('marks: zero and negative are dropped, not coerced', MeetingClock::marks(45) === [10]);
AvRules::resetAll('test');

/* ══ Where a meeting is ════════════════════════════════════════════════ */

$ckReset();
$m = $ckMeeting(28, 45, $AGENDA);           // 17 minutes left
$s = MeetingClock::state($m);
ck('state: it knows the meeting is running', $s['running'] === true);
ck('state: elapsed is right', $s['elapsed'] === 28);
ck('state: remaining is right', $s['remaining'] === 17);
ck('state: the agenda is parsed into items', count($s['items']) === 4);
ck('state: none are ticked yet', $s['outstanding'] === 4);
ck('state: the 20-minute mark is in force', $s['mark'] === 20);
ck('state: the message counts unresolved items', strpos($s['message'], '4 agenda items are unresolved') !== false);

$future = $ckMeeting(-30, 45, $AGENDA);      // starts in 30 minutes
$s = MeetingClock::state($future);
ck('state: a meeting not yet started is not running', $s['running'] === false);
ck('state: and reports how long until it does', $s['starts_in'] === 30);
ck('state: with no warning in force', $s['mark'] === null && $s['message'] === '');

$noAgenda = $ckMeeting(40, 45);
$s = MeetingClock::state($noAgenda);
ck('state: a meeting with no agenda still runs a clock', $s['running'] === true && $s['mark'] === 5);
ck('state: and says so rather than counting zero items',
   strpos($s['message'], 'No agenda was set') !== false);

/* ══ Ticking items off — §10 counts what is UNRESOLVED ═════════════════ */

$ckReset();
$m = $ckMeeting(28, 45, $AGENDA);
ck('tick: item 0 can be resolved', !empty(MeetingClock::setResolved(1, $m, 0, true)['ok']));
ck('tick: item 1 too', !empty(MeetingClock::setResolved(1, $m, 1, true)['ok']));
$s = MeetingClock::state($m);
// The first agenda item is index 0, and "0" is falsy in PHP — a set containing
// only it must survive the round trip.
ck('tick: BOTH stick — index 0 is not lost', $s['items'][0]['done'] === true && $s['items'][1]['done'] === true);
ck('tick: the outstanding count drops', $s['outstanding'] === 2);
ck('tick: and the message follows it', strpos($s['message'], '2 agenda items are unresolved') !== false);

$ckReset();
$m = $ckMeeting(28, 45, $AGENDA);
MeetingClock::setResolved(1, $m, 0, true);
$s = MeetingClock::state($m);
ck('tick: resolving only the FIRST item survives a reload', $s['items'][0]['done'] === true);
ck('tick: and is counted', $s['outstanding'] === 3);

ck('tick: it can be put back', !empty(MeetingClock::setResolved(1, $m, 0, false)['ok']));
ck('tick: and the count returns', MeetingClock::state($m)['outstanding'] === 4);
ck('tick: an item that does not exist is refused', empty(MeetingClock::setResolved(1, $m, 99, true)['ok']));
ck('tick: a stranger cannot tick anything', empty(MeetingClock::setResolved(3, $m, 0, true)['ok']));

// Any participant may tick — the count is only useful if it is true, and it
// will not be if one person's cursor is the only thing that can change it.
$db->prepare('INSERT INTO meeting_attendees (meeting_id,email,user_id) VALUES (?,?,0)')->execute([$m, 'b@x.co']);
ck('tick: a participant who is not the chair may tick', !empty(MeetingClock::setResolved(2, $m, 1, true)['ok']));

/* ══ The one-sentence wording §10 asks for ═════════════════════════════ */

$ckReset();
$m = $ckMeeting(41, 45, $AGENDA);            // 4 minutes left
MeetingClock::setResolved(1, $m, 0, true);
MeetingClock::setResolved(1, $m, 1, true);
$s = MeetingClock::state($m);
ck('wording: under five minutes it suggests a next step',
   strpos($s['message'], 'Consider assigning follow-up actions or extending') !== false);
ck('wording: and names the outstanding count', strpos($s['message'], '2 agenda items are unresolved') !== false);
ck('wording: one item reads as singular',
   strpos(MeetingClock::wording(['running' => true, 'mark' => 5, 'remaining' => 3, 'outstanding' => 1, 'items' => [1]]), '1 agenda item is unresolved') !== false);

/* ══ Overrun — including on a recurring meeting ════════════════════════ */

$ckReset();
$over = $ckMeeting(52, 45, $AGENDA);         // ended 7 minutes ago
$s = MeetingClock::state($over);
ck('overrun: a one-off past its end is flagged', $s['overrun'] === true);
ck('overrun: remaining goes negative', $s['remaining'] === -7);
ck('overrun: the message says how far past', strpos($s['message'], '7 minutes past its scheduled end') !== false);
ck('overrun: and suggests closing out', strpos($s['message'], 'rather than continuing') !== false);

// The case that hides itself: a WEEKLY meeting seven minutes over must not roll
// to next week's occurrence and report "starts in 10,028 minutes".
$ckReset();
$weekly = $ckMeeting(52, 45, $AGENDA, 'weekly');
$s = MeetingClock::state($weekly);
ck('overrun: a recurring meeting running over is still flagged', $s['overrun'] === true);
ck('overrun: not reported as next week', $s['starts_in'] === null);

// Well past the grace window, it correctly becomes next week's meeting.
$ckReset();
$nextWeek = $ckMeeting(60 * 24, 45, $AGENDA, 'weekly');
$s = MeetingClock::state($nextWeek);
ck('occurrence: a day later it is next week\'s meeting', $s['overrun'] === false && $s['starts_in'] > 0);

/* ══ The sweep ═════════════════════════════════════════════════════════ */

$ckReset();
$m = $ckMeeting(35.2, 45, $AGENDA);          // ~9.8 min left → the 10 mark just landed
$db->prepare('INSERT INTO meeting_attendees (meeting_id,email,user_id) VALUES (?,?,0)')->execute([$m, 'b@x.co']);
$s1 = MeetingClock::sweep();
ck('sweep: it finds the running meeting', $s1['meetings'] === 1);
ck('sweep: and warns', $s1['warned'] >= 1);
ck('sweep: everyone in the meeting is told',
   (int) $db->query("SELECT COUNT(DISTINCT user_id) FROM user_notifications WHERE kind='meeting'")->fetchColumn() === 2);
$s2 = MeetingClock::sweep();
ck('sweep: the same mark never fires twice', $s2['warned'] === 0);
ck('sweep: and no duplicate notification is written',
   (int) $db->query("SELECT COUNT(*) FROM user_notifications WHERE kind='meeting'")->fetchColumn() === 2);

// A mark whose moment has passed is recorded as fired and skipped — a late
// warning is worse than none.
$ckReset();
$late = $ckMeeting(30, 45, $AGENDA);         // 15 left: the 20 mark is 5 minutes gone
$s = MeetingClock::sweep();
ck('sweep: a mark past its window is counted stale', $s['stale'] >= 1);
ck('sweep: and is NOT delivered late',
   (int) $db->query("SELECT COUNT(*) FROM user_notifications WHERE kind='meeting'")->fetchColumn() === 0);

// A meeting that has not started, or is cancelled, has no clock to run.
$ckReset();
$ckMeeting(-60, 45, $AGENDA);
ck('sweep: a meeting that has not started is skipped', MeetingClock::sweep()['meetings'] === 0);
$ckReset();
$c = $ckMeeting(20, 45, $AGENDA);
$db->prepare("UPDATE meetings SET status='cancelled' WHERE id=?")->execute([$c]);
ck('sweep: a cancelled meeting is skipped', MeetingClock::sweep()['meetings'] === 0);
ck('state: and has no clock', empty(MeetingClock::state($c)['ok']));

// The master switch.
$ckReset();
$ckMeeting(35.2, 45, $AGENDA);
AvRules::save(['ai.enabled' => '0'], 'test');
ck('switch: ai.enabled off stops the sweep', !empty(MeetingClock::sweep()['off']));
ck('switch: and nothing is sent',
   (int) $db->query("SELECT COUNT(*) FROM user_notifications WHERE kind='meeting'")->fetchColumn() === 0);
AvRules::resetAll('test');

/* ══ Each occurrence gets its own clock ════════════════════════════════ */

$ckReset();
$m = $ckMeeting(28, 45, $AGENDA, 'weekly');
$thisWeek = MeetingClock::state($m)['occurrence'];
MeetingClock::setResolved(1, $m, 0, true);
ck('occurrence: this week has a tick', MeetingClock::state($m)['outstanding'] === 3);

// Moving the series back exactly a week lands on the SAME current occurrence —
// that is what recurrence means, and the tick must survive it.
$db->prepare('UPDATE meetings SET scheduled_at = ? WHERE id = ?')
   ->execute([gmdate('Y-m-d H:i:s', time() - 28 * 60 - 7 * 86400), $m]);
ck('occurrence: a whole period back is still the same occurrence', MeetingClock::state($m)['occurrence'] === $thisWeek);
ck('occurrence: so the tick survives', MeetingClock::state($m)['outstanding'] === 3);

// Shift it so a genuinely DIFFERENT occurrence is current. That one starts
// clean — last week's ticks are not this week's.
$db->prepare('UPDATE meetings SET scheduled_at = ? WHERE id = ?')
   ->execute([gmdate('Y-m-d H:i:s', time() - 28 * 60 - 3 * 3600 - 7 * 86400), $m]);
$other = MeetingClock::state($m);
ck('occurrence: a different occurrence has a different key', $other['occurrence'] !== $thisWeek);
ck('occurrence: and starts with nothing ticked', $other['outstanding'] === 4);

// And the first occurrence's state was not destroyed — it is keyed, not shared.
$db->prepare('UPDATE meetings SET scheduled_at = ? WHERE id = ?')
   ->execute([gmdate('Y-m-d H:i:s', time() - 28 * 60), $m]);
ck('occurrence: going back, the original tick is still there', MeetingClock::state($m)['outstanding'] === 3);

/* ══ The surface ═══════════════════════════════════════════════════════ */

$portal = (string) file_get_contents(AV_ROOT . '/portal/meetings.php');
foreach (['clock_state', 'clock_tick'] as $act) {
    ck("portal: {$act} is implemented", strpos($portal, "case '{$act}'") !== false);
}
$cron = (string) file_get_contents(AV_ROOT . '/tasks/cron.php');
ck('cron: the clock sweep is wired in', strpos($cron, 'MeetingClock::sweep()') !== false);

// The seam is documented and genuinely empty — no unverifiable third-party call
// was written from memory.
$src = (string) file_get_contents(AV_ROOT . '/lib/MeetingClock.php');
ck('seam: in-meeting chat is not silently faked',
   strpos($src, 'send_chat_message') === false && strpos($src, 'AttendeeBot::') === false && strpos($src, 'RecallBot::') === false);
ck('seam: and the reason is written down', strpos($src, 'NOT implemented') !== false);

$ckReset();
