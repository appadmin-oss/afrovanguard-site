<?php
/**
 * STS · rate limiter — sliding-window per (ip, endpoint).
 * Persists in api_rate_limits; falls back to file-store if DB unavailable.
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

function rate_limit(string $endpoint, int $max = 10, int $window = 60): void
{
    $ip = client_ip();
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT request_count, UNIX_TIMESTAMP(window_start) AS started FROM api_rate_limits WHERE ip_address = :ip AND endpoint = :ep FOR UPDATE");
        $stmt->execute([':ip' => $ip, ':ep' => $endpoint]);
        $row = $stmt->fetch();
        $now = time();
        if (!$row) {
            $pdo->prepare("INSERT INTO api_rate_limits (ip_address, endpoint, request_count, window_start) VALUES (:ip, :ep, 1, NOW())")
                ->execute([':ip' => $ip, ':ep' => $endpoint]);
            $pdo->commit();
            return;
        }
        if (($now - (int)$row['started']) > $window) {
            $pdo->prepare("UPDATE api_rate_limits SET request_count = 1, window_start = NOW() WHERE ip_address = :ip AND endpoint = :ep")
                ->execute([':ip' => $ip, ':ep' => $endpoint]);
            $pdo->commit();
            return;
        }
        if ((int)$row['request_count'] >= $max) {
            $pdo->commit();
            header('Retry-After: ' . max(1, $window - ($now - (int)$row['started'])));
            json_error('Too many requests. Please slow down.', 429);
        }
        $pdo->prepare("UPDATE api_rate_limits SET request_count = request_count + 1 WHERE ip_address = :ip AND endpoint = :ep")
            ->execute([':ip' => $ip, ':ep' => $endpoint]);
        $pdo->commit();
    } catch (Throwable $e) {
        // DB failed — fall back to file-based limiter so endpoints still gate
        try { if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable) {}
        file_rate_limit($endpoint, $max, $window);
    }
}

function file_rate_limit(string $endpoint, int $max, int $window): void
{
    $ip = client_ip();
    $dir = __DIR__ . '/logs/rl';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $f = $dir . '/' . md5($ip . '|' . $endpoint) . '.json';
    $now = time();
    $state = is_file($f) ? json_decode(file_get_contents($f) ?: '{}', true) : [];
    if (!is_array($state) || ($now - ($state['t'] ?? 0)) > $window) {
        $state = ['t' => $now, 'c' => 1];
    } else {
        $state['c'] = ($state['c'] ?? 0) + 1;
    }
    @file_put_contents($f, json_encode($state));
    if (($state['c'] ?? 0) > $max) {
        json_error('Too many requests. Please slow down.', 429);
    }
}
