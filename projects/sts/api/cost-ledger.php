<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$out = [];
try {
    $pdo = db();
    $stmt = $pdo->query("SELECT item_key, description, unit_cost_ngn, last_updated FROM cost_ledger ORDER BY unit_cost_ngn ASC");
    foreach ($stmt as $row) {
        $out[] = [
            'key' => $row['item_key'],
            'description' => $row['description'],
            'unit_cost_ngn' => (float)$row['unit_cost_ngn'],
            'updated' => $row['last_updated'],
        ];
    }
} catch (Throwable $e) {
    log_line('db', 'cost-ledger read failed', ['err' => $e->getMessage()]);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=600');
echo json_encode(['ok' => true, 'ledger' => $out], JSON_UNESCAPED_UNICODE);
