<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Dashboard\Application\DTO;

use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\Document\Domain\ValueObjects\StorageUsage;

/**
 * The assembled `/dashboard` overview — everything `GET
 * /api/v1/dashboard/summary` returns, produced by GetDashboardSummaryAction
 * and shaped for the wire by DashboardSummaryResource.
 *
 * Sits in the Application layer, not Domain: it's an orchestration seam that
 * legitimately carries a framework `Collection` of `Matter` models (rendered
 * by MatterResource) and the Document module's `StorageUsage` value object.
 * Every scalar here is a real count against a real column — see each
 * property.
 */
final readonly class DashboardSummary
{
    /**
     * @param Collection<int, \PactTrackSDK\SharedResources\Modules\Matter\Models\Matter> $mattersInProgress
     * @param list<array{date: string, count: int}> $signaturesLast7Days one entry per day, oldest first, zero-count days included
     */
    public function __construct(
        public int $activeMatters,
        public int $mattersCreatedThisWeek,
        public int $docsAwaiting,
        public int $clients,
        public int $clientsCreatedThisMonth,
        public int $signedThisMonth,
        public int $signedPreviousMonth,
        public StorageUsage $storage,
        public array $signaturesLast7Days,
        public Collection $mattersInProgress,
    ) {
    }
}
