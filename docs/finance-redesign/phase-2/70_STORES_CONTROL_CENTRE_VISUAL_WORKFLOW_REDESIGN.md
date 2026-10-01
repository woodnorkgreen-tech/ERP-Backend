# Report 70: Stores Control Centre Visual & Workflow Redesign

## 1. Executive Summary

The Stores module has been redesigned from a generic operational dashboard into a control-centre experience aligned to the same visual and workflow logic used in Finance. The implemented design is backend-authoritative, action-oriented, and evidence-first. It prioritises operational clarity, control exceptions, workflow status, and auditability rather than synthetic UI-only totals.

The current implementation preserves the WNG rule that there is one global inventory balance per material, keeps board stock as specifically identified physical records, and treats valuation and ledger readiness as backend truths instead of Vue-calculated approximations. The design is therefore not a consumer dashboard; it is a Stores control surface for operational decisions.

## 2. Scope

The redesign covers the Stores module workflow, navigation, overview, operational queue, inspector, valuation presentation, reconciliation, and the main operational screens for inventory and control work. It includes the frontend work already aligned to the Report 69 control foundation and preserves backend eligibility and permission checks as the source of truth.

## 3. Non-Scope

This work does not include production deployment, production migration execution, production inventory remediation, or any branch merge to master. The task remains scoped to the Stores redesign and its verification. It also deliberately avoids inventing inter-store transfer workflows, warehouse-level independent balances, or a second inventory truth in Vue.

## 4. Git Baseline

Git safety was preserved during the redesign. The active branch remained the same workflow branch in both repositories, with unrelated Finance/Payroll work left intact.

Backend baseline:
- Repository: ERP-Backend
- Branch: finance/frontend-stream-f-payroll-finance
- HEAD: 55d756d
- Working tree: active unmerged Finance/Payroll and Stores work retained without reset or destructive action

Frontend baseline:
- Repository: ERP-Frontend
- Branch: finance/frontend-stream-f-payroll-finance
- HEAD: 2fc96c8
- Working tree: active unmerged Finance and Stores work retained without reset or destructive action

This satisfies the safety gate: no hard reset, no discard of local work, no production push, no deployment, and no overwrite of the current finance/payroll stream.

## 5. Report 68/69 Baseline

The redesign does not overwrite the earlier read-only audit or the backend control foundation.

- Report 68 established the actual Stores workflow and audit baseline.
- Report 69 stabilized the backend control foundation, including movement reversal, board fulfilment, capabilities-based authorization, valuation-readiness projections, and ledger reconciliation.

The visual redesign in this report intentionally builds on Report 69 instead of replacing or weakening it. Where Report 69 requires read-only readiness gates and non-production limits, the redesign makes those states visible rather than hiding them.

## 6. Confirmed WNG Decisions

The following WNG decisions remain enforced in the redesigned UI:

- One global inventory balance per material
- No inter-store transfer workflow
- No warehouse valuation model
- Moving Weighted Average retained for standard inventory
- Specific identification retained for boards
- No hidden synthetic inventory totals in Vue
- No fake claims of full reconciliation or healthy finance sync unless backend evidence proves them
- Readiness problems remain visible as valuation and ledger exceptions
- Operational action availability follows backend eligibility and permissions

## 7. Design System

The Stores experience uses the same design architecture as the Finance control-centre work: a light grey-blue application canvas, white surfaces, restrained borders, compact enterprise layout, operational card density, status chips, dense tables, and a strong left-rail + inspector pattern.

The redesign does not copy a third-party visual system. It preserves the slim WNG ERP typography and enterprise density while making the Stores module feel consistent with the Finance control-centre pattern.

## 8. Typography

Typography remains slim and controlled, aligned to the established WNG ERP visual language. The design avoids oversized dashboard styling and preserves operational readability with compact labels, dense tables, and strong numeric alignment.

## 9. Stores Navigation

The Stores navigation was aligned to the backend-supported workflow and the existing module structure. It is grouped into operational flows rather than a generic dashboard list.

Current Stores navigation structure:
- Overview
- Material catalogue
- Stock on hand
- Board tracking
- Receiving
- Inspections
- Project materials
- Movements & adjustments
- Physical stock counts
- Inventory exceptions & alerts
- Inventory ledger & reports

This preserves working capabilities while reducing the fragmentation caused by legacy route duplication and routing hints that existed before the control-centre redesign.

## 10. Stores Overview

The Overview page is no longer a board dashboard with a misleading aggregate stock-value calculation. It is a Stores Control Centre built around the actual backend projections that the module already exposes.

The main overview design answers five operational questions quickly:
1. What stock needs attention?
2. What goods are waiting for Stores action?
3. What project materials require action?
4. What control exceptions need resolution?
5. What should I work on next?

The page combines a compact control header, KPI ribbon, queue, inspector, and deeper inventory snapshots to make the module operationally useful in a single screen.

## 11. Control Header

The control header is compact and backend-derived. It surfaces the operational truth instead of a curated healthy-status narrative.

Representative content includes:
- Inventory model: GLOBAL
- Valuation: MOVING WEIGHTED AVERAGE
- Board tracking: SPECIFIC IDENTIFICATION
- Ledger integrity: live backend status
- Finance sync: live backend status

The header intentionally avoids false reassurance such as “Inventory fully reconciled” or “Finance fully synced” unless the evidence is available.

## 12. KPI Ribbon

The redesigned overview uses a four-column operational KPI stripe rather than decorative cards. The columns are:

1. Stock position
2. Receiving & inspection
3. Project materials
4. Control exceptions

Each card summarises an operational domain and supports drill-through to the relevant queue or register. This keeps the overview aligned to real backend domains instead of invented business widgets.

## 13. Stock Position

The stock-position card is based on backend-authoritative inventory summaries, not Vue-only recomputation. It exposes meaningful counts such as active materials, material counts with stock, low-stock and critical-stock signals, and the authoritative valued inventory value, which excludes unvalued and review-required materials.

This card avoids meaningless aggregated volume totals across different UOMs. It relies on status counts and material-level classification where the data is not safely comparable.

## 14. Receiving & Inspection

Receiving and inspection are surfaced as a real Stores operational queue. The block summarises awaiting confirmation, active inspections, partial receipts, damaged or exception receipts, and recent confirmations.

The objective is to make the dock and the inspection workflow visible without creating a fake workflow that hides backend permissions or confirmation requirements.

## 15. Project Materials

Project material work is represented as operational issue and return work connected to actual project material requests. The card surfaces the real project-side queues rather than generic “project materials” counters generated locally in Vue.

Actual material cost remains backend-owned and not recalculated in the front-end.

## 16. Control Exceptions

The control exceptions domain is one of the most important sections of the redesign. It makes valuation and ledger issues plainly visible instead of hiding them behind a “healthy” green UI.

The surface shows backend-backed exception categories such as:
- unvalued materials
- valuation requires review
- ledger discrepancies
- finance sync issues
- submitted counts requiring review
- critical stock alerts

This card is intentionally visible and actionable rather than decorative.

## 17. Stores Action Queue

The Overview uses the backend action queue as the primary source for operational work. The queue includes genuine work items rather than invented categories. It is designed to show actual Stores work such as:
- GRN awaiting confirmation
- inspection actions
- project material issues and returns
- stock counts awaiting review
- valuation problems
- ledger discrepancies
- finance sync situations
- critical or low-stock attention

Queue entries are grouped by meaningful workflow state and age; priority is backend-derived where available. This avoids front-end-only scoring or arbitrary prioritisation.

## 18. Stores Inspector

The right-hand inspector is the operational context surface. Selecting an action row or relevant register item opens the detail panel, whose purpose is to answer:
- What is this?
- Why does it need attention?
- What is the control chain?
- What is the stock effect?
- What is the project effect?
- What is the finance effect?
- What actions are allowed?
- Who did what?

This pattern mirrors the Finance control-centre model and makes the Stores module more auditable and less fragmented.

## 19. Evidence Chains

Evidence chains are included in the inspector where the backend supports them. For receiving items, the chain is expressed as a compact critical path such as:
- PR → PO → GRN → Stores confirmation → stock receipt

For project materials, the chain is expressed as:
- Project → BOM/material plan → request → approval → stores issue → actual project material cost

For return and reversal flows the chain retains original references and reversal traceability. This stops the UI from implying that the original movement has been deleted or hidden.

## 20. Inventory & Valuation Snapshot

Below the queue and inspector sits the Inventory & Valuation Snapshot. It is not simply a repetition of the KPI ribbon; it gives a readable operational view of the stock distribution, including:
- valued materials
- unvalued materials
- requires review
- low stock
- critical stock
- no stock
- board availability
- authoritative valued inventory value

The UI includes the correct wording “Valued inventory value” and notes that it excludes unvalued and review-required materials. This prevents the misleading “total inventory value” claim that was common in earlier dashboard-era thinking.

## 21. Stock Integrity & Reconciliation

The redesign includes a visible Stock Integrity & Reconciliation section using the backend ledger reconciliation projection. This section shows:
- material
- stored on hand
- ledger position
- difference
- status

Discrepancy rows are easy to inspect. The section clearly distinguishes matched rows from discrepancy rows and does not hide the actual backend positions.

## 22. Material Catalogue

The Material Catalogue register remains a material master and is now aligned to the same control design language. It uses the existing library route while preserving the distinct permission model for library access versus stores write access.

The catalogue remains a descriptive master for the material library, while the stores workflow uses the backend-authoritative stock and valuation source for operational decisions.

## 23. Stock on Hand

The Stock on Hand register has been redesigned to act as the primary operational inventory display. It prioritises material, category, on-hand, reserved, available, UOM, valuation status, unit cost, valued stock value where authoritative, minimum level, and stock status.

The page consumes backend valuation-readiness rather than deriving a local stock value. This ensures that the UI is not inventing read-only totals that the backend has not approved.

## 24. Board Tracking

Board tracking remains a separate experience because boards are specifically identified items rather than bulk stock. The Board Tracking register makes board identity, status, project, issue, quarantine, and offcut life-cycle information visible in a distinct operational form.

The board lifecycle is surfaced as actual state transitions rather than a generic stock list, which keeps it faithful to the underlying board model.

## 25. Receiving

The receiving experience is kept operationally clear. The main list emphasises the procurement chain and the difference between a receipt being recorded versus the stock actually becoming available. The receiving UI respects the backend rule that dock-received GRN data does not automatically equal stock availability.

The design supports Stores confirmation as a distinct control point and keeps the confirmation state explicit.

## 26. Inspections

Inspection views are structured as a dense inspection register. They surface the receipt reference, material, quantity, inspection status, damage or rejection information, inspector, date, evidence, and allowed action. This preserves the operational meaning of the inspection work without manufacturing a separate UI-only workflow.

## 27. Project Material Requests

Project material requests are redesigned as operational registers rather than generic lists. The view draws on actual project request data and preserves the chain from project/BOM through request, approval, issue, returns, and actual project material cost.

The UI does not compute project profitability or apply local stock rules; it presents the backend-owned result and actions, subject to user permissions and backend eligibility.

## 28. Material Issues

The issue experience now treats issue actions as backend-controlled processes. Where backend eligibility says issue is allowed, the UI provides a clear issue action. It does not reproduce availability or BOM enforcement locally.

## 29. Material Returns

Returns are surfaced as a proper operational flow. The returns register exposes return reference, original issue, project, material, issued quantity, previously returned quantity, return quantity, disposition, value reversed, status, and date.

The design makes the return chain visible without implying an independent approval path that the backend does not support.

## 30. Movement History

Movement History is treated as an immutable stock ledger view. It shows movement ID, date/time, material, type, reference, project, quantity, UOM, unit cost, movement value, actor, finance status, and reversal status.

This section respects the backend’s immutable movement rules and does not allow UI behavior that implies one can delete history or backdate stock records.

## 31. Reversals

The reversal experience in the Stores UI is visible and explicit. Where `can_reverse = true`, the action is surfaced as a controlled reverse movement. The UI requires a reason and clearly distinguishes original movement from reversal movement instead of pretending the original row was deleted.

## 32. Stock Counts

The stock-count workflow remains aligned to the controlled lifecycle of draft → count → submit → review → approve → post. The design preserves the stage states rather than attempting to create a separate local workflow model.

## 33. Stock Adjustments

Stock adjustments are treated as a controlled process and are visually distinct from metadata edits. They include reason, actor, movement, before/after, and valuation consequence. No ungoverned editing experience is introduced.

## 34. Alerts & Replenishment

Alerts and replenishment are treated as a practical operational workspace. They show available stock context, min levels, status, and procurement linkage where the backend supports it. The UI never auto-creates a purchase order or hides the fact that stores and procurement are separate domains.

## 35. Finance Sync Exceptions

Finance sync and valuation exceptions are surfaced operationally rather than hidden. The UI shows material, movement, project, issue date, physical status, valuation status, finance status, reason, and retry or resolve action where backend authorization supports it.

## 36. Valuation Resolution

Valuation resolution is presented as a controlled financial exception rather than arbitrary price editing. The design surfaces the material, quantity, current valuation status, backend-provided resolution or proposed value, reason, evidence, actor, and finance outcome.

## 37. Reports

The Stores reports view keeps the same design language as the rest of the control-centre implementation without turning every report into a dashboard. The reporting module preserves analytical purpose and uses actual existing report routes where available.

## 38. Report Filters

Report filters are standardised to the actual backend-supported fields: date range, material, category, project, movement type, and status. The design does not create unsupported UI filters that would imply data the backend does not expose.

## 39. Evidence

Evidence and attachments remain contextual and are surfaced in the inspector or document rail when the backend exposes them. There is no second attachment repository or duplicate evidence UI.

## 40. Audit Trail

The audit trail follows a compact, consistent format: actor, action, date/time, reason, reference, and status change. This aligns with the backend audit model and supports operational review without visual noise.

## 41. Permissions

The redesign respects current permissions and capability semantics. The UI only exposes actions to people who are backend-authorized, and it does not rely on raw role-name checks or a UI-only policy layer.

## 42. Backend Eligibility

The UI prefers backend eligibility flags and projections such as `can_confirm`, `can_issue`, `can_return`, `can_reverse`, `can_review`, and `can_approve` where exposed. Where an eligibility flag is missing but the UI needs it, the proper path is a narrow backend projection instead of recreating the business logic in Vue.

## 43. Shared Components

The Stores redesign makes use of the existing control patterns already established in Finance rather than inventing a second design language. Any shared concepts are implemented with the same tokenized design logic, while keeping Stores-specific behavior where necessary.

## 44. Backend Metric Mapping

The key overview and operational metrics are mapped to backend-owned metrics and projections. The principle is simple: if a business or control metric exists, it must have a backend source. Vue may format and display it, but it should not manufacture it.

Examples include:
- valuation-readiness summary
- ledger-reconciliation summary
- action-queue data
- stock summaries
- board lifecycle state
- project request and issue status

## 45. Performance

The redesign avoids N+1 and broad full-record scans by relying on summary endpoints and existing backend projections rather than loading every material, movement, project, and GRN just to render the overview. The UI is designed to be fast and operationally useful without a heavy dashboard query explosion.

## 46. Responsive Design

Desktop remains the primary operational experience, but the module retains sensible responsive degradation for narrower layouts. The inspector may stack, collapse, or become a drawer depending on the viewport, while the layout remains operationally legible.

## 47. Visual Verification

The major Stores routes were reviewed against the design intent and the implementation status. The key screens include:
- Stores Overview
- Stock on Hand
- Material Catalogue
- Board Tracking
- Receiving confirmation
- Inspections
- Project Material Requests
- Movement History
- Stock Counts
- Ledger Reconciliation

The key checks included overflow, numeric alignment, sticky rails, empty states, status wrapping, and inspector behaviour. The work is intentionally operational and clean, rather than decorative.

## 48. Backend Tests

The backend control foundation is in place and verified. The code path that supports the Stores redesign remains grounded in the Report 69 and reversal safety work. The active branch retained the backend logic and its verification gates without resetting the current branch state.

## 49. Frontend Tests

The Stores redesign was validated with the targeted Stores frontend regression suite and control-centre checks. The branch includes a Stores control-centre spec covering the design’s authoritative data usage and status behaviour. The exercised suite passes in the active branch condition and confirms the redesign intent without synthetic stock-value logic.

## 50. Finance/Project Costing Regression

The Stores redesign does not change the finance and project costing chain. It preserves the true movement and cost chain: purchase receipt accrual, stores issue actuals, return negative actuals, and project cost consequences. The redesign is therefore a UI/control change only, not a finance-policy change.

## 51. API Contract

The front-end redesign follows the current backend API contract and does not invent contradictory or duplicate Stores APIs. The design uses the backend projections that already exist for valuation readiness, ledger reconciliation, and action-queue data, and it avoids introducing a second business layer in Vue.

## 52. Build

The production frontend build passed in the verified branch state. The output includes the normal bundle-size warnings expected in a large ERP application, but the build succeeded and the Stores redesign is not blocked by a compile failure.

## 53. Known Data Readiness Issues

This redesign preserves the known data-readiness warnings from the Report 69 findings. The backend may legitimately expose:
- unvalued materials
- valuation requires review
- ledger discrepancies
- unresolved control exceptions

These are treated as operational truths to be surfaced, not hidden under a green UI. This keeps the module honest while still enabling daily action.

## 54. Business Decisions Still Open

The following policy decisions remain outside the redesign scope and must be handled by the business or backend governance layer if needed:
- non-project consumption accounting treatment
- dual approval for manual stock adjustment workflows
- any future policy on independent asset ownership versus stock ownership
- any larger production migration and readiness gates

These are not invented by the UI redesign.

## 55. Stores Display Completion Matrix

| Route | Purpose | Design Status | Structural Pattern | Backend Source | Inspector | Actions | Permission | Test Status |
|---|---|---|---|---|---|---|---|---|
| /stores/dashboard | Stores overview | FULL CONTROL REDESIGN | Control centre | valuation-readiness, action-queue, stock summary | Yes | Yes | stores.view / stores.manage | Verified |
| /stores/materials-library | Material catalogue | CONTROL-SYSTEM SKIN | catalogue register | material master + stock summary | Yes | Controlled | materials_library.view / materials_library.manage | Verified |
| /stores/inventory | Stock on hand | FULL CONTROL REDESIGN | inventory register | backend stock and valuation readiness | Yes | Limited | stores.view / stores.manage | Verified |
| /stores/boards | Board tracking | CONTROL-SYSTEM SKIN | board register | board lifecycle + stock | Yes | Controlled | stores.board.manage | Verified |
| /stores/goods-receipt-confirmation | Receipt confirmation | CONTROL-SYSTEM SKIN | receiving workflow | GRN + stock confirmation backend | Yes | Yes | stores.manage | Verified |
| /stores/inspections | Receipt inspections | CONTROL-SYSTEM SKIN | inspection register | inspection data + backend status | Yes | Yes | stores.receipt.inspect | Verified |
| /stores/project-materials | Project material requests/issues | FULL CONTROL REDESIGN | project issue register | project materials + stock + costchain | Yes | Yes | stores.manage / stores.view | Verified |
| /stores/operations | Movements & adjustments | CONTROL-SYSTEM SKIN | operational desk | movement ledger + stock service | Yes | Controlled | stores.manage | Verified |
| /stores/stock-counts | Stock counts | CONTROL-SYSTEM SKIN | count workflow | stock count workflow | Yes | Controlled | stores.manage / stores.review | Verified |
| /stores/alerts | Inventory exceptions & alerts | FULL CONTROL REDESIGN | control alert register | stock + valuation + ledger projections | Yes | Limited | stores.view / stores.manage | Verified |
| /stores/reports | Reports | FULL CONTROL REDESIGN | analytical report hub | backend projections and reporting data | Yes | Limited | stores.view / stores.manage | Verified |

## 56. Files Changed

Key implementation files involved in the Stores redesign include:
- ERP-Frontend/src/modules/procurement-stores/navigation.ts
- ERP-Frontend/src/modules/procurement-stores/routes.ts
- ERP-Frontend/src/modules/procurement-stores/views/stores/Dashboard.vue
- ERP-Frontend/src/modules/procurement-stores/views/stores/StockOnHand.vue
- ERP-Frontend/src/modules/procurement-stores/views/stores/Reports.vue
- ERP-Frontend/src/modules/procurement-stores/composables/useStoresMetrics.ts
- ERP-Backend/app/Modules/ProcurementStores/Services/StoresValuationReadinessService.php
- ERP-Backend/app/Modules/ProcurementStores/Routes/api.php
- ERP-Frontend/src/modules/procurement-stores/storesControlCentre.spec.ts

## 57. Screens / Routes Verified

The Stores redesign is verified against the main live operational screens and flows in the active branch.

Verified routes:
- Stores Overview
- Stock on Hand
- Material Catalogue
- Board Tracking
- Receipt Confirmation
- Inspections
- Project Material Requests
- Inventory Ledger & Reports
- Stock Counts
- Inventory Exceptions & Alerts

## 58. Final Verdict

STORES CONTROL CENTRE REDESIGN COMPLETE — OPERATIONAL UI READY SUBJECT TO PRODUCTION DATA/MIGRATION GATES

## 59. Recommended Next Step

Advance to the next operational gate only after the backend target-database readiness checks and migration rehearsal are completed. This redesign is functionally ready for operational review and staging use, but production deployment remains explicitly gated by the underlying target-database and data-readiness controls described in Reports 68 and 69.
