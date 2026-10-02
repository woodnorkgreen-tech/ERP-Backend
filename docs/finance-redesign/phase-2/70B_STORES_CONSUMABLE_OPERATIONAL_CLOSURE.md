# Report 70B — Consumable Unit Operational Closure
**WNG ERP · Stores Module**
**Date:** 2026-10-02
**Scope:** Material Catalogue → Roll Identity → Count Adjustment → Valuation Repair → Frontend/Backend Sync

---

## 1. Purpose

Report 70A established the Consumable Unit Tracking core but left operational gaps.
Report 70B closes those gaps and proves frontend/backend completeness across the full
22-capability baseline audited before this work began.

No existing behaviour was redesigned. Every capability listed in 70A is preserved.

---

## 2. Baseline Audit — 22 Capabilities

| # | Capability | 70A Status | 70B Action | Final Status |
|---|---|---|---|---|
| 1 | Material Catalogue — tracking-method selection | ✅ Complete | Preserve | ✅ |
| 2 | `CONSUMABLE_UNIT` projection from `tracking_mode` | ✅ Complete | Preserve | ✅ |
| 3 | Controlled-unit records (ConsumableUnit model) | ✅ Complete | Preserve | ✅ |
| 4 | Unique internal CU identity (tracking_code) | ✅ Complete | Preserve | ✅ |
| 5 | Multi-unit receiving (controlled_units array) | ✅ Complete | Preserve | ✅ |
| 6 | Varied roll quantities per receive | ✅ Complete | Preserve | ✅ |
| 7 | Progressive consumption (running quantity/value) | ✅ Complete | Preserve | ✅ |
| 8 | Multi-roll issues | ✅ Complete | Preserve | ✅ |
| 9 | Same-roll returns | ✅ Complete | Preserve | ✅ |
| 10 | Offcuts (partial return with remainder) | ✅ Complete | Preserve | ✅ |
| 11 | Waste / damage recording | ✅ Complete | Preserve | ✅ |
| 12 | Physical counts | ✅ Complete | Preserve | ✅ |
| 13 | Reconciliation gate | ✅ Complete | Preserve | ✅ |
| 14 | Receipt-based valuation (unit_cost from receipt log) | ✅ Complete | Preserve | ✅ |
| 15 | Project ACTUAL integration | ✅ Complete | Preserve | ✅ |
| 16 | **Count adjustment (APPROVED / REJECTED review)** | ⚠️ Partial | **Implemented** | ✅ |
| 17 | Negative ACTUAL returns | ✅ Complete | Preserve | ✅ |
| 18 | **Valuation repair (un-valued unit recovery)** | ⚠️ Partial | **Implemented** | ✅ |
| 19 | Board-specific tracking compatibility | ✅ Complete | Preserve | ✅ |
| 20 | Bulk inventory compatibility | ✅ Complete | Preserve | ✅ |
| 21 | Finance inventory compatibility | ✅ Complete | Preserve | ✅ |
| 22 | **Frontend/backend sync (resolve modal, desk routing)** | ⚠️ Partial | **Implemented** | ✅ |

---

## 3. What Was Built

### 3.1 Count Adjustment — Row 16

**Problem:** Physical count variances had no formal review workflow. Any storekeeper
could adjust their own count without a second actor.

**Solution:**

- `ConsumableUnitCountReview` model — immutable audit record (DomainException on update/delete).
- `consumable_unit_count_reviews` table — migration `2026_10_02_100000_*`.
- `ConsumableUnitController::review()` — POST `/consumable-units/{unit}/counts/{count}/review`.
  - Requires `stores.review` permission.
  - Blocks self-approval (reviewer ID ≠ count actor ID).
  - Posts immutable `count_adjustment` inventory log with BCMath valuation.
  - Marks count status `REQUIRES_REVIEW` → `APPROVED` or `REJECTED`.
  - On APPROVED: updates unit quantity/value, clears reconciliation difference.
  - On REJECTED: count is preserved as-is; reason is recorded.
- `ConsumableUnitService::review()` — delegates to `postCountAdjustment()`.
- `ConsumableUnitService::postCountAdjustment()` — extracted from `post()` dispatch.
- Stale count guard: rejected if count is no longer the unit's most recent count.

### 3.2 Valuation Repair — Row 18

**Problem:** Units received before cost was known (e.g. GRN issued before invoice)
could be left permanently un-valued if the receipt log lacked a unit cost, blocking
all economic movements.

**Solution:**

- `ConsumableUnitValuationRepair` model — immutable audit record.
- `consumable_unit_valuation_repairs` table — migration above.
- `ConsumableUnitController::repair()` — POST `/consumable-units/{unit}/valuation-repair`.
  - Requires `stores.adjust_quantity` permission.
  - Eligibility check: `unit_cost IS NULL` AND no economic movements with null value.
  - Authoritative cost: prefers `InventoryLog::receipt_unit_cost` from the unit's own
    receive log over user-supplied cost (user cost only used if no receipt snapshot exists).
  - Creates immutable `ConsumableUnitValuationRepair` record.
  - Updates `unit_cost`, `remaining_value`, restores `VALUED` readiness flag.
- `ConsumableUnitService::repairEligibility()` — checks preconditions.
- `ConsumableUnitService::repair()` — orchestrates the repair.

### 3.3 Frontend/Backend Sync — Row 22

#### ResolveMaterialModal.vue
- Receipt made **mandatory** for both match and create resolution modes (removed
  the optional `alsoReceive` checkbox path).
- Physical roll/unit breakdown UI: storekeeper enters individual unit quantities
  when resolving a consumable unit material; row sum must equal the line quantity.
- `controlled_units` array sent to `resolveProjectMaterialCatalogue` endpoint.
- Response returns generated `ConsumableUnit` records; modal displays them on success.

#### ProjectMaterialsDesk.vue
- Row mapping now includes `consumableUnit: !!(match?.tracking_method === 'CONSUMABLE_UNIT' || match?.tracking_mode === 'consumable_unit')`.
- Action column renders a **"Consumable Units"** button for consumable-unit rows instead
  of the standard bulk-issue checkbox.
- `openConsumableIssue()` routes to `/stores/consumable-units` with `action=issue`,
  `material_id`, `project_id`, `project_material_id`, and `recipient` query params —
  mirroring the pattern of `openControlledIssue()`.
- `isIssuableRow()` excludes consumable-unit rows from checkbox-batch selection.
- `routeLabel / routeHint / routeIcon / routeTone` updated to handle `consumableUnit`.

#### ConsumableUnits.vue
- Receipt totals row and `receiptDifference` indicator.
- `save()` captures `controlled_units` from resolve response and surfaces them.
- Count review UI: APPROVED / REJECTED decision form with mandatory reason.
- Valuation repair form with cost input and eligibility guard.

#### storesDecimal.ts (new utility)
- Frontend mirror of BCMath precision for quantity/value display rounding.

#### ProcurementStoresController::resolveProjectMaterialCatalogue
- Added validation for `receive.controlled_units` (array, 1–100 items, each with
  a positive `quantity`).
- Added `controlled_units` to the response payload.

---

## 4. New Files

| File | Purpose |
|------|---------|
| `app/Modules/ProcurementStores/Models/ConsumableUnitCountReview.php` | Immutable review record |
| `app/Modules/ProcurementStores/Models/ConsumableUnitValuationRepair.php` | Immutable repair record |
| `app/Modules/ProcurementStores/Database/Migrations/2026_10_02_100000_create_consumable_unit_reviews_and_repairs.php` | Two audit tables |
| `tests/Feature/Stores/ConsumableUnitOperationalClosureTest.php` | 15 feature tests |
| `src/modules/procurement-stores/utils/storesDecimal.ts` | Frontend decimal utility |
| `src/modules/procurement-stores/utils/storesDecimal.spec.ts` | 2 tests |
| `src/modules/materials-library/consumableMaterialForm.spec.ts` | 6 tests |
| `docs/finance-redesign/phase-2/70b-verification/baseline-audit.md` | Pre-70B audit table |

---

## 5. Test Results

### Backend

```
ConsumableUnitOperationalClosureTest         15 tests,  86 assertions  ✅
ResolveProjectMaterialCatalogueTest          10 tests,  39 assertions  ✅
─────────────────────────────────────────────────────────────────────
Full regression suite (Stores + Finance + CostCollector):
  212 tests, 853 assertions                                            ✅
  Time: 04:19.811  Memory: 48.00 MB
```

### Frontend

```
consumableUnits.spec.ts          23 tests  ✅
consumableMaterialForm.spec.ts    6 tests  ✅
storesDecimal.spec.ts             2 tests  ✅
─────────────────────────────────────────
Total                            31 tests  ✅

Vite production build: ✓ built in 36.43s  (no errors; pre-existing chunk-size warnings only)
API contract check:    0 newly unmatched endpoints; 0 currently unmatched  ✅
```

---

## 6. API Surface

| Method | Route | Action |
|--------|-------|--------|
| POST | `/api/procurement-stores/consumable-units/{unit}/counts/{count}/review` | Count review (APPROVED/REJECTED) |
| POST | `/api/procurement-stores/consumable-units/{unit}/valuation-repair` | Valuation repair |
| POST | `/api/procurement-stores/consumable-units/{unit}/hold` | Place unit on hold |
| POST | `/api/procurement-stores/projects/{project}/catalogue/{material}/resolve` | Resolve + receive (updated: controlled_units) |

---

## 7. Permissions

| Action | Required Permission |
|--------|-------------------|
| Review count | `stores.review` |
| Repair valuation | `stores.adjust_quantity` |
| Self-approval | ❌ Blocked (DomainException) |

---

## 8. Immutability Guarantees

Both new audit models (`ConsumableUnitCountReview`, `ConsumableUnitValuationRepair`)
throw `DomainException` on any attempt to update or delete after creation.
This mirrors the immutability pattern used throughout the stores module for inventory
logs and batch movements.

---

## 9. Residual Policy Gaps (Out of Scope for 70B)

The following are operational policy questions, not software bugs. They are
documented here for completeness but are explicitly deferred:

| Gap | Nature |
|-----|--------|
| Hold → release workflow | Who can release a hold; Finance notification on hold |
| Count review notification | Notify count actor of REJECTED decision via email/in-app |
| Repair audit trail in Finance | Propagate repair cost change to open Finance stock valuations |
| Multi-reviewer quorum | 70B implements single-reviewer approval; quorum is not required |
| Consumable unit disposal | No formal disposal/write-off workflow; currently handled as waste |

---

## 10. Summary

70B closes all three outstanding 70A operational gaps:

1. **Row 16 — Count adjustment**: formal two-actor review with self-approval block,
   immutable audit record, BCMath-valued inventory log posting.

2. **Row 18 — Valuation repair**: eligibility-gated cost recovery, authoritative
   receipt snapshot preference, immutable repair record.

3. **Row 22 — Frontend/backend sync**: mandatory receipt in resolve modal, consumable
   roll breakdown UI, desk routing to Consumable Units workspace, full API contract
   coverage.

All 22 baseline capabilities are now fully implemented and verified.
