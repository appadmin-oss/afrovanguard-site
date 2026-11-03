<?php
/**
 * academy/ngv/_staff-style.php — the chrome the NGV staff pages share.
 *
 * Fines, the book list and the reading review desk are three standalone
 * pages with one look: the sticky dark bar, the cards, the tables, the pills
 * and the buttons. The block lived three times, identically, which is where
 * drift goes to hide — a radius corrected on one page and not the others.
 * A page with extras of its own adds a second <style> after this one.
 */
declare(strict_types=1);
?>
<style>
:root{--red:#e4162b;--orange:#ff6a1a;--gold:#ffb703;--ink:#15120e;--line:#e7e9ee;--muted:#5f6874;--bg:#f5f6f8;--card:#fff;
  --green:#137a3a;--green-bg:#e6f7ec;--amber:#9a5b00;--amber-bg:#fffaf0;--danger:#c0322b;--danger-bg:#fdecec;--blue:#1d4ed8;--blue-bg:#eef4ff;--r:14px}
*{box-sizing:border-box}
/* `hidden` beats a class's display. .btn sets display:inline-block, which
   outranks the browser's [hidden]{display:none}, so every button the page
   hides by setting .hidden was still on screen — "Cancel editing" with
   nothing being edited. */
[hidden]{display:none!important}
body{margin:0;font-family:'Source Sans 3',system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.5}
a{color:var(--red)}
h1,h2,h3{margin:0;font-weight:800;letter-spacing:-.01em}
:focus-visible{outline:3px solid var(--orange);outline-offset:2px}
.top{position:sticky;top:0;z-index:20;background:rgba(21,18,14,.97);color:#fff;display:flex;gap:12px;align-items:center;padding:10px 20px;flex-wrap:wrap}
.top h1{font-size:.95rem}.top h1 b{color:var(--gold)}.top h1 span{font-weight:600;color:rgba(255,255,255,.7)}
.top .sp{flex:1}.top a{color:rgba(255,255,255,.85);text-decoration:none;font-weight:600;font-size:.86rem}
.top a[aria-current]{color:#fff;border-bottom:2px solid var(--gold)}
.msg{font-size:.85rem;font-weight:700}
.wrap{max-width:1180px;margin:22px auto 56px;padding:0 16px;display:flex;flex-direction:column;gap:18px}
.gatebox{max-width:520px;margin:12vh auto;background:#fff;border:1px solid var(--line);border-radius:18px;padding:36px;text-align:center}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);overflow:hidden}
.card>header{padding:13px 18px;border-bottom:1px solid var(--line);display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.card>header h2{font-size:1rem}.card>header .sp{flex:1}
.card>.body{padding:14px 18px 18px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
.stat{background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:12px 16px}
.stat .k{font-size:.75rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.stat .v{font-size:1.5rem;font-weight:800;font-variant-numeric:tabular-nums}
.stat .s{font-size:.8rem;color:var(--muted)}
table{width:100%;border-collapse:collapse;font-size:.88rem}
th,td{text-align:left;padding:.45rem .55rem;border-top:1px solid var(--line);vertical-align:top}
th{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;border-top:0;white-space:nowrap}
td.num,th.num{font-variant-numeric:tabular-nums;text-align:right;white-space:nowrap}
.pill{display:inline-block;border-radius:999px;padding:.05rem .55rem;font-size:.76rem;font-weight:700;white-space:nowrap}
.s-owing{background:var(--danger-bg);color:var(--danger)}.s-part{background:var(--amber-bg);color:var(--amber)}
.s-settled{background:var(--green-bg);color:var(--green)}.s-waived{background:var(--blue-bg);color:var(--blue)}.s-voided{background:#eef0f3;color:var(--muted);text-decoration:line-through}
.s-ready,.s-fined{background:var(--green-bg);color:var(--green)}.s-ambiguous{background:var(--amber-bg);color:var(--amber)}
.s-unmatched,.s-invalid{background:var(--danger-bg);color:var(--danger)}.s-already,.s-duplicate{background:#eef0f3;color:var(--muted)}
.btn{font:inherit;font-size:.82rem;font-weight:700;border:1px solid var(--line);background:#fff;border-radius:9px;padding:.4rem .75rem;cursor:pointer;color:var(--ink);text-decoration:none;display:inline-block}
.btn.primary{background:var(--ink);color:#fff;border-color:var(--ink)}
.btn.sm{font-size:.76rem;padding:.2rem .5rem}
.btn:disabled{opacity:.5;cursor:not-allowed}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.row label,.lbl{font-size:.78rem;font-weight:700;color:var(--muted);display:flex;flex-direction:column;gap:4px}
input,select,textarea{font:inherit;font-size:.88rem;padding:.42rem .55rem;border:1px solid var(--line);border-radius:9px;background:#fff;color:var(--ink);min-width:0}
textarea{width:100%;min-height:7rem;font-family:ui-monospace,monospace;font-size:.8rem}
.check{flex-direction:row!important;align-items:center;gap:6px!important;font-weight:600!important}
.sub{color:var(--muted);font-size:.84rem}
.note{background:var(--amber-bg);border:1px solid #f2c98a;border-radius:12px;padding:.7rem .9rem;font-size:.9rem;margin:0}
.scroll{overflow-x:auto}
.chips{display:flex;flex-wrap:wrap;gap:6px;min-height:1.8rem}
.chip{background:#f1f2f5;border-radius:999px;padding:.15rem .3rem .15rem .65rem;font-size:.82rem;font-weight:600;display:inline-flex;gap:4px;align-items:center}
.chip button{border:0;background:none;cursor:pointer;font-size:1rem;line-height:1;color:var(--muted)}
.picker{position:relative;flex:1;min-width:16rem}
.picker input{width:100%}
.found{position:absolute;left:0;right:0;top:100%;z-index:5;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.08);margin-top:4px;max-height:16rem;overflow:auto}
.found button{display:block;width:100%;text-align:left;border:0;background:none;padding:.45rem .7rem;font:inherit;font-size:.86rem;cursor:pointer}
.found button:hover,.found button:focus{background:#f5f6f8}
.found small{color:var(--muted);display:block}
.grid input,.grid select{width:100%;font-size:.8rem;padding:.25rem .35rem;border-radius:7px}
.grid td{padding:.3rem .3rem;min-width:6.5rem}
.grid td.w{min-width:10rem}
.grid tr.bad td{background:#fff8f7}
.grid tr.warn td{background:#fffdf5}
.why{font-size:.76rem;color:var(--muted);margin-top:3px}
.why.err{color:var(--danger)}
.tabs{display:flex;gap:6px;flex-wrap:wrap}
.tabs a{font-size:.8rem;font-weight:700;padding:.25rem .65rem;border-radius:999px;border:1px solid var(--line);text-decoration:none;color:var(--ink);background:#fff}
.tabs a.on{background:var(--ink);color:#fff;border-color:var(--ink)}
.cat{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}
/* Book covers. A cover is how somebody finds a book on a shelf, so the
   thumbnail keeps a book's proportions (roughly 1:1.4) and a book with none
   shows its initial rather than a broken-image icon or an empty hole. */
.bkcov-cell{white-space:nowrap}
.bkcov{display:inline-block;vertical-align:middle;width:44px;height:62px;object-fit:cover;border-radius:4px;border:1px solid var(--line);background:#f5f6f8}
.bkcov--none{display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:1.1rem;color:var(--muted)}
.bkcov-pick{display:inline-block;margin-left:6px}
.bkcov-pick .btn{cursor:pointer}
.bkcov-pick input:focus-visible + .btn{outline:3px solid var(--ink);outline-offset:2px}
.bke-cell{min-width:180px}
.bke-have{display:inline-flex;align-items:center;gap:6px;font-weight:700;font-size:.86rem;text-decoration:none;color:var(--ink)}
.bke-have:hover{text-decoration:underline}
.bke-acts{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}
.bke-pick .btn{cursor:pointer}
.bke-pick input:focus-visible + .btn{outline:3px solid var(--ink);outline-offset:2px}
@media (max-width:640px){.top{padding:10px 16px}.wrap{padding:0 12px}.card>.body{padding:12px}}
</style>
