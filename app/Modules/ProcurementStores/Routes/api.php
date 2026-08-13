<?php

use Illuminate\Support\Facades\Route;
use App\Modules\ProcurementStores\Controllers\ProcurementStoresController;
use App\Modules\ProcurementStores\Controllers\SupplierController;
use App\Modules\ProcurementStores\Controllers\RequisitionController;
use App\Modules\ProcurementStores\Controllers\PurchaseOrderController;
use App\Modules\ProcurementStores\Controllers\InvoiceController;
use App\Modules\ProcurementStores\Controllers\BillController;
use App\Modules\ProcurementStores\Controllers\GoodsReceiptNoteController;
use App\Modules\ProcurementStores\Controllers\BoardController;
use App\Modules\ProcurementStores\Controllers\BoardRequestController;
use App\Modules\ProcurementStores\Controllers\POVerificationController;

Route::get('/test', [ProcurementStoresController::class, 'test']);
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
Route::get('/outstanding-reusables', [ProcurementStoresController::class, 'outstandingReusables']);
Route::delete('/inventory-logs/{id}', [ProcurementStoresController::class, 'destroyLog']);

// Suppliers
Route::post('/search/suppliers', [SupplierController::class, 'search']);
Route::resource('/suppliers', SupplierController::class);

// Requisitions
Route::post('/search/requisitions', [RequisitionController::class, 'search']);
Route::post('/requisitions/{requisition}/submit', [RequisitionController::class, 'submitForApproval']);
Route::post('/requisitions/{requisition}/approve', [RequisitionController::class, 'approve']);
Route::post('/requisitions/{requisition}/reject', [RequisitionController::class, 'reject']);
Route::resource('/requisitions', RequisitionController::class);

// Purchase Orders
Route::get('/approved-purchase-orders', [PurchaseOrderController::class, 'getApprovedPurchaseOrders']);
Route::post('/search/purchase-orders', [PurchaseOrderController::class, 'search']);
Route::post('/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submitForApproval']);
Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
Route::post('/purchase-orders/{purchaseOrder}/send-email', [PurchaseOrderController::class, 'sendEmail']);
Route::get('/purchase-orders/{purchaseOrder}/download', [PurchaseOrderController::class, 'downloadPdf']);
Route::resource('/purchase-orders', PurchaseOrderController::class);
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
Route::resource('/bills', BillController::class);

// Goods Receipt Notes
Route::get('/goods-receipt-notes', [GoodsReceiptNoteController::class, 'index']);
Route::get('/goods-receipt-notes/search', [GoodsReceiptNoteController::class, 'search']);
Route::get('/goods-receipt-notes/available-purchase-orders', [GoodsReceiptNoteController::class, 'getAvailablePurchaseOrders']);
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
Route::get('/boards/consumption-details',            [BoardController::class, 'consumptionDetails']);

// QR scan lookup — must be before /{id} to avoid conflict
Route::get('/boards/by-code/{trackingCode}',         [BoardController::class, 'showByCode']);

// Query endpoints
Route::get('/boards/available',                      [BoardController::class, 'available']);
Route::get('/boards/job/{jobRef}',                   [BoardController::class, 'byJob']);
Route::post('/boards/job/{jobRef}/calculate-variance', [BoardController::class, 'calculateVariance']);
Route::post('/boards/job/{jobRef}/dispatch-to-station',[BoardController::class, 'dispatchToStation']);
Route::post('/boards/job/{jobRef}/start-wip',          [BoardController::class, 'startWip']);

// Label confirmation
Route::post('/boards/batch/{batchNumber}/confirm-labels', [BoardController::class, 'confirmLabels']);

// Reconciliation
Route::get('/boards/reconciliation/latest',  [BoardController::class, 'latestReconciliation']);
Route::post('/boards/reconciliation',        [BoardController::class, 'saveReconciliation']);

// Lifecycle transitions
Route::post('/boards/{id}/start-processing',         [BoardController::class, 'startProcessing']);
Route::post('/boards/{id}/consume',                  [BoardController::class, 'consume']);
Route::post('/boards/{id}/transition',               [BoardController::class, 'transition']);

// CRUD
Route::get('/boards',                                [BoardController::class, 'index']);
Route::get('/boards/{id}',                           [BoardController::class, 'show']);
Route::put('/boards/{id}',                           [BoardController::class, 'update']);
Route::delete('/boards/{id}',                        [BoardController::class, 'destroy']);
