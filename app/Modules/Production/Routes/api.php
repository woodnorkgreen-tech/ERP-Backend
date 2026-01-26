<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Production\Http\Controllers\WorkOrderController;
use App\Modules\Production\Http\Controllers\JobCardController;
use App\Modules\Production\Http\Controllers\ProductionReportsController;

Route::middleware('auth:sanctum')->group(function () {
    // Work Orders
    Route::apiResource('work-orders', WorkOrderController::class);
    Route::get('work-orders/enquiry/{enquiry_id}', [WorkOrderController::class, 'getByEnquiry']);
    Route::post('work-orders/create-for-existing-projects', [WorkOrderController::class, 'createForExistingProjects']);
    Route::get('work-orders/search', [WorkOrderController::class, 'search']);

    // Job Cards
    Route::apiResource('job-cards', JobCardController::class);
    Route::patch('job-cards/{job_card}/status', [JobCardController::class, 'updateStatus']);
    Route::post('job-cards/{id}/release', [JobCardController::class, 'release']);
    Route::post('job-cards/{id}/start', [JobCardController::class, 'start']);
    Route::post('job-cards/{id}/complete', [JobCardController::class, 'complete']);
    Route::post('job-cards/{id}/hold', [JobCardController::class, 'putOnHold']);
    Route::post('job-cards/{id}/submit-for-approval', [JobCardController::class, 'submitForApproval']);
    Route::post('job-cards/{id}/approve', [JobCardController::class, 'approve']);
    Route::post('job-cards/{id}/reject', [JobCardController::class, 'reject']);
    Route::get('job-cards/search-work-orders', [JobCardController::class, 'searchWorkOrders']);

    // Production Reports
    Route::get('reports/technician/{technician_id}', [ProductionReportsController::class, 'technicianReport']);
    Route::get('analytics', [ProductionReportsController::class, 'analytics']);

    // Reference Data
    Route::get('technicians', [JobCardController::class, 'technicians']);
    Route::get('work-centers', [JobCardController::class, 'workCenters']);
});
