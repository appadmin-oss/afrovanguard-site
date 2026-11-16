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
