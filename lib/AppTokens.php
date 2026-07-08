<?php
/**
 * lib/AppTokens.php — API tokens for integrations (the inbound side).
 *
 * Where Webhooks let Afrovanguard push events OUT, app tokens let trusted
 * apps/sites call IN: post to the community (including as the official
 * @Afrovanguard bot), emit events, or read the public feed — server-to-server,
 * authenticated by a Bearer token, scoped to exactly what each integration may
 * do. The plaintext token is shown ONCE at creation; only its SHA-256 hash is
 * stored, so a DB leak can't be replayed. Superadmin-managed in the Studio.
 */
declare(strict_types=1);

final class AppTokens
{
    /** The scopes an integration can hold. Keep in sync with the API + docs. */
    const SCOPES = [
        'community:read'  => 'Read the public community feed',
        'community:bot'   => 'Post & reply as the official Afrovanguard bot',
        'bot:ask'         => 'Ask the AI bot to generate a reply',
        'events:write'    => 'Emit events (fan out to webhooks/listeners)',
        'mentors:read'    => 'Read the approved mentor / volunteer directory (sister-site sync)',
    ];
    const PREFIX = 'av_int_';

    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $db = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS app_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(120) NOT NULL DEFAULT '',
            token_hash VARCHAR(64) NOT NULL,
            scopes VARCHAR(255) NOT NULL DEFAULT '',
            revoked INTEGER NOT NULL DEFAULT 0,
            last_used VARCHAR(32) NOT NULL DEFAULT '',
            created_ip VARCHAR(64) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_apptokens_hash ON app_tokens(token_hash);";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
        $done = true;
    }

    /** Keep only valid, known scopes. */
    private static function cleanScopes(array $scopes): string
    {
        $ok = array_values(array_intersect(array_keys(self::SCOPES), array_map('strval', $scopes)));
        return implode(' ', $ok);
    }

    /**
     * Mint a token. Returns ['id'=>int,'token'=>string,'scopes'=>string]; the
     * plaintext `token` is returned ONCE and never recoverable afterward.
     */
    public static function issue(string $name, array $scopes): array
    {
        self::ensure();
        $name  = trim($name) !== '' ? trim($name) : 'Integration';
        $token = self::PREFIX . bin2hex(random_bytes(20));
        $scopeStr = self::cleanScopes($scopes) ?: 'community:read';
        Database::pdo()->prepare('INSERT INTO app_tokens (name, token_hash, scopes, created_ip, created_at) VALUES (?,?,?,?,?)')
            ->execute([mb_substr($name, 0, 120), hash('sha256', $token), $scopeStr, av_client_ip(), gmdate('Y-m-d H:i:s')]);
        return ['id' => (int) Database::pdo()->lastInsertId(), 'token' => $token, 'scopes' => $scopeStr];
    }

    /** Resolve a Bearer token to its row (or null). Touches last_used (throttled). */
    public static function verify(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !str_starts_with($token, self::PREFIX)) return null;
        self::ensure();
        $db = Database::pdo();
        $s = $db->prepare('SELECT * FROM app_tokens WHERE token_hash = ? AND revoked = 0');
        $s->execute([hash('sha256', $token)]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        // Throttle the write: only stamp once a minute to avoid a write per call.
        $today = gmdate('Y-m-d H:i:s');
        if (substr((string) $row['last_used'], 0, 16) !== substr($today, 0, 16)) {
            try { $db->prepare('UPDATE app_tokens SET last_used = ? WHERE id = ?')->execute([$today, $row['id']]); } catch (Throwable $e) {}
        }
        $row['scope_list'] = $row['scopes'] === '' ? [] : explode(' ', (string) $row['scopes']);
        return $row;
    }

    public static function hasScope(?array $row, string $scope): bool
    {
        return $row !== null && in_array($scope, $row['scope_list'] ?? explode(' ', (string) ($row['scopes'] ?? '')), true);
    }

    /** All tokens for the admin console (no secrets — hashes only). */
    public static function all(): array
    {
        self::ensure();
        $rows = Database::pdo()->query('SELECT id, name, scopes, revoked, last_used, created_at FROM app_tokens ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $rows;
    }

    public static function revoke(int $id): void
    {
        self::ensure();
        Database::pdo()->prepare('UPDATE app_tokens SET revoked = 1 WHERE id = ?')->execute([$id]);
    }
}
