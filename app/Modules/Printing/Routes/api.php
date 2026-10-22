<?php

use App\Modules\Printing\Controllers\PrintHistoryImportController;
use App\Modules\Printing\Controllers\PrintJobController;
use App\Modules\Printing\Controllers\PrintProjectScheduleController;
use App\Modules\Printing\Controllers\PrintLookupController;
use App\Modules\Printing\Controllers\PrintManualConsumptionController;
use App\Modules\Printing\Controllers\PrintMaterialRequestController;
use App\Modules\Printing\Controllers\PrintRollController;
use App\Modules\Printing\Controllers\PrintingDashboardController;
use App\Modules\Printing\Controllers\UpcomingPrintJobController;
use App\Modules\Printing\Http\Middleware\AuthorizePrinting;
use Illuminate\Support\Facades\Route;

$printing = static fn (string $permission, string $scope = 'staff'): string => AuthorizePrinting::class.':'.$permission.','.$scope;

Route::middleware($printing('printing.dashboard.view'))->group(function () {
    Route::get('/dashboard', [PrintingDashboardController::class, 'index']);
    Route::get('/dashboard/project-usage', [PrintingDashboardController::class, 'projectUsage']);
    Route::get('/dashboard/monthly-consumption', [PrintingDashboardController::class, 'monthlyConsumption']);
});

Route::middleware($printing('printing.history.import', 'lead'))->group(function () {
    Route::post('/history-imports/preview', [PrintHistoryImportController::class, 'preview']);
    Route::post('/history-imports/{batchId}/commit', [PrintHistoryImportController::class, 'commit']);
});

Route::middleware($printing('printing.jobs.view'))->group(function () {
    Route::get('/jobs', [PrintJobController::class, 'index']);
    Route::get('/job-bundles', [PrintJobController::class, 'bundles']);
    Route::get('/upcoming-jobs', [UpcomingPrintJobController::class, 'index']);
    Route::get('/upcoming-jobs/timeline', [PrintProjectScheduleController::class, 'timeline']);
    Route::get('/upcoming-jobs/report', [PrintProjectScheduleController::class, 'report']);
    Route::get('/jobs/{job}', [PrintJobController::class, 'show']);
    Route::get('/jobs/{job}/consumption', [PrintJobController::class, 'consumptions']);
});
Route::patch('/upcoming-jobs/{designJob}/schedule', [PrintProjectScheduleController::class, 'update'])
    ->middleware($printing('printing.jobs.update'));
Route::post('/jobs', [PrintJobController::class, 'store'])->middleware($printing('printing.jobs.create'));
Route::put('/jobs/{job}', [PrintJobController::class, 'update'])->middleware($printing('printing.jobs.update'));
Route::post('/jobs/{job}/status', [PrintJobController::class, 'status'])->middleware($printing('printing.jobs.update'));
Route::post('/jobs/{job}/complete', [PrintJobController::class, 'complete'])->middleware($printing('printing.jobs.work'));
Route::post('/jobs/{job}/work/begin', [PrintJobController::class, 'beginWork'])->middleware($printing('printing.jobs.work'));
Route::post('/jobs/{job}/work/pause', [PrintJobController::class, 'pauseWork'])->middleware($printing('printing.jobs.work'));
Route::post('/jobs/{job}/acknowledge-stop', [PrintJobController::class, 'acknowledgeStop'])->middleware($printing('printing.jobs.work'));
Route::post('/jobs/{job}/reprint', [PrintJobController::class, 'reprint'])->middleware($printing('printing.jobs.reprint', 'lead'));
Route::post('/jobs/{job}/redesign', [PrintJobController::class, 'redesign'])->middleware($printing('printing.jobs.reprint', 'lead'));
Route::post('/jobs/{job}/correction', [PrintJobController::class, 'correction'])->middleware($printing('printing.jobs.correct', 'lead'));
Route::post('/jobs/{job}/consumption', [PrintJobController::class, 'saveConsumption'])->middleware($printing('printing.materials.consume'));

Route::get('/rolls', [PrintRollController::class, 'index'])->middleware($printing('printing.materials.view'));
Route::get('/rolls/{roll}', [PrintRollController::class, 'show'])->middleware($printing('printing.materials.view'));
Route::post('/rolls', [PrintRollController::class, 'store'])->middleware($printing('printing.rolls.create', 'lead'));
Route::put('/rolls/{roll}', [PrintRollController::class, 'update'])->middleware($printing('printing.rolls.update', 'lead'));
Route::delete('/rolls/{roll}', [PrintRollController::class, 'destroy'])->middleware($printing('printing.rolls.delete', 'lead'));
Route::post('/rolls/{roll}/adjust', [PrintRollController::class, 'adjust'])->middleware($printing('printing.rolls.adjust', 'lead'));

Route::get('/material-requests', [PrintMaterialRequestController::class, 'index'])->middleware($printing('printing.materials.view', 'stores-read'));
Route::post('/material-requests', [PrintMaterialRequestController::class, 'store'])->middleware($printing('printing.materials.request'));
Route::delete('/material-requests/{materialRequest}', [PrintMaterialRequestController::class, 'destroy'])->middleware($printing('printing.materials.requests.delete', 'lead'));
Route::post('/material-requests/{materialRequest}/receive', [PrintMaterialRequestController::class, 'receive'])->middleware($printing('printing.materials.view', 'stores-read'));
Route::post('/material-requests/{materialRequest}/issue', [PrintMaterialRequestController::class, 'issue'])->middleware($printing('printing.materials.view', 'stores'));
Route::post('/material-requests/{materialRequest}/awaiting-purchase', [PrintMaterialRequestController::class, 'awaitingPurchase'])->middleware($printing('printing.materials.view', 'stores'));

Route::get('/consumption/manual', [PrintManualConsumptionController::class, 'index'])->middleware($printing('printing.materials.view'));
Route::post('/consumption/manual', [PrintManualConsumptionController::class, 'store'])->middleware($printing('printing.materials.consume'));

Route::middleware($printing('printing.materials.view'))->group(function () {
    Route::get('/lookups/materials', [PrintLookupController::class, 'materials']);
    Route::get('/lookups/machines', [PrintLookupController::class, 'machines']);
});
Route::get('/lookups/operators', [PrintLookupController::class, 'operators'])->middleware($printing('printing.jobs.view'));
Route::get('/lookups/projects', [PrintLookupController::class, 'projects'])->middleware($printing('printing.jobs.view'));
