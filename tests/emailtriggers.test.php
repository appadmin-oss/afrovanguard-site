<?php
/**
 * tests/emailtriggers.test.php — WHEN each designed email is sent.
 *
 * The templates render correctly (emailtemplates.test.php). This file is
 * about the rules around them, which is where a transactional email goes
 * wrong in ways nobody notices for months:
 *
 *   • Sent twice. An admin screen that saves "approved" over "approved",
 *     or an undo and a redo, must not tell a mentor twice that they are in.
 *   • Sent too early. A Diary comment is posted PENDING. Mailing on the post
 *     announces a reply nobody can read — and mails the victim about every
 *     piece of spam that is never published.
 *   • Sent to the wrong person. Replying to your own comment is not news,
 *     and a muted address stays muted.
 *   • Sent when the news is smaller than the other news. Passing the last
 *     quiz finishes the course; the certificate is what you want then, not
 *     both in the same minute.
 *
 * Mail is captured with Mailer::fakeRoads(), so nothing leaves the process.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

reset_users();
$db = Database::pdo();

/* Capture every message instead of sending it. */
$sent = [];
$capture = static function (array $msg) use (&$sent): bool { $sent[] = $msg; return true; };
Mailer::fakeRoads(['smtp' => $capture, 'resend' => $capture, 'relay' => $capture, 'host' => $capture]);
putenv('AV_MAIL_DISABLED=0'); unset($_ENV['AV_MAIL_DISABLED'], $_SERVER['AV_MAIL_DISABLED']);
$reset = static function () use (&$sent): void { $sent = []; };
$subjects = static function () use (&$sent): array { return array_map(fn($m) => (string) ($m['subject'] ?? ''), $sent); };

/* ══ MENTOR: applied, then approved ═══════════════════════════════════════ */

MentorPortal::ensure();
foreach (['mentorships', 'mentor_profiles', 'mentor_sessions'] as $t) { try { $db->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
$db->exec("INSERT INTO lms_users (id,name,email,password_hash) VALUES
    (91,'Adaeze Okafor','adaeze91@x.co','x'), (92,'Segun Bello','segun92@x.co','x'), (93,'Amara Eze','amara93@x.co','x')");

$reset();
Mentorship::becomeMentor(91, ['headline' => 'Chapter lead', 'focus' => 'Leadership', 'bio' => '', 'capacity' => 5, 'accepting' => true]);
$afterApply = $subjects();
ck('trigger: applying to mentor sends exactly one email',
    count($afterApply) === 1);
ck('trigger: …and it is the one for the state the application is actually in',
    (bool) preg_match('/application|approved/i', $afterApply[0] ?? ''));

/* Approval is an undo primitive as well as an admin action, so it gets
   called with the status a profile already has. */
$reset();
Mentorship::setMentorApproval(91, 'pending');
Mentorship::setMentorApproval(91, 'approved');
$first = count($sent);
Mentorship::setMentorApproval(91, 'approved');
ck('trigger: approving a mentor mails them once', $first === 1);
ck('trigger: …and saving "approved" over "approved" does not mail again — an '
 . 'undo and a redo must not read as two approvals',
    count($sent) === 1);

/* ══ MENTEE: matched ══════════════════════════════════════════════════════ */

$reset();
$r = Mentorship::adminAssign(91, 93, 0, 'Vanguard Quest');
ck('trigger: a pairing mails the mentee', ($r['ok'] ?? false) && count($sent) >= 1);
$matched = array_values(array_filter($sent, fn($m) => str_contains((string) $m['subject'], 'mentor')));
ck('trigger: the mentee email goes to the MENTEE, not the mentor',
    $matched !== [] && str_contains(json_encode($matched[0]), 'amara93@x.co'));
/* strip_tags leaves the CSS inside <style> behind, and @media / @keyframes
   then read as handles — so the stylesheet goes first. */
$visible = static fn(string $html): string =>
    strip_tags((string) preg_replace('~<style\b[^>]*>.*?</style>~is', ' ', $html));
ck('trigger: and it carries no message content, phone number or handle (§5)',
    $matched !== [] && !preg_match('/whatsapp|wa\.me|\+234|@[a-z0-9_]+\b(?!\.)/i', $visible((string) ($matched[0]['html'] ?? ''))));

/* ══ MENTORSHIP: the planned close ════════════════════════════════════════ */

$pid = (int) $db->query('SELECT id FROM mentorships ORDER BY id DESC LIMIT 1')->fetchColumn();
$portal = new MentorPortal(91);
$reset();
$portal->closeStep($pid, 'schedule', true);
$one = count($sent);
$portal->closeStep($pid, 'schedule', false);
$portal->closeStep($pid, 'schedule', true);
ck('trigger: scheduling the closing session tells BOTH people', $one === 2);
ck('trigger: …and unticking then reticking the box does not tell them again',
    count($sent) === 2);
$reset();
$portal->closeStep($pid, 'review', true);
ck('trigger: the other two closing steps send nothing', $sent === []);

/* ══ DIARY: a reply, and the mute ═════════════════════════════════════════ */

$repo = new DiaryRepository();
$slug = (string) $db->query('SELECT slug FROM articles ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($slug !== '') {
    $top = $repo->addThreadComment($slug, 'Ngozi Udo', 'The part I keep coming back to.', 'ngozi@x.co', 0, 0);
    $repo->setCommentStatus((int) $top['id'], 'published');

    $reset();
    $rep = $repo->addThreadComment($slug, 'Chidi Okonkwo', 'Same here — it changed how I plan.', 'chidi@x.co', (int) $top['id'], 0);
    ck('trigger: posting a reply mails nobody — the comment is still pending, '
     . 'and most of what is pending is spam that never gets published',
        $sent === []);

    $repo->setCommentStatus((int) $rep['id'], 'published');
    ck('trigger: publishing the reply mails the person replied to', count($sent) === 1);
    ck('trigger: …at their own address', str_contains(json_encode($sent), 'ngozi@x.co'));

    /* The mute link in that email. */
    $tok = $repo->replyMuteToken('ngozi@x.co');
    ck('trigger: the mute token is 40 hex and stable for one address',
        (bool) preg_match('/^[a-f0-9]{40}$/', $tok) && $repo->replyMuteToken('ngozi@x.co') === $tok);
    ck('trigger: issuing the token does not mute anybody by itself',
        !$repo->replyMuted('ngozi@x.co'));
    ck('trigger: a junk token mutes nothing', !$repo->muteRepliesByToken('nope'));
    ck('trigger: the link in the email mutes, once',
        $repo->muteRepliesByToken($tok) && $repo->replyMuted('ngozi@x.co')
        && !$repo->muteRepliesByToken($tok));

    $reset();
    $rep2 = $repo->addThreadComment($slug, 'Bola Member', 'Agreed.', 'bola@x.co', (int) $top['id'], 0);
    $repo->setCommentStatus((int) $rep2['id'], 'published');
    ck('trigger: a muted address stays muted on the next reply', $sent === []);

    /* Replying to yourself. */
    $db->exec("UPDATE diary_comments SET user_id = 91 WHERE id = " . (int) $top['id']);
    $reset();
    $mine = $repo->addThreadComment($slug, 'Adaeze Okafor', 'One more thought.', 'adaeze91@x.co', (int) $top['id'], 91);
    $repo->setCommentStatus((int) $mine['id'], 'published');
    ck('trigger: replying to your own comment does not mail you', $sent === []);
}

Mailer::fakeRoads(null);
