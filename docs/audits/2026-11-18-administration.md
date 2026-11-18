# Administration audit — 2026-11-18

How Afrovanguard is administered, compared with NGG's Control Room, CACENTRE's
admin, and the nonprofit systems an organisation like this would otherwise buy
(Salesforce Nonprofit Cloud, Bloomerang, Raiser's Edge NXT, Neon CRM, CiviCRM).
The question was not "is the code secure" (CODEBASE-AUDIT.md covers that) but
"can the organisation be run from here, by people other than its founder, as it
grows" — the five operating tests, applied to the admin itself.

## Verdict

Strong security foundations and a member desk close to NGG's quality. Weak on
three things that matter most as the organisation grows: **money** (no staff
desk for donations, and one money action with no named person), **structure**
(the list of Studio screens kept in three places), and **spread** (about six
separate staff consoles).

## What is good and must be kept

- Two-person rule on suspensions and ending dues (lib/Approvals.php), run as the
  requester, never self-approved. The same rule as NGG's.
- Offline payments: submitted by one person, verified by another.
- A second factor on every Studio role; admins must hold an org address.
- The member roster: server-side search, facets, paging, bulk with its guards.
- Site content: draft → submit → reject/publish/schedule, with restore and
  compare — ahead of NGG.
- An activity log with undo, and AI that only proposes until a person approves.

## Findings, most serious first

1. **Bank-transfer donations were recorded with the master emergency token**
   (`record_bank_transfer` in process-donation.php, from a form on the donor
   dashboard): no named person, no second factor, no second approver. Anyone who
   had once seen the token could add money to a campaign and send a receipt.
   **Fixed 2026-11-18** — see below.
2. **No donations or donors desk in the Studio.** Donations live in a display
   file that keeps the latest 500 (the idempotency table is the real ledger and
   cannot be browsed); appeals have their own console (give/manage.php). No
   donor record, gift history, retention view or Paystack reconciliation —
   standard in every comparator (batch gift entry, engagement and retention
   dashboards).
3. **The Studio's screens are listed in three places**: the sidebar
   (admin/index.php), the screen→role table (TAB_MIN in admin/app.js) and the
   server's gates (admin/api.php). NGG removed exactly this after it produced
   links that rendered as a 404.
4. **The server's role gate is a deny-list.** An action not named in
   `$managementOnly` is open to editors, so every new action is editor-reachable
   until somebody remembers to list it.
5. **Ranks cannot separate money from content.** "Admin" is people, events,
   webhooks and system at once. NGG keeps fees and the institutional record out
   of every bundle and grants them per person. The second permission model
   ("community clearance") flagged in CODEBASE-AUDIT M-1 is still in place.
6. **About six staff consoles** (the Studio, give/manage, ten NGV console pages,
   q.php, the mentorship desks), each with its own navigation; nothing finds a
   task across them.
7. **Screens cannot be linked.** Only the hash on first load is read; afterwards
   the screen lives in localStorage, so Back does not move between screens and
   "look at this" cannot be sent as a link.
8. **Two very large files**: 244+ actions in one switch (admin/api.php) and the
   whole Studio in one script (admin/app.js).
9. **The Overview counts content** rather than showing what is waiting on
   somebody today.

## Plan, in order

1. ✅ Bank transfers: named, two-person (2026-11-18).
2. ✅ One registry of Studio sections (`lib/StudioSections.php`): the sidebar
   is drawn from it and app.js reads each screen's role from it. The server's
   gate names what editors may do (`$editorAllowed`); an action in none of the
   three lists is refused below Super Admin. `tests/studioregistry.test.php`
   fails the build on an unassigned action, a section with no screen or loader,
   or a section hidden from editors whose actions are open to them. Fixed the
   drift it found: celebrations, communities and sign-in art were open to
   editors on the server while their screens were hidden (2026-11-18).
3. ✅ "Waiting on you" at the top of the Overview (`lib/StudioToday.php`):
   bank transfers to verify, offline payments to check, requests waiting for a
   second admin, member requests, members to verify, site changes to publish,
   posts and comments to moderate, AI suggestions to decide — each opening the
   screen (and member-desk pane) that resolves it, shown only to roles that
   may open that screen. A queue that cannot be counted is said, never shown
   as zero (2026-11-18).
4. A money desk (ledger view, donor record, reconciliation) and a `money`
   capability granted per person.
5. ✅ The screen in the address (`#members/offline`, Back/Forward work), and one
   search (Ctrl+K) across every screen, member-desk pane and staff console (2026-11-18).

## 1. Bank transfers — what changed

`lib/BankGifts.php`, Studio → Money → Bank transfers.

- **Recorded** by a named admin with what the statement shows, including the
  bank reference (unique: the same transfer cannot be entered twice) and the
  date it arrived. The gift is held: no total moves, no receipt goes out.
- **Verified** by a different admin — the server refuses the recorder
  (`own_submission`), and the emergency token is refused for both steps
  (`no_actor`): it is a credential, not a person, and letting it act would let
  one shared secret be both people. Claimed before it runs, so two presses count
  it once. Stored through `Donations::storeIfNew()` like every gift, receipted
  through the one receipt helper (`Donations::sendReceipt`), with both names on
  the donation record.
- **Rejected** needs a reason; **withdrawn** only by the recorder.
- `record_bank_transfer` and `verify_admin_token` answer **410** with the
  Studio's address. The donor dashboard no longer asks anyone for the token.
- Verified: `tests/bankgifts.test.php` (21 checks) and two real admins in
  Chromium with real second factors — record, refused own verify (also when
  sent by hand), verified by the other, totals moved, old endpoint refused.

## Sources

- Salesforce Nonprofit Cloud Winter/Spring '26 (DB Services; Idealist Consulting)
- Bloomerang donor retention and engagement (bloomerang.com; Tech Impact guide)
- G2 / TrustRadius comparisons of Raiser's Edge NXT, Neon CRM, CiviCRM
- NDPC, NDP Act GAID 2025 (ndpc.gov.ng); KPMG review of GAID 2025
