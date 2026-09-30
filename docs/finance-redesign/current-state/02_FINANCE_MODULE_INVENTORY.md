# 02 — Finance Module Inventory

Part of the WNG ERP Finance & Accounts Phase 1 audit. Read-only discovery pass, dated 2026-09-22.
Repos: `ERP-Backend` (Laravel), `ERP-Frontend` (Vue 3/TS). See `01_FINANCE_EXECUTIVE_SUMMARY.md` for
the synthesized findings; this file is the raw inventory the rest of the audit is built on.

Legend: **FACT** (verified in code) · **ISSUE** · **RECOMMENDATION** · **ASSUMPTION** ·
**REQUIRES WNG CONFIRMATION**.

---

## 1.1 Cost Collector (capture, verify, budget-vs-actual)

| Component | Type | File/Location | Purpose | Dependencies | Status | Notes |
|---|---|---|---|---|---|---|
| CostLineController | Backend Controller | `app/Modules/Finance/CostCollector/Http/Controllers/CostLineController.php` | Create/list/correct project cost lines; `myProjects`, `budgetLines`, `costCauses` lookups | CostCollectorService, CostLine model | Active | Routes at `routes/api.php:174-180` |
| CostEvidenceController | Backend Controller | `.../CostCollector/Http/Controllers/CostEvidenceController.php` | Upload receipt/tax evidence attached to a cost line | CostCollectorService | Active | Route `routes/api.php:172` |
| CostVerificationController | Backend Controller | `.../CostCollector/Http/Controllers/CostVerificationController.php` | Finance review queue: verify/query/reject/reverse/resubmit/reclassify | CostVerificationService, CostTaxPricer | Active | Routes `routes/api.php:192-206` |
| CostAccountController | Backend Controller | `.../CostCollector/Http/Controllers/CostAccountController.php` | Budget-vs-actual per project/portfolio, category drill-down | CostAccountService | Active | Routes `routes/api.php:185-187` |
| ExpenseCodeController | Backend Controller | `.../CostCollector/Http/Controllers/ExpenseCodeController.php` | Expense code catalogue, families, recent-used | ExpenseCode model | Active | Routes `routes/api.php:161-168` |
| CostCollectorService | Backend Service | `.../CostCollector/Services/CostCollectorService.php` | Core cost-line create/correct orchestration | CostContextResolver, MaterialExpenseCodeResolver | Active | |
| CostVerificationService | Backend Service | `.../CostCollector/Services/CostVerificationService.php` | Verify/reject/reverse/reclassify state machine | CostTaxPricer, CostNotifier | Active | |
| CostAccountService | Backend Service | `.../CostCollector/Services/CostAccountService.php` | Aggregates budget vs actual per account/category; `marginAgainstJournals()` computes live project margin | BudgetProjector | Active | |
| BudgetProjector | Backend Service | `.../CostCollector/Services/BudgetProjector.php` | Projects committed+planned+actual into one allocation line | CostLine | Active | |
| CostContextResolver | Backend Service | `.../CostCollector/Services/CostContextResolver.php` | Resolves project/enquiry/job-number context for a cost | ProjectEnquiry | Active | Historical job_number vs Projects-PK identity defect fixed here |
| CostNotifier | Backend Service | `.../CostCollector/Services/CostNotifier.php` | Notifies submitter/verifier on state changes | Notifications module | Active | |
| CostQueueQuery | Backend Service | `.../CostCollector/Services/CostQueueQuery.php` | Builds the cost-verification queue query | CostLine | Active | |
| CostTaxPricer | Backend Service | `.../CostCollector/Services/CostTaxPricer.php` | VAT/WHT pricing preview at verification time; shared with commit so preview=commit | TaxResolver | Active | |
| MaterialExpenseCodeResolver | Backend Service | `.../CostCollector/Services/MaterialExpenseCodeResolver.php` | Maps materials to an expense code | ExpenseCode | Active | |
| PettyCashCostProducer | Backend Service | `.../CostCollector/Services/PettyCashCostProducer.php` | Turns a petty-cash disbursement into a cost-collector CostLine | PettyCash module | Active | One of 3 "cost producers" feeding the same ledger |
| ProcurementCostProducer | Backend Service | `.../CostCollector/Services/ProcurementCostProducer.php` | Turns a procurement bill into a CostLine | ProcurementStores Bill | Active | |
| StoresCostProducer | Backend Service | `.../CostCollector/Services/StoresCostProducer.php` | Turns a stock issue/movement into a CostLine | ProcurementStores StockMovement | Active | Historically mis-attributed job numbers; repaired via console command |
| UnbudgetedSpendAdopter | Backend Service | `.../CostCollector/Services/UnbudgetedSpendAdopter.php` | Adopts spend with no matching budget line into the budget | BudgetProjector | Active | |
| CollectsCost / CostContext / PlannedLine | Backend Contracts | `.../CostCollector/Contracts/*.php` | Interfaces shared by the 3 cost producers | — | Active | |
| CostValidationException | Backend Exception | `.../CostCollector/Exceptions/CostValidationException.php` | Domain validation error for cost capture | — | Active | |
| CostLinePolicy | Backend Policy | `.../CostCollector/Policies/CostLinePolicy.php` | Authorization for cost line actions | Permissions | Active | |
| CostLine | Backend Model | `.../CostCollector/Models/CostLine.php` | The core cost-fact row (element, expense code, tax, verification state); shared by budget AND spend via `nature` | ExpenseCode, ProjectEnquiry | Active | Central table for the whole cost ledger; `$guarded=['id']` — see data model doc |
| ExpenseCode | Backend Model | `.../CostCollector/Models/ExpenseCode.php` | Chart-of-accounts-linked expense classification | ChartOfAccount | Active | `is_procurable` flag added Sept 2026 |
| AccountingPeriod | Backend Model | `.../CostCollector/Models/AccountingPeriod.php` | Open/closed/locked period record | PeriodCloseService | Active | Also referenced by top-level Periods feature |
| StoreCostLineRequest | Backend Request | `.../CostCollector/Http/Requests/StoreCostLineRequest.php` | Validates cost-line capture payload | — | Active | |
| CostLineResource / ExpenseCodeResource | Backend Resources | `.../CostCollector/Http/Resources/*.php` | API response shaping | — | Active | |
| AuditCostIdentityCommand | Console command | `.../CostCollector/Console/AuditCostIdentityCommand.php` | Detects job-number vs Projects-PK identity defects | CostLine | Active | |
| RepostMisattributedStoresCostsCommand | Console command | `.../CostCollector/Console/RepostMisattributedStoresCostsCommand.php` | Repairs mis-posted Stores-origin costs | StoresCostProducer | Active | |
| BackfillCostLineElementCommand | Console command | `.../CostCollector/Console/BackfillCostLineElementCommand.php` | Backfills the `element` cost dimension | CostLine | Active | |
| RepairCostLineDetailCommand | Console command | `.../CostCollector/Console/RepairCostLineDetailCommand.php` | Data-repair utility for cost line detail fields | CostLine | Active | |
| BackfillPettyCashCostsCommand | Console command | `.../CostCollector/Console/BackfillPettyCashCostsCommand.php` | Backfills CostLines for historical petty-cash disbursements | PettyCashCostProducer | Active | |
| ProjectBudgetsCommand | Console command | `.../CostCollector/Console/ProjectBudgetsCommand.php` | Recomputes/reports project budget projections | BudgetProjector | Active | |
| CostCollectorIndex.vue | Frontend View | `ERP-Frontend/src/modules/finance/cost-collector/views/CostCollectorIndex.vue` (738 lines) | 3-tab surface: capture / mine / account (`tab` query param) | costCollectorService.ts | Active | Tabs `L36-38` |
| CostAccountsView.vue | Frontend View | `.../cost-collector/views/CostAccountsView.vue` | Portfolio-wide budget-vs-actual table | costCollectorService.ts | Active | 12 columns, none of them billing/margin — see risk register |
| CostVerificationView.vue | Frontend View | `.../cost-collector/views/CostVerificationView.vue` (750 lines) | Finance review queue UI | costCollectorService.ts | Active | |
| PaymentVouchersView.vue | Frontend View | `.../cost-collector/views/PaymentVouchersView.vue` (703 lines) | Lists/approves/posts Spend Vouchers | costCollectorService.ts | Active | UI label "Payment Vouchers" over backend `SpendVoucher` model |
| CostCaptureForm.vue | Frontend Component | `.../cost-collector/components/CostCaptureForm.vue` | Multi-step capture wizard body | DynamicFieldInput, PickerModal | Active | |
| CaptureStep.vue | Frontend Component | `.../cost-collector/components/CaptureStep.vue` | Single step wrapper for the wizard | — | Active | |
| CostAccountPanel.vue | Frontend Component | `.../cost-collector/components/CostAccountPanel.vue` | Account/category drill-down panel; renders margin for one project | — | Active | |
| CostReasonModal.vue | Frontend Component | `.../cost-collector/components/CostReasonModal.vue` | Captures reason text for query/reject/reclassify | — | Active | |
| CostReclassifyPanel.vue | Frontend Component | `.../cost-collector/components/CostReclassifyPanel.vue` | Re-code a cost to a different expense code/project | — | Active | |
| CostTaxPanel.vue | Frontend Component | `.../cost-collector/components/CostTaxPanel.vue` | VAT/WHT preview panel at verification | — | Active | |
| DynamicFieldInput.vue | Frontend Component | `.../cost-collector/components/DynamicFieldInput.vue` | Renders capture-form fields driven by expense-code schema | — | Active | |
| EvidenceViewer.vue | Frontend Component | `.../cost-collector/components/EvidenceViewer.vue` | Views uploaded receipt/tax evidence | — | Active | |
| ExpenseTypeModal / PayeeModal / ProjectModal / PickerModal | Frontend Components | `.../cost-collector/components/*.vue` | Lookup pickers used inside capture wizard | — | Active | |
| QueueFilterBar.vue | Frontend Component | `.../cost-collector/components/QueueFilterBar.vue` | Filter bar for verification queue | — | Active | |
| costCollectorService.ts | Frontend Service | `.../cost-collector/services/costCollectorService.ts` | API client for all cost-collector endpoints | axios | Active | |

## 1.2 Petty Cash

| Component | Type | File/Location | Purpose | Dependencies | Status | Notes |
|---|---|---|---|---|---|---|
| PettyCashController | Backend Controller | `app/Modules/Finance/PettyCash/Controllers/PettyCashController.php` | Disbursements, balance, workspace, transactions, voucher PDF; also hosts the `clearAll()` wipe endpoint | LedgerService, PettyCashService | Active | ~30 routes, `routes/api.php:1104-1145` |
| PettyCashRequisitionController | Backend Controller | `.../PettyCash/Controllers/PettyCashRequisitionController.php` (2,009 lines) | Fund requisition lifecycle: create/approve/disburse/reject/surrender/reconcile | RequisitionSchemaService | Active | Routes `routes/api.php:1178-1194`; public token routes `routes/api.php:63-77` |
| PettyCashRequisitionTypeController | Backend Controller | `.../PettyCash/Controllers/PettyCashRequisitionTypeController.php` | CRUD for requisition-type templates/forms | RequisitionSchemaService | Active | `apiResource` at `routes/api.php:1099` |
| PettyCashTopUpController | Backend Controller | `.../PettyCash/Controllers/PettyCashTopUpController.php` | Float top-ups, balance trends/statistics | TopUpAllocator | Active | Routes `routes/api.php:1125-1136,1174-1175` |
| PettyCashOfflineBatchController | Backend Controller | `.../PettyCash/Controllers/PettyCashOfflineBatchController.php` | Excel bulk-upload of disbursements, approve/reject batches | OfflineBatchService | Active | Routes `routes/api.php:1162-1171` |
| PettyCashReportController | Backend Controller | `.../PettyCash/Controllers/PettyCashReportController.php` | Analytics, custody statements, project reports, export | PettyCashReportService | Active | Routes `routes/api.php:1151-1157` |
| LedgerService | Backend Service | `.../PettyCash/Services/LedgerService.php` | Single-writer hash-chained ledger (credit/debit-once invariant), atomic with `PettyCashBalance` cache update | LedgerEntry | Active | Ledger-as-truth design |
| FundCustodyService | Backend Service | `.../PettyCash/Services/FundCustodyService.php` | Tracks custody handover between float holders; self-audits reconciliation difference | LedgerService | Active | The one genuine Finance KPI dashboard in the product |
| PettyCashService | Backend Service | `.../PettyCash/Services/PettyCashService.php` (999 lines) | Disbursement create/void/search orchestration; also hosts `clearAllData()` | LedgerService, ProjectIdentityResolver | Active | Second, parallel settlement engine to `PaymentSettlementService` |
| OfflineBatchService | Backend Service | `.../PettyCash/Services/OfflineBatchService.php` | Parses/validates/commits Excel batch uploads | PettyCashDisbursementImport | Active | |
| PettyCashReportService | Backend Service | `.../PettyCash/Services/PettyCashReportService.php` | Builds analytics/custody/project reports | LedgerService | Active | |
| ProjectIdentityResolver | Backend Service | `.../PettyCash/Services/ProjectIdentityResolver.php` | Resolves job_number identity for a disbursement | ProjectEnquiry | Active | |
| RequisitionSchemaService | Backend Service | `.../PettyCash/Services/RequisitionSchemaService.php` | Drives dynamic requisition-type form schema | PettyCashRequisitionType | Active | |
| TopUpAllocator | Backend Service | `.../PettyCash/Services/TopUpAllocator.php` | Allocates disbursements against a specific top-up batch | PettyCashTopUp | Active | |
| PettyCashPolicy | Backend Policy | `.../PettyCash/Policies/PettyCashPolicy.php` | Authorization for petty-cash actions; `clearAll()` always returns false (Super Admin `before()` bypass only) | Permissions | Active | |
| PettyCashRepository | Backend Repository | `.../PettyCash/Repositories/PettyCashRepository.php` | Query layer for petty cash reads | — | Active | |
| PettyCashDisbursementImport | Backend Import | `.../PettyCash/Imports/PettyCashDisbursementImport.php` | Maatwebsite import for Excel disbursements | OfflineBatchService | Active | `WithMultipleSheets` pinned to sheet 0 |
| PettyCashTransactionsExport | Backend Export | `.../PettyCash/Exports/PettyCashTransactionsExport.php` | Excel export of transactions | — | Active | |
| DirectDisbursementRequest | Backend Model | `.../PettyCash/Models/DirectDisbursementRequest.php` | A direct-payment approval request (no prior float) | PettyCashController | Active | |
| PettyCashActivityLog | Backend Model | `.../PettyCash/Models/PettyCashActivityLog.php` | Custom audit trail of petty-cash actions, incl. old/new-value diffs for top-up edits | — | Active | One of 3 disconnected audit mechanisms — see 07 |
| PettyCashBalance | Backend Model | `.../PettyCash/Models/PettyCashBalance.php` | Current float balance record (cached projection over the ledger) | LedgerService | Active | |
| PettyCashDisbursementAllocation | Backend Model | `.../PettyCash/Models/PettyCashDisbursementAllocation.php` | Links a disbursement to a planned cost line | CostLine | Active | |
| PettyCashOfflineBatch / PettyCashOfflineRow | Backend Models | `.../PettyCash/Models/PettyCashOfflineBatch.php`, `PettyCashOfflineRow.php` | Excel batch + row-level staging records | OfflineBatchService | Active | |
| PettyCashRequisition / PettyCashRequisitionItem | Backend Models | `.../PettyCash/Models/PettyCashRequisition.php`, `PettyCashRequisitionItem.php` | The fund-requisition document and its line items | RequisitionSchemaService | Active | Only soft-deletable Finance table |
| PettyCashRequisitionType | Backend Model | `.../PettyCash/Models/PettyCashRequisitionType.php` | A configurable requisition template | RequisitionSchemaService | Active | |
| PettyCashSurrenderItem | Backend Model | `.../PettyCash/Models/PettyCashSurrenderItem.php` | Line item for returned change/reconciliation | — | Active | |
| PettyCashTopUp | Backend Model | `.../PettyCash/Models/PettyCashTopUp.php` | A float top-up batch | TopUpAllocator | Active | |
| PettyCashIndex.vue | Frontend View | `ERP-Frontend/src/modules/finance/petty-cash/views/PettyCashIndex.vue` (1,166 lines) | Register: Cashbook / Approval inbox / Top-up custody / Fund requests / Reports / Audit trail tabs | usePettyCash, pettyCashStore | Active | "Project Budgets" tab deliberately removed (dead numbers) |
| RequisitionIndex.vue | Frontend View | `.../petty-cash/views/requisitions/RequisitionIndex.vue` (1,024 lines) | List/search/filter requisitions | pettyCashService | Active | |
| RequisitionFormPage.vue / RequisitionForm.vue | Frontend Views | `.../petty-cash/views/requisitions/*.vue` (2,466 lines) | New/edit requisition form | RequisitionSchemaField | Active | |
| RequisitionShow.vue | Frontend View | `.../petty-cash/views/requisitions/RequisitionShow.vue` (1,461 lines) | Requisition detail + inline edit fields | pettyCashService | Active | |
| RequisitionPreview.vue / RequisitionStatement.vue | Frontend Views | `.../petty-cash/views/requisitions/*.vue` | Print/preview + outstanding-items statement | — | Active | |
| PublicSignOff.vue / PublicRequisitionForm.vue | Frontend Views | `.../petty-cash/views/{requisitions,public}/*.vue` | Unauthenticated payee/requester flows via token link | public API | Active | |
| RequisitionTypesView.vue | Frontend View | `.../petty-cash/views/setup/RequisitionTypesView.vue` | Configure requisition templates/fields | — | Active | |
| DirectApprovalQueue.vue, DisbursementForm.vue (1,570 lines), TopUpForm.vue, BalanceCard.vue, ActivityLogs.vue, TransactionList.vue (984 lines), TransactionDetailModal.vue | Frontend Components | `.../petty-cash/components/*.vue` | Cashbook/approval building blocks | — | Active | |
| BudgetExceptionModal.vue | Frontend Component | `.../petty-cash/components/BudgetExceptionModal.vue` | Records the "authorise overrun / move budget" doors | — | Active | |
| ExcelUploadModal.vue | Frontend Component | `.../petty-cash/components/ExcelUploadModal.vue` | Bulk Excel disbursement upload UI | PettyCashOfflineBatchController | Active | |
| FundCustodyDashboard.vue | Frontend Component | `.../petty-cash/components/FundCustodyDashboard.vue` | Top-up custody drill-down; the one real KPI dashboard | — | Active | |
| ReportsPanel.vue | Frontend Component | `.../petty-cash/components/ReportsPanel.vue` | Spend-by-classification/method/project report | — | Active | Previously unreachable, since wired up |
| PettyCashSurrenderDrawer.vue | Frontend Component | `.../petty-cash/components/PettyCashSurrenderDrawer.vue` | Surrender/reconcile leftover cash | — | Active | |
| RequisitionSchemaField.vue / setup/FieldEditor.vue / fields/* | Frontend Components | `.../petty-cash/components/**` | Dynamic-field rendering/editing for requisition types | registry.ts | Active | |
| usePettyCash / useTopUps / useReports / usePermissions / useErrorHandler | Frontend Composables | `.../petty-cash/composables/*.ts` | State/data-fetch/permission logic | pettyCashStore | Active | |
| pettyCashStore.ts / pettyCashService.ts | Frontend Store/Service | `.../petty-cash/{stores,services}/*.ts` | Central state + API client | axios | Active | |

## 1.3 Ledger / General Ledger / Journals

| Component | Type | File/Location | Purpose | Dependencies | Status | Notes |
|---|---|---|---|---|---|---|
| JournalEntryController | Backend Controller | `app/Modules/Finance/Controllers/JournalEntryController.php` | List journals, trial balance, account statement, export, reverse | JournalPostingService | Active | Read-only + guarded reverse by design; routes `routes/api.php:1030-1041` |
| JournalPostingService | Backend Service | `app/Modules/Finance/Services/JournalPostingService.php` (1,947 lines) | Double-entry posting engine — the "single funnel" (`postBalancedEntry()`) plus several producer methods | ChartOfAccount, PostingRule | Active | Contains 3 `@deprecated` methods still reachable; 2 producers bypass the balance-check funnel entirely — see risk register |
| JournalEntry / JournalLine | Backend Models | `app/Modules/Finance/Models/JournalEntry.php`, `JournalLine.php` | Double-entry journal header/lines | ChartOfAccount | Active | |
| ChartOfAccount / PostingRule | Backend Models | `app/Modules/Finance/Models/ChartOfAccount.php`, `PostingRule.php` | GL account tree and cost→account posting rules | — | Active | `PostingRule` is largely bypassed in practice — see data model doc |
| JournalEntryResource / JournalLineResource | Backend Resources | `app/Modules/Finance/Resources/*.php` | API shaping for journal data | — | Active | |
| LedgerExportService | Backend Service | `app/Modules/Finance/Services/LedgerExportService.php` | CSV/export of journal data, document-batched | JournalEntry | Active | |
| GeneralLedgerView.vue | Frontend View | `ERP-Frontend/src/modules/finance/ledger/views/GeneralLedgerView.vue` (584 lines) | 3-tab GL: Journal entries / Account summary (trial) / Account book | ledgerService.ts | Active | Source filter exposes only 2 of 6+ real posting sources |
| JournalEntryDrawer.vue | Frontend Component | `.../ledger/components/JournalEntryDrawer.vue` | Journal detail/reversal drawer | ledgerService.ts | Active | Never renders `created_by`, even though the API returns it |
| ledgerService.ts | Frontend Service | `.../ledger/services/ledgerService.ts` | API client | axios | Active | |

## 1.4 Receivables / Client Billing

| Component | Type | File/Location | Purpose | Dependencies | Status | Notes |
|---|---|---|---|---|---|---|
| `EnquiryController` (Projects module) | Backend Controller | `app/Modules/Projects/Http/Controllers/EnquiryController.php` (2,067 lines) | **Owns all client invoicing/receivables HTTP endpoints**: finance-progress, receivables/summary, unallocated receipts, invoices CRUD, issue/void, credit notes, log/verify/update/delete payment | `Finance\Services\ReceivablesPostingService` | Active | Receivables backend surface lives entirely outside the Finance module — see navigation map ISSUE-4 |
| ReceivablesPostingService | Backend Service | `app/Modules/Finance/Services/ReceivablesPostingService.php` | Posts invoice/receipt/credit-note journal entries | JournalPostingService | Active | Domain logic correctly lives in Finance even though the controller does not |
| ReceivablesAgeingService | Backend Service | `app/Modules/Finance/Services/ReceivablesAgeingService.php` | AR ageing buckets for reports | ProjectInvoice | Active | |
| InvoicePricer | Backend Service | `app/Modules/Finance/Services/InvoicePricer.php` | Prices invoice lines incl. tax, rate resolved by invoice date | TaxResolver | Active | |
| WorkInProgressReleaseService | Backend Service | `app/Modules/Finance/Services/WorkInProgressReleaseService.php` | Releases WIP to Cost of Sales proportionally at billing | ReceivablesPostingService | Active | |
| ProjectInvoice / ProjectInvoiceLine | Backend Models | `app/Modules/Finance/Models/ProjectInvoice.php`, `ProjectInvoiceLine.php` | Client invoice header/lines; credit notes are negative-value rows of the same table | ChartOfAccount | Active | |
| ClientReceipt | Backend Model | `app/Modules/Finance/Models/ClientReceipt.php` | Client payment/receipt record (bank-side) | ProjectInvoice | Active | |
| EnquiryPayment | Backend Model (root namespace) | `app/Models/EnquiryPayment.php` | Client cash receipt allocation against an enquiry/invoice | ProjectEnquiry, ClientReceipt | Active | Lives outside `App\Modules\Finance\Models` unlike every sibling AR model |
| FinanceReleased | Backend Event | `app/Events/FinanceReleased.php` | Domain event fired when Finance clears/releases a stage tied to an enquiry | ProjectEnquiry, User | Active | |
| ProjectReceivablesIndex.vue | Frontend View | `ERP-Frontend/src/modules/finance/receivables/views/ProjectReceivablesIndex.vue` (777 lines) | Client invoices, receipts, deposits, balances per project | axios direct to Projects-module endpoints | Active | Single page, no internal tabs; imports a type from `@/modules/projects` |
| EnquiryFinanceModal.vue | Frontend Component | `.../receivables/components/EnquiryFinanceModal.vue` (1,384 lines) | Modal for invoice/receipt/credit-note actions on one enquiry | ProjectReceivablesIndex | Active | |

## 1.5 Reconciliation

| Component | Type | File/Location | Purpose | Dependencies | Status | Notes |
|---|---|---|---|---|---|---|
| ReconciliationController | Backend Controller | `app/Modules/Finance/Controllers/ReconciliationController.php` | Statement import, match/unmatch/ignore, auto-match, reconcile/reopen | ReconciliationService | Active | Routes `routes/api.php:993-1006` |
| CashMovementController | Backend Controller | `app/Modules/Finance/Controllers/CashMovementController.php` | Manual offsetting cash movements (bank fees, interest, etc.) | CashMovementService | Active | Routes `routes/api.php:989-992` |
| ReconciliationService | Backend Service | `app/Modules/Finance/Services/ReconciliationService.php` (568 lines) | Matching/suggestion/candidate algorithm, date-tolerance configurable | StatementTransaction, StatementMatch | Active | match/unmatch/ignore hard-delete match history — see risk register |
| CashMovementService | Backend Service | `app/Modules/Finance/Services/CashMovementService.php` | Records/voids ad-hoc cash movements, posts through the balanced-entry funnel | ChartOfAccount | Active | |
| ReconciliationStatement / StatementMatch / StatementTransaction | Backend Models | `app/Modules/Finance/Models/*.php` | Imported bank statement + its transactions + matches | — | Active | Neither StatementMatch nor StatementTransaction is soft-deletable |
| CashMovement | Backend Model | `app/Modules/Finance/Models/CashMovement.php` | An offsetting cash entry not tied to a statement transaction | — | Active | A 4th parallel money-movement table alongside Payment/BillPayment/ClientReceipt |
| ReconciliationView.vue | Frontend View | `ERP-Frontend/src/modules/finance/reconciliation/views/ReconciliationView.vue` (802 lines) | Unmatched / Matched / Ignored tabs; import, auto-match, certify | reconciliation components | Active | |
| ReconciliationHeader.vue / ReconciliationBalancingBar.vue / StatementImportModal.vue / SearchMatchModal.vue / QuickMovementModal.vue / ReconciliationWorkflowGuide.vue | Frontend Components | `.../reconciliation/components/*.vue` | Supporting UI | — | Active | |

## 1.6 Reports (P&L, Trial Balance, Ageing)

| Component | Type | File/Location | Purpose | Dependencies | Status | Notes |
|---|---|---|---|---|---|---|
| FinanceReportController | Backend Controller | `app/Modules/Finance/Controllers/FinanceReportController.php` | `profitAndLoss`, `receivablesAgeing` endpoints | ProfitAndLossService, ReceivablesAgeingService | Active | Routes `routes/api.php:1047-1048` |
| ProfitAndLossService | Backend Service | `app/Modules/Finance/Services/ProfitAndLossService.php` | Computes P&L from journals | JournalEntry | Active | Self-declared non-statutory (no depreciation/opening balances/equity) |
| PayablesAgeingService | Backend Service | `app/Modules/ProcurementStores/Services/PayablesAgeingService.php` | AP ageing, reads `Bill.balance`/`Bill.status` directly | Bill | Active | Lives in ProcurementStores, not Finance — separate route namespace |
| FinancialReportsView.vue | Frontend View | `ERP-Frontend/src/modules/finance/reports/views/FinancialReportsView.vue` (431 lines) | 4 tabs: P&L / Trial balance / AR ageing / AP ageing | reportsService.ts | Active | "Trial balance" tab duplicates GL's "Account summary" tab under a different label |
| reportsService.ts | Frontend Service | `.../reports/services/reportsService.ts` | API client for report endpoints | axios | Active | |

## 1.7 Tax (VAT / eTIMS / WHT)

| Component | Type | File/Location | Purpose | Dependencies | Status | Notes |
|---|---|---|---|---|---|---|
| TaxScheduleController | Backend Controller | `app/Modules/Finance/Controllers/TaxScheduleController.php` | VAT input/output schedules, VAT return, eTIMS gap, WHT schedule, treatments | TaxScheduleService, TaxResolver | Active | Routes `routes/api.php:1081-1089` |
| TaxScheduleService | Backend Service | `app/Modules/Finance/Services/TaxScheduleService.php` (574 lines) | Builds the above schedules; deliberately does not track "filed" state | VatTreatment, WhtCategory | Active | |
| TaxResolver | Backend Service | `app/Modules/Finance/Services/TaxResolver.php` | Resolves applicable tax treatment for a cost/invoice line; rate never hardcoded | VatTreatment, WhtCategory | Active | |
| VatTreatment / WhtCategory | Backend Models | `app/Modules/Finance/Models/VatTreatment.php`, `WhtCategory.php` | Effective-dated tax treatment/category reference data | ChartOfAccount | Active | No non-resident WHT category seeded (deliberate) |
| BackfillTaxDocumentCommand | Console command | `app/Modules/Finance/Console/BackfillTaxDocumentCommand.php` | Backfills tax document fields on historical cost lines | CostLine | Active | |
| TaxSchedulesView.vue | Frontend View | `ERP-Frontend/src/modules/finance/tax/views/TaxSchedulesView.vue` (577 lines) | 4 tabs: VAT input claim / VAT output / Missing evidence / WHT | taxService.ts | Active | Two columns (Treatment, Supplier invoice) exist in data/CSV but not on-screen |
| taxService.ts | Frontend Service | `.../tax/services/taxService.ts` | API client | axios | Active | |

## 1.8 Work Queue

| Component | Type | File/Location | Purpose | Dependencies | Status | Notes |
|---|---|---|---|---|---|---|
| FinanceWorkQueueController | Backend Controller | `app/Modules/Finance/Controllers/FinanceWorkQueueController.php` | Unified queue: count/index/claim/release/reassign/history | FinanceWorkQueueService | Active | Routes `routes/api.php:961-966` |
| FinanceWorkQueueService | Backend Service | `app/Modules/Finance/Services/FinanceWorkQueueService.php` | Aggregates procurement/cost/petty-cash/voucher/receivables work into one list | FinanceWorkAssignmentLifecycle | Active | Pulls from 6 different permission-gated sources |
| FinanceWorkAssignmentLifecycle | Backend Service | `app/Modules/Finance/Services/FinanceWorkAssignmentLifecycle.php` | Claim/release/reassign state machine + history | FinanceWorkAssignment, FinanceWorkAssignmentEvent | Active | `reassign` restricted to Admin/Super Admin and has zero frontend caller |
| FinanceWorkAssignment / FinanceWorkAssignmentEvent | Backend Models | `app/Modules/Finance/Models/*.php` | Who is working which queue item, and its event trail | — | Active | |
| FinanceWorkQueueView.vue | Frontend View | `ERP-Frontend/src/modules/finance/work-queue/views/FinanceWorkQueueView.vue` (172 lines) | Single-page unified inbox, no internal tabs | useFinanceWorkQueueCount | Active | Default landing page for `/finance` |
| useFinanceWorkQueueCount.ts | Frontend Composable | `.../work-queue/useFinanceWorkQueueCount.ts` | Polls/holds the badge count on the "Work queue" rail tab | axios | Active | |

## 1.9 Payments, Payment Sources & Spend/Payment Vouchers

| Component | Type | File/Location | Purpose | Dependencies | Status | Notes |
|---|---|---|---|---|---|---|
| PaymentController | Backend Controller | `app/Modules/Finance/Controllers/PaymentController.php` | **Only** exposes `reverse` | PaymentReversalService | Active | No `index`/`show` route — payments are only browsable through Ledger/Vouchers/Receivables screens |
| PaymentSourceController | Backend Controller | `app/Modules/Finance/Controllers/PaymentSourceController.php` | CRUD for bank/mobile-money/cash paying accounts; `payment-methods`, `ledger-accounts` lookups | PaymentSource model | Active | Routes `routes/api.php:976-982` |
| SpendVoucherController | Backend Controller | `app/Modules/Finance/Controllers/SpendVoucherController.php` (604 lines) | List/create/show/cancel/approve/post spend vouchers, eligible-liabilities | SpendVoucherSettlementService | Active | Reference-pattern 3-permission maker/checker/poster control; no approver-reject action |
| PaymentSettlementService | Backend Service | `app/Modules/Finance/Services/PaymentSettlementService.php` | Allocates one Payment across several invoices/liabilities; documented as "the single cash-side settlement engine" | PaymentAllocation | Active | Claim is false — PettyCashService has its own parallel engine |
| PaymentReversalService | Backend Service | `app/Modules/Finance/Services/PaymentReversalService.php` | Atomic Payment/Voucher reversal | JournalPostingService | Active | |
| SpendVoucherSettlementService | Backend Service | `app/Modules/Finance/Services/SpendVoucherSettlementService.php` | Posts/settles a spend voucher against verified liabilities | JournalPostingService | Active | |
| Payment / PaymentAllocation / PaymentSource | Backend Models | `app/Modules/Finance/Models/*.php` | The unified payment record (renamed from `petty_cash_disbursements`), its allocations, and the paying-account master | ChartOfAccount | Active | Carries both legacy free-text and current structured classification fields |
| SpendVoucher / SpendVoucherAllocation | Backend Models | `app/Modules/Finance/Models/SpendVoucher.php`, `SpendVoucherAllocation.php` | The voucher record and its per-liability allocation lines | Payment | Active | Backend model is `SpendVoucher`; frontend/user-facing name is "Payment Voucher" |
| PaymentMethods (Support) | Backend Support class | `app/Modules/Finance/Support/PaymentMethods.php` | Canonical list of payment methods | — | Active | |
| PaymentException / InvalidPaymentSourceException / PaymentAlreadyVoidedException | Backend Exceptions | `app/Modules/Finance/Exceptions/*.php` | Domain errors for the payment/voucher pipeline | — | Active | |
| PayingAccountsView.vue | Frontend View | `ERP-Frontend/src/modules/finance/setup/views/PayingAccountsView.vue` (276 lines) | Manage bank/mobile-money/cash paying accounts | usePayingAccounts | Active | |
| usePayingAccounts.ts / usePaymentMethods.ts | Frontend Composables | `.../shared/composables/*.ts` | Shared paying-account/payment-method lookups | axios | Active | |
| DocumentNumber (Support) | Backend Support class | `app/Modules/Finance/Support/DocumentNumber.php` | Atomic sequential document numbering (`document_sequences`, row-locked) | Database/Migrations `2026_09_09_000000` | Active | Fixed a real prior race condition; not yet applied to PO/Bill/GRN numbering |

## 1.10 Periods (Month-end Close) & Setup/Readiness

| Component | Type | File/Location | Purpose | Dependencies | Status | Notes |
|---|---|---|---|---|---|---|
| AccountingPeriodController | Backend Controller | `app/Modules/Finance/Controllers/AccountingPeriodController.php` | List periods, checklist, close/lock/reopen | PeriodCloseService | Active | Routes `routes/api.php:1070-1074` |
| PeriodCloseService | Backend Service | `app/Modules/Finance/Services/PeriodCloseService.php` | Runs the close checklist and locks a period | AccountingPeriod, PeriodAuditLog | Active | Before Aug 2026 no month had ever actually been closed |
| ClosePeriodCommand | Console command | `app/Modules/Finance/Console/ClosePeriodCommand.php` | CLI equivalent of the close action | PeriodCloseService | Active | Predates the UI |
| PeriodAuditLog | Backend Model | `app/Modules/Finance/Models/PeriodAuditLog.php` | Audit trail of period close/lock/reopen actions | AccountingPeriod | Active | |
| FinanceReadinessController | Backend Controller | `app/Modules/Finance/Controllers/FinanceReadinessController.php` | Readiness/configuration checklist | FinanceSetting | Active | Route `routes/api.php:963` |
| FinanceSetting | Backend Model | `app/Modules/Finance/Models/FinanceSetting.php` | Effective-dated key/value Finance settings | — | Active | |
| LabourClassificationController | Backend Controller | `app/Modules/Finance/Controllers/LabourClassificationController.php` | index/update department labour classification | — | **Orphaned — no frontend consumer found** | Routes exist; zero references in `ERP-Frontend/src` |
| AccountingPeriodsView.vue | Frontend View | `ERP-Frontend/src/modules/finance/periods/views/AccountingPeriodsView.vue` (390 lines) | Month-end close checklist/lock/reopen | axios direct | Active | |
| FinanceReadinessView.vue | Frontend View | `ERP-Frontend/src/modules/finance/setup/views/FinanceReadinessView.vue` (156 lines) | Readiness checklist landing page | axios direct | Active | 3 different labels for the same page (see navigation map) |
| ChartAccountMap / CatalogueDimensionMap / LedgerCoverage / PettyCashCap (Support) | Backend Support classes | `app/Modules/Finance/Support/*.php` | Static mapping helpers used across services | — | Active | `ChartAccountMap` is under-adopted — see data model doc |

## 1.11 Migrations & Seeders (grouped by theme)

| Component | Type | File/Location | Purpose | Status |
|---|---|---|---|---|
| Chart of accounts & posting rules | Migrations | `Database/Migrations/2026_03_05_071032_*`, `2026_08_09_000001..003_*` | GL account tree, dimensions, tax tables, posting rules | Active |
| Accounting periods & settings | Migrations | `2026_08_09_000004_*`, `2026_08_09_000005_*` | Periods table + finance settings table | Active |
| Expense codes | Migrations | `2026_08_09_000006_*`, `2026_09_06_000001_add_is_procurable_*` | Expense code catalogue + procurable flag | Active |
| Spend vouchers | Migrations | `2026_08_09_000007_*`, `2026_08_23_000001_create_spend_voucher_allocations_*`, `2026_09_13_000007_link_spend_vouchers_to_payments_*` | Voucher table, allocation table, link to unified Payment | Active |
| Cost lines | Migrations | `2026_08_09_000008/000009_*`, `2026_09_07_000003_*`, `2026_08_19_000001_add_tax_document_fields_*` | Core cost-line table + cost centre + tax document fields | Active |
| Finance/Petty-cash permissions | Migrations | `2026_08_09_000010/000011/000012_*`, `2026_08_10_000002_*`, `2026_08_18_000001_*` | Permission seeding via migration (not a seeder) | Active |
| Journal entries | Migration | `2026_08_09_000013_create_journal_entries_tables_*` | Double-entry journal header/lines | Active |
| Supplier tax fields | Migration | `2026_08_10_000001_add_tax_fields_to_suppliers_table_*` | VAT/WHT fields on Supplier (ProcurementStores) | Active |
| Petty cash ↔ planned cost lines | Migrations | `2026_08_10_000003..000006_*` | Petty-cash-to-cost-ledger linkage + direct disbursement requests | Active |
| Requisition catalogue alignment | Migrations | `2026_08_11_000200_*`, `2026_09_05..07_*` | Aligns requisition types/categories with the expense-code catalogue | Active |
| Payment source plumbing | Migrations | `2026_09_07_000004/000005/000006_*` | Connects supplier payments/requisitions/cash ledger to `payment_sources` | Active |
| Unified payment architecture | Migrations | `2026_09_09_000000/000001/000002_*`, `2026_09_13_000008_*` | Document numbering + single Payment doc model | Active |
| Reconciliation & cash movements | Migrations | `2026_09_13_000002/000003/000004_*` | Bank reconciliation schema | Active |
| Period audit log | Migration | `2026_09_13_000001_create_period_audit_logs_table_*` | Close/lock/reopen audit trail | Active |
| Work assignments (queue) | Migrations | `2026_09_13_000005/000006_*` | Unified work-queue claim/history schema | Active |
| Legacy PettyCash sub-migration | Migration | `PettyCash/Database/Migrations/2025_12_16_000001_add_tax_field_to_petty_cash_disbursements_table.php` | Predates the main Finance migration set by ~8 months, in a separate folder | Active |
| Reference-data seeders | Seeders | `Database/Seeders/{ChartOfAccountSeeder,FinanceReferenceSeeder,FinanceDimensionSeeder,FinanceTaxSeeder,FinanceSettingsSeeder,AccountingPeriodSeeder}.php` | Reference data for GL/tax/dimensions/periods | Active |
| Expense-code seeders | Seeders | `Database/Seeders/{ExpenseCodeSeeder,OperationalExpenseCodes,NonExpenseCodes}.php` | Expense code catalogue | Active | `ExpenseCodeSeeder` alone leaves all codes inactive — 4 seeders must run in order |
| Payment source / requisition seeders | Seeders | `Database/Seeders/{PaymentSourceSeeder,PettyCashRequisitionTypeSeeder}.php` | Reference data | Active |

## 1.12 Permissions (Finance domain)

| Component | Type | File/Location | Purpose | Status | Notes |
|---|---|---|---|---|---|
| `FINANCE_*` permission constants | Backend Constants | `app/Constants/Permissions.php:92-227,411` | ~40 distinct finance permissions across costs, petty cash, vouchers, receivables, invoices, journals, periods, reports, payment sources | Active | |
| `FINANCE_BUDGET_*`, `FINANCE_QUOTE_*` | Backend Constants | `Permissions.php:93-103` | Named "finance." but govern **Projects module** quote/budget approval | Active | Zero consumers inside `app/Modules/Finance` |
| `FINANCE_PETTY_CASH_*_LEGACY` | Backend Constants | `Permissions.php:225-227` | Pre-refactor petty cash permission names kept for compatibility | **Legacy — verify still granted/needed** | |
| RolePermissions.php | Backend Constants | `app/Constants/RolePermissions.php` | Maps roles → permission constants; single source of truth, replacing the old migration-vs-seeder disagreement | Active | See `07_APPROVAL_AND_PERMISSION_MATRIX.md` for the full matrix |

## 1.13 Finance-adjacent code embedded in other modules

| Component | Type | File/Location | Purpose | Dependencies | Status | Notes |
|---|---|---|---|---|---|---|
| BillController | Backend Controller | `app/Modules/ProcurementStores/Http/Controllers/BillController.php` (832 lines) | Supplier bills: CRUD, record-payment, multi-payment, verify, ageing, stats, PDF | Finance `Payment`/`JournalPostingService` | Active | Registered `update()` route has **no handler** — a live 500 trap |
| GoodsReceiptNoteController | Backend Controller | `app/Modules/ProcurementStores/Http/Controllers/GoodsReceiptNoteController.php` (741 lines) | GRN receipt confirmation feeding cost/stock valuation | StoresCostProducer | Active | |
| PurchaseOrderController | Backend Controller | `app/Modules/ProcurementStores/Controllers/PurchaseOrderController.php` (591 lines) | PO CRUD/approve | PurchaseOrderWorkflow | Active | `update()` has no status guard at all; `destroy()`'s pending-only guard is commented out — see risk register (CRITICAL) |
| StockMovementPostingService | Backend Service | `app/Modules/Finance/Services/StockMovementPostingService.php` | Posts a Stores stock movement to the GL | JournalPostingService | Active | Lives in Finance but is invoked from ProcurementStores/Stores flows |
| BillingIndex.vue / BillingCreate.vue / BillingShow.vue | Frontend Views | `ERP-Frontend/src/modules/procurement-stores/views/procurement/Billing*.vue` | Supplier bill list/create/detail, incl. VAT/WHT capture | procurement-stores routes.ts | Active | Reached via Procurement, not the Finance nav rail |
| AddMaterialBudgetModal.vue, BudgetTask.vue, BudgetExpensesTab.vue, BudgetLabourTab.vue, BudgetMaterialsTab.vue, BudgetLogisticsTab.vue, BudgetDataDisplay.vue | Frontend Components | `ERP-Frontend/src/modules/projects/components/**` | Project budget capture/approval task inside the Projects quote-to-cash workflow | QuoteApprovalTask, ProjectTasks | Active | A separate "budget" concept from Finance's Cost Accounts — see navigation map ISSUE-5 |
| FinanceContextCard.vue | Frontend Component | `ERP-Frontend/src/modules/universal-task/components/FinanceContextCard.vue` | Shows finance context on a Universal Task card | — | **Dead — zero usages found** | |
| PayrollEngineController | Backend Controller | `app/Modules/HR/Http/Controllers/PayrollEngineController.php` | Payroll variables, ledgers, tax bands, payslips, bank/M-Pesa/P9 exports | HR Routes | Active | 3 commented-out routes (batchGenerate, getComplianceSummary, markAsPaid) — planned-but-unbuilt |
| PayrollRunController | Backend Controller | `app/Modules/HR/Http/Controllers/PayrollRunController.php` | Payroll run lifecycle: process/lock/mark-paid/rollback, salary history | PayrollEngineController | Active | Single-permission gate (`HR_MANAGE_PAYROLL`), identity-only separation of duties |
| SalaryAdvanceController | Backend Controller | `app/Modules/HR/Http/Controllers/SalaryAdvanceController.php` | Staff salary advance request/approve/reject | HR module | Active | Approval creates no `Payment`/disbursement record at all |
| OvertimeController / OvertimeReportController | Backend Controllers | `app/Modules/HR/Http/Controllers/*.php` | Hash-chained overtime ledger, reports | HR module | Active | |
| PayrollDisbursement.vue | Frontend View | `ERP-Frontend/src/modules/finance/views/PayrollDisbursement.vue` (452 lines) | The one HR-payroll screen inside the Finance module tree | axios direct | Active | Sits directly in `finance/views/`, breaking the sub-folder convention every other area follows |
| GUIDES (shared/guides.ts) | Frontend Utility | `ERP-Frontend/src/modules/finance/shared/guides.ts` | Centralised in-page help text | ModuleGuide.vue | Active | |
| Shared UI kit | Frontend Components | `ERP-Frontend/src/modules/finance/shared/components/{FinanceFormField,FinanceFormSection,FinanceModalTabs,FinancePageFrame,FinancePageHeader,LedgerTable,MoneyValue,StatTile,StatusChip}.vue` | Design-system primitives used across every Finance sub-area | finance.css | Active | |
| navigation.spec.ts | Frontend Test | `ERP-Frontend/src/modules/finance/navigation.spec.ts` | Tests `resolveFinanceLocation`/`permittedFinanceSections` | navigation.ts | Active | Only automated test found covering the nav map itself |

---

*Full evidence and file:line citations for every row above are preserved in the Phase 1 research transcripts. This inventory feeds `04_CURRENT_DATA_MODEL.md`, `05_CURRENT_WORKFLOWS.md`, and `06_ACCOUNTING_POSTING_ANALYSIS.md`.*
