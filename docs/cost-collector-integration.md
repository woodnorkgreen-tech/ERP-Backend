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
| **Petty Cash** | disbursement paid | `actual` | ✅ **event-driven** — `PettyCashDisbursementPaid` → `RecordPettyCashCost` (queued). A void fires `PettyCashDisbursementVoided` → `ReversePettyCashCost`, so a backed-out payment stops counting. `finance:backfill-petty-cash` remains for historical rows |
| **Stores** | material issued to a job | `actual` | ❌ not wired. `inventory_logs` already has `project_id` **and** `receipt_unit_cost` — cheapest real win |
| **HR** | overtime approved | `actual` | ❌ not wired. `ot_entries.project_id` exists |
| **Procurement** | PO approved / GRN / Bill | `committed` → `accrued` → `actual` | ❌ not wired, and `purchase_orders` has **no** `project_id` — three nullable hops via `Requisition` |
| **Logistics** | trips, fuel, vehicle running | `actual` | ❌ no project link on any logistics table |
| **Payroll** | labour into WIP | `actual` | ❌ not wired |

---

## Who gets told

`CostNotifier` is the one place that decides. Types are registered in
`config/notifications.php` under the `finance` module.

| Event | Goes to | Why |
|---|---|---|
| `cost_submitted` | anyone with `finance.costs.verify` | the queue belongs to a role, not a person |
| `cost_queried` | the reporter | **the one that matters** — without it a query is sent back to nobody |
| `cost_verified` / `cost_rejected` / `cost_reversed` | the reporter | they asked; they should hear the answer |

Answering a query re-notifies the verifiers, or a resolved query sits waiting for someone who does
not know it moved.

Every send is wrapped. A notification that cannot be delivered must never roll back the decision
that triggered it — verifying a cost is the real work; telling someone is a courtesy allowed to fail
on its own. A producer-posted cost has no human reporter and notifies nobody.

### Why verifiers are resolved by hand rather than broadcast

`NotificationService::dispatchNotification(permission: …)` looks like the obvious way to reach the
queue, and it is wrong here. That path additionally filters recipients through `userCanSeeModule`,
which gates the `finance` module on holding a **Finance** or **Accounts** *role*.

The cost collector is deliberately permission-based — the entire point of replacing the hardcoded
`hasRole('Super Admin')` checks was that someone can be granted `finance.costs.verify` without a
role. Broadcasting would therefore have silently dropped exactly the people who were granted the
right explicitly, and the queue would have looked empty to them forever.

`CostNotifier::verifierIds()` resolves permission holders directly and passes them as explicit
recipients, which bypass the module filter. Holding the permission is a stronger signal of "should
see this" than holding a role.

**If you add a producer or a new notification type here, do the same.** A test caught this only
because it granted the permission without the role — which is precisely the configuration the
permission model exists to support.

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
6. Handle the source document being **undone**. Producers refuse to cost a
   voided document, but that only covers lines created after the void — a document
   costed while live and reversed afterwards keeps its cost line and overstates the
   project forever. Petty cash pairs `RecordPettyCashCost` with `ReversePettyCashCost`
   for exactly this. Note that `CostVerificationService::reverse()` refuses a line that
   already reached the journal: that one needs a compensating entry, which a listener
   has no business inventing, so log it for Finance instead.
