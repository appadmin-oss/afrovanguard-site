<?php
/**
 * lib/StudioSections.php — the ONE list of the Studio's sections.
 *
 * The Studio's screens used to be listed three times: the sidebar in
 * admin/index.php, the screen→role table (TAB_MIN) in admin/app.js, and the
 * server's gates in admin/api.php. Three lists drift; NGG learned it when a
 * working link rendered as a 404, and here it had already happened — the
 * sidebar hid Celebrations and Communities from editors while the server let
 * an editor save and delete them.
 *
 * Now the sidebar is drawn from this list, the role each screen needs is read
 * from it by the page (admin/app.js, via the JSON the page carries), and
 * tests/studioregistry.test.php fails the build when a section has no screen,
 * no loader, or is listed twice. Add a section HERE and nowhere else in the nav.
 *
 *   id     the screen (`#<id>View`, `activateTab('<id>')`, `#<id>` link)
 *   group  the sidebar heading it sits under ('' = above the first heading)
 *   min    the least Studio role that sees it: editor < admin < superadmin
 *   cap    a trust the section also needs, granted per person (money); a Super Admin holds it
 *   badge  an optional counter beside the label, filled by the screen's loader
 *   frames a console that is its own set of pages (NextGen Vanguard, Appeals):
 *          one tab per page, opened inside the Studio, deep-linked #ngv/fines
 *   icon   the inner markup of a 24×24 stroke icon
 */
declare(strict_types=1);

final class StudioSections
{
    /** Old screen names that open another one — kept so a bookmark still lands. */
    public const ALIASES = ['homepage' => 'site', 'pages' => 'site'];

    /**
     * The staff consoles that are not Studio screens. They stay where they are
     * (each is its own page); this list is what lets the Studio's one search
     * (Ctrl+K) find them, so nobody has to know which console holds a task.
     */
    public const CONSOLES = [
        ['label' => 'Vanguard Quest desk', 'url' => '/mentorship/compliance/', 'min' => 'admin', 'words' => 'quest compliance awards deductions promotions demotions'],
    ];

    /** Groups that start folded: the settings nobody opens every day. */
    public const FOLDED = ['Settings'];

    public static function all(): array
    {
        return [
        ['id' => 'overview', 'group' => '', 'label' => 'Overview', 'min' => 'editor', 'badge' => null,
         'icon' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>'],
        ['id' => 'members', 'group' => 'People', 'label' => 'Members', 'min' => 'admin', 'badge' => null,
         'icon' => '<circle cx="9" cy="8" r="3.5"/><path d="M3 20c0-3.6 2.7-5.5 6-5.5"/><path d="M15 12l2 2 4-4"/>'],
        ['id' => 'attendance', 'group' => 'People', 'label' => 'Attendance', 'min' => 'admin', 'badge' => ['id' => 'attBadge', 'class' => 'tab-badge', 'text' => ''],
         'icon' => '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2.5"/><path d="M9 2h6"/>'],
        ['id' => 'ngv', 'group' => 'People', 'label' => 'NextGen Vanguard', 'min' => 'admin', 'badge' => null,
         // Its own pages, one tab each, opened here (academy/ngv/_in-studio.php drops their second navigation).
         'frames' => [
           ['key' => 'members', 'label' => 'Vanguards', 'url' => '/academy/ngv/members.php'],
           ['key' => 'desk', 'label' => 'Registration desk', 'url' => '/academy/ngv/desk.php'],
           ['key' => 'attendance', 'label' => 'Attendance', 'url' => '/academy/ngv/attendance.php'],
           ['key' => 'fines', 'label' => 'Fines', 'url' => '/academy/ngv/fines.php'],
           ['key' => 'reading', 'label' => 'Reading review', 'url' => '/academy/ngv/reading-review.php'],
           ['key' => 'books', 'label' => 'Book list', 'url' => '/academy/ngv/books.php'],
           ['key' => 'courses', 'label' => 'Course list', 'url' => '/academy/ngv/courses.php'],
           ['key' => 'page', 'label' => 'Programme page', 'url' => '/academy/ngv/edit.php'],
         ],
         'icon' => '<path d="M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.4 6.8 19.1l1-5.8L3.5 9.2l5.9-.9z"/>'],
        ['id' => 'mentorship', 'group' => 'People', 'label' => 'Mentorship', 'min' => 'admin', 'badge' => null,
         'icon' => '<circle cx="8" cy="9" r="2.6"/><circle cx="16.5" cy="8" r="2.2"/><path d="M3.5 19c0-2.8 2.1-4.3 4.5-4.3s4.5 1.5 4.5 4.3"/><path d="M14.5 14.4c2-.3 4 .9 4 3.1"/><path d="M12 13l2.5-2.4"/>'],
        ['id' => 'people', 'group' => 'People', 'label' => 'Team directory', 'min' => 'admin', 'badge' => null,
         'icon' => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.3 2.9-5.2 6.5-5.2s6.5 1.9 6.5 5.2"/><path d="M17 8.2a3 3 0 0 1 0 5.6"/><path d="M19 20c0-2.2-1-3.7-2.6-4.6"/>'],
        ['id' => 'celebrations', 'group' => 'People', 'label' => 'Celebrations', 'min' => 'admin', 'badge' => null,
         'icon' => '<rect x="3" y="9" width="18" height="12" rx="1.5"/><path d="M3 13h18M12 9v12"/><path d="M12 9c-1.5-2.5-5-2.5-5-.5 0 1 .8 1.5 2 1.5h3zM12 9c1.5-2.5 5-2.5 5-.5 0 1-.8 1.5-2 1.5h-3z"/>'],
        ['id' => 'gifts', 'group' => 'Money', 'cap' => 'money', 'label' => 'Bank transfers', 'min' => 'admin', 'badge' => ['id' => 'giftsBadge', 'class' => 'tab-badge', 'text' => ''],
         'icon' => '<path d="M3 10h18"/><path d="M5 10V20M9 10v10M15 10v10M19 10v10"/><path d="M2 20h20"/><path d="M12 3l9 5H3l9-5z"/>'],
        ['id' => 'donations', 'group' => 'Money', 'cap' => 'money', 'label' => 'Donations', 'min' => 'admin', 'badge' => null,
         'icon' => '<path d="M12 2v20"/><path d="M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'],
        ['id' => 'appeals', 'group' => 'Money', 'cap' => 'money', 'label' => 'Appeals', 'min' => 'admin', 'badge' => null,
         // Its own pages, one tab each, opened here (academy/ngv/_in-studio.php drops their second navigation).
         'frames' => [
           ['key' => 'manage', 'label' => 'Appeals', 'url' => '/give/manage.php'],
         ],
         'icon' => '<path d="M12 21s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 5.6-7 10-7 10z"/>'],
        ['id' => 'entries', 'group' => 'Content', 'label' => 'Diary', 'min' => 'editor', 'badge' => null,
         'icon' => '<path d="M4 4h10a2 2 0 0 1 2 2v14H6a2 2 0 0 1-2-2V4z"/><path d="M16 6h4v12a2 2 0 0 1-2 2"/><path d="M8 8h4M8 12h4"/>'],
        ['id' => 'site', 'group' => 'Content', 'label' => 'Site', 'min' => 'editor', 'badge' => ['id' => 'siteBadge', 'class' => 'tab-badge', 'text' => ''],
         'icon' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/><path d="M7 13h6M7 16h9"/>'],
        ['id' => 'media', 'group' => 'Content', 'label' => 'Media', 'min' => 'editor', 'badge' => null,
         'icon' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-8 9"/>'],
        ['id' => 'moderation', 'group' => 'Content', 'label' => 'Moderation', 'min' => 'editor', 'badge' => ['id' => 'modBadge', 'class' => 'tab-badge', 'text' => ''],
         'icon' => '<path d="M12 3l7 3v5c0 4.2-2.9 7.3-7 8-4.1-.7-7-3.8-7-8V6l7-3z"/><path d="M9 12l2 2 4-4"/>'],
        ['id' => 'inbox', 'group' => 'Content', 'label' => 'Inbox', 'min' => 'admin', 'badge' => null,
         'icon' => '<path d="M3 12h5l2 3h4l2-3h5"/><path d="M5 5h14a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/>'],
        ['id' => 'academy', 'group' => 'Programmes', 'label' => 'Academy', 'min' => 'editor', 'badge' => null,
         'icon' => '<path d="M12 4L2 9l10 5 10-5-10-5z"/><path d="M6 11v5c0 1.3 2.7 2.5 6 2.5s6-1.2 6-2.5v-5"/><path d="M22 9v5"/>'],
        ['id' => 'summit', 'group' => 'Programmes', 'label' => 'Summit', 'min' => 'admin', 'badge' => ['id' => 'sumBadge', 'class' => 'tab-badge', 'text' => ''],
         'icon' => '<path d="M4 20h16"/><path d="M6 20V9l6-5 6 5v11"/><path d="M10 20v-5h4v5"/>'],
        ['id' => 'events', 'group' => 'Programmes', 'label' => 'Events', 'min' => 'admin', 'badge' => null,
         'icon' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/><path d="M12 12.5l1.1 2.2 2.4.3-1.8 1.7.5 2.4-2.2-1.2-2.2 1.2.5-2.4-1.8-1.7 2.4-.3z"/>'],
        ['id' => 'sessions', 'group' => 'Programmes', 'label' => 'Sessions', 'min' => 'admin', 'badge' => ['id' => 'psLiveBadge', 'class' => 'tab-badge tab-badge--live', 'text' => 'Live'],
         'icon' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/><path d="M7 13h4v4H7z"/>'],
        ['id' => 'communities', 'group' => 'Programmes', 'label' => 'Communities', 'min' => 'admin', 'badge' => null,
         'icon' => '<path d="M21 15a2 2 0 0 1-2 2H8l-4 4V5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2z"/><path d="M8 9h8M8 12h5"/>'],
        ['id' => 'admins', 'group' => 'Settings', 'label' => 'Team & roles', 'min' => 'superadmin', 'badge' => null,
         'icon' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="3.2"/><path d="M22 11l-2.5 2.5L18 12"/>'],
        ['id' => 'signin', 'group' => 'Settings', 'label' => 'Sign-in', 'min' => 'superadmin', 'badge' => null,
         'icon' => '<circle cx="8" cy="15" r="4"/><path d="M10.8 12.2L20 3"/><path d="M16 5l3 3M18.5 7.5l1.5 1.5"/>'],
        ['id' => 'system', 'group' => 'Settings', 'label' => 'System', 'min' => 'admin', 'badge' => null,
         'icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 13a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 0 1-4 0v-.1A1.7 1.7 0 0 0 7 19.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H3a2 2 0 0 1 0-4h.1A1.7 1.7 0 0 0 4.7 7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 10 4.6V4a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9z"/>'],
        ['id' => 'webhooks', 'group' => 'Settings', 'label' => 'Webhooks', 'min' => 'admin', 'badge' => null,
         'icon' => '<path d="M9.5 13.5a4 4 0 0 0 5.7 0l2.8-2.8a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14.5 10.5a4 4 0 0 0-5.7 0l-2.8 2.8a4 4 0 0 0 5.7 5.7l1-1"/>'],
        ['id' => 'rules', 'group' => 'Settings', 'label' => 'Rules & AI', 'min' => 'superadmin', 'badge' => null,
         'icon' => '<path d="M12 3v18"/><path d="M5 7h14"/><path d="M7 7l-3 6h6L7 7z"/><path d="M17 7l-3 6h6l-3-6z"/>'],
        ['id' => 'aiops', 'group' => 'Settings', 'label' => 'AI Ops', 'min' => 'admin', 'badge' => ['id' => 'opsBadge', 'class' => 'tab-badge', 'text' => ''],
         'icon' => '<path d="M3 13h3l2.5-6 3 12 2.5-9 2 3h5"/><circle cx="19" cy="6" r="2"/>'],
        ['id' => 'activity', 'group' => 'Settings', 'label' => 'Activity', 'min' => 'admin', 'badge' => null,
         'icon' => '<path d="M3 12h4l2 6 4-14 2 8h6"/>'],
        ['id' => 'database', 'group' => 'Settings', 'label' => 'Database', 'min' => 'superadmin', 'badge' => null,
         'icon' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>'],
        ['id' => 'design', 'group' => 'Settings', 'label' => 'Design', 'min' => 'superadmin', 'badge' => null,
         'icon' => '<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2a10 10 0 0 0 0 20c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.2 0-1 .8-1.5 1.7-1.5H17a5 5 0 0 0 5-5c0-5-4.5-9-10-9z"/>'],
        ['id' => 'guide', 'group' => 'Help', 'label' => 'Guide', 'min' => 'editor', 'badge' => null,
         'icon' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 4.5 1.5c0 1.5-2 2-2 3"/><line x1="12" y1="17" x2="12" y2="17"/>'],
        ];
    }

    /** id => least role. The page reads this; nothing else defines it. */
    public static function minRoles(): array
    {
        $out = [];
        foreach (self::all() as $s) $out[$s['id']] = $s['min'];
        foreach (self::ALIASES as $from => $to) $out[$from] = $out[$to] ?? 'admin';
        return $out;
    }

    /** What the page carries for app.js: the roles, the aliases, and what the one search can find. */
    public static function forPage(): array
    {
        return ['min' => self::minRoles(), 'aliases' => self::ALIASES,
            /* A section that also needs a trust (AdminRoles::CAPS): money. */
            'caps' => array_column(array_filter(self::all(), static fn($s) => !empty($s['cap'])), 'cap', 'id'),
            'sections' => array_map(static fn($s) => ['id' => $s['id'], 'label' => $s['label'], 'group' => $s['group']], self::all()),
            'consoles' => self::CONSOLES,
            'frames' => array_column(array_map(static fn($s) => [$s['id'], $s['frames']], self::framed()), 1, 0)];
    }

    /** The sections that are a console of their own pages, opened here one tab each. */
    public static function framed(): array
    {
        return array_values(array_filter(self::all(), static fn($s) => !empty($s['frames'])));
    }

    /**
     * Their screens: the section's name, a tab per page, a way out to the page
     * on its own, and the page itself in a frame (same origin; the page drops
     * its own navigation inside, academy/ngv/_in-studio.php).
     */
    public static function frameViewsHtml(): string
    {
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $out = '';
        foreach (self::framed() as $s) {
            $id = $s['id'];
            $out .= '  <main class="studio-main studio-frame" id="' . $h($id) . 'View" data-frame-section="' . $h($id) . '" hidden>' . "\n"
                  . '    <div class="studio-head"><div><h1>' . $h($s['label']) . '</h1></div>'
                  . '<a class="btn btn-outline btn-sm" data-frame-own="' . $h($id) . '" href="' . $h($s['frames'][0]['url']) . '" target="_blank" rel="noopener">Open on its own <span aria-hidden="true">↗</span><span class="sr-only"> (opens in a new tab)</span></a></div>' . "\n";
            if (count($s['frames']) > 1) {
                $out .= '    <div class="studio-subtabs" role="tablist" aria-label="' . $h($s['label']) . ' pages">';
                foreach ($s['frames'] as $i => $f) $out .= '<button class="subtab' . ($i === 0 ? ' active' : '') . '" role="tab" aria-selected="' . ($i === 0 ? 'true' : 'false') . '" data-frame-tab="' . $h($id) . '" data-frame-key="' . $h($f['key']) . '">' . $h($f['label']) . '</button>';
                $out .= "</div>\n";
            }
            $out .= '    <iframe class="studio-frame-page" data-frame-for="' . $h($id) . '" title="' . $h($s['label']) . '" loading="lazy"></iframe>' . "\n"
                  . "  </main>\n";
        }
        return $out;
    }

    /** The sections in sidebar order, grouped under their headings. */
    public static function groups(): array
    {
        $g = [];
        foreach (self::all() as $s) $g[$s['group']][] = $s;
        return $g;
    }

    /** The sidebar, drawn. Every value is ours, but escaped all the same. */
    public static function navHtml(): string
    {
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $out = '';
        $first = true;
        foreach (self::groups() as $group => $items) {
            $out .= '      <div class="nav-group">' . "\n";
            $fold = in_array($group, self::FOLDED, true);
            $gid = 'navg-' . strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $group));
            /* A folded group's heading is its button; the screens under it stay
               in the page (so search and links still find them), just closed. */
            if ($fold) $out .= '        <button type="button" class="nav-group-h nav-fold" aria-expanded="false" aria-controls="' . $h($gid) . '">' . $h($group) . '<span class="nav-fold-ico" aria-hidden="true"></span></button>' . "\n"
                             . '        <div class="nav-group-items" id="' . $h($gid) . '" hidden>' . "\n";
            elseif ($group !== '') $out .= '        <p class="nav-group-h">' . $h($group) . '</p>' . "\n";
            foreach ($items as $s) {
                $on = $first; $first = false;
                $badge = $s['badge'] ? '<span class="' . $h($s['badge']['class']) . '" id="' . $h($s['badge']['id']) . '" hidden>' . $h($s['badge']['text']) . '</span>' : '';
                $out .= '        <button class="tab' . ($on ? ' active' : '') . '" data-tab="' . $h($s['id']) . '"' . ($on ? ' aria-current="page"' : '') . '>'
                      . '<svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $s['icon'] . '</svg>'
                      . '<span>' . $h($s['label']) . '</span>' . $badge . '</button>' . "\n";
            }
            if ($fold) $out .= '        </div>' . "\n";
            $out .= '      </div>' . "\n";
        }
        return $out;
    }
}
