/* portal/tasks.js — moved verbatim from the inline script of portal/index.php v1
   (row 14 shell rebuild). Its logic is unchanged; it reads the same ids and data-* hooks. */
  /* Collaboration — presence, activity, tasks (with the filter chips). */
  (function () {
    var root = document.getElementById('tasks'); if (!root) return;
    var CAC_OPEN = parseInt(root.getAttribute('data-cac-open') || '0', 10) || 0;
    var csrf = root.getAttribute('data-csrf') || '';
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function post(action, body){ return fetch('/portal/collab.php?action='+action,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body||{})}).then(function(r){return r.json();}); }
    var listEl=document.getElementById('taskList'), actEl=document.getElementById('activityList'),
        onlineEl=document.getElementById('onlineList'), onlineCountEl=document.getElementById('onlineCount'),
        topOnline=document.getElementById('topOnline'), tbCount=document.getElementById('tbCount'),
        kpiTasks=document.getElementById('kpiTasks'), kpiOnline=document.getElementById('kpiOnline'),
        fcAll=document.getElementById('fcAll'), fcOpen=document.getElementById('fcOpen'), fcDone=document.getElementById('fcDone'),
        fcOver=document.getElementById('fcOver'), fcMine=document.getElementById('fcMine');
    var poolEl=document.getElementById('poolList'), poolCountEl=document.getElementById('poolCount'),
        aiCard=document.getElementById('taskAi'), aiGoalSel=document.getElementById('aiGoal'),
        aiMaxSel=document.getElementById('aiMax'), aiBtn=document.getElementById('aiGenerate'), aiMsgEl=document.getElementById('aiMsg'),
        poolChk=document.getElementById('taskPool'),
        focusWrap=document.getElementById('taskFocus'), ringEl=document.getElementById('taskRing'),
        ringNEl=document.getElementById('taskRingN'), focusTxt=document.getElementById('taskFocusTxt');
    var TASKS=[], POOL=[], GOALS=[], FILTER='all', SHOW_DONE=false, ALLOCATES=false, SUGGESTED={};
    function ymd(off){ var d=new Date(); d.setHours(0,0,0,0); d.setDate(d.getDate()+(off||0)); return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2); }
    var TODAY=ymd(0), TOMORROW=ymd(1), WEEK_END=ymd(7);
    function isOverdue(t){ return !t.done && t.due && (t.overdue || t.due < TODAY); }
    function counts(){ var open=TASKS.filter(function(t){return !t.done;}).length, done=TASKS.length-open,
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
      return '<li class="task task--pri-'+esc(t.priority||'normal')+(t.done?' is-done':'')+' task--'+esc(st)+'" data-id="'+t.id+'">'
      +'<button type="button" class="task-check" aria-label="Toggle done">'+(t.done?'✓':'')+'</button>'
      +'<span class="task-title">'+esc(t.title)+(who?' <span class="task-who">'+who+'</span>':'')+goal+(note?'<span class="task-notes">'+note+'</span>':'')+'</span>'
      +ctl+priTag(t)+due+rel+'<button type="button" class="task-del" aria-label="Delete task">✕</button></li>'; }
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
      if(!GOALS.length){ aiGoalSel.innerHTML='<option value="">No open goals — add one in Suite → Goals</option>'; if(aiBtn)aiBtn.disabled=true; return; }
      aiGoalSel.innerHTML = GOALS.map(function(g){ return '<option value="'+g.id+'">'+esc(g.title)+'</option>'; }).join('');
      if(aiBtn)aiBtn.disabled=false;
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
      var open=TASKS.filter(function(t){return !t.done;}), done=TASKS.filter(function(t){return t.done;});
      if(!open.length && !(FILTER==='all'&&done.length)){
        listEl.innerHTML='<li class="pc-empty task-empty">✳ Inbox zero. Nothing on your plate — grab one from the pool or plan a goal.</li>'; return; }
      var byB={}; open.forEach(function(t){ var b=bucketOf(t); (byB[b]=byB[b]||[]).push(t); });
      var html='';
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
      users=users||[]; onlineEl.innerHTML = users.length ? users.map(function(u){ return '<div class="online-row"><span class="online-ava is-'+esc(u.status)+'">'+esc(u.initials)+'</span><span class="online-name">'+esc(u.name)+'</span></div>'; }).join('') : '<p class="pc-empty">Just you so far.</p>'; }
    function renderActivity(items){ items=items||[]; actEl.innerHTML = items.length ? items.map(function(a){ var obj=a.object?' <b>'+esc(a.object)+'</b>':''; var inner='<span class="act-ava" aria-hidden="true">'+esc(a.initials)+'</span><span class="act-body"><span class="act-line"><b>'+esc(a.actor)+'</b> '+esc(a.verb)+obj+'</span><span class="act-ago">'+esc(a.ago)+'</span></span>'; return '<li class="act">'+(a.url?'<a href="'+esc(a.url)+'">'+inner+'</a>':inner)+'</li>'; }).join('') : '<li class="pc-empty">No activity yet.</li>'; }

    function load(){ fetch('/portal/collab.php?action=bootstrap',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){ if(!d||!d.ok) return;
        TASKS=d.tasks||[]; GOALS=d.goals||[]; ALLOCATES=!!d.allocates;
        POOL=(d.pool||[]).map(function(t){ return SUGGESTED[t.id]?Object.assign({},t,{suggest:SUGGESTED[t.id]}):t; });
        var aw=document.getElementById('aiAssignWrap'); if(aw) aw.hidden=!ALLOCATES;
        render(); renderPool(); renderActivity(d.activity); renderOnline(d.online,d.count); fillRoster(d.roster); fillGoals();
        if(aiCard) aiCard.hidden = !d.ai;   // only show the AI planner when Claude is wired up
      }).catch(function(){}); }

    // filter chips
    document.querySelectorAll('#taskFilters .pseg-btn').forEach(function(b){ b.addEventListener('click', function(){ document.querySelectorAll('#taskFilters .pseg-btn').forEach(function(x){x.classList.remove('is-on'); x.setAttribute('aria-pressed','false');}); b.classList.add('is-on'); b.setAttribute('aria-pressed','true'); FILTER=b.getAttribute('data-filter'); render(); }); });
    // add
    var form=document.getElementById('taskAdd'), input=document.getElementById('taskInput');
    form.addEventListener('submit', function(e){ e.preventDefault(); var title=(input.value||'').trim(); if(!title) return;
      var asel=document.getElementById('taskAssignee'); var assignee=asel?(+asel.value||0):0;
      var dsel=document.getElementById('taskDue'); var due=dsel?dsel.value:'';
      var psel=document.getElementById('taskPriority'); var priority=psel?psel.value:'normal';
      var toPool = poolChk && poolChk.checked;
      input.value=''; input.disabled=true;
      if(toPool){
        post('task_add_pool',{title:title, due:due, priority:priority}).then(function(d){ input.disabled=false; input.focus(); if(dsel)dsel.value=''; if(psel)psel.value='normal'; poolChk.checked=false; if(d&&d.ok&&d.task){ POOL.unshift(d.task); renderPool(); } }).catch(function(){ input.disabled=false; });
      } else {
        post('task_add',{title:title, assignee:assignee, due:due, priority:priority}).then(function(d){ input.disabled=false; input.focus(); if(asel)asel.value='0'; if(dsel)dsel.value=''; if(psel)psel.value='normal'; if(d&&d.ok&&d.task){ TASKS.unshift(d.task); render(); } }).catch(function(){ input.disabled=false; });
      } });
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
        post('task_suggest',{title:v}).then(function(d){ if((input.value||'').trim()!==v) return; showSuggest(d&&d.ok?d.suggestions:[], d&&d.via); }).catch(function(){}); }, 600); });
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
      if(e.target.closest('.task-check')){ post('task_toggle',{id:id}).then(function(d){ if(d&&d.ok){ TASKS=TASKS.map(function(t){return t.id===id?Object.assign({},t,{done:d.done,state:d.done?'done':(t.started_at?'doing':'todo'),stuck:{flag:'',days:0}}):t;}); render(); if(ATTACHED[id]) loadWeek(); } }); }
      else if(e.target.closest('.task-release')){ var rt=TASKS.filter(function(t){return t.id===id;})[0]; post('task_release',{id:id}).then(function(d){ if(d&&d.ok){ TASKS=TASKS.filter(function(t){return t.id!==id;}); render(); if(rt){ POOL.unshift(Object.assign({},rt,{open:true,mine:false,assignee_id:0,assignee_name:'Unclaimed'})); renderPool(); } } }); }
      else if(e.target.closest('.task-del')){ post('task_delete',{id:id}).then(function(d){ if(d&&d.ok){ TASKS=TASKS.filter(function(t){return t.id!==id;}); render(); } }); } });

    // state and size
    listEl.addEventListener('change', function(e){
      var li=e.target.closest('.task'); if(!li) return; var id=+li.getAttribute('data-id');
      var put=function(d){ if(d&&d.ok&&d.task){ TASKS=TASKS.map(function(t){return t.id===id?d.task:t;}); render(); if(ATTACHED[id]&&d.task.done) loadWeek(); } else { if(d&&d.error) alert(d.error); render(); } };
      if(e.target.classList.contains('task-state-sel')){
        var v=e.target.value, reason='';
        if(v==='blocked'){ reason=prompt('What is it blocked on? Whoever gave it to you is told.')||''; if(!reason.trim()){ render(); return; } }
        post('task_state',{id:id,state:v,reason:reason}).then(put);
      } else if(e.target.classList.contains('task-size-sel')){
        post('task_size',{id:id,size:e.target.value}).then(put);
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
      if(!WEEK.commitments.length){ weekList.innerHTML='<li class="pc-empty">No commitments for this week yet. Set them on CACENTRE — they appear here.</li>'; return; }
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
      fetch('/portal/collab.php?action=cac_week',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){ WEEK=d||{ok:false}; ATTACHED=(d&&d.attached)||{}; renderWeek(); }).catch(function(){}); }
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
    setInterval(function(){ post('heartbeat',{}).then(function(d){ if(d&&typeof d.count==='number'){ if(onlineCountEl)onlineCountEl.textContent=d.count; if(tbCount)tbCount.textContent=d.count; if(kpiOnline)kpiOnline.textContent=d.count; if(topOnline)topOnline.hidden=!(d.count>0); } }).catch(function(){}); }, 45000);
    setInterval(load, 90000);
  })();
