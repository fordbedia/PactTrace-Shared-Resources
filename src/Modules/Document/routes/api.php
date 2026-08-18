<?php

use Illuminate\Support\Facades\Route;
use PactTraceSDK\SharedResources\Modules\Document\Http\Controllers\DocumentController;
use PactTraceSDK\SharedResources\Modules\Document\Http\Controllers\FolderController;

/*
|--------------------------------------------------------------------------
| Document module API routes
|--------------------------------------------------------------------------
|
| Loaded by SharedResourceServiceProvider under the `api` middleware group
| and an `/api` prefix, so the routes below resolve to:
|
|     POST   /api/documents
|     GET    /api/folders
|     POST   /api/folders
|     DELETE /api/folders/{folder}
|
| No auth middleware yet — see the note in DocumentController /
| FolderController. Add the appropriate guard middleware here once auth
| scaffolding exists.
*/

Route::post('documents', [DocumentController::class, 'store']);

Route::get('folders', [FolderController::class, 'index']);
Route::post('folders', [FolderController::class, 'store']);
Route::delete('folders/{folder}', [FolderController::class, 'destroy']);
