<?php
/**
 * tests/fixtures/finalize-race.php — one contender in the payment race.
 *
 *   php tests/fixtures/finalize-race.php <reference> <kobo> <start-at-microtime>
 *
 * Started several times at once by membership-race in tests/payrace.test.php,
 * inheriting the suite's environment (so the same throwaway database). Each
 * waits for the shared start instant, then finalises the same reference — the
 * webhook, the browser return and the cron sweep arriving together.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require dirname(__DIR__, 2) . '/lib/bootstrap.php';
[, $ref, $kobo, $at] = $argv;
$lms = new LmsRepository();
while (microtime(true) < (float) $at) { usleep(200); }
try { echo $lms->finalizePayment($ref, (int) $kobo) ? 'ok' : 'no'; }
catch (Throwable $e) { echo 'err ' . $e->getMessage(); }
