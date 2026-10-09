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
        <button type="button" data-avd-size="-1" aria-label="Smaller text">A−</button>
        <button type="button" data-avd-size="1" aria-label="Larger text">A+</button>
      </div>
    </div>
  </nav>
<?php }
