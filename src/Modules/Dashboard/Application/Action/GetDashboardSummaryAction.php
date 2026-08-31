<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Dashboard\Application\Action;

use Illuminate\Support\Carbon;
use PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientRepository;
use PactTrackSDK\SharedResources\Modules\Dashboard\Application\DTO\DashboardSummary;
use PactTrackSDK\SharedResources\Modules\Document\Application\Action\GetStorageUsageAction;
use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Service\MatterStatsService;
use PactTrackSDK\SharedResources\Modules\Signature\Application\Port\Repository\EnvelopeReadRepository;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Assembles the `/dashboard` overview in one provider-scoped pass — the four
 * stat cards (+ their real trend deltas), the storage figure, the
 * signatures-last-7-days series and the matters-in-progress list.
 *
 * Pure orchestration: every value comes from another module's Application
 * port or use case (MatterStatsService, DocumentRepository, ClientRepository,
 * EnvelopeReadRepository, GetStorageUsageAction) or from a sibling Dashboard
 * action. This class never touches Eloquent.
 *
 * "This week" / "this month" boundaries use the app timezone
 * (`config('app.timezone')`, UTC), matching how `/dashboard/audit-log` and
 * the signature date handling already reason about calendar boundaries.
 */
final class GetDashboardSummaryAction
{
    public function __construct(
        private readonly MatterStatsService $matterStats,
        private readonly MattersRepository $matters,
        private readonly DocumentRepository $documents,
        private readonly ClientRepository $clients,
        private readonly EnvelopeReadRepository $envelopes,
        private readonly GetStorageUsageAction $storageUsage,
        private readonly GetSignaturesLast7DaysAction $signaturesLast7Days,
        private readonly GetMattersInProgressAction $mattersInProgress,
    ) {
    }

    public function handle(User $user): DashboardSummary
    {
        $providerId = (int) $user->provider_id;

        $now = Carbon::now();
        $weekStart = $now->copy()->startOfWeek();
        $monthStart = $now->copy()->startOfMonth();
        $previousMonthStart = $monthStart->copy()->subMonthNoOverflow();

        return new DashboardSummary(
            activeMatters: $this->matterStats->countActive($providerId),
            mattersCreatedThisWeek: $this->matters->countCreatedSince($providerId, $weekStart),
            docsAwaiting: $this->documents->countByStatusForProvider($providerId, [
                DocumentStatus::Sent,
                DocumentStatus::PartiallySigned,
            ]),
            clients: $this->clients->countForProvider($providerId),
            clientsCreatedThisMonth: $this->clients->countCreatedSince($providerId, $monthStart),
            signedThisMonth: $this->envelopes->countCompletedBetween($providerId, $monthStart),
            signedPreviousMonth: $this->envelopes->countCompletedBetween($providerId, $previousMonthStart, $monthStart),
            storage: $this->storageUsage->handle($user),
            signaturesLast7Days: $this->signaturesLast7Days->handle($providerId),
            mattersInProgress: $this->mattersInProgress->handle($providerId),
        );
    }
}
