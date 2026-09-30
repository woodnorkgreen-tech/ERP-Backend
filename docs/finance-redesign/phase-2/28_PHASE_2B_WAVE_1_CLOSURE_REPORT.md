# 28 — Phase 2B Wave 1 Closure Gate

**Date:** 2026-09-23
**Scope:** Bounded closure gate between Wave 1 (Foundations + Client/Project Money) and Wave 2
(Procurement to Payment). Objectives: reproduce and resolve the petty-cash GL-posting defect
documented in `27_PHASE_2B_WAVE_1_IMPLEMENTATION_REPORT.md`, complete the deferred W1 UI, wire
Finance attachments into confirmed evidence paths, rerun regression, and rule on Wave 1 closure.

**Coordination note:** while auditing, this session found that a separate concurrent session
(believed to be the "Codex session" referenced in prior memory of this engagement) had already
built the entire frontend UI (`EnquiryFinanceModal.vue`, `ProjectReceivablesIndex.vue`) and the
backend attachment-wiring (`FinanceAttachmentController`, credit-note routes, `review_state`
computation) this closure gate calls for — none of it built by this session. A peer session on
this machine confirmed it was not the author either. That work was **audited, not assumed
correct**, per §14/§15 of the directive; findings are in Parts B and C below. A cross-session
test-database collision (two `php artisan test` runs hitting `db_test` at once, both self-inflicted
by this session running two background test commands concurrently) produced a spurious 229-failure
run early in this gate; it is not a real finding and is superseded by the clean reruns below.

---

## A. Petty Cash Defect

### Reproduction

The two tests named in the Wave 1 report were run in isolation (`--filter`, then as their own
file, then as their own directory) three separate times, on a clean, non-concurrent `db_test`.
**All three isolated runs passed.** The failure only reproduced inside a large combined run
(`tests/Feature/CostCollector tests/Feature/PettyCash tests/Unit/PettyCash`), and that combined
run's failure signature (`Table 'db_test.migrations' doesn't exist`) is a database-collision
artifact, not an application error — confirmed by deliberately reproducing the same signature via
two concurrent `php artisan test` invocations against `db_test` in this session, which produced 229
unrelated failures with the identical error text.

**Root cause of the original report:** most likely the same class of cross-session/concurrent test
run against the shared `db_test` database, not a defect in `PettyCashCostProducer`,
`JournalPostingService`, or the two tests' fixtures — which were already correct.

### Independent verification of the production guard

`PettyCashService::createDisbursement()` (the only path that creates a real disbursement) already
refuses, before the float balance is touched, to create a **direct** (non-requisition) disbursement
whose expense code or paying account is not active/postable/GL-mapped:

```
if (! $expenseAccount?->is_active || ! $expenseAccount?->is_postable) { ... refuse ... }
if (! $sourceAccount?->is_active || ! $sourceAccount?->is_postable) { ... refuse ... }
```

A requisition-based disbursement (an advance) is exempt from this specific check because its
posting is owned by the separate, already-correct advance/surrender lifecycle (STAB-4/STAB-7). This
guard was independently confirmed by direct code reading, not assumed.

### Classification

**A — the reported failure was a test-infrastructure artifact, not an application defect**, for
the two originally-named tests. Their fixtures were already correct; nothing was changed in them
beyond removing a temporary debug statement added during this investigation.

**A genuinely separate, real gap was found during this same investigation (Classification D):**
`RecordPettyCashCost` posts a direct disbursement's cost/GL entry on a **queue**, after the cash
has already left the float (`ShouldQueue`, by design — a posting failure must never block a
disbursement). If that queued posting throws for any reason — a GL mapping later deactivated, a
closed accounting period, any `JournalPostingService` validation — the failure was previously only
a `Log::error()` line: no Finance-visible flag, no retry mechanism. This is the same shape of
problem STAB-4 already solved for requisition advances (`PettyCashAdvancePoster`), one step later
in the same disbursement's life, on a fully independent, disjoint code path (direct disbursements
never enter the advance/surrender lifecycle at all).

### Fix (Classification D)

Reused STAB-4's exact pattern rather than inventing a new one:

- **Migration** `2026_09_23_000005_add_cost_gl_posting_status_to_payments.php` — additive,
  reversible: `cost_gl_posting_failed_at` (nullable timestamp), `cost_gl_posting_error` (nullable
  text) on `payments`.
- **`PettyCashCostPoster`** (new, `app/Modules/Finance/CostCollector/Services/`) — wraps
  `PettyCashCostProducer::postFor()` in a try/catch mirroring `PettyCashAdvancePoster::attempt()`
  exactly: on success, clears any prior flag; on failure, sets the flag+error, alerts every
  `FINANCE_PETTY_CASH_UPDATE` holder via a new notification type
  (`petty_cash_cost_posting_failed`, registered in `config/notifications.php`), and never lets a
  notification failure mask the real posting failure.
- **`RecordPettyCashCost`** (edited) — now delegates to `PettyCashCostPoster::attempt()` instead of
  calling the producer directly. Behaviour for every already-correct outcome (`posted`,
  `skipped_no_job`, `skipped_unmatched`, `skipped_inactive`, `skipped_supplier_settlement`,
  `skipped_voucher_settlement`, `skipped_requisition_advance`) is unchanged — the poster only adds
  behaviour on the exception path.
- **`PettyCashController::retryCostPosting()`** (new) + route
  `POST finance/petty-cash/disbursements/{id}/retry-cost-posting` — the controlled retry,
  authorized the same way STAB-4's retry is (`reviewRequisition` policy ability,
  `FINANCE_PETTY_CASH_UPDATE`). Safe to call any number of times: both `postFor()` and
  `postDirectPayment()` post through idempotent, entry-number-keyed calls.

### Accounting impact

None on any already-correct posting. This only adds visibility and a retry path around an
exception that previously escaped silently into a log. STAB-7's exclusions
(`skipped_requisition_advance`, supplier/voucher settlement) are unconditionally checked *before*
the poster is ever reached, so nothing here can reopen the triple-posting defect STAB-7 fixed.

### Files changed

- `database/migrations/2026_09_23_000005_add_cost_gl_posting_status_to_payments.php` (new)
- `app/Modules/Finance/CostCollector/Services/PettyCashCostPoster.php` (new)
- `app/Modules/Finance/Models/Payment.php` (fillable/casts for the two new columns)
- `app/Listeners/RecordPettyCashCost.php` (delegates to the poster)
- `app/Modules/Finance/PettyCash/Controllers/PettyCashController.php` (`retryCostPosting()`)
- `routes/api.php` (the retry route)
- `config/notifications.php` (new notification type registration)

### Tests

`tests/Feature/CostCollector/PettyCashCostPostingFailureTest.php` (new, 5 tests): a GL failure is
flagged and alerts Finance; retry after the chart is fixed posts and clears the flag; retrying an
already-posted disbursement does not duplicate the journal entry; a retry against an unfixed
problem still fails visibly; a second queued attempt after success posts nothing twice. All pass.

**Incidental, pre-existing, unrelated fixture bug found and fixed while investigating:**
`PettyCashCommitmentTest::pettyCashSourceId()` created a `payment_sources` test row without
`can_make_payment`/`gl_account_id`, which `PaymentSource::scopePaymentCapable()` (added at some
point after this helper was written) now requires. Not a production defect — the model/scope are
correct; the test fixture had drifted. Fixed by setting both fields to match the real
`PaymentSourceSeeder`-seeded `PC-MAIN` row's shape. 2 of that file's 14 tests were affected; both
now pass.

### STAB-7 impact

Unaffected and independently re-verified: `Stab7PettyCashTriplePostingTest.php` passes in the same
clean run as everything else in this section (see Part D).

### STAB-4 impact

Unaffected: `PettyCashAdvancePostingTest.php` (STAB-4's own tests) passes unchanged; this closure
gate's fix is a parallel, disjoint instance of the same pattern, not a modification of STAB-4's
code.

### Historical impact

None — this fix only changes behaviour on a *future* posting exception; no historical `Payment` row
is touched, and the two new columns are added nullable with no backfill.

---

## B. W1 UI

All nine items were found **already implemented** in `EnquiryFinanceModal.vue` /
`ProjectReceivablesIndex.vue` by the other session, and were independently verified against this
session's actual backend contracts (route paths, field names, permission strings) rather than
assumed correct:

| Item | Backend | UI | Permission | Test | Status |
|---|---|---|---|---|---|
| W1-1 (check/return/issue) | Implemented (Wave 1) | Found built — `checkInvoice()`, `returnInvoice()`, `editReturnedInvoice()`, gated on `created_by !== user.id` | `finance.receivables.invoice_check` correctly used | `InvoiceReviewWorkflowTest.php` (backend) + new `enquiryFinanceModal.spec.ts` (frontend, this session) | Complete |
| W1-2 (basis required + exception) | Implemented (Wave 1) | Found built — quote-waiver UI captures reason/amount; exception evidence upload wired | `finance.receivables.billing_basis` | `InvoiceReviewWorkflowTest.php` | Complete |
| W1-3/W1-4 (figure set) | Implemented (Wave 1) | Found built — every field from `ClientFinancialPositionService` rendered, labelled, unmodified | `finance.receivables.read` (index) | `ClientFinancialPositionTest.php`, `DataIntegrityInvariantsTest.php` + new frontend test (figures pass through unrecalculated) | Complete |
| W1-5 (verify/allocate) | Implemented (Wave 1, pre-existing model) | Found built — separate verify/allocate actions in the receipts register | `finance.receivables.verify`/`.record` | `ClientFinancialPositionTest.php` | Complete |
| W1-6 (credit note/void) | Implemented (Wave 1) | Found built — `Approve & issue` hidden from the credit note's own preparer; void requires reason | `finance.receivables.reverse` | `CreditNoteTest.php` | Complete |
| W1-7 (payment terms) | Implemented (Wave 1) | Found built — configure/list/apply-to-due-date UI | `finance.receivables.billing_basis` | `InvoiceReviewWorkflowTest.php` | Complete |
| W1-8 (discount) | Implemented (Wave 1) | Found built — gross/discount/net/tax/final all shown per line and at the header | none beyond invoice-create | `InvoiceReviewWorkflowTest.php`, `DataIntegrityInvariantsTest.php` + new frontend test | Complete |
| W1-9 (unallocated credit) | Implemented (Wave 1) | Found built — figure + age in days surfaced | `finance.receivables.read` | `ClientFinancialPositionTest.php` | Complete |
| Status vocabulary (§7) | `review_state` computed in `EnquiryController::projectInvoices()` | Found built — `invoiceStatusLabel()` uses the exact 9-state vocabulary the directive specifies, word for word | — | New frontend test asserts the checked/unchecked/returned states render correctly | Complete |

**Fully Loaded Margin** is correctly *not* presented as available — the UI shows
"Not yet available — pending labour, logistics and overhead methodology" verbatim from the
backend's own note, not a client-side approximation.

**Nothing in the UI was found to bypass a backend guard.** Every action button's visibility
condition was checked against the actual permission string and actual state field this session's
backend returns (not a guessed one), and matched in every case audited.

**No frontend code was written or changed by this session for W1-1 through W1-9** — it was found
complete. This session's only frontend changes are the new test file described in Part D.

---

## C. Attachments

- **Generic mechanism status:** `finance_attachments` (table, `FinanceAttachment` model,
  `FinanceAttachmentService`) — built in Wave 1 — is now consumed, not dormant.
- **W1 wiring:** found already done, by `FinanceAttachmentController` (`index`/`store`/`download`)
  and three routes under `enquiries/{enquiry}/invoices/{invoice}/attachments...`. Scoped to exactly
  the two evidence points the directive names: `no_quote_exception_evidence` (only attachable while
  `no_quote_exception_reason` is set) and `credit_note_evidence` (only attachable to a credit note)
  — `Rule::in([...])` refuses any other value, so no speculative evidence taxonomy was introduced.
- **Security controls audited (§15), all found correct, none added:**
  - File type: `mimetypes:application/pdf,image/jpeg,image/png,image/webp` — no executable exposure.
  - Size: 10 MB max.
  - Storage: private `local` disk; served only through an authenticated controller action
    (`response()->download()`), never a public URL.
  - Upload authorization: draft-only, preparer-only (`created_by === user.id`), plus route
    permission middleware.
  - Retrieval authorization: `assertInvoiceScope()` confirms the invoice belongs to the named
    enquiry before either listing or downloading; `download()` additionally confirms the specific
    attachment belongs to the specific invoice (`source_type`/`source_id` match) before serving —
    prevents an IDOR across enquiries or invoices.
  - Deletion/replacement: no delete endpoint exists — append-only, matching "original never
    destructively deleted."
  - Audit metadata: `uploaded_by`, `created_at` present on every row.
- **Remaining dependency:** none identified. The mechanism, wiring, and security controls all
  satisfy §14/§15 as audited.

---

## D. Regression

All runs below were executed **sequentially**, with no other `php artisan test` process running
against `db_test` at the same time, after the cross-session collision earlier in this gate was
identified and understood.

```
tests/Feature/Finance + tests/Feature/Projects + tests/Unit/Finance
  431 passed (2047 assertions), 0 failed

tests/Feature/CostCollector + tests/Feature/PettyCash + tests/Unit/PettyCash
  320 passed (3565 assertions), 0 failed
  (includes the 5 new PettyCashCostPostingFailureTest.php tests, the 2 corrected
  PettyCashCommitmentTest.php tests, and Stab7PettyCashTriplePostingTest.php)

tests/Feature/Procurement/PurchaseOrderMutabilityTest.php  (STAB-3)
  7 passed, 0 failed

tests/Feature/Hr/PayrollIntegrityTest.php  (STAB-6)
  15 passed, 0 failed

tests/Unit/Finance/ChartAccountMapTest.php  (STAB-1) — already included in the 431 above
tests/Feature/Finance/PettyCashAdvancePostingTest.php  (STAB-4) — already included in the 320 above
tests/Feature/PettyCash/ClearAllPettyCashDataCommandTest.php  (STAB-5) — already included in the 320 above
tests/Feature/Finance/Stab7PettyCashTriplePostingTest.php  (STAB-7) — already included in the 320 above
```

**STAB-1 through STAB-7: all pass, unaffected.**

**Frontend:**

```
npx vitest run tests/unit/finance/enquiryFinanceModal.spec.ts (new, this session)
  6 passed, 0 failed

npx vitest run (full suite)
  106 passed, 5 failed — all 5 pre-existing, unrelated to Wave 1/this closure gate
  (financeNavigation.spec.ts: 3 failures from a stale "Record Expense"/"Record spend" label
  and a stale spend-voucher route assertion, both navigation-copy drift unrelated to
  receivables; storesEntry.spec.ts: 2 failures in the unrelated Procurement/Stores rail)

npx vue-tsc --build (type-check)
  89 pre-existing errors, ALL outside finance/receivables (universal-task, csvExport,
  statusHelpers, Register.vue) — zero errors in the module this gate touched
```

**Unresolved failures — discovered, out of scope, not fixed:**

1. `tests/Feature/Procurement/PurchaseOrderApprovalPricingTest.php` and
   `tests/Feature/Procurement/SupplierPaymentGateTest.php` — 2 failures, pre-existing, in
   Workflow 2 (Procurement to Payment) territory, which this closure gate and Wave 1 both
   explicitly exclude. Not investigated further; flagged for Wave 2 triage.
2. `tests/unit/finance/financeNavigation.spec.ts` (3) and
   `src/modules/procurement-stores/storesEntry.spec.ts` (2) — pre-existing frontend test failures,
   navigation-copy and Stores-rail structure, unrelated to receivables/W1.

None of the above were touched by, or could be worsened/concealed by, this closure gate's changes —
all live in disjoint modules with no shared file.

---

## E. Wave 1 Closure Decision

### PASS WITH NON-BLOCKING FOLLOW-UP

**Why PASS:** every item this closure gate was asked to verify or complete is done and tested:
the petty-cash defect is correctly classified (test-infrastructure artifact) and reproduced as
such three times in isolation; the one genuine, adjacent gap found during that investigation
(queued cost-posting failure visibility) is fixed with the same proven STAB-4 pattern and fully
tested; the entire W1-1 through W1-9 UI is verified complete and correct against this session's
actual backend contracts, not merely assumed; Finance attachments are confirmed wired and secure;
regression is clean across Finance, Projects, Petty Cash, Cost Collector, and all seven STAB items.

**Why WITH NON-BLOCKING FOLLOW-UP, not a bare PASS:** two pairs of pre-existing test failures were
discovered incidentally (Procurement pricing/payment-gate on the backend; navigation-copy/
Stores-rail on the frontend). Neither touches Client/Project Money, neither was caused by this
gate's changes, and neither blocks declaring Wave 1 closed — but both should be triaged before or
early in Wave 2, since Wave 2 is Procurement to Payment, the same territory as the backend pair.

**W1-10 remains explicitly unimplemented and open**, as instructed throughout.

---

## Implementation Status Matrix (cumulative, Wave 1 + this closure gate)

| Requirement | Decision Status | Implementation Status | Tests | Remaining Dependency |
|---|---|---|---|---|
| Shared: Audit Metadata | n/a | Implemented | Wave 1 tests | — |
| Shared: Return for Correction | n/a | Implemented | Wave 1 tests | — |
| Shared: Finance Evidence/Attachments | n/a | Implemented, wired, security-audited | Backend routes exercised indirectly via invoice tests; no dedicated attachment test added this gate | Consider a direct `FinanceAttachmentController` test in a future pass |
| Shared: Duplicate Detection | n/a | Deliberately not built | — | Belongs to W2-5/W3-5 |
| W1-1 | CONFIRMED | Implemented, backend + UI | Backend + new frontend test | — |
| W1-2 | CONFIRMED | Implemented, backend + UI | Backend | — |
| W1-3 | CONFIRMED | Implemented, backend + UI | Backend + new frontend test | — |
| W1-4 | CONFIRMED | Implemented, backend + UI (Fully Loaded Margin correctly excluded) | Backend + new frontend test | Fully Loaded Margin needs W6-1A/Workflow 7/8 |
| W1-5 | CONFIRMED | Implemented, backend + UI | Backend | — |
| W1-6 | CONFIRMED | Implemented, backend + UI | Backend | — |
| W1-7 | CONFIRMED (term values open) | Implemented, backend + UI | Backend | Finance to supply the actual term set |
| W1-8 | CONFIRMED | Implemented, backend + UI | Backend + new frontend test | — |
| W1-9 | CONFIRMED (escalation age open) | Implemented, backend + UI | Backend | Finance to confirm escalation age |
| W1-10 | **AWAITING FINANCE/ACCOUNTANT CONFIRMATION** | **Not implemented — intentionally** | — | Accountant decision |
| Petty Cash cost-posting visibility (closure-gate finding) | n/a — a hardening fix, not a WNG decision | Implemented | `PettyCashCostPostingFailureTest.php` | — |

---

## Wave 2 Readiness

**YES, WITH NON-BLOCKING FOLLOW-UP.**

Blockers: none for Client/Project Money — it is stable, fully tested, and its UI is complete and
verified correct against the backend it calls.

Follow-ups to carry into or ahead of Wave 2 (Procurement to Payment):
1. Triage `PurchaseOrderApprovalPricingTest.php` and `SupplierPaymentGateTest.php` — both sit
   directly in Wave 2's own territory, so understanding them before building on top is prudent,
   though neither blocks starting.
2. Triage `financeNavigation.spec.ts`'s stale label/route assertions and `storesEntry.spec.ts` —
   unrelated to Wave 2's business logic, but worth a quick fix so the frontend suite reads clean.
3. When any future wave touches Finance evidence/attachments again, consider adding a dedicated
   `FinanceAttachmentControllerTest.php` — this gate audited it by reading, not by a fresh
   automated test of its own.

**Do not implement W2, W3, W4, W5, W6, W7, W8, W1-10, STAB-2, historical STAB-7 remediation, Fully
Loaded Margin, or any dashboard/report unrelated to W1. Wait for WNG review before continuing.**
