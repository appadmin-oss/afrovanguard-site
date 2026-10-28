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
        'bills'       => ['group' => 'Vanguard Quest', 'label' => 'Bills & appeals', 'for' => ['all'], 'view' => 'bills'],
        'mentorship'  => ['group' => 'Vanguard Quest', 'label' => 'Mentorship', 'for' => ['all'], 'view' => 'mentorship'],
        'attendance'  => ['group' => 'Vanguard Quest', 'label' => 'Attendance & pass', 'for' => ['gate', 'ngv'], 'view' => 'attendance'],
        'ngv-fines'   => ['group' => 'Vanguard Quest', 'label' => 'Fines', 'for' => ['ngv'], 'view' => 'ngv'],
        /* A member outside NGV who has been fined (NgvFines: any member can be). */
        'fines'       => ['group' => 'Vanguard Quest', 'label' => 'Fines', 'for' => ['fines'], 'view' => 'fines'],

        'ngv'         => ['group' => 'NextGen Vanguard', 'label' => 'Programme', 'for' => ['ngv'], 'view' => 'ngv'],
        'ngv-account' => ['group' => 'NextGen Vanguard', 'label' => 'Fees & account', 'for' => ['ngv'], 'view' => 'ngv'],

        'learning'    => ['group' => 'Learn', 'label' => 'Learning', 'for' => ['all'], 'view' => 'learning'],

        'volunteer'   => ['group' => 'Volunteering', 'label' => 'Volunteer dashboard', 'for' => ['vol'], 'view' => 'volunteer'],

        'diary'       => ['group' => 'Learn', 'label' => 'Diary', 'for' => ['all'], 'view' => 'diary'],
        'membership'  => ['group' => 'You', 'label' => 'Membership', 'label_guest' => 'Account', 'for' => ['all'], 'view' => 'membership'],
        'requests'    => ['group' => 'You', 'label' => 'Finance requests', 'for' => ['all'], 'view' => 'requests'],
    ];

    /**
     * The phone tab bar: four slots, each the first visible id in its list,
     * then "More". The second and third slots differ by who is signed in.
     */
    public const TABS = [['overview'], ['tasks', 'ngv', 'learning'], ['chat', 'community'], ['diary']];

    /** Short labels for the tab bar, where the sidebar's would not fit. */
    public const TAB_LABELS = ['overview' => 'Today', 'ngv' => 'Programme', 'chat' => 'Chat'];

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
