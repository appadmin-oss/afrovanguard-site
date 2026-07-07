<?php
/**
 * lib/Mailer.php — one shared, brand-styled mailer for the whole site.
 *
 * Reuses the donation system's SMTP settings (SMTP_HOST/PORT/USERNAME/
 * PASSWORD, FROM_EMAIL, FROM_NAME) and PHPMailer if it is installed
 * (Composer vendor/ or PHPMailer-master/). Degrades gracefully:
 *   PHPMailer+SMTP  →  PHP mail()  →  error_log (never throws).
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
    /** Which transport last delivered: 'smtp' | 'mail' | '' (for diagnostics). */
    public static function lastTransport(): string { return self::$lastTransport; }

    /** True when we can at least attempt delivery (SMTP configured). */
    public static function configured(): bool
    {
        if (defined('ENABLE_EMAIL_NOTIFICATIONS') && !ENABLE_EMAIL_NOTIFICATIONS) return false;
        return defined('SMTP_HOST') && defined('SMTP_USERNAME') && defined('SMTP_PASSWORD') && SMTP_PASSWORD !== '';
    }

    private static function loadPhpMailer(): bool
    {
        if (self::$phpmailer !== null) return self::$phpmailer;
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return self::$phpmailer = true;
        $vendor = AV_ROOT . '/vendor/autoload.php';
        $manual = AV_ROOT . '/PHPMailer-master/src/PHPMailer.php';
        if (is_file($vendor)) { require_once $vendor; }
        elseif (is_file($manual)) {
            require_once $manual;
            require_once AV_ROOT . '/PHPMailer-master/src/SMTP.php';
            require_once AV_ROOT . '/PHPMailer-master/src/Exception.php';
        }
        return self::$phpmailer = class_exists('PHPMailer\\PHPMailer\\PHPMailer');
    }

    /** Send an HTML email. Returns true if handed off to a transport. */
    public static function send(string $to, string $subject, string $html, array $opt = []): bool
    {
        self::$lastError = ''; self::$lastTransport = '';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { self::$lastError = 'Invalid recipient address'; return false; }

        $domain    = defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng';
        // A caller may override the sender (e.g. announcements use the GENERAL
        // address, so donations@ stays reserved for donation receipts). The
        // sender must still be one the SMTP account is allowed to send as
        // (the authenticated mailbox or a verified Gmail "send-as" alias),
        // else Gmail rewrites it back to the authenticated account.
        $fromEmail = (!empty($opt['from']) && filter_var($opt['from'], FILTER_VALIDATE_EMAIL))
            ? $opt['from']
            : (defined('FROM_EMAIL') ? FROM_EMAIL : (defined('SMTP_USERNAME') && SMTP_USERNAME !== '' ? SMTP_USERNAME : 'no-reply@' . $domain));
        $fromName  = !empty($opt['fromName']) ? (string) $opt['fromName'] : (defined('FROM_NAME') ? FROM_NAME : 'Afrovanguard');
        $alt = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html))));

        // Authenticated SMTP first (only when configured); then ALWAYS fall back
        // to PHP mail() so a host with a working local MTA still delivers.
        if (self::configured() && self::loadPhpMailer()) {
            $m = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $m->isSMTP();
                $m->Host       = SMTP_HOST;
                $m->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;
                $m->Username   = SMTP_USERNAME;
                $m->Password   = SMTP_PASSWORD;
                $m->SMTPAuth   = SMTP_USERNAME !== '';
                // Transport security. Defaults to STARTTLS (Gmail/587). Override
                // with SMTP_SECURE: 'ssl'/'smtps' (465), '' or 'none' (internal relay).
                $secure = defined('SMTP_SECURE') ? strtolower((string) SMTP_SECURE) : 'tls';
                if ($secure === 'ssl' || $secure === 'smtps') {
                    $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($secure === '' || $secure === 'none') {
                    $m->SMTPSecure = ''; $m->SMTPAutoTLS = false;
                } else {
                    $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                }
                // Relax certificate checks only for internal/self-signed relays.
                if (defined('SMTP_VERIFY') && !SMTP_VERIFY) {
                    $m->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
                }
                $m->CharSet    = 'UTF-8';
                $m->Timeout    = 20;
                $m->setFrom($fromEmail, $fromName);
                $m->addAddress($to);
                $m->addReplyTo($fromEmail, $fromName);
                if (!empty($opt['bcc']) && filter_var($opt['bcc'], FILTER_VALIDATE_EMAIL)) $m->addBCC($opt['bcc']);
                $m->isHTML(true);
                $m->Subject = $subject;
                $m->Body    = $html;
                $m->AltBody = $alt;
                $m->send();
                self::$lastTransport = 'smtp';
                return true;
            } catch (\Throwable $e) {
                // Don't give up — fall through to the built-in SMTP client (and then
                // mail()). PHPMailer can fail on a host where the dependency-free
                // client succeeds (TLS quirks, OpenSSL stream differences), so it
                // must not be a dead end.
                self::$lastError = (string) ($m->ErrorInfo ?: $e->getMessage());
                error_log('[mail] PHPMailer to ' . $to . ': ' . self::$lastError . ' — trying built-in SMTP');
            }
        }

        // Self-contained SMTP (no PHPMailer needed). REQUIRED for authenticated
        // submission to Gmail/Workspace on hosts without Composer — PHP mail()
        // cannot AUTH, so Gmail silently drops it.
        if (self::configured() && class_exists('Smtp') && defined('SMTP_HOST') && SMTP_HOST !== '') {
            $cfg = [
                'host'   => (string) SMTP_HOST,
                'port'   => defined('SMTP_PORT') ? (int) SMTP_PORT : 587,
                'user'   => (string) SMTP_USERNAME,
                'pass'   => (string) SMTP_PASSWORD,
                'verify' => !(defined('SMTP_VERIFY') && !SMTP_VERIFY),
            ];
            if (defined('SMTP_SECURE')) $cfg['secure'] = (string) SMTP_SECURE;
            [$ok, $err] = Smtp::send($cfg, [
                'from' => $fromEmail, 'fromName' => $fromName, 'to' => $to,
                'subject' => $subject, 'html' => $html, 'text' => $alt,
                'replyTo' => $fromEmail, 'bcc' => (string) ($opt['bcc'] ?? ''),
            ]);
            if ($ok) { self::$lastTransport = 'smtp'; return true; }
            self::$lastError = $err;
            error_log('[mail] SMTP to ' . $to . ': ' . $err);   // fall through to mail() as a last resort
        }

        // Last resort: PHP mail() — attempted even when SMTP is unconfigured or
        // failed, so a host with a working local MTA (e.g. cPanel/exim) delivers.
        // Guard it: on many managed/hardened PHP builds mail() is absent or in
        // disable_functions, and calling it there is a FATAL "undefined function"
        // that `@` cannot suppress — which would crash the whole request.
        if (function_exists('mail')) {
            $headers = 'MIME-Version: 1.0' . "\r\n"
                . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
                . 'From: ' . self::encodeName($fromName) . ' <' . $fromEmail . '>' . "\r\n"
                . 'Reply-To: ' . $fromEmail . "\r\n";
            $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);
            if ($ok) { self::$lastTransport = 'mail'; return true; }
        }
        if (self::$lastError === '') {
            self::$lastError = self::configured()
                ? 'All transports failed'
                : (function_exists('mail')
                    ? 'No SMTP configured and the host mail() reported failure'
                    : 'No SMTP configured, and PHP mail() is unavailable on this host — set SMTP_HOST / SMTP_USERNAME / AV_SMTP_PASSWORD');
        }
        error_log("[mail] all transports failed → {$to}: {$subject} — " . self::$lastError);
        return false;
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
