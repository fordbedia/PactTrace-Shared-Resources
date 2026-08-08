<?php

use Illuminate\Support\Facades\Route;
use PactTraceSDK\SharedResources\Modules\Document\Http\Controllers\DocumentController;

/*
|--------------------------------------------------------------------------
| Document module API routes
|--------------------------------------------------------------------------
|
| Loaded by SharedResourceServiceProvider under the `api` middleware group
| and an `/api` prefix, so the route below resolves to:
|
|     POST /api/documents
|
| No auth middleware yet — see the note in DocumentController. Add the
| appropriate guard middleware here once auth scaffolding exists.
*/

Route::post('documents', [DocumentController::class, 'store']);
