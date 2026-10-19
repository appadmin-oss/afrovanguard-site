<?php
/**
 * lib/QuoteOfWeek.php — the Home page's quote of the week, server-side.
 *
 * There is ONE list of quotes: the `QUOTES` array in assets/site/avh.js, which
 * rotates the quote on the Home page. This reads that array rather than
 * keeping a second copy, so the card at /quote-card.php can never show a
 * different quote from the page that links to it. Edit the list in avh.js.
 *
 * The rotation is the page's: ISO week number modulo the list length; the
 * label is the card design's "Week 41 · 5 – 11 Oct" (Monday to Sunday; the
 * month once when both days share it, "28 Sept – 4 Oct" when they do not;
 * en-GB short months as Intl prints them, so September is "Sept").
 */
declare(strict_types=1);

final class QuoteOfWeek
{
    /** @return list<array{0:string,1:string,2:string}> [text, who, where] */
    public static function all(?string $js = null): array
    {
        $js ??= (string) @file_get_contents(AV_ROOT . '/assets/site/avh.js');
        if (!preg_match('/const\s+QUOTES\s*=\s*\[(.*?)\n\s*\];/s', $js, $m)) return [];
        $str = "'((?:[^'\\\\]|\\\\.)*)'";
        preg_match_all('/\[\s*' . $str . '\s*,\s*' . $str . '\s*,\s*' . $str . '\s*\]/s', $m[1], $rows, PREG_SET_ORDER);
        $un = static fn(string $s): string => stripcslashes($s);
        return array_map(fn($r) => [$un($r[1]), $un($r[2]), $un($r[3])], $rows);
    }

    /** @return array{text:string,who:string,where:string,week:string,n:int} */
    public static function forDate(?DateTimeImmutable $d = null, ?array $list = null): array
    {
        $d ??= new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos'));
        $list ??= self::all();
        if (!$list) $list = [['The trouble with Nigeria is simply and squarely a failure of leadership.', 'Chinua Achebe', 'The Trouble with Nigeria, 1983']];
        $wk  = (int) $d->format('W');
        [$text, $who, $where] = $list[$wk % count($list)];
        $mon = $d->modify('-' . (((int) $d->format('N')) - 1) . ' days');
        $sun = $mon->modify('+6 days');
        $f = static fn(DateTimeImmutable $x): string => $x->format('j') . ' ' . str_replace('Sep', 'Sept', $x->format('M'));
        return ['text' => $text, 'who' => $who, 'where' => $where, 'n' => $wk,
                'week' => 'Week ' . $wk . ' · ' . ($mon->format('Ym') === $sun->format('Ym') ? $mon->format('j') : $f($mon)) . ' – ' . $f($sun)];
    }

    /** Up to two initials, as the Home page draws them when there is no photo. */
    public static function initials(string $who): string
    {
        $out = '';
        foreach (preg_split('/\s+/u', trim($who)) as $w) if ($w !== '') $out .= mb_substr($w, 0, 1);
        return mb_substr($out, 0, 2);
    }
}
