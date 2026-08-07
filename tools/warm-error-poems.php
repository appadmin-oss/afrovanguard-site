<?php
/**
 * tools/warm-error-poems.php — pre-generate the error-page poems.
 *
 * Runs Claude (when ANTHROPIC_API_KEY is set) to refresh the cached poem pool
 * for every mood, so error pages always have fresh, dynamic verses without ever
 * calling the network at render time. Safe to run from cron, e.g. every 12h:
 *
 *   0 *\/12 * * *  php /path/to/tools/warm-error-poems.php >/dev/null 2>&1
 *
 * CLI only.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once dirname(__DIR__) . '/lib/bootstrap.php';

if (!class_exists('AvBot') || !AvBot::configured()) {
    fwrite(STDERR, "ANTHROPIC_API_KEY not configured — nothing to warm (curated poems still work).\n");
    exit(0);
}

$results = ErrorPoem::warm();
foreach ($results as $mood => $ok) {
    fwrite(STDOUT, sprintf("%-8s %s\n", $mood, $ok ? 'refreshed ✓' : 'skipped'));
}
