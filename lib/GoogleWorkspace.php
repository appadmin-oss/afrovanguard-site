<?php
/**
 * lib/GoogleWorkspace.php — REAL Google Workspace reads (not just deep links).
 *
 * Dependency-free service-account client (same RS256-JWT → access-token pattern
 * as lib/Drive.php) that actually calls the Google REST APIs and returns live
 * data: upcoming Calendar events, files in a shared Drive folder, and — with
 * domain-wide delegation — the Workspace user directory.
 *
 * Configure (config.php or env):
 *   AV_GDRIVE_SERVICE_ACCOUNT  service-account JSON (raw) or a path to it
 *   AV_WS_SUBJECT              admin email to impersonate for the Directory API
 *                             (domain-wide delegation; falls back to ADMIN_EMAIL)
 *   AV_WS_CALENDAR_ID          calendar to read (falls back to the subject / domain)
 *   AV_WS_DRIVE_FOLDER_ID      Drive folder to list (or AV_GDRIVE_FOLDER_ID)
 *
 * Every read is best-effort: any auth/transport/permission error is logged and
 * returns [] so the member portal cleanly falls back to the launchpad + embeds.
 * Unconfigured ⇒ configured() is false and nothing is attempted.
 */
declare(strict_types=1);

final class GoogleWorkspace
{
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const SCOPE_CALENDAR  = 'https://www.googleapis.com/auth/calendar.readonly';
    const SCOPE_CALENDAR_RW = 'https://www.googleapis.com/auth/calendar';       // create/update/delete events + Meet
    const SCOPE_DRIVE     = 'https://www.googleapis.com/auth/drive.metadata.readonly';
    const SCOPE_DRIVE_RO  = 'https://www.googleapis.com/auth/drive.readonly';   // read file content (transcripts)
    const SCOPE_DIRECTORY = 'https://www.googleapis.com/auth/admin.directory.user.readonly';
    const SCOPE_GROUPS    = 'https://www.googleapis.com/auth/admin.directory.group.readonly';
    const SCOPE_MEET_RO   = 'https://www.googleapis.com/auth/meetings.space.readonly'; // read conference records
    const SCOPE_REPORTS   = 'https://www.googleapis.com/auth/admin.reports.audit.readonly'; // org Meet audit log

    /** @var array<string,array{v:string,exp:int}> per-request token cache, keyed by scope+subject */
    private static array $tokens = [];

    public static function configured(): bool { return self::credentials() !== null; }

    /** Full write sync is opt-in (creates real calendar events + Meet links). */
    public static function calendarWriteEnabled(): bool
    {
        if (!self::configured()) return false;
        $v = (string) Config::get('AV_WS_CALENDAR_WRITE', '1'); // default on when configured
        return $v !== '0' && strtolower($v) !== 'false';
    }

    /** OAuth token endpoint (overridable for testing/proxying). */
    private static function tokenUrl(): string { return (string) (Config::get('AV_WS_TOKEN_URL', '') ?: self::TOKEN_URL); }
    /** Google API host prefix (overridable for testing). */
    private static function apiBase(): string { return rtrim((string) (Config::get('AV_WS_BASE_URL', '') ?: 'https://www.googleapis.com'), '/'); }
    /** Meet REST API host (own host; overridable for testing). */
    private static function meetBase(): string { return rtrim((string) (Config::get('AV_MEET_BASE_URL', '') ?: 'https://meet.googleapis.com'), '/'); }
    /** Admin SDK Reports API host (overridable for testing). */
    private static function reportsBase(): string { return rtrim((string) (Config::get('AV_REPORTS_BASE_URL', '') ?: 'https://admin.googleapis.com'), '/'); }

    private static function credentials(): ?array
    {
        $v = (string) Config::get('AV_GDRIVE_SERVICE_ACCOUNT', '');
        if ($v === '') return null;
        $json = str_starts_with(ltrim($v), '{') ? $v : (is_file($v) ? (string) @file_get_contents($v) : '');
        $c = json_decode($json, true);
        return (is_array($c) && !empty($c['client_email']) && !empty($c['private_key'])) ? $c : null;
    }

    /** Admin to impersonate for delegated APIs (Directory). */
    private static function subject(): string
    {
        return (string) (Config::get('AV_WS_SUBJECT', '') ?: Config::get('ADMIN_EMAIL', ''));
    }

    private static function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

    /**
     * Mint (per-request cache) an access token for one scope. $impersonate adds
     * the `sub` claim (required for Directory; harmless for Calendar/Drive on
     * resources the impersonated user can see). Returns null on any failure.
     */
    private static function accessToken(string $scope, bool $impersonate = false, ?string $asUser = null): ?string
    {
        $c = self::credentials();
        if (!$c) return null;
        // An explicit $asUser wins (impersonate that specific mailbox — required
        // by the Meet API, which only exposes records to a conference host/guest).
        $sub = $asUser !== null && $asUser !== '' ? $asUser : ($impersonate ? self::subject() : '');
        $key = $scope . '|' . $sub;
        if (isset(self::$tokens[$key]) && self::$tokens[$key]['exp'] > time() + 30) return self::$tokens[$key]['v'];

        $now = time();
        $claim = ['iss' => $c['client_email'], 'scope' => $scope, 'aud' => self::TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600];
        if ($sub !== '') $claim['sub'] = $sub;
        $header = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = self::b64url(json_encode($claim));
        $sig = '';
        if (!openssl_sign($header . '.' . $payload, $sig, $c['private_key'], 'sha256WithRSAEncryption')) return null;
        $assertion = $header . '.' . $payload . '.' . self::b64url($sig);

        $res = self::http('POST', self::tokenUrl(), null, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion,
        ]));
        if (!$res || empty($res['json']['access_token'])) {
            if ($res) error_log('[workspace] token error ' . $res['code'] . ': ' . substr((string) $res['body'], 0, 200));
            return null;
        }
        self::$tokens[$key] = ['v' => (string) $res['json']['access_token'], 'exp' => $now + (int) ($res['json']['expires_in'] ?? 3600)];
        return self::$tokens[$key]['v'];
    }

    /** Minimal curl wrapper → ['code'=>int,'body'=>string,'json'=>?array] or null on transport error.
     *  $jsonBody, when given, is sent as an application/json request body (POST/PATCH). */
    private static function http(string $method, string $url, ?string $bearer, ?string $postBody = null, ?string $jsonBody = null): ?array
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => &$headers];
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $opt[CURLOPT_CUSTOMREQUEST] = $method;
            $opt[CURLOPT_POSTFIELDS] = $jsonBody;
        } elseif ($method === 'POST') {
            $opt[CURLOPT_POST] = true; if ($postBody !== null) $opt[CURLOPT_POSTFIELDS] = $postBody;
        } elseif ($method !== 'GET') {
            $opt[CURLOPT_CUSTOMREQUEST] = $method;
        }
        curl_setopt_array($ch, $opt);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) { error_log('[workspace] transport: ' . $err); return null; }
        return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
    }

    private static function apiGet(string $url, string $scope, bool $impersonate, ?string $asUser = null): ?array
    {
        $tok = self::accessToken($scope, $impersonate, $asUser);
        if (!$tok) return null;
        $res = self::http('GET', $url, $tok);
        if (!$res || $res['code'] >= 400) {
            if ($res) error_log('[workspace] GET ' . $url . ' → ' . $res['code'] . ': ' . substr((string) $res['body'], 0, 200));
            return null;
        }
        return is_array($res['json']) ? $res['json'] : null;
    }

    /** POST/PATCH/DELETE a JSON body to a Google API. Returns the decoded body or null. */
    private static function apiSend(string $method, string $url, string $scope, bool $impersonate, ?array $body = null, ?string $asUser = null): ?array
    {
        $tok = self::accessToken($scope, $impersonate, $asUser);
        if (!$tok) return null;
        $res = self::http($method, $url, $tok, null, $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ($method === 'DELETE' ? '' : '{}'));
        if (!$res || $res['code'] >= 400) {
            if ($res) error_log('[workspace] ' . $method . ' ' . $url . ' → ' . $res['code'] . ': ' . substr((string) $res['body'], 0, 300));
            return null;
        }
        return is_array($res['json']) ? $res['json'] : ($res['code'] < 300 ? ['ok' => true] : null);
    }

    /* ── live reads (normalised; [] on any problem) ─────────────── */

    /** Upcoming events from a calendar, soonest first. */
    public static function calendarEvents(?string $calendarId = null, int $max = 8): array
    {
        if (!self::configured()) return [];
        $cal = $calendarId ?: (string) (Config::get('AV_WS_CALENDAR_ID', '') ?: self::subject());
        if ($cal === '') return [];
        $q = http_build_query([
            'timeMin' => gmdate('Y-m-d\TH:i:s\Z'), 'singleEvents' => 'true', 'orderBy' => 'startTime',
            'maxResults' => max(1, min(50, $max)),
        ]);
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal) . '/events?' . $q;
        $d = self::apiGet($url, self::SCOPE_CALENDAR, self::subject() !== '');
        if (!$d || empty($d['items'])) return [];
        $out = [];
        foreach ($d['items'] as $e) {
            $start = $e['start']['dateTime'] ?? ($e['start']['date'] ?? '');
            $out[] = [
                'id'       => (string) ($e['id'] ?? ''),
                'title'    => (string) ($e['summary'] ?? '(busy)'),
                'start'    => $start,
                'end'      => (string) ($e['end']['dateTime'] ?? ($e['end']['date'] ?? '')),
                'all_day'  => !isset($e['start']['dateTime']),
                'location' => (string) ($e['location'] ?? ''),
                'url'      => (string) ($e['htmlLink'] ?? ''),
                'meet'     => (string) ($e['hangoutLink'] ?? self::meetFrom($e)),
            ];
        }
        return $out;
    }

    /** Files in a shared Drive folder, most-recently-modified first. */
    public static function driveFiles(?string $folderId = null, int $max = 12): array
    {
        if (!self::configured()) return [];
        $folder = $folderId ?: (string) (Config::get('AV_WS_DRIVE_FOLDER_ID', '') ?: Config::get('AV_GDRIVE_FOLDER_ID', ''));
        if ($folder === '') return [];
        $q = http_build_query([
            'q' => "'" . str_replace("'", '', $folder) . "' in parents and trashed = false",
            'fields' => 'files(id,name,mimeType,modifiedTime,webViewLink,iconLink)',
            'orderBy' => 'modifiedTime desc', 'pageSize' => max(1, min(100, $max)),
            'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
        ]);
        $d = self::apiGet('https://www.googleapis.com/drive/v3/files?' . $q, self::SCOPE_DRIVE, self::subject() !== '');
        if (!$d || empty($d['files'])) return [];
        $out = [];
        foreach ($d['files'] as $f) {
            $out[] = [
                'name'     => (string) ($f['name'] ?? 'Untitled'),
                'mime'     => (string) ($f['mimeType'] ?? ''),
                'modified' => (string) ($f['modifiedTime'] ?? ''),
                'url'      => (string) ($f['webViewLink'] ?? ''),
                'icon'     => (string) ($f['iconLink'] ?? ''),
            ];
        }
        return $out;
    }

    /** Org users from the Directory API (needs domain-wide delegation + AV_WS_SUBJECT). */
    public static function directoryUsers(int $max = 200): array
    {
        if (!self::configured() || self::subject() === '') return [];
        $domain = (string) Config::get('AV_ORG_DOMAIN', 'afrovanguard.org.ng');
        $q = http_build_query(['domain' => $domain, 'maxResults' => max(1, min(500, $max)), 'orderBy' => 'email', 'viewType' => 'domain_public']);
        $d = self::apiGet('https://admin.googleapis.com/admin/directory/v1/users?' . $q, self::SCOPE_DIRECTORY, true);
        if (!$d || empty($d['users'])) return [];
        $out = [];
        foreach ($d['users'] as $u) {
            $out[] = [
                'name'    => (string) ($u['name']['fullName'] ?? ''),
                'email'   => (string) ($u['primaryEmail'] ?? ''),
                'photo'   => (string) ($u['thumbnailPhotoUrl'] ?? ''),
                'is_admin'=> !empty($u['isAdmin']),
                'suspended'=> !empty($u['suspended']),
            ];
        }
        return $out;
    }

    /* ── live writes (Calendar + Google Meet) ───────────────────────
     * Create a real calendar event with a Meet conference, invite the
     * attendees, and return the ids + join link. Impersonates the subject so
     * the event lives on a real Workspace calendar and Meet is provisioned.
     * Returns ['id','html_link','meet_url'] or null on any failure. */
    public static function createMeetEvent(string $title, string $startIso, int $durationMin, array $attendeeEmails = [], string $description = '', ?string $calendarId = null, bool $withMeet = true, array $recurrence = []): ?array
    {
        if (!self::calendarWriteEnabled()) return null;
        $cal = $calendarId ?: (string) (Config::get('AV_WS_CALENDAR_ID', '') ?: self::subject());
        if ($cal === '') return null;
        $startTs = strtotime($startIso) ?: time();
        $endTs   = $startTs + max(5, $durationMin) * 60;
        $tz      = (string) Config::get('AV_WS_TZ', 'Africa/Lagos');
        $event = [
            'summary'     => mb_substr($title, 0, 200),
            'description' => mb_substr($description, 0, 4000),
            'start'       => ['dateTime' => gmdate('c', $startTs), 'timeZone' => $tz],
            'end'         => ['dateTime' => gmdate('c', $endTs), 'timeZone' => $tz],
        ];
        // Recurring meeting: an array of RRULE strings (e.g. ['RRULE:FREQ=WEEKLY']).
        if ($recurrence) $event['recurrence'] = array_values(array_filter(array_map('strval', $recurrence)));
        $att = [];
        foreach ($attendeeEmails as $e) { $e = trim((string) $e); if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $att[] = ['email' => $e]; }
        if ($att) $event['attendees'] = $att;
        $qs = ['sendUpdates' => 'all'];
        if ($withMeet) {
            $event['conferenceData'] = ['createRequest' => [
                'requestId' => bin2hex(random_bytes(8)),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ]];
            $qs['conferenceDataVersion'] = '1';
        }
        $url = self::apiBase() . '/calendar/v3/calendars/' . rawurlencode($cal) . '/events?' . http_build_query($qs);
        $d = self::apiSend('POST', $url, self::SCOPE_CALENDAR_RW, self::subject() !== '', $event);
        if (!$d || empty($d['id'])) return null;
        return [
            'id'        => (string) $d['id'],
            'html_link' => (string) ($d['htmlLink'] ?? ''),
            'meet_url'  => (string) ($d['hangoutLink'] ?? self::meetFrom($d)),
        ];
    }

    /** Pull the Meet join URL out of an event's conferenceData entry points. */
    private static function meetFrom(array $event): string
    {
        foreach (($event['conferenceData']['entryPoints'] ?? []) as $ep) {
            if (($ep['entryPointType'] ?? '') === 'video' && !empty($ep['uri'])) return (string) $ep['uri'];
        }
        return '';
    }

    /** Patch an existing event (time/title/attendees). Returns true on success. */
    public static function updateCalendarEvent(string $eventId, array $patch, ?string $calendarId = null): bool
    {
        if (!self::calendarWriteEnabled() || $eventId === '') return false;
        $cal = $calendarId ?: (string) (Config::get('AV_WS_CALENDAR_ID', '') ?: self::subject());
        if ($cal === '') return false;
        $url = self::apiBase() . '/calendar/v3/calendars/' . rawurlencode($cal) . '/events/' . rawurlencode($eventId) . '?sendUpdates=all';
        return self::apiSend('PATCH', $url, self::SCOPE_CALENDAR_RW, self::subject() !== '', $patch) !== null;
    }

    /**
     * Create a plain calendar event (no Meet) on the org calendar — used to
     * mirror native portal team-events to Google Calendar. Supports all-day
     * ($start === '') and timed events. Naive local times are sent with the org
     * timeZone so Google interprets them correctly. Returns ['id','html_link']
     * or null. Dates 'Y-m-d', times 'H:i'.
     */
    public static function createEvent(string $title, string $date, string $start = '', string $end = '', string $location = '', string $description = '', ?string $calendarId = null): ?array
    {
        if (!self::calendarWriteEnabled()) return null;
        $cal = $calendarId ?: (string) (Config::get('AV_WS_CALENDAR_ID', '') ?: self::subject());
        if ($cal === '' || $title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
        $tz = (string) Config::get('AV_WS_TZ', 'Africa/Lagos');
        $event = ['summary' => mb_substr($title, 0, 200)];
        if ($description !== '') $event['description'] = mb_substr($description, 0, 4000);
        if ($location !== '')    $event['location']    = mb_substr($location, 0, 300);
        if ($start === '') {
            // All-day: Google's end date is exclusive, so it's the next day.
            $endDate = gmdate('Y-m-d', (strtotime($date . ' UTC') ?: time()) + 86400);
            $event['start'] = ['date' => $date];
            $event['end']   = ['date' => $endDate];
        } else {
            $endHm = $end;
            if ($endHm === '') { $t = strtotime($date . ' ' . $start); $endHm = $t ? date('H:i', $t + 3600) : $start; }
            $event['start'] = ['dateTime' => $date . 'T' . $start . ':00', 'timeZone' => $tz];
            $event['end']   = ['dateTime' => $date . 'T' . $endHm . ':00', 'timeZone' => $tz];
        }
        $url = self::apiBase() . '/calendar/v3/calendars/' . rawurlencode($cal) . '/events?' . http_build_query(['sendUpdates' => 'none']);
        $d = self::apiSend('POST', $url, self::SCOPE_CALENDAR_RW, self::subject() !== '', $event);
        if (!$d || empty($d['id'])) return null;
        return ['id' => (string) $d['id'], 'html_link' => (string) ($d['htmlLink'] ?? '')];
    }

    /** Cancel/delete an event (e.g. when a session is cancelled). */
    public static function deleteCalendarEvent(string $eventId, ?string $calendarId = null): bool
    {
        if (!self::calendarWriteEnabled() || $eventId === '') return false;
        $cal = $calendarId ?: (string) (Config::get('AV_WS_CALENDAR_ID', '') ?: self::subject());
        if ($cal === '') return false;
        $url = self::apiBase() . '/calendar/v3/calendars/' . rawurlencode($cal) . '/events/' . rawurlencode($eventId) . '?sendUpdates=all';
        return self::apiSend('DELETE', $url, self::SCOPE_CALENDAR_RW, self::subject() !== '') !== null;
    }

    /* ── Google Meet REST API + Admin Reports (authoritative attendance) ──
     * Two independent sources of truth for how long a Meet actually ran, so the
     * logged mentorship hours come from Google — not a browser tab:
     *   • Meet REST API v2 — near-real-time; reads the conferenceRecord for the
     *     meeting space (start/end). Needs domain-wide delegation for the Meet
     *     scope and works by impersonating a conference host/guest (the mentor).
     *   • Admin SDK Reports API — the org's own Meet audit log (call_ended +
     *     duration_seconds), matched by meeting code. Tamper-proof, but lags.
     */

    /** Meet reconciliation is possible when the service account is configured. */
    public static function meetEnabled(): bool { return self::configured(); }
    /** Reports reconciliation additionally needs an admin to impersonate. */
    public static function reportsEnabled(): bool { return self::configured() && self::subject() !== ''; }

    /**
     * Authoritative conference window for a Meet code, as seen by Google.
     * Impersonates $hostEmail (a conference participant — the mentor). Returns
     * ['start'=>ts,'end'=>ts,'seconds'=>int] for the most recent ENDED conference
     * at/after $afterTs, or null if none has ended yet / not configured.
     */
    public static function meetConferenceForCode(string $meetingCode, string $hostEmail, int $afterTs = 0): ?array
    {
        $code = trim($meetingCode);
        if ($code === '' || $hostEmail === '' || !self::meetEnabled()) return null;
        // Resolve the space (accepts the dashed meeting code as an alias).
        $space = self::apiGet(self::meetBase() . '/v2/spaces/' . rawurlencode($code), self::SCOPE_MEET_RO, false, $hostEmail);
        $spaceName = is_array($space) ? (string) ($space['name'] ?? '') : '';
        if ($spaceName === '') return null;
        // List conference records for that space (most recent first).
        $q = http_build_query(['filter' => 'space.name="' . $spaceName . '"', 'pageSize' => 10]);
        $recs = self::apiGet(self::meetBase() . '/v2/conferenceRecords?' . $q, self::SCOPE_MEET_RO, false, $hostEmail);
        $items = is_array($recs) ? ($recs['conferenceRecords'] ?? []) : [];
        $best = null;
        foreach ($items as $r) {
            $s = strtotime((string) ($r['startTime'] ?? '')) ?: 0;
            $e = strtotime((string) ($r['endTime'] ?? '')) ?: 0;
            if ($e <= 0) continue;                 // still live — no authoritative end yet
            if ($afterTs > 0 && $s > 0 && $s < $afterTs - 3600) continue; // not this session
            if ($best === null || $e > $best['end']) $best = ['start' => $s, 'end' => $e, 'seconds' => max(0, $e - $s)];
        }
        return $best;
    }

    /**
     * Longest Meet call duration (seconds) the org audit log recorded for this
     * meeting code, or null. Matches ignoring dashes/case. Impersonates an admin.
     */
    public static function reportsMeetSeconds(string $meetingCode, int $afterTs = 0): ?int
    {
        $code = strtolower(str_replace('-', '', trim($meetingCode)));
        if ($code === '' || !self::reportsEnabled()) return null;
        $params = [
            'eventName' => 'call_ended',
            'filters'   => 'meeting_code==' . $code,
            'maxResults' => 100,
        ];
        if ($afterTs > 0) $params['startTime'] = gmdate('Y-m-d\TH:i:s\Z', $afterTs - 3600);
        $url = self::reportsBase() . '/admin/reports/v1/activity/users/all/applications/meet?' . http_build_query($params);
        $d = self::apiGet($url, self::SCOPE_REPORTS, false, self::subject());
        if (!is_array($d) || empty($d['items'])) return null;
        $max = 0;
        foreach ($d['items'] as $it) {
            foreach (($it['events'] ?? []) as $ev) {
                foreach (($ev['parameters'] ?? []) as $p) {
                    if (($p['name'] ?? '') === 'duration_seconds') {
                        $v = (int) ($p['intValue'] ?? $p['value'] ?? 0);
                        if ($v > $max) $max = $v;
                    }
                }
            }
        }
        return $max > 0 ? $max : null;
    }

    /**
     * Best authoritative duration (minutes) for a Meet, trying the near-real-time
     * Meet API first (impersonating $hostEmail) then the Reports audit log.
     * Returns ['minutes'=>int,'source'=>'meet'|'reports','start'=>?ts,'end'=>?ts]
     * or null when Google can't (yet) confirm it.
     */
    public static function authoritativeMeetMinutes(string $meetingCode, string $hostEmail, int $afterTs = 0): ?array
    {
        $conf = self::meetConferenceForCode($meetingCode, $hostEmail, $afterTs);
        if ($conf && $conf['seconds'] > 0) {
            return ['minutes' => (int) round($conf['seconds'] / 60), 'source' => 'meet', 'start' => $conf['start'], 'end' => $conf['end']];
        }
        $secs = self::reportsMeetSeconds($meetingCode, $afterTs);
        if ($secs !== null && $secs > 0) {
            return ['minutes' => (int) round($secs / 60), 'source' => 'reports', 'start' => null, 'end' => null];
        }
        return null;
    }

    /* ── Directory → members sync ────────────────────────────────── */

    /** Directory groups the org publishes (needs the groups.readonly scope). */
    public static function directoryGroups(int $max = 200): array
    {
        if (!self::configured() || self::subject() === '') return [];
        $domain = (string) Config::get('AV_ORG_DOMAIN', 'afrovanguard.org.ng');
        $q = http_build_query(['domain' => $domain, 'maxResults' => max(1, min(500, $max))]);
        $d = self::apiGet('https://admin.googleapis.com/admin/directory/v1/groups?' . $q, self::SCOPE_GROUPS, true);
        if (!$d || empty($d['groups'])) return [];
        return array_map(fn($g) => [
            'name'  => (string) ($g['name'] ?? ''),
            'email' => (string) ($g['email'] ?? ''),
            'count' => (int) ($g['directMembersCount'] ?? 0),
        ], $d['groups']);
    }

    /**
     * Provision every active org user from the Workspace directory into the
     * LMS as a verified member (idempotent upsert by email). Returns a summary
     * ['created'=>int,'updated'=>int,'skipped'=>int]. Safe to run repeatedly.
     */
    public static function syncDirectoryUsers(int $max = 500): array
    {
        $sum = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        if (!self::configured() || self::subject() === '' || !class_exists('Database')) return $sum + ['ok' => false, 'error' => 'not configured'];
        $users = self::directoryUsers($max);
        if (!$users) return $sum + ['ok' => true];
        $db = Database::pdo();
        $find = $db->prepare('SELECT id FROM lms_users WHERE email = ?');
        $ins  = $db->prepare('INSERT INTO lms_users (name, email, password_hash, role, email_verified, has_password) VALUES (?,?,?,?,1,0)');
        $upd  = $db->prepare('UPDATE lms_users SET name = ? WHERE id = ? AND (name IS NULL OR name = "")');
        foreach ($users as $u) {
            $email = strtolower(trim((string) $u['email']));
            if ($email === '' || !empty($u['suspended'])) { $sum['skipped']++; continue; }
            $name = trim((string) $u['name']) ?: ucfirst(explode('@', $email)[0]);
            $find->execute([$email]);
            $id = (int) ($find->fetchColumn() ?: 0);
            if ($id > 0) { $upd->execute([$name, $id]); $sum['updated']++; continue; }
            try {
                $ins->execute([$name, $email, password_hash(bin2hex(random_bytes(18)), PASSWORD_BCRYPT), 'member']);
                $sum['created']++;
                if (class_exists('Events')) { try { Events::emit('member.created', ['email' => $email, 'name' => $name, 'role' => 'member', 'via' => 'workspace_sync']); } catch (Throwable $e) {} }
            } catch (Throwable $e) { $sum['skipped']++; }
        }
        return $sum + ['ok' => true, 'total' => count($users)];
    }

    /** Fetch a Drive file's text (e.g. a Meet transcript doc) — read-only. */
    public static function driveText(string $fileId, int $maxBytes = 200000): ?string
    {
        if (!self::configured() || $fileId === '') return null;
        $tok = self::accessToken(self::SCOPE_DRIVE_RO, self::subject() !== '');
        if (!$tok) return null;
        // Google Docs must be exported as text/plain; binary files download directly.
        $url = self::apiBase() . '/drive/v3/files/' . rawurlencode($fileId) . '/export?mimeType=' . rawurlencode('text/plain');
        $res = self::http('GET', $url, $tok);
        if (!$res || $res['code'] >= 400) {
            $url = self::apiBase() . '/drive/v3/files/' . rawurlencode($fileId) . '?alt=media';
            $res = self::http('GET', $url, $tok);
        }
        if (!$res || $res['code'] >= 400 || !is_string($res['body'])) return null;
        return mb_substr($res['body'], 0, $maxBytes);
    }

    /**
     * Create a Meet space with Google's NATIVE auto-transcription (and optional
     * auto-recording) turned on, via the Meet REST API. This is the server-drivable
     * "Meet media" path: Google itself records/transcribes the call, and we later
     * read the transcript with meetTranscriptText(). Impersonates the host so the
     * space is owned by a real user. Returns ['uri','code','space'] or null.
     */
    public static function createMeetSpace(string $hostEmail, bool $autoTranscribe = true, bool $autoRecord = false): ?array
    {
        if ($hostEmail === '' || !self::meetEnabled()) return null;
        $cfg = ['accessType' => 'TRUSTED', 'entryPointAccess' => 'ALL'];
        $artifact = [];
        if ($autoTranscribe) $artifact['transcriptionConfig'] = ['autoTranscriptionGeneration' => 'ON'];
        if ($autoRecord)     $artifact['recordingConfig']     = ['autoRecordingGeneration' => 'ON'];
        if ($artifact) $cfg['artifactConfig'] = $artifact;
        // meetings.space.created scope is required to create spaces.
        $scope = 'https://www.googleapis.com/auth/meetings.space.created';
        $d = self::apiSend('POST', self::meetBase() . '/v2/spaces', $scope, false, ['config' => $cfg], $hostEmail);
        if (!is_array($d) || empty($d['meetingUri'])) return null;
        return [
            'uri'   => (string) $d['meetingUri'],
            'code'  => (string) ($d['meetingCode'] ?? self::meetCodeFromUrl((string) $d['meetingUri'])),
            'space' => (string) ($d['name'] ?? ''),
        ];
    }

    /** Extract a Meet code (e.g. "abc-defg-hij") from a Meet URL. */
    public static function meetCodeFromUrl(string $url): string
    {
        if (preg_match('~meet\.google\.com/([a-z0-9\-]+)~i', $url, $m)) return strtolower($m[1]);
        return '';
    }

    /**
     * The official Google Meet transcript text for a meeting, via the Meet REST
     * API (conferenceRecords → transcripts → transcripts.entries). Requires the
     * meetings.space.readonly scope; impersonates $hostEmail (a participant).
     * Returns the joined "Speaker: text" transcript, or null if none exists yet.
     */
    public static function meetTranscriptText(string $meetingCode, string $hostEmail, int $afterTs = 0): ?string
    {
        $code = trim($meetingCode);
        if ($code === '' || $hostEmail === '' || !self::meetEnabled()) return null;
        $space = self::apiGet(self::meetBase() . '/v2/spaces/' . rawurlencode($code), self::SCOPE_MEET_RO, false, $hostEmail);
        $spaceName = is_array($space) ? (string) ($space['name'] ?? '') : '';
        if ($spaceName === '') return null;
        $q = http_build_query(['filter' => 'space.name="' . $spaceName . '"', 'pageSize' => 10]);
        $recs = self::apiGet(self::meetBase() . '/v2/conferenceRecords?' . $q, self::SCOPE_MEET_RO, false, $hostEmail);
        $items = is_array($recs) ? ($recs['conferenceRecords'] ?? []) : [];
        // Newest ended conference at/after the session start.
        $conf = null;
        foreach ($items as $r) {
            $s = strtotime((string) ($r['startTime'] ?? '')) ?: 0;
            if ($afterTs > 0 && $s > 0 && $s < $afterTs - 3600) continue;
            if ($conf === null) $conf = $r;
        }
        $confName = is_array($conf) ? (string) ($conf['name'] ?? '') : '';
        if ($confName === '') return null;
        $trs = self::apiGet(self::meetBase() . '/v2/' . $confName . '/transcripts', self::SCOPE_MEET_RO, false, $hostEmail);
        $tItems = is_array($trs) ? ($trs['transcripts'] ?? []) : [];
        if (!$tItems) return null;
        $tName = (string) ($tItems[0]['name'] ?? '');
        if ($tName === '') return null;
        // Page through the transcript entries and stitch them into readable text.
        $out = [];
        $pageToken = '';
        for ($i = 0; $i < 20; $i++) {
            $eq = http_build_query(array_filter(['pageSize' => 1000, 'pageToken' => $pageToken]));
            $entries = self::apiGet(self::meetBase() . '/v2/' . $tName . '/entries?' . $eq, self::SCOPE_MEET_RO, false, $hostEmail);
            if (!is_array($entries)) break;
            foreach (($entries['transcriptEntries'] ?? []) as $en) {
                $who = (string) ($en['participant'] ?? '');
                $who = $who !== '' ? (basename($who)) : '';
                $txt = trim((string) ($en['text'] ?? ''));
                if ($txt !== '') $out[] = ($who !== '' ? $who . ': ' : '') . $txt;
            }
            $pageToken = (string) ($entries['nextPageToken'] ?? '');
            if ($pageToken === '') break;
        }
        $joined = trim(implode("\n", $out));
        return $joined !== '' ? $joined : null;
    }

    /**
     * Connectivity probe for the Studio → System page. Reports what actually
     * works against live Google, without leaking tokens.
     */
    public static function probe(): array
    {
        if (!self::configured()) return ['configured' => false];
        $tok = self::accessToken(self::SCOPE_CALENDAR, self::subject() !== '');
        return [
            'configured'     => true,
            'subject'        => self::subject(),
            'token_ok'       => $tok !== null,
            'calendar_read'  => count(self::calendarEvents(null, 1)),
            'calendar_write' => self::calendarWriteEnabled(),
            'drive'          => count(self::driveFiles(null, 1)),
            'directory'      => self::subject() !== '' ? count(self::directoryUsers(1)) : null,
            'groups'         => self::subject() !== '' ? count(self::directoryGroups(1)) : null,
        ];
    }
}
