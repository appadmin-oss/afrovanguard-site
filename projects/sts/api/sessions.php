<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$program = $_GET['program'] ?? 'all';
$out = [];
try {
    $pdo = db();
    $sql = "SELECT id, program_slug, session_date, location, capacity, volunteers_registered, status
            FROM program_sessions
            WHERE status = 'open' AND session_date > :now";
    $params = [':now' => date('Y-m-d H:i:s')];
    if ($program && $program !== 'all') {
        $sql .= " AND program_slug = :slug";
        $params[':slug'] = $program;
    }
    $sql .= " ORDER BY session_date ASC LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt as $row) {
        $ts = strtotime($row['session_date']);
        $out[] = [
            'id' => (int)$row['id'],
            'date' => date('D d', $ts),
            'month' => date('M Y', $ts),
            'program' => programNameFor($row['program_slug']),
            'venue' => $row['location'] ?: '',
            'cap' => ['total' => (int)$row['capacity'], 'taken' => (int)$row['volunteers_registered']],
        ];
    }
} catch (Throwable $e) {
    log_line('db', 'sessions read failed', ['err' => $e->getMessage()]);
}

function programNameFor(string $slug): string
{
    $map = [
        'next-gen' => 'NextGen Genius Club',
        'summer-school' => 'Summer School',
        'lcasp' => 'LCASP',
        'street-storm' => 'STREET Storm',
    ];
    return $map[$slug] ?? $slug;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
echo json_encode(['ok' => true, 'sessions' => $out], JSON_UNESCAPED_UNICODE);
