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

$apReset();
