<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports;

/**
 * Withdraws every still-open invitation a provider has outstanding — team and
 * client alike, across all of its workspaces — when the account is deleted.
 *
 * A pending (unaccepted) invitation establishes no relationship and holds
 * nothing worth protecting, so `DeleteOwnAccount` no longer refuses while one
 * exists; it quietly stops the link working, the same as it lapsing. No email
 * is sent to the invitee.
 *
 * Implemented by
 * Infrastructure\Repositories\Eloquent\EloquentProviderInvitationCanceller.
 */
interface ProviderInvitationCanceller
{
    /**
     * Expire every unaccepted `team_invitations` row for the provider.
     * Idempotent. Returns how many rows were withdrawn.
     */
    public function expirePendingTeamInvitationsForProvider(int $providerId): int;

    /**
     * Expire every unaccepted `client_invitations` row for the provider.
     * Idempotent. Returns how many rows were withdrawn.
     */
    public function expirePendingClientInvitationsForProvider(int $providerId): int;
}
