<?php
/**
 * tests/suite.test.php — data-layer tests for the portal Suite + notifications.
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

foreach (['Boards','Polls','Goals','Reminders','Bookmarks','TeamCalendar','Notifications'] as $c) {
    require_once AV_ROOT . "/lib/$c.php";
}

/* ---- Boards: ownership guards ---- */
reset_users();
$c1 = Boards::add(1, 'A card', 'todo');
ck('boards: add returns id', $c1 > 0);
ck('boards: reject empty title', Boards::add(1, '') === 0);
ck('boards: non-author cannot rename', !Boards::rename(2, $c1, 'hax'));
ck('boards: non-author cannot delete', !Boards::remove(2, $c1));
ck('boards: author renames', Boards::rename(1, $c1, 'A edited'));
ck('boards: move to done', Boards::move($c1, 'done'));
$bd = Boards::board(2); $mineForB = true;
foreach ($bd as $col) foreach ($col['cards'] as $cd) if ($cd['mine'] !== false) $mineForB = false;
ck('boards: mine=false for other viewer', $mineForB);
ck('boards: author deletes', Boards::remove(1, $c1));

/* ---- Polls: vote/tally/close ---- */
reset_users();
$p = Polls::create(1, 'Best day?', ['Mon', 'Wed', 'Fri']);
ck('polls: create returns id', $p > 0);
ck('polls: reject <2 options', Polls::create(1, 'x', ['one']) === 0);
Polls::vote(1, $p, 2); Polls::vote(2, $p, 2); Polls::vote(3, $p, 0);
$pd = Polls::listPolls(1, 10)[0];
ck('polls: total 3', $pd['total'] === 3);
ck('polls: Fri 67%', $pd['options'][2]['pct'] === 67);
Polls::vote(1, $p, 0);                       // change vote
ck('polls: change keeps one-per-user', Polls::listPolls(1, 10)[0]['total'] === 3);
ck('polls: non-author cannot close', !Polls::close(2, $p));
ck('polls: author closes', Polls::close(1, $p));
ck('polls: no vote on closed', !Polls::vote(2, $p, 1));

/* ---- Goals: progress clamp + auto-close ---- */
reset_users();
$g = Goals::create(1, 'Reach 500', '500');
ck('goals: create', $g > 0);
ck('goals: non-author cannot set', !Goals::setProgress(2, $g, 50));
Goals::setProgress(1, $g, 150);
$gd = Goals::listGoals(1, 10)[0];
ck('goals: clamp to 100', $gd['progress'] === 100);
ck('goals: auto-close at 100', $gd['closed'] === true);

/* ---- Reminders: due/overdue/toggle ---- */
reset_users();
$r1 = Reminders::add(1, 'Past', '2000-01-01T09:00');
$r2 = Reminders::add(1, 'Undated');
ck('reminders: add', $r1 > 0 && $r2 > 0);
$rl = Reminders::listFor(1, 50);
$past = array_values(array_filter($rl, fn($x) => $x['text'] === 'Past'))[0];
ck('reminders: overdue flagged', $past['overdue'] === true);
ck('reminders: other user sees none', count(Reminders::listFor(2, 50)) === 0);
ck('reminders: toggle done', Reminders::toggle(1, $r2));
ck('reminders: cannot toggle others', !Reminders::toggle(2, $r1));

/* ---- Bookmarks: URL normalisation ---- */
reset_users();
$b1 = Bookmarks::add(1, 'Home', 'afrovanguard.org.ng', 'note');
ck('bookmarks: reject empty url', Bookmarks::add(1, 'x', '') === 0);
$bl = Bookmarks::listLinks(1, 10);
ck('bookmarks: bare domain -> https', $bl[0]['url'] === 'https://afrovanguard.org.ng');
ck('bookmarks: host parsed', $bl[0]['host'] === 'afrovanguard.org.ng');
ck('bookmarks: non-author cannot delete', !Bookmarks::remove(2, $b1));

/* ---- Calendar: native events + org gating + feed merge ---- */
reset_users();
$M = '2026-08';
$e1 = TeamCalendar::create(1, 'Offsite', "$M-10", '14:00', '16:00', 'Lagos', 'note');
ck('calendar: create', $e1 > 0);
ck('calendar: reject bad date', TeamCalendar::create(1, 'x', 'nope') === 0);
$feed = TeamCalendar::feed(1, "$M-01", "$M-28", true);
ck('calendar: event in feed', count(array_filter($feed, fn($x) => $x['kind'] === 'event')) === 1);
ck('calendar: non-org hides team events', count(array_filter(TeamCalendar::feed(1, "$M-01", "$M-28", false), fn($x) => $x['kind'] === 'event')) === 0);
Reminders::add(1, 'Report', "$M-12T10:30");
ck('calendar: reminder merged', count(array_filter(TeamCalendar::feed(1, "$M-01", "$M-28", true), fn($x) => $x['kind'] === 'reminder')) === 1);
ck('calendar: non-author cannot delete event', !TeamCalendar::remove(2, $e1));

/* ---- Notifications: dedupe + dispatch ---- */
reset_users();
$n = Notifications::push(1, 'task', 'New task', 'body', '/portal/#tasks', 'task:1');
ck('notif: push', $n > 0);
ck('notif: dedupe', Notifications::push(1, 'task', 'dup', '', '', 'task:1') === 0);
ck('notif: unread 1', Notifications::unreadCount(1) === 1);
ck('notif: per-user isolation', Notifications::unreadCount(2) === 0);
Notifications::markRead(1, [$n]);
ck('notif: read clears count', Notifications::unreadCount(1) === 0);
Reminders::add(1, 'Due now', '2000-01-01T09:00');
ck('notif: dispatch makes reminder notif', Notifications::dispatchDue()['reminders'] === 1);
ck('notif: dispatch idempotent', Notifications::dispatchDue()['reminders'] === 0);

/* ---- Prefs + timezone ---- */
require_once AV_ROOT . '/lib/Prefs.php';
reset_users();
ck('prefs: default tz is org default', av_user_tz(1) === AV_TZ);
ck('prefs: reject unknown tz', !Prefs::setTimezone(1, 'Mars/Olympus'));
ck('prefs: set valid tz', Prefs::setTimezone(1, 'Europe/London'));
ck('prefs: tz persisted', av_user_tz(1) === 'Europe/London');
ck('prefs: per-user isolation', av_user_tz(2) === AV_TZ);
ck('tz: av_now_tz formats', (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', av_now_tz()));
