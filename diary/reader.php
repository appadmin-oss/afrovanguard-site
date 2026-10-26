<?php
/**
 * diary/reader.php — the reading rail, and its phone form.
 *
 * Not a drop-in: the handoff describes this block in §4 but ships no markup
 * for it, so it is written here against that description.
 *
 * ONE nav, two shapes. On a wide screen it is a 200px column beside the text:
 * a list of the entry's headings, how much reading is left, and the text-size
 * controls. On a phone the same element becomes a sticky bar under the site
 * nav showing the section you are in, which opens into the same list.
 *
 * One element rather than two because a second copy of the headings would give
 * a screen reader two "On this page" navigations for one page, and the one it
 * reached first would be the one CSS had hidden.
 */

/** The reading rail. $sections comes from extract_sections() on the body. */
function avd_rail(array $sections, int $readMinutes): void
{
    if (!$sections) return; ?>
  <nav class="avd-rail" aria-label="On this page" data-avd-rail data-minutes="<?= $readMinutes ?>">
    <div class="avd-rail-progress" aria-hidden="true"><span data-avd-progress></span></div>
    <button type="button" class="avd-rail-toggle" aria-expanded="false" aria-controls="avd-toc">
      <span class="avd-rail-eyebrow">On this page</span>
      <span class="avd-rail-current" data-avd-current><?= e($sections[0]['label']) ?></span>
      <svg class="avd-rail-chev" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
    <div class="avd-rail-panel" id="avd-toc">
      <p class="avd-rail-head">On this page</p>
      <ol class="avd-toc">
<?php foreach ($sections as $s): ?>
        <li><a href="#<?= e($s['anchor']) ?>"><?= e($s['label']) ?></a></li>
<?php endforeach; ?>
      </ol>
      <p class="avd-rail-left av-num"><span data-avd-left><?= $readMinutes ?></span> min left</p>
      <div class="avd-size" role="group" aria-label="Text size">
        <span class="avd-size-l" aria-hidden="true">Text size</span>
        <button type="button" data-avd-size="-1" aria-label="Smaller text">A−</button>
        <button type="button" data-avd-size="1" aria-label="Larger text">A+</button>
      </div>
    </div>
  </nav>
<?php }

/**
 * Mark the paragraph that takes the drop cap (class "has-dropcap").
 *
 * It was simply the first <p>. An entry that opens with a byline or dateline
 * — "From the Office of the Chief Servant", in italics, one line — gave the
 * big letter to that line, and the letter, taller than the line, hung down
 * into the paragraph below it. The drop cap belongs to the first paragraph
 * of prose: one with a real sentence or two in it (MIN_CHARS), that is not
 * wholly italic or bold, among the first few. When there is none, there is no
 * drop cap, which reads better than one in the wrong place.
 */
function avd_dropcap(string $html): string
{
    $min = 120; $look = 6; $seen = 0;
    if (!preg_match_all('~<p(\s[^>]*)?>(.*?)</p>~is', $html, $m, PREG_OFFSET_CAPTURE)) return $html;
    foreach ($m[0] as $i => [$whole, $at]) {
        if (++$seen > $look) break;
        $attrs = (string) ($m[1][$i][0] ?? '');
        $inner = trim((string) $m[2][$i][0]);
        if (preg_match('~class\s*=\s*["\'][^"\']*\blead\b~i', $attrs)) continue;
        $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (mb_strlen($text) < $min) continue;
        if (preg_match('~^<(em|i|strong|b)\b[^>]*>.*</\1>$~is', $inner) && !preg_match('~</(em|i|strong|b)>.+<\1\b~is', $inner)) continue;
        if (!preg_match('/^["“‘\'(]?[\p{L}\p{N}]/u', $text)) continue;
        $tag = preg_match('~\bclass\s*=\s*(["\'])~i', $attrs)
            ? '<p' . preg_replace('~\bclass\s*=\s*(["\'])~i', 'class=${1}has-dropcap ', $attrs, 1) . '>'
            : '<p' . $attrs . ' class="has-dropcap">';
        $open = strlen('<p' . $attrs . '>');
        return substr($html, 0, $at) . $tag . substr($html, $at + $open);
    }
    return $html;
}
