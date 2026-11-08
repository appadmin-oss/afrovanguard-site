# Adaptive Goal Workspace: design revision 1

6 November 2026 · Amends the Product Design Document of 4 November 2026 · Companion to `adaptive-goal-workspace.md` (feasibility study)

This revision fixes the three weaknesses found in the feasibility review:

1. The engine trusts what people report, and can adapt confidently to bad information.
2. The first version is too big for a team of about 10.
3. The claimed advantage over Asana, monday.com, ClickUp and Notion is thin.

Each fix states what changes in the design document and where. Anything not mentioned here stands as written.

---

## Fix 1: the system must know when it does not know

**Problem.** The design assumes honest, timely reports and deliberate approvals. If reports are late or optimistic, or approvers approve without reading, the engine still produces confident proposals. That is worse than no engine, because it looks authoritative.

**Principle added to the design principles:** *The system never presents a conclusion more confidently than its evidence allows.*

### 1.1 Every piece of evidence carries a grade

| Grade | Examples | What it may trigger |
| --- | --- | --- |
| **Hard** | Payment received, form submitted, file uploaded, data check passed | Any proposal, at any tier |
| **Mixed** | A report that a hard signal partly confirms | Small and medium proposals |
| **Reported only** | A status report with nothing to check it against | A question to the person who reported, never a proposal on its own |

Every suggestion shows its grade next to its one-line impact. A medium or large proposal built only on reported evidence is not allowed. The engine asks for confirmation first.

*Changes:* adaptation engine rule 1 (threshold gating) gains "and the evidence is Mixed or Hard"; feature C "Evidence-cited suggestions" gains the grade.

### 1.2 Low-confidence mode

The engine checks the health of its own data before it adapts.

- **Report coverage:** the share of active people who reported in the period.
- **Freshness:** the age of the newest hard signal for each metric.
- **Mismatch rate:** the share of reports that a hard signal contradicted.

If coverage falls below 60%, or no hard signal is connected for a metric, or the mismatch rate rises above 20%, that metric enters **low-confidence mode**. In that mode:

- the engine makes no replanning proposals for the metric;
- the digest's first line says so in plain words, for example: *"Not enough data to adapt the nominee target: 4 of 10 people reported this week, and no form data is connected."*;
- the one action offered is to fix the data, not the plan.

The three thresholds are defaults stored in `AvRules`, to calibrate in the pilot.

*Changes:* new adaptation engine rule 15; setup health (onboarding section) shows each metric's confidence.

### 1.3 Reports are checked against outcomes, not ranked by person

- When a task closes with proof, the system compares what was reported during the task with what the proof shows. The gap is recorded **per task type** (for example "outreach tasks are reported 30% ahead of forms received"). It then discounts future reports of that type.
- No per-person score is shown to anyone but the person concerned, as in the original anti-gaming rule. Rule R7 (surveillance feel) stands.

*Changes:* replaces "per-person reliability weighting" in risk R1 with per-task-type calibration. Acceptance test A9 stands.

### 1.4 Approvals are judged by results

The original mitigation for rubber-stamping included "occasional test suggestions". **That is removed.** Planting false suggestions to test staff is deceptive, and once discovered it destroys trust in every real suggestion.

It is replaced by:

- **Outcome review at each gate.** A one-page list of the changes approved since the last gate, each with the metric it claimed to move and what actually happened. Approvers see whether their decisions worked.
- **Speed signal, not a block.** Medium and large approvals made in under 10 seconds are counted in the workspace health metrics, never shown as a personal score.
- **The reject path is as easy as approve.** One tap each, with the evidence grade visible on both.

*Changes:* risk R3 mitigation; new health metrics below.

### 1.5 New health metrics

Added to the success criteria table:

| Objective | Success criterion (target to validate in pilot) |
| --- | --- |
| Data the engine can trust | Report coverage at or above 60% in at least 3 weeks out of 4 |
| Honest reporting | Report-to-proof mismatch under 20% |
| Deliberate approvals | At gate reviews, at least half of approved changes moved their metric in the claimed direction |

---

## Fix 2: a smaller first version

**Problem.** Four automation levels, three approval tiers, 14 engine rules, a workspace rule builder, shadow mode and an escalation ladder is a lot for 10 people. Each one has to be learned, configured and trusted.

**Key simplification.** In version 1, **the system never acts on its own judgement.** It only notifies and suggests. The only automatic changes are recordings of facts, such as moving a record to Nominated when the form arrives. With no automatic actions, shadow mode, undo windows and kill switches are not needed yet.

### 2.1 What version 1 contains

| Kept in version 1 | Simplified to |
| --- | --- |
| Measurability gate and conversational goal refinement | As designed, with assumptions listed on the goal |
| Pipeline: records and stages that drive task counts | As designed; stage moves from hard signals only (form, payment) |
| One-tap reports | As designed |
| Proof type per task, proof hold | As designed |
| Daily digest, at most 3 decisions | As designed, plus the low-confidence line (fix 1.2) |
| Approvals | **Two tiers**: the task owner decides on their own tasks; the lead (or single owner) approves anything touching the goal, money, deadlines or people |
| Engine rules | **6 of 15**: threshold gating with evidence grade (1), goal anchoring (3), rejection memory (6), check hard signals before nudging (10), rank and cap (12), low-confidence mode (15) |
| Requests to people outside the lead's authority | A request with a date; a missed date becomes a digest item for the lead |
| Audit log | As designed |
| Mobile | Light pages that work on a slow connection, with a retry on failure |

### 2.2 What waits, and what would bring it back

Each deferred feature returns only when the pilot or version 1 shows the need, measured as follows.

| Deferred feature | Bring it back when |
| --- | --- |
| Third approval tier (administrator) | More than 3 decisions a week need someone above the lead, for 2 weeks running |
| Automation levels 2 and 3, with shadow mode, undo and kill switch | One suggestion type is accepted unchanged at least 90% of the time, over at least 20 cases |
| Plain-language rule builder and workspace rules | Owners ask for more than 3 rules that the fixed thresholds cannot express |
| Multi-step escalation ladder | More than 20% of requests to people outside the lead's line miss their date |
| Offline write queue | More than 10% of report attempts fail on connection |
| Engine rules 2, 4, 5, 7, 8, 9, 11, 13, 14 | The specific failure each one prevents is seen in practice |
| Forecast ranges, momentum ranking, multi-goal portfolio | Unchanged: Phase 2 |

### 2.3 Effect on effort

The feasibility study's estimate for the original MVP was 11–15 developer-weeks. Version 1 as defined here is estimated at **7–10 developer-weeks**; this is a judgement estimate, not a measurement.

| Work | Estimate |
| --- | --- |
| Workspace model, measurability gate, pipeline (on `GoalTrack`, `GoalPlanner`, CACENTRE CRM) | 3–4 weeks |
| One-tap reports, proof hold (on `GoalTrack::checkin`, `OsEvidence`) | 1–2 weeks |
| Digest with cap, evidence grades, low-confidence mode | 1–2 weeks |
| Two-tier approvals, requests with dates | 1 week |
| Acceptance tests, audit log, hardening | 1 week |

Acceptance tests that need deferred features (A2 large tier, B1–B5, B9) move with those features.

---

## Fix 3: compete on fit, not features

**Problem.** Individual features (AI plans, approvals, audit logs) already exist in the leading tools or can be copied quickly. Asana's AI Studio already pauses workflows on approve, reject or edit steps.

**Change in strategy:** version 1 is an **internal system for Afrovanguard and CACENTRE**, not a product for sale. Its advantage is fit, which the leading tools cannot easily copy:

- **The hard signals are already there.** Members, mentorship, commitments, appeals, NGV dues and Paystack payments already live in our systems. A general tool has to be integrated to see them; ours starts with them, which is also what fix 1 depends on.
- **It follows how we already work.** It uses our roles, our rules engine (`AvRules`), our commitments and our accountability practice, not a generic template.
- **It fits the local context.** Naira payments through Paystack and Flutterwave, WhatsApp where people already coordinate, slow connections, and the Nigeria Data Protection Act.

*Changes to the design document:*

- Executive summary, "The gap": restate as fit with our own systems and evidence-graded adaptation, not as features missing from competitors.
- Competitive landscape: keep it as background, and add Asana's approval steps to the overlap list.
- Users: "Designed for any goal-driven team" becomes "Designed first for Afrovanguard and CACENTRE goals".
- Risk R8 (competitors close the gap): the mitigation becomes "do not compete on features; the system's value is its integration with our own data".
- Risk R9 (scope too broad): resolved by the internal-first decision.

**When an external offer may be reconsidered.** All of the following must hold:

1. At least two internal goals have run on version 1 and met the success criteria, including the new ones in fix 1.5.
2. Hands-on trials of Asana, monday.com, ClickUp and Notion confirm that something important is still missing from them.
3. The legal and data-protection review is complete.

---

## Summary of changes to the design document

| Section | Change |
| --- | --- |
| Executive summary | Gap restated as fit and evidence-graded adaptation; internal first |
| Problem and objectives | Three new success criteria (fix 1.5); users narrowed to our own organisations |
| Competitive landscape | Asana approval steps added to the overlap |
| Product concept, design principles | New principle: never more confident than the evidence |
| Roles and approvals | Two tiers in version 1; third tier returns on trigger |
| Feature specification | Version 1 as in fix 2.1; deferred features with triggers as in fix 2.2 |
| Adaptation engine rules | Rule 1 gains evidence grade; new rule 15, low-confidence mode; version 1 runs 6 rules |
| Automations | Version 1 notifies and suggests only; levels 2 and 3 deferred |
| Quality assurance | Acceptance tests tied to deferred features move with them |
| Risk register | R1, R3, R8 and R9 mitigations replaced; "test suggestions" removed |
| Roadmap | Pilot, then version 1 (7–10 developer-weeks), then features by trigger |
