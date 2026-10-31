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
foreach (['members', 'fines', 'attendance', 'desk'] as $pg) {
    ck("ngv console: the $pg page is for administrators, not editors",
       str_contains((string) file_get_contents("$root/academy/ngv/$pg.php"), "\$isAdmin = in_array(\$role, ['admin', 'superadmin'], true);"));
}
$led = (string) file_get_contents("$root/lib/NgvLedger.php");
ck('ngv console: the ledger\'s audit says who acted', str_contains($led, "if (\$actor === 'admin' && function_exists('av_admin_actor'))"));
$mem = (string) file_get_contents("$root/academy/ngv/members.php");
foreach (['enroll', 'cert', 'app_status', 'app_invite'] as $a) {
    ck("ngv console: $a is on the audit trail", str_contains($mem, "ngv_console_audit('$a'"));
}
$desk = (string) file_get_contents("$root/academy/ngv/desk.php");
ck('ngv console: every desk action, and a refused completion, is on the audit trail',
   str_contains($desk, 'ngv_desk_audit($act, $target') && str_contains($desk, "ngv_desk_audit('complete_refused'"));

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

/* ── Every control says what it does, and every problem has a way to act ──
 * A button reading "Open", "Review" or "Save" on its own makes a coordinator
 * guess which record, and what happens; a tile saying "3 asked for help" with
 * nothing to press is a fact with no road to it. The sweep reads the visible
 * text of every <a> and <button> on the NGV pages (tags stripped, arrows
 * dropped) and refuses one that is only a bare verb. It does not read labels
 * built by JavaScript, and says so. */
$vagueSweep = static function (string $src): array {
    $bare = ['review', 'view', 'open', 'details', 'more', 'manage', 'ok', 'continue', 'submit', 'go', 'save', 'edit',
             'show', 'find', 'filter', 'send', 'send it', 'post it', 'record it', 'report it', 'reject', 'void', 'waive',
             'revoke', 'attach', 'receipt', 'preview', 'reset', 'lift', 'excuse', 'decline', 'clear', 'enrol', 'cancel'];
    $hits = [];
    /* PHP inside an attribute (href="?m=<?= $id ?>") carries a ">" that would
       end the tag early, so every PHP block is folded to a marker first. */
    $src = (string) preg_replace('~<\?(?:=|php)?.*?\?>~s', "\x01", $src);
    if (preg_match_all('~<(a|button)\b[^>]*>(.*?)</\1>~si', $src, $m)) {
        foreach ($m[2] as $inner) {
            if (str_contains($inner, "\x01")) continue;   // a label chosen at render time is read by its own assertion
            $t = mb_strtolower(trim(preg_replace('~[\s→↗›‹×]+~u', ' ', strip_tags($inner))));
            if (in_array($t, $bare, true)) $hits[] = $t;
        }
    }
    return $hits;
};
ck('ngv labels: the sweep names a bare verb when it sees one (planted)',
   $vagueSweep('<a class="btn sm" href="?m=<?= (int)$id ?>">Open</a><button>Go →</button><button>Record payment</button>') === ['open', 'go']);
foreach (['members', 'desk', 'register', 'status', 'mou', 'dashboard', '_dashboard-body', 'pay', 'fines', 'attendance', 'books', 'edit'] as $pg) {
    $hits = $vagueSweep((string) file_get_contents("$root/academy/ngv/$pg.php"));
    ck("ngv labels: no control on $pg.php is only a bare verb" . ($hits ? ' (found: ' . implode(', ', $hits) . ')' : ''), !$hits);
}
ck('ngv labels: the Outstanding tile goes to the arrears list and to who asked for help',
   str_contains($mem, '<a href="#arrears">See the ') && str_contains($mem, '<a href="#requests">Answer '));
ck('ngv labels: a row in arrears goes to the payment form on that record, which exists',
   str_contains($mem, '#pay">Record payment or waive</a>') && str_contains($mem, '<div class="sect" id="pay"><h3>Record a payment</h3>'));
ck('ngv labels: rejecting an application says before it happens that nobody is emailed',
   str_contains($mem, 'They are NOT emailed'));
ck('ngv labels: the console says what an action did, not a generic "Saved"',
   str_contains($mem, "toast(done[act] || 'Saved ✓')") && str_contains($mem, "if(act==='app_invite'){ toast('Invited — reference '"));
$dbody = (string) file_get_contents("$root/academy/ngv/_dashboard-body.php");
ck('ngv labels: a balance with card payment off still offers a way to pay (the transfer receipt)',
   str_contains($dbody, 'href="#noff" data-open="noff">Paid by transfer? Send the receipt</a>')
   && str_contains((string) file_get_contents("$root/academy/ngv/pay.js"), "closest('[data-open=\"noff\"]')"));
ck('ngv labels: a desk button that cannot act yet says why',
   str_contains($desk, 'aria-describedby="h-done-missing"') && str_contains($desk, 'id="h-done-missing"'));
