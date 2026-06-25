<?php
/**
 * av-lib.php — Shared runtime helpers for the Afrovanguard root endpoints.
 *
 * Tracked in git (unlike the deploy-only security.php) so the controls here
 * can be reviewed and version-controlled. Required by process-donation.php
 * and process-contact.php.
 *
 *   av_client_ip()        — trustworthy client IP (proxy-aware, not spoofable)
 *   av_resolve_data_dir() — a writable data directory, preferably OUTSIDE the web root
 */

declare(strict_types=1);

if (!function_exists('av_cidr_match')) {
    /**
     * True if $ip falls inside $cidr. Handles both IPv4 and IPv6 via packed
     * binary comparison, so the same routine validates either family.
     */
    function av_cidr_match(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false; // mixed families or malformed input
        }
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false; // v4 vs v6 mismatch
        }

        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr(0xFF << (8 - $rem) & 0xFF);
        return (($ipBin[$bytes] ^ $subnetBin[$bytes]) & $mask) === "\0";
    }
}

if (!function_exists('av_client_ip')) {
    /**
     * Resolve the real client IP without trusting attacker-controllable headers.
     *
     * `X-Forwarded-For` / `CF-Connecting-IP` are honoured ONLY when the direct
     * peer (REMOTE_ADDR) is a known Cloudflare edge or an operator-listed proxy
     * (env AV_TRUSTED_PROXIES, comma-separated CIDRs). Otherwise the direct
     * connection address is used. This closes the rate-limit-bypass-by-spoofed-
     * header hole while preserving real per-visitor IPs for Cloudflare-fronted
     * deployments (so the limiter does not collapse every visitor onto the edge IP).
     *
     * Cloudflare ranges are published at https://www.cloudflare.com/ips/ — refresh
     * if Cloudflare changes them (rare).
     */
    function av_client_ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remote === '') {
            return 'unknown';
        }

        static $cloudflare = [
            // IPv4
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            // IPv6
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ];

        $trusted = $cloudflare;
        $extra = (string) getenv('AV_TRUSTED_PROXIES');
        if ($extra !== '') {
            foreach (explode(',', $extra) as $c) {
                $c = trim($c);
                if ($c !== '') {
                    $trusted[] = $c;
                }
            }
        }

        $peerTrusted = false;
        foreach ($trusted as $cidr) {
            if (av_cidr_match($remote, $cidr)) {
                $peerTrusted = true;
                break;
            }
        }
        if (!$peerTrusted) {
            return $remote; // direct connection — cannot be spoofed
        }

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', (string) $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $remote;
    }
}

if (!function_exists('av_resolve_data_dir')) {
    /**
     * Choose where flat-file data (donations.json / contacts.json) lives.
     *
     * Preference order:
     *   1. env AV_DATA_DIR — operator-set absolute path OUTSIDE the web root (recommended).
     *   2. a sibling of the web root (…/public_html → …/av-private) — outside the docroot
     *      on the common shared-hosting layout where the repo IS the docroot.
     *   3. fallback: the application directory itself — never worse than the previous
     *      behaviour, and still covered by the deny rule in the tracked .htaccess.
     *
     * Whatever it returns, the data file is additionally protected by the .htaccess
     * `Require all denied` on donations.json / contacts.json.
     */
    function av_resolve_data_dir(string $appDir): string
    {
        $env = (string) getenv('AV_DATA_DIR');
        if ($env !== '') {
            $env = rtrim($env, '/');
            if (!is_dir($env)) {
                @mkdir($env, 0700, true);
            }
            if (is_dir($env) && is_writable($env)) {
                return $env;
            }
            error_log('[AV] AV_DATA_DIR set but not writable: ' . $env);
        }

        $sibling = dirname($appDir) . '/av-private';
        if (!is_dir($sibling)) {
            @mkdir($sibling, 0700, true);
        }
        if (is_dir($sibling) && is_writable($sibling)) {
            return $sibling;
        }

        return $appDir; // last resort — same location as before
    }
}
