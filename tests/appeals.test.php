<?php
/**
 * tests/appeals.test.php — fundraising appeals: campaigns, needs, money, SEO.
 *
 * The properties that matter here are mostly about MONEY BEING SAID ACCURATELY
 * in public, which is a different risk from money being computed correctly:
 *
 *   • An appeal never holds its own running total. It reads the verified
 *     donation store, so the page and the payment record cannot drift apart.
 *   • Offline gifts are recorded and reported SEPARATELY, because a total that
 *     silently mixes "Paystack verified this" with "a colleague typed this"
 *     cannot be broken apart later, and a donor will ask.
 *   • A draft is not reachable — not by slug, not through the index, not
 *     through the share card, not through the feed.
 *   • A slug never changes once published. It is on a poster and in a search
 *     index, and an appeal that re-addresses itself on a typo fix is an appeal
 *     with no inbound links.
 *   • Needs are idempotent per period, so a morning routine that runs twice
 *     corrects today rather than posting it twice.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$apReset = static function (): void {
    Appeals::ensure();
    try {
        $pdo = Database::pdo();
        foreach (['av_appeal_needs', 'av_appeal_updates', 'av_appeal_tiers', 'av_appeals'] as $t) {
            $pdo->exec('DELETE FROM ' . $t);
        }
    } catch (Throwable $e) {}
    Appeals::forgetMoney();
};

/* ══ Slugs ════════════════════════════════════════════════════════════════ */

$apReset();
/* Yoruba and Igbo diacritics fold to their base letter rather than vanishing.
   iconv's //TRANSLIT drops them in the C locale, which turned "Ìlorin" into
   "lorin" — and that would have been the permanent public address. */
ck('appeals: a Yoruba diacritic folds rather than disappearing',
   Appeals::slugify('Clean water for Ìlorin Community School') === 'clean-water-for-ilorin-community-school');
ck('appeals: combining marks fold too',
   Appeals::slugify('Ẹ̀kọ́ Ọjà Rebuild') === 'eko-oja-rebuild');
ck('appeals: an ampersand becomes a word, not a gap',
   Appeals::slugify('Ada & Sons') === 'ada-and-sons');
ck('appeals: a title with nothing sluggable in it yields an empty slug',
   Appeals::slugify('日本語') === '' && Appeals::slugify('   ') === '');

$apA = Appeals::save(['title' => 'Clean water for Ìlorin Community School', 'status' => 'live',
                      'goal_ngn' => 4000000], 'tester');
ck('appeals: an appeal saves and gets an id', $apA > 0);
$apSlug = (string) Appeals::byId($apA)['slug'];
ck('appeals: and a slug derived from the title', $apSlug === 'clean-water-for-ilorin-community-school');

Appeals::save(['id' => $apA, 'title' => 'Clean water for Ilorin Community School (2026 appeal)'], 'tester');
ck('appeals: retitling NEVER moves the address',
   (string) Appeals::byId($apA)['slug'] === $apSlug);

/* Two appeals may honestly share a title; they may not share an address. */
$apDup1 = Appeals::save(['title' => 'December outreach'], 'tester');
$apDup2 = Appeals::save(['title' => 'December outreach'], 'tester');
ck('appeals: a colliding title gets its own slug rather than overwriting',
   (string) Appeals::byId($apDup1)['slug'] !== (string) Appeals::byId($apDup2)['slug']);

/* ══ Money is read, never held ════════════════════════════════════════════ */

$apReset();
$apB = Appeals::save(['title' => 'Borehole appeal', 'status' => 'live', 'goal_ngn' => 1000000,
                      'offline_ngn' => 250000], 'tester');
$apRow = Appeals::byId($apB);
$apSt  = Appeals::state($apRow);
ck('appeals: an offline gift counts toward what has been raised',
   $apSt['raised'] === 250000 && $apSt['offline'] === 250000);
ck('appeals: and is reported separately from what the site verified',
   $apSt['online'] === 0);
ck('appeals: the percentage is of the combined figure',
   $apSt['percent'] === 25);
ck('appeals: remaining is what is left, not what has come in',
   $apSt['remaining'] === 750000);

/* No column on av_appeals holds a running online total — that is the whole
   point, and a well-meaning future change could add one without noticing. */
$apCols = [];
try {
    foreach (Database::pdo()->query('SELECT * FROM av_appeals LIMIT 1') ?: [] as $r) { $apCols = array_keys($r); break; }
} catch (Throwable $e) {}
ck('appeals: the table holds no online-donation total of its own',
   !in_array('raised_ngn', $apCols, true) && !in_array('raised', $apCols, true)
   && !in_array('donors', $apCols, true));

/* A goal of zero means an OPEN appeal, not "0% of nothing". */
$apOpen = Appeals::save(['title' => 'A standing programme', 'status' => 'live', 'goal_ngn' => 0,
                         'offline_ngn' => 80000], 'tester');
$apOpenSt = Appeals::state(Appeals::byId($apOpen));
ck('appeals: an appeal with no goal reports no percentage rather than zero',
   $apOpenSt['percent'] === null && $apOpenSt['open'] === true);
ck('appeals: and still reports what it has raised', $apOpenSt['raised'] === 80000);

/* A cap on a stray zero. */
$apHuge = Appeals::save(['title' => 'Typo appeal', 'goal_ngn' => 999999999999], 'tester');
ck('appeals: an impossible figure is clamped rather than stored',
   (int) Appeals::byId($apHuge)['goal_ngn'] <= 500000000);
ck('appeals: a negative figure becomes zero, never a negative goal',
   Appeals::money(-50000) === 0);

/* ══ Matched giving is a pledge, not money ════════════════════════════════ */

$apReset();
$apM = Appeals::save(['title' => 'Matched appeal', 'status' => 'live', 'goal_ngn' => 5000000,
                      'offline_ngn' => 1200000, 'match_ngn' => 2000000,
                      'match_sponsor' => 'A Foundation',
                      'match_until' => gmdate('Y-m-d', strtotime('+5 days'))], 'tester');
$apMs = Appeals::state(Appeals::byId($apM));
ck('appeals: a live match is reported as live', $apMs['match_live'] === true);
ck('appeals: the pledge is NOT added to what has been raised', $apMs['raised'] === 1200000);
ck('appeals: what is left to match is the ceiling less what has come in',
   $apMs['match_left'] === 800000);

/* An expired pledge stops being advertised — the page must not promise
   doubling that a sponsor is no longer offering. */
Appeals::save(['id' => $apM, 'match_until' => gmdate('Y-m-d', strtotime('-1 day'))], 'tester');
ck('appeals: an expired match stops being advertised',
   Appeals::state(Appeals::byId($apM))['match_live'] === false);

/* Once the match is exhausted there is nothing left to offer. */
Appeals::save(['id' => $apM, 'match_until' => gmdate('Y-m-d', strtotime('+5 days')),
               'offline_ngn' => 2500000], 'tester');
ck('appeals: a match that has been used up reports nothing left',
   Appeals::state(Appeals::byId($apM))['match_left'] === 0);

/* ══ Needs ════════════════════════════════════════════════════════════════ */

$apReset();
$apN = Appeals::save(['title' => 'Needs appeal', 'status' => 'live', 'goal_ngn' => 500000], 'tester');

$r1 = Appeals::postNeed($apN, ['cadence' => 'daily', 'title' => 'Hot meals',
                               'unit_cost' => 450, 'units_target' => 40, 'unit_label' => 'hot meals'], 'tester');
ck('appeals: a need priced by the unit computes its own target',
   $r1['ok'] && (int) Appeals::needsFor($apN)[0]['target_ngn'] === 18000);

$r2 = Appeals::postNeed($apN, ['cadence' => 'daily', 'title' => 'Hot meals, corrected',
                               'unit_cost' => 500, 'units_target' => 40], 'tester');
ck('appeals: posting again for the same day CORRECTS it rather than adding a second',
   $r2['ok'] && !empty($r2['updated']) && $r2['id'] === $r1['id']);
ck('appeals: and there is still exactly one daily need', count(Appeals::needsFor($apN)) === 1);
ck('appeals: carrying the corrected figure', (int) Appeals::needsFor($apN)[0]['target_ngn'] === 20000);

$r3 = Appeals::postNeed($apN, ['cadence' => 'weekly', 'title' => 'Diesel', 'target_ngn' => 95000], 'tester');
ck('appeals: a weekly need is a different row from the daily one',
   $r3['ok'] && $r3['id'] !== $r1['id'] && count(Appeals::needsFor($apN)) === 2);

ck('appeals: a need with no figure at all is refused',
   !Appeals::postNeed($apN, ['cadence' => 'once', 'title' => 'Vague'], 'tester')['ok']);
ck('appeals: a need with no description is refused',
   !Appeals::postNeed($apN, ['cadence' => 'once', 'target_ngn' => 5000], 'tester')['ok']);

/* Yesterday's lunch cannot still be bought. A need whose window has closed is
   not "current", however unmet it is. */
$apYesterday = Appeals::periodFor('daily', gmdate('Y-m-d', strtotime('-1 day')));
Appeals::postNeed($apN, ['cadence' => 'daily', 'period' => $apYesterday,
                         'title' => 'Yesterday', 'target_ngn' => 7000], 'tester');
$apCur = Appeals::currentNeeds($apN);
ck('appeals: a lapsed need is not offered as current',
   !in_array('Yesterday', array_column($apCur, 'title'), true));
ck('appeals: but it is still on the record', count(Appeals::needsFor($apN)) === 3);
ck('appeals: today before this week — the most urgent first',
   ($apCur[0]['cadence'] ?? '') === 'daily');
ck('appeals: the period helper matches ISO weeks',
   Appeals::periodFor('weekly', '2026-09-28') === gmdate('o-\WW', strtotime('2026-09-28')));

/* Meeting a need does not hide it from its own appeal — "Today · met, thank
   you" is worth showing, and a need that vanishes the moment it is funded
   makes the page look like nothing was ever asked for. It DOES drop off the
   cross-appeal board, where the question is only "what still needs paying". */
Appeals::meetNeed((int) $r1['id'], null, 'tester');
$apMet = Appeals::currentNeeds($apN);
ck('appeals: a met need stays on its own appeal, marked met',
   count($apMet) === 2
   && ($apMet[0]['status'] ?? '') === 'met');
ck('appeals: but it is gone from the board of what still needs paying',
   !in_array('Hot meals, corrected', array_column(Appeals::currentNeedsAll(20), 'title'), true));

/* ══ Draft appeals are not reachable, by any route ════════════════════════ */

$apReset();
$apLive  = Appeals::save(['title' => 'A live appeal', 'status' => 'live', 'goal_ngn' => 100000], 'tester');
$apDraft = Appeals::save(['title' => 'An unfinished draft', 'status' => 'draft'], 'tester');

ck('appeals: a draft is not public', Appeals::isPublic(Appeals::byId($apDraft)) === false);
ck('appeals: a live appeal is', Appeals::isPublic(Appeals::byId($apLive)) === true);
ck('appeals: a draft is not in the published list',
   !in_array('An unfinished draft', array_column(Appeals::published(), 'title'), true));
ck('appeals: a draft contributes nothing to the public summary',
   Appeals::summary()['appeals'] === 1);
ck('appeals: and nothing that does not exist is public', Appeals::isPublic(null) === false);

/* Closed and funded appeals stay READABLE — people follow old links, and how
   it ended is the most persuasive thing on the site. */
Appeals::setStatus($apLive, 'funded', 'tester');
ck('appeals: a funded appeal stays readable', Appeals::isPublic(Appeals::byId($apLive)) === true);
Appeals::setStatus($apLive, 'closed', 'tester');
ck('appeals: so does a closed one', Appeals::isPublic(Appeals::byId($apLive)) === true);

/* ══ Deleting ═════════════════════════════════════════════════════════════ */

$apReset();
$apDel = Appeals::save(['title' => 'Nothing given yet', 'status' => 'live'], 'tester');
ck('appeals: an appeal nobody has given to can be deleted',
   !empty(Appeals::delete($apDel, 'tester')['ok']));

$apKeep = Appeals::save(['title' => 'Money came in', 'status' => 'live', 'offline_ngn' => 50000], 'tester');
$apR = Appeals::delete($apKeep, 'tester');
ck('appeals: one that has received money cannot be deleted', empty($apR['ok']));
ck('appeals: and the refusal explains what to do instead',
   str_contains((string) ($apR['error'] ?? ''), 'Close it instead'));
ck('appeals: it is genuinely still there', Appeals::byId($apKeep) !== null);

/* ══ Escaping and safety in fields staff type ═════════════════════════════ */

$apReset();
$apX = Appeals::save([
    'title' => 'Safe appeal', 'status' => 'live',
    'cover_url' => 'javascript:alert(1)',
    'video_url' => 'data:text/html,<script>alert(1)</script>',
    'gallery'   => "https://ok.test/a.jpg\njavascript:alert(2)\n/local/b.jpg",
], 'tester');
$apXr = Appeals::byId($apX);
ck('appeals: a javascript: cover URL is refused outright', (string) $apXr['cover_url'] === '');
ck('appeals: so is a data: video URL', (string) $apXr['video_url'] === '');
ck('appeals: the gallery keeps only the real URLs',
   $apXr['gallery'] === ['https://ok.test/a.jpg', '/local/b.jpg']);

/* ══ SEO ══════════════════════════════════════════════════════════════════ */

$apReset();
$apS = Appeals::save(['title' => 'Water for Ìlorin', 'tagline' => 'A borehole for 620 children',
                      'status' => 'live', 'goal_ngn' => 4000000, 'offline_ngn' => 1000000], 'tester');
$apSr = Appeals::byId($apS);
$apMeta = Appeals::meta($apSr);
ck('appeals: the meta description carries the live progress',
   str_contains($apMeta['desc'], '25%') && str_contains($apMeta['desc'], '₦4,000,000'));
ck('appeals: the title is bounded so search does not truncate it mid-word',
   mb_strlen($apMeta['title']) <= 80);

$apTypes = array_column(Appeals::jsonLd($apSr), '@type');
ck('appeals: an Article node is emitted (Google renders these)',
   in_array('Article', $apTypes, true));
ck('appeals: a DonateAction node is emitted',
   in_array('DonateAction', $apTypes, true));
/* Google retired FAQ rich results in May 2026. Marking one up for the snippet
   is now work that buys nothing, so it is deliberately absent. */
ck('appeals: no FAQPage — the rich result was retired in May 2026',
   !in_array('FAQPage', $apTypes, true));
/* A plain appeal is not an Event. Emitting one without a startDate is invalid
   markup, and Google rejects the lot rather than the offending node. */
ck('appeals: a non-event appeal emits no Event node',
   !in_array('Event', $apTypes, true));

$apEv = Appeals::save(['title' => 'December outreach', 'kind' => 'event', 'status' => 'live',
                       'event_start' => '2026-12-18 10:00', 'event_venue' => 'CACENTRE Agege'], 'tester');
$apEvTypes = array_column(Appeals::jsonLd(Appeals::byId($apEv)), '@type');
ck('appeals: an event appeal DOES emit an Event node', in_array('Event', $apEvTypes, true));

$apEvNoDate = Appeals::save(['title' => 'Undated event', 'kind' => 'event', 'status' => 'live'], 'tester');
ck('appeals: an event with no start date emits no Event node, rather than an invalid one',
   !in_array('Event', array_column(Appeals::jsonLd(Appeals::byId($apEvNoDate)), '@type'), true));

/* The house convention: one Organization node, referenced by @id, emitted once
   by schema_org(). Restating it here would give the graph two publishers. */
$apLd = Appeals::jsonLd($apSr);
ck('appeals: the organisation is referenced by @id, not restated',
   ($apLd[0]['publisher']['@id'] ?? '') !== '' && !isset($apLd[0]['publisher']['name']));

/* ══ Sharing ══════════════════════════════════════════════════════════════ */

$apShare = Appeals::shareLinks($apSr);
foreach (['whatsapp', 'x', 'facebook', 'telegram', 'linkedin', 'email', 'copy'] as $apNet) {
    ck('appeals: there is a share link for ' . $apNet, !empty($apShare[$apNet]));
}
ck('appeals: each network carries its own utm_source, so the difference is measurable',
   str_contains($apShare['whatsapp'], 'utm_source%3Dwhatsapp')
   && str_contains($apShare['facebook'], 'utm_source%3Dfacebook'));
ck('appeals: the copy-link is the clean address with no tracking on it',
   !str_contains($apShare['copy'], 'utm_') && str_contains($apShare['copy'], '/give/'));
ck('appeals: the canonical URL carries no tracking either',
   !str_contains(Appeals::url($apSr), 'utm_'));

$apQr = Appeals::qrSvg($apSr);
ck('appeals: a QR renders as vector, so a poster survives being enlarged',
   str_contains($apQr, '<svg') && !str_contains($apQr, '<image'));

/* ══ Cross-appeal needs ═══════════════════════════════════════════════════ */

$apReset();
$apOne = Appeals::save(['title' => 'Appeal one', 'status' => 'live', 'goal_ngn' => 1000000, 'offline_ngn' => 100000], 'tester');
$apTwo = Appeals::save(['title' => 'Appeal two', 'status' => 'live', 'goal_ngn' => 1000000, 'offline_ngn' => 900000], 'tester');
$apHid = Appeals::save(['title' => 'Draft appeal', 'status' => 'draft'], 'tester');
Appeals::postNeed($apOne, ['cadence' => 'daily', 'title' => 'One needs lunch', 'target_ngn' => 10000], 'tester');
Appeals::postNeed($apTwo, ['cadence' => 'daily', 'title' => 'Two needs lunch', 'target_ngn' => 12000], 'tester');
Appeals::postNeed($apHid, ['cadence' => 'daily', 'title' => 'Secret need', 'target_ngn' => 9000], 'tester');

$apAll = Appeals::currentNeedsAll(10);
ck('appeals: needs are gathered across every live appeal', count($apAll) === 2);
ck('appeals: a draft appeal contributes no needs to the public board',
   !in_array('Secret need', array_column($apAll, 'title'), true));
ck('appeals: each need names the appeal it belongs to',
   ($apAll[0]['appeal']['title'] ?? '') !== '' && ($apAll[0]['appeal']['url'] ?? '') !== '');
/* Furthest from its target first — the one that needs the help most. */
ck('appeals: the appeal furthest from its goal is listed first',
   ($apAll[0]['appeal']['title'] ?? '') === 'Appeal one');
ck('appeals: the board totals what today actually costs',
   Appeals::needsTotal()['today'] === 22000);


/* ══ Recurring giving ═════════════════════════════════════════════════════ */

$apReset();
$apRec = Appeals::save(['title' => 'Recurring appeal', 'status' => 'live', 'goal_ngn' => 1000000], 'tester');

/* Every refusal path, because this one ends at a payment provider and a
   half-validated subscription is somebody's bank account on a schedule. */
ck('appeals: a recurring gift needs a real email',
   !Appeals::startRecurring($apRec, 'not-an-email', 5000, 'monthly')['ok']);
ck('appeals: an interval we do not offer is refused',
   !Appeals::startRecurring($apRec, 'a@b.co', 5000, 'hourly')['ok']);
ck('appeals: below the minimum donation is refused',
   !Appeals::startRecurring($apRec, 'a@b.co', 50, 'monthly')['ok']);
$apDraftRec = Appeals::save(['title' => 'Draft rec', 'status' => 'draft'], 'tester');
ck('appeals: a draft appeal cannot take a subscription',
   !Appeals::startRecurring($apDraftRec, 'a@b.co', 5000, 'monthly')['ok']);
$apNoPay = Appeals::startRecurring($apRec, 'a@b.co', 5000, 'monthly');
ck('appeals: with no payment provider it refuses in words a donor can act on',
   !$apNoPay['ok'] && str_contains((string) $apNoPay['error'], 'one-off'));

/* A subscription is recorded when the WEBHOOK confirms it, never when somebody
   presses a button — a subscription written down at the click is one that may
   never have been paid for. */
ck('appeals: a confirmed subscription is recorded',
   Appeals::recordSubscription(['sub_code' => 'SUB_one', 'email' => 'Ada@Example.test', 'name' => 'Ada Obi',
                                'amount_ngn' => 5000, 'interval' => 'monthly', 'appeal_id' => $apRec]));
ck('appeals: a webhook retry does not create a second pledge',
   Appeals::recordSubscription(['sub_code' => 'SUB_one', 'email' => 'ada@example.test',
                                'amount_ngn' => 5000, 'appeal_id' => $apRec])
   && Appeals::recurringFor($apRec)['count'] === 1);
ck('appeals: a subscription with no code is refused',
   !Appeals::recordSubscription(['sub_code' => '', 'email' => 'a@b.co', 'appeal_id' => $apRec]));
ck('appeals: a subscription with no appeal to belong to is refused',
   !Appeals::recordSubscription(['sub_code' => 'SUB_x', 'email' => 'a@b.co', 'appeal_id' => 0]));

Appeals::recordSubscription(['sub_code' => 'SUB_two', 'email' => 'bode@example.test',
                             'amount_ngn' => 20000, 'interval' => 'annually', 'appeal_id' => $apRec]);
Appeals::noteRecurringCharge('SUB_one', 5000);
Appeals::noteRecurringCharge('SUB_one', 5000);
$apR = Appeals::recurringFor($apRec);
ck('appeals: collected cycles accumulate against the pledge',
   (int) $apR['rows'][1]['charges'] === 2 && (int) $apR['rows'][1]['total_ngn'] === 10000);
/* ₦5,000 monthly is ₦60,000 a year; ₦20,000 annually is ₦20,000. */
ck('appeals: the annualised value weights each interval correctly',
   $apR['annualised'] === 80000, '(' . $apR['annualised'] . ')');
ck('appeals: a pledge is NOT counted as money already raised',
   Appeals::state(Appeals::byId($apRec))['raised'] === 0);

/* ══ Telling donors, and letting them out ═════════════════════════════════ */

$apReset();
$apMail = Appeals::save(['title' => 'Mailing appeal', 'status' => 'live', 'goal_ngn' => 500000], 'tester');
$apMailRow = Appeals::byId($apMail);
$apSlugM = (string) $apMailRow['slug'];

/* Seed the donation store the way process-donation.php writes it. */
$apDons = [
    ['campaign' => $apSlugM, 'email' => 'one@example.test',  'name' => 'One Person', 'amount' => 10000],
    ['campaign' => $apSlugM, 'email' => 'ONE@example.test',  'name' => 'One Again',  'amount' => 5000],
    ['campaign' => $apSlugM, 'email' => 'two@example.test',  'name' => 'Two Person', 'amount' => 10000],
    ['campaign' => $apSlugM, 'email' => 'not an email',      'name' => 'Broken',     'amount' => 1000],
    ['campaign' => 'a-different-appeal', 'email' => 'elsewhere@example.test', 'name' => 'Nope', 'amount' => 9000],
];
@file_put_contents(av_private_path('donations.json'), json_encode([
    'version' => 2, 'campaigns' => [$apSlugM => ['raised' => 25000, 'goal' => 500000, 'donors' => 3]],
    'totals' => ['donors' => 3, 'raised_ngn' => 25000], 'donations' => $apDons,
]));
Appeals::forgetMoney();

$apPeople = Appeals::updateRecipients($apMailRow);
/* Giving to the borehole is not permission to be told about the outreach.
   Treating it as one is how a charity's mail gets marked as spam by the very
   people who supported it. */
ck('appeals: only people who gave to THIS appeal are writable-to',
   !isset($apPeople['elsewhere@example.test']));
ck('appeals: one address counts once however many times they gave',
   count($apPeople) === 2);
ck('appeals: a malformed address is dropped rather than queued forever',
   !isset($apPeople['not an email']));

Appeals::recordSubscription(['sub_code' => 'SUB_m', 'email' => 'giver@example.test',
                             'amount_ngn' => 3000, 'appeal_id' => $apMail]);
ck('appeals: a recurring giver is on the list even without a one-off donation',
   isset(Appeals::updateRecipients($apMailRow)['giver@example.test']));

$apTok = Appeals::unsubToken('one@example.test', $apMail);
ck('appeals: a forged unsubscribe token is refused',
   !Appeals::unsubscribe('one@example.test', $apMail, 'not-the-token'));
ck('appeals: an unsubscribe token from a DIFFERENT appeal does not work here',
   !Appeals::unsubscribe('one@example.test', $apMail, Appeals::unsubToken('one@example.test', $apMail + 1)));
ck('appeals: the real token works', Appeals::unsubscribe('one@example.test', $apMail, $apTok));
ck('appeals: and they come off the list',
   !isset(Appeals::updateRecipients($apMailRow)['one@example.test']));
ck('appeals: unsubscribing twice is not an error',
   Appeals::unsubscribe('one@example.test', $apMail, $apTok));
/* Out of one appeal is not out of everything. */
ck('appeals: leaving one appeal does not silence them on another',
   !Appeals::isUnsubscribed('one@example.test', $apMail + 999));
ck('appeals: the unsubscribe URL carries its own token',
   str_contains(Appeals::unsubUrl('one@example.test', $apMail), 't=' . $apTok));

/* No address is stored in the clear on either bookkeeping table. */
$apClear = (int) Database::pdo()->query("SELECT COUNT(*) FROM av_appeal_unsubs WHERE email_key LIKE '%@%'")->fetchColumn();
ck('appeals: the unsubscribe list holds no readable addresses', $apClear === 0);

/* The send is a queue that drains, and it never writes to anybody twice. */
$apUp = Appeals::postUpdate($apMail, ['title' => 'Progress', 'body' => 'Half way.'], 'tester');
$apSend = Appeals::mailUpdate((int) $apUp['id']);
ck('appeals: mailing is refused outright while outbound mail is switched off',
   !$apSend['ok'] && str_contains((string) $apSend['error'], 'switched off'));

ck('appeals: an update that does not exist cannot be mailed',
   !Appeals::mailUpdate(999999)['ok']);


/* ══ Afrovanguard-specific: sponsoring a Vanguard ═════════════════════════ */

$apReset();
ck('appeals: only published flagship programmes may be filed against',
   (string) Appeals::byId(Appeals::save(['title' => 'Made-up programme', 'project' => 'not-a-project'], 'tester'))['project'] === '');
ck('appeals: a real programme is kept',
   (string) Appeals::byId(Appeals::save(['title' => 'Techome appeal', 'project' => 'techhome', 'status' => 'live'], 'tester'))['project'] === 'techhome');
ck('appeals: a project page sees only its own live appeals',
   count(Appeals::forProject('techhome')) === 1 && Appeals::forProject('mediapro') === []);
/* The list is read from what actually renders /projects/<slug>/, so it cannot
   offer a programme whose page could never show the appeal. */
ck('appeals: the programme list comes from the real project content',
   isset(Appeals::projects()['techhome']) && isset(Appeals::projects()['ngv'])
   && !isset(Appeals::projects()['a-programme-that-does-not-exist']));
ck('appeals: an unknown project asks for nothing',
   Appeals::forProject('nonsense') === [] && Appeals::forProject('') === []);
/* A draft filed under a programme must not surface on that programme's page. */
Appeals::save(['title' => 'Draft Techome appeal', 'project' => 'techhome', 'status' => 'draft'], 'tester');
ck('appeals: a draft never reaches a project page', count(Appeals::forProject('techhome')) === 1);

$apSpon = Appeals::save(['title' => 'Sponsor a Vanguard', 'status' => 'live',
                         'goal_ngn' => 500000, 'funds_ngv' => 1, 'offline_ngn' => 200000], 'tester');
$apSponRow = Appeals::byId($apSpon);
ck('appeals: a sponsorship appeal is flagged as one', (int) $apSponRow['funds_ngv'] === 1);
ck('appeals: it starts holding everything it has raised',
   Appeals::unallocated($apSponRow) === 200000);

$apPlain = Appeals::save(['title' => 'An ordinary appeal', 'status' => 'live', 'offline_ngn' => 100000], 'tester');
$apNo = Appeals::allocateToVanguards($apPlain, 50000, 1, 'tester');
ck('appeals: an ordinary appeal cannot hand money to NGV', empty($apNo['ok']));
ck('appeals: and the refusal says how to make it one',
   str_contains((string) $apNo['error'], 'funds NGV training fees'));

$apOver = Appeals::allocateToVanguards($apSpon, 900000, 1, 'tester');
ck('appeals: allocating more than was raised is refused outright', empty($apOver['ok']));
ck('appeals: the refusal names what is actually available',
   str_contains((string) $apOver['error'], '₦200,000'));
ck('appeals: a zero allocation is refused',
   empty(Appeals::allocateToVanguards($apSpon, 0, 1, 'tester')['ok']));
ck('appeals: nothing was allocated by any of those refusals',
   Appeals::unallocated($apSponRow) === 200000 && Appeals::allocations($apSpon)['total'] === 0);

/* Nobody is named in the public figure. The count and the total leave the
   method; the names do not. */
$apShort = Appeals::ngvShortfall();
ck('appeals: the NGV shortfall reports a count and a total',
   array_key_exists('participants', $apShort) && array_key_exists('outstanding', $apShort));
ck('appeals: and names nobody',
   !str_contains(strtolower(json_encode($apShort)), 'name')
   && !str_contains(json_encode($apShort), '@'));

/* The page must not name anybody either. */
$apSrc = (string) @file_get_contents(AV_ROOT . '/give/appeal.php');
ck('appeals: the public page shows the shortfall as a count, never a roster',
   str_contains($apSrc, 'ngvShortfall') && !str_contains($apSrc, 'NgvLedger::arrears'));


/* ══ Two bugs that cost data ══════════════════════════════════════════════ */

/* 1. A partial save must touch only what it was given. save() sanitised every
      field with a fallback default, so `save(['id' => N, 'project' => 'sts'])`
      blanked the title, dropped the appeal back to draft and zeroed the goal.
      The console posts the whole form, so it hid there until a one-field call
      was made from elsewhere. */
$apReset();
$apKeep = Appeals::save([
    'title' => 'Everything set', 'tagline' => 'A full record', 'status' => 'live',
    'kind' => 'emergency', 'goal_ngn' => 750000, 'offline_ngn' => 90000, 'urgent' => 1,
    'location' => 'Lagos', 'beneficiary' => '80 households', 'seo_desc' => 'A description',
], 'tester');
$apBefore = Appeals::byId($apKeep);
Appeals::save(['id' => $apKeep, 'project' => 'techhome'], 'tester');
$apAfter = Appeals::byId($apKeep);
foreach (['title', 'tagline', 'status', 'kind', 'goal_ngn', 'offline_ngn', 'urgent',
          'location', 'beneficiary', 'seo_desc', 'slug'] as $apF) {
    ck('appeals: a one-field save leaves "' . $apF . '" alone',
       (string) $apBefore[$apF] === (string) $apAfter[$apF]);
}
ck('appeals: and the field that WAS sent did change',
   (string) $apAfter['project'] === 'techhome');
/* A save with nothing to change is not an error and must not blank anything. */
Appeals::save(['id' => $apKeep], 'tester');
ck('appeals: a save with no fields at all changes nothing',
   (string) Appeals::byId($apKeep)['title'] === 'Everything set');
/* Creating still requires a title, and still fills every default. */
ck('appeals: creating without a title is still refused', Appeals::save(['status' => 'live'], 'tester') === 0);

/* 2. Columns added to the DDL later must reach a database that already has the
      table. CREATE TABLE IF NOT EXISTS is a no-op there, so `project` and
      `funds_ngv` never arrived and every save died on "no such column" — the
      exact failure tests/drift.test.php exists to document. */
$apReset();
try {
    /* Rebuild av_appeals in its pre-column shape, then make the domain use it. */
    $apPdo = Database::pdo();
    $apPdo->exec('DROP TABLE IF EXISTS av_appeals');
    $apPdo->exec("CREATE TABLE av_appeals (
        id INTEGER PRIMARY KEY AUTOINCREMENT, slug VARCHAR(90) NOT NULL UNIQUE,
        title TEXT NOT NULL DEFAULT '', status VARCHAR(16) NOT NULL DEFAULT 'draft',
        goal_ngn INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT '')");
    $apReady = new ReflectionProperty('Appeals', 'ready');
    $apReady->setAccessible(true);
    $apReady->setValue(null, false);
    Appeals::ensure();
    $apHealed = Appeals::save(['title' => 'After a migration gap', 'status' => 'live',
                               'project' => 'ngv', 'funds_ngv' => 1, 'goal_ngn' => 123000], 'tester');
    ck('appeals: a table missing later columns is healed rather than fatal', $apHealed > 0);
    $apH = $apHealed > 0 ? Appeals::byId($apHealed) : [];
    ck('appeals: and the new columns actually work afterwards',
       (string) ($apH['project'] ?? '') === 'ngv' && (int) ($apH['funds_ngv'] ?? 0) === 1);
} catch (Throwable $e) {
    ck('appeals: a table missing later columns is healed rather than fatal', false);
}

$apReset();
