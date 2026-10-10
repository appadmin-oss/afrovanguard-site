<?php
/**
 * tests/avng.test.php — the NGV Programme view (design "Afrovanguard Portal
 * v4"): lib/NgvJourney.php, its content defaults, the staff desk's writer and
 * the portal view's promises (classes only, a hidden block for no data, the
 * claim sheet drawn once).
 */
declare(strict_types=1);
$root = dirname(__DIR__);
NgvJourney::ensure();
NgvDb::pdo()->exec('DELETE FROM ngv_journey');
$jm = 990001;
$c = Ngv::get();

$v = NgvJourney::view($jm, $c);
ck('journey: six levels ship with the programme', count($v['levels']) === 6 && $v['levels'][2] === 'Vanguard');
ck('journey: a new member starts at the first level', $v['level'] === 0 && $v['nextIsVanguard']);
ck('journey: nothing set and nothing read means no score block', $v['scored'] === false && $v['total'] === 0);
ck('journey: no engines set means no engines block', $v['enginesSet'] === false);
ck('journey: five lines of evidence for Vanguard', count($v['needs']) === 5);
ck('journey: no fines on record reads Clear and is ticked', $v['needs'][4]['v'] === 'Clear' && $v['needs'][4]['ok']);
ck('journey: six schools ship, none started', count($v['schools']) === 6 && $v['schools'][0]['done'] === 0);

$r = NgvJourney::set($jm, ['score:character' => [82, 'No breaches · 2 peer commendations'], 'level' => 1], 7);
ck('journey: staff set a score and a level', $r['ok'] && $r['saved'] === 2);
$v = NgvJourney::view($jm, $c);
ck('journey: the score shows with its note', $v['scored'] && $v['score'][0]['value'] === 82 && $v['score'][0]['note'] === 'No breaches · 2 peer commendations');
ck('journey: the total is the sum of the eight', $v['total'] === 82);
ck('journey: the level moves', $v['level'] === 1);

$oor = NgvJourney::set($jm, ['score:skill' => 101], 7);
ck('journey: an out-of-range score is refused, in words staff use', !$oor['ok'] && $oor['error'] === 'Skill (score) must be between 0 and 100.');
ck('journey: an unknown key is refused', !NgvJourney::set($jm, ['score:luck' => 5], 7)['ok']);
$bad = NgvJourney::set($jm, ['score:skill' => 40, 'level' => 9], 7);
ck('journey: one bad value refuses the whole set', !$bad['ok'] && !isset(NgvJourney::raw($jm)['score:skill']));
NgvJourney::set($jm, ['score:character' => 80], 7);
ck('journey: a value set without a note keeps the old note', NgvJourney::raw($jm)['score:character']['note'] === 'No breaches · 2 peer commendations');

NgvJourney::set($jm, ['need:mentor' => 3, 'need:lead' => 1, 'need:project' => 1, 'engine:learn' => 62], 7);
$v = NgvJourney::view($jm, $c);
ck('journey: mentoring three members ticks that line', $v['needs'][2]['ok'] && $v['needs'][2]['v'] === '3 / 3');
ck('journey: a contributed project ticks that line', $v['needs'][1]['ok'] && $v['needs'][1]['v'] === 'Done');
ck('journey: one engine set shows the engines block', $v['enginesSet'] && $v['engines'][0]['value'] === 62);
ck('journey: a school cannot pass its module count', (function () use ($jm, $c) { NgvJourney::set($jm, ['school:0' => 40], 7); return NgvJourney::view($jm, $c)['schools'][0]['done'] === 6; })());

/* Content: Faith is belief in one's roots, not religion. */
ck('journey: the NGV 8 ship', count($c['j_values']) === 8);
ck('journey: Faith is about roots and ancestry', stripos($c['j_values'][0]['desc'], 'ancestry') !== false && stripos($c['j_values'][0]['desc'], 'God') === false);
ck('journey: the term\'s project and roster start empty', ($c['j_project']['title'] ?? 'x') === '' && $c['j_roster'] === []);

/* The view: classes only, data-driven, the sheet once. */
$pv = (string) file_get_contents("$root/portal/views/ngv.php");
$at = strpos($pv, 'id="view-ngv"'); $end = strpos($pv, 'id="view-ngv-account"');
$prog = substr($pv, $at, $end - $at);
ck('programme view: no inline styles', !preg_match('/\sstyle\s*=/', $prog) && stripos($prog, '<style') === false);
ck('programme view: bars are <progress>, not widths', substr_count($prog, '<progress') >= 3);
ck('programme view: book slots keep data-slot inside #books', str_contains($prog, 'id="books"') && str_contains($prog, 'data-slot='));
ck('programme view: the claim sheet is drawn here', str_contains($prog, '_book-modal.php'));
ck('programme view: the project card hides with no title', str_contains($prog, "trim((string) (\$nProj['title'] ?? '')) !== ''"));
ck('programme view: the roster hides when empty', str_contains($prog, '<?php if ($nRoster): ?>'));
ck('programme view: stylesheet is loaded by the shell', str_contains((string) file_get_contents("$root/portal/index.php"), '/portal/avng.css'));
$body = (string) file_get_contents("$root/academy/ngv/_dashboard-body.php");
ck('dashboard body: the sheet only where reading is drawn', str_contains($body, "\$ngvModal      = \$ngvModal ?? (\$ngvParts === null || in_array('reading', \$ngvParts, true));"));
$css = (string) file_get_contents("$root/portal/avng.css");
ck('programme css: no hex colours', !preg_match('/#[0-9a-fA-F]{3,8}\b/', $css));
ck('programme css: narrow layout by container, not window', str_contains($css, '@container avng'));

/* The desk writes it. */
$mem = (string) file_get_contents("$root/academy/ngv/members.php");
ck('desk: journey_set calls NgvJourney::set', str_contains($mem, "\$act === 'journey_set'") && str_contains($mem, 'NgvJourney::set($mid'));
ck('desk: journey saves are audited', str_contains($mem, "ngv_console_audit('journey'"));
$ed = (string) file_get_contents("$root/academy/ngv/edit.php");
ck('editor: the Programme blocks are editable', str_contains($ed, "list:'j_roster'") && str_contains($ed, "path:'j_project.title'") && str_contains($ed, "list:'j_values'"));

NgvDb::pdo()->exec('DELETE FROM ngv_journey');
