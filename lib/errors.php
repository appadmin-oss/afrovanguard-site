<?php
/**
 * lib/errors.php — one standardized, branded, illustrated error page.
 *
 * Self-contained (inline CSS, no DB) so it renders even when the app is
 * broken. Illustrations live at /assets/illustrations/error-<code>.* and
 * are mapped by meaning; a tasteful inline fallback shows until they land.
 *
 *   av_error_render(404);   // echoes a full page
 */
declare(strict_types=1);

function av_error_meta(int $code): array {
    $map = [
        400 => ['Bad request', "That request didn't make sense to us.", 'lost'],
        401 => ['Sign in required', 'You need to be signed in to view this page.', 'stop'],
        403 => ['Access restricted', "You don't have permission to view this page.", 'stop'],
        404 => ['Page not found', "We looked everywhere, but this page isn't here.", 'lost'],
        405 => ['Not allowed', "That action isn't allowed here.", 'stop'],
        413 => ['Too large', 'That upload is larger than we can accept.', 'lost'],
        429 => ['Easy does it', 'Too many requests — give it a moment and try again.', 'stop'],
        500 => ['Something broke', "We're looking into it. Please try again shortly.", 'examine'],
        503 => ['Back shortly', "We're doing a little maintenance. Please check back soon.", 'examine'],
    ];
    return $map[$code] ?? $map[500];
}

// illustration filename + accent per "mood"
function av_error_illo(string $mood): array {
    $m = [
        'lost'    => ['error-404', '#7c7ce0'],   // purple — shielding eyes, searching
        'stop'    => ['error-403', '#f3b416'],   // gold/yellow — hand up, stop
        'examine' => ['error-500', '#ef8a4b'],   // orange — crouching, inspecting
    ];
    return $m[$mood] ?? $m['examine'];
}

function av_error_render(int $code): void {
    if (!headers_sent()) {
        http_response_code($code);
        if (function_exists('send_security_headers')) send_security_headers('public');
        header('Content-Type: text/html; charset=utf-8');
    }
    [$title, $msg, $mood] = av_error_meta($code);
    [$illoBase, $accent] = av_error_illo($mood);
    $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://afrovanguard.org.ng';
    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $illoDir = '/assets/illustrations/';
    // A short poem "for the moment" — Claude-written when configured, curated
    // otherwise. Never touches the network here (pick() reads a cache); fresh
    // verses are generated in the background after the response is sent.
    $poemLines = [];
    try { if (class_exists('ErrorPoem')) { $p = ErrorPoem::pick($code, $mood); $poemLines = $p['lines'] ?? []; } }
    catch (\Throwable $e) { $poemLines = []; }
    ?><!DOCTYPE html>
<html lang="en-NG" data-mood="<?= $esc($mood) ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
<meta name="robots" content="noindex, follow" />
<title><?= $code ?> · <?= $esc($title) ?> — Afrovanguard</title>
<script>(function(){try{var t=localStorage.getItem('av.theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link href="https://fonts.googleapis.com/css2?family=Cormorant:wght@600;700&family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet" />
<style>
:root{--gold:#f3b416;--ink:#111827;--bg:#FDFCF8;--surface:#fff;--muted:#6B7280;--divider:#E5E7EB;--accent:<?= $accent ?>;}
@media (prefers-color-scheme:dark){:root{--ink:#F3F4F6;--bg:#0B1120;--surface:#131C2E;--muted:#8A93A3;--divider:#283349;}}
[data-theme="dark"]{--ink:#F3F4F6;--bg:#0B1120;--surface:#131C2E;--muted:#8A93A3;--divider:#283349;}
[data-theme="light"]{--ink:#111827;--bg:#FDFCF8;--surface:#fff;--muted:#6B7280;--divider:#E5E7EB;}
*{box-sizing:border-box}
body{margin:0;font-family:Montserrat,system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--ink);
 min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
.err-top{padding:22px clamp(18px,5vw,48px)}
.brand{font-weight:800;font-size:18px;letter-spacing:-.02em}.brand .v{color:var(--gold)}
.err-wrap{flex:1;display:flex;align-items:center;justify-content:center;padding:24px clamp(18px,5vw,48px) 48px}
.err-card{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:clamp(24px,5vw,72px);align-items:center;max-width:1080px;width:100%}
.err-illo{position:relative;border-radius:24px;overflow:hidden;background:var(--accent);aspect-ratio:3/4;
 max-width:420px;width:100%;margin-inline:auto;box-shadow:0 30px 70px rgba(17,24,39,.18)}
.err-illo img{display:block;width:100%;height:100%;object-fit:cover}
.err-illo .fallback{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:14px;color:rgba(255,255,255,.92)}
.err-illo .fallback .big{font-family:'Cormorant',Georgia,serif;font-size:clamp(72px,14vw,128px);line-height:.9}
.err-illo .fallback .lbl{font-weight:800;letter-spacing:.18em;text-transform:uppercase;font-size:12px;opacity:.9}
.err-code{font-weight:800;letter-spacing:.16em;text-transform:uppercase;color:var(--accent);font-size:13px;margin-bottom:14px}
.err-title{font-family:'Cormorant',Georgia,serif;font-size:clamp(40px,7vw,72px);line-height:1.02;margin:0 0 16px}
.err-msg{color:var(--muted);font-size:clamp(16px,1.6vw,19px);line-height:1.6;max-width:46ch;margin:0 0 24px}
.err-poem{margin:0 0 28px;padding:2px 0 2px 18px;border-left:2px solid var(--accent);max-width:46ch}
.err-poem p{font-family:'Cormorant',Georgia,serif;font-style:italic;font-size:clamp(18px,2vw,22px);line-height:1.5;color:var(--ink);margin:0;opacity:.9}
.err-actions{display:flex;flex-wrap:wrap;gap:12px}
.btn{display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;letter-spacing:.02em;
 padding:14px 26px;border-radius:9999px;border:2px solid transparent;cursor:pointer;transition:transform .15s,background .15s}
.btn-primary{background:var(--gold);color:#111827;border-color:var(--gold)}.btn-primary:hover{transform:translateY(-2px)}
.btn-outline{border-color:var(--divider);color:var(--ink)}.btn-outline:hover{border-color:var(--ink)}
.err-foot{padding:20px clamp(18px,5vw,48px);color:var(--muted);font-size:13px;border-top:1px solid var(--divider)}
.err-foot a:hover{color:var(--ink)}
@media (max-width:760px){.err-card{grid-template-columns:1fr;text-align:center}.err-illo{order:-1;max-width:300px}.err-msg{margin-inline:auto}.err-actions{justify-content:center}}
@media (prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
</head>
<body>
<header class="err-top"><a class="brand" href="<?= $esc($site) ?>/">AFRO<span class="v">VANGUARD</span></a></header>
<main class="err-wrap">
  <div class="err-card">
    <div>
      <div class="err-code">Error <?= $code ?></div>
      <h1 class="err-title"><?= $esc($title) ?></h1>
      <p class="err-msg"><?= $esc($msg) ?></p>
<?php if ($poemLines): ?>
      <div class="err-poem" role="note" aria-label="A verse for the moment">
        <p><?php foreach ($poemLines as $i => $ln) { echo ($i ? '<br>' : '') . $esc($ln); } ?></p>
      </div>
<?php endif; ?>
      <div class="err-actions">
        <a class="btn btn-primary" href="<?= $esc($site) ?>/">Back home</a>
        <a class="btn btn-outline" href="/diary/">The Diary</a>
        <a class="btn btn-outline" href="/academy/">Academy</a>
      </div>
    </div>
    <figure class="err-illo" aria-hidden="true">
      <picture>
        <source type="image/webp" srcset="<?= $illoDir . $illoBase ?>.webp 1x, <?= $illoDir . $illoBase ?>@2x.webp 2x" />
        <img src="<?= $illoDir . $illoBase ?>.jpg" alt="" loading="eager" decoding="async"
             onerror="this.style.display='none';this.parentNode.parentNode.querySelector('.fallback').style.display='flex'" />
      </picture>
      <div class="fallback" style="display:none"><span class="big"><?= $code ?></span><span class="lbl"><?= $esc($title) ?></span></div>
    </figure>
  </div>
</main>
<footer class="err-foot">&copy; <?= date('Y') ?> Afrovanguard · <a href="<?= $esc($site) ?>/contact/">Contact us</a> · Raising one million incorruptible leaders for Africa.</footer>
</body>
</html><?php
    // Generate a fresh Claude poem for NEXT time — only after the response has
    // been flushed to the client, so the error page never waits on the network.
    // Needs php-fpm's fastcgi_finish_request(); otherwise we skip (a cron can
    // warm the cache via tools/warm-error-poems.php).
    if (class_exists('ErrorPoem') && function_exists('fastcgi_finish_request')) {
        @ob_end_flush();
        @fastcgi_finish_request();
        try { ErrorPoem::maybeRefresh($mood); } catch (\Throwable $e) { /* best-effort */ }
    }
}
