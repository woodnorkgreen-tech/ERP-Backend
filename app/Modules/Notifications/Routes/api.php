<?php

use App\Modules\Notifications\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
Route::get('notifications/stats', [NotificationController::class, 'stats']);
Route::get('notifications/preferences', [NotificationController::class, 'preferences']);
Route::put('notifications/preferences', [NotificationController::class, 'updatePreferences']);
Route::get('notifications/types', [NotificationController::class, 'types']);
Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
Route::post('notifications/mark-read', [NotificationController::class, 'markManyAsRead']);
Route::get('notifications', [NotificationController::class, 'index']);
Route::get('notifications/{id}', [NotificationController::class, 'show']);
Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
Route::post('notifications/{id}/star', [NotificationController::class, 'toggleStar']);

Route::post('user/device-token', [NotificationController::class, 'registerDeviceToken']);
