# Stores Opening Inventory

## First-principles model

Stores inventory is not a second material catalogue. The modules share the same material identity:

| Record | Fact it owns |
|---|---|
| `library_materials` | What the item is and how it must be controlled |
| `stocks` | Current aggregate on-hand and reserved projection |
| `inventory_logs` | Immutable evidence explaining every balance change |
| `stock_counts` / `stock_count_items` | A frozen physical-count proposal and its approval trail |
| lots, serial items and boards | The individual physical identities behind controlled aggregate stock |

Every Stores record connects to `library_materials.id` through `material_id`. No material is copied
into Stores, and changing a material definition must never manufacture a stock quantity.

## Meaning of reset

A safe inventory reset is a rebaseline, not a table deletion:

```text
approved adjustment = physical opening quantity - snapshotted system quantity
new on-hand         = old on-hand + approved adjustment
```

This preserves the prior ledger and records the exact quantity removed or added under an `OPEN-*`
reference. It also initializes zero-balance stock rows, so Active catalogue materials become visible
as governed Stores items without inventing a receipt.

## Workflow and invariants

1. A Stores user starts one opening-inventory session.
2. The server snapshots every Active Material Library item, its update timestamp, existing balance,
   control method, cost and bin.
3. The user enters every bulk physical quantity, opening unit cost and rack/bin.
4. Lots, serials and boards remain aggregate-locked. Their physical identities must be registered
   through Receive Stock after the baseline is approved.
5. Submission is refused if an item changed status or definition, or if the Active catalogue gained
   or lost an item. The stale draft can be discarded and regenerated.
6. A different Manager approves. The creator cannot self-approve even if they are also a Manager.
7. Approval locks the count and stock rows, refuses reserved or concurrently changed balances, then
   posts each nonzero difference through `InventoryService` in one database transaction.
8. An approved opening session is final. Later corrections use ordinary cycle counts and controlled
   receipt/issue workflows.

The opening workflow never truncates `stocks`, `inventory_logs`, lots, serials or boards. This is the
critical boundary that keeps current balances, audit evidence and physical identity ledgers aligned.

## Production rollout

1. Back up the production database and deploy backend plus frontend from the same release.
2. Run `php artisan migrate --force`.
3. Finish and activate the required Material Library definitions before starting the worksheet.
4. In Stores, open **Opening Inventory & Stock Counts** and select **Start opening inventory**.
5. Enter bulk counts, costs and bins; use zero for catalogue items with no opening balance.
6. Submit the complete worksheet and have a different Manager approve it.
7. Use **Receive tracked inventory** to enter each opening lot, serial or board identity.
8. Reconcile the Store Inventory totals and the `OPEN-*` movements before normal issues begin.

If production already contains controlled stock, the opening worksheet deliberately preserves its
aggregate value. Reconcile or reverse those identities in the relevant lot, serial or board workflow;
do not force their aggregate to zero from the opening worksheet.
