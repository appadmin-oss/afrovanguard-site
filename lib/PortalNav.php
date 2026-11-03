<?php
/**
 * lib/PortalNav.php — the member portal's sections, in one place.
 *
 * The sidebar, the views the shell draws, the phone tab bar and the command
 * palette (which reads the sidebar) all come from SECTIONS. Before this the
 * portal kept four lists — the nav arrays, the require lines, the tab bar and
 * the icon map — and a view could be in one and missing from another.
 *
 * Who sees a section is decided by the account, never by the page: `for`
 * names the flags that open it (any one is enough). The flags are worked out
 * by the shell from the account — org member, NextGen Vanguard, volunteer,
 * gate pass, inventory bridge — and passed in as $ctx.
 *
 * The Vanguard Quest is a group of its own because everybody is a part of it
 * and these sections feed it: the Quest record itself; bills and appeals,
 * where a fine or deduction is put to the member and answered; mentorship, because
 * direct mentees actually met are a promotion criterion; attendance, because
 * turning up on time earns points; and fines, because the Pyramid Manual sets
 * points against them.
 */
declare(strict_types=1);

final class PortalNav
{
    /** Group order in the sidebar. A group with nothing visible is not drawn. */
    public const GROUPS = ['Home', 'Work', 'Vanguard Quest', 'NextGen Vanguard', 'Learn', 'Volunteering', 'You'];

    /**
     * id => group, label, who it is for, the view file that draws it.
     * `label_guest` replaces the label for an account that is not an org
     * member. Several ids may share one view file (the programme draws three).
     */
    public const SECTIONS = [
        'overview'    => ['group' => 'Home', 'label' => 'Today', 'for' => ['all'], 'view' => 'today'],
        'tools'       => ['group' => 'Home', 'label' => 'Suite', 'for' => ['all'], 'view' => 'tools'],
        'community'   => ['group' => 'Home', 'label' => 'Community', 'for' => ['all'], 'view' => 'community'],

        'tasks'       => ['group' => 'Work', 'label' => 'Tasks', 'for' => ['org'], 'view' => 'tasks'],
        /* The Suite's meetings app, given its own place: everybody schedules and
           sees meetings. portal/avp.js aliases #meetings to the Suite view and
           portal/tools.js opens the app. */
        'meetings'    => ['group' => 'Work', 'label' => 'Meetings', 'for' => ['org'], 'view' => 'tools'],
        'chat'        => ['group' => 'Work', 'label' => 'Team Chat', 'for' => ['org'], 'view' => 'chat'],
        'inventory'   => ['group' => 'Work', 'label' => 'Inventory', 'for' => ['inventory'], 'view' => 'inventory'],
        /* The centre's Wi-Fi, which CACENTRE runs: an org member's sign-in and PIN,
           over the same CACENTRE link as Inventory (lib/CacWifi.php), so the same flag. */
        'wifi'        => ['group' => 'Work', 'label' => 'Wi-Fi', 'for' => ['inventory'], 'view' => 'wifi'],

        'quest'       => ['group' => 'Vanguard Quest', 'label' => 'The Quest', 'for' => ['all'], 'view' => 'quest'],
        'mentorship'  => ['group' => 'Vanguard Quest', 'label' => 'Mentorship', 'for' => ['all'], 'view' => 'mentorship'],
        'bills'       => ['group' => 'Vanguard Quest', 'label' => 'Bills & appeals', 'for' => ['all'], 'view' => 'bills'],
        'attendance'  => ['group' => 'Vanguard Quest', 'label' => 'Attendance & pass', 'for' => ['gate', 'ngv'], 'view' => 'attendance'],
        'ngv-fines'   => ['group' => 'Vanguard Quest', 'label' => 'Fines', 'for' => ['ngv'], 'view' => 'ngv'],
        /* A member outside NGV who has been fined (NgvFines: any member can be). */
        'fines'       => ['group' => 'Vanguard Quest', 'label' => 'Fines', 'for' => ['fines'], 'view' => 'fines'],

        'ngv'         => ['group' => 'NextGen Vanguard', 'label' => 'Programme', 'for' => ['ngv'], 'view' => 'ngv'],
        'ngv-account' => ['group' => 'NextGen Vanguard', 'label' => 'Fees & account', 'for' => ['ngv'], 'view' => 'ngv'],

        /* The 24-book challenge, for a member or a volunteer. A vanguard's
           copy of the same card sits inside their Programme view, so they do
           NOT get this section as well — one shelf, in one place per person
           (NgvReading::mayRead decides the flag; portal/_reading-card.php is
           the card both draw). */
        'reading'     => ['group' => 'Learn', 'label' => 'Reading', 'for' => ['reading'], 'view' => 'reading'],
        'learning'    => ['group' => 'Learn', 'label' => 'Learning', 'for' => ['all'], 'view' => 'learning'],

        'volunteer'   => ['group' => 'Volunteering', 'label' => 'Volunteer dashboard', 'for' => ['vol'], 'view' => 'volunteer'],

        'diary'       => ['group' => 'Learn', 'label' => 'Diary', 'for' => ['all'], 'view' => 'diary'],
        /* One statement of the member's money from every ledger (lib/MemberStatement.php). */
        'wallet'      => ['group' => 'You', 'label' => 'Wallet', 'for' => ['all'], 'view' => 'wallet'],
        'membership'  => ['group' => 'You', 'label' => 'Membership', 'label_guest' => 'Account', 'for' => ['all'], 'view' => 'membership'],
        'requests'    => ['group' => 'You', 'label' => 'Finance requests', 'for' => ['all'], 'view' => 'requests'],
    ];

    /**
     * The phone tab bar: four slots, each the first visible id in its list,
     * then "More". The second and third slots differ by who is signed in.
     */
    public const TABS = [['overview'], ['tasks', 'ngv', 'learning'], ['chat', 'community'], ['quest']];

    /** Short labels for the tab bar, where the sidebar's would not fit. The
        Quest is "Journey" there (Home v4): it is where a member's growth is. */
    public const TAB_LABELS = ['overview' => 'Today', 'ngv' => 'Programme', 'chat' => 'Chat', 'quest' => 'Journey'];

    /** Home is the top of the sidebar, under no heading (Home v4). */
    public const UNTITLED = ['Home'];

    /**
     * The phone More sheet's eight shortcut tiles, before a member edits them
     * (portal/shell.js keeps their own in Prefs `nav_shortcuts`). Sections the
     * account cannot open are skipped and the next visible ones fill in.
     */
    public const SHORTCUTS = ['community', 'meetings', 'quest', 'mentorship', 'ngv', 'learning', 'wallet', 'membership'];
    public const SHORTCUT_MAX = 8;

    /** One 24px stroke icon per section — the sidebar, the tab bar and the sheet draw from it. */
    public const ICONS = [
        'overview'    => 'M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
        'tools'       => 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
        'community'   => 'M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z',
        'tasks'       => 'M9 11l3 3 8-8M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9',
        'meetings'    => 'M4 6h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2zM18 10l4-3v10l-4-3',
        'chat'        => 'M4 5h16v11H8l-4 4z',
        'inventory'   => 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8',
        'wifi'        => 'M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M2 9a15 15 0 0 1 20 0M12 19.5h.01',
        'quest'       => 'M12 3l9 17H3z',
        'mentorship'  => 'M9 4.5a3.5 3.5 0 1 1 0 7 3.5 3.5 0 0 1 0-7zM2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6',
        'bills'       => 'M12 3v18M5 7h14M7 7l-3 7h6zM17 7l-3 7h6z',
        'attendance'  => 'M4 5h16a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zM3 10h18M8 3v4M16 3v4M9 15l2 2 4-4',
        'ngv-fines'   => 'M12 8v5M12 16.5h.01M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18z',
        'fines'       => 'M12 8v5M12 16.5h.01M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18z',
        'ngv'         => 'M5 21V4M5 4h12l-2 4 2 4H5',
        'ngv-account' => 'M12 3v18M16 7H10a3 3 0 0 0 0 6h4a3 3 0 0 1 0 6H8',
        'reading'     => 'M3 5a2 2 0 0 1 2-2h5a2 2 0 0 1 2 2v16a2 2 0 0 0-2-2H3zM21 5a2 2 0 0 0-2-2h-5a2 2 0 0 0-2 2v16a2 2 0 0 1 2-2h7z',
        'learning'    => 'M2 9l10-5 10 5-10 5zM6 11v5c3 2 9 2 12 0v-5',
        'volunteer'   => 'M12 21s-7-4.5-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 5.5-7 10-7 10z',
        'diary'       => 'M5 3h12a2 2 0 0 1 2 2v16H7a2 2 0 0 1-2-2zM9 8h6M9 12h6',
        'wallet'      => 'M3 6h18v14H3zM3 10h18',
        'membership'  => 'M3 5h18v14H3zM9 9.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM14 10h4M14 14h4',
        'requests'    => 'M12 3v18M16 7H10a3 3 0 0 0 0 6h4a3 3 0 0 1 0 6H8',
    ];

    public static function icon(string $id): string { return self::ICONS[$id] ?? 'M12 12h.01'; }

    /**
     * The sheet's shortcuts for this account: the member's own choice when it
     * is valid, else the defaults — visible sections only, at most eight,
     * topped up from the rest of the registry so the grid is never half empty.
     * @param string[] $chosen
     * @return string[]
     */
    public static function shortcuts(array $ctx, array $chosen = []): array
    {
        $ok = static fn(string $id): bool => $id !== 'overview' && self::visible($id, $ctx);
        $picked = array_values(array_unique(array_filter(array_map('strval', $chosen), $ok)));
        if ($picked) return array_slice($picked, 0, self::SHORTCUT_MAX);
        $out = array_values(array_filter(self::SHORTCUTS, $ok));
        foreach (array_keys(self::SECTIONS) as $id) {
            if (count($out) >= self::SHORTCUT_MAX) break;
            if ($ok($id) && !in_array($id, $out, true) && !in_array(self::SECTIONS[$id]['group'], ['Home'], true)) $out[] = $id;
        }
        return array_slice($out, 0, self::SHORTCUT_MAX);
    }

    public static function visible(string $id, array $ctx): bool
    {
        $s = self::SECTIONS[$id] ?? null;
        if ($s === null) return false;
        foreach ($s['for'] as $flag) {
            if ($flag === 'all' || !empty($ctx[$flag])) return true;
        }
        return false;
    }

    public static function label(string $id, array $ctx): string
    {
        $s = self::SECTIONS[$id];
        return empty($ctx['org']) && isset($s['label_guest']) ? $s['label_guest'] : $s['label'];
    }

    /** group => [ [id, label], … ] in GROUPS order, visible sections only. */
    public static function groups(array $ctx): array
    {
        $out = [];
        foreach (self::GROUPS as $g) {
            foreach (self::SECTIONS as $id => $s) {
                if ($s['group'] === $g && self::visible($id, $ctx)) $out[$g][] = [$id, self::label($id, $ctx)];
            }
        }
        return $out;
    }

    /** The view files to draw, once each, Today first (it is the one not hidden). */
    public static function views(array $ctx): array
    {
        $out = [];
        foreach (self::SECTIONS as $id => $s) {
            if (self::visible($id, $ctx)) $out[$s['view']] = true;
        }
        return array_keys($out);
    }

    /** [ [id, label], … ] for the phone tab bar. */
    public static function tabs(array $ctx): array
    {
        $out = [];
        foreach (self::TABS as $slot) {
            foreach ($slot as $id) {
                if (self::visible($id, $ctx)) { $out[] = [$id, self::TAB_LABELS[$id] ?? self::label($id, $ctx)]; break; }
            }
        }
        return $out;
    }
}
