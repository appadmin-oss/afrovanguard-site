/**
 * Street-To-Stardom · 2026 · Empowered 360°
 * Apps Script — Google Sheets DB layer
 * ─────────────────────────────────────────────────────────────────────
 * Called only by the PHP backend (api/_helpers.php → sts_appscript_post).
 * The web app endpoint does NOT support CORS preflight, so PHP posts
 * with Content-Type: text/plain (not application/json) and a SHARED_KEY
 * inside the body. doPost reads e.postData.contents and parses JSON.
 *
 * Setup (one time):
 *   1. Create a Google Sheet. Note its ID (the long string between /d/ and /edit).
 *   2. Extensions → Apps Script. Replace any default code with this file.
 *   3. Project Settings → Script Properties — add these three keys:
 *        SHEET_ID    = your sheet id from step 1
 *        SHARED_KEY  = a long random string, mirrored in PHP api/config.php
 *        ADMIN_EMAIL = optional notification address
 *   4. Deploy → New deployment → Type: Web app
 *        Execute as: Me   ·   Access: Anyone
 *      Copy the /exec URL — paste into PHP config as APPS_SCRIPT_URL.
 *   5. Run any function once (e.g. selfTest) to grant Sheets + Mail scopes.
 *   6. Open the Sheet again — the four tabs auto-create on first request:
 *        Submissions · Subscribers · Reservations · Donations
 *
 * Updating later:
 *   Saving the script doesn't update the live URL. To publish a change:
 *   Deploy → Manage deployments → pencil icon → Version: New version → Deploy.
 *   The URL stays the same.
 * ─────────────────────────────────────────────────────────────────────
 */

const TAB_SUBMISSIONS  = 'Submissions';
const TAB_SUBSCRIBERS  = 'Subscribers';
const TAB_RESERVATIONS = 'Reservations';
const TAB_DONATIONS    = 'Donations';
const TAB_MILESTONES   = 'Milestones';

// Column schemas — keep these stable; appending new columns is safe,
// reordering or removing existing ones will break older deployments.
const HEADERS_SUBMISSIONS = [
  'Timestamp', 'ID', 'Honorific', 'Full Name', 'Role', 'Organisation',
  'Email', 'WhatsApp', 'Centre ID', 'Centre Name', 'Centre Focus',
  'Date ID', 'Date', 'Theme', 'Series Theme', 'Notes', 'Has Photo',
  'Donated', 'Donation Total (₦)', 'Donation Reference',
  // Comma-separated list of reminder stage codes already sent for this
  // speaker (e.g. "T28,T21"). Safe to append; column ops use named
  // lookups so order shifts don't break anything.
  'Reminders Sent',
  // Team-managed confirmation status — drives downstream logic:
  //   • 'pending'    (default) — auto-set on new submission. No reminders sent.
  //   • 'confirmed'  — team has spoken with the speaker and verified
  //                    attendance. Reminders fire on schedule; the
  //                    speaker shows up in the public hero peer list
  //                    (via the reservations merge).
  //   • 'declined'   — speaker withdrew. No reminders, no public listing.
  //                    Row is kept for the record.
  //   • 'cancelled'  — series-side cancellation. Same effect as declined.
  // The cell has a data-validation drop-down applied (see
  // applyConfirmedValidation) so the team can only enter valid statuses.
  'Confirmed'
];
const HEADERS_SUBSCRIBERS  = ['Timestamp', 'Email', 'Source'];
const HEADERS_RESERVATIONS = ['DateId', 'Name', 'Title', 'Initial'];
const HEADERS_DONATIONS    = [
  'Timestamp', 'Speaker ID', 'Speaker Name', 'Email', 'Tier', 'Children',
  'Amount (₦)', 'Currency', 'Channel', 'Reference', 'Verified', 'Paid At'
];
// Milestones are edited directly in the sheet — order column controls
// display order, date column is ISO-8601 (YYYY-MM-DD or with time).
const HEADERS_MILESTONES   = ['Order', 'Key', 'Label', 'Date'];
// Seed used the first time the Milestones tab is created so the team
// has a working set to edit instead of an empty sheet.
const DEFAULT_MILESTONES = [
  [1, 'ss-start',   'School Storm starts',            '2026-06-15'],
  [2, 'ss-end',     'School Storm ends',              '2026-07-15'],
  [3, 'sum-start',  'Summer School starts',           '2026-08-01'],
  [4, 'sum-end',    'Summer School ends',             '2026-08-30'],
  [5, 'boot-start', 'Bootcamp starts',                '2026-09-07'],
  [6, 'boot-end',   'Bootcamp ends',                  '2026-09-27'],
  [7, 'ogidi',      'Ogidi Omo Concert & Exhibition', '2026-10-17']
];

// ─── HTTP routing ────────────────────────────────────────────────────

function doGet(e) {
  try {
    if (!authorized(e)) return fail('Unauthorized.', 401);
    const action = paramOf(e, 'action');
    switch (action) {
      case 'ping':               return ok({ pong: true, ts: new Date().toISOString() });
      case 'fetch_reservations': return ok({ reservations: fetchReservations() });
      case 'fetch_milestones':   return ok({ milestones: fetchMilestones() });
      case 'self_test':          return ok(selfTest());
      case 'send_reminders':     return ok(sendDailyReminders());
      case 'preview_reminders':  return ok({ message: 'See Stackdriver logs.' });
      default:                   return ok({ message: 'STS Sheets DB layer.', actions: ['submit','subscribe','log_donation','fetch_reservations','fetch_milestones','ping','self_test','send_reminders'] });
    }
  } catch (err) { return fail(messageOf(err), 500); }
}

function doPost(e) {
  let body = {};
  try {
    const raw = (e && e.postData && e.postData.contents) || '{}';
    body = JSON.parse(raw);
  } catch (err) {
    return fail('Invalid JSON body.', 400);
  }
  if (!authorizedBody(body)) return fail('Unauthorized.', 401);

  const action = String(body.action || '');
  const data   = body.data || {};
  try {
    switch (action) {
      case 'submit':             return ok(handleSubmit(data));
      case 'subscribe':          return ok(handleSubscribe(data));
      case 'log_donation':       return ok(handleDonation(data));
      case 'fetch_reservations': return ok({ reservations: fetchReservations() });
      case 'fetch_milestones':   return ok({ milestones: fetchMilestones() });
      default:                   return fail('Unknown action: ' + action, 400);
    }
  } catch (err) { return fail(messageOf(err), 500); }
}

// ─── Action handlers ─────────────────────────────────────────────────

/**
 * Speaker submission.
 * Idempotent on `id` — if a row with the same ID already exists, the row is
 * updated in place rather than appended. This protects against double-submit
 * (retry, refresh, network blip) without manual cleanup.
 */
function handleSubmit(d) {
  const required = ['name', 'role', 'org', 'email', 'centre', 'date'];
  for (let i = 0; i < required.length; i++) {
    if (!d[required[i]]) throw new Error('Missing field: ' + required[i]);
  }
  if (!isValidEmail(d.email)) throw new Error('Invalid email.');

  const sheet = getOrCreateSheet(TAB_SUBMISSIONS, HEADERS_SUBMISSIONS);
  const id = sanitize(d.id, 80) || ('sts_' + new Date().getTime().toString(36) + '_' + Math.random().toString(36).slice(2, 7));
  const now = new Date();

  const row = [
    now, id,
    sanitize(d.honorific, 30), sanitize(d.name, 200), sanitize(d.role, 200),
    sanitize(d.org, 200), sanitize(d.email, 200), sanitize(d.whatsapp, 40),
    sanitize(d.centre, 30), sanitize(d.centre_name, 120), sanitize(d.centre_focus, 200),
    sanitize(d.date, 20), sanitize(d.date_display, 120), sanitize(d.theme, 120),
    sanitize(d.series_theme, 120), sanitize(d.notes, 1000),
    d.has_photo ? 'yes' : 'no',
    'no', 0, '',  // donation columns; populated by handleDonation
    '',           // reminders sent (filled by the reminder cron)
    'pending'     // confirmed status (default — team flips to 'confirmed' after verifying)
  ];

  const existingRow = findRowById(sheet, id, 2);
  if (existingRow > 0) {
    // Update in place — preserve donation + reminder + confirmed columns from existing row
    const range = sheet.getRange(existingRow, 1, 1, HEADERS_SUBMISSIONS.length);
    const existing = range.getValues()[0];
    row[17] = existing[17] || 'no';
    row[18] = existing[18] || 0;
    row[19] = existing[19] || '';
    row[20] = existing[20] || '';
    row[21] = existing[21] || 'pending';
    range.setValues([row]);
  } else {
    sheet.appendRow(row);
  }
  applyConfirmedValidation(sheet);
  applyConditionalFormatting(sheet);

  // Optional admin email
  notifyAdminSubmission(d, id, now);

  return { id: id, updated: existingRow > 0 };
}

function handleSubscribe(d) {
  if (!d || !isValidEmail(d.email)) throw new Error('Invalid email.');
  const sheet = getOrCreateSheet(TAB_SUBSCRIBERS, HEADERS_SUBSCRIBERS);
  const email = String(d.email).trim().toLowerCase();

  // De-dupe — read just the Email column for speed
  const last = sheet.getLastRow();
  if (last > 1) {
    const values = sheet.getRange(2, 2, last - 1, 1).getValues();
    for (let i = 0; i < values.length; i++) {
      if (String(values[i][0] || '').toLowerCase() === email) {
        return { duplicate: true, email: email };
      }
    }
  }
  sheet.appendRow([new Date(), email, sanitize(d.source || 'footer', 60)]);
  return { ok: true, email: email };
}

/**
 * Donation logging — called by api/donate.php (intent click) and
 * api/paystack-verify.php (actual verified payment). The `verified` field
 * distinguishes them. Also back-links to the Submissions row so the
 * the team sees "Donated · ₦300,000" alongside the speaker.
 */
function handleDonation(d) {
  const sheet = getOrCreateSheet(TAB_DONATIONS, HEADERS_DONATIONS);
  const amount = parseInt(d.amount, 10) || 0;
  const verified = !!d.verified;
  const speakerId = sanitize(d.speaker_id, 80);

  sheet.appendRow([
    new Date(),
    speakerId,
    sanitize(d.speaker_name, 200),
    sanitize(d.email, 200),
    sanitize(d.tier, 60),
    parseInt(d.children, 10) || 0,
    amount,
    sanitize(d.currency || 'NGN', 8),
    sanitize(d.channel, 40),
    sanitize(d.reference, 120),
    verified ? 'yes' : 'intent',
    sanitize(d.paid_at, 60),
  ]);

  // Back-link to Submissions row — write the commitment regardless of
  // verification status. When verified=false (intent/pledge), we mark the
  // row "committed" so the team sees the intent alongside the speaker;
  // verified payments overwrite to "yes" and update the total properly.
  if (speakerId) {
    const subs = getOrCreateSheet(TAB_SUBMISSIONS, HEADERS_SUBMISSIONS);
    const r = findRowById(subs, speakerId, 2);
    if (r > 0) {
      const donatedCol = HEADERS_SUBMISSIONS.indexOf('Donated') + 1;
      const totalCol   = HEADERS_SUBMISSIONS.indexOf('Donation Total (₦)') + 1;
      const refCol     = HEADERS_SUBMISSIONS.indexOf('Donation Reference') + 1;
      if (verified) {
        const existing = subs.getRange(r, totalCol).getValue();
        const newTotal = (parseInt(existing, 10) || 0) + amount;
        subs.getRange(r, donatedCol).setValue('yes');
        subs.getRange(r, totalCol).setValue(newTotal);
        subs.getRange(r, refCol).setValue(sanitize(d.reference, 120));
      } else {
        // Pledge — only set "committed" if we haven't yet logged a verified
        // payment for this speaker (don't downgrade a real donation).
        const currentStatus = String(subs.getRange(r, donatedCol).getValue() || '').toLowerCase();
        if (currentStatus !== 'yes') {
          subs.getRange(r, donatedCol).setValue('committed');
          const existing = subs.getRange(r, totalCol).getValue();
          if (!parseInt(existing, 10)) {
            subs.getRange(r, totalCol).setValue(amount);
          }
          const tierLabel = sanitize(d.tier, 60) || '';
          if (tierLabel) subs.getRange(r, refCol).setValue('PLEDGE · ' + tierLabel);
        }
      }
      applyConditionalFormatting(subs);
    }
  }

  return { ok: true, verified: verified };
}

function fetchReservations() {
  // Two sources merge into the public reservations feed:
  //   1. The editorial Reservations tab — manually maintained.
  //   2. The Submissions tab, filtered to rows where Confirmed='confirmed'.
  // Editorial wins for any DateId clash, so the team can pin a custom
  // name/title without the form overwriting it.
  const byDate = {};

  // 1. Confirmed form submissions (auto-feed)
  try {
    const subs = getOrCreateSheet(TAB_SUBMISSIONS, HEADERS_SUBMISSIONS);
    const last = subs.getLastRow();
    if (last >= 2) {
      const rows = subs.getRange(2, 1, last - 1, HEADERS_SUBMISSIONS.length).getValues();
      const dateIdCol    = HEADERS_SUBMISSIONS.indexOf('Date ID');
      const honorificCol = HEADERS_SUBMISSIONS.indexOf('Honorific');
      const nameCol      = HEADERS_SUBMISSIONS.indexOf('Full Name');
      const roleCol      = HEADERS_SUBMISSIONS.indexOf('Role');
      const orgCol       = HEADERS_SUBMISSIONS.indexOf('Organisation');
      const confirmedCol = HEADERS_SUBMISSIONS.indexOf('Confirmed');
      rows.forEach(function (r) {
        const status = String(r[confirmedCol] || '').toLowerCase();
        if (status !== 'confirmed') return;
        const dateId = String(r[dateIdCol] || '').trim();
        if (!dateId) return;
        const honor = String(r[honorificCol] || '').trim();
        const name  = (honor ? honor + ' ' : '') + String(r[nameCol] || '').trim();
        const title = [String(r[roleCol] || '').trim(), String(r[orgCol] || '').trim()].filter(Boolean).join(', ');
        byDate[dateId] = {
          dateId:  dateId,
          name:    name,
          title:   title,
          initial: initialsOf(name),
          source:  'submission',
        };
      });
    }
  } catch (e) { /* non-fatal — fall back to editorial-only */ }

  // 2. Editorial Reservations tab (overrides any submission for the same date)
  const sheet = getOrCreateSheet(TAB_RESERVATIONS, HEADERS_RESERVATIONS);
  const last = sheet.getLastRow();
  if (last >= 2) {
    const values = sheet.getRange(2, 1, last - 1, HEADERS_RESERVATIONS.length).getValues();
    for (let i = 0; i < values.length; i++) {
      const row = values[i];
      if (!row[0]) continue;
      const dateId = String(row[0]).trim();
      byDate[dateId] = {
        dateId:  dateId,
        name:    String(row[1] || '').trim(),
        title:   String(row[2] || '').trim(),
        initial: String(row[3] || '').toUpperCase().slice(0, 3) || initialsOf(String(row[1] || '')),
        source:  'editorial',
      };
    }
  }

  return Object.keys(byDate).map(function (k) { return byDate[k]; });
}

function initialsOf(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/**
 * Read the editable list of programme milestones from the Milestones tab.
 * On first call the tab is auto-seeded with DEFAULT_MILESTONES so the team
 * has a working set to edit. Subsequent calls just read whatever the team
 * has put in the sheet.
 *
 * Each milestone object: { order, key, label, date } where `date` is an
 * ISO-8601 string. The client computes weeks-remaining locally from `date`.
 */
function fetchMilestones() {
  const sheet = getOrCreateSheet(TAB_MILESTONES, HEADERS_MILESTONES);
  const last = sheet.getLastRow();

  // Seed on first use — empty sheet means the team hasn't filled it in yet.
  if (last < 2) {
    sheet.getRange(2, 1, DEFAULT_MILESTONES.length, HEADERS_MILESTONES.length)
         .setValues(DEFAULT_MILESTONES);
    SpreadsheetApp.flush();
  }

  const lastNow = sheet.getLastRow();
  if (lastNow < 2) return [];
  const values = sheet.getRange(2, 1, lastNow - 1, HEADERS_MILESTONES.length).getValues();

  const out = [];
  for (let i = 0; i < values.length; i++) {
    const row = values[i];
    const order = parseInt(row[0], 10);
    const key   = String(row[1] || '').trim();
    const label = String(row[2] || '').trim();
    let date    = row[3];
    if (!label || !date) continue;

    // Date may be a Date object (typed cell) or a string. Normalise to ISO.
    if (date instanceof Date) {
      date = Utilities.formatDate(date, 'Africa/Lagos', "yyyy-MM-dd'T'HH:mm:ssXXX");
    } else {
      date = String(date).trim();
    }

    out.push({
      order: isNaN(order) ? 999 : order,
      key:   key || ('m' + i),
      label: label,
      date:  date,
    });
  }
  out.sort(function (a, b) { return a.order - b.order; });
  return out;
}

// ─── Sheet plumbing ──────────────────────────────────────────────────

function getOrCreateSheet(name, headers) {
  const sheetId = props().getProperty('SHEET_ID');
  if (!sheetId) throw new Error('SHEET_ID property not set in Apps Script project settings.');
  let ss;
  try { ss = SpreadsheetApp.openById(sheetId); }
  catch (e) { throw new Error('Cannot open spreadsheet ' + sheetId + ' — is the Sheet ID correct and accessible by the script owner?'); }

  let sheet = ss.getSheetByName(name);
  if (!sheet) {
    sheet = ss.insertSheet(name);
  }
  // Ensure headers row exists and matches the canonical schema
  const firstRow = sheet.getRange(1, 1, 1, Math.max(sheet.getLastColumn(), headers.length)).getValues()[0];
  const headerMismatch = headers.some((h, i) => String(firstRow[i] || '').trim() !== h);
  if (sheet.getLastRow() === 0 || headerMismatch) {
    sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
  }
  // Always reapply header chrome (idempotent)
  const headRange = sheet.getRange(1, 1, 1, headers.length);
  headRange
    .setFontWeight('bold')
    .setBackground('#0A0A0F')
    .setFontColor('#FFFFFF')
    .setFontFamily('Inter')
    .setFontSize(10)
    .setVerticalAlignment('middle')
    .setBorder(null, null, true, null, false, false, '#1791c8', SpreadsheetApp.BorderStyle.SOLID_THICK);
  sheet.setFrozenRows(1);
  sheet.setRowHeight(1, 32);
  return sheet;
}

/**
 * Apply conditional formatting to the Submissions sheet so the team can
 * scan the table at a glance:
 *   • Confirmed=confirmed   → soft green row + bold dark-green pill in the cell
 *   • Confirmed=declined    → strikethrough grey row + amber-grey pill
 *   • Confirmed=cancelled   → same as declined
 *   • Confirmed=pending     → faint yellow pill in the cell (row stays default)
 *   • Donated=yes/committed → keep the existing green/amber donation tints
 *     (drawn AFTER the confirmed-status tint so a confirmed sponsor reads
 *     as both — green dominates anyway)
 *   • Has Photo=yes         → blue chip
 */
function applyConditionalFormatting(sheet) {
  if (sheet.getName() !== TAB_SUBMISSIONS) return;
  const last = sheet.getLastRow();
  if (last < 2) return;
  try {
    sheet.clearConditionalFormatRules();
    const rowRange     = sheet.getRange(2, 1, last - 1, HEADERS_SUBMISSIONS.length);
    const donatedCol   = HEADERS_SUBMISSIONS.indexOf('Donated') + 1;
    const photoCol     = HEADERS_SUBMISSIONS.indexOf('Has Photo') + 1;
    const confirmedCol = HEADERS_SUBMISSIONS.indexOf('Confirmed') + 1;
    const confirmedCell = sheet.getRange(2, confirmedCol, last - 1, 1);

    const rules = [];

    // Row-wide tints driven by the Confirmed column
    rules.push(SpreadsheetApp.newConditionalFormatRule()
      .whenFormulaSatisfied('=INDIRECT("R[0]C' + confirmedCol + '",FALSE)="confirmed"')
      .setBackground('#E6F4EC')
      .setRanges([rowRange])
      .build());
    rules.push(SpreadsheetApp.newConditionalFormatRule()
      .whenFormulaSatisfied('=OR(INDIRECT("R[0]C' + confirmedCol + '",FALSE)="declined",INDIRECT("R[0]C' + confirmedCol + '",FALSE)="cancelled")')
      .setBackground('#F1F1F2')
      .setFontColor('#9CA3AF')
      .setStrikethrough(true)
      .setRanges([rowRange])
      .build());

    // Donation tints — applied after Confirmed so non-confirmed sponsors
    // still get a visual cue
    rules.push(SpreadsheetApp.newConditionalFormatRule()
      .whenFormulaSatisfied('=INDIRECT("R[0]C' + donatedCol + '",FALSE)="committed"')
      .setBackground('#FFF8E1')
      .setRanges([rowRange])
      .build());

    // Confirmed-column pills
    rules.push(SpreadsheetApp.newConditionalFormatRule()
      .whenTextEqualTo('confirmed')
      .setBackground('#0D6A3C').setFontColor('#FFFFFF').setBold(true)
      .setRanges([confirmedCell]).build());
    rules.push(SpreadsheetApp.newConditionalFormatRule()
      .whenTextEqualTo('declined')
      .setBackground('#6B7280').setFontColor('#FFFFFF').setBold(true)
      .setRanges([confirmedCell]).build());
    rules.push(SpreadsheetApp.newConditionalFormatRule()
      .whenTextEqualTo('cancelled')
      .setBackground('#9CA3AF').setFontColor('#FFFFFF').setBold(true)
      .setRanges([confirmedCell]).build());
    rules.push(SpreadsheetApp.newConditionalFormatRule()
      .whenTextEqualTo('pending')
      .setBackground('#FEF3C7').setFontColor('#92400E').setBold(true)
      .setRanges([confirmedCell]).build());

    // Donated-column pills
    rules.push(SpreadsheetApp.newConditionalFormatRule()
      .whenTextEqualTo('yes')
      .setBackground('#CDEAD8').setFontColor('#0d6a3c').setBold(true)
      .setRanges([sheet.getRange(2, donatedCol, last - 1, 1)]).build());
    rules.push(SpreadsheetApp.newConditionalFormatRule()
      .whenTextEqualTo('committed')
      .setBackground('#FFE7A8').setFontColor('#8a5a00').setBold(true)
      .setRanges([sheet.getRange(2, donatedCol, last - 1, 1)]).build());

    // Photo chip
    rules.push(SpreadsheetApp.newConditionalFormatRule()
      .whenTextEqualTo('yes')
      .setBackground('#EEF1FF').setFontColor('#0420B5')
      .setRanges([sheet.getRange(2, photoCol, last - 1, 1)]).build());

    sheet.setConditionalFormatRules(rules);
  } catch (e) { /* non-fatal */ }
}

/**
 * Apply a dropdown data-validation to the Confirmed column so the team
 * can only enter the four valid statuses (and gets a typo-proof
 * dropdown in each cell).
 */
function applyConfirmedValidation(sheet) {
  if (sheet.getName() !== TAB_SUBMISSIONS) return;
  const last = sheet.getLastRow();
  if (last < 2) return;
  try {
    const col = HEADERS_SUBMISSIONS.indexOf('Confirmed') + 1;
    const range = sheet.getRange(2, col, Math.max(last - 1, 1), 1);
    const rule = SpreadsheetApp.newDataValidation()
      .requireValueInList(['pending', 'confirmed', 'declined', 'cancelled'], true)
      .setAllowInvalid(false)
      .setHelpText('Set to "confirmed" once the speaker has been verified by phone/email. Only confirmed rows fire reminders and appear in the public peer list.')
      .build();
    range.setDataValidation(rule);
  } catch (e) { /* non-fatal */ }
}

function findRowById(sheet, id, startRow) {
  if (!id) return -1;
  const last = sheet.getLastRow();
  if (last < (startRow || 2)) return -1;
  const idCol = HEADERS_SUBMISSIONS.indexOf('ID') + 1; // 2
  const values = sheet.getRange(startRow || 2, idCol, last - (startRow - 1), 1).getValues();
  for (let i = 0; i < values.length; i++) {
    if (String(values[i][0]).trim() === id) return i + (startRow || 2);
  }
  return -1;
}

// ─── Notifications (optional admin email) ────────────────────────────

function notifyAdminSubmission(d, id, now) {
  const adminEmail = props().getProperty('ADMIN_EMAIL');
  if (!adminEmail) return;
  try {
    MailApp.sendEmail({
      to: adminEmail,
      subject: 'STS 2026 · ' + (d.honorific ? d.honorific + ' ' : '') + d.name + ' — initiated',
      htmlBody: [
        '<table style="font:14px/1.5 Inter,system-ui,sans-serif;color:#0A0A0F;border-collapse:collapse;">',
        '<tr><td style="padding:4px 12px 4px 0;color:#6B6B70;">Leader</td><td>' + esc((d.honorific ? d.honorific + ' ' : '') + d.name) + '</td></tr>',
        '<tr><td style="padding:4px 12px 4px 0;color:#6B6B70;">Role</td><td>' + esc(d.role) + '</td></tr>',
        '<tr><td style="padding:4px 12px 4px 0;color:#6B6B70;">Organisation</td><td>' + esc(d.org) + '</td></tr>',
        '<tr><td style="padding:4px 12px 4px 0;color:#6B6B70;">Email</td><td><a href="mailto:' + esc(d.email) + '">' + esc(d.email) + '</a></td></tr>',
        '<tr><td style="padding:4px 12px 4px 0;color:#6B6B70;">Centre</td><td>' + esc(d.centre_name || d.centre) + '</td></tr>',
        '<tr><td style="padding:4px 12px 4px 0;color:#6B6B70;">Date</td><td>' + esc(d.date_display || d.date) + '</td></tr>',
        '<tr><td style="padding:4px 12px 4px 0;color:#6B6B70;">Theme</td><td>' + esc(d.theme || '') + '</td></tr>',
        '<tr><td style="padding:4px 12px 4px 0;color:#6B6B70;">Reference</td><td><code>' + esc(id) + '</code></td></tr>',
        '</table>'
      ].join('')
    });
  } catch (e) { /* non-fatal */ }
}

// ─── Auth + utility ──────────────────────────────────────────────────

function authorized(e) {
  const key = props().getProperty('SHARED_KEY');
  if (!key) return true; // dev mode — no key set
  return paramOf(e, 'key') === key;
}
function authorizedBody(body) {
  const key = props().getProperty('SHARED_KEY');
  if (!key) return true;
  return body && body.key === key;
}

function paramOf(e, name) { return (e && e.parameter && e.parameter[name]) || ''; }
function props() { return PropertiesService.getScriptProperties(); }
function sanitize(s, max) {
  if (s === undefined || s === null) return '';
  return String(s).replace(/<[^>]*>/g, '').replace(/[\u0000-\u0008\u000B-\u001F]/g, '').slice(0, max || 500);
}
function isValidEmail(v) { return /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i.test(String(v || '').trim()); }
function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function messageOf(err) { return String(err && err.message || err || 'Unknown error'); }

function ok(data) {
  return ContentService.createTextOutput(JSON.stringify({ ok: true, data: data || null }))
    .setMimeType(ContentService.MimeType.JSON);
}
function fail(error, code) {
  return ContentService.createTextOutput(JSON.stringify({ ok: false, error: String(error), code: code || 400 }))
    .setMimeType(ContentService.MimeType.JSON);
}

// ─── Self-test (run from the Apps Script editor to validate setup) ───

function selfTest() {
  const issues = [];
  if (!props().getProperty('SHEET_ID')) issues.push('SHEET_ID missing in Script Properties.');
  if (!props().getProperty('SHARED_KEY')) issues.push('SHARED_KEY missing in Script Properties.');
  let ss = null;
  try {
    ss = SpreadsheetApp.openById(props().getProperty('SHEET_ID') || '');
  } catch (e) { issues.push('Cannot open SHEET_ID — check it and your share permissions.'); }
  if (ss) {
    [TAB_SUBMISSIONS, TAB_SUBSCRIBERS, TAB_RESERVATIONS, TAB_DONATIONS, TAB_MILESTONES].forEach(function (t) {
      const headers = t === TAB_SUBMISSIONS ? HEADERS_SUBMISSIONS
                    : t === TAB_SUBSCRIBERS ? HEADERS_SUBSCRIBERS
                    : t === TAB_RESERVATIONS ? HEADERS_RESERVATIONS
                    : t === TAB_DONATIONS    ? HEADERS_DONATIONS
                    : HEADERS_MILESTONES;
      try { getOrCreateSheet(t, headers); }
      catch (e) { issues.push('Cannot create/access tab "' + t + '": ' + messageOf(e)); }
    });
  }
  return { ok: issues.length === 0, issues: issues };
}

// ─────────────────────────────────────────────────────────────────────
// Reminder system — weekly cron that emails confirmed speakers in the
// run-up to their session. Plain English, short paragraphs, and direct
// about the commitment they've made. Each reminder names the thing they
// said yes to and reminds them — clearly — that people are counting on it.
//
// Sponsors who also pledged get one extra line that names what they
// committed to give, so the appointment carries both gestures forward.
//
// Setup (one time):
//   • From the Apps Script editor, run setupReminderTrigger() once.
//   • That installs a weekly time-based trigger at ~07:00 Lagos time
//     every Wednesday.
//   • Each run sends what's due for the current week (T-28 / T-21 /
//     T-14 / T-7) and records the stage code back to the speaker's row
//     so the same reminder never fires twice.
//
// To remove: removeReminderTrigger().
// To preview without sending: previewReminders() logs to the Stackdriver
//   console and writes nothing.
// ─────────────────────────────────────────────────────────────────────

// Reminder stages, in days-until-session. Order matters: nearest first
// so we send the most relevant single reminder per run if multiple are
// somehow due (e.g. catch-up after an outage). Windows are wide enough
// that a weekly cron will always land at least one of them per speaker
// in the four weeks before the date.
const REMINDER_STAGES = [
  { code: 'T7',  minDays: 0,   maxDays: 10,  buildSubject: rWeekSubject,        buildBody: rWeekBody        },
  { code: 'T14', minDays: 10,  maxDays: 17,  buildSubject: rTwoWeeksSubject,    buildBody: rTwoWeeksBody    },
  { code: 'T21', minDays: 17,  maxDays: 24,  buildSubject: rThreeWeeksSubject,  buildBody: rThreeWeeksBody  },
  { code: 'T28', minDays: 24,  maxDays: 32,  buildSubject: rMonthSubject,       buildBody: rMonthBody       }
];

/**
 * Install the weekly trigger. Idempotent — removes any existing reminder
 * trigger first, then installs a fresh one every Wednesday 07:00 Lagos.
 */
function setupReminderTrigger() {
  removeReminderTrigger();
  ScriptApp.newTrigger('sendWeeklyReminders')
    .timeBased()
    .onWeekDay(ScriptApp.WeekDay.WEDNESDAY)
    .atHour(7)
    .inTimezone('Africa/Lagos')
    .create();
  return { ok: true, message: 'Weekly reminder trigger installed (Wednesdays 07:00 Africa/Lagos).' };
}

function removeReminderTrigger() {
  const triggers = ScriptApp.getProjectTriggers();
  let removed = 0;
  triggers.forEach(function (t) {
    const fn = t.getHandlerFunction();
    if (fn === 'sendWeeklyReminders' || fn === 'sendDailyReminders') {
      ScriptApp.deleteTrigger(t); removed++;
    }
  });
  return { ok: true, removed: removed };
}

/**
 * Weekly cron entry point. Reads the Submissions sheet, decides which
 * reminder (if any) is due for each speaker, sends it, and writes the
 * stage code back to the "Reminders Sent" column.
 *
 * sendDailyReminders is kept as an alias so existing manual triggers /
 * web-app links continue to work after the daily → weekly switch.
 */
function sendDailyReminders() { return sendWeeklyReminders(); }
function sendWeeklyReminders() {
  const sheet = getOrCreateSheet(TAB_SUBMISSIONS, HEADERS_SUBMISSIONS);
  const last = sheet.getLastRow();
  if (last < 2) return { ok: true, sent: 0, skipped: 0, message: 'No submissions.' };

  const range = sheet.getRange(2, 1, last - 1, HEADERS_SUBMISSIONS.length);
  const values = range.getValues();
  const remCol = HEADERS_SUBMISSIONS.indexOf('Reminders Sent') + 1;

  const now = new Date();
  let sent = 0, skipped = 0, errors = 0;

  for (let i = 0; i < values.length; i++) {
    const row = values[i];
    const rowIndex = i + 2;
    try {
      const speaker = parseSubmissionRow(row);
      if (!speaker || !speaker.email) { skipped++; continue; }
      if (!isValidEmail(speaker.email)) { skipped++; continue; }
      // Gate on the team's manual verification — pending / declined /
      // cancelled rows never get reminders. The team flips the cell to
      // 'confirmed' once they've spoken with the speaker.
      if (speaker.confirmed !== 'confirmed') { skipped++; continue; }

      const sessionDate = parseSessionDate(speaker.dateDisplay, speaker.dateId);
      if (!sessionDate) { skipped++; continue; }

      const daysUntil = (sessionDate.getTime() - now.getTime()) / (1000 * 60 * 60 * 24);
      if (daysUntil < -1) { skipped++; continue; } // session is in the past

      const alreadySent = String(speaker.remindersSent || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
      const due = pickDueReminder(daysUntil, alreadySent);
      if (!due) { skipped++; continue; }

      const subject = due.buildSubject(speaker, daysUntil);
      const body = due.buildBody(speaker, daysUntil, sessionDate);

      MailApp.sendEmail({
        to: speaker.email,
        subject: subject,
        htmlBody: body,
        name: 'Street-To-Stardom 2026',
        replyTo: props().getProperty('ADMIN_EMAIL') || ''
      });

      // Record the stage code back so we don't double-send.
      alreadySent.push(due.code);
      sheet.getRange(rowIndex, remCol).setValue(alreadySent.join(','));
      sent++;
    } catch (e) {
      errors++;
      console.error('[STS reminder] row ' + rowIndex + ' · ' + messageOf(e));
    }
  }
  return { ok: true, sent: sent, skipped: skipped, errors: errors };
}

/**
 * Dry-run — log what WOULD be sent without sending anything or writing
 * back. Useful from the editor to test your tone changes safely.
 */
function previewReminders() {
  const sheet = getOrCreateSheet(TAB_SUBMISSIONS, HEADERS_SUBMISSIONS);
  const last = sheet.getLastRow();
  if (last < 2) { console.log('No submissions.'); return; }
  const values = sheet.getRange(2, 1, last - 1, HEADERS_SUBMISSIONS.length).getValues();
  const now = new Date();

  values.forEach(function (row, i) {
    const speaker = parseSubmissionRow(row);
    if (!speaker || !speaker.email) return;
    const sessionDate = parseSessionDate(speaker.dateDisplay, speaker.dateId);
    if (!sessionDate) { console.log('row ' + (i+2) + ' · ' + speaker.email + ' · could not parse date: ' + speaker.dateDisplay); return; }
    const daysUntil = (sessionDate.getTime() - now.getTime()) / (1000 * 60 * 60 * 24);
    const alreadySent = String(speaker.remindersSent || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    const due = pickDueReminder(daysUntil, alreadySent);
    const gated = speaker.confirmed !== 'confirmed';
    console.log('row ' + (i+2) + ' · ' + speaker.email +
      ' · confirmed=' + speaker.confirmed +
      ' · days=' + daysUntil.toFixed(2) +
      ' · sent=' + (alreadySent.join(',') || '(none)') +
      ' · due=' + (due ? due.code : '(none)') +
      (gated && due ? ' · GATED (not confirmed)' : ''));
  });
}

// ─── Reminder helpers ───────────────────────────────────────────────

function parseSubmissionRow(row) {
  return {
    timestamp:    row[0],
    id:           row[1],
    honorific:    row[2],
    name:         row[3],
    role:         row[4],
    org:          row[5],
    email:        String(row[6] || '').trim(),
    whatsapp:     row[7],
    centreId:     row[8],
    centreName:   row[9],
    centreFocus:  row[10],
    dateId:       row[11],
    dateDisplay:  row[12],
    theme:        row[13],
    seriesTheme:  row[14],
    notes:        row[15],
    hasPhoto:     row[16],
    donated:      String(row[17] || '').toLowerCase(),
    donationTotal: parseInt(row[18], 10) || 0,
    donationRef:  row[19],
    remindersSent: row[20],
    confirmed:    String(row[21] || 'pending').toLowerCase()
  };
}

/**
 * Robustly parse the session date. The form writes "Saturday, Aug 15, 2026"
 * to the Date column; we try that first, then fall back to deriving from
 * the Date ID (e.g. "w3d1") using the published 2026 schedule.
 */
function parseSessionDate(dateDisplay, dateId) {
  // Try the display string directly — JS understands "Aug 15, 2026"
  // but chokes on the leading "Saturday, " in some runtimes.
  const display = String(dateDisplay || '').trim();
  if (display) {
    const stripped = display.replace(/^[A-Za-z]+,\s*/, ''); // drop "Saturday, "
    const t = Date.parse(stripped);
    if (!isNaN(t)) {
      const d = new Date(t);
      d.setHours(9, 0, 0, 0); // session starts 09:00
      return d;
    }
  }
  // Fallback — date IDs are weekly: w1d1=Aug 1 (Sat), w1d2=Aug 2 (Sun), …
  const m = /^w(\d)d(\d)$/.exec(String(dateId || '').trim());
  if (m) {
    const week = parseInt(m[1], 10);
    const day  = parseInt(m[2], 10);
    // Saturdays of Aug 2026: 1, 8, 15, 22, 29
    const saturdays = [1, 8, 15, 22, 29];
    const dayOfMonth = saturdays[week - 1] + (day - 1);
    if (dayOfMonth >= 1 && dayOfMonth <= 31) {
      return new Date(2026, 7, dayOfMonth, 9, 0, 0); // month is 0-indexed
    }
  }
  return null;
}

function pickDueReminder(daysUntil, alreadySent) {
  for (let i = 0; i < REMINDER_STAGES.length; i++) {
    const s = REMINDER_STAGES[i];
    if (daysUntil >= s.minDays && daysUntil <= s.maxDays && alreadySent.indexOf(s.code) === -1) {
      return s;
    }
  }
  return null;
}

// ─── Reminder copy ──────────────────────────────────────────────────
// Plain English. Short sentences. The commitment is named clearly each
// time — what the speaker said yes to, who is counting on them, and
// (for sponsors) what they also promised to give. No CTAs, no asks.

function firstName(speaker) {
  return String(speaker.name || '').trim().split(/\s+/)[0] || 'Friend';
}
function honoured(speaker) {
  const h = speaker.honorific ? String(speaker.honorific).trim() + ' ' : '';
  return h + firstName(speaker);
}
function dateLong(d) {
  const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  return days[d.getDay()] + ', ' + d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
}
function dayOfWeek(d) {
  return ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][d.getDay()];
}

/**
 * Direct commitment line. Names what they agreed to. Mentions the
 * sponsorship too when there is one.
 */
function commitmentLine(speaker) {
  if (speaker.donated === 'yes' || speaker.donated === 'committed') {
    if (speaker.donationTotal > 0) {
      return 'You committed to two things: to speak to the children at <strong>' + esc(speaker.centreName) + '</strong>, and to help equip them with their Summer Packs (<strong>₦' + Number(speaker.donationTotal).toLocaleString() + '</strong>). We are counting on both.';
    }
    return 'You committed to two things: to speak to the children at <strong>' + esc(speaker.centreName) + '</strong>, and to help equip them with their Summer Packs. We are counting on both.';
  }
  return 'You committed to be there. The children are counting on you.';
}

// ── T-28 — "about a month" ─────────────────────────────────────────
function rMonthSubject(s) { return 'About a month away, ' + firstName(s) + '.'; }
function rMonthBody(s, days, d) {
  return reminderShell({
    speaker: s,
    eyebrow: 'ABOUT A MONTH OUT  ·  STREET-TO-STARDOM 2026',
    h1: 'About a month away.',
    paragraphs: [
      'Dear ' + honoured(s) + ',',
      'Your session at <strong>' + esc(s.centreName) + '</strong> is on <strong>' + esc(dateLong(d)) + '</strong>. That is about a month away. The theme for the room is <em>' + esc(s.theme || 'your address') + '</em>.',
      commitmentLine(s),
      'You do not need to do anything yet. We will send a short personalised brief closer to the date. If something changes on your side, please write to ' + esc(props().getProperty('ADMIN_EMAIL') || 'cacentre@afrovanguard.org.ng') + ' as soon as you can.',
      'Thank you again for saying yes.<br>The 2026 Series Team'
    ]
  });
}

// ── T-21 — "three weeks" ───────────────────────────────────────────
function rThreeWeeksSubject(s) { return 'Three weeks to go, ' + firstName(s) + '.'; }
function rThreeWeeksBody(s, days, d) {
  return reminderShell({
    speaker: s,
    eyebrow: 'THREE WEEKS  ·  STREET-TO-STARDOM 2026',
    h1: 'Three weeks to go.',
    paragraphs: [
      'Dear ' + honoured(s) + ',',
      'Three weeks until <strong>' + esc(dateLong(d)) + '</strong> at <strong>' + esc(s.centreName) + '</strong>. The team is already lining up the room and telling the children who is coming.',
      commitmentLine(s),
      'If you can, start thinking about one story from your own life that fits the theme: <em>' + esc(s.theme || '—') + '</em>. The children remember stories, not slides.',
      'If anything has changed, tell us now while we still have time. Write to ' + esc(props().getProperty('ADMIN_EMAIL') || 'cacentre@afrovanguard.org.ng') + '.',
      'Thank you.<br>The 2026 Series Team'
    ]
  });
}

// ── T-14 — "two weeks" ─────────────────────────────────────────────
function rTwoWeeksSubject(s) { return 'Two weeks, ' + firstName(s) + '. Please confirm you are still on.'; }
function rTwoWeeksBody(s, days, d) {
  return reminderShell({
    speaker: s,
    eyebrow: 'TWO WEEKS  ·  STREET-TO-STARDOM 2026',
    h1: 'Two weeks to go.',
    paragraphs: [
      'Dear ' + honoured(s) + ',',
      'Two weeks until your session. <strong>' + esc(dateLong(d)) + '</strong> at <strong>' + esc(s.centreName) + '</strong>. The theme is <em>' + esc(s.theme || '—') + '</em>. The session runs from <strong>9:00 to 10:00 AM</strong>, with a 30-minute mentorship circle after.',
      commitmentLine(s),
      'A simple ask: please reply to this email and confirm you are still on. A one-line "yes, still on" is enough. If you need to move the date, we need at least seven days to reset the room — please tell us today.',
      'Thank you.<br>The 2026 Series Team'
    ]
  });
}

// ── T-7 — "one week" ───────────────────────────────────────────────
function rWeekSubject(s) { return 'One week, ' + firstName(s) + '. The room is being set.'; }
function rWeekBody(s, days, d) {
  return reminderShell({
    speaker: s,
    eyebrow: 'ONE WEEK  ·  STREET-TO-STARDOM 2026',
    h1: 'One week to go.',
    paragraphs: [
      'Dear ' + honoured(s) + ',',
      'One week until <strong>' + esc(dateLong(d)) + '</strong>. The children at <strong>' + esc(s.centreName) + '</strong> have been told you are coming. Theme for the room: <em>' + esc(s.theme || '—') + '</em>.',
      commitmentLine(s),
      'Practical notes for the morning:',
      '<strong>Arrive at 8:30 AM.</strong> The team will meet you at the gate. The address is from 9:00 to 10:00 AM, then a 30-minute mentorship circle. Bring nothing — we have water, a microphone, and a chair.',
      'We will send the personalised brief 48 hours before the session. If anything has changed, write to ' + esc(props().getProperty('ADMIN_EMAIL') || 'cacentre@afrovanguard.org.ng') + ' today.',
      'See you on ' + esc(dayOfWeek(d)) + '.<br>The 2026 Series Team'
    ]
  });
}

/**
 * Shared HTML shell — table-based, inline-styled, dark-mode-safe.
 * Mirrors the brand grammar of the speaker-confirmation email so the
 * speaker recognises it as part of the same correspondence.
 */
function reminderShell(opts) {
  const blocks = opts.paragraphs.map(function (p) {
    return '<p style="font-size:15px;line-height:1.65;margin:0 0 16px 0;color:#3c3c46;">' + p + '</p>';
  }).join('');
  return [
    '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">',
    '<style>',
    '  body{margin:0;padding:0;background:#f4f4f6;font-family:Montserrat,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;color:#0A0A0F;}',
    '  .wrap{max-width:600px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;}',
    '  .band{background:linear-gradient(135deg,#0732F7,#C8102E);padding:18px 32px;}',
    '  .band-h{color:#fff;font-size:18px;font-weight:800;letter-spacing:-0.01em;margin:0;}',
    '  .band-sub{color:rgba(255,255,255,0.78);font-size:10.5px;letter-spacing:0.18em;font-weight:600;text-transform:uppercase;margin-top:2px;}',
    '  .section{padding:34px 36px 28px;}',
    '  .eyebrow{display:inline-block;font-size:10px;font-weight:700;letter-spacing:0.18em;color:#0420B5;background:#EEF1FF;padding:6px 10px;border-radius:999px;margin-bottom:18px;text-transform:uppercase;}',
    '  h1{font-family:"Playfair Display",Georgia,serif;font-size:28px;line-height:1.18;margin:0 0 22px 0;color:#0A0A0F;}',
    '  .foot{background:#fafafb;border-top:1px solid #ecedef;padding:18px 32px;font-size:11px;color:#6b7280;text-align:center;line-height:1.55;}',
    '  .foot a{color:#0420B5;text-decoration:none;}',
    '  @media (prefers-color-scheme:dark){',
    '    body{background:#0B0B12 !important;}',
    '    .wrap{background:#14141C !important;}',
    '    h1{color:#fff !important;}',
    '    p{color:#cfd0d6 !important;}',
    '    .eyebrow{background:rgba(81,114,255,0.16) !important;color:#A8BBFF !important;}',
    '    .foot{background:#0B0B12 !important;border-top-color:#23232f !important;color:#94949c !important;}',
    '  }',
    '</style></head><body>',
    '<div style="padding:32px 12px;">',
    '<div class="wrap">',
    '  <div class="band">',
    '    <div class="band-h">STREET-TO-STARDOM</div>',
    '    <div class="band-sub">2026 SERIES · EMPOWERED 360°</div>',
    '  </div>',
    '  <div class="section">',
    '    <span class="eyebrow">' + esc(opts.eyebrow) + '</span>',
    '    <h1>' + esc(opts.h1) + '</h1>',
    blocks,
    '  </div>',
    '  <div class="foot">',
    '    Street-To-Stardom · An Afrovanguard initiative · Alimosho, Lagos<br>',
    '    Replying to this email reaches the 2026 Series Team directly.',
    '  </div>',
    '</div>',
    '</div>',
    '</body></html>'
  ].join('');
}
