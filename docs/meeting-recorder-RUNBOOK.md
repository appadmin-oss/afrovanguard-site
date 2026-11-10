# Meeting Recorder: runbook

Owner: **to be named** (the person who holds the signing key) · Version date: 2026-11-11 · Extension version: 1.4.0

The Meeting Recorder is a Chrome extension that records a meeting from the
browser tab (the meeting's sound plus your microphone) and turns it into
minutes on the meeting in the portal. It is installed for staff from the
Google Admin console, at no cost, without the Chrome Web Store.

| Part | Where |
|---|---|
| Extension source | `extensions/meeting-recorder/` |
| Publish (sign and build) | Studio page `/admin/recorder.php`, or `tools/build-recorder.php` with SSH. Both use `lib/RecorderBuild.php` |
| What Chrome downloads | `downloads/meeting-recorder/`, written on the server by Publish (not in git) |
| Server API | `integrations/recorder.php` → `lib/Recorder.php` |
| Connect page | `/portal/recorder.php` |
| Transcription | `tasks/cron.php` → `Recorder::sweep()` (the existing web-cron) |
| Tests | `php tests/run.php recorder` · `node extensions/meeting-recorder-tests/e2e.mjs` |
| Plan and design | `docs/meeting-recorder-extension-PLAN.md` |

---

## 1. First release (once): no SSH needed

Everything happens in the browser. You need a **superadmin** Studio account.

1. **Upload the site files** as usual (cPanel File Manager or FTP), including
   the hidden `downloads/meeting-recorder/.htaccess`.
2. **Publish.** Open `https://afrovanguard.org.ng/admin/recorder.php`
   (Studio → Setup → *Meeting Recorder →*) and click **Publish for the first
   time**. The site makes the signing key, keeps it in `db/private/recorder/`
   (never web-served), signs the extension and writes
   `downloads/meeting-recorder/` (the `.crx`, `updates.xml`, `extension-id.txt`).
   If the page says the host cannot publish, it names the missing piece:
   the PHP Zip extension (cPanel → Select PHP Version) or a `downloads` folder
   the site can write (File Manager, permissions 755).
3. **Back up the key.** On the same page click **Download the key**, and keep
   the file in the organisation's password manager or secrets vault. The
   extension's ID comes from this key, and every installed copy only accepts
   updates signed with it. If the site moves host, upload it on the new host's
   page (*Restore the key*) **before** publishing there.
4. **Add it in the Google Admin console** (the page shows the ID and URL to paste). admin.google.com → Devices →
   Chrome → Apps & extensions → Users & browsers → choose the staff
   organisational unit → yellow **+** → *Add Chrome app or extension by ID* →
   *From a custom URL*. ID = `extension-id.txt`, URL = the `updates.xml` URL
   above. Installation policy: **Force install** (and *Pin to browser
   toolbar*).
5. **Check it reached people.** On a staff laptop signed in to Chrome with
   a work account, `chrome://extensions` shows *Afrovanguard Meeting Recorder*
   (installed by policy) within about an hour. Force a refresh with
   `chrome://policy` → *Reload policies*.
6. **Transcription key.** Recordings are transcribed by Whisper (`OPENAI_API_KEY`)
   or Gemini (`AV_GEMINI_API_KEY`), set in Studio → Setup → AI. Without one,
   recordings upload and wait. They are transcribed as soon as a key is set.
7. **Cron.** Nothing new: the existing web-cron (`tools/cpanel-cron-setup.md`)
   now also runs the recorder's queue. Every 5 minutes or more often is right.

Only Chrome signed in with an afrovanguard.org.ng Google account gets the
extension. Personal Chrome profiles, mentors and partners don't (see §6).

## 2. Releasing an update

1. Change the code in `extensions/meeting-recorder/`.
2. Raise `"version"` in `extensions/meeting-recorder/manifest.json` (e.g. 1.0.0 → 1.0.1).
   Chrome ignores a package whose version did not go up.
3. Run the tests (§5).
4. Upload the changed files, then open `/admin/recorder.php` and click
   **Publish version …**. Chrome updates installed copies within a few hours.
   (With SSH, `php tools/build-recorder.php` does the same.)

## 3. What staff do

1. First time only: the set-up page opens by itself. Click *Allow the
   microphone*, then *Connect*. Connect opens `/portal/recorder.php` while
   signed in and links the browser to the account.
2. In a meeting: click the red icon → **Record this meeting**, or press
   **Alt+Shift+R**. On Google Meet a reminder appears when the call starts.
   **Tell the others the meeting is being recorded — the recorder does not
   tell them.** Its reminder appears only on your own screen. Once you have
   told them, click **I've told everyone** on the banner (or in the popup).
   The recording keeps exactly what happened: *confirmed* (with the time),
   *reminded* (the reminder was on your screen, but you didn't confirm), or
   *none*. The listening page shows it.
3. While recording:
   - **Alt+Shift+M** (or *★ Mark this moment*) flags the moment as important.
     It appears in the transcript as "★ Marked as important … at 00:12:34", so
     the minutes pick it up.
   - **Alt+Shift+P** (or *Pause*) pauses for a side conversation, and the same
     again resumes. Nothing is recorded while paused.
   - The recorder warns you if your microphone sends no sound at all (muted,
     or the wrong one chosen), or if the meeting tab is silent from the start
     (probably the wrong tab). It tells you if the notetaker is already in
     the meeting.
4. To stop: press Alt+Shift+R again, click *Stop*, leave the call, or close
   the tab. It also stops by itself after 10 minutes of silence.
5. **Listening.** Every recorded meeting gets **🎧 Listen** on its card in the
   portal (for the meeting's attendees and those who manage it). The page
   plays the parts in order, jumps to each ★ marked moment, shows who talked
   how long, and offers the whole recording as a zip.
6. **With no AI transcriber** (no OpenAI or Gemini key), recordings still
   upload and can be listened to; the popup says *Saved — no written minutes
   yet*, never "Minutes ready". Write the minutes by hand on the meeting, or
   add a key later: every waiting recording is then transcribed by itself,
   oldest first. Audio waiting for a transcriber is never deleted; the
   Studio page (`/admin/recorder.php`) shows how much is waiting and how
   much disk it uses, and warns below 1 GB free.
7. **Speaker names.** In the transcript, your own lines carry your name. In a
   one-to-one call (the meeting has exactly one other person, **and** Meet
   showed exactly two people in the call), the other person's lines carry
   theirs. If the head-count is unknown (a Zoom or Teams tab, or a Meet page
   it could not read), nobody but you is named. In a group
   call, everyone else's lines are labelled **Others**. The recorder does not
   guess who in the call is speaking, because a wrong name on an action item
   is worse than none. A line where both sides talked over each other stays
   unlabelled. For named speakers in a large group call, use Google Meet's
   own transcript (the meeting's *Pull Google Meet transcript*), which knows
   every speaker.
8. The minutes appear on the meeting in the portal (*Meetings*), usually a
   few minutes after the upload finishes, and a Chrome notification says so.

If the meeting was scheduled in the portal, the recording attaches to it (by
the Meet code). Otherwise the recorder makes a meeting record titled with the
call's name and date, with the person recording as an attendee. It never
opens a new Meet room.

## 4. When something is wrong

| What you see | Why | What to do |
|---|---|---|
| Badge shows **!** | Not connected, or the device was disconnected in the portal | Click the icon → Connect |
| Badge shows **↑** for a long time | Uploads waiting: offline, or the site is down | Nothing; they resume on their own. The audio is kept in the browser until the server has it |
| *"Your microphone is not being recorded"* | Permission not given | Open the set-up page from the popup link and allow it |
| Upload done but no minutes | No transcription key (the popup says *Saved*), or the cron is not running | Listen and write them by hand, or add a key in Studio → Setup → AI; check the cron's last run |
| *"Kept with the meeting"* | The notetaker or Google already wrote the minutes | By design: the existing minutes are never overwritten. The recorder's transcript is in `recordings.transcript` |
| A lost laptop, or someone who left | Its token can still upload | The user (portal → Meeting Recorder → *Disconnect*), or a superadmin on `/admin/recorder.php` → *Connected browsers* → *Disconnect*. A token also stops by itself when its owner is no longer a member |
| *"Your recording space is full"* | The person passed `AV_REC_QUOTA_MB` in 30 days | The audio waits in their browser and is retried hourly; raise the quota, or wait for older recordings to age out |
| Parts stay untranscribed for a day | The person passed `AV_REC_TRANSCRIBE_PARTS_DAY` | They continue the next day (UTC) by themselves |
| Cron stopped after this update | The scheduler still sends the admin token, which is no longer accepted | Paste the command from `/admin/recorder.php` → *Scheduled job* into cPanel → Cron Jobs |
| Chrome says the extension is *corrupted* | The `.crx` was served with the wrong type or modified | Check `.htaccess` has `AddType application/x-chrome-extension .crx`; rebuild |

Server-side state is in four tables: `recorder_devices`, `recorder_pairings`,
`recordings`, `recording_segments`. Audio is in `db/private/recordings/<id>/`,
which the web can't reach.

## 5. Tests (run before every release)

```bash
php tests/run.php recorder academyroutes
```

```bash
node extensions/meeting-recorder-tests/e2e.mjs
```

The second one starts the site on a throwaway database, builds the extension
with a throwaway key, and loads it into Chromium (Playwright). It connects
through the real portal page, records three segments, uploads them, survives
the server going away mid-meeting, has the cron write the minutes, and checks
the popup: 30 checks. It **cannot** press the toolbar button, which Chrome
requires before it will capture a tab. So after a release, do one real
2-minute Meet call on a staff laptop and confirm the minutes arrive.

## 6. Settings

| Setting | Default | Meaning |
|---|---|---|
| `AV_REC_AUDIENCE` | members | `all` lets any signed-in account connect a recorder |
| `AV_REC_RETAIN_DAYS` | 30 | Days audio is kept after the minutes are written; `0` keeps it. Transcripts are kept |
| `AV_REC_STORAGE` | local | `drive` moves finished audio to Google Drive (uses the existing `AV_GDRIVE_*`) |
| `AV_REC_CHUNK_MAX` | 1 MB | Largest upload piece. Never above what the host's `upload_max_filesize` allows: the server lowers it on its own |
| `AV_REC_QUOTA_MB` | 2048 | Audio one person may upload in 30 days (about 140 hours) |
| `AV_REC_TRANSCRIBE_PARTS_DAY` | 72 | Ten-minute parts one person may have transcribed per day (about 12 hours), so one person can't run up the AI bill |
| `AV_CRON_KEY` | derived | The scheduled job's key. If unset, the site derives one from its secret. The admin token is **not** accepted |
| `AV_REC_VOCAB` | — | Extra names and terms the transcriber should spell right, comma-separated (e.g. `Ikeja hub, Ndukwe`). The meeting's title, agenda and attendees' names are always given |
| `AV_REC_DIR` | `db/private/recordings` | Where audio is kept on the server |

## 7. For the privacy policy (draft: needs the owner's approval before it goes on `/privacy-policy/`)

> **Meeting recordings.** Afrovanguard staff may record meetings with our
> Meeting Recorder browser extension. A recording contains what is said in
> the meeting. It is sent only to Afrovanguard's own servers, where it is
> transcribed by our transcription provider (OpenAI or Google) to write the
> meeting's minutes. The audio is deleted 30 days after the minutes are
> written. A recording of a call that was not scheduled in our portal is
> private: only the person who recorded it, its attendees and our staff who
> manage meetings can read its minutes or hear it. For a scheduled meeting,
> its minutes follow the privacy the organiser set; the audio is only ever for
> its attendees and those who manage it. People recording must tell everyone
> in the meeting before they start, and record that they did. To ask about or remove a
> recording, contact us at the address below.

## 8. Known limits (v1)

- Chromium browsers only (Chrome, Edge, Brave). Firefox and Safari have no tab capture.
- Starting a recording always takes one click or the shortcut. Chrome does
  not let any extension record a tab on its own. Hands-free recording stays
  with the notetaker bot.
- Meet's page changes without notice. If it does, the *in-call reminder* and
  *stop when you leave* may go quiet. Recording itself, stop on tab close and
  stop on silence don't depend on Meet's page.
- Speaker names: you, and the other person in a one-to-one call. Others in a
  group call are "Others" (Gemini may still use a name when someone is clearly
  addressed). Telling them apart would need Meet's page, which changes without
  notice.
- Telling you from the call relies on Chrome's echo cancellation. On loud
  laptop speakers with no headset, some of the call can leak into your
  microphone. The recorder needs your voice to be clearly louder than the call
  before it calls a moment yours. No Zoom or Teams prompts yet, though
  recording those tabs already works.
- The "tell everyone" notice is shown to the person recording, not posted
  into the call.
