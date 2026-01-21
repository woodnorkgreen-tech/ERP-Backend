<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Production\Http\Controllers\WorkOrderController;

Route::middleware('auth:sanctum')->group(function () {
    // Work Orders
    Route::apiResource('work-orders', WorkOrderController::class);
    Route::get('work-orders/enquiry/{enquiry_id}', [WorkOrderController::class, 'getByEnquiry']);
    Route::post('work-orders/create-for-existing-projects', [WorkOrderController::class, 'createForExistingProjects']);
});
