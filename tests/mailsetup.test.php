<?php
/**
 * tests/mailsetup.test.php — "is email set up?", and why not.
 *
 * Same cause as tests/gwssetup.test.php and a different screen. The
 * Apps Script secret is an encrypted setting, so the day APP_KEY changes
 * it stops decrypting; AppsScriptMail::configured() is a boolean and
 * cannot tell that from "nobody ever entered one". System Health was
 * printing "URL set but no secret", which sends an operator to paste a
 * secret that is sitting in the database — the one instruction no
 * control on the page can satisfy.
 *
 * These pin the distinction at each surface that makes the claim: the
 * road itself, the mail diagnostic, and System Health.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$url = 'https://script.google.com/macros/s/AKfycbxTESTDEPLOY/exec';

$detail = static function (string $label): string {
    $d = Config::diagnostics();
    foreach (($d['groups'] ?? $d) as $g) {
        foreach (($g['checks'] ?? []) as $c) {
            if (($c['label'] ?? '') === $label) return (string) ($c['detail'] ?? '');
        }
    }
    return '';
};

/* ── nothing stored ───────────────────────────────────────────────────── */

Database::pdo()->prepare("DELETE FROM av_settings WHERE setting_key IN ('AV_GAS_URL','AV_GAS_SECRET')")->execute();
AvSettings::flush(); AvSettings::apply();

ck('mail: an empty site is told Apps Script is not set up',
   str_contains(AppsScriptMail::whyNotConfigured(), 'not set up'));
ck('mail: and is not told a secret is stored when none is',
   !str_contains(AppsScriptMail::whyNotConfigured(), 'IS stored'));

/* ── the URL alone ────────────────────────────────────────────────────── */

AvSettings::save(['AV_GAS_URL' => $url], 'test');
ck('mail: a URL with no secret says so, and names where the secret goes',
   str_contains(AppsScriptMail::whyNotConfigured(), 'secret is not')
   && str_contains(AppsScriptMail::whyNotConfigured(), 'Setup → Email'));

/* ── both halves ──────────────────────────────────────────────────────── */

AvSettings::save(['AV_GAS_SECRET' => 'the-shared-secret'], 'test');
ck('mail: both halves stored is configured', AppsScriptMail::configured());
ck('mail: and configured has no complaint to make', AppsScriptMail::whyNotConfigured() === '');
ck('mail: health reports it set up', str_contains($detail('Google Apps Script'), 'set up · ' . $url));

/* ── stored, and unreadable: the reported symptom ─────────────────────── */

/* Re-encrypt nothing; just make what is stored undecryptable, the way a
   changed APP_KEY does. The row stays exactly where it was. */
Database::pdo()->prepare("UPDATE av_settings SET value = :v, is_secret = 1 WHERE setting_key = 'AV_GAS_SECRET'")
    ->execute([':v' => base64_encode(random_bytes(48))]);
AvSettings::flush(); AvSettings::apply();

ck('mail: an unreadable secret is reported unreadable, not absent',
   in_array('AV_GAS_SECRET', AvSettings::unreadable(), true));
ck('mail: the road says the secret IS stored',
   str_contains(AppsScriptMail::whyNotConfigured(), 'IS stored on this site'));
ck('mail: and never claims nobody entered one',
   !str_contains(AppsScriptMail::whyNotConfigured(), 'not set up'));

/* The regression this file exists for. */
$gas = $detail('Google Apps Script');
ck('mail: health no longer says "URL set but no secret" for a stored secret',
   !str_contains($gas, 'URL set but no secret'));
ck('mail: health says it is stored and cannot be read back',
   str_contains($gas, 'IS stored on this site'));
ck('mail: health names the one action that fixes it',
   str_contains($gas, 'Paste the Apps Script secret again'));

/* Runtime names the unreadable key in one place, so the operator does not
   have to read every integration's line to find out which one broke. */
$store = $detail('Stored secrets');
ck('mail: System Health reports the settings store at all', $store !== '');
ck('mail: and names the key that cannot be read', str_contains($store, 'AV_GAS_SECRET'));

/* Mailer::unavailable() — the text in the failure log — delegates to the
   same whyNotConfigured() pinned above; it is private, and is not worth a
   public accessor it would otherwise have no caller for. */

/* ── tidy up: leave nothing stored or published for later files ───────── */

Database::pdo()->prepare("DELETE FROM av_settings WHERE setting_key IN ('AV_GAS_URL','AV_GAS_SECRET')")->execute();
AvSettings::flush(); AvSettings::apply();
