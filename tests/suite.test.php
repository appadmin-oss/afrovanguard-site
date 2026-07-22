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

/* ---- Edit-in-place (F2) ---- */
reset_users();
$g2 = Goals::create(1, 'Old title', 'old');
ck('edit: non-author cannot edit goal', !Goals::edit(2, $g2, 'Hacked', ''));
ck('edit: author edits goal', Goals::edit(1, $g2, 'New title', 'new target'));
$gg = Goals::listGoals(1, 10)[0];
ck('edit: goal title updated', $gg['title'] === 'New title' && $gg['target'] === 'new target');
$l2 = Bookmarks::add(1, 'Old', 'example.com', '');
ck('edit: non-author cannot edit link', !Bookmarks::edit(2, $l2, 'x', 'y.com', ''));
ck('edit: author edits link', Bookmarks::edit(1, $l2, 'New', 'newsite.org', 'n'));
$ll2 = Bookmarks::listLinks(1, 10)[0];
ck('edit: link url normalised', $ll2['url'] === 'https://newsite.org' && $ll2['title'] === 'New');
$rr = Reminders::add(1, 'Old rem', '');
ck('edit: owner edits reminder', Reminders::edit(1, $rr, 'New rem', '2031-05-05T08:00'));
ck('edit: non-owner cannot edit reminder', !Reminders::edit(2, $rr, 'x', ''));
$ev = TeamCalendar::create(1, 'Old ev', '2026-09-09', '10:00');
ck('edit: author updates event', TeamCalendar::update(1, $ev, 'New ev', '2026-09-09', '11:00', '12:00', 'Hall', 'n'));
ck('edit: non-author cannot update event', !TeamCalendar::update(2, $ev, 'x', '2026-09-09'));
$evf = array_values(array_filter(TeamCalendar::feed(1, '2026-09-01', '2026-09-30', true), fn($x) => $x['kind'] === 'event'))[0];
ck('edit: event updated', $evf['title'] === 'New ev' && $evf['time'] === '11:00' && $evf['location'] === 'Hall');

/* ---- Member directory + profile cards ---- */
require_once AV_ROOT . '/lib/MemberDirectory.php';
(function () {
    $db = Database::pdo();
    $db->exec('DELETE FROM lms_users');
    $db->exec('DELETE FROM user_prefs');
    $db->exec("INSERT INTO lms_users (id,name,email,password_hash,role,status) VALUES "
        . "(1,'Ada Obi','ada@afrovanguard.org.ng','x','mentor','active'),"
        . "(2,'Bode Ade','bode@afrovanguard.org.ng','x','member','active'),"
        . "(3,'Ext Person','ext@gmail.com','x','member','active')");
    Prefs::setTimezone(1, 'Africa/Lagos');
    MemberDirectory::setSkills(1, 'writing, leadership, design, a, b, c, d, e, f, g');   // >8, should cap
    $c = MemberDirectory::card(2, 1);
    ck('dir: card name/role', $c && $c['name'] === 'Ada Obi' && $c['role'] === 'Mentor');
    ck('dir: card local time + tz', $c && $c['tz'] === 'Africa/Lagos' && (bool) preg_match('/^\d\d:\d\d$/', $c['local_time']));
    ck('dir: skills capped at 8', $c && count($c['skills']) === 8);
    ck('dir: is_me false for other viewer', $c && $c['is_me'] === false);
    ck('dir: org-only list excludes non-org', count(MemberDirectory::listMembers(1, '')) === 2);
    $q = MemberDirectory::listMembers(1, 'bode');
    ck('dir: name search works', count($q) === 1 && $q[0]['name'] === 'Bode Ade');
    ck('dir: non-org member card is null', MemberDirectory::card(1, 3) === null);
})();

/* ---- Community data-classification tags ---- */
require_once AV_ROOT . '/lib/Community.php';
(function () {
    $db = Database::pdo();
    Community::ensure();                       // create tables (+ seed) first
    $db->exec('DELETE FROM lms_users'); $db->exec('DELETE FROM community_posts');
    $db->exec("INSERT INTO lms_users (id,name,email,password_hash,role,status) VALUES "
        . "(1,'Coord','c@afrovanguard.org.ng','x','coordinator','active'),"
        . "(2,'Memb','m@afrovanguard.org.ng','x','member','active'),"
        . "(3,'Learn','l@gmail.com','x','learner','active')");
    ck('class: clearance coordinator=2', Community::clearance(1) === 2);
    ck('class: clearance member=1', Community::clearance(2) === 1);
    ck('class: clearance learner=0', Community::clearance(3) === 0);
    $conf = Community::createPost(1, 'open-floor', 'secret', null, false, 'confidential');
    Community::createPost(1, 'open-floor', 'team note', null, false, 'members');
    Community::createPost(1, 'open-floor', 'hello world', null, false, 'public');
    $seen = fn($vid) => array_map(fn($p) => $p['classification'], Community::feed(null, 'latest', 50, 0, $vid));
    ck('class: coordinator sees confidential', in_array('confidential', $seen(1), true));
    ck('class: member cannot see confidential', !in_array('confidential', $seen(2), true) && in_array('members', $seen(2), true));
    ck('class: learner sees only public', $seen(3) === ['public']);
    ck('class: member blocked from confidential by id', Community::post($conf, 2) === null);
    ck('class: coordinator opens confidential by id', Community::post($conf, 1) !== null);
    $capped = Community::createPost(2, 'open-floor', 'member tries', null, false, 'confidential');
    ck('class: member post capped to members', Community::post($capped, 1)['classification'] === 'members');
    ck('class: label present', Community::post($conf, 1)['class_label'] === 'Confidential');
})();

/* ---- Community admin moderation + announcements ---- */
(function () {
    $db = Database::pdo();
    Community::ensure();
    $db->exec('DELETE FROM lms_users'); $db->exec('DELETE FROM community_posts');
    $db->exec("INSERT INTO lms_users (id,name,email,password_hash,role,status) VALUES "
        . "(1,'Coord','c@afrovanguard.org.ng','x','coordinator','active'),"
        . "(2,'Memb','m@afrovanguard.org.ng','x','member','active')");
    ck('mod: coordinator is admin', Community::isAdmin(1) === true);
    ck('mod: member is not admin', Community::isAdmin(2) === false);
    $mine = Community::createPost(2, 'open-floor', 'my own post', null, false, 'members');
    $other = Community::createPost(1, 'open-floor', 'coord post', null, false, 'members');
    ck('mod: author can delete own', Community::moderateDelete(2, $mine));
    ck('mod: removed post leaves feed', count(array_filter(Community::feed(null,'latest',50,0,1), fn($p)=>$p['id']===$mine)) === 0);
    ck('mod: member cannot delete others', !Community::moderateDelete(2, $other));
    ck('mod: admin can delete any', Community::moderateDelete(1, $other));
    $p3 = Community::createPost(2, 'open-floor', 'pin me', null, false, 'members');
    ck('mod: member cannot pin', !Community::moderatePin(2, $p3, true));
    ck('mod: admin pins', Community::moderatePin(1, $p3, true));
    ck('mod: pinned reflected', (bool) Community::post($p3, 1)['pinned'] === true);
    ck('mod: admin reclassifies', Community::moderateClassify(1, $p3, 'confidential'));
    ck('mod: reclassify reflected', Community::post($p3, 1)['classification'] === 'confidential');
    ck('mod: member cannot reclassify', !Community::moderateClassify(2, $p3, 'public'));
    $ann = Community::announce(1, 'announcements', 'Official notice', true);
    ck('mod: admin announces (bot post)', $ann > 0 && Community::post($ann, 1)['is_bot'] === true);
    ck('mod: member cannot announce', Community::announce(2, 'announcements', 'nope') === 0);
})();

/* ---- Meetings: standardized scheduling + transcripts ---- */
(function () {
    require_once AV_ROOT . '/lib/Meetings.php';
    reset_users();
    $bad = Meetings::schedule(1, ['title' => '', 'when' => '2030-01-01T10:00']);
    ck('meet: reject empty title', empty($bad['ok']));
    $bad2 = Meetings::schedule(1, ['title' => 'Sync', 'when' => '']);
    ck('meet: reject empty when', empty($bad2['ok']));

    $r = Meetings::schedule(1, ['title' => 'Weekly sync', 'when' => '2030-06-01T09:00', 'duration' => 45, 'frequency' => 'weekly', 'attendees' => 'b@x.co', 'agenda' => 'Plan the week']);
    ck('meet: schedule ok', !empty($r['ok']) && ($r['id'] ?? 0) > 0);
    $mid = (int) $r['id'];
    $m = $r['meeting'];
    ck('meet: google-only, no link without Google', ($m['meet_url'] ?? 'x') === '' && ($m['provider'] ?? 'x') === '');
    ck('meet: warns to connect Google', isset($r['warning']));
    ck('meet: frequency stored', ($m['frequency'] ?? '') === 'weekly');
    ck('meet: duration clamped/stored', ($m['duration_min'] ?? 0) === 45);

    $list = Meetings::listFor(1);
    ck('meet: creator sees meeting', count(array_filter($list, fn($x) => $x['id'] === $mid)) === 1);
    ck('meet: invited attendee sees meeting', count(array_filter(Meetings::listFor(2), fn($x) => $x['id'] === $mid)) === 1);
    ck('meet: uninvited does not see it', count(array_filter(Meetings::listFor(3), fn($x) => $x['id'] === $mid)) === 0);

    // Transcript: raw stored even when AI unconfigured (structured=false, ok=true)
    $ts = Meetings::saveTranscript(1, $mid, "Ada: welcome. Bode: shipped the API. Decision: launch Friday.", 'paste');
    ck('meet: transcript saved', !empty($ts['ok']));
    ck('meet: raw persists', (Meetings::get(1, $mid)['transcript']['has_raw'] ?? false) === true);
    ck('meet: non-participant cannot add transcript', empty(Meetings::saveTranscript(3, $mid, 'x')['ok']));

    ck('meet: non-owner cannot cancel', empty(Meetings::cancel(2, $mid)['ok']));
    ck('meet: owner cancels', !empty(Meetings::cancel(1, $mid)['ok']));
    ck('meet: cancelled drops out of list', count(array_filter(Meetings::listFor(1), fn($x) => $x['id'] === $mid)) === 0);

    ck('meet: freq labels present', Meetings::freqLabel('biweekly') === 'Every 2 weeks');
})();

/* ---- Meetings: Gemini provider + recording bot ---- */
(function () {
    require_once AV_ROOT . '/lib/Gemini.php';
    require_once AV_ROOT . '/lib/Meetings.php';
    reset_users();
    // Gemini gating (no key in test env → not configured, clean error, no crash)
    ck('gemini: not configured without key', Gemini::configured() === false);
    $g = Gemini::generate('hi');
    ck('gemini: clean error when unconfigured', empty($g['ok']) && strpos((string) $g['error'], 'not configured') !== false);
    $ta = Gemini::transcribeAudio('', 'audio/mpeg');
    ck('gemini: transcribe rejects empty audio', empty($ta['ok']));

    // Recording bot: token round-trips; ingest is token-authed and stores raw.
    $r = Meetings::schedule(1, ['title' => 'Recorded standup', 'when' => '2030-07-01T08:00', 'auto_record' => true]);
    ck('meet: schedule with auto_record', !empty($r['ok']));
    $mid = (int) $r['id'];
    ck('meet: auto_record persisted', ($r['meeting']['auto_record'] ?? false) === true);
    ck('meet: bot marked unconfigured w/o recorder', ($r['meeting']['bot_state'] ?? '') === 'unconfigured');
    $tok = Meetings::botToken($mid);
    ck('meet: botIngest rejects bad token', empty(Meetings::botIngest($mid, 'nope', 'x')['ok']));
    ck('meet: botIngest accepts valid token', !empty(Meetings::botIngest($mid, $tok, 'Speaker 1: hello team.')['ok']));
    ck('meet: bot transcript stored raw', (Meetings::get(1, $mid)['transcript']['has_raw'] ?? false) === true);
    ck('meet: meetCodeFromUrl parses', GoogleWorkspace::meetCodeFromUrl('https://meet.google.com/abc-defg-hij?x=1') === 'abc-defg-hij');
})();

/* ---- Meetings: bot providers (Recall.ai + Google native) ---- */
(function () {
    require_once AV_ROOT . '/lib/RecallBot.php';
    require_once AV_ROOT . '/lib/Meetings.php';
    reset_users();
    ck('recall: not configured without key', RecallBot::configured() === false);
    ck('recall: createBot clean error unconfigured', empty(RecallBot::createBot('https://meet.google.com/x')['ok']));
    ck('recall: fetchTranscript empty when unconfigured', RecallBot::fetchTranscript('bot_1') === '');

    // Provider selection honours the explicit override.
    putenv('AV_MEET_BOT_PROVIDER=none');
    ck('provider: none disables bot', Meetings::botProvider() === '' && Meetings::botConfigured() === false);
    putenv('AV_MEET_BOT_PROVIDER=recall');
    ck('provider: forced recall', Meetings::botProvider() === 'recall');
    putenv('AV_MEET_BOT_PROVIDER');   // unset

    // recall webhook auth (no token configured → rejects).
    ck('recall: webhook rejects when no token set', empty(Meetings::recallWebhook('anything', ['event' => 'bot.status_change'])['ok']));
    ck('recall: ingest unknown bot', empty(Meetings::ingestFromRecall('nope')['ok']));
})();

/* ---- IQ: Incorruptible Quiz (quizzes, grading, leaderboard, shortcode) ---- */
(function () {
    require_once AV_ROOT . '/lib/IQ.php';
    reset_users();
    // Author a quiz (admin gating is enforced at the API layer; test the data layer)
    $r = IQ::saveQuiz(1, [
        'title' => 'Integrity Basics', 'category' => 'Values', 'difficulty' => 'easy', 'published' => 1,
        'questions' => [
            ['prompt' => '2+2?', 'points' => 10, 'options' => [['t'=>'3','c'=>0],['t'=>'4','c'=>1],['t'=>'5','c'=>0]]],
            ['prompt' => 'Sky colour?', 'points' => 10, 'options' => [['t'=>'Blue','c'=>1],['t'=>'Green','c'=>0]]],
        ],
    ]);
    ck('iq: save returns id+slug', !empty($r['ok']) && ($r['id'] ?? 0) > 0 && ($r['slug'] ?? '') !== '');
    $slug = $r['slug'];
    ck('iq: reject empty title', empty(IQ::saveQuiz(1, ['title' => ''])['ok']));

    $list = IQ::listQuizzes(0);
    ck('iq: published quiz listed', count(array_filter($list, fn($q) => $q['slug'] === $slug)) === 1);

    $play = IQ::getForPlay($slug);
    ck('iq: play view has questions', $play && count($play['questions']) === 2);
    // Correctness must NOT leak to the player
    $leak = false;
    foreach ($play['questions'] as $q) foreach ($q['options'] as $o) if (array_key_exists('c', $o) || array_key_exists('correct', $o)) $leak = true;
    ck('iq: play view hides correct answers', !$leak);

    // Grade: answer both correctly (map question id -> original option index)
    $ans = [];
    $edit = IQ::getForEdit($r['id']);
    foreach ($edit['questions'] as $q) {
        foreach ($q['options'] as $i => $o) if (!empty($o['c'])) $ans[(string) $q['id']] = $i;
    }
    $g = IQ::grade($slug, $ans, 1, 'Ada', 12);
    ck('iq: perfect score graded', !empty($g['ok']) && $g['pct'] === 100 && $g['correct'] === 2 && $g['passed']);
    // Wrong answers
    $bad = []; foreach ($edit['questions'] as $q) $bad[(string) $q['id']] = 99;
    $g2 = IQ::grade($slug, $bad, 2, 'Bode', 30);
    ck('iq: wrong answers score 0', $g2['pct'] === 0 && !$g2['passed']);

    $lb = IQ::quizLeaderboard($slug);
    ck('iq: leaderboard ranks top scorer first', count($lb) >= 1 && $lb[0]['pct'] === 100);
    ck('iq: global leaderboard counts signed-in only', count(IQ::globalLeaderboard()) >= 1);
    $badges = IQ::badges(1);
    ck('iq: perfect earns Flawless badge', (bool) (array_values(array_filter($badges, fn($b) => $b['key'] === 'perfect'))[0]['earned'] ?? false));

    // Shortcode → iframe embed
    $html = IQ::embedShortcodes('Intro [iq ' . $slug . '] outro');
    ck('iq: shortcode becomes an embed iframe', strpos($html, '/IQ/embed.php?quiz=' . $slug) !== false);

    ck('iq: delete removes quiz', IQ::deleteQuiz($r['id']) && count(array_filter(IQ::listQuizzes(0), fn($q) => $q['slug'] === $slug)) === 0);
})();
