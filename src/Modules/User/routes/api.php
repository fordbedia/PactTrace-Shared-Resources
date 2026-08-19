<?php

use Illuminate\Support\Facades\Route;
use PactTrackSDK\SharedResources\Modules\User\Http\Controllers\RegistrationController;
use PactTrackSDK\SharedResources\Modules\User\Http\Controllers\SessionController;
use PactTrackSDK\SharedResources\Modules\User\Http\Controllers\UserController;

/*
| Loaded by SharedResourceServiceProvider under the `api` prefix and the `api`
| middleware group, so every path below is served at /api/... — which is what
| nginx proxies to the backend and what NEXT_PUBLIC_API_URL points at.
|
| Session auth on these routes needs `statefulApi()` in backend/bootstrap/app.php;
| without it the `api` group never starts a session and the CSRF cookie the SPA
| primes is ignored.
|
| No `guest` middleware here on purpose: it answers with a 302 to the HOME
| route, which an XHR client follows into a nonsense response rather than
| reporting. Whether an already-signed-in browser should be allowed to reach the
| signup form is the SPA's routing question, not this endpoint's.
*/
Route::prefix('v1')->group(function () {
	Route::post('user/register', [RegistrationController::class, 'store'])
		->name('user.register');

	Route::prefix('auth')->name('auth.')->group(function () {
		Route::post('login', [SessionController::class, 'store'])
			->name('login');
	});

// Deliberately unauthenticated: signing out is idempotent, and a browser
// holding an expired cookie asking to be signed out should be told "fine",
// not 401'd into an error path it cannot recover from.
	Route::post('logout', [SessionController::class, 'destroy'])
		->name('logout');

	Route::middleware('auth:sanctum')->group(function () {
		Route::apiResource('user', UserController::class);
	});
});