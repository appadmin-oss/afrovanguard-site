<?php
/**
 * error.php — central error handler. Wire in .htaccess, e.g.:
 *   ErrorDocument 404 /error.php?code=404
 *   ErrorDocument 403 /error.php?code=403
 *   ErrorDocument 500 /error.php?code=500
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/errors.php';
$code = (int) ($_GET['code'] ?? ($_SERVER['REDIRECT_STATUS'] ?? 500));
if ($code < 400 || $code > 599) $code = 500;
av_error_render($code);
