<?php
/**
 * lib/Reports.php — the reports Afrovanguard publishes, one list.
 *
 * Read by /reports/ (reports/index.php) and the sitemap. How to add, replace
 * or finish a report: docs/REPORTS.md. Only a report that exists is listed as
 * published; a year still being written is listed as 'preparing', so the
 * page says plainly what is coming and when, and invents nothing.
 *
 * Each row:
 *   year     the year the report covers
 *   title    as printed on the report
 *   kind     'annual' (the whole organisation), 'project' (one project's
 *            report on what it did) or 'document' (a project's plan or
 *            proposal — published, but not a report of results)
 *   status   'published' — complete and linked
 *            'partial'   — published, but some sections are not finished;
 *                          `note` says which
 *            'preparing' — not out yet; `expected` says when
 *   doc      a key in DocStream::DOCS (a PDF this site serves), or
 *   url      an https:// link (a large file kept on Google Drive)
 *   note     optional, one sentence shown under the title
 *   expected for 'preparing' only, read after "Expected:", e.g. 'later in 2026'
 */
declare(strict_types=1);

require_once __DIR__ . '/DocStream.php';

final class Reports
{
    public const STATUSES = ['published', 'partial', 'preparing'];
    public const KINDS = ['annual' => 'Annual report', 'project' => 'Project report', 'document' => 'Project document'];

    public const ROWS = [
        [
            'year' => 2026, 'title' => 'Afrovanguard Annual Report 2026', 'kind' => 'annual',
            'status' => 'preparing', 'expected' => 'later in 2026',
        ],
        [
            'year' => 2026, 'title' => 'LCASP 2026 Project Document', 'kind' => 'document',
            'status' => 'published', 'doc' => 'lcasp-2026',
            'note' => 'The Lagos Community Advancement School Project — a Street-To-Stardom programme, formerly the Alimosho Summer School.',
        ],
        [
            'year' => 2025, 'title' => 'Street-To-Stardom 2025 Impact Report', 'kind' => 'project',
            'status' => 'published', 'doc' => 'sts-report-2025',
        ],
    ];

    /**
     * The rows, newest year first, annual before project within a year, each
     * with `href` and `label` (what a reader is about to download) resolved.
     * A published row whose file cannot be found is dropped, never shown as a
     * dead link.
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::ROWS as $r) {
            $r += ['note' => '', 'expected' => '', 'doc' => '', 'url' => ''];
            $r['href'] = '';
            $r['label'] = '';
            $r['download'] = '';
            if ($r['status'] !== 'preparing') {
                if ($r['doc'] !== '') {
                    $r['href'] = DocStream::url($r['doc']);
                    $r['label'] = DocStream::label($r['doc']);
                    $r['download'] = (string) (DocStream::doc($r['doc'])['name'] ?? '');
                } elseif (preg_match('~^https://~', $r['url'])) {
                    $r['href'] = $r['url'];
                }
                if ($r['href'] === '') continue;
            }
            $out[] = $r;
        }
        usort($out, static fn (array $a, array $b): int =>
            [$b['year'], self::rank($a['kind'])] <=> [$a['year'], self::rank($b['kind'])]);
        return $out;
    }

    /** Within a year: the annual report, then project reports, then project documents. */
    private static function rank(string $kind): int
    {
        return ['annual' => 0, 'project' => 1, 'document' => 2][$kind] ?? 3;
    }

    /** The newest report that can be read, and the next one coming; either may be null. */
    public static function headline(array $rows): array
    {
        $latest = $next = null;
        foreach ($rows as $r) {
            if ($r['status'] === 'preparing') { $next ??= $r; continue; }
            if ($r['kind'] !== 'document') $latest ??= $r;   // a plan is not the latest report
        }
        return ['latest' => $latest, 'next' => $next];
    }

    /** Problems with the list itself; the tests fail on any. */
    public static function problems(): array
    {
        $p = [];
        foreach (self::ROWS as $i => $r) {
            $id = '#' . $i . ' ' . ($r['title'] ?? '?');
            if (!is_int($r['year'] ?? null) || $r['year'] < 2017) $p[] = "$id: year";
            if (trim((string) ($r['title'] ?? '')) === '') $p[] = "$id: title";
            if (!isset(self::KINDS[$r['kind'] ?? ''])) $p[] = "$id: kind";
            if (!in_array($r['status'] ?? '', self::STATUSES, true)) $p[] = "$id: status";
            if (($r['status'] ?? '') === 'preparing') {
                if (trim((string) ($r['expected'] ?? '')) === '') $p[] = "$id: a report in preparation says when it is expected";
                if (!empty($r['doc']) || !empty($r['url'])) $p[] = "$id: a report in preparation has no file yet";
            } else {
                $doc = (string) ($r['doc'] ?? '');
                $url = (string) ($r['url'] ?? '');
                if (($doc === '') === ($url === '')) $p[] = "$id: exactly one of doc / url";
                if ($doc !== '' && DocStream::doc($doc) === null) $p[] = "$id: doc '$doc' is not a file DocStream serves";
                if ($url !== '' && !preg_match('~^https://~', $url)) $p[] = "$id: url must be https://";
                if (($r['status'] ?? '') === 'partial' && trim((string) ($r['note'] ?? '')) === '') $p[] = "$id: a partial report says what is missing";
            }
        }
        return $p;
    }
}
