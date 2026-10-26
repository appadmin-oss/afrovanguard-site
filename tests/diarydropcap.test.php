<?php
/**
 * tests/diarydropcap.test.php — the drop cap goes on the first paragraph of
 * prose (avd_dropcap, diary/reader.php), not on a byline above it.
 *
 * An entry opening "From the Office of the Chief Servant, Afrovanguard" in
 * italics gave the big F to that one line, and the letter hung down into the
 * paragraph below.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/diary/reader.php';

$prose = 'A student once approached me with a concern that revealed something many adults overlook. She was in Senior Secondary School One.';
$out = avd_dropcap('<h3>The Hidden Contribution</h3><p><em>From the Office of the Chief Servant, Afrovanguard</em></p><p>' . $prose . '</p>');
ck('dropcap: an italic byline does not take it', str_contains($out, '<p><em>From the Office'));
ck('dropcap: the first paragraph of prose does', str_contains($out, '<p class="has-dropcap">A student'));
ck('dropcap: a short opening line does not take it', str_contains(avd_dropcap('<p>Lagos, 12 May.</p><p>' . $prose . '</p>'), '<p>Lagos, 12 May.</p><p class="has-dropcap">'));
ck('dropcap: a paragraph with some italics in it still does', str_contains(avd_dropcap('<p>' . $prose . ' She said <em>yes</em>.</p>'), '<p class="has-dropcap">'));
ck('dropcap: wholly bold is a heading in disguise, and is passed over', !str_contains(avd_dropcap('<p><strong>' . $prose . '</strong></p>'), 'has-dropcap'));
ck('dropcap: a class already there is kept', str_contains(avd_dropcap('<p class="x">' . $prose . '</p>'), '<p class="has-dropcap x">'));
ck('dropcap: the standfirst (.lead) is passed over', str_contains(avd_dropcap('<p class="lead">' . $prose . '</p><p>' . $prose . '</p>'), '<p class="lead">'));
ck('dropcap: only one', substr_count(avd_dropcap('<p>' . $prose . '</p><p>' . $prose . '</p>'), 'has-dropcap') === 1);
ck('dropcap: none, when no paragraph is prose', avd_dropcap('<p>Short.</p>') === '<p>Short.</p>');
$css = (string) file_get_contents(AV_ROOT . '/diary/diary.css');
ck('dropcap: the stylesheet draws it only on the chosen paragraph', str_contains($css, 'p.has-dropcap::first-letter') && !str_contains($css, 'p:first-of-type:not(.lead)::first-letter'));
ck('dropcap: the entry page marks it', str_contains((string) file_get_contents(AV_ROOT . '/diary/article.php'), 'avd_dropcap('));
