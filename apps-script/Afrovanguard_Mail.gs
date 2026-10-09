/**
 * AFROVANGUARD — MAIL THROUGH GOOGLE APPS SCRIPT
 *
 * The website sends its mail through this script when Google SMTP cannot get through
 * (a closed port on the host, a refused App Password, an account Google has paused for
 * SMTP). The site reaches it over HTTPS, and MailApp sends from the Google account that
 * deployed it — no SMTP port, no API key, no DNS record to get right.
 *
 * ════════════════════════════════════════════════════════════════════════════════════
 * HOW TO DEPLOY IT (about ten minutes, once)
 * ════════════════════════════════════════════════════════════════════════════════════
 *
 *  1. Sign in to Google as the account the mail should come FROM
 *     (e.g. cacentre@afrovanguard.org.ng).
 *  2. Open a new Google Sheet (sheets.new) and name it "Afrovanguard mail".
 *     Then Extensions → Apps Script. (A standalone project at script.google.com works too.)
 *  3. Delete everything in Code.gs and paste this whole file in its place. Save.
 *  4. Set the SECRET below: a long random text between the quotes — 32 letters and digits
 *     or more, no spaces or quotes. Keep a copy; the website needs the identical text.
 *     Save again.
 *  5. Deploy → New deployment → the gear icon → Web app.
 *       Description:    Afrovanguard mail
 *       Execute as:     Me
 *       Who has access: Anyone
 *     Press Deploy. Google asks you to authorise: choose your account → Advanced →
 *     "Go to … (unsafe)" → Allow. (It is your own script; the warning is standard.)
 *  6. Copy the Web app URL. It starts https://script.google.com/macros/s/ and ENDS IN /exec.
 *     (Not the editor's address, and not one ending /dev — that only works for you.)
 *  7. On the website: Studio → Rules & AI → Setup → Email.
 *       Apps Script web-app URL  → paste the /exec URL
 *       Apps Script secret       → paste the SECRET
 *     Save, then press "Test Apps Script mail". Then Studio → System → "Check Apps Script"
 *     and "Send test" to see a message go through it.
 *
 *  AFTER ANY EDIT to this file: Deploy → Manage deployments → the pencil → Version:
 *  "New version" → Deploy. Saving alone does NOT change what the /exec URL runs, and the
 *  URL stays the same, so nothing on the website needs changing.
 *
 * ── WHAT IT CAN CARRY ────────────────────────────────────────────────────────────────
 *
 * MailApp allows about 100 recipients a day on a gmail.com account and 1,500 on Google
 * Workspace. That is plenty for the mail somebody is WAITING for — a sign-in code, a
 * receipt, a confirmation — and nowhere near a newsletter. So the website sends only
 * one-to-one mail this way; announcements (anything with an unsubscribe link) never
 * spend this allowance.
 *
 * ── THE PROTOCOL ─────────────────────────────────────────────────────────────────────
 *
 *   GET  /exec                            → {success:true, service, ts}   (health; no secret)
 *   POST /exec  {action, token, data, source}
 *        action 'ping'                    → {success:true, account}
 *        action 'mail.send'               → {success:true, remaining}
 *           data {to, subject, html, text, name, reply_to, bcc, attachments:[{name, mime, content(base64)}]}
 *        action 'mail.quota'              → {success:true, remaining, account}
 *   Every POST is refused unless SECRET is set here and `token` equals it.
 *   Failures answer {success:false, message:'…'} — the website shows the message as written.
 */

/**
 * THE SHARED SECRET. Leave it empty and every request is refused.
 *
 * The web app is deployed "Anyone", because the website posts to it with no Google login.
 * An open mail relay on a public URL would be a gift to whoever found it — so nothing
 * happens without this token. Put the same text in Studio → Rules & AI → Setup → Email → Apps Script secret.
 */
const SECRET = '';

const SERVICE = 'Afrovanguard mail';
const MAX_ATTACHMENTS = 5;

function doGet(e) {
  return json_({success: true, ok: true, service: SERVICE, secretSet: SECRET !== '', ts: new Date().toISOString()});
}

function doPost(e) {
  let body;
  try { body = JSON.parse((e && e.postData && e.postData.contents) || ''); }
  catch (err) { return respond(false, 'Invalid JSON'); }

  if (!SECRET) {
    return respond(false, 'This Apps Script has no SECRET set, so it refuses every request. Set SECRET at the top of '
      + 'the script, deploy a New version, and paste the same text into Studio → Rules & AI → Setup → Email → Apps Script secret.');
  }
  if (!body || body.token !== SECRET) return respond(false, 'Bad token');

  const action = String(body.action || '');
  try {
    if (action === 'ping')       return respond(true, 'pong', {account: account_()});
    if (action === 'mail.send')  return mailSend(body.data || {});
    if (action === 'mail.quota') return mailQuota();
    return respond(false, 'Unknown action: ' + action);
  } catch (err) {
    return respond(false, String((err && err.message) || err));
  }
}

/**
 * SEND ONE EMAIL FOR THE WEBSITE.
 *
 * The quota is checked FIRST: once the day's allowance is spent MailApp throws a long
 * error, and the website should be told plainly so it can try its next road.
 */
function mailSend(d) {
  const to = String(d.to || '').trim();
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(to)) return respond(false, 'No valid recipient.');
  const bcc = String(d.bcc || '').trim();
  const needed = 1 + (/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(bcc) ? 1 : 0);
  const left = MailApp.getRemainingDailyQuota();
  if (left < needed) {
    return respond(false, 'Apps Script daily email quota exceeded for this Google account.', {remaining: left});
  }

  const opts = {
    to: to,
    subject: String(d.subject || '').slice(0, 250),
    htmlBody: String(d.html || ''),
    body: String(d.text || '') || 'This message is best read in an email app that shows HTML.',
    name: String(d.name || 'Afrovanguard').slice(0, 80)
  };
  if (!opts.htmlBody) delete opts.htmlBody;
  if (d.reply_to) opts.replyTo = String(d.reply_to);
  if (needed === 2) opts.bcc = bcc;

  const files = Array.isArray(d.attachments) ? d.attachments : [];
  if (files.length) {
    opts.attachments = files.slice(0, MAX_ATTACHMENTS).map(function (f) {
      return Utilities.newBlob(Utilities.base64Decode(String(f.content || '')),
        String(f.mime || 'application/octet-stream'), String(f.name || 'file'));
    });
  }

  MailApp.sendEmail(opts);
  return respond(true, 'Sent', {remaining: MailApp.getRemainingDailyQuota()});
}

/** How many more recipients this account may email today. A READ — sends nothing. */
function mailQuota() {
  return respond(true, 'Quota', {remaining: MailApp.getRemainingDailyQuota(), account: account_()});
}

function account_() {
  try { return Session.getEffectiveUser().getEmail() || ''; } catch (err) { return ''; }
}

function respond(success, message, extra) {
  const out = {success: success, ok: success, message: message, timestamp: new Date().toISOString()};
  const more = extra || {};
  Object.keys(more).forEach(function (k) { out[k] = more[k]; });
  return json_(out);
}

function json_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}

/**
 * Run this once from the editor (select testSetup ▸ Run) to grant the mail permission
 * and see the account and today's allowance in the log — before deploying.
 */
function testSetup() {
  Logger.log('Account: ' + account_() + ' · may still send to ' + MailApp.getRemainingDailyQuota()
    + ' recipient(s) today · SECRET ' + (SECRET ? 'is set' : 'is NOT set — set it before deploying'));
}
