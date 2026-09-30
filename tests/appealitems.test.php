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

/* ══ Pictures ═════════════════════════════════════════════════════════════
 *
 * An item's picture is uploaded, not pasted as a link, so what matters is
 * that the file is judged on its own bytes rather than on what the browser
 * claimed it was. A .php named .jpg landing in a web-served folder is the
 * whole reason this is checked server-side.
 */

$itReset();
$itPic = Appeals::saveItem(['title' => 'Plastic chairs', 'kind' => 'goods', 'qty_needed' => 40], 'tester');
$itPicId = (int) $itPic['id'];

ck('images: an item starts with no picture', (string) Appeals::item($itPicId)['image_url'] === '');

/* A real 8x8 PNG, built here so the test carries no binary fixture. */
$itPng = (static function (): string {
    $chunk = static function (string $t, string $d): string {
        return pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    };
    $raw = '';
    for ($y = 0; $y < 8; $y++) $raw .= "\x00" . str_repeat("\xc8\x78\x1e", 8);
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', 8, 8, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress($raw))
        . $chunk('IEND', '');
})();

$itTmp = sys_get_temp_dir() . '/av-item-' . getmypid() . '.png';
file_put_contents($itTmp, $itPng);

ck('images: a picture with no item is refused',
   empty(Appeals::setItemImage(999999, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $itTmp, 'name' => 'x.png', 'size' => 10])['ok']));
ck('images: choosing nothing is refused, and says so',
   (static function () use ($itPicId) {
        $r = Appeals::setItemImage($itPicId, ['error' => UPLOAD_ERR_NO_FILE]);
        return empty($r['ok']) && str_contains((string) $r['error'], 'No picture');
   })());
ck('images: a file over the server limit is refused with the limit named',
   (static function () use ($itPicId, $itTmp) {
        $r = Appeals::setItemImage($itPicId, ['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => $itTmp, 'name' => 'x.png', 'size' => 1]);
        return empty($r['ok']) && str_contains((string) $r['error'], 'larger than this server');
   })());
ck('images: an oversized file is refused before it is stored',
   empty(Appeals::setItemImage($itPicId, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $itTmp,
                                          'name' => 'x.png', 'size' => 50 * 1048576])['ok']));

/* The one that matters: a script renamed .png. The type is read from the
   bytes, so the name buys nothing. */
$itBad = sys_get_temp_dir() . '/av-item-bad-' . getmypid() . '.png';
file_put_contents($itBad, "<?php echo 'pwned'; ?>\n");
$itBadR = Appeals::setItemImage($itPicId, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $itBad,
                                           'name' => 'innocent.png', 'size' => filesize($itBad)]);
ck('images: a PHP script named .png is refused', empty($itBadR['ok']));
ck('images: and the refusal names what it actually was',
   str_contains((string) $itBadR['error'], 'has to be a picture'));
ck('images: nothing was attached by the attempt', (string) Appeals::item($itPicId)['image_url'] === '');

$itOkR = Appeals::setItemImage($itPicId, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $itTmp,
                                          'name' => 'chair.png', 'size' => filesize($itTmp)], 'tester');
ck('images: a real picture is accepted', !empty($itOkR['ok']));
ck('images: and is on the item afterwards', (string) Appeals::item($itPicId)['image_url'] !== '');
ck('images: the catalogue carries it',
   (static function () use ($itPicId) {
        foreach (Appeals::items(['limit' => 50]) as $i) {
            if ((int) $i['id'] === $itPicId) return (string) $i['image_url'] !== '';
        }
        return false;
   })());
ck('images: it can be taken off again',
   !empty(Appeals::clearItemImage($itPicId, 'tester')['ok'])
   && (string) Appeals::item($itPicId)['image_url'] === '');

/* A saveItem that does not mention the picture must not wipe it — the same
   partial-write rule every other field here follows. */
Appeals::setItemImage($itPicId, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $itTmp, 'name' => 'c.png', 'size' => filesize($itTmp)], 't');
Appeals::saveItem(['id' => $itPicId, 'qty_needed' => 50], 'tester');
ck('images: editing another field leaves the picture alone',
   (string) Appeals::item($itPicId)['image_url'] !== '');

@unlink($itTmp); @unlink($itBad);

/* ══ The schema survives a non-SQLite engine ══════════════════════════════
 * The items table is new, and a table that fails to CREATE is invisible:
 * execSchema only logs, so every insert afterwards fails while the page
 * looks fine. These pin the translation rather than waiting for a install
 * on another engine to find out.
 */
$itDdl = (static function (): string {
    $m = new ReflectionMethod('Appeals', 'ddl'); $m->setAccessible(true);
    $d = (string) $m->invoke(null);
    $i = strpos($d, 'CREATE TABLE IF NOT EXISTS av_appeal_items');
    return substr($d, $i, strpos($d, 'CREATE TABLE', $i + 10) - $i);
})();
foreach (['mysql', 'pgsql'] as $itDrv) {
    $t = Database::translateDDL($itDdl, $itDrv);
    /* MySQL rejects a default on TEXT (1101). MariaDB allows it, which is how
       such a column ships unnoticed — so the table must not contain one. */
    ck('items schema: no TEXT column carries a default on ' . $itDrv,
       !preg_match('/\bTEXT\s+(NOT\s+NULL\s+)?DEFAULT/i', $t));
    ck('items schema: AUTOINCREMENT is translated for ' . $itDrv,
       !str_contains(strtoupper($t), 'AUTOINCREMENT'));
}
ck('items schema: MySQL gets InnoDB and utf8mb4',
   str_contains(Database::translateDDL($itDdl, 'mysql'), 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
ck('items schema: MySQL has no IF NOT EXISTS on an index',
   !preg_match('/CREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS/i', Database::translateDDL($itDdl, 'mysql')));

$itReset();
