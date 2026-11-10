<?php
/**
 * lib/Recorder.php — recordings sent by the Meeting Recorder browser extension.
 *
 * The extension (extensions/meeting-recorder/) records a meeting tab's audio
 * and the user's microphone in the browser, in ten-minute segments, and sends
 * each segment here in small chunks. Everything after that is the existing
 * meeting system: the segments are transcribed by the cron, stitched in order,
 * and handed to Meetings::saveTranscript, which writes the minutes and files
 * the action items exactly as for any other transcript.
 *
 * Built for shared hosting:
 *   • a chunk is never larger than the host accepts (limits() reads php.ini),
 *   • an upload request only writes bytes; transcription happens in the cron,
 *     one segment at a time, inside the time a shared host allows,
 *   • every chunk is idempotent (recording, segment, index, sha256), so a
 *     connection that drops just sends the same chunk again,
 *   • segments are standalone WebM files, so nothing needs ffmpeg.
 *
 * Who may write: a device token (av_rec_…), made by pairing from a signed-in
 * portal page. Only its SHA-256 is stored. A token writes only its own user's
 * recordings, and into a meeting only when that user takes part in it.
 *
 * Settings (all optional):
 *   AV_REC_RETAIN_DAYS   days to keep the audio after transcription (default 30; 0 = keep)
 *   AV_REC_STORAGE       "local" (default) or "drive" (move finished audio to Google Drive)
 *   AV_REC_CHUNK_MAX     largest chunk in bytes (default 1 MB, never above the host's limit)
 */
declare(strict_types=1);

final class Recorder
{
    const TOKEN_PREFIX = 'av_rec_';
    const PAIR_TTL = 600;               // a pairing code lives ten minutes
    const MAX_SEGMENTS = 600;           // 100 hours of ten-minute segments
    const MAX_CHUNKS = 4000;
    const MAX_SEGMENT_BYTES = 24 * 1024 * 1024;   // under Whisper's 25 MB
    const MAX_ATTEMPTS = 3;
    const ABANDON_AFTER = 6 * 3600;     // a recording that stopped talking to us
    /* What the notetaker states mean for "is someone else already recording?" */
    const BOT_ACTIVE = ['pending', 'requested', 'scheduled', 'joining', 'joined', 'in_call', 'recording', 'native'];

    /* ── schema ────────────────────────────────────────────────────────── */

    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (class_exists('Meetings')) Meetings::ensure();
        Database::execSchema(Database::pdo(), "
        CREATE TABLE IF NOT EXISTS recorder_devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name VARCHAR(120) NOT NULL DEFAULT '',
            token_hash VARCHAR(64) NOT NULL,
            revoked INTEGER NOT NULL DEFAULT 0,
            last_used VARCHAR(32) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_recdev_tok ON recorder_devices(token_hash);
        CREATE INDEX IF NOT EXISTS idx_recdev_user ON recorder_devices(user_id);
        CREATE TABLE IF NOT EXISTS recorder_pairings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            code_hash VARCHAR(64) NOT NULL,
            expires_at INTEGER NOT NULL DEFAULT 0,
            used INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_recpair_code ON recorder_pairings(code_hash);
        CREATE TABLE IF NOT EXISTS recordings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            device_id INTEGER NOT NULL DEFAULT 0,
            meeting_id INTEGER NOT NULL DEFAULT 0,
            meet_code VARCHAR(60) NOT NULL DEFAULT '',
            title VARCHAR(200) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'recording',
            segments_expected INTEGER NOT NULL DEFAULT 0,
            consent_shown INTEGER NOT NULL DEFAULT 0,
            transcript TEXT NOT NULL DEFAULT '',
            note VARCHAR(500) NOT NULL DEFAULT '',
            started_at VARCHAR(32) NOT NULL DEFAULT '',
            finished_at VARCHAR(32) NOT NULL DEFAULT '',
            updated_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_rec_user ON recordings(user_id, status);
        CREATE INDEX IF NOT EXISTS idx_rec_meeting ON recordings(meeting_id);
        CREATE TABLE IF NOT EXISTS recording_segments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recording_id INTEGER NOT NULL,
            seg_no INTEGER NOT NULL,
            state VARCHAR(16) NOT NULL DEFAULT 'ready',
            offset_sec INTEGER NOT NULL DEFAULT 0,
            bytes INTEGER NOT NULL DEFAULT 0,
            mime VARCHAR(60) NOT NULL DEFAULT 'audio/webm',
            path VARCHAR(500) NOT NULL DEFAULT '',
            stored_url VARCHAR(500) NOT NULL DEFAULT '',
            attempts INTEGER NOT NULL DEFAULT 0,
            text TEXT NOT NULL DEFAULT '',
            error VARCHAR(500) NOT NULL DEFAULT '',
            updated_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_recseg ON recording_segments(recording_id, seg_no);
        CREATE INDEX IF NOT EXISTS idx_recseg_state ON recording_segments(state);");
        // v1.1: moments the person recording marked as important (JSON list of seconds).
        try { Database::pdo()->query('SELECT marks FROM recordings LIMIT 1'); }
        catch (Throwable $e) { Database::pdo()->exec("ALTER TABLE recordings ADD COLUMN marks VARCHAR(2000) NOT NULL DEFAULT ''"); }
        // v1.2: who was talking. Per part, the times the recording person's
        // microphone or the call was speaking; Whisper's timed sentences; and
        // the most people Meet showed in the call.
        // v1.4: what the person recording actually did about telling the others:
        // 'none', 'reminded' (the reminder was on their screen), 'confirmed' (they said they told everyone).
        foreach ([['recording_segments', 'talk', 'TEXT'], ['recording_segments', 'utterances', 'TEXT'], ['recordings', 'people_max', 'INTEGER NOT NULL DEFAULT 0'],
                  ['recordings', 'consent', "VARCHAR(12) NOT NULL DEFAULT 'none'"], ['recordings', 'consent_at', "VARCHAR(32) NOT NULL DEFAULT ''"]] as [$t, $c, $decl]) {
            try { Database::pdo()->query("SELECT $c FROM $t LIMIT 1"); }
            catch (Throwable $e) { Database::pdo()->exec("ALTER TABLE $t ADD COLUMN $c $decl"); }
        }
        /* A call the recorder recorded without a scheduled meeting was filed with
           open minutes, readable by every member. It is private now (only its
           attendees and managers); this puts right the ones filed before the fix.
           Only rows from before it, so a later choice to open one is kept. */
        try { Database::pdo()->exec("UPDATE meetings SET minutes_private = 1 WHERE origin = 'recorder' AND minutes_private = 0 AND created_at < '2026-11-11 00:00:00'"); } catch (Throwable $e) { /* older schema */ }
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    /* ── pairing and devices ───────────────────────────────────────────── */

    /** A single-use code for a signed-in user, handed to the extension by the portal page. */
    public static function createPairing(int $uid): string
    {
        self::ensure();
        $code = bin2hex(random_bytes(16));
        $db = Database::pdo();
        $db->prepare('DELETE FROM recorder_pairings WHERE expires_at < ? OR used = 1')->execute([time()]);
        $db->prepare('INSERT INTO recorder_pairings (user_id, code_hash, expires_at, used) VALUES (?,?,?,0)')
           ->execute([$uid, hash('sha256', $code), time() + self::PAIR_TTL]);
        return $code;
    }

    /** Exchange a pairing code for a device token. The token is returned once and never stored. */
    public static function pair(string $code, string $deviceName): array
    {
        self::ensure();
        $code = trim($code);
        if (!preg_match('/^[a-f0-9]{32}$/', $code)) return ['ok' => false, 'error' => 'That connection code is not valid. Start again from the portal.'];
        $db = Database::pdo();
        $st = $db->prepare('SELECT id, user_id, expires_at, used FROM recorder_pairings WHERE code_hash = ?');
        $st->execute([hash('sha256', $code)]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || (int) $p['used'] === 1 || (int) $p['expires_at'] < time()) {
            return ['ok' => false, 'error' => 'That connection code has expired. Start again from the portal.'];
        }
        // Spend the code first: of two racing requests only one changes the row.
        $up = $db->prepare('UPDATE recorder_pairings SET used = 1 WHERE id = ? AND used = 0');
        $up->execute([(int) $p['id']]);
        if ($up->rowCount() !== 1) return ['ok' => false, 'error' => 'That connection code has already been used.'];

        $token = self::TOKEN_PREFIX . bin2hex(random_bytes(24));
        $name = mb_substr(trim(preg_replace('/[\x00-\x1f]+/u', ' ', $deviceName) ?? ''), 0, 120);
        if ($name === '') $name = 'Browser';
        $db->prepare('INSERT INTO recorder_devices (user_id, name, token_hash, revoked, last_used, created_at) VALUES (?,?,?,0,?,?)')
           ->execute([(int) $p['user_id'], $name, hash('sha256', $token), self::now(), self::now()]);
        $user = self::userRow((int) $p['user_id']);
        return ['ok' => true, 'token' => $token, 'user' => ['name' => (string) ($user['name'] ?? ''), 'email' => (string) ($user['email'] ?? '')]];
    }

    /** The device behind an "Authorization: Bearer av_rec_…" header, or null. */
    public static function device(string $authHeader): ?array
    {
        self::ensure();
        if (!preg_match('/^Bearer\s+(' . self::TOKEN_PREFIX . '[a-f0-9]{48})$/', trim($authHeader), $m)) return null;
        $st = Database::pdo()->prepare('SELECT * FROM recorder_devices WHERE token_hash = ? AND revoked = 0');
        $st->execute([hash('sha256', $m[1])]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) return null;
        // A token must not outlive its user: a deleted or suspended user's device is refused.
        $u = self::userRow((int) $d['user_id']);
        if (!$u || !in_array((string) ($u['status'] ?? 'active'), ['active', ''], true)) return null;
        // Nor its membership: a member who is no longer one (demoted, declined) cannot keep recording.
        if (!self::mayRecord($u)) return null;
        if (strtotime((string) $d['last_used'] . ' UTC') < time() - 300) {
            Database::pdo()->prepare('UPDATE recorder_devices SET last_used = ? WHERE id = ?')->execute([self::now(), (int) $d['id']]);
        }
        return $d;
    }

    /** The same rule as the connect page: members, unless AV_REC_AUDIENCE=all. */
    public static function mayRecord(array $user): bool
    {
        if (strtolower((string) getenv('AV_REC_AUDIENCE')) === 'all') return true;
        return class_exists('LmsAuth') && LmsAuth::isOrgMember($user);
    }

    /** Every connected browser, for the Studio (superadmins): who, which, when last used. */
    public static function allDevices(int $limit = 300): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT d.id, d.name, d.last_used, d.created_at, u.name AS user_name, u.email AS user_email FROM recorder_devices d LEFT JOIN lms_users u ON u.id = d.user_id WHERE d.revoked = 0 ORDER BY d.last_used DESC LIMIT ' . max(1, min(1000, $limit)));
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** A superadmin disconnects any browser (a lost laptop, someone who has left). */
    public static function adminRevoke(int $deviceId): bool
    {
        self::ensure();
        $st = Database::pdo()->prepare('UPDATE recorder_devices SET revoked = 1 WHERE id = ? AND revoked = 0');
        $st->execute([$deviceId]);
        return $st->rowCount() === 1;
    }

    public static function devices(int $uid): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT id, name, last_used, created_at FROM recorder_devices WHERE user_id = ? AND revoked = 0 ORDER BY id DESC');
        $st->execute([$uid]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function revoke(int $uid, int $deviceId): bool
    {
        self::ensure();
        $st = Database::pdo()->prepare('UPDATE recorder_devices SET revoked = 1 WHERE id = ? AND user_id = ?');
        $st->execute([$deviceId, $uid]);
        return $st->rowCount() === 1;
    }

    /* ── limits ────────────────────────────────────────────────────────── */

    private static function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key));
        if ($v === '' || $v === '0' || $v === '-1') return PHP_INT_MAX;
        $n = (int) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024;   // fall through
            case 'm': $n *= 1024;   // fall through
            case 'k': $n *= 1024;
        }
        return $n > 0 ? $n : PHP_INT_MAX;
    }

    /** The largest chunk this host takes, with room for the form's other fields. */
    public static function chunkBytes(): int
    {
        $host = min(self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size'));
        $want = (int) (getenv('AV_REC_CHUNK_MAX') ?: 1024 * 1024);
        $cap = $host === PHP_INT_MAX ? $want : (int) floor(($host - 16 * 1024) * 0.9);
        return max(64 * 1024, min($want, $cap));
    }

    public static function limits(): array
    {
        return ['ok' => true, 'chunk_bytes' => self::chunkBytes(), 'segment_seconds' => 600,
                'max_segment_bytes' => self::MAX_SEGMENT_BYTES, 'transcription' => self::canTranscribe()];
    }

    public static function canTranscribe(): bool
    {
        return (class_exists('OpenAi') && OpenAi::configured()) || (class_exists('Gemini') && Gemini::configured());
    }

    /* ── a recording ───────────────────────────────────────────────────── */

    /**
     * Begin a recording. Finds the meeting by its Meet code (the one nearest in
     * time that this user takes part in), or makes a plain record for a call the
     * portal did not schedule. Never opens a new Meet room.
     */
    public static function start(array $device, array $in): array
    {
        self::ensure();
        $uid = (int) $device['user_id'];
        $code = strtolower(trim((string) ($in['meet_code'] ?? '')));
        if ($code !== '' && !preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/', $code)) $code = '';
        $title = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in['title'] ?? '')) ?? ''), 0, 200);
        $url = (string) ($in['url'] ?? '');
        $url = preg_match('~^https://[a-z0-9.-]+(/[^\s]*)?$~i', $url) ? mb_substr($url, 0, 500) : '';
        $db = Database::pdo();

        $meeting = null;
        $askedId = (int) ($in['meeting_id'] ?? 0);
        if ($askedId > 0 && Meetings::isParticipant($uid, $askedId)) $meeting = self::meetingRow($askedId);
        if (!$meeting && $code !== '') $meeting = self::matchMeeting($uid, $code);

        $created = false;
        if (!$meeting) {
            if ($title === '') $title = 'Recorded call';
            $title = mb_substr($title . ' · ' . gmdate('j M Y'), 0, 200);
            $db->prepare('INSERT INTO meetings (creator_id, title, agenda, scheduled_at, duration_min, frequency, context, context_id, auto_record, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([$uid, $title, '', self::now(), 60, 'once', 'workspace', 0, 0, 'scheduled', self::now()]);
            $mid = (int) $db->lastInsertId();
            // Private: only its attendees (the person recording) and those who manage it read the minutes.
            // An unscheduled call is as likely to be a one-to-one or an HR conversation as anything.
            $db->prepare("UPDATE meetings SET meet_code = ?, meet_url = ?, origin = 'recorder', minutes_private = 1 WHERE id = ?")->execute([$code, $url, $mid]);
            $me = self::userRow($uid);
            if ($me && filter_var((string) $me['email'], FILTER_VALIDATE_EMAIL)) {
                $db->prepare('INSERT INTO meeting_attendees (meeting_id, email) VALUES (?,?)')->execute([$mid, strtolower((string) $me['email'])]);
            }
            $meeting = self::meetingRow($mid);
            $created = true;
        }
        $mid = (int) $meeting['id'];

        $db->prepare('INSERT INTO recordings (user_id, device_id, meeting_id, meet_code, title, status, consent_shown, started_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$uid, (int) $device['id'], $mid, $code, (string) $meeting['title'], 'recording', 0, self::now(), self::now()]);
        $rid = (int) $db->lastInsertId();

        return [
            'ok' => true, 'recording_id' => $rid,
            'meeting' => ['id' => $mid, 'title' => (string) $meeting['title'], 'new' => $created,
                          'url' => (defined('PORTAL_URL') ? rtrim(PORTAL_URL, '/') : '') . '/#meetings'],
            'notetaker_active' => in_array((string) ($meeting['bot_state'] ?? ''), self::BOT_ACTIVE, true),
            'has_transcript' => self::hasTranscript($mid),
            'others_recording' => self::othersRecording($mid, $rid),
            'chunk_bytes' => self::chunkBytes(),
        ];
    }

    private static function matchMeeting(int $uid, string $code): ?array
    {
        $st = Database::pdo()->prepare("SELECT * FROM meetings WHERE meet_code = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 50");
        $st->execute([$code]);
        $best = null; $bestGap = PHP_INT_MAX;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $m) {
            if (!Meetings::isParticipant($uid, (int) $m['id'])) continue;
            /* A recurring meeting keeps one code; its row's date is the series
               start, so any of them is the same meeting. Nearest in time wins. */
            $ts = strtotime((string) $m['scheduled_at'] . ' UTC') ?: 0;
            $gap = abs(time() - $ts);
            if ((string) $m['frequency'] !== 'once') $gap = min($gap, 3600);
            if ($gap < $bestGap) { $best = $m; $bestGap = $gap; }
        }
        return $best;
    }

    private static function meetingRow(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM meetings WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function hasTranscript(int $mid): bool
    {
        $st = Database::pdo()->prepare('SELECT 1 FROM meeting_transcripts WHERE meeting_id = ?');
        $st->execute([$mid]);
        return (bool) $st->fetchColumn();
    }

    private static function othersRecording(int $mid, int $rid): int
    {
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM recordings WHERE meeting_id = ? AND id <> ? AND status = 'recording' AND updated_at > ?");
        $st->execute([$mid, $rid, gmdate('Y-m-d H:i:s', time() - 900)]);
        return (int) $st->fetchColumn();
    }

    private static function userRow(int $uid): ?array
    {
        try {
            $st = Database::pdo()->prepare('SELECT id, name, email, status, role FROM lms_users WHERE id = ?');
            $st->execute([$uid]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { return null; }
    }

    /** One of this device's user's recordings, or null. */
    private static function own(array $device, int $rid): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM recordings WHERE id = ? AND user_id = ?');
        $st->execute([$rid, (int) $device['user_id']]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function touch(int $rid): void
    {
        Database::pdo()->prepare('UPDATE recordings SET updated_at = ? WHERE id = ?')->execute([self::now(), $rid]);
    }

    /* ── storage ───────────────────────────────────────────────────────── */

    public static function baseDir(): string
    {
        $dir = (string) (getenv('AV_REC_DIR') ?: AV_ROOT . '/db/private/recordings');
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        // Belt and braces: db/ is denied already, but this folder holds people's voices.
        if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        }
        return $dir;
    }

    private static function recDir(int $rid): string
    {
        $d = self::baseDir() . '/' . $rid;
        if (!is_dir($d)) @mkdir($d, 0770, true);
        return $d;
    }

    private static function partPath(int $rid, int $seg, int $idx): string
    {
        return self::recDir($rid) . sprintf('/s%04d-c%05d.part', $seg, $idx);
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach ((array) glob($dir . '/{,.}[!.,!..]*', GLOB_BRACE) as $f) { is_dir($f) ? self::rmTree($f) : @unlink($f); }
        @rmdir($dir);
    }

    /* ── upload ────────────────────────────────────────────────────────── */

    /** Store one chunk. Sending the same chunk twice is harmless. */
    public static function chunk(array $device, int $rid, int $seg, int $idx, string $sha, string $tmpFile, int $size): array
    {
        self::ensure();
        $r = self::own($device, $rid);
        if (!$r) return ['ok' => false, 'error' => 'Not your recording.', 'status' => 404];
        if (in_array((string) $r['status'], ['cancelled', 'done', 'failed', 'duplicate'], true)) return ['ok' => false, 'error' => 'This recording is closed.', 'status' => 409];
        if ($seg < 0 || $seg >= self::MAX_SEGMENTS || $idx < 0 || $idx >= self::MAX_CHUNKS) return ['ok' => false, 'error' => 'Chunk out of range.', 'status' => 422];
        if ($size <= 0 || $size > self::chunkBytes() + 1024) return ['ok' => false, 'error' => 'Chunk too large for this host.', 'status' => 413];
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) return ['ok' => false, 'error' => 'Missing checksum.', 'status' => 422];
        if (self::segmentRow($rid, $seg)) return ['ok' => true, 'already' => true];   // the segment is already assembled
        $q = self::quota((int) $device['user_id']);
        if ($q['used'] + $size > $q['limit']) {
            return ['ok' => false, 'quota' => true, 'status' => 507,
                    'error' => 'Your recording space is full (' . (int) round($q['limit'] / 1048576) . ' MB in 30 days). The recording is kept in your browser; ask an admin to raise AV_REC_QUOTA_MB, or wait for older recordings to age out.'];
        }

        $dest = self::partPath($rid, $seg, $idx);
        if (is_file($dest) && hash_file('sha256', $dest) === $sha) return ['ok' => true, 'already' => true];
        if (!is_file($tmpFile) || hash_file('sha256', $tmpFile) !== $sha) return ['ok' => false, 'error' => 'Checksum mismatch — send it again.', 'status' => 422, 'retry' => true];
        $moved = is_uploaded_file($tmpFile) ? @move_uploaded_file($tmpFile, $dest) : @rename($tmpFile, $dest);
        if (!$moved) return ['ok' => false, 'error' => 'The server could not store the chunk.', 'status' => 500, 'retry' => true];
        self::touch($rid);
        return ['ok' => true];
    }

    private static function segmentRow(int $rid, int $seg): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM recording_segments WHERE recording_id = ? AND seg_no = ?');
        $st->execute([$rid, $seg]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Join a segment's chunks into one playable file. Says which are missing if any. */
    public static function segmentDone(array $device, int $rid, int $seg, int $chunks, int $offsetSec, string $mime, array $talk = []): array
    {
        $talkJson = json_encode(self::cleanTalk($talk));
        self::ensure();
        $r = self::own($device, $rid);
        if (!$r) return ['ok' => false, 'error' => 'Not your recording.', 'status' => 404];
        if ((string) $r['status'] === 'cancelled') return ['ok' => false, 'error' => 'This recording was cancelled.', 'status' => 409];
        if ($seg < 0 || $seg >= self::MAX_SEGMENTS || $chunks < 0 || $chunks > self::MAX_CHUNKS) return ['ok' => false, 'error' => 'Segment out of range.', 'status' => 422];
        if (self::segmentRow($rid, $seg)) return ['ok' => true, 'already' => true];
        if ($chunks === 0) {
            // Stop pressed just after a segment began: an empty part, nothing to transcribe.
            Database::pdo()->prepare("INSERT INTO recording_segments (recording_id, seg_no, state, offset_sec, bytes, mime, path, updated_at, talk) VALUES (?,?,'done',?,0,'audio/webm','',?,?)")
               ->execute([$rid, $seg, max(0, $offsetSec), self::now(), $talkJson]);
            self::touch($rid);
            return ['ok' => true, 'bytes' => 0];
        }

        $missing = [];
        for ($i = 0; $i < $chunks; $i++) if (!is_file(self::partPath($rid, $seg, $i))) $missing[] = $i;
        if ($missing) return ['ok' => false, 'missing' => array_slice($missing, 0, 200), 'error' => 'Some chunks have not arrived.', 'status' => 409];

        $mime = preg_match('~^audio/(webm|ogg|mp4)(;\s*codecs=[a-z0-9.]+)?$~i', $mime) ? strtolower(explode(';', $mime)[0]) : 'audio/webm';
        $ext = ['audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a'][$mime] ?? 'webm';
        $path = self::recDir($rid) . sprintf('/segment-%04d.%s', $seg, $ext);
        $out = @fopen($path . '.tmp', 'wb');
        if (!$out) return ['ok' => false, 'error' => 'The server could not write the segment.', 'status' => 500, 'retry' => true];
        $bytes = 0;
        for ($i = 0; $i < $chunks; $i++) {
            $in = @fopen(self::partPath($rid, $seg, $i), 'rb');
            if (!$in) { fclose($out); @unlink($path . '.tmp'); return ['ok' => false, 'error' => 'A chunk could not be read.', 'status' => 500, 'retry' => true]; }
            $bytes += (int) stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);
        if ($bytes > self::MAX_SEGMENT_BYTES) { @unlink($path . '.tmp'); return ['ok' => false, 'error' => 'The segment is too large to transcribe.', 'status' => 413]; }
        @rename($path . '.tmp', $path);
        try {
            Database::pdo()->prepare('INSERT INTO recording_segments (recording_id, seg_no, state, offset_sec, bytes, mime, path, updated_at, talk) VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute([$rid, $seg, 'ready', max(0, $offsetSec), $bytes, $mime, $path, self::now(), $talkJson]);
        } catch (Throwable $e) {
            if (self::segmentRow($rid, $seg)) return ['ok' => true, 'already' => true];   // a racing twin got there first
            throw $e;
        }
        for ($i = 0; $i < $chunks; $i++) @unlink(self::partPath($rid, $seg, $i));
        self::touch($rid);
        return ['ok' => true, 'bytes' => $bytes];
    }

    /** The extension has stopped. Says which segments it still owes, if any. */
    public static function finish(array $device, int $rid, int $segments, array $marks = [], int $people = 0, string $consent = 'none', string $consentAt = ''): array
    {
        self::ensure();
        $r = self::own($device, $rid);
        if (!$r) return ['ok' => false, 'error' => 'Not your recording.', 'status' => 404];
        if ((string) $r['status'] === 'cancelled') return ['ok' => false, 'error' => 'This recording was cancelled.', 'status' => 409];
        $segments = max(0, min(self::MAX_SEGMENTS, $segments));
        if ((string) $r['status'] === 'recording') {
            Database::pdo()->prepare("UPDATE recordings SET status = 'finished', segments_expected = ?, finished_at = ?, updated_at = ? WHERE id = ?")
               ->execute([$segments, self::now(), self::now(), $rid]);
        }
        $marks = self::cleanMarks($marks);
        if ($marks) Database::pdo()->prepare('UPDATE recordings SET marks = ? WHERE id = ?')->execute([json_encode($marks), $rid]);
        if ($people > 0) Database::pdo()->prepare('UPDATE recordings SET people_max = ? WHERE id = ?')->execute([min(500, $people), $rid]);
        // Only what happened: the reminder was shown, or the person recording said they told everyone.
        $consent = in_array($consent, ['none', 'reminded', 'confirmed'], true) ? $consent : 'none';
        $at = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $consentAt) ? $consentAt : ($consent === 'confirmed' ? self::now() : '');
        Database::pdo()->prepare('UPDATE recordings SET consent = ?, consent_at = ?, consent_shown = ? WHERE id = ?')
           ->execute([$consent, $consent === 'none' ? '' : $at, $consent === 'confirmed' ? 1 : 0, $rid]);
        $have = self::segmentNumbers($rid);
        $missing = array_values(array_diff(range(0, max(0, $segments - 1)), $have));
        if ($segments === 0) $missing = [];
        return ['ok' => true, 'missing_segments' => $missing];
    }

    private static function segmentNumbers(int $rid): array
    {
        $st = Database::pdo()->prepare('SELECT seg_no FROM recording_segments WHERE recording_id = ?');
        $st->execute([$rid]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public static function status(array $device, int $rid): array
    {
        self::ensure();
        $r = self::own($device, $rid);
        if (!$r) return ['ok' => false, 'error' => 'Not your recording.', 'status' => 404];
        $st = Database::pdo()->prepare('SELECT seg_no, state, error FROM recording_segments WHERE recording_id = ? ORDER BY seg_no');
        $st->execute([$rid]);
        $segs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $count = array_count_values(array_column($segs, 'state'));
        return ['ok' => true, 'recording' => [
            'id' => $rid, 'status' => (string) $r['status'], 'title' => (string) $r['title'],
            'meeting_id' => (int) $r['meeting_id'], 'note' => (string) $r['note'],
            'segments' => count($segs), 'expected' => (int) $r['segments_expected'],
            'transcribed' => (int) ($count['done'] ?? 0), 'failed' => (int) ($count['failed'] ?? 0),
            'minutes_url' => (defined('PORTAL_URL') ? rtrim(PORTAL_URL, '/') : '') . '/#meetings',
            'listen_url' => self::listenUrl((int) $r['meeting_id']),
            // Uploaded, but nothing on this site can turn it into words yet.
            'waiting' => in_array((string) $r['status'], ['recording', 'finished'], true) && !self::canTranscribe(),
            'consent' => (string) ($r['consent'] ?? 'none'),
        ]];
    }

    public static function cancel(array $device, int $rid): array
    {
        self::ensure();
        $r = self::own($device, $rid);
        if (!$r) return ['ok' => false, 'error' => 'Not your recording.', 'status' => 404];
        if (in_array((string) $r['status'], ['done', 'duplicate'], true)) return ['ok' => false, 'error' => 'The minutes are already written.', 'status' => 409];
        self::rmTree(self::baseDir() . '/' . $rid);
        $db = Database::pdo();
        $db->prepare('DELETE FROM recording_segments WHERE recording_id = ?')->execute([$rid]);
        $db->prepare("UPDATE recordings SET status = 'cancelled', updated_at = ? WHERE id = ?")->execute([self::now(), $rid]);
        // A record the recorder made for an unscheduled call goes too, if nothing else used it.
        $m = self::meetingRow((int) $r['meeting_id']);
        if ($m && (string) ($m['origin'] ?? '') === 'recorder' && !self::hasTranscript((int) $m['id'])) {
            $other = $db->prepare("SELECT COUNT(*) FROM recordings WHERE meeting_id = ? AND status <> 'cancelled'");
            $other->execute([(int) $m['id']]);
            if ((int) $other->fetchColumn() === 0) $db->prepare("UPDATE meetings SET status = 'cancelled' WHERE id = ?")->execute([(int) $m['id']]);
        }
        return ['ok' => true];
    }

    /* ── listening, in the portal (works with no AI at all) ──────────────── */

    public static function listenUrl(int $meetingId): string
    {
        return (defined('PORTAL_URL') ? rtrim(PORTAL_URL, '/') : '/portal') . '/recording.php?meeting=' . $meetingId;
    }

    /**
     * Who may listen: the meeting's participants and those who may manage it.
     * Stricter than reading the minutes — a voice is more than a summary.
     */
    public static function mayListen(int $uid, int $meetingId): bool
    {
        return $uid > 0 && $meetingId > 0 && (Meetings::isParticipant($uid, $meetingId) || Meetings::mayManage($uid, $meetingId));
    }

    /** How many recordings each meeting has: one query for the whole list. */
    public static function countByMeeting(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        self::ensure();
        $rows = Database::pdo()->query("SELECT meeting_id, COUNT(*) FROM recordings WHERE status <> 'cancelled' AND meeting_id IN (" . implode(',', $ids) . ') GROUP BY meeting_id')->fetchAll(PDO::FETCH_NUM) ?: [];
        $out = [];
        foreach ($rows as [$m, $n]) $out[(int) $m] = (int) $n;
        return $out;
    }

    /** A meeting's recordings with their parts, marked moments and talking time. */
    public static function forMeeting(int $meetingId): array
    {
        self::ensure();
        $db = Database::pdo();
        $st = $db->prepare("SELECT r.*, u.name AS by_name FROM recordings r LEFT JOIN lms_users u ON u.id = r.user_id WHERE r.meeting_id = ? AND r.status <> 'cancelled' ORDER BY r.id");
        $st->execute([$meetingId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $sg = $db->prepare('SELECT id, seg_no, state, offset_sec, bytes, path, stored_url, talk FROM recording_segments WHERE recording_id = ? ORDER BY seg_no');
            $sg->execute([(int) $r['id']]);
            $parts = []; $me = 0.0; $them = 0.0;
            foreach ($sg->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
                foreach (self::cleanTalk(json_decode((string) ($p['talk'] ?? ''), true) ?: []) as [$a, $b, $who]) { if ($who === 'me') $me += $b - $a; else $them += $b - $a; }
                if ((int) $p['bytes'] === 0) continue;   // an empty part, nothing to hear
                $parts[] = [
                    'id' => (int) $p['id'], 'n' => (int) $p['seg_no'], 'offset' => (int) $p['offset_sec'],
                    'here' => (string) $p['path'] !== '' && is_file((string) $p['path']),
                    'drive' => (string) $p['stored_url'],
                ];
            }
            $names = self::speakers((int) $r['id']);
            $out[] = [
                'id' => (int) $r['id'], 'by' => (string) ($r['by_name'] ?? ''), 'started_at' => (string) $r['started_at'],
                'consent' => (string) ($r['consent'] ?? 'none'), 'consent_at' => (string) ($r['consent_at'] ?? ''),
                'status' => (string) $r['status'], 'waiting' => in_array((string) $r['status'], ['recording', 'finished'], true) && !self::canTranscribe(),
                'parts' => $parts, 'marks' => self::cleanMarks(json_decode((string) ($r['marks'] ?? ''), true) ?: []),
                'talk' => ['me' => (int) round($me), 'them' => (int) round($them), 'me_name' => $names['me'] !== '' ? $names['me'] : (string) ($r['by_name'] ?? ''), 'them_name' => $names['them']],
            ];
        }
        return $out;
    }

    /** One part's file, with the meeting it belongs to (for the access check). */
    public static function segmentFile(int $segId): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT s.path, s.mime, s.seg_no, s.recording_id, r.meeting_id FROM recording_segments s JOIN recordings r ON r.id = s.recording_id WHERE s.id = ? AND r.status <> 'cancelled'");
        $st->execute([$segId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r && (string) $r['path'] !== '' && is_file((string) $r['path']) ? $r : null;
    }

    /** What is waiting for a transcriber, and how much audio the host is holding. For the Studio. */
    public static function backlog(): array
    {
        self::ensure();
        $db = Database::pdo();
        $waiting = (int) $db->query("SELECT COUNT(*) FROM recordings WHERE status IN ('recording','finished')")->fetchColumn();
        $oldest = (string) $db->query("SELECT MIN(started_at) FROM recordings WHERE status IN ('recording','finished')")->fetchColumn();
        $bytes = (int) $db->query("SELECT COALESCE(SUM(bytes), 0) FROM recording_segments WHERE path <> ''")->fetchColumn();
        $free = @disk_free_space(self::baseDir());
        return ['waiting' => $waiting, 'oldest' => $oldest, 'bytes' => $bytes, 'free' => is_float($free) || is_int($free) ? (int) $free : null];
    }

    /* ── the cron: transcribe, stitch, tidy ────────────────────────────── */

    /**
     * One cron tick. Transcribes segments while there is time (a shared host
     * gives a web-cron 30–60 s), then writes minutes for recordings that are
     * complete, closes ones the browser abandoned, and deletes old audio.
     */
    public static function sweep(int $budgetSec = 25, ?callable $transcriber = null): array
    {
        self::ensure();
        $t0 = microtime(true);
        $out = ['transcribed' => 0, 'failed' => 0, 'finalised' => 0, 'abandoned' => 0, 'purged' => 0];
        $db = Database::pdo();
        $transcriber = $transcriber ?? [self::class, 'transcribe'];

        // Abandoned: still "recording" but silent for hours (the laptop died).
        $st = $db->prepare("SELECT id FROM recordings WHERE status = 'recording' AND updated_at < ?");
        $st->execute([gmdate('Y-m-d H:i:s', time() - self::ABANDON_AFTER)]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rid) {
            $n = count(self::segmentNumbers((int) $rid));
            $db->prepare("UPDATE recordings SET status = 'finished', segments_expected = ?, finished_at = ?, note = ? WHERE id = ?")
               ->execute([$n, self::now(), 'The browser stopped sending; minutes written from what arrived.', (int) $rid]);
            $out['abandoned']++;
        }

        if (self::canTranscribe() || $transcriber !== [self::class, 'transcribe']) {
            $pick = $db->prepare("SELECT s.*, r.user_id FROM recording_segments s JOIN recordings r ON r.id = s.recording_id
                                  WHERE s.state = 'ready' AND s.attempts < ? AND r.status IN ('recording','finished')
                                  ORDER BY s.recording_id, s.seg_no LIMIT 50");
            $capped = [];
            while (microtime(true) - $t0 < $budgetSec) {
                $pick->execute([self::MAX_ATTEMPTS]);
                $s = null;
                // The first part whose owner is still inside today's transcription allowance.
                foreach ($pick->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
                    $owner = (int) $c['user_id'];
                    if (!isset($capped[$owner])) $capped[$owner] = self::transcribedToday($owner) >= self::dailyParts();
                    if (!$capped[$owner]) { $s = $c; break; }
                }
                if (!$s) break;
                // Claim it, so an overlapping cron tick does not transcribe it twice.
                $claim = $db->prepare("UPDATE recording_segments SET state = 'transcribing', attempts = attempts + 1, updated_at = ? WHERE id = ? AND state = 'ready'");
                $claim->execute([self::now(), (int) $s['id']]);
                if ($claim->rowCount() !== 1) continue;
                $bytes = is_file((string) $s['path']) ? (string) file_get_contents((string) $s['path']) : '';
                $res = $bytes === '' ? ['ok' => false, 'error' => 'The audio file is missing.'] : $transcriber($bytes, (string) $s['mime'], self::hints((int) $s['recording_id'], (int) $s['seg_no']));
                if (!empty($res['ok'])) {
                    $utt = !empty($res['segments']) && is_array($res['segments']) ? json_encode(array_values($res['segments']), JSON_UNESCAPED_UNICODE) : null;
                    $db->prepare("UPDATE recording_segments SET state = 'done', text = ?, utterances = ?, error = '', updated_at = ? WHERE id = ?")
                       ->execute([trim((string) $res['text']), $utt, self::now(), (int) $s['id']]);
                    $out['transcribed']++;
                    unset($capped[(int) $s['user_id']]);   // counted again next time round
                } else {
                    $final = (int) $s['attempts'] + 1 >= self::MAX_ATTEMPTS || $bytes === '';
                    $db->prepare('UPDATE recording_segments SET state = ?, error = ?, updated_at = ? WHERE id = ?')
                       ->execute([$final ? 'failed' : 'ready', mb_substr((string) ($res['error'] ?? 'Transcription failed.'), 0, 500), self::now(), (int) $s['id']]);
                    if ($final) $out['failed']++;
                    if (!$final) break;   // the provider is unhappy: try again next tick, not in a tight loop
                }
            }
        }

        // A segment left "transcribing" by a tick that was killed mid-request goes back in the queue.
        $db->prepare("UPDATE recording_segments SET state = 'ready' WHERE state = 'transcribing' AND updated_at < ?")
           ->execute([gmdate('Y-m-d H:i:s', time() - 600)]);

        $fin = $db->query("SELECT * FROM recordings WHERE status = 'finished'");
        foreach ($fin->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (self::finalise($r)) $out['finalised']++;
        }

        $out['purged'] = self::purge();
        return $out;
    }

    /**
     * What the transcriber should know about this part, to spell names and
     * terms right and to carry on from where the previous part stopped:
     * the meeting's title and agenda, the people in it, the organisation's own
     * words (AV_REC_VOCAB adds more), and the last words of the part before.
     * Whisper reads a short "prompt" as text that came before; Gemini gets a
     * full instruction. Returns ['whisper' => …, 'gemini' => …].
     */
    public static function hints(int $rid, int $segNo): array
    {
        $db = Database::pdo();
        $st = $db->prepare('SELECT r.meeting_id, m.title, m.agenda, m.creator_id, m.organiser_name FROM recordings r LEFT JOIN meetings m ON m.id = r.meeting_id WHERE r.id = ?');
        $st->execute([$rid]);
        $m = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $names = [];
        if (!empty($m['meeting_id'])) {
            $p = $db->prepare('SELECT u.name FROM meeting_attendees a JOIN lms_users u ON lower(u.email) = lower(a.email) WHERE a.meeting_id = ? LIMIT 40');
            $p->execute([(int) $m['meeting_id']]);
            $names = $p->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $c = $db->prepare('SELECT name FROM lms_users WHERE id = ?');
            $c->execute([(int) ($m['creator_id'] ?? 0)]);
            $names[] = (string) $c->fetchColumn();
            $names[] = (string) ($m['organiser_name'] ?? '');
        }
        $names = array_values(array_unique(array_filter(array_map(static fn($n) => trim(preg_replace('/\s+/u', ' ', (string) $n) ?? ''), $names))));
        $vocab = array_filter(array_map('trim', explode(',', (string) (getenv('AV_REC_VOCAB') ?: '')))) ;
        $terms = array_values(array_unique(array_merge(['Afrovanguard', 'CACENTRE', 'NextGen Vanguard', "D'Vanguard", 'VanQuest', 'Africa GATES'], $vocab)));
        $title = trim((string) ($m['title'] ?? ''));
        $agenda = trim(mb_substr(preg_replace('/\s+/u', ' ', (string) ($m['agenda'] ?? '')) ?? '', 0, 200));

        $prev = '';
        if ($segNo > 0) {
            $q = $db->prepare("SELECT text FROM recording_segments WHERE recording_id = ? AND seg_no = ? AND state = 'done'");
            $q->execute([$rid, $segNo - 1]);
            $prev = trim((string) $q->fetchColumn());
            $prev = $prev !== '' ? mb_substr($prev, -300) : '';
        }

        // Whisper reads only the last ~224 tokens of its prompt: the glossary stays short and the previous words come last, nearest the audio.
        $gloss = ($title !== '' ? $title . '. ' : '') . ($names ? 'With ' . implode(', ', array_slice($names, 0, 15)) . '. ' : '') . implode(', ', $terms) . '.';
        $whisper = mb_substr($gloss, 0, 420) . ($prev !== '' ? ' … ' . $prev : '');
        $gemini = 'Transcribe this meeting audio verbatim, as plain text only. Label speakers as Speaker 1, Speaker 2, … when distinguishable'
            . ($names ? ', and use a name instead when someone is clearly addressed or introduces themself' : '') . '. '
            . 'People may speak Nigerian English, Pidgin, or Yoruba, Igbo or Hausa words: write them as spoken, do not translate. '
            . ($title !== '' ? 'The meeting: ' . $title . '. ' : '') . ($agenda !== '' ? 'Agenda: ' . $agenda . '. ' : '')
            . ($names ? 'People in it: ' . implode(', ', array_slice($names, 0, 30)) . '. ' : '')
            . 'Spell these exactly: ' . implode(', ', $terms) . '. '
            . ($prev !== '' ? 'This recording continues from a previous part that ended: "' . $prev . '". Do not repeat that text.' : '');
        // Who is talking when: Gemini labels lines itself, so it gets the timeline.
        $sp = self::speakers($rid);
        $tk = $db->prepare('SELECT talk FROM recording_segments WHERE recording_id = ? AND seg_no = ?');
        $tk->execute([$rid, $segNo]);
        $runs = self::cleanTalk(json_decode((string) $tk->fetchColumn(), true) ?: []);
        $mine = array_values(array_filter($runs, static fn($r) => $r[2] === 'me'));
        if ($sp['me'] !== '' && $mine) {
            $gemini .= ' Start each line with the speaker\'s name and a colon. ' . $sp['me'] . ' is the person recording, heard through their own microphone; they are speaking at about these times (seconds from the start of this audio): '
                . implode(', ', array_map(static fn($r) => round($r[0]) . '–' . round($r[1]), array_slice($mine, 0, 120))) . '. '
                . ($sp['them'] !== '' ? 'The only other person in the call is ' . $sp['them'] . '; label their lines with that name.' : 'Everyone else is heard through the call: label them Speaker 2, Speaker 3 … unless they are clearly named.');
        }
        return ['whisper' => $whisper, 'gemini' => $gemini];
    }

    /**
     * The names that are safe to put on lines. "me" is the person who recorded
     * (their microphone is a separate source, so this is reliable). "them" is
     * filled only for a one-to-one call: the meeting has exactly one other
     * named person, and Meet never showed more than two people in it. Anyone
     * else stays unnamed: a wrong name on an action item is worse than none.
     */
    public static function speakers(int $rid): array
    {
        $db = Database::pdo();
        $st = $db->prepare('SELECT r.user_id, r.meeting_id, r.people_max, m.creator_id FROM recordings r LEFT JOIN meetings m ON m.id = r.meeting_id WHERE r.id = ?');
        $st->execute([$rid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['me' => '', 'them' => ''];
        $me = self::userRow((int) $r['user_id']);
        $myName = trim((string) ($me['name'] ?? ''));
        $myEmail = strtolower((string) ($me['email'] ?? ''));
        $people = [];
        $a = $db->prepare('SELECT lower(email) FROM meeting_attendees WHERE meeting_id = ?');
        $a->execute([(int) $r['meeting_id']]);
        foreach ($a->fetchAll(PDO::FETCH_COLUMN) ?: [] as $e) $people[(string) $e] = true;
        $c = self::userRow((int) ($r['creator_id'] ?? 0));
        if ($c && !empty($c['email'])) $people[strtolower((string) $c['email'])] = true;
        unset($people[$myEmail]);
        $them = '';
        // Exactly two people seen in the call (you and them). Unknown (0) is not "one-to-one":
        // a Zoom tab, or a Meet page the count could not read, names nobody.
        if (count($people) === 1 && (int) $r['people_max'] === 2) {
            $n = $db->prepare('SELECT name FROM lms_users WHERE lower(email) = ?');
            $n->execute([array_key_first($people)]);
            $them = trim((string) $n->fetchColumn());
        }
        return ['me' => $myName, 'them' => $them];
    }

    /** Talk runs: [[start, end, "me"|"them"], …] in seconds of the part's audio; at most 3000. */
    public static function cleanTalk(array $talk): array
    {
        $out = [];
        foreach ($talk as $t) {
            if (!is_array($t) || count($t) < 3 || !is_numeric($t[0]) || !is_numeric($t[1]) || !in_array($t[2], ['me', 'them'], true)) continue;
            $a = max(0.0, (float) $t[0]); $b = (float) $t[1];
            if ($b <= $a || $a > 7200) continue;
            $out[] = [round($a, 2), round(min($b, 7200.0), 2), $t[2]];
            if (count($out) >= 3000) break;
        }
        usort($out, static fn($x, $y) => $x[0] <=> $y[0]);
        return $out;
    }

    /**
     * Put names on Whisper's timed sentences: a sentence is "me" or "them"
     * when that side was talking for most of it, and unlabelled when it is
     * not clear (crosstalk, silence detection missed it). Consecutive lines
     * of one speaker become one paragraph. Returns '' when there is nothing
     * to go on, and the plain text is used instead.
     */
    public static function labelled(array $utterances, array $talk, array $names): string
    {
        $talk = self::cleanTalk($talk);
        if (!$utterances || !$talk || ($names['me'] ?? '') === '') return '';
        $label = ['me' => (string) $names['me'], 'them' => (string) (($names['them'] ?? '') !== '' ? $names['them'] : 'Others')];
        $lines = [];
        foreach ($utterances as $u) {
            $a = (float) ($u['start'] ?? 0); $b = max($a + 0.1, (float) ($u['end'] ?? 0));
            $over = ['me' => 0.0, 'them' => 0.0];
            foreach ($talk as [$s, $e, $who]) { $o = min($b, $e) - max($a, $s); if ($o > 0) $over[$who] += $o; }
            $heard = $over['me'] + $over['them'];
            $who = '';
            if ($heard >= 0.3 * ($b - $a)) foreach (['me', 'them'] as $k) if ($over[$k] >= 0.65 * $heard) $who = $k;
            $text = trim((string) ($u['text'] ?? ''));
            if ($text === '') continue;
            $last = count($lines) - 1;
            if ($last >= 0 && $lines[$last][0] === $who) $lines[$last][1] .= ' ' . $text;
            else $lines[] = [$who, $text];
        }
        return implode("\n", array_map(static fn($l) => ($l[0] !== '' ? $label[$l[0]] . ': ' : '') . $l[1], $lines));
    }

    /** Whisper first, Gemini as the fallback — the same order as Meetings::transcribeAudio. */
    public static function transcribe(string $bytes, string $mime, array $hints = []): array
    {
        $res = null;
        if (class_exists('OpenAi') && OpenAi::configured()) $res = OpenAi::transcribeAudio($bytes, $mime, (string) ($hints['whisper'] ?? ''), true);
        if ((!$res || empty($res['ok'])) && class_exists('Gemini') && Gemini::configured()) {
            $g = Gemini::transcribeAudio($bytes, $mime, (string) ($hints['gemini'] ?? ''));
            if (!empty($g['ok']) || !$res) $res = $g;
        }
        return $res ?: ['ok' => false, 'error' => 'Transcription is not configured.'];
    }

    /** Write the minutes once every segment has been dealt with. True when it closed the recording. */
    private static function finalise(array $r): bool
    {
        $rid = (int) $r['id'];
        $db = Database::pdo();
        $st = $db->prepare('SELECT seg_no, state, offset_sec, text, talk, utterances FROM recording_segments WHERE recording_id = ? ORDER BY seg_no');
        $st->execute([$rid]);
        $segs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $have = array_map('intval', array_column($segs, 'seg_no'));
        $expected = (int) $r['segments_expected'];
        $late = strtotime((string) $r['finished_at'] . ' UTC') < time() - self::ABANDON_AFTER;
        // Wait for segments the browser still owes, unless it has been hours.
        if (!$late && $expected > 0 && count(array_diff(range(0, $expected - 1), $have)) > 0) return false;
        foreach ($segs as $s) if (!in_array((string) $s['state'], ['done', 'failed'], true)) return false;

        $text = self::stitch($segs, json_decode((string) ($r['marks'] ?? ''), true) ?: [], self::speakers($rid));
        $failed = count(array_filter($segs, static fn($s) => (string) $s['state'] === 'failed'));
        $note = $failed ? $failed . ' of ' . count($segs) . ' parts could not be transcribed.' : (string) $r['note'];
        if ($text === '') {
            $db->prepare("UPDATE recordings SET status = 'failed', note = ?, updated_at = ? WHERE id = ?")
               ->execute([$segs ? 'No speech could be transcribed.' : 'No audio arrived.', self::now(), $rid]);
            return true;
        }
        $mid = (int) $r['meeting_id'];
        // Someone else's transcript (the notetaker, Google, another recorder) is not overwritten.
        $existing = $db->prepare('SELECT source FROM meeting_transcripts WHERE meeting_id = ?');
        $existing->execute([$mid]);
        $src = $existing->fetchColumn();
        if ($src !== false && $src !== null) {
            $db->prepare("UPDATE recordings SET status = 'duplicate', transcript = ?, note = ?, updated_at = ? WHERE id = ?")
               ->execute([$text, 'The meeting already had minutes; this transcript is kept with the recording.', self::now(), $rid]);
            return true;
        }
        $res = Meetings::saveTranscript((int) $r['user_id'], $mid, $text, 'recorder');
        $ok = !empty($res['ok']);
        $db->prepare('UPDATE recordings SET status = ?, transcript = ?, note = ?, updated_at = ? WHERE id = ?')
           ->execute([$ok ? 'done' : 'failed', $text, $ok ? $note : mb_substr((string) ($res['error'] ?? 'The minutes could not be saved.'), 0, 500), self::now(), $rid]);
        return true;
    }

    private static function clock(int $sec): string
    {
        $sec = max(0, $sec);
        return sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
    }

    /** Marked moments: whole seconds, in order, no repeats, at most 200. */
    public static function cleanMarks(array $marks): array
    {
        $out = [];
        foreach ($marks as $m) if (is_numeric($m) && $m >= 0 && $m <= 360000) $out[] = (int) $m;
        $out = array_values(array_unique($out));
        sort($out);
        return array_slice($out, 0, 200);
    }

    /**
     * The parts in order, each headed by where it starts: "[00:10:00]". A
     * moment the person recording marked is noted at the end of its part, so
     * the minutes can give it weight.
     */
    public static function stitch(array $segs, array $marks = [], array $names = []): string
    {
        usort($segs, static fn($a, $b) => (int) $a['seg_no'] <=> (int) $b['seg_no']);
        $marks = self::cleanMarks($marks);
        $parts = [];
        foreach ($segs as $i => $s) {
            $t = trim((string) ($s['text'] ?? ''));
            if ((string) ($s['state'] ?? 'done') !== 'done') $t = '';
            if ($t !== '' && $names) {
                $named = self::labelled(json_decode((string) ($s['utterances'] ?? ''), true) ?: [], json_decode((string) ($s['talk'] ?? ''), true) ?: [], $names);
                if ($named !== '') $t = $named;
            }
            $o = max(0, (int) ($s['offset_sec'] ?? 0));
            $next = isset($segs[$i + 1]) ? (int) $segs[$i + 1]['offset_sec'] : PHP_INT_MAX;
            $here = array_filter($marks, static fn($m) => ($i === 0 || $m >= $o) && $m < $next);
            $note = $here ? "\n★ Marked as important by the person recording, at " . implode(', ', array_map([self::class, 'clock'], $here)) . '.' : '';
            if ($t !== '') $parts[] = '[' . self::clock($o) . "]\n" . $t . $note;
            elseif ((string) ($s['state'] ?? '') === 'failed') $parts[] = '[' . self::clock($o) . "]\n(This part could not be transcribed.)" . $note;
        }
        $real = array_filter($parts, static fn($p) => strpos($p, '(This part could not be transcribed.)') === false);
        return $real ? implode("\n\n", $parts) : '';
    }

    /* ── quotas ───────────────────────────────────────────────────────── */

    /** Audio one person may upload in 30 days (AV_REC_QUOTA_MB, default 2048 MB ≈ 140 hours). */
    public static function quota(int $uid): array
    {
        $limit = max(1, (int) (getenv('AV_REC_QUOTA_MB') ?: 2048)) * 1048576;
        $db = Database::pdo();
        $st = $db->prepare("SELECT COALESCE(SUM(s.bytes), 0) FROM recording_segments s JOIN recordings r ON r.id = s.recording_id WHERE r.user_id = ? AND r.started_at > ?");
        $st->execute([$uid, gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $used = (int) $st->fetchColumn();
        // Plus chunks waiting to be joined into a part: they take space too.
        $open = $db->prepare("SELECT id FROM recordings WHERE user_id = ? AND status = 'recording'");
        $open->execute([$uid]);
        foreach ($open->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rid) {
            foreach ((array) glob(self::baseDir() . '/' . (int) $rid . '/*.part') as $f) $used += (int) @filesize($f);
        }
        return ['used' => $used, 'limit' => $limit];
    }

    /** Parts one person may have transcribed per day (AV_REC_TRANSCRIBE_PARTS_DAY, default 72 ≈ 12 hours). */
    public static function dailyParts(): int
    {
        return max(1, (int) (getenv('AV_REC_TRANSCRIBE_PARTS_DAY') ?: 72));
    }

    public static function transcribedToday(int $uid): int
    {
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM recording_segments s JOIN recordings r ON r.id = s.recording_id WHERE r.user_id = ? AND s.state = 'done' AND s.bytes > 0 AND s.updated_at >= ?");
        $st->execute([$uid, gmdate('Y-m-d 00:00:00')]);
        return (int) $st->fetchColumn();
    }

    /**
     * Upload chunks nobody will join any more: those of a recording that is
     * closed (its parts are made, or it was abandoned or cancelled), of a
     * recording that no longer exists, and any older than two days. Returns
     * files removed. Bounded, so one tick stays short.
     */
    public static function purgeChunks(int $dirs = 200): int
    {
        $base = self::baseDir();
        $db = Database::pdo();
        $st = $db->prepare('SELECT status FROM recordings WHERE id = ?');
        $n = 0;
        foreach (array_slice((array) glob($base . '/[0-9]*', GLOB_ONLYDIR), 0, $dirs) as $dir) {
            $st->execute([(int) basename($dir)]);
            $status = $st->fetchColumn();
            if ($status === false) {                     // no such recording: everything goes
                foreach ((array) glob($dir . '/*') as $f) { if (is_file($f)) { @unlink($f); $n++; } }
                @rmdir($dir);
                continue;
            }
            // "finished" may still be receiving a part it owes, so only the 2-day rule applies to it.
            $closed = in_array((string) $status, ['done', 'duplicate', 'failed', 'cancelled'], true);
            foreach ((array) glob($dir . '/*.part') as $f) {
                if ($closed || @filemtime($f) < time() - 2 * 86400) { @unlink($f); $n++; }
            }
        }
        return $n;
    }

    /** Delete audio past its retention; move it to Drive first when asked to. Returns files removed. */
    public static function purge(): int
    {
        $n = self::purgeChunks();
        $days = getenv('AV_REC_RETAIN_DAYS');
        $days = $days === false || $days === '' ? 30 : (int) $days;
        $db = Database::pdo();
        $closed = "r.status IN ('done','duplicate','failed','cancelled')";
        if (strtolower((string) getenv('AV_REC_STORAGE')) === 'drive' && class_exists('Drive') && Drive::configured()) {
            $st = $db->query("SELECT s.id, s.path, s.recording_id, s.seg_no FROM recording_segments s JOIN recordings r ON r.id = s.recording_id
                              WHERE $closed AND s.path <> '' AND s.stored_url = '' LIMIT 3");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
                if (!is_file((string) $s['path'])) continue;
                try {
                    $up = Drive::upload((string) $s['path'], sprintf('recording-%d-part-%04d.webm', (int) $s['recording_id'], (int) $s['seg_no']), 'audio/webm');
                    $db->prepare("UPDATE recording_segments SET stored_url = ?, path = '' WHERE id = ?")->execute([(string) $up['url'], (int) $s['id']]);
                    @unlink((string) $s['path']); $n++;
                } catch (Throwable $e) { error_log('[recorder] drive: ' . $e->getMessage()); break; }
            }
        }
        if ($days > 0) {
            $st = $db->prepare("SELECT s.id, s.path FROM recording_segments s JOIN recordings r ON r.id = s.recording_id
                                WHERE $closed AND s.path <> '' AND r.updated_at < ?");
            $st->execute([gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
                @unlink((string) $s['path']);
                $db->prepare("UPDATE recording_segments SET path = '' WHERE id = ?")->execute([(int) $s['id']]);
                $n++;
            }
        }
        return $n;
    }
}
