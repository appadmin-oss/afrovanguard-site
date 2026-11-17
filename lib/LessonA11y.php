<?php
declare(strict_types=1);

/**
 * LessonA11y — what in a lesson's text shuts a learner out.
 *
 * Reads the HTML an instructor wrote in the Studio and names each barrier in
 * plain words, with what to do about it. Used by:
 *   - LmsCourseware::readiness()      the Studio's "before you publish" list
 *   - tools/academy-a11y-audit.php    every course on the live database
 *   - tests/lessona11y.test.php
 *
 * 'fix' = a learner cannot get the content at all (a picture a blind learner
 *         is told nothing about; a table a screen reader cannot read across).
 * 'tip' = it works, but badly (a link called "click here").
 *
 * The checks are the ones WCAG 2.2 makes about content an author controls:
 * 1.1.1 text alternatives, 1.3.1 structure, 2.4.4 link purpose, 2.4.6
 * headings, 4.1.2 names for embedded frames. The page around the lesson is
 * tested separately (tests/academya11y.test.php, the CI axe job).
 */
final class LessonA11y
{
    /** Link text that says nothing out of context — screen readers list links on their own. */
    private const VAGUE = ['click here', 'here', 'click', 'read more', 'more', 'link', 'this link', 'learn more', 'this', 'go', 'download', 'see more'];

    /**
     * @return list<array{level:string,code:string,text:string}>
     */
    public static function check(string $html): array
    {
        $out = [];
        if (trim($html) === '') return $out;
        $add = static function (string $level, string $code, string $text) use (&$out): void {
            foreach ($out as $o) if ($o['code'] === $code && $o['text'] === $text) return;
            $out[] = ['level' => $level, 'code' => $code, 'text' => $text];
        };

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        /* A space before each closing block tag, so "…step two</p><p>Click…"
           reads as two words when the text is read as a whole. */
        $spaced = (string) preg_replace('~(</(?:p|li|h[1-6]|td|th|div|blockquote)>)~i', ' $1', $html);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="lesson-root">' . $spaced . '</div>', LIBXML_NONET | LIBXML_NOERROR);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $x = new DOMXPath($doc);

        /* 1.1.1 — pictures. Missing alt: a screen reader reads the file name
           ("IMG underscore 2041 dot jpeg"). alt="" means "decoration": right for
           an ornament, wrong for a diagram the lesson depends on — and alt=""
           is what the Studio used to insert for every picture, so it is asked. */
        $n = 0; $blank = 0; $named = 0;
        foreach ($x->query('//img') as $img) {
            /** @var DOMElement $img */
            $n++;
            if (!$img->hasAttribute('alt')) { $named++; continue; }
            $alt = trim($img->getAttribute('alt'));
            if ($alt === '') { $blank++; continue; }
            if (preg_match('~\.(jpe?g|png|gif|webp|svg)$~i', $alt) || preg_match('~^(img|image|photo|picture|pic|screenshot|dsc|untitled)[\s_-]*\d*$~i', $alt))
                $add('fix', 'img-alt-filename', 'A picture is described as “' . self::cut($alt) . '”. Say what it shows, as you would to someone on the phone.');
            elseif (mb_strlen($alt) > 250)
                $add('tip', 'img-alt-long', 'A picture’s description is over 250 characters. Keep it short and put the detail in the lesson text, where everyone can read it.');
        }
        if ($named) $add('fix', 'img-alt-missing', self::count($named, 'picture has', 'pictures have') . ' no description. A learner who cannot see it hears the file name.');
        if ($blank) $add('tip', 'img-alt-empty', self::count($blank, 'picture is', 'pictures are') . ' marked as decoration, so screen readers skip ' . ($blank === 1 ? 'it' : 'them') . '. If ' . ($blank === 1 ? 'it teaches' : 'one teaches') . ' something, describe it.');

        /* 2.4.4 — links that make sense on their own. */
        foreach ($x->query('//a[@href]') as $a) {
            /** @var DOMElement $a */
            $t = self::text($a);
            $label = trim($a->getAttribute('aria-label') ?: $a->getAttribute('title'));
            $imgAlt = '';
            foreach ($x->query('.//img[@alt]', $a) as $i) $imgAlt .= trim($i->getAttribute('alt'));
            if ($t === '' && $label === '' && $imgAlt === '') { $add('fix', 'link-empty', 'A link has no words in it, so a screen reader says only “link”. Put words in it, or describe its picture.'); continue; }
            $said = mb_strtolower(trim($t !== '' ? $t : $label, " \t\n.:!→»>"));
            if (in_array($said, self::VAGUE, true))
                $add('tip', 'link-vague', 'A link says “' . self::cut($t ?: $label) . '”. Learners who hear links read out on their own get no clue where it goes — name the place (“the Python download page”).');
            elseif (preg_match('~^(https?://|www\.)\S+$~i', $t) && mb_strlen($t) > 30)
                $add('tip', 'link-url', 'A link is a bare web address (“' . self::cut($t, 40) . '”), which a screen reader spells out. Use the name of the page instead.');
        }

        /* 1.3.1 / 2.4.6 — headings in order. The page's own heading is the
           lesson title (h1), so the lesson's sections start at h2. */
        $last = 1;
        foreach ($x->query('//h1|//h2|//h3|//h4|//h5|//h6') as $h) {
            $lv = (int) substr($h->nodeName, 1);
            $t = self::text($h);
            if ($t === '') { $add('fix', 'heading-empty', 'A heading is empty. Screen-reader users jump between headings; an empty one is a dead stop.'); continue; }
            if ($lv === 1) { $add('tip', 'heading-h1', 'The lesson uses the biggest heading (“' . self::cut($t) . '”). The lesson title is already that; use Heading for sections.'); $last = 2; continue; }
            if ($lv > $last + 1) $add('tip', 'heading-skip', 'The heading “' . self::cut($t) . '” skips a level, so a screen reader announces a section inside a section that is not there. Use Heading, then Subheading.');
            $last = $lv;
        }

        /* Fake headings: a paragraph that is only bold text looks like a
           heading but cannot be jumped to. */
        $fake = 0;
        foreach ($x->query('//p[count(*)=1][strong or b]') as $p) {
            $t = self::text($p);
            if ($t !== '' && mb_strlen($t) <= 80 && self::text($p->firstElementChild) === $t && !preg_match('~[.!?:]$~u', $t)) $fake++;
        }
        if ($fake >= 2) $add('tip', 'heading-fake', self::count($fake, 'line is', 'lines are') . ' bold text standing in for a heading. Use Heading instead, so screen-reader users can jump to ' . ($fake === 1 ? 'it' : 'them') . '.');

        /* 1.3.1 — tables. A screen reader reads a data table cell by cell and
           says the column heading with each; without headings it says nothing
           but the number. */
        foreach ($x->query('//table') as $tb) {
            if ($x->query('.//th', $tb)->length === 0) { $add('fix', 'table-headers', 'A table has no header row, so a screen reader cannot say which column a number is in. Make the first row a header row.'); }
        }

        /* 4.1.2 — embedded frames (videos, slides, forms) need a name. */
        foreach ($x->query('//iframe') as $f) {
            /** @var DOMElement $f */
            if (trim($f->getAttribute('title')) === '' && trim($f->getAttribute('aria-label')) === '')
                $add('fix', 'iframe-title', 'Something is embedded (a video, slides or a form) with no name, so a screen reader announces only “frame”. Give it a title.');
        }

        /* 1.4.1 / 1.3.3 — instructions that depend on seeing. */
        $all = self::text($doc->getElementById('lesson-root') ?? $doc);
        if (preg_match('~\b(click|tap|press|choose|select)\s+(the\s+)?(red|green|blue|yellow|orange|purple)\s+(one|button|box|option|link)\b~i', $all, $m)
            || preg_match('~\b(in|on)\s+the\s+(left|right)(-hand)?\s+(column|side|panel)\b~i', $all, $m))
            $add('tip', 'sensory', 'The text says “' . self::cut($m[0]) . '”. Learners who cannot see colour or layout cannot follow that; name the thing as well.');

        /* Text in capitals is read letter by letter by some screen readers and is
           hard to read for dyslexic learners. */
        if (preg_match_all('~\b[A-Z]{4,}(?:\s+[A-Z]{2,}){4,}\b~', $all, $mm)) $add('tip', 'all-caps', 'A passage is written in capital letters (“' . self::cut($mm[0][0]) . '”). Use ordinary capitals and bold for emphasis — it is easier to read, and screen readers will not spell it out.');

        return $out;
    }

    /**
     * Every lesson of every course (or one course), checked. Shared by the
     * Studio's Accessibility screen (admin/api.php ac_a11y, ac_a11y_csv) and
     * tools/academy-a11y-audit.php, so the screen and the command never
     * disagree. Read-only.
     *
     * @return array{courses:int,lessons:int,fix:int,tip:int,rows:list<array{course:string,course_title:string,status:string,lesson_id:int,lesson:string,lesson_title:string,level:string,code:string,text:string}>}
     */
    public static function audit(PDO $pdo, string $course = '', bool $publishedOnly = false): array
    {
        $sql = 'SELECT id, slug, title, status FROM courses';
        $where = []; $args = [];
        if ($course !== '') { $where[] = 'slug = ?'; $args[] = $course; }
        if ($publishedOnly) $where[] = "status = 'published'";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $st = $pdo->prepare($sql . ' ORDER BY title'); $st->execute($args);
        $courses = $st->fetchAll(PDO::FETCH_ASSOC);
        $ls = $pdo->prepare('SELECT id, slug, title, kind, quiz_json, body_html, video_url, captions_url, transcript FROM lessons WHERE course_id = ? ORDER BY position, id');
        $out = ['courses' => count($courses), 'lessons' => 0, 'fix' => 0, 'tip' => 0, 'rows' => []];
        foreach ($courses as $c) {
            $ls->execute([(int) $c['id']]);
            foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $out['lessons']++;
                $issues = self::check((string) $l['body_html']);
                if (LmsRepository::lessonType($l) === 'video' && trim((string) ($l['captions_url'] ?? '')) === '' && trim((string) ($l['transcript'] ?? '')) === '')
                    $issues[] = ['level' => 'fix', 'code' => 'video-captions', 'text' => 'The video has no captions and no transcript, so learners who cannot hear it cannot follow it.'];
                foreach ($issues as $i) {
                    $out[$i['level'] === 'fix' ? 'fix' : 'tip']++;
                    $out['rows'][] = ['course' => (string) $c['slug'], 'course_title' => (string) $c['title'], 'status' => (string) $c['status'],
                        'lesson_id' => (int) $l['id'], 'lesson' => (string) $l['slug'], 'lesson_title' => (string) $l['title'],
                        'level' => $i['level'], 'code' => $i['code'], 'text' => $i['text']];
                }
            }
        }
        /* The fixes first: they are the ones that shut somebody out. */
        usort($out['rows'], static fn ($a, $b) => [$a['level'] !== 'fix', $a['course_title']] <=> [$b['level'] !== 'fix', $b['course_title']]);
        return $out;
    }

    /** "1 picture has" / "3 pictures have" */
    private static function count(int $n, string $one, string $many): string
    {
        return $n === 1 ? 'A ' . $one : $n . ' ' . $many;
    }

    private static function text(?DOMNode $n): string
    {
        return $n ? trim((string) preg_replace('~\s+~u', ' ', $n->textContent)) : '';
    }

    private static function cut(string $s, int $max = 60): string
    {
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }
}
