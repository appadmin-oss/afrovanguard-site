<?php
/**
 * tests/walletview.test.php — the member's Wallet page (portal/views/wallet.php).
 *
 * The page draws lib/MemberStatement.php and nothing else. What it promises:
 *   · the balance is the statement's, across all three ledgers and units
 *   · money reported but not confirmed is shown, and said not to count
 *   · dues are shown as paid and move no balance
 *   · a ledger that could not be read is named, with a way to try again,
 *     rather than the page showing a smaller balance as if it were all of it
 *   · a member with nothing gets an honest empty statement
 *   · it is in the portal for everybody, from the one registry
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$wvRender = static function (array $myStatement): string {
    ob_start();
    require AV_ROOT . '/portal/views/wallet.php';
    return (string) ob_get_clean();
};

/* ── against real rows in all three ledgers ────────────────────────────── */

$wvPdo = Database::pdo();
Membership::ensure();
OfflinePayments::ensure();
$wvPdo->prepare("INSERT INTO lms_users (name, email, password_hash, role) VALUES ('Wallet Member', 'wallet@wv.test', 'x', 'member')")->execute();
$wv = (int) $wvPdo->query("SELECT id FROM lms_users WHERE email = 'wallet@wv.test'")->fetchColumn();
$wvT1 = gmdate('Y-m-d H:i:s', time() - 7200);
$wvT2 = gmdate('Y-m-d H:i:s', time() - 3600);

$wvPdo->prepare("INSERT INTO payments (user_id, kind, reference, provider, amount_kobo, status, created_at, paid_at, months)
                 VALUES (?, 'membership', 'WV-DUES', 'paystack', 1000000, 'paid', ?, ?, 12)")->execute([$wv, $wvT1, $wvT1]);
NgvDb::pdo()->prepare("INSERT INTO ngv_charges (member_id, kind, amount, period, created_at) VALUES (?, 'membership', 10000, '2026', ?)")->execute([$wv, $wvT1]);
NgvDb::pdo()->prepare("INSERT INTO ngv_payments (member_id, amount, method, reference, created_at) VALUES (?, 2500, 'transfer', 'WV-NGV', ?)")->execute([$wv, $wvT2]);
$wvPdo->prepare("INSERT INTO offline_payments (user_id, purpose, months, amount_ngn, method, reference, paid_on, status, created_at)
                 VALUES (?, 'dues', 3, 3000, 'transfer', 'WV-OFF', ?, 'pending', ?)")->execute([$wv, gmdate('Y-m-d'), $wvT2]);

$wvHtml = $wvRender(MemberStatement::for($wv));

ck('wallet view: the balance is the statement\'s — ₦7,500 owed across kobo and naira ledgers',
   (bool) preg_match('~Balance</span><span class="avp-chip[^"]*avp-chip--gold">To pay</span></div>\s*<div class="avp-kpi-v av-num">₦7,500</div>~', $wvHtml));
ck('wallet view: paid ₦12,500 of ₦20,000 charged', str_contains($wvHtml, '₦12,500') && str_contains($wvHtml, 'Of ₦20,000 charged.'));
ck('wallet view: the unconfirmed ₦3,000 is shown, and says it is not counted',
   str_contains($wvHtml, 'Waiting to be confirmed') && str_contains($wvHtml, '>₦3,000<') && str_contains($wvHtml, 'Not counted yet'));
ck('wallet view: dues are shown as paid, not as a charge',
   (bool) preg_match('~<b>Dues — 12 months</b>.*?<span class="avp-wallet-amt is-green av-num">Paid ₦10,000</span>~s', $wvHtml));
ck('wallet view: the NGV charge and payment are each a line, with a direction a screen reader hears',
   str_contains($wvHtml, '+₦10,000<span class="avp-wallet-sr"> (charged)</span>') && str_contains($wvHtml, '−₦2,500<span class="avp-wallet-sr"> (paid)</span>'));
ck('wallet view: newest first — the payment just reported leads', strpos($wvHtml, 'awaiting confirmation') < strpos($wvHtml, 'NGV membership fee'));
ck('wallet view: a member who owes is told where to pay, NGV fees included', str_contains($wvHtml, 'href="#ngv-account"'));
ck('wallet view: a complete statement raises no warning', !str_contains($wvHtml, 'avp-wallet-warn'));

$wvPdo->prepare('DELETE FROM payments WHERE user_id = ?')->execute([$wv]);
$wvPdo->prepare('DELETE FROM offline_payments WHERE user_id = ?')->execute([$wv]);
$wvPdo->prepare('DELETE FROM lms_users WHERE id = ?')->execute([$wv]);
NgvDb::pdo()->prepare('DELETE FROM ngv_charges WHERE member_id = ?')->execute([$wv]);
NgvDb::pdo()->prepare('DELETE FROM ngv_payments WHERE member_id = ?')->execute([$wv]);

/* ── nothing, and not all of it ────────────────────────────────────────── */

$wvEmpty = $wvRender(MemberStatement::for(0));
ck('wallet view: a member with nothing gets an empty statement that says so',
   str_contains($wvEmpty, 'Nothing has been charged to you or paid by you yet.') && str_contains($wvEmpty, 'You owe nothing.'));
ck('wallet view: no waiting tile when nothing is waiting', !str_contains($wvEmpty, 'Waiting to be confirmed'));

$wvPart = MemberStatement::for(0);
$wvPart['problems'] = ['ngv']; $wvPart['complete'] = false;
$wvBad = $wvRender($wvPart);
ck('wallet view: an unreadable ledger is named, in an alert', (bool) preg_match('~<p class="avp-wallet-warn" role="alert">We could not read your NextGen Vanguard fees~', $wvBad));
ck('wallet view: with a way to try again that reloads the page', (bool) preg_match('~<a href="/portal/\?r=\d+#wallet">Try again</a>~', $wvBad));
ck('wallet view: and the balance says it may be incomplete', str_contains($wvBad, 'Possibly incomplete.'));
ck('wallet view: an empty list from a failed read is not called "nothing charged"', !str_contains($wvBad, 'Nothing has been charged') && str_contains($wvBad, 'Nothing could be shown'));

$wvCredit = MemberStatement::for(0);
$wvCredit['balance_kobo'] = -250000;
ck('wallet view: a credit balance reads as credit, not a debt', str_contains($wvRender($wvCredit), 'In credit') && !str_contains($wvRender($wvCredit), 'To pay'));

/* ── in the portal ─────────────────────────────────────────────────────── */

ck('wallet view: everybody has it, from the one registry',
   PortalNav::visible('wallet', []) && PortalNav::SECTIONS['wallet']['view'] === 'wallet' && in_array('wallet', PortalNav::views([]), true));
$wvIdx = (string) file_get_contents(AV_ROOT . '/portal/index.php');
ck('wallet view: the shell reads the statement once, and the badge is what is owed',
   substr_count($wvIdx, 'MemberStatement::for(') === 1 && str_contains($wvIdx, "'wallet'      => [\$walletOwed > 0"));
ck('wallet view: no inline styles', !preg_match('~\bstyle\s*=~i', (string) file_get_contents(AV_ROOT . '/portal/views/wallet.php')));

/* ── NGG volunteer pay, beside the statement (lib/NggPay.php) ────────────── */

$wvKeep = getenv('NGG_WEBHOOK_SECRET');
putenv('NGG_WEBHOOK_SECRET=' . str_repeat('n', 40));
$wvSeen = null;
NggPay::$transport = static function (string $url, string $body, array $headers) use (&$wvSeen): array {
    $wvSeen = compact('url', 'body', 'headers');
    return ['code' => 200, 'body' => json_encode(['ok' => true, 'wallet' => [
        'owed' => 6000, 'earned' => 9000, 'paid' => 3000, 'daysEarned' => 3, 'rate' => 3000, 'frequency' => 'weekly', 'on' => true, 'active' => true, 'centre' => 'Egbeda',
        'days' => [['day' => '2026-10-24', 'status' => 'earned', 'amount' => 3000, 'paid' => false, 'reasonText' => ''],
                   ['day' => '2026-10-23', 'status' => 'excluded', 'amount' => 0, 'paid' => false, 'reasonText' => 'Present, but no activity recorded'],
                   ['day' => '<script>', 'status' => 'earned', 'amount' => 1]],
        'payouts' => [['period' => '12 Oct – 18 Oct 2026', 'amount' => 3000, 'days' => 1, 'methodText' => 'Bank transfer', 'paidAt' => '2026-10-20T10:00:00Z', 'reference' => 'TRF-1'],
                      ['period' => 'void one', 'amount' => 500, 'void' => true]]]])];
};
$wvR = NggPay::forUser(['id' => 0, 'email' => ' Tobi@Example.org ']);
$wvTs = ''; $wvSig = '';
foreach ($wvSeen['headers'] ?? [] as $h) { if (str_starts_with($h, 'X-NGG-Timestamp: ')) $wvTs = substr($h, 17); if (str_starts_with($h, 'X-NGG-Signature: ')) $wvSig = substr($h, 17); }
ck('wallet view: NGG is asked at its pay door, for this member’s email', str_ends_with((string) ($wvSeen['url'] ?? ''), '/api/hooks/afrovanguard-pay.php')
   && (json_decode((string) $wvSeen['body'], true)['email'] ?? '') === 'tobi@example.org');
ck('wallet view: …signed with the shared secret for this purpose only, so it fits no other NGG door',
   $wvSig === 'sha256=' . hash_hmac('sha256', 'volunteer_pay.' . $wvTs . '.' . $wvSeen['body'], str_repeat('n', 40)));
ck('wallet view: what NGG owes them comes back, with its days', ($wvR['wallet']['owed'] ?? 0) === 6000 && count($wvR['wallet']['days']) === 2);
ck('wallet view: NGG’s answer is kept to what the page draws: no reference, no voided payout, no malformed day',
   !str_contains(json_encode($wvR), 'TRF-1') && count($wvR['wallet']['payouts']) === 1 && !str_contains(json_encode($wvR), 'script'));
ck('wallet view: a day not paid says why', ($wvR['wallet']['days'][1]['why'] ?? '') === 'Present, but no activity recorded' && $wvR['wallet']['days'][1]['earned'] === false);
NggPay::$transport = static fn() => ['code' => 401, 'body' => '{"ok":false}'];
ck('wallet view: NGG refusing is said, not shown as nothing owed', NggPay::forUser(['id' => 0, 'email' => 'tobi@example.org']) === ['ok' => false, 'error' => 'bad-signature']);
NggPay::$transport = static fn() => ['code' => 200, 'body' => '{"ok":true,"wallet":null}'];
ck('wallet view: somebody who is not an NGG volunteer has no NGG pay', NggPay::forUser(['id' => 0, 'email' => 'tobi@example.org']) === ['ok' => true, 'wallet' => null]);
NggPay::$transport = null;
putenv('NGG_WEBHOOK_SECRET');
ck('wallet view: with no link to NGG set up, NGG is not asked', NggPay::forUser(['id' => 0, 'email' => 'tobi@example.org'])['error'] === 'not-configured');
if ($wvKeep !== false) putenv('NGG_WEBHOOK_SECRET=' . $wvKeep);

$wvNg = $wvRender(MemberStatement::for(0));
ck('wallet view: the NGG pay card is on the page, hidden until NGG says they volunteer, and outside the statement’s balance',
   (bool) preg_match('~<section class="avp-card avp-wallet-ngg" id="walletNgg"[^>]*hidden>~', $wvNg) && str_contains($wvNg, 'Not part of your Afrovanguard balance'));
ck('wallet view: the page loads it only when the Wallet opens', str_contains((string) file_get_contents(AV_ROOT . '/portal/index.php'), "/portal/wallet.js")
   && str_contains((string) file_get_contents(AV_ROOT . '/portal/wallet.js'), "view === 'wallet'") && !str_contains((string) file_get_contents(AV_ROOT . '/portal/wallet.js'), 'innerHTML'));
