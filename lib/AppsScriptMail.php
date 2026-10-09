<?php
/**
 * lib/AppsScriptMail.php — mail through Afrovanguard's own Google Apps Script.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS ROAD EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The site sends through Google SMTP. When that fails — a port the shared host has
 * closed, a refused App Password, an account Google has paused for SMTP — sign-in
 * codes and receipts stop, and on a host with no shell there is nothing quick an
 * operator can do. A Google Apps Script web app is reached over HTTPS (443, which
 * every host allows), runs as a Google account, and can send with MailApp. So the
 * site deploys its own: apps-script/Afrovanguard_Mail.gs, the same protocol as the
 * Africa GATES script — POST {action, token, data, source}, actions `mail.send`,
 * `mail.quota` and `ping`, every one refused without the shared SECRET.
 *
 * ── WHAT IT CARRIES, AND WHAT IT DOES NOT ──────────────────────────────────────
 *
 * MailApp allows about 100 recipients a day on a gmail.com account (1,500 on
 * Workspace). Plenty for mail somebody is WAITING for; nowhere near a newsletter.
 * So Mailer offers this road to one-to-one mail only ({@see Mailer::isBulk()}).
 * MailApp cannot carry custom headers either — one more reason announcements
 * (List-Unsubscribe) never take it. Attachments it can carry, base64 in the JSON.
 *
 * ── SETTINGS ───────────────────────────────────────────────────────────────────
 *
 * Studio → Rules & AI → Setup → Email: AV_GAS_URL (the /exec address) and AV_GAS_SECRET.
 * Read through Config, so the Studio wins, then a config.php constant, then the
 * environment; GAS_URL / GAS_SECRET (the Africa GATES names) are accepted too.
 *
 * Every failure is a sentence an operator can act on — this is read on the Studio's
 * System page by somebody whose members are not getting their codes.
 */
declare(strict_types=1);

final class AppsScriptMail
{
    /** Where the script lives in this repository, for the messages that send people to it. */
    public const SCRIPT = 'apps-script/Afrovanguard_Mail.gs';

    /** @var \Closure(string,array,int):array{status:int,body:string,error:string} */
    private \Closure $http;

    public function __construct(
        private readonly string $url,
        private readonly string $secret,
        ?\Closure $http = null,
    ) {
        $this->http = $http ?? \Closure::fromCallable([self::class, 'curl']);
    }

    /** From the site's own settings. */
    public static function boot(): self
    {
        return new self(self::url(), self::secret());
    }

    /** The web-app address in force: Studio → config.php → env; GAS_URL accepted. */
    public static function url(): string
    {
        return self::setting(['AV_GAS_URL', 'GAS_URL']);
    }

    /** The shared secret in force. Never shown anywhere. */
    public static function secret(): string
    {
        return self::setting(['AV_GAS_SECRET', 'GAS_SECRET']);
    }

    /** Both halves are required — the script refuses everything without the secret. */
    public static function configured(): bool
    {
        return self::url() !== '' && self::secret() !== '';
    }

    private static function setting(array $names): string
    {
        foreach ($names as $n) {
            $v = class_exists('Config') ? Config::str($n) : (string) (getenv($n) ?: '');
            if (trim($v) !== '') return trim($v);
        }
        return '';
    }

    /**
     * Send one message. Returns the remaining daily allowance (null when the script
     * did not say). Throws RuntimeException with a sentence an operator can act on.
     *
     * @param array{to:string,subject:string,html?:string,text?:string,name?:string,reply_to?:string,bcc?:string,attachments?:array} $message
     */
    public function send(array $message): ?int
    {
        $r = $this->call('mail.send', self::payload($message), 25);
        if (!$r['ok']) throw new \RuntimeException('Apps Script: ' . $r['message']);
        return isset($r['remaining']) && is_numeric($r['remaining']) ? (int) $r['remaining'] : null;
    }

    /**
     * Is the script deployed with mail, who does it send as, and how much may it
     * still send today? A READ — it sends nothing.
     *
     * @return array{ok:bool, reachable:bool, detail:string, remaining:?int, account:string}
     */
    public function check(): array
    {
        $r = $this->call('mail.quota', [], 12);
        if (!$r['ok']) {
            return ['ok' => false, 'reachable' => !empty($r['reachable']), 'detail' => ucfirst($r['message']) . '.',
                    'remaining' => isset($r['remaining']) && is_numeric($r['remaining']) ? (int) $r['remaining'] : null,
                    'account' => ''];
        }
        $left = isset($r['remaining']) && is_numeric($r['remaining']) ? (int) $r['remaining'] : null;
        $who  = trim((string) ($r['account'] ?? ''));
        $detail = 'The Apps Script answered' . ($who !== '' ? ' as ' . $who : '')
            . ($left !== null ? '; it may send to ' . $left . ' more recipient' . ($left === 1 ? '' : 's') . ' today' : '') . '.';
        if ($left === 0) $detail .= ' Today’s allowance is spent — mail waits for SMTP or the next road until Google resets it.';
        return ['ok' => $left === null || $left > 0, 'reachable' => true, 'detail' => $detail,
                'remaining' => $left, 'account' => $who];
    }

    /**
     * What the script's mailSend() reads.
     *
     * Attachments arrive as Mailer's ['path' => …, 'name' => …] (or ['content' => raw
     * bytes, 'name' => …]) and leave as base64 with a MIME type. A file that cannot be
     * read is left out rather than sent empty.
     */
    public static function payload(array $m): array
    {
        $files = [];
        foreach ((array) ($m['attachments'] ?? []) as $a) {
            if (!is_array($a)) continue;
            $bytes = null;
            if (isset($a['content']) && is_string($a['content'])) $bytes = $a['content'];
            elseif (!empty($a['path']) && is_file((string) $a['path']) && is_readable((string) $a['path'])) {
                $bytes = (string) file_get_contents((string) $a['path']);
            }
            if ($bytes === null) continue;
            $name = (string) ($a['name'] ?? (isset($a['path']) ? basename((string) $a['path']) : 'file'));
            $files[] = ['name' => $name, 'mime' => (string) ($a['mime'] ?? self::mime($name, $a['path'] ?? null)),
                        'content' => base64_encode($bytes)];
        }
        $out = [
            'to'       => (string) ($m['to'] ?? ''),
            'subject'  => (string) ($m['subject'] ?? ''),
            'html'     => (string) ($m['html'] ?? ''),
            'text'     => (string) ($m['text'] ?? ''),
            'name'     => (string) ($m['name'] ?? ''),
            'reply_to' => (string) ($m['reply_to'] ?? ''),
            'attachments' => $files,
        ];
        if (!empty($m['bcc'])) $out['bcc'] = (string) $m['bcc'];
        return $out;
    }

    private static function mime(string $name, $path): string
    {
        if (is_string($path) && $path !== '' && function_exists('mime_content_type') && is_file($path)) {
            $t = @mime_content_type($path);
            if (is_string($t) && $t !== '') return $t;
        }
        return match (strtolower((string) pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'csv' => 'text/csv', 'txt' => 'text/plain',
            'ics' => 'text/calendar', 'html', 'htm' => 'text/html', 'zip' => 'application/zip',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };
    }

    /** @return array{ok:bool, message:string, reachable?:bool}&array<string,mixed> */
    private function call(string $action, array $data, int $timeout): array
    {
        if (trim($this->url) === '') {
            return ['ok' => false, 'message' => 'no Apps Script address is set (Studio → Rules & AI → Setup → Email → Apps Script web-app URL)'];
        }
        if (trim($this->secret) === '') {
            return ['ok' => false, 'message' => 'no Apps Script secret is set (Studio → Rules & AI → Setup → Email → Apps Script secret) — the script refuses mail without one'];
        }
        $r = ($this->http)($this->url, ['action' => $action, 'token' => $this->secret, 'data' => $data, 'source' => 'afrovanguard-web'], $timeout);
        $status = (int) ($r['status'] ?? 0);
        $error  = (string) ($r['error'] ?? '');
        $body   = (string) ($r['body'] ?? '');

        if ($error !== '') {
            return ['ok' => false, 'message' => 'could not reach it (' . $error . ') — check the address, and that this host can make outbound HTTPS calls'];
        }
        if ($status === 404) {
            return ['ok' => false, 'message' => 'there is no web app at that address (HTTP 404) — copy the Web app URL from Deploy → Manage deployments; it ends in /exec'];
        }
        if ($status === 401 || $status === 403 || ($status >= 200 && $status < 400 && self::looksLikeGoogleSignIn($body))) {
            return ['ok' => false, 'reachable' => true, 'message' => 'Google asked for a sign-in instead of running the script — the deployment must have '
                . '“Execute as: Me” and “Who has access: Anyone”. Edit it under Deploy → Manage deployments, and use the /exec address, not /dev'];
        }
        if ($status < 200 || $status >= 400) {
            return ['ok' => false, 'message' => 'could not reach it (HTTP ' . $status . ')'];
        }
        $j = json_decode($body, true);
        if (!is_array($j)) {
            return ['ok' => false, 'reachable' => true, 'message' => 'it did not answer JSON — usually an old or different deployment at that address: open the script, '
                . 'paste the latest ' . self::SCRIPT . ', then Deploy → Manage deployments → edit → Version: New version'];
        }
        $ok  = (bool) ($j['ok'] ?? $j['success'] ?? false);
        $msg = trim((string) ($j['message'] ?? $j['error'] ?? ''));
        if (!$ok) {
            if (str_starts_with($msg, 'Unknown action')) {
                $msg = 'the deployed script is older than the ' . $action . ' action — paste the latest ' . self::SCRIPT . ' and deploy a New version';
            } elseif ($msg === 'Bad token') {
                $msg = 'the script refused the secret (Bad token) — the Apps Script secret in Studio → Rules & AI → Setup → Email must be exactly the text between '
                    . 'the quotes in const SECRET at the top of the script; if you changed it there, deploy a New version';
            } elseif (stripos($msg, 'no SECRET set') !== false) {
                $msg = 'the deployed script has no SECRET of its own yet — put a long random text between the quotes in const SECRET = \'\'; '
                    . 'save, deploy a New version, and paste the same text into Studio → Rules & AI → Setup → Email';
            } elseif (stripos($msg, 'quota') !== false || stripos($msg, 'too many times') !== false) {
                $msg = 'the Google account’s daily MailApp allowance is used up (about 100 recipients a day on Gmail, 1,500 on Workspace) — '
                    . 'it resets within 24 hours; until then mail goes by the next road';
            } elseif ($msg === '') {
                $msg = 'it refused the request without saying why';
            }
        }
        return ['ok' => $ok, 'reachable' => true, 'message' => $msg] + $j;
    }

    /** Google's sign-in or "you need access" page, served when the deployment is not public. */
    private static function looksLikeGoogleSignIn(string $body): bool
    {
        $b = ltrim($body);
        if ($b === '' || $b[0] === '{') return false;
        return stripos($b, 'accounts.google.com') !== false || stripos($b, 'ServiceLogin') !== false
            || stripos($b, 'You need access') !== false;
    }

    /** @return array{status:int, body:string, error:string} */
    private static function curl(string $url, array $payload, int $timeout): array
    {
        if (!function_exists('curl_init')) return ['status' => 0, 'body' => '', 'error' => 'PHP has no curl extension'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            // Apps Script answers a POST with a 302 to script.googleusercontent.com;
            // the body is at the far end of it. curl turns the POST into a GET on a
            // 302, which is exactly what Google expects there.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $body = curl_exec($ch);
        $err  = $body === false ? (string) curl_error($ch) : '';
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $code, 'body' => is_string($body) ? $body : '', 'error' => $err];
    }
}
