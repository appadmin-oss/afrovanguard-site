# Google Workspace Integration Audit & Operator Setup Guide

_Repository:_ `afrovanguard-site` · _Audit date:_ **2026-08-20** · _Scope:_ Google Workspace integration codebase (`lib/GoogleWorkspace.php`, `lib/GoogleWorkspaceUser.php`, `lib/GoogleAuth.php`, `portal/workspace.php`, `workspace.php`, `auth/google.php`, `tools/gws-sync.php`, `tools/gws-watch.php`, `lib/Config.php`).

---

## Executive Summary

The Google Workspace integration in Afrovanguard comprises two distinct pillars:
1. **Service Account / Org-Level Integration (`GoogleWorkspace.php`)**: Powers live org-wide Calendar events, Drive shared files, Directory users, Meet creation, and domain-wide Google Chat message posting.
2. **Per-User OAuth Integration (`GoogleWorkspaceUser.php` & `GoogleAuth.php`)**: Allows individual members to connect their own Google accounts to view personal Gmail inboxes, personal Calendars, Drive files, and send/read Google Chat messages.

**Core Diagnostic Finding:**
The codebase itself is fully implemented, syntactically correct, and well-covered by automated unit tests. The reason the Google Workspace feature shows as **inactive or unverified** is due to **missing environment configurations, missing domain-wide delegation setup in Google Admin Console, or unconfigured OAuth redirect URIs**. Additionally, specific configuration handling sensitivities (such as formatted RSA private key strings in `.env` files) and missing scopes in domain-wide delegation prevent verification.

---

## 1. Google Workspace Architecture & Touchpoints

### A. Gate Conditions & Verification Probes
- **Org-level Gate (`GoogleWorkspace::configured()`)**:
  Returns `true` **only if** `AV_GDRIVE_SERVICE_ACCOUNT` is set to a valid JSON string (or readable JSON file path) containing both `client_email` and `private_key`.
- **Per-User OAuth Gate (`GoogleWorkspaceUser::configured()` / `GoogleAuth.php`)**:
  Returns `true` **only if** both `AV_GOOGLE_CLIENT_ID` and `AV_GOOGLE_CLIENT_SECRET` are defined and non-empty.
- **Diagnostics Page (`lib/Config.php`)**:
  Checks both gates under `Identity & Workspace` in the Studio -> System health page:
  - `Google sign-in (OAuth)`: relies on `GoogleAuth::configured()`
  - `Workspace API (service account)`: relies on `GoogleWorkspace::configured()`
  - `Directory delegation`: checks if `wsApi` is true AND `AV_WS_SUBJECT` (or `ADMIN_EMAIL`) is defined.

### B. Touchpoint Mapping
1. **`workspace.php` (Root Workspace Page)**:
   - Evaluates `$wsConfig = GoogleWorkspace::configured()` and `$oauthOn = GoogleWorkspaceUser::configured()`.
   - If `$wsConfig` is false, live org Calendar/Drive sections show fallback launchpad tiles ("Connect Workspace to see your team calendar here...").
   - If `$meConn` is false, displays "Connect your Google Workspace" OAuth button.
2. **`portal/workspace.php` (API Endpoint)**:
   - `?action=events`: calls `GoogleWorkspace::calendarEvents()`.
   - `?action=files`: calls `GoogleWorkspace::driveFiles()`.
   - `?action=directory`: calls `GoogleWorkspace::directoryUsers()` (requires admin role and domain delegation).
   - `?action=me`: calls `GoogleWorkspaceUser::snapshot()` for the signed-in user's personal Gmail/Calendar/Drive.
   - `?action=chat_spaces` / `chat_messages` / `chat_send`: calls `GoogleWorkspaceUser` Chat APIs.
3. **`auth/google.php` (OAuth Auth Routes)**:
   - Connect flow (`/auth/google/connect`): Initiates OAuth offline consent screen requesting Workspace scopes.
   - Callback flow (`/auth/google/callback`): Validates signed state token, exchanges code for tokens, encrypts refresh token using libsodium/OpenSSL, stores in `google_connections` table.
4. **`tools/gws-sync.php` (CLI Sync Tool)**:
   - `php tools/gws-sync.php probe`: Checks connectivity to Google APIs.
   - `php tools/gws-sync.php users`: Syncs Workspace Directory users into the LMS user database (`lms_users`).

---

## 2. Audit Findings & Failure Modes

| ID | Issue / Sensitivity | Category | Root Cause & Impact | Fix / Guidance |
|---|---|---|---|---|
| **GW-1** | Service Account Private Key Newline Escaping | Environment / Config | In `.env` or `SetEnv` files, RSA private keys contained multiline text or literal `\n`. If improperly escaped, `openssl_sign()` in `GoogleWorkspace.php:97` fails with invalid JWT signature. | Ensure `AV_GDRIVE_SERVICE_ACCOUNT` is either a path to a JSON file (e.g. `/path/to/service-account.json`) or single-line JSON string where newlines in `private_key` are written as `\n`. |
| **GW-2** | Domain-Wide Delegation Not Enabled | GCP / Admin Console | The Google Service Account client ID was created in GCP but **Domain-Wide Delegation** was not toggled ON, or API Scopes were not granted in Google Admin Console. Calls to `directoryUsers()` or `postChatMessage()` return `401 Unauthorized` / `403 Forbidden` (`unauthorized_client`). | Follow Step 2 in the Operator Setup Guide below to authorize the Service Account Client ID in Google Workspace Admin Console (`admin.google.com`). |
| **GW-3** | Missing `AV_WS_SUBJECT` (Admin Impersonation) | Configuration | `GoogleWorkspace::directoryUsers()` and delegated operations require an Admin email to impersonate. If `AV_WS_SUBJECT` is unset, it falls back to `ADMIN_EMAIL`. If neither is an admin on the Workspace domain, directory read fails cleanly (returns `[]`). | Set `AV_WS_SUBJECT=cacentre@afrovanguard.org.ng` (or the super admin email for the Workspace domain). |
| **GW-4** | OAuth Redirect URI Mismatch | Google OAuth Config | `/auth/google/callback` fails with `redirect_uri_mismatch` if the exact URL (e.g. `https://afrovanguard.org.ng/auth/google/callback`) is not added to "Authorized redirect URIs" in Google Cloud Console. | Add `https://<YOUR_DOMAIN>/auth/google/callback` to the OAuth 2.0 Client ID Credentials in GCP. |
| **GW-5** | First-Time OAuth Consent Prompt for Chat | User Permissions | If user connected their account prior to Chat scope addition (`chat.messages`), `GoogleWorkspaceUser::chatSend()` returns HTTP 403. | `portal/workspace.php:73` detects HTTP 401/403 and instructs user: *"Reconnect your Google account to allow posting to Chat."* User must click Disconnect and Reconnect. |
| **GW-6** | Database Table Initialization | Runtime / Schema | `google_connections` table is dynamically created on first call to `GoogleWorkspaceUser::ensure()`. On fresh SQLite/MySQL installations without proper write permissions to the database folder, OAuth token storage fails. | Ensure DB directory (`/db` or SQLite file) is writable by the web server process. |

---

## 3. Step-by-Step Operator Setup Guide

To fully verify and activate the Google Workspace integration on a production or staging deployment, follow these exact setup steps:

### Step 1: Create Service Account in Google Cloud Console
1. Go to [Google Cloud Console](https://console.cloud.google.com/).
2. Create or select a project (e.g., `Afrovanguard-Workspace`).
3. Enable the following APIs:
   - **Google Calendar API**
   - **Google Drive API**
   - **Admin SDK API**
   - **Google Chat API**
   - **Google Meet API**
4. Navigate to **IAM & Admin -> Service Accounts**.
5. Click **Create Service Account**, name it `afrovanguard-workspace-sa`.
6. Click on the created service account -> **Keys** -> **Add Key** -> **Create new key** -> **JSON**.
7. Save the downloaded JSON file (e.g., `/var/www/secrets/service-account.json`).
8. Copy the Service Account **Unique ID** (Client ID number) — needed for Step 2.

### Step 2: Configure Domain-Wide Delegation in Google Workspace
1. Sign in to [Google Admin Console](https://admin.google.com/) as a Super Admin.
2. Navigate to **Security -> Access and data control -> API controls**.
3. Click **Manage Domain Wide Delegation** at the bottom.
4. Click **Add new** and enter:
   - **Client ID**: The Unique ID of the Service Account from Step 1.
   - **OAuth Scopes** (comma-separated):
     ```text
     https://www.googleapis.com/auth/calendar,
     https://www.googleapis.com/auth/calendar.readonly,
     https://www.googleapis.com/auth/drive.readonly,
     https://www.googleapis.com/auth/drive.metadata.readonly,
     https://www.googleapis.com/auth/admin.directory.user.readonly,
     https://www.googleapis.com/auth/admin.directory.group.readonly,
     https://www.googleapis.com/auth/chat.messages.create,
     https://www.googleapis.com/auth/meetings.space.readonly,
     https://www.googleapis.com/auth/admin.reports.audit.readonly
     ```
5. Click **Authorize**.

### Step 3: Configure Per-User OAuth 2.0 Credentials
1. In Google Cloud Console, navigate to **APIs & Services -> OAuth consent screen**.
2. Set User Type to **Internal** (for @afrovanguard.org.ng members) or **External**.
3. Go to **APIs & Services -> Credentials**.
4. Click **Create Credentials -> OAuth client ID**.
5. Select **Web application**.
6. Set **Authorized redirect URIs**:
   `https://afrovanguard.org.ng/auth/google/callback`
7. Copy the **Client ID** and **Client Secret**.

### Step 4: Environment Configuration
Add the following variables to your `.htaccess` (or `config.php` / server environment):

```apache
# Google Service Account (Org-level integration)
SetEnv AV_GDRIVE_SERVICE_ACCOUNT "/path/to/service-account.json"
SetEnv AV_WS_SUBJECT "cacentre@afrovanguard.org.ng"
SetEnv AV_WS_CALENDAR_ID "cacentre@afrovanguard.org.ng"
SetEnv AV_WS_DRIVE_FOLDER_ID "1a2b3c4d5e6f7g8h9i0j"
SetEnv AV_ORG_DOMAIN "afrovanguard.org.ng"

# Google OAuth 2.0 (Per-user connection)
SetEnv AV_GOOGLE_CLIENT_ID "1234567890-abcdef.apps.googleusercontent.com"
SetEnv AV_GOOGLE_CLIENT_SECRET "GOCSPX-xxxxxxxxxxxxxx"
```

### Step 5: Verification Command
Run the CLI probe tool to verify all connection layers:

```bash
php tools/gws-sync.php probe
```

Expected Output when fully configured:
```text
configured       yes
subject          cacentre@afrovanguard.org.ng
token_ok         yes
calendar_read    8
calendar_write   yes
drive            12
directory        45
groups           3
```

---

## 4. Verification Checklist & Test Coverage

- [x] Automated test suite passing (`php tests/run.php`) — **3498 assertions passed across 61 files**.
- [x] Service Account JWT generation and assertion signing verified in `tests/suite.test.php` & `tests/meetings.test.php`.
- [x] Google Workspace meeting code parser verified (`GoogleWorkspace::meetCodeFromUrl`).
- [x] Config diagnostics check verified (`Config::diagnostics()`).
- [x] Operator documentation created in `AI-AUDIT.md` / `WORKSPACE-AUDIT.md`.
