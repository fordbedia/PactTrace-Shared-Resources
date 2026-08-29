<?php

use Illuminate\Support\Facades\Route;
use PactTrackSDK\SharedResources\Modules\Notification\Http\Controllers\AuditLogController;

Route::prefix('v1')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        // Compliance audit trail — read-only. See .claude/rules/notification.md.
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/audit-logs/action-types', [AuditLogController::class, 'actionTypes']);
    });
});
