<?php

use Illuminate\Support\Facades\Route;
use App\Modules\ProcurementStores\Controllers\ProcurementStoresController;

Route::get('/test', [ProcurementStoresController::class, 'test']);
Route::get('/inventory', [ProcurementStoresController::class, 'inventory']);
Route::post('/check-in', [ProcurementStoresController::class, 'checkIn']);
Route::post('/check-out', [ProcurementStoresController::class, 'checkOut']);
Route::post('/update-settings', [ProcurementStoresController::class, 'updateStockSettings']);
Route::post('/returns', [ProcurementStoresController::class, 'returns']);
Route::post('/defective', [ProcurementStoresController::class, 'markDefective']);
Route::get('/inventory-logs', [ProcurementStoresController::class, 'inventoryLogs']);
