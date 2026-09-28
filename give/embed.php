<?php
/**
 * give/embed.php — a small live widget for one appeal. /give/<slug>/embed
 *
 * Meant to be iframed by a partner, a church site, a sponsor's page. It is the
 * figure, the bar and one button, and it is deliberately tiny: an embed that
 * carries the whole appeal is a page somebody will not put on their site.
 *
 * `frame-ancestors *` on purpose — this ONE page is the thing we want embedded
 * anywhere, and it contains nothing that could be clickjacked: no form, no
 * session-bearing action, and its only link leaves the frame for the real page.
 * The site's default DENY still protects every other page.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$a = $slug !== '' ? Appeals::bySlug($slug) : null;
if (!Appeals::isPublic($a)) { http_response_code(404); header('Content-Type: text/plain'); echo 'Appeal not found.'; exit; }

$st  = Appeals::state($a);
$url = Appeals::url($a, 'embed');

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Security-Policy: frame-ancestors *');
header_remove('X-Frame-Options');
header('Cache-Control: public, max-age=300');
?><!doctype html>
<html lang="en-NG">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e((string) $a['title']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
<style>
  :root { --ink:#111827; --muted:#646b77; --gold:#f3b416; --line:#e5e7eb; --bg:#fff; }
  @media (prefers-color-scheme: dark) { :root { --ink:#f3f4f6; --muted:#9aa2b1; --line:#2a3242; --bg:#0d1220; } }
  * { box-sizing:border-box; }
  body { margin:0; font-family:'Montserrat',system-ui,sans-serif; background:var(--bg); color:var(--ink); }
  .w { border:1px solid var(--line); border-radius:14px; padding:18px; display:flex; flex-direction:column; gap:12px; }
  .t { font-size:15px; font-weight:800; line-height:1.3; margin:0; }
  .t a { color:inherit; text-decoration:none; }
  .t a:hover { text-decoration:underline; }
  .f { font-size:22px; font-weight:800; font-variant-numeric:tabular-nums; }
  .g { font-size:12px; color:var(--muted); }
  .bar { height:9px; border-radius:99px; background:var(--line); overflow:hidden; }
  .bar > span { display:block; height:100%; background:var(--gold); border-radius:99px; min-width:3px; }
  .b { display:block; text-align:center; padding:11px 16px; border-radius:99px; background:var(--gold);
       color:#0b0e14; font-weight:800; text-decoration:none; font-size:14px; }
  .b:focus-visible { outline:3px solid var(--ink); outline-offset:2px; }
  .c { font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:var(--muted); font-weight:700; }
</style>
</head>
<body>
<div class="w">
  <div class="c">Afrovanguard appeal</div>
  <p class="t"><a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e((string) $a['title']) ?></a></p>
  <div>
    <div class="f"><?= e(Appeals::naira($st['raised'])) ?>
      <?php if ($st['goal'] > 0): ?><span class="g">of <?= e(Appeals::naira($st['goal'])) ?></span><?php endif; ?></div>
    <?php if ($st['percent'] !== null): ?>
      <div class="bar" style="margin-top:8px" role="progressbar" aria-valuenow="<?= (int) $st['percent'] ?>"
           aria-valuemin="0" aria-valuemax="100" aria-label="<?= (int) $st['percent'] ?>% raised">
        <span style="width:<?= (int) $st['percent'] ?>%"></span></div>
    <?php endif; ?>
    <div class="g" style="margin-top:6px"><?= (int) $st['donors'] ?> donor<?= $st['donors'] === 1 ? '' : 's' ?><?php
      if ($st['days_left'] !== null && $st['days_left'] >= 0): ?> · <?= (int) $st['days_left'] ?> days left<?php endif; ?></div>
  </div>
  <a class="b" href="<?= e($url) ?>" target="_blank" rel="noopener">
    <?= $st['accepting'] ? 'Give to this appeal' : 'See this appeal' ?></a>
</div>
</body>
</html>
