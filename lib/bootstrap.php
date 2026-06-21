<?php
/**
 * lib/bootstrap.php — single entry point for the Diary backend.
 *
 * Loads shared config + the modular data layer. Include this from any
 * diary endpoint (index.php, article.php, api.php). Everything the Diary
 * needs flows from here, so the pieces stay connected and in sync.
 */
declare(strict_types=1);

define('AV_ROOT', dirname(__DIR__));

// Reuse the site's config.php if deployed; otherwise fall back to safe
// public defaults so the Diary runs standalone (and in local dev).
$cfg = AV_ROOT . '/config.php';
if (is_file($cfg)) { require_once $cfg; }
if (!defined('SITE_URL'))         define('SITE_URL', 'https://afrovanguard.org.ng');
if (!defined('AV_DB_PATH'))       define('AV_DB_PATH', AV_ROOT . '/db/diary.sqlite');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DiaryRepository.php';
