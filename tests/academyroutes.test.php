<?php
/**
 * tests/academyroutes.test.php — every academy URL works on Apache too.
 *
 * On Apache, academy/.htaccess handles every /academy/ request and the root
 * .htaccess's academy rules never run (per-directory rewrite rules are not
 * inherited). The PHP dev router reads neither file, so a route added only to
 * the root file and router.php works locally and 404s on the live host. That
 * is how /academy/<course>/discussions went missing in production.
 *
 *   1. Every ^academy/… rule in the root .htaccess has the same rule in
 *      academy/.htaccess (pattern and target, minus the academy/ prefix).
 *   2. A specific rule is never shadowed by a broader one written before it.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$rules = static function (string $file): array {
    $out = [];
    foreach (preg_split('/\R/', (string) file_get_contents($file)) as $line) {
        if (preg_match('/^\s*RewriteRule\s+(\S+)\s+(\S+)/', $line, $m)) $out[] = [$m[1], $m[2]];
    }
    return $out;
};

$root = $rules(AV_ROOT . '/.htaccess');
$sub  = $rules(AV_ROOT . '/academy/.htaccess');
$subPairs = array_map(static fn(array $r): string => $r[0] . ' ' . $r[1], $sub);

$checked = 0;
foreach ($root as [$pattern, $target]) {
    if (strpos($pattern, '^academy/') !== 0 || strpos($target, 'academy/') !== 0) continue;
    $want = '^' . substr($pattern, strlen('^academy/')) . ' ' . substr($target, strlen('academy/'));
    ck('academy/.htaccess has the root rule ' . $pattern, in_array($want, $subPairs, true));
    $checked++;
}
ck('the root .htaccess still has academy rules to compare', $checked >= 10);

/* Order: a rule that would also match a later, more specific one's URLs must
   come after it. The two that matter: teach/grading before teach/<course>,
   and the bare course catch-all last of the course rules. */
$pos = static function (string $pattern) use ($sub): int {
    foreach ($sub as $i => $r) if ($r[0] === $pattern) return $i;
    return -1;
};
ck('teach/grading is matched before teach/<course>',
    $pos('^teach/grading/?$') !== -1 && $pos('^teach/grading/?$') < $pos('^teach/([a-z0-9-]+)/?$'));
$catchAll = $pos('^([a-z0-9-]+)/?$');
ck('the course catch-all comes after the discussions rules',
    $catchAll > $pos('^([a-z0-9-]+)/discussions/?$') && $catchAll > $pos('^([a-z0-9-]+)/discussions/([0-9]+)/?$'));
ck('the course catch-all is the last rule', $catchAll === count($sub) - 1);

/* And a real request through the targets: the files they name exist. */
foreach ($sub as [$pattern, $target]) {
    if ($target === '-') continue;
    $file = explode('?', $target, 2)[0];
    ck('academy rule ' . $pattern . ' points at a file that exists', is_file(AV_ROOT . '/academy/' . $file));
}
