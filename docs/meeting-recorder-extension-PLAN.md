# Afrovanguard Meeting Recorder: Chrome extension plan

Status: **v1 built** (phases 1–3, distribution by the Admin console) · Owner: to be named · Version date: 2026-11-09
How to run and release it: `docs/meeting-recorder-RUNBOOK.md`.

## 1. What it is, in one paragraph

A Chrome (and Edge/Brave) extension that records a meeting from inside the
browser tab, captures the tab's audio plus your microphone, and sends the
recording to the meeting record that already exists on the Afrovanguard portal.
From there the current system handles everything else: transcription
(Whisper, falling back to Gemini), Otter-style minutes, decisions, and action
items filed as commitments. The extension does not build a second meeting
system. It is a new way to get audio into `Meetings`, the one that works when
the Attendee notetaker bot can't join: guests-only calls, external organisers'
calls, Zoom or Teams web calls, or a bot that got refused at the door.

## 2. What already exists (from `meeting-recorder-extension`, which is the latest `afrovanguard-site` line)

| Piece | Where | What the extension reuses |
|---|---|---|
| Meeting records, attendees, Meet codes | `lib/Meetings.php` (`meetings.meet_code`) | Match a recording to its meeting from the Meet URL |
| Instant meeting | `Meetings::startNow` | Make a record when an unscheduled call is recorded |
| Audio → transcript → minutes | `Meetings::transcribeAudio` → `saveTranscript` → `structure` → `fileCommitments` | The whole after-meeting pipeline |
| Notetaker bot | `lib/AttendeeBot.php`, `Meetings::dispatchDueBots` | The extension steps aside when the bot is already in the call |
| Web cron | `tasks/cron.php` (cPanel cron or `AV_CRON_KEY`) | Background transcription jobs |
| Drive storage | `lib/Drive.php` | Where finished recordings are kept on stateless or small hosts |
| Inbound tokens | `lib/AppTokens.php` | The token-hashing pattern (these tokens are org-wide, so the extension needs per-user tokens, §5) |

**Shared-host limits in the current upload path** (`portal/meetings.php?action=transcribe`):
it takes one file of up to 19 MB in a single request and transcribes it
before it responds. On cPanel that fails three ways: `upload_max_filesize`
(often 2–8 MB), `max_execution_time` (often 30–60 s), and Whisper's 25 MB cap.
A one-hour meeting is too big. Sections 4 and 6 fix this.

## 3. Architecture (Manifest V3)

```
 meet.google.com tab                    Extension                                Afrovanguard (shared host, PHP)
 ┌──────────────────┐   tab audio    ┌─────────────────────────────┐   HTTPS   ┌──────────────────────────────┐
 │ content script   │──────────────▶ │ offscreen document          │  chunks   │ integrations/recorder.php    │
 │ · meeting code   │                │ · tabCapture stream + mic   │─────────▶ │ · auth (device token)        │
 │ · join/leave     │◀── banner ──── │ · Web Audio mix → Opus      │           │ · chunk store + SHA-256      │
 │ · consent banner │                │ · MediaRecorder, 10-min     │           │ · assemble per segment       │
 └──────────────────┘                │   segments                  │           └──────────────┬───────────────┘
                                     │ · IndexedDB buffer          │                          │ queue row
        toolbar / Alt+Shift+R ─────▶ │ service worker              │                          ▼
                                     │ · state, retry, alarms      │           tasks/cron.php → RecorderJobs::sweep
                                     │ side panel / popup          │           → Whisper/Gemini per segment
                                     └─────────────────────────────┘           → Meetings::saveTranscript (stitched)
```

Key Chrome decisions:

- **`chrome.tabCapture.getMediaStreamId`** in the service worker, with the
  stream used in an **offscreen document** (`reason: USER_MEDIA`). This is
  Google's supported MV3 recording pattern. It needs no "share your screen"
  picker, and the tab keeps playing audio to the user (route the captured
  stream back to `AudioContext.destination`, or the tab goes silent).
- **Microphone:** `getUserMedia` in the offscreen document. Permission is
  granted once through an extension page, since offscreen documents can't
  show prompts. The two sources are mixed with Web Audio into one track.
- **Audio-only by default:** Opus in WebM at 32 kbps is about 14 MB per hour.
  Video (tab capture at 720p) is optional and off by default. It costs about
  100× the storage, and the minutes don't need it.
- **Segmented recording:** MediaRecorder is restarted every 10 minutes, so
  each segment is a standalone WebM file (about 2.5 MB). Each one is under
  every provider limit, can be transcribed on its own, and a crash loses at
  most one segment. No ffmpeg is needed on the server, and shared hosts don't
  have it.
- **Crash-safe buffer:** each `dataavailable` slice (every 5 s) goes to
  IndexedDB before upload. If the browser closes, the tab crashes or the
  network drops, the recording finishes uploading next time Chrome opens.
- **Chromium only:** Firefox and Safari have no `tabCapture` or offscreen
  documents. Chrome, Edge and Brave all work from one build.

## 4. Built for shared hosting

| Shared-host constraint | Design response |
|---|---|
| Small `upload_max_filesize` / `post_max_size` | Server tells the extension its limit (`GET ?action=limits` reads `ini_get`), extension uploads 1 MB chunks (default), never more than the limit |
| 30–60 s `max_execution_time` | Upload requests only write bytes and return. Transcription runs later in the existing cron, **one segment per tick**, each well under the time limit |
| No long-lived workers / websockets | Plain HTTPS POST + polling (`?action=status`). No server push needed |
| No ffmpeg / no shell | Segments are already independently playable, so they never need re-encoding or splitting |
| Disk quotas | Chunks are assembled into one segment and deleted on arrival. Segments go to Drive when `AV_GDRIVE_*` is set, otherwise to a non-public `db/private/recordings/`. Retention sweep deletes audio after N days (default 30); the transcript stays |
| Flaky connections / Nigerian mobile data | Resumable: each chunk is idempotent `(recording_id, segment, index, sha256)`, the server reports what it already has, the extension resends only what's missing, with exponential backoff via `chrome.alarms` |
| `mod_security` blocking odd bodies | `multipart/form-data` with ordinary field names, so no raw binary PUT is needed |
| SQLite / MySQL / Postgres | New tables via the existing `Database::execSchema` + `translateDDL`, so it works on any engine |

## 5. Sign-in and security

- **Pairing, not passwords:** the user opens *Portal → Meetings → Connect
  recorder*. The portal page sends a one-time pairing code to the extension
  through `externally_connectable` (scoped to the Afrovanguard origin only),
  and the extension exchanges it for a **per-user device token** (`av_rec_…`).
  Only its SHA-256 is stored, in a new `recorder_devices` table (user id,
  name, last used, revoked). Users and admins can revoke tokens from the portal.
- **Why not cookies:** extension requests carrying portal cookies hit
  SameSite and CSRF rules (`av_require_write`) and expire with the session.
  A bearer token avoids all of that and can be revoked per device.
- **Scope:** the token can only create or upload recordings for that user
  and see status. Writing into a meeting still goes through
  `Meetings::isParticipant`, so you can't record into someone else's meeting.
- **Transport:** HTTPS only, the token in an `Authorization` header (never in
  a URL), rate-limited like other writes, and each chunk's SHA-256 checked
  server-side.
- **Consent:** when recording starts, the extension shows a "This meeting is
  being recorded" banner and can post a one-line notice into Meet chat (opt-in
  setting). The portal stores who recorded, when, and that consent was shown.
  This matters under Nigeria's NDPA 2023 and for external guests.

## 6. Server additions (afrovanguard-site)

1. `integrations/recorder.php`, a JSON API:
   `pair`, `limits`, `start {meet_code|meeting_id, title}` → `recording_id`,
   `chunk` (multipart), `segment_done`, `finish`, `status`, `cancel`.
2. `lib/Recorder.php`: tables `recorder_devices`, `recordings`,
   `recording_segments`. It matches `meet_code` to a meeting (or calls
   `Meetings::startNow`), assembles chunks, and enforces retention.
3. `lib/RecorderJobs.php` + one hook in `tasks/cron.php`: it transcribes the
   next pending segment (the existing `OpenAi` / `Gemini` helpers), and when
   all segments are done it stitches text with `[mm:ss]` offsets and calls
   the **existing** `saveTranscript`, so minutes and commitments work exactly
   as they do today.
4. Portal UI: a "Connect recorder" card, a device list, and a live "Recording
   · uploading 3/6 · transcribing" status on the meeting card.
5. Raise the old `transcribe` path's 19 MB ceiling by routing large uploads
   through the same segment queue (an optional side benefit).

## 7. "Smart and automated" features: what's possible and what isn't

Chrome enforces one hard rule: **tab capture can only start from a user
action** (toolbar click, keyboard shortcut, context menu). No extension may
start recording a tab on its own. So the design gets as close to automatic as
Chrome allows:

- **One-press start:** when a Meet tab opens and the server says it's a
  scheduled meeting with `auto_record` on, the toolbar icon pulses and a
  banner offers **Record (Alt+Shift+R)**. That is a single click or keystroke.
- **Fully automatic start** stays with the existing Attendee notetaker bot,
  which already joins on schedule. The extension sees the bot in the call and
  says "the notetaker is already recording". This avoids two transcripts of
  one meeting.
- **Auto-stop** when you leave the call, close the tab, or after 10 minutes of
  silence. A reminder fires near the scheduled end (reusing `MeetingClock`).
- **Auto-link** to the correct meeting from the Meet code, with no picking.
- **Auto-title** for unscheduled calls (from the Meet tab title plus the date).
- **Speaker hints:** the content script records who was speaking from Meet's
  active-speaker indicator. These are timestamped names the minutes can use.
  It is best-effort, because Google can change Meet's page structure at any
  time, so it must never break recording.
- **Self-healing upload:** the queue resumes after crashes, sleep and offline
  periods. Each failure carries a plain-language reason in the popup.
- **Popup** (v1; a side panel can come later): live timer, level meters (so "my mic was muted" is caught
  in the first 10 seconds), segment and upload status, and a link to the
  minutes when they're ready.

## 8. Distribution: Google Admin console, not the Web Store (decided 2026-11-09)

Free, and nothing to find: the extension is **self-hosted** on the site and
**force-installed** for staff from the Google Admin console.

- `tools/build-recorder.php` signs a CRX3 package with the organisation's
  key (kept out of the repository) and writes `updates.xml`. Both are served
  from `/downloads/meeting-recorder/`. Chrome checks the URL and updates
  installed copies by itself.
- Admin console → Chrome → Apps & extensions → *Add by ID → From a custom
  URL* with the ID and `updates.xml` URL → *Force install*.
- Permissions kept to the minimum: `tabCapture`, `offscreen`, `storage`,
  `unlimitedStorage`, `alarms`, `notifications`, `activeTab`. Host access
  only to the site and `meet.google.com`. No remote code.
- The Web Store (US$5, public or unlisted) remains open later, for people
  outside the Workspace domain. The same package works there.

## 9. Phases

| Phase | Scope | Done when |
|---|---|---|
| 0 · Decisions | Answers to §10, named owner | Owner and distribution decided |
| 1 · Core recorder | MV3 skeleton, offscreen capture, tab + mic mix, 10-min segments, IndexedDB, popup | A 90-min Meet recorded in a test profile, every segment playable |
| 2 · Server | `recorder.php`, pairing, chunked resumable upload, cron transcription, stitched minutes | Upload survives a forced network cut and a browser kill, minutes appear on a cPanel staging host with 2 MB/30 s limits |
| 3 · Smart layer | Meet detection, one-press prompt, auto-stop, bot de-duplication, consent banner, side panel | A colleague who didn't build it runs a real meeting with no instructions beyond the runbook |
| 4 · Store | Privacy policy, listing, private-domain publish, force-install | Installed from the store on a fresh machine |
| 5 · Later | Zoom/Teams web, optional video, speaker labels in minutes, CACENTRE staff via the existing SSO handoff | Per demand |

Tests: PHP tests in `tests/recorder.test.php` (chunk idempotency, hash
mismatch, out-of-order chunks, ownership refusal, retention). For the
extension, a Playwright run with the unpacked build against a local Meet-like
page (Playwright can load extensions in Chromium) covers segment rotation and
the resume-after-kill path.

## 10. Decisions (defaults built in v1; each is a setting, see the runbook §6)

1. **Distribution:** ✅ decided: Admin console force-install for the Workspace domain.
2. **Who records:** members by default (`AV_REC_AUDIENCE=all` opens it to everyone signed in).
3. **Audio only, or offer video?** Audio only in v1.
4. **Where recordings live:** the host's private disk by default, Drive with
   `AV_REC_STORAGE=drive`. Audio is deleted 30 days after the minutes
   (`AV_REC_RETAIN_DAYS`), and transcripts are kept.
5. **CACENTRE:** still open. Not in v1.

## 11. The Five Operating Tests

| Test | How this plan passes it |
|---|---|
| Repeatability | This plan plus a runbook (`docs/meeting-recorder-RUNBOOK.md`, written in phase 3) with a named owner and version date. Phase 3 exit requires someone other than the builder to run it unassisted |
| Visibility | Recording, upload and transcription state shown on the meeting card. Failures logged through the existing `AiOps::run` wrapper in cron, so they appear with every other job |
| Ownership | A named owner for the extension listing and the server endpoint. The store account belongs to the organisation, not a person |
| Proven | Automated PHP and Playwright tests, plus the phase exit criteria in §9 as evidence |
| Scale without overhead | Force-install from the Admin console (no per-person setup), self-pairing, self-healing uploads, one segment per cron tick (load grows linearly and stays inside shared-host limits) |
