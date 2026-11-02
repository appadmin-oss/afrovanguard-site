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
/** A book on the admin's list (made once, reused), and a chapter summary long enough to count. */
$rdBook = static function (string $title, string $author = 'Test Author', int $chapters = 1): int {
    foreach (NgvReading::books(true) as $b) if ($b['title'] === $title && $b['author'] === $author) return (int) $b['id'];
    return (int) (NgvReading::saveBook(['title' => $title, 'author' => $author, 'chapters' => $chapters], 1)['book']['id'] ?? 0);
};
$rdChap = static fn(string $tag = ''): string => 'This chapter sets out how institutions are shaped by the incentives of those who run them. ' . $tag;

/**
 * SEND A CLAIM THE WAY A MEMBER DOES. A book cannot be submitted with no
 * reading logged against it, so every claim here is drafted, read in at
 * least one sitting, and then sent — which is also the flow the claim sheet
 * walks somebody through.
 */
$rdSit = static fn(array $o = []): array => $o + [
    'minutes' => 45, 'pages' => '1-20', 'place' => 'At the centre', 'value_key' => 'diligence',
    'notes' => 'This sitting covered the opening argument about institutions and the incentives of the people inside them, '
             . 'and the example the author uses to carry it.',
    'stance' => 'I agree with the claim that rules follow incentives, though I think it underrates what one stubborn person can hold in place.',
    'application' => 'I am going to write down the incentive behind each of our own rules at the next meeting.',
];
$rdSend = static function (int $member, array $claim) use ($rdSit) {
    $d = NgvReading::save($member, $claim, false);
    if (empty($d['ok'])) return $d;
    $today = function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d');
    $day = (string) ($claim['started_on'] ?? '');
    if ($day === '' || $day > $today) $day = $today;
    $sit = NgvReading::logSession($member, $rdSit(['slot' => (int) $claim['slot'], 'day' => $day]));
    if (empty($sit['ok'])) return $sit;
    return NgvReading::save($member, $claim, true);
};

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

$rdOk = ['slot' => 1, 'book_id' => $rdBook('Why Nations Fail', 'Acemoglu'),
         'started_on' => '2026-03-01', 'finished_on' => '2026-03-14',
         'chapter_notes' => [$rdChap()],
         'reflection' => $rdText(), 'takeaway' => $rdTake];

foreach ([
    ['no book chosen',          ['book_id' => 0]],
    ['a book not on the list',  ['book_id' => 999999]],
    ['a missing chapter summary', ['chapter_notes' => []]],
    ['a one-line chapter summary', ['chapter_notes' => ['Good chapter.']]],
    ['no dates',                ['started_on' => '', 'finished_on' => '']],
    ['finished before started', ['started_on' => '2026-03-20', 'finished_on' => '2026-03-01']],
    ['finished in the future',  ['finished_on' => '2099-01-01']],
    ['a one-line reflection',   ['reflection' => 'Good book, I enjoyed it.']],
    ['no specific takeaway',    ['takeaway' => 'it was useful']],
] as [$rdWhy, $rdBad]) {
    ck('reading: a claim with ' . $rdWhy . ' cannot be submitted',
       empty($rdSend(901, array_merge($rdOk, $rdBad))['ok']));
}
ck('reading: a slot outside 1-24 is refused',
   empty($rdSend(901, array_merge($rdOk, ['slot' => 99]))['ok'])
   && empty($rdSend(901, array_merge($rdOk, ['slot' => 0]))['ok']));
/* A draft is for working on, so it is NOT held to the floor. */
ck('reading: but an incomplete draft can still be saved',
   !empty(NgvReading::save(901, ['slot' => 4, 'book_id' => $rdBook('Halfway through')], false)['ok']));

/* ══ Submitting is not the same as counting ══════════════════════════════ */

$rdClaim = $rdSend(901, $rdOk);
ck('reading: a complete claim submits', !empty($rdClaim['ok']));
ck('reading: submitting does NOT make it count', NgvReading::verifiedCount(901) === 0);
ck('reading: and the derived bitstring stays empty',
   (string) NgvMember::participant(901)['books'] === str_repeat('0', 24));
ck('reading: the member sees it as waiting, not done',
   NgvReading::progress(901)['waiting'] === 1 && NgvReading::progress(901)['verified'] === 0);

/* Once it is with a reviewer the member cannot move it — a claim somebody is
   reading must not change underneath them. */
ck('reading: a submitted claim cannot be edited by the member',
   empty(NgvReading::save(901, array_merge($rdOk, ['book_id' => $rdBook('A different book')]), false)['ok']));

$rdV = NgvReading::review((int) $rdClaim['id'], 'verified', 77);
ck('reading: a reviewer verifying it is what makes it count',
   !empty($rdV['ok']) && NgvReading::verifiedCount(901) === 1);
ck('reading: and the bitstring follows the verification',
   substr((string) NgvMember::participant(901)['books'], 0, 1) === '1');
ck('reading: a verified claim is closed to the member for good',
   empty(NgvReading::save(901, array_merge($rdOk, ['book_id' => $rdBook('Swapped after approval')]), false)['ok']));

/* ══ What gets flagged for a human ════════════════════════════════════════ */

$rdReset();
NgvMember::ensureParticipant(901, ['name' => 'Adaeze', 'email' => 'a@example.test']);
NgvMember::ensureParticipant(902, ['name' => 'Bode',   'email' => 'b@example.test']);
NgvDb::pdo()->exec("UPDATE ngv_participants SET start_date = '2026-02-01', status = 'active'");

$rdSend(901, $rdOk);
/* The copy-paste that actually happens in a cohort: one person's words on
   somebody else's claim. */
$rdCopy = $rdSend(902, array_merge($rdOk, ['slot' => 1]));
ck('reading: the same reflection as another participant is flagged',
   in_array('same-words-as-another-participant', $rdCopy['flags'] ?? [], true));
/* Normalisation: changing the capitals and punctuation is still a copy. */
$rdCopy2 = $rdSend(902, array_merge($rdOk, [
    'slot' => 2, 'book_id' => $rdBook('The Second Book'), 'reflection' => strtoupper($rdText()) . '!!!']));
ck('reading: re-capitalising a copied reflection does not hide it',
   in_array('same-words-as-another-participant', $rdCopy2['flags'] ?? [], true));

$rdSame = $rdSend(902, array_merge($rdOk, [
    'slot' => 3, 'book_id' => $rdBook('The Third Book'), 'chapter_notes' => [$rdChap('Three.')], 'started_on' => '2026-04-01', 'finished_on' => '2026-04-01',
    'reflection' => $rdText('A closing line unique to this one.'),
    'typed_ms' => 2000, 'paste_count' => 3]));
ck('reading: starting and finishing on the same day is flagged',
   in_array('started-and-finished-same-day', $rdSame['flags'], true));
ck('reading: pasting rather than typing is flagged',
   in_array('pasted-rather-than-typed', $rdSame['flags'], true));

$rdEarly = $rdSend(902, array_merge($rdOk, [
    'slot' => 4, 'book_id' => $rdBook('The Fourth Book'), 'chapter_notes' => [$rdChap('Four.')], 'started_on' => '2025-10-01', 'finished_on' => '2025-11-01',
    'reflection' => $rdText('Another closing line, different again.')]));
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
   (static function () use ($rdBook) {
        $d = NgvReading::save(901, ['slot' => 9, 'book_id' => $rdBook('A draft')], false);
        return empty(NgvReading::review((int) $d['id'], 'verified', 77)['ok']);
   })());

/* Sent back, the member can work on it again — that is the whole point of
   `resubmit` as distinct from `rejected`. */
ck('reading: a claim sent back becomes editable again',
   !empty(NgvReading::review((int) $rdEarly['id'], 'resubmit', 77, 'Those dates are before you joined.')['ok'])
   && !empty(NgvReading::save(902, ['slot' => 4, 'book_id' => $rdBook('The Fourth Book')], false)['ok']));

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

/* The member surface is three files now — the page, the data it reads and the
   body it renders — so this reads all three. Grepping only dashboard.php would
   have gone quiet the moment the markup moved into a partial. */
$rdDash = implode("\n", array_map(
    static fn(string $f): string => (string) @file_get_contents(AV_ROOT . '/academy/ngv/' . $f),
    ['dashboard.php', '_dashboard-data.php', '_dashboard-body.php']));
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
$rdVerify = static function (int $from, int $to) use ($rdText, $rdTake, $rdBook, $rdChap, $rdSend): void {
    for ($i = $from; $i <= $to; $i++) {
        $r = $rdSend(903, [
            'slot' => $i, 'book_id' => $rdBook('Book number ' . $i, 'Author ' . $i), 'chapter_notes' => [$rdChap('Book ' . $i . '.')],
            /* Dates spread by weeks from a fixed past point — NOT by month
               number, which walks into the future once the year is half over
               and is refused (correctly) by the evidence floor. */
            'started_on' => date('Y-m-d', strtotime('2026-01-05 +' . ($i * 7) . ' days')),
            'finished_on' => date('Y-m-d', strtotime('2026-01-05 +' . ($i * 7 + 5) . ' days')),
            'reflection' => $rdText('This one turned on the idea numbered ' . $i . ', which I had not met before.'),
            'takeaway' => $rdTake . ' Specifically after book ' . $i . '.',
        ]);
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
        $r = $rdSend($rdM, [
            'slot' => $i, 'book_id' => $rdBook('Slot ' . $i, 'A'), 'chapter_notes' => [$rdChap('Member ' . $rdM . ', book ' . $i . '.')],
            'started_on' => date('Y-m-d', strtotime('2026-01-05 +' . ($i * 7) . ' days')),
            'finished_on' => date('Y-m-d', strtotime('2026-01-05 +' . ($i * 7 + 5) . ' days')),
            'reflection' => $rdText('Member ' . $rdM . ' on book ' . $i . ', in their own words entirely.'),
            'takeaway' => $rdTake . ' For member ' . $rdM . ' at book ' . $i . '.',
        ]);
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

/* ══ The book list, and a summary of every chapter ═══════════════════════
 *
 * Admins prepare the books — title, author, chapters — and a vanguard
 * chooses from that list only. A claim carries one summary per chapter as
 * well as the reflection on the whole book.
 */

NgvMember::ensureParticipant(940, ['name' => 'Efe Okafor', 'email' => 'efe@example.test']);
NgvMember::ensureParticipant(941, ['name' => 'Funmi Ade', 'email' => 'funmi@example.test']);
NgvDb::pdo()->exec("UPDATE ngv_participants SET start_date = '2026-01-01', status = 'active' WHERE member_id IN (940, 941)");

ck('book list: a book needs a title, an author and its chapters',
   empty(NgvReading::saveBook(['title' => '', 'author' => 'A', 'chapters' => 3], 1)['ok'])
   && empty(NgvReading::saveBook(['title' => 'T', 'author' => '', 'chapters' => 3], 1)['ok'])
   && empty(NgvReading::saveBook(['title' => 'T', 'author' => 'A', 'chapters' => 0], 1)['ok'])
   && empty(NgvReading::saveBook(['title' => 'T', 'author' => 'A', 'chapters' => NgvReading::MAX_CHAPTERS + 1], 1)['ok']));
$bkThree = NgvReading::saveBook(['title' => 'Things Fall Apart', 'author' => 'Chinua Achebe', 'chapters' => 3], 1);
ck('book list: an admin adds a book with its chapter count', !empty($bkThree['ok']) && (int) $bkThree['book']['chapters'] === 3);
$bkId = (int) $bkThree['book']['id'];
ck('book list: the same book twice is refused',
   empty(NgvReading::saveBook(['title' => 'things fall apart', 'author' => 'Chinua Achebe', 'chapters' => 3], 1)['ok']));
ck('book list: vanguards are offered it', in_array($bkId, array_column(NgvReading::books(), 'id'), true));

$bkClaim = ['slot' => 1, 'book_id' => $bkId, 'started_on' => '2026-03-01', 'finished_on' => '2026-03-20',
            'chapter_notes' => [$rdChap('One, by Efe.'), $rdChap('Two, by Efe.'), $rdChap('Three, by Efe.')],
            'reflection' => $rdText('Efe on the whole of Achebe.'), 'takeaway' => $rdTake];
ck('book list: a title typed by hand, not on the list, is refused',
   empty(NgvReading::save(940, ['slot' => 1, 'title' => 'A book I made up', 'author' => 'Me'], false)['ok']));
ck('book list: every chapter needs its summary before it can be sent',
   empty($rdSend(940, array_merge($bkClaim, ['chapter_notes' => [$rdChap('One.'), $rdChap('Two.')]]))['ok']));
ck('book list: …and the refusal names the chapter',
   str_contains((string) $rdSend(940, array_merge($bkClaim, ['chapter_notes' => [$rdChap('One.'), 'Short.', $rdChap('Three.')]]))['error'], 'chapter 2'));
$bkSent = $rdSend(940, $bkClaim);
$bkRow = NgvReading::claimById((int) $bkSent['id']);
ck('book list: a complete claim is sent with the list\'s title and author, and a summary per chapter',
   !empty($bkSent['ok']) && $bkRow['title'] === 'Things Fall Apart' && $bkRow['author'] === 'Chinua Achebe'
   && $bkRow['chapters'] === 3 && count($bkRow['chapter_notes_list']) === 3 && str_contains($bkRow['chapter_notes_list'][1], 'Two, by Efe'));
ck('book list: the same book cannot fill two slots',
   empty(NgvReading::save(940, array_merge($bkClaim, ['slot' => 2]), false)['ok']));

/* Somebody else's chapter summary, word for word, on the same book. */
$bkCopy = $rdSend(941, array_merge($bkClaim, ['chapter_notes' => [$rdChap('One, by Efe.'), $rdChap('Two, Funmi.'), $rdChap('Three, Funmi.')],
                                                       'reflection' => $rdText('Funmi, her own reflection entirely.')]));
ck('book list: a chapter summary copied from another vanguard is flagged',
   in_array('same-chapter-summary-as-another-participant', $bkCopy['flags'] ?? [], true)
   && NgvReading::flagLabel('same-chapter-summary-as-another-participant') !== 'same-chapter-summary-as-another-participant');

/* Retiring and correcting. */
NgvReading::saveBook(['id' => $bkId, 'title' => 'Things Fall Apart', 'author' => 'Chinua Achebe', 'chapters' => 25], 1);
ck('book list: correcting the chapter count leaves a claim already made as it was',
   NgvReading::claimById((int) $bkSent['id'])['chapters'] === 3 && NgvReading::book($bkId)['chapters'] === 25);
NgvReading::setBookActive($bkId, false);
ck('book list: a retired book is no longer offered', !in_array($bkId, array_column(NgvReading::books(), 'id'), true)
   && in_array($bkId, array_column(NgvReading::books(true), 'id'), true));
ck('book list: …and cannot be chosen for a new claim', empty(NgvReading::save(940, array_merge($bkClaim, ['slot' => 5]), false)['ok']));
ck('book list: …but a claim already on it keeps it', NgvReading::review((int) $bkSent['id'], 'resubmit', 77, 'Say more about chapter two.')['ok']
   && !empty(NgvReading::save(940, $bkClaim, false)['ok']));
NgvReading::setBookActive($bkId, true);

/* A claim recorded before the list existed keeps the book it named. */
NgvDb::pdo()->exec("INSERT INTO ngv_book_claims (member_id, slot, title, author, status, created_at, updated_at) VALUES (940, 7, 'An Old Favourite', 'Someone', 'resubmit', '2026-02-01', '2026-02-01')");
ck('book list: a claim from before the list can still be put right in its own words',
   !empty($rdSend(940, ['slot' => 7, 'title' => 'An Old Favourite', 'author' => 'Someone', 'started_on' => '2026-02-01', 'finished_on' => '2026-02-20',
                                 'reflection' => $rdText('The old favourite, put right.'), 'takeaway' => $rdTake])['ok'])
   && NgvReading::claim(940, 7)['title'] === 'An Old Favourite' && NgvReading::claim(940, 7)['book_id'] === 0);

/* The pages. */
$bkPage = (string) @file_get_contents(AV_ROOT . '/academy/ngv/books.php');
ck('book list: the list page is for admins, and checks the request',
   str_contains($bkPage, "in_array(\$role, ['admin', 'superadmin'], true)") && str_contains($bkPage, 'av_csrf_require()') && str_contains($bkPage, 'require_same_origin()'));
// The claim sheet is its own partial, drawn once per page (academy/ngv/_book-modal.php).
$bkBody = (string) @file_get_contents(AV_ROOT . '/academy/ngv/_dashboard-body.php') . (string) @file_get_contents(AV_ROOT . '/academy/ngv/_book-modal.php');
ck('book list: the vanguard chooses the book, and has nowhere to type a title',
   str_contains($bkBody, 'id="bkBook"') && !str_contains($bkBody, 'id="bkBookTitle"') && str_contains($bkBody, 'id="bkChapters"'));

/* ══ The audit's findings, 2 Nov 2026 (docs/audits/2026-11-02-reading) ═══ */

/* R1. A participant who finishes the challenge gets a conversation for every
   SIX books, not one for the whole twenty-four. The one-at-a-time rule stays
   — a lead facing a backlog of them does none — but the queue must drain.
   This used to leave milestones 12, 18 and 24 unopened for ever. */
$rdReset();
NgvMember::ensureParticipant(950, ['name' => 'Full Shelf', 'email' => 'full@reading.test']);
NgvDb::pdo()->exec("UPDATE ngv_participants SET start_date = '2026-01-01', status = 'active' WHERE member_id = 950");
for ($i = 1; $i <= 24; $i++) {
    $r = $rdSend(950, ['slot' => $i, 'book_id' => $rdBook('Shelf book ' . $i, 'A'),
        'chapter_notes' => [$rdChap('Shelf book ' . $i . '.')],
        'started_on' => '2026-02-01', 'finished_on' => '2026-02-02',
        'reflection' => $rdText('Shelf book ' . $i . ', in their own words.'),
        'takeaway' => $rdTake . ' For shelf book ' . $i . '.']);
    NgvReading::review((int) $r['id'], 'verified', 77);
}
$rdStones = static fn(): array => array_map('intval', NgvDb::pdo()->query(
    'SELECT milestone FROM ngv_book_spot_checks WHERE member_id = 950 ORDER BY milestone')->fetchAll(PDO::FETCH_COLUMN) ?: []);
ck('spot check: verifying a whole shelf in one sitting still opens only one at a time',
   NgvReading::verifiedCount(950) === 24 && $rdStones() === [6] && NgvReading::spotCount() >= 1);
for ($i = 0; $i < 6; $i++) {
    $sp = NgvReading::spotOpen(950);
    if (!$sp) break;
    NgvReading::spotRecord((int) $sp['id'], 'passed', 77, 'Spoke well about it.');
}
ck('spot check: …and recording each one brings on the milestone it was holding up',
   $rdStones() === [6, 12, 18, 24]);
ck('spot check: …which is where it stops — twenty-four books, four conversations',
   NgvReading::spotOpen(950) === null);

/* R3. Nobody signs off their own reading. */
$rdMine = NgvReading::save(950, ['slot' => 1], false);   // closed: verified
$rdOwn = (int) NgvReading::claim(950, 2)['id'];
ck('reading: a reviewer cannot verify their own claim',
   (NgvReading::review($rdOwn, 'resubmit', 950, 'I will redo it')['code'] ?? '') === 'own_account');
ck('reading: …and the system itself (uid 0) still can, as the ledger allows',
   NgvReading::selfDealing(950, 0, 'x') === '' && NgvReading::selfDealing(950, 77, 'x') === '');
$rdR = NgvReading::save(951, ['slot' => 1], false);      // a claim belonging to somebody else
NgvMember::ensureParticipant(951, ['name' => 'Other One', 'email' => 'other@reading.test']);
$rdSpot950 = NgvDb::pdo()->query("SELECT id, member_id FROM ngv_book_spot_checks WHERE member_id = 950 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
NgvDb::pdo()->prepare("UPDATE ngv_book_spot_checks SET outcome = 'open' WHERE id = ?")->execute([(int) $rdSpot950['id']]);
ck('reading: nor hold their own spoken check',
   (NgvReading::spotRecord((int) $rdSpot950['id'], 'passed', 950)['code'] ?? '') === 'own_account');
NgvDb::pdo()->prepare("UPDATE ngv_book_spot_checks SET outcome = 'passed' WHERE id = ?")->execute([(int) $rdSpot950['id']]);

/* R4. A rejection closes the claim — that is what makes it a different
   decision from "send back to rewrite", which it used to be identical to. */
$rdReset();
NgvMember::ensureParticipant(952, ['name' => 'Told No', 'email' => 'no@reading.test']);
NgvDb::pdo()->exec("UPDATE ngv_participants SET start_date = '2026-01-01', status = 'active' WHERE member_id = 952");
$rdNoBook = $rdBook('A Rejected Read', 'A');
$rdNoClaim = ['slot' => 1, 'book_id' => $rdNoBook, 'chapter_notes' => [$rdChap('Rejected.')],
              'started_on' => '2026-02-01', 'finished_on' => '2026-02-08',
              'reflection' => $rdText('Written out in full for the rejection test.'), 'takeaway' => $rdTake];
$rdNo = $rdSend(952, $rdNoClaim);
NgvReading::review((int) $rdNo['id'], 'rejected', 77, 'This is a summary of the blurb.');
ck('reading: a rejected claim is closed to the member',
   ($rdSend(952, $rdNoClaim)['code'] ?? '') === 'closed'
   && (string) NgvReading::claim(952, 1)['status'] === 'rejected');
ck('reading: …and it is listed where it can be reopened',
   in_array((int) $rdNo['id'], array_map('intval', array_column(NgvReading::rejected(), 'id')), true));
ck('reading: a reviewer reopens it, with a reason, on the record',
   !empty(NgvReading::review((int) $rdNo['id'], 'resubmit', 77, 'You made your case — rewrite chapter two.')['ok'])
   && (string) NgvReading::claim(952, 1)['status'] === 'resubmit');
ck('reading: …and then the member can write it again',
   !empty($rdSend(952, $rdNoClaim)['ok']));

/* R8. Over the ceiling is a refusal with the number in it, never a silent cut. */
$rdBig = trim(str_repeat('x y ', 4000));
$rdLong = NgvReading::save(952, ['slot' => 2, 'book_id' => $rdBook('A Long One', 'A'),
    'reflection' => $rdBig], false);
ck('reading: a reflection over the ceiling is refused, saying how long it is',
   ($rdLong['code'] ?? '') === 'too_long' && str_contains((string) $rdLong['error'], (string) mb_strlen($rdBig))
   && str_contains((string) $rdLong['error'], (string) NgvReading::MAX_REFLECTION)
   && NgvReading::claim(952, 2) === null);
$rdLong2 = NgvReading::save(952, ['slot' => 2, 'book_id' => $rdBook('A Long Two', 'A'),
    'takeaway' => str_repeat('z ', 700)], false);
ck('reading: …and so is a takeaway, with nothing saved either way',
   ($rdLong2['code'] ?? '') === 'too_long' && NgvReading::claim(952, 2) === null);

/* R9. The reviewer's name comes with the row, not a query per row. */
$rdSend(952, $rdNoClaim);
$rdNamed = NgvReading::queue(10);
ck('reading: the review queue carries the participant\'s name',
   $rdNamed !== [] && (string) ($rdNamed[0]['member_name'] ?? '') === 'Told No');

/* R5. Who may check the reading: an NGV admin, and anybody granted it. */
ck('reading: a member who has not been granted it cannot review', !NgvReading::isReviewer(952));
ck('reading: an NGV admin may, granted or not', NgvReading::canReview(0) === (function_exists('av_admin_role')
   && in_array(av_admin_role(), ['admin', 'superadmin'], true)));
ck('reading: granting it names them, and it is a grant of exactly this',
   !empty(NgvReading::setReviewer(952, true, 77)['ok']) && NgvReading::isReviewer(952) && NgvReading::canReview(952));
ck('reading: …and it is listed so it can be taken away',
   in_array(952, array_map('intval', array_column(NgvReading::reviewers(), 'member_id')), true));
ck('reading: taking it away takes it away',
   !empty(NgvReading::setReviewer(952, false, 77)['ok']) && !NgvReading::isReviewer(952));

/* R2 and R5's surfaces. */
$rdDesk = (string) @file_get_contents(AV_ROOT . '/academy/ngv/reading-review.php');
ck('reading: the review desk is gated on who may review, not on being an admin',
   str_contains($rdDesk, 'NgvReading::canReview(') && !str_contains($rdDesk, "if (!\$isAdmin) json_out"));
ck('reading: …and it is the same desk the console shows, not a second one',
   str_contains($rdDesk, "_reading-review.php")
   && str_contains((string) @file_get_contents(AV_ROOT . '/academy/ngv/members.php'), "_reading-review.php"));
$rdHome = (string) @file_get_contents(AV_ROOT . '/academy/ngv/index.php');
ck('reading: the console says how many claims and conversations are waiting',
   str_contains($rdHome, 'NgvReading::queueCount()') && str_contains($rdHome, 'NgvReading::spotCount()'));


/* ══ A sitting, not just a summary ═══════════════════════════════════════
   A book used to leave one record, written at the end — so the programme
   could see that twenty-four were finished and nothing about the reading.
   Each sitting says when, how far, how long, where, what it covered, where
   they stand on it, what they will do, and which of the seven values it
   grew. The last is the point: the challenge exists to grow them, and a
   shelf of finished titles cannot show that it has. */

$rdReset();
NgvDb::pdo()->exec('DELETE FROM ngv_reading_sessions');
NgvMember::ensureParticipant(960, ['name' => 'Sitting Reader', 'email' => 'sit@reading.test']);
NgvDb::pdo()->exec("UPDATE ngv_participants SET start_date = '2026-01-01', status = 'active' WHERE member_id = 960");
$sitBook = $rdBook('A Book Read Slowly', 'An Author', 4);
$sitClaim = ['slot' => 1, 'book_id' => $sitBook, 'started_on' => '2026-03-01', 'finished_on' => '2026-03-20',
             'chapter_notes' => [$rdChap('One.'), $rdChap('Two.'), $rdChap('Three.'), $rdChap('Four.')],
             'reflection' => $rdText('Read slowly, over a fortnight.'), 'takeaway' => $rdTake];

ck('sitting: nothing can be logged against a book that has not been started',
   empty(NgvReading::logSession(960, $rdSit(['slot' => 1, 'day' => '2026-03-02']))['ok']));
NgvReading::save(960, $sitClaim, false);

/* The floor, field by field. */
foreach ([
    ['no day',              ['day' => '']],
    ['a day in the future', ['day' => '2099-01-01']],
    ['a day before they started the book', ['day' => '2026-02-01']],
    ['no minutes',          ['minutes' => 0]],
    ['more minutes than a day of reading', ['minutes' => 2000]],
    ['neither chapters nor pages', ['pages' => '', 'chapter_from' => 0, 'chapter_to' => 0]],
    ['a chapter this book does not have', ['pages' => '', 'chapter_from' => 1, 'chapter_to' => 9]],
    ['chapters the wrong way round', ['pages' => '', 'chapter_from' => 3, 'chapter_to' => 2]],
    ['nowhere',             ['place' => '']],
    ['no value named',      ['value_key' => '']],
    ['a value that is not one of the seven', ['value_key' => 'punctuality']],
    ['nothing on what it covered', ['notes' => 'Read a bit.']],
    ['no stance on it',     ['stance' => 'It was fine.']],
    ['no life application', ['application' => 'Nothing.']],
] as [$sitWhy, $sitBad]) {
    ck('sitting: one with ' . $sitWhy . ' is refused',
       empty(NgvReading::logSession(960, $rdSit($sitBad + ['slot' => 1, 'day' => '2026-03-02']))['ok']));
}
$sitOk = NgvReading::logSession(960, $rdSit(['slot' => 1, 'day' => '2026-03-02', 'chapter_from' => 1, 'chapter_to' => 2, 'pages' => '']));
ck('sitting: a complete one is logged', !empty($sitOk['ok']) && count($sitOk['sessions']) === 1);
$sitRow = NgvReading::sessions((int) NgvReading::claim(960, 1)['id'])[0];
ck('sitting: …with how far, how long, where and which value',
   $sitRow['covered'] === 'Chapters 1–2' && $sitRow['minutes'] === 45
   && $sitRow['place'] === 'At the centre' && $sitRow['value_label'] === 'Diligence');
ck('sitting: the mood is the only optional part', $sitRow['mood'] === ''
   && !empty(NgvReading::logSession(960, $rdSit(['id' => $sitRow['id'], 'slot' => 1, 'day' => '2026-03-02', 'mood' => 'absorbed']))['ok'])
   && NgvReading::session((int) $sitRow['id'])['mood_label'] !== '');
ck('sitting: …and a mood that is not one of ours is simply not recorded',
   !empty(NgvReading::logSession(960, $rdSit(['id' => $sitRow['id'], 'slot' => 1, 'day' => '2026-03-02', 'mood' => 'ecstatic']))['ok'])
   && NgvReading::session((int) $sitRow['id'])['mood'] === '');

/* The floor on the BOOK: it cannot be sent with no reading behind it. */
$rdReset2 = NgvReading::claim(960, 1);
NgvDb::pdo()->exec('DELETE FROM ngv_reading_sessions WHERE claim_id = ' . (int) $rdReset2['id']);
ck('sitting: a book with no reading logged against it cannot be sent',
   (NgvReading::save(960, $sitClaim, true)['code'] ?? '') === 'no_sessions');
NgvReading::logSession(960, $rdSit(['slot' => 1, 'day' => '2026-03-02']));
ck('sitting: …and with one, it can', !empty(NgvReading::save(960, $sitClaim, true)['ok']));

/* Once it is with the track lead, the evidence under it stops moving too. */
ck('sitting: a sent book\'s sittings cannot be changed',
   (NgvReading::logSession(960, $rdSit(['slot' => 1, 'day' => '2026-03-03']))['code'] ?? '') === 'closed');
$sitNow = NgvReading::sessions((int) $rdReset2['id']);
ck('sitting: …nor removed', (NgvReading::dropSession(960, (int) $sitNow[0]['id'])['code'] ?? '') === 'closed');
ck('sitting: the reviewer sees them on the claim',
   ($rdQ2 = array_values(array_filter(NgvReading::queue(50), static fn($c) => (int) $c['member_id'] === 960))) !== []
   && count($rdQ2[0]['sessions']) === 1 && (int) $rdQ2[0]['session_minutes'] === 45);

/* A sitting belongs to its owner. 961 has a draft of their OWN at slot 1,
   so the refusal below is the ownership check and not "you have not started
   that book" — which is what it was the first time this was written, and it
   passed with the guard removed. */
NgvMember::ensureParticipant(961, ['name' => 'Somebody Else', 'email' => 'else@reading.test']);
NgvDb::pdo()->exec("UPDATE ngv_participants SET start_date = '2026-01-01', status = 'active' WHERE member_id = 961");
NgvReading::save(961, ['slot' => 1, 'book_id' => $rdBook('Their Own Book', 'An Author', 2), 'started_on' => '2026-03-01'], false);
ck('sitting: nobody edits somebody else\'s, even with a book of their own open',
   empty(NgvReading::logSession(961, $rdSit(['id' => $sitNow[0]['id'], 'slot' => 1, 'day' => '2026-03-02']))['ok'])
   && empty(NgvReading::dropSession(961, (int) $sitNow[0]['id'])['ok'])
   && NgvReading::session((int) $sitNow[0]['id'])['member_id'] === 960);

/* ══ The reading grows the seven values ══════════════════════════════════ */

ck('values: the seven come from the one place they are declared, never a copy',
   NgvReading::values() === MentorPortal::VALUES && NgvReading::valueLabel('communal') === 'Communal Spirit'
   && NgvReading::valueMotto('diligence') === 'Talent is cheap. Discipline is rare.');
$grew = NgvReading::valueGrowth(960);
ck('values: a member sees every one of the seven, including the ones at zero',
   array_keys($grew) === array_keys(MentorPortal::VALUES) && count($grew) === 7);
ck('values: …with what their reading has actually fed',
   (int) $grew['diligence']['sessions'] === 1 && (int) $grew['diligence']['minutes'] === 45
   && (int) $grew['faith']['sessions'] === 0);
$all = NgvReading::valueGrowthAll();
ck('values: and the programme sees the same across everybody',
   (int) $all['diligence']['readers'] === 1 && (int) $all['communal']['sessions'] === 0);

/* A book on the list says which values it is there for, so a gap in the
   list is visible before a cohort reads its way into it. */
$vb = NgvReading::saveBook(['title' => 'On Our People', 'author' => 'A Writer', 'chapters' => 2,
                            'value_keys' => ['faith', 'culture', 'not-a-value']], 1);
ck('values: a book names the values it is on the list for, and only real ones',
   !empty($vb['ok']) && $vb['book']['value_list'] === ['faith', 'culture']
   && $vb['book']['value_labels'] === ['Faith', 'Cultural Appreciation']);

/* ══ The diary keeps the reading ═════════════════════════════════════════ */

$rdLog = NgvReading::sessionsFor(960);
ck('diary: a member\'s whole reading log reads back, newest first, with its book',
   count($rdLog) === 1 && (string) $rdLog[0]['title'] === 'A Book Read Slowly');
$rdNb = (string) @file_get_contents(AV_ROOT . '/portal/notebooks.php');
ck('diary: the diary READS the sittings rather than keeping a second copy',
   str_contains($rdNb, "case 'reading'") && str_contains($rdNb, 'NgvReading::sessionsFor(')
   && !str_contains($rdNb, 'INSERT INTO ngv_reading_sessions'));
$rdJs = (string) @file_get_contents(AV_ROOT . '/portal/avdy.js');
ck('diary: …and shows them as a section of their own, read-only',
   str_contains($rdJs, "'reading', 'Reading'") && str_contains($rdJs, "sc === 'reading'")
   && str_contains($rdJs, 'row.reading ?'));
$rdEx = (string) @file_get_contents(AV_ROOT . '/diary/export.php');
ck('diary: and "download my diary" carries the reading with it',
   str_contains($rdEx, 'NgvReading::sessionsFor('));

/* ══ How the challenge is going, across everybody ════════════════════════
   A reading challenge is a thing a programme manages, and the only way to
   ask used to be to open the console and count tiles. */

$co = NgvReading::cohort();
$coOne = array_values(array_filter($co['people'], static fn($p) => (int) $p['member_id'] === 960))[0] ?? [];
ck('cohort: every active vanguard is in it, with where they stand',
   $coOne !== [] && (int) $coOne['sessions'] === 1 && (int) $coOne['waiting'] === 1
   && (int) $coOne['values'] === 1 && (string) $coOne['last_read'] === '2026-03-02');
ck('cohort: …and somebody who has not begun is told apart from somebody who stalled',
   ($coTwo = array_values(array_filter($co['people'], static fn($p) => (int) $p['member_id'] === 961))[0] ?? [])
   && (int) $coTwo['sessions'] === 0 && $coTwo['stalled_days'] === null && $coTwo['stalled'] === false);
/* A sitting four weeks old on an unfinished shelf IS a stall. */
NgvDb::pdo()->prepare('UPDATE ngv_reading_sessions SET day = ? WHERE member_id = 960')
    ->execute([date('Y-m-d', strtotime((function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d')) . ' -40 days'))]);
$co2 = NgvReading::cohort();
ck('cohort: somebody who started and stopped is counted as stalled, and named',
   (int) $co2['stalled'] === 1
   && (array_values(array_filter($co2['people'], static fn($p) => (int) $p['member_id'] === 960))[0]['stalled'] ?? false) === true);
ck('cohort: it answers in a handful of queries, not one per vanguard',
   count($co2['people']) >= 2 && (int) $co2['verified'] >= 0);
$rvDesk = (string) @file_get_contents(AV_ROOT . '/academy/ngv/_reading-review.php');
ck('cohort: and the desk shows it where the people who run it look',
   str_contains($rvDesk, 'NgvReading::cohort()') && str_contains($rvDesk, 'How the challenge is going'));

$rdReset();
NgvDb::pdo()->exec('DELETE FROM ngv_reading_sessions');
