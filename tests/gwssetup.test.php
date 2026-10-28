<?php
/**
 * tests/gwssetup.test.php — "is Google Workspace set up?", and why not.
 *
 * The reported symptom: a site that HAS a service-account key saying it
 * has none. The cause is not Google at all. av_secret() prefers APP_KEY
 * and otherwise generates a key into the database (lib/security.php), so
 * the encryption key changes silently the day somebody sets APP_KEY, or
 * the day an upload drops it from config.php. Everything stored before
 * that stops decrypting, every screen says "not set up", and the only
 * trace is a line in a log nobody on shared hosting reads.
 *
 * These tests pin three things: the detection itself, the recovery where
 * recovery is possible, and — where it is not — that the site says what
 * is wrong rather than telling somebody to add what they already added.
 */
declare(strict_types=1);

/* A real service-account key: generated here so openssl accepts the
   private key, because the validator checks it properly. */
$mkKey = static function (): string {
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $pem);

    return (string) json_encode([
        'type' => 'service_account', 'project_id' => 'av-test', 'private_key_id' => 'k1',
        'private_key' => $pem, 'client_email' => 'ws@av-test.iam.gserviceaccount.com', 'client_id' => '1',
    ], JSON_UNESCAPED_SLASHES);
};

$sa = $mkKey();

/* ── it is detected when it is there ──────────────────────────────────── */

$r = AvSettings::save(['AV_GDRIVE_SERVICE_ACCOUNT' => $sa], 'tester');
AvSettings::flush();

ck('workspace: a pasted service-account key saves and is detected',
   empty($r['errors']) && GoogleWorkspace::configured());
ck('workspace: with it working, there is nothing to explain',
   GoogleWorkspace::whyNotConfigured() === '');
ck('workspace: and nothing is reported unreadable',
   AvSettings::unreadable() === []);

/* ── the key is stored encrypted, not in the clear ────────────────────── */

$stored = (string) Database::pdo()
    ->query("SELECT value FROM av_settings WHERE setting_key='AV_GDRIVE_SERVICE_ACCOUNT'")->fetchColumn();
ck('workspace: the key is not sitting in the table in plain text',
   $stored !== '' && !str_contains($stored, 'BEGIN PRIVATE KEY') && !str_contains($stored, 'client_email'));

/* ── what a bad paste gets told ───────────────────────────────────────── */

$bad = [
    'half a file'           => '{"type":"service_account"',
    'an OAuth client file'  => '{"type":"authorized_user","client_id":"x","client_secret":"y"}',
    'a key with no email'   => '{"type":"service_account","private_key":"x"}',
];
foreach ($bad as $label => $paste) {
    $out = AvSettings::save(['AV_GDRIVE_SERVICE_ACCOUNT' => $paste], 'tester');
    ck('workspace: ' . $label . ' is refused at the paste, with a reason',
       !empty($out['errors']['AV_GDRIVE_SERVICE_ACCOUNT']));
}
AvSettings::flush();
ck('workspace: and a refused paste did not replace the key that worked',
   GoogleWorkspace::configured());

/* ── a key stored under a secret this host no longer uses ─────────────── */

/* Re-encrypt the row under a secret nothing here can produce: the shape
   of a site re-keyed, moved, or restored from another host. */
$alien = 'o1:' . base64_encode(random_bytes(64));
Database::pdo()->prepare("UPDATE av_settings SET value = ? WHERE setting_key = 'AV_GDRIVE_SERVICE_ACCOUNT'")
    ->execute([$alien]);
/* flush AND apply, which is what a save does (AvSettings::save). Flushing
   alone clears the memo but leaves the old value published in the process
   environment — harmless inside one web request, and not harmless in a
   long-lived process like this runner, where it leaked into later files. */
AvSettings::flush();
AvSettings::apply();

ck('workspace: a key that cannot be read is not passed on as ciphertext',
   AvSettings::get('AV_GDRIVE_SERVICE_ACCOUNT') === null);
ck('workspace: it is reported as stored-but-unreadable, not as absent',
   AvSettings::unreadable() === ['AV_GDRIVE_SERVICE_ACCOUNT']);

$why = GoogleWorkspace::whyNotConfigured();
ck('workspace: and the site says a key IS stored rather than asking for one',
   str_contains($why, 'IS stored') && str_contains($why, 'cannot be read back'));
ck('workspace: with the remedy that actually works — paste it again',
   str_contains($why, 'Paste the service account key again'));
ck('workspace: and it does not send somebody to Google, where nothing is wrong',
   str_contains($why, 'Nothing else needs to change at Google'));

/* ── nothing stored at all is a different sentence ────────────────────── */

Database::pdo()->prepare("DELETE FROM av_settings WHERE setting_key = 'AV_GDRIVE_SERVICE_ACCOUNT'")->execute();
AvSettings::flush();
AvSettings::apply();

ck('workspace: an empty site is told to add the key',
   str_contains(GoogleWorkspace::whyNotConfigured(), 'not set up on this site'));
ck('workspace: and is not told a key is stored when none is',
   !str_contains(GoogleWorkspace::whyNotConfigured(), 'IS stored'));
ck('workspace: nothing stored is not "unreadable"',
   AvSettings::unreadable() === []);
ck('workspace: and it says no key was ever saved here, so a Save that never landed shows',
   str_contains(GoogleWorkspace::whyNotConfigured(), 'no service account key has ever been saved here'));
ck('workspace: storedRow() knows there is no row, without reading the value',
   AvSettings::storedRow('AV_GDRIVE_SERVICE_ACCOUNT') === null);

/* ── a save is reported only when it really stored something ─────────── */

$r = AvSettings::save(['AV_GDRIVE_SERVICE_ACCOUNT' => '{ "type": "service_account", … }'], 'tester');
ck('workspace: the masked preview pasted back is refused with a reason, not skipped as if saved',
   ($r['saved'] ?? 0) === 0 && str_contains((string) ($r['errors']['AV_GDRIVE_SERVICE_ACCOUNT'] ?? ''), 'masked preview'));
$r = AvSettings::save(['AV_GDRIVE_SERVICE_ACCOUNT' => $sa], 'tester');
AvSettings::flush();
ck('workspace: a real key is read back before it is called saved',
   ($r['saved'] ?? 0) === 1 && empty($r['errors']) && GoogleWorkspace::configured());
ck('workspace: storedRow() names who saved it',
   (AvSettings::storedRow('AV_GDRIVE_SERVICE_ACCOUNT')['by'] ?? '') === 'tester');
Database::pdo()->prepare("DELETE FROM av_settings WHERE setting_key = 'AV_GDRIVE_SERVICE_ACCOUNT'")->execute();
AvSettings::flush();
AvSettings::apply();

/* ── a key put in config.php ──────────────────────────────────────────── */

$saArr = json_decode($sa, true);
/* The JSON written inside double quotes: PHP turned each \n in the private
   key into a real line break, which JSON forbids. */
$dq = str_replace('\n', "\n", $sa);
ck('workspace: config.php: the JSON in double quotes is still read',
   json_decode($dq, true) === null && (GoogleWorkspace::parseKey($dq)['private_key'] ?? '') === $saArr['private_key']);
ck('workspace: config.php: a PHP array is read as the key', GoogleWorkspace::parseKey($saArr) !== null);
ck('workspace: config.php: half a file is still refused', GoogleWorkspace::parseKey('{"type":"service_account"') === null);

$cfgDir = sys_get_temp_dir() . '/av-gwcfg-' . getmypid();
@mkdir($cfgDir, 0775, true);
$cfgCase = static function (string $src) use ($cfgDir): string {
    file_put_contents("$cfgDir/config.php", $src);
    GoogleWorkspace::$configPath = "$cfgDir/config.php";
    $f = GoogleWorkspace::configFileFinding();
    GoogleWorkspace::$configPath = null;
    return $f;
};
$tpl = "<?php\n/* Drive */\ndefine('AV_GDRIVE_SERVICE_ACCOUNT', getenv('AV_GDRIVE_SERVICE_ACCOUNT') ?: '');\n";
$f = $cfgCase($tpl . "\n\ndefine('AV_GDRIVE_SERVICE_ACCOUNT', '" . $sa . "');\n");
ck('workspace: config.php: a key defined after the template line is named, with both lines',
   str_contains($f, '2 times (lines 3, 6)') && str_contains($f, 'keeps only the FIRST'));
ck('workspace: config.php: and the finding never prints the key',
   !str_contains($f, 'PRIVATE KEY') && !str_contains($f, 'gserviceaccount'));
ck('workspace: config.php: the template line left empty is named',
   str_contains($cfgCase($tpl), "line 3, but its value is empty"));
ck('workspace: config.php: a key under another name is named',
   str_contains($cfgCase("<?php\ndefine('GOOGLE_SERVICE_ACCOUNT', '" . $sa . "');\n"), 'under GOOGLE_SERVICE_ACCOUNT (line 2)'));
ck('workspace: config.php: a key in a variable is named',
   str_contains($cfgCase("<?php\n\$serviceAccount = '" . $sa . "';\n"), 'variable $serviceAccount'));
ck('workspace: config.php: a config without the key says nothing',
   $cfgCase("<?php\ndefine('SITE_URL', 'x');\n") === '');
@unlink("$cfgDir/config.php"); @rmdir($cfgDir);

$js = (string) file_get_contents(dirname(__DIR__) . '/admin/app.js');
ck('workspace: the Save button does not say "Saved." for a request the server refused',
   str_contains($js, "'Not saved — '") && str_contains($js, "'Nothing was saved.'"));

/* ── the fallback key, named while it still works ─────────────────────── */

$crypto = AvSettings::cryptoStatus();
ck('workspace: crypto status says whether the fallback key is in use',
   array_key_exists('weak', $crypto));

/* Leave nothing published behind for the files that run after this one. */
AvSettings::flush();
AvSettings::apply();
