# Request to order: one approval, no page changes

_2026-09-08. Applies to the ProcurementStores requisition and purchase-order path,
and to the `procurement-stores` frontend module. The steps **after** an order is
placed are unchanged and documented in [purchase-to-pay-workflow.md](purchase-to-pay-workflow.md)._

Buying one thing used to take six screens and two approval cycles. Every step
existed; none of them decided anything the previous step had not already
decided. This records what the route is now, and what was deliberately kept.

## What it cost before

| # | Step | Screen | Full page load |
|---|------|--------|----------------|
| 1 | Raise a requisition | `/procurement/requisition/create` | yes |
| 2 | Send it for approval | `/procurement/requisition/:id` | yes |
| 3 | Approve it | register or detail, behind a `confirm()` | yes |
| 4 | Raise the order | `/procurement/purchase-order/link-from-requisition/:id` | yes |
| 5 | Submit the order for approval | `/procurement/purchase-order/:id` | yes |
| 6 | Approve the order | same screen | — |

Seven navigations, two approval cycles, four human decision points. Steps 2, 5
and 6 asked nobody anything new: 2 confirmed what the requester had just done,
and 5–6 asked the organisation to agree a second time to spend it had approved
at step 3.

## What it costs now

| # | Step | Where | Full page load |
|---|------|-------|----------------|
| 1 | Raise a requisition — **this sends it** | drawer over the register | no |
| 2 | Approve it | one click on the register row | no |
| 3 | Raise the order — **this places it** | drawer over the requisition or its row | no |

One approval. No page changes on the happy path.

## The three rules that made it shorter

### 1. Asking is one action

`RequisitionController::store()` creates the requisition as `pending_approval`
with `submitted_at` set. `save_as_draft: true` keeps it back as a `draft`
instead — the draft was not removed, only demoted from *what always happens* to
*what you ask for*, because somebody half-way down a long list of materials still
needs it. `POST /requisitions/{id}/submit` still exists for those drafts.

### 2. The requisition is the approval

`PurchaseApprovalPolicy` decides whether an order still needs a person. An order
raised from an approved requisition, for no more money than that requisition was
approved for, is placed immediately — `storeLinked()` submits and approves it in
the same call.

**This is cover, not size.** An order still goes to a person when:

- it has no requisition behind it — nobody agreed the need;
- its requisition is not approved;
- it grew past what the requisition was approved for;
- it has no value;
- Finance has set **and signed off** `purchase_order_auto_approval_limit` and the
  order is above it.

That last one changed meaning. The limit used to be the *switch*: with no signed
limit, nothing auto-approved, which made "one approval per purchase" conditional
on a number nobody had entered — so in practice every purchase was still approved
twice. The limit is now an **optional ceiling** on top of the cover rule. Unset,
cover alone decides. Unsigned, it still caps nothing, because an unapproved
threshold must not quietly tighten or loosen anything either way.

### 3. Who approves is a permission

`procurement.requisitions.approve` and `procurement.orders.approve`, held in
`PurchasePolicy` and granted through `App\Constants\RolePermissions` +
`php artisan permissions:sync`.

Both controllers previously carried a private `canApproveOrDelete()` comparing
the user's role name against `['Super Admin', 'Admin', 'Accounts']` — two copies
of one rule, invisible to the roles screens, and widening the approver pool meant
editing PHP. Seeded to **Super Admin, Admin, Accounts and Manager**.

**Procurement is deliberately not seeded with it.** They place the orders;
letting them also approve the need collapses maker-checker on the one decision
that now releases a purchase. Adding them is a line in the matrix if the business
decides the separation is not worth the wait.

The three legacy role names remain as a fallback inside the policy, so nobody
loses access on an environment that has yet to run `permissions:sync`.

## The interface

`WorkspaceDrawer.vue` is the module's one drawer — teleport, scrim, focus trap,
focus restore, scroll lock, escape-to-close — over the `.stores-drawer` CSS that
`OperationsDesk` had earned and kept to itself. Three screens use it:

| Drawer | Opens from | Replaces |
|--------|-----------|----------|
| `RequisitionFormDrawer` | the requisition register | `RequisitionCreate.vue` (deleted) |
| `RaiseOrderDrawer` | a requisition, or its row in the register | `PurchaseOrderCreate.vue` (deleted) |
| `PurchaseOrderDrawer` | a row in the order register | a page load to `PurchaseOrderShow` |

Both deleted routes are **redirects, not 404s** — `/requisition/create` opens the
register with the drawer up, and `/purchase-order/link-from-requisition/:id`
opens the requisition with the order drawer up. The dashboard, HR self-service
and the finance spend entry point all link to the first, and the second was
bookmarked.

`PurchaseOrderShow` remains a route. A printable order and a delivery history are
worth a page, and a link somebody was sent has to keep working; the drawer links
to it as "Full record".

### The refusal is visible before the button is pressed

Approving has three rules — every line classified, every category procurable,
and every category legal for the requisition's job context. They lived inside
`approve()` and were reachable only by pressing Approve and being refused, so
the register showed every pending row as equally ready and the approver found
out that somebody *else* had mis-classified a line at the moment they could do
least about it.

`RequisitionApprovalCheck::firstBlocker()` now holds them. The controller gates
on it; `RequisitionResource` exposes it as `approval_blocker`; the register
shows "Cannot be approved yet" with the reason and swaps Approve for **Fix
categories**, and the detail screen says the same above the record. One rule,
two readers, so the warning and the refusal cannot drift.

`firstBlocker` returns only the *first* problem: the rules run in order of
bluntness, and a line that has no category yet has not been asked whether its
category is legal. It calls `loadMissing('expenseCode')` on the item collection,
so a register of forty rows is not forty queries.

**The case that prompted it:** a project requisition carrying *"Airtime and
internet"* (`OE-COM-001`, `job_id_rule = not_allowed`) — an office-only category
that may never be charged to a job. The picker filters by job context, so it
cannot be chosen that way; the requisition had been switched to a project after
its lines were coded, and the frontend's guard for that only fires while the
form is open.

### Smaller things that were costing clicks

- **No `confirm()` on approve.** It asked "are you sure?" of somebody who had
  just pressed a button labelled Approve on a row naming the requisition. It
  bought no safety and taught the desk to dismiss dialogs unread — which is what
  makes the *destructive* confirmation worthless. Deleting still asks.
- **No `alert()`.** Outcomes land as a notice line above the table, so the
  server's reason ("3 items need a purchase category") stays readable instead of
  vanishing behind an OK button.
- **Approve and Raise order are on the register row.** The buyer never opens the
  requisition to act on it.
- **The delivery address is remembered** in `localStorage` between orders. A
  workshop has one and typing it again is not a decision.

## Tests

- `tests/Feature/Procurement/PurchaseFlowTest.php` — the three rules above.
- `tests/Feature/Procurement/ProcurementSpeedTest.php` — what auto-approval
  refuses, and the cover rule standing on its own.
- `tests/Feature/Projects/ProjectWorkflowContractsTest.php` — the project↔
  procurement sync across the shortened lifecycle.

## Still open

- **Advance and deposit payments** have no authorised path (unchanged; see
  purchase-to-pay-workflow.md).
- **`PurchaseOrderController::store()`** — the hand-typed order, with no
  requisition — always goes to a person, correctly, but has no screen. The only
  way to raise an order is from a requisition.
- **Maker-checker on the single approval** is not enforced: a user holding the
  permission can approve a requisition they raised. The team is small and this
  was the prior position too, but it now guards one gate rather than two.
