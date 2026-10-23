<?php
/**
 * tests/setuptests.test.php — a Setup test that is not ready still answers.
 *
 * The Workspace Test button was drawn disabled whenever the key could not be
 * read, so a key set in config.php that the site could not use made the press
 * do nothing at all. Each test is now always pressable, and one that is not
 * ready carries the reason.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$stSrc = (string) file_get_contents(dirname(__DIR__) . '/admin/app.js');
ck('setup tests: no test button is drawn disabled', !preg_match('/setup-test[^\n]*disabled title="Nothing configured/', $stSrc));
if (!defined('AV_GDRIVE_SERVICE_ACCOUNT') && AvSettings::get('AV_GDRIVE_SERVICE_ACCOUNT') === null) {
    $stKeep = getenv('AV_GDRIVE_SERVICE_ACCOUNT');
    putenv('AV_GDRIVE_SERVICE_ACCOUNT={"type":"service_account","client_email":"x@y.iam.gserviceaccount.com"}');
    $ws = array_values(array_filter(AvSettings::testable(), static fn($t) => $t['key'] === 'google_workspace'))[0] ?? [];
    ck('setup tests: an unusable Workspace key is not ready, and says why', empty($ws['ready']) && str_contains((string) ($ws['why'] ?? ''), 'private_key'));
    $r = AvSettings::test('google_workspace');
    ck('setup tests: pressing Test gives the same reason', !$r['ok'] && str_contains($r['detail'], 'private_key'));
    putenv($stKeep === false ? 'AV_GDRIVE_SERVICE_ACCOUNT' : 'AV_GDRIVE_SERVICE_ACCOUNT=' . $stKeep);
}
