<?php

use App\Constants\Permissions;
use App\Modules\HR\Http\Controllers\DepartmentController;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\HR\Http\Controllers\LeaveDashboardController;
use App\Modules\HR\Http\Controllers\LeaveRequestController;
use App\Modules\HR\Http\Controllers\LeaveTypeController;
use App\Modules\HR\Http\Controllers\TechnicalLabourController;
use Illuminate\Support\Facades\Route;

Route::prefix('hr')->group(function () {
    Route::get('technical-labour/template', [TechnicalLabourController::class, 'downloadTemplate']);

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('employees', [EmployeeController::class, 'index'])
            ->middleware('permission:' . Permissions::EMPLOYEE_READ);
        Route::post('employees', [EmployeeController::class, 'store'])
            ->middleware('permission:' . Permissions::EMPLOYEE_CREATE);
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])
            ->middleware('permission:' . Permissions::EMPLOYEE_READ);
        Route::put('employees/{employee}', [EmployeeController::class, 'update'])
            ->middleware('permission:' . Permissions::EMPLOYEE_UPDATE);
        Route::patch('employees/{employee}', [EmployeeController::class, 'update'])
            ->middleware('permission:' . Permissions::EMPLOYEE_UPDATE);
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])
            ->middleware('permission:' . Permissions::EMPLOYEE_DELETE);

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

        Route::get('technical-labour', [TechnicalLabourController::class, 'index']);
        Route::post('technical-labour/import', [TechnicalLabourController::class, 'import']);
        Route::post('technical-labour', [TechnicalLabourController::class, 'store']);
        Route::put('technical-labour/{technicalLabour}', [TechnicalLabourController::class, 'update']);
        Route::patch('technical-labour/{technicalLabour}', [TechnicalLabourController::class, 'update']);
        Route::delete('technical-labour/{technicalLabour}', [TechnicalLabourController::class, 'destroy']);

        Route::get('leave/dashboard', [LeaveDashboardController::class, 'show']);

        Route::get('leave/types', [LeaveTypeController::class, 'index']);
        Route::post('leave/types', [LeaveTypeController::class, 'store'])
            ->middleware('permission:' . Permissions::LEAVE_TYPE_CREATE);
        Route::put('leave/types/{leaveType}', [LeaveTypeController::class, 'update'])
            ->middleware('permission:' . Permissions::LEAVE_TYPE_UPDATE);
        Route::patch('leave/types/{leaveType}', [LeaveTypeController::class, 'update'])
            ->middleware('permission:' . Permissions::LEAVE_TYPE_UPDATE);
        Route::delete('leave/types/{leaveType}', [LeaveTypeController::class, 'destroy'])
            ->middleware('permission:' . Permissions::LEAVE_TYPE_DELETE);

        Route::get('leave/requests', [LeaveRequestController::class, 'index']);
        Route::post('leave/requests', [LeaveRequestController::class, 'store']);
        Route::get('leave/requests/{leaveRequest}', [LeaveRequestController::class, 'show']);
        Route::put('leave/requests/{leaveRequest}', [LeaveRequestController::class, 'update']);
        Route::patch('leave/requests/{leaveRequest}', [LeaveRequestController::class, 'update']);
        Route::post('leave/requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])
            ->middleware('permission:' . Permissions::LEAVE_REQUEST_APPROVE);
        Route::post('leave/requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])
            ->middleware('permission:' . Permissions::LEAVE_REQUEST_APPROVE);
        Route::post('leave/requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);
    });
});
