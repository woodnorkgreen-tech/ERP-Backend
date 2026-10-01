# Report 69: Stores Backend Stabilization & Control Foundation

## 1. Executive summary
Stores now preserves immutable movement history, serializes board fulfilment, authorizes control actions through permissions, and exposes read-only control projections.

## 2. Final verdict
**IMPLEMENTATION COMPLETE — DEPLOYMENT REMAINS SUBJECT TO DATA-READINESS AND PRODUCTION MIGRATION GATES.**

## 3. Scope
Stores and its Procurement, Projects, and Finance integration points.

## 4. Non-scope
The full Control Centre visual redesign is deferred.

## 5. Inventory model
One global balance per material is retained.

## 6. Warehouse meaning
Warehouse and bin fields remain descriptive metadata.

## 7. Transfers
No inter-store transfer ledger was introduced.

## 8. Valuation method
Moving Weighted Average is retained.

## 9. Board method
Boards remain specifically identified items.

## 10. Cost chain
Procurement receipt evidence feeds Stores; project issues feed Finance actuals.

## 11. Gate A
Hard ledger deletion is retired.

## 12. Legacy DELETE
DELETE /inventory-logs/{id} returns 405 without mutation.

## 13. Reversal endpoint
POST /inventory-logs/{id}/reverse requires a reason.

## 14. Reversal authorization
stores.movement.reverse or stores.manage is required.

## 15. Locking
The movement and stock row are locked in one transaction.

## 16. Linkage
reversal_of_log_id is unique; issue reversals retain original_issue_log_id.

## 17. Duplicate protection
Service checks and a unique constraint prevent a second reversal.

## 18. Reversal-of-reversal
A reversal cannot itself be reversed.

## 19. Receipt floor
Receipt reversal cannot reduce on-hand below reserved stock.

## 20. Issue returns
An issue already returned in whole or part cannot be reversed.

## 21. Board receipts
Every board in a reversed receipt batch must still be Available.

## 22. Board issues
Linked boards must still be Allocated.

## 23. Board audit
Reversal status changes append BoardMovement records.

## 24. Project cost
Project issue reversal posts a negative stock-issue-reversal CostLine.

## 25. Finance idempotency
The reversal movement ID is the Finance source identity.

## 26. GRN rollback
A reversed receipt returns its GRN item to awaiting_stores_details.

## 27. Actor
The reversal log records the authenticated actor.

## 28. Reason
The mandatory reason is retained in movement notes.

## 29. Frontend action
The dashboard calls reversal rather than DELETE.

## 30. Frontend reason gate
At least ten reason characters are required.

## 31. Gate B
Board fulfilment is atomic.

## 32. Request lock
BoardRequest uses lockForUpdate.

## 33. Board lock
Selected or FIFO boards are locked.

## 34. Outstanding cap
Issue quantity cannot exceed the request remainder.

## 35. Valuation gate
Every selected board requires a positive value.

## 36. Reservation
Reservation release occurs under the Stock lock.

## 37. On-hand
Stock deduction is in the same transaction.

## 38. Movement
The issue InventoryLog is created before commit.

## 39. Board links
Boards link to issue, request, project, and material plan.

## 40. Replay protection
Only pending or partial requests can be fulfilled.

## 41. Gate C
Identified Stores role strings are replaced by capabilities.

## 42. Board permission
stores.board.manage governs board controls.

## 43. Inspection permission
stores.receipt.inspect governs inspections.

## 44. Reversal permission
stores.movement.reverse governs reversal.

## 45. Catalogue
All permissions are registered centrally.

## 46. Role matrix
Permissions are represented in the role matrix and migration.

## 47. Phantom roles
Finance Manager and Accountant checks were removed from BoardController.

## 48. Receiving queue
The queue uses stores.view or stores.manage.

## 49. Inspection controller
Inspection uses the granular permission with management compatibility.

## 50. Gate D
Valuation readiness never invents prices.

## 51. VALUED
A positive average must have complete priced receipt evidence.

## 52. UNVALUED
No positive moving average means UNVALUED.

## 53. REVIEW
Incomplete or contradictory evidence means VALUATION_REQUIRES_REVIEW.

## 54. Total value
Only VALUED stock contributes to the authoritative total.

## 55. Audit evidence
Receipt counts, stored average, and classification reason are exposed.

## 56. Valuation route
GET /valuation-readiness is read-only and protected.

## 57. Ledger route
GET /ledger-reconciliation compares stored stock with signed ledger sums.

## 58. Reconciliation result
Each material reports exact difference and status.

## 59. Action queue
GET /action-queue reports real GRN, inspection, count, and Finance work.

## 60. Projection side effects
Control projections do not mutate operational or financial records.

## 61. Adjustment safety
Count changes post adjustment movements; metadata changes do not.

## 62. Opening inventory
Existing controls prevent a second approved opening baseline.

## 63. Gate G
Stores reset is forbidden when app()->isProduction() is true.

## 64. Verification
The implementation gates have been exercised: backend syntax and routes passed; the focused Stores and costing suite passed 153 tests with 614 assertions; the expanded reversal suites passed 19 tests with 78 assertions; the protected Finance regression suite passed 95 tests with 685 assertions; and the focused frontend suite passed 36 tests. The final accounting-reversal check passed 1 test with 10 assertions and proves both the original actual and its matching negative reversal have journal entries. The final production frontend build passed after correcting the two reactive quick-create bindings; only existing bundle-size and mixed-import warnings remain.

## 65. Technical boundary
Controlled lot and serial reversals require focused instance-ledger tests before operational enablement.

## 66. Decision register
Accounting must decide non-project consumption GL treatment. Leadership must decide whether manual adjustments need dual approval. Neither policy is invented here.

## 67. Release recommendation
Deploy only after the Report 69 migrations are backed up and rehearsed, the target database readiness projections are reviewed, and production reset is verified to return 403. No deployment or production mutation was performed during this close-out.

## 68. Read-only rehearsal evidence
The 2026-10-01 rehearsal ran against the local DDEV database named `db`, not production. Projection execution completed without mutation. It reported 22 stocked materials: 0 VALUED, 18 UNVALUED, and 4 VALUATION_REQUIRES_REVIEW; authoritative value therefore remained zero. Ledger reconciliation covered 33 material identities and identified 29 discrepancies. The action queue reported zero pending GRN confirmations, inspections, submitted counts, or Finance sync exceptions.

These are data-remediation findings, not projection failures. The local dataset must not be treated as authoritative inventory value until receipt-cost evidence and ledger balances are reconciled. The same read-only rehearsal must be repeated against the deployment target before release.

## 69. Known baseline debt
Repository-wide frontend type-checking remains red because of pre-existing errors across unrelated modules and some older Stores typing debt. Scoped unit tests pass. Scoped ESLint still reports pre-existing `any`, component-name, and unused-expression violations in Dashboard and ResolveMaterialModal; the Report 69 edits introduced no new lint category. PHP 8.4 also emits deprecation notices from the installed Guzzle promises dependency.

## 70. Completion boundary
Report 69 implementation and development verification are complete. Production release is not complete: target-database rehearsal, migration execution, production authorization checks, and the two policy decisions in section 66 remain operational gates.
