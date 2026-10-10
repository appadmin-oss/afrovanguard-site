<?php /* portal/views/tasks.php — moved verbatim from portal/index.php v1 (row 14 shell
   rebuild). Markup and ids unchanged; restyled through portal.css + avp.css tokens.
   Rendered inside the shell, which defines the variables it reads. */ ?>
        <!-- ============================================================ -->
        <!-- TASKS  (dedicated productivity view)                         -->
        <!-- ============================================================ -->
        <section class="pview" id="view-tasks" data-view="tasks" hidden>
          <div class="view-head">
            <div><h1>Tasks</h1><p class="view-sub">Plan the work, share it with the team, and let AI turn goals into a plan. Anyone can claim an open task.</p></div>
          </div>

          <!-- AI: turn a goal into a plan of tasks (dropped into the pool) -->
          <section class="pcard task-ai" id="taskAi" data-csrf="<?= e($collabCsrf) ?>" hidden>
            <div class="pcard-head">
              <h2>✨ Plan a goal with AI</h2>
              <span class="pchip pchip--indigo">Beta</span>
            </div>
            <div class="pcard-body">
              <p class="pcard-note">Pick a team goal — AI breaks it into concrete tasks with deadlines and posts them to the pool for anyone to pick up.</p>
              <div class="task-ai-row">
                <label class="task-af task-af--grow"><span>Goal</span>
                  <select id="aiGoal" aria-label="Goal to plan"><option value="">Loading goals…</option></select>
                </label>
                <label class="task-af"><span>Up to</span>
                  <select id="aiMax" aria-label="How many tasks">
                    <option value="4">4 tasks</option>
                    <option value="6" selected>6 tasks</option>
                    <option value="8">8 tasks</option>
                  </select>
                </label>
                <button type="button" class="pbtn pbtn-gold" id="aiGenerate">Generate tasks</button>
              </div>
              <p class="task-ai-msg" id="aiMsg" hidden></p>
            </div>
          </section>

          <!-- The shared task pool — open, unclaimed work anyone can take up -->
          <!-- G-1: commitments. Separate from tasks on purpose — a task is work you
               took on, a commitment is a promise you made out loud in a room, and
               the record of the second one is what accountability actually rests on. -->
          <section class="pcard" id="commitments" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head">
              <h2>My commitments</h2>
              <span class="pchip" id="cmtChip">—</span>
            </div>
            <div class="pcard-body">
              <p class="pcard-note">What you promised in a meeting or a mentorship session. Marking one missed asks why — that answer is private to you and your record, and is never shown to the AI or put in a leadership brief.</p>
              <ul class="task-list" id="cmtList"><li class="pc-empty task-empty">Loading your commitments…</li></ul>
              <div id="cmtStats" class="pcard-note" hidden></div>
            </div>
          </section>

          <!-- The chair's side: promises the AI heard but could not safely attribute.
               Only ever populated for meetings and sessions this member was in. -->
          <section class="pcard" id="cmtQueue" data-csrf="<?= e($collabCsrf) ?>" hidden>
            <div class="pcard-head">
              <h2>Confirm who owns these</h2>
              <span class="pchip pchip--gold" id="cmtQueueCount">0</span>
            </div>
            <div class="pcard-body">
              <p class="pcard-note">The assistant heard these promises but will not assign them — it can mishear a name, and work assigned to the wrong person is worse than work assigned to nobody. Confirm the owner and it becomes theirs.</p>
              <ul class="task-list" id="cmtQueueList"></ul>
            </div>
          </section>

          <section class="pcard" id="taskPool" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head">
              <h2>Task pool</h2>
              <span class="pchip pchip--gold" id="poolCount">0 open</span>
            </div>
            <div class="pcard-body">
              <p class="pcard-note">Unclaimed work the team needs done. Claim one to make it yours — it moves into your list below.</p>
              <ul class="task-list task-pool-list" id="poolList"><li class="pc-empty task-empty">Loading the pool…</li></ul>
            </div>
          </section>

          <?php /* data-cac-open: the console's open tasks, so the live refresh
                   below can keep counting them. Without it the KPI is right on
                   first paint and then drops to the portal-only number a second
                   later, which is worse than never having counted them. */ ?>
          <section class="pcard" id="tasks" data-csrf="<?= e($collabCsrf) ?>" data-cac-open="<?= (int) $cacOpen ?>">
            <div class="pcard-head task-head">
              <div class="task-head-l">
                <h2>My tasks</h2>
                <span class="task-head-sub" id="taskFocusTxt">Loading…</span>
              </div>
              <div class="task-focus" id="taskFocus" title="Today’s progress" hidden>
                <span class="task-ring" id="taskRing" style="--pct:0"><span class="task-ring-n" id="taskRingN">0</span></span>
                <span class="task-focus-cap">left<br>today</span>
              </div>
            </div>
            <div class="pcard-body">
              <form class="task-add task-add--full" id="taskAdd" autocomplete="off">
                <input type="text" id="taskInput" name="title" maxlength="300" placeholder="What needs doing?" aria-label="Task">
                <div class="task-add-meta">
                  <label class="task-af"><span>Due</span><input type="date" id="taskDue" aria-label="Due date"></label>
                  <label class="task-af"><span>Priority</span>
                    <select id="taskPriority" aria-label="Priority">
                      <option value="normal" selected>Normal</option>
                      <option value="high">High</option>
                      <option value="low">Low</option>
                    </select>
                  </label>
                  <label class="task-af"><span>Assign</span>
                    <select id="taskAssignee" class="task-assignee" aria-label="Assign to"><option value="0">Me</option></select>
                  </label>
                  <label class="task-af task-af--check"><input type="checkbox" id="taskPool"> <span>Open to anyone (pool)</span></label>
                  <button type="submit" class="pbtn pbtn-gold">Add task</button>
                </div>
              </form>
              <div class="pseg task-filters" id="taskFilters" role="tablist">
                <button type="button" class="pseg-btn is-on" data-filter="all">All <span class="pseg-n" id="fcAll">0</span></button>
                <button type="button" class="pseg-btn" data-filter="open">Open <span class="pseg-n" id="fcOpen">0</span></button>
                <button type="button" class="pseg-btn" data-filter="overdue">Overdue <span class="pseg-n" id="fcOver">0</span></button>
                <button type="button" class="pseg-btn" data-filter="mine">Mine <span class="pseg-n" id="fcMine">0</span></button>
                <button type="button" class="pseg-btn" data-filter="done">Done <span class="pseg-n" id="fcDone">0</span></button>
              </div>
              <ul class="task-list" id="taskList"><li class="pc-empty task-empty">Loading your tasks…</li></ul>
            </div>
          </section>

          <?php if ($cacTasks): ?>
            <?php /* Its own card rather than rows in the list above. Both kinds
                     can now be ticked and rescheduled from here, but only one of
                     them is STORED here: a change to one of these is sent to
                     CACENTRE and made on the row that lives there. Keeping them
                     apart is what lets the card say so — and says which site to
                     go to when the other one is down.

                     This comment, and the two below it, used to say these could
                     not be changed from the portal at all. That was true when
                     the card was written and stopped being true when the write
                     path was built; the code had a checkbox on it for a while
                     with a comment underneath explaining why there wasn't one. */ ?>
            <section class="pcard" id="cacTasks">
              <div class="pcard-head task-head">
                <div class="task-head-l">
                  <h2>From CACENTRE</h2>
                  <span class="task-head-sub">
                    <?= (int) count($cacTasks) ?> open <?= count($cacTasks) === 1 ? 'task' : 'tasks' ?>
                    assigned to you in the console. Ticking or rescheduling one
                    here changes it there, on the row the console holds.
                  </span>
                </div>
                <a class="pbtn" href="<?= e(CacTasks::consoleUrl()) ?>" target="_blank" rel="noopener">Open the console</a>
              </div>
              <div class="pcard-body">
                <ul class="task-list">
                  <?php foreach ($cacTasks as $t):
                    /* The same markup the portal's own task rows use, so these
                       read as tasks rather than as a table that wandered in.
                       A checkbox and the two fields worth changing in passing,
                       but no delete: deciding a piece of work should not exist
                       belongs where the work is managed, and a control that
                       destroys a row on another system from a dashboard is one
                       nobody should reach by accident. */
                    /* CACENTRE has four priorities and this site has three.
                       Mapping urgent down to normal — which is what dropping
                       the unknown value does — loses exactly the signal the
                       column exists for, and the most urgent task on the list
                       would look like the most routine one. It maps to high,
                       and the row keeps the word it actually carries. */
                    $pri = match ($t['priority']) {
                        'urgent', 'high' => 'high',
                        'low'            => 'low',
                        default          => 'normal',
                    }; ?>
                    <li class="task task--pri-<?= e($pri) ?>" data-cac-task="<?= (int) $t['id'] ?>">
                      <?php /* The tick writes to CACENTRE, which still holds
                               the task. Editing it from here is not a second
                               copy — it is the same row, reached from the
                               other side. */ ?>
                      <input type="checkbox" class="task-check" data-cac-toggle="<?= (int) $t['id'] ?>"
                             aria-label="Mark &quot;<?= e($t['title']) ?>&quot; done">
                      <span class="task-body">
                        <span class="task-title"><?= e($t['title']) ?></span>
                        <span class="task-sub">
                          <label class="cac-f">
                            <span class="pc-sr">Priority</span>
                            <select data-cac-pri="<?= (int) $t['id'] ?>">
                              <?php foreach (['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'] as $k => $lab): ?>
                                <option value="<?= e($k) ?>" <?= $pri === $k ? 'selected' : '' ?>><?= e($lab) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </label>
                          <label class="cac-f">
                            <span class="pc-sr">Due</span>
                            <input type="date" value="<?= e($t['due']) ?>" data-cac-due="<?= (int) $t['id'] ?>">
                          </label>
                        </span>
                      </span>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <p class="pc-empty" id="cacMsg" hidden></p>
              </div>
            </section>

          <?php endif; ?>

          <?php /* ── Follow-ups from CACENTRE ──────────────────────────────
                   Beside the tasks rather than inside them: a task is a thing
                   to do and a follow-up is a person waiting to hear back, and
                   putting them under one heading means the person gets ticked
                   off like an errand.

                   It loads when the pane is opened, not with the page. The
                   dashboard must not wait on the other site for a card most
                   visits never look at.

                   And it LOGS. A list that told somebody they owed a call and
                   then sent them to another site to record it would rebuild,
                   in a new place, exactly the gap that left the centre's
                   workbook with ninety leads and no notes. */ ?>
<?php if ($isOrg && CacSso::ready()): ?>
          <section class="pcard" id="cacLeads" data-csrf="<?= e($collabCsrf) ?>"
                   data-lead-url="<?= e(CacLeads::consoleUrl()) ?>">
            <div class="pcard-head task-head">
              <div class="task-head-l">
                <h2>People waiting on you</h2>
                <span class="task-head-sub" id="cacLeadsSub">
                  Leads you own in the console, the overdue ones first.
                </span>
              </div>
              <a class="pbtn" href="<?= e(CacLeads::consoleUrl()) ?>" target="_blank" rel="noopener">Open leads</a>
            </div>
            <div class="pcard-body">
              <p class="pc-empty" id="cacLeadsMsg" hidden></p>
              <ul class="lead-list" id="cacLeadsList"></ul>
            </div>
          </section>
<?php endif; ?>

<?php if ($cacTasks): ?>
            <script>
            (function () {
              'use strict';
              var card = document.getElementById('cacTasks'); if (!card) return;
              var CSRF = document.getElementById('tasks') ? document.getElementById('tasks').getAttribute('data-csrf') : '';
              var msg  = document.getElementById('cacMsg');

              function say(t, bad) {
                if (!msg) return;
                msg.hidden = false; msg.textContent = t;
                msg.style.color = bad ? 'var(--av-red, #b3261e)' : '';
              }
              async function post(action, body) {
                var res;
                try {
                  res = await fetch('/portal/collab.php?action=' + action, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                    credentials: 'same-origin',
                    body: JSON.stringify(body)
                  });
                } catch (e) { return { ok: false, error: 'The network did not answer. Nothing was changed.' }; }
                try { return await res.json(); } catch (e) { return { ok: false, error: 'Unreadable reply.' }; }
              }

              card.querySelectorAll('[data-cac-toggle]').forEach(function (cb) {
                cb.addEventListener('change', async function () {
                  cb.disabled = true;
                  var r = await post('cac_toggle', { id: Number(cb.getAttribute('data-cac-toggle')) });
                  if (!r.ok) {
                    /* Put it back: a tick that stays ticked is a lie about
                       the other site's state. */
                    cb.checked = !cb.checked; cb.disabled = false;
                    say(r.error || 'That did not work.', true);
                    return;
                  }
                  var li = cb.closest('li'); if (li) li.remove();
                  say('Done — ticked off in the console.', false);
                });
              });

              function wire(sel, field) {
                card.querySelectorAll(sel).forEach(function (el) {
                  el.addEventListener('change', async function () {
                    var body = { id: Number(el.getAttribute(sel.slice(1, -1))) };
                    body[field] = el.value;
                    var r = await post('cac_update', body);
                    say(r.ok ? 'Saved in the console.' : (r.error || 'That did not work.'), !r.ok);
                  });
                });
              }
              wire('[data-cac-due]', 'due');
              wire('[data-cac-pri]', 'priority');
            })();
            </script>
          <?php endif; ?>
        </section>
