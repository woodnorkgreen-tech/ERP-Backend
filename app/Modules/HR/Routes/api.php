<?php
use Illuminate\Support\Facades\Route;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\HR\Http\Controllers\HRDashboardController;
use App\Modules\HR\Http\Controllers\HrDocumentController;
use App\Modules\HR\Http\Controllers\DepartmentController;
use App\Modules\HR\Http\Controllers\TechnicalLabourController;
use App\Modules\HR\Http\Controllers\PayrollEngineController;
use App\Modules\HR\Http\Controllers\PayrollRunController;
use App\Modules\HR\Http\Controllers\LeaveDashboardController;
use App\Modules\HR\Http\Controllers\LeaveHandoverController;
use App\Modules\HR\Http\Controllers\LeaveRequestController;
use App\Modules\HR\Http\Controllers\LeaveTypeController;
use App\Modules\HR\Http\Controllers\HRActionController;
use App\Modules\HR\Http\Controllers\EmployeeDocumentController;
use App\Modules\HR\Http\Controllers\AnnouncementController;
use App\Modules\HR\Http\Controllers\IncidentController;
use App\Modules\HR\Http\Controllers\GrievanceController;
use App\Modules\HR\Http\Controllers\DisciplineController;
use App\Modules\HR\Http\Controllers\AttendanceController;
use App\Modules\HR\Http\Controllers\RecruitmentController;
use App\Modules\HR\Http\Controllers\InterviewController;
use App\Modules\HR\Http\Controllers\SalaryAdvanceController;
use App\Modules\HR\Http\Controllers\OvertimeController;
use App\Modules\HR\Http\Controllers\CompensatoryLeaveController;
use App\Modules\HR\Http\Controllers\EmployeeSkillController;
use App\Modules\HR\Http\Controllers\PerformanceReviewController;
use App\Modules\HR\Http\Controllers\OnboardingController;
use App\Modules\HR\Http\Controllers\OffboardingController;
use App\Constants\Permissions;

// Unprotected HR Routes (public recruitment only)
Route::prefix('hr')->group(function () {
    // Public Recruitment — no auth required for job listings and applications
    Route::prefix('recruitment')->group(function () {
        Route::get('jobs', [RecruitmentController::class, 'publicJobs']);
        Route::get('jobs/{id}', [RecruitmentController::class, 'publicJobDetails']);
        Route::post('apply', [RecruitmentController::class, 'apply']);
    });
});

// Public HR Routes (for assets like photos)
Route::get('hr/employees/{employee}/photo', [EmployeeController::class, 'getPhoto']);

// Protected HR Routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::prefix('hr')->group(function () {
        // Cross-domain HR overview (server-side aggregation)
        Route::get('dashboard/overview', [HRDashboardController::class, 'overview'])
            ->middleware('permission:' . Permissions::EMPLOYEE_READ);

        // HR PDF documents
        Route::get('employees/{employee}/certificate-of-service', [HrDocumentController::class, 'certificateOfService']);
        Route::get('discipline/{case}/letters/{type}', [HrDocumentController::class, 'disciplinaryLetter']);

        // Employee management
        Route::get('employees/profile', [EmployeeController::class, 'profile']);
        Route::get('employees/compact', [EmployeeController::class, 'compact']);
        Route::get('employees/stats', [EmployeeController::class, 'stats'])->middleware('permission:' . Permissions::EMPLOYEE_READ);
        Route::post('employees/{employee}/photo', [EmployeeController::class, 'uploadPhoto']);

        // Bulk employee edit via Excel: download with data → edit → reupload (preview, then commit).
        // Registered before the apiResource so 'template' is not captured as an {employee} param.
        Route::get('employees/template', [EmployeeController::class, 'downloadTemplate'])
            ->middleware('permission:' . Permissions::EMPLOYEE_READ);
        Route::post('employees/template/preview', [EmployeeController::class, 'previewImport'])
            ->middleware('permission:' . Permissions::EMPLOYEE_UPDATE);
        Route::post('employees/template/commit', [EmployeeController::class, 'commitImport'])
            ->middleware('permission:' . Permissions::EMPLOYEE_UPDATE);

        // middlewareFor, not middleware([...]): an associative array passed to
        // middleware() is flattened, which stacked ALL five permission checks
        // onto every route (index effectively required create+update+delete too).
        Route::apiResource('employees', EmployeeController::class)
            ->middlewareFor('index',   'permission:' . Permissions::EMPLOYEE_READ)
            ->middlewareFor('store',   'permission:' . Permissions::EMPLOYEE_CREATE)
            ->middlewareFor('show',    'permission:' . Permissions::EMPLOYEE_READ)
            ->middlewareFor('update',  'permission:' . Permissions::EMPLOYEE_UPDATE)
            ->middlewareFor('destroy', 'permission:' . Permissions::EMPLOYEE_DELETE);

        // Reinstatement — restore a soft-deleted (terminated) employee.
        Route::post('employees/{id}/restore', [EmployeeController::class, 'restore'])
            ->middleware('permission:' . Permissions::EMPLOYEE_DELETE);

        // Employee skills & certifications
        Route::prefix('employees/{employee}')->group(function () {
            Route::get('skills',                           [EmployeeSkillController::class, 'indexSkills']);
            Route::post('skills',                          [EmployeeSkillController::class, 'storeSkill']);
            Route::put('skills/{skill}',                   [EmployeeSkillController::class, 'updateSkill']);
            Route::delete('skills/{skill}',                [EmployeeSkillController::class, 'destroySkill']);

            Route::get('certifications',                   [EmployeeSkillController::class, 'indexCertifications']);
            Route::post('certifications',                  [EmployeeSkillController::class, 'storeCertification']);
            Route::put('certifications/{certification}',   [EmployeeSkillController::class, 'updateCertification']);
            Route::delete('certifications/{certification}',[EmployeeSkillController::class, 'destroyCertification']);

            Route::get('performance-reviews',              [PerformanceReviewController::class, 'index']);
            Route::post('performance-reviews',             [PerformanceReviewController::class, 'store']);
            Route::put('performance-reviews/{review}',     [PerformanceReviewController::class, 'update']);
            Route::delete('performance-reviews/{review}',  [PerformanceReviewController::class, 'destroy']);
        });

        // Technical Labour management
        // Promotion creates a real staff record, so it requires the employee-create permission
        // (the route is otherwise only auth-gated and would bypass employee.create entirely).
        Route::post('technical-labour/{technicalLabour}/promote', [TechnicalLabourController::class, 'promote'])
            ->middleware('permission:' . Permissions::EMPLOYEE_CREATE);
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
            Route::get('ledgers/template', [PayrollEngineController::class, 'exportLedgerTemplate']);
            Route::post('ledgers/import', [PayrollEngineController::class, 'importLedgers']);
            Route::get('ledgers', [PayrollEngineController::class, 'getLedgers']);
            Route::post('ledgers', [PayrollEngineController::class, 'storeLedger']);
            Route::put('ledgers/{id}', [PayrollEngineController::class, 'updateLedger']);
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

            // Batch Processing (LEGACY REMOVED - Use Runs)
            // Route::post('batch', [PayrollEngineController::class, 'batchGenerate']);

            // Exports
            Route::get('export/bank', [PayrollEngineController::class, 'exportBankRemittance']);
            Route::get('export/mpesa', [PayrollEngineController::class, 'exportMpesaRemittance']);
            Route::get('export/p9', [PayrollEngineController::class, 'exportP9']);
            Route::get('export/p9/pdf', [PayrollEngineController::class, 'exportP9Pdf']);

            // Compliance & Payment (LEGACY REMOVED - Use Runs)
            // Route::get('compliance-summary', [PayrollEngineController::class, 'getComplianceSummary']);
            // Route::post('mark-paid', [PayrollEngineController::class, 'markAsPaid']);

            // Payroll Runs (lifecycle management)
            Route::get('runs', [PayrollRunController::class, 'index']);
            Route::post('runs', [PayrollRunController::class, 'store']);
            Route::get('runs/{payrollRun}', [PayrollRunController::class, 'show']);
            Route::post('runs/{payrollRun}/process', [PayrollRunController::class, 'process']);
            Route::post('runs/{payrollRun}/lock', [PayrollRunController::class, 'lock']);
            Route::post('runs/{payrollRun}/mark-paid', [PayrollRunController::class, 'markPaid']);
            Route::post('runs/{payrollRun}/rollback', [PayrollRunController::class, 'rollback']);
            Route::delete('runs/{payrollRun}', [PayrollRunController::class, 'destroy']);
            Route::get('runs/{payrollRun}/compliance-summary', [PayrollRunController::class, 'complianceSummary']);
        });

        // Salary Advances
        Route::get('advances', [SalaryAdvanceController::class, 'index']);
        Route::post('advances/{id}/approve', [SalaryAdvanceController::class, 'approve']);
        Route::post('advances/{id}/reject', [SalaryAdvanceController::class, 'reject']);
        Route::get('my-advances', [SalaryAdvanceController::class, 'myRequests']);
        Route::post('my-advances', [SalaryAdvanceController::class, 'store']);

        // Leave management
        Route::prefix('leave')->group(function () {
            // Dashboard
            Route::get('dashboard', [LeaveDashboardController::class, 'show']);
            Route::get('projects', [LeaveDashboardController::class, 'projects']);
            Route::get('holidays', [LeaveDashboardController::class, 'holidays']);

            // Leave types
            Route::get('types', [LeaveTypeController::class, 'index']);
            Route::post('types', [LeaveTypeController::class, 'store'])
                ->middleware('permission:' . Permissions::LEAVE_TYPE_CREATE);
            Route::put('types/{leaveType}', [LeaveTypeController::class, 'update'])
                ->middleware('permission:' . Permissions::LEAVE_TYPE_UPDATE);
            Route::patch('types/{leaveType}', [LeaveTypeController::class, 'update'])
                ->middleware('permission:' . Permissions::LEAVE_TYPE_UPDATE);
            Route::delete('types/{leaveType}', [LeaveTypeController::class, 'destroy'])
                ->middleware('permission:' . Permissions::LEAVE_TYPE_DELETE);

            // Leave requests
            Route::get('requests', [LeaveRequestController::class, 'index']);
            Route::post('requests/preview', [LeaveRequestController::class, 'preview']);
            Route::post('requests', [LeaveRequestController::class, 'store']);
            Route::get('requests/{leaveRequest}', [LeaveRequestController::class, 'show']);
            Route::get('requests/{leaveRequest}/attachment', [LeaveRequestController::class, 'viewAttachment']);
            Route::put('requests/{leaveRequest}', [LeaveRequestController::class, 'update']);
            Route::patch('requests/{leaveRequest}', [LeaveRequestController::class, 'update']);
            Route::post('requests/{leaveRequest}/lead-approve', [LeaveRequestController::class, 'leadApprove']);
            Route::post('requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
            Route::post('requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject']);
            Route::post('requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);
            Route::post('requests/{leaveRequest}/recall', [LeaveRequestController::class, 'recall']);
            Route::get('statistics', [LeaveRequestController::class, 'statistics']);
            Route::post('adjust-balance', [LeaveRequestController::class, 'adjustBalance'])
                ->middleware('permission:' . Permissions::LEAVE_REQUEST_APPROVE);

            // Leave handovers
            Route::get('handovers', [LeaveHandoverController::class, 'index']);
            Route::get('handovers/leave-request/{leaveRequestId}', [LeaveHandoverController::class, 'show']);
            Route::post('handovers', [LeaveHandoverController::class, 'store']);
            Route::put('handovers/{handover}', [LeaveHandoverController::class, 'update']);
            Route::patch('handovers/{handover}', [LeaveHandoverController::class, 'update']);
            Route::delete('handovers/{handover}', [LeaveHandoverController::class, 'destroy']);
        });

        // Overtime Tracking
        Route::prefix('overtime')->group(function () {
            Route::get('/', [OvertimeController::class, 'index']);
            Route::get('projects', [OvertimeController::class, 'projects']);
            Route::post('/', [OvertimeController::class, 'store']);
            Route::post('bulk', [OvertimeController::class, 'bulkStore']);
            Route::post('{entry}/submit', [OvertimeController::class, 'submit']);
            Route::post('{entry}/supervisor-approve', [OvertimeController::class, 'supervisorApprove']);
            Route::post('{entry}/hr-approve', [OvertimeController::class, 'hrApprove']);
            Route::post('{entry}/reject', [OvertimeController::class, 'reject']);
            Route::post('{entry}/reopen', [OvertimeController::class, 'reopen']);
            Route::post('reset-system', [OvertimeController::class, 'resetSystem']);
            Route::delete('{id}', [OvertimeController::class, 'destroy']);
            Route::get('balance/{type}/{id}', [OvertimeController::class, 'balance']);
            Route::get('ledger', [OvertimeController::class, 'ledger']);
            Route::post('ledger/{ledgerEntry}/reverse', [OvertimeController::class, 'reverseLedger']);

            // Reports
            Route::get('reports/ledger-audit', [\App\Modules\HR\Http\Controllers\OvertimeReportController::class, 'downloadLedgerAudit']);
            Route::get('reports/fatigue-matrix', [\App\Modules\HR\Http\Controllers\OvertimeReportController::class, 'downloadFatigueMatrix']);
            Route::get('reports/project-allocation', [\App\Modules\HR\Http\Controllers\OvertimeReportController::class, 'downloadProjectAllocation']);
            Route::get('reports/technical-pool-analysis', [\App\Modules\HR\Http\Controllers\OvertimeReportController::class, 'downloadTechnicalPoolAnalysis']);
            Route::get('reports/personal-statement/{type?}/{id?}', [\App\Modules\HR\Http\Controllers\OvertimeReportController::class, 'downloadPersonalStatement']);
        });

        // Compensatory Leave
        Route::prefix('compensations')->group(function () {
            Route::get('/', [CompensatoryLeaveController::class, 'index']);
            Route::post('/', [CompensatoryLeaveController::class, 'store']);
            Route::post('{compensation}/supervisor-approve', [CompensatoryLeaveController::class, 'supervisorApprove']);
            Route::post('{compensation}/hr-approve', [CompensatoryLeaveController::class, 'hrApprove']);
            Route::post('{compensation}/reject', [CompensatoryLeaveController::class, 'reject']);
            Route::delete('{id}', [CompensatoryLeaveController::class, 'destroy']);
        });

        // Employee Actions (HR directives: promotions, transfers, warnings, etc.)
        Route::get('employees/{employee}/actions', [HRActionController::class, 'index']);
        Route::get('action-types', [HRActionController::class, 'actionTypes']);
        Route::post('actions', [HRActionController::class, 'store']);
        Route::post('actions/{id}/approve', [HRActionController::class, 'approveAction']);
        Route::get('action-attachments/{id}/view', [HRActionController::class, 'viewAttachment']);
        Route::get('action-attachments/{id}/download', [HRActionController::class, 'downloadAttachment']);

        // Employee Self-Service
        Route::get('self-service/activity', [\App\Modules\HR\Http\Controllers\SelfServiceController::class, 'activity']);

        // Profile Updates (Employee Self-Service)
        Route::post('profile-updates', [\App\Modules\HR\Http\Controllers\SelfServiceController::class, 'updateProfile']);
        Route::get('profile-updates', [\App\Modules\HR\Http\Controllers\ProfileUpdateApprovalController::class, 'index']);
        Route::post('profile-updates/{id}/approve', [\App\Modules\HR\Http\Controllers\ProfileUpdateApprovalController::class, 'approve']);
        Route::post('profile-updates/{id}/reject', [\App\Modules\HR\Http\Controllers\ProfileUpdateApprovalController::class, 'reject']);

        // Employee Salary History
        Route::get('employees/{employee}/salary-history', [PayrollRunController::class, 'salaryHistory']);
        Route::post('employees/{employee}/salary-history', [PayrollRunController::class, 'storeSalaryHistory']);

        // Employee Documents
        Route::get('employees/{employeeId}/documents', [EmployeeDocumentController::class, 'index']);
        Route::post('employees/{employeeId}/documents', [EmployeeDocumentController::class, 'store']);
        Route::get('employees/{employeeId}/documents/{documentId}/download', [EmployeeDocumentController::class, 'download']);
        Route::delete('employees/{employeeId}/documents/{documentId}', [EmployeeDocumentController::class, 'destroy']);

        // Incident management
        Route::get('incidents', [IncidentController::class, 'index']);
        Route::post('incidents', [IncidentController::class, 'store']);
        Route::post('incidents/report', [IncidentController::class, 'store']);
        Route::get('incidents/statistics', [IncidentController::class, 'statistics']);
        Route::get('incidents/my', [IncidentController::class, 'myIncidents']);
        Route::get('incidents/pending-reviews', [IncidentController::class, 'pendingReviews']);
        Route::get('incidents/context', [IncidentController::class, 'userContext']);
        Route::get('incidents/{id}', [IncidentController::class, 'show']);
        Route::put('incidents/{id}', [IncidentController::class, 'update']);
        Route::patch('incidents/{id}', [IncidentController::class, 'update']);
        Route::delete('incidents/{id}', [IncidentController::class, 'destroy']);
        Route::post('incidents/{id}/review', [IncidentController::class, 'review']);
        Route::post('incidents/{id}/approve', [IncidentController::class, 'approve']);
        Route::post('incidents/{id}/comments', [IncidentController::class, 'addComment']);
        Route::get('incidents/{id}/pdf', [IncidentController::class, 'downloadPdf']);
        Route::post('incidents/{id}/attachments', [IncidentController::class, 'uploadAttachments']);
        Route::get('incidents/{id}/attachments/{filename}/view', [IncidentController::class, 'viewAttachment']);
        Route::get('incidents/{id}/attachments/{filename}', [IncidentController::class, 'downloadAttachment']);

        // Grievance management
        Route::get('grievance', [GrievanceController::class, 'index']);
        Route::post('grievance', [GrievanceController::class, 'store']);
        Route::get('grievance/statistics', [GrievanceController::class, 'statistics']);
        Route::get('grievance/{id}', [GrievanceController::class, 'show']);
        Route::put('grievance/{id}', [GrievanceController::class, 'update']);
        Route::patch('grievance/{id}', [GrievanceController::class, 'update']);
        Route::post('grievance/{id}/resolve', [GrievanceController::class, 'resolve']);
        Route::post('grievance/{id}/escalate', [GrievanceController::class, 'escalate']);
        Route::post('grievance/{id}/comments', [GrievanceController::class, 'addComment']);
        Route::post('grievance/{id}/attachments', [GrievanceController::class, 'uploadAttachments']);
        Route::get('grievance/{id}/attachments/{filename}/view', [GrievanceController::class, 'viewAttachment']);
        Route::get('grievance/{id}/attachments/{filename}', [GrievanceController::class, 'downloadAttachment']);

        // Discipline management
        Route::get('discipline', [DisciplineController::class, 'index']);
        Route::post('discipline', [DisciplineController::class, 'store']);
        Route::get('discipline/statistics', [DisciplineController::class, 'statistics']);
        Route::get('discipline/{id}', [DisciplineController::class, 'show']);
        Route::post('discipline/{id}/show-cause', [DisciplineController::class, 'issueShowCause']);
        Route::post('discipline/{id}/show-cause-response', [DisciplineController::class, 'submitShowCauseResponse']);
        Route::post('discipline/{id}/schedule-hearing', [DisciplineController::class, 'scheduleHearing']);
        Route::post('discipline/{id}/hearing-minutes', [DisciplineController::class, 'submitHearingMinutes']);
        Route::post('discipline/{id}/issue-warning', [DisciplineController::class, 'issueWarning']);
        Route::post('discipline/{id}/appeal', [DisciplineController::class, 'submitAppeal']);
        Route::post('discipline/{id}/finalize', [DisciplineController::class, 'finalizeCase']);
        Route::post('discipline/{id}/comments', [DisciplineController::class, 'addComment']);
        Route::post('discipline/{id}/attachments', [DisciplineController::class, 'uploadAttachments']);
        Route::get('discipline/{id}/attachments/{filename}/view', [DisciplineController::class, 'viewAttachment']);
        Route::get('discipline/{id}/attachments/{filename}', [DisciplineController::class, 'downloadAttachment']);

        // Attendance Management
        Route::middleware('permission:' . Permissions::HR_MANAGE_ATTENDANCE)
            ->prefix('attendance')
            ->group(function () {
                Route::get('summary', [AttendanceController::class, 'summary']);
                Route::get('device-logs', [AttendanceController::class, 'deviceLogs']);
                Route::post('manual-preview', [AttendanceController::class, 'manualPreview']);
                Route::get('sync-logs', [AttendanceController::class, 'syncLogs']);
                Route::get('exceptions/unmapped', [AttendanceController::class, 'unmappedExceptions']);
                Route::post('exceptions/unmapped/{personId}/map', [AttendanceController::class, 'mapUnmappedPerson']);
                Route::post('reprocess', [AttendanceController::class, 'reprocess']);
                Route::post('sync/test-connection', [AttendanceController::class, 'testSyncConnection']);
                Route::post('sync', [AttendanceController::class, 'sync']);
                Route::get('sync/{syncRequest}', [AttendanceController::class, 'syncStatus']);
                Route::post('upload/preview', [AttendanceController::class, 'uploadPreview']);
                Route::post('upload', [AttendanceController::class, 'upload']);
                Route::get('overtime', [AttendanceController::class, 'overtime']);
                Route::get('overtime/export', [AttendanceController::class, 'exportOvertime']);
                Route::get('/', [AttendanceController::class, 'index']);
                Route::post('/', [AttendanceController::class, 'store']);
                Route::get('{id}', [AttendanceController::class, 'show']);
                Route::put('{id}', [AttendanceController::class, 'update']);
                Route::patch('{id}', [AttendanceController::class, 'update']);
                Route::post('{id}/restore', [AttendanceController::class, 'restore']);
                Route::delete('{id}', [AttendanceController::class, 'destroy']);
            });

        // Onboarding
        Route::prefix('onboarding')->group(function () {
            Route::get('hired-candidates', [OnboardingController::class, 'hiredCandidates']);
            Route::get('/', [OnboardingController::class, 'index']);
            Route::post('/', [OnboardingController::class, 'store']);
            Route::get('{id}', [OnboardingController::class, 'show']);
            Route::post('{id}/link-employee', [OnboardingController::class, 'linkEmployee']);
            Route::post('{id}/hr-approve', [OnboardingController::class, 'approveHRGate']);
            Route::post('{id}/handover', [OnboardingController::class, 'recordHandover']);
            Route::post('{id}/reviews', [OnboardingController::class, 'submitReview']);
            Route::post('{id}/cancel', [OnboardingController::class, 'cancel']);
            Route::get('{id}/activity-log', [OnboardingController::class, 'activityLog']);
            Route::post('cards/{cardId}/tasks', [OnboardingController::class, 'createTask']);
            Route::post('tasks/{taskId}/complete', [OnboardingController::class, 'completeTask']);
            Route::post('tasks/{taskId}/reopen', [OnboardingController::class, 'reopenTask']);
            Route::patch('tasks/{taskId}/toggle-optional', [OnboardingController::class, 'toggleOptionalTask']);
            Route::patch('tasks/{taskId}', [OnboardingController::class, 'updateTaskFlags']);
            Route::post('cases/{caseId}/documents', [OnboardingController::class, 'createDocumentRequirement']);
            Route::patch('documents/{requirementId}/status', [OnboardingController::class, 'updateDocumentStatus']);
            Route::patch('documents/{requirementId}', [OnboardingController::class, 'updateDocumentRequirement']);
            Route::post('cases/{caseId}/welcome-kit', [OnboardingController::class, 'createWelcomeKitItem']);
            Route::post('welcome-kit/{itemId}/toggle', [OnboardingController::class, 'toggleWelcomeKitItem']);
            Route::patch('welcome-kit/{itemId}', [OnboardingController::class, 'updateWelcomeKitItem']);
        });

        // Offboarding
        Route::prefix('offboarding')->group(function () {
            Route::middleware('permission:' . Permissions::OFFBOARDING_VIEW)->group(function () {
                Route::get('/', [OffboardingController::class, 'index']);
                Route::get('{id}', [OffboardingController::class, 'show'])->whereNumber('id');
                Route::get('{id}/activity-log', [OffboardingController::class, 'activityLog']);
                Route::get('attachments/{id}/view', [OffboardingController::class, 'viewAttachment']);
                Route::get('attachments/{id}/download', [OffboardingController::class, 'downloadAttachment']);
            });

            Route::middleware('permission:' . Permissions::OFFBOARDING_CREATE)->group(function () {
                Route::get('eligible-employees', [OffboardingController::class, 'eligibleEmployees']);
                Route::post('/', [OffboardingController::class, 'store']);
            });

            Route::middleware('permission:' . Permissions::OFFBOARDING_MANAGE)->group(function () {
                Route::post('{id}/cancel', [OffboardingController::class, 'cancel']);
                Route::post('{id}/exit-interview', [OffboardingController::class, 'recordExitInterview']);
                Route::post('{id}/exit-interview-attachments', [OffboardingController::class, 'uploadExitInterviewAttachment'])->whereNumber('id');
                Route::post('cards/{cardId}/tasks', [OffboardingController::class, 'createTask']);
                Route::post('tasks/{taskId}/complete', [OffboardingController::class, 'completeTask']);
                Route::post('tasks/{taskId}/reopen', [OffboardingController::class, 'reopenTask']);
                Route::patch('tasks/{taskId}/toggle-optional', [OffboardingController::class, 'toggleOptionalTask']);
                Route::patch('tasks/{taskId}', [OffboardingController::class, 'updateTaskFlags']);
                Route::post('tasks/{taskId}/attachments', [OffboardingController::class, 'uploadTaskAttachment']);
                Route::post('cases/{caseId}/assets', [OffboardingController::class, 'createAssetReturnItem']);
                Route::post('assets/{itemId}/toggle', [OffboardingController::class, 'toggleAssetReturn']);
                Route::patch('assets/{itemId}', [OffboardingController::class, 'updateAssetReturn']);
                Route::post('assets/{itemId}/attachments', [OffboardingController::class, 'uploadAssetReturnAttachment']);
                Route::post('cases/{caseId}/clearances', [OffboardingController::class, 'createClearance']);
                Route::delete('attachments/{attachmentId}', [OffboardingController::class, 'deleteAttachment']);
            });

            Route::middleware('permission:' . Permissions::OFFBOARDING_CLEARANCE)->group(function () {
                Route::patch('clearances/{clearanceId}/status', [OffboardingController::class, 'updateClearanceStatus']);
                Route::patch('clearances/{clearanceId}', [OffboardingController::class, 'updateClearanceFlags']);
                Route::post('{id}/clearance-attachments', [OffboardingController::class, 'uploadClearanceAttachment'])->whereNumber('id');
            });

            Route::middleware('permission:' . Permissions::OFFBOARDING_SETTLEMENT)->group(function () {
                Route::patch('{id}/settlement', [OffboardingController::class, 'updateFinalSettlement']);
                Route::post('{id}/settlement/approve', [OffboardingController::class, 'approveFinalSettlement']);
                Route::post('{id}/settlement/mark-paid', [OffboardingController::class, 'markSettlementPaid']);
                Route::post('{id}/settlement-attachments', [OffboardingController::class, 'uploadSettlementAttachment'])->whereNumber('id');
            });

            Route::post('{id}/approve', [OffboardingController::class, 'approveFinalGate'])
                ->middleware('permission:' . Permissions::OFFBOARDING_APPROVE);
        });

        // Internal Recruitment (ATS)
        Route::prefix('recruitment/admin')->group(function () {
            // Jobs management
            Route::get('jobs', [RecruitmentController::class, 'adminJobs']);
            Route::post('jobs', [RecruitmentController::class, 'storeJob']);
            Route::put('jobs/{id}', [RecruitmentController::class, 'updateJob']);
            Route::post('jobs/{id}/repost', [RecruitmentController::class, 'repostJob']);
            Route::delete('jobs/{id}', [RecruitmentController::class, 'destroyJob']);
            Route::post('jobs/{id}/notify-shortlisted', [InterviewController::class, 'notifyShortlisted'])
                ->middleware('permission:' . Permissions::RECRUITMENT_NOTIFY);

            // Shortlisting
            Route::get('jobs/{id}/shortlist-criteria', [RecruitmentController::class, 'getShortlistCriteria']);
            Route::put('jobs/{id}/shortlist-criteria', [RecruitmentController::class, 'saveShortlistCriteria']);
            Route::post('jobs/{id}/shortlist-preview', [RecruitmentController::class, 'previewShortlist']);
            Route::post('jobs/{id}/run-shortlist', [RecruitmentController::class, 'runShortlist']);

            // Candidates management
            Route::get('candidates', [RecruitmentController::class, 'adminCandidates']);
            Route::get('candidates/{id}', [RecruitmentController::class, 'candidateDetails']);
            Route::put('candidates/{id}/status', [RecruitmentController::class, 'updateCandidateStatus']);
            Route::get('candidates/{id}/documents/{documentId}/download', [RecruitmentController::class, 'downloadDocument']);
            Route::patch('candidates/{id}/background-check', [RecruitmentController::class, 'updateBackgroundCheck'])
                ->middleware('permission:' . Permissions::RECRUITMENT_BACKGROUND_CHECK_UPDATE);
            Route::post('candidates/{id}/background-check/start', [RecruitmentController::class, 'startBackgroundCheck'])
                ->middleware('permission:' . Permissions::RECRUITMENT_BACKGROUND_CHECK_UPDATE);
            Route::post('candidates/{id}/background-check/complete', [RecruitmentController::class, 'completeBackgroundCheck'])
                ->middleware('permission:' . Permissions::RECRUITMENT_BACKGROUND_CHECK_COMPLETE);
            Route::get('candidates/{id}/interviews', [InterviewController::class, 'candidateInterviews']);
            Route::post('candidates/{id}/interviews', [InterviewController::class, 'store'])
                ->middleware('permission:' . Permissions::RECRUITMENT_INTERVIEW_CREATE);

            // Interviews management
            Route::get('interviews', [InterviewController::class, 'index']);
            Route::post('interviews/bulk', [InterviewController::class, 'bulkStore'])
                ->middleware('permission:' . Permissions::RECRUITMENT_INTERVIEW_CREATE);
            Route::put('interviews/{id}', [InterviewController::class, 'update'])
                ->middleware('permission:' . Permissions::RECRUITMENT_INTERVIEW_UPDATE);
            Route::patch('interviews/{id}/complete', [InterviewController::class, 'complete'])
                ->middleware('permission:' . Permissions::RECRUITMENT_INTERVIEW_UPDATE);
            Route::delete('interviews/{id}', [InterviewController::class, 'destroy'])
                ->middleware('permission:' . Permissions::RECRUITMENT_INTERVIEW_DELETE);
            Route::post('interviews/{id}/notify', [InterviewController::class, 'notifyCandidate'])
                ->middleware('permission:' . Permissions::RECRUITMENT_NOTIFY);
        });
    });

    // Announcements at root api/ level for Android app
    Route::get('announcements', [AnnouncementController::class, 'index']);
    Route::post('announcements', [AnnouncementController::class, 'store']);
    Route::post('announcements/read', [AnnouncementController::class, 'markAsRead']);
    Route::get('announcements/unread-count', [AnnouncementController::class, 'unreadCount']);
    Route::delete('announcements/{id}', [AnnouncementController::class, 'destroy']);

    // Departments at root api/ level for Android app
    Route::get('app-departments', [DepartmentController::class, 'index']);
});
