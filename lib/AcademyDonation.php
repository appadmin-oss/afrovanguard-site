<?php
/**
 * lib/AcademyDonation.php — Academy membership is a donation (16 Nov 2026).
 *
 * It was a fixed ₦5,000 a year (AV_MEMBERSHIP_NGN). It is now whatever the learner chooses to
 * give, from a minimum of $20 a year (AV_MEMBERSHIP_MIN_USD), and a paid donation unlocks every
 * members' programme for twelve months, as the fee did.
 *
 * Priced in dollars, charged in naira. Every checkout this site can open (its own Paystack, or
 * CACENTRE's hosted checkout) charges naira, so the donor gives in dollars and pays the naira
 * figure at the office's rate, AV_USD_NGN (naira per dollar). The office sets that rate; the
 * page always shows both figures so nobody is surprised at the checkout. The naira minimum is
 * rounded UP to the next ₦100, so it never falls below $20 at the stated rate.
 *
 * Not Afrovanguard member dues (lib/Membership.php settings): those are an obligation with
 * their own prices and are unchanged.
 */
declare(strict_types=1);

final class AcademyDonation
{
    /** The largest single donation the checkout will open, in dollars. A typo guard, not a cap on generosity. */
    public const MAX_USD = 10000;

    public static function minUsd(): int
    {
        return max(1, defined('AV_MEMBERSHIP_MIN_USD') ? (int) AV_MEMBERSHIP_MIN_USD : 20);
    }

    /** Naira per dollar, as the office set it. */
    public static function rate(): int
    {
        return max(1, defined('AV_USD_NGN') ? (int) AV_USD_NGN : 1500);
    }

    /** The naira charged for a gift of $usd, rounded up to the next ₦100. */
    public static function ngnFor(float $usd): int
    {
        return (int) (ceil(($usd * self::rate()) / 100) * 100);
    }

    public static function minNgn(): int
    {
        return self::ngnFor(self::minUsd());
    }

    /**
     * Check a donor's chosen amount (in dollars; blank means the minimum).
     *
     * @return array{ok:bool, usd?:float, ngn?:int, error?:string}
     */
    public static function check($usd): array
    {
        $raw = trim((string) ($usd ?? ''));
        if ($raw === '') $raw = (string) self::minUsd();
        $raw = str_replace([',', '$', ' '], '', $raw);
        if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $raw)) {
            return ['ok' => false, 'error' => 'Enter your donation in dollars, for example ' . self::minUsd() . '.'];
        }
        $v = (float) $raw;
        if ($v < self::minUsd()) {
            return ['ok' => false, 'error' => 'Membership is a donation of $' . self::minUsd() . ' or more a year.'];
        }
        if ($v > self::MAX_USD) {
            return ['ok' => false, 'error' => 'For a gift over $' . number_format(self::MAX_USD) . ', please contact us so we can thank you properly.'];
        }

        return ['ok' => true, 'usd' => $v, 'ngn' => self::ngnFor($v)];
    }

    /** "From $20 / yr" — the price headline. */
    public static function headline(string $per = 'yr'): string
    {
        return 'From $' . self::minUsd() . ' / ' . $per;
    }

    /** "about ₦30,000" — the naira the minimum costs at today's rate. */
    public static function minNgnText(): string
    {
        return 'about ₦' . number_format(self::minNgn());
    }

    public static function usdText(float $usd): string
    {
        return '$' . (floor($usd) == $usd ? number_format($usd) : number_format($usd, 2));
    }

    /**
     * The amount field for a "Become a member" card. The pay button in the same
     * [data-avac-paycard] sends what is typed here (academy/avac.js); without script the
     * minimum is what is charged.
     */
    public static function field(string $id): string
    {
        $h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $min = self::minUsd();

        return '<div class="avac-give">'
            . '<label class="avac-give-l" for="' . $h($id) . '">Your yearly donation, in US dollars</label>'
            . '<div class="avac-give-row"><span class="avac-give-cur" aria-hidden="true">$</span>'
            . '<input class="avac-give-in" id="' . $h($id) . '" type="number" inputmode="decimal" min="' . $min . '" max="' . self::MAX_USD . '" step="1" value="' . $min . '"'
            . ' data-avac-amount data-rate="' . self::rate() . '" aria-describedby="' . $h($id) . '-ngn"></div>'
            . '<p class="avac-give-ngn" id="' . $h($id) . '-ngn" data-avac-ngn aria-live="polite">Minimum $' . $min . '. You pay ₦'
            . number_format(self::minNgn()) . ' at ₦' . number_format(self::rate()) . ' to the dollar.</p>'
            . '</div>';
    }
}
