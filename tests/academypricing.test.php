<?php
/**
 * tests/academypricing.test.php — the Academy membership price is set in the
 * Studio, and the saved figure is what every page shows and Paystack charges.
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$apPdo = Database::pdo();
Database::metaSet(AcademyPricing::KEY, '');
AcademyPricing::forget();

ck('academy price: before anybody saves one, it is the configured starting price',
   AcademyPricing::membershipNgn() === AcademyPricing::shipped() && AcademyPricing::settings()['source'] === 'config');

foreach (['', 'abc', '99', '0', '-5000', '5000.50', '10000001', '5e3'] as $bad) {
    $r = AcademyPricing::save(['membership_ngn' => $bad], 'Test Admin');
    ck("academy price: \"$bad\" is refused, saying what a price is", $r['ok'] === false && str_contains((string) $r['error'], 'whole number of naira'));
}
ck('academy price: a refused price changes nothing', AcademyPricing::membershipNgn() === AcademyPricing::shipped());

$r = AcademyPricing::save(['membership_ngn' => '₦7,500'], 'Ada Admin');
ck('academy price: "₦7,500" as typed is saved as 7500', $r['ok'] === true && AcademyPricing::membershipNgn() === 7500);
AcademyPricing::forget();
$s = AcademyPricing::settings();
ck('academy price: it is kept (read back afresh), marked as set in the Studio, with who and when',
   $s['membership_ngn'] === 7500 && $s['source'] === 'studio' && $s['updated_by'] === 'Ada Admin' && $s['updated_at'] !== '');
ck('academy price: the change is in its history, from and to', ($s['history'][0]['from'] ?? null) === AcademyPricing::shipped() && ($s['history'][0]['to'] ?? null) === 7500);
ck('academy price: saving the same price again is not a change', (AcademyPricing::save(['membership_ngn' => '7500'], 'Ada Admin')['unchanged'] ?? false) === true
   && count(AcademyPricing::settings()['history']) === 1);

$aud = (int) $apPdo->query("SELECT COUNT(*) FROM lms_audit WHERE action = 'academy.membership_price'")->fetchColumn();
ck('academy price: every change is on the audit trail', $aud >= 1);

/* A payment started before a change is held to the price it was started at. */
$apPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?,?,?,?,?)')
      ->execute(['Price Payer', 'pricepayer' . getmypid() . '@test.ng', 'x', 'learner', 'active']);
$apUid = (int) $apPdo->lastInsertId();
$apLms = new LmsRepository();
$apRef = 'AP-' . getmypid() . '-' . bin2hex(random_bytes(3));
$apLms->createPayment($apUid, 'membership', null, AcademyPricing::membershipNgn() * 100, $apRef, 'paystack', 12);
AcademyPricing::save(['membership_ngn' => '9000'], 'Ada Admin');
ck('academy price: a rise does not refuse somebody who started checkout at the old price',
   $apLms->finalizePayment($apRef, 750000, 'NGN') === true);
$apRef2 = $apRef . 'b';
$apLms->createPayment($apUid, 'membership', null, AcademyPricing::membershipNgn() * 100, $apRef2, 'paystack', 12);
AcademyPricing::save(['membership_ngn' => '2000'], 'Ada Admin');
ck('academy price: a cut does not let somebody who started at the higher price pay less',
   $apLms->finalizePayment($apRef2, 200000, 'NGN') === false);

/* Every place the price is shown or charged reads the one figure. */
/* Pages go through AcademyDonation (donation or fixed), whose fixed price IS this figure. */
$adSrc = (string) file_get_contents(AV_ROOT . '/lib/AcademyDonation.php');
ck('academy price: AcademyDonation\'s fixed price is AcademyPricing\'s, never a copy or the constant',
   str_contains($adSrc, 'return AcademyPricing::membershipNgn();') && !str_contains($adSrc, 'AV_MEMBERSHIP_NGN') && !str_contains($adSrc, "'fixed_ngn'     =>"));
foreach (['academy/index.php', 'academy/api.php', 'academy/course.php', 'academy/learn.php', 'lib/AiKnowledge.php'] as $f) {
    $src = (string) file_get_contents(AV_ROOT . '/' . $f);
    ck("academy price: $f reads AcademyPricing, not the constant", (str_contains($src, 'AcademyPricing::membershipNgn()') || str_contains($src, 'AcademyDonation::')) && !str_contains($src, 'AV_MEMBERSHIP_NGN'));
}
$apApi = (string) file_get_contents(AV_ROOT . '/admin/api.php');
preg_match('/\$managementOnly = \[(.*?)\];/s', $apApi, $apMo);
ck('academy price: only admins and above may set or reset it (not content editors)',
   str_contains($apMo[1] ?? '', "'ac_pricing_save'") && str_contains($apMo[1] ?? '', "'ac_pricing_reset'"));
ck('academy price: the Studio overview has the pricing form',
   str_contains((string) file_get_contents(AV_ROOT . '/academy/studio/index.php'), 'id="pricingForm"'));

$r = AcademyPricing::reset('Ada Admin');
AcademyPricing::forget();
ck('academy price: reset goes back to the starting price and keeps the history',
   $r['ok'] === true && AcademyPricing::membershipNgn() === AcademyPricing::shipped() && AcademyPricing::settings()['source'] === 'config'
   && count(AcademyPricing::settings()['history']) >= 3);

Database::metaSet(AcademyPricing::KEY, '');
AcademyPricing::forget();
