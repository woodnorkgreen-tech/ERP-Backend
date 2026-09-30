# 58 — Finance Frontend Stream B: W1 Sales & Receivables Implementation

**Date:** 2026-09-29  
**Environment:** local development / DDEV only  
**Production:** untouched  
**Verdict:** **STREAM B PARTIAL — FUNCTIONAL WITH DOCUMENTED LEGACY W1 DEPENDENCIES**

---

## 1. Executive Summary

Stream B now provides a coherent cross-project Sales & receivables workspace:

> approved commercial basis / Project → invoice preparation → independent checking → issue → receivable → receipt recording → independent verification → allocation → settlement

The backend remains authoritative. A new read-only, paginated `GET /api/finance/invoices` projection and companion invoice-detail / receipt-list projections expose backend totals, states, balances, audit facts and per-user action eligibility. Existing per-project write routes remain authoritative for every transition and posting.

The frontend adds `/finance/invoices`, `/finance/invoices/:id`, and `/finance/receipts`, using the Stream A frame, navigation, status, money, state and error facilities. My Actions links now land on these authoritative W1 screens.

The redesigned pages cover the primary invoice/receipt lifecycle, including credit notes and payment terms. The legacy `EnquiryFinanceModal` is deliberately preserved because the project-specific quote waiver/no-quote exception, split-receipt, production-deposit release, and some correction/configuration flows still belong to Project billing. No live capability was deleted.

---

## 2. Scope and Safety

- Work was confined to the local frontend and backend repositories.
- Backend tests ran through DDEV against `db_test`.
- No production database, migration, journal, queue worker, deployment or configuration was touched.
- No Stream C/W2 implementation was started.
- Frontend development configuration uses `VITE_API_BASE_URL=/system`; Vite resolves the proxy to the local DDEV backend (`http://erp-backend.ddev.site`). `.env.production` remains separate and was not used.
- No browser/dev server was started.

---

## 3. Pre-Implementation W1 Architecture

Before this stream, W1 was principally exposed through the 1,626-line `EnquiryFinanceModal` inside `ProjectReceivablesIndex`. The backend already had authoritative per-project routes for invoice creation/correction/check/return/issue/void, credit notes, receipt record/verify/reverse and allocation, plus posting, ageing and client-position services.

The architectural gap was discoverability and projection: Finance could not see a paginated cross-project invoice book, and My Actions linked back to a large project modal. The modal also mixed project commercial controls, production release, invoices, receipts, allocations, credit notes and payment terms.

---

## 4. Backend Changes

- Added `ReceivablesController`, a read-only W1 projection controller.
- Added `InvoiceState`, the single mapping from existing invoice fields and allocation totals to user-facing workflow/payment states.
- Added `ReceivablesActions`, which describes the current user's permitted next actions using the same preconditions as the authoritative controllers.
- Added three read routes: invoice index, invoice detail and receipt index.
- Kept every mutation on the existing `/api/projects/enquiries/{enquiry}/...` routes.
- Corrected My Actions target URLs for invoice review/issue/correction and receipt verification.
- Reused the existing model scopes/services for net totals, verified allocations, balances, ageing and posting.
- No migration or accounting-rule change was introduced.

---

## 5. Cross-Project Invoice API

`GET /api/finance/invoices` requires `finance.receivables.read` in the controller, independently of navigation.

It is paginated (10–100 rows, default 25) and filters server-side by:

- client;
- Project/enquiry;
- Project Officer (including the existing assigned-PO fallback);
- workflow/payment state;
- overdue status;
- invoice-date range;
- due-date range;
- invoice number, Project reference/title or client search;
- optional credit-note inclusion.

Rows return allow-listed project/client/person objects; no full User or Employee object is serialized. The response includes authoritative original/net totals, verified paid amount, balance, review state, payment state, due/overdue facts, control actors/timestamps, per-action eligibility and next action.

`GET /api/finance/invoices/{invoice}` adds invoice lines/tax/payment term, commercial basis, quote exception, allocations, applicable verified receipts, credit notes, project position and real governance audit entries.

---

## 6. Invoice Status Model

There is no second state machine. `InvoiceState` derives two independent views:

| Existing facts | Document/review state | Payment state |
|---|---|---|
| draft, never returned/resubmitted | Draft | — |
| draft, latest event is return | Returned | — |
| draft, corrected/resubmitted | Awaiting review | — |
| draft with `checked_at` | Checked | — |
| issued/paid backend status | Issued | Unpaid / Partially paid / Paid from verified allocations and net total |
| void | Void | — |

Credit-note rows are identified separately. The legacy combined `review_state` is retained for compatibility, while `document_state` and `payment_state` prevent review and settlement from being conflated.

Overdue days are emitted only for issued/paid, non-credit invoices with a positive authoritative balance and a past due date. Settled invoices therefore never appear overdue.

---

## 7. Invoice List

`/finance/invoices` is the primary cross-project workspace. It uses `FinancePageFrame`, `MoneyValue`, `StatusChip`, `FinanceState` and `financeErrorMessage`.

The responsive table prioritizes invoice/client, Project/Project Officer, dates, amount/received/balance, separate review/payment chips, and the server-provided next action. Secondary facts are combined within cells to avoid an excessively wide table.

---

## 8. Invoice Detail

`/finance/invoices/:id` presents:

- commercial basis, client, Project and Project Officer;
- invoice date/due date/payment term, lines, VAT, net and total;
- original amount, credit-note-adjusted amount, applied amount and balance;
- preparer/return/resubmission/check/issue/void actors and timestamps;
- return/void reasons;
- applied receipts and allocator/time;
- applicable verified client money for allocation;
- backend governance audit entries only;
- links to Project receipts and Project billing controls.

---

## 9. Invoice Actions

The detail screen only renders actions whose backend projection reports `allowed: true`. It supports correct/resubmit, check, return for correction, issue, void, allocation, raise credit note and independent credit-note issue.

The backend still rejects every invalid/duplicate transition. Issue, void, allocation where financial position changes, receipt verification/reversal, and credit-note issue use deliberate confirmations; ordinary navigation does not.

---

## 10. Invoice Maker/Checker

The preparer cannot check their own invoice. A returned invoice is editable only by its preparer, and correction resubmits it for independent checking. A checked invoice may be issued under the existing backend rule. The action projection, detail screen and My Actions share these outcomes.

The focused backend lifecycle test proves preparer/checker/return/preparer/resubmit/check/issue, including direct rejection of forbidden transitions.

---

## 11. Receipt Workflow

The implemented sequence is:

1. A permitted user records money against a Project and a configured receiving source.
2. The receipt remains `pending`; it is not yet treated as verified client money.
3. A different permitted user verifies it (unless the explicit self-approval facility authorizes the exception).
4. Verification posts/recognizes the receipt according to the existing backend accounting treatment; unapplied money is held for the client.
5. A permitted user applies verified money to an issued invoice.
6. Backend allocation data updates applied/unapplied receipt values and the invoice balance.

This sequence reflects existing backend behavior; accounting order was not redesigned for the UI.

---

## 12. Receipt Verification

`/finance/receipts` exposes pending/verified/reversed states, recorder/verifier/reverser and timestamps, source/method, project/client context, allocations, applied amount and unallocated amount. The Verify action is shown only when the backend says it is allowed and uses explicit confirmation.

---

## 13. Receipt Allocation

Invoice detail lists verified, unreversed receipts with money still available for the same Project. Applying one shows the receipt amount available, amount applied, target invoice and resulting backend balance after refresh. No financial truth is recalculated in Vue.

---

## 14. Client Deposits

The receipt list labels verified but unallocated money as money held for the client. Pending receipts show no available client money, and reversed receipts show none. The summary reports total unapplied client money from the backend. It is not presented as revenue.

---

## 15. M-Pesa Behaviour

Receipt methods are configuration-aware. Mobile-money choices are populated only from active `mobile_money` payment sources linked to a ledger account. With none available, the form shows exactly:

> **M-Pesa receiving account has not yet been configured by Finance.**

The selector is not rendered in that state. No bank is hard-coded. The existing backend also rejects an inactive/unlinked M-Pesa source.

---

## 16. Company Card Finding

`CARD`/company-card sources are excluded from client receipt methods. The frontend offers bank transfer, cheque, M-Pesa and cash, and filters sources by their existing receiving semantics. Company cards remain outgoing-spend instruments; no accounting decision was added.

---

## 17. Receivables / Balance Logic

The displayed relationship is the backend's existing one:

> net invoice total − valid verified unreversed allocations = outstanding balance

`ProjectInvoice::withVerifiedPaidAmount()`, `withNetTotal()`, the allocation service and ageing service remain the source of truth. A backend test compares the invoice projection balance directly to receivables ageing.

---

## 18. Overdue Handling

The projection emits `days_overdue`; Vue only presents it. Due dates alone do not make a settled, draft, void or credit-note record overdue.

---

## 19. Overview Integration

Finance Overview continues to consume Stream A data. Its Sales & receivables navigation now reaches Invoices and Receipts. Existing overview counts are unchanged because no queue counting logic was duplicated.

---

## 20. My Actions Integration

- Invoice check/issue/correction items link to `/finance/invoices/{id}`.
- Receipt verification items link to `/finance/receipts?receipt_id={id}`.
- My Actions remains navigation only; it performs no approval/check/verify mutation.
- Backend and frontend integration tests assert these destinations.

---

## 21. Project Context

Projects provide an `Invoices & receipts` action that opens `/finance/invoices?enquiry_id={id}`. Project billing rows also link to the same filter. The Project billing deep link can locate a Project beyond page one by its reference, preventing the old modal from becoming a second invoice implementation.

---

## 22. Client Context

Invoice detail links the client back to a client-filtered invoice book. Invoice and receipt lists carry client identity and allow server-side client filtering. This is navigation over existing client data, not a CRM redesign.

---

## 23. Credit Notes

Credit notes are functional and use the existing create/issue routes plus `finance.receivables.reverse`. The redesigned invoice detail supports raising a draft credit note and independent issue, displays its state/amount/actors, and uses the existing posting service.

A known pre-existing accounting/read-model behavior is documented: a non-void draft credit note already reduces `withNetTotal()` and therefore balance/ageing/allocation capacity before its ledger reversal is issued. Stream B reports this backend truth and does not conceal or redesign it.

---

## 24. Payment Terms

The invoice editor loads active payment terms from the existing Finance endpoint. Selecting one proposes the due date; a custom due date remains possible. Project-specific receivables terms and configuration remain accessible through Project billing. Nothing was removed from the legacy flow.

---

## 25. Legacy `EnquiryFinanceModal` Parity

Parity checklist:

| Capability | Redesigned workspace | Legacy boundary |
|---|---|---|
| Cross-project invoice/read position | Yes | — |
| Prepare/correct/check/return/issue/void | Yes | Preserved as fallback |
| Receipt record/verify/reverse | Yes | Preserved as fallback |
| Allocation | Yes | Split/project-specific allocation preserved |
| Credit notes | Yes | Preserved as fallback |
| Invoice payment term selection | Yes | Project receivables terms remain |
| Quote waiver/no-quote exception | Linked context | Remains in Project billing |
| Split receipt across projects | Linked context | Remains in Project billing |
| Production deposit release/override | Linked context | Remains in Project billing |

Because the final four project-specific capabilities are not duplicated into the new cross-project screens, the modal has not been retired.

---

## 26. Legacy Code Removed / Preserved

No legacy W1 code was deleted. `EnquiryFinanceModal.vue` remains 1,626 lines and has one live consumer in `ProjectReceivablesIndex.vue`. Removing it now would lose legitimate project-context functionality. New work is isolated in the W1 client, editor/recorder and three workspace views; shared Stream A facilities are reused.

---

## 27. Permissions

- All three read projections enforce `finance.receivables.read` server-side.
- Existing route middleware continues to enforce each mutation permission.
- Action visibility comes from backend per-user eligibility, not role-name conditionals.
- Tests prove read denial, invoice maker/checker, receipt maker/checker, self-approval behavior, and action-specific permissions.

---

## 28. Auditability

Allow-listed identity projections contain only `id` and `name`. Invoice detail includes the real control fields and governance logs; receipt rows include recorded/verified/reversed actors, timestamps and reversal reason. Tests assert salary, password, email, employee identifier and tax PIN are absent.

---

## 29. Backend Tests

- Dedicated W1 + work queue: **21 tests, 277 assertions, PASS**.
- Complete Finance feature suite: **457 tests, 2,682 assertions, PASS**.
- Full backend suite: **1,529 tests, 10,706 assertions, PASS** (574.89 seconds).

Coverage includes read permission, pagination, every requested invoice filter, authoritative totals/balance, safe identity projection, invoice maker/checker/return/resubmit/issue, receipt record/independent verify/allocation/reversal, disabled/unlinked M-Pesa, credit notes, work-queue links and ageing agreement.

---

## 30. Frontend Tests

The new `w1.spec.ts` contains 19 focused tests for list rendering, URL filters, pagination, empty/error states, permissions, detail/audit, maker/checker action visibility, issue/void confirmation, allocation, receipt verify/reverse/allocation display, recorder, M-Pesa and Card behavior.

Complete frontend suite: **30 files, 213 tests, PASS**.

---

## 31. API Contract

The current Laravel `route:list --json` was compared to all endpoint shapes introduced by the new W1 client/editor/recorder:

- **19/19 Stream B endpoint shapes matched**;
- **0 unmatched live Finance API calls introduced**;
- `GET /api/finance/invoices` and `GET /api/finance/invoices/{invoice}` match their consumers;
- `GET /api/finance/receipts` matches its consumer;
- existing per-project mutation routes remain live.

---

## 32. ENG-1

`npx vue-tsc --build --force --pretty false` reports exactly **256 diagnostics**, equal to the pre-existing baseline.

- New W1 files: **0 diagnostics**.
- Stream B Finance/navigation/router files: **0 diagnostics**.
- One diagnostic appears in touched `ProjectsEnquiries.vue:1153`, but that line is outside the Stream B diff and is pre-existing.

Result: **0 new TypeScript errors**.

---

## 33. Frontend Build

`npm run build`: **PASS** — 1,911 modules transformed. Existing unrelated esbuild/chunk warnings remain; no W1 build error occurred.

---

## 34. Backend Full Regression

`ddev exec php artisan test`: **1,529 tests, 10,706 assertions, PASS** in 574.89 seconds. This is the 1,521-test baseline plus the eight new W1 tests. The run was sequential and isolated; it was not overlapped with any other backend suite. Repeated missing-role seeder warnings and PHP/Guzzle deprecation notices remain environmental/pre-existing and did not cause a failure.

---

## 35. Rehearsal Validation

No rehearsal mutation/smoke pipeline was run. The available rehearsal script performs a broader data rehearsal rather than a read-only W1 structural check, and running it was unnecessary and disproportionate for these projection/UI changes. Structural behavior was validated against migrated local schemas and the isolated DDEV test database without printing client-sensitive data. Production remained untouched.

---

## 36. Visual Evidence / Limitation

The development API target was explicitly verified as local DDEV before any possible visual work. Browser automation/screenshots were not attempted: route, component, build and API evidence is complete, and no risky dependency was installed merely for screenshots.

---

## 37. Known Issues

1. The legacy project modal remains required for split receipts, quote/no-quote controls, project receivables terms and production-deposit release/override.
2. Existing `withNetTotal()` behavior counts a non-void draft credit note before issue; read models and ageing therefore reduce the balance before the ledger reversal posts.
3. M-Pesa remains business-configuration-blocked until Finance activates and links a mobile-money payment source.
4. ENG-1 still contains the established 256 repository-wide diagnostics, none introduced by W1.
5. Visual browser evidence and real-data rehearsal evidence were not produced for this stream.

---

## 38. Files Changed

### Backend

- `app/Modules/Finance/Controllers/ReceivablesController.php`
- `app/Modules/Finance/Support/InvoiceState.php`
- `app/Modules/Finance/Support/ReceivablesActions.php`
- `app/Modules/Finance/Services/FinanceWorkQueueService.php`
- `app/Modules/Projects/Http/Controllers/EnquiryController.php`
- `routes/api.php`
- `tests/Feature/Finance/ReceivablesWorkspaceTest.php`
- `tests/Feature/Finance/FinanceWorkQueueTest.php`
- this report

### Frontend

- `src/modules/finance/receivables/w1.ts`
- `src/modules/finance/receivables/w1.spec.ts`
- `src/modules/finance/receivables/components/InvoiceEditor.vue`
- `src/modules/finance/receivables/components/ReceiptRecorder.vue`
- `src/modules/finance/receivables/views/InvoiceListView.vue`
- `src/modules/finance/receivables/views/InvoiceDetailView.vue`
- `src/modules/finance/receivables/views/ReceiptListView.vue`
- `src/modules/finance/receivables/views/ProjectReceivablesIndex.vue`
- `src/modules/finance/navigation.ts` and its test
- `src/modules/finance/shared/status.ts`
- `src/modules/finance/overview/overview.spec.ts`
- `src/modules/projects/views/ProjectsEnquiries.vue`
- `src/router/finance.ts`

The unrelated Accounts Payable document files already present in the backend worktree were not modified.

---

## 39. Stream B Completion Matrix

| Capability | Status | Evidence |
|---|---|---|
| Cross-project invoice list | COMPLETE | Paginated read projection + list tests |
| Invoice filtering | COMPLETE | Server filters + URL-driven frontend tests |
| Invoice detail | COMPLETE | Lines/tax/control/receipt/audit projection and tests |
| Invoice preparation | COMPLETE | New editor; existing authoritative create route |
| Independent checking | COMPLETE | Backend eligibility + lifecycle/UI tests |
| Return/correction | COMPLETE | Reason, preparer-only correction, resubmit tests |
| Invoice issue | COMPLETE | Checked-state gate + confirmation + tests |
| Invoice void/reversal | COMPLETE | Backend eligibility + reason/confirmation; allocation/credit guards |
| Receipts | COMPLETE | Dedicated list/recorder and authoritative projection |
| Independent receipt verification | COMPLETE | Recorder exclusion + explicit self-approval support + tests |
| Allocation | COMPLETE | Verified receipt availability and backend result presentation |
| Client deposits | COMPLETE | Verified unapplied money explicitly shown as held for client |
| Credit notes | COMPLETE | Raise, independent issue, display and existing posting tests |
| Payment terms | COMPLETE | Active-term selection and project terms link preserved |
| M-Pesa configuration-aware UX | BLOCKED BY BUSINESS CONFIGURATION | Exact unconfigured message; no empty selector; backend rejects inactive source |
| Receivable balances | COMPLETE | Shared scopes/services; direct ageing agreement test |
| Project links | COMPLETE | Projects and project billing link to filtered invoice workspace |
| My Actions links | COMPLETE | Backend and frontend link tests |
| Legacy modal parity | PRESERVED THROUGH LEGACY UI | One live consumer intentionally retained for project-specific controls |

---

## 40. Exact Next Stream

Do not start Stream C until the documented legacy W1 dependencies are either accepted as the long-term Project billing boundary or extracted into focused project-context components and parity is revalidated. Once that boundary is formally closed, the exact next stream is:

### STREAM C — W2 PURCHASING & PAYABLES

That stream must connect Purchase Requisition → Purchase Order → GRN → Supplier Bill → WHT → Supplier Payment and address the known supplier-bill verification permission issue. No Stream C work is included here.

---

## 41. Final Verdict

### STREAM B PARTIAL — FUNCTIONAL WITH DOCUMENTED LEGACY W1 DEPENDENCIES

The cross-project invoice and receipt workspace is functional, permission-safe, maker/checker-aware, configuration-aware and regression-tested. The verdict is PARTIAL solely because live project-specific W1 capabilities remain intentionally dependent on the preserved legacy modal and its Project billing context; no functionality was lost.
