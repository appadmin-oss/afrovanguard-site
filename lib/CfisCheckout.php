<?php
/**
 * lib/CfisCheckout.php — let CACENTRE take a payment this site cannot.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Every pay button in this site used to be gated on
 * `Payments::configured('paystack')` — "do WE hold keys" — and when that was
 * false they said things like "Online payment is not available yet — please
 * contact us to pay your dues". That is a dead end dressed as a message:
 * somebody who wanted to pay is told to telephone, and either they do not, or
 * they pay in cash and the record of it lives in whatever note the office
 * happened to write.
 *
 * They ask {@see Payments::canCollect()} now — dues, academy enrolment and
 * membership, the locked-lesson buttons, and a NextGen Vanguard participant
 * paying their own fees. ONE still asks the narrow question and must keep
 * asking it: recurring giving needs a Paystack Plan on our own account, and
 * a standing gift nobody here can cancel is not a thing to sell a donor.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * WHAT IT CANNOT DO THAT OUR OWN KEYS CAN
 * ══════════════════════════════════════════════════════════════════════════
 *
 * It does not push. Our own Paystack sends a webhook and retries it until it
 * is acknowledged, so a payer who closes the tab is still recorded. This is a
 * pull API: the payer's return is the only moment anything would be noticed.
 * So `LmsRepository::sweepHostedPayments()` runs on the cron tick and asks
 * about the ones still open — without it, pay-and-close-the-tab means money
 * taken and an account still asking for it.
 *
 * A merchant account is not an afternoon's work — it is compliance
 * paperwork, a business verification and a key — so "get one" is not an
 * answer for a new centre or a one-off appeal. CACENTRE already has one, and
 * already knows what kinds of money this organisation takes. This hands the
 * payer to CACENTRE's checkout and gets the answer back.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * WHAT IT IS NOT
 * ══════════════════════════════════════════════════════════════════════════
 *
 * NOT A REPLACEMENT FOR OUR OWN PAYSTACK. Where this site has keys it uses
 * them: the money reaches our own bank the same day and nobody is holding it
 * for us. This is the fallback, and {@see Payments::canCollect()} prefers the
 * direct route every time.
 *
 * NOT A RECEIPT. `paid` from CACENTRE means the card went through. The money
 * is in CACENTRE's account and reaches the books when the payout lands.
 * Anything here that treats `paid` as "the money is ours, now" will overstate
 * this site by the float and by the fee.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * THE SAME KEY AS THE INCOME REPORTING
 * ══════════════════════════════════════════════════════════════════════════
 *
 * One signed envelope, one credential. `CFIS_SECRET` is what this site
 * already uses to tell finance what it took; opening a checkout needs no
 * second secret and no second thing to rotate.
 */
declare(strict_types=1);

final class CfisCheckout
{
    /** Seconds. A payer is waiting on this, so it fails fast rather than hanging. */
    private const TIMEOUT = 15;

    /** Is CACENTRE able to collect for us? */
    public static function configured(): bool
    {
        return self::base() !== '' && self::source() !== '' && strlen(self::secret()) >= 32;
    }

    public static function base(): string
    {
        return rtrim((string) (defined('CFIS_URL') ? CFIS_URL : (getenv('CFIS_URL') ?: '')), '/');
    }

    public static function source(): string
    {
        return trim((string) (defined('CFIS_SOURCE') ? CFIS_SOURCE : (getenv('CFIS_SOURCE') ?: '')));
    }

    private static function secret(): string
    {
        return trim((string) (defined('CFIS_SECRET') ? CFIS_SECRET : (getenv('CFIS_SECRET') ?: '')));
    }

    /**
     * Open a checkout and get the URL to send the payer to.
     *
     * `$reference` is OURS and must be stable for this payment: CACENTRE
     * keys idempotency on it, so a retry after a lost response returns the
     * same charge and the payer is asked once. Passing a fresh random
     * reference on a retry is how somebody gets charged twice.
     *
     * @param  array<string,mixed>  $meta
     * @return array{ok: bool, url?: string, reference?: string, error?: string}
     */
    public static function open(
        string $reference,
        string $stream,
        int $amountKobo,
        string $email,
        string $description = '',
        string $name = '',
        string $returnUrl = '',
        array $meta = []
    ): array {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'CACENTRE checkout is not configured for this site.'];
        }

        $res = self::post('/api/checkout/' . rawurlencode(self::source()), [
            'reference' => $reference,
            'stream' => $stream,
            'amount_minor' => $amountKobo,
            'email' => $email,
            'name' => $name,
            'description' => $description,
            'return_url' => $returnUrl,
            'metadata' => $meta,
        ]);

        if (empty($res['ok'])) {
            /* CACENTRE's own words. A refusal flattened to "payment failed"
               sends somebody to read a log to find out that a stream code was
               wrong, which is a thing the message already said. */
            return ['ok' => false, 'error' => (string) ($res['error'] ?? 'CACENTRE refused the checkout.')];
        }

        return [
            'ok' => true,
            'url' => (string) ($res['pay_url'] ?? ''),
            'reference' => (string) ($res['charge']['reference'] ?? ''),
        ];
    }

    /**
     * What happened to a payment.
     *
     * Asked rather than assumed: a payer who closes the tab never comes back
     * through our return URL, so polling this is the only answer that is
     * always available.
     *
     * @return array{ok: bool, status?: string, paid?: bool, amount_minor?: int, error?: string}
     */
    public static function status(string $reference): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'CACENTRE checkout is not configured for this site.'];
        }

        $res = self::post(
            '/api/checkout/' . rawurlencode(self::source()) . '/' . rawurlencode($reference) . '/status',
            []
        );

        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not reach CACENTRE.')];
        }

        $c = is_array($res['charge'] ?? null) ? $res['charge'] : [];

        return [
            'ok' => true,
            'status' => (string) ($c['status'] ?? ''),
            /* `paid` is the card going through, NOT money in our bank. The
               caller may unlock what was bought; it may not book income. */
            'paid' => ($c['status'] ?? '') === 'paid',
            'amount_minor' => (int) ($c['amount_minor'] ?? 0),
            'currency' => strtoupper((string) ($c['currency'] ?? '')),
            /* OUR OWN metadata, handed back. It is what lets a return leg
               ask "paid by THIS person, for THIS thing" rather than only
               "paid" — the check that stops one participant's payment
               being credited to whoever saw its reference first. The
               direct route has always had this, through Paystack's own
               metadata; without it here the hosted route would be the
               weaker of the two and nobody would notice. */
            'metadata' => is_array($c['metadata'] ?? null) ? $c['metadata'] : [],
        ];
    }

    /**
     * The signed envelope CFIS takes — the same `v1.<payload>.<mac>` the
     * income reporting uses, with the same short life.
     */
    public static function mint(array $claims): string
    {
        $now = time();
        $payload = json_encode($claims + [
            'nonce' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + 300,
        ], JSON_UNESCAPED_SLASHES);

        $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $body = $b64((string) $payload);

        return 'v1.' . $body . '.' . $b64(hash_hmac('sha256', $body, self::secret(), true));
    }

    /**
     * @param  array<string,mixed>  $claims
     * @return array<string,mixed>
     */
    private static function post(string $path, array $claims): array
    {
        $token = self::mint($claims);

        $ch = curl_init(self::base() . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-CFIS-Token: ' . $token,
            ],
            /* The claims travel INSIDE the signed token, and the body carries
               it. Sending the amount as a plain field beside the token would
               be an unsigned amount sitting next to a signed one, and the
               wrong one is always the one somebody reads. */
            CURLOPT_POSTFIELDS => json_encode(['token' => $token], JSON_UNESCAPED_SLASHES),
        ]);

        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err !== '' || $body === false) {
            error_log('[cfis-checkout] ' . ($err ?: 'no response'));

            return ['ok' => false, 'error' => 'Could not reach CACENTRE. Please try again in a moment.'];
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            error_log('[cfis-checkout] HTTP ' . $code . ' in a shape this does not understand');

            return ['ok' => false, 'error' => 'CACENTRE answered in a way this site does not understand.'];
        }

        return $json;
    }
}
