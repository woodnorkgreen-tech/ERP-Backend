# 31 — Phase 2B Wave 3 Implementation Report: Expenses, Payment Vouchers, Petty Cash

Prepared 2026-09-23. Scope: the confirmed portions of W3 (Expenses), W4 (Payment Vouchers) and W5
(Petty Cash) in `03_WNG_FINANCE_DECISION_REGISTER.md`. Not yet independently closure-verified —
the next task is the Phase 2B Wave 3 Closure Gate.

---

## 1. Continuation / handoff note

A previous session began Wave 3 and ended at its session limit. It left **uncommitted** work and no
report. This session did not restart the wave, reset the tree, stash, or discard anything: it
inventoried what existed, tested it, kept what was correct, corrected what was wrong, and finished
the remaining confirmed scope. Wave 1 and Wave 2 are also still uncommitted on
`finance/critical-stabilization-fixes`; nothing in them was altered except where §4 says so.

A first attempt at this continuation was interrupted by a machine restart (ddev had stopped); its
partial test run was discarded and re-run from scratch.

## 2. Repository state found on takeover

Files touched by the previous session (modified after the Wave 2 closure report, 17:26–17:33):
2 migrations (`2026_09_23_000006_add_wave_3_expense_controls`, `…_000007_create_petty_cash_control_records`),
4 new models (`SpendVoucherReview`, `SalaryAdvanceRecovery`, `PettyCashCashCount`,
`PettyCashCustodyHandover`), 1 new controller (`PettyCashControlController`), and edits to
`SpendVoucherController`, `SalaryAdvanceController`, `PettyCashRequisitionController`,
`PettyCashController`, `DuplicateDetectionService`, several models, `Permissions`, routes and
`FinanceSettingsSeeder`. **No tests, no frontend, no report.**

| Area | Not started | Partial | Implemented | Tested | UI | Notes on takeover |
|---|---|---|---|---|---|---|
| W3 Expenses | W3-4, W3-7 | W3-1, W3-2, W3-5, W3-6, W3-8 | — | No | No | W3-1 was a no-op; W3-2 invented GL treatment |
| W4 Payment Vouchers | — | W4-1, W4-2 | — | No | No | Returned vouchers could still be approved; posting gate broke 3 existing tests |
| W5 Petty Cash | W5-4 | W5-2, W5-3, W5-5, W5-7, W5-8, W5-9 | — | No | No | W5-9 hard block with no exception path |
| Shared controls | | Duplicate detection, return/correct | | No | No | |
| Permissions | | 4 new, in `all()` only | | | | Missing from `grouped()` (role screen) |
| UI | All | | | | | Surrender drawer could never submit (pre-existing) |
| Regression | | | | | | Migration 000006 failed on MariaDB — every Feature test failed |
| Decision Register | Not updated | | | | | |
| Wave 3 Report | Absent | | | | | |

**Last safe point:** the Wave 2 closure state plus the previous session's schema intent. The
migration itself was not safe (see D1).

## 3. Previous-session work preserved

Kept as written, after review and test: the voucher `review_state`/`spend_voucher_reviews` model and
the return/correct/resubmit/reject/senior-approve endpoints; `PettyCashAdvancePoster` retry wiring;
surrender duplicate columns and the override-at-entry rule (it matches the W2-5 bill pattern);
surrender return endpoint and snapshot; FinanceSetting-backed thresholds with legacy fallback;
surrender due-date derivation; direct-payment approval signals; custody/cash-count tables;
salary-advance ledger linkage, recovery table and derived balance/status.

## 4. Previous-session work corrected

| # | Finding | Severity | Correction |
|---|---|---|---|
| D1 | Migration 000006 generated FK name `petty_cash_surrender_items_duplicate_of_surrender_item_id_foreign` (65 chars) — MariaDB rejects it; the migration aborted, `RefreshDatabase` retried per class, and **every Feature test failed**. On master it would have failed the unattended `migrate --force` deploy | Critical (deploy) | Explicit name `pcsi_duplicate_of_fk`; up/down/up verified on `db_test` |
| D2 | Salary-advance payout posted `Dr 1300 Staff Advance / Cr bank` — an **unconfirmed accounting decision**. Payroll accrual credits advance deductions to 2140, so 1300 would never clear: every advance would sit as both a receivable and a liability | High (incorrect GL, governance) | Journal removed; payout is a `Payment` only. Reported, not legitimised — see §23 |
| D3 | Payout used `classification = 'staff_advance'` (not in the enum) and the petty-cash advance number series (`PCA`) | High (runtime failure) | `admin` (as payroll's own Payment), `PAY` series; payee name from `first_name`/`last_name` (the model has no `name`) |
| D4 | W3-1 added `cash_purchase` to `payments.classification` — the client-segment enum, conflating two concepts; `createDisbursement()` overwrites that field anyway, so it was a silent no-op; and `transaction_classification` was put on requisitions, which by definition have a prior requisition | Medium | Column moved to `payments.transaction_classification`; only valid without a requisition |
| D5 | `surrender_returned` status not in the requisition status ENUM — returning a surrender would 500 | High | ENUM widened in 000006 (down maps it back to `surrender_pending`) |
| D6 | Voucher `approve()` ignored `review_state` — a voucher returned for correction could still be approved | High (control bypass) | Refused while returned/corrected |
| D7 | Posting gate required `review_state = 'approved'`; any voucher created approved (tests, the simulation command) could never post — 3 `JournalPostingTest` regressions | Medium | Gate narrowed to the one extra state that matters: `awaiting_senior_approval` |
| D8 | W5-9 blocked outright with "exception authority remains unconfigured" and showed nothing for non-overdue advances | Medium | Confirmed shape built: show, block only overdue, authorised recorded exception |
| D9 | Duplicate check ran only on `submitSurrender`; `reconcileSurrender` accepts items directly and bypassed it; same receipt twice in one submission not caught; direct payments not checked | Medium | One helper on both paths; in-submission check; payments included |
| D10 | Cash-count variance mixed adjustments into "variance"; no explanation required; reviewer could be the custodian; statuses hard-coded | Medium | Register definition (Physical − Float), unexplained variance needs explanation, independent reviewer, component snapshot |
| D11 | Handover had no confirmation; custody overview's `custodian` was actually the top-up recorder | Low | Incoming-custodian confirmation; field renamed `recorded_by` |
| D12 | New W3 permissions missing from `Permissions::grouped()` — not grantable in the role screen | Low | Added |

## 5. Scope

In: every W3/W4/W5 row whose status is CONFIRMED, limited to the confirmed portion. Out (per the
directive): W1-10, STAB-2, historical STAB-7 remediation, W2-7…W2-10, Wave 2 UI, W6–W8, margin,
overhead allocation, depreciation, statement and dashboard redesign.

## 6. Entry-gate tests

Run against the previous session's code before any correction (migration fixed first, since
nothing could run otherwise): PettyCash, surrender, STAB-4, STAB-7, CostCollector, salary-advance
split, supplier ledger rail, JournalPosting — **371 passed, 3 failed** (all three D7).

## 7. Decision-status matrix

| Item | Register status | Built this wave | Blocker |
|---|---|---|---|
| W3-1 | CONFIRMED A | Yes | — |
| W3-2 | CONFIRMED A | Payment only | GL treatment (Finance/accountant) |
| W3-3 | Factually confirmed / STAB-7 | Regression only | Historical remediation (Finance) |
| W3-4 | Direction confirmed; matrix open | No | Evidence matrix (Finance/WNG) |
| W3-5 | CONFIRMED | Yes | Override role assignment |
| W3-6 | CONFIRMED | Yes | — |
| W3-7 | CONFIRMED | Yes | — |
| W3-8 | CONFIRMED operational; accounting open | Operational | Accounting (Finance/accountant) |
| W4-1 | CONFIRMED A | Yes | — |
| W4-2 | CONFIRMED B; threshold open | Mechanism, inactive | Threshold + senior approver |
| W4-3, W4-4 | AWAITING WNG | No | WNG |
| W5-1 | Resolved by STAB-4 | Regression only | — |
| W5-2 | CONFIRMED A | Yes | — |
| W5-3 | CONFIRMED C | Yes | — |
| W5-4 | CONFIRMED C | Verified, no build | SOP owner to name fields |
| W5-5 | CONFIRMED; amounts open | Mechanism | Amounts (Finance) |
| W5-6 | CONFIRMED B | No change needed | — |
| W5-7 | CONFIRMED; frequency/GL open | Operational | Frequency, variance GL |
| W5-8 | CONFIRMED; deadline open | Yes | Deadline policy |
| W5-9 | CONFIRMED; limits open | Mechanism | Limits, exception authority |

## 8. W3 implementation

- **W3-1** — `payments.transaction_classification` (`cash_purchase`), validated `prohibits:requisition_id`,
  carried through the direct-disbursement request payload to the `Payment`, filterable on
  `GET /disbursements?transaction_classification=`. Accounting is unchanged: it is a direct
  disbursement and posts through the existing direct-disbursement rail.
- **W3-2** — `POST /api/hr/advances/{id}/disburse` (`finance.spend_vouchers.post`): approved and
  unpaid only; the payer cannot be the employee; one `Payment` via `PaymentSettlementService`
  (idempotency key `salary-advance:{id}`); status `disbursed`, `payment_id`, `paid_at`. No journal.
- **W3-5** — see D9 and the register row. Match rule: normalised supplier/payee + receipt number +
  exact amount, against live surrender items and active payments. Refusals come back under a
  `duplicate_receipt` error key so the UI can offer the override.
- **W3-6** — return (reason, reconciler ≠ requester) → `surrender_returned` with snapshot →
  requester corrects and resubmits (recorded) → review → reconcile. Only unreconciled items are
  replaced on resubmission.
- **W3-7** — `PettyCashSurrenderReversalService` (§11–§14 for the accounting).
- **W3-8** — `payroll_ledgers.salary_advance_request_id` on every recovery row (one-off, split and
  remainder); `POST /api/hr/advances/{id}/recoveries` (≤ outstanding, one per payroll run);
  `outstanding_balance`/`recovery_status` derived; `SalaryAdvanceRequest::outstandingFor()` feeds the
  offboarding case and a shortfall log entry at settlement approval (flag, not block).

## 9. W4 implementation

- **W4-1** — return / correct / resubmit / reject as in D6 and the register row; history in
  `spend_voucher_reviews` with a snapshot per action. Cancel (requester), Return, Reject and Reverse
  stay four distinct actions.
- **W4-2** — `FinanceSetting::approvedValue('spend_voucher_senior_approval_threshold')`; above it,
  `review_state = awaiting_senior_approval`; senior approver must differ from requester and first
  approver. Inactive: no approved value is seeded.

## 10. W5 implementation

- **W5-2** approval signals; **W5-3** custody + handover + confirmation; **W5-5** thresholds;
  **W5-7** cash count; **W5-8** due date, states and `GET /petty-cash/advances/outstanding`;
  **W5-9** guard + exception (`finance.petty_cash.advance_exception`). Details in the register rows.
- **W5-4** — the requester form was reviewed: no GL, journal or CostLine field is requester-facing.
  Nothing further was removed; which remaining fields are "genuinely unnecessary" is the SOP
  owner's call.

## 11. Cost Collector integration

No new producer and no new posting path. Surrender CostLines are still created by
`CostCollectorService::postFromSource(postsIndependently: false)` (STAB-7). W3-7 reversal retires
them to `reversed` in the same transaction as the journal compensation, so project cost follows the
ledger; a legacy (pre-STAB-7) line that posted its own journal is reversed through
`reverseCostLine()` as well. The corrected surrender creates fresh CostLines for fresh items.

## 12. Payment settlement integration

Salary-advance payout uses `PaymentSettlementService` (it records money movement only; callers own
GL). **Defect found and fixed (D13, pre-existing, committed code):** `SpendVoucherSettlementService`
stamped `cost_lines.settled_by_payment_id` on the first payment and refused any other, so (a) the
second instalment of a partially settled liability was refused at posting, and (b) because
`PaymentReversalService` never cleared the marker, a reversed voucher's liability could never be
paid again despite the UI saying it was. It failed safe (no double payment). Fix: the guard is now
amount-based — cumulative active payments may not exceed the payable amount; the marker means
*fully* settled and is cleared by reversal when the line falls short. The amount reservation at
voucher creation is unchanged.

## 13. STAB-4 verification

`PettyCashAdvancePostingTest` and `PettyCashCostPostingFailureTest` pass unchanged. Wave 3 did not
touch `PettyCashAdvancePoster`/`PettyCashCostPoster`. Unresolved GL posting failures are now also
surfaced in each cash count's component snapshot.

## 14. STAB-7 verification

`Stab7PettyCashTriplePostingTest` passes unchanged. The mandatory reproduction holds: real spend
KES 2,000 → project cost KES 2,000, Staff Advance nets to zero (`Wave3PettyCashControlsTest`
return/resubmit case). The reversal case proves the same invariant through a correction:
advance 2,000 → posted 1,800 + 200 returned → reversed → corrected 1,700 + 300 returned →
**project cost 1,700, Staff Advance 0, float 498,300** (500,000 − 2,000 + 300), one reversal only,
new entry `JE-PCS-…-G2`.

## 15. Accounting verification

| Scenario | Expected | Result |
|---|---|---|
| Voucher settles a KES 25,000 CostLine | cost stays 25,000; no new CostLine | Pass |
| 100,000 liability: 40,000 then 60,000 | paid 100,000; cost 100,000; settled only after the second | Pass |
| A further KES 1 | refused | Pass |
| Reversed payment, paid again | marker cleared then re-set; cost unchanged | Pass |
| Surrender return / resubmit | cost 2,000 once | Pass |
| Surrender reversal and correction | net cost = corrected spend; float reconciles | Pass |
| Salary-advance payout | one Payment, zero journals | Pass |
| Cash count | no float-ledger row, no journal | Pass |

## 16. Posting-owner matrix

| Rail | CostLine owner | GL posting owner | Payment owner | Duplicate-cost guard |
|---|---|---|---|---|
| Ordinary expense (Cost Collector) | `CostCollectorService` | `JournalPostingService::postCostLine` | Payment Voucher (below) | One CostLine per source; payment never posts an expense leg |
| Cash purchase (W3-1) | `PettyCashCostProducer::postFor` (direct, no requisition) | same producer via `PettyCashCostPoster` | `PettyCashService::createDisbursement` + float ledger | Idempotency key; `postFor` excludes requisition-linked payments; W3-5 receipt check |
| Payment Voucher | none — settles existing lines | `postSpendVoucher` (Dr payable / Cr source; fee → 7800) | `SpendVoucherSettlementService` → `PaymentSettlementService` | Eligible verified liabilities only; allocation ≤ remaining; amount-based settlement guard (D13) |
| Petty-cash advance | commitment only (`commitFor`) | `PettyCashAdvancePoster` → Dr 1300 / Cr float | requisition disbursement | STAB-7: `postFor` skips requisition-linked disbursements |
| Petty-cash surrender | `postFromSource(postsIndependently: false)` | `postPettyCashSurrender` (JE-PCS, generation) | none (cash return credits float) | STAB-7; W3-7 one reversal per entry, new generation on correction |
| Direct disbursement | as cash purchase | as cash purchase | as cash purchase | as cash purchase |
| Supplier Bill | `ProcurementCostProducer` / direct bill | `postSupplierInvoice` | none | `settled_by_bill_id`; W2-5 duplicate bill check |
| Supplier Payment | none | `postSupplierPayment` (Dr AP / Cr bank) | `SupplierPaymentService` → `PaymentSettlementService` | Three-way match gate; no expense leg |
| Salary advance | none | **none (open)** | `PaymentSettlementService` | No expense leg; idempotency key |
| Payroll | none (department split in GL) | `PayrollFinancePostingService` accrual + payment | `PaymentSettlementService` (C7) | One accrual per run; payment idempotency key |

No rail has two owners for the same economic cost.

## 17. Permissions

New this wave (all in `all()` and `grouped()`, **granted to no role**, synced by
`php artisan permissions:sync`): `finance.expenses.override_duplicate`,
`finance.spend_vouchers.approve_senior`, `finance.petty_cash.manage_custody`,
`finance.petty_cash.review_cash_count`, `finance.petty_cash.advance_exception`. Reused:
`finance.spend_vouchers.approve` (return/reject), `…create` (correct/resubmit), `…post` (advance
payout and recovery), `finance.journals.reverse` (surrender reversal), `finance.petty_cash.create`
(surrender return, same authority as reconcile), `…view_reports` (ageing). Separation of duties is
enforced server-side on every new action (requester ≠ reviewer/reverser/rejecter; senior ≠ first
approver; counter/custodian ≠ reviewer; incoming custodian confirms; payer ≠ employee). No legacy
role fallback was added. **Until an administrator grants the new permissions, custody, cash counts,
duplicate overrides, senior approval and the W5-9 exception are unusable by anyone except Super
Admin** — deliberate, since WNG has not named the holders.

## 18. UI

| Screen | Added |
|---|---|
| Payment vouchers | Return, Reject, Correct & resubmit modal, Senior approve; status labels; Post hidden while awaiting senior; Cancel only for the requester |
| Surrender drawer | **Contract fix** (it sent `gross_amount`, `supplier_pin` and receipt types the API rejects — no surrender could ever be submitted from the UI); supplier field; return banner; duplicate override; Return for correction |
| Requisition preview/list | `surrender_returned` status/tab, Correct & resubmit, Reverse surrender, ageing banner, W5-9 exception prompt |
| Direct approval queue | W5-2 signals, duplicate warning, cash-purchase badge |
| Direct payment form | Walk-in cash purchase flag |
| Petty cash › Custody tab | New `PettyCashControlsPanel`: custodian, handover, confirm, cash count + review, surrender ageing |
| HR payroll › Advances | Status filter, Record payout, Record recovery, balances |
| My advances / Offboarding | Recovery status; unrecovered-advance warning on final settlement |

Not built: a Finance-settings screen to set/approve threshold values (none existed before; shared
dependency with W2-1).

## 19. Migrations

`2026_09_23_000006` and `…000007` (previous session's, corrected in place — neither had run on the
dev database, and `db_test` is rebuilt per run). Additive and reversible; up → down → up verified.
Existing rows: vouchers already approved/paid/posted/reversed backfilled to `review_state=approved`,
rejected to `rejected`; nothing else is fabricated (no historical custodian, due date, approver or
evidence). **Both are pending on the dev database and will run on the next master deploy.**

## 20. Tests

New: `tests/Feature/Finance/Wave3PaymentVoucherControlsTest.php` (8),
`tests/Feature/Finance/Wave3PettyCashControlsTest.php` (11),
`tests/Feature/Hr/SalaryAdvancePayoutRecoveryTest.php` (6), frontend
`tests/unit/finance/wave3Controls.spec.ts` (7). All pass.

## 21. Full regression

Run sequentially, one Laravel test process at a time, no concurrent run against `db_test`.

```
Focused (new):   Wave3PaymentVoucherControlsTest 8 · Wave3PettyCashControlsTest 11 ·
                 SalaryAdvancePayoutRecoveryTest 6                          → 25 passed

tests/Feature/Finance + Projects + Procurement + ProcurementStores + CostCollector
  + PettyCash + Hr + tests/Unit                                               (W1, W2, STAB-4, STAB-7,
                                                                               Payroll integrity included)
  1048 passed (7793 assertions), 0 failed, 0 skipped   — 446 s

tests/Feature/Stores + Notifications + UniversalTask + ClientService + MaterialsLibrary
  + Support + Seeding + Overtime + Printing + AuthLifecycleTest
  268 passed (1067 assertions), 0 failed, 0 skipped

Backend total: 1316 passed, 0 failed, 0 skipped (every Feature directory and all Unit tests).

Frontend (npm vitest run, full suite): 23 files, 118 passed (111 before + 7 new), 0 failed.
vue-tsc: 256 errors project-wide, all pre-existing — 0 in any file changed or added this wave.
```

One failure surfaced on the first full run and was fixed, not relabelled:
`Tests\Unit\PettyCash\LedgerServiceTest` (3 tests) drops and recreates the real
`petty_cash_balances` table inside `db_test`; the new custody/cash-count tables reference it, so the
drop was refused. The test now swaps its tables with foreign-key checks suspended and mirrors the new
`held_by` column. The foreign key itself is correct and was kept.

`php artisan permissions:sync --dry-run` on the dev database: creates the five Wave 3 permissions,
grants none of them.

## 22. Defects discovered / fixed

D1–D12 (§4, previous-session work) and D13 (§12, pre-existing settlement defect). D14: the surrender
drawer contract mismatch (§18, pre-existing). None was a duplicated cost, duplicated payment or
destructive loss; D2 would have produced incorrect GL on first use and D1 would have failed the
deploy.

## 23. Open decisions deliberately not implemented

- **W3-2 / W3-8 accounting:** how a salary advance sits in the GL and how payroll recovery clears
  it (and whether 2140 is the right credit for advance deductions today). Until decided, payouts
  are Payments with no journal — so the bank GL does not yet reflect them. This is the one Wave 3
  gap a finance reader must know about.
- W3-4 evidence matrix; W3-5/W5-9/W4-2 role holders; W4-2 threshold; W4-3; W4-4; W5-5 amounts;
  W5-7 frequency and shortage/overage treatment; W5-8 deadline; W5-9 limits; W5-4 field list.
- Follow-up (non-blocking): capture salary-advance recoveries automatically when a payroll run is
  processed instead of by manual entry; a Finance-settings screen.

## 24. Wave 2 follow-up carried forward (unchanged)

W2-1 senior approval UI, W2-2 Procurement evidence UI, W2-3 staged billing visibility, W2-4 amendment
UI, W2-5 duplicate override UI, W2-6 return/correct/resubmit UI; Procurement evidence read
visibility; legacy correction/approval role overlap; W2-1 threshold/senior approver; W2-5 override
role; W2-7…W2-10.

## 25. Implementation Status Matrix

| Requirement | Decision status | Backend | UI | Tests | Remaining dependency |
|---|---|---|---|---|---|
| W3-1 | Confirmed | Done | Done | Done | — |
| W3-2 | Confirmed | Payment done; GL deliberately none | Done | Done | GL treatment (accountant) |
| W3-3 | Resolved (STAB-7) | Regression | n/a | Pass | Historical remediation |
| W3-4 | Matrix open | Not built | — | — | Evidence matrix |
| W3-5 | Confirmed | Done | Done | Done | Override role holder |
| W3-6 | Confirmed | Done | Done | Done | — |
| W3-7 | Confirmed | Done | Done | Done | — |
| W3-8 | Operational confirmed | Done (manual recovery entry) | Done | Done | Accounting; auto-capture from payroll |
| W4-1 | Confirmed | Done | Done | Done | — |
| W4-2 | Confirmed; threshold open | Mechanism (inactive) | Done | Done | Threshold, senior approver, settings screen |
| W4-3 | Awaiting WNG | — | — | — | WNG |
| W4-4 | Awaiting WNG | — | — | — | WNG |
| W5-1 | Resolved (STAB-4) | Regression | n/a | Pass | — |
| W5-2 | Confirmed | Done | Done | Done | — |
| W5-3 | Confirmed | Done | Done | Done | Custody-manager role holder |
| W5-4 | Confirmed | Verified, no change | Verified | — | SOP owner's field list |
| W5-5 | Confirmed; amounts open | Mechanism | Settings screen absent | Done | Amounts; settings screen |
| W5-6 | Confirmed | No change | n/a | n/a | — |
| W5-7 | Confirmed; GL open | Done | Done | Done | Frequency; variance GL; reviewer role |
| W5-8 | Confirmed; deadline open | Done | Done | Done | Deadline policy |
| W5-9 | Confirmed; limits open | Mechanism | Done | Done | Limits; exception holder |

## 26. Wave 3 Closure Gate readiness

## READY WITH NON-BLOCKING FOLLOW-UP

Every confirmed, unblocked W3/W4/W5 control is enforced server-side, usable in the UI, and tested;
no duplicated cost, payment or GL entry remains on any Wave 3 rail; STAB-4 and STAB-7 are intact;
the full suite is green.

Follow-ups for the Closure Gate to weigh (none is an implementation defect):

1. **W3-2/W3-8 accounting (Finance/accountant):** salary-advance payouts are Payments with no
   journal, so the bank GL does not yet show them. The gate should confirm this is acceptable as an
   interim, or treat the accountant's decision as the next dependency.
2. **Role grants (administrator, on WNG's instruction):** the five new permissions are held by no
   role; custody, cash counts, duplicate overrides, senior approval and the W5-9 exception are
   unusable until they are granted.
3. **Settings screen:** W4-2, W5-5 and W5-8 values (and W2-1's) can only be set in the database;
   rows arrive unapproved and every gate stays inactive until then.
4. Automatic capture of salary-advance recoveries from processed payroll runs.
5. Deployment: migrations 000006/000007 are pending on the dev database and will run unattended on
   the next master push; run `php artisan permissions:sync` and the `FinanceSettingsSeeder` there.

Deliberately open (§23): W3-4, W4-3, W4-4, and every threshold, limit, deadline, frequency and
accounting-treatment detail listed in the register.
