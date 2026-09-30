# 63 — Finance Frontend Stream E: W4 Spend Vouchers / Payment Control

**Date:** 2026-09-29
**Environment:** local development / DDEV only
**Production:** untouched. No production database, migration, journal, seed, opening balance, queue worker, deploy or cut-over. Nothing merged to `master`. W8 and Stream F not started.
**Migrations added in this stream:** none.
**Verdict:** see §56.

---

## 1. Executive Summary

Payment vouchers now have one controlled workspace: a register, one detail page per voucher, and a create page.

> VERIFIED LIABILITY → VOUCHER (submitted) → REVIEW → RETURN · CORRECT · RESUBMIT / REJECT → APPROVE → (SENIOR APPROVE) → POST = PAY → (REVERSE)

- **What was already sound.** The backend was mature before this stream. It already had W4-1, W4-2, single economic cost, reversal and partial liability settlement, all tested (`Wave3PaymentVoucherControlsTest`). Every W4 authorization check was already a permission, with no role names.
- **What was missing.** A voucher had no detail page. The old 902-line screen decided in Vue which buttons to show, and the backend returned raw models: reviewer IDs without names, and cost lines as full rows.
- **The new workspace.** Stream E adds a read projection with backend-decided actions (`SpendVoucherWorkspaceController`, `SpendVoucherActions`). It keeps a voucher's **approval, posting and payment** as three separate facts. The new screens are built on the shared Finance components.
- **A real control defect found and fixed:**
  - A voucher could pay a **goods-received accrual** before its supplier bill existed. When the bill was later verified, it cleared the same accrual again and created a second payable for the same goods.
  - The reverse direction had already caused KES 3,050 in real double payments, and was fixed on 15 September. This direction was still open.
  - Goods-received accruals are now paid only through their bill. This applies the existing purchase-to-pay rule and is enforced in three layers (§6).
- **Two smaller fixes:**
  - A voucher already returned to its requester could be "returned" again, stacking duplicate history. That is now refused.
  - Legacy liabilities with no funding mode were silently hidden from the create form. They are now shown, labelled.

## 2. Scope and Safety

Development/test environment only. `woodnork_erpsystem` and the rehearsal copies were read with `SELECT` only. No opening balances, production seeds, production journals, queue workers or cut-over.

Out of scope, and not touched: Stream F, W8, and the unrelated Design/Printing test failures (§42).

## 3. Starting Git Baseline

| Repo | Branch | Start SHA | vs `origin/master` |
|---|---|---|---|
| Backend | `finance/frontend-stream-e-spend-vouchers` | `8968e9b` | identical (0/0) |
| Frontend | `finance/frontend-stream-e-spend-vouchers` | `811e46a` | identical (0/0) |

Starting baselines, measured on that exact tree and saved to `~/.cache/erp-verify/stream-e/`:

- **Frontend tests:** 302 passed, 8 failed. All 8 failures are Design/Printing tests that fail identically on `origin/master` (Report 62 §17).
- **ENG-1:** 267 diagnostics.

## 4. Existing W4 Architecture

This was traced in code, not inferred from labels.

| Part | Where | Role |
|---|---|---|
| `SpendVoucher` (+ `SpendVoucherAllocation`, `SpendVoucherReview`) | `app/Modules/Finance/Models` | The document; it allocates to verified `CostLine` liabilities; its review history |
| `SpendVoucherController` | `api/finance/spend-vouchers/*` | store (= submit), cancel, approve, return, correction, resubmit, reject, senior-approve, post |
| `SpendVoucherSettlementService` | Finance/Services | Mints the `Payment` (idempotency key `spend-voucher:{id}`); never writes cost lines |
| `JournalPostingService::postSpendVoucher` → `postCashSettlement` / `postBalancedEntry` | Finance/Services | Dr the liability account (AP 2100 / Accrued 2150), Cr the paying account; `assertOpenPeriod` |
| `PaymentReversalService` (`POST payments/{id}/reverse`) | Finance/Services | Voids the payment, reverses the journals, marks the voucher `reversed` |
| `FinanceWorkQueueService` | My Actions | Four voucher work types: approve, correct, senior, post |
| `PettyCashCostProducer` | CostCollector | Skips any disbursement that belongs to a voucher (no second cost) |

**The exact lifecycle** is a `status` plus a `review_state`:

| Situation | `status` | `review_state` |
|---|---|---|
| Created (= submitted) | `pending_approval` | null |
| Returned | `pending_approval` | `returned_for_correction` |
| Corrected | `pending_approval` | `corrected` |
| Resubmitted | `pending_approval` | `resubmitted` |
| Approved | `approved` | `approved` |
| Awaiting senior approval | `approved` | `awaiting_senior_approval` |
| Rejected by a reviewer | `rejected` | `rejected` (`rejected_by` set) |
| Cancelled by the requester | `rejected` | unchanged (`rejected_by` null) |
| Posted and paid | `posted` | — |
| Reversed | `reversed` | — |

Points the brief assumed differently:

- **There is no draft state.** Creating a voucher submits it.
- **"Posting failed" is not a stored state.** `post` is one transaction, so any failure rolls it back entirely and the voucher stays `approved`.
- **Posting is the payment (§25).**

## 5. Final W4 Boundary

A payment voucher pays **liabilities that are already verified and in the ledger**, and nothing else.

## 6. Spend Voucher vs Procurement

**A voucher is appropriate for:**

- a supplier's unpaid invoice captured through the Cost Collector (`funding_mode = unpaid_invoice`);
- a staff member's out-of-pocket claim (`out_of_pocket`, voucher type `reimbursement`);
- legacy verified liabilities that have no funding mode.

**A voucher must NOT be used for:**

- buying something new. That is REQ → PO → GRN → Bill.
- paying a purchase-order supplier. That is `Bill → BillPayment` after the three-way match.
- anything with no verified, posted liability behind it.

**Defect found and fixed (W4/W2 boundary).**

- **The gap.** The eligible-liabilities list included GRN accrual cost lines (`source_type = GoodsReceiptNoteItem`, `source_ref = accrual`) whose bill had not been verified yet.
- **What followed from it.** Posting such a voucher debits Accrued 2150 and credits Bank. Later, `postSupplierInvoice` for the same PO debits 2150 **again**, credits AP 2100, and the bill becomes payable. That is a second payment for the same goods.
- **Why the earlier fix didn't catch it.** The 2026-09-15 fix (`settled_by_bill_id`) only blocked the bill-first direction.
- **Why the fix isn't a new rule.** The existing purchase-to-pay decision is "never add a supplier-payment path that bypasses `PurchaseOrderWorkflow`". Stream E applies that rule; it does not invent one.

It is now guarded at every layer:

1. `SpendVoucherController::eligibleLiabilities` uses `->notGrnAccrual()`, so the accrual is not offered.
2. `assertEligibleLiabilities` refuses it at creation with 422: "…is paid through its supplier bill after the three-way match, not by a payment voucher."
3. `JournalPostingService::resolveVerifiedLiabilityAccount`, the funnel shared by every liability-settling path, refuses it even through a stale allocation. A mutation test proved this layer is necessary on its own: without it, a stale allocation posted and paid.

There is one definition, `CostLine::isGrnAccrual()` / `scopeNotGrnAccrual()`. `SupplierLedgerRailTest` had asserted that an accrual was voucher-payable before its bill, which encoded the gap; it now asserts the opposite.

## 7. Spend Voucher vs Petty Cash

Petty cash stays REQUISITION → APPROVAL → DISBURSEMENT → SURRENDER → RECONCILIATION.

- **No cash advances.** A voucher cannot be one: `type` accepts only `payment` or `reimbursement`. The earlier `advance` type was removed on 2026-09-15 because it had no surrender loop.
- **Reimbursing from the float is allowed.** A reimbursement may be paid *from* the petty-cash float (a paying account of type `petty_cash`). This moves cash, not cost: `PettyCashCostProducer` skips any disbursement that belongs to a voucher.
- **Result:** no second cash-advance workflow exists, and none was created.

## 8. Navigation

Expenses & cash → **Payment vouchers** (`/finance/payment-vouchers`), with detail pages at `/payment-vouchers/:id` and a create page at `/payment-vouchers/new`.

**The name stays "Payment vouchers".** The brief says "Spend Vouchers", but the repository settled this on 2026-09-13 as one control with one user-facing name: `routes/api.php` says "'Payment Voucher' is the user-facing name only". Renaming it would bring back the two names that decision collapsed.

Old `/finance/spend-vouchers?voucher=` links redirect to a register search. Journals in the ledger drawer now open their own voucher.

## 9. Voucher Index

`VoucherListView` is fed by `GET api/finance/spend-vouchers/register`:

- **Server-side pagination** (25 per page by default, maximum 100).
- **Filters:** search (voucher number, payee, payment reference, notes), state group, charged to (project/overhead), job number, requester, cost centre, paying account, and date range.
- **Rows show:** voucher number and payee; what it pays (project, overhead or mixed, with job numbers and the number of liabilities); purpose; amount; state; who requested it and when; the payment number; and the backend's next action.
- **Count chips** come from the server's unfiltered summary.
- **Filters live in the URL.**

## 10. Voucher Detail

`VoucherDetailView` is fed by `GET …/{id}/detail`. It has these sections:

- the header, with state plus the three facets (approval, posting, payment);
- a lifecycle stepper;
- the returned notice with its reason;
- the "why you can't take the next step" line;
- backend-allowed actions only;
- the correction form;
- Voucher, Workflow, Liabilities paid, Payment, Accounting, Evidence and History.

## 11. Lifecycle

States are the backend's (§4). `SpendVoucherActions::state()` names each one, and `facets()` separates approval (`pending`/`returned`/`senior_pending`/`approved`/`rejected`/`cancelled`), posting (`not_posted`/`posted`/`reversed`) and payment (`unpaid`/`paid`/`voided`). The frontend only translates these through the shared vocabulary, which gains three small domains, `voucher_approval`, `voucher_posting` and `voucher_payment`. "Approved" never reads as paid. "Posted" reads as "Paid — Posted and paid", because in W4 the two are the same backend step.

## 12. Project vs Overhead

A voucher does not choose a classification: the liabilities it pays already carry one. A cost line with a job number or project is **project**; anything else is **overhead**. A voucher is `mixed` when it pays both.

Shown on each row, on each liability, and filterable. No voucher is forced onto a project.

## 13. Expense Codes

No new expense-category table. Each liability shows the expense code it was recognised under (code, name and family) and the journal that recognised it.

The existing Expense Code → account mapping acted at recognition. The voucher journal touches only the liability account and the paying account. Case B proves this (§43).

## 14. Create / Edit

`VoucherCreateView` works as follows:

- **Type:** supplier payment or staff reimbursement.
- **Liabilities:** the backend's eligible list, showing what remains unpaid on each, with a "pay all" shortcut.
- **Paying accounts:** only configured, active ones that can pay (`payment-sources?for=payment`).
- **Payment methods:** each account's own `typical_methods`. M-Pesa or card appear only where Finance has configured them.
- **Also captured:** reference, fee (business overhead), invoice and eTIMS numbers, and purpose.

The browser only blocks the obvious, such as more than remains or no account chosen. The server re-validates everything:

- one beneficiary per voucher;
- allocations equal the total and never exceed what remains;
- a paying account money can leave;
- an open period;
- only verified, posted liabilities.

**Editing:** after a return, the requester may correct only the payment details (paying account, method, reference, invoice numbers, notes). The liabilities and amount are fixed. That is the backend's rule, and the form says so.

## 15. Submission

Creating a voucher is submitting it. It records the requester and the time, reserves the allocations, and posts nothing.

## 16. Maker / Checker / Poster

| Step | Who | Enforced by |
|---|---|---|
| Make (create, correct, resubmit, cancel) | `finance.spend_vouchers.create`; correct/resubmit/cancel only the requester | controller + `SpendVoucherActions` |
| Check (approve, return, reject) | `…approve`; not the requester | same |
| Senior approve | `…approve_senior`; not the requester or the approver | same |
| Post (= pay) | `…post`; not the requester or the approver | same |
| Reverse | `finance.payments.reverse` | `PaymentController::reverse` |

The documented exception is `approvals.self_approve`: it lifts the person check and is recorded ("Separation-of-duties override used"). Proven in §44.

## 17. Review

The reviewer sees everything on one page:

- the purpose and payee;
- project or overhead, the cost centre, and each liability's expense code and recognising journal;
- the amount, paying account and method;
- the evidence and the preparer;
- the full history;
- the senior-approval requirement.

Duplicate warnings are not shown because W4 has no voucher-level duplicate detection (§27).

## 18. Return for Correction

The reviewer returns the voucher with a required reason (5–1000 characters, through the dialog; no `window.prompt`). The actor and time are recorded, and a review snapshot is kept.

The requester sees **Returned for correction by *name***, with the reason. Only the payment-detail fields reopen.

**New:** a second return while the voucher is still with the requester is refused. It previously stacked a duplicate "returned" event.

## 19. Correction & Resubmission

The same voucher is corrected and resubmitted. Nothing is deleted or replaced, and the history reads Created → Returned → Corrected → Resubmitted → Approved (tested).

## 20. Rejection

Rejection is the reviewer's decision and needs a reason. It is **terminal**: no reopen mechanism exists, so the UI offers none. The liabilities are released for a new voucher. The requester's own withdrawal is **Cancel**, shown separately as "Cancelled — Withdrawn by the requester".

## 21. Approval

Approval uses the backend's eligibility and the maker/checker rule, applies the senior configuration, and records who approved and when. It is shown as "Approved — Ready to post", and the Payment section reads "Approved, not paid yet. Posting the voucher makes the payment."

## 22. Senior Approval

Mechanism: `FinanceSetting::approvedValue('spend_voucher_senior_approval_threshold')`. Above that value, `approve` sets `awaiting_senior_approval`, and `post` refuses until senior approval is given.

`SpendVoucherActions::seniorPolicy()` reports the policy exactly as it stands:

- **`not_configured`**: "Senior approval policy not yet configured. Vouchers need ordinary approval only."
- **`awaiting_sign_off`**: a threshold has been proposed but not approved, so it is not applied.
- **`active`**: shows the approved threshold.

It also reports how many people hold the permission. When the policy is active but nobody holds it, the Overview says so.

No threshold or approver was invented. With nothing configured the gate is inactive: that is the backend's documented behaviour ("Null keeps the gate inactive"), and it is shown, not hidden.

## 23. Posting

The funnel is unchanged: `postSpendVoucher` → `postCashSettlement` / `postBalancedEntry`, balanced and transactional.

- **Open period:** checked in `post()` against the voucher's period, and again by the journal service.
- **Idempotent:** the journal is keyed `JE-SV-{id}`, the payment is keyed by `spend-voucher:{id}`, and the status is checked under `lockForUpdate`.

## 24. Posting Failure

There is no stored "posting failed" state. A failure rolls back the whole transaction, so the voucher stays **approved** with no payment and no journal (tested, including a closed period). The backend's sentence is shown through `financeErrorMessage`. When the voucher's period is closed, the **Post** action is already disabled with that reason, before anyone tries.

## 25. Payment

Posting **is** the payment:

- one step mints the `Payment` (the cash fact) from the voucher's paying account;
- the same step posts the liability-relief journal and the fee (GL 7800).

The Payment section shows the payment number, status, paying account, amount, date, method, reference, and any reversal. Vue writes no payment accounting.

## 26. Partial Payment

- **Voucher level:** not supported. A voucher is paid in full when it is posted: one voucher, one payment.
- **Liability level:** supported. A cost line can be paid in instalments by several vouchers, never above what is payable (`settledAmount` guard, `Wave3…test_partial_settlements…`), and it is marked settled only when fully paid.

No partial settlement of a voucher was invented.

## 27. Duplicate Controls

**Duplicate payment** is structural:

- the payment idempotency key and the journal key;
- a settled-amount guard per liability;
- allocations capped at what remains;
- one beneficiary per voucher.

There is no override path and no "proceed anyway".

**Duplicate economic document:** vouchers are not checked for a repeated external reference or invoice number. The same liability cannot be over-paid, and cost-line capture has its own duplicate-invoice guard. A second voucher for the same invoice would need a second liability, so this is recorded as a gap, not built (§52).

## 28. Attachments

Evidence reuses `FinanceAttachmentService` (`source_type = SpendVoucher`):

- `POST …/{id}/attachments` accepts a file or a reference;
- `GET …/attachments/{a}/download` downloads a file;
- each item shows its type, file or reference, who uploaded it, and when;
- the file path is never exposed (tested).

Who may add evidence: the requester, or anyone holding approve or post. W4 has no evidence policy of its own, so none was invented.

## 29. Audit Trail

The history shows only recorded events: created, plus every `spend_voucher_reviews` row (returned, corrected, resubmitted, approved, senior_approved, rejected), cancelled, posted, and reversed (from the payment's `voided_*`). Reasons are shown with them. Nothing is inferred. `HRAuditLog` keeps its server-side entries.

## 30. Reversal / Void

Reversal goes through `PaymentReversalService` via `POST payments/{id}/reverse`, with a required reason.

- **What reverses:** a reversing journal is added, the payment is voided, and the voucher becomes `reversed`.
- **What stays:** the original records are kept, and the liability becomes payable again.
- **When it's refused:** for a reconciled payment ("unmatch first"), and for anyone without `finance.payments.reverse`. The action projection says which.

Nothing is deleted (Case D).

## 31. Project Costing

The voucher owns **no** CostLine. The project cost belongs to the verified cost line it pays; the detail shows that link (job number, expense code and family, and the recognising journal). Payment adds no project cost (Case A). No margin is computed in Vue.

## 32. Single Economic Cost

The cost is recognised **once**, when the cost line is verified. Posting a voucher:

- debits only the liability account;
- creates no cost line;
- leaves every cost-recognition account's debits unchanged.

Proven for a project voucher (Case A), an overhead voucher (Case B), and a reversed and re-paid voucher (`Wave3…`). The GRN fix (§6) closes the one route by which the same goods could be paid twice.

## 33. WIP

Unchanged, and configuration-aware (capitalise remains the working setting). A project liability's WIP family came from its expense code at recognition. The voucher never touches WIP.

## 34. My Actions

The four existing work types (approve, correct, senior, post) now link to `/finance/payment-vouchers/{id}` (tested). There is no posting-failure type, because no such state exists (§24).

## 35. Overview

Voucher attention items come from the register summary:

- approved vouchers awaiting posting and payment, with the oldest age;
- returned vouchers;
- vouchers needing senior approval when nobody holds that permission.

No voucher dashboard was added.

## 36. Permissions

The final set:

| Permission | Allows |
|---|---|
| `finance.spend_vouchers.read` | Register, detail, evidence download |
| `finance.spend_vouchers.create` | Create, correct, resubmit, cancel (the last three only by the requester) |
| `finance.spend_vouchers.approve` | Approve, return, reject |
| `finance.spend_vouchers.approve_senior` | Senior approval |
| `finance.spend_vouchers.post` | Post = pay |
| `finance.payments.reverse` | Reverse the payment |
| `approvals.self_approve` | The recorded exception to the person checks |

In the matrix:

- Admin and Accounts hold read, create, approve and post. Segregation between those steps comes from the per-document person checks.
- `approve_senior` and `self_approve` are held only by Super Admin.
- `payments.reverse` is held by Accounts.

## 37. Role-Name Audit

| Location | Role-name logic | Security relevant? | Resolution |
|---|---|---|---|
| `SpendVoucherController`, `SpendVoucherSettlementService`, `PaymentController::reverse`, `PaymentReversalService`, `PaymentSettlementService` | None: permissions and the `self-approve` gate only | — | None needed |
| Old `PaymentVouchersView.vue` | None: `can(permission)` | — | Replaced |
| New W4 backend and frontend | None | — | — |
| `AppServiceProvider` global `Gate::before` | `hasRole('Super Admin')` passes every ability, including self-approval | Yes, but system-wide and deliberate | **Not changed.** It is documented there and on `APPROVALS_SELF_APPROVE`, and it is out of W4 scope. A test pins it and proves its use on a voucher is recorded (§44) |

## 38. Backend Eligibility

`SpendVoucherActions::forVoucher` returns `{allowed, reason}` for cancel, correct, resubmit, approve, return, reject, senior_approve, post and reverse. Each rule restates the controller's precondition and uses the controller's own refusal text; the post rule also covers the closed-period check.

Screens show an action only when it is allowed. When someone holds the permission for the next step but the rule refuses them, the screen states the backend reason up front, for example "The requester and approver cannot post this voucher." Tests prove that predicted refusals are real refusals.

## 39. Backend Changes

**New files:**

- **`SpendVoucherWorkspaceController`:** `register`, `detail`, `storeAttachment` and `downloadAttachment`. Identities are `{id, name}`.
- **`SpendVoucherActions`:** actions, state, facets and senior policy.

**Changed files:**

- **`SpendVoucherController`:** the double-return guard; GRN accruals excluded and refused (§6).
- **`JournalPostingService::resolveVerifiedLiabilityAccount`:** refuses GRN accruals.
- **`CostLine`:** `GRN_ACCRUAL_SOURCE`, `isGrnAccrual()` and `scopeNotGrnAccrual()`.
- **`FinanceWorkQueueService`:** voucher links now point to the detail page.
- **`routes/api.php`:** four routes, with `register` declared ahead of `/{id}`.

## 40. Frontend Changes

**New files, in `src/modules/finance/vouchers/`:**

- `w4.ts`: the client, types and labels;
- `VoucherListView.vue`, `VoucherDetailView.vue` and `VoucherCreateView.vue`;
- `VoucherLifecycle.vue` and `VoucherEvidence.vue`.

**Changed files:**

- `shared/status.ts`: new voucher keys plus three facet domains;
- `router/finance.ts`: list, new and detail routes, and the old-link redirect;
- `navigation.ts`;
- the Overview (voucher attention items);
- `JournalEntryDrawer` (opens the voucher itself).

**Deleted:** `cost-collector/views/PaymentVouchersView.vue` (902 lines). Its three tests in `wave3Controls.spec.ts` checked eligibility that Vue decided for itself; they are retired and replaced by backend-driven specs covering the same scenarios.

## 41. Backend Tests

`SpendVoucherWorkspaceTest` has **18 tests**, covering:

- the projection, filters, pagination and safe identity;
- the correction path and full history, and the double-return refusal;
- reject vs cancel;
- the four segregation proofs and the Super Admin exception;
- accounting cases A–D;
- senior-policy states and enforcement;
- the closed period;
- evidence;
- My Actions links;
- the GRN boundary at all three layers.

`SupplierLedgerRailTest` was updated (§6).

Three mutation checks, each caught:

- the double-return guard;
- the GRN posting-funnel guard (without it, a stale allocation paid);
- frontend action gating.

## 42. Frontend Tests

`vouchers/w4.spec.ts` has **22 specs**, covering:

- the vocabulary, and the senior-policy text in all three states;
- the register: rows, server-side filters and pagination, old links, specific empty states, create-permission hiding, error normalisation;
- the detail page:
  - project and overhead liabilities;
  - only backend-allowed actions, and the third-person posting explanation;
  - the return reason prompt, and cancel-does-nothing;
  - correction and resubmit, and senior approval;
  - post and pay, including a server refusal;
  - posted state with payment, journal and reversal;
  - evidence, and recorded history;
- create: legacy rows, account-driven methods, over-remaining guard, server refusal;
- Overview items.

**Full suite: 321 passed, 8 failed (329).**

- 321 = 302 at baseline − 3 retired + 22 new.
- The 8 failures are exactly the baseline's Design/Printing failures: 0 new, 0 fixed.

## 43. Accounting Tests

| Case | Proof |
|---|---|
| A — Project voucher | Cost lines, project cost and cost-recognition debits unchanged after posting; the detail shows project, expense code and recognising journal |
| B — Overhead voucher | The cost is recognised on its expense code's mapped account; the voucher journal never touches that account |
| C — Posting idempotency | A second approve and a second post are refused (422); exactly one journal and one payment |
| D — Reversal | Reversing entry present, original kept, voucher `reversed`, payment `voided`, reason in history, voucher count unchanged |

## 44. Segregation Tests

- **The maker cannot approve their own voucher,** even when holding the approve permission. Refused in the action and at the endpoint.
- **The approver does not become the poster,** even when holding the post permission. A third person posts.
- **Posting needs its own permission.** An independent senior approver without it gets 403.
- **A role name grants nothing.** Admin, Accounts, Finance, Accountant and Manager, with no permissions, get 403 on register, detail, approve and post.
- **Super Admin is the documented bypass.** It can approve and post its own voucher, and the audit log records "Separation-of-duties override used".

## 45. API Contract

`route:list` (1,403 routes) was matched against every Finance call. Each file's `base` is now expanded from its own constant, and the new W4 module was checked call by call (15/15 matched).

**0 unmatched:** 199 direct calls and 65 petty-cash service calls. The one call built from a variable, `api.post(base, …)`, is `POST api/finance/spend-vouchers` (store) and was checked by hand.

## 46. ENG-1

`vue-tsc --build --force --pretty false`: **267 → 267, 0 new diagnostics.** The error sets were compared as normalised sets against the Stream E starting tree, not the old 256. One interim spec typing error was found and fixed.

## 47. Build

`vite build` **PASS** (26.0 s).

## 48. Full Regression

Run sequentially on `db_test`, with no overlap:

| Run | Result |
|---|---|
| W4 + payment/settlement + costing + work queue + journals (8 classes) | **113 passed** (790 assertions) |
| Full suite, before the GRN fix | **1,605 passed, 0 failed** (11,561 assertions, 648 s) |
| GRN fix: W4, ledger rail, Wave 3, payables, settlement, settlement-account | **84 passed** (685 assertions) |
| Full suite, final (after the GRN fix) | **1,606 passed, 0 failed** (11,570 assertions, 680 s, exit 0) |

## 49. Real-Data Validation

Read-only `SELECT`s on the rehearsal copies.

- **Live source copy (`wng_source_rehearsal`):** **0 spend vouchers** and 0 unsettled verified liabilities. WNG has never used this workflow, so there is no historical voucher data, and none was manufactured.
- **Rehearsal target:** 1 voucher, `SV-20260928-0000001` (KES 100, posted). It is the Report 55 smoke test's own artifact, and it is internally consistent: one allocation, one payment (`PAY-2026-0004`, active), one journal (`JE-SV-0000001`), and no cost line sourced from a voucher.
- **Relevant finding:** it paid a **received-not-billed GRN accrual**, the defect closed in §6.

## 50. Visual Validation

**VISUAL VALIDATION NOT PERFORMED.** No browser tooling is set up in this environment. The frontend `.env` points `VITE_API_BASE_URL` at production, so a dev server was not started for screenshots.

## 51. Business Policy Register

| Decision | Technical Mechanism | Current Policy | Blocking? |
|---|---|---|---|
| Senior approval threshold | `spend_voucher_senior_approval_threshold`, applied only when approved; state shown | Awaiting WNG (not configured) | No |
| Senior approver | `finance.spend_vouchers.approve_senior` (matrix: Super Admin only) | Awaiting WNG: who, if anyone besides Super Admin | No |
| Voucher vs Procurement boundary | GRN accruals are bill-only, enforced in three layers (§6) | Existing purchase-to-pay rule applied; Finance to confirm | No |
| Voucher evidence requirement | Evidence can be attached; nothing requires it before posting | Awaiting WNG: whether any evidence is mandatory, and above what value | No |
| Reopen authority after rejection | None exists; rejection is terminal | Awaiting WNG only if a reopen is wanted | No |
| Partial-payment policy | Voucher paid in full; liability-level instalments exist | Awaiting WNG only if voucher-level part payments are wanted | No |

Carried separately and not solved here (§65 of the brief): the M-Pesa account, R-2 custody, petty-cash thresholds and surrender deadline, the advance ceiling, service GRNs, WIP sign-off, opening balances, the salary-advance GL, and W8.

## 52. Known Issues

1. **Rehearsal smoke test, W4 step.** `tests/Rehearsal/RealDataRehearsalSmokeTest.php:641` pays a "received, not billed" GRN accrual. Stream E now correctly refuses that, so the step will stop at "no eligible liability". It must be re-pointed to a Cost Collector unpaid supplier invoice or an out-of-pocket claim. Not edited here: the harness needs the rehearsal-copy pipeline to run, and untestable edits were avoided.
2. **Rehearsal target data.** `SV-20260928-0000001` paid a GRN accrual. Rehearsal-only. If that PO's bill is verified in the rehearsal DB, it would double-count; not relevant to production.
3. **No duplicate check on invoice numbers across vouchers** (§27). Low risk, because a second voucher needs a second verified liability.
4. **No forked-process concurrency test for vouchers.** Every mutation locks the voucher row in a transaction, posting locks allocations and cost lines, and settlement is idempotent. Repeat requests are tested. A true parallel test like `W7LabourConcurrencyTest` is recommended.
5. **Posting-rule error text.** "No complete posting rule could be resolved…" is shown verbatim. It contains no internals, but it is technical.
6. **Super Admin global bypass** (§37). System-wide and unchanged.
7. **The 8 Design/Printing frontend failures and the 267 ENG-1 baseline** come from `master`, not Finance.
8. **The KES 3,050 historical double payments** (bill-first direction, found 15 Sept) still need Finance's recovery decision. Not W4 code.

## 53. Files Changed

**Backend.**

- New: `app/Modules/Finance/Controllers/SpendVoucherWorkspaceController.php`, `app/Modules/Finance/Support/SpendVoucherActions.php`, `tests/Feature/Finance/SpendVoucherWorkspaceTest.php`, and this report.
- Modified: `SpendVoucherController.php`, `CostLine.php`, `JournalPostingService.php`, `FinanceWorkQueueService.php`, `routes/api.php`, `tests/Feature/Finance/SupplierLedgerRailTest.php`.

**Frontend.**

- New: `src/modules/finance/vouchers/` (`w4.ts`, `w4.spec.ts`, three views, two components).
- Modified: `shared/status.ts`, `router/finance.ts`, `navigation.ts`, `overview/useFinanceOverview.ts`, `ledger/components/JournalEntryDrawer.vue`, `tests/unit/finance/wave3Controls.spec.ts`.
- Deleted: `cost-collector/views/PaymentVouchersView.vue`.

## 54. Completion Matrix

| Capability | Status | Evidence |
|---|---|---|
| Spend Voucher index | COMPLETE | §9, specs |
| Server pagination/filtering | COMPLETE | §9, register test + spec |
| Voucher detail | COMPLETE | §10 |
| Project vs overhead | COMPLETE | §12, Cases A/B |
| Expense Code mapping | PRESERVED THROUGH EXISTING CONTROL | §13, Case B |
| Create/edit | COMPLETE | §14 |
| Submit | PRESERVED THROUGH EXISTING CONTROL | §15 (create = submit) |
| Review | COMPLETE | §17 |
| Return for Correction | COMPLETE | §18, double-return fix |
| Correct/resubmit | COMPLETE | §19 |
| Reject | COMPLETE | §20 |
| Approval | COMPLETE | §21 |
| Senior approval mechanism | COMPLETE (threshold/approver BLOCKED BY BUSINESS POLICY) | §22 |
| Maker/checker | COMPLETE | §44 |
| Poster segregation | COMPLETE | §44 |
| Posting | PRESERVED THROUGH EXISTING CONTROL | §23 |
| Open-period control | PRESERVED THROUGH EXISTING CONTROL | §23, closed-period test |
| Posting idempotency | PRESERVED THROUGH EXISTING CONTROL | Case C |
| Payment | PRESERVED THROUGH EXISTING CONTROL | §25 |
| Partial payment | NOT APPLICABLE at voucher level; liability instalments preserved | §26 |
| Duplicate payment control | PRESERVED THROUGH EXISTING CONTROL | §27 |
| Single Economic Cost | COMPLETE (GRN double-payment route closed) | §6, §32 |
| Project Cost integration | COMPLETE | §31 |
| Overhead accounting | COMPLETE | Case B |
| Attachments | COMPLETE | §28 |
| Audit trail | COMPLETE | §29 |
| Reversal/void | COMPLETE | Case D |
| My Actions | COMPLETE | §34 |
| Overview | COMPLETE | §35 |
| Role-name authorization | COMPLETE (Super Admin global bypass unchanged, documented) | §37 |
| Backend eligibility | COMPLETE | §38 |
| API contract | COMPLETE | §45 |
| ENG-1 | COMPLETE | §46 |
| Build | COMPLETE | §47 |

## 55. Exact Next Stream

### STREAM F — W6 PAYROLL FINANCE

Finance-facing payroll posting and settlement, not a rebuild of HR payroll. It includes the D6 labour-classification UI (Report 56 plan).

Carry these into it:

- §52 item 1 (the rehearsal W4 step);
- the §51 policy register;
- the Report 61 carry-overs: the petty-cash threshold fallback and the two remaining `window.prompt` calls in the requisition screens;
- the ENG-1 baseline file at `~/.cache/erp-verify/stream-e/eng1-baseline.txt`.

Stream F was not started.

## 56. Final Verdict

### STREAM E COMPLETE — W4 REDESIGN READY FOR STREAM F

- **Workflow:** every W4 capability is complete, or preserved through an existing control that is now proven by tests.
- **Segregation:** maker, checker and poster separation, the single economic cost, posting idempotency and reversal all pass.
- **Defect:** the one control defect found, the voucher-first GRN double payment, is fixed in three layers and covered by a mutation-tested guard.
- **Verification:**
  - backend 1,606 passed;
  - frontend 0 new failures (321 passed);
  - API contract 0 unmatched;
  - ENG-1 0 new;
  - build PASS.
- **What remains is not a technical defect:**
  - the senior threshold and approver, evidence, reopen and partial-payment decisions are business policy. Each is shown as configuration state or reported, never assumed.
  - the rehearsal-harness step (§52.1) is a test-tool carry-over.
- **Nothing bypassed:** no approval control is bypassed and no policy value was invented. Production is untouched.
