<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $msg, int $status = 400, ?string $field = null): void
{
    $payload = ['ok' => false, 'error' => $msg];
    if ($field) $payload['field'] = $field;
    json_response($payload, $status);
}

function input_post(string $key, ?string $default = null): ?string
{
    if (!isset($_POST[$key])) return $default;
    $v = $_POST[$key];
    if (is_array($v)) return $default;
    $v = trim((string)$v);
    return $v === '' ? $default : $v;
}

function input_email(string $key): ?string
{
    $v = input_post($key);
    if (!$v) return null;
    return filter_var($v, FILTER_VALIDATE_EMAIL) ?: null;
}

function require_field(string $key, ?string $value): string
{
    if ($value === null || $value === '') json_error("Missing required field: $key", 422, $key);
    return $value;
}

function client_ip(): string
{
    $candidates = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($candidates as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = explode(',', $_SERVER[$h])[0];
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function check_honeypot(): void
{
    if (!empty($_POST['website'])) {
        // Silent success: don't reveal that we detected the bot
        json_response(['ok' => true, 'silent' => true]);
    }
}

function log_line(string $channel, string $message, array $context = []): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $line = sprintf("[%s] %s %s\n", date('c'), $message, $context ? json_encode($context) : '');
    @file_put_contents($dir . "/$channel.log", $line, FILE_APPEND);
}

function slugify(string $s): string
{
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}

function short_ref(): string
{
    return strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}
