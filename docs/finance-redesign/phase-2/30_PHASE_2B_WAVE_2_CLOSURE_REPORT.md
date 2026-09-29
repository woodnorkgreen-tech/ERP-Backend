# 30 — Phase 2B Wave 2 Closure Report: Procurement to Payment

**Date:** 2026-09-23
**Type:** Independent verification and closure gate — not a re-implementation. Per the closure
directive, `29_PHASE_2B_WAVE_2_IMPLEMENTATION_REPORT.md` was **not** accepted as proof by itself;
every claim below was checked against the actual migrations, models, controllers, services,
policies, routes and tests, and the full regression suite was re-run.

---

## 1. Scope

Verified: W2-1 through W2-6 and every shared control they introduced or reused; preservation of
STAB-1 through STAB-7; preservation of W1-1 through W1-9; accounting/project-cost invariants
Procurement touches. Not implemented or decided: W2-7 through W2-10, W3+, W1-10, STAB-2, historical
STAB-7 remediation, Fully Loaded Margin, or any dashboard/report redesign.

## 2. Independent code verification

Directly inspected (not merely re-read from the implementation report): `PurchaseOrder`,
`PurchaseOrderItem`, `PurchaseOrderAmendment`, `Bill`, `BillPayment` models; `PurchaseOrderController`,
`PurchaseOrderAmendmentController`, `ProcurementAttachmentController`, `BillController`,
`GoodsReceiptNoteController`; `PurchaseOrderWorkflow`, `SupplierPaymentService`,
`ProcurementCostProducer`, `CostCollectorService`, `DuplicateDetectionService`; `PurchasePolicy`;
all 5 Wave-2 migrations plus the one added during this closure pass; `PurchaseOrderObserver`,
`RecordPurchaseOrderCommitments`; the full Procurement routes file; `ProcurementStoresServiceProvider`
(to find the module's actual middleware/authorization boundary); and every JournalPostingService
comment describing the Bill verification/payment posting sequence. Every backend test file under
`tests/Feature/Procurement`, plus `tests/Feature/Finance/SupplierInvoiceTaxTest.php`, was read in
full, not just run. The frontend was searched directly for any UI wired to the new endpoints.

Where the implementation report's claims and actual code behaviour disagreed, actual behaviour won
— four such disagreements were found (§9, §14 below) and are documented as defects, not silently
reconciled.

## 3. W2-1 result — PASS

`PurchaseApprovalPolicy`'s cover/ceiling logic is untouched. Verified directly: an unconfigured
threshold never triggers the gate (`FinanceSetting::approvedValue()` requires an accountant
sign-off, not just a value); a configured threshold does; the requirement is decided once at
submission/resubmission, not re-evaluated live; a normal approver cannot bypass a required senior
approval (`approve()` throws first); the requester cannot satisfy their own senior approval
(explicit id check); Return for Correction works from a senior-pending order; resubmission
re-evaluates the threshold. **Boundary conditions added and confirmed during closure** (not present
in the Wave 2 test suite): an order at exactly the threshold does **not** require senior approval —
only the first shilling past it does, matching the strict `> 0` comparison in code.

No defect found. Not invented: the KES threshold, the senior approver's identity.

## 4. W2-2 result — PASS, with one documented Medium finding

The generic `finance_attachments` mechanism is genuinely reused — no second file table exists.
Verified directly: upload to both PO and Bill; authenticated download; unsupported MIME rejected
(pre-existing `FinanceAttachmentService` validation); oversized file rejected (**test added during
closure** — was previously asserted only for Wave 1's invoice path, not Procurement's); a PO's
attachment id cannot be fetched through a Bill's download route and vice versa (**both directions
tested during closure** — only the same-type, cross-id case existed before); private storage and
`uploaded_by`/`uploaded_at` confirmed unchanged from Wave 1.

**Finding (Medium, pre-existing, not a Wave-2 regression):** `poIndex`/`poDownload`/`billIndex`/
`billDownload` have no permission check beyond the module's blanket `auth:sanctum`+`active`
middleware — any authenticated, active employee can list or download any PO's or Bill's evidence.
Independently confirmed this is **not** unique to the new attachment endpoints: `PurchaseOrderController::index()`
and the pre-existing `downloadPdf()` (which already exposes supplier and pricing detail as a PDF)
have exactly the same absence of a permission check. This is the whole Procurement module's existing
authorization convention, inherited rather than introduced by W2-2. It is flagged here because
evidence attachments raise the sensitivity of what that convention exposes (raw supplier invoices,
tax documents) — but unilaterally adding a new permission gate to only the attachment endpoints,
while every sibling PO/Bill read endpoint stays open, would create an inconsistent half-measure
nobody asked for. Recommend WNG decide whether Procurement/Finance evidence specifically should be
narrower than the module's general read visibility; not fixed in this closure pass.

## 5. W2-3 result — PASS, after one High-severity defect found and fixed

Reproduced directly: PO = 100,000 (net); Bill 1 = 60,000; Bill 2 = 40,000 → both accepted,
`remainingBillable()` = 0; Bill 3 for any amount is rejected with the correct remaining balance
named in the error. VAT-exempt, derived-VAT and explicitly-stated-VAT invoices all measured
correctly (net-of-tax, matching the three-way match's own basis). A cancelled Bill correctly frees
its share back. Re-verifying one Bill is unaffected by a sibling raised after it.

**Defect found (High) — §6/§18 of the closure directive, reproduced and fixed:** the
*pre-existing* three-way-match check "invoice does not exceed the value accepted into stock" was
written when only one Bill could ever exist per order, and was never made cumulative-aware when
W2-3 allowed several. Reproduced exactly as the directive's own worked example: PO = 100 units,
GRN accepts only 40. Three Bills of 20,000/20,000/1,000 (41,000 total) each **individually** passed
the check (each ≤ 40,000 accepted), because the check compared each Bill's own amount against the
*raw* accepted value rather than what remained of it after other Bills. Fixed:
`PurchaseOrderWorkflow::bill()` now measures each Bill against the accepted value **not yet
consumed by any other valid Bill** (`accepted_value − totalBilled(excluding this bill)`), the exact
same exclusion pattern already used for the order-total check. Confirmed via a new test built on a
genuinely partial receipt — before the fix this reproduced the over-verification; after, the third
Bill is correctly blocked at verification. This was a real, if narrow, weakening of the existing
match caused indirectly by W2-3, not present before it.

**Bill-rejection cleanup confirmed clean:** the cumulative-value check runs after `Bill::create()`
but before any GRN linkage, CostLine, attachment or match record is ever created against that Bill,
so a Bill rejected by either cap (order-total or, now, accepted-value) leaves nothing else to clean
up — `$bill->delete()` is a complete rollback in every case checked.

## 6. W2-4 result — PASS, after one CRITICAL defect found and fixed

**Defect found (CRITICAL) — reproduced directly before any fix was attempted:**
`PurchaseOrderAmendmentController::apply()` deleted and recreated **every** `PurchaseOrderItem` row
on **every** approved amendment — including a purely administrative one that touched no item field
at all — assigning each surviving item a brand-new primary key. Two independent consequences,
both reproduced:

1. Any existing Goods Receipt Note item or CostLine referencing the old item id was silently
   orphaned (pointing at a now-deleted row), for *any* commercial or administrative amendment.
2. The committed CostLine never followed the revised value at all. A dedicated test
   (`PurchaseOrderAmendmentCommitmentTest`) approved a real 100,000 PO via the actual approval
   path (dispatching the real commitment-posting event), then approved a commercial amendment to
   120,000: the measured effective commitment came back **`0`** — neither the correct 120,000 nor
   the stale 100,000 — because the query for "this order's current items' commitments" found
   nothing (the items behind the original commitment no longer existed) while the original,
   now-orphaned CostLine sat untouched at the old value under a deleted item's id.

**Fixed:**
- `apply()` now updates existing items in place by id (preserving every FK/JSON reference to them);
  only an item genuinely removed from the proposed set is deleted, only one with no id at all is a
  new insert.
- A new `ProcurementCostProducer::reconcileAmendedCommitments()` releases every original item's
  live commitment and reposts fresh ones at the revised quantity/price when an amendment changes
  items, using a fresh `source_ref` per amendment (the collector's idempotency key does not
  distinguish by status, so reusing the original key would just hand back the reversed row —
  mirrored from the same pattern `postGoodsReceipt()` already uses for exactly this reason).
- Confirmed by direct test for **both** directions: 100,000→120,000 now measures 120,000
  committed; 120,000→90,000 now measures 90,000 — not the closure directive's failure modes of
  remaining at 100,000 or doubling to 220,000.

**Additional gap found and closed while fixing the above (§9):** the reconciliation above is only
correct when nothing has yet been received or billed against the changed items — reconciling a
*partially* consumed order's commitment has no WNG-confirmed treatment (what happens to an accrual
already posted against the pre-amendment quantity?). Rather than approximate this, an item-level
change is now blocked outright once the order has any Goods Receipt Note or any Bill against it
(confirmed by test in both directions); a non-item commercial change (e.g. supplier) remains
available regardless. A revised total may also never fall below what is already billed — checked
explicitly, though structurally this can currently only ever be reached via an item change, which
the guard above already blocks; kept as an explicit, named check of the exact invariant the
directive asks for, not as dead code.

**§7 (second amendment / history) confirmed by test:** a second amendment on the same order (raising
75,000→90,000 after a first 60,000→75,000, on a 10-item PO) leaves the first amendment's
`original_snapshot`/`proposed_snapshot`/`approved_by`/`approved_at` completely unchanged.

**§10 (administrative amendments) confirmed:** with the item-identity fix above, an administrative
amendment (delivery address, description, due date, date) never touches an item row at all — the
snapshot-equality check on unsupplied items now results in a genuine no-op update rather than a
churn of every item's primary key. It never alters commitment, accounting, supplier, quantities or
prices, and never bypasses commercial approval — none of those fields are in
`ADMINISTRATIVE_HEADER_FIELDS`, and the classification is field-identity-based, never an invented
threshold.

## 7. W2-5 result — PASS, one test-coverage gap closed (not a behavioural defect)

Normalization (`inv-001`/`INV-001`/`INV-001` all match; `Supplier B` + the same number does not)
confirmed. Confirmed duplicates block; an unauthorized override (no permission, or a reason with no
permission) is refused; an authorized override with a reason succeeds. Payment duplicate detection
traced to its actual single choke point, `SupplierPaymentService::recordBatch()`, confirmed to be
where both the single-invoice and batch payment screens funnel through. Same reference + same
account blocked; same reference + a different account allowed; a blank/cash reference never
matches an existing `NULL` reference row.

**Gap found (test coverage, not behaviour):** the Wave 2 test suite asserted `duplicate_of_bill_id`
and `duplicate_override_by`/`_at` after an authorized override, but never asserted the **reason
text itself** was durably retained — exactly the risk the closure directive named ("if the reason
is accepted only in the request... classify this as a control gap"). Independent code inspection
found the reason genuinely is persisted correctly in both the Bill path (via `$request->all()`
mass-assignment, since the column is fillable) and the Payment path (explicitly assigned from
`$data['duplicate_override_reason']`) — this was a real gap in what had been *proven*, not in what
had been *built*. Closed with new assertions/tests confirming the exact reason string round-trips
for both Bill and Payment overrides.

No "possible duplicate" fuzzy tier exists — confirmed still deliberately excluded, per the Wave 2
report's own stated reasoning (no explainable rule without an invented threshold).

## 8. W2-6 result — PASS, after one incomplete-implementation gap found and fixed

Draft → Pending Approval → Returned for Correction → Corrected → Resubmitted → Approval confirmed
end-to-end. `returned_by`/`returned_at`/`return_reason`/`resubmitted_at` all correctly recorded.
Only the requester (holding `PROCUREMENT_ORDERS_CREATE`), not the reviewer who returned it, may
correct a returned order — confirmed by test (`test_a_reviewer_without_the_create_permission_cannot_correct_a_returned_order`).

**Defect found (Medium) — reproduced exactly as the directive anticipated:** before this closure
pass, only the *final* corrected values survived a correction — nothing recorded what the approver
actually saw and rejected, or what specifically the requester changed. **Fixed** with a new,
deliberately separate `purchase_order_corrections` table (not the amendment mechanism, which
answers a different question — an already-approved order — at a different, separately-gated
moment): `returnForCorrection()` snapshots the order's commercial shape at the moment of return
(what was submitted and rejected); `resubmit()` snapshots it again and closes the cycle. Confirmed
by test: the snapshot correctly shows the *pre*-correction value on `previous_snapshot` and the
*post*-correction value on `corrected_snapshot`, and a **second** return/correct cycle on the same
order leaves the first cycle's record completely untouched (multi-cycle history genuinely
preserved, not overwritten).

## 9. STAB-3 interaction — confirmed safe

`PurchaseOrderController::update()`'s guard still blocks direct edits to every status except
`pending` and, now, `returned_for_correction` — re-confirmed by the full, unmodified
`PurchaseOrderMutabilityTest.php` suite (approved/delivered orders still refuse edits; a pending
order still edits freely; delete guards on linked Bill/GRN unchanged).

## 10. Commitment / Cost Collector verification

The mandatory §8 test is described in full in §6 above. Summary: upward amendment (100,000→120,000)
now correctly measures 120,000 committed; downward (120,000→90,000) now correctly measures 90,000;
neither remains stale nor doubles. This was the single most severe finding of this closure pass and
is fixed and regression-tested.

## 11. Supplier accounting verification

Traced directly from `JournalPostingService`'s own documented sequence (unmodified by Wave 2):

```
On verification:  Dr 2150 Accrued Expenses   net           / Cr 2100 Accounts Payable   net+vat−wht
On payment:        Dr 2100 Accounts Payable   net+vat−wht  / Cr <payment source>
```

Worked example (net 100,000, VAT 16,000, no WHT): goods receipt already posted Dr Raw-material
Inventory 100,000 / Cr Accrued Expenses 100,000 at GRN time (unchanged by Wave 2). Verification
moves Dr Accrued Expenses 100,000 / Cr Accounts Payable 116,000 — reclassifying the existing
accrual into a specific payable, not a second expense recognition. Payment moves Dr Accounts
Payable 116,000 / Cr Bank 116,000 — settling the liability, never touching an expense account
again. No Wave 2 change touches `JournalPostingService`; this sequence is exactly as it was, and
the existing `SupplierPaymentGateTest`/`StagedBillingTest` suites (37 + 9 tests, all passing) cover
it directly with real journal-entry assertions.

## 12. Permission review

| Action | Gate | Legacy/fallback | Self-approval guard |
|---|---|---|---|
| Create PO / propose amendment / correct a returned PO | `PROCUREMENT_ORDERS_CREATE` | `correctOrder()` also accepts Super Admin/Admin/Accounts | n/a |
| Approve PO | `PROCUREMENT_ORDERS_APPROVE` | Super Admin/Admin/Accounts | requester (`user_id`) blocked unless `self-approve` granted |
| Senior-approve PO (W2-1) | `PROCUREMENT_ORDERS_APPROVE_SENIOR` | **none — deliberate** | requester blocked, no exception |
| Return PO for correction (W2-6) | `PROCUREMENT_ORDERS_APPROVE` (same ability as approve) | Super Admin/Admin/Accounts | n/a |
| Approve/reject PO amendment (W2-4) | `PROCUREMENT_ORDERS_AMEND` | none | amendment's own requester blocked |
| Upload Procurement/Bill evidence (W2-2) | `PROCUREMENT_ORDERS_CREATE` | n/a | n/a |
| **Read/download Procurement/Bill evidence (W2-2)** | **none beyond auth+active** | n/a | n/a — see §4 finding |
| Override a duplicate Bill/Payment (W2-5) | `PROCUREMENT_BILLS_OVERRIDE_DUPLICATE` | none | n/a |
| Verify a supplier Bill (pre-existing) | "Accounts" role (`canVerify()`) | — | recorder blocked unless `self-approve` granted |
| Record a direct/cash supplier payment (pre-existing) | `FINANCE_PETTY_CASH_CREATE` | — | n/a |

**Finding (Medium, pre-existing pattern, not Wave-2-specific):** `correctOrder()` grants access via
`PROCUREMENT_ORDERS_CREATE` **or** the same legacy-role fallback (`Super Admin`/`Admin`/`Accounts`)
that also satisfies ordinary approval and return authority. The self-approval guard on `approve()`
checks against the order's *original requester* (`user_id`), not against whoever corrected it — so
an Accounts/Admin-role user could in principle return an order, correct it themselves, and then
approve their own correction, with no code-level block on that specific sequence. This mirrors the
identical, intentional legacy-role bridge already used for `approveOrder`/`deleteOrder`/`returnOrder`
(documented as transitional, "so nobody loses access before `permissions:sync` has run"), not a new
pattern introduced for W2-6. Not fixed in this closure pass — flagged for WNG as role assignments
are formalized; a real fix would need a `corrected_by`-vs-approver self-check, deliberately not
added here since narrowing legacy-role grants is a role-governance decision, not a code defect.

No dangerous overlap found between the *new* W2-1/W2-4/W2-5 permissions and any pre-existing
approval ability — each is independently gated with no legacy-role bridge, and each excludes its
own requester explicitly.

## 13. UI verification

Searched the frontend directly (not inferred from the backend report) for any component wired to
`senior-approve`, `return-for-correction`, `resubmit`, `/amendments`, `/attachments`, or
`duplicate_override_reason`. **None exist.** The only Finance/Procurement attachment UI in the
frontend is Wave 1's own invoice-evidence modal; nothing calls the new Procurement/Bill attachment
endpoints. Every one of W2-1 through W2-6's new interactive actions is reachable only via direct
API call today.

**Classification for all six: Backend implemented / UI incomplete** — not closed as fully
implemented. This is a materially different position from Wave 1 in this respect and should be
weighed in the closure decision below: Procurement/Accounts staff cannot yet exercise any of these
controls through the application.

## 14. Regression

Re-run sequentially, no concurrent Laravel processes, after a `db_test` collision was caused (and
fully recovered from, via the documented `export-db`/`import-db` clone) by an accidental concurrent
test invocation partway through this closure pass — noted here for completeness, not as a code
finding.

```
tests/Feature/Finance + tests/Feature/Projects + tests/Unit/Finance + tests/Feature/Procurement
  + tests/Feature/CostCollector + tests/Feature/PettyCash + tests/Unit/PettyCash
  + tests/Feature/Hr/PayrollRecurringRangeTest.php + PayrollLabourSplitTest.php + PayrollIntegrityTest.php

  955 passed (6303 assertions), 0 failed, 0 skipped
```

Frontend (`npm run test:unit`, full suite, unaffected by this closure pass — no frontend file was
touched): **111 passed (111), 0 failed**, including the specific `financeNavigation.spec.ts` (6)
and `storesEntry.spec.ts` (15) fixed during the Wave 1 closure gate, re-confirmed still clean.

No pre-existing unrelated failure was hidden or relabelled; the one failure surfaced mid-pass
(`SupplierPaymentGateTest > an invoice above the value accepted into stock is refused`) was this
closure pass's own §5 fix changing a check's label text, corrected in the same test.

## 15. Defects found and fixed during this closure pass

| # | Severity | Item | Defect | Fix | Status |
|---|---|---|---|---|---|
| 1 | **Critical** | W2-4 | Approving any amendment (even administrative) deleted/recreated all PO items, orphaning GRN/CostLine references and leaving the committed CostLine stale or, after the fix's first pass, at the wrong value entirely (measured `0`) | Item identity preserved (update in place); new `reconcileAmendedCommitments()` correctly re-posts commitments; item changes blocked once GRN/Bill exist | **Fixed, regression-tested** |
| 2 | High | W2-3/§18 | Three-way match's "invoice ≤ accepted value" check was not cumulative once multiple Bills were allowed — staged Bills could jointly bill more than was received | Check now measures against accepted value minus other valid Bills | **Fixed, regression-tested** |
| 3 | Medium | W2-6/§14 | No record of what changed during a PO correction — only final values survived | New `purchase_order_corrections` table with previous/corrected snapshots per cycle | **Fixed, regression-tested** |
| 4 | Medium | W2-5/§11 | Override-reason persistence was implemented correctly but never actually asserted by a test | Assertions/tests added for both Bill and Payment override paths | **Fixed (test coverage), no code change needed** |
| 5 | Medium | §12/§21 | Procurement/Bill evidence read+download has no permission gate beyond authentication — pre-existing module-wide convention, inherited by new attachment endpoints | Not fixed — flagged for WNG; fixing only the attachment endpoints would be an inconsistent half-measure against the rest of the module | **Documented, open** |
| 6 | Medium | §21 | `correctOrder()`'s legacy-role fallback overlaps with approve/return authority; no self-check against who *corrected* an order, only who originally *requested* it | Not fixed — mirrors an existing, intentional transitional pattern; narrowing it is a role-governance decision | **Documented, open** |
| 7 | Low/systemic | §13/§22 | All of W2-1 through W2-6 have zero frontend UI | Not fixed — out of this closure pass's bounded scope; each item classified "Backend implemented / UI incomplete" | **Documented, open** |

## 16. W2-7 to W2-10 evidence for WNG decision

No new evidence was gathered on these this pass (out of scope); restated from the confirmed record
for completeness:

| Item | Current behaviour | Actual gap | Operational consequence | Decision still required |
|---|---|---|---|---|
| W2-7 (PO cancellation/closure) | No cancellation/closure action exists on a PO | A wrong or abandoned PO has no formal end state | Stale open POs accumulate; nothing signals "this will never be received" | Who may cancel, at what stage, whether partial cancellation of unreceived lines is needed |
| W2-8 (service confirmation) | Only a physical Goods Receipt Note exists; no non-stock service-delivery confirmation | A service PO cannot be billed/paid through the same three-way match as a goods PO | Service invoices likely bypass matching entirely today | Who confirms a service was delivered, and how, before billing |
| W2-9 (supplier credit note) | No supplier-credit mechanism found | A returned/over-invoiced amount has no formal accounting treatment | Credits are presumably handled manually outside the ERP | Accounting treatment and process, needs an accountant |
| W2-10 (emergency purchase) | Direct Bill lets a purchase skip Requisition→PO→GRN | No distinct emergency-purchase route with retrospective controls | Unclear whether Direct Bill's existing controls are considered sufficient for genuine emergencies | Whether Direct Bill already covers this or a distinct route is needed |

## 17. Implementation Status Matrix

| Requirement | Decision Status | Implementation Status | Independent Verification | Tests | Remaining Dependency |
|---|---|---|---|---|---|
| W2-1 | CONFIRMED (Option C) | Implemented | PASS | `PurchaseOrderReviewWorkflowTest.php` (+boundary test) | KES threshold + senior-approver identity; UI |
| W2-2 | CONFIRMED (Option A) | Implemented | PASS (1 Medium finding, documented) | `ProcurementAttachmentTest.php` (+3 tests) | WNG's evidence-category matrix (not required for the mechanism); UI |
| W2-3 | CONFIRMED (Option A) | Implemented | PASS (1 High defect found+fixed) | `StagedBillingTest.php` (+2 tests) | UI |
| W2-4 | CONFIRMED (Option B) | Implemented | PASS (1 Critical defect found+fixed) | `PurchaseOrderAmendmentTest.php`, `PurchaseOrderAmendmentCommitmentTest.php`, `PurchaseOrderAmendmentSafetyTest.php` | UI |
| W2-5 | CONFIRMED | Implemented | PASS (1 test-coverage gap closed) | `DuplicateDetectionTest.php` (+1 test, +1 assertion) | Named override-authority role; UI |
| W2-6 | CONFIRMED | Implemented | PASS (1 Medium defect found+fixed) | `PurchaseOrderReviewWorkflowTest.php` (+2 tests) | UI |
| W2-7 | AWAITING WNG | Not implemented — intentionally | N/A | — | WNG decision |
| W2-8 | AWAITING WNG | Not implemented — intentionally | N/A | — | WNG decision |
| W2-9 | AWAITING WNG + accountant | Not implemented — intentionally | N/A | — | WNG + accountant decision |
| W2-10 | AWAITING WNG | Not implemented — intentionally | N/A | — | WNG decision |

## 18. Closure Decision

## PASS WITH NON-BLOCKING FOLLOW-UP

Based on independent code inspection, direct reproduction of every mandatory test scenario, and a
full, clean regression (955/955 backend, 111/111 frontend) — not on the implementation report's own
account. One Critical and one High defect were found and are now fixed and regression-tested; two
Medium gaps were found and fixed; two Medium findings (pre-existing authorization convention;
legacy-role overlap) are documented, open, and correctly out of this pass's bounded scope to
unilaterally resolve. The accounting invariants (commitment, accrual, payable, payment) are all
independently confirmed correct, including for the specific amendment-driven commitment defect that
was the most severe finding of this closure gate.

The non-blocking follow-up is real and should not be minimized: **all six of W2-1 through W2-6 have
zero frontend UI** — every control this wave built is reachable only by direct API call today. This
does not, on its own, constitute a financial-integrity or authorization failure (every control is
correctly enforced server-side regardless of UI), so it does not block closing Wave 2's *backend*
scope — but WNG/Procurement/Accounts cannot actually *use* senior approval, amendments, return for
correction, staged-billing visibility, or duplicate overrides until a UI is built for them.

## 19. Wave 3 Readiness

**Is WNG Finance ready to begin Phase 2B Wave 3 — Expenses + Payment Vouchers + Petty Cash?**

## YES, WITH NON-BLOCKING FOLLOW-UP

Wave 3's scope (Expenses, Payment Vouchers, Petty Cash) has no code dependency on Wave 2's
remaining UI gap — it is a different set of screens and a different backend surface. Nothing found
during this closure pass blocks starting Wave 3.

Non-blocking items to carry forward, for WNG awareness (not gating Wave 3):
- A UI wave for W2-1 through W2-6 is needed before Procurement/Accounts staff can actually use any
  of Wave 2's new controls.
- WNG/Management/Procurement still owe: the W2-1 KES threshold and senior-approver identity; the
  W2-5 duplicate-override-authority role assignment; W2-7 through W2-10's own decisions.
- The two Medium authorization findings in §4/§12 (module-wide Procurement read visibility;
  `correctOrder()`'s legacy-role overlap) are worth a deliberate WNG decision, not an unprompted fix.
- Per the standing deploy discipline: `php artisan permissions:sync` must be run after this ships to
  register the three new permissions (`PROCUREMENT_ORDERS_APPROVE_SENIOR`, `PROCUREMENT_ORDERS_AMEND`,
  `PROCUREMENT_BILLS_OVERRIDE_DUPLICATE`) — this is not automated in the deploy pipeline.

**Per the closure directive's stop condition: this closure gate stops here. Wave 3 has not been
started.**
