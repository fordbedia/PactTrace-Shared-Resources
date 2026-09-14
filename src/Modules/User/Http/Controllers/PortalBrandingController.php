<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PactTrackSDK\SharedResources\Modules\User\Http\Resources\PortalLoginBrandResource;

/**
 * Public, unauthenticated branding for `/portal/login` — closes the
 * "branding-before-login" gap flagged in usePortalLoginBrand.ts and
 * .claude/rules/client.md, "Client portal login".
 *
 * Reads the `resolved_provider` request attribute
 * Http\Middleware\ResolveProviderFromHost already attaches for a request on
 * a tenant's own subdomain or verified custom domain — this controller does
 * no host parsing or lookup of its own, it only shapes what that middleware
 * already resolved into the safe-to-expose subset.
 *
 * No policy, no auth middleware: this endpoint is deliberately reachable by
 * anyone, the same way the login form itself is. It answers with an
 * all-null shape (200, not 404/401) when nothing was resolved — e.g. a
 * request on the platform's own host, or local dev with no subdomain
 * configured — which is a normal, supported state for
 * usePortalLoginBrand.ts's neutral fallback, not an error.
 */
class PortalBrandingController extends Controller
{
    /**
     * GET /api/v1/portal/login-brand
     */
    public function loginBrand(Request $request): JsonResponse
    {
        $provider = $request->attributes->get('resolved_provider');

        if ($provider === null) {
            return response()->json([
                'data' => [
                    'business_name' => null,
                    'logo_url' => null,
                    'primary_color' => null,
                ],
            ]);
        }

        return (new PortalLoginBrandResource($provider))->response();
    }
}
