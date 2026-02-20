# WNG Production Module - Phase 1 Scope Freeze

Last updated: 2026-02-19
Owner: Production Module Team
Status: Active baseline for implementation

## 1. Objective

Deliver a stable Phase 1 production execution and quality control layer aligned to:

- WNG Product Quality Standards Manual (Operational V1)
- WNG ERP Production Module PRD (Phase 1 scope only)

This document prevents scope creep and defines what will and will not be built now.

## 2. Source-of-Truth Boundaries (Non-negotiable)

Production module must not manually own master records that belong elsewhere.

- Projects/Sales own: client, project, enquiry, approved scope, baseline job value/margin.
- Inventory/Stores own: material master, stock, issues/returns/reservations.
- HR own: employee/technician identity, department, employment status.
- Finance own: posted costs, margin reporting, approval authority matrix records.

Production stores only:

- Execution records (tasks, QC checks, rework, NCR/CAPA when added, evidence, gate approvals).
- Foreign keys to source modules.
- Audit snapshots only when needed for compliance history.

## 3. Phase 1 In-Scope

### 3.1 Stability and data consistency

- Fix schema/controller mismatches that break normal workflows.
- Ensure workflow steps and gate transitions are enforceable.

### 3.2 Execution control

- Work order tasking by workstation.
- Assignee tracking.
- Status transitions and reason capture for pauses/failures.
- Evidence upload for tasks/rework.

### 3.3 Quality control foundation

- Mid-QC and Final-QC structured checks.
- Failed QC -> rework creation.
- Rework lifecycle tracking and re-QC status.

### 3.4 SOP-critical control layer (Phase 1 level)

- NCR entity and lifecycle.
- Root-cause coded taxonomy (not free-text-only).
- Reinspection before closure.
- Gate blocking rules for failed/pending QC.
- Mandatory minimum QC evidence rules.

### 3.5 Phase 1 reporting

- QC failure rate.
- NCR frequency and aging.
- Repeat defect trend.
- Rework cost % (as data allows in Phase 1).
- Material wastage trend.

## 4. Explicitly Out of Scope (for now)

- AI forecasting and predictive scheduling.
- Advanced capacity simulations and what-if optimizer.
- IoT machine integrations.
- External client portal features.
- Autonomous recommendations (Phase 3 style intelligence).

## 5. Phase 1 Execution Sequence

1. Stability fixes (schema/status consistency).  
Status: In progress/completing.

2. Scope freeze + acceptance baseline.  
Status: Completed with this document.

3. Defect taxonomy tables.

4. NCR core tables and APIs.

5. QC failure auto-NCR wiring.

6. Reinspection + NCR closure enforcement.

7. Tight QC gate blocking logic.

8. Mandatory QC evidence count validation.

9. Reason-code enforcement for scrap/rework delays.

10. Dispatch readiness checklist and block rules.

11. Installation completion and sign-off capture.

12. KPI/report endpoints for management.

13. Audit and permission hardening.

## 6. Phase 1 Done Criteria

Phase 1 is considered complete only if all are true:

- Jobs cannot move forward when QC/NCR gate rules are violated.
- Defects are coded and trendable (repeat analysis possible).
- NCR closure is controlled and auditable.
- Managers can view defect/rework/wastage trends in reporting.
- No duplicate manual entry of project/client master data in Production.

## 7. Change Control

Any requested feature outside this scope must be tagged as:

- `Phase 2 candidate`, or
- `Phase 3 candidate`

and must not be merged into Phase 1 delivery unless explicitly approved.
