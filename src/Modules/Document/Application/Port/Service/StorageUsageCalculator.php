<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Application\Port\Service;

use PactTrackSDK\SharedResources\Modules\Document\Domain\ValueObjects\StorageUsage;

/**
 * Inbound port for the STORAGE indicator's numbers. Implemented by
 * Infrastructure/Services/DocumentStorageUsageService; GetStorageUsageAction
 * depends on this interface, not on that class, so the calculation can be
 * faked in a test or swapped for a cached/precomputed one later.
 */
interface StorageUsageCalculator
{
    /**
     * @param string|null $plan `providers.plan`, used to look the allowance up.
     * @param int|null $clientId Narrows the total to one client's documents —
     *        pass it for a client-role actor, who must never be shown the
     *        whole tenant's consumption.
     */
    public function forProvider(int $providerId, ?string $plan = null, ?int $clientId = null): StorageUsage;
}
