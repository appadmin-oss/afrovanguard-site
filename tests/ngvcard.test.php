<?php
/**
 * tests/ngvcard.test.php — the NGV ID card, its standing, and the two-way scan.
 *
 * Pinned, because each is a way this goes wrong for a real person:
 *   • The card's QR is this site's /q/AVQR-… URL, and the AVQR- code can be
 *     read back out of it — that is what lets the CACENTRE gate check somebody
 *     in from the same code a phone camera opens as a page.
 *   • One standing, most severe first: a suspended account is "Suspended"
 *     whatever else is true; money owed is "Dues owing"; nothing wrong is
 *     "Active". The card and the page print the same one.
 *   • A stranger who scans a card learns the first name and whether this is a
 *     current member — never money, never discipline, never the full name.
 *   • The holder and NGV staff see the whole standing and the gate's verdict.
 *   • A replaced card says it was replaced; an unknown code says nothing else.
 *
 * Run via tests/run.php (provides ck() and render_page()).
 */
declare(strict_types=1);

$ncPdo = Database::pdo();
GateAttendance::ensure(); MemberCards::ensure();
foreach (['gate_member_cards', 'av_member_cards', 'gate_probation'] as $t) { try { $ncPdo->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
foreach (['ngv_charges', 'ngv_payments', 'ngv_participants'] as $t) { try { NgvDb::pdo()->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
AvRules::save(['gate.block_overdue_days' => '0', 'gate.probation_levels' => 'none'], 'test');

$ncUser = static function (string $name, string $role = 'member', string $status = 'active') use ($ncPdo): int {
    $ncPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)')
          ->execute([$name, strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test', 'x', $role, $status]);
    return (int) $ncPdo->lastInsertId();
};
$ncEnrol = static function (int $id, string $name, string $status = 'active', string $track = 'Software'): void {
    NgvDb::pdo()->prepare("INSERT INTO ngv_participants (member_id, name, email, track, status, start_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, '2026-01-05', '2026-01-05', '2026-01-05')")
        ->execute([$id, $name, 'p' . $id . '@example.test', $track, $status]);
};
$ncU = static fn(int $id) => NgvCard::user($id);
/* Sign a viewer in for render_page: a real session row, and LmsAuth's per-request memo cleared. */
$ncAs = static function (?int $id) use ($ncPdo): void {
    $r = new ReflectionClass('LmsAuth');
    foreach (['checked' => false, 'cache' => null] as $k => $v) { $p = $r->getProperty($k); $p->setAccessible(true); $p->setValue(null, $v); }
    unset($_COOKIE[LmsAuth::COOKIE]);
    if ($id === null) return;
    $tok = bin2hex(random_bytes(20));
    $ncPdo->prepare('INSERT INTO lms_sessions (token_hash, user_id, expires_at) VALUES (?, ?, ?)')->execute([hash('sha256', $tok), $id, gmdate('Y-m-d H:i:s', time() + 3600)]);
    $_COOKIE[LmsAuth::COOKIE] = $tok;
};

/* ── The two-way scan ─────────────────────────────────────────────────────── */
$ada = $ncUser('Adaeze Okafor Vanguard'); $ncEnrol($ada, 'Adaeze Okafor Vanguard');
GateAttendance::assignCard($ada, '', 1);
$code = MemberCards::issue($ada, 'test');
$url = MemberCards::scanUrl($code);
ck('Card: the QR carries this site’s /q/ URL with the AVQR- code in it', $url === rtrim(SITE_URL, '/') . '/q/' . $code);
ck('Card: …and the code comes back out of the URL, whatever its case or trailing query', MemberCards::tokenFrom(strtolower($url) . '?utm=x') === $code && MemberCards::tokenFrom($code) === $code);
ck('Card: a URL without a card in it is nothing', MemberCards::tokenFrom('https://example.test/q/7K2P9QX4MA') === '');
$hit = MemberCards::lookup($url);
ck('Card: the gate’s lookup takes the whole scanned URL', $hit !== null && $hit['member_id'] === $ada && !$hit['void']);
/* The gate (cacentre-site/gate/src/credential.ts) matches AVQR- anywhere in
   what it scanned, before its /q/ rule — so this URL is never read as a
   cacentre card. The two regexes must agree; this is the PHP half. */
ck('Card: the code in the URL matches the gate’s AV_CARD pattern', (bool) preg_match('/\b(AVQR-[0-9A-Z]{12,24})\b/i', $url));
$svg = NgvCard::qrSvg($url);
ck('Card: the QR is inline SVG', str_starts_with(ltrim($svg), '<svg'));

/* ── One standing ─────────────────────────────────────────────────────────── */
$st = NgvCard::standing($ncU($ada));
ck('Standing: nothing wrong is Active, and the gate lets them in', $st['code'] === 'active' && $st['tone'] === 'ok' && $st['gate']);
$sus = $ncUser('Sule Suspended', 'member', 'suspended'); $ncEnrol($sus, 'Sule Suspended');
ck('Standing: a suspended account is Suspended, and the gate says no', ($s = NgvCard::standing($ncU($sus)))['code'] === 'suspended' && $s['tone'] === 'bad' && !$s['gate']);
$wd = $ncUser('Wale Withdrawn'); $ncEnrol($wd, 'Wale Withdrawn', 'withdrawn');
ck('Standing: withdrawn from the programme is Withdrawn', NgvCard::standing($ncU($wd))['code'] === 'withdrawn');
$alum = $ncUser('Amaka Alumna'); $ncEnrol($alum, 'Amaka Alumna', 'completed');
ck('Standing: completed is its own word, not "not active"', NgvCard::standing($ncU($alum))['code'] === 'completed');
$pau = $ncUser('Pelumi Paused'); $ncEnrol($pau, 'Pelumi Paused', 'paused');
ck('Standing: paused is Paused', NgvCard::standing($ncU($pau))['code'] === 'paused');
$lea = $ncUser('Lami Learner', 'learner');
ck('Standing: a learner is not a member — learners are not members', NgvCard::standing($ncU($lea))['code'] === 'not_member');
GateAttendance::setProbation($ada, '', 'late twice', 1);
ck('Standing: probation shows, with why', ($p = NgvCard::standing($ncU($ada)))['code'] === 'probation' && str_contains($p['detail'], 'late twice') && $p['public'] === 'Active member');
$ncPdo->exec('DELETE FROM gate_probation');
$owe = $ncUser('Obi Owing'); $ncEnrol($owe, 'Obi Owing');
$ncLedgerWas = NgvLedger::enabled();
NgvLedger::saveSettings(['enabled' => true], 'test');
{
    $ncCh = NgvLedger::charge($owe, 'fine', 3000, 'other', 'test fine', 1);
    ck('Standing: (the fixture charge posted)', !empty($ncCh['ok']));
    $o = NgvCard::standing($ncU($owe));
    ck('Standing: money payable now is Dues owing, with the amount for the member', $o['code'] === 'owing' && str_contains($o['detail'], '₦3,000'));
    ck('Standing: …which a stranger is never told', $o['public'] === 'Active member' && !str_contains($o['public'], '₦'));
}
NgvLedger::saveSettings(['enabled' => $ncLedgerWas], 'test');

/* ── The card ─────────────────────────────────────────────────────────────── */
$card = NgvCard::html($ncU($ada));
ck('Card: shows the name, the NGV ID, the QR and the status word', str_contains($card, 'Adaeze Okafor Vanguard') && str_contains($card, (string) GateAttendance::cardFor($ada))
   && str_contains($card, '<svg') && str_contains($card, '>Active<'));
ck('Card: says when its status was true, because print goes stale', str_contains($card, 'Status as of') && str_contains($card, 'scan for the live one'));
$pub = NgvCard::html($ncU($ada), ['public' => true]);
ck('Card: the public card has the first name only — not even the surname’s initial', str_contains($pub, '>Adaeze<') && !str_contains($pub, 'Okafor') && str_contains($pub, 'aria-hidden="true">A<'));
$none = NgvCard::html($ncU($wd));
ck('Card: somebody with no gate card is told to ask, not shown a broken code', str_contains($none, 'No gate card yet') && !str_contains($none, '<svg'));

/* ── The page a phone opens ───────────────────────────────────────────────── */
$ncAs(null);
$h = render_page(dirname(__DIR__) . '/q.php', ['c' => $code]);
ck('Page: a stranger sees the first name and "Active member"', str_contains($h, 'Adaeze') && str_contains($h, 'Active member') && !str_contains($h, 'Okafor'));
ck('Page: …and no gate verdict, no money, and a way to sign in if it is theirs', !str_contains($h, 'CACENTRE gate</dt>') && str_contains($h, 'Sign in'));
$ncAs($ada);
$h = render_page(dirname(__DIR__) . '/q.php', ['c' => $code]);
ck('Page: the holder sees their full name, their standing and the gate verdict', str_contains($h, 'Adaeze Okafor Vanguard') && str_contains($h, 'Your standing') && str_contains($h, 'Lets you in') && str_contains($h, '/gate-pass'));
$coord = $ncUser('Cora Coordinator', 'coordinator');
$ncAs($coord);
$h = render_page(dirname(__DIR__) . '/q.php', ['c' => strtolower($code)]);
ck('Page: NGV staff see the whole record, even from a lower-cased code', str_contains($h, 'Adaeze Okafor Vanguard') && str_contains($h, '<dt>Card</dt>') && str_contains($h, 'NGV attendance') && str_contains($h, 'Print card'));
$ncAs(null);
$old = $code; $code = MemberCards::issue($ada, 'test');
$h = render_page(dirname(__DIR__) . '/q.php', ['c' => $old]);
ck('Page: a replaced card says it was replaced, and shows nobody', str_contains($h, 'has been replaced') && !str_contains($h, 'Adaeze'));
$h = render_page(dirname(__DIR__) . '/q.php', ['c' => 'AVQR-0000000000000000']);
ck('Page: an unknown code says only that', str_contains($h, 'Not an Afrovanguard member card') && !str_contains($h, '<article'));
ck('Page: the routes exist in production and in dev', str_contains((string) file_get_contents(dirname(__DIR__) . '/.htaccess'), 'RewriteRule ^q/(AVQR-')
   && str_contains((string) file_get_contents(dirname(__DIR__) . '/router.php'), "require __DIR__ . '/q.php'"));
$ncAs(null);
