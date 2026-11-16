<?php
/**
 * lib/AcademyDonation.php — what Academy membership costs, as the office sets it (16 Nov 2026).
 *
 * Two ways, chosen in the Studio (Members → Dues → Academy membership) by a Super Admin:
 *
 *   donation  the learner gives what they choose, from a minimum in dollars (default $20 a
 *             year), charged in naira at the office's rate (naira per dollar), rounded UP to
 *             the next ₦100 so it never falls below the minimum at that rate;
 *   fixed     a set naira price a year, as it was before (₦5,000).
 *
 * Either way a paid membership unlocks every members' programme for twelve months. The wording
 * on the price card (headline and note) is the office's too, with placeholders filled in:
 *   {min_usd}  the minimum, in dollars        {min_ngn}  the minimum, in naira
 *   {rate}     naira per dollar               {price_ngn} the fixed price, in naira
 *
 * Saved in the site meta (av_academy_membership). Until the office saves anything the defaults
 * come from the deployment: AV_MEMBERSHIP_MIN_USD, AV_USD_NGN, AV_MEMBERSHIP_NGN.
 *
 * Not Afrovanguard member dues (lib/Membership.php): those are an obligation with their own
 * prices and rules.
 */
declare(strict_types=1);

final class AcademyDonation
{
    public const SETTINGS_KEY = 'av_academy_membership';
    public const MODES = ['donation', 'fixed'];
    /** The largest single donation the checkout will open, in dollars. A typo guard, not a cap on generosity. */
    public const MAX_USD = 10000;
    public const MAX_RATE = 100000;
    public const MAX_TEXT = 300;

    private static ?array $settings = null;

    /* ══ The settings ═══════════════════════════════════════════════════════ */

    public static function defaults(): array
    {
        return [
            'mode'          => 'donation',
            'min_usd'       => max(1, defined('AV_MEMBERSHIP_MIN_USD') ? (int) AV_MEMBERSHIP_MIN_USD : 20),
            'usd_ngn'       => max(1, defined('AV_USD_NGN') ? (int) AV_USD_NGN : 1500),
            'fixed_ngn'     => max(100, defined('AV_MEMBERSHIP_NGN') ? (int) AV_MEMBERSHIP_NGN : 5000),
            'headline'      => '',   // blank = the standard wording for the mode
            'note'          => '',
            'updated_at'    => '', 'updated_by' => '',
        ];
    }

    public static function settings(): array
    {
        if (self::$settings !== null) return self::$settings;
        $s = self::defaults();
        try {
            $raw = class_exists('Database') ? Database::metaGet(self::SETTINGS_KEY) : null;
            $saved = $raw ? json_decode($raw, true) : null;
            if (is_array($saved)) $s = array_merge($s, array_intersect_key($saved, $s));
        } catch (Throwable $e) { error_log('[academy membership] settings: ' . $e->getMessage()); }
        if (!in_array($s['mode'], self::MODES, true)) $s['mode'] = 'donation';

        return self::$settings = $s;
    }

    public static function forget(): void { self::$settings = null; }

    /**
     * Save what the office typed. Every field is checked; nothing is saved if one is wrong.
     *
     * @return array{ok:bool, error?:string, fields?:array, settings?:array, unchanged?:bool}
     */
    public static function save(array $in, string $by): array
    {
        $cur = self::settings();
        $s = $cur; $e = [];
        if (array_key_exists('mode', $in)) {
            $v = (string) $in['mode'];
            if (!in_array($v, self::MODES, true)) $e['mode'] = 'Membership is a donation or a fixed price.';
            else $s['mode'] = $v;
        }
        foreach (['min_usd' => [1, self::MAX_USD, 'The minimum donation is a whole number of dollars, from 1 to ' . self::MAX_USD . '.'],
                  'usd_ngn' => [1, self::MAX_RATE, 'The rate is the naira to one dollar, a whole number like 1500.'],
                  'fixed_ngn' => [100, 10000000, 'The fixed price is a whole number of naira, at least 100.']] as $k => [$lo, $hi, $msg]) {
            if (!array_key_exists($k, $in)) continue;
            $v = str_replace([',', ' ', '₦', '$'], '', trim((string) $in[$k]));
            if (!ctype_digit($v) || (int) $v < $lo || (int) $v > $hi) $e[$k] = $msg;
            else $s[$k] = (int) $v;
        }
        foreach (['headline', 'note'] as $k) {
            if (!array_key_exists($k, $in)) continue;
            $v = trim(preg_replace('/\s+/u', ' ', (string) $in[$k]) ?? '');
            if (mb_strlen($v) > self::MAX_TEXT) $e[$k] = 'Keep the ' . $k . ' under ' . self::MAX_TEXT . ' characters.';
            else $s[$k] = $v;
        }
        if ($e) return ['ok' => false, 'error' => implode(' ', $e), 'fields' => $e];

        $changed = [];
        foreach (['mode', 'min_usd', 'usd_ngn', 'fixed_ngn', 'headline', 'note'] as $k) {
            if ($s[$k] !== $cur[$k]) $changed[] = $k . ': ' . var_export($cur[$k], true) . ' → ' . var_export($s[$k], true);
        }
        if (!$changed) return ['ok' => true, 'unchanged' => true, 'settings' => $cur];
        $s['updated_at'] = gmdate('Y-m-d H:i:s'); $s['updated_by'] = mb_substr($by, 0, 191);
        Database::metaSet(self::SETTINGS_KEY, (string) json_encode($s));
        self::forget();
        try { (new LmsRepository())->audit('academy.membership.settings', 'academy', implode('; ', $changed), $by); } catch (Throwable $e) {}

        return ['ok' => true, 'settings' => self::settings()];
    }

    /* ══ What it costs ══════════════════════════════════════════════════════ */

    public static function isDonation(): bool { return self::settings()['mode'] === 'donation'; }
    public static function minUsd(): int { return (int) self::settings()['min_usd']; }
    /** Naira per dollar, as the office set it. */
    public static function rate(): int { return (int) self::settings()['usd_ngn']; }
    public static function fixedNgn(): int { return (int) self::settings()['fixed_ngn']; }

    /** The naira charged for a gift of $usd, rounded up to the next ₦100. */
    public static function ngnFor(float $usd): int
    {
        return (int) (ceil(($usd * self::rate()) / 100) * 100);
    }

    public static function minNgn(): int { return self::ngnFor(self::minUsd()); }

    /** The least a membership costs in naira today, whichever mode. */
    public static function fromNgn(): int { return self::isDonation() ? self::minNgn() : self::fixedNgn(); }

    /**
     * What the checkout charges for what the learner chose. In fixed mode the choice is ignored.
     *
     * @return array{ok:bool, usd?:?float, ngn?:int, error?:string}
     */
    public static function check($usd): array
    {
        if (!self::isDonation()) return ['ok' => true, 'usd' => null, 'ngn' => self::fixedNgn()];
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

    /* ══ What the page says ═════════════════════════════════════════════════ */

    /** Fill the office's placeholders. */
    public static function fill(string $text): string
    {
        return strtr($text, [
            '{min_usd}' => (string) self::minUsd(), '{min_ngn}' => number_format(self::minNgn()),
            '{rate}' => number_format(self::rate()), '{price_ngn}' => number_format(self::fixedNgn()),
        ]);
    }

    /** "From $20 / yr", or "₦5,000 / yr", or the office's own headline. */
    public static function headline(string $per = 'yr'): string
    {
        $custom = trim((string) self::settings()['headline']);
        if ($custom !== '') return self::fill($custom);

        return self::isDonation()
            ? 'From $' . self::minUsd() . ' / ' . $per
            : '₦' . number_format(self::fixedNgn()) . ' / ' . $per;
    }

    /** The sentence under the price, or the office's own. */
    public static function note(): string
    {
        $custom = trim((string) self::settings()['note']);
        if ($custom !== '') return self::fill($custom);

        return self::isDonation()
            ? 'Membership is a donation: give $' . self::minUsd() . ' or more a year (' . self::minNgnText() . ') to unlock this and every members’ programme.'
            : 'Included with Academy membership.';
    }

    /** The pay button's words. */
    public static function buttonLabel(): string
    {
        return self::isDonation() ? 'Donate and become a member →' : 'Become a member — ₦' . number_format(self::fixedNgn()) . '/yr →';
    }

    /** One sentence for answers and FAQs. */
    public static function summary(): string
    {
        return self::isDonation()
            ? 'Academy membership is a donation of $' . self::minUsd() . ' or more a year (' . self::minNgnText() . ', paid in naira at ₦' . number_format(self::rate()) . ' to the dollar)'
            : 'Academy membership is ₦' . number_format(self::fixedNgn()) . ' a year';
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
     * The amount field for a "Become a member" card, in donation mode (nothing in fixed mode).
     * The pay button in the same [data-avac-paycard] sends what is typed here (academy/avac.js);
     * without script the minimum is what is charged.
     */
    public static function field(string $id): string
    {
        if (!self::isDonation()) return '';
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
