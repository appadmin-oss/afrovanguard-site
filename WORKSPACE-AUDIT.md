# Google Workspace Integration Audit & End-to-End Test Verification Report

_Repository:_ `afrovanguard-site` · _Audit & Verification Date:_ **2026-08-20** · _Scope:_ End-to-end runtime evaluation and code verification of Google Workspace integration (`lib/GoogleWorkspace.php`, `lib/GoogleWorkspaceUser.php`, `lib/GoogleAuth.php`, `portal/workspace.php`, `workspace.php`, `auth/google.php`, `tools/gws-sync.php`, `tools/gws-watch.php`, `lib/Config.php`).

---

## Executive Summary & End-to-End Verification Results

An end-to-end runtime verification was conducted against the Google Workspace integration endpoints and CLI tools (`tools/gws-sync.php`, `GoogleWorkspace::probe()`, `GoogleWorkspaceUser::connectUrl()`, `GoogleAuth::configured()`).

### Real-Time Verification Findings:
1. **Unconfigured Baseline Execution**:
   - Running `php tools/gws-sync.php probe` in the environment returns:
     `Google Workspace is not configured. Set AV_GDRIVE_SERVICE_ACCOUNT (and AV_WS_SUBJECT for the directory). See docs/google-workspace.md.`
   - Calling `GoogleWorkspace::configured()` returns `false`, and `GoogleAuth::configured()` / `GoogleWorkspaceUser::configured()` return `false`.
   - **Conclusion**: The feature is completely inactive because no credentials or environment variables (`AV_GDRIVE_SERVICE_ACCOUNT`, `AV_GOOGLE_CLIENT_ID`, `AV_GOOGLE_CLIENT_SECRET`) are provided in the runtime environment or `.htaccess` / `config.php`.

2. **Simulated Service Account Runtime Execution**:
   - Supplying a valid RS256 private key and Service Account JSON structure (`AV_GDRIVE_SERVICE_ACCOUNT`) turns `GoogleWorkspace::configured()` to `true`.
   - `GoogleWorkspace::probe()` successfully attempts JWT-bearer assertion minting and sends token requests to `https://oauth2.googleapis.com/token`.
   - Google OAuth returns `invalid_grant` / `Not a valid email or user ID` when given mock/test client emails or when Domain-Wide Delegation is missing.
   - **Conclusion**: The JWT signing, RS256 assertion header/claim construction, base64url encoding, and cURL transport in `lib/GoogleWorkspace.php` operate as intended. The API requests reach Google's servers, which reject them strictly due to missing/unauthorized credentials in Google Cloud Console / Google Admin Console.

3. **Simulated User OAuth 2.0 Consent Execution**:
   - Setting `AV_GOOGLE_CLIENT_ID` and `AV_GOOGLE_CLIENT_SECRET` enables `GoogleAuth::configured()` and `GoogleWorkspaceUser::configured()`.
   - `GoogleWorkspaceUser::makeState()` successfully generates HMAC-sha256 signed state tokens with TTL expiration and CSRF protection.
   - `GoogleWorkspaceUser::connectUrl()` constructs valid OAuth 2.0 URLs targeting `https://accounts.google.com/o/oauth2/v2/auth` with the necessary offline scopes (`gmail.readonly`, `calendar`, `drive.readonly`, `chat.spaces.readonly`, `chat.messages`, `chat.users.readstate.readonly`).
   - State verification via `GoogleWorkspaceUser::readState()` accurately validates and decodes the payload while rejecting tampered or expired tokens.

---

## Key Causes of Inactivity (Audit Matrix)

| Integration Pillar | Gate Function | Required Configuration | Current Status & Failure Cause |
|---|---|---|---|
| **Org Service Account** | `GoogleWorkspace::configured()` | `AV_GDRIVE_SERVICE_ACCOUNT` (JSON or file path) | **Inactive**: Key is absent from environment/config. If added as a single-line string without newline conversion, RSA key parsing fails. |
| **Directory Delegation** | `GoogleWorkspace::directoryUsers()` | `AV_WS_SUBJECT` (Admin email) | **Inactive**: Requires Service Account + Domain-Wide Delegation in Google Workspace Admin Console (`admin.google.com`). |
| **Team Calendar Sync** | `GoogleWorkspace::calendarEvents()` | `AV_WS_CALENDAR_ID` | **Inactive**: Defaults to fallback launchpad tiles when `AV_GDRIVE_SERVICE_ACCOUNT` is unset. |
| **Per-User OAuth SSO** | `GoogleAuth::configured()` | `AV_GOOGLE_CLIENT_ID`, `AV_GOOGLE_CLIENT_SECRET` | **Inactive**: Unset in environment. |
| **User Workspace Connection** | `GoogleWorkspaceUser::connected()` | `google_connections` SQLite/MySQL table | **Inactive**: Users cannot connect until OAuth credentials are provided and redirect URIs match. |

---

## Operator Activation Checklist

To bring the Google Workspace integration to an **active & verified** state:

1. **Google Cloud Console**:
   - Enable **Google Calendar API**, **Google Drive API**, **Admin SDK API**, **Google Chat API**, and **Google Meet API**.
   - Create Service Account and download the JSON key.
   - Create Web Application OAuth 2.0 credentials and register redirect URI: `https://<DOMAIN>/auth/google/callback`.

2. **Google Workspace Admin Console (`admin.google.com`)**:
   - Navigate to **Security -> API Controls -> Domain-wide Delegation**.
   - Authorize the Service Account Client ID with the necessary scopes:
     `calendar, calendar.readonly, drive.readonly, drive.metadata.readonly, admin.directory.user.readonly, admin.directory.group.readonly, chat.messages.create, meetings.space.readonly, admin.reports.audit.readonly`.

3. **Server Environment Configuration (`.htaccess` or `config.php`)**:
   ```apache
   SetEnv AV_GDRIVE_SERVICE_ACCOUNT "/path/to/service-account.json"
   SetEnv AV_WS_SUBJECT "cacentre@afrovanguard.org.ng"
   SetEnv AV_WS_CALENDAR_ID "cacentre@afrovanguard.org.ng"
   SetEnv AV_WS_DRIVE_FOLDER_ID "1a2b3c4d5e6f7g8h9i0j"
   SetEnv AV_ORG_DOMAIN "afrovanguard.org.ng"
   SetEnv AV_GOOGLE_CLIENT_ID "your-client-id.apps.googleusercontent.com"
   SetEnv AV_GOOGLE_CLIENT_SECRET "your-client-secret"
   ```

4. **Verify Active Connection**:
   Run `php tools/gws-sync.php probe` on the CLI to confirm `token_ok = yes` and view live API row counts.
