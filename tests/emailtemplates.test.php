<?php
/**
 * tests/emailtemplates.test.php — the designed transactional emails.
 *
 * Acceptance from modules/EMAILS.md §6.2: every template renders without a
 * notice from its documented vars, carries no unexecuted PHP, and stays under
 * 100 KB. Three more are added here because each is a way an email reaches a
 * person broken rather than fails loudly:
 *
 *   • A {placeholder} the caller did not fill is invisible in HTML but reads
 *     as "Welcome to Afrovanguard, {first_name}" in the subject line.
 *   • A missing plain-text part is what a screen reader and a text-only
 *     client get, and Mailer would otherwise guess one with strip_tags.
 *   • Escaping is the template's job (the spec forbids pre-escaping), so a
 *     name with an apostrophe or a tag in it has to come out safe.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/email/_layout.php';

/* Display-ready sample values, the way a caller must pass them. */
$sample = [
    'first_name' => 'Ada', 'member_id' => 'AV-2026-0418', 'joined_on' => '20 Oct 2026',
    'chapter' => 'Alimosho', 'portal_url' => 'https://afrovanguard.org.ng/portal/',
    'amount' => '₦25,000.00', 'total' => '₦25,000.00', 'receipt_no' => 'AVD-26-10-0582',
    'paid_on' => '20 Oct 2026', 'method' => 'Card · Paystack', 'designation' => 'Where most needed',
    'receipt_url' => 'https://afrovanguard.org.ng/give/receipt/AVD-26-10-0582.pdf',
    'period' => 'October 2026', 'items' => [['label' => 'Monthly dues', 'amount' => '₦15,000.00'],
                                            ['label' => 'Training fee', 'amount' => '₦10,000.00']],
    'code' => '481920', 'code_digits' => '481920', 'expires_minutes' => '10',
    'request_location' => 'Lagos, Nigeria', 'request_time' => '20 Oct 2026, 6:05 pm',
    'reset_url' => 'https://afrovanguard.org.ng/login/reset?t=abc',
    'event_name' => 'Leaders of Tomorrow Summit', 'event_date' => 'Saturday 14 Nov 2026',
    'event_time' => '10:00 am', 'venue' => 'CACENTRE, Alimosho', 'ticket_code' => 'AVT-9KQ2M4',
    'holder_name' => 'Ada Obi', 'qr_url' => 'https://afrovanguard.org.ng/q/AVT-9KQ2M4.png',
    'calendar_url' => 'https://afrovanguard.org.ng/events/ics/9', 'cancel_url' => 'https://afrovanguard.org.ng/events/cancel/9',
    'mentor_name' => 'Segun Bello', 'mentor_role' => 'Chapter lead', 'focus' => 'Leadership',
    'first_session' => 'Tuesday 27 Oct, 5:00 pm', 'safeguarding_email' => 'safeguarding@afrovanguard.org.ng',
    'application_ref' => 'AVM-26-0117', 'submitted_on' => '20 Oct 2026',
    'status_url' => 'https://afrovanguard.org.ng/mentorship/', 'training_url' => 'https://afrovanguard.org.ng/mentorship/mentor/?v=academy',
    'partner_name' => 'Amara Eze', 'started_on' => '1 Nov 2025', 'final_session' => '12 Dec 2026',
    'reflection_due' => '19 Dec 2026', 'plan_url' => 'https://afrovanguard.org.ng/mentorship/mentor/',
    'sender_name' => 'Chidi Okonkwo', 'sent_at' => '20 Oct 2026, 6:05 pm',
    'message_url' => 'https://afrovanguard.org.ng/portal/#messages',
    'replier_name' => 'Ngozi Udo', 'post_title' => 'What nine years taught us',
    'reply_excerpt' => 'This is the part I keep coming back to.', 'your_excerpt' => 'We publish the working.',
    'reply_url' => 'https://afrovanguard.org.ng/diary/nine-years/#c12', 'mute_url' => 'https://afrovanguard.org.ng/portal/#settings',
    'course_title' => 'Foundations of Incorruptible Leadership', 'course_url' => 'https://afrovanguard.org.ng/academy/foundations/',
    'course_length' => '6 weeks', 'lessons_count' => '12', 'starts_on' => '2 Nov 2026',
    'lesson_no' => '3', 'lesson_title' => 'Character before competency', 'lesson_duration' => '18 min',
    'lesson_url' => 'https://afrovanguard.org.ng/academy/foundations/learn/3',
    'lessons_done' => '4', 'lessons_total' => '12', 'progress_pct' => 33,
    'next_lesson_title' => 'Owning the outcome', 'next_url' => 'https://afrovanguard.org.ng/academy/foundations/learn/5',
    'class_title' => 'Live clinic: difficult conversations', 'class_date' => 'Thursday 5 Nov 2026',
    'class_time' => '6:00 pm WAT', 'host_name' => 'Adaeze Okafor', 'join_url' => 'https://meet.google.com/abc-defg-hij',
    'quiz_title' => 'Module 2 check', 'score' => '92%', 'pass_mark' => '80%',
    'certificate_id' => 'AVC-26-0091', 'completed_on' => '20 Oct 2026',
    'certificate_url' => 'https://afrovanguard.org.ng/academy/certificate/AVC-26-0091.pdf',
    'verify_url' => 'https://afrovanguard.org.ng/academy/verify.php?id=AVC-26-0091',
];

$templates = array_map(
    fn($p) => basename($p, '.php'),
    glob(AV_ROOT . '/lib/email/templates/*.php') ?: []
);
sort($templates);

ck('email: all twenty templates are installed (eighteen designed, plus the two VanQuest mails)', count($templates) === 20);

foreach ($templates as $t) {
    $notices = [];
    set_error_handler(function (int $no, string $str) use (&$notices) { $notices[] = $str; return true; });
    $r = null; $threw = '';
    try { $r = av_email_render($t, $sample); } catch (Throwable $e) { $threw = $e->getMessage(); }
    restore_error_handler();

    ck("email/$t: renders from its documented vars",
        $threw === '' && is_array($r) && ($r['html'] ?? '') !== '');
    ck("email/$t: without a notice or warning" . ($notices ? ' — got: ' . $notices[0] : ''), $notices === []);
    if (!is_array($r)) continue;

    ck("email/$t: no unexecuted PHP reaches the recipient",
        !str_contains($r['html'], '<?'));
    ck("email/$t: under 100 KB (Gmail clips a message above ~102 KB)",
        strlen($r['html']) < 100 * 1024);
    ck("email/$t: the subject is filled in, with no {placeholder} left",
        $r['subject'] !== '' && !preg_match('/\{[a-z_]+\}/', $r['subject']));
    ck("email/$t: carries a real plain-text part from the renderer, not a guess",
        strlen(trim($r['text'])) > 40);
    ck("email/$t: the seal and any illustration are absolute https URLs",
        !preg_match('/src="(?!https:\/\/)/', $r['html']));
}

/* Escaping is the template's job; the caller must never pre-escape. */
$xss = $sample;
$xss['first_name'] = '<script>alert(1)</script>O’Brien';
$r = av_email_render('welcome', $xss);
ck('email: a name the caller did not escape comes out escaped, once',
    !str_contains($r['html'], '<script>') && str_contains($r['html'], '&lt;script&gt;')
    && !str_contains($r['html'], '&amp;lt;'));

ck('email: an unknown template is refused rather than guessed at', (function () {
    try { av_email_render('../../../etc/passwd', []); return false; }
    catch (Throwable $e) { return true; }
})());

/* The frames: Academy on the card, everything else centred (EMAILS.md §4). */
$acad = av_email_render('academy_certificate', $sample)['html'];
$other = av_email_render('welcome', $sample)['html'];
ck('email: Academy templates use the card frame, the rest the centre frame',
    $acad !== '' && $other !== '' && $acad !== $other
    && str_contains($acad, 'Academy'));

/* ---------------------------------------------------------------------------
 * Outlook fallbacks for the illustrations.
 *
 * The Word rendering engine behind Outlook for Windows freezes an animated GIF
 * on its first frame. Frame 1 of these illustrations is the blank start of a
 * draw-on animation — heart, bubbles and check measure 0.0% ink — so an Outlook
 * reader of a donation receipt, a diary reply or a mentor approval would see an
 * empty 100x75 hole. Each illustration therefore ships a PNG of its last frame,
 * served to Outlook alone through a downlevel-revealed conditional. Every other
 * client still gets the untouched GIF.
 * ------------------------------------------------------------------------- */
$artDir = __DIR__ . '/../assets/site/email';
$seen = [];
foreach ($templates as $t) {
    $html = av_email_render($t, $sample)['html'];
    if (!preg_match_all('~/([a-z]+)\.gif~', $html, $m)) {
        ck("email/$t: carries no illustration, so needs no Outlook fallback",
            !str_contains($html, '[if mso]><img'));
        continue;
    }
    foreach (array_unique($m[1]) as $art) {
        $seen[$art] = true;
        ck("email/$t: the $art GIF is hidden from Outlook, not just sent to it",
            str_contains($html, '<!--[if !mso]><!--><img class="av-art" src="')
            && str_contains($html, "/$art.gif"));
        // The designed illustrations sit in a 100x75 box; VanQuest's pixel art
        // has its own sizes. Either way the PNG must take the GIF's exact box.
        preg_match('~src="https://[^"]+/' . $art . '\.gif" width="(\d+)" height="(\d+)"~', $html, $box);
        ck("email/$t: Outlook is served $art.png instead, in the same box as the GIF",
            $box !== [] && (str_starts_with($t, 'vanquest_') || [$box[1], $box[2]] === ['100', '75'])
            && preg_match('~\[if mso\]><img class="av-art" src="https://[^"]+/' . $art
                . '\.png" width="' . $box[1] . '" height="' . $box[2] . '"~', $html) === 1);
    }
}

ck('email: every illustration the templates use has a PNG beside it on disk',
    $seen !== [] && !array_filter(array_keys($seen),
        fn ($a) => !is_file("$artDir/$a.png")));

/* A fallback that is itself blank would fix nothing. */
foreach (array_keys($seen) as $art) {
    $im = @imagecreatefrompng("$artDir/$art.png");
    $ink = 0;
    $cells = 0;
    if ($im) {
        for ($y = 0; $y < imagesy($im); $y += 2) {
            for ($x = 0; $x < imagesx($im); $x += 2) {
                $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
                $cells++;
                if ($c['alpha'] > 100) { continue; }
                if (($c['red'] + $c['green'] + $c['blue']) / 3 < 235) { $ink++; }
            }
        }
    }
    ck("email: the $art fallback is the finished drawing, not a blank frame",
        $im !== false && $cells > 0 && (100 * $ink / $cells) > 3.0);
}

/* ---------------------------------------------------------------------------
 * VanQuest: its two mails, on their own frame. The module that sends them is
 * tested in tests/vanquest.test.php.
 * ------------------------------------------------------------------------- */
$vq = av_email_render('vanquest_welcome', ['seat_url' => 'https://vquest.afrovanguard.org.ng/seat/?t=x']);
ck('vanquest: the welcome renders on the VanQuest frame, not the Afrovanguard centre frame',
    str_contains($vq['html'], 'vqmark.png') && !str_contains($vq['html'], 'email-seal.png'));
ck('vanquest: the welcome asks them to confirm their seat, twice, by the seat link',
    $vq['subject'] === 'Confirm your seat in the VanQuest community'
    && substr_count($vq['html'], 'href="https://vquest.afrovanguard.org.ng/seat/?t=x"') >= 4);
ck('vanquest: one wording for the one action — both buttons say "Confirm your seat"',
    substr_count($vq['html'], 'Confirm your seat →') === 2 && !str_contains($vq['html'], 'Get your seat'));
ck('vanquest: the footer signs as VanQuest, an Afrovanguard initiative — not as Afrovanguard',
    str_contains($vq['html'], 'font-weight:600;">VanQuest</span><br>An Afrovanguard initiative') && !str_contains($vq['html'], 'font-weight:600;">Afrovanguard</span>'));
ck('vanquest: …and its plain-text part is signed VanQuest', str_ends_with(trim($vq['text']), '— VanQuest'));
ck('vanquest: every picture sits on a dark tile, so a mail app that holds pictures back shows no white boxes',
    preg_match_all('~<img [^>]*style="[^"]*background:#[0-9a-f]{6}~', $vq['html']) === preg_match_all('~<img ~', $vq['html']));
ck('vanquest: the welcome explains the game and shows their status',
    str_contains($vq['text'], 'HOW THE GAME WORKS') && str_contains($vq['text'], 'NOT CONFIRMED YET'));
$top = array_slice(json_decode((string) file_get_contents(AV_ROOT . '/lib/quest/ranks.json'), true)['ranks'], -1)[0]['title'];
ck('vanquest: the rank names come from lib/quest/ranks.json',
    str_contains($vq['html'], 'title="' . $top . '"') && str_contains($vq['html'], 'Ordinary to ' . $top));
$people = [['first_name' => 'Chidi', 'letter' => 'O', 'avatar' => 'https://afrovanguard.org.ng/vanquest/api.php?action=avatar&a=0a0b0c0d&s=m'],
           ['first_name' => 'Amara', 'letter' => 'A', 'avatar' => 'https://afrovanguard.org.ng/vanquest/api.php?action=avatar&a=01020304&s=f']];
$vqP = av_email_render('vanquest_welcome', ['people' => $people, 'players' => 9, 'metrics' => ['volunteers_active' => 123]]);
ck('vanquest: the welcome shows the people already in, by name and avatar',
    str_contains($vqP['html'], '>Chidi<') && str_contains($vqP['html'], 'action=avatar&amp;a=0a0b0c0d')
    && str_contains($vqP['text'], 'Chidi, Amara and 7 others'));
ck('vanquest: the mails carry no community numbers (those are on the community page)',
    !str_contains($vqP['html'], '123') && !str_contains($vqP['text'], 'ACTIVE VOLUNTEERS'));
$vqB = av_email_render('vanquest_welcome', ['placement' => 'B', 'xp' => 340]);
ck('vanquest: a placement reads as a potential level that an interview confirms',
    str_contains($vqB['html'], 'Potential Becoming') && str_contains($vqB['html'], 'An interview confirms it.'));
ck('vanquest: a letter above D is not a placement and is ignored',
    !str_contains(av_email_render('vanquest_welcome', ['placement' => 'E'])['html'], 'Potential'));
ck('vanquest: a new link for a confirmed seat says so and leads into the community',
    str_contains(av_email_render('vanquest_welcome', ['seated' => true, 'first_name' => 'Ada'])['html'], 'Enter the community'));

$on = av_email_render('vanquest_onboard', ['first_name' => 'Ada', 'community_url' => 'https://vquest.afrovanguard.org.ng/community/?t=y']);
ck('vanquest: onboarding greets them by name and leads into the community',
    str_contains($on['subject'], 'Ada') && str_contains($on['html'], 'Welcome to the community, Ada.')
    && str_contains($on['html'], 'Enter the community') && !preg_match('/lobby/i', $on['html']));
ck('vanquest: onboarding picks the next step: the placement test when not yet placed',
    str_contains($on['subject'], 'find your starting level') && str_contains($on['html'], '/placement/'));
$onA = av_email_render('vanquest_onboard', ['first_name' => 'Ada', 'placement' => 'A', 'people' => $people, 'joined_since' => 2]);
ck('vanquest: and the interview once placed, with who joined since them',
    str_contains($onA['subject'], 'your interview') && str_contains($onA['text'], 'JOINED SINCE YOU')
    && str_contains($onA['text'], 'Chidi and Amara joined after you'));
ck('vanquest: says XP is not Quest points', str_contains($vq['text'], 'not Quest points') && str_contains($on['text'], 'not Quest points'));
