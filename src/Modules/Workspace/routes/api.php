<?php

use Illuminate\Support\Facades\Route;
use PactTrackSDK\SharedResources\Modules\Workspace\Http\Controllers\WorkspaceController;

/*
| Loaded by SharedResourceServiceProvider under the `api` prefix + `api`
| middleware group, so every path below is served at /api/v1/... — the same
| shape the User module's routes use.
|
| The Account Settings "Deactivate Workspace" surface. All three routes are on
| real `auth:sanctum`; per-workspace authorization (permission `workspace.delete`,
| owner-only) runs in WorkspaceController. `{workspace}` binds by primary key —
| this is a staff-only authenticated surface, so an enumerable id is not the
| concern it would be on a public client URL.
*/
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::get('workspaces', [WorkspaceController::class, 'index'])
        ->name('workspaces.index');

    Route::get('workspaces/{workspace}/deactivation-eligibility', [WorkspaceController::class, 'deactivationEligibility'])
        ->name('workspaces.deactivation-eligibility');

    Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy'])
        ->name('workspaces.destroy');
});
