# Report 70A — Stores Consumable Unit / Roll Tracking

Date: 2 October 2026 (Africa/Nairobi). Implementation and verification in the local workspace. No deployment, Report 73, or W8 work.

**STORES CONSUMABLE UNIT TRACKING PARTIAL —
SPECIFIC SOFTWARE/POLICY GAPS REMAIN**

The reusable roll/unit receiving, progressive consumption, multi-unit issues, returns, offcuts, waste, counts, reconciliation and receipt-based valuation are implemented. Existing board and bulk workflows remain separate. Review-only physical count adjustments and audited repair of already-received unvalued units remain unresolved, as detailed below.

## 1. Git baseline

Backend: `master`, `8427fcb216e30d77e737a24ff3534088bb5daf08`.
Frontend: `master`, `10007e5d5de6704c9b84edf381eef14623a657e8`.
Both repositories were dirty. A partial 70A backend implementation was already present at this session's start, together with unrelated Finance 72B/72C work. This implementation extends that backend and supplies the missing Stores UI. Unrelated Finance work and the pre-existing deletion of a document lock file were preserved. The prior recorded baseline is [git-baseline.json](70a-verification/git-baseline.json); the final worktree inventory is [final-worktree-status.json](70a-verification/final-worktree-status.json). Reports 69 and 70 were not overwritten.

## 2. Tracking methods

The material master supports `consumable_unit` alongside existing `bulk_quantity`, `lot_batch`, `serialized_item` and `dimension_piece` controls. The canonical `tracking_method` projection reports `BULK`, `SPECIFIC_ITEM`, or `CONSUMABLE_UNIT`. Master configuration controls behavior; material names do not. Both full and quick-create material forms expose consumable-unit tracking. Boards retain their specific identification and measured-piece lifecycle.

## 3. Controlled-unit model

`consumable_units` stores identity, material, source receipt/log/reference, supplier, original and remaining quantity, UOM, unit cost, original and remaining value, controlled status, received/opened/depleted timestamps, parent identity, notes, actor and timestamps. Quantity uses `DECIMAL(20,6)`, unit cost `DECIMAL(20,8)`, value `DECIMAL(20,2)`. BCMath performs unit quantity/value calculations. Database checks enforce quantity bounds, nonnegative remaining value and valid depletion/status combinations. Model deletion is blocked to preserve identity and lineage.

Separate immutable `consumable_unit_movements` and `consumable_unit_counts` retain evidence. Original inventory logs are preserved; corrections add movements.

## 4. Unit ID logic

The database-generated ID supplies a global monotonic sequence: `CU-00000001`. A unique ULID temporarily identifies the insert inside its transaction, then the final code is assigned from the primary key. `unit_code` has a unique database constraint. There is no unlocked MAX+1 operation or material-name inference.

## 5. Receiving flow

Stores submits actual stock-UOM quantities for each physical unit through the existing movement endpoint. Their decimal sum must equal the received total. One receipt inventory log can create many units. For accepted GRN receipts the existing GRN line is locked, quantity and material are checked, the authoritative buying-unit receipt valuation is converted to stock UOM, and the same GRN is confirmed. Supplier comes from the original purchase order. No additional GRNs or bills are fabricated. Repeated confirmation is rejected.

Tests include five rolls, varied quantities (50/49.5/50), one GRN/log, supplier/source/cost preservation and duplicate GRN rejection. If measured quantities differ from the GRN's accepted stock total, the movement fails for review rather than silently changing the receipt.

## 6. Issue flow

Stores explicitly selects the physical unit, sees its remaining quantity, enters a decimal quantity and chooses the project/approved requirement and recipient. The existing movement desk directs controlled consumables to this workspace while carrying its project/GRN context. Material, stock and unit rows are locked in a database transaction. Held/depleted units, missing receipt cost, discrepancies, over-issues and reserved-stock violations are rejected in the backend.

The first issue sets `OPEN` and `opened_at`; depletion occurs at zero balance. A 50m roll at KSh 200/m issued by 12m retains 38m and KSh 7,600, with a KSh 2,400 movement.

## 7. Multi-roll flow

Every selected roll is a separate line in the existing atomic batch movement. Stores adds another roll explicitly. Failed later lines roll back the whole batch. Tests cover a 20m/50m issue and rollback when the second line over-issues. No implicit FIFO consumption occurs. The list suggests oldest usable open units before unopened units.

## 8. Return flow

The original issue determines the material, roll, project and approved requirement. Same-roll returns restore quantity and value, cannot exceed the unreturned issue, and cannot exceed the unit's original physical quantity. Partial return values follow the original issue; the final fragment restores any outstanding rounding cents. Returns emit the existing `StockReturned` event and negative ACTUAL cost.

The existing reversal service also restores a wholly unreturned issue and reverses unopened multi-unit receipts. Receipt reversals retain all unit identities and add per-unit history; opened/consumed receipts cannot be reversed as unopened receipts.

## 9. Offcut handling

A reusable offcut return creates a new `OPEN` child unit, retains its parent, supplier/source receipt lineage and original issue link, and leaves the parent's remaining physical roll quantity unchanged. The returned quantity increases the global material total. No minimum reusable size is invented: `POLICY REQUIRED` is shown.

## 10. Waste handling

The existing `damage`/`defective` movement deducts quantity from the selected unit under the same locks and records quantity, reason, actor, reference and value. A reason is required. No silent quantity reduction or separate sticker-specific service is used.

## 11. Physical count

Counts snapshot system quantity, observed quantity, variance, evidence, actor and time per unit. A variance remains `REQUIRES_REVIEW`; a matching observation is `RECONCILED`. The count does not overwrite quantity or valuation. Count evidence is immutable. Applying a reviewed count adjustment is not implemented pending the authority/workflow decision below.

## 12. Reconciliation control

Unit remaining quantities are authoritative for controlled materials. The existing global stock row is checked against their sum on every movement; mismatch blocks posting. Summary exposes `RECONCILED`/`DIFFERENCE`, the two totals and the difference; no automatic reconciliation or write-off occurs. Available stock excludes held units and the existing material reservation. Dashboard counts come from the backend for open units, holds, reconciliation differences and count variances. Existing configured material low-stock levels are reused; no arbitrary per-roll threshold is supplied.

## 13. Valuation method

Bulk materials continue with the existing receipt-supported moving weighted average. Boards continue with specific identification. Consumable units extend specific identification by allocating an authoritative receipt cost/value over their original stock quantity. Consumption and remaining values use decimal arithmetic; depletion consumes residual cents. There is no third independent Finance costing system.

Existing stock conversion uses receipt-supported MWA only when the existing valuation-readiness service classifies the stock as valued. Unknown receipt values remain null and consumption is blocked. Reconciliation differences classify valuation as `VALUATION_REQUIRES_REVIEW`; missing cost is `UNVALUED`; supported reconciled values are `VALUED`. Stores' existing valuation and readiness services expose the authoritative result to Finance. Legacy read interfaces retain their existing numeric presentation; unit transaction calculations remain decimal.

## 14. Project-cost integration

Roll issues dispatch `StockIssued` and use the existing `StoresCostProducer`, `InventoryLog` source identity and ACTUAL cost path. Controlled movements use the authoritative movement value, with no catalogue-price fallback. Returns use the existing proportional credit path and negative ACTUAL value. Tests verify KSh 2,400 ACTUAL, KSh -600 return, enquiry/project identity, and repeated producer calls returning the same cost line.

## 15. Single Economic Cost verification

The existing lifecycle remains PO approval → COMMITTED; GRN acceptance → ACCRUED; Stores issue → ACTUAL; Stores return → negative ACTUAL. PO-backed bill verification and payment do not create another project material ACTUAL. No bill/payment producer was changed by 70A. Roll producer idempotency and existing project material-chain tests passed. The separate SettlementAccount/SupplierLedgerRail regression result is recorded in the verification results below.

## 16. Existing-stock conversion approach

No historical bulk balance is split automatically. A controlled material with unmatched historical stock shows `CONTROLLED UNIT BREAKDOWN REQUIRED`. The explicit conversion operation requires the quantity of every physically verified unit, actor and evidence notes. Their sum must exactly equal the locked existing global balance; discrepancies are rejected. The operation creates opening identities/history without a new receipt or economic cost. Board, serial and lot stock cannot be converted through this tool. The UI exposes conversion to holders of `stores.adjust_quantity` only.

## 17–19. Board, bulk and Finance regression

The final affected suite includes all Stores feature tests, including BoardLifecycle, stock movements, GRN confirmation, reversals, permissions, opening inventory, stock ledger integrity and project requirements; StoresCostProducer and MaterialToCostChain; Finance InventoryFinance and StockCountPosting. These passed: **196 tests, 761 assertions**. The controlled-unit suite includes a second real database connection failing to acquire a locked unit and stale-balance rejection. This validates database exclusion and refreshed-balance controls; it is not a production load test.

## 20. Backend test result

**PASS: 196 tests / 761 assertions** in the final affected regression suite. [Backend evidence](70a-verification/backend-tests.log).

Command:

```sh
ddev exec env APP_CONFIG_CACHE=/tmp/70a-no-config.php DB_DATABASE=db_stores_review_test php -d error_reporting=22527 vendor/bin/phpunit -c phpunit.storesreview.xml tests/Feature/Stores tests/Feature/CostCollector/StoresCostProducerTest.php tests/Feature/CostCollector/MaterialToCostChainTest.php tests/Feature/Finance/InventoryFinanceTest.php tests/Feature/Finance/StockCountPostingTest.php
```

Verification incident: the initial invocation encountered cached application configuration pointing at the local default `db`, despite the PHPUnit test setting. The old guard ran after parent setup, so the local default database may have been refreshed before rejection. The guard now rejects a non-test database during application creation, before RefreshDatabase setup. All subsequent runs explicitly bypassed cached configuration and used `db_stores_review_test`. No production connection was used. No restoration of the local default database was attempted, because its prior state was not established. Two intermediate test runs overlapped on the disposable test database; their results were discarded, their container test processes terminated, and the final passing run was serial.

## 21. Frontend test result

**PASS: 70 tests across seven test files.** [Frontend evidence](70a-verification/frontend-tests.log). Coverage includes master tracking selection, material totals, unit history, multi-roll payloads, varied receiving quantities, offcut return payload, physical count evidence, discrepancies, permission hiding, loading/empty/error states and the existing Stores and Finance screen regressions.

## 22. API contract

**PASS: 0 newly unmatched endpoints; 0 currently unmatched literal endpoints in the checked scope.** The checker compares literal Stores and affected Finance calls against the current Artisan route export and Git HEAD call sites; it explicitly checks all six controlled-unit endpoints and the existing movement endpoint. Dynamic helpers retain their existing routes. [Contract evidence](70a-verification/api-contract.log). Checker: `scripts/check-stores-consumable-contract.py`.

## 23. Build

**PASS: direct `npx vite build`, 26.32 seconds.** Existing chunk-size/dependency-age warnings remain. [Build evidence](70a-verification/vite-build.log).

## 24. Visual verification

Actual Vue components rendered with an Axios fixture adapter and a disposable local headless Chrome profile. No live backend or production data was used by the fixture. Inspected material detail/selected roll issue, unit detail/history/count, explicit 20m+50m issue, a 3m offcut return, and reconciliation difference. Desktop: 1440px. Mobile: 390px, document width 390px, with local horizontal scrolling for wide tables.

Screenshots: [material/issue](70a-verification/material-detail-issue.png), [unit/count](70a-verification/unit-detail-count.png), [multi-roll](70a-verification/multi-roll.png), [return/offcut](70a-verification/return-offcut.png), [mobile](70a-verification/mobile-unit-return.png), [difference/mobile](70a-verification/reconciliation-difference-mobile.png). Fixture: `ERP-Frontend/tests/fixtures/70a/`. Browser script and output: [visual-check.cjs](70a-verification/visual-check.cjs), [visual.log](70a-verification/visual.log).

## 25. Remaining software gaps

- Unit-level count adjustment posting/approval resolution is not implemented. Counts are evidence/review records, not stock corrections. The generic adjustment path deliberately rejects controlled-unit adjustments without reviewed unit-level evidence.
- An audited valuation repair operation for units already received without a cost is not implemented. Those units remain visible and unissuable; do not change their cost directly in the database.
- Receipt and issue corrections use the existing ledger reversal endpoint; return/offcut and count corrections beyond those operations require the reviewed unit adjustment workflow above.

## 26. Remaining policy decisions

- Minimum reusable offcut size: **POLICY REQUIRED**. No threshold applied.
- Authority and workflow for approving manual count adjustments: **REQUIRES REVIEW / APPROVAL**. No authority invented.
- Per-roll low-remaining thresholds and mandatory FIFO have not been adopted. Existing material low-stock configuration is reused; suggested ordering remains optional.

## 27. Files changed for 70A

Backend new files: `ConsumableUnitController.php`; migration `2026_10_01_100000_create_consumable_unit_tracking.php`; models `ConsumableUnit.php`, `ConsumableUnitMovement.php`, `ConsumableUnitCount.php`; services `ConsumableUnitService.php`, `StoresDecimal.php`; `tests/Feature/Stores/ConsumableUnitTest.php`; `scripts/check-stores-consumable-contract.py`; this report and `70a-verification/` evidence.

Backend integration files: MaterialsLibrary `LibraryMaterial.php`, `LibraryMaterialResource.php`, `MaterialControl.php`; ProcurementStores `GoodsReceiptNoteController.php`, `GoodsReceiptInspectionController.php`, `ProcurementStoresController.php`, `InventoryLog.php`, `Stock.php`, `StockMovementRequest.php`, `Routes/api.php`, `InventoryService.php`, `InventoryValuationService.php`, `StockMovementPoster.php`, `StockMovementReversalService.php`, `StoresValuationReadinessService.php`; Finance `CostCollector/Services/StoresCostProducer.php`; `tests/Feature/CostCollector/StoresCostProducerTest.php`; early test database guard in `tests/TestCase.php`. Some of these 70A integrations were present before this session and were retained/extended.

Frontend: new `views/stores/ConsumableUnits.vue`, `consumableUnits.spec.ts`, and `tests/fixtures/70a/`; material forms `MaterialFormModal.vue`, `QuickCreateMaterialFields.vue`, `quickCreateMaterial.ts`; material/category types; Stores `useInventory.ts`, `materialControl.ts`, `navigation.ts`, `navigation.spec.ts`, `routes.ts`, `storesControlCentre.spec.ts`, `Dashboard.vue`, `OperationsDesk.vue`; `src/router/index.ts` permission-based controlled-unit route guard.

Unrelated pre-existing Finance modifications are excluded from this implementation's file list and remain in the working trees.

## 28. Commits

No commits created. Changes remain reviewable in the existing dirty working trees; unrelated work was not bundled into a commit.

## 29. Report path

`ERP-Backend/docs/finance-redesign/phase-2/70A_STORES_CONSUMABLE_UNIT_ROLL_TRACKING.md`.

## 30. Verdict and acceptance gate

**STORES CONSUMABLE UNIT TRACKING PARTIAL —
SPECIFIC SOFTWARE/POLICY GAPS REMAIN**

Implemented and verified: configured tracking; controlled identity; multi-unit/varied receiving; progressive and multi-roll issues; nonnegative balances; transaction/row locks; same-roll returns; offcut lineage; controlled waste; immutable counts/history; non-destructive variance; global reconciliation; receipt valuation/missing-cost gating; ACTUAL/negative ACTUAL; board/bulk/Finance compatibility; backend/frontend tests; API contract; direct Vite build; fixture visual verification; Report 70A.

Completion is not asserted while the software/policy items in sections 25–26 remain. No Report 73, W8, deployment or other next stream was started.

## Additional economic-cost regression result

**PASS: 27 tests / 107 assertions**, run serially after the affected suite. These cover receipt liability/inventory, issue/return WIP, PO-backed bill verification, accrual retirement, idempotency and supplier payment. The existing full material cycle touches project WIP exactly once. [Economic-cost evidence](70a-verification/economic-cost-tests.log). Together with the main suite: **223 backend tests / 868 assertions**, all passing.
