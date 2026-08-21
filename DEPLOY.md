# Deploying / migrating the Afrovanguard site

The app is deliberately host-agnostic: **plain PHP + Apache**, all configuration
via **environment variables**, and a database layer that speaks **SQLite, MySQL,
or PostgreSQL** with the same code. That means you can run it on cPanel today and
move to Google Cloud, AWS, Azure, Render, Fly.io, or a bare VM tomorrow with **no
code changes** — only config. This guide covers the moves.

There is a `Dockerfile`, `docker-compose.yml`, a `deploy/docker-entrypoint.sh`,
and a `/health.php` probe to make that portable.

---

## 1. How configuration works (read this first)

Nothing is hard-coded. On boot, `lib/bootstrap.php`:

- loads `config.php` **only if** its payment/SMTP secrets are present, otherwise
- falls back to **environment variables** for everything.

So on a cloud host you set env vars and never ship `config.php`. The important
ones (full list in `config.example.php`):

| Purpose | Env vars |
|---|---|
| App | `SITE_URL`, `APP_KEY` (32+ random chars), `AV_ORG_DOMAIN` |
| Email | `SMTP_HOST`, `SMTP_USERNAME`, `AV_SMTP_PASSWORD`, `SMTP_SECURE`, `AV_RESEND_KEY` (HTTPS fallback) |
| AI (task planner + "Catch me up") | `ANTHROPIC_API_KEY` **or** `AV_GEMINI_API_KEY` |
| Payments | `AV_PAYSTACK_PK`, `AV_PAYSTACK_SK` |
| Web-cron | `AV_CRON_KEY` |
| Database | see below |

### Database — the same code, any engine

`lib/Database.php` auto-selects a driver and **provisions + migrates the schema
on first request** (idempotent, from `db/schema.sql`) — no manual migration step.

| You want | Set |
|---|---|
| SQLite (default; single instance + a persistent disk) | nothing — a file is created at `AV_DB_PATH` (default `db/diary.sqlite`) |
| MySQL | `AV_DB_HOST`, `AV_DB_NAME`, `AV_DB_USER`, `AV_DB_PASS` (+ `AV_DB_PORT`) |
| PostgreSQL | `AV_DB_DRIVER=pgsql` + the same `AV_DB_*` vars, or `AV_DB_DSN=pgsql:host=…` |
| A full DSN | `AV_DB_DSN=mysql:host=…;dbname=…` |
| Refuse to silently fall back to SQLite | `AV_DB_STRICT=1` (recommended in the cloud) |

> **Serverless note:** Cloud Run / App Runner / App Engine have an ephemeral,
> often read-only filesystem, so **SQLite won't persist** there — use a managed
> MySQL/Postgres (Cloud SQL, RDS) and set `AV_DB_*` + `AV_DB_STRICT=1`.

### File uploads

Images already go to **Cloudinary** and documents to **Google Drive** when
configured (`CLOUDINARY_*`, `AV_GDRIVE_*`) — the right choice on stateless hosts,
since the local `uploads/` folder doesn't persist there. On a VM, the local
folder is fine.

---

## 2. Local / any VM (Docker)

```bash
docker compose up --build         # → http://localhost:8080
```

Set real secrets via env (compose reads `APP_KEY` etc. from your shell or a
`.env` file). To run against MySQL locally, uncomment the `db` service and the
`AV_DB_*` vars in `docker-compose.yml`.

On a plain VM without Docker: it's a normal PHP app — point Apache/nginx+php-fpm
at the repo root, ensure `mod_rewrite`/`.htaccess` is honoured, make `db/` and
`uploads/` writable, and set the env vars.

---

## 3. Google Cloud

### Cloud Run (recommended — serverless containers)

```bash
gcloud run deploy afrovanguard \
  --source . \
  --region europe-west1 \
  --allow-unauthenticated \
  --set-env-vars "SITE_URL=https://YOUR_DOMAIN,APP_KEY=xxxxx,AV_ORG_DOMAIN=afrovanguard.org.ng,AV_DB_STRICT=1" \
  --set-env-vars "AV_DB_HOST=/cloudsql/PROJECT:REGION:INSTANCE,AV_DB_NAME=afrovanguard,AV_DB_USER=afro,AV_DB_PASS=…" \
  --add-cloudsql-instances PROJECT:REGION:INSTANCE
```

- Cloud Run injects `$PORT`; the entrypoint binds Apache to it automatically.
- Use **Cloud SQL (MySQL/Postgres)** for the DB — put secrets in **Secret
  Manager** and reference them with `--set-secrets` instead of `--set-env-vars`.
- **Cron:** create a **Cloud Scheduler** job hitting
  `https://YOUR_DOMAIN/tasks/cron.php?key=$AV_CRON_KEY` every 5 minutes.
- Health check: `/health.php` (Cloud Run uses the container `HEALTHCHECK`).

### App Engine Flex

Works too (it's a container). Provide an `app.yaml` with `env: flex`,
`runtime: custom`, your env vars, and a `manual_scaling`/`automatic_scaling`
block; use Cloud SQL + Cloud Scheduler as above.

---

## 4. AWS

- **Lightsail Containers / App Runner** — push the image, set the same env vars,
  point at **RDS (MySQL/Postgres)**. App Runner injects `$PORT` (handled). Health
  check path: `/health.php`.
- **Elastic Beanstalk** — "Docker running on 64bit Amazon Linux 2"; commit the
  `Dockerfile`, set env vars in the environment config, attach an RDS instance,
  and add an **EventBridge Scheduler** rule to curl `/tasks/cron.php?key=…`.
- **ECS/Fargate** — task definition from the image, env from **SSM Parameter
  Store / Secrets Manager**, RDS for the DB, an ALB target group health check on
  `/health.php`, and an EventBridge schedule for the cron.
- **EC2 / Lightsail VM** — treat it like any VM (§2): LAMP or Docker.

---

## 5. Other hosts

- **Azure** — Web App for Containers (or Container Apps) + Azure Database for
  MySQL; `$PORT` is provided; Logic Apps/Functions timer for the cron.
- **Render / Railway / Fly.io / DigitalOcean App Platform** — deploy the
  `Dockerfile`, add a managed MySQL/Postgres, set env vars, and add the platform's
  scheduled-job feature for `/tasks/cron.php`.
- **Shared cPanel (no SSH)** — no container needed; upload the files and use
  `tools/cpanel-cron-setup.mjs` (or cPanel → Cron Jobs) for the cron. See
  `tools/cpanel-cron-setup.md`.

---

## 6. Migration checklist (cPanel → cloud, or between clouds)

1. **Provision a managed DB** (Cloud SQL / RDS) and set `AV_DB_*` + `AV_DB_STRICT=1`.
2. **Move the data.** From SQLite, export and import into MySQL/Postgres (e.g.
   `sqlite3 db/diary.sqlite .dump` → adapt → import), or `mysqldump` between
   MySQL instances. The schema also auto-creates on first boot, so an empty DB is
   a valid (fresh) start.
3. **Set env vars** for app/email/AI/payments/cron (§1). Put secrets in the
   platform's secret manager, not plain env, in production.
4. **Point uploads at Cloudinary/Drive** (`CLOUDINARY_*`, `AV_GDRIVE_*`) on
   stateless hosts.
5. **Recreate the cron** as a platform scheduled job hitting
   `/tasks/cron.php?key=$AV_CRON_KEY` (reminders, task-deadline nudges, webhook
   retries, birthdays).
6. **Verify** `GET /health.php` returns `{"ok":true,"db":"mysql"}`, then send a
   test email from the Studio and load `/portal/`.
7. **Point DNS** at the new host; keep `SITE_URL` in sync.

Nothing in the application code needs to change for any of the above — it's all
configuration.

## The Google Chat task bot

The bot turns "Bode to submit the revised STS report by Friday", said in a Chat
space, into a tracked commitment on the same board as meeting minutes.

**In Google Cloud**, create a Chat app (APIs & Services → Google Chat API →
Configuration):

| Field | Value |
|---|---|
| App name | Afrovanguard Task Bot |
| Functionality | Receive 1:1 messages **and** join spaces and group conversations |
| Connection settings | **HTTP endpoint URL** → `https://your-domain/webhooks/chat` |
| Visibility | your Workspace domain |

**In the Studio** → System → *Chat task bot*, set `AV_CHAT_AUDIENCE` to the
Cloud project number shown on the API page (or the custom audience you
configured on the app). This is not optional: without it the endpoint refuses
every request rather than accepting one it cannot verify. Google signs each
event as `chat@system.gserviceaccount.com`, and the audience claim is what stops
a token minted for somebody else's Chat app being replayed at yours.

Then add the app to a space and say `help`.

**What it will and will not do.** It records the task either way, and only
assigns it when the name matches exactly one member — two people called Ada
means nobody is assigned and it says which two. The sender must also match a
member account: a valid Google token proves the request came from Chat, not that
the person behind it is one of yours.

Optionally restrict it to named spaces with the `chat.allowed_spaces` rule
(Rules & AI). Blank means any space it has been added to, which is usually
right, because the sender check is the substantive gate.

## AI provider routing

Which model answers which kind of work is three rules in Rules & AI, not a
constant in the code:

| Rule | Default | Covers |
|---|---|---|
| `ai.route_reason` | `openai,anthropic,gemini,groq` | briefs, promotion reviews, agenda drafts, minutes |
| `ai.route_bulk` | `groq,gemini,openai,anthropic` | reminder wording, catch-ups, classifications |
| `ai.route_tools` | `openai,anthropic,gemini` | the assistant that looks things up for itself |

Each is tried in turn until one answers. A provider with no API key is skipped,
so listing one costs nothing. Only the three native providers have a tool loop
implemented, so naming a support-tier endpoint in `ai.route_tools` has no
effect — the router will offer it and `AvAgent` will decline it.

The support tier (`GROQ_API_KEY` and friends, under System → *Support tier*) is
any OpenAI-compatible endpoint: Groq, OpenRouter, Together, DeepSeek, Cerebras,
or a model you host yourself. Base URLs are stable; **model names are not** —
these vendors retire ids faster than the frontier labs, and a sudden run of
failures on one provider is almost always that. Set `AV_<VENDOR>_MODEL` to fix
it. The AI Ops board shows which model each provider is actually using.

`AV_AGENT_PROVIDER` still works as an override: it promotes one provider to the
front of every route without discarding the fallbacks the rules declared.
Useful for pinning a provider while you diagnose another.

## AI Ops

Studio → **AI Ops**. Every AI feature runs on the cron tick and is silent by
design, so a scheduler that stopped and a quiet week produce identical output
everywhere else. This board is where they differ: a per-task heartbeat, model
spend and failure rates per provider, and what the features produced against
what anybody acted on.

It leads with alerts and shows an all-clear banner when there are none. **Run
tasks now** runs the same self-limiting sweeps cron runs, which answers the
question that always follows a dead heartbeat — whether the tasks themselves
still work — without shell access.

Cost is reported in tokens, not money: converting needs a per-model price list
that changes without notice, and a confident wrong figure is worse than the
number we actually know.
