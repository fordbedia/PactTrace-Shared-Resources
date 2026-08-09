<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PactTraceSDK\SharedResources\Modules\User\Http\Controllers\RegistrationController;

Route::group(['prefix' => 'user'], function () {
	Route::apiResource('register', RegistrationController::class);
});