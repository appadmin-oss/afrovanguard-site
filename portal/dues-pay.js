/* portal/dues-pay.js — moved verbatim from the inline script of portal/index.php v1
   (row 14 shell rebuild). Its logic is unchanged; it reads the same ids and data-* hooks. */
  /* Dues paid offline — the receipt goes for checking; nothing is credited on our word. */
  (function () {
    var box=document.getElementById('duesOffline'); if(!box) return;
    var card=document.getElementById('membership'), form=box.querySelector('form'), msg=box.querySelector('.dues-off-msg'), list=box.querySelector('.dues-off-list');
    var LABEL={verified:'Checked and credited', held:'Held — not credited', rejected:'Rejected', pending:'Being checked'};
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function say(t){ msg.hidden=false; msg.textContent=t; }
    function load(){ fetch('/portal/offline-payment.php',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      var rows=(d&&d.payments||[]).filter(function(p){return p.purpose==='dues';});
      list.innerHTML = rows.map(function(p){ return '<div class="dues-off-row"><b>₦'+Number(p.amount_ngn).toLocaleString('en-NG')+'</b> · '+esc(p.method)+' · '+esc(String(p.created_at).slice(0,10))+' — <span>'+esc(LABEL[p.status]||p.status)+'</span>'
        + ((p.reasons||[]).length && p.status!=='verified' ? '<div class="dues-off-why">'+p.reasons.map(esc).join(' ')+'</div>' : '') + (p.decision_note ? '<div class="dues-off-why">'+esc(p.decision_note)+'</div>' : '') + '</div>'; }).join('');
    }).catch(function(){}); }
    form.months.addEventListener('change', function(){ form.amount.value = form.months.selectedOptions[0].getAttribute('data-amount'); });
    form.addEventListener('submit', function(e){ e.preventDefault();
      if(!form.evidence.files.length){ say('Attach the receipt — it is what gets checked.'); return; }
      var fd=new FormData(form); fd.append('purpose','dues');
      var b=form.querySelector('button'); b.disabled=true; say('Checking the receipt…');
      fetch('/portal/offline-payment.php',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':card.getAttribute('data-csrf')||''},body:fd})
        .then(function(r){return r.json();}).then(function(d){ b.disabled=false;
          if(d&&d.ok&&d.status==='verified'){ say('✓ Checked — your dues are credited.'); setTimeout(function(){location.reload();},1200); return; }
          if(d&&d.ok&&d.status==='held'){ say('Not credited yet: '+(d.reasons||[]).join(' ')+' You can send a clearer receipt, or the office will look at it.'); load(); return; }
          say((d&&d.error)||'Could not send that.'); })
        .catch(function(){ b.disabled=false; say('Network error — nothing was sent.'); }); });
    box.addEventListener('toggle', function(){ if(box.open) load(); });
  })();
  /* Membership dues — Paystack checkout (unchanged behaviour). */
  (function () {
    var card=document.getElementById('membership'); if(!card) return;
    var btns=card.querySelectorAll('[data-dues-pay]'); if(!btns.length) return;
    var msg=card.querySelector('.dues-msg');
    function say(t){ if(msg){ msg.hidden=false; msg.textContent=t; } }
    [].forEach.call(btns, function(btn){ btn.addEventListener('click', function(){
      var period=btn.getAttribute('data-period')||'year'; [].forEach.call(btns,function(b){b.disabled=true;}); say('Starting secure checkout…');
      fetch('/portal/dues.php?action=pay_init',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':card.getAttribute('data-csrf')||''},body:JSON.stringify({period:period})})
        .then(function(r){return r.json();}).then(function(d){ if(d&&d.ok&&d.authorization_url){ window.location.href=d.authorization_url; return; } [].forEach.call(btns,function(b){b.disabled=false;}); say((d&&d.error)||'Could not start payment.'); })
        .catch(function(){ [].forEach.call(btns,function(b){b.disabled=false;}); say('Network error — please try again.'); }); }); });
  })();
