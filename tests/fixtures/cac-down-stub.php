<?php
/**
 * tests/fixtures/cac-down-stub.php — a CACENTRE that is failing, for
 * tests/cacbridge.test.php. The file named by CAC_STUB_MODE says how:
 *   blank500   every request answers HTTP 500 with an empty body (the live
 *              site when its PHP could not start)
 *   badsig     a JSON refusal: the bridge secret differs
 *   nomember   a JSON refusal about one member, which is not a fault
 */
declare(strict_types=1);

$mode = trim((string) @file_get_contents((string) getenv('CAC_STUB_MODE')));
header('Content-Type: ' . ($mode === 'blank500' ? 'text/html' : 'application/json'));
if ($mode === 'blank500') { http_response_code(500); return true; }
http_response_code(403);
echo json_encode(['ok' => false, 'error' => $mode === 'badsig' ? 'bad-signature' : 'no-member']);
return true;
