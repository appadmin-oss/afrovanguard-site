/* portal/goalspace.js — CACENTRE's Goal workspaces, worked inline on the
   Tasks page (lib/CacGoalspace.php; CACENTRE's api/afrovanguard-goalspace.php).

   For each of the member's workspaces: how the goal stands, their open tasks
   (say on track, behind or blocked; finish a link task with its link; accept
   or decline a request), and for a lead the team's open work and a form to
   give a member a task. CACENTRE decides who may do what (Goalspace's own
   rules); this shows its answer in words. Loading, empty and an honest error
   with Try again. */
(function () {
  'use strict';
  var root = document.getElementById('goalspace'); if (!root) return;
  var csrf = root.getAttribute('data-csrf') || '';
  var list = document.getElementById('gspList'), msgEl = document.getElementById('gspMsg');
  var WS = [];
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function say(t, bad){ msgEl.hidden = !t; msgEl.textContent = t || ''; msgEl.classList.toggle('is-bad', !!bad); }
  function post(body){
    return fetch('/portal/collab.php?action=gs_do', { method:'POST', credentials:'same-origin',
      headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) })
      .then(function(r){ return r.json().catch(function(){ return { ok:false, error:'Something went wrong on our side (error '+r.status+'). Try again.' }; }); })
      .catch(function(){ return { ok:false, error:'You seem to be offline. Nothing was sent; try again.' }; });
  }
  var PROOF = { link:'a link', file:'a file', signoff:'a sign-off', data_check:'a data check' };
  var STATUS = { open:'', awaiting_proof:'Waiting for its proof', awaiting_review:'Waiting for the lead to check it' };
  function due(d){ if(!d) return ''; var t=new Date(d+'T00:00:00'); var today=new Date(); today.setHours(0,0,0,0);
    var days=Math.round((t-today)/864e5); var s=t.toLocaleDateString(undefined,{day:'numeric',month:'short'});
    return days<0 ? '<b class="is-bad">'+Math.abs(days)+' day'+(days===-1?'':'s')+' overdue</b>' : (days===0?'due today':'due '+esc(s)); }
  function num(n){ return n==null ? '' : (Math.round(n*100)/100).toLocaleString(); }

  function taskHtml(w, t, why){
    var head = (why ? '<span class="gsp-why">'+esc(why)+'</span>' : '') + '<span class="gsp-t">'+esc(t.title)+'</span><span class="gsp-m">'+(w.title && why ? esc(w.title)+' · ' : '')+[/^(\d+ days? overdue|Due (today|tomorrow|in 2 days))\./.test(why||'') ? '' : due(t.due_on), 'proof: '+esc(PROOF[t.proof_type]||t.proof_type), esc(STATUS[t.status]||'')].filter(Boolean).join(' · ')+'</span>';
    var act;
    if (t.is_request && !t.accepted) {
      act = '<button type="button" class="pbtn pbtn-sm" data-gs="accept" data-t="'+t.id+'">Accept</button>'
          + '<button type="button" class="pbtn pbtn-ghost pbtn-sm" data-gs="decline" data-t="'+t.id+'">I can’t</button>';
    } else if (t.status === 'open') {
      act = '<span class="gsp-say" role="group" aria-label="How is “'+esc(t.title)+'” going?">'
          + '<button type="button" class="pbtn pbtn-ghost pbtn-sm" data-gs="report" data-s="on_track" data-w="'+w.id+'" data-t="'+t.id+'">On track</button>'
          + '<button type="button" class="pbtn pbtn-ghost pbtn-sm" data-gs="report" data-s="behind" data-w="'+w.id+'" data-t="'+t.id+'">Behind</button>'
          + '<button type="button" class="pbtn pbtn-ghost pbtn-sm" data-gs="report" data-s="blocked" data-w="'+w.id+'" data-t="'+t.id+'">Blocked</button></span>'
          + (t.proof_type === 'link'
              ? '<form class="gsp-done" novalidate data-t="'+t.id+'"><label class="sr-only" for="gsp-l'+t.id+'">The link that proves “'+esc(t.title)+'” is done</label>'
                + '<input id="gsp-l'+t.id+'" type="url" inputmode="url" placeholder="Paste the link that proves it" required>'
                + '<button type="submit" class="pbtn pbtn-sm">Mark done</button></form>'
              : '<a class="gsp-open" href="'+esc(root.querySelector('.pcard-head a').href)+'" target="_blank" rel="noopener">Finish it in Goal workspaces<span class="sr-only"> (opens in a new tab)</span></a>');
    } else act = '';
    return '<li class="gsp-task" data-t="'+t.id+'">'+head+'<div class="gsp-act">'+act+'</div></li>';
  }

  function wsHtml(w){
    var pct = Math.round((w.progress||0)*100);
    /* Said as the person would say it: where it should be by now, and where it is (CacGoalspace::behind decides). */
    var e = w.elapsed, pace;
    if (e == null || e < 0.15) pace = '<span class="gsp-pace">early days</span>';
    else if (e - (w.progress||0) >= 0.10) pace = '<span class="gsp-pace is-bad">should be about '+Math.round(e*100)+'% done by now</span>';
    else pace = '<span class="gsp-pace">on pace</span>';
    var mine = w.my_tasks.length ? '<ul class="gsp-tasks">'+w.my_tasks.map(function(t){ return taskHtml(w,t); }).join('')+'</ul>'
      : '<p class="gsp-none">Nothing of yours is open here.</p>';
    var lead = '';
    if (w.role === 'lead') {
      var others = w.tasks.filter(function(t){ return !w.my_tasks.some(function(m){ return m.id===t.id; }); });
      var people = w.members.filter(function(m){ return m.role !== 'outside'; });
      lead = '<details class="gsp-lead"><summary>You lead this goal: '+others.length+' open task'+(others.length===1?'':'s')+' in the team</summary>'
        + (others.length ? '<ul class="gsp-team">'+others.map(function(t){ return '<li>'+esc(t.title)+' <span class="gsp-m">'+esc(t.owner_name)+(t.due_on?' · '+due(t.due_on):'')+(STATUS[t.status]?' · '+esc(STATUS[t.status]):'')+'</span></li>'; }).join('')+'</ul>' : '')
        + (people.length ? '<form class="gsp-give" data-w="'+w.id+'"><h4>Give a member a task</h4>'
          + '<label>What needs doing<input name="title" maxlength="300" required></label>'
          + '<div class="gsp-fit" aria-live="polite" hidden></div>'
          + '<label>Who<select name="owner" required>'+people.map(function(m){ return '<option value="'+esc(m.actor)+'">'+esc(m.name)+'</option>'; }).join('')+'</select></label>'
          + '<label>Due<input type="date" name="due_on" required></label>'
          + '<p class="gsp-m">They prove it with a link. For a file or a sign-off, add it in Goal workspaces.</p>'
          + '<button type="submit" class="pbtn pbtn-sm">Give the task</button></form>' : '')
        + '</details>';
    }
    return '<article class="gsp-ws" aria-labelledby="gsp-w'+w.id+'"><div class="gsp-top"><h3 id="gsp-w'+w.id+'">'+esc(w.title)+'</h3>'+pace+'</div>'
      + '<div class="gsp-meter"><progress max="100" value="'+pct+'" aria-label="'+esc(w.title)+': '+pct+'% of the way"></progress>'
      + '<span class="gsp-num">'+num(w.value)+' of '+num(w.target)+' '+esc(w.unit)+(w.due_on?' by '+esc(new Date(w.due_on+'T00:00:00').toLocaleDateString(undefined,{day:'numeric',month:'short'})):'')+'</span></div>'
      + mine + lead + '</article>';
  }

  function draw(d){
    list.setAttribute('aria-busy','false');
    if (!d || !d.ok) {
      list.innerHTML = '<p class="task-add-err" role="alert">'+esc((d&&d.error)||'Your goals could not be loaded.')+' <button type="button" class="pbtn pbtn-ghost pbtn-sm" id="gspRetry">Try again</button></p>';
      return;
    }
    WS = d.workspaces || [];
    if (!d.linked) { list.innerHTML = '<p class="pc-empty">'+esc(d.why||'CACENTRE does not know your account yet.')+'</p>'; return; }
    if (!WS.length) { list.innerHTML = '<p class="pc-empty">You are not in any goal at CACENTRE yet. When a lead adds you, your part shows here.</p>'; return; }
    /* What to do next, first: ranked on the server (CacGoalspace::triage), each with its reason. */
    var byId = {}; WS.forEach(function(w){ byId[w.id] = w; });
    var top = '';
    if ((d.needs_you||[]).length) top += '<section class="gsp-now" aria-labelledby="gspNowH"><h3 id="gspNowH">Needs you now</h3><ul class="gsp-tasks">'
      + d.needs_you.map(function(x){ return taskHtml(byId[x.ws]||{id:x.ws,title:x.ws_title}, x.task, x.why); }).join('') + '</ul></section>';
    else top += '<p class="gsp-calm">Nothing of yours needs anything today. Your open tasks are below.</p>';
    if ((d.needs_help||[]).length) top += '<section class="gsp-now gsp-help" aria-labelledby="gspHelpH"><h3 id="gspHelpH">Your team needs your help</h3><ul class="gsp-team">'
      + d.needs_help.map(function(x){ return '<li><span class="gsp-why">'+esc(x.why)+'</span> <span class="gsp-t">'+esc(x.task.title)+'</span> <span class="gsp-m">'+esc(x.ws_title)+'</span></li>'; }).join('') + '</ul>'
      + '<p class="gsp-m">Checking proof and unblocking people is done in <a href="'+esc(root.querySelector('.pcard-head a').href)+'" target="_blank" rel="noopener">Goal workspaces<span class="sr-only"> (opens in a new tab)</span></a>.</p></section>';
    /* Tasks already in "Needs you now" are not repeated below. */
    var shown = {}; (d.needs_you||[]).forEach(function(x){ shown[x.task.id] = true; });
    list.innerHTML = top + WS.map(function(w){ return wsHtml(Object.assign({}, w, { my_tasks: w.my_tasks.filter(function(t){ return !shown[t.id]; }) })); }).join('');
  }
  function load(){
    list.setAttribute('aria-busy','true');
    fetch('/portal/collab.php?action=gs', { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){ if (d && d.enabled === false) { root.hidden = true; return; } root.hidden = false; draw(d); })
      .catch(function(){ root.hidden = false; draw({ ok:false, error:'You seem to be offline.' }); });
  }

  function act(btn, body, done){
    btn.disabled = true; say('');
    post(body).then(function(r){
      if (r && r.ok) { say(done); load(); }
      else { btn.disabled = false; say((r && r.error) || 'That was not done.', true); }
    });
  }
  list.addEventListener('click', function(e){
    if (e.target.closest('#gspRetry')) { load(); return; }
    var b = e.target.closest('[data-gs]'); if (!b) return;
    var t = +b.getAttribute('data-t'), w = +b.getAttribute('data-w'), what = b.getAttribute('data-gs');
    if (what === 'report') {
      var s = b.getAttribute('data-s');
      act(b, { path:'workspaces/'+w+'/reports', body:{ status:s, task_id:t } }, 'Reported: '+({on_track:'on track',behind:'behind',blocked:'blocked'}[s])+'.');
    } else if (what === 'accept') {
      act(b, { path:'tasks/'+t+'/accept', body:{} }, 'Accepted. It is yours.');
    } else if (what === 'decline') {
      var why = window.prompt('Why can’t you take it? The lead and your manager are told.'); if (!why || !why.trim()) return;
      act(b, { path:'tasks/'+t+'/decline', body:{ reason: why.trim() } }, 'Declined. The lead has been told why.');
    }
  });
  list.addEventListener('submit', function(e){
    var f = e.target; e.preventDefault();
    var btn = f.querySelector('button[type=submit]');
    if (f.classList.contains('gsp-done')) {
      var link = f.querySelector('input').value.trim();
      if (!/^https?:\/\/\S+$/i.test(link)) { say('Paste the full link, starting with https://', true); f.querySelector('input').focus(); return; }
      act(btn, { path:'tasks/'+f.getAttribute('data-t')+'/complete', body:{ proof: link } }, 'Done, with its proof.');
    } else if (f.classList.contains('gsp-give')) {
      act(btn, { path:'workspaces/'+f.getAttribute('data-w')+'/tasks', body:{ title:f.title.value.trim(), owner:f.owner.value, due_on:f.due_on.value, proof_type:'link', moves:'goal' } },
        'Given to '+f.owner.options[f.owner.selectedIndex].text+'. They are told.');
    }
  });

  /* Who fits, while a lead types the task: the portal's matcher, among this goal's members only. */
  var fitT = null;
  list.addEventListener('input', function(e){
    var f = e.target.closest('.gsp-give'); if (!f || e.target.name !== 'title') return;
    clearTimeout(fitT); var box = f.querySelector('.gsp-fit'), v = e.target.value.trim();
    if (v.length < 8) { box.hidden = true; box.innerHTML = ''; return; }
    fitT = setTimeout(function(){
      fetch('/portal/collab.php?action=gs_fit', { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/json', 'X-CSRF-Token':csrf },
        body: JSON.stringify({ ws: +f.getAttribute('data-w'), title: v }) })
        .then(function(r){ return r.json(); }).then(function(d){
          if (e.target.value.trim() !== v) return;
          var fits = (d && d.fits) || []; box.hidden = !fits.length;
          box.innerHTML = fits.length ? '<span class="gsp-m">Fits by role:</span> ' + fits.map(function(x){
            return '<button type="button" class="pbtn pbtn-ghost pbtn-sm gsp-pick" data-a="'+esc(x.actor)+'" aria-pressed="false">'+esc(x.name)+(x.why?' <span class="gsp-m">'+esc(x.why)+'</span>':'')+'</button>'; }).join('') : '';
        }).catch(function(){});
    }, 600);
  });
  list.addEventListener('click', function(e){
    var b = e.target.closest('.gsp-pick'); if (!b) return;
    var f = b.closest('.gsp-give'); f.owner.value = b.getAttribute('data-a');
    f.querySelectorAll('.gsp-pick').forEach(function(x){ x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
  });

  /* Load when the Tasks view is shown, and on the way in if it already is. */
  var view = document.getElementById('view-tasks'), loaded = false;
  function maybe(){ if (!loaded && view && !view.hidden) { loaded = true; load(); } }
  if (view) new MutationObserver(maybe).observe(view, { attributes:true, attributeFilter:['hidden'] });
  maybe();
})();
