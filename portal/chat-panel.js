/* portal/chat-panel.js — moved verbatim from the inline script of portal/index.php v1
   (row 14 shell rebuild). Its logic is unchanged; it reads the same ids and data-* hooks. */
  /* Team Chat — Slack-style native channels: message stream (grouped, day
     dividers), composer with @mention autocomplete, emoji reactions, presence
     rail, and near-realtime polling via sinceId. */
  (function () {
    var root = document.getElementById('teamChat'); if (!root) return;
    var csrf = root.getAttribute('data-csrf') || '';
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function post(action, body){ return fetch('/portal/chat.php?action='+action,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body||{})}).then(function(r){return r.json();}); }
    function get(action, qs){ return fetch('/portal/chat.php?action='+action+(qs||''),{credentials:'same-origin'}).then(function(r){return r.json();}); }
    var streamEl=document.getElementById('tcStream'), chanEl=document.getElementById('tcChannels'),
        membersEl=document.getElementById('tcMembers'), typingEl=document.getElementById('tcTyping'),
        presEl=document.getElementById('tcPresence'), chanNameEl=document.getElementById('tcChannelName'),
        memberCountEl=document.getElementById('tcMemberCount'), topicEl=document.getElementById('tcTopic'),
        catchupBtn=document.getElementById('tcCatchup'),
        pinnedEl=document.getElementById('tcPinned'), pinBtn=document.getElementById('tcPinBtn'), pinCountEl=document.getElementById('tcPinCount'),
        input=document.getElementById('tcInput'), mentEl=document.getElementById('tcMentions'),
        composeForm=document.getElementById('tcCompose'),
        editBar=document.getElementById('tcEditBar'), editCancel=document.getElementById('tcEditCancel'),
        savedBtn=document.getElementById('tcSavedBtn'), addChannelBtn=document.getElementById('tcAddChannel'), chSetBtn=document.getElementById('tcChannelSettings');
    var CHANNEL='general', MSGS=[], LAST=0, EMOJI=[], ME={id:0}, CHANNELS=[], TOPICS={}, MEMBERS={mentors:[],members:[]}, PINS=[], PINS_OPEN=false, AI_OK=false, IS_ADMIN=false, EDITING=0, poller=null, active=false, ready=false, seenKey='av_chat_seen';
    // Per-channel last-seen id (localStorage) → unread dots.
    function seen(){ try{ return JSON.parse(localStorage.getItem(seenKey)||'{}'); }catch(e){ return {}; } }
    function markSeen(ch, id){ var s=seen(); if(!s[ch]||id>s[ch]){ s[ch]=id; try{ localStorage.setItem(seenKey, JSON.stringify(s)); }catch(e){} } }
    function fmtTime(iso){ var t=Date.parse((iso||'').replace(' ','T')+'Z'); if(!t) return ''; return new Date(t).toLocaleTimeString(undefined,{hour:'numeric',minute:'2-digit'}); }
    function dayOf(iso){ var t=Date.parse((iso||'').replace(' ','T')+'Z'); if(!t) return ''; var d=new Date(t); var td=new Date(); var y=new Date(td.getTime()-86400000);
      if(d.toDateString()===td.toDateString()) return 'Today'; if(d.toDateString()===y.toDateString()) return 'Yesterday';
      return d.toLocaleDateString(undefined,{weekday:'long',month:'short',day:'numeric'}); }
    function codeBlock(code){ return '<div class="tc-codewrap"><pre class="tc-code"><code>'+esc(code)+'</code></pre><button type="button" class="tc-copy" title="Copy code">Copy</button></div>'; }
    // Lightweight, safe Markdown: fenced/inline code, **bold** *italic* ~~strike~~,
    // [links](url) + bare URLs, and "- " bullet lists. Everything is escaped first;
    // mentions resolve to chips. Private-use markers isolate code from formatting.
    function bodyHtml(m){
      var raw=String(m.body==null?'':m.body);
      var S='\ue000', E='\ue001';   // private-use markers: never appear in user text
      var fen=[]; raw=raw.replace(/```([\s\S]*?)```/g, function(_, c){ fen.push(c.replace(/^\n/,'').replace(/\n+$/,'')); return S+'F'+(fen.length-1)+E; });
      var inl=[]; raw=raw.replace(/`([^`\n]+)`/g, function(_, c){ inl.push(c); return S+'I'+(inl.length-1)+E; });
      function inline(s){ s=esc(s);
        (m.mentions||[]).forEach(function(mn){ var tok=(mn.token||mn.handle||'').replace(/^@/,''); if(tok){ s=s.split('@'+esc(tok)).join('<span class="tc-at">@'+esc(mn.name||mn.handle||'')+'</span>'); } });
        s=s.replace(/\*\*([^*\n]+)\*\*/g,'<b>$1</b>').replace(/~~([^~\n]+)~~/g,'<s>$1</s>')
           .replace(/(^|[^\w*])\*([^*\n]+)\*(?!\w)/g,'$1<i>$2</i>').replace(/(^|[^\w_])_([^_\n]+)_(?!\w)/g,'$1<i>$2</i>');
        s=s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,'<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
        s=s.replace(/(^|[\s(])((?:https?:\/\/)[^\s<)]+)/g, function(_, p, u){ return p+'<a href="'+u+'" target="_blank" rel="noopener noreferrer">'+u+'</a>'; });
        s=s.replace(new RegExp(S+'I(\\d+)'+E,'g'), function(_, i){ return '<code class="tc-code-inline">'+esc(inl[+i])+'</code>'; });
        return s; }
      var rows=raw.split('\n'), out='', inUl=false, para=[];
      function flush(){ if(para.length){ out+='<span class="tc-line">'+para.join('<br>')+'</span>'; para=[]; } }
      var fenRe=new RegExp('^'+S+'F(\\d+)'+E+'$');
      rows.forEach(function(l){
        var fm=l.match(fenRe);
        if(fm){ flush(); if(inUl){out+='</ul>';inUl=false;} out+=codeBlock(fen[+fm[1]]); return; }
        if(/^\s*[-*]\s+/.test(l)){ flush(); if(!inUl){out+='<ul class="tc-ul">';inUl=true;} out+='<li>'+inline(l.replace(/^\s*[-*]\s+/,''))+'</li>'; return; }
        if(inUl){ out+='</ul>'; inUl=false; }
        para.push(inline(l)); });
      flush(); if(inUl) out+='</ul>';
      return out; }
    // Only render a reactions row when there ARE reactions — an always-present
    // (invisible) add button would reserve empty vertical space under every
    // message. Adding the first reaction happens from the hover actions bar.
    function reactHtml(m){ var rx=m.reactions||[]; if(!rx.length) return '';
      var chips=rx.map(function(r){ return '<button type="button" class="tc-react'+(r.mine?' is-mine':'')+'" data-emoji="'+esc(r.emoji)+'">'+esc(r.emoji)+' '+r.count+'</button>'; }).join('');
      return '<span class="tc-reacts">'+chips+'<button type="button" class="tc-react-add" title="Add reaction">＋</button></span>'; }
    function threadSummary(m){ if(!m.reply_count) return ''; return '<button type="button" class="tc-thread-sum" data-thread="'+m.id+'">🧵 '+m.reply_count+' repl'+(m.reply_count===1?'y':'ies')+(m.last_reply?' <span class="tc-thread-ago">· last '+esc(m.last_reply)+'</span>':'')+'</button>'; }
    function actionsHtml(m){
      if(m.deleted) return '';
      var reply = (!m.parent_id) ? '<button type="button" class="tc-act" data-act="reply" title="Reply in thread">💬</button>' : '';
      var pin = (!m.parent_id) ? '<button type="button" class="tc-act'+(m.pinned?' is-on':'')+'" data-act="pin" title="'+(m.pinned?'Unpin':'Pin to channel')+'">📌</button>' : '';
      var reactBtn = '<button type="button" class="tc-act" data-act="react" title="Add reaction">😊</button>';
      var save = '<button type="button" class="tc-act'+(m.saved?' is-on':'')+'" data-act="save" title="'+(m.saved?'Remove from saved':'Save for later')+'">'+(m.saved?'🔖':'🏷️')+'</button>';
      var edit = m.is_me ? '<button type="button" class="tc-act" data-act="edit" title="Edit">✎</button>' : '';
      var del = (m.is_me || IS_ADMIN) ? '<button type="button" class="tc-act tc-act-del" data-act="delete" title="Delete">🗑</button>' : '';
      return '<div class="tc-actions">'+reactBtn+reply+pin+'<button type="button" class="tc-act" data-act="assign" title="Assign as task">⌗</button>'+save+edit+del+'</div>'; }
    function msgHtml(m, grouped){
      var head = grouped ? '' : '<span class="tc-avatar">'+esc(m.initial)+'</span>';
      var pinMark = m.pinned && !m.deleted ? '<span class="tc-pinmark" title="Pinned">📌</span>' : '';
      var meta = grouped ? '' : '<span class="tc-msg-h"><b class="tc-name">'+esc(m.author)+'</b>'+(m.verified?'<span class="tc-badge" title="Verified member">✓</span>':'')+'<span class="tc-time">'+esc(fmtTime(m.created_at))+'</span>'+pinMark+'</span>';
      if(m.deleted){
        return '<div class="tc-msg is-deleted'+(grouped?' is-grouped':'')+'" data-id="'+m.id+'">'
          +'<div class="tc-msg-l">'+head+'</div>'
          +'<div class="tc-msg-b">'+meta+'<div class="tc-text tc-tomb"><svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13h10l1-13" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg> This message was deleted</div></div></div>'; }
      var edited = m.edited ? '<span class="tc-edited" title="Edited">(edited)</span>' : '';
      var savedMark = m.saved ? '<span class="tc-savemark" title="Saved">🔖</span>' : '';
      return '<div class="tc-msg'+(grouped?' is-grouped':'')+(m.is_me?' is-me':'')+(m.pinned?' is-pinned':'')+'" data-id="'+m.id+'">'
        +actionsHtml(m)
        +'<div class="tc-msg-l">'+head+savedMark+'</div>'
        +'<div class="tc-msg-b">'+meta+'<div class="tc-text">'+bodyHtml(m)+edited+'</div>'+reactHtml(m)+threadSummary(m)+'</div></div>'; }
    function render(){
      if(!MSGS.length){ streamEl.innerHTML='<p class="pc-empty tc-empty">👋 No messages yet in #'+esc(CHANNEL)+'. Say hello to the team.</p>'; return; }
      var html='', lastDay='', lastAuthor='', lastT=0;
      MSGS.forEach(function(m){ var day=dayOf(m.created_at);
        if(day!==lastDay){ html+='<div class="tc-divider"><span>'+esc(day)+'</span></div>'; lastDay=day; lastAuthor=''; }
        var t=Date.parse((m.created_at||'').replace(' ','T')+'Z')||0;
        var grouped = (m.author===lastAuthor) && (t-lastT < 5*60000);
        html+=msgHtml(m, grouped); lastAuthor=m.author; lastT=t; });
      streamEl.innerHTML=html; streamEl.scrollTop=streamEl.scrollHeight;
    }
    function renderChannels(){
      var s=seen();
      chanEl.innerHTML=CHANNELS.map(function(c){ var unread = c.last_id>(s[c.key]||0) && c.key!==CHANNEL;
        var hash = c.private ? '<span class="tc-hash" title="Private">🔒</span>' : '<span class="tc-hash">#</span>';
        var gc = c.gchat ? '<span class="tc-gc" title="Mirrors to Google Chat">↗</span>' : '';
        return '<li><button type="button" class="tc-channel'+(c.key===CHANNEL?' is-on':'')+'" data-ch="'+esc(c.key)+'">'+hash+esc(c.label)+gc+(unread?'<span class="tc-unread"></span>':'')+'</button></li>'; }).join('');
      updateNavBadge();
    }
    // A count of channels with unread messages, shown on the sidebar "Team Chat" nav.
    function updateNavBadge(){
      var link=document.querySelector('.pnav-link[data-view="chat"]'); if(!link) return;
      var s=seen(), chatVisible=!!(document.getElementById('view-chat') && !document.getElementById('view-chat').hidden);
      var n=CHANNELS.filter(function(c){ if(c.key===CHANNEL && chatVisible) return false; return c.last_id>(s[c.key]||0); }).length;
      var badge=link.querySelector('.pnav-badge');
      if(n>0){ if(!badge){ badge=document.createElement('span'); badge.className='pnav-badge'; link.appendChild(badge); } badge.textContent=n; badge.hidden=false; }
      else if(badge){ badge.hidden=true; }
    }
    function updatePresence(count){ if(presEl)presEl.textContent=count||0; }
    function roleTag(r){ return (r==='mentor'||r==='instructor') ? '<span class="tc-role tc-role--mentor">Mentor</span>'
      : (r==='coordinator'||r==='admin') ? '<span class="tc-role tc-role--lead">Lead</span>' : ''; }
    function memberRow(u){ return '<li class="tc-mem'+(u.online?'':' is-off')+'"><span class="tc-mem-ava">'+esc(u.initial)+(u.online?'<span class="tc-mem-dot"></span>':'')+'</span>'
      +'<span class="tc-mem-b"><span class="tc-mem-name">'+esc(u.name)+(u.is_me?' <span class="tc-mem-you">you</span>':'')+'</span>'+roleTag(u.role)+'</span></li>'; }
    function renderMembers(){ if(!membersEl) return; var m=MEMBERS||{mentors:[],members:[]}; var html='';
      if((m.mentors||[]).length){ html+='<div class="tc-mem-h">Mentors · '+m.mentors.length+'</div>'+m.mentors.map(memberRow).join(''); }
      html+='<div class="tc-mem-h tc-mem-h--sp">Members · '+(m.members||[]).length+'</div>'+((m.members||[]).length?m.members.map(memberRow).join(''):'<p class="pc-empty">No members yet.</p>');
      membersEl.innerHTML=html;
      var total=(m.mentors||[]).length+(m.members||[]).length; if(memberCountEl)memberCountEl.textContent=total?('· '+total+' member'+(total===1?'':'s')):''; }
    function renderTyping(names){ names=names||[]; if(!typingEl) return;
      if(!names.length){ typingEl.hidden=true; typingEl.innerHTML=''; return; }
      var label = names.length===1 ? esc(names[0])+' is typing' : names.length===2 ? esc(names[0])+' and '+esc(names[1])+' are typing' : names.length+' people are typing';
      typingEl.hidden=false; typingEl.innerHTML='<span class="tc-typing-dots"><i></i><i></i><i></i></span>'+label+'…'; }
    function setTopic(){ if(topicEl) topicEl.textContent = TOPICS[CHANNEL] || ''; }
    // Plain-text one-liner from a message body (strip fences/inline code/newlines).
    function plainText(body){ return String(body||'').replace(/```[\s\S]*?```/g,'[code]').replace(/`([^`]+)`/g,'$1').replace(/\s+/g,' ').trim(); }
    function renderPins(){
      if(!pinnedEl) return;
      var n=PINS.length;
      if(pinBtn){ pinBtn.hidden = n===0; if(pinCountEl) pinCountEl.textContent=n; pinBtn.classList.toggle('is-on', PINS_OPEN); }
      if(!n || !PINS_OPEN){ pinnedEl.hidden=true; pinnedEl.innerHTML=''; return; }
      pinnedEl.hidden=false;
      pinnedEl.innerHTML='<div class="tc-pinned-h"><svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M9 4h6l-1 6 4 3v2H6v-2l4-3-1-6Z" fill="currentColor"/></svg> '+n+' pinned</div>'
        + PINS.map(function(p){ var txt=plainText(p.body); if(txt.length>140) txt=txt.slice(0,140)+'…';
            return '<div class="tc-pin" data-id="'+p.id+'"><span class="tc-pin-b"><b>'+esc(p.author)+'</b> <span class="tc-pin-txt">'+esc(txt)+'</span></span>'
              +'<button type="button" class="tc-pin-jump" data-jump="'+p.id+'" title="Jump to message">↧</button>'
              +'<button type="button" class="tc-pin-x" data-unpin="'+p.id+'" title="Unpin">✕</button></div>'; }).join('');
    }
    // Append only strictly-newer messages (id > LAST) so a late/overlapping poll can't duplicate.
    function applyNew(list){ if(!list||!list.length) return; var added=false; list.forEach(function(m){ if(m.id>LAST){ MSGS.push(m); LAST=m.id; added=true; } }); if(!added) return; if(MSGS.length>200)MSGS=MSGS.slice(-200); markSeen(CHANNEL,LAST); render(); }
    function bootstrap(){ ready=false; get('bootstrap','&channel='+encodeURIComponent(CHANNEL)).then(function(d){ if(!d||!d.ok){ ready=true; return; }
        CHANNELS=d.channels||[]; EMOJI=d.react_emoji||[]; ME=d.me||ME; TOPICS=d.topics||TOPICS; MEMBERS=d.members||MEMBERS; PINS=d.pins||[]; AI_OK=!!d.ai; IS_ADMIN=!!d.is_admin;
        MSGS=d.messages||[]; LAST=MSGS.length?MSGS[MSGS.length-1].id:0;
        markSeen(CHANNEL,LAST); renderChannels(); render(); renderMembers(); renderTyping(d.typing); renderPins(); setTopic(); updatePresence(d.count);
        if(catchupBtn) catchupBtn.hidden=!AI_OK; if(addChannelBtn) addChannelBtn.hidden=!IS_ADMIN; if(chSetBtn) chSetBtn.hidden=!IS_ADMIN; ready=true; }).catch(function(){ ready=true; }); }
    function poll(){ if(!active || !ready) return; get('poll','&channel='+encodeURIComponent(CHANNEL)+'&since='+LAST).then(function(d){ if(!d||!d.ok) return;
        CHANNELS=d.channels||CHANNELS; if(d.members)MEMBERS=d.members; applyNew(d.messages); renderChannels(); renderMembers(); renderTyping(d.typing); updatePresence(d.count); }).catch(function(){}); }
    function switchChannel(ch){ if(ch===CHANNEL) return; CHANNEL=ch; MSGS=[]; LAST=0; if(chanNameEl)chanNameEl.textContent=ch; if(input)input.setAttribute('data-ph','Message #'+ch+' — use @ to mention'); renderChannels(); setTopic(); streamEl.innerHTML='<p class="pc-empty">Loading…</p>'; bootstrap(); }

    chanEl.addEventListener('click', function(e){ var b=e.target.closest('.tc-channel'); if(b) switchChannel(b.getAttribute('data-ch')); });

    // ── Message search ──
    var searchEl=document.getElementById('tcSearch'), searchResEl=document.getElementById('tcSearchResults'), searchTimer=null;
    function renderSearch(rows){ if(!searchResEl) return; rows=rows||[];
      if(!rows.length){ searchResEl.hidden=false; searchResEl.innerHTML='<div class="tc-sr-empty">No matches.</div>'; return; }
      searchResEl.hidden=false;
      searchResEl.innerHTML=rows.map(function(r){ var t=plainText(r.body); if(t.length>90)t=t.slice(0,90)+'…';
        return '<button type="button" class="tc-sr" data-ch="'+esc(r.channel||'')+'" data-id="'+r.id+'"><span class="tc-sr-top"><b>'+esc(r.author)+'</b> <span class="tc-sr-ch">#'+esc(r.channel||'')+'</span></span><span class="tc-sr-txt">'+esc(t)+'</span></button>'; }).join(''); }
    if(searchEl) searchEl.addEventListener('input', function(){ var q=(searchEl.value||'').trim(); clearTimeout(searchTimer);
      if(q.length<2){ if(searchResEl){searchResEl.hidden=true; searchResEl.innerHTML='';} return; }
      searchTimer=setTimeout(function(){ get('search','&q='+encodeURIComponent(q)).then(function(d){ if(d&&d.ok) renderSearch(d.results); }).catch(function(){}); }, 220); });
    if(searchResEl) searchResEl.addEventListener('click', function(e){ var b=e.target.closest('.tc-sr'); if(!b) return; var ch=b.getAttribute('data-ch'), id=+b.getAttribute('data-id');
      searchResEl.hidden=true; if(searchEl)searchEl.value=''; var switched = ch && ch!==CHANNEL; if(switched) switchChannel(ch);
      setTimeout(function(){ var el=streamEl.querySelector('.tc-msg[data-id="'+id+'"]'); if(el){ el.scrollIntoView({block:'center'}); el.classList.add('tc-flash'); setTimeout(function(){ el.classList.remove('tc-flash'); },1500); } }, switched?650:60); });
    document.addEventListener('click', function(e){ if(searchResEl && !searchResEl.hidden && !e.target.closest('.tc-search')) searchResEl.hidden=true; });

    // ── WYSIWYG composer ─────────────────────────────────────────
    // The composer shows the FORMATTED text as you type (bold/italic/code render
    // live) — not raw markdown. On send/edit it serialises to markdown so the
    // stored + mirrored message stays plain, portable text. attachRichEditor()
    // (defined below) encapsulates the toolbar, @mention chips and md round-trip.
    function replaceMsg(nm){ var i; for(i=0;i<MSGS.length;i++){ if(MSGS[i].id===nm.id){ MSGS[i]=nm; } } for(i=0;i<THREAD.length;i++){ if(THREAD[i].id===nm.id){ THREAD[i]=nm; } } render(); if(OPEN_THREAD) renderThread(); }
    var lastTyping=0;
    var mainRTE = attachRichEditor(input, composeForm, mentEl, {
      onSubmit: function(){ composeForm.requestSubmit(); },
      onInput:  function(){ var now=Date.now(); if(!mainRTE.isEmpty() && now-lastTyping>2500){ lastTyping=now; post('typing',{channel:CHANNEL}); } }
    });
    // Edit mode — load the message's markdown back into the formatted editor.
    function enterEdit(id){ var m=MSGS.concat(THREAD).filter(function(x){return x.id===id;})[0]; if(!m||m.deleted) return;
      EDITING=id; mainRTE.setMarkdown(m.body||''); mainRTE.focus();
      if(editBar) editBar.hidden=false; composeForm.classList.add('is-editing'); }
    function exitEdit(){ EDITING=0; mainRTE.clear(); if(editBar) editBar.hidden=true; composeForm.classList.remove('is-editing'); }
    if(editCancel) editCancel.addEventListener('click', exitEdit);
    // Send / save-edit
    composeForm.addEventListener('submit', function(e){ e.preventDefault(); var body=mainRTE.getMarkdown().trim(); if(!body) return;
      if(EDITING){ var id=EDITING; post('edit',{id:id, body:body}).then(function(d){ if(d&&d.ok&&d.message){ replaceMsg(d.message); } }).catch(function(){}); exitEdit(); return; }
      mainRTE.clear();
      post('send',{channel:CHANNEL, body:body}).then(function(d){ if(d&&d.ok&&d.message){ applyNew([d.message]); renderChannels(); } }).catch(function(){}); });
    // Escape cancels an in-progress edit (when mentions aren't open).
    input.addEventListener('keydown', function(e){ if(e.key==='Escape' && EDITING && !mainRTE.mentionsOpen()){ exitEdit(); } });

    // ── Catch me up (AI recap) ──
    var recapScrim=document.getElementById('recapScrim'), recapBody=document.getElementById('recapBody');
    function mdLite(t){ var lines=String(t||'').split('\n'), out='', inUl=false;
      function inl(s){ return esc(s).replace(/\*\*([^*]+)\*\*/g,'<b>$1</b>').replace(/`([^`]+)`/g,'<code class="tc-code-inline">$1</code>'); }
      lines.forEach(function(l){ var t2=l.trim();
        if(/^[-*•]\s+/.test(t2)){ if(!inUl){ out+='<ul>'; inUl=true; } out+='<li>'+inl(t2.replace(/^[-*•]\s+/,''))+'</li>'; return; }
        if(inUl){ out+='</ul>'; inUl=false; }
        if(!t2){ out+=''; return; }
        if(/^#{1,6}\s/.test(t2)){ out+='<h4>'+inl(t2.replace(/^#{1,6}\s/,''))+'</h4>'; return; }
        out+='<p>'+inl(t2)+'</p>'; });
      if(inUl) out+='</ul>'; return out; }
    function openRecap(){ if(!recapScrim) return; recapScrim.hidden=false; recapBody.innerHTML='<p class="pc-empty tc-recap-load"><span class="tc-typing-dots"><i></i><i></i><i></i></span> Reading #'+esc(CHANNEL)+' and writing your briefing…</p>';
      get('recap','&channel='+encodeURIComponent(CHANNEL)).then(function(d){ if(d&&d.ok){ recapBody.innerHTML=mdLite(d.summary)+'<div class="tc-recap-src">Summarised by AI'+(d.via?' ('+esc(d.via)+')':'')+' — verify anything important.</div>'; } else { recapBody.innerHTML='<p class="pc-empty">'+esc((d&&d.error)||'Could not generate a recap.')+'</p>'; } }).catch(function(){ recapBody.innerHTML='<p class="pc-empty">Network error — try again.</p>'; }); }
    function closeRecap(){ if(recapScrim) recapScrim.hidden=true; }
    if(catchupBtn) catchupBtn.addEventListener('click', openRecap);
    var recapCloseBtn=document.getElementById('recapClose'); if(recapCloseBtn) recapCloseBtn.addEventListener('click', closeRecap);
    if(recapScrim) recapScrim.addEventListener('click', function(e){ if(e.target===recapScrim) closeRecap(); });

    // ── Saved items ──────────────────────────────────────────────
    var savedScrim=document.getElementById('savedScrim'), savedBody=document.getElementById('savedBody'), savedCloseBtn=document.getElementById('savedClose');
    function openSaved(){ if(!savedScrim) return; savedScrim.hidden=false; savedBody.innerHTML='<p class="pc-empty">Loading…</p>';
      get('saved').then(function(d){ if(!d||!d.ok){ savedBody.innerHTML='<p class="pc-empty">Couldn’t load saved items.</p>'; return; }
        var rows=d.messages||[]; if(!rows.length){ savedBody.innerHTML='<p class="pc-empty">🔖 Nothing saved yet. Hover a message and tap 🏷️ to bookmark it.</p>'; return; }
        savedBody.innerHTML=rows.map(function(m){ return '<div class="tc-saved-item" data-ch="'+esc(m.channel||'')+'" data-id="'+m.id+'"><div class="tc-saved-top"><b>'+esc(m.author)+'</b> <span class="tc-saved-ch">#'+esc(m.channel||'')+'</span> <span class="tc-time">'+esc(fmtTime(m.created_at))+'</span></div><div class="tc-text">'+bodyHtml(m)+'</div><div class="tc-saved-act"><button type="button" class="tc-saved-jump" data-jump="'+m.id+'" data-ch="'+esc(m.channel||'')+'">Jump →</button><button type="button" class="tc-saved-unsave" data-unsave="'+m.id+'">Remove</button></div></div>'; }).join(''); }).catch(function(){ savedBody.innerHTML='<p class="pc-empty">Network error.</p>'; }); }
    function closeSaved(){ if(savedScrim) savedScrim.hidden=true; }
    if(savedBtn) savedBtn.addEventListener('click', openSaved);
    if(savedCloseBtn) savedCloseBtn.addEventListener('click', closeSaved);
    if(savedScrim) savedScrim.addEventListener('click', function(e){ if(e.target===savedScrim) closeSaved(); });
    if(savedBody) savedBody.addEventListener('click', function(e){
      var cp=e.target.closest('.tc-copy'); if(cp){ copyCode(cp); return; }
      var un=e.target.closest('.tc-saved-unsave'); if(un){ var uid=+un.getAttribute('data-unsave'); post('save',{id:uid, saved:false}).then(function(){ var it=un.closest('.tc-saved-item'); if(it) it.remove(); MSGS.concat(THREAD).forEach(function(x){ if(x.id===uid){ x.saved=false; } }); render(); }); return; }
      var jp=e.target.closest('.tc-saved-jump'); if(jp){ var jid=+jp.getAttribute('data-jump'), jch=jp.getAttribute('data-ch'); closeSaved();
        var switched = jch && jch!==CHANNEL; if(switched) switchChannel(jch);
        setTimeout(function(){ var el=streamEl.querySelector('.tc-msg[data-id="'+jid+'"]'); if(el){ el.scrollIntoView({block:'center'}); el.classList.add('tc-flash'); setTimeout(function(){ el.classList.remove('tc-flash'); },1500); } }, switched?650:60); } });

    // ── Channel editor (admins) ──────────────────────────────────
    var chanScrim=document.getElementById('chanScrim'), chanForm=document.getElementById('chanForm'), chanTitle=document.getElementById('chanTitle'),
        chanKeyEl=document.getElementById('chanKey'), chanLabelEl=document.getElementById('chanLabel'), chanTopicEl=document.getElementById('chanTopic'),
        chanPrivEl=document.getElementById('chanPrivate'), chanMemberPick=document.getElementById('chanMemberPick'), chanMemberList=document.getElementById('chanMemberList'),
        chanGchatOnEl=document.getElementById('chanGchatOn'), chanGchatSpaceWrap=document.getElementById('chanGchatSpaceWrap'), chanGchatSpaceEl=document.getElementById('chanGchatSpace'),
        chanSaveBtn=document.getElementById('chanSave'), chanMsg=document.getElementById('chanMsg'), chanCloseBtn=document.getElementById('chanClose');
    function allMembers(){ return (MEMBERS.mentors||[]).concat(MEMBERS.members||[]); }
    function renderMemberPicker(selected){ selected=selected||[]; if(!chanMemberList) return;
      chanMemberList.innerHTML=allMembers().filter(function(u){ return !u.is_me; }).map(function(u){ var on=selected.indexOf(u.id)>-1;
        return '<label class="tc-mp-row"><input type="checkbox" value="'+u.id+'"'+(on?' checked':'')+'> <span class="tc-mp-ava">'+esc(u.initial)+'</span> '+esc(u.name)+'</label>'; }).join('') || '<p class="pc-empty">No other members yet.</p>'; }
    function chanMsgSay(t,tone){ if(!chanMsg)return; chanMsg.hidden=!t; chanMsg.textContent=t||''; chanMsg.className='tc-chan-msg'+(tone?' is-'+tone:''); }
    function openChannelEditor(key){ if(!chanScrim||!IS_ADMIN) return; chanScrim.hidden=false; chanMsgSay('');
      if(key){ var c=CHANNELS.filter(function(x){return x.key===key;})[0]||{}; chanTitle.textContent='Edit #'+key; chanKeyEl.value=key;
        chanLabelEl.value=c.label||''; chanTopicEl.value=TOPICS[key]||c.topic||''; chanPrivEl.checked=!!c.private; chanGchatOnEl.checked=!!c.gchat; chanGchatSpaceEl.value='';
        chanSaveBtn.textContent='Save changes'; chanMemberPick.hidden=!c.private; chanGchatSpaceWrap.hidden=!c.gchat; renderMemberPicker([]);
        if(c.private){ get('channel_members','&channel='+encodeURIComponent(key)).then(function(d){ if(d&&d.ok) renderMemberPicker(d.members||[]); }); }
      } else { chanTitle.textContent='New channel'; chanKeyEl.value=''; chanLabelEl.value=''; chanTopicEl.value=''; chanPrivEl.checked=false; chanGchatOnEl.checked=false; chanGchatSpaceEl.value='';
        chanSaveBtn.textContent='Create channel'; chanMemberPick.hidden=true; chanGchatSpaceWrap.hidden=true; renderMemberPicker([]); }
      setTimeout(function(){ chanLabelEl.focus(); },30); }
    function closeChannelEditor(){ if(chanScrim) chanScrim.hidden=true; }
    if(addChannelBtn) addChannelBtn.addEventListener('click', function(){ openChannelEditor(''); });
    if(chSetBtn) chSetBtn.addEventListener('click', function(){ openChannelEditor(CHANNEL); });
    if(chanCloseBtn) chanCloseBtn.addEventListener('click', closeChannelEditor);
    if(chanScrim) chanScrim.addEventListener('click', function(e){ if(e.target===chanScrim) closeChannelEditor(); });
    if(chanPrivEl) chanPrivEl.addEventListener('change', function(){ chanMemberPick.hidden=!chanPrivEl.checked; if(chanPrivEl.checked) renderMemberPicker(collectMembers()); });
    if(chanGchatOnEl) chanGchatOnEl.addEventListener('change', function(){ chanGchatSpaceWrap.hidden=!chanGchatOnEl.checked; });
    function collectMembers(){ if(!chanMemberList) return []; return [].map.call(chanMemberList.querySelectorAll('input:checked'), function(i){ return +i.value; }); }
    if(chanForm) chanForm.addEventListener('submit', function(e){ e.preventDefault();
      var label=(chanLabelEl.value||'').trim(); if(!label){ chanMsgSay('Enter a channel name.','warn'); return; }
      var key=chanKeyEl.value, payload={label:label, topic:(chanTopicEl.value||'').trim(), private:chanPrivEl.checked, gchat_on:chanGchatOnEl.checked};
      if(chanGchatOnEl.checked && (chanGchatSpaceEl.value||'').trim()) payload.gchat_space=(chanGchatSpaceEl.value||'').trim();
      if(chanPrivEl.checked) payload.members=collectMembers();
      chanSaveBtn.disabled=true;
      var action = key ? 'channel_update' : 'channel_create'; if(key) payload.channel=key;
      post(action, payload).then(function(d){ chanSaveBtn.disabled=false;
        if(d&&d.ok){ CHANNELS=d.channels||CHANNELS; renderChannels(); closeChannelEditor(); if(d.channel&&!key){ switchChannel(d.channel.key); } else if(key){ setTopic(); bootstrap(); } }
        else { chanMsgSay((d&&d.error)||'Could not save the channel.','warn'); } }).catch(function(){ chanSaveBtn.disabled=false; chanMsgSay('Network error.','warn'); }); });

    // Message interactions (event-delegated) — shared by the stream and thread panel.
    function onMsgClick(e){
      var cp=e.target.closest('.tc-copy'); if(cp){ copyCode(cp); return; }
      var msgEl=e.target.closest('.tc-msg'); if(!msgEl) return; var id=+msgEl.getAttribute('data-id');
      var chip=e.target.closest('.tc-react'); if(chip){ react(id, chip.getAttribute('data-emoji')); return; }
      var add=e.target.closest('.tc-react-add'); if(add){ openEmojiPicker(add, id); return; }
      var act=e.target.closest('.tc-act'); if(act){ var a=act.getAttribute('data-act');
        if(a==='reply') openThread(id); else if(a==='react') openEmojiPicker(act, id); else if(a==='assign') openAssign(id, msgEl); else if(a==='pin') doPin(id);
        else if(a==='save') doSave(id); else if(a==='edit') enterEdit(id); else if(a==='delete') doDelete(id); return; }
      var sum=e.target.closest('.tc-thread-sum'); if(sum){ openThread(+sum.getAttribute('data-thread')); } }
    streamEl.addEventListener('click', onMsgClick);
    // Pin / unpin a message; the endpoint returns the fresh pin list.
    function doPin(id){ var m=MSGS.filter(function(x){return x.id===id;})[0]; var want=!(m&&m.pinned);
      post('pin',{id:id, pinned:want, channel:CHANNEL}).then(function(d){ if(!d||!d.ok) return;
        if(m){ m.pinned=d.pinned; } PINS=d.pins||PINS; if(want) PINS_OPEN=true; render(); renderPins(); }); }
    // Save / unsave (bookmark) a message.
    function doSave(id){ var m=MSGS.concat(THREAD).filter(function(x){return x.id===id;})[0]; var want=!(m&&m.saved);
      post('save',{id:id, saved:want}).then(function(d){ if(!d||!d.ok) return; MSGS.concat(THREAD).forEach(function(x){ if(x.id===id) x.saved=d.saved; }); render(); if(OPEN_THREAD) renderThread(); }); }
    // Soft-delete a message (retained, compressed, in the trash — never truly gone).
    function doDelete(id){ if(!window.confirm('Delete this message? It’s retained in the trash and can’t be seen by others.')) return;
      post('delete',{id:id}).then(function(d){ if(d&&d.ok&&d.message){ replaceMsg(d.message); if(EDITING===id) exitEdit(); } }); }
    // Header pin button toggles the pinned banner.
    if(pinBtn) pinBtn.addEventListener('click', function(){ PINS_OPEN=!PINS_OPEN; renderPins(); });
    // Pinned banner: unpin or jump-to-message.
    if(pinnedEl) pinnedEl.addEventListener('click', function(e){
      var un=e.target.closest('.tc-pin-x'); if(un){ var uid=+un.getAttribute('data-unpin'); post('pin',{id:uid, pinned:false, channel:CHANNEL}).then(function(d){ if(d&&d.ok){ var mm=MSGS.filter(function(x){return x.id===uid;})[0]; if(mm)mm.pinned=false; PINS=d.pins||[]; render(); renderPins(); } }); return; }
      var jp=e.target.closest('.tc-pin-jump'); if(jp){ var jid=+jp.getAttribute('data-jump'); var el=streamEl.querySelector('.tc-msg[data-id="'+jid+'"]'); if(el){ el.scrollIntoView({behavior:'smooth', block:'center'}); el.classList.add('tc-flash'); setTimeout(function(){ el.classList.remove('tc-flash'); }, 1500); } } });
    function react(id, emoji){ post('react',{id:id, emoji:emoji}).then(function(d){ if(!d||!d.ok) return;
      var m=MSGS.filter(function(x){return x.id===id;})[0]; if(m){ m.reactions=d.reactions; render(); }
      var tm=THREAD.filter(function(x){return x.id===id;})[0]; if(tm){ tm.reactions=d.reactions; }
      if(OPEN_THREAD===id || tm) renderThread(); }); }
    function copyCode(btn){ var pre=btn.parentNode.querySelector('.tc-code'); if(!pre) return; var text=pre.textContent||'';
      var done=function(){ var o=btn.textContent; btn.textContent='Copied ✓'; btn.classList.add('is-done'); setTimeout(function(){ btn.textContent=o; btn.classList.remove('is-done'); },1400); };
      if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(text).then(done).catch(function(){ fallback(text); done(); }); }
      else { fallback(text); done(); }
      function fallback(t){ try{ var ta=document.createElement('textarea'); ta.value=t; ta.style.position='fixed'; ta.style.opacity='0'; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); }catch(e){} } }
    var pickerEl=null;
    function openEmojiPicker(anchor, id){ closePicker(); pickerEl=document.createElement('div'); pickerEl.className='tc-picker';
      pickerEl.innerHTML=EMOJI.map(function(x){ return '<button type="button" data-e="'+esc(x)+'">'+esc(x)+'</button>'; }).join('');
      // Anchor the picker inside the message so hovering it keeps the message
      // (and its action bar) alive; position it just under the clicked button.
      var host=anchor.closest('.tc-msg')||anchor.parentNode; host.appendChild(pickerEl);
      try{ var ar=anchor.getBoundingClientRect(), hr=host.getBoundingClientRect();
        pickerEl.style.top=(ar.bottom-hr.top+4)+'px'; pickerEl.style.left=Math.max(4,(ar.left-hr.left))+'px'; }catch(e){}
      pickerEl.addEventListener('click', function(e){ var b=e.target.closest('button'); if(b){ react(id, b.getAttribute('data-e')); closePicker(); } });
      setTimeout(function(){ document.addEventListener('click', outside); },0);
      function outside(ev){ if(pickerEl && !pickerEl.contains(ev.target) && ev.target!==anchor){ closePicker(); document.removeEventListener('click', outside); } } }
    function closePicker(){ if(pickerEl){ pickerEl.remove(); pickerEl=null; } }

    // ── Threads ──────────────────────────────────────────────────
    var threadPanel=document.getElementById('tcThread'), threadBody=document.getElementById('tcThreadBody'),
        threadForm=document.getElementById('tcThreadForm'), threadInput=document.getElementById('tcThreadInput');
    var OPEN_THREAD=0, THREAD=[], tLast=0, threadReady=false;
    function renderThread(){ if(!OPEN_THREAD) return;
      var parent=MSGS.filter(function(x){return x.id===OPEN_THREAD;})[0];
      var top = parent ? '<div class="tc-thread-parent">'+msgHtml(parent,false)+'</div>' : '';
      var count = THREAD.length ? '<div class="tc-thread-count">'+THREAD.length+' repl'+(THREAD.length===1?'y':'ies')+'</div>' : '';
      var reps = THREAD.length ? THREAD.map(function(m){return msgHtml(m,false);}).join('') : '<p class="pc-empty">No replies yet — start the thread.</p>';
      threadBody.innerHTML = top+count+reps; threadBody.scrollTop=threadBody.scrollHeight; }
    function openThread(id){ OPEN_THREAD=id; THREAD=[]; tLast=0; threadReady=false; threadPanel.hidden=false;
      threadBody.innerHTML='<p class="pc-empty">Loading…</p>'; renderThread();
      get('thread','&parent='+id+'&since=0').then(function(d){ if(!d||!d.ok||OPEN_THREAD!==id) return; THREAD=d.replies||[]; tLast=THREAD.length?THREAD[THREAD.length-1].id:0; threadReady=true; renderThread(); if(threadRTE) threadRTE.focus(); }); }
    function closeThread(){ OPEN_THREAD=0; THREAD=[]; threadPanel.hidden=true; }
    function pollThread(){ if(!OPEN_THREAD||!threadReady) return; get('thread','&parent='+OPEN_THREAD+'&since='+tLast).then(function(d){ if(!d||!d.ok||!d.replies||!d.replies.length) return;
      var added=false; d.replies.forEach(function(m){ if(m.id>tLast){THREAD.push(m);tLast=m.id;added=true;} }); if(added) renderThread(); }); }
    document.getElementById('tcThreadClose').addEventListener('click', closeThread);
    threadBody.addEventListener('click', onMsgClick);
    var threadRTE = attachRichEditor(threadInput, threadForm, document.getElementById('tcThreadMentions'), {
      onSubmit: function(){ threadForm.requestSubmit(); }
    });
    threadForm.addEventListener('submit', function(e){ e.preventDefault(); if(!OPEN_THREAD) return; var b=threadRTE.getMarkdown().trim(); if(!b) return;
      threadRTE.clear();
      post('send',{channel:CHANNEL, body:b, parent_id:OPEN_THREAD}).then(function(d){ if(!d||!d.ok||!d.message) return;
        if(d.message.id>tLast){ THREAD.push(d.message); tLast=d.message.id; }
        var p=MSGS.filter(function(x){return x.id===OPEN_THREAD;})[0]; if(p){ p.reply_count=(p.reply_count||0)+1; p.last_reply='just now'; render(); }
        renderThread(); }).catch(function(){}); });

    // ── Assign a message as a task (the @mention → task-assignment bridge) ──
    var assignPop=null;
    function closeAssign(){ if(assignPop){ assignPop.remove(); assignPop=null; document.removeEventListener('click', assignOutside); } }
    function assignOutside(e){ if(assignPop && !assignPop.contains(e.target) && !e.target.closest('[data-act="assign"]')) closeAssign(); }
    function openAssign(id, anchorEl){ closeAssign();
      var m=MSGS.concat(THREAD).filter(function(x){return x.id===id;})[0]; if(!m) return;
      var mentions=m.mentions||[];
      var opts='<option value="0">Me</option>'+mentions.map(function(mn){ return '<option value="'+mn.id+'">'+esc(mn.name)+'</option>'; }).join('');
      var pop=document.createElement('div'); pop.className='tc-assign'; assignPop=pop;
      pop.innerHTML='<div class="tc-assign-h">Assign as task</div>'
        +'<input type="text" class="tc-assign-title" maxlength="300" placeholder="Task title">'
        +'<div class="tc-assign-row"><label>To <select class="tc-assign-who">'+opts+'</select></label><label>Due <input type="date" class="tc-assign-due"></label></div>'
        +'<div class="tc-assign-actions"><button type="button" class="pbtn pbtn-ghost tc-assign-cancel">Cancel</button><button type="button" class="pbtn pbtn-gold tc-assign-go">Assign</button></div>'
        +'<p class="tc-assign-msg" hidden></p>';
      anchorEl.appendChild(pop);
      pop.querySelector('.tc-assign-title').value = (m.body||'').slice(0,120);
      var who=pop.querySelector('.tc-assign-who'); if(mentions.length) who.value=String(mentions[0].id);
      pop.querySelector('.tc-assign-cancel').addEventListener('click', closeAssign);
      pop.querySelector('.tc-assign-go').addEventListener('click', function(){
        var title=(pop.querySelector('.tc-assign-title').value||'').trim(); var pmsg=pop.querySelector('.tc-assign-msg');
        if(!title){ pmsg.hidden=false; pmsg.className='tc-assign-msg is-warn'; pmsg.textContent='Add a title.'; return; }
        var go=pop.querySelector('.tc-assign-go'); go.disabled=true; go.textContent='Assigning…';
        post('assign',{title:title, assignee:+who.value||0, due:pop.querySelector('.tc-assign-due').value}).then(function(d){
          if(d&&d.ok){ pmsg.hidden=false; pmsg.className='tc-assign-msg is-ok'; pmsg.textContent=(+who.value>0?'Assigned — they’ll get an email. ✓':'Added to your tasks. ✓'); setTimeout(closeAssign,1200); }
          else { go.disabled=false; go.textContent='Assign'; pmsg.hidden=false; pmsg.className='tc-assign-msg is-warn'; pmsg.textContent=(d&&d.error)||'Could not assign.'; } }).catch(function(){ go.disabled=false; go.textContent='Assign'; }); });
      setTimeout(function(){ document.addEventListener('click', assignOutside); },0);
    }

    // ── attachRichEditor — a small WYSIWYG contenteditable editor ────
    // Shows formatted text live (never raw markdown), a modern formatting
    // toolbar with active-state highlighting, @mention chips, and clean
    // markdown serialisation on send/edit. Reused by the main + thread composers.
    function attachRichEditor(el, form, dropEl, opts){
      opts=opts||{};
      function setEmpty(){ var e=isEmpty(); el.classList.toggle('is-empty', e); }
      function isEmpty(){ return el.textContent.replace(/ /g,' ').trim()==='' && !el.querySelector('pre,ul,ol,li,img,.tc-at,code,a'); }
      function focus(){ el.focus(); placeCaretEnd(); }
      function placeCaretEnd(){ try{ var r=document.createRange(); r.selectNodeContents(el); r.collapse(false); var s=window.getSelection(); s.removeAllRanges(); s.addRange(r); }catch(e){} }

      // Keep pasted content plain — no foreign fonts/colours leaking in.
      el.addEventListener('paste', function(e){ e.preventDefault(); var t=((e.clipboardData||window.clipboardData).getData('text/plain'))||''; document.execCommand('insertText', false, t); });

      // ── formatting commands ──
      function surround(tag, ph){ var sel=window.getSelection(); if(!sel.rangeCount){ el.focus(); sel=window.getSelection(); if(!sel.rangeCount) return; }
        var range=sel.getRangeAt(0); var node=document.createElement(tag);
        if(range.collapsed){ node.textContent=ph||''; range.insertNode(node); var r=document.createRange(); r.selectNodeContents(node); sel.removeAllRanges(); sel.addRange(r); }
        else { node.appendChild(range.extractContents()); range.insertNode(node); var r2=document.createRange(); r2.selectNodeContents(node); sel.removeAllRanges(); sel.addRange(r2); } }
      function insertBlock(node){ var sel=window.getSelection(); if(!sel.rangeCount){ el.appendChild(node); return; }
        var range=sel.getRangeAt(0); range.deleteContents(); range.insertNode(node);
        var after=document.createElement('div'); after.appendChild(document.createElement('br')); node.parentNode.insertBefore(after, node.nextSibling);
        var r=document.createRange(); r.selectNodeContents(node); r.collapse(true); sel.removeAllRanges(); sel.addRange(r); }
      function ancestor(tagRe){ var sel=window.getSelection(); if(!sel.rangeCount) return null; var n=sel.getRangeAt(0).startContainer;
        while(n && n!==el){ if(n.nodeType===1 && tagRe.test(n.nodeName)) return n; n=n.parentNode; } return null; }
      function exec(cmd){ el.focus();
        if(cmd==='bold'||cmd==='italic') document.execCommand(cmd,false,null);
        else if(cmd==='strike') document.execCommand('strikeThrough',false,null);
        else if(cmd==='ul') document.execCommand('insertUnorderedList',false,null);
        else if(cmd==='ol') document.execCommand('insertOrderedList',false,null);
        else if(cmd==='quote'){ if(ancestor(/^BLOCKQUOTE$/)) document.execCommand('formatBlock',false,'div'); else document.execCommand('formatBlock',false,'blockquote'); }
        else if(cmd==='code') surround('code','code');
        else if(cmd==='codeblock'){ var pre=document.createElement('pre'); var sel=window.getSelection(); var txt=(sel && sel.toString())||''; pre.textContent=txt||'code'; insertBlock(pre); }
        else if(cmd==='link'){ var sel=window.getSelection(); var text=(sel && sel.toString())||''; var url=window.prompt('Link URL', 'https://'); if(!url) return; url=url.trim(); if(!/^https?:\/\//i.test(url)) url='https://'+url.replace(/^\/+/,'');
          if(text){ var a=document.createElement('a'); a.href=url; a.textContent=text; if(sel.rangeCount){ var rr=sel.getRangeAt(0); rr.deleteContents(); rr.insertNode(a); } }
          else { var a2=document.createElement('a'); a2.href=url; a2.textContent=url; insertInline(a2); } }
        setEmpty(); updateToolbar(); if(opts.onInput) opts.onInput(); }
      function insertInline(node){ var sel=window.getSelection(); if(!sel.rangeCount){ el.appendChild(node); return; } var r=sel.getRangeAt(0); r.deleteContents(); r.insertNode(node); r.setStartAfter(node); r.collapse(true); sel.removeAllRanges(); sel.addRange(r); }

      // Toolbar buttons live inside the form; highlight the active formats.
      var btns=[].slice.call(form.querySelectorAll('.tc-fmt'));
      btns.forEach(function(b){ b.addEventListener('mousedown', function(e){ e.preventDefault(); }); b.addEventListener('click', function(e){ e.preventDefault(); exec(b.getAttribute('data-cmd')); }); });
      function state(cmd){ try{ return document.queryCommandState(cmd); }catch(e){ return false; } }
      function updateToolbar(){ if(!btns.length) return; var inEl = (function(){ var s=window.getSelection(); if(!s.rangeCount) return false; var n=s.getRangeAt(0).startContainer; while(n){ if(n===el) return true; n=n.parentNode; } return false; })();
        btns.forEach(function(b){ var c=b.getAttribute('data-cmd'), on=false; if(inEl){
          if(c==='bold') on=state('bold'); else if(c==='italic') on=state('italic'); else if(c==='strike') on=state('strikeThrough');
          else if(c==='ul') on=state('insertUnorderedList'); else if(c==='ol') on=state('insertOrderedList');
          else if(c==='quote') on=!!ancestor(/^BLOCKQUOTE$/); else if(c==='code') on=!!ancestor(/^CODE$/); else if(c==='codeblock') on=!!ancestor(/^PRE$/); }
          b.classList.toggle('is-on', on); }); }
      document.addEventListener('selectionchange', function(){ updateToolbar(); });

      // ── @mention autocomplete (chips) ──
      var mOpen=false, mTimer=null, mActive=-1, mItems=[], mSaved=null;
      function mHide(){ if(dropEl){ dropEl.hidden=true; } mOpen=false; mActive=-1; mItems=[]; mSaved=null; }
      function mRender(){ if(!dropEl) return; dropEl.innerHTML=mItems.map(function(m,i){ return '<button type="button" class="tc-ment'+(i===0?' is-on':'')+'" data-h="'+esc(m.handle)+'" data-n="'+esc(m.name)+'"><span class="tc-ment-ava">'+esc(m.initial)+'</span>'+esc(m.name)+' <span class="tc-ment-h">@'+esc(m.handle)+'</span></button>'; }).join(''); dropEl.hidden=false; mOpen=true; mActive=0; }
      function caretAt(){ var sel=window.getSelection(); if(!sel.rangeCount) return null; var range=sel.getRangeAt(0); if(!range.collapsed) return null; var node=range.startContainer; if(node.nodeType!==3) return null;
        var before=node.nodeValue.slice(0, range.startOffset); var m=before.match(/(?:^|\s)@([\w.\-]*)$/); if(!m) return null; return {node:node, end:range.startOffset, start:range.startOffset-m[1].length-1, q:m[1]}; }
      function mPick(handle, name){ var cq=mSaved; mHide(); if(!cq) return; try{
        var r=document.createRange(); r.setStart(cq.node, cq.start); r.setEnd(cq.node, cq.end); r.deleteContents();
        var chip=document.createElement('span'); chip.className='tc-at'; chip.setAttribute('contenteditable','false'); chip.setAttribute('data-h', handle); chip.textContent='@'+name;
        r.insertNode(chip); var sp=document.createTextNode(' '); chip.parentNode.insertBefore(sp, chip.nextSibling);
        var nr=document.createRange(); nr.setStartAfter(sp); nr.collapse(true); var s=window.getSelection(); s.removeAllRanges(); s.addRange(nr);
      }catch(e){} setEmpty(); if(opts.onInput) opts.onInput(); }
      if(dropEl) dropEl.addEventListener('mousedown', function(e){ var b=e.target.closest('.tc-ment'); if(b){ e.preventDefault(); mPick(b.getAttribute('data-h'), b.getAttribute('data-n')); } });

      el.addEventListener('input', function(){ setEmpty(); if(opts.onInput) opts.onInput();
        var cq=caretAt(); if(!cq){ mHide(); return; } mSaved=cq; var q=cq.q; clearTimeout(mTimer);
        mTimer=setTimeout(function(){ get('mention','&q='+encodeURIComponent(q)).then(function(d){ if(!d||!d.ok||!d.members.length){ mHide(); return; } mItems=d.members; mRender(); }).catch(mHide); }, 130); });

      el.addEventListener('keydown', function(e){
        if(mOpen){ if(e.key==='ArrowDown'||e.key==='ArrowUp'){ e.preventDefault(); mActive=(mActive+(e.key==='ArrowDown'?1:mItems.length-1))%mItems.length; [].forEach.call(dropEl.children,function(c,i){ c.classList.toggle('is-on',i===mActive); }); return; }
          if(e.key==='Enter'||e.key==='Tab'){ e.preventDefault(); var it=mItems[mActive]; if(it) mPick(it.handle, it.name); return; }
          if(e.key==='Escape'){ e.preventDefault(); mHide(); return; } }
        // Keyboard formatting shortcuts.
        if((e.ctrlKey||e.metaKey) && !e.shiftKey){ var k=e.key.toLowerCase(); if(k==='b'){ e.preventDefault(); exec('bold'); return; } if(k==='i'){ e.preventDefault(); exec('italic'); return; } }
        // Enter sends; Shift+Enter is a newline.
        if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); if(opts.onSubmit) opts.onSubmit(); }
      });

      // ── markdown round-trip ──
      var BLOCK={DIV:1,P:1};
      function ser(node){ var out='';
        for(var i=0;i<node.childNodes.length;i++){ var ch=node.childNodes[i];
          if(ch.nodeType===3){ out+=ch.nodeValue.replace(/ /g,' ').replace(/\n/g,' '); continue; }
          if(ch.nodeType!==1) continue; var tag=ch.nodeName;
          if(ch.classList && ch.classList.contains('tc-at')){ out+='@'+(ch.getAttribute('data-h')||ch.textContent.replace(/^@/,'')); continue; }
          if(tag==='BR'){ out+='\n'; continue; }
          if(tag==='B'||tag==='STRONG'){ var t=ser(ch).trim(); if(t) out+='**'+t+'**'; continue; }
          if(tag==='I'||tag==='EM'){ var t2=ser(ch).trim(); if(t2) out+='*'+t2+'*'; continue; }
          if(tag==='S'||tag==='STRIKE'||tag==='DEL'){ var t3=ser(ch).trim(); if(t3) out+='~~'+t3+'~~'; continue; }
          if(tag==='CODE'){ out+='`'+ch.textContent+'`'; continue; }
          if(tag==='A'){ out+='['+ser(ch).trim()+']('+(ch.getAttribute('href')||'')+')'; continue; }
          if(tag==='PRE'){ out=nl(out)+'```\n'+ch.textContent.replace(/\n+$/,'')+'\n```\n'; continue; }
          if(tag==='BLOCKQUOTE'){ var q=ser(ch).trim().split('\n').map(function(l){return '> '+l;}).join('\n'); out=nl(out)+q+'\n'; continue; }
          if(tag==='UL'||tag==='OL'){ out=nl(out); var n=1; [].forEach.call(ch.children,function(li){ if(li.nodeName==='LI') out+=(tag==='OL'?(n++)+'. ':'- ')+ser(li).trim()+'\n'; }); continue; }
          if(BLOCK[tag]){ out=nl(out)+ser(ch); continue; }
          out+=ser(ch);
        }
        return out; }
      function nl(s){ return (s===''||/\n$/.test(s))? s : s+'\n'; }
      function getMarkdown(){ return ser(el).replace(/[ \t]+\n/g,'\n').replace(/\n{3,}/g,'\n\n').trim(); }

      // markdown → editable HTML (used when loading a message to edit).
      function mdToHtml(md){ var S='', E=''; var raw=String(md||'');
        var fen=[]; raw=raw.replace(/```([\s\S]*?)```/g, function(_,c){ fen.push(c.replace(/^\n/,'').replace(/\n+$/,'')); return S+'F'+(fen.length-1)+E; });
        var inl=[]; raw=raw.replace(/`([^`\n]+)`/g, function(_,c){ inl.push(c); return S+'I'+(inl.length-1)+E; });
        function inline(s){ s=esc(s);
          s=s.replace(/\*\*([^*\n]+)\*\*/g,'<b>$1</b>').replace(/~~([^~\n]+)~~/g,'<s>$1</s>')
             .replace(/(^|[^\w*])\*([^*\n]+)\*(?!\w)/g,'$1<i>$2</i>').replace(/(^|[^\w_])_([^_\n]+)_(?!\w)/g,'$1<i>$2</i>');
          s=s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,'<a href="$2">$1</a>');
          s=s.replace(new RegExp(S+'I(\\d+)'+E,'g'), function(_,i){ return '<code>'+esc(inl[+i])+'</code>'; });
          return s; }
        var rows=raw.split('\n'), out='', inUl=false, inOl=false; var fenRe=new RegExp('^'+S+'F(\\d+)'+E+'$');
        function closeL(){ if(inUl){out+='</ul>';inUl=false;} if(inOl){out+='</ol>';inOl=false;} }
        rows.forEach(function(l){ var fm=l.match(fenRe);
          if(fm){ closeL(); out+='<pre>'+esc(fen[+fm[1]])+'</pre>'; return; }
          if(/^\s*[-*]\s+/.test(l)){ if(inOl){out+='</ol>';inOl=false;} if(!inUl){out+='<ul>';inUl=true;} out+='<li>'+inline(l.replace(/^\s*[-*]\s+/,''))+'</li>'; return; }
          if(/^\s*\d+\.\s+/.test(l)){ if(inUl){out+='</ul>';inUl=false;} if(!inOl){out+='<ol>';inOl=true;} out+='<li>'+inline(l.replace(/^\s*\d+\.\s+/,''))+'</li>'; return; }
          closeL();
          if(/^\s*>\s?/.test(l)){ out+='<blockquote>'+inline(l.replace(/^\s*>\s?/,''))+'</blockquote>'; return; }
          if(l.trim()===''){ out+='<div><br></div>'; return; }
          out+='<div>'+inline(l)+'</div>'; });
        closeL(); return out; }

      function setMarkdown(md){ el.innerHTML=mdToHtml(md); setEmpty(); }
      function clear(){ el.innerHTML=''; setEmpty(); mHide(); }
      setEmpty();
      return { getMarkdown:getMarkdown, setMarkdown:setMarkdown, clear:clear, focus:focus, isEmpty:isEmpty, mentionsOpen:function(){ return mOpen; } };
    }

    // Cheap background refresh of channel state (for the nav unread badge) when
    // the Chat view ISN'T open — a message-less poll that still returns channels.
    function refreshChannelsBg(){ get('poll','&channel='+encodeURIComponent(CHANNEL)+'&since=999999999').then(function(d){ if(d&&d.ok){ CHANNELS=d.channels||CHANNELS; updateNavBadge(); } }).catch(function(){}); }
    // Fast 4s polling while the Chat view is visible; a slower (~24s) background
    // channel check otherwise, so the unread badge stays roughly live everywhere.
    var bg=0;
    function tick(){ var v=document.getElementById('view-chat');
      if(v && !v.hidden){ if(!active){ active=true; bootstrap(); } poll(); pollThread(); }
      else { active=false; if((++bg % 6) === 0) refreshChannelsBg(); } }
    window.addEventListener('hashchange', function(){ setTimeout(tick, 60); });
    if(location.hash.indexOf('chat')>-1){ active=true; bootstrap(); } else { refreshChannelsBg(); }
    poller=setInterval(tick, 4000);
  })();
