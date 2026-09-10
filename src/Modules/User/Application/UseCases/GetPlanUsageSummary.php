<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Service\StorageUsageCalculator;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\PlanUsageReader;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanUsageSummary;

/**
 * Assembles the one {@see PlanUsageSummary} every plan-gated action and the
 * `GET /api/v1/plan-usage` endpoint reads — see .claude/rules/plan.md.
 *
 * Storage is read through the Document module's own `StorageUsageCalculator`
 * port (the same one DocumentController::storage() and the /dashboard
 * summary already use) rather than re-summing `documents.size` here — one
 * calculation, three callers. The other three figures come from this
 * module's own PlanUsageReader port, which has no Document-module
 * equivalent to reuse.
 */
final class GetPlanUsageSummary
{
    public function __construct(
        private readonly StorageUsageCalculator $storage,
        private readonly PlanUsageReader $usage,
    ) {
    }

    public function handle(int $providerId): PlanUsageSummary
    {
        return new PlanUsageSummary(
            activeClientCount: $this->usage->activeClientCount($providerId),
            activeStaffCount: $this->usage->activeStaffCount($providerId),
            storageUsedBytes: $this->storage->forProvider($providerId)->usedBytes,
            envelopesSentThisCycle: $this->usage->envelopesSentThisCycle($providerId),
            activeAdminCount: $this->usage->activeAdminCount($providerId),
            activeStaffRoleCount: $this->usage->activeStaffRoleCount($providerId),
        );
    }
}
