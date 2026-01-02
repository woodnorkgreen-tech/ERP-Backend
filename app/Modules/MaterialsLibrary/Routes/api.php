<?php

use Illuminate\Support\Facades\Route;
use App\Modules\MaterialsLibrary\Controllers\WorkstationController;
use App\Modules\MaterialsLibrary\Controllers\MaterialController;
use App\Modules\MaterialsLibrary\Controllers\MaterialImportController;
use App\Modules\MaterialsLibrary\Controllers\MaterialExportController;

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

// Materials
Route::get('materials/trashed', [MaterialController::class, 'trashed']);
Route::post('materials/{id}/restore', [MaterialController::class, 'restore']);
Route::delete('materials/{id}/force', [MaterialController::class, 'forceDelete']);
Route::get('materials/workstation/{workstationId}', [MaterialController::class, 'byWorkstation']);
Route::apiResource('materials', MaterialController::class);

// Import
Route::post('import', [MaterialImportController::class, 'import']);
Route::get('template/{workstationId}', [MaterialExportController::class, 'downloadTemplate']);

