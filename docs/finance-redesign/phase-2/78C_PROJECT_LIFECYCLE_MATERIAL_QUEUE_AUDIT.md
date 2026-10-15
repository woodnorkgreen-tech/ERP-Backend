# Report 78C — Project Lifecycle-Aware Material Queues: Stage A Audit

**Date:** 2026-10-15 · **Type:** Discovery only. No code, schema or data changed.
**Builds on:** Report 78 (Resolve Material audit), Report 78B (line identity + one UoM policy).
**Evidence:** `78c-evidence/lifecycle_baseline.php` (read-only, runs in a rolled-back transaction) → `78c-evidence/summary.json`.

```
ddev exec php docs/finance-redesign/phase-2/78c-evidence/lifecycle_baseline.php
```

---

## 1. Verdict

**Material demand ignores the project lifecycle entirely. Stage B is needed. It is mostly a read-side change, but four decisions belong to WNG (section 9).**

`ProjectMaterialDemand`, the one service behind the Stores demand forecast, the buyer's material picker and the replenishment planner, has **no lifecycle filter**. It counts any approved BOM line whose enquiry has a `projects` row. So completed jobs drive procurement today:

| Measure (dev, today) | All jobs | Completed / cancelled jobs | Share |
|---|---:|---:|---:|
| Pending-to-issue lines | 342 | 198 | **58%** |
| Pending-to-issue quantity (stock units, summed) | 1,278.8 | 914.3 | **71%** |
| Materials with pending demand | 41 | 27 come **only** from inactive jobs | **66%** |
| Replenishment suggestions (all `job_shortfall`) | 36 | 24 disappear if only active jobs count | **67%** |
| Unresolved approved lines | 4,940 | 3,198 | 65% |

The 3,198 figure matches the Report 78B baseline exactly. The total moved from 4,965 to 4,940 since 78B because of ordinary dev activity.

Three further findings change the shape of the fix:

1. **`projects.status` is stale and cannot be the authority.** 356 projects say `planning` while their enquiry says `completed`. The Project Materials Desk sorts jobs into tabs on `project.status || enquiry.status`, so **its default "In Progress" tab lists 536 jobs, of which only 174 are active**. It also lists 356 completed jobs, 2 cancelled jobs and 4 that have not started.
2. **There is no On Hold, Archived or Legacy state anywhere.** No column, enum value or flag exists for any of them. All three must be designed.
3. **The "overdue but active" population is really an unclassified legacy population.** 162 jobs are `planning`/`in_progress` and past their delivery date. 156 of them are 91–180 days overdue, and only 2 have had any Stores movement in the last 90 days. The brief's rule ("overdue is not completion") correctly keeps them in the queue. But they carry **1,264 unresolved lines**, and they will stay in Active Work until someone decides about each one. The legacy classification (section 6) exists for exactly this case.
   *Caveat:* the dev enquiry snapshot ends 2026-07-26, so dev overstates how overdue these jobs are compared with live. The count of jobs stuck in `planning` is real either way.

---

## 2. Actual lifecycle data

### 2.1 Where status lives

| Field | Values (DB enum) | Role |
|---|---|---|
| `project_enquiries.status` | `client_registered … awaiting_deposit, planning, in_progress, completed, closed, cancelled` (16 values) | **The authority.** Written by every lifecycle action. |
| `projects.status` | `planning, in_progress, completed, closed, cancelled` | A mirror. Synced only by `CompleteProjectAction`, `EnquiryWorkflowService` (planning→in_progress) and `UpdateEnquiryAction` (generic PUT). It was never backfilled for imported jobs. |
| `project_enquiries.expected_delivery_date`, `projects.end_date` | date | Schedule. "Overdue" = delivery date < today. |
| `project_enquiries.financial_closure_status` | `open` (all 1,012 rows) | Finance closure. It is never used yet, so it is not a usable lifecycle signal. |
| On hold / archived / legacy | — | **Do not exist.** |

### 2.2 Dev distribution

| Enquiry status | Enquiries | With a `projects` row | Business meaning |
|---|---:|---:|---|
| `enquiry_logged` … `quote_prepared` | 238 | 1 | Pipeline. Not a commitment. |
| `quote_approved`, `awaiting_deposit` | 36 | 3 | Confirmed, waiting for funds. |
| `planning` | 173 | 173 | Active delivery preparation. Treated as active everywhere else (Cost Collector, Petty Cash). |
| `in_progress` | 1 | 1 | Active. Only reached when a production task starts. |
| `completed` | 433 | 356 | Delivery done. **No `project_completed` governance event exists for any of them.** Every one was imported or set directly. |
| `closed` | 0 | 0 | Handover + report done. Reached only from `completed`. |
| `cancelled` | 130 | 2 | Abandoned. |

`projects.status` ≠ `project_enquiries.status` on 363 rows: 356 completed, 2 cancelled, 1 in_progress, 4 pre-project.

### 2.3 Transitions

| Transition | Path | Gate | Audit |
|---|---|---|---|
| → planning | `ActivateProjectAction` | quote + deposit gates | yes |
| planning → in_progress | `EnquiryWorkflowService` on a production task start | automatic | task log |
| → completed | `POST /enquiries/{id}/complete` (`CompleteProjectAction`) | task readiness + finance gate (admin override logged) | `GovernanceAuditLog: project_completed` |
| completed → closed | `POST /enquiries/{id}/close` | handover + report tasks done | `project_closed` |
| → cancelled | **generic `PUT /enquiries/{id}` with `status=cancelled`** | none | **none** |
| completed/closed → planning (reopen) | **generic `PUT /enquiries/{id}`** | none | **none** |

`UpdateEnquiryAction` blocks `status=completed` and `status=closed` through the PUT. It does not block `cancelled`, and it does not block a move *backwards* from `completed`. Reactivation and cancellation therefore have no authorisation and no audit trail. Phase 7 needs both.

### 2.4 The codebase has four different meanings of "active"

| Where | Rule | Treats `completed` as |
|---|---|---|
| `EnquiryConstants::getClosedStatuses()` | `closed, cancelled` | open (!) |
| `CostLineController` default picker | `whereNotIn(getClosedStatuses())` | open |
| `PettyCashRequisitionController` project picker | not `completed/cancelled/closed` | closed |
| `ProjectMaterialResolution::assertOpenForStores` (78B) | enquiry **or** project status in `completed/cancelled/closed` | closed |
| `ProjectMaterialDemand` | none | open |
| Project Materials Desk tabs (frontend) | `project.status \|\| enquiry.status` text matching | depends on the stale mirror |

A shared policy is not just tidier. Without one, the same job is active on one screen and closed on the next.

---

## 3. Inventory of every place that derives actionable material work

### 3.1 Read side (queues, counts, demand)

| # | Endpoint / service | Consumer | Lifecycle filter today | Needed |
|---|---|---|---|---|
| R1 | `ProjectMaterialDemand::pendingLines / pendingByMaterial` | everything below | **none** (only "has a projects row") | eligibility scope at the source |
| R2 | `GET /procurement-stores/material-demand-forecast` | Desk "Materials at risk" + forecast drawer | via R1: none | inherit from R1, plus a lifecycle split in `projects[]` |
| R3 | `GET /procurement-stores/material-options` (`pending_demand`, `uncommitted`) | `MaterialPicker` (buyer raising a requisition) | via R1: none | inherit from R1 |
| R4 | `ReplenishmentPlanner::suggestions` + `GET/POST /replenishment-suggestions` | Procurement › Replenishment, draft requisition | via R1: none | inherit from R1 |
| R5 | `GET /api/projects` (closure in `routes/api.php:366`) | Desk project picker, tabs and counts | none server-side; the frontend tabs on the stale `project.status` | return a server-computed `lifecycle` block; tab on it |
| R6 | Desk per-project rows (`GET /projects/enquiries/{id}/materials` → `fulfilmentForProject`) | To issue / Short / Unlinked / Ready chips and counts | none (client-side counts) | rows stay visible (history); add a `workflow.read_only` flag; counts follow the lifecycle |
| R7 | Desk "Unlinked" count = **Pending Resolution** | Desk header health chip | per project only; no cross-project queue exists | cross-project count from the policy (new, section 7) |
| R8 | `GET /procurement-stores/action-queue` | Stores dashboard | GRN / inspection / count / finance only. No BOM demand. | **leave alone** (these are obligations) |
| R9 | `GET /procurement-stores/outstanding-reusables` | Desk "Awaiting return" | none | **leave alone.** A completed job still holding boards still owes them back. |
| R10 | Board requests `status=pending` | Stores dashboard "To issue", Production materials view | none | apply the policy to *new* requests. Show existing ones as close-out items. |
| R11 | Procurement dashboard (POs, requisitions, bills-stats, suppliers) | Procurement › Dashboard | none | **leave alone.** These are Existing Procurement Obligations. |

### 3.2 Write side (backend enforcement, test 15)

| # | Path | Lifecycle check today |
|---|---|---|
| W1 | `ProjectMaterialResolution::assertOpenForStores` (resolve, request-new, disposition) | **yes**: inline `completed/cancelled/closed`, enquiry or project |
| W2 | `StockMovementPoster` project issue (`assertProjectLineCanTake`, `assertMaterialsApproved`) | **no**. You can issue to a cancelled job. |
| W3 | `BoardRequestController::store` with `project_material_id` | **no** |
| W4 | `RequisitionController::store` (`requested_by_type=project`) | **no**. You can raise a project requisition on a cancelled job. |
| W5 | `ReplenishmentController::draft` | not needed if R4 is filtered (office requisition, not job-attached) |
| W6 | Returns, `linkProjectMaterial` correction | none. **Must stay open.** A return or correction on a finished job is legitimate. |

W1 is the only lifecycle check in the codebase, and it is the third private copy of the rule. Stage B moves it into the shared policy.

---

## 4. Proposed policy: `ProjectMaterialWorkflowEligibility`

**Location:** `app/Modules/ProcurementStores/Services/ProjectMaterialWorkflowEligibility.php`. It sits beside `ProjectMaterialDemand` and `RequirementUnits` and follows the same pattern: one service owns the rule and every caller asks it.

**Authority:** `project_enquiries.status`, plus the two new overlays from sections 5–6. It never reads `projects.status`. Stage B also adds a one-off reconciliation command for the mirror (section 8).

### 4.1 States

| State | Derived from | |
|---|---|---|
| `active` | enquiry `planning` / `in_progress`, has a projects row, not on hold, not legacy | |
| `active_overdue` | `active` and `COALESCE(expected_delivery_date, projects.end_date) < today` | a **tag** on active, never an exclusion |
| `on_hold` | active + an open hold record (section 5) | |
| `pre_start` | `quote_approved` / `awaiting_deposit` with a projects row | conditional (decision D-3) |
| `pipeline` | any earlier status, or no projects row | not a commitment. Matches today's "quote, not commitment" rule. |
| `completed` · `closed` · `cancelled` | enquiry status | historical |
| `legacy` | an active, unrevoked legacy classification (section 6) | historical |

### 4.2 What each state allows

| Capability | active / overdue | on_hold | pre_start | pipeline | completed / closed / cancelled / legacy |
|---|:-:|:-:|:-:|:-:|:-:|
| 1 Material resolution (W1) | ✔ | ✔ (planning work, no stock moves) | ✔ | — | ✘ (authorised correction only) |
| 2 Pending Stores issue (R6, W2, W3) | ✔ | ✘ | ✘ (D-3) | ✘ | ✘ |
| 3 Stock shortage (R2) | ✔ | ✘ | ✔ (D-3) | ✘ | ✘ |
| 4 New procurement demand (R3, R4, W4) | ✔ | ✘ | ✔ (D-3) | ✘ | ✘ |
| 5 Active dashboard counts | ✔ | own tab | own tab | — | Historical tab |
| Returns, corrections (W6), existing POs/bills/payments/GRNs | ✔ | ✔ | ✔ | ✔ | **✔ always** |

### 4.3 API

```php
stateFor(int $enquiryId): string
statesFor(Collection $enquiryIds): array<int, string>   // one query, for list endpoints
allows(string $state, string $capability): bool
scope(Builder $enquiries, string $capability): Builder  // SQL-level, so lists and counts share one predicate
lineIsRetired(ElementMaterial $line): bool              // section 6, requirement-level legacy
assertAllows(int $enquiryId, string $capability): void  // ValidationException(422), for W1–W4
```

`ProjectMaterialDemand::specifiedLines()` takes a `capability` argument (default `procurement_demand`). Every downstream reader inherits the filter that way, and none of them has to remember to apply it. The Desk tabs and the counts are computed by the same `scope()`, so **a count is always the length of the list it labels** (acceptance criterion 8).

---

## 5. On Hold (new)

No hold state exists. **Proposal:** an overlay, not a 17th enum value. Adding to the status enum would break the six "active status" lists above, and it would make the generic PUT the hold path.

`project_holds`: `id, project_enquiry_id, reason, placed_by, placed_at, released_by, released_at, release_reason`. One open row means the job is on hold. Placing or releasing a hold needs a permission and writes a governance event. Releasing a hold changes nothing stored: demand is derived on read, so it reappears by itself.

*If WNG does not hold jobs in practice, drop this and leave the On Hold filter out. See D-2.*

---

## 6. Legacy — Fulfilled Outside ERP (new)

**Rule:** a job is never classified by date. A person with authority classifies it and gives a reason.

### 6.1 Requirement-level, not just project-level

The brief needs historical requirements and genuinely new work to coexist on one job. BOM lines are upserted by `persistent_id` and soft-deleted on removal (`ProjectElementRegistry::saveLines`), so `element_materials.id` is stable. That makes a per-line record safe:

`material_requirement_retirements`
`id, element_material_id, project_enquiry_id, retired_quantity (stock units, snapshotted from RequirementUnits), basis ('legacy_fulfilled_outside_erp'), reason, retired_by, retired_at, revoked_by, revoked_at, revoke_reason`

- **Classify a job** = one retirement row per line that has outstanding quantity at that moment, each for the remaining quantity. It is all-or-selected and runs in one transaction.
- **Remaining** becomes `max(0, required − net_issued − retired_quantity)`. A line whose quantity is raised later shows only the increase as live work. A line added later is live. Nothing is deleted, and no stock, GRN, requisition or journal is created.
- **A job is `legacy`** when it is still `planning`/`in_progress` and every outstanding line is retired. Otherwise it stays active and only the new work shows.
- **Reverse** = set `revoked_*` on the rows. Rows are append-only and never deleted. The quantity comes back as live demand immediately, because nothing was stored. Revoking requires the reconciliation note the brief asks for: a free-text statement of what was manually issued, kept on the row.
- **For completed / closed / cancelled jobs, retirement is not needed.** The lifecycle already excludes them. Retirement is the tool for the 162 jobs stuck in `planning`.

Permission: a new `project_materials.retire_legacy`. **Holder: decision D-1.** It should not be the Stores role alone. The same person should not be able both to retire demand and to issue against it without a second look.

---

## 7. Metric definitions (Phase 6)

Each label names exactly one unit, and each count comes from the same scope as its list.

| Metric | Unit | Definition |
|---|---|---|
| **Pending to Issue** | lines · jobs · stock-unit qty (three separate figures, never summed across materials) | included, approved, linked, issuable (78B units) lines on `issue`-eligible jobs, with `remaining > 0` |
| **Pending Resolution** | lines | included, approved lines on `resolution`-eligible jobs with no `library_material_id` and no disposition |
| **Awaiting Registration** | lines | the same, with disposition `awaiting_registration` |
| **Procurement Shortage** | materials | forecast rows on `shortage`-eligible demand with status `partially_covered` or `shortage` (= today's "at risk") |
| **Unit exceptions** | lines | linked lines that `RequirementUnits` cannot convert (78B register 01). They are excluded from Pending to Issue but **counted**, so the gap is visible. |
| **Historical Requirements** | lines | outstanding lines on completed/closed/cancelled jobs, plus retired lines. Read-only. |
| **Existing Procurement Obligations** | per document type | open POs, unbilled GRNs, unpaid bills, unpaid supplier payments, GRN discrepancies, returns/credits, finance posting exceptions, **regardless of job lifecycle** |

Stage B adds `GET /procurement-stores/material-workflow-summary`, which returns all of the above from the one policy. The Desk header, the Stores dashboard and the Procurement dashboard read it rather than counting on the client.

---

## 8. Data review (dev `db`, 2026-10-15, read-only)

### 8.1 Approved, included BOM lines by lifecycle

| Lifecycle (enquiry status) | Jobs | Lines | Linked | **Unresolved** |
|---|---:|---:|---:|---:|
| active | 8 | 119 | 12 | 107 |
| active_overdue | 87 | 1,420 | 156 | 1,264 |
| pre_start (has project row) | 2 | 51 | 4 | 47 |
| pipeline (no project row) | 29 | 360 | 36 | 324 |
| completed | 252 | 3,505 | 325 | 3,180 |
| cancelled | 2 | 18 | 0 | 18 |
| closed · on_hold · legacy | 0 | 0 | — | — (states unused / do not exist) |
| **Total** | **380** | **5,473** | **533** | **4,940** |

No line has a disposition set yet (`awaiting_registration` and `non_stock` are both 0), and no line sits under an archived element.

### 8.2 Pending to Issue, as `ProjectMaterialDemand` computes it now

| Lifecycle | Lines | Jobs | Materials | Qty (stock units) |
|---|---:|---:|---:|---:|
| active | 11 | 3 | 4 | 27.0 |
| active_overdue | 129 | 60 | 12 | 306.5 |
| pre_start | 4 | 1 | 4 | 31.0 |
| **completed** | **198** | **88** | **39** | **914.3** |

### 8.3 Before → after

| Measure | Before | After (active + overdue only) |
|---|---:|---:|
| Pending-to-issue lines | 342 | 140 (144 if pre_start counts) |
| Unresolved lines in an active queue | 4,940 (no lifecycle split today) | 1,371 |
| Materials with pending demand | 41 | 14 (27 lose all demand) |
| Replenishment suggestions | 36 | **12** |
| Desk "In Progress" tab | 536 jobs | 174 jobs |

**And after WNG triages the 162 stuck jobs:** if most turn out to be finished work, then completing or retiring them takes the active queue down to the 8 genuinely current jobs (119 lines). That is a decision, not a filter (D-1, D-5).

### 8.4 Obligations on inactive jobs (must stay visible)

2 open project requisitions on completed jobs. There are no bills, board requests or POs traced to inactive jobs in dev. Dev procurement data is thin (3 POs, 10 requisitions, 0 bills), so the obligation-side tests in Stage B must be built on fixtures, not dev data.

---

## 9. Decisions WNG must make before Stage B

| # | Decision | Recommendation |
|---|---|---|
| **D-1** | Who holds `project_materials.retire_legacy` (classify Legacy — Fulfilled Outside ERP) and who may revoke it? | Projects Manager or Operations lead, not Stores alone. Revoking needs the same permission plus a reconciliation note. |
| **D-2** | Does WNG put jobs on hold? If yes, who places and releases a hold? | Build `project_holds` only if yes. Holder = Project Officer's manager. |
| **D-3** | `quote_approved` / `awaiting_deposit` jobs that already have a project row: do they create procurement demand (lead time) before the deposit lands? | Yes for demand and shortage, no for issue. They appear under a separate "Pre-start" tab. |
| **D-4** | Issuing to a completed job (snag or remedial work): refuse outright, or allow with a permission and a reason? | Refuse by default. Allow `stores.issue_to_closed_job` with a reason, logged. Returns and corrections are always allowed. |
| **D-5** | The 162 `planning` jobs past their delivery date (156 are 3–6 months late): who reviews them, and do they get completed through `/complete` or retired as legacy? | A one-time triage list from Stage B, worked by Projects. **Never bulk-classified by date.** |
| **D-6** | Reactivating or cancelling a job currently needs no authority and leaves no audit (`PUT /enquiries/{id}`). Lock both behind governed actions? | Yes. `POST /enquiries/{id}/reactivate` and `/cancel`, each with a reason and a governance event. Block both through the generic PUT, the same way completed/closed are blocked today. |

---

## 10. Stage B plan (after the decisions)

1. **Policy service** + unit tests for the state table (pure, no DB).
2. **Source filter** in `ProjectMaterialDemand` → R2, R3 and R4 inherit it. **This single change delivers the 36 → 12 replenishment fix.**
3. **Write enforcement:** W1 moves onto the policy; W2, W3 and W4 call `assertAllows`; W6 stays explicitly allowed.
4. **`/api/projects` lifecycle block** + a `material-workflow-summary` endpoint. The Desk switches its tabs to Active Work · Overdue Active · On Hold · Pre-start · Historical / Completed · All, defaulting to Active Work, with historical rows read-only.
5. **Governed reactivate / cancel** (D-6) and **retirement** (section 6) with their UI. Per the standing rule, every backend change ships with its screen.
6. **`projects.status` reconciliation command:** dry-run by default, it reports the 363 mismatches, and with `--apply` it sets the mirror from the enquiry. Run on dev only, never production without approval.
7. **Tests 1–16 from the brief.** Test 11 (counts = list lengths) and test 14 (lifecycle filtering creates zero `inventory_logs`, `journal_entries`, `requisitions` and `cost_lines`) are written as invariants. The 78B Resolve and UoM suites are re-run unchanged.

**Already true by construction (no work needed):** reactivation cannot duplicate demand or reservations. Demand is derived on read and there is no stored counter, so lifting a lifecycle exclusion only reveals what was already there. Duplicate requisitions are a separate, partial risk. The planner shows open requisitions as context but does **not** subtract them from the suggestion, so a buyer could draft twice. Stage B should subtract open requisition lines (`draft/pending_approval/approved`, not yet ordered) from the suggestion as well as listing them (test 13).

## 11. Risks

- **`projects.status` readers outside Stores** (`ProjectsDashboardService` counts `Project::where('status','completed')`, which reports 0 completed today). Fixing the mirror changes that dashboard's numbers. That is correct, but it is visible.
- **The concurrent 78B tree:** 78B is uncommitted in ERP-Backend (92 changed paths). Stage B edits `ProjectMaterialDemand` and `ProjectMaterialResolution`, which 78B also touched. Stage only by explicit path.
- **Dev data age:** overdue measurements are against a July snapshot. Re-run the evidence script against the target before any triage list is used operationally.
