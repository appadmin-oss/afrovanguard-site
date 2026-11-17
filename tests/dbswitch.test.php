<?php
/**
 * tests/dbswitch.test.php — switching the site onto the database it was
 * copied to, from the Studio (owner, 2026-11-13: "the migration only copies
 * the database content, there's no switch").
 *
 * Verified end to end on MariaDB 10.11 in a browser: copy → a row added to the
 * site meanwhile → Switch (carried across, every table checked) → the site
 * writes to MariaDB and not the old file → Switch back.
 */
declare(strict_types=1);
$r = AV_ROOT;
$dir = sys_get_temp_dir() . '/avsw-' . bin2hex(random_bytes(4));
@mkdir($dir, 0700, true);
$file = $dir . '/.av-database.php';
putenv('AV_DB_SWITCH_FILE=' . $file);

ck('switch: nothing saved, nothing applied', DbSwitch::read() === null && DbSwitch::status()['saved'] === false);
$path = DbSwitch::write(['driver' => 'mysql', 'host' => 'db.example', 'port' => '3306', 'name' => 'av_live', 'user' => 'avu', 'pass' => "p'a\"ss\$1"], 'Ada (test)');
$c = DbSwitch::read();
ck('switch: the choice is saved where asked, and reads back exactly (quotes and $ in the password too)', $path === $file && $c && $c['name'] === 'av_live' && $c['pass'] === "p'a\"ss\$1" && $c['switched_by'] === 'Ada (test)');
ck('switch: the file is readable by the site only (0600)', (fileperms($file) & 0777) === 0600);
ob_start(); include $file; $printed = ob_get_clean();
ck('switch: the file prints nothing if a server ever ran it from the web', $printed === '');
ck('switch: the Studio names it plainly, never with the password', DbSwitch::label($c) === 'MySQL “av_live” on db.example' && !str_contains(json_encode(DbSwitch::status()), "p'a"));

/* apply(): fills the environment only when the host has chosen nothing. */
$keys = ['AV_DB_DRIVER', 'AV_DB_DSN', 'AV_DB_HOST', 'AV_DB_PORT', 'AV_DB_NAME', 'AV_DB_USER', 'AV_DB_PASS', 'AV_DB_STRICT'];
$saved = []; foreach ($keys as $k) { $saved[$k] = getenv($k); putenv($k); }
DbSwitch::apply();
ck('switch: applied, the site is pointed at it', getenv('AV_DB_DRIVER') === 'mysql' && getenv('AV_DB_NAME') === 'av_live' && getenv('AV_DB_HOST') === 'db.example' && DbSwitch::status()['active'] === true);
ck('switch: and strict, so it never silently falls back to the old SQLite file', getenv('AV_DB_STRICT') === '1');
foreach ($keys as $k) putenv($k);
putenv('AV_DB_NAME=host_chosen');
DbSwitch::apply();
ck('switch: settings the host makes (.env, SetEnv) win, and the Studio is told', getenv('AV_DB_NAME') === 'host_chosen' && getenv('AV_DB_DRIVER') === false && DbSwitch::status()['host_chose'] === true);
putenv('AV_DB_NAME');

ck('switch: back — the file goes', DbSwitch::clear() === 'MySQL “av_live” on db.example' && !is_file($file) && DbSwitch::read() === null);
file_put_contents($file, "<?php return ['driver' => 'oracle', 'name' => 'x'];");
ck('switch: a file that does not name MySQL or PostgreSQL is ignored', DbSwitch::read() === null);
@unlink($file); @rmdir($dir);
foreach ($saved as $k => $v) { if ($v === false) putenv($k); else putenv($k . '=' . $v); }
putenv('AV_DB_SWITCH_FILE');
DbSwitch::apply();

/* The route: checks before switching, and refuses rather than guess. */
$api = (string) file_get_contents("$r/admin/api.php");
ck('switch route: superadmin only, and a write (CSRF)', substr_count($api, "'db_switch', 'db_switch_back'") === 2);
ck('switch route: refuses when the host already chooses, on a fallback, or when it cannot save', str_contains($api, "if (\$sw['host_chose'])") && str_contains($api, "if (Database::fellBack() !== null) json_out(['ok' => false, 'error' => 'The site is on its fallback copy") && str_contains($api, "if (!\$sw['can_write'])"));
ck('switch route: carries new rows across, then every table must match, or nothing is switched', str_contains($api, 'DbMigrateJob::reopenForNewRows($label)') && str_contains($api, 'for ($round = 0; $round < 3; $round++)') && strpos($api, "if (empty(\$v['ok'])) {") < strpos($api, 'DbSwitch::write($conf'));
ck('switch route: logged in both databases', str_contains($api, "\$dst->prepare('INSERT INTO lms_audit") && str_contains($api, "new PDO('sqlite:' . AV_DB_PATH"));
ck('bootstrap: the saved choice is applied on every request', str_contains((string) file_get_contents("$r/lib/bootstrap.php"), 'DbSwitch::apply();'));

/* The screen. */
$js = (string) file_get_contents("$r/admin/app.js"); $html = (string) file_get_contents("$r/admin/index.php");
ck('screen: a Switch the site panel and a Switch back', str_contains($html, 'id="dbSwitchBtn"') && str_contains($js, "post('db_switch', pp)") && str_contains($js, "post('db_switch_back', {})"));
ck('screen: rows added during the copy are "behind", not a failure', str_contains($js, "return r.target === null || (r.target | 0) > (r.source | 0);"));
ck('screen: the old "repoint it by hand" wording is gone', !str_contains($html, 'repoint it by hand') && !str_contains($js, 'It does NOT switch this site over'));

/* A target that really does not match is never switched to. */
$tmp = sys_get_temp_dir() . '/avsw-src-' . bin2hex(random_bytes(3)) . '.sqlite';
$src = new PDO('sqlite:' . $tmp); $src->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)'); $src->exec("INSERT INTO t (v) VALUES ('a'),('b')");
$dstf = $tmp . '-dst'; $dst = new PDO('sqlite:' . $dstf); $dst->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)'); $dst->exec("INSERT INTO t (v) VALUES ('a'),('b'),('stray')");
$v = DbMigrateJob::verify($src, $dst);
ck('verify: a target holding MORE rows than the site does not match', empty($v['ok']));
@unlink($tmp); @unlink($dstf);
