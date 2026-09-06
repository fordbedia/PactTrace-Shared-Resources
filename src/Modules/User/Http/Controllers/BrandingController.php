<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserHintCookie;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Branding\RemoveProviderLogo;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Branding\UpdateProviderBranding;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Branding\UpdateProviderLogo;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Subdomain;
use PactTrackSDK\SharedResources\Modules\User\Http\Requests\UpdateBrandingRequest;
use PactTrackSDK\SharedResources\Modules\User\Http\Requests\UpdateProviderLogoRequest;
use PactTrackSDK\SharedResources\Modules\User\Http\Resources\UserResource;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * `/dashboard/branding` — the tenant's own portal branding. Real
 * `auth:sanctum`; every action is doubly gated:
 *
 *   1. WHO  — `ProviderPolicy::manageBranding` (the `provider.manage-branding`
 *             spatie permission + tenant ownership). Owner-only in practice.
 *   2. WHAT — for the plan-restricted controls (logo, accent colour, custom
 *             domain) the tenant's plan must also allow it:
 *             `PlanInfo::allowsCustomBranding` / `allowsCustomDomain`. A
 *             Starter tenant hitting these endpoints directly gets a 403, not
 *             just a hidden field.
 *
 * Portal name / subdomain / timezone / locale / email branding are gate #1
 * only — every plan can set those.
 */
class BrandingController extends Controller
{
    public function __construct(
        private readonly UpdateProviderBranding $updateBranding,
        private readonly UpdateProviderLogo $updateLogo,
        private readonly RemoveProviderLogo $removeLogo,
        private readonly ProviderRepository $providers,
        private readonly UserHintCookie $hintCookie,
    ) {
    }

    /**
     * PATCH /api/v1/branding — the save bar's non-file fields.
     */
    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        [$user, $provider] = $this->gate($request);

        $attributes = $request->validated();

        if (array_key_exists('primary_color', $attributes)) {
            $this->assertPlan($provider, 'allowsCustomBranding');
        }
        if (array_key_exists('custom_domain', $attributes)) {
            $this->assertPlan($provider, 'allowsCustomDomain');
        }

        try {
            $provider = $this->updateBranding->handle($user, $provider, $attributes);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['subdomain' => [$e->getMessage()]]);
        }

        return $this->freshUser($request);
    }

    /**
     * POST /api/v1/branding/logo — Portal Logo upload / Replace.
     */
    public function updateLogo(UpdateProviderLogoRequest $request): JsonResponse
    {
        [$user, $provider] = $this->gate($request);
        $this->assertPlan($provider, 'allowsCustomBranding');

        $this->updateLogo->handle($user, $provider, $request->file('logo'));

        return $this->freshUser($request);
    }

    /**
     * DELETE /api/v1/branding/logo — Portal Logo "Remove".
     */
    public function destroyLogo(Request $request): JsonResponse
    {
        [$user, $provider] = $this->gate($request);
        $this->assertPlan($provider, 'allowsCustomBranding');

        $this->removeLogo->handle($user, $provider);

        return $this->freshUser($request);
    }

    /**
     * GET /api/v1/branding/subdomain-available?subdomain=foo — the availability
     * pill on the Portal Subdomain field. `{ available, reason? }`.
     */
    public function subdomainAvailable(Request $request): JsonResponse
    {
        [, $provider] = $this->gate($request);

        $raw = (string) $request->query('subdomain', '');

        try {
            $subdomain = Subdomain::fromString($raw);
        } catch (InvalidArgumentException $e) {
            return response()->json(['available' => false, 'reason' => $e->getMessage()]);
        }

        $taken = $this->providers->subdomainTakenByAnother($subdomain->value, (int) $provider->id);

        return response()->json([
            'available' => ! $taken,
            'reason' => $taken ? 'That subdomain is already taken.' : null,
        ]);
    }

    /**
     * Run gate #1 and hand back the acting user + their (non-null) provider.
     *
     * @return array{0: User, 1: Provider}
     */
    private function gate(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $provider = $user->provider;

        abort_if($provider === null, 403);
        Gate::forUser($user)->authorize('manageBranding', $provider);

        return [$user, $provider];
    }

    /**
     * Gate #2 — the tenant's plan must allow this class of branding control.
     *
     * @param 'allowsCustomBranding'|'allowsCustomDomain' $capability
     */
    private function assertPlan(Provider $provider, string $capability): void
    {
        $info = (Plan::tryFrom((string) $provider->plan) ?? Plan::default())->info();

        abort_unless($info->{$capability}, 403, 'Your plan does not include this branding option.');
    }

    private function freshUser(Request $request): JsonResponse
    {
        $user = $request->user()->fresh();

        // Business name / subdomain may have changed — re-attach the hint cookie
        // (same as ProfileController::update).
        $this->hintCookie->attach($user);

        return response()->json([
            'data' => new UserResource($user->loadAuthPayload()),
        ]);
    }
}
