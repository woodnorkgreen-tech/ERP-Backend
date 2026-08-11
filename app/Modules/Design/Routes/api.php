<?php

use App\Modules\Design\Controllers\DesignBomItemController;
use App\Modules\Design\Controllers\DesignDashboardController;
use App\Modules\Design\Controllers\DesignDocumentController;
use App\Modules\Design\Controllers\DesignHandoffController;
use App\Modules\Design\Controllers\DesignItemController;
use App\Modules\Design\Controllers\DesignJobController;
use App\Modules\Design\Controllers\DesignTypeController;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard', [DesignDashboardController::class, 'index']);

Route::get('/jobs', [DesignJobController::class, 'index']);
Route::post('/jobs', [DesignJobController::class, 'store']);
Route::post('/jobs/sync-from-project/{enquiry}', [DesignJobController::class, 'syncFromProject']);
Route::post('/jobs/sync-upcoming', [DesignJobController::class, 'syncUpcoming']);
Route::get('/jobs/{job}', [DesignJobController::class, 'show']);
Route::put('/jobs/{job}', [DesignJobController::class, 'update']);

Route::get('/designers', [DesignItemController::class, 'designers']);

Route::get('/types', [DesignTypeController::class, 'index']);
Route::post('/types', [DesignTypeController::class, 'store']);
Route::put('/types/{type}', [DesignTypeController::class, 'update']);
Route::delete('/types/{type}', [DesignTypeController::class, 'destroy']);

Route::get('/graphic/items', [DesignItemController::class, 'index'])->defaults('stream', 'graphic');
Route::post('/jobs/{job}/graphic/items', [DesignItemController::class, 'store'])->defaults('stream', 'graphic');
Route::put('/graphic/items/{item}', [DesignItemController::class, 'update']);
Route::delete('/graphic/items/{item}', [DesignItemController::class, 'destroy']);
Route::post('/graphic/items/{item}/mark-print-ready', [DesignItemController::class, 'markPrintReady']);
Route::get('/graphic/print-ready', [DesignItemController::class, 'index'])->defaults('stream', 'graphic')->defaults('status', 'print_ready');

Route::get('/structural/items', [DesignItemController::class, 'index'])->defaults('stream', 'structural');
Route::post('/jobs/{job}/structural/items', [DesignItemController::class, 'store'])->defaults('stream', 'structural');
Route::put('/structural/items/{item}', [DesignItemController::class, 'update']);
Route::delete('/structural/items/{item}', [DesignItemController::class, 'destroy']);
Route::post('/structural/items/{item}/mark-production-ready', [DesignItemController::class, 'markProductionReady']);
Route::get('/structural/production-ready', [DesignItemController::class, 'index'])->defaults('stream', 'structural')->defaults('status', 'production_ready');

Route::get('/structural/items/{item}/bom', [DesignBomItemController::class, 'index']);
Route::post('/structural/items/{item}/bom', [DesignBomItemController::class, 'store']);
Route::put('/bom-items/{bomItem}', [DesignBomItemController::class, 'update']);
Route::delete('/bom-items/{bomItem}', [DesignBomItemController::class, 'destroy']);

Route::post('/documents', [DesignDocumentController::class, 'store']);
Route::post('/documents/link', [DesignDocumentController::class, 'storeLink']);
Route::get('/documents/{document}/download', [DesignDocumentController::class, 'download']);
Route::delete('/documents/{document}', [DesignDocumentController::class, 'destroy']);

Route::post('/items/{item}/handoff', [DesignHandoffController::class, 'store']);
