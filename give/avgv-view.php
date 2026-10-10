<?php
/**
 * give/avgv-view.php — what the /give/ page (row 9, prefix avgv-) reads and how it orders it.
 *
 * The data is the same as give/index.php v1 read: Appeals::published, ::summary,
 * ::currentNeedsAll, ::needsTotal and the standing catalogue (appeal_id 0).
 * What is new is that a database failure is caught here and reported as a state,
 * so the page can say "we couldn't load the appeals" instead of a blank 500.
 */
declare(strict_types=1);

/** Everything the page shows, or the empty shape plus error=true. */
function avgv_load(): array
{
    $empty = [
        'error' => false, 'appeals' => [], 'summary' => ['appeals' => 0, 'raised' => 0, 'goal' => 0, 'donors' => 0],
        'needs' => [], 'need_total' => ['count' => 0, 'today' => 0, 'week' => 0],
        'item_cats' => [], 'item_sum' => ['total' => 0, 'open' => 0, 'covered' => 0, 'outstanding_ngn' => 0, 'goods_left' => 0],
    ];
    try {
        /* Appeals' readers swallow their own failures and return []. Ask the store once,
           directly, so "the database is down" reads as an error, not as "nothing to give to". */
        Appeals::ensure();
        Database::pdo()->query('SELECT COUNT(*) FROM av_appeals')->fetchColumn();
        return [
            'error'      => false,
            'appeals'    => avgv_sort(Appeals::published(120)),
            'summary'    => Appeals::summary(),
            'needs'      => Appeals::currentNeedsAll(6),
            'need_total' => Appeals::needsTotal(),
            /* Standing items (appeal_id 0) are the organisation's own running needs. */
            'item_cats'  => Appeals::itemsByCategory(['appeal_id' => 0, 'limit' => 120]),
            'item_sum'   => Appeals::itemsSummary(['appeal_id' => 0, 'limit' => 120]),
        ];
    } catch (Throwable $e) {
        error_log('[give] index load failed: ' . $e->getMessage());
        return ['error' => true] + $empty;
    }
}

/**
 * Live first, then urgent, then ending soon, then the rest; featured first within a rank.
 * A funded or closed appeal stays on the page, at the bottom (v1 order, unchanged).
 */
function avgv_sort(array $appeals): array
{
    $rank = static function (array $a): int {
        if ((string) ($a['status'] ?? '') !== 'live') return 3;
        if (!empty($a['urgent'])) return 0;
        $s = Appeals::state($a);
        if ($s['ending_soon'] && !$s['ended']) return 1;
        return 2;
    };
    usort($appeals, static function (array $x, array $y) use ($rank): int {
        $r = $rank($x) <=> $rank($y);
        return $r !== 0 ? $r : ((int) ($y['featured'] ?? 0)) <=> ((int) ($x['featured'] ?? 0));
    });
    return $appeals;
}

/** The small label above an appeal tile. */
function avgv_kicker(array $a, array $st): string
{
    if ((string) ($a['status'] ?? '') === 'funded') return 'Funded';
    if (!empty($a['urgent'])) return 'Urgent';
    if (!empty($st['ending_soon']) && empty($st['ended'])) {
        $d = (int) $st['days_left'];
        return $d === 0 ? 'Last day' : ($d === 1 ? '1 day left' : $d . ' days left');
    }
    return ucfirst((string) ($a['kind'] ?? 'Appeal')) ?: 'Appeal';
}

/** The needs-board label for a cadence. */
function avgv_need_when(string $cadence): string
{
    return $cadence === 'daily' ? 'Today' : ($cadence === 'weekly' ? 'This week' : 'Still needed');
}

/** A width for a progress bar: 0–100, never negative, never past full. */
function avgv_pct($p): int
{
    return max(0, min(100, (int) ($p ?? 0)));
}

/** The line under the lead appeal's figures: donors and time left, whichever exist. */
function avgv_lead_meta(array $st): string
{
    $bits = [];
    if ((int) ($st['donors'] ?? 0) > 0) $bits[] = number_format((int) $st['donors']) . ((int) $st['donors'] === 1 ? ' donor' : ' donors');
    $d = $st['days_left'] ?? null;
    if ($d !== null && empty($st['ended'])) $bits[] = $d === 0 ? 'last day' : ($d === 1 ? '1 day left' : $d . ' days left');
    return implode(' · ', $bits);
}
