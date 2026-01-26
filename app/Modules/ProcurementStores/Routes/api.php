<?php

use Illuminate\Support\Facades\Route;
use App\Modules\ProcurementStores\Controllers\ProcurementStoresController;
use App\Modules\ProcurementStores\Controllers\SupplierController;
use App\Modules\ProcurementStores\Controllers\RequisitionController;
use App\Modules\ProcurementStores\Controllers\PurchaseOrderController;
use App\Modules\ProcurementStores\Controllers\InvoiceController;
use App\Modules\ProcurementStores\Controllers\BillController;


Route::get('/test', [ProcurementStoresController::class, 'test']);
Route::get('/inventory', [ProcurementStoresController::class, 'inventory']);
Route::post('/check-in', [ProcurementStoresController::class, 'checkIn']);
Route::post('/check-out', [ProcurementStoresController::class, 'checkOut']);
Route::post('/update-settings', [ProcurementStoresController::class, 'updateStockSettings']);
Route::post('/returns', [ProcurementStoresController::class, 'returns']);
Route::post('/defective', [ProcurementStoresController::class, 'markDefective']);
Route::get('/inventory-logs', [ProcurementStoresController::class, 'inventoryLogs']);

// Suppliers
Route::post('/search/suppliers', [SupplierController::class, 'search']);
Route::resource('/suppliers', SupplierController::class);

// Requisitions
Route::post('/search/requisitions', [RequisitionController::class, 'search']);
Route::post('/requisitions/{requisition}/submit', [RequisitionController::class, 'submitForApproval']);
Route::post('/requisitions/{requisition}/approve', [RequisitionController::class, 'approve']);
Route::post('/requisitions/{requisition}/reject', [RequisitionController::class, 'reject']);
Route::resource('/requisitions', RequisitionController::class);

// Purchase Orders - ADD THIS LINE
Route::get('/approved-purchase-orders', [PurchaseOrderController::class, 'getApprovedPurchaseOrders']);
Route::post('/search/purchase-orders', [PurchaseOrderController::class, 'search']);
Route::post('/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submitForApproval']);
Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
Route::post('/purchase-orders/{purchaseOrder}/send-email', [PurchaseOrderController::class, 'sendEmail']);
Route::resource('/purchase-orders', PurchaseOrderController::class);
Route::get('/purchase-orders/link/{requisition}', [PurchaseOrderController::class, 'link'])->name('purchase-orders.link');
Route::post('/purchase-orders/store-linked', [PurchaseOrderController::class, 'storeLinked'])->name('purchase-orders.storeLinked');

Route::post(
    '/purchase-orders/store-linked',
    [PurchaseOrderController::class, 'storeLinked']
)->name('purchase-orders.storeLinked');
// Bills - Specific routes FIRST (before resource)
Route::get('/bills-stats', [BillController::class, 'stats']);
Route::get('/pending-bills', [BillController::class, 'getPendingBills']);
Route::get('/payment-methods', [BillController::class, 'getPaymentMethods']);
Route::post('/payment-methods', [BillController::class, 'storePaymentMethod']);
Route::post('/search/bills', [BillController::class, 'search']);
Route::post('/bills/{bill}/record-payment', [BillController::class, 'recordPayment']);

// Bills - Resource route LAST
Route::resource('/bills', BillController::class);