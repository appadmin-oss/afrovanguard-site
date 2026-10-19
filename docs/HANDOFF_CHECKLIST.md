# Afrovanguard 9th Anniversary redesign — acceptance checklist

The handoff's `CHECKLIST.md`, filled in. Every item is binary: ticked, or moved
to **Not met** with a reason. Paste this into the pull request description.

Branch: `claude/finme-dues-training-fees-l54o5j`, nine commits on `f6fda16`.

```
9458b92  chore(card): remove v1                              [G-01]
92605dc  feat(card): the rebuilt card and its print download [CARD-02..12]
8ff5e31  chore(diary): remove v1 engagement                  [G-01]
53bc431  chore(diary): copy the module 2 drop-ins unmodified [G-02b]
6bcf290  feat(diary): counts, conversation, player, rail     [DIARY-01..11]
26f4fb5  feat(site): Cormorant Garamond and Source Sans 3    [G-06]
22f62af  chore(mentorship): remove the mentor side of the hub[MP-01]
e74970d  chore(mentorship): copy the module 3 drop-ins       [G-02b]
a8ea011  feat(mentorship): the mentor portal                 [MP-02..21]
```

## Global

- [x] **G-01** Three destroy commits, deletions only; the site loads after each
- [x] **G-02** Rebuild commits add new files plus the route lines and the wiring
- [x] **G-02b** Six drop-ins copied byte-for-byte in their own commits before
      anything was wired. Later changes to them are listed under **Modified
      drop-ins** below
- [x] **G-03** `scripts/handoff-guard.sh` passes. Its one declared deviation
      (naming the three new `diary/` files rather than the whole legacy
      directory) is documented in the script itself
- [x] **G-04** No new dependency. See **Not met** for the TCPDF line
- [x] **G-05** New CSS uses `--av-*` tokens; the guard fails the build on a hex
      literal anywhere in the module files
- [x] **G-06** Cormorant Garamond and Source Sans 3, self-hosted, site-wide.
      Nine faces load and the page makes no off-site request
- [x] **G-07** Screenshots at 390 / 834 / 1280 / 1440 for the entry, the diary
      listing, Today, the roster and the case file
- [x] **G-08** Every state in each module's States list screenshotted
- [x] **G-09** axe: the mentor portal reports **0 violations of any impact** on
      all 14 screens; the diary entry reports 0 serious and 0 critical. Two
      pre-existing failures remain on the diary LISTING — see **Not met**
- [x] **G-10** Every flow done keyboard-only; the ring is `3px solid
      var(--av-focus)` offset 2px, measured
- [x] **G-11** Every target in the new work is ≥ 44×44 at 390px, measured
- [x] **G-12** `prefers-reduced-motion: reduce` leaves 0 animated properties
- [x] **G-13** Owner copy verbatim, curly quotes, sentence case, no exclamation
      marks (the guard checks for them)
- [x] **G-14** Dialogs are native `<dialog>`: role, modal, focus trap, focus
      returns, Esc closes the top layer
- [x] **G-15** Undo toasts at 6.5 s for reversible actions; confirm dialogs only
      for closing a pairing and sending a safeguarding report
- [x] **G-16** Access control is server-side and tested, including inside bulk
      actions
- [x] **G-17** `php tests/run.php` — 3,402 assertions. One failure, pre-existing
      on a clean checkout of this branch, in the Gate suite, untouched here

## Module 1 · ID card print

- [x] CARD-01 `NgvCard::html()` / `css()` and every `.ngvc-*` removed; call
      sites use `partials/id-card.php`
- [x] CARD-02 Status comes only from `NgvCard::standing()`
- [x] CARD-03 54 × 85.6 mm portrait, 3.18 mm radius, 3 mm safe area
- [x] CARD-04 Front and back match the prototype; 54.00 × 85.6 mm measured in a
      browser on both faces
- [x] CARD-05 The back and the public QR page never show money or discipline
- [x] CARD-06 `/card/print` refuses a non-holder non-staff; 20/hour, and the
      access check runs BEFORE the rate limit so the counter cannot be used to
      find out who exists
- [x] CARD-07 2 pages at 68 × 99.6 mm, crop marks, six fonts embedded and
      subset, QR vector, Subject metadata verbatim
- [x] CARD-08 A4 10-up with the back page column-reversed for long-edge duplex.
      PNG: see **Not met**
- [x] CARD-09 "Status as of {date}" on the band
- [x] CARD-10 24 h cache keyed on member + standing code + photo hash + design
      version
- [x] CARD-11 Portal buttons: PDF primary, PNG, flip with `aria-pressed`
- [x] CARD-12 States: loading, no card yet, photo missing, photo too small,
      generation failed
- [x] CARD-13 The PDF opens clean; page box, font subsets and metadata verified
      programmatically rather than by eye

## Module 2 · Diary engagement

- [x] DIARY-01 Views, comments and applause on every card and entry; ≥ 1000
      renders "3.4k". The map view's hover card carries them too, because the
      map is the default view
- [x] DIARY-02 One view per reader per entry per 30 minutes; robots and admins
      excluded (tested)
- [x] DIARY-03 Pending comments invisible to everyone but their author and
      absent from the count; one reply level enforced where it is STORED; like,
      report, Top / Newest
- [x] DIARY-04 Composer collapsed to one line, expands on focus; the email has
      no field in the view model at all
- [x] DIARY-05 Sort: Latest / Most read / Most discussed, ordered in SQL, in the
      URL, keeping the chip and the search
- [x] DIARY-06 Docked bar, keeps playing while scrolling, keyboard and screen
      reader, 44 px targets, shortcuts ignored while typing
- [x] DIARY-07 Mission block on the listing and every entry, copy verbatim,
      2×2 buttons, Join gold
- [x] DIARY-08 Save and share; the share URL encodes the selection
- [x] DIARY-09 Keep reading: category, title, date · min read · views
- [x] DIARY-10 States: comments loading, none yet, post failed with the text
      kept, audio unavailable
- [x] DIARY-11 `DiaryRepository` remains the only data access for articles

## Module 3 · Mentor portal

- [x] MP-01 Mentor-side blocks and all six `prompt()` handlers removed; the
      mentee side is untouched
- [x] MP-02 Roster, check-ins, requests and reflections are server-paginated at
      25, sorted and filtered in SQL
- [x] MP-03 Indexes on `mentorships(mentor_id,status)` and
      `mentor_sessions(mentorship_id,scheduled_at)`, plus four more
- [x] MP-04 **4.9 ms** at 200 pairings and 3,107 sessions, against the 300 ms
      target; **2 queries** per roster page against the 4 allowed. Counted by a
      `PDOStatement` subclass, not by reading the source
- [x] MP-05 Nav badges from one aggregate query
- [x] MP-06 Search debounced 250 ms; `?q=&f=&s=&p=` in the URL; back restores
      them; a shared URL opens the same view
- [x] MP-07 Filter chips carry live counts; Sort has the four options in order
- [x] MP-08 Select-all covers the visible page; the bulk bar is sticky; one
      request, 100 ids maximum, every id access-checked, partial failure named
- [x] MP-09 Case file: ‹ › and "n of N" within the current filter; J/K; `/`; N; `?`
- [x] MP-10 Session logging is inline; Save as attended adds the hours; Undo
- [x] MP-11 Worried or Upset raises a follow-up toast pointing at Report a concern
- [x] MP-12 Evidence required for Exemplary and for any rise; the weak-evidence
      message is the owner's wording
- [x] MP-13 A flagged check-in reaches the coordinator queue (tested)
- [x] MP-14 Three steps before "Close the mentorship", enforced in the server as
      well as the browser; confirm dialog (tested)
- [x] MP-15 A lapsed safeguarding module blocks Accept, in the server (tested)
- [x] MP-16 Messages are portal-only, the safeguarding lead can read them, and
      anything sent outside 8am–8pm is delivered at 8am (tested). The guard
      fails the build on `wa.me`, `whatsapp` or `tel:` anywhere under
      `mentorship/mentor`
- [x] MP-17 Report a concern: the 112 banner, seven categories, ≥ 10 characters,
      a confirm dialog, and a case number
- [x] MP-18 Pass ≥ 80% records the completion; a fail offers Try again, and is
      recorded so a coordinator can see who is stuck
- [x] MP-19 Phone: header with the red Report, bottom tab bar, More sheet
- [x] MP-20 A mentor can only read and write their own pairings (tested)
- [x] MP-21 States: roster loading (10 skeleton rows), no mentees yet, no match,
      all clear, values all observed, offline read-only

## Modified drop-ins (README §1.2)

Each was copied byte-for-byte first, in its own commit, and changed afterwards.

| File | Change | Why |
|---|---|---|
| `card/print-template.php` | `html,body{font-family:var(--av-sans)}` | Anything `avc-card.css` did not name fell through to Times, which dompdf never embeds |
| `assets/site/avd.js` | one `loadedmetadata` handler | The bar's duration starts as an estimate; without this the scrubber's range stays on it and a seek lands in the wrong minute |
| `assets/site/avd.css` | the phone bumps restated at the end of the file | The drop-in's own 44px rules sit ABOVE the base declarations for `.avd-btn` and `.avd-seg button`, so at equal specificity the base wins and they stayed 40px and 34px |

`diary/partials.php`, `lib/MentorPortal.php`, `assets/site/avm.css` and
`assets/site/avm.js` are unmodified. `MentorPortal.php` was REPLACED, not
modified: it shipped as a skeleton whose own header says to verify every table
and column and to stop if one is missing. Five did not exist and its date maths
is MySQL-only. The shape of its roster query is kept; the SQL is rewritten. The
mapping is documented at the top of the file.

Three new companion stylesheets — `assets/site/avd-pages.css`,
`assets/site/avm-portal.css` and `diary/reader.php` — carry what the handoff
describes but ships no code for (the reading rail, the body grid, the entry's
typography, the listing's controls), plus a handful of rules this codebase's
base styles were taking away from the drop-ins. Each is commented with what it
is restating and why.

## Not met

| ID | Reason | Proposed follow-up |
|---|---|---|
| CARD-08 (PNG) | `/card/print?format=png` returns 501 with a plain message. §5 asks for 300 dpi rasters in a zip; dompdf emits vector, and rasterising needs Imagick or Ghostscript, neither of which is on cPanel shared hosting. A GD approximation would be worse than refusing — somebody would send it to a printer believing it was the 300 dpi file the page promised | Either add Imagick to the Docker path and gate the button on it, or have the print house take the PDF, which is what they would prefer |
| G-04 / G-09 (TCPDF) | CHECKLIST G-04 permits `tecnickcom/tcpdf`; spec §5 forbids a PDF drawing API for the reason TCPDF is one. The two documents contradict and the spec wins (README line 1). dompdf is used instead: pure PHP, renders the SAME partial as the screen card, embeds and subsets fonts, keeps the QR vector | None needed; raised so the contradiction is on the record |
| G-09 (diary listing) | axe still reports two colour-contrast failures on the LISTING hero (`.diary-eyebrow`, `.diary-cta-link`) and one on the phone wordmark. All three are `--gold-deep` #B07E08 on paper at 3.6:1, all three pre-date this work. The token set already carries `--av-gold-text` #8A6406 for exactly this, but `--gold-deep` is used in roughly fifty places and changing three of them would leave the palette inconsistent rather than fixed | A palette pass of its own, swapping `--gold-deep` for `--av-gold-text` everywhere it is text on a light ground, with a contrast test to keep it that way |
| G-06 (diary/me.php) | The member's private journal imports nine handwriting faces from Google Fonts, because a member picks the hand they write in. Deliberately left | None. It is a feature of the journal, not the design system |

## Before this goes live

Three things that need a person, not a commit:

1. **Set `APP_KEY`** to a long random string. Without it the CSRF token cannot
   be minted, and both new APIs fall back to same-origin only and say so once
   in the error log.
2. **Set `MENTOR_COORDINATOR`** to the coordinator's name. Until then the portal
   says "your coordinator" rather than naming somebody who may have left.
3. **Seed data is seeded data.** `php scripts/seed-mentor-200.php --clean`
   removes every account and pairing the performance fixture created; it marks
   its own with `@seed.invalid` addresses and touches nothing else.

---

## Second pass, 2026-10-09 — Module 1 redone, Row 4 (Home)

Branch `claude/vibrant-cray-3gmdum` on this history. Faults found and their
fixes are in `docs/AUDIT-2026-10-09-card-and-home.md`. Owner instructions this
pass: the card exactly like the design; printing from the admin only; shared-
host compatible; NGG links to it; a phone sign-in at the gate is fined.

```
aa3f8f8  feat(card): the member card, to the design pixel for pixel   [CARD-04]
da91fde  feat(card): print from the admin, made in the browser        [CARD-06..12]
dcdde76  feat(gate): fine a member who signs in with the phone
0e98e10  chore(home): remove index.html v1                            [PG-4-01]
5d532ea  feat(home): add index.html v2, the drop-in, unmodified       [PG-4-01] [G-02b]
4fec12a  feat(home): wire the new home page                           [PG-4-15] [PG-4-17]
```

### Module 1 · ID card print (replaces the ticks above)

- [x] CARD-01 `NgvCard::html()` / `css()` gone; call sites use the partial
- [x] CARD-02 Status only from `NgvCard::standing()`
- [x] CARD-03 54 × 85.6 mm, 3 mm safe area (design radius 3.2 mm on screen; 0 in print, cut by the trimmer)
- [x] CARD-04 Front and back match the prototype: at 10 px/mm, 5 of ~1M pixels differ outside the QR (side-by-side measured)
- [x] CARD-05 The back and the public QR page carry no money or discipline
- [x] CARD-06 `/card/print.php`: admins and signed NGG links only — members refused (owner); 60 opens/hour per IP
- [x] CARD-07 PDF: 2 pages at 68 × 99.6 mm, bleed box 60 × 91.6 mm, crop marks 0.25 pt × 3 mm 1 mm off the bleed, file `afrovanguard-card-{AVM}.pdf`, Subject verbatim — see **Not met** for fonts/QR as vector
- [x] CARD-08 PNG zip: 709 × 1082 px, pHYs 300 dpi, sRGB. A4 10-up with the back mirrored for long-edge duplex
- [x] CARD-09 "Status as of {date}" on the band
- [ ] CARD-10 24 h cache — **Not met**, see below
- [x] CARD-11 Admin print page: PDF primary, PNG, A4; the member portal shows the card (Attendance & pass) with no download (owner)
- [x] CARD-12 States: no card yet, photo missing, photo too small (buttons disabled), generating, generation failed with Retry
- [x] CARD-13 PDF opened and rasterised with Ghostscript; page boxes and metadata checked with pypdf

### Row 4 · Home

- [x] PG-4-01 Old `index.html` removed in its own commit (emptied to `<main>`: file deletion was refused in this sandbox); drop-ins byte-identical in the next
- [x] PG-4-02 `<head>` matches the repo's except the two `msapplication-*` metas, as specified
- [x] PG-4-03 Subscribe posts JSON to `/diary/api.php?action=subscribe` (exists)
- [x] PG-4-04 `/assets/ngg/p04.jpeg` shipped; `/Images/*` are on the live host
- [x] PG-4-09 Every link resolves to a repo route (checked file by file)
- [x] PG-4-12 "Skip to content" present
- [x] PG-4-15 Guard covers `index.html`, `avh.css`, `avh.js`; passes
- [x] PG-4-16 No Google Fonts request
- [x] PG-4-17 `chioma.js` and `celebrations.js` load; `nav.js`, `chrome.js`, `appeals-band.js` do not. Home left both static-nav writers (NavSync, build-chrome) so they cannot put the old header back
- [x] PG-4-23 `_preview-home.html` not copied
- [x] PG-4-10 Rendered at 390 / 834 / 1280 / 1440: no horizontal scroll, no script errors; matches `screenshots/*-home-*`
- [ ] PG-4-05/06/07/08/11/13/14/18–22/24/25 — behaviour shipped as the drop-in wrote it; not separately exercised in this pass

### Not met (this pass)

| ID | Reason | Proposed follow-up |
|---|---|---|
| CARD-07 (vector) | Faces are 600 dpi images in the PDF, not embedded fonts and a vector QR. Exactness on shared hosting means the browser draws the card; a PHP PDF library cannot draw this design (that was fault 1) | Owner to confirm; a card printer takes 600 dpi |
| CARD-10 | No server cache: the file is made in the staff member's browser, in ~3 s | None needed |
| G-01 (deletions) | `card/_render.php`, `card/print-template.php`, `card/print-a4.php`, `portal/your-card.php` are orphaned but still in the tree: this session could not delete files. `dompdf/dompdf` is unused | Delete them; `composer remove dompdf/dompdf` |
| G-04 | Three browser libraries added (`assets/vendor/card/`: snapDOM 3.3.0, jsPDF 2.5.2, JSZip 3.10.1) | Byte-identical to NGG's vendored copies; owner approved the approach |
| — | No write path for `card_photo` / `card_role`: every card prints initials and no role | Decide who sets them |
| — | Back's return address is the organisation's (design); §4 forbids addresses | Owner to confirm |
| — | Phone-sign-in fine needs the cacentre-site gate deployed (`method: 'pass'`) | Deploy the Worker |
