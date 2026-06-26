<?php
/**
 * lib/workspace.php — the Google Workspace launchpad for @org members.
 *
 * The site doesn't reinvent chat/mail/files — it runs *on* Google Workspace and
 * makes the member portal the single sign-on launchpad into it. Members arrive
 * already authenticated via Google SSO (lib/GoogleAuth.php), so these deep links
 * land them straight in the org's own Gmail / Calendar / Drive / Chat / Meet /
 * Groups with no extra login.
 *
 * Everything is config-driven and degrades gracefully:
 *   - Tool links are auto-derived from the org domain (AV_ORG_DOMAIN) using
 *     Google's `/a/<domain>/` convention, so they target the ORG instance.
 *     Any link can be overridden with an env var (AV_WS_MAIL_URL, …).
 *   - Communities (Google Chat Spaces / Groups) are an optional list supplied as
 *     JSON in AV_WS_COMMUNITIES; absent ⇒ the Communities section is simply hidden.
 *
 * No new tables, no realtime backend — a perfect fit for the shared-hosting stack.
 */
declare(strict_types=1);

/** The Workspace domain whose accounts these links target. */
function av_workspace_domain(): string
{
    $d = defined('AV_ORG_DOMAIN') ? trim((string) AV_ORG_DOMAIN) : '';
    return $d !== '' ? $d : 'afrovanguard.org.ng';
}

/** First non-empty of an env override or the derived default. */
function av_ws_link(string $envKey, string $default): string
{
    $v = getenv($envKey);
    return ($v !== false && trim($v) !== '') ? trim($v) : $default;
}

/**
 * The Workspace tool tiles for the hub. Auto-derived from the org domain; each
 * link is overridable via env. `admin` is appended only for Workspace admins.
 */
function av_workspace_surfaces(bool $isAdmin = false): array
{
    $d = av_workspace_domain();
    $s = [
        ['key' => 'mail',     'label' => 'Gmail',    'desc' => 'Your @' . $d . ' inbox',      'icon' => 'mail',     'url' => av_ws_link('AV_WS_MAIL_URL',     "https://mail.google.com/a/$d")],
        ['key' => 'chat',     'label' => 'Chat',     'desc' => 'Team chat & spaces',          'icon' => 'chat',     'url' => av_ws_link('AV_WS_CHAT_URL',     'https://chat.google.com')],
        ['key' => 'meet',     'label' => 'Meet',     'desc' => 'Start or join a video call',  'icon' => 'meet',     'url' => av_ws_link('AV_WS_MEET_URL',     'https://meet.google.com')],
        ['key' => 'calendar', 'label' => 'Calendar', 'desc' => 'Team schedule & events',      'icon' => 'calendar', 'url' => av_ws_link('AV_WS_CALENDAR_URL', "https://calendar.google.com/a/$d")],
        ['key' => 'drive',    'label' => 'Drive',    'desc' => 'Shared files & documents',    'icon' => 'drive',    'url' => av_ws_link('AV_WS_DRIVE_URL',    "https://drive.google.com/a/$d")],
        ['key' => 'groups',   'label' => 'Groups',   'desc' => 'Mailing lists & communities', 'icon' => 'groups',   'url' => av_ws_link('AV_WS_GROUPS_URL',   "https://groups.google.com/a/$d")],
    ];
    if ($isAdmin) {
        $s[] = ['key' => 'admin', 'label' => 'Admin console', 'desc' => 'Manage the Workspace', 'icon' => 'admin', 'url' => av_ws_link('AV_WS_ADMIN_URL', 'https://admin.google.com')];
    }
    return $s;
}

/**
 * Communities (Google Chat Spaces / Groups) shown in the portal.
 * Source of truth: the admin-managed `communities` table (Studio → Communities).
 * If none are configured there, falls back to the optional AV_WS_COMMUNITIES env
 * JSON. Only https links are surfaced. Absent everywhere ⇒ [] (section hidden).
 */
function av_workspace_communities(): array
{
    if (class_exists('Database')) {
        try {
            $pdo = Database::pdo();
            av_communities_ensure($pdo);
            $rows = $pdo->query('SELECT name, description, url FROM communities WHERE enabled = 1 ORDER BY sort ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $url = (string) $r['url'];
                if (!preg_match('#^https://#i', $url)) continue;
                $out[] = ['name' => (string) $r['name'], 'desc' => (string) $r['description'], 'url' => $url];
            }
            if ($out) return $out;
        } catch (Throwable $e) {
            error_log('[workspace] communities table read failed: ' . $e->getMessage());
        }
    }
    return av_ws_communities_env();
}

/** Backward-compatible env-JSON community list (used when none are admin-managed). */
function av_ws_communities_env(): array
{
    $raw = getenv('AV_WS_COMMUNITIES');
    if ($raw === false || trim($raw) === '') return [];
    $list = json_decode($raw, true);
    if (!is_array($list)) return [];
    $out = [];
    foreach ($list as $c) {
        if (!is_array($c) || empty($c['name']) || empty($c['url'])) continue;
        $url = (string) $c['url'];
        if (!preg_match('#^https://#i', $url)) continue;
        $out[] = [
            'name' => mb_substr((string) $c['name'], 0, 80),
            'desc' => mb_substr((string) ($c['desc'] ?? ''), 0, 160),
            'url'  => $url,
        ];
    }
    return $out;
}

/* ── Admin-managed communities (Studio → Communities) ──────────────────────
   A small table, created on first use on every engine (driver-aware DDL via
   translateDDL); SQLite output is byte-identical. No reserved-word columns. */
function av_communities_ensure(PDO $pdo): void
{
    static $done = false; if ($done) return; $done = true;
    $ddl = "CREATE TABLE IF NOT EXISTS communities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(191) NOT NULL DEFAULT '',
        description VARCHAR(500) NOT NULL DEFAULT '',
        url VARCHAR(500) NOT NULL DEFAULT '',
        sort INTEGER NOT NULL DEFAULT 0,
        enabled INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )";
    $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $pdo->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
}

/** All communities (admin view, any enabled state). */
function av_communities_all(PDO $pdo): array
{
    av_communities_ensure($pdo);
    return $pdo->query('SELECT * FROM communities ORDER BY sort ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Insert/update a community; returns its id. Caller validates name + https url. */
function av_communities_save(PDO $pdo, array $in): int
{
    av_communities_ensure($pdo);
    $f = [
        'name'        => mb_substr(trim((string) ($in['name'] ?? '')), 0, 180),
        'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 480),
        'url'         => trim((string) ($in['url'] ?? '')),
        'sort'        => (int) ($in['sort'] ?? 0),
        'enabled'     => isset($in['enabled']) ? (!empty($in['enabled']) ? 1 : 0) : 1,
    ];
    $id = (int) ($in['id'] ?? 0);
    if ($id > 0) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($f)));
        $pdo->prepare("UPDATE communities SET $set WHERE id = :id")->execute($f + ['id' => $id]);
        return $id;
    }
    $cols = implode(', ', array_keys($f));
    $ph   = implode(', ', array_map(fn($k) => ":$k", array_keys($f)));
    $pdo->prepare("INSERT INTO communities ($cols) VALUES ($ph)")->execute($f);
    return (int) $pdo->lastInsertId();
}

function av_communities_delete(PDO $pdo, int $id): void
{
    av_communities_ensure($pdo);
    $pdo->prepare('DELETE FROM communities WHERE id = ?')->execute([$id]);
}

/**
 * Optional in-portal embeds (read-only). Both Google Calendar and Drive folder
 * views allow iframing (unlike Chat/Mail). Configured via env; absent ⇒ omitted.
 *   AV_WS_CALENDAR_EMBED  full https://calendar.google.com/... embed src, OR
 *   AV_WS_CALENDAR_ID     a calendar address (built into an AGENDA embed)
 *   AV_WS_TZ              IANA tz for the calendar (default Africa/Lagos)
 *   AV_WS_DRIVE_FOLDER_ID a Drive folder id shared "anyone with the link"
 */
function av_workspace_embeds(): array
{
    $out = [];
    $calEmbed = trim((string) getenv('AV_WS_CALENDAR_EMBED'));
    $calId    = trim((string) getenv('AV_WS_CALENDAR_ID'));
    if ($calEmbed !== '' && preg_match('#^https://calendar\.google\.com/#i', $calEmbed)) {
        $out['calendar'] = $calEmbed;
    } elseif ($calId !== '') {
        $out['calendar'] = 'https://calendar.google.com/calendar/embed?' . http_build_query([
            'src'       => $calId,
            'ctz'       => (getenv('AV_WS_TZ') ?: 'Africa/Lagos'),
            'mode'      => 'AGENDA',
            'showTitle' => 0,
            'showPrint' => 0,
            'showTabs'  => 0,
        ]);
    }
    $folder = trim((string) getenv('AV_WS_DRIVE_FOLDER_ID'));
    if ($folder !== '' && preg_match('/^[A-Za-z0-9_-]{10,}$/', $folder)) {
        $out['drive'] = 'https://drive.google.com/embeddedfolderview?id=' . rawurlencode($folder) . '#grid';
    }
    return $out;
}

/** Inline line-icon for a Workspace surface (trusted static SVG, stroke = currentColor). */
function av_workspace_icon(string $key): string
{
    $paths = [
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'chat'     => '<path d="M21 11.5a8.38 8.38 0 01-9 8.3 8.5 8.5 0 01-3.8-.9L3 20l1.1-4.2A8.38 8.38 0 013 11.5 8.5 8.5 0 0112 3a8.38 8.38 0 019 8.5z"/>',
        'meet'     => '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="M16 10l5-3v10l-5-3z"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
        'drive'    => '<path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>',
        'groups'   => '<path d="M17 20v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9.5" cy="7" r="3.5"/><path d="M22 20v-2a4 4 0 00-3-3.87M16 3.13A4 4 0 0118 7"/>',
        'admin'    => '<path d="M12 2l8 4v6c0 5-3.4 8.3-8 10-4.6-1.7-8-5-8-10V6z"/>',
    ];
    $p = $paths[$key] ?? '<circle cx="12" cy="12" r="9"/>';
    return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}
