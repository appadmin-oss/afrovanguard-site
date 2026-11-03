<?php
/**
 * lib/GrowthSignature.php — "Your growth signature" on Today.
 *
 * Five bars in the Home v4 design: Knowledge, Competence, Responsibility,
 * Consistency, Impact. Each is counted from the member's verified record —
 * what was done, never a judgement of who they are — and the page links to
 * how each is worked out, so these formulas are the copy as much as the code.
 *
 * A bar with nothing to count is LEFT OUT, not drawn at 0%: somebody who has
 * never been given a task has not been irresponsible. An empty signature
 * means the card is not drawn at all.
 *
 *   Knowledge      verified books of the 24, and finished courses of those
 *                  enrolled — the mean of whichever exist.
 *   Competence     courses with a certificate (the assessment passed) of the
 *                  courses finished.
 *   Responsibility tasks finished on or before their due date, out of every
 *                  dated task that is finished or already late.
 *   Consistency    the gate's attendance rate and mentorship consistency —
 *                  the mean of whichever exist.
 *   Impact         actively mentored members, of the number the next level asks for.
 *
 * Pure: the view passes the numbers in, the test passes its own.
 */
declare(strict_types=1);

final class GrowthSignature
{
    public const HOW = [
        'knowledge'      => 'Verified books out of 24, and finished courses out of those you are enrolled on.',
        'competence'     => 'Courses whose assessment you passed (a certificate), out of the courses you finished.',
        'responsibility' => 'Tasks finished on or before their due date, out of every dated task finished or already late.',
        'consistency'    => 'Your attendance rate at the centre and your mentorship consistency.',
        'impact'         => 'Members you actively mentor, out of what your next level asks for.',
    ];

    /**
     * @param array{
     *   books_verified?:int, books_total?:int, courses?:int, courses_done?:int, courses_certified?:int,
     *   tasks?:array<int,array>, today?:string, attendance_rate?:?int, mentor_rate?:?int,
     *   mentees?:int, mentees_needed?:int
     * } $in
     * @return array<string,int>  key => percent, in the design's order, only those with a basis
     */
    public static function compute(array $in): array
    {
        $out = [];
        $mean = static function (array $xs): ?int {
            $xs = array_values(array_filter($xs, static fn($x) => $x !== null));
            return $xs ? (int) round(array_sum($xs) / count($xs)) : null;
        };
        $pct = static fn(int $n, int $of): ?int => $of > 0 ? (int) round(100 * min($n, $of) / $of) : null;

        $books = !empty($in['books_total']) && (int) ($in['books_verified'] ?? 0) > 0
            ? $pct((int) $in['books_verified'], (int) $in['books_total']) : null;
        $k = $mean([$books, $pct((int) ($in['courses_done'] ?? 0), (int) ($in['courses'] ?? 0))]);
        if ($k !== null) $out['knowledge'] = $k;

        $c = $pct((int) ($in['courses_certified'] ?? 0), (int) ($in['courses_done'] ?? 0));
        if ($c !== null) $out['competence'] = $c;

        $today = (string) ($in['today'] ?? gmdate('Y-m-d'));
        $onTime = 0; $counted = 0;
        foreach ($in['tasks'] ?? [] as $t) {
            $due = trim((string) ($t['due'] ?? ''));
            if ($due === '') continue;
            if (!empty($t['done'])) {
                $counted++;
                $at = substr((string) ($t['done_at'] ?? ''), 0, 10);
                if ($at === '' || $at <= $due) $onTime++;   /* finished before the column existed: no evidence it was late */
            } elseif ($due < $today) {
                $counted++;                                 /* open and past due: late already */
            }
        }
        $r = $pct($onTime, $counted);
        if ($r !== null) $out['responsibility'] = $r;

        $s = $mean([isset($in['attendance_rate']) ? (int) $in['attendance_rate'] : null,
                    isset($in['mentor_rate']) ? (int) $in['mentor_rate'] : null]);
        if ($s !== null) $out['consistency'] = $s;

        $i = $pct((int) ($in['mentees'] ?? 0), (int) ($in['mentees_needed'] ?? 0));
        if ($i !== null) $out['impact'] = $i;

        return $out;
    }
}
