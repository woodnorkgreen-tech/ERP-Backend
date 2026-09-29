# 02 — Critical Financial Integrity Stabilization Plan

Phase 1.5, Part B. Covers every CRITICAL risk (C1–C7) from
`docs/finance-redesign/current-state/10_FINANCE_RISK_REGISTER.md`. This is a **plan**, not an
implementation — nothing described here has been executed. Each risk is presented as a summary
table row, followed by a fuller explanation, and then assigned to one of the two buckets required
by this phase: technical fixes safe to schedule immediately, and portions that must wait for a WNG
decision.

---

## Summary table

| Risk | Current Behaviour | Financial Impact | Proposed Fix | Files Affected | Migration Required? | Tests Required | Business Decision Required? |
|---|---|---|---|---|---|---|---|
| **C1** — Chart-of-accounts hard-coding bypasses `ChartAccountMap` | 12 GL codes looked up as literal numbers in `JournalPostingService`/`PayrollFinancePostingService`, never through the config translation layer | If WNG's live chart doesn't carry these codes, VAT/WHT/Inventory/Accrued/Payable/bank-charge/PAYE postings fail at posting time | Route all 12 lookups through `ChartAccountMap::local()`; fail loudly and specifically when a code is unmapped; separately, populate the mapping once WNG confirms the live chart | `JournalPostingService.php`, `PayrollFinancePostingService.php`, `config/finance_accounts.php` | No (code + config only) | Unit tests per lookup; a "missing mapping fails with a named error" regression test | **Yes** — live chart data + WIP accounting policy |
| **C2** — `PurchaseOrderController::update()` has no status guard | Any PO's items/supplier/total can be rewritten regardless of status | Approved/invoiced/paid orders can be silently altered after the fact | Add a status guard blocking edits once a PO is no longer `pending` | `PurchaseOrderController.php` | No | Feature test: edit refused post-approval; pending edits still work | Partially — the guard itself needs none; a formal amendment/change-order path does |
| **C3** — `PurchaseOrderController::destroy()` guard commented out + cascading FK deletes | Deleting an approved+paid PO cascade-deletes its Bill(s)/GRN(s), orphaning posted journal entries | Real path to destroying part of the audited GL trail for cash already moved | Reinstate and extend the delete guard; change the FK behaviour on `purchase_order_items`/`goods_receipt_notes`/`bills` from CASCADE to RESTRICT | `PurchaseOrderController.php`; new migration for FK change | **Yes** (FK migration) | Feature test: delete refused for non-pending or linked POs; DB test confirming FK no longer cascades | No |
| **C4** — `postSupplierInvoice()`/`reverseEntry()` bypass the balanced-entry funnel | Both write journal rows directly, no `bccomp` assertion | Balance holds "by construction" only — a future leg-logic bug could post an unbalanced entry undetected | Route both through `postBalancedEntry()` or a shared `assertBalanced()` helper | `JournalPostingService.php` | No | Regression tests confirming both paths now assert balance; a deliberately-corrupted-leg unit test | No |
| **C5** — Petty-cash disbursement GL-posting failure silently swallowed | `catch (\Throwable) { Log::warning }` around the GL post; HTTP response still reports success | Cash can leave the tin while the GL shows nothing, with zero visible signal | Stop swallowing the exception; flag the requisition and alert Finance instead of a log-only warning | `PettyCashRequisitionController.php`; possibly a new status/flag column | Possibly (flag column) | Test: failed GL post still completes the disbursement but is flagged and alerts Finance | Partially — the visibility fix needs none; "should this block the disbursement instead" does |
| **C6** — `clearAllData()`/`clear-all` petty-cash wipe endpoint | Live, routed, hard-deletes all petty-cash history in one call; Super Admin only | Irrecoverable destruction of the entire petty-cash audit trail from a single call | Remove production reachability (convert to a non-production console command), or, if a real use case exists, redesign with mandatory confirmation + export-first + out-of-band audit log | `PettyCashService.php`, `PettyCashController.php`, `PettyCashPolicy.php`, `routes/api.php` | No | Test confirming the route is unreachable in production (or the new confirmation/export/audit-log requirements) | One narrow question: does any legitimate production use case exist at all? |
| **C7** — Payroll cash-out has no `Payment` record | `markPaid()` posts a journal entry and writes reference fields onto `PayrollRun` itself; no `Payment` row created | WNG's largest recurring cash outflow is invisible to every Payment-based control | Have `postPayment()` create a real `Payment` row via the existing settlement engine, store its id on `PayrollRun` | `PayrollFinancePostingService.php`, `PayrollRunController.php`, `PaymentSettlementService.php`; new `payment_id` column on `payroll_runs` | **Yes** (new nullable FK column) | Feature test: `markPaid()` creates one correctly-referenced `Payment` row; regression test on accrual posting | Partially — forward-only fix needs none; retroactive backfill and payroll's GL-posting authority ownership do |
| **STAB-7** — Petty-cash requisition expense triple posting (discovered 2026-09-23 during W3-3 verification, not part of the original C1–C7 audit) | A requisition-based petty-cash advance's spend was recognised as project cost up to three times: once immediately at disbursement (`PettyCashCostProducer::postFor()`, both job-costed and overhead), once again per surrender item (`CostCollectorService::postFromSource()`), on top of the already-correct advance/clear pair | Confirmed live, reproduced KES 2,000 real spend → KES 6,000 recognised; fires on every job-costed and overhead petty-cash requisition disbursement in production today, not an edge case | Exclude requisition-linked disbursements from `postFor()`'s immediate posting (new `skipped_requisition_advance` outcome); add `CostContext::$postsIndependently` so a surrender item's CostLine still records project-cost attribution without independently posting; `postPettyCashSurrender()` stamps each surrender item's CostLine with the one entry that actually recognised it | `CostContext.php`, `CostCollectorService.php`, `PettyCashCostProducer.php`, `PettyCashRequisitionController.php`, `JournalPostingService.php` | No | 10 new tests in `Stab7PettyCashTriplePostingTest.php` covering full/partial/overspend surrender, multiple categories, overhead, Direct Disbursement, supplier/voucher settlement exclusions, and idempotency | No — this is a technical defect fix; historical remediation approach is a separate Finance/accountant decision (see `15_STAB_7_HISTORICAL_IMPACT_DIAGNOSTIC.md`) |

---

## Detail per risk

### C1 — Chart-of-accounts hard-coding

**Current behaviour.** `JournalPostingService` and `PayrollFinancePostingService` resolve twelve
GL accounts (`VAT_INPUT_CODE`, `WHT_PAYABLE_CODE`, `INVENTORY_CODE`, `ACCRUED_CODE`,
`PAYABLE_CODE`, `STAFF_ADVANCE_CODE`, `BANK_CHARGES_CODE`, and five payroll codes) by querying
`ChartOfAccount::postable()->where('code', $literalNumber)` directly, at 13+5 call sites, with no
call into `ChartAccountMap::local()` — the one place this pattern is already done correctly is
`resolveVerifiedLiabilityAccount()`.

**Financial impact.** This audit could not query WNG's live database and relies on a documentation
file (`chart-of-accounts-mapping.md`, read 2026-09-07) for what WNG's real chart contains. **If**
that document still matches production, most of the twelve lookups return `null` and the affected
posting paths throw `InvalidArgumentException` — meaning cost-line VAT/WHT legs, supplier-bill
accrual/payable legs, petty-cash advances, bank-charge fees, and PAYE/statutory payroll legs could
all be failing today. This is not confirmed; it is the single most urgent verification in this
entire remediation effort.

**Proposed fix, split into two independent pieces:**
1. *Technical (safe to schedule now):* route every `accountByCode(self::X_CODE)` call through
   `ChartAccountMap::local()` first, exactly as `resolveVerifiedLiabilityAccount()` already does.
   Improve the failure path so a missing mapping throws an exception naming the specific constant
   and account code expected, rather than a generic "account not found" — this makes any remaining
   gap immediately diagnosable from a support ticket rather than requiring a code read. This change
   is safe regardless of what the live chart turns out to contain: if a code is currently resolving
   correctly, routing it through the map first does not change the outcome (the map's default
   behaviour for an unlisted code should be to fall through to the direct lookup, preserving
   today's behaviour exactly, until WNG-confirmed mappings are added).
2. *Data (blocked on WNG confirmation):* populate `config/finance_accounts.php`'s mapping array with
   the correct translations once WNG confirms the live chart export and the WIP-accounting policy
   question is answered (five of the needed entries are already drafted but commented out, pending
   exactly this decision).

### C2 — `PurchaseOrderController::update()` has no status guard

**Current behaviour.** `update()` accepts a full item/supplier/total replace against any PO
regardless of status — pending, approved, delivered, invoiced, or paid — with no permission check
beyond the outer route-group middleware and no audit trail entry.

**Financial impact.** An approved PO underpinning an already-verified, possibly already-paid, Bill
can be silently rewritten. The three-way-match fingerprint will eventually detect the drift (the
bill's verification silently invalidates on next read), but the edit itself is unaudited and
undated relative to the original approval — a real path to disguising what was actually approved,
after the fact, with no immediate signal.

**Proposed fix.** Add a status guard refusing item/amount/supplier edits once a PO is not `pending`,
mirroring the immutability pattern already correctly used elsewhere in this codebase (e.g.
`PettyCashRequisitionController`, `Bill::destroy()`). This is a safe default — no legitimate
business process requires silently rewriting an approved order. A controlled amendment path (a
"change order" concept, with its own approval) is a separate, larger question for WNG (Workflow 2).

### C3 — `PurchaseOrderController::destroy()` guard commented out + cascading deletes

**Current behaviour.** The pending-only delete guard is literally commented out in source; only the
`approveOrder` permission gates the call. `purchase_order_items`, `goods_receipt_notes`, and `bills`
all cascade-delete on `purchase_order_id`.

**Financial impact.** Deleting an approved, delivered, invoiced, and already-paid PO cascade-deletes
its Bill(s) and GRN(s) at the database level, bypassing `Bill::destroy()`'s own protection entirely
(a cascade delete never calls it) and orphaning already-posted `JournalEntry`/`BillPayment` rows —
a real, live path to destroying part of the audited general ledger for a transaction that has
already moved cash. This is the single most severe finding in the entire Phase 1 audit.

**Proposed fix.** Reinstate the guard, and extend it to also refuse deletion of any PO with a
linked Bill or GoodsReceiptNote regardless of the PO's own status — mirroring `Bill::destroy()`'s
pattern almost verbatim. Separately, change the foreign-key constraints on the three cascading
tables from `CASCADE` to `RESTRICT`, as defense-in-depth below the application layer. The
application-level guard should ship immediately; the FK migration can follow once verified safe
against existing data (a `RESTRICT` change could fail if any current row already relies on cascade
behaviour — this needs a dry-run against a copy of production data before the migration is applied
for real).

### C4 — `postSupplierInvoice()`/`reverseEntry()` bypass the balanced-entry funnel

**Current behaviour.** Both methods build `JournalEntry`/`JournalLine` rows directly rather than
via `postBalancedEntry()`. `postSupplierInvoice()`'s `total_debit`/`total_credit` fields are both
set from the sum of the debit legs alone — there is no independent verification that the credit
legs sum to the same total before the write happens.

**Financial impact.** Balance holds only "by construction" for these two paths. This is currently
correct in practice (the leg-building arithmetic is sound today), but nothing in the code would
catch a future edit to that arithmetic, or a bad tax-account GL override, before an unbalanced entry
reached the ledger — this is the one gap in the entire posting architecture capable of letting
debits≠credits through.

**Proposed fix.** Refactor both methods to call `postBalancedEntry()` directly, or extract its
balance-assertion and postable-account checks into a small shared helper both paths call before
writing. Because both paths are already balanced by construction under normal operation, this
change should be behaviourally invisible in the success case — it only adds a verification step
and brings these two paths in line with every other producer in the file.

### C5 — Petty-cash disbursement GL-posting failure silently swallowed

**Current behaviour.** The call to `postPettyCashAdvance()` inside the disbursement flow is wrapped
in `catch (\Throwable $e) { \Log::warning(...) }`. The petty-cash side of the transaction (cash
leaving the float, recorded in `petty_cash_ledger_entries`/`PettyCashBalance`) has already committed
by the time this runs, so the exception is caught after the fact and only logged — the HTTP
response still reports success.

**Financial impact.** Real cash can leave the tin while the General Ledger's Staff Advances/Petty
Cash Float accounts silently record nothing for it — for example, if the petty-cash payment
source's linked GL account is missing or inactive. No automated reconciliation currently exists
between petty cash's own ledger and the GL to catch this divergence later; the only trace is a
server log line.

**Proposed fix.** Stop swallowing the exception silently. The disbursement itself should still
complete (reversing a legitimate cash disbursement after the fact because of an unrelated GL
misconfiguration would create its own problems), but the requisition should be visibly flagged
(e.g. a status/flag indicating "disbursed, GL posting pending") and Finance should receive an
actionable alert, not just a log entry. Whether a failed GL post should instead **block** the
disbursement outright until corrected — trading immediate cash availability for guaranteed GL
consistency — is a genuine operational policy choice for WNG, not a technical default this plan
should assume (Workflow 5).

### C6 — `clearAllData()` / `clear-all` petty-cash wipe endpoint

**Current behaviour.** A live, routed `DELETE clear-all` endpoint hard-deletes every
`PettyCashDisbursementAllocation`, `Payment`, `PettyCashTopUp`, and `petty_cash_ledger_entries` row
in one transaction, then rebuilds the balance from the now-empty ledger. Access is restricted to
Super Admin (the authorization policy always returns `false` except for the Super Admin `before()`
bypass).

**Financial impact.** A single call — from a compromised or misused Super Admin session, or an
engineer confusing this with an environment-reset utility — permanently and irrecoverably destroys
the entire petty-cash audit trail: every historical payment, top-up, and ledger entry, with no date
range, no soft delete, and no export-first step.

**Proposed fix.** The default recommendation is removal: delete the route and controller method
from the production codebase entirely, and if the underlying logic is genuinely needed for
resetting demo/staging data, move it into an artisan console command explicitly guarded to refuse
execution outside non-production environments. If WNG confirms an actual, legitimate production
need exists (none is apparent from this audit), the alternative is a redesign requiring typed
confirmation text, a mandatory export-to-file step before any deletion, and an audit-log write to a
store outside the tables being wiped — but this is the more expensive path and should only be
pursued if removal is confirmed unacceptable.

### C7 — Payroll cash-out has no `Payment` record

**Current behaviour.** `PayrollRunController::markPaid()` calls
`PayrollFinancePostingService::postPayment()`, which posts one `JournalEntry` and writes
`payment_journal_entry_id`/`payment_source_id`/`payment_date`/`payment_reference` directly onto the
`PayrollRun` row. No `Payment` row, no `BillPayment`-equivalent, and no `CashMovement` row is ever
created.

**Financial impact.** An entire month's net payroll — potentially WNG's single largest recurring
cash outflow — is invisible to every Payment-based control in the codebase: `FundCustodyService`,
`PaymentReversalService`, bank-reconciliation views built against `payments`, and any future
"list every cash-out event" report would all need to be separately told to also union
`payroll_runs`.

**Proposed fix.** Have `postPayment()` create (or delegate to the existing
`PaymentSettlementService::settle()` to create) a real `Payment` row referencing the same
`payment_source` the payroll-payment screen already collects, and store the resulting id on
`PayrollRun`. This reuses the exact architecture already correct for supplier payments and payment
vouchers — it is not a new mechanism. This fix, applied prospectively (to future pay runs only),
requires no business decision. Two related questions are separate and deferred: whether to backfill
`Payment` rows for already-paid historical runs (a data question, low risk either way), and whether
payroll's GL-posting authority should move from HR's permission set to an Accounts/Finance
permission (a governance question, tracked in the Decision Register — this does not block the
technical fix).

### STAB-7 — Petty-cash requisition expense triple posting

**Current behaviour (before the fix).** `PettyCashDisbursementPaid` fires the queued
`RecordPettyCashCost` listener on every disbursement, calling `PettyCashCostProducer::postFor()`,
which treats the entire disbursed amount as an immediately-substantiated final expense — correct
for a Direct Disbursement Request or Direct Bill, but not for a requisition-based advance, which
`PettyCashAdvancePoster` is already separately posting as a Staff Advance pending surrender.
Separately, `reconcileSurrender()` calls `CostCollectorService::postFromSource()` once per
surrender item, which posts its own independent journal entry on top of the correctly-designed
aggregate clearing entry `postPettyCashSurrender()` already posts. Full trace and evidence in
`14_STAB_7_PETTY_CASH_TRIPLE_POSTING_ANALYSIS.md`.

**Financial impact.** Reproduced: a KES 2,000 real spend was recognised as KES 6,000 of project
cost. This is not an edge case — it fires on every job-costed and every overhead petty-cash
requisition disbursement, live in production today.

**Proposed fix (implemented).** `postFor()` excludes any disbursement carrying a `requisition_id`
(new `skipped_requisition_advance` outcome, placed after the existing supplier/voucher-settlement
exclusions so those keep priority). `CostContext` gains `postsIndependently: bool = true`; the
surrender-item caller alone passes `false`, so `CostCollectorService::postFromSource()` still
creates a fully-attributed `CostLine` for project-cost reporting but does not also post it.
`postPettyCashSurrender()` stamps each surrender item's `CostLine` with the one entry that actually
recognised it, so nothing is left unposted or unlinked. No business decision is required for the
forward fix; historical remediation is a separate, deferred Finance/accountant decision.

---

## Immediate technical safety fixes — STATUS: ALL SEVEN IMPLEMENTED (2026-09-23); STAB-7 ALSO IMPLEMENTED (2026-09-23)

These do not depend on WNG business policy and were implemented on branch
`finance/critical-stabilization-fixes` following the confirmed decisions in
`03_WNG_FINANCE_DECISION_REGISTER.md`. Where a risk has a business-dependent portion, only the
technical portion was built here — the dependent portion remains under the next heading.

1. **C1 (technical portion — DONE).** `JournalPostingService::accountByCode()` and
   `PayrollFinancePostingService::account()` now route every literal reference-chart code through
   `ChartAccountMap::local()` first, matching the pattern already correct at
   `resolveVerifiedLiabilityAccount()`. Two additional un-mapped literals found during
   implementation (`'1010'` fallback ×2, and the `resolveUnallocatedDebitAccount()` match arm) were
   brought into the same fix, since they are the same defect class. Verified behaviour-unchanged
   when unmapped and correctly-resolving when mapped, via new tests in `JournalPostingTest.php` and
   `PayrollIntegrityTest.php`. The account-code *values* remain blocked on WNG's live chart
   confirmation (STAB-1).
2. **C2 (guard — DONE).** `PurchaseOrderController::update()` refuses to edit any PO whose status is
   not `pending`. Verified in `PurchaseOrderMutabilityTest.php`.
3. **C3 (full fix — DONE).** `PurchaseOrderController::destroy()`'s commented-out guard is
   reinstated and extended to also refuse deletion of any PO with a linked Bill or
   GoodsReceiptNote regardless of status. A new migration
   (`2026_09_22_000001_restrict_purchase_order_cascade_deletes.php`) changes the FK behaviour on
   `purchase_order_items`, `goods_receipt_notes`, and `bills` from `CASCADE` to `RESTRICT` as
   defense-in-depth, looking up each constraint's actual name dynamically (the `bills` table was
   `invoices` at FK-creation time, so its constraint name may not match Laravel's naming
   convention for the current table name). Verified in `PurchaseOrderMutabilityTest.php`.
4. **C4 (full fix — DONE).** `postSupplierInvoice()` and `reverseEntry()` both now build their legs
   and call `postBalancedEntry()` (`reverseEntry()`'s call required adding an optional
   `reversalOfId` parameter to the funnel, and relaxing `sourceType`/`sourceId` to nullable to match
   the schema). A new test in `LedgerCorrectionTest.php` directly proves the fix: reversing a
   deliberately-constructed unbalanced entry now throws "does not balance" instead of silently
   producing an equally-wrong compensating entry.
5. **C5 (visibility + retry — DONE, exceeds the original "visibility only" scope).** A new
   `PettyCashAdvancePoster` service replaces the silent `catch { Log::warning }` in
   `PettyCashRequisitionController::disburse()`: a failed advance posting now flags the requisition
   (two new columns, `advance_gl_posting_failed_at`/`advance_gl_posting_error`), alerts every holder
   of `finance.petty_cash.edit_disbursement` via a new notification type, and a new
   `POST .../retry-advance-posting` endpoint lets Finance retry once the underlying issue is fixed —
   safe to call repeatedly, since it goes through the funnel's own `entry_no` idempotency check.
   Verified in `PettyCashAdvancePostingTest.php` (4 tests, including that the retry does not
   duplicate the journal entry).
6. **C6 (removal — DONE).** The `DELETE /finance/petty-cash/clear-all` route, its controller method,
   and the `PettyCashPolicy::clearAll()` ability are all removed. The underlying logic survives only
   as `php artisan petty-cash:clear-all-non-production`, which refuses to run when
   `app()->environment('production')` and requires interactive confirmation (or `--force`).
   Verified in `ClearAllPettyCashDataCommandTest.php`; `PettyCashPolicyTest.php` updated to assert
   the route is gone (404) rather than forbidden (403).
7. **C7 (forward-only — DONE).** `PayrollFinancePostingService::postPayment()` now creates a real
   `Payment` row through `PaymentSettlementService::settle()` — the same engine every other payment
   rail already uses — linked via a new `payroll_runs.payment_id` column (migration
   `2026_09_22_000003_add_payment_id_to_payroll_runs.php`). Historical runs are untouched, per the
   confirmed forward-only scope. Verified in `PayrollIntegrityTest.php`.
8. **STAB-7 (forward fix — DONE; historical remediation deferred).** Discovered 2026-09-23 during
   the W3-3 factual verification, independently re-reproduced, and fixed the same day — full detail
   in `14_STAB_7_PETTY_CASH_TRIPLE_POSTING_ANALYSIS.md`, `15_STAB_7_HISTORICAL_IMPACT_DIAGNOSTIC.md`,
   `16_STAB_7_VERIFICATION.md`. See the STAB-7 detail section above for the fix; 10 new tests in
   `Stab7PettyCashTriplePostingTest.php` all pass. No historical accounting was changed.

**Two real bugs were found and fixed during implementation, before they could reach production:**
`PettyCashAdvancePoster`'s Finance-alert path originally called Spatie's permission-resolution
query without guarding against the named permission not existing yet in a given environment
(`Spatie\Permission\Exceptions\PermissionDoesNotExist` extends `InvalidArgumentException`, and was
escaping uncaught from inside a catch block) — now the entire alert path is wrapped in one
try/catch. Separately, the new `petty_cash_advance_posting_failed` notification type was not
registered in `config/notifications.php`, which would have made every alert attempt fail silently —
it is now registered alongside the existing `cost_*` types.

**Verification:** the full `tests/Feature/{PettyCash,CostCollector,Finance,Hr,Procurement,
ProcurementStores,Stores}` and `tests/Unit/Finance` suites were run after every change (876 tests
passing at the end). Two clusters of failures (4 tests in `PurchaseOrderApprovalPricingTest`, 2 in
`PettyCashCostProducerTest`) were investigated and confirmed — by direct code review showing the
failing methods use none of the code paths touched by any of the seven fixes, and by reproducing
the identical failures against the unmodified baseline — to be pre-existing and unrelated to this
work; they are not addressed here.

## Requires WNG confirmation first

Nothing in this section should be implemented until the referenced decision is made. Full detail
and options for each are in `03_WNG_FINANCE_DECISION_REGISTER.md`.

1. **C1 (data portion):** the actual account-code mapping values, blocked on WNG supplying/
   confirming the live `chart_of_accounts` export, and on the WIP-vs-immediate-COGS accounting
   policy decision.
2. **C2 (policy portion):** whether a formal change-order/amendment path should exist for approved
   Purchase Orders, and who may authorize it.
3. **C5 (policy portion):** whether a failed petty-cash GL post should block the disbursement
   outright, or proceed with a visible flag (as proposed as the technical default above).
4. **C6 (scope confirmation):** ~~whether any legitimate production use case for a full petty-cash
   data wipe exists at all~~ — **resolved 2026-09-23**: WNG confirmed none does; removal has
   proceeded as planned (see above). If a use case is later identified, this becomes a request to
   re-add a narrower capability, not to reverse a pending decision.
5. **C7 (scope portion):** whether to backfill `Payment` records for already-paid historical
   payroll runs, and whether payroll's GL-posting authority should move to an Accounts/Finance
   permission — neither blocks the forward-only technical fix.

---

*This plan does not authorize implementation. Per the governing instruction for this phase, the
"Immediate technical safety fixes" above are the set that CAN begin once this plan is reviewed and
approved — they are not yet in progress. The "Requires WNG confirmation first" items must not be
started until the referenced entries in `03_WNG_FINANCE_DECISION_REGISTER.md` are resolved.*
