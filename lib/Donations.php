<?php
/**
 * lib/Donations.php — the donation store, and what a card charge becomes.
 *
 * This was the middle of process-donation.php, a 999-line script that is also
 * a web route. It is here because the payment webhook can now arrive at EITHER
 * address (lib/PaymentWebhook.php): a gateway posts to one URL, and the site
 * has two money handlers, so each has to be able to hand a charge to the other.
 * A handler that lives inside a top-level script cannot be called by anybody.
 *
 * The donations themselves still live in `donations.json` (private, never in
 * the web root): the donor wall, the campaign totals and the public figures
 * read it, and moving that is not this change.
 *
 * WHAT DID MOVE IS THE IDEMPOTENCY. The file keeps the newest 500 donations
 * and the duplicate check walked that list, so a webhook redelivered for an
 * older reference — Paystack retries for days, and a restored backup replays
 * everything — was counted a second time, with the campaign total and the
 * donor count moving with it. The reference is now claimed in a table that
 * never forgets (`donation_refs`); the file is the display, not the ledger.
 */
declare(strict_types=1);

final class Donations
{
    /** How many donations the display file keeps. The gate is the table now. */
    public const KEEP = 500;

    private static bool $ready = false;

    public static function file(): string { return av_private_path('donations.json'); }

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        try {
            Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS donation_refs (
                reference   VARCHAR(191) PRIMARY KEY,
                amount      VARCHAR(32) NOT NULL DEFAULT '',
                currency    VARCHAR(8) NOT NULL DEFAULT '',
                campaign    VARCHAR(64) NOT NULL DEFAULT '',
                recorded_at VARCHAR(32) NOT NULL DEFAULT ''
            )");
            /* The ledger: every gift, kept for good, with the donor as they are —
               the display file keeps the newest 500 and says "Anonymous" for
               anyone off the wall, which is right for the wall and useless for
               the office. Read by the Studio's Money → Donations, never by a
               public page. */
            Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS donations_ledger (
                reference    VARCHAR(191) PRIMARY KEY,
                type         VARCHAR(16) NOT NULL DEFAULT '',
                donor_name   VARCHAR(120) NOT NULL DEFAULT '',
                email        VARCHAR(191) NOT NULL DEFAULT '',
                amount       DECIMAL(14,2) NOT NULL DEFAULT 0,
                currency     VARCHAR(8) NOT NULL DEFAULT 'NGN',
                campaign     VARCHAR(64) NOT NULL DEFAULT '',
                frequency    VARCHAR(24) NOT NULL DEFAULT '',
                listed       INTEGER NOT NULL DEFAULT 0,
                recorded_by  VARCHAR(191) NOT NULL DEFAULT '',
                confirmed_by VARCHAR(191) NOT NULL DEFAULT '',
                given_at     VARCHAR(32) NOT NULL DEFAULT ''
            );
            CREATE INDEX IF NOT EXISTS ix_donations_ledger_email ON donations_ledger (email);
            CREATE INDEX IF NOT EXISTS ix_donations_ledger_given ON donations_ledger (given_at)");
        } catch (Throwable $e) { error_log('[donations] ensure: ' . $e->getMessage()); }
    }

    /** One gift into the ledger. Never throws: the ledger is the office's view, not the gate on counting. */
    public static function ledgerAdd(array $entry, array $private = []): void
    {
        self::ensure();
        $ref = mb_substr((string) ($entry['reference'] ?? ($entry['id'] ?? '')), 0, 191);
        if ($ref === '') return;
        $name = trim((string) ($private['name'] ?? ''));
        if ($name === '') $name = (string) ($entry['name'] ?? '');
        try {
            Database::pdo()->prepare(Database::insertIgnore('donations_ledger', ['reference', 'type', 'donor_name', 'email', 'amount', 'currency', 'campaign', 'frequency', 'listed', 'recorded_by', 'confirmed_by', 'given_at']))
                ->execute([$ref, mb_substr((string) ($entry['type'] ?? ''), 0, 16), mb_substr($name, 0, 120), strtolower(trim((string) ($entry['email'] ?? ''))),
                    (float) ($entry['amount'] ?? 0), mb_substr((string) ($entry['currency'] ?? 'NGN'), 0, 8), mb_substr((string) ($entry['campaign'] ?? ''), 0, 64),
                    mb_substr((string) ($entry['frequency'] ?? ''), 0, 24), !empty($entry['listed']) ? 1 : 0, mb_substr((string) ($entry['recorded_by'] ?? ''), 0, 191),
                    mb_substr((string) ($entry['confirmed_by'] ?? ''), 0, 191), (string) ($entry['created_at'] ?? date('c'))]);
        } catch (Throwable $e) { error_log('[donations] ledger ' . $ref . ': ' . $e->getMessage()); }
    }

    /** The gifts that were here before the ledger: carried over from the display file, once. */
    public static function ledgerBackfill(): int
    {
        self::ensure();
        try { if (Database::metaGet('donations_ledger_backfilled') === '1') return 0; } catch (Throwable $e) { return 0; }
        $n = 0;
        foreach (array_reverse((array) (self::read()['donations'] ?? [])) as $d) { if (is_array($d)) { self::ledgerAdd($d); $n++; } }
        try { Database::metaSet('donations_ledger_backfilled', '1'); } catch (Throwable $e) {}
        return $n;
    }

    /**
     * The office's list: search, filter by appeal, kind and dates, newest first,
     * paged, with the true total and sum for the filter (never the page's).
     */
    public static function ledger(array $f = []): array
    {
        self::ledgerBackfill();
        [$where, $args] = self::ledgerWhere($f);
        $pdo = Database::pdo();
        $size = max(10, min(200, (int) ($f['page_size'] ?? 50)));
        $page = max(0, (int) ($f['page'] ?? 0));
        $st = $pdo->prepare('SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN currency = \'NGN\' THEN amount ELSE 0 END), 0) s FROM donations_ledger' . $where);
        $st->execute($args); $tot = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 's' => 0];
        $st = $pdo->prepare('SELECT * FROM donations_ledger' . $where . ' ORDER BY given_at DESC, reference DESC LIMIT ' . $size . ' OFFSET ' . ($page * $size));
        $st->execute($args);
        return ['rows' => array_map([self::class, 'ledgerShape'], $st->fetchAll(PDO::FETCH_ASSOC)), 'total' => (int) $tot['n'], 'sum_ngn' => (float) $tot['s'],
                'page' => $page, 'page_size' => $size];
    }

    /** The header's figures: this month and this year, in naira, and how many gave this year. */
    public static function summary(): array
    {
        self::ledgerBackfill();
        $m = date('Y-m'); $y = date('Y');
        $st = Database::pdo()->prepare("SELECT
                COALESCE(SUM(CASE WHEN substr(given_at, 1, 7) = ? AND currency = 'NGN' THEN amount ELSE 0 END), 0) month_ngn,
                COALESCE(SUM(CASE WHEN substr(given_at, 1, 4) = ? AND currency = 'NGN' THEN amount ELSE 0 END), 0) year_ngn,
                COUNT(DISTINCT CASE WHEN substr(given_at, 1, 4) = ? AND email <> '' AND type <> 'inkind' THEN email END) donors_year
            FROM donations_ledger");
        $st->execute([$m, $y, $y]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['month_ngn' => (float) ($r['month_ngn'] ?? 0), 'year_ngn' => (float) ($r['year_ngn'] ?? 0), 'donors_year' => (int) ($r['donors_year'] ?? 0)];
    }

    /** Every row matching the filter, for an export (capped). */
    public static function ledgerAll(array $f = [], int $cap = 20000): array
    {
        self::ledgerBackfill();
        [$where, $args] = self::ledgerWhere($f);
        $st = Database::pdo()->prepare('SELECT * FROM donations_ledger' . $where . ' ORDER BY given_at DESC LIMIT ' . max(1, $cap));
        $st->execute($args);
        return array_map([self::class, 'ledgerShape'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Filters by name, never SQL from the caller; an unknown value is ignored rather than refused. */
    private static function ledgerWhere(array $f): array
    {
        $w = []; $a = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') { $w[] = '(donor_name LIKE ? OR email LIKE ? OR reference LIKE ?)'; $like = '%' . str_replace(['%', '_'], '', $q) . '%'; array_push($a, $like, $like, $like); }
        $camp = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($f['campaign'] ?? '')));
        if ($camp !== '') { $w[] = 'campaign = ?'; $a[] = $camp; }
        $type = (string) ($f['type'] ?? '');
        if (in_array($type, ['card', 'bank_static', 'bank_va', 'inkind'], true)) { $w[] = 'type = ?'; $a[] = $type; }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            $d = (string) ($f[$k] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $w[] = 'substr(given_at, 1, 10) ' . $op . ' ?'; $a[] = $d; }
        }
        $email = strtolower(trim((string) ($f['email'] ?? '')));
        if ($email !== '') { $w[] = 'email = ?'; $a[] = $email; }
        return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $a];
    }

    private static function ledgerShape(array $r): array
    {
        return ['reference' => (string) $r['reference'], 'type' => (string) $r['type'], 'name' => (string) $r['donor_name'], 'email' => (string) $r['email'],
                'amount' => (float) $r['amount'], 'currency' => (string) $r['currency'], 'campaign' => (string) $r['campaign'], 'frequency' => (string) $r['frequency'],
                'listed' => (int) $r['listed'] === 1, 'recorded_by' => (string) $r['recorded_by'], 'confirmed_by' => (string) $r['confirmed_by'], 'given_at' => (string) $r['given_at']];
    }

    /**
     * Donors: one row per person (by email), with what they gave and when —
     * and the segments a donor office works from: gave last year and not yet
     * this year (LYBUNT, the people most worth a call), gave for the first time
     * this year, and give regularly. Retention: of last year's donors, how many
     * gave again this year.
     */
    public static function donors(string $segment = 'all', string $q = '', ?int $year = null): array
    {
        self::ledgerBackfill();
        $year = $year ?? (int) date('Y');
        $ly = (string) ($year - 1); $ty = (string) $year;
        $rows = Database::pdo()->query("SELECT email, MAX(donor_name) name, COUNT(*) gifts, COALESCE(SUM(CASE WHEN currency = 'NGN' THEN amount ELSE 0 END), 0) total,
                MIN(given_at) first_at, MAX(given_at) last_at,
                SUM(CASE WHEN substr(given_at, 1, 4) = '$ty' THEN 1 ELSE 0 END) this_year,
                SUM(CASE WHEN substr(given_at, 1, 4) = '$ly' THEN 1 ELSE 0 END) last_year,
                SUM(CASE WHEN frequency IN ('Monthly', 'Annual', 'Recurring') THEN 1 ELSE 0 END) recurring
            FROM donations_ledger WHERE email <> '' AND type <> 'inkind' GROUP BY email")->fetchAll(PDO::FETCH_ASSOC);
        $all = array_map(static fn($r) => ['email' => (string) $r['email'], 'name' => (string) $r['name'], 'gifts' => (int) $r['gifts'], 'total' => (float) $r['total'],
            'first_at' => (string) $r['first_at'], 'last_at' => (string) $r['last_at'], 'this_year' => (int) $r['this_year'] > 0, 'last_year' => (int) $r['last_year'] > 0,
            'recurring' => (int) $r['recurring'] > 0, 'new' => substr((string) $r['first_at'], 0, 4) === $ty], $rows);
        $lastYear = count(array_filter($all, static fn($d) => $d['last_year']));
        $kept = count(array_filter($all, static fn($d) => $d['last_year'] && $d['this_year']));
        $seg = [
            'lybunt'    => static fn($d) => $d['last_year'] && !$d['this_year'],
            'new'       => static fn($d) => $d['new'],
            'recurring' => static fn($d) => $d['recurring'],
        ];
        $list = isset($seg[$segment]) ? array_values(array_filter($all, $seg[$segment])) : $all;
        $q = mb_strtolower(trim($q));
        if ($q !== '') $list = array_values(array_filter($list, static fn($d) => str_contains(mb_strtolower($d['name'] . ' ' . $d['email']), $q)));
        usort($list, static fn($a, $b) => strcmp($b['last_at'], $a['last_at']));
        return ['donors' => array_slice($list, 0, 500), 'total' => count($list), 'year' => $year,
                'counts' => ['all' => count($all), 'lybunt' => count(array_filter($all, $seg['lybunt'])), 'new' => count(array_filter($all, $seg['new'])), 'recurring' => count(array_filter($all, $seg['recurring']))],
                'retention' => $lastYear > 0 ? (int) round(100 * $kept / $lastYear) : null, 'retained' => $kept, 'last_year_donors' => $lastYear];
    }

    public static function defaultData(): array
    {
        return [
            'version'      => 2,
            'last_updated' => date('c'),
            'totals'       => ['donors' => 0, 'raised_ngn' => 0, 'inkind' => 0],
            'campaigns'    => [
                'general'  => ['raised' => 0, 'goal' => 50000000, 'donors' => 0],
                'sts'      => ['raised' => 0, 'goal' => 25000000, 'donors' => 0],
                'techhome' => ['raised' => 0, 'goal' => 15000000, 'donors' => 0],
            ],
            'donations' => [],
        ];
    }

    public static function read(): array
    {
        $f = self::file();
        if (!file_exists($f)) return self::defaultData();
        $fp = @fopen($f, 'r');
        if (!$fp) return self::defaultData();
        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $d = json_decode((string) $raw, true);
        return (!is_array($d) || empty($d['campaigns'])) ? self::defaultData() : $d;
    }

    public static function write(array $data): bool
    {
        $f = self::file();
        $isNew = !file_exists($f);
        $data['last_updated'] = date('c');
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $fp = @fopen($f, 'c');
        if (!$fp) { error_log('[donations] cannot write ' . $f); return false; }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0); rewind($fp); fwrite($fp, (string) $json); fflush($fp);
        flock($fp, LOCK_UN); fclose($fp);
        if ($isNew && file_exists($f)) @chmod($f, 0600);
        return true;
    }

    /* ══ Idempotency ════════════════════════════════════════════════════════ */

    /**
     * Claim a reference. True the first time, false ever after.
     *
     * The PRIMARY KEY does the work: two webhooks arriving at once both try
     * the insert and exactly one succeeds, with no read-then-write window
     * between them. A database that cannot answer falls back to the file's
     * own scan — which is what this did before, so a database hiccup is no
     * worse than the old behaviour rather than a silent double count.
     */
    public static function claim(string $ref): bool
    {
        self::ensure();
        try {
            Database::pdo()->prepare('INSERT INTO donation_refs (reference, recorded_at) VALUES (?, ?)')
                ->execute([mb_substr($ref, 0, 191), gmdate('Y-m-d H:i:s')]);
            return true;
        } catch (Throwable $e) {
            if (self::known($ref)) return false;                       // already claimed: a duplicate
            error_log('[donations] claim ' . $ref . ': ' . $e->getMessage());
            return !self::inFile($ref);                                 // the old gate, as a fallback
        }
    }

    /** Give a claimed reference back — the donation was not stored after all. */
    public static function release(string $ref): void
    {
        try { Database::pdo()->prepare('DELETE FROM donation_refs WHERE reference = ?')->execute([mb_substr($ref, 0, 191)]); }
        catch (Throwable $e) { error_log('[donations] release ' . $ref . ': ' . $e->getMessage()); }
    }

    public static function known(string $ref): bool
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT 1 FROM donation_refs WHERE reference = ?');
            $st->execute([mb_substr($ref, 0, 191)]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    private static function inFile(string $ref): bool
    {
        foreach (self::read()['donations'] ?? [] as $d) if ((string) ($d['reference'] ?? '') === $ref) return true;
        return false;
    }

    /**
     * Record one donation, once. Returns true when it was new.
     *
     * The reference is claimed first, then the file is appended under an
     * exclusive lock — the check and the write in one lock, as before, so no
     * concurrent write slips between them.
     */
    public static function storeIfNew(string $ref, array $entry, float $amount, string $campaign, string $currency, array $private = []): bool
    {
        if ($ref === '') return false;
        if (!self::claim($ref)) return false;

        $f = self::file();
        /* 'c+' (read+write), not 'c' (write-only): the re-read below uses
           stream_get_contents(), which returns '' on a write-only handle. */
        $fp = @fopen($f, 'c+');
        if (!$fp) { error_log('[donations] cannot open ' . $f . ' for write'); self::release($ref); return false; }
        flock($fp, LOCK_EX);

        $raw  = stream_get_contents($fp) ?: '';
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['campaigns'])) $data = self::defaultData();

        foreach ($data['donations'] as $d) {
            if (($d['reference'] ?? '') === $ref) {           // in the file already: recorded, so not new
                flock($fp, LOCK_UN); fclose($fp);
                return false;
            }
        }

        array_unshift($data['donations'], $entry);
        if (count($data['donations']) > self::KEEP) $data['donations'] = array_slice($data['donations'], 0, self::KEEP);
        $key = isset($data['campaigns'][$campaign]) ? $campaign : 'general';
        $data['campaigns'][$key]['donors'] = ($data['campaigns'][$key]['donors'] ?? 0) + 1;
        if ($currency === 'NGN') {
            $data['campaigns'][$key]['raised'] = ($data['campaigns'][$key]['raised'] ?? 0) + $amount;
            $data['totals']['raised_ngn']      = ($data['totals']['raised_ngn'] ?? 0) + $amount;
        }
        $data['totals']['donors'] = ($data['totals']['donors'] ?? 0) + 1;
        $data['last_updated']     = date('c');

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        ftruncate($fp, 0); rewind($fp); fwrite($fp, (string) $json); fflush($fp);
        flock($fp, LOCK_UN); fclose($fp);

        try {
            Database::pdo()->prepare('UPDATE donation_refs SET amount = ?, currency = ?, campaign = ? WHERE reference = ?')
                ->execute([(string) $amount, mb_substr($currency, 0, 8), mb_substr($campaign, 0, 64), mb_substr($ref, 0, 191)]);
        } catch (Throwable $e) { /* the claim is what matters; these are for reading back */ }

        /* The ledger keeps the donor's real name ($private), which the display file must not. */
        self::ledgerAdd($entry + ['reference' => $ref, 'amount' => $amount, 'currency' => $currency, 'campaign' => $campaign], $private);

        if (function_exists('av_emit_event')) {
            av_emit_event('donation.completed', [
                'reference' => $ref, 'amount' => $amount, 'currency' => $currency, 'campaign' => $campaign,
                'name' => (string) ($entry['name'] ?? ''), 'email' => (string) ($entry['email'] ?? ''),
            ]);
        }
        return true;
    }

    /* ══ A card charge, as a donation ═══════════════════════════════════════ */

    /**
     * Turn a verified `charge.success` into a donation on the wall and on a
     * campaign total. The whole of this was inline in the webhook; it is here
     * so the other entry point can reach it (lib/PaymentWebhook.php).
     */
    public static function recordCharge(array $tx): array
    {
        $ref = (string) ($tx['reference'] ?? '');
        if ($ref === '') return ['ok' => false, 'error' => 'no reference'];
        $amount   = ((float) ($tx['amount'] ?? 0)) / 100;
        $currency = strtoupper((string) ($tx['currency'] ?? 'NGN'));
        $email    = (string) ($tx['customer']['email'] ?? '');
        $meta     = is_array($tx['metadata'] ?? null) ? $tx['metadata'] : [];
        $fields   = [];
        foreach (($meta['custom_fields'] ?? []) as $cf) $fields[$cf['variable_name'] ?? ''] = $cf['value'] ?? '';

        /* The campaign key arrives three ways and all three are real: a
           Paystack custom_field (the donate form), a flat metadata key (an
           appeal's recurring gift, where we set the metadata), or not at all
           on a RENEWAL — Paystack does not carry our metadata onto later
           charges in a subscription. Reading only custom_fields credited every
           recurring gift to the general fund, which is the failure nobody
           notices until a donor asks why the appeal they fund monthly is at
           zero. */
        $campaign = (string) ($fields['campaign'] ?? '');
        if ($campaign === '') $campaign = (string) ($meta['campaign'] ?? $meta['appeal'] ?? '');
        $planCode = self::planCode($tx);
        if ($campaign === '' && $planCode !== '') $campaign = (string) self::appealForPlan($planCode);
        $campaign = (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($campaign !== '' ? $campaign : 'general'));

        $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        /* THE WALL IS OPT-IN, and the consent is a field of its own.
           `anonymous` was the old opt-out, so its absence meant "publish me"
           — and a gateway charge with no donate form behind it carries no
           fields at all, which is how a dues payer or a course buyer came to
           be on a public donor wall under their own name. `wall` is only
           ever set by the donate form, by somebody who ticked it. */
        $listed = ($fields['wall'] ?? ($meta['wall'] ?? 'No')) === 'Yes';
        $anon = !$listed;
        $fn = trim((string) ($fields['first_name'] ?? ''));
        $ln = trim((string) ($fields['last_name'] ?? ''));
        /* A charge with no name on the form (a renewal, a dues or course
           payment on the same account) used to fall back to the payer's
           EMAIL here. Paystack's own customer name next, then "Supporter".
           It only reaches the wall if `listed` is true in any case. */
        if ($fn === '' && $ln === '') {
            $fn = trim((string) ($tx['customer']['first_name'] ?? ''));
            $ln = trim((string) ($tx['customer']['last_name'] ?? ''));
        }
        $entry = [
            'id'         => $ref,
            'type'       => 'card',
            'reference'  => $ref,
            'name'       => $anon ? 'Anonymous' : $esc(trim("$fn $ln") ?: 'Supporter'),
            'initials'   => $anon ? 'AN' : (strtoupper(substr($fn, 0, 1) . substr($ln, 0, 1)) ?: 'S'),
            'email'      => $email,
            'anonymous'  => $anon,
            'listed'     => $listed,
            'amount'     => $amount,
            'currency'   => $currency,
            'campaign'   => $campaign,
            'frequency'  => $esc($fields['frequency'] ?? ($planCode !== '' ? 'Recurring' : 'One-time')),
            'status'     => 'confirmed',
            'created_at' => date('c'),
        ];
        $isNew = self::storeIfNew($ref, $entry, $amount, $campaign, $currency, ['name' => trim("$fn $ln")]);
        /* Count the cycle only when the donation itself was new. A retried
           webhook must not inflate somebody's recorded giving. */
        if ($isNew && class_exists('Appeals')) {
            $subCode = (string) ($tx['subscription_code'] ?? $meta['subscription_code'] ?? '');
            if ($subCode !== '') Appeals::noteRecurringCharge($subCode, (int) round($amount));
        }
        return ['ok' => true, 'new' => $isNew, 'campaign' => $campaign, 'amount' => $amount];
    }

    /** Paystack reports the plan as an object on some events and a code on others. */
    public static function planCode(array $tx): string
    {
        $p = $tx['plan'] ?? ($tx['plan_object'] ?? '');
        if (is_array($p)) return (string) ($p['plan_code'] ?? '');
        return (string) $p;
    }

    /** The appeal slug a plan belongs to, or '' — a renewal carries no metadata. */
    public static function appealForPlan(string $planCode): string
    {
        if ($planCode === '') return '';
        if (class_exists('Database') && method_exists('Database', 'tableExists') && !Database::tableExists('av_appeal_plans')) return '';
        try {
            $st = Database::pdo()->prepare('SELECT a.slug FROM av_appeal_plans p JOIN av_appeals a ON a.id = p.appeal_id WHERE p.plan_code = ?');
            $st->execute([$planCode]);
            return (string) ($st->fetchColumn() ?: '');
        } catch (Throwable $e) { error_log('[donations] plan→appeal: ' . $e->getMessage()); return ''; }
    }

    /**
     * The donation receipt: one template and one set of fields for every road a
     * gift arrives by (card, the webhook, a verified bank transfer), sent from the
     * donations@ mailbox. Display-ready strings; the template escapes.
     */
    public static function receiptSpec(array $d): array
    {
        $sym = (string) ($d['currency'] ?? 'NGN');
        return ['donation_receipt', [
            'first_name'  => trim((string) ($d['firstName'] ?? '')) ?: 'friend',
            'amount'      => ($sym === 'NGN' ? '₦' : $sym . ' ') . number_format((float) ($d['amount'] ?? 0), 2),
            'receipt_no'  => (string) ($d['reference'] ?? '—'),
            'paid_on'     => date('j M Y'),
            'method'      => (string) ($d['method'] ?? 'Card · Paystack'),
            'designation' => (string) ($d['campaign'] ?? 'Where most needed'),
            'receipt_url' => 'https://afrovanguard.org.ng/give/receipt/' . rawurlencode((string) ($d['reference'] ?? '')),
        ]];
    }

    public static function sendReceipt(string $to, array $d): bool
    {
        if (defined('ENABLE_EMAIL_NOTIFICATIONS') && !ENABLE_EMAIL_NOTIFICATIONS) return true;
        if (!class_exists('Mailer') || !method_exists('Mailer', 'sendTemplate')) return false;
        $from = defined('DONATIONS_FROM_EMAIL') && DONATIONS_FROM_EMAIL !== '' ? DONATIONS_FROM_EMAIL : 'donations@afrovanguard.org.ng';
        $fromName = defined('DONATIONS_FROM_NAME') && DONATIONS_FROM_NAME !== '' ? DONATIONS_FROM_NAME : (defined('FROM_NAME') ? FROM_NAME : 'Afrovanguard');
        [$tpl, $vars] = self::receiptSpec($d);
        $ok = Mailer::sendTemplate($to, $tpl, $vars, ['from' => $from, 'fromName' => $fromName, 'replyTo' => $from]);
        if (!$ok) error_log('[donations] receipt to ' . $to . ': ' . (method_exists('Mailer', 'lastError') ? Mailer::lastError() : 'send failed'));
        return $ok;
    }
}
