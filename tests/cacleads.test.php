<?php
/**
 * tests/cacleads.test.php — the follow-ups a member owes, in the portal.
 *
 * ── WHY FOLLOW-UPS AND NOT THE LEAD REGISTER ────────────────────────────────
 * The register is ninety people's contact details. A member does not need it
 * to answer the question they actually have, which is "who am I meant to be
 * getting back to", so this carries that and nothing else — leads they own,
 * still open, overdue first.
 *
 * ── AND WHY IT WRITES ───────────────────────────────────────────────────────
 * CACENTRE's leads screen exists because the centre's workbook has a NOTES
 * column filled in on none of ninety rows. A read-only follow-up list here
 * would tell somebody they owe a call and then send them to another site to
 * record it — rebuilding, in a new place, the exact gap that emptied the
 * column. So the log is the feature, not a convenience bolted to it.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/CacSso.php';
require_once dirname(__DIR__) . '/lib/CacTasks.php';
require_once dirname(__DIR__) . '/lib/CacLeads.php';

$src    = (string) file_get_contents(dirname(__DIR__) . '/lib/CacLeads.php');
$api    = (string) file_get_contents(dirname(__DIR__) . '/portal/cac-leads.php');
$portal = (string) file_get_contents(dirname(__DIR__) . '/portal/index.php');
$js     = (string) file_get_contents(dirname(__DIR__) . '/portal/leads.js');
$css    = (string) file_get_contents(dirname(__DIR__) . '/portal/portal.css');

/* ── Nothing is stored here ──────────────────────────────────────────────── */
foreach (['INSERT', 'UPDATE ', 'DELETE'] as $write) {
    ck('nothing here writes to a local table (' . $write . '): CACENTRE stays the one place a '
     . 'lead is true', !str_contains($src, $write));
}
ck('and there is no table on this side holding leads', !str_contains($src, 'CREATE TABLE'));

/* ── It fails soft ───────────────────────────────────────────────────────── */
$r = CacLeads::forMember(0);
ck('a member id of nothing makes no request at all', $r['ok'] === false && $r['leads'] === []);
ck('no shared secret has its own answer', str_contains($src, "'not-configured'"));
ck('the console being down has another', str_contains($src, "'unreachable'"));
ck('each becomes a sentence a member can act on, not the word the wire used',
   str_contains($api, 'The console did not answer.'));

/* ── The log is the point, and it is required to say something ───────────── */
ck('a log with nothing in it is refused before a request is even made',
   str_contains($src, "if (trim((string) (\$fields['summary'] ?? '')) === '') return \$bad('no-summary');"));
ck('…and the refusal says why it matters, rather than "required"',
   str_contains($api, 'A tick that records nothing is how the centre ended up with ninety leads and no notes.'));
ck('the write goes to the other site, over the same signed door the read uses',
   str_contains($src, "public static function log(int \$memberId, int \$leadId, array \$fields): array"));
ck('and the cached read is dropped when a log lands — the follow-up it completed is no '
 . 'longer owed', str_contains($src, 'unset(self::$memo[$memberId]);'));

/* ── The write is guarded like every other write on this site ────────────── */
ck('the log is POST only', str_contains($api, "if (\$method !== 'POST') json_out"));
ck('same-origin', str_contains($api, 'require_same_origin();'));
ck('CSRF', str_contains($api, 'av_csrf_require();'));
ck('and rate-limited', str_contains($api, "av_require_write(\$uid, 'cac_leads', 30);"));
ck('reading is rate-limited too, because it reaches another site on demand',
   str_contains($api, "av_rate_ok('cac_leads', 60, 60)"));
ck('it is for org members', str_contains($api, 'LmsAuth::isOrgMember($u)'));

/* ── The member is never named in a query string ─────────────────────────── */
ck('the member id comes from this site\'s session', str_contains($api, '$uid    = (int) $u[\'id\'];'));
ck('…and travels inside the signature', str_contains($src, "CacSso::mint(['id' => \$memberId])"));

/* ── Another system's data is bounded before this page renders it ────────── */
/* Named rather than counted: a count passes while the field somebody just
   added is the one that is not capped. */
foreach (['name', 'phone', 'where', 'stage', 'what'] as $f) {
    ck("the $f arrives length-capped, because it is another system's data and a page renders it",
       (bool) preg_match('/\'' . $f . '\'\s*=> mb_substr\(/', $src));
}
ck('and a date is checked to be one, rather than another system\'s idea of one rendered raw',
   str_contains($src, "preg_match('/^\\d{4}-\\d{2}-\\d{2}/', \$s)"));
ck('the row count is capped', str_contains($src, 'array_slice($rows, 0, 25)'));

/* ── The card costs nothing until the pane is opened ─────────────────────── */
ck('the follow-ups are not read while the portal page is built',
   !str_contains($portal, 'CacLeads::forMember('));
ck('the card loads itself when the Tasks pane is opened',
   str_contains($js, "if (v === 'tasks') load();"));
ck('…and a second view event arriving before the first answer does not fetch twice',
   str_contains($js, 'if (started) return;') && str_contains($js, 'started = true;'));
ck('a failure clears that, so a member can try again',
   substr_count($js, 'started = false;') >= 2);

/* ── The row leaves the list only once the log is stored ─────────────────── */
$i = strpos($js, 'if (!d || !d.ok) { say((d && d.error)');
$j = strpos($js, "li.classList.add('lead--done');");
ck('the row is struck through only after the other side confirms — removing it first would '
 . 'show work as done that had not been written', $i !== false && $j !== false && $i < $j);

/* ── One copy of the phone-number rules ──────────────────────────────────── */
ck('the number is a tel: link, not a wa.me one — normalising a Nigerian number into the '
 . 'form wa.me needs lives in CACENTRE, and a second copy here would be a second answer '
 . 'waiting to disagree',
   str_contains($js, "tel.href = 'tel:'") && !str_contains($js, 'https://wa.me'));

/* ── It is drawn only when there is something behind it ──────────────────── */
ck('the card appears only for org members with the bridge configured',
   str_contains($portal, '<?php if ($isOrg && CacSso::ready()): ?>'));
ck('and it is styled here rather than borrowed from the console', str_contains($css, '.lead--late'));
ck('overdue is the only red on the card, so the colour means one thing',
   substr_count($css, '--av-red') <= 4);
ck('nothing owed reads as the good state rather than an empty screen',
   str_contains($js, 'That is the good state, not an empty screen.'));

/* ── Anything this card hides must actually hide ─────────────────────────────
 * `hidden` only sets display:none through the browser's own stylesheet, and
 * any display rule in ours beats it. The log form is `display: flex`, so it
 * was drawn open under a button that said "Log a conversation" — the
 * fold-away never worked at all, on any row, from the first commit. This file
 * already carried .pview[hidden] and .tc-thread[hidden] for the same reason,
 * which is why the trap is worth a test rather than a comment.
 */
foreach (['.lead-log', '.lead-head'] as $sel) {
    ck("$sel says what to do when it is hidden, rather than relying on the attribute alone",
       str_contains($css, $sel . '[hidden] { display: none; }'));
}
