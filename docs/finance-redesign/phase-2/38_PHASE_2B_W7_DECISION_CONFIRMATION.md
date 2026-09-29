# WNG ERP Finance — Phase 2B
# Workflow 7 — Labour Cost
# Decision Confirmation Report

**Report Number:** 38
**Document:** `38_PHASE_2B_W7_DECISION_CONFIRMATION.md`
**Date:** 2026-09-24
**Branch:** `finance/critical-stabilization-fixes`
**Prepared by:** Independent closure / architecture reconciliation exercise
**Governance references:**
- `03_WNG_FINANCE_DECISION_REGISTER.md` (authoritative — updated in place)
- `37_PHASE_2B_W7_LABOUR_DECISION_ARCHITECTURE.md` (architecture source)
- W6 Closure Gate: `36_PHASE_2B_W6_INDEPENDENT_CLOSURE_GATE.md`

---

## 1. Executive Summary

This report records the outcome of the Workflow 7 Labour Cost **Decision Confirmation & Governance
Reconciliation** exercise conducted on 2026-09-24.

The exercise was triggered because the preliminary W7 architecture briefs (Reports 24 and 25) were
built on assumptions about WNG's Technical/Casual Labour module that WNG has since discarded. Those
assumptions — that labour was tracked via Job Cards, TechnicalLabour records, and a crew-day-first
deployment model — are not operational reality.

WNG confirmed its actual labour-costing operating model. That confirmation has been incorporated into
the authoritative Decision Register (`03_WNG_FINANCE_DECISION_REGISTER.md`, W7 section, lines 175–242)
and is reproduced and explained in this report.

**Key outcome:** Ten W7 decisions (W7-1 through W7-10) are CONFIRMED BY WNG. Thirteen compatible
earlier rules (W7-11 through W7-23) are reconfirmed. Three open accountant decisions (W7-24 through
W7-26) remain AWAITING CONFIRMATION but do NOT block the analytical project labour costing
implementation.

**Final verdict is issued at Section 23.**

---

## 2. WNG Clarifications Confirmed

The following business facts were confirmed by WNG on 2026-09-24 and govern all W7 architecture
decisions:

### Clarification 1 — Project Budget is the Labour Planning Source

> *"Project labour is normally planned during Project Budget creation."*

At WNG, labour planning occurs during Project Budget creation
(`task_budget_data.labour_data` JSON column). Each budget labour line captures:

| Field | Description |
|---|---|
| `id` | UUID — the stable reference for `source_ref` in CostLines |
| `type` | Team/role label (from `COMMON_TEAM_TYPES` — e.g. Pasting Team, Welders) |
| `category` | Work phase (Production, Set Up, Set Down, Technical, Supervision, Other) |
| `unit` | Unit of measure (PAX, days, hours, shift) |
| `quantity` | Headcount or unit count |
| `days` | Duration in days |
| `unitRate` | Approved standard rate (KES / unit-day) |
| `amount` | `quantity × days × unitRate` |
| `isIncluded` | Whether this line is active in the approved budget |

This data is already projected into `cost_lines` as `nature = 'planned'`, `status = 'verified'`,
`budget_category = 'labour'`, `source_type = 'BudgetLine'`, `source_ref = row['id']`
via `BudgetProjector::flatLines()` → `CostCollectorService::postPlanned()`.

**No new labour-budgeting workflow will be created.**

### Clarification 2 — Employee Records are the Sole Personnel Master

> *"Employee Records are WNG's authoritative employee/worker master."*

W7 does not create or maintain a competing casual/technical register.
All worker identity, role, and department data uses the existing `employees` table.
Casual/contract personnel where maintained in Employee Records are covered by the same path.

### Clarification 3 — HR Technical/Casual Labour is Discarded

> *"HR Technical/Casual Labour functionality is discarded / not in operational use."*

`TechnicalLabour`, `JobCard` (as a worker-attribution device), and the crew-day-first model are
not operational. The development database contains:

- `TechnicalLabour::count() = 0`
- `Employee::count() = 0`
- `JobCard::count() = 0`

The code exists across HR, Production, and Teams modules but is dormant. It must NOT become the
foundation of W7.

### Clarification 4 — The Primary W7 Workflow

> *"The primary W7 workflow is:*
> *Project Budget Labour Line → Actual Usage Confirmation → Verification → Actual Labour CostLine*
> *→ W6 Project Costing."*

This is Option A for both W7-1 (attribution source) and W7-2 (cost rate).

### Clarification 5 — Crew-Day-First is No Longer the Default Architecture

> *"Crew-day-first assumption is superseded as the default architecture."*

Crew/team deployment is reframed as an **optional supplementary evidence mechanism** — it may
optionally provide supporting operational data behind a labour confirmation, but is not the
primary costing engine.

### Clarification 6 — Implementation Boundary

> *"Do NOT implement W7 in this task. Do NOT start W8."*

This report and the Decision Register update are governance artefacts only.
No migration, controller, service, Vue component, or permission is written in this task.

---

## 3. Historical W7 Decisions Superseded

The following preliminary decisions from Reports 24 and 25 are superseded. They are retained in
the Decision Register for audit traceability but must not be used as architecture input.

### W7-1A (Hist.) — Technical/Casual Labour via Job Cards

**Original direction (2026-09-23):** Build on the existing Job Card process
(`JobCard + TechnicalLabour → Labour CostLine`) for casual/technical worker attribution.

**Status:** SUPERSEDED BY WNG BUSINESS CLARIFICATION — 2026-09-24.

- Technical/Casual Labour is confirmed discarded and not operational.
- Job Cards, as a worker-attribution device, are not the confirmed architecture.
- Superseded by **W7-1** (Project Budget line confirmation) and **W7-10** (decommissioning).

### W7-1B (Hist.) — Crew-Day-First Attribution

**Original direction (2026-09-23):** Primary target is controlled crew-day/team allocation —
`Crew/Team → Work Date → Project → CostLine` as the primary labour costing engine.

**Status:** SUPERSEDED / REFRAMED — 2026-09-24.

- Project Budget labour lines are the confirmed primary attribution source (W7-1).
- Crew/team deployment is reframed as optional operational evidence, not the primary engine.

---

## 4. Decisions Preserved

The following decisions from earlier sessions are compatible with the confirmed operating model
and are reconfirmed without change:

| ID | Rule | Reconfirmed |
|---|---|---|
| W7-11 | Payroll Confidentiality — no salary/payslip exposure via project CostLines | 2026-09-24 |
| W7-12 | One Economic Cost Once — `postsIndependently = false`; no duplicate GL debit | 2026-09-24 |
| W7-13 | Labour Corrections via W6-4 Cost Transfer architecture (`CL-TRF-OUT` / `CL-TRF-IN`) | 2026-09-24 |
| W7-14 | W6 Closure Guard strictly enforced on labour CostLines | 2026-09-24 |
| W7-15 | Project-Specific Overtime attributable; non-project OT remains overhead | 2026-09-24 |
| W7-16 | Project-Specific Travel Time attributable; commuting excluded | 2026-09-24 |
| W7-17 | Work-Phase Classification tag (Production, Set Up, Set Down, Live Support) optional | 2026-09-24 |
| W7-18 | Idle / Unattributable Labour must NOT be spread across active client projects | 2026-09-24 |
| W7-19 | Client-Caused Rework attributable; tagged for commercial recovery tracking | 2026-09-24 |
| W7-20 | Internal-Error Rework traceable to the project; classified separately | 2026-09-24 |
| W7-21 | General Training is overhead; billable/contracted training may be attributed | 2026-09-24 |
| W7-22 | Management / Supervisory Time is overhead by default; specific chargeable exceptions | 2026-09-24 |
| W7-23 | Office Project-Support Roles: capability confirmed; specific roles AWAITING WNG | 2026-09-24 |

---

## 5. W7-1 Confirmation — Labour Attribution Source: Option A (Project Budget Lines)

**Decision:** How should WNG confirm actual labour used against the Project Budget?

**Confirmed:** OPTION A — Actual usage confirmation directly against the approved Project Budget
labour lines.

### What this means

- The recorder opens the project labour attribution screen.
- The approved Project Budget labour lines are displayed (role, category, unit, rate).
- The recorder enters **actual quantity** (actual days, actual PAX/headcount, actual hours or
  shifts actually consumed).
- The system computes `Actual Labour Cost = Actual Usage × Approved Budget Standard Rate` (W7-2).
- No re-entry of roles, categories, or rates — these come from the approved budget.

### What this does NOT mean

- It does not require a separate standalone timesheet system.
- It does not require the TechnicalLabour or JobCard module.
- It does not require crew-day records as the primary driver.
- It does not replace the existing Project Budget labour tab for planning.

### Repository foundation

```
task_budget_data.labour_data (JSON)  →  BudgetProjector::flatLines()
    ↓  (nature = 'planned', status = 'verified')
cost_lines (Planned Labour CostLine)
    ↓  (W7: actual confirmation workflow)
cost_lines (Actual Labour CostLine, nature = 'actual', status = workflow stage)
```

The `source_ref` on each planned CostLine already equals the `labour_data` row UUID, providing
a stable FK to consume against.

---

## 6. W7-2 Confirmation — Labour Cost Rate: Option A (Budget Standard Rate)

**Decision:** What monetary rate determines Actual Project Labour Cost?

**Confirmed:** OPTION A — Approved Project Budget Standard Rate.

    Actual Project Labour Cost = Actual Confirmed Usage × Approved Project Budget Standard Rate

### What this means

- The rate is the `unitRate` from the approved Project Budget labour line.
- Finance approves Project Budgets — Finance therefore approves the standard labour rates
  embedded in them. No separate rate-approval step is required.
- This is the standard project-management costing model: standard cost, measured usage.
- The resulting CostLine is **analytical only**: `postsIndependently = false` → no GL debit.

### Privacy guarantee

- Employee `salary` figures are never accessed by the W7 actual cost calculation.
- The Project Officer, Site Captain, and Finance verifier see only the standard rate and the
  resulting cost — not any individual employee payroll data.

### Accounting implication (open — W7-25)

The accounting variance between:

- **Standard project labour pool:** Sum(Actual Usage × Budget Rate) across all projects
- **Actual company payroll expense:** GL 5200 total for the period

...is a management-reporting question requiring Finance/Accountant confirmation (W7-25). It does NOT
block building the analytical layer.

---

## 7. W7-3 Confirmation — Attribution Granularity (Role/Category Default)

**Decision:** What level of worker attribution is required on actual project labour?

**Confirmed:** Default is **Role / Labour Category / Budget Line** level. Individual Employee
tagging from Employee Records is optional.

### What this means

- The default confirmation captures: which budget line was consumed, actual quantity, and the
  operational recorder's identity.
- Optional: the recorder may tag specific Employee Records (from `employees`) where operational
  accountability or individual tracking is needed.
- This does not recreate the TechnicalLabour register.
- Employee tagging, where used, references `employees.id` only — no new personnel master.

### Implementation note

The `details` JSON payload on the resulting CostLine will carry:

```json
{
  "budget_line_id":  "<labour_data row UUID>",
  "budget_category": "labour",
  "labour_category": "Production | Set Up | ...",
  "labour_type":     "Pasting Team | Carpenters | ...",
  "actual_quantity": 3,
  "actual_days":     2,
  "actual_unit":     "PAX",
  "employee_ids":    [1, 5]
}
```

---

## 8. W7-4 Confirmation — Two-Stage Operational Control (Recorder + PO Verification)

**Decision:** Who records and verifies actual labour usage on a project?

**Confirmed:** Two-stage operational control:

| Stage | Actor | Action |
|---|---|---|
| 1. Record | Site Captain / Production Lead / authorised operational lead | Records actual labour usage against the approved Project Budget labour line |
| 2. Verify | Responsible Project Officer | Verifies / approves the actual project labour attribution before it becomes approved operational consumption |

### What this means

- The recording step is an operational action — the person on the ground who observed the work.
- The PO verification step is a project-management check — the Project Officer accountable for
  project delivery confirms the attribution is correct.
- Finance verification (W7-5) follows as a separate step.
- No un-verified actual labour attribution becomes an authoritative CostLine.

### Approval-state machine (indicative)

```
draft  →  recorded  →  po_verified  →  finance_verified  (→  reversed if correction required)
```

The actual CostLine is only written with `status = 'verified'` and `nature = 'actual'` after
Finance verification (W7-5).

---

## 9. W7-5 Confirmation — Finance Verification Required

**Decision:** Does Finance need to verify the monetary labour cost before it becomes final?

**Confirmed:** YES — Finance/Accounts verification is required after operational confirmation and
PO verification.

### What this means

- Finance verifies that the monetary computation (`usage × rate`) and the budget attribution are
  correct before the CostLine is stamped `status = 'verified'`.
- This preserves the separation between:
  - **Operational evidence** (recorder + PO verify the facts of actual labour),
  - **Financial recognition** (Finance verifies the cost entry is correct and
    attributable to the right budget line).
- Consistent with the two-verification model already used throughout W6 (PO verification +
  Finance verification for other cost categories).

### Repository precedent

`CostCollectorService::collect()` already supports a `status` field on CostLines. The same
`finance.costs.verify` permission that governs other CostLine verification is expected to govern
W7 Finance verification. Detailed permission mapping is a W7 implementation decision.

---

## 10. W7-6 Confirmation — Unbudgeted Labour: Record, Do Not Hide

**Decision:** How should genuine actual labour not in the approved Project Budget be handled?

**Confirmed:** Record as **Unbudgeted Labour**; never hide the variance.

### What this means

- If actual labour is genuinely not in the approved budget, the recorder classifies it as
  Unbudgeted Labour and provides a required justification reason.
- It still goes through PO verification (W7-4) and Finance verification (W7-5).
- It is posted to the Cost Collector with `consumes_line_id = null` (no planned CostLine
  to consume against — there isn't one).
- The CostLine records `budget_category = 'labour'`, `is_unbudgeted = true`, and the
  justification in `details`.
- The approved Project Budget is never silently modified to absorb the unplanned labour.

### Why this matters

Silent budget modification would obscure the true variance and undermine the Budget vs Actual
control (Section 17). Unbudgeted Labour must surface as an over-budget cost so Finance and
Management can see the true project labour position.

---

## 11. W7-7 Confirmation — Over-Budget Labour: Record Actual + Alert/Escalate

**Decision:** What action occurs when actual labour exceeds the approved budget?

**Confirmed:** Record the full actual; show the overrun; alert/escalate; do NOT hard stop.

### What this means

- Actual labour exceeding the approved budget line is recorded in full.
- The CostAccount service shows: Budget, Actual, Variance, Over Budget flag.
- The existing configurable cost-overrun alert mechanism (`cost_overrun_alert_percent` on
  `ProjectEnquiry`) triggers notification/escalation.
- Over-budget labour is not an automatic hard stop that blocks recording.

### Rationale

A hard stop would prevent Finance from knowing the true project labour cost. The project would
appear profitable on paper while actual costs went unrecorded. WNG's confirmed position is that
true margin visibility is more important than a hard stop. The alert mechanism ensures management
knows immediately.

### Repository hook

`CostAccountService` already carries `cost_overrun_alert_percent` logic for materials/procurement.
The same infrastructure is reused for labour overruns (exact implementation is a W7 implementation
decision).

---

## 12. W7-8 Confirmation — Unused Budgeted Labour: Favourable Variance

**Decision:** How should unused budgeted labour be treated?

**Confirmed:** Unused budgeted labour must NOT become Actual Labour. It is a favourable variance.

### What this means

- If 3 PAX were budgeted but only 2 confirmed, the 1 PAX difference is a favourable labour
  variance — not converted into cost.
- At project Financial Closure (W6-5), the remaining planned labour allowance is closed/released
  per the standard commitment closure architecture already in `ProjectFinancialClosureService`.
- No manufactured cost entries, no rounding errors, no "burn the budget" behaviour.

### Repository foundation

`ProjectFinancialClosureService::closeProject()` already processes planned CostLines at closure.
Labour planned lines are within scope of this existing service.

---

## 13. W7-9 Confirmation — Forward-Only from Implementation Date

**Decision:** What historical labour backfill policy applies?

**Confirmed:** FORWARD ONLY. W7 Actual Labour Costing begins from the controlled W7
implementation date.

### What this means

- No manufactured historical Actual Labour CostLines are created from old Project Budgets.
- Projects active before the W7 implementation date will show planned labour CostLines but no
  historical actual labour CostLines — this is an honest representation.
- The implementation date will be recorded in a governance configuration record (to be confirmed
  at implementation time).

### Rationale

Manufactured historical records would create false precision about costs that were never
actually confirmed or verified. WNG's project-cost statement for a pre-W7 project will clearly
show: "Labour — Planned: KES X; Actual: (W7 not yet implemented for this period)".

---

## 14. W7-10 Confirmation — Decommission Active Technical Labour Workflow

**Decision:** How should the obsolete HR Technical/Casual Labour functionality be decommissioned?

**Confirmed:** Decommission the active workflow; preserve historical schema.

### Active components to decommission (at W7 implementation time)

| Component | Location | Action |
|---|---|---|
| `TechnicalLabourPanel.vue` | Frontend HR module | Remove / disable |
| `useTechnicalLabour.ts` | Frontend composable | Remove / disable |
| HR module API routes for TechnicalLabour | `HR/Routes/api.php` | Remove / disable |
| `TechnicalLabourController.php` | HR module | Retire from routing |
| `JobCard` worker resolution via TechnicalLabour | `JobCard.php` lines 58, 82 | Remove TL path; consolidate on Employee |
| `JobCardController.php` technician dropdown | Line 657: `TechnicalLabour::active()` | Replace with `Employee` or remove |
| `WorkOrderTaskController.php` `technical_labour` type validation | Lines 49, 102 | Remove |
| `ProductionAssigneeController.php` union on TechnicalLabour | Production module | Remove from union |
| `TeamsTask.vue`, `JobCardForm.vue`, `WorkOrderDetailsView.vue`, `ReportTask.vue` | Frontend | Remove TL references |

### Historical components to PRESERVE (no destructive schema changes)

| Component | Reason |
|---|---|
| `technical_labours` table | Historical audit data, even if empty in development |
| `TeamsMember.technical_labour_id` FK | Data integrity; nullable |
| `OTEntry.technical_labour_id`, `LedgerEntry.technical_labour_id`, `Compensation.technical_labour_id` | Nullable FKs; preserve for audit trail |
| `TechnicalLabour.php` model | Keep as dormant model class; remove from active service injection |

> **Decommissioning ≠ hard deletion.** No `DROP TABLE`, no destructive migration, no FK removal.
> The historical tables become read-only archive records.

### Consolidation

Active worker-identity references migrate to `employees.id`. Where a `JobCard` or `WorkOrder`
assignment previously accepted a `TechnicalLabour` ID, it will require an `Employee` ID.

---

## 15. Payroll Privacy Control

**Rule W7-11 — CONFIRMED (2026-09-24)**

Project users must not gain access to employee salary, payslips, deductions, bank details, or
statutory contributions through any W7 feature.

### Enforcement points

| What is visible to project users | What is NEVER exposed |
|---|---|
| Budget standard rate (from approved Project Budget) | `employees.salary` |
| Actual Cost = Usage × Budget Rate | Payslip amounts |
| Labour category / role / team type | Statutory deductions (NHIF, NSSF, Housing Levy) |
| Work phase classification | Bank account details |
| Total project labour CostLine amount | Individual employee payroll history |

### Architecture guarantee

W7-2's confirmed rate basis (Approved Budget Standard Rate, not employee salary) makes this
enforcement automatic: the salary column is never read by any W7 path.

Employee tagging (W7-3, optional) exposes only: employee name, ID, and role — not compensation.

---

## 16. One-Economic-Cost-Once Control

**Rule W7-12 — CONFIRMED (2026-09-24)**

Payroll remains the authoritative company-level labour expense event (GL 5200 / 7550).
W7 project labour CostLines are analytical records only.

### Mechanism

```
postsIndependently = false  on  CostContext
    ↓
CostCollectorService::postFromSource()
    checks postsIndependently
    → skips JournalPostingService::postCostLine()
    → CostLine written as analytical subledger record only
    → zero GL impact
```

This is the STAB-7 pattern, currently used by `PettyCashRequisitionController`.

### Cost reconciliation identity

At company level:

    Company Payroll Expense (GL 5200) = Project Direct Labour + Overhead Labour + Payroll-to-Project Variance

This reconciliation is a management reporting output — constructible from:
- W7 analytical CostLines (sum of actual project labour, all projects)
- Payroll GL 5200 total for the period

The variance (W7-25) is the difference; its accounting treatment is an open accountant decision
(Section 19) but does not block building the CostLine layer.

---

## 17. Budget vs Actual Control

This section documents the confirmed Budget vs Actual labour control architecture,
distinct from W7-7 (over-budget alerting at recording time).

### Control architecture

```
Planned Labour CostLine   (nature = 'planned', status = 'verified')
    ↓  produced by BudgetProjector when budget is saved/revised

Actual Labour CostLine    (nature = 'actual', status = 'verified')
    ↓  produced by W7 actual confirmation workflow (W7-1 through W7-5)

CostAccountService::forEnquiry()
    → budget_category = 'labour'
    → sums planned lines as 'budgeted_labour'
    → sums actual lines as 'actual_labour'
    → computes variance = budgeted - actual
    → flags overrun where actual > budgeted
```

### Key invariants

1. **Planned lines are never promoted to actual.** A separate actual CostLine is written;
   planned and actual are distinct records.
2. **Unused budget produces a favourable variance, not a manufactured cost** (W7-8).
3. **Unbudgeted actual labour posts with `consumes_line_id = null`** and is visible as an
   over-budget line with no planned counterpart (W7-6).
4. **Budget revision reverses the old planned line and creates a new one** (existing
   append-only behaviour). The actual confirmation always targets the *current* planned line.

---

## 18. Technical/Casual Labour Decommissioning Boundary

This section clarifies the exact boundary between what is decommissioned and what is preserved.

### Safe to decommission at W7 implementation time

- Active UI panels and composables that reference TechnicalLabour as a live feature.
- API routes that create, update, or list TechnicalLabour records.
- Worker selection dropdowns that include TechnicalLabour alongside Employees.
- Validation rules that accept `technical_labour` as a valid worker type for new job cards.

### Must NOT be decommissioned (preserve indefinitely)

- Database tables: `technical_labours`, and any associated index/FK columns on
  `team_members`, `ot_entries`, `ledger_entries`, `compensation`.
- The `TechnicalLabour` Eloquent model class (demoted to dormant read-only model).
- Any governance/audit log entries that reference TechnicalLabour IDs historically.
- Historical `JobCard` records that may reference TechnicalLabour IDs (read-only).

### Migration safety rule

> **No destructive migration is permitted for any TechnicalLabour-related table.**
>
> Any W7 migration that touches the TechnicalLabour ecosystem must be:
> - A `nullable()` column addition, or
> - A route/middleware deregistration (PHP code change, not schema), or
> - A soft-delete/archive flag (if ever needed).
>
> `DROP TABLE technical_labours`, `DROP COLUMN technical_labour_id`, or any FK removal
> is PROHIBITED without a separate, explicitly approved, destructive-migration decision.

---

## 19. Open Finance/Accountant Decisions

The following three decisions remain AWAITING CONFIRMATION. They do NOT block the analytical
project labour costing layer (`postsIndependently = false`) but will be required before W7's
GL-posting and variance-reporting features can be finalised.

### W7-24 — GL / WIP Project-Dimension Treatment

**Decision required:** Should project labour require general ledger reclassification
between WIP (Account 1212) and Cost of Sales (Account 5200), or does analytical subledger
costing via `postsIndependently = false` serve as the permanent operational standard?

**Why it matters:**
- If GL reclassification is required: when a project milestone is billed, a journal must be
  written: `Dr Cost of Sales 5200 / Cr WIP 1212` (for the project labour amount billed).
- If analytical subledger is sufficient: no additional journal is required; the analytical
  CostLine provides management reporting without a GL reclassification.

**Interaction:** STAB-2 (deferred GL posting cleanup). Must not be decided in isolation.

**Decision owner:** Finance/Accounts Lead + Accountant

**Status:** AWAITING FINANCE / ACCOUNTANT CONFIRMATION

---

### W7-25 — Standard-Cost vs Actual Payroll Variance Accounting

**Decision required:** How should the accounting variance between Project Standard Labour
Cost (Budget Rate × Usage) and Actual Company Payroll Expense (GL 5200) be reported and
treated in management reporting?

**The variance exists because:**
- The W7 analytical cost uses a budgeted standard rate (approved at project budgeting time).
- The actual company payroll expense is the real payroll run amount (GL 5200).
- These two figures will not always match — the difference is a labour cost variance.

**Options (not decided here):**
- A. Period-end management reconciliation only (no additional journal entry).
- B. Formal variance journal: `Dr Labour Rate Variance account / Cr Overhead Pool`.
- C. Retroactive rate adjustment after each payroll run.

**Decision owner:** Finance/Accounts Lead + Accountant

**Status:** AWAITING FINANCE / ACCOUNTANT CONFIRMATION

---

### W7-26 — Employer Statutory-Cost Absorption into Standard Labour Rates

**Decision required:** Should employer statutory contributions (NSSF Tier I/II, Affordable
Housing Levy, and any future employer charges) be factored into standard project labour rates
or remain company overhead?

**Context:**
- Employer statutory costs are already posted to GL 5200 / 7550 as part of payroll accruals.
- If included in standard rates: Project Budget `unitRate` values must be employer-loaded rates
  (e.g. base rate + NSSF Tier II employer share + AHL employer share).
- If excluded: statutory costs remain part of the company overhead pool and the W7-25 variance
  absorbs them.

**Decision owner:** HR + Finance/Accounts Lead + Accountant

**Status:** AWAITING HR + FINANCE / ACCOUNTANT CONFIRMATION

---

### Impact on W7 Implementation

| Decision | Blocks analytical CostLine? | Blocks GL journal? | Blocks variance report? |
|---|---|---|---|
| W7-24 GL/WIP treatment | NO | YES (if reclassification required) | NO |
| W7-25 Variance accounting | NO | NO | YES (treatment) |
| W7-26 Statutory absorption | NO | NO | Partially (rate methodology) |

**Conclusion:** None of the three open accountant decisions block building the analytical
project labour costing mechanism. The confirmed subset (W7-1 through W7-10) can be implemented
on top of the existing CostCollector infrastructure with `postsIndependently = false`.

---

## 20. W8 Boundary

W8 — Logistics / Fleet Cost is a separate workflow.

The following statements define the boundary between W7 and W8:

1. **W7 scope:** Labour performed by people (employees, workers) on projects.
2. **W8 scope:** Costs arising from vehicle trips, fleet maintenance, and logistics
   operations attributable to projects.
3. **Driver labour / fleet staff labour:** If a driver's time is attributed to a project as
   a labour cost, that is W7. The vehicle trip cost (fuel, maintenance, distance-based rate)
   is W8.
4. **W8 decisions remain AWAITING WNG CONFIRMATION** (see Decision Register, W8 section).
5. **No W8 work is performed in this task.**

---

## 21. Decision Register Changes

This section documents the exact changes made to the authoritative Decision Register
(`03_WNG_FINANCE_DECISION_REGISTER.md`) as part of this exercise.

### Changes made

| Change | Location in Register | Commit |
|---|---|---|
| Added W7 Governance Reconciliation header | Line 177 | `528aadf` |
| Added Confirmed WNG Current-State Facts block (5 items) | Lines 179–184 | `528aadf` |
| Added Superseded Historical Decisions table (W7-1A Hist., W7-1B Hist.) | Lines 188–193 | `528aadf` |
| Added Confirmed Workflow 7 Decisions table (W7-1 through W7-10) | Lines 197–210 | `528aadf` |
| Added Preserved Compatible Earlier Rules table (W7-11 through W7-23) | Lines 214–230 | `528aadf` |
| Added Open Accountant Decisions table (W7-24 through W7-26) | Lines 234–240 | `528aadf` |

### What was NOT changed

- W6 decisions (W6-1 through W6-12) — unchanged.
- W8 decisions (W8-1 through W8-5) — unchanged.
- W1 through W5 decisions — unchanged.
- Any historical record was preserved in place; no historical rows were deleted.

### Integrity check performed

Post-update grep confirmed:

- The string `TechnicalLabour` appears only in historical/superseded rows and W7-10 decommissioning.
- The string `JobCard` appears only in historical/superseded rows.
- The string `crew-day` appears only in the reframing note for W7-1B (Hist.).
- No active decision row references the discarded architecture as the confirmed path.

---

## 22. Implementation Readiness Matrix

| ID | Business Decision | Confirmed? | Repository Architecture Identified? | Open Accounting Dependency | Ready for Implementation? |
|---|---|---|---|---|---|
| W7-1 | Attribution source: Project Budget labour lines (Option A) | YES — WNG 2026-09-24 | YES — `task_budget_data.labour_data`, `BudgetProjector`, `CostCollectorService::postPlanned()` | None | YES |
| W7-2 | Cost rate: Approved Budget Standard Rate (Option A); `postsIndependently = false` | YES — WNG 2026-09-24 | YES — `CostContext.postsIndependently`, STAB-7 pattern, `CostCollectorService::postFromSource()` | W7-25 (variance treatment) — does NOT block CostLine | YES |
| W7-3 | Attribution granularity: Role/Category default; Employee optional | YES — WNG 2026-09-24 | YES — `details` JSON on CostLine; `employees.id` for optional tagging | None | YES |
| W7-4 | Two-stage operational control: Recorder + PO verification | YES — WNG 2026-09-24 | YES — CostLine status machine; existing permission model | None | YES |
| W7-5 | Finance verification required before final Actual Cost | YES — WNG 2026-09-24 | YES — Finance verification step in CostLine status machine | None | YES |
| W7-6 | Unbudgeted labour: Record with justification; `consumes_line_id = null` | YES — WNG 2026-09-24 | YES — `CostLine.consumes_line_id` nullable; justification in `details` | None | YES |
| W7-7 | Over-budget: Record full actual + alert/escalate | YES — WNG 2026-09-24 | YES — `cost_overrun_alert_percent` on `ProjectEnquiry`; `CostAccountService` | None | YES |
| W7-8 | Unused budget = favourable variance; not manufactured cost | YES — WNG 2026-09-24 | YES — `ProjectFinancialClosureService`; planned CostLine closure at project close | None | YES |
| W7-9 | Forward-only from implementation date | YES — WNG 2026-09-24 | YES — no historical backfill needed; governance config record at implementation time | None | YES |
| W7-10 | Decommission active TL workflow; preserve historical tables | YES — WNG 2026-09-24 | YES — dependency map in Report 37; decommission boundary defined (Section 18 above) | None | YES |
| W7-11 | Payroll privacy: no salary exposure via project CostLines | CONFIRMED | YES — W7-2 rate basis (Budget Rate, not salary) makes this automatic | None | YES |
| W7-12 | One Economic Cost Once: `postsIndependently = false` | CONFIRMED | YES — STAB-7 pattern in place | None | YES |
| W7-13 | Labour corrections via W6-4 Cost Transfer (`CL-TRF-OUT`/`CL-TRF-IN`) | CONFIRMED | YES — `CostTransferService::transfer()` in place | None | YES |
| W7-14 | W6 closure guard enforced on labour CostLines | CONFIRMED | YES — `CostCollectorService::collect()` closure guard at lines 40–60 | None | YES |
| W7-24 | GL/WIP project-dimension treatment | AWAITING | Partial — `postsIndependently = false` path identified; GL reclassification path not yet designed | STAB-2 interaction | GL journal only |
| W7-25 | Standard-cost vs actual payroll variance accounting | AWAITING | Architecture for variance reconciliation report is identifiable; treatment not decided | Full | Variance report only |
| W7-26 | Employer statutory-cost absorption into standard rates | AWAITING | Rate methodology impact identified; no block on CostLine mechanism | Full | Rate methodology only |

---

## 23. Final Verdict

### Summary of readiness

All ten W7 business operating decisions (W7-1 through W7-10) have been confirmed by WNG.
The repository architecture has been verified against actual code (Report 37 and the bounded
repository inspections in this session). The three open accountant decisions (W7-24, W7-25,
W7-26) are correctly classified as accounting treatment questions that affect GL journal
generation and variance reporting — they do not block building the analytical project labour
costing mechanism.

Specifically:

- The `CostCollector` infrastructure is in place (`CostContext`, `CostCollectorService`,
  `CostLine`, `BudgetProjector`).
- The `postsIndependently = false` pattern is proven and in use (STAB-7).
- The Project Budget labour data is structured and already projected to `cost_lines`.
- The W6 closure guard, Cost Transfer, and Financial Closure services are in place and will
  govern W7 CostLines without modification.
- The TechnicalLabour dependency map is complete and the decommissioning boundary is defined.

---

## W7 CONFIRMED SUBSET READY FOR IMPLEMENTATION

---

**Scope of the confirmed subset:**

The following W7 capabilities may be implemented immediately using the confirmed decisions and
the existing repository architecture, without requiring the open accountant decisions to be
resolved first:

1. **Actual Labour Confirmation workflow** — recorder confirms actual usage against approved
   Project Budget labour lines (W7-1, W7-4).
2. **Actual Labour Cost computation** — Actual Usage × Approved Budget Standard Rate (W7-2).
3. **Two-stage verification** — PO verification + Finance verification (W7-4, W7-5).
4. **Unbudgeted Labour recording** — with justification and `consumes_line_id = null` (W7-6).
5. **Over-budget alerting** — record full actual + alert/escalate (W7-7).
6. **Budget vs Actual labour view** — in `CostAccountService::forEnquiry()` (W7-7, W7-8).
7. **Closure and correction** — via existing `ProjectFinancialClosureService` and
   `CostTransferService` (W7-8, W7-13, W7-14).
8. **TechnicalLabour active workflow decommissioning** — per defined boundary (W7-10, Section 18).

**What is NOT in the confirmed subset (awaiting accountant decisions):**

- GL reclassification journals for project labour (W7-24).
- Period-end payroll-to-project variance reconciliation report (W7-25).
- Employer statutory-cost absorption into standard rate methodology (W7-26).

**Implementation may proceed on the confirmed subset. The open accountant decisions must be
resolved before the GL and variance-reporting features are built.**

---

*Report 38 prepared: 2026-09-24*
*Branch: `finance/critical-stabilization-fixes`*
*Decision Register: `03_WNG_FINANCE_DECISION_REGISTER.md` (updated in place, commit `528aadf`)*
*W7 Architecture: `37_PHASE_2B_W7_LABOUR_DECISION_ARCHITECTURE.md`*
*Next task: W7 Implementation (confirmed subset only — pending separate instruction)*
