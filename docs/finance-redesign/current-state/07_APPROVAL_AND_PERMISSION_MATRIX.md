# 07 — Approval & Permission Matrix, Financial Periods, Reversal Controls, Audit Trail

Part of the WNG ERP Finance & Accounts Phase 1 audit, dated 2026-09-22. Covers audit sections 13
(Approvals), 14 (Roles & Permissions), 15 (Financial Period Controls), 16
(Correction/Reversal Controls), and 22 (Audit Trail). Uses `spatie/laravel-permission`; permission
constants in `app/Constants/Permissions.php`, the role→permission grant matrix in
`app/Constants/RolePermissions.php::matrix()`.

---

## SECTION 13 — APPROVALS AUDIT

### 13.1 Spend Voucher / Payment Voucher (`SpendVoucher`)

**FACT.** Three distinct permissions gate the lifecycle: create, approve, post.
- **Create** blocks creation into a closed/locked period.
- **Approve** blocks self-approval unless `SelfApproval::allowedFor()`.
- **Post** blocks **both** the requester and the approver unless overridden.
- **Reject** — **no dedicated approver-reject action exists.** The only path to `status='rejected'`
  is `cancel()`, restricted to the original requester. An approver who disagrees has no code path to
  refuse a voucher; they can only withhold approval indefinitely.
- **Edit after creation** — no `update()`/`edit()` method exists; a voucher cannot be altered once
  created, only transitioned. Genuine append-only design.
- **Reversal** — handled by a different controller/permission entirely (`PaymentReversalService`),
  never by editing the voucher.
- **Amount thresholds** — none. The same permission applies identically to a KES 500 voucher and a
  KES 5,000,000 voucher.

**ISSUE (MEDIUM).** No approver-initiated rejection path. **ISSUE (LOW).** No amount-based approval
escalation tier (contrast Procurement's `PurchaseApprovalPolicy`, §13.7).

### 13.2 Petty Cash Requisition

**FACT.** `create`/`reviewRequisition` (approve/reject/edit someone else's)/`void` are three
distinct permission-gated abilities. No `update`/`delete` ability for a disbursement exists by
design — the cash ledger is append-only; corrections go through void + re-record. `destroy()` only
permits deleting a `pending`/`rejected` requisition, and it is a **soft delete**.

### 13.3 Supplier Bill (three-way match)

**ISSUE (MEDIUM).** `BillController::verify()` — the step moving a bill from accrued to
payable — is gated by a **hard-coded 3-role array** (`['Super Admin','Admin','Accounts']`), reused
from a *delete* permission's logic, not a dedicated `Permissions::` constant. Inconsistent with the
rest of the module's move to permission constants. Widening or narrowing who may verify a supplier
invoice for payment requires a code change and deploy, not an admin-screen role edit. **Self-
approval block IS present and correctly built.**

### 13.4 Client Invoice / Credit Note (Receivables)

**FACT.** Posting refuses a non-open accounting period (see §15). Day-to-day receivables work is
not exposed through a dedicated controller's own approve/reject actions — it runs through the
generic Finance Work Queue claim/assign/reassign mechanism, gated per-item on
`FINANCE_RECEIVABLES_VERIFY`.

**FACT — CONFIRMS a prior-audit item.** `release()` only allows the assignee or a Super Admin/Admin
to release a claim back to the pool — a peer cannot be handed a claim directly. A separate
`reassign()` method does support this, restricted to Admin/Super Admin (hard-coded role check), but
its route has **zero frontend callers**. RISK: LOW-MEDIUM (operational bottleneck, not a control
bypass — even an Admin has no UI button to do it from).

### 13.5 Payroll (`PayrollRun`)

**FACT — REFUTES part of a specific prior-audit hypothesis.** The permission gate is still a
**single permission**, `HR_MANAGE_PAYROLL`, wrapping the entire route group including `lock` and
`mark-paid`. **However**, the code now enforces **identity-level** separation of duties: `lock()`
blocks if the locker created the run; `markPaid()` blocks if the payer locked it — both mirror
SpendVoucher's pattern and log the override when used.

**Net assessment: a partial fix, not the SpendVoucher pattern.** SpendVoucher separates duties by
**permission** (3 distinct grants) *and* identity. Payroll separates duties **only by identity**,
under **one** permission — any two distinct `HR_MANAGE_PAYROLL` holders can complete a full
prepare→lock→pay cycle between them; one person alone cannot, and if WNG's HR function is a single
person, payroll structurally jams at `lock()` unless that person is separately granted
`APPROVALS_SELF_APPROVE` (currently held by nobody but Super Admin — see §14), which would remove
the control entirely for that person. **RISK: MEDIUM.**

**FACT.** `rollback()` refuses a `paid` run or a `locked` run with a posted accrual entry — good
containment. `destroy()` refuses a `locked`/`paid` run.

### 13.6 Journal Entries (manual)

**FACT — strong, well-designed single-writer control.** No manual journal-entry creation path
exists anywhere. `JournalEntryController` is explicitly documented as read-only plus one reversal
action, itself routed through the single posting-service writer. Grepping the whole `Finance` tree
for direct `JournalEntry::create(` calls found exactly one call site outside the posting service,
in a console demo/simulation command (not HTTP-reachable).

### 13.7 Procurement Purchase Order (context for Finance's cost side)

**FACT.** `PurchaseApprovalPolicy::evaluate()` is a genuine amount-aware control: an order
auto-approves only if covered by an already-approved requisition **and** does not exceed an
optional, Finance-**signed-off** ceiling (an unsigned config value changes nothing). This is the one
place in the audited code with real value-based approval tiering, well-guarded against a
config-only bypass.

### APPROVAL MATRIX — CURRENT STATE

| Transaction Type | Creator | Reviewer | Approver | Poster | Payer/Reconciler | Self-Approval Possible? |
|---|---|---|---|---|---|---|
| Spend/Payment Voucher | `FINANCE_SPEND_VOUCHERS_CREATE` | — | `..._APPROVE`, blocked if approver=requester | `..._POST`, blocked if poster=requester OR approver | Poster IS payer | Only via `APPROVALS_SELF_APPROVE` (Super Admin only) |
| Petty Cash Requisition | `FINANCE_PETTY_CASH_CREATE` | — | `FINANCE_PETTY_CASH_UPDATE` | Disbursement recorded separately | `FINANCE_PETTY_CASH_CREATE` | Not directly re-verified (permission-only gate) |
| Supplier Bill (3-way match) | Bill creator | — | Hard-coded `['Super Admin','Admin','Accounts']`, blocked if verifier=creator | `recordPayment()`/batch | Same as poster | Only via `SelfApproval::allowed()` |
| Client Invoice / Credit Note | Invoice creator | — | — (posting gate is period-open, not human sign-off) | System, on period-open | Work Queue, `FINANCE_RECEIVABLES_VERIFY` | Not directly re-verified |
| Payroll Run | `HR_MANAGE_PAYROLL` | — | `HR_MANAGE_PAYROLL`, blocked if locker=creator | `HR_MANAGE_PAYROLL`, blocked if payer=locker | Same as poster | Only via `SelfApproval` — one permission for all three roles |
| Journal Entry | System only | — | — | System only | — | N/A — no manual creation path |
| Journal/Payment Reversal | N/A | — | `FINANCE_JOURNALS_REVERSE`/`FINANCE_PAYMENTS_REVERSE` | Reversal is itself the posting | — | Not ownership-blocked; gated purely on the reverse permission |

---

## SECTION 14 — ROLES AND PERMISSIONS AUDIT

**FACT.** `RolePermissions::matrix()` is a single source of truth, replacing a prior state where
nine historical migrations granted permissions to role names that don't exist (e.g. "Finance
Manager") and failed silently. `Super Admin` holds every permission and also a global `Gate::before()`
bypass — omnipotent by construction.

### PERMISSIONS MATRIX (Finance-relevant subset)

| Role | Spend Voucher C/A/P | Petty Cash | Receivables | Costs | Journals/Payments Reverse | Periods | Payroll | Self-Approve | Expenditure Exception |
|---|---|---|---|---|---|---|---|---|---|
| Super Admin | ✅ all | ✅ all | ✅ all | ✅ all | ✅ all | ✅ | ✅ | ✅ | ✅ |
| Admin | ✅ Create/Approve/Post | ✅ most | ✅ Record/Correct/Override/Reverse/Verify | ✅ Create/Verify/Reverse | ❌ | ❌ | ❌ | ❌ | ❌ |
| Accounts | ✅ Create/Approve/Post | ✅ incl. Admin | ✅ Record/Correct/Verify/Release/Reverse | ✅ Create/Verify/Reverse | ✅ **both** | ✅ | ❌ (no HR access) | ❌ | ❌ |
| Costing | ❌ | ❌ | Read only | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| HR | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ single permission covers prepare/lock/pay | ❌ | ❌ |
| Manager | ❌ | ✅ Create/Update/Void | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Project Manager | ❌ | ❌ | Read only | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Procurement/Stores/Logistics/Production/Client Service/Project Officer | ❌ | ❌ | ❌ | ✅ Create/Read only | ❌ | ❌ | ❌ | ❌ | ❌ |
| Designer/Employee | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

**ISSUE (HIGH) — segregation-of-duties concentration.** `Accounts`, as a single named role, holds
create+approve+post+reverse+period-close+receivables-correct simultaneously. `Admin` holds an
almost identical set minus reversal/period permissions. **RISK: HIGH in principle, mitigated in
practice** by the identity-level self-approval blocks throughout §13 — no single Accounts user can
create+approve+post the *same* document. But the role design means the **entire chain for every
Finance transaction type sits inside one role** — segregation of duties depends entirely on
**headcount**, not on role boundaries.

**FACT (positive control).** `APPROVALS_SELF_APPROVE` is granted to **no role** other than Super
Admin — arguably correct as a default (the override should be rare), but combined with the payroll
finding above, it means a single-person HR or Accounts function has **no legitimate way** to
complete their workflow without a Super Admin's help, every time.

**FACT (open governance gap).** `FINANCE_EXPENDITURE_EXCEPTION_APPROVE` (authorizing a project to
spend beyond budget) is likewise granted to **no role** except Super Admin — every budget-overrun
exception at WNG currently requires a Super Admin account, regardless of size.

**FACT.** HR and Finance permission sets do not overlap. Payroll's *posting to the GL* is performed
under the HR-side `HR_MANAGE_PAYROLL` permission — **HR, not Finance/Accounts, is the poster of
payroll's journal entries.**

**Classification:** Accounts/Admin role group → **KEEP & IMPROVE** (split by tier). Payroll
permission model → **KEEP & IMPROVE** (split into stage-specific permissions). Self-approval /
expenditure-exception grants → **REQUIRES WNG CONFIRMATION** (governance decision).

---

## SECTION 15 — FINANCIAL PERIOD CONTROLS AUDIT

**FACT — this section substantially REFUTES the "advisory-only" hypothesis.** Periods are real,
DB-backed, and **enforced at the service layer immediately before every ledger write**:
- `AccountingPeriod` — open/locked/closed, `forDate()`/`isOpen()`.
- Full month-end-close screen: list, checklist, close (with force+reason), lock, reopen (mandatory
  ≥5-char reason, stored and displayed).
- `PeriodCloseService::close()` runs blocking vs warning vs info checks; a close is refused unless
  blockers are clear, or explicitly forced (and the force is recorded).
- Reopen requires a reason and always logs it — deliberately possible but "deliberately
  uncomfortable" (docblock), rather than making close irreversible, because irreversibility was
  diagnosed as *why* every one of the 36 seeded periods had stayed permanently open before this
  feature existed.
- **Posting-time enforcement**, the actual question: `JournalPostingService::assertOpenPeriod()` is
  called from every posting method — manual reversal, supplier invoice, supplier payment, the
  generic balanced-entry poster, the disbursement/payment poster. `SpendVoucherController` re-checks
  the period **again with a `sharedLock()`** immediately before posting, closing the exact
  create-then-post TOCTOU gap a naive design would have. `ReceivablesPostingService` and
  `PayrollFinancePostingService` both throw on a non-open period. `CostCollectorService` refuses to
  let a cost line be recorded into a non-open period at all.
- **Single-writer confirmation:** the only `JournalEntry::create(` call site outside the posting
  service is a console demo command, not HTTP-reachable.

**Conclusion: period closing is not advisory-only.** It is enforced at every ledger-write point
this audit could find, with a re-check under a row lock at the final posting step for spend
vouchers specifically. **No database CHECK constraint or trigger exists** — enforcement is 100%
application/service-layer, so it is only as strong as "every write path goes through these
services" (verified true for the Eloquent path; a raw-SQL bypass was not exhaustively ruled out —
flagged as a follow-up).

**Classification: KEEP** — one of the stronger controls found in this audit.

---

## SECTION 16 — CORRECTION / REVERSAL CONTROLS AUDIT

**FACT — reversal is additive, not destructive, everywhere this audit traced it.**
`JournalPostingService::reverseEntry()` refuses to reverse an already-reversed entry, refuses to
reverse into a non-open period, is idempotent (returns the existing reversal rather than
duplicating), and creates a **new** entry with flipped legs — the original is never deleted or
mutated. `PaymentReversalService::reverse()` is the atomic, cross-cutting cash-event reversal:
refuses to reverse an already-voided or a **reconciled** payment (must be un-matched first),
reverses every journal entry the payment produced in one transaction, voids (not deletes) the
payment, sets the linked voucher to a new terminal `reversed` status, posts a compensating credit
ledger entry for petty cash, and logs the action.

**FACT — CLOSED, a prior-audit gap.** The Voucher Reversal feature now has a wired frontend entry
point, and `Accounts` holds `FINANCE_PAYMENTS_REVERSE` in the live role matrix — no longer "zero
role holders."

**FACT — nuance on a prior hypothesis.** `PaymentVouchersView.vue`'s 4 status tabs (all / pending
approval / approved / posted) have **no dedicated tab** for `reversed`/`rejected` — but both
statuses are visible and color-coded under "All." RISK: LOW (reporting convenience gap, not a
data-loss or audit gap).

**ISSUE (CRITICAL) — a genuine hard-delete-everything endpoint exists for petty cash financial
records.** `PettyCashService::clearAllData()` — routed as `DELETE clear-all` — hard-deletes **every**
`PettyCashDisbursementAllocation`, `Payment`, `PettyCashTopUp`, and `petty_cash_ledger_entries` row
in one transaction, then rebuilds the balance from the now-empty ledger. Docblock: *"CAUTION: this
is a destructive action and cannot be undone."* Gated to Super Admin only (the policy method always
returns `false`; only the Super Admin `before()` bypass reaches it). **The endpoint is real, live,
routed, and reachable — restricted to Super Admin accounts only. RISK: CRITICAL in impact, LOW-
MEDIUM in likelihood given the access restriction** — but a live HTTP endpoint that irreversibly
wipes every historical Payment/disbursement/ledger-entry row for **all** of petty cash, with no
date range, no soft-delete, no export-first step, is the kind of destructive capability that should
not exist in a production financial system regardless of who can invoke it. One compromised or
misused Super Admin session, or one engineer confusing it with an environment-reset tool,
permanently destroys the petty cash audit trail. **This reads like a development/demo-data reset
utility that migrated into the production codebase unchanged.**

**FACT (contrast, well-guarded).** `PettyCashTopUpController::destroy()` refuses to delete a top-up
with any linked disbursement, and posts a compensating reversal ledger entry **before** hard-
deleting the row — so the ledger never loses the credit even though the row is removed.
`PettyCashRequisitionController::destroy()` only permits `pending`/`rejected`, and soft-deletes.
`PayrollRunController::destroy()`/`rollback()` refuse to touch anything already posted to the ledger.

**Classification:** Journal/Payment reversal mechanism → **KEEP**. Payment-voucher status-tab
reporting → **KEEP & IMPROVE**. Petty cash `clearAllData`/`clear-all` endpoint → **REMOVE** (from
production reachability) or **REDESIGN** (confirmation + export-first + out-of-band audit log) —
**CRITICAL**. Petty cash top-up/requisition delete guards → **KEEP**.

## Summary risk register (Sections 13–16)

| # | Issue | Risk | Status |
|---|---|---|---|
| 1 | `clearAllData`/`clear-all` petty-cash wipe endpoint | **CRITICAL** | Open |
| 2 | Accounts/Admin roles concentrate create+approve+post+reverse+period-close in one role each | HIGH (headcount-dependent) | Open |
| 3 | `HR_MANAGE_PAYROLL` single permission for prepare/lock/pay | MEDIUM | Partially fixed (identity checks added) |
| 4 | Bill verification hard-coded 3-role array, not a permission constant | MEDIUM | Open |
| 5 | No approver-initiated reject action for spend/payment vouchers | MEDIUM | Open |
| 6 | `FINANCE_EXPENDITURE_EXCEPTION_APPROVE`/`APPROVALS_SELF_APPROVE` held by nobody but Super Admin | MEDIUM | Governance decision needed |
| 7 | Work-queue `reassign` exists but has zero frontend caller; `release` cannot hand off to a peer | LOW-MEDIUM | Confirmed still true |
| 8 | No dedicated UI tab for reversed/rejected payment vouchers | LOW | Confirmed, nuanced |
| 9 | No amount-based approval escalation tier for spend vouchers | LOW-MEDIUM | Open |
| 10 | Payroll's GL-posting authority sits with HR, not Accounts/Finance | MEDIUM (governance) | Requires WNG decision |

---

## SECTION 22 — AUDIT TRAIL AUDIT

### 22.1 No activity-log package installed

**FACT.** `composer.json` does not include `spatie/laravel-activitylog`; a repo-wide grep for
`LogsActivity` returns zero matches. Any "audit trail" in this codebase is **100% custom-built**,
existing as three separate, non-unified mechanisms plus per-column attribution fields.

### 22.2 The three custom audit mechanisms

1. **`PettyCashActivityLog`** — user_id/action/transaction_type/transaction_id/description/
   changes(json), written via `PettyCashService::logActivity()` (fails soft, logging never blocks
   the transaction). The `updated` case on top-ups **does** capture a genuine before/after diff —
   scoped only to petty-cash top-ups/disbursements/requisitions.
2. **`PeriodAuditLog`** — accounting-period lifecycle events only (open/close/lock/reopen), a real
   well-shaped log.
3. **`HRAuditLog`** — despite living in and being named after the HR module, used cross-module by
   Finance (`SpendVoucherController` approve/cancel; `CostVerificationService`'s self-verification
   override).

**ISSUE (LOW).** The de facto general-purpose audit log for the whole application is `HRAuditLog`,
table `hr_audit_logs`, namespaced under HR. A Finance developer must import an HR-named class to log
a Finance event — confusing, undocumented cross-module coupling.

**ISSUE (MEDIUM for SpendVoucher specifically).** `HRAuditLog` is used only for two specific Finance
actions (spend-voucher approve/cancel, and one self-verification override) — not for cost-line
verification/rejection, bill verification, journal posting, payment voiding, requisition approval,
or reconciliation. For most Finance transaction types, "the audit trail" is whatever column-level
attribution exists on the row and nothing else — there is no independent, append-only log entry
recording "user X did Y to record Z at time T."

### 22.3 Per-transaction-type audit-column inventory

| Event | Spend Voucher | Petty Cash | Supplier Bill | Client Receipt/Invoice | Journal Entry | Payroll Run |
|---|---|---|---|---|---|---|
| Created by | `requester_user_id` | `user_id`/`created_by` | `user_id` | `recorded_by` | `created_by` | `created_by` |
| Edited by / field diff | **No** | Partial (top-up amount only) | **No** | **No** | **No** | **No** |
| Approved by | `approved_by/at` | status only, no dedicated log | `PurchaseOrder.approved_by/at` | N/A | N/A | N/A (only `locked_by`) |
| Rejected by / reason | **No dedicated field or action** | `reject` route exists (not read in depth) | N/A | N/A | N/A | N/A |
| Posted by | `posted_by/at` | via JournalEntry | via JournalEntry's own `created_by` | via JournalEntry | `created_by`+`posted_at`, no separate `posted_by` | via `payment_journal_entry_id` |
| Reversed by | `reversal_of_id`, no `reversed_by/at` | actor captured in the ledger entry, not a column | N/A found | N/A | `reversal_of_id`, no `reversed_by/at` | N/A |
| Reconciled by | N/A | N/A | N/A | `reconciled_by/at`, `imported_by`, `matched_by/at` — well covered | N/A | N/A |

**ISSUE (MEDIUM).** SpendVoucher has no way for anyone but the original requester to formally reject
it, and no `rejected_by`/`rejected_at`/`rejection_reason` columns exist — a genuine SOP/workflow gap
as well as a logging gap (cross-referenced with §13.1 Issue #5).

**ISSUE (LOW-MEDIUM).** No Finance model carries a generic before/after audit for ordinary field
edits except the one petty-cash top-up case. Mitigated because posted/reconciled/paid records are
largely protected from further mutation — the exposure window is mostly lower-stakes pre-posting
drafts.

### 22.4 What IS solid (avoid over-stating the gap)

**FACT.** Reconciliation audit trail is comprehensive and consistent. `CostLine` carries
`submitted_by_user_id`/`verified_by` as first-class, queryable columns. Self-approval is actively
guarded in two places via `SelfApproval::allowedFor()`, requiring an explicit override reason.
`Payment::void()` requires a reason and records `voided_by`/`voided_at`/`void_reason` — a complete
"who reversed and why" trail wherever voiding is implemented at the model level.

**Classification: KEEP&IMPROVE.** Column-level attribution is present and reasonably consistent —
this is not a "no audit trail" codebase. Missing: (a) a unified, correctly-named, general-purpose
audit-log table instead of the accidental `HRAuditLog` borrowing, (b) field-level before/after diffs
beyond the one petty-cash case, (c) a real approver-initiated rejection path for Spend Vouchers.

---

## Questions for WNG (Sections 13–16, 22)

**Management**
1. How many people actually hold `Accounts` and `Admin` today, and is ≥2 expected to stay
   permanent, given segregation of duties depends on headcount, not role boundaries (§14)?
2. Who should hold `FINANCE_EXPENDITURE_EXCEPTION_APPROVE` — currently only Super Admin?
3. Is it acceptable that HR, not Accounts/Finance, holds the authority to post payroll's journal
   entries to the general ledger?
4. Is there a legitimate reason to keep a full petty-cash data-wipe endpoint reachable in
   production, or should it be removed/converted to an offline tool?
5. Is a formal "approver rejects a Payment Voucher" workflow actually needed, or is the current
   design (approve, or ask the requester to cancel) sufficient?

**Finance / Accountant**
6. Should spend/payment vouchers have a value-based second-approval threshold?
7. Should `Accounts` be split into a "clerk" tier (create/record) and a "lead" tier
   (approve/post/reverse/close periods)?
8. For KRA input-VAT defensibility: is it acceptable that supplier bills/POs carry no attached
   invoice scan today?

**SOP / Process**
9. If WNG's HR function is ever a single person, how should a payroll run be locked and paid?
10. Should the Finance work-queue's `reassign` capability be exposed to Finance staff/leads?

**Technical**
11. Confirm whether any code path writes directly to `journal_entries`/`journal_lines` via raw SQL
    outside `JournalPostingService`.
12. Should `BillController::canVerify()` migrate to a dedicated `Permissions::` constant?
13. Should `HR_MANAGE_PAYROLL` be split into stage-specific permissions?

---

*Consolidated risk ratings appear in `10_FINANCE_RISK_REGISTER.md`. Consolidated WNG questions
appear in `12_WNG_CONFIRMATION_QUESTIONS.md`.*
