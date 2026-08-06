<?php
/**
 * academy/ngv/edit.php — admin editor for the NextGen Vanguard page.
 *
 * A modern, two-pane, schema-driven editor (sticky section nav + clean cards)
 * that reads/writes the DB-backed content document (lib/Ngv.php) through the
 * authenticated admin API. Every section, field and list — and each PLAN, with
 * its own duration + fee — can be toggled and edited here, so nothing on the
 * public page is hard-coded. Admin-gated with a friendly sign-in fallback.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$role    = function_exists('av_admin_role') ? av_admin_role() : '';
$isAdmin = $role !== '';
$csrf    = $isAdmin && function_exists('av_csrf_token') ? av_csrf_token() : '';
$hasPrev = $isAdmin ? Ngv::hasPrevious() : false;
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Edit · NextGen Vanguard</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--red:#e4162b;--orange:#ff6a1a;--gold:#ffb703;--ink:#15120e;--line:#e7e9ee;--muted:#5f6874;
  --bg:#f5f6f8;--card:#fff;--grad:linear-gradient(100deg,#e4162b,#ff6a1a 55%,#ffb703);--r:12px}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{margin:0;font-family:Montserrat,system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.5}
a{color:var(--red)}
/* top bar */
.top{position:sticky;top:0;z-index:20;background:rgba(21,18,14,.96);backdrop-filter:blur(8px);color:#fff;
  display:flex;align-items:center;gap:12px;padding:12px 22px;flex-wrap:wrap}
.top .brand{font-weight:800}.top .brand b{color:var(--gold)}
.top .sp{flex:1}
.btn{border:0;border-radius:10px;padding:10px 18px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;
  display:inline-flex;align-items:center;gap:.4em;transition:transform .12s,opacity .12s}
.btn:hover{transform:translateY(-1px)}
.btn:disabled{opacity:.5;cursor:default;transform:none}
.btn-primary{background:var(--grad);color:#fff}
.btn-ghost{background:rgba(255,255,255,.12);color:#fff}
.btn-sm{padding:7px 13px;font-size:.84rem}
.status{font-weight:700;font-size:.9rem;min-width:60px}
/* layout */
.shell{max-width:1180px;margin:24px auto;padding:0 20px;display:grid;grid-template-columns:212px 1fr;gap:26px;align-items:start}
.nav{position:sticky;top:82px;display:grid;gap:2px}
.nav a{display:block;padding:8px 12px;border-radius:9px;color:var(--muted);text-decoration:none;font-weight:600;font-size:.9rem}
.nav a:hover{background:#eceef2;color:var(--ink)}
.nav a.on{background:#fff;color:var(--ink);box-shadow:0 1px 2px rgba(0,0,0,.05)}
.main{display:grid;gap:20px;min-width:0}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;overflow:hidden;scroll-margin-top:80px}
.card>header{display:flex;align-items:center;gap:12px;padding:16px 22px;border-bottom:1px solid var(--line)}
.card>header h2{margin:0;font-size:1.02rem;flex:1}
.card>.body{padding:20px 22px}
.fld{margin-bottom:15px}.fld:last-child{margin-bottom:0}
.fld>label{display:block;font-weight:600;font-size:.82rem;margin-bottom:6px;color:var(--muted)}
.fld input[type=text],.fld textarea{width:100%;border:1px solid var(--line);border-radius:10px;padding:11px 13px;
  font:inherit;background:#fff;color:var(--ink);transition:border .12s,box-shadow .12s}
.fld textarea{min-height:74px;resize:vertical}
.fld input:focus,.fld textarea:focus{outline:0;border-color:var(--orange);box-shadow:0 0 0 3px rgba(255,106,26,.15)}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.hint{font-size:.78rem;color:var(--muted);margin:-2px 0 8px}
/* switch */
.sw{position:relative;display:inline-flex;align-items:center;gap:9px;cursor:pointer;font-weight:700;font-size:.86rem;user-select:none}
.sw input{position:absolute;opacity:0;width:0;height:0}
.sw .track{width:40px;height:23px;border-radius:999px;background:#cdd2da;transition:background .15s;position:relative;flex:none}
.sw .track::after{content:"";position:absolute;top:2px;left:2px;width:19px;height:19px;border-radius:50%;background:#fff;transition:transform .15s;box-shadow:0 1px 2px rgba(0,0,0,.3)}
.sw input:checked+.track{background:linear-gradient(100deg,#e4162b,#ff6a1a)}
.sw input:checked+.track::after{transform:translateX(17px)}
/* repeater */
.reps{display:grid;gap:12px}
.rep{border:1px solid var(--line);border-radius:12px;padding:16px;background:#fbfbfc;position:relative}
.rep-head{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.rep-head .n{width:24px;height:24px;border-radius:7px;background:var(--grad);color:#fff;display:grid;place-items:center;font-size:.78rem;font-weight:800;flex:none}
.rep-head .t{font-weight:700;font-size:.92rem;flex:1;color:var(--ink)}
.rep-x{border:1px solid var(--line);background:#fff;color:#c1121f;border-radius:8px;width:30px;height:30px;cursor:pointer;font-weight:800;font-size:1rem}
.rep-x:hover{background:#fee}
.rep .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.rep .grid .full{grid-column:1/-1}
.rep .sw{margin-top:4px}
.add{margin-top:12px;border:1.5px dashed #d6b3b8;color:var(--red);background:#fff;border-radius:10px;padding:11px;
  font-weight:700;cursor:pointer;width:100%;transition:background .12s}
.add:hover{background:#fff6f6}
/* gate */
.gate{max-width:520px;margin:12vh auto;background:#fff;border:1px solid var(--line);border-radius:18px;padding:36px;text-align:center}
.gate h1{font-size:1.4rem;margin:.2em 0 .4em}
.gate p{color:var(--muted)}
@media(max-width:820px){.shell{grid-template-columns:1fr}.nav{position:static;display:flex;flex-wrap:wrap;gap:6px}.row2,.rep .grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php if (!$isAdmin): ?>
  <div class="gate">
    <h1>Admin sign-in required</h1>
    <p>This editor manages the public NextGen Vanguard page. Sign in to the Academy Studio, then come back here.</p>
    <p style="margin-top:20px"><a class="btn btn-primary" href="/academy/studio/" style="color:#fff">Go to the Studio →</a></p>
    <p style="margin-top:14px"><a href="/academy/ngv/">View the public page</a></p>
  </div>
<?php else: ?>
  <div class="top">
    <span class="brand"><b>NextGen Vanguard</b> · editor</span>
    <span class="sp"></span>
    <span class="status" id="status"></span>
    <a class="btn btn-ghost btn-sm" href="/academy/ngv/" target="_blank" rel="noopener">View ↗</a>
    <a class="btn btn-ghost btn-sm" href="/academy/ngv/members.php">Vanguards</a>
    <button class="btn btn-ghost btn-sm" id="restoreBtn" type="button"<?= $hasPrev ? '' : ' style="display:none"' ?>>Undo last save</button>
    <button class="btn btn-ghost btn-sm" id="resetBtn" type="button">Reset</button>
    <button class="btn btn-primary" id="saveBtn" type="button">Save changes</button>
  </div>
  <div class="shell">
    <nav class="nav" id="nav"></nav>
    <div class="main" id="main"><p>Loading…</p></div>
  </div>

  <script>
  const CSRF=<?= json_encode($csrf) ?>, API='/admin/api.php';
  let DATA=<?= json_encode(Ngv::get(), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  let dirty=false;
  const setDirty=v=>{dirty=v;};

  const SCHEMA=[
    {id:'visibility',title:'Page visibility',fields:[
      {type:'bool',path:'enabled',label:'Published — show this page to the public (off = hidden holding page)'},
    ]},
    {id:'seo',title:'SEO',fields:[
      {type:'text',path:'seo.title',label:'Meta title'},
      {type:'area',path:'seo.desc',label:'Meta description (search + social)'},
      {type:'area',path:'seo.keywords',label:'Keywords (comma separated)'},
      {type:'text',path:'seo.og_image',label:'Social share image URL'},
    ]},
    {id:'hero',title:'Hero',fields:[
      {type:'text',path:'hero.promo_tagline',label:'Promo tagline (the small pill)'},
      {type:'text',path:'hero.eyebrow',label:'Eyebrow'},
      {type:'text',path:'hero.audience',label:'Audience line'},
      {type:'text',path:'hero.title_top',label:'Headline — line 1',half:1},
      {type:'text',path:'hero.title_bottom',label:'Headline — line 2 (gradient)',half:1},
      {type:'area',path:'hero.sub',label:'Sub-headline'},
      {type:'text',path:'hero.cta_primary_label',label:'Primary button label',half:1},
      {type:'text',path:'hero.cta_primary_url',label:'Primary button URL',half:1},
      {type:'text',path:'hero.cta_secondary_label',label:'Secondary button label',half:1},
      {type:'text',path:'hero.cta_secondary_url',label:'Secondary button URL',half:1},
    ]},
    {id:'perks',title:'Hero perks',addLabel:'perk',list:'perks',itemTitle:'label',item:[
      {key:'num',label:'Big text'},{key:'label',label:'Caption'},
    ]},
    {id:'skills',title:'Skills strip',linelist:'marquee',hint:'One skill per line.'},
    {id:'stats',title:'Stats',addLabel:'stat',list:'stats',itemTitle:'label',item:[{key:'num',label:'Number'},{key:'label',label:'Caption'}]},
    {id:'about',title:'About',fields:[
      {type:'text',path:'about.title',label:'Heading'},
      {type:'area',path:'about.body',label:'Paragraph 1'},
      {type:'area',path:'about.body2',label:'Paragraph 2'},
    ]},
    {id:'tracks',title:'Tracks',toggle:'tracks_enabled',addLabel:'track',fields:[
      {type:'text',path:'tracks_title',label:'Heading'},{type:'area',path:'tracks_intro',label:'Intro'},
    ],list:'tracks',itemTitle:'name',item:[{key:'icon',label:'Icon (emoji)',half:1},{key:'name',label:'Name',half:1},{key:'desc',label:'Description',type:'area',full:1}]},
    {id:'phases',title:'Journey / phases',toggle:'phases_enabled',addLabel:'phase',fields:[
      {type:'text',path:'phases_title',label:'Heading'},
    ],list:'phases',itemTitle:'title',item:[
      {key:'tag',label:'Tag',half:1},{key:'title',label:'Title',half:1},{key:'when',label:'When',full:1},
      {key:'items',label:'Bullet points (one per line)',type:'lines',full:1},
    ]},
    {id:'plans',title:'Plans',toggle:'plans_enabled',addLabel:'plan',fields:[
      {type:'text',path:'plans_title',label:'Heading'},{type:'area',path:'plans_intro',label:'Intro'},
    ],list:'plans',itemTitle:'name',newItem:{name:'New plan',price:'Free',cta_label:'Apply now',cta_url:'https://bit.ly/ngv',enabled:true,featured:false},item:[
      {key:'name',label:'Plan name',half:1},{key:'duration',label:'Duration (e.g. 6 months)',half:1},
      {key:'price',label:'Price',half:1},{key:'price_note',label:'Price note',half:1},
      {key:'desc',label:'Description',type:'area',full:1},
      {key:'features',label:'Features (one per line)',type:'lines',full:1},
      {key:'cta_label',label:'Button label',half:1},{key:'cta_url',label:'Button URL',half:1},
      {key:'featured',label:'Highlight as “most popular”',type:'bool'},
      {key:'enabled',label:'Show this plan on the page',type:'bool',default:true},
    ]},
    {id:'why',title:'Why choose us',toggle:'why_enabled',fields:[
      {type:'text',path:'why_title',label:'Heading'},
    ],linelist:'why',hint:'One reason per line.'},
    {id:'fees',title:'Fees & schedule',toggle:'fees_enabled',addLabel:'fee',fields:[
      {type:'text',path:'fees_title',label:'Heading'},{type:'area',path:'fees_note',label:'Support note'},
      {type:'text',path:'schedule.days',label:'Attendance',half:1},{type:'text',path:'schedule.time',label:'Daily schedule',half:1},
      {type:'text',path:'schedule.uniform',label:'Dress code',half:1},{type:'text',path:'schedule.payment',label:'Payments to',half:1},
    ],list:'fees',itemTitle:'name',item:[{key:'name',label:'Fee name',half:1},{key:'amount',label:'Amount',half:1},{key:'desc',label:'What it covers',type:'area',full:1}]},
    {id:'testimonials',title:'Testimonials',toggle:'testimonials_enabled',addLabel:'testimonial',fields:[
      {type:'text',path:'testimonials_title',label:'Heading'},
    ],list:'testimonials',itemTitle:'name',item:[
      {key:'quote',label:'Quote',type:'area',full:1},{key:'name',label:'Name',half:1},{key:'role',label:'Role',half:1},{key:'rating',label:'Rating (e.g. 4.9)',half:1},
    ]},
    {id:'faq',title:'FAQ',toggle:'faq_enabled',addLabel:'question',fields:[
      {type:'text',path:'faq_title',label:'Heading'},
    ],list:'faq',itemTitle:'q',item:[{key:'q',label:'Question',full:1},{key:'a',label:'Answer',type:'area',full:1}]},
    {id:'cta',title:'Final call-to-action',fields:[
      {type:'text',path:'cta.title',label:'Heading'},{type:'area',path:'cta.text',label:'Text'},
      {type:'text',path:'cta.button_label',label:'Button label',half:1},{type:'text',path:'cta.button_url',label:'Button URL',half:1},
    ]},
    {id:'offices',title:'Offices',addLabel:'office',list:'offices',itemTitle:'name',item:[{key:'name',label:'Office name',half:1},{key:'address',label:'Address',half:1}]},
    {id:'contact',title:'Contact',fields:[
      {type:'text',path:'contact.phone',label:'Phone',half:1},{type:'text',path:'contact.email',label:'Email',half:1},
      {type:'text',path:'contact.apply_url',label:'Apply URL'},
    ]},
  ];

  const gp=(o,p)=>p.split('.').reduce((a,k)=>a==null?a:a[k],o);
  const esc=s=>(s==null?'':String(s)).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const sw=(attrs,checked,label)=>`<label class="sw"><input type="checkbox" ${attrs} ${checked?'checked':''}><span class="track"></span> ${esc(label)}</label>`;

  function fieldHTML(f){
    const v=gp(DATA,f.path);
    if(f.type==='bool')return `<div class="fld">${sw('data-path="'+f.path+'"',v,f.label)}</div>`;
    const inp=f.type==='area'?`<textarea data-path="${f.path}">${esc(v)}</textarea>`:`<input type="text" data-path="${f.path}" value="${esc(v)}">`;
    return `<div class="fld">${f.label?`<label>${esc(f.label)}</label>`:''}${inp}</div>`;
  }
  function itemField(fld,val){
    const cls=fld.full?'full':(fld.half?'':'full');
    if(fld.type==='bool')return `<div class="${fld.full?'full':''}">${sw('data-key="'+fld.key+'"',val,fld.label||fld.key)}</div>`;
    if(fld.type==='lines'){const t=Array.isArray(val)?val.join('\n'):(val||'');return `<div class="fld ${cls}"><label>${esc(fld.label||fld.key)}</label><textarea data-key="${fld.key}">${esc(t)}</textarea></div>`;}
    if(fld.type==='area')return `<div class="fld ${cls}"><label>${esc(fld.label||fld.key)}</label><textarea data-key="${fld.key}">${esc(val)}</textarea></div>`;
    return `<div class="fld ${cls}"><label>${esc(fld.label||fld.key)}</label><input type="text" data-key="${fld.key}" value="${esc(val)}"></div>`;
  }
  function repItem(sec,obj,i){
    const title=esc((obj&&obj[sec.itemTitle])||'New item');
    const inner=sec.item.map(f=>itemField(f,obj?obj[f.key]:(f.type==='bool'?false:''))).join('');
    return `<div class="rep"><div class="rep-head"><span class="n">${i+1}</span><span class="t">${title}</span>`
      +`<button class="rep-x" title="Remove" type="button" onclick="this.closest('.rep').remove()">×</button></div><div class="grid">${inner}</div></div>`;
  }
  function sectionHTML(sec){
    let inner='';
    if(sec.fields)for(let i=0;i<sec.fields.length;i++){const f=sec.fields[i],n=sec.fields[i+1];
      if(f.half&&n&&n.half){inner+=`<div class="row2">${fieldHTML(f)}${fieldHTML(n)}</div>`;i++;}else inner+=fieldHTML(f);}
    if(sec.linelist){const arr=DATA[sec.linelist]||[];
      inner+=`<div class="fld">${sec.hint?`<p class="hint">${esc(sec.hint)}</p>`:''}<textarea data-linelist="${sec.linelist}" style="min-height:120px">${esc(arr.join('\n'))}</textarea></div>`;}
    if(sec.list){const arr=DATA[sec.list]||[];
      inner+=`<div class="reps" data-list="${sec.list}" data-sec="${sec.id}">${arr.map((o,i)=>repItem(sec,o,i)).join('')}</div>`
        +`<button class="add" type="button" data-add="${sec.id}">+ Add ${esc(sec.addLabel||'item')}</button>`;}
    const toggle=sec.toggle?sw('data-path="'+sec.toggle+'"',DATA[sec.toggle]!==false,'Show'):'';
    return `<section class="card" id="sec-${sec.id}"><header><h2>${esc(sec.title)}</h2>${toggle}</header><div class="body">${inner}</div></section>`;
  }

  function render(){
    document.getElementById('nav').innerHTML=SCHEMA.map(s=>`<a href="#sec-${s.id}" data-nav="${s.id}">${esc(s.title)}</a>`).join('');
    const main=document.getElementById('main');
    main.innerHTML=SCHEMA.map(sectionHTML).join('');
    main.querySelectorAll('[data-add]').forEach(b=>b.addEventListener('click',()=>{
      const sec=SCHEMA.find(s=>s.id===b.dataset.add);
      const reps=main.querySelector(`.reps[data-sec="${b.dataset.add}"]`);
      // Seed a new card with sensible defaults so it's usable (and, for plans,
      // visible) immediately: explicit newItem template, then any field defaults.
      const seed=Object.assign({},sec.newItem||{});
      sec.item.forEach(f=>{if(f.default!==undefined&&seed[f.key]===undefined)seed[f.key]=f.default;});
      const el=document.createElement('div');
      el.innerHTML=repItem(sec,seed,reps.children.length);
      const node=el.firstElementChild;
      reps.appendChild(node);
      node.scrollIntoView({behavior:'smooth',block:'center'});
      node.querySelector('input,textarea')?.focus();
    }));
    // scrollspy
    const links=[...document.querySelectorAll('[data-nav]')];
    const io=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){
      links.forEach(l=>l.classList.toggle('on',l.dataset.nav===e.target.id.replace('sec-','')));}}),
      {rootMargin:'-40% 0px -55% 0px'});
    SCHEMA.forEach(s=>{const el=document.getElementById('sec-'+s.id);if(el)io.observe(el);});
    setDirty(false); // a fresh render reflects the saved state
  }
  const updateRestore=has=>{const b=document.getElementById('restoreBtn');if(b)b.style.display=has?'':'none';};

  function collect(){
    const main=document.getElementById('main');
    const out=JSON.parse(JSON.stringify(DATA));
    const sp=(o,p,v)=>{const ks=p.split('.');let a=o;for(let i=0;i<ks.length-1;i++){a[ks[i]]=a[ks[i]]||{};a=a[ks[i]];}a[ks.at(-1)]=v;};
    main.querySelectorAll('[data-path]').forEach(el=>sp(out,el.dataset.path,el.type==='checkbox'?el.checked:el.value));
    main.querySelectorAll('[data-linelist]').forEach(el=>out[el.dataset.linelist]=el.value.split('\n').map(s=>s.trim()).filter(Boolean));
    main.querySelectorAll('.reps[data-list]').forEach(reps=>{
      const sec=SCHEMA.find(s=>s.id===reps.dataset.sec);
      out[reps.dataset.list]=[...reps.querySelectorAll('.rep')].map(rep=>{
        const o={};sec.item.forEach(f=>{const el=rep.querySelector(`[data-key="${f.key}"]`);if(!el)return;
          if(f.type==='bool')o[f.key]=el.checked;
          else if(f.type==='lines')o[f.key]=el.value.split('\n').map(s=>s.trim()).filter(Boolean);
          else o[f.key]=el.value;});return o;});
    });
    return out;
  }

  const st=(m,ok)=>{const s=document.getElementById('status');s.textContent=m;s.style.color=ok?'#31c48d':'#ffb703';if(ok)setTimeout(()=>s.textContent='',2500);};
  async function save(){
    const b=document.getElementById('saveBtn');b.disabled=true;st('Saving…');
    try{const r=await fetch(API+'?action=ngv_save',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({content:collect()})});
      const d=await r.json();if(d.ok){DATA=d.content;render();updateRestore(true);st('Saved ✓',true);}else st(d.error||'Save failed');}
    catch(e){st('Network error');}b.disabled=false;}
  async function reset(){
    if(!confirm('Reset the whole NextGen Vanguard page to the shipped defaults? You can still Undo this afterwards.'))return;st('Resetting…');
    try{const r=await fetch(API+'?action=ngv_reset',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':CSRF}});
      const d=await r.json();if(d.ok){DATA=d.content;render();updateRestore(d.has_previous);st('Reset ✓',true);}else st(d.error||'Reset failed');}
    catch(e){st('Network error');}}
  async function restore(){
    if(!confirm('Restore the previous saved version? The current content is kept as the new restore point, so you can toggle back.'))return;st('Restoring…');
    try{const r=await fetch(API+'?action=ngv_restore',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':CSRF}});
      const d=await r.json();if(d.ok){DATA=d.content;render();updateRestore(d.has_previous);st('Restored ✓',true);}else st(d.error||'Nothing to restore');}
    catch(e){st('Network error');}}
  document.getElementById('saveBtn').addEventListener('click',save);
  document.getElementById('resetBtn').addEventListener('click',reset);
  document.getElementById('restoreBtn').addEventListener('click',restore);
  // Unsaved-changes guard: any edit marks the form dirty; add/remove count too.
  const mainEl=document.getElementById('main');
  mainEl.addEventListener('input',()=>setDirty(true));
  mainEl.addEventListener('change',()=>setDirty(true));
  mainEl.addEventListener('click',e=>{if(e.target.closest('.rep-x')||e.target.closest('.add'))setDirty(true);});
  window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});
  render();
  </script>
<?php endif; ?>
</body>
</html>
