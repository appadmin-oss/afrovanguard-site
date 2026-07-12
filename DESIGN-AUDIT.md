# Afrovanguard — Product Design Audit

_Reviewer role: Principal Product Designer / Enterprise UX Architect · Date: 2026-07-12 · Surface: `afrovanguard-site` and the connected Afrovanguard web ecosystem._

> This audit evaluates the **experience** — IA, navigation, interaction, visual system, accessibility, and scalability — grounded in the actual code (stylesheets, templates, and screens). It deliberately avoids cosmetic preference; every recommendation ties to a proven principle and a concrete artifact in the repo.

---

## 1. Executive Summary

Afrovanguard is not a single application — it is a **multi-property, multi-role web ecosystem** for a youth-empowerment nonprofit: a large public marketing site, a member **Portal**, an **Academy** LMS, a **Diary** (blog/journal), **Community** and **Mentorship** modules, and an 18-section admin **Studio** — stitched together across several subdomains (`cacentre.`, `next.`, `afg.`, `members.`). The engineering underneath is mature and hardened. The **design layer, however, is fragmented**: each module has independently reinvented the same primitives, so the product reads as *seven adjacent products* rather than one system.

**The five findings that matter most:**

1. **No shared design-token layer.** The brand gold appears in **~7 different hex values** (`#f3b416`, `#e8a317`, `#c8860a`, `#d49a0e`, `#b07e08`, `#9c6906`, `#a8821a`) across **7 token namespaces** (`--ch-*`, `--color-*`/`--ink*`, `--m-*`, `--p-*`, `--auth-*`, `--cc`, `--gold*`). Surfaces, radii, and shadows are re-declared per module. This is the root cause of most visual inconsistency and the single highest-leverage fix.
2. **The type system contradicts itself.** `--font-body` is defined as **Montserrat** in some modules and **Inter** in others; fonts are aliased under five different token names (`--font`, `--serif`, `--font-body`, `--font-heading`, `--font-display`) and hardcoded as `'Cormorant', Georgia, serif` in 25+ places.
3. **Four+ parallel navigation shells with no shared chrome.** The public mega-nav (`assets/site/nav.css`, 29 KB), the Portal bar, the Studio nav, and the login chrome are each bespoke, each with its own theming. Users crossing surfaces experience four different "apps."
4. **Theming is inconsistent and OS-blind.** Portal, Diary, and the public chrome each ship their own dark/light system (cookie-based, per-module), yet **`prefers-color-scheme` is used zero times** — the product ignores the user's OS preference everywhere.
5. **The admin Studio's IA won't scale.** 18 flat top-level tabs (`overview, entries, moderation, inbox, academy, members, people, mentorship, celebrations, communities, webhooks, signin, system, activity, database, design, admins, guide`) with no grouping. This is already at the limit of flat navigation; every new module makes it worse.

**The opportunity:** the raw materials are strong — genuine accessibility effort (2,315 `aria-*` attributes, 549 `role=`), a distinctive brand (Cormorant display + gold + navy), reduced-motion support, and a clean server-rendered chrome in `lib/partials.php`. Consolidating these into **one design system, one navigation shell, and one theming contract** would convert a functional collection of pages into a coherent, enterprise-grade product without a visual redesign.

---

## 2. Product Understanding

**What it is.** A digital home for Afrovanguard ("The Force for Good"), serving several distinct jobs:

| Surface | Job-to-be-done | Primary users |
|---|---|---|
| Public site (`index/about/ethos/contact/donate`) | Explain the mission, convert donors & volunteers | Anonymous visitors, donors |
| **Donate** flow | Take money (Paystack card / virtual account / bank / in-kind) | Donors |
| **Academy** (LMS) | Courses, lessons, certificates, paid membership | Learners, instructors |
| **Diary** | Publish & read articles; member journaling | Readers, members |
| **Community** | Spaces, posts, the @Afrovanguard bot | Members |
| **Mentorship** | Mentor pool, pairings, cohorts | Members, mentors |
| **Portal** | Member dashboard: Workspace SSO, learning, mentorship, membership **dues** | `@afrovanguard.org.ng` members, learners |
| **Studio** (admin) | Manage everything above + system health | Superadmin / admin / editor |

**Business goals:** donations and in-kind support; recruiting and retaining members/mentors; publishing; running training. **Audience:** ranges from first-time anonymous donors (low intent, mobile-heavy) to daily power admins in the Studio. **Complexity:** genuinely enterprise-shaped — 7+ roles, long-running workflows (enrolment, mentorship cohorts, webhook queues), and datasets that will grow (donors, members, articles, courses, audit logs).

**Existing navigation model.** A public **mega-menu** (About / Academy / Projects / Diary) with a utility bar (Sign in, account menu, Search, Donate CTA), plus separate authenticated shells for Portal and Studio. **Existing design language.** Editorial and warm: Cormorant serif headings, Montserrat body, gold (`#f3b416`) on navy (`#0d1220`), rounded cards, generous shadows. The *intent* is consistent and premium; the *implementation* has drifted per module.

---

## 3. Information Architecture Audit

**Strengths.** The top-level public grouping (About, Academy, Projects, Diary) maps to real mental models. Server-rendered modules share a single chrome (`render_head`/`render_nav`/`render_footer` in `lib/partials.php`), which is the correct backbone.

**Problems.**

- **Property sprawl / ambiguous boundaries.** Navigation links jump between `afrovanguard.org.ng`, `cacentre.afrovanguard.org.ng`, `next.afrovanguard.org.ng`, and `afg.afrovanguard.org.ng`. The same programmes (Techome, MediaPro, Africa GATES, STS) appear both as **local** `/academy/techome/` routes *and* as **external** `cacentre.` links in different menus. Users cannot form a stable model of "where things live."
  → *Recommend:* a single canonical IA with one authoritative URL per entity, and a documented rule for what belongs on the apex vs a subdomain. Treat subdomains as the same product (shared shell + tokens), not separate sites.
- **"Projects" vs "Academy" overlap.** Programmes are categorized inconsistently across the two mega-menus. Consolidate into one taxonomy (e.g., *Programmes* with facets: learning / creative / community).
- **Studio: flat 18-tab structure.** No grouping between content ops (entries, moderation, inbox), people ops (members, people, mentorship, admins), growth (academy, communities, celebrations), and platform (webhooks, system, activity, database, design). → *Recommend:* group the 18 tabs into **4–5 sections** with a collapsible sidebar (see §4).
- **Hidden functionality.** Certificate verification is buried ("Verify a certificate" → `/academy/`), the RSS/subscribe entries live only in a mega-menu column, and membership **dues** (newly added) live only inside the Portal card. High-value, low-discoverability actions should surface in predictable places.
- **Terminology drift** (see §14): "Member" means both an org account (Portal) and a paid Academy tier; "programme," "course," and "project" are used interchangeably.

---

## 4. Navigation Review

**Current state: four navigation systems.**

| Shell | File | Theme | Notes |
|---|---|---|---|
| Public mega-nav | `assets/site/nav.css` (29 KB), `render_nav()` | own light/dark, `--m-*` tokens | Utility bar + mega panels + search + Donate CTA. Good structure. |
| Portal bar | `portal/portal.css`, `portal/index.php` | own cookie theme `av_portal_theme`, `--p-*` | Brand + tag + academy/diary links + avatar. |
| Studio nav | `admin/admin.css`, `admin/index.php` | own, `--cc` | 18 flat tabs. |
| Login chrome | `login/auth.css` | own, `--auth-*` | Minimal. |

**Issues.**
- **No shared shell.** Signing in transports the user into a visually different world (different bar height, colors, type scale, theme behavior). Enterprise products (Linear, Stripe, Atlassian) keep a **persistent global shell** — the workspace switcher, account, and search stay put across every surface.
- **Inconsistent account/auth affordance.** The public nav has a `data-login-link` + account menu; the Portal has an avatar; the Studio has a separate sign-in tab. One **account menu component** should appear identically everywhere.
- **Search is nav-scoped, not global.** `navSearchBtn` routes to Diary search only. As datasets grow, users will expect **one global search** spanning articles, courses, people, members.
- **Mega-menu on mobile.** Verify the mega panels collapse into an accordion (there is a `Mobile navigation` region and an `Open menu` control — good), but the 4-column featured-card mega pattern is desktop-first and must degrade to a simple list.

**Recommendation — one adaptive shell:**
- **Public:** top mega-nav (as today).
- **Authenticated (Portal + Studio):** a **left sidebar** (collapsible to icons) + a slim top bar holding global search, notifications, and the shared account menu. Sidebar sections for Studio: **Content · People · Programmes · Platform · Help**. This scales to dozens of destinations without adding horizontal tabs.

---

## 5. User Journey Analysis

### J1 — Anonymous donor → completed donation (primary revenue path)
- **Entry:** Donate CTA (persistent in nav) or `/donate`. **Intent:** give quickly, trust the org.
- **Path:** choose type (card / virtual account / bank / in-kind) → amount → pay (Paystack inline) → receipt.
- **Friction:** the entire flow lives in one **356 KB** `donate.html` with a carousel banner, dark-mode toggle, and multiple `aria-label="Donation section"` blocks. Heavy page weight hurts the first paint on mobile — precisely where impulse donations happen. The type chooser + amount + provider details compete for attention on one screen.
- **Recommend:** a **3-step wizard** (Amount → Details → Pay) with a persistent summary; lazy-load provider widgets; drastically reduce page weight. Preserve the excellent server-side verification already in place. Add a **one-tap amount** row and remember returning donors.

### J2 — Visitor → member (account creation → Portal)
- **Path:** Sign in / Register → email + code/password **or** Google OAuth → land in Portal.
- **Strength:** progressive login (`login/index.php`) with OTP, password, and Google; `aria-label="Sign-in progress"`. Solid.
- **Friction:** post-auth destination varies (`/academy/`, `/portal/`); after paying dues the user returns to `/academy/?pay=paid`, not the Portal. **Recommend:** a single, predictable post-auth home (Portal) and a consistent "return to where you were" contract.

### J3 — Member → pay membership dues (new)
- **Path:** Portal → "Membership dues" card → Pay/Renew → Paystack → back.
- **Strength:** status is explicit (Current / Due soon / Overdue / Not paid / Lifetime) with amount and paid-through date.
- **Friction:** the return lands on Academy, not Portal (see J2); dues are only discoverable *inside* the Portal. **Recommend:** surface dues status in the account menu (a small badge when overdue), and return to Portal with a success toast.

### J4 — Learner → enrol → complete → certificate
- **Path:** Academy catalogue → course → enrol/pay → learn → certificate.
- **Friction:** access rules (`open / tracked / membership / paid`) are not explained to the user at the point of decision; "Verify a certificate" is hidden. **Recommend:** an access badge on each course card and a first-class `/verify` destination.

### J5 — Admin → daily operations (Studio)
- **Path:** Sign in to Studio → hunt across 18 tabs.
- **Friction:** no overview-driven task routing, no cross-tab search, no bulk actions surfaced. **Recommend:** an **Overview that routes to work** (e.g., "3 posts awaiting moderation," "2 mentor requests") — turn the dashboard into a task inbox.

---

## 6. Screen-by-Screen UX Audit

| Screen | Purpose | Strengths | Key issues |
|---|---|---|---|
| **Home** (`index.html`, 317 KB) | Convert & orient | Strong editorial brand | Massive monolithic HTML; hard to maintain; likely heavy LCP on mobile |
| **Donate** (`donate.html`, 356 KB) | Take donations | Multiple methods, copy-to-clipboard, verification | One-screen overload; page weight; carousel + dark toggle add noise to a money task |
| **Contact** (`contact.html`, 166 KB) | Contact + newsletter | Honeypot, rate-limited | Oversized; form buried in a large page |
| **Login** (`login/`) | Authenticate | Progressive OTP/password/Google, good aria | Its own chrome; unclear post-auth destination |
| **Portal** (`portal/index.php`) | Member dashboard | Card grid, live Workspace data, countdowns, dues | Card hierarchy is flat (every card equal weight); "Coming up" hidden by default; grid mixes 1- and 2-col spans without a clear priority order |
| **Academy** (`academy/`) | LMS | Membership CTA, course cards | Access types not surfaced; pay result page generic |
| **Diary** (`diary/`, CSS 67 KB) | Publish/read | Rich (TTS, journey, feed) | Heaviest stylesheet in the repo (15 media queries) — a mini design system of its own |
| **Community / Mentorship** | Social/coaching | Real feeds, pairings | Separate visual languages again |
| **Studio** (`admin/`, app.js 115 KB) | Admin | Deep functionality, role gating, aria | 18 flat tabs; dense forms; inline styles in template (`style="font-family:var(--font-heading)…"`) |
| **Error pages** (`403/404/429/500/503.html`) | Recover | Branded, consistent | Good — a rare place where consistency already holds |

**Cross-cutting screen issues:** inconsistent page-title patterns, mixed use of inline styles vs classes (especially in `admin/index.php`), and flat visual hierarchy inside card grids (nothing signals "look here first").

---

## 7. Layout & Grid Review

- **No shared grid or spacing scale.** Each module sets its own paddings and breakpoints; media-query counts range from **3 (login) to 15 (diary)** with no common breakpoint tokens. → Adopt a **4/8px spacing scale** and a shared set of breakpoints (`sm 640 / md 768 / lg 1024 / xl 1280`) as tokens.
- **Card systems diverge.** `.portal-card`, Academy cards, Diary cards, and Studio panels are visually similar but independently defined. → One **Card** primitive (padding, radius, border, shadow, header slot, tag slot) reused everywhere.
- **Radius & shadow drift.** Multiple radius values (`--radius-md: 14px` in portal, others hardcoded) and shadow definitions per module. → Tokenize `radius.{sm,md,lg,pill}` and `shadow.{sm,md,lg}`.
- **Static-page layout ≠ module layout.** Monolithic HTML pages hand-roll layout; server-rendered modules use `partials.php`. Two layout worlds. → Migrate static pages onto the shared chrome/partials (or a static-site component) so layout is defined **once**.
- **States are unsystematic.** Only sparse `skeleton` (5), `is-loading` (3), `pc-empty` (2) usages, and no shared error-state component. → Define **Empty / Loading / Error** as first-class, reusable states with consistent copy, iconography, and a primary recovery action.

---

## 8. Form Design Review

**Observed forms:** Donation (text/email/tel/number/checkbox; 4+4+2+2), Contact + newsletter, Login (email → code/password + Google), and dense Studio editors (course, member, token, team, celebration).

**Strengths.** Real validation server-side (`filter_var`, regex on references), honeypot on contact, min/max on amounts, `aria-label`s on inputs, show-password toggle, progressive login.

**Issues & recommendations.**
- **No shared form component / field grid.** Labels, help text, and error placement vary by module. → One **Field** primitive: label + control + description + inline error + required marker, on an 8px vertical rhythm.
- **Validation is mostly server-round-trip.** Add **inline, on-blur client validation** (format, required) with server validation as the source of truth. Show errors adjacent to the field, not as a single top banner.
- **Donation form density.** Split into steps (see J1); show a running summary; make the primary action unmistakable.
- **Required vs optional not signaled consistently.** Standardize: mark **optional** fields (enterprise convention) and keep required implicit, or vice-versa — but pick one.
- **No save-and-resume** on long Studio editors (course/curriculum). For large content, add autosave + draft state.
- **Confirmation & destructive actions.** Ensure delete flows (team_delete, token revoke) use a consistent confirm pattern with typed confirmation for high-impact deletes.

---

## 9. Dashboard & Data Presentation Review

- **Portal dashboard** is a flat card grid — informative but **priority-blind**. Introduce hierarchy: a hero status row (membership/dues, next session, alerts), then supporting cards. Use the newly added **dues** card as the model for a "status object" pattern (state pill + primary metric + one action).
- **Studio "Overview"** should be a **decision surface**: counts that link to the work (pending moderation, unanswered inbox, mentor requests, failed webhooks, dues overdue). Today the overview competes with 17 sibling tabs instead of orchestrating them.
- **Tables at scale.** `enrollments`, `subscribers`, `audit_log` are `LIMIT 200/500` raw lists. As data grows this breaks down. → Standard **DataTable**: server-side pagination, column sort, sticky header, faceted filters, saved views, **bulk actions**, and CSV export. One component, reused across all admin tables.
- **Search / filter / sort are inconsistent** where present and absent where needed. Define a shared **filter bar** (search + facet chips + sort + density toggle).
- **No charts yet** for donations/membership trends — a clear near-term need. When added, follow one charting spec (consistent axes, color, empty/loading states); do **not** let each module pick its own library and palette.

---

## 10. Interaction Design Review

- **Feedback is inconsistent.** Some flows redirect (`?pay=paid`), some show inline messages (`enroll-msg`, the new `dues-msg`), none use a shared **toast** system. → Introduce one toast/notification primitive for success/error/undo.
- **Confirmation flows vary** across modules. Standardize modal + confirm.
- **Keyboard & focus.** Strong semantic base (2,315 `aria-*`), but only **14 `focus-visible`** rules — focus styling is sparse. → Add a global, tokenized focus ring (portal already defines `--p-focus`); ensure every interactive element and the mega-menu/modal traps are keyboard-complete.
- **Progressive disclosure** is used well in places (login steps, "Coming up" countdown) but the Donate and Studio screens dump everything at once. Apply disclosure to reduce first-glance load.
- **Contextual actions.** Row-level and card-level actions should be consistent (hover reveal on desktop, always-visible affordance on touch).
- **Motion.** Good `prefers-reduced-motion` coverage (16 rules) — keep it; extend to all animated components.

---

## 11. Design System Audit

This is the highest-leverage area. Evidence of fragmentation:

| Concept | Current reality | Target |
|---|---|---|
| **Color tokens** | 7 namespaces: `--ch-*`, `--color-*`/`--ink*`, `--m-*`, `--p-*`, `--auth-*`, `--cc`, `--gold*` | One `--afg-*` (or unprefixed semantic) token set: `color.brand`, `color.surface`, `color.ink`, `color.muted`, `color.success/danger/info`, `color.accent` |
| **Brand gold** | `#f3b416, #e8a317, #c8860a, #d49a0e, #b07e08, #9c6906, #a8821a` | One `brand.gold` + a documented **on-light** deep variant (`#b07e08`) for text (contrast) |
| **Type** | `--font-body` = Montserrat **and** Inter; aliases `--font/--serif/--font-body/--font-heading/--font-display`; `'Cormorant'` hardcoded 25+× | `font.display` (Cormorant), `font.body` (pick **one**: Montserrat *or* Inter), `font.mono`; a fixed **type scale** |
| **Spacing** | Ad-hoc per module | 4/8px scale tokens |
| **Radius/shadow** | Re-declared per module | `radius.*`, `shadow.*` tokens |
| **Buttons** | `btn-primary, -outline, -ghost, -ink, -pill, -pill-gold, -pill-ghost, -ghost-light, -sm` | One **Button**: variants `primary / secondary / ghost / danger`, sizes `sm / md`, one shape decision (pill *or* radius, not both) |
| **Cards / inputs / tags / menus** | Independent per module | Shared primitives |
| **Theming** | Per-module cookie themes; `prefers-color-scheme` = **0** | One theming contract: tokens flip via `data-theme`, defaulting from `prefers-color-scheme`, with a single user override persisted app-wide |

**Recommendation:** establish a **single token source of truth** (`assets/site/tokens.css`) consumed by every module, then progressively migrate module CSS to reference tokens (not literals). This is a mechanical, low-risk, high-impact refactor and the foundation for everything else. Pair it with a lightweight **component library** (Button, Card, Field, Table, Modal, Toast, Tag, Menu, EmptyState) documented on the existing Studio **"design"** tab — which already exists and is the perfect home for a living style guide.

---

## 12. Accessibility Assessment (WCAG 2.2 AA)

**Strengths:** extensive `aria-*` (2,315) and `role=` (549), reduced-motion support (16 rules), semantic landmarks in nav/portal.

**Gaps & fixes:**
- **Contrast.** Gold `#f3b416` as text on white ≈ **1.7:1** (fails AA 4.5:1). It's fine as a large decorative fill, but wherever gold conveys text/state it must use the deep variant (`#b07e08`+) — the Portal already does this (`--p-accent: #b07e08`); make it a **rule**, not a per-module choice.
- **Focus visibility.** Only 14 `focus-visible` rules for a large app. → Global tokenized focus ring on all interactive elements; verify visible focus through the mega-menu, modals, and the Studio tabs.
- **OS color-scheme ignored.** `prefers-color-scheme: 0` occurrences. → Default theme from OS, then allow one persisted override.
- **Forms.** Ensure every input has a programmatic label (not only `aria-label` placeholders), errors are announced (`aria-live`), and required state is conveyed non-visually.
- **Target size.** Verify touch targets ≥ 44×44px, especially the nav utility links, carousel dots, and copy-to-clipboard controls on Donate.
- **Motion/carousel.** The Donate banner carousel needs pause/stop control and must respect reduced-motion.

---

## 13. Mobile & Responsive Assessment

- **Breakpoints are ad-hoc** (3–15 media queries per module, no shared tokens) → common breakpoint scale.
- **Page weight is the biggest mobile risk.** `index.html` (317 KB), `donate.html` (356 KB), `about.html` (170 KB), `contact.html` (166 KB) are hand-authored monoliths. On mobile networks this hurts LCP/INP exactly where donors convert. → Componentize, defer non-critical assets, and lazy-load below-the-fold blocks and payment widgets.
- **Mega-nav → mobile.** A dedicated mobile nav region exists (good); confirm mega panels degrade to accordions and that the Donate CTA stays reachable.
- **Studio on tablet/mobile.** 18 horizontal tabs and dense tables won't fit. The proposed collapsible sidebar + responsive DataTable (cards on narrow screens) is essential for admins on the go.
- **Touch feedback & overflow.** Ensure tables scroll within a container (no body horizontal scroll) and interactive rows have touch-appropriate affordances.

---

## 14. Enterprise UX Assessment

- **Roles are real but the UI isn't permission-aware end-to-end.** Studio gates by superadmin/admin/editor server-side; the **interface** should adapt too — hide/disable actions a role can't perform, and label why. As departments grow (content, programmes, finance, mentorship), consider **role-scoped Studio home screens**.
- **Power-user affordances missing.** No command palette, no keyboard shortcuts, no saved filters/views, no bulk actions. Enterprise admins live in these. Add a **command menu** (⌘K) spanning navigation + actions + search — it also solves the "18 tabs" discoverability problem elegantly.
- **High-volume workflows.** Moderation, inbox, and mentorship pairing need queue-style UIs (triage, assign, resolve, next) rather than static lists.
- **Long-running processes.** Webhook retry queue and imports (WordPress WXR) need visible status, progress, and history — partially present (webhook deliveries) but not consistent.
- **Concurrency & auditability.** `lms_audit`/`AdminAudit` exist; surface an **activity/audit view** consistently and show "last edited by" on shared content to prevent silent overwrites.
- **Internationalization & scale.** Copy is hardcoded English; a growing pan-African audience may need i18n readiness (externalized strings, RTL-safe layout).

---

## 15. Product Consistency Report

| Dimension | Inconsistency | Unify to |
|---|---|---|
| **Navigation** | 4 shells, 3 theme systems | 1 adaptive shell + 1 theming contract |
| **Terminology** | "member" (org vs paid), "programme/course/project", "diary/blog" | A glossary; one term per concept |
| **Components** | Per-module buttons/cards/inputs | Shared primitives |
| **Color** | 7 golds, 7 token namespaces | 1 token set, 1 brand gold + deep text variant |
| **Type** | Montserrat vs Inter for body; 5 font token names | 1 body font, 1 token vocabulary, 1 scale |
| **Theming** | Cookie themes per module; OS ignored | `data-theme` from `prefers-color-scheme` + one override |
| **Feedback** | Redirects vs inline vs none | 1 toast system |
| **States** | Sparse, bespoke empty/loading/error | Shared state components |
| **Layout** | Monolithic static pages vs partials chrome | One chrome/layout system |

---

## 16. Prioritized UX Issues

### 🔴 Critical (foundation — do first)
1. **Establish a single design-token layer** (color, type, spacing, radius, shadow, breakpoints). Root cause of most inconsistency. *(§11)*
2. **Resolve the type contradiction** (Montserrat vs Inter; consolidate font tokens). *(§11)*
3. **One theming contract** honoring `prefers-color-scheme` + a single persisted override. *(§11, §12)*
4. **Fix gold-as-text contrast** (enforce deep variant for text/state). *(§12)*
5. **Reduce Donate/Home page weight & restructure the donation flow** — direct revenue and mobile impact. *(§5, §13)*

### 🟠 High
6. **Unify navigation into one adaptive shell** (top mega-nav public; sidebar + slim top bar authenticated). *(§4)*
7. **Restructure Studio IA** into 4–5 grouped sections; add a **⌘K command menu**. *(§3, §14)*
8. **Shared component library** (Button, Card, Field, Table, Modal, Toast, Tag, Menu, EmptyState) documented on the Studio "design" tab. *(§11)*
9. **Standard DataTable** (server pagination, sort, filter, bulk actions, export). *(§9)*
10. **Global search** across articles, courses, people, members. *(§4)*

### 🟡 Medium
11. Systematic **Empty / Loading / Error** states. *(§7, §10)*
12. **Inline form validation** + one Field primitive; consistent required/optional. *(§8)*
13. **Toast feedback** system; consistent post-action navigation (dues → Portal). *(§5, §10)*
14. Permission-aware UI (hide/disable by role). *(§14)*
15. Global **focus ring** + keyboard completeness through menus/modals. *(§12)*

### 🟢 Low
16. Consolidate button variants to one taxonomy. *(§11)*
17. Course **access-type badges**; first-class `/verify`. *(§5)*
18. Carousel pause/reduced-motion on Donate. *(§12)*
19. Terminology glossary & copy audit. *(§15)*

---

## 17. Recommended Design Improvements (systems, not screens)

1. **`tokens.css` as the single source of truth**, imported by every module; migrate literals → tokens incrementally (start with color + type). Zero visual change if values match today's canonical brand; instant consistency thereafter.
2. **One chrome/shell** with slots: `brand`, `primaryNav`, `globalSearch`, `notifications`, `account`. Public renders the mega-nav variant; authenticated renders the sidebar variant. Both consume the same tokens and account menu.
3. **Component library on the existing "design" Studio tab** — turn it into a living style guide with do/don't examples; enforce via code review.
4. **Status-object pattern** (state pill + key metric + one action), generalized from the new dues card, for membership, enrolment, webhook health, moderation queue.
5. **DataTable + FilterBar** primitives for every list surface.
6. **Command menu (⌘K)** for navigation + actions + search — the scalability answer to growing IA.
7. **Wizard pattern** for multi-step flows (Donate, long Studio editors) with progress, summary, and save/resume.

---

## 18. Future Scalability Considerations

- **Design tokens unlock white-labeling / sub-brand theming** (STS, NGG, Techome) from one system instead of forked stylesheets.
- **Sidebar + command menu** absorb new modules without IA churn.
- **DataTable + saved views** handle growth from hundreds to hundreds of thousands of rows.
- **Role-scoped homes** and permission-aware UI prepare for multiple departments and delegated admins.
- **i18n-ready** copy and RTL-safe layout prepare for pan-African and diaspora expansion.
- **A documented component contract** keeps velocity high as contributors increase — new features compose existing primitives instead of inventing CSS.
- **Performance budget** (page weight ceilings, lazy-loading policy) prevents the monolithic-HTML problem from recurring.

---

## 19. Final Enterprise UX Vision

Afrovanguard should feel like **one product with many rooms**, not many products sharing a logo. A visitor lands on a fast, focused public site; signs in once; and moves through Portal, Academy, Diary, Community, and Mentorship inside a **persistent shell** with consistent navigation, one account menu, and global search. Members see a **priority-ordered dashboard** where membership/dues, next actions, and alerts lead. Admins work in a **grouped, searchable Studio** with a command palette, standardized tables, and task-routing overviews — confident, fast, and permission-aware.

Underneath, a **single design-token layer and component library** guarantees that the brand's warmth — Cormorant, gold, navy — renders identically and accessibly in light and dark, on phone and desktop, across every subdomain. The engineering is already enterprise-grade; this plan brings the **experience** to the same standard, and — critically — makes it **cheaper to grow**, because every future feature composes a system instead of reinventing one.

---

### Self-critique & trade-offs (Phase 14)

- **Biggest risk in these recommendations:** a "big-bang" redesign. *Mitigation:* everything here is **incremental** — tokens first (no visual change), then shell, then components — each shippable and reversible.
- **Tension:** editorial richness (Diary, marketing pages) vs system uniformity. *Resolution:* the token/component system governs *primitives and chrome*; editorial pages keep expressive freedom **within** those tokens.
- **Effort honesty:** consolidating 7 token namespaces and 4 nav shells is real work. Sequenced by leverage (Critical → High), the early steps are low-risk mechanical refactors with outsized consistency payoff.
- **What I deliberately did not recommend:** changing the brand identity, swapping frameworks, or introducing an SPA for the public site — none would improve usability enough to justify the cost or the page-weight risk.
