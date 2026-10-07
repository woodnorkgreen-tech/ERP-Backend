# Report 75R-B — Financial Requisition Receiver Accountability, Reconciliation & Closure

Date: 2026-10-06 · Continuation of Reports 75R and 75R-A · Backend `master` @ `5a5a3db`, frontend `master` @ `b82f278`, both with uncommitted work in the tree. Nothing was committed, pushed, merged, stashed or deployed. No production or development database was touched; migrations ran only on the disposable test database.

---

## 1. Executive summary

A requisition paid to several receivers can now be taken to its end: each receiver confirms receipt, accounts for their money in one or more stages, returns what is unused, Finance reconciles, an unpaid approved balance is formally released, and the parent closes — only when it reconciles.

- **One calculation.** `RequisitionPosition` derives every figure (approved, disbursed, released, confirmed, accepted, returned, under review, to disburse, to account) from the records. The screen, reports, advance totals, commitment and closure all read it. Nothing is stored.
- **Receipt confirmation** is per Payment, per receiver. It cannot exceed what was paid, and a reversed Payment's confirmation stops counting.
- **Accountability** is the existing surrender, extended: a surrender now belongs to one receiver, can be staged, keeps its requisition lines, and clears named Payments.
- **Returns** are their own money movement into the account the money left, never netted.
- **Overspend** is held as "requires resolution". It creates no payment, expense, reimbursement or advance.
- **Project cost:** a payment is never a cost; each accepted item reaches the cost collector once.
- **Commitment** now equals what is still promised: approved − accepted − returned − released.
- **Unused balance** is released by a recorded decision; the approved amount is never edited.
- **Closure** is refused, with reasons naming the receiver, until everything reconciles. There is no "mark closed".
- **Staff Advances question** is in Finance Setup as an open decision per kind of receiver. The system did not choose.

Tests: 30 new backend tests (2 of them real multi-process races) and 21 new frontend tests pass. A live browser run against a migrated throwaway backend completed the whole workflow (§23). Regression figures are in §22.

What is not done is listed in §25; the decisions WNG still owes are in §26.

---

## 2. 75R / 75R-A baseline

Both reports were read in full and their controls kept. At the end of this work the 75R tests (13) and the 75R-A tests (28 feature, 3 concurrency) pass.

Three 75R-A expectations were changed on purpose, because this report replaces the behaviour they described:

| 75R-A behaviour | Now | Why |
|---|---|---|
| Commitment released by the payment that completes the requisition | Commitment stands until accounted for, returned or released (§11) | Paid in full is not a cost; the old rule left the project showing nothing |
| A fully paid line could be signed for with the old per-line signature | Refused; receipt is confirmed per receiver (§3) | One signature mechanism per requisition, and the old one blocks further payment |
| `software_gaps` listed receipt, accountability, cancellation, closure | Empty | They are implemented |

Two 75R-A tests were merged into one; nothing was dropped.

---

## 3. Receiver confirmation

Table `requisition_receipt_confirmations`: one row per Payment confirmed, carrying requisition, Payment, receiver, amount, who confirmed, when, on what basis, and evidence.

- **Independent.** One receiver confirms without waiting for the others (tested with three).
- **Per Payment.** A receiver paid in two instalments confirms each as it arrives.
- **Cannot exceed active paid.** There is at most one standing confirmation per active Payment; confirming twice, or confirming someone else's Payment, is refused.
- **Reversal.** Before confirmation, a reversed Payment is simply not there to confirm. After confirmation, the row is kept and marked invalidated with the reason, and it stops counting.

**Who may confirm (§4 of the brief).** The existing rule was reused, not widened: the receiver, or the requester. Finance, the verifier and the approver cannot.

- An Employee receiver with a login confirms for themselves (`basis = receiver`).
- For a receiver with no login (a supplier, another recipient, an employee without an account) the requester confirms, and must state what they rely on: an evidence reference or a note. The record shows *confirmed by*, *basis = on_behalf*, *receiver represented* and *evidence*. No login is invented for a receiver.

An employee named as a receiver can now open the requisition they are paid under; before, only the requester, verifier and Finance could.

---

## 4. Accountability architecture

No second surrender system was built. What changed in the existing one:

| Existing piece | Change |
|---|---|
| `petty_cash_surrender_items` | gains `surrender_id` and `requisition_item_id` (the line it accounts for) |
| surrender state held on the requisition | a header, `petty_cash_surrenders`, so a surrender can belong to one receiver and be one of several stages |
| duplicate-receipt rule (W3-5) | extracted to `SurrenderReceiptGuard`; both surrenders call it |
| project cost at surrender (STAB-7) | extracted to `SurrenderCostPoster`; both surrenders call it |
| expense and VAT journal legs | extracted to one method in `JournalPostingService`; both surrenders use it |
| review history | same `petty_cash_surrender_reviews` table |

A requisition paid as one payment keeps using the surrender columns on the requisition exactly as before; its tests pass unchanged.

**Life of a receiver surrender:** submitted → reconciled, or returned for correction → resubmitted. A reconciled one can be reversed. One stage is open per receiver at a time. References are `REQ-…-A01`, `-A02`, … numbered under the parent lock.

**The invariant**, per receiver and per line:

```
RECEIVED = ACCEPTED SPEND + RETURNED + STILL OUTSTANDING
```

Only confirmed money can be accounted for. Line-level figures are kept: in the brief's example Steve's Site facilitation shows funded 15,000, accepted 14,500, returned 500; Casual support funded 5,000, accepted 5,000 (tested).

**Staged accountability** works: Timothy 30,000 → stage 1 accepts 18,000 (12,000 still to account) → stage 2 accepts 10,000 and returns 2,000 → accounted in full (tested, and done live).

---

## 5. Payment / accountability relationship

The unit a surrender clears is a **slice**: one requisition line as funded by one Payment (a 75R-A `requisition_payment_allocations` row). Table `petty_cash_surrender_allocations` records, for each surrender, how much of which slice it accounts for, as spend or as return.

So for any accepted amount or return the system knows the receiver, the line, the Payment and child reference, and the paying account. Nothing reads `requisition->disbursement` for a receiver-paid requisition.

- Spend fills a line's slices in the order they were paid. This is safe to automate: the credit goes to the advance account, not to a paying account.
- At reconciliation each Payment's advance is cleared from the account its own advance journal debited, read from that journal — not from today's configuration.
- A Payment whose advance has not reached the ledger cannot have money reconciled against it.

---

## 6. Returns

A return is a money movement. At reconciliation each return becomes a `CashMovement` (the existing cash-movement service): direction *in*, into the paying account, offset to the advance account, with its own journal, date, reference, counterparty and audit. Where the account is the petty-cash float, the float's cashbook is credited as the old surrender does. Returned money is not in the surrender's clearing entry.

**Which Payment a return relates to:**

- The submitter may name it.
- If they do not, and the money still held on that line came from one paying account, it is placed oldest Payment first.
- If it came from more than one account, the return is refused until a Payment is chosen. The screen then shows the choice. Nothing is guessed.

Supported and tested: full unused return (no receipts, no expense entry), partial return, return against a specific instalment, and a receiver funded from two accounts. A return above what is held on the line is refused.

Reversing a surrender voids its cash movements with reversing journals and debits the float back. Nothing is deleted.

---

## 7. Overspend

**Existing behaviour found:** the one-payment surrender, when receipts exceed the advance, posts the excess as a credit to the paying account described as "reimbursement" — with no Payment, no approval and no float movement. No approved overspend or reimbursement process exists in the ERP.

**Receiver surrender:** that behaviour was not carried over. A claim above what the receiver holds is recorded and shown as **OVERSPEND REQUIRES RESOLUTION**. It cannot be reconciled, blocks closure, and creates no payment, journal, cost line or cash movement (tested by counting all four). The only resolution in software is for Finance to return it and the receiver to resubmit within the advance.

**Policy gap:** how genuine overspend is approved and paid is undecided (§26). The one-payment surrender's automatic reimbursement leg was left as it is and is flagged for the same decision.

---

## 8. GL treatment

| Event | Entry |
|---|---|
| Payment (75R-A) | Dr advance control · Cr paying account — one per Payment |
| Surrender reconciled | Dr expense / WIP (net) per item · Dr input VAT on ETR receipts · Cr advance control per Payment cleared |
| Return | Dr paying account · Cr advance control — one cash movement each |
| Surrender reversed | reversing entries for the clearing journal and each return |

All through `JournalPostingService::postBalancedEntry`, so period controls apply. A closed period or unpostable account rolls the whole reconciliation back.

In the live run the advance account ended at zero: 65,000 debited, 65,000 credited (61,500 expensed, 3,500 returned), with 2,000 back in the second bank account it came from. All 33 journals balanced (`75rb-evidence/e2e/ledger-check.txt`).

**Staff Advances decision (§10 of the brief).** Not chosen. Three items were added to Finance Setup (Report 75) under accounting policies, one each for Employee, Supplier and Other approved recipient, asking: *"How should money advanced through a Financial Requisition be controlled before accountability?"* Each needs accountant approval, offers no suggested answer, and creates no account.

- Until one is approved and active, payments keep going to Staff Advances, and both Finance Setup and the requisition page label that as *existing behaviour, not an approved accounting decision*.
- Once one is approved and active, new payments to that kind of receiver use it. Advances already posted stay where they are and are cleared from there.

---

## 9. Project-cost lifecycle

- Request: not a cost. Approval: one commitment.
- Payment: never a cost. No payment creates a cost line.
- Accepted surrender item: one actual cost line, keyed to that item, so staging cannot duplicate it.
- Return: no cost line.

Brief's example, tested: accepted 19,500 + 28,000 + 24,000 = **71,500 actual**, 3,500 returned, four cost lines, none from a Payment. Reconciling the same surrender again is refused and changes nothing.

---

## 10. Part-paid exposure

One definition, `PettyCashRequisition::scopeOutstandingAdvances()`: a requisition in an advance stage with money still out, carrying `advance_exposure`.

- Paid by receiver: active Payments − reconciled accepted − reconciled returned.
- Paid as one payment: the whole amount until its surrender is reconciled (unchanged).

Tested equal to the position's "to account". Every reader 75R-A listed now uses it: petty-cash overview, outstanding-advances list, control summary, both overdue-advance checks, and the direct-request approval signals. Approved 75,000 / paid 40,000 reads 40,000. Once everything paid is accounted for, the requisition is not an outstanding or overdue advance, even with money still unpaid.

---

## 11. Commitment lifecycle

For a requisition paid by receiver:

```
commitment = approved − accepted spend − returned − released
```

| Stage | Commitment | Actual |
|---|---|---|
| Approved, unpaid | 75,000 | 0 |
| Part paid | 75,000 | 0 |
| Fully paid, not accounted | 75,000 | 0 |
| A payment reversed | 75,000 | 0 |
| Steve reconciled (19,500 accepted, 500 returned) | 55,000 | 19,500 |
| 10,000 released | 45,000 | 19,500 |
| Steve's surrender reversed | 65,000 | 0 |

Tested step by step. Commitment plus actual never exceeds what was approved, and only one commitment line is open. This fixes both 75R-A gaps: the commitment no longer vanishes at full payment, and it is correct after a reversal.

The four positions the brief asked for are all derived: approved but unpaid (`to_disburse`), paid but not accounted (`to_account`), accepted actual, released.

A one-payment requisition keeps its existing lifecycle (commitment released at payment). That was not changed (§26).

---

## 12. Unused balance release

`POST …/release-unused` records, per receiver, that an amount of approved money will not be paid: amount, reason, who, when, audit with before and after.

- The approved amount is never edited. The record reads Approved 75,000 · Disbursed 65,000 · Released 10,000.
- Cannot exceed what is approved and unpaid for that receiver. Released money can no longer be paid.
- Idempotent on a request key. Three people releasing 6,000 each from 10,000 at the same moment: one succeeds (real race, tested).
- The requester cannot release their own requisition's balance.

**Authority.** No existing permission covers giving up approved money (rejection applies only before approval). A new permission, `finance.requisitions.release_unused`, was added through the registry and **granted to no role**. It is checked directly, so an administrator does not hold it by default (tested). WNG must assign it (§26).

A release cannot be undone in software (§25).

---

## 13. Parent closure

`RequisitionClosureService::blockers()` lists every reason the requisition does not reconcile; `close()` succeeds only when the list is empty.

Conditions: approved; nothing over-disbursed; every receiver identified; nothing approved left unpaid and unreleased; every paid amount confirmed; no overspend; no surrender awaiting Finance or returned and not resubmitted; nothing left to account; every active Payment in the ledger.

That is: `APPROVED = DISBURSED + RELEASED` and `DISBURSED = ACCEPTED + RETURNED`.

Tested: refused with outstanding accountability, with a posting failure, with unresolved overspend, with unconfirmed receipt, with an unpaid balance; succeeds when reconciled; closing twice changes nothing; a closed requisition takes no payment, confirmation, surrender or release. Each blocker names the receiver holding it up.

Closing is done by Finance's reconciliation authority, not the requester. On closing the workflow status becomes `surrendered`, the stage older screens already read as accounted for.

---

## 14. Derived statuses

`control_state` is the one business state shown: awaiting verification, awaiting approval, awaiting disbursement, partially disbursed, awaiting receipt confirmation, awaiting accountability, partially accounted, accountability review, unused balance requires release, ready to close, closed, exception.

Each receiver has its own state (awaiting payment, awaiting receipt confirmation, to account, with Finance for review, returned for correction, overspend requires resolution, balance to pay or release, complete).

The workflow `status` column is kept for compatibility. The page header, banner and panel all show the derived state for a receiver-paid requisition. Fully paid is never shown as closed.

---

## 15. Reversal / correction

| Situation | Outcome |
|---|---|
| Payment reversed before confirmation | allowed; nothing to confirm |
| Payment reversed after confirmation | allowed; confirmation invalidated and kept |
| Payment reversed with a surrender submitted or reconciled against it | refused, naming the surrender; reverse or return that first |
| Payment reversed on a closed requisition | refused |
| Reconciled surrender reversed | reversing journals, returns voided, cost lines retired, items kept as superseded; needs journal-reversal authority; not the requester |
| Surrender reversed on a closed requisition | the requisition reopens, with its own audit entry |

After a reversal the receiver accounts again as a new stage. The reverse-payment button is not offered where the server would refuse.

---

## 16. Legacy compatibility

Every use of `requisition->disbursement` was reviewed.

| Reader | For a receiver-paid requisition |
|---|---|
| Voucher PDF | lists every payment (paid or reversed), receiver, both references, and total paid against approved |
| Finance workspace detail | total of active payments, "N payments", "N paying accounts", plus the full list; surrender figures from the position |
| Finance list rows | total paid and number of payments |
| Requisition page | timeline entry, banner and header chip from the position; one-payment signature, QR sign-off and surrender controls withheld |
| Requisition list | "paid by receiver · X of Y" |
| Outstanding-advances list | first payment date across all payments |
| Whole-requisition receipt, per-line signature, public sign-off link, whole-requisition surrender and reconcile | refused, pointing to the receiver list |

Readers that stay single-payment by design, because they are only reached for one-payment requisitions: the original advance poster, the one-payment surrender posting and its reversal.

A one-payment requisition was run through pay → receipt → surrender → reconcile in the new test suite and behaves as before.

---

## 17. Existing-data compatibility

`php artisan finance:requisition-receiver-compatibility` (and `GET …/finance/receiver-compatibility`) reads open, unpaid requisitions and classifies each. It writes nothing; a test compares the rows before and after.

| Classification | Meaning |
|---|---|
| READY FOR LEGACY SINGLE PAYMENT | one receiver, verified, approved |
| READY FOR PAYMENT BY RECEIVER | every receiver identified, verified, approved |
| REQUIRES RECEIVER IDENTIFICATION | several receivers, at least one named only by typed name |
| REQUIRES RE-VERIFICATION | details not currently verified |
| REQUIRES RE-APPROVAL | verified, not approved |
| MANUAL REVIEW | supplier-bill requisition, no lines, or lines not adding up |

"John Kamau" and "John Kamau Jr" are reported as two names (tested). No identity is proposed.

**It has not been run against production or the development database.** I have no count of affected requisitions.

---

## 18. Permissions

| Act | Authority | Source |
|---|---|---|
| Request | any requester | existing |
| Verify | `finance.requisitions.verify`, assigned verifier | 75R |
| Approve | `finance.petty_cash.edit_disbursement` | existing |
| Pay | `finance.petty_cash.create_disbursement` | existing |
| Confirm receipt | the receiver, or the requester on their behalf | existing rule |
| Submit accountability | the receiver, the requester, or Finance | existing surrender rule, plus the receiver |
| Reconcile / return | `finance.petty_cash.create_disbursement`; never the requester or the receiver | existing surrender rule |
| Release unused | `finance.requisitions.release_unused` | **new, granted to no role** |
| Reverse a surrender | `finance.journals.reverse` | existing |
| Reverse a payment | `finance.payments.reverse` | existing |
| Close | `finance.petty_cash.create_disbursement`; never the requester | existing reconciliation authority |

Holding one does not give another; each is checked where it is used and tested for refusal. Pay, reconcile and close share one permission because that is how the existing surrender is authorised. Whether to separate them is a WNG decision (§26).

---

## 19. Audit / story

Each action writes a `GovernanceAuditLog` entry against the requisition with actor, time, amount, receiver, child references, reason and before/after figures: receipt confirmed, accountability submitted / resubmitted / returned / reconciled / reversed, unused balance released, closed, reopened. Returns are in the reconciliation entry and in their own cash movement.

The **story** is built from the records, not the log. From the live run:

```
Created — KES 75,000.00 requested · Rita Wanjiru
Verified · Winnie Achieng
Approved — KES 75,000.00 approved · Finance Approver
D01 — Steve Otieno — KES 20,000.00 paid · Finance Cashier
D02 — Timothy Mwangi — KES 20,000.00 part payment. Timothy Mwangi outstanding: KES 10,000.00
D03 — Timothy Mwangi — KES 10,000.00 paid
D04 — Winnie Supplies — KES 15,000.00 part payment. Winnie Supplies outstanding: KES 10,000.00
Receipt confirmed — Steve Otieno confirmed KES 20,000.00 · Steve Otieno
Receipt confirmed — Timothy Mwangi confirmed KES 30,000.00 (confirmed on their behalf) · Rita Wanjiru
A01 reconciled — Steve Otieno accounted KES 19,500.00; returned KES 500.00 · Finance Cashier
A04 reconciled — Timothy Mwangi accounted KES 10,000.00; returned KES 2,000.00 · Finance Cashier
Unused balance released — Winnie Supplies — KES 10,000.00 will not be paid: … · Finance Controller
Closed — Reconciled in full · Finance Cashier
Overall — KES 75,000.00 approved; KES 65,000.00 disbursed; KES 10,000.00 released; KES 61,500.00 accounted; KES 3,500.00 returned; KES 0.00 awaiting accountability
```

(abridged; the full story is in `75rb-evidence/e2e/result.json`).

---

## 20. Reporting

Existing queries were extended; no new tracker was created.

| Question | Where |
|---|---|
| Who received money, how much, under which requisition, which child payment, from which account, who paid | `GET …/finance/requisition-payments` |
| Has receipt been confirmed, by whom, on what evidence | same, `receipt`; filter `receipt=confirmed\|unconfirmed` |
| How much is accounted for, returned, still to account, per payment | same, `accounted`, `returned`, `to_account` |
| Per requisition and per receiver: approved, disbursed, confirmed, accounted, returned, released, to account | `GET …/finance/receiver-balances` |
| Which receiver is holding up closure, and why it cannot close | same, `closure_blockers` |
| Who verified, approved, closed | same; who reconciled and who released are in the story and audit on the requisition |
| Open requisitions that cannot be paid as they stand | `GET …/finance/receiver-compatibility` |

These are API queries. The requisition page shows all of it for one requisition; no cross-requisition list screen was built (§25).

---

## 21. Frontend / mobile

- `RequisitionReceiverPayments.vue` — the eight parent figures; control state; "why this requisition cannot close yet"; receiver cards with approved / paid / receipt confirmed / accepted / returned / still to account; each receiver's surrender stages; View payments, Confirm receipt, Submit or Correct accountability, Release unused balance, Reconcile, Return, Reverse, Close.
- `RequisitionAccountabilityDialog.vue` — receipts against requisition lines, returns, the payment choice when required, an overspend warning before submitting.
- `RequisitionShow.vue`, `RequisitionIndex.vue`, `RequisitionFinancePanel.vue` — truthful for receiver-paid requisitions.

Every button is one the server offered (`actions`, `can_close`, `can_reverse`); tested that none appears otherwise. Amounts are never pre-filled for accountability or release. Slim Poppins and the Finance control styles are kept. On a phone the cards stack and dialogs become bottom sheets.

---

## 22. Tests

**New backend** — `RequisitionReceiverAccountabilityTest` (28) and two races added to the concurrency test:

| Brief §27 | Covered by |
|---|---|
| three receivers confirm independently; who may confirm | `three_receivers_confirm_independently`, `only_the_receiver_or_the_requester_may_confirm` |
| two Payments; cannot confirm more than paid | `a_receiver_paid_twice_confirms_each_payment…` |
| reversal before / after confirmation | `reversal_before_and_after_confirmation` |
| partial and two-stage accountability | `accountability_in_two_stages`, `one_stage_at_a_time_and_finance_can_return_it` |
| line purposes retained | `line_purposes_are_retained_through_accountability` |
| returns against correct Payment/account; multiple accounts | `returned_money_is_its_own_movement…`, `a_return_across_two_paying_accounts…`, `a_full_unused_return_needs_no_receipts` |
| overspend unresolved | `overspend_is_held_for_resolution…` |
| no Payment creates actual; accepted once; return no cost | `accepted_accountability_reaches_project_cost_exactly_once` |
| part-paid totals; overdue checks | `advance_totals_and_overdue_checks_use_what_is_actually_out` |
| commitment: partial, full, reversal | `the_commitment_follows_what_is_still_promised_at_every_stage` |
| release; cannot exceed; concurrent | `unused_approved_balance_is_released…`; race `simultaneous_releases…` |
| closure refused ×4; succeeds when reconciled | `closure_is_refused_until_the_requisition_reconciles`, `the_brief_worked_example_closes` |
| reversal after accountability | `a_payment_cannot_be_reversed_from_under_its_accountability`, `reversing_a_reconciled_surrender…` |
| legacy still works; readers show every payment | `a_single_payment_requisition_is_untouched…`, `single_payment_readers_show_every_receiver_payment` |
| ambiguous data reported, not guessed | `existing_ambiguous_receivers_are_reported_and_never_guessed` |
| permissions, audit, idempotency | `who_may_account_and_who_may_reconcile`, `the_story_and_audit…`, `a_replayed_submission…`; race `simultaneous_reconciliation_posts_one_clearing_entry` |
| also | advance-control labelling and governed override; reports |

**New frontend:** `receiverAccountability.spec.ts`, 21 tests.

**Regression** — serial, on the isolated test database:

| Scope | Result |
|---|---|
| `tests/Feature/PettyCash`, `Finance`, `CostCollector`, `Procurement`, `Seeding` and `tests/Unit` | **1,291 passed, 0 failed** (10,779 assertions) |

This covers Reports 75R and 75R-A, petty cash, the one-payment surrender, payment settlement and reversal, CostCollector, project cost, Finance, governance, the reset boundary, procurement and permissions (`75rb-evidence/regression-final.txt`).

One failure appeared on the way and was traced before being fixed. The first full run was 1,290 passed, 1 failed: `CostCollectorApiTest > the picker searches and filters by family`. It passed alone. It failed again with only the Finance directory in front of it and no petty-cash tests at all, so it did not come from this report's code. Cause: the race tests commit data and re-run a seeder that edits migration-created expense codes in place; their cleanup deleted new rows but did not put edited rows back, so an activated code leaked into the next test. It had been hidden because the labour race test never ran on the isolated database. The cleanup in both race tests now restores those rows. Test harness only; no production logic was changed to make a test pass (`75rb-evidence/regression-order-dependent-failure.txt`).

**W7LabourConcurrencyTest.** Its guard tied it to the database name `db_test`, so it could never run beside another session's suite. The guard now accepts the isolated test database as well and still refuses anything else, and its cleanup restores the catalogue rows its seeders edit. Test-harness changes only; no production code. It ran in the regression above and its 5 tests pass.

**Frontend:** full suite 547 passed, 5 failed — the same five long-standing `designTables.spec.ts` failures. **TypeScript:** 273 errors, equal to baseline. **Vite build:** passes. **`git diff --check`:** clean in both repositories.

---

## 23. End-to-end evidence

A throwaway backend (PHP's built-in server in a one-off container, **no queue worker**) ran on the disposable test database `db_scratch_test`, migrated to the latest schema. The real frontend dev server was pointed at it through a separate config. Headless Chrome was driven by script (`75rb-evidence/e2e/run.mjs`). Six people signed in through the real login endpoint. Both servers were stopped afterwards.

| Step | How | Result |
|---|---|---|
| Create, assign verifier | API, as the creator | REQ for 75,000, three receivers of three kinds |
| Verify | browser, as the verifier | Verified |
| Approve | API, as the approver | awaiting disbursement |
| Pay three receivers | browser, as the cashier | D01 Steve 20,000 · D02 + D03 Timothy 20,000 + 10,000 from two bank accounts · D04 Winnie 15,000 |
| Confirm separately | browser | Steve as himself; the requester for Timothy and Winnie with evidence |
| Account separately | browser | Steve 19,500 + 500 returned; Timothy 18,000; Winnie 14,000 + 1,000 returned |
| Reconcile | browser, as the cashier | three surrenders |
| Second stage | browser | Timothy 10,000 + 2,000 returned against D03 (the screen required the choice) |
| Release remainder | browser, as the controller | 10,000 of Winnie's allocation |
| Close | browser, as the cashier | closed |

End state: approved 75,000 = disbursed 65,000 + released 10,000; disbursed 65,000 = accounted 61,500 + returned 3,500; nothing to account; closed. Advance account net zero; 33 journals, none unbalanced.

Evidence in `75rb-evidence/e2e/`: 26 screenshots (13 moments, desktop 1440 and mobile 390), `result.json`, `ledger-check.txt`, and the scripts.

**Stated plainly:** *create* and *approve* were done through the API as the right user, not by driving the form and the approval dialog. Those two screens were not changed by this report. Sign-in was through the login endpoint with the token placed in the browser, not through the login page. Everything from verification to closure was done by clicking the real screens.

---

## 24. Files changed

**Backend — new**

- `database/migrations/2026_10_06_000005_add_requisition_receiver_accountability.php`
- Models: `PettyCashSurrender`, `PettyCashSurrenderAllocation`, `RequisitionReceiptConfirmation`, `RequisitionBalanceRelease`
- Services: `RequisitionPosition`, `RequisitionAccountabilityService`, `RequisitionClosureService`, `RequisitionReceiverCompatibility`, `SurrenderReceiptGuard`, `SurrenderCostPoster`
- `Finance/Support/RequisitionAdvanceControl.php`
- `RequisitionAccountabilityController.php`, `Console/Commands/RequisitionReceiverCompatibilityCommand.php`
- `tests/Feature/PettyCash/RequisitionReceiverAccountabilityTest.php`

**Backend — changed**

- `RequisitionControlProjection`, `RequisitionDisbursementService`, `RequisitionDisbursementReport`
- `PaymentReversalService`, `JournalPostingService`, `PettyCashCostProducer`, `GovernanceCatalogue`
- `PettyCashRequisitionController`, `PettyCashWorkspaceController`, `PettyCashControlController`, `PettyCashController`, `PettyCashActions`
- Models `PettyCashRequisition`, `PettyCashSurrenderItem`, `Payment`
- `Permissions`, `RolePermissions`, `FinanceResetBoundary`, `routes/api.php`, `requisition-voucher.blade.php`
- Tests: `RequisitionReceiverDisbursementTest`, `RequisitionReceiverDisbursementConcurrencyTest`, `ReceiverRequisitionFixture`, `W7LabourConcurrencyTest` (guard only)

**Frontend**

- new: `components/RequisitionAccountabilityDialog.vue`, `receiverAccountability.spec.ts`, `tests/fixtures/75rb-e2e.vite.config.ts`, `tests/fixtures/75rb-e2e-blank.html`
- changed: `components/RequisitionReceiverPayments.vue`, `components/RequisitionFinancePanel.vue`, `receiverPayments.ts`, `receiverPayments.spec.ts`, `views/requisitions/RequisitionShow.vue`, `views/requisitions/RequisitionIndex.vue`, `w3.ts`

**Not applied anywhere but the test database:** migrations `2026_10_06_000001`–`000005`. A push to `master` runs migrations in production.

---

## 25. Remaining software gaps

1. **Overspend resolution.** Held and blocked, but there is no approved way to pay a genuine overspend (§7).
2. **Receipt file and duplicate override in the new dialog.** The dialog captures the line, description, amount, expense type, receipt type and number, payee, and for an ETR receipt the VAT and supplier PIN. Attaching the receipt file and entering a duplicate-receipt override reason are accepted by the API but are not in this dialog; a duplicate receipt is therefore refused from this screen rather than overridden.
3. **Receivers without a login.** They cannot confirm for themselves; the requester does, on recorded evidence. The public sign-off link is not offered for receiver-paid requisitions.
4. **Release cannot be undone.** A wrong release needs a new requisition.
5. **Return date.** A return is dated the day Finance reconciles it; a separate date-received is not captured.
6. **Reversal is whole-stage.** One item of a reconciled surrender cannot be reversed on its own.
7. **Cross-requisition screens.** The payment register, receiver balances and compatibility report are API queries without list screens.
8. **Create and approve in the browser run** were done through the API (§23).
9. **List performance.** The requisition list computes the full projection per row. Not measured.
10. **One-payment requisitions are unchanged**, including the automatic "reimbursement" leg on overspend (§7) and release of the commitment at payment (§11).
11. **Compatibility report not run on real data** (§17).

---

## 26. Remaining WNG decisions

1. **Advance control account.** How should money advanced through a requisition be held before accountability — for employees, suppliers and other recipients? Three open items in Finance Setup. Until approved, Staff Advances is used as existing, unapproved behaviour.
2. **Overspend.** Is overspend ever reimbursed, who approves it, and how is it paid? And should the one-payment surrender's automatic reimbursement entry continue?
3. **Who may release an unused approved balance?** `finance.requisitions.release_unused` is held by nobody.
4. **Who may close?** Today, whoever may pay and reconcile. Should paying, reconciling and closing be separate permissions or separate people?
5. **Who may confirm receipt for a receiver without a login?** Today the requester, on stated evidence. Is that acceptable, and what evidence is required?
6. **One-payment requisitions.** Should their commitment also stand until accountability, as receiver-paid ones now do?
7. **Existing open requisitions with several typed names.** Run the compatibility report, then decide who identifies the receivers.
8. Carried from 75R-A and still open: whether the payer must differ from the approver; whether a per-requisition reference is enough for other recipients or a register is wanted.

---

## 27. Verdict

**FINANCIAL REQUISITION MULTI-RECEIVER LIFECYCLE COMPLETE — WNG POLICY DECISIONS REMAIN**

Confirmation, staged accountability, returns, release, reconciliation and closure are implemented, tested, and were exercised end to end in a browser against a migrated backend. The remaining software items in §25 are limits of capture and convenience, not breaks in the lifecycle. The requisition is never treated as closed because it was paid. The accounting treatment of advances, overspend, and the release and closing authorities are WNG's to decide and were not decided here.
