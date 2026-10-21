<?php
/**
 * lib/CfisRequests.php — a member asks CACENTRE finance for money.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Raising a request in CFIS was already open to anybody signed into the
 * finance console — deliberately, because the person who needs a thing
 * bought is rarely the person with a finance role. But a member here has
 * no console account and never will, so the one population the feature was
 * written for could not use it. A facilitator who paid a bus fare out of
 * their own pocket had to find somebody with a console login and ask them
 * to type it.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * WHAT THIS SITE IS TRUSTED WITH, AND WHAT IT IS NOT
 * ══════════════════════════════════════════════════════════════════════════
 *
 * That a member is who they say, and what they asked for. Nothing else.
 * This site cannot approve anything, cannot set the approval route, cannot
 * choose a priority, and cannot name a ledger heading except by picking
 * from the list CFIS publishes. What arrives there enters the same
 * approval ladder as a request typed at a finance desk, and the same
 * separation of duties applies afterwards. Proving who we are is not
 * deciding anything.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * THE REFERENCE IS OURS, AND IT IS WHY NOBODY IS PAID TWICE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The local row is written BEFORE the send, with a reference this site
 * generates. CFIS keys idempotency on it: a send that times out, a member
 * who presses twice, a retry after a lost response — all resolve to one
 * requisition, and CFIS answers the repeat with the original rather than
 * refusing it, so a send nobody could confirm is always resolvable. A
 * fresh random reference on a retry is how somebody gets paid twice.
 *
 * THE CATEGORY LIST IS NOT KEPT HERE. Every property in this estate has at
 * some point kept its own copy of a list CFIS owns, and every copy drifted
 * — the streams catalogue exists because of it. The form is drawn from
 * what CFIS says it will accept, fetched when the page is drawn. If
 * finance cannot be reached, the form says so rather than offering a
 * guessed list that may no longer be true.
 *
 * The credential is the one this site already uses to report income:
 * CfisCheckout::mint() signs with it, so there is no second key to rotate.
 */
declare(strict_types=1);

final class CfisRequests
{
    /** Seconds. A member is waiting, so it fails fast rather than hanging. */
    private const TIMEOUT = 15;

    /** What a member may be asked for, in naira, before finance would rather talk. */
    private const MIN_NGN = 100;

    public static function configured(): bool
    {
        return CfisCheckout::configured();
    }

    /* ── storage ────────────────────────────────────────────────────── */

    private static function table(): void
    {
        static $made = false;
        if ($made) return;
        $made = true;

        Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS cfis_member_requests (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id         INTEGER NOT NULL,
            ref             VARCHAR(40) NOT NULL,
            title           VARCHAR(160) NOT NULL DEFAULT '',
            purpose         TEXT,
            category        VARCHAR(40) NOT NULL DEFAULT '',
            amount_minor    INTEGER NOT NULL DEFAULT 0,
            cfis_reference  VARCHAR(40) NOT NULL DEFAULT '',
            status          VARCHAR(24) NOT NULL DEFAULT 'unsent',
            last_error      TEXT,
            created_at      VARCHAR(25) NOT NULL DEFAULT '',
            updated_at      VARCHAR(25) NOT NULL DEFAULT ''
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_cfisreq_ref ON cfis_member_requests (ref);
        CREATE INDEX IF NOT EXISTS idx_cfisreq_user ON cfis_member_requests (user_id, id)");
    }

    /* ── asking ─────────────────────────────────────────────────────── */

    /**
     * @param  array<string,mixed>  $in
     * @return array{ok: bool, error?: string, reference?: string, ref?: string}
     */
    public static function submit(int $userId, array $in, string $name, string $email): array
    {
        self::table();

        if (!self::configured()) {
            return ['ok' => false, 'error' => 'Requests to finance are not switched on yet.'];
        }

        $title    = trim((string) ($in['title'] ?? ''));
        $purpose  = trim((string) ($in['purpose'] ?? ''));
        $category = trim((string) ($in['category'] ?? ''));
        $amount   = (float) ($in['amount'] ?? 0);

        if ($title === '' || $purpose === '') {
            return ['ok' => false, 'error' => 'Say what the money is for, and why it is needed.'];
        }
        if ($amount < self::MIN_NGN) {
            return ['ok' => false, 'error' => 'A request has to be for at least ₦' . number_format(self::MIN_NGN) . '.'];
        }
        if ($category === '') {
            return ['ok' => false, 'error' => 'Choose what kind of spending this is.'];
        }

        /* The bank details go with the request because a request that can
           be approved and then cannot be paid is worse than one refused
           now. CFIS keeps them against the member after the first time, so
           this is asked once and then optional. */
        $bank = [
            'account_number' => preg_replace('/\D+/', '', (string) ($in['account_number'] ?? '')) ?: '',
            'bank_name'      => trim((string) ($in['bank_name'] ?? '')),
            'bank_code'      => trim((string) ($in['bank_code'] ?? '')),
            'account_name'   => trim((string) ($in['account_name'] ?? '')) ?: $name,
        ];

        /* OURS, written down before anything is sent. */
        $ref = 'AVG-REQ-' . $userId . '-' . bin2hex(random_bytes(6));
        $now = gmdate('c');
        $minor = (int) round($amount * 100);

        $pdo = Database::pdo();
        $st = $pdo->prepare("INSERT INTO cfis_member_requests
            (user_id, ref, title, purpose, category, amount_minor, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'unsent', ?, ?)");
        $st->execute([$userId, $ref, mb_substr($title, 0, 160), $purpose, $category, $minor, $now, $now]);

        return self::send((int) $pdo->lastInsertId(), $ref, [
            'ref'      => $ref,
            'email'    => $email,
            'name'     => $name,
            'title'    => $title,
            'purpose'  => $purpose,
            'amount'   => number_format($amount, 2, '.', ''),
            'currency' => 'NGN',
            'category' => $category,
        ] + $bank);
    }

    /**
     * Send one, and record what finance said.
     *
     * Safe to call again with the same row: the reference is unchanged, so
     * CFIS answers with the requisition it already has.
     *
     * @param  array<string,mixed>  $claims
     * @return array{ok: bool, error?: string, reference?: string, ref?: string}
     */
    public static function send(int $id, string $ref, array $claims): array
    {
        $r = self::post('/api/requests/' . rawurlencode(CfisCheckout::source()), $claims);

        $pdo = Database::pdo();
        if (!($r['ok'] ?? false)) {
            $pdo->prepare("UPDATE cfis_member_requests SET last_error = ?, updated_at = ? WHERE id = ?")
                ->execute([mb_substr((string) ($r['error'] ?? 'Finance could not be reached.'), 0, 400), gmdate('c'), $id]);

            return ['ok' => false, 'error' => (string) ($r['error'] ?? 'Finance could not be reached. Your request is saved — try sending it again.'), 'ref' => $ref];
        }

        $pdo->prepare("UPDATE cfis_member_requests
                SET cfis_reference = ?, status = ?, last_error = NULL, updated_at = ? WHERE id = ?")
            ->execute([(string) ($r['reference'] ?? ''), (string) ($r['status'] ?? 'submitted'), gmdate('c'), $id]);

        return ['ok' => true, 'reference' => (string) ($r['reference'] ?? ''), 'ref' => $ref];
    }

    /** Send again one that never reached finance. The reference does not change. */
    public static function retry(int $userId, int $id, string $name, string $email): array
    {
        self::table();
        $st = Database::pdo()->prepare("SELECT * FROM cfis_member_requests WHERE id = ? AND user_id = ?");
        $st->execute([$id, $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) return ['ok' => false, 'error' => 'No such request.'];
        if (($row['cfis_reference'] ?? '') !== '') return ['ok' => true, 'reference' => $row['cfis_reference']];

        return self::send((int) $row['id'], (string) $row['ref'], [
            'ref' => (string) $row['ref'], 'email' => $email, 'name' => $name,
            'title' => (string) $row['title'], 'purpose' => (string) $row['purpose'],
            'amount' => number_format(((int) $row['amount_minor']) / 100, 2, '.', ''),
            'currency' => 'NGN', 'category' => (string) $row['category'],
        ]);
    }

    /* ── looking ────────────────────────────────────────────────────── */

    /**
     * This member's requests, with where each one now stands.
     *
     * The status comes from CFIS, which owns it. Ours is a copy that is
     * refreshed here and never authoritative: a request approved this
     * morning should not read "waiting" because nobody pressed anything
     * on this site.
     *
     * @return array{ok: bool, requests: list<array<string,mixed>>, categories: list<string>, max?: string, finance_reachable: bool}
     */
    public static function forUser(int $userId): array
    {
        self::table();

        $st = Database::pdo()->prepare(
            "SELECT * FROM cfis_member_requests WHERE user_id = ? ORDER BY id DESC LIMIT 50"
        );
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $live = self::post('/api/requests/' . rawurlencode(CfisCheckout::source()) . '/status', [
            'refs' => array_values(array_map(fn ($r) => (string) $r['ref'], $rows)),
        ]);
        $reachable = (bool) ($live['ok'] ?? false);
        $states = (array) ($live['requests'] ?? []);

        $out = [];
        foreach ($rows as $row) {
            $state = $states[(string) $row['ref']] ?? null;
            if ($state && ($state['status'] ?? '') !== (string) $row['status']) {
                Database::pdo()->prepare("UPDATE cfis_member_requests SET status = ?, updated_at = ? WHERE id = ?")
                    ->execute([(string) $state['status'], gmdate('c'), (int) $row['id']]);
            }
            $out[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'category' => (string) $row['category'],
                'amount' => number_format(((int) $row['amount_minor']) / 100, 2),
                'reference' => (string) ($state['reference'] ?? $row['cfis_reference']),
                'status' => (string) ($state['status'] ?? $row['status']),
                'paid' => (bool) ($state['paid'] ?? false),
                'sent' => ((string) $row['cfis_reference']) !== '',
                'error' => (string) ($row['last_error'] ?? ''),
                'asked_on' => substr((string) $row['created_at'], 0, 10),
            ];
        }

        return [
            'ok' => true,
            'requests' => $out,
            /* Never a local copy. If finance cannot be reached the form
               says so, rather than offering a list that may no longer be
               what CFIS accepts. */
            'categories' => array_values((array) ($live['categories'] ?? [])),
            'max' => (string) ($live['max'] ?? ''),
            'finance_reachable' => $reachable,
            /* Not set up is not "cannot be reached just now": one is a
               setting an administrator makes once, the other passes. */
            'finance_configured' => self::configured(),
        ];
    }

    /**
     * A count for the dashboard, from THIS site only.
     *
     * Deliberately no call to finance: Today is the page every member
     * lands on, and it must not wait on a network round-trip to another
     * system to draw. What is local is also what is certain — we know
     * what we failed to send, because we are the ones who failed to send
     * it. The live statuses are fetched when the card itself is drawn.
     *
     * @return array{total: int, unsent: int, open: int}
     */
    public static function summaryFor(int $userId): array
    {
        self::table();

        $row = Database::pdo()->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN cfis_reference = '' THEN 1 ELSE 0 END) AS unsent,
                    SUM(CASE WHEN status NOT IN ('paid', 'rejected', 'cancelled') THEN 1 ELSE 0 END) AS open
             FROM cfis_member_requests WHERE user_id = ?"
        );
        $row->execute([$userId]);
        $r = $row->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($r['total'] ?? 0),
            'unsent' => (int) ($r['unsent'] ?? 0),
            'open' => (int) ($r['open'] ?? 0),
        ];
    }

    /* ── transport ──────────────────────────────────────────────────── */

    /**
     * The claims travel inside the signed token, which CfisCheckout mints
     * with the key this site already reports income with. Only the
     * transport is written out again here; the credential is not.
     *
     * @param  array<string,mixed>  $claims
     * @return array<string,mixed>
     */
    private static function post(string $path, array $claims): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'Finance is not connected.'];
        }

        $token = CfisCheckout::mint($claims);

        $ch = curl_init(CfisCheckout::base() . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json',
                                   'X-CFIS-Token: ' . $token],
            CURLOPT_POSTFIELDS => json_encode(['token' => $token], JSON_UNESCAPED_SLASHES),
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code === 0) {
            return ['ok' => false, 'error' => 'Finance could not be reached' . ($err !== '' ? ' (' . $err . ')' : '') . '.'];
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'Finance answered with something unreadable.'];
        }

        /* A refusal from CFIS is a sentence written for the member — the
           heading is not on offer, the amount is over the ceiling — so it
           is passed through rather than replaced with "something went
           wrong", which tells them nothing they can act on. */
        return $json;
    }
}
