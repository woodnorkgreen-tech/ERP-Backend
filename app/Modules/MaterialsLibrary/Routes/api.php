<?php

use Illuminate\Support\Facades\Route;
use App\Modules\MaterialsLibrary\Controllers\WorkstationController;
use App\Modules\MaterialsLibrary\Controllers\MaterialController;
use App\Modules\MaterialsLibrary\Controllers\MaterialImportController;
use App\Modules\MaterialsLibrary\Controllers\MaterialExportController;
use App\Modules\MaterialsLibrary\Controllers\CategoryController;
use App\Modules\MaterialsLibrary\Controllers\ReferenceDataController;

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
Route::get('workstations', [WorkstationController::class, 'index']);
Route::get('workstations/{id}/schema', [WorkstationController::class, 'schema']);
Route::get('workstations/{id}', [WorkstationController::class, 'show']);
Route::get('reference/item-types', [ReferenceDataController::class, 'itemTypes']);
Route::get('reference/units-of-measure', [ReferenceDataController::class, 'unitsOfMeasure']);

// Materials
Route::get('materials/trashed', [MaterialController::class, 'trashed']);
Route::post('materials/{id}/restore', [MaterialController::class, 'restore']);
Route::delete('materials/{id}/force', [MaterialController::class, 'forceDelete']);
Route::get('materials/workstation/{workstationId}', [MaterialController::class, 'byWorkstation']);
Route::apiResource('materials', MaterialController::class);

// Categories
Route::get('categories/tree',    [CategoryController::class, 'tree']);
Route::get('categories/{id}/schema', [CategoryController::class, 'schema']);
Route::get('categories/{id}/normalization-preview', [CategoryController::class, 'normalizationPreview']);
Route::post('categories/{id}/normalize-attributes', [CategoryController::class, 'normalizeAttributes']);
Route::post('categories/normalization-runs/{runId}/rollback', [CategoryController::class, 'rollbackNormalization']);
Route::post('categories',        [CategoryController::class, 'store']);
Route::put('categories/{id}',    [CategoryController::class, 'update']);
Route::post('categories/{id}/merge', [CategoryController::class, 'merge']);
Route::get('categories/suggest-code', [CategoryController::class, 'suggestCode']);
Route::get('categories',         [CategoryController::class, 'index']);

// Import
Route::post('import', [MaterialImportController::class, 'import']);
Route::get('template/{workstationId}', [MaterialExportController::class, 'downloadTemplate']);
