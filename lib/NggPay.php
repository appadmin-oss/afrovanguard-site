<?php
/**
 * lib/NggPay.php — a member's NGG volunteer pay, read from NGG for their wallet.
 *
 * NGG decides and pays it (domain/volunteer-pay.php there): a day earned for
 * each programme day the volunteer was present and active. This site only
 * shows it, on the member's Wallet, beside — never inside — what they owe
 * Afrovanguard: it is money NGG owes them, a different organisation's
 * account, and netting the two would make both figures wrong.
 *
 * Asked of NGG one member at a time, signed with the secret the two sites
 * already share (NGG_WEBHOOK_SECRET here, afrovanguard.ngv_webhook_secret
 * there), under its own purpose so the signature fits no other door:
 *   POST {NGG_URL}/api/hooks/afrovanguard-pay.php   {"op":"wallet","email":…,"volunteerId"?:…}
 *                                                   {"op":"bank_set","email":…,"bankName","accountNumber","accountName"}
 *   X-NGG-Timestamp: unix seconds
 *   X-NGG-Signature: sha256=hex(HMAC_SHA256(secret, "volunteer_pay." + ts + "." + body))
 * NGG finds the volunteer by the member's email, and believes a volunteer id
 * from here only when its email agrees.
 */
declare(strict_types=1);

final class NggPay
{
    private const TIMEOUT = 6;
    /** For tests: replaces the HTTP call with fn(string $url, string $body, array $headers): array{code:int, body:string}. */
    public static $transport = null;

    public static function configured(): bool { return NggVolunteers::configured(); }

    /**
     * ['ok' => true, 'wallet' => array|null] — null when they are not an NGG
     * volunteer — or ['ok' => false, 'error' => sentence] when NGG could not be asked.
     */
    public static function forUser(array $u): array
    {
        if (!self::configured()) return ['ok' => false, 'error' => 'not-configured'];
        $email = strtolower(trim((string) ($u['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => true, 'wallet' => null];
        $body = ['op' => 'wallet', 'email' => $email];
        /* The volunteer this account is linked to by the volunteer sync, when there is one. */
        try {
            NggVolunteers::ensure();
            $st = Database::pdo()->prepare('SELECT volunteer_id FROM ngg_volunteers WHERE user_id = ? ORDER BY status = \'active\' DESC LIMIT 1');
            $st->execute([(int) ($u['id'] ?? 0)]);
            $vid = (string) ($st->fetchColumn() ?: '');
            if ($vid !== '') $body['volunteerId'] = $vid;
        } catch (Throwable $e) { /* the email alone is enough */ }

        $r = self::call($body);
        if (empty($r['ok'])) return $r;
        $j = $r['json'];
        $w = $j['wallet'] ?? ($j['data']['wallet'] ?? null);
        return ['ok' => true, 'wallet' => is_array($w) ? self::clean($w) : null];
    }

    /**
     * The member sets where NGG pays them: NGG finds the volunteer by their
     * email, exactly as for the wallet, checks the details and emails the
     * volunteer about the change. Answers the bank as NGG now has it, or the
     * refusal in words.
     */
    public static function bankSet(array $u, array $in): array
    {
        if (!self::configured()) return ['ok' => false, 'error' => 'NGG is not connected to this site.'];
        $email = strtolower(trim((string) ($u['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Your account has no email NGG can match.'];
        $body = ['op' => 'bank_set', 'email' => $email,
                 'bankName' => mb_substr(trim((string) ($in['bankName'] ?? '')), 0, 80),
                 'accountNumber' => mb_substr(preg_replace('/[^\d]/', '', (string) ($in['accountNumber'] ?? '')), 0, 20),
                 'accountName' => mb_substr(trim((string) ($in['accountName'] ?? '')), 0, 120)];
        $r = self::call($body, true);
        if (empty($r['ok'])) return ['ok' => false, 'error' => $r['message'] ?? 'NGG did not answer just now. Try again in a minute.'];
        $b = $r['json']['bank'] ?? ($r['json']['data']['bank'] ?? null);
        return ['ok' => true, 'bank' => is_array($b) ? self::bank($b) : null];
    }

    /** One signed request to NGG's pay door. ['ok' => true, 'json' => …] or a refusal. */
    private static function call(array $body, bool $wantMessage = false): array
    {
        $raw = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-NGG-Timestamp: ' . $ts,
                    'X-NGG-Signature: sha256=' . hash_hmac('sha256', 'volunteer_pay.' . $ts . '.' . $raw, av_ngg_secret())];
        $url = NggVolunteers::baseUrl() . '/api/hooks/afrovanguard-pay.php';
        if (is_callable(self::$transport)) {
            $r = (self::$transport)($url, $raw, $headers);
        } else {
            if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'unreachable'];
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false]);
            $out = curl_exec($ch);
            $r = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($out) ? $out : ''];
            curl_close($ch);
        }
        $code = (int) ($r['code'] ?? 0);
        $j = json_decode((string) ($r['body'] ?? ''), true);
        if ($code === 422 && $wantMessage && is_array($j)) {
            /* NGG refused the details: its reason is a sentence for the member, kept short and plain. */
            return ['ok' => false, 'error' => 'refused', 'message' => mb_substr(strip_tags((string) ($j['detail'] ?? '')), 0, 200) ?: 'NGG could not accept those details.'];
        }
        if ($code !== 200 || !is_array($j)) {
            return ['ok' => false, 'error' => $code === 401 ? 'bad-signature' : ($code === 404 ? 'no-door' : 'unreachable')];
        }
        return ['ok' => true, 'json' => $j];
    }

    /** Where NGG pays them, as this page may show it: never the whole account number. */
    private static function bank(array $b): array
    {
        return ['set' => !empty($b['set']), 'bankName' => mb_substr((string) ($b['bankName'] ?? ''), 0, 80),
                'last4' => preg_match('/^\d{4}$/', (string) ($b['last4'] ?? '')) ? (string) $b['last4'] : '',
                'accountName' => mb_substr((string) ($b['accountName'] ?? ''), 0, 120)];
    }

    /** Only what the page draws, each value bounded: NGG's answer is data, never markup. */
    public static function clean(array $w): array
    {
        $day = static fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) ? (string) $d : '';
        $days = [];
        foreach (array_slice((array) ($w['days'] ?? []), 0, 60) as $d) {
            if (!is_array($d) || $day($d['day'] ?? '') === '') continue;
            $days[] = ['day' => $day($d['day']), 'earned' => (string) ($d['status'] ?? '') === 'earned', 'amount' => max(0, (int) ($d['amount'] ?? 0)),
                       'paid' => !empty($d['paid']), 'why' => mb_substr(is_array($d['override'] ?? null) && (string) ($d['override']['reason'] ?? '') !== '' ? (string) $d['override']['reason'] : (string) ($d['reasonText'] ?? ''), 0, 200)];
        }
        $payouts = [];
        foreach (array_slice((array) ($w['payouts'] ?? []), 0, 24) as $p) {
            if (!is_array($p) || !empty($p['void'])) continue;
            $payouts[] = ['period' => mb_substr((string) ($p['period'] ?? ''), 0, 60), 'amount' => max(0, (int) ($p['amount'] ?? 0)), 'days' => max(0, (int) ($p['days'] ?? 0)),
                          'gross' => max(0, (int) ($p['gross'] ?? ($p['amount'] ?? 0))), 'fines' => max(0, (int) ($p['fineNgg'] ?? 0)) + max(0, (int) ($p['fineAv'] ?? 0)),
                          'how' => mb_substr((string) ($p['methodText'] ?? ''), 0, 40), 'at' => $day(substr((string) ($p['paidAt'] ?? ''), 0, 10))];
        }
        $freq = ['weekly' => 'weekly', 'fortnightly' => 'every two weeks', 'monthly' => 'monthly'][(string) ($w['frequency'] ?? '')] ?? '';
        return ['owed' => max(0, (int) ($w['owed'] ?? 0)), 'earned' => max(0, (int) ($w['earned'] ?? 0)), 'paid' => max(0, (int) ($w['paid'] ?? 0)),
                'daysEarned' => max(0, (int) ($w['daysEarned'] ?? 0)), 'rate' => max(0, (int) ($w['rate'] ?? 0)), 'frequency' => $freq,
                'on' => !empty($w['on']), 'active' => !empty($w['active']), 'centre' => mb_substr((string) ($w['centre'] ?? ''), 0, 80),
                'days' => $days, 'payouts' => $payouts, 'finesTaken' => max(0, (int) ($w['finesTaken'] ?? 0)),
                'finesDeducted' => !empty($w['finesDeducted']), 'bank' => self::bank(is_array($w['bank'] ?? null) ? $w['bank'] : [])];
    }
}
