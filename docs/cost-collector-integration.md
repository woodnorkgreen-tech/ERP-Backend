# Cost Collector — module integration

**Status:** live · **Updated:** 2026-08-09 · **Branch:** `feat/cost-collector`

How each module reports what a project costs, and what is actually wired today.

---

## The contract

Any module reports a cost by type-hinting the contract and handing it a context:

```php
public function __construct(private CollectsCost $costs) {}

$this->costs->collect(new CostContext(
    expenseCode: 'NE-009',
    amount:      bcmul($log->quantity, $log->receipt_unit_cost, 2),
    projectId:   $log->project_id,
    sourceType:  InventoryLog::class,  sourceId: $log->id,
    details:     ['item' => $log->material_id, 'quantity' => $log->quantity],
    sourceApproved: true,
));
```

`CostContextResolver` fills what the caller did not supply: project identity via
`ProjectIdentityResolver`, activity from the project's live task stage, and the accounting
dimensions from the expense catalogue. `(source_type, source_id, source_ref)` is the idempotency
key, so a producer that retries is a no-op.

Three entry points, all on the single writer:

| Method | For | Validation |
|---|---|---|
| `collect()` | a human reporting a cost | full catalogue rules |
| `postFromSource()` | a producer whose document already carried approval | catalogue rules skipped |
| `postPlanned()` | budget projection | project + amount only |

`postFromSource` skips catalogue validation **by design**. That validation exists to make human
capture correct; a payment that already happened is not a proposal to be corrected, and refusing to
import real spend because the catalogue cannot yet classify it would leave every cost account
understated.

---

## Wiring status

| Module | Cost event | Nature | Status |
|---|---|---|---|
| **Projects** | budget task completes | `planned` | ✅ **event-driven** — `EnquiryTaskCompleted` → `ProjectBudgetLinesOnTaskCompletion` (queued) |
| **Petty Cash** | disbursement paid | `actual` | ⚠️ **command only** — `finance:backfill-petty-cash`. Needs hooking to `VoucherService` |
| **Stores** | material issued to a job | `actual` | ❌ not wired. `inventory_logs` already has `project_id` **and** `receipt_unit_cost` — cheapest real win |
| **HR** | overtime approved | `actual` | ❌ not wired. `ot_entries.project_id` exists |
| **Procurement** | PO approved / GRN / Bill | `committed` → `accrued` → `actual` | ❌ not wired, and `purchase_orders` has **no** `project_id` — three nullable hops via `Requisition` |
| **Logistics** | trips, fuel, vehicle running | `actual` | ❌ no project link on any logistics table |
| **Payroll** | labour into WIP | `actual` | ❌ not wired |

---

## The rule producers must follow

> **Stock-tracked materials cost the project at ISSUE, not at purchase.** Buying into store is a
> balance-sheet move into inventory. Direct-to-site purchases cost at Bill.

Without this a material bought on a PO, received into store, then issued to a job posts three times.
It is the single easiest way to make every project's cost wrong, and this codebase already fails
quietly at exactly these seams.

## Wire producers as queued listeners, not inline calls

Hooking a cost-ledger write into another module's write path means a failure there could stop
somebody completing a task or paying a supplier. **No cost-reporting concern is worth blocking the
workflow it observes.** Listeners are queued with retries and a `failed()` that logs loudly — the
cost line lands a moment later, and a bad projection never becomes an operational outage.

`ProjectBudgetLinesOnTaskCompletion` is the reference implementation.

## Adding a producer

1. Dispatch an event from the module's existing write path — do not call the collector inline.
2. Write a queued listener that builds a `CostContext` and calls `postFromSource()`.
3. Set `sourceType`/`sourceId` from the source document. This is what makes retries safe.
4. Decide the `nature` deliberately: a commitment is not an accrual is not an actual.
5. Add a test that fires the listener twice and asserts one cost line.
