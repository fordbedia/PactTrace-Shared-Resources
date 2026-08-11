<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use PactTraceSDK\SharedResources\Modules\User\Application\Services\UserAuthentication;
use PactTraceSDK\SharedResources\Modules\User\Application\UseCases\RegisterProvider;
use PactTraceSDK\SharedResources\Modules\User\Http\Requests\StoreRegistrationRequest;
use PactTraceSDK\SharedResources\Modules\User\Http\Resources\UserResource;

/**
 * Inbound adapter for signup. Translates HTTP into one call against the
 * registration use case and back again — no business rules live here.
 *
 * Backs the "Create Workspace" button on /sign-up.
 */
class RegistrationController extends Controller
{
    public function __construct(
        private readonly RegisterProvider $registerProvider,
        private readonly UserAuthentication $authentication,
    ) {
    }

    /**
     * POST /api/user/register
     *
     * Registers the tenant and signs the new owner straight in, so the SPA can
     * go to /dashboard without bouncing them through the login form they just
     * proved they do not need.
     */
    public function store(StoreRegistrationRequest $request): JsonResponse
    {
        $provider = $this->registerProvider->handle(
            name: $request->string('name')->toString(),
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            businessName: $request->string('business_name')->toString(),
            subdomain: $request->filled('subdomain') ? $request->string('subdomain')->toString() : null,
            plan: $request->string('plan', 'starter')->toString(),
        );

        $this->authentication->login($provider->owner);

        return response()->json([
            'user' => new UserResource($provider->owner),
            'provider' => [
                'id' => $provider->id,
                'business_name' => $provider->business_name,
                // Worth returning even when the user chose it: for a derived
                // subdomain this is the first time they learn their portal
                // address, and a collision may have shifted it.
                'subdomain' => $provider->subdomain,
                'plan' => $provider->plan,
                'trial_ends_at' => $provider->trial_ends_at?->toIso8601String(),
            ],
        ], 201);
    }
}
