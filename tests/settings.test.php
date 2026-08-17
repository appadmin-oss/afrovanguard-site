<?php
/**
 * tests/settings.test.php — setting the AI up from the Studio.
 *
 * This screen holds the organisation's API keys, so the tests are mostly about
 * what must NOT happen:
 *
 *   1. A stored key is never returned to the browser. describe() feeds the
 *      Studio, and a secret must not appear in it anywhere — only a masked tail.
 *   2. A key is never written to the database in plain text, and if it cannot be
 *      encrypted the save is REFUSED rather than downgraded.
 *   3. Submitting the masked placeholder does not wipe the stored key — otherwise
 *      editing the model name beside it would silently destroy the credential.
 *   4. A Studio value actually reaches every consumer, including the ones that
 *      read a bare getenv(), and clearing it falls back to the environment.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/AvSettings.php';
require_once AV_ROOT . '/lib/AttendeeBot.php';

/**
 * Clear the store AND the process environment.
 *
 * apply() publishes settings with putenv(), which outlives a row delete — so a
 * key saved here would still be visible to test files that run later and expect
 * an unconfigured provider. Unsetting the registry keys keeps this file from
 * leaking into the rest of the suite.
 */
$setreset = static function (): void {
    AvSettings::ensure();
    try { Database::pdo()->exec('DELETE FROM av_settings'); } catch (Throwable $e) {}
    AvSettings::flush();
    foreach (AvSettings::keys() as $k) { putenv($k); unset($_ENV[$k], $_SERVER[$k]); }
};

$setreset();
$SECRET = 'AIza-test-secret-value-abcdef123456';

/* ---- The registry ---- */
ck('settings: the registry is populated', count(AvSettings::keys()) >= 15);
ck('settings: an API key is marked secret', AvSettings::isSecret('ANTHROPIC_API_KEY'));
ck('settings: a model name is not secret', !AvSettings::isSecret('AV_AI_MODEL'));
ck('settings: unknown keys are not defined', !AvSettings::defined('SOMETHING_ELSE'));
// The suite sets APP_KEY, so encryption must be available.
ck('settings: crypto is available under APP_KEY', !empty(AvSettings::cryptoStatus()['ok']));

/* ---- Round trip ---- */
$setreset();
$r = AvSettings::save(['AV_GEMINI_API_KEY' => $SECRET], 'test-admin');
ck('settings: a secret saves', !empty($r['ok']) && $r['saved'] === 1);
ck('settings: it reads back in full server-side', AvSettings::get('AV_GEMINI_API_KEY') === $SECRET);
ck('settings: Config sees it', Config::str('AV_GEMINI_API_KEY') === $SECRET);
// apply() publishes into the environment, which is how bare-getenv consumers work.
ck('settings: it reaches getenv()', getenv('AV_GEMINI_API_KEY') === $SECRET);
ck('settings: the provider class sees it', Gemini::configured());

/* ---- Never in plain text ---- */
$row = Database::pdo()->query("SELECT value, is_secret FROM av_settings WHERE setting_key='AV_GEMINI_API_KEY'")->fetch(PDO::FETCH_ASSOC);
ck('settings: stored as a secret', (int) $row['is_secret'] === 1);
ck('settings: the stored value is not the key', strpos((string) $row['value'], $SECRET) === false);
ck('settings: the stored value is a known ciphertext form',
    strncmp((string) $row['value'], 's1:', 3) === 0 || strncmp((string) $row['value'], 'o1:', 3) === 0);

/* ---- Never sent to the browser ---- */
$d = AvSettings::describe();
$json = (string) json_encode($d);
ck('settings: describe() does not contain the secret', strpos($json, $SECRET) === false);
$field = null;
foreach ($d['groups'] as $g) foreach ($g['fields'] as $f) if ($f['key'] === 'AV_GEMINI_API_KEY') $field = $f;
ck('settings: the secret field is reported as set', $field && $field['is_set'] === true);
ck('settings: its value is blank in the payload', $field && $field['value'] === '');
ck('settings: a masked preview is offered instead', $field && $field['preview'] !== '' && $field['preview'] !== $SECRET);
ck('settings: the preview keeps only the tail', $field && substr($field['preview'], -4) === substr($SECRET, -4));
ck('settings: the source reads as studio', $field && $field['source'] === 'studio');

// A non-secret DOES come back, because there is nothing to protect and an
// administrator needs to see what a model name currently is.
AvSettings::save(['AV_AI_MODEL' => 'claude-haiku-4-5'], 'test-admin');
$model = null;
foreach (AvSettings::describe()['groups'] as $g) foreach ($g['fields'] as $f) if ($f['key'] === 'AV_AI_MODEL') $model = $f;
ck('settings: a non-secret value is returned', $model && $model['value'] === 'claude-haiku-4-5');
ck('settings: a non-secret has no mask', $model && $model['preview'] === '');

/* ---- The masked placeholder must not destroy the key ---- */
$before = AvSettings::get('AV_GEMINI_API_KEY');
AvSettings::save(['AV_GEMINI_API_KEY' => AvSettings::MASK], 'test-admin');
ck('settings: submitting the mask leaves the key alone', AvSettings::get('AV_GEMINI_API_KEY') === $before);
AvSettings::save(['AV_GEMINI_API_KEY' => 'AIza-t…3456'], 'test-admin');
ck('settings: submitting a preview leaves the key alone', AvSettings::get('AV_GEMINI_API_KEY') === $before);

/* ---- Clearing ---- */
ck('settings: clear removes the override', AvSettings::clear('AV_GEMINI_API_KEY', 'test-admin') && AvSettings::get('AV_GEMINI_API_KEY') === null);
ck('settings: an empty value also clears', (function () {
    AvSettings::save(['AV_AI_MODEL' => 'x-model'], 't');
    AvSettings::save(['AV_AI_MODEL' => ''], 't');
    return AvSettings::get('AV_AI_MODEL') === null;
})());
ck('settings: undo dispatch clears', (function () {
    AvSettings::save(['AV_AI_MODEL' => 'y-model'], 't');
    return AvSettings::applyUndo('setting_clear', ['key' => 'AV_AI_MODEL']) && AvSettings::get('AV_AI_MODEL') === null;
})());
ck('settings: undo rejects an unknown op', !AvSettings::applyUndo('nope', ['key' => 'AV_AI_MODEL']));

/* ---- Validation happens before storage ---- */
$setreset();
$bad = AvSettings::save(['AV_BRAVE_API_KEY' => 'key with spaces'], 't');
ck('settings: a key with spaces is refused', empty($bad['ok']) && isset($bad['errors']['AV_BRAVE_API_KEY']));
ck('settings: the refusal explains itself', strpos((string) $bad['errors']['AV_BRAVE_API_KEY'], 'spaces') !== false);
ck('settings: nothing was stored', AvSettings::get('AV_BRAVE_API_KEY') === null);

$quoted = AvSettings::save(['AV_BRAVE_API_KEY' => '"sk-quoted"'], 't');
ck('settings: a quoted key is refused', empty($quoted['ok']));

$enum = AvSettings::save(['AV_MEET_BOT_PROVIDER' => 'nonsense'], 't');
ck('settings: an unknown enum value is refused', empty($enum['ok']));
ck('settings: the refusal lists the options', strpos((string) $enum['errors']['AV_MEET_BOT_PROVIDER'], 'recall') !== false);
ck('settings: a valid enum value is accepted', !empty(AvSettings::save(['AV_MEET_BOT_PROVIDER' => 'google'], 't')['ok']));

$url = AvSettings::save(['AV_MEET_BOT_JOIN_URL' => 'not-a-url'], 't');
ck('settings: a malformed URL is refused', empty($url['ok']));
ck('settings: an https URL is accepted', !empty(AvSettings::save(['AV_MEET_BOT_JOIN_URL' => 'https://recorder.example/join'], 't')['ok']));

$unknown = AvSettings::save(['NOT_A_SETTING' => 'x'], 't');
ck('settings: an unknown key is refused', empty($unknown['ok']) && isset($unknown['errors']['NOT_A_SETTING']));

// A batch reports per-key errors and still saves the good ones — losing valid
// input because one field was wrong is how people give up on a settings screen.
$setreset();
$mixed = AvSettings::save(['AV_AI_MODEL' => 'good-model', 'AV_MEET_BOT_PROVIDER' => 'rubbish'], 't');
ck('settings: a mixed batch reports the bad key', isset($mixed['errors']['AV_MEET_BOT_PROVIDER']));
ck('settings: a mixed batch still saves the good key', AvSettings::get('AV_AI_MODEL') === 'good-model');

/* ---- A Studio value reaches a bare getenv() consumer ---- */
$setreset();
AvSettings::save(['AV_MEET_BOT_PROVIDER' => 'google'], 't');
// Meetings::botProvider() reads getenv() directly and was never modified.
ck('settings: an unmodified getenv() consumer sees it', Meetings::botProvider() === 'google');
AvSettings::clear('AV_MEET_BOT_PROVIDER', 't');
putenv('AV_MEET_BOT_PROVIDER');

/* ---- Clearing a key must withdraw it from the process too ----
   apply() publishes with putenv(). If it only ever ADDS, clearing a key in the
   Studio leaves the old value live for the rest of the request — so the Setup
   screen says "unset" while the provider it belongs to still reports itself
   configured. That is the confusing kind of wrong. ---- */
$setreset();
AvSettings::save(['AV_ATTENDEE_API_KEY' => 'att-key-not-real'], 't');
ck('settings: a saved key reaches the provider class', AttendeeBot::configured());
AvSettings::save(['AV_ATTENDEE_API_KEY' => ''], 't');
ck('settings: clearing withdraws it from getenv()', getenv('AV_ATTENDEE_API_KEY') === false || getenv('AV_ATTENDEE_API_KEY') === '');
ck('settings: the provider class sees it gone', !AttendeeBot::configured());

// Clearing must hand the key BACK to the environment, not delete a value we
// never owned.
$_SERVER['AV_ATTENDEE_API_KEY'] = 'from-the-server-env';
putenv('AV_ATTENDEE_API_KEY=from-the-server-env');
AvSettings::save(['AV_ATTENDEE_API_KEY' => 'studio-wins'], 't');
ck('settings: the Studio value takes over', getenv('AV_ATTENDEE_API_KEY') === 'studio-wins');
AvSettings::save(['AV_ATTENDEE_API_KEY' => ''], 't');
ck('settings: clearing restores the environment value', getenv('AV_ATTENDEE_API_KEY') === 'from-the-server-env');
unset($_SERVER['AV_ATTENDEE_API_KEY']);
putenv('AV_ATTENDEE_API_KEY');
$setreset();

/* ---- Source reporting ---- */
$setreset();
ck('settings: an unset key reports unset', AvSettings::sourceOf('AV_TAVILY_API_KEY')['source'] === 'unset');
AvSettings::save(['AV_TAVILY_API_KEY' => 'tvly-abc123'], 't');
ck('settings: a stored key reports studio', AvSettings::sourceOf('AV_TAVILY_API_KEY')['source'] === 'studio');

// A real deployment sets variables into $_SERVER (SetEnv, or the .env loader).
$_SERVER['AV_SERPER_API_KEY'] = 'from-the-server-env';
ck('settings: an environment value is reported as env', AvSettings::sourceOf('AV_SERPER_API_KEY')['source'] === 'env');
AvSettings::save(['AV_SERPER_API_KEY' => 'set-in-the-studio'], 't');
$src = AvSettings::sourceOf('AV_SERPER_API_KEY');
ck('settings: the Studio wins over the environment', $src['source'] === 'studio');
// Silent overrides are the confusing kind, so this must be surfaced.
ck('settings: shadowing is reported', $src['shadowing'] === 'env');
ck('settings: the Studio value is the one in force', Config::str('AV_SERPER_API_KEY') === 'set-in-the-studio');
AvSettings::clear('AV_SERPER_API_KEY', 't');
unset($_SERVER['AV_SERPER_API_KEY']);
putenv('AV_SERPER_API_KEY');

/* ---- Only registry keys are ever published to the environment ---- */
$setreset();
try {
    Database::pdo()->prepare('INSERT INTO av_settings (setting_key, value, is_secret, updated_by, updated_at) VALUES (?,?,0,?,?)')
        ->execute(['EVIL_INJECTED_VAR', 'pwned', 'attacker', gmdate('c')]);
} catch (Throwable $e) {}
AvSettings::flush();
AvSettings::apply();
ck('settings: a row outside the registry is never published', getenv('EVIL_INJECTED_VAR') === false);
ck('settings: get() refuses a key outside the registry', AvSettings::get('EVIL_INJECTED_VAR') === null);

/* ---- Connection tests ---- */
$setreset();
$t = AvSettings::testable();
$testKeys = array_column($t, 'key');
ck('settings: every provider is testable', $testKeys === ['anthropic', 'gemini', 'openai', 'attendee', 'recall', 'search']);
$notReady = true;
foreach ($t as $x) if ($x['key'] === 'anthropic' && $x['ready']) $notReady = false;
ck('settings: an unconfigured provider is not offered as ready', $notReady);
$res = AvSettings::test('anthropic');
ck('settings: testing an unconfigured provider fails cleanly', empty($res['ok']) && $res['detail'] !== '');
ck('settings: the test is timed', ($res['ms'] ?? -1) >= 0);
ck('settings: an unknown test is refused', empty(AvSettings::test('nope')['ok']));

$setreset();
