# Adaptive Goal Workspace: feasibility study

6 November 2026 · Assessed against the Product Design Document of 4 November 2026 · Code at branch `claude/finme-dues-training-fees-l54o5j` in `afrovanguard-site` and `cacentre-site`

The design document validates its logic on one reference scenario: three awards targeting ₦15M in paid votes. That scenario is a test case, not a live campaign, so this study judges the product, not the awards. Award-specific features (nominee forms, paid voting) are treated as one possible template, not as part of the core product.

## Verdict

| Question | Verdict | Why, in one line |
| --- | --- | --- |
| Can it be built on the existing Afrovanguard and CACENTRE code? | **Yes** | Most of the loop already exists as tested code; six pieces need building |
| What would the MVP cost? | **About 11–15 developer-weeks** | Judgement estimate; hosting and AI running costs are small |
| Will the loop work with real people? | **Unknown, testable cheaply** | The design rests on honest reports and deliberate approvals; neither has been tried with real users |
| Is there a market beyond our own organisations? | **Unproven** | The gap against Asana, monday.com, ClickUp and Notion is narrower than the design document says |
| Does it pass the organisation's Five Operating Tests today? | **Not yet** | No named owner, runbook or unassisted run exists yet |

**Recommendation.** It is worth building, but in this order: pilot the loop by hand on a real internal goal that already has payment data, then build the MVP inside Afrovanguard only if the pilot meets the design's own success criteria. Treat external sale as a later decision.

## 1. Technical feasibility

### What already exists

A survey of both repositories found that most of the loop already exists, spread over three applications: the Afrovanguard site (AV), the CACENTRE site (`cac/`) and the CACENTRE finance app (CFIS, `finance/`). Reuse levels: **direct** means callable as is, **adapt** means the pattern exists but needs generalising.

| Capability in the design | Existing asset | Reuse |
| --- | --- | --- |
| Goal to tasks | `lib/GoalPlanner.php` (`plan`, line 78), `lib/GoalTrack.php` (baseline, target, health, forecast; `checkin`, line 316), `lib/TaskMatch.php`; CACENTRE `includes/os/OsGoals.php` (goal cascade) | Direct |
| Measurability gate | `GoalTrack` already requires baseline, target and direction | Adapt |
| Contact pipeline with stages | CACENTRE `cac/includes/crm/` (pipelines and stages as data, `CrmLead::moveTo`, one activity timeline, rules-based scoring, about 20 test files) | Adapt |
| One-tap report | `GoalTrack::checkin`, `lib/Standup.php`; CACENTRE `OsPulse`, `OsCommitments` | Adapt |
| Impact-tiered approvals | CFIS `Requisitions/ApprovalMatrix.php` (configurable bands, gap and overlap detection), `Approvals` (lapse, segregation of duties); AV `lib/Approvals.php` is two-person only | Adapt: port the band model |
| Proof of completion | CACENTRE `includes/os/OsEvidence.php` (`submit`, `verify`, `satisfies`, evidence kinds ranked by strength) | Direct |
| Workspace rules | `lib/AvRules.php` (registry, override then env then default, conflict check, undo) and `docs/rules-engine.md` | Direct |
| Audit log | CFIS `Audit/AuditTrail.php` (append-only, hash-chained); `lib/AdminAudit.php` (supports undo) | Direct for the pattern |
| Reminders and notifications | `lib/Notifications.php` (de-duplicated), `lib/Reminders.php`, signed outbound webhooks | Direct |
| Escalation ladder | `lib/Accountability.php`, driven by `escalation.*` rules | Adapt: mentorship-only today |
| Commitments that are chased | `lib/Commitments.php`, `docs/commitments.md` | Direct |
| Daily digest | `lib/TaskReport.php`, `lib/Brief.php` (figures computed in code, AI writes only the prose) | Adapt: no 3-decision cap, no "decision" item |
| Hard signals from payments | `lib/Payments.php` (Paystack and Flutterwave, verified webhooks), `lib/PaymentWebhook.php`; CFIS `Reconciliation/Matcher`, `SettlementSync` | Direct |
| AI suggestions | `lib/AvBot.php`, `AvAgent`, `AvPrompts`, `AvKnowledge`; works with no key | Direct |
| Roles | `AdminRoles`, `MemberRoles`, `AppTokens`; CACENTRE `OsRoles` | Direct |
| Scheduled work | `tasks/cron.php` (about 20 sweeps, monitored by `AiOps`) | Direct |
| WhatsApp messages | CFIS `Notices/WhatsApp.php` (opt-in checked at send). **On `main` only** | Adapt: merge first |

### What must be built

1. **One workspace model.** Goals, tasks, contacts and evidence live in three applications with three databases, and the applications deliberately share none (`docs/HANDOFF.md` §4). The MVP should live in Afrovanguard, which already holds goals, rules, commitments and escalation.
2. **Shadow mode.** No rule or automation can yet record what it *would* have done.
3. **An automation engine with levels and undo.** CACENTRE `apps/taskforce/.../Automations.php` has no undo; `AdminAudit` undo is the starting point.
4. **A digest capped at 3 decisions**, ranked by threat to a gate.
5. **A general escalation ladder**, including request-and-escalate through someone else's line.
6. **An offline write queue.** `sw.js` caches pages but does not queue actions; the three core phone actions need IndexedDB plus replay with idempotency keys.

Template-specific features, such as nominee forms and paid voting for the awards template, are not in the core. Paid voting does not exist anywhere in the code; if an awards template is ever wanted, it is a further 2–3 weeks on top of `Payments`.

### Constraints the build must respect

- Shared cPanel hosting: no long-running process and no build step. Every job runs from the web cron and must survive missed or repeated runs. The design's batched, rules-first engine fits this well.
- AI stays advisory and optional. Figures are computed in code, nothing is auto-assigned, and the system works with no key. This matches the design's "rules first, AI second" principle.
- Thresholds go in `AvRules`, not constants, which also delivers the design's workspace rules.
- Each module gets tests in `tests/` and a decision record in `docs/`.

### Effort

Judgement estimates for one developer who knows the code base, not measured:

| Work | Estimate |
| --- | --- |
| Workspace model on `GoalTrack` and `GoalPlanner`; measurability gate; pipeline adapted from CACENTRE CRM | 3–4 weeks |
| Impact tiers (port of the CFIS band model); request-and-escalate | 2 weeks |
| Shadow mode, automation levels, undo, pause and kill switch | 2–3 weeks |
| Digest with decision cap; general escalation ladder | 1–2 weeks |
| Offline write queue for the three core phone actions | 1–2 weeks |
| Acceptance tests A1–A12 and B1–B10; hardening | 2 weeks |
| **Total** | **about 11–15 developer-weeks** |

## 2. Running cost

- **Hosting:** the existing cPanel accounts. No new infrastructure is needed at pilot or single-organisation scale.
- **AI:** low, by design. Rules decide whether anything needs attention, and the model only drafts for what passes, mostly at weekly replans and gates. The existing code already caps knowledge context and degrades to no AI.
- **Messaging:** WhatsApp template messages are charged per message by Meta; email is already in place. The design's notification budget keeps this bounded.
- **People:** the real cost is the time of the project lead and approvers. The design's 3-decisions-a-day cap is what keeps this affordable, and it must be measured in the pilot.

## 3. Will the loop work with real people?

The design's simulations used invented data, so they show the logic is consistent, not that people will behave as assumed. Three assumptions carry the product:

| Assumption | Why it matters | How the pilot tests it |
| --- | --- | --- |
| People file short reports honestly and on time | Adaptation is only as good as its input (design risk R1) | Report rate per person per day; share of reports that contradict hard signals |
| Approvers decide deliberately instead of rubber-stamping | Tiered approval is the main differentiator (R3) | Approval delay; share approved unchanged; time spent per decision |
| Fewer than 25% of detected items need a person | Otherwise the product adds admin instead of removing it | Surfaced-to-detected ratio, the design's own health metric |

All three can be tested by hand, with a shared sheet, a one-tap report form and a weekly review, before any code is written.

**Choosing a pilot goal.** The best pilot is a real internal goal with a number, a deadline and payment data that the system can check reports against. Two already exist in Afrovanguard:

- **An appeal** (`docs/appeals.md`): a named target in naira, with verified donations already recorded by `process-donation.php`.
- **NGV dues and fees collection** (`docs/ngv-fees.md`): obligations, payments, arrears and reminders already recorded.

Either gives the pilot hard signals from day one, which is what the design says the adaptation engine needs.

## 4. Market feasibility

The design document's competitive review was built from search excerpts. One follow-up check narrows the gap it claims:

- Asana's help centre describes AI Studio workflows that pause on an approval task, where a reviewer can approve, reject or edit and redirect. Asana also describes its controls as approvals, assignments, comments and audit trails ([Asana help: human in the loop](https://help.asana.com/s/article/ai-studio-human-in-the-loop)). So "a human approves AI changes" is not a differentiator.
- What was **not** found in any source: approval tiers set by the impact of a change, threshold-gated replanning with rejection memory, a required proof type on every task, and a refusal to plan until the goal is measurable. These remain the differentiators, but only as a combination, and only until a hands-on trial confirms them.

The realistic path is to prove it on Afrovanguard and CACENTRE goals first, since both organisations already run goals, commitments and payments, and then offer it to similar organisations. Public positioning should wait for the hands-on trials in the design's next step 5.

## 5. Legal and data protection

A goal workspace holds personal data (contacts, phone numbers, performance reports, reliability scores, payment references). In Nigeria that falls under the Nigeria Data Protection Act 2023 and the NDPC's General Application and Implementation Directive (GAID), in force from 19 September 2025, according to law-firm summaries ([DLA Piper](https://privacymatters.dlapiper.com/2025/06/nigeria-ndpc-issues-gaid-key-compliance-insights/), [KPMG Nigeria](https://assets.kpmg.com/content/dam/kpmg/ng/pdf/2025/05/Review%20of%20the%20NDPA%20General%20Application%20and%20Implementation%20Directive%20GAID%202025.pdf)). Points that apply:

- Whether the organisation is a data controller "of major importance" (which brings registration, annual audit returns and a data protection officer) depends on thresholds that need a legal check.
- Reliability scores and inferred judgements about people make a data protection impact assessment advisable. The design's "private by default" stance helps.
- Sending data to an AI provider outside Nigeria is a cross-border transfer and needs a basis.
- 72-hour breach notification is reported in the summaries.

None of this blocks an internal pilot. It does block selling to other organisations, which matches the design's next step 6.

## 6. The Five Operating Tests

The organisation's standard requires every capability, including a pilot, to pass all five tests before launch. The design document does not yet supply the evidence for test 1 (repeatability): a written runbook in the central repository with a named owner and a version date, and one unassisted run by someone who did not design it. The other four tests need the same treatment. Before the pilot starts:

1. Name one owner for the pilot.
2. Write a one-page runbook: the sheet, the report form, the weekly review, and who approves what.
3. Have one person who did not design it run a week of it unaided, and record the result.
4. Get a written exception from the executive sponsor for anything that cannot pass in time.

## 7. Recommended plan

**Stage 1: manual pilot (4–6 weeks)**

1. Pick one real goal with payment data: an appeal or the NGV dues drive.
2. Run it with a shared sheet, a one-tap report form and a weekly review, with the starter rules applied by hand in shadow mode.
3. Record the three measures in section 3 and the success criteria in the design document.

**Stage 2: decision gate**

4. Build only if the pilot meets the success criteria, especially the surfaced-to-detected ratio and approver load.

**Stage 3: MVP in Afrovanguard (about 11–15 developer-weeks)**

5. Build in this order: workspace model and pipeline, impact tiers, shadow mode and automations, digest and escalation, offline queue, acceptance tests.
6. Merge the CFIS features that are on `main` only (WhatsApp notices) before relying on them.

**Before any external offer**

7. Complete the legal review and the hands-on competitor trials.

## Sources

Code: the two repositories at the branch above. Web pages were retrieved on 6 November 2026 and read as search summaries, not in full.

- [Asana help: AI Studio human in the loop](https://help.asana.com/s/article/ai-studio-human-in-the-loop)
- [DLA Piper: NDPC issues GAID](https://privacymatters.dlapiper.com/2025/06/nigeria-ndpc-issues-gaid-key-compliance-insights/)
- [KPMG Nigeria: review of GAID 2025](https://assets.kpmg.com/content/dam/kpmg/ng/pdf/2025/05/Review%20of%20the%20NDPA%20General%20Application%20and%20Implementation%20Directive%20GAID%202025.pdf)
