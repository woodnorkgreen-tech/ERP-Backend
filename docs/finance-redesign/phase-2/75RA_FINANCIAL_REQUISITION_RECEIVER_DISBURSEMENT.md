# Report 75R-A — Financial Requisition Receiver Allocation & Multi-Disbursement Execution

Date: 2026-10-06 · Continuation of Report 75R · Backend `master` @ `5a5a3db`, frontend `master` @ `b82f278`, both with uncommitted work in the tree. Nothing was committed, pushed, merged, stashed, deployed or migrated outside the test database.

---

## 1. Executive summary

One requisition can now be paid to several receivers, in full or in instalments, with every payment bound to the parent and to the lines it funds.

- **Bridge.** A new table, `requisition_payment_allocations`, links a requisition line to the Payment that funded it. Each row carries the parent requisition, the line, the Payment, the receiver and the amount.
- **Receivers.** A line is payable to an Employee, a Supplier, or another recipient named together with a reference. A typed name on its own is not an identity and is never merged with another.
- **Payment.** Finance pays one receiver at a time. One Payment may cover several lines of the same receiver; it may never cover two receivers. Part payment is supported.
- **Control.** Outstanding balances are recalculated on the server under a lock on the parent. Two people paying the same last KES 10,000 at the same moment: one succeeds, one is refused. A replayed request returns the Payment it already made.
- **References.** Each payment gets `REQ-…-D01`, `-D02`, … numbered under the parent lock, alongside its own `PAY-…` reference.
- **Ledger.** Each Payment posts its own advance journal and carries its own posting state, retry and reversal. The parent no longer stands in for several transfers.
- **Status.** Not / partially / fully disbursed and the over-disbursement exception are derived from active Payments, for the parent and for each receiver. Reversal shows immediately.
- **Screen.** The requisition page shows parent totals, each receiver with approved / paid / outstanding, a Process payment dialog, every payment with its ledger state, and the story.

What is **not** done, by design of this report: receiver-level receipt confirmation, receiver-level accountability (surrender), cancellation of unused balances, and parent closure. A requisition paid by receiver cannot yet be surrendered; the existing surrender refuses it rather than account for it wrongly (§17, §24).

Test results: 32 new backend tests and 21 new frontend tests pass, including three real multi-process races. Regression figures are in §22.

---

## 2. 75R baseline

Report 75R delivered verification (responsible verifier, creator/verifier separation, fingerprint, invalidation on material change, re-verification, Finance approval gate) and a truthful read projection. It left the disbursement architecture open: no line-to-payment link, payout of the whole parent amount only, one parent journal link, receiver paid amounts reported as "not linked".

Before any change, the 75R focused tests were run on an isolated database: **13 passed** (`75ra-evidence/baseline-75r.txt`).

**What I found already in the tree.** An earlier session had started 75R-A at about 13:00 and stopped at 13:04: a migration, an allocation model, a disbursement service, a receiver-identity service, a per-payment posting method, edits to the controller and projection, and a test file. None of it had run — the test failed in its own setup (`75ra-evidence/initial-tests.log`). I kept its design where it was sound (the table shape, the parent-lock approach, the fingerprinted request key) and rewrote, corrected or completed the rest. The defects found in that start are listed in §23.

All 75R controls are preserved. The 75R tests pass unchanged at the end (13 of 13), and two new tests confirm that a material edit still revokes verification and approval, and that verification is re-checked at the moment of payment.

---

## 3. Allocation architecture

**Existing structures were inspected first and not reused**, because they mean something else:

| Structure | What it links | Why it is not the bridge |
|---|---|---|
| `PettyCashDisbursementAllocation` | a Payment ↔ the float top-ups it drew down | It is about where petty cash came from, not who it was for. |
| `PaymentAllocation` | a settlement Payment ↔ the CostLines it settles | Its `cost_line_id` is required. A requisition payment is an advance and settles no cost line. |

**New table `requisition_payment_allocations`** (migration `2026_10_06_000004`):

| Column | Purpose |
|---|---|
| `requisition_id` | the authoritative parent, on every row |
| `requisition_item_id` | the line funded |
| `payment_id` | the Payment that funded it |
| `receiver_type`, `receiver_identity` | who was paid, as resolved at payment time |
| `allocated_amount` | the share of the Payment that went to this line |
| `created_by`, timestamps | who recorded it and when |

Unique on (`payment_id`, `requisition_item_id`). All foreign keys are RESTRICT: a line, Payment or requisition with allocations cannot be deleted.

Also added by the migration: `petty_cash_requisitions.disbursement_sequence`; `petty_cash_requisition_items.supplier_id` and `other_recipient_reference`; and on `payments`, `requisition_child_reference` (unique), `requisition_request_fingerprint`, `advance_journal_entry_id`, `advance_gl_posting_failed_at`, `advance_gl_posting_error`.

Amounts are not duplicated. No paid or outstanding figure is stored anywhere; they are summed from active Payments and their allocations each time.

---

## 4. Receiver identity

`RequisitionReceiverIdentity` resolves each line to exactly one receiver:

| Type | Identity | Source |
|---|---|---|
| Employee | the Employee record id | the line's `payee_id`, or the requisition's own payee |
| Supplier | the Supplier record id | the line's `supplier_id` |
| Other approved recipient | the reference stated on the line, scoped to that requisition | `other_recipient_reference` + name |

No new directory was created. Employee and Supplier are reused as they are.

**Other approved recipient.** The identity is a reference the creator states (an ID, registration or agreement number), together with the name. Both are part of what the verifier certifies and Finance approves, because both are in the 75R verification fingerprint. Rules enforced:

- a reference with no name is refused;
- one reference cannot carry two different names on the same requisition;
- two lines with the same name but different references are two receivers;
- a line naming more than one kind of receiver is refused;
- a supplier line always carries the supplier's registered name, whatever was typed.

No bank account, card or M-Pesa credential is stored. The form says so next to the field.

**When the rules apply.** At submission, as soon as the lines name a supplier, a referenced recipient, or simply more than one person. A requisition with one typed payee for the whole amount is left as it is and is still paid as a single payment.

**Limit, stated plainly.** The "other" identity holds for one requisition. There is no cross-requisition register of non-employee, non-supplier recipients. Whether WNG wants one is a decision (§25).

---

## 5. Parent / line / receiver / payment relationships

```
PettyCashRequisition (parent, REQ-2026-0048)
 ├─ PettyCashRequisitionItem (line)  ── receiver: employee | supplier | other
 │     └─ RequisitionPaymentAllocation ──┐
 └─ Payment (PAY-…, REQ-…-D0n) ──────────┘   one receiver per Payment
       └─ advance journal entry (per Payment)
```

| Relationship | Supported | Tested |
|---|---|---|
| one requisition → many payments | yes | three receivers, three payments |
| one receiver → many lines | yes | Steve: two lines |
| one payment → many lines, same receiver | yes | grouped payment keeps 15,000 + 5,000 |
| one receiver allocation → many payments | yes | Timothy: 20,000 then 10,000 |
| parent FK on every Payment and allocation | yes | asserted |

A receiver's approved allocation is the sum of that receiver's lines. It is derived, not stored.

---

## 6. Grouped payments

Finance selects a receiver; the request carries that receiver's line ids and an amount. The service creates one Payment and one allocation row per line. In the worked example Steve's KES 20,000 Payment holds 15,000 → Site facilitation and 5,000 → Casual support, and both purposes are shown against the payment on screen.

**Mixed receivers are refused.** If the selected lines resolve to more than one receiver the request fails with "One Payment pays one receiver. Pay each receiver separately." No Payment, allocation or child reference is created (tested).

When a grouped payment is partial, the amount fills the receiver's lines in line order. Choosing a different split per line is not offered (§24).

---

## 7. Instalments

A receiver may be paid any amount up to what is outstanding. Timothy, approved 30,000: after 20,000 he and the parent read PARTIALLY DISBURSED with 10,000 outstanding; after a further 10,000 he reads FULLY DISBURSED. Both Payments sit under the same requisition and the same line.

Each payment is labelled for what it is: **Part payment** while the receiver still has a balance, **Final instalment** when a later payment clears it, **Paid** when one payment settles the receiver.

---

## 8. Child references

Format: `<requisition number>-D<nn>`, e.g. `REQ-2026-0048-D02`. Stored on the Payment in `requisition_child_reference`, with a unique index. The Payment keeps its own `PAY-…` number from the existing locked sequence.

The sequence is a counter on the parent row (`disbursement_sequence`), read and incremented while the parent is locked `FOR UPDATE`. It is not `MAX()+1`. Numbers are never reused: after D02 is reversed the replacement is D04, not D02 (tested). Five simultaneous requests produced exactly D01, D02, D03 with no gaps or duplicates (tested with real processes).

---

## 9. Concurrency

Every payment runs in one database transaction that first locks the parent requisition row, then the lines, the parent's Payments and the active allocations. Payment reversal takes the same parent lock first. So payment, numbering and reversal on one requisition are strictly one after another.

`RequisitionReceiverDisbursementConcurrencyTest` proves this with real races — separate processes, separate connections, released together at a barrier:

| Scenario | Result |
|---|---|
| Two Finance users each pay Timothy's last 10,000 | exactly one succeeds; the other is refused with "…more than the KES 0.00 still outstanding…"; paid = 30,000 |
| Five simultaneous 10,000 payments against 30,000 | exactly three succeed; references D01–D03; three distinct PAY numbers |
| The same request sent twice at once | both answered with one Payment; one journal |

---

## 10. Idempotency

The existing architecture is used: `payments.idempotency_key` (unique) and `PaymentSettlementService`'s key check. On top of it, the request is fingerprinted (requisition, acting user, lines, amount, account, method, date, reference) and the fingerprint is stored on the Payment.

- Same key, same instructions → the existing Payment is returned. No second Payment, allocation, child reference or journal.
- Same key, different instructions → refused: "This request key was already used for different payment instructions."
- The key is required and validated as a UUID by the API.

On the screen the key is created once when the dialog opens and kept for retries, so a double click or a retry after a lost response cannot pay twice. The button is also disabled while a request is in flight, but that is a courtesy; the server is the protection.

---

## 11. Overpayment controls

All checked on the server, under lock, from stored records:

1. the lines still add up to the approved parent total;
2. the amount is not more than what is outstanding on the selected lines;
3. active Payments plus this one do not exceed the approved parent total;
4. a line already paid above its amount blocks further payment until Finance resolves it;
5. a requisition already carrying a whole-amount payment that is not allocated to receivers cannot also be paid by receiver.

The screen defaults the amount to the outstanding balance and will not submit more, but nothing depends on that.

---

## 12. Finance processing

`POST /api/finance/petty-cash/requisitions/{id}/disburse` with `item_ids` pays one receiver. Without `item_ids` it remains the single whole-amount payment for a one-receiver requisition, unchanged.

Gates, in order: payment permission; not the creator (unless separately authorised); approved and not yet received or accounted for; verification current; overdue-advance rule (W5-9, unchanged); not a supplier-bill requisition; then the checks in §11.

On the first payment the requisition's surrender due date is set from the approved setting, exactly as the single-payment path does.

The requisition page (`RequisitionReceiverPayments.vue`) shows what the brief specified: parent totals, each receiver with purposes and approved / paid / outstanding and a status, and **Process payment** only where the server says that receiver is payable. The dialog shows receiver, approved, already paid, outstanding, the balance after this payment, amount, method, paying account, date, transfer reference (required unless cash), an optional transfer charge, and the expense type (fixed when the requisition type sets it).

When payment is not available the page says why, in the server's words (awaiting approval, verification out of date, receiver not identified, and so on).

---

## 13. Parent derived status

`RequisitionControlProjection` computes, from active Payments only:

| Paid vs approved | Status |
|---|---|
| nothing paid | NOT DISBURSED |
| paid < approved | PARTIALLY DISBURSED |
| paid = approved | FULLY DISBURSED |
| paid > approved | OVER-DISBURSEMENT EXCEPTION |

The same is computed per receiver; a receiver with nothing paid is shown as AWAITING PAYMENT.

The existing `status` column is kept as the workflow stage older screens read (`approved` → `disbursed` → …). It becomes `disbursed` at the first payment and returns to `approved` if every payment is reversed. It is no longer the statement of how much was paid. The page banner now reads "Partially disbursed — KES 40,000 of KES 75,000 paid" instead of "Cash has been paid out", and the petty-cash overview counts only what was actually paid as an outstanding advance.

---

## 14. Reversal

The existing `PaymentReversalService` is used (endpoint and permission unchanged). Added for a receiver payment:

- the parent is locked first;
- the Payment is voided, its journals reversed, its allocations and history kept — nothing is deleted;
- paid and outstanding change at once, because they are derived;
- the parent's stage follows: `disbursed` while anything is still paid, `approved` when nothing is; a receipt confirmation that covered the reversed money is cleared and recorded in the audit entry;
- an audit entry records who, how much, why.

Worked example (tested): 75,000 / 75,000 paid; reverse Timothy's 30,000 → 45,000 active paid, parent PARTIALLY DISBURSED, Timothy outstanding 30,000. The replacement payment brings it back to FULLY DISBURSED.

Lines that have payment history can no longer be rewritten, even when nothing is currently paid, because the reversed Payment's allocations still point at them (tested).

---

## 15. GL / accounting integration

No new accounting logic was written. Reused as they are: `PaymentSettlementService` (cash side, float debit, paying-account checks), `JournalPostingService::postPettyCashAdvance` and `postPaymentFee` (Dr 1300 Staff Advances / Cr paying account; fee to bank charges), `PaymentReversalService`, period controls and account mappings inside the posting funnel.

**The one-parent-journal problem is resolved.** `PettyCashAdvancePoster::attemptPayment()` posts per Payment and records the result on that Payment:

| State | Meaning |
|---|---|
| posted | `advance_journal_entry_id` set |
| failed | `advance_gl_posting_failed_at` and the reason set; the cash movement stands and is counted |
| pending | not yet attempted |
| reversed | Payment voided and its entry reversed |

Retry is safe: the entry number is derived from the Payment id, the Payment row is locked during posting, and a failed attempt rolls back to a savepoint before the failure is recorded. Tested: a forced failure is recorded on that Payment only; the retry posts once; repeating the retry posts nothing; other Payments are untouched. The parent's own journal link stays empty for a requisition paid by receiver.

`POST …/retry-advance-posting` now retries every active Payment of the requisition and reports how many still fail. The petty-cash overview lists per-payment failures alongside the existing ones.

**For the accountant to confirm (§25):** every requisition payment posts to Staff Advances (1300), including a payment to a supplier or other recipient. That is the existing behaviour for requisition advances and I did not change it.

---

## 16. Project-cost integration

Unchanged and tested:

- a request is not a cost; approval records one **commitment**;
- a payment is not an expense; it creates no project actual;
- the accepted cost event is the surrender, which is not built here for receiver payments.

With several Payments, the commitment stands **in full** while any part is unpaid, and is released by the Payment that completes the requisition — through the existing `PettyCashDisbursementPaid` → `PettyCashCostProducer` path, which is exactly what a single payment does today. Test: four transfers on a project requisition → still one CostLine, one open commitment until the last payment, zero actual lines, zero lines sourced from a Payment.

Two consequences to be aware of:

1. Between full payment and accountability the project shows neither commitment nor actual. This is today's behaviour for every paid requisition, not new.
2. Reversing a payment does not re-open the commitment. Also today's behaviour for a single-payment void. With part payment it becomes more visible (§24).

---

## 17. Accountability integration / preparation

No second surrender workflow was built.

What now exists for the accountability phase to use: for every line, the receiver, the Payment(s) that funded it, the amount, and through the Payment the paying account, date and reference. One query on `requisition_payment_allocations` answers "who was paid what, from which account, for which purpose".

**Exact remaining gap.** The existing surrender assumes one Payment, one paying account and the whole parent amount: it clears the advance up to the parent total, and routes returned cash and overspend to the single account on `requisition->disbursement`. Applied to a requisition paid in several transfers from possibly several accounts — or only part paid — it would clear and route against the wrong figures. So `submitSurrender` and `reconcileSurrender` **refuse** a requisition paid by receiver, with a plain message. This is a deliberate stop, not a completed feature.

Receipt confirmation has the same limit. The whole-requisition signature is refused until every receiver is paid; a single line can be signed for only once that line is paid in full. There is no per-receiver receipt.

**Practical effect:** money paid through the new path cannot yet be accounted for in the system. It sits in Staff Advances until the follow-up phase is built. WNG should decide whether to use receiver payment in production before that phase (§25).

---

## 18. Permissions

| Act | Authority | Notes |
|---|---|---|
| Create | any requester | cannot pay their own requisition |
| Verify | `finance.requisitions.verify`, assigned verifier only | unchanged (75R) |
| Approve | `finance.petty_cash.edit_disbursement` | unchanged |
| **Pay** | `finance.petty_cash.create_disbursement` | checked in the service as well as the controller |
| Retry posting | `finance.petty_cash.edit_disbursement` | unchanged |
| Reverse | `finance.payments.reverse` | unchanged |

Tested: the verifier, the creator and the approver are each refused (HTTP 403 and at service level) unless they also hold the payment permission; a creator who does hold it still cannot pay their own requisition.

No new permission was added and no role was granted anything. Approver and payer are separate permissions but may be held by the same person; whether they must be different people is a WNG decision (§25).

---

## 19. Audit story

The projection returns a `story` list, oldest first, built from the records themselves. Real output from the test database:

```
Created — KES 75,000.00 requested · Rita Creator
Verified · Winnie Verifier
Approved — KES 75,000.00 approved · Finance Approver
D01 — Steve Otieno — KES 20,000.00 paid · PAY-2026-0001 · Finance Cashier
D02 — Timothy Mwangi — KES 20,000.00 part payment. Timothy Mwangi outstanding: KES 10,000.00 · PAY-2026-0002 · Finance Cashier
Awaiting payment — Winnie Supplies — KES 25,000.00
Overall — KES 40,000.00 / 75,000.00 partially disbursed
```

Reversals appear as their own entries with the reason. Separately, each payment and reversal writes a `GovernanceAuditLog` entry against the requisition (who, child reference, PAY number, receiver, lines funded, outstanding afterwards), shown under "Control history".

---

## 20. Reporting / search

| Question | Where |
|---|---|
| partially / fully / not disbursed, over-disbursed | `GET …/requisitions?disbursement_status=` and `GET …/finance/receiver-balances?disbursement_status=` (derived in SQL from active Payments) |
| receiver outstanding amounts; approved vs disbursed | `GET …/finance/receiver-balances` |
| payments by receiver | `GET …/finance/requisition-payments?receiver_type=&receiver_id=` or `search=` name |
| payments by parent | `…/requisition-payments?requisition_id=` |
| reversals | `…/requisition-payments?status=reversed`; `…/requisitions?has_reversed_payments=1` |
| instalments | `…/requisition-payments?instalments=1` |
| child reference, PAY reference | `search=` on both lists |
| project, requester, verifier | `project_id` / `enquiry_id`, `requester_id` / `user_id`, `verifier_id` / `responsible_verifier_id` |

Both new endpoints need the existing "view all requisitions" authority. They are API queries; no new report screen was built for them (§24).

---

## 21. Frontend / mobile

- `RequisitionReceiverPayments.vue` (new) — totals, receivers, dialog, payments, ledger state, retry, reversal, story.
- `RequisitionVerificationPanel.vue` — now verification plus the component above.
- `RequisitionForm.vue` — each line on a per-recipient requisition chooses Employee / Supplier / Other approved recipient; a supplier is picked from the list (nothing pre-selected); another recipient needs a name and a reference.
- `RequisitionShow.vue` — truthful banner for part-paid and multi-receiver requisitions.

Buttons come from the server (`can_pay`, `can_reverse`, `posting.can_retry`), not from frontend permission checks. Slim Poppins and the Finance control styles are kept. On a phone the receiver cards stack and the dialog becomes a bottom sheet.

Screenshots, desktop 1440 and mobile 390, taken from the real component fed with real projection output from the test database: `75ra-evidence/screenshots/` (18 images: approved, partial, payment dialog, over-amount refusal, verifier without payment authority, posting failure, full, reversed, dark).

Not checked in a browser against a running backend: the dev database has not had the new migrations applied (I did not run them), so the live page was not exercised end to end. The API contract is covered by the backend feature tests instead.

---

## 22. Tests

**New backend tests** — `tests/Feature/PettyCash/`:

| Brief §20 item | Test |
|---|---|
| one receiver → one payment | `one_receiver_one_payment` |
| three receivers → three payments | `three_receivers_three_payments_fully_disburse_the_parent` |
| many lines → one grouped payment | `one_grouped_payment_keeps_each_line_purpose` |
| one allocation → two instalments | `one_allocation_paid_in_two_instalments` |
| mixed receivers refused | `one_payment_cannot_mix_receivers_and_moves_no_cash` |
| above outstanding refused | `payment_above_the_outstanding_allocation_is_refused` |
| two concurrent payments cannot overpay | concurrency test, 2 and 5 racers |
| duplicate request / idempotency | `a_replayed_request…`, `a_double_click_through_the_api…`, concurrency replay |
| child reference uniqueness | three-receiver test; 5-racer test; reversal test (D04) |
| parent FK retained | three-receiver test |
| reversal reduces active paid | `reversal_reduces_active_paid_and_keeps_the_history`, `reversing_the_only_payment…` |
| parent / receiver partial and full status | instalment and three-receiver tests |
| verification still required | `verification_is_still_required_at_the_moment_of_payment` |
| material edit revokes verification | `a_material_edit_revokes_verification_and_blocks_payment` |
| approval still required | `approval_is_still_required` |
| payment permission enforced | `payment_needs_payment_authority…`, `the_creator_cannot_pay_their_own…` |
| PaymentSettlementService reused | `the_payment_goes_through_the_existing_settlement_service` |
| GL no duplicate; retry safe | `each_payment_posts_its_own_journal_once…`, `a_posting_failure_is_recorded…` |
| reversal correct | reversal tests (journal reversed, history kept) |
| CostCollector no duplicate actual; commitment correct | `several_payments_create_no_actual_cost…` |
| surrender not duplicated | `the_existing_surrender_is_not_duplicated…` |
| also | receiver projection, typed-name rules, submission rules, API storage, receipt guard, story, search and reporting |

Result: **29 feature + 3 concurrency = 32 passed**; with the 13 Report 75R tests, **45 of 45** (`75ra-evidence/focused-final.txt`; that log shows 46 because it also ran a temporary fixture-export test, since deleted).

**New frontend tests:** `receiverPayments.spec.ts` (18) and three added to `requisitionForm.spec.ts`; `requisitionVerification.spec.ts` (4) updated to stable selectors. All pass.

**Regression** — run serially on an isolated test database (`db_scratch_test`), not the shared `db_test`:

| Scope | Result |
|---|---|
| `tests/Feature/PettyCash`, `Finance`, `CostCollector`, `Procurement`, `Seeding` and `tests/Unit` | **1,257 passed, 5 failed** (10,412 assertions) |

The 5 failures are all `W7LabourConcurrencyTest`, which refuses to run on any database but the shared `db_test` ("commits data and must only run against db_test"). It was not run there, to avoid colliding with other work on that database; nothing in this report touches labour. Every other test in scope passes, including Report 75R, petty cash, payment and settlement, payment reversal, CostCollector, project cost, the Finance reset boundary and the permission suites (`75ra-evidence/regression-final.txt`).

An earlier run of the same scope showed 19 failures. Each was traced and none was left: 6 were an artefact of my editing a file while the run was in flight, 6 were the Stab7 fixture (§23), 2 were the reset-plan defect (§23), 5 were W7 as above.

**Frontend:** full suite 526 passed, 5 failed. The 5 are the long-standing `tests/unit/design/designTables.spec.ts` failures, unrelated and unchanged. **TypeScript:** 273 errors, equal to the baseline, none in files touched here. **Vite build:** passes. **`git diff --check`:** clean in both repositories.

---

## 23. Files changed

**Backend — new**

- `app/Modules/Finance/PettyCash/Services/RequisitionDisbursementReport.php`
- `tests/Feature/PettyCash/RequisitionReceiverDisbursementConcurrencyTest.php`
- `tests/Support/ReceiverRequisitionFixture.php`
- `docs/finance-redesign/phase-2/75RA_FINANCIAL_REQUISITION_RECEIVER_DISBURSEMENT.md`, `75ra-evidence/`

**Backend — started by the earlier session, rewritten or completed here**

- `database/migrations/2026_10_06_000004_add_requisition_payment_allocations.php` (one FK changed to SET NULL, see below)
- `app/Modules/Finance/PettyCash/Models/RequisitionPaymentAllocation.php` (kept as found)
- `app/Modules/Finance/PettyCash/Services/RequisitionDisbursementService.php` (rewritten)
- `app/Modules/Finance/PettyCash/Services/RequisitionReceiverIdentity.php` (rewritten)
- `app/Modules/Finance/PettyCash/Services/RequisitionControlProjection.php` (rewritten)
- `tests/Feature/PettyCash/RequisitionReceiverDisbursementTest.php` (rewritten)

**Backend — changed**

- `PettyCashAdvancePoster.php`, `PaymentReversalService.php`, `PettyCashService.php`, `RequisitionVerificationService.php`
- `PettyCashRequisitionController.php`, `PettyCashWorkspaceController.php`, `PettyCashActions.php`
- `PettyCashRequisition.php`, `PettyCashRequisitionItem.php`, `Payment.php` (as found)
- `FinanceResetBoundary.php`, `routes/api.php`
- `tests/Feature/Finance/Stab7PettyCashTriplePostingTest.php` (fixture only)

**Frontend**

- new: `petty-cash/receiverPayments.ts`, `petty-cash/receiverPayments.spec.ts`, `petty-cash/components/RequisitionReceiverPayments.vue`, `tests/fixtures/75ra/`
- changed: `RequisitionVerificationPanel.vue`, `requisitionVerification.spec.ts`, `RequisitionForm.vue`, `RequisitionShow.vue`, `types/api.ts`, `tests/unit/finance/requisitionForm.spec.ts`

**Defects corrected in the earlier start**

1. The test could not run (missing permission fixture, no requisition number).
2. Posting retry referenced an undefined `$request` and would have failed at runtime.
3. The new `payments.advance_journal_entry_id` foreign key was RESTRICT and the new table was missing from the controlled Finance reset plan; `FinanceResetBoundaryTest` failed. Fixed: SET NULL (as on the parent's own link) and the table added to the plan.
4. Authorization failures inside the payment path would have surfaced as HTTP 500.
5. Posting was scheduled with `afterCommit` inside the transaction; it now runs after the transaction returns, and never on a replay.
6. A failed post and its failure record shared one transaction with no savepoint.
7. Nothing kept the parent's stage in step after reversal, and nothing stopped a part-paid requisition being signed as received (which would then block the remaining payments).
8. Lines with reversed-payment history could be rewritten, which would have hit a foreign-key error.
9. A supplier line was rejected by the per-recipient form rules unless a name was also typed.
10. The commitment lifecycle for part payment was not handled.

**Fixture, not production, change:** `Stab7PettyCashTriplePostingTest` built an approved requisition with no verification and had been failing since Report 75R's payment gate. It now goes through the real verification fixture. No production rule was relaxed.

**Not applied anywhere but the test database:** migrations `2026_10_06_000001`–`000004` are not in the local dev database, and I did not run them. A push to `master` runs migrations in production.

---

## 24. Remaining software gaps

1. **Receiver-level accountability.** Surrender and reconciliation refuse a requisition paid by receiver. Needed: accounting per receiver, clearing each Payment's advance, routing returns and overspend to the right paying account.
2. **Receiver-level receipt confirmation.** Only whole-requisition and whole-line signatures exist, both held back until paid in full.
3. **Unused balance cancellation and parent closure.** A requisition that will never be fully paid has no way to release the remainder or close. Its commitment stays open.
4. **Commitment after reversal.** Reversing a payment on a fully paid requisition does not re-open the project commitment (existing behaviour, now more exposed).
5. **Other advance totals.** The petty-cash overview was corrected. The outstanding-advances list, the control summary and the overdue-advance check still use the parent total for a part-paid requisition.
6. **Per-line split of a partial grouped payment.** The amount fills lines in order; Finance cannot choose the split.
7. **Screens for the new report queries.** The two endpoints exist; no list screen was built.
8. **Legacy readers of `requisition->disbursement`.** Voucher PDF and some older panels read a single payment and will show only the first one for a requisition paid by receiver.
9. **Open requisitions with several typed names.** These can no longer be paid as one payment and cannot be paid by receiver until edited to identify each receiver, then re-verified and re-approved. No data was changed; I did not count how many exist in production.
10. **End-to-end browser check** against a migrated backend (§21).
11. **`W7LabourConcurrencyTest`** only runs on the shared `db_test` and was not run (§22).

---

## 25. Remaining WNG decisions

1. Should receiver payment go live before receiver-level accountability exists, given that money paid this way cannot yet be surrendered in the system?
2. Should a payment to a supplier or other recipient through a requisition post to Staff Advances (1300), as now, or to a different control account?
3. Must the person who pays be different from the person who approved? Today they are separate permissions that one person may hold.
4. Is a per-requisition reference enough for "other approved recipients", or does WNG want a maintained register of them? What counts as an acceptable reference?
5. Who holds `finance.petty_cash.create_disbursement` and `finance.payments.reverse` in production? No role was changed here.
6. While a requisition is part paid, should the project show the full commitment (as built) or only the unpaid part?
7. How should an approved but never fully paid requisition be closed, and who authorises releasing the remainder?
8. How should existing open requisitions with several typed names be handled (gap 9)?

---

## 26. Verdict

**FINANCIAL REQUISITION RECEIVER DISBURSEMENT COMPLETE — ACCOUNTABILITY/CLOSURE FOLLOW-UP REMAINS**

The disbursement architecture the brief asked for is implemented and tested: the bridge, receiver identity, grouped and instalment payment, overpayment and concurrency control, idempotency, child references, per-Payment ledger state, reversal, derived status, the Finance screen, the story and the queries. Receipt confirmation, accountability, cancellation and closure for receiver-paid requisitions are **not** implemented and are not claimed; items 1–3 of §24 are that follow-up, and until it is built a receiver-paid requisition cannot be surrendered.
