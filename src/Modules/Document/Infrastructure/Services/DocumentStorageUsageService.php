<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Services;

use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Service\StorageUsageCalculator;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\StorageQuotas;
use PactTrackSDK\SharedResources\Modules\Document\Domain\ValueObjects\StorageUsage;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\CachedStorageUsageReader;

/**
 * The calculation behind the STORAGE indicator on /dashboard/documents (see
 * .claude/rules/document.md) and the storage figure in PlanUsageSummary —
 * "used" bytes against the plan's allowance ("limit").
 *
 * The provider-wide "used" figure is a plain read of the cached
 * `providers.storage_used_bytes` column (via CachedStorageUsageReader) — it
 * spans every file table (documents AND message attachments), is maintained
 * at write time by User\Application\Services\ProviderStorageLedger, and is
 * corrected nightly by `storage:reconcile`. It is NOT a live sum on every
 * request — that did not scale and counted only `documents`.
 *
 * The client-narrowed figure (a client-role actor must see only their own
 * documents' total, never the whole practice's consumption) stays a live
 * per-client `SUM(documents.size)` through the DocumentRepository port — the
 * cache is provider-wide and can't answer it, and a single client's library
 * is small enough to sum live.
 */
final class DocumentStorageUsageService implements StorageUsageCalculator
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly StorageQuotas $quotas,
        private readonly CachedStorageUsageReader $cachedUsage,
    ) {
    }

    public function forProvider(int $providerId, ?string $plan = null, ?int $clientId = null): StorageUsage
    {
        $usedBytes = $clientId === null
            ? $this->cachedUsage->usedBytesForProvider($providerId)
            : max(0, $this->documents->totalSizeForProvider($providerId, $clientId));

        return new StorageUsage(
            usedBytes: max(0, $usedBytes),
            limitBytes: $this->quotas->bytesForPlan($plan),
        );
    }
}
