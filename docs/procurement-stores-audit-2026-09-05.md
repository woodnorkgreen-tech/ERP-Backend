# Procurement & Stores: functional audit and interface alignment

_2026-09-05. Follows `purchase-to-pay-workflow.md`, which covers the post-approval
workflow and the supplier payment gate._

## The question that started it: library or inventory?

**A requisition draws from the material library, not from stock on hand.** You
requisition what you *don't* have, so a list of what's on the shelf is the wrong
source — and a material could never be bought into stock for the first time if
the picker only offered things already in it. `MaterialController` already states
this rule for every picker except the ones that consume stock.

Stock is not irrelevant, though — it is the reason to *not* buy. So the picker
now shows free stock against each result and marks a chosen line that is already
available in stores. The catalogue answers "what may I ask for"; stock answers
"should I be asking at all".

## Defects found and fixed

### The requisition items table was structurally broken

The entire Item cell sat inside `<tr>` with **no `<td>` around it**. The HTML
parser lifts stray content out of a table, so the material search box rendered
outside the table and every remaining cell sat one column left of its heading.
Cost classification rendered second while its header was seventh. The empty-state
row spanned 8 of 9 columns.

### The supplier dropdown had two separate faults

1. `RequisitionCreate` never passed a supplier list to the items table at all, so
   the dropdown offered only "No supplier yet". The edit screen did pass one —
   which is why the fault looked intermittent.
2. Every supplier chooser read `GET /suppliers`, the paginated *browsing* index:
   twenty rows, newest first, retired suppliers included. A chooser needs the
   whole set, alphabetically, active only. `GET /suppliers/options` now serves
   that, and keeps one retired supplier visible when a record already names it —
   otherwise editing such a record would silently blank its supplier on save.

### The purchase-order material picker had never worked

It POSTed to `/api/materials-library/search/materials`, a route that has never
existed, so it answered 404 on every keystroke. And on selection it read
`material.unit_price`, which the library does not have — the field is
`unit_cost` — so any line it did produce arrived priced at zero.

### Most requesters could not see the catalogue at all

Raising a requisition needs only an authenticated, active user. Reading the
material library needs `materials_library.view`, held only by Procurement,
Stores, Production and Managers. Everyone else — Accounts, project staff, general
employees — opened the picker, got a silent 403 per keystroke, saw nothing, and
could only type a free-text description. Governed catalogue purchases were being
quietly turned into ungoverned one-offs.

Choosing from a catalogue is not the same act as browsing or managing it, so
`GET /procurement-stores/material-options` exposes the narrow read a picker
needs, under the same authorisation as the document it feeds. The library's own
boundary is unchanged.

It uses `LibraryMaterial::governed()`, not `active()`. The model documents
`item_status` as authoritative and `active()` as a legacy scope reading only
`is_active` — with `active()`, a retired material whose old boolean was never
cleared is still offered. **`MaterialController::buildMaterialQuery()` still uses
`active()`**, so the library listing has the same flaw; not changed here because
it affects every library screen.

### Smaller ones

- The bills list linked rows to `/procurement/bills/:id`, a route that does not
  exist; the detail screen is `/procurement/billing/:id`.
- The invoice screen linked to `/procurement/purchase-orders/:id` (the list)
  rather than `/procurement/purchase-order/:id` (the detail).
- `shared/composables/usePermissions.ts` imported `@/stores/auth`, which does not
  exist in this codebase, so it could never have run. Five screens each fetched
  `/api/user` separately and re-declared the same role lists inline. Rewritten as
  a working composable that fetches the user once per session and states the role
  lists once.
- The type-ahead ran three catalogue-wide aggregate queries on every keystroke,
  because it shared an endpoint with the library dashboard.

## Interface alignment

All twenty Procurement screens now use the same header, rail, breadcrumb and
table treatment as Stores — `WorkspaceHeader`, `WorkspaceNav` and `stores.css`,
driven by the one navigation map. Previously each screen carried its own
hand-rolled breadcrumb and header markup; two of them (`RequisitionIndex`,
`RequisitionShow`) had a wholly separate visual language.

Both line-item tables share one `MaterialPicker`. Its results panel is teleported
to the body and positioned against the field, because an editing table scrolls in
both directions and a panel positioned inside the row is clipped at the last row.

## Left alone, deliberately

- **`buildMaterialQuery()` still uses `active()`** — see above.
- **Only 10 of 428 governed materials carry a unit cost.** The picker fills the
  price from the catalogue, so most lines still arrive at zero and are priced by
  hand. That is a data gap, not a code one.
- **`purchase-order/create` is routed but commented out.** Orders are raised from
  a requisition; the standalone create screen is unreachable by design.
- Three pre-existing type errors remain in the module: two in `pollingManager`,
  one needing `lib: es2021` in tsconfig, which is a repo-wide change.
