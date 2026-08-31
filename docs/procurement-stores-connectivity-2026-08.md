# Procurement & Stores — Connectivity and Usability Pass

**Date:** 2026-08-18 · **Scope:** `app/Modules/ProcurementStores/**` + `ERP-Frontend/src/modules/procurement-stores/**`
**Companions:** [finance-module-gap-analysis-2026-08.md](./finance-module-gap-analysis-2026-08.md) · [stores-material-library-alignment.md](./stores-material-library-alignment.md)

The same sweep applied to Finance, run here: every route against every frontend call, every file
against every reference, every screen against whether a first-time user could work out what it does.

---

## 1. Fixed

### Eight phantom REST routes

`Route::resource` was used for suppliers, requisitions, purchase orders and bills. That registers
`create` and `edit` — routes whose job is to return an HTML form — and **none of the four controllers
implements either method**. Eight routes were published that would fatal on a method-not-found the
moment anything reached them. Converted to `Route::apiResource`; the route count drops 113 → 105 with
no loss of function, and a comment records why so it is not "fixed" back.

### Two imports pointing at deleted classes

`InvoiceController` and `POVerificationController` were still imported by the routes file. Neither
class exists — invoices became bills, and PO verification moved. Nothing referenced them, so nothing
broke, but any route added against either would have 500'd immediately.

### ~1,900 lines of unreachable frontend

| File | Lines | Why it was dead |
|---|---|---|
| `views/stores/CheckIn.vue` | 634 | `/stores/check-in` is now a **redirect** to the Operations desk |
| `views/stores/CheckOut.vue` | 569 | `/stores/check-out` likewise |
| `stores/boardsStore.ts` | 464 | A Pinia store no component ever used |
| `shared/composables/createCrudComposable.ts` | 155 | A CRUD factory with no caller |
| `components/StoresTaskLauncher.vue` | 104 | Referenced by nothing |

`Traits/LoadsRelationships.php` is also unreferenced on the backend; left in place pending a look at
whether the controllers should be using it rather than hand-loading relations.

---

## 2. Endpoints with no caller — genuine missing UI

Unlike the Finance sweep, almost none of these are redundant. They are built features with no way in.

| Endpoint | What is missing |
|---|---|
| `GET /boards/by-code/{trackingCode}` | Look a board up by its printed code. `BoardScan.vue` exists but does not call it. |
| `GET /boards/job/{jobRef}/history` | The board history for a job. |
| `POST /boards/job/{jobRef}/bulk-return` | Returning a job's boards in one action rather than one at a time. |
| `POST /boards/job/{jobRef}/return-batches` | Starting a return batch. |
| `POST /boards/batch/{batchNumber}/confirm-labels` | Confirming a print run of labels was applied. |
| `GET /purchase-orders/link/{requisition}` | Linking an order back to the requisition that caused it — the trace a three-way match depends on. |
| `POST /multi-payment` | Paying several supplier bills in one settlement. |

The board return endpoints are the notable cluster: returns can be initiated and batched server-side,
and the only way to use any of it is one board at a time.

---

## 3. Usability

`ModuleGuide` (promoted out of Finance to `src/components/`, since a module importing a component from
a sibling module is how coupling starts) is now on **18 of 31** procurement and stores screens, with
copy in `shared/guides.ts` — 10 workflow guides and 10 shared term definitions.

Screens still without one are `*Edit` and `*Show` detail views whose index and create screens are
already guided, plus the stores Dashboard and MaterialsLibrary.

The copy states the controls where they bite, in the words of the person at the counter:

- *"Enter what you actually received. Never copy the ordered quantity across because the paperwork says so."*
- *"If you sign for what you did not receive, the three-way match will pass and the company will pay for goods it never got."*
- *"An invoice with no matching goods receipt is the classic route for a fraudulent or duplicated payment. Treat a missing GRN as a stop, not a delay."*
- *"Issuing material is a cost against a project the moment it leaves the shelf — not when it is used, and not when it is invoiced."*
- *"A supplier who is not VAT-registered cannot give you reclaimable VAT."*
- *"A job showing high material cost and a large late return was not expensive — it was over-issued."*

---

## 4. Not done

- **The seven unwired endpoints above.** Each needs a UI decision, not just a call — particularly the
  board return batching, which implies a screen that does not exist.
- **`LoadsRelationships`** — orphaned trait, left pending a judgement on whether it should be adopted.
- **Backend test coverage.** `tests/Feature/Stores` holds one file, `BoardLifecycleTest` (14 tests).
  There is no feature test anywhere for requisition → purchase order → goods receipt → bill, which is
  the module's central workflow and the one carrying the three-way-match control.
