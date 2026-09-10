<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Storage;

use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\StorageSource;

/**
 * The `documents` table's contribution to a provider's stored-bytes total.
 *
 * Reuses the repository's existing `totalSizeForProvider()` aggregate
 * (`SUM(documents.size)`, already `->acrossWorkspaces()`, soft-deleted rows
 * excluded) rather than issuing a second sum — there is exactly one
 * `documents` byte query and this is it, now shared between the storage
 * registry here and the client-narrowed indicator path in
 * DocumentStorageUsageService.
 */
final class DocumentStorageSource implements StorageSource
{
    public function __construct(
        private readonly DocumentRepository $documents,
    ) {
    }

    public function sumBytesForProvider(int $providerId): int
    {
        return max(0, $this->documents->totalSizeForProvider($providerId));
    }

    public function key(): string
    {
        return 'documents';
    }
}
