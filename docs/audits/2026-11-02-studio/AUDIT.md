# The Studio: an audit

2 November 2026, against `b7f7323`. Read with `admin/api.php`,
`admin/app.js` and `admin/index.php` open.

## What it is

One endpoint (`admin/api.php`, 2,160 lines, **236 actions**), one SPA
(`admin/app.js`, 4,772 lines), one page of markup (`admin/index.php`,
1,842 lines) and 21 top-level tabs. Three access levels: editor < admin <
superadmin.

## The shape of the problem

**Four hand-maintained lists of the same names, and nothing reconciles
them.**

| # | Where | What it holds | Length |
|---|---|---|---|
| 1 | `admin/index.php:36-96` | the nav — 21 `<button data-tab>` with inline SVG | 21 |
| 2 | `admin/app.js:12-28` `TAB_MIN` | which role may SEE each tab | 21 |
| 3 | `admin/api.php:117-148` `$superadminOnly` + `$managementOnly` | which role may CALL each action | 32 + 145 |
| 4 | `admin/api.php:91-104` `$writing` | which actions need a CSRF token | ~60 |

The portal solved exactly this with one registry (`PortalNav::SECTIONS`,
everything derived). The Studio has not, and the drift is already here.

List 4 has **already caused a defect** and the code says so: new actions
were "accepted with no CSRF token at all" until `if ($method === 'POST')
$writing = true;` was added as a backstop (`admin/api.php:105-109`). The
list survives, now nearly dead, still inviting maintenance.

---

## How this was confirmed

Everything below was **exercised, not read**. A real editor was created
(`probe.editor@afrovanguard.org.ng` in `lms_users` + `admin_users` at role
`editor`), signed in through `LmsAuth`, bridged to an admin session, and
**enrolled and passed the TOTP second factor** — the Studio reported
`role: "editor", held: "editor"`. Every call below went over HTTP to a
running server with that session's cookies and CSRF token.

The control calls refused exactly as they should, which is what makes the
rest meaningful:

```
roster_list         403  Editors can manage content only.
dues_settings       403  Editors can manage content only.
member_verify_list  403  Editors can manage content only.
admins_list         403  That action needs a Super Admin.
```

Two findings in the first draft of this audit were **wrong**, and are
corrected below: see S2 and the note on the `$writing` list.

## S1 · An editor can change what members are charged — HIGH · **CONFIRMED**

`ngv_save` is in **neither** permission list, so the editor level can call
it (`admin/api.php:1210`). It writes the public NextGen Vanguard page.

`NgvLedger::amounts()` **parses the fee figures off that page**
(`lib/NgvLedger.php:343-350`) — deliberately, so the ledger and the website
cannot disagree. The consequence was not intended: a content editor can
change the membership fee, the commitment fee and every plan's programme
fee, and the audit line reads only *"NextGen Vanguard page updated"*
(`admin/api.php:1215`).

The design decision that money comes from the page is sound. The gate on
who may edit the page is not.

**Exercised.** As the editor, over HTTP:

```
POST ngv_save {"content":{"fees":[{"name":"Membership fee","amount":"₦99,000 / year"}, …]}}
  → HTTP 200 ok=true

NgvLedger::amounts() before : membership = ₦10,000 / year   (source: page)
NgvLedger::amounts() after  : membership = ₦99,000 / year   (source: page)
                              amount: 99000
```

The charge moved. A content editor set the membership fee to ₦99,000 and
the ledger read it as live. Restored to ₦10,000 immediately afterwards and
verified.

**Fix:** put `ngv_save` in `$managementOnly`, or split the fee block from
the marketing copy and gate the fee block alone.

## S2 · Hiding a tab is not gating it — MEDIUM · **CONFIRMED, with a correction**

Six actions where the browser requires `admin` and the server accepts an
editor:

```
cel_list / cel_save / cel_delete      tab 'celebrations' → admin in TAB_MIN
comm_list / comm_save / comm_delete   tab 'communities'  → admin in TAB_MIN
```

**Correction to the first draft.** I wrote that `cel_list` "returns every
member's celebration record". **That was wrong.** The `celebrations` table
holds the organisation's *calendar* — key, name, month-day, emoji, theme,
message (`lib/celebrations.php:84-94`). No member data, no PII. The finding
is a permissions inconsistency, not an exposure, and it is weaker than I
first stated.

**What is confirmed.** As the editor, over HTTP:

```
cel_list    HTTP 200 ok=true
comm_list   HTTP 200 ok=true
cel_save    HTTP 200 ok=true   celebration rows   0 → 1
comm_save   HTTP 200 ok=true   communities        0 → 1
```

Both writes **landed in the database**. An editor created a celebration and
a community space — records the browser hides behind `admin`. Both removed
afterwards and verified.

(My first attempt at these writes came back 422 on validation, which proves
only that the request reached the handler. Valid payloads were needed to
show the write actually lands, and they do.)

**Fix:** add all six to `$managementOnly`, then make the drift impossible
(S5).

## S3 · The one AI action an editor can reach is the one with no rate limit — MEDIUM · **CONFIRMED, scope narrowed**

`guide_ask` calls a paid provider (`AvRouter::complete`, `admin/api.php:554`),
passes the client's `history` array through **uncapped** in count and
length (`:549-553`), and has **no `av_rate_ok()`**. Every comparable action
does:

```
ai_chat      90 / 300s   (admin/api.php:899)
ai_run       60 / 300s   (:888)
brief_run     6 / 600s   (:640)
setup_test   30 / 300s   (:841)
guide_ask    —           (:538)
```

It is also in neither permission list, so it is the editor level's.

**Exercised**, as the editor:

```
25 consecutive guide_ask calls   → {"200": 25}      no 429, no limiter
history of 400 turns × 2,000 chars (~800KB) → HTTP 200 ok=true   no cap
```

**Scope narrowed, honestly.** In this environment `AvRouter::available()`
is false — the response carries `configured: false` — so no provider was
called and **no money was spent**. What is proven is that the limiter and
the cap are absent; the spend only follows on a deployment that has a
provider key set. The missing guard is real; the bill is conditional on
configuration I could not test here.

**Fix:** a rate limit and a cap on `history` (last N turns, M characters).

## S4 · 59 actions are in neither list — MEDIUM · **CONFIRMED**

Most are legitimately the editor's: `cms_*`, `art_*`, `media_*`, `mod_*`,
`ac_*`, `lesson_*`, the diary. But the set also contains `ngv_save` (S1),
`cel_*` and `comm_*` (S2), `guide_ask` (S3), `diary_import_wp` and
`media_start/chunk/finish`.

The problem is the **default**: an action absent from both lists is
editor-callable. A new action is open until somebody remembers to close it.
That is backwards for an admin API.

**Exercised**, as the editor:

```
media_list      HTTP 200 ok=true
mod_queue       HTTP 200 ok=true
ac_list         HTTP 200 ok=true
cms_overview    HTTP 200 ok=true
dashboard       HTTP 200 ok=true
ngv_get         HTTP 200 ok=true
diary_import_wp HTTP 400 "No export file uploaded."  ← reached the handler
```

`diary_import_wp` first answered **403**, which I took for a role refusal.
It was not: it is in the `$writing` CSRF list, and my GET carried no CSRF
header. With the header it reaches its own handler and refuses on the
missing file. **It is editor-reachable.**

That also **corrects a claim in the first draft**: the `$writing` list is
not "nearly dead". The `method === 'POST'` backstop covers POSTs, but the
list is still the only thing forcing a CSRF token on a GET-shaped action.
Removing it would weaken the API.

**Fix:** default-deny. Name the editor's actions explicitly in an
`$editorOk` list and refuse anything in none of the three.

## S5 · Nothing tests that the four lists agree — MEDIUM

`grep -rl TAB_MIN tests/` returns nothing. S1–S4 are all instances of the
same missing test.

**Fix:** one test that parses `TAB_MIN` and the API arrays and asserts, per
action, that the client's requirement is **at least** the server's. It
would have caught all six of S2 and flagged S1.

## S6 · The nav has no accessible state — LOW-MEDIUM

24 `class="tab" data-tab` buttons in `admin/index.php`, **0** carrying any
`aria-` attribute. No `role="tab"`, no `aria-selected`, no
`aria-current="page"`; the active tab is a CSS class only. The *sub*tabs
inside Members do have `role="tab"`, so the pattern is known — it just was
not applied to the main nav.

## S7 · 4,772 lines in one script, no modules — LOW (compounding)

`admin/app.js` is every screen's logic in one IIFE with one `$` helper and
shared mutable state (`memOpen`, `memSel`, `memPage`, `MCLS`…). It works;
it is also where a change to one screen can break another, and it is the
reason a new section is four edits in three files rather than one entry in
a registry.

---

## What is good, and should not be lost

- **The MFA ordering is deliberate and documented** — `mfa_setup` and
  `mfa_verify` run before `require_admin()` because the session they act on
  is precisely the one holding a role without the factor; they stand on the
  role, same-origin and CSRF instead (`admin/api.php:66-89`).
- **The CSRF backstop** (`$method === 'POST'` ⇒ writing) is the right
  correction to a list-shaped bug, and the comment explains the failure
  rather than hiding it.
- **`session` returns both `held` and `role`**, so the Studio can say "you
  are an admin and have not finished signing in" rather than "unauthorized".
- The permission lists carry *reasons* in comments ("Seat claims carry
  names, emails and phone numbers"), which is what makes an audit like this
  possible at all.

## Reservations

- **S1–S4 are now exercised, not inferred** — a real editor session,
  second factor passed, calls over HTTP, database checked before and after,
  everything restored. Two claims in the first draft did not survive that:
  `cel_list` holds no member data, and the `$writing` list is not vestigial.
  Both are corrected in place above rather than quietly dropped.
- **S6 and S7 are still read, not measured.** The `aria` count is a grep;
  I have not run an accessibility tool over the Studio or profiled app.js.
- **"Editor" may have no holders.** `admin_users` was empty of editors
  before I created one for this audit. If every Studio account is admin or
  superadmin today, S1–S4 are **latent rather than live** — and serious the
  day somebody is given the editor level, which is the day they are least
  likely to be re-audited. This is the single most important caveat on this
  document: the vulnerability is in the design, not necessarily in the
  current roll.
- **The fix for S5 is small; the fix for the shape is not.** One registry
  for the Studio, with the nav, the client gate and the server gate all
  derived, is the right answer and is a substantial refactor of a 4,772-line
  file. I would do S1, S2, S3 and S5 first — they are hours — and treat the
  registry as its own piece of work.
- **I have not audited** the Studio's UX, its empty/error states, the
  Database tab's migration jobs, or the AI Ops surface. This is a
  permissions-and-structure audit.
