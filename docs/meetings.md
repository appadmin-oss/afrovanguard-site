# Meetings — scheduling, transcripts & the recording bot

The standardized meeting system (`lib/Meetings.php`, `portal/meetings.php`, the
**Meetings** app in the portal Suite) is used by both the workspace and
mentorship. It always produces a working join link, carries a cadence, and
turns transcripts into Otter-style minutes with **Gemini Flash**.

## Join links

**Google Meet is the only provider.** On schedule we create a real Google
Calendar event (`GoogleWorkspace::createMeetEvent`, `sendUpdates=all`), which:

- attaches a Google Meet link,
- adds the meeting to the organiser's + every attendee's Google Calendar,
- sends each of them a calendar **invite**, and
- carries the cadence as an RRULE for recurring meetings.

The organiser is always added to the invite list alongside the emails entered.
This needs the Workspace service account to have Calendar write access
(`GoogleWorkspace::calendarWriteEnabled()`). If Google isn't connected the
meeting is still saved but no link is created and the response carries a
`warning` telling the organiser to connect Google Workspace (the UI shows
"Meet link pending"). There is no non-Google fallback.

## AI minutes (Gemini Flash)

`Meetings::structure()` sends the transcript to Gemini Flash and stores a
summary, key points, decisions and assigned action items. It falls back to
OpenAI and then to the Anthropic bot, so whichever provider is configured is
the one used.

| Env | Default | Purpose |
|-----|---------|---------|
| `AV_GEMINI_API_KEY` (or `GEMINI_API_KEY`, `GOOGLE_AI_API_KEY`) | — | Enables Gemini |
| `AV_GEMINI_MODEL` | `gemini-2.0-flash` | Flash model id |
| `AV_GEMINI_BASE_URL` | Google endpoint | Gateway / test override |

Transcripts reach the system three ways: **paste**, **upload a recording**, or
**the recording bot** (below).

An uploaded recording goes to **Whisper** when `OPENAI_API_KEY` is set and to
Gemini otherwise. Whisper is preferred because it is a real multipart upload and
takes the ~25MB the API allows, where Gemini inlines the audio as base64 and
stops around 19MB — the difference between most meetings and short ones.

## The recording bot

**Optional.** The site works fully without a notetaker: meetings are scheduled,
invites go out, and minutes come from Google's own transcript, an uploaded
recording or pasted text. With no notetaker available the portal offers no
recording checkbox, no `🤖` badges and no “Add the AI” button.

When one is available, turn on **“Add the recording bot”** when scheduling — or
add it to a meeting that is already running, from the meeting card in the
portal. `Meetings::botProvider()` decides which:

1. `AV_MEET_BOT_PROVIDER=attendee` forces Attendee; `none` switches it off.
2. Otherwise Attendee when `AV_ATTENDEE_API_KEY` is set,
3. otherwise Google Meet's own transcription when Workspace Meet is connected,
4. otherwise nothing — `''`, and meetings carry on without a notetaker.

Attendee is the only third-party bot. Recall.ai and the custom `webhook` recorder
(`AV_MEET_BOT_JOIN_URL`) were retired; the old `recall_webhook` endpoint now
answers `410 Gone`.

### Adding and removing it on demand

`🤖 Add the AI` sends the notetaker into a meeting that was not scheduled with
recording; `Remove the AI` takes it back out. Both are open to **anyone in the
meeting**, not just the organiser — someone who wants a conversation off the
record should not have to find whoever booked the room first.

`Meetings::inviteBot()` is idempotent, so a double-tap cannot put two notetakers
in a room. `removeBot()` also clears `auto_record`, or the cron sweep would send
the bot straight back in.

### When the bot joins

The bot is always given a **join time** — the meeting start, pulled forward by
`meetings.bot_join_lead_min` (default 2 minutes) so it is present before the
first person, and clamped so it is never in the past (providers reject a past
join time outright). Without this a bot created for next Tuesday's meeting joins
an empty room the day it is scheduled and gives up long before anyone arrives.

Attendee has no dependable join-later field, so a meeting more than an hour out
is left `pending` and dispatched by the sweep instead.

### The sweep

`Meetings::dispatchDueBots()` runs on every `tasks/cron.php` tick. It picks up
meetings starting within the lead window whose bot state is empty, `pending` or
`error`, and looks back 30 minutes so a meeting that started between ticks is
still covered. This is what makes a **failed dispatch recoverable** — provider
down, key missing, Meet link not yet provisioned — instead of the bot silently
never arriving.

### Leadership controls (Studio → Rules & AI → Meetings)

Which backend joins is deployment configuration; *whether the organisation
records its meetings* is a leadership decision.

| Rule | Default | Controls |
|---|---|---|
| `meetings.ai_notetaker` | on | Master switch. Off, no bot can be sent by any route. |
| `meetings.bot_on_demand` | on | Whether participants can add it mid-meeting. |
| `meetings.bot_join_lead_min` | 2 | How many minutes early it joins. |
| `meetings.bot_announce` | on | Whether the invite says the meeting will be transcribed. |

`meetings.bot_announce` does **not** hide the bot when switched off — it appears
in the participant list by name regardless, and no setting conceals it. All the
switch removes is the advance notice, so people find out by noticing rather than
by being told. Leave it on.

### Bot states

`bot_state` on a meeting, as the portal renders it:

| State | Meaning |
|---|---|
| `pending` | Wanted, queued for the sweep to dispatch |
| `requested` | Sent to the provider; will join at its join time |
| `joining` / `in_call` | Live in the meeting |
| `native` | Google is transcribing the space itself; no bot to remove |
| `done` | Attended and finished |
| `removed` | Taken out by a participant |
| `error` | Dispatch failed — the sweep will retry |
| `unconfigured` | No provider wired; use Google's transcript or paste one |

### Which backend, and what it costs

| Backend | Cost | What it is |
|---|---|---|
| `google` | **Free** | Google Meet transcribes the call itself. No bot joins. Needs Workspace + the Meet scopes. **Start here** if you are already on Workspace. |
| `attendee` | **Free self-hosted** | An open-source bot you run yourself. You pay for a small always-on container and nothing per meeting to Attendee — the recogniser is a separate bill unless you use Meet's own captions. |

For an organisation already on Google Workspace, `google` costs nothing and is
already wired — use it. Reach for Attendee only when you need Zoom or Teams too,
or want the transcript without depending on Workspace scopes.

### Provider: `attendee` (open source, self-hostable)

[Attendee](https://github.com/attendee-labs/attendee) is a bot that joins Meet,
Zoom or Teams, records and transcribes. Self-hosted, Attendee itself costs
nothing per meeting.

It bundles no recogniser. Attendee either captures the meeting platform's own
closed captions — free, and lower quality — or forwards per-speaker audio to a
provider whose key you add in its dashboard (Deepgram, OpenAI, Gladia,
AssemblyAI), which bills you directly. Deploying it is
[docs/attendee-google-cloud.md](attendee-google-cloud.md).

| Env | Default | Purpose |
|-----|---------|---------|
| `AV_ATTENDEE_API_KEY` | — | Enables it |
| `AV_ATTENDEE_BASE_URL` | `https://app.attendee.dev` | **Set this to your own instance.** The default is the hosted service, which is billed per meeting — leaving it blank defeats the point. |
| `AV_ATTENDEE_BOT_NAME` | `Afrovanguard Notetaker` | Name shown in the meeting |

All three are editable in Studio → Rules & AI → Setup, with a Test button that
distinguishes "reachable" from "key rejected" from "instance not running".

Two behaviours worth knowing, both deliberate:

- **No public webhook is needed.** `Meetings::pollBotTranscripts()` runs on every
  cron tick and asks each bot in flight whether it has finished, then ingests.
  A site on shared hosting, behind a staging domain, or one that has just moved
  therefore needs no inbound callback at all. Mentorship sessions are polled
  the same way by `Mentorship::pollSessionBotTranscripts()`.
- **The bot is dispatched when the meeting starts**, not when it is scheduled.
  Attendee has no dependable join-later field across versions, so a meeting more
  than an hour out is left `pending` for the sweep — which is correct whatever
  the instance supports.

### Provider: `google` (Google Meet native)

The Calendar event makes the Meet; the site then switches that space's own
recording, transcript, Gemini notes and attendance report on, as the organiser
(`Meetings::configureCapture` → `GoogleWorkspace::configureMeetSpace`, scope
`meetings.space.settings`). After the call `MeetingLive::finish` reads the
conference as the organiser — participants with every session, the transcript
with speakers' names once Google has written it (it waits, retrying, up to a
day), and the Gemini notes. Google's live events can arrive through Pub/Sub
(`lib/MeetEvents.php`). See docs/google-workspace.md → "Meetings that run
themselves" for the one-time setup.

> The low-level **Google Meet Media API** (raw WebRTC media streams) needs an
> out-of-process media client and cannot run inside PHP. The `google` provider
> uses Google's own managed recording/transcription — the practical,
> server-drivable equivalent. Use `attendee` when you need a bot that captures
> raw media itself.

## Everybody sees every meeting; the organiser decides about the minutes (Oct 2026)

- **Visibility.** Every member and staff member (`LmsAuth::isOrgMember`)
  sees every meeting in the portal: when, where, who called it, who is
  invited, the agenda and the join link (`Meetings::listFor`, `canSee`). An
  Academy learner still sees only the meetings they are in.
- **Minutes.** Open to everybody who can see the meeting unless the organiser
  ticks **"Keep the minutes to the people invited"** (`minutes_private`), when
  the summary, decisions, action items and transcript are for the people in
  it and whoever may manage it (`canReadMinutes`). The meeting still shows,
  marked 🔒, so nobody reads it as unminuted.
- **Acting on a meeting** stays with the people in it: adding a transcript,
  ticking the agenda (`MeetingClock::setResolved` now asks `isParticipant`),
  the notetaker. Changing or cancelling one is the organiser's (`mayManage`).
- **Meetings has its own menu entry** (Work → Meetings). It opens the Suite's
  meetings app through the router alias `meetings → tools` in `portal/avp.js`.

### CACENTRE staff

CACENTRE's Staff Office has a **Meetings** page (`cac/staff/meetings.php`).
It lists every meeting and schedules and cancels the staff member's own,
through one signed door here, `integrations/cacentre-meetings.php`
(`lib/CacMeetings.php`). The trust is the existing bridge secret,
`AV_SSO_SECRET`, in the v1 token format, issued by CACENTRE. Each token is for
the `meetings` door, names the staff member, is bound to the request body
(`bh`), and its nonce is spent once. A staff member with an account here (by
email) schedules as that account. One without an account is recorded as the
organiser by name and email (`organiser_name`, `origin = cacentre`). They can
cancel, or change the minutes' privacy of, only what they organised.
Tests: `tests/cacmeetings.test.php`.
