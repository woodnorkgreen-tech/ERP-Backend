<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\HR\Http\Controllers\DepartmentController;
use App\Modules\Admin\Http\Controllers\UserController;
use App\Modules\Admin\Http\Controllers\RoleController;
use App\Modules\Admin\Http\Controllers\PermissionController;
use App\Modules\ClientService\Http\Controllers\ClientController;
use App\Modules\ClientService\Http\Controllers\EnquiryController as ClientServiceEnquiryController;
use App\Modules\Projects\Http\Controllers\EnquiryController;
use App\Modules\Projects\Http\Controllers\DashboardController;
use App\Modules\Projects\Http\Controllers\TaskController;
use App\Modules\Projects\Http\Controllers\PhaseDepartmentalTaskController;
use App\Http\Controllers\SiteSurveyController;
use App\Http\Controllers\DesignAssetController;
use App\Http\Controllers\ProcurementController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\HandoverSurveyController;
use App\Http\Controllers\API\PublicHandoverController;

use App\Modules\Finance\PettyCash\Controllers\PettyCashController;
use App\Modules\Finance\PettyCash\Controllers\PettyCashTopUpController;
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

// Public routes for Client Handover
Route::get('public/handover/{token}', [App\Http\Controllers\API\PublicHandoverController::class, 'show']);
Route::post('public/handover/{token}', [App\Http\Controllers\API\PublicHandoverController::class, 'store']);
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
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/user', function () {
    $user = auth()->user();
    return response()->json($user->load('roles'));
})->middleware('auth:sanctum');
//location apis
Route::get('/locations', 'App\Http\Controllers\LocationController@index');
Route::resource('/location', 'App\Http\Controllers\LocationController');
Route::post('/locations', 'App\Http\Controllers\LocationController@store');

Route::get('/announcements', 'App\Http\Controllers\AnnouncementController@index');
Route::post('/announcements', 'App\Http\Controllers\AnnouncementController@store');
Route::post('/announcements/read', 'App\Http\Controllers\AnnouncementController@markAsRead');
Route::get('/announcements/unread-count', 'App\Http\Controllers\AnnouncementController@unreadCount');
Route::delete('/announcements/{id}', 'App\Http\Controllers\AnnouncementController@destroy');
//to be removed later
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
        
        // PDF
        Route::get('/{reportId}/pdf', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'generatePdf']);
        
        // Attachments
        Route::post('/{reportId}/attachments', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'uploadAttachment']);
        Route::delete('/{reportId}/attachments/{attachmentId}', [App\Modules\ArchivalTask\Http\Controllers\ArchivalReportController::class, 'deleteAttachment']);
    });

        // Site survey management
        Route::apiResource('site-surveys', SiteSurveyController::class); // Temporarily remove permissions for debugging
        Route::get('site-surveys/{survey}/pdf', [SiteSurveyController::class, 'generatePDF']);
        Route::post('tasks/{taskId}/survey/photos', [SiteSurveyController::class, 'uploadPhoto']);
        Route::delete('tasks/{taskId}/survey/photos/{photoId}', [SiteSurveyController::class, 'deletePhoto']);

        // Task management routes
        Route::get('tasks', [TaskController::class, 'getDepartmentalTasks']);
        Route::get('tasks/{taskId}', [TaskController::class, 'show']);
        Route::put('tasks/{taskId}/status', [TaskController::class, 'updateTaskStatus']);
        Route::put('tasks/{taskId}/assign', [TaskController::class, 'assignTask']);
        Route::put('tasks/{taskId}', [TaskController::class, 'update']);
        Route::get('enquiries/{enquiryId}/tasks', [TaskController::class, 'getEnquiryTasks']);
        Route::get('all-enquiry-tasks', [TaskController::class, 'getAllEnquiryTasks']);

        // Enquiry task assignment routes
        Route::post('enquiry-tasks/{task}/assign', [TaskController::class, 'assignEnquiryTask']);
        Route::put('enquiry-tasks/{task}/reassign', [TaskController::class, 'reassignEnquiryTask']);
        Route::get('enquiry-tasks/{task}/assignment-history', [TaskController::class, 'getTaskAssignmentHistory']);
        Route::put('enquiry-tasks/{task}', [TaskController::class, 'updateEnquiryTask']);

        // Project management
        Route::get('projects', function () {
            $query = \App\Modules\Projects\Models\Project::with('enquiry.client');

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

//mobile app
Route::get('/app-departments', [DepartmentController::class, 'index']);

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {

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
    // User permissions and navigation
    Route::get('/user/permissions', function () {
        return response()->json([
            'permissions' => auth()->user()->getNavigationPermissions(),
            'user_permissions' => auth()->user()->getAllPermissions()->pluck('name')->toArray(),
            'roles' => auth()->user()->roles->pluck('name'),
            'departments' => auth()->user()->getAccessibleDepartments()
        ]);
    });

    // HR Module Routes
    Route::prefix('hr')->group(function () {
        // Employee management
        Route::apiResource('employees', EmployeeController::class)->middleware([
            'index' => 'permission:' . Permissions::EMPLOYEE_READ,
            'store' => 'permission:' . Permissions::EMPLOYEE_CREATE,
            'show' => 'permission:' . Permissions::EMPLOYEE_READ,
            'update' => 'permission:' . Permissions::EMPLOYEE_UPDATE,
            'destroy' => 'permission:' . Permissions::EMPLOYEE_DELETE,
        ]);
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
    });

    // Admin Module Routes
    Route::prefix('admin')->group(function () {
        // User management
        Route::get('users/available-employees', [UserController::class, 'availableEmployees'])
            ->middleware('permission:' . Permissions::USER_READ . ',' . Permissions::TASK_ASSIGN);
        Route::apiResource('users', UserController::class)->parameters([
            'users' => 'user'
        ])->middleware([
            'index' => 'permission:' . Permissions::USER_READ,
            'store' => 'permission:' . Permissions::USER_CREATE,
            'show' => 'permission:' . Permissions::USER_READ,
            'update' => 'permission:' . Permissions::USER_UPDATE,
            'destroy' => 'permission:' . Permissions::USER_DELETE,
        ]);

        // Role and Permission management
        Route::apiResource('roles', RoleController::class)->middleware([
            'index' => 'permission:' . Permissions::ROLE_READ,
            'store' => 'permission:' . Permissions::ROLE_CREATE,
            'show' => 'permission:' . Permissions::ROLE_READ,
            'update' => 'permission:' . Permissions::ROLE_UPDATE,
            'destroy' => 'permission:' . Permissions::ROLE_DELETE,
        ]);
        Route::apiResource('permissions', PermissionController::class)->middleware([
            'index' => 'permission:' . Permissions::ROLE_READ, // Admin can view permissions
        ]);
    });

    // Project Officers endpoint (accessible by Client Service for enquiry assignment)
    Route::get('project-officers', [UserController::class, 'getProjectOfficers'])
        ->middleware('permission:' . Permissions::ENQUIRY_UPDATE);

    // Users endpoint for task assignment (accessible by Project Managers)
    Route::get('users', [UserController::class, 'index'])
        ->middleware('permission:' . Permissions::USER_READ . ',' . Permissions::TASK_ASSIGN);

    // ClientService Module Routes
    Route::prefix('clientservice')->group(function () {
        // Client management
        Route::get('clients', [ClientController::class, 'index'])
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
    });

    // Materials management routes
    Route::prefix('projects/tasks/{taskId}/materials')->group(function () {
        Route::get('/', [App\Http\Controllers\MaterialsController::class, 'getMaterialsData']);
        Route::post('/', [App\Http\Controllers\MaterialsController::class, 'saveMaterialsData']);
        
        // Material versioning routes
        Route::post('/versions', [App\Http\Controllers\MaterialsController::class, 'createMaterialVersion']);
        Route::get('/versions', [App\Http\Controllers\MaterialsController::class, 'getMaterialVersions']);
        Route::post('/versions/{versionId}/restore', [App\Http\Controllers\MaterialsController::class, 'restoreMaterialVersion']);
        
        // Excel template download and upload
        Route::get('/template/download', [App\Http\Controllers\MaterialsController::class, 'downloadTemplate']);
        Route::post('/template/upload', [App\Http\Controllers\MaterialsController::class, 'uploadTemplate']);

        // Delete element
        Route::delete('/elements/{elementId}', [App\Http\Controllers\MaterialsController::class, 'deleteElement']);
    });

    // Budget management routes
    Route::prefix('projects/tasks/{taskId}/budget')->group(function () {
        Route::get('/', [App\Http\Controllers\BudgetController::class, 'getBudgetData']);;
        Route::post('/', [App\Http\Controllers\BudgetController::class, 'saveBudgetData']);
        Route::post('/submit-approval', [App\Http\Controllers\BudgetController::class, 'submitForApproval']);
        Route::post('/import-materials', [App\Http\Controllers\BudgetController::class, 'importMaterials']);
        Route::get('/check-materials-update', [App\Http\Controllers\BudgetController::class, 'checkMaterialsUpdate']);

        // Budget versioning routes
        Route::post('/versions', [App\Http\Controllers\BudgetController::class, 'createBudgetVersion']);
        Route::get('/versions', [App\Http\Controllers\BudgetController::class, 'getBudgetVersions']);
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
    Route::prefix('projects/tasks/{taskId}/quote')->group(function () {
        Route::get('/', [App\Http\Controllers\QuoteController::class, 'getQuoteData']);
        Route::post('/', [App\Http\Controllers\QuoteController::class, 'saveQuoteData']);
        Route::post('/import-budget', [App\Http\Controllers\QuoteController::class, 'importBudgetData']);
        Route::get('/budget-status', [App\Http\Controllers\QuoteController::class, 'checkBudgetStatus']);
        Route::get('/changes-preview', [App\Http\Controllers\QuoteController::class, 'previewBudgetChanges']);
        Route::post('/smart-merge', [App\Http\Controllers\QuoteController::class, 'smartMergeBudget']);
        
        // Quote versioning routes (standardized to match materials/budget pattern)
        Route::post('/versions', [App\Http\Controllers\QuoteController::class, 'createVersion']);
        Route::get('/versions', [App\Http\Controllers\QuoteController::class, 'getVersions']);
        Route::post('/versions/{versionId}/restore', [App\Http\Controllers\QuoteController::class, 'restoreVersion']);
        
        // Legacy routes (keep for backward compatibility)
        Route::post('/version', [App\Http\Controllers\QuoteController::class, 'createVersion']);
        Route::get('/version/{versionId}', [App\Http\Controllers\QuoteController::class, 'getVersion']);
        Route::post('/restore/{versionId}', [App\Http\Controllers\QuoteController::class, 'restoreVersion']);
    });

    // Quote approval routes
    Route::prefix('projects/tasks/{taskId}/approval')->group(function () {
        Route::post('/', [App\Http\Controllers\QuoteController::class, 'saveApproval']);
    });

    // Procurement management routes
    Route::prefix('projects/tasks/{taskId}/procurement')->group(function () {
        Route::get('/', [App\Http\Controllers\ProcurementController::class, 'getProcurementData']);
        Route::post('/', [App\Http\Controllers\ProcurementController::class, 'saveProcurementData']);
        Route::post('/import-budget', [App\Http\Controllers\ProcurementController::class, 'importBudgetData']);
    });

    // Procurement utility routes
    Route::get('projects/procurement/vendor-suggestions', [App\Http\Controllers\ProcurementController::class, 'getVendorSuggestions']);

    // Production management routes
    Route::prefix('projects/tasks/{taskId}/production')->group(function () {
        Route::get('/', [App\Http\Controllers\ProductionController::class, 'getProductionData']);
        Route::put('/', [App\Http\Controllers\ProductionController::class, 'saveProductionData']);
        Route::post('/import-materials', [App\Http\Controllers\ProductionController::class, 'importMaterialsData']);
        Route::post('/generate-checkpoints', [App\Http\Controllers\ProductionController::class, 'generateQualityCheckpoints']);
    });

    // Handover management routes
    Route::prefix('projects/tasks/{taskId}/handover')->group(function () {
        Route::get('/survey', [HandoverSurveyController::class, 'show']);
        Route::post('/survey', [HandoverSurveyController::class, 'store']);
        Route::delete('/survey', [HandoverSurveyController::class, 'destroy']);
        Route::post('/survey/generate-token', [HandoverSurveyController::class, 'generateToken']);
    });

    // Logistics management routes
    Route::prefix('projects/tasks/{taskId}/logistics')->group(function () {
        Route::get('/', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'show']);
        Route::post('/planning', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'savePlanning']);
        Route::post('/team-confirmation', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'updateTeamConfirmation']);
        Route::put('/assign-team', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'assignTeam']);
        
        // Transport items
        Route::get('/transport-items', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'getTransportItems']);
        Route::post('/transport-items', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'addTransportItem']);
        Route::put('/transport-items/{itemId}', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'updateTransportItem']);
        Route::delete('/transport-items/{itemId}', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'deleteTransportItem']);
        Route::post('/transport-items/import', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'importProductionElements']);
        
        // Checklist
        Route::get('/checklist', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'getChecklist']);
        Route::post('/checklist', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'updateChecklist']);
        Route::post('/checklist/generate', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'generateChecklist']);
    });

    // Logistics utility routes
    Route::get('logistics/drivers', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'getDrivers']);

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

    // Setdown Task management routes
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

    // Projects Module Routes
    Route::prefix('projects')->group(function () {
        // Logistics Task Routes
        Route::prefix('tasks/{taskId}/logistics')->group(function () {
            Route::get('/', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'show']);
            Route::get('/pdf', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'generatePdf']);
            Route::post('/planning', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'savePlanning']);
            Route::put('/team-confirmation', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'updateTeamConfirmation']);
            Route::get('/transport-items', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'getTransportItems']);
            Route::post('/transport-items', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'addTransportItem']);
            Route::put('/transport-items/{itemId}', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'updateTransportItem']);
            Route::delete('/transport-items/{itemId}', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'deleteTransportItem']);
            Route::post('/import-production-elements', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'importProductionElements']);
            Route::get('/checklist', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'getChecklist']);
            Route::post('/checklist', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'updateChecklist']);
            Route::post('/checklist/generate', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'generateChecklist']);
            Route::get('/checklist/stats', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'getChecklistStats']);
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
        });

        // Production Task Routes
        Route::prefix('tasks/{taskId}/production')->group(function () {
            Route::get('/', [App\Http\Controllers\ProductionController::class, 'getProductionData']);
            Route::put('/', [App\Http\Controllers\ProductionController::class, 'saveProductionData']);
            Route::post('/import-materials', [App\Http\Controllers\ProductionController::class, 'importMaterialsData']);
            Route::post('/generate-checkpoints', [App\Http\Controllers\ProductionController::class, 'generateQualityCheckpoints']);
            Route::delete('/quality-checkpoints', [App\Http\Controllers\ProductionController::class, 'deleteQualityCheckpoints']);
        });

        // Notification Routes
        Route::prefix('projects')->group(function () {
            Route::get('/notifications', [App\Http\Controllers\NotificationController::class, 'index']);
        });
        Route::prefix('notifications')->group(function () {
            Route::put('/{id}/read', [App\Http\Controllers\NotificationController::class, 'markAsRead']);
            Route::put('/mark-all-read', [App\Http\Controllers\NotificationController::class, 'markAllAsRead']);
            Route::delete('/{id}', [App\Http\Controllers\NotificationController::class, 'destroy']);
        });

        // Drivers endpoint for logistics
        Route::get('/drivers', [App\Modules\logisticsTask\Http\Controllers\LogisticsTaskController::class, 'getDrivers']);

        // Logistics Log Routes
        Route::apiResource('logistics-log', App\Http\Controllers\LogisticsLogController::class);

        // Dashboard routes
        Route::get('dashboard', [DashboardController::class, 'dashboard']);
        Route::get('dashboard/enquiry-metrics', [DashboardController::class, 'enquiryMetrics']);
        Route::get('dashboard/task-metrics', [DashboardController::class, 'taskMetrics']);
        Route::get('dashboard/project-metrics', [DashboardController::class, 'projectMetrics']);
        Route::get('dashboard/recent-activities', [DashboardController::class, 'recentActivities']);
        Route::get('dashboard/alerts', [DashboardController::class, 'alerts']);
        Route::post('dashboard/filter', [DashboardController::class, 'filterDashboard']);
        Route::get('dashboard/export/pdf', [DashboardController::class, 'exportToPDF']);
        Route::get('dashboard/export/excel', [DashboardController::class, 'exportToExcel']);

        // Task management routes
        Route::get('tasks', [TaskController::class, 'getDepartmentalTasks']);
        Route::get('tasks/{taskId}', [TaskController::class, 'show']);
        Route::put('tasks/{taskId}/status', [TaskController::class, 'updateTaskStatus']);
        Route::put('tasks/{taskId}/assign', [TaskController::class, 'assignTask']);
        Route::put('tasks/{taskId}', [TaskController::class, 'update']);
        Route::get('enquiries/{enquiryId}/tasks', [TaskController::class, 'getEnquiryTasks']);
        Route::get('all-enquiry-tasks', [TaskController::class, 'getAllEnquiryTasks']);

        // Enquiry task assignment routes
        Route::post('enquiry-tasks/{task}/assign', [TaskController::class, 'assignEnquiryTask']);
        Route::put('enquiry-tasks/{task}/reassign', [TaskController::class, 'reassignEnquiryTask']);
        Route::get('enquiry-tasks/{task}/assignment-history', [TaskController::class, 'getTaskAssignmentHistory']);
        Route::put('enquiry-tasks/{task}', [TaskController::class, 'updateEnquiryTask']);

        // Project management
        Route::get('projects', function () {
            $query = \App\Modules\Projects\Models\Project::with('enquiry.client');

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

        // Site survey management
        Route::apiResource('site-surveys', SiteSurveyController::class); // Temporarily remove permissions for debugging
        Route::get('site-surveys/{survey}/pdf', [SiteSurveyController::class, 'generatePDF']);
        Route::post('tasks/{taskId}/survey/photos', [SiteSurveyController::class, 'uploadPhoto']);
        Route::delete('tasks/{taskId}/survey/photos/{photoId}', [SiteSurveyController::class, 'deletePhoto']);


        // Materials management
            Route::get('tasks/{taskId}/materials', [App\Http\Controllers\MaterialsController::class, 'getMaterialsData']);
            Route::post('tasks/{taskId}/materials', [App\Http\Controllers\MaterialsController::class, 'saveMaterialsData']);
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




        // Notification management
        Route::get('notifications', function () {
            $user = auth()->user();
            $notificationService = app(\App\Modules\Projects\Services\NotificationService::class);
            $notifications = $notificationService->getUserNotifications($user->id);
            return response()->json([
                'data' => $notifications,
                'message' => 'Notifications retrieved successfully'
            ]);
        });
        Route::put('notifications/{notification}/read', function (\App\Models\Notification $notification) {
            $user = auth()->user();
            $notificationService = app(\App\Modules\Projects\Services\NotificationService::class);
            $success = $notificationService->markAsRead($notification->id, $user->id);
            return response()->json([
                'success' => $success,
                'message' => $success ? 'Notification marked as read' : 'Notification not found'
            ]);
        });
        Route::put('notifications/mark-all-read', function () {
            $user = auth()->user();
            $notificationService = app(\App\Modules\Projects\Services\NotificationService::class);
            $count = $notificationService->markAllAsRead($user->id);
            return response()->json([
                'count' => $count,
                'message' => "{$count} notifications marked as read"
            ]);
        });
        Route::delete('notifications/{notification}', function (\App\Models\Notification $notification) {
            $user = auth()->user();
            if ($notification->user_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            $notification->delete();
            return response()->json(['message' => 'Notification deleted successfully']);
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
            Route::post('disbursements/{id}/void', [PettyCashController::class, 'void']);

            // Top-up management routes
            Route::get('top-ups', [PettyCashTopUpController::class, 'index']);
            Route::post('top-ups', [PettyCashTopUpController::class, 'store']);
            Route::get('top-ups/{id}', [PettyCashTopUpController::class, 'show']);
            Route::get('top-ups/available', [PettyCashTopUpController::class, 'available'])
                ->withoutMiddleware(['auth:sanctum']);
            Route::get('top-ups/{id}/available-balance', [PettyCashTopUpController::class, 'availableBalance']);

            // Balance and transaction routes
            Route::get('balance', [PettyCashController::class, 'balance']);
            Route::get('balance/trends', [PettyCashTopUpController::class, 'trends']);
            Route::post('balance/check', [PettyCashTopUpController::class, 'checkBalance']);
            Route::post('balance/recalculate', [PettyCashController::class, 'recalculateBalance']);

            // Transaction and reporting routes
            Route::get('transactions', [PettyCashController::class, 'transactions']);
            Route::get('recent', [PettyCashController::class, 'recent']);
            Route::get('search', [PettyCashController::class, 'search']);
            Route::get('summary', [PettyCashController::class, 'summary']);
            Route::get('analytics', [PettyCashController::class, 'analytics']);

            // Statistics and validation routes
            Route::get('statistics', [PettyCashTopUpController::class, 'statistics']);
            Route::get('payment-methods', [PettyCashTopUpController::class, 'paymentMethods']);
            Route::post('validate/top-up', [PettyCashTopUpController::class, 'validate']);
        });
    });
});
