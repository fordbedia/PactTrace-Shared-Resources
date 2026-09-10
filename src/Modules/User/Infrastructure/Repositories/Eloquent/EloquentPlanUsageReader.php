<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent;

use Illuminate\Support\Carbon;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\PlanUsageReader;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
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
        // The Owner is deliberately EXCLUDED (policy change, Ed 2026-09-06):
        // a "seat" is for someone the owner brings on (Admin or Staff), not
        // the owner's own login. On every plan — so Starter/Professional's
        // `maxSeats: 1` now means "the owner PLUS one teammate", not "the
        // owner and nobody else, ever". PlanPolicy's InviteStaff gate and
        // PlanChangePolicy's downgrade pre-flight both read this figure, so
        // this one query change is what shifts their real-world behaviour.
        return $this->activeUserCountForRoles($providerId, [Role::Admin, Role::Staff]);
    }

    /** Admins alone — one half of {@see activeStaffCount()}. */
    public function activeAdminCount(int $providerId): int
    {
        return $this->activeUserCountForRoles($providerId, [Role::Admin]);
    }

    /** Staff alone — the other half of {@see activeStaffCount()}. */
    public function activeStaffRoleCount(int $providerId): int
    {
        return $this->activeUserCountForRoles($providerId, [Role::Staff]);
    }

    /**
     * @param  list<Role>  $roles
     */
    private function activeUserCountForRoles(int $providerId, array $roles): int
    {
        return User::query()
            ->where('provider_id', $providerId)
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->whereIn('name', array_map(
                static fn (Role $role): string => $role->value,
                $roles,
            )))
            ->count();
    }

    public function envelopesSentThisCycle(int $providerId): int
    {
        // Count from the Stripe billing-cycle start, not the 1st of the month:
        // `maxEnvelopesPerMonth` is a flow limit and must reset when Stripe
        // bills the tenant. A card-less trial that never reached Checkout has
        // no `current_period_starts_at` yet — fall back to the calendar month
        // there (a ~monthly window is fine for that transient state).
        $cycleStart = Subscription::query()
            ->where('provider_id', $providerId)
            ->first(['current_period_starts_at'])
            ?->current_period_starts_at
            ?? Carbon::now()->startOfMonth();

        // The cap is provider-wide, not per-workspace (see .claude/rules/plan.md)
        // — dropped for the same cross-workspace-total reason
        // EloquentAccountDeletionSignals drops it for its document count.
        return Envelope::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('provider_id', $providerId)
            ->where('status', '!=', EnvelopeStatus::Draft->value)
            ->where('created_at', '>=', $cycleStart)
            ->count();
    }
}
