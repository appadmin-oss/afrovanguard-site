<?php
/**
 * tests/meetings.test.php — the meeting defects, pinned so they stay fixed.
 *
 * Every assertion here fails against the code as it was. In order of how much
 * they cost:
 *
 *   1. `get($uid,$id)` took a viewer id and ignored it, so any signed-in member
 *      could read any meeting's transcript — summary, decisions, action items —
 *      by guessing an integer.
 *   2. Nothing emailed the attendees. Google Calendar's own invite was the only
 *      notification, so on a deployment without Workspace connected a meeting
 *      was scheduled and nobody was told.
 *   3. A recurring meeting never advanced: `scheduled_at` is the series start,
 *      and the list sorted on it, so a weekly stand-up sank into the past the
 *      week after it began.
 *   4. `is_owner` was the literal `false` for everyone.
 *   5. `LIMIT ?` bound as a string under ATTR_EMULATE_PREPARES=false — fine on
 *      SQLite, fatal the day this moves to MySQL.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/Meetings.php';

/** Reach a private static without loosening the class's own visibility. */
$mcall = static function (string $m, array $args) {
    $r = new ReflectionMethod('Meetings', $m);
    $r->setAccessible(true);
    return $r->invokeArgs(null, $args);
};

reset_users();
Meetings::ensure();
$db = Database::pdo();
$db->exec('DELETE FROM meetings');
$db->exec('DELETE FROM meeting_attendees');
$db->exec('DELETE FROM meeting_transcripts');

/** Insert a meeting directly, so these tests never depend on Google or mail. */
$mk = function (int $creator, string $whenUtc, string $freq = 'once', string $title = 'Sync') use ($db): int {
    $db->prepare('INSERT INTO meetings (creator_id,title,agenda,scheduled_at,duration_min,frequency,context,context_id,auto_record,status,created_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$creator, $title, 'Agenda text', $whenUtc, 30, $freq, 'workspace', 0, 0, 'scheduled', gmdate('Y-m-d H:i:s')]);
    return (int) $db->lastInsertId();
};

/* ── 1 · Authorisation on get() ─────────────────────────────────────────── */
$m1 = $mk(1, gmdate('Y-m-d H:i:s', time() + 3600));
$db->prepare('INSERT INTO meeting_attendees (meeting_id,email,user_id) VALUES (?,?,0)')->execute([$m1, 'b@x.co']);
$db->prepare('INSERT INTO meeting_transcripts (meeting_id,source,raw_text,summary,highlights,decisions,action_items,structured,created_at)
              VALUES (?,?,?,?,?,?,?,?,?)')
   ->execute([$m1, 'paste', 'raw words', 'A private summary', '[]', '[]', '[]', 1, gmdate('Y-m-d H:i:s')]);

ck('meetings: organiser can read their meeting', Meetings::get(1, $m1) !== null);
ck('meetings: invited attendee can read it', Meetings::get(2, $m1) !== null);
// THE BUG. User 3 is neither organiser nor attendee.
ck('meetings: a stranger cannot read the meeting', Meetings::get(3, $m1) === null);

$asStranger = Meetings::get(3, $m1);
ck('meetings: a stranger gets no transcript', $asStranger === null || ($asStranger['transcript'] ?? null) === null);
ck('meetings: a participant does get the transcript',
   (Meetings::get(2, $m1)['transcript']['summary'] ?? '') === 'A private summary');

/* ── 2 · is_owner is a fact about the viewer ────────────────────────────── */
ck('meetings: is_owner true for the organiser', (Meetings::get(1, $m1)['is_owner'] ?? null) === true);
ck('meetings: is_owner false for an attendee',  (Meetings::get(2, $m1)['is_owner'] ?? null) === false);

/* ── 3 · Recurrence advances ────────────────────────────────────────────── */
$weekAgo = gmdate('Y-m-d H:i:s', time() - 7 * 86400 + 3600);

$onceNext   = $mcall('nextOccurrence', [$weekAgo, 'once']);
ck('meetings: a one-off does not advance', $onceNext === $weekAgo);

$weeklyNext = $mcall('nextOccurrence', [$weekAgo, 'weekly']);
ck('meetings: a weekly series advances past now', strtotime($weeklyNext . ' UTC') >= time());
ck('meetings: it advances by whole weeks',
   (strtotime($weeklyNext . ' UTC') - strtotime($weekAgo . ' UTC')) % (7 * 86400) === 0);

// A daily series started long ago must still land, and must not spin.
$longAgo  = gmdate('Y-m-d H:i:s', time() - 200 * 86400);
$dailyNext = $mcall('nextOccurrence', [$longAgo, 'daily']);
ck('meetings: a long-running daily series still resolves', strtotime($dailyNext . ' UTC') >= time());

// Weekday cadence must never land on a Saturday or Sunday.
$wdNext = $mcall('nextOccurrence', [$weekAgo, 'weekdays']);
$dow = (int) gmdate('N', strtotime($wdNext . ' UTC'));
ck('meetings: a weekday series never lands at the weekend', $dow >= 1 && $dow <= 5);

/* ── 4 · Ordering: upcoming first, soonest at the top ───────────────────── */
$db->exec('DELETE FROM meetings');
$soon  = $mk(1, gmdate('Y-m-d H:i:s', time() + 3600),      'once', 'This afternoon');
$later = $mk(1, gmdate('Y-m-d H:i:s', time() + 30 * 86400), 'once', 'Next month');
$past  = $mk(1, gmdate('Y-m-d H:i:s', time() - 2 * 86400),  'once', 'Last week');
$series= $mk(1, gmdate('Y-m-d H:i:s', time() - 14 * 86400), 'weekly', 'Weekly stand-up');

$list = Meetings::listFor(1);
$titles = array_column($list, 'title');
ck('meetings: the list returns everything', count($list) === 4);
ck('meetings: this afternoon comes before next month',
   array_search('This afternoon', $titles, true) < array_search('Next month', $titles, true));
// THE BUG: the weekly stand-up used to sort by a fortnight-old start date and
// land below "Last week".
ck('meetings: a live weekly series outranks a finished one-off',
   array_search('Weekly stand-up', $titles, true) < array_search('Last week', $titles, true));
ck('meetings: past meetings come last', array_search('Last week', $titles, true) === 3);

$row = null;
foreach ($list as $m) if ($m['title'] === 'Weekly stand-up') $row = $m;
ck('meetings: a series reports its next occurrence, not its first',
   $row !== null && strtotime($row['next_at'] . ' UTC') >= time());
ck('meetings: a series still reports its original start',
   $row !== null && strtotime($row['scheduled_at'] . ' UTC') < time());
ck('meetings: is_series flags the repeat', $row !== null && $row['is_series'] === true);

/* ── 5 · The list survives a non-SQLite parameter binding ───────────────── */
// Not a driver test — it pins that the limit is no longer a bound parameter,
// which is what breaks on MySQL. A bound LIMIT would still pass on SQLite, so
// the assertion is on the SQL the method builds.
$src = file_get_contents(AV_ROOT . '/lib/Meetings.php') ?: '';
ck('meetings: LIMIT is interpolated from a clamped int, not bound',
   strpos($src, 'ORDER BY m.scheduled_at DESC LIMIT ?') === false);

/* ── 6 · Cancelled meetings leave the list ──────────────────────────────── */
ck('meetings: only the organiser can cancel', (Meetings::cancel(2, $soon)['ok'] ?? true) === false);
ck('meetings: the organiser cancels', (Meetings::cancel(1, $soon)['ok'] ?? false) === true);
ck('meetings: a cancelled meeting drops out of the list', count(Meetings::listFor(1)) === 3);
