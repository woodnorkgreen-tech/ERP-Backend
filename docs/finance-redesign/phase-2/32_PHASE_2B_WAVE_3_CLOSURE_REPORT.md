# 32 -- Phase 2B Wave 3 Closure Report: Expenses, Payment Vouchers, Petty Cash

**Date:** 2026-09-24  
**Type:** Independent verification and closure gate. The implementation report was not accepted as
proof by itself; the Wave 3 control paths and focused accounting regressions were checked against
the code and re-run.

---

## 1. Gate result

## PASS -- WAVE 3 CLOSED, WITH POLICY/DEPLOYMENT FOLLOW-UPS

The W5-9 disbursement-time bypass found during closure review has been fixed and covered by a
regression test. Every focused Wave 3, STAB-4 and STAB-7 test run for this closure is green:
**39 tests, 301 assertions**.

The remaining items are confirmed policy, role-assignment, settings-UI and deployment work. They
do not provide an undocumented runtime bypass and do not reopen the implementation gate.

## 2. Closure finding -- W5-9 disbursement-time bypass

**Severity:** High  
**Status:** Fixed and regression-tested

W5-9 was checked when a requisition was approved, but not when the approved requisition was
actually disbursed. The control could therefore be bypassed when:

1. the requester had no overdue advance at approval;
2. an existing advance became overdue after approval (or another overdue advance appeared); and
3. Finance disbursed the already-approved requisition.

That was a time-of-check/time-of-use gap in a control whose confirmed direction explicitly applies
before approving **and disbursing** another advance.

### Fix

`PettyCashRequisitionController::disburse()` now queries the requester's current unresolved,
overdue advances before creating any Payment.

- No overdue advance: payout proceeds normally.
- Every currently overdue advance is already named in the approval-time exception: payout may
  proceed under that recorded authority.
- A new or previously uncovered overdue advance: payout returns
  `422 OVERDUE_ADVANCE_EXISTS`; no Payment is created.
- A holder of `finance.petty_cash.advance_exception`, other than the requester, may record a
  reason of at least 15 characters at disbursement.
- The disbursement-time exception records requester, authoriser, timestamp, stage, all current
  overdue advances, and the new amount, and writes the existing petty-cash activity audit event.

This preserves a valid approval-time exception without turning it into a blanket authorization for
advances that were not presented to its authoriser.

## 3. Regression added

`Wave3PettyCashControlsTest::test_an_advance_that_becomes_overdue_after_approval_is_blocked_again_at_disbursement`
reproduces the former bypass:

1. an older advance is unresolved but not overdue;
2. the next requisition is approved without an exception;
3. the older advance is moved past its surrender deadline;
4. disbursement is refused with `OVERDUE_ADVANCE_EXISTS`;
5. the database contains no Payment for the refused attempt;
6. an authorised, reasoned exception permits payout; and
7. the saved exception identifies its `disbursement` stage and the overdue advance.

The existing approval-time tests still prove that overdue advances block approval, authorised
exceptions are attributed, and non-overdue advances are shown without auto-blocking.

## 4. Focused verification evidence

### Wave 3 petty-cash controls

Command:

```text
ddev exec php artisan test tests/Feature/Finance/Wave3PettyCashControlsTest.php
```

Result: **PASS -- 12 tests, 130 assertions**, including the new W5-9 regression.

### Payment vouchers, STAB-4 and STAB-7

Command:

```text
ddev exec php artisan test \
  tests/Feature/Finance/Wave3PaymentVoucherControlsTest.php \
  tests/Feature/Finance/PettyCashAdvancePostingTest.php \
  tests/Feature/CostCollector/PettyCashCostPostingFailureTest.php \
  tests/Feature/Finance/Stab7PettyCashTriplePostingTest.php
```

Result: **PASS -- 27 tests, 171 assertions**.

The runs reconfirmed voucher correction/rejection and settlement limits; visible and idempotent
petty-cash posting failure/retry; and the STAB-7 invariant that advances, surrender, returned cash,
overhead spend and direct disbursements recognize cost exactly once.

The environment emitted existing PHP 8.4 Guzzle deprecation notices and permission-sync warnings
for roles absent in the test database. Neither affected test results.

## 5. Accounting and control conclusion

The closure fix changes only the authority gate immediately before payout. It does not create or
alter a journal, CostLine, commitment, surrender, float movement or payment amount. A refused
attempt stops before `PettyCashService::createDisbursement()`, and the regression explicitly
proves that no Payment is persisted.

STAB-4 remains green: advance/cost posting failures remain visible, retryable and idempotent.
STAB-7 remains green: petty-cash costs are recognized once, with no disbursement-time or
surrender-item duplicate posting.

## 6. Non-blocking follow-ups carried forward

1. WNG/Finance must name the holders of the five new Wave 3 permissions, including
   `finance.petty_cash.advance_exception`. No role is granted exception authority by default.
2. WNG/Finance still owns the W4-2 threshold, W5-5 float thresholds, W5-8 surrender deadline and
   due-soon window, W5-9 count/amount limits (if any), cash-count frequency, and variance accounting
   treatment.
3. The settings UI remains absent; approved values currently require controlled database
   configuration.
4. Salary-advance payout GL treatment and automatic payroll recovery capture remain accountant/WNG
   decisions, not silently invented Wave 3 behavior.
5. Deployment must run the Wave 3 migrations, permission sync and Finance settings seeder.

## 7. Final closure statement

The only implementation defect identified in the continued closure review--the W5-9
approval-to-disbursement timing bypass--is closed. The payout endpoint now evaluates current control
state and requires specific recorded authority for any newly overdue advance. Focused regressions
are green and the accounting rails remain unchanged.

**Phase 2B Wave 3 is closed.**
