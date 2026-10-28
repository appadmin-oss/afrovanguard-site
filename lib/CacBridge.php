<?php
/**
 * lib/CacBridge.php — when CACENTRE fails, the administrators hear why;
 * members do not.
 *
 * Every read and write across the bridge (CacTasks, CacWeek, CacInventory,
 * CacLeads, CacGoals) ends in one curl call, and each turned any failure into
 * null — so a member saw "try again in a moment" whether CACENTRE was down
 * for a minute or had been answering every request with a blank 500 for a
 * day, and nobody who could fix it was told.
 *
 * Each of those calls now passes its handle here (see()) right after
 * curl_exec. A fault of the SYSTEM — CACENTRE erroring, not answering, a
 * door it does not have, a secret or clock that disagrees — is put into
 * words and sent to the administrators as a portal notification and an
 * email, once per kind of fault per ALERT_EVERY. A refusal about one member
 * (not linked, no grant) is not a fault and is left alone.
 *
 * Members keep a neutral sentence (MEMBER): the reason names hosts, files
 * and settings, and that is for the people who can change them.
 */
declare(strict_types=1);

final class CacBridge
{
    /** What a member reads when CACENTRE did not answer. */
    public const MEMBER = 'CACENTRE is not available right now. Your work here is unaffected, and the administrators have been told.';

    /** One alert per kind of fault in this many seconds. */
    private const ALERT_EVERY = 21600;

    /** Refusals that mean the bridge itself is wrong, not one member's access. */
    private const SYSTEM_REFUSALS = ['bad-signature', 'not-configured', 'expired', 'not-yet', 'wrong-door', 'bad-token', 'unavailable'];

    /** The last fault seen in this request, for tests and the Studio. */
    private static ?array $last = null;

    /**
     * After curl_exec, before curl_close.
     *
     * @param CurlHandle|resource $ch
     * @param string|false        $body what curl_exec returned
     */
    public static function see($ch, $body, string $door): void
    {
        $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $url   = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $fault = self::fault($code, $errno, $errno ? curl_error($ch) : '', $body === false ? '' : (string) $body, $door, $url);
        if ($fault === null) return;
        self::$last = $fault;
        try { self::alert($fault); } catch (Throwable $e) { error_log('[cac-bridge] alert: ' . $e->getMessage()); }
    }

    public static function last(): ?array { return self::$last; }

    /**
     * The fault, in words for an administrator, or null when nothing is
     * wrong with the bridge. Pure: the tests drive it directly.
     *
     * @return array{key:string, why:string, door:string, code:int}|null
     */
    public static function fault(int $code, int $errno, string $err, string $body, string $door, string $url = ''): ?array
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $base = class_exists('CacSso') ? CacSso::base() : 'CACENTRE';
        $f = static fn(string $key, string $why) => ['key' => $key, 'why' => $why, 'door' => $door, 'code' => $code];

        if ($errno === 28) return $f('timeout', "CACENTRE ($base) took too long to answer, so the portal gave up. It may be overloaded or down.");
        if ($errno !== 0)  return $f('connect', "The portal could not connect to CACENTRE ($base): $err. Check that the site is up and that CAC_SITE_URL is right.");

        $j = json_decode($body, true);
        $refusal = is_array($j) && empty($j['ok']) ? (string) ($j['error'] ?? '') : '';

        if ($code >= 500 && !is_array($j)) {
            return $f('http' . $code, "CACENTRE answered HTTP $code with " . (trim($body) === '' ? 'an empty page' : 'an error page')
                . ' — its PHP is failing, so nothing in the portal can reach it. The CACENTRE login page (/admin/login.php) names the cause'
                . ' once its latest package is uploaded; otherwise cac/data/logs/errors.log on its server has it. After an upload, the usual'
                . ' cause is cPanel → MultiPHP Manager: choose PHP 8.4 for the domain again and Apply.');
        }
        if ($code === 404) {
            return $f('missing', "CACENTRE has no $path (HTTP 404). Its package is older than this site's: upload the latest CACENTRE package.");
        }
        if ($refusal !== '' && in_array($refusal, self::SYSTEM_REFUSALS, true)) {
            return $f($refusal, match ($refusal) {
                'bad-signature'  => 'CACENTRE refused this site\'s signature: AV_SSO_SECRET is not the same on the two sites. Set the same value on both.',
                'not-configured' => 'CACENTRE has no AV_SSO_SECRET set, so it accepts nothing from the portal. Set the same value as on this site.',
                'expired', 'not-yet' => 'The two servers\' clocks disagree by more than a minute, so CACENTRE treats the portal\'s tokens as stale. Ask the host to sync the clocks (NTP).',
                'wrong-door', 'bad-token' => "CACENTRE did not accept the portal's token at $path. The two sites are on different versions: upload the latest CACENTRE package.",
                'unavailable'    => 'CACENTRE is up but its database is not answering. Its login page names the cause.',
                default          => "CACENTRE refused the portal at $path: $refusal.",
            });
        }
        if ($code >= 500) return $f('http' . $code, "CACENTRE answered HTTP $code at $path" . ($refusal !== '' ? " ($refusal)" : '') . '.');
        return null;
    }

    /** Tell each administrator, once per kind of fault per ALERT_EVERY. */
    private static function alert(array $fault): void
    {
        if (!class_exists('Database')) return;
        $metaKey = 'cac_bridge_alert_' . preg_replace('/[^a-z0-9_-]/', '', $fault['key']);
        $now = time();
        if ((int) (Database::metaGet($metaKey) ?? 0) > $now - self::ALERT_EVERY) return;
        Database::metaSet($metaKey, (string) $now);
        Database::metaSet('cac_bridge_last', (string) json_encode(['at' => gmdate('Y-m-d H:i:s', $now)] + $fault, JSON_UNESCAPED_SLASHES));

        $title = 'CACENTRE link failing: ' . $fault['door'];
        $dedupe = 'cacbridge:' . $fault['key'] . ':' . intdiv($now, self::ALERT_EVERY);
        foreach (self::admins() as $a) {
            if (class_exists('Notifications')) Notifications::push((int) $a['id'], 'warning', $title, $fault['why'], '/admin/', $dedupe);
            if (class_exists('Mailer') && (string) $a['email'] !== '') {
                $html = Mailer::shell('The CACENTRE link is failing', [
                    'The portal could not use CACENTRE (' . htmlspecialchars($fault['door'], ENT_QUOTES) . '). Members see only that CACENTRE is not available right now.',
                    htmlspecialchars($fault['why'], ENT_QUOTES),
                    'You will not hear about this same fault again for six hours.',
                ]);
                try { Mailer::send((string) $a['email'], $title, $html); } catch (Throwable $e) { error_log('[cac-bridge] mail: ' . $e->getMessage()); }
            }
        }
    }

    /** @return list<array{id:int,email:string}> */
    private static function admins(): array
    {
        try {
            $rows = Database::pdo()->query("SELECT id, email, role FROM lms_users WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
        return array_values(array_filter($rows, static fn($r) => LmsAuth::rank((string) $r['role']) >= LmsAuth::ROLE_RANK['admin']));
    }
}
