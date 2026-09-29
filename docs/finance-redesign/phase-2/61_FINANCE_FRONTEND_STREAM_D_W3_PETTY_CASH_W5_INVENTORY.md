# 61 — Finance Frontend Stream D: W3 Petty Cash + W5 Finance-Facing Inventory

**Date:** 2026-09-29
**Environment:** local development / DDEV only
**Production:** untouched. No production database, migration, journal, reset, opening balance, queue worker, deploy or cut-over. W8 not started.
**Migrations added in this stream:** none.
**Verdict:** see §55.

---

## 1. Executive Summary

Finance now has a controlled petty-cash workspace and a read-only inventory workspace:

> REQUISITION → APPROVAL → DISBURSEMENT → SURRENDER → REVIEW → RECONCILIATION → FLOAT

> GRN → INVENTORY VALUE → PROJECT ISSUE (ACTUAL) → STORES vs LEDGER

- **W3 petty cash.** `/finance/petty-cash/overview` and `/finance/petty-cash/advances` are built on the shared Finance UI, and the existing requisition detail gained a backend-driven Finance position panel. Every state, figure and allowed action comes from a new backend projection (`PettyCashActions`, `PettyCashWorkspaceController`). The existing requisition routes still perform and guard every change.
- **W5 inventory.** `/finance/inventory`, `/finance/inventory/issues` and `/finance/inventory/adjustments` are read-only. They show the Stores valuation beside the Inventory ledger account, the difference, and the named reasons for it.

The Stream A carry-overs were larger than stated, and all are fixed:

1. **D-1 (float permission).** `PettyCashPolicy` defined `viewAny`/`viewBalance` but **nothing called them**. Any signed-in user could read the float, the disbursement register, top-ups and trends. Any signed-in user could also **rewrite** the balance (`POST balance/recalculate`). Anyone could download **any** requisition's voucher (an IDOR). All are now gated by permission, and the gate was mutation-checked.
2. **D-2 (reset).** The dead "Reset petty cash data" control and its client call are gone. The route stays withdrawn, and a test proves it is absent.
3. **D-3 (`received`).** It was shown as "Complete. Payment received and confirmed." It means *the requester confirmed the cash; the surrender is still outstanding*. It is now "Funds received — awaiting surrender" everywhere. In the source rehearsal copy, **8 real requisitions (KES 11,329)** were affected.

Further defects found and corrected:

4. **Stores authorisation by role name.** 16 checks in `ProcurementStoresController`, `StockMovementRequest` and 4 in `StockCountController` now use the equivalent `stores.*` permissions. Some of the old lists named **roles that do not exist** ("Finance", "Finance Manager", "Accountant"). Mutation-checked.
5. **The requisition detail granted buttons by `hasRole('Accounts')`**, which the routes then refused. It now shows exactly the backend's allowed actions. Mutation-checked.
6. **STAB-4 retries had no UI.** The advance and cost posting retries existed only as API routes. Failures are now visible on the overview, the requisition, My Actions and the Finance Overview, and can be retried after a deliberate confirmation. A second retry posts nothing.
7. **Petty-cash evidence could not be uploaded.** Surrender items had only a free-text `receipt_path`. Requisitions now take evidence through the generic Finance attachments. A related leak was closed: `FinanceAttachment` had been exposing the storage `file_path`.

One of my own earlier findings was wrong and has been corrected. §34 explains: approved stock counts *do* post to Inventory Adjustments (INV-001). Only write-offs and balances set outside a count do not.

Report 34 §6 step 4 is corrected by the addendum `34A_PROJECT_COSTING_MATERIALS_CHAIN_CORRECTION.md` (§28).

| Check | Result |
|---|---|
| New backend tests | `PettyCashWorkspaceTest` 11, `InventoryFinanceTest` 6: **17 PASS** |
| Stores + stock-count suites (after role → permission) | **129 PASS** |
| Backend full suite | **1,574 passed, 0 failed** (11,222 assertions, 670.8 s), final sequential re-run after the fixture fix, see §47 |
| Frontend suite | **275 PASS** (250 + 25 new) |
| Finance API contract | **176 direct call sites + 70 petty-cash service calls, 0 unmatched** |
| ENG-1 | **256** diagnostics; **0** in any Stream D file (§45) |
| Production build | **PASS** |
| Mutation checks | **3**: balance gate, `StockMovementRequest`, requisition-detail balance fetch (§39) |
| Real-data validation | **PERFORMED, read-only**, on the local rehearsal copies (§48) |

## 2. Scope and Safety

Stream D covered W3 petty cash, W5 Finance-facing inventory, the Stream A carry-overs D-1 to D-3, the role-name audit and the Report 34 correction. Salary advances (§29 of the brief) were not redesigned. Stream E was not started.

Safety:

- All work was local.
- No migration was added.
- Tests ran only on `db_test`, sequentially.
- Real-data validation used `SELECT` only against `wng_target_rehearsal` and `wng_source_rehearsal`.
- No browser was pointed anywhere, and the frontend `.env` that targets production was never used.
- No reset endpoint and no bulk deletion were created.
- No custodian, threshold, deadline, ceiling or GL treatment was invented.

## 3. Stream A Carry-over Audit

| Item | Stated | Found | Result |
|---|---|---|---|
| D-1 | `/petty-cash/balance` readable by any authenticated user | Also the register, show, summary, transactions, voucher, recent and search; all top-up reads; `recalculate` (a write); and a requisition voucher IDOR | Fixed (§4) |
| D-2 | Dead reset control | Button, store action and service call still present; route already withdrawn (C6) | Fixed (§5) |
| D-3 | `received` shown as Complete | Shown as "Complete" in the list and detail, and as "Paid" in the shared vocabulary | Fixed (§6) |

## 4. Petty-Cash Balance Permission

`PettyCashController` now calls the policy (`refuse()` helper → 403 with a sentence).

| Endpoint | Permission required |
|---|---|
| `index`, `show`, `transactions`, `summary`, `voucher`, `voucher/pdf`, `recent`, `search` | `finance.petty_cash.view` (`viewAny`) |
| `balance` | `finance.petty_cash.view_balance` (`viewBalance`) |
| `balance/recalculate` | `finance.petty_cash.recalculate_balance` (Super Admin only) |

`PettyCashTopUpController`:

| Endpoints | Permission required |
|---|---|
| `index`, `show`, `available`, `statistics` | `view` |
| `balance`, `availableBalance`, `trends` | `view_balance` |
| `balance/check` | `view_balance` or `create_disbursement` |
| `validate` | `create_top_up` |

`PettyCashRequisitionController::downloadVoucher` is limited to the owner or a `viewAllRequisitions` holder.

The form lookups a requester needs (projects, accounts, references, budget items) stay open.

On the frontend:

- the requisition detail reads the balance only when `canViewBalance`;
- the workspace overview returns `float: null` to someone without `view_balance`;
- the Finance Overview was already gated.

**Mutation check:** disabling the balance gate makes `test_balance_and_register_reads_require_petty_cash_permissions` fail (expected 403, got 200). The gate was restored.

## 5. Dead Reset Control

Removed from `PettyCashIndex.vue` (the button and `handleClearAll`), `pettyCashStore.ts` (`clearAllPettyCashData`) and `pettyCashService.ts` (`clearAllData` → `DELETE /clear-all`). A comment marks where it was.

`test_the_reset_route_no_longer_exists` asserts that `DELETE /api/finance/petty-cash/clear-all` returns 404. No replacement was created.

## 6. Status Correction

Backend statuses are unchanged. `PettyCashActions::state()` names them for display:

| Backend status | Presentation state |
|---|---|
| pending | awaiting_approval |
| approved | approved |
| rejected | rejected |
| disbursed | disbursed |
| **received** | **funds_received** |
| surrender_pending | surrender_submitted |
| surrender_returned | returned_for_correction |
| surrendered | reconciled |
| any status with a failed advance journal | gl_posting_failed |

The shared vocabulary gained `awaiting_surrender` and `posting_failed`, and a new `petty_cash_state` domain was added.

- `fund_requisition.received` and `disbursed` now read **"Awaiting surrender"** (previously "Paid").
- The detail banners read "Funds received — awaiting surrender. The advance stays open until the surrender is reconciled."
- The list's next step reads "Funds received — awaiting surrender".
- Banners were added for surrender submitted, returned and reconciled.

## 7. Existing Petty-Cash Architecture

- **Workflow authority:** `PettyCashRequisitionController` (approve, reject, disburse, confirm-receipt, surrender, surrender/return, reconcile, surrender/reverse, retry-advance-posting) and `PettyCashController` (disbursements, void, retry-cost-posting).
- **Abilities:** `PettyCashPolicy` on `Payment::class`.
- **Ledger:**
  - `PettyCashAdvancePoster`: Dr Staff Advances / Cr float. A failure is recorded as `advance_gl_posting_failed_at`.
  - `postPettyCashSurrender`: one clearing entry, `JE-PCS-*`.
  - `PettyCashCostPoster`: direct cash purchases.
- **W3 controls (Report 44):** `PettyCashControlController` (outstanding advances, custody, handovers) and cash counts via `PettyCashControlsPanel`.
- **Screens before Stream D:** `PettyCashIndex` (register and controls), `RequisitionIndex` (list with drawers), `RequisitionShow` (detail).

## 8. Final Petty-Cash Boundary

| Surface | Owner | Purpose |
|---|---|---|
| `/finance/petty-cash/requisitions`, `/new`, `/:id`, `/:id/edit` | Requesters and Finance | Raise, approve, disburse, surrender (existing drawers retained) |
| `/finance/petty-cash/overview` | Finance (`view_reports`) | Waiting work, advances, surrenders, posting failures, custody, cash count |
| `/finance/petty-cash/advances` | Finance (`view_reports`) | Every requisition, filtered by backend state |
| `RequisitionFinancePanel` on the detail | Anyone who may view the requisition | Posting, surrender figures, evidence, audit |
| `/finance/petty-cash` | Finance | Float register, top-ups, cash counts, custody (unchanged) |

Reads come from `api/finance/petty-cash/finance/*`. Writes go only to the existing routes.

## 9. Petty-Cash Overview

`GET finance/overview` requires `viewAllRequisitions` and returns:

- the float, only with `viewBalance`, with its low/critical thresholds or `null`;
- pending approval and approved-to-disburse (count and amount);
- outstanding advances (count, amount, count per surrender state, overdue, surrender deadline or `null`);
- surrenders awaiting review and returned;
- GL posting failures (advances and costs), with a `can_retry` flag;
- custody (`held_by`, `configured`);
- the latest cash count.

On screen, "Policy configuration required" appears wherever WNG has not set a value.

## 10. Requisitions

`GET finance/requisitions` is paginated. Filters: search, state (every presentation state plus `outstanding` and `gl_posting_failed`), classification, department, requester, project and date range.

Each row carries the requester, department, classification, project, amount, disbursement, `state`, `surrender_state`, per-action `{allowed, reason}` and `next_action`.

The Advances & surrenders list renders all of this with no client-side rules.

## 11. Approval

Rules (from the controller, restated in `PettyCashActions`):

- `reviewRequisition`;
- status must be pending;
- the requester cannot approve their own requisition unless `SelfApproval` allows it.

The detail now shows Approve/Reject only when the backend allows them. A user holding the Accounts role without the permission, or who raised the requisition, no longer sees buttons the route would refuse. Tested in both suites.

## 12. Disbursement

Rules: the `create` ability, status approved, and separation of duties (the requester cannot disburse to themselves unless `SelfApproval` allows it).

The existing disbursement modal is kept. The Finance panel shows the reference, amount, date, paying account, method, recipient and who recorded it.

## 13. GL Posting Failure / Retry

STAB-4 is preserved. When the journal fails, the disbursement stays recorded.

The failure is visible in four places:

1. overview → `gl_posting_failures` (errors are shown as sentences; `SQLSTATE`, exception text and stack traces are replaced by a safe message);
2. the requisition state `gl_posting_failed`, with `next_action = retry_posting`;
3. **My Actions** → `petty_cash_posting_failed` ("Retry ledger posting"), for holders of `finance.petty_cash.update`;
4. the Finance Overview → "Ledger exceptions".

Retrying requires a confirmation dialog that says to fix the cause first.

Test `test_a_failed_advance_posting_is_visible_and_the_retry_is_idempotent`:

1. makes Staff Advances unpostable;
2. disburses — the cash is recorded and the posting fails;
3. retries → 422;
4. fixes the cause and retries → 200;
5. retries again → 200 with **no new journal entry**;
6. confirms the failure has cleared from the overview and the queue.

## 14. Surrender

Submit and reconcile keep using `PettyCashSurrenderDrawer`, which is now also opened from the detail ("Record surrender" and "Review surrender", both shown by backend action).

The panel shows advance, spent, cash returned and not-yet-accounted-for (backend figures), each line with its expense code and receipt, and superseded lines kept for audit.

STAB-7 is unchanged (`Stab7PettyCashTriplePostingTest`).

## 15. Return for Correction

This existed (`surrender/return`, minimum 10 characters, not by the requester) and is reached through the drawer's review mode.

The projection reports `return_surrender` with the backend's reason, for example "You cannot review your own surrender." (tested). The panel shows who returned it, when, and why.

## 16. Reversal

`surrender/reverse` requires `finance.journals.reverse`, a reconciled status, a person other than the requester, and an existing surrender journal.

It is now offered only when the backend allows it, through a **danger** confirmation with a required reason of at least 10 characters. It posts compensating entries; nothing is deleted.

The list's `window.prompt` for reversal was replaced by the same dialog. Tested: the call carries the reason, and cancelling posts nothing.

## 17. Duplicate Receipt Control

The existing control is preserved (`Wave3PettyCashControlsTest`). A surrender line accepted as a possible duplicate now shows its override reason, who accepted it and when (`duplicate_override`, tested).

## 18. Evidence

New endpoints:

- `GET requisitions/{id}/attachments`
- `POST requisitions/{id}/attachments` (requester or `create`; file up to 10 MB, or a reference)
- `GET …/attachments/{a}/download` (visibility-checked, `nosniff`)

They reuse `FinanceAttachmentService`. `file_path` is now `$hidden` on `FinanceAttachment`, which also closed an existing leak through cash counts.

No evidence threshold is configured (`petty_cash_evidence_threshold` = null), so the screen shows **"Evidence threshold: policy configuration required"** and enforces nothing. Tested: upload, list without `file_path`, download, and an outsider refused all three.

## 19. Custody

The mechanism is unchanged: `held_by` on the balance, handovers, and `finance.petty_cash.manage_custody` (Super Admin only). **R-2 is unresolved**, and no custodian was assigned by this stream.

The overview shows the recorded holder, or "No custodian recorded. Policy configuration required". The rehearsal target has `held_by = 38`, carried over from migrated data (§48). WNG should confirm it.

## 20. Cash Counts

The existing record-and-review mechanism is unchanged (`review_cash_count`, Super Admin). The overview shows the latest count (system, counted, variance, reviewer) and states that a count posts nothing to the ledger. The **discrepancy GL treatment remains an accountant decision** (§50).

## 21. Float / Top-ups

Top-ups stay in the register (`create_top_up`: Super Admin, Admin, Accounts); their reads are now permission-gated (§4).

Known issue: `PettyCashBalance::isLow`/`isCritical` fall back to hard-coded **1000/500** when no threshold is set, and the old register and float tile still show that label. The new overview does not use the fallback; it shows "policy configuration required". See §51.

## 22. Outstanding Advances

Outstanding advances are statuses `disbursed`, `received`, `surrender_pending` and `surrender_returned`, grouped by `surrenderState`.

`surrender_due_days` is not configured, so **nothing is overdue**, and the screen says so rather than inventing a deadline. The filter `state=outstanding` lists them.

## 23. Project vs Overhead

`classification` is `project` when the requisition carries `enquiry_id` or `project_id`, otherwise `overhead`. It can be filtered and is shown on every row and on the detail ("Project cost on surrender" versus "Overhead").

Source rehearsal: 20 project, 2 overhead.

## 24. Petty-Cash Accounting Invariants

| Invariant | Proof |
|---|---|
| A disbursement is a staff advance, **not** an expense | `test_a_disbursement_is_a_staff_advance_not_a_project_cost` (0 ACTUAL lines; Staff Advances debited 2,000) |
| A surrender recognises the cost **once**, through its own clearing journal | `Stab7PettyCashTriplePostingTest` (unchanged, passing) |
| A GL failure keeps the disbursement; the retry is idempotent | §13 test |
| No reset | §5 test |

## 25. Existing Inventory Architecture

- **Stores owns every movement:** receipts (`InventoryService`), issues and returns (`StockMovementPoster`), counts (`StockCountController`), write-offs (`defective`) and stock-setting recounts.
- **Finance postings:**
  - `ProcurementCostProducer`: GRN accruals;
  - `StoresCostProducer`: issues and returns, with the `StoresFinancePosting` outbox;
  - `StockMovementPostingService`: approved stock counts, `JE-STK-*`.

## 26. Finance Inventory Boundary

Finance **reads**. No receiving, issuing, transfer or adjustment form was added to Finance.

`InventoryFinanceController` is gated by `finance.reports.view`, a Finance report permission. Stores staff do not see it, and a Stores permission does not grant it (tested). Identities appear only as `{id, name}` or code and name, with no emails (tested).

## 27. Materials Accounting Chain

- PO → COMMITTED
- GRN → ACCRUED (Dr Inventory / Cr Accrued)
- Stores issue → ACTUAL (Dr WIP or COS by expense code / Cr Inventory), which retires the matching accrual
- Bill → settles the accrual to AP, **no new cost**
- Payment → **no cost**
- Direct bill → ACTUAL once

The full evidence table is in 34A §5.

## 28. Report 34 Correction

`34A_PROJECT_COSTING_MATERIALS_CHAIN_CORRECTION.md` was created. Report 34 is left unchanged as the historical record.

34A states that §6 step 4 ("bill verification converts ACCRUED to ACTUAL") is wrong for PO-backed materials, gives the correct chain and the direct-bill exception, and cites 12 tests.

## 29. Service / Non-Stock Edge Case

A GRN line with no catalogue material produces an ACCRUED line **without** `library_material_id`. No issue can retire it, so it stays in the Inventory account indefinitely.

The position endpoint now separates:

- **stock receipts** (with a material): `received_not_issued.stock`;
- **service/non-stock receipts**: `received_not_issued.non_stock`, and the contributor `non_stock_receipts`.

Tested (`test_a_non_stock_receipt_is_shown_as_a_named_contributor_to_the_difference`).

**The treatment is not decided here.** It is a WNG/accountant decision, tied to W2-8. In the rehearsal target, **all** GRN accruals (2 lines, KES 13,000) are of this kind.

## 30. Inventory Position

`GET finance/inventory/position` returns:

- valuation (method, catalogue methods, total, stocked materials);
- reconciliation;
- received-not-issued (stock and non-stock);
- project issues (count, net value);
- unposted adjustments;
- whether opening inventory is approved;
- the finance-sync backlog;
- the WIP policy;
- the adjustment account.

## 31. Inventory Valuation

The method is a **moving weighted average**: every priced receipt re-averages `unit_cost`, and stock on hand is valued at that average. Boards are valued individually (`current_value` while Available or in Quarantine).

The calculation was extracted into `InventoryValuationService`, which **both** the Stores summary and the Finance position now use. The two cannot disagree, and a test asserts that they are equal.

All 438 catalogue materials record `valuation_method = Weighted Average`, in both dev and rehearsal.

## 32. GL Reconciliation

The screen shows three figures:

- **Stores valuation**
- **Inventory ledger**: the debit balance of posted lines on `ChartAccountMap::local('1200')`, which is `IA-001` under the WNG profile
- **Difference**: Stores minus ledger

It never hides a difference. It lists the known reasons with their size:

- write-offs and balances set outside a stock count (no posting rule);
- service/non-stock GRN lines;
- the Stores → Finance posting backlog;
- **no approved opening inventory**. This was added after real-data validation, where it explained most of the gap.

Tests cover a reconciled case (difference 0.00) and a non-stock case (−300.00, contributor named).

## 33. Project Material Issues

`GET finance/inventory/issues` returns, for each issue or return:

- project `{id, job_number, title}`;
- material `{id, name}`;
- quantity and value;
- the Stores reference;
- the **debit account** (the expense code's account: WIP under capitalise);
- whether it posted.

Filters: project, search and dates. Tested.

## 34. Inventory Adjustments

**Correction of my own earlier finding.** Earlier in this stream I recorded that "INV-001/6800 is referenced nowhere and adjustments never post". That is wrong for **approved stock counts**: `StockMovementPostingService` posts one `JE-STK-{count}` entry per count, to Inventory Adjustments (a cycle count) or Opening Balance Equity (opening inventory).

What does not post:

- `defective` write-offs;
- quantities set directly on stock settings ("Counted balance set to …").

Each adjustment row now shows:

- its **source** (stock count, opening inventory, stock setting, write-off);
- the quantity;
- its value at the current average;
- **posted / not posted**, with the `JE-STK` entry;
- the mapped account.

Only the unposted rows count toward the reconciliation contributor. Tested: a write-off shows "not posted" with account 6800; a count shows "posted" with `JE-STK-…`; the contributor counts 1.

## 35. WIP / COGS

`finance_accounts.wip_policy` (`FINANCE_WIP_POLICY`) is reported as configured, or as `null`. When it is `null`, the screen says the chart default applies and that Finance must confirm the policy. It is not assumed.

Each issue shows the account it actually debited. Tested for both the `null` and `capitalise` cases.

## 36. Overview Integration

The Finance Overview has a new **Ledger exceptions** block (`ledgerExceptionItems`, a pure function). It lists:

- failed petty-cash postings (with `view_reports`);
- overdue advances;
- a Stores-vs-ledger difference (with `finance.reports.view`; only when the account is mapped).

Each item links to the screen that explains it. Readers without either permission trigger no request. Tested.

## 37. My Actions

| Change | Detail |
|---|---|
| New item | `petty_cash_posting_failed`: a human retry is required |
| `fund_disbursement`, `fund_surrender_review` | Now exclude the viewer's own requisitions (separation of duties) |
| Unchanged | Approval, disbursement, surrender review, and the requester's own returned-surrender correction (`fund_surrender` includes `surrender_returned`) |

Inventory creates **no** actions, because data existing is not work.

## 38. Permissions

| Area | Permission | Holders (matrix) |
|---|---|---|
| Petty-cash register | `finance.petty_cash.view` | Super Admin, Admin, Manager, Accounts |
| Float | `view_balance` | Super Admin, Admin, Manager, Accounts |
| Workspace (all requisitions) | `view_reports` | Super Admin, Admin, Manager, Accounts |
| Approve / return | `edit_disbursement` (`reviewRequisition`) | Super Admin, Admin, Manager, Accounts |
| Disburse / reconcile | `create_disbursement` | Super Admin, Admin, Manager, Accounts |
| Reverse surrender | `finance.journals.reverse` | per matrix |
| Recalculate / custody / review count | `recalculate_balance` / `manage_custody` / `review_cash_count` | Super Admin |
| Inventory Finance | `finance.reports.view` | Super Admin, Accounts |
| Stores manage / review | `stores.manage` / `stores.review` | Super Admin, Manager, Stores / Super Admin, Manager |

## 39. Role-Name Audit

| Location | Role-name check | Security relevant? | Replacement |
|---|---|---|---|
| `ProcurementStoresController` (10 actions) | `Stores`/`Manager`/`Super Admin` | Yes: stock movements, settings, log deletion | `stores.manage` |
| `ProcurementStoresController::linkProjectMaterial` | `Manager`/`Super Admin` | Yes | `stores.review` |
| `ProcurementStoresController::materialDemandForecast` | `+ Procurement` | Yes (read) | `stores.manage` or `procurement.view` |
| `ProcurementStoresController` finance-sync / valuation / ledger (4) | Lists including **phantom** "Finance", "Finance Manager", "Accountant" | Yes: finance repair actions | `stores.manage` or `finance.reports.view` |
| `StockMovementRequest::authorize` | `Stores`/`Manager`/`Super Admin` | Yes: every stock movement | `stores.manage` |
| `StockCountController` (4) | `Stores`/`Manager`/`Super Admin`; `Manager`/`Super Admin` | Yes: counts post to the ledger | `stores.manage`; `stores.review` |
| `Stores/Dashboard.vue` | `hasRole` for log deletion | UI only (backend now gated) | `can('stores.manage')` |
| `RequisitionShow.vue` | `hasRole('Accounts')` | UI; granted buttons the routes refused | backend `actions` |
| `PettyCashPolicy::before` | `Super Admin` | No: redundant with `Gate::before` | Kept |
| `pettyCashStore` "Admin" labels | Display text | No | Kept |
| `PettyCashController` line 1027 | Excel template sample value | No | Kept |

Holders are identical to the role lists they replace, taken from the permission matrix. Stores test fixtures now receive their role's real grants (`GrantsMatrixPermissions`), as production does.

**Mutation checks** (each was reverted):

| Mutation | Test that caught it |
|---|---|
| `StockMovementRequest::authorize` → `true` | `test_only_stores_staff_may_post_a_movement` (expected 403, got 422) |
| Balance gate disabled | §4 |
| `RequisitionShow` balance condition removed | The frontend D-1 test |

## 40. Backend Changes

**New**

- `PettyCash/Support/PettyCashActions.php`
- `PettyCash/Controllers/PettyCashWorkspaceController.php`
- `Finance/Controllers/InventoryFinanceController.php`
- `ProcurementStores/Services/InventoryValuationService.php`
- `tests/Concerns/GrantsMatrixPermissions.php`

**Changed**

- `PettyCashController`, `PettyCashTopUpController`, `PettyCashRequisitionController`: gates (§4)
- `PettyCashRequisition::attachments()`
- `FinanceAttachment`: `$hidden = ['file_path']`
- `FinanceWorkQueueService`: new item, not-mine filters (§37)
- `ProcurementStoresController`, `StockMovementRequest`, `StockCountController`: permissions; the summary now uses the shared valuation
- `routes/api.php`: 6 petty-cash and 3 inventory read routes
- Test fixtures given matrix grants: `tests/Feature/Stores/{StockLedgerIntegrity,BoardLifecycle,StockMovementEndpoint,ProjectMaterialDemand,ProjectMaterialIssue,ResolveProjectMaterialCatalogue,OpeningInventory}Test`, `tests/Feature/MaterialsLibrary/CatalogueWorkflowIntegrityTest`

## 41. Frontend Changes

**New**

- `petty-cash/w3.ts`
- `views/finance/PettyCashOverviewView.vue`
- `views/finance/PettyCashRequisitionListView.vue`
- `components/RequisitionFinancePanel.vue`
- `inventory/w5.ts` and three views

**Changed**

- `RequisitionShow`: backend actions, D-3 banners, conditional float read, Finance panel, surrender drawer
- `RequisitionIndex`: D-3 label; reversal dialog
- `PettyCashIndex`, `pettyCashStore`, `pettyCashService`: reset removed
- `shared/status.ts`: vocabulary
- `navigation.ts`: two petty-cash pages and a new **Inventory** section
- `router/finance.ts`: five routes
- `useFinanceOverview` and `FinanceOverviewView`: ledger exceptions
- `stores/Dashboard.vue`: permission check

## 42. Backend Tests

`PettyCashWorkspaceTest` (11):

- the float and register require permissions, and recalculation is not Accounts';
- the overview hides the float without `view_balance`, and thresholds and custody are null;
- the reset route is absent;
- the voucher IDOR is closed;
- `received` → `funds_received`, and the `outstanding` filter works;
- separation of duties in the projection matches the route;
- the requester sees only their own detail;
- a disbursement is an advance, not a cost;
- a failed posting is visible and its retry is idempotent;
- only a reviewer may retry;
- evidence is safe.

`InventoryFinanceTest` (6):

- read permission;
- the valuation equals Stores and reconciles to the ledger;
- a non-stock receipt is a named contributor, and opening inventory is reported missing;
- a project issue is ACTUAL, with safe identities;
- adjustments show whether they posted;
- the WIP policy comes from configuration.

Existing Stab7, Wave3 controls, surrender, SettlementAccount, SupplierLedgerRail and Payables tests are unchanged and passing.

## 43. Frontend Tests

| File | Tests | What they cover |
|---|---|---|
| `w3.spec.ts` | 12 | D-3 vocabulary; overview retry with confirmation, declined, hidden; policy-required texts; float withheld; list filters and state; empty text; panel figures, duplicate override and evidence; retry only when allowed; refusal reason; reversal with a reason, and cancel; review opens the drawer |
| `w5.spec.ts` | 5 | Difference and contributors including opening inventory; WIP policy both ways; server refusal; issues row; adjustments posted or not posted |
| `requisitionShow.spec.ts` | 4 | No actions despite the Accounts role; the backend's actions shown; D-3 banner; D-1 conditional float read (mutation-checked) |
| `overview.spec.ts` | +3 | Ledger exception items; empty; reviewer sees the failure |
| `navigation.spec.ts` | +1 | New pages, permission-gated |

Suite: **275 PASS** (250 before).

## 44. API Contract

`route:list --json` (1,384 routes) was checked against every `api.*` call in `src/modules/finance`, with the `base`, `billUrl` and `requisitionUrl` helpers expanded: **176 direct call sites, 0 unmatched**.

The check now also covers the petty-cash service's `makeRequest` wrapper and `${this.baseUrl}` calls, which earlier reports did not count: **70 calls, 0 unmatched**. The removed `DELETE /clear-all` call no longer appears.

## 45. ENG-1

`vue-tsc --build --force` reports **256** diagnostics, the same count as the Report 60 baseline. **None are in a file changed by Stream D.** One error I introduced (`PettyCashOverviewView` retry parameter type) was found and fixed, taking the count from 257 back to 256.

Limitation: the saved baseline error list (`/tmp/claude-1000/tsc-head.txt`) had been removed by a temp-directory cleanup before this run, so I could not do a line-by-line comparison. The evidence is the equal count plus zero errors in any Stream D file. The current list is saved in the session scratchpad (`tsc-d2.txt`) for the next stream.

## 46. Build

`vite build` passes (28 s). Only the pre-existing chunk-size warning appears.

## 47. Full Regression

Run sequentially on `db_test`, with no overlap:

| Run | Result |
|---|---|
| `PettyCashWorkspaceTest` | 11 PASS |
| `InventoryFinanceTest` | 6 PASS (re-run after the §32 contributor was added) |
| Stores + `StockCountPostingTest` | 129 PASS |
| Backend full suite | **1,572 passed, 2 failed** (11,217 assertions, 676 s) |
| Re-run after the fixture fix: `CatalogueWorkflowIntegrityTest` + `InventoryFinanceTest` + `PettyCashWorkspaceTest` | **25 PASS** |

The 2 failures were `CatalogueWorkflowIntegrityTest` ("a draft cannot be received", single and batch). Its fixture gave a bare "Stores" role only library permissions and relied on the role *name* to receive stock. After §39 it got 403 before reaching the 422 business rule. It now receives the Stores role's real matrix grants (`GrantsMatrixPermissions`), as production does, and both pass. No production code changed for this.

**Final full-suite re-run (2026-09-29, before the Report 62 checkpoint commit):** `ddev exec php artisan test`, sequential, no competing run on `db_test`: **1,574 passed, 0 failed, 11,222 assertions, 670.8 s, exit 0**. This is the result of record for Stream D.

## 48. Real-Data Validation

**Performed, read-only (`SELECT` only).** Sources were `wng_source_rehearsal` (a copy of the live source) and `wng_target_rehearsal` (the migrated rehearsal target). Neither was modified.

**Petty cash**

| Finding | Detail |
|---|---|
| Source volume | 22 requisitions: pending 9, approved 3, disbursed 2, **received 8 (KES 11,329)** |
| D-3 impact | Those 8 showed as "Complete"; they are now "Awaiting surrender" |
| Outstanding advances | 10 (disbursed + received) |
| Classification | 20 project, 2 overhead |
| Target | 1 requisition (reconciled); no advance or cost posting failures |
| Settings | Low/critical thresholds and surrender deadline are `null`, so the screens show "policy configuration required". `petty_cash_max_per_transaction = 20000` exists (a per-transaction limit, not an advance ceiling) |
| Custody | `held_by = 38`, carried over from migrated data. R-2 asks WNG to confirm it |

**Inventory (target)**

| Figure | Value |
|---|---|
| Stocked materials | 33, all bulk |
| Stores valuation | **KES 10,750** |
| Inventory ledger (`IA-001`) | **KES 12,250** (13,000 accrued less a 750 issue) |
| Difference | **−1,500** |
| Of the ledger: service/non-stock GRN accruals | **13,000**, 2 lines, one already billed (§29) |
| Unposted stock-setting adjustments | 3 logs; value 300 at current average |
| Approved opening inventory counts | **none**. Excluding the non-stock 13,000, the ledger's stock-backed balance is −750: the issue relieved stock that was never entered. The 10,750 on the shelf has no ledger entry at all |
| Stores → Finance posting backlog | 0 |

The "no opening inventory" contributor was added because of this validation. **Creating the opening balance is out of scope and was not done**; it belongs to the approved opening-inventory count workflow.

**Source inventory:** stock is valued at 0.00, because no unit costs were set before migration.

## 49. Visual Validation

**Not performed.** No safe browser tooling is configured, and the only frontend `.env` targets production, which the brief forbids. Screens are covered by component tests (§43) and the build.

## 50. Business Policy Register

| Decision | Technical State | Policy State | Blocking? |
|---|---|---|---|
| Petty-cash custodian R-2 | `held_by`, handovers and `manage_custody` exist; the overview shows the holder or "not configured" | Awaiting WNG (rehearsal carries `held_by = 38` from migration) | No |
| Petty-cash float threshold | Settings exist, null; overview shows "configuration required"; the model's 1000/500 fallback is still used by the old register (§51) | Awaiting WNG | No |
| Surrender deadline | `petty_cash_surrender_due_days` exists, null; nothing is overdue | Awaiting WNG | No |
| Employee advance ceiling | Only `petty_cash_max_per_transaction` (20,000); no per-employee ceiling | Awaiting WNG | No |
| Evidence matrix/threshold | Attachments exist; `petty_cash_evidence_threshold` null; not enforced | Awaiting WNG/Finance | No |
| Cash-count discrepancy GL | Count recorded and reviewed; no posting | Awaiting accountant | No |
| Salary advance GL | Payout via `PaymentSettlementService`; no journal | Awaiting accountant | No |
| Service/non-stock receipt treatment | Identified and reported separately (§29) | Awaiting WNG/accountant (W2-8) | No |
| Write-off / stock-setting adjustment posting | Shown as "not posted", with a mapped account | Awaiting accountant | No |
| WIP policy | Reported from configuration; null means chart default | Awaiting Finance confirmation | No |
| Opening inventory | Approved-count workflow exists; none approved in rehearsal | Awaiting WNG count | No (for this stream) |

## 51. Known Issues

1. **`PettyCashBalance::isLow`/`isCritical` fall back to 1000/500** until Finance approves a value. This is a deliberate earlier rule, pinned by `Wave3PettyCashControlsTest` ("Legacy default 1,000 applies until Finance approves a value"). It drives a warning label on the old register and the Finance Overview float tile and gates nothing. The new overview does not use it. Removing it is a WNG decision, for Stream E or later.
2. **Two `window.prompt` calls remain in the requisition screens:** self-approval reason and budget-exception reason. They are pre-existing, not reversals, and still work. They should move to the shared dialog.
3. **Surrender item `receipt_path`** is still a free-text field with no upload. Evidence now attaches at requisition level instead.
4. **The ENG-1 comparison was by count**, not line by line (§45).
5. **The service/non-stock accrual policy** is open (§29).

## 52. Files Changed

Backend: §40, plus `docs/finance-redesign/phase-2/34A_…md` and this report. Frontend: §41. All changes are uncommitted and nothing was pushed.

## 53. Completion Matrix

| Capability | Status | Evidence |
|---|---|---|
| Float/balance access control | COMPLETE | §4; mutation-checked |
| Dead reset removed | COMPLETE | §5; route-absent test |
| Correct petty-cash statuses | COMPLETE | §6; backend and frontend tests |
| Requisition | COMPLETE | §10; list and projection tests |
| Approval | PRESERVED THROUGH OPERATIONAL UI | §11; now backend-gated |
| Disbursement | PRESERVED THROUGH OPERATIONAL UI | §12 |
| GL failure visibility | COMPLETE | §13 |
| Idempotent retry | COMPLETE | §13 test |
| Surrender | PRESERVED THROUGH OPERATIONAL UI | §14; drawer opened from the detail |
| Return for Correction | PRESERVED THROUGH OPERATIONAL UI | §15 |
| Reversal | COMPLETE | §16; confirmation and reason tested |
| Duplicate receipt | COMPLETE | §17 |
| Evidence | COMPLETE (threshold BLOCKED BY BUSINESS POLICY) | §18 |
| Custody mechanism | BLOCKED BY BUSINESS POLICY (mechanism complete) | §19 |
| Cash count | PRESERVED THROUGH OPERATIONAL UI (GL treatment BLOCKED BY BUSINESS POLICY) | §20 |
| Outstanding advances | COMPLETE (deadline BLOCKED BY BUSINESS POLICY) | §22 |
| Advance → no Project cost | COMPLETE | §24 test |
| Surrender → one cost | COMPLETE | Stab7 tests |
| Direct cash purchase | PRESERVED THROUGH OPERATIONAL UI | `PettyCashCostPoster`; `Wave3PettyCashControlsTest` |
| Inventory Finance read | COMPLETE | §26 |
| Inventory valuation | COMPLETE | §31; shared service |
| Inventory GL reconciliation | COMPLETE | §32 |
| GRN → Accrued | COMPLETE | 34A §5 tests |
| Stores issue → Actual | COMPLETE | 34A §5; §33 |
| Bill → no duplicate material cost | COMPLETE | 34A §5 |
| Payment → no cost | COMPLETE | 34A §5 |
| Inventory adjustment | COMPLETE (write-off posting BLOCKED BY BUSINESS POLICY) | §34 |
| WIP/COS relationship | COMPLETE (policy confirmation BLOCKED BY BUSINESS POLICY) | §35 |
| My Actions | COMPLETE | §37 |
| Overview | COMPLETE | §36 |
| Role-name controls | COMPLETE | §39 |

## 54. Exact Next Stream

### STREAM E — W4 SPEND VOUCHERS

Carry these into it:

- the §51 items;
- the threshold fallback decision;
- the two remaining `window.prompt` calls;
- a fresh ENG-1 baseline list saved somewhere durable, not `/tmp`.

Stream E was not started.

## 55. Final Verdict

### STREAM D COMPLETE — W3/W5 REDESIGN READY FOR STREAM E

Every technical mechanism is in place and tested. What remains open is business policy (§50) or pre-existing legacy behaviour (§51):

- each open policy is shown as "policy configuration required", or reported and never assumed;
- no financial control is bypassed;
- no policy value was invented;
- production is untouched.
