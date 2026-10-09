<?php
/**
 * lib/DiaryVisitor.php — who is reading, for counting purposes only.
 *
 * Views, applause and "only you can see this until it's reviewed" all need to
 * tell one reader from another. None of them needs to know WHO the reader is,
 * and storing something that does — an IP, a fingerprint — would turn a view
 * counter into a log of who read what.
 *
 * So a reader gets one opaque random id in a signed cookie. Everything else is
 * derived from it by HMAC with a per-purpose scope, which means the id itself
 * never reaches the database: a row in diary_comment_likes cannot be joined to
 * a row in diary_saves, and neither can be traced back to a person.
 *
 * Two small pieces of state — "which entries has this reader seen in the last
 * 30 minutes" and "how many times has this reader clapped for each entry" —
 * live in signed cookies rather than tables, which is what the spec asks for
 * and what the data is worth. A reader who clears their cookies can view and
 * clap again; no scheme short of requiring an account can prevent that, and
 * requiring an account to clap would cost far more than a few extra claps.
 */
declare(strict_types=1);

final class DiaryVisitor
{
    public const ID_COOKIE  = 'av_rv';     // the reader's opaque id
    private const MAP_TTL   = 7200;        // small signed maps live 2 hours
    private const MAP_MAX   = 24;          // …and remember at most this many entries

    private static ?string $id = null;
    private static array $maps = [];

    /** Bots that identify themselves. A view from one of these is not a reader. */
    private const BOTS = [
        'bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'embedly',
        'quora link preview', 'pinterest', 'bitlybot', 'vkshare', 'whatsapp',
        'telegrambot', 'discordbot', 'skypeuripreview', 'applebot', 'archive.org_bot',
        'headlesschrome', 'phantomjs', 'python-requests', 'curl/', 'wget', 'go-http-client',
        'lighthouse', 'pagespeed', 'gptbot', 'ccbot', 'claudebot', 'perplexitybot',
    ];

    /**
     * The reader's id, minted on first sight. Signed so a forged cookie cannot
     * pass itself off as a different reader and inflate someone's counts.
     */
    public static function id(): string
    {
        if (self::$id !== null) return self::$id;
        $raw = (string) ($_COOKIE[self::ID_COOKIE] ?? '');
        $id  = self::unsign($raw);
        if ($id === '' ) {
            $id = bin2hex(random_bytes(16));
            self::put(self::ID_COOKIE, self::sign($id), 31536000);
            $_COOKIE[self::ID_COOKIE] = self::sign($id);
        }
        return self::$id = $id;
    }

    /**
     * A per-purpose handle for this reader. Different scope, different hash:
     * the same reader is a different row in every table they touch.
     */
    public static function hash(string $scope): string
    {
        $secret = av_secret();
        return hash_hmac('sha256', $scope . '|' . self::id(), $secret !== '' ? $secret : 'av-diary');
    }

    /** True for a user agent that says it is a robot. Robots are not readers. */
    public static function isBot(): bool
    {
        $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua === '') return true;                    // no agent at all: not a browser
        foreach (self::BOTS as $needle) if (str_contains($ua, $needle)) return true;
        return false;
    }

    /** Admins read their own entries constantly; their reads are not an audience. */
    public static function isAdmin(): bool
    {
        return function_exists('av_admin_cookie_valid') && av_admin_cookie_valid();
    }

    /* ── Small signed maps (article id → number), kept in a cookie ─────────── */

    public static function map(string $name): array
    {
        if (isset(self::$maps[$name])) return self::$maps[$name];
        $v = self::unsign((string) ($_COOKIE['av_' . $name] ?? ''));
        $m = $v === '' ? [] : (json_decode($v, true) ?: []);
        return self::$maps[$name] = is_array($m) ? $m : [];
    }

    /**
     * Write one key back. Keeps the map small by dropping the oldest keys, so a
     * reader who works through the whole Diary never grows a cookie that the
     * server then refuses as an oversized header.
     */
    public static function mapSet(string $name, string $key, $value): void
    {
        $m = self::map($name);
        $m[$key] = $value;
        if (count($m) > self::MAP_MAX) $m = array_slice($m, -self::MAP_MAX, null, true);
        self::$maps[$name] = $m;
        $json = json_encode($m, JSON_UNESCAPED_SLASHES);
        self::put('av_' . $name, self::sign((string) $json), self::MAP_TTL);
    }

    /* ── Signing ──────────────────────────────────────────────────────────── */

    private static function sign(string $v): string
    {
        $secret = av_secret();
        $b = rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        return $b . '.' . hash_hmac('sha256', $b, $secret !== '' ? $secret : 'av-diary');
    }

    private static function unsign(string $raw): string
    {
        if ($raw === '' || !str_contains($raw, '.')) return '';
        [$b, $sig] = explode('.', $raw, 2);
        $secret = av_secret();
        $want = hash_hmac('sha256', $b, $secret !== '' ? $secret : 'av-diary');
        if (!hash_equals($want, $sig)) return '';
        $v = base64_decode(strtr($b, '-_', '+/'), true);
        return $v === false ? '' : $v;
    }

    private static function put(string $name, string $value, int $ttl): void
    {
        if (headers_sent()) return;
        setcookie($name, $value, [
            'expires'  => time() + $ttl,
            'path'     => '/',
            'secure'   => (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
