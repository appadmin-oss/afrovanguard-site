<?php
/**
 * tests/studiotoday.test.php — the Overview opens on what is waiting on you.
 *
 *   • Every queue that needs a person is listed with its count and the screen
 *     that resolves it; an empty queue is not listed.
 *   • Only what the viewer's role may act on: an editor is never shown money
 *     or member requests.
 *   • A queue that cannot be counted is said ("could not be counted"), never
 *     shown as zero — a silent "nothing waiting" hides an outage.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);
require_once AV_ROOT . '/lib/StudioSections.php';

$tdPrev = getenv('AV_PRIVATE_DIR');
$tdDir = sys_get_temp_dir() . '/av-test-today-' . getmypid();
@mkdir($tdDir, 0700, true);
putenv('AV_PRIVATE_DIR=' . $tdDir);
BankGifts::ensure();
Database::pdo()->exec('DELETE FROM bank_gifts');

$tdKeys = static fn(array $r): array => array_column($r['items'], 'n', 'key');
$tdBase = StudioToday::for('superadmin');
ck('today: with nothing pending, no bank-transfer item is listed (an empty queue is not a row)', !isset($tdKeys($tdBase)['gifts']) && !$tdBase['errors']);

foreach (['ZT-1', 'ZT-2'] as $ref) BankGifts::submit(['name' => 'Ada Obi', 'email' => 'ada@example.org', 'amount' => 5000, 'campaign' => 'general',
    'bank_ref' => $ref, 'paid_on' => date('Y-m-d')], 'first.admin@afrovanguard.org.ng');
$tdAdmin = StudioToday::for('admin');
$tdG = array_values(array_filter($tdAdmin['items'], static fn($i) => $i['key'] === 'gifts'))[0] ?? [];
ck('today: two transfers waiting are one row saying 2, pointing at the Bank transfers screen', ($tdG['n'] ?? 0) === 2 && ($tdG['go'] ?? '') === 'gifts');
$tdMin = StudioSections::minRoles();
ck('today: every item points at a real section', !array_filter($tdAdmin['items'], static fn($i) => !isset($tdMin[$i['go']])));

$tdEd = StudioToday::for('editor');
$tdLeak = array_values(array_filter($tdEd['items'], static fn($i) => (AdminRoles::RANK[$tdMin[$i['go']]] ?? 99) > AdminRoles::RANK['editor']));
ck('today: an editor is shown only what an editor may open (no money, no member desk)', !$tdLeak && !isset($tdKeys($tdEd)['gifts']), json_encode($tdLeak));
ck('today: nobody below a Super Admin is shown the AI suggestions (the Rules screen is theirs)', !isset($tdKeys($tdAdmin)['proposals']));

/* A queue that cannot be counted is said, not zero. */
Database::pdo()->exec('ALTER TABLE bank_gifts RENAME TO bank_gifts_away');
$tdBroken = StudioToday::for('admin');
Database::pdo()->exec('ALTER TABLE bank_gifts_away RENAME TO bank_gifts');
ck('today: a queue that cannot be counted is reported as such, not as nothing waiting',
    in_array('gifts', array_column($tdBroken['errors'], 'key'), true) && !isset($tdKeys($tdBroken)['gifts']));

$tdApi = (string) file_get_contents(AV_ROOT . '/admin/api.php');
$tdJs = (string) file_get_contents(AV_ROOT . '/admin/app.js');
ck('today: the Overview loads it, and says loading, nothing waiting, could-not-count and could-not-load differently',
    str_contains($tdApi, "case 'today':") && str_contains($tdJs, 'loadToday();') && str_contains($tdJs, 'Nothing is waiting on you')
    && str_contains($tdJs, 'Could not be counted just now') && str_contains($tdJs, 'id="ovTodayRetry"'));

Database::pdo()->exec('DELETE FROM bank_gifts');
foreach (glob($tdDir . '/*') ?: [] as $f) @unlink($f);
@rmdir($tdDir);
if ($tdPrev === false) putenv('AV_PRIVATE_DIR'); else putenv('AV_PRIVATE_DIR=' . $tdPrev);
