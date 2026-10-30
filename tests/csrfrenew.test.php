<?php
/**
 * tests/csrfrenew.test.php — a page left open keeps saving.
 *
 * The anti-forgery token expires two hours after a page loads, and every page
 * script read it once: a portal tab open longer refused every save with "Bad
 * token." and a 403 (a member's finance request among them). The renewal
 * (assets/site/csrf-renew.js, behaviour checked in a browser) asks
 * portal/csrf.php for a fresh token; this pins the wiring around it.
 *
 * Also: Cloudflare's Web Analytics beacon is allowed by both copies of the
 * security policy, so the browser stops blocking it.
 */
declare(strict_types=1);

$crSrc = static fn(string $f): string => (string) file_get_contents(AV_ROOT . '/' . $f);
ck('csrf renew: loaded on every page, before the page scripts that send tokens',
   str_contains($crSrc('lib/partials.php'), "/assets/site/csrf-renew.js") && strpos($crSrc('lib/partials.php'), 'csrf-renew.js') < strpos($crSrc('lib/partials.php'), "/assets/site/nav.js"));
ck('csrf renew: a fresh token only for somebody signed in', str_contains($crSrc('portal/csrf.php'), "if (!LmsAuth::user() && !av_admin_cookie_valid()) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);"));
ck('csrf renew: what it hands out is a token the write guards accept', av_csrf_valid(av_csrf_token()));
ck('csrf renew: only requests already carrying a token, same-origin, are touched, and a refusal for another reason is passed on',
   str_contains($crSrc('assets/site/csrf-renew.js'), "if (!init || !headerOf(init) || !sameOrigin(url)) return orig(input, init);")
   && str_contains($crSrc('assets/site/csrf-renew.js'), "if (!d || !EXPIRED.test(String(d.error || ''))) return res;"));
ck('csrf renew: the renewal script is not itself markup-building', !str_contains($crSrc('assets/site/csrf-renew.js'), 'innerHTML'));

$crHt = $crSrc('.htaccess'); $crSec = $crSrc('lib/security.php');
ck('csp: Cloudflare’s Web Analytics beacon may load, from both copies of the policy',
   str_contains($crHt, 'script-src \'self\' \'unsafe-inline\' https://static.cloudflareinsights.com') && str_contains($crSec, "'self' 'unsafe-inline' https://static.cloudflareinsights.com"));
ck('csp: …and report back', str_contains($crHt, "connect-src 'self' https://cloudflareinsights.com") && str_contains($crSec, "connect-src 'self' https://cloudflareinsights.com"));
