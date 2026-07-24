# Set up the Afrovanguard web-cron on cPanel (no SSH)

The portal's periodic jobs — due reminders, **task-deadline nudges**, webhook
retries, birthday emails — run whenever something hits `tasks/cron.php`. On
shared hosting with no SSH you add that in **cPanel → Cron Jobs**. This folder
has a script that does it for you through the browser.

## What it schedules

```
*/5 * * * *   curl -fsS --max-time 60 "https://your-site/tasks/cron.php?key=YOUR_KEY" >/dev/null 2>&1
```

`YOUR_KEY` must match the server's `AV_CRON_KEY` (or, if that isn't set,
`AV_ADMIN_TOKEN`). See the header of `tasks/cron.php`.

## Automatic (browser) — `cpanel-cron-setup.mjs`

Runs on **your** machine, logs into cPanel in a real browser, then uses
cPanel's own API from inside that session (so it works across cPanel themes and
won't create a duplicate).

```bash
# one-time
npm i playwright

# create the cron (password is prompted, or set CPANEL_PASS)
node tools/cpanel-cron-setup.mjs \
  --cpanel https://yourhost.com:2083 \
  --user   your_cpanel_user \
  --site   https://afrovanguard.org.ng \
  --key    YOUR_AV_CRON_KEY
```

Useful flags:

| flag | meaning |
|------|---------|
| `--dry-run` | log in, list existing cron jobs, print what it *would* add + save a screenshot — changes nothing |
| `--schedule "*/10 * * * *"` | custom cadence (default every 5 min) |
| `--otp 123456` | 2FA code (accounts with 2FA) |
| `--headful` | show the browser |
| `--wget` | use `wget` instead of `curl` |

Requirements: `--cpanel` must be the **direct** cPanel login (e.g.
`https://host:2083` or `https://cpanel.yourdomain.com`), not a hosting-provider
SSO dashboard. Credentials live only in memory for the run — never written or
logged.

## Alternative — cPanel API token (also no SSH, no browser)

If you'd rather not automate the browser: in cPanel create an **API token**
(*Security → Manage API Tokens*), then one call adds the cron:

```bash
curl -H "Authorization: cpanel USER:APITOKEN" \
  "https://host:2083/execute/Cron/add_line" \
  --data-urlencode 'command=curl -fsS --max-time 60 "https://your-site/tasks/cron.php?key=YOUR_KEY" >/dev/null 2>&1' \
  --data 'minute=*/5' --data 'hour=*' --data 'day=*' --data 'month=*' --data 'weekday=*'
```

## Manual (fallback)

cPanel → **Cron Jobs** → *Add New Cron Job* → set "Common Settings" to
*Every 5 Minutes* → paste the command above → **Add New Cron Job**.
