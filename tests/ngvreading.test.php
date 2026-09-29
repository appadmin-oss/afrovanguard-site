<?php
/**
 * tests/ngvreading.test.php — the 24-book challenge, as a claim that is checked.
 *
 * WHAT IS BEING PROTECTED. The count of books a participant has read is a
 * programme outcome — it appears on their record and in what the organisation
 * says about itself. It used to be twenty-four characters the participant
 * toggled themselves, which meant it recorded only that somebody tapped a box.
 *
 * WHAT IS NOT CLAIMED. No test here shows that anybody read a book, because no
 * software can. These assert the four things that ARE achievable: evidence is
 * required, a human decides, the cheap fakes are caught and surfaced, and the
 * count cannot be written around.
 *
 * The last of those is the one that matters most. Every check below is theatre
 * if a member can still POST the bitstring — so that is asserted first.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$rdReset = static function (): void {
    NgvReading::ensure();
    try {
        $pdo = NgvDb::pdo();
        foreach (['ngv_book_claims', 'ngv_participants'] as $t) $pdo->exec('DELETE FROM ' . $t);
    } catch (Throwable $e) {}
};

/** A reflection with enough substance to pass the floor. */
$rdText = static function (string $tail = ''): string {
    return str_repeat('The author argues that institutions decay when incentives drift away from their '
        . 'original purpose, and the chapter on accountability matched what I have seen at the centre. ', 3) . $tail;
};
$rdTake = 'I rewrote our weekly check-in so that every person names one measurable commitment.';

/* ══ The count cannot be written around ═══════════════════════════════════ */

$rdReset();
NgvMember::ensureParticipant(901, ['name' => 'Adaeze Nwosu', 'email' => 'adaeze@example.test']);
NgvDb::pdo()->exec("UPDATE ngv_participants SET start_date = '2026-02-01', status = 'active' WHERE member_id = 901");

/* This is the assertion the whole feature rests on. `books` used to be a
   member-editable field, so one POST of twenty-four ones completed the
   challenge and every check below could simply be stepped around. */
NgvMember::saveSelf(901, ['books' => str_repeat('1', 24)]);
ck('reading: a member cannot write the book count directly any more',
   (string) NgvMember::participant(901)['books'] === str_repeat('0', 24));
ck('reading: and their other self-edited fields still work',
   (static function () { NgvMember::saveSelf(901, ['focus_note' => 'still mine']);
       return (string) NgvMember::participant(901)['focus_note'] === 'still mine'; })());

/* ══ A claim needs evidence before it can even be submitted ═══════════════ */

$rdOk = ['slot' => 1, 'title' => 'Why Nations Fail', 'author' => 'Acemoglu',
         'started_on' => '2026-03-01', 'finished_on' => '2026-03-14',
         'reflection' => $rdText(), 'takeaway' => $rdTake];

foreach ([
    ['no title',                ['title' => '']],
    ['no author',               ['author' => '']],
    ['no dates',                ['started_on' => '', 'finished_on' => '']],
    ['finished before started', ['started_on' => '2026-03-20', 'finished_on' => '2026-03-01']],
    ['finished in the future',  ['finished_on' => '2099-01-01']],
    ['a one-line reflection',   ['reflection' => 'Good book, I enjoyed it.']],
    ['no specific takeaway',    ['takeaway' => 'it was useful']],
] as [$rdWhy, $rdBad]) {
    ck('reading: a claim with ' . $rdWhy . ' cannot be submitted',
       empty(NgvReading::save(901, array_merge($rdOk, $rdBad), true)['ok']));
}
ck('reading: a slot outside 1-24 is refused',
   empty(NgvReading::save(901, array_merge($rdOk, ['slot' => 99]), true)['ok'])
   && empty(NgvReading::save(901, array_merge($rdOk, ['slot' => 0]), true)['ok']));
/* A draft is for working on, so it is NOT held to the floor. */
ck('reading: but an incomplete draft can still be saved',
   !empty(NgvReading::save(901, ['slot' => 4, 'title' => 'Halfway through'], false)['ok']));

/* ══ Submitting is not the same as counting ══════════════════════════════ */

$rdClaim = NgvReading::save(901, $rdOk, true);
ck('reading: a complete claim submits', !empty($rdClaim['ok']));
ck('reading: submitting does NOT make it count', NgvReading::verifiedCount(901) === 0);
ck('reading: and the derived bitstring stays empty',
   (string) NgvMember::participant(901)['books'] === str_repeat('0', 24));
ck('reading: the member sees it as waiting, not done',
   NgvReading::progress(901)['waiting'] === 1 && NgvReading::progress(901)['verified'] === 0);

/* Once it is with a reviewer the member cannot move it — a claim somebody is
   reading must not change underneath them. */
ck('reading: a submitted claim cannot be edited by the member',
   empty(NgvReading::save(901, array_merge($rdOk, ['title' => 'A different book']), false)['ok']));

$rdV = NgvReading::review((int) $rdClaim['id'], 'verified', 77);
ck('reading: a reviewer verifying it is what makes it count',
   !empty($rdV['ok']) && NgvReading::verifiedCount(901) === 1);
ck('reading: and the bitstring follows the verification',
   substr((string) NgvMember::participant(901)['books'], 0, 1) === '1');
ck('reading: a verified claim is closed to the member for good',
   empty(NgvReading::save(901, array_merge($rdOk, ['title' => 'Swapped after approval']), false)['ok']));

/* ══ What gets flagged for a human ════════════════════════════════════════ */

$rdReset();
NgvMember::ensureParticipant(901, ['name' => 'Adaeze', 'email' => 'a@example.test']);
NgvMember::ensureParticipant(902, ['name' => 'Bode',   'email' => 'b@example.test']);
NgvDb::pdo()->exec("UPDATE ngv_participants SET start_date = '2026-02-01', status = 'active'");

NgvReading::save(901, $rdOk, true);
/* The copy-paste that actually happens in a cohort: one person's words on
   somebody else's claim. */
$rdCopy = NgvReading::save(902, array_merge($rdOk, ['slot' => 1]), true);
ck('reading: the same reflection as another participant is flagged',
   in_array('same-words-as-another-participant', $rdCopy['flags'] ?? [], true));
/* Normalisation: changing the capitals and punctuation is still a copy. */
$rdCopy2 = NgvReading::save(902, array_merge($rdOk, [
    'slot' => 2, 'reflection' => strtoupper($rdText()) . '!!!']), true);
ck('reading: re-capitalising a copied reflection does not hide it',
   in_array('same-words-as-another-participant', $rdCopy2['flags'] ?? [], true));

$rdSame = NgvReading::save(902, array_merge($rdOk, [
    'slot' => 3, 'started_on' => '2026-04-01', 'finished_on' => '2026-04-01',
    'reflection' => $rdText('A closing line unique to this one.'),
    'typed_ms' => 2000, 'paste_count' => 3]), true);
ck('reading: starting and finishing on the same day is flagged',
   in_array('started-and-finished-same-day', $rdSame['flags'], true));
ck('reading: pasting rather than typing is flagged',
   in_array('pasted-rather-than-typed', $rdSame['flags'], true));

$rdEarly = NgvReading::save(902, array_merge($rdOk, [
    'slot' => 4, 'started_on' => '2025-10-01', 'finished_on' => '2025-11-01',
    'reflection' => $rdText('Another closing line, different again.')]), true);
ck('reading: finishing before they enrolled is flagged',
   in_array('finished-before-they-enrolled', $rdEarly['flags'], true));

/* A flag is never a verdict — the reviewer can still approve it. */
ck('reading: a flagged claim can still be verified by a human',
   !empty(NgvReading::review((int) $rdSame['id'], 'verified', 77)['ok'])
   && NgvReading::verifiedCount(902) === 1);

/* Every flag has wording a reviewer can act on, and the spoofable ones say so. */
foreach (['same-words-as-another-participant', 'started-and-finished-same-day',
          'finished-before-they-enrolled', 'more-than-four-in-a-week',
          'pasted-rather-than-typed', 'written-implausibly-fast'] as $rdF) {
    ck('reading: the flag "' . $rdF . '" reads as a sentence',
       NgvReading::flagLabel($rdF) !== $rdF && mb_strlen(NgvReading::flagLabel($rdF)) > 20);
}
ck('reading: the browser-signal flags admit they are not proof',
   str_contains(NgvReading::flagLabel('pasted-rather-than-typed'), 'not proof')
   && str_contains(NgvReading::flagLabel('written-implausibly-fast'), 'not proof'));

/* ══ The reviewer's side ══════════════════════════════════════════════════ */

$rdQ = NgvReading::queue();
ck('reading: flagged claims sort to the front of the queue',
   $rdQ !== [] && ($rdQ[0]['flags_list'] ?? []) !== []);
ck('reading: the queue count agrees with the queue', NgvReading::queueCount() === count($rdQ));

/* A refusal with no reason is how a programme loses somebody who was telling
   the truth and never found out what was wrong. */
ck('reading: rejecting without a reason is refused',
   empty(NgvReading::review((int) $rdCopy['id'], 'rejected', 77, '')['ok']));
ck('reading: sending back without a reason is refused',
   empty(NgvReading::review((int) $rdCopy['id'], 'resubmit', 77, '')['ok']));
ck('reading: rejecting with a reason works',
   !empty(NgvReading::review((int) $rdCopy['id'], 'rejected', 77, 'This is another participant\'s reflection.')['ok']));
ck('reading: a rejected book does not count', NgvReading::verifiedCount(902) === 1);
ck('reading: an unknown verdict is refused',
   empty(NgvReading::review((int) $rdEarly['id'], 'approved-ish', 77, 'x')['ok']));
ck('reading: a draft cannot be reviewed at all',
   (static function () {
        $d = NgvReading::save(901, ['slot' => 9, 'title' => 'A draft'], false);
        return empty(NgvReading::review((int) $d['id'], 'verified', 77)['ok']);
   })());

/* Sent back, the member can work on it again — that is the whole point of
   `resubmit` as distinct from `rejected`. */
ck('reading: a claim sent back becomes editable again',
   !empty(NgvReading::review((int) $rdEarly['id'], 'resubmit', 77, 'Those dates are before you joined.')['ok'])
   && !empty(NgvReading::save(902, ['slot' => 4, 'title' => 'Corrected'], false)['ok']));

/* ══ The bitstring is derived, and only from verified claims ══════════════ */

$rdBits = NgvReading::syncBitstring(902);
ck('reading: the bitstring has exactly as many ones as verified claims',
   substr_count($rdBits, '1') === NgvReading::verifiedCount(902));
ck('reading: and it is what the participant row holds',
   (string) NgvMember::participant(902)['books'] === $rdBits);

/* ══ The fingerprint ══════════════════════════════════════════════════════ */

ck('reading: the same words fingerprint the same however they are punctuated',
   NgvReading::fingerprint('The book argued that trust compounds slowly and breaks quickly, which I agree with.')
   === NgvReading::fingerprint('the book argued THAT trust compounds slowly, and breaks quickly -- which I agree with!'));
ck('reading: genuinely different writing fingerprints differently',
   NgvReading::fingerprint($rdText('one ending')) !== NgvReading::fingerprint($rdText('a wholly different ending')));
ck('reading: something too short to be evidence has no fingerprint',
   NgvReading::fingerprint('Good book.') === '');

/* ══ The member surface no longer self-reports ════════════════════════════ */

$rdDash = (string) @file_get_contents(AV_ROOT . '/academy/ngv/dashboard.php');
ck('reading: the dashboard no longer sends a books patch',
   !preg_match('/\$patch\[.books.\]\s*=/', $rdDash));
ck('reading: and it reads the verified progress instead',
   str_contains($rdDash, 'NgvReading::progress'));
/* The member surface must not carry a verdict — only the console does. */
ck('reading: nothing on the member page can verify a book',
   !str_contains($rdDash, 'NgvReading::review'));

/* ══ The spoken check ═════════════════════════════════════════════════════
 *
 * The one check that reaches the reader-of-summaries. Its value rests
 * entirely on the participant not knowing which book is coming, so that is
 * what these assert: it opens on its own, it picks at random, the member
 * surface never names the book, and a failure is handled as one conversation
 * rather than as a verdict on the whole record.
 */

$rdReset();
NgvMember::ensureParticipant(903, ['name' => 'Chidi Okeke', 'email' => 'chidi@example.test']);
NgvDb::pdo()->exec("UPDATE ngv_participants SET start_date = '2026-01-01', status = 'active' WHERE member_id = 903");

/** Verify n books for 903, each with its own wording so nothing gets flagged as a copy. */
$rdVerify = static function (int $from, int $to) use ($rdText, $rdTake): void {
    for ($i = $from; $i <= $to; $i++) {
        $r = NgvReading::save(903, [
            'slot' => $i, 'title' => 'Book number ' . $i, 'author' => 'Author ' . $i,
            /* Dates spread by weeks from a fixed past point — NOT by month
               number, which walks into the future once the year is half over
               and is refused (correctly) by the evidence floor. */
            'started_on' => date('Y-m-d', strtotime('2026-01-05 +' . ($i * 7) . ' days')),
            'finished_on' => date('Y-m-d', strtotime('2026-01-05 +' . ($i * 7 + 5) . ' days')),
            'reflection' => $rdText('This one turned on the idea numbered ' . $i . ', which I had not met before.'),
            'takeaway' => $rdTake . ' Specifically after book ' . $i . '.',
        ], true);
        NgvReading::review((int) $r['id'], 'verified', 77);
    }
};

$rdVerify(1, 5);
ck('spot check: nothing is due before the sixth book', NgvReading::spotOpen(903) === null);
ck('spot check: and the member is not told one is coming',
   empty(NgvReading::progress(903)['spot_pending']));

$rdVerify(6, 6);
$rdSpot = NgvReading::spotOpen(903);
ck('spot check: one opens by itself at six verified books', $rdSpot !== null);
ck('spot check: it names a book the participant actually claimed',
   $rdSpot !== null && in_array((string) $rdSpot['title'], array_map(
       static fn(int $i): string => 'Book number ' . $i, range(1, 6)), true));
ck('spot check: and the member page knows one is due',
   !empty(NgvReading::progress(903)['spot_pending']));

/* The load-bearing property: a member-facing call must never reveal WHICH
   book. `spot_pending` is a boolean so the title is not even in scope on the
   page that could leak it. */
ck('spot check: the member-facing progress is a boolean, not the book',
   is_bool(NgvReading::progress(903)['spot_pending'])
   && !array_intersect(['title', 'claim_id', 'slot', 'spot_title'], array_keys(NgvReading::progress(903))));
$rdDash2 = (string) @file_get_contents(AV_ROOT . '/academy/ngv/dashboard.php');
ck('spot check: and the dashboard never reads the chosen book',
   !preg_match('/spotOpen|spotQueue|spot_title/', $rdDash2));

/* Two checks at once is how a volunteer track lead ends up doing none. */
$rdVerify(7, 12);
ck('spot check: a second does not pile up while the first is open',
   NgvReading::spotCount() === 1);

/* A flat coin-flip on six books is a 1-in-6 chance of any particular one, so
   ten members landing on the same book would be astronomical — this asserts
   the pick is actually varying rather than always taking the first row. */
$rdPicked = [];
for ($rdM = 910; $rdM < 930; $rdM++) {
    NgvMember::ensureParticipant($rdM, ['name' => 'M' . $rdM, 'email' => 'm' . $rdM . '@example.test']);
    NgvDb::pdo()->exec("UPDATE ngv_participants SET start_date = '2026-01-01', status = 'active' WHERE member_id = $rdM");
    for ($i = 1; $i <= 6; $i++) {
        $r = NgvReading::save($rdM, [
            'slot' => $i, 'title' => 'Slot ' . $i, 'author' => 'A',
            'started_on' => date('Y-m-d', strtotime('2026-01-05 +' . ($i * 7) . ' days')),
            'finished_on' => date('Y-m-d', strtotime('2026-01-05 +' . ($i * 7 + 5) . ' days')),
            'reflection' => $rdText('Member ' . $rdM . ' on book ' . $i . ', in their own words entirely.'),
            'takeaway' => $rdTake . ' For member ' . $rdM . ' at book ' . $i . '.',
        ], true);
        NgvReading::review((int) $r['id'], 'verified', 77);
    }
    $sp = NgvReading::spotOpen($rdM);
    if ($sp) $rdPicked[] = (string) $sp['title'];
}
ck('spot check: the book is chosen at random, not always the same slot',
   count(array_unique($rdPicked)) >= 3);

/* Recording it. */
ck('spot check: a failed check needs a note', empty(NgvReading::spotRecord((int) $rdSpot['id'], 'failed', 77, '')['ok']));
ck('spot check: a passed check does not', !empty(NgvReading::spotRecord((int) $rdSpot['id'], 'passed', 77)['ok']));
ck('spot check: it cannot be recorded twice',
   empty(NgvReading::spotRecord((int) $rdSpot['id'], 'failed', 77, 'changed my mind')['ok']));
ck('spot check: an unknown outcome is refused',
   empty(NgvReading::spotRecord((int) $rdSpot['id'], 'maybe', 77, 'x')['ok']));
ck('spot check: passing leaves the count alone', NgvReading::verifiedCount(903) === 12);
ck('spot check: and it shows in their history',
   ($rdH = NgvReading::spotHistory(903)) !== [] && (string) $rdH[0]['outcome'] === 'passed');

/* A failure sends THAT book back and nothing else. One awkward conversation
   is not grounds for voiding somebody's record. */
$rdVerify(13, 18);
$rdSpot2 = NgvReading::spotOpen(903);
ck('spot check: the next one opens at the following milestone', $rdSpot2 !== null);
$rdBefore = NgvReading::verifiedCount(903);
ck('spot check: recording a failure works',
   !empty(NgvReading::spotRecord((int) $rdSpot2['id'], 'failed', 77, 'Could not say what the argument was.')['ok']));
ck('spot check: a failure sends that one book back',
   (string) NgvReading::claimById((int) $rdSpot2['claim_id'])['status'] === 'resubmit');
ck('spot check: exactly one book, not the whole record',
   NgvReading::verifiedCount(903) === $rdBefore - 1);
ck('spot check: and the bitstring follows it down',
   substr_count((string) NgvMember::participant(903)['books'], '1') === $rdBefore - 1);
ck('spot check: the participant is told why, in the note',
   str_contains((string) NgvReading::claimById((int) $rdSpot2['claim_id'])['review_note'], 'Could not say'));

$rdReset();
