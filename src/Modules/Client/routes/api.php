<?php

use Illuminate\Support\Facades\Route;
use PactTraceSDK\SharedResources\Modules\Client\Http\Controllers\ClientController;

Route::prefix('v1')->group(function () {
	Route::middleware('auth:sanctum')->group(function () {
		Route::prefix('client')->group(function() {
			Route::apiResource('/invite', ClientController::class);
		});
	});
});