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

Turn on **“Add the recording bot”** when scheduling — or add it to a meeting
that is already running, from the meeting card in the portal. The provider is
chosen by `AV_MEET_BOT_PROVIDER` (`attendee` | `recall` | `google` | `webhook` |
`none`), or auto-detected preferring the free options — see the cost table below.

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

A custom `webhook` worker cannot be told to join later, so a meeting more than an
hour out is left `pending` and dispatched by the sweep instead.

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
| `attendee` | **Free self-hosted** | An open-source bot you run yourself. Whisper bundled. You pay for a small always-on container and nothing per meeting. |
| `webhook` | **Free self-hosted** | Your own recorder, any implementation. |
| `recall` | **Per meeting-hour** | Hosted, no infrastructure to run, broadest platform support. |

Auto-detection prefers the free options: Attendee before Recall when both are
configured, and Google native when neither bot is. An explicit
`AV_MEET_BOT_PROVIDER` always wins over that preference.

The honest summary: for an organisation already on Google Workspace, `google`
costs nothing and is already wired — use it. Reach for a bot only when you need
Zoom or Teams too, or want the transcript without depending on Workspace scopes.
Between the bots, Attendee self-hosted is free where Recall is metered, and
Recall is the one to pick if you would rather pay than operate a container.

### Provider: `attendee` (open source, self-hostable)

[Attendee](https://github.com/attendee-labs/attendee) is a bot that joins Meet,
Zoom or Teams, records and transcribes with Whisper built in. Self-hosted it
costs nothing per meeting.

| Env | Default | Purpose |
|-----|---------|---------|
| `AV_ATTENDEE_API_KEY` | — | Enables it |
| `AV_ATTENDEE_BASE_URL` | `https://app.attendee.dev` | **Set this to your own instance.** The default is the hosted service, which is billed per meeting — leaving it blank defeats the point. |
| `AV_ATTENDEE_BOT_NAME` | `Afrovanguard Notetaker` | Name shown in the meeting |

All three are editable in Studio → Rules & AI → Setup, with a Test button that
distinguishes "reachable" from "key rejected" from "instance not running".

Two behaviours differ from Recall, both deliberate:

- **No public webhook is needed.** `Meetings::pollBotTranscripts()` runs on every
  cron tick and asks each bot in flight whether it has finished, then ingests.
  A site on shared hosting, behind a staging domain, or one that has just moved
  therefore needs no inbound callback at all. (Recall bots are polled the same
  way, so its webhook is now a speed-up rather than a requirement.)
- **The bot is dispatched when the meeting starts**, not when it is scheduled.
  Attendee has no dependable join-later field across versions, so a meeting more
  than an hour out is left `pending` for the sweep — which is correct whatever
  the instance supports.

### Provider: `recall` (Recall.ai)

A hosted bot joins the meeting, records and transcribes it.

| Env | Default | Purpose |
|-----|---------|---------|
| `AV_RECALL_API_KEY` | — | Enables Recall.ai |
| `AV_RECALL_REGION` | `us-west-2` | API region host prefix |
| `AV_RECALL_BOT_NAME` | `Afrovanguard Notetaker` | Name shown in the meeting |
| `AV_RECALL_WEBHOOK_TOKEN` | — | Shared token guarding the webhook |
| `AV_RECALL_BOT_CONFIG` | — | Optional JSON merged into the create-bot body |

Flow: on schedule we `POST {region}.recall.ai/api/v1/bot/` with the meeting URL
and a realtime webhook pointing at
`SITE_URL/portal/meetings.php?action=recall_webhook&t=<AV_RECALL_WEBHOOK_TOKEN>`.
When Recall reports a terminal status we fetch the transcript
(`GET /bot/{id}/transcript/`), store it, and structure it with Gemini. The bot
id is saved on the meeting (`bot_ref`), so “Pull transcript” also works on
demand. Point Recall's dashboard webhook at the same URL for redundancy.

### Provider: `google` (Google Meet native)

We create a Meet **space** with Google's own auto-transcription (and recording)
turned on via the Meet REST API (`POST /v2/spaces` with
`config.artifactConfig.transcriptionConfig.autoTranscriptionGeneration = ON`),
impersonating the organiser. Google records/transcribes the call; afterwards
`GoogleWorkspace::meetTranscriptText()` reads the transcript
(`conferenceRecords → transcripts → entries`), with the Drive transcript Doc as
a fallback. Requires the service account to have domain-wide delegation for the
`meetings.space.created` and `meetings.space.readonly` scopes.

> The low-level **Google Meet Media API** (raw WebRTC media streams) needs an
> out-of-process media client and cannot run inside PHP. The `google` provider
> uses Google's own managed recording/transcription — the practical,
> server-drivable equivalent. Use `recall` when you need a bot that captures
> raw media itself.

### Provider: `webhook` (bring your own recorder)

Set `AV_MEET_BOT_JOIN_URL`. On schedule we POST:

```json
{ "meeting_id": 123, "join_url": "https://…",
  "callback": "SITE_URL/portal/meetings.php?action=bot_ingest",
  "token": "<hmac>" }
```

Your worker joins, records, and when done POSTs the transcript back:

```
POST SITE_URL/portal/meetings.php?action=bot_ingest
{ "meeting_id": 123, "token": "<hmac from above>", "transcript": "Speaker 1: …" }
```

The `token` is `hash_hmac('sha256', "bot|<meeting_id>", <app secret>)`
(`Meetings::botToken()`), so only your worker can post minutes for a meeting.
`bot_ingest` and `recall_webhook` are service endpoints — no user session — and
are rejected without a valid token.

#### Free / open-source self-hosted bots

The `webhook` provider is the drop-in point for a **free, self-hosted** recorder
instead of a paid API. No app code changes — you host the bot, we hand it the
meeting and receive the transcript. Trade-off: the software is free, but a bot
that joins a live call must run somewhere (a container with headless Chrome +
audio capture), so you operate a small always-on worker.

Known open-source options (each exposes a "send a bot to this meeting" API):

- **Attendee** — <https://github.com/attendee-labs/attendee> — meeting-bot API
  for Meet/Zoom/Teams; positioned as an open-source Recall alternative.
- **Vexa** — <https://vexa.ai> — self-hostable real-time transcription with
  join-bots for Meet/Teams.
- **DIY** — a Playwright/headless-Chromium bot that joins the Meet, captures
  audio via a virtual mic (PulseAudio), and transcribes with Whisper or by
  posting the audio to Gemini.

**Wiring (any of the above):**

1. Stand up the bot; note its "join a meeting" HTTP endpoint.
2. Set `AV_MEET_BOT_PROVIDER=webhook` and `AV_MEET_BOT_JOIN_URL=<that endpoint>`.
3. On schedule we POST the `{meeting_id, join_url, callback, token}` body above.
   Map those fields to your bot's expected shape with a thin adapter if needed
   (e.g. a tiny function that receives our POST and calls Attendee's
   `POST /bots` with `{meeting_url: join_url, webhook: callback}`).
4. When the bot finishes, have it POST the transcript to the `callback`
   (`bot_ingest`) with the same `token`. That's the only contract we require —
   `{ "meeting_id", "token", "transcript" }`.

Because the contract is just "POST the transcript text back with the token,"
any recorder — open-source, DIY, or a future paid one — works without touching
the app. Gemini Flash then produces the minutes from whatever transcript arrives.
