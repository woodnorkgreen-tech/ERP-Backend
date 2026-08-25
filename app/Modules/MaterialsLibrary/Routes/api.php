<?php

use Illuminate\Support\Facades\Route;
use App\Modules\MaterialsLibrary\Controllers\WorkstationController;
use App\Modules\MaterialsLibrary\Controllers\MaterialController;
use App\Modules\MaterialsLibrary\Controllers\MaterialImportController;
use App\Modules\MaterialsLibrary\Controllers\MaterialExportController;
use App\Modules\MaterialsLibrary\Controllers\CategoryController;
use App\Modules\MaterialsLibrary\Controllers\ReferenceDataController;
use App\Constants\Permissions;

/*
|--------------------------------------------------------------------------
| Materials Library API Routes
|--------------------------------------------------------------------------
|
| Here are the API routes for the Materials Library module.
| Routes are automatically prefixed with /api/materials-library
| and protected with auth:sanctum middleware.
|
*/

// Workstations
Route::middleware('permission:'.Permissions::MATERIALS_LIBRARY_VIEW)->group(function () {
    Route::get('workstations', [WorkstationController::class, 'index']);
    Route::get('workstations/{id}/schema', [WorkstationController::class, 'schema']);
    Route::get('workstations/{id}', [WorkstationController::class, 'show']);
    Route::get('reference/item-types', [ReferenceDataController::class, 'itemTypes']);
    Route::get('reference/units-of-measure', [ReferenceDataController::class, 'unitsOfMeasure']);
    Route::get('materials/incomplete', [MaterialController::class, 'incomplete']);
    Route::get('materials/trashed', [MaterialController::class, 'trashed']);
    Route::get('materials/workstation/{workstationId}', [MaterialController::class, 'byWorkstation']);
    Route::get('materials', [MaterialController::class, 'index']);
    Route::get('materials/{material}', [MaterialController::class, 'show']);
    Route::get('categories/tree', [CategoryController::class, 'tree']);
    Route::get('categories/{id}/schema', [CategoryController::class, 'schema']);
    Route::get('categories/{id}/normalization-preview', [CategoryController::class, 'normalizationPreview']);
    Route::get('categories/suggest-code', [CategoryController::class, 'suggestCode']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('template/{workstationId}', [MaterialExportController::class, 'downloadTemplate']);
});

// Materials
// Specific routes before apiResource so /materials/{id} never swallows them.
Route::middleware('permission:'.Permissions::MATERIALS_LIBRARY_MANAGE)->group(function () {
    Route::post('materials/bulk-controls', [MaterialController::class, 'bulkControls']);
    Route::post('materials/{id}/activate', [MaterialController::class, 'activate']);
    Route::post('materials/{id}/restore', [MaterialController::class, 'restore']);
    Route::delete('materials/{id}/force', [MaterialController::class, 'forceDelete']);
    Route::post('materials', [MaterialController::class, 'store']);
    Route::put('materials/{material}', [MaterialController::class, 'update']);
    Route::patch('materials/{material}', [MaterialController::class, 'update']);
    Route::delete('materials/{material}', [MaterialController::class, 'destroy']);
});

// Categories
Route::middleware('permission:'.Permissions::MATERIALS_LIBRARY_MANAGE)->group(function () {
    Route::post('categories/{id}/normalize-attributes', [CategoryController::class, 'normalizeAttributes']);
    Route::post('categories/normalization-runs/{runId}/rollback', [CategoryController::class, 'rollbackNormalization']);
    Route::post('categories', [CategoryController::class, 'store']);
    Route::put('categories/{id}', [CategoryController::class, 'update']);
    Route::post('categories/{id}/merge', [CategoryController::class, 'merge']);
});

// Import
Route::post('import', [MaterialImportController::class, 'import'])
    ->middleware('permission:'.Permissions::MATERIALS_LIBRARY_IMPORT);
