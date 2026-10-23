<?php
/**
 * lib/GoogleWorkspace.php — REAL Google Workspace reads (not just deep links).
 *
 * Dependency-free service-account client (same RS256-JWT → access-token pattern
 * as lib/Drive.php) that actually calls the Google REST APIs and returns live
 * data: upcoming Calendar events, files in a shared Drive folder, and — with
 * domain-wide delegation — the Workspace user directory.
 *
 * Configure (config.php or env):
 *   AV_GDRIVE_SERVICE_ACCOUNT  service-account JSON (raw) or a path to it
 *   AV_WS_SUBJECT              admin email to impersonate for the Directory API
 *                             (domain-wide delegation; falls back to ADMIN_EMAIL)
 *   AV_WS_CALENDAR_ID          calendar to read (falls back to the subject / domain)
 *   AV_WS_DRIVE_FOLDER_ID      Drive folder to list (or AV_GDRIVE_FOLDER_ID)
 *
 * Every read is best-effort: any auth/transport/permission error is logged and
 * returns [] so the member portal cleanly falls back to the launchpad + embeds.
 * Unconfigured ⇒ configured() is false and nothing is attempted.
 */
declare(strict_types=1);

final class GoogleWorkspace
{
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const SCOPE_CALENDAR  = 'https://www.googleapis.com/auth/calendar.readonly';
    const SCOPE_CALENDAR_RW = 'https://www.googleapis.com/auth/calendar';       // create/update/delete events + Meet
    const SCOPE_DRIVE     = 'https://www.googleapis.com/auth/drive.metadata.readonly';
    const SCOPE_DRIVE_RO  = 'https://www.googleapis.com/auth/drive.readonly';   // read file content (transcripts)
    const SCOPE_DIRECTORY = 'https://www.googleapis.com/auth/admin.directory.user.readonly';
    const SCOPE_GROUPS    = 'https://www.googleapis.com/auth/admin.directory.group.readonly';
    const SCOPE_MEET_RO   = 'https://www.googleapis.com/auth/meetings.space.readonly'; // read conference records
    const SCOPE_REPORTS   = 'https://www.googleapis.com/auth/admin.reports.audit.readonly'; // org Meet audit log
    const SCOPE_CHAT      = 'https://www.googleapis.com/auth/chat.messages.create';         // post messages AS the impersonated member
    const SCOPE_MEET_SETTINGS = 'https://www.googleapis.com/auth/meetings.space.settings';  // turn on recording/transcript/notes on a Calendar-made space
    const SCOPE_MEET_CREATED = 'https://www.googleapis.com/auth/meetings.space.created';     // open an instant Meet room with no Calendar event
    const SCOPE_PUBSUB    = 'https://www.googleapis.com/auth/pubsub';                       // drain the Meet events queue (the service account itself)

    /** @var array<string,array{v:string,exp:int}> per-request token cache, keyed by scope+subject */
    private static array $tokens = [];

    /**
     * Why the last Google call failed, in Google's own words ('' = none). Every
     * read here returns [] on failure so the portal degrades quietly — which
     * also meant "no events" and "domain-wide delegation not granted" looked
     * identical on every screen. The Setup test and System page read this.
     */
    private static string $lastError = '';
    /** The scope the last refused token was asked for — the one to add to delegation. */
    private static string $lastScope = '';
    /** curl's own words for the last call that got no answer at all — DNS, TLS, a firewall. */
    private static string $lastTransport = '';
    /** Seconds this server's clock is ahead of Google's (+) or behind (-), from Google's Date header; null = not known. */
    private static ?int $clockSkew = null;
    public static function lastError(): string { return self::$lastError; }
    public static function clockSkew(): ?int { return self::$clockSkew; }

    /** Tests only: forget minted tokens, so a scope refused later is asked for again. */
    public static function forgetTokens(): void { self::$tokens = []; self::$lastError = ''; self::$lastScope = ''; }

    /**
     * Why Google said no, and what fixes it — for the screen where somebody
     * is waiting on it (a meeting without a link, a Meet room that would not
     * open), not just the Studio's test page. '' when there is no error.
     */
    public static function explain(string $err = ''): string
    {
        $err = $err !== '' ? $err : self::$lastError;
        if (!self::configured()) return self::whyNotConfigured();
        if ($err === '') return '';
        $fix = match (true) {
            str_contains($err, 'unauthorized_client') => 'Domain-wide delegation does not grant ' . (self::$lastScope !== '' ? 'the scope ' . self::$lastScope : 'the scope this needs')
                . ' — add it in the Admin console (Security → API controls → Domain-wide delegation). Studio → Rules & AI → Setup → Test Google Workspace lists every scope to grant.',
            str_contains($err, 'could not sign') => 'The private key in the service account key cannot be used to sign. If the key is in config.php, paste the JSON exactly as Google gave it, in single quotes, without editing the \\n line breaks inside it.',
            str_contains($err, 'Invalid JWT Signature') => 'Google does not recognise the key that signed this — it was deleted or disabled in Google Cloud, or belongs to another service account. Make a new JSON key for this service account and put it in place of the old one.',
            str_contains($err, 'Invalid JWT') => 'Google refused the token’s times. This server’s clock is wrong' . (self::$clockSkew !== null ? ' — it is ' . abs(self::$clockSkew) . ' seconds ' . (self::$clockSkew > 0 ? 'ahead of' : 'behind') . ' Google' : '') . '. Ask the host to sync the server clock (NTP).',
            str_contains($err, 'certificate') || str_contains($err, 'SSL') => 'This server cannot check Google’s certificate — its CA bundle is missing or out of date. Ask the host to update the CA certificates for PHP’s curl (curl.cainfo / openssl.cafile).',
            str_contains($err, 'resolve host') || str_contains($err, 'Connection timed out') || str_contains($err, 'Failed to connect') => 'This server cannot reach Google — the host is blocking outgoing connections to googleapis.com. Ask the host to allow outgoing HTTPS to oauth2.googleapis.com and www.googleapis.com.',
            /* Google names the API and the project, and gives the page that turns it on. */
            str_contains($err, 'has not been used in project') || str_contains($err, 'SERVICE_DISABLED') || str_contains($err, 'is disabled') =>
                'That Google API is switched off in the Google Cloud project the service account belongs to. Turn it on there'
                . (preg_match('~https://console\.(?:developers|cloud)\.google\.com/\S+~', $err, $m) ? ': ' . rtrim($m[0], '.') : ' (APIs & Services → Library)')
                . '. It can take a few minutes to apply.',
            str_contains($err, 'Not Authorized to access this resource/api') => 'The “Admin to act as” account may not use the Admin APIs. It must be a Workspace administrator (a super admin, or a role with the Admin API privilege) who has signed in at least once and accepted the terms.',
            str_contains($err, 'insufficient') && str_contains($err, 'scope') => 'The token was granted without the scope this needs — add it to the client ID’s scopes in Admin console → Security → API controls → Domain-wide delegation.',
            str_contains($err, 'invalid_grant') && (str_contains($err, 'account') || str_contains($err, 'email') || str_contains($err, 'sub')) => 'The “Admin to act as” address is not a user in your Workspace. Set it in Studio → Rules & AI → Setup to a real administrator.',
            str_contains($err, 'invalid_grant') => 'Google refused the service account key — it may have been deleted or rotated. Paste a fresh JSON key in Studio → Rules & AI → Setup.',
            str_contains($err, 'forbiddenForServiceAccounts') => 'A service account cannot invite people on its own. Set “Admin to act as” in Studio → Rules & AI → Setup, and grant it domain-wide delegation.',
            str_contains($err, 'Invalid conference type') => 'Google Meet is not enabled for the calendar being written to. Turn Meet on for that account in the Admin console.',
            str_contains($err, 'HTTP 404') || str_contains($err, 'notFound') => 'Google could not find the calendar or file. Check “Shared calendar” in Studio → Rules & AI → Setup, and that it is shared with the admin.',
            str_contains($err, 'HTTP 403') => 'Google refused permission. The account may lack the licence or the API may be off in Google Cloud for this project.',
            str_contains($err, 'no response') => 'Google could not be reached from this server (network or TLS).',
            default => 'Studio → Rules & AI → Setup → Test Google Workspace shows what to change.',
        };
        return 'Google said: ' . $err . '. ' . $fix;
    }

    /** Google's error body → one line: "unauthorized_client: Client is unauthorized …". */
    private static function errorLine(?array $res): string
    {
        if (!$res) return 'no response from Google (network or TLS)' . (self::$lastTransport !== '' ? ': ' . self::$lastTransport : '');
        $j = $res['json'] ?? null;
        if (is_array($j)) {
            if (is_string($j['error'] ?? null)) return $j['error'] . (!empty($j['error_description']) ? ': ' . (string) $j['error_description'] : '');
            if (is_array($j['error'] ?? null)) return 'HTTP ' . (int) $res['code'] . ': ' . (string) ($j['error']['message'] ?? $j['error']['status'] ?? '');
        }
        return 'HTTP ' . (int) ($res['code'] ?? 0);
    }

    /**
     * Remember Google's latest refusal, and its latest success, where the
     * portal can show an administrator what is wrong (GoogleHub::problem) —
     * a failure here used to reach only an error log on a shared host, and
     * every screen showed the same calm "nothing yet".
     */
    private static function noteFailure(): void
    {
        if (class_exists('GoogleAuth')) GoogleAuth::recordFailure('workspace', self::$lastError . (self::$lastScope !== '' ? ' [' . self::$lastScope . ']' : ''));
    }
    private static function noteOk(): void
    {
        static $done = false; if ($done || !class_exists('Database')) return; $done = true;
        try {
            $last = (string) (Database::metaGet('google_last_ok') ?? '');
            if ($last === '' || strtotime($last) < time() - 3600) Database::metaSet('google_last_ok', gmdate('c'));
        } catch (Throwable $e) {}
    }

    public static function configured(): bool { return self::credentials() !== null; }

    /**
     * Why it is not set up — which is three different things.
     *
     * "Not set up" was one sentence telling an administrator to add the
     * key. That is the right advice for an empty site and useless for the
     * one that broke: the key IS there, the site cannot read it back, and
     * being told to add what you already added teaches you the screen is
     * lying. Worse, when there is no APP_KEY at all, saving refuses too —
     * so the advice sends somebody round a loop they cannot leave.
     */
    public static function whyNotConfigured(): string
    {
        if (self::configured()) return '';

        /* The two states that belong to the settings store rather than to
           Google — crypto unavailable, and stored-but-undecryptable. Shared,
           because every encrypted setting has them and each screen that
           re-derived them got one of them wrong. */
        if (class_exists('AvSettings')) {
            $why = AvSettings::whyUnreadable('AV_GDRIVE_SERVICE_ACCOUNT', 'service account key');
            if ($why !== '') return $why . ' Nothing else needs to change at Google.';
        }

        /* Stored as a path rather than pasted JSON — a deployment that
           points at a file the web user cannot read says nothing at all
           otherwise. */
        /* config.php, read for what it says rather than for what PHP made of
           it: a key defined twice, or under another name, is right there in
           the file and invisible to everything that asks the constant. */
        $inFile = self::configFileFinding();
        if ($inFile !== '') return $inFile;

        $got = Config::get('AV_GDRIVE_SERVICE_ACCOUNT', '');
        $raw = is_array($got) ? '{' : trim((string) $got);
        if ($raw !== '' && !str_starts_with($raw, '{')) {
            return is_file($raw)
                ? 'The Google Workspace key points at '.$raw.', which this site cannot read. '
                    .'Check the file\'s permissions, or paste the JSON into Studio → Rules & AI → Setup instead.'
                : 'The Google Workspace key points at '.$raw.', and there is no file there. '
                    .'Paste the JSON into Studio → Rules & AI → Setup instead.';
        }
        if ($raw !== '') {
            /* Say WHERE the unusable key is. Told to paste it into the
               Studio, somebody whose key is in config.php went to a screen
               that was not the problem. */
            $inConfig = (class_exists('AvSettings') ? AvSettings::get('AV_GDRIVE_SERVICE_ACCOUNT') === null : true)
                && defined('AV_GDRIVE_SERVICE_ACCOUNT') && (string) constant('AV_GDRIVE_SERVICE_ACCOUNT') !== '';
            $c = json_decode($raw, true);
            $what = !is_array($c) ? 'it is not complete JSON (often a key cut short, or a quote inside it that ends the PHP string early)'
                : (empty($c['private_key']) ? 'it has no private_key' : (empty($c['client_email']) ? 'it has no client_email' : 'it cannot be read'));
            return $inConfig
                ? 'The key in config.php (AV_GDRIVE_SERVICE_ACCOUNT) is not a usable service-account key: ' . $what . '. '
                    . 'Paste the downloaded JSON file\'s contents whole, inside single quotes — define(\'AV_GDRIVE_SERVICE_ACCOUNT\', \'{…}\'); — '
                    . 'or paste it into Studio → Rules & AI → Setup, which takes precedence.'
                : 'The stored Google Workspace key is not a usable service-account key: ' . $what . '. Download a fresh JSON key and paste it unedited '
                    . 'into Studio → Rules & AI → Setup.';
        }

        $how = 'an administrator adds the service account key in Studio → Rules & AI → Setup → Google sign-in & Workspace.';
        /* Say whether a key was ever saved here. "Not set up" after somebody
           pressed Save is the report this whole area kept producing, and the
           first question is always whether the save reached the database. */
        if (class_exists('AvSettings') && AvSettings::storedRow('AV_GDRIVE_SERVICE_ACCOUNT') === null) {
            return 'Google Workspace is not set up on this site: no service account key has ever been saved here '
                .'(if you pressed Save, it did not reach the database — save again and read the message beside the button). '
                .'To set it up, ' . $how;
        }

        return 'Google Workspace is not set up on this site: ' . $how;
    }

    /** Full write sync is opt-in (creates real calendar events + Meet links). */
    public static function calendarWriteEnabled(): bool
    {
        if (!self::configured()) return false;
        $v = (string) Config::get('AV_WS_CALENDAR_WRITE', '1'); // default on when configured
        return $v !== '0' && strtolower($v) !== 'false';
    }

    /** OAuth token endpoint (overridable for testing/proxying). */
    private static function tokenUrl(): string { return (string) (Config::get('AV_WS_TOKEN_URL', '') ?: self::TOKEN_URL); }
    /** Google API host prefix (overridable for testing). */
    private static function apiBase(): string { return rtrim((string) (Config::get('AV_WS_BASE_URL', '') ?: 'https://www.googleapis.com'), '/'); }
    /** Meet REST API host (own host; overridable for testing). */
    private static function meetBase(): string { return rtrim((string) (Config::get('AV_MEET_BASE_URL', '') ?: 'https://meet.googleapis.com'), '/'); }
    /** Admin SDK Reports API host (overridable for testing). */
    private static function reportsBase(): string { return rtrim((string) (Config::get('AV_REPORTS_BASE_URL', '') ?: 'https://admin.googleapis.com'), '/'); }

    private static function credentials(): ?array
    {
        return self::parseKey(Config::get('AV_GDRIVE_SERVICE_ACCOUNT', ''));
    }

    /**
     * A service-account key as config.php, the environment or the Studio hold it:
     * the JSON itself, a path to the file, or (in config.php) a PHP array.
     * Shared with lib/Drive.php, so both read a key the same way.
     *
     * One repair, and only one: the JSON written into config.php inside DOUBLE
     * quotes. PHP turns every \n in the private key into a real line break,
     * which JSON forbids, so the key was correct and unreadable. Line breaks
     * inside string values are put back as \n; nothing else is changed.
     */
    public static function parseKey($v): ?array
    {
        if (is_array($v)) {
            $c = $v;
        } else {
            $v = trim((string) $v);
            if ($v === '') return null;
            $json = str_starts_with($v, '{') ? $v : (is_file($v) ? (string) @file_get_contents($v) : '');
            $c = json_decode($json, true);
            if (!is_array($c) && $json !== '') {
                $fixed = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"/s',
                    static fn ($m) => str_replace(["\r\n", "\r", "\n", "\t"], ['\n', '\n', '\n', '\t'], $m[0]), $json);
                $c = is_string($fixed) ? json_decode($fixed, true) : null;
            }
        }

        return (is_array($c) && !empty($c['client_email']) && !empty($c['private_key'])) ? $c : null;
    }

    /** Tests point this at a sample config.php; null reads the site's own. */
    public static ?string $configPath = null;

    /**
     * What is wrong with the key in config.php, read from the file's SOURCE —
     * names and line numbers only, never a value. '' when config.php is not
     * the explanation.
     *
     * The common mistake is invisible at runtime: config.example.php already
     * has define('AV_GDRIVE_SERVICE_ACCOUNT', getenv(…) ?: ''), so a key
     * defined again further down is ignored — PHP keeps the first definition,
     * the empty one — and the site says "not set up" to somebody looking at the
     * key in the file.
     */
    public static function configFileFinding(): string
    {
        $state = self::$configPath !== null || !function_exists('av_config_state')
            ? ['present' => true, 'loaded' => true, 'reason' => ''] : av_config_state();
        $file = self::$configPath ?? ((defined('AV_ROOT') ? AV_ROOT : dirname(__DIR__)) . '/config.php');
        if (empty($state['present'])) return '';
        if (empty($state['loaded'])) {
            return 'config.php was not read at all on this request (' . (string) $state['reason'] . '), so nothing in it — the key included — applies.';
        }
        $src = (string) @file_get_contents($file);
        if ($src === '') return '';
        $line = static fn (int $off): int => substr_count(substr($src, 0, $off), "\n") + 1;

        preg_match_all('/define\s*\(\s*[\'"]AV_GDRIVE_SERVICE_ACCOUNT[\'"]/', $src, $m, PREG_OFFSET_CAPTURE);
        $at = array_map(static fn ($x) => $line((int) $x[1]), $m[0]);
        if (count($at) > 1) {
            return 'config.php defines AV_GDRIVE_SERVICE_ACCOUNT ' . count($at) . ' times (lines ' . implode(', ', $at) . '). '
                . 'PHP keeps only the FIRST, on line ' . $at[0] . ', and ignores the rest — so if your key is on a later line, '
                . 'it is never used. Put the key on line ' . $at[0] . ' in place of the empty value, and delete the other definitions.';
        }
        if (count($at) === 1) {
            $raw = defined('AV_GDRIVE_SERVICE_ACCOUNT') ? constant('AV_GDRIVE_SERVICE_ACCOUNT') : null;
            if ($raw === '' || $raw === null) {
                return 'config.php defines AV_GDRIVE_SERVICE_ACCOUNT on line ' . $at[0] . ', but its value is empty here. '
                    . 'If that is the template line, define(\'AV_GDRIVE_SERVICE_ACCOUNT\', getenv(…) ?: \'\'), the key goes in place '
                    . 'of the final \'\' — in single quotes, pasted whole.';
            }
        }
        if (!$at) {
            if (preg_match('/^\s*\$(\w+)\s*=[^;]*service_account/mi', $src, $v, PREG_OFFSET_CAPTURE)) {
                return 'config.php puts a service account key in the variable $' . $v[1][0] . ' (line ' . $line((int) $v[0][1])
                    . '), but the site reads the constant AV_GDRIVE_SERVICE_ACCOUNT. Use define(\'AV_GDRIVE_SERVICE_ACCOUNT\', \'…the JSON…\');';
            }
            if (preg_match('/define\s*\(\s*[\'"](\w+)[\'"]\s*,[^;]*service_account/i', $src, $v, PREG_OFFSET_CAPTURE)) {
                return 'config.php has a service account key under ' . $v[1][0] . ' (line ' . $line((int) $v[0][1])
                    . '), but the site reads AV_GDRIVE_SERVICE_ACCOUNT. Rename it to that.';
            }
        }

        return '';
    }

    /** Admin to impersonate for delegated APIs (Directory). */
    private static function subject(): string
    {
        $s = trim((string) Config::get('AV_WS_SUBJECT', ''));
        if ($s !== '') return $s;
        /* ADMIN_EMAIL is where the site's mail goes, often a personal or a
           shared mailbox outside Workspace. Impersonating one of those fails
           with "invalid_grant: Invalid email or User ID"; it is only a sound
           guess when it is an organisation address. */
        $a = trim((string) Config::get('ADMIN_EMAIL', ''));
        return ($a !== '' && class_exists('LmsAuth') && LmsAuth::isOrgEmail($a)) ? $a : '';
    }

    private static function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

    /**
     * The private key as openssl reads it. A key pasted into config.php with
     * its "\n" escapes doubled arrives as one line with literal backslash-n
     * in it, which no PEM reader accepts; those are put back as line breaks.
     */
    public static function privateKey(string $pem): ?string
    {
        $pem = trim($pem);
        if (!str_contains($pem, "\n") && str_contains($pem, '\\n')) $pem = str_replace(['\\r\\n', '\\n'], "\n", $pem);
        $pem = str_replace("\r\n", "\n", $pem);
        return openssl_pkey_get_private($pem) !== false ? $pem : null;
    }

    /**
     * Mint (per-request cache) an access token for one scope. $impersonate adds
     * the `sub` claim (required for Directory; harmless for Calendar/Drive on
     * resources the impersonated user can see). Returns null on any failure.
     */
    private static function accessToken(string $scope, bool $impersonate = false, ?string $asUser = null): ?string
    {
        $c = self::credentials();
        if (!$c) return null;
        // An explicit $asUser wins (impersonate that specific mailbox — required
        // by the Meet API, which only exposes records to a conference host/guest).
        $sub = $asUser !== null && $asUser !== '' ? $asUser : ($impersonate ? self::subject() : '');
        $key = $scope . '|' . $sub;
        if (isset(self::$tokens[$key]) && self::$tokens[$key]['exp'] > time() + 30) return self::$tokens[$key]['v'];

        $now = time();
        /* Issued a minute in the past, valid for the hour Google allows. A
           shared host's clock a few seconds fast made every token "issued in
           the future", and Google refuses those outright (invalid_grant:
           Invalid JWT). Google's own clients allow for the same skew. */
        $iat = $now - 60;
        $claim = ['iss' => $c['client_email'], 'scope' => $scope, 'aud' => self::TOKEN_URL, 'iat' => $iat, 'exp' => $iat + 3600];
        if ($sub !== '') $claim['sub'] = $sub;
        $header = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = self::b64url(json_encode($claim));
        $sig = '';
        $pk = self::privateKey((string) $c['private_key']);
        while (openssl_error_string() !== false) {}            // only this call's errors below
        if ($pk === null || !openssl_sign($header . '.' . $payload, $sig, $pk, 'sha256WithRSAEncryption')) {
            /* Said, not swallowed: this used to return with no reason at all,
               and the test showed a failure nobody could act on. */
            $why = (string) (openssl_error_string() ?: 'the key is not a PEM private key');
            self::$lastError = 'could not sign with the service account’s private key (' . $why . ')';
            self::$lastScope = $scope;
            self::noteFailure();
            return null;
        }
        $assertion = $header . '.' . $payload . '.' . self::b64url($sig);

        $res = self::http('POST', self::tokenUrl(), null, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion,
        ]));
        if (!$res || empty($res['json']['access_token'])) {
            if ($res) error_log('[workspace] token error ' . $res['code'] . ': ' . substr((string) $res['body'], 0, 200));
            self::$lastError = 'token' . ($sub !== '' ? ' (as ' . $sub . ')' : '') . ' — ' . self::errorLine($res);
            self::$lastScope = $scope;
            self::noteFailure();
            return null;
        }
        self::$tokens[$key] = ['v' => (string) $res['json']['access_token'], 'exp' => $now + (int) ($res['json']['expires_in'] ?? 3600)];
        return self::$tokens[$key]['v'];
    }

    /** Minimal curl wrapper → ['code'=>int,'body'=>string,'json'=>?array] or null on transport error.
     *  $jsonBody, when given, is sent as an application/json request body (POST/PATCH). */
    private static function http(string $method, string $url, ?string $bearer, ?string $postBody = null, ?string $jsonBody = null): ?array
    {
        if (!function_exists('curl_init')) return null;

        /* A call must fit inside whatever budget the caller set, and a host
           that accepts nothing must not cost the full timeout. probe() makes
           sixteen of these in a row: at 15s each with no connect timeout that
           is four minutes, against a shared host that stops the request at
           thirty seconds — so the Studio's test button returned nothing at
           all, which is not a result anybody can act on. */
        $timeout = 15;
        if (self::$deadline !== null) {
            $left = self::$deadline - microtime(true);
            if ($left <= 0.5) {
                self::$lastError = 'ran out of time before this call could be made';
                return null;
            }
            $timeout = max(1, (int) floor(min(15, $left)));
        }

        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_HTTPHEADER => &$headers];
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $opt[CURLOPT_CUSTOMREQUEST] = $method;
            $opt[CURLOPT_POSTFIELDS] = $jsonBody;
        } elseif ($method === 'POST') {
            $opt[CURLOPT_POST] = true; if ($postBody !== null) $opt[CURLOPT_POSTFIELDS] = $postBody;
        } elseif ($method !== 'GET') {
            $opt[CURLOPT_CUSTOMREQUEST] = $method;
        }
        /* Google's Date header: how far this server's clock is from Google's,
           which is the whole story behind "Invalid JWT". */
        $opt[CURLOPT_HEADERFUNCTION] = static function ($ch, string $h): int {
            if (stripos($h, 'Date:') === 0 && ($t = strtotime(trim(substr($h, 5)))) !== false) self::$clockSkew = time() - $t;
            return strlen($h);
        };
        curl_setopt_array($ch, $opt);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) {
            error_log('[workspace] transport: ' . $err);
            /* Kept for the screen too: "network or TLS" alone sends somebody
               guessing; curl's sentence names DNS, the certificate or the firewall. */
            self::$lastTransport = mb_substr($err, 0, 200);
            return null;
        }
        self::$lastTransport = '';
        return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
    }

    private static function apiGet(string $url, string $scope, bool $impersonate, ?string $asUser = null): ?array
    {
        $tok = self::accessToken($scope, $impersonate, $asUser);
        if (!$tok) return null;
        $res = self::http('GET', $url, $tok);
        if (!$res || $res['code'] >= 400) {
            if ($res) error_log('[workspace] GET ' . $url . ' → ' . $res['code'] . ': ' . substr((string) $res['body'], 0, 200));
            self::$lastError = 'GET ' . (string) parse_url($url, PHP_URL_PATH) . ' — ' . self::errorLine($res);
            self::noteFailure();
            return null;
        }
        self::noteOk();
        return is_array($res['json']) ? $res['json'] : null;
    }

    /** POST/PATCH/DELETE a JSON body to a Google API. Returns the decoded body or null. */
    private static function apiSend(string $method, string $url, string $scope, bool $impersonate, ?array $body = null, ?string $asUser = null): ?array
    {
        $tok = self::accessToken($scope, $impersonate, $asUser);
        if (!$tok) return null;
        $res = self::http($method, $url, $tok, null, $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ($method === 'DELETE' ? '' : '{}'));
        if (!$res || $res['code'] >= 400) {
            if ($res) error_log('[workspace] ' . $method . ' ' . $url . ' → ' . $res['code'] . ': ' . substr((string) $res['body'], 0, 300));
            self::$lastError = $method . ' ' . (string) parse_url($url, PHP_URL_PATH) . ' — ' . self::errorLine($res);
            self::noteFailure();
            return null;
        }
        self::noteOk();
        return is_array($res['json']) ? $res['json'] : ($res['code'] < 300 ? ['ok' => true] : null);
    }

    /* ── live reads (normalised; [] on any problem) ─────────────── */

    /** Upcoming events from a calendar, soonest first. */
    public static function calendarEvents(?string $calendarId = null, int $max = 8): array
    {
        if (!self::configured()) return [];
        $cal = $calendarId ?: (string) (Config::get('AV_WS_CALENDAR_ID', '') ?: self::subject());
        if ($cal === '') return [];
        $q = http_build_query([
            'timeMin' => gmdate('Y-m-d\TH:i:s\Z'), 'singleEvents' => 'true', 'orderBy' => 'startTime',
            'maxResults' => max(1, min(50, $max)),
        ]);
        $url = self::apiBase() . '/calendar/v3/calendars/' . rawurlencode($cal) . '/events?' . $q;
        $d = self::apiGet($url, self::SCOPE_CALENDAR, self::subject() !== '');
        if (!$d || empty($d['items'])) return [];
        $out = [];
        foreach ($d['items'] as $e) {
            $start = $e['start']['dateTime'] ?? ($e['start']['date'] ?? '');
            $out[] = [
                'id'       => (string) ($e['id'] ?? ''),
                'title'    => (string) ($e['summary'] ?? '(busy)'),
                'start'    => $start,
                'end'      => (string) ($e['end']['dateTime'] ?? ($e['end']['date'] ?? '')),
                'all_day'  => !isset($e['start']['dateTime']),
                'location' => (string) ($e['location'] ?? ''),
                'url'      => (string) ($e['htmlLink'] ?? ''),
                'meet'     => (string) ($e['hangoutLink'] ?? self::meetFrom($e)),
            ];
        }
        return $out;
    }

    /** Files in a shared Drive folder, most-recently-modified first. */
    public static function driveFiles(?string $folderId = null, int $max = 12): array
    {
        if (!self::configured()) return [];
        $folder = $folderId ?: (string) (Config::get('AV_WS_DRIVE_FOLDER_ID', '') ?: Config::get('AV_GDRIVE_FOLDER_ID', ''));
        if ($folder === '') return [];
        $q = http_build_query([
            'q' => "'" . str_replace("'", '', $folder) . "' in parents and trashed = false",
            'fields' => 'files(id,name,mimeType,modifiedTime,webViewLink,iconLink)',
            'orderBy' => 'modifiedTime desc', 'pageSize' => max(1, min(100, $max)),
            'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
        ]);
        $d = self::apiGet(self::apiBase() . '/drive/v3/files?' . $q, self::SCOPE_DRIVE, self::subject() !== '');
        if (!$d || empty($d['files'])) return [];
        $out = [];
        foreach ($d['files'] as $f) {
            $out[] = [
                'name'     => (string) ($f['name'] ?? 'Untitled'),
                'mime'     => (string) ($f['mimeType'] ?? ''),
                'modified' => (string) ($f['modifiedTime'] ?? ''),
                'url'      => (string) ($f['webViewLink'] ?? ''),
                'icon'     => (string) ($f['iconLink'] ?? ''),
            ];
        }
        return $out;
    }

    /** Org users from the Directory API (needs domain-wide delegation + AV_WS_SUBJECT). */
    public static function directoryUsers(int $max = 200): array
    {
        if (!self::configured() || self::subject() === '') return [];
        $domain = (string) Config::get('AV_ORG_DOMAIN', 'afrovanguard.org.ng');
        /* admin_view, not domain_public: `suspended` and `isAdmin` are admin-only
           fields, so under domain_public every user read as not suspended and
           the sync gave suspended staff a member account. Paged: the API returns
           at most 500 a page, and an organisation past that lost the rest. */
        $users = []; $page = '';
        do {
            $q = http_build_query(['domain' => $domain, 'maxResults' => max(1, min(500, $max - count($users))), 'orderBy' => 'email', 'viewType' => 'admin_view']
                + ($page !== '' ? ['pageToken' => $page] : []));
            $d = self::apiGet(self::reportsBase() . '/admin/directory/v1/users?' . $q, self::SCOPE_DIRECTORY, true);
            if (!$d) break;
            foreach ((array) ($d['users'] ?? []) as $u) $users[] = $u;
            $page = (string) ($d['nextPageToken'] ?? '');
        } while ($page !== '' && count($users) < $max);
        if (!$users) return [];
        $out = [];
        foreach ($users as $u) {
            $out[] = [
                'name'    => (string) ($u['name']['fullName'] ?? ''),
                'email'   => (string) ($u['primaryEmail'] ?? ''),
                'photo'   => (string) ($u['thumbnailPhotoUrl'] ?? ''),
                'is_admin'=> !empty($u['isAdmin']),
                'suspended'=> !empty($u['suspended']),
            ];
        }
        return $out;
    }

    /* ── live writes (Calendar + Google Meet) ───────────────────────
     * Create a real calendar event with a Meet conference, invite the
     * attendees, and return the ids + join link. Impersonates the subject so
     * the event lives on a real Workspace calendar and Meet is provisioned.
     * Returns ['id','html_link','meet_url'] or null on any failure. */
    public static function createMeetEvent(string $title, string $startIso, int $durationMin, array $attendeeEmails = [], string $description = '', ?string $calendarId = null, bool $withMeet = true, array $recurrence = []): ?array
    {
        if (!self::calendarWriteEnabled()) return null;
        $cal = $calendarId ?: (string) (Config::get('AV_WS_CALENDAR_ID', '') ?: self::subject());
        if ($cal === '') return null;
        $startTs = strtotime($startIso) ?: time();
        $endTs   = $startTs + max(5, $durationMin) * 60;
        $tz      = (string) Config::get('AV_WS_TZ', 'Africa/Lagos');
        $event = [
            'summary'     => mb_substr($title, 0, 200),
            'description' => mb_substr($description, 0, 4000),
            'start'       => ['dateTime' => gmdate('c', $startTs), 'timeZone' => $tz],
            'end'         => ['dateTime' => gmdate('c', $endTs), 'timeZone' => $tz],
        ];
        // Recurring meeting: an array of RRULE strings (e.g. ['RRULE:FREQ=WEEKLY']).
        if ($recurrence) $event['recurrence'] = array_values(array_filter(array_map('strval', $recurrence)));
        $att = [];
        foreach ($attendeeEmails as $e) { $e = trim((string) $e); if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $att[] = ['email' => $e]; }
        if ($att) $event['attendees'] = $att;
        $qs = ['sendUpdates' => 'all'];
        if ($withMeet) {
            $event['conferenceData'] = ['createRequest' => [
                'requestId' => bin2hex(random_bytes(8)),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ]];
            $qs['conferenceDataVersion'] = '1';
        }
        $url = self::apiBase() . '/calendar/v3/calendars/' . rawurlencode($cal) . '/events?' . http_build_query($qs);
        $d = self::apiSend('POST', $url, self::SCOPE_CALENDAR_RW, self::subject() !== '', $event);
        if (!$d || empty($d['id'])) return null;
        return [
            'id'        => (string) $d['id'],
            'html_link' => (string) ($d['htmlLink'] ?? ''),
            'meet_url'  => (string) ($d['hangoutLink'] ?? self::meetFrom($d)),
        ];
    }

    /** Pull the Meet join URL out of an event's conferenceData entry points. */
    private static function meetFrom(array $event): string
    {
        foreach (($event['conferenceData']['entryPoints'] ?? []) as $ep) {
            if (($ep['entryPointType'] ?? '') === 'video' && !empty($ep['uri'])) return (string) $ep['uri'];
        }
        return '';
    }

    /** Patch an existing event (time/title/attendees). Returns true on success. */
    public static function updateCalendarEvent(string $eventId, array $patch, ?string $calendarId = null): bool
    {
        if (!self::calendarWriteEnabled() || $eventId === '') return false;
        $cal = $calendarId ?: (string) (Config::get('AV_WS_CALENDAR_ID', '') ?: self::subject());
        if ($cal === '') return false;
        $url = self::apiBase() . '/calendar/v3/calendars/' . rawurlencode($cal) . '/events/' . rawurlencode($eventId) . '?sendUpdates=all';
        return self::apiSend('PATCH', $url, self::SCOPE_CALENDAR_RW, self::subject() !== '', $patch) !== null;
    }

    /**
     * Create a plain calendar event (no Meet) on the org calendar — used to
     * mirror native portal team-events to Google Calendar. Supports all-day
     * ($start === '') and timed events. Naive local times are sent with the org
     * timeZone so Google interprets them correctly. Returns ['id','html_link']
     * or null. Dates 'Y-m-d', times 'H:i'.
     */
    public static function createEvent(string $title, string $date, string $start = '', string $end = '', string $location = '', string $description = '', ?string $calendarId = null): ?array
    {
        if (!self::calendarWriteEnabled()) return null;
        $cal = $calendarId ?: (string) (Config::get('AV_WS_CALENDAR_ID', '') ?: self::subject());
        if ($cal === '' || $title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
        $tz = (string) Config::get('AV_WS_TZ', 'Africa/Lagos');
        $event = ['summary' => mb_substr($title, 0, 200)];
        if ($description !== '') $event['description'] = mb_substr($description, 0, 4000);
        if ($location !== '')    $event['location']    = mb_substr($location, 0, 300);
        if ($start === '') {
            // All-day: Google's end date is exclusive, so it's the next day.
            $endDate = gmdate('Y-m-d', (strtotime($date . ' UTC') ?: time()) + 86400);
            $event['start'] = ['date' => $date];
            $event['end']   = ['date' => $endDate];
        } else {
            $endHm = $end;
            if ($endHm === '') { $t = strtotime($date . ' ' . $start); $endHm = $t ? date('H:i', $t + 3600) : $start; }
            $event['start'] = ['dateTime' => $date . 'T' . $start . ':00', 'timeZone' => $tz];
            $event['end']   = ['dateTime' => $date . 'T' . $endHm . ':00', 'timeZone' => $tz];
        }
        $url = self::apiBase() . '/calendar/v3/calendars/' . rawurlencode($cal) . '/events?' . http_build_query(['sendUpdates' => 'none']);
        $d = self::apiSend('POST', $url, self::SCOPE_CALENDAR_RW, self::subject() !== '', $event);
        if (!$d || empty($d['id'])) return null;
        return ['id' => (string) $d['id'], 'html_link' => (string) ($d['htmlLink'] ?? '')];
    }

    /** Cancel/delete an event (e.g. when a session is cancelled). */
    public static function deleteCalendarEvent(string $eventId, ?string $calendarId = null): bool
    {
        if (!self::calendarWriteEnabled() || $eventId === '') return false;
        $cal = $calendarId ?: (string) (Config::get('AV_WS_CALENDAR_ID', '') ?: self::subject());
        if ($cal === '') return false;
        $url = self::apiBase() . '/calendar/v3/calendars/' . rawurlencode($cal) . '/events/' . rawurlencode($eventId) . '?sendUpdates=all';
        return self::apiSend('DELETE', $url, self::SCOPE_CALENDAR_RW, self::subject() !== '') !== null;
    }

    /* ── Google Chat (post AS the member, via domain-wide delegation) ──
     * The portal's Team Chat mirrors into a Google Chat space authored by the
     * member — the service account impersonates their @org mailbox (they're
     * Google-signed), so the message shows THEIR name, not an app/webhook bot.
     * Needs the Chat scope added to the domain-wide delegation, and each channel
     * an admin has toggled on with a target space id. */

    private static function chatBase(): string { return rtrim((string) (Config::get('AV_CHAT_BASE_URL', '') ?: 'https://chat.googleapis.com'), '/'); }

    /** True when author-posting to Google Chat is possible (delegation configured). */
    public static function chatConfigured(): bool
    {
        return self::configured() && self::subject() !== '';
    }

    /**
     * Post $text to a Google Chat $space AS $authorEmail (impersonated). $space
     * accepts "spaces/AAAA…" or a bare "AAAA…" id. Returns true on success.
     */
    public static function postChatMessage(string $space, string $authorEmail, string $text): bool
    {
        if (!self::chatConfigured()) return false;
        $space = trim($space); $authorEmail = strtolower(trim($authorEmail)); $text = trim($text);
        if ($space === '' || $authorEmail === '' || $text === '') return false;
        if (strpos($space, 'spaces/') !== 0) $space = 'spaces/' . $space;
        $url = self::chatBase() . '/v1/' . $space . '/messages';
        $res = self::apiSend('POST', $url, self::SCOPE_CHAT, true, ['text' => mb_substr($text, 0, 4000)], $authorEmail);
        return $res !== null;
    }

    /* ── Google Meet REST API + Admin Reports (authoritative attendance) ──
     * Two independent sources of truth for how long a Meet actually ran, so the
     * logged mentorship hours come from Google — not a browser tab:
     *   • Meet REST API v2 — near-real-time; reads the conferenceRecord for the
     *     meeting space (start/end). Needs domain-wide delegation for the Meet
     *     scope and works by impersonating a conference host/guest (the mentor).
     *   • Admin SDK Reports API — the org's own Meet audit log (call_ended +
     *     duration_seconds), matched by meeting code. Tamper-proof, but lags.
     */

    /** Meet reconciliation is possible when the service account is configured. */
    public static function meetEnabled(): bool { return self::configured(); }
    /** Reports reconciliation additionally needs an admin to impersonate. */
    public static function reportsEnabled(): bool { return self::configured() && self::subject() !== ''; }

    /**
     * Authoritative conference window for a Meet code, as seen by Google.
     * Impersonates $hostEmail (a conference participant — the mentor). Returns
     * ['start'=>ts,'end'=>ts,'seconds'=>int] for the most recent ENDED conference
     * at/after $afterTs, or null if none has ended yet / not configured.
     */
    public static function meetConferenceForCode(string $meetingCode, string $hostEmail, int $afterTs = 0): ?array
    {
        $code = trim($meetingCode);
        if ($code === '' || $hostEmail === '' || !self::meetEnabled()) return null;
        // Resolve the space (accepts the dashed meeting code as an alias).
        $space = self::apiGet(self::meetBase() . '/v2/spaces/' . rawurlencode($code), self::SCOPE_MEET_RO, false, $hostEmail);
        $spaceName = is_array($space) ? (string) ($space['name'] ?? '') : '';
        if ($spaceName === '') return null;
        // List conference records for that space (most recent first).
        $q = http_build_query(['filter' => 'space.name="' . $spaceName . '"', 'pageSize' => 10]);
        $recs = self::apiGet(self::meetBase() . '/v2/conferenceRecords?' . $q, self::SCOPE_MEET_RO, false, $hostEmail);
        $items = is_array($recs) ? ($recs['conferenceRecords'] ?? []) : [];
        $best = null;
        foreach ($items as $r) {
            $s = strtotime((string) ($r['startTime'] ?? '')) ?: 0;
            $e = strtotime((string) ($r['endTime'] ?? '')) ?: 0;
            if ($e <= 0) continue;                 // still live — no authoritative end yet
            if ($afterTs > 0 && $s > 0 && $s < $afterTs - 3600) continue; // not this session
            if ($best === null || $e > $best['end']) $best = ['start' => $s, 'end' => $e, 'seconds' => max(0, $e - $s)];
        }
        return $best;
    }

    /**
     * Longest Meet call duration (seconds) the org audit log recorded for this
     * meeting code, or null. Matches ignoring dashes/case. Impersonates an admin.
     */
    public static function reportsMeetSeconds(string $meetingCode, int $afterTs = 0): ?int
    {
        $code = strtolower(str_replace('-', '', trim($meetingCode)));
        if ($code === '' || !self::reportsEnabled()) return null;
        $params = [
            'eventName' => 'call_ended',
            'filters'   => 'meeting_code==' . $code,
            'maxResults' => 100,
        ];
        if ($afterTs > 0) $params['startTime'] = gmdate('Y-m-d\TH:i:s\Z', $afterTs - 3600);
        $url = self::reportsBase() . '/admin/reports/v1/activity/users/all/applications/meet?' . http_build_query($params);
        $d = self::apiGet($url, self::SCOPE_REPORTS, false, self::subject());
        if (!is_array($d) || empty($d['items'])) return null;
        $max = 0;
        foreach ($d['items'] as $it) {
            foreach (($it['events'] ?? []) as $ev) {
                foreach (($ev['parameters'] ?? []) as $p) {
                    if (($p['name'] ?? '') === 'duration_seconds') {
                        $v = (int) ($p['intValue'] ?? $p['value'] ?? 0);
                        if ($v > $max) $max = $v;
                    }
                }
            }
        }
        return $max > 0 ? $max : null;
    }

    /**
     * Best authoritative duration (minutes) for a Meet, trying the near-real-time
     * Meet API first (impersonating $hostEmail) then the Reports audit log.
     * Returns ['minutes'=>int,'source'=>'meet'|'reports','start'=>?ts,'end'=>?ts]
     * or null when Google can't (yet) confirm it.
     */
    public static function authoritativeMeetMinutes(string $meetingCode, string $hostEmail, int $afterTs = 0): ?array
    {
        $conf = self::meetConferenceForCode($meetingCode, $hostEmail, $afterTs);
        if ($conf && $conf['seconds'] > 0) {
            return ['minutes' => (int) round($conf['seconds'] / 60), 'source' => 'meet', 'start' => $conf['start'], 'end' => $conf['end']];
        }
        $secs = self::reportsMeetSeconds($meetingCode, $afterTs);
        if ($secs !== null && $secs > 0) {
            return ['minutes' => (int) round($secs / 60), 'source' => 'reports', 'start' => null, 'end' => null];
        }
        return null;
    }

    /* ── Directory → members sync ────────────────────────────────── */

    /** Directory groups the org publishes (needs the groups.readonly scope). */
    public static function directoryGroups(int $max = 200): array
    {
        if (!self::configured() || self::subject() === '') return [];
        $domain = (string) Config::get('AV_ORG_DOMAIN', 'afrovanguard.org.ng');
        $q = http_build_query(['domain' => $domain, 'maxResults' => max(1, min(500, $max))]);
        $d = self::apiGet(self::reportsBase() . '/admin/directory/v1/groups?' . $q, self::SCOPE_GROUPS, true);
        if (!$d || empty($d['groups'])) return [];
        return array_map(fn($g) => [
            'name'  => (string) ($g['name'] ?? ''),
            'email' => (string) ($g['email'] ?? ''),
            'count' => (int) ($g['directMembersCount'] ?? 0),
        ], $d['groups']);
    }

    /**
     * Provision every active org user from the Workspace directory into the
     * LMS as a verified member (idempotent upsert by email). Returns a summary
     * ['created'=>int,'updated'=>int,'skipped'=>int]. Safe to run repeatedly.
     */
    public static function syncDirectoryUsers(int $max = 500): array
    {
        $sum = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        if (!self::configured() || self::subject() === '' || !class_exists('Database')) return $sum + ['ok' => false, 'error' => 'not configured'];
        $users = self::directoryUsers($max);
        if (!$users) return $sum + ['ok' => true];
        $db = Database::pdo();
        $find = $db->prepare('SELECT id FROM lms_users WHERE email = ?');
        $ins  = $db->prepare('INSERT INTO lms_users (name, email, password_hash, role, email_verified, has_password) VALUES (?,?,?,?,1,0)');
        $upd  = $db->prepare("UPDATE lms_users SET name = ? WHERE id = ? AND (name IS NULL OR name = '')");   // '' not "": a double-quoted "" is an identifier on Postgres and ANSI MySQL
        foreach ($users as $u) {
            $email = strtolower(trim((string) $u['email']));
            if ($email === '' || !empty($u['suspended'])) { $sum['skipped']++; continue; }
            $name = trim((string) $u['name']) ?: ucfirst(explode('@', $email)[0]);
            $find->execute([$email]);
            $id = (int) ($find->fetchColumn() ?: 0);
            if ($id > 0) { $upd->execute([$name, $id]); $sum['updated']++; continue; }
            try {
                $ins->execute([$name, $email, password_hash(bin2hex(random_bytes(18)), PASSWORD_BCRYPT), 'member']);
                $sum['created']++;
                if (class_exists('Events')) { try { Events::emit('member.created', ['email' => $email, 'name' => $name, 'role' => 'member', 'via' => 'workspace_sync']); } catch (Throwable $e) {} }
            } catch (Throwable $e) { $sum['skipped']++; }
        }
        return $sum + ['ok' => true, 'total' => count($users)];
    }

    /** Is the daily directory sync switched on (Setup → AV_WS_DIRECTORY_SYNC) and not yet run today? */
    public static function directorySyncDue(): bool
    {
        if (strtolower(trim((string) Config::get('AV_WS_DIRECTORY_SYNC', 'off'))) !== 'daily') return false;
        if (!self::configured() || self::subject() === '' || !class_exists('Database')) return false;
        $last = json_decode((string) (Database::metaGet('gws_directory_sync') ?? ''), true);
        return !is_array($last) || ($last['day'] ?? '') !== gmdate('Y-m-d');
    }

    /**
     * One directory sync, remembered: when it ran and what it did or why it
     * failed, for the System page. The day is claimed BEFORE the call, so a
     * failing sync is tried once a day rather than on every five-minute tick.
     */
    public static function runDirectorySync(): array
    {
        Database::metaSet('gws_directory_sync', (string) json_encode(['day' => gmdate('Y-m-d'), 'at' => gmdate('c'), 'running' => true]));
        self::$lastError = '';
        $r = self::syncDirectoryUsers();
        /* directoryUsers() returns [] on an error as well as on an empty
           directory; the error is what tells them apart. */
        if (self::$lastError !== '') $r = ['ok' => false, 'error' => self::$lastError] + $r;
        Database::metaSet('gws_directory_sync', (string) json_encode(['day' => gmdate('Y-m-d'), 'at' => gmdate('c'), 'result' => $r], JSON_UNESCAPED_SLASHES));
        return $r;
    }

    /** The last directory sync, as runDirectorySync() recorded it, or null. */
    public static function lastDirectorySync(): ?array
    {
        if (!class_exists('Database')) return null;
        $j = json_decode((string) (Database::metaGet('gws_directory_sync') ?? ''), true);
        return is_array($j) ? $j : null;
    }

    /** Fetch a Drive file's text (e.g. a Meet transcript doc) — read-only. */
    public static function driveText(string $fileId, int $maxBytes = 200000): ?string
    {
        if (!self::configured() || $fileId === '') return null;
        $tok = self::accessToken(self::SCOPE_DRIVE_RO, self::subject() !== '');
        if (!$tok) return null;
        // Google Docs must be exported as text/plain; binary files download directly.
        $url = self::apiBase() . '/drive/v3/files/' . rawurlencode($fileId) . '/export?mimeType=' . rawurlencode('text/plain');
        $res = self::http('GET', $url, $tok);
        if (!$res || $res['code'] >= 400) {
            $url = self::apiBase() . '/drive/v3/files/' . rawurlencode($fileId) . '?alt=media';
            $res = self::http('GET', $url, $tok);
        }
        if (!$res || $res['code'] >= 400 || !is_string($res['body'])) return null;
        return mb_substr($res['body'], 0, $maxBytes);
    }

    /**
     * Open an instant Meet room — no Calendar event, no invite from Google — owned
     * by the organiser mailbox (the same owner every scheduled meeting has, so the
     * attendance, transcript and notes are read back the same way). Needs the
     * meetings.space.created scope. Returns ['uri','code','space','mode'] or null.
     */
    public static function createMeetSpace(string $hostEmail = '', ?string $mode = null): ?array
    {
        $host = $hostEmail !== '' ? $hostEmail : self::organiser();
        if ($host === '' || !self::meetEnabled()) return null;
        $mode = $mode ?? self::artifactMode();
        /* Capture is part of the space from the start: the same recording, transcript,
           Gemini notes and attendance report a scheduled meeting gets after the fact.
           An edition that refuses recording is asked again without it, as
           configureMeetSpace() does — a room with no recording beats no room. */
        $tries = $mode === 'all' ? ['all', 'transcript'] : [$mode];
        foreach ($tries as $try) {
            $cfg = ['accessType' => 'TRUSTED', 'entryPointAccess' => 'ALL'];
            if ($try !== 'off') {
                $art = ['transcriptionConfig' => ['autoTranscriptionGeneration' => 'ON'], 'smartNotesConfig' => ['autoSmartNotesGeneration' => 'ON']];
                if ($try === 'all') $art['recordingConfig'] = ['autoRecordingGeneration' => 'ON'];
                $cfg['artifactConfig'] = $art;
                $cfg['attendanceReportGenerationType'] = 'GENERATE_REPORT';
            }
            $d = self::apiSend('POST', self::meetBase() . '/v2/spaces', self::SCOPE_MEET_CREATED, false, ['config' => $cfg], $host);
            if (is_array($d) && !empty($d['meetingUri'])) {
                return [
                    'uri'   => (string) $d['meetingUri'],
                    'code'  => (string) ($d['meetingCode'] ?? self::meetCodeFromUrl((string) $d['meetingUri'])),
                    'space' => (string) ($d['name'] ?? ''),
                    'mode'  => $try,
                ];
            }
        }
        return null;
    }

    /** Extract a Meet code (e.g. "abc-defg-hij") from a Meet URL. */
    public static function meetCodeFromUrl(string $url): string
    {
        if (preg_match('~meet\.google\.com/([a-z0-9\-]+)~i', $url, $m)) return strtolower($m[1]);
        return '';
    }

    /**
     * The official Google Meet transcript text for a meeting, via the Meet REST
     * API (conferenceRecords → transcripts → transcripts.entries). Requires the
     * meetings.space.readonly scope; impersonates $hostEmail (a participant).
     * Returns the joined "Speaker: text" transcript, or null if none exists yet.
     */
    /**
     * Who is in (or was in) a Meet: the conference for this meeting code that
     * began at/after $afterTs - 1h — live or ended — and every participant in
     * it, with when they first joined and last left (null while still in).
     * Meet REST API v2: conferenceRecords → participants (meetings.space.readonly),
     * impersonating $hostEmail.
     *
     * @return null|array{conference:string, live:bool, start:int, participants:list<array{id:string,name:string,google_user:string,kind:string,start:int,end:?int}>}
     */
    public static function meetParticipants(string $meetingCode, string $hostEmail, int $afterTs = 0): ?array
    {
        $code = trim($meetingCode);
        if ($code === '' || $hostEmail === '' || !self::meetEnabled()) return null;
        $space = self::apiGet(self::meetBase() . '/v2/spaces/' . rawurlencode($code), self::SCOPE_MEET_RO, false, $hostEmail);
        $spaceName = is_array($space) ? (string) ($space['name'] ?? '') : '';
        if ($spaceName === '') return null;
        $q = http_build_query(['filter' => 'space.name="' . $spaceName . '"', 'pageSize' => 10]);
        $recs = self::apiGet(self::meetBase() . '/v2/conferenceRecords?' . $q, self::SCOPE_MEET_RO, false, $hostEmail);
        $conf = null;
        foreach ((is_array($recs) ? ($recs['conferenceRecords'] ?? []) : []) as $r) {
            $s = strtotime((string) ($r['startTime'] ?? '')) ?: 0;
            if ($afterTs > 0 && $s > 0 && $s < $afterTs - 3600) continue;
            if ($conf === null || $s > (strtotime((string) ($conf['startTime'] ?? '')) ?: 0)) $conf = $r;
        }
        if (!$conf || empty($conf['name'])) return null;
        $people = [];
        $token = '';
        for ($i = 0; $i < 8; $i++) {
            $pq = http_build_query(array_filter(['pageSize' => 250, 'pageToken' => $token]));
            $page = self::apiGet(self::meetBase() . '/v2/' . $conf['name'] . '/participants?' . $pq, self::SCOPE_MEET_RO, false, $hostEmail);
            if (!is_array($page)) break;
            foreach (($page['participants'] ?? []) as $p) {
                $kind = isset($p['signedinUser']) ? 'signedin' : (isset($p['anonymousUser']) ? 'anonymous' : (isset($p['phoneUser']) ? 'phone' : 'unknown'));
                $who = (array) ($p['signedinUser'] ?? $p['anonymousUser'] ?? $p['phoneUser'] ?? []);
                $end = !empty($p['latestEndTime']) ? (strtotime((string) $p['latestEndTime']) ?: null) : null;
                $people[] = ['id' => (string) ($p['name'] ?? ''), 'name' => (string) ($who['displayName'] ?? ''), 'google_user' => (string) ($who['user'] ?? ''),
                             'kind' => $kind, 'start' => strtotime((string) ($p['earliestStartTime'] ?? '')) ?: 0, 'end' => $end];
            }
            $token = (string) ($page['nextPageToken'] ?? '');
            if ($token === '') break;
        }
        return ['conference' => (string) $conf['name'], 'live' => empty($conf['endTime']), 'start' => strtotime((string) ($conf['startTime'] ?? '')) ?: 0, 'participants' => $people];
    }

    /** A Workspace user's primary email from the id Meet reports ("users/123…"). Null when the directory cannot say. */
    public static function directoryEmailForId(string $googleUser): ?string
    {
        $id = preg_replace('#^users/#', '', trim($googleUser));
        if ($id === '' || !self::configured() || self::subject() === '') return null;
        $d = self::apiGet(self::reportsBase() . '/admin/directory/v1/users/' . rawurlencode($id) . '?fields=primaryEmail', self::SCOPE_DIRECTORY, true);
        $e = is_array($d) ? strtolower((string) ($d['primaryEmail'] ?? '')) : '';
        return $e !== '' ? $e : null;
    }

    public static function meetTranscriptText(string $meetingCode, string $hostEmail, int $afterTs = 0): ?string
    {
        $code = trim($meetingCode);
        if ($code === '' || $hostEmail === '' || !self::meetEnabled()) return null;
        $space = self::apiGet(self::meetBase() . '/v2/spaces/' . rawurlencode($code), self::SCOPE_MEET_RO, false, $hostEmail);
        $spaceName = is_array($space) ? (string) ($space['name'] ?? '') : '';
        if ($spaceName === '') return null;
        $q = http_build_query(['filter' => 'space.name="' . $spaceName . '"', 'pageSize' => 10]);
        $recs = self::apiGet(self::meetBase() . '/v2/conferenceRecords?' . $q, self::SCOPE_MEET_RO, false, $hostEmail);
        $items = is_array($recs) ? ($recs['conferenceRecords'] ?? []) : [];
        // Newest ended conference at/after the session start.
        $conf = null;
        foreach ($items as $r) {
            $s = strtotime((string) ($r['startTime'] ?? '')) ?: 0;
            if ($afterTs > 0 && $s > 0 && $s < $afterTs - 3600) continue;
            if ($conf === null) $conf = $r;
        }
        $confName = is_array($conf) ? (string) ($conf['name'] ?? '') : '';
        if ($confName === '') return null;
        $trs = self::apiGet(self::meetBase() . '/v2/' . $confName . '/transcripts', self::SCOPE_MEET_RO, false, $hostEmail);
        $tItems = is_array($trs) ? ($trs['transcripts'] ?? []) : [];
        if (!$tItems) return null;
        $tName = (string) ($tItems[0]['name'] ?? '');
        if ($tName === '') return null;
        // Page through the transcript entries and stitch them into readable text.
        $out = [];
        $pageToken = '';
        for ($i = 0; $i < 20; $i++) {
            $eq = http_build_query(array_filter(['pageSize' => 1000, 'pageToken' => $pageToken]));
            $entries = self::apiGet(self::meetBase() . '/v2/' . $tName . '/entries?' . $eq, self::SCOPE_MEET_RO, false, $hostEmail);
            if (!is_array($entries)) break;
            foreach (($entries['transcriptEntries'] ?? []) as $en) {
                $who = (string) ($en['participant'] ?? '');
                $who = $who !== '' ? (basename($who)) : '';
                $txt = trim((string) ($en['text'] ?? ''));
                if ($txt !== '') $out[] = ($who !== '' ? $who . ': ' : '') . $txt;
            }
            $pageToken = (string) ($entries['nextPageToken'] ?? '');
            if ($pageToken === '') break;
        }
        $joined = trim(implode("\n", $out));
        return $joined !== '' ? $joined : null;
    }

    /* ══ Meetings that run themselves ════════════════════════════════════
       Calendar makes the Meet (createMeetEvent). Then, as the ORGANISER —
       the mailbox that owns the space, the only one who can change it and
       always able to read it back — the space is told to record, transcribe,
       take notes and report attendance on its own; afterwards the records
       are read as the organiser too (a creator who never joined could not).
       Live events (join, leave, ended, transcript ready) come through the
       Workspace Events API into a Pub/Sub topic the cron drains (MeetEvents). */

    private static function pubsubBase(): string { return rtrim((string) (Config::get('AV_PUBSUB_BASE_URL', '') ?: 'https://pubsub.googleapis.com'), '/'); }
    private static function eventsBase(): string { return rtrim((string) (Config::get('AV_EVENTS_BASE_URL', '') ?: 'https://workspaceevents.googleapis.com'), '/'); }

    /**
     * The mailbox that owns the site's meetings: the Meet organiser, else the shared calendar, else the admin.
     *
     * Every Meet call is made AS this address, so it has to be a mailbox Google lets the
     * service account impersonate. A shared calendar's id ("…@group.calendar.google.com")
     * passes an email check and is not one: the token is refused, and every Meet read,
     * capture setting and event subscription failed for a site whose calendar was a
     * shared one. Such an id is skipped — the space then belongs to the admin the event
     * was created as, which is who Google makes the owner.
     */
    public static function organiser(): string
    {
        foreach ([(string) Config::get('AV_MEET_ORGANISER', ''), (string) Config::get('AV_WS_CALENDAR_ID', ''), self::subject()] as $e) {
            $e = strtolower(trim($e));
            if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) continue;
            if (preg_match('/@(group|resource|import)\.calendar\.google\.com$/', $e)) continue;
            return $e;
        }
        return '';
    }

    /** What Google captures on its own: all | transcript | off. */
    public static function artifactMode(): string
    {
        $m = strtolower(trim((string) Config::get('AV_MEET_ARTIFACTS', 'all')));
        return in_array($m, ['all', 'transcript', 'off'], true) ? $m : 'all';
    }

    /** An event's Meet link, re-read — Calendar can create the conference a moment after the event. */
    public static function eventMeetUrl(string $eventId, ?string $calendarId = null): string
    {
        $cal = $calendarId ?: (string) (Config::get('AV_WS_CALENDAR_ID', '') ?: self::subject());
        if ($eventId === '' || $cal === '' || !self::configured()) return '';
        $d = self::apiGet(self::apiBase() . '/calendar/v3/calendars/' . rawurlencode($cal) . '/events/' . rawurlencode($eventId) . '?conferenceDataVersion=1', self::SCOPE_CALENDAR_RW, self::subject() !== '');
        return is_array($d) ? (string) ($d['hangoutLink'] ?? self::meetFrom($d)) : '';
    }

    /**
     * Switch on Google's own capture for a Calendar-made Meet: recording,
     * transcript and Gemini notes start when someone with the privilege joins,
     * and Meet writes an attendance report. Settings can only be changed by the
     * space's owner, so this acts as the organiser. When the edition refuses
     * recording, it tries again with transcript and notes only.
     *
     * @return null|array{space:string, code:string, mode:string}
     */
    public static function configureMeetSpace(string $meetingCode, ?string $organiser = null): ?array
    {
        $code = trim($meetingCode);
        $who = $organiser ?: self::organiser();
        if ($code === '' || $who === '' || !self::meetEnabled()) return null;
        $space = self::apiGet(self::meetBase() . '/v2/spaces/' . rawurlencode($code), self::SCOPE_MEET_SETTINGS, false, $who);
        $name = is_array($space) ? (string) ($space['name'] ?? '') : '';
        if ($name === '') return null;
        $mode = self::artifactMode();
        if ($mode === 'off') return ['space' => $name, 'code' => $code, 'mode' => 'off'];
        foreach ($mode === 'all' ? ['all', 'transcript'] : ['transcript'] as $try) {
            $art = ['transcriptionConfig' => ['autoTranscriptionGeneration' => 'ON'], 'smartNotesConfig' => ['autoSmartNotesGeneration' => 'ON']];
            $mask = ['config.artifactConfig.transcriptionConfig.autoTranscriptionGeneration', 'config.artifactConfig.smartNotesConfig.autoSmartNotesGeneration', 'config.attendanceReportGenerationType'];
            if ($try === 'all') { $art['recordingConfig'] = ['autoRecordingGeneration' => 'ON']; $mask[] = 'config.artifactConfig.recordingConfig.autoRecordingGeneration'; }
            $body = ['config' => ['artifactConfig' => $art, 'attendanceReportGenerationType' => 'GENERATE_REPORT']];
            $r = self::apiSend('PATCH', self::meetBase() . '/v2/' . $name . '?updateMask=' . rawurlencode(implode(',', $mask)), self::SCOPE_MEET_SETTINGS, false, $body, $who);
            if ($r !== null) return ['space' => $name, 'code' => $code, 'mode' => $try];
        }
        return null;
    }

    /** The newest conference held in a space at or after $afterTs − 1 h (live or ended). */
    public static function meetConference(string $meetingCode, ?string $organiser = null, int $afterTs = 0): ?array
    {
        $code = trim($meetingCode);
        $who = $organiser ?: self::organiser();
        if ($code === '' || $who === '' || !self::meetEnabled()) return null;
        $space = self::apiGet(self::meetBase() . '/v2/spaces/' . rawurlencode($code), self::SCOPE_MEET_RO, false, $who);
        $spaceName = is_array($space) ? (string) ($space['name'] ?? '') : '';
        if ($spaceName === '') return null;
        $recs = self::apiGet(self::meetBase() . '/v2/conferenceRecords?' . http_build_query(['filter' => 'space.name="' . $spaceName . '"', 'pageSize' => 25]), self::SCOPE_MEET_RO, false, $who);
        $conf = null;
        foreach ((is_array($recs) ? ($recs['conferenceRecords'] ?? []) : []) as $r) {
            $st = strtotime((string) ($r['startTime'] ?? '')) ?: 0;
            if ($afterTs > 0 && $st > 0 && $st < $afterTs - 3600) continue;
            if ($conf === null || $st > (strtotime((string) ($conf['startTime'] ?? '')) ?: 0)) $conf = $r;
        }
        if (!$conf || empty($conf['name'])) return null;
        return ['name' => (string) $conf['name'], 'space' => $spaceName, 'live' => empty($conf['endTime']),
                'start' => strtotime((string) ($conf['startTime'] ?? '')) ?: 0, 'end' => !empty($conf['endTime']) ? (strtotime((string) $conf['endTime']) ?: 0) : 0];
    }

    /** One conference record by name (for an event that names it). */
    public static function conferenceRecord(string $name, ?string $organiser = null): ?array
    {
        $who = $organiser ?: self::organiser();
        if (!preg_match('#^conferenceRecords/[\w-]+$#', $name) || $who === '') return null;
        $r = self::apiGet(self::meetBase() . '/v2/' . $name, self::SCOPE_MEET_RO, false, $who);
        return is_array($r) ? ['name' => $name, 'space' => (string) ($r['space'] ?? ''), 'live' => empty($r['endTime']),
                               'start' => strtotime((string) ($r['startTime'] ?? '')) ?: 0, 'end' => !empty($r['endTime']) ? (strtotime((string) $r['endTime']) ?: 0) : 0] : null;
    }

    /**
     * Every participant of a conference, with each separate time they were
     * in (participantSessions) — leaving and rejoining is two sessions, so
     * the minutes someone was really there add up.
     *
     * @return list<array{id:string,name:string,google_user:string,kind:string,sessions:list<array{start:int,end:?int}>}>
     */
    public static function conferenceParticipants(string $conference, ?string $organiser = null, bool $withSessions = true): array
    {
        $who = $organiser ?: self::organiser();
        if ($conference === '' || $who === '') return [];
        $out = []; $token = '';
        for ($i = 0; $i < 8; $i++) {
            $page = self::apiGet(self::meetBase() . '/v2/' . $conference . '/participants?' . http_build_query(array_filter(['pageSize' => 250, 'pageToken' => $token])), self::SCOPE_MEET_RO, false, $who);
            if (!is_array($page)) break;
            foreach (($page['participants'] ?? []) as $p) {
                $kind = isset($p['signedinUser']) ? 'signedin' : (isset($p['anonymousUser']) ? 'anonymous' : (isset($p['phoneUser']) ? 'phone' : 'unknown'));
                $u = (array) ($p['signedinUser'] ?? $p['anonymousUser'] ?? $p['phoneUser'] ?? []);
                $sess = [];
                if ($withSessions && !empty($p['name'])) {
                    $sp = self::apiGet(self::meetBase() . '/v2/' . $p['name'] . '/participantSessions?pageSize=100', self::SCOPE_MEET_RO, false, $who);
                    foreach ((is_array($sp) ? ($sp['participantSessions'] ?? []) : []) as $x) {
                        $sess[] = ['id' => (string) ($x['name'] ?? ''), 'start' => strtotime((string) ($x['startTime'] ?? '')) ?: 0, 'end' => !empty($x['endTime']) ? (strtotime((string) $x['endTime']) ?: null) : null];
                    }
                }
                if (!$sess) $sess[] = ['id' => '', 'start' => strtotime((string) ($p['earliestStartTime'] ?? '')) ?: 0, 'end' => !empty($p['latestEndTime']) ? (strtotime((string) $p['latestEndTime']) ?: null) : null];
                $out[] = ['id' => (string) ($p['name'] ?? ''), 'name' => (string) ($u['displayName'] ?? ''), 'google_user' => (string) ($u['user'] ?? ''), 'kind' => $kind, 'sessions' => $sess];
            }
            $token = (string) ($page['nextPageToken'] ?? '');
            if ($token === '') break;
        }
        return $out;
    }

    /**
     * The conference's transcript as "Name: words" lines — speakers by the
     * name Meet shows, not a participant number. state: 'none' (no transcript
     * was made), 'pending' (Google is still writing it), 'ready'.
     *
     * @return array{state:string, text:string, doc:string}
     */
    public static function conferenceTranscript(string $conference, ?string $organiser = null): array
    {
        $who = $organiser ?: self::organiser();
        $none = ['state' => 'none', 'text' => '', 'doc' => ''];
        if ($conference === '' || $who === '') return $none;
        $trs = self::apiGet(self::meetBase() . '/v2/' . $conference . '/transcripts', self::SCOPE_MEET_RO, false, $who);
        $list = is_array($trs) ? ($trs['transcripts'] ?? []) : [];
        if (!$list) return $none;
        $t = $list[0];
        if ((string) ($t['state'] ?? '') !== 'FILE_GENERATED') return ['state' => 'pending', 'text' => '', 'doc' => ''];
        $names = [];
        foreach (self::conferenceParticipants($conference, $who, false) as $p) $names[$p['id']] = $p['name'];
        $out = []; $token = '';
        for ($i = 0; $i < 30; $i++) {
            $e = self::apiGet(self::meetBase() . '/v2/' . $t['name'] . '/entries?' . http_build_query(array_filter(['pageSize' => 1000, 'pageToken' => $token])), self::SCOPE_MEET_RO, false, $who);
            if (!is_array($e)) break;
            foreach (($e['transcriptEntries'] ?? []) as $en) {
                $txt = trim((string) ($en['text'] ?? ''));
                if ($txt === '') continue;
                $pid = (string) ($en['participant'] ?? '');
                $out[] = (($names[$pid] ?? '') !== '' ? $names[$pid] : 'Someone') . ': ' . $txt;
            }
            $token = (string) ($e['nextPageToken'] ?? '');
            if ($token === '') break;
        }
        return ['state' => $out ? 'ready' : 'pending', 'text' => implode("\n", $out), 'doc' => (string) ($t['docsDestination']['document'] ?? '')];
    }

    /** Gemini's notes for a conference, as plain text (from the Doc Meet wrote). '' when none. */
    public static function conferenceNotes(string $conference, ?string $organiser = null): string
    {
        $who = $organiser ?: self::organiser();
        if ($conference === '' || $who === '') return '';
        $n = self::apiGet(self::meetBase() . '/v2/' . $conference . '/smartNotes', self::SCOPE_MEET_RO, false, $who);
        foreach ((is_array($n) ? ($n['smartNotes'] ?? []) : []) as $x) {
            if ((string) ($x['state'] ?? '') !== 'FILE_GENERATED') continue;
            $doc = (string) ($x['docsDestination']['document'] ?? '');
            if ($doc === '') continue;
            $tok = self::accessToken(self::SCOPE_DRIVE_RO, false, $who);
            if (!$tok) return '';
            $r = self::http('GET', self::apiBase() . '/drive/v3/files/' . rawurlencode($doc) . '/export?mimeType=text/plain', $tok);
            if ($r && $r['code'] < 300 && is_string($r['body'])) return mb_substr(trim($r['body']), 0, 60000);
        }
        return '';
    }

    /** A Workspace user's id ("users/123…") from their email — Workspace Events targets a user by id. */
    public static function directoryUserId(string $email): string
    {
        if ($email === '' || !self::configured() || self::subject() === '') return '';
        $d = self::apiGet(self::reportsBase() . '/admin/directory/v1/users/' . rawurlencode($email) . '?fields=id', self::SCOPE_DIRECTORY, true);
        return is_array($d) && !empty($d['id']) ? (string) $d['id'] : '';
    }

    /* ── Workspace Events + Pub/Sub ─────────────────────────────────────── */

    public const MEET_EVENT_TYPES = [
        'google.workspace.meet.conference.v2.started', 'google.workspace.meet.conference.v2.ended',
        'google.workspace.meet.participant.v2.joined', 'google.workspace.meet.participant.v2.left',
        'google.workspace.meet.transcript.v2.fileGenerated', 'google.workspace.meet.recording.v2.fileGenerated',
        'google.workspace.meet.smartNote.v2.fileGenerated',
    ];

    /**
     * Subscribe to every Meet event of the organiser's spaces, into the topic.
     * Without resource data a subscription lives up to 7 days; renew it
     * (eventsRenew) before then. Returns the subscription name or ''.
     */
    public static function eventsSubscribe(string $topic, ?string $organiser = null): string
    {
        $who = $organiser ?: self::organiser();
        $uid = self::directoryUserId($who);
        if ($uid === '' || $topic === '') { if ($uid === '') self::$lastError = 'could not find the organiser ' . $who . ' in the directory'; return ''; }
        $body = ['targetResource' => '//cloudidentity.googleapis.com/users/' . $uid, 'eventTypes' => self::MEET_EVENT_TYPES,
                 'notificationEndpoint' => ['pubsubTopic' => $topic], 'payloadOptions' => ['includeResource' => false], 'ttl' => '0s'];
        $op = self::apiSend('POST', self::eventsBase() . '/v1/subscriptions', self::SCOPE_MEET_RO, false, $body, $who);
        $name = is_array($op) ? (string) ($op['response']['name'] ?? $op['name'] ?? '') : '';
        if (str_starts_with($name, 'subscriptions/')) return $name;
        /* Not named: either a long-running operation (listed under the organiser shortly
           after), or Google refused it as ALREADY_EXISTS — one subscription per user and
           event types. The second is what a failed renewal leads to, and returning ''
           there left the site re-asking for ever, while the existing subscription went on
           working unrenewed until Google expired it. Look for it either way. */
        $err = self::$lastError;
        $l = self::apiGet(self::eventsBase() . '/v1/subscriptions?' . http_build_query(['filter' => 'event_types:"' . self::MEET_EVENT_TYPES[0] . '"']), self::SCOPE_MEET_RO, false, $who);
        foreach ((is_array($l) ? ($l['subscriptions'] ?? []) : []) as $x) if (($x['targetResource'] ?? '') === $body['targetResource']) return (string) $x['name'];
        if (!is_array($op)) self::$lastError = $err;
        return '';
    }

    /** Push a subscription's expiry out again (ttl 0 = as long as Google allows). */
    public static function eventsRenew(string $subscription, ?string $organiser = null): bool
    {
        $who = $organiser ?: self::organiser();
        if (!str_starts_with($subscription, 'subscriptions/') || $who === '') return false;
        return self::apiSend('PATCH', self::eventsBase() . '/v1/' . $subscription . '?updateMask=ttl', self::SCOPE_MEET_RO, false, ['ttl' => '0s'], $who) !== null;
    }

    /** Pull waiting messages from a Pub/Sub subscription (as the service account). */
    public static function pubsubPull(string $subscription, int $max = 100): array
    {
        if (!preg_match('#^projects/[^/]+/subscriptions/[^/]+$#', $subscription) || !self::configured()) return [];
        $r = self::apiSend('POST', self::pubsubBase() . '/v1/' . $subscription . ':pull', self::SCOPE_PUBSUB, false, ['maxMessages' => max(1, min(1000, $max))]);
        return is_array($r) ? (array) ($r['receivedMessages'] ?? []) : [];
    }

    public static function pubsubAck(string $subscription, array $ackIds): bool
    {
        if (!$ackIds) return true;
        return self::apiSend('POST', self::pubsubBase() . '/v1/' . $subscription . ':acknowledge', self::SCOPE_PUBSUB, false, ['ackIds' => array_values($ackIds)]) !== null;
    }

    /**
     * Every scope the site asks Google for while acting AS a Workspace user, with the
     * feature that needs it. Domain-wide delegation in the Google Admin console must
     * list each of these against the service account's client ID; one that is missing
     * is refused only when that feature runs ("unauthorized_client"), so a probe that
     * tried one scope reported the connection healthy while Meet, scheduling and the
     * rest failed. Pub/Sub is not here: the service account uses it as itself.
     */
    public const DELEGATED_SCOPES = [
        self::SCOPE_CALENDAR          => 'reading the calendar',
        self::SCOPE_CALENDAR_RW       => 'scheduling meetings and events',
        self::SCOPE_DRIVE             => 'listing the Drive folder',
        self::SCOPE_DRIVE_RO          => 'reading transcripts and notes',
        self::SCOPE_DIRECTORY         => 'the staff directory',
        self::SCOPE_GROUPS            => 'directory groups',
        self::SCOPE_MEET_RO           => 'reading Meet attendance and transcripts',
        self::SCOPE_MEET_SETTINGS     => 'switching on Meet recording and notes',
        self::SCOPE_MEET_CREATED      => 'starting an instant Meet room',
        self::SCOPE_REPORTS           => 'Meet minutes from the audit log',
        self::SCOPE_CHAT              => 'posting to Google Chat',
    ];

    /**
     * Ask Google for a token for each delegated scope, as the admin. Returns
     * [scope => '' when granted, else Google's reason].
     *
     * @return array<string,string>
     */
    /** Marks a scope the probe had no time to test — not a refusal. */
    public const SCOPE_UNCHECKED = '~unchecked~';

    public static function scopeCheck(?string $asUser = null): array
    {
        $who = $asUser ?: self::subject();
        if (!self::configured() || $who === '') return [];
        $out = [];
        foreach (array_keys(self::DELEGATED_SCOPES) as $scope) {
            /* Eleven scopes, one token exchange each. Out of time, the rest
               are reported UNCHECKED rather than refused: "refused" sends
               somebody to the Admin console to fix a delegation that may be
               perfectly fine. */
            if (self::$deadline !== null && microtime(true) >= self::$deadline - 0.5) {
                $out[$scope] = self::SCOPE_UNCHECKED;
                continue;
            }
            self::$lastError = '';
            $out[$scope] = self::accessToken($scope, false, $who) !== null ? '' : (self::$lastError ?: 'refused');
        }
        return $out;
    }

    /** The comma-separated line to paste into Admin console → Security → API controls → Domain-wide delegation. */
    public static function delegationLine(): string
    {
        return implode(',', array_keys(self::DELEGATED_SCOPES));
    }

    /**
     * Connectivity probe for the Studio → System page. Reports what actually
     * works against live Google, without leaking tokens.
     */
    /** Set by probe() so one press of the test button cannot outlive the request. */
    private static ?float $deadline = null;

    /** Seconds this probe may spend, leaving the host room to send the answer. */
    public static function probeBudget(): float
    {
        $limit = (int) ini_get('max_execution_time');
        if ($limit <= 0) $limit = 30;              // 0 means no limit (CLI); assume a shared host's 30

        return max(5.0, min(20.0, $limit * 0.6));  // 60%, so there is always time to render JSON
    }

    public static function probe(?float $budget = null): array
    {
        if (!self::configured()) return ['configured' => false];
        self::$lastError = '';
        self::$deadline = microtime(true) + ($budget ?? self::probeBudget());
        try {
            return self::probeInner();
        } finally {
            self::$deadline = null;
        }
    }

    private static function probeInner(): array
    {
        $tok = self::accessToken(self::SCOPE_CALENDAR, self::subject() !== '');
        $err = self::$lastError;
        /* Each step reports its OWN error: a 0 beside an error is a failure,
           a 0 with none is an empty calendar or folder. */
        $step = static function (callable $f) use (&$err): array {
            self::$lastError = '';
            $n = $f();
            if (self::$lastError !== '' && $err === '') $err = self::$lastError;
            return ['n' => $n, 'error' => self::$lastError];
        };
        /* Nothing to read is not a pass: with no calendar or folder named, the
           reads return nothing without asking Google, and the test said ✓. */
        $calId = (string) (Config::get('AV_WS_CALENDAR_ID', '') ?: self::subject());
        $folder = (string) (Config::get('AV_WS_DRIVE_FOLDER_ID', '') ?: Config::get('AV_GDRIVE_FOLDER_ID', ''));
        $cal = $calId !== '' ? $step(fn() => count(self::calendarEvents(null, 1)))
            : ['n' => null, 'error' => 'no calendar to read — set “Shared calendar” or “Admin to act as”'];
        $drv = $folder !== '' ? $step(fn() => count(self::driveFiles(null, 1)))
            : ['n' => null, 'error' => ''];
        $dir = self::subject() !== '' ? $step(fn() => count(self::directoryUsers(1))) : ['n' => null, 'error' => ''];
        $grp = self::subject() !== '' ? $step(fn() => count(self::directoryGroups(1))) : ['n' => null, 'error' => ''];
        return [
            'configured'      => true,
            'service_account' => (string) (self::credentials()['client_email'] ?? ''),
            'client_id'       => (string) (self::credentials()['client_id'] ?? ''),
            'subject'         => self::subject(),
            'token_ok'        => $tok !== null,
            'calendar_read'   => $cal['n'], 'calendar_error' => $cal['error'],
            'calendar_write'  => self::calendarWriteEnabled(),
            'drive'           => $drv['n'], 'drive_error' => $drv['error'], 'drive_folder' => $folder,
            'directory'       => $dir['n'], 'directory_error' => $dir['error'],
            'groups'          => $grp['n'], 'groups_error' => $grp['error'],
            'meet_organiser'  => self::organiser(), 'meet_capture' => self::artifactMode(),
            'meet_events'     => (string) Config::get('AV_MEET_PUBSUB_SUB', '') !== '',
            'scopes'          => self::subject() !== '' ? self::scopeCheck() : [],
            'clock_skew'      => self::$clockSkew,
            'error'           => $err,
        ];
    }
}
