<?php
/**
 * community/index.php — the Community now lives inside the member portal
 * (a tab), not as a standalone page. This endpoint just forwards there,
 * preserving any ?space= deep-link and the members-only sign-in gate.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$space = ($_GET['space'] ?? '') !== '' ? preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $_GET['space'])) : '';
$dest  = '/portal/' . ($space !== '' ? '?space=' . rawurlencode($space) : '') . '#community';

$u = LmsAuth::user();
$target = $u ? $dest : ('/login?next=' . rawurlencode($dest));

if (!headers_sent()) header('Location: ' . $target, true, 302);
echo '<!doctype html><meta charset="utf-8"><title>Community — Afrovanguard</title>'
   . '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES) . '">'
   . '<p style="font-family:system-ui;margin:3rem">The Community has moved into your member portal. '
   . '<a href="' . htmlspecialchars($target, ENT_QUOTES) . '">Open it here →</a></p>';
