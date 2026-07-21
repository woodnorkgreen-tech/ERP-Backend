<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Assets\Controllers\AssetController;
use App\Modules\Assets\Controllers\AssetCategoryController;
use App\Modules\Assets\Controllers\AssetImportController;
use App\Modules\Assets\Controllers\AssetExportController;
use App\Modules\Assets\Controllers\AssetHireRequestController;
use App\Modules\Assets\Controllers\AssetMovementLogController;
use App\Modules\Assets\Controllers\AssetClientPortalController;
use App\Modules\Assets\Controllers\AssetServiceLogController;

/*
|--------------------------------------------------------------------------
| Assets API Routes
|--------------------------------------------------------------------------
|
| Here are the API routes for the Assets (Asset Register) module.
| Routes are automatically prefixed with /api/assets
| and protected with auth:sanctum middleware.
|
*/

Route::get('/categories', [AssetCategoryController::class, 'index']);
Route::post('/categories', [AssetCategoryController::class, 'store']);
Route::put('/categories/{id}', [AssetCategoryController::class, 'update']);
Route::delete('/categories/{id}', [AssetCategoryController::class, 'destroy']);

Route::post('/import', [AssetImportController::class, 'import']);
Route::get('/import/template', [AssetExportController::class, 'downloadTemplate']);
Route::post('/bulk-delete', [AssetController::class, 'bulkDelete']);

Route::get('/hire-requests', [AssetHireRequestController::class, 'index']);
Route::post('/hire-requests', [AssetHireRequestController::class, 'store']);
Route::get('/hire-requests/{id}', [AssetHireRequestController::class, 'show']);
Route::post('/hire-requests/{id}/approve', [AssetHireRequestController::class, 'approve']);
Route::post('/hire-requests/{id}/reject', [AssetHireRequestController::class, 'reject']);
Route::post('/hire-requests/{id}/cancel', [AssetHireRequestController::class, 'cancel']);
Route::post('/hire-requests/{id}/mark-returned', [AssetHireRequestController::class, 'markReturned']);

Route::get('/movement-log', [AssetMovementLogController::class, 'index']);
Route::get('/{id}/hire-history', [AssetHireRequestController::class, 'history']);
Route::get('/{id}/service-logs', [AssetServiceLogController::class, 'index']);
Route::post('/{id}/service-logs', [AssetServiceLogController::class, 'store']);
Route::delete('/{id}/service-logs/{logId}', [AssetServiceLogController::class, 'destroy']);
Route::get('/client-portal/clients', [AssetClientPortalController::class, 'clients']);
Route::get('/client-portal/assets', [AssetClientPortalController::class, 'clientAssets']);

Route::get('/trashed', [AssetController::class, 'trashed']);
Route::post('/{id}/restore', [AssetController::class, 'restore']);
Route::patch('/{id}/availability', [AssetController::class, 'toggleAvailability']);

Route::get('/', [AssetController::class, 'index']);
Route::post('/', [AssetController::class, 'store']);
Route::get('/{id}', [AssetController::class, 'show']);
Route::put('/{id}', [AssetController::class, 'update']);
Route::delete('/{id}', [AssetController::class, 'destroy']);
