<?php
/**
 * lib/CacSso.php — handing a signed-in member across to the CACENTRE CRM.
 *
 * A member signs in here once and follows a link into the CRM at
 * cacentre.afrovanguard.org.ng without a second password.
 *
 * ── THIS SIDE SAYS WHO. IT DOES NOT SAY WHAT THEY MAY DO ────────────────────
 * The assertion is proof that this site signed somebody in. It carries their
 * role because that is worth recording over there, and CACENTRE grants
 * nothing on the strength of it: every arrival is authorised on that side,
 * against its own crm_user_scopes table.
 *
 * That is deliberate and it must stay that way. This portal has learners,
 * mentors and instructors on it — people with every right to be here and none
 * to be in a pipeline holding ninety people's phone numbers and the centre's
 * payroll. If permission were read off this token, adding a role here would
 * silently widen access there.
 *
 * ── THE TOKEN ───────────────────────────────────────────────────────────────
 *     v1.<base64url payload>.<base64url HMAC-SHA256>
 *
 * signed with AV_SSO_SECRET, which both sites hold and neither commits. It is
 * good for sixty seconds and once only: it spends its life in a URL, and a
 * URL lands in browser history, in Referer headers and in the access log of
 * every host in between. CACENTRE records each nonce and refuses a second
 * presentation.
 *
 * The format is mirrored in cacentre-site's includes/crm/CrmSso.php. Two
 * copies of a wire format is a real cost; the alternative is a shared package
 * on a host with no Composer step, which is a larger one. Both files say so,
 * and both are covered by tests that sign here and verify there.
 */
declare(strict_types=1);

final class CacSso
{
    /** Where the other side receives arrivals. */
    public const LANDING = 'https://cacentre.afrovanguard.org.ng/crm/sso.php';

    /** Seconds an assertion is good for. Matches CrmSso::TTL. */
    public const TTL = 60;

    public static function secret(): string
    {
        $v = getenv('AV_SSO_SECRET');
        if ($v !== false && trim((string) $v) !== '') return trim((string) $v);
        if (defined('AV_SSO_SECRET') && trim((string) constant('AV_SSO_SECRET')) !== '') {
            return trim((string) constant('AV_SSO_SECRET'));
        }
        return '';
    }

    /**
     * Is the bridge usable?
     *
     * A secret too short to be one counts as absent: a half-set key that makes
     * the link appear and then fails on every click is worse than no link.
     */
    public static function ready(): bool
    {
        $s = self::secret();
        if ($s === '' || strlen($s) < 32) return false;
        return !preg_match('/^<.*>$/s', $s)
            && !preg_match('/x{8,}/i', $s)
            && !preg_match('/^(your|changeme|replace|example|todo)/i', $s);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Mint an assertion for a signed-in member.
     *
     * @param array $u a row from LmsAuth::user()
     */
    public static function mint(array $u): string
    {
        $now = time();
        $payload = json_encode([
            'iss'   => 'av',
            'sub'   => strtolower(trim((string) ($u['email'] ?? ''))),
            'uid'   => (int) ($u['id'] ?? 0),
            'name'  => mb_substr(trim((string) ($u['name'] ?? '')), 0, 120),
            'role'  => mb_substr(trim((string) ($u['role'] ?? '')), 0, 40),
            'nonce' => bin2hex(random_bytes(16)),
            'iat'   => $now,
            'exp'   => $now + self::TTL,
        ], JSON_UNESCAPED_SLASHES);

        $body = self::b64((string) $payload);
        return 'v1.' . $body . '.' . self::b64(hash_hmac('sha256', $body, self::secret(), true));
    }

    /**
     * The full URL to send a member to.
     *
     * `$next` is a PATH on the CRM, never a URL: it is passed through to a
     * redirect on the other side, and a redirect that follows anything it is
     * handed is an open redirect — a phishing link that genuinely begins on
     * cacentre.afrovanguard.org.ng. That side refuses anything that is not a
     * local path too; this is the same rule kept on both ends rather than
     * trusted to one.
     */
    public static function linkFor(array $u, string $next = '/crm/'): string
    {
        if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//')) $next = '/crm/';
        return self::LANDING . '?t=' . rawurlencode(self::mint($u))
             . '&next=' . rawurlencode($next);
    }
}
