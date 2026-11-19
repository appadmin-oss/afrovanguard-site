<?php
/**
 * tests/moneydesk.test.php — the donations ledger, donors, and Money as a
 * trust granted to a person.
 *
 *   • Every gift goes into a permanent ledger with the donor's real name; the
 *     public display file still says "Anonymous" for anyone off the wall, and
 *     no public page reads the ledger.
 *   • Gifts recorded before the ledger are carried over from the file, once.
 *   • The list filters above the page: the total and sum are the filter's.
 *   • Donors: gave last year and not yet this year (LYBUNT), new this year,
 *     regular; retention is last year's donors who gave again.
 *   • Money is granted per person by a Super Admin; no role carries it; every
 *     money action is behind one refusal; an export of donors is logged and
 *     cannot smuggle a spreadsheet formula.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$mdPrev = getenv('AV_PRIVATE_DIR');
$mdDir = sys_get_temp_dir() . '/av-test-money-' . getmypid();
@mkdir($mdDir, 0700, true);
putenv('AV_PRIVATE_DIR=' . $mdDir);
Donations::ensure();
$mdPdo = Database::pdo();
$mdPdo->exec('DELETE FROM donations_ledger');
$mdPdo->exec("DELETE FROM donation_refs WHERE reference LIKE 'MD-%'");
Database::metaSet('donations_ledger_backfilled', '');

/* A gift recorded before the ledger existed, sitting only in the display file. */
$mdOld = Donations::defaultData();
$mdOld['donations'][] = ['id' => 'MD-OLD-1', 'reference' => 'MD-OLD-1', 'type' => 'card', 'name' => 'Old Donor', 'email' => 'old@example.org', 'amount' => 3000,
                         'currency' => 'NGN', 'campaign' => 'general', 'frequency' => 'One-time', 'listed' => true, 'created_at' => (date('Y') - 1) . '-03-01T10:00:00+01:00'];
Donations::write($mdOld);

$mdGift = static function (string $ref, string $email, string $real, float $amount, string $when, bool $listed = false, string $freq = 'One-time', string $camp = 'general'): bool {
    $entry = ['id' => $ref, 'reference' => $ref, 'type' => 'card', 'name' => $listed ? $real : 'Anonymous', 'email' => $email, 'anonymous' => !$listed, 'listed' => $listed,
              'amount' => $amount, 'currency' => 'NGN', 'campaign' => $camp, 'frequency' => $freq, 'status' => 'confirmed', 'created_at' => $when];
    return Donations::storeIfNew($ref, $entry, $amount, $camp, 'NGN', ['name' => $real]);
};
$ly = (string) (date('Y') - 1); $ty = date('Y');
$mdGift('MD-1', 'ngozi@example.org', 'Ngozi Eze', 10000, $ly . '-05-01T09:00:00+01:00');          // last year only → LYBUNT
$mdGift('MD-2', 'tunde@example.org', 'Tunde Bello', 5000, $ly . '-06-01T09:00:00+01:00');         // last year…
$mdGift('MD-3', 'tunde@example.org', 'Tunde Bello', 7000, $ty . '-02-01T09:00:00+01:00', false, 'Monthly');   // …and this year, monthly
$mdGift('MD-4', 'amaka@example.org', 'Amaka Obi', 20000, $ty . '-03-01T09:00:00+01:00', true, 'One-time', 'sts');   // new this year

$mdFile = Donations::read();
$mdShown = array_values(array_filter($mdFile['donations'], static fn($d) => ($d['reference'] ?? '') === 'MD-1'))[0] ?? [];
ck('ledger: the public display file still says "Anonymous" for a donor off the wall', ($mdShown['name'] ?? '') === 'Anonymous');
$mdL = Donations::ledger(['email' => 'ngozi@example.org']);
ck('ledger: …while the office\'s ledger keeps their real name', ($mdL['rows'][0]['name'] ?? '') === 'Ngozi Eze' && $mdL['total'] === 1);
ck('ledger: a gift recorded before the ledger is carried over from the file', Donations::ledger(['email' => 'old@example.org'])['total'] === 1);
Donations::ledgerBackfill();
ck('ledger: …once, not again each time it is read', Donations::ledger(['email' => 'old@example.org'])['total'] === 1);
ck('ledger: a gift stored twice is one row (the reference decides)', !$mdGift('MD-1', 'ngozi@example.org', 'Ngozi Eze', 10000, $ly . '-05-01T09:00:00+01:00')
    && Donations::ledger(['email' => 'ngozi@example.org'])['total'] === 1);

$mdAll = Donations::ledger(['page_size' => 10]);
ck('ledger: newest first, with every gift counted', $mdAll['total'] === 5 && ($mdAll['rows'][0]['reference'] ?? '') === 'MD-4');
$mdPage = Donations::ledger(['page_size' => 10, 'campaign' => 'general', 'page' => 0]);
ck('ledger: the total and the sum are the filter\'s, not the page\'s', $mdPage['total'] === 4 && abs($mdPage['sum_ngn'] - 25000.0) < 0.01);
ck('ledger: dates filter by the day given', Donations::ledger(['from' => $ty . '-01-01'])['total'] === 2);
ck('ledger: a search finds a donor by name, email or reference', Donations::ledger(['q' => 'tunde'])['total'] === 2 && Donations::ledger(['q' => 'MD-4'])['total'] === 1);
ck('ledger: an unknown kind is ignored, not an error', Donations::ledger(['type' => 'bitcoin'])['total'] === 5);

$mdD = Donations::donors();
$mdBy = array_column($mdD['donors'], null, 'email');
ck('donors: one row per person, with all their giving', ($mdBy['tunde@example.org']['gifts'] ?? 0) === 2 && abs(($mdBy['tunde@example.org']['total'] ?? 0) - 12000.0) < 0.01);
ck('donors: gave last year and not yet this year (the people most worth a call)',
    array_column(Donations::donors('lybunt')['donors'], 'email') == ['ngozi@example.org', 'old@example.org'] || array_column(Donations::donors('lybunt')['donors'], 'email') == ['old@example.org', 'ngozi@example.org']);
ck('donors: new this year', array_column(Donations::donors('new')['donors'], 'email') === ['amaka@example.org']);
ck('donors: giving regularly', array_column(Donations::donors('recurring')['donors'], 'email') === ['tunde@example.org']);
ck('donors: retention is last year\'s donors who gave again (1 of 3)', $mdD['retention'] === 33 && $mdD['retained'] === 1 && $mdD['last_year_donors'] === 3);
$mdS = Donations::summary();
ck('summary: this year\'s naira and donors', abs($mdS['year_ngn'] - 27000.0) < 0.01 && $mdS['donors_year'] === 2);

/* Money is a trust granted to a person. */
AdminRoles::ensure();
$mdPdo->exec("DELETE FROM admin_users WHERE email LIKE 'md.%'");
AdminRoles::add('md.admin@afrovanguard.org.ng', 'admin', 'test');
AdminRoles::add('md.super@afrovanguard.org.ng', 'superadmin', 'test');
ck('money: no role carries it — a new admin has no Money access', AdminRoles::capsForEmail('md.admin@afrovanguard.org.ng') === []);
$mdSet = AdminRoles::setCaps('md.admin@afrovanguard.org.ng', ['money', 'rockets']);
ck('money: a Super Admin grants it to a person (and nothing that is not a trust)', !empty($mdSet['ok']) && AdminRoles::capsForEmail('md.admin@afrovanguard.org.ng') === ['money']);
ck('money: only to somebody already on the team', (AdminRoles::setCaps('stranger@afrovanguard.org.ng', ['money'])['code'] ?? '') === 'not_admin');
ck('money: a Super Admin needs no grant', (AdminRoles::setCaps('md.super@afrovanguard.org.ng', ['money'])['code'] ?? '') === 'superadmin');
AdminRoles::setCaps('md.admin@afrovanguard.org.ng', []);
ck('money: …and it can be withdrawn', AdminRoles::capsForEmail('md.admin@afrovanguard.org.ng') === []);
$mdPdo->exec("DELETE FROM admin_users WHERE email LIKE 'md.%'");

$mdApi = (string) file_get_contents(AV_ROOT . '/admin/api.php');
ck('money: every money action is behind one refusal',
    (bool) preg_match("/\\\$moneyOnly = \\['gifts_list', 'gifts_submit', 'gifts_decide', 'donations_list', 'donations_donors', 'donations_export'\\];/", $mdApi)
    && str_contains($mdApi, "!AdminRoles::hasCap('money')"));
ck('money: granting it is a Super Admin\'s, and logged', (bool) preg_match("/\\\$superadminOnly = \\[[^\\]]*'admin_caps_save'/", $mdApi) && str_contains($mdApi, "AdminAudit::log('admins', 'admin_caps'"));
ck('money: an export of donors is logged, and a cell cannot start a formula',
    str_contains($mdApi, "AdminAudit::log('money', 'donations_export'") && str_contains($mdApi, 'foreach ($rows as $r) Csv::put($out,'));
$mdPd = (string) file_get_contents(AV_ROOT . '/process-donation.php');
ck('money: no public page reads the ledger (the wall reads only what donors agreed to)', !str_contains($mdPd, 'donations_ledger') && !str_contains($mdPd, 'Donations::ledger('));
ck('money: the Appeals console needs the trust too', str_contains((string) file_get_contents(AV_ROOT . '/give/manage.php'), "AdminRoles::hasCap('money')"));
require_once AV_ROOT . '/lib/StudioSections.php';
ck('money: the Money screens are marked as needing it, for the Studio to hide', (StudioSections::forPage()['caps'] ?? []) === ['gifts' => 'money', 'donations' => 'money', 'appeals' => 'money']);

$mdPdo->exec('DELETE FROM donations_ledger');
$mdPdo->exec("DELETE FROM donation_refs WHERE reference LIKE 'MD-%'");
Database::metaSet('donations_ledger_backfilled', '');
foreach (glob($mdDir . '/*') ?: [] as $f) @unlink($f);
@rmdir($mdDir);
if ($mdPrev === false) putenv('AV_PRIVATE_DIR'); else putenv('AV_PRIVATE_DIR=' . $mdPrev);
