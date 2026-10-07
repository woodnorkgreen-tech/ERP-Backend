# Report 75 — Finance Configuration & Governance Centre

**WNG ERP — Finance.** Prepared 2026-10-06. Baseline: Report 74A.
**Scope:** a permanent place in the ERP where WNG Finance proposes, reviews, approves, dates, activates and audits its own configuration. Not a Finance redesign. No cutover was performed and no decision was made on WNG's behalf.

---

## 1. Executive summary

Finance → Setup is now **Finance Setup & Controls**: eleven sections in business language, built on one governance mechanism.

| What was asked | What exists |
|---|---|
| Configuration follows *propose → review → approve → effective date → activate → use → audit* | One lifecycle, enforced on the server, for every governed item. Nothing a person clicks changes accounting behaviour except activating an approved proposal. |
| Proposed ≠ approved ≠ active | Three separate states with separate permissions. An approved policy that has not been activated, or whose date has not arrived, governs nothing (tested). |
| Technical access ≠ accounting authority | Approving and activating need permissions that are checked directly. A Super Admin can see everything and approve nothing (tested). No role is granted approval authority by default. |
| WIP policy as the first controlled policy | Decided in the ERP by radio button, neither option preselected, accountant approval required. Report 74A's fail-closed behaviour is kept: no decision, a draft, a submission, an unactivated approval, a future date or a conflict all leave `POLICY REQUIRED`. |
| Do not auto-migrate current decisions | Nothing was migrated. The 37 mappings, 116 classifications, seeded thresholds and bank links appear as **suggestions awaiting review**. Opening the centre creates no record (tested). |
| Readiness distinguishes resolved from approved | "37 of 37 posting functions technically resolve… 0 of 37 are approved by the accountant" is the literal readiness message on WNG's chart today. |

In numbers, on WNG's completed chart: **190 governable items** (the WIP policy, 37 mappings, 120 classifications, 6 bank and cash accounts, M-Pesa, the company card, 15 settings, the float ceiling and 8 tax rows). On day one 166 carry a suggestion awaiting review and 24 need a decision; none is approved. Behind them: 3 new tables, 7 new permissions, 12 API endpoints and one screen with 11 sections.

**Tests.** Backend: 29 new governance tests pass, and with the chart, readiness, period and permission suites 126 passed on the final code. The wider Finance regression is 1,012 passed and 39 failed; none of the 39 comes from this work — 34 are in petty-cash classes another session was rewriting while the run was in progress, and 5 are a test that refuses any database not named `db_test` (§27). Frontend: 42 new tests pass; the full frontend suite has the same 5 pre-existing failures as the baseline; type errors are 273 before and after (zero new); the production build passes.

**What it does not do**, stated up front (§30): it does not stop an *unapproved* mapping from being used for posting; the old paying-accounts screen can still change a bank link directly (the centre detects it afterwards); and approved classifications are not yet what the cutover tool applies. Each is deliberate (no breaking switch in this report) or small, and each is listed.

**Two incidents during the work**, neither affecting the result (§27): another test run on the shared test database collided with mine, and the workstation rebooted mid-stream. Both are disclosed with what was redone.

**Verdict: FINANCE CONFIGURATION & GOVERNANCE CENTRE COMPLETE — HUMAN APPROVALS AND TARGET DATA GATES REMAIN** (§34).

---

## 2. Baseline

| | Backend (`ERP-Backend`) | Frontend (`ERP-Frontend`) |
|---|---|---|
| Branch / HEAD | `master` / `5a5a3db738110d153610e3e5b6e5da4d9ab2d4db` | `master` / `b82f27895c3d05e67d7d6029224d016783c8f127` |
| Dirty paths at start | 54 | 10 |

Already present and preserved: Reports 73 / 73A (readiness controller, control-centre service, tests, reports), Report 74 (`CompleteChartCommand`, `FinanceChartProfile`, the reuse mechanism, the Supplier Credit guard), Report 74A (resolver fix, WIP fail-closed, target chart identity) and unrelated streams. No reset, stash, discard, merge, commit, push or deploy. `database/finance/wng-chart-profile.json` is still identical to HEAD.

Reports 74 and 74A were re-read; 74A is treated as authoritative. Nothing superseded was reintroduced: the COS-021/022/023 parent is untouched, production chart identity is still unverified, and no production state is inferred from any local database.

**Baseline tests, before any change in this report:**

| Suite | Result |
|---|---|
| Backend Finance / CostCollector / PettyCash / Seeding (Report 74A's final run, on the code this report started from) | 971 passed, 1 failed (the order-dependent `CostCollectorApiTest` case explained in 74A §14) |
| Frontend unit tests | 459 passed, 5 failed (all in `tests/unit/design/designTables.spec.ts`, unrelated to Finance) |
| Frontend type-check | 273 errors, none under `src/modules/finance/setup` |

**Environment.** Local `db` was not migrated or written to: the new tables exist only in disposable test databases. Backend commands and tests ran in one-off containers that start no queue worker.

---

## 3. Architecture reused

Nothing authoritative was duplicated. Where a store already existed, the governance layer feeds it.

| Existing piece | How it is used |
|---|---|
| `finance_settings` (already effective-dated, already carries `approved_by` / `approved_at`) and `FinanceSetting::approvedValue()` | An activated setting is written as a new approved row; the previous row is closed, not overwritten. Every existing reader (`PettyCashCap`, `PurchaseApprovalPolicy`, the voucher threshold, petty cash alerts) works unchanged and now has a way to receive an approved value, which it never had. |
| `ChartAccountMap` | Still the only place a reference code becomes an account. It asks the governance runtime first; there is no second map. |
| `FinanceChartProfile` and the WNG profile | Supply the *suggestions*. Unchanged. |
| `FinanceAccountFunctions` | The 37 functions are the 37 mapping items. |
| `payment_sources` | Bank, M-Pesa and card decisions are applied to the existing rows on activation. |
| `vat_treatments`, `wht_categories` | Verified as they stand; not copied. |
| `AccountingPeriod`, `PeriodCloseService`, `assertOpenPeriod`, the existing period-control screen | Untouched. The Setup section reads the existing endpoint. |
| `ChartIdentity` (extracted from Report 74A's command; same logic) | One definition used by both the cutover tool and the Setup overview, so they cannot disagree. |
| Spatie permissions, the `Permissions` registry, the `RolePermissions` matrix | Seven permissions added the standard way. |
| Finance design system (`finance-control.css`, `FinancePageFrame`, `FinanceState`) | The new screen uses the existing cards, chips, tabs and typography. No new stylesheet. |
| `FinanceReadinessController`, `FinanceControlCentreService`, `FinanceReadiness` | Consume governance state (§23). |

The previous Setup page (technical reference checks) is kept at **Setup → System checks**.

---

## 4. Governance data model

**Items are defined in code; decisions are stored.** What can be decided is derived from things that already exist (the functions, the chart, the paying accounts, the settings, the tax tables), so there is no items table to seed or to drift. `GovernanceCatalogue` describes each item: its title, the question in plain words, why it matters, what it affects, its type, who must approve, what a valid answer is, and the current suggestion.

Three new tables, all empty on arrival:

| Table | Holds |
|---|---|
| `finance_config_versions` | One row per version of one item: value (JSON), reason, requested effective date, in-force dates, status, revision counter, proposer / reviewer / decider / activator with timestamps, the version it supersedes. |
| `finance_config_approvals` | Each review, return, rejection and approval: requirement, decision, actor, comment, time. |
| `finance_config_audit` | Append-only: action, actor, before, after, reason, effective date, time. Nothing updates or deletes a row. |

Guarantees held by the database, not only by code:

| Guarantee | How |
|---|---|
| One open proposal per item | `open_key` holds the item key while a version is a proposal and NULL once decided; unique. |
| Two versions of one item never start on the same day | `active_key` = item + in-force date; unique. |
| Version numbers do not collide | unique (item, version), allocated under a row lock. |
| A stale screen cannot act | every action quotes the `revision` it saw; a mismatch is HTTP 409. |

A decided version is never edited. A change is a new version; the old one keeps its value, approver and dates.

Value types, each validated and reduced to exactly its own fields (a hidden form field cannot ride along): `choice`, `account`, `classification`, `number`, `bank`, `mpesa`, `card`, `verification`.

---

## 5. Roles/permissions

Seven permissions. Nobody is named anywhere in the logic.

| Permission | Allows | Granted by the migration to |
|---|---|---|
| `finance.config.view` | See Finance Setup & Controls | Super Admin, Admin, Manager, Accounts, Costing |
| `finance.config.propose` | Draft, edit, submit, withdraw | Super Admin, Accounts, Costing |
| `finance.config.review` | Mark under review; return for correction | Super Admin, Accounts |
| `finance.config.approve_operational` | Approve items needing Finance review | **nobody** |
| `finance.config.approve_accounting` | Approve items needing accountant approval | **nobody** |
| `finance.config.approve_management` | Approve items needing management approval | **nobody** |
| `finance.config.activate` | Activate an approved proposal | **nobody** |

**The four authority permissions are granted to no role.** Who holds accounting authority at WNG is WNG's decision. It is assigned to a role in Admin → Roles, deliberately. Until it is, nothing can be approved, and the overview says so in words ("Nobody holds Accountant approval authority yet…").

**Super Admin is excluded on purpose, in two ways.** The role matrix gives Super Admin every permission *except* the four authority permissions; and the governance service checks permissions directly and does not go through the gate, because the gate's Super Admin bypass answers yes to everything. Tested: a Super Admin sees the whole centre, has no approve or activate button, and is refused (403) if the endpoint is called anyway. A Super Admin *can* prepare, review, return and withdraw proposals, like anyone holding those working permissions (visible in the screenshot `desktop-admin-no-authority.png`); none of those changes how the books behave. If WNG would rather administrators could only look, remove those two grants from the role.

**Honest limit.** Whoever can edit roles can grant themselves a permission. No permission system prevents that. What this design guarantees is that authority is never *implicit*, that every approval names its approver in an audit nobody can edit, and that nobody approves their own accounting proposal.

Which approval an item needs:

| Requirement | Items | Permission |
|---|---|---|
| Accountant approval | WIP policy, all 37 mappings, all classifications, M-Pesa, company card, tax rows, capitalisation threshold, tax due day, VAT claim window | `approve_accounting` |
| Management approval | Petty cash limit and float ceiling, purchase order and voucher approval thresholds, margin and overrun thresholds | `approve_management` |
| Finance review | Bank and cash accounts, petty cash alerts, surrender deadline, bank-matching tolerance | `approve_operational` |

This assignment is a design default set in the catalogue. It decides none of WNG's values; if WNG wants a threshold signed by someone else it is a one-line change, listed in §31.

**Segregation of duties.** Nobody approves their own proposal where accountant or management approval is required, whatever else they hold. For operational items self-approval needs the existing `approvals.self_approve` permission and is recorded. The screen hides the Approve button from the preparer and says why.

---

## 6. Configuration lifecycle

```
DRAFT → SUBMITTED → UNDER REVIEW → APPROVED → ACTIVE → SUPERSEDED
              ↘ RETURNED FOR CORRECTION → (edited) → SUBMITTED
              ↘ REJECTED
   (DRAFT / RETURNED / SUBMITTED may be WITHDRAWN)
```

| Step | Who | What changes in the books |
|---|---|---|
| Draft, edit | `propose` | Nothing |
| Submit | `propose` — needs an answer, a reason and an effective date | Nothing |
| Start review | `review` | Nothing |
| Return for correction | `review` or the item's approver — comment required | Nothing |
| Reject | the item's approver — comment required | Nothing; the question stays open |
| Approve | the item's approver, not the preparer | **Nothing** |
| Activate | `activate` | The decision comes into force on its date |
| Withdraw | `propose` | Nothing; kept in history |

Rejected and withdrawn versions are kept. There is no delete. A returned proposal is corrected as the same version; a new proposal after a rejection is the next version.

What an item shows overall: *Decision required* (no answer, no suggestion) · *Awaiting review* (a suggestion exists) · *Draft* · *Returned for correction* · *Awaiting approval* · *Approved — awaiting activation* · *Approved — takes effect later* · *Active* · *Review required* (changed since approval) · *Configuration conflict*.

---

## 7. Effective dating

Each version carries two dates, and the difference matters:

| Date | Meaning |
|---|---|
| **Apply from** | What Finance asked for and the approver approved: "not before this date". Cannot be in the past. |
| **In force from** | When it actually governs: the later of the approved date and the day it was activated. Set once, at activation. |

Because a version never comes into force earlier than the day it is activated, **no decision made today can change the meaning of a transaction posted before it**. If an approval sits unactivated past its date, it starts on the day it is activated (tested).

Activating a new version ends the previous one the day before the new one starts; the previous one keeps its value and approver. The brief's example works as written: version 1 *Capitalise*, active; version 2 *Expense on capture*, approved and activated for 1 January 2027, shown as "Approved — takes effect later" until that day, when version 1 becomes "Superseded" with an end date of 31 December 2026 (tested, including the as-of lookups).

**As-of resolution.** `GovernanceRuntime::value($key, $date)` returns the version in force on any date. Runtime posting asks for today. Historical accounting was not retrofitted to ask for the transaction date; the architecture supports it and nothing in this report changes past entries.

**Two kinds of item behave differently, by necessity:**

- *Read live* (WIP policy, mappings) and *settings* (written to `finance_settings`, itself effective-dated): may be activated ahead of their date and wait for it.
- *Written into a live record* (a bank account's link and on/off state, M-Pesa, the card, the float ceiling): take effect the moment they are written, so they cannot be activated before their approved date. The screen says so.

No scheduler or queue worker is involved anywhere: what is in force is worked out from dates when asked.

---

## 8. Finance Setup navigation

Finance → Setup now opens **Finance Setup & Controls**. It is the existing Setup entry in the existing Finance navigation; there is no second Finance application and no second navigation rail (one section-tab strip inside the page, tested).

| Section | Contents |
|---|---|
| Overview | Readiness by area; who can approve |
| Accounting Policies | Project cost treatment (WIP), capitalisation threshold, bank-matching tolerance, margin and overrun thresholds |
| Account Mapping | The 37 posting functions |
| Account Classification | 120 accounts, by confidence |
| Banks & Payment Methods | Bank and cash accounts; M-Pesa; company card |
| Petty Cash & Cash Controls | Limit per payment, float ceiling, alerts, surrender deadline |
| Tax Configuration | VAT treatments, withholding categories, filing day, claim window |
| Accounting Periods | Period status, read-only, with a link to the period controls |
| Document Numbering | How each document is numbered today |
| Approval Rules | Purchase order and payment voucher thresholds |
| Configuration History | The audit feed |

Each tab shows how many of its items still need somebody, from the server's own count. The section and the open item are in the URL (`?section=mapping&item=mapping.bank_charges`), so a link to one decision can be sent to the accountant.

Two entries under Setup: **Setup & controls** and **System checks** (the former page, unchanged in content). Links that pointed at the old page's anchors were repointed. The other Setup pages (money accounts, payment terms, request forms) are untouched.

---

## 9. Overview/readiness

The overview answers one question: *what still needs a person?*

- **A count, not a percentage:** "1 of 13 required areas ready". The areas differ in size and weight (one policy; 37 mappings; 116 classifications), so no percentage is computed from them. The denominator is the number of required areas; it is returned by the server and tested.
- **An area is ready only when every required item in it is approved, activated and in force.** A suggestion, a draft, or something the software can merely resolve does not count. That sentence is on the screen.
- **Every area that is not ready states three things** — *Why is this blocked? What needs to happen? Who needs to act?* — and links to the first item that needs attention.

On WNG's completed chart, before anybody has decided anything, the overview reads:

| Area | Status | Headline |
|---|---|---|
| Accounting structure | Verified | This database holds the company's own chart (152 accounts) |
| Project cost treatment (WIP policy) | Decision required | No answer yet |
| Account mapping | Awaiting review | 0 of 37 approved and active |
| Account classification | Awaiting review | 0 of 116 approved and active · 4 more left open for the accountant |
| Bank accounts | Awaiting review | 0 of 6 confirmed and active |
| M-Pesa | Decision required | No answer yet |
| Company card | Decision required | No answer yet |
| Petty cash controls | Decision required | 0 of 6 approved and active |
| Approval rules | Decision required | 0 of 3 approved and active |
| Other accounting thresholds | Decision required | 0 of 5 approved and active |
| Tax | Decision required | 0 of 10 verified and active |
| Accounting periods | Review required | Current period open; earlier years still open |
| Document numbering | Data required | 1 of 9 document types use a controlled series |

That is real output captured from the API on a test database (`tests/fixtures/75/data/fresh-preparer.overview.json`), not a mock-up. The "Accounting structure" row is the Report 74A chart-identity check for *that* database; it says nothing about production.

Below the areas, **Who can approve** lists the holders of each authority, or says that nobody holds it.

---

## 10. WIP governance

**On screen** (Accounting Policies → Project cost treatment):

> **How should costs for ongoing projects be treated?**
> ○ Hold as Work in Progress until release
> ○ Recognise as project cost immediately
> *Accountant approval required.*

Two radio buttons, neither selected. The profile's default is **not** shown as a suggestion: this item deliberately carries none. The words `capitalise`, `expense_on_capture` and `FINANCE_WIP_POLICY` do not appear on the screen (tested). A proposal needs the choice, a reason, an effective date, the proposer, the accountant's approval and its timestamp; all are recorded.

**What the ledger does, state by state** (each tested against the posting guard, the account map and readiness):

| State | Project cost posting |
|---|---|
| No decision | `POLICY REQUIRED` — refused |
| Draft | `POLICY REQUIRED` — refused |
| Submitted / under review | `POLICY REQUIRED` — refused; readiness says `AWAITING APPROVAL` |
| Approved, not activated | `POLICY REQUIRED` — refused; readiness says `APPROVED — NOT ACTIVATED` |
| Activated for a future date | `POLICY REQUIRED` until that date |
| Active | Permitted. Held as WIP → `WIP-002…010`; recognised immediately → each function's cost-of-sales account |
| Active, but the deployment setting says otherwise | `CONFIGURATION CONFLICT` — refused; neither is applied |

Report 74A's guards are unchanged and still do the refusing: the nine WIP functions map to a marker that is not an account, `assertWipPolicy` stops coded project costs, the WIP release refuses, and invoice issue is refused with the reason.

**Precedence, explicitly** (`GovernanceRuntime::wipPolicy`):

1. A policy approved and active in the ERP governs.
2. `FINANCE_WIP_POLICY` is a **bootstrap**: honoured only while the ERP holds no active policy, so the rehearsal and cutover tooling keep working. Everywhere it is reported as "supplied by a deployment setting, not approved", and the readiness gate does **not** pass on it.
3. If both exist and disagree: `CONFIGURATION CONFLICT`, fail closed. Nothing is guessed.
4. `FINANCE_WIP_POLICY_AUTHORITY=governed` retires the bootstrap: the ERP becomes the only source and the environment value alone supplies nothing.

No breaking switch was made: the default authority is `transition`, under which everything behaves as it did after Report 74A until a policy is activated in the ERP. The migration path is in §22.

**No policy was chosen.** No proposal exists in any real database.

---

## 11. Mapping governance

Account Mapping shows all 37 functions: business function, the account (code and name), its kind, the basis for the suggestion, the review status and the active version. A table on a wide screen; cards on a phone.

- **All 37 start as "Awaiting review".** Each carries the account proposed for WNG's chart as a *suggestion*. None is approved; the overview says `0 of 37 approved and active`.
- **The six judgement mappings stay visibly flagged** — `staff_advances`, `cos_direct_labour`, `cos_project_facilitation`, `cos_rework_warranty`, `inventory_adjustments`, `bank_charges` — each with its reason from Report 74, under an "Individual decision" filter. They **cannot be bulk-approved**: their tick box is disabled and the server refuses them in a bulk request (tested).
- **The other 31 are not silently approved either.** They can be approved one at a time, or several together.

**Bulk approval is never "select all → done".** The accountant ticks proposals (a "select the eligible ones shown" shortcut exists), presses *Approve N selected…*, and is shown a numbered list of exactly what is being approved, each with its account. Confirming sends those proposals at the revisions shown; the server approves all of them or none, refuses if any changed, and refuses a confirmed count that does not match (tested). Approval still activates nothing.

**Bulk activation works the same way**, for the person who holds activation authority: only already-approved proposals can be ticked, the list is confirmed, and one unapproved proposal in the request activates none of them (tested). Its dialog says plainly that this is the step that changes how the books behave.

**Putting suggestions forward** is a separate, earlier step for a preparer: *Submit N for approval…*, with a reason and a date, creating one proposal each in that person's name. It approves nothing.

**Changing an assignment.** The account picker is a search that lists **only compatible accounts**: active, postable, the right side of the balance sheet for that function, and not contradicting a classification already set. The rule comes from what the reference chart says that function's account is. The server applies the same rule, so an incompatible account cannot be proposed by any route (tested: a liability for an expense function, a header, an inactive account and an unknown code are all refused). A reason is required; the previous version is preserved; the change is audited.

**No second map.** An approved, active mapping is read by `ChartAccountMap::local()` — the function everything already uses — through one adapter (`GovernanceRuntime::account`). Tested: after activation `bank_charges` resolves to the newly approved account for posting, readiness and the expense-code seeder, and nothing else moves.

**Two limits, stated plainly:**

1. *An unapproved mapping is still used.* Where no mapping has been approved, posting continues to use the profile's account, exactly as before this report. Readiness reports "accountant approval required"; it does not stop posting. Making approval a precondition for posting would be a breaking switch and belongs with the cutover decision (§30, §33).
2. *The cutover tool plans from the profile.* If the accountant approves a different account from the profile's, `finance:complete-chart` now warns that the profile no longer matches (tested), and the profile must be updated before cutover.

---

## 12. Classification governance

Account Classification shows the 120 accounts with a filter per confidence level:

| Confidence | Accounts | Bulk review |
|---|---|---|
| Deterministic — follows from the category already recorded | 17 | Allowed |
| Strong evidence — follows the account family WNG files it under | 86 | Allowed |
| Accountant judgement — the 13 from Report 74, each with its reason | 13 | **Individual decision only** |
| Unclassified — OPE-026, ITX-001, LDO-001, EQE-001 | 4 | **Individual decision only** |

The counts are asserted in a test against WNG's chart.

For the four unresolved accounts the screen shows the account, its current classification (none), **why a decision is required** (the profile's own explanation), the classifications compatible with that kind of account, and a form for the accountant's decision, reason and effective date. **No answer is suggested for them and none is filled in.** They are not counted as owed in the area total ("4 more left open for the accountant"), matching Reports 74 and 74A.

**Approval records the decision; it does not classify the chart.** Nothing on this screen runs `--classify-existing`, and approving a classification writes nothing to `chart_of_accounts` (tested: every row identical before and after). Applying classifications remains a cutover step; §30 lists the gap that follows.

---

## 13. Banks/payment methods

Each bank and cash account is its own item: the account, its ledger account, whether it is in use, its status and effective date. "In use" is a yes/no per account, so several can be active at once.

- **Nothing is switched on because it exists.** KCB, Stanbic and Family are in the chart, linked and inactive; their suggestion keeps them inactive. Activating one takes a proposal, a Finance approval and an activation (tested with KCB, including that the accountant's permission is *not* the one that approves it).
- The ledger account must be an active, postable **asset** account that exists in the chart.
- A bank account takes effect the moment it is activated, so it cannot be activated ahead of its approved date.
- When in use an account can both pay and receive; the section says so. Supplier Credit is not listed: it is a liability, never an account money moves through (Report 74 §14).

**Drift is detected, not prevented.** The existing paying-accounts screen can still edit a link directly. If something approved here is later changed there, the item shows *Review required — changed since it was approved* (tested). See §30.

---

## 14. M-Pesa

> **Does WNG use M-Pesa for company transactions?**  ○ Yes  ○ No

Unanswered by default. Then, only if Yes:

> **How is it operated?**
> ○ WNG holds a balance in an M-Pesa wallet or till
> ○ M-Pesa is only a channel that settles to a bank

- *Held balance* → asks for the asset account that holds it. The account **must already exist** in the chart; `MPESA-001` is not created, and proposing a non-existent account is refused with a message saying so (tested).
- *Settlement channel* → asks which bank account it settles to, chosen from the bank accounts that have a ledger account. No bank is assumed — not NCBA, not Equity.
- *No* → nothing further is asked, and any earlier answers are dropped rather than stored (tested).

Accountant approval. On activation the M-Pesa paying account is switched on against that account, or switched off and unlinked for "No". Until then it stays as it is. Nothing was decided.

---

## 15. Card

> **Does WNG operate a company card?**  ○ Yes  ○ No

If Yes: a card name, the bank account it is drawn on, optionally the last four digits and a responsible role (chosen from the existing roles, not typed).

- **The card number is never stored.** A name containing a long digit sequence is refused on screen and on the server; the "last four" field accepts exactly four digits (tested, including that a full number appears nowhere in what is saved).
- No credential, expiry or security-code fields exist.
- If No: every other field disappears and is discarded.

Accountant approval; applied to the Card paying account on activation.

---

## 16. Petty Cash/settings

All 15 existing Finance settings are governed items, plus the float ceiling (which lives on the petty cash account). Each shows the current value, the proposal, the unit, the status, who proposed, who approves, the effective date, the last change and the reason.

| Setting | Section | Unit | Approver |
|---|---|---|---|
| Petty cash limit per payment | Petty cash | KES | Management |
| Petty cash float ceiling | Petty cash | KES | Management |
| Low-balance alert; critical-balance alert | Petty cash | KES | Finance |
| Surrender deadline; "due soon" window | Petty cash | days | Finance |
| PO auto-approval limit; PO senior-approval threshold; voucher senior-approval threshold | Approval rules | KES | Management |
| Projected margin warning; final margin escalation; cost overrun alert | Accounting policies | % | Management |
| Capitalisation threshold | Accounting policies | KES | Accountant |
| Bank-matching date tolerance | Accounting policies | days | Finance |
| Tax return due day; input VAT claim window | Tax | day / months | Accountant |

**No value was invented, set or approved.** A figure already in the system (for example the KES 20,000 petty cash limit) is shown as *"A recommended figure already in the system. Nobody has approved it, and nothing enforces it."* The eight unset settings show *Decision required* with no suggestion.

**Proper numbers.** A value is a number input with its unit, validated on screen and on the server: numeric, not negative, within its range (a day of the month is 1–28, a percentage 0–100), whole where the unit is whole, at most two decimals for money. It is stored as a number, not text (tested: `25000`, not `"25000"`).

**"No limit" is a decision too.** Each setting offers *Set a value* or *No limit (this control stays off)*, so leaving a control off is recorded and approved rather than merely absent.

**It reaches the existing code only when active.** Activation writes an approved, effective-dated `finance_settings` row; the earlier unapproved row is closed, not overwritten. `FinanceSetting::approvedValue()` then returns it, which is what `PettyCashCap` and the approval thresholds have always read (tested end to end).

The float ceiling is recorded against the float, but no workflow enforces it yet; the item says so.

---

## 17. Tax

Tax Configuration lists what the system actually holds: each VAT treatment (rate, recoverable or not, mapped account, effective dates) and each withholding category (rate, mapped payable account, effective dates), with the filing-day and claim-window settings.

- Every row starts **unverified**.
- **Verify** records that an authorised person confirmed the figures *as they stand*. The server takes the snapshot itself: whatever a client sends, what is verified is what the table holds (tested).
- **Return for correction** and **Reject** are available to the approver.
- **Verifying changes no rate.** If a figure is wrong, the reference data is corrected first.
- **If a rate or account changes after verification, the row drops back to "Review required"** (tested).

No Kenyan statutory rule was invented or encoded. The statutory questions Report 74 §20 listed (whether three withholding categories are enough; thresholds; the filing day) remain for the accountant.

---

## 18. Accounting periods

The section shows every period: open / closed / locked, whether posting is allowed, who last changed it, when, and the reason. One small additive change to the existing endpoint supplies the person's name.

**Read-only in Setup.** There is no checkbox and no button there (tested). Closing, locking and reopening stay on the existing period-control screen, where each needs the `finance.periods.manage` permission and a confirmation, and reopening requires a recorded reason (re-tested here: refused without permission, refused without a reason).

`assertOpenPeriod` and the period services are untouched. **No period was closed, opened or created.** The overview reports honestly that periods from earlier years are still open; which to close is for Finance and the accountant.

---

## 19. Document numbering

A register of how each document is numbered **today**, read from the code, without pretending:

| Document | Format | Mechanism | Unbroken | Safe under concurrency |
|---|---|---|---|---|
| Payments | `PAY-<year>-<0001>` | Locked counter (`document_sequences`) | Yes | Yes |
| Client invoices | `INV-<yyyymm>-<000001>` | Built from the record's id | No | Yes |
| Payment vouchers | `SV-<yyyymmdd>-<0000001>` | Built from the record's id | No | Yes |
| Supplier bills, purchase orders, GRNs, purchase requisitions, petty cash requisitions | `BILL-`, `PO-`, `GRN-`, `PR-`, `PCR-` | Last number + 1, no lock | No | **No** |
| Journal entries | `JE-<source>-<id>` | Derived from the document posted | No | Yes |

Only payments use the controlled counter; for it the live next numbers are shown. Each row carries its status: "Numbering policy pending", or for payments "the number it should continue from is not on record". The area is **Data required**.

Nothing was migrated and no sequence was created. This section is deliberately not a form: a numbering policy and WNG's last-used numbers have to exist before there is anything to configure.

---

## 20. Approval rules

Three thresholds today: the PO auto-approval limit, the PO senior-approval threshold and the payment voucher senior-approval threshold (the petty cash limit sits under Petty Cash). Each has an amount in KES, the approving role, an effective date, a status and a version history.

- **No amount was invented.** All three are unset and show *Decision required*.
- **Contradictory rules are refused**: an auto-approval limit must be below the senior-approval threshold, or one order would be both waved through and escalated. Likewise the critical balance must be below the low-balance alert, the due-soon window shorter than the surrender deadline, and the escalation margin not above the warning margin (tested).
- **No rule is active until approved and activated**; until then the ordinary approval applies to everything, as today.

---

## 21. Audit/configuration story

**The story** is on every item, in the brief's words — *What? Why? Who proposed? Who approved? When? Effective? What did it replace? What does it affect? Status?* — built from the record, so nobody reads an audit table. From a test:

> **What?** Hold as Work in Progress until release · **Who proposed?** the preparer · **Who approved?** the accountant · **Effective?** 06-Oct-2026 · **What did it replace?** No previous active version. · **Status?** Active

**The audit trail** records created, edited, submitted, review started, returned, approved, rejected, activated, superseded and withdrawn, each with actor, time, before, after, reason and effective date. It is shown per item, and as a feed under Configuration History, filterable by area. The table is append-only and no endpoint edits or deletes it.

No approved version can be deleted. Drafts are withdrawn, not deleted, and remain in the history.

---

## 22. Environment-variable transition

Inventory of Finance-related environment configuration (there are four; none is a secret):

| Variable | What it is | Belongs in the ERP screen? |
|---|---|---|
| `FINANCE_ACCOUNT_PROFILE` | Which company chart profile file this installation uses | **No.** Installation identity, tied to a file shipped with the code. Stays deployment configuration. What the profile *suggests* is what is now governed. |
| `FINANCE_WIP_POLICY` | The WIP policy | **Was business configuration held in deployment.** Now a bootstrap only (§10). |
| `FINANCE_WIP_POLICY_AUTHORITY` (new) | `transition` or `governed`: whether the bootstrap is honoured | No. A deployment switch for the transition itself. |
| `FINANCE_SEED_REFERENCE_CHART` | Whether the reference chart may be seeded | **No.** A deployment safety switch; exposing it would invite the two-chart state. |

Database credentials, the queue connection and the like are infrastructure and were not considered for the screen.

**Transition path for the WIP policy:**

| Stage | State | Behaviour |
|---|---|---|
| 0 — today | Authority `transition`; nothing approved in the ERP | Exactly Report 74A. With the variable unset: `POLICY REQUIRED`. With it set (the rehearsal): tooling runs, and every screen says the policy is supplied by deployment and not approved. |
| 1 | Finance proposes, the accountant approves, it is activated | The ERP policy governs. The variable must agree or be absent; disagreement is a conflict and nothing posts. |
| 2 | Set `FINANCE_WIP_POLICY_AUTHORITY=governed`; remove `FINANCE_WIP_POLICY` | The ERP is the only authority. A stray variable can no longer supply a policy. |

For `FINANCE_ACCOUNT_PROFILE` the path is different: it stays where it is. Mappings and classifications can be reviewed and approved **before** the profile is switched on (the centre uses the installation's single profile for its suggestions when none is active); approved mappings start being used for posting once the profile is active.

---

## 23. Readiness integration

`GET /api/finance/readiness` now carries a `governance` block of gates, each with `pass`, a state and a message:

| Gate | States |
|---|---|
| Project cost treatment | `ACTIVE` (pass) · `AWAITING APPROVAL` · `APPROVED — NOT ACTIVATED` · `POLICY REQUIRED` · `CONFIGURATION CONFLICT` |
| Account mappings | `APPROVED` (pass) · `ACCOUNTANT APPROVAL REQUIRED` (all resolve, not all approved) · `CONFIGURATION REQUIRED` (some do not resolve) |
| Account classification | `APPROVED` · `ACCOUNTANT APPROVAL REQUIRED` |
| M-Pesa; company card | the item's state |

The mappings message is the distinction the brief asks for, verbatim from a test: *"37 of 37 posting functions technically resolve to a usable account. 0 of 37 are approved by the accountant and active. Resolving is not approval."*

Also changed: the control-centre "Project cost / WIP policy" domain is `READY` only when a policy is approved and active in the ERP (a deployment setting leaves it `POLICY_REQUIRED`), and `finance:readiness` reports where the policy came from.

**Deliberately not changed:** the governance gates are **not** added to the reference `checks` or to the `ready` flag, and `finance:readiness` does not fail on them. That command is the last step of every deploy. A decision waiting for the accountant is not a failed deploy, and making it one would push people to approve things to get a release out.

---

## 24. Backend/API

All under `/api/finance/governance`, authenticated, `finance.config.view` to read:

| Method | Path | Purpose |
|---|---|---|
| GET | `/` | Overview |
| GET | `/items?domain=` | Items for a section (slimmed rows) |
| GET | `/items/{key}` | One item: story, versions, audit, permitted actions, eligible accounts |
| GET | `/items/{key}/accounts?q=` | Compatible accounts only |
| GET | `/numbering` | Document numbering register |
| GET | `/history` | Audit feed |
| POST | `/items/{key}/proposals` | Draft, optionally submit |
| PUT | `/proposals/{id}` | Edit a draft or a returned proposal |
| POST | `/proposals/{id}/{submit\|withdraw\|review\|return\|reject\|approve\|activate}` | One step |
| POST | `/suggestions/submit` | Put several suggestions forward |
| POST | `/proposals/approve` | Approve an explicit selection |
| POST | `/proposals/activate` | Activate an explicit selection of approved proposals |

There is no endpoint that writes a setting directly.

| The brief's "prevent" list | How |
|---|---|
| Two active versions where one is permitted | Unique `active_key`; activation locks the item's versions and ends the previous one |
| Approving stale versions | `revision` on every action → 409 |
| Self-approval where segregation forbids it | Service rule; the button is hidden with the reason |
| Activation without approval | `activate` accepts only status `approved` |
| Overlapping effective versions | A new version must start after every activated one |
| Changing approved history | Only drafts and returned proposals are editable; the audit is append-only |
| Unsafe account assignments | One compatibility rule, used by the picker and the validator |

Every mutating step runs in a transaction with the version row locked. Authority is checked inside the service, per action, not by route middleware, because the permission middleware passes for a Super Admin.

Two things found and fixed while testing. The catalogue cached the chart for the life of the process, which is harmless per request under PHP-FPM but wrong for a long-running one: it is now one request-scoped instance, reset at the start of every action, and the resolver that posting reads re-reads at most every five seconds so a queue worker cannot keep an old policy. And the all-items list was about 690 KB: list rows are now slimmed and each section is fetched when opened.

---

## 25. Frontend

`src/modules/finance/setup/governance/`: one view, twelve components, pure rules in `governance.ts`, the API client and types.

- **Real actions only.** Every button calls the API. The buttons shown are exactly the ones the server says this user may press (`actions` on each item). The frontend's own `can()` is not used for this, because it returns true for a Super Admin.
- **Controls match the question**: radio buttons for exactly-one answers (WIP policy, yes/no, M-Pesa mode, debit/credit); tick boxes only for selecting several items for a bulk step; a restricted search for accounts; selects for a bank account or a role; a number input with its unit for amounts and rates; a date picker for the effective date; free text only for the reason and the card's name.
- **Progressive disclosure**: a field the current answers make irrelevant is not rendered and is not sent.
- **States**: loading, error with retry, and empty, at page, section and item level.
- **No hardcoded readiness and no placeholder counts**: every figure on screen comes from an API response.
- **Plain language**: no environment-variable names, JSON, database ids or command names on the governance screens (tested for the WIP question and the policies section).

---

## 26. Mobile/responsive

- The section tabs scroll horizontally; nothing wraps into a second navigation.
- Mapping and classification are a comparison table from tablet width up and **cards below it**; there is no screen where a wide table is the only presentation.
- An opened item sits beside the list on a wide screen and covers the screen on a narrow one, with a Close button.
- The bulk confirmation is a bottom sheet on a phone.
- Forms are single-column on a phone; choices are full-width tap targets.

Checked at 1440 × 1100 and 390 × 844 for every section (§28).

---

## 27. Tests

**Backend** — all against a disposable test database; the harness refuses any database not named `*_test`.

| Run | Result | Evidence |
|---|---|---|
| Governance, chart completion, control centre, readiness, period control, `ChartAccountMap`, permission registry — **on the final code** | **126 passed, 0 failed** (2,164 assertions) | `75-verification/backend-governance-final.log` |
| Finance / CostCollector / PettyCash / Seeding / Unit regression | **1,012 passed, 39 failed** (9,830 assertions) | `backend-finance-regression.log` |

**The 39 failures, all accounted for, none from this report:**

| Count | Where | Cause |
|---|---|---|
| 34 | `PettyCashAdvancePostingTest`, `PettyCashSurrenderTest`, `PettyCashWorkspaceTest`, `Stab7PettyCashTriplePostingTest`, `Wave3PettyCashControlsTest`, `PettyCashCommitmentTest`, `ExpenditureExceptionTest`, `RequisitionTypeManagementTest` | **Another session's work in progress in the same worktree.** A requisition-verification feature was being built during the run: a new migration at 12:10, petty-cash services, controller and models edited until 12:50, and these very test files rewritten at 12:46, mid-run. 26 of the failures quote that feature's new rule verbatim ("This requisition requires verification of its current details before approval or payment"). None of these classes touches governance code, and nothing in this report changes petty-cash behaviour. |
| 5 | `W7LabourConcurrencyTest` | It refuses to run on any database not named `db_test`; the regression ran on an isolated database (below). |

Because of that concurrent work **there is no clean, like-for-like full-regression figure for this report alone**, and I am not presenting one. What can be said: the 971-passing baseline classes that the other session did not touch all still pass; every suite that exercises code this report changed passes (126 of 126 on the final code); and the regression was not re-run after two last small edits (the bulk-activation endpoint, covered by the 126; and a permission-migration grant), for the same reason. When the other session's work settles, the petty-cash classes should be re-run.

`CostCollectorApiTest`, which failed in the baseline for the order-dependency Report 74A explained, passed here — because the concurrency test that leaks into it did not run on this database, not because anything was fixed.

New backend tests (`tests/Feature/Finance/FinanceGovernanceTest.php`, 29 tests), against the brief's list:

| Required | Covered by |
|---|---|
| Create draft · edit draft · submit · approve · activate · future-effective approval | `a_proposal_moves_from_draft_through_approval_to_active_and_only_then_changes_anything` |
| Return · reject · (withdraw) | `a_proposal_can_be_returned_corrected_resubmitted_rejected_or_withdrawn` |
| Supersede; as-of resolution | `a_new_version_supersedes_the_old_one_on_its_date_and_history_is_never_rewritten` |
| Audit trail; configuration story | `every_step_is_audited_with_who_what_and_why` |
| Permission enforcement | `each_action_needs_its_own_permission`; `administering_the_system_confers_no_accounting_authority` |
| Self-approval restriction | `nobody_approves_their_own_accounting_or_management_proposal` |
| Stale approval protection | `acting_on_a_proposal_someone_else_has_changed_is_refused` |
| Duplicate active-version prevention | `only_one_proposal_is_open_per_item…` (including the database constraint); `a_second_activation_on_the_same_day_is_refused` |
| Effective date rules | `the_effective_date_is_required_and_can_never_be_in_the_past` |
| WIP: no decision / draft / submitted / approved-not-activated → `POLICY REQUIRED` | `with_no_decision_a_draft_or_a_submission_the_policy_is_still_required` |
| WIP approved but future → `POLICY REQUIRED` until effective | the lifecycle test above |
| WIP active → posting permitted | `an_active_policy_permits_project_cost_posting…`; `recognising_cost_immediately_sends_each_wip_function_to_its_cost_of_sales_account` |
| Conflicting env / database WIP → fail closed | `a_deployment_setting_that_contradicts_the_approved_policy_fails_closed`; `the_deployment_setting_is_a_bootstrap_that_can_be_retired` |
| Nothing auto-approved | `existing_recommendations_are_suggestions_awaiting_review_never_approvals` |
| Mapping approval; bulk approval and activation | `suggested_mappings_are_put_forward_together_and_approved_individually_or_as_an_explicit_selection` |
| Account mapping compatibility | `a_changed_assignment_must_be_a_compatible_account_and_feeds_the_existing_map_once_active` |
| Classification approval | `classifications_are_reviewed_by_confidence_and_approval_writes_nothing_to_the_chart` |
| M-Pesa conditional configuration | `mpesa_asks_only_what_the_answer_makes_relevant_and_never_invents_an_account` |
| Card conditional configuration | `the_company_card_stores_a_name_and_at_most_four_digits` |
| Banks | `a_bank_account_is_switched_on_only_by_an_approved_decision_and_not_ahead_of_its_date` |
| Numerical setting validation | `a_threshold_is_a_validated_number_and_reaches_the_existing_setting_only_when_active`; `two_approval_rules_cannot_contradict_each_other` |
| Tax | `tax_reference_data_stays_unverified_until_the_accountant_verifies_what_is_actually_there` |
| Period controls | `reopening_a_closed_period_still_needs_permission_and_a_reason` |
| Document numbering | `document_numbering_is_reported_as_it_is` |
| Readiness integration | asserted inside the WIP and "existing recommendations" tests |
| Target-identity safeguards unchanged | `nothing_approved_here_overrides_the_two_chart_stop`, plus Report 74A's three identity tests, still passing |

**Frontend** — `75-verification/frontend-governance-tests.log`, `frontend-tests.log`.

| Run | Result |
|---|---|
| New: `src/modules/finance/setup/governance/governance.spec.ts` | **42 passed** |
| All of `src/modules/finance/setup` | 48 passed (the two existing specs for the former page still pass) |
| Whole frontend suite | 505 passed, 5 failed — the same five as the baseline, all in `tests/unit/design/designTables.spec.ts`. (459 baseline + 42 new here + 4 in a spec the other session added.) |
| Type-check (`vue-tsc --build`) | 273 errors before, 273 after; **0 new**; 0 under `src/modules/finance/setup` (`typecheck.log`) |
| Production build (`vite build`) | **passes** (`production-build.log`) |
| Static API contract (`check-api-contract.py`, re-run from Report 73) | 203 static endpoints called by the Finance frontend, 22 new since HEAD, **0 unmatched** against the registered routes; all 11 statically visible governance calls resolve (`api-contract.log`) |

The 42 frontend tests cover: overview statuses and the area count; that an unknown state is never shown as ready; no pre-selected WIP option or yes/no answer; progressive disclosure for M-Pesa and the card; that hidden fields are not sent; number, date and card-name validation; permissions (a viewer sees no action; each role sees only its own; the self-approval explanation); submit, approve, return and the conflict reload; server refusals shown against the right field; the bulk confirm lists for submit, approve and activate; the judgement mapping's disabled tick box; loading, error and empty states; one navigation strip; the narrow-screen panel; plain language; and that every input has a label and every error is tied to its field.

**Incidents during testing**, for the record:

1. *Shared test database.* Midway through a regression run, existing petty-cash and receivables tests began failing with "Table 'users' already exists". The cause was another `php artisan test` process, started from the DDEV web container by a different session, rebuilding the same `db_test` at the same time. It was not a defect in either codebase. My runs were moved to the isolated `db_scratch_test` (a temporary `phpunit.r75.xml`, removed afterwards) and repeated in full.
2. *Workstation reboot.* The machine restarted during the work. The temporary directory was cleared, taking the runner scripts and an unsaved draft of this report; nothing in either repository was lost. The database came back with the same row counts as before (`db`: 208 chart accounts, 4 journal entries, 0 queued jobs). This stream restarted only the database container, so **no queue worker was started by this stream**. The DDEV web container (which runs a queue daemon) was found running again shortly afterwards, started from outside this stream; it was left alone. The local queue was empty before and after. The first run after the reboot failed wholesale while the database was still recovering and was discarded; the results above are from the run after that.
3. *A second session was changing petty-cash code and tests during the regression*, as described above. Its files were not touched by this stream; where it and this stream edited the same three shared files (`Permissions.php`, `RolePermissions.php`, `routes/api.php`), both sets of changes are present and the files parse.
4. *One test needs the shared database by name.* `W7LabourConcurrencyTest` refuses to run on any database not called `db_test`, so on the isolated database its five tests report failure for that reason alone. That test passed in Report 74A's run on `db_test` and nothing here touches labour.

**Not run:** Projects, Stores, Procurement, HR and other non-Finance backend suites; the real-data rehearsal smoke suite (it commits data and needs the rehearsal databases rebuilt); browser end-to-end tests.

---

## 28. Visual verification

The real Setup & Controls view was rendered with **responses captured from the real API** on a test database in three situations — nothing decided; work in progress across every state; the same as a Super Admin — and screenshotted with headless Chrome at 1440 × 1100 and 390 × 844. Fixture page: `ERP-Frontend/tests/fixtures/75/`. Images: `75-verification/desktop-*.png`, `mobile-*.png` (38 images, re-taken after the final wording changes).

| Screen | Desktop | Mobile |
|---|---|---|
| Overview (in progress; and nothing decided) | ✓ | ✓ |
| WIP policy, item open | ✓ | ✓ |
| Mapping review; a judgement mapping open | ✓ | ✓ |
| Classification review; an undecided account open | ✓ | ✓ |
| Banks & payment methods; M-Pesa open | ✓ | ✓ |
| Petty cash settings; one setting open | ✓ | ✓ |
| Tax | ✓ | ✓ |
| Periods | ✓ | ✓ |
| Document numbering | ✓ | ✓ |
| Approval rules | ✓ | ✓ |
| Configuration history | ✓ | ✓ |
| Super Admin on the WIP proposal (no approve, no activate) | ✓ | ✓ |
| Error state; empty state | ✓ | ✓ |

Checked on the images: no horizontal overflow of the page; no clipped controls; tables readable on desktop and replaced by cards on mobile; one navigation strip; status colours follow the state (green only for active/verified; a zero count is neutral, not red); no technical labels on the governance screens.

**Found by looking, and fixed:** a "Blocked · 0" chip was drawn in red; area links lower-cased "M-Pesa"; a single-item area repeated its status as its headline; the action buttons sat below the story, off the first screen, and were moved above it; and the suggestion basis read "Chart profile 'wng'", which is our vocabulary, not Finance's ("Proposed chart setup for WNG").

**Limits of this verification.** It is a fixture page: the data is real API output but the page is not the logged-in application, and mutations are refused there by design. The surrounding Finance header and section rail render from the real components. It was not checked in dark mode, in another browser, or by a person on a phone. The floating round badge at the bottom of each image is the Vue development tool, not part of the product.

---

## 29. Files changed

`git diff --check`: clean in both repositories

**Backend — new**

| File | Purpose |
|---|---|
| `app/Modules/Finance/Governance/GovernanceCatalogue.php` | What can be decided, validation, plain-language descriptions, account compatibility |
| `…/GovernanceService.php` | The lifecycle |
| `…/GovernanceApplier.php` | Carries an activated decision into `finance_settings` / `payment_sources` |
| `…/GovernanceRuntime.php` | What is in force on a date; WIP precedence; account overrides |
| `…/GovernanceCentre.php` | Overview, item views, stories, readiness gates, history |
| `…/DocumentNumberingRegister.php` | The numbering register |
| `…/FinanceConfigVersion.php`, `FinanceConfigApproval.php` | Models |
| `app/Modules/Finance/Controllers/FinanceGovernanceController.php` | The API |
| `app/Modules/Finance/Support/ChartIdentity.php` | Chart identity, extracted from the 74A command |
| `database/migrations/2026_10_06_000001_create_finance_governance_tables.php` | Three tables |
| `database/migrations/2026_10_06_000002_add_finance_governance_permissions.php` | Seven permissions; authority granted to nobody |
| `tests/Feature/Finance/FinanceGovernanceTest.php` | 29 tests |

**Backend — changed**

| File | Change |
|---|---|
| `app/Constants/Permissions.php`, `RolePermissions.php` | New permissions; `accountingAuthority()`; Super Admin excludes it |
| `app/Modules/Finance/Support/ChartAccountMap.php` | Asks the governance runtime first |
| `app/Modules/Finance/Support/FinanceChartProfile.php` | `wipPolicyBlock()` and `runtimeProblems()` read the resolved policy |
| `app/Modules/Finance/Support/FinanceReadiness.php`, `Controllers/FinanceReadinessController.php`, `Services/FinanceControlCentreService.php` | Governance state in readiness; links repointed |
| `app/Modules/Finance/Console/CompleteChartCommand.php` | Uses `ChartIdentity` and the resolved policy; warns when an approved mapping differs from the profile |
| `app/Modules/Finance/Controllers/AccountingPeriodController.php` | Adds who last changed a period (additive) |
| `InventoryFinanceController`, `FinanceOverviewController`, `FinancialReconciliationService` | Read the resolved policy instead of the raw setting |
| `app/Modules/Finance/Services/WorkInProgressReleaseService.php` | `RELEASE_MAP` made public (read by the runtime) |
| `app/Modules/Finance/Database/Seeders/ChartOfAccountSeeder.php` | `referenceAccount()` accessor; seeding unchanged |
| `app/Modules/Finance/Providers/FinanceServiceProvider.php` | Catalogue bound per request |
| `config/finance_accounts.php` | `wip_policy_authority` |
| `routes/api.php` | The governance routes |
| `tests/Feature/Finance/WngChartCompletionTest.php` | Unchanged in this report |

**Frontend — new:** `src/modules/finance/setup/governance/` (`views/FinanceSetupCentreView.vue`; components `OverviewSection`, `ItemsSection`, `BanksSection`, `PeriodsSection`, `NumberingSection`, `HistorySection`, `GovernanceItemPanel`, `GovernanceItemList`, `ProposalFields`, `AccountPicker`, `BulkConfirmDialog`, `GovernanceStatus`; `governance.ts`, `useGovernance.ts`, `types.ts`, `governance.spec.ts`); `tests/fixtures/75/` (fixture page and captured API responses).

**Frontend — changed:** `src/router/finance.ts` (Setup opens the centre; former page at `setup/system-checks`), `src/modules/finance/navigation.ts` (two Setup entries), `src/modules/finance/setup/views/FinanceReadinessView.vue` (title and back link), `src/modules/finance/overview/useFinanceOverview.ts` (two links repointed).

**Evidence:** `docs/finance-redesign/phase-2/75-verification/`.

Two migrations exist. They create empty tables and permissions and grant view/propose/review to existing roles; they change no Finance data. **Nothing was committed or pushed**, so nothing has been deployed; note that a push to `master` deploys and runs migrations unattended.

A temporary export test and a temporary PHPUnit configuration used to capture the fixtures and to isolate the test database were deleted before this report was finalised.

---

## 30. Remaining software gaps

None of these blocks using the centre. Each is a known limit, most of them the price of not making a breaking switch.

| # | Gap | Why it was left | Suggested next step |
|---|---|---|---|
| 1 | **An unapproved account mapping is still used for posting** (the profile's account). Readiness says approval is required; posting does not wait for it. | Requiring approval would stop posting on the rehearsal and any active profile: a breaking switch. | Decide with the cutover whether approved mappings become a precondition, and switch it on then. |
| 2 | **The existing paying-accounts screen can still change a bank link or switch an account on directly**, bypassing the proposal. The centre detects it ("Review required") but does not prevent it. | Removing a working screen is a redesign decision. | Make that screen read-only, or route its save through a proposal, once Finance is using the centre. |
| 3 | **Approved classifications are not what `--classify-existing` applies.** It still reads the profile's list. | Report 75 was told not to run or alter cutover execution. | Have the command apply only approved classifications, or refuse where approval is missing. |
| 4 | **The cutover tool plans from the profile**, not from approved mappings. It warns on a difference. | Same. | Update the profile from approved decisions, or have the tool read them. |
| 5 | Items written into live records (banks, M-Pesa, card, float ceiling) **must be activated on or after their date by a person**; nothing activates them automatically. | No scheduler or worker was to be introduced. | Acceptable as is; or a daily command later. |
| 6 | **The float ceiling is recorded but not enforced** by any workflow. | No enforcement existed to connect it to. | Enforce on top-up if Finance wants it. |
| 7 | **One approver per item**, fixed in the catalogue. No two-step approval (for example accountant *and* management). | Not asked for; adds states. | Add if WNG's policy needs it. |
| 8 | **No notification** to an approver that something awaits them, and governance work is not in the Finance work queue. | Out of scope; needs the notification rules agreed. | Add queue entries and notifications. |
| 9 | **Tax rows can be verified, not edited** here. A wrong rate still needs a data correction. | The brief asked for verification, and no statutory rule was to be invented. | A governed rate change later. |
| 10 | **Document numbering is a register, not a control.** | Policy and continuation numbers do not exist yet. | Build once they do. |
| 11 | Historical accounting does not ask "as of the transaction date". | Not to be retrofitted in this report. | Use `GovernanceRuntime::value($key, $date)` where a re-run needs it. |
| 12 | `W7LabourConcurrencyTest` leaks catalogue state into later tests (Report 74A §14) and only runs on `db_test`. | Belongs to another stream's tests. | Fix its tear-down. |

---

## 31. Remaining human decisions

In the order that unblocks the most.

1. **Who holds approval authority.** Assign `finance.config.approve_accounting`, `approve_management`, `approve_operational` and `activate` to roles (Admin → Roles). Until this is done nothing can be approved. Consider whether the approver and the activator should be different people.
2. **Whether the default approver for each kind of item (§5) is right** for WNG.
3. **WIP policy** — hold as work in progress, or recognise immediately.
4. **The 37 account mappings**, individually for the six flagged.
5. **The 116 classifications**, individually for the 13 flagged; and the four left open (OPE-026, ITX-001, LDO-001, EQE-001).
6. **M-Pesa**: used or not; held balance or settlement channel; which account.
7. **Company card**: exists or not; which bank.
8. **Which bank accounts are in use** (KCB, Stanbic, Family; and CASH-001, SBM-001, NIC-001, FK-001, which no paying account uses).
9. **The 15 settings and the float ceiling**: a value, or an explicit "no limit", for each.
10. **Tax verification** of the eight reference rows, the filing day and the claim window.
11. **Which earlier accounting periods to close.**
12. **Document numbering policy.**
13. Carried from 74A and unchanged: opening balances, retained earnings and history authority.

None of these was decided, approved or pre-filled in this report.

---

## 32. Remaining data gates

1. **Target chart identity** — still unknown for production. The read-only inspection of Report 74A §9 must be run on the cutover database. The Setup overview shows the same check for whichever database the application is connected to, which makes it visible to Finance, but seeing it locally proves nothing about production.
2. **WNG's last-used document numbers**, per document type.
3. **Opening balances, retained earnings and historical financial data** — out of scope by instruction, untouched.

---

## 33. Cutover implications

**Nothing in this report performs, prepares or weakens the cutover.**

- The Report 74A safeguards are unchanged and re-tested: `WNG_CHART_ONLY` may proceed to attended-cutover preparation after the other gates; `TWO_CHART_STATE` and `OTHER / MANUAL_REVIEW_REQUIRED` stop. **Configuration readiness never overrides this**: a test makes every governed decision and then introduces a second chart; the overview turns to "Two charts detected", and `finance:complete-chart --execute` still refuses with `ACCOUNTANT REVIEW REQUIRED — TWO CHARTS DETECTED`.
- `--cutover` still requires a policy in force. It now accepts one approved and active in the ERP as well as the deployment bootstrap, and refuses on a conflict between them.
- The rehearsal pipeline is unaffected: it sets the policy by environment, which the `transition` authority honours. It has not been re-run since Report 74A's resolver change and should be before cutover.
- **The governance tables must exist on the target before Finance can use the centre.** They arrive by migration; on a target built by the source-to-target migration they are created with the rest of the schema and are empty.
- **Approvals made before cutover carry over only if they are made on the database that becomes the target.** Decisions approved on a local or rehearsal database are not decisions on production.
- **Sequence the human decisions before the technical step** (extending Report 74A §21): authority assigned → WIP policy approved and activated → mappings approved → profile updated to match if any assignment changed → classification approved → then the attended cutover.
- Gaps 1, 3 and 4 in §30 are where approved decisions and the cutover tooling do not yet meet. They should be closed, or consciously accepted, as part of cutover preparation.

---

## 34. Verdict

**FINANCE CONFIGURATION & GOVERNANCE CENTRE COMPLETE — HUMAN APPROVALS AND TARGET DATA GATES REMAIN**

The centre exists and works end to end: every Finance decision in scope is proposed, reviewed, approved, dated, activated and audited through the ERP in business language; proposed, approved and active are distinct; accounting authority is never implied by technical access; the WIP policy is governed with the fail-closed behaviour intact; existing recommendations are suggestions awaiting review and nothing was approved; readiness separates what resolves from what is approved; and the target-identity safeguards are untouched.

"Complete" is said with §30 in view. Those twelve gaps are real, they are listed, and none prevents Finance from using the centre; three of them (an unapproved mapping still posts; the old paying-accounts screen; approved classifications versus the cutover tool) are where a breaking switch was deliberately not made and need a decision at cutover.

This is not a statement that any policy is approved, that the chart is ready, or that a cutover may proceed.

**Stopped here.** No chart completion, no cutover, no production classification or seeding, no opening balances, no retained earnings, no history repair, no WIP choice, no approval on WNG's behalf, no M-Pesa or card activation, no deployment, no W8.
