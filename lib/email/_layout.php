<?php
declare(strict_types=1);

/**
 * Afrovanguard transactional email renderer.
 * Usage:
 *   ['subject' => $s, 'html' => $h, 'text' => $t] = av_email_render('welcome', $vars);
 *   av_send_email($mailer, 'ada@example.com', 'welcome', $vars);
 * Every template lists its required vars in its header. Pass display-ready strings
 * (formatted dates, "₦25,000.00"). Escaping is done here with e().
 */

if (!function_exists('e')) {
    function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

function av_t(string $tpl, array $v): string
{
    return preg_replace_callback('/\{(\w+)\}/', fn($m) => (string) ($v[$m[1]] ?? ''), $tpl);
}

function av_email_render(string $template, array $v): array
{
    if (!preg_match('/^[a-z_]+$/', $template) || !is_file(__DIR__ . "/templates/{$template}.php")) {
        throw new InvalidArgumentException("Unknown email template: {$template}");
    }
    $v += [
        'base_url'        => 'https://afrovanguard.org.ng',
        'postal_address'  => 'Alimosho, Lagos, Nigeria',
        'preferences_url' => 'https://afrovanguard.org.ng/portal/#settings',
    ];
    $v['logo_url'] = $v['logo_url'] ?? $v['base_url'] . '/assets/site/email-seal.png';
    $v['art_base'] = $v['art_base'] ?? $v['base_url'] . '/assets/site/email';
    $subject = '';
    $preheader = '';
    $frame = 'center';
    ob_start();
    require __DIR__ . "/templates/{$template}.php";
    $body = ob_get_clean();
    ob_start();
    require __DIR__ . ($frame === 'card' ? '/_frame_card.php' : '/_frame.php');
    $html = ob_get_clean();
    return ['subject' => $subject, 'html' => $html, 'text' => av_email_text($subject, $body)];
}

function av_email_text(string $subject, string $html): string
{
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    $html = preg_replace('/<\?php.*?\?>/s', '', $html);
    $html = preg_replace_callback('/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/s', fn($m) => trim(strip_tags($m[2])) . ' (' . html_entity_decode($m[1]) . ')', $html);
    $html = preg_replace('/<(br|\/tr|\/h1|\/div)[^>]*>/i', "\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n\s*\n+/", "\n\n", $text);
    return trim($subject . "\n\n" . trim($text)) . "\n\n— Afrovanguard\n";
}

function av_send_email(\PHPMailer\PHPMailer\PHPMailer $mail, string $to, string $template, array $v): bool
{
    $r = av_email_render($template, $v);
    $mail->CharSet = 'UTF-8';
    $mail->isHTML(true);
    $mail->addAddress($to);
    $mail->Subject = $r['subject'];
    $mail->Body    = $r['html'];
    $mail->AltBody = $r['text'];
    return $mail->send();
}
