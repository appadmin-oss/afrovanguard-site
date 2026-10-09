#!/usr/bin/env php
<?php
/**
 * bin/check-inline-js.php — syntax-check the <script> blocks inside HTML pages.
 *
 *   php bin/check-inline-js.php [path ...]      (default: the static pages)
 *
 * WHY THIS EXISTS. CI already runs `node --check` over the standalone .js
 * files, but the big static pages carry their scripts inline, and those were
 * checked by nobody. A single stray `}` at the end of donate.html's main block
 * meant the browser discarded all 1,372 lines of it — the Paystack key fetch,
 * the amount buttons, the multi-step form, the whole donation flow — on the
 * page whose entire job is taking money. Nothing failed loudly: the markup
 * rendered, the buttons were there, and they did nothing.
 *
 * PHP finds the blocks, node parses them. Exits non-zero on the first bad one
 * so CI goes red, and prints the line in the HTML file rather than the line in
 * the extracted fragment, which is the number you actually need.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root  = dirname(__DIR__);
$files = array_slice($argv, 1);
if (!$files) {
    $files = [];
    foreach (['index.html', 'donate.html', 'about.html', 'contact.html', 'projects/index.html'] as $f) {
        if (is_file($root . '/' . $f)) $files[] = $root . '/' . $f;
    }
}

/* Without node there is nothing to parse with. Say so and fail, rather than
   exiting 0 — a check that silently passes when it cannot run is worse than no
   check, because it is reported as a pass. */
exec('node --version 2>/dev/null', $vOut, $vCode);
if ($vCode !== 0) {
    fwrite(STDERR, "node is not on PATH, so inline scripts cannot be checked.\n");
    exit(2);
}

$tmp = sys_get_temp_dir() . '/av-inline-js-' . getmypid();
@mkdir($tmp, 0700, true);
$checked = 0; $bad = 0;

foreach ($files as $path) {
    $html = (string) @file_get_contents($path);
    if ($html === '') { fwrite(STDERR, "cannot read $path\n"); $bad++; continue; }
    $rel = str_replace($root . '/', '', $path);

    /* Blank out HTML comments first. Several pages carry notes that MENTION a
       script tag ("must be in its own <script> tag"), and scanning the raw
       markup opens a block at the comment and runs the prose through node.
       Newlines are preserved one-for-one so reported line numbers still point
       at the right place in the file. */
    $html = (string) preg_replace_callback('~<!--.*?-->~s',
        static fn(array $c): string => str_repeat("\n", substr_count($c[0], "\n")), $html);

    /* Only blocks with no src= — a <script src> is a file the other CI step
       already covers. `type="application/ld+json"` and friends are not
       JavaScript and must not be handed to the parser. */
    if (!preg_match_all('~<script(?![^>]*\bsrc=)([^>]*)>(.*?)</script>~is', $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        continue;
    }
    foreach ($m as $i => $set) {
        $attrs = (string) $set[1][0];
        if (preg_match('~\btype\s*=\s*["\']?([^"\'\s>]+)~i', $attrs, $t)) {
            $type = strtolower($t[1]);
            if (!in_array($type, ['text/javascript', 'application/javascript', 'module'], true)) continue;
        }
        $code = (string) $set[2][0];
        if (trim($code) === '') continue;

        $line = substr_count(substr($html, 0, (int) $set[2][1]), "\n") + 1;
        $frag = $tmp . '/' . basename($path, '.html') . "-$i.js";
        file_put_contents($frag, $code);
        $out = []; $code2 = 0;
        exec('node --check ' . escapeshellarg($frag) . ' 2>&1', $out, $code2);
        $checked++;
        if ($code2 !== 0) {
            $bad++;
            /* node reports the line within the fragment; report the line in the
               file, which is the one somebody has to go and open. */
            $msg = implode("\n", $out);
            if (preg_match('~:(\d+)\s*$~m', $msg, $ln) || preg_match('~\.js:(\d+)~', $msg, $ln)) {
                $inFile = $line + max(0, (int) $ln[1] - 1);
                fwrite(STDERR, "FAIL  $rel — inline script starting at line $line, error near line $inFile\n");
            } else {
                fwrite(STDERR, "FAIL  $rel — inline script starting at line $line\n");
            }
            foreach (array_slice($out, 0, 6) as $l) fwrite(STDERR, "        $l\n");
        }
        @unlink($frag);
    }
}
@rmdir($tmp);

echo ($bad === 0 ? "ok" : "FAILED") . " — $checked inline script block(s) checked, $bad bad\n";
exit($bad === 0 ? 0 : 1);
