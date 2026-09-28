<?php
/**
 * give/poster.php — a printable A4 poster for one appeal. /give/<slug>/poster
 *
 * Promotion that does not depend on an algorithm. A great deal of Nigerian
 * giving is arranged in a room — a church noticeboard, a staff canteen, the
 * back of a hall — and a page somebody can print on the office A4 and pin up
 * reaches people no amount of SEO will. The QR is the bridge back to the site,
 * rendered as vector so it survives being enlarged and photocopied.
 *
 * Deliberately standalone: no site nav, no footer, no cookie banner. It is a
 * document, and everything that is not the appeal is ink somebody pays for.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$a = $slug !== '' ? Appeals::bySlug($slug) : null;
if (!Appeals::isPublic($a)) { http_response_code(404); require AV_ROOT . '/404.html'; exit; }

$st    = Appeals::state($a);
$needs = Appeals::currentNeeds((int) $a['id']);
$qr    = Appeals::qrSvg($a, 'poster');
$url   = Appeals::url($a);
$short = preg_replace('~^https?://~', '', Appeals::url($a));

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, follow');      // the appeal is the indexable page
?><!doctype html>
<html lang="en-NG">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Poster — <?= e((string) $a['title']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant:wght@600;700&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
<style>
  @page { size: A4 portrait; margin: 14mm; }
  :root { --ink:#0b0e14; --gold:#f3b416; --muted:#5b6170; --line:#d8dbe0; }
  * { box-sizing: border-box; }
  body { margin:0; background:#f3f4f6; font-family:'Montserrat',system-ui,sans-serif; color:var(--ink); }
  .sheet {
    width: 210mm; min-height: 297mm; margin: 16px auto; background:#fff; padding: 18mm 16mm;
    display:flex; flex-direction:column; gap: 9mm;
  }
  .rule { height:8px; background:var(--gold); }
  .eyebrow { font-size:11pt; letter-spacing:.18em; text-transform:uppercase; font-weight:800; color:#8f6606; margin:0; }
  h1 { font-family:'Cormorant',Georgia,serif; font-size:40pt; line-height:1.02; margin:0; font-weight:700; letter-spacing:-.01em; }
  .tag { font-size:13pt; line-height:1.5; color:var(--muted); margin:0; max-width:62ch; }
  .fig { font-family:'Cormorant',Georgia,serif; font-size:30pt; font-weight:700; margin:0; font-variant-numeric:tabular-nums; }
  .sub { font-size:11pt; color:var(--muted); margin:2mm 0 0; }
  .bar { height:12px; border:1px solid var(--line); border-radius:99px; overflow:hidden; background:#f4f2ec; }
  .bar > span { display:block; height:100%; background:var(--gold); border-radius:99px; }
  .needs { border-top:1px solid var(--line); padding-top:6mm; display:flex; flex-direction:column; gap:4mm; }
  .need { border-left:4px solid var(--gold); padding-left:5mm; }
  .need .w { font-size:10pt; letter-spacing:.14em; text-transform:uppercase; font-weight:800; color:#8f6606; }
  .need .t { font-size:14pt; font-weight:700; margin:1mm 0; }
  .need .a { font-family:'Cormorant',Georgia,serif; font-size:20pt; font-weight:700; }
  .foot { margin-top:auto; display:flex; gap:8mm; align-items:center; border-top:1px solid var(--line); padding-top:6mm; }
  .foot svg { width:42mm; height:42mm; flex:none; }
  .foot path { fill:#000; }
  .u { font-size:15pt; font-weight:800; word-break:break-all; margin:0 0 2mm; }
  .m { font-size:10.5pt; color:var(--muted); margin:0; line-height:1.5; }
  .print { position:fixed; top:14px; right:14px; padding:10px 18px; border-radius:99px; border:0;
           background:var(--ink); color:#fff; font:700 14px Montserrat,sans-serif; cursor:pointer; }
  @media print { .print { display:none; } body { background:#fff; } .sheet { margin:0; width:auto; min-height:0; padding:0; } }
</style>
</head>
<body>
<button class="print" onclick="window.print()">Print this poster</button>
<div class="sheet">
  <div class="rule"></div>
  <div>
    <p class="eyebrow">Afrovanguard <?= e((string) $a['kind'] === 'event' ? 'event' : 'appeal') ?><?php
      if (!empty($a['location'])): ?> · <?= e((string) $a['location']) ?><?php endif; ?></p>
    <h1><?= e((string) $a['title']) ?></h1>
  </div>
  <?php if (!empty($a['tagline'])): ?><p class="tag"><?= e((string) $a['tagline']) ?></p><?php endif; ?>

  <div>
    <p class="fig"><?= e(Appeals::naira($st['raised'])) ?><?php if ($st['goal'] > 0): ?>
      <span style="font-size:15pt;color:var(--muted);font-weight:500"> of <?= e(Appeals::naira($st['goal'])) ?></span><?php endif; ?></p>
    <?php if ($st['percent'] !== null): ?>
      <div class="bar" style="margin-top:3mm"><span style="width:<?= (int) $st['percent'] ?>%"></span></div>
    <?php endif; ?>
    <p class="sub"><?= (int) $st['donors'] ?> donor<?= $st['donors'] === 1 ? '' : 's' ?><?php
      if ($st['days_left'] !== null && $st['days_left'] >= 0): ?> · <?= (int) $st['days_left'] ?> days left<?php endif; ?><?php
      if ($st['match_live'] && $st['match_left'] > 0): ?> · every gift doubled up to <?= e(Appeals::naira($st['match_left'])) ?><?php endif; ?></p>
  </div>

  <?php if ($needs): ?>
    <div class="needs">
      <?php foreach (array_slice($needs, 0, 3) as $n): ?>
        <div class="need">
          <div class="w"><?= e($n['cadence'] === 'daily' ? 'Today' : ($n['cadence'] === 'weekly' ? 'This week' : 'Still needed')) ?></div>
          <div class="t"><?= e((string) $n['title']) ?></div>
          <div class="a"><?= e(Appeals::naira((int) $n['target_ngn'])) ?><?php
            if ($n['units_target'] > 0 && $n['unit_label'] !== ''): ?>
            <span style="font-size:11pt;color:var(--muted);font-weight:500">
              — <?= e(number_format((int) $n['units_target'])) ?> <?= e((string) $n['unit_label']) ?></span><?php endif; ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="foot">
    <?= $qr !== '' ? $qr : '' ?>
    <div>
      <p class="u"><?= e((string) $short) ?></p>
      <p class="m">Scan the code, or type the address above.<br>
        Payments are taken securely by Paystack. Card, transfer and USSD all work.<br>
        Afrovanguard · Ambassadors for Community, Tech and Cultural Advancements</p>
    </div>
  </div>
</div>
</body>
</html>
