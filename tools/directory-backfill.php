<?php
/**
 * tools/directory-backfill.php — push the WHOLE roster to CACENTRE, once.
 *
 * The cron tick sends one batch every few minutes and takes as many ticks as
 * the roster needs. That is right for keeping up and wrong for starting:
 * nobody wants to watch an empty review queue fill over six hours wondering
 * whether it is working. This does the first sweep in one go.
 *
 *     php tools/directory-backfill.php --dry      what WOULD be sent
 *     php tools/directory-backfill.php            send it
 *     php tools/directory-backfill.php --max=5    stop after five batches
 *
 * Safe to run again. Every row upserts on its source key over there, so a
 * second run changes nothing except the last_pushed_at stamps — which is
 * also what makes it safe to interrupt and restart.
 *
 * It does NOT move the cron's cursor. The two are independent sweeps of the
 * same table and the tick will cover the ground again in its own time; a
 * backfill that advanced the cursor would look like progress the tick had
 * made and hide a tick that had stopped running.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once dirname(__DIR__) . '/lib/bootstrap.php';

$argvv = $argv ?? [];
$dry = in_array('--dry', $argvv, true);
$max = 0;
foreach ($argvv as $a) {
    if (preg_match('/^--max=(\d+)$/', (string) $a, $m)) $max = (int) $m[1];
}

if (!$dry && !DirectoryPush::ready()) {
    fwrite(STDERR, "DIR_PUSH_SECRET_AVG is not set (or is a placeholder).\n"
        . "Set the same 32+ character value here and in CACENTRE's includes/.env, then run again.\n");
    exit(1);
}

$pdo = Database::pdo();
fwrite(STDOUT, ($dry ? 'Would push' : 'Pushing') . " to " . DirectoryPush::endpoint() . "\n");

$sent = $written = $refused = $batches = 0;
$failed = false;

/* The admin table first: it is small, and if the key or the URL is wrong
   this is where you find out, after three rows rather than three thousand. */
foreach (DirectoryPush::adminBatches($pdo) as $chunk) {
    $batches++;
    $sent += count($chunk);
    if ($dry) { fwrite(STDOUT, '  admin_users  ' . count($chunk) . " rows\n"); continue; }
    $r = DirectoryPush::send($chunk);
    $written += (int) ($r['written'] ?? 0);
    $refused += (int) ($r['refused'] ?? 0);
    fwrite(STDOUT, '  admin_users  ' . report($r) . "\n");
    if (empty($r['ok'])) { $failed = true; break; }
}

/* Then the members, page by page, until the sweep wraps. */
$cursor = 0;
while (!$failed) {
    $b = DirectoryPush::batch($pdo, $cursor);
    if ($b['rows'] === []) break;

    $batches++;
    $sent += count($b['rows']);
    if ($dry) {
        fwrite(STDOUT, '  lms_users    ' . count($b['rows']) . ' rows, ids '
            . $b['rows'][0]['local_id'] . '…' . $b['rows'][count($b['rows']) - 1]['local_id'] . "\n");
    } else {
        $r = DirectoryPush::send($b['rows']);
        $written += (int) ($r['written'] ?? 0);
        $refused += (int) ($r['refused'] ?? 0);
        fwrite(STDOUT, '  lms_users    ' . report($r) . "\n");
        if (empty($r['ok'])) {
            /* Stop on the first refusal rather than hammering the far side
               with four thousand more rows it is going to refuse the same
               way. The reason is on the line above this one. */
            $failed = true;
            break;
        }
    }

    $cursor = $b['cursor'];
    if ($b['wrapped']) break;
    if ($max > 0 && $batches >= $max) { fwrite(STDOUT, "  stopped at --max\n"); break; }
}

fwrite(STDOUT, sprintf("\n%s %d row(s) in %d batch(es); %d written, %d refused.\n",
    $dry ? 'Would send' : 'Sent', $sent, $batches, $written, $refused));

if ($failed) {
    fwrite(STDERR, "\nStopped on a refusal. Nothing above it is undone — every row upserts,\n"
        . "so fix the cause and run this again.\n");
    exit(1);
}
exit(0);

function report(array $r): string
{
    if (!empty($r['ok'])) {
        return sprintf('%d written%s', (int) ($r['written'] ?? 0),
            !empty($r['dropped_dates']) ? sprintf(', %d unreadable date(s)', (int) $r['dropped_dates']) : '');
    }

    /* The far side names which rows it refused and why. Printing them is the
       difference between fixing a shaper in five minutes and guessing. */
    $out = 'REFUSED: ' . (string) ($r['error'] ?? $r['why'] ?? 'unknown');
    foreach (array_slice((array) ($r['details'] ?? []), 0, 5) as $d) {
        $out .= "\n                 " . (string) ($d['local_id'] ?? '?') . ' — ' . (string) ($d['why'] ?? '?');
    }

    return $out;
}
