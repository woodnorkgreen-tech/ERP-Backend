<?php

use App\Modules\Printing\Controllers\PrintJobController;
use App\Modules\Printing\Controllers\PrintLookupController;
use App\Modules\Printing\Controllers\PrintManualConsumptionController;
use App\Modules\Printing\Controllers\PrintMaterialRequestController;
use App\Modules\Printing\Controllers\PrintRollController;
use App\Modules\Printing\Controllers\PrintingDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard', [PrintingDashboardController::class, 'index']);
Route::get('/dashboard/project-usage', [PrintingDashboardController::class, 'projectUsage']);

Route::get('/jobs', [PrintJobController::class, 'index']);
Route::get('/jobs/{job}', [PrintJobController::class, 'show']);
Route::put('/jobs/{job}', [PrintJobController::class, 'update']);
Route::post('/jobs/{job}/status', [PrintJobController::class, 'status']);
Route::post('/jobs/{job}/complete', [PrintJobController::class, 'complete']);
Route::post('/jobs/{job}/reprint', [PrintJobController::class, 'reprint']);
Route::post('/jobs/{job}/correction', [PrintJobController::class, 'correction']);
Route::get('/jobs/{job}/consumption', [PrintJobController::class, 'consumptions']);
Route::post('/jobs/{job}/consumption', [PrintJobController::class, 'saveConsumption']);

Route::get('/rolls', [PrintRollController::class, 'index']);
Route::post('/rolls', [PrintRollController::class, 'store']);
Route::get('/rolls/{roll}', [PrintRollController::class, 'show']);
Route::put('/rolls/{roll}', [PrintRollController::class, 'update']);
Route::delete('/rolls/{roll}', [PrintRollController::class, 'destroy']);
Route::post('/rolls/{roll}/adjust', [PrintRollController::class, 'adjust']);

Route::get('/material-requests', [PrintMaterialRequestController::class, 'index']);
Route::post('/material-requests', [PrintMaterialRequestController::class, 'store']);
Route::delete('/material-requests/{materialRequest}', [PrintMaterialRequestController::class, 'destroy']);
Route::post('/material-requests/{materialRequest}/receive', [PrintMaterialRequestController::class, 'receive']);

Route::get('/consumption/manual', [PrintManualConsumptionController::class, 'index']);
Route::post('/consumption/manual', [PrintManualConsumptionController::class, 'store']);

Route::get('/lookups/materials', [PrintLookupController::class, 'materials']);
Route::get('/lookups/machines', [PrintLookupController::class, 'machines']);
Route::get('/lookups/operators', [PrintLookupController::class, 'operators']);
