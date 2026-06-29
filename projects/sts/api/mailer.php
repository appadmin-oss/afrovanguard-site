<?php
/**
 * STS · mailer
 *
 * Real SMTP via PHPMailer when SMTP_HOST is configured; transparent fallback
 * to PHP's native mail() when it isn't (useful for local + first-deploy).
 * Both paths return a bool — endpoints treat send failures as best-effort and
 * never block the form submission.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function send_email(string $to, string $subject, string $bodyHtml, ?string $bodyText = null): bool
{
    $fromAddr = env('SMTP_FROM', 'hello@streettostardom.org') ?: 'hello@streettostardom.org';
    $fromName = env('SMTP_FROM_NAME', 'Street-To-Stardom') ?: 'Street-To-Stardom';
    $bodyText ??= trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $bodyHtml) ?? $bodyHtml));

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        log_line('mailer', 'invalid to-address', ['to' => $to]);
        return false;
    }

    $host = env('SMTP_HOST');
    $hasSmtp = $host !== null && class_exists(PHPMailer::class);

    if ($hasSmtp) {
        return send_email_smtp($to, $subject, $bodyHtml, $bodyText, $fromAddr, $fromName);
    }
    return send_email_native($to, $subject, $bodyHtml, $bodyText, $fromAddr, $fromName);
}

function send_email_smtp(string $to, string $subject, string $bodyHtml, string $bodyText, string $from, string $fromName): bool
{
    $m = new PHPMailer(true);
    try {
        $m->isSMTP();
        $m->Host = (string)env('SMTP_HOST');
        $m->Port = (int)(env('SMTP_PORT', '587') ?: 587);
        $user = env('SMTP_USER');
        $pass = env('SMTP_PASS');
        if ($user) {
            $m->SMTPAuth = true;
            $m->Username = $user;
            $m->Password = (string)$pass;
        }
        $encryption = strtolower((string)env('SMTP_ENCRYPTION', $m->Port === 465 ? 'ssl' : 'tls'));
        if ($encryption === 'ssl') $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        elseif ($encryption === 'tls') $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        else $m->SMTPSecure = false;

        $m->Timeout = 8;
        $m->CharSet = 'UTF-8';
        $m->Encoding = 'base64';

        $m->setFrom($from, $fromName);
        $m->addAddress($to);
        if ($replyTo = env('SMTP_REPLY_TO')) $m->addReplyTo($replyTo);

        $m->Subject = $subject;
        $m->isHTML(true);
        $m->Body = $bodyHtml;
        $m->AltBody = $bodyText;

        $m->send();
        return true;
    } catch (PHPMailerException $e) {
        log_line('mailer', 'smtp send failed', ['to' => $to, 'err' => $e->getMessage()]);
        // Last-resort fall-back so the form still feels responsive even if SMTP is misconfigured.
        return send_email_native($to, $subject, $bodyHtml, $bodyText, $from, $fromName);
    }
}

function send_email_native(string $to, string $subject, string $bodyHtml, string $bodyText, string $from, string $fromName): bool
{
    $boundary = 'sts-' . bin2hex(random_bytes(8));
    $headers = [
        'From: ' . sprintf('%s <%s>', mb_encode_mimeheader($fromName, 'UTF-8', 'B'), $from),
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        'X-Mailer: STS-Mailer',
    ];
    $body = "--$boundary\r\n"
          . "Content-Type: text/plain; charset=utf-8\r\n"
          . "Content-Transfer-Encoding: 8bit\r\n\r\n"
          . $bodyText . "\r\n\r\n"
          . "--$boundary\r\n"
          . "Content-Type: text/html; charset=utf-8\r\n"
          . "Content-Transfer-Encoding: 8bit\r\n\r\n"
          . $bodyHtml . "\r\n\r\n"
          . "--$boundary--\r\n";
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    try {
        $sent = @mail($to, $encodedSubject, $body, implode("\r\n", $headers), '-f' . $from);
        if (!$sent) log_line('mailer', 'mail() failed', ['to' => $to]);
        return (bool)$sent;
    } catch (Throwable $e) {
        log_line('mailer', 'native exception', ['err' => $e->getMessage()]);
        return false;
    }
}

function inbox_for(string $category): string
{
    $map = [
        'volunteer'    => env('VOLUNTEERS_INBOX', 'volunteers@streettostardom.org'),
        'partner'      => env('PARTNERSHIPS_INBOX', 'partnerships@streettostardom.org'),
        'partnership'  => env('PARTNERSHIPS_INBOX', 'partnerships@streettostardom.org'),
        'sponsor'      => env('SPONSORS_INBOX', 'sponsors@streettostardom.org'),
        'press'        => env('PRESS_INBOX', 'press@streettostardom.org'),
        'general'      => env('GENERAL_INBOX', 'hello@streettostardom.org'),
        'complaint'    => env('SAFEGUARDING_INBOX', env('GENERAL_INBOX', 'safeguarding@streettostardom.org')),
    ];
    return $map[$category] ?? ($map['general']);
}
