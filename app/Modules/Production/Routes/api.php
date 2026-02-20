<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Production\Http\Controllers\WorkOrderController;
use App\Modules\Production\Http\Controllers\JobCardController;
use App\Modules\Production\Http\Controllers\ProductionReportsController;
use App\Modules\Production\Http\Controllers\WorkOrderScrapLogController;
use App\Modules\Production\Http\Controllers\WorkOrderTaskController;
use App\Modules\Production\Http\Controllers\ProductionAssigneeController;
use App\Modules\Production\Http\Controllers\WorkOrderMidQcController;
use App\Modules\Production\Http\Controllers\WorkOrderTaskEvidenceController;
use App\Modules\Production\Http\Controllers\WorkOrderFinalQcController;
use App\Modules\Production\Http\Controllers\WorkOrderReworkController;
use App\Modules\Production\Http\Controllers\WorkOrderReworkEvidenceController;
use App\Modules\Production\Http\Controllers\ProductionNcrController;

Route::middleware('auth:sanctum')->group(function () {
    // Work Orders
    Route::apiResource('work-orders', WorkOrderController::class);
    Route::get('work-orders/enquiry/{enquiry_id}', [WorkOrderController::class, 'getByEnquiry']);
    Route::post('work-orders/create-for-existing-projects', [WorkOrderController::class, 'createForExistingProjects']);
    Route::get('work-orders/{work_order}/scrap-logs', [WorkOrderScrapLogController::class, 'index']);
    Route::post('work-orders/{work_order}/scrap-logs', [WorkOrderScrapLogController::class, 'store']);
    Route::delete('work-orders/{work_order}/scrap-logs/{scrap_log}', [WorkOrderScrapLogController::class, 'destroy']);
    Route::get('work-orders/{work_order}/tasks', [WorkOrderTaskController::class, 'index']);
    Route::post('work-orders/{work_order}/tasks', [WorkOrderTaskController::class, 'store']);
    Route::put('work-orders/{work_order}/tasks/{task}', [WorkOrderTaskController::class, 'update']);
    Route::delete('work-orders/{work_order}/tasks/{task}', [WorkOrderTaskController::class, 'destroy']);
    Route::get('work-orders/{work_order}/tasks/{task}/evidence', [WorkOrderTaskEvidenceController::class, 'index']);
    Route::post('work-orders/{work_order}/tasks/{task}/evidence', [WorkOrderTaskEvidenceController::class, 'store']);
    Route::delete('work-orders/{work_order}/tasks/{task}/evidence/{evidence}', [WorkOrderTaskEvidenceController::class, 'destroy']);
    Route::get('work-orders/{work_order}/mid-qc', [WorkOrderMidQcController::class, 'index']);
    Route::post('work-orders/{work_order}/mid-qc', [WorkOrderMidQcController::class, 'store']);
    Route::get('work-orders/{work_order}/final-qc', [WorkOrderFinalQcController::class, 'index']);
    Route::post('work-orders/{work_order}/final-qc', [WorkOrderFinalQcController::class, 'store']);
    Route::get('work-orders/{work_order}/reworks', [WorkOrderReworkController::class, 'index']);
    Route::post('work-orders/{work_order}/reworks', [WorkOrderReworkController::class, 'store']);
    Route::put('work-orders/{work_order}/reworks/{rework}', [WorkOrderReworkController::class, 'update']);
    Route::get('work-orders/{work_order}/reworks/{rework}/evidence', [WorkOrderReworkEvidenceController::class, 'index']);
    Route::post('work-orders/{work_order}/reworks/{rework}/evidence', [WorkOrderReworkEvidenceController::class, 'store']);
    Route::delete('work-orders/{work_order}/reworks/{rework}/evidence/{evidence}', [WorkOrderReworkEvidenceController::class, 'destroy']);
    Route::get('ncrs/reference-data', [ProductionNcrController::class, 'referenceData']);
    Route::get('ncrs', [ProductionNcrController::class, 'index']);
    Route::post('ncrs', [ProductionNcrController::class, 'store']);
    Route::get('ncrs/{ncr}', [ProductionNcrController::class, 'show']);
    Route::put('ncrs/{ncr}', [ProductionNcrController::class, 'update']);
    Route::post('ncrs/{ncr}/assign', [ProductionNcrController::class, 'assign']);
    Route::post('ncrs/{ncr}/request-reinspection', [ProductionNcrController::class, 'requestReinspection']);
    Route::post('ncrs/{ncr}/close', [ProductionNcrController::class, 'close']);
    Route::post('ncrs/{ncr}/upload-image', [ProductionNcrController::class, 'uploadImage']);

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
    Route::get('assignees', [ProductionAssigneeController::class, 'index']);
});
