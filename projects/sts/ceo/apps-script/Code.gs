/**
 * Street-To-Stardom · Apps Script — Sheets DB layer
 * ─────────────────────────────────────────────────────────────────────
 * Called only by the PHP backend (not the frontend directly).
 * Setup:
 *   1. Create a Google Sheet. Note its ID.
 *   2. Extensions → Apps Script. Paste this Code.gs.
 *   3. Project Settings → Script Properties:
 *        SHEET_ID    = your sheet id
 *        SHARED_KEY  = a long random string (also goes in PHP config)
 *        ADMIN_EMAIL = optional notification recipient
 *   4. Deploy → New deployment → Web app. Execute as: Me. Access: Anyone.
 *   5. Copy /exec URL → paste into PHP config.php as APPS_SCRIPT_URL.
 * ─────────────────────────────────────────────────────────────────────
 */

const SUBMISSIONS_TAB  = 'Submissions';
const SUBSCRIBERS_TAB  = 'Subscribers';
const RESERVATIONS_TAB = 'Reservations';

function doGet(e) {
  try {
    if (!authorized(e)) return fail('Unauthorized.');
    const action = (e && e.parameter && e.parameter.action) || '';
    switch (action) {
      case 'fetch_reservations': return ok({ reservations: fetchReservations() });
      case 'ping':               return ok({ pong: true });
      default:                   return ok({ message: 'STS Sheets DB layer.' });
    }
  } catch (err) {
    return fail(String(err && err.message || err));
  }
}

function doPost(e) {
  let body = {};
  try {
    body = JSON.parse((e && e.postData && e.postData.contents) || '{}');
  } catch (err) {
    return fail('Invalid JSON body.');
  }
  if (!authorizedBody(body)) return fail('Unauthorized.');

  const action = body.action;
  const data   = body.data || {};
  try {
    switch (action) {
      case 'submit':             return ok(handleSubmit(data));
      case 'subscribe':          return ok(handleSubscribe(data));
      case 'fetch_reservations': return ok({ reservations: fetchReservations() });
      default:                   return fail('Unknown action: ' + action);
    }
  } catch (err) {
    return fail(String(err && err.message || err));
  }
}

function authorized(e) {
  const key = props().getProperty('SHARED_KEY');
  if (!key) return true;
  return (e && e.parameter && e.parameter.key) === key;
}
function authorizedBody(body) {
  const key = props().getProperty('SHARED_KEY');
  if (!key) return true;
  return body && body.key === key;
}

function handleSubmit(d) {
  const required = ['name', 'role', 'org', 'email', 'centre', 'date'];
  for (let i = 0; i < required.length; i++) {
    if (!d[required[i]]) throw new Error('Missing field: ' + required[i]);
  }
  if (!isValidEmail(d.email)) throw new Error('Invalid email.');

  const sheet = getOrCreateSheet(SUBMISSIONS_TAB, [
    'Timestamp', 'ID', 'Honorific', 'Name', 'Role', 'Organisation',
    'Email', 'WhatsApp', 'Notes', 'Centre', 'Date'
  ]);
  const id = d.id || ('sts_' + new Date().getTime().toString(36) + '_' + Math.random().toString(36).slice(2, 7));
  const now = new Date();
  sheet.appendRow([
    now, id,
    sanitize(d.honorific, 30), sanitize(d.name, 200), sanitize(d.role, 200),
    sanitize(d.org, 200), sanitize(d.email, 200), sanitize(d.whatsapp, 40),
    sanitize(d.notes, 1000), sanitize(d.centre, 30), sanitize(d.date, 20),
  ]);

  const adminEmail = props().getProperty('ADMIN_EMAIL');
  if (adminEmail) {
    try {
      MailApp.sendEmail({
        to: adminEmail,
        subject: 'STS 2026 · New speaker initiation: ' + d.name,
        body:
          'Name: ' + (d.honorific || '') + ' ' + d.name + '\n' +
          'Role: ' + d.role + '\nOrganisation: ' + d.org + '\n' +
          'Email: ' + d.email + '\nWhatsApp: ' + (d.whatsapp || '') + '\n' +
          'Centre: ' + d.centre + '\nDate: ' + d.date + '\n' +
          'Notes: ' + (d.notes || '') + '\n\nID: ' + id + '\nSubmitted: ' + now.toISOString()
      });
    } catch (ignored) {}
  }
  return { id: id };
}

function handleSubscribe(d) {
  if (!d.email || !isValidEmail(d.email)) throw new Error('Invalid email.');
  const sheet = getOrCreateSheet(SUBSCRIBERS_TAB, ['Timestamp', 'Email', 'Source']);
  const email = String(d.email).trim().toLowerCase();
  const values = sheet.getDataRange().getValues();
  for (let i = 1; i < values.length; i++) {
    if (String(values[i][1] || '').toLowerCase() === email) return { duplicate: true };
  }
  sheet.appendRow([new Date(), email, sanitize(d.source || 'footer', 30)]);
  return { ok: true };
}

function fetchReservations() {
  const sheet = getOrCreateSheet(RESERVATIONS_TAB, ['DateId', 'Name', 'Title', 'Initial']);
  const values = sheet.getDataRange().getValues();
  const out = [];
  for (let i = 1; i < values.length; i++) {
    const row = values[i];
    if (!row[0]) continue;
    out.push({
      dateId:  String(row[0]),
      name:    String(row[1] || ''),
      title:   String(row[2] || ''),
      initial: String(row[3] || '').toUpperCase().slice(0, 3),
    });
  }
  return out;
}

function getOrCreateSheet(name, headers) {
  const sheetId = props().getProperty('SHEET_ID');
  if (!sheetId) throw new Error('SHEET_ID not configured.');
  const ss = SpreadsheetApp.openById(sheetId);
  let sheet = ss.getSheetByName(name);
  if (!sheet) {
    sheet = ss.insertSheet(name);
    sheet.appendRow(headers);
    sheet.getRange(1, 1, 1, headers.length).setFontWeight('bold').setBackground('#f4f4f0');
    sheet.setFrozenRows(1);
  } else if (sheet.getLastRow() === 0) {
    sheet.appendRow(headers);
    sheet.getRange(1, 1, 1, headers.length).setFontWeight('bold').setBackground('#f4f4f0');
    sheet.setFrozenRows(1);
  }
  return sheet;
}

function props() { return PropertiesService.getScriptProperties(); }
function sanitize(s, max) { if (s === undefined || s === null) return ''; return String(s).replace(/<[^>]*>/g, '').slice(0, max || 500); }
function isValidEmail(v) { return /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i.test(String(v || '').trim()); }
function ok(data) { return ContentService.createTextOutput(JSON.stringify({ ok: true, data: data })).setMimeType(ContentService.MimeType.JSON); }
function fail(error) { return ContentService.createTextOutput(JSON.stringify({ ok: false, error: String(error) })).setMimeType(ContentService.MimeType.JSON); }
