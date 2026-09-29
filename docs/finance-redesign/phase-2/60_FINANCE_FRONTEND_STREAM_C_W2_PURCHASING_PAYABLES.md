# 60 — Finance Frontend Stream C: W2 Purchasing & Payables

**Date:** 2026-09-29
**Environment:** local development / DDEV only
**Production:** untouched
**Verdict:** **STREAM C COMPLETE — W2 REDESIGN READY FOR STREAM D**

---

## 1. Executive Summary

Finance now has its own Purchasing & payables workspace, separate from Procurement's documents and joined to them by links:

> REQ → PO → GRN → **BILL → VERIFY → (WHT) → PAYMENT → SETTLED**

- **Procurement** still owns requisitions, orders, amendments, goods receipts and recording supplier bills.
- **Finance** now verifies, returns, pays and monitors bills in `/finance/payables/*`. It sees every bill's full document chain, the backend's three-way match, the staged-billing position, WHT and its liability account, controls, payments, the project cost behind it, evidence and audit.

Four defects were found and corrected:

1. **Supplier-bill verification was authorised by role NAME** (`Super Admin`/`Admin`/`Accounts`) in two places. It is now the permission `finance.payables.verify`. The same people hold it, and a role name without the permission verifies nothing. This is proven by the mandatory §51 test, which was mutation-checked.
2. **Supplier bills had no Return for Correction.** `PUT /bills/{bill}` was routed to a handler that did not exist. There is now return → correct → resubmit → independent verify, with an immutable audit trail.
3. **A direct bill (no PO) never reached project costing.** Its expense was posted to the ledger but no cost line existed. A verified direct bill now becomes the project's ACTUAL cost, exactly once, as an analytical line with no second journal.
4. **Withholding on supplier bills was missing from the WHT remittance return.** It reached the ledger (Cr WHT payable) but `whtSchedule()` read only cost lines. Verified, posted bills are now on the return. This was mutation-checked.

| Check | Result |
|---|---|
| Backend full suite (final code) | **1,557 tests / 11,104 assertions PASS** (557 s; 1,539 + 18 new) |
| Finance feature suite | **485 tests PASS** (467 + 18 new) |
| Procurement + Stores + Cost Collector suites | **444 tests PASS** |
| Dedicated W2 test (`PayablesWorkspaceTest`) | **18 tests / 229 assertions PASS** |
| Frontend suite | **31 files / 250 tests PASS** (229 + 21 new) |
| ENG-1 | **256 diagnostics; error set identical to `HEAD` — 0 new** |
| Production build | **PASS** (1,949 modules) |
| API contract | **176 Finance call sites, 0 unmatched** (W2: 15/15) |
| Production / cutover / W8 / Stream D | untouched / not started |

---

## 2. Scope and Safety

- Local repositories and DDEV only.
- Tests ran sequentially against `db_test`, each after a competing-run check.
- The two new migrations were applied to the **local dev database only** (`DB_HOST=db`, `APP_ENV=local`), and only after confirming they were the only pending migrations.
- No production database, migration, journal, opening balance, queue worker, deployment or cutover. W8 and Stream D were not started. Nothing is committed or pushed.
- **Session note:** before starting I checked for concurrent sessions, since a Codex session had overlapped Report 59. None was writing.

---

## 3. Existing W2 Architecture (audit, before any change)

| Area | Backend (authoritative) | Frontend before Stream C | Finding |
|---|---|---|---|
| Requisition | `RequisitionController` submit/approve/reject; `procurement.requisitions.approve` | Procurement screens | Sound |
| Purchase order | approve, **senior-approve (W2-1)**, return-for-correction/resubmit (**W2-6, PO only**), send, PDF, workflow | Procurement | Sound; senior tier dormant (threshold unset) |
| PO amendment (W2-4) | `PurchaseOrderAmendmentController` index/store/approve/reject; original/proposed snapshots; `procurement.orders.amend` | Procurement | Sound; billing paused while a commercial amendment is pending |
| GRN | `GoodsReceiptNoteController`, item confirm (Stores), inspections | Procurement/Stores | Sound |
| Service confirmation | **none** (services use the GRN) | — | W2-8 open policy |
| Supplier bill | `BillController` store/show/verify/pay/batch-pay/destroy; `PurchaseOrderWorkflow::bill()` = three-way match + fingerprint + `can_pay` | `BillingIndex/Show/Create` in Procurement | **Verify and delete authorised by role name**; **no update/correction** (`PUT` 500 trap); **no Return for Correction**; index/store carry no permission beyond login |
| Staged billing (W2-3) | cap on net vs `remainingBillable()` and remaining accepted value | shown in `BillingShow` | Sound |
| Duplicate bill/payment (W2-5) | `DuplicateDetectionService`; override needs `procurement.bills.override_duplicate` + reason; actor/time stored | partly | Sound |
| WHT | `SupplierInvoiceTax` from supplier category; posting Cr WHT payable (category GL or mapped `2120`/WNG `WHT-001`) | amount only | **Bill WHT missing from WHT return** |
| Payment | `SupplierPaymentService` (one transfer, n allocations), guard from `BillPayment::creating`, `postSupplierPayment`; source must be active + `can_make_payment`; `finance.petty_cash.create_disbursement` | modal in `BillingShow` | Sound; payment adds no cost |
| Costing | PO → COMMITTED, GRN → ACCRUED (Dr Inventory/Cr 2150), Stores issue → ACTUAL; bill verification Dr 2150 / Cr AP and marks accrual `settled_by_bill_id` | — | PO path sound. **Direct bills produced no cost line** |
| Attachments (W2-2) | generic `finance_attachments` for PO and bill; uploader `{id,name}`; no path exposed | Procurement | Sound |
| My Actions | `supplier_invoice` (role-name gated, linked to Procurement) | — | Role-name gate; no correction/payment items |

---

## 4. Final W2 Boundary

| Responsibility | Owner / screen |
|---|---|
| Raise requisitions, orders, amendments; receive goods; record supplier bills | **Procurement / Stores** (existing screens, authoritative) |
| Verify a bill, return it, pay it, see WHT, AP position and supplier balances | **Finance** `/finance/payables/*` |
| Correct a returned bill | Its **preparer** — from the Finance bill page (the action appears only for them) |

Finance does not edit POs, amendments or GRNs: its pages link to them.

---

## 5. Navigation

`Purchasing & payables` now contains:

- **Finance pages** (gated on `finance.payables.read`): Supplier bills · Supplier payments · Withholding tax · What we owe suppliers.
- **Procurement links** (external): Purchase requisitions · Purchase orders · Goods received.

Detail pages under `/finance/payables/` stay inside the section. The section root for Accounts is `/finance/payables/bills`.

## 6. Procurement → Finance Document Chain

`DocumentChain` on every bill: Requisition → Purchase order (with amendment count) → Goods received → Supplier bill → Payment(s). Requisition, order and receipt link to their Procurement screens; payments link to the payment register.

- **Direct bills** show "Not applicable (direct bill)" rather than empty steps.
- **A PO bill with no receipt** shows "No GRN has been linked to this supplier bill."

## 7. Payables Workspace — `GET api/finance/payables/*` (new, read-only)

| Endpoint | Purpose |
|---|---|
| `bills` | paginated (10–100), server filters, summary counts |
| `bills/{bill}` | the full detail (§11) |
| `payments` | supplier payment register |
| `position` | AP position: summary, ageing, suppliers, ledger |
| `wht` | withholding on supplier bills |

All require `finance.payables.read`, with no role logic. Every mutation stays on the Procurement bill routes. Identities are `{id, name}` only.

## 8. Supplier Bill Index

- **Filters:** search (bill, supplier invoice, PO, supplier) · supplier · project (via requisition → project → enquiry, or a direct bill's own fields) · Project Officer · PO · verification state · payment state · WHT · overdue · bill-date and due-date ranges.
- **Row:** supplier, bill and supplier invoice number, project, PO, GRNs, dates and days overdue, gross, WHT, payable, paid, owed, **separate** review and payment chips, and the backend's next action.

## 9. Supplier Bill Detail

Supplier and invoice facts; procurement basis (requisition, PO with approvals and senior-approval state, original order vs amendments, receipts line by line with receiver and confirmation); three-way match; tax and WHT; controls (prepared, returned, resubmitted, verified, postings, duplicate override); payments; project cost; evidence (filename, type, uploader, time; downloads through the controlled route); and audit (real `governance_audit_logs` only).

## 10. 3-Way Match

The backend's `PurchaseOrderWorkflow::bill()` is rendered as-is; there is no matching logic in Vue.

- **Named checks:** order approved, supplier matches, supplier invoice number recorded, goods accepted, within the remaining accepted value, within the order's remaining billable amount.
- **Line table:** ordered, received, accepted, unit price, accepted value, result.
- **Basis:** bills are header-level, so billing is compared by net value; the screen says so. The fingerprint is unchanged, so a later change to the order, receipt or bill still withdraws verification.

## 11. Goods Receipt

A Finance-facing view inside the bill: GRN number, date, receiver, Stores status, and per line ordered/received/accepted/condition/confirmation. Operational receiving stays in Stores.

## 12. Service Procurement

No service-confirmation mechanism exists; services are received through the GRN. W2-8 is an open policy, so nothing was invented. `service_confirmation` is `null`, and the screen says the decision is WNG's.

## 13. Staged Billing

The position shows order value, previously billed, this bill, billed in total and still billable (net).

- **Backend enforcement is unchanged at creation** and **extended to corrections**: a corrected bill is re-capped and refused if it would breach the cap (tested).
- The UI never enforces the cap.

## 14. Duplicate Bill Control

W2-5 is unchanged: same supplier + same supplier invoice number is refused with `DUPLICATE_BILL` facts. An override needs `procurement.bills.override_duplicate` **and** a reason; it records actor and time, and the detail shows it (tested).

Corrections re-run the duplicate check, excluding the bill itself.

## 15. Return for Correction

New for supplier bills:

- **Return:** `POST /bills/{bill}/return-for-correction`. Needs `finance.payables.verify` and a reason. It is refused for the bill's own preparer, and for a verified, paid, cancelled or already-returned bill.
- **Correct:** `PUT /bills/{bill}`. Only the preparer, only while the bill is returned, with a correction note. It re-prices tax, re-checks duplicates and the staged cap, and stamps `resubmitted_at`.
- **While returned:** the bill cannot be verified or paid. `PurchaseOrderWorkflow` blocks it, so the payment guard and every screen agree.
- **Preparer's view:** a "Returned for correction" banner with who returned it, when and why. Their My Actions gets "Supplier invoice returned to you".
- **Audit:** return, correction (with before/after and the note) and verification are logged. Migration `2026_09_29_000002` adds `returned_by/at`, `return_reason` and `resubmitted_at`; payment status stays in `bills.status`.

## 16. Supplier Bill Permission Correction

- **Permissions:** `finance.payables.read` and `finance.payables.verify`, named after `finance.receivables.*`.
- **`BillController`:** `canVerify()` now checks the permission. Deleting an unverified bill uses the same permission instead of the role list.
- **`FinanceWorkQueueService`:** the role-name `canVerifySupplierBills()` was deleted.
- **Grants:** Admin and Accounts, through `RolePermissions::matrix()` for fresh builds and migration `2026_09_29_000001` for existing ones. The migration grants only to roles that exist and warns otherwise (the phantom-role trap). Super Admin holds it through `Gate::before`. That is exactly the previous population; nothing was broadened.
- **Frontend:** `BillingShow` shows Verify only to holders of the permission, and `BillingIndex` delete uses it instead of `isAccounts`.
- **Tests:** six existing fixtures authorised verification with a *bare* `Accounts` role. They now grant the permission to that role, as production does.

## 17. Maker/Checker

Unchanged and re-proved: a preparer cannot verify their own bill, even while holding the permission, unless `approvals.self_approve` applies (`BillVerificationSegregationTest`, `PayablesWorkspaceTest`).

A preparer also cannot return their own bill, and only the preparer corrects it. The full sequence prepare → return → correct → resubmit → independent verify is tested end to end.

## 18. Purchase Orders

Finance sees the authoritative PO inside the bill: supplier, value, date, raiser, approver and time, senior approval, return history, amendments, receipts and evidence (through the PO attachment route). Editing remains Procurement's.

## 19. PO Amendment

Original vs amendment is shown per amendment: original total → approved (or proposed) total, commercial flag, reason, requester, approver or rejecter, and times. The original terms are never overwritten (W2-4 snapshots). A pending commercial amendment is flagged: "billing on this order is paused".

## 20. Senior Approval

The mechanism is complete: `senior_approval_required` per order, `procurement.orders.approve_senior`, and the threshold is the Finance setting `purchase_order_senior_approval_threshold`.

- The setting is **unset**, so the tier applies to nothing.
- The projection reports the threshold as `null`, and the screen says "No senior-approval threshold has been set by WNG yet". It never shows a default (tested).
- No KES value and no approver were invented.

## 21. WHT

The screen shows **Gross supplier bill − Withholding tax = Amount payable to supplier**, separately states the **WHT liability** (retained, owed to KRA, on the mapped account), and says it is **not a discount**.

- **Mapping:** WHT is calculated by the backend from the supplier's category. The liability account is the category's own, else `ChartAccountMap::local(WHT_PAYABLE)`: `2120` in this chart and `WHT-001 — Withholding Tax Payable` under the Report 54 WNG profile.
- **No new accounts:** no new liability account and no WHT receivable.
- **WHT page:** withheld on verified vs unverified bills, and the ledger balance of the liability account.
- **Defect fixed:** `TaxScheduleService::whtSchedule()` now includes verified, posted bills carrying a WHT category, dated by tax point and grouped as their own payee rows. Receipt accruals carry a category but no withheld amount, so merging them would double the aggregation base.

## 22. Supplier Payments

`BillPaymentForm` works from the bill.

- **It shows:** gross, WHT, payable, already paid, outstanding and outstanding after this payment.
- **Accounts:** only active, `can_make_payment`, ledger-linked accounts are offered. Supplier Credit is never offered. M-Pesa and card accounts are named as "not available until Finance configures them" and not offered.
- **Fields:** method, reference, date and optional bank charge (overhead).
- **Control:** a deliberate confirmation before posting.
- **Register:** `/finance/payables/payments` lists every payment with its bill, account, recorder and any duplicate override.

Paying stays gated as before: `finance.petty_cash.create_disbursement`, not legacy, and `can_pay`. A user without it gets **Request payment** (petty-cash requisition against the bill), exactly as `BillingShow` does.

## 23. Partial Payments

Supported by the backend and tested: 20,000 then 30,000 against 50,000. The bill is `partially_paid` with 30,000 outstanding, then `paid`. Overpayment is refused. The form refuses more than is outstanding before submitting, but the backend remains the authority.

## 24. Duplicate Payment Control

The same reference on the same account is refused with `DUPLICATE_PAYMENT` facts. The UI shows the matched payment and bill. It offers an override reason **only** when the backend says `can_override`; otherwise the button stays disabled, so there is no generic "proceed anyway" (tested both ways). The override is recorded on the payment and shown.

## 25. Accounts Payable Position

`/finance/payables/position` shows:

- outstanding, overdue and unverified amounts per supplier (with links to that supplier's bills and payments);
- ageing buckets from `PayablesAgeingService`;
- the **AP control account balance** from posted journal lines, beside the bill totals for reconciliation.

Legacy bills never posted, so the two can legitimately differ, and the page says so. It is tested: after a 10,000 payment on a 50,000 verified bill, supplier outstanding, summary outstanding and AP ledger all read 40,000.

## 26. Project Costing Integration

| Event | Project cost | Evidence |
|---|---|---|
| PO approved | COMMITTED | `ProcurementCostProducer::postPurchaseOrder` (tested) |
| GRN accepted | ACCRUED (commitment released) | `postGoodsReceipt` (tested) |
| PO-backed bill verified | **no new cost** — Dr Accrued / Cr AP; accrual marked `settled_by_bill_id` | tested: line count unchanged |
| Stores issue to a job | ACTUAL (relieves the accrual) | existing W6 materials chain |
| **Direct bill verified** | **ACTUAL**, once, analytical (`postsIndependently: false`) | **new**; tested (one line, one journal) |
| Payment | **no new project cost** | tested for PO and direct bills |

**On "Bill → ACTUAL" for PO-backed bills.** Creating an ACTUAL line at verification would double-count goods that become ACTUAL when issued. The implemented W6 chain (Report 34 §450) recognises cost once: at GRN (accrued) and issue (actual). Report 34 §6 step 4 contradicts that chain and should be corrected; it is listed in §45. The bill page shows the accrual it settled and states that payments add no cost.

## 27. WIP Integration

Unchanged. Working policy remains CAPITALISE (rehearsal). The direct-bill cost line is analytical only, so no WIP or COS posting changed.

## 28. Attachments

W2-2 is reused as-is. Bill evidence shows filename, document type, uploader and time, and downloads through `/bills/{bill}/attachments/{id}/download`. No file path is ever serialized (a test asserts `file_path` is absent). PO evidence remains on the PO.

## 29. Auditability

Shown only from real records:

- **Bill:** prepared, returned (reason), corrected (before/after and note), verified (notes).
- **Ledger:** postings (entry number and status).
- **Overrides:** duplicate overrides on bills and payments.
- **Procurement:** amendments (request, approval, rejection); PO approval and senior approval; receipt receiver and confirmation.

Nothing is fabricated: an event with no record is not shown.

## 30. My Actions

| Type | Who | When | Link |
|---|---|---|---|
| `supplier_invoice` Verify supplier invoice | `finance.payables.verify` | unverified, not returned, not your own | `/finance/payables/bills/{id}` |
| `supplier_invoice_correction` Correct supplier invoice | the preparer | returned, not resubmitted | same |
| `supplier_payment` Pay supplier invoice | `finance.petty_cash.create_disbursement` | verified, non-legacy, balance > 0 | same |

All three were previously role-gated or absent. They are tested through the full return → correct → verify → pay lifecycle, including disappearing once no longer actionable. Requisition and PO approval items are unchanged. My Actions stays navigation-only.

## 31. Overview

- **Automatic:** the Purchasing attention area picks up the three W2 queue types.
- **New "Owed to suppliers" block** (for `finance.payables.read`): outstanding, bills past due and amount, and verified bills awaiting payment, linking to the AP position.
- There is no second dashboard.

## 32. Project Context

Project Billing gains a **Supplier bills** link (`/finance/payables/bills?enquiry_id=…`) for holders of `finance.payables.read`. The bill list filters by project server-side. Project users without the payables permission see no supplier finance.

## 33. Supplier Context

Supplier → bills (`?supplier_id=`), → payments (`?supplier_id=`), → outstanding (the position page's supplier table). The supplier master was not changed.

## 34. Backend Changes

- **Permissions:** `FINANCE_PAYABLES_READ` and `FINANCE_PAYABLES_VERIFY` added to the registry, groups and role matrix, plus migration `2026_09_29_000001`.
- **Bill correction:** migration `2026_09_29_000002` adds the return/correction columns; `Bill` gets `returnedBy()` and `awaitingCorrection()`.
- **`BillController`:**
  - permission-based `canVerify()`;
  - `returnForCorrection()` and `update()`;
  - the returned-bill verify refusal;
  - `recordDirectBillCost()`;
  - audit entries.
- **`PurchaseOrderWorkflow`:** a returned bill is ineligible for verification and payment, and the state carries `awaiting_correction`.
- **`FinanceWorkQueueService`:** permission gate, correction and payment items, Finance links; role helper removed.
- **`TaxScheduleService::whtSchedule()`:** now includes supplier-bill WHT.
- **New:** `PayablesController` (5 read projections), `PayablesActions`, and routes.

## 35. Frontend Changes

- **New `src/modules/finance/payables/`:**
  - `w2.ts`, `w2.spec.ts`;
  - components `DocumentChain`, `ThreeWayMatch`, `BillPaymentForm`, `BillCorrectionForm`;
  - views `BillListView`, `BillDetailView`, `SupplierPaymentListView`, `WithholdingTaxView`, `PayablesPositionView`.
- **Changed:** navigation and routes; `status.ts` (`bill_verification`, `bill_payment`); the Overview block; the Project Billing link; `BillingShow`/`BillingIndex` switched to permissions.

## 36. Backend Tests

`PayablesWorkspaceTest` (new, 18 tests / 229 assertions):

- **Permission:** §51 ×3; preparer self-verify.
- **Read:** access; filters and paging; figures, chain and safe identities.
- **Bill:** return/correct/resubmit/verify with audit; correction staged cap; duplicate and override.
- **Payment:** partial/full with no cost; unverified refused, duplicate payment, inactive source; payment register.
- **WHT:** payable, liability account, ledger and WHT return.
- **Costing:** PO → GRN → bill → payment; direct bill ACTUAL once.
- **Other:** AP position vs ledger; My Actions lifecycle.

Six existing bill fixtures gained the explicit permission grant. Everything else passed unchanged.

## 37. Frontend Tests

`w2.spec.ts` (21 tests):

- **List:** figures and chips; URL filters and paging; empty and error states.
- **Detail:** chain links; match and staged billing; WHT presentation; amendments, receipts, controls and cost; verify gated and confirmed; maker/checker reason; return and preparer banner; request-payment path; direct bill.
- **Payment:** paying accounts; partial payment with confirmation; duplicate without and with override; outstanding cap.
- **Correction; registers and pages:** correction; payments register; WHT page; AP position; navigation.

`navigation.spec.ts`: two tests that pinned "Purchasing is all external" were updated to the new boundary.

## 38. Permission Tests (mandatory §51)

- **A role name alone verifies nothing:** users whose only role is `Accounts`, `Admin`, `Finance` or `Accountant`, with the permission stripped, get **403** on verify, their bill stays unverified, and My Actions offers them nothing.
- **The permission alone is enough:** a user with **no roles at all** but the permission verifies successfully.
- **A role verifies through the permission:** a role carrying the permission verifies.
- **Mutation-checked:** putting the role-name check back makes the test fail ("Accounts verified by role name").

## 39. API Contract

`route:list --json` was checked against every `api.*` call in `src/modules/finance`, with helper-built URLs (`invoiceUrl`, `billUrl`) and base constants expanded: **176 call sites, 0 unmatched**. W2 accounts for 15 endpoint shapes, all matched.

## 40. ENG-1

`vue-tsc --build --force --pretty false`: **256**. The normalised error set was diffed against a pristine `git archive HEAD` build: **0 new, 0 removed**. All W2 files are clean.

## 41. Build

`npm run build`: **PASS**, 1,949 modules (`BillDetailView` 41.3 kB, `BillListView` 13.0 kB).

## 42. Full Regression

- **Full backend suite (final code):** **1,557 tests / 11,104 assertions PASS** in 556.93 s.
- **Earlier run on the same code, before the final additive senior-threshold field:** 1,557 tests / 11,102 assertions PASS.
- **Finance:** 485 PASS.
- **Procurement + Stores + Cost Collector:** 444 PASS.
- **Frontend:** 250 PASS.

All runs were sequential.

## 43. Visual Validation

- **Not produced.** No Playwright, Puppeteer or Chromium is installed, none was installed, and no dev server was started.
- **Read-only probe instead.** The projections were run against the local dev database as a Super Admin: all endpoints answered and 0 details failed. **However, the dev database holds no supplier bills**, so this is not real-data evidence.
- **Real-data validation needs** the rehearsal (source-copy) database; that was out of scope.

## 44. Business Policy Register

| Decision | Technical mechanism | Current policy | Blocking? |
|---|---|---|---|
| Senior approval threshold | `purchase_order_senior_approval_threshold` (Finance setting) + `senior_approval_required` gate | **Awaiting WNG** (unset → tier applies to nothing; screen says so) | No |
| Senior approver | `procurement.orders.approve_senior` (assignable; no default holder) | **Awaiting WNG** | No |
| PO cancellation/closure (W2-7) | none (a `cancelled` status exists in the vocabulary; no action) | **Awaiting WNG** | No |
| Service confirmation (W2-8) | none; services received through the GRN | **Awaiting WNG** | No |
| Supplier credit note (W2-9) | none (corrections before verification use Return for Correction) | **Awaiting WNG** | No |
| Emergency purchase (W2-10) | direct bill exists (no PO), verified like any bill | **Awaiting WNG** (whether it constitutes the emergency route) | No |
| Cross-client receipt allocation (from Report 59) | allowed; UI warns | Awaiting Finance | No |

## 45. Known Issues

1. **Report 34 §6 step 4 is inaccurate.** It says bill verification converts ACCRUED to ACTUAL; the implemented chain recognises ACTUAL at Stores issue (§26). The document should be corrected. Relatedly, a PO-received item never issued to a job (e.g. a service received through the GRN) remains ACCRUED, correctly valued but labelled "Received, not invoiced". This ties to W2-8 and needs a W6 decision.
2. **Anyone signed in can record a supplier bill** (`BillController::store` has no permission). Verification is now controlled, so this is a data-entry control. Not changed: it was out of scope and would affect Procurement.
3. **`PurchaseOrderShow`'s approve button is still gated on role name** (`isAccounts`) in the frontend. The backend enforces `procurement.orders.approve`, so this is a visibility issue only; it belongs to the Procurement screens.
4. **Batch (multi-bill) payment** remains in Procurement's billing screen; the Finance page pays one bill at a time.
5. **The supplier master exposes no dedicated supplier page** in Finance. Supplier context is via filters and the position page.
6. **No visual or real-data evidence** (§43).
7. **M-Pesa and Company Card** stay unavailable until configured.

## 46. Files Changed

### Backend
- **Changed:** `app/Constants/Permissions.php`, `RolePermissions.php`; `app/Modules/ProcurementStores/{Controllers/BillController.php, Models/Bill.php, Routes/api.php, Services/PurchaseOrderWorkflow.php}`; `app/Modules/Finance/Services/{FinanceWorkQueueService.php, TaxScheduleService.php}`; `routes/api.php`.
- **New:** `app/Modules/Finance/Controllers/PayablesController.php`, `Support/PayablesActions.php`; `database/migrations/2026_09_29_000001_add_payables_verification_permissions.php`; `app/Modules/ProcurementStores/Database/Migrations/2026_09_29_000002_add_return_for_correction_to_bills.php`.
- **Tests:** new `tests/Feature/Finance/PayablesWorkspaceTest.php`; fixture grants in `BillVerificationSegregationTest`, `DuplicateDetectionTest`, `StagedBillingTest`, `SupplierPaymentGateTest`, `SupplierInvoiceTaxTest`, `SupplierLedgerRailTest`.

### Frontend
- **New:** `src/modules/finance/payables/**` (client, spec, 4 components, 5 views).
- **Changed:** `modules/finance/{navigation.ts, navigation.spec.ts, shared/status.ts, overview/useFinanceOverview.ts, overview/views/FinanceOverviewView.vue, receivables/views/ProjectBillingView.vue}`, `router/finance.ts`, `modules/procurement-stores/views/procurement/{BillingShow.vue, BillingIndex.vue}`.

## 47. W2 Completion Matrix

| Capability | Status | Evidence |
|---|---|---|
| Purchase Requisition visibility | COMPLETE | chain + bill detail (number, status, approver) |
| PO visibility | COMPLETE | bill detail procurement basis |
| PO amendment | COMPLETE (visibility) / PRESERVED THROUGH EXISTING OPERATIONAL UI (editing) | original vs amendment; W2-4 routes unchanged |
| GRN | COMPLETE (Finance view) / PRESERVED (operational receiving) | receipt lines, receiver, confirmation |
| Service confirmation | BLOCKED BY BUSINESS POLICY | W2-8; stated on screen |
| Supplier Bill list | COMPLETE | projection + list tests |
| Supplier Bill detail | COMPLETE | detail tests |
| 3-way match | COMPLETE | backend checks and lines rendered |
| Staged billing | COMPLETE | position + correction cap test |
| Duplicate bill | COMPLETE | refusal/override tests |
| Return for Correction | COMPLETE | new; lifecycle test |
| Dedicated verification permission | COMPLETE | §51 tests, mutation-checked |
| Maker/checker | COMPLETE | preparer ≠ verifier / returner; preparer-only correction |
| WHT | COMPLETE | payable, liability account, WHT return fix |
| Supplier Payment | COMPLETE | payment form + tests |
| Partial payment | COMPLETE | tested |
| Duplicate payment | COMPLETE | refusal, permissioned override |
| Attachments | COMPLETE | reused W2-2; no paths |
| AP position | COMPLETE | reconciles to AP ledger (tested) |
| PO → Committed | COMPLETE | tested |
| GRN → Accrued | COMPLETE | tested |
| Bill → Actual | COMPLETE for direct bills (fixed); PO bills recognise ACTUAL at Stores issue, bill adds none (tested; §26, §45.1) | tests |
| Payment → No new cost | COMPLETE | tested (PO and direct) |
| My Actions | COMPLETE | three types, lifecycle test |
| Overview | COMPLETE | queue area + "Owed to suppliers" |
| Senior approval | PRESERVED; threshold BLOCKED BY BUSINESS POLICY | shown as unset |

## 48. Exact Next Stream

### STREAM D — W3 PETTY CASH + W5 FINANCE-FACING INVENTORY

Recommended first steps:

- **Carry-overs from Stream A (Report 56):** audit the petty-cash float/balance permission (`/petty-cash/balance` readable by anyone), the "Reset petty cash data" button (calls a withdrawn route), and `received` being labelled "Complete".
- **Requisition screen:** move the petty-cash requisition screen onto the shared status/money/error system. Keep "petty cash is settlement, not cost" (payment ≠ expense, as proven here for suppliers).
- **Inventory:** give Finance a read view of inventory valuation that reconciles to Inventory (Dr at GRN accrual) and COGS (Cr at issue), reusing `StoresFinancePosting`.
- **Consistency:** apply the same action-projection pattern (`*Actions::for…`) and the same §51-style role-name audit to any petty-cash control still keyed on role names.

Stream D was **not** started.

## 49. Final Verdict

### STREAM C COMPLETE — W2 REDESIGN READY FOR STREAM D

Finance can find, verify, return, pay and monitor supplier bills across projects on backend truth. The document chain is visible end to end. Verification is a permission and never a role name. Return for Correction exists with maker/checker. WHT is shown as the liability it is and now reaches the WHT return. Payments settle without adding cost, and direct bills finally reach project costing.

The open items are business-policy decisions whose mechanisms exist or whose absence is stated on screen (§44), plus documented follow-ups (§45) that do not affect W2's correctness.
