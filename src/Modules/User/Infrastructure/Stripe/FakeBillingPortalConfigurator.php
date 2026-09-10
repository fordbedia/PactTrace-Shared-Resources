<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Stripe;

use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingPortalConfigurator;

/**
 * No network calls — bound in tests exactly like {@see FakeBillingProvider}.
 * Records every {@see syncRestrictedConfiguration()} call so a command test
 * can assert the source id / existing id it was handed, and returns a stable
 * fake `bpc_...` id.
 */
final class FakeBillingPortalConfigurator implements BillingPortalConfigurator
{
    /** @var list<array{source: string, existing: string|null}> */
    public array $calls = [];

    public string $restrictedConfigurationId = 'bpc_fake_restricted';

    public function syncRestrictedConfiguration(
        string $sourceConfigurationId,
        ?string $existingRestrictedId = null,
    ): string {
        $this->calls[] = ['source' => $sourceConfigurationId, 'existing' => $existingRestrictedId];

        return $this->restrictedConfigurationId;
    }
}
