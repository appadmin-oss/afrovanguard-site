<?php
/**
 * lib/Payments.php — Paystack (primary) + Flutterwave payment provider.
 *
 * Server-side initialise + verify so access is only granted on a verified
 * transaction. Keys come from config.php / env:
 *   PAYSTACK_PUBLIC_KEY, PAYSTACK_SECRET_KEY   (already used by donations)
 *   FLW_PUBLIC_KEY, FLW_SECRET_KEY             (optional)
 */
declare(strict_types=1);

final class Payments
{
    public static function configured(string $provider = 'paystack'): bool
    {
        if ($provider === 'paystack') return defined('PAYSTACK_SECRET_KEY') && PAYSTACK_SECRET_KEY && defined('PAYSTACK_PUBLIC_KEY') && PAYSTACK_PUBLIC_KEY;
        if ($provider === 'flutterwave') return defined('FLW_SECRET_KEY') && FLW_SECRET_KEY;
        return false;
    }

    /**
     * Can this site take a payment at all, by any route?
     *
     * `configured('paystack')` answers "do we have our own keys", which is a
     * different question and the wrong one to gate a Pay button on. Nine
     * places asked the narrow one and, when it was false, told the payer to
     * telephone — so a site with no merchant account could take nothing,
     * however willing the payer, and whatever CACENTRE could have collected
     * on its behalf.
     */
    public static function canCollect(): bool
    {
        return self::configured('paystack')
            || (class_exists('CfisCheckout') && CfisCheckout::configured());
    }

    /**
     * Which route a payment would take: 'paystack' | 'cfis' | ''.
     *
     * OUR OWN KEYS WIN, always. Money through our own account is in our bank
     * the same day and nobody is holding it for us; the hosted route is the
     * answer to having no account, not a preference.
     */
    public static function route(): string
    {
        return self::routeFor(
            self::configured('paystack'),
            class_exists('CfisCheckout') && CfisCheckout::configured()
        );
    }

    /**
     * The preference, as a function of what is available.
     *
     * Split out because the ORDER is the rule, and order is the one thing a
     * test cannot see from the outside when the deployment running it has no
     * Paystack keys to begin with — both branches answer the same. Reversing
     * these two lines is a silent change that sends every payment through
     * somebody else's merchant account while every test still passes, which
     * is precisely what happened to the first version of this.
     */
    public static function routeFor(bool $ownKeys, bool $hostedAvailable): string
    {
        if ($ownKeys) return 'paystack';
        if ($hostedAvailable) return 'cfis';
        return '';
    }

    /**
     * Start a payment by whichever route is available.
     *
     * Returns the URL to send the payer to, and the provider that is to be
     * asked about it later — which the caller MUST store, because verifying
     * a CACENTRE charge against Paystack finds nothing and reads as a
     * payment that never happened.
     *
     * @param  array<string,mixed>  $meta
     * @return array{ok: bool, url?: string, provider?: string, error?: string}
     */
    public static function startCheckout(
        string $email,
        int $amountKobo,
        string $reference,
        string $callbackUrl,
        string $stream,
        string $description = '',
        string $name = '',
        array $meta = []
    ): array {
        $route = self::route();

        if ($route === 'paystack') {
            $url = self::paystackInit($email, $amountKobo, $reference, $callbackUrl, $meta);

            return $url
                ? ['ok' => true, 'url' => $url, 'provider' => 'paystack']
                : ['ok' => false, 'error' => 'Could not start the payment. Please try again in a moment.'];
        }

        if ($route === 'cfis') {
            $res = CfisCheckout::open(
                $reference, $stream, $amountKobo, $email, $description, $name, $callbackUrl, $meta
            );

            return !empty($res['ok'])
                ? ['ok' => true, 'url' => (string) $res['url'], 'provider' => 'cfis']
                : ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not start the payment.')];
        }

        return ['ok' => false, 'error' => 'Online payment is not set up for this site yet.'];
    }

    /**
     * Verify by the route the payment was actually taken on.
     *
     * The provider comes from the stored row, never from the request: a
     * caller that guesses asks Paystack about a CACENTRE charge, finds
     * nothing, and tells somebody who paid that they did not.
     *
     * @return array{ok: bool, paid: bool, amount_minor: int, error?: string}
     */
    public static function verifyBy(string $provider, string $reference): array
    {
        if ($provider === 'cfis') {
            $r = class_exists('CfisCheckout')
                ? CfisCheckout::status($reference)
                : ['ok' => false, 'error' => 'CACENTRE checkout is not available.'];

            return [
                'ok' => !empty($r['ok']),
                'paid' => !empty($r['paid']),
                'amount_minor' => (int) ($r['amount_minor'] ?? 0),
                'error' => $r['error'] ?? null,
            ];
        }

        /* paystackVerify() already returns a flat verdict — `paid` and
           `amount`, not Paystack's envelope. Reading `data.status` off it
           finds nothing and reports every real payment as unpaid. */
        $v = self::paystackVerify($reference);

        return [
            'ok' => true,
            'paid' => !empty($v['paid']),
            'amount_minor' => (int) ($v['amount'] ?? 0),
            'error' => null,
        ];
    }

    public static function reference(string $kind): string
    {
        return strtoupper($kind[0]) . date('ymd') . '-' . bin2hex(random_bytes(6));
    }

    /* ── Paystack ───────────────────────────────────────────────── */
    /** Initialise a transaction; returns the hosted authorization_url or null. */
    public static function paystackInit(string $email, int $amountKobo, string $reference, string $callbackUrl, array $meta = []): ?string
    {
        $res = self::curl('https://api.paystack.co/transaction/initialize', [
            'email' => $email, 'amount' => $amountKobo, 'reference' => $reference,
            'currency' => 'NGN', 'callback_url' => $callbackUrl, 'metadata' => $meta,
        ], 'Bearer ' . PAYSTACK_SECRET_KEY);
        return ($res && ($res['status'] ?? false) && !empty($res['data']['authorization_url'])) ? $res['data']['authorization_url'] : null;
    }

    /**
     * Initialise a RECURRING transaction against a Paystack Plan. On success the
     * first charge is taken and Paystack creates a subscription that auto-charges
     * each interval. Returns the hosted authorization_url or null.
     */
    public static function paystackInitPlan(string $email, string $planCode, string $reference, string $callbackUrl, array $meta = []): ?string
    {
        $res = self::curl('https://api.paystack.co/transaction/initialize', [
            'email' => $email, 'plan' => $planCode, 'reference' => $reference,
            'callback_url' => $callbackUrl, 'metadata' => $meta,
        ], 'Bearer ' . PAYSTACK_SECRET_KEY);
        return ($res && ($res['status'] ?? false) && !empty($res['data']['authorization_url'])) ? $res['data']['authorization_url'] : null;
    }

    /**
     * Find or create a Paystack Plan, returning its plan_code (or null).
     *
     * A recurring gift needs a Plan on Paystack's side before a subscription
     * can exist. Plans are created once per (amount, interval, name) and then
     * reused for every donor who picks that option — creating one per click
     * would fill the dashboard with thousands of identical plans and make the
     * merchant's own reporting useless.
     *
     * Callers are expected to CACHE the returned code. This makes a live API
     * call, so it belongs on the click that starts a subscription, never on a
     * page render.
     *
     * $interval is Paystack's own vocabulary: daily, weekly, monthly,
     * quarterly, biannually, annually.
     */
    public static function paystackFindOrCreatePlan(string $name, int $amountKobo, string $interval): ?array
    {
        if (!self::configured('paystack')) return null;
        $interval = strtolower(trim($interval));
        if (!in_array($interval, ['daily', 'weekly', 'monthly', 'quarterly', 'biannually', 'annually'], true)) return null;
        if ($amountKobo < 100) return null;

        $res = self::curl('https://api.paystack.co/plan', [
            'name'     => mb_substr($name, 0, 100),
            'amount'   => $amountKobo,
            'interval' => $interval,
            'currency' => 'NGN',
        ], 'Bearer ' . PAYSTACK_SECRET_KEY);

        if ($res && ($res['status'] ?? false) && !empty($res['data']['plan_code'])) {
            return ['code' => (string) $res['data']['plan_code'], 'id' => (int) ($res['data']['id'] ?? 0)];
        }
        error_log('[payments] plan create failed: ' . substr(json_encode($res), 0, 200));
        return null;
    }

    /**
     * Cancel a subscription. Paystack needs BOTH the subscription code and the
     * current email token, and the token is only obtainable by fetching the
     * subscription first — so this does the fetch rather than making every
     * caller know that.
     */
    public static function paystackCancelSubscription(string $subscriptionCode): bool
    {
        if (!self::configured('paystack') || $subscriptionCode === '') return false;
        $sub = self::curl('https://api.paystack.co/subscription/' . rawurlencode($subscriptionCode), null, 'Bearer ' . PAYSTACK_SECRET_KEY);
        $token = (string) ($sub['data']['email_token'] ?? '');
        if ($token === '') { error_log('[payments] cancel: no email token for ' . $subscriptionCode); return false; }
        $res = self::curl('https://api.paystack.co/subscription/disable', [
            'code' => $subscriptionCode, 'token' => $token,
        ], 'Bearer ' . PAYSTACK_SECRET_KEY);
        return (bool) ($res['status'] ?? false);
    }

    /**
     * Verify a transaction. Returns ['paid'=>bool,'amount'=>kobo,'reference','currency','metadata'].
     *
     * The metadata is returned so a caller can check WHAT the payment was for
     * and WHOSE it was: a paid reference alone says only that somebody paid
     * something — a donation and an NGV fee look the same from here.
     */
    public static function paystackVerify(string $reference): array
    {
        $res = self::curl('https://api.paystack.co/transaction/verify/' . rawurlencode($reference), null, 'Bearer ' . PAYSTACK_SECRET_KEY);
        $d = $res['data'] ?? [];
        $meta = $d['metadata'] ?? [];
        if (is_string($meta)) $meta = json_decode($meta, true);
        return [
            'paid' => ($res['status'] ?? false) && (($d['status'] ?? '') === 'success'),
            'amount' => (int) ($d['amount'] ?? 0),
            'reference' => (string) ($d['reference'] ?? $reference),
            'currency' => strtoupper((string) ($d['currency'] ?? '')),
            'metadata' => is_array($meta) ? $meta : [],
        ];
    }

    /** Validate a Paystack webhook signature (raw body, x-paystack-signature). */
    public static function paystackWebhookValid(string $rawBody, string $signature): bool
    {
        if (!self::configured('paystack') || $signature === '') return false;
        return hash_equals(hash_hmac('sha512', $rawBody, PAYSTACK_SECRET_KEY), $signature);
    }

    /* ── Flutterwave (secondary) ────────────────────────────────── */
    public static function flutterwaveVerify(string $txId): array
    {
        if (!self::configured('flutterwave')) return ['paid' => false, 'amount' => 0];
        $res = self::curl('https://api.flutterwave.com/v3/transactions/' . rawurlencode($txId) . '/verify', null, 'Bearer ' . FLW_SECRET_KEY);
        $d = $res['data'] ?? [];
        return ['paid' => ($d['status'] ?? '') === 'successful', 'amount' => (int) (($d['amount'] ?? 0) * 100), 'reference' => (string) ($d['tx_ref'] ?? '')];
    }

    /* ── HTTP ───────────────────────────────────────────────────── */
    private static function curl(string $url, ?array $post, string $auth): ?array
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $auth, 'Content-Type: application/json', 'Cache-Control: no-cache'],
        ];
        if ($post !== null) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = json_encode($post); }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
        if ($raw === false || $err !== '') { error_log('[payments] curl: ' . $err); return null; }
        $j = json_decode((string) $raw, true);
        return is_array($j) ? $j : null;
    }
}
