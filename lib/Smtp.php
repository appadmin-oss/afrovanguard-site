<?php
/**
 * lib/Smtp.php — a tiny, dependency-free SMTP client.
 *
 * Shared cPanel hosts rarely have Composer/PHPMailer, and PHP mail() cannot do
 * the authenticated submission Gmail/Workspace require — so authenticated mail
 * silently never leaves the box. This speaks just enough SMTP (EHLO, STARTTLS,
 * AUTH LOGIN/PLAIN, MAIL/RCPT/DATA) to deliver through smtp.gmail.com and any
 * standard provider, using only streams + openssl (both present here).
 *
 * Ports: 587 → STARTTLS (default), 465 → implicit TLS (smtps), anything else →
 * plaintext (internal relays). Returns [bool ok, string error]; never throws.
 */
declare(strict_types=1);

final class Smtp
{
    /**
     * @param array{host:string,port:int,user:string,pass:string,secure?:string,verify?:bool,timeout?:int} $cfg
     * @param array{from:string,fromName:string,to:string,subject:string,html:string,text?:string,replyTo?:string,bcc?:string} $msg
     * @return array{0:bool,1:string}
     */
    public static function send(array $cfg, array $msg): array
    {
        $host = (string) ($cfg['host'] ?? '');
        $port = (int) ($cfg['port'] ?? 587);
        $user = (string) ($cfg['user'] ?? '');
        $pass = (string) ($cfg['pass'] ?? '');
        $secure = strtolower((string) ($cfg['secure'] ?? ($port === 465 ? 'ssl' : 'tls')));
        $verify = $cfg['verify'] ?? true;
        $timeout = (int) ($cfg['timeout'] ?? 20);
        if ($host === '') return [false, 'No SMTP host.'];

        $sslOpts = $verify ? [] : ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        $ctx = stream_context_create($sslOpts);
        $implicit = ($secure === 'ssl' || $secure === 'smtps');
        $dsn = ($implicit ? 'ssl://' : 'tcp://') . $host . ':' . $port;

        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($dsn, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) return [false, "Connect failed: {$errstr} ({$errno})"];
        stream_set_timeout($fp, $timeout);

        $err = '';
        $read = function () use ($fp, &$err): array {
            $data = ''; $code = 0;
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                $code = (int) substr($line, 0, 3);
                if (isset($line[3]) && $line[3] === ' ') break;   // last line of a multiline reply
            }
            return [$code, trim($data)];
        };
        $cmd = function (string $c) use ($fp, $read): array { fwrite($fp, $c . "\r\n"); return $read(); };

        try {
            [$code] = $read();
            if ($code !== 220) throw new RuntimeException('Greeting: expected 220');
            $ehloHost = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'afrovanguard.org.ng')) ?: 'localhost';

            [$code, $resp] = $cmd('EHLO ' . $ehloHost);
            if ($code !== 250) throw new RuntimeException('EHLO refused: ' . $resp);

            if (!$implicit && $secure !== '' && $secure !== 'none') {
                [$code] = $cmd('STARTTLS');
                if ($code !== 220) throw new RuntimeException('STARTTLS refused');
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                if (!@stream_socket_enable_crypto($fp, true, $crypto)) throw new RuntimeException('TLS handshake failed');
                [$code, $resp] = $cmd('EHLO ' . $ehloHost);   // re-EHLO over TLS
                if ($code !== 250) throw new RuntimeException('EHLO (post-TLS) refused: ' . $resp);
            }

            if ($user !== '') {
                [$code] = $cmd('AUTH LOGIN');
                if ($code !== 334) throw new RuntimeException('AUTH LOGIN not offered');
                [$code] = $cmd(base64_encode($user));
                if ($code !== 334) throw new RuntimeException('Username rejected');
                [$code, $resp] = $cmd(base64_encode($pass));
                if ($code !== 235) throw new RuntimeException('Authentication failed (check the App Password): ' . $resp);
            }

            $from = (string) $msg['from'];
            [$code, $resp] = $cmd('MAIL FROM:<' . $from . '>');
            if ($code !== 250) throw new RuntimeException('MAIL FROM rejected: ' . $resp);
            $rcpts = array_filter([(string) $msg['to'], (string) ($msg['bcc'] ?? '')]);
            foreach ($rcpts as $rcpt) {
                [$code, $resp] = $cmd('RCPT TO:<' . $rcpt . '>');
                if ($code !== 250 && $code !== 251) throw new RuntimeException('RCPT rejected: ' . $resp);
            }
            [$code] = $cmd('DATA');
            if ($code !== 354) throw new RuntimeException('DATA refused');

            $payload = self::buildMessage($msg);
            // dot-stuff lines beginning with '.' per RFC 5321
            $payload = preg_replace('/^\./m', '..', $payload);
            fwrite($fp, $payload . "\r\n.\r\n");
            [$code, $resp] = $read();
            if ($code !== 250) throw new RuntimeException('Message not accepted: ' . $resp);

            $cmd('QUIT');
            fclose($fp);
            return [true, ''];
        } catch (Throwable $e) {
            if (is_resource($fp)) { @fwrite($fp, "QUIT\r\n"); @fclose($fp); }
            return [false, $e->getMessage()];
        }
    }

    /** Build a MIME multipart/alternative message (plain + HTML). */
    private static function buildMessage(array $msg): string
    {
        $h = "=?UTF-8?B?" . base64_encode((string) $msg['subject']) . "?=";
        $fromName = (string) ($msg['fromName'] ?? 'Afrovanguard');
        $fromHdr = preg_match('/[^\x20-\x7e]/', $fromName)
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $msg['from'] . '>'
            : '"' . str_replace('"', '', $fromName) . '" <' . $msg['from'] . '>';
        $text = (string) ($msg['text'] ?? trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", (string) $msg['html'])))));
        $boundary = 'av-' . bin2hex(random_bytes(10));
        $date = gmdate('D, d M Y H:i:s') . ' +0000';
        $msgId = '<' . bin2hex(random_bytes(12)) . '@' . (preg_replace('/^.*@/', '', (string) $msg['from']) ?: 'afrovanguard.org.ng') . '>';

        $head  = 'Date: ' . $date . "\r\n";
        $head .= 'From: ' . $fromHdr . "\r\n";
        $head .= 'To: <' . $msg['to'] . '>' . "\r\n";
        if (!empty($msg['replyTo'])) $head .= 'Reply-To: <' . $msg['replyTo'] . '>' . "\r\n";
        $head .= 'Message-ID: ' . $msgId . "\r\n";
        $head .= 'Subject: ' . $h . "\r\n";
        $head .= 'MIME-Version: 1.0' . "\r\n";
        $head .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n";

        $body  = '--' . $boundary . "\r\n";
        $body .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        $body .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
        $body .= chunk_split(base64_encode($text)) . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $body .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
        $body .= chunk_split(base64_encode((string) $msg['html'])) . "\r\n";
        $body .= '--' . $boundary . '--';

        return $head . "\r\n" . $body;
    }
}
