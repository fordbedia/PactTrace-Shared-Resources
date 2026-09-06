<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent;

use Illuminate\Support\Carbon;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\PlanUsageReader;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Scopes\WorkspaceScope;

/**
 * @see PlanUsageReader
 *
 * Reaches into the Client/Signature/User models directly for three plain
 * COUNTs — the same "read another module's model straight from an
 * Infrastructure adapter" pattern EloquentAccountDeletionSignals already
 * uses for the account-deletion pre-flight.
 */
final class EloquentPlanUsageReader implements PlanUsageReader
{
    public function activeClientCount(int $providerId): int
    {
        return Client::query()
            ->where('provider_id', $providerId)
            ->where('status', 'active')
            ->count();
    }

    public function activeStaffCount(int $providerId): int
    {
        return User::query()
            ->where('provider_id', $providerId)
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->whereIn('name', array_map(
                static fn (Role $role): string => $role->value,
                Role::providerSide(),
            )))
            ->count();
    }

    public function envelopesSentThisMonth(int $providerId): int
    {
        // The monthly cap is provider-wide, not per-workspace (see
        // .claude/rules/plan.md) — dropped for the same cross-workspace-total
        // reason EloquentAccountDeletionSignals drops it for its document count.
        return Envelope::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('provider_id', $providerId)
            ->where('status', '!=', EnvelopeStatus::Draft->value)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->count();
    }
}
