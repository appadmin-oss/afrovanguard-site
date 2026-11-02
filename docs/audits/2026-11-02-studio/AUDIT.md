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

## S1 · An editor can change what members are charged — HIGH

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

**Fix:** put `ngv_save` in `$managementOnly`, or split the fee block from
the marketing copy and gate the fee block alone.

## S2 · Hiding a tab is not gating it — MEDIUM-HIGH

Six actions where the browser requires `admin` and the server accepts an
editor:

```
cel_list / cel_save / cel_delete      tab 'celebrations' → admin in TAB_MIN
comm_list / comm_save / comm_delete   tab 'communities'  → admin in TAB_MIN
```

`cel_list` returns every member's celebration record
(`admin/api.php:294-298`); `comm_save` writes community spaces
(`admin/api.php:318`). The tab is hidden, so nobody sees it by accident —
but hiding is a client-side convenience and the API is the boundary. An
editor session with an HTTP client has them.

**Fix:** add all six to `$managementOnly`, then make the drift impossible
(S5).

## S3 · The one AI action an editor can reach is the one with no rate limit — MEDIUM

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

**Fix:** a rate limit and a cap on `history` (last N turns, M characters).

## S4 · 59 actions are in neither list — MEDIUM

Most are legitimately the editor's: `cms_*`, `art_*`, `media_*`, `mod_*`,
`ac_*`, `lesson_*`, the diary. But the set also contains `ngv_save` (S1),
`cel_*` and `comm_*` (S2), `guide_ask` (S3), `diary_import_wp` and
`media_start/chunk/finish`.

The problem is the **default**: an action absent from both lists is
editor-callable. A new action is open until somebody remembers to close it.
That is backwards for an admin API.

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

- **I have read the Studio, not exercised it.** S1–S3 are read off the
  source and the data flow; I have not signed in as an editor and called
  these endpoints. Each should be confirmed with a real editor session
  before being treated as proven.
- **"Editor" may have no holders.** If every Studio account is admin or
  superadmin today, S1–S4 are latent rather than live — serious the day
  somebody is given the editor level, which is the day they are least
  likely to be re-audited. Worth checking `AdminRoles` for who holds what.
- **The fix for S5 is small; the fix for the shape is not.** One registry
  for the Studio, with the nav, the client gate and the server gate all
  derived, is the right answer and is a substantial refactor of a 4,772-line
  file. I would do S1, S2, S3 and S5 first — they are hours — and treat the
  registry as its own piece of work.
- **I have not audited** the Studio's UX, its empty/error states, the
  Database tab's migration jobs, or the AI Ops surface. This is a
  permissions-and-structure audit.
