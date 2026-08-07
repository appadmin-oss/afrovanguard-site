<?php
/**
 * IQ/admin.php — author Incorruptible Quizzes (coordinators/admins only).
 * Create/edit quizzes and their questions, mark the correct option, publish.
 * Talks to IQ/api.php (admin_* actions). Served at /IQ/admin.php.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$u = LmsAuth::user();
if (!$u) { header('Location: ' . av_login_url('/IQ/admin.php')); exit; }
$isAdmin = class_exists('Community') && Community::isAdmin((int) $u['id']);
$csrf = av_csrf_token();

render_head([
    'title'      => 'Author quizzes — IQ — Afrovanguard',
    'robots'     => 'noindex, nofollow',
    'body_class' => 'iq-page',
    'css'        => ['/IQ/iq.css'],
]);
render_nav('academy');
?>
<main id="main-content" class="iq" data-csrf="<?= e($csrf) ?>">
  <header class="iq-hero"><div class="iq-in">
    <p class="iq-kicker">✎ IQ Author</p>
    <h1>Quiz authoring</h1>
    <p class="iq-lead">Create and edit Incorruptible Quizzes. Mark the correct option per question, then publish to <a href="/IQ/">/IQ</a>.</p>
  </div></header>
  <div class="iq-in iq-body">
<?php if (!$isAdmin): ?>
    <p class="iq-empty">This tool is for Afrovanguard coordinators and admins. If that’s you, ask an admin to grant access.</p>
<?php else: ?>
    <div id="iqaWrap">
      <div class="iq-p-foot" style="justify-content:flex-start;margin:0 0 16px">
        <button class="iq-btn iq-btn-gold" id="iqaNew">＋ New quiz</button>
        <a class="iq-btn iq-btn-ghost" href="/IQ/">View live IQ →</a>
      </div>
      <div id="iqaList" class="iq-grid"><p class="iq-empty">Loading…</p></div>
      <div id="iqaEditor" hidden></div>
    </div>

<script>
(function () {
  var CSRF = document.querySelector('.iq').getAttribute('data-csrf') || '';
  var API = '/IQ/api.php';
  function api(action, opts) { opts = opts || {}; var init = { credentials:'same-origin' };
    if (opts.body) { init.method='POST'; init.headers={'Content-Type':'application/json','X-CSRF-Token':CSRF}; init.body=JSON.stringify(opts.body); }
    return fetch(API+'?action='+action+(opts.qs||''), init).then(function(r){return r.json();}); }
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  var list = document.getElementById('iqaList'), editor = document.getElementById('iqaEditor');

  function load() {
    api('admin_list').then(function(d){
      if (!d||!d.ok) { list.innerHTML='<p class="iq-empty">Could not load.</p>'; return; }
      if (!d.quizzes.length) { list.innerHTML='<p class="iq-empty">No quizzes yet. Click “New quiz”.</p>'; return; }
      list.innerHTML = d.quizzes.map(function(q){
        return '<div class="iq-card" style="cursor:default">'+
          '<span class="iq-card-badge iq-diff--'+esc(q.difficulty)+'">'+esc(q.difficulty_label)+'</span>'+
          '<h3>'+esc(q.title)+'</h3>'+
          '<div class="iq-card-meta"><span>'+q.q_count+' questions</span><span>'+(q.published?'✅ Published':'📝 Draft')+'</span></div>'+
          '<div class="iq-p-foot" style="margin-top:10px"><button class="iq-btn iq-btn-ghost" data-edit="'+q.id+'">Edit</button>'+
          '<button class="iq-btn iq-btn-ghost" data-del="'+q.id+'">Delete</button></div></div>';
      }).join('');
      Array.prototype.forEach.call(list.querySelectorAll('[data-edit]'),function(b){b.addEventListener('click',function(){edit(+b.getAttribute('data-edit'));});});
      Array.prototype.forEach.call(list.querySelectorAll('[data-del]'),function(b){b.addEventListener('click',function(){
        if(confirm('Delete this quiz and its scores?')) api('admin_delete',{body:{id:+b.getAttribute('data-del')}}).then(function(d){if(d.ok)load();});});});
    });
  }

  function blankQ(){ return {prompt:'',points:10,options:[{t:'',c:1},{t:'',c:0},{t:'',c:0},{t:'',c:0}]}; }
  function edit(id) {
    if (!id) return renderEditor({id:0,title:'',description:'',category:'General',difficulty:'easy',time_limit_sec:0,blog_slug:'',published:false,questions:[blankQ()]});
    api('admin_get',{qs:'&id='+id}).then(function(d){ if(d&&d.ok) renderEditor(d.quiz); });
  }

  function renderEditor(q) {
    list.hidden = true; editor.hidden = false;
    var qs = q.questions && q.questions.length ? q.questions : [blankQ()];
    editor.innerHTML =
      '<div class="iq-player" style="max-width:760px">'+
      '<div class="iq-p-top"><button class="iq-back" id="iqaBack">‹ All quizzes</button></div>'+
      '<label class="iqa-l">Title<input class="iq-scr-in" style="text-transform:none;letter-spacing:0;text-align:left;width:100%" id="qTitle" value="'+esc(q.title)+'"></label>'+
      '<label class="iqa-l">Description<textarea class="iqa-ta" id="qDesc">'+esc(q.description)+'</textarea></label>'+
      '<div class="iqa-row">'+
        '<label class="iqa-l">Category<input class="iqa-in" id="qCat" value="'+esc(q.category)+'"></label>'+
        '<label class="iqa-l">Difficulty<select class="iqa-in" id="qDiff"><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select></label>'+
        '<label class="iqa-l">Time limit (sec, 0=none)<input class="iqa-in" id="qTime" type="number" min="0" value="'+(q.time_limit_sec||0)+'"></label>'+
      '</div>'+
      '<label class="iqa-l">Link to blog post (Diary slug, optional)<input class="iqa-in" id="qBlog" value="'+esc(q.blog_slug||'')+'"></label>'+
      '<div id="qList"></div>'+
      '<button class="iq-btn iq-btn-ghost" id="qAdd" style="margin-top:12px">＋ Add question</button>'+
      '<div class="iq-p-foot" style="margin-top:20px"><label style="font-weight:600"><input type="checkbox" id="qPub" '+(q.published?'checked':'')+'> Published</label>'+
      '<button class="iq-btn iq-btn-gold" id="qSave">Save quiz</button></div>'+
      '<p class="iq-note" id="qMsg" style="text-align:right"></p></div>';
    document.getElementById('qDiff').value = q.difficulty || 'easy';
    var QS = JSON.parse(JSON.stringify(qs));
    function drawQs(){
      var wrap = document.getElementById('qList');
      wrap.innerHTML = QS.map(function(item,qi){
        var opts = (item.options||[]).map(function(o,oi){
          return '<div class="iqa-opt"><input type="radio" name="correct_'+qi+'" '+(o.c?'checked':'')+' data-corr="'+qi+','+oi+'">'+
            '<input class="iqa-in" placeholder="Option '+(oi+1)+'" value="'+esc(o.t||'')+'" data-opt="'+qi+','+oi+'"></div>';
        }).join('');
        return '<div class="iqa-q"><div class="iqa-qh"><b>Q'+(qi+1)+'</b>'+
          '<button class="iq-back" data-rmq="'+qi+'">Remove</button></div>'+
          '<textarea class="iqa-ta" placeholder="Question prompt" data-prompt="'+qi+'">'+esc(item.prompt||'')+'</textarea>'+
          '<div class="iqa-opts">'+opts+'</div>'+
          '<label class="iqa-l" style="max-width:140px">Points<input class="iqa-in" type="number" min="1" value="'+(item.points||10)+'" data-pts="'+qi+'"></label></div>';
      }).join('');
      wrap.querySelectorAll('[data-prompt]').forEach(function(t){t.addEventListener('input',function(){QS[+t.getAttribute('data-prompt')].prompt=t.value;});});
      wrap.querySelectorAll('[data-opt]').forEach(function(t){t.addEventListener('input',function(){var p=t.getAttribute('data-opt').split(',');QS[+p[0]].options[+p[1]].t=t.value;});});
      wrap.querySelectorAll('[data-corr]').forEach(function(t){t.addEventListener('change',function(){var p=t.getAttribute('data-corr').split(',');QS[+p[0]].options.forEach(function(o,i){o.c=(i===+p[1])?1:0;});});});
      wrap.querySelectorAll('[data-pts]').forEach(function(t){t.addEventListener('input',function(){QS[+t.getAttribute('data-pts')].points=+t.value||10;});});
      wrap.querySelectorAll('[data-rmq]').forEach(function(b){b.addEventListener('click',function(){QS.splice(+b.getAttribute('data-rmq'),1);if(!QS.length)QS.push(blankQ());drawQs();});});
    }
    drawQs();
    document.getElementById('qAdd').addEventListener('click',function(){QS.push(blankQ());drawQs();});
    document.getElementById('iqaBack').addEventListener('click',function(){editor.hidden=true;list.hidden=false;load();});
    document.getElementById('qSave').addEventListener('click',function(){
      var payload = { id:q.id, title:document.getElementById('qTitle').value, description:document.getElementById('qDesc').value,
        category:document.getElementById('qCat').value, difficulty:document.getElementById('qDiff').value,
        time_limit_sec:+document.getElementById('qTime').value||0, blog_slug:document.getElementById('qBlog').value,
        published:document.getElementById('qPub').checked, questions:QS };
      document.getElementById('qMsg').textContent='Saving…';
      api('admin_save',{body:payload}).then(function(d){
        if(!d||!d.ok){document.getElementById('qMsg').textContent=(d&&d.error)||'Could not save.';return;}
        document.getElementById('qMsg').textContent='Saved ✓';
        setTimeout(function(){editor.hidden=true;list.hidden=false;load();},600);
      });
    });
  }

  document.getElementById('iqaNew').addEventListener('click',function(){edit(0);});
  load();
})();
</script>
<style>
  .iqa-l{display:block;font-size:12px;font-weight:700;color:var(--afg-muted,#6b7280);margin:12px 0 4px}
  .iqa-in,.iqa-ta{font:inherit;font-size:14px;width:100%;padding:9px 12px;border:1px solid var(--afg-border,#e5e7eb);border-radius:9px;background:var(--afg-surface,#fff);color:var(--afg-ink,#111827)}
  .iqa-ta{min-height:56px;resize:vertical}
  .iqa-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
  .iqa-q{border:1px solid var(--afg-border,#e5e7eb);border-radius:12px;padding:14px;margin-top:14px;background:var(--afg-surface-2,#f4f2ec)}
  .iqa-qh{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
  .iqa-opts{display:flex;flex-direction:column;gap:8px;margin-top:8px}
  .iqa-opt{display:flex;gap:8px;align-items:center}
  .iqa-opt input[type=radio]{width:18px;height:18px;accent-color:var(--afg-accent,#f3b416);flex:none}
  @media(max-width:640px){.iqa-row{grid-template-columns:1fr}}
</style>
<?php endif; ?>
  </div>
</main>
<?php render_footer(); ?>
