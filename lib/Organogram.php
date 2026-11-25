<?php
/**
 * lib/Organogram.php — Afrovanguard's organisational chart, derived from the
 * same map as the People page.
 *
 *   Page:  /people/organogram/            (people/organogram/index.php)
 *   PDF:   /people/organogram/download/   (people/organogram/download.php)
 *
 * ONE source for the structure. Every body and office on the chart comes from
 * AV_TEAM_STRUCTURE (lib/people.php) — its labels, its office order, its
 * placement rule (av_team_placement) — so the People page and the chart can
 * never disagree. This file adds only what the People map does not carry:
 *
 *   TREE      the solid lines (who is accountable to whom), as nesting, with
 *             the constitutional basis of each line;
 *   EXTRA     the chart's own nodes that hold no listed people (the General
 *             Assembly, the Board committees);
 *   LINES     the independent ("dotted") lines the Constitution keeps outside
 *             the chain — finance, safeguarding, compliance, the Trustees'
 *             fiduciary oversight — each described in words at both ends;
 *   ALONGSIDE the honorary and membership roles that hold no line at all.
 *
 * Source: docs/governance/constitution.md — Art. VI–X, XI–XIV, Schedules A
 * and B. The Constitution is members-only (ConstitutionDoc::PUBLIC = false):
 * the public chart names bodies and offices, every sentence below is our own
 * plain wording (tests/organogram.test.php checks no run of eight words is
 * copied), and the article references in 'basis' are shown, and linked, only
 * once the Constitution is public. NGGC is left off the chart by the owner's
 * decision (it is still named in Art. XII §2 and Schedule B).
 *
 * Holders: only people av_team_public_rows() returns (av_team_listable: curated
 * or consented, not a system account), placed by av_team_placement().
 */
declare(strict_types=1);
require_once __DIR__ . '/people.php';

final class Organogram
{
    /** Chart-only nodes: [label, our one line]. */
    public const EXTRA = [
        'assembly' => ['General Assembly', 'Our voting Members, meeting at least once a year. The highest forum of the membership.'],
        'committees' => ['Board committees', 'Standing committees the Board sets up and supervises.'],
    ];

    /** The five Board committees (Art. IX §5(1)), in constitutional order. */
    public const COMMITTEES = [
        'afr' => 'Audit, Finance and Risk Committee',
        'gns' => 'Governance, Nominations and Succession Committee',
        'pis' => 'Programmes, Impact and Safeguarding Committee',
        'trd' => 'Technology, Research and Data Ethics Committee',
        'prm' => 'Partnerships and Resource Mobilisation Committee',
    ];

    /*
     * The solid lines. Each node: id, kind, what it points at, the basis of the
     * line to its parent, our sentence for that line, and its children.
     *   kind: plain   — a chart-only node (EXTRA)
     *         body    — a body of the People map, listing its offices and holders
     *         office  — one office of a body (body + office)
     *         offices — a group box listing several offices of one body
     *         units   — a group box of bodies, each expandable to its team
     *         cmtees  — the committees box
     */
    public const TREE = [
        'id' => 'assembly', 'kind' => 'plain', 'basis' => ['VI §1', 'VI §2'],
        'say' => 'The General Assembly of voting Members sits at the top: it approves amendments, receives the annual reports and holds every governing body to account.',
        'children' => [
            ['id' => 'council', 'kind' => 'body', 'body' => 'council', 'basis' => ['VI §2(b)', 'VI §2(g)', 'Schedule A'],
             'say' => 'The Governing Council answers to the General Assembly, which elects or ratifies governing officers and holds them to account.',
             'children' => [
                ['id' => 'board', 'kind' => 'body', 'body' => 'board', 'basis' => ['VII §4(c)', 'IX §4(h)'],
                 'say' => 'The Board of Directors sits under the Governing Council, which reviews its performance; the Board sends major strategic decisions up for approval.',
                 'children' => [
                    ['id' => 'president', 'kind' => 'office', 'body' => 'executive', 'office' => 'president', 'basis' => ['IX §4(b)', 'X §1(2)'],
                     'say' => 'The President and Chief Servant leads the Executive under the Board\'s oversight, and the Board evaluates the President.',
                     'children' => [
                        ['id' => 'coo', 'kind' => 'office', 'body' => 'executive', 'office' => 'coo', 'basis' => ['X §1(1)', 'X §4(1)'],
                         'say' => 'The Chief Operating Officer is the President\'s principal deputy for operations.',
                         'children' => [
                            ['id' => 'directorates', 'kind' => 'units', 'group' => 'directorates', 'basis' => ['X §4(2)(b)', 'X §4(3)', 'XI §1(3)', 'XIV §1', 'Schedule A'],
                             'say' => 'Each of the seven Directorates is headed by a Project Director, who reports to the COO for operations.'],
                            ['id' => 'institutions', 'kind' => 'units', 'group' => 'institutions', 'basis' => ['X §4(2)(c)', 'X §4(3)', 'XII §1(3)', 'XII §3'],
                             'say' => 'The Academy is headed like a Directorate, by a Director answering to the COO, and the COO supervises how the CACENTREs perform.'],
                        ]],
                        ['id' => 'chief-officers', 'kind' => 'offices', 'body' => 'executive', 'skip' => ['president', 'coo'], 'basis' => ['X §2(1)(e)', 'X §3', 'Schedule B'],
                         'say' => 'The other Chief Officers are supervised by the President. The COO coordinates their day-to-day work, but never overrides the independent control functions.',
                         'label' => 'Chief Officers'],
                    ]],
                    ['id' => 'committees', 'kind' => 'cmtees', 'basis' => ['IX §4(g)', 'IX §5'],
                     'say' => 'Each of the five Board committees works to written terms and answers to the Board.'],
                ]],
             ]],
            ['id' => 'trustees', 'kind' => 'body', 'body' => 'trustees', 'basis' => ['VI §2(f)', 'VIII §1', 'Schedule A', 'XXI §5(3)'],
             'say' => 'The Registered Trustees are appointed and removed through the General Assembly\'s lawful powers and the statutory procedure. They hold Afrovanguard\'s property and legal continuity in trust.',
             'aside' => 'custodians'],
        ],
    ];

    /*
     * The independent lines. from/to are node ids ('directorate-finance' is the
     * Finance Officer of every Directorate). draw: both ends are named boxes,
     * so the line can be drawn between them. On the page every line with an end
     * on the chart is drawn (dashed, on request, to the nearest visible box) and
     * written in full ('say') in the words of the node at each end; at_from /
     * at_to are the short forms (the PDF key, the panel).
     */
    public const LINES = [
        ['from' => 'office-cfo', 'to' => 'cmte-afr', 'kind' => 'finance', 'draw' => true, 'basis' => ['X §5(3)', 'Schedule A'],
         'at_from' => 'Independent line to the Audit, Finance and Risk Committee', 'at_to' => 'Independent line from the Chief Financial Officer',
         'say' => 'On serious financial concerns the Chief Financial Officer goes straight to the Audit, Finance and Risk Committee, which also oversees the financial controls.'],
        ['from' => 'office-safeguarding', 'to' => 'cmte-pis', 'kind' => 'safeguarding', 'draw' => true, 'basis' => ['X §13(3)', 'IX §5(1)(c)', 'Schedule A'],
         'at_from' => 'Independent line to the Programmes, Impact and Safeguarding Committee', 'at_to' => 'Independent line from the Chief Safeguarding and Welfare Officer',
         'say' => 'The Chief Safeguarding and Welfare Officer reports independently to the Programmes, Impact and Safeguarding Committee, which oversees safeguarding investigations.'],
        ['from' => 'office-cto', 'to' => 'cmte-trd', 'kind' => 'technology', 'draw' => true, 'basis' => ['X §6(2)(j)', 'Schedule A'],
         'at_from' => 'Reports technology risks to the Technology, Research and Data Ethics Committee', 'at_to' => 'Receives technology risk reports from the Chief Technology Officer',
         'say' => 'The Chief Technology Officer reports serious technology risks to the Technology, Research and Data Ethics Committee, which oversees technology strategy.'],
        ['from' => 'office-counsel', 'to' => null, 'kind' => 'compliance', 'draw' => false, 'basis' => ['X §12(2)(f)', 'X §3(4)(a)'],
         'at_from' => 'Reports significant legal risks to the relevant governance body',
         'say' => 'The General Counsel and Compliance Officer reports significant legal risks to the relevant governance body, and stays independent of whoever approves or carries out what it checks.'],
        ['from' => 'trustees', 'to' => 'board', 'kind' => 'fiduciary', 'draw' => true, 'basis' => ['Schedule A', 'X §1(2)', 'XIII §8(2)'],
         'at_from' => 'Fiduciary oversight of the budget and of major assets', 'at_to' => 'Budget and major assets under the Trustees\' fiduciary oversight',
         'say' => 'The Registered Trustees keep fiduciary oversight of the annual budget the Board approves, and their approval is needed for disposing of major assets or for any agreement that touches land.'],
        ['from' => 'council', 'to' => 'president', 'kind' => 'review', 'draw' => false, 'basis' => ['VII §4(c)'],
         'at_from' => 'Also reviews how the Executive performs', 'at_to' => 'Performance also reviewed by the Governing Council',
         'say' => 'Beside the Board\'s evaluation, the Governing Council also reviews how the executive leadership performs.'],
        ['from' => 'office-cpio', 'to' => 'directorates', 'kind' => 'programmes', 'draw' => false, 'basis' => ['X §7(3)', 'Schedule A'],
         'at_from' => 'Oversees programme delivery with the COO; reports results independently', 'at_to' => 'Programme delivery also overseen by the Chief Programmes and Impact Officer',
         'say' => 'The Chief Programmes and Impact Officer oversees how the Directorates deliver alongside the COO, and reports programme results with professional independence.'],
        ['from' => 'directorate-finance', 'to' => 'office-cfo', 'kind' => 'finance', 'draw' => false, 'basis' => ['X §5(2)(c)'],
         'at_from' => 'Also supervised by the Chief Financial Officer', 'at_to' => 'Supervises the Directorates\' Finance Officers',
         'say' => 'Each Directorate\'s Finance Officer is also supervised by the Chief Financial Officer.'],
        ['from' => 'board', 'to' => 'unit-cacentre', 'kind' => 'franchise', 'draw' => false, 'basis' => ['XIII §4(1)', 'XIII §8(2)', 'Schedule A'],
         'at_to' => 'Social Franchises and adoptions granted by the Board on the COO\'s recommendation',
         'say' => 'The Board, or an officer it names in writing, grants each Social Franchise and approves each CACENTRE Adoption, on the COO\'s recommendation.'],
        ['from' => 'office-safeguarding', 'to' => 'unit-cacentre', 'kind' => 'safeguarding', 'draw' => false, 'basis' => ['XIII §11(4)'],
         'at_to' => 'Safeguarding overseen by the Chief Safeguarding and Welfare Officer',
         'say' => 'Every CACENTRE, however it is run, follows safeguarding policy under the Chief Safeguarding and Welfare Officer.'],
    ];

    /** Roles that hold no line on the chart: [id, title, our line, People page anchor, basis]. */
    public const ALONGSIDE = [
        ['custodians', 'Honorary Institutional Custodians', 'Offer counsel, mentorship and support beside the Trustees. They are not Trustees and hold none of their powers.', 'ppl-b-custodians', ['VIII §6']],
        ['honorary', 'Honorary Patrons and advisers', 'Friends who lend counsel and support. An honorary role carries no vote and no line of authority.', 'ppl-g-honorary', ['IV §1(3)', 'XVII §3']],
        ['volunteers', 'Official and Executive Volunteers', 'Classes of membership, not offices: they serve across the Directorates and centres.', 'ppl-g-volunteers', ['IV §1']],
        ['affiliates', 'Affiliated institutions', 'Partners we work with under written agreements. Sharing people or premises gives Afrovanguard no control of them.', '', ['XII §4']],
    ];

    /** Words shown with the solid line between a Project Director and their team. */
    public const TEAM_SAY = 'Each Project Director leads a small team: a Deputy, an administrator, a finance officer, a volunteer coordinator and programme officers, with named safeguarding people wherever children or vulnerable people are served.';

    /* ── Structure ─────────────────────────────────────────────────────── */

    /** Every node of TREE, flattened in document order: id => node (+ 'parent', 'depth'). */
    public static function nodes(): array
    {
        $out = [];
        $walk = static function (array $n, ?string $parent, int $depth) use (&$walk, &$out): void {
            $out[$n['id']] = $n + ['parent' => $parent, 'depth' => $depth];
            foreach ($n['children'] ?? [] as $c) $walk($c, $n['id'], $depth + 1);
        };
        $walk(self::TREE, null, 0);
        return $out;
    }

    /** A node's title: from the People map where it has one, else the chart's own. */
    public static function label(array $n): string
    {
        switch ($n['kind']) {
            case 'plain':  return self::EXTRA[$n['id']][0];
            case 'cmtees': return self::EXTRA['committees'][0];
            case 'body':   return av_team_body_label($n['body']);
            case 'office': return av_team_office_label($n['body'], $n['office']);
            case 'units':  return AV_TEAM_STRUCTURE[$n['group']]['label'];
            case 'offices':return (string) ($n['label'] ?? av_team_body_label($n['body']));
        }
        return '';
    }

    /** A node's one line (our words): from the People map where it has one. */
    public static function note(array $n): string
    {
        switch ($n['kind']) {
            case 'plain':  return self::EXTRA[$n['id']][1];
            case 'cmtees': return self::EXTRA['committees'][1];
            case 'body':   return (string) (AV_TEAM_STRUCTURE[av_team_bodies()[$n['body']]['group']]['desc'] ?? '');
            case 'units':  return (string) (AV_TEAM_STRUCTURE[$n['group']]['desc'] ?? '');
        }
        return '';
    }

    /** The offices a box lists: [office key => label], in map order. */
    public static function offices(array $n): array
    {
        $all = av_team_bodies()[$n['body']]['def']['offices'] ?? [];
        return array_diff_key($all, array_flip($n['skip'] ?? []));
    }

    /** The bodies a units box holds: [body key => definition], in map order. */
    public static function units(array $n): array
    {
        return AV_TEAM_STRUCTURE[$n['group']]['bodies'];
    }

    /** Ids of every node, row and unit the chart draws (TREE nodes, offices, units, committees, asides). */
    public static function allIds(): array
    {
        $ids = [];
        foreach (self::nodes() as $id => $n) {
            $ids[] = $id;
            if ($n['kind'] === 'offices') foreach (array_keys(self::offices($n)) as $o) $ids[] = 'office-' . $o;
            if ($n['kind'] === 'units') foreach (array_keys(self::units($n)) as $b) $ids[] = 'unit-' . $b;
            if ($n['kind'] === 'cmtees') foreach (array_keys(self::COMMITTEES) as $c) $ids[] = 'cmte-' . $c;
            if (!empty($n['aside'])) $ids[] = $n['aside'];
        }
        return $ids;
    }

    /** The independent lines that touch an id, as [text, line] pairs (both ends). */
    public static function linesAt(string $id): array
    {
        $out = [];
        foreach (self::LINES as $l) {
            if ($l['from'] === $id && !empty($l['at_from'])) $out[] = [$l['at_from'], $l, 'from'];
            if ($l['to'] === $id && !empty($l['at_to'])) $out[] = [$l['at_to'], $l, 'to'];
        }
        return $out;
    }

    /** Every solid line in words: [child label, parent label, sentence, basis], in tree order. */
    public static function solidLines(): array
    {
        $nodes = self::nodes(); $out = [];
        foreach ($nodes as $id => $n) {
            if ($n['parent'] === null) continue;
            $out[] = ['from' => $id, 'to' => $n['parent'], 'child' => self::label($n), 'parent' => self::label($nodes[$n['parent']]), 'say' => $n['say'], 'basis' => $n['basis']];
        }
        $out[] = ['from' => 'team', 'to' => 'directorates', 'child' => 'Each Directorate\'s team', 'parent' => 'its Project Director', 'say' => self::TEAM_SAY, 'basis' => ['XIV §1(2)']];
        return $out;
    }

    /* ── People ────────────────────────────────────────────────────────── */

    /** The people who may appear: the public rows only, as member dicts. */
    public static function people(PDO $pdo): array
    {
        return array_map('av_team_member_dict', av_team_public_rows($pdo));
    }

    /**
     * Holders by office: ['body/office' => [member, …]]. Rows are filtered by
     * av_team_listable() once more here when they still carry its fields, so a
     * caller cannot put an unlisted person on the chart by passing raw rows.
     * Somebody placed in a body but in no named office is not an office holder
     * and is left off the chart (the People page still lists them).
     */
    public static function holders(array $people): array
    {
        $out = [];
        foreach ($people as $m) {
            if (array_key_exists('synced', $m) && !av_team_listable($m)) continue;
            if (av_team_is_system_account((string) ($m['name'] ?? ''))) continue;
            $p = av_team_placement($m);
            if ($p['office'] === '') continue;
            $out[$p['body'] . '/' . $p['office']][] = $m;
        }
        return $out;
    }

    /* ── The chart model (page and panel) ─────────────────────────────── */

    /** Which tier a top-level node belongs to: one accent per tier on the page. */
    private const TIER = ['assembly' => 'gov', 'council' => 'gov', 'board' => 'gov', 'trustees' => 'gov', 'committees' => 'gov',
        'president' => 'exec', 'coo' => 'exec', 'chief-officers' => 'exec', 'directorates' => 'ops', 'institutions' => 'ops'];

    /**
     * Every node the page shows, flattened in tree (reading) order, key => [
     *   key, title, parent, kids[], depth, tier (gov|exec|ops), kind,
     *   desc     the one plain line (People map / chart copy), may be ''
     *   say      our sentence for the line to its parent
     *   offices  [[office label, [member…]]…] — who holds it
     *   face     [member…] — the holders the lean node shows
     *   layout   how its children sit on the wide chart: row | stack
     *   collapsible, beside (no line: drawn beside its parent), noun (for counts)
     * ]. Holders come from holders(), so the listing rule already applies.
     */
    public static function model(array $holders): array
    {
        $M = [];
        $who = static fn(string $b, string $o): array => $holders[$b . '/' . $o] ?? [];
        $add = static function (string $key, array $f, ?string $parent) use (&$M): void {
            $f += ['desc' => '', 'say' => '', 'offices' => [], 'face' => [], 'layout' => 'row', 'collapsible' => false, 'beside' => false, 'noun' => '', 'kind' => 'office', 'aka' => ''];
            if (empty($f['face'])) foreach ($f['offices'] as [, $ms]) foreach ($ms as $m) $f['face'][] = $m;
            $M[$key] = ['key' => $key, 'parent' => $parent, 'kids' => [], 'depth' => $parent === null ? 0 : $M[$parent]['depth'] + 1,
                        'tier' => $f['tier'] ?? ($parent !== null ? $M[$parent]['tier'] : 'gov')] + $f;
            if ($parent !== null) $M[$parent]['kids'][] = $key;
            // A member of a group box answers to the group's own parent (a Chief Officer to the President).
            $M[$key]['upTo'] = $parent !== null && $M[$parent]['kind'] === 'group' ? $M[$parent]['parent'] : $parent;
        };
        $walk = static function (array $n, ?string $parent) use (&$walk, &$M, $add, $who): void {
            $id = $n['id']; $t = self::TIER[$id] ?? null; $base = ['title' => self::label($n), 'say' => $n['say'], 'desc' => self::note($n)] + ($t ? ['tier' => $t] : []);
            switch ($n['kind']) {
                case 'plain':
                    $add($id, $base + ['kind' => 'body'], $parent); break;
                case 'body':
                    $offs = [];
                    foreach (self::offices($n) as $o => $lab) $offs[] = [$lab, $who($n['body'], $o)];
                    $add($id, $base + ['kind' => 'body', 'offices' => $offs], $parent); break;
                case 'office':
                    $add($id, $base + ['offices' => [[self::label($n), $who($n['body'], $n['office'])]], 'aka' => $n['office']], $parent); break;
                case 'offices':
                    $add($id, $base + ['kind' => 'group', 'layout' => 'stack', 'collapsible' => true, 'noun' => 'offices'], $parent);
                    foreach (self::offices($n) as $o => $lab) $add('office-' . $o, ['title' => $lab, 'say' => $n['say'], 'offices' => [[$lab, $who($n['body'], $o)]], 'aka' => strlen($o) <= 4 ? $o : ''], $id);
                    break;
                case 'cmtees':
                    $add($id, $base + ['kind' => 'group', 'layout' => 'stack', 'collapsible' => true, 'noun' => 'committees'], $parent);
                    foreach (self::COMMITTEES as $k => $lab) $add('cmte-' . $k, ['title' => $lab, 'say' => $n['say']], $id);
                    break;
                case 'units':
                    $add($id, $base + ['kind' => 'group', 'layout' => 'stack', 'collapsible' => true, 'noun' => $n['group'] === 'directorates' ? 'directorates' : 'institutions'], $parent);
                    foreach (self::units($n) as $b => $def) {
                        $lead = (string) array_key_first($def['offices']); $offs = [];
                        foreach ($def['offices'] as $o => $lab) $offs[] = [$lab, $who($b, $o)];
                        $add('unit-' . $b, ['title' => $def['label'], 'desc' => (string) ($def['desc'] ?? ''), 'say' => $n['say'], 'kind' => 'unit', 'offices' => $offs,
                             'face' => $who($b, $lead), 'layout' => 'stack', 'collapsible' => true, 'noun' => 'roles'], $id);
                        foreach ($def['offices'] as $o => $lab) {
                            if ($o === $lead) continue;
                            $add($b . '-' . $o, ['title' => $lab, 'say' => $n['group'] === 'directorates' ? self::TEAM_SAY : '', 'offices' => [[$lab, $who($b, $o)]]], 'unit-' . $b);
                        }
                    }
                    break;
            }
            foreach ($n['children'] ?? [] as $c) $walk($c, $id);
            // Groups under one office (the COO's Directorates and institutions) stack, not fan out.
            if ($n['kind'] === 'office' && !empty($n['children']) && !array_filter($n['children'], static fn($c) => $c['kind'] !== 'units')) $M[$id]['layout'] = 'stack';
            if (!empty($n['aside'])) {
                $ab = $n['aside']; $def = av_team_bodies()[$ab]['def']; $offs = [];
                foreach ($def['offices'] as $o => $lab) $offs[] = [$lab, $who($ab, $o)];
                $line = '';
                foreach (self::ALONGSIDE as $a) if ($a[0] === $ab) $line = $a[2];
                $add($ab, ['title' => $def['label'], 'desc' => $line, 'kind' => 'body', 'offices' => $offs, 'beside' => true], $id);
                $M[$id]['layout'] = 'beside';
            }
        };
        $walk(self::TREE, null);
        return $M;
    }

    /** The node keys a LINES end names ('directorate-finance' is every Directorate's Finance Officer). */
    public static function endKeys(?string $end): array
    {
        if ($end === null) return [];
        if ($end !== 'directorate-finance') return [$end];
        return array_map(static fn($b) => $b . '-finance', array_keys(AV_TEAM_STRUCTURE['directorates']['bodies']));
    }

    /**
     * The independent lines at one node, in words: [[sentence, other end key or null, short words], …].
     * At the CFO's end the seven Finance Officers are one line, to the Directorates.
     */
    public static function linesFor(string $key): array
    {
        $out = [];
        foreach (self::LINES as $l) {
            if (in_array($key, self::endKeys($l['from']), true)) $out[] = [$l['say'], $l['to'], (string) ($l['at_from'] ?? '')];
            if ($l['to'] === $key) $out[] = [$l['say'], $l['from'] === 'directorate-finance' ? 'directorates' : $l['from'], (string) ($l['at_to'] ?? '')];
        }
        return $out;
    }

    /** The count shown on a collapsible node: "9 offices · 3 filled", "5 committees", "7 directorates". */
    public static function countText(array $M, string $key): string
    {
        $n = $M[$key]; $kids = $n['kids']; $c = count($kids);
        if (!$c || $n['noun'] === '') return '';
        $txt = $c . ' ' . ($c === 1 ? rtrim($n['noun'], 's') : $n['noun']);
        if (in_array($n['noun'], ['offices', 'roles'], true)) {
            $filled = count(array_filter($kids, static fn($k) => !empty($M[$k]['face'])));
            $txt .= ' · ' . $filled . ' filled';
        }
        return $txt;
    }

    /** Where a placement sits on the chart: the anchor id for a profile's "where they sit" link. */
    public static function anchorFor(array $placement): string
    {
        $b = $placement['body'] ?? ''; $o = $placement['office'] ?? '';
        if ($b === 'executive') return in_array($o, ['president', 'coo'], true) ? $o : ($o !== '' ? 'office-' . $o : 'chief-officers');
        if (in_array($b, ['council', 'board', 'trustees', 'custodians'], true)) return $b;
        $g = av_team_bodies()[$b]['group'] ?? '';
        if (in_array($g, ['directorates', 'institutions'], true)) return 'unit-' . $b;
        return in_array($g, ['honorary', 'volunteers'], true) ? 'along-' . $g : 'alongside';
    }

    /** "VII §4(c), Schedule A" — the references, for the report and (when public) the page. */
    public static function basisText(array $basis): string
    {
        return implode('; ', array_map(static fn($b) => str_starts_with($b, 'Schedule') ? $b : 'Art. ' . $b, $basis));
    }

    /** May the page show the article references (and link the Constitution)? */
    public static function showBasis(): bool
    {
        if (!class_exists('ConstitutionDoc')) require_once __DIR__ . '/ConstitutionDoc.php';
        return ConstitutionDoc::PUBLIC;
    }

    /* ── PDF ───────────────────────────────────────────────────────────── */

    public const FILENAME = 'Afrovanguard-Organogram.pdf';

    /** The PDF bytes, cached by the structure and the listed names. Null without Dompdf. */
    public static function pdf(array $people): ?string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) return null;
        $holders = self::holders($people);
        $sig = json_encode([self::TREE, self::LINES, self::ALONGSIDE, AV_TEAM_STRUCTURE, array_map(static fn($l) => array_map(static fn($m) => [$m['id'], $m['name']], $l), $holders), self::showBasis(), (string) @filemtime(__FILE__), (string) @md5_file(__FILE__)]);
        $cache = av_private_path('organogram-' . substr(hash('sha256', (string) $sig), 0, 16) . '.pdf');
        if (is_file($cache) && filesize($cache) > 0) return (string) file_get_contents($cache);

        $pdf = new \Dompdf\Dompdf(self::pdfOptions());
        $pdf->loadHtml(self::pdfHtml($holders), 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        $out = (string) $pdf->output();
        if ($out !== '') @file_put_contents($cache, $out, LOCK_EX);
        return $out;
    }

    /**
     * The chart for Dompdf: one A4 landscape page. Dompdf has no flexbox or
     * grid and its tables cannot place a tree, so it is done in two passes:
     * every box is first rendered on its own at its width to measure its
     * height (pdfMeasure), then placed absolutely on a fixed grid in points,
     * with the solid connectors as thin absolutely placed rules. Boxes are
     * lean — a title, then each office and who holds it — and every
     * independent line is a small dashed letter at both of its ends, written
     * out once in the key under the chart.
     * Columns: Directorates | Institutions | Chief Officers | Committees | Trustees.
     */
    public static function pdfHtml(array $holders): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $nodes = self::nodes();
        $names = static fn(string $b, string $o): array => array_map(static fn($m) => (string) $m['name'], $holders[$b . '/' . $o] ?? []);

        /* The independent lines: a letter each, and the box or row at each end that carries it. */
        $tags = []; $key = []; $i = 0;
        $at = static fn(?string $end): ?string => $end === 'directorate-finance' ? 'directorates' : $end;
        foreach (self::LINES as $l) {
            $letter = chr(97 + $i++);
            foreach ([$at($l['from']), $at($l['to'])] as $end) if ($end !== null) $tags[$end][] = $letter;
            $from = $l['from'] === 'directorate-finance' ? 'Each Directorate\'s Finance Officer' : self::pdfLabel($l['from']);
            $key[] = [$letter, $from, (string) ($l['at_from'] ?? '') !== '' ? (string) $l['at_from'] : self::pdfLabel((string) $l['to']) . ': ' . lcfirst((string) $l['at_to'])];
        }
        $tag = static function (string $id) use (&$tags, $e): string {
            $out = '';
            foreach ($tags[$id] ?? [] as $t) $out .= ' <span class="tg">' . $e($t) . '</span>';
            return $out;
        };

        /* One box: title (+ tags), then rows of [label, names[], tag html], in $cols columns. */
        $box = static function (string $title, array $rows, string $tagHtml = '', int $cols = 1) use ($e): string {
            $html = '<div class="t">' . $e($title) . $tagHtml . '</div>';
            if (!$rows) return $html;
            $per = (int) ceil(count($rows) / $cols); $cells = array_fill(0, $cols, '');
            foreach (array_values($rows) as $i => [$label, $who, $tg]) {
                $cell = '<div class="row">' . ($label !== '' ? '<div class="o">' . $e($label) . $tg . '</div>' : '');
                foreach ($who as $n) $cell .= '<div class="who">' . $e($n) . '</div>';
                $cells[intdiv($i, max(1, $per))] .= $cell . '</div>';
            }
            if ($cols === 1) return $html . $cells[0];
            $html .= '<table class="cols"><tr>';
            foreach ($cells as $i => $c) $html .= '<td' . ($i ? ' class="c2"' : '') . ' style="width:' . round(100 / $cols, 2) . '%">' . $c . '</td>';
            return $html . '</tr></table>';
        };
        $bodyRows = static function (array $n) use ($names): array {
            $r = [];
            foreach (self::offices($n) as $k => $lab) $r[] = [$lab, $names($n['body'], $k), ''];
            return $r;
        };

        $X = [0, 160, 290, 548, 680]; $Wc = [154, 124, 252, 126, 110];
        $cx = static fn(int $from, int $to): float => ($X[$from] + $X[$to] + $Wc[$to]) / 2;
        $c14 = $cx(0, 3); $c13 = $cx(0, 2); $c12 = $cx(0, 1);

        // [id, class, x, width, inner html]
        $B = [];
        $B['assembly'] = ['top', $c14 - 100, 200, $box(self::label($nodes['assembly']), [])];
        $B['council'] = ['', $c14 - 100, 200, $box(self::label($nodes['council']), $bodyRows($nodes['council']), $tag('council'))];
        $B['trustees'] = ['', $X[4], $Wc[4], $box(self::label($nodes['trustees']), $bodyRows($nodes['trustees']), $tag('trustees'))];
        $cust = $names('custodians', 'custodian');
        $B['custodians'] = ['aside', $X[4], $Wc[4], '<div class="k">Beside the Trustees, no line</div>' . $box(av_team_body_label('custodians'), $cust ? [['', $cust, '']] : [])];
        $B['board'] = ['', $c14 - 100, 200, $box(self::label($nodes['board']), $bodyRows($nodes['board']), $tag('board'))];
        $B['president'] = ['exec', $c13 - 95, 190, $box(self::label($nodes['president']), ($w = $names('executive', 'president')) ? [['', $w, '']] : [], $tag('president'))];
        $cm = [];
        foreach (self::COMMITTEES as $k => $lab) $cm[] = [$lab, [], $tag('cmte-' . $k)];
        $B['committees'] = ['group', $X[3], $Wc[3], $box(self::label($nodes['committees']), $cm)];
        $B['coo'] = ['exec', $c12 - 85, 170, $box(self::label($nodes['coo']), ($w = $names('executive', 'coo')) ? [['', $w, '']] : [])];
        $co = [];
        foreach (self::offices($nodes['chief-officers']) as $k => $lab) $co[] = [$lab, $names('executive', $k), $tag('office-' . $k)];
        $B['chief-officers'] = ['group exec', $X[2], $Wc[2], $box(self::label($nodes['chief-officers']), $co, '', 2)];
        foreach (['directorates' => 0, 'institutions' => 1] as $gid => $col) {
            $r = [];
            foreach (self::units($nodes[$gid]) as $b => $def) $r[] = [$def['label'], $names($b, (string) array_key_first($def['offices'])), $tag('unit-' . $b)];
            $B[$gid] = ['group ops', $X[$col], $Wc[$col], $box(self::label($nodes[$gid]), $r, $tag($gid))];
        }

        $css = self::pdfCss();
        $H = self::pdfMeasure($css, $B);
        $h = static fn(string $id): float => $H[$id] ?? 40.0;

        $G = 14; $Y = []; $rules = '';
        $v = static function (float $x, float $y1, float $y2) use (&$rules): void { $rules .= '<div class="rv" style="left:' . round($x, 1) . 'pt;top:' . round($y1, 1) . 'pt;height:' . round($y2 - $y1, 1) . 'pt"></div>'; };
        $hr = static function (float $x1, float $x2, float $y) use (&$rules): void { $rules .= '<div class="rh" style="left:' . round(min($x1, $x2), 1) . 'pt;top:' . round($y, 1) . 'pt;width:' . round(abs($x2 - $x1), 1) . 'pt"></div>'; };
        $fork = static function (float $from, float $y, array $to) use ($v, $hr, $G): void {
            $v($from, $y, $y + $G / 2); $xs = array_merge([$from], $to); $hr(min($xs), max($xs), $y + $G / 2);
            foreach ($to as $x) $v($x, $y + $G / 2, $y + $G);
        };
        $Y['assembly'] = 0.0; $b = $h('assembly');
        $fork($c14, $b, [$c14, $cx(4, 4)]);
        $Y['council'] = $Y['trustees'] = $b + $G;
        $Y['custodians'] = $Y['trustees'] + $h('trustees') + 6;
        $b = $Y['council'] + $h('council'); $v($c14, $b, $b + $G);
        $Y['board'] = $b + $G; $b = $Y['board'] + $h('board');
        $fork($c14, $b, [$c13, $cx(3, 3)]);
        $Y['president'] = $Y['committees'] = $b + $G; $b = $Y['president'] + $h('president');
        $fork($c13, $b, [$c12, $cx(2, 2)]);
        $Y['coo'] = $Y['chief-officers'] = $b + $G; $b = $Y['coo'] + $h('coo');
        $fork($c12, $b, [$cx(0, 0), $cx(1, 1)]);
        $Y['directorates'] = $Y['institutions'] = $b + $G;

        $out = ''; $bottom = 0.0;
        foreach ($B as $id => [$cls, $x, $w, $inner]) {
            $out .= '<div class="node ' . $cls . '" style="left:' . round($x, 1) . 'pt;top:' . round($Y[$id], 1) . 'pt;width:' . round($w - 11.4, 1) . 'pt">' . $inner . '</div>';   // minus padding and borders
            $bottom = max($bottom, $Y[$id] + $h($id));
        }
        // Too tall for the page: scale the whole chart down to fit.
        $avail = 405.0; $z = $bottom > $avail ? round($avail / $bottom, 3) : 1;
        $keyHtml = '<table class="key"><tr>';
        $per = (int) ceil(count($key) / 2);
        foreach (array_chunk($key, max(1, $per)) as $j => $chunk) {
            $keyHtml .= '<td' . ($j ? ' class="c2"' : '') . '>';
            foreach ($chunk as [$l, $from, $words]) $keyHtml .= '<div class="kl"><span class="tg">' . $e($l) . '</span> <b>' . $e($from) . '</b> — ' . $e($words) . '</div>';
            $keyHtml .= '</td>';
        }
        $keyHtml .= '</tr></table>';
        $date = date('j F Y');
        return '<!doctype html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>
<table class="head"><tr><td><h1>How Afrovanguard is organised</h1></td><td class="meta">Organogram · ' . $e($date) . ' · afrovanguard.org.ng/people/organogram/</td></tr></table>
<div class="chart" style="height:' . round(min($bottom, $avail) + 2, 1) . 'pt"><div class="zoom" style="' . ($z < 1 ? 'transform:scale(' . $z . ');transform-origin:0 0;' : '') . '">' . $rules . $out . '</div></div>
<div class="legend"><span class="s"></span>Answers to the body above <span class="d"></span>Independent line: the same dashed letter at both ends, set out below. Names are the people listed on our public People page.</div>
' . $keyHtml . '
</body></html>';
    }

    /** A short name for a line end in the PDF key. */
    private static function pdfLabel(string $id): string
    {
        $nodes = self::nodes();
        if (isset($nodes[$id])) return self::label($nodes[$id]);
        if (str_starts_with($id, 'office-')) return av_team_office_label('executive', substr($id, 7));
        if (str_starts_with($id, 'cmte-')) return self::COMMITTEES[substr($id, 5)] ?? $id;
        if (str_starts_with($id, 'unit-')) return av_team_body_label(substr($id, 5));
        return $id;
    }

    /** The PDF's stylesheet (shared by the measuring pass and the page). */
    private static function pdfCss(): string
    {
        $R = AV_ROOT;
        return '@font-face { font-family: "AV Display"; src: url("' . $R . '/assets/site/fonts/CormorantGaramond-500.ttf") format("truetype"); }
@font-face { font-family: "AV Text"; font-weight: normal; src: url("' . $R . '/assets/site/fonts/SourceSans3-400.ttf") format("truetype"); }
@font-face { font-family: "AV Text"; font-weight: bold; src: url("' . $R . '/assets/site/fonts/SourceSans3-700.ttf") format("truetype"); }
@page { margin: 20pt 26pt 16pt 26pt; }
body { font-family: "AV Text", sans-serif; font-size: 8pt; line-height: 1.18; color: #22262D; }
h1 { font-family: "AV Display", serif; font-weight: normal; font-size: 18pt; line-height: 1.1; margin: 0; color: #111827; }
.head { width: 100%; border-collapse: collapse; margin-bottom: 8pt; }
.head td { vertical-align: bottom; padding: 0; }
.meta { text-align: right; font-size: 6.5pt; color: #5F636D; }
.chart { position: relative; }
.zoom { position: relative; }
.node { position: absolute; border: 0.7pt solid #B9B5AC; border-top: 2pt solid #F3B416; background: #fff; padding: 4pt 5pt 3pt; }
.node.top { border-color: #111827; border-top-color: #F3B416; }
.node.exec { border-top-color: #111827; }
.node.ops { border-top-color: #17673D; }
.node.aside { border: 0.7pt dashed #B9B5AC; background: #FAF9F6; }
.t { font-weight: bold; font-size: 9pt; color: #111827; padding-bottom: 1.5pt; }
.k { font-size: 6.4pt; color: #5F636D; }
.row { border-top: 0.4pt solid #E8E6E1; padding: 1.6pt 0; }
.o { color: #3a404b; }
.who { font-weight: bold; color: #111827; }
.tg { padding: 0 1.8pt; border: 0.6pt dashed #8A6406; color: #5F4605; background: #FBF4E0; font-size: 6.8pt; line-height: 1; font-weight: bold; }
.cols { border-collapse: collapse; width: 100%; } .cols td { vertical-align: top; padding: 0; } .cols td.c2 { padding-left: 7pt; }
.rv { position: absolute; width: 0; border-left: 0.9pt solid #6B6B6B; }
.rh { position: absolute; height: 0; border-top: 0.9pt solid #6B6B6B; }
.legend { margin-top: 8pt; font-size: 6.8pt; color: #454A56; }
.legend .s { display: inline-block; width: 16pt; border-top: 0.9pt solid #6B6B6B; margin-right: 3pt; }
.legend .d { display: inline-block; width: 16pt; border-top: 0.9pt dashed #8A6406; margin: 0 3pt 0 9pt; }
.key { width: 100%; border-collapse: collapse; margin-top: 4pt; } .key td { vertical-align: top; padding: 0; width: 50%; } .key td.c2 { padding-left: 12pt; }
.kl { font-size: 6.6pt; color: #3a404b; padding: 0.9pt 0; }
.m { position: static; margin-bottom: 4pt; }';
    }

    /** Pass one: render each box alone at its width and read back its height (pt). */
    private static function pdfMeasure(string $css, array $boxes): array
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>';
        foreach ($boxes as $id => [$cls, , $w, $inner]) $html .= '<div id="m-' . htmlspecialchars((string) $id, ENT_QUOTES) . '" class="node m ' . $cls . '" style="width:' . round($w - 11.4, 1) . 'pt">' . $inner . '</div>';
        $html .= '</body></html>';
        $H = [];
        $d = new \Dompdf\Dompdf(self::pdfOptions());
        $d->setCallbacks([['event' => 'end_frame', 'f' => static function ($frame) use (&$H) {
            $n = $frame->get_node();
            if ($n instanceof \DOMElement && str_starts_with($n->getAttribute('id'), 'm-')) {
                $H[substr($n->getAttribute('id'), 2)] = (float) $frame->get_border_box()['h'];
            }
        }]]);
        $d->loadHtml($html, 'UTF-8');
        $d->setPaper([0, 0, 842, 6000]);
        $d->render();
        return $H;
    }

    private static function pdfOptions(): \Dompdf\Options
    {
        @mkdir(sys_get_temp_dir() . '/av-dompdf-fonts', 0700, true);
        return new \Dompdf\Options([
            'isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true, 'defaultFont' => 'DejaVu Sans',
            'fontDir' => sys_get_temp_dir() . '/av-dompdf-fonts', 'fontCache' => sys_get_temp_dir() . '/av-dompdf-fonts',
            'chroot' => AV_ROOT, 'tempDir' => sys_get_temp_dir(),
        ]);
    }
}
