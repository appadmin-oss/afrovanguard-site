<?php
/**
 * tests/taskflow.test.php — tasks people finish (lib/Collab.php, lib/TaskLedger.php,
 * lib/TaskReport.php, lib/Notifications.php, lib/Commitments.php), after
 * CACENTRE's Goalspace: one owner, a date, proof, accept-or-decline with a
 * reason, escalation up the line.
 *
 *   A  a task can say what "done" looks like (outcome)
 *   B  the first agreed date is kept; an assignee moving it gives a reason,
 *      the move is logged and the giver told; on time is against the agreement
 *   C  given work waits to be accepted: accept · propose a date · can't, because…
 *      AI steps arrive proposed, not assigned
 *   D  finishing work somebody gave you tells them, with Thanks / Send back
 *   E  one escalation ladder for tasks and meeting commitments, and the daily
 *      report to whoever gave out the late work
 *   F, G  the portal's summary and a tick that survives a bad connection
 *      (portal/tasks.js, executed in node when it is installed)
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$fp = Database::pdo();
Collab::myTasks(0, 1);
TaskLedger::ensure();
TaskMatch::ensure();
Notifications::ensure();
Commitments::ensure();
$fp->exec('DELETE FROM collab_tasks');
$fp->exec('DELETE FROM task_assign_log WHERE user_id BETWEEN 9960 AND 9969');
$fp->exec('DELETE FROM user_notifications WHERE user_id BETWEEN 9960 AND 9969');
$fp->exec('DELETE FROM lms_users WHERE id BETWEEN 9960 AND 9969');
foreach ([9961 => ['Gina Giver', 'member'], 9962 => ['Olu Owner', 'member'], 9963 => ['Remi Other', 'member'], 9964 => ['Kola Coord', 'coordinator'], 9965 => ['Chair Chi', 'member']] as $id => [$n, $role]) {
    $fp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([$id, $n, 'tf' . $id . '@afrovanguard.org.ng', 'x', $role, 'active']);
}
$notes = static fn(int $uid, string $like) => (int) $fp->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = $uid AND (title LIKE " . $fp->quote($like) . ' OR body LIKE ' . $fp->quote($like) . ')')->fetchColumn();
$row = static function (int $id) use ($fp): array { $st = $fp->prepare('SELECT * FROM collab_tasks WHERE id = ?'); $st->execute([$id]); return $st->fetch(PDO::FETCH_ASSOC) ?: []; };
$day = static fn(int $off) => gmdate('Y-m-d', strtotime(TaskReport::today() . ' 12:00 UTC') + $off * 86400);

/* ── A: what done looks like ─────────────────────────────────────────── */
$a = Collab::addTask(9961, 'Print the flyers', 9962, $day(5), mayAllocate: true, outcome: '200 flyers at the hall door');
$ta = Collab::oneTask(9962, $a);
ck('A: a task keeps its "done when" line and shows it', ($ta['outcome'] ?? '') === '200 flyers at the hall door');
ck('A: the line is optional', Collab::addTask(9962, 'Water the plants') > 0);
$v = (string) file_get_contents(AV_ROOT . '/portal/views/tasks.php'); $js = (string) file_get_contents(AV_ROOT . '/portal/tasks.js');
ck('A: the add form offers it, and asks for it when the task goes to someone else or is sized L', str_contains($v, 'id="taskOutcome"') && str_contains($js, 'askOutcome'));

/* ── B: the agreed date ──────────────────────────────────────────────── */
$r = $row($a);
ck('B: the first agreed date is kept', $r['first_due'] === $day(5) && $r['agreed_due'] === $day(5));
ck('B: the assignee moving the date without a reason is refused, and told why', Collab::updateTask(9962, $a, ['due' => $day(9)]) === null && str_contains(Collab::$lastError, 'reason') && $row($a)['due'] === $day(5));
$fp->exec('DELETE FROM user_notifications WHERE user_id = 9961');
$m = Collab::updateTask(9962, $a, ['due' => $day(9), 'reason' => 'The printer is down until Friday']);
ck('B: with a reason it moves', is_array($m) && $m['due'] === $day(9));
ck('B: but the agreed date stays where it was agreed', $row($a)['agreed_due'] === $day(5) && $row($a)['first_due'] === $day(5));
$log = TaskLedger::dueChanges($a);
ck('B: the move is logged — who, when, from, to, why', count($log) === 1 && $log[0]['by_id'] === 9962 && $log[0]['from'] === $day(5) && $log[0]['to'] === $day(9) && $log[0]['why'] === 'The printer is down until Friday' && $log[0]['at'] !== '');
ck('B: and the person who gave the task is told, with the reason', $notes(9961, '%printer is down%') === 1);
/* on time is against the agreement: finished on day 7, after the agreed 5, before the moved 9 */
Collab::acceptTask(9962, $a);
Collab::completeTask(9962, $a);
$fp->prepare('UPDATE collab_tasks SET done_at = ? WHERE id = ?')->execute([$day(7) . ' 10:00:00', $a]);
$h = TaskLedger::history([9962], strtotime($day(8) . ' 12:00 UTC'));
ck('B: finished after the agreed date is late, however far it was moved (TaskLedger, TaskMatch)', $h[9962]['late'] === 1 && $h[9962]['on_time'] === 0);
$rep = TaskReport::build($day(7), strtotime($day(7) . ' 18:00 UTC'));
ck('B: and the report says late too', ($x = array_values(array_filter($rep['done'], fn($t) => $t['id'] === $a))[0] ?? null) && $x['late'] === true);
/* The giver resetting the date is a new agreement. */
$b = Collab::addTask(9961, 'Book the hall', 9962, $day(3), mayAllocate: true);
$fp->exec('DELETE FROM user_notifications WHERE user_id = 9962');
ck('B: the giver may set a new date without a reason', is_array(Collab::updateTask(9961, $b, ['due' => $day(6)])));
ck('B: and that is the new agreement, and the owner is told', $row($b)['agreed_due'] === $day(6) && $notes(9962, '%' . $day(6) . '%') >= 1);
$s = Collab::addTask(9962, 'My own errand', 0, $day(2));
ck('B: your own task moves without a reason', is_array(Collab::updateTask(9962, $s, ['due' => $day(4)])));

/* ── C: accept, propose a date, or can't because… ────────────────────── */
$c = Collab::addTask(9961, 'Write the newsletter', 9962, $day(4), mayAllocate: true);
$tc = Collab::oneTask(9962, $c);
ck('C: a task given to someone arrives waiting for them, not as theirs yet', $tc['accept_state'] === 'pending' && $tc['waiting'] === true && $tc['mine'] === false);
ck('C: so "my tasks" does not count it until it is accepted', !in_array($c, array_column(array_filter(Collab::myTasks(9962), fn($t) => $t['mine'] && !$t['done']), 'id'), true));
ck('C: and the giver is not shown it as theirs', Collab::oneTask(9961, $c)['mine'] === false && Collab::oneTask(9961, $c)['i_gave'] === true);
$fp->prepare('UPDATE collab_tasks SET assigned_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 5 * 3600), $c]);
ck('C: only the person asked may accept', Collab::acceptTask(9963, $c) === null);
$ac = Collab::acceptTask(9962, $c);
ck('C: accepted, it is theirs', is_array($ac) && $ac['mine'] === true && $ac['waiting'] === false && $row($c)['accepted_at'] !== '');
ck('C: the time from given to accepted is kept', ($hh = TaskLedger::history([9962])[9962]['accept_hours']) !== null && $hh >= 2.4 && $hh <= 5.1);
ck('C: tasks you make for yourself skip the step', Collab::oneTask(9962, $s)['accept_state'] === '' && Collab::oneTask(9962, $s)['mine'] === true);
$p = Collab::addTask(9961, 'Call the sponsor', 9962, $day(2), mayAllocate: true);
$fp->exec('DELETE FROM user_notifications WHERE user_id = 9961');
ck('C: proposing a date needs a date', Collab::proposeDate(9962, $p, 'soon') === null);
$pp = Collab::proposeDate(9962, $p, $day(6), 'After the board meets');
ck('C: proposing a date asks the giver to agree', is_array($pp) && $row($p)['accept_state'] === 'counter' && $row($p)['proposed_due'] === $day(6) && $notes(9961, '%' . $day(6) . '%') === 1);
ck('C: the giver sees it as waiting on them', Collab::oneTask(9961, $p)['needs_me'] === 'date');
ck('C: only the giver answers it', Collab::answerDate(9962, $p, true) === null);
Collab::answerDate(9961, $p, true);
ck('C: agreed, the new date is the agreed date and the task is accepted', $row($p)['due'] === $day(6) && $row($p)['agreed_due'] === $day(6) && $row($p)['accept_state'] === '' && $row($p)['accepted_at'] !== '');
$k = Collab::addTask(9961, 'Count the chairs', 9962, $day(2), mayAllocate: true);
Collab::proposeDate(9962, $k, $day(8), '');
Collab::answerDate(9961, $k, false);
ck('C: the giver keeping their date sends it back to wait for an answer', $row($k)['due'] === $day(2) && $row($k)['accept_state'] === 'pending' && $row($k)['proposed_due'] === '');
ck('C: the reasons are one list, shared with handing back', array_keys(TaskLedger::REASONS) === ['busy', 'unclear', 'blocked', 'not_mine', 'deprioritised', 'other']);
ck('C: a reason not on the list is refused', Collab::declineTask(9962, $k, 'lazy') === null);
$fp->exec('DELETE FROM user_notifications WHERE user_id = 9961');
ck("C: can't, because… sends it back to the giver", Collab::declineTask(9962, $k, 'blocked', 'Waiting on the keys') !== null
   && (int) $row($k)['assignee_id'] === 9961 && $row($k)['accept_state'] === '');
ck('C: and tells them why', $notes(9961, '%Waiting on the keys%') === 1);
ck('C: and the ledger keeps the reason, as the matcher reads a hand-back', (string) $fp->query("SELECT reason FROM task_assign_log WHERE task_id = $k AND event = 'declined'")->fetchColumn() === 'blocked'
   && in_array('blocked', array_column(TaskLedger::history([9962])[9962]['handed_back'], 'reason'), true));
/* AI steps arrive proposed. */
$ai = Collab::addTask(9961, 'Draft the grant outline', 9963, $day(10), via: 'ai', mayAllocate: true);
ck('C: an AI-made step arrives proposed, not assigned', $row($ai)['accept_state'] === 'proposed' && Collab::oneTask(9963, $ai)['mine'] === false && Collab::oneTask(9963, $ai)['waiting'] === true);
ck('C: and its notice says it is a suggestion', $notes(9963, 'Suggested for you%') === 1);
Collab::declineTask(9963, $ai, 'not_mine');
ck('C: a proposed step declined goes back to the pool for someone else', (int) $row($ai)['assignee_id'] === 0 && $row($ai)['accept_state'] === '');
$src = (string) file_get_contents(AV_ROOT . '/lib/GoalPlanner.php') . (string) file_get_contents(AV_ROOT . '/lib/Collab.php');
ck('C: the planner gives its steps through the AI door', str_contains($src, "\$goalId, \$size, 'ai', true"));
ck('C: ticking a task done that was waiting accepts it on the way', ($w = Collab::addTask(9961, 'Sweep the stage', 9962, mayAllocate: true)) && Collab::completeTask(9962, $w) && $row($w)['accept_state'] === '' && $row($w)['accepted_at'] !== '');

/* ── D: done is told ─────────────────────────────────────────────────── */
$d = Collab::addTask(9961, 'Send the minutes', 9962, $day(1), mayAllocate: true);
Collab::acceptTask(9962, $d);
$fp->exec('DELETE FROM user_notifications WHERE user_id IN (9961, 9962)');
Collab::completeTask(9962, $d);
ck('D: finishing work somebody gave you tells them', $notes(9961, 'Done: Send the minutes%') === 1);
ck('D: once', Collab::completeTask(9962, $d) && $notes(9961, 'Done: Send the minutes%') === 1);
ck('D: and it waits on them for Thanks or Send back', Collab::oneTask(9961, $d)['needs_me'] === 'review');
ck('D: your own task tells nobody', ($o = Collab::addTask(9962, 'Tidy my desk')) && Collab::completeTask(9962, $o) && $notes(9962, 'Done: Tidy my desk%') === 0);
ck('D: only the giver thanks', Collab::thankTask(9963, $d) === null);
Collab::thankTask(9961, $d);
ck('D: thanks reaches the person who did it', $notes(9962, '%thanks%') >= 1 && $row($d)['review_state'] === 'thanked');
$e = Collab::addTask(9961, 'Fix the projector', 9962, mayAllocate: true);
Collab::completeTask(9962, $e);
ck('D: sending back needs a reason', Collab::sendBack(9961, $e, '  ') === null && (int) $row($e)['done'] === 1);
$fp->exec('DELETE FROM user_notifications WHERE user_id = 9962');
Collab::sendBack(9961, $e, 'The HDMI port still fails');
ck('D: sent back, it is open again and they are told why', (int) $row($e)['done'] === 0 && $row($e)['review_state'] === 'sent_back' && $notes(9962, '%HDMI port%') === 1);

/* ── E: the escalation ladder ────────────────────────────────────────── */
ck('E: the ladder, step by step', TaskLedger::ladder('2026-11-18', '2026-11-18') === 'due' && TaskLedger::ladder('2026-11-18', '2026-11-19') === 'day1'
   && TaskLedger::ladder('2026-11-18', '2026-11-20') === '' && TaskLedger::ladder('2026-11-18', '2026-11-21') === 'escalate'
   && TaskLedger::ladder('2026-11-18', '2026-11-22') === '' && TaskLedger::ladder('2026-11-18', '2026-11-23') === 'weekly'
   && TaskLedger::ladder('2026-11-18', '2026-11-24') === '' && TaskLedger::ladder('2026-11-18', '2026-11-17') === ''
   && TaskLedger::ladder('2026-11-13', '2026-11-16') === 'escalate');
$fp->exec('DELETE FROM collab_tasks'); $fp->exec('DELETE FROM user_notifications WHERE user_id BETWEEN 9960 AND 9969');
$lag = static fn(string $ymd) => (new DateTimeImmutable($ymd . ' 09:00', new DateTimeZone('Africa/Lagos')))->getTimestamp();
$L = Collab::addTask(9961, 'Chase the venue', 9962, '2026-11-18', mayAllocate: true);
Collab::acceptTask(9962, $L);
$fp->exec('DELETE FROM user_notifications WHERE user_id BETWEEN 9960 AND 9969');
TaskLedger::chaseAll($lag('2026-11-17'));
ck('E: nothing before the due day', $notes(9962, '%Chase the venue%') === 0);
TaskLedger::chaseAll($lag('2026-11-18')); TaskLedger::chaseAll($lag('2026-11-18'));
ck('E: the owner is reminded on the due day, once', $notes(9962, 'Due today: Chase the venue%') === 1);
TaskLedger::chaseAll($lag('2026-11-19')); TaskLedger::chaseAll($lag('2026-11-19'));
ck('E: and the day after, once', $notes(9962, 'Overdue: Chase the venue%') === 1);
TaskLedger::chaseAll($lag('2026-11-20'));
ck('E: not on day two', $notes(9962, '%Chase the venue%') === 2 && $notes(9961, '%Chase the venue%') === 0);
TaskLedger::chaseAll($lag('2026-11-21')); TaskLedger::chaseAll($lag('2026-11-21'));
ck('E: day three overdue tells the giver, once, with what they can do', $notes(9961, '3 days late: Chase the venue%') === 1 && $notes(9961, '%Reassign it, set a new date, or drop it%') === 1);
ck('E: the owner is not nagged on day three', $notes(9962, '%Chase the venue%') === 2);
TaskLedger::chaseAll($lag('2026-11-22'));
ck('E: no daily nagging after that', $notes(9962, '%Chase the venue%') === 2 && $notes(9961, '%Chase the venue%') === 1);
TaskLedger::chaseAll($lag('2026-11-23')); TaskLedger::chaseAll($lag('2026-11-23'));
ck('E: one weekly roll-up (a Monday), to the owner and the giver, once', $notes(9962, 'Still overdue%') === 1 && $notes(9961, 'Still overdue%') === 1);
TaskLedger::chaseAll($lag('2026-11-24')); TaskLedger::chaseAll($lag('2026-11-26'));
ck('E: and nothing between roll-ups', $notes(9962, 'Still overdue%') === 1 && $notes(9962, '%Chase the venue%') === 3);   /* two reminders, and the roll-up naming it */
/* The old daily notice is gone: a long-overdue task on an ordinary day sends nothing. */
$fp->exec('DELETE FROM user_notifications WHERE user_id BETWEEN 9960 AND 9969');
$old = Collab::addTask(9961, 'Ancient chore', 9962, $day(-10), mayAllocate: true);
$fp->exec('DELETE FROM user_notifications WHERE user_id BETWEEN 9960 AND 9969');
$wd = (int) (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->format('N');
Notifications::dispatchDue();
ck('E: the indefinite daily "Overdue task" notice is gone', $notes(9962, 'Overdue task:%') === 0 && ($wd === 1 || $notes(9962, '%Ancient chore%') === 0));
$fp->prepare('DELETE FROM collab_tasks WHERE id = ?')->execute([$old]);
/* Meeting commitments climb the same ladder, to the meeting's chair. */
$fp->exec('DELETE FROM commitments');
Meetings::ensure();
$fp->exec('DELETE FROM meetings WHERE id = 7701');
$fp->prepare('INSERT INTO meetings (id, title, creator_id) VALUES (?,?,?)')->execute([7701, 'Board', 9965]);
Commitments::fileMany(Commitments::SRC_MEETING, 7701, [['task' => 'Send the audited accounts', 'owner' => '', 'due_days' => 1]]);
$cm = (int) Commitments::forSource(Commitments::SRC_MEETING, 7701)[0]['id'];
Commitments::assign($cm, 9962, 9965);
$fp->prepare('UPDATE commitments SET due = ? WHERE id = ?')->execute(['2026-11-18', $cm]);
$fp->exec('DELETE FROM user_notifications WHERE user_id BETWEEN 9960 AND 9969');
foreach (['2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-21', '2026-11-22'] as $dd) TaskLedger::chaseAll($lag($dd));
ck('E: a commitment reminds its owner on the day and the day after', $notes(9962, '%Send the audited accounts%') === 2);
ck('E: and on day three tells the meeting’s chair, once', $notes(9965, '3 days late: Send the audited accounts%') === 1);
$csrc = (string) file_get_contents(AV_ROOT . '/lib/Commitments.php');
ck('E: the daily commitment nudge is gone', !str_contains($csrc, "'commitment_overdue:' . (int) \$c['id'] . ':' . gmdate('Y-m-d')"));
ck('E: the chair can set a new date (reassign and drop were there)', method_exists('Commitments', 'setDue') && str_contains((string) file_get_contents(AV_ROOT . '/portal/commitments.php'), "case 'redate':"));
$fp->exec('DELETE FROM commitments'); $fp->exec('DELETE FROM meetings WHERE id = 7701');

/* The daily report goes to whoever gave out the late work. */
$fp->exec('DELETE FROM collab_tasks'); $fp->exec('DELETE FROM user_notifications WHERE user_id BETWEEN 9960 AND 9969');
$today = TaskReport::today();
$late = Collab::addTask(9961, 'Late from Gina', 9962, gmdate('Y-m-d', strtotime($today . ' 12:00 UTC') - 2 * 86400), mayAllocate: true);
Collab::addTask(9964, 'On time from Kola', 9963, gmdate('Y-m-d', strtotime($today . ' 12:00 UTC') + 2 * 86400), mayAllocate: true);
$was = [AvRules::bool('tasks.daily_report'), AvRules::int('tasks.daily_report_hour')];
AvRules::save(['tasks.daily_report' => '1', 'tasks.daily_report_hour' => '18'], 'test');
Database::metaSet('task_report_day', '');
$sent = TaskReport::sendIfDue((new DateTimeImmutable($today . ' 19:00', new DateTimeZone('Africa/Lagos')))->getTimestamp());
ck('E: the report goes to the person who gave out the late work', $notes(9961, 'Tasks today:%') === 1);
ck('E: not to a coordinator who gave out none', $notes(9964, 'Tasks today:%') === 0);
ck('E: and lists the late work they gave', is_array($sent) && ($sent['sent'] ?? 0) === 1);
AvRules::save(['tasks.daily_report' => $was[0] ? '1' : '0', 'tasks.daily_report_hour' => (string) $was[1]], 'test');
Database::metaSet('task_report_day', '');

/* ── F, G: the page (executed in node when it is installed) ──────────── */
ck('F: My tasks comes first, after the "Everything you owe" summary', ($i1 = strpos($v, 'id="owe"')) !== false && ($i2 = strpos($v, 'id="tasks"')) !== false && $i1 < $i2
   && $i2 < (int) strpos($v, 'id="commitments"') && $i2 < (int) strpos($v, 'id="taskPool"'));
ck('F: the summary reuses what the page loads — no new endpoint', !str_contains($js, "action=owe") && str_contains((string) file_get_contents(AV_ROOT . '/portal/commitments.js'), "'av:owe'") && str_contains((string) file_get_contents(AV_ROOT . '/portal/goalspace.js'), "'av:owe'"));
$node = trim((string) @shell_exec('command -v node 2>/dev/null'));
if ($node === '') { ck('F/G: node not installed — executed checks skipped', true); }
else {
    $runner = sys_get_temp_dir() . '/av-taskkit-' . getmypid() . '.js';
    file_put_contents($runner, <<<'JS'
global.window = global; global.document = { getElementById: function(){ return null; }, addEventListener: function(){} };
require(process.argv[2]);
var K = global.AvTaskKit, out = {};
var g = K.oweGroups([
  {title:'late', due:'2026-11-16'}, {title:'today', due:'2026-11-18'}, {title:'week', due:'2026-11-22'},
  {title:'later', due:'2026-12-30'}, {title:'nodate', due:''}, {title:'wait', due:'2026-11-16', waiting:true}], '2026-11-18');
out.groups = g.map(function(x){ return [x.key, x.items.map(function(i){ return i.title; })]; });
var store = {}, storage = { getItem:function(k){ return store[k]||null; }, setItem:function(k,v){ store[k]=v; }, removeItem:function(k){ delete store[k]; } };
var said = [], fail = true;
var q = K.tickQueue(storage, function(id){ return fail ? Promise.reject(new Error('offline')) : Promise.resolve({ok:true, id:id.id, done:id.done}); }, function(m){ said.push(m); });
q.tick(41, true).then(function(r1){
  out.kept = JSON.parse(store['av.task.ticks']||'[]'); out.r1 = r1; out.said1 = said.slice();
  fail = false; return q.flush();
}).then(function(){ out.after = JSON.parse(store['av.task.ticks']||'[]'); out.said2 = said.slice(); console.log(JSON.stringify(out)); });
JS);
    $o = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg(AV_ROOT . '/portal/tasks.js') . ' 2>&1'), true);
    @unlink($runner);
    ck('F: the summary groups Overdue / Today / This week / Waiting on you', is_array($o) && $o['groups'] === [['over', ['late']], ['today', ['today']], ['week', ['week']], ['wait', ['wait']]]);
    ck('G: a tick on a bad connection is kept, not lost, and the person is told', is_array($o) && $o['kept'] === [['id' => 41, 'done' => true]] && ($o['r1']['queued'] ?? false) === true && (bool) array_filter($o['said1'], fn($s) => str_contains($s, 'send it')));
    ck('G: and sent when the connection is back', is_array($o) && $o['after'] === [] && (bool) array_filter($o['said2'], fn($s) => str_contains($s, 'sent')));
}

$fp->exec('DELETE FROM collab_tasks');
$fp->exec('DELETE FROM task_assign_log WHERE user_id BETWEEN 9960 AND 9969');
$fp->exec('DELETE FROM user_notifications WHERE user_id BETWEEN 9960 AND 9969');
$fp->exec('DELETE FROM lms_users WHERE id BETWEEN 9960 AND 9969');
