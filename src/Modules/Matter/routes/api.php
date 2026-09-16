<?php

use Illuminate\Support\Facades\Route;
use PactTrackSDK\SharedResources\Modules\Matter\Http\Controllers\MattersController;
use PactTrackSDK\SharedResources\Modules\Matter\Http\Controllers\PortalMatterController;

Route::prefix('v1')->group(function () {
	Route::middleware('auth:sanctum')->group(function () {
		Route::get('/matters/clients/search', [MattersController::class, 'searchClients']);
		Route::get('/matters/assignable-staff', [MattersController::class, 'assignableStaff']);
		Route::get('/matters/search', [MattersController::class, 'search']);
		Route::get('/matters/stats', [MattersController::class, 'stats']);
		Route::apiResource('/matters', MattersController::class);
		// See .claude/rules/matter.md, "Matter Archive / Restore" — the
		// controller only authorizes and delegates; the actual writes live in
		// ArchiveMatterHandler / UnarchiveMatterHandler.
		Route::post('/matters/{matter}/archive', [MattersController::class, 'archive']);
		Route::post('/matters/{matter}/unarchive', [MattersController::class, 'unarchive']);
	});

	// Client portal — see .claude/rules/matter.md and
	// PortalMatterController's own docblock for why this stays a separate
	// controller from MattersController above, and why it authenticates via
	// ResolvesActingUser (same as SigningController in the Signature
	// module) rather than the `auth:sanctum` middleware used above: it's
	// the same client-portal auth shape as the rest of that surface, and
	// (unlike `auth:sanctum`) it's exercisable under this package's own
	// Testbench harness, which has no `sanctum` guard configured.
	Route::get('/portal/matters', [PortalMatterController::class, 'index']);
	Route::get('/portal/matters/{matter}', [PortalMatterController::class, 'show']);
});