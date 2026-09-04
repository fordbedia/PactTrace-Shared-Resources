<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent;

use PactTrackSDK\SharedResources\Modules\Client\Models\ClientInvitation;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderInvitationCanceller;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;

/**
 * Expires a provider's still-open team and client invitations in SQL.
 *
 * "Expire" rather than delete: the rows are kept for the audit trail, and an
 * invitation past `expires_at` already reads as unusable to both accept flows
 * (`TeamInvitation::unusableReason()` returns `expired`;
 * `EloquentClientInvitationRepository::findValidByToken` filters
 * `expires_at > now()`), so each link stops working with no email and no
 * schema change.
 *
 * The `client_invitations` cross-module read mirrors
 * `EloquentAccountDeletionSignals` — the same seam that already reaches into
 * the Client module from here.
 */
final class EloquentProviderInvitationCanceller implements ProviderInvitationCanceller
{
    public function expirePendingTeamInvitationsForProvider(int $providerId): int
    {
        return TeamInvitation::query()
            ->where('provider_id', $providerId)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);
    }

    public function expirePendingClientInvitationsForProvider(int $providerId): int
    {
        return ClientInvitation::query()
            ->where('provider_id', $providerId)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);
    }
}
