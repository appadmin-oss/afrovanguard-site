/**
 * Academy learner pages (My learning, Grades, Discussions, Programmes,
 * Grading). Vanilla, no deps. Everything posts to /academy/api.php, which
 * checks access and role itself; nothing here is a permission.
 */
(() => {
  'use strict';
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const run = (name, fn) => { try { fn(); } catch (err) { console.error('[avcw] ' + name, err); } };
  const post = (action, data) => fetch('/academy/api.php?action=' + action, {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
  }).then(r => r.json()).catch(() => ({ ok: false, error: 'Network error — please try again.' }));
  const tell = (form, m, ok) => { const o = $('[data-avcw-msg]', form); if (o) { o.textContent = m; o.className = 'avcw-msg ' + (ok ? 'is-ok' : 'is-err'); } };
  const forum = $('[data-avcw-forum]');
  const course = forum ? forum.dataset.course : '';

  run('thread', () => {
    const f = $('[data-avcw-thread]'); if (!f) return;
    f.addEventListener('submit', e => {
      e.preventDefault();
      const b = $('button[type=submit]', f); b.disabled = true;
      post('forum_thread', { course, title: f.elements.title.value, body: f.elements.body.value }).then(d => {
        b.disabled = false;
        if (d && d.ok) { location.href = location.pathname.replace(/\/$/, '') + '/' + d.id; return; }
        tell(f, (d && d.error) || 'Could not post.', false);
      });
    });
  });

  run('reply', () => {
    const f = $('[data-avcw-reply]'); if (!f) return;
    f.addEventListener('submit', e => {
      e.preventDefault();
      const b = $('button[type=submit]', f); b.disabled = true;
      post('forum_reply', { course, thread: parseInt(f.dataset.avcwReply, 10), body: f.elements.body.value }).then(d => {
        b.disabled = false;
        if (d && d.ok) { location.reload(); return; }
        tell(f, (d && d.error) || 'Could not post.', false);
      });
    });
  });

  run('moderate', () => {
    $$('[data-avcw-mod]').forEach(btn => btn.addEventListener('click', () => {
      btn.disabled = true;
      post('forum_moderate', { course, what: btn.dataset.avcwMod, id: parseInt(btn.dataset.id, 10), op: btn.dataset.op }).then(d => {
        btn.disabled = false;
        if (d && d.ok) location.reload();
      });
    }));
  });

  run('grade', () => {
    $$('[data-avcw-grade]').forEach(f => {
      const send = ret => {
        const id = parseInt(f.dataset.avcwGrade, 10);
        const score = f.elements.score.value.trim();
        if (!ret && score === '') { tell(f, 'Give a score from 0 to 100.', false); f.elements.score.focus(); return; }
        $$('button', f).forEach(b => { b.disabled = true; });
        post('assignment_grade', { id, score: ret ? '' : parseInt(score, 10), feedback: f.elements.feedback.value, return: ret }).then(d => {
          $$('button', f).forEach(b => { b.disabled = false; });
          if (!d || !d.ok) { tell(f, (d && d.error) || 'Could not save.', false); return; }
          tell(f, ret ? 'Returned to the learner.' : (d.passed ? 'Saved — passed.' : 'Saved — not passed; they can resubmit.'), true);
          const card = f.closest('.avcw-sub-card'); if (card) card.classList.add('is-done');
        });
      };
      f.addEventListener('submit', e => { e.preventDefault(); send(false); });
      const r = $('[data-avcw-return]', f);
      if (r) r.addEventListener('click', () => { if (!f.elements.feedback.value.trim()) { tell(f, 'Say what to change in the feedback.', false); f.elements.feedback.focus(); return; } send(true); });
    });
  });
})();
