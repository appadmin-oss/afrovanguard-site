<?php
/**
 * lib/GoogleHub.php — Google Workspace, as part of the portal rather than a
 * page beside it.
 *
 * There used to be a /workspace page (and a "Workspace" view in the portal
 * that pointed at it): a second home with its own inbox, calendar, Drive
 * and Chat. People live in the portal, so Google is in the portal now, where
 * each thing belongs —
 *
 *   Today      inbox, recent files and the quick actions (compose, new doc,
 *              start a Meet), and the connect button when not connected
 *   Calendar   the member's own Google Calendar beside the team's
 *              (TeamCalendar::feed)
 *   Chat       Google Chat spaces, beside the portal's own channels
 *   Suite      the Google apps, as tiles, with the rest of the tools
 *   Meetings   Meet links, recording and transcripts (lib/Meetings.php)
 *   the side   the Google account the portal is connected to
 *
 * and /workspace sends people to the portal. This file is what every one of
 * those reads to know what Google can do for this person right now — and,
 * for an administrator, what is wrong when it cannot.
 */
declare(strict_types=1);

require_once __DIR__ . '/workspace.php';

final class GoogleHub
{
    /** Where the Google connect round-trip comes back to. */
    public const HOME = '/portal/';

    /**
     * What the portal draws for this person:
     *  oauth       members can connect their own Google account here
     *  connected   this member has
     *  org         the organisation's service account is set up (team calendar, Meet, Drive folder)
     *  org_member  an @organisation member (Google features are theirs)
     *  admin       sees what is wrong when something is
     *  problem     for an admin: Google's latest refusal, still current, in words; else ''
     *  connect     the connect URL;  apps  the Google apps as tiles
     */
    public static function state(array $u): array
    {
        $uid = (int) ($u['id'] ?? 0);
        $isAdmin = class_exists('LmsAuth') && LmsAuth::atLeast($u, 'admin');
        $oauth = class_exists('GoogleWorkspaceUser') && GoogleWorkspaceUser::configured();
        $org = class_exists('GoogleWorkspace') && GoogleWorkspace::configured();
        return [
            'oauth' => $oauth,
            'connected' => $oauth && $uid > 0 && GoogleWorkspaceUser::connected($uid),
            /* delegated: reached through the organisation's service account, nothing to connect */
            'mode' => $oauth && $uid > 0 ? GoogleWorkspaceUser::mode($uid) : '',
            'org' => $org,
            'org_member' => class_exists('LmsAuth') && LmsAuth::isOrgMember($u),
            'admin' => $isAdmin,
            'email' => (string) ($u['email'] ?? ''),
            'domain' => av_workspace_domain(),
            'problem' => $isAdmin ? self::problem($oauth, $org) : '',
            'connect' => '/auth/google/connect?next=' . rawurlencode(self::HOME),
            'apps' => array_values(array_filter(av_workspace_surfaces($isAdmin), static fn($s) => empty($s['internal']))),
        ];
    }

    /**
     * What is wrong with Google, for an administrator, in a line — or ''.
     * Not set up at all; or Google's latest refusal, when nothing has
     * succeeded since (GoogleWorkspace records both).
     */
    public static function problem(bool $oauth, bool $org): string
    {
        if (!$oauth && !$org) return 'Google is not set up: members cannot connect their Google account, and meetings get no Meet links. Studio → Rules & AI → Setup → Google sign-in & Workspace.';
        if (!$org) return 'The organisation’s Google service account is not set up, so meetings get no Meet links or recordings. Studio → Rules & AI → Setup → Google sign-in & Workspace.';
        $f = class_exists('GoogleAuth') ? GoogleAuth::lastFailure() : null;
        if (!$f) return '';
        $at = strtotime((string) ($f['at'] ?? '')) ?: 0;
        $ok = strtotime((string) (class_exists('Database') ? (Database::metaGet('google_last_ok') ?? '') : '')) ?: 0;
        if ($at < time() - 3 * 86400 || ($ok > $at && ($f['where'] ?? '') === 'workspace')) return '';
        $why = (string) ($f['why'] ?? '');
        if (preg_match('/\[(https:[^\]]+)\]$/', $why, $m)) {
            $why = trim(substr($why, 0, -strlen($m[0])));
            return 'Google refused: ' . $why . ' — domain-wide delegation needs ' . $m[1] . '. Studio → Rules & AI → Setup → Test Google Workspace lists every scope.';
        }
        return 'Google refused (' . (string) ($f['where'] ?? '') . '): ' . $why . '. Studio → Rules & AI → Setup → Test Google Workspace shows what to change.';
    }

    /**
     * Keep one of the site's events (lib/SiteEvents.php) on the org's Google
     * Calendar, so the team sees it beside everything else. Published → there
     * (made or updated), draft or cancelled → taken off. Best-effort: the
     * event is saved whatever Google says.
     */
    public static function mirrorEvent(array $ev): void
    {
        if (!class_exists('GoogleWorkspace') || !GoogleWorkspace::calendarWriteEnabled()) return;
        $gid = (string) ($ev['google_event_id'] ?? '');
        if (($ev['status'] ?? '') !== 'published') { self::unmirrorEvent($ev); return; }
        $tz = 'Africa/Lagos';
        $site = rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/');
        $body = ['summary' => mb_substr((string) $ev['title'], 0, 200),
                 'description' => mb_substr(trim(($ev['summary'] ?? '') . "\n\n" . ($ev['body'] ?? '') . (($ev['audience'] ?? '') === 'public' ? "\n\n" . $site . '/events/' . $ev['slug'] . '/' : '')), 0, 4000),
                 'location' => mb_substr(trim(($ev['venue'] ?? '') . ' ' . ($ev['location'] ?? '')), 0, 300)];
        if (!empty($ev['all_day'])) {
            $end = ($ev['ends_at'] ?? '') !== '' ? substr((string) $ev['ends_at'], 0, 10) : substr((string) $ev['starts_at'], 0, 10);
            $body['start'] = ['date' => substr((string) $ev['starts_at'], 0, 10)];
            $body['end'] = ['date' => (new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d')];
        } else {
            $s = new DateTimeImmutable((string) $ev['starts_at'], new DateTimeZone($tz));
            $f = ($ev['ends_at'] ?? '') !== '' ? new DateTimeImmutable((string) $ev['ends_at'], new DateTimeZone($tz)) : $s->modify('+2 hours');
            $body['start'] = ['dateTime' => $s->format('Y-m-d\TH:i:s'), 'timeZone' => $tz];
            $body['end'] = ['dateTime' => $f->format('Y-m-d\TH:i:s'), 'timeZone' => $tz];
        }
        $id = GoogleWorkspace::upsertEvent($gid, $body);
        if ($id !== null && $id !== $gid && class_exists('SiteEvents')) SiteEvents::setGoogleId((int) $ev['id'], $id);
    }

    public static function unmirrorEvent(array $ev): void
    {
        $gid = (string) ($ev['google_event_id'] ?? '');
        if ($gid === '' || !class_exists('GoogleWorkspace')) return;
        GoogleWorkspace::deleteCalendarEvent($gid);
        if (class_exists('SiteEvents')) SiteEvents::setGoogleId((int) $ev['id'], '');
    }

    /** The sentence for the connect round-trip's ?google=… / ?google_e=…, or null. */
    public static function notice(array $get): ?array
    {
        $e = (string) ($get['google_e'] ?? '');
        return av_workspace_notice($e, isset($get['google']) && $get['google'] === 'connected');
    }
}
