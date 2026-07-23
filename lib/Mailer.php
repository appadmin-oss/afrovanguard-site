<?php
/**
 * lib/Mailer.php — one shared, brand-styled mailer for the whole site.
 *
 * Delivery strategy (mirrors the battle-tested NextGenGen mailer): send through
 * the bundled PHPMailer over authenticated SMTP first — it reliably completes
 * the Gmail/Workspace handshake (EHLO, STARTTLS, AUTH) where a hand-rolled
 * client quietly fails — then fall through to an HTTPS API (Resend) for hosts
 * that block outbound SMTP ports, then the raw built-in SMTP client, and
 * finally PHP mail(). Degrades gracefully and never throws:
 *
 *   PHPMailer/SMTP  →  Resend (HTTPS)  →  built-in Smtp  →  mail()  →  error_log
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

    private static function loadPhpMailer(): bool
    {
        if (self::$phpmailer !== null) return self::$phpmailer;
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return self::$phpmailer = true;
        $vendor = AV_ROOT . '/vendor/autoload.php';
        $manual = AV_ROOT . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
        $legacy = AV_ROOT . '/PHPMailer-master/src/PHPMailer.php';
        if (is_file($vendor)) { require_once $vendor; }
        elseif (is_file($manual)) {
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

    /** From/reply identity, resolved once from config. */
    private static function from(): array
    {
        $domain    = defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng';
        $fromEmail = defined('FROM_EMAIL') ? FROM_EMAIL : (defined('SMTP_USERNAME') && SMTP_USERNAME !== '' ? SMTP_USERNAME : 'no-reply@' . $domain);
        $fromName  = defined('FROM_NAME') ? FROM_NAME : 'Afrovanguard';
        // Replies should reach a human even when the From is a no-reply box.
        $replyTo   = defined('REPLY_TO') && REPLY_TO !== '' ? (string) REPLY_TO : $fromEmail;
        return ['email' => $fromEmail, 'name' => $fromName, 'replyTo' => $replyTo];
    }

    /** Send an HTML email. Returns true if a transport accepted the message. */
    public static function send(string $to, string $subject, string $html, array $opt = []): bool
    {
        self::$lastError = ''; self::$lastTransport = '';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { self::$lastError = 'Invalid recipient address'; return false; }

        $from = self::from();
        $alt  = trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html))));
        $errors = [];

        // Path 1 — PHPMailer over authenticated SMTP. Most reliable when the
        // host permits outbound 587/465 and the relay is aligned with the From.
        if (self::smtpConfigured() && self::loadPhpMailer()) {
            $r = self::sendViaPhpMailer($to, $subject, $html, $alt, $from, $opt, false);
            if (!empty($r['ok'])) { self::$lastTransport = 'smtp'; return true; }

            // Retry once WITHOUT cert verification only when the failure looks like a
            // TLS/connect problem AND the operator has explicitly opted in. Downgrading
            // TLS silently would let an attacker who can induce a TLS error strip
            // verification off a connection carrying the SMTP AUTH credentials — so it
            // is off by default (set SMTP_ALLOW_INSECURE_FALLBACK = true to permit).
            $err     = (string) ($r['error'] ?? '');
            $tlsish  = preg_match('/certificate|ssl|tls|verify|self.signed|could not connect|connect\(\)|stream_socket/i', $err) === 1;
            $optedIn = defined('SMTP_ALLOW_INSECURE_FALLBACK') && SMTP_ALLOW_INSECURE_FALLBACK === true
                       && !(defined('SMTP_VERIFY') && !SMTP_VERIFY);
            if ($tlsish && $optedIn) {
                error_log('[mail] cert verification failed — retrying without peer verification (operator opt-in): ' . $err);
                $r2 = self::sendViaPhpMailer($to, $subject, $html, $alt, $from, $opt, true);
                if (!empty($r2['ok'])) { self::$lastTransport = 'smtp'; return true; }
                $r = $r2;
            } elseif ($tlsish) {
                error_log('[mail] TLS/connect failure; insecure fallback disabled (set SMTP_ALLOW_INSECURE_FALLBACK=true to permit): ' . $err);
            }
            $errors[] = 'smtp: ' . ((string) ($r['error'] ?? 'send failed')) . ((string) ($r['log'] ?? ''));
            error_log('[mail] PHPMailer to ' . $to . ': ' . ((string) ($r['error'] ?? '')) . ' — trying next transport');
        }

        // Path 2 — Resend HTTPS API (https://resend.com). Shared hosts that block
        // SMTP ports almost always still allow outbound HTTPS, so this is the most
        // reliable cross-host fallback. One key, no SMTP socket, no Composer dep.
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

        // Path 3 — built-in hand-rolled SMTP client. Only reached if PHPMailer
        // could not load (bundled copy missing); PHP mail() cannot AUTH, so
        // Gmail/Workspace silently drop unauthenticated submission.
        if (self::smtpConfigured() && class_exists('Smtp')) {
            $cfg = [
                'host'   => (string) SMTP_HOST,
                'port'   => defined('SMTP_PORT') ? (int) SMTP_PORT : 587,
                'user'   => (string) SMTP_USERNAME,
                'pass'   => (string) SMTP_PASSWORD,
                'verify' => !(defined('SMTP_VERIFY') && !SMTP_VERIFY),
            ];
            if (defined('SMTP_SECURE')) $cfg['secure'] = (string) SMTP_SECURE;
            [$ok, $err] = Smtp::send($cfg, [
                'from' => $from['email'], 'fromName' => $from['name'], 'to' => $to,
                'subject' => $subject, 'html' => $html, 'text' => $alt,
                'replyTo' => $from['replyTo'], 'bcc' => (string) ($opt['bcc'] ?? ''),
            ]);
            if ($ok) { self::$lastTransport = 'smtp'; return true; }
            $errors[] = 'smtp_raw: ' . $err;
            error_log('[mail] built-in SMTP to ' . $to . ': ' . $err);
        }

        // Path 4 — PHP mail() last resort (often silently dropped on shared hosts,
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
     * One PHPMailer SMTP attempt. Returns ['ok'=>bool, 'error'=>string, 'log'=>string].
     * $relaxTls skips certificate verification — the fallback for shared hosts
     * whose CA bundle is stale/missing (the #1 cause of "the exact same SMTP
     * works elsewhere but not here").
     */
    private static function sendViaPhpMailer(string $to, string $subject, string $html, string $alt, array $from, array $opt, bool $relaxTls): array
    {
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
            $m->Port     = defined('SMTP_PORT') ? SMTP_PORT : 587;
            $m->Username = SMTP_USERNAME;
            $m->Password = SMTP_PASSWORD;
            $m->SMTPAuth = SMTP_USERNAME !== '';
            // Transport security. Defaults to STARTTLS (Gmail/587). Override with
            // SMTP_SECURE: 'ssl'/'smtps' (465), '' or 'none' (internal relay).
            $secure = defined('SMTP_SECURE') ? strtolower((string) SMTP_SECURE) : 'tls';
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
