<?php
use Illuminate\Support\Facades\Route;
use App\Modules\HR\Http\Controllers\EmployeeController;
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
use App\Modules\HR\Http\Controllers\OnboardingController;
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
        // Employee management
        Route::get('employees/profile', [EmployeeController::class, 'profile']);
        Route::get('employees/compact', [EmployeeController::class, 'compact']);
        Route::post('employees/{employee}/photo', [EmployeeController::class, 'uploadPhoto']);

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
            Route::post('requests', [LeaveRequestController::class, 'store']);
            Route::get('requests/{leaveRequest}', [LeaveRequestController::class, 'show']);
            Route::put('requests/{leaveRequest}', [LeaveRequestController::class, 'update']);
            Route::patch('requests/{leaveRequest}', [LeaveRequestController::class, 'update']);
            Route::post('requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])
                ->middleware('permission:' . Permissions::LEAVE_REQUEST_APPROVE);
            Route::post('requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])
                ->middleware('permission:' . Permissions::LEAVE_REQUEST_APPROVE);
            Route::post('requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);
            Route::post('requests/{leaveRequest}/recall', [LeaveRequestController::class, 'recall'])
                ->middleware('permission:' . Permissions::LEAVE_REQUEST_APPROVE);
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
        Route::get('attendance/summary', [AttendanceController::class, 'summary']);
        Route::get('attendance/sync-logs', [AttendanceController::class, 'syncLogs']);
        Route::post('attendance/sync', [AttendanceController::class, 'sync']);
        Route::post('attendance/upload/preview', [AttendanceController::class, 'uploadPreview']);
        Route::post('attendance/upload', [AttendanceController::class, 'upload']);
        Route::get('attendance/overtime', [AttendanceController::class, 'overtime']);
        Route::get('attendance', [AttendanceController::class, 'index']);
        Route::post('attendance', [AttendanceController::class, 'store']);
        Route::get('attendance/{id}', [AttendanceController::class, 'show']);
        Route::put('attendance/{id}', [AttendanceController::class, 'update']);
        Route::patch('attendance/{id}', [AttendanceController::class, 'update']);
        Route::delete('attendance/{id}', [AttendanceController::class, 'destroy']);

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
            Route::post('tasks/{taskId}/complete', [OnboardingController::class, 'completeTask']);
            Route::post('tasks/{taskId}/reopen', [OnboardingController::class, 'reopenTask']);
            Route::patch('tasks/{taskId}/toggle-optional', [OnboardingController::class, 'toggleOptionalTask']);
            Route::patch('documents/{requirementId}/status', [OnboardingController::class, 'updateDocumentStatus']);
            Route::post('welcome-kit/{itemId}/toggle', [OnboardingController::class, 'toggleWelcomeKitItem']);
        });

        // Internal Recruitment (ATS)
        Route::prefix('recruitment/admin')->group(function () {
            // Jobs management
            Route::get('jobs', [RecruitmentController::class, 'adminJobs']);
            Route::post('jobs', [RecruitmentController::class, 'storeJob']);
            Route::put('jobs/{id}', [RecruitmentController::class, 'updateJob']);
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
