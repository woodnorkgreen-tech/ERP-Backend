# 76A — P0 Financial & Cost Integrity Closure, Part 1

**Date:** 2026-10-07
**Baseline:** `76_CROSS_MODULE_FINANCIAL_COST_FLOW_AUDIT.md`
**Mode:** Verify infrastructure, then implement the integrity fixes that need no WNG accounting decision.
**Code:** changes are in the working trees of `ERP-Backend` (on `master@376a162`) and `ERP-Frontend` (on `master@3bade6f`). **Nothing is committed, pushed or deployed.**
**Boundary kept:** no production access, no production data touched, no worker started or restarted, no queued job processed, retried or deleted, no Finance cutover, no `--execute` chart completion, no account classification, no W8. P0-3 to P0-6 were not implemented.
**Verdict:** 76A PART 1 COMPLETE — CODE-ONLY P0 INTEGRITY DEFECTS CLOSED; ACCOUNTING POLICY ITEMS REMAIN (§20).

---

## 1. Executive summary

Six items were in scope. All six are fixed in code and covered by tests that assert accounts and amounts, not the existence of a journal.

| Item | What was wrong | Now |
|---|---|---|
| **P0-7** Queue reliability | Eight cost-chain listeners waited on a queue worker. With no worker they never ran, and nothing failed. | All eight run in the request that raised them and leave a record. A failure is visible to Finance and can be retried; a retry posts once. |
| **P0-1** Stock issue reversal | Posted Dr Accounts Payable / Cr WIP. | Posts Dr Inventory / Cr WIP. Accounts Payable is untouched. |
| **P0-2** Payment reversal | Reversed from Finance, the payment was voided but its project cost and journal stayed. | One reversal for every entry point. A payment that is a project cost takes its cost and journal with it, in one transaction. |
| **P0-8** Margin basis | VAT-inclusive invoice totals less VAT-exclusive cost. | Revenue net of output VAT. Net 100,000 + VAT 16,000 with cost 60,000 gives 40,000, not 56,000. |
| **P0-9** Company-paid capture | Credited a cash or bank account in the ledger with no payment document. | Verification creates one `Payment` through the settlement engine. One cost, one payment, one expense entry. |
| **P1-11** Void listener crash | Read an undefined variable when a void had no user. | Fixed. A cost left standing is reported as a failed posting naming the cost line; no user is invented. |

Three things found along the way and fixed because they sat inside these changes:

1. **Every listener in `app/Listeners` was registered twice** — once explicitly and once by the framework's event discovery. Each event ran its 18 listeners twice. Idempotency hid it for cost postings; it also doubled every board-workflow and enquiry notification. Discovery is now off (§4.4).
2. **Payment reversal tried to reverse a reversing entry** when part of a payment (its fee) had already been backed out, and refused the whole reversal (§6).
3. **The cost capture form offered Supplier Credit as a paying account**; the server accepted any active source (§8).

Two things this phase did **not** do, stated plainly:

- **The target environment was not inspected.** No route to it exists from this workstation (§3). What is reported is repository and source-copy evidence, plus a read-only command an operator can run on the host.
- **The dev database is not migrated.** The change adds one table, `finance_event_postings`. Until `php artisan migrate` is run on a database, approving an order, receiving goods, paying or voiding on that database will fail when the posting record is written. I did not migrate `db` without being asked (§16).

Tests: seven backend suites, 1,586 of 1,586 passing on the final code (§15), including 23 new regression tests. Five new frontend tests pass.

---

## 2. Baseline

Report 76 classified 9 P0 findings. This phase takes the five that are code-only (P0-1, P0-2, P0-7, P0-8, P0-9) and P1-11. P0-3 to P0-6 wait on WNG decisions (§17).

Two statements in Report 76 are corrected here:

| Report 76 said | Correct |
|---|---|
| "Only `CostCollectorService` and `CostTransferService` create cost lines" (§3) | `RepostMisattributedStoresCostsCommand` also writes them, by `replicate()->save()`. It is a console repair tool, not a live path. |
| Listeners "registered exactly once" (carried from Report 48 §2) | Each was registered twice. See §4.4. |

---

## 3. 76A-0 — target queue verification

### 3.1 What could be inspected

**The live target could not be inspected.** The only credentials for the host are GitHub Actions secrets (`SSH_HOST`, `SSH_USER`, `SSH_PRIVATE_KEY`, `SSH_PORT` in `.github/workflows/deploy.yml`). This workstation has no SSH configuration for it and no other route. No attempt was made to obtain one.

| Question | Answer | Source |
|---|---|---|
| 1. `QUEUE_CONNECTION` on the target | **Not verified.** `.env.example` ships `database`; `config/queue.php` falls back to `redis` if the key is absent. Neither is `sync`. | Repository |
| 2. Is a worker configured | **Not verified.** Nothing in the repository starts one on production. `deploy/erp-queue-worker.service` exists and its own header says it was never installed on shared hosting. | Repository |
| 3. Is a worker running | **Not verified on the target.** On the production source copy it was not, as of the copy date: see 5–7. | Source copy |
| 4. Supervisor, systemd, cron or cPanel | **Not verified.** `deploy.yml` contains no `queue:work`, `queue:restart` or `schedule:run`. Commit `60491b1` records that production shared hosting has no service supervisor. | Repository |
| 5. Jobs count | **1,088** in `woodnork_erpsystem.jobs` (local copy of the production source database). Target: not verified. | Source copy |
| 6. Oldest pending job | **2026-01-06 15:21:50.** Newest 2026-07-27 08:33:09. None reserved by a worker. | Source copy |
| 7. `failed_jobs` count | **0.** | Source copy |
| 8. Do the eight listeners depend on that worker | **Before this change, yes, all eight.** After it, none (§4). | Code |
| 9. Scheduler | Two scheduled commands in `routes/console.php` and two in `bootstrap/app.php`; none drains a queue. Whether the host runs `schedule:run` is **not verified**. | Repository |

Reading of 5–7: 1,088 jobs accumulating over seven months, none reserved, none failed, is what an asynchronous driver with no worker looks like. It is evidence about the source system on its copy date, not about the target today.

### 3.2 How to verify the target

Two read-only tools now exist. Neither starts, restarts, processes, retries or deletes anything.

```
# On the host, as the deploy user:
bash ~/erp-backend-master/deploy/diagnose-target-deploy.sh ~/erp-backend-master
# or just the queue part:
php artisan finance:posting-health
```

`finance:posting-health` prints the queue driver in force, whether config is cached, the jobs count with oldest and newest dates and how many a worker has reserved, the jobs by class, the `failed_jobs` count, and the cost postings needing attention. The diagnostic script additionally looks for a running `queue:work` process, a systemd user unit and Supervisor.

`finance:posting-health --retry` re-runs failed cost postings. It still never touches the `jobs` table. It was not run anywhere.

### 3.3 What the answer changes

Nothing about cost correctness any more. Whatever the target's queue is, the eight cost-chain postings no longer use it. The answer still matters for the 1,088 old jobs (902 of them `ProjectActivated`, 158 mail notifications) and for notifications, which remain queued by design. Those jobs were not processed and should not be without a decision: most are months stale.

---

## 4. Critical-listener architecture, before and after

### 4.1 Classification

| Listener | Establishes or reverses | Critical | After |
|---|---|---|---|
| `RecordPurchaseOrderCommitments` | Project commitment | Yes | Synchronous, recorded |
| `RecordGoodsReceiptAccruals` | Accrual; the only Inventory debit for a purchase | Yes | Synchronous, recorded |
| `RecordPettyCashCommitment` | Project commitment | Yes | Synchronous, recorded |
| `ReleasePettyCashCommitment` | Reversal of a commitment | Yes | Synchronous, recorded |
| `RecordPettyCashCost` | Actual cost and its journal; payment fee journal | Yes | Synchronous, recorded |
| `ReversePettyCashCost` | Reversal of cost, fee and direct-payment journals | Yes | Synchronous, recorded |
| `ProjectBudgetLines` | Planned lines every commitment and actual is matched to | Yes, for budget-versus-actual | Same process, after the response, recorded |
| `SyncBudgetWithMaterialsList` | The budget's material list, which feeds the planned lines | Indirectly: a budget that stops following its materials list is a wrong plan | Same process, after the response, recorded |

Not converted, deliberately: the four board-workflow notification listeners, mail and push notification jobs, task notifications, attendance sync. They are not financial and stay on the queue.

### 4.2 Before

```
business transaction commits
  → event dispatched
  → listener is ShouldQueue → a row in `jobs`
  → (worker) runs it        ← if no worker: nothing, for ever, silently
```

`failed()` hooks existed on every listener and could never fire for a job that never started. Tests could not see the gap: `phpunit.xml` forces `QUEUE_CONNECTION=sync`.

### 4.3 After

```
business transaction
  → event dispatched → listener writes ONE row in finance_event_postings
                       (type, subject, payload, status = pending) — inside the transaction
  commit
  → FinanceEventPoster runs the posting, in the same PHP process
       success → status = posted, outcome recorded
       failure → status = failed, error recorded, Finance notified
                 the business action has already succeeded and is not disturbed
  → an authorised user retries (or `finance:posting-health --retry`)
       every handler is idempotent on its own source key → posts once
```

| Property | How |
|---|---|
| No worker needed | `DB::afterCommit` in the request process. The budget projection uses the framework's after-response hook (`defer`), still the same process. |
| Cannot exist for a rolled-back event | The row is written inside the caller's transaction. |
| Cannot be owed without a record | Same row, same transaction. |
| Never blocks the business action | `attempt()` catches everything and records it. |
| A posting raised by another posting | The materials sync rewrites the budget, which announces a projection. That second posting runs in place rather than being handed to an after-response hook that is already executing. |
| Visible failure | `GET /api/finance/postings`; the "Cost postings" panel on Finance → Setup → System checks; a critical notification to holders of `finance.costs.verify`. |
| Retry | `POST /api/finance/postings/{id}/retry`, permission `finance.costs.verify`, attributed (`last_retried_by`). |
| Idempotent | Cost lines on `(source_type, source_id, source_ref)`; journals on `entry_no`; reversals on `reversal_of_id`. Unchanged. |
| A request that dies between commit and posting | The row stays `pending`, is shown as "not finished" after 10 minutes, and is retried the same way. |
| Monitoring | `finance:posting-health` (read-only). |

One row per (posting type, subject), updated on each occurrence, so the table does not grow with every budget save.

The existing petty-cash "retry cost posting" action now goes through the same record, so the payment's own failure flag and the posting list cannot disagree.

**Not done:** `finance:posting-health --retry` is not wired into `deploy.yml` or the scheduler. That is a deployment change and was left for a decision.

### 4.4 Duplicate listener registration

`php artisan event:list` before the change showed every `app/Listeners` class twice per event, as `Listener` and `Listener@handle`: 124 registrations, 36 of them for 18 classes. `App\Providers\EventServiceProvider` lists them explicitly and sets `shouldDiscoverEvents()` to false, but that only governs itself; the framework's own provider was still discovering the same directory.

`bootstrap/app.php` now calls `->withEvents(discover: false)`. After: 106 registrations, each of the 18 once, and every other registration identical (`76a-evidence/event-list-before.txt`, `event-list-after.txt`).

Effect beyond Finance: board-request, dispatch, offcut and enquiry notifications were being sent twice. They are now sent once.

---

## 5. P0-1 — stock issue reversal

| | |
|---|---|
| **Root cause** | `JournalPostingService::settlementAccountFor` treated only `stock-issue` and `stock-return` as stock movements. A reversal carries `stock-issue-reversal`, fell to the last fallback, and settled against Accounts Payable. |
| **Before** | Issue: Dr WIP 1,000 / Cr Inventory 1,000. Reversal: **Dr Accounts Payable 1,000** / Cr WIP 1,000. |
| **Change** | One constant, `JournalPostingService::STOCK_MOVEMENT_REFS`, names every cost-line reference a Stores movement produces. `settlementAccountFor` uses it. No controller chooses an account. The same list now drives the cost line's "inventory movement — no cash liability" label and Finance's inventory issue views, which had the same omission. |
| **After** | Reversal: **Dr Inventory 1,000** / Cr WIP 1,000 — the account the issue debited. |
| **Test** | `test_a_stock_issue_reversal_restores_inventory_and_never_touches_accounts_payable`: asserts the debit account is Inventory (function code 1200), the credit account is the issue's own debit account, net Inventory 0.00, net WIP 0.00, **zero journal lines on Accounts Payable**, one cost line and one journal after two calls, project actual 0.00, original cost line still on record. |
| **Accounting effect** | Inventory restored. WIP cleared. Accounts Payable not involved. |
| **Project cost effect** | Unchanged from before: one negative actual, linked to the original by `reversal_of_id`. |

Unchanged and still true: the reversal restores stock once (`StockMovementReversalService`, under lock, one reversal per movement), is period-controlled, and keeps both movements.

---

## 6. P0-2 — payment reversal

| | |
|---|---|
| **Root cause** | `PaymentReversalService` reversed journals sourced to the payment, its voucher and its bill payments. A payment that is itself a project cost holds that cost on a cost line whose journal is sourced to the **cost line**. Only `PettyCashService::voidDisbursement` dispatched the event that reversed it, through a queued listener. `PaymentController::reverse` did not. |
| **Before** | Finance reversal: payment `voided`, float 500,000; cost line `verified`, journal `posted`, project actual 4,500. |
| **Change** | `PaymentReversalService::reverse` is the one orchestration. It now calls `reverseDirectCost`, which finds the payment's cost lines **from source links only** (`directCostLines`) and reverses each through `CostVerificationService::reverse`, inside the same transaction as the void. If the cost cannot be reversed the whole reversal is refused. |
| **After** | Both entry points: payment `voided`, cost line `reversed`, cost journal `reversed` with one reversing entry, project actual 0.00, ledger float net 0.00, custody float 500,000. |
| **Test** | `test_both_reversal_entry_points_leave_a_directly_costed_payment_in_the_same_state` reverses one payment through `POST /api/finance/payments/{id}/reverse` and another through `PettyCashService::voidDisbursement` and asserts the same result for both. Also: `..._is_idempotent`, `..._that_created_no_project_cost_invents_none`, `test_a_closed_period_refuses_the_whole_reversal`. |
| **Accounting effect** | Dr paying account / Cr expense or WIP, dated today, alongside the fee and direct-payment reversals already done. |
| **Project cost effect** | The payment's actual is reversed with it. |

**Which payments carry a cost, and how that is decided.** Two links, nothing else:

| Link | Meaning |
|---|---|
| A cost line whose `source_type`/`source_id` is the payment | A direct petty-cash payment charged to a job |
| The cost line the payment names in `source_document_type`/`source_document_id` | The settlement of a company-paid cost (P0-9) |

A supplier bill payment, a requisition advance, a voucher payment and a payroll payment have neither, so nothing is reversed for them beyond their own journals. No description, job number or account text is read.

**The other direction.** Reversing the cost line of such a payment from the cost screen used to restore the ledger while leaving the payment active and the cashbook debited. `CostVerificationService::reverse` now hands that request to the payment reversal, so the two cannot be separated from either side.

**Refusals kept exactly as they were:** reconciled payments, payments accounted for in a live surrender, closed requisitions, payroll payments, payments already voided, and a closed accounting period (the reversing entries need an open one).

**Second defect fixed here.** The service selected every `posted` journal sourced to the payment, which includes a reversing entry already posted for that payment. If a fee had been reversed first, the service asked the ledger to reverse the reversal and was refused. It now excludes entries that are themselves reversals. `PettyCashCostListenerTest::test_voiding_a_paid_disbursement_reverses_its_cost` exposed this.

---

## 7. P0-8 — margin basis

| | |
|---|---|
| **Root cause** | `marginAgainstJournals` and `portfolioMargin` summed `project_invoices.total_amount`, which is subtotal + VAT. Costs are net of recoverable VAT. |
| **Before** | Net 100,000, VAT 16,000, cost 60,000 → margin 56,000 (48.3%). |
| **Change** | One shared SQL fragment, `CostAccountService::REVENUE_SUMS`, gives net, VAT and gross. Margin uses net (`total_amount − tax_amount`). Both functions use it, so they cannot diverge. The response adds `revenue_basis: net_of_output_vat`, `billed_gross` and `output_vat`. |
| **After** | Margin 40,000 (40.0%). |
| **Test** | `test_project_margin_is_measured_on_revenue_net_of_output_vat` (including a credit note of −10,000 / −1,600, giving revenue 90,000 and margin 30,000) and `test_portfolio_margin_uses_the_same_basis` (every figure equal between the two). |
| **Accounting effect** | None. No journal, invoice, receivable, output VAT, allocation, client balance or tax report is read differently or changed. |
| **Project cost effect** | None. Only the revenue side of the displayed margin. |

Labels are unchanged and still enforced by the test: `margin_type: direct`, `margin_status: provisional`, `fully_loaded_available: false`.

The Finance Overview's project block reads `portfolioMargin`, so it follows. Screens now say "billed excl. VAT"; the cost account shows the VAT-inclusive total in a tooltip.

**Deliberately not changed:** `billed_fraction`, and `WorkInProgressReleaseService::billedFraction`, still divide the invoice **total** by the agreed quote. Whether quote amounts include VAT is WNG decision 8 of Report 76 and was not assumed. The code comment says so at the point of calculation. One consequence to be aware of: where margin uses the released-WIP cost basis, the released amount still follows that unconfirmed fraction (Report 76 P1-12, open).

---

## 8. P0-9 — company-paid cost capture

| | |
|---|---|
| **Root cause** | Capture stored `payment_source_id` in the cost line's details. Verification posted Dr expense / Cr that account's ledger account. Nothing created a `Payment`. |
| **Before** | Ledger cash credited; no payment document; petty-cash balance and cashbook untouched; nothing for reconciliation to match. Any active source was accepted, including Supplier Credit. |
| **Change** | `CostVerificationService::verify` calls `settleCompanyPaid` before the journal, inside the verification transaction. It creates the `Payment` through `PaymentSettlementService::settle` — the existing engine, no second one. |
| **After** | One cost line, one `Payment`, one journal. |
| **Test** | Five tests, §15. |
| **Accounting effect** | Unchanged in the ledger: Dr expense (+ input VAT) / Cr WHT / Cr paying account, once, from the cost line. The payment posts nothing. |
| **Project cost effect** | Unchanged: the cost line is the actual. The payment creates none. |

**The relationship**

```
Cost capture (company paid)
  → verified CostLine      — the project's actual cost; the ONE journal
  → Payment                — the money movement; no journal, no cost line
       source_document = the CostLine        cost line.settled_by_payment_id = the Payment
  → paying account         — petty cash: balance checked, capped, cashbook debited by the engine
                             bank / mobile money / card: an active payment for reconciliation to match
```

| Requirement | How it is met |
|---|---|
| Linked to the source cost | Both directions, as above. Payment number also stored on the line. |
| Amount | What left the account: net + VAT − withholding. The same figure the journal credits. |
| Visible in Finance | An ordinary row in `payments`, numbered from the shared payment sequence. |
| No double expense | The payment has no journal. `PettyCashCostProducer::postFor` returns `skipped_cost_capture_settlement` for it, so neither the backfill nor a replayed "paid" event can cost or post it again. Decided from the source link. |
| Reversible consistently | Through the one payment reversal (§6), from the payment side or the cost side. |
| Reconcilable | Matched like any payment, by `payment_id`. |
| Petty-cash custody | The engine debits the float and writes the cashbook entry. If the float cannot cover it, the verification is refused and rolled back. |
| Idempotent | `idempotency_key = cost-line:{id}`. |
| Permission | Verification already requires `finance.costs.verify` and a verifier other than the reporter. The payment is created by, and attributed to, the verifier. No new permission was added. |
| Payment-capable source only | Capture now requires `is_active`, `can_make_payment`, a ledger account, and type not `payable`. The engine re-checks at verification. The capture form lists payment-capable accounts only. |

**A point for WNG to confirm, not decided here.** The verifier's click now moves the petty-cash balance. That is correct — the money did leave — but it means a cost verifier can record a payment from the float without the disbursement permission. If WNG wants the payer to be a different role, verification of company-paid costs should require the payment permission as well.

---

## 9. P1-11 — void reversal listener

| | |
|---|---|
| **Root cause** | The "no voiding user" branch logged `$line->id` before any `$line` existed. Laravel raises that as an exception, so the job died on its own error message. |
| **Before** | Void with no user: exception, no reversal, and with a worker three retries of the same crash. |
| **Change** | The branch reports the outstanding cost lines by id and reference and raises a clear failure. Since §6, the payment reversal itself reverses these lines, so the listener normally finds nothing left to do; it remains as the net under any void that reaches the event another way. Failures are collected across all lines and raised once, so the posting record reads `failed` instead of a log line being the only trace. |
| **After** | See the table below. |
| **Test** | Four tests, §15. |
| **Accounting effect** | None of its own; it reverses through the same services. |
| **Project cost effect** | A cost that cannot be reversed is now reported, not lost. |

| Case | Result |
|---|---|
| Void with an actor, cost still standing | Cost reversed, attributed to that user. Posting `posted`. |
| Void with no actor, cost still standing | Not reversed. Posting `failed`: "Payment N was voided with no identified user, so its project cost (CL-…) is still standing and must be reversed by a named Finance user." No system user invented. Retried by a named user, it reverses once. |
| Already reversed | No-op. Posting `posted`: "project cost already reversed with the payment". |
| No cost line | No-op. Posting `posted`: "no project cost to reverse". |
| Reversal refused (closed period) | Posting `failed`, naming the cost line. Cost line unchanged. |

Fee and direct-payment journal reversals still run first and accept a missing actor, as before.

---

## 10. Single Economic Cost verification

| Workflow | Actual cost event | Changed by this phase | Verified |
|---|---|---|---|
| Stores issue | The issue | No | Reversal now mirrors it exactly |
| Direct petty-cash payment with a job | The payment | No | One cost line; reversed with the payment from any entry point |
| Company-paid capture | Verification | A `Payment` now exists beside it | One cost line, one journal, payment creates neither |
| Supplier bill payment | None (goods were costed earlier) | No | Reversal leaves project actual unchanged |
| Requisition advance | None until accountability | No | Reversal controls unchanged; PettyCash suite passes |
| Manual capture, out of pocket or unpaid invoice | Verification | No | Unchanged |

The new payment for a company-paid cost is the place a second cost could have appeared. Three guards prevent it: the payment has no journal; `postFor` refuses to cost it; the test asserts cost-line and journal counts stay at one after the backfill path and after a replayed event.

---

## 11. GL verification

Asserted by test, by account:

| Event | Entry |
|---|---|
| Stock issue | Dr WIP / Cr Inventory |
| Stock issue reversal | Dr Inventory / Cr WIP. No line on Accounts Payable. |
| Direct costed payment | Dr WIP / Cr Petty Cash Float |
| Its reversal, either entry point | Dr Petty Cash Float / Cr WIP. Float nets to 0.00. |
| Company-paid cost, bank | Dr expense / Cr that bank's account. One journal; none sourced to the payment. |
| Company-paid cost, petty cash | Dr expense / Cr Petty Cash Float |
| Its reversal | Journal reversed once. Float nets to 0.00. |

Read-only counts after the change (`76a-evidence/`): unbalanced journals 0, duplicate entry numbers 0, cost lines with more than one original journal 0, on both the dev and rehearsal databases.

---

## 12. Project-cost verification

| Check | Result |
|---|---|
| Issue then reversal | Project actual 0.00 |
| Directly costed payment | 4,500.00; after reversal 0.00, from both entry points |
| Payment with no cost link, reversed | Project actual unchanged at 60,000.00; cost-line count unchanged |
| Company-paid cost | One cost line; none sourced to the payment |
| Margin | 40,000.00 on net 100,000.00 and cost 60,000.00; 30,000.00 after a 10,000 net credit note; identical in the portfolio |
| Posting with an asynchronous queue and no worker | Project actual present in the same request; no job queued |

---

## 13. Payment verification

| Check | Result |
|---|---|
| One reversal orchestration | `PaymentReversalService::reverse`. Entry points: Finance reverse action, petty-cash void, cost reversal of a payment-backed cost. |
| Same result from each | Asserted field by field |
| Supplier, advance, voucher, payroll payments | No cost link, so no cost reversal; existing suites pass unchanged |
| Reconciled, accounted-for, payroll, closed-period refusals | Unchanged; closed period asserted to leave payment, cost and float exactly as they were |
| Company-paid payment | Created by the engine, numbered, linked, idempotent, reversible, payable source refused |
| Custody | Float 500,000 → 498,800 on a 1,200 petty-cash cost; cashbook debit present; back to 500,000 on reversal |

Still open from Report 76 and not in scope: two payment creators (the settlement engine and the older petty-cash disbursement path) and three live payment-journal engines beside one unused replacement (P3-6).

---

## 14. Queue, failure and retry verification

| Test | Shows |
|---|---|
| `test_a_payment_reaches_the_cost_ledger_with_an_asynchronous_queue_and_no_worker` | With `queue.default = database` and nothing draining it, the cost exists in the paying request, the posting reads `posted` after one attempt, and no job for it is in `jobs`. |
| `test_no_cost_chain_listener_is_queued` | Every handler in `FinanceEventPoster::HANDLERS` is not `ShouldQueue` and exposes `post()`. |
| `test_a_failed_posting_is_visible_retryable_and_posts_exactly_once` | Closed period: the event does not throw; posting `failed` with the reason; listed by `GET /api/finance/postings`; a retry while the cause stands fails with 422 and posts nothing; after reopening, three further attempts leave exactly one cost line; `last_retried_by` recorded. |
| `test_a_budget_projection_runs_after_the_response_in_the_same_process_not_on_a_queue` | The posting is `pending` before the response, `posted` after the framework's after-response hook, and nothing is queued. |
| `test_a_posting_that_cannot_run_never_reaches_the_business_caller` | A handler that throws leaves a `failed` posting and no exception. |
| `test_retrying_a_posting_needs_the_cost_verification_permission` | 403 without `finance.costs.verify`. |

These do not rely on `QUEUE_CONNECTION=sync`: the first sets an asynchronous driver explicitly, and the second is structural.

---

## 15. Tests

Run serially on an isolated database created for this phase (`db_report76a_test`, removed afterwards), on the final code.

| Run | Database | Result |
|---|---|---|
| `Finance`, `Procurement`, `ProcurementStores`, `Stores`, `CostCollector`, `PettyCash`, `Projects` — 160 test classes | `db_report76a_test` | **1,576 passed, 10 failed**, 11,076 assertions, 881 s |
| The two failing classes, re-run | `db_scratch_test` | **10 passed** |

The 10 failures are the same environment guard as in Report 76: `W7LabourConcurrencyTest` and `RequisitionReceiverDisbursementConcurrencyTest` commit data from forked processes and refuse any database other than `db_test` or `db_scratch_test`. On an allowed database all 10 pass. **Net: 1,586 of 1,586.**

New: `tests/Feature/Finance/Report76AIntegrityTest.php`, 23 tests.

One existing test failed during the work and pointed at a real defect, which was fixed in the service rather than in the test: `PettyCashCostListenerTest::test_voiding_a_paid_disbursement_reverses_its_cost` (§6, second defect).

**The brief's 19 checks**

| # | Check | Test |
|---|---|---|
| 1 | Stock issue reversal account legs | `test_a_stock_issue_reversal_restores_inventory_and_never_touches_accounts_payable` |
| 2 | Repeated issue reversal | Same test (called twice; one line, one journal) |
| 3 | Finance endpoint payment reversal | `test_both_reversal_entry_points_…` |
| 4 | Petty-cash service payment reversal | Same test |
| 5 | Directly costed payment reversal | Same test; `…_is_idempotent` |
| 6 | Supplier payment reversal | `…_that_created_no_project_cost_invents_none` (a payment with no cost link); `SupplierLedgerRailTest` (existing, passing) |
| 7 | Requisition advance reversal restrictions | `RequisitionReceiverDisbursementTest`, `RequisitionReceiverAccountabilityTest` (existing, passing, unchanged) |
| 8 | Company-paid cost creates a Payment | `test_a_company_paid_cost_from_the_bank_is_one_cost_one_payment_one_expense` |
| 9 | Only one project actual | Same |
| 10 | No duplicate expense | Same (one journal; backfill and replayed event add nothing) |
| 11 | Petty-cash custody | `test_a_company_paid_cost_from_petty_cash_moves_custody`; `…_refused_when_the_float_cannot_cover_it` |
| 12 | Bank payment reconcilable | Bank test: an active payment on the bank account, unmatched and matchable |
| 13 | Payable source refused | `test_a_liability_or_unmapped_account_cannot_be_named_as_the_paying_account` |
| 14 | Margin excludes output VAT | `test_project_margin_is_measured_on_revenue_net_of_output_vat` |
| 15 | Credit note reduces net revenue | Same |
| 16 | Portfolio margin same basis | `test_portfolio_margin_uses_the_same_basis` |
| 17 | Void listener, null actor | `test_the_void_listener_with_no_actor_fails_visibly_and_invents_no_user` |
| 18 | Idempotent retry and reversal | `…_is_idempotent`; `…_posts_exactly_once`; `…_no_op_for_an_already_reversed_or_uncosted_payment` |
| 19 | Accounting-period controls | `test_a_closed_period_refuses_the_whole_reversal`; `test_a_cost_that_cannot_be_reversed_is_a_failed_posting_…` |

Existing tests changed: one. `BudgetProjectionOnCompletionTest::test_the_projection_is_queued` asserted the listener **was** queued; it now asserts the opposite and is renamed. No other existing test was edited.

Frontend: `costPostingExceptions.spec.ts`, 5 new tests, passing; the Finance setup, overview and cost-collector specs together, 87 of 87. The spec caught a defect in the new panel before it was finished: refreshing the list after a refused retry cleared the reason. Changed frontend files have no new lint or type errors; three pre-existing lint errors in two of those files were left alone.

Not run: `Hr`, `Unit`, the remaining frontend suites, and any browser-level check of the new panel.

---

## 16. Historical-data implications

| Finding | New behaviour | History |
|---|---|---|
| P0-1 | Fixed | Dev and rehearsal: 0 stock-issue reversals exist, so nothing was mis-posted there. Any installation that reversed an issue before this fix has a Dr Accounts Payable / Cr WIP entry to correct. **Requires a check on live data; no remediation was run.** |
| P0-2 | Fixed | Dev and rehearsal: 0 voided payments with a cost still counting. Live data: check before trusting project actuals for voided direct payments. |
| P0-8 | Fixed on read | None. Margin is calculated, not stored. |
| P0-9 | Fixed | Dev and rehearsal: 0 verified company-paid costs. Any such cost verified before this fix has no payment and is not given one retroactively. |
| P1-11 | Fixed | None. |
| P0-7 | Fixed going forward | **Old queued jobs were not processed.** Commitments, accruals and costs that never posted because no worker ran are not recovered by this change. Existing idempotent commands can repost them (`finance:backfill-petty-cash`, `finance:project-budgets`); there is none for purchase-order commitments and receipt accruals. That is a remediation task for a decision, not done here. |
| Duplicate listeners | Fixed | Duplicate notifications already sent stay sent. Cost data unaffected. |

**Migration.** One new table, `finance_event_postings`. It was applied only to the test databases. `php artisan migrate --pretend` on dev shows it as the only pending migration. A push to `master` runs `migrate --force` on production; this work has not been pushed.

---

## 17. Findings deliberately deferred

Not implemented, as instructed. Each still stands exactly as Report 76 describes.

| Finding | Why it waits |
|---|---|
| **P0-3** Receipt accrual ↔ supplier invoice true-up | Needs the VAT basis of order prices and where a difference goes |
| **P0-4** Non-stock purchase accounting | Needs the accounting point and account for a non-stock purchase |
| **P0-5** Stock receipt without a GRN | Needs the other side of the entry per reason for receipt |
| **P0-6** Damage, non-project issue, receipt reversal, adjustment reversal | Needs accounts and approval rules |

Also untouched: order close and cancel, supplier credit note and returns, W8 logistics, labour and WIP policy, quote VAT basis and WIP release, Stores valuation, project dashboard, Finance cutover.

Because P0-3 to P0-6 remain, **P0 is not closed overall.**

---

## 18. Remaining WNG decisions

All 18 decisions in Report 76 §32 remain open. This phase adds three:

| # | Decision |
|---|---|
| 19 | Should verifying a company-paid cost require the payment permission as well as cost verification, now that it records a payment and moves the float? (§8) |
| 20 | What happens to the 1,088 jobs queued on the source system, and to cost postings that never ran there? Process, discard, or repost selectively? (§3.3, §16) |
| 21 | Should `finance:posting-health --retry` run on each deploy or on the scheduler? (§4.3) |

---

## 19. Recommended next phase

1. **Before any deployment:** run `finance:posting-health` on the target (read-only) to close 76A-0 with evidence; run the migration; check live data for the two historical patterns in §16.
2. **76A-2:** the decisions paper for P0-3 to P0-6, then their implementation. Decisions 1 to 6 of Report 76 are the blockers.
3. **76B** as set out in Report 76 §30.

---

## 20. Verdict

**76A PART 1 COMPLETE — CODE-ONLY P0 INTEGRITY DEFECTS CLOSED; ACCOUNTING POLICY ITEMS REMAIN**

Closed in code and by test: P0-1, P0-2, P0-7, P0-8, P0-9, P1-11.

Not closed: P0-3, P0-4, P0-5, P0-6, which wait on WNG. P0 is not closed overall.

Carried with this verdict, not hidden by it: the live target's queue was not inspected; nothing is committed or deployed; the dev database is not migrated; historical data was not remediated.

Stopped after Report 76A Part 1.
