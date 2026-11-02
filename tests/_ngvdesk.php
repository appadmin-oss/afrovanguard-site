<?php
/**
 * tests/_ngvdesk.php — registers one application at the CACENTRE desk, the way
 * staff do: every checklist item ticked, the guarantor seen with ID, the MoU
 * version recorded, the membership fee waived. Shared by the suites that need a
 * registered vanguard rather than an invited applicant. Not a test itself
 * (tests/run.php only runs *.test.php).
 */
declare(strict_types=1);

if (!function_exists('ngv_test_register')) {
    function ngv_test_register(int $appId, array $guarantor = []): array
    {
        $app = NgvMember::application($appId);
        if (!$app) return ['ok' => false, 'error' => 'no application'];
        NgvRegistration::tick($appId, array_keys(NgvRegistration::checklistFor($app)), 0);
        $g = $guarantor + ['g_name' => 'Desk Guarantor', 'g_relationship' => 'parent', 'g_phone' => '08030000000', 'g_email' => 'guarantor.' . $appId . '@desk.test'];
        $seen = NgvRegistration::guarantorSeen($appId, $g, 'National ID', 0);
        if (empty($seen['ok'])) return $seen;
        NgvRegistration::setMouVersion($appId, (string) (Ngv::get()['mou']['version'] ?? 'test'));
        return NgvRegistration::complete($appId, 0, ['how' => 'waived', 'reason' => 'test registration']);
    }
}
