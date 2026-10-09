# Audit — the member card, its printing, and the home page (2026-10-09)

Against the strict handoff `design_handoff_strict_afrovanguard/` (README,
REPLACEMENT_MAP, CHECKLIST, `modules/ID_CARD_PRINT.md`, PAGES) and the owner's
instructions of 2026-10-09: the card must look exactly like the design, print
only from the admin, stay shared-host compatible, link to NextGen Genius, and
a member who signs in with the phone is fined.

Each fault says what was wrong, how it showed, and what was done.

## Faults found and fixed

| # | Fault | How it showed | Fix |
|---|---|---|---|
| 1 | **The print PDF did not look like the card.** dompdf implements CSS 2.1: no flexbox, grid, gradients, shadows, `object-fit` or clipped corners | Header stacked, photo panel gone, tier chip and number split, facts and QR out of their row, the back's 2×2 grid a list. CARD-04/07 had been ticked "verified programmatically rather than by eye" | Files are made in the admin's browser from the screen partial (snapDOM → jsPDF / JSZip), as NGG's ID Card Studio does. Same pixels as the screen |
| 2 | **dompdf ignores inline `<svg>`** | The QR printed as an empty white box | Gone with 1 |
| 3 | **`IdCard::issued()` read `issued_at` from `MemberCards::lookup()`, which does not return it** | A fatal TypeError for every member who HAD a card: the portal's card view and the public `/q/` scan page died. Tests passed because their member had no card | Read from the card row (`MemberCards::of`); a test now issues a card first |
| 4 | **Members could not download their card at all** | `portal/your-card.php` (the block with the buttons) was never included anywhere | Superseded: printing is staff-only now (owner). Member desk → **Print card**; a scanned card's page links admins to it |
| 5 | **`/card/print` was never routed** | `.htaccess` maps one-segment paths only, and `router.php` had no rule: the link 404'd | Links use `/card/print.php` |
| 6 | **The card back diverged from the design** | No italic mission line, no bordered facts table, no signature lines, no return note, no gold strip, no shapes pattern | Partial and CSS rebuilt from the design at 10px/mm. At 1:1, 5 of ~1M pixels differ outside the QR |
| 7 | **Two weights the design uses were not self-hosted** | Given name (Source Sans 3 300) drawn at 400 on screen and in Times by dompdf; mission line had no italic | Both TTFs added to `assets/site/fonts/` and `fonts.css` |
| 8 | **The A4 sheet could not fit on A4** | Five portrait cards down = 436 mm on a 297 mm page; neighbours' bleeds overlapped | Cards laid landscape, 2 × 5, 3 mm gutters, 1.5 mm bleed each, cut marks in the margins; back mirrored for a long-edge flip |
| 9 | **QR quiet zone inside the box** | Every module a fifth smaller than designed | `NgvCard::qrSvg($url, $quiet = 4)`; the card passes 0 (its white box is the quiet zone) |
| 10 | **0.1 mm hairlines vanished in print** | The back grid's row divider was lost at 1 mm = 3.78 px | Print faces lay out on the design's own 10px/mm canvas, so every value is a whole pixel |
| 11 | **The gate could not tell a phone pass from a card** | Both reported `method: 'scan'` | cacentre-site gate reports a pass as `'pass'` (branch `claude/vibrant-cray-3gmdum`); this site fines it |
| 12 | **Two tools would overwrite the new home nav** | `NavSync` and `tools/build-chrome.php` splice the old header into `index.html` | `index.html` removed from both lists |
| 13 | **CSP blocked Cloudinary photos for printing** | `connect-src` lacked `res.cloudinary.com`; snapDOM fetches the photo to embed it | Added in `.htaccess` and `lib/security.php` |
| 14 | **The branch** | `main` is an unrelated static snapshot of the site; the handoff targets `claude/finme-dues-training-fees-l54o5j` | Work is on that branch's history (owner) |

## What changed for the owner's instructions

- **Exactly like the design.** `partials/id-card.php`, `assets/site/avc-card.css`
  (tokens only, no hex). Checked side by side at the design's 1280 canvas.
- **Print only from the admin.** `card/print.php`: an admin (`AdminRoles` ≥
  admin) or a signed NGG link. A member cannot print their own card. Every
  opening is on the admin audit log. PDF 68 × 99.6 mm with crop marks and the
  Subject note; PNG zip 709 × 1082 px tagged 300 dpi + sRGB; A4 10-up.
- **Shared-host compatible.** Nothing runs on the server but PHP. The three
  libraries (`assets/vendor/card/`) are NGG's vendored files, byte-identical.
- **NextGen Genius.** NGG Control Room → People → a vanguard → *Print their
  Afrovanguard card*. NGG signs a 10-minute link with the NGV integration
  secret it already shares (`NGG_WEBHOOK_SECRET` here); the card opens here.
  nextgengen branch `claude/vibrant-cray-3gmdum`, rebuilt `dist/`.
- **Phone sign-in fine.** A carded, expected NGV participant who comes through
  the gate on the phone pass is charged the Fines desk's "Uniform or ID card"
  amount, once a day. Rule `gate.fine_phone_signin` (on) switches it off.

## Still open — needs a person

1. **Delete four orphaned files** (the session's sandbox refused deletions):
   `card/_render.php`, `card/print-template.php`, `card/print-a4.php`
   (the dompdf renderer) and `portal/your-card.php` (members no longer print).
   Nothing references them. Also `composer remove dompdf/dompdf`: it is now
   unused, and its vendor tree is ~200k lines.
2. **No way to add a card photo or role.** `IdCard` reads `card_photo` and
   `card_role` from `Prefs`, and nothing writes them, so every card prints
   initials and no role. Decide who sets them (member, or staff on the desk).
3. **The return address on the back** ("CACENTRE, Alimosho, Lagos") is the
   organisation's, printed because the design has it; ID_CARD_PRINT §4 says
   no addresses. Confirm.
4. **Raster, not vector.** CARD-07 asks for embedded fonts and a vector QR.
   Exactness on shared hosting means the browser draws the card: the faces
   are 600 dpi images in the PDF. Card printers take this; confirm.
5. **The A4 sheet repeats one member ten times.** A run of ten different
   members would need a batch screen on the member desk.
6. **The gate change must be deployed** (cacentre-site `gate/`, a Cloudflare
   Worker) before the phone fine can fire.
7. **Card number format.** `MemberCards` mints `AVQR-` + 16 characters; the
   design shows `AVQR-XXXX-XXXX`. It fits the back's cell; untouched.

## Second round (same day)

| # | Fault | How it showed | Fix |
|---|---|---|---|
| 15 | `IdCard::number()` read `cardFor()['code']`; `cardFor()` returns a string | Every card printed with **no number**; the chip letter came from the NGV plan, not the level | Number from the gate (NGV) or the recorded member ID (AVM), split into level letter + number; chip = the member's level |
| 16 | Nothing wrote `card_photo` / `card_role` | Every card printed initials and no title | Member desk: photo + title. Members: Membership → Your card → Add your photo. Google sign-in: profile photo when there is none |
| 17 | The portal never loaded `avc-card.css` | The member card rendered unstyled in the portal | Loaded |
| 18 | The card lived only under Attendance & pass (gate or NGV only) | An AVM member could not see their card | Membership → Your card, for every member with a card |

**Founding members** (`lib/MemberSeed.php`): nine people, linked to the
account they already have by email, else created; the list's names; level from
the ID; NGV numbers at the gate, AVM numbers as recorded member IDs. Runs once,
by itself, on the first web request after deploy.

**NGG's ID Card Studio prints Afrovanguard cards** from Afrovanguard's own card
(`integrations/ngg-cards.php` ↔ nextgengen `domain/ngv-portal.php`,
`src/modules/idcards/ids-av.jsx`). End to end against both sites: 0.001% of
pixels off against this site's card at 10px/mm; 9 fronts + 9 backs at 300 dpi;
logged under "afrovanguard".

**Needs a person:** set the same secret on both sites — `NGG_WEBHOOK_SECRET`
here, `afrovanguard.ngv_webhook_secret` in NGG's `api/config.php`; deploy the
cacentre-site gate for the phone fine; Gemini key (`AV_GEMINI_API_KEY`) for
automatic photo framing and the Google photo.
