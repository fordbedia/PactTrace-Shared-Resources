<?php

use Illuminate\Support\Facades\Route;
use PactTrackSDK\SharedResources\Modules\Notification\Http\Controllers\AuditLogController;
use PactTrackSDK\SharedResources\Modules\Notification\Http\Controllers\NotificationPreferenceController;

Route::prefix('v1')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        // Compliance audit trail — read-only. See .claude/rules/notification.md.
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/audit-logs/action-types', [AuditLogController::class, 'actionTypes']);

        // Notification Preferences screen (/notification). Scoped to the
        // signed-in user; no policy, same as ProfileController.
        Route::get('/notification-preferences', [NotificationPreferenceController::class, 'index']);
        Route::post('/notification-preferences/reset', [NotificationPreferenceController::class, 'reset']);
        Route::patch('/notification-preferences/{key}', [NotificationPreferenceController::class, 'update']);
    });
});
