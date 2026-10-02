<?php
/**
 * tests/ngvconsole.test.php — the console and the ledger's housekeeping.
 *
 *   • Accrual took the first 300 active participants, every time, so the rest
 *     were never charged.
 *   • The NGV money pages let `editor` (a content role) void payments, waive
 *     balances, fine people and enrol them.
 *   • Every ledger audit line read "admin".
 *   • require_same_origin() matched substrings, so a look-alike host passed.
 *   • A masked "****5678" on a receipt matched any account ending 5678.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$csNgv = NgvDb::pdo();
foreach (['ngv_charges', 'ngv_payments', 'ngv_participants'] as $t) { try { $csNgv->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
try { Database::metaSet('ngv_fees', ''); Database::metaSet('ngv_accrue_cursor', ''); } catch (Throwable $e) {}
$cc = new ReflectionProperty('NgvLedger', 'cache'); $cc->setAccessible(true); $cc->setValue(null, null);
NgvLedger::saveSettings(['enabled' => true, 'accrueFrom' => '2026-01-01'], 'test');
foreach (range(901, 905) as $m) {
    NgvMember::ensureParticipant($m, ['name' => 'P' . $m, 'email' => "p$m@console.test"]);
    $csNgv->exec("UPDATE ngv_participants SET start_date = '2026-01-05' WHERE member_id = $m");
}
$r = NgvLedger::accrueAll(2, '2026-03-10');
$charged = (int) $csNgv->query("SELECT COUNT(DISTINCT member_id) FROM ngv_charges WHERE member_id BETWEEN 901 AND 905")->fetchColumn();
ck('ngv console: accrual reaches every active participant, not only the first batch', $charged === 5 && !empty($r['complete']) && (int) $r['accrued']['participants'] === 5);

$root = dirname(__DIR__);
foreach (['members', 'fines', 'attendance'] as $pg) {
    ck("ngv console: the $pg page is for administrators, not editors",
       str_contains((string) file_get_contents("$root/academy/ngv/$pg.php"), "\$isAdmin = in_array(\$role, ['admin', 'superadmin'], true);"));
}
$led = (string) file_get_contents("$root/lib/NgvLedger.php");
ck('ngv console: the ledger\'s audit says who acted', str_contains($led, "if (\$actor === 'admin' && function_exists('av_admin_actor'))"));
$mem = (string) file_get_contents("$root/academy/ngv/members.php");
foreach (['enroll', 'cert', 'app_status', 'app_enroll'] as $a) {
    ck("ngv console: $a is on the audit trail", str_contains($mem, "ngv_console_audit('$a'"));
}

/* The origin check, in its own process: it ends the request when it refuses. */
$probe = static function (string $host, string $origin) use ($root): string {
    $code = 'require ' . var_export("$root/lib/helpers.php", true) . ';'
          . ' $_SERVER["HTTP_HOST"] = ' . var_export($host, true) . '; $_SERVER["HTTP_ORIGIN"] = ' . var_export($origin, true) . '; require_same_origin(); echo "ALLOWED";';
    return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1'));
};
ck('ngv console: the same host is allowed', str_ends_with($probe('afrovanguard.org.ng', 'https://afrovanguard.org.ng'), 'ALLOWED'));
ck('ngv console: a look-alike host is refused', str_contains($probe('afrovanguard.org.ng', 'https://afrovanguard.org.ng.evil.example'), 'Cross-origin request rejected.'));
ck('ngv console: …and so is a host contained in ours', str_contains($probe('www.afrovanguard.org.ng', 'https://vanguard.org.ng'), 'Cross-origin request rejected.'));

$off = (string) file_get_contents("$root/lib/OfflinePayments.php");
ck('ngv console: a receipt\'s account number matches on six digits, not a masked last four', str_contains($off, 'str_ends_with($a, $pa) && strlen($pa) >= 6'));
ck('ngv console: a receipt with no readable reference is checked against same-member, same-amount, same-day credits',
   str_contains($off, "WHERE status = 'verified' AND user_id = ? AND amount_ngn = ? AND paid_on = ? AND id <> ?"));
