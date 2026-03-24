<?php

use Illuminate\Support\Facades\Route;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\HR\Http\Controllers\DepartmentController;
use App\Modules\HR\Http\Controllers\TechnicalLabourController;
use App\Modules\HR\Http\Controllers\PayrollEngineController;
use App\Modules\HR\Http\Controllers\LeaveDashboardController;
use App\Modules\HR\Http\Controllers\LeaveRequestController;
use App\Modules\HR\Http\Controllers\LeaveTypeController;
use App\Modules\HR\Http\Controllers\HRActionController;
use App\Modules\HR\Http\Controllers\EmployeeDocumentController;
use App\Modules\HR\Http\Controllers\AnnouncementController;
use App\Constants\Permissions;

// Unprotected HR Routes 
Route::prefix('hr')->group(function () {
    // Employee management
    Route::apiResource('employees', EmployeeController::class);
    
    // Department management
    Route::get('departments', [DepartmentController::class, 'index']);
    Route::post('departments', [DepartmentController::class, 'store']);
    Route::get('departments/{department}', [DepartmentController::class, 'show']);
    Route::put('departments/{department}', [DepartmentController::class, 'update']);
    Route::patch('departments/{department}', [DepartmentController::class, 'update']);
    Route::delete('departments/{department}', [DepartmentController::class, 'destroy']);

    // Technical Labour Management
    Route::get('technical-labour/template', [TechnicalLabourController::class, 'downloadTemplate']);
    Route::post('technical-labour/import', [TechnicalLabourController::class, 'import']);
    Route::get('technical-labour', [TechnicalLabourController::class, 'index']);
    Route::post('technical-labour', [TechnicalLabourController::class, 'store']);
    Route::put('technical-labour/{technicalLabour}', [TechnicalLabourController::class, 'update']);
    Route::delete('technical-labour/{technicalLabour}', [TechnicalLabourController::class, 'destroy']);
});

// Protected HR Routes
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::prefix('hr')->group(function () {
        // Employee management
        Route::apiResource('employees', EmployeeController::class)->middleware([
            'index' => 'permission:' . Permissions::EMPLOYEE_READ,
            'store' => 'permission:' . Permissions::EMPLOYEE_CREATE,
            'show' => 'permission:' . Permissions::EMPLOYEE_READ,
            'update' => 'permission:' . Permissions::EMPLOYEE_UPDATE,
            'destroy' => 'permission:' . Permissions::EMPLOYEE_DELETE,
        ]);

        // Technical Labour management
        Route::apiResource('technical-labour', TechnicalLabourController::class);

        // Department management
        Route::get('departments', [DepartmentController::class, 'index'])
            ->middleware('permission:' . Permissions::DEPARTMENT_READ);
        Route::post('departments', [DepartmentController::class, 'store'])
            ->middleware('permission:' . Permissions::DEPARTMENT_CREATE);
        Route::get('departments/{department}', [DepartmentController::class, 'show'])
            ->middleware('permission:' . Permissions::DEPARTMENT_READ);
        Route::put('departments/{department}', [DepartmentController::class, 'update'])
            ->middleware('permission:' . Permissions::DEPARTMENT_UPDATE);
        Route::patch('departments/{department}', [DepartmentController::class, 'update'])
            ->middleware('permission:' . Permissions::DEPARTMENT_UPDATE);
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])
            ->middleware('permission:' . Permissions::DEPARTMENT_DELETE);

        // Payroll management
        Route::prefix('payroll')->group(function () {
            // Variables
            Route::get('variables', [PayrollEngineController::class, 'getVariables']);
            Route::post('variables', [PayrollEngineController::class, 'storeVariable']);
            Route::put('variables/{id}/toggle', [PayrollEngineController::class, 'toggleVariable']);

            // Ledgers
            Route::get('ledgers', [PayrollEngineController::class, 'getLedgers']);
            Route::post('ledgers', [PayrollEngineController::class, 'storeLedger']);
            Route::delete('ledgers/{id}', [PayrollEngineController::class, 'destroyLedger']);

            // Tax Bands
            Route::get('tax-bands', [PayrollEngineController::class, 'getTaxBands']);
            Route::post('tax-bands', [PayrollEngineController::class, 'storeTaxBand']);
            Route::put('tax-bands/{id}/toggle', [PayrollEngineController::class, 'toggleTaxBand']);

            // Payslips
            Route::get('payslips', [PayrollEngineController::class, 'getPayslips']);
            Route::get('payslips/{id}/download', [PayrollEngineController::class, 'generatePdf']);
            Route::delete('payslips/{id}', [PayrollEngineController::class, 'destroyPayslip']);
            Route::delete('payslips', [PayrollEngineController::class, 'clearPayslips']);

            // Batch Processing
            Route::post('batch', [PayrollEngineController::class, 'batchGenerate']);

            // Exports
            Route::get('export/bank', [PayrollEngineController::class, 'exportBankRemittance']);
            Route::get('export/mpesa', [PayrollEngineController::class, 'exportMpesaRemittance']);
            Route::get('export/p9', [PayrollEngineController::class, 'exportP9']);

            // Compliance & Payment 
            Route::get('compliance-summary', [PayrollEngineController::class, 'getComplianceSummary']);
            Route::post('mark-paid', [PayrollEngineController::class, 'markAsPaid']);
        });

        // Leave management
        Route::prefix('leave')->group(function () {
            // Dashboard
            Route::get('dashboard', [LeaveDashboardController::class, 'show']);

            // Leave types
            Route::get('types', [LeaveTypeController::class, 'index']);
            Route::post('types', [LeaveTypeController::class, 'store']);
            Route::put('types/{leaveType}', [LeaveTypeController::class, 'update']);
            Route::delete('types/{leaveType}', [LeaveTypeController::class, 'destroy']);

            // Leave requests
            Route::get('requests', [LeaveRequestController::class, 'index']);
            Route::post('requests', [LeaveRequestController::class, 'store']);
            Route::get('requests/{leaveRequest}', [LeaveRequestController::class, 'show']);
            Route::put('requests/{leaveRequest}', [LeaveRequestController::class, 'update']);
            Route::post('requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
            Route::post('requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject']);
            Route::post('requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);
            Route::post('requests/{leaveRequest}/recall', [LeaveRequestController::class, 'recall']);
        });

        // Employee Actions (HR directives: promotions, transfers, warnings, etc.)
        Route::get('employees/{employee}/actions', [HRActionController::class, 'index']);
        Route::post('actions', [HRActionController::class, 'store']);

        // Employee Documents
        Route::get('employees/{employeeId}/documents', [EmployeeDocumentController::class, 'index']);
        Route::post('employees/{employeeId}/documents', [EmployeeDocumentController::class, 'store']);
        Route::get('employees/{employeeId}/documents/{documentId}/download', [EmployeeDocumentController::class, 'download']);
        Route::delete('employees/{employeeId}/documents/{documentId}', [EmployeeDocumentController::class, 'destroy']);
    });

    // Announcements at root api/ level for Android app
    Route::get('announcements', [AnnouncementController::class, 'index']);
    Route::post('announcements', [AnnouncementController::class, 'store']);
    Route::post('announcements/read', [AnnouncementController::class, 'markAsRead']);
    Route::get('announcements/unread-count', [AnnouncementController::class, 'unreadCount']);
    Route::delete('announcements/{id}', [AnnouncementController::class, 'destroy']);
});
