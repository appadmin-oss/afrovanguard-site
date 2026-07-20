# Meetings — scheduling, transcripts & the recording bot

The standardized meeting system (`lib/Meetings.php`, `portal/meetings.php`, the
**Meetings** app in the portal Suite) is used by both the workspace and
mentorship. It always produces a working join link, carries a cadence, and
turns transcripts into Otter-style minutes with **Gemini Flash**.

## Join links

On schedule a link is provisioned in this order:

1. **Google Meet** — when the Workspace service account can write Calendar
   (`GoogleWorkspace::calendarWriteEnabled()`), a real Meet event is created,
   attendees invited, and the cadence attached as an RRULE.
2. **Built-in room** — otherwise a stable Jitsi room
   (`https://meet.jit.si/Afrovanguard-…`), overridable via `AV_MEET_ROOM_BASE`.

Scheduling never dead-ends: some link is always attached (mentorship sessions
included — `Mentorship::addSession` uses the same fallback).

## AI minutes (Gemini Flash)

`Meetings::structure()` sends the transcript to Gemini Flash and stores a
summary, key points, decisions and assigned action items. Falls back to the
Anthropic bot if Gemini isn't configured.

| Env | Default | Purpose |
|-----|---------|---------|
| `AV_GEMINI_API_KEY` (or `GEMINI_API_KEY`, `GOOGLE_AI_API_KEY`) | — | Enables Gemini |
| `AV_GEMINI_MODEL` | `gemini-2.0-flash` | Flash model id |
| `AV_GEMINI_BASE_URL` | Google endpoint | Gateway / test override |

Transcripts reach the system three ways: **paste**, **upload a recording**
(Gemini Flash transcribes the audio), or **the recording bot** (below).

## The recording bot

Turn on **“Add the recording bot”** when scheduling. The provider is chosen by
`AV_MEET_BOT_PROVIDER` (`recall` | `google` | `webhook` | `none`), or
auto-detected: Recall.ai → custom webhook worker → Google native.

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
