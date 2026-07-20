/* team-chat.js — live Google Chat panel (spaces + messages + send + unread).
   Self-initialises on #chatCard (portal + /workspace). Near-live via polling. */
(function () {
  'use strict';
  var card = document.getElementById('chatCard'); if (!card) return;
  var csrf = card.getAttribute('data-csrf') || '';
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function $(id){ return document.getElementById(id); }
  var spacesEl=$('chatSpaces'), threadEl=$('chatThread'), form=$('chatCompose'), input=$('chatInput'),
      countEl=$('chatSpaceCount'), unreadEl=$('chatUnreadBadge');
  if (!spacesEl || !threadEl) return;
  var curSpace=null, poll=null, SPACES=[], UNREAD={};

  function tfmt(iso){ var t=Date.parse(iso); if(!t) return ''; var d=new Date(t); return d.toLocaleTimeString(undefined,{hour:'numeric',minute:'2-digit'}); }

  function renderSpaces(){
    if(!SPACES.length){ spacesEl.innerHTML='<p class="pc-empty">No spaces yet. <a href="https://chat.google.com/" target="_blank" rel="noopener">Open Chat ↗</a></p>'; return; }
    spacesEl.innerHTML = SPACES.map(function(s){
      var dot = UNREAD[s.id] ? '<span class="chat-unread-dot" aria-label="Unread"></span>' : '';
      return '<button type="button" class="chat-space'+(s.id===curSpace?' is-on':'')+(UNREAD[s.id]?' has-unread':'')+'" data-id="'+esc(s.id)+'">'+dot+'<span class="chat-space-name">'+esc(s.label)+'</span></button>';
    }).join('');
    if (countEl) { countEl.textContent = SPACES.length; countEl.hidden = !SPACES.length; }
    refreshUnreadBadge();
  }
  function refreshUnreadBadge(){
    if (!unreadEl) return;
    var n = SPACES.reduce(function(a,s){ return a + (UNREAD[s.id]?1:0); }, 0);
    unreadEl.textContent = n; unreadEl.hidden = n === 0;
  }
  function renderMsgs(list){
    list = list || [];
    threadEl.innerHTML = list.length ? list.map(function(m){
      return '<div class="chat-msg"><div class="chat-msg-h"><b>'+esc(m.sender)+'</b><span>'+esc(tfmt(m.ts))+'</span></div><div class="chat-msg-b">'+esc(m.text).replace(/\n/g,'<br>')+'</div></div>';
    }).join('') : '<p class="pc-empty">No messages yet — say hello.</p>';
    threadEl.scrollTop = threadEl.scrollHeight;
  }
  function loadMsgs(){
    if(!curSpace) return;
    fetch('/portal/workspace.php?action=chat_messages&space='+encodeURIComponent(curSpace),{credentials:'same-origin'})
      .then(function(r){return r.json();}).then(function(d){ if(d&&d.ok) renderMsgs(d.messages); }).catch(function(){});
  }
  function openSpace(id){
    curSpace=id;
    if (UNREAD[id]) { UNREAD[id]=false; refreshUnreadBadge(); }
    [].forEach.call(spacesEl.querySelectorAll('.chat-space'),function(b){ var on=b.getAttribute('data-id')===id; b.classList.toggle('is-on',on); if(on) b.classList.remove('has-unread'); });
    if (form) form.hidden=false;
    threadEl.innerHTML='<p class="pc-empty">Loading messages…</p>'; loadMsgs();
    clearInterval(poll); poll=setInterval(function(){ if(!document.hidden) loadMsgs(); }, 8000);
  }
  spacesEl.addEventListener('click', function(e){ var b=e.target.closest('.chat-space'); if(b) openSpace(b.getAttribute('data-id')); });

  var noteEl=null;
  function sayChat(msg, reconnect){
    if(!form) return;
    if(!noteEl){ noteEl=document.createElement('p'); noteEl.className='ws-chat-note'; form.parentNode.insertBefore(noteEl, form.nextSibling); }
    noteEl.innerHTML = esc(msg) + (reconnect ? ' <a href="/auth/google/connect?next=/workspace">Reconnect Google →</a>' : '');
    noteEl.hidden = !msg;
  }
  if(form) form.addEventListener('submit', function(e){
    e.preventDefault(); var t=(input.value||'').trim(); if(!t||!curSpace) return;
    input.disabled=true; sayChat('');
    fetch('/portal/workspace.php?action=chat_send',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({space:curSpace,text:t})})
      .then(function(r){return r.json();}).then(function(d){
        input.disabled=false; input.focus();
        if(d&&d.ok){ input.value=''; loadMsgs(); return; }   // keep the text if it failed
        sayChat((d&&d.error)||'Could not send.', !!(d&&d.reconnect));
      }).catch(function(){ input.disabled=false; sayChat('Network error — try again.', false); });
  });
  // Load spaces, then (non-blocking) their unread state.
  fetch('/portal/workspace.php?action=chat_spaces',{credentials:'same-origin'}).then(function(r){return r.json();})
    .then(function(d){
      if(d&&d.ok){ SPACES=d.spaces||[]; renderSpaces();
        fetch('/portal/workspace.php?action=chat_unread',{credentials:'same-origin'}).then(function(r){return r.json();})
          .then(function(u){ if(u&&u.ok&&u.unread){ UNREAD=u.unread; renderSpaces(); } }).catch(function(){});
      } else { spacesEl.innerHTML='<p class="pc-empty">Chat unavailable.</p>'; }
    }).catch(function(){ spacesEl.innerHTML='<p class="pc-empty">Chat unavailable.</p>'; });
  document.addEventListener('visibilitychange', function(){ if(!document.hidden) loadMsgs(); });
})();
