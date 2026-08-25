<?php

use Illuminate\Support\Facades\Route;
use PactTrackSDK\SharedResources\Modules\Signature\Http\Controllers\DocusignWebhookController;
use PactTrackSDK\SharedResources\Modules\Signature\Http\Controllers\EnvelopeController;
use PactTrackSDK\SharedResources\Modules\Signature\Http\Controllers\EnvelopeDetailController;
use PactTrackSDK\SharedResources\Modules\Signature\Http\Controllers\GuestSigningController;
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
Route::get('signature/documents/{document}/prepare', [EnvelopeController::class, 'draftSigners']);
Route::post('signature/documents/{document}/prepare', [EnvelopeController::class, 'prepare']);
Route::get('signature/envelopes/{envelope}/status', [EnvelopeController::class, 'status']);

// Flow B — client-facing embedded signing (Recipient View).
Route::get('signature/pending', [SigningController::class, 'pending']);
Route::get('signature/pending-next', [SigningController::class, 'pendingNext']);
Route::post('signature/envelopes/{envelope}/signing-token', [SigningController::class, 'signingToken']);
Route::get('signature/envelopes/{envelope}/signer-status', [SigningController::class, 'signerStatus']);

// Guest (no PactTrack account) signing — a tokenized link, not a signed-in
// session; see .claude/rules/signature.md, "Guest signers". Unauthenticated
// by design, same as the rest of this file for now — the signingLinkToken
// itself is what scopes the request.
Route::post('signature/envelopes/{envelope}/guest-signing-token', [GuestSigningController::class, 'signingToken']);
Route::post('signature/envelopes/{envelope}/guest-signer-status', [GuestSigningController::class, 'signerStatus']);

// Provider webhook — no auth middleware, verified via signature header.
Route::post('signature/webhooks/docusign', DocusignWebhookController::class);

// The envelope detail view (/dashboard/signatures/matter/{matterId}), see
// .claude/rules/signature.md. Real `auth:sanctum` middleware, matching
// MattersController's pattern — not the ResolvesActingUser bypass the rest
// of this file still uses (see EnvelopeDetailController's own docblock for
// why this one surface is on the modern pattern already). Both `{matter}`
// routes below bind by Matter's default route key, `public_id`
// (Matter::getRouteKeyName(), see .claude/rules/matter.md) — matching
// /dashboard/matters/{matterId} itself, which every link into this page
// originates from. Previously bound by the internal id (staff-only, so
// hiding it wasn't a security requirement) — switched for consistency once
// the Matter Detail page's own URL became public_id-keyed; two different
// identifier schemes for sibling staff routes under the same matter was the
// actual problem, not staff-only-ness.
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('signature/matters/{matter}/envelope', [EnvelopeDetailController::class, 'show']);
    Route::post('signature/matters/{matter}/prepare-all-envelopes', [EnvelopeDetailController::class, 'prepareAll']);
    Route::post('signature/envelopes/{envelope}/void', [EnvelopeDetailController::class, 'void']);
});
