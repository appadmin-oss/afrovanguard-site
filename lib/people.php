<?php
/**
 * lib/people.php — native Team / People directory (replaces the old Google
 * Apps Script proxy). Backs the About people section, the /people directory,
 * Volunteer of the Month, and birthday celebrations. Managed in the Studio
 * (admin) and served as JSON by api.php.
 */
declare(strict_types=1);

const AV_TEAM_TIERS = ['management', 'director', 'patron', 'ngv', 'ngg', 'volunteer'];
const AV_TEAM_LEAD_TIERS = ['management', 'director'];
const AV_TIER_LABELS = ['management' => 'Management', 'director' => 'Director', 'patron' => 'Patron', 'ngv' => 'NGV', 'ngg' => 'NGG', 'volunteer' => 'Volunteer'];

/** URL slug + canonical profile URL for a member dict. */
function av_person_slug(array $m): string {
    $s = strtolower(trim((string) ($m['name'] ?? '')));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string) $s, '-');
}
function av_person_url(array $m): string {
    return '/people/' . (int) $m['id'] . '-' . av_person_slug($m) . '/';
}
function av_tier_label(string $t): string { return AV_TIER_LABELS[$t] ?? ucfirst($t); }

/** Create the team table if missing (idempotent; safe on deployed DBs). */
function av_team_ensure(PDO $pdo): void
{
    $ddl = "CREATE TABLE IF NOT EXISTS team (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT '',
        tier TEXT NOT NULL DEFAULT 'volunteer',
        featured INTEGER NOT NULL DEFAULT 0,
        operations INTEGER NOT NULL DEFAULT 0,
        tagline TEXT NOT NULL DEFAULT '',
        bio TEXT NOT NULL DEFAULT '',
        location TEXT NOT NULL DEFAULT '',
        photo_url TEXT NOT NULL DEFAULT '',
        socials TEXT NOT NULL DEFAULT '',
        birthday TEXT NOT NULL DEFAULT '',
        votm_month TEXT NOT NULL DEFAULT '',
        votm_reason TEXT NOT NULL DEFAULT '',
        votm_quote TEXT NOT NULL DEFAULT '',
        position INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )";
    $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $pdo->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
    // Columns added after the initial release (idempotent — ADD COLUMN errors if
    // it already exists, which we swallow). `email` powers birthday emails.
    try { $pdo->exec("ALTER TABLE team ADD COLUMN email TEXT NOT NULL DEFAULT ''"); }
    catch (\Throwable $e) { /* column already present */ }
}

/** Normalise/validate a tier string. */
function av_team_tier(string $t): string
{
    $t = strtolower(trim($t));
    return in_array($t, AV_TEAM_TIERS, true) ? $t : 'volunteer';
}

/** A DB row → the public member shape consumed by the front-end. */
function av_team_member_dict(array $r): array
{
    $socials = [];
    if (!empty($r['socials'])) {
        $d = json_decode((string) $r['socials'], true);
        if (is_array($d)) {
            foreach ($d as $k => $v) { if (is_string($v) && trim($v) !== '') $socials[$k] = trim($v); }
        }
    }
    return [
        'id'         => (int) $r['id'],
        'name'       => (string) $r['name'],
        'role'       => (string) $r['role'],
        'tier'       => av_team_tier((string) $r['tier']),
        'featured'   => (bool) (int) $r['featured'],
        'operations' => (bool) (int) $r['operations'],
        'tagline'    => (string) $r['tagline'],
        'bio'        => (string) $r['bio'],
        'location'   => (string) $r['location'],
        'photo'      => (string) $r['photo_url'],
        'birthday'   => (string) $r['birthday'],
        'email'      => (string) ($r['email'] ?? ''),
        'socials'    => $socials,
    ];
}

/** All rows (optionally active-only), ordered for display. */
function av_team_rows(PDO $pdo, bool $activeOnly = true): array
{
    av_team_ensure($pdo);
    $sql = 'SELECT * FROM team' . ($activeOnly ? ' WHERE active = 1' : '')
         . ' ORDER BY position ASC, featured DESC, id ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Public members payload (matches the legacy api.php contract). */
function av_team_members(PDO $pdo): array
{
    return ['status' => 'ok', 'members' => array_map('av_team_member_dict', av_team_rows($pdo, true))];
}

/** A single member by id (full profile). */
function av_team_one(PDO $pdo, int $id): array
{
    av_team_ensure($pdo);
    $s = $pdo->prepare('SELECT * FROM team WHERE id = ? AND active = 1');
    $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['status' => 'error', 'error' => 'Not found'];
    return ['status' => 'ok', 'member' => av_team_member_dict($r)];
}

/** Current Volunteer of the Month: this month's pick, else the most recent. */
function av_votm(PDO $pdo, ?string $month = null): array
{
    av_team_ensure($pdo);
    $month = $month ?: date('Y-m');
    $s = $pdo->prepare("SELECT * FROM team WHERE active = 1 AND votm_month <> '' AND votm_month <= ? ORDER BY votm_month DESC, id DESC LIMIT 1");
    $s->execute([$month]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['status' => 'ok', 'votm' => null];
    [$yy, $mm] = array_pad(explode('-', (string) $r['votm_month']), 2, '');
    $m = av_team_member_dict($r);
    return ['status' => 'ok', 'votm' => [
        'id'       => $m['id'],
        'name'     => $m['name'],
        'role'     => $m['role'],
        'photo'    => $m['photo'],
        'location' => $m['location'],
        'month'    => (int) ($mm ?: date('n')),
        'year'     => (int) ($yy ?: date('Y')),
        'reason'   => (string) $r['votm_reason'],
        'quote'    => (string) $r['votm_quote'],
    ]];
}

/** Active people whose birthday (MM-DD) matches the given date (default today). */
function av_birthdays_on(PDO $pdo, ?string $mmdd = null): array
{
    av_team_ensure($pdo);
    $mmdd = $mmdd ?: date('m-d');
    $s = $pdo->prepare("SELECT * FROM team WHERE active = 1 AND birthday = ?");
    $s->execute([$mmdd]);
    return array_map('av_team_member_dict', $s->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/**
 * Send each of today's birthday people a personalised birthday email — once
 * per person per day, idempotently. Safe to call from cron AND lazily from a
 * page request: a per-day "sent" ledger in app_meta guarantees no duplicates
 * even if both fire. Returns the number of emails actually sent this call.
 */
function av_birthday_emails_run(PDO $pdo): int
{
    $people = av_birthdays_on($pdo);
    if (!$people) return 0;

    $today = date('Y-m-d');
    $sent = [];
    try {
        $raw = class_exists('Database') ? Database::metaGet('bday_sent') : null;
        $d = $raw ? json_decode($raw, true) : null;
        if (is_array($d) && ($d['date'] ?? '') === $today && is_array($d['ids'] ?? null)) {
            $sent = array_map('intval', $d['ids']);
        }
    } catch (\Throwable $e) { /* start fresh */ }

    $n = 0;
    foreach ($people as $p) {
        $id = (int) ($p['id'] ?? 0);
        $email = trim((string) ($p['email'] ?? ''));
        if ($id <= 0 || $email === '' || in_array($id, $sent, true)) continue;
        if (class_exists('Notify') && method_exists('Notify', 'birthday')) {
            Notify::birthday($p);         // best-effort; Notify swallows failures
        }
        $sent[] = $id; $n++;
    }
    if ($n > 0) {
        try { Database::metaSet('bday_sent', json_encode(['date' => $today, 'ids' => array_values(array_unique($sent))])); }
        catch (\Throwable $e) { /* best-effort */ }
    }
    return $n;
}

/** Persist a member (insert or update). Returns the id. */
function av_team_save(PDO $pdo, array $in): int
{
    av_team_ensure($pdo);
    $socials = is_array($in['socials'] ?? null) ? json_encode($in['socials']) : (string) ($in['socials'] ?? '');
    $fields = [
        'name' => trim((string) ($in['name'] ?? '')),
        'role' => trim((string) ($in['role'] ?? '')),
        'tier' => av_team_tier((string) ($in['tier'] ?? 'volunteer')),
        'featured' => !empty($in['featured']) ? 1 : 0,
        'operations' => !empty($in['operations']) ? 1 : 0,
        'tagline' => trim((string) ($in['tagline'] ?? '')),
        'bio' => trim((string) ($in['bio'] ?? '')),
        'location' => trim((string) ($in['location'] ?? '')),
        'photo_url' => trim((string) ($in['photo'] ?? $in['photo_url'] ?? '')),
        'socials' => $socials,
        'email' => filter_var(trim((string) ($in['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
        'birthday' => preg_match('/^\d{2}-\d{2}$/', (string) ($in['birthday'] ?? '')) ? $in['birthday'] : '',
        'votm_month' => preg_match('/^\d{4}-\d{2}$/', (string) ($in['votm_month'] ?? '')) ? $in['votm_month'] : '',
        'votm_reason' => trim((string) ($in['votm_reason'] ?? '')),
        'votm_quote' => trim((string) ($in['votm_quote'] ?? '')),
        'position' => (int) ($in['position'] ?? 0),
        'active' => isset($in['active']) ? (!empty($in['active']) ? 1 : 0) : 1,
    ];
    $id = (int) ($in['id'] ?? 0);
    if ($id > 0) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        $stmt = $pdo->prepare("UPDATE team SET $set WHERE id = :id");
        $stmt->execute($fields + ['id' => $id]);
        return $id;
    }
    $cols = implode(', ', array_keys($fields));
    $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
    $pdo->prepare("INSERT INTO team ($cols) VALUES ($ph)")->execute($fields);
    return (int) $pdo->lastInsertId();
}

function av_team_delete(PDO $pdo, int $id): void
{
    av_team_ensure($pdo);
    $pdo->prepare('DELETE FROM team WHERE id = ?')->execute([$id]);
}
