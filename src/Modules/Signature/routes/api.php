<?php

use Illuminate\Support\Facades\Route;
use PactTraceSDK\SharedResources\Modules\Signature\Http\Controllers\EnvelopeController;

/*
|--------------------------------------------------------------------------
| Signature module API routes
|--------------------------------------------------------------------------
|
| Loaded by SharedResourceServiceProvider under the `api` middleware group
| and an `/api` prefix, so the route below resolves to:
|
|     POST /api/signature/documents/{document}/prepare
|
| No auth middleware yet — see the note in EnvelopeController. Add the
| appropriate guard middleware here once auth scaffolding exists.
*/

Route::post('signature/documents/{document}/prepare', [EnvelopeController::class, 'prepare']);
