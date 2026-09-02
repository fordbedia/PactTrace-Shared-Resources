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

    Route::post('workspaces', [WorkspaceController::class, 'store'])
        ->name('workspaces.store');

    Route::put('workspaces/{workspace}', [WorkspaceController::class, 'update'])
        ->name('workspaces.update');

    Route::post('workspaces/{workspace}/activate', [WorkspaceController::class, 'activate'])
        ->name('workspaces.activate');

    // Acts on soft-deleted rows, which route-model binding hides — the
    // controller resolves `{workspace}` (a raw id here) with-trashed itself.
    Route::post('workspaces/{workspace}/restore', [WorkspaceController::class, 'restore'])
        ->name('workspaces.restore');

    Route::get('workspaces/{workspace}/deactivation-eligibility', [WorkspaceController::class, 'deactivationEligibility'])
        ->name('workspaces.deactivation-eligibility');

    Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy'])
        ->name('workspaces.destroy');
});
