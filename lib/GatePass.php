<?php
/**
 * lib/GatePass.php — a member's pass for the CACENTRE gate.
 *
 * CACENTRE's front door is a gate (a Cloudflare Worker) that keeps no copy of
 * Afrovanguard's members. A member shows this pass — a QR on their phone —
 * and the gate checks it BY ITS SIGNATURE, at the edge, without asking this
 * site anything. The portal can be down and members still come in.
 *
 * ── THE PASS ────────────────────────────────────────────────────────────────
 * An HS256 JWT (firebase/php-jwt), with standard claims only:
 *     iss "afrovanguard"   aud "cacentre-gate"   sub <member id>
 *     name <display name>  role                 iat  exp  jti
 * It lives TTL seconds — twelve hours by default — so a screenshot shared
 * around is worthless by tomorrow, and the page mints a fresh one every time
 * it is opened. The gate refuses any pass that lives longer than 31 days.
 *
 * The signing key is never the secret itself but
 *     hex(HMAC_SHA256(GATE_PASS_SECRET, "cacentre-gate/v1/member-pass"))
 * — the Worker's own derivation (gate/src/crypto.ts, purposeSecret), so the
 * same shared secret can also sign revocations with a different key.
 *
 * ── WHO GETS ONE ────────────────────────────────────────────────────────────
 * Members, not everybody with an account: by default every role but
 * `learner` (GATE_PASS_ROLES overrides). A pass says who somebody is. What
 * a desk lets them do is the desk's business, and this pass grants nothing
 * on this site.
 *
 * ── A LOST PHONE ────────────────────────────────────────────────────────────
 * revoke() tells the gate that every pass issued to that member up to now is
 * void. The next one they open is good again.
 */
declare(strict_types=1);

use Firebase\JWT\JWT;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;

final class GatePass
{
    public const ISS = 'afrovanguard';
    public const AUD = 'cacentre-gate';
    public const DEFAULT_TTL = 12 * 3600;
    public const MAX_TTL = 31 * 86400;
    public const DEFAULT_ROLES = ['admin', 'coordinator', 'instructor', 'mentor', 'member'];

    private static function env(string $k): string
    {
        $v = getenv($k);
        if ($v !== false && trim((string) $v) !== '') return trim((string) $v);
        return defined($k) ? trim((string) constant($k)) : '';
    }

    public static function secret(): string { return self::env('GATE_PASS_SECRET'); }

    /** The gate's public origin: where a pass's URL points, and where revocations go. */
    public static function gateUrl(): string
    {
        $u = self::env('GATE_URL');
        return rtrim($u !== '' ? $u : 'https://cacentre.afrovanguard.org.ng', '/');
    }

    /** A secret too short to be one counts as absent. */
    public static function ready(): bool
    {
        $s = self::secret();
        if (strlen($s) < 32) return false;
        return !preg_match('/^<.*>$/s', $s) && !preg_match('/x{8,}/i', $s)
            && !preg_match('/^(your|changeme|replace|example|todo)/i', $s);
    }

    public static function ttl(): int
    {
        $t = (int) self::env('GATE_PASS_TTL');
        return $t > 0 ? min($t, self::MAX_TTL) : self::DEFAULT_TTL;
    }

    public static function key(string $purpose, ?string $secret = null): string
    {
        return hash_hmac('sha256', 'cacentre-gate/v1/' . $purpose, $secret ?? self::secret());
    }

    /** @return string[] */
    public static function roles(): array
    {
        $r = self::env('GATE_PASS_ROLES');
        return $r !== '' ? array_values(array_filter(array_map('trim', explode(',', strtolower($r))))) : self::DEFAULT_ROLES;
    }

    public static function eligible(?array $u): bool { return self::whyNot($u) === null; }

    /**
     * Why this member gets no pass, in words — or null. The rules are the
     * gate's entry rules (GateAttendance::whyNot): an active account, a member
     * role, and, where leadership has switched it on, no fine left unpaid too
     * long. A pass is withheld rather than issued and refused at the door,
     * because the gate checks a pass without asking this site.
     */
    public static function whyNot(?array $u): ?string
    {
        if (!$u) return 'Sign in first.';
        if (class_exists('GateAttendance')) return GateAttendance::whyNot($u);
        if (isset($u['status']) && (string) $u['status'] !== 'active') return 'This account is suspended.';
        return in_array(strtolower((string) ($u['role'] ?? 'learner')), self::roles(), true) ? null : 'Gate passes are for Afrovanguard members.';
    }

    /**
     * Check a request the gate signed to this site: "ts.body" under the
     * purpose key, at most five minutes old.
     */
    public static function verify(string $purpose, string $ts, string $body, string $sig): bool
    {
        if (!self::ready() || !ctype_digit($ts) || abs(time() - (int) $ts) > 300) return false;
        return hash_equals('sha256=' . hash_hmac('sha256', $ts . '.' . $body, self::key($purpose)), $sig);
    }

    /**
     * A pass for this member, good from now for ttl() seconds.
     *
     * @return array{token:string, url:string, exp:int}
     */
    public static function mint(array $u, ?int $now = null): array
    {
        if (!self::ready()) throw new RuntimeException('GATE_PASS_SECRET is not set.');
        $why = self::whyNot($u);
        if ($why !== null) throw new RuntimeException($why);
        $now = $now ?? time();
        $claims = [
            'iss' => self::ISS, 'aud' => self::AUD, 'sub' => (string) (int) $u['id'],
            'name' => mb_substr(trim((string) ($u['name'] ?? '')) ?: 'Member', 0, 80),
            'role' => ucfirst(strtolower((string) ($u['role'] ?? 'member'))),
            'iat' => $now, 'exp' => $now + self::ttl(), 'jti' => bin2hex(random_bytes(9)),
        ];
        $token = JWT::encode($claims, self::key('member-pass'), 'HS256');
        return ['token' => $token, 'url' => self::gateUrl() . '/p/' . $token, 'exp' => $claims['exp']];
    }

    /** The QR, as SVG. Level M: a phone screen is flat and bright, and a smaller code scans from further away. */
    public static function svg(string $url): string
    {
        $o = new QROptions([
            'outputInterface' => QRMarkupSVG::class, 'eccLevel' => EccLevel::M, 'addQuietzone' => true, 'quietzoneSize' => 4,
            'outputBase64' => false, 'svgAddXmlHeader' => false, 'drawLightModules' => false, 'connectPaths' => true,
        ]);
        return (new QRCode($o))->render($url);
    }

    /**
     * Withdraw every pass this member has been issued so far.
     *
     * @param ?callable $send (url, headers, body) => [status, body] — tests pass one
     */
    public static function revoke(int $memberId, ?int $before = null, ?callable $send = null): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'GATE_PASS_SECRET is not set.'];
        $body = json_encode(['sub' => (string) $memberId, 'before' => $before ?? time()]);
        $ts = (string) time();
        $headers = ['Content-Type: application/json', 'X-Gate-Timestamp: ' . $ts,
                    'X-Gate-Signature: sha256=' . hash_hmac('sha256', $ts . '.' . $body, self::key('admin'))];
        [$status, $resp] = ($send ?? [self::class, 'post'])(self::gateUrl() . '/v1/issuers/av/revoke', $headers, $body);
        $j = json_decode((string) $resp, true);
        return $status === 200 && !empty($j['ok']) ? ['ok' => true] : ['ok' => false, 'error' => 'The gate did not take the revocation (' . $status . ').'];
    }

    /** @return array{0:int,1:string} */
    public static function post(string $url, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) return [0, ''];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4]);
        $r = curl_exec($ch);
        $s = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$s, $r === false ? '' : (string) $r];
    }
}
