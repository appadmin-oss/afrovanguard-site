# HANDOFF — Google Workspace still reports "not configured"

Working memory for whoever picks this up next. Written 2026-10-21.

**Branch:** `claude/finme-dues-training-fees-l54o5j` — everything below is
pushed there. Latest relevant commit at time of writing: `3045ff0`.

**Status:** NOT solved. The reporting around it has been fixed (three times,
in three places). The underlying "Workspace is not configured" on the live
site has not been diagnosed, because it has never been reproduced here.

---

## 0. The symptom, in the operator's words

> "The test does not say anything and the website says it is not configured."

Two distinct observations, and they may have two different causes:

1. **The test says nothing** — pressing *Test Google Workspace* in
   Studio → Rules & AI → Setup produces no visible result.
2. **The site says Workspace is not configured** — the Studio banner, the
   System health row, and the portal's Workspace view all report it unset.

Earlier in the same session the operator confirmed **Test Google sign-in
passes** ("Google accepted the client ID and secret"). That is a *different
credential*: sign-in uses the OAuth client ID/secret, Workspace uses a
**service-account JSON key**. A green sign-in says nothing about Workspace.

---

## 1. READ THIS FIRST — the deployed code is probably not current

Strong evidence the live site is running code older than this branch's head:

- On 2026-10-21 the operator hit
  `Schema step failed: … 1061 Duplicate key name 'idx_articles_published'`
  from the migration screen. That string is produced by the **old one-shot**
  `db_migrate` action in `admin/api.php`. The rewrite on this branch points
  the "Migrate now" button at `db_job_begin` instead, which cannot produce it.
- `admin/app.js` is cache-busted (`?v=<?= filemtime(...) ?>` in
  `admin/index.php:1627`), so a stale browser cache is **not** the explanation.

**Therefore: before investigating anything below, establish which commit is
actually on the server.** Everything in §3 was written after the operator's
reports, so "it still does not work" may simply mean "the fix is not deployed".

A cheap way to tell: the Workspace test now gives a *reason* rather than a
blank, and System → health has a **"Stored secrets"** row that did not exist
before. If neither is present, the server is on older code — redeploy first
and re-read the symptom.

---

## 2. What the pieces are

| Thing | Where |
|---|---|
| Is it configured? | `GoogleWorkspace::configured()` → `credentials() !== null` |
| Why not? | `GoogleWorkspace::whyNotConfigured()` |
| The credential | `AV_GDRIVE_SERVICE_ACCOUNT`, an **encrypted** setting (`secret => true`, `type => json`) in `AvSettings::DEFS` (~line 121) |
| Where it is stored | `av_settings` table, column `value`, `is_secret = 1`, encrypted with `av_secret()` |
| The test button | `admin/index.php` → `setup_test` in `admin/api.php:757` → `AvSettings::test('google_workspace')` → `AvSettings::testGoogleWorkspace()` → `GoogleWorkspace::probe()` |
| The probe | `GoogleWorkspace::probe()` → token + 4 API reads + `scopeCheck()` over 11 scopes |

`credentials()` returns null — and so `configured()` is false — when **any** of
these is true:

```php
$v = Config::get('AV_GDRIVE_SERVICE_ACCOUNT', '');   // '' → null
$json = str_starts_with(ltrim($v), '{') ? $v : (is_file($v) ? file_get_contents($v) : '');
$c = json_decode($json, true);
return (is_array($c) && !empty($c['client_email']) && !empty($c['private_key'])) ? $c : null;
```

- nothing stored
- stored but **undecryptable** (APP_KEY changed / site moved) — the value never
  reaches `credentials()` at all; `AvSettings::all()` skips it and records it in
  `AvSettings::unreadable()`
- stored as a **path** to a file the web user cannot read
- stored but not valid JSON, or JSON missing `client_email` / `private_key`

`whyNotConfigured()` distinguishes all four. If the live site shows the generic
"Google Workspace is not set up on this site: an administrator adds the service
account key…", that is the **last** branch — meaning `Config::get()` returned
an empty string: *nothing is stored, or what is stored could not be decrypted
and was skipped*.

---

## 3. What has already been fixed (do not redo)

All pushed on this branch. These changed **the reporting**, not the underlying
configuration.

| Commit | What |
|---|---|
| `d6a9690` | `AvSettings` learned `unreadable()` / `cryptoStatus()`; `GoogleWorkspace::whyNotConfigured()` added; Studio banner + per-field `.setup-bad` notice |
| `3ce31b1` | Extracted `AvSettings::whyUnreadable()` so every integration shares one answer; `AppsScriptMail::whyNotConfigured()`; System health's Workspace row stopped calling the bare boolean; added the **"Stored secrets"** health row that names every key that cannot be decrypted |
| `592420b` | `probe()` is time-budgeted (`probeBudget()`, 60% of `max_execution_time`, clamped 5–20s); `http()` got `CURLOPT_CONNECTTIMEOUT`; `scopeCheck()` marks unreached scopes `SCOPE_UNCHECKED` instead of "refused"; the test button gives up at 45s instead of hanging; `testGoogleWorkspace()`'s "not configured" branch now returns `whyNotConfigured()` instead of "Paste the service account key first" |

Tests: `tests/gwssetup.test.php`, `tests/mailsetup.test.php`,
`tests/wsprobe.test.php`. Full suite green at **5739 assertions / 126 files**.

### Why the test used to "say nothing" — already fixed, verify it is deployed

`probe()` makes **16 sequential network calls** (1 token + 4 API reads + 11
scope token-exchanges — a different scope each time, so the per-scope token
cache never hits). `http()` used `CURLOPT_TIMEOUT = 15` with **no connect
timeout**, so a host that accepts a connection and never answers cost 15s each:
**up to ~240s for one button press**, against a shared host that kills the
request at 30s. PHP was killed mid-flight; the browser got nothing.

Measured here against a socket listened on but never `accept()`ed (TCP
handshake completes, TLS hangs — the real failure, not a fast refusal):

```
budget 8s -> probe took 8.0s · scopes: 11 unchecked, 0 refused
```

**If the test still says nothing on current code, that is a NEW finding** and
the next step is §4.1.

---

## 4. Ranked hypotheses, cheapest first

Four tempting theories were investigated and **disproven** before writing this.
They are in §6 so nobody spends the afternoon on them again.

### 4.1 The deployed code is older than this branch — CHECK THIS FIRST

See §1. There is direct evidence for it (the `1061` error came from a code path
this branch no longer calls). Until the server is known to be on `3045ff0` or
later, "it still does not work" is not yet a finding.

**Check:** System → health. Current code has a **"Stored secrets"** row in the
Runtime group, and the Workspace row gives a *reason* rather than
"not set — portal uses the launchpad + embeds". Neither existed before.

### 4.2 The key is stored but cannot be decrypted

The exact failure `d6a9690` was written for, and it fits the symptom precisely:
the value never reaches `credentials()`, so `configured()` is false and the
generic "not set up" sentence is what gets printed.

**Check, in order:**

1. System → health → **"Stored secrets"**. It names every key in
   `AvSettings::unreadable()`. If `AV_GDRIVE_SERVICE_ACCOUNT` is listed, this
   is the bug and the remedy is to paste the key again.
2. `error_log` for `[settings] cannot decrypt AV_GDRIVE_SERVICE_ACCOUNT — skipping`.
3. Directly:
   `SELECT setting_key, length(value), is_secret FROM av_settings WHERE setting_key = 'AV_GDRIVE_SERVICE_ACCOUNT';`
   A row with `is_secret = 1` and a healthy length, while the site says "not
   set up", **is** this case.

Causes: `APP_KEY` changed, was added where there was none, or the site moved
host. Note `cryptoStatus()` reports `weak` when there is no `APP_KEY` at all —
the site then encrypts with a key kept in the database beside the value, which
works but means any restore of one without the other loses it.

### 4.3 It was never stored in the first place

If the query in 4.2 returns **no row**, nothing was ever saved. The save path
refuses loudly rather than silently (see §6), so look for:

- an error shown next to the field at save time that was missed;
- the key pasted into the wrong field (the OAuth client secret and the service
  account key sit in the same group);
- `AV_GDRIVE_SERVICE_ACCOUNT` set in `config.php` or the environment instead —
  `Config::get()` reads those too, and a value there shows the source badge as
  "from config.php" / "from the environment" rather than "set here". If it is
  set there but malformed, `whyNotConfigured()` reports which.

### 4.4 The host's OpenSSL cannot read the key

`validateServiceAccount()` rejects the paste when
`openssl_pkey_get_private($pk) === false`, with "The key's private_key cannot
be read. Download a fresh JSON key and paste it unedited."

This is a **visible** error, so the operator should have seen it — but it is a
genuine shared-hosting failure mode (an old OpenSSL against a modern PKCS#8
key). If the operator reports that message, this is it, and the answer is a
fresh key or a PHP/OpenSSL upgrade, not anything in this codebase.

### 4.5 Outbound HTTPS is blocked on the host

This would **not** cause "not configured" — that is decided before any network
call — but it is the other half of "the test says nothing" on old code, and it
would make every probe fail on new code too (with a reason, now).

**Check:** `error_log` for `[workspace] transport:` lines, or a one-off page
doing `curl https://oauth2.googleapis.com/token`. In this container the proxy
reached Google and the probe came back in 2.7s, so the code path is sound.

## 4b. The first thing to run — a read-only probe on the live site

Nobody investigating this can see production, which is why it is still open.
Drop this somewhere web-reachable, open it once, **delete it**. It writes
nothing and prints no secret — only lengths, types and reasons.

```php
<?php // wsdiag.php — DELETE AFTER USE
require __DIR__ . '/lib/bootstrap.php';
if (!function_exists('av_admin_role') || av_admin_role() !== 'superadmin') { http_response_code(404); exit; }
header('Content-Type: text/plain');

$raw = (string) Config::get('AV_GDRIVE_SERVICE_ACCOUNT', '');
echo "value seen by Config : ", $raw === '' ? 'EMPTY' : strlen($raw) . ' chars, starts ' . substr($raw, 0, 1), "\n";
echo "looks like JSON      : ", var_export(str_starts_with(ltrim($raw), '{'), true), "\n";
echo "configured()         : ", var_export(GoogleWorkspace::configured(), true), "\n";
echo "whyNotConfigured()   : ", GoogleWorkspace::whyNotConfigured(), "\n";
echo "unreadable keys      : ", json_encode(AvSettings::unreadable()), "\n";
echo "crypto               : ", json_encode(AvSettings::cryptoStatus()), "\n";

$row = Database::pdo()->query("SELECT setting_key, length(value) n, is_secret, updated_by, updated_at
                               FROM av_settings WHERE setting_key='AV_GDRIVE_SERVICE_ACCOUNT'")->fetch();
echo "db row               : ", $row ? json_encode($row) : 'NO ROW — nothing was ever saved', "\n";
foreach ((AvSettings::describe()['groups']['Google sign-in & Workspace'] ?? []) as $f) {
    if ($f['key'] !== 'AV_GDRIVE_SERVICE_ACCOUNT') continue;
    echo "source / is_set      : ", $f['source'], " / ", var_export($f['is_set'], true),
         " · unreadable=", var_export($f['unreadable'], true), "\n";
}

$t = microtime(true);
$r = AvSettings::test('google_workspace');
printf("test (%.1fs)          : ok=%s %s\n", microtime(true) - $t, var_export($r['ok'], true), $r['detail']);
```

Reading it:

| What it prints | What it means |
|---|---|
| `db row: NO ROW` | Nothing was ever saved → §4.3 |
| a row, but `value seen by Config: EMPTY` | Stored and **undecryptable** → §4.2. `unreadable keys` will list it |
| `value seen by Config` non-empty, `looks like JSON: false` | Stored as a path → §4.4 / `whyNotConfigured()` says which |
| `configured(): true` but the test fails | Not a configuration problem at all — read the test's detail; likely delegation or blocked egress (§4.5) |
| the test takes ~20s and returns a reason | Current code, working as intended |
| the test hangs or the page dies | **Old code is deployed** → §4.1 |

**This script was run here against both states, so the table above is not a
guess.** Nothing stored:

```
value seen by Config : EMPTY
db row               : NO ROW — nothing was ever saved
whyNotConfigured()   : Google Workspace is not set up on this site: …
```

Stored under one APP_KEY, read under another:

```
value seen by Config : EMPTY
unreadable keys      : ["AV_GDRIVE_SERVICE_ACCOUNT"]
db row               : {"setting_key":"AV_GDRIVE_SERVICE_ACCOUNT","n":2507,"is_secret":1,…}
whyNotConfigured()   : The service account key IS stored on this site, but it
                       cannot be read back — … Paste the service account key
                       again … Nothing else needs to change at Google.
```

Note both print `value seen by Config : EMPTY`. **That field alone cannot tell
the two apart** — the `db row` line and `unreadable keys` are what separate
them, which is the whole reason this distinction kept being got wrong.

## 5. How to reproduce locally

No Google credentials are needed for most of it.

```bash
cd /home/user/afrovanguard-site
php tests/run.php                 # full suite
```

A self-contained probe harness pattern (the one used to prove the timeout fix)
is in `tests/wsprobe.test.php`: it builds a real RSA service-account key with
`openssl_pkey_new`, points `AV_WS_TOKEN_URL` / `AV_WS_BASE_URL` at a socket
that is listened on but never accepted, and asserts the probe returns inside
its budget. **Note `AV_WS_TOKEN_URL` is not in `AvSettings::DEFS`** — set it
via the environment (`putenv`), not `AvSettings::save()`, which silently drops
unknown keys.

To exercise the stored-but-unreadable state, write the setting under one
`APP_KEY` in one process and read it back under a different `APP_KEY` in
another — the pattern in `tests/gwssetup.test.php` and `tests/mailsetup.test.php`.

---

## 6. Ruled out — do not spend time here

Each of these was checked in the source on 2026-10-21.

- **A stale browser cache for `admin/app.js`.** Cache-busted by `filemtime`
  (`admin/index.php:1627`).
- **Google sign-in being broken.** It passes, and it is a different credential
  (OAuth client ID/secret, not the service account).
- **The Setup form pre-filling the masked value, so a save is a silent no-op.**
  It does not. `setupField()` in `admin/app.js` puts the mask in the
  `placeholder` and the preview in a separate `<code class="setup-mask">`; the
  JSON textarea is never given a `value`.
- **A save wiping the key by submitting an untouched empty field.** `save()`
  does delete on an empty value, but the client sends **only dirty fields**
  (`setupDirty`, `admin/app.js:1850`), so an untouched field is never
  submitted. Worth re-checking if that handler is ever changed to post the
  whole form.
- **The `strpos($val, '…')` guard silently skipping a real paste.** The guard
  exists (`AvSettings::save()`), and it does `continue` without an error — but
  nothing prefills a secret field with the mask, so a genuine key never
  contains `…`. Still a trap if someone copies the visible mask by hand.
- **Invalid JSON being stored as the string `"null"`.** `validate()` routes
  `type => json` to `validateServiceAccount()`, which rejects non-JSON, a
  non-`service_account` type, a missing/invalid `client_email` and an
  unreadable `private_key`, each with its own message, **before** the
  `json_encode(json_decode(...))` normalisation runs.
- **`whyNotConfigured()` not covering the cases.** It covers four distinct
  states and is tested.
- **The probe being unbounded.** Fixed and measured (§3).
- **`scopeCheck()` reporting a timeout as a refused scope.** Fixed; unreached
  scopes are `SCOPE_UNCHECKED` and an incomplete test no longer returns `ok`.

---

## 7. Also open on this branch (unrelated to Workspace, same operator)

- **Migration to MySQL.** `1061 Duplicate key name` fixed in `a8fa2a1` —
  MySQL has no `CREATE INDEX IF NOT EXISTS`, so re-applying the schema always
  raised it, and the one-shot path aborted the whole migration there. Both
  schema paths now share `Database::isBenignSchemaError()`. **The resumable
  migration has never been run against a live MySQL** — there is no MySQL
  server in this container. Dry-run on a scratch database first.
- **The database is read from the environment only.** `AV_DB_*` in
  `config.php` is dead text (`Database::pdo()` runs before `Config`/`AvSettings`
  and would recurse). `Database::configSource()` reports the mismatch and
  System health names it. The operator's site is still on SQLite.
- **Meetings "cannot be edited".** They can; a successful save carrying a
  "no Meet link yet" warning was rendered in the failure colour and never
  cleared (`3043df5`). Caused by Workspace being unreachable — so it will
  resolve itself when this handoff's problem is solved.

---

## 8. One caution about this whole area

Three separate screens have now been caught reporting "not configured" for a
state that was actually "stored but unreadable", and each was fixed
individually before the shared helper (`AvSettings::whyUnreadable()`) existed.
When adding a new integration that stores a secret, call that helper rather
than writing a fourth boolean. `tests/mailsetup.test.php` documents the trap.

Equally: **do not let a timeout be reported as a refusal.** It sends an
operator to the Google Admin console to fix a delegation that may be fine.
That distinction is why `SCOPE_UNCHECKED` exists.
