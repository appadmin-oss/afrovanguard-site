<?php
/**
 * lib/CfisPairing.php — collect this site's CFIS key from CFIS.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * WHAT THIS REPLACES
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The Studio used to say: "CACENTRE's finance team mints it with
 * `php artisan cfis:connect avg` and hands it over privately." Behind that
 * sentence, a 64-character signing key was printed to somebody's terminal,
 * lived in scrollback, travelled by whatever means the two people found
 * convenient, and was pasted into a form here by hand. The instruction to
 * keep it private was the whole of the control.
 *
 * It also meant a lost hand-over and a compromised key had the same remedy —
 * run `cfis:connect` again — and that remedy mints a NEW key, which stops
 * the old one working. The commoner problem was fixed by breaking the site.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * WHY A SECOND CREDENTIAL, AND NOT JUST "ASK CFIS FOR THE KEY"
 * ══════════════════════════════════════════════════════════════════════════
 *
 * This site cannot simply fetch its own key, because the only thing that
 * proves it is Afrovanguard is that same key. Something has to break the
 * loop, and the thing that breaks it should be worth as little as possible.
 *
 * So finance issues a PAIRING CODE: sixteen characters, good for fifteen
 * minutes, usable once, stored by CFIS only as a hash. A person carries the
 * CODE. The key travels machine to machine over TLS and is never displayed
 * on either side. A code overheard or screenshotted is worth nothing after
 * one use or one quarter of an hour, and — unlike a leaked key — CFIS's
 * audit trail can show that it was used, by whom and from where.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * WHAT THIS SITE IS NOT ALLOWED TO DO
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Collect, and nothing else. It cannot mint, rotate or re-issue: those stay
 * with CACENTRE's finance team, where the money is. A compromised
 * Afrovanguard must not be able to cycle CACENTRE's keys.
 */
declare(strict_types=1);

final class CfisPairing
{
    /** Seconds. An operator is watching a spinner, so it fails fast. */
    private const TIMEOUT = 15;

    /**
     * Redeem a pairing code and store the key it returns.
     *
     * @return array{ok:bool, error?:string, fingerprint?:string, name?:string, source?:string}
     */
    public static function pair(string $code, string $actor = ''): array
    {
        $code = trim($code);
        if ($code === '') return ['ok' => false, 'error' => 'Enter the pairing code finance gave you.'];

        /* base() returns '' for anything that is not https (or loopback), so
           this also refuses to send a pairing code over plain http — where
           anybody on the path would read the key out of the ANSWER. */
        $base = CfisCheckout::base();
        if ($base === '') {
            return ['ok' => false, 'error' => 'The finance address is not set, or is not an https:// address. Check it above and try again.'];
        }
        $source = CfisCheckout::source();
        if ($source === '') return ['ok' => false, 'error' => 'No property code is set for this site.'];

        $res = self::post($base . '/api/pair/' . rawurlencode($source), ['code' => $code]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => (string) ($res['error'] ?? 'CACENTRE refused the pairing code.')];
        }

        $secret = trim((string) ($res['secret'] ?? ''));
        if (strlen($secret) < 32) {
            return ['ok' => false, 'error' => 'CACENTRE answered without a usable key. Ask finance to check the property is connected.'];
        }

        /*
         * Check the key against the fingerprint CFIS sent with it.
         *
         * Not theatre: it is the one way to tell a key that arrived intact
         * from one truncated by a proxy or mangled in transit, and the
         * symptom otherwise is every settlement refused for a bad signature
         * days later, with nothing pointing back here. It is also the value
         * the operator compares with what finance has on screen, so both
         * sides can confirm they hold the same key without either printing
         * it.
         */
        $expect = (string) ($res['fingerprint'] ?? '');
        $actual = self::fingerprint($secret);
        if ($expect !== '' && !hash_equals($expect, $actual)) {
            return ['ok' => false, 'error' => 'The key did not arrive intact (its fingerprint does not match). Nothing was saved — ask finance for a new pairing code.'];
        }

        $save = AvSettings::save(['CFIS_SECRET' => $secret], $actor);
        if (empty($save['ok'])) {
            return ['ok' => false, 'error' => (string) (reset($save['errors']) ?: 'The key could not be stored.')];
        }

        return [
            'ok' => true,
            'fingerprint' => $actual,
            'name' => (string) ($res['name'] ?? ''),
            'source' => (string) ($res['source'] ?? $source),
        ];
    }

    /**
     * The same 16 characters CFIS shows for a key.
     *
     * Mirrors IncomeSource::fingerprintFor() on the CACENTRE side. Two
     * implementations of one definition is a thing that drifts, so it is
     * pinned by a test on each side rather than left to goodwill.
     */
    public static function fingerprint(string $secret): string
    {
        return substr(hash('sha256', 'cfis-source-key:' . $secret), 0, 16);
    }

    /** What this site currently holds, for the Studio to show. */
    public static function current(): array
    {
        $secret = (string) (AvSettings::get('CFIS_SECRET') ?? '');
        if ($secret === '') $secret = (string) Config::get('CFIS_SECRET', '');
        $secret = trim($secret);

        return [
            'paired' => strlen($secret) >= 32,
            'fingerprint' => strlen($secret) >= 32 ? self::fingerprint($secret) : '',
            'source' => CfisCheckout::source(),
        ];
    }

    private static function post(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);

        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err !== '' || $body === false) {
            /* The code, never the body: a failed pairing response may still
               carry a key, and this is the one log line that would hold it. */
            error_log('[cfis-pair] ' . ($err ?: 'no response'));

            return ['ok' => false, 'error' => 'Could not reach CACENTRE finance. Check the address above and try again.'];
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            error_log('[cfis-pair] HTTP ' . $code . ' in a shape this does not understand');

            return ['ok' => false, 'error' => 'CACENTRE answered in a way this site does not understand (HTTP ' . $code . ').'];
        }

        return $json;
    }
}
