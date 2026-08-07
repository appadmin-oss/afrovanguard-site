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

    /** Verify a transaction. Returns ['paid'=>bool,'amount'=>kobo,'reference'=>...]. */
    public static function paystackVerify(string $reference): array
    {
        $res = self::curl('https://api.paystack.co/transaction/verify/' . rawurlencode($reference), null, 'Bearer ' . PAYSTACK_SECRET_KEY);
        $d = $res['data'] ?? [];
        return [
            'paid' => ($res['status'] ?? false) && (($d['status'] ?? '') === 'success'),
            'amount' => (int) ($d['amount'] ?? 0),
            'reference' => (string) ($d['reference'] ?? $reference),
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
