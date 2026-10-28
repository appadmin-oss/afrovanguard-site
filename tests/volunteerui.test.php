<?php
/**
 * tests/volunteerui.test.php — the Volunteer dashboard's meetings can be edited, and it writes no inline style.
 *
 * The edit button read "⚠ Add Meet link" whenever a meeting had no link —
 * every meeting on a site without Google Workspace — so nobody could see it
 * was the edit button.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$vj = (string) file_get_contents(AV_ROOT . '/portal/volunteer.js');
$vv = (string) file_get_contents(AV_ROOT . '/portal/views/volunteer.php');
ck('volunteer: a meeting anybody may change always shows an Edit button', str_contains($vj, 'data-medit="\' + m.id + \'" aria-label="Edit \' + esc(m.title) + \'">Edit</button>')
   && !str_contains($vj, "'⚠ Add Meet link' : 'Edit'"));
ck('volunteer: a missing Meet link is said beside it, not instead of it', str_contains($vj, 'No Meet link yet'));
ck('volunteer: the dashboard writes no inline style — not in markup, not from script', !str_contains($vj, 'style="') && !str_contains($vj, '.style.') && !str_contains($vv, 'style="'));
