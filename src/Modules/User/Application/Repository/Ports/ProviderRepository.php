<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports;

use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * Port for persisting the tenant record.
 *
 * Implemented by Infrastructure\Repositories\Eloquent\EloquentProviderRepository,
 * which also implements Domain\Ports\SubdomainAvailability — availability lives
 * on that separate, narrower port so the domain's SubdomainAllocator can ask
 * its one question without being handed write access to the whole table.
 */
interface ProviderRepository
{
    public function create(array $data): Provider;

    /** Persist an already-resolved, mutated Provider instance. */
    public function save(Provider $provider): Provider;

    /**
     * Whether `$subdomain` is already registered by a provider OTHER than
     * `$exceptProviderId` — the availability check for the branding screen,
     * where the tenant's own current subdomain must not report as taken.
     */
    public function subdomainTakenByAnother(string $subdomain, int $exceptProviderId): bool;
}
