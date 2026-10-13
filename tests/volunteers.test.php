<?php
/**
 * tests/volunteers.test.php — the Volunteer hub: teams, meetings that run
 * themselves, live capture, and minutes from draft to published.
 *
 *   • Teams: a coordinator starts one; its lead runs it; one secretary.
 *   • A team meeting invites the team, and whoever joins later.
 *   • Answers per occurrence; an apology is on the record.
 *   • Live capture: the room's QR (it changes every minute), "I'm here", room
 *     captions, the notetaker's signed webhook (Attendee) — stored once each.
 *   • Attendance from what happened: on time, late, apologies, absent.
 *   • Minutes: drafted on their own with the agenda, matters arising, the live
 *     attendance and the room's words; the secretary hands them to the chair;
 *     publishing files each action to its owner and credits hours; amending
 *     keeps every version; a re-publish files nothing twice.
 *   • The sweep: a day before, an hour before, the draft when it ends, the
 *     reminder when it is late — each once.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$vp = Database::pdo();
Volunteers::ensure(); MeetingMinutes::ensure(); MeetingLive::ensure(); Commitments::ensure();
foreach (['vol_teams', 'vol_team_members', 'vol_hours', 'meeting_rsvps', 'meeting_minutes', 'meeting_minutes_history', 'meeting_nudges', 'meeting_live_events', 'user_notifications'] as $t) { try { $vp->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
$vp->exec("DELETE FROM meetings WHERE title LIKE 'VT %'");
$vp->exec("DELETE FROM commitments WHERE dedupe_key LIKE 'min:%'");
$vp->exec('DELETE FROM lms_users WHERE id BETWEEN 9300 AND 9399');
$mk = static function (int $id, string $name, string $role = 'member') use ($vp): array {
    $email = strtolower(str_replace(' ', '.', $name)) . '@afrovanguard.org.ng';
    $vp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([$id, $name, $email, 'x', $role, 'active']);
    return ['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role];
};
$coord = $mk(9301, 'Kemi Coordinator', 'coordinator');
$lead  = $mk(9302, 'Lanre Lead');
$sec   = $mk(9303, 'Sade Secretary');
$mem   = $mk(9304, 'Musa Member');
$late  = $mk(9305, 'Lola Late');
$out   = $mk(9306, 'Outsider Person', 'learner');
$vp->prepare("UPDATE lms_users SET email = 'outsider@gmail.test' WHERE id = 9306")->execute();
$out['email'] = 'outsider@gmail.test';

/* ── Who ── */
ck('Volunteers: an org member is a volunteer', Volunteers::isVolunteer($mem));
ck('Volunteers: …a learner with a personal email is not', !Volunteers::isVolunteer($out));

/* ── Teams ── */
ck('Volunteers: only a coordinator starts a team', !Volunteers::createTeam($mem, 'Outreach')['ok']);
$t = Volunteers::createTeam($coord, 'VT Outreach', 'Schools and communities');
$tid = (int) ($t['id'] ?? 0);
ck('Volunteers: a coordinator starts one', $t['ok'] && $tid > 0);
ck('Volunteers: …and two teams cannot share a name', !Volunteers::createTeam($coord, 'vt outreach')['ok']);
Volunteers::setMember($coord, $tid, (string) $lead['id'], 'lead');
Volunteers::setMember($lead, $tid, $sec['email'], 'secretary');
Volunteers::setMember($lead, $tid, $mem['email'], 'member');
ck('Volunteers: the lead adds people by email or account', count(Volunteers::team($tid)['members']) === 3);
ck('Volunteers: a member cannot add people', !Volunteers::setMember($mem, $tid, $late['email'])['ok']);
Volunteers::setMember($lead, $tid, $mem['email'], 'secretary');
$roles = array_column(Volunteers::team($tid)['members'], 'role', 'id');
ck('Volunteers: one secretary — naming a new one steps the old one down', $roles[9304] === 'secretary' && $roles[9303] === 'member');
Volunteers::setMember($lead, $tid, $sec['email'], 'secretary');
ck('Volunteers: the only lead cannot step down without a successor', !Volunteers::removeMember($lead, $tid, 9302)['ok']);
ck('Volunteers: somebody on a team is a volunteer, whatever their email', (function () use ($lead, $tid, $out) { Volunteers::setMember($lead, $tid, (string) $out['id']); $r = Volunteers::isVolunteer($out); Volunteers::removeMember($lead, $tid, 9306); return $r; })());

/* ── A meeting on now ── */
$start = time() - 20 * 60;                     // began 20 minutes ago, an hour long
$occ = gmdate('Y-m-d H:i:s', $start);
$vp->prepare("INSERT INTO meetings (creator_id, title, agenda, scheduled_at, duration_min, frequency, context, context_id, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
   ->execute([9302, 'VT Outreach weekly', "1. School visits\n- Budget\n* Volunteers' week", $occ, 60, 'weekly', 'team', $tid, 'scheduled', gmdate('Y-m-d H:i:s')]);
$mid = (int) $vp->lastInsertId();
foreach (Volunteers::team($tid)['members'] as $p) $vp->prepare('INSERT INTO meeting_attendees (meeting_id, email) VALUES (?, ?)')->execute([$mid, $p['email']]);
Volunteers::setMember($lead, $tid, $late['email']);
ck('Volunteers: somebody who joins the team is invited to its meetings to come', in_array($late['email'], array_column(MeetingMinutes::invitees($mid), 'email'), true));
$m = $vp->query("SELECT * FROM meetings WHERE id = $mid")->fetch(PDO::FETCH_ASSOC);
ck('Minutes: a weekly meeting\'s occurrences are a week apart', ($o = MeetingMinutes::occurrences($m, $start - 1, $start + 15 * 86400)) && count($o) === 3 && $o[1] - $o[0] === 7 * 86400);
ck('Minutes: the agenda text becomes items, bullets and numbers stripped', array_column(MeetingMinutes::agendaItems((string) $m['agenda']), 'item') === ['School visits', 'Budget', "Volunteers' week"]);

/* ── Answers ── */
$next = gmdate('Y-m-d H:i:s', $start + 7 * 86400);
ck('Answers: a volunteer answers for an occurrence', MeetingMinutes::rsvp($mem, $mid, $next, 'yes')['ok']);
ck('Answers: …not for a day the meeting is not on', !MeetingMinutes::rsvp($mem, $mid, gmdate('Y-m-d H:i:s', $start + 86400), 'yes')['ok']);
ck('Answers: …not for one that is over', !MeetingMinutes::rsvp($mem, $mid, gmdate('Y-m-d H:i:s', $start - 7 * 86400), 'yes')['ok']);
ck('Answers: somebody not invited cannot', !MeetingMinutes::rsvp($out, $mid, $next, 'yes')['ok']);
MeetingMinutes::rsvp($mem, $mid, $occ, 'no', 'Exams');
ck('Answers: an apology tells the chair', (int) $vp->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = 9302 AND title LIKE '%apologies%'")->fetchColumn() === 1);

/* ── Live capture ── */
$code = MeetingLive::checkinCode($mid, $occ);
ck('Live: the room\'s code checks a phone in', MeetingLive::checkin($sec, $mid, $occ, $code)['ok']);
ck('Live: …once, however often it is scanned', MeetingLive::checkin($sec, $mid, $occ, $code)['already'] === true);
ck('Live: last minute\'s code still works (the screen just changed)', MeetingLive::checkin($lead, $mid, $occ, MeetingLive::checkinCode($mid, $occ, intdiv(time(), 60) - 1))['ok']);
ck('Live: an older code does not — a photo sent home stops working', !MeetingLive::checkin($late, $mid, $occ, MeetingLive::checkinCode($mid, $occ, intdiv(time(), 60) - 5))['ok']);
ck('Live: the QR is an SVG of the check-in link', str_contains(MeetingLive::checkinQr($mid, $occ), '<svg'));
ck('Live: "I\'m here" during the meeting', MeetingLive::here($late, $mid, $occ)['ok']);
ck('Live: a caption from the room is stored', MeetingLive::caption($sec, $mid, $occ, 'We agreed to visit three schools in November.', 'cap0001', 'Room')['ok']
    && MeetingLive::caption($sec, $mid, $occ, 'Sade will draft the letters by Friday.', 'cap0002')['ok']);
ck('Live: …the same caption twice is one line', MeetingLive::caption($sec, $mid, $occ, 'We agreed to visit three schools in November.', 'cap0001')['ok'] && count(MeetingLive::events($mid, $occ, 0, 'utterance')) === 2);
/* A Google-style join on time and leave, recorded as the poller would. */
MeetingLive::record($mid, $occ, 'join', 'google', 'j:conf/p1', ['name' => 'Lanre Lead', 'email' => $lead['email']], $start + 60);
MeetingLive::record($mid, $occ, 'leave', 'google', 'l:conf/p1', ['name' => 'Lanre Lead', 'email' => $lead['email']], $start + 30 * 60);
MeetingLive::record($mid, $occ, 'join', 'google', 'j:conf/p1', ['name' => 'Lanre Lead'], $start + 60);
$people = array_column(MeetingLive::people($m, $occ), null, 'user_id');
ck('Live: the poller re-reading Google stores nothing twice', count(MeetingLive::events($mid, $occ, 0, 'join')) === 4);
ck('Live: a person\'s sources merge into one row — Google and the QR are the same Lanre', isset($people[9302]) && in_array('google', $people[9302]['sources'], true) && in_array('checkin', $people[9302]['sources'], true));
ck('Live: joining 20 minutes in is late, and by how much', $people[9305]['late'] === true && $people[9305]['late_by'] >= 19);
ck('Live: "I\'m here" alone is marked self-reported', $people[9305]['self_reported'] === true);
$att = MeetingLive::attendance($m, $occ, MeetingMinutes::answers($mid, $occ)['by']);
ck('Live: attendance from what happened — on time, late (checked in 20 minutes in), apologies', ($att['9302'] ?? '') === 'present' && ($att['9303'] ?? '') === 'late' && ($att['9305'] ?? '') === 'late' && ($att['9304'] ?? '') === 'apologies');
$room = MeetingLive::room($mem, $mid, $occ);
ck('Live: the room shows who is here, what was said, and the QR', $room['ok'] && count($room['words']) === 2 && $room['checkin'] !== null && count($room['people']) >= 3);
ck('Live: the room is for the people of the meeting', !MeetingLive::room($out, $mid, $occ)['ok']);

/* The notetaker's live webhook — signed the way Attendee signs (verified against Python). */
putenv('AV_ATTENDEE_WEBHOOK_SECRET=YXR0ZW5kZWUtdGVzdC1zZWNyZXQtMDEyMzQ1Njc4OQ==');
$wire = "{\n  \"idempotency_key\": \"5f1c\",\n  \"bot_id\": \"bot_abc\",\n  \"bot_metadata\": {\n    \"av_meeting_id\": \"7\"\n  },\n  \"trigger\": \"transcript.update\",\n  \"data\": {\n    \"speaker_name\": \"Ad\u{e9} Bello\",\n    \"speaker_uuid\": \"u1\",\n    \"speaker_is_host\": false,\n    \"timestamp_ms\": 1760000000000,\n    \"duration_ms\": 1500,\n    \"transcription\": {\n      \"transcript\": \"Let's ship it / today\",\n      \"words\": null\n    },\n    \"event_data\": {},\n    \"score\": 0.5,\n    \"whole\": 2.0\n  }\n}";
$sig = 'X5I942mIJHaDUC7NCzmWEbT2bpILEb7Eep4e1X7sG6E=';
ck('Notetaker: Attendee\'s signature checks out (canonical JSON, as Attendee computes it)', AttendeeBot::verifyWebhook($wire, $sig));
ck('Notetaker: …a forged one does not', !AttendeeBot::verifyWebhook($wire, 'AAAA' . substr($sig, 4)) && MeetingLive::fromAttendee($wire, 'nope')['status'] === 401);
$vp->prepare("INSERT INTO meetings (creator_id, title, scheduled_at, duration_min, frequency, context, status, bot_ref, bot_provider, created_at) VALUES (9302, 'VT Bot call', '2025-10-09 08:45:00', 60, 'once', 'workspace', 'scheduled', 'bot_abc', 'attendee', '2025-10-01')")->execute();
$botMid = (int) $vp->lastInsertId();
$r = MeetingLive::fromAttendee($wire, $sig);
ck('Notetaker: a live utterance lands on the right meeting (by its bot) and occurrence', ($r['stored'] ?? '') === 'utterance' && count(MeetingLive::events($botMid, '2025-10-09 08:45:00', 0, 'utterance')) === 1);
ck('Notetaker: …once, however often it is retried', MeetingLive::fromAttendee($wire, $sig)['ok'] && count(MeetingLive::events($botMid, '2025-10-09 08:45:00', 0, 'utterance')) === 1);
ck('Notetaker: the bot is asked for joins and leaves too', in_array('participant_events.join_leave', AttendeeBot::buildCreateBody('https://meet.google.com/abc-defg-hij', 'https://x.test/hook')['webhooks'][0]['triggers'] ?? [], true));
putenv('AV_ATTENDEE_WEBHOOK_SECRET');

/* ── Minutes ── */
$open = MeetingMinutes::open($mem, $mid, $occ);
ck('Minutes: somebody who is not the secretary or chair cannot start them', $open['ok'] && $open['minutes'] === null);
$open = MeetingMinutes::open($sec, $mid, $occ);
$min = $open['minutes'];
ck('Minutes: the secretary opens a draft that already has the agenda', $min && $min['status'] === 'draft' && count($min['body']['agenda']) === 3);
ck('Minutes: …and the live attendance', in_array('live', $min['seeded_from'], true) && ((array) $min['attendance'])['9305'] === 'late');
$prev = Commitments::fileMany(Commitments::SRC_MEETING, $mid, [['task' => 'VT older action', 'owner' => '']]);
$vp->exec("DELETE FROM meeting_minutes WHERE id = " . (int) $min['id']);
$min = MeetingMinutes::open($sec, $mid, $occ)['minutes'];
ck('Minutes: open actions from earlier meetings come back as matters arising', in_array('VT older action', array_column($min['body']['arising'], 'title'), true));
$body = $min['body'];
$body['summary'] = 'Planned the **school visits**.';
$body['agenda'][0]['notes'] = "- Three schools\n- November";
$body['decisions'] = ['Visit three schools in November'];
$body['actions'] = [['task' => 'Draft the letters to schools', 'owner_id' => 0, 'due' => '']];
ck('Minutes: only the secretary or chair write them', !MeetingMinutes::save($mem, (int) $min['id'], $body, [])['ok']);
$saved = MeetingMinutes::save($sec, (int) $min['id'], $body, (array) $min['attendance']);
ck('Minutes: the secretary saves — a new version, the old one kept', $saved['ok'] && $saved['minutes']['version'] === 2 && (int) $vp->query('SELECT COUNT(*) FROM meeting_minutes_history WHERE minutes_id = ' . (int) $min['id'])->fetchColumn() === 1);
ck('Minutes: an action nobody owns cannot go to the chair', !MeetingMinutes::submit($sec, (int) $min['id'])['ok']);
$body['actions'][0]['owner_id'] = 9303; $body['actions'][0]['due'] = gmdate('Y-m-d', time() + 5 * 86400);
MeetingMinutes::save($sec, (int) $min['id'], $body, (array) $min['attendance']);
ck('Minutes: handed to the chair', MeetingMinutes::submit($sec, (int) $min['id'])['ok']);
ck('Minutes: …who is told', (int) $vp->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = 9302 AND title = 'Minutes to approve'")->fetchColumn() === 1);
ck('Minutes: the secretary cannot publish', !MeetingMinutes::publish($sec, (int) $min['id'])['ok']);
ck('Minutes: the meeting has to have happened', (function () use ($vp, $min, $lead) {
    $vp->exec("UPDATE meeting_minutes SET occurrence = '2999-01-01 00:00:00' WHERE id = " . (int) $min['id']);
    $r = MeetingMinutes::publish($lead, (int) $min['id']);
    $vp->prepare('UPDATE meeting_minutes SET occurrence = ? WHERE id = ?')->execute([$min['occurrence'], (int) $min['id']]);
    return !$r['ok'];
})());
$pub = MeetingMinutes::publish($lead, (int) $min['id']);
ck('Minutes: the chair publishes', $pub['ok'] && $pub['minutes']['status'] === 'published');
ck('Minutes: …each action becomes a commitment owned by the person named, due when the minutes say',
    (int) $vp->query("SELECT COUNT(*) FROM commitments WHERE dedupe_key LIKE 'min:%' AND member_id = 9303 AND title = 'Draft the letters to schools' AND due = '" . $body['actions'][0]['due'] . "'")->fetchColumn() === 1);
ck('Minutes: …and they are told', (int) $vp->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = 9303 AND title LIKE 'An action for you%'")->fetchColumn() === 1);
ck('Minutes: …everybody present is credited the meeting\'s hour', Volunteers::hoursFor(9303)['month'] >= 1.0 && Volunteers::hoursFor(9305)['month'] >= 1.0 && Volunteers::hoursFor(9304)['month'] == 0.0);
ck('Minutes: …everybody invited is told the minutes are out', (int) $vp->query("SELECT COUNT(DISTINCT user_id) FROM user_notifications WHERE title = 'Minutes published'")->fetchColumn() >= 4);
$html = MeetingMinutes::html($m, $vp->query('SELECT * FROM meeting_minutes WHERE id = ' . (int) $min['id'])->fetch(PDO::FETCH_ASSOC));
ck('Minutes: the document has who came, the decisions and the actions', str_contains($html, 'Apologies:') && str_contains($html, 'Musa Member') && str_contains($html, 'Visit three schools') && str_contains($html, 'Sade Secretary'));
ck('Minutes: notes are Markdown, rendered safely', str_contains($html, '<strong>school visits</strong>') || !ChiomaMarkdown::available());
$pdf = MeetingMinutes::pdfFor($mem, (int) $min['id']);
ck('Minutes: a PDF of them (dompdf)', $pdf !== null && str_starts_with($pdf['bytes'], '%PDF'));
$body['actions'][0]['due'] = gmdate('Y-m-d', time() + 9 * 86400);
MeetingMinutes::save($lead, (int) $min['id'], $body, (array) $min['attendance']);
$amend = MeetingMinutes::publish($lead, (int) $min['id']);
ck('Minutes: amending and publishing again says so, and files nothing twice', $amend['ok'] && $amend['amended'] === true && $amend['actions'] === 0
    && (int) $vp->query("SELECT COUNT(*) FROM commitments WHERE dedupe_key LIKE 'min:%'")->fetchColumn() === 1
    && (string) $vp->query("SELECT due FROM commitments WHERE dedupe_key LIKE 'min:%'")->fetchColumn() === $body['actions'][0]['due']);
ck('Minutes: …and hours are not credited twice', Volunteers::hoursFor(9303)['month'] < 2.0);
ck('Minutes: printable for anybody on the meeting, nobody else', is_string(MeetingMinutes::printable($mem, (int) $min['id'])) && MeetingMinutes::printable($out, (int) $min['id']) === null);

/* ── The calendar file ── */
$ics = MeetingMinutes::icsFor($m, 'REQUEST');
$unfolded = str_replace("\r\n ", '', $ics);
ck('Calendar: an invite a mail client can answer — organiser, attendees, RSVP, the weekly rule', str_contains($ics, "METHOD:REQUEST\r\n") && str_contains($unfolded, 'ORGANIZER;CN=Lanre Lead:mailto:lanre.lead@afrovanguard.org.ng')
    && substr_count($unfolded, 'RSVP=TRUE') === 4 && str_contains($ics, "RRULE:FREQ=WEEKLY\r\n"));
ck('Calendar: …lines folded at 75 octets, as RFC 5545 asks', max(array_map('strlen', explode("\r\n", $ics))) <= 75);

/* ── The hub ── */
$hub = Volunteers::hub($sec);
ck('Hub: my teams, my actions, my hours', $hub['ok'] && count($hub['teams']) === 1 && count($hub['actions']) >= 1 && $hub['hours']['month'] >= 1.0);
ck('Hub: the meeting on now is in the upcoming list, with its room open', ($u0 = $hub['upcoming'][0] ?? null) && $u0['id'] === $mid && $u0['room'] === true);
ck('Hub: hours logged by hand, and removed', ($h = Volunteers::logHours(9304, '', 2.5, 'Outreach at Egbeda'))['ok'] && Volunteers::hoursFor(9304)['month'] == 2.5
    && Volunteers::removeHours(9304, (int) $h['hours']['recent'][0]['id'])['ok'] && !Volunteers::logHours(9304, '2999-01-01', 1, 'x')['ok']);

/* ── The dashboard's views ── */
$vp->exec("UPDATE meetings SET duration_min = 15 WHERE id = $mid");   /* so it has ended */
$past = MeetingMinutes::past($sec);
$vp->exec("UPDATE meetings SET duration_min = 60 WHERE id = $mid");
ck('Dashboard: past meetings show who came and where the minutes stand', ($p0 = $past[0] ?? null) && $p0['id'] === $mid && $p0['minutes_status'] === 'published' && $p0['came'] >= 3 && $p0['my_mark'] === 'late');
ck('Dashboard: …filtered by team', MeetingMinutes::past($sec, $tid + 999) === []);
ck('Dashboard: the minutes archive finds published minutes by what they decided', count(MeetingMinutes::archive($mem, 'published', 0, 'three schools')) === 1 && MeetingMinutes::archive($mem, '', 0, 'no such words') === []);
$vp->prepare("INSERT INTO meeting_minutes (meeting_id, occurrence, status, secretary_id, body, attendance, created_at, updated_at) VALUES (?, ?, 'draft', 9303, '{}', '{}', '', '')")->execute([$mid, gmdate('Y-m-d H:i:s', $start - 7 * 86400)]);
ck('Dashboard: a draft is the secretary\'s and chair\'s until it is handed over', count(MeetingMinutes::archive($mem, 'draft')) === 0 && count(MeetingMinutes::archive($sec, 'draft')) === 1);
$at = MeetingMinutes::attendanceOf($late);
ck('Dashboard: my attendance — late still counts as came', $at['late'] === 1 && $at['rate'] === 100);
ck('Dashboard: …apologies are not held against anybody', MeetingMinutes::attendanceOf($mem)['apologies'] === 1 && MeetingMinutes::attendanceOf($mem)['rate'] === null);
$ta = MeetingMinutes::teamAttendance($tid);
ck('Dashboard: a team\'s attendance, member by member', ($ta[9303]['rate'] ?? null) === 100 && ($ta[9304]['apologies'] ?? 0) === 1);
$series = Volunteers::hoursSeries(9303, 12);
ck('Dashboard: twelve months of hours, oldest first, this month last', count($series) === 12 && $series[11]['month'] === substr(av_today_tz(), 0, 7) && $series[11]['hours'] >= 1.0);
Volunteers::logHours(9304, '', 1, '=HYPERLINK("http://x")');
ck('Dashboard: the hours spreadsheet cannot carry a formula', str_contains(Volunteers::hoursCsv(9304), "'=HYPERLINK") && str_starts_with(Volunteers::hoursCsv(9304), 'Date,Hours'));
ck('Dashboard: a team\'s open actions, for those on it', count(Volunteers::teamActions($lead, $tid)) >= 1 && Volunteers::teamActions($out, $tid) === []);
ck('Dashboard: the activity in my teams', count(Volunteers::activity($sec)) >= 3);
$ints = Volunteers::integrations();
ck('Dashboard: without Attendee, no notetaker is offered — and the hub still works', $ints['notetaker'] === false && Volunteers::hub($sec)['ok'] === true);

/* ── What brings people back ── */
GateAttendance::ensure();
$vp->exec('DELETE FROM gate_attendance WHERE member_id BETWEEN 9300 AND 9399');
$today = av_today_tz();
$vp->prepare("INSERT INTO gate_attendance (member_id, day, status, in_at) VALUES (9303, ?, 'present', ?)")->execute([$today, gmdate('c')]);
Volunteers::logHours(9303, date('Y-m-d', strtotime($today . ' -7 days')), 2, 'Outreach');
Volunteers::logHours(9303, date('Y-m-d', strtotime($today . ' -14 days')), 2, 'Outreach');
Birthdays::ensure();
$vp->prepare('UPDATE lms_users SET birthday = ? WHERE id = 9305')->execute([date('m-d', strtotime($today . ' +2 days'))]);
$en = Volunteers::engagement($sec);
ck('Engagement: a week-by-week streak — this week, last week and the one before', $en['streak'] === 3);
ck('Engagement: milestones earned are listed, and the nearest one next', in_array('first_hour', array_column($en['earned'], 'key'), true) && $en['next'] !== null && $en['next']['pct'] < 100);
ck('Engagement: today at the gate', ($en['gate']['today']['status'] ?? '') === 'present');
ck('Engagement: where I stand this month among my teams', $en['my_rank'] !== null && $en['my_rank']['rank'] === 1 && count($en['board']) <= 5);
ck('Engagement: a teammate\'s birthday coming up', ($en['birthdays'][0]['name'] ?? '') === 'Lola Late' && $en['birthdays'][0]['in'] === 2);
ck('Engagement: …the hub carries all of it, with the monthly goal', Volunteers::hub($sec)['engagement']['goal'] === Volunteers::MONTHLY_GOAL);
ck('Engagement: somebody with nothing yet has no streak and a first milestone ahead', ($e0 = Volunteers::engagement($out))['streak'] === 0 && $e0['earned'] === [] && $e0['next']['key'] === 'first_hour');
$vp->exec('DELETE FROM gate_attendance WHERE member_id BETWEEN 9300 AND 9399');

/* ── The portal is the dashboard and Membership ── */
$idx = (string) file_get_contents(AV_ROOT . '/portal/index.php');
ck('Portal: the dashboard is the volunteer\'s home, drawn first', strpos($idx, "require __DIR__ . '/views/volunteer.php'") < strpos($idx, "require __DIR__ . '/views/membership.php'")
    && str_contains((string) file_get_contents(AV_ROOT . '/portal/avp.js'), "var home = views[0].getAttribute('data-view');"));
ck('Portal: nothing else is drawn — no Today, Suite, Tasks, Chat, Learning, Community, Mentorship, Workspace or Diary',
    !preg_match("~views/(today|tools|tasks|chat|learning|community|mentorship|inventory|workspace|diary)\.php~", $idx));
ck('Portal: vanguards keep their programme', str_contains($idx, "if (\$isNgv) require __DIR__ . '/views/ngv.php';"));
ck('Portal: attendance is in the dashboard for a volunteer', str_contains((string) file_get_contents(AV_ROOT . '/portal/views/volunteer.php'), "_attendance.php") && str_contains($idx, '$attView = ($hasGate || $isNgv) && !$isVol;'));

/* ── The sweep ── */
$vp->exec("DELETE FROM meeting_nudges");
$t0 = time();
$vp->prepare("INSERT INTO meetings (creator_id, title, agenda, scheduled_at, duration_min, frequency, context, context_id, status, created_at) VALUES (9302, 'VT Planning', 'Plans', ?, 45, 'once', 'team', ?, 'scheduled', ?)")
   ->execute([gmdate('Y-m-d H:i:s', $t0 + 20 * 3600), $tid, gmdate('Y-m-d H:i:s')]);
$soon = (int) $vp->lastInsertId();
foreach (Volunteers::team($tid)['members'] as $p) $vp->prepare('INSERT INTO meeting_attendees (meeting_id, email) VALUES (?, ?)')->execute([$soon, $p['email']]);
$s1 = MeetingMinutes::sweep($t0);
ck('Sweep: a day before, everybody is reminded', $s1['day_before'] >= 1 && (int) $vp->query("SELECT COUNT(*) FROM user_notifications WHERE title = 'Tomorrow: VT Planning'")->fetchColumn() === 4);
$s2 = MeetingMinutes::sweep($t0 + 60);
ck('Sweep: …once', (int) $vp->query("SELECT COUNT(*) FROM user_notifications WHERE title = 'Tomorrow: VT Planning'")->fetchColumn() === 4);
MeetingMinutes::sweep($t0 + 20 * 3600 - 30 * 60);
ck('Sweep: half an hour before, "starting soon"', (int) $vp->query("SELECT COUNT(*) FROM user_notifications WHERE title = 'Starting soon: VT Planning'")->fetchColumn() === 4);
$vp->prepare('UPDATE meetings SET scheduled_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', $t0 - 2 * 3600), $soon]);
$s3 = MeetingMinutes::sweep($t0);
ck('Sweep: when it ends, the draft minutes are made and the secretary asked', $s3['drafted'] >= 1 && (int) $vp->query("SELECT COUNT(*) FROM meeting_minutes WHERE meeting_id = $soon")->fetchColumn() === 1
    && (int) $vp->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = 9303 AND title = 'Minutes to write: VT Planning'")->fetchColumn() === 1);
$vp->prepare('UPDATE meetings SET scheduled_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', $t0 - 3 * 86400), $soon]);
$vp->prepare('UPDATE meeting_minutes SET occurrence = ? WHERE meeting_id = ?')->execute([gmdate('Y-m-d H:i:s', $t0 - 3 * 86400), $soon]);
$s4 = MeetingMinutes::sweep($t0);
ck('Sweep: two days on with no minutes out, the secretary and chair are reminded', $s4['overdue'] >= 1
    && (int) $vp->query("SELECT COUNT(DISTINCT user_id) FROM user_notifications WHERE title = 'Minutes still not out: VT Planning'")->fetchColumn() === 2);
ck('Sweep: …once', MeetingMinutes::sweep($t0)['overdue'] === 0);

/* ── The page and the API ── */
$api = (string) file_get_contents(AV_ROOT . '/portal/volunteer.php');
ck('API: writes are same-origin + CSRF + rate-limited; the hub is volunteers-only', str_contains($api, 'av_require_write(') && str_contains($api, 'Volunteers::isVolunteer($u)'));
$cron = (string) file_get_contents(AV_ROOT . '/tasks/cron.php');
ck('Cron: the meetings sweep runs with a heartbeat', str_contains($cron, "AiOps::run('meetings_live'") && isset(AiOps::RUNGS['meetings_live']));

foreach (['vol_teams', 'vol_team_members', 'vol_hours', 'meeting_rsvps', 'meeting_minutes', 'meeting_minutes_history', 'meeting_nudges', 'meeting_live_events'] as $t) { try { $vp->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
$vp->exec("DELETE FROM meetings WHERE title LIKE 'VT %'");
$vp->exec("DELETE FROM commitments WHERE dedupe_key LIKE 'min:%' OR title = 'VT older action'");
$vp->exec('DELETE FROM lms_users WHERE id BETWEEN 9300 AND 9399');
