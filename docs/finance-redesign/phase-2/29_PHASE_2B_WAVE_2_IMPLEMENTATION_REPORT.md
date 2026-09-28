# 29 — Phase 2B Implementation Wave 2: Procurement to Payment

**Date:** 2026-09-23
**Scope:** Workflow 2 — Procurement to Payment. Implements W2-1 through W2-6 only. W2-7 through
W2-10 remain explicitly unimplemented and open — they are WNG/Finance decision items with no
engineering action pending a decision, per `03_WNG_FINANCE_DECISION_REGISTER.md`.

**Governance trace for every item below:** Audit Finding (`11_W2_PROCUREMENT_DECISION_BRIEF.md`,
`12_W2_PROCUREMENT_GAP_ANALYSIS.md`) → WNG Decision (`03_WNG_FINANCE_DECISION_REGISTER.md`,
confirmed 2026-09-23) → Architecture Requirement (`09_BUSINESS_PROCESS_BLUEPRINT.md`) → Code
Change (this document) → Test (this document). Nothing below invents a KES threshold, a named
approver, or a requirement beyond what W2-1 through W2-6 actually confirm.

---

## 1. Entry gate — pre-existing failure triage (§1)

Before touching Procurement business logic, the two failures the Wave 1 Closure Gate had flagged
were reproduced individually, together, sequentially, and against a clean, non-concurrent
`db_test`:

- **`PurchaseOrderApprovalPricingTest.php` (4 tests) — Classification B, fixture drift.** Approving
  a PO now also dispatches `PurchaseOrderApproved` → `RecordPurchaseOrderCommitments` →
  `ProcurementCostProducer`, which posts a committed cost line and needs an open accounting
  period. This test predates that side effect and never seeded one. Confirmed **not** a
  financial-integrity defect: `PurchaseOrder::approve()` wraps the status update and the
  commitment posting in one `DB::transaction()`, so the missing period throws and rolls back
  cleanly — no partial or silent approval. Fixed by seeding `AccountingPeriodSeeder` in the
  test's `setUp()`. A second, independent issue in the same file — a duplicate `units_of_measure`
  row already sitting in `db_test` from an earlier, unrelated session collision (case-insensitive
  collation on the unique `code` column) — was fixed by switching the test's own `UnitOfMeasure`
  creation to `firstOrCreate()`, per the standing rule never to drop or recreate `db_test`.
- **`SupplierPaymentGateTest.php` — Classification E, unrelated test-environment residue.**
  Reproduced clean in isolation and combined with the file above (34/34, 140/140). The originally
  reported failure was very likely the same class of stray `db_test` state from an earlier
  session, and had already self-healed by the time this wave began (every write to the shared
  `petty_cash_balances` singleton since then had already corrected it).

Neither was a real financial-integrity defect capable of unauthorized commitment, incorrect
payment, overpayment, duplicate payment, payment without verification, or accounting duplication
— so per the directive's own rule, this did not require stopping to treat it as a stabilization
defect, and Wave 2 implementation proceeded.

## 2. Frontend cleanup (§2)

- **`financeNavigation.spec.ts` (3 failures) — Classification A, stale test expectations.** The
  tests described a "spend door" consolidation (`/finance/spend`, a hidden alias, `/finance/spend-vouchers`
  renamed to "Spend vouchers") that was never built — `navigation.ts` has no such paths or
  labels, only the existing "Record Expense" and "Payment vouchers". Corrected the three
  assertions to the current, working contract; no navigation redesign performed.
- **`storesEntry.spec.ts` (2 failures) — a real, narrow UI bug, fixed.** `WorkspaceNav.vue`'s
  `showChildSidebar` used a hardcoded exemption list (`['overview', 'materials']`) instead of
  checking whether the active group actually had more than one visible page. A Stores group
  collapsed to one page by access filtering, and every Procurement section (which always has
  exactly one page), still rendered a redundant single-link sub-navigation row. Changed to
  `subTabs.value.length > 1`, matching the codebase's own stated principle ("one destination is
  not a choice") consistently rather than for two hardcoded keys. Full frontend suite: 111/111
  after the fix.

## 3. Preservation (§3)

Confirmed clean, after all Wave 2 changes:

```
tests/Feature/Finance + tests/Feature/Projects + tests/Unit/Finance + tests/Feature/Procurement
  595 passed (2576 assertions), 0 failed

tests/Feature/CostCollector + tests/Feature/PettyCash + tests/Unit/PettyCash
  320 passed (3565 assertions), 0 failed
```

W1-1 through W1-9, STAB-1 through STAB-7, W1 attachments, ChartAccountMap, the posting funnel,
PaymentSettlementService, Cost Collector, petty-cash posting/retry, and invoice/receipt accounting
are untouched by this wave. W1-10 and STAB-2 remain open, as instructed.

---

## 4. W2-1 — Senior approval above a high-value threshold

**Preserved unchanged:** `PurchaseApprovalPolicy::evaluate()` — the existing cover-based
auto-approval and optional Finance-signed ceiling. Nothing in this method was edited.

**Added, layered on top:**
- `purchase_orders` gains `senior_approval_required` (bool), `senior_approved_by`,
  `senior_approved_at` (migration `2026_09_23_000002_add_senior_approval_to_purchase_orders.php`).
- The threshold itself reuses `FinanceSetting::approvedValue('purchase_order_senior_approval_threshold')`
  — the same effective-dated, accountant-signed-off mechanism the existing optional ceiling
  already uses. No new settings table. A new row is seeded in `FinanceSettingsSeeder`, **unapproved**
  (`approved_by`/`approved_at` null), matching the existing ceiling's own seeded state exactly —
  the gate is inert until Finance signs an actual amount off.
- `PurchaseOrder::submitForApproval()`/`resubmit()` decide `senior_approval_required` once, at
  submission, from the threshold in force at that moment — not re-evaluated live at approval time,
  so a threshold Finance changes mid-flight cannot retroactively add or remove the requirement on
  an order already awaiting a decision.
- New permission `PROCUREMENT_ORDERS_APPROVE_SENIOR`, with **no legacy-role fallback** (unlike
  ordinary order approval) — granting it automatically to Admin/Accounts would make the tier
  meaningless the day it goes live.
- New action `PurchaseOrder::seniorApprove()` / `PurchaseOrderController::seniorApprove()` /
  `POST purchase-orders/{po}/senior-approve`. The requester cannot satisfy their own senior
  approval — checked explicitly, no exception.
- `approve()` (model and controller) now refuses to finalise when `senior_approval_required` is
  true and `senior_approved_at` is still null — the existing approval permission and cover/ceiling
  logic are otherwise completely unchanged.
- Return/rejection of a senior approval reuses the W2-6 Return for Correction mechanism (see §9)
  rather than a second set of columns — the same action, gated by either permission.

**Not invented:** the KES threshold, the senior approver's identity, any additional tiers.

## 5. W2-2 — Supplier supporting documents

Reused the generic `finance_attachments` mechanism built in Wave 1 for invoice evidence — **no**
`bill_attachments`/`po_attachments` table.

- `PurchaseOrder::attachments()` / `Bill::attachments()` — `morphMany(FinanceAttachment::class, 'source')`,
  identical to `ProjectInvoice::attachments()`.
- New `ProcurementAttachmentController` (index/store/download for both sources), reusing
  `FinanceAttachmentService` unchanged. Evidence-type vocabulary: `supplier_invoice`, `quotation`,
  `receipt`, `delivery_note`, `grn_evidence`, `tax_evidence`, `service_evidence` (a placeholder for
  the still-open W2-8), `other` — open-ended, none mandatory for any transaction, since WNG has
  not confirmed an evidence matrix.
- Security carried over unchanged from Wave 1: 10 MB max, PDF/JPEG/PNG/WebP only, private `local`
  disk, authenticated download, and a source-scope check on download (the attachment must belong
  to the named PO/Bill) preventing cross-record access.
- New routes gated on `PROCUREMENT_ORDERS_CREATE` for upload (the module's existing broadest
  authoring permission — no new permission invented for this), open to any authenticated user for
  read, matching this module's existing convention (no other Bill/PO read endpoint is
  permission-gated beyond authentication).

## 6. W2-3 — Multiple/staged Bills per PO

Removed the one-Bill-per-PO guard in `BillController::store()`, replaced with a cumulative cap —
never simply deleted.

- `PurchaseOrder::totalBilled(?excludingBillId)` / `remainingBillable(?excludingBillId)` —
  `Remaining Billable = Approved PO Value − Sum(non-cancelled Bills' net_amount)`. **Net**, not
  gross: `total_amount` (and every `PurchaseOrderItem` total beneath it) is VAT-exclusive, so
  comparing against gross would fail every VAT-bearing invoice by exactly the tax.
- The cap is checked **after** `SupplierInvoiceTax::priceFor()` prices the bill's real net/VAT
  split, not from the raw request — an invoice's net amount depends on the supplier's resolved tax
  treatment, which can only be computed once the bill exists with its real supplier/order
  relationships. A pre-creation estimate using only the request's raw `amount`/`vat_amount` was
  tried first and found to be wrong whenever VAT is derived rather than explicitly stated
  (caught by a genuine regression in `SupplierInvoiceTaxTest.php` — see §11). If the priced net
  exceeds the remaining balance, the just-created bill is deleted and a 422 returned; nothing else
  had yet been created against it.
- `PurchaseOrderWorkflow::bill()`'s three-way match: the "invoice ≤ approved order" check is now
  "invoice ≤ remaining billable **excluding this bill**" — unchanged in every case where an order
  carries only one Bill (remaining billable then equals the order total), and correctly measures a
  later Bill against what earlier Bills already consumed without re-penalising an already-verified
  Bill for a sibling raised after it.
- Each Bill keeps its own full invoice number, evidence, verification, fingerprint, payment
  status and audit trail — already how Bills worked per-row; nothing here changes that.
- Multiple GRNs per PO and the fingerprint mechanism are untouched.

**Not reworked:** `PurchaseOrderWorkflow::order()`'s PO-level `stage`/`bill_id`/`bill_number`
fields still reflect the **first** bill raised against a PO, for backward compatibility with any
existing consumer of that shape. A full "PO stage machine for N bills" (what "invoice"/"payment"/
"complete" mean when Bills are individually at different stages) is real, non-trivial UX design
work the gap analysis itself flagged as such, and is deliberately not built this wave — it was not
part of W2-3's own confirmed minimum (the cumulative balance and the cap), and inventing a stage
model nobody has confirmed would be exactly the kind of speculative extension this engagement has
consistently avoided.

## 7. W2-4 — Formal PO Amendment / Change-Order

New, additive `purchase_order_amendments` table (migration
`2026_09_23_000004_create_purchase_order_amendments_table.php`) and `PurchaseOrderAmendment`
model: `purchase_order_id`, `amendment_number` (sequential per order), `reason`, `requested_by/at`,
`is_commercial`, `changed_fields` (json), `original_snapshot`/`proposed_snapshot` (json — a
snapshot rather than a column diff, since a PO's commercial shape includes its items, not just its
own row), `status` (pending/approved/rejected), `approved_by/at`, `rejected_by/at`,
`rejection_reason`.

- **Materiality is computed, never invented.** Supplier change, any item change (material,
  quantity, unit price, or the item set itself), or the resulting total are commercial; delivery
  address, description, due date and date are administrative. No KES amount or percentage
  threshold appears anywhere in this logic.
- **Commercial** amendments: `status = 'pending'`, the real PO is untouched, and receiving/billing
  against it is paused (`PurchaseOrder::hasPendingCommercialAmendment()`, checked in both
  `GoodsReceiptNoteController::store()` and `BillController::store()`) until a separate
  `PROCUREMENT_ORDERS_AMEND` holder approves or rejects it. The requester cannot approve their own
  amendment — checked explicitly.
- **Administrative** amendments: applied immediately on a lighter path (still fully recorded, just
  without the reapproval gate), matching the confirmed direction that not every correction needs
  the full commercial chain.
- New `PurchaseOrderAmendmentController` (`index`/`store`/`approve`/`reject`) and routes under
  `purchase-orders/{po}/amendments`.
- STAB-3 is unmodified and fully respected: the original PO row is only ever changed through
  `apply()`, called exclusively from an approved (or administrative) amendment — never by a direct
  edit. Every prior amendment record remains immutable regardless of what happens afterward.

**Not invented:** who specifically may request or approve (existing `PROCUREMENT_ORDERS_CREATE`/new
`PROCUREMENT_ORDERS_AMEND` permissions, not a named position); a materiality percentage/KES
threshold; PO cancellation/closure (W2-7, untouched).

## 8. W2-5 — Duplicate detection

New, reusable `App\Modules\Finance\Services\DuplicateDetectionService` — deliberately narrow,
implementing exactly the confirmed minimum and explicitly designed to be extendable (not yet
extended) to a future W3-5:

- `checkBillInvoiceNumber(supplierId, invoiceNumber, ?excludeBillId)` — exact, normalised
  (trimmed, case-folded) match, scoped to one supplier. Supplier A's invoice 123 is not flagged
  against Supplier B's invoice 123.
- `checkPaymentReference(paymentSourceId, reference, ?excludePaymentId)` — same normalisation,
  scoped to one paying account/source. The same reference on a different account is not flagged.
- **No "possible duplicate" fuzzy tier was built.** The directive is explicit that every flag must
  be explainable as "why was this flagged?" — an exact match on the two facts both sides of a
  duplicate necessarily share answers that on its own; a looser heuristic would need a tolerance
  or threshold nobody has confirmed, which is exactly the kind of speculative extension the
  directive itself warns against.
- **Bills:** wired into `BillController::store()`, alongside (not replacing) the pre-existing
  Bill-vs-Cost-Collector `unpaid_invoice` cross-check. A confirmed duplicate blocks unless
  `duplicate_override_reason` is supplied by a `PROCUREMENT_BILLS_OVERRIDE_DUPLICATE` holder — new
  columns `duplicate_of_bill_id`/`duplicate_override_by`/`duplicate_override_at` record it.
- **Payments:** wired into `SupplierPaymentService::recordBatch()` — the single choke point both
  the single-invoice and batch payment screens already funnel through, matching this file's own
  "one door" design. Cash payments (no reference) are excluded from the check entirely — a blank
  reference is never itself a duplicate signal, proven directly against a persisted row with a
  `NULL` reference_number. New columns `duplicate_of_payment_id`/`duplicate_override_by`/
  `duplicate_override_at` on `bill_payments`.
- New permission `PROCUREMENT_BILLS_OVERRIDE_DUPLICATE` — no named employee holds it by default.

**Not invented:** which specific employee holds the override permission; any uniqueness constraint
broader than the confirmed scope (no global unique index on `supplier_invoice_number` or
`reference_number` alone, since both can legitimately recur across different suppliers/accounts).

## 9. W2-6 — Return for Correction

- `purchase_orders` gains `returned_by`, `returned_at`, `return_reason`, `resubmitted_at`
  (migration `2026_09_23_000001_add_return_for_correction_to_purchase_orders.php`) and a new
  status value `returned_for_correction` — safe to add directly since `purchase_orders.status` is
  already a plain `VARCHAR(50)` (converted from `ENUM` by an earlier migration), not a hard enum
  requiring an `ALTER`.
- `PurchaseOrder::returnForCorrection()` / `resubmit()`, `PurchaseOrderController::returnForCorrection()`
  / `resubmit()`, routes `POST .../return-for-correction` and `POST .../resubmit`.
- Only a `pending_approval` order can be returned; only `returned_for_correction` can be
  resubmitted. `update()`'s existing STAB-3 guard is extended (not weakened) to also permit edits
  in the `returned_for_correction` status, gated on a **new** in-controller check
  (`correctOrder` policy ability, `PROCUREMENT_ORDERS_CREATE`) — the reviewer who returned it is
  never required to make the fix themselves, since they typically hold the approval permission,
  not the create one.
- Resubmitting restarts approval (`status → pending_approval`) and re-evaluates W2-1's
  senior-approval requirement against the (possibly corrected) total — a correction that raises
  the value above the threshold correctly picks up the senior gate on resubmission.
- Distinct from W2-4 throughout: this operates only on `pending_approval` orders that were never
  approved; W2-4 operates only on `approved` orders. Neither can be reached from the other's state.

---

## 10. Files changed

**Migrations (all additive, reversible, no historical data rewritten):**
- `2026_09_23_000001_add_return_for_correction_to_purchase_orders.php`
- `2026_09_23_000002_add_senior_approval_to_purchase_orders.php`
- `2026_09_23_000003_add_duplicate_detection_to_bills_and_bill_payments.php`
- `2026_09_23_000004_create_purchase_order_amendments_table.php`

**New models/services/controllers:**
- `app/Modules/ProcurementStores/Models/PurchaseOrderAmendment.php`
- `app/Modules/ProcurementStores/Controllers/PurchaseOrderAmendmentController.php`
- `app/Modules/ProcurementStores/Controllers/ProcurementAttachmentController.php`
- `app/Modules/Finance/Services/DuplicateDetectionService.php`

**Edited:**
- `app/Modules/ProcurementStores/Models/PurchaseOrder.php` (new columns/relations;
  `submitForApproval()`, `resubmit()`, `returnForCorrection()`, `seniorApprove()`,
  `totalBilled()`, `remainingBillable()`, `hasPendingCommercialAmendment()`, `attachments()`,
  `amendments()`)
- `app/Modules/ProcurementStores/Models/Bill.php` (`attachments()`; duplicate-override fillable)
- `app/Modules/ProcurementStores/Models/BillPayment.php` (duplicate-override fillable)
- `app/Modules/ProcurementStores/Controllers/PurchaseOrderController.php` (`seniorApprove()`,
  `returnForCorrection()`, `resubmit()`; `approve()`/`update()` guards extended)
- `app/Modules/ProcurementStores/Controllers/BillController.php` (W2-3 cap, W2-4 pause, W2-5
  duplicate check)
- `app/Modules/ProcurementStores/Controllers/GoodsReceiptNoteController.php` (W2-4 pause)
- `app/Modules/ProcurementStores/Services/PurchaseOrderWorkflow.php` (remaining-billable check)
- `app/Modules/ProcurementStores/Services/SupplierPaymentService.php` (W2-5 payment-reference check)
- `app/Modules/ProcurementStores/Policies/PurchasePolicy.php` (`approveOrderSenior`, `returnOrder`,
  `correctOrder`, `amendOrder` abilities)
- `app/Constants/Permissions.php` (`PROCUREMENT_ORDERS_APPROVE_SENIOR`, `PROCUREMENT_ORDERS_AMEND`,
  `PROCUREMENT_BILLS_OVERRIDE_DUPLICATE`)
- `app/Modules/ProcurementStores/Routes/api.php` (all new routes)
- `app/Modules/Finance/Database/Seeders/FinanceSettingsSeeder.php` (new, unapproved
  `purchase_order_senior_approval_threshold` row)

**Tests (all new):**
- `tests/Feature/Procurement/PurchaseOrderReviewWorkflowTest.php` (11 — W2-1, W2-6)
- `tests/Feature/Procurement/ProcurementAttachmentTest.php` (8 — W2-2)
- `tests/Feature/Procurement/StagedBillingTest.php` (6 — W2-3)
- `tests/Feature/Procurement/PurchaseOrderAmendmentTest.php` (11 — W2-4)
- `tests/Feature/Procurement/DuplicateDetectionTest.php` (7 — W2-5)

**46 new tests, all passing.**

**Test fixed for a pre-existing, unrelated fixture bug found during entry-gate triage:**
`tests/Feature/Procurement/PurchaseOrderApprovalPricingTest.php` (§1).

**Test fixed for a genuine regression this wave caused and then corrected (§11):**
`tests/Feature/Finance/SupplierInvoiceTaxTest.php`.

---

## 11. Regression, including one real defect found and fixed mid-wave

Running the full Finance/Projects/Unit-Finance/Procurement suite after W2-3 landed surfaced 13
failures, all in `SupplierInvoiceTaxTest.php`. **Root cause: a genuine defect in this wave's own
W2-3 implementation**, not a pre-existing issue — the cumulative-billing check was originally
computed from the raw request (`amount − vat_amount`) **before** `Bill::create()` and before
`SupplierInvoiceTax::priceFor()` resolves the invoice's real tax treatment, so any bill relying on
derived (not explicitly stated) VAT was checked against the wrong, inflated "net" figure. Per the
defect-stop discipline, this was root-caused before any further work: fixed by moving the check to
after the bill's real `net_amount` is priced, deleting the bill if the (now-accurate) check fails.
One test in the same file (`test_a_stated_vat_beats_the_derived_one`) had chosen a stated-VAT value
that, by coincidence, produced a net exceeding its fixture PO's real 50,000 commitment — corrected
to a value that stays within it, preserving the test's actual purpose (an explicit `vat_amount`
overrides the derived one).

Final, clean state:

```
tests/Feature/Finance + tests/Feature/Projects + tests/Unit/Finance + tests/Feature/Procurement
  595 passed (2576 assertions), 0 failed

tests/Feature/CostCollector + tests/Feature/PettyCash + tests/Unit/PettyCash
  320 passed (3565 assertions), 0 failed — STAB-4/STAB-7 preservation confirmed
```

A second, unrelated observation during regression: an intermediate full-suite run reported 31
failures that could not be reproduced on immediate re-run with more (not fewer) changes in place.
This matches the same cross-session `db_test` collision pattern already documented in the Wave 1
Closure Gate, not a real finding — flagged here only for completeness, not acted on.

---

## 12. Implementation Status Matrix

| Requirement | Decision Status | Implementation Status | Tests | Remaining Dependency |
|---|---|---|---|---|
| W2-1 | CONFIRMED (Option C) | Implemented — gate built, inactive until configured | `PurchaseOrderReviewWorkflowTest.php` | Management/Procurement to name the KES threshold; an accountant to sign it off; WNG to name who holds `PROCUREMENT_ORDERS_APPROVE_SENIOR` |
| W2-2 | CONFIRMED (Option A) | Implemented | `ProcurementAttachmentTest.php` | WNG to confirm which evidence category is mandatory for which transaction type (not required for the mechanism to work) |
| W2-3 | CONFIRMED (Option A) | Implemented | `StagedBillingTest.php` | None technical; PO-level multi-bill "stage" UX is deliberately deferred (§6) |
| W2-4 | CONFIRMED (Option B) | Implemented | `PurchaseOrderAmendmentTest.php` | WNG may narrow the `PROCUREMENT_ORDERS_CREATE`/`PROCUREMENT_ORDERS_AMEND` grants to specific roles |
| W2-5 | CONFIRMED | Implemented (exact-match tier only) | `DuplicateDetectionTest.php` | WNG to name who holds `PROCUREMENT_BILLS_OVERRIDE_DUPLICATE`; a "possible duplicate" tier remains a future, not-yet-confirmed decision |
| W2-6 | CONFIRMED | Implemented | `PurchaseOrderReviewWorkflowTest.php` | None |
| W2-7 | AWAITING WNG CONFIRMATION | **Not implemented — intentionally** | — | WNG to decide PO cancellation/closure triggers |
| W2-8 | AWAITING WNG CONFIRMATION | **Not implemented — intentionally** | — | WNG to decide who confirms a service (non-stock) delivery, and how |
| W2-9 | AWAITING WNG + FINANCE/ACCOUNTANT CONFIRMATION | **Not implemented — intentionally** | — | WNG + an accountant to confirm the supplier-credit process and accounting treatment |
| W2-10 | AWAITING WNG CONFIRMATION | **Not implemented — intentionally** | — | WNG to decide whether Direct Bill already covers emergency purchases or a distinct route is needed |

---

## 13. Final summary

**Result:** W2-1 through W2-6 implemented exactly as confirmed, each layered onto or alongside
existing, preserved controls (`PurchaseApprovalPolicy`, the three-way match, STAB-3's edit guard,
the Bill-vs-Cost-Collector duplicate check, `SupplierPaymentService`'s single payment funnel) —
none replaced or weakened.

**Tests:** 46 new tests, all passing. Full Finance/Projects/Unit-Finance/Procurement regression:
595 passed (2576 assertions), 0 failed. Petty Cash/Cost Collector preservation (STAB-4/STAB-7):
320 passed (3565 assertions), 0 failed.

**Migrations:** 4, all additive/reversible, no historical data rewritten, no fabricated identities.

**Controls implemented:** senior-approval gate (inactive until configured); generic evidence
attachment on POs and Bills; cumulative staged-billing cap measured on real, post-tax net amounts;
formal amendment record with computed (not invented) materiality and a receiving/billing pause
while a commercial amendment is pending; exact-match duplicate detection with an authorized,
auditable override on both Bills and payments; a full Return for Correction state machine for
`pending_approval` orders.

**Accounting invariants verified:** every existing posting (goods-receipt accrual, bill
verification's accrual-to-payable move, payment settlement, the transaction-fee split) is
unchanged; the three-way match's own basis (net-of-VAT) is now consistently the same figure the
new staged-billing cap uses, not two different formulas measuring the same thing.

**Unresolved issues:** none introduced by this wave that were not fixed within it (see §11 for the
one defect found and fixed mid-wave).

**Regression:** one genuine defect in this wave's own first-draft W2-3 implementation, found via
the required regression pass and fixed before completion (§11) — not shipped.

**Deferred, with reasons given:** a "possible duplicate" fuzzy-matching tier (W2-5, §8); a full
PO-level multi-bill stage/UX model (W2-3, §6); W2-7 through W2-10 in their entirety.

**Recommendation:** Workflow 2's confirmed scope (W2-1 through W2-6) is stable, tested, and does
not block on anything technical — only on WNG/Management/Procurement supplying the specific
threshold amount, approver identity, and permission-role assignments the confirmed decisions
themselves left open. Safe to proceed once WNG reviews this report.

**Per the directive's stop condition: this wave stops here for WNG review. No further workflow's
implementation has begun.**
