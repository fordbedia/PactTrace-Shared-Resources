<?php

use Illuminate\Support\Facades\Route;
use PactTrackSDK\SharedResources\Modules\Client\Http\Controllers\ClientController;
use PactTrackSDK\SharedResources\Modules\Client\Http\Controllers\ClientInvitationController;

Route::prefix('v1')->group(function () {
	// Deliberately outside auth:sanctum — the whole point is that the person
	// hitting these has no login yet. The token in the URL is the credential;
	// see ClientInvitationController and AcceptClientInvitation.
	Route::prefix('client-invitations')->name('client-invitations.')->group(function () {
		Route::get('/{token}', [ClientInvitationController::class, 'show'])
			->name('show');
		Route::post('/{token}/accept', [ClientInvitationController::class, 'accept'])
			->name('accept');
	});

	Route::middleware('auth:sanctum')->group(function () {
		Route::prefix('client')->group(function() {
			Route::apiResource('/invite', ClientController::class);
		});

		Route::get('/clients/search', [ClientController::class, 'search']);
		Route::get('/clients', [ClientController::class, 'index']);
	});
});