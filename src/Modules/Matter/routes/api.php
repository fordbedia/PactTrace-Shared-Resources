<?php

use Illuminate\Support\Facades\Route;
use PactTraceSDK\SharedResources\Modules\Matter\Http\Controllers\MattersController;

Route::prefix('v1')->group(function () {
	Route::middleware('auth:sanctum')->group(function () {
		Route::get('/matters/clients/search', [MattersController::class, 'searchClients']);
		Route::apiResource('/matters', MattersController::class);
	});
});