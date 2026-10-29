<?php
/**
 * lib/CacWifi.php — a member's Wi-Fi, which CACENTRE runs.
 *
 * CACENTRE is the authority for the Wi-Fi across the estate: it holds every
 * PIN, the routers fetch their lists from it, and its blocks win. Afrovanguard
 * has a say about its own people and shows each member their sign-in name and
 * PIN on their own portal dashboard — nobody is sent to another site for it.
 *
 * ── THE CONNECTION IS THE ONE THAT ALREADY EXISTS ───────────────────────────
 * Same key (AV_SSO_SECRET), same CACENTRE origin (CacTasks::site()), same
 * v1 token as the leads, tasks and inventory doors; the door is
 * /api/afrovanguard-wifi.php and every token names it (`aud: wifi`).
 *
 *   roster()/push()   every Afrovanguard member and our say about each, from
 *                     the cron. `full`: anybody not sent loses Wi-Fi there.
 *   card($id)         one member's card for the portal, op show. The token
 *                     carries the member's own roster row (`me`), so a member
 *                     CACENTRE has not been sent yet is added there at once.
 *   setPassword($id)  the Wi-Fi password the member chose (three changes a
 *                     day). In the POST body, never a URL; never stored here.
 *
 * WHAT TRAVELS: id, kind (member | staff), name, email, our verdict and its
 * reason. Nothing else.
 */
declare(strict_types=1);

final class CacWifi
{
    public const PATH = '/api/afrovanguard-wifi.php';
    private const TIMEOUT = 4;
    /** CACENTRE refuses a bigger list rather than cut it (WifiStore::ROSTER_MAX). */
    public const ROSTER_MAX = 5000;

    /**
     * Every Afrovanguard member, with our verdict. "Member" is the portal's own
     * test (LmsAuth::isOrgMember: role member or above, or an org address);
     * coordinators and admins go as staff. An account that is not active goes
     * as off, with the reason, rather than vanishing without one.
     *
     * @return list<array<string,string>>
     */
    public static function roster(): array
    {
        $out = [];
        foreach (Database::pdo()->query('SELECT id, name, email, role, status FROM lms_users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $u) {
            if (($row = self::row($u)) !== null) $out[] = $row;
        }
        return $out;
    }

    /** One member's roster row, or null when they are not an org member. */
    public static function row(array $u): ?array
    {
            if (!LmsAuth::isOrgMember($u)) return null;
            $active = (string) ($u['status'] ?? 'active') === 'active';
            return [
                'id'        => (string) (int) $u['id'],
                'kind'      => LmsAuth::canManageMembers($u) ? 'staff' : 'member',
                'name'      => mb_substr(trim((string) $u['name']), 0, 160),
                'email'     => strtolower(trim((string) $u['email'])),
                'printedId' => '',
                'homeSite'  => '',
                'verdict'   => $active ? 'on' : 'off',
                'why'       => $active ? '' : 'The Afrovanguard account is not active',
            ];
    }

    /** Send the roster. One full push; a list over the cap is not sent at all, and says so. */
    public static function push(): array
    {
        if (!CacSso::ready()) return ['ok' => false, 'error' => 'not-configured'];
        $people = self::roster();
        if (count($people) > self::ROSTER_MAX) return ['ok' => false, 'error' => 'too-many', 'sent' => 0];
        $raw = self::sendRoster($people, true);
        if ($raw === null) return ['ok' => false, 'error' => 'unreachable', 'sent' => count($people)];
        $d = json_decode($raw, true);
        if (!is_array($d) || empty($d['ok'])) return ['ok' => false, 'error' => is_array($d) ? (string) ($d['error'] ?? 'refused') : 'unreadable', 'sent' => count($people)];
        return ['ok' => true, 'sent' => count($people), 'applied' => (int) ($d['applied'] ?? 0), 'refused' => (int) ($d['refused'] ?? 0), 'gone' => (int) ($d['gone'] ?? 0)];
    }

    /** Send people as a roster. `full` withdraws anybody not sent; a partial one withdraws nobody. */
    private static function sendRoster(array $people, bool $full): ?string
    {
        return self::send(CacTasks::site() . self::PATH, [
            'action' => 'roster',
            'token'  => CacSso::sign(['aud' => 'wifi', 'typ' => 'wifi.roster', 'full' => $full, 'people' => $people]),
        ], $full ? 60 : self::TIMEOUT);
    }

    /**
     * One member's card: ['ok', 'card' => array|null, 'error'].
     *
     * Automatic, never "come back in a quarter of an hour": if CACENTRE has
     * not been sent this member yet, they are sent now, alone, as a partial
     * roster (which withdraws nobody, and which every CACENTRE that has the
     * Wi-Fi door accepts), and the card is asked for again. A CACENTRE that
     * also reads the member's own row from the card request adds them on the
     * first ask; this covers one that does not.
     */
    public static function card(int $memberId): array
    {
        $r = self::ask($memberId, null);
        if (empty($r['ok']) || $r['card'] !== null) return $r;
        $me = self::me($memberId);
        if ($me === null) return $r;
        $raw = self::sendRoster([$me], false);
        $d = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($d) || empty($d['ok'])) return ['ok' => false, 'card' => null, 'error' => $raw === null ? 'unreachable' : 'roster-refused', 'message' => ''];
        $again = self::ask($memberId, null);
        if (!empty($again['ok']) && $again['card'] === null) {
            /* Sent and still not listed: CACENTRE refused the row, and says why. */
            $why = (string) ($d['results'][0]['error'] ?? '');
            return ['ok' => false, 'card' => null, 'error' => 'not-accepted', 'message' => $why === 'name_taken' ? 'Your sign-in name is already used by somebody else on the Wi-Fi.' : ''];
        }
        return $again;
    }

    /** This member's roster row, or null when they are not an org member. */
    private static function me(int $memberId): ?array
    {
        $st = Database::pdo()->prepare('SELECT id, name, email, role, status FROM lms_users WHERE id = ?');
        $st->execute([$memberId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        return $u ? self::row($u) : null;
    }

    public static function setPassword(int $memberId, string $password): array
    {
        return self::ask($memberId, ['action' => 'password', 'password' => $password]);
    }

    private static function ask(int $memberId, ?array $post): array
    {
        if ($memberId <= 0) return ['ok' => false, 'card' => null, 'error' => 'no-member'];
        if (!CacSso::ready()) return ['ok' => false, 'card' => null, 'error' => 'not-configured'];
        $claims = ['aud' => 'wifi', 'uid' => $memberId];
        if ($post === null && ($me = self::me($memberId)) !== null) $claims['me'] = $me;
        $url = CacTasks::site() . self::PATH . '?t=' . rawurlencode(CacSso::sign($claims));
        $raw = self::send($url, $post, self::TIMEOUT);
        if ($raw === null) return ['ok' => false, 'card' => null, 'error' => 'unreachable'];
        $d = json_decode($raw, true);
        if (!is_array($d) || empty($d['ok'])) return ['ok' => false, 'card' => null, 'error' => is_array($d) ? (string) ($d['error'] ?? 'refused') : 'unreadable',
                                                      'message' => is_array($d) ? mb_substr((string) ($d['message'] ?? ''), 0, 200) : ''];
        return ['ok' => true, 'card' => is_array($d['card'] ?? null) ? self::clean($d['card']) : null, 'error' => '', 'message' => ''];
    }

    /** Only the fields the portal draws, each bounded. */
    private static function clean(array $c): array
    {
        $sp = is_array($c['speed'] ?? null) ? $c['speed'] : null;
        $u = is_array($c['usage'] ?? null) ? $c['usage'] : [];
        return [
            'username'   => mb_substr((string) ($c['username'] ?? ''), 0, 64),
            'hasPassword' => !empty($c['hasPassword']),
            'pinSetAt'   => preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', (string) ($c['pinSetAt'] ?? '')) ? (string) $c['pinSetAt'] : '',
            'access'     => in_array($c['access'] ?? '', ['on', 'limited', 'off'], true) ? (string) $c['access'] : 'off',
            /* CACENTRE words a reason for its staff ("Afrovanguard has no Wi-Fi
               for them: …"); on the member's own screen our part is just our reason. */
            'why'        => mb_substr(in_array((string) ($c['case'] ?? ''), ['issuer_off', 'issuer_limited'], true) && str_contains((string) ($c['why'] ?? ''), ': ')
                                ? substr((string) $c['why'], strpos((string) $c['why'], ': ') + 2) : (string) ($c['why'] ?? ''), 0, 255),
            'speed'      => $sp ? ['down' => (int) ($sp['down'] ?? 0), 'devices' => (int) ($sp['devices'] ?? 1), 'hours' => (string) ($sp['hours'] ?? '')] : null,
            'usage'      => ['today' => (int) ($u['today'] ?? 0), 'month' => (int) ($u['month'] ?? 0), 'dayMb' => (int) ($u['dayMb'] ?? 0), 'monthMb' => (int) ($u['monthMb'] ?? 0), 'over' => (string) ($u['over'] ?? '')],
            'where'      => mb_substr((string) ($c['where'] ?? ''), 0, 80),
            'resetsLeft' => max(0, (int) ($c['resetsLeft'] ?? 0)),
            'hasRouter'  => !empty($c['hasRouter']),
        ];
    }

    /** One request (a POST when $body is given), every transport failure turned into null. TLS verification stays on. */
    private static function send(string $url, ?array $body, int $timeout): ?string
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Afrovanguard/1.0 (+wifi)',
        ];
        if ($body !== null) $opt += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES), CURLOPT_HTTPHEADER => ['Content-Type: application/json']];
        curl_setopt_array($ch, $opt);
        $out = curl_exec($ch);
        curl_close($ch);
        /* The body comes back on a refusal too: the far side says which one. */
        return $out === false ? null : (string) $out;
    }
}
