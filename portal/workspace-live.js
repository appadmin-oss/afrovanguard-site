/* portal/workspace-live.js — moved verbatim from the inline script of portal/index.php v1
   (row 14 shell rebuild). Its logic is unchanged; it reads the same ids and data-* hooks. */
  /* Google Workspace — live snapshot once connected (real difference post-connect). */
  (function () {
    var box = document.getElementById('wsLive'); if (!box) return;
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function whenFmt(iso){ var t=Date.parse(iso); if(!t) return esc(iso||''); var d=new Date(t); return d.toLocaleDateString(undefined,{month:'short',day:'numeric'})+' · '+d.toLocaleTimeString(undefined,{hour:'numeric',minute:'2-digit'}); }
    var $=function(id){return document.getElementById(id);};
    fetch('/portal/workspace.php?action=me',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(!d||!d.ok||!d.mine) return; var m=d.mine;
      if($('wsUnread')) $('wsUnread').textContent = (m.unread==null?'—':m.unread);
      if($('wsFiles')) $('wsFiles').textContent = (m.files?m.files.length:0);
      var ev=(m.events||[])[0];
      if(ev){ if($('wsNextC')) $('wsNextC').textContent=ev.title||'Event'; if($('wsNextW')) $('wsNextW').textContent=whenFmt(ev.start); }
      else { if($('wsNextC')) $('wsNextC').textContent='Nothing scheduled'; if($('wsNextW')) $('wsNextW').textContent=''; }
      var mail=$('wsMail'); if(mail){ var ms=m.mail||[]; mail.innerHTML = ms.length ? ms.map(function(x){ return '<li class="ws-li'+(x.unread?' is-unread':'')+'"><a href="'+esc(x.url)+'" target="_blank" rel="noopener"><span class="ws-li-from">'+esc(x.from)+'</span><span class="ws-li-sub">'+esc(x.subject)+'</span></a></li>'; }).join('') : '<li class="pc-empty">Inbox is clear.</li>'; }
      var evl=$('wsEvents'); if(evl){ var es=m.events||[]; evl.innerHTML = es.length ? es.map(function(x){ return '<li class="ws-li"><a href="'+esc(x.url||x.meet_url||'#')+'" target="_blank" rel="noopener"><span class="ws-li-sub">'+esc(x.title)+'</span><span class="ws-li-when">'+whenFmt(x.start)+'</span></a></li>'; }).join('') : '<li class="pc-empty">No upcoming events.</li>'; }
    }).catch(function(){});
  })();

