<?php
/**
 * tests/signin.test.php — the rebuilt sign-in page (/login, row 10).
 *
 * The page is presentation only; every server check lives in academy/api.php
 * and auth/google.php. What the page itself owns: the ?next= redirect target
 * (same-origin path only, never an open redirect), the error banner map, and
 * handing the policy (methods, code length) to the script.
 */
declare(strict_types=1);

$signin = static function (array $get): string {
    $saved = $_GET;
    $_GET = $get;
    ob_start();
    try { include AV_ROOT . '/login/index.php'; } finally { $html = (string) ob_get_clean(); $_GET = $saved; }
    return $html;
};
$cfgOf = static function (string $html): array {
    if (!preg_match('~data-cfg="([^"]*)"~', $html, $m)) return [];
    return json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true) ?: [];
};

$h = $signin([]);
ck('sign in: renders the design screen', str_contains($h, 'class="avsi"') && str_contains($h, 'Raising one million incorruptible leaders for Africa by 2040.'));
ck('sign in: standalone — no site nav or footer', !str_contains($h, 'avh-nav') && !str_contains($h, 'avh-foot'));
ck('sign in: noindex', str_contains($h, 'noindex, nofollow'));
ck('sign in: default next is the portal', ($cfgOf($h)['next'] ?? '') === '/portal/');
ck('sign in: one code box per policy digit', substr_count($h, 'class="avsi-box"') === (AuthPolicy::allows('otp') ? AuthPolicy::otpLength() : 0));
ck('sign in: methods come from AuthPolicy', ($cfgOf($h)['methods'] ?? null) === AuthPolicy::publicMethods());

foreach (['//evil.example/x', '/\\evil.example/x', '/\\/evil.example', 'https://evil.example/', 'evil.example', "/ok\nLocation: x", "/ok\rx", "/ok\tx", ''] as $bad) {
    $c = $cfgOf($signin(['next' => $bad]));
    ck('sign in: refuses next=' . json_encode($bad), ($c['next'] ?? '') === '/portal/');
}
$c = $cfgOf($signin(['next' => '/academy/ngv/?x=1']));
ck('sign in: keeps a same-origin next', ($c['next'] ?? '') === '/academy/ngv/?x=1');
$h = $signin(['next' => '/academy/']);
ck('sign in: Google start carries next', str_contains($h, e('/auth/google/start?next=' . rawurlencode('/academy/'))) || !AuthPolicy::allows('google'));
ck('sign in: skip link goes to next', str_contains($h, 'href="/academy/" data-avsi-skip'));

ck('sign in: error banner for ?e=google_failed', str_contains($signin(['e' => 'google_failed']), 'We couldn’t complete Google sign-in.'));
ck('sign in: unknown ?e= shows nothing', !str_contains($signin(['e' => '<script>']), 'data-avsi-banner'));
ck('sign in: verify_error banner', str_contains($signin(['verify_error' => '1']), 'That verification link is invalid or has expired.'));

$js = (string) @file_get_contents(AV_ROOT . '/assets/site/avsi.js');
foreach (['otp-request', 'otp-verify', 'login', 'resend-verification', 'set-password'] as $a) {
    ck("sign in: script calls the {$a} action", str_contains($js, "'{$a}'"));
}
ck('sign in: script posts to the academy API', str_contains($js, "'/academy/api.php'"));
ck('sign in: script follows the server Google steer', str_contains($js, 'd.google'));
