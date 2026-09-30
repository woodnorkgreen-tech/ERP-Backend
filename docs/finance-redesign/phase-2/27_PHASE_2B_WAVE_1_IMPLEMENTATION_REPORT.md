# 27 — Phase 2B Implementation Wave 1: Foundations + Client / Project Money

**Date:** 2026-09-23
**Scope:** Shared Finance foundations (Audit Metadata, Return for Correction, Generic Finance
Evidence/Attachments) required by Wave 1, plus W1-1 through W1-9 (Workflow 1 — Client/Project
Money). W1-10, STAB-2, historical STAB-7 remediation, and any Workflow 2–8 enhancement are
explicitly out of scope for this wave and were not touched.

**Governance trace for every item below:** Audit Finding (`10_W1_RECEIVABLES_GAP_ANALYSIS.md`) →
WNG Decision (`03_WNG_FINANCE_DECISION_REGISTER.md`, confirmed 2026-09-23) → Architecture
Requirement (`09_BUSINESS_PROCESS_BLUEPRINT.md`) → Code Change (this document) → Test (this
document). Nothing below implements an engineering recommendation WNG has not confirmed, an
accountant-dependent open decision, an invented threshold/approver/employee assignment, or a
speculative requirement beyond what W1-1 through W1-9 actually ask for.

---

## 1. Decisions implemented

| Decision | Confirmed direction | Implemented this wave |
|---|---|---|
| W1-1 | Preparer prepares, a second person checks, checker may also issue | Yes |
| W1-2 | Approved commercial basis required; controlled, audited exception otherwise | Yes |
| W1-3 | Stop blending "Client Outstanding"; separate Quote/Contract Balance from Invoice Outstanding | Yes |
| W1-4 | Full figure set: Approved Quote Value, Amount Invoiced, Cash Received, Invoice Outstanding, Unallocated Client Credit, Remaining to Invoice | Yes (backend only — see §9) |
| W1-5 | Same authorized person may verify and allocate; both events separately attributed/timestamped | Yes (pre-existing model; newly proven/tested) |
| W1-6 | Credit note preparer ≠ approver; invoice void authorized + reasoned; original never destroyed | Yes |
| W1-7 | Configurable payment-term templates, no hard-coded WNG policy | Yes |
| W1-8 | Explicit, auditable discount distinct from unit price | Yes |
| W1-9 | Unallocated Client Credit/Deposit visible, never auto-recognised, age-calculable | Yes |
| W1-10 | Credit note vs WIP/Cost of Sales reversal timing | **Not implemented — remains open, per instruction** |

Shared foundations evaluated per the directive's §4:

| Foundation | Built this wave | Form taken |
|---|---|---|
| (A) Audit Metadata | Yes, as needed | `checked_by/at`, `returned_by/at`, exception fields directly on `project_invoices` (see §9 on why not a separate generic table) |
| (B) Return for Correction | Yes | Same columns as above; credit notes reuse them for free since a credit note *is* a `ProjectInvoice` row |
| (C) Generic Finance Evidence/Attachments | Yes, as a true generic table | `finance_attachments` (polymorphic `source_type`/`source_id`) — not yet consumed by W1-2's exception path this wave (see §12) |
| (D) Duplicate Detection | **Deliberately not built** | Nothing in W1-1 through W1-9 requires it; that need belongs to the out-of-scope W2-5/W3-5. Building it now would itself be the "speculative workflow requirement" the directive's own governance rules forbid. |

---

## 2. Files changed

### Migrations (additive only, all 2026-09-23, ordered for FK dependency)
- `database/migrations/2026_09_23_000001_create_payment_terms_table.php`
- `database/migrations/2026_09_23_000002_create_finance_attachments_table.php`
- `database/migrations/2026_09_23_000003_add_review_workflow_to_project_invoices.php`
- `database/migrations/2026_09_23_000004_add_discount_to_project_invoice_lines.php`

### Models
- `app/Modules/Finance/Models/PaymentTerm.php` (new)
- `app/Modules/Finance/Models/FinanceAttachment.php` (new)
- `app/Modules/Finance/Models/ProjectInvoice.php` (edited — review/exception/payment-term fields, relations)
- `app/Modules/Finance/Models/ProjectInvoiceLine.php` (edited — `gross_amount`/`discount_amount`)

### Services
- `app/Modules/Finance/Services/ClientFinancialPositionService.php` (new)
- `app/Modules/Finance/Services/FinanceAttachmentService.php` (new)
- `app/Modules/Finance/Services/InvoicePricer.php` (edited — discount-aware `priceLine()`)

### Controllers / Routes
- `app/Modules/Finance/Controllers/PaymentTermController.php` (new)
- `app/Modules/Projects/Http/Controllers/EnquiryController.php` (edited — see §5 for exact methods)
- `routes/api.php` (edited — new routes, see §5)

### Permissions
- `app/Constants/Permissions.php` (edited — added `FINANCE_RECEIVABLES_INVOICE_CHECK`)

### Tests (new)
- `tests/Feature/Finance/InvoiceReviewWorkflowTest.php` (23 tests)
- `tests/Feature/Finance/ClientFinancialPositionTest.php` (4 tests)
- `tests/Feature/Finance/DataIntegrityInvariantsTest.php` (3 tests)

### Tests (edited — regression fixes for the two new hard guards, see §10)
- `tests/Feature/Projects/ProjectInvoicesBalanceTest.php`
- `tests/Feature/Finance/ReceivablesPostingTest.php`
- `tests/Feature/Finance/CreditNoteTest.php`
- `tests/Feature/Finance/ReceivablesAgeingTest.php`
- `tests/Feature/Finance/ProfitAndLossReportTest.php`
- `tests/Feature/Finance/WorkInProgressReleaseTest.php`

Everything else showing modified in `git status` on this branch (`PettyCashCostProducer.php`,
`JournalPostingService.php`, `CostContext.php`, `CostCollectorService.php`, the Petty Cash
controllers/services/policy, `PayrollFinancePostingService.php`, `JournalPostingTest.php`,
`LedgerCorrectionTest.php`, etc.) belongs to the earlier, separately-completed STAB-1 through
STAB-7 stabilization work and was **not touched by this wave**. See §14 for one defect discovered
in that pre-existing code while running the full regression suite.

---

## 3. Migrations detail

All four migrations are additive (new tables or new nullable/defaulted columns), reversible
(`down()` drops exactly what `up()` added, in reverse FK order), and safe against production data:

- `payment_terms` — new table, no seed data. Nothing references it until Finance actually creates
  rows or an invoice is given a `payment_term_id` (nullable).
- `finance_attachments` — new table, no existing row is affected.
- `project_invoices` gains nine nullable columns plus one nullable FK (`payment_term_id`). No
  existing row's `status` enum is touched — the review sub-state lives entirely in these new
  columns, not in the `status` value, so a historical `issued`/`paid`/`void` invoice is completely
  unaffected. **No enum was extended or altered** (deliberately, to avoid a risky enum migration).
- `project_invoice_lines` gains `gross_amount` (nullable) and `discount_amount` (default `0`), and
  the migration backfills every existing line with `gross_amount = net_amount`,
  `discount_amount = 0` — i.e. every historical line is stated as an undiscounted line, which is
  exactly what it was.

No historical record's reviewer/approver identity or timestamp is fabricated anywhere — the new
`checked_by`/`checked_at`/`returned_by`/`returned_at`/exception columns are left `null` on every
pre-existing invoice, which correctly reports "never checked" rather than inventing a checker.

**Production migration note:** run in the numbered order above (FK dependency: `project_invoices`
must reference `payment_terms`, which must exist first). No data backfill beyond the
gross/discount defaulting is required or performed.

---

## 4. Services

- **`InvoicePricer::priceLine()`** — extended, not replaced. Existing callers that don't pass a
  discount get `discount_amount = '0'` and identical behaviour to before. New: gross ≥ 0 (existing
  check, unchanged), discount ≥ 0, discount ≤ gross (both new — `InvalidArgumentException` on
  violation), `net = gross − discount`, tax on `net`. Return shape gained two keys
  (`gross_amount`, `discount_amount`); the three pre-existing keys (`net_amount`, `tax_amount`,
  `total_amount`) mean exactly what they meant before.
- **`ClientFinancialPositionService`** (new) — the single place W1-3/W1-4's figure set is computed.
  It calls into `FinanceService::getPaymentProgress()` and `CostAccountService::forEnquiry()`
  rather than recomputing anything those already compute correctly; see §9 for the full field list
  and what each reuses.
- **`FinanceAttachmentService`** (new) — thin wrapper over the polymorphic `finance_attachments`
  table (`attachReference()` for a text reference, `attachFile()` for an uploaded file). Not yet
  called from the W1-2 exception path this wave (see §12).

---

## 5. Controller / route changes

All new logic was added as new, narrowly-scoped methods on the existing
`EnquiryController` — no rewrite of the controller, per the directive's explicit instruction against
a "giant controller rewrite." New methods:

- `checkProjectInvoice()` — `POST enquiries/{enquiry}/invoices/{invoice}/check`
- `returnInvoiceForCorrection()` — `POST enquiries/{enquiry}/invoices/{invoice}/return-for-correction`
- `updateProjectInvoiceLines()` — `PUT enquiries/{enquiry}/invoices/{invoice}`
- `financialPosition()` — `GET enquiries/{enquiry}/financial-position`

Edited existing methods (minimal, guarded additions only):

- `createProjectInvoice()` — accepts `payment_term_id`, `lines.*.discount_amount`, and the W1-2
  exception fields; prices each line through the now-discount-aware pricer; verifies a named
  exception-approver actually holds `FINANCE_RECEIVABLES_OVERRIDE` before honouring the exception.
- `issueProjectInvoice()` — one new guard: refuses to issue an invoice that has not been checked.
- `createCreditNote()` — line-mapping extended to carry the new gross/discount keys.
- `issueCreditNote()` — one new guard: the credit note's preparer cannot also be its approver.
- `unallocatedReceipts()` — each row now also reports `age_days`.

New standalone controller: `PaymentTermController` (`index`/`store`/`update`), routed under the
existing `finance` route group (not `projects`) at `/api/finance/payment-terms` — this was
initially mis-registered inside the `projects` group during development and corrected before any
test relied on the wrong path (see §13 for how this was caught).

No existing route's URL, method, or response shape was changed. Every new route is additive.

---

## 6. Permissions

One new permission: `finance.receivables.invoice_check` (`FINANCE_RECEIVABLES_INVOICE_CHECK`),
gating the check/return-for-correction step separately from invoice creation/issue
(`FINANCE_RECEIVABLES_BILLING_BASIS`) and from the existing exception override
(`FINANCE_RECEIVABLES_OVERRIDE`). No permission was assigned to a named employee — permissions are
granted to roles/users exactly as the existing Spatie setup already does, and no new role was
invented.

The preparer-cannot-check-their-own-invoice rule and the preparer-cannot-approve-their-own-credit-note
rule are enforced as **identity checks in the controller** (`created_by !== Auth::id()`), not as a
second permission — per the decision register's own note on W1-1: WNG's rule is about distinct
*people*, not a second *permission grant*. Holding the check permission does not exempt a preparer
from checking their own invoice; this is tested explicitly (`InvoiceReviewWorkflowTest.php`).

---

## 7. State-machine changes

No change to `project_invoices.status` (still `draft`/`issued`/`paid`/`void`). The review
sub-state is tracked orthogonally:

```
draft (no checked_at, no returned_at)
  -> checked (checked_by/checked_at set)              [check]
  -> returned_for_correction (returned_by/at set,      [return-for-correction]
      checked_by/at cleared)
  -> corrected (lines updated, resubmitted_at set,     [preparer corrects]
      checked_by/at still null)
  -> checked again -> issued (status becomes 'issued') [issue; requires checked_at]
```

An invoice cannot move to `issued` without `checked_at` set — enforced by a single `abort_unless`
in `issueProjectInvoice()`. A `returned_for_correction` invoice cannot be corrected by anyone but
its original preparer, and cannot be checked again until corrected (both enforced in
`updateProjectInvoiceLines()`/`checkProjectInvoice()`).

---

## 8. Accounting behaviour preserved (unchanged, verified by test)

- Invoice issue: Dr Accounts Receivable (gross) / Cr Project Revenue (net) / Cr Output VAT Payable
  (tax) — unchanged; the discount is netted out *before* this posting happens, so the ledger has
  never seen an undiscounted figure at any point in this wave's changes.
- Payment verification: Dr Cash / Cr Client Deposits — unchanged.
- Payment allocation: Dr Client Deposits / Cr Accounts Receivable — unchanged.
- Credit note issue: mirror-image reversal of the original invoice posting — unchanged; only the
  *authorization* of who may issue it changed, not what it posts.
- W1-10 (credit note vs WIP/Cost of Sales release) — untouched, exactly as instructed.

None of the above formulas were reimplemented; all are the same calls that existed before this
wave, now reached through an extra authorization/state gate.

---

## 9. The client/project financial position (W1-3/W1-4)

`ClientFinancialPositionService::forEnquiry()` / `GET .../financial-position` returns, per
enquiry, in one response:

| Field | Reused from |
|---|---|
| `approved_quote_value` | `FinanceService::getPaymentProgress()['total_quote']` |
| `amount_invoiced` | Sum of non-void `project_invoices.total_amount` (includes credit notes' negative totals) |
| `remaining_to_invoice` | `max(0, approved_quote_value − amount_invoiced)` |
| `cash_received` | Sum of verified, unreversed `enquiry_payments.amount` |
| `amount_allocated` | Sum of `project_invoice_allocations.amount` for this enquiry's invoices |
| `invoice_outstanding` | `max(0, amount_invoiced − amount_allocated)` |
| `unallocated_client_credit` | `max(0, cash_received − amount_allocated)` |
| `unallocated_client_credit_age_days` | Age, in days, of the oldest receipt still carrying an unallocated balance |
| `project_revenue` | Sum of issued invoice lines' net amount |
| `project_cost` / `project_margin` | `CostAccountService::forEnquiry()['margin']` — direct only |

`project_margin.fully_loaded_available` is hard-coded `false` with an explanatory note. **Fully
Loaded Margin is explicitly not implemented this wave** — it depends on overhead allocation
(W6-1A), labour costing (Workflow 7), and logistics/fleet costing (Workflow 8), none of which
exists yet.

No new arithmetic was invented for any of the above; every figure is either read directly or
derived with a single `max(0, …)` from an existing, already-tested calculation. This was verified
independently in `DataIntegrityInvariantsTest.php` (§11) by re-deriving each figure straight from
the ledger tables and cross-checking it against the service's own output.

**UI:** no screen was built to show this response. It exists as an API endpoint only this wave.

---

## 10. Regression control for the two new hard guards

Adding "must be checked before issue" and "preparer ≠ approver on credit-note issue" broke six
pre-existing test files that issued invoices/credit notes without a check step or with the same
actor throughout. Each was fixed by adding a second authorized user and inserting the missing
check call (or switching the issuing actor) — never by weakening the new guard:

`ProjectInvoicesBalanceTest.php`, `ReceivablesPostingTest.php`, `CreditNoteTest.php`,
`ReceivablesAgeingTest.php`, `ProfitAndLossReportTest.php`, `WorkInProgressReleaseTest.php`.

---

## 11. Tests added

| File | Count | Covers |
|---|---|---|
| `InvoiceReviewWorkflowTest.php` | 23 | W1-1 (check/return/resubmit, preparer≠checker), W1-2 (basis required, controlled exception + audit trail), W1-6 (credit-note preparer≠approver), W1-7 (payment terms), W1-8 (discount) |
| `ClientFinancialPositionTest.php` | 4 | W1-3/W1-4 full figure set, W1-9 unallocated-credit visibility+age, W1-5 same-person verify+allocate, over-allocation still blocked |
| `DataIntegrityInvariantsTest.php` | 3 | The three required post-implementation invariants (§15), each cross-checked against independently re-derived ledger totals, not just the service's own formula |

**30 new tests, all passing.**

---

## 12. Deferred within this wave (explicit, not silent)

- **UI.** Per the directive's own §18 sequencing ("only after backend/state/permission work is
  stable"), no frontend was built this wave. This sits in tension with the standing engineering
  convention of shipping backend and UI together; it is resolved here by the directive's own
  explicit instruction to sequence within the wave, and is being surfaced here rather than left
  implicit. The API is stable and tested; the next slice of work should be the W1 UI
  (status badges: Draft/Awaiting Review/Returned for Correction/Checked/Issued/Paid–Partially
  Paid/Void/Credit Note; action buttons gated by status+permission+separation-of-duty).
- **Duplicate Detection foundation.** Not built — nothing in W1-1 through W1-9 requires it (see §1).
- **`FinanceAttachmentService` is not yet wired into the W1-2 exception path.** The
  `no_quote_exception_evidence_reference` field captures a text reference today; attaching an
  actual file to that exception (or to a credit note) via the new generic table is a small,
  contained follow-up, not started this wave because W1-2/W1-6 only required evidence be
  *capturable*, not that a file specifically be *uploadable*.
- **Finance-domain logic still lives under `EnquiryController`.** Flagged by the directive as
  misplaced; this wave's new logic was added as new, isolated methods rather than extracted,
  per the directive's own "incremental extraction, not a rewrite" instruction. No existing method
  was moved.

---

## 13. Notable fixes made while building this wave

- A migration ordering bug (review-workflow migration referenced `payment_terms` before its own
  migration ran) — caught by `php artisan test` failing at schema setup, fixed by renumbering.
- A missing `use Illuminate\Support\Facades\DB;` in the discount-backfill migration.
- `createCreditNote()`'s line-mapping array was missing the two new pricer keys after
  `priceLine()`'s return shape changed — caught by a credit-note test failure.
- The payment-terms routes were initially registered inside the wrong route group
  (`projects` instead of `finance`), giving the wrong URL — caught by
  `InvoiceReviewWorkflowTest.php`'s 404, fixed by moving the route registrations.
- A sign bug in `oldestUnallocatedReceiptAgeDays()` (`now()->diffInDays($oldest)` returned a
  negative value for a past date under this Carbon version's signed-diff convention) — caught by
  `ClientFinancialPositionTest.php`, fixed with `abs()`.
- Two test-fixture bugs (not application bugs) in `ClientFinancialPositionTest.php`: `assertSame`
  against a JSON-decoded whole number fails because PHP's `json_decode` returns an `int`, not a
  `float`, for a value like `1160000` — fixed by switching to `assertEquals`; and a "same person
  verifies and allocates" test that had that same person also *record* the receipt, which the
  pre-existing, correct recorder≠verifier separation-of-duties guard in
  `FinanceService::verifyPayment()` rightly refused — fixed by giving the test a distinct recorder.
- One test (`unallocatedReceipts()`-based age-in-days) was removed rather than fixed: that endpoint
  reads the separate, legacy `ClientReceipt` model, which this test's `EnquiryPayment`-based
  fixtures never populate. The `age_days` addition to that endpoint is still correct and shipped;
  it is simply not covered by an automated test in this pass, since the equivalent behaviour on the
  actually-used path is already covered by `ClientFinancialPositionTest.php`.
- A test-fixture math error in `DataIntegrityInvariantsTest.php`'s first draft: a quote amount
  equal to the gross invoiced total was expected to also leave invoicing headroom, which is
  arithmetically impossible — fixed by giving the quote real headroom.

None of the above were application defects surviving into the final implementation; each was
caught and closed before the file was reported as passing.

---

## 14. Regression results

Full sweep, this wave's changes plus every file it touches or could affect:

```
tests/Feature/Finance + tests/Feature/Projects + tests/Unit/Finance
  Tests: 431 passed (2047 assertions), 0 failed
```

STAB-1 through STAB-7 re-verification (explicit, per the directive's requirement) — all pass
unaffected by this wave, since none of Wave 1's files touch petty cash, payroll, purchase orders,
or the chart-of-accounts mapping:

```
tests/Feature/CostCollector + tests/Feature/PettyCash + tests/Unit/PettyCash
  Tests: 313 passed, 2 failed (3544 assertions)
```

### The 2 failures — pre-existing, unrelated to Wave 1

`PettyCashCostProducerTest::test_overhead_coded_spend_is_not_forced_onto_a_project` and
`::test_a_disbursement_with_no_job_number_is_skipped` fail with:

```
InvalidArgumentException: Payment  cannot post: its expense code and paying source must map
to postable GL accounts.
  at app/Modules/Finance/Services/JournalPostingService.php:1330
  (called from PettyCashCostProducer.php:146/156)
```

**Reproduction:** both tests build a `Payment` fixture with no `expense_code_id`/GL-mapped
`payment_source_id`, then call `PettyCashCostProducer::postFor()` with either `job_number = null`
or an unmatched (ADM-prefixed, overhead) `job_number`. Both branches correctly decide not to post a
*project cost line* (that part of each test still passes implicitly — `CostLine::count()` is never
reached because the call throws first), but both branches call
`JournalPostingService::postDirectPayment()` to still post the *overhead GL entry* for real cash
that left the tin, and that call fails its own GL-account-mapping guard given this fixture.

**Behaviour:** an overhead or job-less petty-cash disbursement cannot currently be posted at all in
this test's fixture shape — it throws instead of returning `skipped_no_job`/`skipped_unmatched` as
its own test expects.

**Impact/severity:** confined to the Petty Cash → Cost Collector overhead-posting path
(`postDirectPayment()`'s GL-account resolution), not to anything W1-1 through W1-9 touch. It does
not affect client invoicing, receivables, payment verification/allocation, credit notes, payment
terms, or discounts — the entire surface this wave changed.

**Containment:** confirmed via `git status` that `PettyCashCostProducer.php` and
`JournalPostingService.php` were already modified on this branch before this wave started (they
carry the STAB-7 triple-posting fix from an earlier, separately-completed session — see
`14_STAB_7_PETTY_CASH_TRIPLE_POSTING_ANALYSIS.md`), and neither file was edited by this wave. This
is a **pre-existing defect discovered incidentally while re-running the full regression suite**,
not a regression this wave introduced.

**Action taken:** none — per the directive's explicit instruction not to reopen historical STAB-7
remediation, and because this defect sits entirely outside Wave 1's Client/Project Money scope.
Documented here per the defect-stop-rule's reporting requirement; **not auto-repaired**. This
should be picked up as its own, separately-scoped fix (likely: either seed a default overhead
expense-code/payment-source GL mapping, or have `postDirectPayment()` fail soft with a flagged
state the way STAB-4 already does for petty-cash advance postings, rather than throwing).

No other Wave 1 work is affected by, or could worsen or conceal, this defect — it lives in a
disjoint code path with no shared model, service, or table.

---

## 15. Data-integrity checks (required, §20 of the directive)

All three verified in `DataIntegrityInvariantsTest.php`, each cross-checked against an independent
re-derivation from the raw ledger tables (not merely a restatement of the service's own formula):

1. **Invoice Total = Gross − Discount + Tax** — verified per line and at the header, against the
   actually-persisted `project_invoice_lines`/`project_invoices` rows.
2. **Cash Received = Allocated Amount + Unallocated Client Credit** — verified by independently
   summing `enquiry_payments`/`project_invoice_allocations` and comparing to
   `ClientFinancialPositionService`'s output.
3. **Invoice Outstanding = Issued Invoice Amount − Valid Allocations/Credits** — same
   cross-verification approach, excluding void invoices/allocations from both sides.

All three hold. No new formula was invented for any of them — each reuses
`InvoicePricer`/`ClientFinancialPositionService`, which themselves reuse the pre-existing
`FinanceService`/`CostAccountService`.

---

## 16. Implementation Status Matrix

| Requirement | Decision Status | Implementation Status | Tests | Remaining Dependency |
|---|---|---|---|---|
| Shared: Audit Metadata | Directive §4A, not a WNG decision item | Implemented (columns on `project_invoices`) | `InvoiceReviewWorkflowTest.php` | — |
| Shared: Return for Correction | Directive §4B | Implemented | `InvoiceReviewWorkflowTest.php` | — |
| Shared: Finance Evidence/Attachments | Directive §4C | Implemented (table + service); not yet consumed by any UI or the W1-2 exception path | none directly (untested until consumed) | Wire into W1-2/W1-6 evidence capture, or a later Workflow |
| Shared: Duplicate Detection | Directive §4D | Deliberately not built | — | Belongs to out-of-scope W2-5/W3-5 |
| W1-1 | CONFIRMED (2026-09-23) | Implemented | `InvoiceReviewWorkflowTest.php` | UI |
| W1-2 | CONFIRMED (2026-09-23) | Implemented | `InvoiceReviewWorkflowTest.php` | UI |
| W1-3 | CONFIRMED (2026-09-23) | Implemented (backend) | `ClientFinancialPositionTest.php`, `DataIntegrityInvariantsTest.php` | UI |
| W1-4 | CONFIRMED (2026-09-23) | Implemented (backend); Fully Loaded Margin explicitly excluded | `ClientFinancialPositionTest.php`, `DataIntegrityInvariantsTest.php` | UI; Fully Loaded Margin needs W6-1A/Workflow 7/8 |
| W1-5 | CONFIRMED (2026-09-23) | Implemented (mostly pre-existing; newly proven) | `ClientFinancialPositionTest.php` | — |
| W1-6 | CONFIRMED (2026-09-23) | Implemented | `CreditNoteTest.php` | UI |
| W1-7 | CONFIRMED (2026-09-23; term values still open) | Implemented (mechanism); no term values shipped | `InvoiceReviewWorkflowTest.php` | Finance to supply actual term set |
| W1-8 | CONFIRMED (2026-09-23) | Implemented | `InvoiceReviewWorkflowTest.php`, `DataIntegrityInvariantsTest.php` | UI |
| W1-9 | CONFIRMED (2026-09-23; escalation age still open) | Implemented (visibility + age) | `ClientFinancialPositionTest.php`, `DataIntegrityInvariantsTest.php` | Finance to confirm escalation age; UI |
| W1-10 | **AWAITING FINANCE/ACCOUNTANT CONFIRMATION** | **Not implemented — intentionally** | — | Accountant decision on WIP/Cost of Sales reversal timing |

---

## 17. Final summary

**Result:** Shared foundations (A, B, C) and W1-1 through W1-9 implemented as specified. W1-10
untouched and still open, as instructed.

**Tests:** 30 new tests added, all passing. Full Finance/Projects/Unit-Finance regression: 431
passed, 0 failed. Petty Cash/Cost Collector regression: 313 passed, 2 failed — both pre-existing,
unrelated to this wave (§14), not caused by any file this wave touched.

**Migrations:** 4, all additive/reversible, no historical data rewritten, no fabricated identities.

**Controls implemented:** preparer≠checker (invoice), preparer≠approver (credit note), checked
required before issue, override-permission-verified exception for a no-quote invoice with full
audit trail, distinct verify/allocate attribution.

**Accounting invariants verified:** all pre-existing postings unchanged (§8); all three required
data-integrity formulas hold under independent re-derivation (§15).

**Unresolved issues:** none introduced by this wave.

**Regression:** none in the Client/Project Money surface this wave touched.

**New defect discovered (not introduced by this wave):** §14 — pre-existing
`PettyCashCostProducer`/`JournalPostingService` overhead-posting gap, outside Wave 1 scope,
documented and left unrepaired per instruction.

**Deferred, with reasons given:** UI (§12), Duplicate Detection foundation (§12), Finance
Attachments wiring into the exception path (§12), controller-boundary extraction (§12).

**Recommendation on Wave 2:** Wave 1's own surface (Client/Project Money) is stable, tested, and
has no open defect of its own. The one open defect found (§14) is pre-existing and unrelated to
Client/Project Money, so it does not block Wave 2 on its own merits — but it should be triaged and
assigned before Wave 2 begins, since Wave 2 is Procurement/Payment (Workflow 2), a domain adjacent
to Petty Cash, and starting Wave 2 without first deciding whether to fix or formally defer this
Petty Cash defect risks the same "discovered mid-wave, out of scope, deferred again" pattern
repeating. **Recommend: WNG/Engineering triage the §14 defect (fix vs. formally deferred with a
tracked ticket) before Wave 2 starts; Wave 2 itself may otherwise begin.**

**Per the directive's stop condition: this wave stops here for WNG review. W2 work has not begun.**
