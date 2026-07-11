<?php
/**
 * Headless STS content — public read of admin-managed content sections.
 *
 * The STS pages (sponsor, impact, programs, …) fetch their editable copy/data
 * from here instead of hardcoding it. Content is authored in the Studio
 * (admin → STS Content) and stored as JSON per section.
 *
 * GET ?section=<slug>  → { ok, section, data, updated }
 * GET  (no section)    → { ok, sections:[{section,updated_at}] }
 */
require_once __DIR__ . '/../lib/bootstrap.php';
if (function_exists('send_security_headers')) send_security_headers('public');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'GET') json_out(['ok' => false, 'error' => 'GET required.'], 405);
if (!av_rate_ok('sts_content', 240, 60)) json_out(['ok' => false, 'error' => 'Too many requests.'], 429);

$section = preg_replace('/[^a-z0-9_.-]/i', '', (string) ($_GET['section'] ?? ''));

try {
    $pdo = Database::pdo();
    if ($section === '') {
        $rows = $pdo->query("SELECT section, updated_at FROM sts_content ORDER BY section")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        json_out(['ok' => true, 'sections' => $rows]);
    }
    $st = $pdo->prepare("SELECT data, updated_at FROM sts_content WHERE section = ? LIMIT 1");
    $st->execute([$section]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) json_out(['ok' => false, 'error' => 'Unknown section.'], 404);
    header('Cache-Control: public, max-age=120');
    json_out([
        'ok'      => true,
        'section' => $section,
        'data'    => json_decode((string) $r['data'], true) ?: new stdClass(),
        'updated' => $r['updated_at'],
    ]);
} catch (Throwable $e) {
    error_log('[sts-content] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Content temporarily unavailable.'], 500);
}
