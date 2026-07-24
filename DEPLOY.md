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
