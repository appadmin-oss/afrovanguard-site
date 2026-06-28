# Configuration

Configuration comes from two compatible sources, read through one accessor.

1. **`config.php`** (deployment, git-ignored) — defines constants for secrets
   (SMTP, Paystack, admin token, Cloudinary, Drive, Google OAuth). Copy
   `config.example.php` → `config.php` and fill it in, or…
2. **Environment variables** (`SetEnv` in `.htaccess`) — `AV_*` keys. The
   bootstrap turns the important ones into constants with safe fallbacks, so the
   public site renders even when a secret is absent.

## Reading config — `lib/Config.php`

Single source of truth for *reading* config and asking *is this configured?*:

```php
Config::get('SITE_URL', 'https://afrovanguard.org.ng'); // constant → env → default
Config::str/int/bool('KEY', $default);
Config::has('PAYSTACK_PUBLIC_KEY', 'PAYSTACK_SECRET_KEY'); // all present & non-empty?
```

Existing code that reads constants directly keeps working — `Config` is additive
and is the recommended accessor for new code.

## Health — Studio → System

The **System** tab renders `Config::diagnostics()`: a live, colour-coded status
of every integration and the runtime:

- 🟢 **green** — ready
- 🟠 **amber** — optional or degraded (falls back gracefully)
- 🔴 **red** — required but missing (needs attention)

Groups: Runtime (PHP + extensions), Database (driver, tables, content), Email
(SMTP), Payments (Paystack), Identity & Workspace (admin token, Google OAuth,
org domain), Storage (Cloudinary, Drive), Webhooks (endpoints + delivery
backlog), Filesystem & portability (writable dirs, generated schema files).

## Required vs optional

| Integration | Keys | If missing |
|---|---|---|
| **Email** | `SMTP_HOST`, `SMTP_USERNAME`, `SMTP_PASSWORD` (or `AV_SMTP_PASSWORD`) | 🔴 verification + notifications off |
| **Payments** | `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_SECRET_KEY` | 🔴 donations + paid enrolment off |
| **Admin** | `ADMIN_TOKEN` (≥ 8 chars) | 🔴 Studio unavailable |
| **Database** | `AV_DB_DRIVER` + `AV_DB_*` (default: SQLite) | — (defaults to SQLite) |
| Google OAuth | `AV_GOOGLE_CLIENT_ID/SECRET` | 🟠 password sign-in still works |
| Cloudinary / Drive | `CLOUDINARY_*` / `AV_GDRIVE_*` | 🟠 falls back to local `/uploads` |
| Workspace / Webhooks | `AV_WS_*` / managed in Studio | 🟠 features simply hidden |

> If the public site shows `{"success":false,"message":"Server configuration
> error"}`, the four required secrets (`AV_SMTP_PASSWORD`, `AV_PAYSTACK_PK`,
> `AV_PAYSTACK_SK`, `AV_ADMIN_TOKEN`) are unset — the System page shows exactly
> which. Restore them via `SetEnv` in `.htaccess`.
