<?php

use Illuminate\Support\Facades\Route;
use PactTrackSDK\SharedResources\Modules\Signature\Http\Controllers\DocusignWebhookController;
use PactTrackSDK\SharedResources\Modules\Signature\Http\Controllers\EnvelopeController;
use PactTrackSDK\SharedResources\Modules\Signature\Http\Controllers\SigningController;

/*
|--------------------------------------------------------------------------
| Signature module API routes
|--------------------------------------------------------------------------
|
| Loaded by SharedResourceServiceProvider under the `api` middleware group
| and an `/api` prefix, so the routes below resolve under /api/signature/*.
|
| No auth middleware yet — see the note in EnvelopeController/SigningController.
| Add the appropriate guard middleware here once auth scaffolding exists.
| The webhook route is the one exception that must stay unauthenticated even
| after auth lands — DocuSign Connect calls it directly, not a signed-in
| user; its own signature verification (see DocusignWebhookController) is
| the guard.
*/

// Flow A — tenant/staff embedded authoring (Sender View), see .claude/rules/signature.md.
Route::post('signature/documents/{document}/prepare', [EnvelopeController::class, 'prepare']);
Route::get('signature/envelopes/{envelope}/status', [EnvelopeController::class, 'status']);

// Flow B — client-facing embedded signing (Recipient View).
Route::get('signature/pending', [SigningController::class, 'pending']);
Route::get('signature/pending-next', [SigningController::class, 'pendingNext']);
Route::post('signature/envelopes/{envelope}/signing-token', [SigningController::class, 'signingToken']);

// Provider webhook — no auth middleware, verified via signature header.
Route::post('signature/webhooks/docusign', DocusignWebhookController::class);
