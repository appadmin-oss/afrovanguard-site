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

## 3. Needs an owner decision before the application goes in

These are facts only the organisation can confirm. The site currently
contradicts itself on each one.

| # | Finding | Where |
|---|---|---|
| O-1 | **Founding year.** "Since 2017" (Home, About, "© 2017–2026", "9 years of impact") vs "Est. 2018" (sign-in page) vs the founder profile, which says "Founded Afrovanguard in 2018" and gives 2018 as the "since" year. | `index.html`, `about.html`, `login/index.php:120`, `member.html:336-338` |
| O-2 | **Number of programmes.** "9 Programmes" / "Nine Pathways" (Home, Projects) vs "Eight programmes, one standard" (About) vs "8 flagship programmes" (founder profile). `lib/projects_content.php` lists nine, two of which (Africa GATES, NGV) are separate sites. | `index.html:338`, `projects/index.html`, `about.html:254`, `member.html:338` |
| O-3 | **Lives reached.** "10,000+ lives transformed" (Home, About, Projects, Donate, Contact) vs "5,000+ beneficiaries" (founder profile). There is no source or date next to the 10,000 figure. Cisco asks for impact that has been validated: each headline number should have a year, a definition (reached? enrolled? completed?) and a link to the report it comes from. | as above |
| O-4 | **Volunteer counts.** Donate says 20 in one place and 30 in another; LCASP says 20 and 50. | `donate.html`, `projects/sts/lcasp.html` |
| O-5 | **No registration details anywhere on the site.** No CAC / Incorporated Trustees number, no registered address, no tax status. Cisco grants only to registered non-profits, and a reviewer will look in the footer or on the About page. | site-wide footer (`partials/avh-chrome.php`) |
| O-6 | **No non-discrimination or safeguarding statement.** Cisco excludes organisations that deny service on protected grounds. A short public statement on About or in the footer answers this before anyone asks, and a safeguarding policy is expected of a youth organisation. "Kingdom Advancement Project" is a heritage and culture programme, not a religious one; say so on its page so a reviewer does not have to guess. | `about.html`, `projects/kap/` |
| O-7 | **No published annual report or financials for Afrovanguard as a whole.** The only report on the site is STS 2025. A "Reports" link carrying the latest annual report, the audited or management accounts, and the board list would support the transparency criterion more than anything else on this page. | — |

## 4. Smaller findings (not changed)

| # | Finding |
|---|---|
| S-1 | Duplicate host: `www.afrovanguard.org.ng` answers 200 rather than redirecting to the apex domain. The canonical tags make this harmless for search engines; a 301 at Cloudflare would be tidier. |
| S-2 | `/academy/teach/` has no `<h1>`. |
| S-3 | Contact and Donate have no `og:image`, so a shared link shows no picture. |
| S-4 | `/Images/*` (the main photo set) is not in the repository; it lives only on the live host. A fresh deployment from git would show no photographs. Back it up and add it to `DEPLOY.md`. |
| S-5 | Older audits in the repository root (`CODEBASE-AUDIT.md`, `DESIGN-AUDIT.md`) still describe `projects/sts/ceo/api/ai.php`, which this change removed. |

## 5. Verification

- `php tests/run.php`: 7,627 assertions across 182 files, all passing (the
  baseline before this change was 7,591 across 181).
- `php bin/check-inline-js.php`: 5 inline script blocks, none bad.
- Through `php -S … router.php`: `/projects/sts/about/`, `/team/`, `/ceo/` and
  `/blog/x/?a=1` each answer 301 to the same path (query string kept) on
  sts.afrovanguard.org.ng; `/projects/sts/ceo/api/data/*` still answers 403;
  `/projects/sts/`, `/projects/sts/lcasp.html`, `/diary/` and `/sitemap.xml`
  answer 200.
- The `.htaccess` rules can only be checked on Apache. After deploying, request
  `https://afrovanguard.org.ng/projects/sts/team/` and confirm it redirects.
