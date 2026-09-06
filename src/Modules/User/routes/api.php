<?php

use Illuminate\Support\Facades\Route;
use PactTrackSDK\SharedResources\Modules\User\Http\Controllers\BillingController;
use PactTrackSDK\SharedResources\Modules\User\Http\Controllers\BrandingController;
use PactTrackSDK\SharedResources\Modules\User\Http\Controllers\PlanController;
use PactTrackSDK\SharedResources\Modules\User\Http\Controllers\PlanUsageController;
use PactTrackSDK\SharedResources\Modules\User\Http\Controllers\ProfileController;
use PactTrackSDK\SharedResources\Modules\User\Http\Controllers\RegistrationController;
use PactTrackSDK\SharedResources\Modules\User\Http\Controllers\SessionController;
use PactTrackSDK\SharedResources\Modules\User\Http\Controllers\StripeWebhookController;
use PactTrackSDK\SharedResources\Modules\User\Http\Controllers\TeamController;
use PactTrackSDK\SharedResources\Modules\User\Http\Controllers\TeamInvitationController;
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

		// The plan catalogue (every tier's PlanInfo) — read-only reference
		// data for /dashboard/billing's comparison grid. Not tenant-specific.
		Route::get('plans', [PlanController::class, 'index'])->name('plans.index');

		// This tenant's own live usage against its plan's limits — the one
		// payload the storage indicators and the frontend plan-guard hook
		// both read. See .claude/rules/plan.md.
		Route::get('plan-usage', [PlanUsageController::class, 'index'])->name('plan-usage');

		// ------------------------------------------------------------------
		// The signed-in user's own account screen (`/profile`). No policy —
		// every action is scoped to the caller themselves. See ProfileController.
		// ------------------------------------------------------------------
		Route::patch('profile', [ProfileController::class, 'update'])
			->name('profile.update');
		Route::post('profile/avatar', [ProfileController::class, 'updateAvatar'])
			->name('profile.avatar');
		Route::put('profile/password', [ProfileController::class, 'updatePassword'])
			->name('profile.password');
		Route::get('profile/deletion-eligibility', [ProfileController::class, 'deletionEligibility'])
			->name('profile.deletion-eligibility');
		Route::delete('profile', [ProfileController::class, 'destroy'])
			->name('profile.destroy');

		// ------------------------------------------------------------------
		// /dashboard/branding — the tenant's own portal branding. Doubly
		// gated in the controller: `provider.manage-branding` (who) + the
		// plan's `allowsCustomBranding` / `allowsCustomDomain` (what). See
		// BrandingController.
		// ------------------------------------------------------------------
		Route::prefix('branding')->name('branding.')->group(function () {
			Route::patch('/', [BrandingController::class, 'update'])->name('update');
			Route::post('logo', [BrandingController::class, 'updateLogo'])->name('logo.update');
			Route::delete('logo', [BrandingController::class, 'destroyLogo'])->name('logo.destroy');
			Route::get('subdomain-available', [BrandingController::class, 'subdomainAvailable'])
				->name('subdomain-available');
		});

		// ------------------------------------------------------------------
		// /dashboard/billing — Checkout, the Stripe Customer Portal, and the
		// downgrade/upgrade usage pre-flight. Owner-only
		// (`provider.manage-billing`, see BillingController). See
		// .claude/rules/plan.md.
		// ------------------------------------------------------------------
		Route::prefix('billing')->name('billing.')->group(function () {
			Route::post('checkout', [BillingController::class, 'checkout'])->name('checkout');
			Route::get('portal-session', [BillingController::class, 'portalSession'])->name('portal-session');
			Route::post('change-plan', [BillingController::class, 'changePlan'])->name('change-plan');
		});

		// ------------------------------------------------------------------
		// Team administration (staff-facing). A previous version registered
		// `Route::apiResource('/', TeamController::class)`, which produced a
		// malformed `{}` route parameter and bare route names (`store`,
		// `show`, …) with no `team.` prefix. `members` is a real resource
		// name; `{member}` is a real binding parameter.
		// ------------------------------------------------------------------
		Route::prefix('team')->name('team.')->group(function () {
			Route::apiResource('members', TeamController::class);

			// Re-send a pending invite with a fresh token. Same permission as
			// inviting (checked in the controller). `throttle:team-invitation-resend`
			// is a named limiter keyed per acting-user + invitation id — see
			// UserProvider::boot() — so the same pending invite can't be
			// spammed at the mail provider.
			Route::post('invitations/{invitation}/resend', [TeamController::class, 'resend'])
				->middleware('throttle:team-invitation-resend')
				->name('invitations.resend');
		});
	});

	// Team invitation accept flow — OUTSIDE auth:sanctum on purpose: the
	// person accepting has no account yet, so the token in the URL is the
	// only credential (same reasoning as the logout route above and the
	// client-invitation routes). Throttled by IP because the token is a
	// bearer credential and there is no session to key on — the app has no
	// other convention here (login/register are unthrottled), so these pick a
	// conservative Breeze-style ceiling.
	Route::prefix('team')->name('team.')->group(function () {
		Route::get('invitations/{token}', [TeamInvitationController::class, 'show'])
			->middleware('throttle:10,1')
			->name('invitations.show');
		Route::post('invitations/{token}/accept', [TeamInvitationController::class, 'accept'])
			->middleware('throttle:6,1')
			->name('invitations.accept');
	});

	// Stripe Connect webhook — OUTSIDE auth:sanctum, Stripe cannot send a
	// session cookie. Signature verification (BillingProvider::
	// constructWebhookEvent()) stands in for authentication, same shape as
	// the Signature module's DocusignWebhookController. Path matches the
	// `stripe listen --forward-to http://localhost/api/v1/stripe/webhook`
	// comment in configs/.env.local.
	Route::post('stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');
});
