# Sending the website's email through Google Apps Script

When the website cannot reach Google's mail server (SMTP) — common on shared hosting — sign-in
codes, receipts and replies can still go out through a small Google Apps Script that you own.
Google sends them from your Google account. Nothing to install on the server, no port to open,
no API key.

It takes about ten minutes, once.

## What you need

- The Google account the mail should come **from** (for example `cacentre@afrovanguard.org.ng`).
- Super Admin access to the Studio.
- The file `apps-script/Afrovanguard_Mail.gs` from this repository.

## Steps

1. **Open Apps Script.** Signed in as that Google account, open a new Google Sheet
   (`sheets.new`), name it "Afrovanguard mail", then choose **Extensions → Apps Script**.
2. **Paste the script.** Delete everything in `Code.gs` and paste the whole of
   `apps-script/Afrovanguard_Mail.gs`. Press **Save**.
3. **Set the secret.** Near the top, put a long random text between the quotes:

   ```js
   const SECRET = 'YOUR-OWN-LONG-RANDOM-TEXT';
   ```

   Make up your own — letters and digits, 32 or more, no spaces or quotes (a password
   manager's generator is ideal). Never reuse an example. Keep a copy — the website needs
   exactly the same text. Press **Save**.
4. **Deploy it as a web app.** **Deploy → New deployment →** the gear icon **→ Web app**:
   - Execute as: **Me**
   - Who has access: **Anyone**

   Press **Deploy**. Google asks for permission: choose your account → **Advanced** →
   **Go to … (unsafe)** → **Allow**. (It is your own script; the warning is standard.)
5. **Copy the address.** Copy the **Web app URL**. It starts
   `https://script.google.com/macros/s/` and **ends in `/exec`**. Not the editor's address,
   and not one ending `/dev` — that only works for you.
6. **Tell the website.** Studio → **Rules & AI → Setup → Email**:
   - **Apps Script web-app URL** — paste the `/exec` address.
   - **Apps Script secret** — paste the secret.
   - **Sending road** — leave on **auto**.

   Press **Save**, then **Test Apps Script mail** at the top of the Setup page. It should say
   which account answered and how many messages it may still send today.
7. **Send a real test.** Studio → **System** → **Check Apps Script**, then **Send a test email**.
   The result says which road carried the message (`gas` means Apps Script).

## After you edit the script

Saving is not enough. **Deploy → Manage deployments →** the pencil **→ Version: New version →
Deploy.** The `/exec` address stays the same, so nothing on the website changes.

## The sending road

| Setting | What happens |
|---|---|
| **auto** (recommended) | SMTP first. If it fails: Apps Script (one-to-one mail only), then Resend if a key is set, then the server's own mail. |
| **smtp** | SMTP only. |
| **gas** | Apps Script only. Announcements are **held** with a reason, not sent. |
| **resend** | The Resend API only. |
| **host** | The server's own `mail()` only. |

## Limits worth knowing

- Google allows about **100 recipients a day** from a gmail.com account and **1,500** from
  Google Workspace. That is why the website sends only mail somebody is waiting for this way —
  sign-in codes, receipts, confirmations, replies — and never announcements or appeal updates
  (anything with an unsubscribe link). Those wait for SMTP or go by Resend.
- Mail arrives **from the script's Google account**. The display name and the Reply-To are the
  website's, so replies still reach the right inbox. To send *as* another address of yours,
  add it under Gmail → Settings → Accounts → "Send mail as" — Apps Script cannot use an
  address the account does not own.
- Attachments (certificates, receipts) go through, up to five per message.

## When it says…

| Message | What to do |
|---|---|
| no Apps Script address / secret is set | Fill in step 6. |
| the script refused the secret (Bad token) | The secret on the website must match `const SECRET` exactly. If you changed it in the script, deploy a New version. |
| the deployed script has no SECRET of its own | Do step 3, then deploy a New version. |
| it did not answer JSON / older than the mail action | That address runs an old copy. Paste the latest file and deploy a New version. |
| Google asked for a sign-in | The deployment must be *Execute as: Me* and *Who has access: Anyone*. |
| the daily MailApp allowance is used up | Wait — it resets within 24 hours. Meanwhile mail goes by the next road. |

## For the record

- The old relay on NextGen Genius's protocol (`MAIL_RELAY_URL` / `MAIL_RELAY_SECRET`) still
  works if it is set, and is tried after the site's own script.
- `AV_GAS_URL`, `AV_GAS_SECRET` and `AV_MAIL_TRANSPORT` may also come from `config.php` or the
  environment (`GAS_URL` / `GAS_SECRET` / `MAIL_TRANSPORT` are accepted too). A value saved in
  the Studio wins.
- Code: `lib/AppsScriptMail.php`, `lib/Mailer.php` (`plan()`), tests in
  `tests/appsscriptmail.test.php`.
