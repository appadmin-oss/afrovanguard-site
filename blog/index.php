<?php
/** Custom-owned /blog → /diary permanent redirect (PHP fallback). */
declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$rest = preg_replace('#^/?blog/?#', '', ltrim($path, '/'));
$qs   = $_SERVER['QUERY_STRING'] ?? '';
$dest = '/diary/' . $rest . ($qs !== '' ? '?' . $qs : '');
header('Location: ' . $dest, true, 301);
header('Cache-Control: max-age=86400');
echo 'The blog has moved to <a href="' . htmlspecialchars($dest, ENT_QUOTES) . '">the Afrovanguard Diary</a>.';
