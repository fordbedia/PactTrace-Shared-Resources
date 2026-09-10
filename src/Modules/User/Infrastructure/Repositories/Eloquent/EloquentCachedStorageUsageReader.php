<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent;

use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\CachedStorageUsageReader;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * @see CachedStorageUsageReader
 *
 * A single indexed-primary-key column read — the whole point of the cache is
 * that this replaces a `SUM` across every file table on each request.
 */
final class EloquentCachedStorageUsageReader implements CachedStorageUsageReader
{
    public function usedBytesForProvider(int $providerId): int
    {
        return (int) Provider::query()
            ->whereKey($providerId)
            ->value('storage_used_bytes');
    }
}
