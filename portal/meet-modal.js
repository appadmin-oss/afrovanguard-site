/* portal/meet-modal.js — moved verbatim from the inline script of portal/index.php v1
   (row 14 shell rebuild). Its logic is unchanged; it reads the same ids and data-* hooks. */
  /* Schedule-a-meeting modal → creates a Google Meet + calendar invite via
     portal/meetings.php, then refreshes the Today calendar agenda. */
  (function () {
    var scrim=document.getElementById('meetScrim'), modal=document.getElementById('meetModal'); if(!scrim||!modal) return;
    var csrf=modal.getAttribute('data-csrf')||'', msg=document.getElementById('mmMsg');
    function open(){ scrim.hidden=false; document.body.style.overflow='hidden'; var w=document.getElementById('mmWhen'); if(w&&!w.value){ var d=new Date(Date.now()+3600000); d.setMinutes(0); w.value=d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2)+'T'+('0'+d.getHours()).slice(-2)+':00'; } document.getElementById('mmTitle').focus(); }
    function close(){ scrim.hidden=true; document.body.style.overflow=''; if(msg)msg.hidden=true; }
    function say(t,tone){ if(!msg)return; msg.hidden=!t; msg.textContent=t||''; msg.className='pm-msg'+(tone?' is-'+tone:''); }
    var openBtn=document.getElementById('openMeetModal'); if(openBtn) openBtn.addEventListener('click', open);
    document.getElementById('meetClose').addEventListener('click', close);
    document.getElementById('meetCancel').addEventListener('click', close);
    scrim.addEventListener('click', function(e){ if(e.target===scrim) close(); });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape' && !scrim.hidden) close(); });
    document.getElementById('meetForm').addEventListener('submit', function(e){ e.preventDefault();
      var title=(document.getElementById('mmTitle').value||'').trim(); var when=document.getElementById('mmWhen').value;
      if(!title||!when){ say('Add a title and a time.','warn'); return; }
      var atts=(document.getElementById('mmAtt').value||'').split(',').map(function(s){return s.trim();}).filter(Boolean);
      var btn=document.getElementById('mmSubmit'); btn.disabled=true; var old=btn.textContent; btn.textContent='Creating…'; say('Creating the meeting and Meet link…','');
      fetch('/portal/meetings.php?action=schedule',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body:JSON.stringify({title:title, when:when, duration:+document.getElementById('mmDur').value, frequency:document.getElementById('mmFreq').value, agenda:document.getElementById('mmAgenda').value, attendees:atts, context:'workspace'})})
        .then(function(r){return r.json();}).then(function(d){ btn.disabled=false; btn.textContent=old;
          if(d&&d.ok){ say((d.warning||'Meeting scheduled — invites sent. 🎉'), d.warning?'warn':'ok'); if(window.avReloadCalendar) window.avReloadCalendar();
            setTimeout(close, d.warning?2600:900); document.getElementById('meetForm').reset(); }
          else { say((d&&d.error)||'Could not schedule the meeting.','warn'); } })
        .catch(function(){ btn.disabled=false; btn.textContent=old; say('Network error — try again.','warn'); }); });
  })();
