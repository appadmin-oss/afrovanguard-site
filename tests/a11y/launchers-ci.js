/**
 * tests/a11y/launchers-ci.js — the two things on every page that a reader
 * drives by keyboard and that float or open over the page: the site search
 * palette (assets/site/avsearch.js) and the Accessibility launcher and panel
 * (assets/site/a11y.js, bottom-ui.js). axe-ci.js checks what they say; this
 * checks what they DO, in Chromium:
 *
 *   - the palette: "/", Ctrl+K, the combobox keys, Tab kept inside, filter
 *     chips, Enter opens, recent searches, no results, focus handed back;
 *     the portal keeps its own Ctrl+K; without JavaScript it is a link;
 *   - the panel: Alt+Shift+A, the footer link, the controls, Tab kept inside,
 *     Escape and focus back to the launcher, the launcher hidden while open;
 *   - the bottom clearance: --av-bottom-ui measured from the cookie notice and
 *     the portal's phone tab bar, both launchers above it, the tab waiting on
 *     a phone while the notice asks;
 *   - tuck on scroll and never over a control (a button put under its
 *     resting spot), focus always bringing it back, reduced motion, RTL;
 *   - no overlap with Chioma, at 1280, 390 and 320; out of print;
 *   - Chioma's launcher by the same rule (launcher-tuck.js): tuck, focus, its
 *     panel open, its nudge, never over a control, reduced motion, RTL, print;
 *   - axe on the open states with the display settings off and on.
 *
 *   BASE=http://127.0.0.1:8000 MEMBER_TOKEN=<…> node launchers-ci.js
 *
 * MEMBER_TOKEN (tests/a11y/ci-session.php --member) adds the portal checks.
 */
'use strict';
const { chromium } = require('playwright'); const fs=require('fs');
const BASE=(process.env.BASE||'http://127.0.0.1:8000').replace(/\/$/,''), TOK=process.env.MEMBER_TOKEN||'';
const AXE=fs.readFileSync(require.resolve('axe-core/axe.min.js'),'utf8');
let pass=0, fail=[]; const ck=(l,c)=>{ if(c) pass++; else fail.push(l); };
const ae = pg => pg.evaluate(()=>{const a=document.activeElement;return a?(a.id||a.className||a.tagName):''});
async function ctx(b,w,h,opts={}){const c=await b.newContext({viewport:{width:w,height:h},reducedMotion:'reduce'});
 const ck=[]; if(!opts.noConsent)ck.push({name:'av_consent',value:'analytics%3D0',url:BASE}); if(opts.signed&&TOK)ck.push({name:'av_lms',value:TOK,url:BASE}); if(opts.a11y)ck.push({name:'av_a11y',value:opts.a11y,url:BASE}); if(ck.length)await c.addCookies(ck); return c;}
async function go(c,p){const pg=await c.newPage();await pg.goto(BASE+p,{waitUntil:'networkidle'}).catch(()=>{});await pg.waitForTimeout(300);return pg;}
const rect=(pg,s)=>pg.evaluate(s=>{const e=document.querySelector(s);if(!e||!e.getClientRects().length)return null;const r=e.getBoundingClientRect();return {l:r.left,t:r.top,r:r.right,b:r.bottom}},s);
const overlap=(a,b)=>a&&b&&a.l<b.r&&b.l<a.r&&a.t<b.b&&b.t<a.b;
async function axeRun(pg){await pg.addScriptTag({content:AXE});return pg.evaluate(async()=>(await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']},resultTypes:['violations']})).violations.map(v=>v.id+'('+v.nodes.length+'): '+v.nodes.slice(0,2).map(n=>n.target.join(' ')).join(' | ')));}
(async()=>{const b=await chromium.launch();
/* ── Search palette, keyboard ── */
{const c=await ctx(b,1280,800);const pg=await go(c,'/about.html');
 await pg.keyboard.press('/'); await pg.waitForTimeout(200);
 ck('palette: "/" opens it', await pg.evaluate(()=>!!document.querySelector('#av-search[open]')));
 ck('palette: focus is in the combobox', await pg.evaluate(()=>document.activeElement.getAttribute('role')==='combobox'));
 ck('palette: empty state shows Popular', await pg.evaluate(()=>/Popular/.test(document.querySelector('#avs-list').textContent)));
 await pg.keyboard.type('mentors'); await pg.waitForResponse(r=>r.url().includes('format=json')).catch(()=>{}); await pg.waitForTimeout(700);
 /* The count is spoken a beat after the typing settles (AvA11y.announce, 450ms). */
 await pg.waitForFunction(()=>/result/.test((document.querySelector('#av-search #av-announce-polite')||{}).textContent||''), null, { timeout: 3000 }).catch(()=>{});
 const st=await pg.evaluate(()=>{const i=document.querySelector('.avs-q');return {exp:i.getAttribute('aria-expanded'),ad:i.getAttribute('aria-activedescendant'),n:document.querySelectorAll('#avs-list [role=option]').length,live:(document.querySelector('#av-search #av-announce-polite')||{textContent:''}).textContent,sel:document.querySelectorAll('[aria-selected=true]').length,mark:!!document.querySelector('#avs-list mark')}});
 ck('palette: results as you type, expanded, first option active', st.exp==='true'&&st.ad==='avs-o0'&&st.n>1&&st.sel===1);
 ck('palette: matched words marked', st.mark);
 ck('palette: count announced politely, by the one shared announcer inside the open dialog ('+st.live+')', /result/.test(st.live));
 await pg.keyboard.press('ArrowDown'); ck('palette: ↓ moves the active option', await pg.evaluate(()=>document.querySelector('.avs-q').getAttribute('aria-activedescendant'))==='avs-o1');
 await pg.keyboard.press('ArrowUp'); await pg.keyboard.press('ArrowUp');
 ck('palette: ↑ wraps to the last option', await pg.evaluate(()=>{const n=document.querySelectorAll('#avs-list [role=option]').length;return document.querySelector('.avs-q').getAttribute('aria-activedescendant')==='avs-o'+(n-1)}));
 // Tab stays inside
 let inside=true; for(let i=0;i<14;i++){await pg.keyboard.press('Tab'); if(!await pg.evaluate(()=>!!document.activeElement.closest('#av-search'))) inside=false;}
 ck('palette: Tab stays inside the dialog', inside);
 // chips: focus chips and arrow
 await pg.focus('.avs-q'); for(let i=0;i<4&&!(await pg.evaluate(()=>document.activeElement.classList.contains('avs-chip')));i++) await pg.keyboard.press('Tab');
 const onChip=await pg.evaluate(()=>document.activeElement.classList.contains('avs-chip'));
 await pg.keyboard.press('ArrowRight'); await pg.keyboard.press('Enter'); await pg.waitForTimeout(700);
 ck('palette: Tab reaches the chips, → moves, Enter filters', onChip && await pg.evaluate(()=>document.querySelector('.avs-chip[aria-pressed=true]').dataset.type==='Page'));
 ck('palette: focus returns to the field after filtering', await pg.evaluate(()=>document.activeElement.classList.contains('avs-q')));
 await pg.keyboard.press('Escape'); await pg.waitForTimeout(200);
 ck('palette: Esc closes', await pg.evaluate(()=>!document.querySelector('#av-search[open]')));
 // trigger click then Esc returns focus to trigger
 await pg.click('.avh-wide [data-search-open]'); await pg.waitForTimeout(200); await pg.keyboard.press('Escape'); await pg.waitForTimeout(200);
 ck('palette: focus goes back to the header control', await pg.evaluate(()=>document.activeElement.matches('.avh-wide [data-search-open]')));
 ck('palette: control is a button that says it opens a dialog', await pg.evaluate(()=>{const t=document.querySelector('.avh-wide [data-search-open]');return t.tagName==='BUTTON'&&t.getAttribute('aria-haspopup')==='dialog'&&/Control\+K/.test(t.getAttribute('aria-keyshortcuts'))}));
 // Ctrl+K toggles
 await pg.keyboard.press('Control+k'); await pg.waitForTimeout(150); const o1=await pg.evaluate(()=>!!document.querySelector('#av-search[open]'));
 await pg.keyboard.press('Control+k'); await pg.waitForTimeout(150); const o2=await pg.evaluate(()=>!!document.querySelector('#av-search[open]'));
 ck('palette: Ctrl+K opens and closes', o1 && !o2);
 // "/" while typing in a field does nothing
 await pg.focus('input[type=email]').catch(()=>{}); await pg.keyboard.press('/'); await pg.waitForTimeout(150);
 ck('palette: "/" typed in a field stays in the field', await pg.evaluate(()=>!document.querySelector('#av-search[open]')));
 // Enter opens the active option
 await pg.keyboard.press('Escape'); await pg.evaluate(()=>document.activeElement.blur()); await pg.keyboard.press('/'); await pg.keyboard.type('donate'); await pg.waitForTimeout(900);
 await Promise.all([pg.waitForNavigation({timeout:5000}).catch(()=>{}), pg.keyboard.press('Enter')]);
 ck('palette: Enter opens the active result ('+pg.url()+')', pg.url().endsWith('/donate.html'));
 ck('palette: the search was remembered', await pg.evaluate(()=>JSON.parse(localStorage.getItem('av.search.recent')||'[]')[0]==='donate'));
 // Recent + clear
 await pg.keyboard.press('Control+k'); await pg.waitForTimeout(200);
 ck('palette: recent searches show when empty', await pg.evaluate(()=>/Recent searches/.test(document.querySelector('#avs-list').textContent)));
 const n=await pg.evaluate(()=>[...document.querySelectorAll('#avs-list [role=option]')].findIndex(o=>/Clear recent/.test(o.textContent)));
 for(let i=0;i<=n;i++) await pg.keyboard.press('ArrowDown'); await pg.keyboard.press('Enter'); await pg.waitForTimeout(150);
 ck('palette: Clear recent searches empties them', await pg.evaluate(()=>!localStorage.getItem('av.search.recent') && !/Recent searches/.test(document.querySelector('#avs-list').textContent)));
 // no results + suggestion; Enter without choice → search page
 await pg.keyboard.type('phxtogrxphy qqzz'); await pg.waitForTimeout(900);
 ck('palette: no results says so and offers Browse instead + Ask', await pg.evaluate(()=>/No results/.test(document.querySelector('[data-avs-note]').textContent)&&/Browse instead/.test(document.querySelector('#avs-list').textContent)&&/Ask Chioma/.test(document.querySelector('#avs-list').textContent)));
 await pg.close(); await c.close();}
/* ── No JS: the control is a link to the search page; the form works ── */
{const c=await b.newContext({javaScriptEnabled:false,viewport:{width:1280,height:800}});const pg=await c.newPage();await pg.goto(BASE+'/about.html');
 ck('no-js: the header control is a link to /search.php', await pg.evaluate(()=>document.querySelector('.avh-wide a[data-search-open]').getAttribute('href')==='/search.php'));
 ck('no-js: the footer link reaches the settings form', await pg.evaluate(()=>!!document.querySelector('a[data-a11y-link][href="/accessibility/#settings"]')));
 await pg.goto(BASE+'/search.php?q=mentorship'); ck('no-js: the search page lists results', await pg.evaluate(()=>document.querySelectorAll('.avsr-item').length>0));
 await c.close();}
/* ── Portal: its own Ctrl+K stays; the header control opens site search ── */
if (TOK) {const c=await ctx(b,1280,800,{signed:true});const pg=await go(c,'/portal/#overview');
 await pg.keyboard.press('Control+k'); await pg.waitForTimeout(300);
 ck('portal: Ctrl+K still opens the portal palette, not site search', await pg.evaluate(()=>!document.querySelector('#av-search[open]')));
 await pg.keyboard.press('Escape'); await pg.click('.avn-top-actions [data-search-open]'); await pg.waitForTimeout(300);
 ck('portal: the header control opens site search', await pg.evaluate(()=>!!document.querySelector('#av-search[open]')));
 await pg.keyboard.press('Escape');
 const fab=await rect(pg,'.av-a11y-fab'), side=await rect(pg,'.avp-side');
 ck('portal: the button sits past the sidebar on a wide screen', fab && side && fab.l>=side.r);
 await pg.close(); await c.close();}
if (TOK) {const c=await ctx(b,390,844,{signed:true});const pg=await go(c,'/portal/#overview');
 const fab=await rect(pg,'.av-a11y-fab'), tabs=await rect(pg,'.avp-tabs');
 ck('portal phone: the button sits above the tab bar', fab && tabs && fab.b<=tabs.t);
 await pg.close(); await c.close();}
/* ── Accessibility panel, keyboard ── */
{const c=await ctx(b,1280,800);const pg=await go(c,'/about.html');
 await pg.keyboard.press('Alt+Shift+A'); await pg.waitForTimeout(200);
 ck('panel: Alt+Shift+A opens it', await pg.evaluate(()=>!!document.querySelector('#av-a11y[open]')));
 ck('panel: focus starts on the chosen text size', await pg.evaluate(()=>document.activeElement.name==='av-a11y-text'&&document.activeElement.checked));
 await pg.keyboard.press('ArrowRight'); await pg.waitForTimeout(100);
 ck('panel: → picks 125% at once', await pg.evaluate(()=>document.documentElement.classList.contains('a11y-t125')));
 await pg.keyboard.press('Tab'); ck('panel: Tab goes to the contrast group', await pg.evaluate(()=>document.activeElement.name==='av-a11y-hc'));
 await pg.keyboard.press('Tab'); await pg.keyboard.press('Space'); await pg.waitForTimeout(100);
 ck('panel: Space flips a switch (role=switch, checked)', await pg.evaluate(()=>document.activeElement.getAttribute('role')==='switch'&&document.activeElement.checked&&document.documentElement.classList.contains('a11y-spacing')));
 ck('panel: the cookie keeps it', /av_a11y=t125\.spacing/.test(await pg.evaluate(()=>document.cookie)));
 let inside=true; for(let i=0;i<16;i++){await pg.keyboard.press('Tab'); if(!await pg.evaluate(()=>!!document.activeElement.closest('#av-a11y'))) inside=false;}
 ck('panel: Tab stays inside', inside);
 await pg.click('[data-a11y-reset]'); await pg.waitForTimeout(100);
 ck('panel: Reset returns everything to standard', await pg.evaluate(()=>!/a11y-/.test(document.documentElement.className)));
 ck('panel: it fits without scrolling on a laptop', await pg.evaluate(()=>{const d=document.querySelector('#av-a11y');return d.scrollHeight<=d.clientHeight+1}));
 ck('panel: Reset is announced through the shared announcer inside the dialog', await pg.evaluate(()=>new Promise(r=>setTimeout(()=>r(/reset/i.test((document.querySelector('#av-a11y #av-announce-polite')||{}).textContent||'')),250))));
 /* WCAG 2.1.4: single-key shortcuts can be turned off. */
 await pg.click('input[name="av-a11y-nokeys"]'); await pg.waitForTimeout(100);
 ck('panel: "Single-key shortcuts" off stores nokeys', await pg.evaluate(()=>document.documentElement.classList.contains('a11y-nokeys')&&/av_a11y=nokeys/.test(document.cookie)));
 await pg.keyboard.press('Escape'); await pg.waitForTimeout(100); await pg.evaluate(()=>document.body.focus());
 await pg.keyboard.press('/'); await pg.waitForTimeout(150);
 ck('keys: with single-key shortcuts off, "/" does not open the search', await pg.evaluate(()=>!document.querySelector('#av-search[open]')));
 await pg.keyboard.press('Control+k'); await pg.waitForTimeout(150);
 ck('keys: Ctrl+K still does', await pg.evaluate(()=>!!document.querySelector('#av-search[open]')));
 await pg.keyboard.press('Escape'); await pg.waitForTimeout(100);
 await pg.keyboard.press('Alt+Shift+A'); await pg.waitForTimeout(150);
 await pg.click('[data-a11y-reset]'); await pg.waitForTimeout(100);
 const pr=await rect(pg,'#av-a11y');
 ck('panel: the launcher steps aside while it is open', !(await rect(pg,'.av-a11y-fab')));
 ck('panel: it opens in the launcher\'s corner', pr && Math.abs(pr.l-16)<2 && Math.abs(800-16-pr.b)<2);
 await pg.keyboard.press('Escape'); await pg.waitForTimeout(150);
 await pg.focus('.av-a11y-fab'); await pg.keyboard.press('Enter'); await pg.waitForTimeout(150); await pg.keyboard.press('Escape'); await pg.waitForTimeout(150);
 ck('panel: Esc closes and focus returns to the round button', await pg.evaluate(()=>!document.querySelector('#av-a11y[open]')&&document.activeElement.classList.contains('av-a11y-fab')));
 ck('fab: named, a dialog opener, its shortcut said', await pg.evaluate(()=>{const f=document.querySelector('.av-a11y-fab');return f.getAttribute('aria-label')==='Accessibility settings'&&f.getAttribute('aria-haspopup')==='dialog'&&f.getAttribute('aria-keyshortcuts')==='Alt+Shift+A'}));
 ck('fab: the tooltip shows on keyboard focus', await pg.evaluate(()=>getComputedStyle(document.querySelector('.av-a11y-tip')).visibility==='visible'));
 await pg.keyboard.press('Escape'); await pg.waitForTimeout(150);
 ck('fab: Esc dismisses the tooltip', await pg.evaluate(()=>getComputedStyle(document.querySelector('.av-a11y-tip')).visibility==='hidden'));
 await pg.keyboard.press('Enter'); await pg.waitForTimeout(150);
 ck('fab: Enter opens the panel', await pg.evaluate(()=>!!document.querySelector('#av-a11y[open]')));
 await pg.keyboard.press('Escape');
 // footer link
 await pg.click('a[data-a11y-link]'); await pg.waitForTimeout(150);
 ck('panel: the footer link opens it', await pg.evaluate(()=>!!document.querySelector('#av-a11y[open]')));
 await pg.close(); await c.close();}
/* ── Never overlapping: Chioma, the cookie notice, at 320 and desktop; out of print ── */
for(const [w,h] of [[1280,800],[390,844],[320,640]]) for(const p of ['/','/about.html','/academy/','/events/','/search.php?q=tech','/404.html']) {
 const c=await ctx(b,w,h);const pg=await go(c,p);
 const f=await rect(pg,'.av-a11y-fab'), ch=await rect(pg,'.chioma-fab'), cc=await rect(pg,'.av-cc');
 ck(`layout ${w} ${p}: button present, ≥44px`, f && f.r-f.l>=44 && f.b-f.t>=44);
 ck(`layout ${w} ${p}: not on Chioma`, !overlap(f,ch));
 ck(`layout ${w} ${p}: not on the cookie notice`, !overlap(f,cc));
 await pg.emulateMedia({media:'print'}); ck(`layout ${w} ${p}: hidden in print`, !(await rect(pg,'.av-a11y-fab'))); await pg.emulateMedia({media:'screen'});
 await pg.close(); await c.close(); }
/* ── Bottom UI, tuck, never over a control (after Gee) ── */
{ // cookie notice: desktop — both launchers ride above it; phone — the tab waits
 for (const [w,h] of [[1280,800],[700,800],[375,812],[320,640]]) {
  const c=await ctx(b,w,h,{noConsent:true});const pg=await go(c,'/about.html'); await pg.waitForTimeout(300);
  const cc=await rect(pg,'.av-cc'), f=await rect(pg,'.av-a11y-fab'), ch=await rect(pg,'.chioma-fab');
  const v=await pg.evaluate(()=>getComputedStyle(document.body).getPropertyValue('--av-bottom-ui').trim());
  ck(`cookie ${w}: the notice is measured (--av-bottom-ui ${v})`, cc && parseInt(v)===Math.round(h-cc.t));
  if (w<600) ck(`cookie ${w}: on a phone the tab waits while the notice asks`, !f);
  else ck(`cookie ${w}: the button sits above the notice`, f && f.b<=cc.t);
  if (w<560) ck(`cookie ${w}: Chioma waits too on a phone`, !ch); else ck(`cookie ${w}: Chioma sits above the notice too`, !ch || ch.b<=cc.t+1);
  await pg.click('.av-cc button[data-v="0"]'); await pg.waitForTimeout(300);
  const f2=await rect(pg,'.av-a11y-fab'), v2=await pg.evaluate(()=>getComputedStyle(document.body).getPropertyValue('--av-bottom-ui').trim());
  ck(`cookie ${w}: answered, the figure goes back to 0 and the button returns to its corner`, v2==='0px' && f2 && Math.abs(h-16-f2.b)<2);
  await pg.close(); await c.close(); }
}
if (TOK) { // the portal's phone tab bar
 for (const [w,h] of [[375,812],[320,640]]) { const c=await ctx(b,w,h,{signed:true});const pg=await go(c,'/portal/#overview'); await pg.waitForTimeout(400);
  const f=await rect(pg,'.av-a11y-fab'), t=await rect(pg,'.avp-tabs'); const v=await pg.evaluate(()=>getComputedStyle(document.body).getPropertyValue('--av-bottom-ui').trim());
  ck(`portal ${w}: the tab bar is measured (${v}) and the launcher sits 16px above it`, t && parseInt(v)===Math.round(h-t.t) && f && Math.abs(t.t-16-f.b)<2);
  ck(`portal ${w}: flush with the edge on a phone`, f && f.l===0 && f.r-f.l>=44 && f.b-f.t>=44);
  await pg.close(); await c.close(); }
}
{ // never over a control: put a button under the resting spot  (on /404.html: about.html's hero buttons rightly sit under the spot at 320px)
 for (const [w,h] of [[1280,800],[320,640]]) { const c=await ctx(b,w,h);const pg=await go(c,'/404.html'); await pg.waitForTimeout(300);
  ck(`over-control ${w}: clear spot, not tucked`, await pg.evaluate(()=>!document.querySelector('.av-a11y-fab').hasAttribute('data-tuck')));
  await pg.evaluate(()=>{const x=document.createElement('button');x.id='under';x.textContent='Under';x.style.cssText='position:fixed;left:0;bottom:0;width:120px;height:120px;z-index:1';document.body.appendChild(x);dispatchEvent(new Event('resize'));});
  await pg.waitForTimeout(200);
  ck(`over-control ${w}: a button under the resting spot keeps it tucked`, await pg.evaluate(()=>document.querySelector('.av-a11y-fab').hasAttribute('data-tuck')));
  ck(`over-control ${w}: …and the button underneath is clickable`, await pg.evaluate(()=>{const r=document.getElementById('under').getBoundingClientRect();return document.elementFromPoint(r.left+30,r.bottom-30).id==='under'}));
  await pg.keyboard.press('Tab'); await pg.focus('.av-a11y-fab');
  ck(`over-control ${w}: keyboard focus still brings it back`, await pg.evaluate(()=>{const f=document.querySelector('.av-a11y-fab');return !f.hasAttribute('data-tuck')||getComputedStyle(f).opacity==='1'}));
  await pg.evaluate(()=>{document.activeElement.blur();document.getElementById('under').remove();dispatchEvent(new Event('resize'));}); await pg.waitForTimeout(200);
  ck(`over-control ${w}: the spot clear again, it comes back`, await pg.evaluate(()=>!document.querySelector('.av-a11y-fab').hasAttribute('data-tuck')));
  await pg.close(); await c.close(); }
}
{ // tuck on scroll
 for (const [w,h,phone] of [[1280,800,false],[375,812,true]]) { const c=await ctx(b,w,h);const pg=await go(c,'/'); await pg.waitForTimeout(300);
  const tucked=()=>pg.evaluate(()=>document.querySelector('.av-a11y-fab').hasAttribute('data-tuck'));
  await pg.mouse.wheel(0,600); await pg.waitForTimeout(150);
  ck(`tuck ${w}: reading down tucks it`, await tucked());
  ck(`tuck ${w}: tucked by transform and opacity, still in the tab order`, await pg.evaluate(()=>{const f=document.querySelector('.av-a11y-fab'),cs=getComputedStyle(f);return cs.display!=='none'&&cs.opacity==='0'&&f.tabIndex===0}));
  await pg.waitForTimeout(1200);
  const after=await tucked(); const blocked=await pg.evaluate(()=>{const f=document.querySelector('.av-a11y-fab');const r={x:f.offsetLeft,y:f.offsetTop,w:f.offsetWidth,h:f.offsetHeight};return document.elementsFromPoint(r.x+r.w/2,r.y+r.h/2).some(e=>e!==f&&!f.contains(e)&&e.closest&&e.closest('a[href],button,input,select,textarea,summary,label,[role=button],[role=link],[tabindex]:not([tabindex="-1"])'))});
  if (phone) ck(`tuck ${w}: on a phone it stays away after the scrolling stops`, after);
  else ck(`tuck ${w}: on a desktop it returns ~900ms after the scrolling stops (unless over a control: ${blocked})`, after===blocked);
  await pg.mouse.wheel(0,-200); await pg.waitForTimeout(150);
  const up=await tucked(); ck(`tuck ${w}: scrolling up brings it back (unless over a control)`, !up || blocked);
  await pg.mouse.wheel(0,600); await pg.waitForTimeout(150); await pg.focus('.av-a11y-fab');
  ck(`tuck ${w}: never tucked while it has focus`, !(await tucked()));
  await pg.close(); await c.close(); }
}
{ const c=await b.newContext({viewport:{width:1280,height:800},reducedMotion:'reduce'}); await c.addCookies([{name:'av_consent',value:'analytics%3D0',url:BASE}]); const pg=await go(c,'/');
  await pg.mouse.wheel(0,600); await pg.waitForTimeout(150);
  ck('tuck: with reduced motion it fades without sliding', await pg.evaluate(()=>{const f=document.querySelector('.av-a11y-fab');return f.hasAttribute('data-tuck')&&getComputedStyle(f).transform==='none'}));
  await pg.close(); await c.close(); }
{ const c=await ctx(b,1280,800);const pg=await go(c,'/about.html');
  await pg.evaluate(()=>document.documentElement.setAttribute('dir','rtl')); await pg.waitForTimeout(100);
  const f=await rect(pg,'.av-a11y-fab'); ck('rtl: the launcher mirrors to the inline start (right)', f && Math.abs(1280-16-f.r)<20);
  await pg.close(); await c.close(); }

/* ── Chioma's launcher: the same rule, from the same module (launcher-tuck.js) ── */
const chTucked=pg=>pg.evaluate(()=>document.querySelector('.chioma-fab').hasAttribute('data-tuck'));
const chBlocked=pg=>pg.evaluate(()=>{const f=document.querySelector('.chioma-fab');let x=0,y=0,e=f;while(e){x+=e.offsetLeft;y+=e.offsetTop;e=e.offsetParent;}
  return document.elementsFromPoint(x+f.offsetWidth/2,y+f.offsetHeight/2).some(el=>!el.closest('[data-av-launcher]')&&el.closest('a[href],button,input,select,textarea,summary,label,[role=button],[role=link],[tabindex]:not([tabindex="-1"])'))});
{ for (const [w,h,phone] of [[1280,800,false],[375,812,true],[320,640,true]]) { const c=await ctx(b,w,h);const pg=await go(c,'/'); await pg.waitForTimeout(300);
  ck(`chioma ${w}: both launchers registered with the shared module`, await pg.evaluate(()=>!!window.AvTuck&&document.querySelector('.chioma-root').hasAttribute('data-av-launcher')&&document.querySelector('.av-a11y-fab').hasAttribute('data-av-launcher')));
  await pg.mouse.wheel(0,600); await pg.waitForTimeout(150);
  ck(`chioma ${w}: reading down tucks it`, await chTucked(pg));
  ck(`chioma ${w}: tucked by transform and opacity, still in the tab order`, await pg.evaluate(()=>{const f=document.querySelector('.chioma-fab'),cs=getComputedStyle(f);return cs.display!=='none'&&cs.opacity==='0'&&cs.pointerEvents==='none'&&f.tabIndex===0}));
  await pg.waitForTimeout(1200);
  const after=await chTucked(pg), blocked=await chBlocked(pg);
  if (phone) ck(`chioma ${w}: on a phone it stays away after the scrolling stops`, after);
  else ck(`chioma ${w}: on a desktop it returns ~900ms after the scrolling stops (unless over a control: ${blocked})`, after===blocked);
  await pg.mouse.wheel(0,-200); await pg.waitForTimeout(150);
  ck(`chioma ${w}: scrolling up brings it back (unless over a control)`, !(await chTucked(pg)) || await chBlocked(pg));
  await pg.mouse.wheel(0,600); await pg.waitForTimeout(150); await pg.focus('.chioma-fab');
  ck(`chioma ${w}: never tucked while it has focus`, !(await chTucked(pg)) && await pg.evaluate(()=>getComputedStyle(document.querySelector('.chioma-fab')).opacity==='1'));
  await pg.keyboard.press('Enter'); await pg.waitForTimeout(300);
  ck(`chioma ${w}: Enter still opens the panel`, await pg.evaluate(()=>document.querySelector('.chioma-root').classList.contains('is-open')));
  if (!phone) { await pg.focus('#chInput'); await pg.mouse.wheel(0,400); await pg.waitForTimeout(150);
    ck(`chioma ${w}: never tucked while its panel is open`, !(await chTucked(pg))); }
  await pg.keyboard.press('Escape'); await pg.waitForTimeout(300);
  ck(`chioma ${w}: Escape closes it and focus comes back to the launcher, visible`, await pg.evaluate(()=>!document.querySelector('.chioma-root').classList.contains('is-open')&&document.activeElement.id==='chFab'&&!document.activeElement.hasAttribute('data-tuck')));
  await pg.emulateMedia({media:'print'}); ck(`chioma ${w}: hidden in print`, !(await rect(pg,'.chioma-fab'))); await pg.emulateMedia({media:'screen'});
  await pg.close(); await c.close(); }
}
{ // the nudge goes with the launcher, and waits while it is away
 const c=await ctx(b,1280,800);const pg=await go(c,'/about.html');
 await pg.evaluate(()=>document.querySelector('#chGreet').classList.add('show'));
 await pg.mouse.wheel(0,600); await pg.waitForTimeout(150);
 ck('chioma nudge: tucking hides a showing nudge', await chTucked(pg) && await pg.evaluate(()=>!document.querySelector('#chGreet').classList.contains('show')));
 await pg.close(); await c.close(); }
{ // never over a control
 for (const [w,h] of [[1280,800],[320,640]]) { const c=await ctx(b,w,h);const pg=await go(c,'/404.html'); await pg.waitForTimeout(300);
  ck(`chioma over-control ${w}: clear spot, not tucked`, !(await chTucked(pg)));
  await pg.evaluate(()=>{const x=document.createElement('button');x.id='under';x.textContent='Under';x.style.cssText='position:fixed;right:0;bottom:0;width:120px;height:120px;z-index:1';document.body.appendChild(x);dispatchEvent(new Event('resize'));});
  await pg.waitForTimeout(200);
  ck(`chioma over-control ${w}: a button under the resting spot keeps it tucked`, await chTucked(pg));
  ck(`chioma over-control ${w}: …and the button underneath is clickable`, await pg.evaluate(()=>{const r=document.getElementById('under').getBoundingClientRect();return document.elementFromPoint(r.right-30,r.bottom-30).id==='under'}));
  await pg.focus('.chioma-fab');
  ck(`chioma over-control ${w}: keyboard focus still brings it back`, await pg.evaluate(()=>getComputedStyle(document.querySelector('.chioma-fab')).opacity==='1'));
  await pg.evaluate(()=>{document.activeElement.blur();document.getElementById('under').remove();dispatchEvent(new Event('resize'));}); await pg.waitForTimeout(200);
  ck(`chioma over-control ${w}: the spot clear again, it comes back`, !(await chTucked(pg)));
  await pg.close(); await c.close(); }
}
{ const c=await b.newContext({viewport:{width:1280,height:800},reducedMotion:'reduce'}); await c.addCookies([{name:'av_consent',value:'analytics%3D0',url:BASE}]); const pg=await go(c,'/');
  await pg.mouse.wheel(0,600); await pg.waitForTimeout(150);
  ck('chioma: with reduced motion it fades without sliding', await pg.evaluate(()=>{const f=document.querySelector('.chioma-fab');return f.hasAttribute('data-tuck')&&getComputedStyle(f).transform==='none'}));
  await pg.close(); await c.close(); }
{ const c=await ctx(b,1280,800);const pg=await go(c,'/about.html');
  await pg.evaluate(()=>document.documentElement.setAttribute('dir','rtl')); await pg.waitForTimeout(100);
  const ch=await rect(pg,'.chioma-fab'), f=await rect(pg,'.av-a11y-fab');
  ck('rtl: Chioma mirrors to the inline end (left), clear of the Accessibility button', ch && ch.l<40 && !overlap(ch,f));
  await pg.mouse.wheel(0,600); await pg.waitForTimeout(400);
  const t=await rect(pg,'.chioma-fab'); ck('rtl: Chioma tucks off its own (left) edge', t && t.r<=0);
  await pg.close(); await c.close(); }

/* ── Open states through axe, with settings off and on, desktop and 320 ── */
const axeOut=[];
for(const mode of ['', 't200.spacing.hc-dark', 't200.spacing.hc-light']) for(const [w,h] of [[1280,800],[320,640]]) for(const p of ['/'].concat(TOK?['/academy/techome/learn/welcome-to-techome','/portal/#overview']:[])) for(const state of ['palette empty','palette results','panel']){
 const c=await ctx(b,w,h,{signed:p!=='/',a11y:mode||undefined});const pg=await go(c,p);
 await pg.addStyleTag({content:'*,*::before,*::after{animation-duration:0s!important;transition-duration:0s!important}'});
 if(state==='panel') await pg.evaluate(()=>document.querySelector('.av-a11y-fab').click());
 else { await pg.evaluate(()=>window.AvSearch.open()); if(state==='palette results'){await pg.keyboard.type('tech');await pg.waitForTimeout(900);} }
 await pg.waitForTimeout(200);
 const v=await axeRun(pg); const ov=w===320?await pg.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth):0;
 if(ov>1) v.push('reflow '+ov);
 if(v.length) axeOut.push(`${mode||'standard'} ${w} ${p} [${state}]: `+v.join(' ; '));
 await pg.close(); await c.close(); }
ck('axe: no WCAG violations with the palette or panel open ('+axeOut.length+')', axeOut.length===0);
await b.close();
console.log('PASS', pass, 'FAIL', fail.length); fail.forEach(f=>console.log(' ✗', f)); axeOut.forEach(a=>console.log(' axe', a));
if (!TOK) console.log('(no MEMBER_TOKEN: the portal checks were skipped)');
if (fail.length) process.exit(1);
})().catch(e => { console.error(e); process.exit(1); });
