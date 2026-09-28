# Appeals — campaigns, daily and weekly needs, and how they are promoted

## What this is

An **appeal** is one specific, named ask with a story and a number: a borehole
for a school, the December outreach, forty refurbished laptops. It is not
`/donate.html`, which is the standing "give to Afrovanguard" surface and stays
exactly where it is.

A **need** is the small end of the same idea: *today we need ₦18,000 for 40 hot
meals*. Needs are daily or weekly, they carry a real unit price, and they are
what both the appeals index and the home page lead with.

## Why it exists

`process-donation.php` already took money against a campaign key — but the keys
were three constants written into `defaultData()`: `general`, `sts`,
`techhome`. There was no way to run a real appeal. No page to send anybody to,
nothing to share, nothing for a search engine to index, and no way to say what
today actually costs. The money path worked; there was simply nothing in front
of it.

## The one rule about money

**An appeal never holds its own running total.** Verified donations live in the
file `process-donation.php` writes under `av_private_path('donations.json')`,
which is idempotent, exclusively locked and already correct. `Appeals` *reads*
that file for every figure it shows.

An appeal that stored its own total would be a second set of books, and the
interesting question about two sets of books is never *whether* they will
disagree. `tests/appeals.test.php` asserts the table has no `raised`,
`raised_ngn` or `donors` column, so a well-meaning future change cannot add one
quietly.

The one figure that does live on the appeal is `offline_ngn`: cash, a branch
transfer, a gift in kind valued by staff. It is recorded and displayed
**separately** rather than folded in, because a total that mixes "Paystack
verified this" with "a colleague typed this" cannot be audited afterwards, and
the first person to ask will be a donor.

### The consequence, which bit twice

Offline money has no donor count. So an appeal sitting at ₦1,450,000 with every
naira recorded by hand has **zero donors**, and the first version of both the
appeal page and the index cheerfully printed *"₦1,450,000 · 0 donors"* — in the
largest type on the page. Three surfaces now say something true instead
(`recorded by our team`, `raised so far`, or the donor count only when there is
one). If you add a fourth surface that shows a total, it needs the same
treatment.

### Registering the campaign key

`storeDonationIfNew()` files a donation under `general` when the key it is given
is not in the campaigns map. So `Appeals::registerCampaignKey()` writes the new
slug into `donations.json` on save and on publish. Without it, every gift to a
new appeal would be credited to the general fund: the appeal would sit at zero
while the money was real and somewhere else — the kind of error discovered by a
donor asking why their name is not on the page.

## Needs

`UNIQUE (appeal_id, cadence, period)` — the same lesson the NGV ledger learned
about accrual, for the same reason: a morning routine that runs twice must
correct today rather than posting it twice.

A need can be priced two ways and they agree by construction. Give it a unit
cost and a count — 40 meals at ₦450 — and the target is computed, so the page
can say "40 hot meals" and "₦18,000" without anybody keeping the two in step by
hand. Give it a bare figure and it is just a figure.

Two distinctions that are easy to get backwards:

- **A lapsed need is not a current need.** Yesterday's lunch cannot still be
  bought, however unmet it is. It stays on the record and drops off the ask.
- **A met need stays on its own appeal, marked met** ("Today · met, thank
  you") — a need that vanishes the moment it is funded makes the page look like
  nothing was ever asked for. It *does* drop off `currentNeedsAll()`, the
  cross-appeal board, where the only question is what still needs paying.

## Reachable vs listed

These are deliberately different, and conflating them broke every inbound link
to a finished campaign in the first cut:

| | `isPublic()` — may be opened | `published()` — appears in lists |
|---|---|---|
| `draft` | no | no |
| `live` / `paused` / `funded` | yes | yes |
| `closed` | **yes** | no |

A closed appeal is not advertised any more, but it stays reachable: people
follow old links, a poster outlives the campaign printed on it, and how a thing
ended is the most persuasive page on the site. It is sent `noindex, follow` so
it stops competing in search with the appeals still asking, and it is absent
from the sitemap and the feed.

## Promotion

Everything below is generated. There is nothing to maintain per appeal.

| Surface | Path | Notes |
|---|---|---|
| Appeal page | `/give/<slug>/` | Full SEO, share toolkit, QR |
| Index | `/give/` | Needs board, lead story, tiles |
| Share card | `/give/og/<slug>.png` | 1200×630, generated, disk-cached against a content key |
| Printable poster | `/give/<slug>/poster` | A4, vector QR, today's and this week's needs |
| Embeddable widget | `/give/<slug>/embed` | For a partner or sponsor site |
| JSON feed | `/give/feed.json` | Same-origin; powers the home and donate bands |
| RSS feed | `/give/feed.xml` | Syndication |
| Home page band | `/` | Progressive — see below |
| Donate page band | `/donate.html` | Same band, same script |

### The slug is the address

Derived from the title on creation and **never rewritten afterwards**. It is on
a poster and in a search index, and an appeal that re-addresses itself when
somebody fixes a typo in the title is an appeal with no inbound links.

Diacritics fold rather than vanish. `iconv`'s `//TRANSLIT` is locale-dependent
and in the C locale drops accented letters entirely — "Ìlorin" came out
`lorin`, which is not a transliteration of anything and would have been the
permanent public address. Yoruba and Igbo marks are folded explicitly before
`iconv` sees the string. This is a Nigerian organisation; that is not an edge
case.

### Structured data

Chosen against what Google actually renders, verified rather than recalled:

- **Article** — the story. Rich result.
- **Event** — only when the appeal *is* one and has a real `startDate`. An
  Event without one is invalid and Google rejects the whole graph, not just the
  offending node.
- **DonateAction** — earns no rich result, included anyway: it is the correct
  vocabulary for the page, and the machines reading pages now are not only
  search crawlers.
- **BreadcrumbList**, and the Organization node by `@id` reference — never
  restated, or the graph has two publishers.
- **No FAQPage.** Google retired FAQ rich results on 7 May 2026. Marking one up
  for the snippet is now work that buys nothing.

### QR is vector, not raster

The thing a QR is for is print — a poster on a noticeboard, a flyer at a
service, a slide behind a speaker. A raster QR blown up to A3 is a QR that will
not scan. Error-correction level Q tolerates a quarter of the symbol being
damaged, which is what a logo in the middle and a photocopier between it and a
phone actually amount to.

### The home and donate bands are progressive

`/` and `/donate.html` are static HTML served by `DirectoryIndex`, so they
cannot read the database. Rather than convert six thousand lines of hand-built
page into PHP to show three appeals, both bands ship **finished** — real
heading, real copy, a link to `/give/` — and `assets/site/appeals-band.js` adds
the live appeals and needs on top. Every failure path leaves the section intact
and says nothing. A visitor who never sees the script run sees a complete
section, which is the only honest way to put live data on a cached static page.

Everything from the feed is inserted as **text**, never markup, and the figures
are pre-formatted server-side by one formatter — so a band cannot punctuate
naira differently from the page it links to.

## Recurring giving

A donor can give once, monthly, quarterly or yearly on the appeal page itself.

Paystack needs a **Plan** to exist before a subscription can, so the one that
matches an (appeal, interval, amount) combination is created the first time
anybody picks it and the code is cached in `av_appeal_plans`. Creating a plan
per click would fill the merchant dashboard with thousands of identical plans
and make Afrovanguard's own reporting useless.

Three things worth knowing:

- **A subscription is recorded when the webhook confirms it, never at the
  click.** A pledge written down when somebody pressed a button is a pledge
  that may never have been paid for.
- **A pledge is not money.** `recurringFor()` reports the count, the annualised
  value and what has actually been collected — the appeal's raised figure moves
  only when a charge lands, like any other gift.
- **A renewal carries no metadata.** Paystack does not copy our metadata onto
  later charges in a subscription, so `process-donation.php` recovers the appeal
  from the plan code via `av_appeal_plans`. Reading only `custom_fields`
  credited every recurring gift to the general fund — the failure nobody
  notices until a donor asks why the appeal they fund monthly is still at zero.

The widget is an ordinary form that posts to `/donate.html`, and works with
JavaScript off. Only the recurring path is intercepted, because that one
genuinely needs a server round trip.

## Donor updates

Staff post an update, then press **Email donors**.

**Who gets it.** Only people who gave to *this* appeal, plus its recurring
givers, minus anyone who has opted out. A donation to the borehole is not
permission to be told about the December outreach, and treating it as one is
how a charity's mail starts being marked as spam by the people who supported
it.

**Sending is a queue that drains.** Shared cPanel hosting meters outbound mail
by the hour, and a nonprofit that spends its whole allowance announcing a
milestone has also stopped its own password resets, receipts and enquiry
replies. So the console sends the first batch (60) and `Appeals::cronTick()`
finishes the rest. `av_appeal_sends` records each (update, recipient) pair, so:

- pressing the button twice is safe,
- the cron never writes to anybody twice,
- a refused address is logged as done rather than retried every run — retrying
  a bounce spends the quota on an address that will never accept it and starves
  the ones that would.

The cap is on **attempts, not successes**: a batch of addresses that all bounce
costs the host as much as a batch that all arrive.

**Getting out is one click.** Every message carries a `List-Unsubscribe` header
and a visible link, both HMAC-signed per (address, appeal).
`give/unsubscribe.php` answers GET (a person clicking) and POST (a mail client
acting on `List-Unsubscribe-Post`). It is deliberately unauthenticated beyond
the HMAC — an unsubscribe behind a login is an unsubscribe that becomes a spam
complaint, and the complaint costs the whole domain its reputation.

Neither `av_appeal_unsubs` nor `av_appeal_sends` stores an address in the
clear; both index by an HMAC of it.

`Mailer` gained an allowlisted `$opt['headers']` for this. Values are stripped
of CR and LF, because a newline in a mail header ends it and begins another —
an unfiltered value could add a `Bcc` and quietly copy every message somewhere.

## The editorial layer

`assets/site/editorial.css` (`.ed-*`) carries the institutional-editorial
patterns the appeals surfaces are built on, and is loaded by the home page and
the donate page too: a large serif display face, whitespace doing the work
borders usually do, photography-led borderless story tiles, a small uppercase
kicker above every headline, hairline rules between movements, and text links
with a travelling arrow instead of a button for every secondary action.

**What is borrowed and what is not.** The borrowing is structural and
typographic — grid, rhythm, card anatomy, the kicker/headline/meta stack. The
palette stays Afrovanguard's own gold-on-ink, from the `--afg-*` tokens. Lifting
another institution's colours and marks would not be a house style, it would be
a costume, and it would read as one next to our own logo.

The file defines no colour of its own, so both themes and any Studio brand
override follow for free.

### The meter lives here, not in `give.css`

It started in `give.css`, and then the home page band — which does not load
`give.css` — rendered every appeal with an invisible progress bar, because the
markup referenced classes that were not on the page. A component used by more
than one surface belongs in the shared layer; two copies of the same bar is how
two surfaces come to disagree about what 63% looks like.

## Nobody outside Afrovanguard can start an appeal

Every write on `Appeals` is staff-gated by its callers, and the console at
`/give/manage.php` is behind `av_admin_role()` with same-origin + CSRF + a
per-user rate limit on every action.

This is a deliberate product decision, not an omission. User-created
fundraisers would make this a payment platform holding other people's money,
with the identity, trust and payout obligations that come with it.

## Where it lives

| | |
|---|---|
| Domain | `lib/Appeals.php` |
| Schema | `av_appeals`, `av_appeal_needs`, `av_appeal_updates`, `av_appeal_tiers` — provisioned on demand by `Appeals::ensure()` |
| Public | `give/index.php`, `give/appeal.php`, `give/og.php`, `give/poster.php`, `give/embed.php`, `give/feed.php`, `give/share.php`, `give/recurring.php`, `give/unsubscribe.php` |
| Console | `give/manage.php` + `give/manage.js` + `give/manage.css` |
| Styles | `assets/site/editorial.css` (shared), `give/give.css` (appeal-specific) |
| Widget | `give/give.js` — the giving form; the page works without it |
| Recurring | `av_appeal_plans`, `av_appeal_subs` · `Payments::paystackFindOrCreatePlan()` / `paystackCancelSubscription()` |
| Mailing | `av_appeal_unsubs`, `av_appeal_sends` · drained by `Appeals::cronTick()` from `tasks/cron.php` |
| Band script | `assets/site/appeals-band.js` |
| Money | read from `av_private_path('donations.json')` — written only by `process-donation.php` |
| QR | `chillerlan/php-qrcode` |
| Tests | `tests/appeals.test.php` |

Schema is provisioned **on demand** rather than by a version-stamped migration
step. The stamped steps are the ones that leave a deployment broken when its
stamp already matched — which is exactly what `tests/drift.test.php` exists to
document. A subsystem that checks its own schema heals itself.

The same `ddl()` trap applies as in NGV: **no semicolon in any comment inside
the DDL**, because `execSchema()` splits statements by exploding on `;` and a
semicolon in prose cuts a `CREATE` in half — silently, down the benign-error
path. It is a `<<<'SQL'` nowdoc for the same reason.

## What is not built yet

- **No donor messages on the wall.** The wall shows names and amounts from the
  donation record; "words of support" would need a moderated field captured at
  donation time, in `process-donation.php`.
- **A donor cannot manage their own recurring gift.** They can unsubscribe from
  emails in one click, but cancelling a pledge means asking staff, who can do it
  from the console. A self-service page would need donor identity, which this
  site does not have for one-off givers.
- **Plan creation is not retried.** If Paystack is unreachable at the moment a
  donor first picks an amount, they are told to give a one-off gift instead
  rather than being put in a queue.
- **No image upload in the console.** Cover, gallery and update images are URLs.
  `Storage::put()` exists and is used elsewhere; the console does not call it.
- **Multi-currency.** Everything is naira. The donation store records a currency
  and only sums NGN into campaign totals, so a USD gift to an appeal would be
  recorded and not counted on the page.
