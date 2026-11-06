# Adaptive Goal Workspace: feasibility study

6 November 2026 · Assessed against the Product Design Document of 4 November 2026 · Code at branch `claude/finme-dues-training-fees-l54o5j` in `afrovanguard-site` and `cacentre-site`

## Verdict

The answer depends on what is being asked. There are four separate questions, and each has its own answer.

| Question | Verdict | Why, in one line |
| --- | --- | --- |
| 1. Run the three awards on the built product before the 15 November gate | **Not feasible** | 9 days remain, and nothing for nominations or paid voting exists in either repository |
| 2. Pilot the loop on the real awards with simple tools (design doc, next step 3) | **Feasible, with conditions** | Needs only a sheet, a form and a weekly review, plus a named owner and runbook |
| 3. Build the MVP on the existing Afrovanguard and CACENTRE code | **Technically feasible** | Most components exist and are tested; about six gaps need to be built from scratch |
| 4. Sell it as a product against Asana, monday.com, ClickUp and Notion | **Unproven** | The gap is narrower than the design document claims; validate it internally first |

**Recommendation.** Do question 2 now and question 3 after the awards close. Do not start building before 15 November. The only build work that is urgent is paid voting, and the decision about it is below (see *Paid voting by about 16 November*).

## 1. Timing: what the calendar allows

Today is 6 November.

| Date | Item in the design document | Status |
| --- | --- | --- |
| 4 Nov | Nomination form live and tested on mobile (systems lead) | **Past due.** Open question in the design document; confirm today |
| 6 Nov | Prospect list of about 60 per award (180 total) | Due today |
| 8 Nov | Week-one checkpoint | 2 days away |
| 10 Nov | Pricing and voting rules sign-off (administrator) | 4 days away |
| 12 Nov | Voting and payment test across all three awards | 6 days away |
| 15 Nov | Gate 1: 20 confirmed nominees per award | 9 days away |
| ~16 Nov | Voting opens | 10 days away |
| ~6 Dec | Gate 2: ₦5M per award, reconciled | 30 days away |

No software build, test and handover fits into 9 days without putting the nomination push at risk. The design document already accepts this: it says to validate the logic on the real awards first, and to run the pilot only if it adds no work for volunteers.

## 2. Technical feasibility: what already exists

A survey of both repositories found that most of the loop already exists as tested code, spread over three applications. Reuse levels: **direct** means it can be called as is, **adapt** means the pattern exists but needs generalising, and **build** means nothing usable exists.

| Capability in the design | Existing asset | Reuse |
| --- | --- | --- |
| Goal to tasks | `lib/GoalPlanner.php` (`plan`, line 78), `lib/GoalTrack.php` (baseline, target, health, forecast; `checkin`, line 316), `lib/TaskMatch.php`; CACENTRE `includes/os/OsGoals.php` (goal cascade) | Direct |
| Contact pipeline with stages | CACENTRE `cac/includes/crm/` (pipelines and stages as data, `CrmLead::moveTo`, one activity timeline, rules-based scoring, about 20 test files) | Adapt |
| One-tap report | `GoalTrack::checkin`, `lib/Standup.php`; CACENTRE `OsPulse`, `OsCommitments` | Adapt |
| Impact-tiered approvals | CFIS `finance/app/Domain/Requisitions/ApprovalMatrix.php` (configurable bands, gap and overlap detection), `Approvals` (lapse, segregation of duties); AV `lib/Approvals.php` is two-person only | Adapt: port the band model |
| Proof of completion | CACENTRE `includes/os/OsEvidence.php` (`submit`, `verify`, `satisfies`, with evidence kinds ranked by strength) | Direct |
| Workspace rules | `lib/AvRules.php` (registry, override then env then default, conflict check, undo) and `docs/rules-engine.md` | Direct |
| Audit log | CFIS `Audit/AuditTrail.php` (append-only, hash-chained); `lib/AdminAudit.php` (supports undo) | Direct for the pattern |
| Reminders and notifications | `lib/Notifications.php` (de-duplicated), `lib/Reminders.php`, signed outbound webhooks | Direct |
| Escalation ladder | `lib/Accountability.php`, driven by `escalation.*` rules | Adapt: it is specific to mentorship today |
| Commitments that are chased | `lib/Commitments.php` and `docs/commitments.md` (idempotent, confidential miss reason) | Direct |
| Daily digest | `lib/TaskReport.php`, `lib/Brief.php` (figures computed in code, AI writes only the prose) | Adapt: there is no 3-decision cap and no "decision" item type |
| Payments and reconciliation | `lib/Payments.php` (Paystack primary, Flutterwave secondary, HMAC-SHA512 webhook check), `lib/PaymentWebhook.php`; CFIS `Reconciliation/Matcher`, `Settlements/SettlementSync` | Direct |
| AI suggestions | `lib/AvBot.php`, `AvAgent`, `AvPrompts`, `AvKnowledge`; works with no key | Direct |
| Roles | `AdminRoles`, `MemberRoles`, `AppTokens`; CACENTRE `OsRoles` (capability × scope) | Direct |
| Scheduled work | `tasks/cron.php` (about 20 sweeps, monitored by `AiOps`) | Direct |
| WhatsApp messages | CFIS `Notices/WhatsApp.php` (opt-in checked at send). **On `main` only** | Adapt: merge first |

### Gaps that must be built

1. **Awards and paid voting.** There is no code for nominations, categories or paid votes anywhere. `lib/Polls.php` is one free vote per member. A build would need a public nominee form, per-vote checkout through `Payments`, vote credit from the webhook that is safe to receive twice, fraud and rate limits, and public tallies.
2. **Shadow mode.** No rule or automation can yet record what it *would* have done.
3. **A general automation engine with undo.** CACENTRE `apps/taskforce/.../Automations.php` has no undo; `AdminAudit` undo is the starting point.
4. **A digest capped at 3 decisions**, with ranking by threat to a gate.
5. **A general escalation ladder** for any object, including request-and-escalate through someone else's line.
6. **An offline write queue.** `sw.js` caches pages but does not queue actions; the design needs IndexedDB plus replay with idempotency keys.
7. **One workspace model.** Goals, tasks, contacts and evidence live in three applications with three databases (AV on SQLite or MySQL, CACENTRE on MySQL, CFIS on Laravel). The applications deliberately share no database or session (`docs/HANDOFF.md` §4). The MVP must live in one of them; Afrovanguard is the natural host, because the goal, rules, commitments and escalation code are already there.

### Constraints the build must respect

- Shared cPanel hosting: no long-running process and no build step. Every job runs from the web cron and must survive missed or repeated runs.
- AI stays advisory and optional. Figures are computed in code, nothing is auto-assigned, and the system must work with no key.
- Thresholds go in `AvRules`, not in constants, which also gives the design's "workspace rules" for free.
- Each module gets tests in `tests/` and a `docs/*.md` decision record.

### Effort estimate

These are judgement estimates, not measured, for one developer who knows the code base. They exclude design work for Phase 2 and 3 features.

| Work | Estimate |
| --- | --- |
| Workspace model on top of `GoalTrack` and `GoalPlanner`, with a pipeline adapted from CACENTRE CRM | 3–4 weeks |
| Impact tiers (port of the CFIS band model), request-and-escalate | 2 weeks |
| Shadow mode, automation levels, undo, pause and kill switch | 2–3 weeks |
| Digest with decision cap, general escalation ladder | 1–2 weeks |
| Offline write queue for the three core phone actions | 1–2 weeks |
| Awards and paid voting module (if built rather than bought) | 2–3 weeks |
| Acceptance tests A1–A12 and B1–B10, hardening | 2 weeks |
| **Total** | **about 13–18 developer-weeks** |

## 3. Paid voting by about 16 November

Voting opens about 10 days from now, and nothing in the code can take paid votes. This is the one decision that cannot wait for the pilot.

| Option | For | Against |
| --- | --- | --- |
| **A. A Nigerian voting platform** (for example [Votesphere](https://www.votesphere.com.ng/), [Pevent](https://www.pevent.ng/voting), [VoteFest](https://votefest.infotekps.com/), [247Votes](https://247votes.com.ng/)) | Live in days; the platform carries the payment and fraud risk | Commission: Votesphere states 10% and 247Votes cites 15% on one tier, so **₦1.5M–₦2.25M of ₦15M**; the platform's data is outside our audit log |
| **B. Build on `lib/Payments.php` (Paystack)** | Keeps about 98% of revenue; reconciles directly against our records | 2–3 weeks of work squeezed into 10 days, untested under a voting spike, and it competes with the nomination push for the systems lead's time |

**Recommendation: option A for this cycle, option B after it,** unless the 12 November payment test already uses an in-house flow that the systems lead has built. Whichever is chosen, the ₦15M target must state whether it is gross or net, because the commission alone moves it by up to 15%.

Paystack's local card fee is widely reported as 1.5% plus ₦100, with the ₦100 waived under ₦2,500 and the fee capped at ₦2,000, though the sources found were third-party pages, not Paystack's own pricing page. On ₦15M that is roughly ₦225,000 plus the flat fees, depending on how votes are bundled.

## 4. The revenue target

The plan needs about **1,250 paid votes per nominee (₦250,000 each)**. No reliable public data on how many paid votes Nigerian awards draw was found. Vendor tallies are unverified, and the best-known large figure (BBNaija) was [shown by Daily Trust to be overstated](https://dailytrust.com/fact-check-nigerians-didnt-spend-n7-2bn-on-240m-votes-for-bbnaija/) because most votes were free.

What this means:

- ₦250,000 per nominee is equivalent to, for example, 125 supporters each spending ₦2,000. That is demanding for most nominees.
- Paid votes usually concentrate on a few nominees, so the average hides the risk. A handful of nominees with large followings will carry the total, and the rest will bring little. The "nominee momentum" ranking in the design is therefore more important than it looks, but it is Phase 2.
- The only reliable source of the figure is the organisation's own past awards. Getting it is still the most valuable open question in the design document.

**Verdict on the target: at risk until the past-awards figure is known.** Feasibility of the target, not of the software, is the biggest uncertainty in the whole plan.

## 5. Market feasibility

The design document's competitive review was built from search excerpts. One follow-up check narrows the gap it claims:

- Asana's help centre describes AI Studio workflows that pause on an approval task, where a reviewer can approve, reject or edit and redirect, and Asana describes its controls as approvals, assignments, comments and audit trails ([Asana help: human in the loop](https://help.asana.com/s/article/ai-studio-human-in-the-loop)). So "a human approves AI changes" is not a differentiator.
- What was **not** found in any source: approval tiers set by the impact of a change, threshold-gated replanning with rejection memory, a required proof type on every task, and a refusal to plan until the goal is measurable. These remain the differentiators, but only as a combination, and only until a hands-on trial confirms them.

The realistic market path is: prove it internally on Afrovanguard and CACENTRE campaigns, then offer it to similar organisations. Afrovanguard already runs goals, mentorship, commitments and payments, so it is its own first customer. Positioning against the four leaders should wait for the hands-on trials in next step 5.

## 6. Legal and data protection

Voter and nominee data (names, phone numbers, emails, payment references) falls under the Nigeria Data Protection Act 2023 and the NDPC's General Application and Implementation Directive (GAID), in force from 19 September 2025, according to law-firm summaries ([DLA Piper](https://privacymatters.dlapiper.com/2025/06/nigeria-ndpc-issues-gaid-key-compliance-insights/), [KPMG Nigeria](https://assets.kpmg.com/content/dam/kpmg/ng/pdf/2025/05/Review%20of%20the%20NDPA%20General%20Application%20and%20Implementation%20Directive%20GAID%202025.pdf)). Points that apply:

- Whether the organisation is a data controller "of major importance" (which brings registration, annual audit returns and a data protection officer) depends on thresholds that need a legal check.
- 72-hour breach notification is reported in the summaries.
- A data protection impact assessment is advisable before taking paid votes at scale.
- Sending data to an AI provider or a voting platform outside Nigeria is a cross-border transfer and needs a basis.

None of this blocks the pilot. It does block a public launch, which matches next step 6 in the design document.

## 7. The Five Operating Tests

The organisation's standard requires every capability, including a pilot, to pass all five tests before launch. The design document does not yet supply the evidence for test 1 (repeatability): a written runbook in the central repository with a named owner and a version date, and one unassisted run by someone who did not design it. The other four tests need the same treatment. Before the pilot starts:

1. Name one owner for the pilot (the project lead is the obvious choice).
2. Write a one-page runbook: the sheet, the daily report form, the weekly review, and who approves what.
3. Have one volunteer run a day of it unaided, and record the result.
4. Get a written exception from the executive sponsor for anything that cannot pass in time.

## 8. Recommended plan

**This week (before 8 November)**

1. Confirm that the nomination form is live and mobile-ready. It was due 4 November.
2. Decide on paid voting (option A or B in section 3) and confirm whether ₦15M is gross or net.
3. Ask the administrator for the average paid votes per nominee in past awards.

**8–15 November: pilot with simple tools**

4. Shared sheet for the pipeline (Prospect → Contacted → Interested → Nominated → Active), with *Nominated* meaning a submitted form.
5. A one-tap daily report form; the six starter rules applied by hand, in shadow mode, at the weekly review.
6. Record the baselines from next step 4: reports per volunteer per day, suggestions accepted, items surfaced against items detected.

**After Gate 2 (about 6 December)**

7. Review the pilot against the success criteria. Build the MVP only if they hold.
8. Build in Afrovanguard, in this order: workspace model and pipeline, impact tiers, shadow mode and automations, digest and escalation, offline queue, paid voting (if not bought), acceptance tests.
9. Complete the legal review and the hands-on competitor trials before any external positioning.

## Sources

Code: the two repositories at the branch above. Web pages were retrieved on 6 November 2026 and read as search summaries, not in full.

- [Asana help: AI Studio human in the loop](https://help.asana.com/s/article/ai-studio-human-in-the-loop)
- [Daily Trust: fact check on BBNaija voting revenue](https://dailytrust.com/fact-check-nigerians-didnt-spend-n7-2bn-on-240m-votes-for-bbnaija/)
- [Votesphere](https://www.votesphere.com.ng/), [Pevent voting](https://www.pevent.ng/voting), [VoteFest](https://votefest.infotekps.com/), [247Votes](https://247votes.com.ng/) (vendor pages)
- [Kolonell: Paystack transaction fees, Nigeria 2026](https://kolonell.com/en/blog/paystack-transaction-fees-breakdown-nigeria-2026), [Afrotools Paystack fee calculator](https://afrotools.com/tools/payment-fee-calculator/) (third-party; confirm against Paystack's pricing page)
- [DLA Piper: NDPC issues GAID](https://privacymatters.dlapiper.com/2025/06/nigeria-ndpc-issues-gaid-key-compliance-insights/), [KPMG Nigeria: review of GAID 2025](https://assets.kpmg.com/content/dam/kpmg/ng/pdf/2025/05/Review%20of%20the%20NDPA%20General%20Application%20and%20Implementation%20Directive%20GAID%202025.pdf)
