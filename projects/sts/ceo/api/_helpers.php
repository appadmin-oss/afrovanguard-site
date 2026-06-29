<?php
// ─────────────────────────────────────────────────────────────────────
// STS · Shared helpers
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

function sts_cors_and_json() {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}
function sts_ok($data = null) {
    echo json_encode(['ok' => true] + ($data === null ? [] : ['data' => $data]));
    exit;
}
function sts_fail($error, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}
function sts_client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return 'unknown';
}
function sts_rate_limit($bucket, $limit) {
    $ip = sts_client_ip();
    $hour = date('YmdH');
    $file = DATA_DIR . '/.rate_' . preg_replace('/[^a-z_]/', '', $bucket) . '.json';
    $rates = file_exists($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
    foreach ($rates as $k => $_) { if (strpos($k, $hour) !== 0) unset($rates[$k]); }
    $key = $hour . ':' . $ip;
    $rates[$key] = ($rates[$key] ?? 0) + 1;
    @file_put_contents($file, json_encode($rates), LOCK_EX);
    return $rates[$key] <= $limit;
}

function sts_appscript_post($action, $data = []) {
    if (!defined('APPS_SCRIPT_URL') || strpos(APPS_SCRIPT_URL, 'http') !== 0) {
        return ['ok' => false, 'error' => 'APPS_SCRIPT_URL not configured'];
    }
    $payload = json_encode(['action' => $action, 'data' => $data, 'key' => APPS_SCRIPT_KEY]);
    $ch = curl_init(APPS_SCRIPT_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: text/plain;charset=utf-8'],
        // Aggressive timeouts — shared hosts kill at 30s; we have other work
        // (emails) waiting after this. Better to skip Sheets than 500.
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $code >= 400) {
        error_log('[STS Sheets] HTTP ' . $code . ' err=' . $err);
        return ['ok' => false, 'error' => 'Apps Script HTTP ' . $code . ' ' . $err];
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : ['ok' => false, 'error' => 'Invalid response'];
}

/**
 * After we've sent the success JSON back to the client, call this to
 * close the HTTP connection so the client doesn't wait while we do slow
 * post-response work (Sheets, emails). Works under php-fpm and Apache
 * mod_php (with output buffering). No-op on CLI / unusual SAPIs.
 */
function sts_finish_response() {
    // FastCGI / php-fpm — preferred, lets PHP keep running with the client closed
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return;
    }
    // Apache mod_php — flush + close approach
    if (!headers_sent()) {
        ignore_user_abort(true);
        @header('Connection: close');
        @header('Content-Length: ' . ob_get_length());
    }
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();
}
function sts_appscript_get($action, $params = []) {
    if (!defined('APPS_SCRIPT_URL') || strpos(APPS_SCRIPT_URL, 'http') !== 0) {
        return ['ok' => false, 'error' => 'APPS_SCRIPT_URL not configured'];
    }
    $q = http_build_query(array_merge(['action' => $action, 'key' => APPS_SCRIPT_KEY], $params));
    $url = APPS_SCRIPT_URL . (strpos(APPS_SCRIPT_URL, '?') !== false ? '&' : '?') . $q;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code >= 400) return ['ok' => false, 'error' => 'HTTP ' . $code];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : ['ok' => false, 'error' => 'Invalid response'];
}
function sts_sanitize($s, $max = 500) {
    if ($s === null) return '';
    $s = trim(strip_tags((string)$s));
    // mbstring is missing on many shared hosts — fall back to substr.
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}
function sts_read_json_body() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Detect PHPMailer (composer or manual install at api/phpmailer/src/).
 * Returns the FQCN to instantiate, or null if not available.
 */
function sts_phpmailer_available() {
    static $loaded = null;
    if ($loaded !== null) return $loaded;
    // Composer autoload
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) require_once $autoload;
    // Manual install: api/phpmailer/src/*.php
    $manual = __DIR__ . '/phpmailer/src/';
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer') && file_exists($manual . 'PHPMailer.php')) {
        require_once $manual . 'Exception.php';
        require_once $manual . 'PHPMailer.php';
        require_once $manual . 'SMTP.php';
    }
    $loaded = class_exists('PHPMailer\\PHPMailer\\PHPMailer') ? 'PHPMailer\\PHPMailer\\PHPMailer' : false;
    return $loaded;
}

/**
 * Send an HTML email. Uses PHPMailer if available (with SMTP if USE_SMTP), else falls back to mail().
 * @return bool true on success
 */
function sts_send_email($to, $subject, $html_body, $text_body = '', $reply_to = '') {
    $text_body = $text_body ?: trim(preg_replace('/\s+/', ' ', strip_tags($html_body)));
    $to = trim((string)$to);
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('[STS Email] invalid recipient: ' . substr((string)$to, 0, 80));
        return false;
    }

    $cls = sts_phpmailer_available();
    $errors = [];

    // Loud warning if SMTP is asked for but not actually configured —
    // the most common reason emails "don't send" is that USE_SMTP=true
    // but SMTP_HOST / SMTP_USER were left blank, so we silently fall
    // through to mail() which shared hosts often discard.
    if (defined('USE_SMTP') && USE_SMTP && (empty(SMTP_HOST) || empty(SMTP_USER))) {
        error_log('[STS Email] USE_SMTP=true but SMTP_HOST or SMTP_USER is empty — fix api/config.php.');
    }

    // Path 1 — PHPMailer / SMTP (most reliable when configured)
    if ($cls) {
        try {
            $mail = new $cls(true);
            if (defined('USE_SMTP') && USE_SMTP && SMTP_HOST) {
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->Port       = (int)SMTP_PORT;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                $mail->SMTPSecure = SMTP_SECURE === 'ssl' ? 'ssl' : 'tls';
                // Reasonable timeout so a hanging SMTP host can't tie up
                // the whole post-response request.
                $mail->Timeout    = 12;
                if (defined('SMTP_DEBUG') && (int)SMTP_DEBUG > 0) {
                    $mail->SMTPDebug   = (int)SMTP_DEBUG;
                    $mail->Debugoutput = function ($str) { error_log('[STS Email/SMTP] ' . trim($str)); };
                }
            }
            $mail->setFrom(NOTIFY_FROM, NOTIFY_FROM_NAME);
            $mail->addAddress($to);
            if ($reply_to && filter_var($reply_to, FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($reply_to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body    = $html_body;
            $mail->AltBody = $text_body;
            $mail->CharSet = 'UTF-8';
            if ($mail->send()) return true;
            $errors[] = 'PHPMailer returned false: ' . ($mail->ErrorInfo ?? 'unknown');
            error_log('[STS Email/PHPMailer] send() returned false · ' . ($mail->ErrorInfo ?? 'no ErrorInfo'));
        } catch (\Throwable $e) {
            $errors[] = 'PHPMailer: ' . $e->getMessage();
            error_log('[STS Email/PHPMailer] ' . $e->getMessage());
        }
    } else {
        error_log('[STS Email] PHPMailer class not found — expected at api/phpmailer/src/ or api/vendor/.');
    }

    // Path 2 — Resend (HTTPS POST, no SMTP, no PHPMailer needed).
    // Most shared hosts that block mail() and SMTP still permit outbound
    // HTTPS, so this is the most reliable cross-host option. Sign up
    // free at https://resend.com (3,000 emails/month, single API key).
    if (defined('RESEND_API_KEY') && !empty(RESEND_API_KEY) && RESEND_API_KEY !== 'YOUR_RESEND_KEY_HERE') {
        $r = sts_resend_send($to, $subject, $html_body, $text_body, $reply_to);
        if ($r) return true;
        $errors[] = 'Resend send failed';
    }

    // Path 3 — PHP mail() last resort.
    // We intentionally keep the header list MINIMAL here: many shared hosts
    // silently drop messages that include Return-Path or Message-ID set by
    // userland code (they want the MTA to inject those). Likewise, using the
    // 5th `-f` parameter often gets rejected unless the host has added the
    // user to its sendmail trusted list. We rely on mail()'s envelope sender
    // being injected by the host MTA, and we set From/Reply-To/MIME only.
    $boundary = '=_STS_' . md5(uniqid('', true));
    $from_header = 'From: =?UTF-8?B?' . base64_encode(NOTIFY_FROM_NAME) . '?= <' . NOTIFY_FROM . '>';
    $headers = [
        'MIME-Version: 1.0',
        $from_header,
        'Reply-To: ' . ($reply_to && filter_var($reply_to, FILTER_VALIDATE_EMAIL) ? $reply_to : NOTIFY_FROM),
        'X-Mailer: STS-PHP',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body = "--{$boundary}\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: 7bit\r\n\r\n"
          . $text_body . "\r\n\r\n"
          . "--{$boundary}\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: 7bit\r\n\r\n"
          . $html_body . "\r\n\r\n"
          . "--{$boundary}--\r\n";
    $subj_enc = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    // Try with -f envelope-sender first (helps with SPF on some hosts).
    // If the host rejects it (safe_mode-ish restrictions), retry without.
    $ok = @mail($to, $subj_enc, $body, implode("\r\n", $headers), '-f' . NOTIFY_FROM);
    if (!$ok) $ok = @mail($to, $subj_enc, $body, implode("\r\n", $headers));
    if (!$ok) {
        $e = error_get_last();
        $errors[] = 'mail(): ' . ($e['message'] ?? 'returned false');
        error_log('[STS Email/mail()] ' . ($e['message'] ?? 'returned false') . ' to=' . $to);
    }
    if (!$ok && $errors) {
        error_log('[STS Email] all paths failed for ' . $to . ' — ' . implode(' | ', $errors));
    }
    return (bool)$ok;
}

/**
 * Send via Resend API (https://resend.com/docs/api-reference/emails/send-email).
 * Single HTTP POST with API key in header. Returns true on success.
 */
function sts_resend_send($to, $subject, $html, $text, $reply_to = '') {
    $payload = [
        'from'    => NOTIFY_FROM_NAME ? (NOTIFY_FROM_NAME . ' <' . NOTIFY_FROM . '>') : NOTIFY_FROM,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
        'text'    => $text,
    ];
    if ($reply_to) $payload['reply_to'] = [$reply_to];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . RESEND_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw   = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log('[STS Email/Resend] curl: ' . $err);
        return false;
    }
    if ($code >= 200 && $code < 300) return true;
    error_log('[STS Email/Resend] HTTP ' . $code . ' · ' . substr((string)$raw, 0, 200));
    return false;
}

/**
 * Compose a polished HTML email.
 *
 * The template is intentionally inline-styled and table-based: it has to
 * render the same in Gmail, Outlook (incl. desktop with its MSO engine),
 * Apple Mail (incl. dark mode), and mobile clients (iOS Mail, Gmail app).
 *
 * Design language: STS brand (Montserrat display, blue + crimson rule),
 * with Playfair Display for the title (graceful Georgia fallback in mail
 * clients that won't load webfonts). Dark mode supported via
 * `prefers-color-scheme: dark`.
 *
 * @param string $title    H1 of the email
 * @param string $lede     intro paragraph (HTML allowed)
 * @param array  $rows     label => value rows for the details card
 * @param string $cta_label optional button label
 * @param string $cta_url   optional button URL
 * @param string $kicker   small pill above the title (e.g. "Session Confirmed")
 */
function sts_email_template($title, $lede, $rows = [], $cta_label = '', $cta_url = '', $kicker = 'Street-To-Stardom · 2026') {
    $rows_html = '';
    foreach ($rows as $label => $value) {
        $rows_html .=
            '<div style="margin-top:16px;">' .
              '<div class="label" style="font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#0420B5;margin-bottom:4px;">' .
              htmlspecialchars($label) .
              '</div>' .
              '<div class="value" style="font-size:16px;font-weight:600;color:#111111;">' . $value . '</div>' .
            '</div>';
    }
    $card_html = $rows ? '<div class="card" style="background-color:#EEF1FF;border:1px solid #C7D1FF;border-radius:8px;padding:20px;margin-bottom:24px;">' . $rows_html . '</div>' : '';

    $cta_html = '';
    if ($cta_label && $cta_url) {
        $cta_html =
'<!--[if mso]>
<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="' . htmlspecialchars($cta_url) . '"
style="height:48px;width:260px;v-text-anchor:middle;" arcsize="12%" fillcolor="#0732F7" stroke="f">
<w:anchorlock/>
<center style="color:#ffffff;font-family:Montserrat,sans-serif;font-size:15px;font-weight:600;">
' . htmlspecialchars($cta_label) . '
</center>
</v:roundrect>
<![endif]-->
<!--[if !mso]><!-- -->
<a href="' . htmlspecialchars($cta_url) . '" class="button" style="display:inline-block;padding:14px 24px;background-color:#0732F7;color:#ffffff !important;text-decoration:none;border-radius:8px;font-weight:600;font-size:15px;">'
. htmlspecialchars($cta_label) .
'</a>
<!--<![endif]-->';
    }

    $preheader = htmlspecialchars(strip_tags($lede));
    $preheader = function_exists('mb_substr')
        ? mb_substr($preheader, 0, 140, 'UTF-8')
        : substr($preheader, 0, 140);
    $year = date('Y');

    return
'<!DOCTYPE html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>' . htmlspecialchars($title) . '</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
<style>
body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
table { border-collapse: collapse; }
body { margin: 0; padding: 0; width: 100%; background-color: #f4f4f4; font-family: \'Montserrat\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', Arial, sans-serif; color: #111111; }
a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
.email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; }
.section { padding: 32px 40px; }
.section-tight { padding: 20px 40px; }
h1 { font-family: \'Playfair Display\', Georgia, serif; font-size: 28px; margin: 0 0 16px 0; color: #111111; line-height: 1.2; }
p { font-size: 15px; line-height: 1.6; margin: 0 0 16px 0; color: #4b5563; }
strong { color: #111111; }
.badge { display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; background-color: #EEF1FF; color: #0420B5; padding: 6px 10px; border-radius: 999px; margin-bottom: 16px; }
.footer { background-color: #fafafa; border-top: 1px solid #eeeeee; padding: 24px 32px; text-align: center; }
.footer p { font-size: 11px; color: #6b7280; margin-bottom: 10px; line-height: 1.5; }
.footer a { color: #6b7280; text-decoration: underline; }
.note { font-size: 13px; color: #6b7280; }
@media (prefers-color-scheme: dark) {
  body { background-color: #0B0B12 !important; }
  .email-container { background-color: #14141C !important; }
  p, .note { color: #cfcfd4 !important; }
  h1, strong, .sts-mast { color: #ffffff !important; }
  .badge { background-color: rgba(81,114,255,0.16) !important; color: #A8BBFF !important; }
  .card { background-color: rgba(81,114,255,0.08) !important; border-color: rgba(81,114,255,0.28) !important; }
  .label { color: #A8BBFF !important; }
  .value { color: #ffffff !important; }
  .footer { background-color: #0B0B12 !important; border-top-color: #232330 !important; }
  .footer p, .footer a { color: #94949C !important; }
}
@media screen and (max-width:600px) {
  .section, .section-tight { padding: 24px 20px !important; }
  h1 { font-size: 24px !important; }
  .button { width: 100% !important; text-align: center; box-sizing: border-box; }
}
</style>
</head>
<body>
<div style="display:none;font-size:1px;color:#f4f4f4;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">' . $preheader . '</div>
<center>
<table width="100%" cellpadding="0" cellspacing="0">
<tr><td style="padding:40px 10px;">
<table class="email-container" width="100%" cellpadding="0" cellspacing="0">
<tr><td class="section-tight" align="center" style="background:linear-gradient(135deg,#0732F7,#C8102E);">
  <div class="sts-mast" style="font-family:Montserrat,sans-serif;font-size:22px;font-weight:800;color:#ffffff;letter-spacing:-0.01em;">
    STREET<span style="color:rgba(255,255,255,0.6);">-</span>TO<span style="color:rgba(255,255,255,0.6);">-</span>STARDOM
  </div>
  <div style="font-size:10px;letter-spacing:2px;font-weight:600;color:rgba(255,255,255,0.85);text-transform:uppercase;margin-top:4px;">
    2026 Series · Empowered 360°
  </div>
</td></tr>
<tr><td class="section">
<span class="badge">' . htmlspecialchars($kicker) . '</span>
<h1>' . htmlspecialchars($title) . '</h1>
<p>' . $lede . '</p>
' . $card_html . '
' . $cta_html . '
<p style="margin-top:28px;">Warm regards,<br><strong>The 2026 Series Team</strong><br>
<span style="font-size:12px;color:#0420B5;text-transform:uppercase;letter-spacing:1px;font-weight:600;">An Afrovanguard initiative</span></p>
</td></tr>
<tr><td class="footer">
<p>Street-To-Stardom · An Afrovanguard initiative<br>Alimosho, Lagos, Nigeria</p>
<p>You are receiving this email because you confirmed your participation in the 2026 Leadership Series.</p>
<p>
<a href="mailto:' . htmlspecialchars(NOTIFY_EMAIL) . '">Contact the team</a>
&nbsp;·&nbsp;
<a href="https://afrovanguard.org.ng">afrovanguard.org.ng</a>
&nbsp;·&nbsp;
<a href="https://afrovanguard.org.ng/privacy">Privacy</a>
</p>
<p>© ' . $year . ' Afrovanguard. All rights reserved.</p>
</td></tr>
</table>
</td></tr>
</table>
</center>
</body>
</html>';
}
