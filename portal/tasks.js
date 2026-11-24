/* portal/tasks.js — moved verbatim from the inline script of portal/index.php v1
   (row 14 shell rebuild). Its logic is unchanged; it reads the same ids and data-* hooks. */
/* AvTaskKit — the two pieces of the Tasks page that are rules rather than
   drawing, kept outside the page's closure so tests/taskflow.test.php can run
   them in node:
     oweGroups   "Everything you owe": Overdue / Today / This week / Waiting on you
     tickQueue   a tick made on a bad connection is kept on this device and sent
                 when the connection is back — it used to be posted with no
                 .catch, so a tick on a dropped connection looked done and was lost. */
(function (g) {
  function addDays(ymd, n){ var d=new Date(ymd+'T12:00:00Z'); d.setUTCDate(d.getUTCDate()+n); return d.toISOString().slice(0,10); }
  /* items: {title, due 'YYYY-MM-DD'|'' , waiting?, …}. Waiting on you comes from the
     answer it needs, whatever its date; beyond a week, or with no date, it is not owed yet. */
  function oweGroups(items, today){
    var end=addDays(today,7), by={over:[],today:[],week:[],wait:[]};
    (items||[]).forEach(function(it){
      if(it.waiting){ by.wait.push(it); return; }
      var d=String(it.due||'').slice(0,10); if(!d) return;
      if(d<today) by.over.push(it); else if(d===today) by.today.push(it); else if(d<=end) by.week.push(it);
    });
    ['over','today','week'].forEach(function(k){ by[k].sort(function(a,b){ return String(a.due).localeCompare(String(b.due)); }); });
    return [['over','Overdue'],['today','Today'],['week','This week'],['wait','Waiting on you']]
      .filter(function(x){ return by[x[0]].length; }).map(function(x){ return {key:x[0], label:x[1], items:by[x[0]]}; });
  }
  /* The tick is a STATE ({id, done}) sent to an idempotent door (task_done), so a
     retry after a response that was lost on the way back cannot flip it again. */
  function tickQueue(storage, send, say){
    var KEY='av.task.ticks';
    function read(){ try{ return JSON.parse((storage&&storage.getItem(KEY))||'[]')||[]; }catch(e){ return []; } }
    function write(l){ try{ if(l.length) storage.setItem(KEY, JSON.stringify(l)); else storage.removeItem(KEY); }catch(e){} }
    function keep(it){ var l=read().filter(function(x){ return x.id!==it.id; }); l.push(it); write(l); }
    function drop(id){ write(read().filter(function(x){ return x.id!==id; })); }
    return {
      pending: read,
      tick: function(id, done){ var it={id:id, done:!!done};
        return send(it).then(function(r){ drop(id); return r; }, function(){
          keep(it); say('No connection — your tick is kept on this device, and we will send it when you are back online.');
          return {ok:false, queued:true, id:id, done:!!done}; }); },
      flush: function(){ var l=read(); if(!l.length) return Promise.resolve([]);
        return Promise.all(l.map(function(it){ return send(it).then(function(r){ drop(it.id); return r; }, function(){ return null; }); }))
          .then(function(rs){ var n=rs.filter(function(r){ return r && r.ok; }).length; if(n) say(n===1?'Your saved tick was sent.':'Your '+n+' saved ticks were sent.'); return rs; }); }
    };
  }
  g.AvTaskKit = { oweGroups: oweGroups, tickQueue: tickQueue, addDays: addDays };
})(typeof window !== 'undefined' ? window : globalThis);

  /* Collaboration — presence, activity, tasks (with the filter chips). */
  (function () {
    var root = document.getElementById('tasks'); if (!root) return;
    var CAC_OPEN = parseInt(root.getAttribute('data-cac-open') || '0', 10) || 0;
    var csrf = root.getAttribute('data-csrf') || '';
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function post(action, body){ return fetch('/portal/collab.php?action='+action,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body||{})}).then(function(r){ return r.json().catch(function(){ return {ok:false, error: r.status===419||r.status===403 ? 'Your session has expired. Reload the page and try again.' : 'Something went wrong on our side (error '+r.status+'). Try again.'}; }); }); }
    var listEl=document.getElementById('taskList'), actEl=document.getElementById('activityList'),
        onlineEl=document.getElementById('onlineList'), onlineCountEl=document.getElementById('onlineCount'),
        topOnline=document.getElementById('topOnline'), tbCount=document.getElementById('tbCount'),
        kpiTasks=document.getElementById('kpiTasks'), kpiOnline=document.getElementById('kpiOnline'),
        fcAll=document.getElementById('fcAll'), fcOpen=document.getElementById('fcOpen'), fcDone=document.getElementById('fcDone'),
        fcOver=document.getElementById('fcOver'), fcMine=document.getElementById('fcMine');
    var poolEl=document.getElementById('poolList'), poolCountEl=document.getElementById('poolCount'),
        aiCard=document.getElementById('taskAi'), aiGoalSel=document.getElementById('aiGoal'),
        aiMaxSel=document.getElementById('aiMax'), aiBtn=document.getElementById('aiGenerate'), aiMsgEl=document.getElementById('aiMsg'),
        poolChk=document.getElementById('taskToPool'),
        focusWrap=document.getElementById('taskFocus'), ringEl=document.getElementById('taskRing'),
        ringNEl=document.getElementById('taskRingN'), focusTxt=document.getElementById('taskFocusTxt');
    var ROSTER=[], OWE={}, oweEl=document.getElementById('oweList'), oweN=document.getElementById('oweCount');
    var CG=null, TASKS=[], POOL=[], GOALS=[], FILTER='all', SHOW_DONE=false, ALLOCATES=false, SUGGESTED={}, REASONS={}, AWAY='';
    function ymd(off){ var d=new Date(); d.setHours(0,0,0,0); d.setDate(d.getDate()+(off||0)); return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2); }
    var TODAY=ymd(0), TOMORROW=ymd(1), WEEK_END=ymd(7);
    function isOverdue(t){ return !t.done && t.due && (t.overdue || t.due < TODAY); }
    /* Waiting for your yes is not yet your work: not counted until accepted (Collab::acceptTask). */
    function counts(){ var open=TASKS.filter(function(t){return !t.done && !t.waiting;}).length, done=TASKS.filter(function(t){return t.done;}).length,
        over=TASKS.filter(isOverdue).length, mine=TASKS.filter(function(t){return t.mine && !t.done;}).length;
      if(fcAll)fcAll.textContent=TASKS.length; if(fcOpen)fcOpen.textContent=open; if(fcDone)fcDone.textContent=done;
      if(fcOver)fcOver.textContent=over; if(fcMine)fcMine.textContent=mine;
      // The chip counts the member's work, not this site's half of it.
      if(kpiTasks)kpiTasks.textContent=open+CAC_OPEN; }
    // Relative, urgency-aware due label + tone.
    function dueMeta(t){ if(!t.due) return null;
      var tone = isOverdue(t) ? 'over' : (t.due===TODAY ? 'today' : (t.due===TOMORROW ? 'soon' : ''));
      var label; if(isOverdue(t)) label='Overdue'; else if(t.due===TODAY) label='Today'; else if(t.due===TOMORROW) label='Tomorrow';
      else { var d=new Date(t.due+'T00:00:00'); label=isNaN(d)?t.due:d.toLocaleDateString(undefined,(t.due<ymd(365)?{weekday:'short',month:'short',day:'numeric'}:{month:'short',day:'numeric',year:'numeric'})); }
      return {label:label, tone:tone}; }
    function priTag(t){ if(t.priority==='high') return '<span class="task-flag task-flag--high" title="High priority">High</span>';
      if(t.priority==='low') return '<span class="task-flag task-flag--low" title="Low priority">Low</span>'; return ''; }
    // Which urgency bucket an open task belongs in.
    function bucketOf(t){ if(t.done) return 'done'; if(!t.due) return 'nodate';
      if(t.due<TODAY) return 'over'; if(t.due===TODAY) return 'today'; if(t.due===TOMORROW) return 'tom';
      if(t.due<=WEEK_END) return 'week'; return 'later'; }
    var BUCKETS=[['over','Overdue'],['today','Today'],['tom','Tomorrow'],['week','This week'],['later','Later'],['nodate','No date']];
    function taskHtml(t){
      var who = t.assigned_out ? ('→ '+esc(t.assignee_name)) : (t.mine ? '' : ('from '+esc(t.creator_name)));
      var dm = (t.due&&!t.done) ? dueMeta(t) : null;
      var due = dm ? '<span class="task-due'+(dm.tone?' is-'+dm.tone:'')+'">'+esc(dm.label)+'</span>' : '';
      var goal = t.goal_title ? ' <span class="task-goal" title="Advances a goal">◎ '+esc(t.goal_title)+'</span>' : '';
      var cw = (typeof ATTACHED!=='undefined' && ATTACHED[t.id] && WEEK && WEEK.commitments) ? (WEEK.commitments.filter(function(c){return c.id===ATTACHED[t.id];})[0]||null) : null;
      if(cw) goal += ' <span class="task-goal task-goal--cac">Counts toward: '+esc(cw.statement)+'</span>';
      var rel = (t.mine && !t.done) ? '<button type="button" class="task-release" title="Return to the pool" aria-label="Return to pool">↩</button>' : '';
      /* Where it is and how big (lib/Collab.php setState/setSize): a step that
         is blocked says on what, and work that has sat too long says so. */
      var st = t.state || (t.done ? 'done' : 'todo'), ctl = '', note = '';
      if(!t.done){
        ctl = '<select class="task-state-sel is-'+esc(st)+'" aria-label="Where this task is: '+esc(t.title)+'">'
          + [['todo','To do'],['doing','Doing'],['blocked','Blocked…'],['done','Done']].map(function(o){ return '<option value="'+o[0]+'"'+(o[0]===st?' selected':'')+'>'+o[1]+'</option>'; }).join('')+'</select>'
          + '<select class="task-size-sel" aria-label="Size of this task" title="Size: S an hour or two, M a day, L several days">'
          + [['','Size'],['S','S'],['M','M'],['L','L']].map(function(o){ return '<option value="'+o[0]+'"'+(o[0]===(t.size||'')?' selected':'')+'>'+o[1]+'</option>'; }).join('')+'</select>';
        if(st==='blocked' && t.blocked_reason) note += '<span class="task-blocked">Blocked: '+esc(t.blocked_reason)+'</span>';
        var sk = t.stuck || {};
        if(sk.flag) note += '<span class="task-stuck">'+({blocked:'Blocked',stalled:'In progress',ageing:'Untouched'}[sk.flag]||'Waiting')+' '+(+sk.days||0)+' days</span>';
      }
      if(t.outcome) note += '<span class="task-outcome-line">Done when: '+esc(t.outcome)+'</span>';
      if(t.moved && !t.done) note += '<span class="task-moved">Agreed for '+esc(t.agreed_due)+'</span>';
      if(t.review_state==='sent_back' && !t.done && t.review_note) note += '<span class="task-blocked">Sent back: '+esc(t.review_note)+'</span>';
      var ask = flowHtml(t);
      return '<li class="task task--pri-'+esc(t.priority||'normal')+(t.done?' is-done':'')+(t.waiting?' is-waiting':'')+' task--'+esc(st)+'" data-id="'+t.id+'">'
      +'<button type="button" class="task-check" aria-label="Toggle done">'+(t.done?'✓':'')+'</button>'
      +'<span class="task-title">'+esc(t.title)+(who?' <span class="task-who">'+who+'</span>':'')+goal+(note?'<span class="task-notes">'+note+'</span>':'')+'</span>'
      +ctl+priTag(t)+due+rel+(t.mine&&!t.done?'<button type="button" class="task-move" aria-label="Move the date: '+esc(t.title)+'">Move date</button>':'')
      +'<button type="button" class="task-del" aria-label="Delete task">✕</button>'+ask+'</li>'; }
    /* What a task asks of the person looking at it, as buttons on the row:
       given to you → Accept · Propose a date · Can't, because…;
       a date proposed to you → Agree · Keep mine; finished for you → Thanks · Send back;
       given by you and three days late → Reassign · New date · Drop. */
    function flowHtml(t){
      var lab=' <span class="sr-only">: '+esc(t.title)+'</span>';
      if(t.waiting && t.accept_state==='counter') return '<div class="task-ask" role="group" aria-label="Your proposed date"><span class="task-ask-q">You proposed '+esc(t.proposed_due)+' — waiting for '+esc(t.assigned_by_name)+'.</span></div>';
      if(t.waiting) return '<div class="task-ask" role="group" aria-label="Answer this task"><span class="task-ask-q">'+(t.accept_state==='proposed'?'Suggested for you by the planner.':esc(t.assigned_by_name)+' asks you to take this on.')+'</span>'
        +'<button type="button" class="pbtn pbtn-sm pbtn-gold" data-flow="accept">Accept'+lab+'</button>'
        +'<button type="button" class="pbtn pbtn-sm" data-flow="propose">Propose a date'+lab+'</button>'
        +'<button type="button" class="pbtn pbtn-sm pbtn-ghost" data-flow="decline">Can’t, because…'+lab+'</button></div>';
      if(t.needs_me==='date') return '<div class="task-ask" role="group" aria-label="A new date to answer"><span class="task-ask-q">'+esc(t.assignee_name)+' can do it by <b>'+esc(t.proposed_due)+'</b>'+(t.proposed_why?': '+esc(t.proposed_why):'')+'.</span>'
        +'<button type="button" class="pbtn pbtn-sm pbtn-gold" data-flow="agree">Agree'+lab+'</button><button type="button" class="pbtn pbtn-sm" data-flow="keep">Keep '+esc(t.due||'my date')+lab+'</button></div>';
      if(t.needs_me==='review') return '<div class="task-ask" role="group" aria-label="Finished for you"><span class="task-ask-q">'+esc(t.assignee_name)+' finished this.</span>'
        +'<button type="button" class="pbtn pbtn-sm pbtn-gold" data-flow="thanks">Thanks'+lab+'</button><button type="button" class="pbtn pbtn-sm" data-flow="sendback">Send back'+lab+'</button></div>';
      if(t.i_gave && !t.done && t.late_days>=3) return '<div class="task-ask task-ask--late" role="group" aria-label="Three days late"><span class="task-ask-q">'+t.late_days+' days late with '+esc(t.assignee_name)+'.</span>'
        +'<button type="button" class="pbtn pbtn-sm" data-flow="reassign">Reassign'+lab+'</button><button type="button" class="pbtn pbtn-sm" data-flow="redate">New date'+lab+'</button><button type="button" class="pbtn pbtn-sm pbtn-ghost" data-flow="drop">Drop'+lab+'</button></div>';
      return '';
    }
    function poolHtml(t){
      var dm = t.due ? dueMeta(t) : null;
      var due = dm ? '<span class="task-due'+(dm.tone?' is-'+dm.tone:'')+'">'+esc(dm.label)+'</span>' : '';
      var goal = t.goal_title ? '<span class="task-goal" title="Advances a goal">◎ '+esc(t.goal_title)+'</span>' : '';
      var by = (t.creator_name && t.creator_name!=='You') ? '<span class="task-who">by '+esc(t.creator_name)+'</span>' : '';
      return '<li class="task task-pool-item task--pri-'+esc(t.priority||'normal')+'" data-id="'+t.id+'">'
      +'<span class="task-body"><span class="task-title">'+esc(t.title)+'</span>'
      +'<span class="task-sub">'+priTag(t)+by+goal+due+'</span>'
      +(t.suggest&&ALLOCATES?'<span class="task-fit">Fits <b>'+esc(t.suggest.name)+'</b> — '+esc(t.suggest.why)+'</span>':'')+'</span>'
      +(t.suggest&&ALLOCATES?'<button type="button" class="pbtn task-give" data-to="'+(+t.suggest.id)+'">Assign to '+esc(t.suggest.name.split(' ')[0])+'<span class="sr-only">: '+esc(t.title)+'</span></button>':'')
      +'<button type="button" class="pbtn pbtn-gold task-claim">Claim<span class="sr-only">: '+esc(t.title)+'</span></button></li>'; }
    function renderPool(){
      if(!poolEl) return;
      poolEl.innerHTML = POOL.length ? POOL.map(poolHtml).join('') : '<li class="pc-empty task-empty">The pool is empty — nice work. Add an open task or plan a goal with AI.</li>';
      if(poolCountEl) poolCountEl.textContent = POOL.length + ' open';
    }
    function fillGoals(){
      if(!aiGoalSel) return;
      if(!GOALS.length){ aiGoalSel.innerHTML='<option value="">No open goals yet</option>'; if(aiBtn)aiBtn.disabled=true; cgNote(); return; }
      aiGoalSel.innerHTML = GOALS.map(function(g){ return '<option value="'+g.id+'">'+esc(g.title)+'</option>'; }).join('');
      if(aiBtn)aiBtn.disabled=false;
    }
    /* Why the list is empty: what CACENTRE last answered, in a sentence; and,
       for an administrator, reading it now instead of waiting for the hour. */
    function cgNote(){
      if(!aiMsgEl || !CG) return;
      var L=CG.last, when=L&&L.at ? new Date(L.at.replace(' ','T')+'Z').toLocaleString(undefined,{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}) : '';
      var t = !CG.ready ? 'CACENTRE is not linked to this site, so its goals cannot come across. Add one in Suite → Goals.'
        : !L ? 'CACENTRE’s goals have not been read yet — they come across within the hour.'
        : !L.ok ? 'CACENTRE could not be read'+(when?' ('+when+')':'')+'. '+(CG.can_sync?'Reason: '+L.error+'. ':'')+'It is tried again every ten minutes.'
        : L.goals===0 ? 'CACENTRE has no open goals'+(when?' (read '+when+')':'')+'. Add one there, or here in Suite → Goals.'
        : 'CACENTRE has '+L.goals+' open goal'+(L.goals===1?'':'s')+(when?' (read '+when+')':'')+', and they are coming across — refresh in a minute.';
      aiMsgEl.hidden=false; aiMsgEl.className='task-ai-msg';
      aiMsgEl.innerHTML=esc(t)+(CG.can_sync && CG.ready ? ' <button type="button" class="pbtn pbtn-sm" id="cgSync">Read CACENTRE goals now</button>' : '');
      var b=document.getElementById('cgSync');
      if(b) b.addEventListener('click',function(){ b.disabled=true; b.textContent='Reading…';
        post('cac_goals_sync',{}).then(function(d){ if(!d||!d.ok){ aiSay((d&&d.error)||'Could not read CACENTRE.','bad'); return; }
          CG=d.cac_goals; GOALS=d.goals||[]; fillGoals(); if(GOALS.length) aiSay(GOALS.length+' goal'+(GOALS.length===1?'':'s')+' from CACENTRE and here.','ok'); load(); })
        .catch(function(){ b.disabled=false; b.textContent='Read CACENTRE goals now'; }); });
    }
    function aiSay(msg,tone){ if(!aiMsgEl) return; aiMsgEl.hidden=!msg; aiMsgEl.textContent=msg||''; aiMsgEl.className='task-ai-msg'+(tone?(' is-'+tone):''); }
    function fillRoster(roster){ var sel=document.getElementById('taskAssignee'); if(!sel||!roster) return;
      var cur=sel.value; sel.innerHTML='<option value="0">Me</option>'+roster.map(function(m){ return '<option value="'+m.id+'">'+esc(m.name)+(m.labels?' — '+esc(m.labels):'')+'</option>'; }).join(''); sel.value=cur; }
    function groupHtml(label,n,extra){ return '<li class="task-group'+(extra||'')+'"><span class="task-group-label">'+esc(label)+'</span><span class="task-group-n">'+n+'</span></li>'; }
    function render(){
      counts(); renderFocus();
      // Flat lenses: overdue / mine / done render as a simple filtered list.
      if(FILTER==='overdue'||FILTER==='mine'||FILTER==='done'){
        var rows=TASKS.filter(function(t){ if(FILTER==='done')return t.done; if(FILTER==='overdue')return isOverdue(t); return t.mine && !t.done; });
        listEl.innerHTML = rows.length ? rows.map(taskHtml).join('') : '<li class="pc-empty task-empty">Nothing here.</li>';
        return;
      }
      // Default: group open tasks by urgency; completed folds into a collapsible tail.
      var open=TASKS.filter(function(t){return !t.done && !t.waiting && t.needs_me!=='date';}), done=TASKS.filter(function(t){return t.done;}),
          ask=TASKS.filter(function(t){ return t.waiting || t.needs_me; });
      if(!open.length && !ask.length && !(FILTER==='all'&&done.length)){
        listEl.innerHTML='<li class="pc-empty task-empty">✳ Inbox zero. Nothing on your plate — grab one from the pool or plan a goal.</li>'; return; }
      var byB={}; open.forEach(function(t){ var b=bucketOf(t); (byB[b]=byB[b]||[]).push(t); });
      /* What waits on your answer comes first: until you answer it, it is nobody's. */
      var html=ask.length ? groupHtml('Waiting on you',ask.length,' is-today')+ask.map(taskHtml).join('') : '';
      BUCKETS.forEach(function(bk){ var arr=byB[bk[0]]; if(!arr||!arr.length) return;
        html+=groupHtml(bk[1],arr.length,(bk[0]==='over'?' is-over':(bk[0]==='today'?' is-today':'')))+arr.map(taskHtml).join(''); });
      if(FILTER==='all'&&done.length){
        html+='<li class="task-group task-group--done" id="doneToggle">'+
          '<span class="task-group-label">Completed <span class="task-caret">'+(SHOW_DONE?'▾':'▸')+'</span></span>'+
          '<span class="task-group-n">'+done.length+'</span></li>';
        if(SHOW_DONE) html+=done.map(taskHtml).join('');
      }
      listEl.innerHTML=html;
    }
    function renderFocus(){
      if(!focusWrap) return;
      // Today's focus = everything due today or already overdue (the actionable set).
      var set=TASKS.filter(function(t){ return t.due && t.due<=TODAY; });
      var total=set.length, doneN=set.filter(function(t){return t.done;}).length, left=total-doneN;
      if(!total){ focusWrap.hidden=true; if(focusTxt)focusTxt.textContent = TASKS.filter(function(t){return !t.done;}).length ? 'Nothing due today — you’re ahead.' : 'All clear. Add a task to get going.'; return; }
      focusWrap.hidden=false;
      var pct=Math.round(100*doneN/total);
      if(ringEl) ringEl.style.setProperty('--pct', pct);
      if(ringEl) ringEl.classList.toggle('is-complete', left===0);
      if(ringNEl) ringNEl.textContent = left===0 ? '✓' : left;
      if(focusTxt) focusTxt.textContent = left===0 ? ('Today’s done — '+doneN+' cleared. 🎉') : (left+' of '+total+' left today · '+pct+'% done');
    }
    function renderOnline(users,count){ if(onlineCountEl)onlineCountEl.textContent=count||0; if(tbCount)tbCount.textContent=count||0; if(kpiOnline)kpiOnline.textContent=count||0; if(topOnline)topOnline.hidden=!(count>0);
      if(!onlineEl) return;   /* not on every page that loads this file */
      users=users||[]; onlineEl.innerHTML = users.length ? users.map(function(u){ return '<div class="online-row"><span class="online-ava is-'+esc(u.status)+'">'+esc(u.initials)+'</span><span class="online-name">'+esc(u.name)+'</span></div>'; }).join('') : '<p class="pc-empty">Just you so far.</p>'; }
    function renderActivity(items){ if(!actEl) return; items=items||[]; actEl.innerHTML = items.length ? items.map(function(a){ var obj=a.object?' <b>'+esc(a.object)+'</b>':''; var inner='<span class="act-ava" aria-hidden="true">'+esc(a.initials)+'</span><span class="act-body"><span class="act-line"><b>'+esc(a.actor)+'</b> '+esc(a.verb)+obj+'</span><span class="act-ago">'+esc(a.ago)+'</span></span>'; return '<li class="act">'+(a.url?'<a href="'+esc(a.url)+'">'+inner+'</a>':inner)+'</li>'; }).join('') : '<li class="pc-empty">No activity yet.</li>'; }

    /* Everything you owe: what the page has already loaded, from every card
       (the commitments, Goalspace and the CACENTRE week hand theirs over as
       'av:owe'; the console's tasks are in the page), in one list by when. */
    document.addEventListener('av:owe', function(e){ var d=e.detail||{}; if(d.source){ OWE[d.source]=d.items||[]; renderOwe(); } });
    function consoleOwe(){ var out=[]; document.querySelectorAll('#cacTasks li[data-cac-task]').forEach(function(li){
        var t=li.querySelector('.task-title'), d=li.querySelector('[data-cac-due]'); out.push({title:t?t.textContent:'', due:d?d.value:'', src:'CACENTRE'}); }); return out; }
    function renderOwe(){
      if(!oweEl) return;
      OWE.console = consoleOwe();
      OWE.week = (WEEK&&WEEK.ok&&WEEK.commitments||[]).filter(function(c){ return c.status==='open'; }).map(function(c){ return {title:c.statement, due:c.end, src:'CACENTRE week'}; });
      var all=[]; Object.keys(OWE).forEach(function(k){ all=all.concat(OWE[k]||[]); });
      var groups=window.AvTaskKit.oweGroups(all, TODAY), n=groups.reduce(function(a,g){ return a+g.items.length; },0);
      if(oweN) oweN.textContent = n ? n+' to do' : 'All clear';
      oweEl.innerHTML = n ? groups.map(function(g){ return '<h3 class="owe-h'+(g.key==='over'?' is-over':'')+'">'+esc(g.label)+' <span class="owe-n">'+g.items.length+'</span></h3><ul class="owe-list">'
          + g.items.map(function(it){ return '<li><span class="owe-t">'+esc(it.title)+'</span><span class="owe-m">'+esc(it.src||'')+(it.due?' · '+esc(it.due):'')+'</span></li>'; }).join('')+'</ul>'; }).join('')
        : '<p class="pc-empty">Nothing owed this week. Your later work is in My tasks below.</p>';
    }
    var TICKS = window.AvTaskKit.tickQueue((function(){ try{ return window.localStorage; }catch(e){ return null; } })(),
      function(it){ return fetch('/portal/collab.php?action=task_done',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(it)})
        .then(function(r){ return r.json().catch(function(){ return {ok:false, error:'Something went wrong on our side (error '+r.status+'). Try again.'}; }); }); },
      function(msg){ say(msg); var el=document.getElementById('taskTickMsg'); if(!el&&listEl){ el=document.createElement('p'); el.id='taskTickMsg'; el.className='task-ai-msg'; el.setAttribute('role','status'); listEl.parentNode.insertBefore(el,listEl); } if(el){ el.hidden=false; el.textContent=msg; } });
    window.addEventListener('online', function(){ TICKS.flush().then(function(){ load(); }); });
    setInterval(function(){ if(TICKS.pending().length) TICKS.flush(); }, 30000);

    /* Today's report: done, undone, unclaimed — for whoever hands out work. */
    var TR = null, TR_TAB = 'done', trEl = document.getElementById('taskReport');
    function trLoad(){ if(!trEl) return; if(!ALLOCATES){ trEl.hidden = true; return; }
      fetch('/portal/collab.php?action=task_report',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){ if(!d||!d.ok) return; TR=d.report; trEl.hidden=false; trDraw(); }).catch(function(){}); }
    function trDraw(){
      if(!TR) return; var c=TR.counts;
      document.getElementById('trHead').textContent = 'Done '+c.done+' · Undone '+c.undone+(c.overdue?' ('+c.overdue+' overdue)':'')+' · Unclaimed '+c.unclaimed;
      document.getElementById('trDoneN').textContent=c.done; document.getElementById('trUndoneN').textContent=c.undone; document.getElementById('trUnclaimedN').textContent=c.unclaimed;
      trEl.querySelectorAll('[data-tr]').forEach(function(b){ var on=b.getAttribute('data-tr')===TR_TAB; b.classList.toggle('is-on',on); b.setAttribute('aria-selected',on?'true':'false'); });
      var li=function(main, meta, cls){ return '<li class="tr-item'+(cls?' '+cls:'')+'"><span class="tr-t">'+main+'</span><span class="tr-m">'+meta+'</span></li>'; }, rows='';
      if(TR_TAB==='done') rows=TR.done.map(function(x){ return li(esc(x.title), esc(x.by)+(x.late?' · <em>late</em>':'')+(x.goal?' · ◎ '+esc(x.goal):'')); }).join('') || '<li class="pc-empty">Nothing finished yet today.</li>';
      else if(TR_TAB==='undone') rows=TR.undone.map(function(x){ return li(esc(x.title), esc(x.who)+(x.late_days>0?' · <b class="is-bad">'+x.late_days+' day'+(x.late_days===1?'':'s')+' overdue</b>':(x.due_today?' · due today':(x.due?' · due '+esc(x.due):'')))+(x.blocked?' · blocked: '+esc(x.blocked):''), x.late_days>0?'is-late':''); }).join('') || '<li class="pc-empty">Nothing held and open.</li>';
      else if(TR_TAB==='unclaimed') rows=TR.unclaimed.map(function(x){ return li(esc(x.title), 'in the pool '+(x.age_days===0?'since today':x.age_days+' day'+(x.age_days===1?'':'s'))+(x.priority==='high'?' · <b>high priority</b>':'')+(x.due?' · due '+esc(x.due):'')); }).join('') || '<li class="pc-empty">The pool is empty.</li>';
      else rows=TR.people.map(function(p){ return li(esc(p.name), p.done+' done · '+p.open+' open'+(p.overdue?' · <b class="is-bad">'+p.overdue+' overdue</b>':'')); }).join('') || '<li class="pc-empty">Nobody holds a task.</li>';
      document.getElementById('trList').innerHTML = rows;
    }
    if(trEl) trEl.addEventListener('click', function(e){ var b=e.target.closest('[data-tr]'); if(!b) return; TR_TAB=b.getAttribute('data-tr'); trDraw(); });

    /* A task taken or ticked elsewhere on the page (the to-do pop-up) shows here at once. */
    document.addEventListener('av:tasks-changed', function(){ setTimeout(load, 200); });
    function load(){ fetch('/portal/collab.php?action=bootstrap',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){ if(!d||!d.ok){ loadErr((d&&d.error)||'Your tasks could not be loaded.'); return; }
        ROSTER=d.roster||[]; TASKS=d.tasks||[]; GOALS=d.goals||[]; ALLOCATES=!!d.allocates; CG=d.cac_goals||null; REASONS=d.handback_reasons||{}; AWAY=d.away_until||''; drawAway();
        POOL=(d.pool||[]).map(function(t){ return SUGGESTED[t.id]?Object.assign({},t,{suggest:SUGGESTED[t.id]}):t; });
        var aw=document.getElementById('aiAssignWrap'); if(aw) aw.hidden=!ALLOCATES;
        /* Posting to the pool is for organisation members (task_add_pool); don't offer what will be refused. */
        var pw=document.getElementById('taskToPoolWrap'); if(pw) pw.hidden=!d.org;
        /* Each part on its own: one that fails no longer stops the rest. The
           activity panel left this page in the redesign, and drawing it threw;
           everything after it then never ran — the Assign list stayed empty
           (only "Me"), the planner had no goals and stayed hidden, and the
           day's report never loaded. The error was swallowed, so nobody saw why. */
        [function(){ render(); }, function(){ renderPool(); }, function(){ renderActivity(d.activity); }, function(){ renderOnline(d.online,d.count); },
         function(){ fillRoster(d.roster); }, function(){ fillGoals(); }, function(){ trLoad(); },
         function(){ if(aiCard) aiCard.hidden = !d.ai; },  // only show the AI planner when Claude is wired up
         function(){ OWE.tasks = TASKS.filter(function(t){ return (t.mine||t.waiting) && !t.done || t.needs_me; }).map(function(t){
             return {title:t.title, due:t.due, waiting:!!(t.waiting||t.needs_me), src: t.needs_me==='date'?'Agree a date':(t.needs_me==='review'?'Finished for you':(t.waiting?'Accept or decline':'Task'))}; }); renderOwe(); }
        ].forEach(function(step){ try{ step(); }catch(e){ if(window.console) console.error('[tasks]', e); } });
        loadErr('');
      }).catch(function(){ loadErr('Your tasks could not be loaded. Check your connection.'); }); }
    /* Loading, loaded, or an honest error with a way to try again. */
    function loadErr(msg){ var el=document.getElementById('taskLoadErr');
      if(!el && msg && listEl){ el=document.createElement('p'); el.id='taskLoadErr'; el.className='task-add-err'; el.setAttribute('role','alert'); listEl.parentNode.insertBefore(el, listEl); }
      if(!el) return; el.hidden=!msg; el.innerHTML = msg ? esc(msg)+' <button type="button" class="pbtn pbtn-ghost" id="taskRetry">Try again</button>' : '';
      var r=document.getElementById('taskRetry'); if(r) r.onclick=function(){ loadErr(''); load(); }; }

    // filter chips
    document.querySelectorAll('#taskFilters .pseg-btn').forEach(function(b){ b.addEventListener('click', function(){ document.querySelectorAll('#taskFilters .pseg-btn').forEach(function(x){x.classList.remove('is-on'); x.setAttribute('aria-pressed','false');}); b.classList.add('is-on'); b.setAttribute('aria-pressed','true'); FILTER=b.getAttribute('data-filter'); render(); }); });
    // add
    var form=document.getElementById('taskAdd'), input=document.getElementById('taskInput');
    form.addEventListener('submit', function(e){ e.preventDefault(); var title=(input.value||'').trim(); if(!title) return;
      var asel=document.getElementById('taskAssignee'); var assignee=asel?(+asel.value||0):0;
      var dsel=document.getElementById('taskDue'); var due=dsel?dsel.value:'';
      var psel=document.getElementById('taskPriority'); var priority=psel?psel.value:'normal';
      var toPool = !!(poolChk && poolChk.checked);
      var ssel=document.getElementById('taskSize'), size=ssel?ssel.value:'';
      var osel=document.getElementById('taskOutcome'), outcome=osel?(osel.value||'').trim():'';
      if(askOutcome(assignee, size, outcome)) return;
      /* The title stays in the box until the task exists. A refusal (a rule,
         the rate limit, a dropped connection) used to clear it and say nothing:
         the task simply vanished. */
      addErr(''); input.disabled=true;
      var done=function(d, ok){
        input.disabled=false;
        if(!ok){ addErr((d&&d.error)||'The task was not added. Check your connection and try again.'); input.focus(); return; }
        input.value=''; input.focus(); if(osel)osel.value=''; if(ssel)ssel.value=''; ASKED=false; outcomeHint(''); if(asel)asel.value='0'; if(dsel)dsel.value=''; if(psel)psel.value='normal'; if(poolChk)poolChk.checked=false;
        addErr(''); say(toPool?'Posted to the pool.':(assignee?'Task given to '+((d.task&&d.task.assignee_name)||'them')+'.':'Task added.'));
      };
      post(toPool?'task_add_pool':'task_add', toPool?{title:title, due:due, priority:priority}:{title:title, assignee:assignee, due:due, priority:priority, size:size, outcome:outcome})
        .then(function(d){ var ok=!!(d&&d.ok&&d.task); if(ok){ if(toPool){ POOL.unshift(d.task); renderPool(); } else { TASKS.unshift(d.task); render(); } } done(d, ok); })
        .catch(function(){ done(null, false); });
    });
    /* Given to a person, or posted to the pool: one or the other, never both
       (the pool used to win silently). */
    var aselEl=document.getElementById('taskAssignee');
    if(aselEl&&poolChk){ aselEl.addEventListener('change', function(){ if(+aselEl.value) poolChk.checked=false; });
      poolChk.addEventListener('change', function(){ if(poolChk.checked) aselEl.value='0'; }); }
    /* "Done when" is asked for — once, never forced — when the task goes to somebody
       else or is sized L: the two cases where "finished" is most often read two ways.
       A second press of Add sends it as it is. */
    var ASKED=false;
    function outcomeHint(msg){ var h=document.getElementById('taskOutcomeHint'); if(h){ h.textContent=msg; h.hidden=!msg; } }
    function askOutcome(assignee, size, outcome){
      if(outcome || ASKED || !(assignee || size==='L')) return false;
      ASKED=true; outcomeHint((assignee?'They will read this to know when it is finished':'A big task')+' — add a "done when" line? Press Add again to send it without one.');
      var o=document.getElementById('taskOutcome'); if(o) o.focus(); return true;
    }
    function addErr(msg){ var el=document.getElementById('taskAddErr'); if(!el) return; el.textContent=msg; el.hidden=!msg; input.setAttribute('aria-invalid', msg?'true':'false'); }
    var liveEl=null; function say(msg){ if(!liveEl){ liveEl=document.createElement('p'); liveEl.className='av-sr'; liveEl.setAttribute('role','status'); liveEl.setAttribute('aria-live','polite'); form.appendChild(liveEl); } liveEl.textContent=''; setTimeout(function(){ liveEl.textContent=msg; }, 30); }
    // Who it fits, while the title is typed (people who hand out work only)
    var sugEl=document.getElementById('taskSuggest'), sugT=null, sugFor='';
    function showSuggest(list, via){
      if(!sugEl) return;
      if(!list||!list.length){ sugEl.hidden=true; sugEl.innerHTML=''; return; }
      sugEl.hidden=false;
      sugEl.innerHTML='<span class="task-suggest-h">'+(via==='ai'?'AI suggests':'Fits by role')+'</span>'+list.map(function(x){
        return '<button type="button" class="task-suggest-pick" data-id="'+(+x.id)+'" title="'+esc((x.why||[]).join(' · '))+'">'+esc(x.name)
          +'<span class="task-suggest-why">'+esc((x.why&&x.why[0])||x.summary||'')+'</span></button>'; }).join('');
    }
    if(input&&sugEl) input.addEventListener('input', function(){
      clearTimeout(sugT); var v=(input.value||'').trim();
      if(!ALLOCATES||v.length<8){ showSuggest([]); return; }
      sugT=setTimeout(function(){ if(v===sugFor) return; sugFor=v;
        var dEl=document.getElementById('taskDue'), pEl=document.getElementById('taskPriority');
        post('task_suggest',{title:v,due:dEl?dEl.value:'',priority:pEl?pEl.value:''}).then(function(d){ if((input.value||'').trim()!==v) return; showSuggest(d&&d.ok?d.suggestions:[], d&&d.via); }).catch(function(){}); }, 600); });
    if(sugEl) sugEl.addEventListener('click', function(e){ var b=e.target.closest('.task-suggest-pick'); if(!b) return;
      var sel=document.getElementById('taskAssignee'); if(!sel) return; var id=b.getAttribute('data-id');
      if(!Array.prototype.some.call(sel.options,function(o){return o.value===id;})){ var o=document.createElement('option'); o.value=id; o.textContent=b.firstChild.textContent; sel.appendChild(o); }
      sel.value=id; if(poolChk) poolChk.checked=false;
      sugEl.querySelectorAll('.task-suggest-pick').forEach(function(x){ x.setAttribute('aria-pressed', x===b?'true':'false'); });
      sel.focus(); });
    form.addEventListener('submit', function(){ showSuggest([]); sugFor=''; });

    // toggle / delete / release
    listEl.addEventListener('click', function(e){
      if(e.target.closest('#doneToggle')){ SHOW_DONE=!SHOW_DONE; render(); return; }
      var li=e.target.closest('.task'); if(!li) return; var id=+li.getAttribute('data-id');
      if(e.target.closest('.task-check')){
        /* Shown at once, sent as a state; on a dropped connection it is kept and
           sent later (TICKS), and the person is told — never silently lost. */
        var was=TASKS.filter(function(t){return t.id===id;})[0], want=!(was&&was.done);
        var show=function(v){ TASKS=TASKS.map(function(t){return t.id===id?Object.assign({},t,{done:v,state:v?'done':(t.started_at?'doing':'todo'),stuck:{flag:'',days:0}}):t;}); render(); };
        show(want);
        TICKS.tick(id, want).then(function(d){ if(d&&d.ok){ show(d.done); if(ATTACHED[id]) loadWeek(); } else if(d&&!d.queued){ show(!want); alert((d&&d.error)||'That tick did not save.'); } }); }
      else if(e.target.closest('[data-flow]')){ flow(li, id, e.target.closest('[data-flow]').getAttribute('data-flow')); }
      else if(e.target.closest('.task-move')){ var nd=prompt('New date (YYYY-MM-DD):'); if(!nd) return; var why=prompt('Why does it need a new date? Whoever gave it to you is told.')||'';
        post('task_update',{id:id, due:nd.trim(), reason:why}).then(function(d){ if(d&&d.ok&&d.task){ TASKS=TASKS.map(function(t){return t.id===id?d.task:t;}); render(); } else alert((d&&d.error)||'The date was not changed.'); }); }
      else if(e.target.closest('.task-release')){ askWhy(li, id); }
      else if(e.target.closest('.task-why-pick')){ var b=e.target.closest('.task-why-pick'); release(id, b.getAttribute('data-reason')||''); }
      else if(e.target.closest('.task-why-cancel')){ var w=li.querySelector('.task-why'); if(w) w.remove(); }
      else if(e.target.closest('.task-del')){ post('task_delete',{id:id}).then(function(d){ if(d&&d.ok){ TASKS=TASKS.filter(function(t){return t.id!==id;}); render(); } }); } });

    function flow(li, id, what){
      var t=TASKS.filter(function(x){return x.id===id;})[0]||{}, body={id:id}, act='';
      var put=function(d){ if(d&&d.ok){ if(d.task && (d.task.mine||d.task.waiting||d.task.i_gave||d.task.creator_id===t.creator_id)) TASKS=TASKS.map(function(x){return x.id===id?d.task:x;}); else TASKS=TASKS.filter(function(x){return x.id!==id;}); render(); load(); } else alert((d&&d.error)||'That did not work.'); };
      if(what==='accept') act='task_accept';
      else if(what==='propose'){ var dt=prompt('The date you can do it by (YYYY-MM-DD):'); if(!dt) return; body.date=dt.trim(); body.why=prompt('Anything to add? (optional)')||''; act='task_propose'; }
      else if(what==='decline'){ var old=li.querySelector('.task-why'); if(old){ old.remove(); return; }
        var keys=Object.keys(REASONS), w=document.createElement('div'); w.className='task-why'; w.setAttribute('role','group'); w.setAttribute('aria-label','Why can’t you take it on?');
        w.innerHTML='<span class="task-why-q">Can’t, because…</span>'+keys.map(function(k){ return '<button type="button" class="task-why-pick" data-decline="'+esc(k)+'">'+esc(REASONS[k])+'</button>'; }).join('')+'<button type="button" class="task-why-cancel">Keep it</button>';
        li.appendChild(w); w.addEventListener('click', function(ev){ var b=ev.target.closest('[data-decline]'); if(!b) return; ev.stopPropagation();
          post('task_decline',{id:id, reason:b.getAttribute('data-decline'), note:prompt('Anything they should know? (optional)')||''}).then(put); });
        var f=w.querySelector('button'); if(f) f.focus(); return; }
      else if(what==='agree'||what==='keep'){ body.agree = what==='agree'; act='task_answer'; }
      else if(what==='thanks') act='task_thanks';
      else if(what==='sendback'){ var r=prompt('What is missing? '+(t.assignee_name||'They')+' is told.'); if(!r||!r.trim()) return; body.reason=r; act='task_sendback'; }
      else if(what==='redate'){ var nd=prompt('New date (YYYY-MM-DD):'); if(!nd) return; body.due=nd.trim(); act='task_update'; }
      else if(what==='drop'){ if(!confirm('Drop this task? '+(t.assignee_name||'They')+' is told.')) return; post('task_delete',{id:id}).then(function(d){ if(d&&d.ok){ TASKS=TASKS.filter(function(x){return x.id!==id;}); render(); } else alert((d&&d.error)||'Not dropped.'); }); return; }
      else if(what==='reassign'){ var old2=li.querySelector('.task-reassign'); if(old2){ old2.remove(); return; }
        var box=document.createElement('div'); box.className='task-why task-reassign';
        box.innerHTML='<label>Give it to <select>'+ROSTER.filter(function(m){return m.id!==t.assignee_id;}).map(function(m){ return '<option value="'+m.id+'">'+esc(m.name)+'</option>'; }).join('')+'</select></label> <button type="button" class="pbtn pbtn-sm pbtn-gold">Reassign</button>';
        li.appendChild(box); box.querySelector('button').addEventListener('click', function(ev){ ev.stopPropagation(); post('task_assign',{id:id, assignee:+box.querySelector('select').value}).then(put); }); box.querySelector('select').focus(); return; }
      if(act) post(act, body).then(put);
    }

    /* Handing a task back asks why, in one tap: "not my kind of work" keeps the
       like of it from them next time; "no time" only for a while (lib/TaskLedger.php). */
    function askWhy(li, id){
      var old=li.querySelector('.task-why'); if(old){ old.remove(); return; }
      var keys=Object.keys(REASONS); if(!keys.length){ release(id,''); return; }
      var w=document.createElement('div'); w.className='task-why'; w.setAttribute('role','group'); w.setAttribute('aria-label','Why hand it back?');
      w.innerHTML='<span class="task-why-q">Back to the pool — why?</span>'+keys.map(function(k){ return '<button type="button" class="task-why-pick" data-reason="'+esc(k)+'">'+esc(REASONS[k])+'</button>'; }).join('')
        +'<button type="button" class="task-why-cancel">Keep it</button>';
      li.appendChild(w); var f=w.querySelector('button'); if(f) f.focus();
    }
    function release(id, reason){
      var rt=TASKS.filter(function(t){return t.id===id;})[0];
      post('task_release',{id:id,reason:reason}).then(function(d){ if(d&&d.ok){ TASKS=TASKS.filter(function(t){return t.id!==id;}); render(); if(rt){ POOL.unshift(Object.assign({},rt,{open:true,mine:false,assignee_id:0,assignee_name:'Unclaimed'})); renderPool(); } } else if(d&&d.error) alert(d.error); });
    }

    /* Away: no task is given or suggested to them until the date. */
    var awayEl=document.getElementById('taskAway');
    function drawAway(){
      if(!awayEl) return;
      var today=new Date().toISOString().slice(0,10);
      awayEl.innerHTML = AWAY
        ? '<span class="task-away-on">Away until '+esc(new Date(AWAY+'T00:00:00').toLocaleDateString(undefined,{day:'numeric',month:'short'}))+' — nothing new is given to you</span> <button type="button" class="task-away-back">I’m back</button>'
        : '<button type="button" class="task-away-set" aria-expanded="false">Going away?</button><span class="task-away-form" hidden><label>Not taking new tasks until <input type="date" min="'+today+'" class="task-away-date"></label> <button type="button" class="task-away-save">Save</button></span>';
    }
    if(awayEl) awayEl.addEventListener('click', function(e){
      var f=awayEl.querySelector('.task-away-form');
      if(e.target.closest('.task-away-set')){ f.hidden=!f.hidden; e.target.setAttribute('aria-expanded', String(!f.hidden)); if(!f.hidden) f.querySelector('input').focus(); return; }
      var until = e.target.closest('.task-away-back') ? '' : (e.target.closest('.task-away-save') ? (awayEl.querySelector('.task-away-date').value||'') : null);
      if(until===null) return;
      if(e.target.closest('.task-away-save') && !until){ awayEl.querySelector('.task-away-date').focus(); return; }
      post('task_away',{until:until}).then(function(d){ if(d&&d.ok){ AWAY=d.away_until||''; drawAway(); } else alert((d&&d.error)||'Could not save that.'); });
    });

    // state and size
    listEl.addEventListener('change', function(e){
      var li=e.target.closest('.task'); if(!li) return; var id=+li.getAttribute('data-id');
      var put=function(d){ if(d&&d.ok&&d.task){ TASKS=TASKS.map(function(t){return t.id===id?d.task:t;}); render(); if(ATTACHED[id]&&d.task.done) loadWeek(); } else { if(d&&d.error) alert(d.error); render(); } };
      if(e.target.classList.contains('task-state-sel')){
        var v=e.target.value, reason='';
        if(v==='blocked'){ reason=prompt('What is it blocked on? Whoever gave it to you is told.')||''; if(!reason.trim()){ render(); return; } }
        post('task_state',{id:id,state:v,reason:reason}).then(put);
      } else if(e.target.classList.contains('task-size-sel')){
        var sz=e.target.value, tt=TASKS.filter(function(x){return x.id===id;})[0]||{};
        post('task_size',{id:id,size:sz}).then(function(d){ put(d);
          /* Sized L with no "done when": asked, once, not forced. */
          if(d&&d.ok&&sz==='L'&&!tt.outcome){ var o=prompt('A big one. What does done look like? (optional)'); if(o&&o.trim()) post('task_update',{id:id,outcome:o.trim()}).then(put); } });
      }
    });

    // claim from the pool
    if(poolEl) poolEl.addEventListener('click', function(e){ var give=e.target.closest('.task-give'); if(!give) return;
      var li=e.target.closest('.task'); var id=+li.getAttribute('data-id'); give.disabled=true;
      post('task_assign',{id:id, assignee:+give.getAttribute('data-to')}).then(function(d){
        if(d&&d.ok){ POOL=POOL.filter(function(t){return t.id!==id;}); delete SUGGESTED[id]; renderPool(); if(d.task){ TASKS.unshift(d.task); render(); } aiSay('Assigned to '+(d.task?d.task.assignee_name:'them')+'.','ok'); }
        else { give.disabled=false; aiSay((d&&d.error)||'Could not assign.','warn'); } }).catch(function(){ give.disabled=false; }); });
    if(poolEl) poolEl.addEventListener('click', function(e){ var btn=e.target.closest('.task-claim'); if(!btn) return; var li=e.target.closest('.task'); if(!li) return; var id=+li.getAttribute('data-id');
      btn.disabled=true; btn.textContent='Claiming…';
      post('task_claim',{id:id}).then(function(d){ if(d&&d.ok&&d.task){ POOL=POOL.filter(function(t){return t.id!==id;}); renderPool(); TASKS.unshift(d.task); render(); }
        else { btn.disabled=false; btn.textContent='Claim'; if(d&&d.error){ btn.textContent='Taken'; POOL=POOL.filter(function(t){return t.id!==id;}); setTimeout(renderPool,900); } } }).catch(function(){ btn.disabled=false; btn.textContent='Claim'; }); });

    // AI: plan a goal into pooled tasks
    if(aiBtn) aiBtn.addEventListener('click', function(){ var gid=aiGoalSel?(+aiGoalSel.value||0):0; if(!gid){ aiSay('Pick a goal first.','warn'); return; }
      var max=aiMaxSel?(+aiMaxSel.value||6):6; aiBtn.disabled=true; var old=aiBtn.textContent; aiBtn.textContent='Thinking…'; aiSay('Planning your goal into tasks…','');
      var auto=!!(document.getElementById('aiAssign')||{}).checked;
      post('ai_from_goal',{goal_id:gid, max:max, auto_assign:auto}).then(function(d){ aiBtn.disabled=false; aiBtn.textContent=old;
        if(d&&d.ok&&d.created&&d.created.length){
          var pooled=0; d.created.forEach(function(t){ if(t.open){ pooled++; if(t.suggest) SUGGESTED[t.id]=t.suggest; POOL.unshift(t); } else if(t.mine||t.assigned_out){ TASKS.unshift(t); } });
          renderPool(); render();
          aiSay(d.assigned ? ('Planned '+d.created.length+' tasks: '+d.assigned+' assigned to the best fit'+(pooled?', '+pooled+' left in the pool':'')+'.')
            : ('Added '+d.created.length+' task'+(d.created.length===1?'':'s')+' to the pool'+(ALLOCATES?', each with who it fits — assign in one click, or let people claim.':' — claim what you can take on.')),'ok'); }
        else { aiSay((d&&d.error)||'Could not generate tasks. Try again.','warn'); } }).catch(function(){ aiBtn.disabled=false; aiBtn.textContent=old; aiSay('Network error — try again.','warn'); }); });

    // ── This week at CACENTRE: commitments, goals, and tasks that count toward them
    var weekEl=document.getElementById('cacWeek'), weekList=document.getElementById('cacWeekList'), weekMsg=document.getElementById('cacWeekMsg'), WEEK=null, ATTACHED={};
    function fmtN(n){ n=+n||0; return (Math.round(n*10)/10).toLocaleString(); }
    function weekSay(msg,tone){ if(!weekMsg) return; weekMsg.hidden=!msg; weekMsg.textContent=msg||''; weekMsg.className='task-ai-msg'+(tone?(' is-'+tone):''); }
    function taskTitle(id){ var t=TASKS.filter(function(x){return x.id===id;})[0]; return t?t.title:('Task '+id); }
    function renderWeek(){
      if(!weekEl||!WEEK) return;
      if(!WEEK.ok){ weekEl.hidden = WEEK.error==='not-configured'; if(!weekEl.hidden){ weekList.innerHTML='<li class="pc-empty">CACENTRE is not available right now. Your tasks here are unaffected.</li>'; } return; }
      weekEl.hidden=false;
      var r=document.getElementById('cacWeekRange'); if(r&&WEEK.week){ r.textContent=WEEK.week.start+' – '+WEEK.week.end; }
      if(!WEEK.linked){
        /* What CACENTRE said, and the one thing that fixes it: an address on the
           person record there that names this account, and only this one. */
        var me=(document.getElementById('cacWeek').getAttribute('data-email')||'this email');
        var why = WEEK.why==='ambiguous' ? 'CACENTRE has more than one person with '+esc(me)+', so it will not guess which is you. Ask your coordinator to remove it from the record that is not yours.'
          : WEEK.why==='taken' ? 'Your CACENTRE record is already linked to a different portal account. Ask your coordinator to check it.'
          : 'CACENTRE keeps a week for Afrovanguard members, and this account ('+esc(me)+') is not one. Sign in with your Afrovanguard account.';
        weekList.innerHTML='<li class="pc-empty">'+why+'</li>'; return; }
      if(!WEEK.commitments.length){
        /* No role at a centre yet: nothing to commit to, and saying "set them" would send the member looking for a form they do not have. */
        weekList.innerHTML = WEEK.roles===0
          ? '<li class="pc-empty">You are on CACENTRE, but not yet in a role at a centre, so there is nothing to commit to this week. The CACENTRE goals are below, and their steps are open to you.</li>'
          : '<li class="pc-empty">No commitments for this week yet. Open my week to set them — they appear here.</li>';
        return; }
      var open=TASKS.filter(function(t){ return !t.done && (t.mine || t.creator_name==='You'); });
      weekList.innerHTML=WEEK.commitments.map(function(c){
        var pct=c.target>0?Math.min(100,Math.round(100*c.delivered/c.target)):0, g=c.goal;
        var counted=Object.keys(ATTACHED).filter(function(k){return ATTACHED[k]===c.id;}).map(Number);
        var free=open.filter(function(t){ return !ATTACHED[t.id]; });
        var gp=g&&g.target?Math.min(100,Math.round(100*g.progress/g.target)):null;
        return '<li class="cw-item'+(c.status!=='open'?' is-closed':'')+'" data-c="'+c.id+'">'
          +'<div class="cw-top"><span class="cw-stmt">'+esc(c.statement)+'</span><span class="cw-when">'+(c.period==='daily'?'Daily · ':'')+esc(c.status==='open'?('by '+c.end):c.status)+'</span></div>'
          +'<div class="cw-meter"><progress max="'+(c.target||1)+'" value="'+Math.min(c.delivered,c.target||1)+'" aria-label="'+esc(c.statement)+': '+fmtN(c.delivered)+' of '+fmtN(c.target)+' '+esc(c.unit)+'"></progress>'
          +'<span class="cw-num" aria-hidden="true">'+fmtN(c.delivered)+' / '+fmtN(c.target)+' '+esc(c.unit)+' · '+pct+'%</span></div>'
          +(g?'<div class="cw-goal">Feeds <b>'+esc(g.code?g.code+' · ':'')+esc(g.title)+'</b>'+(gp!==null?' — '+gp+'% of '+fmtN(g.target)+' '+esc(g.unit):'')+(g.due?' · due '+esc(g.due):'')+'</div>':'<div class="cw-goal">Not tied to a goal yet.</div>')
          +(counted.length?'<ul class="cw-tasks" aria-label="Tasks counting toward this">'+counted.map(function(id){ var t=TASKS.filter(function(x){return x.id===id;})[0];
              return '<li>'+(t&&t.done?'✓ ':'○ ')+esc(taskTitle(id))+'<span class="sr-only">'+(t&&t.done?', done':', open')+'</span> <button type="button" class="cw-off" data-t="'+id+'">Stop counting<span class="sr-only">: '+esc(taskTitle(id))+'</span></button></li>'; }).join('')+'</ul>':'')
          +(c.status==='open'&&free.length?'<div class="cw-add"><label class="sr-only" for="cwSel'+c.id+'">A task to count toward: '+esc(c.statement)+'</label>'
              +'<select id="cwSel'+c.id+'"><option value="">Count one of my tasks toward this…</option>'+free.map(function(t){return '<option value="'+t.id+'">'+esc(t.title)+'</option>';}).join('')+'</select>'
              +'<button type="button" class="pbtn cw-on">Count it</button></div>':'')
          +'</li>'; }).join('');
    }
    function loadWeek(){ if(!weekEl) return;
      fetch('/portal/collab.php?action=cac_week',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){ WEEK=d||{ok:false}; ATTACHED=(d&&d.attached)||{}; renderWeek(); renderOwe(); }).catch(function(){}); }
    function countTask(taskId, cid){
      post('cac_count',{id:taskId, commitment_id:cid}).then(function(d){
        if(d&&d.ok){ ATTACHED=d.attached||{}; if(d.week&&d.week.ok){ WEEK=d.week; } renderWeek(); render(); weekSay(cid?'Counted. Ticking it here now counts on CACENTRE.':'It no longer counts toward that commitment.','ok'); }
        else weekSay((d&&d.error)||'CACENTRE refused that.','warn'); }).catch(function(){ weekSay('Network error — try again.','warn'); }); }
    if(weekList) weekList.addEventListener('click', function(e){
      var li=e.target.closest('.cw-item'); if(!li) return; var cid=+li.getAttribute('data-c');
      if(e.target.closest('.cw-on')){ var sel=li.querySelector('select'); var tid=sel?+sel.value:0; if(!tid){ weekSay('Choose the task first.','warn'); if(sel)sel.focus(); return; } countTask(tid,cid); }
      var off=e.target.closest('.cw-off'); if(off) countTask(+off.getAttribute('data-t'),0); });

    load();
    loadWeek();
    TICKS.flush();   /* ticks kept from a bad connection last time */
    setInterval(function(){ post('heartbeat',{}).then(function(d){ if(d&&typeof d.count==='number'){ if(onlineCountEl)onlineCountEl.textContent=d.count; if(tbCount)tbCount.textContent=d.count; if(kpiOnline)kpiOnline.textContent=d.count; if(topOnline)topOnline.hidden=!(d.count>0); } }).catch(function(){}); }, 45000);
    setInterval(load, 90000);
  })();
