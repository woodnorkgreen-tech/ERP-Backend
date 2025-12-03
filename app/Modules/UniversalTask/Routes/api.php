<?php

use Illuminate\Support\Facades\Route;
use App\Modules\UniversalTask\Controllers\TaskController;
use App\Modules\UniversalTask\Controllers\SubtaskController;
use App\Modules\UniversalTask\Controllers\TaskIssueController;
use App\Modules\UniversalTask\Controllers\TaskExperienceController;
use App\Modules\UniversalTask\Controllers\TaskCommentController;
use App\Modules\UniversalTask\Controllers\TaskAttachmentController;
use App\Modules\UniversalTask\Controllers\TaskTemplateController;
use App\Modules\UniversalTask\Controllers\TaskAnalyticsController;
use App\Modules\UniversalTask\Controllers\TaskTimeEntryController;
use App\Modules\UniversalTask\Controllers\TaskSavedViewController;

/*
|--------------------------------------------------------------------------
| Universal Task API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for the Universal Task module.
| These routes are loaded by the UniversalTaskServiceProvider and are
| automatically prefixed with 'api' and assigned the 'api' middleware group.
|
*/

// Note: using explicit rate limit (60 req/min) instead of named limiter 'api'
// to avoid MissingRateLimiterException in apps without a configured RateLimiter::for('api')
Route::prefix('api/universal-tasks')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {

    // ==================== Task Routes ====================
    Route::apiResource('tasks', TaskController::class);
    Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus']);
    Route::post('tasks/{task}/assign', [TaskController::class, 'assign']);
    Route::get('tasks/{task}/history', [TaskController::class, 'getHistory']);
    Route::get('tasks/{task}/activity', [TaskController::class, 'getActivity']);

    // ==================== Subtask Routes ====================
    Route::get('tasks/{task}/subtasks', [SubtaskController::class, 'index']);
    Route::post('tasks/{task}/subtasks', [SubtaskController::class, 'store']);
    Route::get('tasks/{task}/hierarchy', [SubtaskController::class, 'getHierarchy']);

    // ==================== Task Dependencies ====================
    // TODO: Implement dependency management routes when dependency controller is created
    // Route::get('tasks/{task}/dependencies', [TaskController::class, 'getDependencies']);
    // Route::post('tasks/{task}/dependencies', [TaskController::class, 'addDependency']);
    // Route::delete('tasks/{task}/dependencies/{dependency}', [TaskController::class, 'removeDependency']);
    // Route::get('tasks/{task}/dependency-chain', [TaskController::class, 'getDependencyChain']);

    // ==================== Task Issues ====================
    Route::apiResource('issues', TaskIssueController::class)->parameters(['issues' => 'issue']);
    Route::patch('issues/{issue}/resolve', [TaskIssueController::class, 'resolve']);
    Route::get('issues/search', [TaskIssueController::class, 'search']);

    // ==================== Task Experience Logs ====================
    Route::apiResource('experience-logs', TaskExperienceController::class)->parameters(['experience-logs' => 'log']);
    Route::get('experience-logs/search', [TaskExperienceController::class, 'search']);

    // ==================== Task Comments ====================
    Route::get('tasks/{task}/comments', [TaskCommentController::class, 'index']);
    Route::post('tasks/{task}/comments', [TaskCommentController::class, 'store']);
    Route::get('comments/{comment}', [TaskCommentController::class, 'show']);
    Route::put('comments/{comment}', [TaskCommentController::class, 'update']);
    Route::delete('comments/{comment}', [TaskCommentController::class, 'destroy']);
    Route::post('tasks/{task}/comments/{comment}/reply', [TaskCommentController::class, 'reply']);

    // ==================== Task Attachments ====================
    Route::get('tasks/{task}/attachments', [TaskAttachmentController::class, 'index']);
    Route::post('tasks/{task}/attachments', [TaskAttachmentController::class, 'store']);
    Route::get('attachments/{attachment}', [TaskAttachmentController::class, 'show']);
    Route::get('attachments/{attachment}/download', [TaskAttachmentController::class, 'download']);
    Route::delete('attachments/{attachment}', [TaskAttachmentController::class, 'destroy']);
    Route::get('attachments/file/{filename}/versions', [TaskAttachmentController::class, 'getVersions']);

    // ==================== Time Tracking ====================
    Route::get('tasks/{task}/time-entries', [TaskTimeEntryController::class, 'index']);
    Route::post('tasks/{task}/time-entries', [TaskTimeEntryController::class, 'store']);
    Route::get('time-entries/{timeEntry}', [TaskTimeEntryController::class, 'show']);
    Route::put('time-entries/{timeEntry}', [TaskTimeEntryController::class, 'update']);
    Route::delete('time-entries/{timeEntry}', [TaskTimeEntryController::class, 'destroy']);
    Route::get('tasks/{task}/time-variance', [TaskTimeEntryController::class, 'getVariance']);

    // ==================== Task Templates ====================
    Route::apiResource('templates', TaskTemplateController::class);
    Route::post('templates/{template}/instantiate', [TaskTemplateController::class, 'instantiate']);
    Route::get('templates/{template}/versions', [TaskTemplateController::class, 'getVersions']);

    // ==================== Analytics Routes ====================
    Route::prefix('analytics')->group(function () {
        Route::get('dashboard', [TaskAnalyticsController::class, 'getDashboard']);
        Route::get('metrics', [TaskAnalyticsController::class, 'getMetrics']);
        Route::get('time-series', [TaskAnalyticsController::class, 'getTimeSeries']);
        Route::get('departments', [TaskAnalyticsController::class, 'getDepartmentAnalytics']);
        Route::get('export', [TaskAnalyticsController::class, 'export']);
    });

    // ==================== Saved Views Routes ====================
    Route::apiResource('saved-views', TaskSavedViewController::class)->parameters(['saved-views' => 'view']);
    Route::post('saved-views/{view}/apply', [TaskSavedViewController::class, 'apply']);
});
