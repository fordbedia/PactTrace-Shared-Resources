<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Infrastructure\Services;

use PactTraceSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTraceSDK\SharedResources\Modules\Document\Application\Port\Service\StorageUsageCalculator;
use PactTraceSDK\SharedResources\Modules\Document\Domain\Ports\StorageQuotas;
use PactTraceSDK\SharedResources\Modules\Document\Domain\ValueObjects\StorageUsage;

/**
 * The calculation behind the STORAGE indicator on /dashboard/documents (see
 * .claude/rules/document.md) — "used" is a live `SUM(documents.size)` for the
 * tenant, "limit" is the plan's allowance.
 *
 * Depends on the DocumentRepository *port*, which DocumentProvider binds to
 * EloquentDocumentRepository — so the SQL aggregate lives in the Eloquent
 * adapter (`totalSizeForProvider`) and this class stays free of query
 * builders, same shape as the Matter module's MattersListingService.
 *
 * Note `used` is what the database says was uploaded, not what the storage
 * bucket reports: `documents.size` is written once at upload
 * (UploadDocumentAction) and prior versions live in `document_versions`,
 * which is deliberately not counted here — the indicator answers "how much of
 * your library are you storing", and every version of a re-uploaded file
 * counting against the same allowance is a product decision nobody has made
 * yet. It is also purely informational: nothing enforces the limit at upload
 * time (see config/document.php).
 */
final class DocumentStorageUsageService implements StorageUsageCalculator
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly StorageQuotas $quotas,
    ) {
    }

    public function forProvider(int $providerId, ?string $plan = null, ?int $clientId = null): StorageUsage
    {
        return new StorageUsage(
            usedBytes: max(0, $this->documents->totalSizeForProvider($providerId, $clientId)),
            limitBytes: $this->quotas->bytesForPlan($plan),
        );
    }
}
