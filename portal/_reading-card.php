<?php
/**
 * portal/_reading-card.php — the 24-book challenge, for whoever is reading it.
 *
 * ONE card, drawn in two places: inside the NextGen Vanguard programme view
 * (portal/views/ngv.php) and on its own Reading page for a member or a
 * volunteer (portal/views/reading.php). It is a partial and not two copies
 * because the shelf, the slots and the claim sheet would drift apart within a
 * season otherwise — and the thing that drifts is what somebody is told their
 * reading is worth.
 *
 * What varies between the two is the WORDS, not the machinery: a vanguard's
 * books are checked by their track lead and sit inside a programme; a
 * member's or a volunteer's are checked by whoever the office has given the
 * reading to. Saying "your track lead" to somebody who has no track sends
 * them looking for a person who does not exist.
 *
 * Expects, from the host view:
 *   $u          the signed-in member
 *   $rdKind     'vanguard' | 'member' | 'volunteer' (NgvReading::readerKind)
 *   $rdProgress NgvReading::progress()
 *   $rdShelf    NgvReading::shelf()
 *
 * The slots keep data-slot inside #books, because academy/ngv/reading.js
 * opens the claim sheet from them.
 */
declare(strict_types=1);

$rdStWord = ['empty' => 'not started', 'draft' => 'draft', 'submitted' => 'with the checker',
             'resubmit' => 'sent back', 'rejected' => 'not accepted', 'verified' => 'verified'];
$rdStTone = ['draft' => 'gray', 'submitted' => 'gold', 'resubmit' => 'gold', 'rejected' => 'red', 'verified' => 'green'];
/* Who checks it, in words the reader can act on. A vanguard has a track lead;
   nobody else does. */
$rdChecker = $rdKind === 'vanguard' ? 'your track lead' : 'the reading checker';
$rdStWord['submitted'] = 'with ' . $rdChecker;

$rdFirstEmpty = 0;
foreach ($rdShelf as $rdSlot => $rdBc) {
    if ((string) ($rdBc['status'] ?? 'empty') === 'empty') { $rdFirstEmpty = (int) $rdSlot; break; }
}
$rdRecorded = array_values(array_filter($rdShelf, static fn($b) => (string) ($b['status'] ?? 'empty') !== 'empty'));
?>
                <section class="avng-card" aria-labelledby="avng-bk-h" id="ngv-books">
                  <div class="avng-card-h"><h2 id="avng-bk-h">24 books in 12 months</h2><span class="av-num"><?= (int) $rdProgress['verified'] ?> / <?= (int) $rdProgress['total'] ?></span></div>
                  <div class="avng-books" id="books">
<?php if ($rdKind !== 'vanguard'): ?>
                    <p class="avng-pace">Twenty-four books in a year, read a sitting at a time. Each one is checked by somebody at the office before it counts — the same challenge the NextGen Vanguards do, open to you because you are <?= $rdKind === 'volunteer' ? 'a volunteer' : 'a member' ?>.</p>
<?php endif; ?>
                    <div class="avng-slots" role="group" aria-label="Your 24 book slots">
<?php foreach ($rdShelf as $rdSlot => $rdBc): $rdSt = (string) ($rdBc['status'] ?? 'empty'); ?>
                      <button type="button" class="avng-slot is-<?= e($rdSt) ?>" data-slot="<?= (int) $rdSlot ?>" aria-label="Book <?= (int) $rdSlot ?>, <?= e($rdStWord[$rdSt] ?? 'not started') ?><?= ($rdBc['title'] ?? '') !== '' ? ': ' . e((string) $rdBc['title']) : '' ?>"></button>
<?php endforeach; ?>
                    </div>
                    <p class="avng-pace"><?= (int) $rdProgress['verified'] ?> verified<?= $rdProgress['waiting'] > 0 ? ' · ' . (int) $rdProgress['waiting'] . ' with ' . e($rdChecker) : '' ?><?= $rdProgress['needs_work'] > 0 ? ' · ' . (int) $rdProgress['needs_work'] . ' to put right' : '' ?>. Only verified books count. Two a month keeps you on pace.</p>
<?php if (!empty($rdProgress['spot_pending'])): ?>
                    <p class="avng-callout"><b><?= $rdKind === 'vanguard' ? 'Your track lead' : 'Somebody' ?> will ask you about one of your books.</b> It happens every six books and the book is picked at random, so it could be any of them. Nothing to prepare — if you read them, you can talk about them.</p>
<?php endif; ?>
<?php if ($rdFirstEmpty): ?>
                    <button type="button" class="avng-record" data-slot="<?= $rdFirstEmpty ?>">Record a book — choose from the book list…</button>
<?php endif; ?>
<?php if ($rdRecorded): ?>
                    <ul class="avng-shelf">
<?php foreach ($rdRecorded as $rdBc): $rdSt = (string) $rdBc['status']; $rdEd = in_array($rdSt, ['draft', 'resubmit'], true);
      $rdChN = (int) ($rdBc['chapters'] ?? 0);
      $rdChW = count(array_filter((array) ($rdBc['chapter_notes_list'] ?? []), static fn($x) => trim((string) $x) !== ''));
      $rdSits = (array) ($rdBc['sessions'] ?? []); $rdMin = (int) ($rdBc['session_minutes'] ?? 0); ?>
                      <li>
                        <span class="avng-shelf-t"><span><?= e((string) $rdBc['title']) ?></span><span><?= e((string) ($rdBc['author'] ?? '')) ?><?= $rdChN ? ' · ' . $rdChW . ' of ' . $rdChN . ' chapters' : '' ?><?= $rdSits ? ' · ' . count($rdSits) . ' sitting' . (count($rdSits) === 1 ? '' : 's') . ', ' . $rdMin . ' min' : ' · no reading logged yet' ?></span></span>
                        <span class="avng-chip" data-tone="<?= e($rdStTone[$rdSt] ?? 'gray') ?>"><?= e($rdStWord[$rdSt] ?? $rdSt) ?></span>
                        <button type="button" class="avng-btn" data-slot="<?= (int) $rdBc['slot'] ?>"><?= $rdEd ? 'Continue' : 'View' ?></button>
                      </li>
<?php endforeach; ?>
                    </ul>
<?php endif; ?>
                  </div>
<?php require AV_ROOT . '/academy/ngv/_book-modal.php'; ?>
                </section>
