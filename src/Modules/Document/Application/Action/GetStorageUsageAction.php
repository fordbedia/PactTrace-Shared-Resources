<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Application\Action;

use PactTraceSDK\SharedResources\Modules\Document\Application\Port\Service\StorageUsageCalculator;
use PactTraceSDK\SharedResources\Modules\Document\Domain\ValueObjects\StorageUsage;
use PactTraceSDK\SharedResources\Modules\User\Models\User;

/**
 * Orchestration behind the STORAGE indicator on /dashboard/documents —
 * DocumentController::storage() calls only this. Same shape as
 * ListDocumentsAction: resolve who is asking (tenant, plan, and — for a
 * client-role actor — their own client id), then delegate the actual work to
 * a port.
 *
 * The client narrowing is the point of having this layer at all: a
 * client-role user seeing the whole tenant's consumption would leak how much
 * business the provider does, so they get their own documents' total, exactly
 * as ListDocumentsAction scopes their document list.
 */
class GetStorageUsageAction
{
    public function __construct(
        private readonly StorageUsageCalculator $calculator,
    ) {
    }

    public function handle(User $user): StorageUsage
    {
        return $this->calculator->forProvider(
            providerId: (int) $user->provider_id,
            plan: $user->provider?->plan,
            clientId: $user->isClientUser() ? $user->client?->id : null,
        );
    }
}
