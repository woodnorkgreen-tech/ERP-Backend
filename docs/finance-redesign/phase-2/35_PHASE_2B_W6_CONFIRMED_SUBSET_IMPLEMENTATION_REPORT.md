# WNG ERP FINANCE — PHASE 2B WORKFLOW 6
# CONFIRMED SUBSET IMPLEMENTATION REPORT

**Document ID:** `35_PHASE_2B_W6_CONFIRMED_SUBSET_IMPLEMENTATION_REPORT.md`  
**Date:** 2026-09-24  
**Author:** AI Agent (Antigravity)  
**Status:** IMPLEMENTATION COMPLETE — READY FOR INDEPENDENT CLOSURE GATE  
**Context:** Implements the confirmed subset of Workflow 6 (Project Costing) concurrently across Backend and Frontend with complete test coverage.

---

## 1. Executive Summary

Following the reconciliation between `34_PHASE_2B_W6_PROJECT_COSTING_DECISION_ARCHITECTURE.md` and `03_WNG_FINANCE_DECISION_REGISTER.md`, the confirmed subset of Workflow 6 — Project Costing has been fully implemented across the full stack:
* **Business Decision → Backend → Permissions → Functional Frontend → Accounting Integration → Audit Trail → Backend Tests → Frontend Tests**.

No operational workflow was left API-only. All controls are accessible in the UI with strict server-side permission gating.

### Governance Boundary Compliance
* **Labour (W7), Logistics (W8), and Overhead Allocation (W6-1A)** remain strictly unbuilt and labelled `not_included` (never defaulted to 0.00).
* **Final Margin vs Provisional Margin (W6-10)** is enforced: margin is always reported with `margin_status = 'provisional'` and `fully_loaded_available = false` until W7/W8/W6-1A are resolved.
* **Pre-Closure Checks (W6-5)** return `policy_classification = 'pending_configuration'` (all checks are advisory warnings; zero hardcoded blockers invented).
* **Late Cost Exception (W6-6)**: `finance.costs.reopen` was created in the database and assigned to **NO role** by default.
* **Shared Cost Invariant (W6-3)**: `SUM(allocated_amount) = parent.net_amount` is strictly enforced via `CostAllocationService` using bcmath arithmetic.
* **Cost Transfer Invariant (W6-4)**: The original cost line is **never mutated**; reclassification occurs via an auto-verified reversing pair (`CL-TRF-OUT-xxxxx` on source, `CL-TRF-IN-xxxxx` on destination) with a linking audit record in `cost_line_transfers`.

---

## 2. Implementation Scope

### 2.1 Backend Architecture

1. **Permissions (`app/Constants/Permissions.php`)**:
   - `finance.costs.portfolio`: Gating batched portfolio-wide margin view. Assigned to `Accounts` role.
   - `finance.costs.allocate`: Gating multi-project cost allocation. Assigned to `Accounts` role.
   - `finance.costs.transfer`: Gating cost transfer/reclassification. Assigned to `Accounts` role.
   - `finance.costs.close`: Gating project financial closure. Assigned to `Accounts` role.
   - `finance.costs.reopen`: Gating late-cost closure override. **Assigned to NO role** (WNG grants per event).
   - Added to `Permissions::all()`, `Permissions::grouped()`, and registered in database via migration.

2. **Database Migrations**:
   - `2026_09_24_000001_create_cost_line_allocations_table.php`: Child table for shared cost allocation with unique index `cla_line_project_unique`.
   - `2026_09_24_000002_create_cost_line_transfers_table.php`: Audit linking table for cost transfer triplets (source, out, in).
   - `2026_09_24_000003_add_financial_closure_to_project_enquiries.php`: Adds `financial_closure_status`, `financially_closed_by`, `financially_closed_at`, `closure_reopened_by`, `closure_reopened_at`, `closure_reopen_reason` to `project_enquiries`.
   - `2026_09_24_000004_add_w6_project_costing_permissions.php`: Seeds permissions and assigns the 4 confirmed permissions to Accounts role.

3. **Domain Models**:
   - `CostLineAllocation`: Represents a project slice of a shared cost line.
   - `CostLineTransfer`: Represents a transfer grouping linking source, OUT, and IN lines.
   - `CostLine`: Extended with `allocations()` hasMany relationship and `isAllocated()` check.
   - `ProjectEnquiry`: Extended with fillable and casts for financial closure fields.

4. **Domain Services**:
   - `CostAllocationService`: Atomic allocation/deallocation enforcing the sum invariant.
   - `CostTransferService`: Atomic creation of reversing pairs preserving source line immutability.
   - `ProjectFinancialClosureService`: Pre-closure check suite (unverified lines, queried lines, unposted invoices) with advisory classifications, plus `close()` and `reopen()`.
   - `CostAccountService`: Extended `marginAgainstJournals()` with `cost_completeness`, `margin_type`, `margin_status`, and `fully_loaded_available`. Extended `forEnquiry()` project block with closure status. Implemented `portfolioMargin(array $enquiryIds)` batched query (single round trip for N projects, zero N+1 queries).
   - `CostCollectorService`: Added financial closure guard in `collect()` preventing new costs from being booked to financially closed projects.

5. **Controllers & API Routes (`routes/api.php`)**:
   - `PortfolioMarginController`: `POST /api/costs/portfolio-margin` (permission: `finance.costs.portfolio`).
   - `CostAllocationController`: `GET/POST/DELETE /api/costs/lines/{cost}/allocations` (permission: `finance.costs.allocate`).
   - `CostTransferController`: `POST /api/costs/lines/{cost}/transfer` (permission: `finance.costs.transfer`).
   - `ProjectFinancialClosureController`:
     - `GET /api/costs/projects/{enquiry}/closure-check` (permission: `finance.costs.close`)
     - `POST /api/costs/projects/{enquiry}/close` (permission: `finance.costs.close`)
     - `POST /api/costs/projects/{enquiry}/reopen` (permission: `finance.costs.reopen`)

6. **Access Control (`ProjectFinancialAccess`)**:
   - Added `canViewPortfolio`, `canAllocate`, `canTransfer`, `canClose`, and `canReopen`.

---

### 2.2 Frontend Architecture

1. **TypeScript Types (`costCollector.ts`)**:
   - Extended `CostAccount` with `financial_closure_status` and `financially_closed_at`.
   - Extended `CostAccount.margin` with `cost_completeness`, `margin_type`, `margin_status`, `fully_loaded_available`.
   - Added interfaces: `CostLineAllocationSlice`, `CostLineAllocation`, `CostTransferResult`, `ClosureCheck`, `ProjectClosureCheckResult`, `CostCompleteness`, `ProjectMargin`.

2. **Frontend Service (`costCollectorService.ts`)**:
   - Added `portfolioMargin(enquiryIds)`, `getAllocations(costLineId)`, `allocate(costLineId, slices, reason)`, `deallocate(costLineId)`, `transfer(costLineId, destinationEnquiryId, reason)`, `closureCheck(enquiryId)`, `closeProject(enquiryId)`, `reopenProject(enquiryId, reason)`.

3. **Components & Views**:
   - `CostAllocationModal.vue`: Modal allowing users to split a verified cost across projects. Enforces dynamic sum validation and balance indicator before allowing submission.
   - `CostTransferModal.vue`: Modal for moving a verified cost to another project via reversing pair with mandatory reason.
   - `ProjectFinancialClosureModal.vue`: Displays structured pre-closure checks with advisory status, handles confirmation of closure, and supports exception reopen workflow with mandatory justification.
   - `CostAccountPanel.vue`:
     - Added financial closure badge and action button in project header.
     - Added cost completeness breakdown in margin block (showing included vs not-included categories).
     - Added "Allocate" and "Transfer" action buttons to charged cost lines in both elements and flat drilldowns.
     - Mounted all 3 modals with event listeners triggering automatic data reload.
   - `CostAccountsView.vue`:
     - Added `Direct margin` column to the portfolio accounts grid.
     - Implemented batched margin loading via `portfolioMargin()` (zero N+1 queries).
     - Added provisional indicator and explanatory footnote.

---

## 3. Test Verification & Results

### 3.1 Backend Test Suite
All 5 dedicated W6 test files pass cleanly:
* `tests/Feature/CostCollector/CostAllocationTest.php` (5 tests, 12 assertions) — **PASS**
* `tests/Feature/CostCollector/CostTransferTest.php` (5 tests, 25 assertions) — **PASS**
* `tests/Feature/CostCollector/ProjectFinancialClosureTest.php` (6 tests, 28 assertions) — **PASS**
* `tests/Feature/CostCollector/PortfolioMarginTest.php` (3 tests, 20 assertions) — **PASS**
* `tests/Feature/CostCollector/CostAccountMarginalTest.php` (3 tests, 16 assertions) — **PASS**
* `tests/Unit/Constants/PermissionRegistryTest.php` (9 tests, 996 assertions) — **PASS**
* Existing `tests/Feature/CostCollector/CostAccountTest.php` (23 tests, 101 assertions) — **PASS**

### 3.2 Frontend Test Suite
Full Vitest test suite passes with zero regressions:
* `tests/unit/finance/wave6Controls.spec.ts` (9 tests) — **PASS**
* Full suite: **25 test files / 142 tests passing** (baseline was 24 files / 133 tests).

---

## 4. Files Created and Modified

| Area | File | Change |
|---|---|---|
| Backend | `app/Constants/Permissions.php` | Added 5 W6 permissions, updated `all()`, `grouped()`, and Accounts role |
| Backend | `database/migrations/2026_09_24_000001_create_cost_line_allocations_table.php` | Created allocations table |
| Backend | `database/migrations/2026_09_24_000002_create_cost_line_transfers_table.php` | Created transfers audit table |
| Backend | `database/migrations/2026_09_24_000003_add_financial_closure_to_project_enquiries.php` | Added financial closure columns |
| Backend | `database/migrations/2026_09_24_000004_add_w6_project_costing_permissions.php` | Seeded W6 permissions |
| Backend | `app/Models/ProjectEnquiry.php` | Added closure fields to fillable and casts |
| Backend | `app/Modules/Finance/CostCollector/Models/CostLineAllocation.php` | Created allocation model |
| Backend | `app/Modules/Finance/CostCollector/Models/CostLineTransfer.php` | Created transfer model |
| Backend | `app/Modules/Finance/CostCollector/Models/CostLine.php` | Added `allocations()` and `isAllocated()` |
| Backend | `app/Modules/Finance/CostCollector/Services/CostAllocationService.php` | Created allocation service |
| Backend | `app/Modules/Finance/CostCollector/Services/CostTransferService.php` | Created transfer service |
| Backend | `app/Modules/Finance/CostCollector/Services/ProjectFinancialClosureService.php` | Created closure service |
| Backend | `app/Modules/Finance/CostCollector/Services/CostAccountService.php` | Extended margin calculation & added `portfolioMargin()` |
| Backend | `app/Modules/Finance/CostCollector/Services/CostCollectorService.php` | Added closed-project guard in `collect()` |
| Backend | `app/Services/ProjectFinancialAccess.php` | Added 5 W6 permission accessors |
| Backend | `app/Modules/Finance/CostCollector/Http/Controllers/CostAllocationController.php` | Created allocation controller |
| Backend | `app/Modules/Finance/CostCollector/Http/Controllers/CostTransferController.php` | Created transfer controller |
| Backend | `app/Modules/Finance/CostCollector/Http/Controllers/ProjectFinancialClosureController.php` | Created closure controller |
| Backend | `app/Modules/Finance/CostCollector/Http/Controllers/PortfolioMarginController.php` | Created portfolio margin controller |
| Backend | `routes/api.php` | Registered W6 API routes |
| Backend Tests | `tests/Feature/CostCollector/CostAllocationTest.php` | Created allocation feature tests |
| Backend Tests | `tests/Feature/CostCollector/CostTransferTest.php` | Created transfer feature tests |
| Backend Tests | `tests/Feature/CostCollector/ProjectFinancialClosureTest.php` | Created closure feature tests |
| Backend Tests | `tests/Feature/CostCollector/PortfolioMarginTest.php` | Created portfolio margin tests |
| Backend Tests | `tests/Feature/CostCollector/CostAccountMarginalTest.php` | Created margin completeness tests |
| Frontend | `src/modules/finance/cost-collector/types/costCollector.ts` | Extended types with W6 fields & interfaces |
| Frontend | `src/modules/finance/cost-collector/services/costCollectorService.ts` | Added W6 API client methods |
| Frontend | `src/modules/finance/cost-collector/components/CostAllocationModal.vue` | Created allocation modal |
| Frontend | `src/modules/finance/cost-collector/components/CostTransferModal.vue` | Created transfer modal |
| Frontend | `src/modules/finance/cost-collector/components/ProjectFinancialClosureModal.vue` | Created closure modal |
| Frontend | `src/modules/finance/cost-collector/components/CostAccountPanel.vue` | Updated header, margin completeness, line actions, modals |
| Frontend | `src/modules/finance/cost-collector/views/CostAccountsView.vue` | Added direct margin column & batched loading |
| Frontend Tests | `tests/unit/finance/wave6Controls.spec.ts` | Created unit tests for W6 modals and services |
| Documentation | `docs/finance-redesign/phase-2/03_WNG_FINANCE_DECISION_REGISTER.md` | Updated W6 items with implementation status |

---

## 5. Next Steps & Governance Rule

Per governance requirements:
* **STOP**: Do not begin Workflow 7 (Labour Cost).
* **STOP**: Do not begin Workflow 8 (Logistics/Fleet Cost).
* **STOP**: Do not perform the W6 Independent Closure Gate yourself.
* Await the independent closure gate review of Workflow 6.
