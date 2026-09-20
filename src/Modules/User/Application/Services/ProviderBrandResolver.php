<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Services;

use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\ProviderLogoStorage;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\ProviderBrand;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * Resolves a Provider to the ProviderBrand a client-facing surface may
 * render, through the central `Plan::info()->allowsCustomBranding` gate —
 * no parallel plan check anywhere. See ProviderBrand for the rules.
 */
class ProviderBrandResolver
{
    public function __construct(private readonly ProviderLogoStorage $logos)
    {
    }

    public function forProvider(Provider $provider): ProviderBrand
    {
        $allows = (Plan::tryFrom((string) $provider->plan) ?? Plan::default())->info()->allowsCustomBranding;

        return ProviderBrand::resolve(
            $provider->business_name,
            $allows && $provider->logo_path !== null ? $this->logos->url($provider->logo_path) : null,
            $allows,
        );
    }
}
