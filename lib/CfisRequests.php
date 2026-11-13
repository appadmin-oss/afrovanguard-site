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

    /**
     * ON BY DEFAULT. Asking used to need CFIS connected first, so on a site
     * without the key every member read "not switched on" and could not ask
     * at all. Now a member can always ask: when finance is not connected, or
     * cannot be reached, the request is KEPT here under its own reference,
     * the office is told by email, and it is sent by itself (sendPending,
     * from the cron) the moment finance can take it. The reference never
     * changes, so a request that did arrive is never a second one.
     *
     * The kinds of spending are finance's. With finance not answering, the
     * form offers CFIS's own default headings (its config/cfis.php
     * member_requests.categories), and CFIS still decides on arrival: a
     * heading it no longer takes comes back refused, with its reason, on the
     * member's request.
     */
    public const DEFAULT_CATEGORIES = ['transport', 'materials', 'equipment', 'venue', 'internet', 'utility', 'facilitation', 'repairs', 'events', 'publicity'];

    /**
     * The same, with the words a member reads. CFIS publishes its own
     * (`category_list`) and keeps them on Books › Request categories; this
     * is only what the form says while finance is not answering — and CFIS
     * still decides on arrival.
     */
    public const DEFAULT_CATEGORY_LIST = [
        ['key' => 'transport',    'label' => 'Transport and travel',     'hint' => 'Fares, fuel for a trip, logistics'],
        ['key' => 'materials',    'label' => 'Learning materials',       'hint' => 'Printing, stationery, workbooks'],
        ['key' => 'equipment',    'label' => 'Equipment and ICT',        'hint' => 'Devices, cables, tools'],
        ['key' => 'venue',        'label' => 'Venue and setup',          'hint' => 'Hall hire, chairs, decoration for a session'],
        ['key' => 'internet',     'label' => 'Internet and airtime',     'hint' => 'Data, airtime, hosting'],
        ['key' => 'utility',      'label' => 'Utilities',                'hint' => 'Electricity (prepaid units or a bill), water, generator fuel, waste collection'],
        ['key' => 'facilitation', 'label' => 'Facilitation fees',        'hint' => 'Paying a facilitator or instructor'],
        ['key' => 'repairs',      'label' => 'Repairs and maintenance',  'hint' => 'Fixing a generator, plumbing, a broken device'],
        ['key' => 'events',       'label' => 'Events',                   'hint' => 'Refreshments and other costs of an event'],
        ['key' => 'publicity',    'label' => 'Publicity and printing',   'hint' => 'Flyers, banners, adverts'],
    ];

    /** Money already spent over this, in naira, needs a receipt or a reason. CFIS's figure wins when it answers. */
    public const RECEIPT_OVER_NGN = 5000;

    /** Receipts with one request: CFIS takes three, each up to 5MB. */
    public const MAX_FILES = 3;
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;
    /** All of them together, so the body stays under a shared host's upload limit. */
    public const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const RECEIPT_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];

    /** Kept requests older than this are no longer sent by themselves; the member sends them again. */
    private const PENDING_DAYS = 60;

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
        /* Where to pay them, kept with a request that waits, so it goes
           with it when it is sent later. */
        /* Money already spent, the reason there is no receipt, and the
           receipts themselves (name, hash, and where the file waits until
           finance has it), kept with a request that waits. */
        foreach (['bank' => 'TEXT', 'asker_name' => "VARCHAR(160) NOT NULL DEFAULT ''", 'asker_email' => "VARCHAR(191) NOT NULL DEFAULT ''",
                  'claim_kind' => "VARCHAR(16) NOT NULL DEFAULT 'advance'", 'no_receipt' => 'TEXT', 'evidence' => 'TEXT'] as $col => $decl) {
            try {
                if (!Database::columnExists('cfis_member_requests', $col)) Database::pdo()->exec("ALTER TABLE cfis_member_requests ADD COLUMN $col $decl");
            } catch (Throwable $e) {}
        }
    }

    /* ── asking ─────────────────────────────────────────────────────── */

    /**
     * @param  array<string,mixed>  $in
     * @return array{ok: bool, error?: string, reference?: string, ref?: string}
     */
    public static function submit(int $userId, array $in, string $name, string $email, array $files = []): array
    {
        self::table();

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

        /* Money already spent, or money needed. Every request used to arrive
           at finance as money needed, so the receipt rule — which asks only
           about money already spent — never asked the member who had paid
           out of their own pocket: the person this page was built for. */
        $claimKind = ($in['claim_kind'] ?? '') === 'reimbursement' ? 'reimbursement' : 'advance';
        $noReceipt = mb_substr(trim((string) ($in['no_receipt_reason'] ?? '')), 0, 500);

        $receipts = self::receipts($files);
        if (isset($receipts['error'])) return ['ok' => false, 'error' => $receipts['error']];

        /* Asked here as well as at finance, so a request kept while finance
           is not answering is not refused days later for a receipt the
           member could have added now. */
        if ($claimKind === 'reimbursement' && $receipts['files'] === [] && $noReceipt === '' && $amount > self::RECEIPT_OVER_NGN) {
            return ['ok' => false, 'error' => 'This is money you have already spent, over ₦' . number_format(self::RECEIPT_OVER_NGN)
                . '. Add a photo of the receipt, or say why there is none.'];
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

        $evidence = self::keep($ref, $receipts['files']);

        $pdo = Database::pdo();
        $st = $pdo->prepare("INSERT INTO cfis_member_requests
            (user_id, ref, title, purpose, category, amount_minor, status, created_at, updated_at, bank, asker_name, asker_email, claim_kind, no_receipt, evidence)
            VALUES (?, ?, ?, ?, ?, ?, 'unsent', ?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([$userId, $ref, mb_substr($title, 0, 160), $purpose, $category, $minor, $now, $now, (string) json_encode($bank),
                      mb_substr($name, 0, 160), mb_substr(strtolower($email), 0, 191), $claimKind, $noReceipt, (string) json_encode($evidence)]);
        $id = (int) $pdo->lastInsertId();
        $row = ['id' => $id, 'ref' => $ref, 'title' => $title, 'purpose' => $purpose, 'category' => $category,
                'amount_minor' => $minor, 'bank' => json_encode($bank), 'claim_kind' => $claimKind,
                'no_receipt' => $noReceipt, 'evidence' => json_encode($evidence)];

        if (!self::configured()) {
            $pdo->prepare("UPDATE cfis_member_requests SET last_error = ? WHERE id = ?")
                ->execute(['Kept here: it goes to finance as soon as finance is connected.', $id]);
            self::tellFinance($row, $name, $email, 'Kept here: finance (CFIS) is not connected yet. It is sent by itself once CFIS_SECRET is set in Studio → Rules & AI → Setup.');
            return ['ok' => true, 'queued' => true, 'ref' => $ref,
                    'message' => 'Asked. Your request is kept under ' . $ref . ' and goes to finance by itself as soon as finance is connected. Finance has been told.'];
        }

        $r = self::send($id, $ref, self::claimsOf($row, $name, $email), $evidence);
        /* Finance not answering is not the member's problem to solve: the
           request is kept and goes when it can. A refusal FROM finance (a
           heading not on offer, over the ceiling) is still said as such. */
        if (empty($r['ok']) && !empty($r['unreachable'])) {
            self::tellFinance($row, $name, $email, 'Kept here: CFIS could not be reached. It is sent again by itself.');
            return ['ok' => true, 'queued' => true, 'ref' => $ref,
                    'message' => 'Asked. Finance could not be reached just now, so your request is kept under ' . $ref . ' and goes by itself as soon as it can.'];
        }
        if (!empty($r['ok'])) {
            self::tellFinance($row, $name, $email, 'In CFIS as ' . (string) ($r['reference'] ?? '') . ', waiting for approval there.');
        }
        return $r;
    }

    /**
     * Send the requests that are waiting: never reached finance, made in the
     * last PENDING_DAYS. For the cron. Stops at the first sign finance is
     * still not answering, rather than timing out once per request.
     *
     * @return array{sent:int, refused:int, waiting:int}
     */
    public static function sendPending(int $limit = 20): array
    {
        self::table();
        $out = ['sent' => 0, 'refused' => 0, 'waiting' => 0];
        if (!self::configured()) return $out;
        /* Who asked, as they were when they asked; their account now, for a
           request kept from before these were written down. */
        $st = Database::pdo()->prepare("SELECT r.*, COALESCE(NULLIF(r.asker_email, ''), u.email, '') AS email, COALESCE(NULLIF(r.asker_name, ''), u.name, '') AS name
            FROM cfis_member_requests r LEFT JOIN lms_users u ON u.id = r.user_id
            WHERE r.cfis_reference = '' AND r.status = 'unsent' AND r.created_at >= ? ORDER BY r.id LIMIT " . max(1, min(100, $limit)));
        $st->execute([gmdate('c', time() - self::PENDING_DAYS * 86400)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!filter_var((string) $row['email'], FILTER_VALIDATE_EMAIL)) continue;   // nobody for finance to answer
            $r = self::send((int) $row['id'], (string) $row['ref'], self::claimsOf($row, (string) $row['name'], (string) $row['email']), self::evidenceOf($row));
            if (!empty($r['ok'])) { $out['sent']++; continue; }
            if (!empty($r['unreachable'])) { $out['waiting']++; break; }
            $out['refused']++;
        }
        return $out;
    }

    /**
     * What CFIS is sent for a stored request.
     *
     * The receipts are named here by their SHA-256, so they are covered by
     * the signature: CFIS refuses bytes that do not match, or a file the
     * token did not list.
     */
    private static function claimsOf(array $row, string $name, string $email): array
    {
        $bank = json_decode((string) ($row['bank'] ?? ''), true);
        $claims = [
            'ref' => (string) $row['ref'], 'email' => $email, 'name' => $name,
            'title' => (string) $row['title'], 'purpose' => (string) $row['purpose'],
            'amount' => number_format(((int) $row['amount_minor']) / 100, 2, '.', ''),
            'currency' => 'NGN', 'category' => (string) $row['category'],
            'claim_kind' => (string) ($row['claim_kind'] ?? 'advance') === 'reimbursement' ? 'reimbursement' : 'advance',
        ] + (is_array($bank) ? $bank : []);
        if (trim((string) ($row['no_receipt'] ?? '')) !== '') $claims['no_receipt_reason'] = (string) $row['no_receipt'];
        $evidence = self::evidenceOf($row);
        if ($evidence !== []) {
            $claims['evidence'] = array_map(fn ($e) => ['name' => $e['name'], 'sha256' => $e['sha256']], $evidence);
        }
        return $claims;
    }

    /** @return list<array{name:string, sha256:string, path:string}> */
    private static function evidenceOf(array $row): array
    {
        $e = json_decode((string) ($row['evidence'] ?? ''), true);
        return is_array($e) ? array_values(array_filter($e, fn ($x) => is_array($x) && !empty($x['sha256']))) : [];
    }

    /**
     * The receipts a member chose, checked before anything is written.
     *
     * By their BYTES, not their names: a file called receipt.jpg that is a
     * script is not a receipt. Photos and PDFs only — what a phone makes and
     * what a supplier sends.
     *
     * @param  array<int, array{name?:string, tmp_name?:string, error?:int, size?:int}>  $files
     * @return array{files: list<array{name:string, mime:string, tmp:string}>, error?: string}
     */
    private static function receipts(array $files): array
    {
        $out = []; $total = 0;
        foreach ($files as $f) {
            if (!is_array($f) || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if ((int) ($f['error'] ?? 0) !== UPLOAD_ERR_OK || !is_file((string) ($f['tmp_name'] ?? ''))) {
                return ['files' => [], 'error' => 'A receipt did not upload. It may be too large — try a smaller photo.'];
            }
            $size = (int) filesize((string) $f['tmp_name']);
            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file((string) $f['tmp_name']);
            if (!isset(self::RECEIPT_TYPES[$mime])) {
                return ['files' => [], 'error' => 'A receipt must be a photo or a PDF.'];
            }
            if ($size > self::MAX_FILE_BYTES) {
                return ['files' => [], 'error' => 'A receipt is over 5MB. Take the photo again at a smaller size.'];
            }
            $total += $size;
            $out[] = ['name' => mb_substr(basename((string) ($f['name'] ?? 'receipt')), 0, 120) ?: 'receipt', 'mime' => $mime, 'tmp' => (string) $f['tmp_name']];
        }
        if (count($out) > self::MAX_FILES) return ['files' => [], 'error' => 'Up to ' . self::MAX_FILES . ' receipts with one request.'];
        if ($total > self::MAX_TOTAL_BYTES) return ['files' => [], 'error' => 'The receipts together are over 8MB. Send smaller photos.'];
        return ['files' => $out];
    }

    /**
     * Keep the receipts until finance has them, in the private store (never
     * the web root: a receipt carries a name, an address, sometimes a card
     * number). Deleted once CFIS confirms it holds the request.
     *
     * @param  list<array{name:string, mime:string, tmp:string}>  $files
     * @return list<array{name:string, sha256:string, path:string}>
     */
    private static function keep(string $ref, array $files): array
    {
        if ($files === []) return [];
        $dir = dirname(av_private_path('cfis-receipts.keep')) . '/cfis-receipts';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $kept = [];
        foreach ($files as $i => $f) {
            $bytes = (string) file_get_contents($f['tmp']);
            $path = $dir . '/' . preg_replace('/[^A-Za-z0-9-]/', '', $ref) . '-' . $i . '.' . self::RECEIPT_TYPES[$f['mime']];
            if (@file_put_contents($path, $bytes) === false) continue;
            @chmod($path, 0600);
            $kept[] = ['name' => $f['name'], 'sha256' => hash('sha256', $bytes), 'path' => $path];
        }
        return $kept;
    }

    /**
     * Finance hears of every request, wherever it now stands — sent into
     * CFIS, or kept here because CFIS is not connected or not answering —
     * so no money is asked for into a void. To the finance mailbox
     * (av_finance_email(), Studio → Setup): it went to the general mailbox,
     * and only when the request could not be sent. Best effort: the request
     * is kept whatever the mail does.
     */
    private static function tellFinance(array $row, string $name, string $email, string $where): void
    {
        if (!class_exists('Mailer')) return;
        $to = av_finance_email();
        $amount = ((int) ($row['amount_minor'] ?? 0)) / 100;
        $receipts = count(self::evidenceOf($row));
        try {
            Mailer::send($to, 'Finance request: ' . (string) $row['title'] . ' — ₦' . number_format($amount, 2), Mailer::shell('A member has asked finance for money', [
                'Reference' => (string) $row['ref'], 'From' => $name . ' <' . $email . '>', 'For' => (string) $row['title'],
                'Why' => (string) $row['purpose'], 'Kind' => self::labelOf((string) $row['category']),
                'Already spent?' => ((string) ($row['claim_kind'] ?? '')) === 'reimbursement' ? 'Yes — a claim for money they paid' : 'No — money needed',
                'Receipts' => $receipts > 0 ? $receipts . ' attached in CFIS' : (trim((string) ($row['no_receipt'] ?? '')) !== '' ? 'None: ' . (string) $row['no_receipt'] : 'None'),
                'Amount' => '₦' . number_format($amount, 2),
                'Where it is' => $where,
            ]), ['replyTo' => $email]);
        } catch (Throwable $e) { error_log('[cfis-requests] tell finance: ' . $e->getMessage()); }
    }

    /** The words for a category key, from the list the form was drawn from. */
    private static function labelOf(string $key, array $list = self::DEFAULT_CATEGORY_LIST): string
    {
        foreach ($list as $c) if (($c['key'] ?? '') === $key) return (string) $c['label'];
        return ucfirst(str_replace('_', ' ', $key));
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
    public static function send(int $id, string $ref, array $claims, array $evidence = []): array
    {
        /* The bytes travel beside the token; the token names each by hash. */
        $files = [];
        foreach ($evidence as $e) {
            $bytes = is_file((string) ($e['path'] ?? '')) ? (string) file_get_contents((string) $e['path']) : '';
            if ($bytes === '' || hash('sha256', $bytes) !== $e['sha256']) {
                return ['ok' => false, 'ref' => $ref, 'error' => 'A receipt kept with this request can no longer be read. Ask again with the receipt.'];
            }
            $files[] = ['name' => (string) $e['name'], 'data' => base64_encode($bytes)];
        }
        $r = self::post('/api/requests/' . rawurlencode(CfisCheckout::source()), $claims, $files === [] ? [] : ['files' => $files]);

        $pdo = Database::pdo();
        if (!($r['ok'] ?? false)) {
            $pdo->prepare("UPDATE cfis_member_requests SET last_error = ?, updated_at = ? WHERE id = ?")
                ->execute([mb_substr((string) ($r['error'] ?? 'Finance could not be reached.'), 0, 400), gmdate('c'), $id]);

            return ['ok' => false, 'error' => (string) ($r['error'] ?? 'Finance could not be reached. Your request is saved — try sending it again.'), 'ref' => $ref,
                    'unreachable' => !empty($r['unreachable'])];
        }

        $pdo->prepare("UPDATE cfis_member_requests
                SET cfis_reference = ?, status = ?, last_error = NULL, updated_at = ? WHERE id = ?")
            ->execute([(string) ($r['reference'] ?? ''), (string) ($r['status'] ?? 'submitted'), gmdate('c'), $id]);

        /* Finance holds them now; a copy here is a receipt with somebody's
           details on it, kept for no reason. The names and hashes stay. */
        foreach ($evidence as $e) if (is_file((string) ($e['path'] ?? ''))) @unlink((string) $e['path']);

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

        if (!self::configured()) return ['ok' => false, 'error' => 'Finance is not connected yet. Your request is kept and goes by itself as soon as it is.'];
        return self::send((int) $row['id'], (string) $row['ref'], self::claimsOf($row, $name, $email), self::evidenceOf($row));
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
        /* The words come from finance with the list (category_list); a
           CFIS from before it published them sends keys only. */
        $list = $reachable
            ? (is_array($live['category_list'] ?? null) && $live['category_list'] !== []
                ? array_values(array_filter($live['category_list'], 'is_array'))
                : array_map(fn ($k) => ['key' => (string) $k, 'label' => self::labelOf((string) $k), 'hint' => ''], (array) ($live['categories'] ?? [])))
            : self::DEFAULT_CATEGORY_LIST;

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
                'category' => self::labelOf((string) $row['category'], array_merge($list, self::DEFAULT_CATEGORY_LIST)),
                'claim_kind' => (string) ($row['claim_kind'] ?? 'advance'),
                'receipts' => count(self::evidenceOf($row)),
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
            'categories' => array_column($list, 'key'),
            'category_list' => $list,
            'receipt_over' => (string) ($live['receipt_over'] ?? self::RECEIPT_OVER_NGN),
            'max_files' => self::MAX_FILES,
            'max' => (string) ($live['max'] ?? ''),
            'finance_reachable' => $reachable,
            /* Not set up is not "cannot be reached just now": one is a
               setting an administrator makes once, the other passes. */
            'finance_configured' => self::configured(),
            /* Asking is always open: what finance cannot take yet is kept. */
            'accepting' => true,
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
    private static function post(string $path, array $claims, array $extra = []): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'Finance is not connected.', 'unreachable' => true];
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
            CURLOPT_POSTFIELDS => json_encode(['token' => $token] + $extra, JSON_UNESCAPED_SLASHES),
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code === 0) {
            return ['ok' => false, 'error' => 'Finance could not be reached' . ($err !== '' ? ' (' . $err . ')' : '') . '.', 'unreachable' => true];
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'Finance answered with something unreadable.', 'unreachable' => $code >= 500 || $code === 404];
        }

        /* A refusal from CFIS is a sentence written for the member — the
           heading is not on offer, the amount is over the ceiling — so it
           is passed through rather than replaced with "something went
           wrong", which tells them nothing they can act on. */
        return $json;
    }
}
