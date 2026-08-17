<?php
/**
 * lib/AvWeb.php — the AI's window onto the web.
 *
 * Two capabilities: search for pages, and read one. Both are exposed to the
 * model as tools (see AvTools), so it can go and find current material instead
 * of answering from whatever it was trained on — and then propose what it
 * learned as a doctrine entry for an administrator to approve.
 *
 * Search is provider-agnostic because none of these vendors is a safe bet to
 * hardcode. Whichever key is set wins, in this order:
 *
 *   AV_SEARCH_PROVIDER   force one of: brave | serper | google | tavily
 *   AV_BRAVE_API_KEY     Brave Search API
 *   AV_SERPER_API_KEY    serper.dev (Google results)
 *   AV_GOOGLE_CSE_KEY + AV_GOOGLE_CSE_CX   Google Programmable Search
 *   AV_TAVILY_API_KEY    Tavily (built for LLM use)
 *
 * With none of them set, search reports itself unavailable and the tool is not
 * offered to the model at all — telling a model it can search when it cannot
 * just makes it retry.
 *
 * FETCHING IS THE DANGEROUS HALF. A URL supplied by a model, in a system that
 * also runs on a server with private network neighbours, is a server-side
 * request forgery waiting to happen. So fetch():
 *
 *   • allows only http/https — no file://, gopher://, dict://, etc.
 *   • resolves the host and refuses private, loopback, link-local and reserved
 *     addresses, so the AI cannot be talked into reading 169.254.169.254 or a
 *     database on localhost;
 *   • re-checks after every redirect (following redirects is the classic bypass,
 *     so redirects are followed manually rather than by cURL);
 *   • caps body size, time and redirect count;
 *   • sends no cookies, no auth, and never the site's own credentials.
 *
 * Nothing here throws into a request: every failure is a readable error the
 * model can reason about.
 */
declare(strict_types=1);

final class AvWeb
{
    private const MAX_BYTES     = 400000;   // ~400KB of HTML before we stop reading
    private const MAX_TEXT      = 12000;    // characters of extracted text returned
    private const TIMEOUT       = 20;
    private const MAX_REDIRECTS = 4;

    private static function cfg(string $k, string $def = ''): string
    {
        if (class_exists('Config')) return Config::str($k, $def);
        return (string) (getenv($k) ?: $def);
    }

    /* ════════════════════════════════════════════════════════════════
       Availability
       ════════════════════════════════════════════════════════════════ */

    /** Which search backend is in play ('' when none is configured). */
    public static function searchProvider(): string
    {
        $forced = strtolower(trim(self::cfg('AV_SEARCH_PROVIDER')));
        if (in_array($forced, ['brave', 'serper', 'google', 'tavily'], true)) return $forced;
        if (self::cfg('AV_BRAVE_API_KEY') !== '')  return 'brave';
        if (self::cfg('AV_SERPER_API_KEY') !== '') return 'serper';
        if (self::cfg('AV_GOOGLE_CSE_KEY') !== '' && self::cfg('AV_GOOGLE_CSE_CX') !== '') return 'google';
        if (self::cfg('AV_TAVILY_API_KEY') !== '') return 'tavily';
        return '';
    }

    /** Is a given tool usable right now? Fetching needs only cURL. */
    public static function available(string $tool): bool
    {
        if (!function_exists('curl_init')) return false;
        if (class_exists('AvRules') && !AvRules::bool('ai.web_access')) return false;
        return $tool === 'web_fetch' ? true : self::searchProvider() !== '';
    }

    /** A human explanation for the Studio when a tool is greyed out. */
    public static function whyUnavailable(string $tool): string
    {
        if (!function_exists('curl_init')) return 'cURL is not available on this host';
        if (class_exists('AvRules') && !AvRules::bool('ai.web_access')) return 'web access is switched off in the Studio rules';
        if ($tool === 'web_search' && self::searchProvider() === '') {
            return 'no search key set (AV_BRAVE_API_KEY, AV_SERPER_API_KEY, AV_GOOGLE_CSE_KEY+CX or AV_TAVILY_API_KEY)';
        }
        return '';
    }

    /* ════════════════════════════════════════════════════════════════
       Search
       ════════════════════════════════════════════════════════════════ */

    /** @return array{results?:array,provider?:string,error?:string} */
    public static function search(string $query, int $count = 5): array
    {
        $query = trim($query);
        if ($query === '') return ['error' => 'Give something to search for.'];
        if (!self::available('web_search')) return ['error' => 'Web search is unavailable: ' . self::whyUnavailable('web_search') . '.'];
        $count = max(1, min(10, $count));

        $p = self::searchProvider();
        try {
            switch ($p) {
                case 'brave':  $r = self::braveSearch($query, $count); break;
                case 'serper': $r = self::serperSearch($query, $count); break;
                case 'google': $r = self::googleSearch($query, $count); break;
                case 'tavily': $r = self::tavilySearch($query, $count); break;
                default:       return ['error' => 'No search provider configured.'];
            }
        } catch (Throwable $e) {
            error_log('[web] search: ' . $e->getMessage());
            return ['error' => 'The search failed.'];
        }
        if (isset($r['error'])) return $r;
        return [
            'provider' => $p,
            'results'  => $r['results'],
            'note'     => 'These are snippets, not sources. web_fetch a page before relying on what it says, and cite the URL.',
        ];
    }

    private static function braveSearch(string $q, int $n): array
    {
        $url = 'https://api.search.brave.com/res/v1/web/search?q=' . rawurlencode($q) . '&count=' . $n;
        $res = self::apiGet($url, ['Accept: application/json', 'X-Subscription-Token: ' . self::cfg('AV_BRAVE_API_KEY')]);
        if (isset($res['__error'])) return ['error' => $res['__error']];
        $out = [];
        foreach ((array) ($res['web']['results'] ?? []) as $r) {
            $out[] = self::hit((string) ($r['title'] ?? ''), (string) ($r['url'] ?? ''), (string) ($r['description'] ?? ''));
        }
        return ['results' => array_slice($out, 0, $n)];
    }

    private static function serperSearch(string $q, int $n): array
    {
        $res = self::apiPost('https://google.serper.dev/search',
            ['X-API-KEY: ' . self::cfg('AV_SERPER_API_KEY'), 'Content-Type: application/json'],
            ['q' => $q, 'num' => $n]);
        if (isset($res['__error'])) return ['error' => $res['__error']];
        $out = [];
        foreach ((array) ($res['organic'] ?? []) as $r) {
            $out[] = self::hit((string) ($r['title'] ?? ''), (string) ($r['link'] ?? ''), (string) ($r['snippet'] ?? ''));
        }
        return ['results' => array_slice($out, 0, $n)];
    }

    private static function googleSearch(string $q, int $n): array
    {
        $url = 'https://www.googleapis.com/customsearch/v1?key=' . rawurlencode(self::cfg('AV_GOOGLE_CSE_KEY'))
             . '&cx=' . rawurlencode(self::cfg('AV_GOOGLE_CSE_CX'))
             . '&q=' . rawurlencode($q) . '&num=' . $n;
        $res = self::apiGet($url, ['Accept: application/json']);
        if (isset($res['__error'])) return ['error' => $res['__error']];
        $out = [];
        foreach ((array) ($res['items'] ?? []) as $r) {
            $out[] = self::hit((string) ($r['title'] ?? ''), (string) ($r['link'] ?? ''), (string) ($r['snippet'] ?? ''));
        }
        return ['results' => array_slice($out, 0, $n)];
    }

    private static function tavilySearch(string $q, int $n): array
    {
        $res = self::apiPost('https://api.tavily.com/search',
            ['Content-Type: application/json'],
            ['api_key' => self::cfg('AV_TAVILY_API_KEY'), 'query' => $q, 'max_results' => $n]);
        if (isset($res['__error'])) return ['error' => $res['__error']];
        $out = [];
        foreach ((array) ($res['results'] ?? []) as $r) {
            $out[] = self::hit((string) ($r['title'] ?? ''), (string) ($r['url'] ?? ''), (string) ($r['content'] ?? ''));
        }
        return ['results' => array_slice($out, 0, $n)];
    }

    private static function hit(string $title, string $url, string $snippet): array
    {
        return [
            'title'   => mb_substr(trim($title), 0, 300),
            'url'     => trim($url),
            'snippet' => mb_substr(trim(html_entity_decode(strip_tags($snippet), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 500),
        ];
    }

    /* ════════════════════════════════════════════════════════════════
       Fetch
       ════════════════════════════════════════════════════════════════ */

    /**
     * Fetch a page and return its readable text.
     *
     * Redirects are followed by hand so every hop is re-validated — letting cURL
     * follow them would check only the first URL, which is the standard way an
     * SSRF filter gets walked past.
     *
     * @return array{url?:string,title?:string,text?:string,truncated?:bool,error?:string}
     */
    public static function fetch(string $url): array
    {
        if (!self::available('web_fetch')) return ['error' => 'Web fetching is unavailable: ' . self::whyUnavailable('web_fetch') . '.'];

        $url = trim($url);
        $seen = [];
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $guard = self::guard($url);
            if (isset($guard['error'])) return $guard;
            if (isset($seen[$url])) return ['error' => 'Redirect loop.'];
            $seen[$url] = true;

            $r = self::rawGet($url);
            if (isset($r['error'])) return $r;

            if ($r['status'] >= 300 && $r['status'] < 400 && $r['location'] !== '') {
                $next = self::resolveUrl($url, $r['location']);
                if ($next === '') return ['error' => 'Unfollowable redirect.'];
                $url = $next;
                continue;
            }
            if ($r['status'] >= 400) return ['error' => 'The page returned HTTP ' . $r['status'] . '.'];

            $ct = strtolower($r['content_type']);
            if ($ct !== '' && strpos($ct, 'html') === false && strpos($ct, 'text') === false
                && strpos($ct, 'json') === false && strpos($ct, 'xml') === false) {
                return ['error' => 'That is not a readable text page (' . $ct . ').'];
            }

            $title = self::extractTitle($r['body']);
            $text  = self::htmlToText($r['body']);
            if ($text === '') return ['error' => 'No readable text on that page.'];
            $trunc = mb_strlen($text) > self::MAX_TEXT;

            return [
                'url'       => $url,
                'title'     => $title,
                'text'      => $trunc ? mb_substr($text, 0, self::MAX_TEXT) . '…' : $text,
                'truncated' => $trunc,
            ];
        }
        return ['error' => 'Too many redirects.'];
    }

    /**
     * Refuse anything that is not a public http(s) URL.
     *
     * Every resolved address is checked, not just the first: a hostname can carry
     * several A records, and a filter that inspects one of them is not a filter.
     */
    private static function guard(string $url): array
    {
        $p = parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) return ['error' => 'That is not a usable URL.'];
        $scheme = strtolower((string) $p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') return ['error' => 'Only http and https URLs can be fetched.'];
        if (!empty($p['user']) || !empty($p['pass'])) return ['error' => 'URLs with embedded credentials are refused.'];

        $host = (string) $p['host'];
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443, 8080, 8443], true)) return ['error' => 'That port is not allowed.'];

        // A bare IP is checked directly; a name is resolved and every answer checked.
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            if (!preg_match('/^[a-z0-9.\-]+$/i', $host)) return ['error' => 'That hostname is not valid.'];
            $recs = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
            foreach ($recs as $r) {
                if (!empty($r['ip']))   $ips[] = (string) $r['ip'];
                if (!empty($r['ipv6'])) $ips[] = (string) $r['ipv6'];
            }
            if (!$ips) {
                $one = gethostbyname($host);
                if ($one !== $host) $ips[] = $one;
            }
            if (!$ips) return ['error' => 'That hostname does not resolve.'];
        }
        foreach ($ips as $ip) {
            if (!self::publicIp($ip)) {
                return ['error' => 'That address is on a private or reserved network and will not be fetched.'];
            }
        }
        return ['ok' => true];
    }

    /** True only for a genuinely public, routable address. */
    private static function publicIp(string $ip): bool
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) return false;
        // FILTER_FLAG_NO_RES_RANGE misses some ranges that matter to us, so the
        // metadata address and IPv4-mapped IPv6 forms are rejected explicitly.
        if (strpos($ip, '169.254.') === 0) return false;
        if (stripos($ip, '::ffff:') === 0) {
            $v4 = substr($ip, 7);
            return filter_var($v4, FILTER_VALIDATE_IP, $flags) !== false && strpos($v4, '169.254.') !== 0;
        }
        return true;
    }

    /** One HTTP GET, no redirect following, bounded body. */
    private static function rawGet(string $url): array
    {
        if (!function_exists('curl_init')) return ['error' => 'cURL is unavailable.'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,          // validated per hop instead
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'AfrovanguardBot/1.0 (+https://afrovanguard.org.ng)',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,text/plain,application/json;q=0.9,*/*;q=0.5'],
            CURLOPT_COOKIEFILE     => '',             // send no cookies, keep none
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // Stop reading once the cap is passed rather than buffering a whole
            // multi-megabyte page into memory.
            CURLOPT_BUFFERSIZE     => 16384,
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => static function ($ch, $dlTotal, $dlNow) {
                return $dlNow > self::MAX_BYTES ? 1 : 0;
            },
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $ctyp = (string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
        $errn = curl_errno($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        // Aborting on the size cap is a success for our purposes — we keep what
        // arrived and read that.
        if (!is_string($raw) && $errn !== CURLE_ABORTED_BY_CALLBACK) {
            return ['error' => 'Could not reach that page: ' . $err];
        }
        if (!is_string($raw)) $raw = '';
        $head = substr($raw, 0, $hlen);
        $body = substr($raw, $hlen);
        $loc  = '';
        if (preg_match('/^location:\s*(.+)$/im', $head, $m)) $loc = trim($m[1]);

        return ['status' => $code, 'location' => $loc, 'content_type' => $ctyp, 'body' => $body, 'error' => null];
    }

    /** Resolve a possibly-relative redirect target against its base. */
    private static function resolveUrl(string $base, string $rel): string
    {
        if ($rel === '') return '';
        if (preg_match('#^https?://#i', $rel)) return $rel;
        $b = parse_url($base);
        if (!$b || empty($b['scheme']) || empty($b['host'])) return '';
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (strpos($rel, '//') === 0) return $b['scheme'] . ':' . $rel;
        if (strpos($rel, '/') === 0)  return $origin . $rel;
        $dir = isset($b['path']) ? preg_replace('#/[^/]*$#', '/', $b['path']) : '/';
        return $origin . $dir . $rel;
    }

    private static function extractTitle(string $html): string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            return mb_substr(trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 300);
        }
        return '';
    }

    /**
     * HTML → readable text. Not a full reader implementation, but it drops the
     * furniture (script, style, nav, header, footer, aside) that otherwise
     * dominates a page and wastes the model's context on cookie banners.
     */
    private static function htmlToText(string $html): string
    {
        if (trim($html) === '') return '';
        // JSON and plain text arrive here too — leave them be.
        $t = ltrim($html);
        if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) return trim(mb_substr($html, 0, self::MAX_TEXT * 2));

        $html = preg_replace('#<(script|style|noscript|svg|iframe|form)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(nav|header|footer|aside)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;
        // Keep block structure as newlines so paragraphs survive.
        $html = preg_replace('#</(p|div|li|h[1-6]|tr|section|article|blockquote)>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\xc2\xa0"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '') $lines[] = $line;
        }
        return trim(implode("\n", $lines));
    }

    /* ── small JSON HTTP helpers for the search APIs ───────────────── */

    private static function apiGet(string $url, array $headers): array
    {
        return self::api('GET', $url, $headers, null);
    }
    private static function apiPost(string $url, array $headers, array $body): array
    {
        return self::api('POST', $url, $headers, $body);
    }
    private static function api(string $method, string $url, array $headers, ?array $body): array
    {
        if (!function_exists('curl_init')) return ['__error' => 'cURL is unavailable.'];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if (!is_string($resp)) return ['__error' => 'transport: ' . $err];
        $d = json_decode($resp, true);
        if ($code >= 400) {
            error_log('[web] api ' . $code . ': ' . substr($resp, 0, 200));
            // Don't leak a provider's raw error (it can echo the key) to the model.
            return ['__error' => 'The search provider returned HTTP ' . $code . '.'];
        }
        return is_array($d) ? $d : ['__error' => 'Unreadable response from the search provider.'];
    }
}
