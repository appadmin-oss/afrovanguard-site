<?php
/**
 * lib/CacInventory.php — the CACENTRE register, read from here.
 *
 * ── WHY THE PORTAL READS IT AT ALL ──────────────────────────────────────────
 * A member can already open the console's inventory — it is in
 * AdminRole::MEMBER_PAGES over there, and membership is the grant. What they
 * could not do is answer "where is the Dell laptop, and who has it" without
 * leaving the portal, signing across, landing in a different shell and
 * finding their way back. The question takes four seconds and the journey
 * took thirty, so people stopped asking the system and started asking each
 * other. That is how a register becomes fiction.
 *
 * This is not new access. It is the same access, reachable without moving.
 *
 * ── READ, NOT COPIED ────────────────────────────────────────────────────────
 * The mirror of CacTasks, deliberately: CACENTRE stays the one place an item
 * is true. Nothing is stored here, so there is never a pair of rows to
 * reconcile and never a question about which is right.
 *
 * ── AND IT FAILS SOFT ───────────────────────────────────────────────────────
 * Every failure is an empty result and a reason; none throws. A member
 * looking something up while the other site is deploying gets a sentence
 * saying so, not a blank page and not a stack trace.
 */
declare(strict_types=1);

final class CacInventory
{
    /** Where CACENTRE answers. */
    public const PATH = '/api/afrovanguard-inventory.php';

    /** Long enough for a slow deploy, short enough not to be a hang. */
    private const TIMEOUT = 3;

    /** One fetch per distinct query per request, however often a page asks. */
    private static array $memo = [];

    /** Where a member goes to actually change one of these. */
    public static function consoleUrl(): string
    {
        return CacTasks::site() . '/admin/inventory.php';
    }

    /**
     * The register, filtered, as CACENTRE holds it.
     *
     * @param array<string,string|int> $filters  q, category, site, status, page
     * @return array{ok:bool, items:array<int,array<string,mixed>>, total:int,
     *               page:int, pages:int, facets:array<string,array>, error:string, url:string}
     */
    public static function search(int $memberId, array $filters = []): array
    {
        $blank = [
            'ok' => false, 'items' => [], 'total' => 0, 'page' => 1, 'pages' => 1,
            'facets' => ['categories' => [], 'sites' => [], 'statuses' => []],
            'error' => '', 'url' => self::consoleUrl(),
        ];

        if ($memberId <= 0)   return $blank;
        if (!CacSso::ready()) return array_merge($blank, ['error' => 'not-configured']);

        /* Only the keys the far side reads, so a stray query parameter on the
           portal's own URL cannot be forwarded into somebody else's API. */
        $q = [];
        foreach (['q', 'category', 'site', 'status', 'page'] as $k) {
            $v = trim((string) ($filters[$k] ?? ''));
            if ($v !== '') $q[$k] = mb_substr($v, 0, 80);
        }
        ksort($q);
        $key = $memberId . '|' . http_build_query($q);
        if (isset(self::$memo[$key])) return self::$memo[$key];

        $url  = CacTasks::site() . self::PATH
              . '?t=' . rawurlencode(CacSso::mint(['id' => $memberId]))
              . ($q ? '&' . http_build_query($q) : '');
        $body = self::get($url);

        if ($body === null) return self::$memo[$key] = array_merge($blank, ['error' => 'unreachable']);

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['ok'])) {
            $why = is_array($data) ? (string) ($data['error'] ?? 'refused') : 'unreadable';
            return self::$memo[$key] = array_merge($blank, ['error' => $why]);
        }

        return self::$memo[$key] = [
            'ok'     => true,
            'items'  => self::clean($data['items'] ?? []),
            'total'  => (int) ($data['total'] ?? 0),
            'page'   => max(1, (int) ($data['page'] ?? 1)),
            'pages'  => max(1, (int) ($data['pages'] ?? 1)),
            'facets' => [
                'categories' => self::strings($data['facets']['categories'] ?? []),
                'sites'      => self::strings($data['facets']['sites'] ?? []),
                'statuses'   => self::strings($data['facets']['statuses'] ?? []),
            ],
            /* `off` means the centre has inventory switched off entirely,
               which is a different sentence from "nothing matched". */
            'error'  => !empty($data['off']) ? 'off' : '',
            'url'    => self::consoleUrl(),
        ];
    }

    /**
     * Shape another system's rows into the few fields this site draws.
     *
     * Every field arrives bounded, because it is another system's data and a
     * page renders it. A missing one becomes empty rather than a warning
     * halfway down somebody's screen.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function clean(mixed $rows): array
    {
        if (!is_array($rows)) return [];
        $out = [];
        foreach (array_slice($rows, 0, 60) as $r) {
            if (!is_array($r)) continue;
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') continue;
            $out[] = [
                'id'       => (int) ($r['id'] ?? 0),
                'tag'      => mb_substr(trim((string) ($r['tag'] ?? '')), 0, 40),
                'name'     => mb_substr($name, 0, 160),
                'category' => mb_substr(trim((string) ($r['category'] ?? '')), 0, 60),
                'site'     => mb_substr(trim((string) ($r['site'] ?? '')), 0, 60),
                'location' => mb_substr(trim((string) ($r['location'] ?? '')), 0, 80),
                'status'   => mb_substr(trim((string) ($r['status'] ?? '')), 0, 40),
                /* A tone drives a colour. Anything the far side invents that
                   this site has no class for becomes nothing, rather than an
                   unstyled word or an attribute injected into a class list. */
                'tone'     => in_array((string) ($r['tone'] ?? ''), ['pos', 'warn', 'neg'], true)
                              ? (string) $r['tone'] : '',
                'holder'   => mb_substr(trim((string) ($r['holder'] ?? '')), 0, 120),
                'quantity' => max(0, (int) ($r['quantity'] ?? 0)),
                'out'      => max(0, (int) ($r['out'] ?? 0)),
                'unit'     => mb_substr(trim((string) ($r['unit'] ?? '')), 0, 24),
            ];
        }
        return $out;
    }

    /**
     * A facet list as key => label, whatever shape the far side sent.
     *
     * The centre's categories are a plain list and its sites are a map, and
     * both arrive through the same door. Normalising here means the screen
     * has one kind of loop rather than three.
     *
     * @return array<string,string>
     */
    private static function strings(mixed $v): array
    {
        if (!is_array($v)) return [];
        $out = [];
        foreach (array_slice($v, 0, 60, true) as $k => $label) {
            if (is_array($label)) continue;
            $label = trim((string) $label);
            if ($label === '') continue;
            $key = is_int($k) ? $label : trim((string) $k);
            if ($key === '') continue;
            $out[mb_substr($key, 0, 40)] = mb_substr($label, 0, 60);
        }
        return $out;
    }

    /** One GET, every failure turned into null. TLS verification stays on. */
    private static function get(string $url): ?string
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Afrovanguard/1.0 (+inventory)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($body === false || $code !== 200) ? null : (string) $body;
    }
}
