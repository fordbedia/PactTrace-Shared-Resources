<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Services;

use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\StorageSource;

/**
 * The one place a provider's *true* stored-bytes total is assembled — the sum
 * of every registered {@see StorageSource}.
 *
 * This is what `storage:reconcile`
 * ({@see \PactTrackSDK\SharedResources\Modules\User\Application\UseCases\ReconcileProviderStorageUsage})
 * uses for its nightly full recompute. The per-request read paths do NOT call
 * this — they read the cached `providers.storage_used_bytes` column instead
 * (see {@see \PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\CachedStorageUsageReader}),
 * which {@see \PactTrackSDK\SharedResources\Modules\User\Application\Services\ProviderStorageLedger}
 * keeps current at write time and this aggregator corrects on a schedule.
 *
 * The sources are injected as a tagged collection, so each module contributes
 * its own without a central hardcoded list — see UserProvider::register().
 */
final class StorageUsageAggregator
{
    /** @var list<StorageSource> */
    private readonly array $sources;

    /**
     * @param iterable<StorageSource> $sources
     */
    public function __construct(iterable $sources)
    {
        $this->sources = is_array($sources) ? array_values($sources) : iterator_to_array($sources, false);
    }

    public function totalBytesForProvider(int $providerId): int
    {
        $total = 0;

        foreach ($this->sources as $source) {
            $total += max(0, $source->sumBytesForProvider($providerId));
        }

        return $total;
    }

    /**
     * The same total, broken down by source key — for the drift warning
     * `storage:reconcile` logs when the cache was wrong, so the missing
     * increment/decrement path can be found.
     *
     * @return array<string, int>
     */
    public function breakdownForProvider(int $providerId): array
    {
        $breakdown = [];

        foreach ($this->sources as $source) {
            $breakdown[$source->key()] = max(0, $source->sumBytesForProvider($providerId));
        }

        return $breakdown;
    }
}
