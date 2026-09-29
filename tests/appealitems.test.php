<?php
/**
 * tests/appealitems.test.php — the giving catalogue.
 *
 * An ITEM is the answer to "what does my money actually buy", which the
 * donation research says is the question people abandon a gift over. It
 * replaces twenty-three hand-typed list rows on the donate page whose counts
 * never moved however many laptops came in.
 *
 * So what matters here is that the count is REAL: that it only moves through
 * fundItem(), that it cannot run past what was asked for, and that a partial
 * edit does not quietly blank the rest of the record — which is exactly the
 * bug Appeals::save() shipped with earlier.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$itReset = static function (): void {
    Appeals::ensure();
    try { Database::pdo()->exec('DELETE FROM av_appeal_items'); } catch (Throwable $e) {}
};

$itReset();

/* ── what an item must carry ─────────────────────────────────────────────── */

ck('items: an item without a name is refused',
   empty(Appeals::saveItem(['title' => '', 'unit_cost' => 5000])['ok']));
ck('items: a priced item without a price is refused',
   empty(Appeals::saveItem(['title' => 'Exercise books', 'kind' => 'money', 'unit_cost' => 0])['ok']));
/* Because a priced item with no price is the hand-typed list again, wearing
   a database. An in-kind item is different: the thing IS the gift. */
$itGoods = Appeals::saveItem(['title' => 'Laptops', 'kind' => 'goods', 'detail' => 'Any working condition',
                              'category' => 'ICT', 'qty_needed' => 18], 'tester');
ck('items: an in-kind item needs no price', !empty($itGoods['ok']));
ck('items: and is stored carrying none',
   (int) Appeals::item((int) $itGoods['id'])['unit_cost'] === 0);

$itBooks = Appeals::saveItem(['title' => "A term's exercise books", 'kind' => 'money', 'unit_cost' => 4500,
                              'unit_label' => 'set', 'category' => 'Classroom', 'qty_needed' => 40], 'tester');
ck('items: a priced item with its price is accepted', !empty($itBooks['ok']));

/* ── slugs ───────────────────────────────────────────────────────────────── */

$itDup = Appeals::saveItem(['title' => 'Laptops', 'kind' => 'goods', 'qty_needed' => 5], 'tester');
ck('items: a second item of the same name gets its own slug',
   !empty($itDup['ok'])
   && Appeals::item((int) $itDup['id'])['slug'] !== Appeals::item((int) $itGoods['id'])['slug']);
ck('items: and each is findable by that slug',
   Appeals::itemBySlug((string) Appeals::item((int) $itBooks['id'])['slug'])['id'] === (int) $itBooks['id']);

/* ── the count only moves one way ────────────────────────────────────────── */

ck('items: a new item starts at nothing covered',
   (int) Appeals::item((int) $itBooks['id'])['qty_funded'] === 0
   && (int) Appeals::item((int) $itBooks['id'])['qty_left'] === 40);

Appeals::fundItem((int) $itBooks['id'], 12, 'tester');
$itB = Appeals::item((int) $itBooks['id']);
ck('items: funding moves the count', (int) $itB['qty_funded'] === 12);
ck('items: and what is left follows it', (int) $itB['qty_left'] === 28);
ck('items: the percentage is derived, not stored separately', (int) $itB['pct'] === 30);
ck('items: it is still open', !empty($itB['is_open']));

/* Over-funding is a real case — two people cover the last one at once — and
   a list that reads "41 of 40" reads as a page nobody maintains. */
Appeals::fundItem((int) $itBooks['id'], 500, 'tester');
$itB = Appeals::item((int) $itBooks['id']);
ck('items: the count cannot run past what was asked for', (int) $itB['qty_funded'] === 40);
ck('items: reaching the number closes the item', (string) $itB['status'] === 'funded');
ck('items: and a closed item is no longer open', empty($itB['is_open']));
ck('items: funding a covered item is refused',
   empty(Appeals::fundItem((int) $itBooks['id'], 1, 'tester')['ok']));
ck('items: funding something that does not exist is refused',
   empty(Appeals::fundItem(999999, 1)['ok']));

/* ── a partial edit must not blank the rest ──────────────────────────────── */

$itEdit = Appeals::saveItem(['title' => 'Plastic chairs', 'kind' => 'money', 'unit_cost' => 3500,
                             'unit_label' => 'chair', 'category' => 'Centre setup', 'detail' => 'Stackable, for the hall',
                             'qty_needed' => 60], 'tester');
Appeals::fundItem((int) $itEdit['id'], 10, 'tester');
Appeals::saveItem(['id' => (int) $itEdit['id'], 'qty_needed' => 80], 'tester');
$itE = Appeals::item((int) $itEdit['id']);
ck('items: editing one field leaves the title alone',   (string) $itE['title'] === 'Plastic chairs');
ck('items: leaves the price alone',                      (int) $itE['unit_cost'] === 3500);
ck('items: leaves the detail alone',                     (string) $itE['detail'] === 'Stackable, for the hall');
ck('items: leaves the category alone',                   (string) $itE['category'] === 'Centre setup');
ck('items: leaves what people already gave alone',       (int) $itE['qty_funded'] === 10);
ck('items: and applies the change that was asked for',   (int) $itE['qty_needed'] === 80);

/* Raising the target on a funded item should reopen it, or the page claims
   something is covered while asking for more of it. */
Appeals::saveItem(['id' => (int) $itBooks['id'], 'qty_needed' => 60, 'status' => 'live'], 'tester');
$itB = Appeals::item((int) $itBooks['id']);
ck('items: raising the target leaves it open again',
   (int) $itB['qty_left'] === 20 && !empty($itB['is_open']));

/* ── the catalogue ───────────────────────────────────────────────────────── */

ck('items: the catalogue lists what is live', count(Appeals::items(['limit' => 50])) >= 4);
ck('items: filtering by kind works',
   (static function () {
        foreach (Appeals::items(['kind' => 'goods']) as $i) if ((string) $i['kind'] !== 'goods') return false;
        return Appeals::items(['kind' => 'goods']) !== [];
   })());
ck('items: grouping by category keeps them under their heading',
   array_key_exists('Classroom', Appeals::itemsByCategory(['limit' => 50])));

/* Hidden means hidden — it is the alternative to deleting a record somebody
   may have already given against. */
Appeals::saveItem(['id' => (int) $itDup['id'], 'status' => 'hidden'], 'tester');
ck('items: a hidden item leaves the catalogue',
   !in_array((int) $itDup['id'], array_column(Appeals::items(['limit' => 90]), 'id'), true));
ck('items: but is still there when asked for directly',
   Appeals::item((int) $itDup['id']) !== null);

/* Funded items sort last: a list that leads with what is done buries the ask. */
$itOrder = array_map(static fn(array $i): string => (string) $i['status'], Appeals::items(['limit' => 90]));
$itSeenFunded = false; $itOk = true;
foreach ($itOrder as $stt) {
    if ($stt === 'funded') $itSeenFunded = true;
    elseif ($itSeenFunded) $itOk = false;
}
ck('items: covered items sort below the ones still needed', $itOk);

/* ── the summary the page leads with ─────────────────────────────────────── */

$itSum = Appeals::itemsSummary(['limit' => 90]);
ck('items: the summary counts what is open', $itSum['open'] >= 1);
ck('items: and totals only the money still outstanding',
   $itSum['outstanding_ngn'] === array_sum(array_map(
       static fn(array $i): int => (string) $i['kind'] === 'money' ? (int) $i['line_ngn'] : 0,
       Appeals::items(['limit' => 90]))));
ck('items: in-kind things are counted, not priced',
   $itSum['goods_left'] > 0);

/* ── deleting ────────────────────────────────────────────────────────────── */

ck('items: an item can be deleted outright', Appeals::deleteItem((int) $itDup['id']));
ck('items: and is then gone', Appeals::item((int) $itDup['id']) === null);

$itReset();
