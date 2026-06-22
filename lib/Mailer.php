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
        if (!self::configured()) { error_log("[mail] skipped (not configured) → {$to}: {$subject}"); return false; }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

        $fromEmail = defined('FROM_EMAIL') ? FROM_EMAIL : SMTP_USERNAME;
        $fromName  = defined('FROM_NAME') ? FROM_NAME : 'Afrovanguard';
        $alt = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html))));

        if (self::loadPhpMailer()) {
            $m = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $m->isSMTP();
                $m->Host       = SMTP_HOST;
                $m->SMTPAuth   = true;
                $m->Username   = SMTP_USERNAME;
                $m->Password   = SMTP_PASSWORD;
                $m->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;
                $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
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
                return true;
            } catch (\Throwable $e) {
                error_log('[mail] PHPMailer to ' . $to . ': ' . ($m->ErrorInfo ?: $e->getMessage()));
                return false;
            }
        }

        // Fallback: PHP mail()
        $headers = 'MIME-Version: 1.0' . "\r\n"
            . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
            . 'From: ' . self::encodeName($fromName) . ' <' . $fromEmail . '>' . "\r\n"
            . 'Reply-To: ' . $fromEmail . "\r\n";
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);
        if (!$ok) error_log("[mail] mail() failed → {$to}: {$subject}");
        return $ok;
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
