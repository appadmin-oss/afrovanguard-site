<?php
/**
 * lib/Mailer.php — one shared, brand-styled mailer for the whole site.
 *
 * Delivery strategy (mirrors the battle-tested NextGenGen mailer): send through
 * the bundled PHPMailer over authenticated SMTP — it reliably completes the
 * Gmail/Workspace handshake (EHLO, STARTTLS, AUTH) where a hand-rolled client
 * quietly fails. On a STARTTLS/587 connect failure it automatically retries over
 * SMTPS/465 (the common shared-host case where 587 is blocked but 465 is open).
 * Then it falls through to an HTTPS API (Resend) for hosts that block SMTP ports
 * entirely, and finally PHP mail(). There is NO hand-rolled SMTP client — every
 * SMTP send goes through PHPMailer:
 *
 *   PHPMailer/SMTP (587 → 465)  →  Resend (HTTPS)  →  mail()  →  error_log
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
    /** Which transport last delivered: 'smtp' | 'resend' | 'mail' | '' (diagnostics). */
    public static function lastTransport(): string { return self::$lastTransport; }

    /** True when we can at least attempt delivery (SMTP or a Resend key configured). */
    public static function configured(): bool
    {
        if (defined('ENABLE_EMAIL_NOTIFICATIONS') && !ENABLE_EMAIL_NOTIFICATIONS) return false;
        if (self::resendKey() !== '') return true;
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
    public static function send(string $to, string $subject, string $html, array $opt = []): bool
    {
        self::$lastError = ''; self::$lastTransport = '';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { self::$lastError = 'Invalid recipient address'; return false; }

        $from = self::from($opt);
        $alt  = trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html))));
        $errors = [];

        // Path 1 — PHPMailer over authenticated SMTP (the ONLY SMTP transport; no
        // hand-rolled client). Try the configured transport first, then, on a
        // connect/timeout failure, automatically retry over the OTHER common Gmail
        // port (STARTTLS/587 ⇄ SMTPS/465) — shared hosts frequently block one but
        // not the other. Each attempt may itself retry without cert verification
        // when the operator opts in (stale CA bundle).
        if (self::smtpConfigured() && self::loadPhpMailer()) {
            foreach (self::smtpAttempts() as $attempt) {
                $r = self::sendViaPhpMailer($to, $subject, $html, $alt, $from, $opt, false, $attempt);
                if (!empty($r['ok'])) { self::$lastTransport = 'smtp'; return true; }

                $err     = (string) ($r['error'] ?? '');
                $tlsish  = preg_match('/certificate|ssl|tls|verify|self.signed|could not connect|connect\(\)|stream_socket|timed? ?out/i', $err) === 1;
                $optedIn = defined('SMTP_ALLOW_INSECURE_FALLBACK') && SMTP_ALLOW_INSECURE_FALLBACK === true
                           && !(defined('SMTP_VERIFY') && !SMTP_VERIFY);
                if ($tlsish && $optedIn) {
                    error_log('[mail] cert verification failed on ' . $attempt['label'] . ' — retrying without peer verification (operator opt-in): ' . $err);
                    $r2 = self::sendViaPhpMailer($to, $subject, $html, $alt, $from, $opt, true, $attempt);
                    if (!empty($r2['ok'])) { self::$lastTransport = 'smtp'; return true; }
                    $r = $r2; $err = (string) ($r['error'] ?? '');
                }
                $errors[] = 'smtp(' . $attempt['label'] . '): ' . ($err ?: 'send failed') . ((string) ($r['log'] ?? ''));
                error_log('[mail] PHPMailer ' . $attempt['label'] . ' to ' . $to . ': ' . $err);
                // Only fall through to the next port when it was a connect-level
                // failure; an AUTH/relay rejection will fail identically on 465.
                $connectish = preg_match('/could not connect|connect\(\)|stream_socket|timed? ?out|connection refused|network is unreachable/i', $err) === 1;
                if (!$connectish) break;
            }
        }

        // Path 2 — Resend HTTPS API (https://resend.com). Shared hosts that block
        // SMTP ports entirely almost always still allow outbound HTTPS, so this is
        // the most reliable cross-host fallback. One key, no SMTP socket.
        $resendKey = self::resendKey();
        if ($resendKey !== '') {
            try {
                if (self::sendViaResend($resendKey, $from, $to, $subject, $html, $alt, $opt)) {
                    self::$lastTransport = 'resend'; return true;
                }
                $errors[] = 'resend: send failed';
            } catch (\Throwable $e) {
                $errors[] = 'resend: ' . $e->getMessage();
                error_log('[mail] Resend to ' . $to . ': ' . $e->getMessage());
            }
        }

        // Path 3 — PHP mail() last resort (often silently dropped on shared hosts,
        // but a host with a working local MTA — cPanel/exim — still delivers).
        $headers = 'MIME-Version: 1.0' . "\r\n"
            . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
            . 'From: ' . self::encodeName($from['name']) . ' <' . $from['email'] . '>' . "\r\n"
            . 'Reply-To: ' . $from['replyTo'] . "\r\n";
        // Envelope sender (-f): many shared hosts SPF-fail or drop mail without one.
        $params = filter_var($from['email'], FILTER_VALIDATE_EMAIL) ? ('-f' . $from['email']) : '';
        $subj   = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $ok = @mail($to, $subj, $html, $headers, $params);
        if (!$ok && $params !== '') $ok = @mail($to, $subj, $html, $headers);
        if ($ok) { self::$lastTransport = 'mail'; return true; }
        $errors[] = 'php_mail(): rejected or unavailable';

        self::$lastError = $errors
            ? implode(' | ', $errors)
            : (self::configured() ? 'All transports failed' : 'No SMTP configured and the host mail() is unavailable');
        error_log("[mail] all transports failed → {$to}: {$subject} — " . self::$lastError);
        return false;
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
            $m->Sender = (SMTP_USERNAME !== '' && filter_var(SMTP_USERNAME, FILTER_VALIDATE_EMAIL))
                ? (string) SMTP_USERNAME : (string) $from['email'];
            $m->addAddress($to);
            $m->addReplyTo($from['replyTo'], $from['name']);
            if (!empty($opt['bcc']) && filter_var($opt['bcc'], FILTER_VALIDATE_EMAIL)) $m->addBCC($opt['bcc']);
            // Attachments — accept a single ['path'=>, 'name'=>] or a list of them.
            foreach (self::normAttachments($opt) as $att) {
                if (!empty($att['path']) && is_file($att['path'])) $m->addAttachment($att['path'], (string) ($att['name'] ?? basename($att['path'])));
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
    public static function shell(string $heading, array $rows, ?array $cta = null, string $preheader = ''): string
    {
        $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://afrovanguard.org.ng';
        $body = '';
        foreach ($rows as $r) { $body .= '<p style="margin:0 0 16px;font-size:15.5px;line-height:1.6;color:#2b2b2e">' . $r . '</p>'; }
        $btn = '';
        if ($cta && !empty($cta['url']) && !empty($cta['text'])) {
            $btn = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 4px"><tr><td style="border-radius:8px;background:#f3b416">'
                . '<a href="' . htmlspecialchars($cta['url'], ENT_QUOTES) . '" style="display:inline-block;padding:13px 26px;font-weight:700;font-size:15px;color:#15140f;text-decoration:none;border-radius:8px">'
                . htmlspecialchars($cta['text']) . '</a></td></tr></table>';
        }
        $pre = $preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . htmlspecialchars($preheader) . '</div>' : '';
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#f4f2ec;font-family:Montserrat,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;color:#2b2b2e">'
            . $pre
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f2ec;padding:28px 12px"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,0.06)">'
            . '<tr><td style="background:#15140f;border-bottom:4px solid #f3b416;padding:22px 36px">'
            . '<span style="color:#fff;font-size:18px;font-weight:800;letter-spacing:.02em">Afrovanguard <span style="color:#f3b416">Academy</span></span></td></tr>'
            . '<tr><td style="padding:34px 36px 30px">'
            . '<h1 style="margin:0 0 18px;font-size:24px;line-height:1.2;color:#15140f">' . htmlspecialchars($heading) . '</h1>'
            . $body . $btn
            . '</td></tr>'
            . '<tr><td style="padding:22px 36px;background:#faf8f3;border-top:1px solid #ece7da;font-size:12.5px;color:#8a8578;line-height:1.6">'
            . 'Afrovanguard — raising one million incorruptible leaders for Africa.<br>'
            . '<a href="' . $site . '" style="color:#a8821a;text-decoration:none">' . str_replace('https://', '', $site) . '</a>'
            . '</td></tr></table></td></tr></table></body></html>';
    }
}
