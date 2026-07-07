<?php
/**
 * lib/Donations.php — admin-side repository over the donation ledger.
 *
 * The PUBLIC money path (card / virtual account / webhook / in-kind) lives in
 * process-donation.php and stores records in donations.json (file-locked, not
 * web-served). This class gives the Studio a managed view over the same file:
 * stats, filtered listing, CSV export, manual (offline) gift entry, status
 * changes (confirm / void) and campaign goal edits — all under the same
 * LOCK_EX read-modify-write discipline so the two writers never corrupt
 * each other.
 *
 * Ledger invariants (mirrors storeDonationIfNew in process-donation.php):
 *  - `donations` keeps the most recent 500 entries; `totals` / `campaigns`
 *    are INCREMENTAL and include history beyond that cap — so totals are
 *    adjusted delta-wise, never recomputed from the visible list.
 *  - Every stored donation counts one donor (in-kind included); only
 *    confirmed NGN monetary amounts add to raised figures.
 */
declare(strict_types=1);

final class Donations
{
    public const MONETARY = ['card', 'bank_static', 'bank_va', 'cash'];

    private static function path(): string { return AV_ROOT . '/donations.json'; }

    private static function defaults(): array
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

    /** Read the ledger under a shared lock. Never throws. */
    public static function read(): array
    {
        $p = self::path();
        if (!is_file($p)) return self::defaults();
        $fp = @fopen($p, 'r');
        if (!$fp) return self::defaults();
        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN); fclose($fp);
        $d = json_decode((string) $raw, true);
        return (is_array($d) && !empty($d['campaigns'])) ? $d : self::defaults();
    }

    /**
     * Exclusive read-modify-write. $fn receives the decoded ledger and returns
     * the mutated ledger (or null to abort without writing). Returns the final
     * ledger. Same 'c+' + in-lock re-read pattern as the public processor.
     */
    private static function mutate(callable $fn): array
    {
        $fp = @fopen(self::path(), 'c+');
        if (!$fp) throw new RuntimeException('Cannot open donations.json for writing — check file permissions.');
        flock($fp, LOCK_EX);
        $raw  = stream_get_contents($fp) ?: '';
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['campaigns'])) $data = self::defaults();

        $out = $fn($data);
        if ($out === null) { flock($fp, LOCK_UN); fclose($fp); return $data; }

        $out['last_updated'] = date('c');
        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        ftruncate($fp, 0); rewind($fp); fwrite($fp, $json); fflush($fp);
        flock($fp, LOCK_UN); fclose($fp);
        return $out;
    }

    /* ── Reading ──────────────────────────────────────────────── */

    /** Studio dashboard stats: lifetime totals + this-month + per-type/status counts. */
    public static function stats(): array
    {
        $d = self::read();
        $month = date('Y-m');
        $mSum = 0.0; $mCount = 0; $pending = 0; $voided = 0; $inkind = 0; $monetary = 0;
        foreach ($d['donations'] as $r) {
            $st = (string) ($r['status'] ?? 'confirmed');
            if ($st === 'voided') { $voided++; continue; }
            if (($r['type'] ?? '') === 'inkind') $inkind++; else $monetary++;
            if ($st === 'pending') $pending++;
            if (strncmp((string) ($r['created_at'] ?? ''), $month, 7) === 0 && $st === 'confirmed'
                && ($r['currency'] ?? 'NGN') === 'NGN' && ($r['type'] ?? '') !== 'inkind') {
                $mSum += (float) ($r['amount'] ?? 0); $mCount++;
            }
        }
        return [
            'totals'     => $d['totals'],
            'campaigns'  => $d['campaigns'],
            'this_month' => ['raised_ngn' => $mSum, 'count' => $mCount],
            'recent'     => ['monetary' => $monetary, 'inkind' => $inkind, 'pending' => $pending, 'voided' => $voided,
                             'window' => count($d['donations'])],   // stats above cover the stored (≤500) window
            'updated_at' => (string) ($d['last_updated'] ?? ''),
        ];
    }

    /** Filtered, paginated admin listing (full records — admin eyes only). */
    public static function filtered(array $q): array
    {
        $d    = self::read();
        $text = strtolower(trim((string) ($q['q'] ?? '')));
        $type = (string) ($q['type'] ?? '');        // '' | monetary | inkind | card | bank_static | bank_va | cash
        $camp = (string) ($q['campaign'] ?? '');
        $stat = (string) ($q['status'] ?? '');      // '' | confirmed | pending | voided
        $rows = array_values(array_filter($d['donations'], function ($r) use ($text, $type, $camp, $stat) {
            if ($type === 'monetary' && !in_array($r['type'] ?? '', self::MONETARY, true)) return false;
            if ($type === 'inkind' && ($r['type'] ?? '') !== 'inkind') return false;
            if ($type !== '' && $type !== 'monetary' && $type !== 'inkind' && ($r['type'] ?? '') !== $type) return false;
            if ($camp !== '' && ($r['campaign'] ?? 'general') !== $camp) return false;
            if ($stat !== '' && ($r['status'] ?? 'confirmed') !== $stat) return false;
            if ($text !== '') {
                $hay = strtolower(($r['name'] ?? '') . ' ' . ($r['email'] ?? '') . ' ' . ($r['reference'] ?? '') . ' ' . ($r['note'] ?? ''));
                if (strpos($hay, $text) === false) return false;
            }
            return true;
        }));
        $total = count($rows);
        $per   = min(100, max(10, (int) ($q['per'] ?? 25)));
        $page  = max(0, (int) ($q['page'] ?? 0));
        return ['rows' => array_slice($rows, $page * $per, $per), 'total' => $total, 'page' => $page, 'per' => $per];
    }

    /** CSV of the filtered set (every matching row, paged internally). */
    public static function csv(array $q): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Date', 'Reference', 'Name', 'Email', 'Type', 'Amount', 'Currency', 'Campaign', 'Frequency', 'Status', 'Anonymous', 'Note']);
        $page = 0;
        do {
            $chunk = self::filtered(array_merge($q, ['per' => 100, 'page' => $page]));
            foreach ($chunk['rows'] as $r) {
                fputcsv($out, [
                    (string) ($r['created_at'] ?? ''), (string) ($r['reference'] ?? ''),
                    (string) ($r['name'] ?? ''), (string) ($r['email'] ?? ''),
                    (string) ($r['type'] ?? ''),
                    ($r['type'] ?? '') === 'inkind' ? (string) ($r['inkind_type'] ?? 'contribution') : (string) ($r['amount'] ?? '0'),
                    (string) ($r['currency'] ?? 'NGN'), (string) ($r['campaign'] ?? 'general'),
                    (string) ($r['frequency'] ?? 'One-time'), (string) ($r['status'] ?? 'confirmed'),
                    !empty($r['anonymous']) ? 'yes' : 'no', (string) ($r['note'] ?? ''),
                ]);
            }
            $page++;
        } while ($page * 100 < $chunk['total']);
        rewind($out);
        return (string) stream_get_contents($out);
    }

    /**
     * A member's own giving history (for the portal's "My giving" card).
     * Matches by email over the stored window; voided gifts excluded.
     */
    public static function forEmail(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') return ['rows' => [], 'count' => 0, 'total_ngn' => 0.0, 'inkind' => 0];
        $rows = []; $total = 0.0; $inkind = 0;
        foreach (self::read()['donations'] as $r) {
            if (strtolower((string) ($r['email'] ?? '')) !== $email) continue;
            if ((string) ($r['status'] ?? 'confirmed') === 'voided') continue;
            $rows[] = $r;
            if (($r['type'] ?? '') === 'inkind') $inkind++;
            elseif (($r['currency'] ?? 'NGN') === 'NGN') $total += (float) ($r['amount'] ?? 0);
        }
        return ['rows' => $rows, 'count' => count($rows), 'total_ngn' => $total, 'inkind' => $inkind];
    }

    /* ── Writing ──────────────────────────────────────────────── */

    /**
     * Record a MANUAL gift (offline bank transfer, cash, or in-kind) from the
     * Studio. Validates, generates a MAN- reference, and applies the same
     * totals increments as the public path. Returns the stored entry.
     */
    public static function add(array $in): array
    {
        $type = (string) ($in['type'] ?? 'bank_static');
        if (!in_array($type, ['bank_static', 'cash', 'inkind'], true)) {
            throw new InvalidArgumentException('Type must be bank transfer, cash, or in-kind.');
        }
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 100);
        if ($name === '') throw new InvalidArgumentException('A donor name is required (use "Anonymous" if needed).');
        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('That email address looks invalid.');
        $camp = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($in['campaign'] ?? 'general'))) ?: 'general';
        $currency = (string) ($in['currency'] ?? 'NGN');
        if (!in_array($currency, ['NGN', 'USD', 'GBP'], true)) $currency = 'NGN';
        $amount = 0.0; $inkindType = '';
        if ($type === 'inkind') {
            $inkindType = mb_substr(trim((string) ($in['inkind_type'] ?? 'Contribution')), 0, 60) ?: 'Contribution';
        } else {
            $amount = round((float) ($in['amount'] ?? 0), 2);
            if ($amount <= 0) throw new InvalidArgumentException('Enter the gift amount.');
            if ($amount > 100000000) throw new InvalidArgumentException('Amount exceeds the single-gift maximum.');
        }
        $anon  = !empty($in['anonymous']);
        $parts = preg_split('/\s+/', $name) ?: [];
        $initials = $anon ? 'AN' : strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
        $ref = 'MAN' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

        $entry = [
            'id' => $ref, 'type' => $type, 'reference' => $ref,
            'name' => $anon ? 'Anonymous' : $name, 'initials' => $initials,
            'email' => $email, 'anonymous' => $anon,
            'amount' => $amount, 'currency' => $currency,
            'campaign' => $camp, 'frequency' => 'One-time',
            'status' => 'confirmed', 'created_at' => date('c'),
            'recorded_by' => 'studio',
            'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 300),
        ];
        if ($type === 'inkind') $entry['inkind_type'] = $inkindType;

        self::mutate(function (array $d) use ($entry, $ref, $amount, $camp, $currency, $type) {
            foreach ($d['donations'] as $r) { if (($r['reference'] ?? '') === $ref) return null; }
            array_unshift($d['donations'], $entry);
            if (count($d['donations']) > 500) $d['donations'] = array_slice($d['donations'], 0, 500);
            $key = isset($d['campaigns'][$camp]) ? $camp : 'general';
            $d['campaigns'][$key]['donors'] = ($d['campaigns'][$key]['donors'] ?? 0) + 1;
            if ($currency === 'NGN' && $type !== 'inkind') {
                $d['campaigns'][$key]['raised'] = ($d['campaigns'][$key]['raised'] ?? 0) + $amount;
                $d['totals']['raised_ngn']      = ($d['totals']['raised_ngn'] ?? 0) + $amount;
            }
            if ($type === 'inkind') $d['totals']['inkind'] = ($d['totals']['inkind'] ?? 0) + 1;
            $d['totals']['donors'] = ($d['totals']['donors'] ?? 0) + 1;
            return $d;
        });
        if (function_exists('av_emit_event')) {
            av_emit_event('donation.completed', [
                'reference' => $ref, 'amount' => $amount, 'currency' => $currency, 'campaign' => $camp,
                'name' => (string) $entry['name'], 'email' => $email, 'source' => 'studio-manual',
            ]);
        }
        return $entry;
    }

    /**
     * Change a donation's status: confirmed ⇄ voided (and pending → confirmed).
     * Adjusts the incremental totals by the exact delta so voiding a mistaken
     * or refunded gift keeps every figure truthful.
     */
    public static function setStatus(string $reference, string $status): array
    {
        if (!in_array($status, ['confirmed', 'voided'], true)) {
            throw new InvalidArgumentException('Status must be confirmed or voided.');
        }
        $updated = null;
        self::mutate(function (array $d) use ($reference, $status, &$updated) {
            foreach ($d['donations'] as $i => $r) {
                if (($r['reference'] ?? '') !== $reference) continue;
                $old = (string) ($r['status'] ?? 'confirmed');
                if ($old === $status) { $updated = $r; return null; }
                $counted    = $old !== 'voided';         // pending + confirmed both counted at store time
                $willCount  = $status !== 'voided';
                $amt  = (float) ($r['amount'] ?? 0);
                $ngn  = (($r['currency'] ?? 'NGN') === 'NGN') && (($r['type'] ?? '') !== 'inkind');
                $camp = (string) ($r['campaign'] ?? 'general');
                $key  = isset($d['campaigns'][$camp]) ? $camp : 'general';
                $sign = ($willCount ? 1 : 0) - ($counted ? 1 : 0);
                if ($sign !== 0) {
                    $d['campaigns'][$key]['donors'] = max(0, ($d['campaigns'][$key]['donors'] ?? 0) + $sign);
                    $d['totals']['donors']          = max(0, ($d['totals']['donors'] ?? 0) + $sign);
                    if ($ngn) {
                        $d['campaigns'][$key]['raised'] = max(0, ($d['campaigns'][$key]['raised'] ?? 0) + $sign * $amt);
                        $d['totals']['raised_ngn']      = max(0, ($d['totals']['raised_ngn'] ?? 0) + $sign * $amt);
                    }
                    if (($r['type'] ?? '') === 'inkind') $d['totals']['inkind'] = max(0, ($d['totals']['inkind'] ?? 0) + $sign);
                }
                $d['donations'][$i]['status'] = $status;
                $updated = $d['donations'][$i];
                return $d;
            }
            throw new InvalidArgumentException('No donation with that reference in the stored window.');
        });
        return $updated;
    }

    /** Update a campaign fundraising goal (NGN). Creates the campaign bucket if new. */
    public static function setGoal(string $campaign, int $goal): array
    {
        $camp = preg_replace('/[^a-z0-9_-]/', '', strtolower($campaign));
        if ($camp === '') throw new InvalidArgumentException('Campaign key is required.');
        if ($goal < 0 || $goal > 100000000000) throw new InvalidArgumentException('Goal out of range.');
        $d = self::mutate(function (array $d) use ($camp, $goal) {
            if (!isset($d['campaigns'][$camp])) $d['campaigns'][$camp] = ['raised' => 0, 'goal' => 0, 'donors' => 0];
            $d['campaigns'][$camp]['goal'] = $goal;
            return $d;
        });
        return $d['campaigns'][$camp];
    }
}
