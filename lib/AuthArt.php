<?php
/**
 * lib/AuthArt.php — admin-managed sign-in illustrations (with scheduling).
 *
 * Admins upload illustrations in the Studio and give each one a schedule:
 *   - always : shown year-round (the default rotation pool)
 *   - range  : shown between two calendar dates (one-off, e.g. a campaign)
 *   - annual : shown every year in an MM-DD window (holidays; supports wrap,
 *              e.g. 12-24 → 01-02 for the festive season)
 *
 * The /login page calls av_auth_art_active_today(): if any scheduled
 * (range/annual) art matches today it wins; otherwise the "always" pool is
 * used. av_auth_illustration() (lib/partials.php) then picks one, stable per
 * visit. With no DB art at all it falls back to assets/illustrations/auth/.
 */
declare(strict_types=1);

function av_auth_art_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS auth_illustrations (
           id            INTEGER PRIMARY KEY AUTOINCREMENT,
           label         TEXT NOT NULL DEFAULT '',
           image_url     TEXT NOT NULL,
           active        INTEGER NOT NULL DEFAULT 1,
           schedule_kind TEXT NOT NULL DEFAULT 'always',   -- always | range | annual
           start_date    TEXT,        -- YYYY-MM-DD (range)
           end_date      TEXT,        -- YYYY-MM-DD (range)
           start_md      TEXT,        -- MM-DD (annual)
           end_md        TEXT,        -- MM-DD (annual)
           sort          INTEGER NOT NULL DEFAULT 0,
           created_at    TEXT NOT NULL DEFAULT (datetime('now'))
         );
         CREATE INDEX IF NOT EXISTS idx_auth_art_active ON auth_illustrations(active, schedule_kind);"
    );
    $done = true;
}

/** True if MM-DD $md falls within [$start,$end], supporting a year wrap. */
function av_md_in_window(string $md, ?string $start, ?string $end): bool
{
    if (!$start || !$end) return false;
    return ($start <= $end) ? ($md >= $start && $md <= $end) : ($md >= $start || $md <= $end);
}

/** Full list for the admin Studio. */
function av_auth_art_all(PDO $pdo): array
{
    av_auth_art_ensure($pdo);
    return $pdo->query("SELECT * FROM auth_illustrations ORDER BY active DESC, sort, id DESC")->fetchAll();
}

/**
 * Active art that applies today. Any matching scheduled (range/annual) art
 * takes precedence; otherwise the "always" pool. Returns DB rows.
 */
function av_auth_art_active_today(PDO $pdo): array
{
    av_auth_art_ensure($pdo);
    $rows = $pdo->query("SELECT * FROM auth_illustrations WHERE active = 1 ORDER BY sort, id")->fetchAll();
    $today = date('Y-m-d');
    $md    = date('m-d');
    $scheduled = [];
    $always    = [];
    foreach ($rows as $r) {
        switch ($r['schedule_kind']) {
            case 'range':
                if ($r['start_date'] && $r['end_date'] && $today >= $r['start_date'] && $today <= $r['end_date']) $scheduled[] = $r;
                break;
            case 'annual':
                if (av_md_in_window($md, $r['start_md'], $r['end_md'])) $scheduled[] = $r;
                break;
            default: // always
                $always[] = $r;
        }
    }
    return $scheduled ?: $always;
}

/** Create or update an illustration. Returns its id. */
function av_auth_art_save(PDO $pdo, array $in): int
{
    av_auth_art_ensure($pdo);
    $kind = in_array(($in['schedule_kind'] ?? 'always'), ['always', 'range', 'annual'], true) ? $in['schedule_kind'] : 'always';
    $md = static fn($v) => preg_match('/^\d{2}-\d{2}$/', (string) $v) ? (string) $v : null;
    $d  = static fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : null;
    $fields = [
        'label'         => trim((string) ($in['label'] ?? '')),
        'image_url'     => trim((string) ($in['image_url'] ?? '')),
        'active'        => !empty($in['active']) ? 1 : 0,
        'schedule_kind' => $kind,
        'start_date'    => $kind === 'range'  ? $d($in['start_date'] ?? '') : null,
        'end_date'      => $kind === 'range'  ? $d($in['end_date'] ?? '')   : null,
        'start_md'      => $kind === 'annual' ? $md($in['start_md'] ?? '')  : null,
        'end_md'        => $kind === 'annual' ? $md($in['end_md'] ?? '')    : null,
        'sort'          => (int) ($in['sort'] ?? 0),
    ];
    if (!empty($in['id'])) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        $pdo->prepare("UPDATE auth_illustrations SET $set WHERE id = :id")->execute($fields + ['id' => (int) $in['id']]);
        return (int) $in['id'];
    }
    $cols = implode(',', array_keys($fields));
    $ph   = implode(',', array_map(fn($k) => ":$k", array_keys($fields)));
    $pdo->prepare("INSERT INTO auth_illustrations ($cols) VALUES ($ph)")->execute($fields);
    return (int) $pdo->lastInsertId();
}

function av_auth_art_delete(PDO $pdo, int $id): void
{
    av_auth_art_ensure($pdo);
    $pdo->prepare("DELETE FROM auth_illustrations WHERE id = ?")->execute([$id]);
}
