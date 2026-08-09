<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Modules\Admin\Http\Controllers\UserController;
use App\Modules\Admin\Http\Controllers\RoleController;
use App\Modules\Admin\Http\Controllers\PermissionController;
use App\Modules\Admin\Http\Controllers\AdminDashboardController;
use App\Modules\ClientService\Http\Controllers\ClientController;
use App\Modules\ClientService\Http\Controllers\EnquiryController as ClientServiceEnquiryController;
use App\Modules\Projects\Http\Controllers\EnquiryController;
use App\Modules\Projects\Http\Controllers\DashboardController;
use App\Modules\Projects\Http\Controllers\TaskController;
use App\Modules\Projects\Http\Controllers\PhaseDepartmentalTaskController;
use App\Modules\Projects\Http\Controllers\DeliverablesBlueprintController;
use App\Modules\Projects\Models\EnquiryTask;
use App\Models\TaskMaterialsData;
use App\Http\Controllers\SiteSurveyController;
use App\Http\Controllers\DesignAssetController;
use App\Http\Controllers\ProcurementController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\HandoverSurveyController;
use App\Http\Controllers\API\PublicHandoverController;
use App\Modules\Production\Http\Controllers\JobCardController;
use App\Modules\Logistics\Controllers\DriverDeliveryController;
use App\Http\Controllers\DesignRequirementController;
use App\Modules\HR\Http\Controllers\TechnicalLabourController;

use App\Modules\Finance\PettyCash\Controllers\PettyCashController;
use App\Modules\Finance\PettyCash\Controllers\PettyCashTopUpController;
use App\Modules\Finance\PettyCash\Controllers\PettyCashRequisitionController;
use App\Modules\Teams\Controllers\TeamsTaskController;
use App\Modules\Teams\Controllers\TeamMemberController;
use App\Constants\Permissions;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/



// Public routes for Lead Capture Form
Route::get('public/services', [App\Modules\ClientService\Http\Controllers\PublicLeadController::class, 'getServices']);
Route::post('public/leads', [App\Modules\ClientService\Http\Controllers\PublicLeadController::class, 'store']);

// Public routes for Client Handover
Route::get('public/handover/{token}', [App\Http\Controllers\API\PublicHandoverController::class, 'show']);
Route::post('public/handover/{token}', [App\Http\Controllers\API\PublicHandoverController::class, 'store']);

// Public routes for Petty Cash Requisition Sign-off
Route::get('public/pcr/{token}', [PettyCashRequisitionController::class, 'getByToken']);
Route::post('public/pcr/{token}/sign', [PettyCashRequisitionController::class, 'publicSignOff']);
Route::post('public/pcr/{token}/item/{itemId}/sign', [PettyCashRequisitionController::class, 'publicItemSignOff']);
Route::prefix('public')->group(function () {
    Route::post('job-cards/lookup', [JobCardController::class, 'publicLookupOrCreate']);
    Route::post('job-cards', [JobCardController::class, 'publicStore']);
    Route::get('job-cards/{token}', [JobCardController::class, 'publicShow']);
    Route::post('job-cards/{token}', [JobCardController::class, 'publicUpdate']);
    Route::get('technicians', [JobCardController::class, 'publicTechnicians']);

    // Public Petty Cash Requisition routes
    Route::get('petty-cash/form-data', [PettyCashRequisitionController::class, 'getPublicFormData']);
    Route::post('petty-cash/requisitions', [PettyCashRequisitionController::class, 'publicStore']);
    Route::get('petty-cash/payees/search', [PettyCashRequisitionController::class, 'publicSearchPayees']);
    Route::get('petty-cash/requisitions/project-team-members', [PettyCashRequisitionController::class, 'getPublicProjectTeamMembers']);
});

Route::get('/storage/{path}', function ($path) {
    $file = storage_path('app/public/' . $path);

    if (!file_exists($file)) {
        abort(404);
    }

    $mimeType = mime_content_type($file);
    return response()->file($file, [
        'Content-Type' => $mimeType,
        'Cache-Control' => 'public, max-age=31536000',
    ]);
})->where('path', '.*');

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum', 'active']);


Route::get('/user', function () {
    $user = auth()->user()->load(['roles', 'employee:id,profile_photo_path,updated_at']);
    $data = $user->toArray();
    $data['profile_photo_url'] = $user->employee?->profile_photo_url;
    unset($data['employee']);
    return response()->json($data);
})->middleware(['auth:sanctum', 'active']);
//location apis
Route::get('/locations', 'App\Http\Controllers\LocationController@index');
Route::resource('/location', 'App\Http\Controllers\LocationController');

// System Refresh Routes
Route::get('/system/version', [App\Http\Controllers\SystemController::class, 'getVersion']);
Route::post('/system/refresh', [App\Http\Controllers\SystemController::class, 'triggerRefresh'])
    ->middleware(['auth:sanctum', 'active']);
Route::post('/locations', 'App\Http\Controllers\LocationController@store');

// Excel quote download: outside the auth group so plain <a href> links work,
// but every link is a short-lived signed URL minted server-side. Signed and
// validated RELATIVELY (path+query only) so links survive dev-server proxies
// and host differences between APP_URL and the browser origin.
Route::get('projects/tasks/{taskId}/quote/excel/download', [App\Http\Controllers\QuoteController::class, 'downloadExcelQuote'])
    ->name('quote.excel.download')
    ->middleware('signed:relative');

// QR-code driven logistics confirmations: the token in the URL IS the
// credential (random UUID, validated + expiry/revocation checked inside each
// controller's availableLink()). These are meant to be opened by a driver or
// site contact who has no ERP account, so they must live outside auth:sanctum
// — moved here 2026-07-28 after they were found gated behind login.
Route::prefix('projects')->group(function () {
    Route::get('/manifest-submit/{token}', [App\Modules\logisticsTask\Http\Controllers\ManifestSubmissionController::class, 'show']);
    Route::post('/manifest-submit/{token}', [App\Modules\logisticsTask\Http\Controllers\ManifestSubmissionController::class, 'submit']);
    Route::get('/loading-confirm/{token}', [App\Modules\logisticsTask\Http\Controllers\LoadingConfirmationController::class, 'show']);
    Route::patch('/loading-confirm/{token}/items', [App\Modules\logisticsTask\Http\Controllers\LoadingConfirmationController::class, 'updateItems']);
    Route::post('/loading-confirm/{token}/confirm', [App\Modules\logisticsTask\Http\Controllers\LoadingConfirmationController::class, 'confirm']);
    Route::get('/return-confirm/{token}', [App\Modules\logisticsTask\Http\Controllers\ReturnConfirmationController::class, 'show']);
    Route::patch('/return-confirm/{token}/items', [App\Modules\logisticsTask\Http\Controllers\ReturnConfirmationController::class, 'updateItems']);
    Route::post('/return-confirm/{token}/confirm', [App\Modules\logisticsTask\Http\Controllers\ReturnConfirmationController::class, 'confirm']);
});

// Protected Project & Task Routes - 'active' middleware ensures deactivated users are blocked instantly
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Cost collector — the single intake for spend from anywhere in the ERP.
    // Reached over the authenticated mobile API rather than a tokenised public
    // link: casual workers and vendors have no account, so a named staff member
    // captures on their behalf and `payee` stays separate from `submitted_by`.
    Route::prefix('costs')->group(function () {
        Route::get('expense-codes', [App\Modules\Finance\CostCollector\Http\Controllers\ExpenseCodeController::class, 'index']);
        Route::get('expense-codes/families', [App\Modules\Finance\CostCollector\Http\Controllers\ExpenseCodeController::class, 'families']);
        Route::get('expense-codes/{code}', [App\Modules\Finance\CostCollector\Http\Controllers\ExpenseCodeController::class, 'show']);

        // Throttled: this writes to the cost ledger, and the July audit found no
        // rate limiting on any money-moving endpoint in Finance.
        Route::post('evidence', [App\Modules\Finance\CostCollector\Http\Controllers\CostEvidenceController::class, 'store'])
            ->middleware('throttle:60,1');
        Route::post('/', [App\Modules\Finance\CostCollector\Http\Controllers\CostLineController::class, 'store'])
            ->middleware('throttle:60,1');
        Route::get('/', [App\Modules\Finance\CostCollector\Http\Controllers\CostLineController::class, 'index']);
        Route::get('budget-lines/{enquiry}', [App\Modules\Finance\CostCollector\Http\Controllers\CostLineController::class, 'budgetLines']);

        // The project cost account: budget vs committed vs actual, the
        // unbudgeted panel, exception spend and how much of the budget has been
        // answered at all.
        Route::get('accounts', [App\Modules\Finance\CostCollector\Http\Controllers\CostAccountController::class, 'index']);
        Route::get('account/{enquiry}', [App\Modules\Finance\CostCollector\Http\Controllers\CostAccountController::class, 'show']);

        // Verification. Policy-gated, and the service additionally refuses to let
        // anyone verify a cost they reported themselves.
        Route::prefix('verification')->group(function () {
            Route::get('/', [App\Modules\Finance\CostCollector\Http\Controllers\CostVerificationController::class, 'index']);
            Route::post('{cost}/verify', [App\Modules\Finance\CostCollector\Http\Controllers\CostVerificationController::class, 'verify']);
            Route::post('{cost}/query', [App\Modules\Finance\CostCollector\Http\Controllers\CostVerificationController::class, 'query']);
            Route::post('{cost}/reject', [App\Modules\Finance\CostCollector\Http\Controllers\CostVerificationController::class, 'reject']);
            Route::post('{cost}/reverse', [App\Modules\Finance\CostCollector\Http\Controllers\CostVerificationController::class, 'reverse']);
            Route::post('{cost}/resubmit', [App\Modules\Finance\CostCollector\Http\Controllers\CostVerificationController::class, 'resubmit']);
        });
    });

    Route::prefix('support')->group(function () {
        Route::get('tickets/assignees', [App\Modules\Support\Http\Controllers\SupportTicketController::class, 'assignees']);
        Route::get('tickets/metrics', [App\Modules\Support\Http\Controllers\SupportTicketController::class, 'metrics']);
        Route::get('tickets', [App\Modules\Support\Http\Controllers\SupportTicketController::class, 'index']);
        Route::post('tickets', [App\Modules\Support\Http\Controllers\SupportTicketController::class, 'store'])->middleware('throttle:10,1');
        Route::get('tickets/{ticket}', [App\Modules\Support\Http\Controllers\SupportTicketController::class, 'show']);
        Route::patch('tickets/{ticket}', [App\Modules\Support\Http\Controllers\SupportTicketController::class, 'update']);
        Route::post('tickets/{ticket}/replies', [App\Modules\Support\Http\Controllers\SupportTicketController::class, 'reply']);
        Route::post('tickets/{ticket}/confirm-resolution', [App\Modules\Support\Http\Controllers\SupportTicketController::class, 'confirmResolution']);
        Route::post('tickets/{ticket}/attachments', [App\Modules\Support\Http\Controllers\SupportTicketController::class, 'uploadAttachment']);
        Route::get('tickets/{ticket}/attachments/{attachment}', [App\Modules\Support\Http\Controllers\SupportTicketController::class, 'downloadAttachment']);
    });

    // Action Logs
    Route::get('/logs/{type}/{id}', [App\Http\Controllers\ActionLogController::class, 'index']);

    // Event Calendar Routes
    // Get all events
    Route::get('/events', 'App\Http\Controllers\EventController@index');
    
    // Get single event
    Route::get('/events/{id}', 'App\Http\Controllers\EventController@show');
    
    // Save new event
    Route::post('/events/save', 'App\Http\Controllers\EventController@save');
    
    // Update event
    Route::post('/events/update', 'App\Http\Controllers\EventController@update');
    
    // Delete event
    Route::post('/events/delete', 'App\Http\Controllers\EventController@delete');
    
    // Get events by date range
    Route::post('/events/range', 'App\Http\Controllers\EventController@getByDateRange');

    // User permissions and navigation
    Route::get('/user/permissions', function () {
        return response()->json([
            'permissions' => auth()->user()->getNavigationPermissions(),
            'user_permissions' => auth()->user()->getAllPermissions()->pluck('name')->toArray(),
            'roles' => auth()->user()->roles->pluck('name'),
            'departments' => auth()->user()->getAccessibleDepartments()
        ]);
    });

    Route::prefix('projects/tasks/{taskId}/setdown')->group(function () {
        Route::get('/', [App\Modules\setdownTask\Http\Controllers\SetdownTaskController::class, 'show']);
        Route::post('/documentation', [App\Modules\setdownTask\Http\Controllers\SetdownTaskController::class, 'saveDocumentation']);

        // Photos
        Route::post('/photos', [App\Modules\setdownTask\Http\Controllers\SetdownTaskController::class, 'uploadPhoto']);
        Route::delete('/photos/{photoId}', [App\Modules\setdownTask\Http\Controllers\SetdownTaskController::class, 'deletePhoto']);

        // Issues
        Route::post('/issues', [App\Modules\setdownTask\Http\Controllers\SetdownTaskController::class, 'addIssue']);
        Route::put('/issues/{issueId}', [App\Modules\setdownTask\Http\Controllers\SetdownTaskController::class, 'updateIssue']);
        Route::delete('/issues/{issueId}', [App\Modules\setdownTask\Http\Controllers\SetdownTaskController::class, 'deleteIssue']);

        // Checklist
        Route::get('/checklist', [App\Modules\setdownTask\Http\Controllers\SetdownTaskController::class, 'getChecklist']);
        Route::patch('/checklist/items/{itemId}', [App\Modules\setdownTask\Http\Controllers\SetdownTaskController::class, 'updateChecklistItem']);
    });


    // Setup Task management routes
    Route::prefix('projects/tasks/{taskId}/setup')->group(function () {
        Route::get('/', [App\Modules\setupTask\Http\Controllers\SetupTaskController::class, 'show']);
        Route::post('/documentation', [App\Modules\setupTask\Http\Controllers\SetupTaskController::class, 'saveDocumentation']);

        // Photos
        Route::post('/photos', [App\Modules\setupTask\Http\Controllers\SetupTaskController::class, 'uploadPhoto']);
        Route::delete('/photos/{photoId}', [App\Modules\setupTask\Http\Controllers\SetupTaskController::class, 'deletePhoto']);

        // Issues
        Route::post('/issues', [App\Modules\setupTask\Http\Controllers\SetupTaskController::class, 'addIssue']);
        Route::put('/issues/{issueId}', [App\Modules\setupTask\Http\Controllers\SetupTaskController::class, 'updateIssue']);
        Route::delete('/issues/{issueId}', [App\Modules\setupTask\Http\Controllers\SetupTaskController::class, 'deleteIssue']);
    });

    // Archival Task management routes
    Route::prefix('projects/tasks/{taskId}/archival')->group(function () {
        Route::get('/', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'index']);
        Route::post('/', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'store']);
        Route::put('/{reportId}', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'update']);
        Route::delete('/{reportId}', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'destroy']);
        Route::get('/auto-populate', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'autoPopulate']);
        Route::post('/{reportId}/status', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'changeStatus']);
        Route::get('/{reportId}/analyze', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'analyze']);

        // PDF
        Route::get('/{reportId}/pdf', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'generatePdf']);

        // Attachments
        Route::post('/{reportId}/attachments', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'uploadAttachment']);
        Route::delete('/{reportId}/attachments/{attachmentId}', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'deleteAttachment']);
    });


    // Task management routes
    Route::get('tasks', [TaskController::class, 'getDepartmentalTasks']);
    Route::get('tasks/{taskId}', [TaskController::class, 'show']);
    Route::put('tasks/{taskId}/status', [TaskController::class, 'updateTaskStatus'])->middleware(\App\Http\Middleware\EnsureFinancialClearance::class);
    Route::put('tasks/{taskId}/assign', [TaskController::class, 'assignTask']);
    Route::put('tasks/{taskId}', [TaskController::class, 'update']);
    Route::get('enquiries/{enquiryId}/tasks', [TaskController::class, 'getEnquiryTasks']);
    Route::get('all-enquiry-tasks', [TaskController::class, 'getAllEnquiryTasks']);

    // Enquiry task assignment routes
    Route::post('enquiry-tasks/{task}/assign', [TaskController::class, 'assignEnquiryTask']);
    Route::put('enquiry-tasks/{task}/reassign', [TaskController::class, 'reassignEnquiryTask']);
    Route::put('enquiry-tasks/{taskId}/release', [TaskController::class, 'releaseEnquiryTask']);
    Route::get('enquiry-tasks/{taskId}/assignment-history', [TaskController::class, 'getTaskAssignmentHistory']);
    Route::put('enquiry-tasks/{taskId}', [TaskController::class, 'updateEnquiryTask']);

    // Project management
    Route::get('projects', function () {
        $query = \App\Models\Project::with('enquiry.client');

        if (request()->has('enquiry_id')) {
            $query->where('enquiry_id', request()->enquiry_id);
        }

        return response()->json([
            'data' => $query->get(),
            'message' => 'Projects retrieved successfully'
        ]);
    }); // No permission for debugging

    // Enquiry management
    Route::get('enquiries', [EnquiryController::class, 'index']);
    Route::get('enquiries/{enquiry}', [EnquiryController::class, 'show']);
    Route::post('enquiries', [EnquiryController::class, 'store']);
    Route::put('enquiries/{enquiry}', [EnquiryController::class, 'update']);
    Route::delete('enquiries/{enquiry}', [EnquiryController::class, 'destroy']);
    Route::put('enquiries/{enquiry}/phases/{phase}', [EnquiryController::class, 'updatePhase']);
    Route::post('enquiries/{enquiry}/approve-quote', [EnquiryController::class, 'approveQuote']);

    // Notifications routes live in app/Modules/Notifications/Routes/api.php. They were
    // once duplicated here as inline closures against Laravel's native Notifiable
    // table, which the 2026_06_30 legacy-migration dropped in favor of
    // app_notifications — so this file must not register any notifications/* routes
    // the module already owns.

    Route::post('/user/onesignal-token', function (Request $request) {
        $user = auth()->user();
        $user->update(['onesignal_player_id' => $request->player_id]);
        return response(['success' => true]);
    });

    Route::get('/announcements', 'App\Http\Controllers\AnnouncementController@index');
    Route::post('/announcements', 'App\Http\Controllers\AnnouncementController@store');
    Route::post('/announcements/read', 'App\Http\Controllers\AnnouncementController@markAsRead');
    Route::get('/announcements/unread-count', 'App\Http\Controllers\AnnouncementController@unreadCount');
    Route::delete('/announcements/{id}', 'App\Http\Controllers\AnnouncementController@destroy');

    // Event Calendar Routes
    // Get all events
    Route::get('/events', 'App\Http\Controllers\EventController@index');

    // Get single event
    Route::get('/events/{id}', 'App\Http\Controllers\EventController@show');

    // Save new event
    Route::post('/events/save', 'App\Http\Controllers\EventController@save');

    // Update event
    Route::post('/events/update', 'App\Http\Controllers\EventController@update');

    // Delete event
    Route::post('/events/delete', 'App\Http\Controllers\EventController@delete');

    // Get events by date range
    Route::post('/events/range', 'App\Http\Controllers\EventController@getByDateRange');

    // HR routes live in app/Modules/HR/Routes/api.php. They were once duplicated
    // here; the duplicate registrations shadowed the module's static routes
    // (e.g. employees/stats resolved as employees/{employee} → 404), so this
    // file must not register any hr/* routes the module already owns.

    // Admin Module Routes
    Route::prefix('admin')->group(function () {
        // User management
        Route::get('users/available-employees', [UserController::class, 'availableEmployees'])
            ->middleware('permission:' . Permissions::USER_READ . ',' . Permissions::TASK_ASSIGN);
        Route::get('dashboard/stats', [AdminDashboardController::class, 'index'])
            ->middleware('permission:' . Permissions::USER_READ);
        Route::get('audit-logs', [AdminDashboardController::class, 'auditTrail'])
            ->middleware('permission:' . Permissions::USER_READ);

        // Account state (distinct from delete) + bulk
        Route::post('users/bulk-status', [UserController::class, 'bulkUpdateStatus'])
            ->middleware('permission:' . Permissions::USER_UPDATE);
        Route::post('users/{user}/activate', [UserController::class, 'activate'])
            ->middleware('permission:' . Permissions::USER_ACTIVATE);
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])
            ->middleware('permission:' . Permissions::USER_DEACTIVATE);

        // Session / token management (force-logout)
        Route::get('users/{user}/tokens', [UserController::class, 'tokens'])
            ->middleware('permission:' . Permissions::USER_READ);
        Route::delete('users/{user}/tokens/{tokenId}', [UserController::class, 'revokeToken'])
            ->middleware('permission:' . Permissions::USER_UPDATE);
        Route::delete('users/{user}/tokens', [UserController::class, 'revokeAllTokens'])
            ->middleware('permission:' . Permissions::USER_UPDATE);

        Route::apiResource('users', UserController::class)->parameters([
            'users' => 'user'
        ])
            ->middlewareFor('index',   'permission:' . Permissions::USER_READ)
            ->middlewareFor('store',   'permission:' . Permissions::USER_CREATE)
            ->middlewareFor('show',    'permission:' . Permissions::USER_READ)
            ->middlewareFor('update',  'permission:' . Permissions::USER_UPDATE)
            ->middlewareFor('destroy', 'permission:' . Permissions::USER_DELETE);

        // Role and Permission management
        Route::apiResource('roles', RoleController::class)
            ->middlewareFor('index',   'permission:' . Permissions::ROLE_READ)
            ->middlewareFor('store',   'permission:' . Permissions::ROLE_CREATE)
            ->middlewareFor('show',    'permission:' . Permissions::ROLE_READ)
            ->middlewareFor('update',  'permission:' . Permissions::ROLE_UPDATE)
            ->middlewareFor('destroy', 'permission:' . Permissions::ROLE_DELETE);
        Route::post('roles/{role}/clone', [RoleController::class, 'clone'])
            ->middleware('permission:' . Permissions::ROLE_CREATE);

        Route::get('permissions/grouped', [PermissionController::class, 'grouped'])
            ->middleware('permission:' . Permissions::ROLE_READ);
        Route::apiResource('permissions', PermissionController::class)
            ->middlewareFor('index', 'permission:' . Permissions::ROLE_READ); // Admin can view permissions
    });

    // Project Officers endpoint (accessible by Client Service for enquiry assignment)
    Route::get('project-officers', [UserController::class, 'getProjectOfficers'])
        ->middleware('permission:' . Permissions::USER_READ . ',' . Permissions::ENQUIRY_ASSIGN . ',' . Permissions::TASK_ASSIGN);

    // Users endpoint for task assignment (accessible by Project Managers)
    Route::get('users', [UserController::class, 'index'])
        ->middleware('permission:' . Permissions::USER_READ . ',' . Permissions::TASK_ASSIGN);

    // ClientService Module Routes
    Route::prefix('clientservice')->group(function () {
        Route::get('dashboard', [App\Modules\ClientService\Http\Controllers\DashboardController::class, 'index']);
        Route::get('handovers', [\App\Modules\ClientService\Http\Controllers\HandoverController::class, 'index']);
        Route::get('handovers/stats', [\App\Modules\ClientService\Http\Controllers\HandoverController::class, 'stats']);
        Route::get('handovers/pending', [\App\Modules\ClientService\Http\Controllers\HandoverController::class, 'pending']);
        Route::get('handovers/awaiting-review', [\App\Modules\ClientService\Http\Controllers\HandoverController::class, 'awaitingReview']);
        Route::get('handovers/{id}', [\App\Modules\ClientService\Http\Controllers\HandoverController::class, 'show'])->whereNumber('id');
        Route::post('handovers/{id}/review', [\App\Modules\ClientService\Http\Controllers\HandoverReviewController::class, 'review'])->whereNumber('id');
        // NCR reports
        Route::get('ncr', [\App\Modules\ClientService\Http\Controllers\NcrController::class, 'index']);
        Route::patch('ncr/{id}', [\App\Modules\ClientService\Http\Controllers\NcrController::class, 'update'])->whereNumber('id');
        // Client management
        Route::get('clients', [ClientController::class, 'index']);
        Route::get('clients/export', [ClientController::class, 'export'])
            ->middleware('permission:' . Permissions::CLIENT_READ);
        Route::get('clients/lead-sources', [ClientController::class, 'getLeadSources'])
            ->middleware('permission:' . Permissions::CLIENT_READ);
        Route::get('clients/active-delivery-ids', [\App\Modules\ClientService\Http\Controllers\ClientProfileController::class, 'activeDeliveryClients'])
            ->middleware('permission:' . Permissions::CLIENT_READ);
        Route::get('clients/{client}', [ClientController::class, 'show'])
            ->middleware('permission:' . Permissions::CLIENT_READ);
        Route::post('clients', [ClientController::class, 'store'])
            ->middleware('permission:' . Permissions::CLIENT_CREATE);
        Route::put('clients/{client}', [ClientController::class, 'update'])
            ->middleware('permission:' . Permissions::CLIENT_UPDATE);
        Route::patch('clients/{client}/toggle-status', [ClientController::class, 'toggleStatus'])
            ->middleware('permission:' . Permissions::CLIENT_UPDATE);
        Route::delete('clients/{client}', [ClientController::class, 'destroy'])
            ->middleware('permission:' . Permissions::CLIENT_DELETE);

        // Client 360 profile + interaction timeline
        Route::get('clients/{client}/profile', [\App\Modules\ClientService\Http\Controllers\ClientProfileController::class, 'show'])
            ->middleware('permission:' . Permissions::CLIENT_READ);
        Route::get('clients/{client}/active-delivery', [\App\Modules\ClientService\Http\Controllers\ClientProfileController::class, 'activeDelivery'])
            ->middleware('permission:' . Permissions::CLIENT_READ);
        Route::get('clients/{client}/interactions', [\App\Modules\ClientService\Http\Controllers\ClientInteractionController::class, 'index'])
            ->middleware('permission:' . Permissions::CLIENT_READ);
        Route::post('clients/{client}/interactions', [\App\Modules\ClientService\Http\Controllers\ClientInteractionController::class, 'store'])
            ->middleware('permission:' . Permissions::CLIENT_UPDATE);
        Route::delete('clients/{client}/interactions/{id}', [\App\Modules\ClientService\Http\Controllers\ClientInteractionController::class, 'destroy'])
            ->middleware('permission:' . Permissions::CLIENT_UPDATE);

        // Enquiry management
        Route::get('enquiries', [ClientServiceEnquiryController::class, 'index'])
            ->middleware('permission:' . Permissions::ENQUIRY_READ);
        Route::get('enquiries/{enquiry}', [ClientServiceEnquiryController::class, 'show'])
            ->middleware('permission:' . Permissions::ENQUIRY_READ);
        Route::post('enquiries', [ClientServiceEnquiryController::class, 'store'])
            ->middleware('permission:' . Permissions::ENQUIRY_CREATE);
        Route::put('enquiries/{enquiry}', [ClientServiceEnquiryController::class, 'update'])
            ->middleware('permission:' . Permissions::ENQUIRY_UPDATE);
        Route::delete('enquiries/{enquiry}', [ClientServiceEnquiryController::class, 'destroy'])
            ->middleware('permission:' . Permissions::ENQUIRY_DELETE);
        // Lead management
        Route::get('leads', [App\Modules\ClientService\Http\Controllers\PublicLeadController::class, 'index'])
            ->middleware('permission:' . Permissions::ENQUIRY_READ);
        Route::get('leads-pipeline-stats', [App\Modules\ClientService\Http\Controllers\PublicLeadController::class, 'pipelineStats'])
            ->middleware('permission:' . Permissions::ENQUIRY_READ);
        Route::get('leads/{lead}', [App\Modules\ClientService\Http\Controllers\PublicLeadController::class, 'show'])
            ->middleware('permission:' . Permissions::ENQUIRY_READ);
        Route::put('leads/{lead}', [App\Modules\ClientService\Http\Controllers\PublicLeadController::class, 'update'])
            ->middleware('permission:' . Permissions::ENQUIRY_UPDATE);
        Route::delete('leads/{lead}', [App\Modules\ClientService\Http\Controllers\PublicLeadController::class, 'destroy'])
            ->middleware('permission:' . Permissions::ENQUIRY_DELETE);
        Route::post('leads/{lead}/convert', [App\Modules\ClientService\Http\Controllers\PublicLeadController::class, 'convert'])
            ->middleware('permission:' . Permissions::ENQUIRY_UPDATE);
    });

    // Materials management routes
    Route::prefix('projects/tasks/{taskId}/materials')->group(function () {
        Route::get('/', [App\Http\Controllers\MaterialsController::class, 'getMaterialsData']);
        Route::post('/', [App\Http\Controllers\MaterialsController::class, 'saveMaterialsData']);
        Route::get('/approved-quote-preview', [App\Http\Controllers\MaterialsController::class, 'previewApprovedQuoteImport']);
        Route::post('/import-approved-quote', [App\Http\Controllers\MaterialsController::class, 'importApprovedQuote']);

        // Material versioning routes
        Route::post('/versions', [App\Http\Controllers\MaterialsController::class, 'createMaterialVersion']);
        Route::get('/versions', [App\Http\Controllers\MaterialsController::class, 'getMaterialVersions']);
        Route::post('/versions/{versionId}/restore', [App\Http\Controllers\MaterialsController::class, 'restoreMaterialVersion']);

        // Excel template download and upload
        Route::get('/template/download', [App\Http\Controllers\MaterialsController::class, 'downloadTemplate']);
        Route::post('/template/upload', [App\Http\Controllers\MaterialsController::class, 'uploadTemplate']);

        // Delete element
        Route::delete('/elements/{elementId}', [App\Http\Controllers\MaterialsController::class, 'deleteElement']);

        // Push selected elements to the enquiry's Logistics loading sheet
        Route::post('/push-to-logistics', [App\Http\Controllers\MaterialsController::class, 'pushParticularsToLogistics']);

        // PDF Generation
        Route::get('/pdf', [App\Http\Controllers\MaterialsController::class, 'downloadPdf']);
    });


    // Budget management routes
    Route::prefix('projects/tasks/{taskId}/budget')->group(function () {
        Route::get('/', [App\Http\Controllers\BudgetController::class, 'getBudgetData']);;
        Route::post('/', [App\Http\Controllers\BudgetController::class, 'saveBudgetData']);
        Route::post('/import-materials', [App\Http\Controllers\BudgetController::class, 'importMaterials']);
        Route::get('/check-materials-update', [App\Http\Controllers\BudgetController::class, 'checkMaterialsUpdate']);
        Route::get('/pdf', [App\Http\Controllers\BudgetController::class, 'downloadPdf']);

        // Budget versioning routes
        Route::post('/versions', [App\Http\Controllers\BudgetController::class, 'createBudgetVersion']);
        Route::get('/versions', [App\Http\Controllers\BudgetController::class, 'getBudgetVersions']);
        Route::get('/versions/{versionId}', [App\Http\Controllers\BudgetController::class, 'getBudgetVersion']);
        Route::post('/versions/{versionId}/restore', [App\Http\Controllers\BudgetController::class, 'restoreBudgetVersion']);

        // Budget additions management
        Route::get('/additions', [App\Http\Controllers\BudgetAdditionController::class, 'index']);
        Route::post('/additions', [App\Http\Controllers\BudgetAdditionController::class, 'store']);
        Route::post('/additions/from-material', [App\Http\Controllers\BudgetAdditionController::class, 'createFromMaterial']);
        Route::get('/additions/{additionId}', [App\Http\Controllers\BudgetAdditionController::class, 'show']);
        Route::put('/additions/{additionId}', [App\Http\Controllers\BudgetAdditionController::class, 'update']);
        Route::post('/additions/{additionId}/approve', [App\Http\Controllers\BudgetAdditionController::class, 'approve']);
        Route::delete('/additions/{additionId}', [App\Http\Controllers\BudgetAdditionController::class, 'destroy']);
    });

    // Quote management routes
    Route::prefix('projects/tasks/{taskId}/quote')->middleware('quote.access:quote')->group(function () {
        Route::get('/', [App\Http\Controllers\QuoteController::class, 'getQuoteData']);
        Route::post('/', [App\Http\Controllers\QuoteController::class, 'saveQuoteData']);
        Route::post('/import-budget', [App\Http\Controllers\QuoteController::class, 'importBudgetData']);
        Route::get('/budget-status', [App\Http\Controllers\QuoteController::class, 'checkBudgetStatus']);
        Route::get('/changes-preview', [App\Http\Controllers\QuoteController::class, 'previewBudgetChanges']);
        Route::post('/smart-merge', [App\Http\Controllers\QuoteController::class, 'smartMergeBudget']);
        // Server-side scope → quote sync (creates/updates/removes material elements to match enquiry scope)
        Route::post('/sync-scope', [App\Http\Controllers\QuoteController::class, 'syncScope']);

        // Excel quote upload (alternative path to the in-system builder)
        Route::post('/upload-excel', [App\Http\Controllers\QuoteController::class, 'uploadExcelQuote']);
        // Detect the workbook total without storing (pre-fills the amount field)
        Route::post('/inspect-excel', [App\Http\Controllers\QuoteController::class, 'inspectExcelQuote']);
        Route::delete('/excel', [App\Http\Controllers\QuoteController::class, 'removeExcelQuote']);

        // Quote versioning routes (standardized to match materials/budget pattern)
        Route::post('/versions', [App\Http\Controllers\QuoteController::class, 'createVersion']);
        Route::get('/versions', [App\Http\Controllers\QuoteController::class, 'getVersions']);
        Route::post('/versions/{versionId}/restore', [App\Http\Controllers\QuoteController::class, 'restoreVersion']);
        Route::delete('/versions/{versionId}', [App\Http\Controllers\QuoteController::class, 'deleteVersion']);
        Route::delete('/versions', [App\Http\Controllers\QuoteController::class, 'clearVersions']);

        // Legacy routes (keep for backward compatibility)
        Route::post('/version', [App\Http\Controllers\QuoteController::class, 'createVersion']);
        Route::get('/version/{versionId}', [App\Http\Controllers\QuoteController::class, 'getVersion']);
        Route::post('/restore/{versionId}', [App\Http\Controllers\QuoteController::class, 'restoreVersion']);
    });

    // Quote approval routes
    Route::prefix('projects/tasks/{taskId}/approval')->middleware('quote.access:quote_approval')->group(function () {
        Route::get('/', [App\Http\Controllers\QuoteController::class, 'getApprovalData']);
        Route::post('/', [App\Http\Controllers\QuoteController::class, 'saveApproval']);
    });

    // Procurement management routes
    Route::prefix('projects/tasks/{taskId}/procurement')->group(function () {
        Route::get('/', [App\Http\Controllers\ProcurementController::class, 'getProcurementData']);
        Route::post('/', [App\Http\Controllers\ProcurementController::class, 'saveProcurementData']);
        Route::post('/import-budget', [App\Http\Controllers\ProcurementController::class, 'importBudgetData']);
        Route::get('/pdf', [App\Http\Controllers\ProcurementController::class, 'downloadPdf']);
    });

    // Procurement utility routes
    Route::get('projects/procurement/vendor-suggestions', [App\Http\Controllers\ProcurementController::class, 'getVendorSuggestions']);


    // Handover management routes
    Route::prefix('projects/tasks/{taskId}/handover')->group(function () {
        Route::get('/survey', [HandoverSurveyController::class, 'show']);
        Route::post('/survey', [HandoverSurveyController::class, 'store']);
        Route::delete('/survey', [HandoverSurveyController::class, 'destroy']);
        Route::post('/survey/generate-token', [HandoverSurveyController::class, 'generateToken']);
    });

    // Logistics, Setup, and Setdown routes consolidated under 'projects' prefix below.

    // Teams management routes
    Route::prefix('projects/tasks/{taskId}/teams')->group(function () {
        // Team CRUD operations
        Route::get('/', [App\Modules\Teams\Controllers\TeamsTaskController::class, 'index']);
        Route::post('/', [App\Modules\Teams\Controllers\TeamsTaskController::class, 'store']);
        Route::put('/{teamTaskId}', [App\Modules\Teams\Controllers\TeamsTaskController::class, 'update']);
        Route::delete('/{teamTaskId}', [App\Modules\Teams\Controllers\TeamsTaskController::class, 'destroy']);

        // Bulk assign teams
        Route::post('/bulk-assign', [App\Modules\Teams\Controllers\TeamsTaskController::class, 'bulkAssign']);

        // Team member management
        Route::prefix('/{teamTaskId}/members')->group(function () {
            Route::get('/', [App\Modules\Teams\Controllers\TeamMemberController::class, 'index']);
            Route::post('/', [App\Modules\Teams\Controllers\TeamMemberController::class, 'store']);
            Route::put('/{memberId}', [App\Modules\Teams\Controllers\TeamMemberController::class, 'update']);
            Route::delete('/{memberId}', [App\Modules\Teams\Controllers\TeamMemberController::class, 'destroy']);
        });
    });

    // Team categories and types (helper routes)
    Route::get('/teams/categories', [App\Modules\Teams\Controllers\TeamsTaskController::class, 'getTeamCategories']);
    Route::get('/teams/types', [App\Modules\Teams\Controllers\TeamsTaskController::class, 'getTeamTypes']);

    // Get quote by enquiry ID (for frontend access)
    Route::get('projects/enquiries/{enquiryId}/quote', function ($enquiryId) {
        $quoteTask = \App\Modules\Projects\Models\EnquiryTask::where('project_enquiry_id', $enquiryId)
            ->where('type', 'quote')
            ->first();

        if (!$quoteTask) {
            return response()->json(['message' => 'Quote task not found'], 404);
        }

        return app(\App\Http\Controllers\QuoteController::class)->getQuoteData($quoteTask->id);
    });

    // Element templates
    Route::get('projects/element-templates', [App\Http\Controllers\MaterialsController::class, 'getElementTemplates']);
    Route::post('projects/element-templates', [App\Http\Controllers\MaterialsController::class, 'createElementTemplate']);

    // Element types management
    Route::get('projects/element-types', [App\Http\Controllers\API\ElementTypeController::class, 'index']);
    Route::post('projects/element-types', [App\Http\Controllers\API\ElementTypeController::class, 'store']);
    Route::get('projects/element-types/{id}', [App\Http\Controllers\API\ElementTypeController::class, 'show']);
    Route::put('projects/element-types/{id}', [App\Http\Controllers\API\ElementTypeController::class, 'update']);
    Route::delete('projects/element-types/{id}', [App\Http\Controllers\API\ElementTypeController::class, 'destroy']);

    // Get materials by enquiry ID (for budget import)
    Route::get('projects/enquiries/{enquiryId}/materials', [App\Http\Controllers\MaterialsController::class, 'getMaterialsByEnquiry']);

    // Get project by enquiry ID
    Route::get('projects/enquiries/{enquiryId}/project', [App\Modules\Projects\Http\Controllers\EnquiryController::class, 'getByProjectEnquiryId']);

    // Projects Module Routes
    Route::prefix('projects')->group(function () {
        // Site survey management
        Route::apiResource('site-surveys', SiteSurveyController::class);
        Route::get('site-surveys/{survey}/pdf', [SiteSurveyController::class, 'generatePDF']);
        Route::post('tasks/{taskId}/survey/photos', [SiteSurveyController::class, 'uploadPhoto']);
        Route::get('tasks/{taskId}/survey/pdf', [SiteSurveyController::class, 'downloadTaskPdf']);
        Route::delete('tasks/{taskId}/survey/photos/{photoId}', [SiteSurveyController::class, 'deletePhoto']);

        // Deliverables Blueprint Routes
        Route::apiResource('deliverables-blueprints', DeliverablesBlueprintController::class);

        // Logistics Task Routes
        Route::prefix('tasks/{taskId}/logistics')->group(function () {
            Route::get('/', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'show']);
            Route::get('/pdf', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'generatePdf']);
            Route::post('/planning', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'savePlanning']);
            Route::get('/transport-items',   [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'getTransportItems']);
            Route::post('/transport-items', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'addTransportItem']);
            Route::put('/transport-items/{itemId}', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'updateTransportItem']);
            Route::delete('/transport-items/{itemId}', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'deleteTransportItem']);
            Route::post('/import-production-elements', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'importProductionElements']);
            Route::get('/checklist', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'getChecklist']);
            Route::post('/checklist', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'updateChecklist']);
            Route::post('/checklist/generate',                    [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'generateChecklist']);
            Route::get('/checklist/stats',                       [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'getChecklistStats']);
            Route::get('/checklist/pdf',                         [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'downloadChecklistPdf']);
            Route::post('/return-checklist/generate',            [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'generateReturnChecklist']);
            Route::post('/return-checklist/authorize',           [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'authorizeReturn']);
            Route::get('/return-checklist/pdf',                  [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'downloadReturnChecklistPdf']);
            Route::get('/manifest-submissions', [App\Modules\logisticsTask\Http\Controllers\ManifestSubmissionController::class, 'index']);
            Route::post('/manifest-submission-links', [App\Modules\logisticsTask\Http\Controllers\ManifestSubmissionController::class, 'store']);
            Route::delete('/manifest-submission-links/{link}', [App\Modules\logisticsTask\Http\Controllers\ManifestSubmissionController::class, 'revoke']);
            Route::patch('/manifest-submissions/{submission}/review', [App\Modules\logisticsTask\Http\Controllers\ManifestSubmissionController::class, 'review']);
            Route::get('/loading-confirmation-links', [App\Modules\logisticsTask\Http\Controllers\LoadingConfirmationController::class, 'index']);
            Route::post('/loading-confirmation-links', [App\Modules\logisticsTask\Http\Controllers\LoadingConfirmationController::class, 'store']);
            Route::delete('/loading-confirmation-links/{link}', [App\Modules\logisticsTask\Http\Controllers\LoadingConfirmationController::class, 'revoke']);
            Route::get('/return-confirmation-links', [App\Modules\logisticsTask\Http\Controllers\ReturnConfirmationController::class, 'index']);
            Route::post('/return-confirmation-links', [App\Modules\logisticsTask\Http\Controllers\ReturnConfirmationController::class, 'store']);
            Route::delete('/return-confirmation-links/{link}', [App\Modules\logisticsTask\Http\Controllers\ReturnConfirmationController::class, 'revoke']);
        });

        // Archival Task Routes (Project Memorial Report)
        Route::prefix('tasks/{taskId}/archival')->group(function () {
            Route::get('/', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'index']);
            Route::post('/', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'store']);
            Route::put('/{reportId}', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'update']);
            Route::delete('/{reportId}', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'destroy']);
            Route::post('/{reportId}/attachments', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'uploadAttachment']);
            Route::delete('/{reportId}/attachments/{attachmentId}', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'deleteAttachment']);
            Route::get('/auto-populate', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'autoPopulate']);
            Route::post('/{reportId}/status', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'changeStatus']);
            Route::get('/{reportId}/analyze', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'analyze']);
        });

        // Production Task Routes
        Route::prefix('tasks/{taskId}/production')->group(function () {
            Route::get('/', [App\Http\Controllers\ProductionController::class, 'getProductionData']);
            Route::put('/', [App\Http\Controllers\ProductionController::class, 'saveProductionData']);
            Route::post('/import-materials', [App\Http\Controllers\ProductionController::class, 'importMaterialsData']);
            Route::post('/generate-checkpoints', [App\Http\Controllers\ProductionController::class, 'generateQualityCheckpoints']);
            Route::delete('/quality-checkpoints', [App\Http\Controllers\ProductionController::class, 'deleteQualityCheckpoints']);
        });



        // Drivers endpoint for logistics
        Route::get('/drivers', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'getDrivers']);

        // Logistics Log Routes
        Route::apiResource('logistics-log', App\Http\Controllers\LogisticsLogController::class);

        // Dashboard route (lean: one endpoint → KPIs + ranked signals)
        Route::get('dashboard', [DashboardController::class, 'dashboard']);

        // Task management routes
        Route::get('tasks', [TaskController::class, 'getDepartmentalTasks']);
        Route::get('tasks/{taskId}', [TaskController::class, 'show']);
        Route::put('tasks/{taskId}/status', [TaskController::class, 'updateTaskStatus'])->middleware(\App\Http\Middleware\EnsureFinancialClearance::class);
        Route::put('tasks/{taskId}/assign', [TaskController::class, 'assignTask']);
        Route::put('tasks/{taskId}', [TaskController::class, 'update']);
        Route::get('enquiries/{enquiryId}/tasks', [TaskController::class, 'getEnquiryTasks']);
        Route::get('all-enquiry-tasks', [TaskController::class, 'getAllEnquiryTasks']);

        // Enquiry task assignment routes
        Route::post('enquiry-tasks/{taskId}/assign', [TaskController::class, 'assignEnquiryTask']);
        Route::put('enquiry-tasks/{taskId}/reassign', [TaskController::class, 'reassignEnquiryTask']);
        Route::put('enquiry-tasks/{taskId}/release', [TaskController::class, 'releaseEnquiryTask']);
        Route::get('enquiry-tasks/{taskId}/assignment-history', [TaskController::class, 'getTaskAssignmentHistory']);
        Route::put('enquiry-tasks/{taskId}', [TaskController::class, 'updateEnquiryTask']);

        // Project management
        Route::get('projects', function () {
            $query = \App\Models\Project::with('enquiry.client');

            if (request()->has('enquiry_id')) {
                $query->where('enquiry_id', request()->enquiry_id);
            }

            return response()->json([
                'data' => $query->get(),
                'message' => 'Projects retrieved successfully'
            ]);
        }); // No permission for debugging

        // Enquiry management
        Route::get('approved-wng', [EnquiryController::class, 'approvedWngList']);
        Route::get('enquiries', [EnquiryController::class, 'index']);
        Route::get('enquiries/{enquiry}', [EnquiryController::class, 'show']);
        Route::post('enquiries', [EnquiryController::class, 'store']);
        Route::put('enquiries/{enquiry}', [EnquiryController::class, 'update']);
        Route::delete('enquiries/{enquiry}', [EnquiryController::class, 'destroy']);
        Route::put('enquiries/{enquiry}/phases/{phase}', [EnquiryController::class, 'updatePhase']);
        Route::put('enquiries/{enquiry}/deliverables', [EnquiryController::class, 'updateDeliverables']);
        Route::post('enquiries/{enquiry}/approve-quote', [EnquiryController::class, 'approveQuote'])
            ->middleware('quote.access');
        Route::get('enquiries/{enquiry}/workflow-state', [EnquiryController::class, 'workflowState']);
        Route::middleware('quote.access')->group(function () {
            Route::get('enquiries/{enquiry}/finance-progress', [EnquiryController::class, 'getFinanceProgress']);
            Route::get('enquiries/{enquiry}/governance-trace', [EnquiryController::class, 'getGovernanceTrace']);
            Route::post('enquiries/{enquiry}/quote-waiver', [EnquiryController::class, 'waiveQuoteRequirement']);
            Route::post('enquiries/{enquiry}/payments', [EnquiryController::class, 'logPayment']);
            Route::put('enquiries/{enquiry}/payments/{payment}', [EnquiryController::class, 'updatePayment']);
            Route::delete('enquiries/{enquiry}/payments/{payment}', [EnquiryController::class, 'deletePayment']);
            Route::post('enquiries/{enquiry}/release', [EnquiryController::class, 'releaseProject']);
        });
        Route::get('enquiries/{enquiry}/completion-readiness', [EnquiryController::class, 'completionReadiness']);
        Route::post('enquiries/{enquiry}/complete', [EnquiryController::class, 'completeProject']);
        Route::get('enquiries/{enquiry}/closure-readiness', [EnquiryController::class, 'closureReadiness']);
        Route::post('enquiries/{enquiry}/close', [EnquiryController::class, 'closeProject']);

        // Available project officers for enquiry assignment
        Route::get('available-project-officers', function () {
            $projectOfficers = \App\Models\User::whereHas('roles', function ($query) {
                $query->where('name', 'Project Officer');
            })
            ->where('is_active', true)
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

            return response()->json([
                'data' => $projectOfficers,
                'message' => 'Available project officers retrieved successfully'
            ]);
        });

        // Departmental tasks management
        Route::get('departmental-tasks', [PhaseDepartmentalTaskController::class, 'index']); // No permission for debugging
        Route::post('departmental-tasks', [PhaseDepartmentalTaskController::class, 'store']); // No permission for debugging
        Route::get('departmental-tasks/{task}', [PhaseDepartmentalTaskController::class, 'show']); // No permission for debugging
        Route::put('departmental-tasks/{task}', [PhaseDepartmentalTaskController::class, 'update']); // No permission for debugging
        Route::delete('departmental-tasks/{task}', [PhaseDepartmentalTaskController::class, 'destroy']); // No permission for debugging
        Route::post('departmental-tasks/{task}/action', [PhaseDepartmentalTaskController::class, 'performAction']); // No permission for debugging
        Route::get('departmental-tasks-stats', [PhaseDepartmentalTaskController::class, 'getStats']); // No permission for debugging




        // Materials management
            Route::get('tasks/{taskId}/materials', [App\Http\Controllers\MaterialsController::class, 'getMaterialsData']);
            Route::post('tasks/{taskId}/materials', [App\Http\Controllers\MaterialsController::class, 'saveMaterialsData']);
            Route::get('tasks/{taskId}/materials/approved-quote-preview', [App\Http\Controllers\MaterialsController::class, 'previewApprovedQuoteImport']);
            Route::post('tasks/{taskId}/materials/import-approved-quote', [App\Http\Controllers\MaterialsController::class, 'importApprovedQuote']);
            Route::get('enquiries/{enquiryId}/materials', [App\Http\Controllers\MaterialsController::class, 'getMaterialsByEnquiry']);
            Route::get('element-templates', [App\Http\Controllers\MaterialsController::class, 'getElementTemplates']);
            Route::post('element-templates', [App\Http\Controllers\MaterialsController::class, 'createElementTemplate']);

            // Materials approval endpoints
            Route::post('tasks/{taskId}/materials/approve/{department}', [App\Http\Controllers\MaterialsController::class, 'approveMaterials']);
            Route::get('tasks/{taskId}/materials/approval-status', [App\Http\Controllers\MaterialsController::class, 'getApprovalStatus']);

            // Materials configuration
            Route::get('materials/config', [App\Http\Controllers\MaterialsController::class, 'getMaterialsConfig']);

        // Design asset management
        Route::prefix('enquiry-tasks/{task}/design-assets')->group(function () {
            Route::get('/', [DesignAssetController::class, 'index']);
            Route::post('/', [DesignAssetController::class, 'store']);

            // Specific routes FIRST
            Route::get('/{asset}/download', [DesignAssetController::class, 'download']);
            Route::post('/{asset}/approve', [DesignAssetController::class, 'approve']);
            Route::post('/{asset}/reject', [DesignAssetController::class, 'reject']);

            // Generic routes LAST
            Route::get('/{asset}', [DesignAssetController::class, 'show']);
            Route::put('/{asset}', [DesignAssetController::class, 'update']);
            Route::delete('/{asset}', [DesignAssetController::class, 'destroy']);
        });

        // Design requirement management
        Route::prefix('enquiry-tasks/{task}/design-requirements')->group(function () {
            Route::get('/', [DesignRequirementController::class, 'index']);
            Route::put('/', [DesignRequirementController::class, 'update']);
        });


    });



    // Finance Module Routes
    Route::prefix('finance')->group(function () {
        // Petty Cash Module Routes
        Route::prefix('petty-cash')->group(function () {
            // Disbursement management routes
            Route::get('disbursements', [PettyCashController::class, 'index']);
            Route::post('disbursements', [PettyCashController::class, 'store']);
            Route::get('disbursements/{id}', [PettyCashController::class, 'show']);
            Route::put('disbursements/{id}', [PettyCashController::class, 'update']);
            Route::delete('disbursements/{id}', [PettyCashController::class, 'destroy']);
            Route::post('disbursements/bulk-delete', [PettyCashController::class, 'bulkDestroy']);
            Route::post('disbursements/{id}/void', [PettyCashController::class, 'void']);
            Route::post('transactions/{id}/archive', [PettyCashController::class, 'archive']);
            Route::post('transactions/{id}/archive-group', [PettyCashController::class, 'archiveGroup']);
            Route::post('transactions/bulk-archive', [PettyCashController::class, 'bulkArchive']);
            Route::post('transactions/bulk-archive-groups', [PettyCashController::class, 'bulkArchiveGroups']);
            Route::get('activity-logs', [PettyCashController::class, 'getActivityLogs']);
            Route::delete('clear-all', [PettyCashController::class, 'clearAll']);

            // Projects reference for job numbers
            Route::get('projects', [PettyCashController::class, 'getProjects']);
            Route::get('projects/{jobNumber}/budget-items', [PettyCashController::class, 'getProjectBudgetItems']);
            Route::get('accounts', [PettyCashController::class, 'accounts']);

            // Top-up management routes
            Route::get('top-ups', [PettyCashTopUpController::class, 'index']);
            Route::post('top-ups', [PettyCashTopUpController::class, 'store']);
            Route::put('top-ups/{id}', [PettyCashTopUpController::class, 'update']);
            Route::get('top-ups/available', [PettyCashTopUpController::class, 'available']);
            Route::get('top-ups/{id}', [PettyCashTopUpController::class, 'show']);
            Route::get('top-ups/{id}/available-balance', [PettyCashTopUpController::class, 'availableBalance']);
            Route::delete('top-ups/{id}', [PettyCashTopUpController::class, 'destroy']);

            // Balance and transaction routes
            Route::get('balance', [PettyCashController::class, 'balance']);
            Route::get('balance/trends', [PettyCashTopUpController::class, 'trends']);
            Route::post('balance/check', [PettyCashTopUpController::class, 'checkBalance']);
            Route::post('balance/recalculate', [PettyCashController::class, 'recalculateBalance']);

            Route::get('workspace', [PettyCashController::class, 'workspace']);
            Route::get('summary', [PettyCashController::class, 'summary']);
            Route::get('transactions', [PettyCashController::class, 'transactions']);
            Route::get('recent', [PettyCashController::class, 'recent']);
            Route::get('search', [PettyCashController::class, 'search']);
            Route::get('voucher', [PettyCashController::class, 'voucher']);
            Route::get('voucher/pdf', [PettyCashController::class, 'downloadVoucherPdf']);

            // Excel upload route
            Route::post('upload-excel', [PettyCashController::class, 'uploadExcel'])
                ->middleware('permission:' . Permissions::FINANCE_PETTY_CASH_UPLOAD_EXCEL);
            Route::get('download-template', [PettyCashController::class, 'downloadTemplate']);

            // Statistics and validation routes
            Route::get('statistics', [PettyCashTopUpController::class, 'statistics']);
            Route::get('payment-methods', [PettyCashTopUpController::class, 'paymentMethods']);
            Route::post('validate/top-up', [PettyCashTopUpController::class, 'validateTopUp']);

            // Requisition routes
            Route::get('requisitions', [PettyCashRequisitionController::class, 'index']);
            Route::post('requisitions', [PettyCashRequisitionController::class, 'store']);
            Route::put('requisitions/{id}', [PettyCashRequisitionController::class, 'update']);
            Route::delete('requisitions/{id}', [PettyCashRequisitionController::class, 'destroy']);
            Route::get('requisitions/stats', [PettyCashRequisitionController::class, 'stats']);
            Route::get('requisitions/form-data', [PettyCashRequisitionController::class, 'getFormData']);
            Route::get('requisitions/project-team-members', [PettyCashRequisitionController::class, 'getProjectTeamMembers']);
            Route::get('requisitions/search-payees', [PettyCashRequisitionController::class, 'searchPayees']);
            Route::get('requisitions/{id}', [PettyCashRequisitionController::class, 'show']);
            Route::post('requisitions/{id}/approve', [PettyCashRequisitionController::class, 'approve']);
            Route::post('requisitions/{id}/disburse', [PettyCashRequisitionController::class, 'disburse']);
            Route::post('requisitions/{id}/reject', [PettyCashRequisitionController::class, 'reject']);
            Route::post('requisitions/{id}/confirm-receipt', [PettyCashRequisitionController::class, 'confirmReceipt']);
            Route::post('requisitions/{id}/items/{itemId}/confirm-receipt', [PettyCashRequisitionController::class, 'confirmItemReceipt']);
            Route::get('requisitions/{id}/voucher', [PettyCashRequisitionController::class, 'downloadVoucher']);
        });
    });

});
