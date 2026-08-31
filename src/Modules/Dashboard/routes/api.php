<?php

use Illuminate\Support\Facades\Route;
use PactTrackSDK\SharedResources\Modules\Dashboard\Http\Controllers\DashboardController;

/*
|--------------------------------------------------------------------------
| Dashboard module API routes
|--------------------------------------------------------------------------
|
| Loaded by SharedResourceServiceProvider under the `api` middleware group
| and an `/api` prefix, so these resolve under /api/v1/dashboard/*.
|
| Real `auth:sanctum` (session guard for the SPA) — the same modern pattern
| MattersController / AuditLogController / EnvelopeDetailController use, not
| the ResolvesActingUser dev bypass the older Document/Signature routes
| still carry.
*/

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('/dashboard/recent-documents', [DashboardController::class, 'recentDocuments']);
    Route::get('/dashboard/recent-activity', [DashboardController::class, 'recentActivity']);
});
