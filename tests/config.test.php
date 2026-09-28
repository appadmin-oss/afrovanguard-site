<?php
/**
 * tests/config.test.php — does the site actually READ its own config.php?
 *
 * The bug this pins was silent and expensive: bootstrap loaded config.php only
 * when all four of AV_SMTP_PASSWORD, AV_PAYSTACK_PK, AV_PAYSTACK_SK and
 * AV_ADMIN_TOKEN were set. A site with working SMTP credentials but no Paystack
 * key therefore never read the file that held them — SMTP_HOST was never
 * defined, Mailer::configured() was false, and every email on the site fell
 * through to PHP mail(), which shared hosts drop. One payments key switched off
 * the mail, and the System screen said "SMTP not configured", which read as
 * "you never set credentials" rather than "we never read them".
 *
 * These run bootstrap in a SUBPROCESS: the guard runs once per request, at
 * include time, so it cannot be re-exercised inside the already-booted suite.
 * lib/bootstrap.php resolves its siblings with __DIR__ and only AV_ROOT with
 * the site root, so pointing AV_ROOT at a temp directory swaps config.php alone.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

/** Boot the real bootstrap against a temp root holding $configSrc, and report. */
$boot = static function (?string $configSrc, array $env): array {
    $root = sys_get_temp_dir() . '/av-cfg-' . getmypid() . '-' . substr(md5((string) $configSrc . serialize($env)), 0, 8);
    @mkdir($root, 0777, true);
    if ($configSrc !== null) file_put_contents($root . '/config.php', $configSrc);

    $lib = dirname(__DIR__) . '/lib/bootstrap.php';
    $probe = '<?php declare(strict_types=1);'
        . 'define("AV_ROOT", ' . var_export($root, true) . ');'
        . 'require ' . var_export($lib, true) . ';'
        . 'echo "<<AVJSON>>", json_encode(['
        . '"state" => av_config_state(),'
        . '"smtp_host" => defined("SMTP_HOST") ? SMTP_HOST : null,'
        . ']);';
    $probeFile = $root . '/probe.php';
    file_put_contents($probeFile, $probe);

    // A clean environment plus only what the case sets, so the developer's own
    // exported secrets cannot make a failing case look like it passed.
    $assign = '';
    foreach ($env + ['AV_DB_PATH' => $root . '/t.sqlite', 'AV_NGV_DB_PATH' => $root . '/n.sqlite',
                     'APP_KEY' => 'ci_test_key_0123456789abcdefghij', 'AV_MAIL_DISABLED' => '1'] as $k => $v) {
        $assign .= escapeshellarg($k . '=' . $v) . ' ';
    }
    $out = (string) shell_exec('env -i PATH=' . escapeshellarg((string) getenv('PATH')) . ' '
        . $assign . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile) . ' 2>/dev/null');

    @unlink($probeFile);
    if ($configSrc !== null) @unlink($root . '/config.php');
    @unlink($root . '/t.sqlite'); @unlink($root . '/n.sqlite');
    @rmdir($root);

    // The bootstrap prints provisioning notices before the JSON, and the JSON
    // itself contains nested braces — so scanning for a brace finds the wrong
    // one. Split on a sentinel the probe prints immediately before it.
    $i = strpos($out, '<<AVJSON>>');
    $j = $i === false ? false : json_decode(substr($out, $i + 10), true);
    return is_array($j) ? $j : ['state' => [], 'smtp_host' => null, '__raw' => $out];
};

/* The shape shipped in config.example.php: a missing secret disables ONE
   feature and returns '', it does not end the request. */
$modern = <<<'CFG'
<?php
function _av_require_env(string $k): string {
    $v = getenv($k);
    if ($v === false || $v === '') { error_log("missing $k"); return ''; }
    return $v;
}
define('SMTP_HOST', 'smtp.example.org');
define('SMTP_USERNAME', 'post@example.org');
define('SMTP_PASSWORD', _av_require_env('AV_SMTP_PASSWORD'));
define('PAYSTACK_SECRET_KEY', _av_require_env('AV_PAYSTACK_SK'));
CFG;

/* A hand-edited config that really does terminate the request. Loading this one
   with a secret unset would take the whole site down, so it stays guarded. */
$fatal = str_replace('error_log("missing $k"); return \'\';',
                     'error_log("missing $k"); exit(1);', $modern);

// ── The regression itself ───────────────────────────────────────────────────
// SMTP credentials present, payments key absent: the exact production shape.
$smtpOnly = ['AV_SMTP_PASSWORD' => 's3cret', 'AV_ADMIN_TOKEN' => 'tok'];

$r = $boot($modern, $smtpOnly);
ck('config: a missing payments key does not stop config.php being read',
   !empty($r['state']['loaded']));
ck('config: …so the SMTP credentials in it are actually in effect',
   ($r['smtp_host'] ?? null) === 'smtp.example.org');
ck('config: the unset secret is still reported, so the operator can see it',
   in_array('AV_PAYSTACK_SK', $r['state']['missing'] ?? [], true));

// ── The one shape still worth guarding ──────────────────────────────────────
$f = $boot($fatal, $smtpOnly);
ck('config: a config.php that exits on a missing secret is NOT loaded',
   isset($f['state']['loaded']) && $f['state']['loaded'] === false);
ck('config: …and it says why, rather than failing silently',
   strpos((string) ($f['state']['reason'] ?? ''), 'AV_PAYSTACK_SK') !== false);
ck('config: …but with every secret present it loads even so',
   !empty($boot($fatal, $smtpOnly + ['AV_PAYSTACK_PK' => 'pk', 'AV_PAYSTACK_SK' => 'sk'])['state']['loaded']));

// ── A host with no config.php at all (env-only) stays quiet ─────────────────
$n = $boot(null, $smtpOnly);
ck('config: no config.php is not reported as a failure',
   isset($n['state']['present']) && $n['state']['present'] === false
   && ($n['state']['reason'] ?? '') === '');
