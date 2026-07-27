<?php
/**
 * academy/ngv/edit.php — admin editor for the NextGen Vanguard page.
 *
 * A self-contained, schema-driven form that reads/writes the DB-backed content
 * document (lib/Ngv.php) through the authenticated admin API. Every section,
 * text field, list and — as requested — every PLAN can be toggled on/off and
 * edited here, so nothing on the public page is hard-coded. Admin-gated: a
 * signed-in admin (Studio session) sees the editor; everyone else sees a gate.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$role = function_exists('av_admin_role') ? av_admin_role() : '';
$isAdmin = $role !== '';
$csrf = $isAdmin && function_exists('av_csrf_token') ? av_csrf_token() : '';
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Edit — NextGen Vanguard</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--red:#ec1c24;--orange:#ff6a00;--gold:#ffc300;--ink:#14100c;--line:#e6e8ec;--muted:#606875;--bg:#f4f6f8;--card:#fff;--grad:linear-gradient(120deg,#ec1c24,#ff6a00 55%,#ffc300)}
*{box-sizing:border-box}
body{margin:0;font-family:Montserrat,system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.5}
a{color:var(--red)}
.top{position:sticky;top:0;z-index:10;background:var(--ink);color:#fff;display:flex;align-items:center;gap:14px;padding:12px 20px;flex-wrap:wrap}
.top b{color:var(--gold)}
.top .sp{flex:1}
.btn{border:0;border-radius:999px;padding:10px 20px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:.4em}
.btn-primary{background:var(--grad);color:#fff}
.btn-ghost{background:transparent;color:#fff;border:1px solid rgba(255,255,255,.4)}
.btn-sm{padding:6px 14px;font-size:.85rem}
.wrap{max-width:920px;margin:24px auto;padding:0 18px}
.sec{background:var(--card);border:1px solid var(--line);border-radius:16px;margin-bottom:18px;overflow:hidden}
.sec>h2{margin:0;padding:16px 20px;font-size:1.05rem;background:linear-gradient(120deg,rgba(236,28,36,.06),rgba(255,163,0,.06));border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px}
.sec>.body{padding:18px 20px}
.fld{margin-bottom:14px}
.fld:last-child{margin-bottom:0}
.fld label{display:block;font-weight:600;font-size:.85rem;margin-bottom:5px;color:var(--muted)}
.fld input[type=text],.fld textarea{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font:inherit;background:#fff;color:var(--ink)}
.fld textarea{min-height:70px;resize:vertical}
.fld input:focus,.fld textarea:focus{outline:2px solid var(--orange);border-color:transparent}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.toggle{display:inline-flex;align-items:center;gap:10px;font-weight:700;cursor:pointer;user-select:none}
.toggle input{width:20px;height:20px;accent-color:var(--red)}
.reps{display:grid;gap:12px}
.rep{border:1px solid var(--line);border-radius:12px;padding:14px;background:#fbfbfc;position:relative}
.rep .rep-x{position:absolute;top:8px;right:8px;border:0;background:#fee;color:#c1121f;border-radius:8px;width:28px;height:28px;cursor:pointer;font-weight:800}
.rep .rep-badges{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px}
.hint{font-size:.78rem;color:var(--muted);margin:2px 0 8px}
.add{margin-top:10px;border:1px dashed var(--red);color:var(--red);background:#fff;border-radius:10px;padding:9px 16px;font-weight:700;cursor:pointer}
.status{font-weight:700}
.gate{max-width:520px;margin:12vh auto;background:#fff;border:1px solid var(--line);border-radius:18px;padding:32px;text-align:center}
.gate h1{font-size:1.4rem}
@media(max-width:640px){.row2{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php if (!$isAdmin): ?>
  <div class="gate">
    <h1>Admin sign-in required</h1>
    <p style="color:var(--muted)">This editor manages the NextGen Vanguard page. Sign in to the Academy Studio, then come back here.</p>
    <p><a class="btn btn-primary" href="/academy/studio/" style="color:#fff">Go to the Studio →</a></p>
    <p><a href="/academy/ngv/">View the public page</a></p>
  </div>
<?php else: ?>
  <div class="top">
    <b>NextGen Vanguard</b><span>· page editor</span>
    <span class="sp"></span>
    <span class="status" id="status"></span>
    <a class="btn btn-ghost btn-sm" href="/academy/ngv/" target="_blank" rel="noopener">View page ↗</a>
    <button class="btn btn-ghost btn-sm" id="resetBtn" type="button">Reset to defaults</button>
    <button class="btn btn-primary" id="saveBtn" type="button">Save changes</button>
  </div>
  <div class="wrap" id="form"><p>Loading…</p></div>

  <script>
  const CSRF = <?= json_encode($csrf) ?>;
  const API = '/admin/api.php';
  let DATA = <?= json_encode(Ngv::get(), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;

  // Declarative editor schema. Each section may carry a toggle (a *_enabled
  // boolean), scalar `fields`, an object `list` (repeater) or a `linelist`.
  const SCHEMA = [
    {title:'📢 Page visibility', fields:[
      {type:'bool', path:'enabled', label:'Published — show this page to the public (off = hidden holding page)'},
    ]},
    {title:'🔎 SEO', fields:[
      {type:'text', path:'seo.title', label:'Meta title'},
      {type:'area', path:'seo.desc', label:'Meta description (search + social)'},
      {type:'area', path:'seo.keywords', label:'Keywords (comma separated)'},
      {type:'text', path:'seo.og_image', label:'Social share image URL'},
    ]},
    {title:'🚀 Hero', fields:[
      {type:'text', path:'hero.eyebrow', label:'Eyebrow'},
      {type:'text', path:'hero.audience', label:'Audience line'},
      {type:'text', path:'hero.title_top', label:'Headline — line 1'},
      {type:'text', path:'hero.title_bottom', label:'Headline — line 2 (gold)'},
      {type:'area', path:'hero.sub', label:'Sub-headline'},
      {type:'text', path:'hero.cta_primary_label', label:'Primary button label', half:1},
      {type:'text', path:'hero.cta_primary_url', label:'Primary button URL', half:1},
      {type:'text', path:'hero.cta_secondary_label', label:'Secondary button label', half:1},
      {type:'text', path:'hero.cta_secondary_url', label:'Secondary button URL', half:1},
      {type:'text', path:'hero.image', label:'Hero / share image URL'},
    ]},
    {title:'✨ “Why join us” perks', list:'perks', item:[
      {key:'num', label:'Big text'}, {key:'label', label:'Caption'},
    ]},
    {title:'🏃 Skills marquee', linelist:'marquee', hint:'One skill per line.'},
    {title:'📊 Stats band', list:'stats', item:[{key:'num',label:'Number'},{key:'label',label:'Caption'}]},
    {title:'📖 About', fields:[
      {type:'text', path:'about.title', label:'Heading'},
      {type:'area', path:'about.body', label:'Paragraph 1'},
      {type:'area', path:'about.body2', label:'Paragraph 2'},
    ]},
    {title:'🎯 Tracks', toggle:'tracks_enabled', fields:[
      {type:'text', path:'tracks_title', label:'Heading'},
      {type:'area', path:'tracks_intro', label:'Intro'},
    ], list:'tracks', item:[{key:'icon',label:'Icon (emoji)'},{key:'name',label:'Name'},{key:'desc',label:'Description',type:'area'}]},
    {title:'🗺️ Journey / phases', toggle:'phases_enabled', fields:[
      {type:'text', path:'phases_title', label:'Heading'},
    ], list:'phases', item:[
      {key:'tag',label:'Tag'},{key:'title',label:'Title'},{key:'when',label:'When'},
      {key:'items',label:'Bullet points (one per line)',type:'lines'},
    ]},
    {title:'💳 Plans / programme options', toggle:'plans_enabled', fields:[
      {type:'text', path:'plans_title', label:'Heading'},
      {type:'area', path:'plans_intro', label:'Intro'},
    ], list:'plans', item:[
      {key:'name',label:'Plan name'},{key:'price',label:'Price'},{key:'price_note',label:'Price note'},
      {key:'desc',label:'Description',type:'area'},
      {key:'features',label:'Features (one per line)',type:'lines'},
      {key:'cta_label',label:'Button label'},{key:'cta_url',label:'Button URL'},
      {key:'featured',label:'Highlight as “most popular”',type:'bool'},
      {key:'enabled',label:'Show this plan',type:'bool'},
    ]},
    {title:'✅ Why choose us', toggle:'why_enabled', fields:[
      {type:'text', path:'why_title', label:'Heading'},
    ], linelist:'why', hint:'One reason per line.'},
    {title:'💰 Fees & schedule', toggle:'fees_enabled', fields:[
      {type:'text', path:'fees_title', label:'Heading'},
      {type:'area', path:'fees_note', label:'Support note'},
      {type:'text', path:'schedule.days', label:'Attendance'},
      {type:'text', path:'schedule.time', label:'Daily schedule'},
      {type:'text', path:'schedule.uniform', label:'Dress code'},
      {type:'text', path:'schedule.payment', label:'Payments to'},
    ], list:'fees', item:[{key:'name',label:'Fee name'},{key:'amount',label:'Amount'},{key:'desc',label:'What it covers',type:'area'}]},
    {title:'💬 Testimonials', toggle:'testimonials_enabled', fields:[
      {type:'text', path:'testimonials_title', label:'Heading'},
    ], list:'testimonials', item:[
      {key:'quote',label:'Quote',type:'area'},{key:'name',label:'Name'},{key:'role',label:'Role'},{key:'rating',label:'Rating'},
    ]},
    {title:'❓ FAQ', toggle:'faq_enabled', fields:[
      {type:'text', path:'faq_title', label:'Heading'},
    ], list:'faq', item:[{key:'q',label:'Question'},{key:'a',label:'Answer',type:'area'}]},
    {title:'🎬 Final call-to-action', fields:[
      {type:'text', path:'cta.title', label:'Heading'},
      {type:'area', path:'cta.text', label:'Text'},
      {type:'text', path:'cta.button_label', label:'Button label', half:1},
      {type:'text', path:'cta.button_url', label:'Button URL', half:1},
    ]},
    {title:'📞 Contact', fields:[
      {type:'text', path:'contact.phone', label:'Phone', half:1},
      {type:'text', path:'contact.email', label:'Email', half:1},
      {type:'text', path:'contact.address', label:'Address'},
      {type:'text', path:'contact.apply_url', label:'Apply URL'},
    ]},
  ];

  const gp = (o,p)=>p.split('.').reduce((a,k)=>(a==null?a:a[k]),o);
  const esc = s => (s==null?'':String(s)).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  function fieldHTML(f){
    const v = gp(DATA, f.path);
    if(f.type==='bool') return `<label class="toggle"><input type="checkbox" data-path="${f.path}" ${v?'checked':''}> ${esc(f.label)}</label>`;
    const inp = f.type==='area'
      ? `<textarea data-path="${f.path}">${esc(v)}</textarea>`
      : `<input type="text" data-path="${f.path}" value="${esc(v)}">`;
    return `<div class="fld${f.half?' half':''}"><label>${esc(f.label)}</label>${inp}</div>`;
  }

  function itemFieldHTML(fld, val){
    if(fld.type==='bool') return `<label class="toggle"><input type="checkbox" data-key="${fld.key}" ${val?'checked':''}> ${esc(fld.label||fld.key)}</label>`;
    if(fld.type==='lines'){ const t=Array.isArray(val)?val.join('\n'):(val||''); return `<div class="fld"><label>${esc(fld.label||fld.key)}</label><textarea data-key="${fld.key}">${esc(t)}</textarea></div>`; }
    if(fld.type==='area') return `<div class="fld"><label>${esc(fld.label||fld.key)}</label><textarea data-key="${fld.key}">${esc(val)}</textarea></div>`;
    return `<div class="fld"><label>${esc(fld.label||fld.key)}</label><input type="text" data-key="${fld.key}" value="${esc(val)}"></div>`;
  }

  function repItemHTML(sec, obj){
    const inner = sec.item.map(fld=>itemFieldHTML(fld, obj?obj[fld.key]:'')).join('');
    return `<div class="rep"><button class="rep-x" title="Remove" type="button" onclick="this.closest('.rep').remove()">×</button>${inner}</div>`;
  }

  function render(){
    const root = document.getElementById('form');
    root.innerHTML = SCHEMA.map((sec,si)=>{
      let inner = '';
      if(sec.toggle) inner += `<div class="fld">${fieldHTML({type:'bool',path:sec.toggle,label:'Show this section'})}</div>`;
      if(sec.fields){
        // group consecutive half fields into a row2
        for(let i=0;i<sec.fields.length;i++){
          const f=sec.fields[i], n=sec.fields[i+1];
          if(f.half && n&&n.half){ inner+=`<div class="row2">${fieldHTML(f)}${fieldHTML(n)}</div>`; i++; }
          else inner+=fieldHTML(f);
        }
      }
      if(sec.linelist){
        const arr = DATA[sec.linelist]||[];
        inner += `<div class="fld"><label>Items</label>${sec.hint?`<p class="hint">${esc(sec.hint)}</p>`:''}<textarea data-linelist="${sec.linelist}" style="min-height:120px">${esc(arr.join('\n'))}</textarea></div>`;
      }
      if(sec.list){
        const arr = DATA[sec.list]||[];
        inner += `<div class="reps" data-list="${sec.list}" data-si="${si}">${arr.map(o=>repItemHTML(sec,o)).join('')}</div>`;
        inner += `<button class="add" type="button" data-add="${si}">+ Add item</button>`;
      }
      return `<section class="sec"><h2>${esc(sec.title)}</h2><div class="body">${inner}</div></section>`;
    }).join('');

    root.querySelectorAll('[data-add]').forEach(btn=>btn.addEventListener('click',()=>{
      const sec=SCHEMA[+btn.dataset.add];
      const reps=root.querySelector(`.reps[data-si="${btn.dataset.add}"]`);
      reps.insertAdjacentHTML('beforeend', repItemHTML(sec, {}));
    }));
  }

  function collect(){
    const root=document.getElementById('form');
    const out=JSON.parse(JSON.stringify(DATA));
    const sp=(o,p,val)=>{const ks=p.split('.');let a=o;for(let i=0;i<ks.length-1;i++){a[ks[i]]=a[ks[i]]||{};a=a[ks[i]];}a[ks[ks.length-1]]=val;};
    // scalar + bool fields
    root.querySelectorAll('[data-path]').forEach(el=>{
      sp(out, el.dataset.path, el.type==='checkbox'?el.checked:el.value);
    });
    // line lists
    root.querySelectorAll('[data-linelist]').forEach(el=>{
      out[el.dataset.linelist]=el.value.split('\n').map(s=>s.trim()).filter(Boolean);
    });
    // object lists
    root.querySelectorAll('.reps[data-list]').forEach(reps=>{
      const sec=SCHEMA[+reps.dataset.si];
      const items=[...reps.querySelectorAll('.rep')].map(rep=>{
        const obj={};
        sec.item.forEach(fld=>{
          const el=rep.querySelector(`[data-key="${fld.key}"]`);
          if(!el)return;
          if(fld.type==='bool') obj[fld.key]=el.checked;
          else if(fld.type==='lines') obj[fld.key]=el.value.split('\n').map(s=>s.trim()).filter(Boolean);
          else obj[fld.key]=el.value;
        });
        return obj;
      });
      out[reps.dataset.list]=items;
    });
    return out;
  }

  function setStatus(msg,good){const s=document.getElementById('status');s.textContent=msg;s.style.color=good?'#7CFF9B':'#FFC300';if(good)setTimeout(()=>s.textContent='',2500);}

  async function save(){
    const btn=document.getElementById('saveBtn');btn.disabled=true;setStatus('Saving…');
    try{
      const r=await fetch(API+'?action=ngv_save',{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
        body:JSON.stringify({content:collect()})});
      const d=await r.json();
      if(d.ok){DATA=d.content;render();setStatus('Saved ✓',true);}
      else setStatus(d.error||'Save failed');
    }catch(e){setStatus('Network error');}
    btn.disabled=false;
  }

  async function reset(){
    if(!confirm('Reset the entire NextGen Vanguard page to the shipped defaults? Your edits will be lost.'))return;
    setStatus('Resetting…');
    try{
      const r=await fetch(API+'?action=ngv_reset',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':CSRF}});
      const d=await r.json();
      if(d.ok){DATA=d.content;render();setStatus('Reset ✓',true);}else setStatus(d.error||'Reset failed');
    }catch(e){setStatus('Network error');}
  }

  document.getElementById('saveBtn').addEventListener('click', save);
  document.getElementById('resetBtn').addEventListener('click', reset);
  render();
  </script>
<?php endif; ?>
</body>
</html>
