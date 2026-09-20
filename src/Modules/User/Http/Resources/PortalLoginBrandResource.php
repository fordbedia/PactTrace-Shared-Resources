<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\ProviderBrandResolver;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * The safe-to-expose subset of a Provider's branding, for an UNAUTHENTICATED
 * visitor on `/portal/login` — see PortalBrandingController::loginBrand()
 * and .claude/rules/client.md, "Subdomain-based portal host resolution".
 *
 * This is a public, pre-auth endpoint's response. Deliberately an allow-list
 * of exactly three fields, unlike ProviderResource (the authenticated user's
 * own, much larger payload): no id, no subdomain/custom_domain, no plan
 * string, no client/matter data of any kind — just enough to paint a login
 * card. `logo_url`/`primary_color` are gated on the SAME
 * `Plan::info()->allowsCustomBranding` flag every other white-labeling
 * surface reads (BrandingController, PortalShell, the client-facing
 * Mailables) — a Starter tenant's subdomain must not leak a logo/colour it
 * isn't entitled to render.
 *
 * @mixin Provider
 */
class PortalLoginBrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $allowsCustomBranding = (Plan::tryFrom((string) $this->plan) ?? Plan::default())
            ->info()
            ->allowsCustomBranding;

        $brand = app(ProviderBrandResolver::class)->forProvider($this->resource);

        return [
            'business_name' => $brand->name,
            'logo_url' => $brand->logoUrl,
            'primary_color' => $allowsCustomBranding ? $this->primary_color : null,
        ];
    }
}
