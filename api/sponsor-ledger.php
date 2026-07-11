<?php
/**
 * Public, headless cost/impact ledger for the STS sponsor page.
 *
 * Serves the admin-editable tiers (amount → impact line) plus the sponsor-form
 * config (slider bounds, programs, cycles). The page renders these instead of
 * the values that used to be hardcoded in the template.
 *
 * GET → { ok, tiers:[{amount_ngn,label,impact_line}], config:{...}, updated }
 */
require_once __DIR__ . '/../lib/bootstrap.php';
if (function_exists('send_security_headers')) send_security_headers('public');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'GET') json_out(['ok' => false, 'error' => 'GET required.'], 405);
if (!av_rate_ok('sts_ledger', 120, 60)) json_out(['ok' => false, 'error' => 'Too many requests.'], 429);

try {
    $pdo = Database::pdo();
    $tiers = $pdo->query(
        "SELECT amount_ngn, label, impact_line FROM sponsor_tiers WHERE active = 1 ORDER BY sort, amount_ngn"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $tiers = array_map(fn($t) => [
        'amount_ngn'  => (int) $t['amount_ngn'],
        'label'       => (string) $t['label'],
        'impact_line' => (string) $t['impact_line'],
    ], $tiers);

    $row    = $pdo->query("SELECT data, updated_at FROM sts_content WHERE section = 'sponsor' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $config = $row ? (json_decode((string) $row['data'], true) ?: []) : [];

    // Brief CDN/browser cache — the ledger changes rarely.
    header('Cache-Control: public, max-age=120');
    json_out(['ok' => true, 'tiers' => $tiers, 'config' => $config, 'updated' => $row['updated_at'] ?? null]);
} catch (Throwable $e) {
    error_log('[sponsor-ledger] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Ledger temporarily unavailable.'], 500);
}
