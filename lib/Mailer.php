<?php
/**
 * lib/Mailer.php — one shared, brand-styled mailer for the whole site.
 *
 * Delivery strategy (mirrors the battle-tested NextGenGen mailer): send through
 * the bundled PHPMailer over authenticated SMTP — it reliably completes the
 * Gmail/Workspace handshake (EHLO, STARTTLS, AUTH) where a hand-rolled client
 * quietly fails. On a STARTTLS/587 connect failure it automatically retries over
 * SMTPS/465 (the common shared-host case where 587 is blocked but 465 is open).
 * There is NO hand-rolled SMTP client — every SMTP send goes through PHPMailer.
 *
 * The road is chosen by Studio → Rules & AI → Setup → Email → Sending road
 * (AV_MAIL_TRANSPORT; see transport() and plan()). On `auto`, the default:
 *
 *   PHPMailer/SMTP (587 → 465)  →  Google Apps Script  →  NGG relay  →  Resend (HTTPS)  →  mail()
 *
 * Google Apps Script is the site's OWN script (apps-script/Afrovanguard_Mail.gs,
 * lib/AppsScriptMail.php; setup in docs/EMAIL-APPS-SCRIPT.md): MailApp sends from
 * the account that deployed it, over 443, with no SMTP port, API key or DNS
 * record to get right, and it carries attachments. Its allowance is ~100
 * recipients a day on Gmail (1,500 on Workspace), so it — and the older relay on
 * NGG's protocol (op `sendMail`, ?sig=HMAC, MAIL_RELAY_URL/SECRET, still honoured
 * when set) — carry ONE-TO-ONE mail only. Announcements (`bulk` or a
 * List-Unsubscribe header) go SMTP → Resend → mail() and never spend it.
 *
 * Everything is best-effort: a mail failure must never break a request
 * (a learner still gets access even if the receipt email can't be sent).
 */
declare(strict_types=1);

final class Mailer
{
    private static ?bool $phpmailer = null;
    private static string $lastError = '';
    private static string $lastTransport = '';

    /** The last transport error (for the Studio "send test email" diagnostic). */
    public static function lastError(): string { return self::$lastError; }
    /** Which transport last delivered: 'smtp' | 'gas' | 'relay' | 'resend' | 'mail' | 'disabled' | '' (diagnostics). */
    public static function lastTransport(): string { return self::$lastTransport; }

    /** True when we can at least attempt delivery (SMTP, Apps Script, the relay or a Resend key). */
    public static function configured(): bool
    {
        if (defined('ENABLE_EMAIL_NOTIFICATIONS') && !ENABLE_EMAIL_NOTIFICATIONS) return false;
        if (self::resendKey() !== '' || self::relayConfigured() || self::gasConfigured()) return true;
        return defined('SMTP_HOST') && defined('SMTP_USERNAME') && defined('SMTP_PASSWORD') && SMTP_PASSWORD !== '';
    }

    /** True when authenticated SMTP credentials are present. */
    private static function smtpConfigured(): bool
    {
        return defined('SMTP_HOST') && SMTP_HOST !== ''
            && defined('SMTP_USERNAME') && defined('SMTP_PASSWORD') && SMTP_PASSWORD !== '';
    }

    /** Resend HTTPS API key, if configured (env AV_RESEND_KEY or a RESEND_KEY constant). */
    private static function resendKey(): string
    {
        $k = defined('RESEND_KEY') ? (string) RESEND_KEY : (string) (getenv('AV_RESEND_KEY') ?: '');
        $k = trim($k);
        return ($k === '' || $k === 'YOUR_RESEND_KEY_HERE') ? '' : $k;
    }

    /** The Apps Script relay: MAIL_RELAY_URL + MAIL_RELAY_SECRET (constants or env). */
    private static function relayConf(string $name): string
    {
        if (defined($name) && trim((string) constant($name)) !== '') return trim((string) constant($name));
        $alias = $name === 'MAIL_RELAY_URL' ? ['MAIL_RELAY_URL', 'AV_MAIL_RELAY_URL', 'APPS_SCRIPT_MAIL_URL']
                                            : ['MAIL_RELAY_SECRET', 'AV_MAIL_RELAY_SECRET', 'APPS_SCRIPT_MAIL_SECRET'];
        foreach ($alias as $k) { $v = getenv($k); if ($v !== false && trim((string) $v) !== '') return trim((string) $v); }
        return '';
    }

    /** True when the Apps Script relay is configured (diagnostics). */
    public static function relayConfigured(): bool
    {
        return str_starts_with(self::relayConf('MAIL_RELAY_URL'), 'https://') && self::relayConf('MAIL_RELAY_SECRET') !== '';
    }

    /** One message through the relay. Throws with the reason on failure. */
    private static function sendViaRelay(array $from, string $to, string $subject, string $html, string $text): bool
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('curl unavailable');
        $body = json_encode([
            'op' => 'sendMail', 'to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text,
            'fromName' => (string) ($from['name'] ?? ''), 'replyTo' => (string) ($from['replyTo'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $url = self::relayConf('MAIL_RELAY_URL');
        $sig = hash_hmac('sha256', (string) $body, self::relayConf('MAIL_RELAY_SECRET'));
        $ch = curl_init($url . (str_contains($url, '?') ? '&' : '?') . 'sig=' . $sig);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_FOLLOWLOCATION => true,   // /exec 302s to googleusercontent
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $cerr !== '') throw new \RuntimeException('curl: ' . $cerr);
        $j = json_decode((string) $raw, true);
        if (is_array($j) && !empty($j['ok'])) return true;
        $why = is_array($j) ? (string) ($j['error'] ?? 'refused') : 'HTTP ' . $code;
        if ($why === 'bad_signature') $why .= ' (MAIL_RELAY_SECRET does not match the script\'s SHARED_SECRET)';
        throw new \RuntimeException($why);
    }

    /** True when the site's own Google Apps Script has both its URL and secret (diagnostics). */
    public static function gasConfigured(): bool
    {
        return class_exists('AppsScriptMail') && AppsScriptMail::configured();
    }

    /** True when the Resend HTTPS API key is present (diagnostics). */
    public static function resendConfigured(): bool { return self::resendKey() !== ''; }

    /**
     * What PHPMailer this deploy will actually send through — the answer the
     * Studio's email diagnostic needs. PHPMailer is the only SMTP transport
     * (the hand-rolled lib/Smtp.php was retired), so "credentials are set" is
     * only half the story: without the library there is no SMTP send at all,
     * and mail silently degrades to PHP mail(). Reports whether it LOADS,
     * not merely whether a file is on disk.
     */
    public static function phpMailerInfo(): array
    {
        $available = self::loadPhpMailer();
        $out = ['available' => $available, 'version' => '', 'source' => '', 'path' => ''];
        if (!$available) return $out;
        try {
            $r = new \ReflectionClass('PHPMailer\\PHPMailer\\PHPMailer');
            $path = (string) $r->getFileName();
            $out['path']    = $path;
            $out['source']  = str_contains($path, '/lib/vendor/') ? 'bundled with the site'
                            : (str_contains($path, '/vendor/') ? 'Composer' : 'manual install');
            $out['version'] = (string) ($r->getConstant('VERSION') ?: '');
        } catch (\Throwable $e) {
            // Loaded but unintrospectable — availability is the part that matters.
        }
        return $out;
    }

    private static function loadPhpMailer(): bool
    {
        if (self::$phpmailer !== null) return self::$phpmailer;
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return self::$phpmailer = true;
        // PHPMailer from whichever location this deploy has it — Composer, the
        // committed lib/vendor bundle (Africa GATES / NextGenGen style), the
        // Composer package path, or a legacy manual drop. No Composer required.
        $vendor  = AV_ROOT . '/vendor/autoload.php';                          // Composer autoloader
        $bundled = AV_ROOT . '/lib/vendor/phpmailer/PHPMailer.php';           // committed flat bundle
        $manual  = AV_ROOT . '/vendor/phpmailer/phpmailer/src/PHPMailer.php'; // Composer package (no autoload)
        $legacy  = AV_ROOT . '/PHPMailer-master/src/PHPMailer.php';           // legacy manual drop
        if (is_file($vendor)) {
            require_once $vendor;
        } elseif (is_file($bundled)) {
            require_once AV_ROOT . '/lib/vendor/phpmailer/Exception.php';
            require_once $bundled;
            require_once AV_ROOT . '/lib/vendor/phpmailer/SMTP.php';
        } elseif (is_file($manual)) {
            require_once AV_ROOT . '/vendor/phpmailer/phpmailer/src/Exception.php';
            require_once $manual;
            require_once AV_ROOT . '/vendor/phpmailer/phpmailer/src/SMTP.php';
        } elseif (is_file($legacy)) {
            require_once AV_ROOT . '/PHPMailer-master/src/Exception.php';
            require_once $legacy;
            require_once AV_ROOT . '/PHPMailer-master/src/SMTP.php';
        }
        return self::$phpmailer = class_exists('PHPMailer\\PHPMailer\\PHPMailer');
    }

    /**
     * From/reply identity. Defaults to the general org mailbox
     * (FROM_EMAIL, e.g. cacentre@…), but a caller may override per message via
     * $opt['from'] / $opt['fromName'] / $opt['replyTo'] — e.g. donation receipts
     * send from donations@… while everything else sends from cacentre@….
     */
    private static function from(array $opt = []): array
    {
        $domain    = defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng';
        $fromEmail = '';
        if (!empty($opt['from']) && filter_var((string) $opt['from'], FILTER_VALIDATE_EMAIL)) $fromEmail = (string) $opt['from'];
        if ($fromEmail === '') {
            $fromEmail = defined('FROM_EMAIL') && (string) FROM_EMAIL !== '' ? (string) FROM_EMAIL
                : (defined('SMTP_USERNAME') && SMTP_USERNAME !== '' ? (string) SMTP_USERNAME : 'no-reply@' . $domain);
        }
        $fromName  = !empty($opt['fromName']) ? (string) $opt['fromName'] : (defined('FROM_NAME') ? FROM_NAME : 'Afrovanguard');
        // Replies should reach a human even when the From is a no-reply box.
        $replyTo   = (!empty($opt['replyTo']) && filter_var((string) $opt['replyTo'], FILTER_VALIDATE_EMAIL)) ? (string) $opt['replyTo']
            : (defined('REPLY_TO') && REPLY_TO !== '' ? (string) REPLY_TO : $fromEmail);
        return ['email' => $fromEmail, 'name' => $fromName, 'replyTo' => $replyTo];
    }

    /** Send an HTML email. Returns true if a transport accepted the message. */
    /**
     * Outbound mail is switched OFF for this process.
     *
     * `AV_MAIL_DISABLED=1` (env or constant). For a staging or a restored copy
     * of the site, where the danger is real: the databases come with it, so the
     * first payment somebody records emails a receipt to an actual participant
     * from a server nobody meant to be live. Also used by the test suite, which
     * otherwise shells out to a sendmail that is not there, once per message.
     *
     * It reports FAILURE rather than pretending to send. An operator seeing
     * "delivery failed" on staging is being told the truth; one seeing "sent" is
     * being taught to trust a number that means nothing.
     */
    public static function disabled(): bool
    {
        $v = getenv('AV_MAIL_DISABLED');
        if ($v !== false && $v !== '' && $v !== '0') return true;
        return defined('AV_MAIL_DISABLED') && AV_MAIL_DISABLED;
    }

    /* ════════════════════════════════════════════════════════════════
       Which road a message takes
       ════════════════════════════════════════════════════════════════ */

    /** The values AV_MAIL_TRANSPORT accepts (Studio → Rules & AI → Setup → Email → Sending road). */
    public const TRANSPORTS = ['auto', 'smtp', 'gas', 'resend', 'host'];

    /**
     * How mail leaves, as the Studio setting says — `auto` when unset or unknown.
     *
     *   auto    SMTP; then Google Apps Script (one-to-one mail only); then the older
     *           NGG-protocol relay if it is still configured (one-to-one only); then
     *           Resend; then this server's own mail().
     *   smtp    SMTP and nothing else.
     *   gas     Google Apps Script only (the legacy relay stands in when the new
     *           script is not set up). Announcements are NOT sent — they fail with
     *           a reason, because MailApp's daily allowance belongs to sign-in codes.
     *   resend  The Resend HTTPS API only.
     *   host    PHP mail() only.
     */
    public static function transport(): string
    {
        foreach (['AV_MAIL_TRANSPORT', 'MAIL_TRANSPORT'] as $k) {
            $v = class_exists('Config') ? Config::str($k) : (string) (getenv($k) ?: '');
            $v = strtolower(trim($v));
            if ($v !== '') return in_array($v, self::TRANSPORTS, true) ? $v : 'auto';
        }
        return 'auto';
    }

    /**
     * Is this an announcement rather than mail somebody is waiting for?
     *
     * `$opt['bulk'] = true`, or a List-Unsubscribe header — the convention every
     * bulk sender here already follows (Appeals' update mailings), and the honest
     * test: a message somebody can be offered a way out of is one they did not
     * ask for. A sign-in code, a receipt or a reply carries neither.
     */
    public static function isBulk(array $opt): bool
    {
        if (!empty($opt['bulk'])) return true;
        return isset(self::extraHeaders($opt)['List-Unsubscribe']);
    }

    /**
     * The roads a message may take, in order. Pure — it reads no settings — so the
     * order is pinned by the suite. A road that is not configured is skipped at
     * send time; it stays in the plan so the order is the whole story.
     *
     * The two Apps Script roads ('gas' and the legacy 'relay') are both MailApp,
     * whose allowance (~100 recipients a day on Gmail) is what sign-in codes need,
     * so neither is ever offered to bulk mail.
     */
    public static function plan(string $transport, bool $bulk): array
    {
        switch ($transport) {
            case 'smtp':   return ['smtp'];
            case 'gas':    return $bulk ? [] : ['gas', 'relay'];
            case 'resend': return ['resend'];
            case 'host':   return ['host'];
            default:       return $bulk ? ['smtp', 'resend', 'host'] : ['smtp', 'gas', 'relay', 'resend', 'host'];
        }
    }

    /* ── Test seams. The suite only; nothing in the site calls these. ── */

    /** @var array<string,\Closure>|null road => fn(array $msg): bool (throw to fail) */
    private static ?array $fakes = null;
    private static ?AppsScriptMail $gasClient = null;
    /** @var list<string> */
    private static array $lastTried = [];
    /** @var list<string> */
    private static array $lastFailures = [];

    /**
     * Replace the real roads with closures, for the test suite. While set, a road
     * with no closure is treated as unavailable — nothing real is ever attempted.
     * Pass null to restore the real roads.
     */
    public static function fakeRoads(?array $fakes): void { self::$fakes = $fakes; }

    /** Hand the mailer an Apps Script client (the suite's has a mocked HTTP closure). */
    public static function useAppsScript(?AppsScriptMail $client): void { self::$gasClient = $client; }

    /** The roads the last send() actually tried, in order. */
    public static function lastTried(): array { return self::$lastTried; }

    /** Why each road before the one that worked failed (empty when the first worked). */
    public static function lastFailures(): array { return self::$lastFailures; }

    private static function gas(): ?AppsScriptMail
    {
        if (self::$gasClient !== null) return self::$gasClient;
        if (self::$fakes !== null || !class_exists('AppsScriptMail') || !AppsScriptMail::configured()) return null;
        return AppsScriptMail::boot();
    }

    /** '' when the road can be tried for this message, else why not. */
    private static function unavailable(string $road, array $opt): string
    {
        $fake = self::$fakes !== null;
        switch ($road) {
            case 'smtp':
                if ($fake) return isset(self::$fakes['smtp']) ? '' : 'not configured';
                if (!self::smtpConfigured()) return 'not configured (SMTP_HOST, SMTP_USERNAME, AV_SMTP_PASSWORD)';
                return self::loadPhpMailer() ? '' : 'PHPMailer is missing';
            case 'gas':
                if (self::gas() !== null) return '';
                /* The reason, so a failure log naming this road says whether
                   the secret was never entered or is stored and unreadable. */
                return class_exists('AppsScriptMail')
                    ? AppsScriptMail::whyNotConfigured()
                    : 'not set up (Studio → Rules & AI → Setup → Email → Apps Script URL and secret)';
            case 'relay':
                if ($fake ? !isset(self::$fakes['relay']) : !self::relayConfigured()) return 'not configured';
                return self::normAttachments($opt) ? 'cannot carry attachments' : '';
            case 'resend':
                if ($fake) return isset(self::$fakes['resend']) ? '' : 'not configured';
                return self::resendKey() !== '' ? '' : 'no Resend key';
            case 'host':
                if ($fake) return isset(self::$fakes['host']) ? '' : 'not available';
                return function_exists('mail') ? '' : 'PHP mail() is not available';
        }
        return 'unknown road';
    }

    /** Plain names for the roads, for the Studio. */
    public const ROAD_NAMES = [
        'smtp' => 'authenticated SMTP', 'gas' => 'Google Apps Script', 'relay' => 'the NGG Apps Script relay',
        'resend' => 'the Resend HTTPS API', 'mail' => 'PHP mail()', 'host' => 'PHP mail()',
    ];

    public static function send(string $to, string $subject, string $html, array $opt = []): bool
    {
        self::$lastError = ''; self::$lastTransport = ''; self::$lastTried = []; self::$lastFailures = [];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { self::$lastError = 'Invalid recipient address'; return false; }
        if (self::disabled()) {
            self::$lastTransport = 'disabled';
            self::$lastError = 'Outbound mail is disabled on this installation (AV_MAIL_DISABLED).';
            error_log('[mail] suppressed → ' . $to . ': ' . $subject);
            return false;
        }

        $from = self::from($opt);
        $alt  = trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html))));
        $transport = self::transport();
        $bulk = self::isBulk($opt);
        $roads = self::plan($transport, $bulk);

        if ($roads === []) {
            self::$lastError = 'Not sent: sending is set to Google Apps Script only (Sending road = gas), and Apps Script carries '
                . 'one-to-one mail only — sign-in codes, receipts and replies. This announcement waits. To send announcements, '
                . 'set Studio → Rules & AI → Setup → Email → Sending road to auto or smtp.';
            error_log('[mail] bulk held (transport gas) → ' . $to . ': ' . $subject);
            return false;
        }

        $msg = ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $alt, 'from' => $from, 'opt' => $opt];
        $errors = [];
        foreach ($roads as $road) {
            $why = self::unavailable($road, $opt);
            if ($why !== '') {
                // In auto mode a road that is simply not set up is not news; when
                // the operator chose ONE road, its absence is the whole story.
                if ($transport !== 'auto') $errors[] = $road . ': ' . $why;
                continue;
            }
            self::$lastTried[] = $road;
            $ok = false;
            try {
                if (self::$fakes !== null && $road !== 'gas') {
                    $ok = (bool) (self::$fakes[$road])($msg);
                    if (!$ok) $errors[] = $road . ': send failed';
                } else {
                    switch ($road) {
                        case 'smtp':   $ok = self::viaSmtp($msg, $errors); break;
                        case 'gas':    $ok = self::viaGas($msg); break;
                        case 'relay':  $ok = self::viaRelay($msg); break;
                        case 'resend':
                            $ok = self::sendViaResend(self::resendKey(), $from, $to, $subject, $html, $alt, $opt);
                            if (!$ok) $errors[] = 'resend: send failed';
                            break;
                        case 'host':
                            $ok = self::viaHost($msg);
                            if (!$ok) $errors[] = 'php_mail(): rejected or unavailable';
                            break;
                    }
                }
            } catch (\Throwable $e) {
                $ok = false;
                $errors[] = $road . ': ' . $e->getMessage();
                error_log('[mail] ' . $road . ' to ' . $to . ': ' . $e->getMessage());
            }
            if ($ok) {
                self::$lastTransport = $road === 'host' ? 'mail' : $road;
                self::$lastFailures = $errors;
                if ($errors) error_log('[mail] → ' . $to . ' via ' . self::$lastTransport . ' after: ' . implode(' | ', $errors));
                return true;
            }
        }

        self::$lastFailures = $errors;
        self::$lastError = $errors
            ? implode(' | ', $errors)
            : (self::configured() ? 'All transports failed' : 'No SMTP configured and the host mail() is unavailable');
        if ($bulk && $transport === 'auto') {
            self::$lastError .= ' (an announcement: Google Apps Script is kept for one-to-one mail, so it was not tried)';
        }
        error_log("[mail] all transports failed → {$to}: {$subject} — " . self::$lastError);
        return false;
    }

    /**
     * Path 1 — PHPMailer over authenticated SMTP (the ONLY SMTP transport; no
     * hand-rolled client). Try the configured transport first, then, on a
     * connect/timeout failure, automatically retry over the OTHER common Gmail
     * port (STARTTLS/587 ⇄ SMTPS/465) — shared hosts frequently block one but
     * not the other. Each attempt may itself retry without cert verification
     * when the operator opts in (stale CA bundle).
     */
    private static function viaSmtp(array $msg, array &$errors): bool
    {
        [$to, $subject, $html, $alt, $from, $opt] = [$msg['to'], $msg['subject'], $msg['html'], $msg['text'], $msg['from'], $msg['opt']];
        foreach (self::smtpAttempts() as $attempt) {
            $r = self::sendViaPhpMailer($to, $subject, $html, $alt, $from, $opt, false, $attempt);
            if (!empty($r['ok'])) return true;

            $err     = (string) ($r['error'] ?? '');
            $tlsish  = preg_match('/certificate|ssl|tls|verify|self.signed|could not connect|connect\(\)|stream_socket|timed? ?out/i', $err) === 1;
            $optedIn = defined('SMTP_ALLOW_INSECURE_FALLBACK') && SMTP_ALLOW_INSECURE_FALLBACK === true
                       && !(defined('SMTP_VERIFY') && !SMTP_VERIFY);
            if ($tlsish && $optedIn) {
                error_log('[mail] cert verification failed on ' . $attempt['label'] . ' — retrying without peer verification (operator opt-in): ' . $err);
                $r2 = self::sendViaPhpMailer($to, $subject, $html, $alt, $from, $opt, true, $attempt);
                if (!empty($r2['ok'])) return true;
                $r = $r2; $err = (string) ($r['error'] ?? '');
            }
            $errors[] = 'smtp(' . $attempt['label'] . '): ' . ($err ?: 'send failed') . ((string) ($r['log'] ?? ''));
            error_log('[mail] PHPMailer ' . $attempt['label'] . ' to ' . $to . ': ' . $err);
            // Only fall through to the next port when it was a connect-level
            // failure; an AUTH/relay rejection will fail identically on 465.
            $connectish = preg_match('/could not connect|connect\(\)|stream_socket|timed? ?out|connection refused|network is unreachable/i', $err) === 1;
            if (!$connectish) break;
        }
        return false;
    }

    /**
     * Path 2 — Afrovanguard's own Google Apps Script (apps-script/Afrovanguard_Mail.gs).
     * Carries attachments and a Bcc; MailApp sends from the account that deployed
     * the script, with the From NAME and Reply-To given here. Throws with the
     * operator sentence AppsScriptMail writes.
     */
    private static function viaGas(array $msg): bool
    {
        $g = self::gas();
        if ($g === null) throw new \RuntimeException('Apps Script is not set up');
        $bcc = (string) ($msg['opt']['bcc'] ?? '');
        $g->send([
            'to'       => $msg['to'],
            'subject'  => $msg['subject'],
            'html'     => $msg['html'],
            'text'     => $msg['text'],
            'name'     => (string) ($msg['from']['name'] ?? ''),
            'reply_to' => (string) ($msg['from']['replyTo'] ?? ''),
            'bcc'      => filter_var($bcc, FILTER_VALIDATE_EMAIL) ? $bcc : '',
            'attachments' => self::normAttachments($msg['opt']),
        ]);
        return true;
    }

    /**
     * Path 3 — the older relay on NGG's protocol (op `sendMail`, ?sig=HMAC). Kept
     * so a site already pointed at NGG's deployment keeps sending; tried after the
     * site's own script. It carries no attachments (see unavailable()).
     */
    private static function viaRelay(array $msg): bool
    {
        $opt = $msg['opt'];
        self::sendViaRelay($msg['from'], $msg['to'], $msg['subject'], $msg['html'], $msg['text']);
        if (!empty($opt['bcc']) && filter_var($opt['bcc'], FILTER_VALIDATE_EMAIL)) {
            try { self::sendViaRelay($msg['from'], (string) $opt['bcc'], $msg['subject'], $msg['html'], $msg['text']); }
            catch (\Throwable $e) { error_log('[mail] relay bcc: ' . $e->getMessage()); }
        }
        return true;
    }

    /**
     * Path 5 — PHP mail() last resort (often silently dropped on shared hosts,
     * but a host with a working local MTA — cPanel/exim — still delivers).
     * As NGG's mail_native: a Date and a Message-ID (their absence is a
     * strong spam signal) and a text part beside the HTML.
     */
    private static function viaHost(array $msg): bool
    {
        [$to, $subject, $html, $alt, $from, $opt] = [$msg['to'], $msg['subject'], $msg['html'], $msg['text'], $msg['from'], $msg['opt']];
        $b    = 'av_' . bin2hex(random_bytes(8));
        $dom  = substr((string) strrchr($from['email'], '@'), 1) ?: 'localhost';
        $crlf = static fn(string $x): string => (string) preg_replace('/\r\n|\r|\n/', "\r\n", $x);
        $headers = 'MIME-Version: 1.0' . "\r\n"
            . 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000' . "\r\n"
            . 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $dom . '>' . "\r\n"
            . 'Content-Type: multipart/alternative; boundary="' . $b . '"' . "\r\n"
            . 'From: ' . self::encodeName($from['name']) . ' <' . $from['email'] . '>' . "\r\n"
            . 'Reply-To: ' . $from['replyTo'] . "\r\n";
        foreach (self::extraHeaders($opt) as $hk => $hv) $headers .= $hk . ': ' . $hv . "\r\n";
        $mbody = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . $crlf($alt) . "\r\n\r\n"
               . "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . $crlf($html) . "\r\n\r\n--{$b}--\r\n";
        // Envelope sender (-f): many shared hosts SPF-fail or drop mail without one.
        $params = filter_var($from['email'], FILTER_VALIDATE_EMAIL) ? ('-f' . $from['email']) : '';
        $subj   = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $ok = function_exists('mail') && @mail($to, $subj, $mbody, $headers, $params);
        if (!$ok && $params !== '' && function_exists('mail')) $ok = @mail($to, $subj, $mbody, $headers);
        return (bool) $ok;
    }

    /**
     * The ordered list of SMTP transport attempts: the configured transport
     * first, then (for the standard Gmail ports) the OTHER port as a fallback —
     * STARTTLS/587 ⇄ SMTPS/465 — so a host that blocks one still delivers on the
     * other. Each attempt is ['port'=>int, 'secure'=>string, 'label'=>string].
     */
    private static function smtpAttempts(): array
    {
        $port   = defined('SMTP_PORT') ? (int) SMTP_PORT : 587;
        $secure = defined('SMTP_SECURE') ? strtolower((string) SMTP_SECURE) : ($port === 465 ? 'ssl' : 'tls');
        $label  = fn(int $p, string $s) => (($s === 'ssl' || $s === 'smtps') ? 'smtps:' : (($s === '' || $s === 'none') ? 'plain:' : 'starttls:')) . $p;
        $attempts = [['port' => $port, 'secure' => $secure, 'label' => $label($port, $secure)]];
        // Auto-add the alternate standard Gmail/Workspace port as a fallback.
        if ($secure === 'tls' && $port === 587)            $attempts[] = ['port' => 465, 'secure' => 'ssl', 'label' => 'smtps:465'];
        elseif (($secure === 'ssl' || $secure === 'smtps') && $port === 465) $attempts[] = ['port' => 587, 'secure' => 'tls', 'label' => 'starttls:587'];
        return $attempts;
    }

    /**
     * One PHPMailer SMTP attempt over the transport in $attempt (port + secure).
     * Returns ['ok'=>bool, 'error'=>string, 'log'=>string]. $relaxTls skips
     * certificate verification — the fallback for shared hosts whose CA bundle is
     * stale/missing (a common cause of "the exact same SMTP works elsewhere but
     * not here").
     */
    private static function sendViaPhpMailer(string $to, string $subject, string $html, string $alt, array $from, array $opt, bool $relaxTls, array $attempt = []): array
    {
        $port   = (int) ($attempt['port'] ?? (defined('SMTP_PORT') ? SMTP_PORT : 587));
        $secure = (string) ($attempt['secure'] ?? (defined('SMTP_SECURE') ? strtolower((string) SMTP_SECURE) : 'tls'));
        $m = new \PHPMailer\PHPMailer\PHPMailer(true);
        // Capture only the SERVER side of the exchange for diagnostics — never the
        // CLIENT lines, which carry the base64-encoded credentials.
        $srv = '';
        try {
            $m->SMTPDebug   = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
            $m->Debugoutput = function ($str, $level) use (&$srv) {
                if (stripos((string) $str, 'CLIENT -> SERVER') !== false) return;
                $srv .= trim((string) $str) . "\n";
            };
            $m->isSMTP();
            $m->Host     = SMTP_HOST;
            $m->Port     = $port;
            $m->Username = SMTP_USERNAME;
            $m->Password = SMTP_PASSWORD;
            $m->SMTPAuth = SMTP_USERNAME !== '';
            // Transport security for THIS attempt (587=STARTTLS, 465=SMTPS, or a
            // plain internal relay). Passed in by smtpAttempts()/the port fallback.
            if ($secure === 'ssl' || $secure === 'smtps') {
                $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === '' || $secure === 'none') {
                $m->SMTPSecure = ''; $m->SMTPAutoTLS = false;
            } else {
                $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            if ($relaxTls || (defined('SMTP_VERIFY') && !SMTP_VERIFY)) {
                $m->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
            }
            $m->CharSet = 'UTF-8';
            $m->Timeout = 20;
            $m->setFrom($from['email'], $from['name']);
            // Envelope-from aligned with the authenticated mailbox — Gmail requires
            // it, and it keeps SPF/DMARC happy on any authenticated relay. This is
            // the single most common reason authenticated SMTP "sends" but the
            // message is silently rejected downstream.
            // On Gmail the envelope must be the authenticated mailbox. On a relay
            // such as Brevo — what Africa GATES delivers through from this same
            // domain — the login is a relay id (…@smtp-brevo.com), and an
            // envelope on it fails DMARC alignment: there the envelope is the
            // From, exactly as Africa GATES sets it.
            $gmail = stripos((string) SMTP_HOST, 'gmail') !== false || stripos((string) SMTP_HOST, 'google') !== false;
            $m->Sender = ($gmail && SMTP_USERNAME !== '' && filter_var(SMTP_USERNAME, FILTER_VALIDATE_EMAIL))
                ? (string) SMTP_USERNAME : (string) $from['email'];
            $m->addAddress($to);
            $m->addReplyTo($from['replyTo'], $from['name']);
            if (!empty($opt['bcc']) && filter_var($opt['bcc'], FILTER_VALIDATE_EMAIL)) $m->addBCC($opt['bcc']);
            // Attachments — accept a single ['path'=>, 'name'=>] or a list of them.
            foreach (self::normAttachments($opt) as $att) {
                if (!empty($att['path']) && is_file($att['path'])) $m->addAttachment($att['path'], (string) ($att['name'] ?? basename($att['path'])));
            }
            /* Extra headers. `List-Unsubscribe` is the one that matters: a bulk
               send to several hundred donors without it is a send that lands in
               spam, and a donor who cannot get out of a list complains to their
               provider rather than to us — which is how a charity loses its
               sending reputation on one campaign. */
            foreach (self::extraHeaders($opt) as $hk => $hv) {
                try { $m->addCustomHeader($hk, $hv); } catch (Throwable $e) { /* one bad header is not a failed send */ }
            }
            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body    = $html;
            $m->AltBody = $alt;
            $m->send();
            return ['ok' => true, 'error' => '', 'log' => ''];
        } catch (\Throwable $e) {
            $info = trim((string) ($m->ErrorInfo ?? '')) ?: $e->getMessage();
            self::$lastError = $info;
            $log = trim($srv);
            $log = $log !== '' ? ' | server: ' . substr((string) preg_replace('/\s+/', ' ', $log), -300) : '';
            return ['ok' => false, 'error' => $info, 'log' => $log];
        }
    }

    /**
     * Send via the Resend HTTPS API (https://resend.com/docs). One JSON POST with
     * the key as a bearer token — no SMTP socket, so it works on locked-down
     * shared hosts where SMTP ports and mail() both fail. Throws on failure so
     * send() can log and fall through.
     */
    private static function sendViaResend(string $apiKey, array $from, string $to, string $subject, string $html, string $text, array $opt): bool
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('curl unavailable');
        $payload = [
            'from'    => $from['name'] !== '' ? ($from['name'] . ' <' . $from['email'] . '>') : $from['email'],
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
            'text'    => $text,
        ];
        if (!empty($from['replyTo'])) $payload['reply_to'] = [$from['replyTo']];
        if (!empty($opt['bcc']) && filter_var($opt['bcc'], FILTER_VALIDATE_EMAIL)) $payload['bcc'] = [$opt['bcc']];
        $hdrs = self::extraHeaders($opt);
        if ($hdrs) $payload['headers'] = $hdrs;

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) throw new \RuntimeException('curl: ' . $cerr);
        if ($code >= 200 && $code < 300) return true;
        throw new \RuntimeException('HTTP ' . $code . ' · ' . substr((string) $raw, 0, 200));
    }

    private static function encodeName(string $n): string
    {
        return preg_match('/[^\x20-\x7e]/', $n) ? '=?UTF-8?B?' . base64_encode($n) . '?=' : $n;
    }

    /** Normalise $opt attachments to a list of ['path'=>, 'name'=>]. Accepts a
     *  single 'attachment' assoc or an 'attachments' list. */
    private static function normAttachments(array $opt): array
    {
        $out = [];
        if (!empty($opt['attachment']) && is_array($opt['attachment'])) $out[] = $opt['attachment'];
        if (!empty($opt['attachments']) && is_array($opt['attachments'])) {
            foreach ($opt['attachments'] as $a) if (is_array($a)) $out[] = $a;
        }
        return $out;
    }

    /**
     * Wrap body content in the Afrovanguard email shell (gold/ink brand).
     * $rows is an array of HTML strings rendered as stacked paragraphs.
     */
    /**
     * `$opt['headers']` as a clean name => value map.
     *
     * Only a short allowlist, and every value stripped of CR and LF. A newline
     * in a mail header is header injection: it ends the header and begins
     * another, so an unfiltered value could add a Bcc and quietly copy every
     * message somewhere. These values come from our own code today, which is
     * exactly the assumption that stops being true later.
     */
    private static function extraHeaders(array $opt): array
    {
        static $allowed = ['List-Unsubscribe', 'List-Unsubscribe-Post', 'List-Id', 'X-Entity-Ref-ID', 'Precedence'];
        $in = $opt['headers'] ?? null;
        if (!is_array($in)) return [];
        $out = [];
        foreach ($in as $k => $v) {
            $k = trim((string) $k);
            if (!in_array($k, $allowed, true)) continue;
            $v = trim(str_replace(["\r", "\n", "\0"], '', (string) $v));
            if ($v !== '') $out[$k] = mb_substr($v, 0, 500);
        }
        return $out;
    }

    /* ══ THE EMAIL ════════════════════════════════════════════════════════════
       One template for every message this site sends.

       It is the home page's language, moved into the one medium that cannot
       have the home page's stylesheet: Cormorant over a clean sans, warm paper
       under a white card, a single gold accent, hairlines instead of shadows.

       Three rules email forces on that:

       1. INLINE STYLES ONLY. Gmail strips <style> blocks, which is how the
          contact and donation emails — built with a <style> block and class
          names — have been arriving at most people unstyled. Everything here
          is on the element.
       2. HEX, NOT TOKENS. var(--av-ink) resolves to nothing in a mail client,
          so the values are written out. They are the SAME values as
          assets/site/av-tokens.css and are listed once, below, so a token
          change has one place to follow.
       3. THE WEBFONT WILL NOT LOAD. Cormorant Garamond and Source Sans 3 are
          self-hosted for the site; almost no mail client will fetch them. The
          fallbacks are therefore chosen, not inherited: Georgia carries the
          display line (same era, similar colour on the page), and the system
          sans stack carries the body.

       The mark sits at the FOOTER, not over the heading: the first thing read
       should be the sentence the message exists for. */

    /** The site tokens this template needs, written out for mail clients. */
    private const MAIL = [
        'ink'    => '#111827',   // --av-ink
        'body'   => '#3D3D3D',   // --av-copy-0
        'muted'  => '#5F636D',   // --av-muted
        'paper'  => '#FAF9F6',   // --av-paper
        'white'  => '#FFFFFF',   // --av-white
        'line'   => '#E8E6E1',   // --av-line
        'gold'   => '#F3B416',   // --av-gold
        'goldtx' => '#8A6406',   // --av-gold-text (4.5:1 on white — link colour)
        'stone'  => '#F8F7F4',   // --av-stone-2
    ];
    private const MAIL_SERIF = "'Cormorant Garamond',Garamond,Georgia,'Times New Roman',serif";
    private const MAIL_SANS  = "'Source Sans 3',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";

    /**
     * A label/value table — a receipt, an enquiry, a set of submitted fields.
     *
     * Inline-styled for the same reason everything here is: the stylesheet
     * never arrives. Values are escaped; pass pre-built HTML with $raw.
     *
     * @param array<array{0:string,1:string}> $rows
     */
    public static function facts(array $rows, bool $raw = false): string
    {
        $c = self::MAIL;
        $out = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;margin:0 0 20px">';
        foreach ($rows as $i => [$label, $value]) {
            $top = $i === 0 ? '0' : '10px';
            $out .= '<tr>'
                 . '<td style="padding:' . $top . ' 16px 10px 0;font-family:' . self::MAIL_SANS . ';font-size:11px;'
                 . 'font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:' . $c['muted'] . ';'
                 . 'white-space:nowrap;vertical-align:top;width:1%">' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</td>'
                 . '<td style="padding:' . $top . ' 0 10px;font-family:' . self::MAIL_SANS . ';font-size:15px;'
                 . 'line-height:1.55;color:' . $c['ink'] . '">'
                 . ($raw ? (string) $value : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'))
                 . '</td></tr>';
        }
        return $out . '</table>';
    }

    /** A quiet bordered block — a quoted message, a receipt, a set of facts. */
    public static function panel(string $label, string $html): string
    {
        $c = self::MAIL;
        $head = $label === '' ? '' :
            '<p style="margin:0 0 10px;font-family:' . self::MAIL_SANS . ';font-size:11px;font-weight:700;'
            . 'letter-spacing:.1em;text-transform:uppercase;color:' . $c['goldtx'] . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</p>';
        return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;margin:0 0 20px">'
             . '<tr><td style="padding:20px 22px;background:' . $c['stone'] . ';border:1px solid ' . $c['line'] . ';border-radius:10px">'
             . $head
             . '<div style="font-family:' . self::MAIL_SANS . ';font-size:15px;line-height:1.65;color:' . $c['body'] . '">' . $html . '</div>'
             . '</td></tr></table>';
    }

    /**
     * @param string     $heading   the one sentence the message is about
     * @param string[]   $rows      paragraphs; raw HTML, already escaped by the caller
     * @param array|null $cta       ['text' => …, 'url' => …]
     * @param string     $preheader the line the inbox shows beside the subject
     * @param array      $opt       'eyebrow' a short label above the heading;
     *                              'note' a quiet line in the footer (why this
     *                              email was sent, a reference number, …)
     */
    public static function shell(string $heading, array $rows, ?array $cta = null, string $preheader = '', array $opt = []): string
    {
        $c    = self::MAIL;
        $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://afrovanguard.org.ng';
        $host = preg_replace('#^https?://#', '', $site);
        $esc  = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $body = '';
        foreach ($rows as $r) {
            $r = trim((string) $r);
            if ($r === '') continue;
            $body .= '<p style="margin:0 0 18px;font-family:' . self::MAIL_SANS . ';font-size:16px;line-height:1.65;color:' . $c['body'] . '">' . $r . '</p>';
        }

        $eyebrow = trim((string) ($opt['eyebrow'] ?? ''));
        $eyebrow = $eyebrow === '' ? '' :
            '<p style="margin:0 0 12px;font-family:' . self::MAIL_SANS . ';font-size:11px;font-weight:700;'
            . 'letter-spacing:.14em;text-transform:uppercase;color:' . $c['goldtx'] . '">' . $esc($eyebrow) . '</p>';

        /* The button is a table so Outlook gives it its padding, and 48px tall
           so a thumb can hit it. */
        $btn = '';
        if ($cta && !empty($cta['url']) && !empty($cta['text'])) {
            $btn = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:26px 0 4px">'
                 . '<tr><td align="center" bgcolor="' . $c['gold'] . '" style="border-radius:8px">'
                 . '<a href="' . $esc((string) $cta['url']) . '" style="display:inline-block;padding:15px 30px;'
                 . 'font-family:' . self::MAIL_SANS . ';font-size:15.5px;font-weight:700;line-height:18px;'
                 . 'color:' . $c['ink'] . ';text-decoration:none;border-radius:8px">' . $esc((string) $cta['text'])
                 . '</a></td></tr></table>';
        }

        $note = trim((string) ($opt['note'] ?? ''));
        $note = $note === '' ? '' :
            '<p style="margin:0 0 14px;font-family:' . self::MAIL_SANS . ';font-size:13px;line-height:1.6;color:' . $c['muted'] . '">' . $note . '</p>';

        /* The preheader is what the inbox shows next to the subject. The
           zero-width run after it stops the client pulling the heading in
           behind it. */
        $pre = $preheader === '' ? '' :
            '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;opacity:0;color:transparent">'
            . $esc($preheader) . str_repeat('&#847;&zwnj;&nbsp;', 24) . '</div>';

        $heading = $esc($heading);

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
            . '<title>' . $heading . '</title></head>'
            . '<body style="margin:0;padding:0;width:100%;background:' . $c['paper'] . '">'
            . $pre
            . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background:' . $c['paper'] . '">'
            . '<tr><td align="center" style="padding:40px 16px">'

            . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" style="width:100%;max-width:600px;background:' . $c['white'] . ';border:1px solid ' . $c['line'] . ';border-radius:14px;overflow:hidden">'

            /* One gold hairline carries the brand at the top; the mark is at the foot. */
            . '<tr><td style="height:3px;line-height:3px;font-size:0;background:' . $c['gold'] . '">&nbsp;</td></tr>'

            . '<tr><td style="padding:40px 36px 34px">'
            . $eyebrow
            . '<h1 style="margin:0 0 20px;font-family:' . self::MAIL_SERIF . ';font-size:31px;line-height:1.18;'
            . 'font-weight:400;letter-spacing:-.01em;color:' . $c['ink'] . '">' . $heading . '</h1>'
            . $body . $btn
            . '</td></tr>'

            /* The mark, last: seal, wordmark, what we are for, where to find us. */
            . '<tr><td style="padding:28px 36px 32px;background:' . $c['stone'] . ';border-top:1px solid ' . $c['line'] . '">'
            . $note
            . '<table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr>'
            . '<td style="padding-right:12px" valign="middle">'
            . '<img src="' . $site . '/assets/site/av-seal-96.png" width="44" height="44" alt="Afrovanguard"'
            . ' style="display:block;width:44px;height:44px;border:0;outline:none;text-decoration:none"></td>'
            . '<td valign="middle"><span style="font-family:' . self::MAIL_SERIF . ';font-size:20px;line-height:1.1;'
            . 'color:' . $c['ink'] . '">Afrovanguard</span></td>'
            . '</tr></table>'
            . '<p style="margin:14px 0 0;font-family:' . self::MAIL_SANS . ';font-size:13px;line-height:1.6;color:' . $c['muted'] . '">'
            . 'Raising one million incorruptible leaders for Africa.<br>'
            . '<a href="' . $site . '/" style="color:' . $c['goldtx'] . ';text-decoration:none">' . $esc((string) $host) . '</a>'
            . '</p>'
            . '</td></tr>'

            . '</table></td></tr></table></body></html>';
    }
}
