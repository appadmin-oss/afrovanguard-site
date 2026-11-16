<?php
/**
 * tests/academydonation.test.php — Academy membership is a donation from $20 a year (16 Nov 2026).
 */
declare(strict_types=1);

ck('donation: the minimum is $20 by default', AcademyDonation::minUsd() === (defined('AV_MEMBERSHIP_MIN_USD') ? (int) AV_MEMBERSHIP_MIN_USD : 20));
$r = AcademyDonation::rate();
ck('donation: the naira minimum is $20 at the rate, rounded up to the next ₦100',
   AcademyDonation::minNgn() === (int) (ceil(AcademyDonation::minUsd() * $r / 100) * 100) && AcademyDonation::minNgn() % 100 === 0);
ck('donation: the naira charged never falls below the dollars at the rate', AcademyDonation::ngnFor(20.01) >= 20.01 * $r);

$min = AcademyDonation::check('');
ck('donation: no amount means the minimum', $min['ok'] && $min['usd'] == AcademyDonation::minUsd() && $min['ngn'] === AcademyDonation::minNgn());
ck('donation: less than the minimum is refused, saying the minimum', !AcademyDonation::check('19.99')['ok']
   && str_contains(AcademyDonation::check('5')['error'], '$' . AcademyDonation::minUsd()));
$more = AcademyDonation::check('$50');
ck('donation: a larger gift is charged in full', $more['ok'] && $more['ngn'] === AcademyDonation::ngnFor(50));
ck('donation: a gift with cents is accepted', AcademyDonation::check('25.50')['ok']);
ck('donation: words, negatives and three decimals are refused', !AcademyDonation::check('twenty')['ok']
   && !AcademyDonation::check('-30')['ok'] && !AcademyDonation::check('20.001')['ok']);
ck('donation: an implausibly large figure is refused as a typo', !AcademyDonation::check((string) (AcademyDonation::MAX_USD + 1))['ok']);
ck('donation: the headline reads From $20 / yr', AcademyDonation::headline() === 'From $' . AcademyDonation::minUsd() . ' / yr');

$f = AcademyDonation::field('t-give');
ck('donation: the field starts at the minimum and tells the browser the floor',
   str_contains($f, 'min="' . AcademyDonation::minUsd() . '"') && str_contains($f, 'value="' . AcademyDonation::minUsd() . '"') && str_contains($f, 'data-avac-amount'));
ck('donation: the field says what is paid in naira, and is labelled', str_contains($f, 'You pay ₦' . number_format(AcademyDonation::minNgn()))
   && str_contains($f, '<label class="avac-give-l" for="t-give"'));

/* Wiring: the checkout charges the chosen gift, the pages no longer quote the old fixed price. */
$root = dirname(__DIR__);
$api = (string) file_get_contents($root . '/academy/api.php');
ck('donation: pay_init checks the gift and charges its naira figure',
   str_contains($api, "AcademyDonation::check(\$body['amount_usd'] ?? '')") && str_contains($api, "\$amountNgn = (int) \$gift['ngn'];"));
ck('donation: pay_init no longer charges the fixed AV_MEMBERSHIP_NGN', !str_contains($api, '$amountNgn = (int) AV_MEMBERSHIP_NGN'));
foreach (['academy/course.php', 'academy/index.php', 'academy/learn.php', 'lib/AiKnowledge.php'] as $page) {
    ck("donation: {$page} no longer quotes the fixed ₦ membership price", !str_contains((string) file_get_contents($root . '/' . $page), 'AV_MEMBERSHIP_NGN'));
}
ck('donation: the pay button sends the amount typed on its card', str_contains((string) file_get_contents($root . '/academy/avac.js'), 'amount_usd: amt ? amt.value'));

/* ── Configurable by the office (Studio → Members → Dues → Academy membership) ── */
$by = 'test@afrovanguard.org.ng';
$bad = AcademyDonation::save(['min_usd' => '0', 'usd_ngn' => 'lots', 'mode' => 'free'], $by);
ck('settings: a zero minimum, a worded rate and an unknown mode are all refused, and nothing is saved',
   !$bad['ok'] && isset($bad['fields']['min_usd'], $bad['fields']['usd_ngn'], $bad['fields']['mode']) && AcademyDonation::minUsd() >= 1);
$ok = AcademyDonation::save(['min_usd' => '25', 'usd_ngn' => '1,600'], $by);
AcademyDonation::forget();
ck('settings: a new minimum and rate are saved and read back after the cache is cleared',
   $ok['ok'] && AcademyDonation::minUsd() === 25 && AcademyDonation::rate() === 1600 && AcademyDonation::minNgn() === 40000);
ck('settings: the checkout follows the saved minimum', !AcademyDonation::check('24')['ok'] && AcademyDonation::check('25')['ngn'] === 40000);
ck('settings: saving the same values again changes nothing', !empty(AcademyDonation::save(['min_usd' => '25'], $by)['unchanged']));

AcademyDonation::save(['headline' => 'Give from ${min_usd} a year', 'note' => 'That is {min_ngn} naira at {rate} to the dollar.'], $by);
ck('settings: the office words the headline and note, with placeholders filled',
   AcademyDonation::headline() === 'Give from $25 a year' && AcademyDonation::note() === 'That is 40,000 naira at 1,600 to the dollar.');
ck('settings: an overlong headline is refused', !AcademyDonation::save(['headline' => str_repeat('x', AcademyDonation::MAX_TEXT + 1)], $by)['ok']);

AcademyDonation::save(['mode' => 'fixed', 'fixed_ngn' => '7500', 'headline' => '', 'note' => ''], $by);
ck('settings: fixed mode charges the fixed price whatever was typed', AcademyDonation::check('500')['ok'] && AcademyDonation::check('500')['ngn'] === 7500 && AcademyDonation::check('')['usd'] === null);
ck('settings: fixed mode shows the naira price, no amount field, and the price on the button',
   AcademyDonation::headline() === '₦7,500 / yr' && AcademyDonation::field('x') === '' && str_contains(AcademyDonation::buttonLabel(), '₦7,500'));

AcademyDonation::save(['mode' => 'donation', 'min_usd' => '20', 'usd_ngn' => (string) (defined('AV_USD_NGN') ? AV_USD_NGN : 1500)], $by);
ck('settings: back to a donation from $20', AcademyDonation::isDonation() && AcademyDonation::minUsd() === 20 && AcademyDonation::headline() === 'From $20 / yr');
$adm = (string) file_get_contents($root . '/admin/api.php');
ck('settings: only a Super Admin saves them; the office team can read them',
   str_contains($adm, "'academy_membership_save',") && str_contains($adm, "'dues_owing', 'academy_membership',") && str_contains($adm, "array_flip(['mode', 'min_usd', 'usd_ngn', 'fixed_ngn', 'headline', 'note'])"));
