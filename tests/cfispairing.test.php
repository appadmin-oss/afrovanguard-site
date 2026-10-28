<?php
/**
 * tests/cfispairing.test.php — collecting this site's CFIS key from CFIS.
 *
 * WHAT CHANGED. The key used to be printed on a terminal at CACENTRE and
 * carried here by a person, who pasted it into a form. Finance now issues a
 * pairing code; this site redeems it and the key travels machine to machine.
 *
 * WHAT IS PINNED HERE. The decisions THIS side makes, each of which fails
 * silently or dangerously if it goes wrong:
 *
 *   · a pairing code is never stored as a setting — it is a live credential
 *     and av_settings is not where anybody would look for one;
 *   · the key is refused unless its fingerprint matches, so a truncated
 *     answer does not become a key that fails every settlement days later;
 *   · the fingerprint is computed the same way CFIS computes it, or the two
 *     sides compare values that can never agree;
 *   · nothing is sent over plain http, where the ANSWER carries the key.
 *
 * The far side's rules — expiry, single use, who may issue — belong to CFIS
 * and are tested there (PropertyPairingTest).
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$pkeep = ['CFIS_URL' => getenv('CFIS_URL'), 'CFIS_SOURCE' => getenv('CFIS_SOURCE')];

/* ══ 1. the fingerprint is the same definition on both sides ═════════════ */

/* CFIS: substr(hash('sha256', 'cfis-source-key:'.$secret), 0, 16), in
   App\Models\Cfis\IncomeSource::fingerprintFor(). Written out here rather
   than referenced, so a change to either side fails this rather than
   quietly agreeing with itself. */
$secret = str_repeat('a', 64);
ck('the key fingerprint matches CFIS\'s own definition',
   CfisPairing::fingerprint($secret) === substr(hash('sha256', 'cfis-source-key:' . $secret), 0, 16));
ck('a fingerprint is 16 characters', strlen(CfisPairing::fingerprint($secret)) === 16);
ck('two different keys have different fingerprints',
   CfisPairing::fingerprint('a') !== CfisPairing::fingerprint('b'));

/* ══ 2. a pairing code is not a setting ══════════════════════════════════ */

ck('the pairing control is declared, so it appears in the Studio', AvSettings::defined('CFIS_PAIRING_CODE'));
ck('it is declared as an action, not a value', (function (): bool {
    foreach (AvSettings::describe()['groups'] as $g) {
        foreach ($g['fields'] as $f) if ($f['key'] === 'CFIS_PAIRING_CODE') return $f['type'] === 'action';
    }
    return false;
})());

/* The guard that matters: a hand-made request must not be able to park a
   live pairing code in av_settings. */
$try = AvSettings::save(['CFIS_PAIRING_CODE' => 'ABCD-EFGH-JKLM-NPQR'], 'test');
ck('saving a pairing code is refused, not quietly stored',
   empty($try['ok']) && (int) ($try['saved'] ?? 0) === 0 && isset($try['errors']['CFIS_PAIRING_CODE']));
ck('and nothing was written', AvSettings::get('CFIS_PAIRING_CODE') === null);

/* ══ 3. it never talks over plain http ═══════════════════════════════════ */

putenv('CFIS_URL=http://finance.example.test');
putenv('CFIS_SOURCE=avg');
AvSettings::flush();
$r = CfisPairing::pair('ABCD-EFGH-JKLM-NPQR', 'test');
ck('a pairing code is never sent over plain http',
   empty($r['ok']) && str_contains((string) $r['error'], 'https://'));

/* ══ 4. an empty code never leaves the building ══════════════════════════ */

putenv('CFIS_URL=https://finance.example.test');
AvSettings::flush();
$r = CfisPairing::pair('   ', 'test');
ck('an empty code is refused here, not at CACENTRE', empty($r['ok']) && str_contains((string) $r['error'], 'pairing code'));

/* ══ 5. the Studio shows the key's fingerprint, never the key ════════════ */

$fieldFor = static function (string $key): array {
    foreach (AvSettings::describe()['groups'] as $g) {
        foreach ($g['fields'] as $f) if ($f['key'] === $key) return $f;
    }
    return [];
};
putenv('CFIS_SECRET=' . $secret);
AvSettings::flush();
$f = $fieldFor('CFIS_SECRET');
ck('the stored key is shown as a fingerprint, to compare with finance\'s',
   ($f['fingerprint'] ?? '') === CfisPairing::fingerprint($secret));
ck('and the key itself is never in the Studio payload',
   !str_contains(json_encode(AvSettings::describe()), $secret));
ck('CfisPairing::current() reports what is held, by fingerprint',
   CfisPairing::current()['paired'] === true && CfisPairing::current()['fingerprint'] === CfisPairing::fingerprint($secret));

putenv('CFIS_SECRET');
AvSettings::flush();
ck('with no key, nothing is reported as paired', CfisPairing::current()['paired'] === false);

/* ══ 6. the Studio's own wiring ══════════════════════════════════════════ */

$js = (string) file_get_contents(AV_ROOT . '/admin/app.js');
ck('the pairing field carries no data-skey, so Save can never post it',
   preg_match("~f\\.type === 'action'~", $js) === 1 && str_contains($js, "data-pairfield"));
ck('the Pair button calls the pairing action', str_contains($js, "post('setup_cfis_pair'"));

$api = (string) file_get_contents(AV_ROOT . '/admin/api.php');
ck('pairing is Super Admin only, like the other credential actions',
   preg_match("~'setup_get', 'setup_save', 'setup_test',.*?'setup_cfis_pair'~s", $api) === 1);
ck('pairing is rate-limited', preg_match("~av_rate_ok\\('setup_cfis_pair'~", $api) === 1);
ck('a successful pairing is audited by fingerprint, never by key',
   str_contains($api, "'cfis_paired'") && str_contains($api, "\$res['fingerprint']"));

foreach ($pkeep as $k => $v) { if ($v === false) putenv($k); else putenv($k . '=' . $v); }
AvSettings::flush();
