# Team Chat — feature roadmap & what makes ours different

A running brainstorm of productivity-chat features, what we've already shipped,
and where we can go to be genuinely best-in-class rather than "another Slack."

## Shipped

- **Channels** (General / Announcements / Mentorship / Random) with per-channel
  topics, unread dots, and an unread badge on the sidebar nav.
- **Near-realtime** message stream via `sinceId` polling (fast while open, a
  slow background check otherwise) — no realtime server needed.
- **Threads** (reply to a message) with live-polled replies.
- **@mentions** with autocomplete in both the main and thread composers →
  in-app notification **and** email to the tagged member.
- **Emoji reactions** on any message.
- **Presence** + a **members rail** (mentors vs members, live dots).
- **Code blocks** (```fenced```) and `inline code`.
- **Typing indicators**.
- **"Catch me up"** — an AI recap of a channel (TL;DR / decisions / open
  questions / action items) using whichever AI key is set.
- **Assign-as-task from a message** — the mention→task bridge (emails the
  assignee); tasks flow into the org task pool and calendar.

## What makes ours distinctive (lean into these)

These tie chat to the rest of the portal — the moat a standalone chat app can't
easily copy:

1. **Chat → Task → Calendar as one loop.** A message becomes a pooled/assigned
   task, which shows on the Today calendar and drives deadline reminders. Double
   down: "turn thread into a checklist", "schedule this as a meeting" from a
   message.
2. **AI that knows the org.** "Catch me up", goal→task planning, and (next)
   "@Afrovanguard" in-channel answers grounded in `AiKnowledge` — an assistant
   that actually knows the programmes, not a generic bot.
3. **Mentorship-native.** Mentor badges, a #mentorship channel, and links to
   sessions/Meet. Next: "book a session" and "log hours" inline.
4. **Levels / XP & recognition.** The community already has levels; surface them
   in chat (kudos, streaks, "give XP") to reinforce service and initiative.
5. **Email-first reach.** Everything important also emails, so members who don't
   live in the app still get pulled in — right for a volunteer community.

## Near-term backlog (high value, low risk)

- **Saved items / bookmarks** — star a message; a "Saved" view (there is already
  a `Bookmarks` lib to build on).
- **Pinned messages / channel brief** — a pinned banner per channel (the design
  shows this) for the week's challenge or house rules.
- **Message edit & delete** (author, with an "edited" marker; soft-delete).
- **Slash commands** — `/task`, `/poll`, `/meet`, `/remind`, `/gif`, `/shrug`.
- **Link & repo unfurling** — GitHub/Drive/YouTube preview cards.
- **File & image attachments** — via Cloudinary/Drive (already wired for uploads).
- **Full-text search** across messages (the design has a search box).
- **@here / @channel** and **per-channel mute / notification prefs**.
- **Read receipts / "seen by"** on important posts.
- **Scheduled messages** and **reminders on a message** ("remind me Monday").

## Bigger bets (differentiators)

- **AI thread summaries & auto-action-items** — every long thread gets a one-tap
  summary and extracted tasks (assignee-suggested).
- **"Standups in chat"** — a bot prompts, collects, and posts a digest; feeds the
  existing Standup tool.
- **Huddles** — one-click ephemeral Google Meet for a channel (we already
  provision Meet links).
- **Knowledge capture** — mark a message as an "answer" → it becomes an FAQ entry
  in `AiKnowledge`, so the bot gets smarter from real conversations.
- **Voice notes** with AI transcription (Gemini transcription already exists for
  meetings).
- **Moderation & safety** — AI flagging + the existing clearance/classification
  model, important for a youth community.

## Technical notes for whoever picks this up

- Chat backend lives in `lib/Community.php` (`chat*` methods) on the portable
  SQLite/MySQL/Postgres layer; the API is `portal/chat.php`; the UI is the
  `#teamChat` IIFE + `.tc-*` styles in `portal/index.php` / `portal/portal.css`.
- Everything is polling-based and degrades gracefully — keep new features on the
  same "cheap poll returns the delta" pattern rather than requiring a socket
  server, so it stays deployable on shared hosting.
- AI features use `AvBot` (Claude) with a `Gemini` fallback — reuse
  `Community::chatAiAvailable()` / the pattern in `chatRecap()`.
