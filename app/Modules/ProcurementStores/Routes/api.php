<?php

use Illuminate\Support\Facades\Route;
use App\Modules\ProcurementStores\Controllers\ProcurementStoresController;
use App\Modules\ProcurementStores\Controllers\SupplierController;
use App\Modules\ProcurementStores\Controllers\RequisitionController;
use App\Modules\ProcurementStores\Controllers\PurchaseOrderController;
use App\Modules\ProcurementStores\Controllers\BillController;
use App\Modules\ProcurementStores\Controllers\GoodsReceiptNoteController;
use App\Modules\ProcurementStores\Controllers\BoardController;
use App\Modules\ProcurementStores\Controllers\BoardRequestController;
use App\Modules\ProcurementStores\Controllers\StockCountController;
use App\Modules\ProcurementStores\Controllers\StoresResetController;
use App\Modules\ProcurementStores\Controllers\GoodsReceiptInspectionController;

// apiResource, not resource: `create` and `edit` return HTML form scaffolding,
// which no controller here implements and no client asks for. Registering them
// published eight routes that would fatal on a method-not-found if anything ever
// reached them.
Route::get('/test', [ProcurementStoresController::class, 'test']);
Route::post('/inventory/check-availability', [ProcurementStoresController::class, 'checkAvailability']);
Route::get('/inventory', [ProcurementStoresController::class, 'inventory']);
Route::get('/inventory/{material}/control-options', [ProcurementStoresController::class, 'controlOptions']);
Route::post('/check-in', [ProcurementStoresController::class, 'checkIn']);
Route::post('/check-out', [ProcurementStoresController::class, 'checkOut']);
Route::post('/batch-check-in', [ProcurementStoresController::class, 'batchCheckIn']);
Route::post('/batch-check-out', [ProcurementStoresController::class, 'batchCheckOut']);
Route::post('/update-settings', [ProcurementStoresController::class, 'updateStockSettings']);
Route::post('/returns', [ProcurementStoresController::class, 'returns']);
Route::post('/defective', [ProcurementStoresController::class, 'markDefective']);
Route::get('/inventory-logs', [ProcurementStoresController::class, 'inventoryLogs']);
Route::get('/inventory-logs/pdf', [ProcurementStoresController::class, 'inventoryLogsPdf']);
Route::get('/material-ledger', [ProcurementStoresController::class, 'materialLedger']);
Route::get('/outstanding-reusables', [ProcurementStoresController::class, 'outstandingReusables']);
Route::get('/material-demand-forecast', [ProcurementStoresController::class, 'materialDemandForecast']);
Route::get('/finance-sync-exceptions', [ProcurementStoresController::class, 'financeSyncExceptions']);
Route::post('/finance-sync-exceptions/{inventoryLog}/retry', [ProcurementStoresController::class, 'retryFinanceSync']);
Route::post('/finance-sync-exceptions/{inventoryLog}/resolve-valuation', [ProcurementStoresController::class, 'resolveFinanceValuation']);
Route::delete('/inventory-logs/{id}', [ProcurementStoresController::class, 'destroyLog']);
Route::post('/inventory-logs/{inventoryLog}/link-project-material', [ProcurementStoresController::class, 'linkProjectMaterial']);
Route::get('/stock-counts', [StockCountController::class, 'index']);
Route::post('/stock-counts', [StockCountController::class, 'store']);
Route::get('/stock-counts/{stockCount}', [StockCountController::class, 'show']);
Route::put('/stock-counts/{stockCount}', [StockCountController::class, 'update']);
Route::post('/stock-counts/{stockCount}/submit', [StockCountController::class, 'submit']);
Route::post('/stock-counts/{stockCount}/approve', [StockCountController::class, 'approve']);
Route::post('/stock-counts/{stockCount}/reject', [StockCountController::class, 'reject']);
Route::delete('/stock-counts/{stockCount}', [StockCountController::class, 'destroy']);
Route::get('/stores-reset/preview', [StoresResetController::class, 'preview']);
Route::post('/stores-reset', [StoresResetController::class, 'store']);
Route::get('/goods-receipt-inspections', [GoodsReceiptInspectionController::class, 'index']);
Route::post('/goods-receipt-inspections/{item}/resolve', [GoodsReceiptInspectionController::class, 'resolve']);

// Suppliers
Route::post('/search/suppliers', [SupplierController::class, 'search']);
Route::apiResource('/suppliers', SupplierController::class);

// Requisitions
Route::post('/search/requisitions', [RequisitionController::class, 'search']);
Route::post('/requisitions/{requisition}/submit', [RequisitionController::class, 'submitForApproval']);
Route::post('/requisitions/{requisition}/approve', [RequisitionController::class, 'approve']);
Route::post('/requisitions/{requisition}/reject', [RequisitionController::class, 'reject']);
Route::apiResource('/requisitions', RequisitionController::class);

// Purchase Orders
Route::get('/approved-purchase-orders', [PurchaseOrderController::class, 'getApprovedPurchaseOrders']);
Route::post('/search/purchase-orders', [PurchaseOrderController::class, 'search']);
Route::post('/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submitForApproval']);
Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
Route::post('/purchase-orders/{purchaseOrder}/send-email', [PurchaseOrderController::class, 'sendEmail']);
Route::get('/purchase-orders/{purchaseOrder}/download', [PurchaseOrderController::class, 'downloadPdf']);
Route::apiResource('/purchase-orders', PurchaseOrderController::class);
Route::get('/purchase-orders/link/{requisition}', [PurchaseOrderController::class, 'link'])->name('purchase-orders.link');
Route::post('/purchase-orders/store-linked', [PurchaseOrderController::class, 'storeLinked'])->name('purchase-orders.storeLinked');

// Bills - Specific routes FIRST (before resource)
Route::get('/bills-stats', [BillController::class, 'stats']);
Route::get('/pending-bills', [BillController::class, 'getPendingBills']);
Route::get('/payment-methods', [BillController::class, 'getPaymentMethods']);
Route::post('/payment-methods', [BillController::class, 'storePaymentMethod']);
Route::post('/search/bills', [BillController::class, 'search']);
Route::post('/bills/{bill}/record-payment', [BillController::class, 'recordPayment']);
Route::get('/bills/{bill}/download', [BillController::class, 'downloadPdf']);
Route::post('/multi-payment', [BillController::class, 'recordMultiBillPayment']);

// Bills - Resource route LAST
Route::apiResource('/bills', BillController::class);

// Goods Receipt Notes
Route::get('/goods-receipt-notes', [GoodsReceiptNoteController::class, 'index']);
Route::get('/goods-receipt-notes/search', [GoodsReceiptNoteController::class, 'search']);
Route::get('/goods-receipt-notes/available-purchase-orders', [GoodsReceiptNoteController::class, 'getAvailablePurchaseOrders']);
Route::get('/goods-receipt-notes/receiving-queue', [GoodsReceiptNoteController::class, 'receivingQueue']);
Route::get('/goods-receipt-notes/pending-confirmations', [GoodsReceiptNoteController::class, 'pendingConfirmations']);
Route::get('/goods-receipt-notes/pending-confirmations-count', [GoodsReceiptNoteController::class, 'pendingConfirmationsCount']);
Route::post('/goods-receipt-note-items/{grnItem}/confirm', [GoodsReceiptNoteController::class, 'confirmItem']);
Route::get('/goods-receipt-notes/{id}/download', [GoodsReceiptNoteController::class, 'downloadPdf']);
Route::get('/goods-receipt-notes/{id}', [GoodsReceiptNoteController::class, 'show']);
Route::post('/goods-receipt-notes', [GoodsReceiptNoteController::class, 'store']);
Route::put('/goods-receipt-notes/{id}', [GoodsReceiptNoteController::class, 'update']);
Route::delete('/goods-receipt-notes/{id}', [GoodsReceiptNoteController::class, 'destroy']);

// ── Board Requests (MRF) ────────────────────────────────────────────────────
Route::get('/board-requests',                        [BoardRequestController::class, 'index']);
Route::post('/board-requests',                       [BoardRequestController::class, 'store']);
Route::post('/board-requests/{id}/fulfil',           [BoardRequestController::class, 'fulfil']);
Route::delete('/board-requests/{id}',                [BoardRequestController::class, 'cancel']);

// ── Board Tracking ──────────────────────────────────────────────────────────
// Specific routes BEFORE parameterised {id} to avoid conflicts

Route::post('/boards/ingest',                        [BoardController::class, 'ingest']);

// Workflow task inbox (role-filtered — must be before /{id} params)
Route::get('/boards/workflow-tasks',                        [BoardController::class, 'workflowTasks']);
Route::post('/boards/workflow-tasks/{taskId}/claim',        [BoardController::class, 'claimWorkflowTask']);
Route::post('/boards/workflow-tasks/{taskId}/return-offcut',[BoardController::class, 'returnOffcut']);

// Production WIP board list
Route::get('/boards/my-wip',                               [BoardController::class, 'myWipBoards']);

// Dashboard / analytics
Route::get('/boards/command-center-metrics',         [BoardController::class, 'commandCenterMetrics']);
Route::get('/boards/stock-registry',                 [BoardController::class, 'stockRegistry']);
Route::get('/boards/compliance-exceptions',          [BoardController::class, 'complianceExceptions']);
Route::get('/boards/quarantine-returns',             [BoardController::class, 'quarantineReturns']);
Route::post('/boards/{id}/review-quarantine-return', [BoardController::class, 'reviewQuarantineReturn']);
Route::get('/boards/consumption-details',            [BoardController::class, 'consumptionDetails']);

// Receipt valuation — repairs boards received without a price so they can be issued
Route::get('/boards/unvalued',                       [BoardController::class, 'unvalued']);
Route::post('/boards/record-valuation',              [BoardController::class, 'recordValuation']);

// QR scan lookup — must be before /{id} to avoid conflict
Route::get('/boards/by-code/{trackingCode}',         [BoardController::class, 'showByCode']);

// Query endpoints
Route::get('/boards/available',                      [BoardController::class, 'available']);
Route::get('/boards/job/{jobRef}/history',           [BoardController::class, 'jobHistory']);
Route::get('/boards/job/{jobRef}',                   [BoardController::class, 'byJob']);
Route::post('/boards/job/{jobRef}/calculate-variance', [BoardController::class, 'calculateVariance']);
Route::post('/boards/job/{jobRef}/dispatch-to-station',[BoardController::class, 'dispatchToStation']);
Route::post('/boards/job/{jobRef}/start-wip',          [BoardController::class, 'startWip']);
Route::post('/boards/job/{jobRef}/bulk-return',        [BoardController::class, 'bulkReturn']);
Route::post('/boards/job/{jobRef}/return-batches',     [BoardController::class, 'initiateReturnBatch']);
Route::get('/boards/return-batches',                   [BoardController::class, 'returnBatches']);
Route::post('/boards/return-batches/{batch}/mark-missing', [BoardController::class, 'markReturnBatchMissing']);

// Label confirmation
Route::post('/boards/batch/{batchNumber}/confirm-labels', [BoardController::class, 'confirmLabels']);
Route::post('/boards/{id}/confirm-label',             [BoardController::class, 'confirmBoardLabel']);

// Reconciliation
Route::get('/boards/reconciliation/latest',  [BoardController::class, 'latestReconciliation']);
Route::post('/boards/reconciliation',        [BoardController::class, 'saveReconciliation']);

// Lifecycle transitions
Route::post('/boards/{id}/start-processing',         [BoardController::class, 'startProcessing']);
Route::post('/boards/{id}/consume',                  [BoardController::class, 'consume']);
Route::post('/boards/{id}/initiate-return',           [BoardController::class, 'initiateReturn']);
Route::post('/boards/{id}/receive-return',            [BoardController::class, 'receiveReturn']);
Route::post('/boards/{id}/note',                      [BoardController::class, 'addNote']);
Route::post('/boards/{id}/transition',               [BoardController::class, 'transition']);

// CRUD
Route::get('/boards',                                [BoardController::class, 'index']);
Route::get('/boards/{id}',                           [BoardController::class, 'show']);
Route::put('/boards/{id}',                           [BoardController::class, 'update']);
Route::delete('/boards/{id}',                        [BoardController::class, 'destroy']);
