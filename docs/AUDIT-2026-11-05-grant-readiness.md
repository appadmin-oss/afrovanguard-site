# Public-site audit for the Cisco grant application — 2026-11-05

_Scope: the public pages of afrovanguard.org.ng (home, about, programmes,
donate, contact, academy, diary, people, policies), read as a Cisco reviewer
and a search engine would. Method: every public page was crawled locally
(48 pages, 109 internal links), and the live site was checked over HTTPS.
Each finding names the file it came from._

## 1. What Cisco looks for

From Cisco's own pages ([grant-giving policies][gp], [investment areas][ia]),
not from aggregator sites:

| Cisco criterion | Where a reviewer will look on our site |
|---|---|
| Technology applied to deliver programmes | Academy, Techome, the member portal, IQ |
| Can be replicated beyond the first geography | Social Franchise / CACENTRE model (`/franchise`, `/how-it-works`) |
| Potential for scale | The 2040 vision; the CACENTRE production model |
| Tracks progress, validates impact, **reports transparently** | Impact numbers on Home, About, Projects, Donate; the STS 2025 report |
| Serves underserved communities | Alimosho / Lagos LCASP centres |
| Strong management, inclusive teams | `/people/`, About |
| Financially viable beyond Cisco | Donate, Give appeals, dues |
| Excluded: groups that deny service on protected grounds or require religious adherence | Nowhere does the site state a non-discrimination policy |

Cisco's focus areas are Education and Economic Opportunity (skills,
entrepreneurship), which fit Afrovanguard. Applications go through Cisco's
online form only. Third-party listings disagree about deadlines and about a
"65% underserved beneficiaries" rule, so confirm both on Cisco's application
portal before submitting.

**The criterion this audit most affects is "reports transparently."** A
reviewer who finds two different founding years, or three different volunteer
counts, has reason to doubt every other number on the site.

[gp]: https://www.cisco.com/c/en/us/about/csr/community/nonprofits/grant-giving-policies.html
[ia]: https://www.cisco.com/c/en/us/about/csr/community/nonprofits/investment-areas.html

## 2. Fixed in this change

| # | Problem | Fix |
|---|---|---|
| F-1 | **A second Street-To-Stardom website lived inside this one** (`/projects/sts/about/`, `/team/`, `/impact/`, `/programs/`, `/blog/`, `/get-involved/`, `/units/`, `/contact/`, `/ceo/`, 52 files). It was live and indexable, and it contradicted the main site: "Founded 2019", "234 active volunteers" (the real STS report says 4 volunteers and 12 instructors), "audited financials", and "every **shilling** we receive" (Kenyan currency). Its team page lists named trustees and placeholder mentors "M-001…M-010". Its `sitemap.xml` pointed at another domain. STS has its own site at sts.afrovanguard.org.ng. | Removed. The old paths now 301-redirect to the same path on sts.afrovanguard.org.ng (`.htaccess`, mirrored in `router.php`). This site keeps the STS programme page, the LCASP page and the two published PDFs. The deny rule on `ceo/api/data` and its `.htaccess` stay, because a host deployed before this change may still hold that form's backups (names, emails, WhatsApp numbers), and `git pull` does not delete untracked files. |
| F-2 | `sitemap.xml` listed four pages under URLs that the pages themselves do not call canonical (`/about/` vs `about.html`, `/contact/` vs `contact.html`, `/donate.html` vs `/donate/`, `/projects` vs `/projects/`). It also left out the privacy policy, terms, people, how-it-works, franchise and every programme page. | `lib/Sitemap.php` now lists each page under its own canonical URL and includes the missing pages; `sitemap.xml` was regenerated (19 → 31 URLs). |
| F-3 | The Diary's "Join." button linked to `/volunteer/`, which is a 404 on the live site. An existing test pinned the broken URL. | Links to the volunteer form the rest of the site uses (cacentre…/volunteer); the test was updated. |
| F-4 | The LCASP page gave `/projects/sts/lcasp/` (a 404 on the live site) as its canonical URL, breadcrumb and calendar link. | Changed to `/projects/sts/lcasp.html`. |
| F-5 | About, 2023 timeline entry: the chip said "10,000+ lives" while the paragraph under it said "more than 5,000 lives". | Chip changed to "5,000+ lives". |
| F-6 | No HSTS header on the static pages (Home, About, Donate, Contact). PHP pages already sent one. | `.htaccess` now sends the same value `lib/security.php` sends. |

Pinned by `tests/siteaudit.test.php`.

## 3. Owner decisions (answered 2026-11-05) and what changed

| # | Question | Owner's answer | Done |
|---|---|---|---|
| O-1 | Founding year | **2017** | Sign-in page ("Est. 2017") and the founder profile now agree with Home, About and the footer. |
| O-2 | How many programmes | **Seven projects**: Africa GATES, Career Hub, Business Executive Club, Techome, MediaPro, Kingdom Advancement Project, Street-To-Stardom. Programmes run under projects: Next Generation Genius and LCASP (formerly the Alimosho Summer School) are STS programmes. | `lib/projects_content.php` holds the seven; the STS page lists its programmes; Home, Projects, About, Contact, menus and footer say "seven projects". The Academy is no longer shown as a project. |
| O-3 | Lives reached | **10,000+** | The founder profile's "5,000+" now reads 10,000+. The About timeline keeps 5,000+ for 2023, as a point on the way. |
| O-4 | Volunteer counts | They vary by season (more during LCASP); not all are retained. | The first pass misread these: Donate said 30 *instructors* and 20 volunteers, matching LCASP's own budget, and LCASP labels 20 or 50 per episode. They did not conflict. The Donate block is now the running campaign's own figures (below). |
| O-5 | Registration | Registered address: CACENTRE, 2 Abolude / Oremeji Street, off Akowonjo Road, Alimosho. | On the Contact page, the Reports page and in the search-engine data (JSON-LD). The CAC number is public record at the Corporate Affairs Commission and funders ask for it; it is not on the site, and can be added to the Contact page when you choose. |
| O-6 | Non-discrimination, forms | Forms record sex as Male or Female. | Every form across this site, CACENTRE and NGG already offers only Male / Female. The NGV application store, which accepted any text, now keeps only Male, Female or blank. |
| O-7 | Reports | Reports exist up to 2025, some large or incomplete; 2026 to come later this year. | New page **/reports/** (footer and About menu), listed from `lib/Reports.php`, with the 2026 annual report shown as in preparation. Procedure: `docs/REPORTS.md`. |

**Before submitting to Cisco**, read their certification wording closely. Cisco's
policy excludes organisations that deny service or employment on protected
grounds, and the application asks the organisation to certify that.

## 3A. Also fixed in the second pass

| # | Problem | Fix |
|---|---|---|
| F-7 | **An unresolved merge conflict was live** in `projects/index.html`: `<<<<<<< HEAD … >>>>>>>` inside `<head>`, which a browser prints as text at the top of the page. | Resolved (both sides were identical). `tests/siteaudit.test.php` now fails on a conflict marker anywhere in the site. |
| F-8 | **The YouTube icon linked to a stranger's channel**: youtube.com/@afrovanguard is "Max Morris". Instagram linked @afrovanguard, not the organisation's @afro.vanguard. | YouTube now goes to AFROVANGUARD TV (@afrovanguardism) and Instagram to @afro.vanguard, on every page. Both YouTube channels, with Okun Alimosho TV (@okunalimosho), are on the Contact page and in the search-engine data. **The Studio may hold its own copy of these links** (Content → Site → Site details): check them there after deploying. |
| F-9 | The Google map on Contact loaded from Google, which can set cookies, before the visitor had been asked. | The map loads only when "Show the map" is pressed. |
| F-10 | The Reports page called the LCASP plan a "Project report" and showed it as the latest report. | It is a "Project document"; a document is never shown as the latest report. |

## 3A-2. Campaigns are timed (owner, 2026-11-05)

The LCASP 2026 campaign stayed on Donate ("the ₦40.3M campaign"), Projects
("Open for enrollment now") and the Home events after it ended, because each was
typed into its page. Campaigns are now appeals, timed by their Starts and Ends
dates: shown from the first day, gone the day after the last (`docs/appeals.md`,
"Campaigns are appeals"). The LCASP 2026 page redirects to the Street-To-Stardom
page; the Home events fallback names no date, and says "Nothing scheduled right
now" when nothing is.

## 3B. Cookies and other data the site collects

Checked by loading the live pages in a browser without answering the notice,
and by reading every cookie the code sets.

**Urgent: the live site sets Google Analytics cookies without asking.** The
version deployed now predates the consent notice (`assets/site/ga.js` on the
live host is the 2026-11-04 version): visiting the home page set `_ga` and
`_ga_K3MXKHNB0W` with no notice shown. The notice is in this branch; deploying
it fixes this.

Only Google Analytics needs the visitor's consent, and that is what the notice
asks about. Everything else is needed for the site to work, never leaves the
site, or loads only when the visitor asks for it. All of it is now listed in the
privacy policy (section 6, Cookies), and the notice links there:

| What | Kind | Consent needed? |
|---|---|---|
| `av_consent`; sign-in (`av_lms`, `av_si`, `av_oauth`, `av_gws`, `av_admin`); Cloudflare `cf_clearance` | Needed for the site to work | No |
| Diary reader id `av_rv` and view/applause cookies; invitation credit `av_ref`; `av_illo` | First-party, anonymous or functional | No; disclosed |
| Theme, text size, saved Diary entries | Browser storage, never sent | No; disclosed |
| Cloudflare Web Analytics | Count without cookies | No; disclosed |
| jsDelivr, Cloudinary | Files only (they see an IP address, like any server) | No; disclosed |
| Google Analytics `_ga` | Optional | **Yes**: the notice |
| Google Maps (Contact), videos and Diary embeds, Paystack | Third party, loads only when asked for (show, play, pay) | Disclosed |

The privacy policy also said a cookie remembered the theme; it is browser
storage, and now says so.

## 4. Smaller findings (not changed)

| # | Finding |
|---|---|
| S-1 | Duplicate host: `www.afrovanguard.org.ng` answers 200 rather than redirecting to the apex domain. The canonical tags make this harmless for search engines; a 301 at Cloudflare would be tidier. |
| S-2 | `/academy/teach/` has no `<h1>`. |
| S-3 | Contact and Donate have no `og:image`, so a shared link shows no picture. |
| S-4 | `/Images/*` (the main photo set) is not in the repository; it lives only on the live host. A fresh deployment from git would show no photographs. Back it up and add it to `DEPLOY.md`. |
| S-6 | Contact says "14 nations reached". Confirm it or remove it: a reviewer may ask which. |
| S-7 | Contact says of donations: "100% of funds go to programmes". A funder will compare that with the accounts; make sure they agree. |
| S-5 | Older audits in the repository root (`CODEBASE-AUDIT.md`, `DESIGN-AUDIT.md`) still describe `projects/sts/ceo/api/ai.php`, which this change removed. |

## 5. Verification

- `php tests/run.php`: 7,645 assertions across 182 files, all passing (the
  baseline before this work was 7,591 across 181).
- `php bin/check-inline-js.php`: 5 inline script blocks, none bad.
- Through `php -S … router.php`: `/projects/sts/about/`, `/team/`, `/ceo/` and
  `/blog/x/?a=1` each answer 301 to the same path (query string kept) on
  sts.afrovanguard.org.ng; `/projects/sts/ceo/api/data/*` still answers 403;
  `/projects/sts/`, `/projects/sts/lcasp.html`, `/diary/` and `/sitemap.xml`
  answer 200.
- The `.htaccess` rules can only be checked on Apache. After deploying, request
  `https://afrovanguard.org.ng/projects/sts/team/` and confirm it redirects.
