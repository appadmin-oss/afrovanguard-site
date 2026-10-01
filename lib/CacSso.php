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
    /** Where CACENTRE lives when nothing says otherwise. */
    public const DEFAULT_BASE = 'https://cacentre.afrovanguard.org.ng';

    /** The path on that host that receives arrivals. */
    public const LANDING_PATH = '/crm/sso.php';

    /**
     * The CACENTRE origin.
     *
     * This used to be a const holding the full production URL, which made
     * the bridge HALF configurable and that is worse than not configurable
     * at all: CacTasks already honoured CAC_SITE_URL, so pointing the API
     * at a staging CACENTRE moved the task list and left the sign-on
     * handoff aimed at production. Somebody testing locally would be
     * thrown at the live CRM with a live assertion and no indication
     * anything was wrong.
     *
     * One variable now moves both. Same resolution order as secret():
     * environment first, then a constant from config.php, then the
     * default — so a shared host with no env control can still set it.
     */
    public static function base(): string
    {
        foreach ([getenv('CAC_SITE_URL'), getenv('AV_CACENTRE_URL')] as $v) {
            if ($v !== false && trim((string) $v) !== '') return self::normaliseBase((string) $v);
        }
        foreach (['CAC_SITE_URL', 'AV_CACENTRE_URL'] as $c) {
            if (defined($c) && trim((string) constant($c)) !== '') return self::normaliseBase((string) constant($c));
        }

        return self::DEFAULT_BASE;
    }

    /** Where the other side receives arrivals. */
    public static function landing(): string
    {
        return self::base() . self::LANDING_PATH;
    }

    /**
     * An origin, or the default.
     *
     * This value decides where a signed assertion is SENT. A typo that
     * resolved to something odd would post a credential somewhere nobody
     * meant, so anything that is not a plain http(s) origin is refused and
     * the default stands — a misconfiguration keeps working against
     * production rather than quietly handing tokens to a stray host.
     */
    private static function normaliseBase(string $v): string
    {
        $v = rtrim(trim($v), '/');
        $p = parse_url($v);
        if (!is_array($p) || empty($p['host']) || empty($p['scheme'])) return self::DEFAULT_BASE;
        if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) return self::DEFAULT_BASE;
        /* No credentials, no path, no query: an origin and nothing else. */
        if (!empty($p['user']) || !empty($p['pass']) || !empty($p['query']) || !empty($p['fragment'])) {
            return self::DEFAULT_BASE;
        }
        $port = !empty($p['port']) ? ':' . (int) $p['port'] : '';

        return strtolower($p['scheme']) . '://' . $p['host'] . $port;
    }

    /** Seconds an assertion is good for. Matches CrmSso::TTL. */
    public const TTL = 60;

    /** Where somebody lands on the other side when they asked for nothing. */
    public const HOME = '/crm/';

    /** The door on this side. One address, from anywhere on the site. */
    public const DOOR = '/cacentre';

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
    public static function linkFor(array $u, string $next = self::HOME): string
    {
        return self::landing() . '?t=' . rawurlencode(self::mint($u))
             . '&next=' . rawurlencode(self::path($next));
    }

    /**
     * A path on CACENTRE, or the home page.
     *
     * Written once because three things need the same answer: the door, the
     * link builder, and the redirect on the far side. What arrives here is
     * a query parameter — it comes from whatever link somebody clicked —
     * and it ends up in a Location header on a host that trusts this one.
     * So: a local path, or nothing.
     *
     *   //evil.test      a protocol-relative URL, which is a host
     *   /\evil.test      the same thing after a browser folds the backslash
     *   https://…        a host said out loud
     *   /crm/x%0D%0A…    a second header, if the bytes get through raw
     *
     * CACENTRE's crm/sso.php refuses a non-local `next` as well. That is
     * deliberate: the rule holds on both ends rather than on the promise of
     * one, because an open redirect here is a phishing link that genuinely
     * begins on cacentre.afrovanguard.org.ng.
     */
    public static function path(string $p): string
    {
        $p = trim($p);
        if ($p === '' || $p[0] !== '/') return self::HOME;
        if (str_starts_with($p, '//') || str_starts_with($p, '/\\')) return self::HOME;
        if (str_contains($p, '\\')) return self::HOME;
        /* Control characters, which is how a second header gets written. */
        if (preg_match('/[\x00-\x1f\x7f]/', $p)) return self::HOME;
        return $p;
    }

    /**
     * The door's own address, for a link on a page.
     *
     * Not a minted link: an assertion lives sixty seconds, and one baked
     * into a page starts expiring the moment the page renders. This points
     * at the door, which mints on the click.
     */
    public static function door(string $to = self::HOME): string
    {
        $to = self::path($to);
        return self::DOOR . ($to === self::HOME ? '' : '?to=' . rawurlencode($to));
    }
}
