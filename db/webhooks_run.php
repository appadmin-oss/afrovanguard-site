<?php
/**
 * db/webhooks_run.php — deliver/retry queued webhook deliveries (cron).
 *
 * Recommended cron (every minute):
 *   * * * * * /usr/bin/php /path/to/site/db/webhooks_run.php >> /path/to/logs/webhooks.log 2>&1
 *
 * Optional arg: max deliveries to process per run (default 50).
 * Safe to run with no endpoints configured — it's a no-op then.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$max = isset($argv[1]) && ctype_digit((string) $argv[1]) ? (int) $argv[1] : 50;
$r = Webhooks::runQueue($max);
fwrite(STDOUT, gmdate('c') . " webhooks: processed {$r['processed']}, delivered {$r['ok']}\n");
exit(0);
