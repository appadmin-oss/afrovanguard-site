<?php
/** Timeline: sessions, goals, values and the pairing itself, newest first. */
$events = [];
foreach ($c['sessions'] as $sx) {
    if ($sx['when'] === '') continue;
    $events[] = ['at' => (string) $sx['when'], 'what' => Mentorship::typeLabel((string) $sx['type']),
                 'detail' => $sx['attendance'] === 'attended' ? (trim((string) $sx['outcome']) ?: 'Attended') : ucfirst((string) $sx['attendance'])];
}
foreach ($c['values'] as $val) {
    if ($val['last'] === '') continue;
    $events[] = ['at' => $val['last'], 'what' => $val['label'],
                 'detail' => MentorPortal::LEVELS[max(0, min(3, $val['level']))] . ($val['evidence'] !== '' ? ' — ' . $val['evidence'] : '')];
}
/* One line per goal, and a second when it was met or set aside — the record a
   closing conversation looks back at. */
foreach ($c['goals_list'] as $g) {
    $events[] = ['at' => $g['created_at'] !== '' ? $g['created_at'] : $c['since'], 'what' => 'Goal agreed',
                 'detail' => $g['title'] . ($g['due_label'] !== '' ? ' — by ' . $g['due_label'] : '')];
    if ($g['status'] !== 'open' && $g['closed_at'] !== '') {
        $events[] = ['at' => $g['closed_at'], 'what' => $g['status'] === 'met' ? 'Goal met' : 'Goal set aside',
                     'detail' => $g['title']];
    }
}
$events[] = ['at' => $c['since'], 'what' => 'Paired', 'detail' => 'You were matched with ' . $c['first'] . '.'];
usort($events, fn($x, $y) => strtotime($y['at']) <=> strtotime($x['at']));
?>
      <section class="avm-panel" aria-labelledby="avm-tl-h">
        <div class="avm-h2row"><h2 id="avm-tl-h">Timeline</h2></div>
<?php foreach ($events as $ev): ?>
        <div style="display:grid;grid-template-columns:96px minmax(0,1fr);gap:12px;padding:10px 0;border-bottom:1px solid var(--av-stone-3)">
          <small style="color:var(--av-muted)"><?= e(preg_match('/\d{2}:\d{2}/', $ev['at']) ? MentorPortal::when($ev['at'], 'j M Y') : $ev['at']) ?></small>
          <span><b style="display:block;font-size:13.5px"><?= e($ev['what']) ?></b><small style="color:var(--av-text-2)"><?= e($ev['detail']) ?></small></span>
        </div>
<?php endforeach; ?>
      </section>
