<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports;

/**
 * Reads the cached provider-wide stored-bytes total — the
 * `providers.storage_used_bytes` column that
 * {@see \PactTrackSDK\SharedResources\Modules\User\Application\Services\ProviderStorageLedger}
 * maintains at write time and `storage:reconcile` corrects nightly.
 *
 * This is what every per-request read path resolves to for the whole-tenant
 * figure (the /dashboard/documents STORAGE indicator, the /dashboard summary,
 * and the UploadDocument plan-gate via GetPlanUsageSummary) instead of
 * re-summing every file table live. The Document module's
 * DocumentStorageUsageService depends on this port; the client-narrowed
 * figure it serves stays a live per-client document sum, which the cache
 * (provider-wide only) can't answer.
 */
interface CachedStorageUsageReader
{
    /** Cached `providers.storage_used_bytes` for one provider; 0 when the row is unknown. */
    public function usedBytesForProvider(int $providerId): int;
}
