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
    var TASKS=[], POOL=[], GOALS=[], FILTER='all', SHOW_DONE=false;
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
      var rel = (t.mine && !t.done) ? '<button type="button" class="task-release" title="Return to the pool" aria-label="Return to pool">↩</button>' : '';
      return '<li class="task task--pri-'+esc(t.priority||'normal')+(t.done?' is-done':'')+'" data-id="'+t.id+'">'
      +'<button type="button" class="task-check" aria-label="Toggle done">'+(t.done?'✓':'')+'</button>'
      +'<span class="task-title">'+esc(t.title)+(who?' <span class="task-who">'+who+'</span>':'')+goal+'</span>'
      +priTag(t)+due+rel+'<button type="button" class="task-del" aria-label="Delete task">✕</button></li>'; }
    function poolHtml(t){
      var dm = t.due ? dueMeta(t) : null;
      var due = dm ? '<span class="task-due'+(dm.tone?' is-'+dm.tone:'')+'">'+esc(dm.label)+'</span>' : '';
      var goal = t.goal_title ? '<span class="task-goal" title="Advances a goal">◎ '+esc(t.goal_title)+'</span>' : '';
      var by = (t.creator_name && t.creator_name!=='You') ? '<span class="task-who">by '+esc(t.creator_name)+'</span>' : '';
      return '<li class="task task-pool-item task--pri-'+esc(t.priority||'normal')+'" data-id="'+t.id+'">'
      +'<span class="task-body"><span class="task-title">'+esc(t.title)+'</span>'
      +'<span class="task-sub">'+priTag(t)+by+goal+due+'</span></span>'
      +'<button type="button" class="pbtn pbtn-gold task-claim">Claim</button></li>'; }
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
      var cur=sel.value; sel.innerHTML='<option value="0">Me</option>'+roster.map(function(m){ return '<option value="'+m.id+'">'+esc(m.name)+'</option>'; }).join(''); sel.value=cur; }
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
    function renderActivity(items){ items=items||[]; actEl.innerHTML = items.length ? items.map(function(a){ var obj=a.object?' <b>'+esc(a.object)+'</b>':''; var inner='<span class="act-ava">'+esc(a.initials)+'</span><span class="act-body"><span class="act-line"><b>'+esc(a.actor)+'</b> '+esc(a.verb)+obj+'</span><span class="act-ago">'+esc(a.ago)+'</span></span>'; return '<li class="act">'+(a.url?'<a href="'+esc(a.url)+'">'+inner+'</a>':inner)+'</li>'; }).join('') : '<li class="pc-empty">No activity yet.</li>'; }

    function load(){ fetch('/portal/collab.php?action=bootstrap',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){ if(!d||!d.ok) return;
        TASKS=d.tasks||[]; POOL=d.pool||[]; GOALS=d.goals||[];
        render(); renderPool(); renderActivity(d.activity); renderOnline(d.online,d.count); fillRoster(d.roster); fillGoals();
        if(aiCard) aiCard.hidden = !d.ai;   // only show the AI planner when Claude is wired up
      }).catch(function(){}); }

    // filter chips
    document.querySelectorAll('#taskFilters .pseg-btn').forEach(function(b){ b.addEventListener('click', function(){ document.querySelectorAll('#taskFilters .pseg-btn').forEach(function(x){x.classList.remove('is-on');}); b.classList.add('is-on'); FILTER=b.getAttribute('data-filter'); render(); }); });
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
    // toggle / delete / release
    listEl.addEventListener('click', function(e){
      if(e.target.closest('#doneToggle')){ SHOW_DONE=!SHOW_DONE; render(); return; }
      var li=e.target.closest('.task'); if(!li) return; var id=+li.getAttribute('data-id');
      if(e.target.closest('.task-check')){ post('task_toggle',{id:id}).then(function(d){ if(d&&d.ok){ TASKS=TASKS.map(function(t){return t.id===id?Object.assign({},t,{done:d.done}):t;}); render(); } }); }
      else if(e.target.closest('.task-release')){ var rt=TASKS.filter(function(t){return t.id===id;})[0]; post('task_release',{id:id}).then(function(d){ if(d&&d.ok){ TASKS=TASKS.filter(function(t){return t.id!==id;}); render(); if(rt){ POOL.unshift(Object.assign({},rt,{open:true,mine:false,assignee_id:0,assignee_name:'Unclaimed'})); renderPool(); } } }); }
      else if(e.target.closest('.task-del')){ post('task_delete',{id:id}).then(function(d){ if(d&&d.ok){ TASKS=TASKS.filter(function(t){return t.id!==id;}); render(); } }); } });

    // claim from the pool
    if(poolEl) poolEl.addEventListener('click', function(e){ var btn=e.target.closest('.task-claim'); if(!btn) return; var li=e.target.closest('.task'); if(!li) return; var id=+li.getAttribute('data-id');
      btn.disabled=true; btn.textContent='Claiming…';
      post('task_claim',{id:id}).then(function(d){ if(d&&d.ok&&d.task){ POOL=POOL.filter(function(t){return t.id!==id;}); renderPool(); TASKS.unshift(d.task); render(); }
        else { btn.disabled=false; btn.textContent='Claim'; if(d&&d.error){ btn.textContent='Taken'; POOL=POOL.filter(function(t){return t.id!==id;}); setTimeout(renderPool,900); } } }).catch(function(){ btn.disabled=false; btn.textContent='Claim'; }); });

    // AI: plan a goal into pooled tasks
    if(aiBtn) aiBtn.addEventListener('click', function(){ var gid=aiGoalSel?(+aiGoalSel.value||0):0; if(!gid){ aiSay('Pick a goal first.','warn'); return; }
      var max=aiMaxSel?(+aiMaxSel.value||6):6; aiBtn.disabled=true; var old=aiBtn.textContent; aiBtn.textContent='Thinking…'; aiSay('Planning your goal into tasks…','');
      post('ai_from_goal',{goal_id:gid, max:max}).then(function(d){ aiBtn.disabled=false; aiBtn.textContent=old;
        if(d&&d.ok&&d.created&&d.created.length){ d.created.forEach(function(t){ POOL.unshift(t); }); renderPool(); aiSay('Added '+d.created.length+' task'+(d.created.length===1?'':'s')+' to the pool — claim what you can take on.','ok'); }
        else { aiSay((d&&d.error)||'Could not generate tasks. Try again.','warn'); } }).catch(function(){ aiBtn.disabled=false; aiBtn.textContent=old; aiSay('Network error — try again.','warn'); }); });

    load();
    setInterval(function(){ post('heartbeat',{}).then(function(d){ if(d&&typeof d.count==='number'){ if(onlineCountEl)onlineCountEl.textContent=d.count; if(tbCount)tbCount.textContent=d.count; if(kpiOnline)kpiOnline.textContent=d.count; if(topOnline)topOnline.hidden=!(d.count>0); } }).catch(function(){}); }, 45000);
    setInterval(load, 90000);
  })();
