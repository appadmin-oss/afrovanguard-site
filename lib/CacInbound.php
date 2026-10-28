<?php
/**
 * lib/CacInbound.php — a request FROM CACENTRE, checked once, for every door.
 *
 * CACENTRE calls this site at a few doors (meetings, sending mail). Each signs
 * with the bridge secret both sites already hold, AV_SSO_SECRET, in the same
 * v1.<claims>.<hmac> format the portal signs in the other direction:
 *
 *   iss "cacentre", aud <the door>, iat, exp (a minute), nonce,
 *   bh = sha256 of the request the token came with
 *
 * The door is named, so a token for one cannot be spent at another; `bh`
 * binds it to its body, so it cannot be re-aimed; the nonce is spent, so it
 * cannot be replayed. One check, so a fix to it reaches every door.
 */
declare(strict_types=1);

final class CacInbound
{
    private const SKEW = 60;

    private static function unb64(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        return (string) base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /**
     * The token's claims, or the reason it was refused.
     *
     * @return array<string,mixed>|string
     */
    public static function verify(string $token, string $signed, string $aud, ?int $now = null)
    {
        if (!class_exists('CacSso') || !CacSso::ready()) return 'not-configured';
        $p = explode('.', $token);
        if (count($p) !== 3 || $p[0] !== 'v1') return 'bad-token';
        if (!hash_equals(hash_hmac('sha256', $p[1], CacSso::secret(), true), self::unb64($p[2]))) return 'bad-signature';
        $c = json_decode(self::unb64($p[1]), true);
        if (!is_array($c)) return 'bad-token';
        $now = $now ?? time();
        if ((string) ($c['iss'] ?? '') !== 'cacentre' || (string) ($c['aud'] ?? '') !== $aud) return 'wrong-door';
        if ((int) ($c['exp'] ?? 0) < $now - self::SKEW) return 'expired';
        if ((int) ($c['iat'] ?? 0) > $now + self::SKEW) return 'not-yet';
        if (!hash_equals((string) ($c['bh'] ?? ''), hash('sha256', $signed))) return 'body-mismatch';
        if (!self::spend((string) ($c['nonce'] ?? ''), $now)) return 'replayed';
        return $c;
    }

    /** A nonce is good once. Kept a day, far past any token's life. */
    private static function spend(string $nonce, int $now): bool
    {
        if (!preg_match('/^[a-f0-9]{16,64}$/', $nonce)) return false;
        $db = Database::pdo();
        /* The table keeps the name it was given when only meetings used it. */
        Database::execSchema($db, "CREATE TABLE IF NOT EXISTS cac_meeting_nonces (nonce VARCHAR(64) PRIMARY KEY, at INTEGER NOT NULL DEFAULT 0)");
        try { $db->prepare('INSERT INTO cac_meeting_nonces (nonce, at) VALUES (?, ?)')->execute([$nonce, $now]); }
        catch (Throwable $e) { return false; }
        if (random_int(1, 50) === 1) $db->prepare('DELETE FROM cac_meeting_nonces WHERE at < ?')->execute([$now - 86400]);
        return true;
    }
}
