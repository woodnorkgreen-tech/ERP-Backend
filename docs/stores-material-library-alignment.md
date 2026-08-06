# Stores and Material Library Alignment

## Data ownership boundary

Material Library owns catalogue identity and classification: material name, SKU, category/subcategory, item type, UOM, technical specification, issue disposition, tracking mode, and control flags. Creating or importing a catalogue item must not establish stock.

Stores Receive Stock owns the first physical inventory facts: quantity, receipt unit cost, warehouse/bin, reference, lot, expiry, and serial identities. Every balance is derived from posted inventory movements. The material `unit_cost` exposed for valuation is a read-only weighted average derived from receipt transactions, not a registration input.

The Receive Stock picker presents only Active catalogue items and supports direct narrowing by material class (Boards, Consumables, Reusables), category, SKU, and name. Selection never creates a second material record; it posts a receipt against the existing Material Library identity.

## Operating contract

The Material Library owns item identity and policy. Stores owns physical quantity, location and movement. An operator may record what happened, but may not reinterpret how an item behaves.

| Material Library owns | Stores owns |
|---|---|
| SKU, name, item type and category | Receipt, issue, return, transfer, adjustment and defect movements |
| Base/purchase/issue UOM and conversions | Quantity by warehouse/location/lot/serial/piece |
| Lifecycle status | Physical availability, reservation and quarantine state |
| Issue disposition and tracking mode | Document references, projects, recipients and transaction time |
| Batch/serial/expiry/hazard/project flags | Actual lot, serial, expiry, condition and storage location |
| Standard dimensions and recovery thresholds | Actual piece dimensions, lineage and recovery outcome |

## Current lifecycle

1. A material definition is registered in Materials Library.
2. Stores inventory combines that definition with its stock row.
3. Check-in increments stock and creates an immutable movement; board items also create individual board records and labels.
4. Check-out deducts ordinary stock. Dimension-controlled items are redirected to Board Requests.
5. Return adds ordinary stock. Dimension-controlled pieces use the board/recovery lifecycle.
6. Defective removes ordinary stock. Boards use their lifecycle transition so piece state and stock agree.
7. Outstanding reusables are calculated from reusable issue and return movements by project.
8. Reports and alerts combine the material definition, stock balance and movement history.

## UI principles

- Every material result shows status, disposition, tracking and base UOM from the master.
- Checkout and returns never offer a consumable/reusable toggle.
- Inactive, blocked, discontinued and under-review items cannot be issued.
- Dimension-controlled items are redirected before a generic transaction is submitted.
- Batch-controlled receipts require a lot identifier.
- Expiry-controlled receipts clearly indicate FEFO requirements.
- Serialized items require an individual-instance workflow rather than aggregate quantity entry.
- Returnable items explain that an outstanding obligation will be created.
- Recoverable items show thresholds and use actual dimensions/condition during recovery.
- Errors tell the operator which workflow to use, not merely that the request is invalid.

## Screen alignment map

| Screen | Required master-driven behaviour |
|---|---|
| Stores Materials Library | Display governed classification, stock and correct action |
| Check-in | Require lot/serial/dimensions based on tracking mode; display receiving effect |
| Batch check-in | Validate every row before posting; prohibit mixed unsupported tracking flows |
| Check-out | Derive disposition; block inactive; route measured pieces to allocation |
| Batch check-out | Read-only disposition; preflight all rows; no operator override |
| Returns | Prefer outstanding return obligations; route measured recovery separately |
| Defective | Capture reason/condition; route physical pieces to lifecycle transition |
| Stock settings | Location/reorder policy only; never classification or quantity adjustment |
| Alerts | Separate low stock, expiring lots, overdue returns, quarantine and master-data blocks |
| Reports | Filter by disposition/tracking/status and reconcile ledger to stock |

## Remaining downstream work

The current alignment introduces the shared control language and removes transaction-level classification overrides. Full operational enforcement still requires:

- inventory lot records with received/expiry dates and FEFO allocation;
- serialized inventory instances and scan-based receipt/issue/return;
- item-location rows for multiple warehouses/bins and per-location reorder levels;
- generalized recoverable-piece records for rolls, cable, profiles and sheets beyond boards;
- transaction reversals instead of deleting posted movement history;
- approval actions for Under Review → Active;
- batch screen preflight and actionable per-row errors;
- aligned GRN receipt posting so procurement receipts create the same controlled stock records as manual check-in.
