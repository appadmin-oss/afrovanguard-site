<?php
/**
 * lib/errors.php — one standardized, branded, illustrated error page.
 *
 * No DB, so it renders even when the app is broken: the Home nav and footer
 * (partials/avh-chrome.php, plain markup) around one card styled by
 * assets/site/averr.css. Illustrations live at /assets/illustrations/error-<code>.*
 * and are mapped by meaning; the numeral fallback shows until they land.
 *
 *   av_error_render(404);         // echoes a full page
 *   av_error_render(404, true);   // the static copy Apache serves
 *                                 // (php tools/build-error-pages.php)
 */
declare(strict_types=1);

function av_error_meta(int $code): array {
    $map = [
        400 => ['Bad request', 'That request didn’t make sense to us.', 'lost'],
        401 => ['Sign in required', 'You need to be signed in to view this page.', 'stop'],
        403 => ['Access restricted', 'You don’t have permission to view this page.', 'stop'],
        404 => ['Page not found', 'We looked everywhere, but this page isn’t here.', 'lost'],
        405 => ['Not allowed', 'That action isn’t allowed here.', 'stop'],
        413 => ['Too large', 'That upload is larger than we can accept.', 'lost'],
        429 => ['Easy does it', 'Too many requests — give it a moment and try again.', 'stop'],
        500 => ['Something broke', 'We’re looking into it. Please try again shortly.', 'examine'],
        503 => ['Back shortly', 'We’re doing a little maintenance. Please check back soon.', 'examine'],
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

function av_error_render(int $code, bool $static = false): void {
    if (!$static && !headers_sent()) {
        http_response_code($code);
        if (function_exists('send_security_headers')) send_security_headers('public');
        header('Content-Type: text/html; charset=utf-8');
    }
    [$title, $msg, $mood] = av_error_meta($code);
    [$illoBase] = av_error_illo($mood);
    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $illoDir = '/assets/illustrations/';
    // A short poem "for the moment" — Claude-written when configured, curated
    // otherwise. Never touches the network here (pick() reads a cache); fresh
    // verses are generated in the background after the response is sent.
    // The static copies (tools/build-error-pages.php) carry none: Apache serves
    // them when PHP itself may be down, and a frozen verse is not "for the moment".
    $poemLines = [];
    if (!$static) {
        try { if (class_exists('ErrorPoem')) { $p = ErrorPoem::pick($code, $mood); $poemLines = $p['lines'] ?? []; } }
        catch (\Throwable $e) { $poemLines = []; }
    }
    // The Home nav and footer: plain markup, no DB, so a broken app still draws them.
    require_once dirname(__DIR__) . '/partials/avh-chrome.php';
    ['nav' => $nav, 'foot' => $foot] = avh_chrome_html();
    if ($static) {  // the markers tools/build-avh-chrome.php keeps in step
        $nav  = "<!-- avh:nav -->\n" . $nav . "\n<!-- /avh:nav -->";
        $foot = "<!-- avh:foot -->\n" . $foot . "\n<!-- /avh:foot -->";
    }
    ?><!DOCTYPE html>
<html lang="en-NG" class="no-js" data-mood="<?= $esc($mood) ?>">
<head>
<script>document.documentElement.classList.replace('no-js','js');if(/(^|; )av_si=1/.test(document.cookie))document.documentElement.classList.add('av-si')</script>
<meta charset="UTF-8" />
<link rel="icon" href="/favicon.ico" sizes="any" />
<link rel="icon" type="image/png" sizes="192x192" href="/assets/site/icon-192.png" />
<link rel="apple-touch-icon" href="/assets/site/icon-192.png" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
<meta name="robots" content="noindex, follow" />
<title><?= $code ?> · <?= $esc($title) ?> — Afrovanguard</title>
<link rel="stylesheet" href="/assets/site/fonts.css" />
<link rel="stylesheet" href="/assets/site/av-tokens.css" />
<link rel="stylesheet" href="/assets/site/avh.css" />
<link rel="stylesheet" href="/assets/site/averr.css" />
<script src="/assets/site/avh.js" defer></script>
</head>
<body class="avh" id="top">
<a class="avh-skip" href="#main">Skip to content</a>
<div class="avh-page">
<?= $nav ?>

<main id="main" tabindex="-1" class="averr">
  <div class="averr-card">
    <div>
      <p class="averr-code">Error <?= $code ?></p>
      <h1 class="averr-title"><?= $esc($title) ?></h1>
      <p class="averr-msg"><?= $esc($msg) ?></p>
<?php if ($poemLines): ?>
      <div class="averr-poem" role="note" aria-label="A verse for the moment">
        <p><?php foreach ($poemLines as $i => $ln) { echo ($i ? '<br>' : '') . $esc($ln); } ?></p>
      </div>
<?php endif; if ($code === 404): ?>
      <form class="averr-search" role="search" action="/search.php" method="get">
        <input id="averr-q" type="search" name="q" minlength="2" placeholder="Search the site" aria-label="Search Afrovanguard" />
        <button type="submit">Search</button>
      </form>
<?php endif; ?>
      <div class="averr-actions">
        <a class="averr-btn" href="/">Back home</a>
        <a class="averr-btn averr-btn--line" href="/diary/">The Diary</a>
        <a class="averr-btn averr-btn--line" href="/academy/">Academy</a>
      </div>
    </div>
    <figure class="averr-illo" aria-hidden="true">
      <picture>
        <source type="image/webp" srcset="<?= $illoDir . $illoBase ?>.webp 1x, <?= $illoDir . $illoBase ?>@2x.webp 2x" />
        <img src="<?= $illoDir . $illoBase ?>.jpg" alt="" loading="eager" decoding="async" onerror="this.style.display='none';this.closest('.averr-illo').classList.add('is-fallback')" />
      </picture>
      <div class="averr-fallback"><b><?= $code ?></b><span><?= $esc($title) ?></span></div>
    </figure>
  </div>
</main>

<?= $foot ?>

</div>
<script src="/assets/site/chioma.js" defer></script>
<script src="/assets/site/celebrations.js" defer></script>
</body>
</html><?php
    // Generate a fresh Claude poem for NEXT time — only after the response has
    // been flushed to the client, so the error page never waits on the network.
    // Needs php-fpm's fastcgi_finish_request(); otherwise we skip (a cron can
    // warm the cache via tools/warm-error-poems.php).
    if (!$static && class_exists('ErrorPoem') && function_exists('fastcgi_finish_request')) {
        @ob_end_flush();
        @fastcgi_finish_request();
        try { ErrorPoem::maybeRefresh($mood); } catch (\Throwable $e) { /* best-effort */ }
    }
}


/** The codes .htaccess serves as static files (ErrorDocument). */
function av_error_static_codes(): array { return [403, 404, 429, 500, 503]; }

/** One static error page's exact bytes (tools/build-error-pages.php writes them). */
function av_error_static_html(int $code): string {
    ob_start(); av_error_render($code, true); return (string) ob_get_clean();
}
